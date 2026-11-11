<?php
/**
 * Gutenberg block integration.
 *
 * @package ShapeFormsLite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the ShapeForms block.
 */
class ShapeForms_Lite_Block {

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
	 * Registers the block and editor script.
	 *
	 * @return void
	 */
	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'shapeforms-lite-block-editor',
			SHAPEFORMS_LITE_URL . 'assets/js/block-form.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n', 'wp-server-side-render' ),
			SHAPEFORMS_LITE_VERSION,
			true
		);

		if ( $this->has_script_translation_files() ) {
			wp_set_script_translations( 'shapeforms-lite-block-editor', 'shapeforms', SHAPEFORMS_LITE_PATH . 'languages' );
		}

		// Register the form styles so they can be attached as the block's editor styles
		// below. With apiVersion 3 the editor canvas is iframed (WP 6.3+), and assets
		// enqueued on enqueue_block_editor_assets do NOT reach the iframe — so the
		// server-side-rendered block preview would render unstyled. editor_style_handles
		// loads these inside the iframe. They are editor-only; the front end continues to
		// enqueue 'shapeforms-lite-frontend' via the shortcode, so front-end output is unchanged.
		if ( ! wp_style_is( 'shapeforms-lite-frontend', 'registered' ) ) {
			wp_register_style( 'shapeforms-lite-frontend', SHAPEFORMS_LITE_URL . 'assets/css/frontend.css', array(), SHAPEFORMS_LITE_VERSION );
		}
		if ( ! wp_style_is( 'shapeforms-lite-flatpickr', 'registered' ) ) {
			wp_register_style( 'shapeforms-lite-flatpickr', SHAPEFORMS_LITE_URL . 'assets/css/flatpickr.min.css', array(), '4.6.13' );
		}

		// Register the block explicitly to avoid metadata path resolution passing
		// unexpected null values into WordPress core on some environments.
		$block_attributes = array(
			'formId'              => array( 'type' => 'number', 'default' => 0 ),
			'hideLabels'          => array( 'type' => 'boolean', 'default' => false ),
			'hidePlaceholders'    => array( 'type' => 'boolean', 'default' => false ),
			'formMaxWidth'        => array( 'type' => 'string', 'default' => '' ),
			'formPadding'         => array( 'type' => 'string', 'default' => '' ),
			'formBgColor'         => array( 'type' => 'string', 'default' => '' ),
			'formBorderRadius'    => array( 'type' => 'string', 'default' => '' ),
			'formBorderWidth'     => array( 'type' => 'string', 'default' => '' ),
			'formBorderColor'     => array( 'type' => 'string', 'default' => '' ),
			'rowGap'              => array( 'type' => 'string', 'default' => '' ),
			'columnGap'           => array( 'type' => 'string', 'default' => '' ),
			'fieldGap'            => array( 'type' => 'string', 'default' => '' ),
			'labelColor'          => array( 'type' => 'string', 'default' => '' ),
			'labelFontSize'       => array( 'type' => 'string', 'default' => '' ),
			'fieldHeight'         => array( 'type' => 'string', 'default' => '' ),
			'textareaHeight'      => array( 'type' => 'string', 'default' => '' ),
			'fieldPadding'        => array( 'type' => 'string', 'default' => '' ),
			'fieldBgColor'        => array( 'type' => 'string', 'default' => '' ),
			'fieldTextColor'      => array( 'type' => 'string', 'default' => '' ),
			'fieldBorderWidth'    => array( 'type' => 'string', 'default' => '' ),
			'fieldBorderColor'    => array( 'type' => 'string', 'default' => '' ),
			'fieldBorderRadius'   => array( 'type' => 'string', 'default' => '' ),
			'fieldFocusColor'     => array( 'type' => 'string', 'default' => '' ),
			'fieldFocusBgColor'   => array( 'type' => 'string', 'default' => '' ),
			'buttonPadding'       => array( 'type' => 'string', 'default' => '' ),
			'buttonBgColor'       => array( 'type' => 'string', 'default' => '' ),
			'buttonTextColor'     => array( 'type' => 'string', 'default' => '' ),
			'buttonHoverBgColor'  => array( 'type' => 'string', 'default' => '' ),
			'buttonBorderRadius'  => array( 'type' => 'string', 'default' => '' ),
			'buttonFontSize'      => array( 'type' => 'string', 'default' => '' ),
			'buttonFullWidth'     => array( 'type' => 'boolean', 'default' => false ),
			'errorColor'               => array( 'type' => 'string', 'default' => '' ),
			'requiredIndicatorColor'   => array( 'type' => 'string', 'default' => '' ),
		);

		/**
		 * Filter the Gutenberg block attributes for the ShapeForms block.
		 *
		 * Pro can add attributes for multi-step styling, payment fields, etc.
		 *
		 * @param array<string, array<string, mixed>> $block_attributes Block attribute definitions.
		 */
		$block_attributes = apply_filters( 'shapeforms_block_attributes', $block_attributes );

		/**
		 * Filter the stylesheet handles loaded inside an editor canvas that renders a
		 * real form (the iframed block editor, the Elementor preview).
		 *
		 * The iframe loads ONLY these handles: assets enqueued while the block's
		 * render_callback runs are discarded with the ServerSideRender response, so a
		 * field type whose markup is styled by an extension renders raw unless its
		 * handle is added here. Callbacks must wp_register_style() what they add.
		 *
		 * @param array<int, string> $handles Registered stylesheet handles.
		 */
		$editor_style_handles = apply_filters(
			'shapeforms_preview_style_handles',
			array( 'shapeforms-lite-frontend', 'shapeforms-lite-flatpickr' )
		);

		register_block_type(
			'shapeforms/form',
			array(
				'api_version'          => 3,
				'editor_script'        => 'shapeforms-lite-block-editor',
				'editor_style_handles' => array_values( array_unique( array_filter( array_map( 'strval', (array) $editor_style_handles ) ) ) ),
				'render_callback'      => array( $this, 'render_block' ),
				'attributes'           => $block_attributes,
				'supports'             => array(
					'html' => false,
				),
			)
		);
	}

	/**
	 * Enqueues editor data for the block dropdown.
	 *
	 * @return void
	 */
	public function enqueue_editor_assets() {
		wp_enqueue_style(
			'shapeforms-lite-frontend',
			SHAPEFORMS_LITE_URL . 'assets/css/frontend.css',
			array(),
			SHAPEFORMS_LITE_VERSION
		);

		// Load Flatpickr + frontend behaviour so date/time fields render their picker in the
		// server-side-rendered block preview (matching the live frontend).
		if ( ! wp_style_is( 'shapeforms-lite-flatpickr', 'registered' ) ) {
			wp_register_style( 'shapeforms-lite-flatpickr', SHAPEFORMS_LITE_URL . 'assets/css/flatpickr.min.css', array(), '4.6.13' );
		}
		if ( ! wp_script_is( 'shapeforms-lite-flatpickr', 'registered' ) ) {
			wp_register_script( 'shapeforms-lite-flatpickr', SHAPEFORMS_LITE_URL . 'assets/js/flatpickr.min.js', array(), '4.6.13', true );
		}
		if ( ! wp_script_is( 'shapeforms-lite-frontend', 'registered' ) ) {
			wp_register_script( 'shapeforms-lite-frontend', SHAPEFORMS_LITE_URL . 'assets/js/frontend.js', array( 'jquery' ), SHAPEFORMS_LITE_VERSION, true );
		}
		wp_enqueue_style( 'shapeforms-lite-flatpickr' );
		wp_enqueue_script( 'shapeforms-lite-flatpickr' );
		wp_enqueue_script( 'shapeforms-lite-frontend' );
		wp_localize_script(
			'shapeforms-lite-frontend',
			'shapeformsLiteFrontend',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'ajaxAction'     => 'shapeforms_lite_submit_form',
				'submittingText' => __( 'Submitting...', 'shapeforms' ),
				'successText'    => __( 'Form submitted successfully.', 'shapeforms' ),
				'errorText'      => __( 'Unable to submit the form.', 'shapeforms' ),
				'invalidEmail'   => __( 'Please enter a valid email address.', 'shapeforms' ),
			)
		);

		$block_data = array(
			'forms'        => $this->get_form_options(),
			'placeholder'  => __( 'Select a form', 'shapeforms' ),
			'emptyMessage' => __( 'No published forms found.', 'shapeforms' ),
			'previewText'  => __( 'Selected form will render on the frontend.', 'shapeforms' ),
			'builderUrl'   => admin_url( 'admin.php?page=shapeforms-lite-builder&form_id=' ),
		);

		/**
		 * Filter the data passed to the Gutenberg block editor JS.
		 *
		 * Pro can add panel configs, multi-step options, etc.
		 *
		 * @param array<string, mixed> $block_data Editor data for shapeformsLiteBlock.
		 */
		$block_data = apply_filters( 'shapeforms_block_editor_data', $block_data );

		wp_localize_script( 'shapeforms-lite-block-editor', 'shapeformsLiteBlock', $block_data );

		/**
		 * Fires after ShapeForms block editor assets are enqueued.
		 *
		 * Pro can enqueue additional editor scripts for multi-step controls.
		 */
		do_action( 'shapeforms_block_editor_enqueue' );
	}

	/**
	 * Renders the dynamic block on the frontend.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_block( $attributes ) {
		$form_id = isset( $attributes['formId'] ) ? absint( $attributes['formId'] ) : 0;

		if ( ! $form_id ) {
			return '';
		}

		$html  = do_shortcode( sprintf( '[shapeforms id="%d"]', $form_id ) );
		$style = $this->build_block_inline_style( $attributes );

		// Build CSS classes for display overrides.
		$classes = array( 'shapeforms-block-wrap' );
		if ( ! empty( $attributes['hideLabels'] ) ) {
			$classes[] = 'shapeforms-hide-labels';
		}
		if ( ! empty( $attributes['hidePlaceholders'] ) ) {
			$classes[] = 'shapeforms-hide-ph-yes';
		}

		$needs_wrap = $style || count( $classes ) > 1;

		if ( $needs_wrap ) {
			// $html is the fully pre-escaped output of render_shortcode() via do_shortcode().
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"' . ( $style ? ' style="' . esc_attr( $style ) . '"' : '' ) . '>' . $html . '</div>';
		}

		// $html is the fully pre-escaped output of render_shortcode() via do_shortcode().
		return $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Builds inline CSS custom properties from block attributes.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	private function build_block_inline_style( $attributes ) {
		$map = array(
			'formMaxWidth'      => '--bfb-form-max-width',
			'formPadding'       => '--bfb-form-padding',
			'formBgColor'       => '--bfb-form-bg',
			'formBorderRadius'  => '--bfb-form-radius',
			'formBorderWidth'   => '--bfb-form-border-width',
			'formBorderColor'   => '--bfb-form-border-color',
			'rowGap'            => '--bfb-row-gap',
			'columnGap'         => '--bfb-col-gap',
			'fieldGap'          => '--bfb-field-gap',
			'labelColor'        => '--bfb-label-color',
			'labelFontSize'     => '--bfb-label-font-size',
			'fieldHeight'       => '--bfb-field-height',
			'textareaHeight'    => '--bfb-textarea-height',
			'fieldPadding'      => '--bfb-field-padding',
			'fieldBgColor'      => '--bfb-field-bg',
			'fieldTextColor'    => '--bfb-field-text',
			'fieldBorderWidth'  => '--bfb-field-border-width',
			'fieldBorderColor'  => '--bfb-field-border-color',
			'fieldBorderRadius' => '--bfb-field-radius',
			'fieldFocusColor'   => '--bfb-focus-color',
			'fieldFocusBgColor' => '--bfb-focus-bg',
			'buttonPadding'     => '--bfb-btn-padding',
			'buttonBgColor'     => '--bfb-btn-bg',
			'buttonTextColor'   => '--bfb-btn-text',
			'buttonHoverBgColor'=> '--bfb-btn-hover-bg',
			'buttonBorderRadius'=> '--bfb-btn-radius',
			'buttonFontSize'    => '--bfb-btn-font-size',
			'errorColor'             => '--bfb-error-color',
			'requiredIndicatorColor' => '--bfb-required-color',
		);

		$vars = array();

		foreach ( $map as $attr => $var ) {
			if ( ! empty( $attributes[ $attr ] ) ) {
				// Strip characters that could terminate this declaration and inject
				// extra CSS properties into the shared inline style attribute. Legitimate
				// color/length values never contain ; { or }.
				$value  = str_replace( array( ';', '{', '}' ), '', sanitize_text_field( (string) $attributes[ $attr ] ) );
				$vars[] = $var . ':' . $value;
			}
		}

		if ( ! empty( $attributes['buttonFullWidth'] ) ) {
			$vars[] = '--bfb-btn-width:100%';
		}

		return implode( ';', $vars );
	}

	/**
	 * Returns published forms for the block dropdown.
	 *
	 * @return array<int, array<string, string|int>>
	 */
	private function get_form_options() {
		global $wpdb;

		$table_name = $this->plugin->get_forms_table_name();
		$cache_key  = 'shapeforms_lite_block_form_options';
		$forms      = wp_cache_get( $cache_key, 'shapeforms_lite' );
		$options    = array();
		$safe_table = esc_sql( $table_name );

		if ( false === $forms ) {
			$forms = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->prepare(
					"SELECT id, title FROM `{$safe_table}` WHERE status = %s ORDER BY title ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'publish'
				)
			);
			wp_cache_set( $cache_key, $forms, 'shapeforms_lite', MINUTE_IN_SECONDS );
		}

		foreach ( $forms as $form ) {
			$options[] = array(
				'value' => (int) $form->id,
				/* translators: %d: form ID number */
				'label' => $form->title ? (string) $form->title : sprintf( __( 'Form #%d', 'shapeforms' ), (int) $form->id ),
			);
		}

		return $options;
	}

	/**
	 * Determines whether script translation JSON files exist.
	 *
	 * @return bool
	 */
	private function has_script_translation_files() {
		$translation_files = glob( SHAPEFORMS_LITE_PATH . 'languages/*.json' );

		return ! empty( $translation_files );
	}
}
