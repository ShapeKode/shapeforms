<?php
/**
 * Frontend form submission handler.
 *
 * @package ShapeFormsLite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles secure frontend form submissions.
 */
class ShapeForms_Lite_Form_Handler {

	/**
	 * Main plugin instance.
	 *
	 * @var ShapeForms_Lite
	 */
	private $plugin;

	/**
	 * Email notifications handler.
	 *
	 * @var ShapeForms_Lite_Email_Handler
	 */
	private $email_handler;

	/**
	 * Constructor.
	 *
	 * @param ShapeForms_Lite               $plugin        Main plugin instance.
	 * @param ShapeForms_Lite_Email_Handler $email_handler Email notifications handler.
	 */
	public function __construct( $plugin, $email_handler ) {
		$this->plugin        = $plugin;
		$this->email_handler = $email_handler;
	}

	/**
	 * Handles non-AJAX form submissions.
	 *
	 * @return void
	 */
	public function handle_submission() {
		// AJAX submissions are handled by ajax_submit_form() on the wp_ajax hook.
		// This classic handler runs on `init`, which also fires on admin-ajax.php — and
		// because the AJAX request carries shapeforms_action=submit_form, without this guard
		// it would intercept the AJAX POST and emit a 302 redirect instead of the JSON the
		// front end expects (the front end would then show the generic success fallback).
		if ( wp_doing_ajax() ) {
			return;
		}

		if ( 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified in process_submission().
		$action = isset( $_POST['shapeforms_action'] ) ? sanitize_key( wp_unslash( $_POST['shapeforms_action'] ) ) : '';

		if ( 'submit_form' !== $action ) {
			return;
		}

		$result = $this->process_submission( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$form_id      = isset( $_POST['shapeforms_form_id'] ) ? absint( $_POST['shapeforms_form_id'] ) : 0;
		$redirect_url = wp_get_referer();

		if ( ! $redirect_url ) {
			$redirect_url = home_url( '/' );
		}

		if ( ! empty( $result['redirect_url'] ) ) {
			wp_redirect( esc_url_raw( $result['redirect_url'] ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- URL is user-configured in the form builder, external redirects are intentional.
			exit;
		}

		$redirect_url = remove_query_arg(
			array(
				'shapeforms_status',
				'shapeforms_message',
				'shapeforms_form_id',
				'shapeforms_msg',
			),
			$redirect_url
		);

		// Hand the message back through a one-shot token rather than the query string.
		// The thank-you message is rich markup, which a URL round-trip cannot carry (it
		// has to be flattened to text to stay safe to print). Keeping the message
		// server-side means what renders is what this request produced -- including any
		// override applied by the shapeforms_submission_result filter -- instead of
		// whatever a visitor put in the URL.
		$token = bin2hex( random_bytes( 16 ) );

		set_transient(
			'shapeforms_lite_msg_' . $token,
			array(
				'type'    => $result['success'] ? 'success' : 'error',
				'message' => (string) $result['message'],
				'form_id' => $form_id,
			),
			5 * MINUTE_IN_SECONDS
		);

		$redirect_url = add_query_arg( 'shapeforms_msg', $token, $redirect_url );

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * AJAX callback for form submission.
	 *
	 * @return void
	 */
	public function ajax_submit_form() {
		$result = $this->process_submission( $_POST, true ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified in process_submission().

		if ( $result['success'] ) {
			$payload = array(
				// The success message is inserted as markup by the frontend script, so
				// it is filtered here rather than where it was read: shapeforms_submission_result
				// runs after that point and can replace the message with anything.
				// Filtering at the boundary keeps that filter from becoming a way to
				// put script on the page. The error branch below is printed as text.
				'message'      => wp_kses_post( (string) $result['message'] ),
				'redirectUrl'  => $result['redirect_url'],
			);

			/**
			 * Filter the successful AJAX response payload sent to the browser.
			 *
			 * A submission is not always finished when it is saved. This lets an
			 * extension attach whatever the browser needs to complete the work the
			 * submission started — Pro's payment module uses it when a card issuer
			 * interrupts for an authentication step. Pair it with the
			 * shapeforms_form_submitted JS event, which lets the same extension hold
			 * the confirmation until that work is done.
			 *
			 * The message and redirect URL are already escaped above; anything
			 * added here is the adding extension's responsibility.
			 *
			 * @param array<string, mixed> $payload Response payload.
			 * @param array<string, mixed> $result  Full submission result.
			 */
			$payload = (array) apply_filters( 'shapeforms_submission_response', $payload, $result );

			wp_send_json_success( $payload );
		}

		$payload = array(
			'message' => $result['message'],
			'errors'  => $result['errors'],
		);

		/**
		 * Filter the rejected AJAX response payload sent to the browser.
		 *
		 * Counterpart to shapeforms_submission_response. A rejection is not always
		 * the end of the story: an extension may have created state the visitor's
		 * next attempt should continue from rather than duplicate. Pro's payment
		 * module uses this to name the entry a declined card left behind, so the
		 * retry updates that row instead of stranding it and inserting another.
		 *
		 * The message and errors are printed as text by the frontend script;
		 * anything added here is the adding extension's responsibility.
		 *
		 * @param array<string, mixed> $payload Response payload.
		 * @param array<string, mixed> $result  Full submission result.
		 */
		$payload = (array) apply_filters( 'shapeforms_submission_error_response', $payload, $result );

		wp_send_json_error( $payload, 400 );
	}

	/**
	 * Processes submitted form data.
	 *
	 * @param array<string, mixed> $request Request data.
	 * @param bool                 $is_ajax Whether the request is AJAX.
	 * @return array<string, mixed>
	 */
	public function process_submission( $request, $is_ajax = false ) {
		$form_id = isset( $request['shapeforms_form_id'] ) ? absint( $request['shapeforms_form_id'] ) : 0;
		$nonce   = isset( $request['shapeforms_nonce'] ) ? sanitize_text_field( wp_unslash( $request['shapeforms_nonce'] ) ) : '';

		if ( ! $form_id || ! wp_verify_nonce( $nonce, 'shapeforms_lite_submit_form_' . $form_id ) ) {
			return $this->build_result(
				false,
				__( 'Security check failed. Please refresh the page and try again.', 'shapeforms' ),
				array()
			);
		}

		$form_record = $this->get_form( $form_id );

		if ( ! $form_record || 'publish' !== $form_record->status ) {
			return $this->build_result(
				false,
				__( 'The requested form is not available.', 'shapeforms' ),
				array()
			);
		}

		// Honeypot check — if the hidden field has a value, it's a bot.
		$hp_key = 'shapeforms_hp_' . $form_id;
		if ( ! empty( $request[ $hp_key ] ) ) {
			return $this->build_result(
				false,
				__( 'Spam detected.', 'shapeforms' ),
				array()
			);
		}

		$fields   = $this->extract_fields_from_record( $form_record );
		$settings = $this->extract_settings_from_record( $form_record );
		$captcha  = $this->get_captcha_settings();

		if ( empty( $fields ) ) {
			return $this->build_result(
				false,
				__( 'The requested form has no fields configured.', 'shapeforms' ),
				array()
			);
		}

		/**
		 * Fires before ShapeForms validates and processes a submission.
		 *
		 * Pro can use this to run pre-validation logic (e.g. rate limiting, geo blocking).
		 *
		 * @param int                              $form_id     Form ID.
		 * @param array<string, mixed>             $request     Raw request payload.
		 * @param array<int, array<string, mixed>> $fields      Flattened field definitions.
		 * @param array<string, mixed>             $settings    Form settings.
		 */
		do_action( 'shapeforms_before_submission', $form_id, $request, $fields, $settings );

		/**
		 * Filter to allow Pro modules to gate a submission before validation.
		 *
		 * Return an array with `success => false` and a `message` string to reject
		 * the submission immediately (e.g. scheduling window, entry limit, geo-block).
		 * Return null (default) to proceed normally.
		 *
		 * @param array<string, mixed>|null            $block    Null to proceed; array( 'success'=>false, 'message'=>'…' ) to reject.
		 * @param int                                  $form_id  Form ID.
		 * @param array<string, mixed>                 $settings Form settings.
		 * @param array<string, mixed>                 $request  Raw request payload.
		 */
		$submission_gate = apply_filters( 'shapeforms_gate_submission', null, $form_id, $settings, $request );
		if ( is_array( $submission_gate ) && isset( $submission_gate['success'] ) && false === $submission_gate['success'] ) {
			return $this->build_result(
				false,
				isset( $submission_gate['message'] ) ? (string) $submission_gate['message'] : __( 'This form is not currently available.', 'shapeforms' ),
				array()
			);
		}

		$captcha_result = $this->structure_contains_field_type( $fields, 'captcha' ) ? $this->validate_captcha( $captcha, $request ) : array(
			'success' => true,
			'message' => '',
		);

		if ( ! $captcha_result['success'] ) {
			return $this->build_result(
				false,
				$captcha_result['message'],
				array()
			);
		}

		// Strip values for fields hidden by conditional logic so they are not validated or saved.
		$request = $this->strip_conditionally_hidden_fields( $fields, $request );

		/**
		 * Filter the raw request payload before field validation.
		 *
		 * Pro can use this to inject or remove values (e.g. pre-populate hidden fields,
		 * strip fields on conditional logic evaluation).
		 *
		 * @param array<string, mixed>             $request  Raw request payload.
		 * @param int                              $form_id  Form ID.
		 * @param array<int, array<string, mixed>> $fields   Flattened field definitions.
		 */
		$request = apply_filters( 'shapeforms_submission_request', $request, $form_id, $fields );

		// Convert the submitted payload into a normalized, trusted entry array before saving or emailing.
		$validation = $this->validate_and_sanitize_fields( $fields, $request );

		if ( ! empty( $validation['errors'] ) ) {
			return $this->build_result(
				false,
				__( 'Please correct the highlighted fields and try again.', 'shapeforms' ),
				$validation['errors']
			);
		}

		/**
		 * Filter the validated entry data before it is saved.
		 *
		 * Pro can mutate, enrich, or strip fields (e.g. payment metadata, calculated values).
		 *
		 * @param array<string, array<string, mixed>> $entry_data Normalized entry data.
		 * @param int                                 $form_id    Form ID.
		 * @param array<string, mixed>                $settings   Form settings.
		 */
		$validation['entry_data'] = apply_filters( 'shapeforms_entry_data', $validation['entry_data'], $form_id, $settings );

		/**
		 * Filter whether this submission should be treated as spam.
		 *
		 * Return true to STORE the entry with the "spam" status and SKIP every
		 * post-save side effect — the shapeforms_entry_created action (which drives
		 * integrations, webhooks, Zapier, Slack, etc.) and the notification emails —
		 * while still returning the normal success response to the visitor. This
		 * routes a flagged submission to the reviewable Spam folder (Entries → Spam)
		 * instead of rejecting it outright, so a false positive can be recovered and
		 * a real spammer/bot is given no signal that it was caught.
		 *
		 * An anti-spam add-on returns true here to send a flagged submission to the
		 * folder; returning a rejection from shapeforms_gate_submission instead is the
		 * hard-block alternative.
		 *
		 * The duplicate-entry check and post-save side effects are skipped for a spam
		 * submission; the entry is still persisted (as spam) and shapeforms_entry_saved
		 * still fires so persistence-level listeners run.
		 *
		 * @since 1.0.0
		 * @param bool                                $is_spam    Whether to treat the submission as spam. Default false.
		 * @param int                                 $form_id    Form ID.
		 * @param array<string, mixed>                $settings   Form settings.
		 * @param array<string, array<string, mixed>> $entry_data Validated entry data.
		 * @param array<string, mixed>                $request    Raw request payload.
		 */
		$is_spam = (bool) apply_filters( 'shapeforms_submission_is_spam', false, $form_id, $settings, $validation['entry_data'], $request );

		// Duplicate entry check — runs after validation so entry_data is fully resolved.
		// Skipped for spam: a flagged submission is always folded silently into the
		// Spam folder rather than shown a "you already submitted" message (which would
		// signal a bot that the form is working).
		if ( ! $is_spam && $this->check_duplicate_entry( $form_id, $settings, $validation['entry_data'], $fields ) ) {
			$dup_message = ! empty( $settings['dup_message'] )
				? $settings['dup_message']
				: __( 'You have already submitted this form.', 'shapeforms' );

			/**
			 * Filter the duplicate-submission error message.
			 *
			 * @param string               $message  Default or configured message.
			 * @param int                  $form_id  Form ID.
			 * @param array<string, mixed> $settings Form settings.
			 */
			$dup_message = (string) apply_filters( 'shapeforms_duplicate_entry_message', $dup_message, $form_id, $settings );

			return $this->build_result( false, $dup_message, array() );
		}

		/**
		 * Fires before an entry is persisted to the database.
		 *
		 * @param int                                 $form_id    Form ID.
		 * @param array<string, array<string, mixed>> $entry_data Normalized entry data.
		 * @param array<string, mixed>                $settings   Form settings.
		 */
		do_action( 'shapeforms_before_entry_save', $form_id, $validation['entry_data'], $settings );

		/**
		 * Filter whether the entry should be saved immediately.
		 *
		 * Return false to defer or skip the entry save (e.g. Pro payment module
		 * defers saving until payment is confirmed for WooCommerce redirect flow).
		 * When returning false, the module is responsible for saving the entry itself.
		 *
		 * @param bool                                $should_save Whether to save the entry now.
		 * @param int                                 $form_id     Form ID.
		 * @param array<string, array<string, mixed>> $entry_data  Validated entry data.
		 * @param array<string, mixed>                $settings    Form settings.
		 * @param array<string, mixed>                $request     Raw request payload.
		 */
		$should_save = apply_filters( 'shapeforms_should_save_entry', true, $form_id, $validation['entry_data'], $settings, $request );

		$entry_id = 0;

		if ( $should_save ) {
			$entry_id = $this->create_entry( $form_id, $validation['entry_data'], $is_spam ? 'spam' : 'unread' );

			if ( ! $entry_id ) {
				return $this->build_result(
					false,
					__( 'Unable to save your submission right now.', 'shapeforms' ),
					array()
				);
			}

			/**
			 * Fires immediately after a new entry is saved to the database.
			 *
			 * Always fires on save (even when post-save side effects are deferred),
			 * so persistence-level listeners can run.
			 *
			 * @param int                                 $form_id    Form ID.
			 * @param array<string, array<string, mixed>> $entry_data Saved entry data.
			 * @param object                              $form_record Form database row.
			 * @param array<string, mixed>                $settings   Form settings.
			 */
			do_action( 'shapeforms_entry_saved', $form_id, $validation['entry_data'], $form_record, $settings );
		}

		/**
		 * Filter whether post-save side effects (the shapeforms_entry_created action and
		 * the notification emails) should run now.
		 *
		 * Defaults to false (run immediately). An extension may return true to DEFER
		 * them when the entry is not yet final, then re-run them itself later by firing
		 * shapeforms_entry_created and dispatching notifications via the handler returned
		 * by ShapeForms_Lite::get_email_handler().
		 *
		 * @param bool                                $defer      Whether to defer post-save side effects.
		 * @param int                                 $form_id    Form ID.
		 * @param int                                 $entry_id   Saved entry ID (0 if not saved).
		 * @param array<string, mixed>                $settings   Form settings.
		 * @param array<string, array<string, mixed>> $entry_data Saved entry data.
		 */
		$defer_actions = (bool) apply_filters( 'shapeforms_defer_post_save_actions', false, $form_id, $entry_id, $settings, $validation['entry_data'] );

		// A spam entry is stored for review but must never trigger notifications,
		// integrations, or webhooks — skip all post-save side effects for it.
		if ( $should_save && ! $defer_actions && ! $is_spam ) {
			/**
			 * Fires after an entry is saved, passing the new entry ID.
			 *
			 * Extensions use this to trigger post-save integrations (CRM, webhooks,
			 * Zapier, Slack) or to attach related records to the entry.
			 *
			 * @param int                                 $entry_id   Newly inserted entry ID.
			 * @param int                                 $form_id    Form ID.
			 * @param array<string, array<string, mixed>> $entry_data Saved entry data.
			 * @param array<string, mixed>                $settings   Form settings.
			 */
			do_action( 'shapeforms_entry_created', $entry_id, $form_id, $validation['entry_data'], $settings );

			$this->email_handler->send_notifications( $form_record, $settings, $validation['entry_data'], (int) $entry_id );
		}

		// Only redirect when the form's confirmation mode is "redirect" (To a Page /
		// Custom URL). In AJAX/message mode we return no redirect URL so the front end
		// shows the thank-you message, even if a stale redirect_url lingers in storage.
		$redirect_url = ! empty( $settings['enable_redirect'] ) && ! empty( $settings['redirect_url'] )
			? $settings['redirect_url']
			: '';

		$result = $this->build_result(
			true,
			$settings['thank_you_message'],
			array(),
			$redirect_url
		);

		/**
		 * Filter the final submission result before it is returned to the browser.
		 *
		 * Pro can override the redirect URL (e.g. after payment) or success message.
		 *
		 * @param array<string, mixed>                $result     Result array (success, message, redirect_url, errors).
		 * @param int                                 $form_id    Form ID.
		 * @param array<string, array<string, mixed>> $entry_data Saved entry data.
		 * @param array<string, mixed>                $settings   Form settings.
		 * @param int                                 $entry_id   Saved entry ID (0 if deferred).
		 * @param array<string, mixed>                $request    Raw request payload.
		 */
		return apply_filters( 'shapeforms_submission_result', $result, $form_id, $validation['entry_data'], $settings, $entry_id, $request );
	}

	/**
	 * Returns a form row.
	 *
	 * @param int $form_id Form ID.
	 * @return object|null
	 */
	private function get_form( $form_id ) {
		global $wpdb;

		$table_name = $this->plugin->get_forms_table_name();

		$safe_table = esc_sql( $table_name );

		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM `{$safe_table}` WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$form_id
			)
		);
	}

	/**
	 * Extracts saved field definitions.
	 *
	 * @param object|null $form_record Form database row.
	 * @return array<int, array<string, mixed>>
	 */
	private function extract_fields_from_record( $form_record ) {
		if ( ! $form_record || empty( $form_record->fields_json ) ) {
			return array();
		}

		$decoded = json_decode( (string) $form_record->fields_json, true );

		if ( isset( $decoded['rows'] ) && is_array( $decoded['rows'] ) ) {
			return $this->flatten_structure_fields( $decoded['rows'] );
		}

		if ( isset( $decoded['fields'] ) && is_array( $decoded['fields'] ) ) {
			return $decoded['fields'];
		}

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Checks whether a duplicate entry already exists for this form submission.
	 *
	 * Method: email  — match on the value of the first email-type field in the form.
	 * Method: ip     — match on the submitter's IP address.
	 * Method: field  — match on the value of a specific field chosen by the admin.
	 *
	 * The query targets `entry_data_json` only for email/field methods, using a
	 * JSON_EXTRACT path so the search is anchored and avoids false positives from
	 * partial-string matches.  A covering index on (form_id, status) keeps the
	 * scan small; for `ip` we read a stored column directly.
	 *
	 * @param int                                 $form_id    Form ID.
	 * @param array<string, mixed>                $settings   Normalized form settings.
	 * @param array<string, array<string, mixed>> $entry_data Validated entry data (keyed by field_id).
	 * @param array<int, array<string, mixed>>    $fields     All field definitions.
	 * @return bool  True if a duplicate exists.
	 */
	private function check_duplicate_entry( $form_id, $settings, $entry_data, $fields ) {
		if ( empty( $settings['dup_enabled'] ) ) {
			return false;
		}

		global $wpdb;

		$method     = isset( $settings['dup_method'] ) ? (string) $settings['dup_method'] : 'email';
		$table      = esc_sql( $this->plugin->get_entries_table_name() );

		switch ( $method ) {

			// ── IP-based ────────────────────────────────────────────────────────
			case 'ip':
				$raw_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
				$ip     = $this->sanitize_ip_address( $raw_ip );
				if ( '' === $ip ) {
					return false;
				}

				// Single indexed column lookup — very fast.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$count = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM `{$table}` WHERE form_id = %d AND user_ip = %s AND status != %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$form_id,
						$ip,
						'spam'
					)
				);
				return $count > 0;

			// ── Email-based ─────────────────────────────────────────────────────
			case 'email':
				// Find the first email-type field that has a value in entry_data.
				$email_value = '';
				$email_fid   = '';
				foreach ( $fields as $field ) {
					if ( 'email' !== ( $field['type'] ?? '' ) ) {
						continue;
					}
					$fid = sanitize_key( (string) ( $field['id'] ?? '' ) );
					if ( isset( $entry_data[ $fid ]['value'] ) && '' !== $entry_data[ $fid ]['value'] ) {
						$email_value = (string) $entry_data[ $fid ]['value'];
						$email_fid   = $fid;
						break;
					}
				}
				if ( '' === $email_value ) {
					return false; // No email field — skip.
				}
				// Anchor the match to the email field's own JSON key so the value cannot
				// false-positive against another field's value or a stored label.
				return $this->duplicate_json_field_match( $table, $form_id, $email_value, $email_fid );

			// ── Custom field-based ──────────────────────────────────────────────
			case 'field':
				$dup_field_id = isset( $settings['dup_field_id'] ) ? (string) $settings['dup_field_id'] : '';
				if ( '' === $dup_field_id ) {
					return false;
				}
				$field_value = isset( $entry_data[ $dup_field_id ]['value'] ) ? (string) $entry_data[ $dup_field_id ]['value'] : '';
				if ( '' === $field_value ) {
					return false;
				}
				return $this->duplicate_json_field_match( $table, $form_id, $field_value, $dup_field_id );

			default:
				return false;
		}
	}

	/**
	 * Queries entry_data_json for an exact field-value match.
	 *
	 * Uses JSON_EXTRACT so the search is anchor-exact and avoids substring hits.
	 * Falls back to a LIKE scan on older MySQL (<5.7) that lack JSON_EXTRACT, but
	 * this is effectively MySQL 5.7+ / MariaDB 10.2+ (WordPress minimum).
	 *
	 * DB query example:
	 *   SELECT COUNT(*) FROM wp_shapeforms_entries
	 *   WHERE form_id = 12
	 *     AND status != 'spam'
	 *     AND JSON_EXTRACT(entry_data_json, '$."email_field_id".value') = 'user@example.com'
	 *   LIMIT 1;
	 *
	 * @param string $table    Sanitized table name.
	 * @param int    $form_id  Form ID.
	 * @param string $value    Value to match.
	 * @param string $field_id Field ID key inside entry_data_json. Empty = search all fields.
	 * @return bool
	 */
	private function duplicate_json_field_match( $table, $form_id, $value, $field_id = '' ) {
		global $wpdb;

		$table = esc_sql( $table );

		if ( '' !== $field_id ) {
			// Targeted path: JSON_EXTRACT('$."<field_id>".value').
			// Wrap field_id in quotes inside the JSON path — sanitize_key already stripped special chars.
			$json_path = '$."' . esc_sql( $field_id ) . '".value';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `{$table}` WHERE form_id = %d AND status != %s AND JSON_UNQUOTE(JSON_EXTRACT(entry_data_json, %s)) = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$form_id,
					'spam',
					$json_path,
					$value
				)
			);
		} else {
			// Email method without known field_id: scan all ".value" leaves.
			// JSON_SEARCH returns the path of the first match — NULL means no match.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `{$table}` WHERE form_id = %d AND status != %s AND JSON_SEARCH(entry_data_json, 'one', %s) IS NOT NULL LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$form_id,
					'spam',
					$value
				)
			);
		}

		return $count > 0;
	}

	/**
	 * Tests a single condition against the submitted request data.
	 *
	 * @param array<string, mixed> $cond    Condition: {field_id, operator, value}.
	 * @param array<string, mixed> $request Raw request payload (field names use shapeforms_ prefix).
	 * @return bool
	 */
	private function evaluate_condition( $cond, $request ) {
		$field_id  = isset( $cond['field_id'] ) ? (string) $cond['field_id'] : '';
		$operator  = isset( $cond['operator'] ) ? (string) $cond['operator'] : 'is';
		$cond_val  = isset( $cond['value'] ) ? (string) $cond['value'] : '';
		$field_key = 'shapeforms_' . $field_id;

		$raw = '';
		if ( isset( $request[ $field_key ] ) ) {
			if ( is_array( $request[ $field_key ] ) ) {
				$raw = implode( ', ', array_map( 'strval', $request[ $field_key ] ) );
			} else {
				$raw = (string) $request[ $field_key ];
			}
		}

		switch ( $operator ) {
			case 'is':           return $raw === $cond_val;
			case 'is_not':       return $raw !== $cond_val;
			case 'contains':     return false !== stripos( $raw, $cond_val );
			case 'not_contains': return false === stripos( $raw, $cond_val );
			case 'starts_with':  return 0 === stripos( $raw, $cond_val );
			case 'ends_with':    return '' !== $cond_val && strlen( $raw ) >= strlen( $cond_val ) && ( substr_compare( strtolower( $raw ), strtolower( $cond_val ), -strlen( $cond_val ) ) === 0 );
			case 'greater_than': return is_numeric( $raw ) && is_numeric( $cond_val ) && (float) $raw > (float) $cond_val;
			case 'less_than':    return is_numeric( $raw ) && is_numeric( $cond_val ) && (float) $raw < (float) $cond_val;
			case 'not_empty':    return '' !== $raw;
			case 'empty':        return '' === $raw;
			default:             return false;
		}
	}

	/**
	 * Evaluates conditional logic for all fields and nulls out values for fields
	 * that a hide/disable action targets when its conditions are met.
	 *
	 * This prevents hidden fields from being validated as required or saved.
	 *
	 * @param array<int, array<string, mixed>> $fields  All form field definitions.
	 * @param array<string, mixed>             $request Submitted request payload.
	 * @return array<string, mixed>  Modified request.
	 */
	private function strip_conditionally_hidden_fields( $fields, $request ) {
		foreach ( $fields as $field ) {
			$cond = isset( $field['conditional'] ) && is_array( $field['conditional'] ) ? $field['conditional'] : array();
			if ( empty( $cond['enabled'] ) ) {
				continue;
			}

			// Multi-condition structure — action always applies to this field itself.
			if ( isset( $cond['conditions'] ) && is_array( $cond['conditions'] ) ) {
				$logic      = isset( $cond['logic'] ) && 'OR' === $cond['logic'] ? 'OR' : 'AND';
				$action     = isset( $cond['action'] ) && 'hide' === $cond['action'] ? 'hide' : 'show';
				$conditions = $cond['conditions'];
				$field_key  = 'shapeforms_' . sanitize_key( (string) ( $field['id'] ?? '' ) );

				$results = array_map( function ( $c ) use ( $request ) {
					return $this->evaluate_condition( $c, $request );
				}, $conditions );

				$condition_met = 'OR' === $logic
					? in_array( true, $results, true )
					: ! in_array( false, $results, true );

				// Strip if field should be hidden (not visible to the user).
				if ( ( 'show' === $action && ! $condition_met ) || ( 'hide' === $action && $condition_met ) ) {
					unset( $request[ $field_key ] );
				}
			} elseif ( ! empty( $cond['field_id'] ) ) {
				// Legacy single-rule fallback.
				$match  = $this->evaluate_condition( $cond, $request );
				$action = isset( $cond['action'] ) ? (string) $cond['action'] : 'show';
				$field_key = 'shapeforms_' . sanitize_key( (string) ( $field['id'] ?? '' ) );
				if ( ( 'show' === $action && ! $match ) || ( 'hide' === $action && $match ) ) {
					unset( $request[ $field_key ] );
				}
			}
		}
		return $request;
	}

	/**
	 * Extracts normalized submission settings from a form record.
	 *
	 * @param object|null $form_record Form database row.
	 * @return array<string, mixed>
	 */
	private function extract_settings_from_record( $form_record ) {
		$defaults = array(
			'submission_type'   => 'ajax',
			'enable_ajax'       => true,
			'enable_redirect'   => false,
			'redirect_url'      => '',
			'thank_you_message' => __( 'Thanks! Your form was submitted successfully.', 'shapeforms' ),
			'button_text'       => __( 'Submit', 'shapeforms' ),
			'button_alignment'  => 'left',
			'button_layout'     => 'below',
			'button_color'      => 'teal',
			'field_style'            => '',
			'field_size'             => '',
			'field_focus_color'      => '',
			'field_border_width'     => '',
			'field_border_radius'    => '',
			'field_background_color' => '',
			'field_border_color'     => '',
			'field_text_color'       => '',
			'label_size'             => '',
			'label_color'            => '',
			'label_subtext_color' => '',
			'error_color'          => '',
			'button_size'          => '',
			'button_border_style' => '',
			'button_border_width' => '',
			'button_border_radius' => '',
			'button_background_color' => '',
			'button_border_color' => '',
			'button_text_color' => '',
			'admin_email_type'   => 'site_admin',
			'enable_admin_email' => true,
			'enable_user_email' => true,
			'admin_email'        => '',
		);

		if ( ! $form_record || empty( $form_record->settings_json ) ) {
			return $defaults;
		}

		$decoded = json_decode( (string) $form_record->settings_json, true );

		if ( ! is_array( $decoded ) ) {
			return $defaults;
		}

		$submission_type  = isset( $decoded['submission_type'] ) && in_array( $decoded['submission_type'], array( 'ajax', 'redirect' ), true )
			? $decoded['submission_type']
			: ( ! empty( $decoded['enable_redirect'] ) ? 'redirect' : 'ajax' );
		$admin_email      = isset( $decoded['admin_email'] ) ? sanitize_email( (string) $decoded['admin_email'] ) : '';
		$admin_email_type = isset( $decoded['admin_email_type'] ) && in_array( $decoded['admin_email_type'], array( 'site_admin', 'custom' ), true )
			? $decoded['admin_email_type']
			: ( $admin_email ? 'custom' : 'site_admin' );

		$redirect_type = isset( $decoded['redirect_type'] ) && in_array( $decoded['redirect_type'], array( 'page', 'custom' ), true )
			? $decoded['redirect_type']
			: ( ! empty( $decoded['redirect_url'] ) ? 'custom' : 'page' );

		return array(
			'submission_type'   => $submission_type,
			'enable_ajax'       => 'ajax' === $submission_type,
			'enable_redirect'   => 'redirect' === $submission_type,
			'redirect_type'     => $redirect_type,
			'redirect_url'      => isset( $decoded['redirect_url'] ) ? esc_url_raw( (string) $decoded['redirect_url'] ) : '',
			// Rich markup: filtered with the post allowlist, matching the save path. This is
			// the filter the AJAX success message is rendered from, so it runs on every
			// submission rather than trusting what the row happens to hold.
			'thank_you_message' => isset( $decoded['thank_you_message'] ) ? wp_kses_post( (string) $decoded['thank_you_message'] ) : $defaults['thank_you_message'],
			'button_text'       => isset( $decoded['button_text'] ) ? sanitize_text_field( (string) $decoded['button_text'] ) : $defaults['button_text'],
			'button_alignment'  => isset( $decoded['button_alignment'] ) && in_array( $decoded['button_alignment'], array( 'left', 'center', 'right' ), true ) ? $decoded['button_alignment'] : $defaults['button_alignment'],
			'button_layout'     => isset( $decoded['button_layout'] ) && in_array( $decoded['button_layout'], array( 'below', 'inline' ), true ) ? $decoded['button_layout'] : $defaults['button_layout'],
			'button_icon_type'     => isset( $decoded['button_icon_type'] ) && in_array( $decoded['button_icon_type'], array( 'none', 'dashicon', 'svg' ), true ) ? $decoded['button_icon_type'] : 'none',
			'button_icon_dashicon' => isset( $decoded['button_icon_dashicon'] ) ? sanitize_text_field( (string) $decoded['button_icon_dashicon'] ) : '',
			'button_icon_svg'      => isset( $decoded['button_icon_svg'] ) ? esc_url_raw( (string) $decoded['button_icon_svg'] ) : '',
			'button_icon_position' => isset( $decoded['button_icon_position'] ) && in_array( $decoded['button_icon_position'], array( 'left', 'right' ), true ) ? $decoded['button_icon_position'] : 'right',
			'button_icon_gap'      => isset( $decoded['button_icon_gap'] ) ? absint( $decoded['button_icon_gap'] ) : 8,
			'button_icon_size'     => isset( $decoded['button_icon_size'] ) ? absint( $decoded['button_icon_size'] ) : 18,
			'button_icon_color'    => isset( $decoded['button_icon_color'] ) && sanitize_hex_color( $decoded['button_icon_color'] ) ? sanitize_hex_color( $decoded['button_icon_color'] ) : '',
			'button_color'      => isset( $decoded['button_color'] ) && in_array( $decoded['button_color'], array( 'teal', 'blue', 'green', 'red', 'dark' ), true ) ? $decoded['button_color'] : $defaults['button_color'],
			'field_style'            => isset( $decoded['field_style'] ) && in_array( $decoded['field_style'], array( 'solid', 'dashed', 'none', 'outline', 'soft', 'minimal' ), true ) ? $decoded['field_style'] : '',
			'field_size'             => isset( $decoded['field_size'] ) && in_array( $decoded['field_size'], array( 'small', 'medium', 'large', 'compact', 'comfortable', 'spacious' ), true ) ? $decoded['field_size'] : '',
			'field_focus_color'      => isset( $decoded['field_focus_color'] ) && in_array( $decoded['field_focus_color'], array( 'teal', 'blue', 'green', 'dark' ), true ) ? $decoded['field_focus_color'] : '',
			'field_border_width'     => isset( $decoded['field_border_width'] ) && '' !== $decoded['field_border_width'] ? max( 0, min( 10, absint( $decoded['field_border_width'] ) ) ) : '',
			'field_border_radius'    => isset( $decoded['field_border_radius'] ) && '' !== $decoded['field_border_radius'] ? max( 0, min( 50, absint( $decoded['field_border_radius'] ) ) ) : '',
			'field_background_color' => isset( $decoded['field_background_color'] ) && sanitize_hex_color( $decoded['field_background_color'] ) ? sanitize_hex_color( $decoded['field_background_color'] ) : '',
			'field_border_color'     => isset( $decoded['field_border_color'] ) && sanitize_hex_color( $decoded['field_border_color'] ) ? sanitize_hex_color( $decoded['field_border_color'] ) : '',
			'field_text_color'       => isset( $decoded['field_text_color'] ) && sanitize_hex_color( $decoded['field_text_color'] ) ? sanitize_hex_color( $decoded['field_text_color'] ) : '',
			'label_size'             => isset( $decoded['label_size'] ) && in_array( $decoded['label_size'], array( 'small', 'medium', 'large' ), true ) ? $decoded['label_size'] : '',
			'label_color'            => isset( $decoded['label_color'] ) && sanitize_hex_color( $decoded['label_color'] ) ? sanitize_hex_color( $decoded['label_color'] ) : '',
			'label_subtext_color' => isset( $decoded['label_subtext_color'] ) && sanitize_hex_color( $decoded['label_subtext_color'] ) ? sanitize_hex_color( $decoded['label_subtext_color'] ) : '',
			'error_color'          => isset( $decoded['error_color'] ) && sanitize_hex_color( $decoded['error_color'] ) ? sanitize_hex_color( $decoded['error_color'] ) : '',
			'button_size'          => isset( $decoded['button_size'] ) && in_array( $decoded['button_size'], array( 'small', 'medium', 'large' ), true ) ? $decoded['button_size'] : '',
			'button_border_style' => isset( $decoded['button_border_style'] ) && in_array( $decoded['button_border_style'], array( 'solid', 'dashed', 'none' ), true ) ? $decoded['button_border_style'] : '',
			'button_border_width' => isset( $decoded['button_border_width'] ) && '' !== $decoded['button_border_width'] ? max( 0, min( 10, absint( $decoded['button_border_width'] ) ) ) : '',
			'button_border_radius' => isset( $decoded['button_border_radius'] ) && '' !== $decoded['button_border_radius'] ? max( 0, min( 50, absint( $decoded['button_border_radius'] ) ) ) : '',
			'button_background_color' => isset( $decoded['button_background_color'] ) && sanitize_hex_color( $decoded['button_background_color'] ) ? sanitize_hex_color( $decoded['button_background_color'] ) : '',
			'button_border_color' => isset( $decoded['button_border_color'] ) && sanitize_hex_color( $decoded['button_border_color'] ) ? sanitize_hex_color( $decoded['button_border_color'] ) : '',
			'button_text_color' => isset( $decoded['button_text_color'] ) && sanitize_hex_color( $decoded['button_text_color'] ) ? sanitize_hex_color( $decoded['button_text_color'] ) : '',
			'admin_email_type'   => $admin_email_type,
			'enable_admin_email' => isset( $decoded['enable_admin_email'] ) ? (bool) $decoded['enable_admin_email'] : $defaults['enable_admin_email'],
			'enable_user_email' => isset( $decoded['enable_user_email'] ) ? (bool) $decoded['enable_user_email'] : $defaults['enable_user_email'],
			'admin_email'        => $admin_email,
			// Integrations — assigned connections + field mapping (saved by shapeforms_form_settings_extra filter).
			'assigned_connections' => isset( $decoded['assigned_connections'] ) && is_array( $decoded['assigned_connections'] ) ? $decoded['assigned_connections'] : array(),
			'connection_field_map' => isset( $decoded['connection_field_map'] ) && is_array( $decoded['connection_field_map'] ) ? $decoded['connection_field_map'] : array(),
			// Duplicate-submission prevention (configured in the builder, enforced in check_duplicate_entry()).
			'dup_enabled'  => ! empty( $decoded['dup_enabled'] ),
			'dup_method'   => isset( $decoded['dup_method'] ) && in_array( $decoded['dup_method'], array( 'email', 'ip', 'field' ), true ) ? $decoded['dup_method'] : 'email',
			'dup_field_id' => isset( $decoded['dup_field_id'] ) ? sanitize_key( (string) $decoded['dup_field_id'] ) : '',
			'dup_message'  => isset( $decoded['dup_message'] ) ? sanitize_textarea_field( (string) $decoded['dup_message'] ) : '',
			// Pro: Scheduling.
			'schedule_open_date'      => isset( $decoded['schedule_open_date'] )      ? sanitize_text_field( (string) $decoded['schedule_open_date'] )  : '',
			'schedule_close_date'     => isset( $decoded['schedule_close_date'] )     ? sanitize_text_field( (string) $decoded['schedule_close_date'] ) : '',
			'schedule_tz'             => isset( $decoded['schedule_tz'] )             ? sanitize_text_field( (string) $decoded['schedule_tz'] )         : '',
			'schedule_closed_msg'     => isset( $decoded['schedule_closed_msg'] )     ? wp_kses_post( (string) $decoded['schedule_closed_msg'] )        : '',
			'schedule_before_msg'     => isset( $decoded['schedule_before_msg'] )     ? wp_kses_post( (string) $decoded['schedule_before_msg'] )        : '',
			'schedule_show_countdown' => ! empty( $decoded['schedule_show_countdown'] ),
		);
	}

	/**
	 * Returns normalized captcha settings from global plugin options.
	 *
	 * @return array<string, string>
	 */
	private function get_captcha_settings() {
		$saved = get_option( 'shapeforms_lite_settings', array() );
		$saved = is_array( $saved ) ? $saved : array();

		$provider = isset( $saved['captcha_provider'] ) ? sanitize_key( (string) $saved['captcha_provider'] ) : 'simple_math';
		$provider = in_array( $provider, array( 'recaptcha', 'hcaptcha', 'turnstile', 'simple_math' ), true ) ? $provider : 'simple_math';

		return array(
			'provider'             => $provider,
			'recaptcha_secret_key' => isset( $saved['recaptcha_secret_key'] ) ? sanitize_text_field( (string) $saved['recaptcha_secret_key'] ) : '',
			'hcaptcha_secret_key'  => isset( $saved['hcaptcha_secret_key'] ) ? sanitize_text_field( (string) $saved['hcaptcha_secret_key'] ) : '',
			'turnstile_secret_key' => isset( $saved['turnstile_secret_key'] ) ? sanitize_text_field( (string) $saved['turnstile_secret_key'] ) : '',
		);
	}

	/**
	 * Validates the active captcha provider before processing field data.
	 *
	 * @param array<string, string> $captcha Captcha settings.
	 * @param array<string, mixed>  $request Submission payload.
	 * @return array<string, mixed>
	 */
	private function validate_captcha( $captcha, $request ) {
		if ( 'simple_math' === $captcha['provider'] ) {
			return $this->validate_simple_math_captcha( $request );
		}

		if ( 'recaptcha' !== $captcha['provider'] && 'hcaptcha' !== $captcha['provider'] && 'turnstile' !== $captcha['provider'] ) {
			return array(
				'success' => true,
				'message' => '',
			);
		}

		if ( 'recaptcha' === $captcha['provider'] ) {
			$secret = $captcha['recaptcha_secret_key'];
			$token  = isset( $request['g-recaptcha-response'] ) ? sanitize_text_field( wp_unslash( $request['g-recaptcha-response'] ) ) : '';
			$api    = 'https://www.google.com/recaptcha/api/siteverify';
		} elseif ( 'turnstile' === $captcha['provider'] ) {
			$secret = $captcha['turnstile_secret_key'];
			$token  = isset( $request['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $request['cf-turnstile-response'] ) ) : '';
			$api    = 'https://challenges.cloudflare.com/turnstile/v0/siteverify'; // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- server-side captcha verification, documented under == External Services ==; no asset reaches the page.
		} else {
			$secret = $captcha['hcaptcha_secret_key'];
			$token  = isset( $request['h-captcha-response'] ) ? sanitize_text_field( wp_unslash( $request['h-captcha-response'] ) ) : '';
			$api    = 'https://hcaptcha.com/siteverify';
		}

		if ( empty( $secret ) ) {
			return array(
				'success' => false,
				'message' => __( 'Captcha is enabled but not configured correctly.', 'shapeforms' ),
			);
		}

		if ( '' === $token ) {
			return array(
				'success' => false,
				'message' => __( 'Please complete the captcha challenge.', 'shapeforms' ),
			);
		}

		$response = wp_remote_post(
			$api,
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $secret,
					'response' => $token,
					'remoteip' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => __( 'Captcha verification failed. Please try again.', 'shapeforms' ),
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['success'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Captcha verification failed. Please try again.', 'shapeforms' ),
			);
		}

		return array(
			'success' => true,
			'message' => '',
		);
	}

	/**
	 * Validates the built-in simple math captcha.
	 *
	 * @param array<string, mixed> $request Submission payload.
	 * @return array<string, mixed>
	 */
	private function validate_simple_math_captcha( $request ) {
		$challenge = isset( $request['shapeforms_math_captcha_challenge'] ) ? sanitize_text_field( wp_unslash( $request['shapeforms_math_captcha_challenge'] ) ) : '';
		$hash      = isset( $request['shapeforms_math_captcha_hash'] ) ? sanitize_text_field( wp_unslash( $request['shapeforms_math_captcha_hash'] ) ) : '';
		$answer    = isset( $request['shapeforms_math_captcha_answer'] ) ? absint( wp_unslash( $request['shapeforms_math_captcha_answer'] ) ) : 0;

		if ( '' === $challenge || '' === $hash ) {
			return array(
				'success' => false,
				'message' => __( 'Captcha verification failed. Please refresh the page and try again.', 'shapeforms' ),
			);
		}

		if ( 1 !== preg_match( '/^\d+\+\d+$/', $challenge ) ) {
			return array(
				'success' => false,
				'message' => __( 'Captcha verification failed. Please refresh the page and try again.', 'shapeforms' ),
			);
		}

		list( $left_number, $right_number ) = array_map( 'intval', explode( '+', $challenge ) );
		$expected_answer = $left_number + $right_number;
		$expected_hash   = wp_hash( $challenge . '|' . $expected_answer );

		if ( ! hash_equals( $expected_hash, $hash ) || $answer !== $expected_answer ) {
			return array(
				'success' => false,
				'message' => __( 'Please solve the math captcha correctly.', 'shapeforms' ),
			);
		}

		return array(
			'success' => true,
			'message' => '',
		);
	}

	/**
	 * Flattens row/column structure into a field list for validation.
	 *
	 * @param array<int, array<string, mixed>> $rows Row structure.
	 * @return array<int, array<string, mixed>>
	 */
	private function flatten_structure_fields( $rows ) {
		$fields = array();

		// Validation works against a flat list, so collapse the saved layout into field definitions only.
		foreach ( $rows as $row ) {
			if ( empty( $row['columns'] ) || ! is_array( $row['columns'] ) ) {
				continue;
			}

			foreach ( $row['columns'] as $column ) {
				if ( empty( $column['fields'] ) || ! is_array( $column['fields'] ) ) {
					continue;
				}

				$fields = array_merge( $fields, $column['fields'] );
			}
		}

		return $fields;
	}

	/**
	 * Checks a flattened field list for a specific field type.
	 *
	 * @param array<int, array<string, mixed>> $fields Field definitions.
	 * @param string                           $field_type Field type.
	 * @return bool
	 */
	private function structure_contains_field_type( $fields, $field_type ) {
		foreach ( $fields as $field ) {
			if ( isset( $field['type'] ) && $field_type === $field['type'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Validates configured fields and sanitizes submitted values.
	 *
	 * @param array<int, array<string, mixed>> $fields  Field definitions.
	 * @param array<string, mixed>             $request Request payload.
	 * @return array<string, mixed>
	 */
	private function validate_and_sanitize_fields( $fields, $request ) {
		$errors     = array();
		$entry_data = array();

		foreach ( $fields as $field ) {
			$type     = isset( $field['type'] ) ? sanitize_key( (string) $field['type'] ) : 'text';
			$label    = isset( $field['label'] ) ? sanitize_text_field( (string) $field['label'] ) : '';
			$required = ! empty( $field['required'] );

			$skip_types = apply_filters( 'shapeforms_skip_field_types', array( 'captcha', 'section_break', 'submit', 'paragraph', 'html_editor' ) );
			if ( in_array( $type, $skip_types, true ) ) {
				continue;
			}

			$field_id = ! empty( $field['id'] ) ? sanitize_key( (string) $field['id'] ) : wp_unique_id( 'field_' );
			$key      = 'shapeforms_' . $field_id;

			// Handle file uploads separately.
			if ( 'file' === $type ) {
				$file_result = $this->handle_file_upload( $key, $field, $label, $required );

				if ( ! empty( $file_result['error'] ) ) {
					$errors[ $field_id ] = $file_result['error'];
					continue;
				}

				if ( ! empty( $file_result['url'] ) ) {
					$entry_data[ $field_id ] = array(
						'label' => $label,
						'type'  => 'file',
						'value' => $file_result['url'],
						'path'  => $file_result['path'],
					);
				}

				continue;
			}

			$raw      = isset( $request[ $key ] ) ? wp_unslash( $request[ $key ] ) : null;
			$options  = isset( $field['options'] ) && is_array( $field['options'] ) ? $this->normalize_options( $field['options'] ) : array();
			$value    = $this->sanitize_field_value( $type, $raw );

			// A provided-but-malformed email would otherwise be blanked by sanitize_email()
			// and reported as "required" (or silently saved empty). Flag it as invalid instead.
			//
			// The third clause covers the opposite failure, which is worse than blanking:
			// sanitize_email() REPAIRS as readily as it blanks. "a@b.com\nBcc: x@y.com"
			// comes back as the perfectly is_email()-valid "a@b.comBccxy.com", so without
			// this the submission is accepted and a DIFFERENT address than the visitor
			// typed is stored.
			//
			// It tests for control characters rather than requiring the value to survive
			// sanitizing unchanged. "Unchanged" looks stricter and is, but it rejects
			// addresses that are perfectly real: PHP's trim() only strips ASCII
			// whitespace while sanitize_email() strips everything an address cannot
			// contain, so the two disagree on a trailing NBSP, a BOM, a zero-width
			// space or a curly apostrophe — every one of which arrives routinely from
			// pasting an address out of Outlook, Word or a web page. The front-end
			// validator uses jQuery's $.trim(), which DOES strip those, so such a
			// submission passes the client and is refused by the server while looking
			// flawless on screen.
			//
			// Control characters are what actually make a value dangerous, and nothing
			// else here needs to be rejected: an address that survives with a stray
			// invisible character is still mailed to the right place, and a value that
			// sanitizing repaired can never become a recipient anyway — the email
			// handler's valid_addresses() re-checks byte-identity at the point of
			// sending, which is where it matters.
			if ( 'email' === $type && is_string( $raw ) && '' !== trim( (string) $raw )
				&& ( '' === $value || ! is_email( (string) $value )
					|| preg_match( '/[\x00-\x1F\x7F]/', (string) $raw ) ) ) {
				$errors[ $field_id ] = sprintf(
					/* translators: %s: field label */
					__( '%s must be a valid email address.', 'shapeforms' ),
					$label ? $label : __( 'This field', 'shapeforms' )
				);
				continue;
			}

			if ( $required && $this->is_empty_value( $value ) ) {
				$custom_error = isset( $field['custom_error'] ) ? sanitize_text_field( (string) $field['custom_error'] ) : '';
				if ( $custom_error ) {
					$errors[ $field_id ] = $custom_error;
				} else {
					$global   = get_option( 'shapeforms_lite_settings', array() );
					$type_msg = ! empty( $global[ 'required_msg_' . $type ] ) ? $global[ 'required_msg_' . $type ] : '';
					$errors[ $field_id ] = $type_msg ? $type_msg : sprintf(
						/* translators: %s: field label */
						__( '%s is required.', 'shapeforms' ),
						$label ? $label : __( 'This field', 'shapeforms' )
					);
				}
				continue;
			}

			if ( ! $this->validate_field_value( $type, $value, $options, $field ) ) {
				$errors[ $field_id ] = sprintf(
					/* translators: %s: field label */
					__( '%s contains an invalid value.', 'shapeforms' ),
					$label ? $label : __( 'This field', 'shapeforms' )
				);
				continue;
			}

			/**
			 * Filter the validation error for a single field.
			 *
			 * Pro can add custom validation rules (e.g. regex patterns, date ranges,
			 * conditional required). Return a non-empty string to mark the field invalid.
			 *
			 * @param string               $error    Empty string means valid; non-empty means the error message.
			 * @param mixed                $value    Sanitized field value.
			 * @param array<string, mixed> $field    Field definition.
			 * @param array<string, mixed> $request  Full submission payload (for cross-field rules).
			 */
			$custom_validation_error = apply_filters( 'shapeforms_validate_field', '', $value, $field, $request );
			if ( ! empty( $custom_validation_error ) ) {
				$errors[ $field_id ] = sanitize_text_field( (string) $custom_validation_error );
				continue;
			}

			$entry_data[ $field_id ] = array(
				'label' => $label,
				'type'  => $type,
				'value' => $value,
			);
		}

		return array(
			'errors'     => $errors,
			'entry_data' => $entry_data,
		);
	}

	/**
	 * Sanitizes a submitted field value by type.
	 *
	 * @param string            $type Field type.
	 * @param string|array|null $raw  Raw submitted value.
	 * @return string|array<int, string>
	 */
	private function sanitize_field_value( $type, $raw ) {
		/**
		 * Short-circuits field-value sanitization for a given field type.
		 *
		 * Returning a non-null value fully replaces Lite's built-in sanitization,
		 * letting add-ons (ShapeForms Pro) correctly handle custom field types whose
		 * values Lite's generic text sanitizer would otherwise damage — e.g. array
		 * values (matrix grids, geolocation, multi image-choice) coerced to '', or
		 * HTML (rich text) that would be tag-stripped. Implementations MUST return
		 * an already-sanitized value, or null to defer to Lite.
		 *
		 * @since 1.0.0
		 *
		 * @param mixed  $pre  Null to defer to Lite; any other value short-circuits.
		 * @param string $type Field type slug.
		 * @param mixed  $raw  Raw submitted value (string or array).
		 */
		$pre = apply_filters( 'shapeforms_pre_sanitize_field_value', null, $type, $raw );
		if ( null !== $pre ) {
			return $pre;
		}

		if ( 'checkbox' === $type || 'multiselect' === $type ) {
			if ( ! is_array( $raw ) ) {
				return is_scalar( $raw ) && '' !== (string) $raw ? array( sanitize_text_field( (string) $raw ) ) : array();
			}

			return array_values(
				array_filter(
					array_map(
						static function ( $v ) {
							return sanitize_text_field( (string) ( $v ?? '' ) );
						},
						$raw
					),
					static function ( $value ) {
						return '' !== $value;
					}
				)
			);
		}

		if ( 'name' === $type ) {
			if ( ! is_array( $raw ) ) {
				return array();
			}
			$sanitized = array();
			foreach ( array( 'first', 'middle', 'last' ) as $sub_key ) {
				$sanitized[ $sub_key ] = isset( $raw[ $sub_key ] ) ? sanitize_text_field( (string) $raw[ $sub_key ] ) : '';
			}
			return $sanitized;
		}

		if ( 'address' === $type ) {
			if ( ! is_array( $raw ) ) {
				return array();
			}
			$sanitized = array();
			foreach ( array( 'street', 'city', 'state', 'zip', 'country' ) as $sub_key ) {
				$sanitized[ $sub_key ] = isset( $raw[ $sub_key ] ) ? sanitize_text_field( (string) $raw[ $sub_key ] ) : '';
			}
			return $sanitized;
		}

		$raw = is_scalar( $raw ) ? (string) $raw : '';

		if ( 'textarea' === $type ) {
			return sanitize_textarea_field( $raw );
		}

		if ( 'email' === $type ) {
			return sanitize_email( $raw );
		}

		if ( 'number' === $type ) {
			return is_numeric( $raw ) ? (string) $raw : '';
		}

		if ( 'url' === $type ) {
			return esc_url_raw( $raw );
		}

		if ( 'tel' === $type ) {
			return preg_replace( '/[^\d\s\+\-\(\)\.]/i', '', $raw );
		}

		if ( 'star_rating' === $type ) {
			return (string) absint( $raw );
		}

		if ( 'slider_range' === $type || 'numeric' === $type ) {
			// Dual-handle slider submits "lo - hi"; keep both numeric parts.
			if ( 'slider_range' === $type && false !== strpos( $raw, ' - ' ) ) {
				$pair = array_map( 'trim', explode( ' - ', $raw, 2 ) );
				if ( 2 === count( $pair ) && is_numeric( $pair[0] ) && is_numeric( $pair[1] ) ) {
					return $pair[0] . ' - ' . $pair[1];
				}
				return '';
			}
			return is_numeric( $raw ) ? (string) $raw : '';
		}

		if ( 'html_editor' === $type ) {
			return wp_kses_post( $raw );
		}

		return sanitize_text_field( $raw );
	}

	/**
	 * Validates a sanitized field value.
	 *
	 * @param string                    $type    Field type.
	 * @param string|array<int, string> $value   Sanitized value.
	 * @param array<int, string>        $options Allowed option values.
	 * @param array<string, mixed>      $field   Field definition (for min/max/step bounds).
	 * @return bool
	 */
	private function validate_field_value( $type, $value, $options = array(), $field = array() ) {
		if ( 'email' === $type && '' !== $value ) {
			return false !== is_email( (string) $value );
		}

		if ( 'number' === $type && '' !== $value ) {
			return is_numeric( $value ) && $this->is_within_numeric_bounds( (float) $value, $field );
		}

		if ( in_array( $type, array( 'select', 'radio' ), true ) && is_array( $value ) ) {
			return false;
		}

		if ( in_array( $type, array( 'select', 'radio' ), true ) && '' !== $value ) {
			return in_array( (string) $value, $options, true );
		}

		if ( 'checkbox' === $type || 'multiselect' === $type ) {
			if ( ! is_array( $value ) ) {
				return false;
			}

			foreach ( $value as $item ) {
				if ( ! in_array( $item, $options, true ) ) {
					return false;
				}
			}
		}

		if ( 'star_rating' === $type && '' !== $value ) {
			$int_val = (int) $value;
			return $int_val >= 0 && $int_val <= 10;
		}

		if ( ( 'numeric' === $type || 'slider_range' === $type ) && '' !== $value ) {
			// Accept the dual-handle "lo - hi" format as well as a single number.
			if ( 'slider_range' === $type && false !== strpos( $value, ' - ' ) ) {
				if ( ! preg_match( '/^(-?\d+(?:\.\d+)?)\s-\s(-?\d+(?:\.\d+)?)$/', $value, $matches ) ) {
					return false;
				}
				$lo = (float) $matches[1];
				$hi = (float) $matches[2];
				return $lo <= $hi
					&& $this->is_within_numeric_bounds( $lo, $field )
					&& $this->is_within_numeric_bounds( $hi, $field );
			}
			return is_numeric( $value ) && $this->is_within_numeric_bounds( (float) $value, $field );
		}

		if ( 'country' === $type && '' !== $value ) {
			return is_string( $value ) && '' !== $value;
		}

		return true;
	}

	/**
	 * Checks a numeric value against a field's configured min/max/step bounds.
	 *
	 * Mirrors the browser's native min/max/step enforcement so a crafted POST
	 * that bypasses the client-side constraints cannot store an out-of-range
	 * value. Returns true when the field configures no bounds.
	 *
	 * @param float                $num   Numeric value to test.
	 * @param array<string, mixed> $field Field definition.
	 * @return bool
	 */
	private function is_within_numeric_bounds( $num, $field ) {
		if ( ! is_array( $field ) ) {
			return true;
		}

		$has_min  = isset( $field['min_value'] ) && '' !== (string) $field['min_value'] && is_numeric( $field['min_value'] );
		$has_max  = isset( $field['max_value'] ) && '' !== (string) $field['max_value'] && is_numeric( $field['max_value'] );
		$has_step = isset( $field['step_value'] ) && '' !== (string) $field['step_value'] && is_numeric( $field['step_value'] ) && (float) $field['step_value'] > 0;

		if ( $has_min && $num < (float) $field['min_value'] ) {
			return false;
		}

		if ( $has_max && $num > (float) $field['max_value'] ) {
			return false;
		}

		if ( $has_step ) {
			$step  = (float) $field['step_value'];
			$base  = $has_min ? (float) $field['min_value'] : 0.0;
			$steps = ( $num - $base ) / $step;
			// Allow a small tolerance so fractional steps (e.g. 0.1) are not rejected by float error.
			if ( abs( $steps - round( $steps ) ) > 0.0001 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Handles a single file upload field.
	 *
	 * @param string              $key      Form field name.
	 * @param array<string,mixed> $field    Field definition.
	 * @param string              $label    Field label.
	 * @param bool                $required Whether the field is required.
	 * @return array<string, string> Array with 'url', 'path', or 'error' key.
	 */
	private function handle_file_upload( $key, $field, $label, $required ) {
		// Nonce is verified in process_submission() before this private method is called.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$file_error = isset( $_FILES[ $key ]['error'] ) ? absint( $_FILES[ $key ]['error'] ) : UPLOAD_ERR_NO_FILE; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- integer cast via absint().
		if ( empty( $_FILES[ $key ]['name'] ) || UPLOAD_ERR_NO_FILE === $file_error ) {
			if ( $required ) {
				$custom_error = isset( $field['custom_error'] ) ? sanitize_text_field( (string) $field['custom_error'] ) : '';

				return array(
					'error' => $custom_error ? $custom_error : sprintf(
						/* translators: %s: field label */
						__( '%s is required.', 'shapeforms' ),
						$label ? $label : __( 'This field', 'shapeforms' )
					),
				);
			}

			return array();
		}

		$file = $_FILES[ $key ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- handled by wp_handle_upload.
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Reject anything that is not a genuine HTTP POST upload. This defends against
		// $_FILES spoofing and stops arbitrary local-file references from reaching
		// wp_handle_upload(); it also guarantees filesize() below reads real bytes.
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return array(
				'error' => sprintf(
					/* translators: %s: field label */
					__( '%s could not be uploaded. Please try again.', 'shapeforms' ),
					$label ? $label : __( 'File', 'shapeforms' )
				),
			);
		}

		// Hard-block SVG/SVGZ and inline-renderable HTML/script types on front-end uploads
		// regardless of the field's allowed types. SVGs can carry executable scripts and
		// HTML/PHP files can host stored XSS or code when served inline, so they must never
		// be accepted from untrusted submitters.
		$submitted_ext   = strtolower( pathinfo( sanitize_file_name( (string) $file['name'] ), PATHINFO_EXTENSION ) );
		$blocked_exts    = array( 'svg', 'svgz', 'htm', 'html', 'xhtml', 'shtml', 'xht', 'phtml', 'php', 'php3', 'php4', 'php5', 'phps' );
		if ( in_array( $submitted_ext, $blocked_exts, true ) ) {
			return array(
				'error' => sprintf(
					/* translators: 1: field label, 2: blocked file extension */
					__( '%1$s: .%2$s files are not allowed.', 'shapeforms' ),
					$label ? $label : __( 'File', 'shapeforms' ),
					$submitted_ext
				),
			);
		}

		// Validate file size. Use the field's configured "Max file size (MB)" when set
		// (stored as an integer MB in the builder), otherwise fall back to 2 MB. Pro can
		// still override via the filter.
		$default_max_mb = ( isset( $field['max_file_size'] ) && (int) $field['max_file_size'] > 0 )
			? (int) $field['max_file_size']
			: 2;
		/**
		 * Filter the maximum file upload size in megabytes.
		 *
		 * @param int                  $max_mb Max upload size in MB (per-field value, default 2).
		 * @param array<string, mixed> $field  Field definition.
		 */
		$max_mb    = apply_filters( 'shapeforms_max_file_size', $default_max_mb, $field );
		$max_bytes = $max_mb * 1024 * 1024;

		// Use the real on-disk size, not the client-supplied $file['size'] (spoofable).
		// Fail closed if the size cannot be read (filesize() returns false) rather than
		// casting false to 0 and silently passing the check.
		$real_size = filesize( $file['tmp_name'] );
		if ( false === $real_size || $real_size > $max_bytes ) {
			return array(
				'error' => sprintf(
					/* translators: 1: field label, 2: max file size in MB */
					__( '%1$s exceeds the maximum file size of %2$d MB.', 'shapeforms' ),
					$label ? $label : __( 'File', 'shapeforms' ),
					$max_mb
				),
			);
		}

		// Validate file type.
		$allowed = isset( $field['allowed_types'] ) && '' !== $field['allowed_types']
			? array_filter( array_map( 'trim', explode( ',', (string) $field['allowed_types'] ) ) )
			: array();

		if ( ! empty( $allowed ) ) {
			$ext = '.' . strtolower( pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_EXTENSION ) );

			$match = false;

			foreach ( $allowed as $allowed_ext ) {
				if ( strtolower( $allowed_ext ) === $ext || '.' . strtolower( $allowed_ext ) === $ext ) {
					$match = true;
					break;
				}
			}

			if ( ! $match ) {
				return array(
					'error' => sprintf(
						/* translators: 1: field label, 2: allowed extensions list */
						__( '%1$s must be one of: %2$s', 'shapeforms' ),
						$label ? $label : __( 'File', 'shapeforms' ),
						implode( ', ', $allowed )
					),
				);
			}
		} else {
			// No per-field allowlist: still verify the real file content resolves to a
			// WordPress-recognized type whose MIME matches the extension (finfo-backed),
			// so a spoofed-content or unknown-type file is rejected rather than stored.
			$checked = wp_check_filetype_and_ext( $file['tmp_name'], sanitize_file_name( (string) $file['name'] ) );

			if ( empty( $checked['ext'] ) || empty( $checked['type'] ) ) {
				return array(
					'error' => sprintf(
						/* translators: %s: field label */
						__( '%s: this file type is not allowed.', 'shapeforms' ),
						$label ? $label : __( 'File', 'shapeforms' )
					),
				);
			}
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// When the field restricts types, hand wp_handle_upload an explicit mimes map so
		// WordPress verifies the real file content (via finfo) against the allowed
		// extensions, rather than trusting the client-supplied filename alone.
		$upload_overrides = array( 'test_form' => false );

		if ( ! empty( $allowed ) ) {
			$mimes     = array();
			$all_mimes = wp_get_mime_types();

			foreach ( $allowed as $allowed_ext ) {
				$ext_clean = ltrim( strtolower( (string) $allowed_ext ), '.' );

				foreach ( $all_mimes as $ext_pattern => $mime ) {
					if ( in_array( $ext_clean, explode( '|', $ext_pattern ), true ) ) {
						$mimes[ $ext_pattern ] = $mime;
						break;
					}
				}
			}

			if ( ! empty( $mimes ) ) {
				$upload_overrides['mimes'] = $mimes;
			}
		}

		$upload = wp_handle_upload( $file, $upload_overrides );

		if ( ! empty( $upload['error'] ) ) {
			return array( 'error' => sanitize_text_field( $upload['error'] ) );
		}

		return array(
			'url'  => $upload['url'],
			'path' => $upload['file'],
		);
	}

	/**
	 * Determines whether a sanitized value should be treated as empty.
	 *
	 * @param string|array<int, string> $value Sanitized value.
	 * @return bool
	 */
	private function is_empty_value( $value ) {
		if ( is_array( $value ) ) {
			// Structured fields (name, address) always submit their sub-keys, so an
			// array of blank sub-values is NOT empty under empty(); treat the field as
			// filled only when at least one (possibly nested) sub-value is non-empty.
			foreach ( $value as $sub_value ) {
				if ( is_array( $sub_value ) ) {
					if ( ! $this->is_empty_value( $sub_value ) ) {
						return false;
					}
				} elseif ( '' !== trim( (string) $sub_value ) ) {
					return false;
				}
			}
			return true;
		}

		return '' === trim( (string) $value );
	}

	/**
	 * Persists a submission to the entries table and returns the new entry ID.
	 *
	 * Public so Pro modules (e.g. payment) can save deferred entries.
	 *
	 * @param int                                 $form_id    Form ID.
	 * @param array<string, array<string, mixed>> $entry_data Sanitized entry payload.
	 * @param string                              $status     Initial entry status. Whitelisted to the known
	 *                                                        set ('unread', 'read', 'starred', 'spam'); anything
	 *                                                        else falls back to 'unread'. Lets a spam filter store
	 *                                                        a flagged submission straight to the reviewable Spam
	 *                                                        folder. Defaults to 'unread'.
	 * @return int Inserted entry ID, or 0 on failure.
	 */
	public function create_entry( $form_id, $entry_data, $status = 'unread' ) {
		global $wpdb;

		$table_name = $this->plugin->get_entries_table_name();
		$user_ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_textarea_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		// Guard the status to the known set so a caller can never write an arbitrary
		// value into the column.
		if ( ! in_array( $status, array( 'unread', 'read', 'starred', 'spam' ), true ) ) {
			$status = 'unread';
		}

		// Bail before insert if the payload cannot be encoded (e.g. invalid UTF-8),
		// otherwise an empty-data entry would be saved and reported as a success.
		$entry_data_json = wp_json_encode( $entry_data );
		if ( false === $entry_data_json ) {
			return 0;
		}

		$data = array(
			'form_id'          => $form_id,
			'entry_data_json'  => $entry_data_json,
			'submission_key'   => wp_hash( uniqid( 'shapeforms_', true ) ),
			'user_id'          => get_current_user_id(),
			'user_ip'          => $this->sanitize_ip_address( $user_ip ),
			'user_agent'       => $user_agent,
			'status'           => $status,
		);

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table_name,
			$data,
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		return false !== $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Builds a normalized operation result.
	 *
	 * @param bool                       $success Whether the operation succeeded.
	 * @param string                     $message Result message.
	 * @param array<string, string>      $errors  Validation errors.
	 * @return array<string, mixed>
	 */
	private function build_result( $success, $message, $errors, $redirect_url = '' ) {
		return array(
			'success'      => (bool) $success,
			'message'      => (string) $message,
			'errors'       => $errors,
			'redirect_url' => esc_url_raw( (string) $redirect_url ),
		);
	}

	/**
	 * Normalizes field option values.
	 *
	 * @param array<int, mixed> $options Raw option values.
	 * @return array<int, string>
	 */
	private function normalize_options( $options ) {
		$normalized = array();

		foreach ( $options as $option ) {
			$option = sanitize_text_field( (string) $option );

			if ( '' !== $option ) {
				$normalized[] = $option;
			}
		}

		return $normalized;
	}

	/**
	 * Sanitizes and validates a submitted IP address.
	 *
	 * @param string $ip_address Raw IP address.
	 * @return string
	 */
	private function sanitize_ip_address( $ip_address ) {
		$ip_address = trim( (string) $ip_address );

		return filter_var( $ip_address, FILTER_VALIDATE_IP ) ? $ip_address : '';
	}
}
