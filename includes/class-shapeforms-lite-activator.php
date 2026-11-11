<?php
/**
 * Runs plugin activation tasks.
 *
 * @package ShapeFormsLite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin activator.
 */
class ShapeForms_Lite_Activator {

	/**
	 * Runs on plugin activation.
	 *
	 * On a standard (single-site) install this creates the tables for the current
	 * site.  On a multisite network the $network_wide flag is true when the plugin
	 * is network-activated, so we iterate every site and create tables for each.
	 *
	 * @param bool $network_wide Whether activated network-wide.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			// Network activation — create tables for every existing subsite.
			$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( $site_id );
				self::create_tables();
				update_option( 'shapeforms_lite_db_version', SHAPEFORMS_LITE_DB_VERSION );
				restore_current_blog();
			}
		} else {
			// Standard or per-site activation.
			self::create_tables();
			update_option( 'shapeforms_lite_db_version', SHAPEFORMS_LITE_DB_VERSION );
		}
	}

	/**
	 * Creates tables for a newly created subsite.
	 *
	 * Hooked to `wp_initialize_site` (WP 5.1+) and the legacy `wpmu_new_blog`.
	 *
	 * @param int|\WP_Site $site Site ID or WP_Site object.
	 * @return void
	 */
	public static function on_new_site( $site ) {
		// is_plugin_active_for_network() lives in wp-admin/includes/plugin.php, which is
		// not loaded when a site is created outside the admin (REST, WP-CLI, programmatic
		// wp_initialize_site). Load it on demand to avoid an undefined-function fatal.
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active_for_network( plugin_basename( SHAPEFORMS_LITE_FILE ) ) ) {
			return;
		}

		$site_id = $site instanceof \WP_Site ? (int) $site->id : (int) $site;

		switch_to_blog( $site_id );
		self::create_tables();
		update_option( 'shapeforms_lite_db_version', SHAPEFORMS_LITE_DB_VERSION );
		restore_current_blog();
	}

	/**
	 * Creates the plugin database tables for the current blog context.
	 *
	 * Uses $wpdb->prefix so it automatically picks up the correct per-site
	 * table prefix after switch_to_blog() has been called.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$forms_table     = $wpdb->prefix . 'shapeforms_forms';
		$entries_table   = $wpdb->prefix . 'shapeforms_entries';

		$forms_sql = "CREATE TABLE {$forms_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			title varchar(191) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			fields_json longtext NOT NULL,
			settings_json longtext NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY created_by (created_by)
		) {$charset_collate};";

		$entries_sql = "CREATE TABLE {$entries_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL,
			entry_data_json longtext NOT NULL,
			submission_key varchar(64) NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_ip varchar(100) NULL,
			user_agent text NULL,
			status varchar(20) NOT NULL DEFAULT 'unread',
			trashed_at datetime NULL DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY form_id (form_id),
			KEY status (status),
			KEY trashed_at (trashed_at),
			KEY created_at (created_at),
			KEY submission_key (submission_key)
		) {$charset_collate};";

		dbDelta( $forms_sql );
		dbDelta( $entries_sql );

		self::migrate_entry_trash( $entries_table );
	}

	/**
	 * Normalises legacy trash state onto the dedicated `trashed_at` column.
	 *
	 * The trash lifecycle used to be a `status = 'trash'` value, which conflated it
	 * with the read-state (unread/read/starred/spam) and lost the entry's prior status
	 * on restore. Trash now lives in its own nullable timestamp column, so read-state
	 * is preserved. Any row still carrying the old value is converted once — the entry
	 * is stamped as trashed now and its status reset to `read` (its pre-trash status is
	 * unknown for these legacy rows). Idempotent: after conversion no such rows remain.
	 *
	 * @param string $entries_table Fully-qualified entries table name.
	 * @return void
	 */
	private static function migrate_entry_trash( $entries_table ) {
		global $wpdb;

		$safe_table = esc_sql( $entries_table );

		// Only touch rows that still use the legacy value; skips entirely on fresh installs.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE `{$safe_table}` SET trashed_at = %s, status = %s WHERE status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'mysql' ),
				'read',
				'trash'
			)
		);
	}
}
