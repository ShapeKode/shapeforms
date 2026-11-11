<?php
/**
 * ShapeForms Integrations dispatcher.
 *
 * Dispatches form submissions to globally-configured connections.
 * Connections are stored in wp_options by ShapeForms_Lite_Integrations_Page.
 *
 * Each form stores a list of assigned connection IDs in its settings_json:
 *   "assigned_connections": ["conn_abc123", "conn_xyz456"]
 *
 * Field mapping (which field = email, first name, last name) is stored
 * per-form in settings_json under "connection_field_map":
 *   "connection_field_map": {
 *     "conn_abc123": { "email": "field_1", "fname": "field_2", "lname": "field_3" }
 *   }
 *
 * @package ShapeFormsLite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Integrations dispatcher.
 */
class ShapeForms_Lite_Integrations {

	/**
	 * Main plugin instance.
	 *
	 * @var ShapeForms_Lite
	 */
	private $plugin;

	/**
	 * Integrations page handler (connection store).
	 *
	 * @var ShapeForms_Lite_Integrations_Page
	 */
	private $page;

	/**
	 * Constructor.
	 *
	 * @param ShapeForms_Lite                   $plugin Plugin instance.
	 * @param ShapeForms_Lite_Integrations_Page $page   Integrations page handler.
	 */
	public function __construct( $plugin, $page ) {
		$this->plugin = $plugin;
		$this->page   = $page;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		// Persist assigned connections + field map on form save.
		add_filter( 'shapeforms_form_settings_extra', array( $this, 'save_assignment_settings' ), 10, 2 );

		// Dispatch after a successful entry is saved.
		add_action( 'shapeforms_entry_created', array( $this, 'handle_entry_created' ), 20, 4 );

		// Cron handler.
		add_action( 'shapeforms_integration_dispatch', array( $this, 'run_dispatch' ), 10, 4 );

		// Builder: supply connections list for the assign panel.
		add_filter( 'shapeforms_builder_localize_data', array( $this, 'inject_builder_data' ) );
	}

	// =========================================================================
	// Settings persistence
	// =========================================================================

	/**
	 * Saves assigned connections + field mapping into form settings.
	 *
	 * @param array<string, mixed> $extra           Existing extra.
	 * @param array<string, mixed> $settings_payload Raw POST payload.
	 * @return array<string, mixed>
	 */
	public function save_assignment_settings( array $extra, array $settings_payload ): array {
		// Assigned connection IDs.
		$raw_ids = isset( $settings_payload['assigned_connections'] ) && is_array( $settings_payload['assigned_connections'] )
			? $settings_payload['assigned_connections']
			: array();

		$extra['assigned_connections'] = array_values(
			array_filter( array_map( 'sanitize_key', $raw_ids ) )
		);

		// Field mapping per connection: { conn_id: { email, fname, lname } }
		$raw_map = isset( $settings_payload['connection_field_map'] ) && is_array( $settings_payload['connection_field_map'] )
			? $settings_payload['connection_field_map']
			: array();

		$clean_map = array();
		foreach ( $raw_map as $conn_id => $map ) {
			if ( ! is_array( $map ) ) {
				continue;
			}
			$clean_map[ sanitize_key( (string) $conn_id ) ] = array(
				'email' => sanitize_key( (string) ( $map['email'] ?? '' ) ),
				'fname' => sanitize_key( (string) ( $map['fname'] ?? '' ) ),
				'lname' => sanitize_key( (string) ( $map['lname'] ?? '' ) ),
			);
		}

		$extra['connection_field_map'] = $clean_map;

		return $extra;
	}

	// =========================================================================
	// Dispatch
	// =========================================================================

	/**
	 * Schedules async dispatch for each assigned connection.
	 *
	 * @param int                  $entry_id   Entry ID.
	 * @param int                  $form_id    Form ID.
	 * @param array<string, mixed> $entry_data Submitted field data.
	 * @param array<string, mixed> $settings   Form settings.
	 * @return void
	 */
	public function handle_entry_created( int $entry_id, int $form_id, array $entry_data, array $settings ): void {
		$assigned = isset( $settings['assigned_connections'] ) && is_array( $settings['assigned_connections'] )
			? $settings['assigned_connections']
			: array();

		if ( empty( $assigned ) ) {
			return;
		}

		$field_map = isset( $settings['connection_field_map'] ) && is_array( $settings['connection_field_map'] )
			? $settings['connection_field_map']
			: array();

		foreach ( $assigned as $conn_id ) {
			$connection = $this->page->get_connection( $conn_id );

			// Fail closed: only dispatch to a connection that is explicitly active.
			if ( ! $connection || 'active' !== ( $connection['status'] ?? 'inactive' ) ) {
				continue;
			}

			$map = $field_map[ $conn_id ] ?? array();

			// Schedule with the connection ID only — never serialize the API key into the
			// cron option. run_dispatch() re-fetches and re-validates the connection at run time.
			wp_schedule_single_event(
				time(),
				'shapeforms_integration_dispatch',
				array( (string) $conn_id, $entry_data, $map, $entry_id )
			);
		}

		spawn_cron();
	}

	/**
	 * Cron handler — executes one integration dispatch.
	 *
	 * @param string|array<string, mixed> $connection_or_id Connection ID (current) or, for
	 *                                                       legacy in-flight events, the full config array.
	 * @param array<string, mixed>        $entry_data       Submitted field data.
	 * @param array<string, mixed>        $field_map        { email, fname, lname } field ID mapping.
	 * @param int                         $entry_id         Entry ID.
	 * @return void
	 */
	public function run_dispatch( $connection_or_id, array $entry_data = array(), array $field_map = array(), int $entry_id = 0 ): void {
		// Resolve the connection from its ID and re-validate it now (it may have been
		// deactivated or deleted since the event was scheduled). Accept a full array too
		// so any event scheduled before this change still dispatches.
		$connection = is_array( $connection_or_id )
			? $connection_or_id
			: $this->page->get_connection( (string) $connection_or_id );

		// Fail closed: a connection with no explicit 'active' status is treated as inactive.
		if ( ! is_array( $connection ) || empty( $connection ) || 'active' !== ( $connection['status'] ?? 'inactive' ) ) {
			return;
		}

		$type = $connection['type'] ?? '';

		switch ( $type ) {
			case 'mailchimp':
				$this->subscribe_mailchimp( $connection, $entry_data, $field_map, $entry_id );
				break;

			case 'brevo':
				$this->subscribe_brevo( $connection, $entry_data, $field_map, $entry_id );
				break;

			default:
				/**
				 * Allow Pro to handle its own connection types.
				 *
				 * @param array<string, mixed> $connection Connection config.
				 * @param array<string, mixed> $entry_data Submission data.
				 * @param array<string, mixed> $field_map  Email/name field mapping.
				 * @param int                  $entry_id   Entry ID.
				 */
				do_action( 'shapeforms_integration_dispatch_' . sanitize_key( $type ), $connection, $entry_data, $field_map, $entry_id );
				break;
		}
	}

	// =========================================================================
	// Mailchimp
	// =========================================================================

	/**
	 * Subscribes a contact to a Mailchimp audience.
	 *
	 * @param array<string, mixed> $conn       Connection config.
	 * @param array<string, mixed> $entry_data Submission data.
	 * @param array<string, mixed> $field_map  Field mapping.
	 * @param int                  $entry_id   Entry ID (for the dispatch-result hook).
	 * @return void
	 */
	private function subscribe_mailchimp( array $conn, array $entry_data, array $field_map, int $entry_id = 0 ): void {
		$api_key = trim( (string) ( $conn['api_key'] ?? '' ) );
		$list_id = trim( (string) ( $conn['list_id'] ?? '' ) );
		$email   = $this->get_field_value( $entry_data, $field_map['email'] ?? '' );

		// Mailchimp audience IDs are alphanumeric; reject anything else so a stored
		// value can never inject extra path/query segments into the request URL.
		if ( ! $api_key || ! $list_id || ! preg_match( '/^[A-Za-z0-9]+$/', $list_id ) || ! is_email( $email ) ) {
			return;
		}

		// The Mailchimp data center is the suffix of the API key (e.g. "-us21").
		// Without it we cannot build a correct endpoint, so bail rather than guess.
		// Parsed by the same helper the "Test Connection" path uses, so a key that
		// tests successfully also dispatches.
		$dc = ShapeForms_Lite_Integrations_Page::mailchimp_datacenter( $api_key );
		if ( '' === $dc ) {
			return;
		}

		// Upsert via PUT to the member resource (hash = md5 of the lowercased email)
		// rather than POST /members, which returns HTTP 400 "Member Exists" on every
		// resubmission. With PUT, `status_if_new` applies only on first insert, so a
		// returning contact's subscription status is never overwritten.
		$subscriber_hash = md5( strtolower( $email ) );

		$body = array(
			'email_address' => $email,
			'status_if_new' => ! empty( $conn['double_optin'] ) ? 'pending' : 'subscribed',
		);

		$merge = array();
		$fname = $this->get_field_value( $entry_data, $field_map['fname'] ?? '' );
		$lname = $this->get_field_value( $entry_data, $field_map['lname'] ?? '' );
		if ( $fname ) {
			$merge['FNAME'] = $fname;
		}
		if ( $lname ) {
			$merge['LNAME'] = $lname;
		}
		if ( $merge ) {
			$body['merge_fields'] = $merge;
		}

		if ( ! empty( $conn['tags'] ) ) {
			$body['tags'] = array_values( array_filter( array_map( 'trim', explode( ',', (string) $conn['tags'] ) ) ) );
		}

		$response = wp_remote_request(
			"https://{$dc}.api.mailchimp.com/3.0/lists/{$list_id}/members/{$subscriber_hash}",
			array(
				'method'  => 'PUT',
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( 'anystring:' . $api_key ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		$this->record_dispatch_result( 'mailchimp', $conn, $response, $entry_id );
	}

	// =========================================================================
	// Brevo
	// =========================================================================

	/**
	 * Subscribes a contact to a Brevo list.
	 *
	 * @param array<string, mixed> $conn       Connection config.
	 * @param array<string, mixed> $entry_data Submission data.
	 * @param array<string, mixed> $field_map  Field mapping.
	 * @param int                  $entry_id   Entry ID (for the dispatch-result hook).
	 * @return void
	 */
	private function subscribe_brevo( array $conn, array $entry_data, array $field_map, int $entry_id = 0 ): void {
		$api_key = trim( (string) ( $conn['api_key'] ?? '' ) );
		$list_id = (int) ( $conn['list_id'] ?? 0 );
		$email   = $this->get_field_value( $entry_data, $field_map['email'] ?? '' );

		if ( ! $api_key || ! $list_id || ! is_email( $email ) ) {
			return;
		}

		$body = array( 'email' => $email, 'listIds' => array( $list_id ), 'updateEnabled' => true );

		$attrs = array();
		$fname = $this->get_field_value( $entry_data, $field_map['fname'] ?? '' );
		$lname = $this->get_field_value( $entry_data, $field_map['lname'] ?? '' );
		if ( $fname ) {
			$attrs['FIRSTNAME'] = $fname;
		}
		if ( $lname ) {
			$attrs['LASTNAME'] = $lname;
		}
		if ( $attrs ) {
			$body['attributes'] = $attrs;
		}

		$response = wp_remote_post(
			'https://api.brevo.com/v3/contacts',
			array(
				'timeout' => 15,
				'headers' => array(
					'api-key'      => $api_key,
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		$this->record_dispatch_result( 'brevo', $conn, $response, $entry_id );
	}

	/**
	 * Fires an action after a built-in integration dispatch HTTP request completes.
	 *
	 * The dispatch itself stays fire-and-forget; this seam lets logging/monitoring
	 * (and Pro) observe whether a subscribe call succeeded or failed.
	 *
	 * @param string                  $type     Connection type ('mailchimp', 'brevo', …).
	 * @param array<string, mixed>    $conn     Connection config.
	 * @param array<string, mixed>|WP_Error $response wp_remote_* response array or WP_Error.
	 * @param int                     $entry_id Entry ID that triggered the dispatch.
	 * @return void
	 */
	private function record_dispatch_result( string $type, array $conn, $response, int $entry_id ): void {
		/**
		 * Fires after a built-in integration subscribe request returns.
		 *
		 * @param string                        $type          Connection type ('mailchimp', 'brevo', …).
		 * @param string                        $connection_id Connection ID.
		 * @param array<string, mixed>|WP_Error $response      wp_remote_* response array or WP_Error.
		 * @param int                           $entry_id      Entry ID that triggered the dispatch.
		 */
		do_action( 'shapeforms_integration_dispatched', $type, (string) ( $conn['id'] ?? '' ), $response, $entry_id );
	}

	// =========================================================================
	// Builder data injection
	// =========================================================================

	/**
	 * Injects global connections list into the builder localize data.
	 *
	 * @param array<string, mixed> $data Builder data.
	 * @return array<string, mixed>
	 */
	public function inject_builder_data( array $data ): array {
		// Only expose what the builder's assign panel actually uses — id, name,
		// type, status. Never localize the API key (or any secret/config field)
		// into the builder page HTML.
		$safe = array();
		foreach ( $this->page->get_all_connections() as $conn ) {
			if ( ! is_array( $conn ) ) {
				continue;
			}
			$safe[] = array(
				'id'     => (string) ( $conn['id'] ?? '' ),
				'name'   => (string) ( $conn['name'] ?? '' ),
				'type'   => (string) ( $conn['type'] ?? '' ),
				'status' => (string) ( $conn['status'] ?? 'inactive' ),
			);
		}

		$data['globalConnections']      = $safe;
		$data['integrationsNonce']      = wp_create_nonce( 'shapeforms_integration_nonce' );
		$data['integrationsAdminUrl']   = admin_url( 'admin.php?page=shapeforms-lite-integrations' );

		// The connection types the free plugin can actually dispatch. Any assigned
		// connection of another type is a leftover from when an add-on was active
		// (a clean free install cannot create one), so the builder renders those as
		// locked upgrade teasers rather than working toggles that would silently
		// never fire. The array_values keeps this a JSON array, not an object.
		$data['freeIntegrationTypes'] = array_values( ShapeForms_Lite_Integrations_Page::FREE_TYPES );

		return $data;
	}

	// =========================================================================
	// Helper
	// =========================================================================

	/**
	 * Extracts a scalar value from entry_data by field ID.
	 *
	 * @param array<string, mixed> $entry_data Submission data.
	 * @param string               $field_id   Field ID.
	 * @return string
	 */
	private function get_field_value( array $entry_data, string $field_id ): string {
		if ( ! $field_id || ! isset( $entry_data[ $field_id ] ) ) {
			return '';
		}
		$field = $entry_data[ $field_id ];
		$val   = is_array( $field ) ? ( $field['value'] ?? '' ) : $field;
		return is_array( $val ) ? implode( ', ', $val ) : (string) $val;
	}
}
