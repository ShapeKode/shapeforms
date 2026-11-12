<?php
/**
 * Value object describing the outcome of migrating one source form.
 *
 * @package ShapeFormsLite
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The result of importing a single form: which ShapeForms form was
 * created/updated, plus any fields or features that could not be carried over.
 */
class ShapeForms_Lite_Migration_Result {

	/**
	 * The resulting ShapeForms form ID (0 when the import failed).
	 *
	 * @var int
	 */
	public $form_id = 0;

	/**
	 * The imported form's title.
	 *
	 * @var string
	 */
	public $title = '';

	/**
	 * Whether an existing migrated form was updated (true) or a new one created (false).
	 *
	 * @var bool
	 */
	public $updated = false;

	/**
	 * Human-readable notes about fields or features that were not migrated.
	 *
	 * @var array<int, string>
	 */
	public $skipped = array();

	/**
	 * Non-fatal warnings surfaced to the admin.
	 *
	 * @var array<int, string>
	 */
	public $warnings = array();

	/**
	 * Number of entries copied along with the form (sources that support it).
	 *
	 * @var int
	 */
	public $entries = 0;

	/**
	 * Informational notes from the source's after-import step.
	 *
	 * @var array<int, string>
	 */
	public $notes = array();

	/**
	 * A fatal error, when the form could not be imported at all.
	 *
	 * @var WP_Error|null
	 */
	public $error = null;

	/**
	 * Constructor.
	 *
	 * @param string $title Source form title.
	 */
	public function __construct( $title = '' ) {
		$this->title = (string) $title;
	}

	/**
	 * Whether the import succeeded (a form was created or updated).
	 *
	 * @return bool
	 */
	public function is_success() {
		return $this->form_id > 0 && ! is_wp_error( $this->error );
	}
}
