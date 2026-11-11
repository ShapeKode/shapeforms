<?php
/**
 * Cache-compatibility integration.
 *
 * Form design, status and field changes are saved through the plugin's own AJAX
 * handler (`admin/ajax-save.php`) rather than by updating the WordPress post that
 * embeds the form. Full-page cache plugins (LiteSpeed, WP Rocket, W3TC, WP Super
 * Cache) and Elementor's generated CSS files therefore have no way of knowing the
 * embedding page changed, so they keep serving a stale snapshot — including the
 * old inline `--bf-*` style variables — until the cache is purged by hand.
 *
 * This service closes that gap: whenever a form is saved it asks the active cache
 * layers to purge, so the front end reflects the edit immediately. Every purge
 * call is a no-op when the corresponding plugin is inactive, so it is safe to run
 * unconditionally and never creates a hard dependency on any caching plugin.
 *
 * @package ShapeFormsLite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Purges third-party caches when a form changes.
 */
class ShapeForms_Lite_Cache {

	/**
	 * Main plugin instance.
	 *
	 * @var ShapeForms_Lite
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param ShapeForms_Lite $plugin Main plugin instance.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Purges supported caches after a form has been saved.
	 *
	 * Hooked to `shapeforms_form_saved`. The hook's arguments are accepted for
	 * context (and to allow extenders to short-circuit via the filter below) but
	 * a full purge is used because a single form can be embedded on any number of
	 * pages, which cannot be discovered cheaply or reliably.
	 *
	 * @param int   $form_id Saved form ID.
	 * @param array $data    Sanitized form payload that was stored.
	 * @param bool  $is_new  Whether the save created a new form.
	 * @return void
	 */
	public function purge_on_form_saved( $form_id, $data = array(), $is_new = false ) {
		/**
		 * Filters whether saving a form should purge front-end caches.
		 *
		 * Return false to keep the existing cache (e.g. to batch many saves and
		 * purge once manually).
		 *
		 * @param bool $should_purge Whether to purge. Default true.
		 * @param int  $form_id      Saved form ID.
		 * @param bool $is_new       Whether the save created a new form.
		 */
		if ( ! apply_filters( 'shapeforms_purge_caches_on_save', true, $form_id, $is_new ) ) {
			return;
		}

		$this->purge_caches();
	}

	/**
	 * Asks every supported cache layer to clear itself.
	 *
	 * Each branch is guarded so that only caches which are actually present do any
	 * work; absent plugins are skipped silently. Extenders (including ShapeForms Pro)
	 * can hook `shapeforms_purge_caches` to flush additional caches.
	 *
	 * @return void
	 */
	public function purge_caches() {
		// LiteSpeed Cache — listens for this action and purges the page cache.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party action defined by the LiteSpeed Cache plugin; the name is theirs by design.
		do_action( 'litespeed_purge_all', 'shapeforms form saved' );

		// WP Rocket — clear the full domain cache.
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		// W3 Total Cache.
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}

		// WP Super Cache.
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}

		// Elementor — regenerate the cached per-post CSS files.
		if ( class_exists( '\Elementor\Plugin' ) ) {
			$elementor = \Elementor\Plugin::$instance;
			if ( isset( $elementor->files_manager ) ) {
				$elementor->files_manager->clear_cache();
			}
		}

		/**
		 * Fires after ShapeForms has purged the built-in supported caches.
		 *
		 * Allows add-ons to flush any additional cache layer following a form change.
		 */
		do_action( 'shapeforms_purge_caches' );
	}
}
