<?php
/**
 * Contract for a Form Migrator source.
 *
 * Each supported source plugin (Contact Form 7 first; WPForms, Gravity, etc.
 * later) implements this interface. The migrator controller stays
 * source-agnostic: it lists sources, asks each for its forms, converts one
 * form at a time, and feeds the plain rows/settings through the builder's own
 * save-path sanitizers before inserting.
 *
 * @package ShapeFormsLite
 * @since   1.1.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A pluggable importer for one external form plugin.
 */
interface ShapeForms_Lite_Migration_Source {

	/**
	 * Machine slug for this source, e.g. 'cf7'.
	 *
	 * Used in URLs, nonces and the migration marker, so keep it stable.
	 *
	 * @return string Lowercase slug ([a-z0-9_-]).
	 */
	public function get_slug();

	/**
	 * Human, translatable label, e.g. 'Contact Form 7'.
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Whether this source's plugin or data is present.
	 *
	 * A source only renders a tab when this is true.
	 *
	 * @return bool
	 */
	public function is_available();

	/**
	 * Lists this source's forms for the migrator table.
	 *
	 * Keep this a light query (id + title only); the full structure is parsed
	 * lazily in import_form().
	 *
	 * @return array<int, array{id:(int|string), title:string, imported:bool}>
	 */
	public function get_forms();

	/**
	 * Converts ONE source form into a ShapeForms structure (pre-sanitize).
	 *
	 * The returned rows/settings are plain PHP, fed to
	 * ShapeForms_Lite_Ajax_Save::prepare_rows() / ::normalize_form_settings()
	 * by the controller — this method must never hand-write fields_json.
	 *
	 * @param int|string $source_id Source form identifier from get_forms().
	 * @return array{
	 *     title:string,
	 *     rows:array<int, array<string, mixed>>,
	 *     settings:array<string, mixed>,
	 *     skipped:array<int, string>,
	 *     source_id:(int|string)
	 * }|WP_Error Structure to import, or a WP_Error when the form can't be read.
	 */
	public function import_form( $source_id );
}
