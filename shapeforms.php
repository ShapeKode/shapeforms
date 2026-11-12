<?php
/**
 * Plugin Name:       ShapeForms
 * Plugin URI:        https://shapekode.com/
 * Description:       Lightweight drag and drop form builder for WordPress.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Tested up to:      7.1
 * Requires PHP:      7.4
 * Author:            Shapekode
 * Author URI:        https://shapekode.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shapeforms
 * Domain Path:       /languages
 *
 * @package ShapeFormsLite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SHAPEFORMS_LITE_VERSION', '1.0.0' );
define( 'SHAPEFORMS_LITE_DB_VERSION', '1.0.0' );
define( 'SHAPEFORMS_LITE_FILE', __FILE__ );
define( 'SHAPEFORMS_LITE_PATH', plugin_dir_path( __FILE__ ) );
define( 'SHAPEFORMS_LITE_URL', plugin_dir_url( __FILE__ ) );

/**
 * Appsero project hash for opt-in usage telemetry.
 *
 * This is the public application identifier for the plugin's Appsero project — it
 * is not a secret. Telemetry is still strictly opt-in: Appsero's Insights module
 * collects nothing until the site administrator consents via the admin notice it
 * displays, and a site can disable it entirely by defining this constant as an
 * empty string in wp-config.php (or via the 'shapeforms_lite_appsero_hash' filter).
 */
if ( ! defined( 'SHAPEFORMS_LITE_APPSERO_HASH' ) ) {
	define( 'SHAPEFORMS_LITE_APPSERO_HASH', '34841725-f5f2-4b3a-ade1-864bcbd22b07' );
}

require_once SHAPEFORMS_LITE_PATH . 'includes/class-shapeforms-lite-activator.php';
require_once SHAPEFORMS_LITE_PATH . 'includes/class-shapeforms-lite-loader.php';
require_once SHAPEFORMS_LITE_PATH . 'includes/class-shapeforms-lite.php';

register_activation_hook( SHAPEFORMS_LITE_FILE, array( 'ShapeForms_Lite_Activator', 'activate' ) );

/**
 * Clears plugin-scheduled cron events on deactivation so no orphaned events
 * linger. User data (tables/options) is preserved — only removed on uninstall
 * when the user has opted in.
 *
 * @return void
 */
function shapeforms_lite_deactivate() {
	wp_clear_scheduled_hook( 'shapeforms_integration_dispatch' );
}
register_deactivation_hook( SHAPEFORMS_LITE_FILE, 'shapeforms_lite_deactivate' );

/**
 * One-time migration: stop autoloading the settings option on existing installs.
 *
 * The option holds secrets (SMTP password, captcha secret keys); new saves already
 * pass autoload=false, this flips it for installs created before that change. Runs
 * once in the admin, then a flag short-circuits it.
 *
 * @return void
 */
function shapeforms_lite_migrate_settings_autoload() {
	if ( ! is_admin() || get_option( 'shapeforms_lite_autoload_migrated' ) ) {
		return;
	}

	// Re-add the option with autoload disabled. delete + add keeps this compatible
	// with the plugin's minimum WordPress 6.3 (wp_set_option_autoload() only exists
	// on 6.4+) while producing an identical end state.
	$settings = get_option( 'shapeforms_lite_settings', null );
	if ( null !== $settings ) {
		delete_option( 'shapeforms_lite_settings' );
		add_option( 'shapeforms_lite_settings', $settings, '', 'no' );
	}

	update_option( 'shapeforms_lite_autoload_migrated', 1 );
}
add_action( 'admin_init', 'shapeforms_lite_migrate_settings_autoload' );

// Multisite: create tables for each new subsite when the plugin is network-active.
add_action( 'wp_initialize_site', array( 'ShapeForms_Lite_Activator', 'on_new_site' ) ); // WP 5.1+
add_action( 'wpmu_new_blog',      array( 'ShapeForms_Lite_Activator', 'on_new_site' ) ); // legacy fallback

/**
 * Starts the plugin.
 *
 * @return ShapeForms_Lite
 */
function shapeforms_lite() {
	return ShapeForms_Lite::get_instance();
}

add_action( 'plugins_loaded', 'shapeforms_lite' );

/**
 * Safety net for multisite: if the current site's tables don't exist yet
 * (e.g. plugin was network-activated after the subsite was created), create
 * them now rather than waiting for a manual re-activation.
 */
function shapeforms_lite_maybe_create_tables() {
	if ( ! is_multisite() ) {
		return;
	}

	// Only act as a first-run safety net for a subsite that was never initialized
	// (db-version option absent). Schema upgrades on an already-initialized site are
	// handled by ShapeForms_Lite::maybe_upgrade_database(), so this never runs dbDelta
	// on every page load just because the stored version differs from the constant.
	if ( false !== get_option( 'shapeforms_lite_db_version', false ) ) {
		return;
	}

	ShapeForms_Lite_Activator::create_tables();
	update_option( 'shapeforms_lite_db_version', SHAPEFORMS_LITE_DB_VERSION );
}
add_action( 'plugins_loaded', 'shapeforms_lite_maybe_create_tables' );

/**
 * Resolves the Checkbox & Radio treatment a single field renders in.
 *
 * A field may follow the form (the default, and what every form saved before this
 * option existed does), or pin itself to Default or Button regardless. The pinned
 * values are the only two the form-level setting has, so a field can never ask for
 * a treatment the stylesheet does not implement.
 *
 * @since 1.0.0
 *
 * @param mixed  $field      Field array.
 * @param string $form_style The form-level choice_style.
 * @return string 'default' or 'button'.
 */
function shapeforms_lite_field_choice_style( $field, $form_style ) {
	$own = is_array( $field ) && isset( $field['choice_style'] ) ? (string) $field['choice_style'] : 'inherit';

	if ( in_array( $own, array( 'default', 'button' ), true ) ) {
		return $own;
	}

	return 'button' === $form_style ? 'button' : 'default';
}

/**
 * Every CSS custom property a single Checkbox & Radio field may override.
 *
 * Mirrors bfChoiceAllVars() plus the typography family in assets/js/builder.js.
 * A field stores finished property values keyed by property name — the same shape
 * the form-level Style tab stores — which is what lets one set of controls edit
 * either scope. A property missing from this list is dropped on save, so the
 * builder and the sanitizer cannot disagree about what a field may set.
 *
 * @since 1.0.0
 *
 * @return array<int, string> Allowed custom-property names.
 */
function shapeforms_lite_choice_style_vars() {
	return array(
		// Label.
		'--bf-choice-color',
		'--bf-choice-ff', '--bf-choice-fs', '--bf-choice-fw',
		'--bf-choice-lh', '--bf-choice-ls', '--bf-choice-tt',
		// Default (box) treatment.
		'--bf-choice-size', '--bf-choice-gap',
		'--bf-choice-border', '--bf-choice-bg',
		'--bf-choice-hover-border', '--bf-choice-hover-bg',
		'--bf-choice-accent', '--bf-choice-icon',
		// Button (pill) treatment.
		'--bf-choice-btn-padding', '--bf-choice-btn-radius', '--bf-choice-btn-gap',
		'--bf-choice-btn-border-width', '--bf-choice-btn-border-style', '--bf-choice-btn-border-color',
		'--bf-choice-btn-bg', '--bf-choice-btn-text',
		'--bf-choice-btn-hover-bg', '--bf-choice-btn-hover-text', '--bf-choice-btn-hover-border',
		'--bf-choice-btn-active-bg', '--bf-choice-btn-active-text', '--bf-choice-btn-active-border',
	);
}

/**
 * Sanitizes a per-field Checkbox & Radio override map.
 *
 * Values run through the same strict grammar the form-level Style tab uses
 * (ShapeForms_Lite_Ajax_Save::sanitize_css_value), which accepts finished tokens —
 * lengths, hex/rgba colours, 1-4 length lists, gradients — and rejects anything
 * carrying a colon, semicolon, brace, angle bracket, quote or CSS escape. A value
 * that fails is dropped rather than stored empty, so "inherit the form" stays the
 * absence of a value on both sides of the save.
 *
 * @since 1.0.0
 *
 * @param mixed $source Raw map of property => value.
 * @return array<string, string> Sanitized map.
 */
function shapeforms_lite_sanitize_choice_style( $source ) {
	$out = array();

	if ( ! is_array( $source ) ) {
		return $out;
	}

	$allowed = array_flip( shapeforms_lite_choice_style_vars() );

	foreach ( $source as $css_var => $value ) {
		if ( ! is_string( $css_var ) || ! isset( $allowed[ $css_var ] ) ) {
			continue;
		}

		$clean = ShapeForms_Lite_Ajax_Save::sanitize_css_value( $value );

		if ( '' !== $clean ) {
			$out[ $css_var ] = $clean;
		}
	}

	return $out;
}

/**
 * Builds the inline custom-property declarations for a field's choice overrides.
 *
 * Re-sanitized here rather than trusted from storage: only a value that passes the
 * grammar can reach a style attribute, whatever route it took into the row (a
 * hand-edited export and an import both re-enter through here).
 *
 * @since 1.0.0
 *
 * @param mixed $source Field array, or a bare override map.
 * @return string Declarations without the surrounding attribute.
 */
function shapeforms_lite_choice_style_declarations( $source ) {
	if ( is_array( $source ) && isset( $source['choice_styles'] ) ) {
		$source = $source['choice_styles'];
	}

	$parts = array();

	foreach ( shapeforms_lite_sanitize_choice_style( $source ) as $css_var => $value ) {
		$parts[] = $css_var . ':' . $value;
	}

	return implode( ';', $parts );
}

/**
 * The icon set a single checkbox or radio option may carry.
 *
 * Stroke-drawn 24x24 glyphs, stored as the inner markup of an <svg> rather than a
 * font or a sprite file: the front end must not gain a webfont request for a
 * decoration, and inline markup is the only form that survives the field kses
 * allowlist (which permits svg, path, polyline, line, circle, rect and g, and no
 * other SVG element — a <polygon> or a <use> is silently dropped, so nothing here
 * may use one).
 *
 * Keys are the stored value. They are part of the saved form, so renaming one
 * orphans the icon on every form that chose it; add rather than rename.
 *
 * @since 1.0.0
 *
 * @return array<string, array{label: string, svg: string}> Icon key => label + inner SVG.
 */
function shapeforms_lite_choice_icons() {
	$icons = array(
		// --- General ---
		'check'         => array( 'label' => __( 'Check', 'shapeforms' ), 'svg' => '<path d="M20 6 9 17l-5-5"/>' ),
		'close'         => array( 'label' => __( 'Cross', 'shapeforms' ), 'svg' => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>' ),
		'plus'          => array( 'label' => __( 'Plus', 'shapeforms' ), 'svg' => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>' ),
		'minus'         => array( 'label' => __( 'Minus', 'shapeforms' ), 'svg' => '<line x1="5" y1="12" x2="19" y2="12"/>' ),
		'circle'        => array( 'label' => __( 'Circle', 'shapeforms' ), 'svg' => '<circle cx="12" cy="12" r="10"/>' ),
		'square'        => array( 'label' => __( 'Square', 'shapeforms' ), 'svg' => '<rect x="3" y="3" width="18" height="18" rx="2"/>' ),
		'star'          => array( 'label' => __( 'Star', 'shapeforms' ), 'svg' => '<path d="m12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/>' ),
		'heart'         => array( 'label' => __( 'Heart', 'shapeforms' ), 'svg' => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 1 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>' ),
		'thumbs-up'     => array( 'label' => __( 'Thumbs up', 'shapeforms' ), 'svg' => '<path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/>' ),
		'smile'         => array( 'label' => __( 'Smile', 'shapeforms' ), 'svg' => '<circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/>' ),
		'award'         => array( 'label' => __( 'Award', 'shapeforms' ), 'svg' => '<circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/>' ),
		'flag'          => array( 'label' => __( 'Flag', 'shapeforms' ), 'svg' => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/>' ),
		'bookmark'      => array( 'label' => __( 'Bookmark', 'shapeforms' ), 'svg' => '<path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/>' ),
		'bell'          => array( 'label' => __( 'Bell', 'shapeforms' ), 'svg' => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>' ),
		'info'          => array( 'label' => __( 'Info', 'shapeforms' ), 'svg' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>' ),
		'help'          => array( 'label' => __( 'Question', 'shapeforms' ), 'svg' => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>' ),
		'alert'         => array( 'label' => __( 'Warning', 'shapeforms' ), 'svg' => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>' ),

		// --- People and contact ---
		'user'          => array( 'label' => __( 'Person', 'shapeforms' ), 'svg' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>' ),
		'users'         => array( 'label' => __( 'People', 'shapeforms' ), 'svg' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>' ),
		'mail'          => array( 'label' => __( 'Email', 'shapeforms' ), 'svg' => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22 6 12 13 2 6"/>' ),
		'phone'         => array( 'label' => __( 'Phone', 'shapeforms' ), 'svg' => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>' ),
		'map-pin'       => array( 'label' => __( 'Location', 'shapeforms' ), 'svg' => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>' ),
		'globe'         => array( 'label' => __( 'Globe', 'shapeforms' ), 'svg' => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>' ),
		'home'          => array( 'label' => __( 'Home', 'shapeforms' ), 'svg' => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>' ),
		'briefcase'     => array( 'label' => __( 'Business', 'shapeforms' ), 'svg' => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>' ),

		// --- Commerce ---
		'shopping-cart' => array( 'label' => __( 'Cart', 'shapeforms' ), 'svg' => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>' ),
		'tag'           => array( 'label' => __( 'Tag', 'shapeforms' ), 'svg' => '<path d="M20.59 13.41 13.42 20.58a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>' ),
		'credit-card'   => array( 'label' => __( 'Card', 'shapeforms' ), 'svg' => '<rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>' ),
		'gift'          => array( 'label' => __( 'Gift', 'shapeforms' ), 'svg' => '<polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/>' ),
		'package'       => array( 'label' => __( 'Package', 'shapeforms' ), 'svg' => '<path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>' ),
		'truck'         => array( 'label' => __( 'Delivery', 'shapeforms' ), 'svg' => '<rect x="1" y="3" width="15" height="13"/><path d="M16 8h4l3 3v5h-7z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>' ),

		// --- Media and files ---
		'image'         => array( 'label' => __( 'Image', 'shapeforms' ), 'svg' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>' ),
		'camera'        => array( 'label' => __( 'Camera', 'shapeforms' ), 'svg' => '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>' ),
		'file'          => array( 'label' => __( 'Document', 'shapeforms' ), 'svg' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>' ),
		'folder'        => array( 'label' => __( 'Folder', 'shapeforms' ), 'svg' => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>' ),
		'printer'       => array( 'label' => __( 'Print', 'shapeforms' ), 'svg' => '<polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>' ),
		'monitor'       => array( 'label' => __( 'Screen', 'shapeforms' ), 'svg' => '<rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>' ),
		'smartphone'    => array( 'label' => __( 'Mobile', 'shapeforms' ), 'svg' => '<rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/>' ),
		'wifi'          => array( 'label' => __( 'Wireless', 'shapeforms' ), 'svg' => '<path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/>' ),

		// --- Tools and layout ---
		'tool'          => array( 'label' => __( 'Tools', 'shapeforms' ), 'svg' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>' ),
		'sliders'       => array( 'label' => __( 'Settings', 'shapeforms' ), 'svg' => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>' ),
		'scissors'      => array( 'label' => __( 'Cut', 'shapeforms' ), 'svg' => '<circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><line x1="20" y1="4" x2="8.12" y2="15.88"/><line x1="14.47" y1="14.48" x2="20" y2="20"/><line x1="8.12" y1="8.12" x2="12" y2="12"/>' ),
		'edit'          => array( 'label' => __( 'Edit', 'shapeforms' ), 'svg' => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z"/>' ),
		'type'          => array( 'label' => __( 'Text', 'shapeforms' ), 'svg' => '<polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/>' ),
		'layers'        => array( 'label' => __( 'Layers', 'shapeforms' ), 'svg' => '<path d="m12 2 10 5-10 5L2 7z"/><path d="m2 17 10 5 10-5"/><path d="m2 12 10 5 10-5"/>' ),
		'grid'          => array( 'label' => __( 'Grid', 'shapeforms' ), 'svg' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>' ),
		'maximize'      => array( 'label' => __( 'Size', 'shapeforms' ), 'svg' => '<path d="M8 3H5a2 2 0 0 0-2 2v3"/><path d="M21 8V5a2 2 0 0 0-2-2h-3"/><path d="M3 16v3a2 2 0 0 0 2 2h3"/><path d="M16 21h3a2 2 0 0 0 2-2v-3"/>' ),

		// --- Time, place and weather ---
		'calendar'      => array( 'label' => __( 'Calendar', 'shapeforms' ), 'svg' => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>' ),
		'clock'         => array( 'label' => __( 'Clock', 'shapeforms' ), 'svg' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>' ),
		'sun'           => array( 'label' => __( 'Sun', 'shapeforms' ), 'svg' => '<circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>' ),
		'moon'          => array( 'label' => __( 'Moon', 'shapeforms' ), 'svg' => '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>' ),
		'cloud'         => array( 'label' => __( 'Cloud', 'shapeforms' ), 'svg' => '<path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/>' ),
		'droplet'       => array( 'label' => __( 'Water', 'shapeforms' ), 'svg' => '<path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/>' ),
		'umbrella'      => array( 'label' => __( 'Weather', 'shapeforms' ), 'svg' => '<path d="M23 12a11.05 11.05 0 0 0-22 0zm-5 7a3 3 0 0 1-6 0v-7"/>' ),
		'zap'           => array( 'label' => __( 'Power', 'shapeforms' ), 'svg' => '<path d="M13 2 3 14h9l-1 8 10-12h-9z"/>' ),
		'anchor'        => array( 'label' => __( 'Anchor', 'shapeforms' ), 'svg' => '<circle cx="12" cy="5" r="3"/><line x1="12" y1="22" x2="12" y2="8"/><path d="M5 12H2a10 10 0 0 0 20 0h-3"/>' ),
		'compass'       => array( 'label' => __( 'Compass', 'shapeforms' ), 'svg' => '<circle cx="12" cy="12" r="10"/><path d="m16.24 7.76-2.12 6.36-6.36 2.12 2.12-6.36z"/>' ),

		// --- Data and movement ---
		'bar-chart'     => array( 'label' => __( 'Chart', 'shapeforms' ), 'svg' => '<line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/>' ),
		'trending-up'   => array( 'label' => __( 'Growth', 'shapeforms' ), 'svg' => '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>' ),
		'target'        => array( 'label' => __( 'Target', 'shapeforms' ), 'svg' => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>' ),
		'search'        => array( 'label' => __( 'Search', 'shapeforms' ), 'svg' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>' ),
		'eye'           => array( 'label' => __( 'View', 'shapeforms' ), 'svg' => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>' ),
		'lock'          => array( 'label' => __( 'Lock', 'shapeforms' ), 'svg' => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>' ),
		'shield'        => array( 'label' => __( 'Shield', 'shapeforms' ), 'svg' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>' ),
		'link'          => array( 'label' => __( 'Link', 'shapeforms' ), 'svg' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>' ),
		'send'          => array( 'label' => __( 'Send', 'shapeforms' ), 'svg' => '<line x1="22" y1="2" x2="11" y2="13"/><path d="M22 2 15 22l-4-9-9-4z"/>' ),
		'download'      => array( 'label' => __( 'Download', 'shapeforms' ), 'svg' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>' ),
		'upload'        => array( 'label' => __( 'Upload', 'shapeforms' ), 'svg' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>' ),
		'refresh'       => array( 'label' => __( 'Refresh', 'shapeforms' ), 'svg' => '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>' ),
		'arrow-right'   => array( 'label' => __( 'Arrow', 'shapeforms' ), 'svg' => '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>' ),
		'navigation'    => array( 'label' => __( 'Navigation', 'shapeforms' ), 'svg' => '<path d="M3 11 22 2l-9 19-2-8z"/>' ),
		'book'          => array( 'label' => __( 'Book', 'shapeforms' ), 'svg' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>' ),
		'coffee'        => array( 'label' => __( 'Food & drink', 'shapeforms' ), 'svg' => '<path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/>' ),
	);

	/**
	 * Filters the icons a checkbox or radio option can be given.
	 *
	 * The `svg` of each entry is the INNER markup of a 24x24 stroke-drawn <svg>
	 * and is printed without escaping, so an extension adding one is responsible
	 * for it being safe and static. Only elements Lite's field kses allowlist
	 * permits survive the render (svg, path, polyline, line, circle, rect, g).
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, array{label: string, svg: string}> $icons Icon key => label + inner SVG.
	 */
	return apply_filters( 'shapeforms_choice_icons', $icons );
}

/**
 * Renders one choice icon as inline SVG.
 *
 * @since 1.0.0
 *
 * @param mixed $key  Icon key.
 * @param int   $size Pixel size for the width/height attributes.
 * @return string SVG markup, or '' when the key names no icon.
 */
function shapeforms_lite_choice_icon_svg( $key, $size = 16 ) {
	$key   = is_string( $key ) ? $key : '';
	$icons = shapeforms_lite_choice_icons();

	if ( '' === $key || ! isset( $icons[ $key ]['svg'] ) ) {
		return '';
	}

	$size = max( 8, min( 64, (int) $size ) );

	return '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
		. $icons[ $key ]['svg']
		. '</svg>';
}

/**
 * Sanitizes one option's icon.
 *
 * An option can carry nothing, one of the built-in glyphs, or a file the user
 * picked from the media library — an SVG or a raster image, which are the same
 * thing once uploaded and differ only in what the picker offered. The two are
 * stored differently on purpose: a built-in glyph is a KEY, which survives a site
 * move and follows the pill's colour, while a file is a URL, which does neither.
 * Sniffing one shape for the other would be guesswork, so the file form says what
 * it is.
 *
 * Media-library SVGs are sanitized on upload by
 * ShapeForms_Lite_Admin::sanitize_svg_upload(), so what a URL here points at has
 * already had its script, event handlers and external references stripped.
 *
 * @since 1.0.0
 *
 * @param mixed $raw Raw value.
 * @return string|array{type: string, id: int, url: string} Icon key, image descriptor, or '' for none.
 */
function shapeforms_lite_sanitize_option_icon( $raw ) {
	if ( is_string( $raw ) ) {
		$key   = sanitize_key( $raw );
		$icons = shapeforms_lite_choice_icons();

		return isset( $icons[ $key ] ) ? $key : '';
	}

	if ( ! is_array( $raw ) ) {
		return '';
	}

	$type = isset( $raw['type'] ) ? (string) $raw['type'] : '';

	// The builder writes a bare key for a built-in glyph, but an import or a
	// hand-edited export may spell it out.
	if ( 'icon' === $type ) {
		return shapeforms_lite_sanitize_option_icon( isset( $raw['key'] ) ? $raw['key'] : '' );
	}

	if ( 'image' !== $type ) {
		return '';
	}

	$url = isset( $raw['url'] ) ? esc_url_raw( (string) $raw['url'] ) : '';

	// No URL, no icon: the attachment id alone is a local reference that says
	// nothing to a renderer, and an empty <img> is worse than no icon.
	if ( '' === $url ) {
		return '';
	}

	return array(
		'type' => 'image',
		// Kept so an export can map the file to the destination site's library the
		// way the submit button's icon already does.
		'id'   => isset( $raw['id'] ) ? absint( $raw['id'] ) : 0,
		'url'  => $url,
	);
}

/**
 * Sanitizes the per-option icon list of a checkbox or radio field.
 *
 * Positional: entry N belongs to option N. Icons are optional and most options
 * have none, so an unusable value becomes '' rather than being dropped — dropping
 * it would shift every icon after it onto the wrong option. The list is trimmed to
 * the option count and trailing empties are cut, so a field with no icons at all
 * stores an empty array and adds nothing to the saved form.
 *
 * @since 1.0.0
 *
 * @param mixed $source Raw list.
 * @param int   $count  Number of options the field has.
 * @return array<int, string|array<string, mixed>> Icons, '' where an option has none.
 */
function shapeforms_lite_sanitize_option_icons( $source, $count ) {
	$count = max( 0, (int) $count );

	if ( ! is_array( $source ) || 0 === $count ) {
		return array();
	}

	$out = array();

	for ( $i = 0; $i < $count; $i++ ) {
		$out[] = shapeforms_lite_sanitize_option_icon( isset( $source[ $i ] ) ? $source[ $i ] : '' );
	}

	// Trailing empties carry no information; without this a field whose last icon
	// is cleared keeps growing the stored array every save.
	while ( $out && '' === end( $out ) ) {
		array_pop( $out );
	}

	return array_values( $out );
}

/**
 * Builds the icon element for one option.
 *
 * Emitted whatever the treatment: whether an icon is SHOWN is a CSS question (only
 * the Button treatment has a pill to put one in), and answering it here would mean
 * the renderer had to know a form-level setting that reaches the markup as a class
 * on the form element — which is exactly what an extension rendering its own choice
 * markup cannot see.
 *
 * @since 1.0.0
 *
 * @param mixed $key Icon key, image descriptor, or ''.
 * @return string Markup, or '' when there is no icon.
 */
function shapeforms_lite_choice_icon_html( $key ) {
	$icon = shapeforms_lite_sanitize_option_icon( $key );

	// A media-library file. Drawn as an <img>, which is what the submit button's
	// icon already does: it keeps the file's own colours, and an SVG loaded through
	// <img> cannot run script or reach the network in any browser, whatever the
	// upload sanitizer let through.
	if ( is_array( $icon ) ) {
		return '<span class="shapeforms-lite-form__choice-icon" aria-hidden="true">'
			. '<img src="' . esc_url( $icon['url'] ) . '" alt="">'
			. '</span>';
	}

	$svg = shapeforms_lite_choice_icon_svg( $icon );

	return '' === $svg ? '' : '<span class="shapeforms-lite-form__choice-icon" aria-hidden="true">' . $svg . '</span>';
}

/**
 * Returns the ShapeForms brand mark as inline SVG markup.
 *
 * Single source of truth for the logo across the whole admin UI (menu icon,
 * topbars, builder, stat cards, empty states, etc.). The mark is the ShapeForms "B"
 * monogram with three form lines knocked out of it (a single evenodd path, so the
 * lines read as holes in any single colour). The fill defaults to currentColor so
 * the icon adapts to whatever colour its surrounding context sets (a topbar accent,
 * a white badge, the muted admin-menu icon colour, and so on).
 *
 * The artwork is portrait (a 59×74 viewBox). Callers that pass a `size` get a square
 * box, within which the mark is centred (never distorted); pass size 0 to let CSS
 * size it to the natural aspect ratio.
 *
 * @param array<string, mixed> $args {
 *     Optional. Display arguments.
 *
 *     @type string $class Class attribute for the <svg>. Empty string omits it. Default 'shapeforms-brand-icon'.
 *     @type int    $size  Width/height in px. 0 omits both attributes so CSS sizes it. Default 0.
 *     @type string $fill  Fill colour. Default 'currentColor'.
 * }
 * @return string SVG markup.
 */
function shapeforms_lite_get_brand_icon( $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'class' => 'shapeforms-brand-icon',
			'size'  => 0,
			'fill'  => 'currentColor',
		)
	);

	$size_attr  = $args['size'] ? sprintf( ' width="%1$d" height="%1$d"', absint( $args['size'] ) ) : '';
	$class_attr = '' !== $args['class'] ? sprintf( ' class="%s"', esc_attr( $args['class'] ) ) : '';

	// A geometric "S": one continuous stroke of two stacked half-rounds, drawn as a
	// stroke (not a fill) so the mark stays crisp at 16px in the admin menu.
	return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"' . $size_attr . $class_attr . ' fill="none" stroke="' . esc_attr( $args['fill'] ) . '" stroke-width="10" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
		. '<path d="M50 12H22a10 10 0 0 0 0 20h20a10 10 0 0 1 0 20H14"/>'
		. '</svg>';
}

/**
 * Echoes the ShapeForms brand mark.
 *
 * @see shapeforms_lite_get_brand_icon()
 *
 * @param array<string, mixed> $args Optional. See shapeforms_lite_get_brand_icon().
 * @return void
 */
function shapeforms_lite_brand_icon( $args = array() ) {
	echo shapeforms_lite_get_brand_icon( $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG markup; all dynamic attributes escaped in the getter.
}

/**
 * Boots the Appsero client for opt-in usage telemetry.
 *
 * Appsero's Insights module is strictly opt-in: it gathers nothing until the site
 * administrator agrees via the admin notice it displays. The client is only created
 * when a project hash is configured (constant or filter), so telemetry stays
 * completely dormant otherwise. Runs at plugin-file load so Appsero can register its
 * own activation/deactivation tracking hooks.
 *
 * @return void
 */
function shapeforms_lite_appsero() {
	/**
	 * Filters the Appsero project hash used for opt-in telemetry.
	 *
	 * @param string $hash The Appsero application hash. Empty disables the client.
	 */
	$hash = apply_filters( 'shapeforms_lite_appsero_hash', SHAPEFORMS_LITE_APPSERO_HASH );

	if ( empty( $hash ) ) {
		return;
	}

	if ( ! class_exists( 'Appsero\Client' ) ) {
		$sdk = SHAPEFORMS_LITE_PATH . 'includes/appsero/Client.php';

		if ( ! is_readable( $sdk ) ) {
			return;
		}

		require_once $sdk;
	}

	$client = new Appsero\Client( $hash, 'ShapeForms – Drag &amp; Drop Form Builder', SHAPEFORMS_LITE_FILE );

	// Opt-in usage telemetry only — Appsero shows the consent notice and collects
	// nothing until the administrator allows it.
	$client->insights()->init();
}

shapeforms_lite_appsero();