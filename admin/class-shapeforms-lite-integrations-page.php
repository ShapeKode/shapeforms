<?php
/**
 * ShapeForms Integrations admin page.
 *
 * Unified integrations list — each service is a row with toggle + settings.
 * Free: Mailchimp, Brevo (fully configurable).

 *
 * Connections stored in wp_options under `shapeforms_connections` keyed by type.
 *
 * @package ShapeFormsLite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Integrations admin page handler.
 */
class ShapeForms_Lite_Integrations_Page {

	/**
	 * Plugin instance.
	 *
	 * @var ShapeForms_Lite
	 */
	private $plugin;

	/**
	 * wp_options key for global connections.
	 */
	const OPTION_KEY = 'shapeforms_connections';

	/**
	 * Free connection types.
	 */
	const FREE_TYPES = array( 'mailchimp', 'brevo' );

	/**
	 * All type definitions (extended by Pro via filter).
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static $type_defs = null;

	/**
	 * Constructor.
	 *
	 * @param ShapeForms_Lite $plugin Plugin instance.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu',                              array( $this, 'register_menu' ), 20 );
		add_action( 'wp_ajax_shapeforms_connection_save',        array( $this, 'ajax_save_connection' ) );
		add_action( 'wp_ajax_shapeforms_connection_delete',      array( $this, 'ajax_delete_connection' ) );
		add_action( 'wp_ajax_shapeforms_connection_test',        array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_shapeforms_connection_toggle',      array( $this, 'ajax_toggle_connection' ) );
		add_action( 'wp_ajax_shapeforms_connection_fetch_lists', array( $this, 'ajax_fetch_lists' ) );
		add_action( 'admin_enqueue_scripts',                   array( $this, 'enqueue_assets' ) );
	}

	// =====================================================================
	// Menu
	// =====================================================================

	/**
	 * Registers the Integrations submenu page.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		// This page registers on admin_menu priority 20 — after the core ShapeForms
		// submenus (priority 10) — so pass an explicit position (offset 5) to slot it right
		// after the core items.
		$hook = add_submenu_page(
			'shapeforms',
			__( 'Integrations', 'shapeforms' ),
			__( 'Integrations', 'shapeforms' ),
			'manage_options',
			'shapeforms-lite-integrations',
			array( $this, 'render_page' ),
			5
		);

		// See ShapeForms_Lite_Admin::force_submenu_highlight() for why this is needed —
		// WordPress's own file_exists()-based fallback for the sidebar "current" state
		// is unreliable on some server setups, so it's set explicitly here too.
		if ( $hook ) {
			add_action(
				'load-' . $hook,
				function () {
					$GLOBALS['submenu_file'] = 'shapeforms-lite-integrations';
				}
			);
		}
	}

	// =====================================================================
	// Assets
	// =====================================================================

	/**
	 * Enqueues JS/CSS only on the integrations page.
	 *
	 * @param string $hook Current admin hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, 'shapeforms-lite-integrations' ) ) {
			return;
		}

		// Strip third-party admin notices so only ShapeForms's own show. Defer to
		// in_admin_header (the last hook before notices render) because some plugins
		// register their notices as late as admin_head — after this enqueue pass.
		// Delegates to the main purge so both screens behave identically: core's
		// own notices survive, third-party ones do not.
		$admin = $this->plugin->get_admin();
		add_action(
			'in_admin_header',
			static function () use ( $admin ) {
				$admin->suppress_foreign_notices();
			},
			1000
		);

		// Cache-bust by file mtime (fall back to the plugin version) so CSS/JS
		// edits show without a hard refresh, matching the builder assets.
		$settings_css = SHAPEFORMS_LITE_PATH . 'assets/css/settings.css';
		$int_css      = SHAPEFORMS_LITE_PATH . 'assets/css/integrations-page.css';
		$int_js       = SHAPEFORMS_LITE_PATH . 'assets/js/integrations-page.js';
		$settings_ver = file_exists( $settings_css ) ? (string) filemtime( $settings_css ) : SHAPEFORMS_LITE_VERSION;
		$int_css_ver  = file_exists( $int_css ) ? (string) filemtime( $int_css ) : SHAPEFORMS_LITE_VERSION;
		$int_js_ver   = file_exists( $int_js ) ? (string) filemtime( $int_js ) : SHAPEFORMS_LITE_VERSION;

		wp_enqueue_style(
			'shapeforms-lite-admin',
			SHAPEFORMS_LITE_URL . 'assets/css/settings.css',
			array(),
			$settings_ver
		);

		wp_enqueue_style(
			'shapeforms-lite-integrations-page',
			SHAPEFORMS_LITE_URL . 'assets/css/integrations-page.css',
			array( 'shapeforms-lite-admin' ),
			$int_css_ver
		);

		wp_enqueue_script(
			'shapeforms-lite-integrations-page',
			SHAPEFORMS_LITE_URL . 'assets/js/integrations-page.js',
			array( 'jquery' ),
			$int_js_ver,
			true
		);

		$connections = $this->client_safe_connections();

		wp_localize_script(
			'shapeforms-lite-integrations-page',
			'shapeformsIntegrationsPage',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'shapeforms_integration_nonce' ),
				'connections' => (object) $connections, // keyed by conn_id; api_key stripped
				'typeDefs'    => array_values( $this->get_type_defs() ),
				'i18n'        => array(
					'save'           => __( 'Save', 'shapeforms' ),
					'saving'         => __( 'Saving…', 'shapeforms' ),
					'test'           => __( 'Test Connection', 'shapeforms' ),
					'testing'        => __( 'Testing…', 'shapeforms' ),
					'delete'         => __( 'Delete', 'shapeforms' ),
					'confirmDelete'  => __( 'Remove this connection and its settings?', 'shapeforms' ),
					'selectList'     => __( '— select —', 'shapeforms' ),
					'active'         => __( 'Active', 'shapeforms' ),
					'inactive'       => __( 'Inactive', 'shapeforms' ),
					'testOk'         => __( 'Connection successful!', 'shapeforms' ),
					'testFail'       => __( 'Connection failed: ', 'shapeforms' ),
					'saved'          => __( 'Saved.', 'shapeforms' ),
					'errRequired'    => __( 'API Key is required.', 'shapeforms' ),
					'keepKey'        => __( 'Saved — leave blank to keep current key', 'shapeforms' ),
				),
			)
		);
	}

	// =====================================================================
	// Page render
	// =====================================================================

	/**
	 * Renders the Integrations admin page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		$active_tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_tab  = in_array( $active_tab, array( 'all', 'newsletter', 'crm', 'productivity', 'automation', 'messaging', 'storage' ), true ) ? $active_tab : 'all';
		$type_defs   = $this->get_type_defs();
		$connections = $this->get_all_connections();
		?>
		<?php $this->render_topbar(); ?>
		<div class="wrap shapeforms-int-page">
			<hr class="wp-header-end">

			<div class="shapeforms-int-header">
				<div class="shapeforms-int-header__titles">
					<h1><?php esc_html_e( 'Integrations', 'shapeforms' ); ?></h1>
					<p><?php esc_html_e( 'Connect your forms to email marketing, CRMs, and apps.', 'shapeforms' ); ?></p>
				</div>
				<?php $this->render_header_upgrade(); ?>
			</div>

			<!-- Tabs -->
			<div class="shapeforms-int-tabs">
				<?php
				$tabs = array(
					'all'          => __( 'All', 'shapeforms' ),
					'newsletter'   => __( 'Newsletter', 'shapeforms' ),
					'crm'          => __( 'CRM', 'shapeforms' ),
					'productivity' => __( 'Productivity', 'shapeforms' ),
					'automation'   => __( 'Automation', 'shapeforms' ),
					'messaging'    => __( 'Messaging', 'shapeforms' ),
					'storage'      => __( 'Storage', 'shapeforms' ),
				);
				foreach ( $tabs as $tab_key => $tab_label ) :
				?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=shapeforms-lite-integrations&tab=' . $tab_key ) ); ?>"
					   class="shapeforms-int-tab<?php echo $tab_key === $active_tab ? ' is-active' : ''; ?>">
						<?php echo esc_html( $tab_label ); ?>
					</a>
				<?php endforeach; ?>
			</div>

			<!-- Integration grid -->
			<div class="bf-int-grid">
				<?php foreach ( $type_defs as $type => $def ) :
					if ( 'all' !== $active_tab && ( $def['category'] ?? 'newsletter' ) !== $active_tab ) {
						continue;
					}

					$is_static = ! empty( $def['pro'] );
					// Tied to $is_static: 'locked' only ever means anything on a static
					// (Pro-only) entry. Without this, a filter setting 'locked' on a normal
					// connectable entry would replace its toggle with a dead-end badge and
					// leave no way to configure it.
					$is_locked = $is_static && ! empty( $def['locked'] );
					$conn      = null;

					// Tooltip on a locked card. The generic default deliberately says
					// nothing about why the card is locked: only the add-on that set
					// 'locked' knows that, and it owns the wording through this filter
					// (mirroring shapeforms_library_lock_title / shapeforms_template_lock_title).
					$lock_title = '';
					if ( $is_locked ) {
						/**
						 * Filters the tooltip shown on a locked integration card.
						 *
						 * @since 1.1.7
						 *
						 * @param string $title Tooltip text.
						 * @param string $type  Integration type slug.
						 */
						$lock_title = (string) apply_filters(
							'shapeforms_integration_lock_title',
							__( 'Available in the premium add-on', 'shapeforms' ),
							$type
						);
					}

					if ( ! $is_static ) {
						foreach ( $connections as $c ) {
							if ( ( $c['type'] ?? '' ) === $type ) {
								$conn = $c;
								break;
							}
						}
					}

					$is_on = $conn && 'active' === ( $conn['status'] ?? 'inactive' );
				?>
					<div class="bf-int-card<?php echo $is_on ? ' is-on' : ''; ?><?php echo $is_locked ? ' is-locked' : ''; ?>"
						 <?php if ( ! $is_static ) : ?>data-type="<?php echo esc_attr( $type ); ?>" data-conn-id="<?php echo esc_attr( $conn ? $conn['id'] : '' ); ?>"<?php endif; ?>
						 style="--bf-svc-color:<?php echo esc_attr( $def['color'] ); ?>">

						<div class="bf-int-card__icon" style="background:<?php echo esc_attr( $def['color'] ); ?>">
							<span class="dashicons <?php echo esc_attr( $def['icon'] ); ?>" style="color:<?php echo esc_attr( $def['icon_color'] ); ?>"></span>
						</div>

						<span class="bf-int-card__name"><?php echo esc_html( $def['label'] ); ?></span>
						<span class="bf-int-card__desc"><?php echo esc_html( $def['desc'] ?? '' ); ?></span>

						<div class="bf-int-card__actions">
							<?php if ( $is_locked ) : ?>
								<?php if ( ! empty( $def['locked_url'] ) ) : ?>
									<a class="bf-int-card__locked-badge" href="<?php echo esc_url( $def['locked_url'] ); ?>" title="<?php echo esc_attr( $lock_title ); ?>">
										<span class="dashicons dashicons-lock"></span>
										<?php esc_html_e( 'Locked', 'shapeforms' ); ?>
									</a>
								<?php else : ?>
									<span class="bf-int-card__locked-badge" title="<?php echo esc_attr( $lock_title ); ?>">
										<span class="dashicons dashicons-lock"></span>
										<?php esc_html_e( 'Locked', 'shapeforms' ); ?>
									</span>
								<?php endif; ?>
							<?php elseif ( $is_static ) : ?>
								<span class="bf-int-card__pro-note"><?php esc_html_e( 'Available in ShapeForms Pro', 'shapeforms' ); ?></span>
							<?php else : ?>
								<label class="bf-int-toggle" title="<?php echo $is_on ? esc_attr__( 'Disable', 'shapeforms' ) : esc_attr__( 'Enable', 'shapeforms' ); ?>">
									<input type="checkbox" class="bf-toggle-input" data-type="<?php echo esc_attr( $type ); ?>"<?php echo $is_on ? ' checked' : ''; ?>>
									<span class="bf-int-toggle__track"></span>
								</label>

								<button type="button" class="bf-int-card__settings bf-settings-btn" data-type="<?php echo esc_attr( $type ); ?>" title="<?php esc_attr_e( 'Settings', 'shapeforms' ); ?>">
									<span class="dashicons dashicons-admin-generic"></span>
								</button>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<!-- Modal -->
			<div class="bf-conn-modal" id="bf-conn-modal" hidden>
				<div class="bf-conn-modal__backdrop" id="bf-conn-modal-backdrop"></div>
				<div class="bf-conn-modal__dialog" role="dialog" aria-modal="true">
					<div class="bf-conn-modal__head">
						<span class="bf-conn-modal__title" id="bf-conn-modal-title"></span>
						<button type="button" class="bf-conn-modal__close" id="bf-conn-modal-close">&times;</button>
					</div>
					<div class="bf-conn-modal__body" id="bf-conn-modal-body"></div>
					<div class="bf-conn-modal__foot">
						<span class="bf-conn-modal__status" id="bf-conn-modal-status"></span>
						<div class="bf-conn-modal__actions">
							<button type="button" class="button" id="bf-conn-modal-cancel"><?php esc_html_e( 'Cancel', 'shapeforms' ); ?></button>
							<button type="button" class="button button-secondary" id="bf-conn-test-btn"><?php esc_html_e( 'Test Connection', 'shapeforms' ); ?></button>
							<button type="button" class="button button-primary" id="bf-conn-save-btn"><?php esc_html_e( 'Save', 'shapeforms' ); ?></button>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the shared admin topbar.
	 *
	 * @return void
	 */
	private function render_topbar(): void {
		$nav_items = array(
			array( 'slug' => 'shapeforms',              'label' => __( 'Forms', 'shapeforms' ),        'icon' => 'dashicons-feedback', 'brand' => true, 'url' => admin_url( 'admin.php?page=shapeforms' ) ),
			array( 'slug' => 'shapeforms-lite-entries',       'label' => __( 'Entries', 'shapeforms' ),      'icon' => 'dashicons-email-alt',     'url' => admin_url( 'admin.php?page=shapeforms-lite-entries' ) ),
			array( 'slug' => 'shapeforms-lite-reports',       'label' => __( 'Reports', 'shapeforms' ),      'icon' => 'dashicons-chart-bar',     'url' => admin_url( 'admin.php?page=shapeforms-lite-reports' ) ),
			array( 'slug' => 'shapeforms-lite-integrations',  'label' => __( 'Integrations', 'shapeforms' ), 'icon' => 'dashicons-randomize',     'url' => admin_url( 'admin.php?page=shapeforms-lite-integrations' ) ),
			array( 'slug' => 'shapeforms-lite-settings',      'label' => __( 'Settings', 'shapeforms' ),     'icon' => 'dashicons-admin-generic', 'url' => admin_url( 'admin.php?page=shapeforms-lite-settings' ) ),
			array( 'slug' => 'shapeforms-lite-settings#smtp',  'label' => __( 'SMTP', 'shapeforms' ),        'icon' => 'dashicons-email',         'url' => admin_url( 'admin.php?page=shapeforms-lite-settings&tab=smtp' ) ),
			array( 'slug' => 'shapeforms-lite-settings#tools', 'label' => __( 'Tools', 'shapeforms' ),       'icon' => 'dashicons-admin-tools',   'url' => admin_url( 'admin.php?page=shapeforms-lite-settings&tab=tools' ) ),
			array(
				'slug'     => 'shapeforms-lite-docs',
				'label'    => __( 'Documentation', 'shapeforms' ),
				'icon'     => 'dashicons-book',
				'url'      => 'https://documentation.shapekode.com/docs/shapeforms-user-guide/',
				'external' => true,
				'children' => array(
					array( 'label' => __( 'User Documentation', 'shapeforms' ), 'icon' => 'dashicons-admin-users', 'url' => 'https://documentation.shapekode.com/docs/shapeforms-user-guide/', 'external' => true ),
					array( 'label' => __( 'Admin Documentation', 'shapeforms' ), 'icon' => 'dashicons-admin-tools', 'url' => 'https://documentation.shapekode.com/docs/shape-forms-developer-guide/', 'external' => true ),
				),
			),
		);
		$nav_items = apply_filters( 'shapeforms_admin_topbar_items', $nav_items, 'shapeforms-lite-integrations' );

		?>
		<div class="shapeforms-admin-topbar">
			<div class="shapeforms-admin-topbar__brand">
				<?php shapeforms_lite_brand_icon( array( 'class' => 'dashicons shapeforms-brand-icon' ) ); ?>
				<span class="shapeforms-admin-topbar__name"><?php esc_html_e( 'ShapeForms', 'shapeforms' ); ?></span>
				<span class="shapeforms-admin-topbar__version"><?php echo esc_html( SHAPEFORMS_LITE_VERSION ); ?></span>
			</div>
			<nav class="shapeforms-admin-topbar__nav">
				<?php foreach ( $nav_items as $item ) : ?>
					<?php
					$is_active   = 'shapeforms-lite-integrations' === $item['slug'];
					$target_attr = ! empty( $item['external'] ) ? ' target="_blank" rel="noopener noreferrer"' : '';
					?>
					<?php if ( ! empty( $item['children'] ) ) : ?>
						<div class="shapeforms-admin-topbar__item shapeforms-admin-topbar__item--has-children">
							<a href="<?php echo esc_url( $item['url'] ); ?>" class="shapeforms-admin-topbar__link<?php echo $is_active ? ' is-active' : ''; ?>" aria-haspopup="true"<?php echo $target_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute string. ?>>
								<span class="dashicons <?php echo esc_attr( $item['icon'] ); ?>"></span>
								<?php echo esc_html( $item['label'] ); ?>
								<span class="dashicons dashicons-arrow-down-alt2 shapeforms-admin-topbar__caret"></span>
							</a>
							<div class="shapeforms-admin-topbar__dropdown" role="menu">
								<?php foreach ( $item['children'] as $child ) : ?>
									<?php $child_target = ! empty( $child['external'] ) ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>
									<a href="<?php echo esc_url( $child['url'] ); ?>" class="shapeforms-admin-topbar__dropdown-link" role="menuitem"<?php echo $child_target; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute string. ?>>
										<?php if ( ! empty( $child['icon'] ) ) : ?>
											<span class="dashicons <?php echo esc_attr( $child['icon'] ); ?>"></span>
										<?php endif; ?>
										<?php echo esc_html( $child['label'] ); ?>
									</a>
								<?php endforeach; ?>
							</div>
						</div>
					<?php else : ?>
						<a href="<?php echo esc_url( $item['url'] ); ?>" class="shapeforms-admin-topbar__link<?php echo $is_active ? ' is-active' : ''; ?>"<?php echo $target_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute string. ?>>
							<?php if ( ! empty( $item['brand'] ) ) : ?>
								<?php shapeforms_lite_brand_icon( array( 'class' => 'dashicons shapeforms-brand-icon' ) ); ?>
							<?php else : ?>
								<span class="dashicons <?php echo esc_attr( $item['icon'] ); ?>"></span>
							<?php endif; ?>
							<?php echo esc_html( $item['label'] ); ?>
						</a>
					<?php endif; ?>
				<?php endforeach; ?>
			</nav>
		</div>
		<?php
	}

	/**
	 * Renders the "Upgrade to Pro" CTA for the Integrations page header.
	 *
	 * Mirrors ShapeForms_Lite_Admin::render_header_upgrade(); duplicated here because
	 * this page is a separate class. Shown by default; hidden when a callback on
	 * shapeforms_show_upgrade_cta returns false.
	 *
	 * @return void
	 */
	private function render_header_upgrade(): void {
		if ( ! apply_filters( 'shapeforms_show_upgrade_cta', true ) ) {
			return;
		}
		?>
		<a class="shapeforms-header-upgrade" href="https://shapekode.com/" target="_blank" rel="noopener noreferrer">
			<?php esc_html_e( 'Upgrade to Pro', 'shapeforms' ); ?>
		</a>
		<?php
	}

	// =====================================================================
	// Connection CRUD
	// =====================================================================

	/** @return array<string, array<string, mixed>> */
	public function get_all_connections(): array {
		$raw = get_option( self::OPTION_KEY, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Returns all connections with the secret API key stripped, suitable for
	 * sending to the browser. Each connection gains a boolean `has_key` so the
	 * UI can show whether a key is stored without ever exposing the key itself.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function client_safe_connections(): array {
		$safe = array();
		foreach ( $this->get_all_connections() as $id => $conn ) {
			if ( ! is_array( $conn ) ) {
				continue;
			}
			$safe[ $id ] = $this->client_safe_connection( $conn );
		}
		return $safe;
	}

	/**
	 * Strips the secret API key from a single connection and flags whether one
	 * is stored. Pass-through for null so callers can forward a missing lookup.
	 *
	 * @param array<string, mixed>|null $conn Connection config.
	 * @return array<string, mixed>|null
	 */
	public function client_safe_connection( ?array $conn ): ?array {
		if ( ! is_array( $conn ) ) {
			return $conn;
		}
		$conn['has_key'] = ! empty( $conn['api_key'] );
		unset( $conn['api_key'] );
		return $conn;
	}

	/** @return array<string, mixed>|null */
	public function get_connection( string $id ): ?array {
		$all = $this->get_all_connections();
		return $all[ $id ] ?? null;
	}

	/** @return string Connection ID. */
	public function upsert_connection( array $data ): string {
		$all  = $this->get_all_connections();
		$id   = ! empty( $data['id'] ) ? sanitize_key( (string) $data['id'] ) : 'conn_' . wp_generate_uuid4();
		$type = sanitize_key( (string) ( $data['type'] ?? '' ) );

		$normalized = array(
			'id'     => $id,
			'type'   => $type,
			'name'   => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			'status' => ! empty( $data['status'] ) && 'active' === $data['status'] ? 'active' : 'inactive',
		);

		$normalized = array_merge( $normalized, $this->normalize_type_fields( $data, $type ) );

		// Preserve the stored API key when the payload leaves it blank. The key is
		// never sent to the browser, so a save from the settings modal arrives with
		// an empty api_key unless the admin deliberately typed a new one.
		if ( empty( $normalized['api_key'] ) && ! empty( $all[ $id ]['api_key'] ) ) {
			$normalized['api_key'] = $all[ $id ]['api_key'];
		}

		$all[ $id ] = $normalized;
		update_option( self::OPTION_KEY, $all, false );

		return $id;
	}

	/** @return void */
	public function delete_connection( string $id ): void {
		$all = $this->get_all_connections();
		unset( $all[ $id ] );
		update_option( self::OPTION_KEY, $all, false );
	}

	/**
	 * Normalizes type-specific fields.
	 *
	 * @param array<string, mixed> $data Raw data.
	 * @param string               $type Connection type.
	 * @return array<string, mixed>
	 */
	private function normalize_type_fields( array $data, string $type ): array {
		$fields = array(
			'api_key'      => sanitize_text_field( (string) ( $data['api_key'] ?? '' ) ),
			'list_id'      => sanitize_text_field( (string) ( $data['list_id'] ?? '' ) ),
			'tags'         => sanitize_text_field( (string) ( $data['tags'] ?? '' ) ),
			'double_optin' => ! empty( $data['double_optin'] ),
		);

		return (array) apply_filters( 'shapeforms_connection_normalize_fields', $fields, $data, $type );
	}

	// =====================================================================
	// Type definitions
	// =====================================================================

	/** @return array<string, array<string, mixed>> */
	public function get_type_defs(): array {
		if ( self::$type_defs !== null ) {
			return self::$type_defs;
		}

		$defs = array(
			'mailchimp' => array(
				'type'       => 'mailchimp',
				'label'      => 'Mailchimp',
				'icon'       => 'dashicons-email-alt',
				'color'      => '#ffe01b',
				'icon_color' => '#241c15',
				'category'   => 'newsletter',
				'desc'       => __( 'Sync subscribers to a Mailchimp audience.', 'shapeforms' ),
				'list_label' => __( 'Audience', 'shapeforms' ),
				'fields'     => array(
					array( 'key' => 'api_key',      'label' => 'API Key',              'type' => 'password', 'required' => true, 'placeholder' => 'xxxxxxxxxxxx-us6' ),
					array( 'key' => 'list_id',      'label' => 'Audience',             'type' => 'list_select', 'required' => true ),
					array( 'key' => 'tags',         'label' => 'Tags',                 'type' => 'text',     'placeholder' => 'newsletter, webform' ),
					array( 'key' => 'double_optin', 'label' => 'Enable double opt-in', 'type' => 'checkbox' ),
				),
			),
			'brevo' => array(
				'type'       => 'brevo',
				'label'      => 'Brevo',
				'icon'       => 'dashicons-email',
				'color'      => '#0b996e',
				'icon_color' => '#ffffff',
				'category'   => 'newsletter',
				'desc'       => __( 'Add contacts to a Brevo contact list.', 'shapeforms' ),
				'list_label' => __( 'Contact List', 'shapeforms' ),
				// No Tags field: Brevo's POST /v3/contacts endpoint has no tags parameter,
				// so tagging is not supported here (unlike Mailchimp).
				'fields'     => array(
					array( 'key' => 'api_key', 'label' => 'API Key',      'type' => 'password', 'required' => true, 'placeholder' => 'xkeysib-…' ),
					array( 'key' => 'list_id', 'label' => 'Contact List', 'type' => 'list_select', 'required' => true ),
				),
			),
			// Additional types — shown as static cards. Pro replaces with functional entries via filter.
			// ── Newsletter ──
			'activecampaign' => array(
				'type'       => 'activecampaign',
				'label'      => 'ActiveCampaign',
				'icon'       => 'dashicons-megaphone',
				'color'      => '#356ae6',
				'icon_color' => '#fff',
				'category'   => 'newsletter',
				'desc'       => __( 'Add contacts and apply tags in ActiveCampaign.', 'shapeforms' ),
				'list_label' => __( 'List', 'shapeforms' ),
				'fields'     => array(),
				'pro'        => true,
			),
			'convertkit' => array(
				'type'       => 'convertkit',
				'label'      => 'Kit (ConvertKit)',
				'icon'       => 'dashicons-rss',
				'color'      => '#fb6970',
				'icon_color' => '#fff',
				'category'   => 'newsletter',
				'desc'       => __( 'Subscribe to a Kit form or sequence.', 'shapeforms' ),
				'list_label' => __( 'Form', 'shapeforms' ),
				'fields'     => array(),
				'pro'        => true,
			),
			'aweber' => array(
				'type'       => 'aweber',
				'label'      => 'AWeber',
				'icon'       => 'dashicons-email-alt2',
				'color'      => '#2c5aa0',
				'icon_color' => '#fff',
				'category'   => 'newsletter',
				'desc'       => __( 'Add subscribers to an AWeber list.', 'shapeforms' ),
				'list_label' => __( 'List', 'shapeforms' ),
				'fields'     => array(),
				'pro'        => true,
			),
			'getresponse' => array(
				'type'       => 'getresponse',
				'label'      => 'GetResponse',
				'icon'       => 'dashicons-email',
				'color'      => '#00baff',
				'icon_color' => '#fff',
				'category'   => 'newsletter',
				'desc'       => __( 'Add contacts to a GetResponse campaign.', 'shapeforms' ),
				'list_label' => __( 'Campaign', 'shapeforms' ),
				'fields'     => array(),
				'pro'        => true,
			),
			'mailerlite' => array(
				'type'       => 'mailerlite',
				'label'      => 'MailerLite',
				'icon'       => 'dashicons-email-alt',
				'color'      => '#09c269',
				'icon_color' => '#fff',
				'category'   => 'newsletter',
				'desc'       => __( 'Add subscribers to a MailerLite group.', 'shapeforms' ),
				'list_label' => __( 'Group', 'shapeforms' ),
				'fields'     => array(),
				'pro'        => true,
			),
			// ── CRM & Apps ──
			'hubspot' => array(
				'type'       => 'hubspot',
				'label'      => 'HubSpot',
				'icon'       => 'dashicons-networking',
				'color'      => '#ff7a59',
				'icon_color' => '#fff',
				'category'   => 'crm',
				'desc'       => __( 'Create or update HubSpot CRM contacts.', 'shapeforms' ),
				'list_label' => __( 'Portal', 'shapeforms' ),
				'fields'     => array(),
				'pro'        => true,
			),
			'zoho' => array(
				'type'       => 'zoho',
				'label'      => 'Zoho CRM',
				'icon'       => 'dashicons-groups',
				'color'      => '#e42527',
				'icon_color' => '#fff',
				'category'   => 'crm',
				'desc'       => __( 'Push leads and contacts to Zoho CRM.', 'shapeforms' ),
				'list_label' => __( 'Module', 'shapeforms' ),
				'fields'     => array(),
				'pro'        => true,
			),
			'helpscout' => array(
				'type'       => 'helpscout',
				'label'      => 'Help Scout',
				'icon'       => 'dashicons-sos',
				'color'      => '#1292ee',
				'icon_color' => '#fff',
				'category'   => 'crm',
				'desc'       => __( 'Create conversations in Help Scout.', 'shapeforms' ),
				'list_label' => __( 'Mailbox', 'shapeforms' ),
				'fields'     => array(),
				'pro'        => true,
			),
			'fluentcrm' => array(
				'type'       => 'fluentcrm',
				'label'      => 'FluentCRM',
				'icon'       => 'dashicons-admin-users',
				'color'      => '#7742e6',
				'icon_color' => '#fff',
				'category'   => 'crm',
				'desc'       => __( 'Add contacts and tags in FluentCRM.', 'shapeforms' ),
				'list_label' => __( 'List', 'shapeforms' ),
				'fields'     => array(),
				'pro'        => true,
			),
			'googlesheets' => array(
				'type'       => 'googlesheets',
				'label'      => 'Google Sheets',
				'icon'       => 'dashicons-media-spreadsheet',
				'color'      => '#34a853',
				'icon_color' => '#fff',
				'category'   => 'storage',
				'desc'       => __( 'Append each submission as a new row.', 'shapeforms' ),
				'list_label' => __( 'Spreadsheet', 'shapeforms' ),
				'fields'     => array(),
				'pro'        => true,
			),
			'dropbox' => array(
				'type'       => 'dropbox',
				'label'      => 'Dropbox',
				'icon'       => 'dashicons-cloud-saved',
				'color'      => '#0061fe',
				'icon_color' => '#fff',
				'category'   => 'storage',
				'desc'       => __( 'Upload file submissions to Dropbox.', 'shapeforms' ),
				'list_label' => __( 'Folder', 'shapeforms' ),
				'fields'     => array(),
				'pro'        => true,
			),
			'slack' => array(
				'type'       => 'slack',
				'label'      => 'Slack',
				'icon'       => 'dashicons-format-chat',
				'color'      => '#4a154b',
				'icon_color' => '#fff',
				'category'   => 'messaging',
				'desc'       => __( 'Post a notification to a Slack channel.', 'shapeforms' ),
				'list_label' => __( 'Channel', 'shapeforms' ),
				'fields'     => array(),
				'pro'        => true,
			),
			// ── Newsletter (new) ──
			'constantcontact' => array(
				'type'       => 'constantcontact',
				'label'      => 'Constant Contact',
				'icon'       => 'dashicons-email-alt',
				'color'      => '#0076be',
				'icon_color' => '#fff',
				'category'   => 'newsletter',
				'desc'       => __( 'Add contacts to a Constant Contact list.', 'shapeforms' ),
				'list_label' => __( 'List', 'shapeforms' ),
				'fields'     => array(),
				'pro'        => true,
			),
			'drip' => array(
				'type'       => 'drip',
				'label'      => 'Drip',
				'icon'       => 'dashicons-migrate',
				'color'      => '#613ebe',
				'icon_color' => '#fff',
				'category'   => 'newsletter',
				'desc'       => __( 'Subscribe to a Drip campaign.', 'shapeforms' ),
				'list_label' => __( 'Campaign', 'shapeforms' ),
				'fields'     => array(),
				'pro'        => true,
			),
			'moosend' => array(
				'type'       => 'moosend',
				'label'      => 'Moosend',
				'icon'       => 'dashicons-email',
				'color'      => '#02b15a',
				'icon_color' => '#fff',
				'category'   => 'newsletter',
				'desc'       => __( 'Add subscribers to a Moosend mailing list.', 'shapeforms' ),
				'list_label' => __( 'Mailing List', 'shapeforms' ),
				'fields'     => array(),
				'pro'        => true,
			),
			// ── CRM (new) ──
			'salesforce' => array(
				'type'       => 'salesforce',
				'label'      => 'Salesforce',
				'icon'       => 'dashicons-cloud',
				'color'      => '#00a1e0',
				'icon_color' => '#fff',
				'category'   => 'crm',
				'desc'       => __( 'Create leads or contacts in Salesforce.', 'shapeforms' ),
				'list_label' => __( 'Object', 'shapeforms' ),
				'fields'     => array(),
				'pro'        => true,
			),
			'pipedrive' => array(
				'type'       => 'pipedrive',
				'label'      => 'Pipedrive',
				'icon'       => 'dashicons-chart-line',
				'color'      => '#017737',
				'icon_color' => '#fff',
				'category'   => 'crm',
				'desc'       => __( 'Create deals and contacts in Pipedrive.', 'shapeforms' ),
				'list_label' => __( 'Pipeline', 'shapeforms' ),
				'fields'     => array(),
				'pro'        => true,
			),
			'freshsales' => array(
				'type'       => 'freshsales',
				'label'      => 'Freshsales',
				'icon'       => 'dashicons-businessman',
				'color'      => '#f36c21',
				'icon_color' => '#fff',
				'category'   => 'crm',
				'desc'       => __( 'Create leads in Freshsales CRM.', 'shapeforms' ),
				'list_label' => __( 'View', 'shapeforms' ),
				'fields'     => array(),
				'pro'        => true,
			),
			'monday' => array(
				'type'       => 'monday',
				'label'      => 'Monday.com',
				'icon'       => 'dashicons-grid-view',
				'color'      => '#ff3d57',
				'icon_color' => '#fff',
				'category'   => 'crm',
				'desc'       => __( 'Create items on a Monday.com board.', 'shapeforms' ),
				'list_label' => __( 'Board', 'shapeforms' ),
				'fields'     => array(),
				'pro'        => true,
			),
			// ── Productivity ──
			'notion' => array(
				'type'       => 'notion',
				'label'      => 'Notion',
				'icon'       => 'dashicons-editor-table',
				'color'      => '#000000',
				'icon_color' => '#fff',
				'category'   => 'productivity',
				'desc'       => __( 'Add entries to a Notion database.', 'shapeforms' ),
				'list_label' => __( 'Database', 'shapeforms' ),
				'fields'     => array(),
				'pro'        => true,
			),
			'airtable' => array(
				'type'       => 'airtable',
				'label'      => 'Airtable',
				'icon'       => 'dashicons-layout',
				'color'      => '#18bfff',
				'icon_color' => '#fff',
				'category'   => 'productivity',
				'desc'       => __( 'Create records in an Airtable base.', 'shapeforms' ),
				'list_label' => __( 'Table', 'shapeforms' ),
				'fields'     => array(),
				'pro'        => true,
			),
			'trello' => array(
				'type'       => 'trello',
				'label'      => 'Trello',
				'icon'       => 'dashicons-columns',
				'color'      => '#0052cc',
				'icon_color' => '#fff',
				'category'   => 'productivity',
				'desc'       => __( 'Create cards on a Trello board.', 'shapeforms' ),
				'list_label' => __( 'List', 'shapeforms' ),
				'fields'     => array(),
				'pro'        => true,
			),
			'asana' => array(
				'type'       => 'asana',
				'label'      => 'Asana',
				'icon'       => 'dashicons-list-view',
				'color'      => '#f06a6a',
				'icon_color' => '#fff',
				'category'   => 'productivity',
				'desc'       => __( 'Create tasks in an Asana project.', 'shapeforms' ),
				'list_label' => __( 'Project', 'shapeforms' ),
				'fields'     => array(),
				'pro'        => true,
			),
			// ── Automation ──
			'zapier' => array(
				'type'       => 'zapier',
				'label'      => 'Zapier',
				'icon'       => 'dashicons-controls-repeat',
				'color'      => '#ff4a00',
				'icon_color' => '#fff',
				'category'   => 'automation',
				'desc'       => __( 'Trigger a Zapier webhook on submission.', 'shapeforms' ),
				'list_label' => __( 'Zap', 'shapeforms' ),
				'fields'     => array(),
				'pro'        => true,
			),
			'make' => array(
				'type'       => 'make',
				'label'      => 'Make (Integromat)',
				'icon'       => 'dashicons-update',
				'color'      => '#6d00cc',
				'icon_color' => '#fff',
				'category'   => 'automation',
				'desc'       => __( 'Trigger a Make scenario via webhook.', 'shapeforms' ),
				'list_label' => __( 'Scenario', 'shapeforms' ),
				'fields'     => array(),
				'pro'        => true,
			),
			'pabbly' => array(
				'type'       => 'pabbly',
				'label'      => 'Pabbly Connect',
				'icon'       => 'dashicons-randomize',
				'color'      => '#333333',
				'icon_color' => '#fff',
				'category'   => 'automation',
				'desc'       => __( 'Trigger a Pabbly Connect workflow.', 'shapeforms' ),
				'list_label' => __( 'Workflow', 'shapeforms' ),
				'fields'     => array(),
				'pro'        => true,
			),
			// ── Messaging ──
			'discord' => array(
				'type'       => 'discord',
				'label'      => 'Discord',
				'icon'       => 'dashicons-format-status',
				'color'      => '#5865f2',
				'icon_color' => '#fff',
				'category'   => 'messaging',
				'desc'       => __( 'Post notifications to a Discord channel.', 'shapeforms' ),
				'list_label' => __( 'Channel', 'shapeforms' ),
				'fields'     => array(),
				'pro'        => true,
			),
			'telegram' => array(
				'type'       => 'telegram',
				'label'      => 'Telegram',
				'icon'       => 'dashicons-share-alt2',
				'color'      => '#26a5e4',
				'icon_color' => '#fff',
				'category'   => 'messaging',
				'desc'       => __( 'Send messages to a Telegram chat.', 'shapeforms' ),
				'list_label' => __( 'Chat', 'shapeforms' ),
				'fields'     => array(),
				'pro'        => true,
			),
			'msteams' => array(
				'type'       => 'msteams',
				'label'      => 'Microsoft Teams',
				'icon'       => 'dashicons-groups',
				'color'      => '#6264a7',
				'icon_color' => '#fff',
				'category'   => 'messaging',
				'desc'       => __( 'Post notifications to a Teams channel.', 'shapeforms' ),
				'list_label' => __( 'Channel', 'shapeforms' ),
				'fields'     => array(),
				'pro'        => true,
			),
			// ── Storage ──
			'googledrive' => array(
				'type'       => 'googledrive',
				'label'      => 'Google Drive',
				'icon'       => 'dashicons-cloud-upload',
				'color'      => '#4285f4',
				'icon_color' => '#fff',
				'category'   => 'storage',
				'desc'       => __( 'Upload file submissions to Google Drive.', 'shapeforms' ),
				'list_label' => __( 'Folder', 'shapeforms' ),
				'fields'     => array(),
				'pro'        => true,
			),
		);

		self::$type_defs = (array) apply_filters( 'shapeforms_integration_type_defs', $defs );

		return self::$type_defs;
	}

	// =====================================================================
	// AJAX handlers
	// =====================================================================

	/** AJAX: save connection settings. */
	public function ajax_save_connection(): void {
		check_ajax_referer( 'shapeforms_integration_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'shapeforms' ) ), 403 );
		}

		$data = isset( $_POST['connection'] ) ? map_deep( wp_unslash( $_POST['connection'] ), 'sanitize_text_field' ) : array();

		if ( empty( $data['type'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Type is required.', 'shapeforms' ) ) );
		}

		$data['type'] = sanitize_key( $data['type'] );

		// Use type label as default name.
		if ( empty( $data['name'] ) ) {
			$defs = $this->get_type_defs();
			$data['name'] = $defs[ $data['type'] ]['label'] ?? $data['type'];
		}

		$id         = $this->upsert_connection( $data );
		$connection = $this->client_safe_connection( $this->get_connection( $id ) );

		wp_send_json_success( array( 'connection' => $connection ) );
	}

	/** AJAX: delete connection. */
	public function ajax_delete_connection(): void {
		check_ajax_referer( 'shapeforms_integration_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'shapeforms' ) ), 403 );
		}

		$id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		if ( $id ) {
			$this->delete_connection( $id );
		}
		wp_send_json_success();
	}

	/** AJAX: toggle connection active/inactive. */
	public function ajax_toggle_connection(): void {
		check_ajax_referer( 'shapeforms_integration_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'shapeforms' ) ), 403 );
		}

		$type   = isset( $_POST['type'] )   ? sanitize_key( wp_unslash( $_POST['type'] ) )   : '';
		$enable = isset( $_POST['enable'] ) && 'true' === $_POST['enable']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( ! $type ) {
			wp_send_json_error( array( 'message' => __( 'Missing type.', 'shapeforms' ) ) );
		}

		// Find existing connection for this type.
		$connections = $this->get_all_connections();
		$found       = null;
		foreach ( $connections as $c ) {
			if ( ( $c['type'] ?? '' ) === $type ) {
				$found = $c;
				break;
			}
		}

		if ( $enable ) {
			// Refuse to activate an unconfigured connection — it would show as "on"
			// while every dispatch silently no-ops. Tell the UI to open the settings
			// modal so the admin can finish setup first.
			if ( ! $found ) {
				wp_send_json_error(
					array(
						'message' => __( 'Configure this integration in settings before enabling it.', 'shapeforms' ),
						'code'    => 'needs_setup',
					)
				);
			}

			// Gate on the type's REQUIRED fields rather than hardcoding api_key, so
			// keyless integrations work too: API-key types need their key, Google
			// Sheets needs its service-account JSON + spreadsheet ID, FluentCRM needs
			// a chosen list. Any empty required field is the same silent-no-op risk.
			$def = $this->get_type_defs()[ $type ] ?? array();
			foreach ( ( $def['fields'] ?? array() ) as $def_field ) {
				if ( empty( $def_field['required'] ) ) {
					continue;
				}
				$field_key = (string) ( $def_field['key'] ?? '' );
				if ( '' !== $field_key && empty( $found[ $field_key ] ) ) {
					wp_send_json_error(
						array(
							'message' => __( 'Complete the required settings before enabling this integration.', 'shapeforms' ),
							'code'    => 'needs_setup',
						)
					);
				}
			}

			// Activate the existing, configured connection.
			$found['status'] = 'active';
			$id   = $this->upsert_connection( $found );
			$conn = $this->client_safe_connection( $this->get_connection( $id ) );
			wp_send_json_success( array( 'connection' => $conn ) );
		} else {
			// Deactivate (keep settings).
			if ( $found ) {
				$found['status'] = 'inactive';
				$this->upsert_connection( $found );
			}
			wp_send_json_success();
		}
	}

	/** AJAX: test connection (validate + fetch lists). */
	public function ajax_test_connection(): void {
		check_ajax_referer( 'shapeforms_integration_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'shapeforms' ) ), 403 );
		}

		$type    = isset( $_POST['type'] )    ? sanitize_key( wp_unslash( $_POST['type'] ) )           : '';
		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		$extra = isset( $_POST['extra'] ) ? map_deep( wp_unslash( $_POST['extra'] ), 'sanitize_text_field' ) : array();

		$result = $this->fetch_lists_for_type( $type, $api_key, $extra );

		if ( is_null( $result ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown integration type.', 'shapeforms' ) ) );
		}
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'lists' => $result ) );
	}

	/** AJAX: fetch lists for a saved connection. */
	public function ajax_fetch_lists(): void {
		check_ajax_referer( 'shapeforms_integration_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'shapeforms' ) ), 403 );
		}

		$conn_id = isset( $_POST['conn_id'] ) ? sanitize_key( wp_unslash( $_POST['conn_id'] ) ) : '';
		$conn    = $this->get_connection( $conn_id );

		if ( ! $conn || empty( $conn['api_key'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Connection not found.', 'shapeforms' ) ) );
		}

		$result = $this->fetch_lists_for_type( $conn['type'], $conn['api_key'], $conn );

		if ( is_null( $result ) || is_wp_error( $result ) ) {
			wp_send_json_success( array( 'lists' => array() ) );
		} else {
			wp_send_json_success( array( 'lists' => $result ) );
		}
	}

	/**
	 * Dispatches list-fetch to the correct handler.
	 *
	 * @return array|WP_Error|null
	 */
	public function fetch_lists_for_type( string $type, string $api_key, array $extra = array() ) {
		switch ( $type ) {
			case 'mailchimp':
				return $this->fetch_mailchimp_lists( $api_key );
			case 'brevo':
				return $this->fetch_brevo_lists( $api_key );
			default:
				return apply_filters( 'shapeforms_connection_fetch_lists_' . $type, null, $type, $api_key, $extra );
		}
	}

	// =====================================================================
	// API clients (free)
	// =====================================================================

	/**
	 * Extracts the Mailchimp data center from an API key.
	 *
	 * The data center is the suffix after the final dash — always letters followed
	 * by digits (e.g. us6, us21, eu2). Returns '' when the key has no valid suffix,
	 * so callers never interpolate an arbitrary value into the request host nor
	 * silently fall back to a guessed data center. Shared by the list-fetch (here)
	 * and the dispatcher so both parse the key identically.
	 *
	 * @param string $api_key Mailchimp API key.
	 * @return string Data-center token, or '' when absent/invalid.
	 */
	public static function mailchimp_datacenter( string $api_key ): string {
		if ( preg_match( '/-([a-z]+\d+)$/', trim( $api_key ), $m ) ) {
			return $m[1];
		}
		return '';
	}

	private function fetch_mailchimp_lists( string $api_key ) {
		$api_key = trim( $api_key );
		$dc      = self::mailchimp_datacenter( $api_key );

		if ( '' === $dc ) {
			return new WP_Error( 'mailchimp_error', __( 'API key is missing a valid data-center suffix (for example, -us6).', 'shapeforms' ) );
		}

		$response = wp_remote_get(
			"https://{$dc}.api.mailchimp.com/3.0/lists?count=200&fields=lists.id,lists.name",
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( 'anystring:' . $api_key ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				),
			)
		);

		if ( is_wp_error( $response ) ) return $response;

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 || ! isset( $body['lists'] ) ) {
			return new WP_Error( 'mailchimp_error', $body['detail'] ?? __( 'Invalid API key.', 'shapeforms' ) );
		}

		return array_map( fn( $l ) => array( 'id' => (string) $l['id'], 'name' => (string) $l['name'] ), (array) $body['lists'] );
	}

	private function fetch_brevo_lists( string $api_key ) {
		$response = wp_remote_get(
			'https://api.brevo.com/v3/contacts/lists?limit=50&offset=0',
			array(
				'timeout' => 15,
				'headers' => array( 'api-key' => trim( $api_key ), 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) return $response;

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 || ! isset( $body['lists'] ) ) {
			return new WP_Error( 'brevo_error', $body['message'] ?? __( 'Invalid API key.', 'shapeforms' ) );
		}

		return array_map( fn( $l ) => array( 'id' => (string) $l['id'], 'name' => (string) $l['name'] ), (array) $body['lists'] );
	}
}
