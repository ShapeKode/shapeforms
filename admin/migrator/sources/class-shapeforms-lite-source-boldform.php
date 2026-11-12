<?php
/**
 * Form Migrator source: BoldForm.
 *
 * ShapeForms grew out of BoldForm, so a BoldForm form is stored in exactly the
 * shape ShapeForms uses (rows -> columns -> fields in `fields_json`, plus a
 * `settings_json` blob) — only the table names differ. Importing is therefore a
 * straight copy through the builder's own sanitizers rather than a conversion,
 * and field IDs survive unchanged, which is what lets the form's entries be
 * copied across too (entry values are keyed by field ID).
 *
 * The source reads BoldForm's tables directly, so it works whether the BoldForm
 * plugin is still active or already deactivated — only deleting BoldForm (with
 * its "remove data on uninstall" setting on) takes the data away.
 *
 * @package ShapeFormsLite
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports BoldForm forms, their entries, and BoldForm's global settings.
 */
class ShapeForms_Lite_Source_BoldForm implements ShapeForms_Lite_Migration_Source {

	/**
	 * Source slug (also the migration-map key prefix: "boldform:{id}").
	 */
	const SLUG = 'boldform';

	/**
	 * Option recording the last BoldForm entry ID copied, per "{boldform id}:{shapeforms id}"
	 * pair, so a re-import only copies entries submitted since the previous import —
	 * and a form re-created after its import was deleted gets every entry again.
	 */
	const ENTRIES_OPTION = 'shapeforms_migration_boldform_entries';

	/**
	 * BoldForm option => ShapeForms option, for the one-time settings copy.
	 *
	 * @var array<string, string>
	 */
	const SETTINGS_OPTIONS = array(
		'boldform_lite_settings'     => 'shapeforms_lite_settings',
		'boldform_connections'       => 'shapeforms_connections',
		'boldform_lite_ai_api_keys'  => 'shapeforms_lite_ai_api_keys',
		'boldform_lite_ai_provider'  => 'shapeforms_lite_ai_provider',
		'boldform_lite_ai_models'    => 'shapeforms_lite_ai_models',
	);

	/**
	 * Cached result of the table-exists check.
	 *
	 * @var bool|null
	 */
	private $available = null;

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return self::SLUG;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return __( 'BoldForm', 'shapeforms' );
	}

	/**
	 * True when BoldForm's forms table exists, active plugin or not.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( null !== $this->available ) {
			return $this->available;
		}

		global $wpdb;

		$table = self::forms_table();

		$this->available = ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $this->available;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_forms() {
		if ( ! $this->is_available() ) {
			return array();
		}

		global $wpdb;

		$table = esc_sql( self::forms_table() );
		$rows  = $wpdb->get_results( "SELECT id, title FROM `{$table}` WHERE status <> 'trash' ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$forms = array();

		foreach ( (array) $rows as $row ) {
			$id      = (int) $row['id'];
			$forms[] = array(
				'id'       => $id,
				'title'    => self::form_title( $row['title'], $id ),
				'imported' => false,
			);
		}

		return $forms;
	}

	/**
	 * {@inheritDoc}
	 */
	public function import_form( $source_id ) {
		$id   = (int) $source_id;
		$form = $this->get_source_form( $id );

		if ( ! $form ) {
			return new WP_Error(
				'shapeforms_migrator_source_missing',
				__( 'That BoldForm form could not be found.', 'shapeforms' )
			);
		}

		$fields   = json_decode( (string) $form['fields_json'], true );
		$settings = json_decode( (string) $form['settings_json'], true );
		$rows     = is_array( $fields ) && isset( $fields['rows'] ) && is_array( $fields['rows'] ) ? $fields['rows'] : array();

		return array(
			'title'     => self::form_title( $form['title'], $id ),
			'rows'      => $rows,
			'settings'  => is_array( $settings ) ? $settings : array(),
			'skipped'   => $this->find_unsupported_fields( $rows ),
			'warnings'  => array(),
			'source_id' => $id,
		);
	}

	/**
	 * Runs after a form was created or updated: copies its entries, and on the
	 * first BoldForm import also copies BoldForm's global settings.
	 *
	 * Called by the migrator controller for sources that define it.
	 *
	 * @param int|string $source_id BoldForm form ID.
	 * @param int        $form_id   ShapeForms form ID it was imported into.
	 * @return array{entries:int, notes:array<int, string>}
	 */
	public function after_import( $source_id, $form_id ) {
		$notes = array();

		$copied = $this->copy_entries( (int) $source_id, (int) $form_id );

		if ( false === $copied ) {
			$notes[] = __( 'Its entries could not be copied.', 'shapeforms' );
			$copied  = 0;
		}

		$settings_note = $this->maybe_copy_settings();

		if ( '' !== $settings_note ) {
			$notes[] = $settings_note;
		}

		return array(
			'entries' => (int) $copied,
			'notes'   => $notes,
		);
	}

	/**
	 * Copies a form's entries that were not copied before.
	 *
	 * Copies every column the two entries tables share (so Pro's extra columns
	 * come across when both sides have them) in one INSERT … SELECT, then records
	 * the highest BoldForm entry ID copied so re-imports never duplicate.
	 *
	 * @param int $source_id BoldForm form ID.
	 * @param int $form_id   ShapeForms form ID.
	 * @return int|false Number of entries copied, or false on a database error.
	 */
	private function copy_entries( $source_id, $form_id ) {
		global $wpdb;

		$source_table = self::entries_table();
		$target_table = $wpdb->prefix . 'shapeforms_entries';

		if ( $source_table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $source_table ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return 0;
		}

		$columns = array_values(
			array_diff(
				array_intersect( self::table_columns( $source_table ), self::table_columns( $target_table ) ),
				array( 'id', 'form_id' )
			)
		);

		if ( ! in_array( 'entry_data_json', $columns, true ) ) {
			return false;
		}

		$progress = get_option( self::ENTRIES_OPTION, array() );
		$progress = is_array( $progress ) ? $progress : array();
		$key      = $source_id . ':' . $form_id;
		$last_id  = isset( $progress[ $key ] ) ? (int) $progress[ $key ] : 0;

		$max_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT MAX(id) FROM `' . esc_sql( $source_table ) . '` WHERE form_id = %d', $source_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		if ( $max_id <= $last_id ) {
			return 0;
		}

		$column_list = '`' . implode( '`, `', array_map( 'esc_sql', $columns ) ) . '`';

		// Column names come from SHOW COLUMNS on our own tables and are escaped;
		// table names are built from $wpdb->prefix. Values are bound placeholders.
		$copied = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'INSERT INTO `' . esc_sql( $target_table ) . "` (form_id, {$column_list}) SELECT %d, {$column_list} FROM `" . esc_sql( $source_table ) . '` WHERE form_id = %d AND id > %d AND id <= %d ORDER BY id ASC', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				$form_id,
				$source_id,
				$last_id,
				$max_id
			)
		);

		if ( false === $copied ) {
			return false;
		}

		$progress[ $key ] = $max_id;
		update_option( self::ENTRIES_OPTION, $progress, false );

		// The admin menu's unread-entries badge is cached.
		delete_transient( 'shapeforms_lite_unread_count' );

		return (int) $copied;
	}

	/**
	 * Copies BoldForm's global settings (SMTP, captcha keys, integrations, AI
	 * keys) the first time, but only into ShapeForms options that are still
	 * unset — settings already saved in ShapeForms are never overwritten.
	 *
	 * @return string A note for the import summary, or '' when nothing to say.
	 */
	private function maybe_copy_settings() {
		static $done = false;

		if ( $done ) {
			return '';
		}

		$done = true;

		$copied  = 0;
		$kept    = 0;
		$missing = new stdClass();

		foreach ( self::SETTINGS_OPTIONS as $from => $to ) {
			$value = get_option( $from, $missing );

			if ( $missing === $value ) {
				continue;
			}

			if ( get_option( $to, $missing ) !== $missing ) {
				++$kept;
				continue;
			}

			add_option( $to, $value, '', false );
			++$copied;
		}

		if ( $copied > 0 ) {
			return __( 'BoldForm\'s global settings (email, SMTP, captcha, integrations) were copied to ShapeForms.', 'shapeforms' );
		}

		if ( $kept > 0 && 0 === $copied ) {
			// Say this once per install, not on every import.
			if ( get_option( 'shapeforms_migration_boldform_settings_noted' ) ) {
				return '';
			}
			update_option( 'shapeforms_migration_boldform_settings_noted', 1, false );

			return __( 'ShapeForms already has its own global settings, so BoldForm\'s were left as they are.', 'shapeforms' );
		}

		return '';
	}

	/**
	 * Lists fields whose type this site can't save (typically Pro field types
	 * while ShapeForms Pro is not active), so the summary can say what was left out.
	 *
	 * @param array<int, mixed> $rows Source rows.
	 * @return array<int, string> Skip notes.
	 */
	private function find_unsupported_fields( $rows ) {
		if ( ! class_exists( 'ShapeForms_Lite_Ajax_Save' ) ) {
			require_once SHAPEFORMS_LITE_PATH . 'admin/ajax-save.php';
		}

		// Ask the real sanitizer which types survive, one probe field per type.
		$types = array();

		foreach ( $rows as $row ) {
			foreach ( ( is_array( $row ) && isset( $row['columns'] ) && is_array( $row['columns'] ) ) ? $row['columns'] : array() as $column ) {
				foreach ( ( is_array( $column ) && isset( $column['fields'] ) && is_array( $column['fields'] ) ) ? $column['fields'] : array() as $field ) {
					$type = is_array( $field ) && isset( $field['type'] ) ? sanitize_key( (string) $field['type'] ) : '';

					if ( '' === $type ) {
						continue;
					}

					$label = is_array( $field ) && isset( $field['label'] ) && '' !== trim( (string) $field['label'] ) ? wp_strip_all_tags( (string) $field['label'] ) : $type;

					$types[ $type ][] = $label;
				}
			}
		}

		$skipped = array();

		foreach ( $types as $type => $labels ) {
			$probe = ShapeForms_Lite_Ajax_Save::prepare_rows(
				array(
					'rows' => array(
						array(
							'columns' => array(
								array(
									'width'  => '100%',
									'fields' => array(
										array(
											'id'   => 'probe',
											'type' => $type,
										),
									),
								),
							),
						),
					),
				)
			);

			// The sanitizer keeps the (now empty) row even when it drops the field,
			// so look for the field itself.
			if ( ! empty( $probe[0]['columns'][0]['fields'] ) ) {
				continue;
			}

			foreach ( $labels as $label ) {
				$skipped[] = sprintf(
					/* translators: 1: field label, 2: field type. */
					__( '"%1$s" (%2$s field) was not imported — this field type needs ShapeForms Pro.', 'shapeforms' ),
					$label,
					$type
				);
			}
		}

		return $skipped;
	}

	/**
	 * Reads one BoldForm form row.
	 *
	 * @param int $id BoldForm form ID.
	 * @return array<string, mixed>|null
	 */
	private function get_source_form( $id ) {
		if ( $id <= 0 || ! $this->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = esc_sql( self::forms_table() );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, title, fields_json, settings_json FROM `{$table}` WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Column names of a table.
	 *
	 * @param string $table Fully-qualified table name.
	 * @return array<int, string>
	 */
	private static function table_columns( $table ) {
		global $wpdb;

		return array_map( 'strval', (array) $wpdb->get_col( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . '`' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * A display title, falling back to "BoldForm #ID" when blank.
	 *
	 * @param string $title Raw title.
	 * @param int    $id    BoldForm form ID.
	 * @return string
	 */
	private static function form_title( $title, $id ) {
		$title = trim( (string) $title );

		/* translators: %d: BoldForm form ID. */
		return '' !== $title ? $title : sprintf( __( 'BoldForm #%d', 'shapeforms' ), $id );
	}

	/**
	 * BoldForm's forms table.
	 *
	 * @return string
	 */
	public static function forms_table() {
		global $wpdb;

		return $wpdb->prefix . 'boldform_forms';
	}

	/**
	 * BoldForm's entries table.
	 *
	 * @return string
	 */
	public static function entries_table() {
		global $wpdb;

		return $wpdb->prefix . 'boldform_entries';
	}

	/**
	 * Keeps old BoldForm embeds working once BoldForm is deactivated.
	 *
	 * When nothing else owns the `[boldform]` shortcode or the `boldform/form`
	 * block, they are registered here and render the ShapeForms form that BoldForm
	 * form was imported into. A form that was never imported renders nothing, as
	 * it would with BoldForm gone. Hooked late on `init` so an active BoldForm
	 * always keeps its own handlers.
	 *
	 * @return void
	 */
	public static function register_legacy_embeds() {
		if ( ! shortcode_exists( 'boldform' ) ) {
			add_shortcode( 'boldform', array( __CLASS__, 'render_legacy_shortcode' ) );
		}

		if ( function_exists( 'register_block_type' ) && class_exists( 'WP_Block_Type_Registry' ) && ! WP_Block_Type_Registry::get_instance()->is_registered( 'boldform/form' ) ) {
			register_block_type(
				'boldform/form',
				array(
					'render_callback' => array( __CLASS__, 'render_legacy_block' ),
					'attributes'      => array(
						'formId' => array(
							'type'    => 'number',
							'default' => 0,
						),
					),
				)
			);
		}
	}

	/**
	 * Renders `[boldform id="N"]` as its imported ShapeForms form.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function render_legacy_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), (array) $atts, 'boldform' );

		return self::render_mapped_form( absint( $atts['id'] ) );
	}

	/**
	 * Renders the legacy `boldform/form` block as its imported ShapeForms form.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public static function render_legacy_block( $attributes ) {
		return self::render_mapped_form( isset( $attributes['formId'] ) ? absint( $attributes['formId'] ) : 0 );
	}

	/**
	 * Renders the ShapeForms form a BoldForm form was imported into.
	 *
	 * @param int $boldform_id BoldForm form ID.
	 * @return string
	 */
	private static function render_mapped_form( $boldform_id ) {
		if ( $boldform_id <= 0 ) {
			return '';
		}

		$map = get_option( ShapeForms_Lite_Migrator::MAP_OPTION, array() );
		$key = self::SLUG . ':' . $boldform_id;

		if ( ! is_array( $map ) || empty( $map[ $key ] ) ) {
			return '';
		}

		return do_shortcode( '[shapeforms id="' . absint( $map[ $key ] ) . '"]' );
	}
}
