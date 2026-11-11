<?php
/**
 * Elementor ShapeForms widget.
 *
 * @package ShapeFormsLite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ShapeForms Elementor widget class.
 */
class ShapeForms_Lite_Elementor_Widget extends \Elementor\Widget_Base {

	/**
	 * Main plugin instance.
	 *
	 * @var ShapeForms_Lite
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>       $data Initial widget data.
	 * @param array<string, mixed>|null  $args Extra widget args.
	 * @param ShapeForms_Lite|null         $plugin Plugin instance.
	 */
	public function __construct( $data = array(), $args = null, $plugin = null ) {
		$this->plugin = $plugin instanceof ShapeForms_Lite ? $plugin : null;

		parent::__construct( $data, $args );
	}

	/**
	 * Widget slug.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'shapeforms';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'ShapeForms', 'shapeforms' );
	}

	/**
	 * Widget icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-form-horizontal';
	}

	/**
	 * Widget categories.
	 *
	 * @return array<int, string>
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/**
	 * Returns style dependencies for Elementor preview/editor.
	 *
	 * @return array<int, string>
	 */
	public function get_style_depends() {
		$this->register_frontend_assets();

		// Same contract as the block editor: Elementor re-renders a widget over AJAX,
		// where an enqueue made during render() never reaches the page. Anything that
		// styles a rendered field has to be declared as a dependency here instead.
		/** This filter is documented in public/class-shapeforms-lite-block.php */
		$handles = apply_filters(
			'shapeforms_preview_style_handles',
			array( 'shapeforms-lite-frontend', 'shapeforms-lite-flatpickr' )
		);

		return array_values( array_unique( array_filter( array_map( 'strval', (array) $handles ) ) ) );
	}

	/**
	 * Returns script dependencies for Elementor preview/editor.
	 *
	 * @return array<int, string>
	 */
	public function get_script_depends() {
		$this->register_frontend_assets();

		/**
		 * Filter the script handles loaded into an editor canvas that renders a real
		 * form. Unlike the block editor (whose preview is static HTML), the Elementor
		 * preview is live, so field types with their own behaviour — repeater rows,
		 * signature pads — need their scripts declared here to work while editing.
		 *
		 * Callbacks must wp_register_script() what they add.
		 *
		 * @param array<int, string> $handles Registered script handles.
		 */
		$handles = apply_filters(
			'shapeforms_preview_script_handles',
			array( 'shapeforms-lite-flatpickr', 'shapeforms-lite-frontend' )
		);

		return array_values( array_unique( array_filter( array_map( 'strval', (array) $handles ) ) ) );
	}

	/**
	 * Registers widget controls.
	 *
	 * @return void
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'ShapeForms', 'shapeforms' ),
			)
		);

		$this->add_control(
			'form_id',
			array(
				'label'   => __( 'Select Form', 'shapeforms' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => $this->get_form_options(),
				'default' => '0',
			)
		);

		$this->add_control(
			'edit_form_link',
			array(
				'type'            => \Elementor\Controls_Manager::RAW_HTML,
				'raw'             => '<a href="#" class="shapeforms-edit-form-link" target="_blank" style="display:inline-flex;align-items:center;gap:4px;color:#2f80ed;font-weight:500;font-size:13px;text-decoration:none;"><span class="dashicons dashicons-edit" style="font-size:14px;width:14px;height:14px;"></span>' . esc_html__( 'Edit this form in builder', 'shapeforms' ) . '</a>',
				'content_classes' => 'shapeforms-elementor-edit-link',
				'condition'       => array( 'form_id!' => '0' ),
			)
		);

		$this->add_control(
			'hide_labels',
			array(
				'label'        => __( 'Hide Labels', 'shapeforms' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => '',
				'return_value' => 'yes',
				'selectors'    => array(
					'{{WRAPPER}} .shapeforms-lite-form__label' => 'display: none !important;',
				),
			)
		);

		$this->add_control(
			'hide_placeholders',
			array(
				'label'        => __( 'Hide Placeholders', 'shapeforms' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => '',
				'return_value' => 'yes',
				'prefix_class' => 'shapeforms-hide-ph-',
			)
		);

		$this->end_controls_section();

		// ── Style: Form Container ──
		$this->start_controls_section(
			'section_style_form',
			array(
				'label' => __( 'Form Container', 'shapeforms' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'form_max_width',
			array(
				'label'      => __( 'Max Width', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array(
					'px' => array( 'min' => 200, 'max' => 1400 ),
					'%'  => array( 'min' => 10, 'max' => 100 ),
				),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form' => 'max-width: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'form_align',
			array(
				'label'                => __( 'Alignment', 'shapeforms' ),
				'type'                 => \Elementor\Controls_Manager::CHOOSE,
				'options'              => array(
					'left'    => array(
						'title' => __( 'Left', 'shapeforms' ),
						'icon'  => 'eicon-text-align-left',
					),
					'center'  => array(
						'title' => __( 'Center', 'shapeforms' ),
						'icon'  => 'eicon-text-align-center',
					),
					'right'   => array(
						'title' => __( 'Right', 'shapeforms' ),
						'icon'  => 'eicon-text-align-right',
					),
					'justify' => array(
						'title' => __( 'Full Width', 'shapeforms' ),
						'icon'  => 'eicon-text-align-justify',
					),
				),
				'selectors_dictionary' => array(
					'left'    => 'margin-left: 0; margin-right: auto;',
					'center'  => 'margin-left: auto; margin-right: auto;',
					'right'   => 'margin-left: auto; margin-right: 0;',
					'justify' => 'margin-left: 0; margin-right: 0; max-width: 100%;',
				),
				'selectors'            => array(
					'{{WRAPPER}} .shapeforms-lite-form' => '{{VALUE}}',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Background::get_type(),
			array(
				'name'     => 'form_background',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .shapeforms-lite-form',
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'form_border',
				'selector' => '{{WRAPPER}} .shapeforms-lite-form',
			)
		);

		$this->add_responsive_control(
			'form_border_radius',
			array(
				'label'      => __( 'Border Radius', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'form_padding',
			array(
				'label'      => __( 'Padding', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'form_shadow_heading',
			array(
				'label'     => __( 'Box Shadow', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->start_controls_tabs( 'form_shadow_tabs' );

		$this->start_controls_tab( 'form_shadow_normal', array( 'label' => __( 'Normal', 'shapeforms' ) ) );
		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'form_box_shadow',
				'selector' => '{{WRAPPER}} .shapeforms-lite-form',
			)
		);
		$this->end_controls_tab();

		$this->start_controls_tab( 'form_shadow_hover', array( 'label' => __( 'Hover', 'shapeforms' ) ) );
		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'form_box_shadow_hover',
				'selector' => '{{WRAPPER}} .shapeforms-lite-form:hover',
			)
		);
		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->end_controls_section();

		// ── Style: Layout / Spacing ──
		$this->start_controls_section(
			'section_style_layout',
			array(
				'label' => __( 'Layout & Spacing', 'shapeforms' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'row_gap',
			array(
				'label'      => __( 'Row Gap', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array(
					'px' => array( 'min' => 0, 'max' => 100 ),
					'em' => array( 'min' => 0, 'max' => 6, 'step' => 0.1 ),
				),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form__fields' => 'row-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'column_gap',
			array(
				'label'      => __( 'Column Gap', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 80 ) ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form__row'    => 'margin-left: -{{SIZE}}px; margin-right: -{{SIZE}}px;',
					'{{WRAPPER}} .shapeforms-lite-form__column' => 'padding-left: {{SIZE}}px; padding-right: {{SIZE}}px;',
				),
			)
		);

		$this->add_responsive_control(
			'subfield_gap',
			array(
				'label'      => __( 'Sub-field Gap', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array(
					'px' => array( 'min' => 0, 'max' => 60 ),
					'em' => array( 'min' => 0, 'max' => 4, 'step' => 0.1 ),
				),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form' => '--bf-subfield-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'field_margin',
			array(
				'label'      => __( 'Field Margin', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form__field' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'input_margin',
			array(
				'label'      => __( 'Input Margin', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form__field input:not([type="checkbox"]):not([type="radio"]), {{WRAPPER}} .shapeforms-lite-form__field textarea, {{WRAPPER}} .shapeforms-lite-form__field select, {{WRAPPER}} .shapeforms-lite-form__field .bf-select__trigger' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		// ── Style: Labels ──
		$this->start_controls_section(
			'section_style_labels',
			array(
				'label' => __( 'Labels', 'shapeforms' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'label_typography',
				'selector' => '{{WRAPPER}} .shapeforms-lite-form__label',
			)
		);

		$this->add_responsive_control(
			'label_margin',
			array(
				'label'      => __( 'Margin', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form__label' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'required_indicator_color',
			array(
				'label'     => __( 'Required Indicator Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form__required' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'      => 'sublabel_typography',
				'label'     => __( 'Sub-label Typography', 'shapeforms' ),
				'selector'  => '{{WRAPPER}} .shapeforms-lite-name__sub',
				'separator' => 'before',
			)
		);

		$this->add_control(
			'label_colors_heading',
			array(
				'label'     => __( 'Colors', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->start_controls_tabs( 'label_color_tabs' );

		$this->start_controls_tab( 'label_color_normal', array( 'label' => __( 'Normal', 'shapeforms' ) ) );
		$this->add_control(
			'label_color',
			array(
				'label'     => __( 'Label Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form__label' => 'color: {{VALUE}};',
				),
			)
		);
		$this->add_control(
			'sublabel_color',
			array(
				'label'     => __( 'Sub-label Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form' => '--bf-sublabel-color: {{VALUE}};',
				),
			)
		);
		$this->end_controls_tab();

		$this->start_controls_tab( 'label_color_focus', array( 'label' => __( 'Focus', 'shapeforms' ) ) );
		$this->add_control(
			'label_focus_color',
			array(
				'label'     => __( 'Label Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form__field:focus-within > .shapeforms-lite-form__label' => 'color: {{VALUE}};',
				),
			)
		);
		$this->add_control(
			'sublabel_focus_color',
			array(
				'label'     => __( 'Sub-label Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-name__field:focus-within .shapeforms-lite-name__sub' => 'color: {{VALUE}};',
				),
			)
		);
		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->end_controls_section();

		// ── Style: Input Fields ──
		$this->start_controls_section(
			'section_style_fields',
			array(
				'label' => __( 'Input Fields', 'shapeforms' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'field_height',
			array(
				'label'      => __( 'Height', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 30, 'max' => 80 ) ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form__field input:not([type="checkbox"]):not([type="radio"]), {{WRAPPER}} .shapeforms-lite-form .bf-select__trigger, {{WRAPPER}} .shapeforms-lite-form select' => 'height: {{SIZE}}{{UNIT}}; min-height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'textarea_height',
			array(
				'label'      => __( 'Textarea Height', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 60, 'max' => 400 ) ),
				'default'    => array( 'size' => 120, 'unit' => 'px' ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form__field textarea' => 'height: {{SIZE}}{{UNIT}}; min-height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'field_border_radius',
			array(
				'label'      => __( 'Border Radius', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form__field input:not([type="checkbox"]):not([type="radio"]), {{WRAPPER}} .shapeforms-lite-form__field textarea' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'field_padding',
			array(
				'label'      => __( 'Padding', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'separator'  => 'before',
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form__field input:not([type="checkbox"]):not([type="radio"]), {{WRAPPER}} .shapeforms-lite-form__field textarea' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'field_typography',
				'selector' => '{{WRAPPER}} .shapeforms-lite-form__field input:not([type="checkbox"]):not([type="radio"]), {{WRAPPER}} .shapeforms-lite-form__field textarea',
			)
		);

		$this->add_control(
			'field_states_heading',
			array(
				'label'     => __( 'Colors & Border', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->start_controls_tabs( 'field_state_tabs' );

		$this->start_controls_tab( 'field_state_normal', array( 'label' => __( 'Normal', 'shapeforms' ) ) );
		$this->add_group_control(
			\Elementor\Group_Control_Background::get_type(),
			array(
				'name'     => 'field_background',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .shapeforms-lite-form__field input:not([type="checkbox"]):not([type="radio"]), {{WRAPPER}} .shapeforms-lite-form__field textarea',
			)
		);
		$this->add_control(
			'field_text_color',
			array(
				'label'     => __( 'Text Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form__field input:not([type="checkbox"]):not([type="radio"]), {{WRAPPER}} .shapeforms-lite-form__field textarea' => 'color: {{VALUE}};',
				),
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'field_border',
				'selector' => '{{WRAPPER}} .shapeforms-lite-form__field input:not([type="checkbox"]):not([type="radio"]), {{WRAPPER}} .shapeforms-lite-form__field textarea',
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'field_box_shadow',
				'selector' => '{{WRAPPER}} .shapeforms-lite-form__field input:not([type="checkbox"]):not([type="radio"]), {{WRAPPER}} .shapeforms-lite-form__field textarea',
			)
		);
		$this->end_controls_tab();

		$this->start_controls_tab( 'field_state_hover', array( 'label' => __( 'Hover', 'shapeforms' ) ) );
		$this->add_group_control(
			\Elementor\Group_Control_Background::get_type(),
			array(
				'name'     => 'field_hover_background',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .shapeforms-lite-form input:not([type="checkbox"]):not([type="radio"]):not([type="range"]):hover, {{WRAPPER}} .shapeforms-lite-form textarea:hover, {{WRAPPER}} .shapeforms-lite-form select:hover',
			)
		);
		$this->add_control(
			'field_hover_text_color',
			array(
				'label'     => __( 'Text Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form input:not([type="checkbox"]):not([type="radio"]):not([type="range"]):hover, {{WRAPPER}} .shapeforms-lite-form textarea:hover, {{WRAPPER}} .shapeforms-lite-form select:hover' => 'color: {{VALUE}};',
				),
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'field_hover_border',
				'selector' => '{{WRAPPER}} .shapeforms-lite-form input:not([type="checkbox"]):not([type="radio"]):not([type="range"]):hover, {{WRAPPER}} .shapeforms-lite-form textarea:hover, {{WRAPPER}} .shapeforms-lite-form select:hover',
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'field_hover_box_shadow',
				'selector' => '{{WRAPPER}} .shapeforms-lite-form input:not([type="checkbox"]):not([type="radio"]):not([type="range"]):hover, {{WRAPPER}} .shapeforms-lite-form textarea:hover, {{WRAPPER}} .shapeforms-lite-form select:hover',
			)
		);
		$this->end_controls_tab();

		$this->start_controls_tab( 'field_state_focus', array( 'label' => __( 'Focus', 'shapeforms' ) ) );
		$this->add_control(
			'field_focus_bg_color',
			array(
				'label'     => __( 'Background Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form input:not([type="checkbox"]):not([type="radio"]):not([type="range"]):focus, {{WRAPPER}} .shapeforms-lite-form textarea:focus, {{WRAPPER}} .shapeforms-lite-form select:focus' => 'background-color: {{VALUE}};',
				),
			)
		);
		$this->add_control(
			'field_focus_text_color',
			array(
				'label'     => __( 'Text Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form input:not([type="checkbox"]):not([type="radio"]):not([type="range"]):focus, {{WRAPPER}} .shapeforms-lite-form textarea:focus, {{WRAPPER}} .shapeforms-lite-form select:focus' => 'color: {{VALUE}};',
				),
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'field_focus_border',
				'selector' => '{{WRAPPER}} .shapeforms-lite-form input:not([type="checkbox"]):not([type="radio"]):not([type="range"]):focus, {{WRAPPER}} .shapeforms-lite-form textarea:focus, {{WRAPPER}} .shapeforms-lite-form select:focus',
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'field_box_shadow_focus',
				'selector' => '{{WRAPPER}} .shapeforms-lite-form__field input:not([type="checkbox"]):not([type="radio"]):focus, {{WRAPPER}} .shapeforms-lite-form__field textarea:focus',
			)
		);
		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->end_controls_section();

		// ── Style: Placeholder ──
		$this->start_controls_section(
			'section_style_placeholder',
			array(
				'label' => __( 'Placeholder', 'shapeforms' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'placeholder_color',
			array(
				'label'     => __( 'Placeholder Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form input::placeholder' => 'color: {{VALUE}};',
					'{{WRAPPER}} .shapeforms-lite-form textarea::placeholder' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'placeholder_typography',
				'selector' => '{{WRAPPER}} .shapeforms-lite-form input::placeholder, {{WRAPPER}} .shapeforms-lite-form textarea::placeholder',
			)
		);

		$this->end_controls_section();

		// ── Style: Checkbox & Radio ──
		$this->start_controls_section(
			'section_style_checkbox_radio',
			array(
				'label' => __( 'Checkbox & Radio', 'shapeforms' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'choice_size',
			array(
				'label'      => __( 'Size', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 12, 'max' => 60 ) ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form' => '--bf-choice-size: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'choice_label_color',
			array(
				'label'     => __( 'Label Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form__choice' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'choice_typography',
				'selector' => '{{WRAPPER}} .shapeforms-lite-form__choice',
			)
		);

		$this->add_control(
			'choice_spacing',
			array(
				'label'      => __( 'Spacing Between Options', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 24 ) ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form__choice' => 'margin-bottom: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'choice_box_heading',
			array(
				'label'     => __( 'Box', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->start_controls_tabs( 'choice_box_tabs' );

		$this->start_controls_tab( 'choice_box_normal', array( 'label' => __( 'Normal', 'shapeforms' ) ) );
		$this->add_control(
			'choice_border_color',
			array(
				'label'     => __( 'Border Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form' => '--bf-choice-border: {{VALUE}};',
				),
			)
		);
		$this->add_control(
			'choice_bg_color',
			array(
				'label'     => __( 'Background', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form' => '--bf-choice-bg: {{VALUE}};',
				),
			)
		);
		$this->end_controls_tab();

		$this->start_controls_tab( 'choice_box_hover', array( 'label' => __( 'Hover', 'shapeforms' ) ) );
		$this->add_control(
			'choice_hover_border_color',
			array(
				'label'     => __( 'Border Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form' => '--bf-choice-hover-border: {{VALUE}};',
				),
			)
		);
		$this->add_control(
			'choice_hover_bg_color',
			array(
				'label'     => __( 'Background', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form' => '--bf-choice-hover-bg: {{VALUE}};',
				),
			)
		);
		$this->end_controls_tab();

		$this->start_controls_tab( 'choice_box_checked', array( 'label' => __( 'Checked', 'shapeforms' ) ) );
		$this->add_control(
			'choice_checked_color',
			array(
				'label'     => __( 'Fill Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form' => '--bf-choice-accent: {{VALUE}};',
				),
			)
		);
		$this->add_control(
			'choice_icon_color',
			array(
				'label'     => __( 'Check/Dot Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form' => '--bf-choice-icon: {{VALUE}};',
				),
			)
		);
		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->end_controls_section();

		// ── Style: Select Dropdown ──
		$this->start_controls_section(
			'section_style_select',
			array(
				'label' => __( 'Select Dropdown', 'shapeforms' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		// ── Trigger (closed state) ────────────────────────────────────────────

		$this->add_control(
			'select_placeholder_color',
			array(
				'label'     => __( 'Placeholder Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form .bf-select__placeholder' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'select_arrow_color',
			array(
				'label'     => __( 'Arrow Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form .bf-select__arrow' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'select_typography',
				'selector' => '{{WRAPPER}} .shapeforms-lite-form .bf-select__trigger, {{WRAPPER}} .shapeforms-lite-form select',
			)
		);

		$this->add_responsive_control(
			'select_border_radius',
			array(
				'label'      => __( 'Border Radius', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form .bf-select__trigger, {{WRAPPER}} .shapeforms-lite-form select' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'select_padding',
			array(
				'label'      => __( 'Padding', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form .bf-select__trigger, {{WRAPPER}} .shapeforms-lite-form select' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'select_trigger_heading',
			array(
				'label'     => __( 'Trigger Colors & Border', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->start_controls_tabs( 'select_trigger_tabs' );

		$this->start_controls_tab( 'select_trigger_normal', array( 'label' => __( 'Normal', 'shapeforms' ) ) );
		$this->add_group_control(
			\Elementor\Group_Control_Background::get_type(),
			array(
				'name'     => 'select_background',
				'label'    => __( 'Background', 'shapeforms' ),
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .shapeforms-lite-form .bf-select__trigger, {{WRAPPER}} .shapeforms-lite-form select',
			)
		);
		$this->add_control(
			'select_text_color',
			array(
				'label'     => __( 'Text Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form .bf-select__trigger' => 'color: {{VALUE}};',
					'{{WRAPPER}} .shapeforms-lite-form .bf-select__value' => 'color: {{VALUE}};',
					'{{WRAPPER}} .shapeforms-lite-form select' => 'color: {{VALUE}};',
				),
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'select_border',
				'selector' => '{{WRAPPER}} .shapeforms-lite-form .bf-select__trigger, {{WRAPPER}} .shapeforms-lite-form select',
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'select_box_shadow',
				'selector' => '{{WRAPPER}} .shapeforms-lite-form .bf-select__trigger, {{WRAPPER}} .shapeforms-lite-form select',
			)
		);
		$this->end_controls_tab();

		$this->start_controls_tab( 'select_trigger_hover', array( 'label' => __( 'Hover', 'shapeforms' ) ) );
		$this->add_control(
			'select_hover_border_color',
			array(
				'label'     => __( 'Border Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form' => '--bf-select-hover-border: {{VALUE}};',
				),
			)
		);
		$this->end_controls_tab();

		$this->start_controls_tab( 'select_trigger_focus', array( 'label' => __( 'Open / Focus', 'shapeforms' ) ) );
		$this->add_control(
			'select_focus_border_color',
			array(
				'label'     => __( 'Border Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form .bf-select.is-open .bf-select__trigger' => 'border-color: {{VALUE}};',
				),
			)
		);
		$this->add_control(
			'select_focus_bg_color',
			array(
				'label'     => __( 'Background Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form .bf-select.is-open .bf-select__trigger' => 'background-color: {{VALUE}};',
				),
			)
		);
		$this->add_control(
			'select_focus_shadow_color',
			array(
				'label'     => __( 'Ring Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form .bf-select.is-open .bf-select__trigger' => 'box-shadow: 0 0 0 3px {{VALUE}};',
				),
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'select_box_shadow_focus',
				'selector' => '{{WRAPPER}} .shapeforms-lite-form .bf-select.is-open .bf-select__trigger, {{WRAPPER}} .shapeforms-lite-form select:focus',
			)
		);
		$this->end_controls_tab();

		$this->end_controls_tabs();

		// ── Dropdown panel ────────────────────────────────────────────────────

		$this->add_control(
			'select_panel_heading',
			array(
				'label'     => __( 'Dropdown Panel', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Background::get_type(),
			array(
				'name'     => 'select_dropdown_background',
				'label'    => __( 'Panel Background', 'shapeforms' ),
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .shapeforms-lite-form .bf-select__panel',
			)
		);

		$this->add_control(
			'select_panel_border_color',
			array(
				'label'     => __( 'Panel Border Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form .bf-select__panel' => 'border-color: {{VALUE}};',
					'{{WRAPPER}} .shapeforms-lite-form .bf-select__search-wrap' => 'border-bottom-color: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'select_panel_border_radius',
			array(
				'label'      => __( 'Panel Border Radius', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form .bf-select__panel' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'select_panel_shadow',
				'selector' => '{{WRAPPER}} .shapeforms-lite-form .bf-select__panel',
			)
		);

		// ── Search box inside panel ───────────────────────────────────────────

		$this->add_control(
			'select_search_heading',
			array(
				'label'     => __( 'Search Box', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Background::get_type(),
			array(
				'name'     => 'select_search_background',
				'label'    => __( 'Search Background', 'shapeforms' ),
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .shapeforms-lite-form .bf-select__search-wrap, {{WRAPPER}} .shapeforms-lite-form .bf-select__panel-search',
			)
		);

		$this->add_control(
			'select_search_color',
			array(
				'label'     => __( 'Search Text Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form .bf-select__panel-search' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'select_search_placeholder_color',
			array(
				'label'     => __( 'Search Placeholder Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form .bf-select__panel-search::placeholder' => 'color: {{VALUE}};',
				),
			)
		);

		// ── Options ───────────────────────────────────────────────────────────

		$this->add_control(
			'select_options_heading',
			array(
				'label'     => __( 'Options', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'select_option_typography',
				'selector' => '{{WRAPPER}} .shapeforms-lite-form .bf-select__option',
			)
		);

		$this->add_control(
			'select_check_color',
			array(
				'label'     => __( 'Checkmark Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form .bf-select__option.is-active .bf-select__check' => 'background-color: {{VALUE}}; border-color: {{VALUE}};',
				),
			)
		);

		$this->start_controls_tabs( 'select_option_tabs' );

		$this->start_controls_tab( 'select_opt_normal', array( 'label' => __( 'Normal', 'shapeforms' ) ) );
		$this->add_group_control(
			\Elementor\Group_Control_Background::get_type(),
			array(
				'name'     => 'select_option_background',
				'label'    => __( 'Background', 'shapeforms' ),
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .shapeforms-lite-form .bf-select__option',
			)
		);
		$this->add_control(
			'select_option_color',
			array(
				'label'     => __( 'Text Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form .bf-select__option' => 'color: {{VALUE}};',
					'{{WRAPPER}} .shapeforms-lite-form .bf-select__option-text' => 'color: {{VALUE}};',
				),
			)
		);
		$this->end_controls_tab();

		$this->start_controls_tab( 'select_opt_hover', array( 'label' => __( 'Hover', 'shapeforms' ) ) );
		$this->add_group_control(
			\Elementor\Group_Control_Background::get_type(),
			array(
				'name'     => 'select_option_hover_background',
				'label'    => __( 'Background', 'shapeforms' ),
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .shapeforms-lite-form .bf-select__option:hover',
			)
		);
		$this->add_control(
			'select_option_hover_color',
			array(
				'label'     => __( 'Text Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form .bf-select__option:hover' => 'color: {{VALUE}};',
				),
			)
		);
		$this->end_controls_tab();

		$this->start_controls_tab( 'select_opt_selected', array( 'label' => __( 'Selected', 'shapeforms' ) ) );
		$this->add_group_control(
			\Elementor\Group_Control_Background::get_type(),
			array(
				'name'     => 'select_option_active_background',
				'label'    => __( 'Background', 'shapeforms' ),
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .shapeforms-lite-form .bf-select__option.is-active',
			)
		);
		$this->add_control(
			'select_option_active_color',
			array(
				'label'     => __( 'Text Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form .bf-select__option.is-active' => 'color: {{VALUE}};',
				),
			)
		);
		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->end_controls_section();

		// ── Style: Terms & Conditions ──
		$this->start_controls_section(
			'section_style_terms',
			array(
				'label' => __( 'Terms & Conditions', 'shapeforms' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'terms_text_color',
			array(
				'label'     => __( 'Text Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form__terms' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'terms_typography',
				'selector' => '{{WRAPPER}} .shapeforms-lite-form__terms',
			)
		);

		$this->add_control(
			'terms_link_heading',
			array(
				'label'     => __( 'Link', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->start_controls_tabs( 'terms_link_tabs' );

		$this->start_controls_tab( 'terms_link_normal', array( 'label' => __( 'Normal', 'shapeforms' ) ) );
		$this->add_control(
			'terms_link_color',
			array(
				'label'     => __( 'Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form__terms a' => 'color: {{VALUE}};',
				),
			)
		);
		$this->end_controls_tab();

		$this->start_controls_tab( 'terms_link_hover', array( 'label' => __( 'Hover', 'shapeforms' ) ) );
		$this->add_control(
			'terms_link_hover_color',
			array(
				'label'     => __( 'Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form' => '--bf-terms-link-hover: {{VALUE}};',
				),
			)
		);
		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_control(
			'terms_copy_heading',
			array(
				'label'     => __( 'Copy Text', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'terms_copy_typography',
				'selector' => '{{WRAPPER}} .shapeforms-lite-form__terms-copy, {{WRAPPER}} .shapeforms-lite-form__terms-copy a',
			)
		);

		$this->add_control(
			'terms_checkbox_heading',
			array(
				'label'     => __( 'Checkbox', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'terms_checkbox_size',
			array(
				'label'      => __( 'Checkbox Size', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 12, 'max' => 30 ) ),
				'default'    => array( 'size' => 18, 'unit' => 'px' ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form__terms .shapeforms-lite-form__choice-control' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}}; min-width: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'terms_checkbox_border_width',
			array(
				'label'      => __( 'Checkbox Border Width', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 1, 'max' => 5 ) ),
				'default'    => array( 'size' => 2, 'unit' => 'px' ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form__terms .shapeforms-lite-form__choice-control' => 'border-width: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'terms_checkbox_border_radius',
			array(
				'label'      => __( 'Checkbox Border Radius', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form__terms .shapeforms-lite-form__choice-control' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'terms_gap_heading',
			array(
				'label'     => __( 'Spacing', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_responsive_control(
			'terms_checkbox_gap',
			array(
				'label'      => __( 'Gap Between Checkbox & Text', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 5, 'max' => 30 ) ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form__terms' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'terms_field_margin',
			array(
				'label'      => __( 'Field Margin', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form__field--terms_conditions' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'terms_checkbox_colors_heading',
			array(
				'label'     => __( 'Checkbox Colors', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->start_controls_tabs( 'terms_checkbox_tabs' );

		$this->start_controls_tab( 'terms_checkbox_normal', array( 'label' => __( 'Normal', 'shapeforms' ) ) );
		$this->add_control(
			'terms_checkbox_border_color',
			array(
				'label'     => __( 'Border Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form__terms .shapeforms-lite-form__choice-control' => 'border-color: {{VALUE}};',
				),
			)
		);
		$this->add_control(
			'terms_checkbox_bg_color',
			array(
				'label'     => __( 'Background', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form__terms .shapeforms-lite-form__choice-control' => 'background-color: {{VALUE}};',
				),
			)
		);
		$this->end_controls_tab();

		$this->start_controls_tab( 'terms_checkbox_hover', array( 'label' => __( 'Hover', 'shapeforms' ) ) );
		$this->add_control(
			'terms_checkbox_hover_border_color',
			array(
				'label'     => __( 'Border Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form' => '--bf-terms-box-hover-border: {{VALUE}};',
				),
			)
		);
		$this->add_control(
			'terms_checkbox_hover_bg_color',
			array(
				'label'     => __( 'Background', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form' => '--bf-terms-box-hover-bg: {{VALUE}};',
				),
			)
		);
		$this->end_controls_tab();

		$this->start_controls_tab( 'terms_checkbox_checked', array( 'label' => __( 'Checked', 'shapeforms' ) ) );
		$this->add_control(
			'terms_checkbox_checked_color',
			array(
				'label'     => __( 'Fill Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form__terms input[type="checkbox"]:checked ~ .shapeforms-lite-form__choice-control' => 'border-color: {{VALUE}}; background-color: {{VALUE}};',
				),
			)
		);
		$this->add_control(
			'terms_checkbox_icon_color',
			array(
				'label'     => __( 'Check Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form' => '--bf-terms-box-icon: {{VALUE}};',
				),
			)
		);
		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->end_controls_section();

		// ── Style: Star Rating ──
		$this->start_controls_section(
			'section_style_star_rating',
			array(
				'label' => __( 'Star Rating', 'shapeforms' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'star_inactive_color',
			array(
				'label'     => __( 'Inactive Star Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-star' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'star_size',
			array(
				'label'      => __( 'Star Size', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 16, 'max' => 60 ) ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-star-rating' => '--bf-star-size: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'star_active_heading',
			array(
				'label'     => __( 'Active Star Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->start_controls_tabs( 'star_color_tabs' );

		$this->start_controls_tab( 'star_color_normal', array( 'label' => __( 'Normal', 'shapeforms' ) ) );
		$this->add_control(
			'star_color',
			array(
				'label'     => __( 'Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-star-rating' => '--bf-star-color: {{VALUE}};',
				),
			)
		);
		$this->end_controls_tab();

		$this->start_controls_tab( 'star_color_hover', array( 'label' => __( 'Hover', 'shapeforms' ) ) );
		$this->add_control(
			'star_hover_color',
			array(
				'label'     => __( 'Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-star-rating' => '--bf-star-hover: {{VALUE}};',
				),
			)
		);
		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->end_controls_section();

		// ── Style: Range Slider ──
		$this->start_controls_section(
			'section_style_slider',
			array(
				'label' => __( 'Range Slider', 'shapeforms' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		// NOTE: the slider markup carries the per-field builder values as INLINE
		// custom properties (style="--bf-slider-color/-height"), and inline styles
		// outrank a stylesheet rule. So these two declarations use !important to let
		// an explicitly-set Elementor value override the form's baked-in default.
		// When left unset, Elementor emits no rule and the field default still wins.
		$this->add_control(
			'slider_color',
			array(
				'label'     => __( 'Slider Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-slider' => '--bf-slider-color: {{VALUE}} !important;',
				),
			)
		);

		$this->add_responsive_control(
			'slider_track_height',
			array(
				'label'      => __( 'Track Height', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 2, 'max' => 24 ) ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-slider' => '--bf-slider-height: {{SIZE}}{{UNIT}} !important;',
				),
			)
		);

		$this->add_control(
			'slider_value_color',
			array(
				'label'     => __( 'Value Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'separator' => 'before',
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-slider__value' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'slider_value_typography',
				'selector' => '{{WRAPPER}} .shapeforms-lite-slider__value',
			)
		);

		$this->add_control(
			'slider_labels_color',
			array(
				'label'     => __( 'Min / Max Labels Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-slider__labels' => 'color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		// ── Style: File Upload ──
		$this->start_controls_section(
			'section_style_file',
			array(
				'label' => __( 'File Upload', 'shapeforms' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->start_controls_tabs( 'file_btn_tabs' );

		$this->start_controls_tab( 'file_btn_normal', array( 'label' => __( 'Normal', 'shapeforms' ) ) );

		$this->add_control(
			'file_btn_color',
			array(
				'label'     => __( 'Button Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form input[type="file"]::file-selector-button' => 'background-color: {{VALUE}}; border-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'file_btn_text_color',
			array(
				'label'     => __( 'Button Text Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form input[type="file"]::file-selector-button' => 'color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab( 'file_btn_hover', array( 'label' => __( 'Hover', 'shapeforms' ) ) );

		$this->add_control(
			'file_btn_hover_color',
			array(
				'label'     => __( 'Button Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form input[type="file"]::file-selector-button:hover' => 'background-color: {{VALUE}}; border-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'file_btn_hover_text_color',
			array(
				'label'     => __( 'Button Text Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form input[type="file"]::file-selector-button:hover' => 'color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->end_controls_section();

		// ── Style: Section Break ──
		$this->start_controls_section(
			'section_style_section_break',
			array(
				'label' => __( 'Section Break', 'shapeforms' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'section_break_margin',
			array(
				'label'      => __( 'Margin', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form__section-break' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'section_break_padding',
			array(
				'label'      => __( 'Padding', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form__section-break' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'section_break_border_heading',
			array(
				'label'     => __( 'Border', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'section_break_border_enable',
			array(
				'label'        => __( 'Show Border', 'shapeforms' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'shapeforms' ),
				'label_off'    => __( 'No', 'shapeforms' ),
				'return_value' => 'yes',
				'default'      => '',
				'selectors'    => array(
					'{{WRAPPER}} .shapeforms-lite-form__section-break' => 'border-style: solid;',
				),
			)
		);

		$this->add_responsive_control(
			'section_break_border_width',
			array(
				'label'      => __( 'Border Width', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px' ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form__section-break' => 'border-width: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
				'condition'  => array(
					'section_break_border_enable' => 'yes',
				),
			)
		);

		$this->add_control(
			'section_break_border_color',
			array(
				'label'     => __( 'Border Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form__section-break' => 'border-color: {{VALUE}};',
				),
				'condition' => array(
					'section_break_border_enable' => 'yes',
				),
			)
		);

		$this->add_responsive_control(
			'section_break_border_radius',
			array(
				'label'      => __( 'Border Radius', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form__section-break' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'section_break_title_heading',
			array(
				'label'     => __( 'Title', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'section_break_color',
			array(
				'label'     => __( 'Title Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form__section-title' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'section_break_typography',
				'selector' => '{{WRAPPER}} .shapeforms-lite-form__section-title',
			)
		);

		$this->add_responsive_control(
			'section_break_title_margin',
			array(
				'label'      => __( 'Title Margin', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form__section-title' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'section_break_desc_heading',
			array(
				'label'     => __( 'Description', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'section_break_desc_color',
			array(
				'label'     => __( 'Description Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form__section-description' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'section_break_desc_typography',
				'selector' => '{{WRAPPER}} .shapeforms-lite-form__section-description',
			)
		);

		$this->add_responsive_control(
			'section_break_desc_margin',
			array(
				'label'      => __( 'Description Margin', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form__section-description' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		// ── Style: Submit Button ──
		$this->start_controls_section(
			'section_style_button',
			array(
				'label' => __( 'Submit Button', 'shapeforms' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'button_align',
			array(
				'label'                => __( 'Button Alignment', 'shapeforms' ),
				'type'                 => \Elementor\Controls_Manager::CHOOSE,
				'options'              => array(
					'left'   => array(
						'title' => __( 'Left', 'shapeforms' ),
						'icon'  => 'eicon-text-align-left',
					),
					'center' => array(
						'title' => __( 'Center', 'shapeforms' ),
						'icon'  => 'eicon-text-align-center',
					),
					'right'  => array(
						'title' => __( 'Right', 'shapeforms' ),
						'icon'  => 'eicon-text-align-right',
					),
				),
				'selectors_dictionary' => array(
					'left'   => 'flex-start',
					'center' => 'center',
					'right'  => 'flex-end',
				),
				'selectors'            => array(
					'{{WRAPPER}} .shapeforms-lite-form__actions' => 'display: flex; justify-content: {{VALUE}};',
				),
				'condition'            => array(
					'button_full_width!' => 'yes',
				),
			)
		);

		$this->add_control(
			'button_full_width',
			array(
				'label'        => __( 'Full Width', 'shapeforms' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'selectors'    => array(
					'{{WRAPPER}} .shapeforms-lite-form__actions' => 'display: block;',
					'{{WRAPPER}} .shapeforms-lite-form__submit' => 'width: 100%;',
				),
			)
		);

		$this->add_responsive_control(
			'button_padding',
			array(
				'label'      => __( 'Padding', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form__submit' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'button_typography',
				'selector' => '{{WRAPPER}} .shapeforms-lite-form__submit',
			)
		);

		$this->add_control(
			'button_icon_color_el',
			array(
				'label'     => __( 'Icon Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form__submit .dashicons' => 'color: {{VALUE}} !important;',
					'{{WRAPPER}} .shapeforms-lite-form__submit .shapeforms-btn-icon-svg' => 'fill: {{VALUE}} !important; color: {{VALUE}} !important;',
					'{{WRAPPER}} .shapeforms-lite-form__submit .shapeforms-btn-icon-svg svg' => 'fill: {{VALUE}} !important;',
					'{{WRAPPER}} .shapeforms-lite-form__submit .shapeforms-btn-icon-svg svg path' => 'fill: {{VALUE}} !important;',
					'{{WRAPPER}} .shapeforms-lite-form__submit .shapeforms-btn-icon-svg svg rect' => 'fill: {{VALUE}} !important;',
				),
			)
		);

		$this->add_responsive_control(
			'button_border_radius',
			array(
				'label'      => __( 'Border Radius', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form__submit' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'button_margin',
			array(
				'label'      => __( 'Button Margin', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'separator'  => 'before',
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form__submit' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'button_el_margin',
			array(
				'label'      => __( 'Button Container Margin', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form__actions' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'button_states_heading',
			array(
				'label'     => __( 'Colors & Border', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->start_controls_tabs( 'button_state_tabs' );

		$this->start_controls_tab( 'button_state_normal', array( 'label' => __( 'Normal', 'shapeforms' ) ) );
		$this->add_group_control(
			\Elementor\Group_Control_Background::get_type(),
			array(
				'name'     => 'button_background',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .shapeforms-lite-form__submit',
			)
		);
		$this->add_control(
			'button_text_color',
			array(
				'label'     => __( 'Text Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form__submit' => 'color: {{VALUE}};',
				),
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'button_border',
				'selector' => '{{WRAPPER}} .shapeforms-lite-form__submit',
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'button_box_shadow',
				'selector' => '{{WRAPPER}} .shapeforms-lite-form__submit',
			)
		);
		$this->end_controls_tab();

		$this->start_controls_tab( 'button_state_hover', array( 'label' => __( 'Hover', 'shapeforms' ) ) );
		$this->add_group_control(
			\Elementor\Group_Control_Background::get_type(),
			array(
				'name'     => 'button_hover_background',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .shapeforms-lite-form__submit:hover',
			)
		);
		$this->add_control(
			'button_hover_text_color',
			array(
				'label'     => __( 'Text Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form__submit:hover' => 'color: {{VALUE}};',
				),
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'button_hover_border',
				'selector' => '{{WRAPPER}} .shapeforms-lite-form__submit:hover',
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'button_box_shadow_hover',
				'selector' => '{{WRAPPER}} .shapeforms-lite-form__submit:hover',
			)
		);
		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->end_controls_section();

		// ── Style: Error Messages ──
		$this->start_controls_section(
			'section_style_errors',
			array(
				'label' => __( 'Error Messages', 'shapeforms' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'error_color',
			array(
				'label'     => __( 'Error Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form__field-error'   => 'color: {{VALUE}};',
					'{{WRAPPER}} .shapeforms-lite-form__required'      => 'color: {{VALUE}};',
					'{{WRAPPER}} .shapeforms-lite-form__field.is-invalid input, {{WRAPPER}} .shapeforms-lite-form__field.is-invalid textarea, {{WRAPPER}} .shapeforms-lite-form__field.is-invalid select, {{WRAPPER}} .shapeforms-lite-form__field.is-invalid .bf-select__trigger' => 'border-color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'error_typography',
				'selector' => '{{WRAPPER}} .shapeforms-lite-form__field-error',
			)
		);

		$this->add_control(
			'error_message_text_color',
			array(
				'label'     => __( 'Notice Text Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'separator' => 'before',
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form__message.is-error' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Background::get_type(),
			array(
				'name'     => 'error_message_background',
				'label'    => __( 'Notice Background', 'shapeforms' ),
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .shapeforms-lite-form__message.is-error',
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'error_message_border',
				'selector' => '{{WRAPPER}} .shapeforms-lite-form__message.is-error',
			)
		);

		$this->add_responsive_control(
			'error_message_border_radius',
			array(
				'label'      => __( 'Border Radius', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form__message.is-error' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'error_message_padding',
			array(
				'label'      => __( 'Padding', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form__message.is-error' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'error_message_margin',
			array(
				'label'      => __( 'Margin', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form__message.is-error' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		// ── Style: Success Message ──
		$this->start_controls_section(
			'section_style_success',
			array(
				'label' => __( 'Success Message', 'shapeforms' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'success_message_color',
			array(
				'label'     => __( 'Text Color', 'shapeforms' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shapeforms-lite-form__message.is-success' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'success_typography',
				'selector' => '{{WRAPPER}} .shapeforms-lite-form__message.is-success',
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Background::get_type(),
			array(
				'name'     => 'success_message_background',
				'label'    => __( 'Background', 'shapeforms' ),
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .shapeforms-lite-form__message.is-success',
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'success_message_border',
				'selector' => '{{WRAPPER}} .shapeforms-lite-form__message.is-success',
			)
		);

		$this->add_responsive_control(
			'success_message_border_radius',
			array(
				'label'      => __( 'Border Radius', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form__message.is-success' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'success_message_shadow',
				'selector' => '{{WRAPPER}} .shapeforms-lite-form__message.is-success',
			)
		);

		$this->add_responsive_control(
			'success_message_padding',
			array(
				'label'      => __( 'Padding', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form__message.is-success' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'success_message_margin',
			array(
				'label'      => __( 'Margin', 'shapeforms' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .shapeforms-lite-form__message.is-success' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		/**
		 * Fires after all Elementor widget controls are registered.
		 *
		 * Pro can add additional control sections (e.g. multi-step style, payment, etc.).
		 *
		 * @param \Elementor\Widget_Base $widget This widget instance.
		 */
		do_action( 'shapeforms_elementor_widget_controls', $this );
	}

	/**
	 * Renders the widget output.
	 *
	 * @return void
	 */
	protected function render() {
		$this->register_frontend_assets();

		$settings = $this->get_settings_for_display();
		$form_id  = isset( $settings['form_id'] ) ? absint( $settings['form_id'] ) : 0;

		if ( ! $form_id ) {
			echo esc_html__( 'Select a form to display.', 'shapeforms' );
			return;
		}

		echo do_shortcode( sprintf( '[shapeforms id="%d"]', $form_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Registers frontend assets when Elementor preview requests them in admin/editor context.
	 *
	 * @return void
	 */
	private function register_frontend_assets() {
		if ( ! wp_style_is( 'shapeforms-lite-frontend', 'registered' ) ) {
			wp_register_style(
				'shapeforms-lite-frontend',
				SHAPEFORMS_LITE_URL . 'assets/css/frontend.css',
				array(),
				SHAPEFORMS_LITE_VERSION
			);
		}

		if ( ! wp_script_is( 'shapeforms-lite-frontend', 'registered' ) ) {
			wp_register_script(
				'shapeforms-lite-frontend',
				SHAPEFORMS_LITE_URL . 'assets/js/frontend.js',
				array( 'jquery' ),
				SHAPEFORMS_LITE_VERSION,
				true
			);
		}

		// Register Flatpickr too so date/time fields show their picker in the Elementor editor preview.
		if ( ! wp_style_is( 'shapeforms-lite-flatpickr', 'registered' ) ) {
			wp_register_style(
				'shapeforms-lite-flatpickr',
				SHAPEFORMS_LITE_URL . 'assets/css/flatpickr.min.css',
				array(),
				'4.6.13'
			);
		}

		if ( ! wp_script_is( 'shapeforms-lite-flatpickr', 'registered' ) ) {
			wp_register_script(
				'shapeforms-lite-flatpickr',
				SHAPEFORMS_LITE_URL . 'assets/js/flatpickr.min.js',
				array(),
				'4.6.13',
				true
			);
		}

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
	}

	/**
	 * Returns the available forms as Elementor select options.
	 *
	 * @return array<string, string>
	 */
	private function get_form_options() {
		global $wpdb;

		$options = array(
			'0' => __( 'Select a form', 'shapeforms' ),
		);

		if ( ! $this->plugin ) {
			return $options;
		}

		$table_name = $this->plugin->get_forms_table_name();
		$cache_key  = 'shapeforms_lite_elementor_form_options';
		$forms      = wp_cache_get( $cache_key, 'shapeforms_lite' );

		$safe_table = esc_sql( $table_name );

		if ( false === $forms ) {
			$forms = $wpdb->get_results( $wpdb->prepare( "SELECT id, title FROM `{$safe_table}` WHERE status = %s ORDER BY title ASC", 'publish' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
			wp_cache_set( $cache_key, $forms, 'shapeforms_lite', MINUTE_IN_SECONDS );
		}

		foreach ( $forms as $form ) {
			/* translators: %d: form ID number */
			$options[ (string) $form->id ] = $form->title ? (string) $form->title : sprintf( __( 'Form #%d', 'shapeforms' ), (int) $form->id );
		}

		return $options;
	}
}
