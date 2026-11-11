( function ( blocks, element, components, blockEditor, serverSideRender, i18n ) {
	var el = element.createElement;
	var Fragment = element.Fragment;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var ToggleControl = components.ToggleControl;
	var Placeholder = components.Placeholder;
	var ServerSideRender = serverSideRender && serverSideRender.default ? serverSideRender.default : serverSideRender;
	var __ = i18n.__;

	blocks.registerBlockType(
		'shapeforms/form',
		{
			title: __( 'ShapeForms', 'shapeforms' ),
			icon: 'feedback',
			category: 'widgets',
			description: __( 'Display a ShapeForms form.', 'shapeforms' ),
			edit: function ( props ) {
				var attributes = props.attributes;
				var setAttributes = props.setAttributes;
				var blockProps = useBlockProps();
				var forms = window.shapeformsLiteBlock && Array.isArray( window.shapeformsLiteBlock.forms ) ? window.shapeformsLiteBlock.forms : [];
				var options = [
					{
						label: window.shapeformsLiteBlock && window.shapeformsLiteBlock.placeholder ? window.shapeformsLiteBlock.placeholder : __( 'Select a form', 'shapeforms' ),
						value: 0
					}
				];

				forms.forEach( function ( form ) {
					options.push( { label: form.label, value: form.value } );
				} );

				function setAttr( key ) {
					return function ( val ) {
						var obj = {};
						obj[ key ] = val;
						setAttributes( obj );
					};
				}

				return el(
					Fragment,
					null,
					el(
						InspectorControls,
						null,

						/* ── Form Selection ── */
						el( PanelBody, { title: __( 'Form Settings', 'shapeforms' ), initialOpen: true },
							el( SelectControl, {
								label: __( 'Select Form', 'shapeforms' ),
								value: attributes.formId || 0,
								options: options,
								onChange: function ( value ) {
									setAttributes( { formId: parseInt( value, 10 ) || 0 } );
								}
							} ),
							attributes.formId ? el(
								'a',
								{
									href: ( window.shapeformsLiteBlock && window.shapeformsLiteBlock.builderUrl || '/wp-admin/admin.php?page=shapeforms-lite-builder&form_id=' ) + attributes.formId,
									target: '_blank',
									rel: 'noopener',
									style: { display: 'inline-flex', alignItems: 'center', gap: '4px', color: '#2f80ed', fontWeight: 500, fontSize: '13px', textDecoration: 'none', marginBottom: '12px' }
								},
								el( 'span', { className: 'dashicons dashicons-edit', style: { fontSize: '14px', width: '14px', height: '14px' } } ),
								__( 'Edit this form in builder', 'shapeforms' )
							) : null,
							el( ToggleControl, {
								label: __( 'Hide Labels', 'shapeforms' ),
								checked: !! attributes.hideLabels,
								onChange: setAttr( 'hideLabels' )
							} ),
							el( ToggleControl, {
								label: __( 'Hide Placeholders', 'shapeforms' ),
								checked: !! attributes.hidePlaceholders,
								onChange: setAttr( 'hidePlaceholders' )
							} )
						)
					),
					el(
						'div',
						blockProps,
						attributes.formId
							? el( ServerSideRender, {
								block: 'shapeforms/form',
								attributes: attributes
							} )
							: el(
								Placeholder,
								{
									label: __( 'ShapeForms', 'shapeforms' ),
									instructions: forms.length
										? ( window.shapeformsLiteBlock && window.shapeformsLiteBlock.previewText ? window.shapeformsLiteBlock.previewText : __( 'Select a form to preview it in the editor.', 'shapeforms' ) )
										: ( window.shapeformsLiteBlock && window.shapeformsLiteBlock.emptyMessage ? window.shapeformsLiteBlock.emptyMessage : __( 'No published forms found.', 'shapeforms' ) )
								},
								el( SelectControl, {
									label: __( 'Form', 'shapeforms' ),
									value: attributes.formId || 0,
									options: options,
									onChange: function ( value ) {
										setAttributes( { formId: parseInt( value, 10 ) || 0 } );
									}
								} )
							)
					)
				);
			},
			save: function () {
				return null;
			}
		}
	);
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.components,
	window.wp.blockEditor,
	window.wp.serverSideRender,
	window.wp.i18n
);
