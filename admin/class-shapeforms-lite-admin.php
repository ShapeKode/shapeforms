<?php
/**
 * Admin functionality for ShapeForms.
 *
 * @package ShapeFormsLite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SHAPEFORMS_LITE_PATH . 'admin/ajax-save.php';

/**
 * Handles WordPress admin UI registration.
 */
class ShapeForms_Lite_Admin {

	/**
	 * User-meta key holding the list of admin-notice ids the user has dismissed.
	 *
	 * @var string
	 */
	const DISMISSED_NOTICES_META = 'shapeforms_lite_dismissed_notices';

	/**
	 * Notice id for the ShapeForms Pro promo banner.
	 *
	 * A fresh id (was 'pro_waitlist') so anyone who dismissed the old
	 * "launching soon" waitlist banner still sees this new sale banner once.
	 *
	 * @var string
	 */
	const NOTICE_PRO_SALE = 'pro_early_bird_sale';

	/**
	 * Main plugin instance.
	 *
	 * @var ShapeForms_Lite
	 */
	private $plugin;

	/**
	 * Builder page hook suffix.
	 *
	 * @var string
	 */
	private $builder_page_hook = '';

	/**
	 * List page hook suffix.
	 *
	 * @var string
	 */
	private $list_page_hook = '';

	/**
	 * Entries page hook suffix.
	 *
	 * @var string
	 */
	private $entries_page_hook = '';

	/**
	 * Preview page hook suffix.
	 *
	 * @var string
	 */
	private $preview_page_hook = '';

	/**
	 * Settings page hook suffix.
	 *
	 * @var string
	 */
	private $settings_page_hook = '';

	/**
	 * Reports page hook suffix.
	 *
	 * @var string
	 */
	private $reports_page_hook = '';

	/**
	 * AJAX handler.
	 *
	 * @var ShapeForms_Lite_Ajax_Save
	 */
	private $ajax_handler;

	/**
	 * Constructor.
	 *
	 * @param ShapeForms_Lite $plugin Main plugin instance.
	 */
	public function __construct( $plugin ) {
		$this->plugin       = $plugin;
		$this->ajax_handler = new ShapeForms_Lite_Ajax_Save( $plugin );
	}

	/**
	 * Registers admin menus.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->list_page_hook = add_menu_page(
			__( 'ShapeForms', 'shapeforms' ),
			__( 'ShapeForms', 'shapeforms' ),
			'manage_options',
			'shapeforms',
			array( $this, 'render_forms_page' ),
			$this->get_menu_icon_data_uri(),
			56
		);

		$this->force_submenu_highlight( $this->list_page_hook, 'shapeforms' );

		add_submenu_page(
			'shapeforms',
			__( 'All Forms', 'shapeforms' ),
			__( 'All Forms', 'shapeforms' ),
			'manage_options',
			'shapeforms',
			array( $this, 'render_forms_page' )
		);

		$this->builder_page_hook = add_submenu_page(
			'shapeforms',
			__( 'Add New Form', 'shapeforms' ),
			__( 'Add New', 'shapeforms' ),
			'manage_options',
			'shapeforms-lite-builder',
			array( $this, 'render_builder_page' )
		);
		$this->force_submenu_highlight( $this->builder_page_hook, 'shapeforms-lite-builder' );

		$this->entries_page_hook = add_submenu_page(
			'shapeforms',
			__( 'Entries', 'shapeforms' ),
			$this->get_entries_menu_title(),
			'manage_options',
			'shapeforms-lite-entries',
			array( $this, 'render_entries_page' )
		);
		$this->force_submenu_highlight( $this->entries_page_hook, 'shapeforms-lite-entries' );

		$this->settings_page_hook = add_submenu_page(
			'shapeforms',
			__( 'Settings', 'shapeforms' ),
			__( 'Settings', 'shapeforms' ),
			'manage_options',
			'shapeforms-lite-settings',
			array( $this, 'render_settings_page' )
		);
		$this->force_submenu_highlight( $this->settings_page_hook, 'shapeforms-lite-settings' );

		$this->reports_page_hook = add_submenu_page(
			'shapeforms',
			__( 'Reports', 'shapeforms' ),
			__( 'Reports', 'shapeforms' ),
			'manage_options',
			'shapeforms-lite-reports',
			array( $this, 'render_reports_page' )
		);
		$this->force_submenu_highlight( $this->reports_page_hook, 'shapeforms-lite-reports' );

		$this->preview_page_hook = add_submenu_page(
			'',
			__( 'Preview Form', 'shapeforms' ),
			__( 'Preview Form', 'shapeforms' ),
			'manage_options',
			'shapeforms-lite-preview',
			array( $this, 'render_preview_page' )
		);

		add_action( 'load-' . $this->preview_page_hook, array( $this, 'set_preview_title' ) );

		/**
		 * Fires after ShapeForms registers all its admin submenu pages.
		 *
		 * Pro can add its own submenu pages (Payments, Integrations, etc.) under shapeforms.
		 *
		 * @param ShapeForms_Lite_Admin $admin The admin instance.
		 */
		do_action( 'shapeforms_admin_menu', $this );
	}

	/**
	 * Forces a submenu item to be marked "current" in the sidebar, on load-{$hook}
	 * (which fires before wp-admin/admin-header.php renders the menu).
	 *
	 * WordPress normally resolves this itself via a file_exists() fallback in
	 * wp-admin/menu-header.php when $submenu_file is never set — that fallback is
	 * relative to the PHP process's current working directory, which some server
	 * setups (php-fpm pools that don't chdir to the script's own directory) leave
	 * pointed somewhere the check can't reliably resolve, so the active submenu
	 * item silently loses its highlight even on the right page. Setting
	 * $submenu_file explicitly makes the highlight deterministic on every server.
	 *
	 * @param string $hook Hook suffix returned by add_menu_page()/add_submenu_page().
	 * @param string $slug Menu slug that should be marked current.
	 * @return void
	 */
	private function force_submenu_highlight( $hook, $slug ) {
		if ( ! $hook ) {
			return;
		}
		add_action(
			'load-' . $hook,
			function () use ( $slug ) {
				$GLOBALS['submenu_file'] = $slug;
			}
		);
	}

	/**
	 * Adds an "Upgrade to Pro" link to the plugin's row on the Plugins screen.
	 *
	 * Hooked to plugin_action_links_{basename}. Shown by default; the
	 * shapeforms_show_upgrade_cta filter defaults to true. A callback returning false
	 * on that filter hides this (and every other) CTA.
	 *
	 * @param array<string, string> $links Existing action links.
	 * @return array<string, string>
	 */
	public function add_plugin_action_links( $links ) {
		if ( ! apply_filters( 'shapeforms_show_upgrade_cta', true ) ) {
			return $links;
		}

		$links['shapeforms_upgrade'] = sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer" style="color:#d63638;font-weight:700;">%2$s</a>',
			esc_url( 'https://shapekode.com/' ),
			esc_html__( 'Upgrade to Pro', 'shapeforms' )
		);

		return $links;
	}

	/**
	 * Builds the "Entries" submenu title, appending an unread-count bubble when
	 * there are unread entries — mirroring core's "Comments" awaiting-moderation
	 * badge so it inherits the active admin colour scheme automatically.
	 *
	 * @return string Menu title (may contain the count-bubble markup).
	 */
	private function get_entries_menu_title() {
		$title  = __( 'Entries', 'shapeforms' );
		$unread = $this->get_unread_entries_count();

		if ( $unread < 1 ) {
			return $title;
		}

		return sprintf(
			'%1$s <span class="awaiting-mod shapeforms-menu-count count-%2$d"><span class="shapeforms-unread-count" aria-hidden="true">%3$s</span><span class="screen-reader-text">%4$s</span></span>',
			$title,
			$unread,
			number_format_i18n( $unread ),
			esc_html(
				sprintf(
					/* translators: %s: number of unread entries. */
					_n( '%s unread entry', '%s unread entries', $unread, 'shapeforms' ),
					number_format_i18n( $unread )
				)
			)
		);
	}

	/**
	 * Returns the number of unread entries, cached in a short-lived transient to
	 * keep the count off the critical path of every admin page load. The cache is
	 * cleared explicitly on every entry mutation (see clear_unread_count_cache),
	 * with the TTL as a safety net for any path not covered.
	 *
	 * @return int Unread entry count.
	 */
	private function get_unread_entries_count() {
		$cached = get_transient( 'shapeforms_lite_unread_count' );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;

		$entries_table = esc_sql( $this->plugin->get_entries_table_name() );

		// Trashed entries never count toward the menu's unread badge, even though they
		// keep their pre-trash status (which may be 'unread') for restore.
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$entries_table}` WHERE status = %s AND trashed_at IS NULL", 'unread' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		set_transient( 'shapeforms_lite_unread_count', $count, 5 * MINUTE_IN_SECONDS );

		return $count;
	}

	/**
	 * Invalidates the cached unread-entry count. Hooked to entry creation and
	 * called after every admin-side status/delete mutation so the menu badge
	 * reflects the change on the next page load.
	 *
	 * @return void
	 */
	public function clear_unread_count_cache() {
		delete_transient( 'shapeforms_lite_unread_count' );
	}

	/**
	 * Returns the brand mark as a base64 data-URI for use as the admin-menu icon.
	 *
	 * Rendered as a white silhouette so the raw data-URI still degrades to a visible
	 * icon if the mask CSS in print_menu_icon_styles() is unavailable; once masked,
	 * only the shape's alpha matters and the colour follows the admin colour scheme.
	 *
	 * @return string
	 */
	private function get_menu_icon_data_uri() {
		$svg = shapeforms_lite_get_brand_icon(
			array(
				'class' => '',
				'size'  => 32,
				'fill'  => '#fff',
			)
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Inlining a static brand SVG as a menu icon; not obfuscation.
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * Registers scoped CSS that renders the ShapeForms menu icon as a colour-aware mask.
	 *
	 * A plain SVG background image cannot be recoloured, so it would ignore the admin
	 * colour scheme and the hover/current states. Painting the mask with currentColor
	 * lets WordPress's own .wp-menu-image:before colour rules drive it, so the logo
	 * dims, brightens on hover, and turns white when active in step with the native
	 * icons. The data-URI passed to add_menu_page() remains the no-CSS fallback.
	 *
	 * Hooked to admin_enqueue_scripts (not admin_head — wp_print_styles() runs before
	 * admin_head, so registering an inline-style carrier that late would never print).
	 *
	 * @return void
	 */
	public function print_menu_icon_styles() {
		$icon = $this->get_menu_icon_data_uri();
		$css  = '#toplevel_page_shapeforms .wp-menu-image{position:relative;background-image:none!important;}'
			. '#toplevel_page_shapeforms .wp-menu-image::before{'
			. 'content:"";position:absolute;top:0;right:0;bottom:0;left:0;'
			. 'background-color:currentColor;'
			. '-webkit-mask:url("' . $icon . '") center/20px auto no-repeat;'
			. 'mask:url("' . $icon . '") center/20px auto no-repeat;'
			. '}'
			// Entries unread-count badge. Keep it a visible pill in EVERY state — core's
			// colour-scheme rules (_admin.scss) flip any .awaiting-mod to its "current"
			// colours on `li:hover`, which blanks a submenu badge on the dark flyout. The
			// two-id selector below outranks those rules so our badge stays consistent.
			. '#adminmenu #toplevel_page_shapeforms .shapeforms-menu-count,'
			. '#adminmenu #toplevel_page_shapeforms:hover .shapeforms-menu-count,'
			. '#adminmenu #toplevel_page_shapeforms.current .shapeforms-menu-count,'
			. '#adminmenu #toplevel_page_shapeforms.wp-has-current-submenu .shapeforms-menu-count{'
			. 'background:#2271b1;color:#fff;}'
			// Active submenu item mirrors the hover treatment — accent text + a left
			// accent bar — so "you are here" matches the hover affordance. Defined for
			// hover/focus/current together so they look identical (2 IDs out-rank core).
			. '#adminmenu #toplevel_page_shapeforms .wp-submenu li a:hover,'
			. '#adminmenu #toplevel_page_shapeforms .wp-submenu li a:focus,'
			. '#adminmenu #toplevel_page_shapeforms .wp-submenu li.current a,'
			. '#adminmenu #toplevel_page_shapeforms .wp-submenu li.current a:hover,'
			. '#adminmenu #toplevel_page_shapeforms .wp-submenu li.current a:focus{'
			. 'color:#2271b1;box-shadow:inset 3px 0 0 #2271b1;}'
			// The active item stays semi-bold to reinforce "you are here".
			. '#adminmenu #toplevel_page_shapeforms .wp-submenu li.current a,'
			. '#adminmenu #toplevel_page_shapeforms .wp-submenu li.current a:hover,'
			. '#adminmenu #toplevel_page_shapeforms .wp-submenu li.current a:focus{'
			. 'font-weight:600;}';

		// No stylesheet file backs this handle (src=false) — it exists only to carry
		// the inline CSS below via wp_add_inline_style(), instead of echoing a raw
		// <style> tag. Must run on admin_enqueue_scripts (register+enqueue before
		// admin_head, which is too late for wp_print_styles() to pick it up), and
		// unconditionally on every admin screen, since the wp-admin menu sidebar this
		// styles is itself present on every screen, not just ShapeForms's own.
		wp_register_style( 'shapeforms-lite-menu-icon', false, array(), SHAPEFORMS_LITE_VERSION );
		wp_enqueue_style( 'shapeforms-lite-menu-icon' );
		wp_add_inline_style( 'shapeforms-lite-menu-icon', $css );
	}

	/**
	 * Sets the global page title for the hidden preview page.
	 *
	 * @return void
	 */
	public function set_preview_title() {
		global $title;

		$title = __( 'Preview Form', 'shapeforms' );
	}

	/**
	 * Tags the hidden Preview Form screen with a body class.
	 *
	 * The form builder's full-bleed layout removes #wpcontent's left gutter
	 * globally (in builder.css, which the preview page also loads). The preview
	 * page uses a normal .wrap and needs that gutter, so this class lets its CSS
	 * restore it — keeping the page symmetric and free of horizontal overflow.
	 *
	 * @param string $classes Space-separated admin body classes.
	 * @return string
	 */
	public function add_admin_body_class( $classes ) {
		$screen = get_current_screen();
		if ( $screen && $this->preview_page_hook === $screen->id ) {
			$classes .= ' shapeforms-preview-screen';
		}
		return $classes;
	}

	/**
	 * Allow SVG uploads in the media library.
	 *
	 * @param array<string, string> $mimes Allowed mime types.
	 * @return array<string, string>
	 */
	public function allow_svg_upload( $mimes ) {
		// Only expose SVG as an allowed upload type to users who can upload files (admins).
		// Never on the front end / for anonymous form submissions — SVGs can carry scripts
		// and would otherwise become a stored-XSS vector through form file fields.
		if ( ! current_user_can( 'upload_files' ) ) {
			return $mimes;
		}
		$mimes['svg']  = 'image/svg+xml';
		$mimes['svgz'] = 'image/svg+xml';
		return $mimes;
	}

	/**
	 * Fix SVG file type detection on upload.
	 *
	 * @param array<string, string|false> $data     File data.
	 * @param string                      $file     Full path to the file.
	 * @param string                      $filename The name of the file.
	 * @param string[]|null               $mimes    Allowed mime types.
	 * @return array<string, string|false>
	 */
	public function fix_svg_filetype( $data, $file, $filename, $mimes ) {
		// Mirror allow_svg_upload(): only treat .svg as a valid type for users who can
		// upload files, so the front-end upload path can never be tricked into accepting one.
		if ( ! current_user_can( 'upload_files' ) ) {
			return $data;
		}
		if ( ! empty( $data['ext'] ) && ! empty( $data['type'] ) ) {
			return $data;
		}
		$ext = pathinfo( $filename, PATHINFO_EXTENSION );
		if ( 'svg' === $ext || 'svgz' === $ext ) {
			$data['ext']  = $ext;
			$data['type'] = 'image/svg+xml';
		}
		return $data;
	}

	/**
	 * Sanitizes uploaded SVG markup before it is stored.
	 *
	 * SVGs can carry executable script (inline <script>, on* event handlers,
	 * javascript: hrefs, <foreignObject>, XXE via DOCTYPE/ENTITY). Since the media
	 * library allows SVG uploads for capable users, every uploaded SVG is rewritten
	 * to a sanitized copy here. Anything that cannot be parsed as a valid SVG is
	 * rejected rather than stored.
	 *
	 * @param array<string, mixed> $file Upload file array ($_FILES entry).
	 * @return array<string, mixed>
	 */
	public function sanitize_svg_upload( $file ) {
		$name = isset( $file['name'] ) ? (string) $file['name'] : '';
		$type = isset( $file['type'] ) ? (string) $file['type'] : '';
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( 'image/svg+xml' !== $type && 'svg' !== $ext ) {
			return $file;
		}

		$tmp = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';

		if ( '' === $tmp || ! is_file( $tmp ) ) {
			return $file;
		}

		$markup = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a just-uploaded temp file, not a remote/URL resource.

		if ( false === $markup ) {
			$file['error'] = __( 'The SVG file could not be read.', 'shapeforms' );
			return $file;
		}

		$clean = $this->sanitize_svg_markup( $markup );

		if ( null === $clean ) {
			$file['error'] = __( 'This SVG could not be sanitized and was rejected for security reasons.', 'shapeforms' );
			return $file;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- overwriting the upload temp file in place before WordPress moves it.
		file_put_contents( $tmp, $clean );

		return $file;
	}

	/**
	 * Strips script-bearing content from SVG markup.
	 *
	 * Removes dangerous elements (script/foreignObject/etc.), all on* event-handler
	 * attributes, javascript:/vbscript: in href-like attributes, and DOCTYPE/ENTITY
	 * declarations. Returns the cleaned SVG string, or null if the input is not a
	 * parseable SVG (caller rejects the upload in that case).
	 *
	 * @param string $svg Raw SVG markup.
	 * @return string|null Cleaned markup, or null on failure.
	 */
	private function sanitize_svg_markup( $svg ) {
		$svg = trim( (string) $svg );

		if ( '' === $svg || ! class_exists( 'DOMDocument' ) ) {
			return null;
		}

		// Drop DOCTYPE/ENTITY declarations (XXE / entity-expansion) before parsing.
		$svg = preg_replace( '/<!DOCTYPE.*?>/is', '', $svg );
		$svg = preg_replace( '/<!ENTITY[^>]*>/i', '', (string) $svg );

		$dom                     = new DOMDocument();
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput       = false;

		$libxml_prev = libxml_use_internal_errors( true );

		// PHP < 8.0: explicitly disable external entity loading (default-safe on 8.0+/libxml 2.9+).
		$entity_prev = null;
		if ( PHP_VERSION_ID < 80000 && function_exists( 'libxml_disable_entity_loader' ) ) {
			$entity_prev = libxml_disable_entity_loader( true );
		}

		$loaded = $dom->loadXML( (string) $svg, LIBXML_NONET );

		if ( null !== $entity_prev ) {
			libxml_disable_entity_loader( $entity_prev );
		}
		libxml_clear_errors();
		libxml_use_internal_errors( $libxml_prev );

		if ( ! $loaded || ! $dom->documentElement || 'svg' !== strtolower( $dom->documentElement->nodeName ) ) {
			return null;
		}

		// Dangerous elements removed wholesale. Matched case-INSENSITIVELY on the local
		// name because SVG/XML is case-sensitive (e.g. <foreignObject>, <animateMotion>),
		// so a tag-name match must normalize case rather than rely on getElementsByTagName.
		$dangerous_tags = array(
			'script',
			'foreignobject',
			'iframe',
			'embed',
			'object',
			'audio',
			'video',
			'handler',
			'listener',
			'set',
			'animate',
			'animatemotion',
			'animatetransform',
			'style', // CSS can exfiltrate via @import / url(); strip the whole block.
		);

		$href_attrs = array( 'href', 'xlink:href', 'src', 'from', 'to', 'values', 'by' );

		// Single pass over every element: collect first (removal mutates the live list).
		$xpath    = new DOMXPath( $dom );
		$node_list = $xpath->query( '//*' );
		$elements  = array();

		if ( $node_list ) {
			foreach ( $node_list as $el ) {
				$elements[] = $el;
			}
		}

		foreach ( $elements as $el ) {
			$local = strtolower( $el->localName ? $el->localName : $el->nodeName );

			if ( in_array( $local, $dangerous_tags, true ) ) {
				if ( $el->parentNode ) {
					$el->parentNode->removeChild( $el );
				}
				continue;
			}

			if ( ! $el->attributes ) {
				continue;
			}

			$remove = array();

			foreach ( $el->attributes as $attr ) {
				$attr_name = strtolower( $attr->nodeName );
				$attr_val  = (string) $attr->nodeValue;

				if ( 0 === strpos( $attr_name, 'on' ) ) {
					$remove[] = $attr;
				} elseif ( in_array( $attr_name, $href_attrs, true ) && $this->svg_href_is_unsafe( $attr_val ) ) {
					$remove[] = $attr;
				} elseif ( 'style' === $attr_name && preg_match( '/expression\s*\(|url\s*\(|@import|javascript\s*:|vbscript\s*:/i', $attr_val ) ) {
					$remove[] = $attr;
				}
			}

			foreach ( $remove as $attr ) {
				$el->removeAttributeNode( $attr );
			}
		}

		$clean = $dom->saveXML( $dom->documentElement );

		if ( false === $clean || '' === trim( (string) $clean ) ) {
			return null;
		}

		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $clean;
	}

	/**
	 * Determines whether an SVG href/src-like value is unsafe.
	 *
	 * Normalizes the value (decodes entities, strips whitespace and control chars)
	 * so obfuscated schemes like "java&#9;script:" are caught, then rejects
	 * executable schemes, non-image data: URIs, and external/protocol-relative
	 * references (which can fetch remote content or exfiltrate). Internal fragment
	 * references (#id), relative paths, and safe base64 raster images are allowed.
	 *
	 * @param string $value Raw attribute value.
	 * @return bool True if the reference is unsafe and should be stripped.
	 */
	private function svg_href_is_unsafe( $value ) {
		$normalized = html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' );
		$normalized = preg_replace( '/[\s\x00-\x20]+/', '', (string) $normalized );
		$normalized = strtolower( (string) $normalized );

		if ( '' === $normalized || '#' === $normalized[0] ) {
			return false;
		}

		if ( preg_match( '#^(javascript|vbscript|mocha|livescript):#', $normalized ) ) {
			return true;
		}

		if ( 0 === strpos( $normalized, 'data:' ) ) {
			// Allow only safe base64 raster images; reject data:text/html, data:image/svg+xml, etc.
			return ! preg_match( '#^data:image/(png|jpe?g|gif|webp);base64,#', $normalized );
		}

		// Block external and protocol-relative references.
		if ( preg_match( '#^(https?:)?//#', $normalized ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Cache-busting version string for a plugin asset.
	 *
	 * Uses the file's modification time so edits to the (hand-maintained) builder
	 * assets are picked up on a normal page load instead of being masked by the
	 * browser cache until the plugin version constant changes. Falls back to the
	 * plugin version if the file is unreadable.
	 *
	 * @param string $relative_path Asset path relative to the plugin root, e.g. 'assets/css/builder.css'.
	 * @return string Version string for wp_enqueue_*().
	 */
	private function asset_version( $relative_path ) {
		$absolute = SHAPEFORMS_LITE_PATH . ltrim( $relative_path, '/' );
		$mtime    = file_exists( $absolute ) ? filemtime( $absolute ) : false;
		return ( false !== $mtime ) ? (string) $mtime : SHAPEFORMS_LITE_VERSION;
	}

	/**
	 * Enqueues admin assets.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		$all_pages = array(
			$this->list_page_hook,
			$this->builder_page_hook,
			$this->entries_page_hook,
			$this->settings_page_hook,
			$this->reports_page_hook,
			$this->preview_page_hook,
		);

		if ( in_array( $hook_suffix, $all_pages, true ) ) {
			// ShapeForms screens show only ShapeForms's own notices. Foreign plugins/themes
			// register their notices as late as admin_head (e.g. MetForm) — after this
			// admin_enqueue_scripts pass — so defer the purge to in_admin_header, the last
			// hook before the notice actions fire, to catch them all. Re-add ours there so
			// it survives the purge. Stripping server-side means no flash before load.
			add_action( 'in_admin_header', array( $this, 'suppress_foreign_notices' ), 1000 );
		}

		if ( $this->builder_page_hook === $hook_suffix ) {
			$form_id     = isset( $_GET['form_id'] ) ? absint( wp_unslash( $_GET['form_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$form_record = $form_id ? $this->get_form( $form_id ) : null;
			$form_data   = $this->normalize_form_for_builder( $form_record );

			// The Add-New setup screen renders the shared ShapeForms topbar, whose styles
			// live in settings.css. Load it first (as a dependency) so builder.css is
			// enqueued after it and still wins any shared-selector conflict on the canvas.
			wp_enqueue_style(
				'shapeforms-lite-admin',
				SHAPEFORMS_LITE_URL . 'assets/css/settings.css',
				array(),
				$this->asset_version( 'assets/css/settings.css' )
			);

			wp_enqueue_style(
				'shapeforms-lite-builder',
				SHAPEFORMS_LITE_URL . 'assets/css/builder.css',
				array( 'shapeforms-lite-admin' ),
				$this->asset_version( 'assets/css/builder.css' )
			);

			wp_enqueue_script(
				'shapeforms-lite-sortable',
				SHAPEFORMS_LITE_URL . 'assets/js/sortable.js',
				array(),
				SHAPEFORMS_LITE_VERSION,
				true
			);

			wp_enqueue_media();

			// Powers the rich thank-you message editor in the Confirmation settings.
			wp_enqueue_editor();

			wp_enqueue_script(
				'shapeforms-lite-builder',
				SHAPEFORMS_LITE_URL . 'assets/js/builder.js',
				array( 'jquery', 'shapeforms-lite-sortable', 'wp-a11y', 'editor', 'quicktags' ),
				$this->asset_version( 'assets/js/builder.js' ),
				true
			);

			// Send the builder a fully normalized payload so the JS app does not need to understand raw DB rows.
			$builder_data = array(
					'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
					'nonce'              => wp_create_nonce( 'shapeforms_lite_save_form' ),
					'formId'             => $form_data['id'],
					// The labels an empty Next / Back / Start field falls back to,
					// taken from the renderer itself. The panel shows them as
					// placeholders, so a second copy here would let the builder
					// promise one word while the page renders another — which is
					// exactly what happened when the placeholder said "Next" and
					// every renderer produced "OK". Localised rather than written
					// in JS so a translated site agrees with itself too.
					'cvDefaults'         => ShapeForms_Lite_Conversational::default_labels(),
					/**
					 * Field types the conversational engine pins to the final
					 * screen. The builder numbers its screen badges with the same
					 * list so the canvas order matches what the visitor walks
					 * through — a badge that disagrees with the form is worse
					 * than no badge.
					 *
					 * @param string[] $types Field type slugs.
					 */
					'cvTailTypes'        => array_values( array_filter( array_map( 'sanitize_key', (array) apply_filters(
						'shapeforms_conversational_tail_types',
						array( 'captcha', 'terms_conditions' )
					) ) ) ),
					/**
					 * Field types that render no control and no text, so they never
					 * earn a screen of their own.
					 *
					 * @param string[] $types Field type slugs.
					 */
					'cvSilentTypes'      => array_values( array_filter( array_map( 'sanitize_key', (array) apply_filters(
						'shapeforms_conversational_silent_types',
						array( 'hidden_field', 'page_break' )
					) ) ) ),
					'formTitle'          => $form_data['title'],
					'formStructure'      => $form_data['structure'],
					'formSettings'       => $form_data['settings'],
					'fieldLibrary'       => $this->get_field_library(),
					// The one icon registry, handed to the builder rather than repeated
					// in JS: the picker offers exactly the keys the sanitizer accepts and
					// draws exactly the glyph the front end will render.
					'choiceIcons'        => shapeforms_lite_choice_icons(),
					'columnPresets'      => array(
						array(
							'value'  => '1',
							'label'  => __( '1 Column', 'shapeforms' ),
							'widths' => array( '100%' ),
						),
						array(
							'value'  => '2',
							'label'  => __( '2 Columns', 'shapeforms' ),
							'widths' => array( '50%', '50%' ),
						),
						array(
							'value'  => '3',
							'label'  => __( '3 Columns', 'shapeforms' ),
							'widths' => array( '33.33%', '33.33%', '33.33%' ),
						),
						array(
							'value'  => '4',
							'label'  => __( '4 Columns', 'shapeforms' ),
							'widths' => array( '25%', '25%', '25%', '25%' ),
						),
					),
					'saveText'           => __( 'Save Form', 'shapeforms' ),
						'savingText'         => __( 'Saving...', 'shapeforms' ),
					'emptyCanvasText'    => __( 'Start building your form by adding a row, then drag or click fields into a column.', 'shapeforms' ),
					'selectFieldText'    => __( 'Select a field to edit its settings.', 'shapeforms' ),
					'defaultFormTitle'   => __( 'Untitled Form', 'shapeforms' ),
					'exampleOptionsText' => __( 'Option 1, Option 2', 'shapeforms' ),
					'pages'              => $this->get_pages_for_redirect(),
					'defaults'           => array(
						'thankYouMessage'  => __( 'Thanks! Your form was submitted successfully.', 'shapeforms' ),
						'submitText'       => __( 'Submit', 'shapeforms' ),
						'option1'          => __( 'Option 1', 'shapeforms' ),
						'option2'          => __( 'Option 2', 'shapeforms' ),
						'termsContent'     => __( 'I agree to the <a href="#">terms and conditions</a>.', 'shapeforms' ),
						'sectionDesc'      => __( 'Add a short description for this section.', 'shapeforms' ),
						// Default placeholder text, keyed by field type. Pre-filled into a new
						// field's Placeholder setting so it shows in settings, canvas, preview
						// and on the front end consistently (the builder bakes it into the
						// saved value, so it stays editable / clearable per field).
						'placeholders'     => array(
							'text'           => __( 'Enter text', 'shapeforms' ),
							'email'          => __( 'you@example.com', 'shapeforms' ),
							'url'            => __( 'https://example.com', 'shapeforms' ),
							'tel'            => __( '+1 (555) 000-0000', 'shapeforms' ),
							'number'         => __( 'Enter a number', 'shapeforms' ),
							'numeric'        => __( 'Enter a number', 'shapeforms' ),
							'textarea'       => __( 'Enter your message', 'shapeforms' ),
							'select'         => __( 'Select…', 'shapeforms' ),
							'multiselect'    => __( 'Select options…', 'shapeforms' ),
							'date'           => __( 'Select a date', 'shapeforms' ),
							'time'           => __( 'Select a time', 'shapeforms' ),
							'password_field' => __( 'Password', 'shapeforms' ),
							'date_range'     => __( 'Select date range', 'shapeforms' ),
							'lookup'         => __( 'Type to search…', 'shapeforms' ),
						),
					),
					'actions'            => array(
						'duplicate' => __( 'Duplicate', 'shapeforms' ),
						'delete'    => __( 'Delete', 'shapeforms' ),
						'addRow'    => __( 'Add Row', 'shapeforms' ),
					),
					// Screen-reader announcements (wp.a11y.speak) for builder mutations.
					'a11y'               => array(
						'rowAdded'        => __( 'Row added.', 'shapeforms' ),
						'rowDeleted'      => __( 'Row deleted.', 'shapeforms' ),
						'rowDuplicated'   => __( 'Row duplicated.', 'shapeforms' ),
						'fieldAdded'      => __( 'Field added.', 'shapeforms' ),
						'fieldDeleted'    => __( 'Field deleted.', 'shapeforms' ),
						'fieldDuplicated' => __( 'Field duplicated.', 'shapeforms' ),
					),
					'labels'             => array(
						'label'        => __( 'Label', 'shapeforms' ),
						'selectedField'=> __( 'Selected Field', 'shapeforms' ),
						'placeholder'  => __( 'Placeholder', 'shapeforms' ),
						'defaultValue' => __( 'Default Value', 'shapeforms' ),
						'required'     => __( 'Required', 'shapeforms' ),
						'customError'  => __( 'Custom error message', 'shapeforms' ),
						'options'      => __( 'Options', 'shapeforms' ),
						'optionsHelp'  => __( 'Separate options with commas.', 'shapeforms' ),
						'optionsLayout'      => __( 'Options Layout', 'shapeforms' ),
						'optionsLayoutBlock'  => __( 'Stacked (default)', 'shapeforms' ),
						'optionsLayoutInline' => __( 'Inline', 'shapeforms' ),
						'cancel'               => __( 'Cancel', 'shapeforms' ),
						/* translators: %s: design theme name, e.g. "Royal Purple". */
						'themeConflictTitle'   => __( 'Apply %s?', 'shapeforms' ),
						'themeConflictBody'    => __( 'These custom colors are overriding the theme. Applying it will replace them:', 'shapeforms' ),
						'themeConflictApply'   => __( 'Apply theme', 'shapeforms' ),
						'checkboxStyle'        => __( 'Style', 'shapeforms' ),
						'checkboxStyleDefault' => __( 'Checkbox', 'shapeforms' ),
						'checkboxStyleSwitch'  => __( 'Switch', 'shapeforms' ),
						'repAddAlign'          => __( 'Add button alignment', 'shapeforms' ),
						'choiceStyleInherit'   => __( 'Form default', 'shapeforms' ),
						'repHideLabel'         => __( 'Hide label', 'shapeforms' ),
						'repRemovePos'         => __( 'Remove button position', 'shapeforms' ),
						'repRemoveTop'         => __( 'Top of the row', 'shapeforms' ),
						'repRemoveBottom'      => __( 'Bottom of the row', 'shapeforms' ),
						// Per-field Checkbox & Radio overrides. The controls themselves are
						// the Style tab's, so they carry their own labels.
						'choiceOverride'       => __( 'Options appearance', 'shapeforms' ),
						'choiceOverrideHint'   => __( 'Empty follows the form style', 'shapeforms' ),
						'choiceOverrideReset'  => __( 'Reset', 'shapeforms' ),
						// Per-option icons. Offered only while the options render as
						// buttons, which is the treatment that has room for one.
						'chooseIcon'           => __( 'Choose an icon', 'shapeforms' ),
						'iconTabIcons'         => __( 'Icons', 'shapeforms' ),
						'iconTabSvg'           => __( 'SVG', 'shapeforms' ),
						'iconTabImage'         => __( 'Image', 'shapeforms' ),
						'chooseSvg'            => __( 'Upload or choose an SVG', 'shapeforms' ),
						'chooseImage'          => __( 'Upload or choose an image', 'shapeforms' ),
						'useThisFile'          => __( 'Use this file', 'shapeforms' ),
						'svgHint'              => __( 'SVG files from your Media Library. Every upload is cleaned before it is stored.', 'shapeforms' ),
						'imageHint'            => __( 'PNG, JPG, GIF or WebP from your Media Library.', 'shapeforms' ),
						'changeFile'           => __( 'Click to choose a different file', 'shapeforms' ),
						'customImage'          => __( 'Custom image', 'shapeforms' ),
						// Both option editors use these; only the top-level one had them
						// before, through a JS-side fallback string.
						'optionPlaceholder'    => __( 'Option value', 'shapeforms' ),
						'addOption'            => __( 'Add Option', 'shapeforms' ),
						'noIcon'               => __( 'Remove icon', 'shapeforms' ),
						'searchIcons'          => __( 'Search icons', 'shapeforms' ),
						'noIconsFound'         => __( 'No icons match.', 'shapeforms' ),
						'columnWidth'  => __( 'Column Width', 'shapeforms' ),
						'layout'       => __( 'Layout', 'shapeforms' ),
						'basicFields'  => __( 'Basic Fields', 'shapeforms' ),
						'advancedFields' => __( 'Advanced Fields', 'shapeforms' ),
						'row'          => __( 'Row', 'shapeforms' ),
						'columns'      => __( 'columns', 'shapeforms' ),
						/* translators: 1: this screen's number, 2: total number of screens. Shown on each question card in the builder when conversational mode is on. */
						'cvScreenOf'   => __( 'Screen %1$s of %2$s', 'shapeforms' ),
						'cvSilent'     => __( 'Not a screen — this field renders nothing for the visitor.', 'shapeforms' ),
						'cvStyleTitle' => __( 'Default Screen Colours', 'shapeforms' ),
						'cvStyleHelp'  => __( 'The starting point for every screen. Any screen can override these from its own settings, and a colour you leave untouched here inherits your form\'s existing style.', 'shapeforms' ),
						'cvStyleOff'   => __( 'Conversational mode is off for this form. Turn it on under Settings → Conversational to style it.', 'shapeforms' ),
						'fields'       => __( 'fields', 'shapeforms' ),
						'dropHere'     => __( 'Drop a field here', 'shapeforms' ),
						'dropHereHint' => __( 'or click one in the Field Library', 'shapeforms' ),
						'blankTemplateTitle' => __( 'Blank Form', 'shapeforms' ),
						'contactTemplateTitle' => __( 'Contact Form', 'shapeforms' ),
						'leadTemplateTitle' => __( 'Lead Capture Form', 'shapeforms' ),
						'contactTemplateDescription' => __( 'A simple contact form with name, email, subject, and message.', 'shapeforms' ),
						'leadTemplateDescription' => __( 'A lead form for collecting contact details, budget, and project needs.', 'shapeforms' ),
						'feedbackTemplateTitle' => __( 'Feedback Form', 'shapeforms' ),
						'feedbackTemplateDescription' => __( 'Collect user feedback with rating and comments.', 'shapeforms' ),
						'newsletterTemplateTitle' => __( 'Newsletter Signup', 'shapeforms' ),
						'newsletterTemplateDescription' => __( 'Simple email signup with name for newsletters.', 'shapeforms' ),
						'registrationTemplateTitle' => __( 'Registration Form', 'shapeforms' ),
						'registrationTemplateDescription' => __( 'Event or account registration with full details.', 'shapeforms' ),
						'importTemplate' => __( 'Import Template', 'shapeforms' ),
						/* translators: %s: comma-separated list of feature names that must be enabled. */
						'templateNeedsModule' => __( 'This template uses %s, which is currently disabled. Enable it in Settings for the form to work fully.', 'shapeforms' ),
						/**
						 * Filters the headline shown in the template preview pane when a
						 * locked template row is selected.
						 *
						 * An add-on that is installed but not yet entitled says "activate"
						 * rather than "upgrade" here — the visitor already owns the product,
						 * and buying it again is not the action they need.
						 *
						 * @since 1.1.7
						 *
						 * @param string $title Headline text.
						 */
						'templateLockTitle' => apply_filters( 'shapeforms_template_lock_title', __( 'Available with an upgrade', 'shapeforms' ) ),

						/**
						 * Filters the body copy shown in the template preview pane when a
						 * locked template row is selected.
						 *
						 * @since 1.1.7
						 *
						 * @param string $text Body copy.
						 */
						'templateLockText'  => apply_filters( 'shapeforms_template_lock_text', __( 'This ready-made form is not included here. Upgrade to import it in one click, along with every other template in the library.', 'shapeforms' ) ),
						'upgradeNow'        => apply_filters( 'shapeforms_upgrade_label', __( 'Upgrade Now', 'shapeforms' ), 'button' ),
						'enableAjax'   => __( 'Enable AJAX submit', 'shapeforms' ),
						'enableRedirect' => __( 'Enable redirect after submit', 'shapeforms' ),
						'redirectUrl'  => __( 'Redirect URL', 'shapeforms' ),
						'thankYouMessage' => __( 'Thank you message', 'shapeforms' ),
						// WordPress labels the editor's second tab "Text". This holds a
						// message template rather than a post, so "Code" describes it better.
						'editorVisual'    => __( 'Visual', 'shapeforms' ),
						'editorCode'      => __( 'Code', 'shapeforms' ),
						/*
						 * Teaser buttons name the FEATURE, not the action, and route their call to
						 * action through shapeforms_upgrade_label — so an add-on that is installed but
						 * not yet entitled reads "Activate" rather than selling what is already
						 * bought. A capability verb here ("Attach a PDF of the submission") sits in
						 * the real control's slot and reads as though this plugin withholds the
						 * feature; the button only opens an explanatory dialog, so a noun phrase
						 * naming a separate add-on describes it honestly.
						 */
						'addShortcodes'   => sprintf(
							/* translators: %s: call-to-action label, e.g. "Upgrade". */
							__( 'Submitted-data shortcodes — %s', 'shapeforms' ),
							apply_filters( 'shapeforms_upgrade_label', __( 'Upgrade', 'shapeforms' ), 'suffix' )
						),
						'shortcodeHint'   => __( 'Insert submitted data into the message with an upgrade.', 'shapeforms' ),
						'customizeEmail'  => sprintf(
							/* translators: %s: call-to-action label, e.g. "Upgrade". */
							__( 'Custom email editor — %s', 'shapeforms' ),
							apply_filters( 'shapeforms_upgrade_label', __( 'Upgrade', 'shapeforms' ), 'suffix' )
						),
						'emailTeaserHint' => __( 'Write your own subject and message for this email with an upgrade.', 'shapeforms' ),
						'attachDocument'  => sprintf(
							/* translators: %s: call-to-action label, e.g. "Upgrade". */
							__( 'PDF attachments — %s', 'shapeforms' ),
							apply_filters( 'shapeforms_upgrade_label', __( 'Upgrade', 'shapeforms' ), 'suffix' )
						),
						'attachmentTeaserHint' => __( 'Attach a PDF of each submission to this email with an upgrade.', 'shapeforms' ),
						'routeRecipients'   => sprintf(
							/* translators: %s: call-to-action label, e.g. "Upgrade". */
							__( 'Conditional recipients — %s', 'shapeforms' ),
							apply_filters( 'shapeforms_upgrade_label', __( 'Upgrade', 'shapeforms' ), 'suffix' )
						),
						'routingTeaserHint' => __( 'Route this notification to different people based on what was answered, with an upgrade.', 'shapeforms' ),
						'integrationUpgrade' => __( 'Upgrade', 'shapeforms' ),
						'integrationLocked'  => __( 'Available with an upgrade', 'shapeforms' ),
						'submitBehavior' => __( 'Submission Settings', 'shapeforms' ),
						'submissionType' => __( 'After Submit', 'shapeforms' ),
						'ajaxSubmit' => __( 'AJAX submit', 'shapeforms' ),
						'ajaxSubmitHelp' => __( 'Submit without page reload and show a success message.', 'shapeforms' ),
						'customPageRedirect' => __( 'Custom page redirect', 'shapeforms' ),
						'customPageRedirectHelp' => __( 'Send the user to a custom URL after submit.', 'shapeforms' ),
						'enableAdminEmail' => __( 'Enable admin email', 'shapeforms' ),
						'enableUserEmail' => __( 'Enable user confirmation email', 'shapeforms' ),
						'adminEmailAddress' => __( 'Admin email address', 'shapeforms' ),
						'emailRecipient' => __( 'Admin Email Recipient', 'shapeforms' ),
						'siteAdminEmail' => __( 'Site admin email', 'shapeforms' ),
						'siteAdminEmailHelp' => __( 'Use the email address from WordPress settings.', 'shapeforms' ),
						'customEmail' => __( 'Custom email', 'shapeforms' ),
						'customEmailHelp' => __( 'Send notifications to a custom email address.', 'shapeforms' ),
						'adminNotifications' => __( 'Admin Notifications', 'shapeforms' ),
						'userNotifications' => __( 'User Confirmation Email', 'shapeforms' ),
						'termsContent' => __( 'Terms text', 'shapeforms' ),
						'captchaNotice' => __( 'This field will use the captcha provider selected in global settings.', 'shapeforms' ),
						'npsColors'      => __( 'Zone Colors', 'shapeforms' ),
						'npsDetractor'   => __( 'Detractors (0–6)', 'shapeforms' ),
						'npsPassive'     => __( 'Passives (7–8)', 'shapeforms' ),
						'npsPromoter'    => __( 'Promoters (9–10)', 'shapeforms' ),
						'resetColor'     => __( 'Reset to default', 'shapeforms' ),
						'starSizeField'        => __( 'Icon Size (px)', 'shapeforms' ),
						'starColorField'       => __( 'Star Color', 'shapeforms' ),
						'starActiveColorField' => __( 'Active Color', 'shapeforms' ),
						'fileUploadHint' => __( 'Choose file or drag & drop', 'shapeforms' ),
						'allowedTypes'   => __( 'Allowed file types', 'shapeforms' ),
						'maxFileSize'    => __( 'Max file size (MB)', 'shapeforms' ),
						'sectionDescription' => __( 'Description', 'shapeforms' ),
						'submitButton' => __( 'Submit Button', 'shapeforms' ),
						'buttonText' => __( 'Button text', 'shapeforms' ),
						'buttonAlignment' => __( 'Button alignment', 'shapeforms' ),
						'buttonLayout' => __( 'Button layout', 'shapeforms' ),
						'buttonIconType'  => __( 'Icon', 'shapeforms' ),
						'dashicon'        => __( 'Dashicon', 'shapeforms' ),
						'customSvg'       => __( 'Custom SVG', 'shapeforms' ),
						'dashiconClass'   => __( 'Dashicon', 'shapeforms' ),
						'uploadSvg'       => __( 'Upload SVG', 'shapeforms' ),
						'changeSvg'       => __( 'Change SVG', 'shapeforms' ),
						'useSvg'          => __( 'Use this SVG', 'shapeforms' ),
						'svgCode'         => __( 'SVG Icon', 'shapeforms' ),
						'iconPosition'    => __( 'Icon position', 'shapeforms' ),
						'iconGap'         => __( 'Icon gap (px)', 'shapeforms' ),
						'cssClass'          => __( 'CSS Class', 'shapeforms' ),
						'autoPopulateKey'   => __( 'Auto Populate Key', 'shapeforms' ),
						'autoPopulateDesc'  => __( 'Pre-fill from URL parameter (?key=value) or logged-in user data (email, first_name, last_name, display_name). Pro: also meta_*, post_meta_*, query_*.', 'shapeforms' ),
						'rowSettings'     => __( 'Row settings', 'shapeforms' ),
						'moveUp'          => __( 'Move up', 'shapeforms' ),
						'moveDown'        => __( 'Move down', 'shapeforms' ),
						'column'          => __( 'Column', 'shapeforms' ),
						'width'           => __( 'Width', 'shapeforms' ),
						'belowFields' => __( 'Below fields', 'shapeforms' ),
						'inlineLastRow' => __( 'Inline with last row', 'shapeforms' ),
						'buttonColor' => __( 'Button color', 'shapeforms' ),
						'fieldAppearance' => __( 'Field Appearance', 'shapeforms' ),
						'fieldStyle' => __( 'Field style', 'shapeforms' ),
						'fieldSize' => __( 'Field size', 'shapeforms' ),
						'fieldFocusColor' => __( 'Focus color', 'shapeforms' ),
						'fieldStyles' => __( 'Field Styles', 'shapeforms' ),
						'labelStyles' => __( 'Label Styles', 'shapeforms' ),
						'buttonStyles' => __( 'Button Styles', 'shapeforms' ),
						'stylePreviewEmpty' => __( 'Add fields in the Builder tab to preview your styling here.', 'shapeforms' ),
						'size' => __( 'Size', 'shapeforms' ),
						'border' => __( 'Border', 'shapeforms' ),
						'borderSize' => __( 'Border Size', 'shapeforms' ),
						'borderRadius' => __( 'Border Radius', 'shapeforms' ),
						'background' => __( 'Background', 'shapeforms' ),
						'text' => __( 'Text', 'shapeforms' ),
						'defaultStyle' => __( 'Default', 'shapeforms' ),
						'subLabel' => __( 'Sublabel', 'shapeforms' ),
						'error' => __( 'Error', 'shapeforms' ),
						'solid' => __( 'Solid', 'shapeforms' ),
						'dashed' => __( 'Dashed', 'shapeforms' ),
						'none' => __( 'None', 'shapeforms' ),
						'small' => __( 'Small', 'shapeforms' ),
						'medium' => __( 'Medium', 'shapeforms' ),
						'large' => __( 'Large', 'shapeforms' ),
						'left' => __( 'Left', 'shapeforms' ),
						'center' => __( 'Center', 'shapeforms' ),
						'right' => __( 'Right', 'shapeforms' ),
						'teal' => __( 'Teal', 'shapeforms' ),
						'blue' => __( 'Blue', 'shapeforms' ),
						'green' => __( 'Green', 'shapeforms' ),
						'red' => __( 'Red', 'shapeforms' ),
						'dark' => __( 'Dark', 'shapeforms' ),
					),
					// Labels for the advanced (full-parity) Style-tab controls.
					'advStyle'           => array(
						'secContainer'    => __( 'Form Container', 'shapeforms' ),
						'secLayout'       => __( 'Layout & Spacing', 'shapeforms' ),
						'secLabels'       => __( 'Labels', 'shapeforms' ),
						'secInputs'       => __( 'Input Fields', 'shapeforms' ),
						'secPlaceholder'  => __( 'Placeholder', 'shapeforms' ),
						'secChoice'       => __( 'Checkbox & Radio', 'shapeforms' ),
						'secSelect'       => __( 'Select Dropdown', 'shapeforms' ),
						'secTerms'        => __( 'Terms & Conditions', 'shapeforms' ),
						'secFile'         => __( 'File Upload', 'shapeforms' ),
						'secSection'      => __( 'Section Break', 'shapeforms' ),
						'secButton'       => __( 'Submit Button', 'shapeforms' ),
						'secError'        => __( 'Error Messages', 'shapeforms' ),
						'secSuccess'      => __( 'Success Message', 'shapeforms' ),
						'maxWidth'        => __( 'Max Width', 'shapeforms' ),
						'alignment'       => __( 'Alignment', 'shapeforms' ),
						'alignLeft'       => __( 'Left', 'shapeforms' ),
						'alignCenter'     => __( 'Center', 'shapeforms' ),
						'alignRight'      => __( 'Right', 'shapeforms' ),
						'alignJustify'    => __( 'Full Width', 'shapeforms' ),
						'padding'         => __( 'Padding', 'shapeforms' ),
						'margin'          => __( 'Margin', 'shapeforms' ),
						'borderColor'     => __( 'Border Color', 'shapeforms' ),
						'colorLabel'      => __( 'Color', 'shapeforms' ),
						'boxShadow'       => __( 'Box Shadow', 'shapeforms' ),
						'hoverShadow'     => __( 'Box Shadow (Hover)', 'shapeforms' ),
						'focusShadow'     => __( 'Box Shadow (Focus)', 'shapeforms' ),
						'stateNormal'     => __( 'Normal', 'shapeforms' ),
						'stateHover'      => __( 'Hover', 'shapeforms' ),
						'stateFocus'      => __( 'Focus', 'shapeforms' ),
						'stateChecked'    => __( 'Checked', 'shapeforms' ),
						'stateSelected'   => __( 'Selected', 'shapeforms' ),
						// Checkbox & Radio → Style: box-and-label, or a selectable pill.
						'choiceStyle'        => __( 'Style', 'shapeforms' ),
						'choiceStyleDefault' => __( 'Default', 'shapeforms' ),
						'choiceStyleButton'  => __( 'Button', 'shapeforms' ),
						'rowGap'          => __( 'Row Gap', 'shapeforms' ),
						'columnGap'       => __( 'Column Gap', 'shapeforms' ),
						'fieldMargin'     => __( 'Field Margin', 'shapeforms' ),
						'inputMargin'     => __( 'Input Margin', 'shapeforms' ),
						'typography'      => __( 'Typography', 'shapeforms' ),
						'requiredColor'   => __( 'Required Mark Color', 'shapeforms' ),
						'height'          => __( 'Height', 'shapeforms' ),
						'textareaHeight'  => __( 'Textarea Height', 'shapeforms' ),
						'textColor'       => __( 'Text Color', 'shapeforms' ),
						'placeholderColor'=> __( 'Placeholder Color', 'shapeforms' ),
						'focusBorderColor'=> __( 'Focus Border Color', 'shapeforms' ),
						'focusBgColor'    => __( 'Focus Background', 'shapeforms' ),
						'accentColor'     => __( 'Accent Color', 'shapeforms' ),
						'labelColor'      => __( 'Label Color', 'shapeforms' ),
						'linkColor'       => __( 'Link Color', 'shapeforms' ),
						'spacing'         => __( 'Spacing', 'shapeforms' ),
						'gap'             => __( 'Gap', 'shapeforms' ),
						'checkedColor'    => __( 'Checked Color', 'shapeforms' ),
						'arrowColor'      => __( 'Arrow Color', 'shapeforms' ),
						'panelBg'         => __( 'Panel Background', 'shapeforms' ),
						'panelBorder'     => __( 'Panel Border Color', 'shapeforms' ),
						'optionBg'        => __( 'Option Background', 'shapeforms' ),
						'optionText'      => __( 'Option Text', 'shapeforms' ),
						'optionHoverBg'   => __( 'Option Hover Background', 'shapeforms' ),
						'optionHoverText' => __( 'Option Hover Text Color', 'shapeforms' ),
						'optionActiveBg'  => __( 'Selected Option Background', 'shapeforms' ),
						'searchBox'       => __( 'Search Box', 'shapeforms' ),
						'searchBg'        => __( 'Search Background', 'shapeforms' ),
						'searchText'      => __( 'Search Text Color', 'shapeforms' ),
						'searchPh'        => __( 'Search Placeholder Color', 'shapeforms' ),
						'copyText'        => __( 'Copy Text', 'shapeforms' ),
						'borderWidth'     => __( 'Border Width', 'shapeforms' ),
						'borderStyle'     => __( 'Border Style', 'shapeforms' ),
						'btnBg'           => __( 'Button Background', 'shapeforms' ),
						'btnText'         => __( 'Button Text Color', 'shapeforms' ),
						'titleColor'      => __( 'Title Color', 'shapeforms' ),
						'descColor'       => __( 'Description Color', 'shapeforms' ),
						'noticeBg'        => __( 'Notice Background', 'shapeforms' ),
						'noticeText'      => __( 'Notice Text Color', 'shapeforms' ),
						'fullWidth'       => __( 'Full Width', 'shapeforms' ),
						'iconColor'       => __( 'Icon Color', 'shapeforms' ),
						'hoverTextColor'  => __( 'Hover Text Color', 'shapeforms' ),
						'hoverBg'         => __( 'Hover Background', 'shapeforms' ),
						'hoverBorderColor'=> __( 'Hover Border Color', 'shapeforms' ),
						'hover'           => __( 'Hover', 'shapeforms' ),
						'focus'           => __( 'Focus', 'shapeforms' ),
						'focusRing'       => __( 'Focus Ring Color', 'shapeforms' ),
						'ringColor'       => __( 'Ring Color', 'shapeforms' ),
						'hoverColor'      => __( 'Hover Color', 'shapeforms' ),
						'linkHoverColor'  => __( 'Link Hover Color', 'shapeforms' ),
						'focusLabelColor' => __( 'Focused Label Color', 'shapeforms' ),
						'states'          => __( 'States', 'shapeforms' ),
						'subLabel'        => __( 'Sub-field Label', 'shapeforms' ),
						'subLabelColor'   => __( 'Sub-label Color', 'shapeforms' ),
						'subfieldGap'     => __( 'Sub-field Gap', 'shapeforms' ),
						'buttonMargin'    => __( 'Button Margin', 'shapeforms' ),
						'containerMargin' => __( 'Container Margin', 'shapeforms' ),
						'fontFamily'      => __( 'Font Family', 'shapeforms' ),
						'fontSize'        => __( 'Font Size', 'shapeforms' ),
						'fontWeight'      => __( 'Weight', 'shapeforms' ),
						'lineHeight'      => __( 'Line Height', 'shapeforms' ),
						'letterSpacing'   => __( 'Letter Spacing', 'shapeforms' ),
						'textTransform'   => __( 'Transform', 'shapeforms' ),
						'shadowX'         => __( 'X Offset', 'shapeforms' ),
						'shadowY'         => __( 'Y Offset', 'shapeforms' ),
						'shadowBlur'      => __( 'Blur', 'shapeforms' ),
						'shadowSpread'    => __( 'Spread', 'shapeforms' ),
						'shadowColor'     => __( 'Shadow Color', 'shapeforms' ),
						'inset'           => __( 'Inset', 'shapeforms' ),
						'gradient'        => __( 'Gradient', 'shapeforms' ),
						'solidFill'       => __( 'Solid', 'shapeforms' ),
						'angle'           => __( 'Angle', 'shapeforms' ),
						'colorStop1'      => __( 'Color 1', 'shapeforms' ),
						'colorStop2'      => __( 'Color 2', 'shapeforms' ),
						'styleLabel'      => __( 'Style', 'shapeforms' ),
						'widthLabel'      => __( 'Width', 'shapeforms' ),
						'radiusLabel'     => __( 'Radius', 'shapeforms' ),
						'linkSides'       => __( 'Link sides', 'shapeforms' ),
						'sideTop'         => __( 'Top', 'shapeforms' ),
						'sideRight'       => __( 'Right', 'shapeforms' ),
						'sideBottom'      => __( 'Bottom', 'shapeforms' ),
						'sideLeft'        => __( 'Left', 'shapeforms' ),
						'inheritDefault'  => __( 'Default', 'shapeforms' ),
						'opacity'         => __( 'Opacity (%)', 'shapeforms' ),
						'reset'           => __( 'Reset color', 'shapeforms' ),
						'resetSection'    => __( 'Reset this section', 'shapeforms' ),
						'previewStates'   => __( 'Preview states — messages & open dropdown', 'shapeforms' ),
						'sampleSuccess'   => __( 'Your form has been submitted successfully.', 'shapeforms' ),
						'sampleError'     => __( 'Please correct the highlighted fields below.', 'shapeforms' ),
						'sampleFieldLabel' => __( 'Field with an error', 'shapeforms' ),
						'sampleValue'     => __( 'Invalid value', 'shapeforms' ),
						'sampleRequired'  => __( 'This field is required.', 'shapeforms' ),
						'sampleDropdown'  => __( 'Dropdown (open)', 'shapeforms' ),
						'sampleSearch'    => __( 'Search…', 'shapeforms' ),
						'optionSelected'  => __( 'Selected option', 'shapeforms' ),
						'optionAnother'   => __( 'Another option', 'shapeforms' ),
						'themeFont'       => __( 'Theme Default', 'shapeforms' ),
						'uppercase'       => __( 'UPPERCASE', 'shapeforms' ),
						'lowercase'       => __( 'lowercase', 'shapeforms' ),
						'capitalize'      => __( 'Capitalize', 'shapeforms' ),
						'dotted'          => __( 'Dotted', 'shapeforms' ),
					),
					'messages'           => array(
						'emptyFields' => __( 'Add at least one field before saving.', 'shapeforms' ),
						'saveSuccess' => __( 'Form saved successfully.', 'shapeforms' ),
						'saveError'   => __( 'Unable to save the form.', 'shapeforms' ),
					),
					// Thank-you message shortcode picker. The builder always renders the
					// slot; this flag only decides whether the free teaser goes inside it.
					// An add-on that ships real shortcodes turns shapeforms_show_upgrade_cta
					// off, so the slot is left empty for it to fill on the
					// shapeforms:form_settings_rendered event.
					//
					// Must stay a bool: wp_localize_script casts top-level scalars with
					// (string), so this reaches JS as '1' or '' and the empty string is
					// correctly falsy. Do NOT "simplify" it to `? 1 : 0` -- that arrives
					// as the string '0', which is truthy, and the teaser would then show
					// even with an add-on active.
					'showUpgradeCta'     => (bool) apply_filters( 'shapeforms_show_upgrade_cta', true ),
					// Same bool rule as showUpgradeCta above. Gates ONLY the template
					// library's locked rows, so an add-on that suppresses the shared CTAs
					// can still advertise templates it has not unlocked yet.
					'showLockedTemplates' => $this->show_locked_templates_teaser(),
					// Locked entries advertised in the "Choose a Template" library. Empty
					// once the teaser above is off, at which point an add-on supplies the
					// real templates through proTemplates instead — the two never show
					// together. See premium_template_teasers().
					'premiumTemplates'   => $this->premium_template_teasers(),
					// Integrations — globalConnections + integrationsNonce injected via shapeforms_builder_localize_data filter by ShapeForms_Lite_Integrations.
				);

			/**
			 * Filter the data passed to the builder JS.
			 *
			 * Pro can add keys like proFileSize, extra field library items, etc.
			 *
			 * @param array<string, mixed> $builder_data Builder localize data.
			 */
			$builder_data = apply_filters( 'shapeforms_builder_localize_data', $builder_data );

			wp_localize_script(
				'shapeforms-lite-builder',
				'shapeformsLiteBuilder',
				$builder_data
			);

			// The Style tab previews a real conversational screen, so it needs the
			// real stylesheet rather than a builder-only copy that would drift
			// from the front end. Safe to load unconditionally: every rule is
			// scoped under .shapeforms-cv, which only exists once the preview
			// renders it.
			wp_enqueue_style(
				'shapeforms-lite-conversational',
				SHAPEFORMS_LITE_URL . 'assets/css/conversational.css',
				array( 'shapeforms-lite-builder' ),
				SHAPEFORMS_LITE_VERSION
			);

			// Integrations assign panel (builder tab).
			wp_enqueue_style(
				'shapeforms-lite-integrations',
				SHAPEFORMS_LITE_URL . 'assets/css/integrations.css',
				array(),
				SHAPEFORMS_LITE_VERSION
			);

			wp_enqueue_script(
				'shapeforms-lite-integrations',
				SHAPEFORMS_LITE_URL . 'assets/js/integrations.js',
				array( 'jquery', 'shapeforms-lite-builder' ),
				SHAPEFORMS_LITE_VERSION,
				true
			);

			/**
			 * Fires after all core builder scripts and localisation are enqueued.
			 * Pro modules should use this action to enqueue their own builder JS.
			 *
			 * At this point `shapeforms-lite-builder` is already registered,
			 * so Pro scripts can safely depend on it.
			 */
			do_action( 'shapeforms_builder_enqueue_assets' );

			return;
		}

		if ( $this->preview_page_hook === $hook_suffix ) {
			wp_enqueue_style(
				'shapeforms-lite-builder',
				SHAPEFORMS_LITE_URL . 'assets/css/builder.css',
				array(),
				$this->asset_version( 'assets/css/builder.css' )
			);

			wp_enqueue_style(
				'shapeforms-lite-frontend',
				SHAPEFORMS_LITE_URL . 'assets/css/frontend.css',
				array(),
				SHAPEFORMS_LITE_VERSION
			);

			wp_enqueue_script(
				'shapeforms-lite-frontend',
				SHAPEFORMS_LITE_URL . 'assets/js/frontend.js',
				array( 'jquery' ),
				SHAPEFORMS_LITE_VERSION,
				true
			);

			wp_localize_script(
				'shapeforms-lite-frontend',
				'shapeformsLiteFrontend',
				array(
					'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
					'ajaxAction'     => 'shapeforms_lite_submit_form',
					'submittingText' => __( 'Submitting...', 'shapeforms' ),
					'successText'    => __( 'Form submitted successfully.', 'shapeforms' ),
					'errorText'      => __( 'Unable to submit the form.', 'shapeforms' ),
				)
			);

			/**
			 * Fires after preview page assets are enqueued.
			 *
			 * Pro or other plugins should hook here to register and enqueue
			 * their own assets for the admin form preview page.
			 */
			do_action( 'shapeforms_preview_enqueue_assets' );

			wp_add_inline_script(
				'shapeforms-lite-frontend',
				'(function($){
					$(document).on("click","[data-preview-device]",function(){
						var device=String($(this).data("preview-device")||"desktop");
						$("[data-preview-device]").removeClass("is-active");
						$(this).addClass("is-active");
						$("#shapeforms-preview-stage").removeClass("is-desktop is-tablet is-mobile").addClass("is-"+device);
					});
					$(document).on("click","#shapeforms-preview-shortcode",function(){
						var $btn=$(this),code=String($btn.data("shortcode")||""),$icon=$btn.find(".shapeforms-preview-shortcode__copy");
						var flash=function(){
							$btn.addClass("is-copied");
							$icon.removeClass("dashicons-admin-page").addClass("dashicons-yes-alt");
							clearTimeout($btn.data("copiedTimer"));
							$btn.data("copiedTimer",setTimeout(function(){
								$btn.removeClass("is-copied");
								$icon.removeClass("dashicons-yes-alt").addClass("dashicons-admin-page");
							},1500));
						};
						var legacy=function(){
							var $t=$("<textarea>").val(code).css({position:"fixed",top:"-9999px",opacity:0}).appendTo("body");
							$t[0].select();
							try{document.execCommand("copy");}catch(e){}
							$t.remove();
						};
						if(navigator.clipboard&&navigator.clipboard.writeText){
							navigator.clipboard.writeText(code).then(flash,function(){legacy();flash();});
							return;
						}
						legacy();flash();
					});
				}(jQuery));'
			);
		}

		$admin_pages = array( $this->settings_page_hook, $this->list_page_hook, $this->entries_page_hook, $this->reports_page_hook );

		if ( in_array( $hook_suffix, $admin_pages, true ) ) {
			wp_enqueue_style(
				'shapeforms-lite-admin',
				SHAPEFORMS_LITE_URL . 'assets/css/settings.css',
				array(),
				$this->asset_version( 'assets/css/settings.css' )
			);

			// Shared admin JS handle — inline scripts for each page are attached below.
			wp_register_script(
				'shapeforms-lite-admin',
				false,
				array( 'jquery' ),
				SHAPEFORMS_LITE_VERSION,
				true
			);
			wp_enqueue_script( 'shapeforms-lite-admin' );

			// Replaces the bulk-bar <select> option lists, which browsers draw as
			// operating-system menus that no stylesheet can reach. Standalone and
			// dependency-free: with it blocked the native selects still work.
			wp_enqueue_script(
				'shapeforms-lite-admin-select',
				SHAPEFORMS_LITE_URL . 'assets/js/admin-select.js',
				array(),
				$this->asset_version( 'assets/js/admin-select.js' ),
				true
			);

			// ── Forms list page ──────────────────────────────────────────────────
			if ( $this->list_page_hook === $hook_suffix ) {
				wp_localize_script(
					'shapeforms-lite-admin',
					'shapeformsAdminForms',
					array(
						'statusNonce'   => wp_create_nonce( 'shapeforms_lite_form_status' ),
						'labelActive'   => __( 'Active', 'shapeforms' ),
						'labelInactive' => __( 'Inactive', 'shapeforms' ),
					)
				);
				wp_add_inline_script(
					'shapeforms-lite-admin',
					'jQuery(function($){
						$("#shapeforms-select-all").on("change",function(){
							$("input[name=\'shapeforms_form_ids[]\']").prop("checked",this.checked);
						});
						$(document).on("change","input[name=\'shapeforms_form_ids[]\']",function(){
							var $boxes=$("input[name=\'shapeforms_form_ids[]\']");
							$("#shapeforms-select-all").prop("checked",$boxes.length>0&&$boxes.filter(":checked").length===$boxes.length);
						});
						$(".shapeforms-copy-shortcode").on("click",function(e){
							e.preventDefault();
							var sc=$(this).data("shortcode");
							if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(sc);}
							else{var $t=$("<textarea>").val(sc).appendTo("body");$t[0].select();try{document.execCommand("copy");}catch(err){}$t.remove();}
							var $btn=$(this);
							var $icon=$btn.find(".dashicons");
							$btn.addClass("is-copied");
							$icon.removeClass("dashicons-admin-page").addClass("dashicons-yes-alt");
							clearTimeout($btn.data("copiedTimer"));
							$btn.data("copiedTimer",setTimeout(function(){
								$btn.removeClass("is-copied");
								$icon.removeClass("dashicons-yes-alt").addClass("dashicons-admin-page");
							},1500));
						});
						$(".shapeforms-form-actions-btn").on("click",function(e){
							e.stopPropagation();
							var $dd=$(this).closest(".shapeforms-form-actions-dd");
							var wasOpen=$dd.hasClass("is-open");
							$(".shapeforms-form-actions-dd").removeClass("is-open");
							if(!wasOpen)$dd.addClass("is-open");
						});
						$(document).on("click",function(){$(".shapeforms-form-actions-dd").removeClass("is-open");});
						$(".shapeforms-form-status-toggle input").on("change",function(){
							var $toggle=$(this).closest(".shapeforms-form-status-toggle");
							var formId=$toggle.data("form-id");
							var isActive=$(this).is(":checked");
							var newStatus=isActive?"publish":"draft";
							var $label=$toggle.find(".shapeforms-form-status-toggle__label");
							$label.text(isActive?shapeformsAdminForms.labelActive:shapeformsAdminForms.labelInactive);
							$.post(ajaxurl,{action:"shapeforms_lite_toggle_form_status",_ajax_nonce:shapeformsAdminForms.statusNonce,form_id:formId,status:newStatus});
						});
					});'
				);
			}

			// ── Entries list page ────────────────────────────────────────────────
			if ( $this->entries_page_hook === $hook_suffix && ! isset( $_GET['entry_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				wp_localize_script(
					'shapeforms-lite-admin',
					'shapeformsAdminEntries',
					array(
						'entryStatusNonce' => wp_create_nonce( 'shapeforms_lite_entry_status' ),
						'exportUrl'        => admin_url( 'admin.php?page=shapeforms-lite-entries' ),
						'exportNonce'      => wp_create_nonce( 'shapeforms_lite_csv_export' ),
						'selectedText'     => __( 'selected', 'shapeforms' ),
					'applyHintText'    => __( 'Select one or more entries and choose a bulk action.', 'shapeforms' ),
						/* translators: %d: number of selected entries to delete. */
						'confirmDelete'    => __( 'Permanently delete %d selected entries? This cannot be undone.', 'shapeforms' ),
						'errorText'        => __( 'Something went wrong. Please try again.', 'shapeforms' ),
					)
				);

				// Export upgrade-modal wiring (shared with the Tools export teaser). Loads
				// only when that teaser is shown — the same guard the teaser uses — so it
				// never loads once an add-on suppresses it, and always loads when the
				// teaser is on (otherwise the locked options would open nothing).
				if ( $this->show_locked_export_teaser() ) {
					wp_add_inline_script( 'shapeforms-lite-admin', $this->upgrade_modal_inline_js() );
				}

				wp_add_inline_script(
					'shapeforms-lite-admin',
					'jQuery(function($){
						var nonce=shapeformsAdminEntries.entryStatusNonce;
						$(".shapeforms-star-btn").on("click",function(){
							var $btn=$(this),id=$btn.data("entry-id");
							var isStarred=$btn.hasClass("is-starred");
							var newStatus=isStarred?"read":"starred";
							$.post(ajaxurl,{action:"shapeforms_lite_update_entry_status",_ajax_nonce:nonce,entry_id:id,status:newStatus},function(r){
								if(r.success){
									$btn.toggleClass("is-starred");
									$btn.find(".dashicons").toggleClass("dashicons-star-filled dashicons-star-empty");
									var $badge=$btn.closest("tr").find(".shapeforms-status-badge");
									$badge.attr("class","shapeforms-status-badge shapeforms-status--"+newStatus).text(newStatus.charAt(0).toUpperCase()+newStatus.slice(1));
									$btn.closest("tr").removeClass("shapeforms-entry--unread");
								}
							});
						});
						$(".shapeforms-dropdown__trigger").on("click",function(e){
							e.stopPropagation();
							var $dd=$(this).closest(".shapeforms-dropdown");
							var wasOpen=$dd.hasClass("is-open");
							$(".shapeforms-dropdown").removeClass("is-open");
							if(!wasOpen)$dd.addClass("is-open");
						});
						$(document).on("click",function(){$(".shapeforms-dropdown").removeClass("is-open");});
						$(".shapeforms-dropdown__panel").on("click",function(e){e.stopPropagation();});
						$("[data-action=\'custom-date\']").on("click",function(){
							$(".shapeforms-dropdown").removeClass("is-open");
							$("#shapeforms-custom-dates").removeAttr("hidden");
							$("#shapeforms-custom-dates input[type=\'date\']:first").focus();
						});

						// ── Bulk selection + actions ──────────────────────────
						function selectedIds(){
							return $(".shapeforms-entry-checkbox:checked").map(function(){return $(this).val();}).get();
						}
						// Apply is only actionable with BOTH a selection and an action, which is
						// exactly the pair the click handler used to bail on silently.
						function refreshApplyState(){
							var ready=selectedIds().length>0&&!!$("#shapeforms-bulk-action").val();
							$("#shapeforms-bulk-apply").prop("disabled",!ready)
								.attr("title",ready?"":shapeformsAdminEntries.applyHintText);
						}
						function refreshBulkBar(){
							var ids=selectedIds(),n=ids.length;
							var $count=$("#shapeforms-bulk-count");
							// Bar stays put; the count text + Export Selected dropdown show once something is selected.
							if(n){$count.text(n+" "+shapeformsAdminEntries.selectedText).removeAttr("hidden");$("#shapeforms-bulk-export-dd").removeAttr("hidden");}
							else{$count.attr("hidden",true);$("#shapeforms-bulk-export-dd").attr("hidden",true).removeClass("is-open");}
							var total=$(".shapeforms-entry-checkbox").length;
							$("#shapeforms-cb-all").prop("checked",total>0&&n===total).prop("indeterminate",n>0&&n<total);
							refreshApplyState();
						}
						$("#shapeforms-bulk-action").on("change",refreshApplyState);
						// Export CSV (dropdown item) — POST the chosen ids to the CSV endpoint (a POST
						// form, not a GET URL, so any number of selected ids works without URL limits).
						$("#shapeforms-bulk-export-csv").on("click",function(){
							var ids=selectedIds();
							if(!ids.length)return;
							$("#shapeforms-bulk-export-dd").removeClass("is-open");
							var $f=$("<form>",{method:"post",action:shapeformsAdminEntries.exportUrl,style:"display:none"});
							$f.append($("<input>",{type:"hidden",name:"shapeforms_export_csv",value:"1"}));
							$f.append($("<input>",{type:"hidden",name:"_wpnonce",value:shapeformsAdminEntries.exportNonce}));
							ids.forEach(function(id){$f.append($("<input>",{type:"hidden",name:"entry_ids[]",value:id}));});
							$("body").append($f);
							$f.trigger("submit");
						});
						$("#shapeforms-cb-all").on("change",function(){
							$(".shapeforms-entry-checkbox").prop("checked",$(this).is(":checked"));
							refreshBulkBar();
						});
						$(document).on("change",".shapeforms-entry-checkbox",refreshBulkBar);
						$("#shapeforms-bulk-apply").on("click",function(){
							var ids=selectedIds(),action=$("#shapeforms-bulk-action").val();
							if(!ids.length||!action)return;
							if(action==="delete"&&!window.confirm(shapeformsAdminEntries.confirmDelete.replace("%d",ids.length)))return;
							var $btn=$(this).prop("disabled",true);
							$.post(ajaxurl,{action:"shapeforms_lite_bulk_entry_action",_ajax_nonce:nonce,bulk_action:action,entry_ids:ids},function(r){
								if(r&&r.success){location.reload();}
								else{refreshApplyState();window.alert((r&&r.data&&r.data.message)||shapeformsAdminEntries.errorText);}
							}).fail(function(){refreshApplyState();window.alert(shapeformsAdminEntries.errorText);});
						});
						// Sync on load: browsers restore ticked checkboxes across the reload this
						// handler triggers (and on back/refresh), so the bar and the Apply state
						// must reflect that restored selection rather than assuming none.
						refreshBulkBar();
					});'
				);
			}

			// ── Entry detail page ────────────────────────────────────────────────
			if ( $this->entries_page_hook === $hook_suffix && isset( $_GET['entry_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$entry_id = absint( wp_unslash( $_GET['entry_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				wp_localize_script(
					'shapeforms-lite-admin',
					'shapeformsAdminEntry',
					array(
						'entryStatusNonce' => wp_create_nonce( 'shapeforms_lite_entry_status' ),
						'entryId'          => $entry_id,
						'spamText'         => __( 'Mark as Spam', 'shapeforms' ),
						'notSpamText'      => __( 'Not Spam', 'shapeforms' ),
					)
				);
				wp_add_inline_script(
					'shapeforms-lite-admin',
					'jQuery(function($){
						var nonce=shapeformsAdminEntry.entryStatusNonce;
						var entryId=shapeformsAdminEntry.entryId;
						function updateStatus(status){
							$.post(ajaxurl,{action:"shapeforms_lite_update_entry_status",_ajax_nonce:nonce,entry_id:entryId,status:status},function(r){
								if(r.success){
									$("#shapeforms-detail-status").attr("class","shapeforms-status-badge shapeforms-status--"+status).text(status.charAt(0).toUpperCase()+status.slice(1));
									$("#shapeforms-mark-unread").prop("disabled",status==="unread");
									$("#shapeforms-mark-starred").find(".dashicons").attr("class","dashicons "+(status==="starred"?"dashicons-star-filled":"dashicons-star-empty"));
									$("#shapeforms-mark-spam").toggleClass("is-spam",status==="spam").attr("title",status==="spam"?shapeformsAdminEntry.notSpamText:shapeformsAdminEntry.spamText);
								}
							});
						}
						$("#shapeforms-mark-unread").on("click",function(){updateStatus("unread");});
						$("#shapeforms-mark-starred").on("click",function(){
							var current=$("#shapeforms-detail-status").text().toLowerCase();
							updateStatus(current==="starred"?"read":"starred");
						});
						$("#shapeforms-mark-spam").on("click",function(){
							var current=$("#shapeforms-detail-status").text().toLowerCase();
							updateStatus(current==="spam"?"read":"spam");
						});
					});'
				);

				/**
				 * Fires when admin assets are enqueued for the single-entry detail screen.
				 *
				 * Lets an add-on enqueue its own CSS/JS for the entry-detail view — e.g. a
				 * notes panel rendered via `shapeforms_entry_detail_sidebar`. Mirrors
				 * `shapeforms_builder_enqueue_assets` for the builder screen.
				 *
				 * @since 1.1.3
				 *
				 * @param int $entry_id The entry being viewed.
				 */
				do_action( 'shapeforms_entry_detail_enqueue_assets', $entry_id );
			}

			// ── Settings page ─────────────────────────────────────────────────────
			if ( $this->settings_page_hook === $hook_suffix ) {
				$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				// Tools -> Entries export teaser: wire the shared upgrade modal, only when
				// that teaser is shown (same guard the teaser uses).
				if ( 'tools' === $active_tab && $this->show_locked_export_teaser() ) {
					wp_add_inline_script( 'shapeforms-lite-admin', $this->upgrade_modal_inline_js() );
				}
				wp_add_inline_script(
					'shapeforms-lite-admin',
					'(function(){
						var options=document.querySelectorAll(".shapeforms-style-option");
						for(var i=0;i<options.length;i++){
							options[i].querySelector("input").addEventListener("change",function(){
								for(var j=0;j<options.length;j++)options[j].classList.remove("is-selected");
								this.closest(".shapeforms-style-option").classList.add("is-selected");
							});
						}
						var providerInputs=document.querySelectorAll("input[name=\'shapeforms_captcha_provider\']");
						var panels=document.querySelectorAll("[data-captcha-panel]");
						var cards=document.querySelectorAll(".shapeforms-captcha-card");
						function updateCaptchaPanels(){
							var selected="simple_math";
							for(var i=0;i<providerInputs.length;i++){if(providerInputs[i].checked)selected=providerInputs[i].value;}
							for(var j=0;j<panels.length;j++){panels[j].hidden=panels[j].getAttribute("data-captcha-panel")!==selected;}
							for(var k=0;k<cards.length;k++){cards[k].classList.toggle("is-selected",cards[k].querySelector("input").checked);}
						}
						for(var i=0;i<providerInputs.length;i++){providerInputs[i].addEventListener("change",updateCaptchaPanels);}
						updateCaptchaPanels();
						var enableYes=document.getElementById("shapeforms-smtp-enable-yes");
						var enableNo=document.getElementById("shapeforms-smtp-enable-no");
						var smtpFields=document.getElementById("shapeforms-smtp-fields");
						var authYes=document.getElementById("shapeforms-smtp-auth-yes");
						var authNo=document.getElementById("shapeforms-smtp-auth-no");
						var authFields=document.getElementById("shapeforms-smtp-auth-fields");
						if(enableYes){
							function toggleSmtp(){smtpFields.style.display=enableYes.checked?"":"none";}
							function toggleAuth(){authFields.style.display=authYes.checked?"":"none";}
							enableYes.addEventListener("change",toggleSmtp);
							enableNo.addEventListener("change",toggleSmtp);
							authYes.addEventListener("change",toggleAuth);
							authNo.addEventListener("change",toggleAuth);
						}
						/* AI tab: show the key and model belonging to the selected
						   provider and hide the rest. All of them stay in the form, so
						   the values entered for the others are still posted and survive
						   the save. The whole row is hidden, not the control: the styled
						   dropdown clips the native select off-screen, so hiding that
						   would hide something already invisible and leave the visible
						   one on the page. */
						var aiProvider=document.querySelector("[data-bf-ai-provider]");
						if(aiProvider){
							var aiRows=document.querySelectorAll("[data-bf-ai-key-for],[data-bf-ai-model-for]");
							function syncAiRows(){
								for(var i=0;i<aiRows.length;i++){
									var owner=aiRows[i].getAttribute("data-bf-ai-key-for")||aiRows[i].getAttribute("data-bf-ai-model-for");
									aiRows[i].hidden=owner!==aiProvider.value;
								}
							}
							aiProvider.addEventListener("change",syncAiRows);
							syncAiRows();
						}
					})();'
				);
				if ( 'smtp' === $active_tab ) {
					wp_localize_script(
						'shapeforms-lite-admin',
						'shapeformsAdminSmtp',
						array(
							'testMailNonce'   => wp_create_nonce( 'shapeforms_lite_test_mail' ),
							'sendingText'     => __( 'Sending...', 'shapeforms' ),
							'sentText'        => __( 'Email sent successfully!', 'shapeforms' ),
							'failedText'      => __( 'Failed to send email.', 'shapeforms' ),
						)
					);
					wp_add_inline_script(
						'shapeforms-lite-admin',
						'(function(){
							var btn=document.getElementById("shapeforms-send-test-mail");
							var result=document.getElementById("shapeforms-test-mail-result");
							if(!btn)return;
							btn.addEventListener("click",function(){
								btn.disabled=true;
								result.textContent=shapeformsAdminSmtp.sendingText;
								result.style.color="#646970";
								var data=new FormData();
								data.append("action","shapeforms_lite_send_test_mail");
								data.append("_ajax_nonce",shapeformsAdminSmtp.testMailNonce);
								data.append("to",document.getElementById("shapeforms-test-to").value);
								data.append("subject",document.getElementById("shapeforms-test-subject").value);
								data.append("message",document.getElementById("shapeforms-test-message").value);
								fetch(ajaxurl,{method:"POST",body:data,credentials:"same-origin"})
									.then(function(r){return r.json();})
									.then(function(r){
										result.textContent=r.data&&r.data.message?r.data.message:(r.success?shapeformsAdminSmtp.sentText:shapeformsAdminSmtp.failedText);
										result.style.color=r.success?"#00a32a":"#d63638";
										btn.disabled=false;
									});
							});
						})();'
					);
				}
			}
		}

	}

	/**
	 * Whether the locked rows in the template library should be advertised.
	 *
	 * Defaults to the shared `shapeforms_show_upgrade_cta` switch, so a site with no
	 * add-on installed is unaffected. An add-on that suppresses the shared CTAs can
	 * override this one filter to keep just this teaser visible — which is what an
	 * installed-but-not-yet-entitled add-on wants, since otherwise four categories
	 * vanish from the library with nothing to explain their absence.
	 *
	 * @return bool
	 */
	public function show_locked_templates_teaser() {
		/**
		 * Filters whether the template library advertises its locked rows.
		 *
		 * @since 1.1.7
		 *
		 * @param bool $show Whether to show the teaser. Defaults to shapeforms_show_upgrade_cta.
		 */
		return (bool) apply_filters(
			'shapeforms_show_locked_templates_teaser',
			apply_filters( 'shapeforms_show_upgrade_cta', true )
		);
	}

	/**
	 * Whether the locked Excel/PDF export controls should be advertised.
	 *
	 * Gates BOTH surfaces that offer them — the Entries screen buttons and the
	 * Tools -> Entries format field — because they advertise one capability and must
	 * never disagree about whether it is on offer.
	 *
	 * Same shape and rationale as show_locked_templates_teaser(): defaults to the
	 * shared switch, but can be kept on by itself. Without it, an add-on that ships
	 * real multi-format export but is not yet entitled removes these teasers (shared
	 * switch off) while its own controls do not register either — leaving the Entries
	 * header with no export buttons and the Tools panel with no format field at all,
	 * which is strictly worse than the free plugin's own behaviour.
	 *
	 * @return bool
	 */
	public function show_locked_export_teaser() {
		/**
		 * Filters whether the locked Excel/PDF export controls are advertised.
		 *
		 * @since 1.1.7
		 *
		 * @param bool $show Whether to show the teasers. Defaults to shapeforms_show_upgrade_cta.
		 */
		return (bool) apply_filters(
			'shapeforms_show_locked_export_teaser',
			apply_filters( 'shapeforms_show_upgrade_cta', true )
		);
	}

	/**
	 * Ready-made forms advertised in the template library but not included here.
	 *
	 * Four of the library's eight categories — Health & Medical, Education & Nonprofit,
	 * Payment & Calculation and Multi-Step — have no template in this plugin, so the
	 * category never rendered and there was nothing to tell anyone those forms exist.
	 * These entries fill the gap: they list as locked rows that preview their
	 * description and offer an upgrade instead of an import.
	 *
	 * Nothing here detects an add-on. The list is emptied by
	 * `shapeforms_show_locked_templates_teaser`, which defaults to the same
	 * `shapeforms_show_upgrade_cta` filter every other teaser respects — so a site with
	 * no add-on behaves exactly as before. An add-on that turns the CTAs off supplies
	 * the real, importable versions of these very templates through `proTemplates`, so
	 * a locked row and its real counterpart can never appear at the same time. Keep the
	 * keys identical to the add-on's template slugs: that is what makes the swap exact
	 * rather than approximate.
	 *
	 * The dedicated filter exists because an add-on can be installed yet not entitled
	 * to the real templates. It suppresses the shared CTAs but still wants this one
	 * contextual teaser, so the category is not simply missing with no explanation.
	 *
	 * @return array<int, array<string, string>> Locked entries, or [] when the teaser is off.
	 */
	private function premium_template_teasers() {
		if ( ! $this->show_locked_templates_teaser() ) {
			return array();
		}

		return array(
			// --- General ---------------------------------------------------------
			array( 'key' => 'contest_entry', 'category' => 'general', 'title' => __( 'Contest / Giveaway Entry', 'shapeforms' ), 'description' => __( 'Run a contest or giveaway with entrant details and a skill-testing question.', 'shapeforms' ) ),

			// --- Business --------------------------------------------------------
			array( 'key' => 'quote_request', 'category' => 'business', 'title' => __( 'Project Quote Request', 'shapeforms' ), 'description' => __( 'Let prospects describe a project and request a price quote with budget and timeline.', 'shapeforms' ) ),
			array( 'key' => 'file_upload', 'category' => 'business', 'title' => __( 'File Upload / Document Submission', 'shapeforms' ), 'description' => __( 'Collect documents from users — resumes, contracts, invoices — with contact details.', 'shapeforms' ) ),
			array( 'key' => 'consent_waiver', 'category' => 'business', 'title' => __( 'Consent / Waiver Form', 'shapeforms' ), 'description' => __( 'Capture agreement and a signature for waivers, consents, and release forms.', 'shapeforms' ) ),
			array( 'key' => 'real_estate_inquiry', 'category' => 'business', 'title' => __( 'Real Estate Inquiry', 'shapeforms' ), 'description' => __( 'Capture buyer and seller leads with inquiry type, property type, budget, and timeline.', 'shapeforms' ) ),
			array( 'key' => 'testimonial_submission', 'category' => 'business', 'title' => __( 'Testimonial Submission', 'shapeforms' ), 'description' => __( 'Collect customer testimonials with a star rating, quote, and optional photo.', 'shapeforms' ) ),
			array( 'key' => 'rental_application', 'category' => 'business', 'title' => __( 'Rental Application', 'shapeforms' ), 'description' => __( 'Screen rental applicants with contact, employment, occupancy, and document details.', 'shapeforms' ) ),
			array( 'key' => 'rma_request', 'category' => 'business', 'title' => __( 'Product Return / RMA Request', 'shapeforms' ), 'description' => __( 'Handle returns with order number, reason, preferred resolution, and a photo.', 'shapeforms' ) ),

			// --- Events & Booking ------------------------------------------------
			array( 'key' => 'restaurant_reservation', 'category' => 'events', 'title' => __( 'Restaurant Reservation', 'shapeforms' ), 'description' => __( 'Take table bookings with party size, date, time, and seating preferences.', 'shapeforms' ) ),
			array( 'key' => 'wedding_rsvp', 'category' => 'events', 'title' => __( 'Wedding RSVP', 'shapeforms' ), 'description' => __( 'Collect RSVPs with guest count, meal choice, and a note to the couple.', 'shapeforms' ) ),
			array( 'key' => 'event_ticket', 'category' => 'events', 'title' => __( 'Event Ticket Purchase', 'shapeforms' ), 'description' => __( 'Sell event tickets with ticket tiers, quantity, and an order summary.', 'shapeforms' ) ),
			array( 'key' => 'catering_estimate', 'category' => 'events', 'title' => __( 'Catering Request Estimate', 'shapeforms' ), 'description' => __( 'Request catering and see a live estimate from guest count multiplied by price per person.', 'shapeforms' ) ),

			// --- HR & Surveys ----------------------------------------------------
			array( 'key' => 'time_off_request', 'category' => 'hr_survey', 'title' => __( 'Time-Off / Leave Request', 'shapeforms' ), 'description' => __( 'Employees request leave with dates and a reason, ready for an approval workflow.', 'shapeforms' ) ),
			array( 'key' => 'employee_onboarding', 'category' => 'hr_survey', 'title' => __( 'Employee Onboarding', 'shapeforms' ), 'description' => __( 'Onboard new hires: personal details, document upload, and a policy signature.', 'shapeforms' ) ),
			array( 'key' => 'nps_survey', 'category' => 'hr_survey', 'title' => __( 'NPS Feedback Survey', 'shapeforms' ), 'description' => __( 'Measure loyalty with a Net Promoter Score question and follow-up feedback.', 'shapeforms' ) ),

			// --- Health & Medical ------------------------------------------------
			array( 'key' => 'patient_intake', 'category' => 'health', 'title' => __( 'Patient Intake / Medical History', 'shapeforms' ), 'description' => __( 'Gather patient details, medical history, and a consent signature before an appointment.', 'shapeforms' ) ),

			// --- Education & Nonprofit -------------------------------------------
			array( 'key' => 'course_enrollment', 'category' => 'education', 'title' => __( 'Course Enrollment', 'shapeforms' ), 'description' => __( 'Enroll students in a course with plan selection, level, and an order summary.', 'shapeforms' ) ),
			array( 'key' => 'volunteer_signup', 'category' => 'education', 'title' => __( 'Volunteer Signup', 'shapeforms' ), 'description' => __( 'Recruit volunteers with areas of interest, availability, and experience.', 'shapeforms' ) ),
			array( 'key' => 'petition', 'category' => 'education', 'title' => __( 'Petition / Signature Drive', 'shapeforms' ), 'description' => __( 'Gather supporters with a name, location, comment, and a signature.', 'shapeforms' ) ),

			// --- Payment & Calculation -------------------------------------------
			array( 'key' => 'payment_order', 'category' => 'payment', 'title' => __( 'Payment Order Form', 'shapeforms' ), 'description' => __( 'Collect product orders with a payment item, quantity, custom amount, and order summary.', 'shapeforms' ) ),
			array( 'key' => 'donation_form', 'category' => 'payment', 'title' => __( 'Donation Form', 'shapeforms' ), 'description' => __( 'Accept donations with preset amounts or a custom amount, plus donor details.', 'shapeforms' ) ),
			array( 'key' => 'service_calculator', 'category' => 'payment', 'title' => __( 'Service Price Calculator', 'shapeforms' ), 'description' => __( 'Let users calculate a service price from quantity multiplied by rate, with an instant total.', 'shapeforms' ) ),
			array( 'key' => 'loan_calculator', 'category' => 'payment', 'title' => __( 'Loan Repayment Calculator', 'shapeforms' ), 'description' => __( 'Estimate simple-interest loan cost from amount, rate, and term.', 'shapeforms' ) ),
			array( 'key' => 'subscription_signup', 'category' => 'payment', 'title' => __( 'Subscription Signup', 'shapeforms' ), 'description' => __( 'Let customers pick a subscription plan and sign up, ready for recurring billing.', 'shapeforms' ) ),
			array( 'key' => 'gym_membership', 'category' => 'payment', 'title' => __( 'Gym Membership Signup', 'shapeforms' ), 'description' => __( 'Sign up new members with a plan, add-ons, emergency contact, and payment.', 'shapeforms' ) ),

			// --- Multi-Step -------------------------------------------------------
			array( 'key' => 'multi_step_registration', 'category' => 'multi_step', 'title' => __( 'Multi-Step Registration', 'shapeforms' ), 'description' => __( 'Three-step registration: personal information, account setup, and preferences.', 'shapeforms' ) ),
			array( 'key' => 'multi_step_survey', 'category' => 'multi_step', 'title' => __( 'Multi-Step Survey', 'shapeforms' ), 'description' => __( 'A two-step satisfaction survey split across pages for better completion.', 'shapeforms' ) ),
			array( 'key' => 'multi_step_booking', 'category' => 'multi_step', 'title' => __( 'Multi-Step Booking + Payment', 'shapeforms' ), 'description' => __( 'Service booking with date and time selection, attendee details, and payment.', 'shapeforms' ) ),
		);
	}

	/**
	 * Strips third-party admin notices on ShapeForms screens so only ShapeForms's own show.
	 *
	 * Hooked to in_admin_header (priority 1000) for ShapeForms pages: it runs after every
	 * plugin/theme has registered its notices — including those added as late as
	 * admin_head — and immediately before the notice actions fire, then re-registers
	 * ShapeForms's own notice so it survives the purge.
	 *
	 * WordPress core's own notices (update nags, PHP-version and security warnings)
	 * are deliberately preserved — hiding those from a site owner is not ours to do.
	 *
	 * @return void
	 */
	public function suppress_foreign_notices() {
		self::remove_third_party_notices( 'admin_notices' );
		self::remove_third_party_notices( 'all_admin_notices' );
		self::remove_third_party_notices( 'user_admin_notices' );
		add_action( 'admin_notices', array( $this, 'render_own_notices' ) );
	}

	/**
	 * Removes third-party callbacks from a notice hook while keeping WordPress
	 * core's own (update nags, PHP-version warnings, security notices, etc).
	 *
	 * A blanket remove_all_actions() also strips core's own notices, which can
	 * hide an update-required or security warning the site owner needs to see.
	 * This keeps any callback whose declaring file lives under wp-admin/ or
	 * wp-includes/ and removes everything else (plugins, themes, mu-plugins).
	 *
	 * @param string $hook Notice hook name.
	 * @return void
	 */
	private static function remove_third_party_notices( $hook ) {
		global $wp_filter;

		if ( empty( $wp_filter[ $hook ] ) ) {
			return;
		}

		// Snapshot first: remove_action() mutates the same structure being walked.
		$registered = $wp_filter[ $hook ]->callbacks;

		foreach ( $registered as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( ! self::is_core_notice_callback( $callback['function'] ) ) {
					remove_action( $hook, $callback['function'], $priority );
				}
			}
		}
	}

	/**
	 * Determines whether a hooked callback is declared by WordPress core.
	 *
	 * @param callable $function The hooked callback.
	 * @return bool
	 */
	private static function is_core_notice_callback( $function ) {
		try {
			if ( is_array( $function ) ) {
				$reflection = new ReflectionMethod( $function[0], $function[1] );
			} elseif ( is_string( $function ) && false !== strpos( $function, '::' ) ) {
				$reflection = new ReflectionMethod( $function );
			} elseif ( $function instanceof Closure || is_string( $function ) ) {
				$reflection = new ReflectionFunction( $function );
			} else {
				return false;
			}
		} catch ( ReflectionException $e ) {
			return false;
		}

		$file = $reflection->getFileName();

		if ( ! $file ) {
			return false;
		}

		$file = wp_normalize_path( $file );

		return ( false !== strpos( $file, '/wp-admin/' ) || false !== strpos( $file, '/wp-includes/' ) )
			&& false === strpos( $file, '/wp-content/' );
	}

	/**
	 * Renders ShapeForms's own admin notices — the only place they render at all.
	 *
	 * This is registered on the 'admin_notices' hook exclusively from within the
	 * foreign-notice purge (suppress_foreign_notices(), and the Integrations page's
	 * equivalent), which only runs on ShapeForms's own screens. There is no global
	 * 'admin_notices' registration for this class elsewhere — that is what keeps
	 * ShapeForms notices confined to ShapeForms's own screens instead of showing on
	 * every wp-admin page.
	 *
	 * @return void
	 */
	public function render_own_notices() {
		settings_errors( 'shapeforms_lite_settings' );

		/**
		 * Fires where an add-on can render an admin notice on ShapeForms's screens.
		 *
		 * The purge above removes third-party 'admin_notices' callbacks and
		 * re-adds only this method, so an add-on registering on 'admin_notices'
		 * directly is silently stripped on exactly the screens it wants to reach.
		 * This action is the supported seam: it runs inside the one callback that
		 * survives, and — because the Integrations page's separate purge re-adds
		 * this same method — it covers both purges at once.
		 *
		 * Callbacks must echo their own fully escaped markup, and should guard
		 * against rendering twice in one request (more than one ShapeForms screen can
		 * wire the purge in a single load).
		 *
		 * @since 1.1.7
		 */
		do_action( 'shapeforms_admin_notices' );
	}


	/**
	 * Persists the current user's dismissal of an admin notice.
	 *
	 * Generic: any notice that renders the shared markup (a `.shapeforms-admin-notice`
	 * with a `data-notice-id` and a `.shapeforms-admin-notice__dismiss` button) posts
	 * its id here, and it is appended to the per-user dismissed-notices list.
	 *
	 * @return void
	 */
	public function ajax_dismiss_notice() {
		check_ajax_referer( 'shapeforms_lite_dismiss_notice', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( '', 403 );
		}

		$notice_id = isset( $_POST['notice_id'] ) ? sanitize_key( wp_unslash( $_POST['notice_id'] ) ) : '';

		if ( '' === $notice_id ) {
			wp_send_json_error( '', 400 );
		}

		$user_id   = get_current_user_id();
		$dismissed = get_user_meta( $user_id, self::DISMISSED_NOTICES_META, true );
		$dismissed = is_array( $dismissed ) ? $dismissed : array();

		if ( ! in_array( $notice_id, $dismissed, true ) ) {
			$dismissed[] = $notice_id;
			update_user_meta( $user_id, self::DISMISSED_NOTICES_META, $dismissed );
		}

		wp_send_json_success();
	}

	/**
	 * Whether the current user has dismissed a given admin notice.
	 *
	 * @param string $notice_id Notice identifier.
	 * @return bool
	 */
	private function is_notice_dismissed( $notice_id ) {
		$dismissed = get_user_meta( get_current_user_id(), self::DISMISSED_NOTICES_META, true );

		return is_array( $dismissed ) && in_array( $notice_id, $dismissed, true );
	}

	/**
	 * Whether a given admin notice should be shown to the current user.
	 *
	 * Single source of truth for both the asset enqueue and the markup render, so
	 * the stylesheet/script only load when the notice will actually appear. Pass any
	 * notice identifier so this gates every ShapeForms admin notice, not just one.
	 *
	 * @param string $notice_id Notice identifier (e.g. self::NOTICE_PRO_SALE).
	 * @return bool
	 */
	private function should_show_notice( $notice_id ) {
		return current_user_can( 'manage_options' ) && ! $this->is_notice_dismissed( $notice_id );
	}




	/**
	 * Renders the shared admin topbar navigation.
	 *
	 * @param string $active_page Current active page slug.
	 * @return void
	 */
	private function render_admin_topbar( $active_page = '' ) {
		$nav_items = array(
			array(
				'slug'  => 'shapeforms',
				'label' => __( 'Forms', 'shapeforms' ),
				'icon'  => 'dashicons-feedback',
				'brand' => true,
				'url'   => admin_url( 'admin.php?page=shapeforms' ),
			),
			array(
				'slug'  => 'shapeforms-lite-entries',
				'label' => __( 'Entries', 'shapeforms' ),
				'icon'  => 'dashicons-email-alt',
				'url'   => admin_url( 'admin.php?page=shapeforms-lite-entries' ),
			),
			array(
				'slug'  => 'shapeforms-lite-reports',
				'label' => __( 'Reports', 'shapeforms' ),
				'icon'  => 'dashicons-chart-bar',
				'url'   => admin_url( 'admin.php?page=shapeforms-lite-reports' ),
			),
			array(
				'slug'  => 'shapeforms-lite-integrations',
				'label' => __( 'Integrations', 'shapeforms' ),
				'icon'  => 'dashicons-randomize',
				'url'   => admin_url( 'admin.php?page=shapeforms-lite-integrations' ),
			),
			array(
				'slug'  => 'shapeforms-lite-settings',
				'label' => __( 'Settings', 'shapeforms' ),
				'icon'  => 'dashicons-admin-generic',
				'url'   => admin_url( 'admin.php?page=shapeforms-lite-settings' ),
			),
			array(
				'slug'  => 'shapeforms-lite-settings#smtp',
				'label' => __( 'SMTP', 'shapeforms' ),
				'icon'  => 'dashicons-email',
				'url'   => admin_url( 'admin.php?page=shapeforms-lite-settings&tab=smtp' ),
			),
			array(
				'slug'  => 'shapeforms-lite-settings#tools',
				'label' => __( 'Tools', 'shapeforms' ),
				'icon'  => 'dashicons-admin-tools',
				'url'   => admin_url( 'admin.php?page=shapeforms-lite-settings&tab=tools' ),
			),
			array(
				'slug'     => 'shapeforms-lite-docs',
				'label'    => __( 'Documentation', 'shapeforms' ),
				'icon'     => 'dashicons-book',
				// Parent is a category; clicking it opens the user guide, hovering reveals both.
				'url'      => 'https://documentation.shapekode.com/docs/shapeforms-user-guide/',
				'external' => true,
				'children' => array(
					array(
						'label'    => __( 'User Documentation', 'shapeforms' ),
						'icon'     => 'dashicons-admin-users',
						'url'      => 'https://documentation.shapekode.com/docs/shapeforms-user-guide/',
						'external' => true,
					),
					array(
						'label'    => __( 'Admin Documentation', 'shapeforms' ),
						'icon'     => 'dashicons-admin-tools',
						'url'      => 'https://documentation.shapekode.com/docs/shape-forms-developer-guide/',
						'external' => true,
					),
				),
			),
		);

		/**
		 * Filter the admin topbar navigation items.
		 *
		 * Pro can append items (e.g. Payments, Integrations) to the topbar.
		 * Each item must be an array with keys: slug, label, icon (dashicon class), url.
		 * Optional keys: 'external' (bool, opens in a new tab) and 'children' (array of
		 * { label, url, icon?, external? } rendered as a hover dropdown).
		 *
		 * @param array<int, array<string, string>> $nav_items Topbar navigation items.
		 * @param string                            $active_page Currently active page slug.
		 */
		$nav_items = apply_filters( 'shapeforms_admin_topbar_items', $nav_items, $active_page );

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
					$is_active   = $item['slug'] === $active_page;
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
	 * Renders the "Upgrade to Pro" call-to-action used in page-title headers.
	 *
	 * The shapeforms_show_upgrade_cta filter defaults to true, so the CTA shows. A
	 * callback returning false on that filter hides this and every other CTA — that
	 * is the supported way for any add-on or developer to suppress them.
	 *
	 * @return void
	 */
	public function render_header_upgrade() {
		if ( ! apply_filters( 'shapeforms_show_upgrade_cta', true ) ) {
			return;
		}
		?>
		<a class="shapeforms-header-upgrade" href="https://shapekode.com/" target="_blank" rel="noopener noreferrer">
			<?php esc_html_e( 'Upgrade to Pro', 'shapeforms' ); ?>
		</a>
		<?php
	}

	/**
	 * Renders locked "Export Excel" / "Export PDF" teaser buttons beside the free
	 * Export CSV button on the Entries screen, plus a one-time upgrade modal.
	 *
	 * The buttons never export — they open a contextual upgrade modal so people
	 * learn what an upgrade unlocks at the exact moment they want it.
	 *
	 * This is an unconditional part of the free plugin: Lite does not know or check
	 * whether any paid add-on exists. It shares show_locked_export_teaser() with the
	 * Tools -> Entries format selector because both advertise the same capability —
	 * an add-on that ships real export turns that switch off and renders its own
	 * controls on this same action, but one that is installed without being entitled
	 * to run keeps the teaser, so these buttons never disappear leaving the Entries
	 * header with no export control at all.
	 *
	 * @return void
	 */
	public function render_entries_export_teaser() {
		if ( ! $this->show_locked_export_teaser() ) {
			return;
		}

		$formats = array(
			'excel' => array(
				'label' => __( 'Export Excel', 'shapeforms' ),
				'icon'  => 'media-spreadsheet',
			),
			'pdf'   => array(
				'label' => __( 'Export PDF', 'shapeforms' ),
				'icon'  => 'media-document',
			),
		);

		foreach ( $formats as $key => $format ) {
			?>
			<button type="button" class="shapeforms-btn-add shapeforms-export-teaser" data-shapeforms-export-feature="<?php echo esc_attr( $key ); ?>">
				<span class="dashicons dashicons-<?php echo esc_attr( $format['icon'] ); ?>"></span>
				<?php echo esc_html( $format['label'] ); ?>
				<span class="shapeforms-export-teaser__badge" aria-hidden="true"><span class="dashicons dashicons-lock"></span></span>
				<span class="screen-reader-text"><?php echo esc_html( apply_filters( 'shapeforms_upgrade_label', __( 'Upgrade required', 'shapeforms' ), 'sr_state' ) ); ?></span>
			</button>
			<?php
		}

		$this->render_export_upgrade_modal();
	}

	/**
	 * Renders the contextual upgrade modal opened by the export teaser buttons.
	 * Self-contained (the builder modal's CSS is not loaded on this screen); its
	 * styles live in settings.css and it is toggled by inline JS on the Entries
	 * page. Rendered only from the teaser callbacks, so it is present only when a
	 * teaser is actually shown (i.e. while show_locked_export_teaser() is true).
	 *
	 * @return void
	 */
	private function render_export_upgrade_modal() {
		?>
		<div class="shapeforms-upgrade-modal" id="shapeforms-export-upgrade-modal" hidden>
			<div class="shapeforms-upgrade-modal__backdrop" data-shapeforms-upgrade-close></div>
			<div class="shapeforms-upgrade-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="shapeforms-export-upgrade-modal-title">
				<button type="button" class="shapeforms-upgrade-modal__close" data-shapeforms-upgrade-close aria-label="<?php esc_attr_e( 'Close', 'shapeforms' ); ?>"><span class="dashicons dashicons-no-alt"></span></button>
				<div class="shapeforms-upgrade-modal__icon" aria-hidden="true"><span class="dashicons dashicons-lock"></span></div>
				<h2 id="shapeforms-export-upgrade-modal-title" class="shapeforms-upgrade-modal__title"><?php
					/**
					 * Filters the heading of a locked-content upgrade dialog.
					 *
					 * NOTE for the export dialog: this modal is rendered hidden and its
					 * heading is rewritten per format each time it opens, so the per-format
					 * headings go through this same filter in upgrade_modal_inline_js()
					 * under the 'export_excel' and 'export_pdf' contexts. The value filtered
					 * HERE is only the fallback — a callback that handles just 'export' will
					 * not change what the user actually sees on this screen.
					 *
					 * @since 1.1.7
					 *
					 * @param string $title   Heading text.
					 * @param string $context Which dialog. One of 'fields', 'templates',
					 *                        'export', 'export_excel', 'export_pdf'.
					 */
					echo esc_html( apply_filters( 'shapeforms_upgrade_modal_title', __( 'Excel & PDF export', 'shapeforms' ), 'export' ) );
				?></h2>
				<p class="shapeforms-upgrade-modal__text"><?php
					/**
					 * Filters the body copy of a locked-content upgrade dialog.
					 *
					 * @since 1.1.7
					 *
					 * @param string $text    Body copy.
					 * @param string $context Which dialog. One of 'fields', 'templates', 'export'.
					 */
					echo esc_html( apply_filters( 'shapeforms_upgrade_modal_text', __( 'ShapeForms exports your entries to CSV. Upgrade to download them as formatted Excel spreadsheets and print-ready PDF files, right from this screen.', 'shapeforms' ), 'export' ) );
				?></p>
				<ul class="shapeforms-upgrade-modal__list">
					<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Formatted Excel (.xlsx) for reporting and analysis', 'shapeforms' ); ?></li>
					<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Print-ready PDF records to share or archive', 'shapeforms' ); ?></li>
					<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'One click from this screen, honouring your current filters', 'shapeforms' ); ?></li>
				</ul>
				<div class="shapeforms-upgrade-modal__actions">
					<?php $this->render_upgrade_cta(); ?>
					<button type="button" class="shapeforms-upgrade-modal__dismiss" data-shapeforms-upgrade-close><?php esc_html_e( 'Maybe later', 'shapeforms' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the Tools -> Entries export format selector for the free plugin:
	 * JSON, the one format this plugin produces. The add-on's Excel and PDF
	 * formats are described beneath the control rather than listed inside it, so
	 * the select never offers something this plugin cannot deliver. Hooked to
	 * shapeforms_tools_entries_export_fields.
	 * Like the Entries teaser it is gated by show_locked_export_teaser(), NOT by
	 * shapeforms_show_upgrade_cta directly: an add-on that ships real multi-format export
	 * turns the shared switch off, and this teaser has to survive that until the add-on
	 * is actually able to render its own control (see shapeforms_show_locked_export_teaser).
	 *
	 * @return void
	 */
	public function render_tools_export_teaser() {
		if ( ! $this->show_locked_export_teaser() ) {
			return;
		}

		/**
		 * Filters the hint under the export-format control.
		 *
		 * @since 1.1.7
		 *
		 * @param string $hint Default hint text.
		 */
		$hint = apply_filters(
			'shapeforms_export_lock_hint',
			__( 'Excel and PDF export are available in the ShapeForms premium add-on.', 'shapeforms' )
		);
		?>
		<div class="shapeforms-field-row">
			<div class="shapeforms-field-label"><label for="shapeforms-export-format"><?php esc_html_e( 'Export format', 'shapeforms' ); ?></label></div>
			<div class="shapeforms-field-control">
				<select id="shapeforms-export-format" name="shapeforms_export_format" style="max-width:100%;">
					<option value="json"><?php esc_html_e( 'JSON', 'shapeforms' ); ?></option>
				</select>
				<p class="shapeforms-upgrade-hint">
					<?php echo esc_html( $hint ); ?>
					<button type="button" class="button-link shapeforms-export-teaser" data-shapeforms-export-feature="both"><?php esc_html_e( 'Learn more', 'shapeforms' ); ?></button>
				</p>
			</div>
		</div>
		<?php
		$this->render_export_upgrade_modal();
	}

	/**
	 * Renders the anchor for an upgrade call-to-action.
	 *
	 * Every upgrade CTA in the admin goes through here so they cannot disagree. Three
	 * things were being duplicated per call site and drifting:
	 *
	 * 1. `shapeforms_upgrade_url` / `shapeforms_upgrade_label`. An add-on that is installed
	 *    but not yet entitled repoints both at its own License tab. A CTA that skipped
	 *    the filters carried on selling the product the user already owns, on the same
	 *    screen as one that didn't — worse than if none of them had been converted.
	 * 2. `target="_blank"`. Correct for the sales site, wrong for an in-admin URL: a
	 *    filtered CTA pointing at a License tab opened wp-admin in a second tab.
	 * 3. Escaping, which is easy to get right once and easy to forget in the seventh copy.
	 *
	 * @since 1.1.7
	 *
	 * @param string $class   CSS class for the anchor.
	 * @param string $default Default label, in case this CTA reads differently.
	 * @return void
	 */
	public function render_upgrade_cta( $class = 'shapeforms-upgrade-modal__cta', $default = '' ) {
		if ( '' === $default ) {
			$default = __( 'Upgrade Now', 'shapeforms' );
		}

		/**
		 * Filters the destination of every upgrade call-to-action.
		 *
		 * An add-on that is installed but not yet entitled should point this at its
		 * own activation screen: the visitor already owns the product, so sending
		 * them to a sales page is the wrong action. An in-admin URL is detected and
		 * opened in the same tab.
		 *
		 * @since 1.1.2
		 *
		 * @param string $url Destination URL. Default the sales site.
		 */
		$url = (string) apply_filters( 'shapeforms_upgrade_url', 'https://shapekode.com/' );

		/**
		 * Filters the text of an upgrade call-to-action.
		 *
		 * Applied to several strings that read differently, so a callback that wants
		 * to change only one of them must branch on $context. Rewriting them all to
		 * the same value produces, for example, the accessible name "Export Excel
		 * Activate License" and the option label "Excel (.xlsx) — Activate License".
		 *
		 * @since 1.1.7 The $context argument.
		 *
		 * @param string $label   Default label for this call-to-action.
		 * @param string $context Where it appears. One of:
		 *                        'button'   — a real button or link the user clicks;
		 *                        'suffix'   — appended to another label, e.g. "Excel (.xlsx) — %s";
		 *                        'sr_state' — screen-reader text describing a state, not an action.
		 */
		$label = (string) apply_filters( 'shapeforms_upgrade_label', $default, 'button' );

		// An in-admin destination stays in this tab; anything else is an external site
		// and opens in a new one so the user does not lose their place in the builder.
		$is_local = 0 === strpos( $url, admin_url() );

		printf(
			'<a class="%1$s" href="%2$s"%3$s>%4$s</a>',
			esc_attr( $class ),
			esc_url( $url ),
			$is_local ? '' : ' target="_blank" rel="noopener noreferrer"',
			esc_html( $label )
		);
	}

	/**
	 * Returns the inline jQuery that toggles the shared export upgrade modal.
	 * Opens on a teaser button click (Entries screen) or when a locked format is
	 * chosen in the export-format select (Tools screen), and closes on the X, the
	 * backdrop, or Escape. Attached to the shapeforms-lite-admin handle only while the
	 * locked export teasers are shown (show_locked_export_teaser()), so it stops
	 * loading once an add-on supplies its own export UI.
	 *
	 * The modal is rendered hidden and every open() rewrites its heading from the map
	 * below, so the per-format headings MUST go through shapeforms_upgrade_modal_title
	 * here as well as at the server-rendered <h2>. Building them any other way makes
	 * that filter dead on this screen: the JS would overwrite whatever a callback
	 * returned, on every open, with no way to tell.
	 *
	 * @return string
	 */
	private function upgrade_modal_inline_js() {
		/** This filter is documented in admin/class-shapeforms-lite-admin.php */
		$excel = apply_filters( 'shapeforms_upgrade_modal_title', __( 'Excel export', 'shapeforms' ), 'export_excel' );

		/** This filter is documented in admin/class-shapeforms-lite-admin.php */
		$pdf = apply_filters( 'shapeforms_upgrade_modal_title', __( 'PDF export', 'shapeforms' ), 'export_pdf' );

		/** This filter is documented in admin/class-shapeforms-lite-admin.php */
		$both = apply_filters( 'shapeforms_upgrade_modal_title', __( 'Excel & PDF export', 'shapeforms' ), 'export' );

		// Keys are the two vocabularies the openers use: data-shapeforms-export-feature
		// on the Entries teaser buttons ('excel'/'pdf') and the option values in the
		// Tools format select ('xlsx'/'pdf').
		$titles = array(
			'excel' => (string) $excel,
			'xlsx'  => (string) $excel,
			'pdf'   => (string) $pdf,
		);

		return 'jQuery(function($){' .
			'var $m=$("#shapeforms-export-upgrade-modal");if(!$m.length){return;}' .
			'var titles=' . wp_json_encode( $titles ) . ';' .
			'var fallback=' . wp_json_encode( (string) $both ) . ';' .
			'function openModal(f){$m.find(".shapeforms-upgrade-modal__title").text(titles[f]||fallback);$m.removeAttr("hidden");$("body").addClass("shapeforms-upgrade-modal-open");}' .
			'function closeModal(){$m.attr("hidden","hidden");$("body").removeClass("shapeforms-upgrade-modal-open");}' .
			'$(document).on("click",".shapeforms-export-teaser",function(e){e.preventDefault();openModal($(this).data("shapeforms-export-feature"));});' .
			'$m.on("click","[data-shapeforms-upgrade-close]",function(){closeModal();});' .
			'$(document).on("keydown",function(e){if(e.key==="Escape"){closeModal();}});' .
		'});';
	}


	/**
	 * Registers ShapeForms nodes in the WordPress admin bar.
	 *
	 * Adds a top-level "ShapeForms" node with dropdown children for each nav item.
	 * Pro can append extra items via the `shapeforms_admin_bar_items` filter.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar The admin bar instance.
	 * @return void
	 */
	public function register_admin_bar( $wp_admin_bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$nav_items = array(
			array(
				'id'     => 'shapeforms-bar-forms',
				'title'  => __( 'All Forms', 'shapeforms' ),
				'href'   => admin_url( 'admin.php?page=shapeforms' ),
			),
			array(
				'id'     => 'shapeforms-bar-entries',
				'title'  => __( 'Entries', 'shapeforms' ),
				'href'   => admin_url( 'admin.php?page=shapeforms-lite-entries' ),
			),
			array(
				'id'     => 'shapeforms-bar-reports',
				'title'  => __( 'Reports', 'shapeforms' ),
				'href'   => admin_url( 'admin.php?page=shapeforms-lite-reports' ),
			),
			array(
				'id'     => 'shapeforms-bar-settings',
				'title'  => __( 'Settings', 'shapeforms' ),
				'href'   => admin_url( 'admin.php?page=shapeforms-lite-settings' ),
			),
			array(
				'id'     => 'shapeforms-bar-smtp',
				'title'  => __( 'SMTP', 'shapeforms' ),
				'href'   => admin_url( 'admin.php?page=shapeforms-lite-settings&tab=smtp' ),
			),
			array(
				'id'     => 'shapeforms-bar-tools',
				'title'  => __( 'Tools', 'shapeforms' ),
				'href'   => admin_url( 'admin.php?page=shapeforms-lite-settings&tab=tools' ),
			),
		);

		/**
		 * Filter the ShapeForms admin bar dropdown items.
		 *
		 * Pro can append items (e.g. Payments, Integrations).
		 * Each item must have: id (string), title (string), href (string).
		 *
		 * @param array<int, array<string, string>> $nav_items Admin bar child nodes.
		 */
		$nav_items = apply_filters( 'shapeforms_admin_bar_items', $nav_items );

		// Top-level parent node.
		$wp_admin_bar->add_node(
			array(
				'id'    => 'shapeforms-bar',
				'title' => __( 'ShapeForms', 'shapeforms' ),
				'href'  => admin_url( 'admin.php?page=shapeforms' ),
			)
		);

		// Child nodes.
		foreach ( $nav_items as $item ) {
			$wp_admin_bar->add_node(
				array(
					'parent' => 'shapeforms-bar',
					'id'     => sanitize_key( $item['id'] ),
					'title'  => esc_html( $item['title'] ),
					'href'   => esc_url( $item['href'] ),
				)
			);
		}
	}

	/**
	 * Renders the All Forms page.
	 *
	 * @return void
	 */
	public function render_forms_page() {
		$current_view    = isset( $_GET['form_status'] ) && 'trash' === sanitize_key( wp_unslash( $_GET['form_status'] ) ) ? 'trash' : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_trash        = 'trash' === $current_view;
		$all_count       = $this->get_forms_count();
		$trash_count     = $this->get_forms_count( 'trash' );
		$notice          = isset( $_GET['shapeforms_notice'] ) ? sanitize_key( wp_unslash( $_GET['shapeforms_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$form_action_url = $is_trash ? admin_url( 'admin.php?page=shapeforms&form_status=trash' ) : admin_url( 'admin.php?page=shapeforms' );

		// Status filter (Active/Inactive) — applies to the non-trash view only.
		$status_filter = isset( $_GET['status_filter'] ) ? sanitize_key( wp_unslash( $_GET['status_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $status_filter, array( 'active', 'inactive' ), true ) || $is_trash ) {
			$status_filter = '';
		}

		// Search (matches against the form title).
		$search_term = isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Sorting (server-side, WP-style): allowlisted column + direction.
		$allowed_orderby = array( 'title', 'entries', 'updated' );
		$orderby         = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = '';
		}
		$order = ( isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ) ? 'asc' : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Pagination — server-side, fixed 10-per-page (mirrors the Entries list).
		// Filtering/searching/sorting all run in SQL (see get_forms()/get_forms_total()),
		// which is required for pagination to be correct: applying them in PHP after a
		// LIMIT/OFFSET slice would only filter/sort the current page's rows.
		$per_page     = 10;
		$total_items  = $this->get_forms_total( $current_view, $status_filter, $search_term );
		$total_pages  = max( 1, (int) ceil( $total_items / $per_page ) );
		$current_page = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_page = min( $current_page, $total_pages );
		$offset       = ( $current_page - 1 ) * $per_page;

		$forms = $this->get_forms( $current_view, $status_filter, $search_term, $orderby, $order, $per_page, $offset );

		// Sort/search/filter links must preserve the active status filter and search term
		// in the URL. They deliberately don't carry `paged` — changing them resets to page 1.
		$sort_base_url = ( '' !== $status_filter ) ? add_query_arg( 'status_filter', $status_filter, $form_action_url ) : $form_action_url;
		if ( '' !== $search_term ) {
			$sort_base_url = add_query_arg( 's', rawurlencode( $search_term ), $sort_base_url );
		}
		?>
		<?php $this->render_admin_topbar( 'shapeforms' ); ?>
		<div class="wrap">
			<?php // Anchor relocated admin notices above the header. WordPress moves every non-inline .notice to directly after .wp-header-end (wp-admin/js/common.js); without this marker it falls back to "after the first <h1>", which drops the notice between the title and the Add New button. Placing the marker first keeps notices on top, header + content below — and works for any third-party notice too. ?>
			<hr class="wp-header-end">
			<div class="shapeforms-page-header">
				<h1><?php esc_html_e( 'Forms', 'shapeforms' ); ?></h1>
				<?php if ( ! $is_trash ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=shapeforms-lite-builder' ) ); ?>" class="shapeforms-btn-add">
						<span class="dashicons dashicons-plus-alt2"></span>
						<?php esc_html_e( 'Add New', 'shapeforms' ); ?>
					</a>
				<?php endif; ?>
				<?php // Notice carries the `inline` class so WordPress does not relocate it; it sits in the header row, after the Add New button. ?>
				<?php $this->render_admin_notice( $notice ); ?>
				<?php $this->render_header_upgrade(); ?>
			</div>

			<div class="shapeforms-forms-tabs">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=shapeforms' ) ); ?>" class="shapeforms-forms-tab<?php echo ! $is_trash ? ' is-active' : ''; ?>">
					<?php esc_html_e( 'All Forms', 'shapeforms' ); ?> <span class="shapeforms-forms-tab__count"><?php echo absint( $all_count ); ?></span>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=shapeforms&form_status=trash' ) ); ?>" class="shapeforms-forms-tab<?php echo $is_trash ? ' is-active' : ''; ?>">
					<?php esc_html_e( 'Trash', 'shapeforms' ); ?> <span class="shapeforms-forms-tab__count"><?php echo absint( $trash_count ); ?></span>
				</a>
			</div>

			<div class="shapeforms-table-card">
				<div class="shapeforms-bulk-bar">
					<div class="shapeforms-bulk-action-wrap">
						<select name="shapeforms_bulk_action" id="shapeforms-bulk-action" form="shapeforms-bulk-form">
							<option value=""><?php esc_html_e( 'Bulk Actions', 'shapeforms' ); ?></option>
							<?php if ( $is_trash ) : ?>
								<option value="restore"><?php esc_html_e( 'Restore', 'shapeforms' ); ?></option>
								<option value="delete"><?php esc_html_e( 'Delete Permanently', 'shapeforms' ); ?></option>
							<?php else : ?>
								<option value="trash"><?php esc_html_e( 'Move to Trash', 'shapeforms' ); ?></option>
							<?php endif; ?>
						</select>
						<button type="submit" class="shapeforms-bulk-apply" form="shapeforms-bulk-form"><?php esc_html_e( 'Apply', 'shapeforms' ); ?></button>
					</div>
					<?php if ( ! $is_trash ) : ?>
						<form method="get" class="shapeforms-filter-form" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
							<input type="hidden" name="page" value="shapeforms">
							<?php if ( '' !== $orderby ) : ?>
								<input type="hidden" name="orderby" value="<?php echo esc_attr( $orderby ); ?>">
								<input type="hidden" name="order" value="<?php echo esc_attr( $order ); ?>">
							<?php endif; ?>
							<?php if ( '' !== $search_term ) : ?>
								<input type="hidden" name="s" value="<?php echo esc_attr( $search_term ); ?>">
							<?php endif; ?>
							<select name="status_filter" id="shapeforms-status-filter">
								<option value=""><?php esc_html_e( 'All Status', 'shapeforms' ); ?></option>
								<option value="active" <?php selected( $status_filter, 'active' ); ?>><?php esc_html_e( 'Active', 'shapeforms' ); ?></option>
								<option value="inactive" <?php selected( $status_filter, 'inactive' ); ?>><?php esc_html_e( 'Inactive', 'shapeforms' ); ?></option>
							</select>
							<button type="submit" class="shapeforms-bulk-apply"><?php esc_html_e( 'Filter', 'shapeforms' ); ?></button>
						</form>
					<?php endif; ?>
					<form method="get" class="shapeforms-search-form" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
						<input type="hidden" name="page" value="shapeforms">
						<?php if ( $is_trash ) : ?>
							<input type="hidden" name="form_status" value="trash">
						<?php endif; ?>
						<?php if ( '' !== $status_filter ) : ?>
							<input type="hidden" name="status_filter" value="<?php echo esc_attr( $status_filter ); ?>">
						<?php endif; ?>
						<?php if ( '' !== $orderby ) : ?>
							<input type="hidden" name="orderby" value="<?php echo esc_attr( $orderby ); ?>">
							<input type="hidden" name="order" value="<?php echo esc_attr( $order ); ?>">
						<?php endif; ?>
						<label class="screen-reader-text" for="shapeforms-search-input"><?php esc_html_e( 'Search Forms', 'shapeforms' ); ?></label>
						<input type="search" id="shapeforms-search-input" class="shapeforms-search-input" name="s" value="<?php echo esc_attr( $search_term ); ?>" placeholder="<?php esc_attr_e( 'Search forms…', 'shapeforms' ); ?>">
						<button type="submit" class="shapeforms-bulk-apply"><?php esc_html_e( 'Search Forms', 'shapeforms' ); ?></button>
					</form>
				</div>

				<form method="post" id="shapeforms-bulk-form" action="<?php echo esc_url( $form_action_url ); ?>">
					<?php wp_nonce_field( 'shapeforms_lite_bulk_action', 'shapeforms_bulk_nonce' ); ?>
					<div class="shapeforms-table-scroll">
					<table class="shapeforms-forms-table">
						<thead>
							<tr>
								<th class="shapeforms-col-cb"><input type="checkbox" id="shapeforms-select-all"></th>
								<?php $this->render_sortable_th( 'title', 'shapeforms-col-title', __( 'Form', 'shapeforms' ), $orderby, $order, $sort_base_url ); ?>
								<th class="shapeforms-col-shortcode"><?php esc_html_e( 'Shortcode', 'shapeforms' ); ?></th>
								<?php $this->render_sortable_th( 'entries', 'shapeforms-col-entries', __( 'Entries', 'shapeforms' ), $orderby, $order, $sort_base_url ); ?>
								<th class="shapeforms-col-status"><?php esc_html_e( 'Status', 'shapeforms' ); ?></th>
								<?php $this->render_sortable_th( 'updated', 'shapeforms-col-date', __( 'Updated', 'shapeforms' ), $orderby, $order, $sort_base_url ); ?>
								<th class="shapeforms-col-actions"><?php esc_html_e( 'Actions', 'shapeforms' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $forms ) ) : ?>
								<tr>
									<td colspan="7" class="shapeforms-forms-empty">
										<?php if ( '' !== $search_term ) : ?>
											<span class="dashicons dashicons-search"></span>
											<p>
												<?php
												printf(
													/* translators: %s: search term. */
													esc_html__( 'No forms found for "%s".', 'shapeforms' ),
													esc_html( $search_term )
												);
												?>
											</p>
										<?php elseif ( $is_trash ) : ?>
											<span class="dashicons dashicons-trash"></span>
											<p><?php esc_html_e( 'Trash is empty.', 'shapeforms' ); ?></p>
										<?php else : ?>
											<span class="shapeforms-forms-empty__badge"><?php shapeforms_lite_brand_icon( array( 'size' => 30 ) ); ?></span>
											<p><?php esc_html_e( 'No forms yet. Create your first form!', 'shapeforms' ); ?></p>
											<a href="<?php echo esc_url( admin_url( 'admin.php?page=shapeforms-lite-builder' ) ); ?>" class="shapeforms-btn-add"><?php esc_html_e( 'Add New Form', 'shapeforms' ); ?></a>
										<?php endif; ?>
									</td>
								</tr>
							<?php else : ?>
								<?php foreach ( $forms as $form ) : ?>
									<?php
									$form_id_int   = absint( $form->id );
									$form_entries  = absint( $form->entry_count ?? 0 );
									$form_fields   = count( $this->extract_fields_from_record( $form ) );
									$shortcode_str = '[shapeforms id="' . $form_id_int . '"]';
									?>
									<tr>
										<td class="shapeforms-col-cb"><input type="checkbox" name="shapeforms_form_ids[]" value="<?php echo absint( $form_id_int ); ?>"></td>
										<td class="shapeforms-col-title">
											<div class="shapeforms-form-title-wrap">
												<?php if ( $is_trash ) : ?>
													<strong><?php echo esc_html( (string) $form->title ); ?></strong>
													<div class="shapeforms-form-row-actions">
														<a href="<?php echo esc_url( $this->get_form_action_url( 'restore', (int) $form->id ) ); ?>"><?php esc_html_e( 'Restore', 'shapeforms' ); ?></a>
														<span class="shapeforms-form-row-sep">|</span>
														<a href="<?php echo esc_url( $this->get_form_action_url( 'delete', (int) $form->id ) ); ?>" class="shapeforms-action-danger" onclick="return confirm('<?php echo esc_js( __( 'Delete permanently?', 'shapeforms' ) ); ?>');"><?php esc_html_e( 'Delete', 'shapeforms' ); ?></a>
													</div>
												<?php else : ?>
													<a href="<?php echo esc_url( admin_url( 'admin.php?page=shapeforms-lite-builder&form_id=' . $form_id_int ) ); ?>" class="shapeforms-form-title-link">
														<?php echo esc_html( (string) $form->title ); ?>
													</a>
													<div class="shapeforms-form-row-actions">
														<a href="<?php echo esc_url( admin_url( 'admin.php?page=shapeforms-lite-builder&form_id=' . $form_id_int ) ); ?>"><?php esc_html_e( 'Edit', 'shapeforms' ); ?></a>
														<span class="shapeforms-form-row-sep">|</span>
														<a href="<?php echo esc_url( $this->get_form_action_url( 'duplicate', (int) $form->id ) ); ?>"><?php esc_html_e( 'Duplicate', 'shapeforms' ); ?></a>
														<span class="shapeforms-form-row-sep">|</span>
														<a href="<?php echo esc_url( admin_url( 'admin.php?page=shapeforms-lite-entries&form_id=' . $form_id_int ) ); ?>"><?php esc_html_e( 'Entries', 'shapeforms' ); ?></a>
														<span class="shapeforms-form-row-sep">|</span>
														<a href="<?php echo esc_url( admin_url( 'admin.php?page=shapeforms-lite-preview&form_id=' . $form_id_int ) ); ?>"><?php esc_html_e( 'Preview', 'shapeforms' ); ?></a>
														<span class="shapeforms-form-row-sep">|</span>
														<a href="<?php echo esc_url( $this->get_form_action_url( 'trash', (int) $form->id ) ); ?>" class="shapeforms-action-danger"><?php esc_html_e( 'Trash', 'shapeforms' ); ?></a>
													</div>
												<?php endif; ?>
											</div>
										</td>
										<td class="shapeforms-col-shortcode">
											<button type="button" class="shapeforms-copy-shortcode" data-shortcode="<?php echo esc_attr( $shortcode_str ); ?>" title="<?php esc_attr_e( 'Click to copy', 'shapeforms' ); ?>">
												<code><?php echo esc_html( $shortcode_str ); ?></code>
												<span class="dashicons dashicons-admin-page"></span>
											</button>
										</td>
										<td class="shapeforms-col-entries">
											<a href="<?php echo esc_url( admin_url( 'admin.php?page=shapeforms-lite-entries&form_id=' . $form_id_int ) ); ?>" class="shapeforms-entries-link">
												<?php echo absint( $form_entries ); ?>
											</a>
										</td>
										<td class="shapeforms-col-status">
											<?php $form_is_active = 'publish' === ( $form->status ?? 'publish' ); ?>
											<label class="shapeforms-form-status-toggle" data-form-id="<?php echo absint( $form_id_int ); ?>">
												<input type="checkbox"<?php echo $form_is_active ? ' checked' : ''; ?>>
												<span class="shapeforms-form-status-toggle__track"><span class="shapeforms-form-status-toggle__thumb"></span></span>
												<span class="shapeforms-form-status-toggle__label"><?php echo $form_is_active ? esc_html__( 'Active', 'shapeforms' ) : esc_html__( 'Inactive', 'shapeforms' ); ?></span>
											</label>
										</td>
										<td class="shapeforms-col-date"><?php echo esc_html( isset( $form->updated_at ) ? wp_date( get_option( 'date_format' ), strtotime( (string) $form->updated_at ) ) : '—' ); ?></td>
										<td class="shapeforms-col-actions">
											<div class="shapeforms-form-actions-dd">
												<button type="button" class="shapeforms-form-actions-btn" title="<?php esc_attr_e( 'Actions', 'shapeforms' ); ?>">
													<span class="dashicons dashicons-ellipsis"></span>
												</button>
												<div class="shapeforms-form-actions-menu">
													<?php if ( $is_trash ) : ?>
														<a href="<?php echo esc_url( $this->get_form_action_url( 'restore', (int) $form->id ) ); ?>"><span class="dashicons dashicons-undo"></span> <?php esc_html_e( 'Restore', 'shapeforms' ); ?></a>
														<a href="<?php echo esc_url( $this->get_form_action_url( 'delete', (int) $form->id ) ); ?>" class="shapeforms-action-danger" onclick="return confirm('<?php echo esc_js( __( 'Delete permanently?', 'shapeforms' ) ); ?>');"><span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Delete', 'shapeforms' ); ?></a>
													<?php else : ?>
														<a href="<?php echo esc_url( admin_url( 'admin.php?page=shapeforms-lite-builder&form_id=' . $form_id_int ) ); ?>"><span class="dashicons dashicons-edit"></span> <?php esc_html_e( 'Edit', 'shapeforms' ); ?></a>
														<a href="<?php echo esc_url( admin_url( 'admin.php?page=shapeforms-lite-entries&form_id=' . $form_id_int ) ); ?>"><span class="dashicons dashicons-email-alt"></span> <?php esc_html_e( 'Entries', 'shapeforms' ); ?></a>
														<a href="<?php echo esc_url( admin_url( 'admin.php?page=shapeforms-lite-preview&form_id=' . $form_id_int ) ); ?>"><span class="dashicons dashicons-visibility"></span> <?php esc_html_e( 'Preview', 'shapeforms' ); ?></a>
														<a href="<?php echo esc_url( $this->get_form_action_url( 'duplicate', (int) $form->id ) ); ?>"><span class="dashicons dashicons-admin-page"></span> <?php esc_html_e( 'Duplicate', 'shapeforms' ); ?></a>
														<a href="<?php echo esc_url( admin_url( 'admin.php?page=shapeforms-lite-settings' ) ); ?>"><span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e( 'Settings', 'shapeforms' ); ?></a>
														<hr>
														<a href="<?php echo esc_url( $this->get_form_action_url( 'trash', (int) $form->id ) ); ?>" class="shapeforms-action-danger"><span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Trash', 'shapeforms' ); ?></a>
													<?php endif; ?>
												</div>
											</div>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
					</div>
				</form>

				<?php if ( $total_pages > 1 ) : ?>
					<div class="shapeforms-pagination">
						<?php
						$paginate_args = array(
							'form_status'   => $is_trash ? 'trash' : '',
							'status_filter' => $status_filter,
							's'             => $search_term,
							'orderby'       => $orderby,
							'order'         => '' !== $orderby ? $order : '',
						);
						$paginate_args = array_filter( $paginate_args, 'strlen' );

						echo wp_kses_post(
							paginate_links(
								array(
									'base'      => add_query_arg(
										array_merge(
											array(
												'page'  => 'shapeforms',
												'paged' => '%#%',
											),
											$paginate_args
										),
										admin_url( 'admin.php' )
									),
									'format'    => '',
									'current'   => min( $current_page, $total_pages ),
									'total'     => $total_pages,
									'prev_text' => __( '&laquo;', 'shapeforms' ),
									'next_text' => __( '&raquo;', 'shapeforms' ),
								)
							)
						);
						?>
					</div>
				<?php endif; ?>
			</div>

		</div>
		<?php
	}

	/**
	 * Renders the builder page.
	 *
	 * @return void
	 */
	public function render_builder_page() {
		$form_id     = isset( $_GET['form_id'] ) ? absint( wp_unslash( $_GET['form_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$form_record = $form_id ? $this->get_form( $form_id ) : null;
		$form_data   = $this->normalize_form_for_builder( $form_record );

		include SHAPEFORMS_LITE_PATH . 'admin/admin-builder.php';
	}

	/**
	 * Renders the admin preview page.
	 *
	 * @return void
	 */
	public function render_preview_page() {
		$form_id = isset( $_GET['form_id'] ) ? absint( wp_unslash( $_GET['form_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$form    = $form_id ? $this->get_form( $form_id ) : null;
		?>
		<div class="wrap shapeforms-preview-wrap">
			<h1 class="screen-reader-text"><?php esc_html_e( 'Preview Form', 'shapeforms' ); ?></h1>
			<hr class="wp-header-end">

			<?php if ( ! $form ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Form not found.', 'shapeforms' ); ?></p></div>
				<?php return; ?>
			<?php endif; ?>

			<div class="shapeforms-preview-shell">
				<div class="shapeforms-preview-toolbar">
					<div class="shapeforms-preview-toolbar__lead">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=shapeforms-lite-builder&form_id=' . $form_id ) ); ?>" class="shapeforms-preview-back">
							<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
							<span class="shapeforms-preview-back__label"><?php esc_html_e( 'Exit', 'shapeforms' ); ?></span>
						</a>
						<span class="shapeforms-preview-toolbar__divider" aria-hidden="true"></span>
						<span class="shapeforms-preview-toolbar__badge" aria-hidden="true">
							<span class="dashicons dashicons-visibility"></span>
						</span>
						<span class="shapeforms-preview-toolbar__title">
							<span class="shapeforms-preview-toolbar__eyebrow"><?php esc_html_e( 'Form Preview', 'shapeforms' ); ?></span>
							<span class="shapeforms-preview-toolbar__name"><?php echo esc_html( '' !== (string) $form->title ? (string) $form->title : __( 'Untitled form', 'shapeforms' ) ); ?></span>
						</span>
					</div>

					<div class="shapeforms-preview-toolbar__devices" role="tablist" aria-label="<?php esc_attr_e( 'Preview devices', 'shapeforms' ); ?>">
						<button type="button" class="shapeforms-device-btn is-active" data-preview-device="desktop" title="<?php esc_attr_e( 'Desktop', 'shapeforms' ); ?>"><span class="dashicons dashicons-desktop"></span></button>
						<button type="button" class="shapeforms-device-btn" data-preview-device="tablet" title="<?php esc_attr_e( 'Tablet', 'shapeforms' ); ?>"><span class="dashicons dashicons-tablet"></span></button>
						<button type="button" class="shapeforms-device-btn" data-preview-device="mobile" title="<?php esc_attr_e( 'Mobile', 'shapeforms' ); ?>"><span class="dashicons dashicons-smartphone"></span></button>
					</div>

					<button type="button" class="shapeforms-preview-shortcode" id="shapeforms-preview-shortcode" data-shortcode="[shapeforms id=&quot;<?php echo esc_attr( (string) $form_id ); ?>&quot;]" title="<?php esc_attr_e( 'Copy shortcode', 'shapeforms' ); ?>" aria-label="<?php esc_attr_e( 'Copy shortcode', 'shapeforms' ); ?>">
						<span class="shapeforms-preview-shortcode__label"><?php esc_html_e( 'Shortcode', 'shapeforms' ); ?></span>
						<code class="shapeforms-preview-shortcode__code"><span class="shapeforms-preview-shortcode__text">[shapeforms id="<?php echo esc_html( (string) $form_id ); ?>"]</span><span class="dashicons dashicons-admin-page shapeforms-preview-shortcode__copy" aria-hidden="true"></span></code>
					</button>
				</div>

				<div class="shapeforms-preview-stage is-desktop" id="shapeforms-preview-stage">
					<div class="shapeforms-preview-viewport">
						<?php echo do_shortcode( '[shapeforms id="' . $form_id . '"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the entries management page.
	 *
	 * @return void
	 */
	public function render_entries_page() {
		$entry_id = isset( $_GET['entry_id'] ) ? absint( wp_unslash( $_GET['entry_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $entry_id ) {
			$this->render_entry_detail( $entry_id );
			return;
		}

		// Collect filters from URL.
		$filter_form   = isset( $_GET['form_id'] ) ? absint( wp_unslash( $_GET['form_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter_status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter_date   = isset( $_GET['date_range'] ) ? sanitize_key( wp_unslash( $_GET['date_range'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter_from   = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter_to     = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Resolve date range presets.
		$today = wp_date( 'Y-m-d' );
		if ( 'today' === $filter_date ) {
			$filter_from = $today;
			$filter_to   = $today;
		} elseif ( 'yesterday' === $filter_date ) {
			$filter_from = wp_date( 'Y-m-d', strtotime( '-1 day' ) );
			$filter_to   = $filter_from;
		} elseif ( 'last_week' === $filter_date ) {
			$filter_from = wp_date( 'Y-m-d', strtotime( '-7 days' ) );
			$filter_to   = $today;
		} elseif ( 'last_month' === $filter_date ) {
			$filter_from = wp_date( 'Y-m-d', strtotime( '-30 days' ) );
			$filter_to   = $today;
		}

		$filters = array(
			'form_id'   => $filter_form,
			'status'    => $filter_status,
			'date_from' => $filter_from,
			'date_to'   => $filter_to,
		);

		$current_page = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page     = 10;
		$total_items  = $this->get_entries_count( $filters );
		$total_pages  = max( 1, (int) ceil( $total_items / $per_page ) );
		$entries      = $this->get_entries( $current_page, $per_page, $filters );
		$all_forms    = $this->get_forms();
		$count_all    = $this->get_entries_count( array( 'form_id' => $filter_form, 'date_from' => $filter_from, 'date_to' => $filter_to ) );
		$count_unread = $this->get_entries_count( array_merge( $filters, array( 'status' => 'unread' ) ) );
		$count_read   = $this->get_entries_count( array_merge( $filters, array( 'status' => 'read' ) ) );
		$count_starred = $this->get_entries_count( array_merge( $filters, array( 'status' => 'starred' ) ) );
		$count_spam    = $this->get_entries_count( array_merge( $filters, array( 'status' => 'spam' ) ) );
		$count_trash   = $this->get_entries_count( array_merge( $filters, array( 'status' => 'trash' ) ) );

		$base_url = admin_url( 'admin.php?page=shapeforms-lite-entries' );
		$notice   = isset( $_GET['shapeforms_notice'] ) ? sanitize_key( wp_unslash( $_GET['shapeforms_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Build filter URL helper.
		$filter_url = function ( $overrides = array() ) use ( $base_url, $filter_form, $filter_status, $filter_date, $filter_from, $filter_to ) {
			$params = array(
				'form_id'    => isset( $overrides['form_id'] ) ? $overrides['form_id'] : $filter_form,
				'status'     => isset( $overrides['status'] ) ? $overrides['status'] : $filter_status,
				'date_range' => isset( $overrides['date_range'] ) ? $overrides['date_range'] : $filter_date,
			);
			if ( 'custom' === $params['date_range'] ) {
				$params['date_from'] = isset( $overrides['date_from'] ) ? $overrides['date_from'] : $filter_from;
				$params['date_to']   = isset( $overrides['date_to'] ) ? $overrides['date_to'] : $filter_to;
			}
			// Remove empty values.
			$params = array_filter( $params, function ( $v ) { return '' !== $v && '0' !== (string) $v && 'all' !== $v; } );
			return add_query_arg( $params, $base_url );
		};
		?>
		<?php $this->render_admin_topbar( 'shapeforms-lite-entries' ); ?>
		<div class="wrap">
			<hr class="wp-header-end"><?php // Keep relocated notices above the header (see Forms list for rationale). ?>
			<div class="shapeforms-page-header">
				<h1><?php esc_html_e( 'Entries', 'shapeforms' ); ?></h1>
				<?php if ( ! empty( $entries ) ) : ?>
					<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array_filter( array( 'form_id' => $filter_form, 'status' => 'all' !== $filter_status ? $filter_status : '', 'date_range' => $filter_date, 'date_from' => $filter_from, 'date_to' => $filter_to ) ), admin_url( 'admin.php?page=shapeforms-lite-entries&shapeforms_export_csv=1' ) ), 'shapeforms_lite_csv_export' ) ); ?>" class="shapeforms-btn-add">
						<span class="dashicons dashicons-download"></span>
						<?php esc_html_e( 'Export CSV', 'shapeforms' ); ?>
					</a>
					<?php
					/**
					 * Fires beside the Entries screen's "Export CSV" button so add-ons can
					 * offer additional export formats (such as Excel and PDF). Only
					 * fires when the current filtered view has entries. Handlers should render
					 * their own filter-scoped, nonce-protected export links/buttons.
					 *
					 * @since 1.1.2
					 *
					 * @param array<string, mixed> $filter_context The current screen filters:
					 *        'form_id', 'status', 'date_range', 'date_from', 'date_to'.
					 */
					do_action(
						'shapeforms_entries_export_actions',
						array(
							'form_id'    => $filter_form,
							'status'     => $filter_status,
							'date_range' => $filter_date,
							'date_from'  => $filter_from,
							'date_to'    => $filter_to,
						)
					);
					?>
				<?php endif; ?>
				<?php $this->render_header_upgrade(); ?>
			</div>

			<?php if ( 'entry_deleted' === $notice ) : ?>
				<div class="shapeforms-card shapeforms-card--success" style="margin-bottom:16px;">
					<p><?php esc_html_e( 'Entry deleted successfully.', 'shapeforms' ); ?></p>
				</div>
			<?php elseif ( 'entry_trashed' === $notice ) : ?>
				<div class="shapeforms-card shapeforms-card--success" style="margin-bottom:16px;">
					<p><?php esc_html_e( 'Entry moved to Trash.', 'shapeforms' ); ?></p>
				</div>
			<?php endif; ?>

			<!-- Filters bar -->
			<div class="shapeforms-entries-filters">
				<div class="shapeforms-entries-filters__left">
					<!-- Status tabs -->
					<div class="shapeforms-entries-tabs">
						<a href="<?php echo esc_url( $filter_url( array( 'status' => 'all' ) ) ); ?>" class="shapeforms-entries-tab<?php echo 'all' === $filter_status ? ' is-active' : ''; ?>">
							<?php esc_html_e( 'All', 'shapeforms' ); ?> <span class="shapeforms-entries-tab__count"><?php echo absint( $count_all ); ?></span>
						</a>
						<a href="<?php echo esc_url( $filter_url( array( 'status' => 'unread' ) ) ); ?>" class="shapeforms-entries-tab<?php echo 'unread' === $filter_status ? ' is-active' : ''; ?>">
							<?php esc_html_e( 'Unread', 'shapeforms' ); ?> <span class="shapeforms-entries-tab__count"><?php echo absint( $count_unread ); ?></span>
						</a>
						<a href="<?php echo esc_url( $filter_url( array( 'status' => 'read' ) ) ); ?>" class="shapeforms-entries-tab<?php echo 'read' === $filter_status ? ' is-active' : ''; ?>">
							<?php esc_html_e( 'Read', 'shapeforms' ); ?> <span class="shapeforms-entries-tab__count"><?php echo absint( $count_read ); ?></span>
						</a>
						<a href="<?php echo esc_url( $filter_url( array( 'status' => 'starred' ) ) ); ?>" class="shapeforms-entries-tab<?php echo 'starred' === $filter_status ? ' is-active' : ''; ?>">
							<?php esc_html_e( 'Starred', 'shapeforms' ); ?> <span class="shapeforms-entries-tab__count"><?php echo absint( $count_starred ); ?></span>
						</a>
						<a href="<?php echo esc_url( $filter_url( array( 'status' => 'spam' ) ) ); ?>" class="shapeforms-entries-tab<?php echo 'spam' === $filter_status ? ' is-active' : ''; ?>">
							<?php esc_html_e( 'Spam', 'shapeforms' ); ?> <span class="shapeforms-entries-tab__count"><?php echo absint( $count_spam ); ?></span>
						</a>
						<?php // Trash tab is always shown (with a 0 count when empty) so it stays a discoverable, fixed destination. ?>
						<a href="<?php echo esc_url( $filter_url( array( 'status' => 'trash' ) ) ); ?>" class="shapeforms-entries-tab<?php echo 'trash' === $filter_status ? ' is-active' : ''; ?>">
							<?php esc_html_e( 'Trash', 'shapeforms' ); ?> <span class="shapeforms-entries-tab__count"><?php echo absint( $count_trash ); ?></span>
						</a>
					</div>
				</div>
				<div class="shapeforms-entries-filters__right">
					<!-- Form dropdown -->
					<?php
					$active_form_label = __( 'All Forms', 'shapeforms' );
					if ( $filter_form ) {
						foreach ( $all_forms as $f ) {
							if ( (int) $f->id === $filter_form ) {
								$active_form_label = $f->title ? $f->title : '#' . $f->id;
								break;
							}
						}
					}
					?>
					<div class="shapeforms-dropdown" id="shapeforms-form-dropdown">
						<button type="button" class="shapeforms-dropdown__trigger">
							<span class="dashicons dashicons-format-aside"></span>
							<span class="shapeforms-dropdown__label"><?php echo esc_html( $active_form_label ); ?></span>
							<span class="shapeforms-dropdown__arrow"></span>
						</button>
						<div class="shapeforms-dropdown__panel">
							<a href="<?php echo esc_url( $filter_url( array( 'form_id' => 0 ) ) ); ?>" class="shapeforms-dropdown__item<?php echo ! $filter_form ? ' is-active' : ''; ?>">
								<?php esc_html_e( 'All Forms', 'shapeforms' ); ?>
							</a>
							<?php foreach ( $all_forms as $f ) : ?>
								<a href="<?php echo esc_url( $filter_url( array( 'form_id' => absint( $f->id ) ) ) ); ?>" class="shapeforms-dropdown__item<?php echo $filter_form === (int) $f->id ? ' is-active' : ''; ?>">
									<?php echo esc_html( $f->title ? $f->title : '#' . $f->id ); ?>
								</a>
							<?php endforeach; ?>
						</div>
					</div>

					<!-- Date filter -->
					<?php
					$date_labels = array(
						''           => __( 'All Time', 'shapeforms' ),
						'today'      => __( 'Today', 'shapeforms' ),
						'yesterday'  => __( 'Yesterday', 'shapeforms' ),
						'last_week'  => __( 'Last 7 Days', 'shapeforms' ),
						'last_month' => __( 'Last 30 Days', 'shapeforms' ),
						'custom'     => __( 'Custom Range', 'shapeforms' ),
					);
					$active_date_label = isset( $date_labels[ $filter_date ] ) ? $date_labels[ $filter_date ] : $date_labels[''];
					?>
					<div class="shapeforms-dropdown" id="shapeforms-date-dropdown">
						<button type="button" class="shapeforms-dropdown__trigger">
							<span class="dashicons dashicons-calendar-alt"></span>
							<span class="shapeforms-dropdown__label"><?php echo esc_html( $active_date_label ); ?></span>
							<span class="shapeforms-dropdown__arrow"></span>
						</button>
						<div class="shapeforms-dropdown__panel">
							<?php foreach ( $date_labels as $key => $label ) : ?>
								<?php if ( 'custom' === $key ) : ?>
									<button type="button" class="shapeforms-dropdown__item<?php echo 'custom' === $filter_date ? ' is-active' : ''; ?>" data-action="custom-date">
										<?php echo esc_html( $label ); ?>
									</button>
								<?php else : ?>
									<a href="<?php echo esc_url( $filter_url( array( 'date_range' => $key ) ) ); ?>" class="shapeforms-dropdown__item<?php echo $filter_date === $key ? ' is-active' : ''; ?>">
										<?php echo esc_html( $label ); ?>
									</a>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
					</div>
					<?php
					/**
					 * Fires inside the Entries filter toolbar, after the Form and Date
					 * dropdowns, so an add-on can add its own filter control (e.g. an
					 * Approval-status dropdown). Render a `.shapeforms-dropdown` block to
					 * match the native Form/Date filters — Lite's dropdown toggle JS
					 * already handles any `.shapeforms-dropdown__trigger` on this screen.
					 *
					 * @since 1.1.3
					 *
					 * @param array<string, mixed> $filter_context form_id, status, date_range, date_from, date_to.
					 */
					do_action(
						'shapeforms_entries_filter_controls',
						array(
							'form_id'    => $filter_form,
							'status'     => $filter_status,
							'date_range' => $filter_date,
							'date_from'  => $filter_from,
							'date_to'    => $filter_to,
						)
					);
					?>
				</div>
			</div>

			<!-- Custom date range (shown when custom selected) -->
			<div id="shapeforms-custom-dates" class="shapeforms-custom-dates"<?php echo 'custom' !== $filter_date ? ' hidden' : ''; ?>>
				<form method="get" action="<?php echo esc_url( $base_url ); ?>" class="shapeforms-custom-dates__form">
					<input type="hidden" name="page" value="shapeforms-lite-entries">
					<?php if ( $filter_form ) : ?><input type="hidden" name="form_id" value="<?php echo absint( $filter_form ); ?>"><?php endif; ?>
					<?php if ( 'all' !== $filter_status ) : ?><input type="hidden" name="status" value="<?php echo esc_attr( $filter_status ); ?>"><?php endif; ?>
					<input type="hidden" name="date_range" value="custom">
					<label><?php esc_html_e( 'From', 'shapeforms' ); ?> <input type="date" name="date_from" value="<?php echo esc_attr( $filter_from ); ?>"></label>
					<label><?php esc_html_e( 'To', 'shapeforms' ); ?> <input type="date" name="date_to" value="<?php echo esc_attr( $filter_to ); ?>"></label>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply', 'shapeforms' ); ?></button>
				</form>
			</div>

			<!-- Bulk actions bar (always visible; only the count text appears once rows are selected) -->
			<?php if ( ! empty( $entries ) ) : ?>
				<div class="shapeforms-bulk-bar" id="shapeforms-bulk-bar">
					<span class="shapeforms-bulk-bar__count" id="shapeforms-bulk-count" hidden></span>
					<select id="shapeforms-bulk-action" class="shapeforms-bulk-bar__select">
						<option value=""><?php esc_html_e( 'Bulk actions', 'shapeforms' ); ?></option>
						<?php if ( 'trash' === $filter_status ) : ?>
							<option value="restore"><?php esc_html_e( 'Restore', 'shapeforms' ); ?></option>
							<option value="delete"><?php esc_html_e( 'Delete Permanently', 'shapeforms' ); ?></option>
						<?php else : ?>
							<option value="read"><?php esc_html_e( 'Mark as Read', 'shapeforms' ); ?></option>
							<option value="unread"><?php esc_html_e( 'Mark as Unread', 'shapeforms' ); ?></option>
							<option value="starred"><?php esc_html_e( 'Mark as Starred', 'shapeforms' ); ?></option>
							<option value="spam"><?php esc_html_e( 'Mark as Spam', 'shapeforms' ); ?></option>
							<option value="trash"><?php esc_html_e( 'Move to Trash', 'shapeforms' ); ?></option>
							<?php
							/**
							 * Fires inside the entries bulk-actions <select>, in non-trash
							 * views, so an add-on can add its own <option> bulk actions
							 * (e.g. Approve / Reject). The add-on performs the work by
							 * handling the `shapeforms_bulk_entry_action` action.
							 *
							 * @since 1.1.3
							 *
							 * @param string $filter_status The current status view.
							 */
							do_action( 'shapeforms_entries_bulk_actions', $filter_status );
							?>
						<?php endif; ?>
					</select>
					<?php
					// Rendered disabled: on load nothing is selected and no action is chosen, so
					// Apply has nothing to do. The JS enables it as soon as both are true. Starting
					// disabled also avoids a brief window where it looks clickable but silently
					// no-ops before the inline script runs.
					?>
					<button type="button" class="button button-primary" id="shapeforms-bulk-apply" disabled title="<?php esc_attr_e( 'Select one or more entries and choose a bulk action.', 'shapeforms' ); ?>"><?php esc_html_e( 'Apply', 'shapeforms' ); ?></button>
					<?php // Export-selected menu: a single dropdown replaces the row of format buttons. Shown only while rows are selected (JS toggles the `hidden` attribute). ?>
					<div class="shapeforms-dropdown shapeforms-bulk-export-dd" id="shapeforms-bulk-export-dd" hidden>
						<button type="button" class="shapeforms-dropdown__trigger">
							<span class="dashicons dashicons-download"></span>
							<span class="shapeforms-dropdown__label"><?php esc_html_e( 'Export Selected', 'shapeforms' ); ?></span>
							<span class="shapeforms-dropdown__arrow"></span>
						</button>
						<div class="shapeforms-dropdown__panel">
							<button type="button" class="shapeforms-dropdown__item" id="shapeforms-bulk-export-csv">
								<span class="dashicons dashicons-media-text"></span>
								<?php esc_html_e( 'Export CSV', 'shapeforms' ); ?>
							</button>
							<?php
							/**
							 * Fires inside the Entries "Export Selected" dropdown, after the CSV
							 * item, so add-ons can add more selected-rows export formats (Excel,
							 * PDF). Handlers render dropdown items (`.shapeforms-dropdown__item`)
							 * and own the JS that collects the checked ids and posts to their
							 * endpoint.
							 *
							 * @since 1.1.2
							 */
							do_action( 'shapeforms_entries_bulk_export_actions' );
							?>
						</div>
					</div>
				</div>
			<?php endif; ?>

			<div class="shapeforms-table-card">
				<div class="shapeforms-table-scroll">
				<table class="widefat fixed shapeforms-entries-table">
					<thead>
						<tr>
							<th style="width:34px;" class="shapeforms-entry-cb">
								<input type="checkbox" id="shapeforms-cb-all" title="<?php esc_attr_e( 'Select all', 'shapeforms' ); ?>">
							</th>
							<th style="width:40px;">&nbsp;</th>
							<th style="width:60px;"><?php esc_html_e( 'ID', 'shapeforms' ); ?></th>
							<th style="width:46%;"><?php esc_html_e( 'Submission', 'shapeforms' ); ?></th>
							<th style="width:12%;"><?php esc_html_e( 'Form', 'shapeforms' ); ?></th>
							<th style="width:18%;"><?php esc_html_e( 'Date', 'shapeforms' ); ?></th>
							<th style="width:12%;"><?php esc_html_e( 'Status', 'shapeforms' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $entries ) ) : ?>
							<tr class="shapeforms-empty-row">
								<td colspan="7"><?php esc_html_e( 'No entries found.', 'shapeforms' ); ?></td>
							</tr>
						<?php else : ?>
							<?php foreach ( $entries as $entry ) : ?>
								<?php
								$entry_status = isset( $entry->status ) ? (string) $entry->status : 'unread';
								$is_unread    = 'unread' === $entry_status;
								$is_starred   = 'starred' === $entry_status;
								$first_values = $this->get_entry_preview_text( $entry );
								$form         = $this->get_form( (int) $entry->form_id );
								$form_title   = $form ? ( $form->title ? (string) $form->title : '#' . absint( $form->id ) ) : '#' . absint( $entry->form_id );
								?>
								<tr class="<?php echo $is_unread ? 'shapeforms-entry--unread' : ''; ?>">
									<td class="shapeforms-entry-cb">
										<input type="checkbox" class="shapeforms-entry-checkbox" value="<?php echo absint( $entry->id ); ?>" aria-label="<?php esc_attr_e( 'Select entry', 'shapeforms' ); ?>">
									</td>
									<td class="shapeforms-entry-star">
										<?php // In the Trash view the star is inert — starring here would silently pull the entry back out of the trash. ?>
										<?php if ( 'trash' !== $filter_status ) : ?>
											<button type="button" class="shapeforms-star-btn<?php echo $is_starred ? ' is-starred' : ''; ?>" data-entry-id="<?php echo absint( $entry->id ); ?>" title="<?php esc_attr_e( 'Star', 'shapeforms' ); ?>">
												<span class="dashicons <?php echo $is_starred ? 'dashicons-star-filled' : 'dashicons-star-empty'; ?>"></span>
											</button>
										<?php endif; ?>
									</td>
									<td><strong><?php echo absint( $entry->id ); ?></strong></td>
									<td>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=shapeforms-lite-entries&entry_id=' . absint( $entry->id ) ) ); ?>" class="shapeforms-entry-link">
											<?php echo esc_html( $first_values ); ?>
										</a>
									</td>
									<td><span class="shapeforms-entry-form-badge"><?php echo esc_html( $form_title ); ?></span></td>
									<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( (string) $entry->created_at ) ) ); ?></td>
									<td>
										<span class="shapeforms-status-badge shapeforms-status--<?php echo esc_attr( $entry_status ); ?>"><?php echo esc_html( ucfirst( $entry_status ) ); ?></span>
										<?php
										/**
										 * Filter extra HTML shown after an entry's status badge in the
										 * list (e.g. an approval-status badge). Returned markup is passed
										 * through wp_kses_post before output.
										 *
										 * @since 1.1.3
										 *
										 * @param string $html  Extra HTML (default empty).
										 * @param object $entry The entry row object.
										 */
										echo wp_kses_post( apply_filters( 'shapeforms_entry_status_badge_after', '', $entry ) );
										?>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
				</div>

				<?php if ( $total_pages > 1 ) : ?>
					<div class="shapeforms-pagination">
						<?php
						$paginate_args = array(
							'form_id'    => $filter_form ? $filter_form : '',
							'status'     => 'all' !== $filter_status ? $filter_status : '',
							'date_range' => $filter_date,
						);
						if ( 'custom' === $filter_date ) {
							$paginate_args['date_from'] = $filter_from;
							$paginate_args['date_to']   = $filter_to;
						}
						$paginate_args = array_filter( $paginate_args );

						echo wp_kses_post(
							paginate_links(
								array(
									'base'      => add_query_arg(
										array_merge(
											array(
												'page'  => 'shapeforms-lite-entries',
												'paged' => '%#%',
											),
											$paginate_args
										),
										admin_url( 'admin.php' )
									),
									'format'    => '',
									'current'   => min( $current_page, $total_pages ),
									'total'     => $total_pages,
									'prev_text' => __( '&laquo;', 'shapeforms' ),
									'next_text' => __( '&raquo;', 'shapeforms' ),
								)
							)
						);
						?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the global settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_tab = in_array( $active_tab, array( 'general', 'captcha', 'ai', 'smtp', 'tools' ), true ) ? $active_tab : 'general';

		$this->handle_settings_save();

		$settings = $this->get_global_settings();

		$tabs = array(
			'general' => array( 'label' => __( 'General', 'shapeforms' ), 'icon' => 'dashicons-admin-generic' ),
			'captcha' => array( 'label' => __( 'Captcha', 'shapeforms' ), 'icon' => 'dashicons-shield' ),
			// `mark` opts a tab out of Dashicons. Dashicons has no sparkle, and this
			// is the only tab that needs one; `icon` stays as the fallback.
			'ai'      => array( 'label' => __( 'AI', 'shapeforms' ), 'icon' => 'dashicons-superhero-alt', 'mark' => 'sparkle' ),
			'smtp'    => array( 'label' => __( 'SMTP', 'shapeforms' ), 'icon' => 'dashicons-email-alt' ),
			'tools'   => array( 'label' => __( 'Tools', 'shapeforms' ), 'icon' => 'dashicons-migrate' ),
		);
		?>
		<?php
		$topbar_active = 'shapeforms-lite-settings';
		if ( 'smtp' === $active_tab ) {
			$topbar_active = 'shapeforms-lite-settings#smtp';
		}
		if ( 'tools' === $active_tab ) {
			$topbar_active = 'shapeforms-lite-settings#tools';
		}
		$this->render_admin_topbar( $topbar_active );
		?>
		<div class="wrap">
			<hr class="wp-header-end"><?php // Keep relocated notices above the header (see Forms list for rationale). ?>
			<div class="shapeforms-page-header">
				<h1 class="wp-heading-inline"><?php esc_html_e( 'ShapeForms Settings', 'shapeforms' ); ?></h1>
				<?php $this->render_header_upgrade(); ?>
			</div>

			<div class="shapeforms-settings-wrap">
				<nav class="shapeforms-settings-sidebar">
					<?php foreach ( $tabs as $tab_key => $tab ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=shapeforms-lite-settings&tab=' . $tab_key ) ); ?>" class="shapeforms-nav-item<?php echo $tab_key === $active_tab ? ' is-active' : ''; ?>">
							<?php if ( 'sparkle' === ( $tab['mark'] ?? '' ) && class_exists( 'ShapeForms_Lite_AI_Builder' ) ) : ?>
								<?php
								// Static markup built from literals inside the helper — there is
								// nothing here for an escaper to act on, and running wp_kses over
								// it would only strip the gradient it depends on.
								echo ShapeForms_Lite_AI_Builder::sparkle_svg( 'tab' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								?>
							<?php else : ?>
								<span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>"></span>
							<?php endif; ?>
							<?php echo esc_html( $tab['label'] ); ?>
						</a>
					<?php endforeach; ?>
				</nav>

				<div class="shapeforms-settings-content">
					<?php if ( 'general' === $active_tab ) : ?>
						<h2><?php esc_html_e( 'General Settings', 'shapeforms' ); ?></h2>
						<p class="shapeforms-tab-description"><?php esc_html_e( 'Configure the core behavior and appearance of your forms.', 'shapeforms' ); ?></p>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=shapeforms-lite-settings&tab=general' ) ); ?>">
							<?php wp_nonce_field( 'shapeforms_lite_save_settings', 'shapeforms_settings_nonce' ); ?>
							<input type="hidden" name="shapeforms_settings_tab" value="general">

							<div class="shapeforms-card">
								<h3><?php esc_html_e( 'Form Style', 'shapeforms' ); ?></h3>
								<label class="shapeforms-style-option<?php echo 'plugin' === $settings['form_style_mode'] ? ' is-selected' : ''; ?>">
									<input type="radio" name="shapeforms_form_style_mode" value="plugin"<?php checked( $settings['form_style_mode'], 'plugin' ); ?>>
									<div>
										<strong><?php esc_html_e( 'Plugin (ShapeForms)', 'shapeforms' ); ?></strong>
										<span><?php esc_html_e( 'Use the built-in ShapeForms styles for inputs, buttons, and layout.', 'shapeforms' ); ?></span>
									</div>
								</label>
								<label class="shapeforms-style-option<?php echo 'theme' === $settings['form_style_mode'] ? ' is-selected' : ''; ?>">
									<input type="radio" name="shapeforms_form_style_mode" value="theme"<?php checked( $settings['form_style_mode'], 'theme' ); ?>>
									<div>
										<strong><?php esc_html_e( 'Theme (Inherit)', 'shapeforms' ); ?></strong>
										<span><?php esc_html_e( 'Inherit input, button, and label styles from your active theme.', 'shapeforms' ); ?></span>
									</div>
								</label>
							</div>

							<div class="shapeforms-card">
								<h3><?php esc_html_e( 'Notifications', 'shapeforms' ); ?></h3>
								<div class="shapeforms-field-row">
									<div class="shapeforms-field-label">
										<label for="shapeforms-default-email"><?php esc_html_e( 'Default email', 'shapeforms' ); ?></label>
									</div>
									<div class="shapeforms-field-control">
										<input type="email" id="shapeforms-default-email" name="shapeforms_default_email" value="<?php echo esc_attr( $settings['default_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
										<p class="description"><?php esc_html_e( 'Leave blank to use the site admin email.', 'shapeforms' ); ?></p>
									</div>
								</div>
							</div>

							<div class="shapeforms-card">
								<h3><?php esc_html_e( 'Validation Messages', 'shapeforms' ); ?></h3>
								<p class="shapeforms-tab-description"><?php esc_html_e( 'Customize the required field error messages. Leave blank to use the default.', 'shapeforms' ); ?></p>
								<?php
								$msg_fields = array(
									'text'     => __( 'Text field', 'shapeforms' ),
									'email'    => __( 'Email field', 'shapeforms' ),
									'number'   => __( 'Number field', 'shapeforms' ),
									'textarea' => __( 'Textarea field', 'shapeforms' ),
									'select'   => __( 'Select field', 'shapeforms' ),
									'checkbox' => __( 'Checkbox field', 'shapeforms' ),
									'radio'    => __( 'Radio field', 'shapeforms' ),
									'date'     => __( 'Date field', 'shapeforms' ),
									'time'     => __( 'Time field', 'shapeforms' ),
								);
								foreach ( $msg_fields as $msg_key => $msg_label ) :
									$setting_key = 'required_msg_' . $msg_key;
								?>
								<div class="shapeforms-field-row">
									<div class="shapeforms-field-label"><label for="shapeforms-<?php echo esc_attr( $setting_key ); ?>"><?php echo esc_html( $msg_label ); ?></label></div>
									<div class="shapeforms-field-control">
										<?php
										/* translators: %s: field label */
										$placeholder_text = sprintf( __( '%s is required.', 'shapeforms' ), $msg_label );
										?>
										<input type="text" id="shapeforms-<?php echo esc_attr( $setting_key ); ?>" name="shapeforms_<?php echo esc_attr( $setting_key ); ?>" value="<?php echo esc_attr( $settings[ $setting_key ] ); ?>" placeholder="<?php echo esc_attr( $placeholder_text ); ?>">
									</div>
								</div>
								<?php endforeach; ?>
							</div>

							<div class="shapeforms-card">
								<h3><?php esc_html_e( 'Data', 'shapeforms' ); ?></h3>
								<label class="shapeforms-toggle">
									<input type="checkbox" id="shapeforms-uninstall-data" name="shapeforms_uninstall_data" value="1"<?php checked( $settings['uninstall_data'] ); ?>>
									<span><?php esc_html_e( 'Delete all forms, entries, and settings when the plugin is uninstalled.', 'shapeforms' ); ?></span>
								</label>
							</div>

							<div class="shapeforms-submit-area">
								<?php submit_button( __( 'Save Changes', 'shapeforms' ), 'primary', 'submit', false ); ?>
							</div>
						</form>

					<?php elseif ( 'captcha' === $active_tab ) : ?>
						<h2><?php esc_html_e( 'Captcha Settings', 'shapeforms' ); ?></h2>
						<p class="shapeforms-tab-description"><?php esc_html_e( 'Choose which captcha service should protect all frontend forms.', 'shapeforms' ); ?></p>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=shapeforms-lite-settings&tab=captcha' ) ); ?>">
							<?php wp_nonce_field( 'shapeforms_lite_save_settings', 'shapeforms_settings_nonce' ); ?>
							<input type="hidden" name="shapeforms_settings_tab" value="captcha">

							<div class="shapeforms-card">
								<h3><?php esc_html_e( 'Provider', 'shapeforms' ); ?></h3>
								<div class="shapeforms-captcha-provider-grid" id="shapeforms-captcha-provider-grid">
									<label class="shapeforms-captcha-card<?php echo 'recaptcha' === $settings['captcha_provider'] ? ' is-selected' : ''; ?>">
										<input type="radio" name="shapeforms_captcha_provider" value="recaptcha"<?php checked( $settings['captcha_provider'], 'recaptcha' ); ?>>
										<span class="shapeforms-captcha-card__title"><?php esc_html_e( 'Google reCAPTCHA v2', 'shapeforms' ); ?></span>
										<span class="shapeforms-captcha-card__description"><?php esc_html_e( 'Free checkbox challenge from Google.', 'shapeforms' ); ?></span>
									</label>
									<label class="shapeforms-captcha-card<?php echo 'hcaptcha' === $settings['captcha_provider'] ? ' is-selected' : ''; ?>">
										<input type="radio" name="shapeforms_captcha_provider" value="hcaptcha"<?php checked( $settings['captcha_provider'], 'hcaptcha' ); ?>>
										<span class="shapeforms-captcha-card__title"><?php esc_html_e( 'hCaptcha', 'shapeforms' ); ?></span>
										<span class="shapeforms-captcha-card__description"><?php esc_html_e( 'Privacy-focused captcha alternative.', 'shapeforms' ); ?></span>
									</label>
									<label class="shapeforms-captcha-card<?php echo 'turnstile' === $settings['captcha_provider'] ? ' is-selected' : ''; ?>">
										<input type="radio" name="shapeforms_captcha_provider" value="turnstile"<?php checked( $settings['captcha_provider'], 'turnstile' ); ?>>
										<span class="shapeforms-captcha-card__title"><?php esc_html_e( 'Cloudflare Turnstile', 'shapeforms' ); ?></span>
										<span class="shapeforms-captcha-card__description"><?php esc_html_e( 'Modern, no-puzzle captcha from Cloudflare.', 'shapeforms' ); ?></span>
									</label>
									<label class="shapeforms-captcha-card<?php echo 'simple_math' === $settings['captcha_provider'] ? ' is-selected' : ''; ?>">
										<input type="radio" name="shapeforms_captcha_provider" value="simple_math"<?php checked( $settings['captcha_provider'], 'simple_math' ); ?>>
										<span class="shapeforms-captcha-card__title"><?php esc_html_e( 'Simple Math', 'shapeforms' ); ?></span>
										<span class="shapeforms-captcha-card__description"><?php esc_html_e( 'Built-in math challenge, no external services.', 'shapeforms' ); ?></span>
									</label>
								</div>
							</div>

							<div class="shapeforms-captcha-panel" data-captcha-panel="recaptcha"<?php echo 'recaptcha' === $settings['captcha_provider'] ? '' : ' hidden'; ?>>
								<div class="shapeforms-card">
									<h3><?php esc_html_e( 'reCAPTCHA Keys', 'shapeforms' ); ?></h3>
									<div class="shapeforms-field-row">
										<div class="shapeforms-field-label"><label for="shapeforms-recaptcha-site-key"><?php esc_html_e( 'Site key', 'shapeforms' ); ?></label></div>
										<div class="shapeforms-field-control">
											<input type="text" id="shapeforms-recaptcha-site-key" name="shapeforms_recaptcha_site_key" value="<?php echo esc_attr( $settings['recaptcha_site_key'] ); ?>">
										</div>
									</div>
									<div class="shapeforms-field-row">
										<div class="shapeforms-field-label"><label for="shapeforms-recaptcha-secret-key"><?php esc_html_e( 'Secret key', 'shapeforms' ); ?></label></div>
										<div class="shapeforms-field-control">
											<input type="password" id="shapeforms-recaptcha-secret-key" name="shapeforms_recaptcha_secret_key" value="" placeholder="<?php echo '' !== $settings['recaptcha_secret_key'] ? esc_attr__( 'Saved — leave blank to keep current key', 'shapeforms' ) : ''; ?>" autocomplete="off">
											<p class="description"><?php echo wp_kses( sprintf( /* translators: %s: reCAPTCHA URL */ __( 'Get your keys from %s. Supports reCAPTCHA v2 (checkbox).', 'shapeforms' ), '<code>google.com/recaptcha/admin</code>' ), array( 'code' => array() ) ); ?></p>
										</div>
									</div>
								</div>
							</div>

							<div class="shapeforms-captcha-panel" data-captcha-panel="hcaptcha"<?php echo 'hcaptcha' === $settings['captcha_provider'] ? '' : ' hidden'; ?>>
								<div class="shapeforms-card">
									<h3><?php esc_html_e( 'hCaptcha Keys', 'shapeforms' ); ?></h3>
									<div class="shapeforms-field-row">
										<div class="shapeforms-field-label"><label for="shapeforms-hcaptcha-site-key"><?php esc_html_e( 'Site key', 'shapeforms' ); ?></label></div>
										<div class="shapeforms-field-control">
											<input type="text" id="shapeforms-hcaptcha-site-key" name="shapeforms_hcaptcha_site_key" value="<?php echo esc_attr( $settings['hcaptcha_site_key'] ); ?>">
										</div>
									</div>
									<div class="shapeforms-field-row">
										<div class="shapeforms-field-label"><label for="shapeforms-hcaptcha-secret-key"><?php esc_html_e( 'Secret key', 'shapeforms' ); ?></label></div>
										<div class="shapeforms-field-control">
											<input type="password" id="shapeforms-hcaptcha-secret-key" name="shapeforms_hcaptcha_secret_key" value="" placeholder="<?php echo '' !== $settings['hcaptcha_secret_key'] ? esc_attr__( 'Saved — leave blank to keep current key', 'shapeforms' ) : ''; ?>" autocomplete="off">
											<p class="description"><?php echo wp_kses( sprintf( /* translators: %s: the captcha provider's URL */ __( 'Get your keys from %s.', 'shapeforms' ), '<code>hcaptcha.com</code>' ), array( 'code' => array() ) ); ?></p>
										</div>
									</div>
								</div>
							</div>

							<div class="shapeforms-captcha-panel" data-captcha-panel="turnstile"<?php echo 'turnstile' === $settings['captcha_provider'] ? '' : ' hidden'; ?>>
								<div class="shapeforms-card">
									<h3><?php esc_html_e( 'Turnstile Keys', 'shapeforms' ); ?></h3>
									<div class="shapeforms-field-row">
										<div class="shapeforms-field-label"><label for="shapeforms-turnstile-site-key"><?php esc_html_e( 'Site key', 'shapeforms' ); ?></label></div>
										<div class="shapeforms-field-control">
											<input type="text" id="shapeforms-turnstile-site-key" name="shapeforms_turnstile_site_key" value="<?php echo esc_attr( $settings['turnstile_site_key'] ); ?>">
										</div>
									</div>
									<div class="shapeforms-field-row">
										<div class="shapeforms-field-label"><label for="shapeforms-turnstile-secret-key"><?php esc_html_e( 'Secret key', 'shapeforms' ); ?></label></div>
										<div class="shapeforms-field-control">
											<input type="password" id="shapeforms-turnstile-secret-key" name="shapeforms_turnstile_secret_key" value="" placeholder="<?php echo '' !== $settings['turnstile_secret_key'] ? esc_attr__( 'Saved — leave blank to keep current key', 'shapeforms' ) : ''; ?>" autocomplete="off">
											<p class="description"><?php echo wp_kses( sprintf( /* translators: %s: the captcha provider's URL */ __( 'Get your keys from %s.', 'shapeforms' ), '<code>dash.cloudflare.com &rarr; Turnstile</code>' ), array( 'code' => array() ) ); /* phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- instructional copy naming the dashboard page where the admin finds their own keys; nothing is loaded from it. */ ?></p>
										</div>
									</div>
								</div>
							</div>

							<div class="shapeforms-captcha-panel" data-captcha-panel="simple_math"<?php echo 'simple_math' === $settings['captcha_provider'] ? '' : ' hidden'; ?>>
								<div class="shapeforms-card">
									<p style="margin:0;">
										<strong><?php esc_html_e( 'Simple Math captcha is built in.', 'shapeforms' ); ?></strong><br>
										<?php esc_html_e( 'No keys required. Visitors solve a small math question before submitting.', 'shapeforms' ); ?>
									</p>
								</div>
							</div>

							<div class="shapeforms-submit-area">
								<?php submit_button( __( 'Save Changes', 'shapeforms' ), 'primary', 'submit', false ); ?>
							</div>
						</form>

					<?php elseif ( 'ai' === $active_tab ) : ?>
						<?php
						$ai_providers = ShapeForms_Lite_AI_Builder::providers();
						$ai_provider  = ShapeForms_Lite_AI_Builder::selected_provider();
						$ai_keys      = ShapeForms_Lite_AI_Builder::api_keys();
						$ai_models    = ShapeForms_Lite_AI_Builder::models();
						?>
						<h2><?php esc_html_e( 'AI Form Builder', 'shapeforms' ); ?></h2>
						<p class="shapeforms-tab-description"><?php esc_html_e( 'Describe a form in plain language and have it built for you. Choose a provider and add your own API key — prompts are sent from this site straight to that provider, and usage is billed to your own account.', 'shapeforms' ); ?></p>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=shapeforms-lite-settings&tab=ai' ) ); ?>">
							<?php wp_nonce_field( 'shapeforms_lite_save_settings', 'shapeforms_settings_nonce' ); ?>
							<input type="hidden" name="shapeforms_settings_tab" value="ai">

							<div class="shapeforms-card">
								<h3><?php esc_html_e( 'Provider', 'shapeforms' ); ?></h3>
								<div class="shapeforms-field-row">
									<div class="shapeforms-field-label"><label for="shapeforms-ai-provider"><?php esc_html_e( 'Service', 'shapeforms' ); ?></label></div>
									<div class="shapeforms-field-control">
										<select id="shapeforms-ai-provider" name="shapeforms_ai_provider" data-shapeforms-select data-bf-ai-provider>
											<?php foreach ( $ai_providers as $bf_slug => $bf_provider ) : ?>
												<option value="<?php echo esc_attr( $bf_slug ); ?>" data-key-url="<?php echo esc_url( $bf_provider['key_url'] ); ?>" <?php selected( $ai_provider, $bf_slug ); ?>>
													<?php echo esc_html( $bf_provider['label'] ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</div>
								</div>

								<?php
								/*
								 * One key field per provider, all rendered, only the selected one
								 * shown. Hidden rather than omitted so every field still posts —
								 * switching provider and saving cannot wipe the key stored for
								 * another. Values are never printed back: each renders empty with a
								 * "leave blank to keep" placeholder, exactly as the captcha secrets
								 * and the SMTP password do.
								 */
								?>
								<?php foreach ( $ai_providers as $bf_slug => $bf_provider ) : ?>
									<div class="shapeforms-field-row shapeforms-ai-key-field" data-bf-ai-key-for="<?php echo esc_attr( $bf_slug ); ?>"<?php echo $bf_slug === $ai_provider ? '' : ' hidden'; ?>>
										<div class="shapeforms-field-label">
											<label for="shapeforms-ai-key-<?php echo esc_attr( sanitize_html_class( $bf_slug ) ); ?>"><?php esc_html_e( 'API key', 'shapeforms' ); ?></label>
										</div>
										<div class="shapeforms-field-control">
											<input
												type="password"
												id="shapeforms-ai-key-<?php echo esc_attr( sanitize_html_class( $bf_slug ) ); ?>"
												name="shapeforms_ai_api_key[<?php echo esc_attr( $bf_slug ); ?>]"
												value=""
												class="regular-text"
												autocomplete="new-password"
												spellcheck="false"
												placeholder="<?php echo ! empty( $ai_keys[ $bf_slug ] ) ? esc_attr__( 'Saved — leave blank to keep current key', 'shapeforms' ) : esc_attr( $bf_provider['key_hint'] ); ?>"
											>
											<p class="description">
												<a href="<?php echo esc_url( $bf_provider['key_url'] ); ?>" target="_blank" rel="noopener noreferrer">
													<?php
													/* translators: %s: AI provider name, e.g. "OpenRouter". */
													printf( esc_html__( 'Get a key from %s', 'shapeforms' ), esc_html( $bf_provider['label'] ) );
													?>
												</a>
											</p>
										</div>
									</div>
								<?php endforeach; ?>

								<?php
								/*
								 * Model picker, only for providers that publish a catalogue — today
								 * that means aggregators. A direct provider hosts a handful of models
								 * and the plugin already picks the right one, so a chooser there is
								 * mostly a way to pick a worse one.
								 */
								?>
								<?php foreach ( $ai_providers as $bf_slug => $bf_provider ) : ?>
									<?php
									if ( ! ShapeForms_Lite_AI_Builder::has_model_picker( $bf_slug ) ) {
										continue;
									}
									$bf_choices = ShapeForms_Lite_AI_Builder::model_choices( $bf_slug );
									$bf_current = isset( $ai_models[ $bf_slug ] ) ? $ai_models[ $bf_slug ] : '';
									$bf_default = $bf_provider['model'];

									// A stored model absent from the catalogue — newly retired, or the
									// list failed to load — is put back at the top, so that merely
									// opening Settings and pressing Save cannot silently reset it.
									$bf_known = array();
									foreach ( $bf_choices as $bf_group ) {
										$bf_known = array_merge( $bf_known, array_keys( $bf_group ) );
									}
									if ( '' !== $bf_current && ! in_array( $bf_current, $bf_known, true ) ) {
										$bf_choices = array_merge( array( __( 'Currently set', 'shapeforms' ) => array( $bf_current => $bf_current ) ), $bf_choices );
									}
									?>
									<div class="shapeforms-field-row shapeforms-ai-model-field" data-bf-ai-model-for="<?php echo esc_attr( $bf_slug ); ?>"<?php echo $bf_slug === $ai_provider ? '' : ' hidden'; ?>>
										<div class="shapeforms-field-label">
											<label for="shapeforms-ai-model-<?php echo esc_attr( sanitize_html_class( $bf_slug ) ); ?>"><?php esc_html_e( 'Model', 'shapeforms' ); ?></label>
										</div>
										<div class="shapeforms-field-control">
											<?php if ( empty( $bf_choices ) ) : ?>
												<?php // Catalogue unreachable — degrade to free text rather than to no way of setting a model at all. ?>
												<input type="text" id="shapeforms-ai-model-<?php echo esc_attr( sanitize_html_class( $bf_slug ) ); ?>" name="shapeforms_ai_model[<?php echo esc_attr( $bf_slug ); ?>]" value="<?php echo esc_attr( $bf_current ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $bf_default ); ?>" autocomplete="off" spellcheck="false">
											<?php else : ?>
												<select id="shapeforms-ai-model-<?php echo esc_attr( sanitize_html_class( $bf_slug ) ); ?>" name="shapeforms_ai_model[<?php echo esc_attr( $bf_slug ); ?>]" data-shapeforms-select>
													<option value=""><?php
														/* translators: %s: the provider's built-in default model id. */
														printf( esc_html__( 'Default — %s', 'shapeforms' ), esc_html( $bf_default ) );
													?></option>
													<?php foreach ( $bf_choices as $bf_vendor => $bf_group ) : ?>
														<optgroup label="<?php echo esc_attr( $bf_vendor ); ?>">
															<?php foreach ( $bf_group as $bf_value => $bf_label ) : ?>
																<option value="<?php echo esc_attr( $bf_value ); ?>" <?php selected( $bf_current, $bf_value ); ?>><?php echo esc_html( $bf_label ); ?></option>
															<?php endforeach; ?>
														</optgroup>
													<?php endforeach; ?>
												</select>
											<?php endif; ?>
											<p class="description"><?php esc_html_e( 'Optional. Leave on Default unless you have a reason to change it. Only models that support strict JSON schema output are listed — anything else cannot build a form.', 'shapeforms' ); ?></p>
										</div>
									</div>
								<?php endforeach; ?>
							</div>

							<div class="shapeforms-submit-area">
								<?php submit_button( __( 'Save Changes', 'shapeforms' ), 'primary', 'submit', false ); ?>
							</div>
						</form>

					<?php elseif ( 'smtp' === $active_tab ) : ?>
						<?php
						$smtp_sub = isset( $_GET['smtp_tab'] ) ? sanitize_key( wp_unslash( $_GET['smtp_tab'] ) ) : 'config'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						$smtp_sub = in_array( $smtp_sub, array( 'config', 'test' ), true ) ? $smtp_sub : 'config';
						?>
						<h2><?php esc_html_e( 'SMTP Settings', 'shapeforms' ); ?></h2>
						<p class="shapeforms-tab-description"><?php esc_html_e( 'Configure a custom SMTP server to send emails reliably.', 'shapeforms' ); ?></p>

						<div class="shapeforms-smtp-subtabs">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=shapeforms-lite-settings&tab=smtp&smtp_tab=config' ) ); ?>" class="<?php echo 'config' === $smtp_sub ? 'active' : ''; ?>">
								<?php esc_html_e( 'Configuration', 'shapeforms' ); ?>
							</a>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=shapeforms-lite-settings&tab=smtp&smtp_tab=test' ) ); ?>" class="<?php echo 'test' === $smtp_sub ? 'active' : ''; ?>">
								<?php esc_html_e( 'Mail Test', 'shapeforms' ); ?>
							</a>
						</div>

						<?php if ( 'config' === $smtp_sub ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=shapeforms-lite-settings&tab=smtp&smtp_tab=config' ) ); ?>">
								<?php wp_nonce_field( 'shapeforms_lite_save_settings', 'shapeforms_settings_nonce' ); ?>
								<input type="hidden" name="shapeforms_settings_tab" value="smtp">

								<div class="shapeforms-card">
									<h3><?php esc_html_e( 'Sender', 'shapeforms' ); ?></h3>
									<p class="description"><?php esc_html_e( 'Applied to all ShapeForms emails, even when SMTP is disabled.', 'shapeforms' ); ?></p>
									<div class="shapeforms-field-row">
										<div class="shapeforms-field-label"><label for="shapeforms-smtp-from-email"><?php esc_html_e( 'From Email', 'shapeforms' ); ?></label></div>
										<div class="shapeforms-field-control">
											<input type="email" id="shapeforms-smtp-from-email" name="shapeforms_smtp_from_email" value="<?php echo esc_attr( $settings['smtp_from_email'] ); ?>" placeholder="<?php esc_attr_e( 'you@example.com', 'shapeforms' ); ?>">
										</div>
									</div>
									<div class="shapeforms-field-row">
										<div class="shapeforms-field-label"><label for="shapeforms-smtp-from-name"><?php esc_html_e( 'From Name', 'shapeforms' ); ?></label></div>
										<div class="shapeforms-field-control">
											<input type="text" id="shapeforms-smtp-from-name" name="shapeforms_smtp_from_name" value="<?php echo esc_attr( $settings['smtp_from_name'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
										</div>
									</div>
									<div class="shapeforms-field-row">
										<div class="shapeforms-field-label"><label for="shapeforms-smtp-reply-to"><?php esc_html_e( 'Reply-To', 'shapeforms' ); ?></label></div>
										<div class="shapeforms-field-control">
											<input type="email" id="shapeforms-smtp-reply-to" name="shapeforms_smtp_reply_to" value="<?php echo esc_attr( $settings['smtp_reply_to'] ); ?>" placeholder="<?php esc_attr_e( 'reply@example.com', 'shapeforms' ); ?>">
										</div>
									</div>
								</div>

								<div class="shapeforms-card">
									<div class="shapeforms-field-row">
										<div class="shapeforms-field-label"><?php esc_html_e( 'Enable SMTP', 'shapeforms' ); ?></div>
										<div class="shapeforms-field-control">
											<div class="shapeforms-radio-group">
												<label><input type="radio" name="shapeforms_smtp_enabled" value="1" id="shapeforms-smtp-enable-yes"<?php checked( $settings['smtp_enabled'] ); ?>><span><?php esc_html_e( 'Yes', 'shapeforms' ); ?></span></label>
												<label><input type="radio" name="shapeforms_smtp_enabled" value="0" id="shapeforms-smtp-enable-no"<?php checked( $settings['smtp_enabled'], false ); ?>><span><?php esc_html_e( 'No', 'shapeforms' ); ?></span></label>
											</div>
										</div>
									</div>
								</div>

								<div id="shapeforms-smtp-fields" style="<?php echo $settings['smtp_enabled'] ? '' : 'display:none;'; ?>">
									<div class="shapeforms-card">
										<h3><?php esc_html_e( 'Server', 'shapeforms' ); ?></h3>
										<div class="shapeforms-field-row">
											<div class="shapeforms-field-label"><label for="shapeforms-smtp-host"><?php esc_html_e( 'SMTP Host', 'shapeforms' ); ?></label></div>
											<div class="shapeforms-field-control">
												<input type="text" id="shapeforms-smtp-host" name="shapeforms_smtp_host" value="<?php echo esc_attr( $settings['smtp_host'] ); ?>" placeholder="smtp.example.com">
											</div>
										</div>
										<div class="shapeforms-field-row">
											<div class="shapeforms-field-label"><?php esc_html_e( 'Encryption', 'shapeforms' ); ?></div>
											<div class="shapeforms-field-control">
												<div class="shapeforms-radio-group">
													<label><input type="radio" name="shapeforms_smtp_encryption" value="none"<?php checked( $settings['smtp_encryption'], 'none' ); ?>><span><?php esc_html_e( 'None', 'shapeforms' ); ?></span></label>
													<label><input type="radio" name="shapeforms_smtp_encryption" value="tls"<?php checked( $settings['smtp_encryption'], 'tls' ); ?>><span><?php esc_html_e( 'TLS', 'shapeforms' ); ?></span></label>
													<label><input type="radio" name="shapeforms_smtp_encryption" value="ssl"<?php checked( $settings['smtp_encryption'], 'ssl' ); ?>><span><?php esc_html_e( 'SSL', 'shapeforms' ); ?></span></label>
												</div>
											</div>
										</div>
										<div class="shapeforms-field-row">
											<div class="shapeforms-field-label"><label for="shapeforms-smtp-port"><?php esc_html_e( 'Port', 'shapeforms' ); ?></label></div>
											<div class="shapeforms-field-control">
												<input type="number" id="shapeforms-smtp-port" name="shapeforms_smtp_port" value="<?php echo esc_attr( $settings['smtp_port'] ); ?>" placeholder="587">
											</div>
										</div>
									</div>

									<div class="shapeforms-card">
										<h3><?php esc_html_e( 'Authentication', 'shapeforms' ); ?></h3>
										<div class="shapeforms-field-row">
											<div class="shapeforms-field-label"><?php esc_html_e( 'SMTP Auth', 'shapeforms' ); ?></div>
											<div class="shapeforms-field-control">
												<div class="shapeforms-radio-group">
													<label><input type="radio" name="shapeforms_smtp_auth" value="1" id="shapeforms-smtp-auth-yes"<?php checked( $settings['smtp_auth'] ); ?>><span><?php esc_html_e( 'Yes', 'shapeforms' ); ?></span></label>
													<label><input type="radio" name="shapeforms_smtp_auth" value="0" id="shapeforms-smtp-auth-no"<?php checked( $settings['smtp_auth'], false ); ?>><span><?php esc_html_e( 'No', 'shapeforms' ); ?></span></label>
												</div>
											</div>
										</div>
										<div id="shapeforms-smtp-auth-fields" style="<?php echo $settings['smtp_auth'] ? '' : 'display:none;'; ?>">
											<div class="shapeforms-field-row">
												<div class="shapeforms-field-label"><label for="shapeforms-smtp-username"><?php esc_html_e( 'Username', 'shapeforms' ); ?></label></div>
												<div class="shapeforms-field-control">
													<input type="text" id="shapeforms-smtp-username" name="shapeforms_smtp_username" value="<?php echo esc_attr( $settings['smtp_username'] ); ?>" autocomplete="off">
												</div>
											</div>
											<div class="shapeforms-field-row">
												<div class="shapeforms-field-label"><label for="shapeforms-smtp-password"><?php esc_html_e( 'Password', 'shapeforms' ); ?></label></div>
												<div class="shapeforms-field-control">
													<input type="password" id="shapeforms-smtp-password" name="shapeforms_smtp_password" value="" placeholder="<?php echo '' !== $settings['smtp_password'] ? esc_attr__( 'Saved — leave blank to keep current password', 'shapeforms' ) : ''; ?>" autocomplete="new-password">
												</div>
											</div>
										</div>
									</div>
								</div>

								<div class="shapeforms-submit-area">
									<?php submit_button( __( 'Save Changes', 'shapeforms' ), 'primary', 'submit', false ); ?>
								</div>
							</form>

						<?php else : ?>
							<div class="shapeforms-card">
								<h3><?php esc_html_e( 'Send a Test Email', 'shapeforms' ); ?></h3>
								<div class="shapeforms-field-row">
									<div class="shapeforms-field-label"><label for="shapeforms-test-to"><?php esc_html_e( 'To', 'shapeforms' ); ?></label></div>
									<div class="shapeforms-field-control">
										<input type="email" id="shapeforms-test-to" placeholder="<?php esc_attr_e( 'recipient@example.com', 'shapeforms' ); ?>">
									</div>
								</div>
								<div class="shapeforms-field-row">
									<div class="shapeforms-field-label"><label for="shapeforms-test-subject"><?php esc_html_e( 'Subject', 'shapeforms' ); ?></label></div>
									<div class="shapeforms-field-control">
										<input type="text" id="shapeforms-test-subject" placeholder="<?php esc_attr_e( 'Subject', 'shapeforms' ); ?>">
									</div>
								</div>
								<div class="shapeforms-field-row">
									<div class="shapeforms-field-label"><label for="shapeforms-test-message"><?php esc_html_e( 'Message', 'shapeforms' ); ?></label></div>
									<div class="shapeforms-field-control">
										<textarea id="shapeforms-test-message" rows="4" placeholder="<?php esc_attr_e( 'Message', 'shapeforms' ); ?>"></textarea>
									</div>
								</div>
							</div>
							<div class="shapeforms-submit-area">
								<button type="button" id="shapeforms-send-test-mail" class="button button-primary"><?php esc_html_e( 'Send Test Mail', 'shapeforms' ); ?></button>
								<span id="shapeforms-test-mail-result" class="shapeforms-test-mail-result"></span>
							</div>
						<?php endif; ?>

					<?php elseif ( 'tools' === $active_tab ) : ?>
						<h2><?php esc_html_e( 'Tools', 'shapeforms' ); ?></h2>
						<p class="shapeforms-tab-description"><?php esc_html_e( 'Export and import your forms, entries, and plugin settings.', 'shapeforms' ); ?></p>

						<?php
						$export_import = ShapeForms_Lite::get_instance()->get_export_import();
						if ( $export_import ) {
							$export_import->render_tools_tab( $settings );
						}
						?>

					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Returns global plugin settings.
	 *
	 * @return array<string, mixed>
	 */
	private function get_global_settings() {
		$defaults = array(
			'form_style_mode'      => 'plugin',
			'default_email'        => '',
			'uninstall_data'       => false,
			'captcha_provider'     => 'simple_math',
			'recaptcha_site_key'   => '',
			'recaptcha_secret_key' => '',
			'hcaptcha_site_key'    => '',
			'hcaptcha_secret_key'  => '',
			'turnstile_site_key'   => '',
			'turnstile_secret_key' => '',
			'required_msg_text'     => '',
			'required_msg_email'    => '',
			'required_msg_number'   => '',
			'required_msg_textarea' => '',
			'required_msg_select'   => '',
			'required_msg_checkbox' => '',
			'required_msg_radio'    => '',
			'required_msg_date'     => '',
			'required_msg_time'     => '',
			'smtp_enabled'         => false,
			'smtp_from_email'      => '',
			'smtp_from_name'       => '',
			'smtp_reply_to'        => '',
			'smtp_host'            => '',
			'smtp_encryption'      => 'none',
			'smtp_port'            => '',
			'smtp_auth'            => false,
			'smtp_username'        => '',
			'smtp_password'        => '',
		);

		$saved = get_option( 'shapeforms_lite_settings', array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return array_merge( $defaults, $saved );
	}

	/**
	 * Handles settings form submission.
	 *
	 * @return void
	 */
	private function handle_settings_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) {
			return;
		}

		$nonce = isset( $_POST['shapeforms_settings_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['shapeforms_settings_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'shapeforms_lite_save_settings' ) ) {
			return;
		}

		$settings = $this->get_global_settings();

		// Each settings tab is its own <form> and only submits its own fields. Branch on
		// the tab sentinel so saving one tab never nulls out the keys owned by the others
		// (absent-from-POST keys would otherwise be reset to their defaults). Fall back to
		// 'general' for legacy/no-sentinel posts.
		$active_tab = isset( $_POST['shapeforms_settings_tab'] ) ? sanitize_key( wp_unslash( $_POST['shapeforms_settings_tab'] ) ) : 'general';

		if ( 'general' === $active_tab ) {
			$form_style_mode                  = isset( $_POST['shapeforms_form_style_mode'] ) ? sanitize_key( wp_unslash( $_POST['shapeforms_form_style_mode'] ) ) : 'plugin';
			$settings['form_style_mode']      = in_array( $form_style_mode, array( 'plugin', 'theme' ), true ) ? $form_style_mode : 'plugin';
			$settings['default_email']        = isset( $_POST['shapeforms_default_email'] ) ? sanitize_email( wp_unslash( $_POST['shapeforms_default_email'] ) ) : '';
			$settings['uninstall_data']       = ! empty( $_POST['shapeforms_uninstall_data'] );

			// Validation messages.
			$msg_types = array( 'text', 'email', 'number', 'textarea', 'select', 'checkbox', 'radio', 'date', 'time' );
			foreach ( $msg_types as $msg_type ) {
				$msg_key = 'required_msg_' . $msg_type;
				$settings[ $msg_key ] = isset( $_POST[ 'shapeforms_' . $msg_key ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'shapeforms_' . $msg_key ] ) ) : '';
			}
		}

		if ( 'captcha' === $active_tab ) {
			$captcha_provider                 = isset( $_POST['shapeforms_captcha_provider'] ) ? sanitize_key( wp_unslash( $_POST['shapeforms_captcha_provider'] ) ) : 'simple_math';
			$settings['captcha_provider']     = in_array( $captcha_provider, array( 'recaptcha', 'hcaptcha', 'turnstile', 'simple_math' ), true ) ? $captcha_provider : 'simple_math';
			$settings['recaptcha_site_key']   = isset( $_POST['shapeforms_recaptcha_site_key'] ) ? sanitize_text_field( wp_unslash( $_POST['shapeforms_recaptcha_site_key'] ) ) : '';
			$settings['hcaptcha_site_key']    = isset( $_POST['shapeforms_hcaptcha_site_key'] ) ? sanitize_text_field( wp_unslash( $_POST['shapeforms_hcaptcha_site_key'] ) ) : '';
			$settings['turnstile_site_key']   = isset( $_POST['shapeforms_turnstile_site_key'] ) ? sanitize_text_field( wp_unslash( $_POST['shapeforms_turnstile_site_key'] ) ) : '';

			// Secret keys are masked on render (value=""); only overwrite when a new value is
			// submitted, so re-saving the page with a blank field preserves the stored key.
			if ( isset( $_POST['shapeforms_recaptcha_secret_key'] ) && '' !== $_POST['shapeforms_recaptcha_secret_key'] ) {
				$settings['recaptcha_secret_key'] = sanitize_text_field( wp_unslash( $_POST['shapeforms_recaptcha_secret_key'] ) );
			}
			if ( isset( $_POST['shapeforms_hcaptcha_secret_key'] ) && '' !== $_POST['shapeforms_hcaptcha_secret_key'] ) {
				$settings['hcaptcha_secret_key'] = sanitize_text_field( wp_unslash( $_POST['shapeforms_hcaptcha_secret_key'] ) );
			}
			if ( isset( $_POST['shapeforms_turnstile_secret_key'] ) && '' !== $_POST['shapeforms_turnstile_secret_key'] ) {
				$settings['turnstile_secret_key'] = sanitize_text_field( wp_unslash( $_POST['shapeforms_turnstile_secret_key'] ) );
			}
		}

		if ( 'ai' === $active_tab ) {
			/*
			 * AI settings live in their own options, NOT in $settings. The export
			 * tool writes shapeforms_lite_settings into a downloadable file and
			 * scrubs it with a flat key scan — a nested credential would slip
			 * straight past that and ship inside an export someone might share.
			 * Keeping them out of the array keeps them out of the export.
			 */
			$bf_ai_providers = ShapeForms_Lite_AI_Builder::providers();

			$bf_ai_provider = isset( $_POST['shapeforms_ai_provider'] ) ? sanitize_key( wp_unslash( $_POST['shapeforms_ai_provider'] ) ) : '';
			if ( '' !== $bf_ai_provider && array_key_exists( $bf_ai_provider, $bf_ai_providers ) ) {
				update_option( 'shapeforms_lite_ai_provider', $bf_ai_provider, false );
			}

			/*
			 * Keys render empty and only overwrite when something was typed — the
			 * same contract as the captcha secrets above. A provider missing from
			 * the POST is left alone rather than cleared: absent means unknown (the
			 * page may predate that provider), not "delete this". Getting this
			 * wrong once silently wiped a stored key on an ordinary save.
			 */
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitized in the loop below.
			$bf_posted_keys = isset( $_POST['shapeforms_ai_api_key'] ) && is_array( $_POST['shapeforms_ai_api_key'] ) ? wp_unslash( $_POST['shapeforms_ai_api_key'] ) : array();
			$bf_stored_keys = ShapeForms_Lite_AI_Builder::api_keys();

			foreach ( $bf_posted_keys as $bf_slug => $bf_value ) {
				$bf_slug  = sanitize_key( (string) $bf_slug );
				$bf_value = trim( sanitize_text_field( (string) $bf_value ) );

				if ( '' === $bf_value || ! array_key_exists( $bf_slug, $bf_ai_providers ) ) {
					continue;
				}

				$bf_stored_keys[ $bf_slug ] = $bf_value;
			}

			update_option( 'shapeforms_lite_ai_api_keys', $bf_stored_keys, false );

			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitized in the loop below.
			$bf_posted_models = isset( $_POST['shapeforms_ai_model'] ) && is_array( $_POST['shapeforms_ai_model'] ) ? wp_unslash( $_POST['shapeforms_ai_model'] ) : array();
			$bf_stored_models = ShapeForms_Lite_AI_Builder::models();

			foreach ( $bf_posted_models as $bf_slug => $bf_value ) {
				$bf_slug  = sanitize_key( (string) $bf_slug );
				$bf_value = trim( sanitize_text_field( (string) $bf_value ) );

				if ( ! array_key_exists( $bf_slug, $bf_ai_providers ) ) {
					continue;
				}

				// An empty value here IS a deliberate choice — it is the "Default"
				// entry in the picker — so unlike a key it clears the override.
				if ( '' === $bf_value ) {
					unset( $bf_stored_models[ $bf_slug ] );
					continue;
				}

				// The character class the provider catalogues actually use; rolling
				// aliases carry a tilde.
				if ( preg_match( '#^[A-Za-z0-9._:/~-]{1,128}$#', $bf_value ) ) {
					$bf_stored_models[ $bf_slug ] = $bf_value;
				}
			}

			update_option( 'shapeforms_lite_ai_models', $bf_stored_models, false );
		}
		if ( 'smtp' === $active_tab ) {
			$settings['smtp_enabled']    = ! empty( $_POST['shapeforms_smtp_enabled'] );
			$settings['smtp_from_email'] = isset( $_POST['shapeforms_smtp_from_email'] ) ? sanitize_email( wp_unslash( $_POST['shapeforms_smtp_from_email'] ) ) : '';
			$settings['smtp_from_name']  = isset( $_POST['shapeforms_smtp_from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['shapeforms_smtp_from_name'] ) ) : '';
			$settings['smtp_reply_to']   = isset( $_POST['shapeforms_smtp_reply_to'] ) ? sanitize_email( wp_unslash( $_POST['shapeforms_smtp_reply_to'] ) ) : '';
			$settings['smtp_host']       = isset( $_POST['shapeforms_smtp_host'] ) ? sanitize_text_field( wp_unslash( $_POST['shapeforms_smtp_host'] ) ) : '';
			$smtp_encryption             = isset( $_POST['shapeforms_smtp_encryption'] ) ? sanitize_key( wp_unslash( $_POST['shapeforms_smtp_encryption'] ) ) : 'none';
			$settings['smtp_encryption'] = in_array( $smtp_encryption, array( 'none', 'tls', 'ssl' ), true ) ? $smtp_encryption : 'none';
			$settings['smtp_port']       = isset( $_POST['shapeforms_smtp_port'] ) ? absint( $_POST['shapeforms_smtp_port'] ) : '';
			$settings['smtp_auth']       = ! empty( $_POST['shapeforms_smtp_auth'] );
			$settings['smtp_username']   = isset( $_POST['shapeforms_smtp_username'] ) ? sanitize_text_field( wp_unslash( $_POST['shapeforms_smtp_username'] ) ) : '';

			// Only update password if a new value was provided (preserve existing on empty).
			if ( isset( $_POST['shapeforms_smtp_password'] ) && '' !== $_POST['shapeforms_smtp_password'] ) {
				$settings['smtp_password'] = sanitize_text_field( wp_unslash( $_POST['shapeforms_smtp_password'] ) );
			}
		}

		// autoload=false: this option holds secrets (SMTP password, captcha secret keys) and
		// is only read on admin/submission/mail paths, never on every front-end page load.
		update_option( 'shapeforms_lite_settings', $settings, false );

		add_settings_error( 'shapeforms_lite_settings', 'settings_updated', __( 'Settings saved.', 'shapeforms' ), 'success' );
		settings_errors( 'shapeforms_lite_settings' );
	}

	/**
	 * Filters the From email address for all wp_mail() calls.
	 *
	 * Applied even when SMTP is disabled so admin/user emails don't
	 * fall back to the WordPress default wordpress@domain.com address,
	 * which many mail servers reject.
	 *
	 * @param string $from Default from address.
	 * @return string
	 */
	public function filter_mail_from( $from ) {
		$settings = $this->get_global_settings();

		if ( empty( $settings['smtp_from_email'] ) || ! is_email( $settings['smtp_from_email'] ) ) {
			return $from;
		}

		$configured_email = $settings['smtp_from_email'];

		// If SMTP is enabled, trust it to handle any from address (credentials authenticate the sender).
		if ( ! empty( $settings['smtp_enabled'] ) && ! empty( $settings['smtp_host'] ) ) {
			return $configured_email;
		}

		// Without SMTP, only use the configured address as From if its domain matches the site domain.
		// Using a Gmail/Yahoo/external address as From without SMTP causes DMARC rejection.
		$site_domain       = wp_parse_url( home_url(), PHP_URL_HOST );
		$configured_domain = substr( strrchr( $configured_email, '@' ), 1 );

		if ( $configured_domain && $site_domain && rtrim( $configured_domain, '.' ) === rtrim( $site_domain, '.' ) ) {
			return $configured_email;
		}

		// Domain mismatch — keep site's default From and let configure_smtp handle Reply-To.
		return $from;
	}

	/**
	 * Filters the From name for all wp_mail() calls.
	 *
	 * @param string $name Default from name.
	 * @return string
	 */
	public function filter_mail_from_name( $name ) {
		$settings = $this->get_global_settings();

		if ( ! empty( $settings['smtp_from_name'] ) ) {
			return $settings['smtp_from_name'];
		}

		return $name;
	}

	/**
	 * Configures PHPMailer to use SMTP when enabled.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance.
	 * @return void
	 */
	public function configure_smtp( $phpmailer ) {
		$settings = $this->get_global_settings();

		// Always apply Reply-To if configured (works with or without SMTP).
		$reply_to = ! empty( $settings['smtp_reply_to'] ) ? $settings['smtp_reply_to'] : '';

		// If From Email is set but its domain doesn't match the site (e.g. Gmail),
		// use it as Reply-To instead so replies go to the right address without DMARC failure.
		if ( empty( $reply_to ) && ! empty( $settings['smtp_from_email'] ) && is_email( $settings['smtp_from_email'] ) ) {
			$site_domain       = wp_parse_url( home_url(), PHP_URL_HOST );
			$configured_domain = substr( strrchr( $settings['smtp_from_email'], '@' ), 1 );
			if ( $configured_domain && $site_domain && rtrim( $configured_domain, '.' ) !== rtrim( $site_domain, '.' ) ) {
				$reply_to = $settings['smtp_from_email'];
			}
		}

		// Apply the global Reply-To only when the message doesn't already carry one
		// (e.g. a per-form Reply-To set via the email headers filter), so a more
		// specific Reply-To always wins over this site-wide default.
		if ( $reply_to && empty( $phpmailer->getReplyToAddresses() ) ) {
			$phpmailer->addReplyTo( $reply_to );
		}

		if ( empty( $settings['smtp_enabled'] ) || empty( $settings['smtp_host'] ) ) {
			return;
		}

		$phpmailer->isSMTP();
		$phpmailer->Host = $settings['smtp_host']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		if ( ! empty( $settings['smtp_port'] ) ) {
			$phpmailer->Port = (int) $settings['smtp_port']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		if ( 'tls' === $settings['smtp_encryption'] ) {
			$phpmailer->SMTPSecure = 'tls'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		} elseif ( 'ssl' === $settings['smtp_encryption'] ) {
			$phpmailer->SMTPSecure = 'ssl'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		} else {
			$phpmailer->SMTPSecure = ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$phpmailer->SMTPAutoTLS = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		if ( ! empty( $settings['smtp_auth'] ) ) {
			$phpmailer->SMTPAuth = true; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$phpmailer->Username = $settings['smtp_username']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$phpmailer->Password = $settings['smtp_password']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		if ( ! empty( $settings['smtp_from_email'] ) ) {
			$phpmailer->From = $settings['smtp_from_email']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		if ( ! empty( $settings['smtp_from_name'] ) ) {
			$phpmailer->FromName = $settings['smtp_from_name']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

	}

	/**
	 * Sends a test email via AJAX.
	 *
	 * @return void
	 */
	public function ajax_send_test_mail() {
		check_ajax_referer( 'shapeforms_lite_test_mail' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'shapeforms' ) ), 403 );
		}

		$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';

		if ( ! is_email( $to ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'shapeforms' ) ) );
		}

		// Throttle so the endpoint can't be used to fire mail in a tight loop.
		$throttle_key = 'shapeforms_test_mail_' . get_current_user_id();
		if ( get_transient( $throttle_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Please wait a few seconds before sending another test email.', 'shapeforms' ) ) );
		}
		set_transient( $throttle_key, 1, 15 );

		// Use the admin-supplied subject/body, falling back to defaults when either
		// is left blank. Sanitized to plain text so this stays a delivery check, not
		// an open HTML mailer.
		$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

		if ( '' === $subject ) {
			$subject = __( 'ShapeForms SMTP test email', 'shapeforms' );
		}

		if ( '' === $message ) {
			$message = __( 'This is a test email from ShapeForms confirming your email/SMTP settings are working.', 'shapeforms' );
		}

		$sent = wp_mail( $to, $subject, $message );

		if ( $sent ) {
			wp_send_json_success( array( 'message' => __( 'Email sent successfully!', 'shapeforms' ) ) );
		}

		wp_send_json_error( array( 'message' => __( 'Failed to send email. Check your SMTP configuration.', 'shapeforms' ) ) );
	}

	/**
	 * Proxies the AJAX save action.
	 *
	 * @return void
	 */
	public function ajax_save_form() {
		$this->ajax_handler->save_form();
	}

	/**
	 * Handles list-table actions like duplicate and delete.
	 *
	 * @return void
	 */
	public function handle_form_actions() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->maybe_export_csv();
		$this->maybe_delete_entry();
		$this->maybe_trash_or_restore_entry();

		// Bulk actions are submitted via POST, while single-row actions arrive through signed admin URLs.
		$this->handle_bulk_actions();

		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$action = isset( $_GET['shapeforms_action'] ) ? sanitize_key( wp_unslash( $_GET['shapeforms_action'] ) ) : '';

		if ( 'shapeforms' !== $page || ! in_array( $action, array( 'duplicate', 'delete', 'trash', 'restore' ), true ) ) {
			return;
		}

		$form_id = isset( $_GET['form_id'] ) ? absint( wp_unslash( $_GET['form_id'] ) ) : 0;
		$nonce   = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! $form_id || ! wp_verify_nonce( $nonce, 'shapeforms_lite_' . $action . '_form_' . $form_id ) ) {
			wp_safe_redirect( $this->get_forms_page_url( 'invalid_nonce' ) );
			exit;
		}

		if ( 'duplicate' === $action ) {
			$new_form_id = $this->duplicate_form( $form_id );
			wp_safe_redirect( $new_form_id ? admin_url( 'admin.php?page=shapeforms-lite-builder&form_id=' . $new_form_id ) : $this->get_forms_page_url( 'duplicate_failed' ) );
			exit;
		}

		if ( 'trash' === $action ) {
			$this->trash_form( $form_id );
			wp_safe_redirect( $this->get_forms_page_url( 'trashed' ) );
			exit;
		}

		if ( 'restore' === $action ) {
			$this->restore_form( $form_id );
			wp_safe_redirect( $this->get_forms_page_url( 'restored', 'trash' ) );
			exit;
		}

		if ( 'delete' === $action ) {
			wp_safe_redirect( $this->delete_form( $form_id ) ? $this->get_forms_page_url( 'deleted', 'trash' ) : $this->get_forms_page_url( 'delete_failed', 'trash' ) );
			exit;
		}
	}

	/**
	 * Handles bulk actions from the forms list table.
	 *
	 * @return void
	 */
	private function handle_bulk_actions() {
		$bulk_action = isset( $_POST['shapeforms_bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['shapeforms_bulk_action'] ) ) : '';

		if ( ! in_array( $bulk_action, array( 'trash', 'restore', 'delete' ), true ) ) {
			return;
		}

		$nonce = isset( $_POST['shapeforms_bulk_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['shapeforms_bulk_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'shapeforms_lite_bulk_action' ) ) {
			wp_safe_redirect( $this->get_forms_page_url( 'invalid_nonce' ) );
			exit;
		}

		$form_ids = isset( $_POST['shapeforms_form_ids'] ) && is_array( $_POST['shapeforms_form_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['shapeforms_form_ids'] ) ) : array();

		if ( empty( $form_ids ) ) {
			wp_safe_redirect( $this->get_forms_page_url() );
			exit;
		}

		foreach ( $form_ids as $form_id ) {
			if ( ! $form_id ) {
				continue;
			}

			if ( 'trash' === $bulk_action ) {
				$this->trash_form( $form_id );
			} elseif ( 'restore' === $bulk_action ) {
				$this->restore_form( $form_id );
			} elseif ( 'delete' === $bulk_action ) {
				$this->delete_form( $form_id );
			}
		}

		if ( 'trash' === $bulk_action ) {
			wp_safe_redirect( $this->get_forms_page_url( 'bulk_trashed' ) );
		} elseif ( 'restore' === $bulk_action ) {
			wp_safe_redirect( $this->get_forms_page_url( 'bulk_restored', 'trash' ) );
		} else {
			wp_safe_redirect( $this->get_forms_page_url( 'bulk_deleted', 'trash' ) );
		}
		exit;
	}

	/**
	 * Returns a list of forms filtered by view.
	 *
	 * @param string $view 'all' for non-trashed forms, 'trash' for trashed forms.
	 * @return array<int, object>
	 */
	private function get_forms( $view = 'all', $status_filter = '', $search_term = '', $orderby = '', $order = 'desc', $per_page = null, $offset = 0 ) {
		global $wpdb;

		$forms_table   = esc_sql( $this->plugin->get_forms_table_name() );
		$entries_table = esc_sql( $this->plugin->get_entries_table_name() );

		list( $where, $params ) = $this->build_forms_where( $view, $status_filter, $search_term );

		$order_sql = $this->forms_order_sql( $orderby, $order );

		$limit_sql = '';
		if ( null !== $per_page ) {
			$limit_sql = 'LIMIT %d OFFSET %d';
			$params[]  = (int) $per_page;
			$params[]  = (int) $offset;
		}

		// Every interpolated part of this query is code, not data: the table names are
		// esc_sql()'d above, $order_sql and $limit_sql come from allowlists, and $where
		// is an array of literal fragments whose only variables are %s/%d placeholders
		// bound from $params by prepare(). `phpcs:ignore` covers a single line, so a
		// disable/enable pair is needed to span the whole multi-line statement.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$sql = $wpdb->prepare(
			"SELECT f.id, f.title, f.status, f.fields_json, f.updated_at, COALESCE(ec.total, 0) AS entry_count
			FROM `{$forms_table}` f
			LEFT JOIN ( SELECT form_id, COUNT(*) AS total FROM `{$entries_table}` WHERE trashed_at IS NULL GROUP BY form_id ) ec ON ec.form_id = f.id
			WHERE " . implode( ' AND ', $where ) . "
			ORDER BY {$order_sql}
			{$limit_sql}",
			$params
		);

		$results = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $results;
	}

	/**
	 * Returns the total number of forms matching a view/status/search combination
	 * (for computing pagination — must reflect the same WHERE clause as get_forms()).
	 *
	 * @param string $view          'all' for non-trashed forms, 'trash' for trashed forms.
	 * @param string $status_filter 'active', 'inactive', or '' for no filter (non-trash view only).
	 * @param string $search_term   Search term matched against the title, or ''.
	 * @return int
	 */
	private function get_forms_total( $view, $status_filter, $search_term ) {
		global $wpdb;

		$forms_table = esc_sql( $this->plugin->get_forms_table_name() );

		list( $where, $params ) = $this->build_forms_where( $view, $status_filter, $search_term );

		// Same reasoning as get_forms(): the table name is esc_sql()'d and $where holds
		// literal fragments with bound placeholders. Aliased "f" to match the column
		// prefixes build_forms_where() emits.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$forms_table}` f WHERE " . implode( ' AND ', $where ),
				$params
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $total;
	}

	/**
	 * Builds the shared WHERE clause + bound params for the forms list, used by
	 * both get_forms() and get_forms_total() so the paginated rows and the total
	 * count can never drift apart.
	 *
	 * @param string $view          'all' for non-trashed forms, 'trash' for trashed forms.
	 * @param string $status_filter 'active', 'inactive', or '' for no filter (non-trash view only).
	 * @param string $search_term   Search term matched against the title, or ''.
	 * @return array{0: string[], 1: array<int, string>} [ $where_clauses, $params ].
	 */
	private function build_forms_where( $view, $status_filter, $search_term ) {
		global $wpdb;

		$where  = array();
		$params = array();

		if ( 'trash' === $view ) {
			$where[]  = 'f.status = %s';
			$params[] = 'trash';
		} else {
			$where[]  = 'f.status != %s';
			$params[] = 'trash';

			// Only two non-trash statuses exist ('publish'/'draft'), so "inactive" maps
			// directly to 'draft' rather than a PHP-side "anything but published" check.
			if ( 'active' === $status_filter ) {
				$where[]  = 'f.status = %s';
				$params[] = 'publish';
			} elseif ( 'inactive' === $status_filter ) {
				$where[]  = 'f.status = %s';
				$params[] = 'draft';
			}
		}

		if ( '' !== $search_term ) {
			$where[]  = 'f.title LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search_term ) . '%';
		}

		return array( $where, $params );
	}

	/**
	 * Resolves an allowlisted orderby/order pair to an ORDER BY fragment for the
	 * forms list query. entry_count is a SELECT alias (from the entries JOIN in
	 * get_forms()), which MySQL allows ordering by directly.
	 *
	 * @param string $orderby One of 'title', 'entries', 'updated', or '' for the default.
	 * @param string $order   'asc' or 'desc'.
	 * @return string
	 */
	private function forms_order_sql( $orderby, $order ) {
		$dir = ( 'asc' === $order ) ? 'ASC' : 'DESC';

		switch ( $orderby ) {
			case 'title':
				return 'f.title ' . $dir . ', f.id DESC';
			case 'entries':
				return 'entry_count ' . $dir . ', f.id DESC';
			case 'updated':
				return 'f.updated_at ' . $dir . ', f.id DESC';
			default:
				return 'f.id DESC';
		}
	}

	/**
	 * Echoes a sortable column header (<th>) for the forms list, mirroring the
	 * native WordPress list-table behaviour: a link that toggles asc/desc with
	 * the active direction reflected in the markup (and aria-sort).
	 *
	 * @param string $key             Sort key ('title', 'entries', 'updated').
	 * @param string $col_class       The column's CSS class (e.g. 'shapeforms-col-title').
	 * @param string $label           Visible header label.
	 * @param string $current_orderby The active orderby, if any.
	 * @param string $current_order   The active order ('asc'|'desc').
	 * @param string $base_url        Page URL to build the sort link from.
	 * @return void
	 */
	private function render_sortable_th( $key, $col_class, $label, $current_orderby, $current_order, $base_url ) {
		$is_sorted = ( $current_orderby === $key );

		// Title reads naturally A→Z first; counts/dates are more useful high→low first.
		$default_dir = ( 'title' === $key ) ? 'asc' : 'desc';
		$next_order  = $is_sorted ? ( 'asc' === $current_order ? 'desc' : 'asc' ) : $default_dir;

		$th_class = $col_class . ' shapeforms-sortable';
		$aria     = 'none';
		if ( $is_sorted ) {
			$th_class .= ' is-sorted is-sorted-' . ( 'asc' === $current_order ? 'asc' : 'desc' );
			$aria      = ( 'asc' === $current_order ) ? 'ascending' : 'descending';
		}

		$url = add_query_arg(
			array(
				'orderby' => $key,
				'order'   => $next_order,
			),
			$base_url
		);

		printf(
			'<th class="%1$s" aria-sort="%2$s"><a href="%3$s" class="shapeforms-sort-link"><span class="shapeforms-sort-label">%4$s</span><span class="shapeforms-sort-indicators" aria-hidden="true"><span class="shapeforms-sort-indicator shapeforms-sort-asc"></span><span class="shapeforms-sort-indicator shapeforms-sort-desc"></span></span></a></th>',
			esc_attr( $th_class ),
			esc_attr( $aria ),
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/**
	 * Returns the count of forms for a given status.
	 *
	 * @param string $status Optional status to count. Empty for non-trashed.
	 * @return int
	 */
	private function get_forms_count( $status = '' ) {
		global $wpdb;

		$table_name = $this->plugin->get_forms_table_name();

		$safe_table = esc_sql( $table_name );

		if ( 'trash' === $status ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$safe_table}` WHERE status = %s", 'trash' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$safe_table}` WHERE status != %s", 'trash' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Builds a secure action URL for a form.
	 *
	 * @param string $action  Action name.
	 * @param int    $form_id Form ID.
	 * @return string
	 */
	private function get_form_action_url( $action, $form_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'page'            => 'shapeforms',
					'shapeforms_action' => $action,
					'form_id'         => $form_id,
				),
				admin_url( 'admin.php' )
			),
			'shapeforms_lite_' . $action . '_form_' . $form_id
		);
	}

	/**
	 * Returns the forms list URL with an optional notice.
	 *
	 * @param string $notice      Notice key.
	 * @param string $form_status Optional status view ('trash').
	 * @return string
	 */
	private function get_forms_page_url( $notice = '', $form_status = '' ) {
		$args = array(
			'page' => 'shapeforms',
		);

		if ( '' !== $notice ) {
			$args['shapeforms_notice'] = $notice;
		}

		if ( 'trash' === $form_status ) {
			$args['form_status'] = 'trash';
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Renders list-page notices for completed actions.
	 *
	 * @param string $notice Notice key.
	 * @return void
	 */
	private function render_admin_notice( $notice ) {
		$messages = array(
			'trashed'          => array( 'success', __( 'Form moved to trash.', 'shapeforms' ) ),
			'bulk_trashed'     => array( 'success', __( 'Selected forms moved to trash.', 'shapeforms' ) ),
			'restored'         => array( 'success', __( 'Form restored.', 'shapeforms' ) ),
			'bulk_restored'    => array( 'success', __( 'Selected forms restored.', 'shapeforms' ) ),
			'deleted'          => array( 'success', __( 'Form permanently deleted.', 'shapeforms' ) ),
			'bulk_deleted'     => array( 'success', __( 'Selected forms permanently deleted.', 'shapeforms' ) ),
			'delete_failed'    => array( 'error', __( 'Unable to delete the form.', 'shapeforms' ) ),
			'duplicate_failed' => array( 'error', __( 'Unable to duplicate the form.', 'shapeforms' ) ),
			'invalid_nonce'    => array( 'error', __( 'Security check failed.', 'shapeforms' ) ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		list( $type, $message ) = $messages[ $notice ];
		$icon = ( 'error' === $type ) ? 'dashicons-warning' : 'dashicons-yes-alt';
		// Keep `notice is-dismissible` so WordPress still wires up the dismiss (×)
		// button; the shapeforms-inline-notice classes restyle it into a modern alert.
		// NB: a distinct namespace from the full-width promo card (.shapeforms-admin-notice
		// in admin-notice.css) — they must not share a class or the card's layout breaks.
		?>
		<div class="notice inline is-dismissible shapeforms-inline-notice shapeforms-inline-notice--<?php echo esc_attr( $type ); ?>">
			<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	/**
	 * Creates a duplicate of an existing form.
	 *
	 * @param int $form_id Source form ID.
	 * @return int
	 */
	private function duplicate_form( $form_id ) {
		global $wpdb;

		$form = $this->get_form( $form_id );

		if ( ! $form ) {
			return 0;
		}

		$table_name = $this->plugin->get_forms_table_name();
		$title      = sprintf(
			/* translators: %s: original form title */
			__( '%s (Copy)', 'shapeforms' ),
			(string) $form->title
		);

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table_name,
			array(
				'title'         => $title,
				'status'        => (string) $form->status,
				'fields_json'   => (string) $form->fields_json,
				'settings_json' => (string) $form->settings_json,
				'created_by'    => get_current_user_id(),
			),
			array( '%s', '%s', '%s', '%s', '%d' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Moves a form to trash by setting its status.
	 *
	 * @param int $form_id Form ID.
	 * @return bool
	 */
	private function trash_form( $form_id ) {
		global $wpdb;

		$table_name = $this->plugin->get_forms_table_name();
		$updated    = $wpdb->update( $table_name, array( 'status' => 'trash' ), array( 'id' => $form_id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return false !== $updated && $updated > 0;
	}

	/**
	 * Restores a form from trash.
	 *
	 * @param int $form_id Form ID.
	 * @return bool
	 */
	private function restore_form( $form_id ) {
		global $wpdb;

		$table_name = $this->plugin->get_forms_table_name();
		$updated    = $wpdb->update( $table_name, array( 'status' => 'publish' ), array( 'id' => $form_id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return false !== $updated && $updated > 0;
	}

	/**
	 * Permanently deletes a form and its entries.
	 *
	 * @param int $form_id Form ID.
	 * @return bool
	 */
	private function delete_form( $form_id ) {
		global $wpdb;

		$forms_table   = $this->plugin->get_forms_table_name();
		$entries_table = $this->plugin->get_entries_table_name();

		$wpdb->delete( $entries_table, array( 'form_id' => $form_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete( $forms_table, array( 'id' => $form_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->clear_unread_count_cache();

		return false !== $deleted && $deleted > 0;
	}

	/**
	 * Returns a single form.
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
	 * Returns paginated entries.
	 *
	 * @param int $page     Current page.
	 * @param int $per_page Items per page.
	 * @return array<int, object>
	 */
	private function get_entries( $page, $per_page, $filters = array() ) {
		global $wpdb;

		$table_name = $this->plugin->get_entries_table_name();
		$offset     = max( 0, ( $page - 1 ) * $per_page );
		$safe_table = esc_sql( $table_name );
		$where      = $this->build_entries_where( $filters ); // Each clause is individually prepared via $wpdb->prepare().

		$columns = 'id, form_id, entry_data_json, status, trashed_at, user_ip, created_at';

		/**
		 * Filter extra columns selected for each entries-list row, so an add-on can
		 * read its own column (e.g. an approval status) on the row object without an
		 * extra query per row. Values must be plain identifier names of columns that
		 * exist on the entries table; each is passed through esc_sql().
		 *
		 * @since 1.1.3
		 *
		 * @param string[] $extra_columns Extra column names (default empty).
		 */
		$extra_columns = (array) apply_filters( 'shapeforms_entries_list_columns', array() );
		foreach ( $extra_columns as $extra_column ) {
			if ( is_string( $extra_column ) && '' !== $extra_column ) {
				$columns .= ', ' . esc_sql( $extra_column );
			}
		}

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->prepare(
				"SELECT {$columns} FROM `{$safe_table}` {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$per_page,
				$offset
			)
		);
	}

	/**
	 * Renders a single entry detail view.
	 *
	 * @param int $entry_id Entry ID.
	 * @return void
	 */
	private function render_entry_detail( $entry_id ) {
		$entry = $this->get_entry( $entry_id );

		$this->render_admin_topbar( 'shapeforms-lite-entries' );

		if ( ! $entry ) {
			?>
			<div class="wrap">
				<hr class="wp-header-end"><?php // Keep relocated notices above the header (see Forms list for rationale). ?>
				<div class="shapeforms-page-header"><h1><?php esc_html_e( 'Entry Not Found', 'shapeforms' ); ?></h1></div>
				<p><?php esc_html_e( 'The requested entry does not exist.', 'shapeforms' ); ?></p>
			</div>
			<?php
			return;
		}

		// Auto-mark as read when viewed — this also applies to a trashed entry (it stays
		// in the Trash, only `status` changes) so an opened submission never lingers as
		// "Unread". Restore then returns it to `read`, which is correct: it has been read.
		if ( 'unread' === $entry->status ) {
			global $wpdb;
			$wpdb->update( $this->plugin->get_entries_table_name(), array( 'status' => 'read' ), array( 'id' => $entry_id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$entry->status = 'read';
			$this->clear_unread_count_cache();
		}

		$decoded      = json_decode( (string) $entry->entry_data_json, true );
		$form         = $this->get_form( (int) $entry->form_id );
		$form_title   = $form ? ( $form->title ? (string) $form->title : '#' . absint( $form->id ) ) : '';
		$entry_status = (string) $entry->status;
		$is_trashed   = ! empty( $entry->trashed_at );
		$delete_url   = wp_nonce_url(
			admin_url( 'admin.php?page=shapeforms-lite-entries&shapeforms_delete_entry=' . absint( $entry->id ) ),
			'shapeforms_lite_delete_entry_' . absint( $entry->id )
		);
		$trash_url    = wp_nonce_url(
			admin_url( 'admin.php?page=shapeforms-lite-entries&shapeforms_trash_entry=' . absint( $entry->id ) ),
			'shapeforms_lite_trash_entry_' . absint( $entry->id )
		);
		$restore_url  = wp_nonce_url(
			admin_url( 'admin.php?page=shapeforms-lite-entries&shapeforms_restore_entry=' . absint( $entry->id ) ),
			'shapeforms_lite_restore_entry_' . absint( $entry->id )
		);
		?>
		<div class="wrap shapeforms-entry-detail-wrap">
			<hr class="wp-header-end"><?php // Keep relocated notices above the header (see Forms list for rationale). ?>
			<!-- Header bar -->
			<div class="shapeforms-entry-header">
				<div class="shapeforms-entry-header__left">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=shapeforms-lite-entries' ) ); ?>" class="shapeforms-entry-header__back" title="<?php esc_attr_e( 'Back to Entries', 'shapeforms' ); ?>">
						<span class="dashicons dashicons-arrow-left-alt2"></span>
					</a>
					<div class="shapeforms-entry-header__title">
						<h1><?php /* translators: %d: entry ID */ printf( esc_html__( 'Entry #%d', 'shapeforms' ), absint( $entry->id ) ); ?></h1>
						<?php if ( $form_title ) : ?>
							<span class="shapeforms-entry-header__form"><?php echo esc_html( $form_title ); ?></span>
						<?php endif; ?>
					</div>
				</div>
				<div class="shapeforms-entry-header__right">
					<span class="shapeforms-status-badge shapeforms-status--<?php echo esc_attr( $entry_status ); ?>" id="shapeforms-detail-status"><?php echo esc_html( ucfirst( $entry_status ) ); ?></span>
					<?php if ( $is_trashed ) : ?>
						<?php // Trashed entry: only restore or permanently delete — the status marks don't apply here. ?>
						<a href="<?php echo esc_url( $restore_url ); ?>" class="shapeforms-entry-action-btn" title="<?php esc_attr_e( 'Restore', 'shapeforms' ); ?>">
							<span class="dashicons dashicons-undo"></span>
						</a>
						<a href="<?php echo esc_url( $delete_url ); ?>" class="shapeforms-entry-action-btn shapeforms-entry-action-btn--danger" title="<?php esc_attr_e( 'Delete Permanently', 'shapeforms' ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this entry permanently? This cannot be undone.', 'shapeforms' ) ); ?>');">
							<span class="dashicons dashicons-trash"></span>
						</a>
					<?php else : ?>
						<button type="button" class="shapeforms-entry-action-btn" id="shapeforms-mark-starred" title="<?php echo 'starred' === $entry_status ? esc_attr__( 'Remove Star', 'shapeforms' ) : esc_attr__( 'Star Entry', 'shapeforms' ); ?>">
							<span class="dashicons <?php echo 'starred' === $entry_status ? 'dashicons-star-filled' : 'dashicons-star-empty'; ?>"></span>
						</button>
						<button type="button" class="shapeforms-entry-action-btn" id="shapeforms-mark-unread" title="<?php esc_attr_e( 'Mark as Unread', 'shapeforms' ); ?>" <?php echo 'unread' === $entry_status ? 'disabled' : ''; ?>>
							<span class="dashicons dashicons-email"></span>
						</button>
						<button type="button" class="shapeforms-entry-action-btn<?php echo 'spam' === $entry_status ? ' is-spam' : ''; ?>" id="shapeforms-mark-spam" title="<?php echo 'spam' === $entry_status ? esc_attr__( 'Not Spam', 'shapeforms' ) : esc_attr__( 'Mark as Spam', 'shapeforms' ); ?>">
							<span class="dashicons dashicons-shield"></span>
						</button>
						<a href="<?php echo esc_url( $trash_url ); ?>" class="shapeforms-entry-action-btn shapeforms-entry-action-btn--danger" title="<?php esc_attr_e( 'Move to Trash', 'shapeforms' ); ?>">
							<span class="dashicons dashicons-trash"></span>
						</a>
					<?php endif; ?>
				</div>
			</div>

			<div class="shapeforms-entry-layout">
				<!-- Main: Submitted Data -->
				<div class="shapeforms-entry-main">
					<?php if ( is_array( $decoded ) && ! empty( $decoded ) ) : ?>
						<div class="shapeforms-entry-data">
							<?php foreach ( $decoded as $idx => $field ) : ?>
								<?php
								$label   = isset( $field['label'] ) && '' !== $field['label'] ? (string) $field['label'] : __( 'Field', 'shapeforms' );
								$type    = isset( $field['type'] ) ? (string) $field['type'] : '';
								$value   = isset( $field['value'] ) ? $field['value'] : '';
								$is_file = 'file' === $type;

								// Present each value human-readably by field type via the shared helper.
								$value = ShapeForms_Lite::format_field_value( $value, $type );

								if ( 'country' === $type && '' !== $value ) {
									// Show the country name instead of the raw ISO code.
									$countries = ShapeForms_Lite_Shortcode::get_country_list();
									$value     = isset( $countries[ $value ] ) ? $countries[ $value ] : $value;
								} elseif ( 'terms_conditions' === $type ) {
									// Stored as "1" when agreed — show a readable label.
									$value = ( '' !== $value && '0' !== $value ) ? __( 'Accepted', 'shapeforms' ) : __( 'Not accepted', 'shapeforms' );
								}
								?>
								<div class="shapeforms-entry-field">
									<div class="shapeforms-entry-field__label"><?php echo esc_html( $label ); ?></div>
									<div class="shapeforms-entry-field__value">
										<?php
											// Let an extension render rich HTML for this value on the admin
											// surface only (e.g. a signature image). Default '' falls through
											// to the file link / escaped text below, so CSV, email and privacy
											// export (plain-text shapeforms_format_field_value) stay unaffected.
											$value_html = apply_filters( 'shapeforms_entry_value_admin_html', '', isset( $field['value'] ) ? $field['value'] : '', $type, $field );
											if ( '' !== (string) $value_html ) :
												echo wp_kses(
													(string) $value_html,
													array(
														'img'  => array( 'src' => true, 'alt' => true, 'class' => true, 'style' => true, 'width' => true, 'height' => true ),
														'a'    => array( 'href' => true, 'class' => true, 'download' => true, 'target' => true, 'rel' => true, 'style' => true ),
														'span' => array( 'class' => true ),
													),
													array( 'http', 'https', 'data' )
												);
											elseif ( $is_file && ! empty( $value ) ) : ?>
											<a href="<?php echo esc_url( (string) $value ); ?>" target="_blank" class="shapeforms-entry-file-link">
												<span class="dashicons dashicons-media-default"></span>
												<?php echo esc_html( basename( (string) $value ) ); ?>
											</a>
										<?php elseif ( '' !== (string) $value ) : ?>
											<?php echo nl2br( esc_html( (string) $value ) ); ?>
										<?php else : ?>
											<span class="shapeforms-entry-field__empty">&mdash;</span>
										<?php endif; ?>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<?php
					/**
					 * Fires in the entry-detail main column, after the read-only submitted-data list.
					 *
					 * Lets an add-on render an inline editor for the submitted values (or any other
					 * main-column UI). Receives the entry row object and the decoded entry-data map
					 * ( $field_id => array{ label, type, value, path? } ) so it need not re-query.
					 *
					 * @since 1.1.3
					 *
					 * @param object                             $entry   The entry row object.
					 * @param array<string, array<string, mixed>> $decoded Decoded entry_data_json map.
					 */
					do_action( 'shapeforms_entry_detail_after_data', $entry, is_array( $decoded ) ? $decoded : array() );
					?>
				</div>

				<!-- Sidebar: Meta -->
				<div class="shapeforms-entry-sidebar">
					<div class="shapeforms-entry-meta-card">
						<h3><span class="dashicons dashicons-info-outline"></span> <?php esc_html_e( 'Details', 'shapeforms' ); ?></h3>
						<div class="shapeforms-entry-meta-list">
							<div class="shapeforms-entry-meta-item">
								<span class="shapeforms-entry-meta-item__icon dashicons dashicons-calendar-alt"></span>
								<div>
									<span class="shapeforms-entry-meta-item__label"><?php esc_html_e( 'Submitted', 'shapeforms' ); ?></span>
									<span class="shapeforms-entry-meta-item__value"><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( (string) $entry->created_at ) ) ); ?></span>
								</div>
							</div>
							<?php if ( $form_title ) : ?>
								<div class="shapeforms-entry-meta-item">
									<?php shapeforms_lite_brand_icon( array( 'class' => 'shapeforms-entry-meta-item__icon dashicons shapeforms-brand-icon' ) ); ?>
									<div>
										<span class="shapeforms-entry-meta-item__label"><?php esc_html_e( 'Form', 'shapeforms' ); ?></span>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=shapeforms-lite-builder&form_id=' . absint( $entry->form_id ) ) ); ?>" class="shapeforms-entry-meta-item__value shapeforms-entry-meta-item__link"><?php echo esc_html( $form_title ); ?></a>
									</div>
								</div>
							<?php endif; ?>
							<?php if ( ! empty( $entry->user_ip ) ) : ?>
								<div class="shapeforms-entry-meta-item">
									<span class="shapeforms-entry-meta-item__icon dashicons dashicons-admin-site-alt3"></span>
									<div>
										<span class="shapeforms-entry-meta-item__label"><?php esc_html_e( 'IP Address', 'shapeforms' ); ?></span>
										<span class="shapeforms-entry-meta-item__value"><?php echo esc_html( (string) $entry->user_ip ); ?></span>
									</div>
								</div>
							<?php endif; ?>
							<?php if ( ! empty( $entry->user_agent ) ) : ?>
								<div class="shapeforms-entry-meta-item">
									<span class="shapeforms-entry-meta-item__icon dashicons dashicons-desktop"></span>
									<div>
										<span class="shapeforms-entry-meta-item__label"><?php esc_html_e( 'Browser', 'shapeforms' ); ?></span>
										<span class="shapeforms-entry-meta-item__value shapeforms-entry-meta-item__ua"><?php echo esc_html( (string) $entry->user_agent ); ?></span>
									</div>
								</div>
							<?php endif; ?>
						</div>
					</div>

					<?php
					/**
					 * Fires inside the entry-detail sidebar, after the Details card.
					 *
					 * Lets an add-on append its own sidebar card to the entry-detail view — e.g.
					 * a private admin-notes panel. Receives the full entry row object.
					 *
					 * @since 1.1.3
					 *
					 * @param object $entry The entry row object (id, form_id, status, created_at, …).
					 */
					do_action( 'shapeforms_entry_detail_sidebar', $entry );
					?>
				</div>
			</div>

		</div>
		<?php
	}

	/**
	 * Returns a single entry by ID.
	 *
	 * @param int $entry_id Entry ID.
	 * @return object|null
	 */
	private function get_entry( $entry_id ) {
		global $wpdb;

		$safe_table = esc_sql( $this->plugin->get_entries_table_name() );

		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM `{$safe_table}` WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$entry_id
			)
		);
	}

	/**
	 * Toggles a form's publish/draft status via AJAX.
	 *
	 * @return void
	 */
	public function ajax_toggle_form_status() {
		check_ajax_referer( 'shapeforms_lite_form_status' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'shapeforms' ) ), 403 );
		}

		$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		$status  = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';

		if ( ! $form_id || ! in_array( $status, array( 'publish', 'draft' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'shapeforms' ) ) );
		}

		global $wpdb;

		$wpdb->update( $this->plugin->get_forms_table_name(), array( 'status' => $status ), array( 'id' => $form_id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		wp_send_json_success( array( 'status' => $status ) );
	}

	/**
	 * Updates an entry's read/unread/starred/spam status via AJAX.
	 *
	 * @return void
	 */
	public function ajax_update_entry_status() {
		check_ajax_referer( 'shapeforms_lite_entry_status' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'shapeforms' ) ), 403 );
		}

		$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
		$status   = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';

		if ( ! $entry_id || ! in_array( $status, array( 'unread', 'read', 'starred', 'spam' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'shapeforms' ) ) );
		}

		global $wpdb;

		$table = $this->plugin->get_entries_table_name();

		$wpdb->update( $table, array( 'status' => $status ), array( 'id' => $entry_id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->clear_unread_count_cache();

		wp_send_json_success( array( 'status' => $status ) );
	}

	/**
	 * Handles a bulk action on the Entries list (status change or delete).
	 *
	 * Mirrors the security of the single-entry handler: same nonce, the same
	 * `manage_options` gate, ids cast to ints. Status changes and deletes run as a
	 * single prepared `IN (...)` query rather than a per-row loop.
	 *
	 * @return void
	 */
	public function ajax_bulk_entry_action() {
		check_ajax_referer( 'shapeforms_lite_entry_status' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'shapeforms' ) ), 403 );
		}

		$action  = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		// Each element is cast with absint() on the next line, so the raw array is safe.
		$raw_ids = isset( $_POST['entry_ids'] ) && is_array( $_POST['entry_ids'] ) ? wp_unslash( $_POST['entry_ids'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		// Cast to a unique list of positive ints; drop anything else.
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $raw_ids ) ) ) );

		/**
		 * Fires in the bulk entry-action handler after the ids are resolved, so an
		 * add-on can perform a custom bulk action it registered in the dropdown
		 * (e.g. Approve / Reject). The request nonce and the manage_options
		 * capability are already verified above. A handler that claims $action MUST
		 * send its own JSON response (which ends the request); if none does, Lite
		 * proceeds with its built-in actions and rejects an unknown action.
		 *
		 * @since 1.1.3
		 *
		 * @param string $action The requested bulk action.
		 * @param int[]  $ids    Resolved entry ids.
		 */
		do_action( 'shapeforms_bulk_entry_action', $action, $ids );

		// Status marks + Trash lifecycle. "trash" moves entries to the Trash view;
		// "restore" brings them back (to read — the entry has already been seen);
		// "delete" removes them permanently. Read/unread/starred/spam are plain marks.
		$is_status = in_array( $action, array( 'unread', 'read', 'starred', 'spam' ), true );
		$is_move   = in_array( $action, array( 'trash', 'restore' ), true );

		if ( empty( $ids ) || ( ! $is_status && ! $is_move && 'delete' !== $action ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'shapeforms' ) ) );
		}

		global $wpdb;

		$safe_table   = esc_sql( $this->plugin->get_entries_table_name() );
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// $safe_table is esc_sql()'d above; $placeholders is a generated run of %d
		// placeholders bound by $wpdb->prepare(), so the query is fully prepared. The
		// sniffs below can't see the dynamic placeholders and are safely ignored.
		if ( 'delete' === $action ) {
			$sql = $wpdb->prepare( "DELETE FROM `{$safe_table}` WHERE id IN ( {$placeholders} )", $ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		} elseif ( 'trash' === $action ) {
			// Set the trash timestamp; leave `status` untouched so restore recovers it.
			$params = array_merge( array( current_time( 'mysql' ) ), $ids );
			$sql = $wpdb->prepare( "UPDATE `{$safe_table}` SET trashed_at = %s WHERE id IN ( {$placeholders} )", $params ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		} elseif ( 'restore' === $action ) {
			// Clear the trash timestamp; the preserved `status` is the restored state.
			$sql = $wpdb->prepare( "UPDATE `{$safe_table}` SET trashed_at = NULL WHERE id IN ( {$placeholders} )", $ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		} else {
			$params = array_merge( array( $action ), $ids );
			$sql = $wpdb->prepare( "UPDATE `{$safe_table}` SET status = %s WHERE id IN ( {$placeholders} )", $params ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		$affected = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->clear_unread_count_cache();

		wp_send_json_success(
			array(
				'action'   => $action,
				'affected' => (int) $affected,
			)
		);
	}

	/**
	 * Handles entry delete action.
	 *
	 * @return void
	 */
	private function maybe_delete_entry() {
		if ( empty( $_GET['shapeforms_delete_entry'] ) ) {
			return;
		}

		$entry_id = absint( $_GET['shapeforms_delete_entry'] );

		check_admin_referer( 'shapeforms_lite_delete_entry_' . $entry_id );

		if ( ! current_user_can( 'manage_options' ) || ! $entry_id ) {
			return;
		}

		global $wpdb;

		$table = $this->plugin->get_entries_table_name();

		$wpdb->delete( $table, array( 'id' => $entry_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->clear_unread_count_cache();

		wp_safe_redirect( admin_url( 'admin.php?page=shapeforms-lite-entries&shapeforms_notice=entry_deleted' ) );
		exit;
	}

	/**
	 * Moves a single entry to the Trash, or restores it, from the entry-detail screen.
	 *
	 * Two signed single-row actions: `shapeforms_trash_entry` sets the status to `trash`
	 * (the entry moves to the Trash tab, recoverable), and `shapeforms_restore_entry`
	 * sets it back to `read`. Permanent deletion stays in maybe_delete_entry().
	 *
	 * @return void
	 */
	private function maybe_trash_or_restore_entry() {
		$is_trash   = ! empty( $_GET['shapeforms_trash_entry'] );
		$is_restore = ! empty( $_GET['shapeforms_restore_entry'] );

		if ( ! $is_trash && ! $is_restore ) {
			return;
		}

		$entry_id = $is_trash ? absint( $_GET['shapeforms_trash_entry'] ) : absint( $_GET['shapeforms_restore_entry'] );

		check_admin_referer( ( $is_trash ? 'shapeforms_lite_trash_entry_' : 'shapeforms_lite_restore_entry_' ) . $entry_id );

		if ( ! current_user_can( 'manage_options' ) || ! $entry_id ) {
			return;
		}

		global $wpdb;

		// Trash stamps the timestamp; restore clears it. `status` is never touched, so a
		// restored entry returns to exactly the read-state it had before being trashed.
		// $wpdb->update() writes `trashed_at = NULL` for a null value (the format is
		// ignored in that case), so restore correctly clears the column.
		$data = array( 'trashed_at' => $is_trash ? current_time( 'mysql' ) : null );
		$wpdb->update( $this->plugin->get_entries_table_name(), $data, array( 'id' => $entry_id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->clear_unread_count_cache();

		// Trashing returns to the list (the entry is no longer in a normal view); restoring
		// reopens the entry so the admin can keep working with it.
		$redirect = $is_trash
			? admin_url( 'admin.php?page=shapeforms-lite-entries&shapeforms_notice=entry_trashed' )
			: admin_url( 'admin.php?page=shapeforms-lite-entries&entry_id=' . $entry_id );

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Exports entries as a CSV download.
	 *
	 * @return void
	 */
	private function maybe_export_csv() {
		// Fires for the header "Export CSV" link (GET, filter-scoped) and the bulk-bar
		// "Export Selected" button (POST with entry_ids). Runs on admin_init (before any
		// output) so streaming the download is safe.
		if ( empty( $_GET['shapeforms_export_csv'] ) && empty( $_POST['shapeforms_export_csv'] ) ) {
			return;
		}

		check_admin_referer( 'shapeforms_lite_csv_export' );

		$filters = $this->get_entries_export_filters();

		$filename = 'shapeforms-entries-' . gmdate( 'Y-m-d' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$output = fopen( 'php://output', 'w' );

		// Delegate the query, column-union and value formatting to the shared exporter so
		// this CSV path and any add-on export format can never drift apart.
		// The closures are non-static so they keep this class's scope and can reach the
		// private csv_escape_cell() (formula-injection guard) exactly as before.
		$this->stream_entries_export(
			$filters,
			function ( $columns ) use ( $output ) {
				fputcsv( $output, array_map( array( $this, 'csv_escape_cell' ), array_merge( array( 'Entry ID', 'Form ID', 'Date' ), $columns ) ) );
			},
			function ( $row ) use ( $output ) {
				fputcsv( $output, array_map( array( $this, 'csv_escape_cell' ), $row ) );
			}
		);

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- writing to php://output stream, not a filesystem file.
		exit;
	}

	/**
	 * Resolves the entries-export filter set from the current request.
	 *
	 * A selected-rows export (the bulk bar POSTs `entry_ids`) takes precedence and
	 * exports exactly those ids, ignoring the status/date tab filters. Otherwise the
	 * screen filters passed in the header export link (form/status/date) are honored.
	 * Both the built-in CSV export and add-on exporters (such as Excel/PDF)
	 * call this so every format scopes an export identically. The caller is
	 * responsible for the nonce/capability check before invoking an export.
	 *
	 * @since 1.1.2
	 *
	 * @return array<string, mixed> Filter set for {@see self::stream_entries_export()}.
	 */
	public function get_entries_export_filters() {
		$selected_ids = isset( $_POST['entry_ids'] ) && is_array( $_POST['entry_ids'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- caller verifies the nonce before dispatch.
			? array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $_POST['entry_ids'] ) ) ) ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: array();

		if ( ! empty( $selected_ids ) ) {
			return array( 'ids' => $selected_ids );
		}

		// Honor the same filters the Entries screen passes in the export link (form/status/date).
		return array(
			'form_id'   => isset( $_GET['form_id'] ) ? absint( wp_unslash( $_GET['form_id'] ) ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'status'    => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'date_from' => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'date_to'   => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
	}

	/**
	 * Streams the entries export dataset to caller-supplied callbacks, batched to
	 * bound memory.
	 *
	 * This is the single source of truth for every entry export format: Lite's
	 * built-in CSV export and any add-on format (for example an Excel
	 * or PDF exporter) all iterate the same query, share the same column set — the
	 * union of field labels across the filtered entries, in first-seen order — and
	 * run every value through {@see ShapeForms_Lite::format_field_value()}, so no two
	 * export formats can ever drift apart. Rows are read in bounded batches so peak
	 * memory stays flat no matter how many entries match.
	 *
	 * The callbacks receive already-assembled data; the caller only serializes it
	 * for its target file type (a CSV cell, an XLSX row, a PDF table cell). No output
	 * is produced by this method itself.
	 *
	 * @since 1.1.2
	 *
	 * @param array<string, mixed> $filters    Query filters. Accepts 'ids' (int[],
	 *                                          exact entry ids — takes precedence) or
	 *                                          'form_id', 'status', 'date_from',
	 *                                          'date_to' (screen filters).
	 * @param callable             $on_columns Called once, after the column set is
	 *                                          known: fn( string[] $columns ): void.
	 * @param callable             $on_row     Called once per entry, in display order:
	 *                                          fn( array $row, array $meta ): void.
	 *                                          $row is flat: [ id, form_id, created_at,
	 *                                          <one cell per column> ]. $meta carries
	 *                                          'id', 'form_id', 'created_at'.
	 * @return void
	 */
	public function stream_entries_export( array $filters, callable $on_columns, callable $on_row ) {
		global $wpdb;

		$safe_table = esc_sql( $this->plugin->get_entries_table_name() );
		$where      = $this->build_entries_where( $filters ); // Each clause is individually prepared via $wpdb->prepare().
		$base_query = "SELECT id, form_id, created_at, entry_data_json FROM `{$safe_table}` {$where} ORDER BY created_at DESC";
		$batch_size = 500;

		// Pass 1: collect the union of field labels (column headers) in bounded batches,
		// so memory stays flat regardless of how many entries exist.
		$columns = array();
		$offset  = 0;
		do {
			$limit_sql = $wpdb->prepare( ' LIMIT %d OFFSET %d', $batch_size, $offset );
			$batch     = $wpdb->get_results( $base_query . $limit_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

			// A DB error makes get_results() return null (not an empty array); bail cleanly
			// so the count() loop condition below never receives null, which is a fatal
			// TypeError on PHP 8.0+. A successful empty result is array() and ends the loop.
			if ( ! is_array( $batch ) ) {
				break;
			}

			foreach ( $batch as $entry ) {
				$data = json_decode( $entry['entry_data_json'], true );

				if ( ! is_array( $data ) ) {
					continue;
				}

				foreach ( $data as $field ) {
					$label = isset( $field['label'] ) && '' !== $field['label'] ? (string) $field['label'] : 'Field';

					if ( ! in_array( $label, $columns, true ) ) {
						$columns[] = $label;
					}
				}
			}

			$offset += $batch_size;
		} while ( count( $batch ) === $batch_size );

		call_user_func( $on_columns, $columns );

		// Pass 2: stream the rows in the same order, batched to bound memory.
		$offset = 0;
		do {
			$limit_sql = $wpdb->prepare( ' LIMIT %d OFFSET %d', $batch_size, $offset );
			$batch     = $wpdb->get_results( $base_query . $limit_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

			// See pass 1: guard against a null (DB-error) result before the count() loop
			// condition, which would otherwise fatal on PHP 8.0+.
			if ( ! is_array( $batch ) ) {
				break;
			}

			foreach ( $batch as $entry ) {
				$data = json_decode( $entry['entry_data_json'], true );
				$row  = array(
					$entry['id'],
					$entry['form_id'],
					$entry['created_at'],
				);

				$field_map = array();

				if ( is_array( $data ) ) {
					foreach ( $data as $field ) {
						$label = isset( $field['label'] ) && '' !== $field['label'] ? (string) $field['label'] : 'Field';
						$value = isset( $field['value'] ) ? $field['value'] : '';
						$type  = isset( $field['type'] ) ? (string) $field['type'] : '';

						$field_map[ $label ] = ShapeForms_Lite::format_field_value( $value, $type );
					}
				}

				foreach ( $columns as $col ) {
					$row[] = isset( $field_map[ $col ] ) ? $field_map[ $col ] : '';
				}

				call_user_func(
					$on_row,
					$row,
					array(
						'id'         => $entry['id'],
						'form_id'    => $entry['form_id'],
						'created_at' => $entry['created_at'],
					)
				);
			}

			$offset += $batch_size;
		} while ( count( $batch ) === $batch_size );
	}

	/**
	 * Neutralizes CSV/spreadsheet formula injection in a single cell.
	 *
	 * Prefixes a leading =, +, -, @, tab or CR with a single quote so spreadsheet
	 * apps treat attacker-supplied submission values as text, not formulas.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	private function csv_escape_cell( $value ) {
		$value = (string) $value;

		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			$value = "'" . $value;
		}

		return $value;
	}

	/**
	 * Returns total entries count.
	 *
	 * @return int
	 */
	private function get_entries_count( $filters = array() ) {
		global $wpdb;

		$table_name = $this->plugin->get_entries_table_name();
		$safe_table = esc_sql( $table_name );
		$where      = $this->build_entries_where( $filters ); // Each clause is individually prepared via $wpdb->prepare().

		return (int) $wpdb->get_var( "SELECT COUNT(id) FROM `{$safe_table}` {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Builds WHERE clause for entries queries.
	 *
	 * @param array $filters Filter parameters.
	 * @return string
	 */
	private function build_entries_where( $filters ) {
		global $wpdb;

		$clauses = array();

		// Explicit id list (selected-rows export) — restrict to exactly these entries.
		if ( ! empty( $filters['ids'] ) && is_array( $filters['ids'] ) ) {
			$ids = array_values( array_unique( array_filter( array_map( 'absint', $filters['ids'] ) ) ) );
			if ( ! empty( $ids ) ) {
				$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
				// $placeholders is a generated run of %d placeholders bound by prepare().
				$clauses[] = $wpdb->prepare( "id IN ( {$placeholders} )", $ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			}
		}

		if ( ! empty( $filters['form_id'] ) ) {
			$clauses[] = $wpdb->prepare( 'form_id = %d', absint( $filters['form_id'] ) );
		}

		// Trash lifecycle vs. status view. Trash is a separate dimension (the nullable
		// `trashed_at` column), independent of the read-state in `status`, so restoring
		// an entry keeps its exact prior status. The "trash" view shows trashed entries;
		// every other view (including "All") excludes them, as do exports — unless an
		// explicit id list is supplied (selected-rows export), which is authoritative.
		if ( empty( $filters['ids'] ) ) {
			$status = ! empty( $filters['status'] ) ? sanitize_key( $filters['status'] ) : 'all';
			if ( 'trash' === $status ) {
				$clauses[] = 'trashed_at IS NOT NULL';
			} else {
				$clauses[] = 'trashed_at IS NULL';
				if ( 'all' !== $status ) {
					$clauses[] = $wpdb->prepare( 'status = %s', $status );
				}
			}
		}

		if ( ! empty( $filters['date_from'] ) ) {
			$clauses[] = $wpdb->prepare( 'created_at >= %s', sanitize_text_field( $filters['date_from'] ) . ' 00:00:00' );
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$clauses[] = $wpdb->prepare( 'created_at <= %s', sanitize_text_field( $filters['date_to'] ) . ' 23:59:59' );
		}

		/**
		 * Filter the WHERE conditions for entries list/count queries.
		 *
		 * Lets an add-on scope the entries list by a Pro-owned column (e.g. the
		 * Entry Approval status). Each clause in the returned array is joined with
		 * AND verbatim, so a callback MUST return only already-escaped or
		 * $wpdb->prepare()'d SQL fragments — never raw user input.
		 *
		 * @since 1.1.3
		 *
		 * @param string[]             $clauses Prepared WHERE fragments.
		 * @param array<string, mixed> $filters Active filters for this query.
		 */
		$clauses = (array) apply_filters( 'shapeforms_entries_where_clauses', $clauses, $filters );

		if ( empty( $clauses ) ) {
			return '';
		}

		return 'WHERE ' . implode( ' AND ', $clauses );
	}

	/**
	 * Normalizes a form record for the builder.
	 *
	 * @param object|null $form_record Form database record.
	 * @return array<string, mixed>
	 */
	private function normalize_form_for_builder( $form_record ) {
		// The builder expects one stable shape for both new forms and existing DB records.
		return array(
			'id'        => $form_record ? (int) $form_record->id : 0,
			'title'     => $form_record ? (string) $form_record->title : __( 'Untitled Form', 'shapeforms' ),
			'structure' => $this->extract_structure_from_record( $form_record ),
			'settings'  => $this->extract_settings_from_record( $form_record ),
		);
	}

	/**
	 * Extracts normalized form settings from a database record.
	 *
	 * @param object|null $form_record Form database record.
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
			'button_color'      => 'teal',
			'field_style'       => '',
			'field_size'        => '',
			'field_focus_color' => '',
			'field_border_width'=> '',
			'field_border_radius'=> '',
			'field_background_color' => '',
			'field_border_color' => '',
			'field_text_color'  => '',
			'label_size'        => '',
			'label_color'       => '',
			'label_subtext_color' => '',
			'error_color'       => '',
			'button_size'       => '',
			'button_border_style' => '',
			'button_border_width' => '',
			'button_border_radius' => '',
			'button_background_color' => '',
			'button_border_color' => '',
			'button_text_color' => '',
			'admin_email_type'  => 'site_admin',
			'enable_admin_email'=> true,
			'enable_user_email' => true,
			'admin_email'       => '',
		);

		if ( ! $form_record || empty( $form_record->settings_json ) ) {
			return $defaults;
		}

		$decoded = json_decode( (string) $form_record->settings_json, true );

		if ( ! is_array( $decoded ) ) {
			return $defaults;
		}

		$submission_type = isset( $decoded['submission_type'] ) && in_array( $decoded['submission_type'], array( 'ajax', 'redirect' ), true )
			? $decoded['submission_type']
			: ( ! empty( $decoded['enable_redirect'] ) ? 'redirect' : 'ajax' );
		$admin_email     = isset( $decoded['admin_email'] ) ? sanitize_email( (string) $decoded['admin_email'] ) : $defaults['admin_email'];
		$admin_email_type = isset( $decoded['admin_email_type'] ) && in_array( $decoded['admin_email_type'], array( 'site_admin', 'custom' ), true )
			? $decoded['admin_email_type']
			: ( $admin_email ? 'custom' : 'site_admin' );

		$settings = array(
			'submission_type'   => $submission_type,
			'enable_ajax'       => 'ajax' === $submission_type,
			'enable_redirect'   => 'redirect' === $submission_type,
			'redirect_type'     => isset( $decoded['redirect_type'] ) && in_array( $decoded['redirect_type'], array( 'page', 'custom' ), true )
				? $decoded['redirect_type']
				: ( ! empty( $decoded['redirect_url'] ) ? 'custom' : 'page' ),
			'redirect_url'      => isset( $decoded['redirect_url'] ) ? esc_url_raw( (string) $decoded['redirect_url'] ) : $defaults['redirect_url'],
			// Rich markup: filtered with the post allowlist, matching the save path.
			'thank_you_message' => isset( $decoded['thank_you_message'] ) ? wp_kses_post( (string) $decoded['thank_you_message'] ) : $defaults['thank_you_message'],
			'button_text'       => isset( $decoded['button_text'] ) ? sanitize_text_field( (string) $decoded['button_text'] ) : $defaults['button_text'],
			'button_alignment'  => isset( $decoded['button_alignment'] ) && in_array( $decoded['button_alignment'], array( 'left', 'center', 'right' ), true ) ? $decoded['button_alignment'] : $defaults['button_alignment'],
			'button_color'      => isset( $decoded['button_color'] ) && in_array( $decoded['button_color'], array( 'teal', 'blue', 'green', 'red', 'dark' ), true ) ? $decoded['button_color'] : $defaults['button_color'],
			'field_style'       => isset( $decoded['field_style'] ) && in_array( $decoded['field_style'], array( 'solid', 'dashed', 'none', 'outline', 'soft', 'minimal' ), true ) ? $decoded['field_style'] : '',
			'field_size'        => isset( $decoded['field_size'] ) && in_array( $decoded['field_size'], array( 'small', 'medium', 'large', 'compact', 'comfortable', 'spacious' ), true ) ? $decoded['field_size'] : '',
			'field_focus_color' => isset( $decoded['field_focus_color'] ) && in_array( $decoded['field_focus_color'], array( 'teal', 'blue', 'green', 'dark' ), true ) ? $decoded['field_focus_color'] : '',
			'field_border_width'=> isset( $decoded['field_border_width'] ) && '' !== $decoded['field_border_width'] ? max( 0, min( 10, absint( $decoded['field_border_width'] ) ) ) : '',
			'field_border_radius'=> isset( $decoded['field_border_radius'] ) && '' !== $decoded['field_border_radius'] ? max( 0, min( 50, absint( $decoded['field_border_radius'] ) ) ) : '',
			'field_background_color' => isset( $decoded['field_background_color'] ) && sanitize_hex_color( $decoded['field_background_color'] ) ? sanitize_hex_color( $decoded['field_background_color'] ) : '',
			'field_border_color' => isset( $decoded['field_border_color'] ) && sanitize_hex_color( $decoded['field_border_color'] ) ? sanitize_hex_color( $decoded['field_border_color'] ) : '',
			'field_text_color'  => isset( $decoded['field_text_color'] ) && sanitize_hex_color( $decoded['field_text_color'] ) ? sanitize_hex_color( $decoded['field_text_color'] ) : '',
			'label_size'        => isset( $decoded['label_size'] ) && in_array( $decoded['label_size'], array( 'small', 'medium', 'large' ), true ) ? $decoded['label_size'] : '',
			'label_color'       => isset( $decoded['label_color'] ) && sanitize_hex_color( $decoded['label_color'] ) ? sanitize_hex_color( $decoded['label_color'] ) : '',
			'label_subtext_color' => isset( $decoded['label_subtext_color'] ) && sanitize_hex_color( $decoded['label_subtext_color'] ) ? sanitize_hex_color( $decoded['label_subtext_color'] ) : '',
			'error_color'       => isset( $decoded['error_color'] ) && sanitize_hex_color( $decoded['error_color'] ) ? sanitize_hex_color( $decoded['error_color'] ) : '',
			'button_size'       => isset( $decoded['button_size'] ) && in_array( $decoded['button_size'], array( 'small', 'medium', 'large' ), true ) ? $decoded['button_size'] : '',
			'button_border_style' => isset( $decoded['button_border_style'] ) && in_array( $decoded['button_border_style'], array( 'solid', 'dashed', 'none' ), true ) ? $decoded['button_border_style'] : '',
			'button_border_width' => isset( $decoded['button_border_width'] ) && '' !== $decoded['button_border_width'] ? max( 0, min( 10, absint( $decoded['button_border_width'] ) ) ) : '',
			'button_border_radius' => isset( $decoded['button_border_radius'] ) && '' !== $decoded['button_border_radius'] ? max( 0, min( 50, absint( $decoded['button_border_radius'] ) ) ) : '',
			'button_background_color' => isset( $decoded['button_background_color'] ) && sanitize_hex_color( $decoded['button_background_color'] ) ? sanitize_hex_color( $decoded['button_background_color'] ) : '',
			'button_border_color' => isset( $decoded['button_border_color'] ) && sanitize_hex_color( $decoded['button_border_color'] ) ? sanitize_hex_color( $decoded['button_border_color'] ) : '',
			'button_text_color' => isset( $decoded['button_text_color'] ) && sanitize_hex_color( $decoded['button_text_color'] ) ? sanitize_hex_color( $decoded['button_text_color'] ) : '',
			'button_icon_type'     => isset( $decoded['button_icon_type'] ) && in_array( $decoded['button_icon_type'], array( 'none', 'dashicon', 'svg' ), true ) ? $decoded['button_icon_type'] : 'none',
			'button_icon_dashicon' => isset( $decoded['button_icon_dashicon'] ) ? sanitize_text_field( (string) $decoded['button_icon_dashicon'] ) : '',
			'button_icon_svg'      => isset( $decoded['button_icon_svg'] ) ? (string) $decoded['button_icon_svg'] : '',
			'button_icon_position' => isset( $decoded['button_icon_position'] ) && in_array( $decoded['button_icon_position'], array( 'left', 'right' ), true ) ? $decoded['button_icon_position'] : 'right',
			'button_icon_gap'      => isset( $decoded['button_icon_gap'] ) ? absint( $decoded['button_icon_gap'] ) : 8,
			'button_icon_size'     => isset( $decoded['button_icon_size'] ) ? absint( $decoded['button_icon_size'] ) : 18,
			'button_icon_color'    => isset( $decoded['button_icon_color'] ) && sanitize_hex_color( $decoded['button_icon_color'] ) ? sanitize_hex_color( $decoded['button_icon_color'] ) : '',
			'button_layout'     => isset( $decoded['button_layout'] ) && in_array( $decoded['button_layout'], array( 'below', 'inline' ), true ) ? $decoded['button_layout'] : 'below',
			'admin_email_type'  => $admin_email_type,
			'enable_admin_email'=> isset( $decoded['enable_admin_email'] ) ? (bool) $decoded['enable_admin_email'] : $defaults['enable_admin_email'],
			'enable_user_email' => isset( $decoded['enable_user_email'] ) ? (bool) $decoded['enable_user_email'] : $defaults['enable_user_email'],
			'admin_email'       => $admin_email,
			// Multi-step settings (data passthrough for Pro's multi-page module).
			'step_progress_style' => isset( $decoded['step_progress_style'] ) && in_array( $decoded['step_progress_style'], array( 'bar', 'steps', 'headings' ), true ) ? $decoded['step_progress_style'] : 'bar',
			'step_progress_color' => isset( $decoded['step_progress_color'] ) && sanitize_hex_color( $decoded['step_progress_color'] ) ? sanitize_hex_color( $decoded['step_progress_color'] ) : '',
			'step_progress_bg_color' => isset( $decoded['step_progress_bg_color'] ) && sanitize_hex_color( $decoded['step_progress_bg_color'] ) ? sanitize_hex_color( $decoded['step_progress_bg_color'] ) : '',
			'step_btn_color'      => isset( $decoded['step_btn_color'] ) && sanitize_hex_color( $decoded['step_btn_color'] ) ? sanitize_hex_color( $decoded['step_btn_color'] ) : '',
			'step_btn_text_color' => isset( $decoded['step_btn_text_color'] ) && sanitize_hex_color( $decoded['step_btn_text_color'] ) ? sanitize_hex_color( $decoded['step_btn_text_color'] ) : '',
			'step_btn_size'       => isset( $decoded['step_btn_size'] ) && in_array( $decoded['step_btn_size'], array( 'small', 'medium', 'large' ), true ) ? $decoded['step_btn_size'] : 'medium',
			'step_btn_radius'     => isset( $decoded['step_btn_radius'] ) && '' !== $decoded['step_btn_radius'] ? max( 0, min( 50, absint( $decoded['step_btn_radius'] ) ) ) : '',
			'step_next_text'      => isset( $decoded['step_next_text'] ) ? sanitize_text_field( (string) $decoded['step_next_text'] ) : 'Next',
			'step_prev_text'      => isset( $decoded['step_prev_text'] ) ? sanitize_text_field( (string) $decoded['step_prev_text'] ) : 'Previous',
			'design_theme'        => isset( $decoded['design_theme'] ) ? sanitize_key( (string) $decoded['design_theme'] ) : '',
			'hide_labels'         => ! empty( $decoded['hide_labels'] ),
			'hide_placeholders'   => ! empty( $decoded['hide_placeholders'] ),
			'choice_style'        => isset( $decoded['choice_style'] ) && 'button' === $decoded['choice_style'] ? 'button' : 'default',
			'dup_enabled'         => ! empty( $decoded['dup_enabled'] ),
			'dup_method'          => isset( $decoded['dup_method'] ) && in_array( $decoded['dup_method'], array( 'email', 'ip', 'field' ), true ) ? $decoded['dup_method'] : 'email',
			'dup_field_id'        => isset( $decoded['dup_field_id'] ) ? sanitize_key( (string) $decoded['dup_field_id'] ) : '',
			'dup_message'         => isset( $decoded['dup_message'] ) && '' !== trim( (string) $decoded['dup_message'] ) ? sanitize_textarea_field( (string) $decoded['dup_message'] ) : '',
			'style'               => $this->extract_style_from_record_settings( $decoded ),
			// Conversational mode. This list is a hard gate, not a convenience: a
			// key absent here never reaches the builder, so the pane would reopen
			// showing its defaults and the next save would write those defaults
			// back over the author's choices. Re-validated on read because a
			// hand-edited settings_json is untrusted input.
			'cv_enabled'          => ! empty( $decoded['cv_enabled'] ),
			'cv_progress'         => isset( $decoded['cv_progress'] ) && in_array( $decoded['cv_progress'], array( 'bar', 'dots', 'counter', 'percent', 'none' ), true ) ? $decoded['cv_progress'] : 'bar',
			'cv_transition'       => isset( $decoded['cv_transition'] ) && in_array( $decoded['cv_transition'], array( 'slide', 'fade', 'none' ), true ) ? $decoded['cv_transition'] : 'slide',
			'cv_key_hint'         => isset( $decoded['cv_key_hint'] ) ? ! empty( $decoded['cv_key_hint'] ) : true,
			'cv_next_text'        => isset( $decoded['cv_next_text'] ) ? sanitize_text_field( (string) $decoded['cv_next_text'] ) : '',
			'cv_prev_text'        => isset( $decoded['cv_prev_text'] ) ? sanitize_text_field( (string) $decoded['cv_prev_text'] ) : '',
			'cv_bg'               => isset( $decoded['cv_bg'] ) && sanitize_hex_color( $decoded['cv_bg'] ) ? sanitize_hex_color( $decoded['cv_bg'] ) : '',
			'cv_question_color'   => isset( $decoded['cv_question_color'] ) && sanitize_hex_color( $decoded['cv_question_color'] ) ? sanitize_hex_color( $decoded['cv_question_color'] ) : '',
			'cv_answer_color'     => isset( $decoded['cv_answer_color'] ) && sanitize_hex_color( $decoded['cv_answer_color'] ) ? sanitize_hex_color( $decoded['cv_answer_color'] ) : '',
			'cv_btn_color'        => isset( $decoded['cv_btn_color'] ) && sanitize_hex_color( $decoded['cv_btn_color'] ) ? sanitize_hex_color( $decoded['cv_btn_color'] ) : '',
			'cv_btn_text_color'   => isset( $decoded['cv_btn_text_color'] ) && sanitize_hex_color( $decoded['cv_btn_text_color'] ) ? sanitize_hex_color( $decoded['cv_btn_text_color'] ) : '',
			'cv_accent'           => isset( $decoded['cv_accent'] ) && sanitize_hex_color( $decoded['cv_accent'] ) ? sanitize_hex_color( $decoded['cv_accent'] ) : '',
			'cv_track_color'      => isset( $decoded['cv_track_color'] ) && sanitize_hex_color( $decoded['cv_track_color'] ) ? sanitize_hex_color( $decoded['cv_track_color'] ) : '',
			'cv_prev_color'       => isset( $decoded['cv_prev_color'] ) && sanitize_hex_color( $decoded['cv_prev_color'] ) ? sanitize_hex_color( $decoded['cv_prev_color'] ) : '',
			'cv_prev_bg'          => isset( $decoded['cv_prev_bg'] ) && sanitize_hex_color( $decoded['cv_prev_bg'] ) ? sanitize_hex_color( $decoded['cv_prev_bg'] ) : '',
			'cv_hint_color'       => isset( $decoded['cv_hint_color'] ) && sanitize_hex_color( $decoded['cv_hint_color'] ) ? sanitize_hex_color( $decoded['cv_hint_color'] ) : '',
			'cv_nav_align'        => isset( $decoded['cv_nav_align'] ) && in_array( $decoded['cv_nav_align'], array( 'left', 'center', 'split', 'right' ), true ) ? $decoded['cv_nav_align'] : 'left',
			'cv_progress_align'   => isset( $decoded['cv_progress_align'] ) && in_array( $decoded['cv_progress_align'], array( 'left', 'center', 'right' ), true ) ? $decoded['cv_progress_align'] : 'left',
			'cv_welcome_enabled'  => ! empty( $decoded['cv_welcome_enabled'] ),
			'cv_welcome_title'    => isset( $decoded['cv_welcome_title'] ) ? sanitize_text_field( (string) $decoded['cv_welcome_title'] ) : '',
			'cv_welcome_text'     => isset( $decoded['cv_welcome_text'] ) ? wp_kses_post( (string) $decoded['cv_welcome_text'] ) : '',
			'cv_welcome_btn'      => isset( $decoded['cv_welcome_btn'] ) ? sanitize_text_field( (string) $decoded['cv_welcome_btn'] ) : '',
			'cv_media_hide_mobile' => isset( $decoded['cv_media_hide_mobile'] ) ? ! empty( $decoded['cv_media_hide_mobile'] ) : true,
		);

		/**
		 * Re-merge any Pro-persisted extra settings back into the builder's
		 * formSettings on reload, so Pro module panes (Scheduling, etc.) repopulate
		 * their saved values. This reuses the same `shapeforms_form_settings_extra`
		 * contract used on save: persist callbacks read the same key names from the
		 * decoded settings as they do from the save payload, so passing $decoded here
		 * round-trips their values back out. Lite stays decoupled — it names no Pro
		 * key; core keys above always win over the extras.
		 *
		 * @param array<string, mixed> $extra   Extra settings (start empty).
		 * @param array<string, mixed> $decoded Decoded saved settings_json.
		 */
		$extra = (array) apply_filters( 'shapeforms_form_settings_extra', array(), $decoded );

		return array_merge( $extra, $settings );
	}

	/**
	 * Validates the nested per-device advanced style map from a decoded settings blob.
	 *
	 * Mirrors normalize_style_settings() in ajax-save.php: only ShapeForms-namespaced
	 * custom properties (`--bf-*`) carrying values from the safe CSS grammar survive.
	 * The value was already sanitized on save; re-validating on the way back out means
	 * a hand-edited or legacy settings_json can never feed unsafe tokens into the
	 * builder preview or the localized payload. Without this the Style tab would boot
	 * to its defaults on every reload because the allowlist above drops the key.
	 *
	 * @param array<string, mixed> $decoded Decoded settings_json.
	 * @return array<string, array<string, string>> Per-device { '--bf-*' => value } map.
	 */
	private function extract_style_from_record_settings( $decoded ) {
		$out = array(
			'desktop' => array(),
			'tablet'  => array(),
			'mobile'  => array(),
		);

		if ( empty( $decoded['style'] ) || ! is_array( $decoded['style'] ) ) {
			return $out;
		}

		foreach ( array( 'desktop', 'tablet', 'mobile' ) as $device ) {
			if ( empty( $decoded['style'][ $device ] ) || ! is_array( $decoded['style'][ $device ] ) ) {
				continue;
			}

			foreach ( $decoded['style'][ $device ] as $css_var => $value ) {
				if ( ! is_string( $css_var ) || ! preg_match( '/^--bf-[a-z0-9-]+$/', $css_var ) || ! is_string( $value ) ) {
					continue;
				}

				$value = trim( $value );
				if ( '' === $value || strlen( $value ) > 200 ) {
					continue;
				}
				// Same hard charset gate as the save-side sanitizer: no `:;{}<>@"'` or backslash.
				if ( preg_match( '/[^a-zA-Z0-9#%().,\s_\-]/', $value ) ) {
					continue;
				}
				$lower = strtolower( $value );
				if (
					false !== strpos( $lower, 'url(' ) ||
					false !== strpos( $lower, 'expression' ) ||
					false !== strpos( $lower, 'import' ) ||
					false !== strpos( $lower, '/*' )
				) {
					continue;
				}

				$out[ $device ][ $css_var ] = $value;
			}
		}

		return $out;
	}

	/**
	 * Resolves conversational media into a thumbnail URL, on rows AND fields.
	 *
	 * Only an attachment ID is stored, which is right — a URL breaks when the
	 * site moves and cannot produce responsive sources. But the builder's
	 * settings panels render a real <img>, so without this the author reopens
	 * the form to an empty box and cannot tell an image is set at all.
	 *
	 * Both are walked because either can own a screen: a multi-column row is
	 * one screen, and a single-column row is one screen per field.
	 *
	 * The URL is derived, never persisted: it is added on the way out and
	 * dropped again by prepare_rows() on the way back in.
	 *
	 * @param array<int, array<string, mixed>> $rows Stored rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function attach_row_media_previews( $rows ) {
		foreach ( $rows as $index => $row ) {
			$src = $this->resolve_cv_media_preview( $row );

			if ( '' !== $src ) {
				$rows[ $index ]['cv_media_preview'] = $src;
			}

			if ( empty( $row['columns'] ) || ! is_array( $row['columns'] ) ) {
				continue;
			}

			foreach ( $row['columns'] as $col_index => $column ) {
				if ( ! is_array( $column ) || empty( $column['fields'] ) || ! is_array( $column['fields'] ) ) {
					continue;
				}

				foreach ( $column['fields'] as $field_index => $field ) {
					$field_src = is_array( $field ) ? $this->resolve_cv_media_preview( $field ) : '';

					if ( '' !== $field_src ) {
						$rows[ $index ]['columns'][ $col_index ]['fields'][ $field_index ]['cv_media_preview'] = $field_src;
					}
				}
			}
		}

		return $rows;
	}

	/**
	 * Thumbnail URL for one row or field's stored attachment.
	 *
	 * A deleted attachment resolves to '' and the caller leaves the id alone,
	 * so the author can see something is wrong and re-pick rather than having
	 * the setting silently cleared underneath them.
	 *
	 * @param array<string, mixed> $source Stored row or field.
	 * @return string Image URL, or '' when there is nothing to show.
	 */
	private function resolve_cv_media_preview( $source ) {
		$attachment_id = isset( $source['cv_media_id'] ) ? absint( $source['cv_media_id'] ) : 0;

		if ( ! $attachment_id ) {
			return '';
		}

		$src = wp_get_attachment_image_url( $attachment_id, 'medium' );

		return $src ? $src : '';
	}

	/**
	 * Extracts builder structure from a database record.
	 *
	 * @param object|null $form_record Form database record.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function extract_structure_from_record( $form_record ) {
		if ( ! $form_record || empty( $form_record->fields_json ) ) {
			return array(
				'rows' => array(),
			);
		}

		$decoded = json_decode( (string) $form_record->fields_json, true );

		if ( isset( $decoded['rows'] ) && is_array( $decoded['rows'] ) ) {
			return array(
				'rows' => $this->attach_row_media_previews( $decoded['rows'] ),
			);
		}

		if ( isset( $decoded['fields'] ) && is_array( $decoded['fields'] ) ) {
			return array(
				'rows' => array(
					array(
						'columns' => array(
							array(
								'width'  => '100%',
								'fields' => $decoded['fields'],
							),
						),
					),
				),
			);
		}

		if ( is_array( $decoded ) ) {
			return array(
				'rows' => array(
					array(
						'columns' => array(
							array(
								'width'  => '100%',
								'fields' => $decoded,
							),
						),
					),
				),
			);
		}

		return array(
			'rows' => array(),
		);
	}

	/**
	 * Extracts field array from a database record.
	 *
	 * @param object|null $form_record Form database record.
	 * @return array<int, array<string, mixed>>
	 */
	private function extract_fields_from_record( $form_record ) {
		$structure = $this->extract_structure_from_record( $form_record );
		$fields    = array();

		if ( empty( $structure['rows'] ) || ! is_array( $structure['rows'] ) ) {
			return $fields;
		}

		foreach ( $structure['rows'] as $row ) {
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
	 * Returns a short preview string from the first 2-3 field values.
	 *
	 * @param object $entry Entry record.
	 * @return string
	 */
	private function get_entry_preview_text( $entry ) {
		$decoded = json_decode( (string) $entry->entry_data_json, true );

		if ( empty( $decoded ) || ! is_array( $decoded ) ) {
			return __( 'No data', 'shapeforms' );
		}

		$parts = array();

		foreach ( $decoded as $field ) {
			if ( count( $parts ) >= 3 ) {
				break;
			}

			if ( empty( $field['value'] ) || ( isset( $field['type'] ) && in_array( $field['type'], array( 'file', 'submit' ), true ) ) ) {
				continue;
			}

			$type  = isset( $field['type'] ) ? (string) $field['type'] : '';
			$value = ShapeForms_Lite::format_field_value( $field['value'], $type );

			$value = sanitize_text_field( $value );

			if ( mb_strlen( $value ) > 40 ) {
				$value = mb_substr( $value, 0, 40 ) . '...';
			}

			$parts[] = $value;
		}

		return $parts ? implode( ' — ', $parts ) : __( 'No data', 'shapeforms' );
	}

	/**
	 * Returns published pages for the redirect dropdown.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function get_pages_for_redirect() {
		$pages  = get_pages( array( 'post_status' => 'publish', 'sort_column' => 'post_title', 'sort_order' => 'ASC' ) );
		$result = array();

		if ( $pages ) {
			foreach ( $pages as $page ) {
				$result[] = array(
					'id'    => (int) $page->ID,
					'title' => (string) $page->post_title,
					'url'   => (string) get_permalink( $page->ID ),
				);
			}
		}

		return $result;
	}

	/**
	 * Returns field library definitions for the builder.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function get_field_library() {
		$library = array(
			'text'     => array(
				'label' => __( 'Text', 'shapeforms' ),
				'icon'  => 'dashicons-editor-textcolor',
				'group' => 'basic',
			),
			'name'     => array(
				'label' => __( 'Name', 'shapeforms' ),
				'icon'  => 'dashicons-admin-users',
				'group' => 'basic',
			),
			'email'    => array(
				'label' => __( 'Email', 'shapeforms' ),
				'icon'  => 'dashicons-email',
				'group' => 'basic',
			),
			'number'   => array(
				'label' => __( 'Number', 'shapeforms' ),
				'icon'  => 'dashicons-editor-ol',
				'group' => 'basic',
			),
			'textarea' => array(
				'label' => __( 'Textarea', 'shapeforms' ),
				'icon'  => 'dashicons-media-text',
				'group' => 'basic',
			),
			'date'     => array(
				'label' => __( 'Date Picker', 'shapeforms' ),
				'icon'  => 'dashicons-calendar-alt',
				'group' => 'basic',
			),
			'time'     => array(
				'label' => __( 'Time Picker', 'shapeforms' ),
				'icon'  => 'dashicons-clock',
				'group' => 'basic',
			),
			'select'   => array(
				'label' => __( 'Select', 'shapeforms' ),
				'icon'  => 'dashicons-arrow-down-alt2',
				'group' => 'basic',
			),
			'multiselect' => array(
				'label' => __( 'Multi Select', 'shapeforms' ),
				'icon'  => 'dashicons-list-view',
				'group' => 'basic',
			),
			'checkbox' => array(
				'label' => __( 'Checkbox', 'shapeforms' ),
				'icon'  => 'dashicons-yes-alt',
				'group' => 'basic',
			),
			'radio'    => array(
				'label' => __( 'Radio', 'shapeforms' ),
				'icon'  => 'dashicons-marker',
				'group' => 'basic',
			),
			'tel'      => array(
				'label' => __( 'Phone', 'shapeforms' ),
				'icon'  => 'dashicons-phone',
				'group' => 'basic',
			),
			'url'      => array(
				'label' => __( 'URL', 'shapeforms' ),
				'icon'  => 'dashicons-admin-links',
				'group' => 'basic',
			),
			'numeric'  => array(
				'label' => __( 'Numeric', 'shapeforms' ),
				'icon'  => 'dashicons-calculator',
				'group' => 'basic',
			),
			'input_mask' => array(
				'label' => __( 'Input Mask', 'shapeforms' ),
				'icon'  => 'dashicons-editor-customchar',
				'group' => 'basic',
			),
			'html_editor' => array(
				'label' => __( 'HTML Editor', 'shapeforms' ),
				'icon'  => 'dashicons-editor-code',
				'group' => 'basic',
			),
			'paragraph' => array(
				'label' => __( 'Paragraph', 'shapeforms' ),
				'icon'  => 'dashicons-editor-paragraph',
				'group' => 'basic',
			),
			'address'  => array(
				'label' => __( 'Address', 'shapeforms' ),
				'icon'  => 'dashicons-location',
				'group' => 'basic',
			),
			'country'  => array(
				'label' => __( 'Country', 'shapeforms' ),
				'icon'  => 'dashicons-admin-site-alt3',
				'group' => 'basic',
			),
			'star_rating' => array(
				'label' => __( 'Star Rating', 'shapeforms' ),
				'icon'  => 'dashicons-star-filled',
				'group' => 'basic',
			),
			'slider_range' => array(
				'label' => __( 'Slider Range', 'shapeforms' ),
				'icon'  => 'dashicons-leftright',
				'group' => 'basic',
			),
			'captcha'  => array(
				'label' => __( 'Captcha', 'shapeforms' ),
				'icon'  => 'dashicons-shield',
				'group' => 'advanced',
			),
			'section_break' => array(
				'label' => __( 'Section Break', 'shapeforms' ),
				'icon'  => 'dashicons-minus',
				'group' => 'advanced',
			),
			'terms_conditions' => array(
				'label' => __( 'Terms & Conditions', 'shapeforms' ),
				'icon'  => 'dashicons-media-document',
				'group' => 'advanced',
			),
			'file' => array(
				'label' => __( 'File Upload', 'shapeforms' ),
				'icon'  => 'dashicons-upload',
				'group' => 'advanced',
			),
			'submit' => array(
				'label' => __( 'Submit Button', 'shapeforms' ),
				'icon'  => 'dashicons-button',
				'group' => 'advanced',
			),
		);

		/**
		 * Filter the field library items available in the builder sidebar.
		 *
		 * Pro can add new field types here (signature, payment, etc.).
		 * Each entry: 'type_key' => array( 'label' => '', 'icon' => 'dashicons-...', 'group' => 'basic|advanced|pro' )
		 *
		 * @param array<string, array<string, string>> $library Field library items.
		 */
		return apply_filters( 'shapeforms_field_library', $library );
	}

	/**
	 * Renders the Reports page.
	 *
	 * @return void
	 */
	public function render_reports_page() {
		global $wpdb;

		$entries_table = esc_sql( $this->plugin->get_entries_table_name() );
		$forms_table   = esc_sql( $this->plugin->get_forms_table_name() );

		// Overview stats.
		$total_forms   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$forms_table}` WHERE status != 'trash'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// Spam AND trashed entries are excluded from every reporting stat below — spam is
		// not a genuine submission and a trashed entry is on its way to deletion; either
		// would skew totals, per-form counts, and the trend chart.
		$total_entries = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$entries_table}` WHERE status != 'spam' AND trashed_at IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$unread_count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$entries_table}` WHERE status = %s AND trashed_at IS NULL", 'unread' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$starred_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$entries_table}` WHERE status = %s AND trashed_at IS NULL", 'starred' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// Today's entries.
		$today_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$entries_table}` WHERE status != 'spam' AND trashed_at IS NULL AND created_at >= %s", wp_date( 'Y-m-d' ) . ' 00:00:00' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// This week entries.
		$week_start  = wp_date( 'Y-m-d', strtotime( 'monday this week' ) );
		$week_count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$entries_table}` WHERE status != 'spam' AND trashed_at IS NULL AND created_at >= %s", $week_start . ' 00:00:00' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// Entries per form.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names sanitized via esc_sql() above.
		$per_form = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT f.id, f.title, COUNT(e.id) AS total,
				SUM(CASE WHEN e.status = 'unread' THEN 1 ELSE 0 END) AS unread,
				SUM(CASE WHEN e.status = 'read' THEN 1 ELSE 0 END) AS is_read,
				SUM(CASE WHEN e.status = 'starred' THEN 1 ELSE 0 END) AS starred
			FROM `{$forms_table}` f
			LEFT JOIN `{$entries_table}` e ON e.form_id = f.id AND e.status != 'spam' AND e.trashed_at IS NULL
			WHERE f.status != 'trash'
			GROUP BY f.id
			ORDER BY total DESC"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Daily submissions for last 30 days.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $entries_table sanitized via esc_sql() above.
		$daily_data = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT DATE(created_at) AS entry_date, COUNT(*) AS total
				FROM `{$entries_table}`
				WHERE status != 'spam' AND trashed_at IS NULL AND created_at >= %s
				GROUP BY DATE(created_at)
				ORDER BY entry_date ASC",
				wp_date( 'Y-m-d', strtotime( '-30 days' ) ) . ' 00:00:00'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Build 30-day labels and values arrays.
		$daily_map = array();
		foreach ( $daily_data as $row ) {
			$daily_map[ $row->entry_date ] = (int) $row->total;
		}

		$chart_labels = array();
		$chart_values = array();
		for ( $i = 29; $i >= 0; $i-- ) {
			$date           = wp_date( 'Y-m-d', strtotime( "-{$i} days" ) );
			$chart_labels[] = wp_date( 'M j', strtotime( $date ) );
			$chart_values[] = isset( $daily_map[ $date ] ) ? $daily_map[ $date ] : 0;
		}

		// Recent entries (last 10).
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names sanitized via esc_sql() above.
		$recent_entries = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT e.id, e.form_id, e.entry_data_json, e.status, e.created_at, f.title AS form_title
			FROM `{$entries_table}` e
			LEFT JOIN `{$forms_table}` f ON f.id = e.form_id
			ORDER BY e.created_at DESC
			LIMIT 10"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Max entries for progress bars in "Entries by Form".
		$max_form_entries = 1;
		foreach ( $per_form as $row ) {
			if ( (int) $row->total > $max_form_entries ) {
				$max_form_entries = (int) $row->total;
			}
		}

		$this->render_admin_topbar( 'shapeforms-lite-reports' );
		?>
		<div class="wrap shapeforms-reports-wrap">
			<hr class="wp-header-end"><?php // Keep relocated notices above the header (see Forms list for rationale). ?>
			<div class="shapeforms-page-header">
				<h1><?php esc_html_e( 'Reports', 'shapeforms' ); ?></h1>
				<span class="shapeforms-page-header__badge"><?php esc_html_e( 'Overview', 'shapeforms' ); ?></span>
				<?php $this->render_header_upgrade(); ?>
			</div>

			<!-- Overview Stat Cards -->
			<div class="shapeforms-reports-stats">
				<div class="shapeforms-stat-card">
					<div class="shapeforms-stat-card__icon shapeforms-stat-card__icon--forms">
						<?php shapeforms_lite_brand_icon( array( 'class' => 'dashicons shapeforms-brand-icon' ) ); ?>
					</div>
					<div class="shapeforms-stat-card__body">
						<span class="shapeforms-stat-card__value"><?php echo absint( $total_forms ); ?></span>
						<span class="shapeforms-stat-card__label"><?php esc_html_e( 'Total Forms', 'shapeforms' ); ?></span>
					</div>
				</div>
				<div class="shapeforms-stat-card">
					<div class="shapeforms-stat-card__icon shapeforms-stat-card__icon--entries">
						<span class="dashicons dashicons-email-alt"></span>
					</div>
					<div class="shapeforms-stat-card__body">
						<span class="shapeforms-stat-card__value"><?php echo absint( $total_entries ); ?></span>
						<span class="shapeforms-stat-card__label"><?php esc_html_e( 'Total Entries', 'shapeforms' ); ?></span>
					</div>
				</div>
				<div class="shapeforms-stat-card">
					<div class="shapeforms-stat-card__icon shapeforms-stat-card__icon--unread">
						<span class="dashicons dashicons-email"></span>
					</div>
					<div class="shapeforms-stat-card__body">
						<span class="shapeforms-stat-card__value"><?php echo absint( $unread_count ); ?></span>
						<span class="shapeforms-stat-card__label"><?php esc_html_e( 'Unread', 'shapeforms' ); ?></span>
					</div>
				</div>
				<div class="shapeforms-stat-card">
					<div class="shapeforms-stat-card__icon shapeforms-stat-card__icon--starred">
						<span class="dashicons dashicons-star-filled"></span>
					</div>
					<div class="shapeforms-stat-card__body">
						<span class="shapeforms-stat-card__value"><?php echo absint( $starred_count ); ?></span>
						<span class="shapeforms-stat-card__label"><?php esc_html_e( 'Starred', 'shapeforms' ); ?></span>
					</div>
				</div>
				<div class="shapeforms-stat-card">
					<div class="shapeforms-stat-card__icon shapeforms-stat-card__icon--today">
						<span class="dashicons dashicons-calendar-alt"></span>
					</div>
					<div class="shapeforms-stat-card__body">
						<span class="shapeforms-stat-card__value"><?php echo absint( $today_count ); ?></span>
						<span class="shapeforms-stat-card__label"><?php esc_html_e( 'Today', 'shapeforms' ); ?></span>
					</div>
				</div>
				<div class="shapeforms-stat-card">
					<div class="shapeforms-stat-card__icon shapeforms-stat-card__icon--week">
						<span class="dashicons dashicons-calendar"></span>
					</div>
					<div class="shapeforms-stat-card__body">
						<span class="shapeforms-stat-card__value"><?php echo absint( $week_count ); ?></span>
						<span class="shapeforms-stat-card__label"><?php esc_html_e( 'This Week', 'shapeforms' ); ?></span>
					</div>
				</div>
			</div>

			<?php
			/**
			 * Fires after the stat cards row on the Reports page.
			 * Pro modules can inject additional stat cards here.
			 *
			 * @param array<string, mixed> $stats {
			 *     @type int $total_forms   Total active forms.
			 *     @type int $total_entries Total submissions.
			 *     @type int $unread_count  Unread entries.
			 *     @type int $starred_count Starred entries.
			 *     @type int $today_count   Entries today.
			 *     @type int $week_count    Entries this week.
			 * }
			 */
			do_action(
				'shapeforms_reports_after_stats',
				array(
					'total_forms'   => $total_forms,
					'total_entries' => $total_entries,
					'unread_count'  => $unread_count,
					'starred_count' => $starred_count,
					'today_count'   => $today_count,
					'week_count'    => $week_count,
				)
			);
			?>

			<!-- Submissions Chart (FREE) -->
			<div class="shapeforms-reports-row">
				<div class="shapeforms-reports-chart-card">
					<div class="shapeforms-reports-card-header">
						<h2><span class="dashicons dashicons-chart-area"></span> <?php esc_html_e( 'Submissions', 'shapeforms' ); ?></h2>
						<span class="shapeforms-reports-card-header__sub"><?php esc_html_e( 'Last 30 Days', 'shapeforms' ); ?></span>
					</div>
					<canvas id="shapeforms-submissions-chart" height="300"></canvas>
				</div>
			</div>

			<?php
			/**
			 * Fires after the submissions chart row on the Reports page.
			 * Pro modules can inject additional chart rows here (e.g. views vs submissions).
			 *
			 * @param array<string, mixed> $chart_data {
			 *     @type string[] $chart_labels 30-day date labels (e.g. "Apr 1").
			 *     @type int[]    $chart_values 30-day submission counts.
			 * }
			 */
			do_action(
				'shapeforms_reports_after_chart',
				array(
					'chart_labels' => $chart_labels,
					'chart_values' => $chart_values,
				)
			);
			?>

			<!-- Two-column: Entries by Form + Recent Entries -->
			<div class="shapeforms-reports-row shapeforms-reports-row--two-col">
				<!-- Entries by Form -->
				<div class="shapeforms-reports-table-card shapeforms-reports-table-card--compact">
					<div class="shapeforms-reports-card-header">
						<h2><span class="dashicons dashicons-list-view"></span> <?php esc_html_e( 'Entries by Form', 'shapeforms' ); ?></h2>
					</div>
					<?php if ( ! empty( $per_form ) ) : ?>
						<div class="shapeforms-reports-paginated" id="shapeforms-forms-paginated" data-per-page="5">
							<table class="shapeforms-reports-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Form', 'shapeforms' ); ?></th>
										<th class="shapeforms-col-center"><?php esc_html_e( 'Entries', 'shapeforms' ); ?></th>
										<th><?php esc_html_e( 'Breakdown', 'shapeforms' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $per_form as $row ) : ?>
										<?php
										$total   = max( 1, (int) $row->total );
										$bar_pct = round( ( $total / $max_form_entries ) * 100 );
										?>
										<tr>
											<td>
												<a href="<?php echo esc_url( admin_url( 'admin.php?page=shapeforms-lite-entries&form_id=' . absint( $row->id ) ) ); ?>">
													<?php echo esc_html( $row->title ? $row->title : '#' . $row->id ); ?>
												</a>
											</td>
											<td class="shapeforms-col-center">
												<span class="shapeforms-reports-count-badge"><?php echo absint( $row->total ); ?></span>
											</td>
											<td>
												<div class="shapeforms-reports-bar-wrap">
													<div class="shapeforms-reports-bar" style="width:<?php echo absint( $bar_pct ); ?>%;">
														<span class="shapeforms-reports-bar__segment shapeforms-reports-bar__segment--unread" style="width:<?php echo $total ? absint( round( ( (int) $row->unread / $total ) * 100 ) ) : 0; ?>%;" title="<?php /* translators: %d: unread count */ printf( esc_attr__( 'Unread: %d', 'shapeforms' ), absint( $row->unread ) ); ?>"></span>
														<span class="shapeforms-reports-bar__segment shapeforms-reports-bar__segment--read" style="width:<?php echo $total ? absint( round( ( (int) $row->is_read / $total ) * 100 ) ) : 0; ?>%;" title="<?php /* translators: %d: read count */ printf( esc_attr__( 'Read: %d', 'shapeforms' ), absint( $row->is_read ) ); ?>"></span>
														<span class="shapeforms-reports-bar__segment shapeforms-reports-bar__segment--starred" style="width:<?php echo $total ? absint( round( ( (int) $row->starred / $total ) * 100 ) ) : 0; ?>%;" title="<?php /* translators: %d: starred count */ printf( esc_attr__( 'Starred: %d', 'shapeforms' ), absint( $row->starred ) ); ?>"></span>
													</div>
												</div>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
							<div class="shapeforms-reports-card-footer">
								<div class="shapeforms-reports-bar-legend">
									<span class="shapeforms-reports-bar-legend__item"><span class="shapeforms-reports-bar-legend__dot shapeforms-reports-bar-legend__dot--unread"></span> <?php esc_html_e( 'Unread', 'shapeforms' ); ?></span>
									<span class="shapeforms-reports-bar-legend__item"><span class="shapeforms-reports-bar-legend__dot shapeforms-reports-bar-legend__dot--read"></span> <?php esc_html_e( 'Read', 'shapeforms' ); ?></span>
									<span class="shapeforms-reports-bar-legend__item"><span class="shapeforms-reports-bar-legend__dot shapeforms-reports-bar-legend__dot--starred"></span> <?php esc_html_e( 'Starred', 'shapeforms' ); ?></span>
								</div>
								<div class="shapeforms-reports-pager"></div>
							</div>
						</div>
					<?php else : ?>
						<div class="shapeforms-reports-empty-state">
							<span class="dashicons dashicons-chart-pie"></span>
							<p><?php esc_html_e( 'No forms found. Create your first form to see reports.', 'shapeforms' ); ?></p>
						</div>
					<?php endif; ?>
				</div>

				<!-- Recent Entries -->
				<div class="shapeforms-reports-table-card shapeforms-reports-table-card--compact">
					<div class="shapeforms-reports-card-header">
						<h2><span class="dashicons dashicons-clock"></span> <?php esc_html_e( 'Recent Entries', 'shapeforms' ); ?></h2>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=shapeforms-lite-entries' ) ); ?>" class="shapeforms-reports-card-header__link"><?php esc_html_e( 'View All', 'shapeforms' ); ?> &rarr;</a>
					</div>
					<?php if ( ! empty( $recent_entries ) ) : ?>
						<div class="shapeforms-reports-paginated" id="shapeforms-entries-paginated" data-per-page="5">
							<div class="shapeforms-reports-activity">
								<?php foreach ( $recent_entries as $entry ) : ?>
									<?php
									$preview = '';
									$decoded = json_decode( (string) $entry->entry_data_json, true );
									if ( is_array( $decoded ) && ! empty( $decoded ) ) {
										$first = reset( $decoded );
										$val   = isset( $first['value'] ) ? $first['value'] : '';
										if ( is_array( $val ) ) {
											$val = implode( ', ', $val );
										}
										$preview = wp_trim_words( (string) $val, 6 );
									}
									?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=shapeforms-lite-entries&entry_id=' . absint( $entry->id ) ) ); ?>" class="shapeforms-reports-activity__item">
										<span class="shapeforms-reports-activity__dot shapeforms-reports-activity__dot--<?php echo esc_attr( $entry->status ); ?>"></span>
										<div class="shapeforms-reports-activity__body">
											<span class="shapeforms-reports-activity__text">
												<?php echo esc_html( $preview ? $preview : __( 'Entry', 'shapeforms' ) ); ?>
												<span class="shapeforms-reports-activity__form"><?php echo esc_html( $entry->form_title ? $entry->form_title : '#' . $entry->form_id ); ?></span>
											</span>
											<span class="shapeforms-reports-activity__meta">
												<span class="shapeforms-status-badge shapeforms-status--<?php echo esc_attr( $entry->status ); ?>"><?php echo esc_html( ucfirst( (string) $entry->status ) ); ?></span>
												<span class="shapeforms-reports-activity__date"><?php echo esc_html( human_time_diff( strtotime( (string) $entry->created_at ), current_time( 'timestamp' ) ) ); ?> <?php esc_html_e( 'ago', 'shapeforms' ); ?></span>
											</span>
										</div>
									</a>
								<?php endforeach; ?>
							</div>
							<div class="shapeforms-reports-card-footer">
								<span class="shapeforms-reports-pager-info"></span>
								<div class="shapeforms-reports-pager"></div>
							</div>
						</div>
					<?php else : ?>
						<div class="shapeforms-reports-empty-state">
							<span class="dashicons dashicons-format-chat"></span>
							<p><?php esc_html_e( 'No entries yet. Entries will appear here once forms are submitted.', 'shapeforms' ); ?></p>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
		// Pass chart data to the already-enqueued admin handle, then add the chart+pagination script.
		wp_localize_script(
			'shapeforms-lite-admin',
			'shapeformsReports',
			array(
				'chartLabels'  => $chart_labels,
				'chartValues'  => $chart_values,
				'entriesLabel' => __( 'entries', 'shapeforms' ),
			)
		);
		wp_add_inline_script(
			'shapeforms-lite-admin',
			'(function(){
				var labels=shapeformsReports.chartLabels;
				var values=shapeformsReports.chartValues;
				var canvas=document.getElementById("shapeforms-submissions-chart");
				if(canvas){
					var ctx=canvas.getContext("2d");
					var dpr=window.devicePixelRatio||1;
					var rect=canvas.parentElement.getBoundingClientRect();
					var w=rect.width;
					var h=300;
					canvas.width=w*dpr;canvas.height=h*dpr;
					canvas.style.width=w+"px";canvas.style.height=h+"px";
					ctx.scale(dpr,dpr);
					var padL=50,padR=20,padT=20,padB=50;
					var chartW=w-padL-padR;
					var chartH=h-padT-padB;
					var maxVal=Math.max.apply(null,values)||1;
					var step=Math.ceil(maxVal/5)||1;
					maxVal=step*5;
					ctx.strokeStyle="#e2e8f0";ctx.lineWidth=1;
					ctx.fillStyle="#94a3b8";
					ctx.font="11px -apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,sans-serif";
					ctx.textAlign="right";ctx.textBaseline="middle";
					for(var i=0;i<=5;i++){
						var y=Math.round(padT+chartH-(chartH*i/5))+0.5;
						ctx.beginPath();ctx.setLineDash(i===0?[]:[4,4]);
						ctx.moveTo(padL,y);ctx.lineTo(w-padR,y);ctx.stroke();
						ctx.fillText(String(step*i),padL-10,y);
					}
					ctx.setLineDash([]);
					var barW=Math.max(6,Math.min(20,(chartW/labels.length)-6));
					for(var j=0;j<values.length;j++){
						var barH=maxVal>0?(values[j]/maxVal)*chartH:0;
						if(barH<0)barH=0;
						var x=padL+(chartW/labels.length)*j+((chartW/labels.length)-barW)/2;
						var barY=padT+chartH-barH;
						var gradient=ctx.createLinearGradient(x,barY,x,padT+chartH);
						gradient.addColorStop(0,"#3b82f6");gradient.addColorStop(1,"#93c5fd");
						ctx.fillStyle=gradient;
						var r=Math.min(barW/2,4);
						if(barH>r){
							ctx.beginPath();ctx.moveTo(x+r,barY);ctx.lineTo(x+barW-r,barY);
							ctx.quadraticCurveTo(x+barW,barY,x+barW,barY+r);
							ctx.lineTo(x+barW,padT+chartH);ctx.lineTo(x,padT+chartH);
							ctx.lineTo(x,barY+r);ctx.quadraticCurveTo(x,barY,x+r,barY);
							ctx.fill();
						}else if(barH>0){ctx.fillRect(x,barY,barW,barH);}
					}
					ctx.fillStyle="#94a3b8";
					ctx.font="10px -apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,sans-serif";
					ctx.textAlign="center";ctx.textBaseline="top";
					var labelStep=labels.length<=15?1:Math.ceil(labels.length/10);
					for(var k=0;k<labels.length;k++){
						if(k%labelStep===0){
							var lx=padL+(chartW/labels.length)*k+(chartW/labels.length)/2;
							ctx.save();ctx.translate(lx,padT+chartH+10);ctx.rotate(-0.45);
							ctx.fillText(labels[k],0,0);ctx.restore();
						}
					}
					var tooltipEl=document.createElement("div");
					tooltipEl.className="shapeforms-chart-tooltip";
					canvas.parentElement.style.position="relative";
					canvas.parentElement.appendChild(tooltipEl);
					canvas.addEventListener("mousemove",function(e){
						var cRect=canvas.getBoundingClientRect();
						var mx=e.clientX-cRect.left;
						var slotW=chartW/labels.length;
						var idx=Math.floor((mx-padL)/slotW);
						if(idx>=0&&idx<labels.length){
							tooltipEl.innerHTML="<strong>"+labels[idx]+"</strong><br>"+values[idx]+" "+shapeformsReports.entriesLabel;
							tooltipEl.style.display="block";
							tooltipEl.style.left=(padL+slotW*idx+slotW/2)+"px";
							tooltipEl.style.top="10px";
						}else{tooltipEl.style.display="none";}
					});
					canvas.addEventListener("mouseleave",function(){tooltipEl.style.display="none";});
				}
				document.querySelectorAll(".shapeforms-reports-paginated").forEach(function(wrap){
					var perPage=parseInt(wrap.getAttribute("data-per-page"),10)||5;
					var table=wrap.querySelector("table");
					var activity=wrap.querySelector(".shapeforms-reports-activity");
					var items;
					if(table){items=Array.prototype.slice.call(table.querySelectorAll("tbody tr"));}
					else if(activity){items=Array.prototype.slice.call(activity.children);}
					else{return;}
					var totalPages=Math.ceil(items.length/perPage);
					if(totalPages<=1)return;
					var currentPage=1;
					var pagerEl=wrap.querySelector(".shapeforms-reports-pager");
					var infoEl=wrap.querySelector(".shapeforms-reports-pager-info");
					function showPage(page){
						currentPage=page;
						var start=(page-1)*perPage,end=start+perPage;
						items.forEach(function(item,idx){item.style.display=(idx>=start&&idx<end)?"":"none";});
						renderPager();
						if(infoEl)infoEl.textContent=page+" / "+totalPages;
					}
					function renderPager(){
						if(!pagerEl)return;
						pagerEl.innerHTML="";
						var prev=document.createElement("button");prev.type="button";
						prev.className="shapeforms-pager-btn";prev.innerHTML="&lsaquo;";
						prev.disabled=currentPage===1;
						prev.addEventListener("click",function(){showPage(currentPage-1);});
						pagerEl.appendChild(prev);
						for(var p=1;p<=totalPages;p++){
							var btn=document.createElement("button");btn.type="button";
							btn.className="shapeforms-pager-btn"+(p===currentPage?" is-active":"");
							btn.textContent=p;
							btn.addEventListener("click",(function(pg){return function(){showPage(pg);};})(p));
							pagerEl.appendChild(btn);
						}
						var next=document.createElement("button");next.type="button";
						next.className="shapeforms-pager-btn";next.innerHTML="&rsaquo;";
						next.disabled=currentPage===totalPages;
						next.addEventListener("click",function(){showPage(currentPage+1);});
						pagerEl.appendChild(next);
					}
					showPage(1);
				});
			})();'
		);
	}
}
