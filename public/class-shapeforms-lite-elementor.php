<?php
/**
 * Elementor integration bootstrap.
 *
 * @package ShapeFormsLite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers ShapeForms Elementor widgets.
 */
class ShapeForms_Lite_Elementor {

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
	 * Registers the ShapeForms Elementor widget.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Elementor widgets manager.
	 * @return void
	 */
	public function register_widget( $widgets_manager ) {
		if ( ! did_action( 'elementor/loaded' ) ) {
			return;
		}

		require_once SHAPEFORMS_LITE_PATH . 'public/class-shapeforms-lite-elementor-widget.php';

		$widgets_manager->register( new ShapeForms_Lite_Elementor_Widget( array(), null, $this->plugin ) );
	}

	/**
	 * Enqueues JS for the Elementor editor panel (not the preview iframe).
	 *
	 * @return void
	 */
	public function enqueue_editor_scripts() {
		wp_enqueue_script(
			'shapeforms-elementor-editor',
			SHAPEFORMS_LITE_URL . 'assets/js/elementor-editor.js',
			array( 'jquery' ),
			SHAPEFORMS_LITE_VERSION,
			true
		);

		wp_localize_script(
			'shapeforms-elementor-editor',
			'shapeformsElementorEditor',
			array(
				'builderBase' => admin_url( 'admin.php?page=shapeforms-lite-builder&form_id=' ),
			)
		);
	}
}
