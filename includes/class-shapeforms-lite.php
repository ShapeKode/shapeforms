<?php
/**
 * Core plugin class.
 *
 * @package ShapeFormsLite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin bootstrap.
 */
final class ShapeForms_Lite {

	/**
	 * Single class instance.
	 *
	 * @var ShapeForms_Lite|null
	 */
	private static $instance = null;

	/**
	 * Loader instance.
	 *
	 * @var ShapeForms_Lite_Loader
	 */
	private $loader;

	/**
	 * Admin instance.
	 *
	 * @var ShapeForms_Lite_Admin
	 */
	private $admin;

	/**
	 * Frontend shortcode instance.
	 *
	 * @var ShapeForms_Lite_Shortcode
	 */
	private $shortcode;

	/**
	 * Conversational mode instance.
	 *
	 * @var ShapeForms_Lite_Conversational
	 */
	private $conversational;

	/**
	 * Frontend submission handler.
	 *
	 * @var ShapeForms_Lite_Form_Handler
	 */
	private $form_handler;

	/**
	 * Gutenberg block integration.
	 *
	 * @var ShapeForms_Lite_Block
	 */
	private $block;

	/**
	 * Elementor integration.
	 *
	 * @var ShapeForms_Lite_Elementor
	 */
	private $elementor;

	/**
	 * Email notification handler.
	 *
	 * @var ShapeForms_Lite_Email_Handler
	 */
	private $email_handler;

	/**
	 * Export/Import handler.
	 *
	 * @var ShapeForms_Lite_Export_Import
	 */
	private $export_import;

	/**
	 * Form Migrator controller.
	 *
	 * @var ShapeForms_Lite_Migrator
	 */
	private $migrator;

	/**
	 * Integrations page handler.
	 *
	 * @var ShapeForms_Lite_Integrations_Page
	 */
	private $integrations_page;

	/**
	 * Integrations dispatcher.
	 *
	 * @var ShapeForms_Lite_Integrations
	 */
	private $integrations;

	/**
	 * Privacy (GDPR) export/erase handler.
	 *
	 * @var ShapeForms_Lite_Privacy
	 */
	private $privacy;

	/**
	 * Cache-compatibility (third-party cache purge) handler.
	 *
	 * @var ShapeForms_Lite_Cache
	 */
	private $cache;

	/**
	 * AI form builder instance.
	 *
	 * @var ShapeForms_Lite_AI_Builder
	 */
	private $ai_builder;

	/**
	 * Returns the single instance of the plugin.
	 *
	 * @return ShapeForms_Lite
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {
		// Boot order matters here because several runtime services depend on the loader.
		$this->load_dependencies();
		$this->set_locale();
		$this->define_hooks();
	}

	/**
	 * Prevent clone.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialize.
	 *
	 * @throws Exception Always throws because the plugin uses a singleton.
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize singleton.' );
	}

	/**
	 * Loads core dependencies.
	 *
	 * @return void
	 */
	private function load_dependencies() {
		require_once SHAPEFORMS_LITE_PATH . 'admin/class-shapeforms-lite-admin.php';
		require_once SHAPEFORMS_LITE_PATH . 'public/class-shapeforms-lite-shortcode.php';
		require_once SHAPEFORMS_LITE_PATH . 'public/class-shapeforms-lite-conversational.php';
		require_once SHAPEFORMS_LITE_PATH . 'public/class-shapeforms-lite-form-handler.php';
		require_once SHAPEFORMS_LITE_PATH . 'public/class-shapeforms-lite-block.php';
		require_once SHAPEFORMS_LITE_PATH . 'public/class-shapeforms-lite-elementor.php';
		require_once SHAPEFORMS_LITE_PATH . 'includes/class-shapeforms-lite-email-handler.php';
		require_once SHAPEFORMS_LITE_PATH . 'admin/class-shapeforms-lite-export-import.php';
		require_once SHAPEFORMS_LITE_PATH . 'admin/migrator/interface-shapeforms-lite-migration-source.php';
		require_once SHAPEFORMS_LITE_PATH . 'admin/migrator/class-shapeforms-lite-migration-result.php';
		require_once SHAPEFORMS_LITE_PATH . 'admin/migrator/class-shapeforms-lite-migrator.php';
		require_once SHAPEFORMS_LITE_PATH . 'admin/class-shapeforms-lite-integrations-page.php';
		require_once SHAPEFORMS_LITE_PATH . 'includes/class-shapeforms-lite-integrations.php';
		require_once SHAPEFORMS_LITE_PATH . 'includes/class-shapeforms-lite-privacy.php';
		require_once SHAPEFORMS_LITE_PATH . 'includes/class-shapeforms-lite-cache.php';
		require_once SHAPEFORMS_LITE_PATH . 'includes/class-shapeforms-lite-ai-builder.php';

		$this->loader            = new ShapeForms_Lite_Loader();
		$this->admin             = new ShapeForms_Lite_Admin( $this );
		$this->email_handler     = new ShapeForms_Lite_Email_Handler( $this );
		$this->form_handler      = new ShapeForms_Lite_Form_Handler( $this, $this->email_handler );
		$this->shortcode         = new ShapeForms_Lite_Shortcode( $this, $this->form_handler );
		$this->conversational    = new ShapeForms_Lite_Conversational( $this );
		$this->block             = new ShapeForms_Lite_Block( $this );
		$this->elementor         = new ShapeForms_Lite_Elementor( $this );
		$this->export_import     = new ShapeForms_Lite_Export_Import( $this );
		$this->migrator          = new ShapeForms_Lite_Migrator( $this );
		$this->integrations_page = new ShapeForms_Lite_Integrations_Page( $this );
		$this->integrations      = new ShapeForms_Lite_Integrations( $this, $this->integrations_page );
		$this->privacy           = new ShapeForms_Lite_Privacy( $this );
		$this->cache             = new ShapeForms_Lite_Cache( $this );
		$this->ai_builder        = new ShapeForms_Lite_AI_Builder();
	}

	/**
	 * Loads plugin text domain.
	 *
	 * @return void
	 */
	private function set_locale() {
		$this->loader->add_action( 'init', $this, 'load_textdomain' );
		// Run schema upgrades on admin_init, not plugins_loaded: the plugin boots ON
		// plugins_loaded, so a callback it registers for that same hook is not reliably
		// executed (the hook is already mid-flight). admin_init fires later — after this
		// registration — and is early enough that the schema is ready before the Entries
		// screen or its actions run. The entries tables are only read/written in admin.
		$this->loader->add_action( 'admin_init', $this, 'maybe_upgrade_database', 1 );
	}

	/**
	 * Defines the core hooks.
	 *
	 * @return void
	 */
	private function define_hooks() {
		$this->loader->add_action( 'admin_menu', $this->admin, 'register_menu' );
		$this->loader->add_filter( 'plugin_action_links_' . plugin_basename( SHAPEFORMS_LITE_FILE ), $this->admin, 'add_plugin_action_links' );
		// Every upgrade CTA (the Upgrade menu item/page, header button, plugin-row link,
		// and promo notice) is gated by the shapeforms_show_upgrade_cta filter (default
		// true). Lite does NOT detect the paid add-on — the add-on turns this filter off
		// when installed, so all CTAs hide with zero add-on awareness here.
		// Preview the premium Excel/PDF export beside the free Export CSV button, and on
		// the Tools → Entries export panel. Unconditional free-plugin features — Lite
		// never detects any add-on; they simply respect shapeforms_show_upgrade_cta. An
		// add-on that ships real export turns that filter off (so these bail) and renders
		// its own controls on the same actions (see the paid add-on's entry-export module).
		$this->loader->add_action( 'shapeforms_entries_export_actions', $this->admin, 'render_entries_export_teaser' );
		$this->loader->add_action( 'shapeforms_tools_entries_export_fields', $this->admin, 'render_tools_export_teaser' );
		$this->loader->add_action( 'shapeforms_entry_created', $this->admin, 'clear_unread_count_cache' );
		$this->loader->add_action( 'admin_enqueue_scripts', $this->admin, 'print_menu_icon_styles' );
		$this->loader->add_filter( 'admin_body_class', $this->admin, 'add_admin_body_class' );
		$this->loader->add_action( 'admin_bar_menu', $this->admin, 'register_admin_bar', 100 );
		$this->loader->add_action( 'admin_enqueue_scripts', $this->admin, 'enqueue_assets' );
		$this->loader->add_action( 'admin_init', $this->admin, 'handle_form_actions' );
		$this->loader->add_action( 'wp_ajax_shapeforms_lite_dismiss_notice', $this->admin, 'ajax_dismiss_notice' );
		$this->loader->add_filter( 'upload_mimes', $this->admin, 'allow_svg_upload' );
		$this->loader->add_filter( 'wp_check_filetype_and_ext', $this->admin, 'fix_svg_filetype', 10, 4 );
		$this->loader->add_filter( 'wp_handle_upload_prefilter', $this->admin, 'sanitize_svg_upload' );
		$this->loader->add_action( 'wp_ajax_shapeforms_lite_save_form', $this->admin, 'ajax_save_form' );
		$this->loader->add_action( 'wp_ajax_shapeforms_lite_send_test_mail', $this->admin, 'ajax_send_test_mail' );
		$this->loader->add_action( 'wp_ajax_shapeforms_lite_update_entry_status', $this->admin, 'ajax_update_entry_status' );
		$this->loader->add_action( 'wp_ajax_shapeforms_lite_bulk_entry_action', $this->admin, 'ajax_bulk_entry_action' );
		$this->loader->add_action( 'wp_ajax_shapeforms_lite_toggle_form_status', $this->admin, 'ajax_toggle_form_status' );
		$this->loader->add_action( 'phpmailer_init', $this->admin, 'configure_smtp' );
		$this->loader->add_filter( 'wp_mail_from',      $this->admin, 'filter_mail_from' );
		$this->loader->add_filter( 'wp_mail_from_name', $this->admin, 'filter_mail_from_name' );
		$this->loader->add_action( 'wp_enqueue_scripts', $this->shortcode, 'register_assets' );
		$this->loader->add_action( 'init', $this->shortcode, 'register_shortcode' );
		// Conversational mode. Priority 6 disengages the multi-step renderer
		// (which wraps at 10) before it runs; priority 30 injects the chrome
		// after every consumer at 20, so anything they add to the form is
		// already present and lands on the correct screen.
		$this->loader->add_action( 'wp_enqueue_scripts', $this->conversational, 'register_assets' );
		// The admin form-preview screen is wp-admin, where wp_enqueue_scripts
		// never fires — without this the engine silently does not load there.
		$this->loader->add_action( 'shapeforms_preview_enqueue_assets', $this->conversational, 'enqueue_preview_assets' );
		$this->loader->add_filter( 'shapeforms_form_output', $this->conversational, 'stand_down_multi_page', 6, 4 );
		$this->loader->add_filter( 'shapeforms_form_output', $this->conversational, 'wrap_form_output', 30, 4 );
		$this->loader->add_action( 'init', $this->form_handler, 'handle_submission' );
		$this->loader->add_action( 'wp_ajax_shapeforms_lite_submit_form', $this->form_handler, 'ajax_submit_form' );
		$this->loader->add_action( 'wp_ajax_nopriv_shapeforms_lite_submit_form', $this->form_handler, 'ajax_submit_form' );
		$this->loader->add_action( 'init', $this->block, 'register_block' );
		$this->loader->add_action( 'enqueue_block_editor_assets', $this->block, 'enqueue_editor_assets' );
		$this->loader->add_action( 'elementor/widgets/register', $this->elementor, 'register_widget' );
		$this->loader->add_action( 'elementor/editor/after_enqueue_scripts', $this->elementor, 'enqueue_editor_scripts' );
		$this->loader->add_filter( 'wp_privacy_personal_data_exporters', $this->privacy, 'register_exporter' );
		$this->loader->add_filter( 'wp_privacy_personal_data_erasers', $this->privacy, 'register_eraser' );
		$this->loader->add_action( 'shapeforms_form_saved', $this->cache, 'purge_on_form_saved', 10, 3 );

		// The AI builder owns its own hook set, so it registers them itself rather
		// than listing them here — it is the only collaborator with a REST route,
		// and keeping the route beside the code that serves it means one place to
		// look when it misbehaves.
		$this->ai_builder->register_hooks( $this->loader );

		$this->export_import->init();
		$this->migrator->init();
		$this->integrations_page->init();
		$this->integrations->init();

		$this->loader->run();

		/**
		 * Fires after ShapeForms has fully booted and all hooks are registered.
		 *
		 * Pro or third-party plugins should hook here to extend ShapeForms.
		 *
		 * @param ShapeForms_Lite $plugin The main plugin instance.
		 */
		do_action( 'shapeforms_loaded', $this );
	}

	/**
	 * Returns the forms table name.
	 *
	 * @return string
	 */
	public function get_forms_table_name() {
		global $wpdb;

		return $wpdb->prefix . 'shapeforms_forms';
	}

	/**
	 * Returns the entries table name.
	 *
	 * @return string
	 */
	public function get_entries_table_name() {
		global $wpdb;

		return $wpdb->prefix . 'shapeforms_entries';
	}

	/**
	 * Whether this build provides a named extension point.
	 *
	 * The way for an add-on to ask "can I hook that?" without guessing from a
	 * version number:
	 *
	 *     if ( method_exists( 'ShapeForms_Lite', 'supports' )
	 *         && ShapeForms_Lite::supports( 'admin_email_attachments' ) ) {
	 *         add_filter( 'shapeforms_lite_admin_email_attachments', … );
	 *     }
	 *
	 * The method_exists() guard matters: on a ShapeForms older than this one the
	 * method itself is absent, which correctly reads as "no capabilities".
	 *
	 * @since 1.0.0
	 *
	 * @param string $capability Capability name.
	 * @return bool
	 */
	public static function supports( $capability ) {
		/*
		 * Extension points this build provides, by name.
		 *
		 * An add-on that needs a seam should ask for it here rather than compare
		 * version numbers. The version constant marks what has been *released*,
		 * so between releases it names a build that does not have the seam yet —
		 * which made add-ons hide working features during development, and made
		 * "does this hook exist" unanswerable without knowing the release
		 * calendar. A name is true the moment the seam is real.
		 *
		 * Names, not a number, because features are built on parallel branches:
		 * two branches each adding one key merge cleanly, whereas two branches
		 * each bumping the same integer to 2 do not.
		 *
		 * Entries are permanent. Removing one breaks the add-ons that ask for it,
		 * which is exactly what this is meant to prevent.
		 */
		$capabilities = array(
			// The admin notification's recipient is filterable
			// (shapeforms_lite_admin_email_to), re-validated per address, and the
			// builder renders .shapeforms-email-routing-slot[data-email-slot="admin"].
			// Lets an add-on route a notification by what was answered.
			'admin_email_recipient'   => true,

			// The admin notification's attachment list is filterable
			// (shapeforms_lite_admin_email_attachments) and the builder renders
			// .shapeforms-email-attachment-slot[data-email-slot="admin"].
			'admin_email_attachments' => true,

			// The same for the user confirmation
			// (shapeforms_lite_user_email_attachments, and the slot with
			// data-email-slot="user"). Separate from the admin capability because
			// the two shipped apart: an add-on may need to support a build that
			// has one and not the other.
			'user_email_attachments'  => true,
		);

		return ! empty( $capabilities[ (string) $capability ] );
	}

	/**
	 * Flattens a stored field value into a readable string.
	 *
	 * Multi-part values are stored as arrays: composite fields as associative
	 * arrays (name => {first,middle,last}; address => {street,city,…}) and
	 * checkbox/multiselect as indexed arrays of selected options. Empty parts
	 * (e.g. a blank middle name) are dropped so a name reads "First Last" rather
	 * than "First, , Last". Names join with a space; every other multi-part value
	 * joins with ", ". Scalars pass through unchanged.
	 *
	 * This is the single source of truth for value flattening shared by the admin
	 * entry view, CSV export, email notifications, the privacy exporter, and the
	 * entries-list preview, so their formatting can never drift apart.
	 *
	 * @param mixed  $value Stored field value (string, indexed array, or assoc array).
	 * @param string $type  Field type (only 'name' changes the separator).
	 * @return string
	 */
	public static function format_field_value( $value, $type = '' ) {
		if ( ! is_array( $value ) ) {
			$formatted = is_scalar( $value ) ? (string) $value : '';
		} else {
			$parts = array_filter(
				array_map(
					static function ( $part ) {
						return is_scalar( $part ) ? sanitize_text_field( (string) $part ) : '';
					},
					$value
				),
				static function ( $part ) {
					return '' !== trim( (string) $part );
				}
			);

			$formatted = implode( 'name' === $type ? ' ' : ', ', $parts );
		}

		/**
		 * Filters the human-readable display string for a stored entry value.
		 *
		 * Add-ons (e.g. ShapeForms Pro) use this to render complex value shapes the
		 * default flattener cannot express — signature data URIs, repeater row
		 * sets, geolocation coordinate arrays, image-choice selections. Filtered
		 * values should be PLAIN TEXT: this helper feeds the admin entry view, CSV
		 * export, email notifications and the privacy exporter, each of which
		 * escapes on output, so returning markup here would break CSV/plain-text
		 * surfaces.
		 *
		 * @since 1.0.0
		 *
		 * @param string $formatted Default flattened display string.
		 * @param mixed  $value     Raw stored field value (string or array).
		 * @param string $type      Field type slug.
		 */
		return apply_filters( 'shapeforms_format_field_value', $formatted, $value, $type );
	}

	/**
	 * Placeholder for the translations hook.
	 *
	 * WordPress automatically loads plugin translations since 4.6
	 * for plugins hosted on WordPress.org. No manual call needed.
	 *
	 * @return void
	 */
	public function load_textdomain() {
	}

	/**
	 * Runs database upgrades when the stored schema version is outdated.
	 *
	 * @return void
	 */
	public function maybe_upgrade_database() {
		$installed_version = (string) get_option( 'shapeforms_lite_db_version', '' );

		if ( SHAPEFORMS_LITE_DB_VERSION === $installed_version ) {
			return;
		}

		ShapeForms_Lite_Activator::activate();
	}

	/**
	 * Returns the loader instance.
	 *
	 * @return ShapeForms_Lite_Loader
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Returns the export/import handler.
	 *
	 * @return ShapeForms_Lite_Export_Import
	 */
	public function get_export_import() {
		return $this->export_import;
	}

	/**
	 * Returns the Form Migrator controller.
	 *
	 * @return ShapeForms_Lite_Migrator
	 */
	public function get_migrator() {
		return $this->migrator;
	}

	/**
	 * Returns the admin handler.
	 *
	 * @return ShapeForms_Lite_Admin
	 */
	public function get_admin() {
		return $this->admin;
	}

	/**
	 * Returns the form-submission handler.
	 *
	 * Exposed so an extension can persist an entry itself when it defers the
	 * default save via the `shapeforms_should_save_entry` filter.
	 *
	 * @return ShapeForms_Lite_Form_Handler
	 */
	public function get_form_handler() {
		return $this->form_handler;
	}

	/**
	 * Returns the email notifications handler.
	 *
	 * Exposed so an extension can dispatch the standard notification emails itself
	 * after it has deferred them via the `shapeforms_defer_post_save_actions` filter.
	 *
	 * @return ShapeForms_Lite_Email_Handler
	 */
	public function get_email_handler() {
		return $this->email_handler;
	}
}
