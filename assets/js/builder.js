jQuery(
	function ( $ ) {
		var optionFieldTypes = [ 'select', 'multiselect', 'checkbox', 'radio' ];
		var specialFieldTypes = [ 'captcha', 'section_break', 'terms_conditions', 'file', 'submit', 'paragraph', 'html_editor', 'name', 'address', 'product', 'quantity', 'custom_amount', 'order_summary', 'signature', 'hidden_field', 'image_choice', 'repeater', 'password_field', 'rich_text', 'date_range', 'nps', 'matrix', 'lookup', 'geolocation', 'page_break' ];
		var submitButtonId = '__shapeforms_submit_button__';
		var state = {
			formId: Number( shapeformsLiteBuilder.formId || 0 ),
			formTitle: shapeformsLiteBuilder.formTitle || shapeformsLiteBuilder.defaultFormTitle,
			structure: normalizeStructure( shapeformsLiteBuilder.formStructure ),
			formSettings: normalizeFormSettings( shapeformsLiteBuilder.formSettings ),
			selectedFieldId: null,
			selectedRowIndex: null,
			activeColumn: null,
			activeSidebarTab: 'library',
			activeEditorView: 'builder',
			activeSettingsAccordion: 'settings',
			selectedTemplate: 'contact',
			activeDevice: 'desktop'
		};

		// Escapes for HTML text AND attribute contexts: encodes < > & plus both quote
		// characters, so the result is safe to interpolate inside quote-delimited
		// attribute values. In text context the extra quote-encoding renders identically.
		function escapeHtml( value ) {
			return $( '<div />' ).text( value || '' ).html()
				.replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
		}

	/**
	 * The link an author can share for this form.
	 *
	 * Falls back to the form id when no slug is set, which is exactly what the
	 * server does when resolving the URL — so the preview never shows a link
	 * that would not resolve.
	 */
	/** Whether this form is presented one question at a time. */
	function cvIsOn() {
		return !! state.formSettings.cv_enabled;
	}

	/**
	 * The value an empty Next / Back / Start field — or an empty welcome heading
	 * — falls back to.
	 *
	 * Read from the renderer via shapeformsLiteBuilder.cvDefaults, never restated
	 * here. These values appear as PLACEHOLDERS, which is the only place an
	 * author is told what "leave it empty" gives them — so a local copy is a
	 * promise the page is free to break, and did once.
	 *
	 * The literal is a last resort for a payload that predates the key, and is
	 * deliberately the SAME word the renderer uses, so it cannot reintroduce the
	 * disagreement it exists to guard against.
	 */
	function cvDefaultLabel( key ) {
		var map = ( shapeformsLiteBuilder && shapeformsLiteBuilder.cvDefaults ) || {};
		var fallback = { next: 'Next', prev: 'Back', start: 'Start', welcome: 'Let’s get started' };
		return map[ key ] || fallback[ key ] || '';
	}

	/**
	 * Whether anything on this form can show the progress track colour.
	 *
	 * --bfc-track paints the bar's groove and the dots not yet reached. A
	 * counter and a percentage are text, and "none" renders no progress row at
	 * all, so under those three the control would offer a colour that cannot
	 * appear anywhere — worse than no control, because it looks like it works.
	 */
	function cvHasTrack() {
		var progress = state.formSettings.cv_progress || 'bar';
		return 'bar' === progress || 'dots' === progress;
	}

	/**
	 * Whether the progress indicator is something that can be moved.
	 *
	 * A bar spans the full width, so aligning it does nothing, and "none"
	 * renders no indicator at all. Only the counter, the percentage and the row
	 * of dots are inline boxes with space around them.
	 */
	function cvProgressMoves() {
		var progress = state.formSettings.cv_progress || 'bar';
		return 'dots' === progress || 'counter' === progress || 'percent' === progress;
	}

	/**
	 * Whether this form renders the "press Enter" hint at all.
	 *
	 * --bfc-hint has exactly one consumer, and build_chrome() omits it entirely
	 * when the switch is off. Offering its colour then would be the Accent
	 * background mistake again: a control that stores a value nothing can ever
	 * show, which reads as broken rather than as inapplicable.
	 *
	 * The switch lives on the Settings tab and this control on the Style tab, so
	 * whatever reads this has to re-render when the switch moves.
	 */
	function cvHasHint() {
		return !! state.formSettings.cv_key_hint;
	}

	/**
	 * Whether this form renders a progress indicator at all.
	 *
	 * --bfc-accent paints the bar's fill, the dots already passed, the counter
	 * and the percentage — and nothing else. Under "none" there is no indicator,
	 * so every rule reading it is on an element that was never rendered.
	 *
	 * The focus ring used to be the exception, and the reason this control was
	 * kept under "none". It now reads --bfc-btn, which is what a ring around a
	 * button should have read all along, so the exception is gone.
	 */
	function cvHasIndicator() {
		return 'none' !== ( state.formSettings.cv_progress || 'bar' );
	}

	/**
	 * What the accent colour paints on THIS form, in words.
	 *
	 * Only ever called where cvHasIndicator() is true, so there is no "and
	 * nothing else" branch to write — a form with no indicator is not shown the
	 * control at all.
	 */
	function cvAccentDoes() {
		var progress = state.formSettings.cv_progress || 'bar';
		var paints   = {
			bar:     'fills the progress bar',
			dots:    'fills the dots already passed',
			counter: 'colours the question counter',
			percent: 'colours the percentage'
		}[ progress ];

		return 'Accent ' + ( paints || 'colours the progress indicator' ) + '.';
	}

	/**
	 * The heading the welcome cover will actually carry.
	 *
	 * build_welcome() falls back to a default heading when the author has left
	 * both the heading and the text empty, so that switching the cover on is
	 * never a no-op. The Style tab's preview has to show the same thing, and
	 * writing the condition twice is how the two drift — so it is written here,
	 * once, and a suite holds it against the renderer.
	 */
	function cvWelcomeTitle() {
		var title = String( state.formSettings.cv_welcome_title || '' ).trim();
		// The renderer measures the text with its tags stripped, so markup alone
		// ("<p></p>") counts as empty on both sides.
		var text  = String( state.formSettings.cv_welcome_text || '' ).replace( /<[^>]*>/g, '' ).trim();

		if ( '' === title && '' === text ) {
			return cvDefaultLabel( 'welcome' );
		}

		return title;
	}

	/**
	 * One conversational colour control, using the builder's own swatch + hex
	 * pairing.
	 *
	 * An untouched control keeps a data-cv-unset marker so it stores '' —
	 * "inherit the form's existing style" — rather than the fallback shown in
	 * the swatch. The marker is cleared as soon as the author picks a colour.
	 *
	 * Module scope because these controls live on the Style tab while the rest
	 * of the conversational settings live on the Settings tab.
	 */
	function cvColourField( id, label, value, fallback ) {
		var shown = value || fallback;
		var unset = value ? '' : ' data-cv-unset="1"';

		return '<div class="shapeforms-setting-group">' +
			'<label for="' + id + '">' + escapeHtml( label ) + '</label>' +
			'<div class="shapeforms-color-field">' +
				'<div class="shapeforms-color-swatch" style="background:' + escapeHtml( shown ) + '">' +
					'<input type="color" id="' + id + '" value="' + escapeHtml( shown ) + '"' + unset + '>' +
				'</div>' +
				'<input type="text" class="shapeforms-color-hex" maxlength="7" value="' + escapeHtml( shown ) + '" data-color-for="' + id + '" spellcheck="false">' +
			'</div>' +
		'</div>';
	}

	/**
	 * The Default Screen Colours section of the Style tab.
	 *
	 * What every screen STARTS from. A screen that overrides one of these
	 * stores its own value on whichever of row or field it is; this section is
	 * what the rest still inherit, which is why removing it was never an
	 * option — it would mean styling a twenty-screen form twenty times.
	 *
	 * These settings are stored as cv_* hex strings, not as --bf-* custom
	 * properties like the rest of the Style tab, so the section is hand-built
	 * rather than declared in bfStyleSchema(). Changing their storage to match
	 * would be a migration, and the render layer already reads them as they are.
	 */
	function cvStyleSection() {
		var labels = shapeformsLiteBuilder.labels || {};

		if ( ! cvIsOn() ) {
			return '<div class="shapeforms-style-section" data-adv-section="conversational">' +
				'<div class="shapeforms-style-section__head"><h3>' + escapeHtml( labels.cvStyleTitle || 'Default Screen Colours' ) + '</h3>' +
					'<div class="shapeforms-style-section__head-actions"><span class="dashicons dashicons-arrow-down-alt2"></span></div>' +
				'</div>' +
				'<div class="shapeforms-style-section__body">' +
					'<p class="shapeforms-setting-desc">' + escapeHtml( labels.cvStyleOff || 'Conversational mode is off for this form.' ) + '</p>' +
				'</div>' +
			'</div>';
		}

		return '<div class="shapeforms-style-section is-open" data-adv-section="conversational" data-pane="conversational-style">' +
			'<div class="shapeforms-style-section__head"><h3>' + escapeHtml( labels.cvStyleTitle || 'Default Screen Colours' ) + '</h3>' +
				'<div class="shapeforms-style-section__head-actions"><span class="dashicons dashicons-arrow-down-alt2"></span></div>' +
			'</div>' +
			'<div class="shapeforms-style-section__body">' +
				// Grouped by what they colour, and in the order a visitor meets
				// them: the screen, then the buttons, then the progress row.
				// Background sits alone because it is the canvas the rest are
				// read against, not half of a pair.
				'<div class="shapeforms-setting-row">' +
					cvColourField( 'shapeforms-cv-bg', 'Background', state.formSettings.cv_bg, '#ffffff' ) +
				'</div>' +
				'<div class="shapeforms-setting-row">' +
					cvColourField( 'shapeforms-cv-question-color', 'Question text', state.formSettings.cv_question_color, '#111827' ) +
					cvColourField( 'shapeforms-cv-answer-color', 'Answer text', state.formSettings.cv_answer_color, '#111827' ) +
				'</div>' +
				'<div class="shapeforms-setting-row">' +
					cvColourField( 'shapeforms-cv-btn-color', 'Button', state.formSettings.cv_btn_color, '#2f80ed' ) +
					cvColourField( 'shapeforms-cv-btn-text-color', 'Button text', state.formSettings.cv_btn_text_color, '#ffffff' ) +
				'</div>' +
				'<div class="shapeforms-setting-row">' +
					// Back is the outlined half of the pair. One control for its
					// text AND its border, because the border is currentColor and
					// the two are the same by design; a second for its fill, which
					// is transparent until an author says otherwise.
					cvColourField( 'shapeforms-cv-prev-color', 'Back button', state.formSettings.cv_prev_color, state.formSettings.cv_btn_color || '#2f80ed' ) +
					cvColourField( 'shapeforms-cv-prev-bg', 'Back background', state.formSettings.cv_prev_bg, '#ffffff' ) +
				'</div>' +
				'<p class="shapeforms-setting-desc">Back button sets its text and its border together. Left alone, both follow your Button colour and the fill stays transparent.</p>' +
				// The hint sits in the nav row beside Next, so it belongs with the
				// buttons rather than with the progress colours below. Withdrawn
				// when the switch that renders it is off — see cvHasHint().
				( cvHasHint()
					? '<div class="shapeforms-setting-row">' +
						cvColourField( 'shapeforms-cv-hint-color', 'Enter hint', state.formSettings.cv_hint_color, '#6b7280' ) +
					'</div>' +
					'<p class="shapeforms-setting-desc">Colours the "press Enter" text and its key cap together. Left alone it is a faded version of your Answer text, so it stays readable on a dark background; pick a colour and the hint is exactly that colour.</p>'
					: '' ) +
				// Accent and the track are the two halves of the progress row and
				// belong side by side — and the whole row goes when there is no
				// progress row to colour. Accent paints the finished part, the
				// track the part not reached yet, and under "none" neither of
				// those elements is rendered at all.
				( cvHasIndicator()
					? '<div class="shapeforms-setting-row">' +
						cvColourField( 'shapeforms-cv-accent', 'Accent', state.formSettings.cv_accent, '#2f80ed' ) +
						// The track is the groove behind the bar and the dots not
						// yet reached, so it has nothing to colour under a counter
						// or a percentage either. It is also a value a single
						// screen cannot override: the progress row sits on the
						// wrapper, outside every screen.
						( cvHasTrack()
							? cvColourField( 'shapeforms-cv-track-color', 'Accent background', state.formSettings.cv_track_color, '#e5e7eb' )
							: '' ) +
					'</div>' +
					'<p class="shapeforms-setting-desc">' +
						// Named for the indicator actually chosen. A fixed sentence
						// about "the progress bar" is wrong three times out of four,
						// and being told a colour does something it does not is how
						// an author concludes the control is broken.
						escapeHtml( cvAccentDoes() ) +
						( cvHasTrack() ? ' Accent background is the part not reached yet.' : '' ) +
					'</p>'
					: '' ) +
				'<p class="shapeforms-setting-desc">' + escapeHtml( labels.cvStyleHelp || 'The starting point for every screen. Any screen can override these from its own settings.' ) + '</p>' +
			'</div>' +
		'</div>';
	}

	/**
	 * The screens a visitor will walk through, in the order they will see them.
	 *
	 * THIS MUST AGREE WITH conversational.js. The engine works on rendered DOM
	 * and this works on the saved structure, so the rule cannot literally be
	 * shared — but a builder that numbers screens differently from the form is
	 * worse than a builder that numbers nothing. The rule, in both places:
	 *
	 *   - a field that renders nothing (hidden, page break) is never a screen
	 *   - consent and captcha are pinned to the LAST screen
	 *   - a row with more than one column is ONE screen (the author's layout)
	 *   - a single-column row is one screen PER FIELD
	 *
	 * Returns [ { rowIndex, fieldIds[], isTail } ] in visitor order.
	 */
	function cvScreens() {
		var tailTypes   = shapeformsLiteBuilder.cvTailTypes || [];
		var silentTypes = shapeformsLiteBuilder.cvSilentTypes || [];
		var screens     = [];
		var tailFields  = [];
		var tailRow     = -1;

		getAllRows().forEach( function ( row, rowIndex ) {
			var columns = row.columns || [];
			var visible = [];

			columns.forEach( function ( column ) {
				( column.fields || [] ).forEach( function ( field ) {
					if ( silentTypes.indexOf( field.type ) !== -1 ) {
						return;
					}
					if ( tailTypes.indexOf( field.type ) !== -1 ) {
						tailFields.push( field.id );
						if ( tailRow < 0 ) { tailRow = rowIndex; }
						return;
					}
					visible.push( field.id );
				} );
			} );

			if ( ! visible.length ) {
				return;
			}

			// isRow says WHICH THING is the screen — the row, or each field in it.
			// It cannot be re-derived later from the field count: a three-column
			// row holding a single field is still one row-screen, and counting
			// fields would call it a field-screen, drawing the card around that
			// one field and leaving the row's other columns outside it.
			if ( columns.length > 1 ) {
				screens.push( { rowIndex: rowIndex, fieldIds: visible, isTail: false, isRow: true } );
				return;
			}

			visible.forEach( function ( id ) {
				screens.push( { rowIndex: rowIndex, fieldIds: [ id ], isTail: false, isRow: false } );
			} );
		} );

		if ( tailFields.length ) {
			screens.push( { rowIndex: tailRow, fieldIds: tailFields, isTail: true, isRow: false } );
		}

		return screens;
	}

	/**
	 * Screen number by field id, plus the rows that are a screen in their own
	 * right (a multi-column row, where the badge belongs on the row rather than
	 * on each of the fields sharing it).
	 */
	function cvScreenMap() {
		var byField = {};
		var byRow   = {};
		var screens = cvScreens();

		screens.forEach( function ( screen, index ) {
			var number = index + 1;

			if ( screen.isRow ) {
				byRow[ screen.rowIndex ] = number;
			}

			screen.fieldIds.forEach( function ( id ) {
				byField[ id ] = number;
			} );
		} );

		return { byField: byField, byRow: byRow, total: screens.length };
	}

		function generateId() {
			return 'bf_' + Date.now() + '_' + Math.floor( Math.random() * 100000 );
		}

		// Announce builder mutations to assistive tech (drag/add/delete have no visible
		// status text otherwise). Safe no-op if wp.a11y isn't present.
		function announce( key ) {
			var messages = shapeformsLiteBuilder.a11y || {};
			if ( messages[ key ] && window.wp && wp.a11y && typeof wp.a11y.speak === 'function' ) {
				wp.a11y.speak( messages[ key ] );
			}
		}

		// Normalise a repeater's `repeater_fields` into layout groups, mirroring
		// ShapeForms_Pro_Repeater::sanitize_groups(). A repeater saved before groups is a
		// flat list of sub-fields; it is wrapped in one implicit group here, on read,
		// so the builder only ever deals with the grouped shape. Group ids are layout
		// only — they never reach an input name or a saved entry.
		function repeaterGroups( field ) {
			var raw = field ? field.repeater_fields : null;

			if ( typeof raw === 'string' ) {
				try { raw = JSON.parse( raw || '[]' ); } catch ( e ) { raw = []; }
			}
			if ( ! Array.isArray( raw ) ) { raw = []; }

			var hasGroups = raw.some( function ( e ) { return e && Array.isArray( e.fields ); } );

			if ( ! hasGroups ) {
				raw = raw.length ? [ { fields: raw } ] : [];
			} else {
				var loose = raw.filter( function ( e ) { return e && ! Array.isArray( e.fields ); } );
				var real  = raw.filter( function ( e ) { return e && Array.isArray( e.fields ); } );
				raw = loose.length ? [ { fields: loose } ].concat( real ) : real;
			}

			return raw.map( function ( g, i ) {
				var cols = parseInt( g.columns, 10 );
				return {
					id:          g.id || 'g' + i,
					label:       g.label || '',
					columns:     ( cols >= 1 && cols <= 4 ) ? cols : 0,
					collapsible: !! g.collapsible,
					open:        typeof g.open === 'undefined' ? true : !! g.open,
					fields:      Array.isArray( g.fields ) ? g.fields.filter( Boolean ) : []
				};
			} );
		}

		// ── Per-field Checkbox & Radio overrides ──
		// A field stores finished CSS custom properties, keyed by property name — the
		// same shape the form-level Style tab stores, which is what lets both surfaces
		// run on the same controls. Emitted on the field wrapper, where a nearer
		// declaration beats the form's without a single !important.
		// A field may follow the form or pin itself to one treatment. 'inherit' is the
		// default, and is what every form saved before the option existed does.
		function bfFieldChoiceMode( field ) {
			var own = field && field.choice_style;
			return ( 'default' === own || 'button' === own ) ? own
				: ( 'button' === state.formSettings.choice_style ? 'button' : 'default' );
		}

		function bfChoiceStyles( field ) {
			var v = field && field.choice_styles;
			return ( v && 'object' === typeof v && ! Array.isArray( v ) ) ? v : {};
		}

		// ── Per-option icons ──
		// The registry is the server's (shapeforms_lite_choice_icons()), handed over in
		// the localize payload rather than restated here: the picker can then only
		// offer keys the sanitizer accepts, and the glyph it draws is the glyph the
		// front end will render. An absent registry simply means no picker.
		function bfChoiceIconSet() {
			var set = shapeformsLiteBuilder.choiceIcons;
			return ( set && 'object' === typeof set && ! Array.isArray( set ) ) ? set : {};
		}

		// Positional: entry N is option N's icon. Absent and '' both mean "none",
		// which is what nearly every option is.
		function bfOptionIcons( holder ) {
			return ( holder && Array.isArray( holder.option_icons ) ) ? holder.option_icons : [];
		}

		// One option's icon, in the shape it is stored in: a built-in glyph's KEY, an
		// { type: 'image', id, url } descriptor for a media-library file, or '' for
		// none. Mirrors shapeforms_lite_sanitize_option_icon().
		function bfOptionIcon( holder, index ) {
			return bfNormalizeIcon( bfOptionIcons( holder )[ index ] );
		}

		function bfNormalizeIcon( raw ) {
			if ( 'string' === typeof raw ) {
				return bfChoiceIconSet()[ raw ] ? raw : '';
			}
			if ( raw && 'object' === typeof raw && 'image' === raw.type && raw.url ) {
				return { type: 'image', id: Number( raw.id ) || 0, url: String( raw.url ) };
			}
			return '';
		}

		function bfIconIsImage( icon ) {
			return !! ( icon && 'object' === typeof icon && 'image' === icon.type );
		}

		// The value a trigger is currently carrying. Held as JSON on the element
		// because the option rows ARE the model — both editors read their options
		// back out of the DOM — and an uploaded file is more than a key.
		function bfTriggerIcon( $btn ) {
			var raw = $btn.attr( 'data-icon' );
			if ( ! raw ) { return ''; }
			try { return bfNormalizeIcon( JSON.parse( raw ) ); } catch ( e ) { return ''; }
		}

		// Pads up to the index being written, so option 3 keeps entry 3 even when the
		// three options before it have no icon, then drops trailing empties so a field
		// that has been cleared stores nothing at all.
		function bfSetOptionIcon( holder, index, icon ) {
			if ( ! holder ) { return; }
			var icons = bfOptionIcons( holder ).slice();
			while ( icons.length <= index ) { icons.push( '' ); }
			icons[ index ] = icon || '';
			while ( icons.length && '' === icons[ icons.length - 1 ] ) { icons.pop(); }
			holder.option_icons = icons;
		}

		// Mirrors shapeforms_lite_choice_icon_svg(). The path data comes from the server
		// registry, so it is written through as-is; only keys and labels are escaped.
		function bfChoiceIconSvg( key, size ) {
			var icon = bfChoiceIconSet()[ key ];
			if ( ! icon || ! icon.svg ) { return ''; }
			size = size || 16;
			return '<svg viewBox="0 0 24 24" width="' + size + '" height="' + size + '" fill="none" stroke="currentColor" ' +
				'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + icon.svg + '</svg>';
		}

		// The glyph or the file, at a given size. One definition, so the canvas, the
		// trigger and the picker all draw an icon the same way.
		function bfIconMarkup( icon, size ) {
			if ( bfIconIsImage( icon ) ) {
				return '<img src="' + escapeHtml( icon.url ) + '" alt="" width="' + ( size || 16 ) + '" height="' + ( size || 16 ) + '">';
			}
			return bfChoiceIconSvg( icon, size );
		}

		// Mirrors shapeforms_lite_choice_icon_html(): emitted whatever the treatment,
		// hidden by CSS outside the Button one. The canvas draws what the page draws.
		function bfChoiceIconHtml( holder, index ) {
			var markup = bfIconMarkup( bfOptionIcon( holder, index ) );
			return markup ? '<span class="shapeforms-lite-form__choice-icon" aria-hidden="true">' + markup + '</span>' : '';
		}

		// Only a checkbox or radio ever becomes a row of pills; a select's options
		// stay list rows whatever the Style says, so there is nowhere to put an icon
		// and the trigger is not offered.
		function bfIconableChoice( holder ) {
			var type = holder && holder.type;
			return ( 'checkbox' === type || 'radio' === type ) && 'button' === bfFieldChoiceMode( holder );
		}

		function bfIconName( icon ) {
			var l = shapeformsLiteBuilder.labels || {};
			if ( bfIconIsImage( icon ) ) { return l.customImage || 'Custom image'; }
			var def = bfChoiceIconSet()[ icon ];
			return def ? ( def.label || icon ) : '';
		}

		// The picker trigger. One definition for both places a choice's options are
		// edited — a top-level field's option rows and a repeater sub-field's icon
		// strip — so both write the same key into the same positional slot.
		// `inert` renders it present but unusable: the top-level rows keep the trigger
		// in the DOM whatever the treatment, because the options are collected FROM
		// the DOM and a trigger that came and went would take the icons with it.
		function bfOpticonBtn( holder, index, optionText, inert ) {
			var l    = shapeformsLiteBuilder.labels || {};
			var icon = bfOptionIcon( holder, index );
			var pick = l.chooseIcon || 'Choose an icon';

			return '<button type="button" class="shapeforms-opticon' + ( icon ? ' is-set' : '' ) +
				( bfIconIsImage( icon ) ? ' is-image' : '' ) + '"' +
				' data-bf-opticon="1" data-opt-index="' + index + '"' +
				' data-icon="' + escapeHtml( JSON.stringify( icon || '' ) ) + '"' +
				( inert ? ' hidden tabindex="-1"' : '' ) +
				' title="' + escapeHtml( icon ? pick + ' \u2014 ' + bfIconName( icon ) : pick ) + '"' +
				' aria-label="' + escapeHtml( optionText ? pick + ': ' + optionText : pick ) + '">' +
				( icon ? bfIconMarkup( icon, 16 ) : '<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>' ) +
			'</button>';
		}

		// Repaints one trigger in place. The settings panel is where the user is
		// working, so choosing an icon must not rebuild it and scroll them away from
		// the option they were looking at.
		function bfPaintOpticon( $btn, icon ) {
			var pick = ( shapeformsLiteBuilder.labels || {} ).chooseIcon || 'Choose an icon';
			icon = bfNormalizeIcon( icon );
			$btn.attr( 'data-icon', JSON.stringify( icon || '' ) )
				.attr( 'title', icon ? pick + ' \u2014 ' + bfIconName( icon ) : pick )
				.toggleClass( 'is-set', !! icon )
				.toggleClass( 'is-image', bfIconIsImage( icon ) )
				.html( icon ? bfIconMarkup( icon, 16 ) : '<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>' );
		}

		// A choice sub-field edits its options as rows, the way a top-level choice
		// field does: one row per option, each with its value, its icon and its own
		// Remove. The textarea this replaces could not carry an icon at all — there
		// was nothing to hang one on but the line itself — and made reordering or
		// removing an option a text-editing exercise.
		//
		// The icon trigger stays in every row whatever the treatment, `hidden`
		// outside the Button one, for the same reason the top-level rows keep it:
		// these rows ARE the model, read back on every edit, and a control that came
		// and went would take the icons with it.
		// Which of a sub-field's options start out selected. A list rather than Lite's
		// comma-joined string, because an option is free text and may contain a comma.
		// A radio or a select can only hold one, so the list is capped on the way out
		// as well as on the way in — switching a checkbox with three defaults to a
		// radio must not render three checked radios.
		function bfSubDefaults( holder ) {
			var raw = holder && holder.default_value;

			if ( 'string' === typeof raw ) { raw = '' === raw ? [] : [ raw ]; }
			if ( ! Array.isArray( raw ) ) { return []; }

			var opts = ( holder && Array.isArray( holder.options ) ) ? holder.options.map( function ( o ) {
				return $.trim( ( o || '' ).toString() );
			} ) : [];

			var out = [];
			raw.forEach( function ( v ) {
				v = $.trim( ( v || '' ).toString() );
				if ( v && opts.indexOf( v ) !== -1 && out.indexOf( v ) === -1 ) { out.push( v ); }
			} );

			return 'checkbox' === ( holder && holder.type ) ? out : out.slice( 0, 1 );
		}

		function bfSubOptionsMarkup( holder ) {
			var l     = shapeformsLiteBuilder.labels || {};
			var opts  = ( holder && Array.isArray( holder.options ) ) ? holder.options : [];
			var inert = ! bfIconableChoice( holder );
			var picked = bfSubDefaults( holder );
			var multi  = 'checkbox' === ( holder && holder.type );
			// Radios have to be a group, and the group is this sub-field — otherwise
			// choosing a default in one sub-field would clear it in the next.
			var group  = 'bf-rep-opt-default-' + ( ( holder && holder.id ) || 'x' );
			var rows   = '';

			opts.forEach( function ( opt, i ) {
				var text = ( opt || '' ).toString();
				var on   = picked.indexOf( $.trim( text ) ) !== -1;
				rows += '<div class="shapeforms-rep-opt">' +
					// The same control a top-level choice field has: which option a
					// fresh row opens with already selected.
					'<label class="shapeforms-rep-opt__default' + ( on ? ' is-checked' : '' ) + '" title="' + escapeHtml( l.setDefault || 'Set as default' ) + '">' +
						'<input type="' + ( multi ? 'checkbox' : 'radio' ) + '" name="' + escapeHtml( group ) + '"' + ( on ? ' checked' : '' ) + '>' +
						'<span class="shapeforms-rep-opt__mark' + ( multi ? ' is-box' : '' ) + '"></span>' +
					'</label>' +
					'<span class="shapeforms-rep-opt__drag dashicons dashicons-menu" draggable="true" title="' + escapeHtml( 'Drag to reorder' ) + '"></span>' +
					bfOpticonBtn( holder, i, $.trim( text ), inert ) +
					'<input type="text" class="shapeforms-rep-opt__value" value="' + escapeHtml( text ) + '" placeholder="' + escapeHtml( l.optionPlaceholder || 'Option value' ) + '">' +
					'<button type="button" class="shapeforms-rep-opt__remove" title="' + escapeHtml( ( shapeformsLiteBuilder.actions || {} ).delete || 'Remove' ) + '"><span class="dashicons dashicons-no-alt"></span></button>' +
				'</div>';
			} );

			return '<div class="shapeforms-rep-field-row__options">' +
				'<label class="shapeforms-rep-field__options-label">' + escapeHtml( l.options || 'Options' ) + '</label>' +
				'<div class="shapeforms-rep-opts">' + rows + '</div>' +
				'<button type="button" class="shapeforms-rep-opt__add"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> ' + escapeHtml( l.addOption || 'Add Option' ) + '</button>' +
			'</div>';
		}

		// Rebuilds a sub-field's options AND its icons from its rows, in one pass, in
		// DOM order — the same rule the top-level editor follows, so the two lists
		// cannot drift apart however the rows were changed.
		//
		// A blank value is KEPT here, unlike at the top level: a just-added option is
		// blank, and dropping it would take the row out from under the caret. Blanks
		// are dropped by the Pro sanitizer on save, which packs the icons onto the
		// options that survive.
		function collectSubOptions( $list ) {
			var options  = [];
			var icons    = [];
			var defaults = [];

			$list.find( '.shapeforms-rep-opt' ).each( function () {
				var $row  = $( this );
				var value = ( $row.find( '.shapeforms-rep-opt__value' ).val() || '' ).toString();

				options.push( value );
				icons.push( bfTriggerIcon( $row.find( '[data-bf-opticon]' ) ) );

				// Stored by VALUE, not by index, so a reorder needs no remapping and a
				// blank row simply cannot be the default.
				if ( $row.find( '.shapeforms-rep-opt__default input' ).is( ':checked' ) && $.trim( value ) ) {
					defaults.push( $.trim( value ) );
				}
			} );

			while ( icons.length && '' === icons[ icons.length - 1 ] ) {
				icons.pop();
			}

			return { options: options, icons: icons, defaults: defaults };
		}

		// Inline declarations for the canvas, so the preview shows what will render.
		// Mirrors shapeforms_lite_choice_style_declarations() in shapeforms.php.
		function bfChoiceStyleAttr( field ) {
			var styles = bfChoiceStyles( field );
			var allow  = bfChoiceAllVars().concat( bfChoiceTypographyVars() );
			var parts  = [];

			allow.forEach( function ( cssVar ) {
				if ( styles[ cssVar ] ) { parts.push( cssVar + ':' + styles[ cssVar ] ); }
			} );

			return parts.length ? ' style="' + escapeHtml( parts.join( ';' ) ) + '"' : '';
		}

		// The typography control writes a family of derived properties off one base
		// name, so they are not in bfChoiceAllVars() and have to be spelled out.
		function bfChoiceTypographyVars() {
			return [ '--bf-choice-color' ].concat(
				[ '-ff', '-fs', '-fw', '-lh', '-ls', '-tt' ].map( function ( sfx ) { return '--bf-choice' + sfx; } )
			);
		}

		// The override block: the Style tab's own Checkbox & Radio controls, pointed at
		// this field. Rendered inline rather than behind a disclosure, because the
		// settings panel is rebuilt on every change and a collapsed section would
		// reopen itself on each edit.
		function bfChoiceStyleMarkup( field ) {
			var l = shapeformsLiteBuilder.labels || {};

			// Read-only scope for the render pass; writes arrive later through
			// recomputeAdvField(), which resolves its own scope from the DOM.
			var prev = bfStyleScope;
			bfStyleScope = bfFieldStyleScope( { field: field, commit: function () {} } );

			var mode = bfFieldChoiceMode( field );
			var body;
			try {
				body = bfOrderControls( bfChoiceControls( false, mode ) ).map( advControl ).join( '' );
			} finally {
				bfStyleScope = prev;
			}

			// The treatment this field renders in. Three options, not two: a field has
			// to be able to say "follow the form", which is what it does today.
			var own  = ( 'default' === ( field && field.choice_style ) || 'button' === ( field && field.choice_style ) ) ? field.choice_style : 'inherit';
			var modeCtl = '<div class="shapeforms-setting-group shapeforms-choice-style__mode">' +
				'<label>' + escapeHtml( advLabel( 'choiceStyle' ) ) + '</label>' +
				'<div class="shapeforms-btn-group shapeforms-choice-style__modegroup">' +
					[ [ 'inherit', shapeformsLiteBuilder.labels.choiceStyleInherit || 'Form default' ],
					  [ 'default', advLabel( 'choiceStyleDefault' ) ],
					  [ 'button',  advLabel( 'choiceStyleButton' ) ] ].map( function ( o ) {
						return '<button type="button" class="shapeforms-btn-group__btn' + ( o[0] === own ? ' is-active' : '' ) +
							'" data-choice-mode="' + o[0] + '">' + escapeHtml( o[1] ) + '</button>';
					} ).join( '' ) +
				'</div>' +
			'</div>';

			var count = Object.keys( bfChoiceStyles( field ) ).length;
			var open  = !! bfChoiceOpen[ field && field.id ];

			// Collapsed by default: this is a long section and most fields never
			// override anything, so it should not push the controls a user actually
			// came for off the bottom of the panel.
			return '<div class="shapeforms-choice-style' + ( open ? ' is-open' : '' ) + '" data-bf-choice-scope="1">' +
				'<div class="shapeforms-choice-style__head">' +
					'<button type="button" class="shapeforms-choice-style__toggle" aria-expanded="' + ( open ? 'true' : 'false' ) + '">' +
						'<span class="shapeforms-choice-style__caret" aria-hidden="true"></span>' +
						'<span class="shapeforms-choice-style__title">' + escapeHtml( l.choiceOverride || 'Options appearance' ) + '</span>' +
						// A count, so a collapsed block still says whether this field
						// has been customised at all.
						( count ? '<span class="shapeforms-choice-style__badge">' + count + '</span>' : '' ) +
					'</button>' +
					'<button type="button" class="shapeforms-choice-style__reset"' + ( count ? '' : ' disabled' ) + '>' +
						escapeHtml( l.choiceOverrideReset || 'Reset' ) +
					'</button>' +
				'</div>' +
				'<div class="shapeforms-choice-style__body">' +
					modeCtl +
					'<div class="shapeforms-adv-grid">' + body + '</div>' +
					'<p class="shapeforms-choice-style__hint">' + escapeHtml( l.choiceOverrideHint || 'Empty follows the form style' ) + '</p>' +
				'</div>' +
			'</div>';
		}

		// Open/closed per field id, not per element: the settings panel is rebuilt on
		// every edit, so without this the section would slam shut mid-change — the
		// same "feels like it refreshed" problem the Style tab had.
		var bfChoiceOpen = {};

		// Only this block's Reset — the Style tab's section resets are refreshed by
		// bfRefreshResetStates(), which reads the form map and must not see a field.
		function bfRefreshChoiceReset() {
			$( '.shapeforms-choice-style' ).each( function () {
				var t = bfChoiceStyleTarget( $( this ) );
				var n = ( t && t.field ) ? Object.keys( bfChoiceStyles( t.field ) ).length : 0;
				$( this ).find( '.shapeforms-choice-style__reset' ).prop( 'disabled', ! n );

				// Keep the collapsed-state count honest without re-rendering.
				var $toggle = $( this ).find( '.shapeforms-choice-style__toggle' );
				var $badge  = $toggle.find( '.shapeforms-choice-style__badge' );
				if ( ! n ) {
					$badge.remove();
				} else if ( $badge.length ) {
					$badge.text( n );
				} else {
					$toggle.append( '<span class="shapeforms-choice-style__badge">' + n + '</span>' );
				}
			} );
		}

		// Only 'center', 'right' and 'full' are stored as a deviation; anything else —
		// including an absent key on a form saved before the option existed — is 'left'.
		function repAddAlign( field ) {
			var v = field && field.repeater_add_align;
			return ( 'center' === v || 'right' === v || 'full' === v ) ? v : 'left';
		}

		// A row is removable only when the repeater can hold more rows than its
		// minimum: it opens at min_rows and Remove appears once the count exceeds it.
		// With max <= min the button can never be reached, so its two settings are
		// controls for something that cannot happen and the panel leaves them out.
		function repRemovable( field ) {
			var min = Math.max( 1, Math.min( 10, parseInt( field && field.repeater_min_rows, 10 ) || 1 ) );
			var max = Math.max( 1, Math.min( 20, parseInt( field && field.repeater_max_rows, 10 ) || 5 ) );
			return max > min;
		}

		// '' means "follow the row title" — the behaviour every repeater had before the
		// option existed. Only 'top' and 'bottom' are stored as a deviation.
		function repRemovePos( field ) {
			var v = field && field.repeater_remove_position;
			return ( 'top' === v || 'bottom' === v ) ? v : '';
		}

		// What '' actually resolves to, which is what the control shows. A form saved
		// before the setting existed has no stored value, and offering the user a
		// third "whatever it was" choice explains nothing — the panel shows the real
		// position instead, and clicking either segment writes it down explicitly.
		function repRemoveEffective( field ) {
			var v = repRemovePos( field );
			if ( v ) { return v; }
			return '' !== ( ( field && field.repeater_row_label ) || '' ) ? 'top' : 'bottom';
		}

		// Total sub-fields across every group — the cap counts the repeater, not a group.
		function repeaterFieldCount( groups ) {
			return groups.reduce( function ( n, g ) { return n + g.fields.length; }, 0 );
		}

		// Write groups back, and keep state.structure the canonical owner of the shape.
		function setRepeaterGroups( field, groups ) {
			field.repeater_fields = groups;
		}

		function getLibraryItem( type ) {
			return shapeformsLiteBuilder.fieldLibrary[ type ] || { label: type, icon: 'dashicons-editor-textcolor', group: 'basic' };
		}

		function hasSubmitField() {
			var found = false;
			state.structure.rows.forEach( function ( row ) {
				row.columns.forEach( function ( col ) {
					col.fields.forEach( function ( f ) {
						if ( 'submit' === f.type ) { found = true; }
					});
				});
			});
			return found;
		}

		function normalizeFormSettings( settings ) {
			var submissionType = settings && settings.submission_type ? settings.submission_type : ( settings && settings.enable_redirect ? 'redirect' : 'ajax' );
			var adminEmail = settings && settings.admin_email ? settings.admin_email : '';
			var adminEmailType = settings && settings.admin_email_type ? settings.admin_email_type : ( adminEmail ? 'custom' : 'site_admin' );

			var normalized = {
				submission_type: submissionType,
				enable_ajax: 'ajax' === submissionType,
				enable_redirect: 'redirect' === submissionType,
				redirect_type: settings && settings.redirect_type ? settings.redirect_type : ( settings && settings.redirect_url ? 'custom' : 'page' ),
				redirect_url: settings && settings.redirect_url ? settings.redirect_url : '',
				thank_you_message: settings && settings.thank_you_message ? settings.thank_you_message : ( shapeformsLiteBuilder.defaults && shapeformsLiteBuilder.defaults.thankYouMessage || 'Thanks! Your form was submitted successfully.' ),
				// Default ONLY when button_text was never set (new form). An explicit
				// empty string is a deliberate icon-only button, so it must survive a
				// reload — a truthiness check would wrongly reset '' back to 'Submit'.
				// Mirrors the server default logic (shortcode uses isset(), not truthy).
				button_text: settings && typeof settings.button_text !== 'undefined' ? settings.button_text : ( shapeformsLiteBuilder.defaults && shapeformsLiteBuilder.defaults.submitText || 'Submit' ),
				button_alignment: settings && settings.button_alignment ? settings.button_alignment : 'left',
				button_icon_type: settings && settings.button_icon_type ? settings.button_icon_type : 'none',
				button_icon_dashicon: settings && settings.button_icon_dashicon ? settings.button_icon_dashicon : 'dashicons-arrow-right-alt',
				button_icon_svg: settings && settings.button_icon_svg ? settings.button_icon_svg : '',
				button_icon_position: settings && settings.button_icon_position ? settings.button_icon_position : 'right',
				button_icon_gap: settings && settings.button_icon_gap !== '' && typeof settings.button_icon_gap !== 'undefined' ? settings.button_icon_gap : '8',
				button_icon_size: settings && settings.button_icon_size !== '' && typeof settings.button_icon_size !== 'undefined' ? settings.button_icon_size : '18',
				button_icon_color: settings && settings.button_icon_color ? settings.button_icon_color : '',
				button_color: settings && settings.button_color ? settings.button_color : 'teal',
				field_style: settings && settings.field_style ? settings.field_style : '',
				field_size: settings && settings.field_size ? settings.field_size : 'small',
				field_focus_color: settings && settings.field_focus_color ? settings.field_focus_color : '',
				field_border_width: settings && settings.field_border_width !== '' && typeof settings.field_border_width !== 'undefined' ? Number( settings.field_border_width ) : '',
				field_border_radius: settings && settings.field_border_radius !== '' && typeof settings.field_border_radius !== 'undefined' ? Number( settings.field_border_radius ) : '',
				field_background_color: settings && settings.field_background_color ? settings.field_background_color : '',
				field_border_color: settings && settings.field_border_color ? settings.field_border_color : '',
				field_text_color: settings && settings.field_text_color ? settings.field_text_color : '',
				label_size: settings && settings.label_size ? settings.label_size : '',
				label_color: settings && settings.label_color ? settings.label_color : '',
				label_subtext_color: settings && settings.label_subtext_color ? settings.label_subtext_color : '',
				error_color: settings && settings.error_color ? settings.error_color : '',
				button_size: settings && settings.button_size ? settings.button_size : 'small',
				button_border_style: settings && settings.button_border_style ? settings.button_border_style : '',
				button_border_width: settings && settings.button_border_width !== '' && typeof settings.button_border_width !== 'undefined' ? Number( settings.button_border_width ) : '',
				button_border_radius: settings && settings.button_border_radius !== '' && typeof settings.button_border_radius !== 'undefined' ? Number( settings.button_border_radius ) : '',
				button_background_color: settings && settings.button_background_color ? settings.button_background_color : '',
				button_border_color: settings && settings.button_border_color ? settings.button_border_color : '',
				button_text_color: settings && settings.button_text_color ? settings.button_text_color : '',
				admin_email_type: adminEmailType,
				enable_admin_email: ! settings || typeof settings.enable_admin_email === 'undefined' ? true : !! settings.enable_admin_email,
				enable_user_email: ! settings || typeof settings.enable_user_email === 'undefined' ? true : !! settings.enable_user_email,
				admin_email: adminEmail,
				design_theme: settings && settings.design_theme ? settings.design_theme : '',
				hide_labels: false,
				hide_placeholders: false,
				// Checkbox/radio presentation. Structural rather than cosmetic — it
				// swaps which element carries the visual treatment — so it lives here
				// as a form setting, not in the per-device style layer: "buttons on
				// desktop, boxes on mobile" is not a thing anyone wants, and a class
				// cannot be media-queried anyway. The --bf-choice-btn-* vars that
				// dress the button ARE in the style layer and stay per-device.
				choice_style: settings && 'button' === settings.choice_style ? 'button' : 'default',
				dup_enabled:  settings && settings.dup_enabled  ? true : false,
				dup_method:   settings && settings.dup_method   ? settings.dup_method   : 'email',
				dup_field_id: settings && settings.dup_field_id ? settings.dup_field_id : '',
				dup_message:  settings && settings.dup_message  ? settings.dup_message  : '',
				// Conversational mode. Presentation only — never alters the stored
				// form structure, so these survive switching the mode off and back on.
				cv_enabled:         settings && settings.cv_enabled ? true : false,
				cv_progress:        settings && settings.cv_progress   ? settings.cv_progress   : 'bar',
				cv_transition:      settings && settings.cv_transition ? settings.cv_transition : 'slide',
				cv_key_hint:        ! settings || typeof settings.cv_key_hint === 'undefined' ? true : !! settings.cv_key_hint,
				cv_next_text:       settings && settings.cv_next_text  ? settings.cv_next_text  : '',
				cv_prev_text:       settings && settings.cv_prev_text  ? settings.cv_prev_text  : '',
				cv_bg:              settings && settings.cv_bg              ? settings.cv_bg              : '',
				cv_question_color:  settings && settings.cv_question_color  ? settings.cv_question_color  : '',
				cv_answer_color:    settings && settings.cv_answer_color    ? settings.cv_answer_color    : '',
				cv_btn_color:       settings && settings.cv_btn_color       ? settings.cv_btn_color       : '',
				cv_btn_text_color:  settings && settings.cv_btn_text_color  ? settings.cv_btn_text_color  : '',
				cv_accent:          settings && settings.cv_accent          ? settings.cv_accent          : '',
				cv_track_color:     settings && settings.cv_track_color     ? settings.cv_track_color     : '',
				cv_prev_color:      settings && settings.cv_prev_color      ? settings.cv_prev_color      : '',
				cv_prev_bg:         settings && settings.cv_prev_bg         ? settings.cv_prev_bg         : '',
				cv_hint_color:      settings && settings.cv_hint_color      ? settings.cv_hint_color      : '',
				cv_nav_align:       settings && settings.cv_nav_align       ? settings.cv_nav_align       : 'left',
				cv_progress_align:  settings && settings.cv_progress_align  ? settings.cv_progress_align  : 'left',
				cv_welcome_enabled: settings && settings.cv_welcome_enabled ? true : false,
				cv_welcome_title:   settings && settings.cv_welcome_title ? settings.cv_welcome_title : '',
				cv_welcome_text:    settings && settings.cv_welcome_text  ? settings.cv_welcome_text  : '',
				cv_welcome_btn:     settings && settings.cv_welcome_btn   ? settings.cv_welcome_btn   : '',
				cv_media_hide_mobile: ! settings || typeof settings.cv_media_hide_mobile === 'undefined' ? true : !! settings.cv_media_hide_mobile,
				style: normalizeStyleSettings( settings && settings.style ),
			};

			// Preserve any extra keys (e.g. Pro module settings like Scheduling's
			// schedule_*) that Lite localizes but doesn't model above, so their builder
			// panes can repopulate saved values on reload. The core keys normalized
			// above always win; unknown keys pass through untouched. Pro re-collects its
			// own live values on shapeforms:before_save, so this is purely a read passthrough.
			return $.extend( {}, settings, normalized );
		}

		// Advanced per-device style vars written by the Style tab. Shape:
		// { desktop: { '--bf-*': value }, tablet: {…}, mobile: {…} }. Mirrors the
		// PHP normalize_style_settings(); only ShapeForms-namespaced string vars survive.
		function normalizeStyleSettings( style ) {
			var out = { desktop: {}, tablet: {}, mobile: {} };
			if ( ! style || typeof style !== 'object' ) {
				return out;
			}
			[ 'desktop', 'tablet', 'mobile' ].forEach( function ( device ) {
				if ( style[ device ] && typeof style[ device ] === 'object' ) {
					Object.keys( style[ device ] ).forEach( function ( key ) {
						if ( /^--bf-[a-z0-9-]+$/.test( key ) && typeof style[ device ][ key ] === 'string' ) {
							out[ device ][ key ] = style[ device ][ key ];
						}
					} );
				}
			} );
			return out;
		}

		// Default placeholder text for a field type (localized in PHP). Pre-filled into a
		// new field's Placeholder setting so the same value shows in settings, canvas,
		// preview and on the front end; users can edit or clear it per field.
		function getDefaultPlaceholder( type ) {
			var map = ( shapeformsLiteBuilder.defaults && shapeformsLiteBuilder.defaults.placeholders ) || {};
			if ( typeof map[ type ] !== 'undefined' ) {
				return map[ type ];
			}
			// An amount box renders empty, so it needs a prompt of its own.
			return 'custom_amount' === type ? 'Enter amount' : '';
		}

		/**
		 * Currency symbol for builder previews. Supplied by the payments add-on;
		 * falls back to "$" so Lite never depends on it being present.
		 *
		 * @return {string} Currency symbol.
		 */
		function getPaymentCurrencySymbol() {
			return shapeformsLiteBuilder.paymentCurrencySymbol || '$';
		}

		/**
		 * Whether a Custom Amount field has its "Set Amount Limits" switch on.
		 *
		 * Mirrors the add-on's server-side rule: fields saved before the switch
		 * existed carry no flag, so a positive minimum or any maximum is read as
		 * limits being in force. Without that, upgrading would silently drop a
		 * range an admin had deliberately configured.
		 *
		 * @param {Object} field Field definition.
		 * @return {boolean} True when limits apply.
		 */
		function hasAmountLimits( field ) {
			if ( ! field ) {
				return false;
			}
			if ( typeof field.amount_limits !== 'undefined' ) {
				return !! field.amount_limits;
			}
			return parseFloat( field.amount_min || 0 ) > 0 || parseFloat( field.amount_max || 0 ) > 0;
		}

		function createField( type ) {
			var libraryItem = getLibraryItem( type );

			return {
				id: generateId(),
				type: type,
				label: libraryItem.label,
				placeholder: getDefaultPlaceholder( type ),
				// A Custom Amount is a payment field: leaving it blank means no
				// payment, so it is required out of the box. Every other type stays
				// optional by default.
				required: 'custom_amount' === type,
				default_value: '',
				options: optionFieldTypes.indexOf( type ) !== -1 ? [ shapeformsLiteBuilder.defaults && shapeformsLiteBuilder.defaults.option1 || 'Option 1', shapeformsLiteBuilder.defaults && shapeformsLiteBuilder.defaults.option2 || 'Option 2' ] : [],
				options_layout: optionFieldTypes.indexOf( type ) !== -1 ? 'block' : '',
				checkbox_style: 'checkbox' === type ? 'default' : '',
				// Per-field Checkbox & Radio overrides. Empty means "inherit the form",
				// so a new field adds nothing to fields_json until something is set.
				choice_styles: {},
				// 'inherit' follows the form's Checkbox & Radio Style option.
				choice_style: optionFieldTypes.indexOf( type ) !== -1 ? 'inherit' : '',
				// Positional per-option icons, empty until one is chosen.
				option_icons: [],
				content: 'terms_conditions' === type ? ( shapeformsLiteBuilder.defaults && shapeformsLiteBuilder.defaults.termsContent || 'I agree to the <a href="#">terms and conditions</a>.' ) : '',
				description: 'section_break' === type ? ( shapeformsLiteBuilder.defaults && shapeformsLiteBuilder.defaults.sectionDesc || 'Add a short description for this section.' ) : '',
				custom_error: '',
				allowed_types: 'file' === type ? '.jpg,.jpeg,.png,.gif,.pdf,.doc,.docx' : '',
				max_file_size: 'file' === type ? '5' : '',
				css_class: '',
				label_placement: 'top',
				show_middle_name: true,
				show_last_name: true,
				address_fields: { street: true, city: true, state: true, zip: true, country: true },
				address_order: [ 'street', 'city', 'state', 'zip', 'country' ],
				conditional: { enabled: false, action: 'show', field_id: '', operator: 'is', value: '' },
				select_searchable: false,
				select_multiple: false,
				mask_pattern: '',
				min_value: '',
				max_value: '',
				step_value: '',
				max_stars: 5,
				star_color: '',
				star_inactive_color: '',
				star_size: '20',
				slider_color: '',
				slider_height: '',
				dual_handle: false,
				step_title: '',
				next_text: '',
				prev_text: '',
				btn_color: '',
				btn_text_color: '',
				btn_size: '',
				btn_radius: '',
				progress_color: '',
				progress_style: '',
				product_options: 'product' === type ? [ { label: 'Option 1', price: '10.00' } ] : [],
				product_style: 'product' === type ? 'radio' : '',
				linked_product: 'quantity' === type ? '' : '',
				qty_min: 'quantity' === type ? '1' : '',
				qty_max: 'quantity' === type ? '' : '',
				qty_default: 'quantity' === type ? '1' : '',
				// Limits are opt-in. The min/max are seeded with sensible figures so
				// switching "Set Amount Limits" on lands on a usable range, but they
				// stay inert — and unrendered — until it is on.
				amount_limits: false,
				amount_min: 'custom_amount' === type ? '10.00' : '',
				amount_max: 'custom_amount' === type ? '500.00' : '',
				// Empty: a donation box pre-filled with 0.00 forces every visitor to
				// clear it before they can type their own figure.
				amount_default: '',
				auto_populate_key: '',
				calc_formula:  'calculation' === type ? '' : '',
				calc_decimals: 'calculation' === type ? 2 : 2,
				calc_prefix:   '',
				calc_suffix:   '',
				// Seed a new repeater with two text sub-fields so the settings list, the
				// canvas preview AND the front end all show real, editable sub-fields from
				// the start (a repeater with zero sub-fields renders an empty, unusable row).
				repeater_add_align: 'repeater' === type ? 'left' : '',
				repeater_remove_position: '',
				repeater_fields: 'repeater' === type ? [ {
					id: 'g_' + Math.random().toString( 36 ).slice( 2, 8 ),
					label: '', columns: 0, collapsible: false, open: true,
					fields: [
						{ id: 'sf_' + Math.random().toString( 36 ).slice( 2, 8 ), type: 'text', label: '', placeholder: '', required: false, full_width: false },
						{ id: 'sf_' + Math.random().toString( 36 ).slice( 2, 8 ), type: 'text', label: '', placeholder: '', required: false, full_width: false }
					]
				} ] : []
			};
		}

		function createTemplateField( type, overrides ) {
			var field = createField( type );

			return $.extend( true, field, overrides || {} );
		}

		/**
		 * Copies a screen's own styling across a normalize — whichever of those
		 * keys are present, and only those.
		 *
		 * normalizeField() and normalizeStructure() rebuild every field and row
		 * from a whitelist, so a key not named here is dropped the moment a saved
		 * form is reopened — and written away by the next save. The values would
		 * round-trip through the database perfectly and still disappear.
		 *
		 * Both normalizers need it because a screen is a row OR a field depending
		 * on the layout, and the styling lives on whichever one it is.
		 *
		 * cv_media_preview is the odd one out: the server resolves it into the
		 * builder's structure and it is never persisted (ajax-save drops it, on
		 * purpose — a stored URL breaks when the site moves). Dropping it here
		 * would empty the thumbnail every time the form was reopened.
		 *
		 * Absent stays absent: defaulting each one to '' would turn "inherits"
		 * into a stored value on every field of every form ever opened, which is
		 * the exact pinning the empty string exists to avoid.
		 *
		 * This is the builder's half of the contract sanitize_cv_media() and
		 * sanitize_cv_colours() enforce on the way into the database.
		 *
		 * THE LIST LIVES IN HERE, and must stay here. `state` is built at the top
		 * of this file and calls normalizeStructure() on the way — which reaches
		 * this function long before execution has passed any `var` between the
		 * two. A function declaration hoists whole, so the call works; a `var`
		 * hoists as undefined, so the list would not exist yet and the builder
		 * would die on load with "Cannot read properties of undefined".
		 */
		/**
		 * Where a range's thumb sits, 0-100, whatever its own min and max are.
		 *
		 * Brightness runs 10-100, so its value is NOT its position: at 10 the
		 * thumb is at the far left, not a tenth of the way along. Both the
		 * rendered track and the drag handler read this so they cannot disagree.
		 */
		function rangePercent( value, min, max ) {
			var lo   = Number( min );
			var hi   = Number( max );
			var span = hi - lo;

			if ( ! span ) {
				return 0;
			}

			return Math.max( 0, Math.min( 100, Math.round( ( ( Number( value ) - lo ) / span ) * 100 ) ) );
		}

		function carryScreenKeys( from, to ) {
			var keys = [
				'cv_media_id', 'cv_media_layout', 'cv_media_x', 'cv_media_y',
				'cv_media_brightness', 'cv_media_height', 'cv_media_alt', 'cv_media_preview',
				'cv_bg', 'cv_question_color', 'cv_answer_color',
				'cv_btn_color', 'cv_btn_text_color', 'cv_accent'
			];

			keys.forEach( function ( key ) {
				if ( from && typeof from[ key ] !== 'undefined' ) {
					to[ key ] = from[ key ];
				}
			} );

			return to;
		}

		function normalizeField( field ) {
			var type = field && field.type ? field.type : 'text';
			var normalized = createField( type );

			normalized.id = field && field.id ? field.id : generateId();
			normalized.label = field && typeof field.label !== 'undefined' ? field.label : normalized.label;
			normalized.placeholder = field && typeof field.placeholder !== 'undefined' ? field.placeholder : '';
			normalized.required = !! ( field && field.required );
			normalized.default_value = field && typeof field.default_value !== 'undefined' ? field.default_value : '';
			normalized.options = field && Array.isArray( field.options ) ? field.options : normalized.options;
			normalized.options_layout = field && field.options_layout ? field.options_layout : normalized.options_layout;
			normalized.checkbox_style = field && 'switch' === field.checkbox_style ? 'switch' : normalized.checkbox_style;
			// normalizeField() rebuilds from createField(), so an unlisted key is
			// dropped on reload — the overrides have to be carried explicitly.
			normalized.choice_style = ( field && ( 'default' === field.choice_style || 'button' === field.choice_style ) ) ? field.choice_style : normalized.choice_style;
			// Delegated to repAddAlign(), which is the single definition of what the
			// key may hold. Repeating the allowlist here is how 'full' came back from
			// the database and was silently rewritten to 'left' on reload.
			normalized.repeater_add_align = 'repeater' === type ? repAddAlign( field ) : normalized.repeater_add_align;
			normalized.repeater_remove_position = repRemovePos( field );
			normalized.choice_styles = field && field.choice_styles && 'object' === typeof field.choice_styles && ! Array.isArray( field.choice_styles ) ? field.choice_styles : {};
			// Positional, so it is carried as a whole: re-deriving it from anything
			// else would have to guess which option each entry belonged to.
			normalized.option_icons = field && Array.isArray( field.option_icons ) ? field.option_icons : [];
			normalized.content = field && typeof field.content !== 'undefined' ? field.content : normalized.content;
			normalized.description = field && typeof field.description !== 'undefined' ? field.description : normalized.description;
			normalized.custom_error = field && typeof field.custom_error !== 'undefined' ? field.custom_error : '';
			normalized.allowed_types = field && typeof field.allowed_types !== 'undefined' ? field.allowed_types : normalized.allowed_types;
			normalized.max_file_size = field && typeof field.max_file_size !== 'undefined' ? field.max_file_size : normalized.max_file_size;
			normalized.css_class = field && typeof field.css_class !== 'undefined' ? field.css_class : '';
			normalized.label_placement = field && field.label_placement ? field.label_placement : 'top';
			normalized.show_middle_name = ! field || typeof field.show_middle_name === 'undefined' ? true : !! field.show_middle_name;
			normalized.show_last_name = ! field || typeof field.show_last_name === 'undefined' ? true : !! field.show_last_name;
			normalized.address_fields = field && field.address_fields ? {
				street: typeof field.address_fields.street === 'undefined' ? true : !! field.address_fields.street,
				city: typeof field.address_fields.city === 'undefined' ? true : !! field.address_fields.city,
				state: typeof field.address_fields.state === 'undefined' ? true : !! field.address_fields.state,
				zip: typeof field.address_fields.zip === 'undefined' ? true : !! field.address_fields.zip,
				country: typeof field.address_fields.country === 'undefined' ? true : !! field.address_fields.country
			} : { street: true, city: true, state: true, zip: true, country: true };
			normalized.address_order = field && Array.isArray( field.address_order ) && field.address_order.length ? field.address_order : [ 'street', 'city', 'state', 'zip', 'country' ];
			if ( field && field.conditional ) {
				var rawCond = field.conditional;
				// Preserve the saved multi-condition structure; fall back to the
				// legacy flat { field_id, operator, value } shape for old forms.
				var condList = Array.isArray( rawCond.conditions ) && rawCond.conditions.length
					? rawCond.conditions.map( function ( c ) {
						c = c || {};
						return {
							field_id: c.field_id || '',
							operator: c.operator || 'is',
							value: typeof c.value !== 'undefined' && c.value !== null ? c.value : ''
						};
					} )
					: [ {
						field_id: rawCond.field_id || '',
						operator: rawCond.operator || 'is',
						value: rawCond.value || ''
					} ];
				normalized.conditional = {
					enabled: !! rawCond.enabled,
					action: rawCond.action || 'show',
					logic: 'OR' === String( rawCond.logic || '' ).toUpperCase() ? 'OR' : 'AND',
					conditions: condList
				};
			} else {
				normalized.conditional = { enabled: false, action: 'show', logic: 'AND', conditions: [ { field_id: '', operator: 'is', value: '' } ] };
			}
			normalized.select_searchable = !! ( field && field.select_searchable );
			normalized.select_multiple = !! ( field && field.select_multiple );
			normalized.mask_pattern = field && typeof field.mask_pattern !== 'undefined' ? field.mask_pattern : '';
			normalized.min_value = field && typeof field.min_value !== 'undefined' ? field.min_value : '';
			normalized.max_value = field && typeof field.max_value !== 'undefined' ? field.max_value : '';
			normalized.step_value = field && typeof field.step_value !== 'undefined' ? field.step_value : '';
			normalized.max_stars = field && field.max_stars ? Number( field.max_stars ) : 5;
			// Treat the legacy forced default (#f59e0b) as "unset" so existing star
			// fields follow the theme accent; a deliberate custom color is kept.
			normalized.star_color = field && field.star_color && '#f59e0b' !== field.star_color ? field.star_color : '';
			normalized.star_inactive_color = field && field.star_inactive_color ? field.star_inactive_color : '';
			normalized.star_size = field && field.star_size ? field.star_size : '20';
			normalized.slider_color = field && field.slider_color ? field.slider_color : '';
			normalized.slider_height = field && field.slider_height ? field.slider_height : '';
			normalized.dual_handle = !! ( field && field.dual_handle );
			normalized.step_title = field && typeof field.step_title !== 'undefined' ? field.step_title : '';
			normalized.next_text = field && typeof field.next_text !== 'undefined' ? field.next_text : 'Next';
			normalized.prev_text = field && typeof field.prev_text !== 'undefined' ? field.prev_text : 'Previous';
			normalized.btn_color = field && typeof field.btn_color !== 'undefined' ? field.btn_color : '';
			normalized.btn_text_color = field && typeof field.btn_text_color !== 'undefined' ? field.btn_text_color : '';
			normalized.btn_size = field && typeof field.btn_size !== 'undefined' ? field.btn_size : 'medium';
			normalized.btn_radius = field && typeof field.btn_radius !== 'undefined' ? field.btn_radius : '';
			normalized.progress_color = field && typeof field.progress_color !== 'undefined' ? field.progress_color : '';
			normalized.progress_style = field && typeof field.progress_style !== 'undefined' ? field.progress_style : 'bar';
			normalized.product_options = field && Array.isArray( field.product_options ) ? field.product_options : [];
			normalized.product_style = field && field.product_style ? field.product_style : 'radio';
			normalized.linked_product = field && typeof field.linked_product !== 'undefined' ? field.linked_product : '';
			normalized.qty_min = field && typeof field.qty_min !== 'undefined' ? field.qty_min : '1';
			normalized.qty_max = field && typeof field.qty_max !== 'undefined' ? field.qty_max : '';
			normalized.qty_default = field && typeof field.qty_default !== 'undefined' ? field.qty_default : '1';
			// Preserve an absent amount_limits as absent rather than coercing it to
			// false, so hasAmountLimits() can still infer it from a legacy field's
			// stored min/max instead of silently switching its range off.
			if ( field && typeof field.amount_limits !== 'undefined' ) {
				normalized.amount_limits = !! field.amount_limits;
			} else if ( field && 'custom_amount' === field.type ) {
				normalized.amount_limits = hasAmountLimits( field );
			}
			normalized.amount_min = field && typeof field.amount_min !== 'undefined' ? field.amount_min : '';
			normalized.amount_max = field && typeof field.amount_max !== 'undefined' ? field.amount_max : '';
			normalized.amount_default = field && typeof field.amount_default !== 'undefined' ? field.amount_default : '';
			normalized.auto_populate_key = field && typeof field.auto_populate_key !== 'undefined' ? field.auto_populate_key : '';
			normalized.calc_formula   = field && typeof field.calc_formula  !== 'undefined' ? field.calc_formula  : '';
			normalized.calc_decimals  = field && typeof field.calc_decimals !== 'undefined' ? Number( field.calc_decimals ) : 2;
			normalized.calc_prefix    = field && typeof field.calc_prefix   !== 'undefined' ? field.calc_prefix   : '';
			normalized.calc_suffix    = field && typeof field.calc_suffix   !== 'undefined' ? field.calc_suffix   : '';

			// Signature field defaults.
			normalized.sig_pen_color  = field && field.sig_pen_color  ? field.sig_pen_color  : '#000000';
			normalized.sig_pen_width  = field && field.sig_pen_width  ? Number( field.sig_pen_width )  : 2;
			normalized.sig_bg_color   = field && field.sig_bg_color   ? field.sig_bg_color   : '#ffffff';
			normalized.sig_height     = field && field.sig_height     ? Number( field.sig_height )     : 160;

			// Hidden field defaults.
			normalized.hidden_source  = field && field.hidden_source  ? field.hidden_source  : 'static';
			normalized.hidden_value   = field && typeof field.hidden_value !== 'undefined' ? field.hidden_value : '';

			// Image choice field defaults.
			// image_choice_options may arrive as a JSON string (from DB) or as an array (live state).
			// Seed 3 starter options when missing OR empty so the settings-panel repeater, the
			// canvas preview and the front-end renderer all show the same options — and a save
			// persists real choices instead of "[]" (self-heals forms saved with no options).
			normalized.image_choice_options    = ( function () {
				var arr = [];
				if ( field ) {
					if ( Array.isArray( field.image_choice_options ) ) {
						arr = field.image_choice_options;
					} else if ( typeof field.image_choice_options === 'string' && field.image_choice_options ) {
						try { var p = JSON.parse( field.image_choice_options ); arr = Array.isArray( p ) ? p : []; } catch (e) { arr = []; }
					}
				}
				return arr.length ? arr : [
					{ label: 'Option 1', value: 'Option 1', image_url: '' },
					{ label: 'Option 2', value: 'Option 2', image_url: '' },
					{ label: 'Option 3', value: 'Option 3', image_url: '' }
				];
			}() );
			normalized.image_choice_type       = field && field.image_choice_type === 'checkbox' ? 'checkbox' : 'radio';
			normalized.image_choice_columns    = field && field.image_choice_columns ? Number( field.image_choice_columns ) : 3;
			normalized.image_choice_img_height = field && field.image_choice_img_height ? Number( field.image_choice_img_height ) : 160;

			// Repeater field defaults.
			// Pro persists repeater_fields as a JSON STRING (wp_json_encode), so parse it
			// back to an array on load — otherwise reopening a saved repeater would wipe its
			// sub-fields (and their options) to [] and the next save would lose them. Mirrors
			// the matrix_rows/columns self-heal below and the panel/canvas string parsing.
			// NOTE: do NOT re-seed an empty array here — a new repeater gets its starter
			// sub-fields from createField(); once the user deletes them all, an empty
			// repeater must STAY empty (respect the deletion) rather than reappearing.
			// Parsed AND migrated on load: a pre-groups flat list becomes one implicit
			// group here, so every later read — panel, canvas, save — sees one shape.
			// Do NOT re-seed when empty: a new repeater gets its starter group from
			// createField(), and a repeater the user emptied must stay empty.
			normalized.repeater_fields       = repeaterGroups( field );
			normalized.repeater_min_rows     = field && field.repeater_min_rows ? Number( field.repeater_min_rows ) : 1;
			normalized.repeater_max_rows     = field && field.repeater_max_rows ? Number( field.repeater_max_rows ) : 5;
			normalized.repeater_add_label    = field && typeof field.repeater_add_label    !== 'undefined' ? field.repeater_add_label    : '';
			normalized.repeater_columns      = field && field.repeater_columns ? Number( field.repeater_columns ) : 2;
			normalized.repeater_remove_label = field && typeof field.repeater_remove_label !== 'undefined' ? field.repeater_remove_label : '';
			normalized.repeater_row_label    = field && typeof field.repeater_row_label    !== 'undefined' ? field.repeater_row_label    : '';

			// Advanced Pro field defaults.
			normalized.confirm_password     = !! ( field && field.confirm_password );
			normalized.rte_height           = field && field.rte_height           ? Number( field.rte_height )           : 200;
			normalized.date_range_format    = field && field.date_range_format    ? field.date_range_format              : 'Y-m-d';
			normalized.date_range_separator = field && typeof field.date_range_separator !== 'undefined' ? field.date_range_separator : ' to ';
			normalized.date_range_min_days  = field && typeof field.date_range_min_days  !== 'undefined' ? field.date_range_min_days  : '';
			normalized.date_range_max_days  = field && typeof field.date_range_max_days  !== 'undefined' ? field.date_range_max_days  : '';
			normalized.nps_low_label        = field && typeof field.nps_low_label  !== 'undefined' ? field.nps_low_label  : 'Not likely';
			normalized.nps_high_label       = field && typeof field.nps_high_label !== 'undefined' ? field.nps_high_label : 'Extremely likely';
			// NPS zone colours (detractor 0-6 / passive 7-8 / promoter 9-10). Empty = use
			// the semantic default (red/amber/green); a value overrides it everywhere.
			normalized.nps_detractor_color  = field && field.nps_detractor_color ? field.nps_detractor_color : '';
			normalized.nps_passive_color    = field && field.nps_passive_color   ? field.nps_passive_color   : '';
			normalized.nps_promoter_color   = field && field.nps_promoter_color  ? field.nps_promoter_color  : '';
			// Matrix rows/columns: keep the saved list, but fall back to defaults when the
			// value is missing OR an empty array. This self-heals forms previously saved
			// with empty matrix_rows/matrix_columns (the front-end already substitutes the
			// same defaults), so the builder, canvas and front end all stay consistent and
			// a save persists real values instead of "[]".
			normalized.matrix_rows = ( function () {
				var v = field ? field.matrix_rows : undefined;
				var arr;
				try { arr = Array.isArray( v ) ? v : JSON.parse( v || '[]' ); } catch ( e ) { arr = []; }
				return ( arr && arr.length ) ? JSON.stringify( arr ) : '["Row 1","Row 2","Row 3"]';
			}() );
			normalized.matrix_columns = ( function () {
				var v = field ? field.matrix_columns : undefined;
				var arr;
				try { arr = Array.isArray( v ) ? v : JSON.parse( v || '[]' ); } catch ( e ) { arr = []; }
				return ( arr && arr.length ) ? JSON.stringify( arr ) : '["Agree","Neutral","Disagree"]';
			}() );
			normalized.matrix_type          = field && field.matrix_type === 'checkbox' ? 'checkbox' : 'radio';
			normalized.lookup_items         = field && typeof field.lookup_items       !== 'undefined' ? field.lookup_items       : '[]';
			normalized.lookup_min_chars     = field && field.lookup_min_chars     ? Number( field.lookup_min_chars )     : 2;
			normalized.lookup_max_results   = field && field.lookup_max_results   ? Number( field.lookup_max_results )   : 8;
			normalized.lookup_allow_custom  = !! ( field && field.lookup_allow_custom );
			normalized.geo_show_map         = !! ( field && field.geo_show_map );
			normalized.geo_map_height       = field && field.geo_map_height ? Number( field.geo_map_height ) : 250;
			normalized.geo_store_format     = field && field.geo_store_format && [ 'both', 'latlng', 'address' ].indexOf( field.geo_store_format ) !== -1 ? field.geo_store_format : 'both';

			// A single-column row is one screen per field, so a field can BE a
			// screen and carry that screen's styling.
			carryScreenKeys( field, normalized );

			return normalized;
		}

		function createColumn( width, fields ) {
			return {
				id: generateId(),
				width: width || '100%',
				fields: Array.isArray( fields ) ? fields.map( normalizeField ) : []
			};
		}

		function createRow( widths ) {
			return {
				id: generateId(),
				css_class: '',
				columns: ( widths || [ '100%' ] ).map(
					function ( width ) {
						return createColumn( width, [] );
					}
				)
			};
		}

		function createTemplateRow( columns ) {
			return {
				id: generateId(),
				columns: ( columns || [] ).map(
					function ( column ) {
						return createColumn( column.width, column.fields || [] );
					}
				)
			};
		}

		function getTemplateDefinitions() {
			return {
				contact: {
					title: shapeformsLiteBuilder.labels.contactTemplateTitle || 'Contact Form',
					description: shapeformsLiteBuilder.labels.contactTemplateDescription || 'A simple contact form with name, email, subject, and message.',
					rows: [
						createTemplateRow(
							[
								{
									width: '50%',
									fields: [
										createTemplateField(
											'text',
											{
												label: 'First Name',
												placeholder: 'Enter your first name',
												required: true
											}
										)
									]
								},
								{
									width: '50%',
									fields: [
										createTemplateField(
											'email',
											{
												label: 'Email Address',
												placeholder: 'Enter your email address',
												required: true
											}
										)
									]
								}
							]
						),
						createTemplateRow(
							[
								{
									width: '100%',
									fields: [
										createTemplateField(
											'text',
											{
												label: 'Subject',
												placeholder: 'What is this about?',
												required: true
											}
										),
										createTemplateField(
											'textarea',
											{
												label: 'Message',
												placeholder: 'Tell us how we can help',
												required: true
											}
										)
									]
								}
							]
						)
					]
				},
				lead: {
					title: shapeformsLiteBuilder.labels.leadTemplateTitle || 'Lead Capture Form',
					description: shapeformsLiteBuilder.labels.leadTemplateDescription || 'A lead form for collecting contact details, budget, and project needs.',
					rows: [
						createTemplateRow(
							[
								{
									width: '50%',
									fields: [
										createTemplateField(
											'text',
											{
												label: 'Full Name',
												placeholder: 'Enter your full name',
												required: true
											}
										)
									]
								},
								{
									width: '50%',
									fields: [
										createTemplateField(
											'email',
											{
												label: 'Work Email',
												placeholder: 'name@company.com',
												required: true
											}
										)
									]
								}
							]
						),
						createTemplateRow(
							[
								{
									width: '50%',
									fields: [
										createTemplateField(
											'text',
											{
												label: 'Company',
												placeholder: 'Company name'
											}
										)
									]
								},
								{
									width: '50%',
									fields: [
										createTemplateField(
											'select',
											{
												label: 'Budget Range',
												placeholder: 'Select a budget range',
												required: true,
												options: [ '$1k - $5k', '$5k - $10k', '$10k+' ]
											}
										)
									]
								}
							]
						),
						createTemplateRow(
							[
								{
									width: '100%',
									fields: [
										createTemplateField(
											'textarea',
											{
												label: 'Project Details',
												placeholder: 'What are you looking to build?',
												required: true
											}
										)
									]
								}
							]
						)
					]
				},
				feedback: {
					title: shapeformsLiteBuilder.labels.feedbackTemplateTitle || 'Feedback Form',
					description: shapeformsLiteBuilder.labels.feedbackTemplateDescription || 'Collect user feedback with rating and comments.',
					rows: [
						createTemplateRow( [
							{ width: '50%', fields: [ createTemplateField( 'text', { label: 'Name', placeholder: 'Your name', required: true } ) ] },
							{ width: '50%', fields: [ createTemplateField( 'email', { label: 'Email', placeholder: 'Your email', required: true } ) ] }
						] ),
						createTemplateRow( [
							{ width: '100%', fields: [
								createTemplateField( 'select', { label: 'Rating', placeholder: 'Select a rating', required: true, options: [ 'Excellent', 'Good', 'Average', 'Poor' ] } ),
								createTemplateField( 'textarea', { label: 'Comments', placeholder: 'Tell us what you think...', required: true } )
							] }
						] )
					]
				},
				newsletter: {
					title: shapeformsLiteBuilder.labels.newsletterTemplateTitle || 'Newsletter Signup',
					description: shapeformsLiteBuilder.labels.newsletterTemplateDescription || 'Simple email signup with name for newsletters.',
					rows: [
						createTemplateRow( [
							{ width: '50%', fields: [ createTemplateField( 'text', { label: 'First Name', placeholder: 'Your first name' } ) ] },
							{ width: '50%', fields: [ createTemplateField( 'email', { label: 'Email Address', placeholder: 'you@example.com', required: true } ) ] }
						] )
					]
				},
				registration: {
					title: shapeformsLiteBuilder.labels.registrationTemplateTitle || 'Registration Form',
					description: shapeformsLiteBuilder.labels.registrationTemplateDescription || 'Event or account registration with full details.',
					rows: [
						createTemplateRow( [
							{ width: '50%', fields: [ createTemplateField( 'text', { label: 'First Name', placeholder: 'First name', required: true } ) ] },
							{ width: '50%', fields: [ createTemplateField( 'text', { label: 'Last Name', placeholder: 'Last name', required: true } ) ] }
						] ),
						createTemplateRow( [
							{ width: '50%', fields: [ createTemplateField( 'email', { label: 'Email', placeholder: 'you@example.com', required: true } ) ] },
							{ width: '50%', fields: [ createTemplateField( 'tel', { label: 'Phone', placeholder: '+1 (555) 000-0000' } ) ] }
						] ),
						createTemplateRow( [
							{ width: '100%', fields: [
								createTemplateField( 'textarea', { label: 'Additional Info', placeholder: 'Anything else we should know?' } ),
								createTemplateField( 'terms_conditions', { required: true } )
							] }
						] )
					]
				},
				support: {
					title: 'Support Ticket',
					description: 'Let users submit support requests with priority and file attachments.',
					rows: [
						createTemplateRow( [
							{ width: '50%', fields: [ createTemplateField( 'name', { label: 'Your Name', required: true } ) ] },
							{ width: '50%', fields: [ createTemplateField( 'email', { label: 'Email', placeholder: 'you@example.com', required: true } ) ] }
						] ),
						createTemplateRow( [
							{ width: '50%', fields: [ createTemplateField( 'select', { label: 'Department', placeholder: 'Select department', required: true, options: [ 'Sales', 'Technical Support', 'Billing', 'General Inquiry' ] } ) ] },
							{ width: '50%', fields: [ createTemplateField( 'select', { label: 'Priority', placeholder: 'Select priority', required: true, options: [ 'Low', 'Medium', 'High', 'Urgent' ] } ) ] }
						] ),
						createTemplateRow( [
							{ width: '100%', fields: [
								createTemplateField( 'text', { label: 'Subject', placeholder: 'Brief description of your issue', required: true } ),
								createTemplateField( 'textarea', { label: 'Description', placeholder: 'Please describe your issue in detail...', required: true } ),
								createTemplateField( 'file', { label: 'Attachment' } )
							] }
						] )
					]
				},
				job_application: {
					title: 'Job Application',
					description: 'Collect resumes, cover letters, and candidate details for hiring.',
					rows: [
						createTemplateRow( [
							{ width: '50%', fields: [ createTemplateField( 'name', { label: 'Full Name', required: true } ) ] },
							{ width: '50%', fields: [ createTemplateField( 'email', { label: 'Email Address', placeholder: 'you@example.com', required: true } ) ] }
						] ),
						createTemplateRow( [
							{ width: '50%', fields: [ createTemplateField( 'tel', { label: 'Phone Number', placeholder: '+1 (555) 000-0000', required: true } ) ] },
							{ width: '50%', fields: [ createTemplateField( 'url', { label: 'LinkedIn Profile', placeholder: 'https://linkedin.com/in/...' } ) ] }
						] ),
						createTemplateRow( [
							{ width: '50%', fields: [ createTemplateField( 'select', { label: 'Position', placeholder: 'Select position', required: true, options: [ 'Frontend Developer', 'Backend Developer', 'Designer', 'Project Manager', 'Other' ] } ) ] },
							{ width: '50%', fields: [ createTemplateField( 'select', { label: 'Experience', placeholder: 'Years of experience', options: [ '0-1 years', '1-3 years', '3-5 years', '5-10 years', '10+ years' ] } ) ] }
						] ),
						createTemplateRow( [
							{ width: '100%', fields: [
								createTemplateField( 'textarea', { label: 'Cover Letter', placeholder: 'Tell us why you are a great fit...' } ),
								createTemplateField( 'file', { label: 'Resume / CV', required: true } )
							] }
						] )
					]
				},
				event_rsvp: {
					title: 'Event RSVP',
					description: 'Accept RSVPs for events with guest count and dietary preferences.',
					rows: [
						createTemplateRow( [
							{ width: '50%', fields: [ createTemplateField( 'name', { label: 'Your Name', required: true } ) ] },
							{ width: '50%', fields: [ createTemplateField( 'email', { label: 'Email', placeholder: 'you@example.com', required: true } ) ] }
						] ),
						createTemplateRow( [
							{ width: '50%', fields: [ createTemplateField( 'numeric', { label: 'Number of Guests', placeholder: '1', min_value: '1', max_value: '10', required: true } ) ] },
							{ width: '50%', fields: [ createTemplateField( 'select', { label: 'Attending?', placeholder: 'Select', required: true, options: [ 'Yes, I will attend', 'No, I cannot attend', 'Maybe' ] } ) ] }
						] ),
						createTemplateRow( [
							{ width: '100%', fields: [
								createTemplateField( 'checkbox', { label: 'Dietary Restrictions', options: [ 'Vegetarian', 'Vegan', 'Gluten-Free', 'Nut Allergy', 'None' ] } ),
								createTemplateField( 'textarea', { label: 'Special Requests', placeholder: 'Any additional notes...' } )
							] }
						] )
					]
				},
				customer_survey: {
					title: 'Customer Survey',
					description: 'Gather customer satisfaction data with star ratings and detailed feedback.',
					rows: [
						createTemplateRow( [
							{ width: '50%', fields: [ createTemplateField( 'name', { label: 'Your Name' } ) ] },
							{ width: '50%', fields: [ createTemplateField( 'email', { label: 'Email', placeholder: 'you@example.com' } ) ] }
						] ),
						createTemplateRow( [
							{ width: '50%', fields: [ createTemplateField( 'star_rating', { label: 'Overall Satisfaction', required: true, max_stars: 5 } ) ] },
							{ width: '50%', fields: [ createTemplateField( 'star_rating', { label: 'Ease of Use', max_stars: 5 } ) ] }
						] ),
						createTemplateRow( [
							{ width: '100%', fields: [
								createTemplateField( 'radio', { label: 'Would you recommend us?', required: true, options: [ 'Definitely', 'Probably', 'Not sure', 'Probably not', 'Definitely not' ] } ),
								createTemplateField( 'textarea', { label: 'What can we improve?', placeholder: 'Your feedback helps us get better...' } )
							] }
						] )
					]
				},
				booking: {
					title: 'Booking / Appointment',
					description: 'Schedule appointments with date, time, and service selection.',
					rows: [
						createTemplateRow( [
							{ width: '50%', fields: [ createTemplateField( 'name', { label: 'Full Name', required: true } ) ] },
							{ width: '50%', fields: [ createTemplateField( 'email', { label: 'Email', placeholder: 'you@example.com', required: true } ) ] }
						] ),
						createTemplateRow( [
							{ width: '50%', fields: [ createTemplateField( 'tel', { label: 'Phone', placeholder: '+1 (555) 000-0000', required: true } ) ] },
							{ width: '50%', fields: [ createTemplateField( 'select', { label: 'Service', placeholder: 'Choose a service', required: true, options: [ 'Consultation', 'Follow-up', 'Full Session', 'Quick Check-in' ] } ) ] }
						] ),
						createTemplateRow( [
							{ width: '50%', fields: [ createTemplateField( 'date', { label: 'Preferred Date', required: true } ) ] },
							{ width: '50%', fields: [ createTemplateField( 'time', { label: 'Preferred Time', required: true } ) ] }
						] ),
						createTemplateRow( [
							{ width: '100%', fields: [
								createTemplateField( 'textarea', { label: 'Additional Notes', placeholder: 'Anything we should know beforehand?' } )
							] }
						] )
					]
				},
				order_form: {
					title: 'Order / Quote Request',
					description: 'Product order or quote request with quantity, budget, and shipping address.',
					rows: [
						createTemplateRow( [
							{ width: '50%', fields: [ createTemplateField( 'name', { label: 'Full Name', required: true } ) ] },
							{ width: '50%', fields: [ createTemplateField( 'email', { label: 'Email', placeholder: 'you@example.com', required: true } ) ] }
						] ),
						createTemplateRow( [
							{ width: '50%', fields: [ createTemplateField( 'tel', { label: 'Phone', placeholder: '+1 (555) 000-0000' } ) ] },
							{ width: '50%', fields: [ createTemplateField( 'text', { label: 'Company', placeholder: 'Company name (optional)' } ) ] }
						] ),
						createTemplateRow( [
							{ width: '50%', fields: [ createTemplateField( 'select', { label: 'Product / Service', placeholder: 'Select', required: true, options: [ 'Basic Plan', 'Pro Plan', 'Enterprise Plan', 'Custom' ] } ) ] },
							{ width: '50%', fields: [ createTemplateField( 'numeric', { label: 'Quantity', placeholder: '1', min_value: '1', max_value: '1000' } ) ] }
						] ),
						createTemplateRow( [
							{ width: '100%', fields: [
								createTemplateField( 'address', { label: 'Shipping Address' } ),
								createTemplateField( 'textarea', { label: 'Special Instructions', placeholder: 'Any specific requirements...' } )
							] }
						] )
					]
				}
			};
		}

		function normalizeStructure( structure ) {
			var rows = [];

			if ( structure && Array.isArray( structure.rows ) ) {
				rows = structure.rows.map(
					function ( row ) {
						// A multi-column row is ONE screen, and the row is what
						// stores that screen's styling — so it has to survive the
						// rebuild too, not just the fields inside it.
						return carryScreenKeys( row, {
							id: row && row.id ? row.id : generateId(),
							css_class: row && row.css_class ? row.css_class : '',
							columns: Array.isArray( row && row.columns ) && row.columns.length
								? row.columns.map(
									function ( column ) {
										return createColumn( column.width, column.fields );
									}
								)
								: [ createColumn( '100%', [] ) ]
						} );
					}
				);
			}

			return {
				rows: rows
			};
		}

		function getAllRows() {
			return state.structure.rows;
		}

		function getAllFields() {
			var fields = [];
			getAllRows().forEach( function ( row ) {
				row.columns.forEach( function ( col ) {
					col.fields.forEach( function ( f ) {
						fields.push( f );
					} );
				} );
			} );
			return fields;
		}

		// --- Calculation formula chip editor helpers -------------------------------
		// The calculation Formula field is a chip editor: the user sees field LABELS,
		// while the stored/evaluated formula keeps the stable {field_id} tokens. These
		// helpers translate between the two. The engine and the saved value never see
		// labels — chips are purely a builder-display layer.

		// Resolve a field's current label from its id (empty string if not found).
		function bfCalcLabelById( id ) {
			var label = '';
			getAllFields().forEach( function ( f ) {
				if ( f.id === id ) {
					label = f.label || f.id;
				}
			} );
			return label;
		}

		// Build a single token-chip element for a field id.
		function bfCalcMakeChip( id ) {
			var label   = bfCalcLabelById( id );
			var missing = '' === label;
			var chip    = document.createElement( 'span' );
			chip.className = 'bfcp-token' + ( missing ? ' bfcp-token--missing' : '' );
			chip.setAttribute( 'contenteditable', 'false' );
			chip.setAttribute( 'data-id', id );
			chip.setAttribute( 'title', '{' + id + '}' );
			chip.textContent = missing ? ( '(' + id + ')' ) : label;
			return chip;
		}

		// Render a stored "{id} * {id2}" formula into chip + text HTML for the editor.
		function bfCalcFormulaToChips( formula ) {
			formula = String( formula || '' );
			var html = '';
			var re   = /\{([^}]+)\}/g;
			var last = 0;
			var m;
			while ( ( m = re.exec( formula ) ) !== null ) {
				if ( m.index > last ) {
					html += escapeHtml( formula.slice( last, m.index ) );
				}
				html += bfCalcMakeChip( m[1] ).outerHTML;
				last = re.lastIndex;
			}
			if ( last < formula.length ) {
				html += escapeHtml( formula.slice( last ) );
			}
			return html;
		}

		// Convert a stored "{id}" formula to a readable "Label" string (plain text)
		// for the canvas preview badge. Two deliberate transforms:
		//   1. Curly braces are dropped — they are formula syntax the user never
		//      types (fields are inserted as pills in the editor), and showing them
		//      in the read-only badge made users think they had to close a brace.
		//   2. Operators are prettified to friendly math symbols with even spacing
		//      (× ÷ + -), so "{Weight}/({Height}*{Height})" reads as
		//      "Weight ÷ (Height × Height)".
		// Both are DISPLAY-ONLY — the stored formula keeps its ASCII "*" and "/".
		// Operators are formatted BEFORE labels are substituted, so a field label
		// that itself contains a hyphen or operator character is never mangled.
		// Deleted fields fall back to "(id)" so a broken reference stays visible.
		function bfCalcFormulaToLabels( formula ) {
			var text = String( formula || '' )
				.replace( /\s*\*\s*/g, ' × ' )
				.replace( /\s*\/\s*/g, ' ÷ ' )
				.replace( /\s*\+\s*/g, ' + ' )
				.replace( /\s*-\s*/g, ' - ' )
				.replace( /\(\s+/g, '(' )
				.replace( /\s+\)/g, ')' );
			return text.replace( /\{([^}]+)\}/g, function ( match, id ) {
				var label = bfCalcLabelById( id );
				return label || ( '(' + id + ')' );
			} );
		}

		// Serialize the editor DOM back to a "{id}" formula string. Normalises any
		// non-breaking spaces the browser may inject to plain spaces, since the PHP
		// formula whitelist only accepts ASCII whitespace.
		function bfCalcChipsToFormula( editor ) {
			if ( ! editor ) {
				return '';
			}
			var out = '';
			( function walk( node ) {
				for ( var i = 0; i < node.childNodes.length; i++ ) {
					var child = node.childNodes[ i ];
					if ( 3 === child.nodeType ) {
						out += child.nodeValue;
					} else if ( 1 === child.nodeType ) {
						if ( child.classList && child.classList.contains( 'bfcp-token' ) ) {
							out += '{' + ( child.getAttribute( 'data-id' ) || '' ) + '}';
						} else if ( 'BR' === child.nodeName ) {
							out += ' ';
						} else {
							walk( child );
						}
					}
				}
			}( editor ) );
			out = out.replace( /\u00A0/g, ' ' );
			return /^\s*$/.test( out ) ? '' : out;
		}

		// Insert chip + trailing space at the caret (or end) of the formula editor.
		function bfCalcInsertChip( editor, id ) {
			var chip  = bfCalcMakeChip( id );
			var space = document.createTextNode( ' ' );
			var sel   = window.getSelection();
			var range;
			if ( sel && sel.rangeCount && editor.contains( sel.anchorNode ) ) {
				range = sel.getRangeAt( 0 );
				range.deleteContents();
			} else {
				range = document.createRange();
				range.selectNodeContents( editor );
				range.collapse( false );
			}
			var frag = document.createDocumentFragment();
			frag.appendChild( chip );
			frag.appendChild( space );
			range.insertNode( frag );
			range.setStartAfter( space );
			range.collapse( true );
			if ( sel ) {
				sel.removeAllRanges();
				sel.addRange( range );
			}
		}

		function getColumn( rowIndex, columnIndex ) {
			var row = getAllRows()[ rowIndex ];

			if ( ! row || ! row.columns || ! row.columns[ columnIndex ] ) {
				return null;
			}

			return row.columns[ columnIndex ];
		}

		function getFieldLocation( fieldId ) {
			var match = null;

			getAllRows().forEach(
				function ( row, rowIndex ) {
					row.columns.forEach(
						function ( column, columnIndex ) {
							column.fields.forEach(
								function ( field, fieldIndex ) {
									if ( field.id === fieldId ) {
										match = {
											rowIndex: rowIndex,
											columnIndex: columnIndex,
											fieldIndex: fieldIndex,
											field: field,
											column: column,
											row: row
										};
									}
								}
							);
						}
					);
				}
			);

			return match;
		}

		function getSelectedFieldLocation() {
			if ( ! state.selectedFieldId ) {
				return null;
			}

			return getFieldLocation( state.selectedFieldId );
		}

		function ensureActiveColumn() {
			if ( state.activeColumn && getColumn( state.activeColumn.rowIndex, state.activeColumn.columnIndex ) ) {
				return state.activeColumn;
			}

			if ( ! getAllRows().length ) {
				state.activeColumn = null;
				return null;
			}

			// Default to the last row, first column — new fields go to the bottom.
			var lastRowIndex = getAllRows().length - 1;
			state.activeColumn = {
				rowIndex: lastRowIndex,
				columnIndex: 0
			};

			return state.activeColumn;
		}

		// Whether the author actually PICKED the active column, as opposed to the
		// builder defaulting to one on load. The two mean different things to the
		// field library: a picked column is where clicked fields go, and with
		// nothing picked they append to the bottom of the form, which is what
		// "keep adding fields" should do. Without this distinction, opening a
		// saved form and clicking a field would drop it in row 1.
		var activeColumnChosen = false;

		function setActiveColumn( rowIndex, columnIndex, chosen ) {
			state.activeColumn = {
				rowIndex: rowIndex,
				columnIndex: columnIndex
			};
			activeColumnChosen = !! chosen;
		}

		// ── Unsaved-changes tracking ──────────────────────────────────────────────
		// hasUnsavedChanges flips true on any structural or value mutation and is
		// cleared after a successful save. builderReady gates it so the initial render
		// (which builds state from saved data) does not count as a change. The Preview
		// button reads this to warn before navigating (the preview renders the SAVED form).
		var hasUnsavedChanges = false;
		var builderReady = false;
		function markDirty() {
			if ( builderReady ) {
				hasUnsavedChanges = true;
			}
		}

		function removeFieldAt( rowIndex, columnIndex, fieldIndex ) {
			var column = getColumn( rowIndex, columnIndex );

			if ( ! column || ! column.fields[ fieldIndex ] ) {
				return null;
			}

			markDirty();
			return column.fields.splice( fieldIndex, 1 )[ 0 ];
		}

		function insertFieldAt( rowIndex, columnIndex, field, fieldIndex ) {
			var column = getColumn( rowIndex, columnIndex );
			var insertAt;

			if ( ! column ) {
				return;
			}

			insertAt = typeof fieldIndex === 'number' ? fieldIndex : column.fields.length;
			column.fields.splice( insertAt, 0, field );
			state.selectedFieldId = field.id;
			// Follows the field, but does not itself count as picking a column —
			// otherwise one clicked field would pin the library to that column for
			// the rest of the session.
			setActiveColumn( rowIndex, columnIndex, activeColumnChosen );
			markDirty();
		}

		function addRow( widths ) {
			var row = createRow( widths );

			getAllRows().push( row );
			markDirty();
			setActiveColumn( getAllRows().length - 1, 0 );
			switchEditorView( 'builder' );
			switchSidebarTab( 'library' );
			renderAll();
			announce( 'rowAdded' );
		}

		/**
		 * "Questions on screen N" — the columns of THAT SCREEN, and nothing else.
		 *
		 * In conversational mode this control is not asking for a column count. It
		 * is asking how many questions sit side by side on one screen, and the
		 * ordinary row behaviour answers a different question entirely: an author
		 * with four questions on four screens picked "2" and got all four stacked
		 * in the left half of ONE screen, an empty right half, and three screens
		 * gone.
		 *
		 * THE RULE, stated once: the screen count does not change. The author is
		 * laying out the screen in front of them, not re-cutting the form. Every
		 * other screen is left exactly as it was, and the questions that were on
		 * other screens stay on their own.
		 *
		 * That is not free, because a row is two different things here. A row with
		 * more than one column IS a screen; a single-column row is one screen PER
		 * FIELD. So giving screen 2 of a three-question row a second column means
		 * lifting that one question into a row of its own — the questions above and
		 * below it stay behind in single-column rows, still one screen each. The
		 * row splits; the screens do not.
		 *
		 * Questions fill each cell before the next, so a screen already holding more
		 * questions than the new layout has cells keeps all of them and keeps being
		 * ONE screen. Filling in order rather than wrapping round the cells is
		 * deliberate: wrapping would read better left to right, but it REORDERS the
		 * questions in the DOM, and that is the order they are tabbed through,
		 * submitted in and listed in the entry. A layout tile must not quietly
		 * rewrite any of those.
		 *
		 * @param {number} rowIndex Row holding the screen.
		 * @param {Array}  widths   Column widths of the chosen preset.
		 * @param {string} fieldId  The question whose screen panel is open.
		 */
		function reflowScreenColumns( rowIndex, widths, fieldId ) {
			var rows  = getAllRows();
			var row   = rows[ rowIndex ];

			if ( ! row ) {
				return;
			}

			var multi = ( row.columns || [] ).length > 1;
			var cells = widths.length;
			var all   = [];

			( row.columns || [] ).forEach( function ( column ) {
				( column.fields || [] ).forEach( function ( field ) {
					all.push( field );
				} );
			} );

			// Already one question per screen and staying that way. Splitting the
			// row into one row per question here would rewrite the form without
			// changing a single thing the visitor sees.
			if ( ! multi && cells < 2 ) {
				row.columns[ 0 ].width = widths[ 0 ];
				return;
			}

			// Which questions are ON the screen being edited. A multi-column row is
			// one screen, so all of them. A single-column row is one screen per
			// field, so exactly the one whose panel is open — everything above and
			// below it belongs to other screens.
			var at = 0;

			if ( ! multi ) {
				all.some( function ( field, index ) {
					if ( field.id === fieldId ) {
						at = index;
						return true;
					}
					return false;
				} );
			}

			var mine   = multi ? all : all.slice( at, at + 1 );
			var before = multi ? [] : all.slice( 0, at );
			var after  = multi ? [] : all.slice( at + 1 );
			var buckets = widths.map( function () { return []; } );
			var per     = Math.max( 1, Math.ceil( mine.length / cells ) );

			mine.forEach( function ( field, index ) {
				buckets[ Math.min( cells - 1, Math.floor( index / per ) ) ].push( field );
			} );

			// The screen keeps its look while its owner changes. A field-screen's
			// styling lives on the field and a row-screen's lives on the row, so a
			// screen crossing between the two has to take its cv_* keys with it —
			// otherwise adding a column silently blanks the background image and
			// colours the author set, with nothing on screen to explain it. Stored
			// values are copied, never resolved ones, so a colour left inheriting
			// carries on inheriting.
			if ( ! multi && mine.length ) {
				carryScreenKeys( mine[ 0 ], row );
			} else if ( multi && cells < 2 ) {
				// One screen becoming several: each keeps the look it already had.
				mine.forEach( function ( field ) {
					carryScreenKeys( row, field );
				} );
			}

			row.columns = widths.map( function ( width, index ) {
				return createColumn( width, buckets[ index ] );
			} );

			/** A neighbouring screen, kept single-column so it stays its own. */
			function siblingRow( fields ) {
				var next = createRow( [ '100%' ] );

				next.css_class = row.css_class || '';
				next.columns   = [ createColumn( '100%', fields ) ];

				return next;
			}

			var replacement = [];

			if ( before.length ) { replacement.push( siblingRow( before ) ); }
			replacement.push( row );
			if ( after.length ) { replacement.push( siblingRow( after ) ); }

			if ( replacement.length > 1 ) {
				// In place, in order: the questions either side of this screen must
				// stay where the visitor already meets them.
				rows.splice.apply( rows, [ rowIndex, 1 ].concat( replacement ) );
			}
		}

		function duplicateRow( rowIndex ) {
			var rows = getAllRows();

			if ( ! rows[ rowIndex ] ) {
				return;
			}

			// Deep-clone the row and assign fresh IDs to every field.
			var clone = $.extend( true, {}, rows[ rowIndex ] );
			clone.columns.forEach( function ( col ) {
				col.fields = col.fields.map( function ( field ) {
					var f = $.extend( true, {}, field );
					f.id = generateId();
					return f;
				} );
			} );

			// Insert the clone immediately after the source row.
			rows.splice( rowIndex + 1, 0, clone );
			markDirty();
			setActiveColumn( rowIndex + 1, 0 );
			switchEditorView( 'builder' );
			renderAll();
			announce( 'rowDuplicated' );
		}

		function deleteRow( rowIndex ) {
			var rows = getAllRows();

			if ( ! rows[ rowIndex ] ) {
				return;
			}

			rows.splice( rowIndex, 1 );
			markDirty();
			state.selectedFieldId = null;

			if ( rows.length ) {
				setActiveColumn( 0, 0 );
			} else {
				state.activeColumn = null;
			}

			switchSidebarTab( 'library' );
			renderAll();
			announce( 'rowDeleted' );
		}

		function moveRow( oldIndex, newIndex ) {
			var rows = getAllRows();
			var row;

			if ( oldIndex === newIndex || ! rows[ oldIndex ] ) {
				return;
			}

			row = rows.splice( oldIndex, 1 )[ 0 ];
			rows.splice( newIndex, 0, row );
			markDirty();
			renderAll();
		}

		// The click path into the canvas. It must land in the same place, and obey
		// the same rules, as the drag path (addFieldToColumn) — the two used to
		// disagree on both counts.
		function addFieldToActiveColumn( type ) {
			// Same guard as the drag path. Without it, clicking Submit Button twice
			// produced two submit buttons while dragging it twice produced one.
			if ( 'submit' === type && hasSubmitField() ) {
				return;
			}

			if ( ! getAllRows().length ) {
				addRow( [ '100%' ] );
			}

			var rows   = getAllRows();
			var target = activeColumnChosen ? state.activeColumn : null;

			// Honour the column the author picked. The field library says
			// "click to insert into the selected column", but this used to
			// overwrite the pick with the last row's first column — so a row added
			// with three columns could only ever be filled by dragging, and every
			// clicked field piled into the first column at that column's width.
			// Re-validated against the current structure, because rows can be
			// deleted, moved or re-columned after the pick was made.
			if (
				! target
				|| ! rows[ target.rowIndex ]
				|| ! rows[ target.rowIndex ].columns[ target.columnIndex ]
			) {
				target = { rowIndex: rows.length - 1, columnIndex: 0 };
			}

			insertFieldAt( target.rowIndex, target.columnIndex, createField( type ) );
			state.selectedFieldId = null;
			switchEditorView( 'builder' );
			renderAll();
			announce( 'fieldAdded' );
		}

		function addFieldToColumn( type, rowIndex, columnIndex, newIndex ) {
			if ( 'submit' === type && hasSubmitField() ) {
				// The drag engine has already put the dragged library chip into
				// the canvas by this point — refusing without a re-render left
				// that chip sitting there as a stray element until something
				// else happened to redraw. Rebuild from state instead, which is
				// the only place the truth lives.
				renderAll();
				return;
			}
			insertFieldAt( rowIndex, columnIndex, createField( type ), newIndex );
			state.selectedFieldId = null;
			switchEditorView( 'builder' );
			renderAll();
			announce( 'fieldAdded' );
		}

		function duplicateField( fieldId ) {
			var location = getFieldLocation( fieldId );
			var duplicate;

			if ( ! location ) {
				return;
			}

			duplicate = $.extend( true, {}, location.field );
			duplicate.id = generateId();
			duplicate.label = duplicate.label + ' Copy';

			insertFieldAt( location.rowIndex, location.columnIndex, duplicate, location.fieldIndex + 1 );
			switchEditorView( 'builder' );
			switchSidebarTab( 'settings' );
			renderAll();
			announce( 'fieldDuplicated' );
		}

		function buildButtonIconHtml() {
			var type = state.formSettings.button_icon_type || 'none';
			if ( 'none' === type ) return '';
			var icon = '';
			var size = state.formSettings.button_icon_size || '18';
			var color = state.formSettings.button_icon_color || '';
			var style = '';
			if ( size && size !== '18' ) {
				style += 'font-size:' + escapeHtml( size ) + 'px;width:' + escapeHtml( size ) + 'px;height:' + escapeHtml( size ) + 'px;';
			}
			if ( color ) {
				style += 'color:' + escapeHtml( color ) + ';';
			}
			var styleAttr = style ? ' style="' + style + '"' : '';

			if ( 'dashicon' === type ) {
				icon = '<span class="dashicons ' + escapeHtml( state.formSettings.button_icon_dashicon || 'dashicons-arrow-right-alt' ) + '"' + styleAttr + '></span>';
			} else if ( 'svg' === type && state.formSettings.button_icon_svg ) {
				var imgW   = ( size && size !== '18' ) ? escapeHtml( size ) : '18';
				var svgUrl = escapeHtml( state.formSettings.button_icon_svg );
				if ( color ) {
					// Tint a monochrome SVG to the chosen colour via a CSS mask: the
					// SVG shape masks a solid background-color, so it recolours reliably
					// regardless of the file's internal fills. An <img> can't be
					// recoloured; this matches how the front end renders a coloured SVG.
					var maskRef = "url('" + svgUrl + "') center / contain no-repeat";
					icon = '<span class="shapeforms-btn-icon-svg" aria-hidden="true" style="'
						+ 'display:inline-block;vertical-align:middle;flex-shrink:0;'
						+ 'width:' + imgW + 'px;height:' + imgW + 'px;'
						+ 'background-color:' + escapeHtml( color ) + ';'
						+ '-webkit-mask:' + maskRef + ';mask:' + maskRef + ';"></span>';
				} else {
					// No colour override — show the SVG's own colours via <img>.
					icon = '<img src="' + svgUrl + '" class="shapeforms-btn-icon-svg" alt="" style="'
						+ 'width:' + imgW + 'px;height:' + imgW + 'px;display:inline-block;vertical-align:middle;flex-shrink:0;">';
				}
			}
			return icon;
		}

		function buildButtonContent() {
			var icon = buildButtonIconHtml();
			var rawText = state.formSettings.button_text;
			var text = escapeHtml( rawText || '' );
			var gap = state.formSettings.button_icon_gap || '8';

			// No icon — show text (or default).
			if ( ! icon ) return text || 'Submit';

			// Icon-only (no text).
			if ( ! text ) {
				return '<span style="display:inline-flex;align-items:center;">' + icon + '</span>';
			}

			// Icon + text.
			var style = 'display:inline-flex;align-items:center;gap:' + escapeHtml( gap ) + 'px;';
			if ( 'left' === state.formSettings.button_icon_position ) {
				return '<span style="' + style + '">' + icon + text + '</span>';
			}
			return '<span style="' + style + '">' + text + icon + '</span>';
		}

		function deleteField( fieldId ) {
			var location = getFieldLocation( fieldId );

			if ( ! location ) {
				return;
			}

			removeFieldAt( location.rowIndex, location.columnIndex, location.fieldIndex );
			state.selectedFieldId = null;
			renderAll();
			announce( 'fieldDeleted' );
		}

		function renderInputPreview( field ) {
			// Match the front-end renderer (shortcode.php render_control): a field
			// whose label the user has intentionally cleared shows NO <label> at all.
			// New fields keep their real saved label (createField stores the library
			// name), so this only affects deliberately-emptied labels — keeping the
			// canvas WYSIWYG instead of falling back to the field-type name.
			var labelText = field.label || '';
			// Carries the FRONT END's label class, and this single line is why the
			// Style tab's conversational preview gets the real question type
			// scale. That scale is six rules in conversational.css — a base size
			// plus a step-down for 2, 3 and many columns, a narrow-width block and
			// an invalid state — so re-stating it against builder markup would be
			// six values to keep in sync, and the first attempt at that copied
			// only the base and previewed a two-column screen a third too large.
			// Every return path below concatenates this one variable, so naming it
			// once here is the whole fix.
			//
			// Inert on the canvas: no rule in builder.css or settings.css matches
			// this class, and every conversational rule that does is scoped under
			// `.shapeforms-cv.is-ready`, which the canvas is not inside.
			var label = ( state.formSettings.hide_labels || 'hidden' === field.label_placement || '' === labelText ) ? '' : '<label class="shapeforms-lite-form__label">' + escapeHtml( labelText ) + ( field.required ? ' <span class="shapeforms-required">*</span>' : '' ) + '</label>';
			var html = '';

			// Shared theme tokens so the advanced (Pro) field previews adopt the active
			// Design Theme — accent + field surface — exactly like the free fields,
			// instead of rendering with hardcoded greys. The accent resolves theme
			// primary (button bg, always set by a theme) → focus colour → neutral,
			// matching the established --bf-choice-accent-r fallback chain in builder.css
			// so every Pro field agrees with the core checkbox/radio accent. These read
			// the --bf-* vars emitted onto the canvas / style-preview surfaces by
			// getFormStyleBlock().
			var bfAccent      = 'var(--bf-button-bg, var(--bf-focus-color, #0f766e))';
			var bfFieldBorder = 'var(--bf-field-border, #d1d5db)';
			var bfFieldBg     = 'var(--bf-field-bg, #fff)';
			var bfFieldText   = 'var(--bf-field-text, #374151)';
			var bfFieldRadius = 'var(--bf-field-radius, 6px)';
			var bfLabelColor  = 'var(--bf-label-color, #1f2937)';
			var bfMuted       = '#9ca3af';

			if ( field.type === 'name' ) {
				html = '<div class="shapeforms-canvas-name">';
				html += '<div class="shapeforms-canvas-name__field"><input type="text" placeholder="' + escapeHtml( shapeformsLiteBuilder.labels.firstName || 'First Name' ) + '" disabled><span class="shapeforms-canvas-name__sub">' + escapeHtml( shapeformsLiteBuilder.labels.firstName || 'First Name' ) + '</span></div>';
				if ( field.show_middle_name ) {
					html += '<div class="shapeforms-canvas-name__field"><input type="text" placeholder="' + escapeHtml( shapeformsLiteBuilder.labels.middleName || 'Middle Name' ) + '" disabled><span class="shapeforms-canvas-name__sub">' + escapeHtml( shapeformsLiteBuilder.labels.middleName || 'Middle Name' ) + '</span></div>';
				}
				if ( field.show_last_name ) {
					html += '<div class="shapeforms-canvas-name__field"><input type="text" placeholder="' + escapeHtml( shapeformsLiteBuilder.labels.lastName || 'Last Name' ) + '" disabled><span class="shapeforms-canvas-name__sub">' + escapeHtml( shapeformsLiteBuilder.labels.lastName || 'Last Name' ) + '</span></div>';
				}
				html += '</div>';
			} else if ( field.type === 'file' ) {
				return label + '<div class="shapeforms-canvas-file-preview"><span class="dashicons dashicons-upload"></span> <span>' + escapeHtml( shapeformsLiteBuilder.labels.fileUploadHint || 'Choose file or drag & drop' ) + '</span></div>';
			} else if ( field.type === 'submit' ) {
				return '<div class="shapeforms-canvas-submit is-inline is-align-' + escapeHtml( state.formSettings.button_alignment || 'left' ) + '"><button type="button" class="shapeforms-canvas-submit__button">' + buildButtonContent() + '</button></div>';
			} else if ( field.type === 'page_break' ) {
				// Shows the Step Title set in the settings panel directly on the canvas
				// row, so which step starts here is visible without opening Settings.
				return '<div class="shapeforms-canvas-page-break"><span class="dashicons dashicons-layout"></span><strong>' + escapeHtml( shapeformsLiteBuilder.labels.pageBreak || 'Page Break' ) + '</strong>' +
					( field.step_title ? '<span class="shapeforms-canvas-page-break__title">&#8212; ' + escapeHtml( field.step_title ) + '</span>' : '' ) +
				'</div>';
			} else if ( field.type === 'product' ) {
				var prodOpts = Array.isArray( field.product_options ) ? field.product_options : [];
				if ( 'select' === field.product_style ) {
					html += '<select style="pointer-events:none"><option>' + ( prodOpts.length ? escapeHtml( prodOpts[0].label || 'Select…' ) + ' — $' + escapeHtml( parseFloat( prodOpts[0].price || 0 ).toFixed( 2 ) ) : 'Select…' ) + '</option></select>';
				} else {
					// Use the SAME wrapper/markup as the checkbox/radio canvas preview
					// (shapeforms-canvas-field-choices + __choice-control + __choice-label) so
					// the canvas CSS hides the native input, draws the custom radio, and
					// stacks rows with min-width:0 (no overflow). Frontend __choices alone
					// leaves native radios styled as full-width input lines.
					html += '<div class="shapeforms-canvas-field-choices' + ( 'button' === state.formSettings.choice_style ? ' is-btn' : '' ) + '">';
					// The price <em> must use the button-bg-FIRST accent chain, mirroring
					// the front end's .bfp-product-price rule (Pro payments.css). Keying
					// off --bf-focus-color alone renders teal under every theme whose
					// primary isn't blue/green/dark, because applyDesignTheme()'s focusMap
					// only covers those and leaves --bf-focus-color unset otherwise.
					prodOpts.slice( 0, 4 ).forEach( function ( opt, idx ) {
						html += '<label class="shapeforms-lite-form__choice"><input type="radio"' + ( 0 === idx ? ' checked' : '' ) + ' disabled><span class="shapeforms-lite-form__choice-control" aria-hidden="true"></span><span class="shapeforms-lite-form__choice-label">' + escapeHtml( opt.label || '' ) + ' <em style="color:var(--bf-button-bg, var(--bf-focus-color, #0d9488));font-weight:600">— $' + escapeHtml( parseFloat( opt.price || 0 ).toFixed( 2 ) ) + '</em></span></label>';
					} );
					if ( prodOpts.length > 4 ) { html += '<label class="shapeforms-lite-form__choice"><span class="shapeforms-lite-form__choice-label" style="color:#9ca3af">…and ' + ( prodOpts.length - 4 ) + ' more</span></label>'; }
					html += '</div>';
				}
				return label + '<div class="shapeforms-canvas-field-control">' + html + '</div>';
			} else if ( field.type === 'quantity' ) {
				html += '<input type="number" value="' + escapeHtml( field.qty_default || '1' ) + '" min="' + escapeHtml( field.qty_min || '1' ) + '"' + ( field.qty_max ? ' max="' + escapeHtml( field.qty_max ) + '"' : '' ) + ' disabled>';
				return label + '<div class="shapeforms-canvas-field-control">' + html + '</div>';
			} else if ( field.type === 'custom_amount' ) {
				// Mirror the front end exactly: limits only apply when the switch is
				// on, the box renders empty unless a real default was set, and the
				// hint appears only when there is a limit to state.
				var caLimits  = hasAmountLimits( field );
				var caMin     = caLimits && parseFloat( field.amount_min || 0 ) > 0 ? parseFloat( field.amount_min ) : null;
				var caMax     = caLimits && parseFloat( field.amount_max || 0 ) > 0 ? parseFloat( field.amount_max ) : null;
				var caDefault = parseFloat( field.amount_default || 0 ) > 0 ? parseFloat( field.amount_default ).toFixed( 2 ) : '';
				var caSymbol  = getPaymentCurrencySymbol();
				html += '<div class="shapeforms-canvas-amount">';
				html += '<span class="shapeforms-canvas-amount__symbol">' + escapeHtml( caSymbol ) + '</span>';
				html += '<input type="number" value="' + escapeHtml( caDefault ) + '" placeholder="' + escapeHtml( field.placeholder || 'Enter amount' ) + '" step="0.01"' + ( null !== caMin ? ' min="' + caMin + '"' : '' ) + ( null !== caMax ? ' max="' + caMax + '"' : '' ) + ' disabled>';
				html += '</div>';
				if ( null !== caMin && null !== caMax ) {
					html += '<span class="shapeforms-canvas-field-hint">Minimum ' + escapeHtml( caSymbol ) + caMin.toFixed( 2 ) + ' · Maximum ' + escapeHtml( caSymbol ) + caMax.toFixed( 2 ) + '</span>';
				} else if ( null !== caMin ) {
					html += '<span class="shapeforms-canvas-field-hint">Minimum ' + escapeHtml( caSymbol ) + caMin.toFixed( 2 ) + '</span>';
				} else if ( null !== caMax ) {
					html += '<span class="shapeforms-canvas-field-hint">Maximum ' + escapeHtml( caSymbol ) + caMax.toFixed( 2 ) + '</span>';
				}
				return label + '<div class="shapeforms-canvas-field-control">' + html + '</div>';
			} else if ( field.type === 'order_summary' ) {
				var osTotal  = 0;
				var osFields = getAllFields();
				// Mirror the front end: map each product to its linked quantity
				// field's default qty (Pro's build_order_line_items()).
				var osQtyByProduct = {};
				osFields.forEach( function ( qf ) {
					if ( qf.type === 'quantity' && qf.linked_product ) {
						osQtyByProduct[ qf.linked_product ] = qf;
					}
				} );
				html  = '<div style="border:1px solid ' + bfFieldBorder + ';border-radius:' + bfFieldRadius + ';overflow:hidden;font-size:13px;color:' + bfFieldText + ';">';
				html += '<table style="width:100%;border-collapse:collapse;">';
				html += '<thead style="background:' + bfFieldBg + ';color:' + bfLabelColor + ';"><tr><th style="padding:8px 12px;text-align:left;">Item</th><th style="padding:8px 12px;text-align:right;">Price</th><th style="padding:8px 12px;text-align:right;">Qty</th><th style="padding:8px 12px;text-align:right;">Total</th></tr></thead>';
				html += '<tbody>';
				osFields.forEach( function ( pf ) {
					if ( pf.type === 'product' ) {
						var opts   = Array.isArray( pf.product_options ) ? pf.product_options : [];
						var first  = opts[0] || {};
						var price  = parseFloat( first.price || 0 );
						var qtyF   = osQtyByProduct[ pf.id ];
						var qty    = qtyF && '' !== qtyF.qty_default && typeof qtyF.qty_default !== 'undefined' ? parseInt( qtyF.qty_default, 10 ) || 1 : 1;
						var rowTot = price * qty;
						var lbl    = ( pf.label || 'Product' ) + ( first.label ? ' — ' + first.label : '' );
						osTotal += rowTot;
						html += '<tr style="border-bottom:1px solid ' + bfFieldBorder + ';"><td style="padding:8px 12px;">' + escapeHtml( lbl ) + '</td><td style="padding:8px 12px;text-align:right;">$' + price.toFixed(2) + '</td><td style="padding:8px 12px;text-align:right;">' + qty + '</td><td style="padding:8px 12px;text-align:right;">$' + rowTot.toFixed(2) + '</td></tr>';
					} else if ( pf.type === 'custom_amount' ) {
						// Front end starts this row hidden (display:none) until the
						// visitor types a non-zero amount; the default still counts
						// toward the total. Show it muted in the builder so designers
						// know it exists without implying it renders on load.
						var ca = parseFloat( pf.amount_default || 0 );
						osTotal += ca;
						html += '<tr style="border-bottom:1px solid ' + bfFieldBorder + ';opacity:.55;font-style:italic;"><td style="padding:8px 12px;">' + escapeHtml( pf.label || 'Custom Amount' ) + ' <span style="font-style:normal;font-size:11px;color:' + bfMuted + ';">(' + escapeHtml( 'appears when filled' ) + ')</span></td><td style="padding:8px 12px;text-align:right;">$' + ca.toFixed(2) + '</td><td style="padding:8px 12px;text-align:right;">1</td><td style="padding:8px 12px;text-align:right;">$' + ca.toFixed(2) + '</td></tr>';
					}
				} );
				html += '</tbody>';
				html += '<tfoot style="background:' + bfFieldBg + ';border-top:2px solid ' + bfFieldBorder + ';"><tr><td colspan="3" style="padding:10px 12px;text-align:right;font-weight:600;color:' + bfLabelColor + ';">Order Total</td><td style="padding:10px 12px;text-align:right;font-weight:700;font-size:15px;color:' + bfAccent + ';">$' + osTotal.toFixed(2) + '</td></tr></tfoot>';
				html += '</table>';
				html += '';
				html += '</div>';
				return label + '<div class="shapeforms-canvas-field-control">' + html + '</div>';
			} else if ( field.type === 'section_break' ) {
				html = '<div class="shapeforms-canvas-section-break"><strong>' + escapeHtml( field.label ) + '</strong><p>' + escapeHtml( field.description || '' ) + '</p></div>';
			} else if ( field.type === 'terms_conditions' ) {
				// Intentional raw-HTML sink: field.content is admin-authored rich text
				// (builder is manage_options-only) and is re-sanitized server-side with
				// wp_kses_post() on save, so it is emitted unescaped here to preserve markup.
				// A consent field has no heading, so the shared label above never draws
				// the required asterisk for it — mirror the front end and append one to
				// the copy instead, or a required consent box looks entirely optional.
				html = '<div class="shapeforms-canvas-terms"><input type="checkbox"' + ( field.required ? ' checked' : '' ) + '><span class="shapeforms-lite-form__choice-control" aria-hidden="true"></span><div class="shapeforms-canvas-terms__copy">' + ( field.content || '' ) + ( field.required ? ' <span class="shapeforms-required">*</span>' : '' ) + '</div></div>';
			} else if ( field.type === 'captcha' ) {
				html = '<div class="shapeforms-canvas-field-note">' + escapeHtml( shapeformsLiteBuilder.labels.captchaNotice || 'This field will use the captcha provider selected in global settings.' ) + '</div>';
			} else if ( field.type === 'textarea' ) {
				html = '<textarea rows="3" placeholder="' + escapeHtml( field.placeholder || field.label || '' ) + '">' + escapeHtml( field.default_value ) + '</textarea>';
			} else if ( field.type === 'select' ) {
				var selectedValue = $.trim( field.default_value || '' );
				var selectedLabel = '';
				field.options.forEach( function ( option ) {
					if ( $.trim( option || '' ) === selectedValue ) selectedLabel = option;
				} );

				html = '<div class="bf-select">';
				html += '<div class="bf-select__trigger">';
				if ( selectedLabel ) {
					html += '<span class="bf-select__value">' + escapeHtml( selectedLabel ) + '</span>';
				} else {
					html += '<span class="bf-select__placeholder">' + escapeHtml( field.placeholder || 'Select\u2026' ) + '</span>';
				}
				html += '<span class="bf-select__arrow"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></span>';
				html += '</div></div>';
			} else if ( field.type === 'multiselect' ) {
				var msDefaults = ( field.default_value || '' ).split( ',' ).map( function ( v ) { return $.trim( v ); } ).filter( function ( v ) { return v.length; } );

				html = '<div class="bf-select bf-select--multi">';
				html += '<div class="bf-select__trigger">';
				if ( msDefaults.length ) {
					html += '<span class="bf-select__tags">';
					msDefaults.forEach( function ( v ) {
						html += '<span class="bf-select__tag">' + escapeHtml( v ) + '</span>';
					} );
					html += '</span>';
				} else {
					html += '<span class="bf-select__placeholder">' + escapeHtml( field.placeholder || 'Select options\u2026' ) + '</span>';
				}
				html += '<span class="bf-select__arrow"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></span>';
				html += '</div></div>';
			} else if ( field.type === 'checkbox' || field.type === 'radio' ) {
				var choiceDefaults = ( field.default_value || '' ).split( ',' ).map( function ( v ) { return $.trim( v ); } ).filter( function ( v ) { return v.length; } );
				var isSwitchStyle = 'checkbox' === field.type && 'switch' === field.checkbox_style;
				// Mirrors the front end exactly (see render_field_control): the
				// form-level Style: Button applies unless this field is already a
				// Switch, which is a deliberate per-field choice that wins.
				var isBtnStyle = 'button' === bfFieldChoiceMode( field ) && ! isSwitchStyle;
				html = '<div class="shapeforms-canvas-field-choices' + ( 'inline' === field.options_layout ? ' is-inline' : '' ) + ( isSwitchStyle ? ' is-switch' : '' ) + ( isBtnStyle ? ' is-btn' : '' ) + '"' + bfChoiceStyleAttr( field ) + '>';
				field.options.forEach(
					function ( option, optionIndex ) {
						var isChecked = choiceDefaults.indexOf( $.trim( option ) ) !== -1;
						var iconHtml  = bfChoiceIconHtml( field, optionIndex );
						html += '<label class="shapeforms-lite-form__choice"><input type="' + escapeHtml( field.type ) + '"' + ( isChecked ? ' checked' : '' ) + '><span class="shapeforms-lite-form__choice-control" aria-hidden="true"></span><span class="shapeforms-lite-form__choice-label">' + iconHtml + ( iconHtml ? '<span class="shapeforms-lite-form__choice-text">' + escapeHtml( option ) + '</span>' : escapeHtml( option ) ) + '</span></label>';
					}
				);
				html += '</div>';
			} else if ( field.type === 'input_mask' ) {
				html = '<input type="text" placeholder="' + escapeHtml( field.placeholder || field.mask_pattern || field.label || '' ) + '" value="' + escapeHtml( field.default_value ) + '" disabled>';
			} else if ( field.type === 'html_editor' ) {
				html = '<div class="shapeforms-canvas-html-editor"><span class="dashicons dashicons-editor-code"></span> ' + escapeHtml( shapeformsLiteBuilder.labels.htmlEditor || 'Rich Text Editor' ) + '</div>';
			} else if ( field.type === 'paragraph' ) {
				html = '<div class="shapeforms-canvas-paragraph">' + escapeHtml( field.content || 'Paragraph text...' ) + '</div>';
			} else if ( field.type === 'numeric' ) {
				var numPlaceholder = field.placeholder || '';
				if ( field.min_value !== '' || field.max_value !== '' ) {
					numPlaceholder = numPlaceholder || ( ( field.min_value || '0' ) + ' - ' + ( field.max_value || '...' ) );
				}
				// Final fallback to the label so the field is never bare (matches front-end).
				numPlaceholder = numPlaceholder || field.label || '';
				html = '<input type="number" placeholder="' + escapeHtml( numPlaceholder ) + '" value="' + escapeHtml( field.default_value ) + '" disabled>';
			} else if ( field.type === 'address' ) {
				var af = field.address_fields || {};
				var addrOrder = field.address_order || [ 'street', 'city', 'state', 'zip', 'country' ];
				var addrPlaceholders = { street: 'Street Address', city: 'City', state: 'State / Province', zip: 'ZIP / Postal', country: 'Country' };
				var enabledAddr = addrOrder.filter( function ( k ) { return af[ k ] !== false; } );
				html = '<div class="shapeforms-canvas-address">';
				// Render in exact order. Street = full width, others pair up in sequence.
				var pairBuffer = [];
				enabledAddr.forEach( function ( key ) {
					if ( key === 'street' ) {
						// Flush any pending pair first.
						if ( pairBuffer.length ) {
							var cls = pairBuffer.length === 2 ? ' shapeforms-canvas-address__row--half' : '';
							html += '<div class="shapeforms-canvas-address__row' + cls + '">';
							pairBuffer.forEach( function ( k ) { html += '<input type="text" placeholder="' + escapeHtml( addrPlaceholders[ k ] ) + '" disabled>'; } );
							html += '</div>';
							pairBuffer = [];
						}
						html += '<input type="text" placeholder="' + escapeHtml( addrPlaceholders[ key ] ) + '" disabled>';
					} else {
						pairBuffer.push( key );
						if ( pairBuffer.length === 2 ) {
							html += '<div class="shapeforms-canvas-address__row shapeforms-canvas-address__row--half">';
							pairBuffer.forEach( function ( k ) { html += '<input type="text" placeholder="' + escapeHtml( addrPlaceholders[ k ] ) + '" disabled>'; } );
							html += '</div>';
							pairBuffer = [];
						}
					}
				} );
				// Flush remaining single field.
				if ( pairBuffer.length ) {
					html += '<div class="shapeforms-canvas-address__row">';
					pairBuffer.forEach( function ( k ) { html += '<input type="text" placeholder="' + escapeHtml( addrPlaceholders[ k ] ) + '" disabled>'; } );
					html += '</div>';
				}
				html += '</div>';
			} else if ( field.type === 'country' ) {
				html = '<div class="bf-select">';
				html += '<div class="bf-select__trigger">';
				html += '<span class="bf-select__placeholder">' + escapeHtml( field.placeholder || 'Select a country' ) + '</span>';
				html += '<span class="bf-select__arrow"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></span>';
				html += '</div></div>';
			} else if ( field.type === 'star_rating' ) {
				var maxStars = field.max_stars || 5;
				var defRating = Number( field.default_value ) || 0;
				var starSize = field.star_size || '20';
				// Emit --star-color (active) / --star-inactive only when set; otherwise the
				// CSS falls back (active → theme accent --bf-button-bg, inactive → a light
				// tint of the active colour).
				var starStyle = '--star-size:' + escapeHtml( starSize ) + 'px';
				if ( field.star_color ) {
					starStyle = '--star-color:' + escapeHtml( field.star_color ) + ';' + starStyle;
				}
				if ( field.star_inactive_color ) {
					starStyle = '--star-inactive:' + escapeHtml( field.star_inactive_color ) + ';' + starStyle;
				}
				html = '<div class="shapeforms-canvas-stars" style="' + starStyle + '">';
				for ( var si = 1; si <= maxStars; si++ ) {
					html += '<span class="shapeforms-canvas-star' + ( si <= defRating ? ' is-active' : '' ) + '">&#9733;</span>';
				}
				html += '</div>';
			} else if ( field.type === 'slider_range' ) {
				var slMin = field.min_value || '0';
				var slMax = field.max_value || '100';
				var slVal = field.default_value || slMin;
				var slColor = field.slider_color || '';
				var slHeight = field.slider_height || '';
				var slStyle = '';
				if ( slColor ) slStyle += '--slider-color:' + escapeHtml( slColor ) + ';';
				if ( slHeight ) slStyle += '--slider-height:' + escapeHtml( slHeight ) + 'px;';
				if ( field.dual_handle ) {
					// Preview thumbs sit at 25% / 75% of the track (see CSS); show the
					// matching values so the label reflects the handle positions.
					var slSpan = Number( slMax ) - Number( slMin );
					var slLo   = Math.round( Number( slMin ) + ( slSpan * 0.25 ) );
					var slHi   = Math.round( Number( slMin ) + ( slSpan * 0.75 ) );
					html = '<div class="shapeforms-canvas-slider shapeforms-canvas-slider--dual"' + ( slStyle ? ' style="' + slStyle + '"' : '' ) + '>';
					html += '<div class="shapeforms-canvas-slider__track">';
					html += '<div class="shapeforms-canvas-slider__fill"></div>';
					html += '<span class="shapeforms-canvas-slider__thumb shapeforms-canvas-slider__thumb--min"></span>';
					html += '<span class="shapeforms-canvas-slider__thumb shapeforms-canvas-slider__thumb--max"></span>';
					html += '</div>';
					html += '<div class="shapeforms-canvas-slider__labels"><span>' + escapeHtml( slMin ) + '</span><span>' + escapeHtml( String( slLo ) ) + ' – ' + escapeHtml( String( slHi ) ) + '</span><span>' + escapeHtml( slMax ) + '</span></div>';
					html += '</div>';
				} else {
					html = '<div class="shapeforms-canvas-slider"' + ( slStyle ? ' style="' + slStyle + '"' : '' ) + '>';
					html += '<input type="range" min="' + escapeHtml( slMin ) + '" max="' + escapeHtml( slMax ) + '" value="' + escapeHtml( slVal ) + '" disabled>';
					html += '<div class="shapeforms-canvas-slider__labels"><span>' + escapeHtml( slMin ) + '</span><span>' + escapeHtml( slVal ) + '</span><span>' + escapeHtml( slMax ) + '</span></div>';
					html += '</div>';
				}
			} else if ( field.type === 'calculation' ) {
				var calcDecimals = (typeof field.calc_decimals === 'number') ? field.calc_decimals : 2;
				var calcPrefix   = field.calc_prefix || '';
				var calcSuffix   = field.calc_suffix || '';
				var calcSample   = calcPrefix + parseFloat(0).toFixed(calcDecimals) + calcSuffix;
				var calcFormula  = field.calc_formula || '';
				html = '<div class="shapeforms-canvas-calculation">';
				html += '<input type="text" value="' + escapeHtml(calcSample) + '" readonly class="shapeforms-canvas-calc-input">';
				if ( calcFormula ) {
					html += '<div class="shapeforms-canvas-calc-badge">= ' + escapeHtml( bfCalcFormulaToLabels( calcFormula ) ) + '</div>';
				}
				html += '</div>';

			} else if ( field.type === 'signature' ) {
				html  = '<div class="shapeforms-canvas-signature">';
				html += '<span class="dashicons dashicons-edit"></span>';
				html += '<span>' + escapeHtml( shapeformsLiteBuilder.labels.signatureHint || 'Sign here…' ) + '</span>';
				html += '</div>';

			} else if ( field.type === 'hidden_field' ) {
				var hfSource = field.hidden_source || 'static';
				var hfValue  = field.hidden_value  || '';
				var hfSourceLabel = {
					static: 'Static value',
					url_param: 'URL parameter',
					user_id: 'User ID',
					user_email: 'User email',
					user_login: 'User login',
					post_id: 'Post ID',
					referrer: 'Referrer URL'
				}[ hfSource ] || hfSource;
				html  = '<div class="shapeforms-canvas-hidden-field">';
				html += '<span class="dashicons dashicons-hidden"></span>';
				html += '<span class="shapeforms-canvas-hidden-field__label">Hidden: ' + escapeHtml( hfSourceLabel );
				if ( 'static' === hfSource && hfValue ) {
					html += ' = <em>' + escapeHtml( hfValue ) + '</em>';
				} else if ( 'url_param' === hfSource && hfValue ) {
					html += ' <code>?' + escapeHtml( hfValue ) + '</code>';
				}
				html += '</span></div>';

			} else if ( field.type === 'image_choice' ) {
				var icOpts = [];
				if ( field.image_choice_options ) {
					try {
						icOpts = typeof field.image_choice_options === 'string'
							? JSON.parse( field.image_choice_options )
							: field.image_choice_options;
					} catch (e) { icOpts = []; }
				}
				var icCols = field.image_choice_columns || 3;
				var icImgH = Math.max( 40, Math.min( 600, Number( field.image_choice_img_height ) || 160 ) );
				// Scale down proportionally for the builder canvas (canvas ~280px wide).
				var icCanvasH = Math.round( icImgH * 0.35 );
				icCanvasH = Math.max( 40, Math.min( 180, icCanvasH ) );
				// Fallback matches normalizeField + the front-end renderer's defaults.
				var icItems = icOpts.length ? icOpts : [
					{ label: 'Option 1', value: 'Option 1', image_url: '' },
					{ label: 'Option 2', value: 'Option 2', image_url: '' },
					{ label: 'Option 3', value: 'Option 3', image_url: '' }
				];
				html = '<div class="shapeforms-canvas-ic shapeforms-canvas-ic--' + escapeHtml( String( icCols ) ) + 'col">';
				icItems.slice( 0, 6 ).forEach( function ( opt, i ) {
					var isFirst = ( i === 0 );
					html += '<div class="shapeforms-canvas-ic__item' + ( isFirst ? ' is-checked' : '' ) + '">';
					html += '<div class="shapeforms-canvas-ic__img" style="height:' + icCanvasH + 'px">';
					if ( opt.image_url ) {
						html += '<img src="' + escapeHtml( opt.image_url ) + '" alt="' + escapeHtml( opt.label || '' ) + '">';
					} else {
						html += '<span class="dashicons dashicons-format-image"></span>';
					}
					if ( isFirst ) {
						html += '<span class="shapeforms-canvas-ic__check">&#10003;</span>';
					}
					html += '</div>';
					html += '<span class="shapeforms-canvas-ic__lbl">' + escapeHtml( opt.label || ( 'Option ' + ( i + 1 ) ) ) + '</span>';
					html += '</div>';
				} );
				html += '</div>';

			} else if ( field.type === 'repeater' ) {
				var repGroupsP = repeaterGroups( field );
				var repColsP = Math.max( 1, Math.min( 4, Number( field.repeater_columns ) || 2 ) );
				// Default sub-field labels mirror render_sub_field() in the Pro module so
				// the canvas preview matches the front end when a sub-field has no label.
				var repLabelMap = { text: 'Text', email: 'Email', url: 'URL', tel: 'Phone', number: 'Number', date: 'Date', time: 'Time', textarea: 'Message', select: 'Select', radio: 'Choose one', checkbox: 'Select all' };
				var repSubLabel = function ( sf ) {
					var l = ( sf.label || '' ).toString();
					return ( l && l.replace( /\s+/g, '' ) ) ? l : ( repLabelMap[ sf.type ] || 'Field' );
				};
				// Render a lightweight mockup of each sub-field's control, matching its type
				// (and its options for select/radio/checkbox) so the preview reflects settings.
				var repControl = function ( sf ) {
					var t = sf.type || 'text';
					var ph = ( sf.placeholder || '' ).toString();
					// Text-style controls fall back to the (default) label so the preview box
					// is never empty — mirrors Lite core fields and the front-end render.
					var phText = ph || repSubLabel( sf );
					var opts = Array.isArray( sf.options ) ? sf.options : [];
					if ( 'textarea' === t ) {
						return '<div class="shapeforms-canvas-repeater__control shapeforms-canvas-repeater__control--area">' + escapeHtml( phText ) + '</div>';
					}
					if ( 'select' === t ) {
						return '<div class="shapeforms-canvas-repeater__control shapeforms-canvas-repeater__control--select"><span>' + escapeHtml( ph || opts[ 0 ] || 'Select…' ) + '</span><svg width="12" height="8" viewBox="0 0 12 8" aria-hidden="true"><path fill="#94a3b8" d="M1 1l5 5 5-5"/></svg></div>';
					}
					if ( 'radio' === t || 'checkbox' === t ) {
						var items = opts.length ? opts : [ 'Option 1', 'Option 2' ];
						// A repeater's choice sub-fields ARE checkboxes and radios, so the
						// canvas draws them with the same markup as a top-level choice field
						// and they answer to the Checkbox & Radio style group, the
						// Default/Button Style option included — exactly what the front end
						// renders (see ShapeForms_Pro_Repeater::render_sub_field()).
						var ch = '<div class="shapeforms-canvas-field-choices shapeforms-canvas-repeater__choices' + ( 'button' === bfFieldChoiceMode( sf ) ? ' is-btn' : '' ) + '">';
						// What a fresh row opens with, drawn as it will arrive.
						var picked = opts.length ? bfSubDefaults( sf ) : [];
						items.slice( 0, 6 ).forEach( function ( o, oi ) {
							// Only the real options carry icons; the two placeholders a
							// sub-field with no options shows are drawn without one.
							var ic = opts.length ? bfChoiceIconHtml( sf, oi ) : '';
							var tx = escapeHtml( ( o || '' ).toString() );
							var on = picked.indexOf( $.trim( ( o || '' ).toString() ) ) !== -1;
							ch += '<label class="shapeforms-lite-form__choice"><input type="' + escapeHtml( t ) + '"' + ( on ? ' checked' : '' ) + ' disabled><span class="shapeforms-lite-form__choice-control" aria-hidden="true"></span><span class="shapeforms-lite-form__choice-label">' + ic + ( ic ? '<span class="shapeforms-lite-form__choice-text">' + tx + '</span>' : tx ) + '</span></label>';
						} );
						ch += '</div>';
						return ch;
					}
					return '<div class="shapeforms-canvas-repeater__control">' + escapeHtml( phText ) + '</div>';
				};
				// Show ONLY the configured sub-fields — no fake fallback. A repeater with no
				// sub-fields shows a guidance hint (it starts with two via createField; once
				// the user deletes them all, the canvas reflects that empty state).
				html  = '<div class="shapeforms-canvas-repeater">';
				if ( ! repeaterFieldCount( repGroupsP ) ) {
					html += '<div class="shapeforms-canvas-repeater__empty" style="padding:10px 12px;color:#9ca3af;font-size:13px;font-style:italic">' + escapeHtml( 'No sub-fields yet — add one in Field Settings.' ) + '</div>';
				} else {
					// One row, drawn the way the front end draws it: a stack of groups,
					// each its own grid at its own column count (0 inherits the
					// repeater's). See ShapeForms_Pro_Repeater::render_row().
					html += '<div class="shapeforms-canvas-repeater__row">';
					repGroupsP.forEach( function ( grp ) {
						if ( ! grp.fields.length ) { return; }
						var cols = grp.columns > 0 ? grp.columns : repColsP;
						// A collapsible group is drawn as the panel the front end draws
						// (see ShapeForms_Pro_Repeater::render_row) — the canvas never
						// toggles, so it always shows the open state.
						var grpCollapsible = grp.collapsible && grp.label;
						html += '<div class="shapeforms-canvas-repeater__group' + ( grpCollapsible ? ' is-collapsible' : '' ) + '">';
						if ( grp.label ) {
							html += '<span class="shapeforms-canvas-repeater__group-head">' +
								escapeHtml( grp.label ) +
								( grpCollapsible ? '<span class="shapeforms-canvas-repeater__group-caret" aria-hidden="true"></span>' : '' ) +
								'</span>';
						}
						html += '<div class="shapeforms-canvas-repeater__group-body" style="grid-template-columns:repeat(' + cols + ',minmax(0,1fr))">';
						grp.fields.forEach( function ( sf ) {
							// Overrides ride the sub-field wrapper, matching where
							// ShapeForms_Pro_Repeater::render_sub_field() puts them.
							html += '<div class="shapeforms-canvas-repeater__field' + ( sf.full_width ? ' is-full' : '' ) + '"' + bfChoiceStyleAttr( sf ) + '>';
							// The front end keeps a hidden label in the DOM for screen
							// readers, but it is out of flow — so the canvas, which is only a
							// picture, simply omits the line.
							if ( ! sf.hide_label ) {
								html += '<span class="shapeforms-canvas-repeater__label">' + escapeHtml( repSubLabel( sf ) ) + ( sf.required ? ' <span class="shapeforms-required">*</span>' : '' ) + '</span>';
							}
							html += repControl( sf );
							html += '</div>';
						} );
						html += '</div></div>';
					} );
					html += '</div>';
				}
				html += '<div class="shapeforms-canvas-repeater__add' + ( 'left' !== repAddAlign( field ) ? ' is-align-' + repAddAlign( field ) : '' ) + '"><span class="dashicons dashicons-plus-alt2"></span> ' + escapeHtml( field.repeater_add_label || 'Add Row' ) + '</div>';
				html += '</div>';

			} else if ( field.type === 'password_field' ) {
				html  = '<div style="display:flex;align-items:center;gap:6px;position:relative">';
				html += '<input type="password" placeholder="' + escapeHtml( field.placeholder || 'Password' ) + '" disabled style="flex:1;padding-right:36px">';
				html += '<span style="position:absolute;right:10px;color:#9ca3af"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></span>';
				html += '</div>';
				if ( field.confirm_password ) {
					html += '<div style="display:flex;align-items:center;gap:6px;position:relative;margin-top:6px">';
					html += '<input type="password" placeholder="Confirm password" disabled style="flex:1;padding-right:36px">';
					html += '<span style="position:absolute;right:10px;color:#9ca3af"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></span>';
					html += '</div>';
				}

			} else if ( field.type === 'rich_text' ) {
				var rteH = Math.max( 80, Math.round( ( field.rte_height || 200 ) * 0.45 ) );
				// Mockup of the front-end TinyMCE editor (real editor renders on the front end).
				var rteBtn = 'display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:24px;padding:0 5px;border:1px solid ' + bfFieldBorder + ';border-radius:4px;background:' + bfFieldBg + ';color:' + bfFieldText + ';font-size:12px;';
				var rteSep = '<span style="width:1px;height:18px;background:' + bfFieldBorder + ';margin:0 2px"></span>';
				html  = '<div style="border:1px solid ' + bfFieldBorder + ';border-radius:' + bfFieldRadius + ';overflow:hidden;background:' + bfFieldBg + '">';
				html += '<div style="display:flex;align-items:center;gap:4px;flex-wrap:wrap;padding:6px 8px;background:' + bfFieldBg + ';border-bottom:1px solid ' + bfFieldBorder + '">';
				html += '<span style="' + rteBtn + 'font-weight:700">B</span>';
				html += '<span style="' + rteBtn + 'font-style:italic">I</span>';
				html += '<span style="' + rteBtn + 'text-decoration:underline">U</span>';
				html += rteSep;
				html += '<span style="' + rteBtn + '">&#8226;</span>';
				html += '<span style="' + rteBtn + '">1.</span>';
				html += rteSep;
				html += '<span style="' + rteBtn + '">&#128279;</span>';
				html += rteSep;
				html += '<span style="' + rteBtn + '">&#8634;</span>';
				html += '<span style="' + rteBtn + '">&#8635;</span>';
				html += '</div>';
				html += '<div style="min-height:' + rteH + 'px;padding:10px 12px;color:' + bfMuted + ';font-size:13px">Rich text content…</div>';
				html += '</div>';

			} else if ( field.type === 'date_range' ) {
				html  = '<div style="position:relative;display:flex;align-items:center">';
				html += '<input type="text" placeholder="' + escapeHtml( field.placeholder || 'Select date range' ) + '" disabled style="width:100%;padding-right:32px">';
				html += '<span style="position:absolute;right:10px;color:#9ca3af"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span>';
				html += '</div>';

			} else if ( field.type === 'nps' ) {
				// Each zone (detractor 0-6 / passive 7-8 / promoter 9-10) shows its own
				// colour at rest, derived from the field's zone colours (or the semantic
				// defaults). Mirrors the front-end .bf-nps-btn--<zone> rest styling.
				var npsBase = {
					d:  field.nps_detractor_color || '#ef4444',
					p:  field.nps_passive_color   || '#f59e0b',
					pr: field.nps_promoter_color  || '#22c55e'
				};
				html = '<div style="display:flex;gap:3px;flex-wrap:wrap">';
				for ( var npsI = 0; npsI <= 10; npsI++ ) {
					var npsKey   = npsI <= 6 ? 'd' : ( npsI <= 8 ? 'p' : 'pr' );
					var npsC     = npsBase[ npsKey ];
					var npsBg    = 'color-mix(in srgb, ' + npsC + ' 14%, #fff)';
					var npsBd    = 'color-mix(in srgb, ' + npsC + ' 40%, #fff)';
					html += '<div style="flex:1;min-width:20px;height:32px;border:1px solid ' + npsBd + ';border-radius:4px;background:' + npsBg + ';color:var(--bf-field-text,#374151);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600">' + npsI + '</div>';
				}
				html += '</div>';
				html += '<div style="display:flex;justify-content:space-between;font-size:11px;color:#9ca3af;margin-top:4px">';
				html += '<span>' + escapeHtml( field.nps_low_label || 'Not likely' ) + '</span>';
				html += '<span>' + escapeHtml( field.nps_high_label || 'Extremely likely' ) + '</span>';
				html += '</div>';

			} else if ( field.type === 'matrix' ) {
				var matRows = [];
				var matCols = [];
				// Empty/invalid data falls back to the SAME defaults the front-end renderer
				// uses (render_matrix in Pro) so the canvas preview matches what visitors see.
				try { matRows = JSON.parse( field.matrix_rows || '[]' ); } catch(e) { matRows = []; }
				try { matCols = JSON.parse( field.matrix_columns || '[]' ); } catch(e) { matCols = []; }
				if ( ! matRows.length ) matRows = [ 'Row 1', 'Row 2', 'Row 3' ];
				if ( ! matCols.length ) matCols = [ 'Agree', 'Neutral', 'Disagree' ];
				var matType = field.matrix_type || 'radio';
				// Render a styled control SPAN (radio circle / checkbox square) rather than a
				// native <input>: a bare input inherits the generic full-width canvas-input
				// styling and renders as an orange underline instead of a control. Mirrors the
				// front-end's grey outline (matches the product/choice canvas preview approach).
				var matMarkStyle = 'display:inline-block;width:16px;height:16px;border:2px solid ' + bfFieldBorder + ';background:' + bfFieldBg + ';vertical-align:middle;box-sizing:border-box;border-radius:' + ( 'checkbox' === matType ? '4px' : '50%' );
				html = '<div style="overflow-x:auto;border:1px solid ' + bfFieldBorder + ';border-radius:' + bfFieldRadius + '"><table style="width:100%;border-collapse:collapse;font-size:12px;color:' + bfFieldText + '">';
				html += '<thead><tr><th style="border:1px solid ' + bfFieldBorder + ';padding:4px 6px;background:' + bfFieldBg + '"></th>';
				matCols.forEach( function(c) { html += '<th style="border:1px solid ' + bfFieldBorder + ';padding:4px 6px;background:' + bfFieldBg + ';color:' + bfLabelColor + ';text-align:center">' + escapeHtml( c ) + '</th>'; } );
				html += '</tr></thead><tbody>';
				matRows.forEach( function(r) {
					html += '<tr><td style="border:1px solid ' + bfFieldBorder + ';padding:4px 8px;font-weight:500;color:' + bfLabelColor + '">' + escapeHtml( r ) + '</td>';
					matCols.forEach( function() { html += '<td style="border:1px solid ' + bfFieldBorder + ';text-align:center;padding:4px"><span style="' + matMarkStyle + '"></span></td>'; } );
					html += '</tr>';
				} );
				html += '</tbody></table></div>';

			} else if ( field.type === 'lookup' ) {
				html  = '<div style="position:relative;display:flex;align-items:center">';
				html += '<input type="text" placeholder="' + escapeHtml( field.placeholder || 'Type to search…' ) + '" disabled style="width:100%;padding-right:32px">';
				html += '<span style="position:absolute;right:10px;color:#9ca3af"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></span>';
				html += '</div>';

			} else if ( field.type === 'geolocation' ) {
				html  = '<div style="display:flex;gap:8px;align-items:center">';
				html += '<input type="text" placeholder="Detecting location…" disabled style="flex:1">';
				html += '<span style="display:inline-flex;align-items:center;gap:4px;padding:0 10px;height:36px;background:' + bfAccent + ';color:var(--bf-button-text,#fff);border-radius:' + bfFieldRadius + ';font-size:12px;font-weight:500;white-space:nowrap">';
				html += '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 1v4M12 19v4M4.22 4.22l2.83 2.83M16.95 16.95l2.83 2.83M1 12h4M19 12h4"/></svg> Detect</span>';
				html += '</div>';
				if ( field.geo_show_map ) {
					var geoMapH = Math.max( 40, Math.round( ( field.geo_map_height || 250 ) * 0.3 ) );
					html += '<div style="margin-top:6px;height:' + geoMapH + 'px;background:' + bfFieldBg + ';border:1px solid ' + bfFieldBorder + ';border-radius:' + bfFieldRadius + ';display:flex;align-items:center;justify-content:center;color:' + bfMuted + ';font-size:12px">Map preview</div>';
				}

			} else {
				// Free-text inputs fall back to the label when no placeholder is set
				// (mirrors the front-end renderer).
				html = '<input type="' + escapeHtml( field.type ) + '" placeholder="' + escapeHtml( field.placeholder || field.label || '' ) + '" value="' + escapeHtml( field.default_value ) + '">';
			}

			return label + '<div class="shapeforms-canvas-field-control">' + html + '</div>';
		}

		function getFormStyleVariables() {
			var focusColorMap = {
				teal: '#0f766e',
				blue: '#2563eb',
				green: '#16a34a',
				dark: '#334155'
			};
			var fieldSize = state.formSettings.field_size || 'small';
			var labelSize = state.formSettings.label_size || 'small';
			var buttonSize = state.formSettings.button_size || 'small';
			var fieldStyle = state.formSettings.field_style || '';
			var sizeMap = {
				small: { fieldY: '10px', fieldX: '12px', fieldFont: '14px', labelFont: '14px', buttonY: '10px', buttonX: '16px', buttonFont: '14px', radius: '6px' },
				medium: { fieldY: '12px', fieldX: '14px', fieldFont: '15px', labelFont: '16px', buttonY: '12px', buttonX: '18px', buttonFont: '15px', radius: '8px' },
				large: { fieldY: '15px', fieldX: '16px', fieldFont: '16px', labelFont: '18px', buttonY: '14px', buttonX: '20px', buttonFont: '16px', radius: '10px' }
			};
			var fieldScale = fieldSize ? sizeMap[ fieldSize ] : sizeMap['small'];
			var labelScale = labelSize ? sizeMap[ labelSize ] : sizeMap['small'];
			var buttonScale = buttonSize ? sizeMap[ buttonSize ] : sizeMap['small'];
			var variables = [];

			if ( 'outline' === fieldStyle || 'soft' === fieldStyle || 'minimal' === fieldStyle ) {
				fieldStyle = 'solid';
			}

			if ( fieldStyle ) {
				variables.push( '--bf-field-border-style:' + fieldStyle );
			}
			if ( '' !== state.formSettings.field_border_width ) {
				variables.push( '--bf-field-border-width:' + Number( state.formSettings.field_border_width ) + 'px' );
			}
			if ( '' !== state.formSettings.field_border_radius ) {
				variables.push( '--bf-field-radius:' + Number( state.formSettings.field_border_radius ) + 'px' );
			}
			if ( state.formSettings.field_background_color ) {
				variables.push( '--bf-field-bg:' + state.formSettings.field_background_color );
			}
			if ( state.formSettings.field_border_color ) {
				variables.push( '--bf-field-border:' + state.formSettings.field_border_color );
			}
			if ( state.formSettings.field_text_color ) {
				variables.push( '--bf-field-text:' + state.formSettings.field_text_color );
			}
			if ( state.formSettings.field_focus_color && focusColorMap[ state.formSettings.field_focus_color ] ) {
				variables.push( '--bf-focus-color:' + focusColorMap[ state.formSettings.field_focus_color ] );
			}
			if ( state.formSettings.label_color ) {
				variables.push( '--bf-label-color:' + state.formSettings.label_color );
			}
			if ( state.formSettings.label_subtext_color ) {
				variables.push( '--bf-subtext-color:' + state.formSettings.label_subtext_color );
			}
			if ( state.formSettings.error_color ) {
				variables.push( '--bf-error-color:' + state.formSettings.error_color );
			}
			if ( labelScale ) {
				variables.push( '--bf-label-font-size:' + labelScale.labelFont );
			}
			if ( fieldScale ) {
				variables.push( '--bf-field-padding-y:' + fieldScale.fieldY );
				variables.push( '--bf-field-padding-x:' + fieldScale.fieldX );
				variables.push( '--bf-field-font-size:' + fieldScale.fieldFont );
				variables.push( '--bf-field-radius:' + fieldScale.radius );
			}
			if ( state.formSettings.button_border_style ) {
				variables.push( '--bf-button-border-style:' + state.formSettings.button_border_style );
			}
			if ( '' !== state.formSettings.button_border_width ) {
				variables.push( '--bf-button-border-width:' + Number( state.formSettings.button_border_width ) + 'px' );
			}
			if ( '' !== state.formSettings.button_border_radius ) {
				variables.push( '--bf-button-radius:' + Number( state.formSettings.button_border_radius ) + 'px' );
			}
			if ( state.formSettings.button_background_color ) {
				variables.push( '--bf-button-bg:' + state.formSettings.button_background_color );
			}
			if ( state.formSettings.button_border_color ) {
				variables.push( '--bf-button-border:' + state.formSettings.button_border_color );
			}
			if ( state.formSettings.button_text_color ) {
				variables.push( '--bf-button-text:' + state.formSettings.button_text_color );
			}
			if ( buttonScale ) {
				variables.push( '--bf-button-padding-y:' + buttonScale.buttonY );
				variables.push( '--bf-button-padding-x:' + buttonScale.buttonX );
				variables.push( '--bf-button-font-size:' + buttonScale.buttonFont );
				variables.push( '--bf-button-radius:' + buttonScale.radius );
			}

			return variables.join( ';' ) + ( variables.length ? ';' : '' );
		}

		// Resolves the advanced per-device style vars into a single cascaded map.
		// Tablet inherits desktop; mobile inherits desktop+tablet — matching how the
		// front-end media queries stack (max-width:1024 then max-width:767).
		function resolveDeviceStyleVars( device ) {
			var style = state.formSettings.style || { desktop: {}, tablet: {}, mobile: {} };
			var order = [ 'desktop' ];
			if ( 'tablet' === device ) { order = [ 'desktop', 'tablet' ]; }
			if ( 'mobile' === device ) { order = [ 'desktop', 'tablet', 'mobile' ]; }
			var merged = {};
			order.forEach( function ( layer ) {
				var vars = style[ layer ] || {};
				Object.keys( vars ).forEach( function ( key ) {
					if ( '' !== vars[ key ] && null != vars[ key ] ) {
						merged[ key ] = vars[ key ];
					}
				} );
			} );
			return merged;
		}

		function styleVarsObjectToString( obj ) {
			var out = '';
			Object.keys( obj ).forEach( function ( key ) {
				out += key + ':' + obj[ key ] + ';';
			} );
			return out;
		}

		// Builds the CSS for one preview surface, scoped to its selector. The builder
		// preview can't rely on viewport media queries (the editor viewport is always
		// "desktop"-wide), so it emits the fully RESOLVED vars for state.activeDevice
		// directly; the device toggle additionally resizes the preview container.
		function getFormStyleBlock( scopeSelector ) {
			var decl = getFormStyleVariables() + styleVarsObjectToString( resolveDeviceStyleVars( state.activeDevice ) );
			return decl ? scopeSelector + '{' + decl + '}' : '';
		}

		// Single shared <style> node carrying the scoped rules for all three preview
		// surfaces. Replaces the legacy inline style="" on each canvas so responsive
		// device resolution (and, later, more complex rules) is possible.
		function applyPreviewStyleBlock() {
			var node = document.getElementById( 'shapeforms-preview-style' );
			if ( ! node ) {
				node = document.createElement( 'style' );
				node.id = 'shapeforms-preview-style';
				document.head.appendChild( node );
			}
			node.textContent =
				getFormStyleBlock( '#shapeforms-canvas' ) +
				getFormStyleBlock( '#shapeforms-style-preview-canvas' ) +
				getFormStyleBlock( '#shapeforms-template-preview-canvas' );
		}

		function getStyleControlValue( value, fallback ) {
			return value || fallback;
		}

		function normalizeStyleColorValue( value, fallback ) {
			return value === fallback ? '' : value;
		}

		/**
		 * The "Screen N of M" badge shown on a conversational question.
		 *
		 * Rendered as a plain span inside the existing row/field markup rather
		 * than as a new wrapper: the canvas's drag-and-drop is bound to the
		 * exact row/column/field structure, and re-nesting it to draw cards
		 * would break dropping, sorting and selection.
		 */
		function cvBadge( number, total, isTail ) {
			if ( ! number ) {
				return '';
			}

			var text = ( shapeformsLiteBuilder.labels.cvScreenOf || 'Screen %1$s of %2$s' )
				.replace( '%1$s', String( number ) )
				.replace( '%2$s', String( total ) );

			return '<span class="shapeforms-cv-badge' + ( isTail ? ' is-tail' : '' ) + '">' +
				'<span class="shapeforms-cv-badge__num">' + escapeHtml( String( number ) ) + '</span>' +
				'<span class="shapeforms-cv-badge__text">' + escapeHtml( text ) + '</span>' +
			'</span>';
		}

		function renderCanvas() {
			var $rows = $( '#shapeforms-canvas-rows' );
			var $empty = $( '#shapeforms-canvas-empty' );
			var markup = '';
			var canvasClasses = 'shapeforms-canvas';
			// Conversational canvases are laid out as a stack of numbered
			// question cards, so the author can see the steps without leaving
			// the editor. Stated as a class so the CSS owns the appearance and
			// the markup stays identical in both modes.
			var cvOn  = cvIsOn();
			var cvMap = cvOn ? cvScreenMap() : { byField: {}, byRow: {}, total: 0 };

			if ( cvOn ) {
				canvasClasses += ' is-conversational';
			}

			$rows.empty();
			$( '#shapeforms-canvas' ).attr( 'class', canvasClasses );
			applyPreviewStyleBlock();

			if ( ! getAllRows().length ) {
				$empty.show();
				return;
			}

			$empty.hide();

			getAllRows().forEach(
				function ( row, rowIndex ) {
					var rowSelected = state.selectedRowIndex === rowIndex;
					var cvRowNumber = cvOn ? ( cvMap.byRow[ rowIndex ] || 0 ) : 0;
					// Stated as a class rather than inferred in CSS: "this row is
					// itself one screen" is the difference between the row being
					// the card and each field being a card, and a :has() test for
					// a badge cannot tell a row badge from a field badge.
					var cvRowClass  = cvRowNumber ? ' is-cv-screen' : '';

					// A SEPARATE fact, and the distinction matters: "does this row
					// put things side by side" is not "is this row a screen". The
					// gutter used to ride on is-cv-screen, so it vanished from
					// every multi-column row that is not one — a row still empty,
					// a row holding only fields that render nothing, and every
					// multi-column row once "Put every field on its own screen" is
					// on. Two drop zones then shared an edge.
					cvRowClass += row.columns.length > 1 ? ' is-cv-multicol' : '';

					// There used to be a third class here, is-cv-split, for a
					// multi-column row whose columns were SEPARATE screens — the
					// state "Put every field on its own screen" produced. With
					// that switch removed the state cannot occur: a multi-column
					// row with visible fields is always the screen, so it always
					// has a row number, and the class could never be added.

					// ONE toolbar per screen.
					//
					// A row that produces field-screens is not a screen itself — its
					// field cards are — so it must not carry a toolbar of its own.
					// It used to, and because a field-screen's row card and field
					// card start at the same y, the two pills landed 18px apart and
					// read as one stacked mess. Worse than the look: it offered two
					// toolbars for one thing.
					//
					// An EMPTY row keeps its toolbar regardless. It produces no
					// screens, so nothing else could ever delete it.
					var rowHasFields = row.columns.some( function ( c ) { return c.fields.length; } );
					var showRowTools = ! cvOn || !! cvRowNumber || ! rowHasFields;

					markup += '<section class="shapeforms-row' + ( rowSelected ? ' is-row-selected' : '' ) + cvRowClass + '" data-row-index="' + rowIndex + '">';
					markup += '<div class="shapeforms-row__head">';
					markup += '<strong>' + escapeHtml( shapeformsLiteBuilder.labels.row ) + ' ' + ( rowIndex + 1 ) + '</strong><span>' + row.columns.length + ' ' + escapeHtml( shapeformsLiteBuilder.labels.columns ) + '</span>';

					if ( showRowTools ) {
					markup += '<div class="shapeforms-row__actions">';
					// In conversational mode the row IS the screen here, so its gear
					// opens SCREEN settings — which lives in the field panel, and so
					// selects the first question on the screen to get there.
					markup += cvRowNumber
						? '<button type="button" class="shapeforms-action-icon shapeforms-screen-settings" title="Screen ' + cvRowNumber + ' settings" aria-label="Screen ' + cvRowNumber + ' settings"><span class="dashicons dashicons-admin-generic"></span></button>'
						: '<button type="button" class="shapeforms-action-icon shapeforms-row-settings' + ( rowSelected ? ' is-active' : '' ) + '" title="' + escapeHtml( shapeformsLiteBuilder.labels.rowSettings || 'Row settings' ) + '" aria-label="' + escapeHtml( shapeformsLiteBuilder.labels.rowSettings || 'Row settings' ) + '"><span class="dashicons dashicons-admin-generic"></span></button>';
					markup += '<button type="button" class="shapeforms-action-icon shapeforms-row-move" title="Move row" aria-label="Move row" draggable="true"><span class="dashicons dashicons-move"></span></button>';
					markup += '<button type="button" class="shapeforms-action-icon shapeforms-row-duplicate" title="Duplicate row" aria-label="Duplicate row"><span class="dashicons dashicons-admin-page"></span></button>';
					markup += '<button type="button" class="shapeforms-action-icon is-danger shapeforms-row-delete" title="Delete row" aria-label="Delete row"><span class="dashicons dashicons-trash"></span></button>';
					markup += '</div>';
					}

					markup += '</div>';
					markup += '<div class="shapeforms-row__columns">';
					// A multi-column row is ONE screen, and this container is the
					// card that draws it — so the badge goes INSIDE, in the same
					// top-left position it occupies on a single-question card.
					// Putting it in the row head instead left the two kinds of
					// screen looking like two different things. Safe as a child
					// here: the container is a wrapping flex row and nothing in
					// the JS walks it positionally — columns are found by class
					// and data attribute.
					markup += cvRowNumber ? cvBadge( cvRowNumber, cvMap.total, false ) : '';

					// Does this row put a NUMBERED SCREEN on the canvas? Either the
					// row is one itself, or one of its fields is.
					//
					// This is the line between an empty column that is clutter and
					// one that is the only thing there is to see, and it is drawn
					// at screens rather than at fields on purpose. The complaint
					// was an empty card sitting BETWEEN two numbered questions, so
					// a row that numbers nothing has no such position to be in:
					//
					//   - a row still being built has no other visible presence in
					//     this mode — its head text is clipped and its toolbar only
					//     appears on hover — so hiding its columns would make
					//     "Add Row" look like it did nothing at all;
					//   - a row holding only fields that render nothing to the
					//     visitor is the same case, and hiding one of its two
					//     columns would leave the other stranded at half width
					//     against a gutter that is there to separate them.
					//
					// Measured, not argued: keying this on "the row has fields"
					// instead took the gutter off that second row, and the canvas
					// suite said so.
					var rowHasScreens = !! cvRowNumber || row.columns.some( function ( column ) {
						return ( column.fields || [] ).some( function ( f ) {
							return !! cvMap.byField[ f.id ];
						} );
					} );

					row.columns.forEach(
						function ( column, columnIndex ) {
							var columnClasses = 'shapeforms-column';

							// PRESENTATION ONLY. The column stays in row.columns
							// exactly as it is, keeps its width, and comes back the
							// instant conversational mode goes off. Nothing here
							// touches the data.
							//
							// An empty column drawn as a full drop-zone card reads
							// as a step the visitor will walk through, which is the
							// one thing this canvas must never say wrongly. Hidden
							// while idle, revealed while a drag is in flight — see
							// the is-dragging rules in builder.css and the listeners
							// in bindCanvasDragState().
							if ( cvOn && rowHasScreens && ! ( column.fields || [] ).length ) {
								columnClasses += ' is-cv-ghost';
							}
							// PICKED, not merely current. The builder defaults
							// activeColumn to a column on load and after adding a
							// row, and painting that read as a selection the author
							// never made — on a brand-new form the very first thing
							// they saw was a highlighted box. The highlight answers
							// "where will a clicked field land", which is only a
							// question worth answering once they have chosen.
							var isActive = activeColumnChosen && state.activeColumn && state.activeColumn.rowIndex === rowIndex && state.activeColumn.columnIndex === columnIndex;

							if ( isActive ) {
								columnClasses += ' is-active';
							}

							markup += '<div class="' + columnClasses + '" data-row-index="' + rowIndex + '" data-column-index="' + columnIndex + '" style="width:' + escapeHtml( column.width ) + ';">';
							markup += '<div class="shapeforms-column__head"><span>' + escapeHtml( column.width ) + '</span><span>' + column.fields.length + ' ' + escapeHtml( shapeformsLiteBuilder.labels.fields ) + '</span></div>';
							var fieldsClasses = 'shapeforms-column-fields' + ( column.fields.length ? '' : ' is-empty' );
							markup += '<div class="' + fieldsClasses + '" data-row-index="' + rowIndex + '" data-column-index="' + columnIndex + '">';

							if ( ! column.fields.length ) {
								// Both ways in, said once. "Drop fields here" alone
								// names the harder one and leaves the easier one —
								// clicking a field in the library — to be guessed
								// from a line of help text above the canvas.
								markup += '<div class="shapeforms-column__empty">' +
									'<span class="shapeforms-column__empty-icon dashicons dashicons-plus-alt2" aria-hidden="true"></span>' +
									'<span class="shapeforms-column__empty-title">' + escapeHtml( shapeformsLiteBuilder.labels.dropHere ) + '</span>' +
									'<span class="shapeforms-column__empty-hint">' + escapeHtml( shapeformsLiteBuilder.labels.dropHereHint || 'or click one in the Field Library' ) + '</span>' +
								'</div>';
							}

							column.fields.forEach(
								function ( field ) {
									var fieldClasses = 'shapeforms-canvas-field';
									var fieldLabelPos = field.label_placement || 'top';

									if ( field.id === state.selectedFieldId ) {
										fieldClasses += ' is-selected';
									}
									if ( fieldLabelPos !== 'top' ) {
										fieldClasses += ' is-label-' + fieldLabelPos;
									}

									// A field carries the badge only when it IS the screen.
									// In a multi-column row the badge sits on the row,
									// because both fields share one screen.
									var cvFieldNumber = ( cvOn && ! cvMap.byRow[ rowIndex ] ) ? cvMap.byField[ field.id ] : 0;

									if ( cvOn && ! cvMap.byField[ field.id ] ) {
										// Renders nothing to the visitor, so it is never a
										// step — said out loud rather than left looking
										// like an unnumbered question.
										fieldClasses += ' is-cv-silent';
									}

									markup += '<div class="' + fieldClasses + '" data-field-id="' + escapeHtml( field.id ) + '">';
									markup += cvFieldNumber ? cvBadge( cvFieldNumber, cvMap.total, false ) : '';
									markup += '<div class="shapeforms-canvas-field-actions">';
									// This field IS the screen, so its toolbar is the
									// screen's toolbar and leads the same way the row
									// card's does — with the settings gear first.
									markup += cvFieldNumber
										? '<button type="button" class="shapeforms-action-icon shapeforms-screen-settings" title="Screen ' + cvFieldNumber + ' settings" aria-label="Screen ' + cvFieldNumber + ' settings"><span class="dashicons dashicons-admin-generic"></span></button>'
										: '';
									markup += '<span class="shapeforms-action-icon shapeforms-move-field" title="' + escapeHtml( shapeformsLiteBuilder.labels.moveField || 'Move field' ) + '" aria-label="' + escapeHtml( shapeformsLiteBuilder.labels.moveField || 'Move field' ) + '" draggable="true"><span class="dashicons dashicons-move"></span></span>';
									markup += '<button type="button" class="shapeforms-action-icon shapeforms-edit-field" title="Edit field" aria-label="Edit field"><span class="dashicons dashicons-edit"></span></button>';
									markup += '<button type="button" class="shapeforms-action-icon shapeforms-duplicate-field" title="' + escapeHtml( shapeformsLiteBuilder.actions.duplicate ) + '" aria-label="' + escapeHtml( shapeformsLiteBuilder.actions.duplicate ) + '"><span class="dashicons dashicons-admin-page"></span></button>';
									markup += '<button type="button" class="shapeforms-action-icon is-danger shapeforms-delete-field" title="' + escapeHtml( shapeformsLiteBuilder.actions.delete ) + '" aria-label="' + escapeHtml( shapeformsLiteBuilder.actions.delete ) + '"><span class="dashicons dashicons-trash"></span></button>';
									markup += '</div>';
									markup += '<div class="shapeforms-canvas-field-body">';
									markup += renderInputPreview( field );
									markup += '</div></div>';
								}
							);

							markup += '</div></div>';
						}
					);

					markup += '</div></section>';
				}
			);

			// Only show the fixed bottom button if no draggable submit field exists in the structure.
			if ( ! hasSubmitField() ) {
				var submitMarkup = '<button type="button" class="shapeforms-canvas-submit__button' + ( state.selectedFieldId === submitButtonId ? ' is-selected' : '' ) + '" data-field-id="' + submitButtonId + '">' + buildButtonContent() + '</button>';
				markup += '<div class="shapeforms-canvas-submit is-align-' + escapeHtml( state.formSettings.button_alignment || 'left' ) + '">' + submitMarkup + '</div>';
			}

			markup += '<button type="button" class="shapeforms-canvas-add-row" id="shapeforms-add-row-canvas">';
			markup += '<span class="dashicons dashicons-plus-alt2"></span> ';
			markup += escapeHtml( shapeformsLiteBuilder.labels.addRow || 'Add Row' );
			markup += '</button>';

			$rows.html( markup );
			setupSortables();
		}

		function renderSettingsPanel() {
			var selected = getSelectedFieldLocation();
			var $panel = $( '#shapeforms-settings-panel' );
			var $empty = $( '#shapeforms-settings-empty' );
			var optionsMarkup = '';
			var isChoiceField = false;

			// Row settings.
			if ( state.selectedRowIndex !== null ) {
				var rowObj = getAllRows()[ state.selectedRowIndex ];
				if ( rowObj ) {
					$empty.hide();
					var colWidths = '';

					rowObj.columns.forEach( function ( col, ci ) {
						colWidths += '<div class="shapeforms-setting-group">' +
							'<label>' + escapeHtml( ( shapeformsLiteBuilder.labels.column || 'Column' ) + ' ' + ( ci + 1 ) + ' ' + ( shapeformsLiteBuilder.labels.width || 'Width' ) ) + '</label>' +
							'<input type="text" class="shapeforms-row-col-width" data-col-index="' + ci + '" value="' + escapeHtml( col.width ) + '" placeholder="50%">' +
						'</div>';
					} );

					// Build layout preset buttons for changing columns.
					var layoutPresets = [
						{ label: '1', widths: '100%' },
						{ label: '2', widths: '50%,50%' },
						{ label: '3', widths: '33.33%,33.33%,33.33%' },
						{ label: '2:1', widths: '66.66%,33.33%' },
						{ label: '1:2', widths: '33.33%,66.66%' },
						{ label: '4', widths: '25%,25%,25%,25%' }
					];
					var layoutHtml = '<div class="shapeforms-setting-group"><label>' + escapeHtml( shapeformsLiteBuilder.labels.columnLayout || 'Column Layout' ) + '</label><div class="shapeforms-row-layout-presets">';
					layoutPresets.forEach( function ( preset ) {
						var isActive = preset.widths === rowObj.columns.map( function( c ) { return c.width; } ).join( ',' );
						layoutHtml += '<button type="button" class="shapeforms-row-layout-btn' + ( isActive ? ' is-active' : '' ) + '" data-widths="' + escapeHtml( preset.widths ) + '" title="' + escapeHtml( preset.label ) + '">';
						preset.widths.split( ',' ).forEach( function ( w ) {
							layoutHtml += '<span style="width:' + escapeHtml( w ) + '"></span>';
						} );
						layoutHtml += '</button>';
					} );
					layoutHtml += '</div></div>';

					$panel.removeAttr( 'hidden' ).html(
						'<div class="shapeforms-setting-group">' +
							'<label>' + escapeHtml( shapeformsLiteBuilder.labels.selectedField || 'Selected' ) + '</label>' +
							'<div class="shapeforms-setting-field-name">' + escapeHtml( ( shapeformsLiteBuilder.labels.row || 'Row' ) + ' ' + ( state.selectedRowIndex + 1 ) ) + '</div>' +
						'</div>' +
						layoutHtml +
						colWidths +
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-row-css-class">' + escapeHtml( shapeformsLiteBuilder.labels.cssClass || 'CSS Class' ) + '</label>' +
							'<input type="text" id="shapeforms-setting-row-css-class" value="' + escapeHtml( rowObj.css_class || '' ) + '" placeholder="my-custom-row">' +
						'</div>'
						// No image or colour control here, on purpose. Styling
						// belongs to a SCREEN, and a row is not reliably one — it
						// may be several. It is set by selecting the question and
						// using the "Screen N" panel, which edits the right thing
						// whichever the screen turns out to be.
					);
					return;
				}
			}


			/**
			 * Everything that styles the SCREEN a question belongs to.
			 *
			 * The author selects a question; this edits the screen that question is
			 * on. WHICH object it writes to depends on what the screen is — the
			 * field when the field is the screen, the row when several questions
			 * share one — but that is a storage detail, not something the author
			 * should have to think about or navigate to.
			 *
			 * There is deliberately NO background or colour control in row settings.
			 * "Go and select the row instead" is a step nobody should have to take
			 * to style the screen they are already looking at, and it was the reason
			 * the setting still felt like a row setting after the storage was fixed.
			 *
			 * Its own accordion, first in the panel, because on a conversational
			 * form the screen is what the author came here to style — burying it
			 * under a field's validation settings inverts that.
			 *
			 * Conversational mode only: on an ordinary form this section would do
			 * nothing at all.
			 */
			function screenSettings( location ) {
				if ( ! cvIsOn() || ! location || ! location.field ) {
					return '';
				}

				var map    = cvScreenMap();
				var number = map.byField[ location.field.id ];

				if ( ! number ) {
					// Hidden fields, page breaks — nothing the visitor ever sees.
					return screenAccordion(
						'Screen',
						'<p class="shapeforms-setting-help">This field never becomes a screen, so it has nothing to style.</p>'
					);
				}

				// A multi-column row is ONE screen, shared by the questions on it,
				// so the row is where that screen's styling lives. Edited from right
				// here all the same.
				var shared = !! map.byRow[ location.rowIndex ];
				var target = shared ? ( getAllRows()[ location.rowIndex ] || location.field ) : location.field;

				return screenAccordion(
					'Screen ' + number,
					screenLayoutSettings( location, number )
						+ cvColourSettings( target, number, shared )
						+ cvMediaSettings(
							target,
							number,
							shared
								? 'This screen is the whole row, so the questions beside this one share it.'
								: 'Just this question.'
						)
				);
			}

			/**
			 * The accordion shell, matching Settings and Advanced below it.
			 *
			 * Closed by default and opened by the screen card's gear, which is the
			 * affordance that points at it.
			 */
			function screenAccordion( title, body ) {
				return '<div class="shapeforms-field-accordion' + ( 'screen' === state.activeSettingsAccordion ? ' is-open' : '' ) + '" data-accordion="screen">' +
					'<button type="button" class="shapeforms-field-accordion__head">' + escapeHtml( title ) + ' <span class="dashicons dashicons-arrow-down-alt2"></span></button>' +
					'<div class="shapeforms-field-accordion__body">' + body + '</div>' +
				'</div>';
			}

			/**
			 * How many questions share this screen.
			 *
			 * The same control as row settings' "Column Layout", asked the way it
			 * actually matters here. It lives in screen settings because
			 * conversational mode has no row settings panel to reach — a row that
			 * produces field-screens carries no toolbar at all, since its field
			 * cards are the screens.
			 */
			function screenLayoutSettings( location, number ) {
				var row = getAllRows()[ location.rowIndex ];

				if ( ! row ) {
					return '';
				}

				var current = row.columns.map( function ( c ) { return c.width; } ).join( ',' );
				var html    = '<div class="shapeforms-setting-group">' +
					'<label>Questions on screen ' + number + '</label>' +
					'<div class="shapeforms-row-layout-presets">';

				[
					{ label: '1', widths: '100%' },
					{ label: '2', widths: '50%,50%' },
					{ label: '3', widths: '33.33%,33.33%,33.33%' },
					{ label: '2:1', widths: '66.66%,33.33%' },
					{ label: '1:2', widths: '33.33%,66.66%' },
					{ label: '4', widths: '25%,25%,25%,25%' }
				].forEach( function ( preset ) {
					html += '<button type="button" class="shapeforms-row-layout-btn' + ( preset.widths === current ? ' is-active' : '' ) + '" data-widths="' + escapeHtml( preset.widths ) + '" title="' + escapeHtml( preset.label ) + '">';
					preset.widths.split( ',' ).forEach( function ( w ) {
						html += '<span style="width:' + escapeHtml( w ) + '"></span>';
					} );
					html += '</button>';
				} );

				// What the chosen tile actually did, said in the same breath as
				// the choice. Two cases now, not three: the branch describing
				// "Put every field on its own screen" went with that switch.
				var help;

				if ( row.columns.length < 2 ) {
					help = 'One question per screen. Add a column to put questions side by side on the same screen.';
				} else {
					help = 'Side-by-side questions share one screen. The other screens are not affected.';
				}

				return html + '</div>' +
					'<p class="shapeforms-setting-help">' + escapeHtml( help ) + '</p></div>';
			}

			/**
			 * This screen's six colours.
			 *
			 * Every one of them starts as "Form default" and stays that way until
			 * the author changes it HERE. The alternative — pre-filling each picker
			 * with the form's current value — looks identical and is a trap: the
			 * author changes the form's colour six months later and nothing moves,
			 * because every screen quietly pinned a copy of the old one. So an
			 * untouched colour is stored as '' and the property is never emitted,
			 * which is what leaves the form's own value free to cascade in.
			 *
			 * Background and question text sit side by side deliberately. Nothing
			 * here stops an author making a screen unreadable, but the two colours
			 * that decide it are at least visible together while choosing.
			 */
			function cvColourSettings( target, screenNumber, shared ) {
				var html = '<div class="shapeforms-setting-group shapeforms-screen-colours">' +
					'<label>Screen ' + screenNumber + ' colours</label>' +
					'<p class="shapeforms-setting-help">' +
						escapeHtml( shared
							? 'Each one follows the form until you change it here, for every question on this screen.'
							: 'Each one follows the form until you change it here.' ) +
					'</p>';

				/*
				 * Three, not six.
				 *
				 * Accent, Button and Button text used to be offered here too, and
				 * every one of them was inert: the only rules that read those
				 * three tokens are the nav buttons, the progress bar, the dots and
				 * the counter — all of which live on the WRAPPER, outside every
				 * screen. A value set on a screen could never reach any of them,
				 * so the controls stored a colour and changed nothing. They belong
				 * to the form, and are on the Style tab.
				 *
				 * What is left is what a screen genuinely owns: what it is painted
				 * on, and the two kinds of text inside it.
				 */
				[
					[ [ 'cv_bg', 'Background', '#ffffff' ], [ 'cv_question_color', 'Question text', '#111827' ] ],
					[ [ 'cv_answer_color', 'Answer text', '#111827' ] ]
				].forEach( function ( pair ) {
					html += '<div class="shapeforms-setting-row">';
					pair.forEach( function ( spec ) {
						html += screenColourField( target, spec[ 0 ], spec[ 1 ], spec[ 2 ] );
					} );
					html += '</div>';
				} );

				return html + '</div>';
			}

			/**
			 * One colour, in one of two states.
			 *
			 * The SAME control the Style tab uses for every other colour in this
			 * plugin, minus the opacity row these do not have: swatch, hex
			 * box, reset. Two panels styling the same kind of thing with two
			 * different-looking controls is a worse cost than the small amount of
			 * markup shared here.
			 *
			 * A colour input cannot be empty — it reports #000000 when unset — so
			 * "inherits" is carried outside the value: the stored string is '', the
			 * hex box is left EMPTY so its "Default" placeholder shows, and reset
			 * is disabled because there is nothing to undo. The swatch meanwhile
			 * previews what the screen will actually look like, which is the form's
			 * colour, or the stylesheet's if the form never set one.
			 *
			 * Classes are borrowed deliberately, and carefully: `bf-adv-colorpick`
			 * because the generic swatch handler excludes it, `bf-adv-hex` for the
			 * typography — but never `shapeforms-adv-field`, which is what scopes the
			 * Style tab's handlers. Those write through the `--bf-*` variable
			 * system these flat `cv_*` keys are not part of.
			 */
			function screenColourField( target, key, label, hardDefault ) {
				var own       = target[ key ] || '';
				var inherited = state.formSettings[ key ] || hardDefault;
				var shown     = own || inherited;
				var id        = 'shapeforms-screen-' + key.replace( /_/g, '-' );
				var resetText = advLabel( 'reset' );

				return '<div class="shapeforms-setting-group shapeforms-screen-colour' + ( own ? ' is-custom' : '' ) + '" data-cv-key="' + key + '">' +
					'<label for="' + id + '">' + escapeHtml( label ) + '</label>' +
					'<div class="shapeforms-color-field shapeforms-screen-colour__field" style="--bf-sw:' + escapeHtml( shown ) + '">' +
						'<div class="shapeforms-color-swatch">' +
							'<input type="color" id="' + id + '" class="bf-adv-colorpick" value="' + escapeHtml( shown ) + '" data-cv-key="' + key + '">' +
						'</div>' +
						// Empty when inheriting, so the placeholder speaks. A
						// resolved hex here would read as a value this screen owns.
						'<input type="text" class="bf-adv-hex bf-screen-hex" maxlength="7" placeholder="' + escapeHtml( advLabel( 'inheritDefault' ) ) + '" value="' + escapeHtml( own ) + '" spellcheck="false" data-cv-key="' + key + '">' +
						'<button type="button" class="bf-adv-color-reset"' + ( own ? '' : ' disabled' ) + ' data-cv-key="' + key + '" title="' + escapeHtml( resetText ) + '" aria-label="' + escapeHtml( resetText ) + '">' +
							'<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg>' +
						'</button>' +
					'</div>' +
				'</div>';
			}

			/**
			 * The controls themselves.
			 *
			 * Stores the attachment ID, not the URL: a stored URL breaks when the
			 * site moves and cannot produce responsive sources.
			 *
			 * Called an IMAGE, not a background. "Behind the question" is only one
			 * of the three placements, and the colour section directly above has a
			 * control called Background — two different things under one word in
			 * one panel.
			 */
			function cvMediaSettings( rowObj, screenNumber, coverage ) {
				var layout   = rowObj.cv_media_layout || 'none';
				var hasImg   = !! rowObj.cv_media_id;
				var preview  = rowObj.cv_media_preview || '';

				/** A range plus a live readout — "30" means nothing without one. */
				function mediaRange( id, label, value, min, max, suffix ) {
					return '<div class="shapeforms-setting-group shapeforms-row-media__range">' +
						'<label for="' + id + '">' + escapeHtml( label ) +
							'<output for="' + id + '" id="' + id + '-out">' + value + ( suffix || '' ) + '</output>' +
						'</label>' +
						// The track's fill is drawn from this, not by the browser's
						// accent-color — which stops at the thumb's centre and left
						// unfilled track showing under a readout saying 100%.
						'<input type="range" id="' + id + '" min="' + min + '" max="' + max + '" value="' + value + '" data-suffix="' + escapeHtml( suffix || '' ) + '" style="--bf-range-p:' + rangePercent( value, min, max ) + '">' +
					'</div>';
				}

				var chooser = hasImg
					? '<div class="shapeforms-row-media__thumb">' +
							'<img src="' + escapeHtml( preview ) + '" alt="">' +
							'<div class="shapeforms-row-media__thumb-actions">' +
								'<button type="button" class="button button-small" id="shapeforms-row-media-pick">Replace</button>' +
								'<button type="button" class="button button-small shapeforms-row-media__remove" id="shapeforms-row-media-remove">Remove</button>' +
							'</div>' +
						'</div>'
					: '<button type="button" class="shapeforms-row-media__drop" id="shapeforms-row-media-pick">' +
							'<span class="dashicons dashicons-format-image" aria-hidden="true"></span>' +
							'<span class="shapeforms-row-media__drop-title">Choose an image</span>' +
							'<span class="shapeforms-row-media__drop-hint">Wide images work best &mdash; 1600&times;900 or larger</span>' +
						'</button>';

				// WHERE the image goes, always visible — including before there is
				// an image. It used to appear only after one was chosen, so the
				// panel's empty state said "choose an image" and gave no hint that
				// left, right and behind were even possible; the only placement
				// anyone ever saw was the one it silently defaulted to.
				//
				// With no image yet, picking a placement IS how you add one: it
				// records the intent and opens the media library, so the author
				// answers "where" and "which" in that order instead of choosing
				// blind and correcting afterwards.
				var placements = [
					{ value: 'left', label: 'Left' },
					{ value: 'right', label: 'Right' },
					{ value: 'background', label: 'Behind' }
				].map( function ( option ) {
					return '<button type="button" class="shapeforms-media-place__btn' +
							( option.value === layout ? ' is-active' : '' ) + '"' +
							' data-layout="' + option.value + '"' +
							' aria-pressed="' + ( option.value === layout ? 'true' : 'false' ) + '">' +
						'<span class="shapeforms-media-place__fig shapeforms-media-place__fig--' + option.value + '" aria-hidden="true"></span>' +
						'<span class="shapeforms-media-place__label">' + escapeHtml( option.label ) + '</span>' +
					'</button>';
				} ).join( '' );

				// Titled by the SCREEN, not by what happens to store it. The
				// author is styling step 3, whether step 3 turns out to be a field
				// or a row.
				return '<div class="shapeforms-setting-group shapeforms-row-media">' +
					'<label>Screen ' + screenNumber + ' image</label>' +
					'<p class="shapeforms-setting-help">' + escapeHtml( coverage ) + '</p>' +
					chooser +
					'<div class="shapeforms-setting-group shapeforms-media-place">' +
						'<label>Placement</label>' +
						'<div class="shapeforms-media-place__row">' + placements + '</div>' +
						( hasImg ? '' : '<p class="shapeforms-setting-help">Pick one to choose an image for it.</p>' ) +
					'</div>' +
					( hasImg ?
						// Framing only matters once the image is cropped into a
						// panel; with no placement there is nothing to position.
						( 'none' === layout ? '' :
							'<div class="shapeforms-row-media__grid">' +
								mediaRange( 'shapeforms-row-media-x', 'Horizontal', ( typeof rowObj.cv_media_x === 'number' ? rowObj.cv_media_x : 50 ), 0, 100, '%' ) +
								mediaRange( 'shapeforms-row-media-y', 'Vertical', ( typeof rowObj.cv_media_y === 'number' ? rowObj.cv_media_y : 50 ), 0, 100, '%' ) +
							'</div>' +
							mediaRange( 'shapeforms-row-media-brightness', 'Brightness', ( typeof rowObj.cv_media_brightness === 'number' ? rowObj.cv_media_brightness : 100 ), 10, 100, '%' ) +
							( 'background' === layout ? '<p class="shapeforms-setting-help">Dim a busy image so the question stays readable on top of it.</p>' : '' ) +
							// A number, not a range: a range cannot say "unset",
							// and this control's whole point is that leaving it
							// alone keeps following the default.
							'<div class="shapeforms-setting-group">' +
								'<label for="shapeforms-row-media-height">Height</label>' +
								'<div class="shapeforms-input-suffix">' +
									'<input type="number" id="shapeforms-row-media-height" min="80" max="2000" step="10" value="' + escapeHtml( rowObj.cv_media_height ? String( rowObj.cv_media_height ) : '' ) + '" placeholder="Default">' +
									'<span class="shapeforms-input-suffix__unit">px</span>' +
								'</div>' +
								'<p class="shapeforms-setting-help">' +
									escapeHtml( 'background' === layout
										? 'How tall this screen is. Leave empty to follow the default.'
										: 'How tall the image panel is. Leave empty to follow the default.' ) +
								'</p>' +
							'</div>'
						) +
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-row-media-alt">Alt text</label>' +
							'<input type="text" id="shapeforms-row-media-alt" value="' + escapeHtml( rowObj.cv_media_alt || '' ) + '" placeholder="Describe the image">' +
							'<p class="shapeforms-setting-help">Leave empty if the image is decorative &mdash; it is then hidden from screen readers.</p>' +
						'</div>'
					: '' ) +
				'</div>';
			}

			var isSubmitSelected = state.selectedFieldId === submitButtonId || ( selected && selected.field && 'submit' === selected.field.type );

			if ( isSubmitSelected ) {
				$empty.hide();
				$panel.removeAttr( 'hidden' ).html(
					'<div class="shapeforms-setting-group">' +
						'<label>' + escapeHtml( shapeformsLiteBuilder.labels.selectedField ) + '</label>' +
						'<div class="shapeforms-setting-field-name">' + escapeHtml( shapeformsLiteBuilder.labels.submitButton ) + '</div>' +
					'</div>' +
					'<div class="shapeforms-setting-group">' +
						'<label for="shapeforms-setting-button-text">' + escapeHtml( shapeformsLiteBuilder.labels.buttonText ) + '</label>' +
						'<input type="text" id="shapeforms-setting-button-text" value="' + escapeHtml( state.formSettings.button_text ) + '">' +
					'</div>' +
					'<div class="shapeforms-setting-group">' +
						'<label>' + escapeHtml( shapeformsLiteBuilder.labels.buttonAlignment ) + '</label>' +
						'<div class="shapeforms-btn-group" id="shapeforms-setting-button-alignment">' +
							'<button type="button" class="shapeforms-btn-group__btn' + ( 'left' === state.formSettings.button_alignment ? ' is-active' : '' ) + '" data-value="left"><span class="dashicons dashicons-editor-alignleft"></span></button>' +
							'<button type="button" class="shapeforms-btn-group__btn' + ( 'center' === state.formSettings.button_alignment ? ' is-active' : '' ) + '" data-value="center"><span class="dashicons dashicons-editor-aligncenter"></span></button>' +
							'<button type="button" class="shapeforms-btn-group__btn' + ( 'right' === state.formSettings.button_alignment ? ' is-active' : '' ) + '" data-value="right"><span class="dashicons dashicons-editor-alignright"></span></button>' +
						'</div>' +
					'</div>' +
					'<div class="shapeforms-setting-group">' +
						'<label for="shapeforms-setting-button-icon-type">' + escapeHtml( shapeformsLiteBuilder.labels.buttonIconType || 'Icon' ) + '</label>' +
						'<select id="shapeforms-setting-button-icon-type">' +
							'<option value="none"' + ( 'none' === ( state.formSettings.button_icon_type || 'none' ) ? ' selected' : '' ) + '>' + escapeHtml( shapeformsLiteBuilder.labels.none || 'None' ) + '</option>' +
							'<option value="dashicon"' + ( 'dashicon' === state.formSettings.button_icon_type ? ' selected' : '' ) + '>' + escapeHtml( shapeformsLiteBuilder.labels.dashicon || 'Dashicon' ) + '</option>' +
							'<option value="svg"' + ( 'svg' === state.formSettings.button_icon_type ? ' selected' : '' ) + '>' + escapeHtml( shapeformsLiteBuilder.labels.customSvg || 'Custom SVG' ) + '</option>' +
						'</select>' +
					'</div>' +
					( 'dashicon' === state.formSettings.button_icon_type ? ( function () {
						var dashicons = [
							'dashicons-arrow-right-alt',
							'dashicons-arrow-right-alt2',
							'dashicons-arrow-right',
							'dashicons-arrow-left-alt',
							'dashicons-arrow-left-alt2',
							'dashicons-arrow-left',
							'dashicons-arrow-up-alt',
							'dashicons-arrow-up-alt2',
							'dashicons-arrow-up',
							'dashicons-arrow-down-alt',
							'dashicons-arrow-down-alt2',
							'dashicons-arrow-down',
							'dashicons-controls-forward',
							'dashicons-controls-back',
							'dashicons-controls-play',
							'dashicons-redo',
							'dashicons-undo',
							'dashicons-update',
							'dashicons-leftright',
							'dashicons-sort',
							'dashicons-randomize',
							'dashicons-external',
							'dashicons-migrate',
							'dashicons-move',
							'dashicons-download',
							'dashicons-upload',
							'dashicons-exit',
							'dashicons-plus',
							'dashicons-plus-alt',
							'dashicons-plus-alt2',
							'dashicons-minus',
							'dashicons-yes',
							'dashicons-yes-alt',
							'dashicons-no',
							'dashicons-no-alt',
							'dashicons-dismiss',
							'dashicons-saved',
							'dashicons-email',
							'dashicons-email-alt',
							'dashicons-cart',
							'dashicons-heart',
							'dashicons-star-filled',
							'dashicons-lock',
							'dashicons-unlock',
							'dashicons-search',
							'dashicons-share',
							'dashicons-share-alt',
							'dashicons-share-alt2',
							'dashicons-insert',
							'dashicons-paperclip',
							'dashicons-edit'
						];
						var current = state.formSettings.button_icon_dashicon || 'dashicons-arrow-right-alt';
						var opts = '';
						for ( var i = 0; i < dashicons.length; i++ ) {
							opts += '<option value="' + dashicons[ i ] + '"' + ( dashicons[ i ] === current ? ' selected' : '' ) + '>' + dashicons[ i ].replace( 'dashicons-', '' ) + '</option>';
						}
						return '<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-button-icon-dashicon">' + escapeHtml( shapeformsLiteBuilder.labels.dashiconClass || 'Dashicon' ) + '</label>' +
							'<div style="display:flex;align-items:center;gap:8px;">' +
								'<select id="shapeforms-setting-button-icon-dashicon" style="flex:1;">' + opts + '</select>' +
								'<span class="dashicons ' + escapeHtml( current ) + '" style="font-size:20px;width:20px;height:20px;color:#555;"></span>' +
							'</div>' +
						'</div>';
					}() ) : ''
					) +
					( 'svg' === state.formSettings.button_icon_type ? ( function () {
						var svgUrl = state.formSettings.button_icon_svg || '';
						return '<div class="shapeforms-setting-group">' +
							'<label>' + escapeHtml( shapeformsLiteBuilder.labels.svgCode || 'SVG Icon' ) + '</label>' +
							'<div class="shapeforms-svg-upload-wrap">' +
								( svgUrl ?
									'<div class="shapeforms-svg-preview">' +
										'<img src="' + escapeHtml( svgUrl ) + '" class="shapeforms-svg-preview__img" alt="icon">' +
										'<span class="shapeforms-svg-preview__name">' + escapeHtml( svgUrl.split( '/' ).pop() ) + '</span>' +
										'<button type="button" class="shapeforms-svg-remove" title="Remove">' +
											'<span class="dashicons dashicons-no-alt"></span>' +
										'</button>' +
									'</div>' : ''
								) +
								'<button type="button" class="shapeforms-svg-upload-btn" id="shapeforms-svg-upload-btn">' +
									'<span class="dashicons dashicons-upload"></span> ' +
									escapeHtml( svgUrl ? ( shapeformsLiteBuilder.labels.changeSvg || 'Change SVG' ) : ( shapeformsLiteBuilder.labels.uploadSvg || 'Upload SVG' ) ) +
								'</button>' +
							'</div>' +
						'</div>';
					}() ) : ''
					) +
					( 'none' !== ( state.formSettings.button_icon_type || 'none' ) ?
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-button-icon-position">' + escapeHtml( shapeformsLiteBuilder.labels.iconPosition || 'Icon position' ) + '</label>' +
							'<select id="shapeforms-setting-button-icon-position">' +
								'<option value="left"' + ( 'left' === state.formSettings.button_icon_position ? ' selected' : '' ) + '>' + escapeHtml( shapeformsLiteBuilder.labels.left ) + '</option>' +
								'<option value="right"' + ( 'right' === ( state.formSettings.button_icon_position || 'right' ) ? ' selected' : '' ) + '>' + escapeHtml( shapeformsLiteBuilder.labels.right ) + '</option>' +
							'</select>' +
						'</div>' +
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-button-icon-gap">' + escapeHtml( shapeformsLiteBuilder.labels.iconGap || 'Icon gap (px)' ) + '</label>' +
							'<input type="number" id="shapeforms-setting-button-icon-gap" value="' + escapeHtml( state.formSettings.button_icon_gap || '8' ) + '" min="0" max="30" placeholder="8">' +
						'</div>' +
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-button-icon-size">' + escapeHtml( shapeformsLiteBuilder.labels.iconSize || 'Icon size (px)' ) + '</label>' +
							'<input type="number" id="shapeforms-setting-button-icon-size" value="' + escapeHtml( state.formSettings.button_icon_size || '18' ) + '" min="10" max="60" placeholder="18">' +
						'</div>' +
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-button-icon-color">' + escapeHtml( shapeformsLiteBuilder.labels.iconColor || 'Icon color' ) + '</label>' +
							'<div class="shapeforms-color-wrap"><input type="color" id="shapeforms-setting-button-icon-color" value="' + escapeHtml( state.formSettings.button_icon_color || '#ffffff' ) + '"><span class="shapeforms-color-preview" style="background:' + escapeHtml( state.formSettings.button_icon_color || '#ffffff' ) + '"></span></div>' +
						'</div>' +
						'<p class="description" style="color:#9ca3af;font-size:12px;margin:4px 0">Clear the button text above for an icon-only button.</p>' : ''
					)
				);
				return;
			}

			if ( ! selected ) {
				$empty.show();
				$panel.attr( 'hidden', true ).empty();
				return;
			}

			isChoiceField = selected.field.type === 'checkbox' || selected.field.type === 'radio';

			if ( optionFieldTypes.indexOf( selected.field.type ) !== -1 ) {
				var isMultiDefault = selected.field.type === 'checkbox' || selected.field.type === 'multiselect';
				var defaultInputType = isMultiDefault ? 'checkbox' : 'radio';
				var currentDefaults = isMultiDefault
					? ( selected.field.default_value || '' ).split( ',' ).map( function ( v ) { return $.trim( v ); } ).filter( function ( v ) { return v.length; } )
					: [ $.trim( selected.field.default_value || '' ) ];

				optionsMarkup = '<div class="shapeforms-setting-group">';
				optionsMarkup += '<label>' + escapeHtml( shapeformsLiteBuilder.labels.options ) + '</label>';
				optionsMarkup += '<div class="shapeforms-options-repeater" id="shapeforms-options-repeater" data-field-type="' + escapeHtml( selected.field.type ) + '">';

				// The trigger stays in every row whatever the treatment, because the
				// options are collected back FROM these rows — a trigger that appeared
				// and disappeared with the treatment would take the icons with it on
				// the next keystroke. Outside the Button treatment it is `hidden`, so
				// it is neither seen nor reachable, only readable.
				var iconsInert = ! bfIconableChoice( selected.field );

				selected.field.options.forEach( function ( option, index ) {
					var trimmed = $.trim( option );
					var isDefault = trimmed.length > 0 && currentDefaults.indexOf( trimmed ) !== -1;
					optionsMarkup += '<div class="shapeforms-options-repeater__item" data-option-index="' + index + '">';
					optionsMarkup += '<label class="shapeforms-options-repeater__default' + ( isDefault ? ' is-checked' : '' ) + '" title="' + escapeHtml( shapeformsLiteBuilder.labels.setDefault || 'Set as default' ) + '">';
					optionsMarkup += '<input type="' + defaultInputType + '" name="shapeforms-option-default" value="' + index + '"' + ( isDefault ? ' checked' : '' ) + '>';
					optionsMarkup += '<span class="shapeforms-options-repeater__' + ( isMultiDefault ? 'checkbox' : 'radio' ) + '"></span>';
					optionsMarkup += '</label>';
					optionsMarkup += '<span class="shapeforms-options-repeater__drag" draggable="true"><span class="dashicons dashicons-menu"></span></span>';
					optionsMarkup += bfOpticonBtn( selected.field, index, trimmed, iconsInert );
					optionsMarkup += '<input type="text" class="shapeforms-options-repeater__input" value="' + escapeHtml( option ) + '" placeholder="' + escapeHtml( shapeformsLiteBuilder.labels.optionPlaceholder || 'Option value' ) + '">';
					optionsMarkup += '<button type="button" class="shapeforms-options-repeater__remove" title="' + escapeHtml( shapeformsLiteBuilder.actions.delete || 'Remove' ) + '"><span class="dashicons dashicons-no-alt"></span></button>';
					optionsMarkup += '</div>';
				} );

				optionsMarkup += '</div>';
				optionsMarkup += '<button type="button" class="shapeforms-options-repeater__add" id="shapeforms-option-add"><span class="dashicons dashicons-plus-alt2"></span> ' + escapeHtml( shapeformsLiteBuilder.labels.addOption || 'Add Option' ) + '</button>';
				optionsMarkup += '</div>';
			}

			if ( isChoiceField ) {
				optionsMarkup +=
					'<div class="shapeforms-setting-group">' +
						'<label>' + escapeHtml( shapeformsLiteBuilder.labels.optionsLayout || 'Layout' ) + '</label>' +
						'<select id="shapeforms-setting-options-layout">' +
							'<option value="block"' + ( 'inline' !== selected.field.options_layout ? ' selected' : '' ) + '>' + escapeHtml( shapeformsLiteBuilder.labels.optionsLayoutBlock || 'Stacked (default)' ) + '</option>' +
							'<option value="inline"' + ( 'inline' === selected.field.options_layout ? ' selected' : '' ) + '>' + escapeHtml( shapeformsLiteBuilder.labels.optionsLayoutInline || 'Inline' ) + '</option>' +
						'</select>' +
					'</div>';

				// Per-field appearance. Sits under Layout because it is the same kind
				// of decision — how this particular option set looks — while the Style
				// tab keeps owning the form-wide defaults these fall back to.
				optionsMarkup += bfChoiceStyleMarkup( selected.field );
			}

			// Checkbox-only: render as a toggle switch instead of a square box.
			if ( selected.field.type === 'checkbox' ) {
				optionsMarkup +=
					'<div class="shapeforms-setting-group">' +
						'<label>' + escapeHtml( shapeformsLiteBuilder.labels.checkboxStyle || 'Style' ) + '</label>' +
						'<select id="shapeforms-setting-checkbox-style">' +
							'<option value="default"' + ( 'switch' !== selected.field.checkbox_style ? ' selected' : '' ) + '>' + escapeHtml( shapeformsLiteBuilder.labels.checkboxStyleDefault || 'Checkbox' ) + '</option>' +
							'<option value="switch"' + ( 'switch' === selected.field.checkbox_style ? ' selected' : '' ) + '>' + escapeHtml( shapeformsLiteBuilder.labels.checkboxStyleSwitch || 'Switch' ) + '</option>' +
						'</select>' +
					'</div>';
			}

			if ( selected.field.type === 'select' ) {
				optionsMarkup +=
					'<div class="shapeforms-switch-item">' +
						'<label class="shapeforms-switch__row">' +
							'<span class="shapeforms-switch__text">' + escapeHtml( shapeformsLiteBuilder.labels.selectSearchable || 'Enable search' ) + '</span>' +
							'<input type="checkbox" id="shapeforms-setting-select-searchable"' + ( selected.field.select_searchable ? ' checked' : '' ) + '>' +
							'<span class="shapeforms-switch__track"><span class="shapeforms-switch__thumb"></span></span>' +
						'</label>' +
					'</div>';
			}

			// Name field settings.
			if ( selected.field.type === 'name' ) {
				optionsMarkup +=
					'<div class="shapeforms-switch-item">' +
						'<label class="shapeforms-switch__row">' +
							'<span class="shapeforms-switch__text">' + escapeHtml( shapeformsLiteBuilder.labels.showMiddleName || 'Middle Name' ) + '</span>' +
							'<input type="checkbox" id="shapeforms-setting-show-middle-name"' + ( selected.field.show_middle_name ? ' checked' : '' ) + '>' +
							'<span class="shapeforms-switch__track"><span class="shapeforms-switch__thumb"></span></span>' +
						'</label>' +
					'</div>' +
					'<div class="shapeforms-switch-item">' +
						'<label class="shapeforms-switch__row">' +
							'<span class="shapeforms-switch__text">' + escapeHtml( shapeformsLiteBuilder.labels.showLastName || 'Last Name' ) + '</span>' +
							'<input type="checkbox" id="shapeforms-setting-show-last-name"' + ( selected.field.show_last_name ? ' checked' : '' ) + '>' +
							'<span class="shapeforms-switch__track"><span class="shapeforms-switch__thumb"></span></span>' +
						'</label>' +
					'</div>';
			}

			// Address field settings.
			if ( selected.field.type === 'address' ) {
				var addrLabels = {
					street: shapeformsLiteBuilder.labels.street || 'Street Address',
					city: shapeformsLiteBuilder.labels.city || 'City',
					state: shapeformsLiteBuilder.labels.state || 'State / Province',
					zip: shapeformsLiteBuilder.labels.zip || 'ZIP / Postal Code',
					country: shapeformsLiteBuilder.labels.country || 'Country'
				};
				var af = selected.field.address_fields || {};
				var addrOrder = selected.field.address_order || [ 'street', 'city', 'state', 'zip', 'country' ];
				optionsMarkup += '<div class="shapeforms-setting-group"><label>' + escapeHtml( shapeformsLiteBuilder.labels.addressFields || 'Address Fields' ) + '</label></div>';
				optionsMarkup += '<div class="shapeforms-addr-list" id="shapeforms-addr-list">';
				addrOrder.forEach( function ( key ) {
					var isOn = af[ key ] !== false;
					optionsMarkup +=
						'<div class="shapeforms-addr-item" data-addr-key="' + key + '">' +
							'<span class="shapeforms-addr-item__drag" draggable="true"><span class="dashicons dashicons-menu"></span></span>' +
							'<span class="shapeforms-addr-item__label">' + escapeHtml( addrLabels[ key ] || key ) + '</span>' +
							'<label class="shapeforms-addr-item__toggle">' +
								'<input type="checkbox" class="shapeforms-setting-address-field" data-addr-key="' + key + '"' + ( isOn ? ' checked' : '' ) + '>' +
								'<span class="shapeforms-switch__track"><span class="shapeforms-switch__thumb"></span></span>' +
							'</label>' +
						'</div>';
				} );
				optionsMarkup += '</div>';
			}

			// Input mask settings.
			if ( selected.field.type === 'input_mask' ) {
				optionsMarkup +=
					'<div class="shapeforms-setting-group">' +
						'<label for="shapeforms-setting-mask-pattern">' + escapeHtml( shapeformsLiteBuilder.labels.maskPattern || 'Mask Pattern' ) + '</label>' +
						'<select id="shapeforms-setting-mask-pattern">' +
							'<option value=""' + ( ! selected.field.mask_pattern ? ' selected' : '' ) + '>' + escapeHtml( shapeformsLiteBuilder.labels.noMask || 'No mask' ) + '</option>' +
							'<option value="(999) 999-9999"' + ( '(999) 999-9999' === selected.field.mask_pattern ? ' selected' : '' ) + '>Phone: (999) 999-9999</option>' +
							'<option value="99/99/9999"' + ( '99/99/9999' === selected.field.mask_pattern ? ' selected' : '' ) + '>Date: 99/99/9999</option>' +
							'<option value="9999-9999-9999-9999"' + ( '9999-9999-9999-9999' === selected.field.mask_pattern ? ' selected' : '' ) + '>Card: 9999-9999-9999-9999</option>' +
							'<option value="99:99"' + ( '99:99' === selected.field.mask_pattern ? ' selected' : '' ) + '>Time: 99:99</option>' +
							'<option value="999.999.999-99"' + ( '999.999.999-99' === selected.field.mask_pattern ? ' selected' : '' ) + '>CPF: 999.999.999-99</option>' +
							'<option value="custom"' + ( selected.field.mask_pattern && [ '(999) 999-9999', '99/99/9999', '9999-9999-9999-9999', '99:99', '999.999.999-99' ].indexOf( selected.field.mask_pattern ) === -1 ? ' selected' : '' ) + '>Custom</option>' +
						'</select>' +
					'</div>';

				var presetMasks = [ '(999) 999-9999', '99/99/9999', '9999-9999-9999-9999', '99:99', '999.999.999-99', '' ];
				if ( selected.field.mask_pattern && presetMasks.indexOf( selected.field.mask_pattern ) === -1 ) {
					optionsMarkup +=
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-mask-custom">' + escapeHtml( shapeformsLiteBuilder.labels.customMask || 'Custom Mask' ) + '</label>' +
							'<input type="text" id="shapeforms-setting-mask-custom" value="' + escapeHtml( selected.field.mask_pattern ) + '" placeholder="999-AAA-***">' +
							'<p>9 = digit, A = letter, * = any</p>' +
						'</div>';
				}
			}

			// Numeric settings.
			if ( selected.field.type === 'numeric' || selected.field.type === 'slider_range' ) {
				optionsMarkup +=
					'<div class="shapeforms-setting-group">' +
						'<label for="shapeforms-setting-min-value">' + escapeHtml( shapeformsLiteBuilder.labels.minValue || 'Min Value' ) + '</label>' +
						'<input type="number" id="shapeforms-setting-min-value" value="' + escapeHtml( selected.field.min_value ) + '" placeholder="0">' +
					'</div>' +
					'<div class="shapeforms-setting-group">' +
						'<label for="shapeforms-setting-max-value">' + escapeHtml( shapeformsLiteBuilder.labels.maxValue || 'Max Value' ) + '</label>' +
						'<input type="number" id="shapeforms-setting-max-value" value="' + escapeHtml( selected.field.max_value ) + '" placeholder="100">' +
					'</div>' +
					'<div class="shapeforms-setting-group">' +
						'<label for="shapeforms-setting-step-value">' + escapeHtml( shapeformsLiteBuilder.labels.stepValue || 'Step' ) + '</label>' +
						'<input type="number" id="shapeforms-setting-step-value" value="' + escapeHtml( selected.field.step_value ) + '" placeholder="1">' +
					'</div>';
			}

			if ( selected.field.type === 'slider_range' ) {
				optionsMarkup +=
					'<div class="shapeforms-switch-item">' +
						'<label class="shapeforms-switch__row">' +
							'<span class="shapeforms-switch__text">' + escapeHtml( shapeformsLiteBuilder.labels.dualHandle || 'Dual range (min–max)' ) + '</span>' +
							'<input type="checkbox" id="shapeforms-setting-dual-handle"' + ( selected.field.dual_handle ? ' checked' : '' ) + '>' +
							'<span class="shapeforms-switch__track"><span class="shapeforms-switch__thumb"></span></span>' +
						'</label>' +
					'</div>' +
					'<div class="shapeforms-setting-row">' +
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-slider-color">' + escapeHtml( shapeformsLiteBuilder.labels.sliderColor || 'Track Color' ) + '</label>' +
							'<div class="bf-color-reset-wrap">' +
								'<input type="color" id="shapeforms-setting-slider-color" value="' + escapeHtml( selected.field.slider_color || state.formSettings.button_background_color || '#0f766e' ) + '">' +
								'<button type="button" class="bf-color-reset" data-color-reset="slider_color" title="' + escapeHtml( shapeformsLiteBuilder.labels.resetColor || 'Reset to theme color' ) + '" aria-label="' + escapeHtml( shapeformsLiteBuilder.labels.resetColor || 'Reset to theme color' ) + '"><span class="dashicons dashicons-image-rotate"></span></button>' +
							'</div>' +
						'</div>' +
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-slider-height">' + escapeHtml( shapeformsLiteBuilder.labels.sliderHeight || 'Track Height (px)' ) + '</label>' +
							'<input type="number" id="shapeforms-setting-slider-height" value="' + escapeHtml( selected.field.slider_height || '8' ) + '" min="2" max="20" placeholder="8">' +
						'</div>' +
					'</div>';
			}

			// Star rating settings.
			if ( selected.field.type === 'star_rating' ) {
				optionsMarkup +=
					'<div class="shapeforms-setting-row">' +
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-max-stars">' + escapeHtml( shapeformsLiteBuilder.labels.maxStars || 'Number of Stars' ) + '</label>' +
							'<input type="number" id="shapeforms-setting-max-stars" value="' + escapeHtml( selected.field.max_stars || 5 ) + '" min="1" max="10" placeholder="5">' +
						'</div>' +
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-star-size">' + escapeHtml( shapeformsLiteBuilder.labels.starSizeField || 'Icon Size (px)' ) + '</label>' +
							'<input type="number" id="shapeforms-setting-star-size" value="' + escapeHtml( selected.field.star_size || '20' ) + '" min="12" max="60" placeholder="20">' +
						'</div>' +
					'</div>' +
					'<div class="shapeforms-setting-row">' +
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-star-inactive-color" class="shapeforms-setting-sublabel">' + escapeHtml( shapeformsLiteBuilder.labels.starColorField || 'Star Color' ) + '</label>' +
							'<div class="bf-color-reset-wrap">' +
								'<input type="color" id="shapeforms-setting-star-inactive-color" value="' + escapeHtml( selected.field.star_inactive_color || '#cbd5e1' ) + '">' +
								'<button type="button" class="bf-color-reset" data-color-reset="star_inactive_color" title="' + escapeHtml( shapeformsLiteBuilder.labels.resetColor || 'Reset to default' ) + '" aria-label="' + escapeHtml( shapeformsLiteBuilder.labels.resetColor || 'Reset to default' ) + '"><span class="dashicons dashicons-image-rotate"></span></button>' +
							'</div>' +
						'</div>' +
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-star-color" class="shapeforms-setting-sublabel">' + escapeHtml( shapeformsLiteBuilder.labels.starActiveColorField || 'Active Color' ) + '</label>' +
							'<div class="bf-color-reset-wrap">' +
								'<input type="color" id="shapeforms-setting-star-color" value="' + escapeHtml( selected.field.star_color || state.formSettings.button_background_color || '#f59e0b' ) + '">' +
								'<button type="button" class="bf-color-reset" data-color-reset="star_color" title="' + escapeHtml( shapeformsLiteBuilder.labels.resetColor || 'Reset to default' ) + '" aria-label="' + escapeHtml( shapeformsLiteBuilder.labels.resetColor || 'Reset to default' ) + '"><span class="dashicons dashicons-image-rotate"></span></button>' +
							'</div>' +
						'</div>' +
					'</div>';
			}

			// Paragraph content.
			if ( selected.field.type === 'paragraph' ) {
				optionsMarkup +=
					'<div class="shapeforms-setting-group">' +
						'<label for="shapeforms-setting-content">' + escapeHtml( shapeformsLiteBuilder.labels.paragraphText || 'Paragraph Text' ) + '</label>' +
						'<textarea id="shapeforms-setting-content" rows="4">' + escapeHtml( selected.field.content || '' ) + '</textarea>' +
					'</div>';
			}

			// HTML editor rich text.
			if ( selected.field.type === 'html_editor' ) {
				optionsMarkup +=
					'<div class="shapeforms-setting-group">' +
						'<label>' + escapeHtml( shapeformsLiteBuilder.labels.defaultContent || 'Content' ) + '</label>' +
						'<div class="shapeforms-richtext">' +
							'<div class="shapeforms-richtext__toolbar">' +
								'<button type="button" data-cmd="bold" title="Bold"><b>B</b></button>' +
								'<button type="button" data-cmd="italic" title="Italic"><i>I</i></button>' +
								'<button type="button" data-cmd="underline" title="Underline"><u>U</u></button>' +
								'<span class="shapeforms-richtext__sep"></span>' +
								'<button type="button" data-cmd="justifyLeft" title="Align Left"><span class="dashicons dashicons-editor-alignleft"></span></button>' +
								'<button type="button" data-cmd="justifyCenter" title="Center"><span class="dashicons dashicons-editor-aligncenter"></span></button>' +
								'<button type="button" data-cmd="justifyRight" title="Align Right"><span class="dashicons dashicons-editor-alignright"></span></button>' +
								'<span class="shapeforms-richtext__sep"></span>' +
								'<button type="button" data-cmd="insertUnorderedList" title="Bullet List"><span class="dashicons dashicons-editor-ul"></span></button>' +
								'<button type="button" data-cmd="insertOrderedList" title="Numbered List"><span class="dashicons dashicons-editor-ol"></span></button>' +
							'</div>' +
							// Intentional raw-HTML sink: seeds the contenteditable editor with the
							// admin-authored rich text. It is re-sanitized server-side via
							// wp_kses_post() on save, so it is injected unescaped to keep formatting.
							'<div class="shapeforms-richtext__editor" id="shapeforms-richtext-editor" contenteditable="true">' + ( selected.field.content || '' ) + '</div>' +
						'</div>' +
					'</div>';
			}

			// Build conditional logic setup.
			var condFields = getAllFields().filter( function ( f ) { return f.id !== selected.field.id && specialFieldTypes.indexOf( f.type ) === -1; } );
			var cond = selected.field.conditional || {};
			// Ensure multi-condition structure (no separate actions — always show/hide this field).
			if ( ! cond.conditions ) {
				cond = {
					enabled:    !! cond.enabled,
					action:     cond.action || 'show',
					logic:      'AND',
					conditions: [ { field_id: cond.field_id || '', operator: cond.operator || 'is', value: cond.value || '' } ],
				};
				selected.field.conditional = cond;
			}

			function buildCondFieldOptions( selectedId ) {
				var opts = '<option value="">' + escapeHtml( shapeformsLiteBuilder.labels.selectField || '— select field —' ) + '</option>';
				condFields.forEach( function ( f ) {
					opts += '<option value="' + escapeHtml( f.id ) + '"' + ( selectedId === f.id ? ' selected' : '' ) + '>' + escapeHtml( f.label || getLibraryItem( f.type ).label ) + '</option>';
				} );
				return opts;
			}

			var allowsValue = function ( op ) { return op !== 'not_empty' && op !== 'empty'; };

			var condLogicLabel = ( cond.logic || 'AND' ) === 'OR' ? 'OR' : 'AND';
			var conditionsHtml = '';
			( cond.conditions || [] ).forEach( function ( c, ci ) {
				conditionsHtml +=
					'<div class="bfcl-condition-row" data-ci="' + ci + '">' +
						'<span class="bfcl-cond-connector">' + ( ci === 0 ? 'IF' : condLogicLabel ) + '</span>' +
						'<select class="bfcl-cond-field" data-ci="' + ci + '">' + buildCondFieldOptions( c.field_id ) + '</select>' +
						'<select class="bfcl-cond-op" data-ci="' + ci + '">' +
							'<option value="is"' + ( c.operator === 'is' ? ' selected' : '' ) + '>' + escapeHtml( shapeformsLiteBuilder.labels.is || 'is' ) + '</option>' +
							'<option value="is_not"' + ( c.operator === 'is_not' ? ' selected' : '' ) + '>' + escapeHtml( shapeformsLiteBuilder.labels.isNot || 'is not' ) + '</option>' +
							'<option value="contains"' + ( c.operator === 'contains' ? ' selected' : '' ) + '>' + escapeHtml( shapeformsLiteBuilder.labels.contains || 'contains' ) + '</option>' +
							'<option value="not_contains"' + ( c.operator === 'not_contains' ? ' selected' : '' ) + '>' + escapeHtml( shapeformsLiteBuilder.labels.notContains || 'not contains' ) + '</option>' +
							'<option value="starts_with"' + ( c.operator === 'starts_with' ? ' selected' : '' ) + '>' + escapeHtml( shapeformsLiteBuilder.labels.startsWith || 'starts with' ) + '</option>' +
							'<option value="ends_with"' + ( c.operator === 'ends_with' ? ' selected' : '' ) + '>' + escapeHtml( shapeformsLiteBuilder.labels.endsWith || 'ends with' ) + '</option>' +
							'<option value="greater_than"' + ( c.operator === 'greater_than' ? ' selected' : '' ) + '>' + escapeHtml( shapeformsLiteBuilder.labels.greaterThan || '>' ) + '</option>' +
							'<option value="less_than"' + ( c.operator === 'less_than' ? ' selected' : '' ) + '>' + escapeHtml( shapeformsLiteBuilder.labels.lessThan || '<' ) + '</option>' +
							'<option value="not_empty"' + ( c.operator === 'not_empty' ? ' selected' : '' ) + '>' + escapeHtml( shapeformsLiteBuilder.labels.notEmpty || 'is not empty' ) + '</option>' +
							'<option value="empty"' + ( c.operator === 'empty' ? ' selected' : '' ) + '>' + escapeHtml( shapeformsLiteBuilder.labels.isEmpty || 'is empty' ) + '</option>' +
						'</select>' +
						( allowsValue( c.operator ) ? '<input type="text" class="bfcl-cond-value" data-ci="' + ci + '" value="' + escapeHtml( c.value ) + '" placeholder="' + escapeHtml( shapeformsLiteBuilder.labels.value || 'Value' ) + '">' : '<span class="bfcl-no-value"></span>' ) +
						( ci > 0 ? '<button type="button" class="bfcl-remove-cond" data-ci="' + ci + '" title="Remove">&#215;</button>' : '<span class="bfcl-remove-placeholder"></span>' ) +
					'</div>';
			} );

			$empty.hide();
			var activeAccordion = state.activeSettingsAccordion || 'settings';

			// Screen only exists in conversational mode. Left selected after the
			// mode is switched off, every accordion in the panel would render
			// closed and the field's settings would look like they had vanished.
			if ( 'screen' === activeAccordion && ! cvIsOn() ) {
				activeAccordion = 'settings';
			}

			$panel.removeAttr( 'hidden' ).html(
				'<div class="shapeforms-setting-group">' +
					'<label>' + escapeHtml( shapeformsLiteBuilder.labels.selectedField ) + '</label>' +
					'<div class="shapeforms-setting-field-name">' + escapeHtml( selected.field.label || getLibraryItem( selected.field.type ).label ) + '</div>' +
				'</div>' +

				// --- Screen Accordion (conversational mode only) ---
				// First, because on a conversational form the screen is the thing
				// the author came to style. Empty string on an ordinary form.
				screenSettings( selected ) +

				// --- Settings Accordion ---
				'<div class="shapeforms-field-accordion' + ( activeAccordion === 'settings' ? ' is-open' : '' ) + '" data-accordion="settings">' +
					'<button type="button" class="shapeforms-field-accordion__head">' + escapeHtml( shapeformsLiteBuilder.labels.settings || 'Settings' ) + ' <span class="dashicons dashicons-arrow-down-alt2"></span></button>' +
					'<div class="shapeforms-field-accordion__body">' +
					( 'page_break' === selected.field.type ?
						// A Page Break is a structural divider between steps, not an input --
						// it has no visible label, placeholder or default value of its own.
						// Its only real settings are the step's title and how the multi-step
						// progress indicator looks (a form-wide choice, set from here since
						// this is the field users think of as "the step").
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-step-title">Step Title</label>' +
							'<input type="text" id="shapeforms-setting-step-title" value="' + escapeHtml( selected.field.step_title || '' ) + '" placeholder="' + escapeHtml( selected.field.label || 'Page Break' ) + '">' +
							'<p class="shapeforms-setting-desc">Shown in the multi-step progress indicator for the step that follows.</p>' +
						'</div>' +
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-page-break-progress-style">Progress Style</label>' +
							'<select id="shapeforms-page-break-progress-style">' +
								'<option value="bar"' + ( 'bar' === ( state.formSettings.step_progress_style || 'bar' ) ? ' selected' : '' ) + '>Progress Bar</option>' +
								'<option value="steps"' + ( 'steps' === state.formSettings.step_progress_style ? ' selected' : '' ) + '>Numbers</option>' +
								'<option value="headings"' + ( 'headings' === state.formSettings.step_progress_style ? ' selected' : '' ) + '>Headings</option>' +
							'</select>' +
						'</div>' +
						'<div class="shapeforms-setting-row">' +
							'<div class="shapeforms-setting-group">' +
								'<label for="shapeforms-page-break-progress-color">Progress Color</label>' +
								'<div class="shapeforms-color-field">' +
									'<div class="shapeforms-color-swatch" style="background:' + escapeHtml( state.formSettings.step_progress_color || '#2f80ed' ) + '">' +
										'<input type="color" id="shapeforms-page-break-progress-color" value="' + escapeHtml( state.formSettings.step_progress_color || '#2f80ed' ) + '">' +
									'</div>' +
									'<input type="text" class="shapeforms-color-hex" maxlength="7" value="' + escapeHtml( state.formSettings.step_progress_color || '#2f80ed' ) + '" data-color-for="shapeforms-page-break-progress-color" spellcheck="false">' +
								'</div>' +
							'</div>' +
							'<div class="shapeforms-setting-group">' +
								'<label for="shapeforms-page-break-progress-bg-color">Progress Background</label>' +
								'<div class="shapeforms-color-field">' +
									'<div class="shapeforms-color-swatch" style="background:' + escapeHtml( state.formSettings.step_progress_bg_color || '#e5e7eb' ) + '">' +
										'<input type="color" id="shapeforms-page-break-progress-bg-color" value="' + escapeHtml( state.formSettings.step_progress_bg_color || '#e5e7eb' ) + '">' +
									'</div>' +
									'<input type="text" class="shapeforms-color-hex" maxlength="7" value="' + escapeHtml( state.formSettings.step_progress_bg_color || '#e5e7eb' ) + '" data-color-for="shapeforms-page-break-progress-bg-color" spellcheck="false">' +
								'</div>' +
							'</div>' +
						'</div>' +
						'<p class="shapeforms-setting-desc">Progress Color highlights the active/completed step; Progress Background is the unfilled track behind it. Both apply to Progress Bar, Numbers and Headings alike.</p>' +
						'<div class="shapeforms-setting-group">' +
							'<p class="shapeforms-setting-desc" style="margin:8px 0 0;font-weight:600;color:#374151">Step Navigation Buttons</p>' +
							'<p class="shapeforms-setting-desc">The Next/Previous buttons render for every style above — these settings are not tied to the Progress Style choice.</p>' +
						'</div>' +
						'<div class="shapeforms-setting-row">' +
							'<div class="shapeforms-setting-group">' +
								'<label for="shapeforms-page-break-btn-color">Nav Button Color</label>' +
								'<div class="shapeforms-color-field">' +
									'<div class="shapeforms-color-swatch" style="background:' + escapeHtml( state.formSettings.step_btn_color || '#2f80ed' ) + '">' +
										'<input type="color" id="shapeforms-page-break-btn-color" value="' + escapeHtml( state.formSettings.step_btn_color || '#2f80ed' ) + '">' +
									'</div>' +
									'<input type="text" class="shapeforms-color-hex" maxlength="7" value="' + escapeHtml( state.formSettings.step_btn_color || '#2f80ed' ) + '" data-color-for="shapeforms-page-break-btn-color" spellcheck="false">' +
								'</div>' +
							'</div>' +
							'<div class="shapeforms-setting-group">' +
								'<label for="shapeforms-page-break-btn-text-color">Nav Button Text Color</label>' +
								'<div class="shapeforms-color-field">' +
									'<div class="shapeforms-color-swatch" style="background:' + escapeHtml( state.formSettings.step_btn_text_color || '#ffffff' ) + '">' +
										'<input type="color" id="shapeforms-page-break-btn-text-color" value="' + escapeHtml( state.formSettings.step_btn_text_color || '#ffffff' ) + '">' +
									'</div>' +
									'<input type="text" class="shapeforms-color-hex" maxlength="7" value="' + escapeHtml( state.formSettings.step_btn_text_color || '#ffffff' ) + '" data-color-for="shapeforms-page-break-btn-text-color" spellcheck="false">' +
								'</div>' +
							'</div>' +
						'</div>' +
						'<div class="shapeforms-setting-row">' +
							'<div class="shapeforms-setting-group">' +
								'<label for="shapeforms-page-break-btn-size">Nav Button Size</label>' +
								'<select id="shapeforms-page-break-btn-size">' +
									'<option value="small"' + ( 'small' === state.formSettings.step_btn_size ? ' selected' : '' ) + '>Small</option>' +
									'<option value="medium"' + ( 'medium' === ( state.formSettings.step_btn_size || 'medium' ) ? ' selected' : '' ) + '>Medium</option>' +
									'<option value="large"' + ( 'large' === state.formSettings.step_btn_size ? ' selected' : '' ) + '>Large</option>' +
								'</select>' +
							'</div>' +
							'<div class="shapeforms-setting-group">' +
								'<label for="shapeforms-page-break-btn-radius">Nav Button Radius (px)</label>' +
								'<input type="number" id="shapeforms-page-break-btn-radius" min="0" max="50" value="' + escapeHtml( '' === ( state.formSettings.step_btn_radius || '' ) ? '' : String( state.formSettings.step_btn_radius ) ) + '" placeholder="6">' +
							'</div>' +
						'</div>' +
						'<div class="shapeforms-setting-row">' +
							'<div class="shapeforms-setting-group">' +
								'<label for="shapeforms-page-break-next-text">Next Button Text</label>' +
								'<input type="text" id="shapeforms-page-break-next-text" value="' + escapeHtml( state.formSettings.step_next_text || '' ) + '" placeholder="Next">' +
							'</div>' +
							'<div class="shapeforms-setting-group">' +
								'<label for="shapeforms-page-break-prev-text">Previous Button Text</label>' +
								'<input type="text" id="shapeforms-page-break-prev-text" value="' + escapeHtml( state.formSettings.step_prev_text || '' ) + '" placeholder="Previous">' +
							'</div>' +
						'</div>'
					:
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-label">' + escapeHtml( shapeformsLiteBuilder.labels.label ) + '</label>' +
							'<input type="text" id="shapeforms-setting-label" value="' + escapeHtml( selected.field.label ) + '">' +
						'</div>' +
						'<div class="shapeforms-setting-group">' +
							'<label>' + escapeHtml( shapeformsLiteBuilder.labels.labelPlacement || 'Label Placement' ) + '</label>' +
							'<div class="shapeforms-btn-group" id="shapeforms-setting-label-placement">' +
								'<button type="button" class="shapeforms-btn-group__btn' + ( 'top' === ( selected.field.label_placement || 'top' ) ? ' is-active' : '' ) + '" data-value="top">' + escapeHtml( shapeformsLiteBuilder.labels.top || 'Top' ) + '</button>' +
								'<button type="button" class="shapeforms-btn-group__btn' + ( 'left' === selected.field.label_placement ? ' is-active' : '' ) + '" data-value="left">' + escapeHtml( shapeformsLiteBuilder.labels.left || 'Left' ) + '</button>' +
								'<button type="button" class="shapeforms-btn-group__btn' + ( 'right' === selected.field.label_placement ? ' is-active' : '' ) + '" data-value="right">' + escapeHtml( shapeformsLiteBuilder.labels.right || 'Right' ) + '</button>' +
								'<button type="button" class="shapeforms-btn-group__btn' + ( 'bottom' === selected.field.label_placement ? ' is-active' : '' ) + '" data-value="bottom">' + escapeHtml( shapeformsLiteBuilder.labels.below || 'Below' ) + '</button>' +
								'<button type="button" class="shapeforms-btn-group__btn' + ( 'hidden' === selected.field.label_placement ? ' is-active' : '' ) + '" data-value="hidden">' + escapeHtml( shapeformsLiteBuilder.labels.hidden || 'Hide' ) + '</button>' +
							'</div>' +
						'</div>'
					) +
					( specialFieldTypes.indexOf( selected.field.type ) !== -1 || 'star_rating' === selected.field.type ? '' :
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-placeholder">' + escapeHtml( shapeformsLiteBuilder.labels.placeholder ) + '</label>' +
							'<input type="text" id="shapeforms-setting-placeholder" value="' + escapeHtml( selected.field.placeholder ) + '">' +
						'</div>' +
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-default">' + escapeHtml( shapeformsLiteBuilder.labels.defaultValue ) + '</label>' +
							'<input type="text" id="shapeforms-setting-default" value="' + escapeHtml( selected.field.default_value ) + '">' +
						'</div>'
					) +
					optionsMarkup +
					( 'section_break' === selected.field.type ?
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-description">' + escapeHtml( shapeformsLiteBuilder.labels.sectionDescription || 'Description' ) + '</label>' +
							'<textarea id="shapeforms-setting-description" rows="4">' + escapeHtml( selected.field.description || '' ) + '</textarea>' +
						'</div>' : ''
					) +
					( 'terms_conditions' === selected.field.type ?
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-content">' + escapeHtml( shapeformsLiteBuilder.labels.termsContent || 'Terms text' ) + '</label>' +
							'<textarea id="shapeforms-setting-content" rows="5">' + escapeHtml( selected.field.content || '' ) + '</textarea>' +
						'</div>' : ''
					) +
					( 'captcha' === selected.field.type ?
						'<p class="shapeforms-canvas-field-note">' + escapeHtml( shapeformsLiteBuilder.labels.captchaNotice || 'This field will use the captcha provider selected in global settings.' ) + '</p>' : ''
					) +
					( 'product' === selected.field.type ? ( function () {
						var prodOpts = Array.isArray( selected.field.product_options ) ? selected.field.product_options : [];
						var rowsHtml = '';
						prodOpts.forEach( function ( opt, idx ) {
							rowsHtml +=
								'<div class="shapeforms-product-option-row" data-product-index="' + idx + '">' +
									'<span class="shapeforms-product-option__drag"><span class="dashicons dashicons-menu"></span></span>' +
									'<input type="text" class="shapeforms-product-option__label" data-product-index="' + idx + '" value="' + escapeHtml( opt.label || '' ) + '" placeholder="Option label">' +
									'<input type="number" class="shapeforms-product-option__price" data-product-index="' + idx + '" value="' + escapeHtml( opt.price || '0.00' ) + '" min="0" step="0.01" placeholder="0.00" style="width:80px;">' +
									'<button type="button" class="shapeforms-product-option__remove" data-product-index="' + idx + '" title="Remove"><span class="dashicons dashicons-no-alt"></span></button>' +
								'</div>';
						} );
						return '<div class="shapeforms-setting-group">' +
							'<label>Product Options</label>' +
							'<div class="shapeforms-product-options-repeater" id="shapeforms-product-options-repeater">' +
								rowsHtml +
							'</div>' +
							'<button type="button" class="shapeforms-options-repeater__add" id="shapeforms-product-option-add"><span class="dashicons dashicons-plus-alt2"></span> Add Option</button>' +
						'</div>' +
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-product-style">Display Style</label>' +
							'<select id="shapeforms-setting-product-style">' +
								'<option value="radio"' + ( 'radio' === ( selected.field.product_style || 'radio' ) ? ' selected' : '' ) + '>Radio buttons</option>' +
								'<option value="select"' + ( 'select' === selected.field.product_style ? ' selected' : '' ) + '>Dropdown</option>' +
							'</select>' +
						'</div>';
					}() ) : '' ) +
					( 'quantity' === selected.field.type ? ( function () {
						var allFields = getAllFields();
						var productFields = allFields.filter( function ( f ) { return f.type === 'product'; } );
						var productOpts = '<option value="">— none —</option>';
						productFields.forEach( function ( f ) {
							productOpts += '<option value="' + escapeHtml( f.id ) + '"' + ( selected.field.linked_product === f.id ? ' selected' : '' ) + '>' + escapeHtml( f.label || 'Product' ) + '</option>';
						} );
						return '<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-qty-linked-product">Linked Product Field</label>' +
							'<select id="shapeforms-setting-qty-linked-product">' + productOpts + '</select>' +
						'</div>' +
						'<div class="shapeforms-setting-row">' +
							'<div class="shapeforms-setting-group">' +
								'<label for="shapeforms-setting-qty-min">Min Qty</label>' +
								'<input type="number" id="shapeforms-setting-qty-min" value="' + escapeHtml( selected.field.qty_min || '1' ) + '" min="0" placeholder="1">' +
							'</div>' +
							'<div class="shapeforms-setting-group">' +
								'<label for="shapeforms-setting-qty-max">Max Qty</label>' +
								'<input type="number" id="shapeforms-setting-qty-max" value="' + escapeHtml( selected.field.qty_max || '' ) + '" min="1" placeholder="unlimited">' +
							'</div>' +
						'</div>' +
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-qty-default">Default Qty</label>' +
							'<input type="number" id="shapeforms-setting-qty-default" value="' + escapeHtml( selected.field.qty_default || '1' ) + '" min="1" placeholder="1">' +
						'</div>';
					}() ) : '' ) +
					( 'custom_amount' === selected.field.type ? ( function () {
						// The generic Placeholder control is suppressed for every type in
						// specialFieldTypes, so an amount field needs its own. Reusing the
						// same element id keeps the existing live-typing and save wiring —
						// no extra plumbing, and no chance of the two drifting apart.
						var caLimits = hasAmountLimits( selected.field );
						var caSymbol = getPaymentCurrencySymbol();

						var markup =
							'<div class="shapeforms-setting-group">' +
								'<label for="shapeforms-setting-placeholder">' + escapeHtml( shapeformsLiteBuilder.labels.placeholder || 'Placeholder' ) + '</label>' +
								'<input type="text" id="shapeforms-setting-placeholder" value="' + escapeHtml( selected.field.placeholder || '' ) + '" placeholder="Enter amount">' +
							'</div>' +
							'<div class="shapeforms-switch-item">' +
								'<label class="shapeforms-switch__row">' +
									'<span class="shapeforms-switch__text">Set Amount Limits</span>' +
									'<input type="checkbox" id="shapeforms-setting-amount-limits"' + ( caLimits ? ' checked' : '' ) + '>' +
									'<span class="shapeforms-switch__track"><span class="shapeforms-switch__thumb"></span></span>' +
								'</label>' +
							'</div>';

						// Min/Max stay hidden until the switch is on, so a pay-what-you-want
						// field shows nothing it does not use.
						if ( caLimits ) {
							markup +=
								'<div class="shapeforms-setting-row">' +
									'<div class="shapeforms-setting-group">' +
										'<label for="shapeforms-setting-amount-min">Minimum Amount (' + escapeHtml( caSymbol ) + ')</label>' +
										'<input type="number" id="shapeforms-setting-amount-min" value="' + escapeHtml( selected.field.amount_min || '' ) + '" min="0" step="0.01" placeholder="no minimum">' +
									'</div>' +
									'<div class="shapeforms-setting-group">' +
										'<label for="shapeforms-setting-amount-max">Maximum Amount (' + escapeHtml( caSymbol ) + ')</label>' +
										'<input type="number" id="shapeforms-setting-amount-max" value="' + escapeHtml( selected.field.amount_max || '' ) + '" min="0" step="0.01" placeholder="no maximum">' +
									'</div>' +
								'</div>';
						}

						markup +=
							'<div class="shapeforms-setting-group">' +
								'<label for="shapeforms-setting-amount-default">Default Amount (' + escapeHtml( caSymbol ) + ')</label>' +
								'<input type="number" id="shapeforms-setting-amount-default" value="' + escapeHtml( selected.field.amount_default || '' ) + '" min="0" step="0.01" placeholder="leave empty">' +
								'<p class="shapeforms-setting-desc">Leave empty so visitors enter their own amount.</p>' +
							'</div>';

						return markup;
					}() ) : '' ) +
					( 'order_summary' === selected.field.type ?
						''
					: '' ) +
					( 'calculation' === selected.field.type ? ( function () {
						var calcField   = selected.field;
						var allFields   = getAllFields();
						var refOpts     = '';
						// Only fields that resolve to a single numeric value can be
						// referenced in a formula. Structural/text/multi-value fields
						// are excluded so the picker stays short and meaningful.
						var calcRefTypes = [ 'number', 'numeric', 'quantity', 'slider_range', 'star_rating', 'nps', 'calculation' ];
						allFields.forEach( function ( f ) {
							if ( ! f.id ) return;
							if ( calcRefTypes.indexOf( f.type ) === -1 ) return;
							if ( f.id === calcField.id ) return; // never self-reference
							// Show the label; expose the {token} as a hover tooltip
							// (a native <select> option cannot render a muted inline hint).
							refOpts += '<option value="' + escapeHtml( f.id ) + '" title="{' + escapeHtml( f.id ) + '}">' + escapeHtml( f.label || f.id ) + '</option>';
						} );
						var formula   = calcField.calc_formula  || '';
						var decimals  = typeof calcField.calc_decimals === 'number' ? calcField.calc_decimals : 2;
						var prefix    = calcField.calc_prefix   || '';
						var suffix    = calcField.calc_suffix   || '';
						var valClass  = formula ? ( /[^0-9\s\+\-\*\/\(\)\.\{\}a-z0-9_]/i.test(formula) ? 'bfcp-invalid' : 'bfcp-valid' ) : '';
						return '<div class="bfcp-panel">' +
							'<div class="bfcp-row">' +
								'<label class="bfcp-label">Formula</label>' +
								'<div class="bfcp-formula-wrap">' +
									'<div class="bfcp-formula-toolbar">' +
										'<select class="bfcp-field-insert">' +
											'<option value="">Insert Field&hellip;</option>' +
											refOpts +
										'</select>' +
									'</div>' +
									'<div class="bfcp-formula-input ' + valClass + '" id="shapeforms-calc-formula" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="' + escapeHtml( 'e.g. {Price} * {Quantity}' ) + '">' + bfCalcFormulaToChips( formula ) + '</div>' +
									'<div class="bfcp-formula-help">Pick a field from the dropdown to insert it, then type operators between them. Supports +, -, *, /, ( ).</div>' +
								'</div>' +
							'</div>' +
							'<div class="bfcp-row bfcp-row--inline">' +
								'<div class="bfcp-col">' +
									'<label class="bfcp-label">Decimals</label>' +
									'<input type="number" class="bfcp-decimals" id="shapeforms-calc-decimals" min="0" max="10" value="' + escapeHtml( String(decimals) ) + '">' +
								'</div>' +
								'<div class="bfcp-col">' +
									'<label class="bfcp-label">Prefix</label>' +
									'<input type="text" class="bfcp-prefix" id="shapeforms-calc-prefix" value="' + escapeHtml( prefix ) + '" placeholder="$">' +
								'</div>' +
								'<div class="bfcp-col">' +
									'<label class="bfcp-label">Suffix</label>' +
									'<input type="text" class="bfcp-suffix" id="shapeforms-calc-suffix" value="' + escapeHtml( suffix ) + '" placeholder=" USD">' +
								'</div>' +
							'</div>' +
						'</div>';
					}() ) : '' ) +

					// --- Signature field settings ---
					( 'signature' === selected.field.type ?
						'<div class="shapeforms-setting-row">' +
							'<div class="shapeforms-setting-group">' +
								'<label for="shapeforms-setting-sig-pen-color">Pen Color</label>' +
								'<div class="shapeforms-color-field">' +
									'<div class="shapeforms-color-swatch" style="background:' + escapeHtml( selected.field.sig_pen_color || '#1e293b' ) + '">' +
										'<input type="color" id="shapeforms-setting-sig-pen-color" value="' + escapeHtml( selected.field.sig_pen_color || '#1e293b' ) + '">' +
									'</div>' +
									'<input type="text" class="shapeforms-color-hex" maxlength="7" value="' + escapeHtml( selected.field.sig_pen_color || '#1e293b' ) + '" data-color-for="shapeforms-setting-sig-pen-color" spellcheck="false">' +
								'</div>' +
							'</div>' +
							'<div class="shapeforms-setting-group">' +
								'<label for="shapeforms-setting-sig-pen-width">Pen Width (px)</label>' +
								'<input type="number" id="shapeforms-setting-sig-pen-width" min="1" max="8" value="' + escapeHtml( String( selected.field.sig_pen_width || 2 ) ) + '">' +
							'</div>' +
						'</div>' +
						'<div class="shapeforms-setting-row">' +
							'<div class="shapeforms-setting-group">' +
								'<label for="shapeforms-setting-sig-bg-color">Background Color</label>' +
								'<div class="shapeforms-color-field">' +
									'<div class="shapeforms-color-swatch" style="background:' + escapeHtml( selected.field.sig_bg_color || '#ffffff' ) + '">' +
										'<input type="color" id="shapeforms-setting-sig-bg-color" value="' + escapeHtml( selected.field.sig_bg_color || '#ffffff' ) + '">' +
									'</div>' +
									'<input type="text" class="shapeforms-color-hex" maxlength="7" value="' + escapeHtml( selected.field.sig_bg_color || '#ffffff' ) + '" data-color-for="shapeforms-setting-sig-bg-color" spellcheck="false">' +
								'</div>' +
							'</div>' +
							'<div class="shapeforms-setting-group">' +
								'<label for="shapeforms-setting-sig-height">Height (px)</label>' +
								'<input type="number" id="shapeforms-setting-sig-height" min="80" max="400" value="' + escapeHtml( String( selected.field.sig_height || 160 ) ) + '">' +
							'</div>' +
						'</div>'
					: '' ) +

					// --- Hidden field settings ---
					( 'hidden_field' === selected.field.type ?
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-hidden-source">Value Source</label>' +
							'<select id="shapeforms-setting-hidden-source">' +
								'<option value="static"'     + ( 'static'     === ( selected.field.hidden_source || 'static' ) ? ' selected' : '' ) + '>Static value</option>' +
								'<option value="url_param"'  + ( 'url_param'  === selected.field.hidden_source ? ' selected' : '' ) + '>URL parameter</option>' +
								'<option value="user_id"'    + ( 'user_id'    === selected.field.hidden_source ? ' selected' : '' ) + '>User ID</option>' +
								'<option value="user_email"' + ( 'user_email' === selected.field.hidden_source ? ' selected' : '' ) + '>User email</option>' +
								'<option value="user_login"' + ( 'user_login' === selected.field.hidden_source ? ' selected' : '' ) + '>User login</option>' +
								'<option value="post_id"'    + ( 'post_id'    === selected.field.hidden_source ? ' selected' : '' ) + '>Post ID</option>' +
								'<option value="referrer"'   + ( 'referrer'   === selected.field.hidden_source ? ' selected' : '' ) + '>Referrer URL</option>' +
							'</select>' +
						'</div>' +
						( ( selected.field.hidden_source || 'static' ) === 'static' || ( selected.field.hidden_source || 'static' ) === 'url_param' ?
							'<div class="shapeforms-setting-group">' +
								'<label for="shapeforms-setting-hidden-value">' + ( 'url_param' === selected.field.hidden_source ? 'URL Parameter Name' : 'Value' ) + '</label>' +
								'<input type="text" id="shapeforms-setting-hidden-value" value="' + escapeHtml( selected.field.hidden_value || '' ) + '" placeholder="' + ( 'url_param' === selected.field.hidden_source ? 'e.g. utm_source' : 'Enter value' ) + '">' +
							'</div>'
						: '<p class="description" style="color:#6b7280;font-size:12px;margin:4px 0 0">Value resolved automatically at render time.</p>' ) +
						'<p class="description" style="color:#9ca3af;font-size:12px;margin:8px 0 0"><span class="dashicons dashicons-hidden" style="font-size:13px;"></span> This field is invisible to visitors.</p>'
					: '' ) +

					// --- Image Choice settings ---
					( 'image_choice' === selected.field.type ? ( function () {
						var icOpts = [];
						if ( selected.field.image_choice_options ) {
							try {
								icOpts = typeof selected.field.image_choice_options === 'string'
									? JSON.parse( selected.field.image_choice_options )
									: ( Array.isArray( selected.field.image_choice_options ) ? selected.field.image_choice_options : [] );
							} catch (e) { icOpts = []; }
						}
						var icRowsHtml = '';
						icOpts.forEach( function ( opt, idx ) {
							var thumb = opt.image_url
								? '<img src="' + escapeHtml( opt.image_url ) + '" alt="">'
								: '<span class="dashicons dashicons-format-image"></span>';
							icRowsHtml +=
								'<div class="shapeforms-ic-option-row" data-ic-index="' + idx + '">' +
									'<button type="button" class="shapeforms-ic-option__img-btn" data-ic-index="' + idx + '" title="Choose image">' +
										thumb +
									'</button>' +
									'<div class="shapeforms-ic-option__fields">' +
										'<input type="text" class="shapeforms-ic-option__label" data-ic-index="' + idx + '" value="' + escapeHtml( opt.label || '' ) + '" placeholder="Label">' +
										'<input type="text" class="shapeforms-ic-option__value" data-ic-index="' + idx + '" value="' + escapeHtml( opt.value || '' ) + '" placeholder="Value (submitted)">' +
									'</div>' +
									'<button type="button" class="shapeforms-ic-option__remove" data-ic-index="' + idx + '" title="Remove"><span class="dashicons dashicons-no-alt"></span></button>' +
								'</div>';
						} );
						return '<div class="shapeforms-setting-group">' +
							'<label>Choices</label>' +
							'<div class="shapeforms-ic-options-repeater" id="shapeforms-ic-options-repeater">' + icRowsHtml + '</div>' +
							'<button type="button" class="shapeforms-options-repeater__add" id="shapeforms-ic-option-add"><span class="dashicons dashicons-plus-alt2"></span> Add Choice</button>' +
						'</div>' +
						'<div class="shapeforms-setting-row">' +
							'<div class="shapeforms-setting-group">' +
								'<label for="shapeforms-setting-ic-type">Selection</label>' +
								'<select id="shapeforms-setting-ic-type">' +
									'<option value="radio"'    + ( 'checkbox' !== ( selected.field.image_choice_type || 'radio' ) ? ' selected' : '' ) + '>Single (radio)</option>' +
									'<option value="checkbox"' + ( 'checkbox' === selected.field.image_choice_type ? ' selected' : '' ) + '>Multiple (checkbox)</option>' +
								'</select>' +
							'</div>' +
							'<div class="shapeforms-setting-group">' +
								'<label for="shapeforms-setting-ic-columns">Columns</label>' +
								'<select id="shapeforms-setting-ic-columns">' +
									[ 2, 3, 4, 5, 6 ].map( function (n) {
										return '<option value="' + n + '"' + ( n === ( selected.field.image_choice_columns || 3 ) ? ' selected' : '' ) + '>' + n + '</option>';
									} ).join('') +
								'</select>' +
							'</div>' +
						'</div>' +
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-ic-img-height">Image Height (px)</label>' +
							'<input type="number" id="shapeforms-setting-ic-img-height" min="60" max="600" step="10" value="' + escapeHtml( String( selected.field.image_choice_img_height || 160 ) ) + '">' +
						'</div>';
					}() ) : '' ) +

					// --- Repeater field settings ---
					( 'repeater' === selected.field.type ? ( function () {
						var repGroups = repeaterGroups( selected.field );
						var atCap     = repeaterFieldCount( repGroups ) >= repeaterMaxSubFields();

						// One editable block per layout group: its name, its column count,
						// and the sub-fields inside it. Fields drag between groups; groups
						// drag among themselves. A group is layout only — its name and id
						// never reach an input name or a saved entry.
						var groupsHtml = '';

						repGroups.forEach( function ( grp, gi ) {
							var rowsHtml = '';

							grp.fields.forEach( function ( sf, idx ) {
								var sfType    = sf.type || 'text';
								var isChoice  = ( 'select' === sfType || 'radio' === sfType || 'checkbox' === sfType );
								rowsHtml +=
									'<div class="shapeforms-rep-field-row" data-rep-group="' + gi + '" data-rep-index="' + idx + '" data-rep-id="' + escapeHtml( sf.id || '' ) + '">' +
										'<div class="shapeforms-rep-field-row__main">' +
											// draggable="true" is what starts a native drag; the handle
											// carried a grab cursor but no drag, so nothing moved. Every
											// other handle in the builder sets it the same way.
											'<span class="shapeforms-rep-field__drag dashicons dashicons-menu" draggable="true" title="' + escapeHtml( 'Drag to reorder or move between groups' ) + '"></span>' +
											// Named with the SAME label the field palette uses for that
											// type — "Textarea", "Select", "Date Picker" — instead of the
											// raw slug. getLibraryItem() is already localised, so this
											// introduces no new strings to translate and cannot drift from
											// what the palette calls the field the sub-field will become.
											'<select class="shapeforms-rep-field__type" aria-label="' + escapeHtml( 'Sub-field type' ) + '">' +
												[ 'text','email','url','tel','number','date','time','textarea','select','radio','checkbox' ].map( function (t) {
													return '<option value="' + t + '"' + ( t === sfType ? ' selected' : '' ) + '>' + escapeHtml( getLibraryItem( t ).label || t ) + '</option>';
												} ).join('') +
											'</select>' +
											'<input type="text" class="shapeforms-rep-field__label" value="' + escapeHtml( sf.label || '' ) + '" placeholder="Label">' +
											'<button type="button" class="shapeforms-rep-field__remove" title="Remove"><span class="dashicons dashicons-no-alt"></span></button>' +
										'</div>' +
										// Placeholder, Full width and Required. The first two keys have
										// always been in the sub-field schema and rendered on the front
										// end; there was simply no control to set them.
										'<div class="shapeforms-rep-field-row__meta">' +
											// Choice types have no placeholder to set, so the row simply
											// starts with the flags. An empty spacer in its place left a
											// gap that stranded the first flag on a line of its own.
											( 'radio' === sfType || 'checkbox' === sfType ? '' :
												'<input type="text" class="shapeforms-rep-field__placeholder" value="' + escapeHtml( sf.placeholder || '' ) + '" placeholder="' + escapeHtml( advLabel( 'placeholder' ) || 'Placeholder' ) + '">'
											) +
											// The three flags are one group on one line, so they read as a
											// set and land in the same place whatever the sub-field type.
											'<div class="shapeforms-rep-field__flags">' +
												'<label class="shapeforms-rep-field__flag">' +
													'<input type="checkbox" class="shapeforms-rep-field__full"' + ( sf.full_width ? ' checked' : '' ) + '> ' +
													escapeHtml( 'Full width' ) +
												'</label>' +
												'<label class="shapeforms-rep-field__flag">' +
													'<input type="checkbox" class="shapeforms-rep-field__required"' + ( sf.required ? ' checked' : '' ) + '> ' +
													escapeHtml( advLabel( 'required' ) || 'Required' ) +
												'</label>' +
												// A sub-field has no Label Placement control, so this is its
												// equivalent of "Hide". The label stays in the DOM for screen
												// readers; only its line disappears.
												'<label class="shapeforms-rep-field__flag">' +
													'<input type="checkbox" class="shapeforms-rep-field__hidelabel"' + ( sf.hide_label ? ' checked' : '' ) + '> ' +
													escapeHtml( shapeformsLiteBuilder.labels.repHideLabel || 'Hide label' ) +
												'</label>' +
											'</div>' +
										'</div>' +
										( isChoice ?
											bfSubOptionsMarkup( sf ) +
											// The same override block a top-level choice field gets. One
											// definition, so a sub-field can never accept a value the
											// field itself rejects.
											bfChoiceStyleMarkup( sf )
										: '' ) +
									'</div>';
							} );

							var colOpts = [ [ 0, 'Inherit (' + ( Number( selected.field.repeater_columns ) || 2 ) + ')' ], [ 1, '1 column' ], [ 2, '2 columns' ], [ 3, '3 columns' ], [ 4, '4 columns' ] ].map( function ( o ) {
								return '<option value="' + o[0] + '"' + ( o[0] === grp.columns ? ' selected' : '' ) + '>' + escapeHtml( o[1] ) + '</option>';
							} ).join( '' );

							groupsHtml +=
								'<div class="shapeforms-rep-group" data-rep-group="' + gi + '">' +
									'<div class="shapeforms-rep-group__head">' +
										'<span class="shapeforms-rep-group__drag dashicons dashicons-menu" draggable="true" title="' + escapeHtml( 'Drag to reorder group' ) + '"></span>' +
										'<input type="text" class="shapeforms-rep-group__label" value="' + escapeHtml( grp.label || '' ) + '" placeholder="' + escapeHtml( 'Group name (optional)' ) + '">' +
										'<select class="shapeforms-rep-group__cols" aria-label="' + escapeHtml( 'Columns in this group' ) + '">' + colOpts + '</select>' +
										( repGroups.length > 1 ? '<button type="button" class="shapeforms-rep-group__remove" title="' + escapeHtml( 'Remove group' ) + '"><span class="dashicons dashicons-trash"></span></button>' : '<span></span>' ) +
									'</div>' +
									// A group can only collapse behind its own name, so the
									// control is offered only once the group has one.
									( grp.label ?
										'<div class="shapeforms-rep-group__opts">' +
											'<label class="shapeforms-rep-field__flag">' +
												'<input type="checkbox" class="shapeforms-rep-group__collapsible"' + ( grp.collapsible ? ' checked' : '' ) + '> ' +
												escapeHtml( 'Collapsible' ) +
											'</label>' +
											( grp.collapsible ?
												'<label class="shapeforms-rep-field__flag">' +
													'<input type="checkbox" class="shapeforms-rep-group__open"' + ( grp.open ? ' checked' : '' ) + '> ' +
													escapeHtml( 'Open by default' ) +
												'</label>'
											: '' ) +
										'</div>'
									: '' ) +
									'<div class="shapeforms-rep-group__fields" data-rep-group="' + gi + '">' + rowsHtml + '</div>' +
									'<button type="button" class="shapeforms-rep-field-add"' + ( atCap ? ' disabled' : '' ) + '><span class="dashicons dashicons-plus-alt2"></span> Add Sub-field</button>' +
								'</div>';
						} );

						return '<div class="shapeforms-setting-group">' +
							'<label>Sub-fields</label>' +
							'<div class="shapeforms-rep-groups" id="shapeforms-rep-groups">' + groupsHtml + '</div>' +
							'<button type="button" class="shapeforms-options-repeater__add" id="shapeforms-rep-group-add"><span class="dashicons dashicons-plus-alt2"></span> Add Group</button>' +
							( atCap ? '<p class="shapeforms-rep-cap-note">' + escapeHtml( 'Sub-field limit reached (' + repeaterMaxSubFields() + ' per repeater).' ) + '</p>' : '' ) +
						'</div>' +
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-rep-columns">Columns</label>' +
							'<select id="shapeforms-setting-rep-columns">' +
								[ 1, 2, 3, 4 ].map( function (n) {
									return '<option value="' + n + '"' + ( n === ( Number( selected.field.repeater_columns ) || 2 ) ? ' selected' : '' ) + '>' + n + '</option>';
								} ).join('') +
							'</select>' +
						'</div>' +
						'<div class="shapeforms-setting-row">' +
							'<div class="shapeforms-setting-group">' +
								'<label for="shapeforms-setting-rep-min">Min rows</label>' +
								'<input type="number" id="shapeforms-setting-rep-min" min="1" max="10" value="' + escapeHtml( String( selected.field.repeater_min_rows || 1 ) ) + '">' +
							'</div>' +
							'<div class="shapeforms-setting-group">' +
								'<label for="shapeforms-setting-rep-max">Max rows</label>' +
								'<input type="number" id="shapeforms-setting-rep-max" min="1" max="20" value="' + escapeHtml( String( selected.field.repeater_max_rows || 5 ) ) + '">' +
							'</div>' +
						'</div>' +
						// Full width, not half: the placeholder explaining the {n} token is
						// longer than a half-width input can show.
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-rep-row-label">Row title</label>' +
							'<input type="text" id="shapeforms-setting-rep-row-label" value="' + escapeHtml( selected.field.repeater_row_label || '' ) + '" placeholder="' + escapeHtml( 'e.g. Product {n} — leave empty for none' ) + '">' +
						'</div>' +
						// The Add button's two settings — its text and where it sits — share a
						// row, so they read as one control's worth of decisions. Remove
						// button text is about a different button, so it gets its own line.
						'<div class="shapeforms-setting-row">' +
							'<div class="shapeforms-setting-group">' +
								'<label for="shapeforms-setting-rep-add-label">Add button text</label>' +
								'<input type="text" id="shapeforms-setting-rep-add-label" value="' + escapeHtml( selected.field.repeater_add_label || 'Add Row' ) + '">' +
							'</div>' +
							// Same segmented control as Label Placement, so the panel keeps
							// one idiom for "pick one of a few".
							'<div class="shapeforms-setting-group">' +
								'<label>' + escapeHtml( shapeformsLiteBuilder.labels.repAddAlign || 'Add button alignment' ) + '</label>' +
								'<div class="shapeforms-btn-group is-icons" id="shapeforms-setting-rep-add-align">' +
								// Icons, not words: a fourth option does not fit beside the Add
								// button text at panel width, and this is the same left/centre/
								// right/full set the block toolbar draws.
								// advLabel(), not labels.*: Left/Center/Right/Full Width already
								// live in the Style-tab label set and are already translated there.
								[ [ 'left', advLabel( 'alignLeft' ), 'align-left' ],
								  [ 'center', advLabel( 'alignCenter' ), 'align-center' ],
								  [ 'right', advLabel( 'alignRight' ), 'align-right' ],
								  [ 'full', advLabel( 'fullWidth' ), 'align-full-width' ] ].map( function ( o ) {
									return '<button type="button" class="shapeforms-btn-group__btn' +
										( o[0] === repAddAlign( selected.field ) ? ' is-active' : '' ) +
										'" data-value="' + o[0] + '" title="' + escapeHtml( o[1] ) + '" aria-label="' + escapeHtml( o[1] ) + '">' +
										'<span class="dashicons dashicons-' + o[2] + '" aria-hidden="true"></span>' +
									'</button>';
								} ).join( '' ) +
								'</div>' +
							'</div>' +
						'</div>' +
						// The Remove button gets the same pair the Add button gets: its text
						// and where it sits, side by side — but only when a row can actually
						// be removed. At max <= min the button never appears, so its two
						// settings would be controls for something that cannot happen.
						( repRemovable( selected.field ) ?
						'<div class="shapeforms-setting-row">' +
							'<div class="shapeforms-setting-group">' +
								'<label for="shapeforms-setting-rep-remove-label">Remove button text</label>' +
								'<input type="text" id="shapeforms-setting-rep-remove-label" value="' + escapeHtml( selected.field.repeater_remove_label || 'Remove' ) + '">' +
							'</div>' +
							'<div class="shapeforms-setting-group">' +
								'<label>' + escapeHtml( shapeformsLiteBuilder.labels.repRemovePos || 'Remove button position' ) + '</label>' +
								'<div class="shapeforms-btn-group is-icons" id="shapeforms-setting-rep-remove-pos">' +
								// 'Auto' is the default and is what every existing repeater does:
								// the button follows the row title. The other two decide outright.
								[ [ 'top', shapeformsLiteBuilder.labels.repRemoveTop || 'Top', 'arrow-up-alt2' ],
								  [ 'bottom', shapeformsLiteBuilder.labels.repRemoveBottom || 'Bottom', 'arrow-down-alt2' ] ].map( function ( o ) {
									return '<button type="button" class="shapeforms-btn-group__btn' +
										( o[0] === repRemoveEffective( selected.field ) ? ' is-active' : '' ) +
										'" data-value="' + o[0] + '" title="' + escapeHtml( o[1] ) + '" aria-label="' + escapeHtml( o[1] ) + '">' +
										'<span class="dashicons dashicons-' + o[2] + '" aria-hidden="true"></span>' +
									'</button>';
								} ).join( '' ) +
								'</div>' +
							'</div>' +
						'</div>'
						: '' );
					}() ) : '' ) +

					// --- Password field settings ---
					( 'password_field' === selected.field.type ?
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-pw-placeholder">Placeholder</label>' +
							'<input type="text" id="shapeforms-setting-pw-placeholder" value="' + escapeHtml( selected.field.placeholder || '' ) + '">' +
						'</div>' +
						'<div class="shapeforms-switch-item">' +
							'<label class="shapeforms-switch__row">' +
								'<span class="shapeforms-switch__text">Add confirm password field</span>' +
								'<input type="checkbox" id="shapeforms-setting-pw-confirm"' + ( selected.field.confirm_password ? ' checked' : '' ) + '>' +
								'<span class="shapeforms-switch__track"><span class="shapeforms-switch__thumb"></span></span>' +
							'</label>' +
						'</div>'
					: '' ) +

					// --- Rich Text settings ---
					( 'rich_text' === selected.field.type ?
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-rte-height">Editor Height (px)</label>' +
							'<input type="number" id="shapeforms-setting-rte-height" min="100" max="800" value="' + escapeHtml( String( selected.field.rte_height || 200 ) ) + '">' +
						'</div>'
					: '' ) +

					// --- Date Range settings ---
					( 'date_range' === selected.field.type ?
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-dr-placeholder">Placeholder</label>' +
							'<input type="text" id="shapeforms-setting-dr-placeholder" value="' + escapeHtml( selected.field.placeholder || '' ) + '">' +
						'</div>' +
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-dr-format">Date Format</label>' +
							'<select id="shapeforms-setting-dr-format">' +
								'<option value="Y-m-d"' + ( ( selected.field.date_range_format || 'Y-m-d' ) === 'Y-m-d' ? ' selected' : '' ) + '>YYYY-MM-DD</option>' +
								'<option value="d/m/Y"' + ( selected.field.date_range_format === 'd/m/Y' ? ' selected' : '' ) + '>DD/MM/YYYY</option>' +
								'<option value="m/d/Y"' + ( selected.field.date_range_format === 'm/d/Y' ? ' selected' : '' ) + '>MM/DD/YYYY</option>' +
								'<option value="d M Y"' + ( selected.field.date_range_format === 'd M Y' ? ' selected' : '' ) + '>DD Mon YYYY</option>' +
							'</select>' +
						'</div>' +
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-dr-separator">Separator</label>' +
							'<input type="text" id="shapeforms-setting-dr-separator" value="' + escapeHtml( typeof selected.field.date_range_separator !== 'undefined' ? selected.field.date_range_separator : ' to ' ) + '">' +
						'</div>' +
						'<div class="shapeforms-setting-row">' +
							'<div class="shapeforms-setting-group">' +
								'<label for="shapeforms-setting-dr-min-days">Min Days</label>' +
								'<input type="number" id="shapeforms-setting-dr-min-days" min="0" placeholder="—" value="' + escapeHtml( String( selected.field.date_range_min_days || '' ) ) + '">' +
							'</div>' +
							'<div class="shapeforms-setting-group">' +
								'<label for="shapeforms-setting-dr-max-days">Max Days</label>' +
								'<input type="number" id="shapeforms-setting-dr-max-days" min="1" placeholder="—" value="' + escapeHtml( String( selected.field.date_range_max_days || '' ) ) + '">' +
							'</div>' +
						'</div>'
					: '' ) +

					// --- NPS settings ---
					( 'nps' === selected.field.type ?
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-nps-low">Low end label</label>' +
							'<input type="text" id="shapeforms-setting-nps-low" value="' + escapeHtml( selected.field.nps_low_label || 'Not likely' ) + '">' +
						'</div>' +
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-nps-high">High end label</label>' +
							'<input type="text" id="shapeforms-setting-nps-high" value="' + escapeHtml( selected.field.nps_high_label || 'Extremely likely' ) + '">' +
						'</div>' +
						'<div class="shapeforms-setting-group">' +
							'<label>' + escapeHtml( shapeformsLiteBuilder.labels.npsColors || 'Zone Colors' ) + '</label>' +
							'<div class="shapeforms-setting-row shapeforms-setting-row--3">' +
								'<div class="shapeforms-setting-group">' +
									'<label for="shapeforms-setting-nps-detractor-color" class="shapeforms-setting-sublabel">' + escapeHtml( shapeformsLiteBuilder.labels.npsDetractor || 'Detractors (0–6)' ) + '</label>' +
									'<div class="bf-color-reset-wrap">' +
										'<input type="color" id="shapeforms-setting-nps-detractor-color" value="' + escapeHtml( selected.field.nps_detractor_color || '#ef4444' ) + '">' +
										'<button type="button" class="bf-color-reset" data-color-reset="nps_detractor_color" title="' + escapeHtml( shapeformsLiteBuilder.labels.resetColor || 'Reset to default' ) + '" aria-label="' + escapeHtml( shapeformsLiteBuilder.labels.resetColor || 'Reset to default' ) + '"><span class="dashicons dashicons-image-rotate"></span></button>' +
									'</div>' +
								'</div>' +
								'<div class="shapeforms-setting-group">' +
									'<label for="shapeforms-setting-nps-passive-color" class="shapeforms-setting-sublabel">' + escapeHtml( shapeformsLiteBuilder.labels.npsPassive || 'Passives (7–8)' ) + '</label>' +
									'<div class="bf-color-reset-wrap">' +
										'<input type="color" id="shapeforms-setting-nps-passive-color" value="' + escapeHtml( selected.field.nps_passive_color || '#f59e0b' ) + '">' +
										'<button type="button" class="bf-color-reset" data-color-reset="nps_passive_color" title="' + escapeHtml( shapeformsLiteBuilder.labels.resetColor || 'Reset to default' ) + '" aria-label="' + escapeHtml( shapeformsLiteBuilder.labels.resetColor || 'Reset to default' ) + '"><span class="dashicons dashicons-image-rotate"></span></button>' +
									'</div>' +
								'</div>' +
								'<div class="shapeforms-setting-group">' +
									'<label for="shapeforms-setting-nps-promoter-color" class="shapeforms-setting-sublabel">' + escapeHtml( shapeformsLiteBuilder.labels.npsPromoter || 'Promoters (9–10)' ) + '</label>' +
									'<div class="bf-color-reset-wrap">' +
										'<input type="color" id="shapeforms-setting-nps-promoter-color" value="' + escapeHtml( selected.field.nps_promoter_color || '#22c55e' ) + '">' +
										'<button type="button" class="bf-color-reset" data-color-reset="nps_promoter_color" title="' + escapeHtml( shapeformsLiteBuilder.labels.resetColor || 'Reset to default' ) + '" aria-label="' + escapeHtml( shapeformsLiteBuilder.labels.resetColor || 'Reset to default' ) + '"><span class="dashicons dashicons-image-rotate"></span></button>' +
									'</div>' +
								'</div>' +
							'</div>' +
						'</div>'
					: '' ) +

					// --- Matrix settings ---
					( 'matrix' === selected.field.type ? ( function () {
						var mRows = [];
						var mCols = [];
						// Hydrate the textareas from the saved values. Empty/invalid data falls
						// back to the same defaults the front-end renderer (Pro render_matrix)
						// substitutes, so the builder shows the effective values a visitor sees
						// instead of blank boxes — and a save then persists them (self-heals
						// forms saved with empty matrix_rows/matrix_columns).
						try { mRows = JSON.parse( selected.field.matrix_rows || '[]' ); } catch(e) { mRows = []; }
						try { mCols = JSON.parse( selected.field.matrix_columns || '[]' ); } catch(e) { mCols = []; }
						if ( ! mRows.length ) { mRows = [ 'Row 1', 'Row 2', 'Row 3' ]; }
						if ( ! mCols.length ) { mCols = [ 'Agree', 'Neutral', 'Disagree' ]; }
						return '<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-matrix-type">Input Type</label>' +
							'<select id="shapeforms-setting-matrix-type">' +
								'<option value="radio"' + ( ( selected.field.matrix_type || 'radio' ) === 'radio' ? ' selected' : '' ) + '>Radio (one per row)</option>' +
								'<option value="checkbox"' + ( selected.field.matrix_type === 'checkbox' ? ' selected' : '' ) + '>Checkbox (multiple per row)</option>' +
							'</select>' +
						'</div>' +
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-matrix-rows">Rows <small style="color:#9ca3af">(one per line)</small></label>' +
							'<textarea id="shapeforms-setting-matrix-rows" rows="4">' + escapeHtml( mRows.join( '\n' ) ) + '</textarea>' +
						'</div>' +
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-matrix-cols">Columns <small style="color:#9ca3af">(one per line)</small></label>' +
							'<textarea id="shapeforms-setting-matrix-cols" rows="3">' + escapeHtml( mCols.join( '\n' ) ) + '</textarea>' +
						'</div>';
					}() ) : '' ) +

					// --- Lookup settings ---
					( 'lookup' === selected.field.type ? ( function () {
						var lItems = [];
						try { lItems = JSON.parse( selected.field.lookup_items || '[]' ); } catch(e) { lItems = []; }
						return '<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-lookup-placeholder">Placeholder</label>' +
							'<input type="text" id="shapeforms-setting-lookup-placeholder" value="' + escapeHtml( selected.field.placeholder || '' ) + '">' +
						'</div>' +
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-lookup-items">Options <small style="color:#9ca3af">(one per line)</small></label>' +
							'<textarea id="shapeforms-setting-lookup-items" rows="5" placeholder="Option 1&#10;Option 2&#10;Option 3">' + escapeHtml( lItems.join( '\n' ) ) + '</textarea>' +
						'</div>' +
						'<div class="shapeforms-setting-row">' +
							'<div class="shapeforms-setting-group">' +
								'<label for="shapeforms-setting-lookup-min-chars">Min chars to search</label>' +
								'<input type="number" id="shapeforms-setting-lookup-min-chars" min="1" max="5" value="' + escapeHtml( String( selected.field.lookup_min_chars || 2 ) ) + '">' +
							'</div>' +
							'<div class="shapeforms-setting-group">' +
								'<label for="shapeforms-setting-lookup-max-results">Max results</label>' +
								'<input type="number" id="shapeforms-setting-lookup-max-results" min="3" max="20" value="' + escapeHtml( String( selected.field.lookup_max_results || 8 ) ) + '">' +
							'</div>' +
						'</div>' +
						'<div class="shapeforms-switch-item">' +
							'<label class="shapeforms-switch__row">' +
								'<span class="shapeforms-switch__text">Allow custom typed values</span>' +
								'<input type="checkbox" id="shapeforms-setting-lookup-allow-custom"' + ( selected.field.lookup_allow_custom ? ' checked' : '' ) + '>' +
								'<span class="shapeforms-switch__track"><span class="shapeforms-switch__thumb"></span></span>' +
							'</label>' +
						'</div>';
					}() ) : '' ) +

					// --- Geolocation settings ---
					( 'geolocation' === selected.field.type ?
						'<div class="shapeforms-switch-item">' +
							'<label class="shapeforms-switch__row">' +
								'<span class="shapeforms-switch__text">Show map preview</span>' +
								'<input type="checkbox" id="shapeforms-setting-geo-show-map"' + ( selected.field.geo_show_map ? ' checked' : '' ) + '>' +
								'<span class="shapeforms-switch__track"><span class="shapeforms-switch__thumb"></span></span>' +
							'</label>' +
						'</div>' +
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-geo-map-height">Map Height (px)</label>' +
							'<input type="number" id="shapeforms-setting-geo-map-height" min="150" max="600" value="' + escapeHtml( String( selected.field.geo_map_height || 250 ) ) + '">' +
						'</div>' +
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-geo-store-format">Store format</label>' +
							'<select id="shapeforms-setting-geo-store-format">' +
								'<option value="both"' + ( ( selected.field.geo_store_format || 'both' ) === 'both' ? ' selected' : '' ) + '>Lat/Lng + Address</option>' +
								'<option value="latlng"' + ( selected.field.geo_store_format === 'latlng' ? ' selected' : '' ) + '>Lat/Lng only</option>' +
								'<option value="address"' + ( selected.field.geo_store_format === 'address' ? ' selected' : '' ) + '>Address only</option>' +
							'</select>' +
						'</div>'
					: '' ) +

					( 'file' === selected.field.type ?
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-allowed-types">' + escapeHtml( shapeformsLiteBuilder.labels.allowedTypes || 'Allowed file types' ) + '</label>' +
							'<input type="text" id="shapeforms-setting-allowed-types" value="' + escapeHtml( selected.field.allowed_types || '' ) + '" placeholder=".jpg,.png,.pdf,.doc">' +
						'</div>' +
						( shapeformsLiteBuilder.proFileSize
							? '<div class="shapeforms-setting-group">' +
								'<label for="shapeforms-setting-max-file-size">' + escapeHtml( shapeformsLiteBuilder.labels.maxFileSize || 'Max file size (MB)' ) + '</label>' +
								'<input type="number" id="shapeforms-setting-max-file-size" value="' + escapeHtml( selected.field.max_file_size || '' ) + '" min="1" max="100" placeholder="2">' +
							'</div>'
							: '<p class="description" style="color:#9ca3af;font-size:12px;margin:0">' + escapeHtml( shapeformsLiteBuilder.labels.maxFileSizeNote || 'Max upload size: 2 MB' ) + '</p>'
						) : ''
					) +
				'</div></div>' +

				// --- Advanced Accordion ---
				'<div class="shapeforms-field-accordion' + ( activeAccordion === 'advanced' ? ' is-open' : '' ) + '" data-accordion="advanced">' +
					'<button type="button" class="shapeforms-field-accordion__head">' + escapeHtml( shapeformsLiteBuilder.labels.advanced || 'Advanced' ) + ' <span class="dashicons dashicons-arrow-down-alt2"></span></button>' +
					'<div class="shapeforms-field-accordion__body">' +
					( 'page_break' === selected.field.type ? '' :
						'<div class="shapeforms-switch-item">' +
							'<label class="shapeforms-switch__row">' +
								'<span class="shapeforms-switch__text">' + escapeHtml( shapeformsLiteBuilder.labels.required ) + '</span>' +
								'<input type="checkbox" id="shapeforms-setting-required"' + ( selected.field.required ? ' checked' : '' ) + '>' +
								'<span class="shapeforms-switch__track"><span class="shapeforms-switch__thumb"></span></span>' +
							'</label>' +
						'</div>' +
						( selected.field.required ?
							'<div class="shapeforms-setting-group">' +
								'<label for="shapeforms-setting-custom-error">' + escapeHtml( shapeformsLiteBuilder.labels.customError || 'Custom error message' ) + '</label>' +
								'<input type="text" id="shapeforms-setting-custom-error" value="' + escapeHtml( selected.field.custom_error || '' ) + '" placeholder="' + escapeHtml( ( selected.field.label || 'This field' ) + ' is required.' ) + '">' +
							'</div>' : ''
						) +
						'<div class="shapeforms-setting-group">' +
							'<label for="shapeforms-setting-auto-populate-key">' + escapeHtml( shapeformsLiteBuilder.labels.autoPopulateKey || 'Auto Populate Key' ) + '</label>' +
							'<input type="text" id="shapeforms-setting-auto-populate-key" value="' + escapeHtml( selected.field.auto_populate_key || '' ) + '" placeholder="e.g. name, email, user_email">' +
							'<p class="shapeforms-setting-desc">' + escapeHtml( shapeformsLiteBuilder.labels.autoPopulateDesc || 'Pre-fill from URL parameter (?key=value) or logged-in user data.' ) + '</p>' +
						'</div>'
					) +
					'<div class="shapeforms-setting-group">' +
						'<label for="shapeforms-setting-css-class">' + escapeHtml( shapeformsLiteBuilder.labels.cssClass || 'CSS Class' ) + '</label>' +
						'<input type="text" id="shapeforms-setting-css-class" value="' + escapeHtml( selected.field.css_class || '' ) + '" placeholder="my-custom-class">' +
					'</div>' +

					// Conditional Logic
					'<div class="shapeforms-switch-item">' +
						'<label class="shapeforms-switch__row">' +
							'<span class="shapeforms-switch__text">' + escapeHtml( shapeformsLiteBuilder.labels.conditionalLogic || 'Conditional Logic' ) + '</span>' +
							'<input type="checkbox" id="shapeforms-setting-cond-enabled"' + ( cond.enabled ? ' checked' : '' ) + '>' +
							'<span class="shapeforms-switch__track"><span class="shapeforms-switch__thumb"></span></span>' +
						'</label>' +
					'</div>' +
					( cond.enabled ?
						'<div class="bfcl-builder">' +
							'<div class="bfcl-action-bar">' +
								'<select class="bfcl-action-select">' +
									'<option value="show"' + ( 'show' === ( cond.action || 'show' ) ? ' selected' : '' ) + '>' + escapeHtml( shapeformsLiteBuilder.labels.show || 'Show' ) + '</option>' +
									'<option value="hide"' + ( 'hide' === cond.action ? ' selected' : '' ) + '>' + escapeHtml( shapeformsLiteBuilder.labels.hide || 'Hide' ) + '</option>' +
								'</select>' +
								'<span class="bfcl-action-label">this field if</span>' +
								'<div class="bfcl-logic-toggle">' +
									'<label class="bfcl-logic-opt' + ( 'AND' === ( cond.logic || 'AND' ) ? ' is-active' : '' ) + '">' +
										'<input type="radio" name="bfcl-logic" value="AND"' + ( 'AND' === ( cond.logic || 'AND' ) ? ' checked' : '' ) + '> All' +
									'</label>' +
									'<label class="bfcl-logic-opt' + ( 'OR' === cond.logic ? ' is-active' : '' ) + '">' +
										'<input type="radio" name="bfcl-logic" value="OR"' + ( 'OR' === cond.logic ? ' checked' : '' ) + '> Any' +
									'</label>' +
								'</div>' +
								'<span class="bfcl-action-label">of the following match:</span>' +
							'</div>' +
							'<div class="bfcl-conditions-list">' + conditionsHtml + '</div>' +
							'<button type="button" class="bfcl-add-condition">+ Add Condition</button>' +
						'</div>' : ''
					) +
				'</div></div>'
			);

			setupOptionsSortable();
			setupAddressSortable();
			// Every path that re-renders the panel comes through here, so the repeater's
			// group and field sortables are wired in one place rather than at each of
			// the caller sites.
			setupRepeaterSortables();
		}

		// Remembers which settings tab is active across re-renders.
		var activeSettingsTab = 'confirmation';

		// ── Upgrade teasers ──────────────────────────────────────────────────────
		// The free plugin advertises paid features without implementing any of them:
		// a locked button that opens an upgrade dialog explaining what the feature
		// does. Nothing behind these buttons half-works -- Lite has no tag engine and
		// no custom email templates, so there is deliberately no picker to browse and
		// no toggle to flip.
		//
		// None of this detects an add-on. It renders whenever shapeforms_show_upgrade_cta
		// is true, exactly like the Tools -> Entries export teaser; an add-on shipping
		// the real feature turns that filter off and fills the slot itself.

		/**
		 * Whether the upgrade teasers should render.
		 *
		 * wp_localize_script stringifies the PHP boolean, so this is '1' or '' rather
		 * than true/false -- hence the truthiness check rather than a strict compare.
		 *
		 * @return {boolean} True while the upgrade CTAs are shown.
		 */
		function showUpgradeCta() {
			return !! shapeformsLiteBuilder.showUpgradeCta;
		}

		/**
		 * Whether the template library should advertise its locked rows.
		 *
		 * Deliberately separate from showUpgradeCta(): an add-on can be installed —
		 * which switches every shared CTA off — while still not entitled to the real
		 * templates. Without its own switch those rows would simply disappear, taking
		 * four whole categories with them and explaining nothing.
		 *
		 * @return {boolean}
		 */
		function showLockedTemplates() {
			return !! shapeformsLiteBuilder.showLockedTemplates;
		}

		/**
		 * A locked button that opens an upgrade dialog.
		 *
		 * aria-haspopup="dialog": it opens the dialog named by modalId, not a menu.
		 *
		 * @param {string} label   Button text.
		 * @param {string} modalId Id of the dialog it opens.
		 * @return {string} Markup.
		 */
		function upgradeButton( label, modalId ) {
			return '<button type="button" class="shapeforms-upgrade-btn" aria-haspopup="dialog"' +
				' data-upgrade-modal="' + escapeHtml( modalId ) + '">' +
				'<span class="dashicons dashicons-lock" aria-hidden="true"></span>' +
				escapeHtml( label ) +
			'</button>';
		}

		/**
		 * The lock hint shown beneath a teased control.
		 *
		 * @param {string} text Hint.
		 * @return {string} Markup.
		 */
		function upgradeHint( text ) {
			// No padlock here: the button above the hint already carries one, and a
			// second lock on the explanatory line reads as a control being withheld
			// rather than as a note about a separate add-on.
			return '<p class="shapeforms-upgrade-hint">' +
				escapeHtml( text ) +
			'</p>';
		}

		/**
		 * Teaser for the thank-you message shortcodes, sat beside the message label.
		 *
		 * @return {string} Markup, or '' when the CTAs are off.
		 */
		function shortcodeTeaser() {
			if ( ! showUpgradeCta() ) {
				return '';
			}

			return upgradeButton(
				shapeformsLiteBuilder.labels.addShortcodes || 'Add Shortcodes',
				'shapeforms-shortcode-upgrade-modal'
			);
		}

		/**
		 * The shortcode hint under the thank-you editor.
		 *
		 * @return {string} Markup, or '' when the CTAs are off.
		 */
		function shortcodeHint() {
			if ( ! showUpgradeCta() ) {
				return '';
			}

			return upgradeHint( shapeformsLiteBuilder.labels.shortcodeHint || '' );
		}

		/**
		 * Teaser for the custom email editor, rendered into an email block's add-on
		 * slot. Matches where the real per-email controls appear, so the block does
		 * not change shape when the paid feature replaces it.
		 *
		 * @return {string} Markup, or '' when the CTAs are off.
		 */
		function emailTeaser() {
			if ( ! showUpgradeCta() ) {
				return '';
			}

			return '<div class="shapeforms-email-teaser">' +
				upgradeButton(
					shapeformsLiteBuilder.labels.customizeEmail || 'Customize this email',
					'shapeforms-email-upgrade-modal'
				) +
				upgradeHint( shapeformsLiteBuilder.labels.emailTeaserHint || '' ) +
			'</div>';
		}

		/**
		 * Teaser for attaching a generated document, rendered into an email
		 * block's attachment slot. Same placement as the real control, so the
		 * block does not change shape when the paid feature replaces it.
		 *
		 * Rendered into both blocks because the real control appears in both:
		 * the two notifications are attached to independently.
		 *
		 * @return {string} Markup, or '' when the CTAs are off.
		 */
		function attachmentTeaser() {
			if ( ! showUpgradeCta() ) {
				return '';
			}

			return '<div class="shapeforms-attachment-teaser">' +
				upgradeButton(
					shapeformsLiteBuilder.labels.attachDocument || 'Attach a PDF of the submission',
					'shapeforms-attachment-upgrade-modal'
				) +
				upgradeHint( shapeformsLiteBuilder.labels.attachmentTeaserHint || '' ) +
			'</div>';
		}

		/**
		 * Teaser for conditional recipient routing, rendered into the admin block's
		 * routing slot — where the real "send to different people" control appears,
		 * so the block does not change shape when the paid feature replaces it.
		 *
		 * @return {string} Markup, or '' when the CTAs are off.
		 */
		function routingTeaser() {
			if ( ! showUpgradeCta() ) {
				return '';
			}

			return '<div class="shapeforms-routing-teaser">' +
				upgradeButton(
					shapeformsLiteBuilder.labels.routeRecipients || 'Send to different people based on the answers',
					'shapeforms-routing-upgrade-modal'
				) +
				upgradeHint( shapeformsLiteBuilder.labels.routingTeaserHint || '' ) +
			'</div>';
		}

		/**
		 * Opens an upgrade dialog by id.
		 *
		 * @param {string} id Dialog id.
		 * @return {void}
		 */
		function openUpgradeModal( id ) {
			// Bail if the dialog isn't on this page. Buttons and their dialogs are
			// gated by the same switch today, but there are now three such switches
			// rather than one, so a future teaser could ship its button without its
			// dialog. Without this guard that locks page scrolling with nothing
			// visible to close.
			var el = document.getElementById( id );

			if ( ! el ) {
				return;
			}

			$( el ).removeAttr( 'hidden' );
			$( 'body' ).addClass( 'shapeforms-upgrade-modal-open' );
		}

		/**
		 * Closes every upgrade dialog.
		 *
		 * @return {void}
		 */
		function closeUpgradeModals() {
			$( '.shapeforms-upgrade-modal' ).attr( 'hidden', 'hidden' );
			$( 'body' ).removeClass( 'shapeforms-upgrade-modal-open' );
		}

		$( document )
			.on( 'click.bfup', '.shapeforms-upgrade-btn', function ( e ) {
				e.preventDefault();
				openUpgradeModal( $( this ).data( 'upgrade-modal' ) );
			} )
			.on( 'click.bfup', '.shapeforms-upgrade-modal [data-shapeforms-upgrade-close]', function () {
				closeUpgradeModals();
			} )
			.on( 'keydown.bfup', function ( e ) {
				if ( 'Escape' === e.key ) {
					closeUpgradeModals();
				}
			} );

		// ── Thank-you message editor ─────────────────────────────────────────────
		// The message is rich markup, so it is edited in the WordPress editor rather
		// than a plain textarea. renderFormSettings() replaces the whole settings
		// panel's HTML, so the editor has to be torn down before each render and stood
		// back up after -- see removeThankYouEditor()/initThankYouEditor().

		var THANK_YOU_EDITOR_ID = 'shapeforms-thank-you-message';

		/**
		 * Whether the WordPress editor API is available.
		 *
		 * @return {boolean} True when wp.editor can be used.
		 */
		function hasEditorApi() {
			return !! ( window.wp && window.wp.editor && window.wp.editor.initialize );
		}

		/**
		 * Tears down the thank-you editor.
		 *
		 * Safe to call when no instance exists, which is the common case: the panel is
		 * re-rendered on changes that have nothing to do with this field.
		 *
		 * @return {void}
		 */
		function removeThankYouEditor() {
			if ( ! hasEditorApi() ) {
				return;
			}

			try {
				window.wp.editor.remove( THANK_YOU_EDITOR_ID );
			} catch ( e ) {
				// No instance to remove; nothing to do.
			}
		}

		/**
		 * Stands the thank-you editor up and wires it back to formSettings.
		 *
		 * Only the AJAX submit mode renders the field, so this is a no-op in the
		 * redirect modes.
		 *
		 * @return {void}
		 */
		function initThankYouEditor() {
			if ( ! hasEditorApi() || ! document.getElementById( THANK_YOU_EDITOR_ID ) ) {
				return;
			}

			// An instance can survive in TinyMCE's registry after its DOM is gone. Left
			// registered, initialize() would bind to the corpse and the message would
			// silently stop syncing.
			if ( window.tinymce && window.tinymce.get( THANK_YOU_EDITOR_ID ) ) {
				window.tinymce.remove( '#' + THANK_YOU_EDITOR_ID );
			}

			window.wp.editor.initialize( THANK_YOU_EDITOR_ID, {
				mediaButtons: false,
				quicktags:    true,
				tinymce:      {
					wpautop:  true,
					height:   200,
					toolbar1: 'formatselect,bold,italic,underline,bullist,numlist,alignleft,aligncenter,alignright,link,unlink,forecolor,removeformat,undo,redo',
					toolbar2: '',
					setup:    function ( editor ) {
						// Mirror every keystroke: TinyMCE edits an iframe, so the
						// textarea's own input/change events never fire and the
						// delegated settings collector would never see the message.
						editor.on( 'change keyup SetContent undo redo', syncThankYouMessage );
					}
				}
			} );

			$( '#' + THANK_YOU_EDITOR_ID + '-html' ).text( shapeformsLiteBuilder.labels.editorCode || 'Code' );
			$( '#' + THANK_YOU_EDITOR_ID + '-tmce' ).text( shapeformsLiteBuilder.labels.editorVisual || 'Visual' );
		}

		/**
		 * Reads the thank-you message out of whichever editor mode is active and stores it.
		 *
		 * wp.editor.getContent() flushes TinyMCE into the textarea first when Visual is
		 * active, so this is correct in either mode.
		 *
		 * @return {void}
		 */
		function syncThankYouMessage() {
			if ( ! document.getElementById( THANK_YOU_EDITOR_ID ) ) {
				return;
			}

			state.formSettings.thank_you_message = hasEditorApi()
				? window.wp.editor.getContent( THANK_YOU_EDITOR_ID )
				: ( $( '#' + THANK_YOU_EDITOR_ID ).val() || '' );
		}

		function renderFormSettings() {
			var submitMode = 'ajax';
			if ( 'redirect' === state.formSettings.submission_type ) {
				submitMode = 'custom' === state.formSettings.redirect_type ? 'custom_url' : 'page';
			}
			var useCustomAdminEmail = 'custom' === state.formSettings.admin_email_type;
			var pages = shapeformsLiteBuilder.pages || [];

			// ── Confirmation pane ────────────────────────────────────────────────
			var confirmationPane =
				'<div class="bfs-stab-pane-head">' +
					'<h3>' + escapeHtml( shapeformsLiteBuilder.labels.submitBehavior ) + '</h3>' +
					'<p>' + escapeHtml( shapeformsLiteBuilder.labels.submitBehaviorDesc || 'Choose what happens after a visitor submits this form.' ) + '</p>' +
				'</div>' +
				'<div class="shapeforms-choice-grid shapeforms-choice-grid--3">' +
					'<label class="shapeforms-choice-card' + ( 'ajax' === submitMode ? ' is-selected' : '' ) + '">' +
						'<input type="radio" name="shapeforms-submit-mode" value="ajax"' + ( 'ajax' === submitMode ? ' checked' : '' ) + '>' +
						'<span class="shapeforms-choice-card__title">' + escapeHtml( shapeformsLiteBuilder.labels.ajaxSubmit ) + '</span>' +
						'<span class="shapeforms-choice-card__description">' + escapeHtml( shapeformsLiteBuilder.labels.ajaxSubmitDesc || 'Show a success message without reloading.' ) + '</span>' +
					'</label>' +
					'<label class="shapeforms-choice-card' + ( 'page' === submitMode ? ' is-selected' : '' ) + '">' +
						'<input type="radio" name="shapeforms-submit-mode" value="page"' + ( 'page' === submitMode ? ' checked' : '' ) + '>' +
						'<span class="shapeforms-choice-card__title">' + escapeHtml( shapeformsLiteBuilder.labels.toAPage || 'To a Page' ) + '</span>' +
						'<span class="shapeforms-choice-card__description">' + escapeHtml( shapeformsLiteBuilder.labels.toAPageDesc || 'Redirect to an existing page.' ) + '</span>' +
					'</label>' +
					'<label class="shapeforms-choice-card' + ( 'custom_url' === submitMode ? ' is-selected' : '' ) + '">' +
						'<input type="radio" name="shapeforms-submit-mode" value="custom_url"' + ( 'custom_url' === submitMode ? ' checked' : '' ) + '>' +
						'<span class="shapeforms-choice-card__title">' + escapeHtml( shapeformsLiteBuilder.labels.customUrl || 'Custom URL' ) + '</span>' +
						'<span class="shapeforms-choice-card__description">' + escapeHtml( shapeformsLiteBuilder.labels.customUrlDesc || 'Redirect to any URL you specify.' ) + '</span>' +
					'</label>' +
				'</div>' +
				( 'ajax' === submitMode
					? '<div class="shapeforms-setting-group bfs-stab-field">' +
						'<div class="shapeforms-ty-head">' +
							'<label for="shapeforms-thank-you-message">' + escapeHtml( shapeformsLiteBuilder.labels.thankYouMessage ) + '</label>' +
							// Always rendered, even when empty: an add-on shipping real
							// shortcodes fills this slot on shapeforms:form_settings_rendered.
							'<div class="shapeforms-shortcode-slot" data-shortcode-slot="thank_you">' +
								shortcodeTeaser() +
							'</div>' +
						'</div>' +
						// Only the bare textarea: wp.editor.initialize() builds the editor
						// wrap, the Visual/Code tabs and the quicktags toolbar around it.
						'<div class="shapeforms-rich-editor">' +
							'<textarea id="shapeforms-thank-you-message" rows="6">' + escapeHtml( state.formSettings.thank_you_message ) + '</textarea>' +
						'</div>' +
						shortcodeHint() +
					'</div>'
					: ''
				) +
				( 'page' === submitMode
					? '<div class="shapeforms-setting-group bfs-stab-field">' +
						'<label>' + escapeHtml( shapeformsLiteBuilder.labels.toAPage || 'Redirect to Page' ) + '</label>' +
						'<select id="shapeforms-redirect-url"><option value="">' + escapeHtml( '— Select a page —' ) + '</option>' +
						(function () {
							var opts = '';
							pages.forEach( function ( p ) {
								opts += '<option value="' + escapeHtml( p.url ) + '"' + ( state.formSettings.redirect_url === p.url ? ' selected' : '' ) + '>' + escapeHtml( p.title ) + '</option>';
							} );
							return opts;
						}()) +
						'</select>' +
					'</div>'
					: ''
				) +
				( 'custom_url' === submitMode
					? '<div class="shapeforms-setting-group bfs-stab-field">' +
						'<label>' + escapeHtml( shapeformsLiteBuilder.labels.customUrl || 'Custom URL' ) + '</label>' +
						'<input type="url" id="shapeforms-redirect-custom-url" value="' + escapeHtml( state.formSettings.redirect_url || '' ) + '" placeholder="https://example.com/thank-you">' +
					'</div>'
					: ''
				);

			// ── Email Notification pane ──────────────────────────────────────────
			var emailPane =
				'<div class="bfs-stab-pane-head">' +
					'<h3>' + escapeHtml( shapeformsLiteBuilder.labels.adminNotifications ) + '</h3>' +
					'<p>' + escapeHtml( shapeformsLiteBuilder.labels.adminNotificationsDesc || 'Send an email to yourself or a custom address every time this form is submitted.' ) + '</p>' +
				'</div>' +
				'<div class="bfsп-email-block">' +
					'<div class="bfsп-email-block__head">' +
						'<span class="bfsп-email-block__title">' + escapeHtml( shapeformsLiteBuilder.labels.adminNotifications ) + '</span>' +
						'<label class="shapeforms-switch">' +
							'<input type="checkbox" id="shapeforms-enable-admin-email"' + ( state.formSettings.enable_admin_email ? ' checked' : '' ) + '>' +
							'<span class="shapeforms-switch__slider"></span>' +
						'</label>' +
					'</div>' +
					( state.formSettings.enable_admin_email
						? '<div class="bfsп-email-block__body">' +
							'<div class="shapeforms-choice-grid">' +
								'<label class="shapeforms-choice-card' + ( ! useCustomAdminEmail ? ' is-selected' : '' ) + '">' +
									'<input type="radio" name="shapeforms-admin-email-type" id="shapeforms-admin-email-type-site-admin" value="site_admin"' + ( ! useCustomAdminEmail ? ' checked' : '' ) + '>' +
									'<span class="shapeforms-choice-card__title">' + escapeHtml( shapeformsLiteBuilder.labels.siteAdminEmail ) + '</span>' +
									'<span class="shapeforms-choice-card__description">' + escapeHtml( shapeformsLiteBuilder.labels.siteAdminEmailHelp ) + '</span>' +
								'</label>' +
								'<label class="shapeforms-choice-card' + ( useCustomAdminEmail ? ' is-selected' : '' ) + '">' +
									'<input type="radio" name="shapeforms-admin-email-type" id="shapeforms-admin-email-type-custom" value="custom"' + ( useCustomAdminEmail ? ' checked' : '' ) + '>' +
									'<span class="shapeforms-choice-card__title">' + escapeHtml( shapeformsLiteBuilder.labels.customEmail ) + '</span>' +
									'<span class="shapeforms-choice-card__description">' + escapeHtml( shapeformsLiteBuilder.labels.customEmailHelp ) + '</span>' +
								'</label>' +
							'</div>' +
							( useCustomAdminEmail
								? '<div class="shapeforms-setting-group" style="margin-top:12px;margin-bottom:0">' +
									'<label for="shapeforms-admin-email">' + escapeHtml( shapeformsLiteBuilder.labels.adminEmailAddress ) + '</label>' +
									'<input type="email" id="shapeforms-admin-email" value="' + escapeHtml( state.formSettings.admin_email ) + '">' +
								'</div>'
								: ''
							) +
						// A slot for what rides along with the admin notification (e.g. a
						// generated PDF of the submission). Kept distinct from the content
						// slot below so two add-ons can fill the same email block without
						// one overwriting the other.
						//
						// Carries the teaser when the CTAs are on and nothing when they
						// are off, so an add-on always finds this slot empty and can
						// never overwrite a working control with one that only
						// advertises it.
						//
						// Inside this branch on purpose: there is no sense configuring what
						// to attach to an email that is switched off.
						'<div class="shapeforms-email-attachment-slot" data-email-slot="admin">' + attachmentTeaser() + '</div>' +
						// A slot for who the admin notification goes to (e.g.
						// Conditional Email Routing). Kept distinct from the content
						// slot below so two add-ons can fill the same email block
						// without one overwriting the other.
						//
						// Inside this branch on purpose: there is no sense
						// configuring who an email goes to while that email is
						// switched off.
						//
						// Carries the teaser only while the upgrade CTAs are shown,
						// so an add-on always finds the slot empty and ready to fill.
						'<div class="shapeforms-email-routing-slot" data-email-slot="admin">' + routingTeaser() + '</div>' +
							// Pro extension slot: add-ons (e.g. Custom Email Editor) inject per-email
							// content controls here on shapeforms:form_settings_rendered. It lives INSIDE
							// __body alongside the attachment/routing slots so all three align
							// identically — the add-on styles its own block with a top-border divider
							// and NO horizontal padding (the body's 16px provides the inset), matching
							// .bf-pdf-block / .bf-er-block. Inside the enabled branch, so "Customize
							// this email" only shows when the notification is on. The teaser only
							// occupies it while the upgrade CTAs are shown, so an add-on always finds
							// the slot empty and ready to fill.
							'<div class="shapeforms-email-pro-slot" data-email-slot="admin">' + emailTeaser() + '</div>' +
						'</div>'
						: ''
					) +
				'</div>' +
				'<div class="bfsп-email-block">' +
					'<div class="bfsп-email-block__head">' +
						'<span class="bfsп-email-block__title">' + escapeHtml( shapeformsLiteBuilder.labels.userNotifications ) + '</span>' +
						'<label class="shapeforms-switch">' +
							'<input type="checkbox" id="shapeforms-enable-user-email"' + ( state.formSettings.enable_user_email ? ' checked' : '' ) + '>' +
							'<span class="shapeforms-switch__slider"></span>' +
						'</label>' +
					'</div>' +
					// A slot for what rides along with the user confirmation. Mirrors the
					// admin block: inside the enabled branch, because there is no sense
					// configuring what to attach to an email that is switched off.
					( state.formSettings.enable_user_email
						? '<div class="bfsп-email-block__body">' +
							'<div class="shapeforms-email-attachment-slot" data-email-slot="user">' + attachmentTeaser() + '</div>' +
							// Pro extension slot for the user confirmation email — inside __body
							// alongside the attachment slot so both align identically (see the admin
							// block above). Inside the enabled branch, so it only shows when on.
							'<div class="shapeforms-email-pro-slot" data-email-slot="user">' + emailTeaser() + '</div>' +
						'</div>'
						: ''
					) +
				'</div>';

			// ── Security pane — duplicate prevention ────────────────────────────
			var dupEnabled  = !! state.formSettings.dup_enabled;
			var dupMethod   = state.formSettings.dup_method   || 'email';
			var dupFieldId  = state.formSettings.dup_field_id || '';
			var dupMessage  = state.formSettings.dup_message  || '';

			// Build field options for the "custom field" method.
			var dupFields = getAllFields().filter( function ( f ) {
				return [ 'text', 'email', 'tel', 'number', 'select', 'radio', 'hidden' ].indexOf( f.type ) !== -1;
			} );
			var dupFieldOpts = '<option value="">' + escapeHtml( shapeformsLiteBuilder.labels.selectField || '— select field —' ) + '</option>';
			dupFields.forEach( function ( f ) {
				dupFieldOpts += '<option value="' + escapeHtml( f.id ) + '"' + ( dupFieldId === f.id ? ' selected' : '' ) + '>' + escapeHtml( f.label || f.type ) + '</option>';
			} );

			var securityPane =
				'<div class="bfs-stab-pane-head">' +
					'<h3>Duplicate Prevention</h3>' +
					'<p>Block the same person from submitting this form more than once.</p>' +
				'</div>' +
				'<div class="bfsп-email-block">' +
					'<div class="bfsп-email-block__head">' +
						'<span class="bfsп-email-block__title">Prevent Duplicate Entries</span>' +
						'<label class="shapeforms-switch">' +
							'<input type="checkbox" id="shapeforms-dup-enabled"' + ( dupEnabled ? ' checked' : '' ) + '>' +
							'<span class="shapeforms-switch__slider"></span>' +
						'</label>' +
					'</div>' +
					( dupEnabled ?
						'<div class="bfsп-email-block__body">' +
							'<div class="shapeforms-setting-group">' +
								'<label>Detection Method</label>' +
								'<div class="shapeforms-choice-grid shapeforms-choice-grid--3">' +
									'<label class="shapeforms-choice-card' + ( 'email' === dupMethod ? ' is-selected' : '' ) + '">' +
										'<input type="radio" name="shapeforms-dup-method" value="email"' + ( 'email' === dupMethod ? ' checked' : '' ) + '>' +
										'<span class="shapeforms-choice-card__title">Email</span>' +
										'<span class="shapeforms-choice-card__description">Match on email field value</span>' +
									'</label>' +
									'<label class="shapeforms-choice-card' + ( 'ip' === dupMethod ? ' is-selected' : '' ) + '">' +
										'<input type="radio" name="shapeforms-dup-method" value="ip"' + ( 'ip' === dupMethod ? ' checked' : '' ) + '>' +
										'<span class="shapeforms-choice-card__title">IP Address</span>' +
										'<span class="shapeforms-choice-card__description">Match on submitter IP</span>' +
									'</label>' +
									'<label class="shapeforms-choice-card' + ( 'field' === dupMethod ? ' is-selected' : '' ) + '">' +
										'<input type="radio" name="shapeforms-dup-method" value="field"' + ( 'field' === dupMethod ? ' checked' : '' ) + '>' +
										'<span class="shapeforms-choice-card__title">Custom Field</span>' +
										'<span class="shapeforms-choice-card__description">Match on any field value</span>' +
									'</label>' +
								'</div>' +
							'</div>' +
							( 'field' === dupMethod ?
								'<div class="shapeforms-setting-group">' +
									'<label for="shapeforms-dup-field-id">Field to match on</label>' +
									'<select id="shapeforms-dup-field-id">' + dupFieldOpts + '</select>' +
								'</div>' : ''
							) +
							'<div class="shapeforms-setting-group">' +
								'<label for="shapeforms-dup-message">Error message <span style="font-weight:400;color:#94a3b8">(optional)</span></label>' +
								'<input type="text" id="shapeforms-dup-message" value="' + escapeHtml( dupMessage ) + '" placeholder="You have already submitted this form.">' +
							'</div>' +
						'</div>' : ''
					) +
				'</div>';

			// ── Conversational pane ──────────────────────────────────────────────
			// Presentation-only settings. Nothing here alters the stored form, so
			// the toggle is reversible at any time and the design survives being
			// switched off (see cv_* in normalize_form_settings()).
			var cvEnabled   = !! state.formSettings.cv_enabled;
			var cvNavAlign  = state.formSettings.cv_nav_align || 'left';
			var cvProgAlign = state.formSettings.cv_progress_align || 'left';
			var cvProgress  = state.formSettings.cv_progress  || 'bar';
			var cvTransition= state.formSettings.cv_transition || 'slide';

			/**
			 * One toggle row, using the builder's own switch markup.
			 *
			 * The switch is a label wrapping the checkbox plus a track/thumb pair;
			 * writing a bare checkbox instead leaves an unstyled control overlapping
			 * its own text.
			 */
			function cvSwitch( id, label, on ) {
				return '<div class="shapeforms-switch-item">' +
					'<label class="shapeforms-switch__row">' +
						'<span class="shapeforms-switch__text">' + escapeHtml( label ) + '</span>' +
						'<input type="checkbox" id="' + id + '"' + ( on ? ' checked' : '' ) + '>' +
						'<span class="shapeforms-switch__track"><span class="shapeforms-switch__thumb"></span></span>' +
					'</label>' +
				'</div>';
			}

			var conversationalPane =
				'<div class="bfs-stab-pane-head">' +
					'<h3>Conversational Mode</h3>' +
					'<p>Ask one question at a time, like a conversation. Your fields, layout and logic stay exactly as they are — this only changes how the form is presented, and you can switch back at any time.</p>' +
				'</div>' +
				'<div class="bfsп-email-block">' +
					'<div class="bfsп-email-block__head">' +
						'<span class="bfsп-email-block__title">Enable Conversational Mode</span>' +
						'<label class="shapeforms-switch">' +
							'<input type="checkbox" id="shapeforms-cv-enabled"' + ( cvEnabled ? ' checked' : '' ) + '>' +
							'<span class="shapeforms-switch__slider"></span>' +
						'</label>' +
					'</div>' +
					( cvEnabled ?
						'<div class="bfsп-email-block__body">' +
							'<div class="shapeforms-setting-row">' +
								'<div class="shapeforms-setting-group">' +
									'<label for="shapeforms-cv-progress">Progress indicator</label>' +
									'<select id="shapeforms-cv-progress">' +
										'<option value="bar"' +     ( 'bar' === cvProgress ? ' selected' : '' ) +     '>Progress bar</option>' +
										'<option value="dots"' +    ( 'dots' === cvProgress ? ' selected' : '' ) +    '>Dots</option>' +
										'<option value="counter"' + ( 'counter' === cvProgress ? ' selected' : '' ) + '>Counter (2 of 7)</option>' +
										'<option value="percent"' + ( 'percent' === cvProgress ? ' selected' : '' ) + '>Percentage</option>' +
										'<option value="none"' +    ( 'none' === cvProgress ? ' selected' : '' ) +    '>None</option>' +
									'</select>' +
								'</div>' +
								'<div class="shapeforms-setting-group">' +
									'<label for="shapeforms-cv-transition">Transition</label>' +
									'<select id="shapeforms-cv-transition">' +
										'<option value="slide"' + ( 'slide' === cvTransition ? ' selected' : '' ) + '>Slide</option>' +
										'<option value="fade"' +  ( 'fade' === cvTransition ? ' selected' : '' ) +  '>Fade</option>' +
										'<option value="none"' +  ( 'none' === cvTransition ? ' selected' : '' ) +  '>None</option>' +
									'</select>' +
								'</div>' +
							'</div>' +
						'<p class="shapeforms-setting-desc">Visitors who prefer reduced motion never see animation, whichever option you choose.</p>' +
							// A bar is full width and cannot move, and "none" renders
							// no indicator at all — so this appears only for the three
							// that are inline boxes with room around them.
							( cvProgressMoves() ?
								'<div class="shapeforms-setting-group">' +
									'<label for="shapeforms-cv-progress-align">Indicator position</label>' +
									'<select id="shapeforms-cv-progress-align">' +
										'<option value="left"' +   ( 'left' === cvProgAlign ? ' selected' : '' ) +   '>Left</option>' +
										'<option value="center"' + ( 'center' === cvProgAlign ? ' selected' : '' ) + '>Centred</option>' +
										'<option value="right"' +  ( 'right' === cvProgAlign ? ' selected' : '' ) +  '>Right</option>' +
									'</select>' +
								'</div>' : '' ) +
							'<div class="shapeforms-setting-row">' +
								'<div class="shapeforms-setting-group">' +
									'<label for="shapeforms-cv-next-text">Next button text</label>' +
									'<input type="text" id="shapeforms-cv-next-text" value="' + escapeHtml( state.formSettings.cv_next_text || '' ) + '" placeholder="' + escapeHtml( cvDefaultLabel( 'next' ) ) + '">' +
								'</div>' +
								'<div class="shapeforms-setting-group">' +
									'<label for="shapeforms-cv-prev-text">Back button text</label>' +
									'<input type="text" id="shapeforms-cv-prev-text" value="' + escapeHtml( state.formSettings.cv_prev_text || '' ) + '" placeholder="' + escapeHtml( cvDefaultLabel( 'prev' ) ) + '">' +
								'</div>' +
							'</div>' +
							'<div class="shapeforms-setting-group">' +
								'<label for="shapeforms-cv-nav-align">Button alignment</label>' +
								'<select id="shapeforms-cv-nav-align">' +
									'<option value="left"' +   ( 'left' === cvNavAlign ? ' selected' : '' ) +   '>Left, with the question</option>' +
									'<option value="center"' + ( 'center' === cvNavAlign ? ' selected' : '' ) + '>Centred</option>' +
									'<option value="split"' +  ( 'split' === cvNavAlign ? ' selected' : '' ) +  '>Back left, Next right</option>' +
									'<option value="right"' +  ( 'right' === cvNavAlign ? ' selected' : '' ) +  '>Both on the right</option>' +
								'</select>' +
							'</div>' +
							cvSwitch( 'shapeforms-cv-welcome-enabled', 'Open with a welcome screen', state.formSettings.cv_welcome_enabled ) +
							( state.formSettings.cv_welcome_enabled ?
								'<div class="shapeforms-setting-group">' +
									'<label for="shapeforms-cv-welcome-title">Welcome heading</label>' +
									// The placeholder is what an empty field actually
									// produces, read from the renderer rather than
									// written here — see cvDefaultLabel().
									'<input type="text" id="shapeforms-cv-welcome-title" value="' + escapeHtml( state.formSettings.cv_welcome_title || '' ) + '" placeholder="' + escapeHtml( cvDefaultLabel( 'welcome' ) ) + '">' +
								'</div>' +
								'<div class="shapeforms-setting-group">' +
									'<label for="shapeforms-cv-welcome-text">Welcome text <span class="shapeforms-setting-sublabel">(optional)</span></label>' +
									'<textarea id="shapeforms-cv-welcome-text" rows="2" placeholder="This will only take a minute.">' + escapeHtml( state.formSettings.cv_welcome_text || '' ) + '</textarea>' +
								'</div>' +
								'<div class="shapeforms-setting-group">' +
									'<label for="shapeforms-cv-welcome-btn">Start button text</label>' +
									'<input type="text" id="shapeforms-cv-welcome-btn" value="' + escapeHtml( state.formSettings.cv_welcome_btn || '' ) + '" placeholder="' + escapeHtml( cvDefaultLabel( 'start' ) ) + '">' +
								'</div>' : ''
							) +
							// Named for what it DOES, now that it does it. It used
							// to read "Show the 'press Enter' hint" and only draw
							// the label — Enter advanced either way — so an author
							// who turned it off to stop visitors skipping ahead
							// got a form that still skipped ahead.
							cvSwitch( 'shapeforms-cv-key-hint', 'Let Enter move to the next screen', state.formSettings.cv_key_hint ) +
							'<p class="shapeforms-setting-desc">Visitors can press Enter instead of clicking, and a small "press Enter" note appears beside the button so they know. Turn this off and Enter does nothing — useful when a stray keypress should not skip a question.</p>' +
							cvSwitch( 'shapeforms-cv-media-hide-mobile', 'Hide screen images on phones', state.formSettings.cv_media_hide_mobile ) +
							// Stale since the background became a SCREEN setting: it
							// used to say "select its row", which is the navigation
							// step that whole change existed to remove.
							'<p class="shapeforms-setting-desc">Add an image to a screen from its own settings &mdash; select the question on the Builder tab, or use the gear on its card.</p>' +
							// Two places, and the difference matters: the Style tab
							// sets what every screen STARTS from, a screen's own
							// settings override it. Only behaviour is set here.
							'<p class="shapeforms-setting-desc">Colours every screen starts from are on the <strong>Style</strong> tab, under Default Screen Colours. Any screen can override them from its own settings.</p>' +
						'</div>' : ''
					) +
				'</div>';


			// ── Build tabbed layout ──────────────────────────────────────────────
			var tabs = [
				{ id: 'confirmation',  icon: '<span class="dashicons dashicons-yes"></span>', label: escapeHtml( shapeformsLiteBuilder.labels.tabConfirmation  || 'Confirmation' ),      desc: escapeHtml( shapeformsLiteBuilder.labels.tabConfirmationDesc  || 'Redirect or message' ) },
				{ id: 'email',         icon: '<span class="dashicons dashicons-email-alt"></span>', label: escapeHtml( shapeformsLiteBuilder.labels.tabEmail         || 'Email Notification' ), desc: escapeHtml( shapeformsLiteBuilder.labels.tabEmailDesc         || 'Admin & user emails' ) },
				{ id: 'conversational', icon: '<span class="dashicons dashicons-format-chat"></span>', label: 'Conversational', desc: 'One question at a time' },
				{ id: 'security',      icon: '<span class="dashicons dashicons-privacy"></span>', label: 'Security',       desc: 'Duplicate prevention' }
			];

			var navHtml = '';
			tabs.forEach( function ( t ) {
				navHtml +=
					'<button type="button" class="bfs-stab-nav-item" data-stab="' + t.id + '">' +
						'<span class="bfs-stab-nav-icon">' + t.icon + '</span>' +
						'<span class="bfs-stab-nav-text">' +
							'<span class="bfs-stab-nav-label">' + t.label + '</span>' +
							'<span class="bfs-stab-nav-desc">' + t.desc + '</span>' +
						'</span>' +
						'<span class="bfs-stab-nav-arrow">&#8250;</span>' +
					'</button>';
			} );

			// Placeholder for Pro tabs (injected via shapeforms:form_settings_rendered).
			navHtml += '<div class="bfs-stab-nav-pro-slots"></div>';

			var html =
				'<div class="bfs-stab-layout">' +
					'<nav class="bfs-stab-nav">' + navHtml + '</nav>' +
					'<div class="bfs-stab-content">' +
						'<div class="bfs-stab-pane" data-pane="confirmation">' + confirmationPane + '</div>' +
						'<div class="bfs-stab-pane" data-pane="email">' + emailPane + '</div>' +
						'<div class="bfs-stab-pane" data-pane="conversational">' + conversationalPane + '</div>' +
						'<div class="bfs-stab-pane" data-pane="security">' + securityPane + '</div>' +
					'</div>' +
				'</div>';

			// Tear the editor down before the markup it is attached to is destroyed:
			// TinyMCE keeps the instance (and its iframe) alive otherwise.
			removeThankYouEditor();

			$( '#shapeforms-form-settings-panel' ).html( html );

			// ── Tab switching ────────────────────────────────────────────────────
			var $panel = $( '#shapeforms-form-settings-panel' );

			$panel.off( 'click.bfstab', '.bfs-stab-nav-item' )
				.on( 'click.bfstab', '.bfs-stab-nav-item', function () {
					var tab = $( this ).data( 'stab' );
					activeSettingsTab = tab;
					$panel.find( '.bfs-stab-nav-item' ).removeClass( 'is-active' );
					$( this ).addClass( 'is-active' );
					$panel.find( '.bfs-stab-pane' ).removeClass( 'is-active' );
					$panel.find( '.bfs-stab-pane[data-pane="' + tab + '"]' ).addClass( 'is-active' );
				} );

			/**
			 * Fired after the core form settings panel is rendered.
			 * Pro modules append nav items to .bfs-stab-nav-pro-slots and
			 * panes to .bfs-stab-content.
			 *
			 * @event shapeforms:form_settings_rendered
			 * @param {object} formSettings Current state.formSettings snapshot.
			 */
			$( document ).trigger( 'shapeforms:form_settings_rendered', [ state.formSettings ] );

			// Order the injected tabs deterministically.
			//
			// Listeners append in whatever order they happened to bind, which depends on
			// how each one is loaded (inline script vs. its own file), so the resulting
			// tab order is otherwise incidental. Instead, an add-on declares its position
			// with a numeric `data-stab-order` on its nav item and this pass sorts by it
			// (items without one default to 50, and equal values keep their append order,
			// so a listener that declares nothing still behaves exactly as before).
			//
			// Deferred by setTimeout so it runs after every synchronous listener of the
			// event above has finished appending — sorting inline would only see the tabs
			// injected so far. Nothing here names a specific add-on: it is a generic
			// ordering seam.
			setTimeout( function () {
				var $slots = $panel.find( '.bfs-stab-nav-pro-slots' );
				if ( ! $slots.length ) {
					return;
				}

				var $items = $slots.children( '.bfs-stab-nav-item' );
				if ( $items.length < 2 ) {
					return;
				}

				$items.get()
					.map( function ( el, i ) {
						var order = parseFloat( $( el ).attr( 'data-stab-order' ) );
						return { el: el, order: isNaN( order ) ? 50 : order, i: i };
					} )
					.sort( function ( a, b ) {
						return a.order - b.order || a.i - b.i;
					} )
					.forEach( function ( item ) {
						$slots.append( item.el );
					} );
			}, 0 );

			// Restore the previously active tab (Pro panes are now injected above).
			var $restore = $panel.find( '.bfs-stab-nav-item[data-stab="' + activeSettingsTab + '"]' );
			if ( ! $restore.length ) {
				// Fallback to first tab if the stored tab no longer exists.
				$restore = $panel.find( '.bfs-stab-nav-item' ).first();
				activeSettingsTab = $restore.data( 'stab' ) || 'confirmation';
			}
			$restore.addClass( 'is-active' );
			$panel.find( '.bfs-stab-pane[data-pane="' + activeSettingsTab + '"]' ).addClass( 'is-active' );

			// After the active pane is set, so TinyMCE measures a visible container.
			initThankYouEditor();
		}

		var designThemes = {
			'default-blue':   { label: 'Default Blue',   primary: '#2f80ed', focus: '#2f80ed', btnBg: '#2f80ed', btnText: '#fff', fieldBorder: '#bfdbfe', fieldBg: '#eff6ff', fieldRadius: 16 },
			'ocean-teal':     { label: 'Ocean Teal',     primary: '#0f766e', focus: '#0f766e', btnBg: '#0f766e', btnText: '#fff', fieldBorder: '#99f6e4', fieldBg: '#f0fdfa', fieldRadius: 16 },
			'forest-green':   { label: 'Forest Green',   primary: '#16a34a', focus: '#16a34a', btnBg: '#16a34a', btnText: '#fff', fieldBorder: '#bbf7d0', fieldBg: '#f0fdf4', fieldRadius: 12 },
			'sunset-orange':  { label: 'Sunset Orange',  primary: '#ea580c', focus: '#ea580c', btnBg: '#ea580c', btnText: '#fff', fieldBorder: '#fed7aa', fieldBg: '#fff7ed', fieldRadius: 8 },
			'royal-purple':   { label: 'Royal Purple',   primary: '#7c3aed', focus: '#7c3aed', btnBg: '#7c3aed', btnText: '#fff', fieldBorder: '#ddd6fe', fieldBg: '#f5f3ff', fieldRadius: 12 },
			'midnight-dark':  { label: 'Midnight Dark',  primary: '#1e293b', focus: '#334155', btnBg: '#1e293b', btnText: '#fff', fieldBorder: '#475569', fieldBg: '#f8fafc', fieldRadius: 8 },
			'minimal-gray':   { label: 'Minimal Gray',   primary: '#6b7280', focus: '#6b7280', btnBg: '#374151', btnText: '#fff', fieldBorder: '#e5e7eb', fieldBg: '#f9fafb', fieldRadius: 4 },
			'rose-pink':      { label: 'Rose Pink',      primary: '#e11d48', focus: '#e11d48', btnBg: '#e11d48', btnText: '#fff', fieldBorder: '#fecdd3', fieldBg: '#fff1f2', fieldRadius: 16 }
		};

		// Apply a Design Theme. When clearColorOverrides is true, per-control colour
		// overrides stored in the style layer are dropped first — without that a
		// single explicit colour (e.g. --bf-choice-accent) outranks the theme's
		// --bf-button-bg for the rest of the form's life and the theme looks broken.
		// Callers should route through bfApplyThemeWithConflictCheck() so the user is
		// asked before anything is discarded.
		function applyDesignTheme( themeKey, clearColorOverrides ) {
			var theme = designThemes[ themeKey ];
			if ( ! theme ) return;
			if ( clearColorOverrides ) { bfClearThemeColorOverrides(); }
			state.formSettings.design_theme = themeKey;
			state.formSettings.button_background_color = theme.btnBg;
			state.formSettings.button_border_color = theme.btnBg;
			state.formSettings.button_text_color = theme.btnText;
			state.formSettings.field_background_color = theme.fieldBg;
			state.formSettings.field_border_color = theme.fieldBorder;
			state.formSettings.field_border_radius = theme.fieldRadius;
			// Map focus color to nearest named value for build_form_style_variables
			var focusMap = { '#2f80ed': 'blue', '#2563eb': 'blue', '#0f766e': '', '#16a34a': 'green', '#334155': 'dark' };
			state.formSettings.field_focus_color = focusMap[ theme.focus ] || '';
			renderAll();
		}

		// Small localized-label reader for the theme dialog.
		function bfThemeLabel( key, fallback ) {
			var l = shapeformsLiteBuilder.labels || {};
			return l[ key ] ? l[ key ] : fallback;
		}

		// Entry point for every "apply a Design Theme" interaction. Applies straight
		// away when nothing is in the way; otherwise asks before discarding the
		// colour overrides that would outrank the theme.
		function bfApplyThemeWithConflictCheck( themeKey ) {
			if ( ! designThemes[ themeKey ] ) { return; }
			var conflicts = bfThemeColorConflicts();
			if ( ! conflicts.length ) {
				applyDesignTheme( themeKey, false );
				return;
			}
			bfShowThemeConflictDialog( themeKey, conflicts );
		}

		function bfShowThemeConflictDialog( themeKey, conflicts ) {
			$( '#shapeforms-theme-confirm' ).remove();

			var themeName = designThemes[ themeKey ] ? designThemes[ themeKey ].label : themeKey;
			var listHtml = conflicts.map( function ( c ) {
				return '<li style="display:flex;align-items:center;gap:8px;padding:4px 0">' +
					'<span aria-hidden="true" style="flex:0 0 auto;width:14px;height:14px;border-radius:3px;border:1px solid rgba(15,23,42,.2);background:' + escapeHtml( c.value ) + '"></span>' +
					'<span style="flex:1 1 auto;min-width:0;color:#334155">' + escapeHtml( c.label ) + '</span>' +
					'<code style="flex:0 0 auto;background:#f1f5f9;border-radius:3px;padding:1px 5px;font-size:11px;color:#475569">' + escapeHtml( c.value ) + '</code>' +
				'</li>';
			} ).join( '' );

			var intro = bfThemeLabel( 'themeConflictBody', 'These custom colors are overriding the theme. Applying it will replace them:' );

			var $overlay = $(
				'<div id="shapeforms-theme-confirm" role="dialog" aria-modal="true" aria-labelledby="shapeforms-theme-confirm-title" ' +
					'style="position:fixed;inset:0;z-index:100050;display:flex;align-items:center;justify-content:center;background:rgba(15,23,42,.55)">' +
					'<div style="background:#fff;max-width:460px;width:calc(100% - 40px);border-radius:10px;box-shadow:0 20px 60px rgba(0,0,0,.3);padding:24px">' +
						'<h2 id="shapeforms-theme-confirm-title" style="margin:0 0 8px;font-size:18px;line-height:1.3">' +
							escapeHtml( bfThemeLabel( 'themeConflictTitle', 'Apply theme?' ).replace( '%s', themeName ) ) +
						'</h2>' +
						'<p style="margin:0 0 12px;color:#475569;line-height:1.5">' + escapeHtml( intro ) + '</p>' +
						'<ul style="margin:0 0 20px;padding:0;list-style:none;max-height:180px;overflow-y:auto">' + listHtml + '</ul>' +
						'<div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end">' +
							'<button type="button" class="button" data-bf-theme="cancel">' +
								escapeHtml( bfThemeLabel( 'cancel', 'Cancel' ) ) +
							'</button>' +
							'<button type="button" class="button button-primary" data-bf-theme="apply">' +
								escapeHtml( bfThemeLabel( 'themeConflictApply', 'Apply theme' ) ) +
							'</button>' +
						'</div>' +
					'</div>' +
				'</div>'
			);

			function closeDialog() {
				$overlay.remove();
				$( document ).off( 'keydown.bftheme' );
			}

			$overlay.on( 'click', function ( e ) {
				var action = $( e.target ).closest( '[data-bf-theme]' ).attr( 'data-bf-theme' );
				if ( e.target === $overlay[ 0 ] ) { action = 'cancel'; }
				if ( ! action ) { return; }
				closeDialog();
				if ( 'apply' === action ) {
					applyDesignTheme( themeKey, true );
				}
			} );

			$( document ).on( 'keydown.bftheme', function ( e ) {
				if ( 27 === e.keyCode ) { closeDialog(); }
			} );

			$( 'body' ).append( $overlay );
			$overlay.find( '[data-bf-theme="apply"]' ).trigger( 'focus' );
		}

		function renderStylingSettings() {
			// Build theme cards.
			var themeCardsHtml = '<div class="shapeforms-theme-grid">';
			Object.keys( designThemes ).forEach( function ( key ) {
				var t = designThemes[ key ];
				var isActive = state.formSettings.design_theme === key;
				// Per-theme accent so the card's hover/active border + shadow match the
				// theme's own colour (not a fixed blue). Emit the hex + an RGB triplet
				// for the soft tints used by the CSS rgba() rules.
				var accent = t.primary || '#2f80ed';
				var hx = accent.replace( '#', '' );
				if ( 3 === hx.length ) { hx = hx.replace( /(.)/g, '$1$1' ); }
				var accentRgb = parseInt( hx.substr( 0, 2 ), 16 ) + ',' + parseInt( hx.substr( 2, 2 ), 16 ) + ',' + parseInt( hx.substr( 4, 2 ), 16 );
				themeCardsHtml += '<button type="button" class="shapeforms-theme-card' + ( isActive ? ' is-active' : '' ) + '" data-theme="' + escapeHtml( key ) + '" style="--bf-theme-accent:' + escapeHtml( accent ) + ';--bf-theme-accent-rgb:' + accentRgb + '">' +
					'<span class="shapeforms-theme-card__preview">' +
						'<span class="shapeforms-theme-card__input" style="border-color:' + escapeHtml( t.fieldBorder ) + ';background:' + escapeHtml( t.fieldBg ) + ';border-radius:' + t.fieldRadius + 'px"></span>' +
						'<span class="shapeforms-theme-card__btn" style="background:' + escapeHtml( t.btnBg ) + ';color:' + escapeHtml( t.btnText ) + ';border-radius:' + Math.min( t.fieldRadius, 8 ) + 'px"></span>' +
					'</span>' +
					'<span class="shapeforms-theme-card__name">' + escapeHtml( t.label ) + '</span>' +
				'</button>';
			} );
			themeCardsHtml += '</div>';

			var themeResetSvg = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg>';
			var themeResetLabel = escapeHtml( advLabel( 'resetSection' ) );
			$( '#shapeforms-form-styling-panel' ).html(
				'<div class="shapeforms-style-section is-open">' +
					'<div class="shapeforms-style-section__head"><h3>Design Theme</h3>' +
						'<div class="shapeforms-style-section__head-actions">' +
							'<button type="button" class="shapeforms-style-section__reset shapeforms-theme-reset" title="' + themeResetLabel + '" aria-label="' + themeResetLabel + '">' + themeResetSvg + '</button>' +
							'<span class="dashicons dashicons-arrow-down-alt2"></span>' +
						'</div>' +
					'</div>' +
					'<div class="shapeforms-style-section__body">' + themeCardsHtml + '</div>' +
				'</div>' +
				renderAdvancedStyleSections() +
				cvStyleSection()
			);
			bfSyncFormMaxWidthState();
			bfRefreshResetStates();
		}

		// ====================================================================
		// Advanced (full Elementor-parity) Style controls — schema-driven and
		// responsive-aware. Each control writes finished CSS values into
		// state.formSettings.style[device] keyed by the literal custom-property
		// name. The compile/emit/persist pipeline is generic over --bf-* vars
		// (getFormStyleBlock + PHP build_responsive_style_vars + sanitize_css_value),
		// so adding a control needs no PHP change — only the matching var hook in
		// frontend.css. Hover/focus/placeholder states are expressed as static
		// frontend.css rules that read a state var with the base var as fallback.
		// ====================================================================

		// Curated, WordPress.org-safe font stacks (no remote/Google Fonts). Stored
		// unquoted (valid CSS ident sequences) so they pass the value sanitizer.
		var BF_FONT_STACKS = {
			'system':    '-apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Helvetica Neue, Arial, sans-serif',
			'arial':     'Arial, Helvetica, sans-serif',
			'helvetica': 'Helvetica Neue, Helvetica, Arial, sans-serif',
			'verdana':   'Verdana, Geneva, sans-serif',
			'tahoma':    'Tahoma, Geneva, sans-serif',
			'trebuchet': 'Trebuchet MS, Helvetica, sans-serif',
			'georgia':   'Georgia, Times New Roman, serif',
			'times':     'Times New Roman, Times, serif',
			'palatino':  'Palatino Linotype, Book Antiqua, Palatino, serif',
			'courier':   'Courier New, Courier, monospace'
		};

		function advLabel( key ) {
			var a = shapeformsLiteBuilder.advStyle || {};
			if ( a[ key ] ) { return a[ key ]; }
			var b = shapeformsLiteBuilder.labels || {};
			return b[ key ] || key;
		}

		// ── Where the Style controls read and write ──
		// The Style tab edits the form's own style map. A field's "Options appearance"
		// block reuses the SAME controls pointed at that field instead, so the two can
		// never drift in look or behaviour. Every control reads through advStyleGet()
		// and every write funnels through recomputeAdvField(), so this one indirection
		// is the whole of it — no control and no handler knows which scope it is in.
		var bfStyleScope = null;

		// A field-backed store with the same two methods the form map exposes.
		function bfFieldStyleScope( target ) {
			return {
				get: function ( cssVar ) {
					var m = bfChoiceStyles( target.field );
					return typeof m[ cssVar ] === 'string' ? m[ cssVar ] : '';
				},
				set: function ( map ) {
					var m = $.extend( {}, bfChoiceStyles( target.field ) );
					Object.keys( map ).forEach( function ( k ) {
						var v = map[ k ];
						// Absent, not empty: "inherit the form" is the absence of a value.
						if ( '' === v || null == v ) { delete m[ k ]; } else { m[ k ] = String( v ); }
					} );
					target.field.choice_styles = m;
					// Never re-render the panel from a write — the user may be mid-keystroke.
					target.commit( false );
					bfRefreshChoiceReset();
				}
			};
		}

		// Handlers fire long after the markup was built, so the scope cannot be held in
		// a closure — it has to be recoverable from the element alone.
		function bfScopeForEl( $el ) {
			if ( ! $el || ! $el.length || ! $el.closest( '[data-bf-choice-scope]' ).length ) { return null; }
			var t = bfChoiceStyleTarget( $el );
			return ( t && t.field ) ? bfFieldStyleScope( t ) : null;
		}

		function advStyleGet( cssVar ) {
			if ( bfStyleScope ) { return bfStyleScope.get( cssVar ); }
			var layer = ( state.formSettings.style && state.formSettings.style[ state.activeDevice ] ) || {};
			return typeof layer[ cssVar ] === 'string' ? layer[ cssVar ] : '';
		}

		// Writes a map of { cssVar: value } into the active device layer (deletes on
		// empty), then refreshes the preview only — never re-renders the controls,
		// so input focus is preserved while typing.
		function advStyleSetVars( map ) {
			if ( bfStyleScope ) { return bfStyleScope.set( map ); }
			if ( ! state.formSettings.style ) {
				state.formSettings.style = { desktop: {}, tablet: {}, mobile: {} };
			}
			var layer = state.formSettings.style[ state.activeDevice ];
			if ( ! layer ) { layer = state.formSettings.style[ state.activeDevice ] = {}; }
			Object.keys( map ).forEach( function ( k ) {
				var v = map[ k ];
				if ( '' === v || null == v ) { delete layer[ k ]; }
				else { layer[ k ] = String( v ); }
			} );
			applyPreviewStyleBlock();
			bfRefreshResetStates();
		}

		var BF_HEX6 = /^#[0-9a-fA-F]{6}$/;

		// ── Colour helpers: store/compose hex ⇄ rgba so colours can carry opacity ──
		function bfHexToRgb( hex ) {
			var n = parseInt( hex.slice( 1 ), 16 );
			return { r: ( n >> 16 ) & 255, g: ( n >> 8 ) & 255, b: n & 255 };
		}
		function bfRgbToHex( r, g, b ) {
			return '#' + [ r, g, b ].map( function ( x ) {
				x = Math.max( 0, Math.min( 255, parseInt( x, 10 ) || 0 ) );
				var h = x.toString( 16 );
				return 1 === h.length ? '0' + h : h;
			} ).join( '' );
		}
		// Compose a stored colour from an RGB hex + opacity 0–100 (plain hex when fully opaque).
		function bfComposeColor( hex, opacity ) {
			if ( ! BF_HEX6.test( hex ) ) { return ''; }
			var op = parseInt( opacity, 10 );
			if ( isNaN( op ) ) { op = 100; }
			op = Math.max( 0, Math.min( 100, op ) );
			if ( op >= 100 ) { return hex.toLowerCase(); }
			var c = bfHexToRgb( hex );
			return 'rgba(' + c.r + ', ' + c.g + ', ' + c.b + ', ' + ( Math.round( op ) / 100 ) + ')';
		}
		// Parse a stored colour ( #rrggbb | #rrggbbaa | rgb()/rgba() ) → { hex, opacity }.
		function bfParseColor( val ) {
			val = ( val || '' ).trim();
			if ( BF_HEX6.test( val ) ) { return { hex: val.toLowerCase(), opacity: 100 }; }
			var m8 = /^#([0-9a-fA-F]{6})([0-9a-fA-F]{2})$/.exec( val );
			if ( m8 ) { return { hex: ( '#' + m8[1] ).toLowerCase(), opacity: Math.round( parseInt( m8[2], 16 ) / 255 * 100 ) }; }
			var m = /^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*(\d*\.?\d+)\s*)?\)$/i.exec( val );
			if ( m ) {
				var a = ( void 0 === m[4] ) ? 1 : parseFloat( m[4] );
				if ( isNaN( a ) ) { a = 1; }
				a = Math.max( 0, Math.min( 1, a ) );
				return { hex: bfRgbToHex( m[1], m[2], m[3] ), opacity: Math.round( a * 100 ) };
			}
			return { hex: '', opacity: 100 };
		}
		// Read a .shapeforms-color-field element → composed colour string ('' if no valid hex).
		function bfReadColorField( $cf ) {
			if ( ! $cf || ! $cf.length ) { return ''; }
			var hv = ( $cf.find( '.bf-adv-hex' ).val() || '' ).trim();
			if ( ! BF_HEX6.test( hv ) ) { return ''; }
			var opRaw = $cf.find( '.bf-adv-opacity' ).val();
			return bfComposeColor( hv, ( '' === opRaw || null == opRaw ) ? 100 : opRaw );
		}
		// Alpha-slider track gradient: same colour fading 0→100% alpha (8-digit hex keeps
		// the hue true at every step, unlike fading to the muddy `transparent` keyword).
		function bfAlphaGrad( hex ) {
			if ( ! BF_HEX6.test( hex ) ) { hex = '#0f766e'; }
			return 'linear-gradient(to right, ' + hex + '00, ' + hex + ')';
		}
		// When an advanced colour is left unset, the colour the form actually renders is
		// the flat base/theme value (the advanced layer sits on top of it). Map the
		// advanced var → its flat settings key so the swatch can preview that real default.
		var BF_FLAT_DEFAULT = {
			'--bf-field-bg': 'field_background_color',
			'--bf-field-text': 'field_text_color',
			'--bf-field-border': 'field_border_color',
			'--bf-select-bg': 'field_background_color',
			'--bf-select-text': 'field_text_color',
			'--bf-button-bg': 'button_background_color',
			'--bf-button-text': 'button_text_color',
			'--bf-button-border': 'button_border_color',
			'--bf-label-color': 'label_color',
			'--bf-error-color': 'error_color'
		};
		// Controls whose effective default is a hard-coded CSS literal (not a theme/flat
		// value) — e.g. the success/error notices. Mirrors the fallbacks in frontend.css
		// so the swatch previews the real default instead of an empty checkerboard.
		var BF_LITERAL_DEFAULT = {
			'--bf-msg-success-bg': '#ecfdf5',
			'--bf-msg-success-text': '#166534',
			'--bf-msg-success-border-color': '#bbf7d0',
			'--bf-msg-error-bg': '#fef2f2',
			'--bf-msg-error-text': '#b91c1c',
			'--bf-msg-error-border-color': '#fecaca',
			'--bf-error-color': '#dc2626',
			'--bf-ph-color': '#9ca3af',
			'--bf-select-ph': '#9ca3af',
			'--bf-select-search-ph': '#9ca3af',
			'--bf-select-arrow': '#6b7280',
			'--bf-section-title-color': '#0f172a',
			'--bf-section-desc-color': '#6b7280',
			'--bf-file-btn-bg': '#e2e8f0',
			'--bf-file-btn-text': '#334155'
		};
		function bfEffectiveDefault( cssVar ) {
			if ( ! cssVar ) { return ''; }
			var key = BF_FLAT_DEFAULT[ cssVar ];
			if ( key && state.formSettings ) {
				var fromFlat = bfParseColor( String( state.formSettings[ key ] || '' ) ).hex;
				if ( fromFlat ) { return fromFlat; }
			}
			return BF_LITERAL_DEFAULT[ cssVar ] || '';
		}
		// Resolve a colour field's visual: an explicit value wins; otherwise preview the
		// effective default (flat base); otherwise the checkerboard 'no colour' state.
		function bfVisualFor( composed, defVar ) {
			if ( composed ) {
				return { sw: composed, grad: bfAlphaGrad( bfParseColor( composed ).hex ), isDefault: false };
			}
			var defHex = bfEffectiveDefault( defVar );
			if ( defHex ) {
				return { sw: defHex, grad: bfAlphaGrad( defHex ), isDefault: true };
			}
			return { sw: 'transparent', grad: 'linear-gradient(to right, transparent, transparent)', isDefault: false };
		}
		function bfSyncColorSwatch( $cf ) {
			var composed = bfReadColorField( $cf );
			var vis = bfVisualFor( composed, $cf.attr( 'data-def-var' ) || '' );
			$cf.css( '--bf-sw', vis.sw ).css( '--bf-agrad', vis.grad ).toggleClass( 'is-default', vis.isDefault );
			// Reset has nothing to do until an explicit colour is set — disable it otherwise.
			$cf.find( '.bf-adv-color-reset' ).prop( 'disabled', '' === composed );
		}

		function advColorFieldMarkup( hexClass, val, placeholder, defVar ) {
			var parsed = bfParseColor( val );
			var valid = '' !== parsed.hex;
			var op = valid ? parsed.opacity : 100;
			var vis = bfVisualFor( valid ? bfComposeColor( parsed.hex, parsed.opacity ) : '', defVar );
			return '<div class="shapeforms-color-field shapeforms-color-field--alpha' + ( vis.isDefault ? ' is-default' : '' ) + '"' + ( defVar ? ' data-def-var="' + escapeHtml( defVar ) + '"' : '' ) + ' style="--bf-sw:' + escapeHtml( vis.sw ) + ';--bf-agrad:' + escapeHtml( vis.grad ) + '">' +
					'<div class="shapeforms-color-cmain">' +
						'<div class="shapeforms-color-swatch">' +
							'<input type="color" class="bf-adv-colorpick" value="' + ( valid ? escapeHtml( parsed.hex ) : '#000000' ) + '">' +
						'</div>' +
						'<input type="text" class="bf-adv-input bf-adv-hex ' + hexClass + '" maxlength="7" placeholder="' + escapeHtml( placeholder || advLabel( 'inheritDefault' ) ) + '" value="' + escapeHtml( parsed.hex ) + '" spellcheck="false">' +
						'<button type="button" class="bf-adv-color-reset"' + ( valid ? '' : ' disabled' ) + ' title="' + escapeHtml( advLabel( 'reset' ) ) + '" aria-label="' + escapeHtml( advLabel( 'reset' ) ) + '"><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg></button>' +
					'</div>' +
					'<div class="shapeforms-color-alpha">' +
						'<input type="range" class="bf-adv-opacity" min="0" max="100" step="1" value="' + op + '" title="' + escapeHtml( advLabel( 'opacity' ) ) + '" aria-label="' + escapeHtml( advLabel( 'opacity' ) ) + '">' +
						'<span class="bf-adv-op-val">' + op + '%</span>' +
					'</div>' +
				'</div>';
		}

		function advColor( cssVar, label ) {
			return '<div class="shapeforms-setting-group shapeforms-adv-field" data-type="color" data-var="' + cssVar + '">' +
				'<label>' + escapeHtml( label ) + '</label>' +
				advColorFieldMarkup( '', advStyleGet( cssVar ), null, cssVar ) +
			'</div>';
		}

		function advSlider( cssVar, label, min, max, units ) {
			units = units || [ 'px' ];
			var raw = advStyleGet( cssVar );
			var num = parseFloat( raw );
			var unit = 'px';
			var m = /[a-z%]+$/i.exec( raw ); if ( m ) { unit = m[0]; }
			var unitCtl;
			if ( units.length > 1 ) {
				unitCtl = '<select class="bf-adv-input bf-adv-unit">' + units.map( function ( u ) {
					return '<option value="' + u + '"' + ( u === unit ? ' selected' : '' ) + '>' + u + '</option>';
				} ).join( '' ) + '</select>';
			} else {
				unitCtl = '<span data-unit="' + escapeHtml( units[0] ) + '">' + escapeHtml( units[0] ) + '</span>';
			}
			return '<div class="shapeforms-setting-group shapeforms-adv-field" data-type="slider" data-var="' + cssVar + '">' +
				'<label>' + escapeHtml( label ) + '</label>' +
				'<div class="shapeforms-style-input-wrap"><input type="number" class="bf-adv-input bf-adv-num" min="' + min + '" max="' + max + '" placeholder="' + escapeHtml( advLabel( 'inheritDefault' ) ) + '" value="' + ( isNaN( num ) ? '' : num ) + '">' + unitCtl + '</div>' +
			'</div>';
		}

		function advDimension( cssVar, label, units ) {
			units = units || [ 'px', 'em', '%' ];
			var raw = advStyleGet( cssVar );
			var parts = raw.split( /\s+/ ).filter( Boolean );
			var unit = 'px';
			var nums = [ '', '', '', '' ];
			if ( parts.length ) {
				var mm = /[a-z%]+$/i.exec( parts[0] ); if ( mm ) { unit = mm[0]; }
				for ( var i = 0; i < 4; i++ ) {
					var p = parts[ i ] != null ? parts[ i ] : parts[ parts.length - 1 ];
					var n = parseFloat( p );
					nums[ i ] = isNaN( n ) ? '' : n;
				}
			}
			var unitOpts = units.map( function ( u ) {
				return '<option value="' + u + '"' + ( u === unit ? ' selected' : '' ) + '>' + u + '</option>';
			} ).join( '' );
			function dimInp( idx, cap ) {
				return '<div class="bf-adv-dim-cell">' +
					'<input type="number" class="bf-adv-input bf-adv-dim" data-side="' + idx + '" placeholder="—" value="' + nums[ idx ] + '">' +
					'<span class="bf-adv-dim-cap">' + escapeHtml( cap ) + '</span>' +
				'</div>';
			}
			// Linked when all four sides are equal — including the default all-empty state,
			// so a fresh control starts linked and one value fills every side. Only diverging
			// side values render it unlinked. Also restores the toggle state on reload.
			var linked = nums[0] === nums[1] && nums[1] === nums[2] && nums[2] === nums[3];
			return '<div class="shapeforms-setting-group shapeforms-adv-field' + ( linked ? ' is-linked' : '' ) + '" data-type="dimension" data-var="' + cssVar + '">' +
				'<label>' + escapeHtml( label ) + '</label>' +
				'<div class="shapeforms-adv-dim">' +
					dimInp( 0, advLabel( 'sideTop' ) ) + dimInp( 1, advLabel( 'sideRight' ) ) + dimInp( 2, advLabel( 'sideBottom' ) ) + dimInp( 3, advLabel( 'sideLeft' ) ) +
					'<select class="bf-adv-input bf-adv-dim-unit">' + unitOpts + '</select>' +
					'<button type="button" class="bf-adv-dim-link' + ( linked ? ' is-active' : '' ) + '" title="' + escapeHtml( advLabel( 'linkSides' ) ) + '"><span class="dashicons dashicons-admin-links"></span></button>' +
				'</div>' +
			'</div>';
		}

		function advBorder( baseVar, label, colorVar ) {
			colorVar = colorVar || ( baseVar + '-color' );
			var w = advStyleGet( baseVar + '-width' ), st = advStyleGet( baseVar + '-style' ), c = advStyleGet( colorVar );
			var wn = parseFloat( w );
			var styles = [ 'solid', 'dashed', 'dotted', 'double', 'none' ];
			var stOpts = '<option value="">' + escapeHtml( advLabel( 'inheritDefault' ) ) + '</option>' + styles.map( function ( s ) {
				return '<option value="' + s + '"' + ( s === st ? ' selected' : '' ) + '>' + s + '</option>';
			} ).join( '' );
			return '<div class="shapeforms-setting-group shapeforms-adv-field" data-type="border" data-var="' + baseVar + '" data-colorvar="' + colorVar + '">' +
				'<label>' + escapeHtml( label ) + '</label>' +
				'<div class="shapeforms-adv-border">' +
					'<div class="bf-adv-bd-cell bf-adv-bd-cell--width">' +
						'<div class="shapeforms-style-input-wrap"><input type="number" class="bf-adv-input bf-adv-bw" min="0" max="20" placeholder="' + escapeHtml( advLabel( 'inheritDefault' ) ) + '" value="' + ( isNaN( wn ) ? '' : wn ) + '"><span>px</span></div>' +
						'<span class="bf-adv-dim-cap">' + escapeHtml( advLabel( 'widthLabel' ) ) + '</span>' +
					'</div>' +
					'<div class="bf-adv-bd-cell bf-adv-bd-cell--style">' +
						'<select class="bf-adv-input bf-adv-bs">' + stOpts + '</select>' +
						'<span class="bf-adv-dim-cap">' + escapeHtml( advLabel( 'styleLabel' ) ) + '</span>' +
					'</div>' +
				'</div>' +
				'<div class="bf-adv-bc-row" data-type="color"><span class="bf-adv-dim-cap">' + escapeHtml( advLabel( 'colorLabel' ) ) + '</span>' + advColorFieldMarkup( 'bf-adv-bc', c, advLabel( 'borderColor' ), colorVar ) + '</div>' +
			'</div>';
		}

		function advTypography( baseVar, label ) {
			var ff = advStyleGet( baseVar + '-ff' ), fs = advStyleGet( baseVar + '-fs' ), fw = advStyleGet( baseVar + '-fw' ),
				lh = advStyleGet( baseVar + '-lh' ), ls = advStyleGet( baseVar + '-ls' ), tt = advStyleGet( baseVar + '-tt' );
			var fsNum = parseFloat( fs ), lsNum = parseFloat( ls );
			var ffKey = '';
			Object.keys( BF_FONT_STACKS ).forEach( function ( k ) { if ( BF_FONT_STACKS[ k ] === ff ) { ffKey = k; } } );
			var ffOpts = '<option value="">' + escapeHtml( advLabel( 'themeFont' ) ) + '</option>' + Object.keys( BF_FONT_STACKS ).map( function ( k ) {
				var name = k.charAt( 0 ).toUpperCase() + k.slice( 1 );
				return '<option value="' + k + '"' + ( k === ffKey ? ' selected' : '' ) + '>' + escapeHtml( name ) + '</option>';
			} ).join( '' );
			var weights = [ '300', '400', '500', '600', '700', '800' ];
			var fwOpts = '<option value="">' + escapeHtml( advLabel( 'inheritDefault' ) ) + '</option>' + weights.map( function ( wv ) {
				return '<option value="' + wv + '"' + ( wv === fw ? ' selected' : '' ) + '>' + wv + '</option>';
			} ).join( '' );
			var transforms = [ [ 'none', 'none' ], [ 'uppercase', advLabel( 'uppercase' ) ], [ 'lowercase', advLabel( 'lowercase' ) ], [ 'capitalize', advLabel( 'capitalize' ) ] ];
			var ttOpts = '<option value="">' + escapeHtml( advLabel( 'inheritDefault' ) ) + '</option>' + transforms.map( function ( t ) {
				return '<option value="' + t[0] + '"' + ( t[0] === tt ? ' selected' : '' ) + '>' + escapeHtml( t[1] ) + '</option>';
			} ).join( '' );
			return '<div class="shapeforms-setting-group shapeforms-adv-field shapeforms-adv-typo" data-type="typography" data-var="' + baseVar + '">' +
				'<label>' + escapeHtml( label ) + '</label>' +
				'<div class="shapeforms-adv-typo-grid">' +
					'<div class="bf-adv-typo-cell bf-adv-typo-cell--full"><select class="bf-adv-input bf-adv-ff">' + ffOpts + '</select><span class="bf-adv-dim-cap">' + escapeHtml( advLabel( 'fontFamily' ) ) + '</span></div>' +
					'<div class="bf-adv-typo-cell"><div class="shapeforms-style-input-wrap"><input type="number" class="bf-adv-input bf-adv-fs" min="8" max="80" placeholder="' + escapeHtml( advLabel( 'inheritDefault' ) ) + '" value="' + ( isNaN( fsNum ) ? '' : fsNum ) + '"><span>px</span></div><span class="bf-adv-dim-cap">' + escapeHtml( advLabel( 'fontSize' ) ) + '</span></div>' +
					'<div class="bf-adv-typo-cell"><select class="bf-adv-input bf-adv-fw">' + fwOpts + '</select><span class="bf-adv-dim-cap">' + escapeHtml( advLabel( 'fontWeight' ) ) + '</span></div>' +
					'<div class="bf-adv-typo-cell"><div class="shapeforms-style-input-wrap"><input type="number" class="bf-adv-input bf-adv-lh" min="0" max="100" step="1" placeholder="' + escapeHtml( advLabel( 'inheritDefault' ) ) + '" value="' + ( lh === '' ? '' : parseFloat( lh ) ) + '"><span>px</span></div><span class="bf-adv-dim-cap">' + escapeHtml( advLabel( 'lineHeight' ) ) + '</span></div>' +
					'<div class="bf-adv-typo-cell"><div class="shapeforms-style-input-wrap"><input type="number" class="bf-adv-input bf-adv-ls" min="-5" max="20" step="0.1" placeholder="' + escapeHtml( advLabel( 'inheritDefault' ) ) + '" value="' + ( isNaN( lsNum ) ? '' : lsNum ) + '"><span>px</span></div><span class="bf-adv-dim-cap">' + escapeHtml( advLabel( 'letterSpacing' ) ) + '</span></div>' +
					'<div class="bf-adv-typo-cell bf-adv-typo-cell--full"><select class="bf-adv-input bf-adv-tt">' + ttOpts + '</select><span class="bf-adv-dim-cap">' + escapeHtml( advLabel( 'textTransform' ) ) + '</span></div>' +
				'</div>' +
			'</div>';
		}

		function shadowBodyMarkup( cssVar ) {
			var raw = advStyleGet( cssVar );
			var inset = /(^|\s)inset(\s|$)/.test( raw );
			var nums = ( raw.replace( 'inset', '' ).match( /-?\d+(\.\d+)?px/g ) || [] ).map( parseFloat );
			// Colour may be hex (#rrggbb / #rrggbbaa) or rgb()/rgba() — bfComposeColor emits
			// rgba() whenever opacity < 100%, so parse every form back (not just hex),
			// otherwise a translucent shadow colour is lost on reload.
			var colMatch = raw.match( /#[0-9a-fA-F]{8}|#[0-9a-fA-F]{6}|rgba?\([^)]*\)/i );
			var col = colMatch ? colMatch[0] : '';
			function sInp( cls, cap, v ) {
				// Caption (not placeholder) so the field name stays visible once a value is
				// entered — a bare placeholder vanishes and leaves four anonymous px boxes.
				return '<div class="bf-adv-shadow-cell">' +
					'<div class="shapeforms-style-input-wrap"><input type="number" class="bf-adv-input ' + cls + '" placeholder="0" value="' + ( isNaN( v ) || v == null ? '' : v ) + '"><span>px</span></div>' +
					'<span class="bf-adv-dim-cap">' + escapeHtml( cap ) + '</span>' +
				'</div>';
			}
			return '<div class="shapeforms-adv-shadow-grid">' +
				sInp( 'bf-adv-sx', advLabel( 'shadowX' ), nums[0] ) +
				sInp( 'bf-adv-sy', advLabel( 'shadowY' ), nums[1] ) +
				sInp( 'bf-adv-sblur', advLabel( 'shadowBlur' ), nums[2] ) +
				sInp( 'bf-adv-sspread', advLabel( 'shadowSpread' ), nums[3] ) +
			'</div>' +
			'<div class="shapeforms-adv-shadow-foot">' +
				'<div class="bf-adv-sc-row" data-type="color"><span class="bf-adv-dim-cap">' + escapeHtml( advLabel( 'colorLabel' ) ) + '</span>' + advColorFieldMarkup( 'bf-adv-sc', col, advLabel( 'shadowColor' ) ) + '</div>' +
				'<label class="bf-adv-inset-lbl"><input type="checkbox" class="bf-adv-input bf-adv-inset"' + ( inset ? ' checked' : '' ) + '> ' + escapeHtml( advLabel( 'inset' ) ) + '</label>' +
			'</div>';
		}

		function advShadow( cssVar, label, states ) {
			var hasStates = states && states.length > 1;
			var activeVar = hasStates ? states[0][0] : cssVar;
			var tabs = '';
			if ( hasStates ) {
				tabs = '<div class="shapeforms-adv-bg-tabs shapeforms-adv-shadow-tabs">' + states.map( function ( st, i ) {
					return '<button type="button" class="bf-adv-bgtype bf-adv-shadow-tab' + ( 0 === i ? ' is-active' : '' ) + '" data-shadow-var="' + st[0] + '">' + escapeHtml( advLabel( st[1] ) ) + '</button>';
				} ).join( '' ) + '</div>';
			}
			return '<div class="shapeforms-setting-group shapeforms-adv-field shapeforms-adv-shadow" data-type="shadow" data-var="' + activeVar + '">' +
				'<label>' + escapeHtml( label ) + '</label>' +
				tabs +
				'<div class="bf-adv-shadow-body">' + shadowBodyMarkup( activeVar ) + '</div>' +
			'</div>';
		}

		function advBackground( cssVar, label ) {
			var raw = advStyleGet( cssVar );
			var isGrad = /gradient/i.test( raw );
			var c1 = '', c2 = '', angle = 135, solid = '';
			if ( isGrad ) {
				// Match hex8/hex6/rgba so opacity-bearing gradient stops restore on reload.
				var cols = raw.match( /#[0-9a-fA-F]{8}|#[0-9a-fA-F]{6}|rgba?\([^)]*\)/gi ) || [];
				c1 = cols[0] || ''; c2 = cols[1] || '';
				var am = raw.match( /(-?\d+)deg/ ); if ( am ) { angle = parseInt( am[1], 10 ); }
			} else {
				// Pass the raw colour (hex/hex8/rgba); advColorFieldMarkup parses opacity,
				// so a solid background set with <100% alpha survives a reload too.
				solid = raw;
			}
			return '<div class="shapeforms-setting-group shapeforms-adv-field shapeforms-adv-bg" data-type="background" data-var="' + cssVar + '">' +
				'<label>' + escapeHtml( label ) + '</label>' +
				'<div class="shapeforms-adv-bg-tabs">' +
					'<button type="button" class="bf-adv-bgtype' + ( ! isGrad ? ' is-active' : '' ) + '" data-bg="solid">' + escapeHtml( advLabel( 'solidFill' ) ) + '</button>' +
					'<button type="button" class="bf-adv-bgtype' + ( isGrad ? ' is-active' : '' ) + '" data-bg="gradient">' + escapeHtml( advLabel( 'gradient' ) ) + '</button>' +
				'</div>' +
				'<div class="bf-adv-bg-solid"' + ( isGrad ? ' hidden' : '' ) + ' data-type="color">' + advColorFieldMarkup( 'bf-adv-bg-c', solid, null, cssVar ) + '</div>' +
				'<div class="bf-adv-bg-grad"' + ( ! isGrad ? ' hidden' : '' ) + '>' +
					'<div class="bf-adv-bg-stop" data-type="color"><span class="bf-adv-bg-cap">' + escapeHtml( advLabel( 'colorStop1' ) ) + '</span>' + advColorFieldMarkup( 'bf-adv-bg-c1', c1 ) + '</div>' +
					'<div class="bf-adv-bg-stop" data-type="color"><span class="bf-adv-bg-cap">' + escapeHtml( advLabel( 'colorStop2' ) ) + '</span>' + advColorFieldMarkup( 'bf-adv-bg-c2', c2 ) + '</div>' +
					'<div class="bf-adv-bg-stop"><span class="bf-adv-bg-cap">' + escapeHtml( advLabel( 'angle' ) ) + '</span><div class="shapeforms-style-input-wrap"><input type="number" class="bf-adv-input bf-adv-bg-angle" min="0" max="360" value="' + angle + '"><span>deg</span></div></div>' +
				'</div>' +
			'</div>';
		}

		function advSelectCtl( cssVar, label, options ) {
			var cur = advStyleGet( cssVar );
			var opts = '<option value="">' + escapeHtml( advLabel( 'inheritDefault' ) ) + '</option>' + options.map( function ( o ) {
				return '<option value="' + escapeHtml( o[0] ) + '"' + ( o[0] === cur ? ' selected' : '' ) + '>' + escapeHtml( o[1] ) + '</option>';
			} ).join( '' );
			return '<div class="shapeforms-setting-group shapeforms-adv-field" data-type="select" data-var="' + cssVar + '">' +
				'<label>' + escapeHtml( label ) + '</label>' +
				'<select class="bf-adv-input bf-adv-select">' + opts + '</select>' +
			'</div>';
		}

		// Checkbox & Radio → Style. A segmented Default/Button switch.
		//
		// The one control in this section that is NOT a style-layer var: it decides
		// which element carries the treatment, which the renderer expresses as a
		// class, so it is stored as a plain form setting (see normalizeFormSettings).
		// That also keeps it out of the per-device layer, where "buttons on desktop,
		// boxes on mobile" would be offered and could not be honoured.
		function advChoiceStyle( label ) {
			var cur = 'button' === state.formSettings.choice_style ? 'button' : 'default';
			var opts = [ [ 'default', advLabel( 'choiceStyleDefault' ) ], [ 'button', advLabel( 'choiceStyleButton' ) ] ];
			return '<div class="shapeforms-setting-group shapeforms-adv-field" data-type="choicestyle">' +
				'<label>' + escapeHtml( label ) + '</label>' +
				'<div class="bf-adv-choice-style" role="group">' + opts.map( function ( o ) {
					return '<button type="button" class="bf-adv-choice-style-btn' + ( o[0] === cur ? ' is-active' : '' ) +
						'" data-choice-style="' + escapeHtml( o[0] ) + '" aria-pressed="' + ( o[0] === cur ? 'true' : 'false' ) + '">' +
						escapeHtml( o[1] ) + '</button>';
				} ).join( '' ) + '</div>' +
			'</div>';
		}

		function advSwitch( cssVar, label, onValue ) {
			var on = '' !== advStyleGet( cssVar );
			return '<div class="shapeforms-setting-group shapeforms-adv-field shapeforms-adv-switch" data-type="switch" data-var="' + cssVar + '" data-on="' + escapeHtml( onValue ) + '">' +
				'<label class="bf-adv-switch-lbl"><input type="checkbox" class="bf-adv-input bf-adv-toggle"' + ( on ? ' checked' : '' ) + '> ' + escapeHtml( label ) + '</label>' +
			'</div>';
		}

		// Segmented Normal/Hover/Focus switcher: one panel of controls visible at a
		// time, so each state's colours are grouped and discoverable instead of a flat
		// list of "Hover …"/"Focus …" labels. Inner controls are ordinary advControls,
		// so their handlers/recompute work unchanged whether shown or hidden.
		function advStateTabs( group ) {
			var states = group.states || [];
			var tabs = '<div class="shapeforms-adv-state-tabs">' + states.map( function ( s, i ) {
				return '<button type="button" class="bf-adv-state-tab' + ( 0 === i ? ' is-active' : '' ) + '" data-state="' + escapeHtml( s.key ) + '">' + escapeHtml( advLabel( s.label ) ) + '</button>';
			} ).join( '' ) + '</div>';
			var panels = states.map( function ( s, i ) {
				var inner = ( s.controls || [] ).map( advControl ).join( '' );
				return '<div class="bf-adv-state-panel"' + ( 0 === i ? '' : ' hidden' ) + ' data-state="' + escapeHtml( s.key ) + '"><div class="shapeforms-adv-grid">' + inner + '</div></div>';
			} ).join( '' );
			return '<div class="shapeforms-adv-statetabs">' +
				( group.label ? '<label>' + escapeHtml( advLabel( group.label ) ) + '</label>' : '' ) +
				tabs + panels +
			'</div>';
		}

		// Icon-segmented horizontal alignment (left / center / right / full width) for
		// the form container. Stores the keyword in `cssVar` so the active button
		// restores on reload; recomputeAdvField derives the margin/width vars the CSS uses.
		function advAlign( cssVar, label ) {
			var cur = advStyleGet( cssVar ) || 'left';
			var svg = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">';
			var icons = {
				left:    svg + '<line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="12" x2="14" y2="12"></line><line x1="3" y1="18" x2="18" y2="18"></line></svg>',
				center:  svg + '<line x1="3" y1="6" x2="21" y2="6"></line><line x1="7" y1="12" x2="17" y2="12"></line><line x1="5" y1="18" x2="19" y2="18"></line></svg>',
				right:   svg + '<line x1="3" y1="6" x2="21" y2="6"></line><line x1="10" y1="12" x2="21" y2="12"></line><line x1="6" y1="18" x2="21" y2="18"></line></svg>',
				justify: svg + '<line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>'
			};
			var btns = [ 'left', 'center', 'right', 'justify' ].map( function ( k ) {
				var t = escapeHtml( advLabel( 'align' + k.charAt( 0 ).toUpperCase() + k.slice( 1 ) ) );
				return '<button type="button" class="bf-adv-align-btn' + ( k === cur ? ' is-active' : '' ) + '" data-align="' + k + '" title="' + t + '" aria-label="' + t + '">' + icons[ k ] + '</button>';
			} ).join( '' );
			return '<div class="shapeforms-setting-group shapeforms-adv-field" data-type="align" data-var="' + cssVar + '">' +
				'<label>' + escapeHtml( label ) + '</label>' +
				'<div class="bf-adv-align">' + btns + '</div>' +
			'</div>';
		}

		// Max Width and the "Full Width" (justify) alignment are mutually exclusive:
		// full width forces 100% and ignores any max-width. When justify is active,
		// disable the Max Width control so the relationship is clear; re-enable it
		// for left/center/right. Runs on render and after every alignment change.
		function bfSyncFormMaxWidthState() {
			var av = $( '[data-var="--bf-form-align"] .bf-adv-align-btn.is-active' ).data( 'align' ) || '';
			var disabled = ( 'justify' === av );
			var $mw = $( '[data-var="--bf-form-max-width"]' );
			$mw.toggleClass( 'is-disabled', disabled );
			$mw.find( 'input, select' ).prop( 'disabled', disabled );
		}

		function advControl( c ) {
			switch ( c.type ) {
				case 'color':      return advColor( c.var, advLabel( c.label ) );
				case 'slider':     return advSlider( c.var, advLabel( c.label ), c.min, c.max, c.units );
				case 'dimension':  return advDimension( c.var, advLabel( c.label ), c.units );
				case 'border':     return advBorder( c.var, advLabel( c.label ), c.colorVar );
				case 'typography': return advTypography( c.var, advLabel( c.label ) );
				case 'shadow':     return advShadow( c.var, advLabel( c.label ), c.states );
				case 'background': return advBackground( c.var, advLabel( c.label ) );
				case 'select':     return advSelectCtl( c.var, advLabel( c.label ), c.options );
				case 'switch':     return advSwitch( c.var, advLabel( c.label ), c.on );
				case 'align':      return advAlign( c.var, advLabel( c.label ) );
				case 'choiceStyle': return advChoiceStyle( advLabel( c.label ) );
				case 'stateTabs':  return advStateTabs( c );
				case 'heading':    return '<div class="shapeforms-adv-subhead">' + escapeHtml( advLabel( c.label ) ) + '</div>';
			}
			return '';
		}

		// The full control catalog. Sections are appended after the legacy
		// Design Theme / Field / Label / Button sections. Phases add sections here.
		function bfStyleSchema() {
			// Display order of the Style-tab sections: foundation (container, layout)
			// → field anatomy (labels, inputs, placeholder) → field types (select,
			// choice, star, file, terms) → structure (section break) → action (submit)
			// → messages. Reorder by editing this id list; the definitions stay put.
			var bfSectionOrder = [ 'container', 'layout', 'labels', 'placeholder', 'inputs', 'select', 'choice', 'file', 'terms', 'section', 'button', 'error', 'success' ];
			var bfSections = [
				{ id: 'container', title: advLabel( 'secContainer' ), controls: [
					{ type: 'slider', var: '--bf-form-max-width', label: 'maxWidth', min: 0, max: 1400, units: [ 'px', '%' ] },
						{ type: 'align', var: '--bf-form-align', label: 'alignment' },
					{ type: 'dimension', var: '--bf-form-padding', label: 'padding' },
					{ type: 'background', var: '--bf-form-bg', label: 'background' },
					{ type: 'border', var: '--bf-form-border', label: 'border' },
					{ type: 'dimension', var: '--bf-form-radius', label: 'borderRadius', units: [ 'px', '%' ] },
					{ type: 'shadow', var: '--bf-form-shadow', label: 'boxShadow', states: [ [ '--bf-form-shadow', 'stateNormal' ], [ '--bf-form-shadow-hover', 'stateHover' ] ] }
				] },
				{ id: 'layout', title: advLabel( 'secLayout' ), controls: [
					{ type: 'slider', var: '--bf-row-gap', label: 'rowGap', min: 0, max: 100, units: [ 'px', 'em' ] },
					{ type: 'slider', var: '--bf-col-gap', label: 'columnGap', min: 0, max: 80, units: [ 'px' ] },
					{ type: 'dimension', var: '--bf-field-margin', label: 'fieldMargin' },
					{ type: 'dimension', var: '--bf-input-margin', label: 'inputMargin' },
						{ type: 'slider', var: '--bf-subfield-gap', label: 'subfieldGap', min: 0, max: 60, units: [ 'px', 'em' ] }
				] },
				{ id: 'labels', title: advLabel( 'secLabels' ), controls: [
					{ type: 'stateTabs', label: 'states', states: [
						{ key: 'normal', label: 'stateNormal', controls: [
							{ type: 'color', var: '--bf-label-color', label: 'labelColor' },
							{ type: 'color', var: '--bf-sublabel-color', label: 'subLabelColor' }
						] },
						{ key: 'focus', label: 'stateFocus', controls: [
							{ type: 'color', var: '--bf-label-focus-color', label: 'labelColor' },
							{ type: 'color', var: '--bf-sublabel-focus-color', label: 'subLabelColor' }
						] }
					] },
					{ type: 'typography', var: '--bf-label', label: 'typography' },
					{ type: 'dimension', var: '--bf-label-margin', label: 'margin' },
					{ type: 'color', var: '--bf-required-color', label: 'requiredColor' },
					{ type: 'heading', label: 'subLabel' },
					{ type: 'typography', var: '--bf-sublabel', label: 'typography' }
				] },
				{ id: 'inputs', title: advLabel( 'secInputs' ), controls: [
					{ type: 'slider', var: '--bf-field-height', label: 'height', min: 24, max: 90, units: [ 'px' ] },
					{ type: 'slider', var: '--bf-textarea-height', label: 'textareaHeight', min: 40, max: 400, units: [ 'px' ] },
					{ type: 'dimension', var: '--bf-field-padding', label: 'padding' },
					{ type: 'typography', var: '--bf-field', label: 'typography' },
					{ type: 'stateTabs', label: 'states', states: [
						{ key: 'normal', label: 'stateNormal', controls: [
							{ type: 'background', var: '--bf-field-bg', label: 'background' },
							{ type: 'color', var: '--bf-field-text', label: 'textColor' },
							{ type: 'border', var: '--bf-field-border', colorVar: '--bf-field-border', label: 'border' },
							{ type: 'shadow', var: '--bf-field-shadow', label: 'boxShadow' }
						] },
						{ key: 'hover', label: 'stateHover', controls: [
							{ type: 'background', var: '--bf-field-hover-bg', label: 'background' },
							{ type: 'color', var: '--bf-field-hover-text', label: 'textColor' },
							{ type: 'border', var: '--bf-field-hover-border', colorVar: '--bf-field-hover-border', label: 'border' },
							{ type: 'shadow', var: '--bf-field-hover-shadow', label: 'boxShadow' }
						] },
						{ key: 'focus', label: 'stateFocus', controls: [
							{ type: 'background', var: '--bf-field-focus-bg', label: 'background' },
							{ type: 'color', var: '--bf-field-focus-text', label: 'textColor' },
							{ type: 'border', var: '--bf-field-focus-border', colorVar: '--bf-field-focus-border', label: 'border' },
							{ type: 'shadow', var: '--bf-field-focus-shadow', label: 'boxShadow' }
						] }
					] },
					{ type: 'dimension', var: '--bf-field-radius', label: 'borderRadius', units: [ 'px', '%' ] }
				] },
				{ id: 'button', title: advLabel( 'secButton' ), controls: [
					{ type: 'switch', var: '--bf-btn-width', label: 'fullWidth', on: '100%' },
					{ type: 'dimension', var: '--bf-button-padding', label: 'padding' },
					{ type: 'typography', var: '--bf-btn', label: 'typography' },
					{ type: 'stateTabs', label: 'states', states: [
						{ key: 'normal', label: 'stateNormal', controls: [
							{ type: 'background', var: '--bf-button-bg', label: 'background' },
							{ type: 'color', var: '--bf-button-text', label: 'textColor' },
							{ type: 'border', var: '--bf-button-border', colorVar: '--bf-button-border', label: 'border' },
							{ type: 'shadow', var: '--bf-button-shadow', label: 'boxShadow' }
						] },
						{ key: 'hover', label: 'stateHover', controls: [
							{ type: 'background', var: '--bf-btn-hover-bg', label: 'background' },
							{ type: 'color', var: '--bf-btn-hover-text', label: 'textColor' },
							{ type: 'border', var: '--bf-btn-hover-border', colorVar: '--bf-btn-hover-border', label: 'border' },
							{ type: 'shadow', var: '--bf-button-hover-shadow', label: 'boxShadow' }
						] }
					] },
					{ type: 'dimension', var: '--bf-button-radius', label: 'borderRadius', units: [ 'px', '%' ] },
					{ type: 'color', var: '--bf-btn-icon-color', label: 'iconColor' },
					{ type: 'dimension', var: '--bf-btn-margin', label: 'buttonMargin' },
					{ type: 'dimension', var: '--bf-actions-margin', label: 'containerMargin' }
				] },
				// Checkbox & Radio. The Style control at the top decides which half of
				// this section is shown: the box treatment (Default) or the pill
				// treatment (Button). Only one is ever relevant, and showing both
				// would offer controls that demonstrably do nothing in the active
				// mode. `extraVars` still names BOTH sets so the section's reset
				// clears whichever is currently hidden too — otherwise switching
				// modes could resurrect values the user thought they had cleared.
				{ id: 'choice', title: advLabel( 'secChoice' ), extraVars: bfChoiceAllVars(), controls: bfChoiceControls( true ) },
				{ id: 'terms', title: advLabel( 'secTerms' ), controls: [
					{ type: 'color', var: '--bf-terms-color', label: 'textColor' },
					{ type: 'stateTabs', label: 'linkColor', states: [
						{ key: 'normal', label: 'stateNormal', controls: [
							{ type: 'color', var: '--bf-terms-link', label: 'linkColor' }
						] },
						{ key: 'hover', label: 'stateHover', controls: [
							{ type: 'color', var: '--bf-terms-link-hover', label: 'linkColor' }
						] }
					] },
					{ type: 'typography', var: '--bf-terms', label: 'typography' },
					{ type: 'typography', var: '--bf-terms-copy', label: 'copyText' },
					{ type: 'heading', label: 'secChoice' },
					{ type: 'slider', var: '--bf-terms-box-size', label: 'size', min: 12, max: 32, units: [ 'px' ] },
					{ type: 'stateTabs', label: 'states', states: [
						{ key: 'normal', label: 'stateNormal', controls: [
							{ type: 'color', var: '--bf-terms-box-border', label: 'borderColor' },
							{ type: 'background', var: '--bf-terms-box-bg', label: 'background' }
						] },
						{ key: 'hover', label: 'stateHover', controls: [
							{ type: 'color', var: '--bf-terms-box-hover-border', label: 'borderColor' },
							{ type: 'background', var: '--bf-terms-box-hover-bg', label: 'background' }
						] },
						{ key: 'checked', label: 'stateChecked', controls: [
							{ type: 'color', var: '--bf-terms-box-checked', label: 'checkedColor' },
							{ type: 'color', var: '--bf-terms-box-icon', label: 'iconColor' }
						] }
					] },
					{ type: 'slider', var: '--bf-terms-box-border-width', label: 'borderWidth', min: 1, max: 5, units: [ 'px' ] },
					{ type: 'slider', var: '--bf-terms-box-radius', label: 'borderRadius', min: 0, max: 12, units: [ 'px' ] },
					{ type: 'slider', var: '--bf-terms-gap', label: 'gap', min: 0, max: 30, units: [ 'px' ] },
					{ type: 'dimension', var: '--bf-terms-margin', label: 'margin' }
				] },
				{ id: 'file', title: advLabel( 'secFile' ), controls: [
					{ type: 'stateTabs', label: 'states', states: [
						{ key: 'normal', label: 'stateNormal', controls: [
							{ type: 'background', var: '--bf-file-btn-bg', label: 'background' },
							{ type: 'color', var: '--bf-file-btn-text', label: 'textColor' }
						] },
						{ key: 'hover', label: 'stateHover', controls: [
							{ type: 'background', var: '--bf-file-btn-hover-bg', label: 'background' },
							{ type: 'color', var: '--bf-file-btn-hover-text', label: 'textColor' }
						] }
					] }
				] },
				{ id: 'section', title: advLabel( 'secSection' ), controls: [
					{ type: 'dimension', var: '--bf-section-margin', label: 'margin' },
					{ type: 'dimension', var: '--bf-section-padding', label: 'padding' },
					{ type: 'border', var: '--bf-section-border', label: 'border' },
					{ type: 'dimension', var: '--bf-section-radius', label: 'borderRadius', units: [ 'px', '%' ] },
					{ type: 'heading', label: 'titleColor' },
					{ type: 'color', var: '--bf-section-title-color', label: 'titleColor' },
					{ type: 'typography', var: '--bf-section-title', label: 'typography' },
					{ type: 'dimension', var: '--bf-section-title-margin', label: 'margin' },
					{ type: 'heading', label: 'descColor' },
					{ type: 'color', var: '--bf-section-desc-color', label: 'descColor' },
					{ type: 'typography', var: '--bf-section-desc', label: 'typography' },
					{ type: 'dimension', var: '--bf-section-desc-margin', label: 'margin' }
				] },
				{ id: 'select', title: advLabel( 'secSelect' ), controls: [
					{ type: 'color', var: '--bf-select-ph', label: 'placeholderColor' },
					{ type: 'color', var: '--bf-select-arrow', label: 'arrowColor' },
					{ type: 'typography', var: '--bf-select', label: 'typography' },
					{ type: 'dimension', var: '--bf-select-radius', label: 'borderRadius', units: [ 'px', '%' ] },
					{ type: 'dimension', var: '--bf-select-padding', label: 'padding' },
					{ type: 'stateTabs', label: 'states', states: [
						{ key: 'normal', label: 'stateNormal', controls: [
							{ type: 'background', var: '--bf-select-bg', label: 'background' },
							{ type: 'color', var: '--bf-select-text', label: 'textColor' },
							{ type: 'border', var: '--bf-select-border', label: 'border' },
							{ type: 'shadow', var: '--bf-select-shadow', label: 'boxShadow' }
						] },
						{ key: 'hover', label: 'stateHover', controls: [
							{ type: 'color', var: '--bf-select-hover-border', label: 'borderColor' }
						] },
						{ key: 'focus', label: 'stateFocus', controls: [
							{ type: 'color', var: '--bf-select-focus-border', label: 'borderColor' },
							{ type: 'background', var: '--bf-select-focus-bg', label: 'background' },
							{ type: 'color', var: '--bf-select-focus-ring', label: 'ringColor' }
						] }
					] },
					{ type: 'heading', label: 'panelBg' },
					{ type: 'background', var: '--bf-select-panel-bg', label: 'panelBg' },
					{ type: 'color', var: '--bf-select-panel-border', label: 'panelBorder' },
					{ type: 'dimension', var: '--bf-select-panel-radius', label: 'borderRadius', units: [ 'px', '%' ] },
					{ type: 'shadow', var: '--bf-select-panel-shadow', label: 'boxShadow' },
					{ type: 'heading', label: 'searchBox' },
					{ type: 'background', var: '--bf-select-search-bg', label: 'searchBg' },
					{ type: 'color', var: '--bf-select-search-text', label: 'searchText' },
					{ type: 'color', var: '--bf-select-search-ph', label: 'searchPh' },
					{ type: 'heading', label: 'optionText' },
					{ type: 'typography', var: '--bf-select-opt', label: 'typography' },
					{ type: 'color', var: '--bf-select-check', label: 'accentColor' },
					{ type: 'stateTabs', label: 'states', states: [
						{ key: 'normal', label: 'stateNormal', controls: [
							{ type: 'color', var: '--bf-select-opt-bg', label: 'background' },
							{ type: 'color', var: '--bf-select-opt-color', label: 'textColor' }
						] },
						{ key: 'hover', label: 'stateHover', controls: [
							{ type: 'color', var: '--bf-select-opt-hover-bg', label: 'background' },
							{ type: 'color', var: '--bf-select-opt-hover-color', label: 'textColor' }
						] },
						{ key: 'selected', label: 'stateSelected', controls: [
							{ type: 'color', var: '--bf-select-opt-active-bg', label: 'background' },
							{ type: 'color', var: '--bf-select-opt-active-color', label: 'textColor' }
						] }
					] }
				] },
				{ id: 'placeholder', title: advLabel( 'secPlaceholder' ), controls: [
					{ type: 'color', var: '--bf-ph-color', label: 'placeholderColor' },
					{ type: 'typography', var: '--bf-ph', label: 'typography' }
				] },
				{ id: 'error', title: advLabel( 'secError' ), controls: [
					{ type: 'color', var: '--bf-error-color', label: 'textColor' },
					{ type: 'typography', var: '--bf-error', label: 'typography' },
					{ type: 'heading', label: 'noticeBg' },
					{ type: 'background', var: '--bf-msg-error-bg', label: 'noticeBg' },
					{ type: 'color', var: '--bf-msg-error-text', label: 'noticeText' },
					{ type: 'dimension', var: '--bf-msg-error-padding', label: 'padding' },
						{ type: 'dimension', var: '--bf-msg-error-margin', label: 'margin' },
					{ type: 'border', var: '--bf-msg-error-border', label: 'border' },
					{ type: 'dimension', var: '--bf-msg-error-radius', label: 'borderRadius', units: [ 'px', '%' ] }
				] },
				{ id: 'success', title: advLabel( 'secSuccess' ), controls: [
					{ type: 'background', var: '--bf-msg-success-bg', label: 'noticeBg' },
					{ type: 'color', var: '--bf-msg-success-text', label: 'noticeText' },
					{ type: 'typography', var: '--bf-msg-success', label: 'typography' },
					{ type: 'dimension', var: '--bf-msg-success-padding', label: 'padding' },
						{ type: 'dimension', var: '--bf-msg-success-margin', label: 'margin' },
					{ type: 'border', var: '--bf-msg-success-border', label: 'border' },
					{ type: 'dimension', var: '--bf-msg-success-radius', label: 'borderRadius', units: [ 'px', '%' ] },
					{ type: 'shadow', var: '--bf-msg-success-shadow', label: 'boxShadow' }
				] }
			];
			bfSections.sort( function ( a, b ) {
				return bfSectionOrder.indexOf( a.id ) - bfSectionOrder.indexOf( b.id );
			} );
			return bfSections;
		}

		// Every --bf-* var a section's controls write, expanded across composite types
		// (border → -width/-style/-color, typography → -ff/-fs/…, shadow → each state).
		// Every var the Checkbox & Radio section can write, in EITHER style mode.
		//
		// The section only renders one mode's controls at a time, so bfSectionVars()
		// walking the visible controls would miss the other half. Reset and the
		// "has anything to reset?" check both need the full set: a user who styled
		// the buttons, switched back to Default and hit reset expects the button
		// values gone too, not lying in wait for the next switch.
		// The Checkbox & Radio control list, shared by the Style tab (which owns the
		// form-wide defaults) and by a single field's "Options appearance" block. One
		// definition is the point: the two surfaces stay identical because there is
		// only one list, and a control added here appears in both.
		//
		// `withModeSwitch` is the Default/Button chooser, which is a form-level
		// decision — a field overrides colours within the chosen treatment, it does
		// not pick a different treatment of its own.
		function bfChoiceControls( withModeSwitch, mode ) {
			mode = mode || ( 'button' === state.formSettings.choice_style ? 'button' : 'default' );
			return ( withModeSwitch ? [ { type: 'choiceStyle', label: 'choiceStyle' } ] : [] ).concat( [
					{ type: 'color', var: '--bf-choice-color', label: 'labelColor' },
					{ type: 'typography', var: '--bf-choice', label: 'typography' }
				].concat( 'button' === mode ? [
					{ type: 'dimension', var: '--bf-choice-btn-padding', label: 'padding' },
					{ type: 'dimension', var: '--bf-choice-btn-radius', label: 'borderRadius', units: [ 'px', '%' ] },
					{ type: 'slider', var: '--bf-choice-btn-gap', label: 'spacing', min: 0, max: 40, units: [ 'px' ] },
					{ type: 'border', var: '--bf-choice-btn-border', label: 'border' },
					{ type: 'stateTabs', label: 'states', states: [
						{ key: 'normal', label: 'stateNormal', controls: [
							{ type: 'background', var: '--bf-choice-btn-bg', label: 'background' },
							{ type: 'color', var: '--bf-choice-btn-text', label: 'textColor' }
						] },
						{ key: 'hover', label: 'stateHover', controls: [
							{ type: 'background', var: '--bf-choice-btn-hover-bg', label: 'background' },
							{ type: 'color', var: '--bf-choice-btn-hover-text', label: 'textColor' },
							{ type: 'color', var: '--bf-choice-btn-hover-border', label: 'borderColor' }
						] },
						{ key: 'selected', label: 'stateSelected', controls: [
							{ type: 'background', var: '--bf-choice-btn-active-bg', label: 'background' },
							{ type: 'color', var: '--bf-choice-btn-active-text', label: 'textColor' },
							{ type: 'color', var: '--bf-choice-btn-active-border', label: 'borderColor' }
						] }
					] }
				] : [
					{ type: 'slider', var: '--bf-choice-size', label: 'size', min: 12, max: 32, units: [ 'px' ] },
					{ type: 'slider', var: '--bf-choice-gap', label: 'spacing', min: 0, max: 32, units: [ 'px' ] },
					{ type: 'stateTabs', label: 'states', states: [
						{ key: 'normal', label: 'stateNormal', controls: [
							{ type: 'color', var: '--bf-choice-border', label: 'borderColor' },
							{ type: 'background', var: '--bf-choice-bg', label: 'background' }
						] },
						{ key: 'hover', label: 'stateHover', controls: [
							{ type: 'color', var: '--bf-choice-hover-border', label: 'borderColor' },
							{ type: 'background', var: '--bf-choice-hover-bg', label: 'background' }
						] },
						{ key: 'checked', label: 'stateChecked', controls: [
							{ type: 'color', var: '--bf-choice-accent', label: 'accentColor' },
							{ type: 'color', var: '--bf-choice-icon', label: 'iconColor' }
						] }
					] }
				] ) );
		}

		function bfChoiceAllVars() {
			return [
				// Default (box) treatment.
				'--bf-choice-size', '--bf-choice-gap',
				'--bf-choice-border', '--bf-choice-bg',
				'--bf-choice-hover-border', '--bf-choice-hover-bg',
				'--bf-choice-accent', '--bf-choice-icon',
				// Button (pill) treatment.
				'--bf-choice-btn-padding', '--bf-choice-btn-radius', '--bf-choice-btn-gap',
				'--bf-choice-btn-border-width', '--bf-choice-btn-border-style', '--bf-choice-btn-border-color',
				'--bf-choice-btn-bg', '--bf-choice-btn-text',
				'--bf-choice-btn-hover-bg', '--bf-choice-btn-hover-text', '--bf-choice-btn-hover-border',
				'--bf-choice-btn-active-bg', '--bf-choice-btn-active-text', '--bf-choice-btn-active-border'
			];
		}

		function bfSectionVars( sec ) {
			var vars = [];
			function expand( c ) {
				if ( 'stateTabs' === c.type && c.states ) {
					// Recurse into each state panel's controls.
					c.states.forEach( function ( s ) { ( s.controls || [] ).forEach( expand ); } );
					return;
				}
				if ( ! c.var ) { return; }
				if ( 'border' === c.type ) {
					vars.push( c.var + '-width', c.var + '-style', c.colorVar || ( c.var + '-color' ) );
				} else if ( 'typography' === c.type ) {
					[ '-ff', '-fs', '-fw', '-lh', '-ls', '-tt' ].forEach( function ( sfx ) { vars.push( c.var + sfx ); } );
				} else if ( 'shadow' === c.type && c.states && c.states.length ) {
					c.states.forEach( function ( st ) { vars.push( st[0] ); } );
				} else if ( 'align' === c.type ) {
					// The align control also derives margin-left/right + a max-width override.
					var ab = c.var.replace( /-align$/, '' );
					vars.push( c.var, ab + '-ml', ab + '-mr', ab + '-mw' );
				} else {
					vars.push( c.var );
				}
			}
			( sec.controls || [] ).forEach( expand );
			// Vars a section owns but does not currently render a control for (see
			// bfChoiceAllVars). Deduped so a var named in both places is cleared once.
			( sec.extraVars || [] ).forEach( function ( v ) {
				if ( -1 === vars.indexOf( v ) ) { vars.push( v ); }
			} );
			return vars;
		}

		// Every schema var that carries a COLOUR, as { name, label } — the set a
		// Design Theme is entitled to reclaim.
		//
		// Why this exists: the colour fallback chain is
		//   var(--bf-choice-accent, var(--bf-button-bg, var(--bf-focus-color, …)))
		// and a Design Theme only writes position 2 (--bf-button-bg, via the legacy
		// button_background_color key). An explicit style-layer value in position 1
		// therefore outranks the theme permanently, so switching themes appears to do
		// nothing for that control. Clearing these lets the theme's value through.
		//
		// Deliberately EXCLUDES:
		//  - 'shadow' — its var holds a composite "x y blur spread colour", so clearing
		//    one would discard geometry the user tuned, not just a colour.
		//  - border -width / -style — only the colour half of a border control is taken.
		//  - typography / slider / dimension / align / switch — not colours.
		// Memoized: the schema and its labels are static for the life of the page, and
		// this is walked on every colour edit via bfRefreshResetStates().
		var bfThemeColorVarsCache = null;
		function bfThemeColorVars() {
			if ( bfThemeColorVarsCache ) { return bfThemeColorVarsCache; }
			var out = [];
			bfStyleSchema().forEach( function ( sec ) {
				function expand( c ) {
					if ( 'stateTabs' === c.type && c.states ) {
						c.states.forEach( function ( s ) { ( s.controls || [] ).forEach( expand ); } );
						return;
					}
					if ( ! c.var ) { return; }
					var name = null;
					if ( 'color' === c.type || 'background' === c.type ) {
						name = c.var;
					} else if ( 'border' === c.type ) {
						name = c.colorVar || ( c.var + '-color' );
					}
					if ( ! name ) { return; }
					out.push( { name: name, label: sec.title + ' · ' + advLabel( c.label ) } );
				}
				( sec.controls || [] ).forEach( expand );
			} );
			bfThemeColorVarsCache = out;
			return out;
		}

		// Non-empty colour overrides across EVERY device layer (not just the active
		// one) — a tablet-only override would otherwise silently survive a theme
		// switch and reappear at that breakpoint. Deduped by var name so one entry
		// is reported per colour regardless of how many breakpoints carry it.
		function bfThemeColorConflicts() {
			var style = state.formSettings.style || {};
			var seen = {}, out = [];
			bfThemeColorVars().forEach( function ( v ) {
				Object.keys( style ).forEach( function ( device ) {
					var layer = style[ device ] || {};
					var val = layer[ v.name ];
					if ( 'string' !== typeof val || '' === val || seen[ v.name ] ) { return; }
					seen[ v.name ] = true;
					out.push( { name: v.name, label: v.label, value: val } );
				} );
			} );
			return out;
		}

		// Drop every colour override from all three device layers so the freshly
		// applied theme is what actually renders.
		function bfClearThemeColorOverrides() {
			var style = state.formSettings.style || {};
			var names = bfThemeColorVars().map( function ( v ) { return v.name; } );
			Object.keys( style ).forEach( function ( device ) {
				var layer = style[ device ];
				if ( ! layer ) { return; }
				names.forEach( function ( n ) { delete layer[ n ]; } );
			} );
		}

		// True when the active device layer holds any non-empty value for this
		// section's vars — i.e. there is something for its reset button to clear.
		function bfSectionHasValues( sec ) {
			// Style: Button is a section value like any other even though it lives
			// outside the style layer — without this the reset button stays greyed
			// on a section that visibly has something to reset.
			if ( 'choice' === sec.id && 'button' === state.formSettings.choice_style ) {
				return true;
			}
			var layer = ( state.formSettings.style && state.formSettings.style[ state.activeDevice ] ) || {};
			return bfSectionVars( sec ).some( function ( v ) {
				return typeof layer[ v ] === 'string' && '' !== layer[ v ];
			} );
		}

		// Enable a section's reset button only when it has something to reset;
		// otherwise show it greyed + non-clickable. Covers every schema section plus
		// the Design Theme card (changed = a theme other than the default is active).
		function bfRefreshResetStates() {
			bfStyleSchema().forEach( function ( sec ) {
				var has = bfSectionHasValues( sec );
				$( '.shapeforms-style-section__reset[data-reset-section="' + sec.id + '"]' )
					.toggleClass( 'is-disabled', ! has )
					.prop( 'disabled', ! has )
					.attr( 'aria-disabled', has ? 'false' : 'true' );
			} );
			// Enabled when the active theme differs from the default, OR when colour
			// overrides are currently outranking the theme — otherwise a form sitting
			// on Default Blue with stale overrides would have no way to reclaim them.
			var themeChanged = ( !! state.formSettings.design_theme && 'default-blue' !== state.formSettings.design_theme ) ||
				bfThemeColorConflicts().length > 0;
			$( '.shapeforms-theme-reset' )
				.toggleClass( 'is-disabled', ! themeChanged )
				.prop( 'disabled', ! themeChanged )
				.attr( 'aria-disabled', themeChanged ? 'false' : 'true' );
		}

		// Within each heading-delimited segment of a section, render the non-state
		// controls first and the Normal/Hover/… state-tab groups last, so the tabs
		// never sit above unrelated non-state controls (which made those read as
		// state-specific and confused users). Headings start a new segment, so
		// sub-grouped sections (Select's panel/options groups, Terms' link vs
		// checkbox groups) keep their internal structure.
		function bfOrderControls( controls ) {
			var out = [], seg = [];
			function flush() {
				var rest = [], tabs = [];
				seg.forEach( function ( c ) { ( 'stateTabs' === c.type ? tabs : rest ).push( c ); } );
				out = out.concat( rest, tabs );
				seg = [];
			}
			( controls || [] ).forEach( function ( c ) {
				if ( 'heading' === c.type ) { flush(); }
				seg.push( c );
			} );
			flush();
			return out;
		}

		function renderAdvancedStyleSections() {
			var html = '';
			var resetSvg = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg>';
			bfStyleSchema().forEach( function ( sec ) {
				var body = '';
				bfOrderControls( sec.controls ).forEach( function ( c ) { body += advControl( c ); } );
				var rLabel = escapeHtml( advLabel( 'resetSection' ) );
				html += '<div class="shapeforms-style-section" data-adv-section="' + sec.id + '">' +
					'<div class="shapeforms-style-section__head"><h3>' + escapeHtml( sec.title ) + '</h3>' +
						'<div class="shapeforms-style-section__head-actions">' +
							'<button type="button" class="shapeforms-style-section__reset" data-reset-section="' + sec.id + '" title="' + rLabel + '" aria-label="' + rLabel + '">' + resetSvg + '</button>' +
							'<span class="dashicons dashicons-arrow-down-alt2"></span>' +
						'</div>' +
					'</div>' +
					'<div class="shapeforms-style-section__body"><div class="shapeforms-adv-grid">' + body + '</div></div>' +
				'</div>';
			} );
			return html;
		}

		// Re-render ONE Style section's controls in place.
		//
		// Almost every control writes a CSS var and needs no re-render at all. The
		// exception is a control that changes which OTHER controls the section offers —
		// Checkbox & Radio's Style, whose Button mode swaps in a different set. Calling
		// renderStylingSettings() for that would work, but the accordion's open/closed
		// state lives only in the DOM as an `is-open` class: rebuilding the panel throws
		// it away, collapsing the group being edited and springing the first one open.
		// That reads as an unexplained page refresh. Replacing just the control grid
		// keeps the section open, the panel scrolled where it was, and every other
		// section untouched. The schema is re-read by id, so a caller that has already
		// changed the setting gets the new control set.
		function bfRerenderStyleSection( sectionId ) {
			var sec = null;
			bfStyleSchema().forEach( function ( s ) { if ( s.id === sectionId ) { sec = s; } } );
			if ( ! sec ) { return; }

			var $grid = $( '#shapeforms-form-styling-panel [data-adv-section="' + sectionId + '"] .shapeforms-adv-grid' ).first();
			if ( ! $grid.length ) { return; }

			var body = '';
			bfOrderControls( sec.controls ).forEach( function ( c ) { body += advControl( c ); } );
			$grid.html( body );
		}

		// Render a live, read-only mirror of the form on the Style tab so styling
		// changes preview instantly. Reuses the canvas field structure
		// (.shapeforms-canvas ancestor + .shapeforms-canvas-field > -field-body) so the
		// same --bf-* variable styling applies, minus the editing chrome.
		// Sample state previews appended below the form: messages, an open dropdown and a
		// file button only appear on submit / interaction, so they can't render from the
		// live form alone — these labelled samples let their --bf-* styles preview too.
		function bfPreviewStatesMarkup() {
			var chk = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>';
			var caret = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>';
			return '<div class="shapeforms-preview-states" aria-hidden="true">' +
				'<div class="shapeforms-preview-states__cap">' + escapeHtml( advLabel( 'previewStates' ) ) + '</div>' +
				'<div class="shapeforms-lite-form__message is-success is-visible">' + escapeHtml( advLabel( 'sampleSuccess' ) ) + '</div>' +
				'<div class="shapeforms-lite-form__message is-error is-visible">' + escapeHtml( advLabel( 'sampleError' ) ) + '</div>' +
				'<div class="shapeforms-preview-states__field bf-state-invalid">' +
					'<label>' + escapeHtml( advLabel( 'sampleFieldLabel' ) ) + '</label>' +
					'<input type="text" value="' + escapeHtml( advLabel( 'sampleValue' ) ) + '" readonly>' +
					'<div class="shapeforms-lite-form__field-error">' + escapeHtml( advLabel( 'sampleRequired' ) ) + '</div>' +
				'</div>' +
				'<div class="shapeforms-preview-states__field">' +
					'<label>' + escapeHtml( advLabel( 'sampleDropdown' ) ) + '</label>' +
					'<div class="bf-select is-open">' +
						'<div class="bf-select__trigger"><span class="bf-select__value">' + escapeHtml( advLabel( 'optionSelected' ) ) + '</span><span class="bf-select__arrow">' + caret + '</span></div>' +
						'<div class="bf-select__panel">' +
							'<div class="bf-select__search-wrap"><input class="bf-select__panel-search" type="text" placeholder="' + escapeHtml( advLabel( 'sampleSearch' ) ) + '" readonly></div>' +
							'<div class="bf-select__list">' +
								'<div class="bf-select__option is-active"><span class="bf-select__check">' + chk + '</span><span class="bf-select__option-text">' + escapeHtml( advLabel( 'optionSelected' ) ) + '</span></div>' +
								'<div class="bf-select__option"><span class="bf-select__check"></span><span class="bf-select__option-text">' + escapeHtml( advLabel( 'optionAnother' ) ) + '</span></div>' +
							'</div>' +
						'</div>' +
					'</div>' +
				'</div>' +
			'</div>';
		}

		/**
		 * The Style-tab preview when conversational mode is on.
		 *
		 * Shows the SELECTED screen exactly as a visitor meets it — progress, the
		 * question, the Next button and the key hint — so the six colours can be
		 * judged against the thing they actually paint. It reuses the real
		 * .shapeforms-cv class names and the real --bfc-* custom properties, so it
		 * cannot drift from the front end the way a hand-drawn mock would.
		 *
		 * It follows the selection rather than pinning screen 1 because screens
		 * can now differ from one another: an author styling screen 4 while
		 * watching screen 1 not change is worse served by a preview than by none.
		 *
		 * Deliberately static: this is a colour preview, not a second engine.
		 */
		function cvPreviewMarkup() {
			var screens = cvScreens();
			var total   = screens.length;

			if ( ! total ) {
				return '<div class="shapeforms-style-preview-empty">' +
					escapeHtml( shapeformsLiteBuilder.labels.stylePreviewEmpty || 'Add fields in the Builder tab to preview your styling here.' ) +
				'</div>';
			}

			// Which screen the author is looking at. A field that never becomes a
			// screen (hidden, page break) maps to nothing, so screen 1 stands in.
			var index = ( cvScreenMap().byField[ state.selectedFieldId ] || 1 ) - 1;

			if ( index < 0 || index >= total ) {
				index = 0;
			}

			var screen = screens[ index ];
			var shown  = index + 1;

			var lookup = {};
			getAllRows().forEach( function ( row ) {
				( row.columns || [] ).forEach( function ( column ) {
					( column.fields || [] ).forEach( function ( field ) { lookup[ field.id ] = field; } );
				} );
			} );

			// Whichever object IS this screen — the same resolution the panel and
			// the picker use.
			var owner = screen.isRow
				? ( getAllRows()[ screen.rowIndex ] || {} )
				: ( lookup[ screen.fieldIds[ 0 ] ] || {} );

			/** Emits only the colours that are SET: '' has to inherit here too. */
			function cvVars( source, map ) {
				return map.filter( function ( pair ) {
					return !! source[ pair[ 0 ] ];
				} ).map( function ( pair ) {
					return pair[ 1 ] + ':' + source[ pair[ 0 ] ];
				} ).join( ';' );
			}

			// Same property names build_custom_properties() emits, so an unset
			// colour inherits here exactly as it does on the front end.
			var vars = cvVars( state.formSettings, [
				[ 'cv_bg', '--bfc-bg' ],
				[ 'cv_question_color', '--bfc-question' ],
				[ 'cv_answer_color', '--bfc-answer' ],
				[ 'cv_btn_color', '--bfc-btn' ],
				[ 'cv_btn_text_color', '--bfc-btn-text' ],
				[ 'cv_accent', '--bfc-accent' ],
				// Wrapper only. The screen map below deliberately does not carry
				// these — the progress row and the nav are not inside a screen, so
				// a screen-level value could never apply.
				[ 'cv_track_color', '--bfc-track' ],
				[ 'cv_prev_color', '--bfc-prev' ],
				[ 'cv_prev_bg', '--bfc-prev-bg' ],
				[ 'cv_hint_color', '--bfc-hint' ]
			] );

			// The hint's dimming is cancelled by a second property, emitted only
			// when a colour is set — mirroring build_custom_properties(). Without
			// this the preview shows the author a faded version of the colour
			// they picked while the page shows the colour itself, and the pane
			// that exists to answer "what will this look like" answers wrongly.
			if ( state.formSettings.cv_hint_color ) {
				vars += ( vars ? ';' : '' ) + '--bfc-hint-opacity:1';
			}

			// The screen's own overrides, on the screen element — the same names
			// on the same element as the front end, so an override previews
			// exactly as it renders. cv_bg becomes --bfc-screen-bg here for the
			// same reason it does there: --bfc-bg paints the wrapper, and the
			// screen sits inside it.
			var screenVars = cvVars( owner, [
				[ 'cv_bg', '--bfc-screen-bg' ],
				[ 'cv_question_color', '--bfc-question' ],
				[ 'cv_answer_color', '--bfc-answer' ]
			] );

			/**
			 * The screen's image, as the page will build it.
			 *
			 * A DELIBERATE MIRROR of build_row_media_attrs() in the shortcode and
			 * of attachMedia() in the engine, which between them decide three
			 * things on the front end: the placement classes go on the SCREEN,
			 * the --bfc-media-* properties go on the SCREEN, and the <figure> is
			 * the screen's FIRST child. Any of the three in the wrong place and
			 * the stylesheet — which is the same stylesheet — lays it out
			 * differently here than it does on the page.
			 *
			 * Reads cv_media_preview, the one media key the server resolves into
			 * the builder's structure and never persists. The front end resolves
			 * the attachment ID instead, so the preview shows a medium file where
			 * the page shows a large one; that is a difference in bytes, not in
			 * layout, and nothing here depends on the size.
			 */
			function cvMediaPreview( source ) {
				var none   = { cls: '', vars: '', figure: '' };
				var layout = source.cv_media_layout || 'none';

				if ( [ 'left', 'right', 'background' ].indexOf( layout ) === -1 ) {
					return none;
				}

				// Same two-part test the renderer applies: an ID with no
				// resolvable file degrades to no media, never a broken image.
				if ( ! source.cv_media_id || ! source.cv_media_preview ) {
					return none;
				}

				var clamp = function ( value, lo, hi, fallback ) {
					var n = parseInt( value, 10 );
					return isNaN( n ) ? fallback : Math.max( lo, Math.min( hi, n ) );
				};

				var x    = clamp( source.cv_media_x, 0, 100, 50 );
				var y    = clamp( source.cv_media_y, 0, 100, 50 );
				var dim  = clamp( source.cv_media_brightness, 0, 100, 100 );
				// 0 means "use the stylesheet's default", so the property is
				// omitted entirely rather than set to a number — the same
				// contract the empty string carries for the colours above.
				var high = source.cv_media_height > 0 ? clamp( source.cv_media_height, 80, 2000, 0 ) : 0;
				var alt  = source.cv_media_alt || '';

				var vars = '--bfc-media-x:' + x + '%;--bfc-media-y:' + y + '%' +
					';--bfc-media-dim:' + ( dim / 100 ).toFixed( 2 ) +
					( high ? ';--bfc-media-h:' + high + 'px' : '' );

				return {
					cls:  ' shapeforms-lite-form__row--media shapeforms-lite-form__row--media-' + layout,
					vars: vars,
					// Decorative when the author supplied no alt text, exactly as
					// the renderer decides it.
					figure: '<figure class="shapeforms-lite-form__row-media" aria-hidden="' + ( alt ? 'false' : 'true' ) + '">' +
						'<img src="' + escapeHtml( source.cv_media_preview ) + '" alt="' + escapeHtml( alt ) + '" loading="lazy" decoding="async">' +
					'</figure>'
				};
			}

			var media = cvMediaPreview( owner );

			if ( media.vars ) {
				screenVars += ( screenVars ? ';' : '' ) + media.vars;
			}

			var progress   = state.formSettings.cv_progress || 'bar';
			var transition = state.formSettings.cv_transition || 'slide';
			var nextText   = state.formSettings.cv_next_text || cvDefaultLabel( 'next' );
			var prevText   = state.formSettings.cv_prev_text || cvDefaultLabel( 'prev' );
			var ratio      = Math.round( ( shown / total ) * 100 );

			var progressMarkup = '';
			if ( 'bar' === progress ) {
				progressMarkup = '<div class="shapeforms-cv__bar"><span class="shapeforms-cv__bar-fill" style="width:' + ratio + '%"></span></div>';
			} else if ( 'dots' === progress ) {
				var dots = '';
				for ( var d = 0; d < total; d++ ) {
					dots += '<span class="shapeforms-cv__dot' + ( d < shown ? ' is-done' : '' ) + '"></span>';
				}
				progressMarkup = '<div class="shapeforms-cv__dots">' + dots + '</div>';
			} else if ( 'counter' === progress ) {
				progressMarkup = '<span class="shapeforms-cv__count">' + shown + ' of ' + total + '</span>';
			} else if ( 'percent' === progress ) {
				progressMarkup = '<span class="shapeforms-cv__count">' + ratio + '% completed</span>';
			}

			if ( progressMarkup ) {
				progressMarkup = '<div class="shapeforms-cv__progress">' + progressMarkup + '</div>';
			}

			// The welcome cover is what a visitor sees first, so it is what the
			// preview opens on — but only while the author is not looking at a
			// particular question. Selecting one on the Builder tab is a request
			// to see THAT screen, and a cover that outranked the selection would
			// leave the question styling with no preview at all, which is the one
			// thing this pane exists to provide.
			var body = '';
			// Asks cvWelcomeTitle() rather than re-deriving the fallback, so the
			// preview and the page can never disagree about what the cover says.
			var welcomeTitle = cvWelcomeTitle();
			var welcomeText  = ( state.formSettings.cv_welcome_text || '' ).trim();
			var isWelcome    = !! state.formSettings.cv_welcome_enabled && ! state.selectedFieldId;

			if ( isWelcome ) {
				body = '<div class="shapeforms-cv__welcome">' +
					( welcomeTitle ? '<h2 class="shapeforms-cv__welcome-title">' + escapeHtml( welcomeTitle ) + '</h2>' : '' ) +
					( welcomeText ? '<div class="shapeforms-cv__welcome-text">' + welcomeText + '</div>' : '' ) +
					'<button type="button" class="shapeforms-cv__btn shapeforms-cv__welcome-btn">' +
						escapeHtml( state.formSettings.cv_welcome_btn || cvDefaultLabel( 'start' ) ) +
					'</button>' +
				'</div>';
			} else {
				var columns = screen.isRow
					? ( ( getAllRows()[ screen.rowIndex ] || {} ).columns || [] ).length || 1
					: 1;

				var fieldsMarkup = '';
				screen.fieldIds.forEach( function ( id ) {
					if ( ! lookup[ id ] ) { return; }
					fieldsMarkup += '<div class="shapeforms-canvas-field"><div class="shapeforms-canvas-field-body">' +
						renderInputPreview( lookup[ id ] ) +
					'</div></div>';
				} );

				body = '<div class="shapeforms-cv__viewport">' +
					'<div class="shapeforms-lite-form__row shapeforms-cv-screen shapeforms-cv-screen--cols-' +
						escapeHtml( columns > 3 ? 'many' : String( columns ) ) + ' is-active' + media.cls + '"' +
						( screenVars ? ' style="' + escapeHtml( screenVars ) + '"' : '' ) + '>' +
						// FIRST, like the engine's insertBefore(): `left` and
						// `right` place it with padding on the screen and an
						// absolute figure, so source order is what decides which
						// of two absolutely-positioned children paints on top.
						media.figure +
						fieldsMarkup +
					'</div>' +
				'</div>' +
				'<div class="shapeforms-cv__nav">' +
					// Back appears on exactly the screens it appears on for a
					// visitor — never on the first — so the alignment an author
					// picks previews as it will render rather than as a mock-up.
					( shown > 1
						? '<button type="button" class="shapeforms-cv__btn shapeforms-cv__btn--prev">' + escapeHtml( prevText ) + '</button>'
						: '' ) +
					'<button type="button" class="shapeforms-cv__btn shapeforms-cv__btn--next">' + escapeHtml( nextText ) + '</button>' +
					( state.formSettings.cv_key_hint
						? '<span class="shapeforms-cv__hint">press <kbd>Enter</kbd></span>'
						: '' ) +
				'</div>';
			}

			// The selection is made on the Builder tab and read here, so without
			// this the author has no way to tell which screen they are judging.
			var caption = '<div class="shapeforms-cv-preview-cap">' +
				escapeHtml( isWelcome
					? 'Welcome screen'
					: 'Screen ' + shown + ' of ' + total ) +
			'</div>';

			// is-ready is not decoration, and there is no engine here to add it.
			//
			// conversational.css reveals a screen only under
			// `.shapeforms-cv.is-ready` — the NO-JS CONTRACT: without JavaScript
			// the form has to stay an ordinary submittable form, so the mode's
			// look and its chrome stay off until the engine is actually running.
			// 53 of that stylesheet's 87 rules sit behind it, including the
			// question type scale, the 44px control floor, the wrapper padding
			// and `display` on the nav.
			//
			// This preview is a hand-built still with no engine, so it says so
			// itself. Without it the author was shown a plain stack of small
			// fields and no OK button — a preview of the no-JS fallback, which
			// is the one state they are not styling.
			return caption +
				'<div class="shapeforms-cv shapeforms-cv--preview is-ready shapeforms-cv--progress-' + escapeHtml( progress ) +
					' shapeforms-cv--nav-' + escapeHtml( state.formSettings.cv_nav_align || 'left' ) +
					' shapeforms-cv--progress-align-' + escapeHtml( state.formSettings.cv_progress_align || 'left' ) +
					// "Hide screen images on phones" is a @media rule on the front
					// end and the preview stage is a NARROWED DIV, not a narrow
					// window, so that rule can never fire in here. builder.css
					// answers this class under .is-device-mobile instead. Emitted
					// unconditionally-shaped like the front end's own gate: absent
					// key means on.
					( ( typeof state.formSettings.cv_media_hide_mobile === 'undefined' || state.formSettings.cv_media_hide_mobile )
						? ' shapeforms-cv--media-hide-mobile'
						: '' ) +
					' shapeforms-cv--motion-' + escapeHtml( transition ) + '"' +
					( vars ? ' style="' + escapeHtml( vars ) + '"' : '' ) + '>' +
				progressMarkup +
				body +
			'</div>';
		}

		function renderStylePreview() {
			var $canvas = $( '#shapeforms-style-preview-canvas' );

			if ( ! $canvas.length ) {
				return;
			}

			var rows = getAllRows();

			// Reflect the live style variables via the shared scoped <style> node.
			applyPreviewStyleBlock();

			if ( ! rows.length ) {
				$canvas.html(
					'<div class="shapeforms-style-preview-empty">' +
						escapeHtml( shapeformsLiteBuilder.labels.stylePreviewEmpty || 'Add fields in the Builder tab to preview your styling here.' ) +
					'</div>'
				);
				return;
			}

			if ( cvIsOn() ) {
				$canvas.html( cvPreviewMarkup() );
				return;
			}

			var markup = '<div class="shapeforms-style-preview-form">';

			rows.forEach(
				function ( row ) {
					markup += '<div class="shapeforms-style-preview-row">';

					row.columns.forEach(
						function ( column ) {
							markup += '<div class="shapeforms-style-preview-column" style="width:' + escapeHtml( column.width ) + ';">';

							column.fields.forEach(
								function ( field ) {
									var fieldClasses = 'shapeforms-canvas-field';
									var fieldLabelPos = field.label_placement || 'top';

									if ( 'top' !== fieldLabelPos ) {
										fieldClasses += ' is-label-' + fieldLabelPos;
									}

									markup += '<div class="' + fieldClasses + '">' +
										'<div class="shapeforms-canvas-field-body">' + renderInputPreview( field ) + '</div>' +
									'</div>';
								}
							);

							markup += '</div>';
						}
					);

					markup += '</div>';
				}
			);

			// Mirror the canvas: append a submit button only when the structure has none.
			if ( ! hasSubmitField() ) {
				markup += '<div class="shapeforms-canvas-submit is-align-' + escapeHtml( state.formSettings.button_alignment || 'left' ) + '">' +
					'<button type="button" class="shapeforms-canvas-submit__button">' + buildButtonContent() + '</button>' +
				'</div>';
			}

			markup += '</div>';
			markup += bfPreviewStatesMarkup();
			$canvas.html( markup );
		}

		function switchEditorView( view ) {
			state.activeEditorView = view;

			$( '.shapeforms-editor-tab' ).each(
				function () {
					var isActive = $( this ).data( 'editor-tab' ) === view;

					$( this )
						.toggleClass( 'is-active', isActive )
						.attr( 'aria-selected', isActive ? 'true' : 'false' );
				}
			);

			$( '[data-editor-view]' ).each(
				function () {
					var isActive = $( this ).data( 'editor-view' ) === view;

					$( this )
						.toggleClass( 'is-active', isActive )
						.prop( 'hidden', ! isActive );
				}
			);

			// Refresh the live preview when entering the Style tab so any
			// structure changes made on the Builder tab are reflected.
			if ( 'style' === view ) {
				renderStylePreview();
			}
		}

		function renderRowPresets() {
			var markup = '';

			( shapeformsLiteBuilder.columnPresets || [] ).forEach(
				function ( preset ) {
					markup += '<button type="button" class="shapeforms-preset" data-columns="' + escapeHtml( preset.value ) + '" data-widths="' + escapeHtml( preset.widths.join( ',' ) ) + '">';
					markup += '<span class="shapeforms-preset__top"><strong>' + escapeHtml( preset.label ) + '</strong>';
					markup += '<span class="shapeforms-preset__check" aria-hidden="true"><span class="dashicons dashicons-yes"></span></span></span>';
					markup += '<span class="shapeforms-preset__grid">';
					preset.widths.forEach(
						function ( width ) {
							markup += '<span style="width:' + escapeHtml( width ) + ';"></span>';
						}
					);
					markup += '</span></button>';
				}
			);

			$( '#shapeforms-column-presets' ).html( markup );
		}

		function switchSidebarTab( tab ) {
			state.activeSidebarTab = tab;

			$( '.shapeforms-sidebar-tab' ).each(
				function () {
					var isActive = $( this ).data( 'tab' ) === tab;

					$( this )
						.toggleClass( 'is-active', isActive )
						.attr( 'aria-selected', isActive ? 'true' : 'false' );
				}
			);

			$( '[data-tab-panel]' ).each(
				function () {
					var isActive = $( this ).data( 'tab-panel' ) === tab;

					$( this )
						.toggleClass( 'is-active', isActive )
						.prop( 'hidden', ! isActive );
				}
			);
		}

		function renderAll() {
			$( '#shapeforms-form-title' ).val( state.formTitle );
			renderCanvas();
			renderSettingsPanel();
			renderFormSettings();
			renderStylingSettings();
			renderStylePreview();
			renderRowPresets();
			switchSidebarTab( state.activeSidebarTab );
			switchEditorView( state.activeEditorView );
		}

		function getAllTemplateDefinitions() {
			var lite = getTemplateDefinitions();
			// Additional templates can be added via shapeformsLiteBuilder.proTemplates.
			var pro = ( shapeformsLiteBuilder.proTemplates && typeof shapeformsLiteBuilder.proTemplates === 'object' )
				? shapeformsLiteBuilder.proTemplates
				: {};
			return $.extend( {}, lite, pro );
		}

		function applyTemplate( templateName ) {
			var templates = getAllTemplateDefinitions();
			var template = templates[ templateName ];

			if ( ! template ) {
				// A locked row advertises a template that is not installed. The Import
				// button is hidden while one is selected, so this is belt-and-braces:
				// without it an advertised key would fall through to the blank-row
				// modal, which reads as the upgrade having silently done something.
				if ( lockedTemplate( templateName ) ) {
					openUpgradeModal( 'shapeforms-templates-upgrade-modal' );
					return;
				}
				openRowModal();
				return;
			}

			// normalizeStructure runs each field through normalizeField() which assigns
			// IDs and fills in all defaults — required for Pro templates that arrive as
			// plain PHP-serialized objects without pre-generated IDs.
			state.structure = normalizeStructure( { rows: template.rows } );
			markDirty();
			state.selectedFieldId = null;
			state.formTitle = template.title || state.formTitle || shapeformsLiteBuilder.defaultFormTitle;
			setActiveColumn( 0, 0 );
			switchEditorView( 'builder' );
			switchSidebarTab( 'library' );
			renderAll();
		}

		function openTemplateModal() {
			renderTemplateModal();
			$( '#shapeforms-template-modal' ).removeAttr( 'hidden' );
		}

		function closeTemplateModal() {
			$( '#shapeforms-template-modal' ).attr( 'hidden', true );
		}

		var tplCategories = {
			general:   'General',
			business:  'Business',
			events:    'Events & Booking',
			hr_survey: 'HR & Surveys',
			health:    'Health & Medical',
			education: 'Education & Nonprofit',
			payment:   'Payment & Calculation',
			multi_step: 'Multi-Step'
		};
		var tplCategoryMap = {
			contact: 'general', newsletter: 'general', feedback: 'general', registration: 'general',
			lead: 'business', support: 'business', order_form: 'business',
			event_rsvp: 'events', booking: 'events',
			job_application: 'hr_survey', customer_survey: 'hr_survey',
			// Additional template category mappings can be added dynamically.
		};

		// Labels of any feature modules a template needs that are currently disabled.
		// A template declares dependencies via `requires_modules` (an array of module
		// keys); the live on/off state comes from shapeformsLiteBuilder.proModules (set
		// by the Pro add-on). Returns [] when nothing is missing — so the notice shows
		// only while a required module is off and disappears once it is re-enabled.
		function bfTemplateMissingModules( tpl ) {
			var req  = ( tpl && Array.isArray( tpl.requires_modules ) ) ? tpl.requires_modules : [];
			if ( ! req.length ) return [];
			var mods = ( shapeformsLiteBuilder.proModules && typeof shapeformsLiteBuilder.proModules === 'object' )
				? shapeformsLiteBuilder.proModules
				: {};
			var missing = [];
			req.forEach( function ( key ) {
				var m = mods[ key ];
				// Only flag a module we actually know about AND that is off. If Pro
				// isn't present, req is empty for Lite templates, so this never fires.
				if ( m && ! m.active ) missing.push( m.label || key );
			} );
			return missing;
		}

		/**
		 * Locked template rows advertised in the library, keyed by category.
		 *
		 * Empty whenever the upgrade CTAs are off, which is also when the add-on
		 * supplies the real versions — so a locked row never sits beside the template
		 * it advertises. A locked entry whose key already exists as a real template is
		 * dropped as a second guarantee of that.
		 *
		 * @param {Object} realTemplates Templates that can actually be imported.
		 * @return {Object} Category key => array of {key,title,description}.
		 */
		function groupedTemplateTeasers( realTemplates ) {
			var grouped = {};

			if ( ! showLockedTemplates() || ! Array.isArray( shapeformsLiteBuilder.premiumTemplates ) ) {
				return grouped;
			}

			shapeformsLiteBuilder.premiumTemplates.forEach( function ( tpl ) {
				if ( ! tpl || ! tpl.key || realTemplates[ tpl.key ] ) {
					return;
				}
				var cat = tpl.category || 'general';
				if ( ! grouped[ cat ] ) grouped[ cat ] = [];
				grouped[ cat ].push( tpl );
			} );

			return grouped;
		}

		/**
		 * Looks a locked entry up by key, or null when it is not one.
		 *
		 * @param {string} key Template key.
		 * @return {Object|null} The locked entry, or null.
		 */
		function lockedTemplate( key ) {
			if ( ! showLockedTemplates() || ! Array.isArray( shapeformsLiteBuilder.premiumTemplates ) ) {
				return null;
			}
			var found = null;
			shapeformsLiteBuilder.premiumTemplates.forEach( function ( tpl ) {
				if ( tpl && tpl.key === key ) found = tpl;
			} );
			return found;
		}

		function renderTemplateModal() {
			var templates = getAllTemplateDefinitions();
			var teasers   = groupedTemplateTeasers( templates );

			// A locked row can be "selected" to preview what it offers, so the
			// selection is valid if it names either a real template or a locked one.
			var lockedSelection = templates[ state.selectedTemplate ] ? null : lockedTemplate( state.selectedTemplate );
			var selectedKey = ( templates[ state.selectedTemplate ] || lockedSelection )
				? state.selectedTemplate
				: 'contact';
			var selectedTemplate = templates[ selectedKey ];
			var listMarkup = '';
			var previewMarkup = '';

			// Group templates by category.
			var grouped = {};
			Object.keys( tplCategories ).forEach( function ( cat ) { grouped[ cat ] = []; } );
			Object.keys( templates ).forEach( function ( key ) {
				// A template may declare its own `category` (this is how Pro
				// templates land in the right group without editing tplCategoryMap);
				// fall back to the built-in map, then to 'general'.
				var tpl = templates[ key ];
				var cat = ( tpl && tpl.category ) || tplCategoryMap[ key ] || 'general';
				if ( ! grouped[ cat ] ) grouped[ cat ] = [];
				grouped[ cat ].push( key );
			} );

			// Categories the add-on fills but this plugin does not — Health & Medical,
			// Education & Nonprofit, Payment & Calculation, Multi-Step — hold nothing but
			// locked rows, so they have to be walked too or they never render at all.
			Object.keys( teasers ).forEach( function ( cat ) {
				if ( ! grouped[ cat ] ) grouped[ cat ] = [];
			} );

			Object.keys( grouped ).forEach( function ( cat ) {
				var catTeasers = teasers[ cat ] || [];
				if ( ! grouped[ cat ].length && ! catTeasers.length ) return;
				// Accordion: the group holding the currently-selected template starts
				// open, the rest start collapsed. On first open the selection defaults
				// to 'contact', so the first (General) group is the one shown. Keeping
				// the selected group open means it stays expanded across the re-render
				// that fires when a template is picked.
				var groupOpen = grouped[ cat ].indexOf( selectedKey ) !== -1 ||
					catTeasers.some( function ( t ) { return t.key === selectedKey; } );
				listMarkup += '<div class="shapeforms-template-group' + ( groupOpen ? ' is-open' : '' ) + '">';
				listMarkup += '<button type="button" class="shapeforms-template-group__header" data-template-group-toggle aria-expanded="' + ( groupOpen ? 'true' : 'false' ) + '">';
				listMarkup += '<span class="shapeforms-template-group__title">' + escapeHtml( tplCategories[ cat ] || cat ) + '</span>';
				listMarkup += '<span class="shapeforms-template-group__chevron dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>';
				listMarkup += '</button>';
				listMarkup += '<div class="shapeforms-template-group__body"><div class="shapeforms-template-group__inner">';
				grouped[ cat ].forEach( function ( key ) {
					var tpl = templates[ key ];
					var isActive = key === selectedKey;
					listMarkup += '<button type="button" class="shapeforms-template-option' +
						( isActive ? ' is-active' : '' ) +
						'" data-template-option="' + escapeHtml( key ) + '">';
					listMarkup += '<span class="shapeforms-tpl-option-label"><strong>' + escapeHtml( tpl.title ) + '</strong></span>';
					listMarkup += '</button>';
				} );
				// Locked rows sit under the importable ones so the free templates always
				// come first. They are real buttons, not inert chips: selecting one shows
				// what the form does in the preview pane, which is the whole point.
				catTeasers.forEach( function ( tpl ) {
					var isActive = tpl.key === selectedKey;
					listMarkup += '<button type="button" class="shapeforms-template-option is-locked' +
						( isActive ? ' is-active' : '' ) +
						'" data-template-option="' + escapeHtml( tpl.key ) + '">';
					listMarkup += '<span class="shapeforms-tpl-option-label"><strong>' + escapeHtml( tpl.title ) + '</strong></span>';
					listMarkup += '<span class="shapeforms-template-option__lock dashicons dashicons-lock" aria-hidden="true"></span>';
					listMarkup += '</button>';
				} );
				listMarkup += '</div></div>';
				listMarkup += '</div>';
			} );

			// A locked row has no structure to draw — it is an advertisement, not a
			// template — so the canvas shows what the form is for and how to get it,
			// and the Import button is swapped for the upgrade call to action.
			if ( ! selectedTemplate ) {
				var lockedTpl = lockedSelection || lockedTemplate( selectedKey );

				$( '#shapeforms-template-list' ).html( listMarkup );
				$( '#shapeforms-template-preview__head' ).html(
					'<h3>' + escapeHtml( lockedTpl ? lockedTpl.title : '' ) + '</h3>' +
					'<p>' + escapeHtml( lockedTpl ? lockedTpl.description : '' ) + '</p>'
				);
				$( '#shapeforms-template-preview-canvas' ).html(
					'<div class="shapeforms-template-lock">' +
						'<span class="shapeforms-template-lock__badge dashicons dashicons-lock" aria-hidden="true"></span>' +
						'<strong class="shapeforms-template-lock__title">' +
							escapeHtml( shapeformsLiteBuilder.labels.templateLockTitle || 'Available with an upgrade' ) +
						'</strong>' +
						'<p class="shapeforms-template-lock__text">' +
							escapeHtml( shapeformsLiteBuilder.labels.templateLockText || '' ) +
						'</p>' +
						'<button type="button" class="shapeforms-upgrade-btn shapeforms-template-lock__cta" aria-haspopup="dialog" data-upgrade-modal="shapeforms-templates-upgrade-modal">' +
							'<span class="dashicons dashicons-lock" aria-hidden="true"></span>' +
							escapeHtml( shapeformsLiteBuilder.labels.upgradeNow || 'Upgrade Now' ) +
						'</button>' +
					'</div>'
				);
				$( '#shapeforms-import-template' ).attr( 'hidden', 'hidden' );
				return;
			}

			$( '#shapeforms-import-template' ).removeAttr( 'hidden' );

			selectedTemplate.rows.forEach( function ( row ) {
				previewMarkup += '<div class="shapeforms-template-preview-row">';
				row.columns.forEach( function ( column ) {
					previewMarkup += '<div class="shapeforms-template-preview-column" style="width:' + escapeHtml( column.width ) + ';">';
					column.fields.forEach( function ( field ) {
						previewMarkup += '<div class="shapeforms-template-preview-field">';
						previewMarkup += renderInputPreview( field );
						previewMarkup += '</div>';
					} );
					previewMarkup += '</div>';
				} );
				previewMarkup += '</div>';
			} );
			previewMarkup += '<div class="shapeforms-template-preview-submit"><button type="button" class="shapeforms-canvas-submit__button">' + escapeHtml( state.formSettings.button_text || 'Submit' ) + '</button></div>';

			$( '#shapeforms-template-list' ).html( listMarkup );
			$( '#shapeforms-template-preview-canvas' ).html( previewMarkup );
			applyPreviewStyleBlock();
			var missingModules = bfTemplateMissingModules( selectedTemplate );
			var moduleNotice   = '';
			if ( missingModules.length ) {
				var noticeText = ( shapeformsLiteBuilder.labels.templateNeedsModule || 'This template uses %s, which is currently disabled. Enable it in Settings for the form to work fully.' )
					.replace( '%s', missingModules.join( ', ' ) );
				moduleNotice = '<div class="shapeforms-template-module-notice"><span class="dashicons dashicons-warning" aria-hidden="true"></span> ' + escapeHtml( noticeText ) + '</div>';
			}

			$( '#shapeforms-template-preview__head' ).html(
				'<h3>' + escapeHtml( selectedTemplate.title ) + '</h3>' +
				'<p>' + escapeHtml( selectedTemplate.description ) + '</p>' +
				moduleNotice
			);

			$( '#shapeforms-import-template' )
				.text( shapeformsLiteBuilder.labels.importTemplate || 'Import Template' );
		}

		function updateShortcodeDisplay() {
			var $shortcode = $( '#shapeforms-builder-shortcode' );
			var $code = $( '#shapeforms-builder-shortcode-code' );
			var $preview = $( '#shapeforms-preview-form' );

			if ( ! state.formId ) {
				$shortcode.attr( 'hidden', true ).removeClass( 'is-visible' );
				$preview.hide();
				return;
			}

			$code.text( '[shapeforms id="' + state.formId + '"]' );
			$shortcode.removeAttr( 'hidden' ).addClass( 'is-visible' );
			$preview.attr( 'href', 'admin.php?page=shapeforms-lite-preview&form_id=' + state.formId ).show();
		}

		function copyShortcode() {
			var shortcode;

			if ( ! state.formId ) {
				return;
			}

			shortcode = '[shapeforms id="' + state.formId + '"]';

			// Brief "copied" feedback: swap the copy icon to a checkmark, then revert.
			var flashCopied = function () {
				var $btn  = $( '#shapeforms-builder-shortcode' );
				var $icon = $btn.find( '.shapeforms-builder-shortcode__copy' );
				$btn.addClass( 'is-copied' );
				$icon.removeClass( 'dashicons-admin-page' ).addClass( 'dashicons-yes-alt' );
				clearTimeout( $btn.data( 'copiedTimer' ) );
				$btn.data( 'copiedTimer', setTimeout( function () {
					$btn.removeClass( 'is-copied' );
					$icon.removeClass( 'dashicons-yes-alt' ).addClass( 'dashicons-admin-page' );
				}, 1500 ) );
			};

			// Legacy copy for non-secure contexts (e.g. plain-HTTP local sites)
			// where navigator.clipboard is unavailable.
			var legacyCopy = function () {
				var $temp = $( '<textarea>' ).val( shortcode ).css( {
					position: 'fixed',
					top: '-9999px',
					opacity: 0
				} ).appendTo( 'body' );
				$temp[0].select();
				try {
					document.execCommand( 'copy' );
				} catch ( e ) {}
				$temp.remove();
			};

			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( shortcode ).then( flashCopied, function () {
					legacyCopy();
					flashCopied();
				} );
				return;
			}

			legacyCopy();
			flashCopied();
		}

		// Save the form. Accepts an optional onSaved( formId ) callback invoked only
		// after a successful save — used by the Preview button to auto-save the current
		// builder state (including unsaved fields) before navigating to the preview page,
		// which renders the SAVED form from the database.
		function saveForm( onSaved ) {
			var $save = $( '#shapeforms-save-form' );
			var title = $.trim( $( '#shapeforms-form-title' ).val() ) || shapeformsLiteBuilder.defaultFormTitle;
			var payload;

			// Note: an empty form (no rows/fields) is intentionally allowed to save,
			// so a user can clear a form and have that persist. The server stores an
			// empty structure and the renderer skips a field-less form gracefully.

			// Flush the thank-you editor. Typing in Code view fires no event the editor
			// reports, so without this a message written there and saved immediately
			// would not be picked up.
			syncThankYouMessage();

			/**
			 * Allow Pro modules to mutate state.formSettings before it is serialised.
			 * Attach handlers to `shapeforms:before_save` to inject extra keys.
			 *
			 * @event shapeforms:before_save
			 * @param {object} formSettings Live reference to state.formSettings.
			 */
			$( document ).trigger( 'shapeforms:before_save', [ state.formSettings ] );

			payload = {
				action: 'shapeforms_lite_save_form',
				nonce: shapeformsLiteBuilder.nonce,
				form_id: state.formId,
				title: title,
				structure: JSON.stringify( state.structure ),
				settings: JSON.stringify( state.formSettings )
			};

			$save.prop( 'disabled', true ).text( shapeformsLiteBuilder.savingText );

			// Reset the status line: cancel any pending auto-dismiss and restore visibility.
			var $status = $( '#shapeforms-builder-status' );
			clearTimeout( $status.data( 'dismissTimer' ) );
			$status.stop( true, true ).css( { display: '', opacity: 1 } ).text( '' );

			$.post( shapeformsLiteBuilder.ajaxUrl, payload )
				.done(
					function ( response ) {
						if ( response && response.success ) {
							state.formId = response.data.formId;
							hasUnsavedChanges = false; // saved → no pending changes
							updateShortcodeDisplay();
							$( '#shapeforms-builder-status' ).text( response.data.message );

							// Auto-dismiss the success message after a few seconds (errors stay).
							$status.data( 'dismissTimer', setTimeout( function () {
								$status.fadeOut( 300, function () {
									$( this ).text( '' ).css( { display: '', opacity: 1 } );
								} );
							}, 4000 ) );

							// Keep form_id in the URL so a page refresh stays on the builder.
							if ( state.formId && window.history && window.history.replaceState ) {
								var url = window.location.href;
								if ( url.indexOf( 'form_id=' ) !== -1 ) {
									url = url.replace( /form_id=\d*/, 'form_id=' + state.formId );
								} else {
									url += ( url.indexOf( '?' ) !== -1 ? '&' : '?' ) + 'form_id=' + state.formId;
								}
								window.history.replaceState( null, '', url );
							}

							if ( 'function' === typeof onSaved ) {
								onSaved( state.formId );
							}

							return;
						}

						// WordPress returns 0 or -1 as response body when nonce/auth fails.
						if ( response === '0' || response === '-1' || response === 0 ) {
							$( '#shapeforms-builder-status' ).text( shapeformsLiteBuilder.messages.saveError + ' (session expired — please reload the page)' );
							return;
						}

						$( '#shapeforms-builder-status' ).text( response && response.data && response.data.message ? response.data.message : shapeformsLiteBuilder.messages.saveError );
					}
				)
				.fail(
					function ( xhr ) {
						var message = shapeformsLiteBuilder.messages.saveError;

						if ( xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
							message = xhr.responseJSON.data.message;
						} else if ( xhr && xhr.status === 403 ) {
							message = shapeformsLiteBuilder.messages.saveError + ' (session expired — please reload the page)';
						}

						$( '#shapeforms-builder-status' ).text( message );
					}
				)
				.always(
					function () {
						$save.prop( 'disabled', false ).text( shapeformsLiteBuilder.saveText );
					}
				);
		}

		/**
		 * Marks the canvas while a drag is in flight, so hidden empty columns
		 * can reappear as drop targets for exactly as long as they are useful.
		 *
		 * THIS IS NOT DECORATION. sortable.js finds a drop target by hit-testing
		 * every registered container's bounding rect and skipping any that
		 * measures 0x0 (`if ( ! rect.width && ! rect.height ) continue`). A
		 * display:none column is therefore not merely invisible — it is not a
		 * drop target at all, and a field could never be put back into it. The
		 * reveal is what makes hiding them safe.
		 *
		 * Bound ONCE on the document, not per render: setupSortables() runs on
		 * every canvas render, and listeners added there would multiply. The
		 * document is also the only place that catches a drag starting in the
		 * field library and ending on the canvas.
		 *
		 * Both `dragend` and `drop` clear it. `dragend` alone is the documented
		 * pair for `dragstart`, but a drag that ends over a container which
		 * re-renders the canvas can lose its source element first; `drop` is the
		 * belt to that braces, and clearing twice costs nothing.
		 */
		function bindCanvasDragState() {
			var canvas = document.getElementById( 'shapeforms-canvas' );

			if ( ! canvas || canvas.__bfDragStateBound ) {
				return;
			}

			canvas.__bfDragStateBound = true;

			var on  = function () { canvas.classList.add( 'is-dragging' ); };
			var off = function () { canvas.classList.remove( 'is-dragging' ); };

			document.addEventListener( 'dragstart', on );
			document.addEventListener( 'dragend', off );
			document.addEventListener( 'drop', off );
		}

		function setupSortables() {
			bindCanvasDragState();

			if ( document.getElementById( 'shapeforms-canvas-rows' ) ) {
				Sortable.create(
					document.getElementById( 'shapeforms-canvas-rows' ),
					{
						group: {
							name: 'shapeforms-rows',
							put: false
						},
						draggable: '.shapeforms-row',
						handle: '.shapeforms-row-move',
						onEnd: function ( event ) {
							moveRow( event.oldIndex, event.newIndex );
						}
					}
				);
			}

			// The premium-fields teaser reuses .shapeforms-library-grid for layout but its
			// chips are inert adverts, so it is excluded here — never a drag source.
			$( '.shapeforms-library-grid' ).not( '.shapeforms-library-grid--locked' ).each(
				function () {
					Sortable.create(
						this,
						{
							group: {
								name: 'shapeforms-fields',
								pull: 'clone',
								put: false
							},
							// Palette only: drag a copy out to the canvas, but never
							// reorder items within the library sidebar itself. The
							// custom sortable honours this flag in canAccept().
							sort: false,
							draggable: '.shapeforms-library-item',
							handle: '.shapeforms-library-item'
						}
					);
				}
			);

			$( '.shapeforms-column-fields' ).each(
				function () {
					Sortable.create(
						this,
						{
							group: {
								name: 'shapeforms-fields',
								put: true
							},
							draggable: '.shapeforms-canvas-field',
							handle: '.shapeforms-move-field',
							onAdd: function ( event ) {
								var type = $( event.item ).data( 'field-type' );
								var toRowIndex = Number( $( event.to ).data( 'row-index' ) );
								var toColumnIndex = Number( $( event.to ).data( 'column-index' ) );

								if ( type ) {
									addFieldToColumn( type, toRowIndex, toColumnIndex, event.newIndex );
								}
							},
							onEnd: function ( event ) {
								var fromRowIndex = Number( $( event.from ).data( 'row-index' ) );
								var fromColumnIndex = Number( $( event.from ).data( 'column-index' ) );
								var toRowIndex = Number( $( event.to ).data( 'row-index' ) );
								var toColumnIndex = Number( $( event.to ).data( 'column-index' ) );
								var fromColumn = getColumn( fromRowIndex, fromColumnIndex );
								var toColumn = getColumn( toRowIndex, toColumnIndex );

								if ( ! fromColumn || ! toColumn ) return;

								var field = fromColumn.fields.splice( event.oldIndex, 1 )[ 0 ];
								if ( ! field ) return;
								toColumn.fields.splice( event.newIndex, 0, field );
								state.selectedFieldId = field.id;
								setActiveColumn( toRowIndex, toColumnIndex );
								renderAll();
							}
						}
					);
				}
			);
		}

		function openRowModal() {
			$( '#shapeforms-row-modal' ).removeAttr( 'hidden' );
		}

		function closeRowModal() {
			$( '#shapeforms-row-modal' ).attr( 'hidden', true );
		}

		// Options and their icons in one pass over the rows. Both lists come out of
		// the same DOM order under the same "an empty row is not an option" rule, so
		// a rename, a drag or a blanked-out row can never leave entry N describing a
		// different option than the one it is beside.
		function collectRepeaterOptions() {
			var options = [];
			var icons   = [];

			$( '#shapeforms-options-repeater .shapeforms-options-repeater__item' ).each( function () {
				var $row = $( this );
				var val  = $.trim( $row.find( '.shapeforms-options-repeater__input' ).val() || '' );
				if ( ! val.length ) {
					return;
				}
				options.push( val );
				icons.push( bfTriggerIcon( $row.find( '[data-bf-opticon]' ) ) );
			} );

			while ( icons.length && '' === icons[ icons.length - 1 ] ) {
				icons.pop();
			}

			return { options: options, icons: icons };
		}

		// Writes both lists onto a field in one step. Every caller that re-reads the
		// option rows goes through this, so the two can never be written apart.
		function applyCollectedOptions( field ) {
			var collected = collectRepeaterOptions();
			field.options      = collected.options;
			field.option_icons = collected.icons;
		}

		function syncRepeaterToField() {
			var selected = getSelectedFieldLocation();
			if ( ! selected || optionFieldTypes.indexOf( selected.field.type ) === -1 ) return;
			applyCollectedOptions( selected.field );

			var defaults = [];
			$( '#shapeforms-options-repeater input[name="shapeforms-option-default"]:checked' ).each( function () {
				var idx = $( this ).closest( '.shapeforms-options-repeater__item' ).index();
				var val = $.trim( selected.field.options[ idx ] || '' );
				if ( val.length ) {
					defaults.push( val );
				}
			} );
			selected.field.default_value = defaults.join( ', ' );

			renderCanvas();
		}

		function setupOptionsSortable() {
			var el = document.getElementById( 'shapeforms-options-repeater' );
			if ( ! el ) return;
			Sortable.create( el, {
				draggable: '.shapeforms-options-repeater__item',
				handle: '.shapeforms-options-repeater__drag',
				onEnd: function () {
					syncRepeaterToField();
				}
			} );
		}

		function setupAddressSortable() {
			var el = document.getElementById( 'shapeforms-addr-list' );
			if ( ! el ) return;
			Sortable.create( el, {
				draggable: '.shapeforms-addr-item',
				handle: '.shapeforms-addr-item__drag',
				onEnd: function () {
					var selected = getSelectedFieldLocation();
					if ( ! selected ) return;
					var newOrder = [];
					$( '#shapeforms-addr-list .shapeforms-addr-item' ).each( function () {
						newOrder.push( $( this ).data( 'addr-key' ) );
					} );
					selected.field.address_order = newOrder;
					renderCanvas();
				}
			} );
		}

		// Style section collapse toggle — accordion: only one section open at a
		// time. Opening a section collapses any sibling that was open; clicking an
		// already-open section's head closes it (leaving all collapsed).
		$( document ).on( 'click', '.shapeforms-style-section__head', function () {
			var $sec = $( this ).closest( '.shapeforms-style-section' );
			var willOpen = ! $sec.hasClass( 'is-open' );
			$sec.siblings( '.shapeforms-style-section' ).removeClass( 'is-open' );
			$sec.toggleClass( 'is-open', willOpen );
		} );

		// Reset a whole section: clear every var its controls write (active device), then
		// rebuild the section body so all controls fall back to their default.
		// Design Theme reset — revert to the default theme (no schema section, so it
		// needs its own handler; the generic one below no-ops on this button).
		$( document ).on( 'click', '.shapeforms-theme-reset', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			if ( $( this ).is( '.is-disabled, :disabled' ) ) { return; }
			bfApplyThemeWithConflictCheck( 'default-blue' );
		} );

		$( document ).on( 'click', '.shapeforms-style-section__reset', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			var $btn = $( this ), secId = $btn.attr( 'data-reset-section' ), sec = null;
			if ( $btn.is( '.is-disabled, :disabled' ) ) { return; }
			bfStyleSchema().forEach( function ( s ) { if ( s.id === secId ) { sec = s; } } );
			if ( ! sec ) { return; }
			var layer = state.formSettings.style && state.formSettings.style[ state.activeDevice || 'desktop' ];
			if ( layer ) { bfSectionVars( sec ).forEach( function ( v ) { delete layer[ v ]; } ); }
			// Checkbox & Radio carries one non-var value. Resetting it changes which
			// controls the section offers, so the grid is rebuilt from the schema rather
			// than left showing the Button controls — bfRerenderStyleSection() re-reads
			// the schema by id, since `sec` was captured before the reset. The canvas
			// also re-renders here, as the field markup itself carries the modifier.
			if ( 'choice' === sec.id && 'button' === state.formSettings.choice_style ) {
				state.formSettings.choice_style = 'default';
				markDirty();
				bfRerenderStyleSection( 'choice' );
				renderStylePreview();
				renderCanvas();
				bfRefreshResetStates();
				return;
			}
			var body = '';
			bfOrderControls( sec.controls ).forEach( function ( c ) { body += advControl( c ); } );
			$btn.closest( '.shapeforms-style-section' ).find( '.shapeforms-adv-grid' ).html( body );
			applyPreviewStyleBlock();
			bfRefreshResetStates();
			bfSyncFormMaxWidthState();
		} );

		// Live-preview device switcher. Sets the active breakpoint, frames the preview
		// at a representative width, and re-renders so the controls + preview reflect
		// that device's values.
		$( document ).on( 'click', '.shapeforms-device-btn', function () {
			var device = $( this ).data( 'device' );
			if ( ! device || device === state.activeDevice ) {
				return;
			}
			state.activeDevice = device;

			$( '.shapeforms-device-btn' ).removeClass( 'is-active' ).attr( 'aria-pressed', 'false' );
			$( this ).addClass( 'is-active' ).attr( 'aria-pressed', 'true' );

			$( '#shapeforms-style-preview-canvas' )
				.removeClass( 'is-device-desktop is-device-tablet is-device-mobile' )
				.addClass( 'is-device-' + device );

			renderStylingSettings();
			renderStylePreview();
		} );

		// ---- Advanced style controls: recompute a field's CSS var(s) from its inputs ----
		function recomputeAdvField( $field ) {
			var bfPrevScope = bfStyleScope;
			bfStyleScope = bfScopeForEl( $field );
			try {
				recomputeAdvFieldInner( $field );
			} finally {
				bfStyleScope = bfPrevScope;
			}
		}

		function recomputeAdvFieldInner( $field ) {
			if ( ! $field || ! $field.length ) { return; }
			var type = $field.data( 'type' );
			var cssVar = $field.data( 'var' );
			var out = {};
			var px = function ( v ) { return ( '' === v || null == v ) ? 0 : parseFloat( v ); };

			if ( 'color' === type ) {
				out[ cssVar ] = bfReadColorField( $field.find( '.shapeforms-color-field' ).first() );
			} else if ( 'slider' === type ) {
				var n = $field.find( '.bf-adv-num' ).val();
				var u = $field.find( '.bf-adv-unit' ).length ? $field.find( '.bf-adv-unit' ).val() : ( $field.find( 'span[data-unit]' ).data( 'unit' ) || 'px' );
				out[ cssVar ] = ( '' === n ) ? '' : ( parseFloat( n ) + u );
			} else if ( 'dimension' === type ) {
				var du = $field.find( '.bf-adv-dim-unit' ).val() || 'px';
				var vals = $field.find( '.bf-adv-dim' ).map( function () { return $( this ).val(); } ).get();
				var anySet = vals.some( function ( x ) { return '' !== x; } );
				out[ cssVar ] = anySet ? vals.map( function ( x ) { return px( x ) + du; } ).join( ' ' ) : '';
			} else if ( 'border' === type ) {
				var colorVar = $field.data( 'colorvar' ) || ( cssVar + '-color' );
				var bw = $field.find( '.bf-adv-bw' ).val();
				out[ cssVar + '-width' ] = ( '' === bw ) ? '' : ( parseFloat( bw ) + 'px' );
				var bs = $field.find( '.bf-adv-bs' ).val() || '';
				// A width with no explicit style would otherwise resolve to `none` (button)
				// and render nothing — default to `solid` so setting a width is visible.
				if ( ! bs && '' !== bw && parseFloat( bw ) > 0 ) { bs = 'solid'; }
				out[ cssVar + '-style' ] = bs;
				out[ colorVar ] = bfReadColorField( $field.find( '.bf-adv-bc' ).closest( '.shapeforms-color-field' ) );
			} else if ( 'typography' === type ) {
				var ffKey = $field.find( '.bf-adv-ff' ).val();
				out[ cssVar + '-ff' ] = ( ffKey && BF_FONT_STACKS[ ffKey ] ) ? BF_FONT_STACKS[ ffKey ] : '';
				var fsv = $field.find( '.bf-adv-fs' ).val();
				out[ cssVar + '-fs' ] = ( '' === fsv ) ? '' : ( parseFloat( fsv ) + 'px' );
				out[ cssVar + '-fw' ] = $field.find( '.bf-adv-fw' ).val() || '';
				var lhv = $field.find( '.bf-adv-lh' ).val();
				out[ cssVar + '-lh' ] = ( '' === lhv ) ? '' : ( parseFloat( lhv ) + 'px' );
				var lsv = $field.find( '.bf-adv-ls' ).val();
				out[ cssVar + '-ls' ] = ( '' === lsv ) ? '' : ( parseFloat( lsv ) + 'px' );
				out[ cssVar + '-tt' ] = $field.find( '.bf-adv-tt' ).val() || '';
			} else if ( 'shadow' === type ) {
				var sx = $field.find( '.bf-adv-sx' ).val(), sy = $field.find( '.bf-adv-sy' ).val(),
					sb = $field.find( '.bf-adv-sblur' ).val(), ss = $field.find( '.bf-adv-sspread' ).val();
				var sc = bfReadColorField( $field.find( '.bf-adv-sc' ).closest( '.shapeforms-color-field' ) );
				var anyNum = ( '' !== sx || '' !== sy || '' !== sb || '' !== ss );
				if ( ! sc || ! anyNum ) {
					out[ cssVar ] = '';
				} else {
					out[ cssVar ] = ( $field.find( '.bf-adv-inset' ).prop( 'checked' ) ? 'inset ' : '' ) +
						px( sx ) + 'px ' + px( sy ) + 'px ' + px( sb ) + 'px ' + px( ss ) + 'px ' + sc;
				}
			} else if ( 'background' === type ) {
				if ( $field.find( '.bf-adv-bgtype[data-bg="gradient"]' ).hasClass( 'is-active' ) ) {
					var c1 = bfReadColorField( $field.find( '.bf-adv-bg-c1' ).closest( '.shapeforms-color-field' ) );
					var c2 = bfReadColorField( $field.find( '.bf-adv-bg-c2' ).closest( '.shapeforms-color-field' ) );
					var ang = parseInt( $field.find( '.bf-adv-bg-angle' ).val(), 10 );
					if ( isNaN( ang ) ) { ang = 135; }
					out[ cssVar ] = ( c1 && c2 )
						? ( 'linear-gradient(' + ang + 'deg, ' + c1 + ' 0%, ' + c2 + ' 100%)' ) : '';
				} else {
					out[ cssVar ] = bfReadColorField( $field.find( '.bf-adv-bg-c' ).closest( '.shapeforms-color-field' ) );
				}
			} else if ( 'select' === type ) {
				out[ cssVar ] = $field.find( '.bf-adv-select' ).val() || '';
			} else if ( 'switch' === type ) {
				out[ cssVar ] = $field.find( '.bf-adv-toggle' ).prop( 'checked' ) ? ( String( $field.data( 'on' ) ) || '1' ) : '';
			} else if ( 'align' === type ) {
				// Keep the keyword (for state restore) + derive margin-left/right and a
				// max-width override (full width = "justify") onto <base>-ml/-mr/-mw.
				var av = $field.find( '.bf-adv-align-btn.is-active' ).data( 'align' ) || '';
				var base = cssVar.replace( /-align$/, '' );
				var amap = { left: [ '0', 'auto', '' ], center: [ 'auto', 'auto', '' ], right: [ 'auto', '0', '' ], justify: [ '0', '0', '100%' ] };
				var a = amap[ av ] || [ '', '', '' ];
				out[ cssVar ] = av;
				out[ base + '-ml' ] = a[0];
				out[ base + '-mr' ] = a[1];
				out[ base + '-mw' ] = a[2];
			}

			advStyleSetVars( out );
		}

		// Checkbox & Radio → Style. Swaps the treatment, then re-renders the Style
		// panel because the section's other controls differ per mode, and the canvas
		// because the modifier class is emitted by renderInputPreview().
		$( document ).on( 'click', '.bf-adv-choice-style-btn', function () {
			var next = $( this ).data( 'choice-style' ) === 'button' ? 'button' : 'default';
			if ( next === state.formSettings.choice_style ) {
				return;
			}
			state.formSettings.choice_style = next;
			markDirty();
			// Swap only this section's controls — see bfRerenderStyleSection().
			bfRerenderStyleSection( 'choice' );
			renderStylePreview();
			renderCanvas();
			bfRefreshResetStates();
		} );

		// Alignment segmented control → activate the clicked button, recompute.
		$( document ).on( 'click', '.bf-adv-align-btn', function () {
			var $btn = $( this );
			$btn.closest( '.bf-adv-align' ).find( '.bf-adv-align-btn' ).removeClass( 'is-active' );
			$btn.addClass( 'is-active' );
			recomputeAdvField( $btn.closest( '.shapeforms-adv-field' ) );
			bfSyncFormMaxWidthState();
		} );

		// Color picker → sync hex text + swatch, then recompute owning field.
		$( document ).on( 'input', '.shapeforms-adv-field .bf-adv-colorpick', function () {
			var v = $( this ).val();
			var $cf = $( this ).closest( '.shapeforms-color-field' );
			$cf.find( '.bf-adv-hex' ).val( v );
			bfSyncColorSwatch( $cf );
			recomputeAdvField( $( this ).closest( '.shapeforms-adv-field' ) );
		} );

		// Opacity (alpha) range slider → live % readout, swatch overlay, recompute → rgba().
		$( document ).on( 'input', '.shapeforms-adv-field .bf-adv-opacity', function () {
			var $cf = $( this ).closest( '.shapeforms-color-field' );
			$cf.find( '.bf-adv-op-val' ).text( ( $( this ).val() || '0' ) + '%' );
			bfSyncColorSwatch( $cf );
			recomputeAdvField( $( this ).closest( '.shapeforms-adv-field' ) );
		} );

		// Reset a colour field back to "unset" — clears the hex + opacity so the
		// underlying --bf-* var is dropped (advStyleSetVars deletes empty values),
		// i.e. the colour behaves exactly as if the user never touched it.
		$( document ).on( 'click', '.shapeforms-adv-field .bf-adv-color-reset', function ( e ) {
			e.preventDefault();
			var $cf = $( this ).closest( '.shapeforms-color-field' );
			$cf.find( '.bf-adv-hex' ).val( '' );
			$cf.find( '.bf-adv-colorpick' ).val( '#000000' );
			$cf.find( '.bf-adv-opacity' ).val( 100 );
			$cf.find( '.bf-adv-op-val' ).text( '100%' );
			bfSyncColorSwatch( $cf );
			recomputeAdvField( $( this ).closest( '.shapeforms-adv-field' ) );
		} );

		// Any other advanced input/select/checkbox change.
		$( document ).on( 'input change', '.shapeforms-adv-field .bf-adv-input', function () {
			var $el = $( this );
			if ( $el.hasClass( 'bf-adv-hex' ) ) {
				var hv = ( $el.val() || '' ).trim();
				var $cf = $el.closest( '.shapeforms-color-field' );
				if ( BF_HEX6.test( hv ) ) {
					$cf.find( '.bf-adv-colorpick' ).val( hv );
				}
				bfSyncColorSwatch( $cf );
			}
			if ( $el.hasClass( 'bf-adv-dim' ) && $el.closest( '.shapeforms-adv-field' ).hasClass( 'is-linked' ) ) {
				$el.closest( '.shapeforms-adv-field' ).find( '.bf-adv-dim' ).val( $el.val() );
			}
			recomputeAdvField( $el.closest( '.shapeforms-adv-field' ) );
		} );

		// Dimension link toggle.
		$( document ).on( 'click', '.bf-adv-dim-link', function () {
			var $field = $( this ).closest( '.shapeforms-adv-field' );
			$field.toggleClass( 'is-linked' );
			$( this ).toggleClass( 'is-active', $field.hasClass( 'is-linked' ) );
		} );

		// State switcher tabs (Normal / Hover / Focus): show only the active state's panel.
		$( document ).on( 'click', '.bf-adv-state-tab', function () {
			var $wrap = $( this ).closest( '.shapeforms-adv-statetabs' );
			var key = String( $( this ).data( 'state' ) );
			$wrap.find( '.bf-adv-state-tab' ).removeClass( 'is-active' );
			$( this ).addClass( 'is-active' );
			$wrap.find( '.bf-adv-state-panel' ).each( function () {
				$( this ).prop( 'hidden', String( $( this ).data( 'state' ) ) !== key );
			} );
		} );

		// Background type tabs (solid / gradient).
		$( document ).on( 'click', '.bf-adv-bgtype', function () {
			// Shadow state tabs share the segmented .bf-adv-bgtype styling — let their
			// own handler manage them.
			if ( $( this ).hasClass( 'bf-adv-shadow-tab' ) ) { return; }
			var $field = $( this ).closest( '.shapeforms-adv-field' );
			$field.find( '.bf-adv-bgtype' ).removeClass( 'is-active' );
			$( this ).addClass( 'is-active' );
			var grad = 'gradient' === $( this ).data( 'bg' );
			$field.find( '.bf-adv-bg-solid' ).prop( 'hidden', grad );
			$field.find( '.bf-adv-bg-grad' ).prop( 'hidden', ! grad );
			recomputeAdvField( $field );
		} );

		// Box-shadow Normal/Hover (or Normal/Focus) state tabs: switch which CSS var the
		// shadow fields edit, repopulating them from that state's stored value. The other
		// state's value persists untouched in state.formSettings.style.
		$( document ).on( 'click', '.bf-adv-shadow-tab', function () {
			var $field = $( this ).closest( '.shapeforms-adv-field' );
			var newVar = $( this ).attr( 'data-shadow-var' );
			$field.find( '.bf-adv-shadow-tab' ).removeClass( 'is-active' );
			$( this ).addClass( 'is-active' );
			$field.attr( 'data-var', newVar ).data( 'var', newVar );
			$field.find( '.bf-adv-shadow-body' ).html( shadowBodyMarkup( newVar ) );
		} );

		// Field setting accordion toggle (only one open at a time).
		$( document ).on( 'click', '.shapeforms-field-accordion__head', function () {
			var $accordion = $( this ).closest( '.shapeforms-field-accordion' );
			var isOpen = $accordion.hasClass( 'is-open' );
			$accordion.siblings( '.shapeforms-field-accordion' ).removeClass( 'is-open' );
			$accordion.toggleClass( 'is-open', ! isOpen );
			state.activeSettingsAccordion = ! isOpen ? ( $accordion.data( 'accordion' ) || 'settings' ) : 'settings';
		} );

		// Reset a field-setting color picker (slider track / star) back to its
		// theme-following default by clearing the stored color.
		$( document ).on( 'click', '.bf-color-reset', function () {
			var prop = $( this ).data( 'color-reset' );
			var selected = getSelectedFieldLocation();

			if ( ! selected || ! prop ) {
				return;
			}

			selected.field[ prop ] = '';
			renderAll();
		} );

		// Custom Amount — "Set Amount Limits" switch. Re-renders so the Minimum and
		// Maximum controls appear or disappear with it, and the canvas preview picks
		// up (or drops) the limits hint at the same time.
		$( document ).on( 'change', '#shapeforms-setting-amount-limits', function () {
			var selected = getSelectedFieldLocation();
			if ( ! selected ) return;
			selected.field.amount_limits = $( this ).is( ':checked' );
			renderAll();
		} );

		// Conditional logic — enable/disable toggle.
		$( document ).on( 'change', '#shapeforms-setting-cond-enabled', function () {
			var selected = getSelectedFieldLocation();
			if ( ! selected ) return;
			selected.field.conditional.enabled = $( this ).is( ':checked' );
			state.activeSettingsAccordion = 'advanced';
			renderSettingsPanel();
			setupOptionsSortable();
			setupAddressSortable();
		} );

		// Conditional logic — AND/OR logic toggle.
		$( document ).on( 'change', 'input[name="bfcl-logic"]', function () {
			var selected = getSelectedFieldLocation();
			if ( ! selected ) return;
			selected.field.conditional.logic = $( this ).val();
			state.activeSettingsAccordion = 'advanced';
			renderSettingsPanel();
			setupOptionsSortable();
			setupAddressSortable();
		} );

		// Conditional logic — condition field/operator changes.
		$( document ).on( 'change', '.bfcl-cond-field, .bfcl-cond-op', function () {
			var selected = getSelectedFieldLocation();
			if ( ! selected ) return;
			var ci = parseInt( $( this ).data( 'ci' ), 10 );
			var cond = selected.field.conditional;
			if ( ! cond || ! cond.conditions || ! cond.conditions[ ci ] ) return;
			if ( $( this ).hasClass( 'bfcl-cond-field' ) ) {
				cond.conditions[ ci ].field_id = $( this ).val();
			} else {
				cond.conditions[ ci ].operator = $( this ).val();
			}
			state.activeSettingsAccordion = 'advanced';
			renderSettingsPanel();
			setupOptionsSortable();
			setupAddressSortable();
		} );

		// Conditional logic — condition value input.
		$( document ).on( 'input', '.bfcl-cond-value', function () {
			var selected = getSelectedFieldLocation();
			if ( ! selected ) return;
			var ci = parseInt( $( this ).data( 'ci' ), 10 );
			var cond = selected.field.conditional;
			if ( cond && cond.conditions && cond.conditions[ ci ] ) {
				cond.conditions[ ci ].value = $( this ).val();
			}
		} );

		// Conditional logic — add condition.
		$( document ).on( 'click', '.bfcl-add-condition', function () {
			var selected = getSelectedFieldLocation();
			if ( ! selected ) return;
			selected.field.conditional.conditions.push( { field_id: '', operator: 'is', value: '' } );
			state.activeSettingsAccordion = 'advanced';
			renderSettingsPanel();
			setupOptionsSortable();
			setupAddressSortable();
		} );

		// Conditional logic — remove condition.
		$( document ).on( 'click', '.bfcl-remove-cond', function () {
			var selected = getSelectedFieldLocation();
			if ( ! selected ) return;
			var ci = parseInt( $( this ).data( 'ci' ), 10 );
			selected.field.conditional.conditions.splice( ci, 1 );
			if ( ! selected.field.conditional.conditions.length ) {
				selected.field.conditional.conditions.push( { field_id: '', operator: 'is', value: '' } );
			}
			state.activeSettingsAccordion = 'advanced';
			renderSettingsPanel();
			setupOptionsSortable();
			setupAddressSortable();
		} );

		// Conditional logic — show/hide action selector.
		$( document ).on( 'change', '.bfcl-action-select', function () {
			var selected = getSelectedFieldLocation();
			if ( ! selected ) return;
			selected.field.conditional.action = $( this ).val();
		} );

		// Address field toggle.
		$( document ).on( 'change', '.shapeforms-setting-address-field', function () {
			var selected = getSelectedFieldLocation();
			if ( ! selected ) return;
			var key = $( this ).data( 'addr-key' );
			selected.field.address_fields[ key ] = $( this ).is( ':checked' );
			renderCanvas();
		} );

		// Button group clicks (label placement, button alignment).
		$( document ).on( 'click', '.shapeforms-btn-group__btn', function () {
			var $group = $( this ).closest( '.shapeforms-btn-group' );
			var val = $( this ).data( 'value' );
			$group.find( '.shapeforms-btn-group__btn' ).removeClass( 'is-active' );
			$( this ).addClass( 'is-active' );

			if ( $group.attr( 'id' ) === 'shapeforms-setting-label-placement' ) {
				var selected = getSelectedFieldLocation();
				if ( selected ) {
					selected.field.label_placement = val;
					renderCanvas();
				}
			} else if ( $group.attr( 'id' ) === 'shapeforms-setting-button-alignment' ) {
				state.formSettings.button_alignment = val;
				renderAll();
			} else if ( $group.attr( 'id' ) === 'shapeforms-setting-rep-add-align' ) {
				var repSel = getSelectedFieldLocation();
				if ( repSel ) {
					repSel.field.repeater_add_align = val;
					markDirty();
					renderCanvas();
				}
			} else if ( $group.attr( 'id' ) === 'shapeforms-setting-rep-remove-pos' ) {
				var repSelR = getSelectedFieldLocation();
				if ( repSelR ) {
					repSelR.field.repeater_remove_position = val;
					markDirty();
					renderCanvas();
				}
			}
		} );

		// Rich text editor toolbar.
		$( document ).on( 'click', '.shapeforms-richtext__toolbar button', function ( e ) {
			e.preventDefault();
			document.execCommand( $( this ).data( 'cmd' ), false, null );
			$( '#shapeforms-richtext-editor' ).focus();
		} );

		// Rich text editor content sync.
		$( document ).on( 'input', '#shapeforms-richtext-editor', function () {
			var selected = getSelectedFieldLocation();
			if ( selected ) {
				selected.field.content = $( this ).html();
				renderCanvas();
			}
		} );

		// Repeater: input change.
		$( document ).on( 'input', '.shapeforms-options-repeater__input', function () {
			syncRepeaterToField();
		} );

		// Repeater: default option change.
		$( document ).on( 'change', 'input[name="shapeforms-option-default"]', function () {
			var $label = $( this ).closest( '.shapeforms-options-repeater__default' );
			if ( $( this ).attr( 'type' ) === 'radio' ) {
				$( '.shapeforms-options-repeater__default' ).removeClass( 'is-checked' );
				$label.addClass( 'is-checked' );
			} else {
				$label.toggleClass( 'is-checked', $( this ).is( ':checked' ) );
			}
			syncRepeaterToField();
		} );


		// Repeater: add option.
		$( document ).on( 'click', '#shapeforms-option-add', function () {
			var selected = getSelectedFieldLocation();
			if ( ! selected ) return;
			selected.field.options.push( '' );
			renderSettingsPanel();
			setupOptionsSortable();
			setupAddressSortable();
			// Focus the new empty input.
			$( '#shapeforms-options-repeater .shapeforms-options-repeater__item:last-child .shapeforms-options-repeater__input' ).focus();
		} );

		// Repeater: remove option.
		$( document ).on( 'click', '.shapeforms-options-repeater__remove', function () {
			var selected = getSelectedFieldLocation();
			if ( ! selected ) return;
			// The row's position now, not the index stamped into it when the panel was
			// drawn: a drag reorders the rows without re-rendering, so the attribute
			// can point at a different option than the button that was clicked.
			var index = $( this ).closest( '.shapeforms-options-repeater__item' ).index();
			if ( index < 0 || selected.field.options.length <= 1 ) return; // Keep at least one.
			selected.field.options.splice( index, 1 );
			// The icons are positional, so the removed option's icon goes with it
			// rather than sliding onto whatever option takes its place.
			if ( Array.isArray( selected.field.option_icons ) && index < selected.field.option_icons.length ) {
				selected.field.option_icons.splice( index, 1 );
				while ( selected.field.option_icons.length && '' === selected.field.option_icons[ selected.field.option_icons.length - 1 ] ) {
					selected.field.option_icons.pop();
				}
			}
			renderSettingsPanel();
			setupOptionsSortable();
			setupAddressSortable();
			renderCanvas();
		} );

		// Product options repeater: add row.
		$( document ).on( 'click', '#shapeforms-product-option-add', function () {
			var selected = getSelectedFieldLocation();
			if ( ! selected ) return;
			if ( ! Array.isArray( selected.field.product_options ) ) {
				selected.field.product_options = [];
			}
			selected.field.product_options.push( { label: '', price: '0.00' } );
			renderSettingsPanel();
			$( '#shapeforms-product-options-repeater .shapeforms-product-option-row:last-child .shapeforms-product-option__label' ).focus();
		} );

		// Product options repeater: remove row.
		$( document ).on( 'click', '.shapeforms-product-option__remove', function () {
			var selected = getSelectedFieldLocation();
			if ( ! selected ) return;
			var idx = Number( $( this ).data( 'product-index' ) );
			if ( ! Array.isArray( selected.field.product_options ) || selected.field.product_options.length <= 1 ) return;
			selected.field.product_options.splice( idx, 1 );
			renderSettingsPanel();
			renderCanvas();
		} );

		// Product options repeater: update label/price inline.
		$( document ).on( 'input change', '.shapeforms-product-option__label, .shapeforms-product-option__price', function () {
			var selected = getSelectedFieldLocation();
			if ( ! selected || ! Array.isArray( selected.field.product_options ) ) return;
			var $row = $( this ).closest( '.shapeforms-product-option-row' );
			var idx = Number( $row.data( 'product-index' ) );
			if ( ! selected.field.product_options[ idx ] ) return;
			if ( $( this ).hasClass( 'shapeforms-product-option__label' ) ) {
				selected.field.product_options[ idx ].label = $( this ).val();
			} else {
				selected.field.product_options[ idx ].price = $( this ).val();
			}
			renderCanvas();
		} );

		// ---- Image Choice option management ----

		// Add image-choice option row.
		$( document ).on( 'click', '#shapeforms-ic-option-add', function () {
			var selected = getSelectedFieldLocation();
			if ( ! selected ) return;
			if ( ! Array.isArray( selected.field.image_choice_options ) ) {
				selected.field.image_choice_options = [];
			}
			selected.field.image_choice_options.push( { label: '', value: '', image_url: '' } );
			renderSettingsPanel();
			setupOptionsSortable();
			setupAddressSortable();
			$( '#shapeforms-ic-options-repeater .shapeforms-ic-option-row:last-child .shapeforms-ic-option__label' ).focus();
		} );

		// Remove image-choice option row.
		$( document ).on( 'click', '.shapeforms-ic-option__remove', function () {
			var selected = getSelectedFieldLocation();
			if ( ! selected || ! Array.isArray( selected.field.image_choice_options ) ) return;
			var idx = Number( $( this ).data( 'ic-index' ) );
			selected.field.image_choice_options.splice( idx, 1 );
			renderSettingsPanel();
			setupOptionsSortable();
			setupAddressSortable();
			renderCanvas();
		} );

		// Edit image-choice option label/value inline.
		$( document ).on( 'input', '.shapeforms-ic-option__label, .shapeforms-ic-option__value', function () {
			var selected = getSelectedFieldLocation();
			if ( ! selected || ! Array.isArray( selected.field.image_choice_options ) ) return;
			var $row = $( this ).closest( '.shapeforms-ic-option-row' );
			var idx = Number( $row.data( 'ic-index' ) );
			if ( ! selected.field.image_choice_options[ idx ] ) return;
			if ( $( this ).hasClass( 'shapeforms-ic-option__label' ) ) {
				selected.field.image_choice_options[ idx ].label = $( this ).val();
			} else {
				selected.field.image_choice_options[ idx ].value = $( this ).val();
			}
			renderCanvas();
		} );

		// ---- Repeater group + sub-field management ----

		// The cap belongs to the module that enforces it on save; the builder reads
		// the published value so the two can never disagree. The fallback matches the
		// historical limit, for the case where the add-on is older than this builder.
		function repeaterMaxSubFields() {
			var n = parseInt( shapeformsLiteBuilder.repeaterMaxSubFields, 10 );
			return n > 0 ? n : 8;
		}

		// Resolve the group (and optionally the sub-field) an element belongs to.
		// Every control in the panel sits inside a [data-rep-group], and every
		// sub-field control inside a .shapeforms-rep-field-row carrying its index.
		function repTarget( $el ) {
			var selected = getSelectedFieldLocation();
			if ( ! selected || 'repeater' !== selected.field.type ) { return null; }

			var groups = repeaterGroups( selected.field );
			var gi     = Number( $el.closest( '[data-rep-group]' ).data( 'rep-group' ) );
			if ( isNaN( gi ) || ! groups[ gi ] ) { return null; }

			var $row = $el.closest( '.shapeforms-rep-field-row' );
			var fi   = $row.length ? Number( $row.data( 'rep-index' ) ) : -1;

			return {
				selected: selected,
				groups:   groups,
				group:    groups[ gi ],
				gi:       gi,
				field:    fi >= 0 ? groups[ gi ].fields[ fi ] : null,
				fi:       fi,
				commit:   function ( rerender ) {
					setRepeaterGroups( selected.field, groups );
					markDirty();
					if ( rerender ) {
						renderSettingsPanel();
						setupOptionsSortable();
						setupAddressSortable();
					}
					renderCanvas();
				}
			};
		}

		// Add a group.
		$( document ).on( 'click', '#shapeforms-rep-group-add', function () {
			var selected = getSelectedFieldLocation();
			if ( ! selected || 'repeater' !== selected.field.type ) { return; }
			var groups = repeaterGroups( selected.field );
			if ( groups.length >= 10 ) { return; } // MAX_GROUPS
			groups.push( {
				id: 'g_' + Math.random().toString( 36 ).slice( 2, 8 ),
				label: '', columns: 0, collapsible: false, open: true, fields: []
			} );
			setRepeaterGroups( selected.field, groups );
			markDirty();
			renderSettingsPanel();
			setupOptionsSortable();
			setupAddressSortable();
			renderCanvas();
			$( '#shapeforms-rep-groups .shapeforms-rep-group:last-child .shapeforms-rep-group__label' ).focus();
		} );

		// Remove a group, and every sub-field in it.
		$( document ).on( 'click', '.shapeforms-rep-group__remove', function () {
			var t = repTarget( $( this ) );
			if ( ! t || t.groups.length < 2 ) { return; }
			t.groups.splice( t.gi, 1 );
			t.commit( true );
		} );

		// Group name. A group can only collapse behind a name, so gaining or losing
		// one adds or removes the Collapsible controls — that transition is the only
		// time this re-renders, because a rebuild mid-word would steal the caret.
		$( document ).on( 'input', '.shapeforms-rep-group__label', function () {
			var t = repTarget( $( this ) );
			if ( ! t ) { return; }

			var had = '' !== ( t.group.label || '' );
			var val = $( this ).val();
			var has = '' !== val;

			t.group.label = val;

			if ( had === has ) {
				t.commit( false );
				return;
			}

			// Clearing the name also clears the state those controls held: a nameless
			// group is not collapsible, and leaving the flag set would silently
			// resurrect it if the user typed a name again later.
			if ( ! has ) {
				t.group.collapsible = false;
			}

			t.commit( true );

			// Put the caret back where it was — the panel was just rebuilt.
			var $again = $( '#shapeforms-rep-groups .shapeforms-rep-group' ).eq( t.gi ).find( '.shapeforms-rep-group__label' );
			$again.focus();
			var el = $again.get( 0 );
			if ( el && el.setSelectionRange ) { el.setSelectionRange( val.length, val.length ); }
		} );

		// Group column count. 0 inherits the repeater's own column count.
		$( document ).on( 'change', '.shapeforms-rep-group__cols', function () {
			var t = repTarget( $( this ) );
			if ( ! t ) { return; }
			t.group.columns = parseInt( $( this ).val(), 10 ) || 0;
			t.commit( false );
		} );

		// Add a sub-field to this group.
		$( document ).on( 'click', '.shapeforms-rep-field-add', function () {
			var t = repTarget( $( this ) );
			if ( ! t ) { return; }
			if ( repeaterFieldCount( t.groups ) >= repeaterMaxSubFields() ) { return; }
			t.group.fields.push( {
				id: 'sf_' + Math.random().toString( 36 ).slice( 2, 8 ),
				type: 'text', label: '', placeholder: '', required: false, full_width: false
			} );
			t.commit( true );
			$( '#shapeforms-rep-groups .shapeforms-rep-group' ).eq( t.gi )
				.find( '.shapeforms-rep-field-row:last-child .shapeforms-rep-field__label' ).focus();
		} );

		// Remove a sub-field.
		$( document ).on( 'click', '.shapeforms-rep-field__remove', function () {
			var t = repTarget( $( this ) );
			if ( ! t || ! t.field ) { return; }
			t.group.fields.splice( t.fi, 1 );
			t.commit( true );
		} );

		// Sub-field label.
		$( document ).on( 'input', '.shapeforms-rep-field__label', function () {
			var t = repTarget( $( this ) );
			if ( ! t || ! t.field ) { return; }
			t.field.label = $( this ).val();
			t.commit( false );
		} );

		// Sub-field placeholder.
		$( document ).on( 'input', '.shapeforms-rep-field__placeholder', function () {
			var t = repTarget( $( this ) );
			if ( ! t || ! t.field ) { return; }
			t.field.placeholder = $( this ).val();
			t.commit( false );
		} );

		// Sub-field required.
		$( document ).on( 'change', '.shapeforms-rep-field__required', function () {
			var t = repTarget( $( this ) );
			if ( ! t || ! t.field ) { return; }
			t.field.required = $( this ).is( ':checked' );
			t.commit( false );
		} );

		// Hide the sub-field's label.
		$( document ).on( 'change', '.shapeforms-rep-field__hidelabel', function () {
			var t = repTarget( $( this ) );
			if ( ! t || ! t.field ) { return; }
			t.field.hide_label = $( this ).is( ':checked' );
			t.commit( false );
		} );

		// Sub-field spans every column of its group.
		$( document ).on( 'change', '.shapeforms-rep-field__full', function () {
			var t = repTarget( $( this ) );
			if ( ! t || ! t.field ) { return; }
			t.field.full_width = $( this ).is( ':checked' );
			t.commit( false );
		} );

		// ── Per-field Checkbox & Radio overrides ──
		// A control either sits in a repeater sub-field row, where the sub-field owns
		// the value, or in the field panel, where the selected field does. Everything
		// else — every control, every handler — is the Style tab's, unchanged.
		function bfChoiceStyleTarget( $el ) {
			if ( $el.closest( '.shapeforms-rep-field-row' ).length ) {
				var t = repTarget( $el );
				return t && t.field ? t : null;
			}

			var selected = getSelectedFieldLocation();
			if ( ! selected ) { return null; }

			return {
				field:  selected.field,
				commit: function ( rerender ) {
					markDirty();
					if ( rerender ) { renderSettingsPanel(); }
					renderCanvas();
				}
			};
		}

		// Toggled in place rather than by re-rendering: nothing else in the panel
		// should move, and the controls keep whatever the user was part-way through.
		$( document ).on( 'click', '.shapeforms-choice-style__toggle', function () {
			var t = bfChoiceStyleTarget( $( this ) );
			if ( ! t || ! t.field ) { return; }

			var open = ! bfChoiceOpen[ t.field.id ];
			bfChoiceOpen[ t.field.id ] = open;

			$( this ).attr( 'aria-expanded', open ? 'true' : 'false' )
				.closest( '.shapeforms-choice-style' ).toggleClass( 'is-open', open );
		} );

		// Switching the treatment changes which controls are relevant, so this one
		// does re-render the panel. The disclosure stays open across it.
		$( document ).on( 'click', '.shapeforms-choice-style__modegroup .shapeforms-btn-group__btn', function () {
			var t = bfChoiceStyleTarget( $( this ) );
			if ( ! t || ! t.field ) { return; }

			var next = $( this ).data( 'choice-mode' );
			if ( next === ( t.field.choice_style || 'inherit' ) ) { return; }

			t.field.choice_style = next;
			t.commit( true );
		} );

		// Clears every override on the field, which is what "follows the form style"
		// means. Re-renders, because every control's displayed value has changed.
		$( document ).on( 'click', '.shapeforms-choice-style__reset', function () {
			var t = bfChoiceStyleTarget( $( this ) );
			if ( ! t || ! t.field ) { return; }
			t.field.choice_styles = {};
			t.commit( true );
		} );

		// Sub-field type. Re-renders so the options editor and the placeholder control
		// appear or disappear for the new type.
		$( document ).on( 'change', '.shapeforms-rep-field__type', function () {
			var t = repTarget( $( this ) );
			if ( ! t || ! t.field ) { return; }
			t.field.type = $( this ).val();
			t.commit( true );
		} );

		// ── Sub-field options ──
		// Every edit rebuilds the whole list from the rows rather than patching one
		// entry, so the options and their icons are always written together and the
		// stored order is always the order on screen.
		function commitSubOptions( $el, rerender ) {
			var t = repTarget( $el );
			if ( ! t || ! t.field ) { return null; }

			var $list = $el.closest( '.shapeforms-rep-field-row' ).find( '.shapeforms-rep-opts' );
			if ( $list.length ) {
				var collected = collectSubOptions( $list );
				t.field.options       = collected.options;
				t.field.option_icons  = collected.icons;
				t.field.default_value = collected.defaults;
			}

			t.commit( !! rerender );
			return t;
		}

		// Typing: no re-render, or the input would be taken out from under the caret.
		$( document ).on( 'input', '.shapeforms-rep-opt__value', function () {
			commitSubOptions( $( this ), false );
		} );

		// Which option a fresh row opens with selected.
		$( document ).on( 'change', '.shapeforms-rep-opt__default input', function () {
			var $list = $( this ).closest( '.shapeforms-rep-opts' );

			if ( 'radio' === this.type ) {
				$list.find( '.shapeforms-rep-opt__default' ).removeClass( 'is-checked' );
			}
			$( this ).closest( '.shapeforms-rep-opt__default' ).toggleClass( 'is-checked', this.checked );
			commitSubOptions( $( this ), false );
		} );

		// A radio group cannot express "nothing selected" once something is, and
		// "this field opens blank" is a legitimate choice — so clicking the chosen
		// option again clears it. The state has to be read on mousedown: by click
		// time the browser has already marked it checked, and no change event fires
		// when the selected radio is re-selected.
		//
		// Bound to the LABEL, not the input. The input is visually hidden and takes
		// no pointer events, so the press lands on the label; clicking a label
		// synthesises a click on its input but never a mousedown, which is why a
		// handler on the input would never see the press at all.
		$( document ).on( 'mousedown', '.shapeforms-rep-opt__default', function () {
			var input = this.querySelector( 'input[type="radio"]' );
			if ( input ) { input.setAttribute( 'data-bf-was', input.checked ? '1' : '0' ); }
		} );

		$( document ).on( 'click', '.shapeforms-rep-opt__default input[type="radio"]', function () {
			if ( '1' === this.getAttribute( 'data-bf-was' ) ) {
				this.checked = false;
				$( this ).closest( '.shapeforms-rep-opt__default' ).removeClass( 'is-checked' );
				commitSubOptions( $( this ), false );
			}
			this.removeAttribute( 'data-bf-was' );
		} );

		$( document ).on( 'click', '.shapeforms-rep-opt__add', function () {
			var $row = $( this ).closest( '.shapeforms-rep-field-row' );
			var gi   = Number( $row.closest( '[data-rep-group]' ).data( 'rep-group' ) );
			var fi   = Number( $row.data( 'rep-index' ) );
			// Read the rows first: the new option is appended to what is on screen,
			// not to a model that may be a keystroke behind it.
			var t = commitSubOptions( $( this ), false );
			if ( ! t || ! t.field ) { return; }

			t.field.options = ( t.field.options || [] ).concat( '' );
			t.commit( true );

			// Re-rendered, so the row is a new element: find it again by position and
			// put the caret in the option that was just added.
			$( '#shapeforms-rep-groups .shapeforms-rep-group' ).eq( gi )
				.find( '.shapeforms-rep-field-row' ).eq( fi )
				.find( '.shapeforms-rep-opt:last-child .shapeforms-rep-opt__value' ).trigger( 'focus' );
		} );

		$( document ).on( 'click', '.shapeforms-rep-opt__remove', function () {
			var $opt  = $( this ).closest( '.shapeforms-rep-opt' );
			var index = $opt.index();
			var t     = commitSubOptions( $( this ), false );
			if ( ! t || ! t.field || index < 0 ) { return; }

			// A choice field with no options renders nothing, so the last one stays.
			if ( ( t.field.options || [] ).length <= 1 ) { return; }

			t.field.options.splice( index, 1 );
			// Positional, so the removed option's icon goes with it rather than
			// sliding onto whatever option takes its place.
			if ( Array.isArray( t.field.option_icons ) && index < t.field.option_icons.length ) {
				t.field.option_icons.splice( index, 1 );
				while ( t.field.option_icons.length && '' === t.field.option_icons[ t.field.option_icons.length - 1 ] ) {
					t.field.option_icons.pop();
				}
			}
			t.commit( true );
		} );

		// Which options list a trigger edits. Inside a repeater sub-field row it is
		// that sub-field's; anywhere else it is the selected field's. One resolver, so
		// a trigger behaves the same in both editors.
		function bfOpticonTarget( $btn ) {
			if ( $btn.closest( '.shapeforms-rep-field-row' ).length ) {
				var t = repTarget( $btn );
				if ( ! t || ! t.field ) { return null; }
				return { holder: t.field, commit: function () { t.commit( false ); } };
			}

			var selected = getSelectedFieldLocation();
			if ( ! selected || ! selected.field ) { return null; }
			return {
				holder: selected.field,
				commit: function () { markDirty(); renderCanvas(); }
			};
		}

		// Built once, on first use, and reused: the grid holds every icon in the
		// registry, so rebuilding it per click would be the most expensive thing the
		// settings panel does. It lives on <body> rather than inside the panel so it
		// can overhang the panel's edge and its own scroll container.
		var $bfIconPop   = null;
		var bfIconPopFor = null;
		// Where the trigger was when the popover was placed, so a later scroll can
		// tell "the panel moved under it" from "a scroll that was already queued".
		var bfIconPopAt  = 0;

		function bfIconPopover() {
			if ( $bfIconPop ) { return $bfIconPop; }

			var l    = shapeformsLiteBuilder.labels || {};
			var set  = bfChoiceIconSet();
			var pick = l.chooseIcon || 'Choose an icon';
			var grid = '';

			Object.keys( set ).forEach( function ( key ) {
				var name = set[ key ].label || key;
				grid += '<button type="button" class="shapeforms-iconpop__item" data-icon-key="' + escapeHtml( key ) + '"' +
					// Searched against both the human name and the key, so "cart" finds
					// it whether the translation matches or not.
					' data-icon-name="' + escapeHtml( ( name + ' ' + key ).toLowerCase() ) + '"' +
					' title="' + escapeHtml( name ) + '">' + bfChoiceIconSvg( key, 18 ) + '</button>';
			} );

			// Three sources, three tabs. The built-in set is a KEY — it survives a site
			// move and takes the pill's colour — while the other two are a file in the
			// media library, which does neither, so they are worth telling apart even
			// though both end up stored the same way.
			var tabs = [
				[ 'icon',  l.iconTabIcons || 'Icons' ],
				[ 'svg',   l.iconTabSvg   || 'SVG' ],
				[ 'image', l.iconTabImage || 'Image' ]
			].map( function ( t, i ) {
				return '<button type="button" class="shapeforms-iconpop__tab' + ( 0 === i ? ' is-active' : '' ) +
					'" data-icon-tab="' + t[0] + '" role="tab" aria-selected="' + ( 0 === i ? 'true' : 'false' ) + '">' +
					escapeHtml( t[1] ) + '</button>';
			} ).join( '' );

			// One card, not a box with a button beneath it: the whole panel is the
			// action, so the tab has something to say in its empty state instead of
			// reading as a control that failed to load.
			var mediaPanel = function ( kind, title, hint ) {
				return '<div class="shapeforms-iconpop__panel" data-icon-panel="' + kind + '" hidden>' +
					'<button type="button" class="shapeforms-iconpop__drop" data-icon-media="' + kind + '"' +
						' data-drop-title="' + escapeHtml( title ) + '" data-drop-hint="' + escapeHtml( hint ) + '">' +
						'<span class="shapeforms-iconpop__drop-figure" data-icon-preview="' + kind + '"></span>' +
						'<span class="shapeforms-iconpop__drop-text">' +
							'<span class="shapeforms-iconpop__drop-title"></span>' +
							'<span class="shapeforms-iconpop__drop-hint"></span>' +
						'</span>' +
					'</button>' +
				'</div>';
			};

			$bfIconPop = $(
				'<div class="shapeforms-iconpop" role="dialog" aria-label="' + escapeHtml( pick ) + '" hidden>' +
					'<div class="shapeforms-iconpop__tabs" role="tablist">' + tabs + '</div>' +

					'<div class="shapeforms-iconpop__panel" data-icon-panel="icon">' +
						'<input type="search" class="shapeforms-iconpop__search" placeholder="' + escapeHtml( l.searchIcons || 'Search icons' ) + '" aria-label="' + escapeHtml( l.searchIcons || 'Search icons' ) + '">' +
						'<div class="shapeforms-iconpop__grid">' + grid + '</div>' +
						'<p class="shapeforms-iconpop__empty" hidden>' + escapeHtml( l.noIconsFound || 'No icons match.' ) + '</p>' +
					'</div>' +

					mediaPanel( 'svg', l.chooseSvg || 'Upload or choose an SVG', l.svgHint || 'SVG files from your Media Library. Every upload is cleaned before it is stored.' ) +
					mediaPanel( 'image', l.chooseImage || 'Upload or choose an image', l.imageHint || 'PNG, JPG, GIF or WebP from your Media Library.' ) +

					// One clear action, on every tab: an option that already has an icon
					// should never need the user to work out which tab it came from to
					// take it off again.
					'<div class="shapeforms-iconpop__foot">' +
						'<button type="button" class="shapeforms-iconpop__none" disabled>' + escapeHtml( l.noIcon || 'Remove icon' ) + '</button>' +
					'</div>' +
				'</div>'
			).appendTo( document.body );

			return $bfIconPop;
		}

		function bfCloseIconPop() {
			if ( $bfIconPop ) { $bfIconPop.attr( 'hidden', true ); }
			bfIconPopFor = null;
		}

		// Paints one media card. Empty, it says what the tab accepts; filled, it shows
		// the file and the name it will be recognised by, which is the only thing a
		// thumbnail the size of a favicon cannot tell you on its own.
		function bfPaintDropCard( $card, icon ) {
			var l    = shapeformsLiteBuilder.labels || {};
			var $fig = $card.find( '.shapeforms-iconpop__drop-figure' );

			if ( bfIconIsImage( icon ) ) {
				// Basename of the path, so a query string or a long upload folder does
				// not push the actual name out of view.
				var path = String( icon.url ).split( /[?#]/ )[ 0 ];
				var name = decodeURIComponent( path.substring( path.lastIndexOf( '/' ) + 1 ) ) || icon.url;

				$card.addClass( 'is-filled' );
				$fig.html( '<img src="' + escapeHtml( icon.url ) + '" alt="">' );
				$card.find( '.shapeforms-iconpop__drop-title' ).text( name );
				$card.find( '.shapeforms-iconpop__drop-hint' ).text( l.changeFile || 'Click to choose a different file' );
				return;
			}

			$card.removeClass( 'is-filled' );
			$fig.html( bfChoiceIconSvg( 'upload', 20 ) );
			$card.find( '.shapeforms-iconpop__drop-title' ).text( $card.attr( 'data-drop-title' ) || '' );
			$card.find( '.shapeforms-iconpop__drop-hint' ).text( $card.attr( 'data-drop-hint' ) || '' );
		}

		// Shows one tab. Kept separate from opening so the tab buttons and the
		// "open on the tab this option is already using" rule share it.
		function bfIconPopTab( name ) {
			var $pop = bfIconPopover();

			$pop.find( '.shapeforms-iconpop__tab' ).each( function () {
				var on = $( this ).attr( 'data-icon-tab' ) === name;
				$( this ).toggleClass( 'is-active', on ).attr( 'aria-selected', on ? 'true' : 'false' );
			} );
			$pop.find( '.shapeforms-iconpop__panel' ).each( function () {
				$( this ).attr( 'hidden', $( this ).attr( 'data-icon-panel' ) === name ? null : true );
			} );
		}

		function bfOpenIconPop( $btn ) {
			var $pop = bfIconPopover();
			var cur  = bfTriggerIcon( $btn );

			bfIconPopFor = $btn[ 0 ];

			$pop.find( '.shapeforms-iconpop__search' ).val( '' );
			$pop.find( '.shapeforms-iconpop__item' ).removeClass( 'is-hidden is-active' );
			$pop.find( '.shapeforms-iconpop__empty' ).attr( 'hidden', true );
			$pop.find( '.shapeforms-iconpop__none' ).prop( 'disabled', ! cur );

			// Whatever this option already has is what the picker shows first: a
			// built-in glyph marked in the grid, a file shown on the tab it came from.
			var onSvgTab = bfIconIsImage( cur ) && /\.svg(\?|#|$)/i.test( cur.url );

			$pop.find( '.shapeforms-iconpop__drop' ).each( function () {
				var kind = $( this ).attr( 'data-icon-media' );
				// The file is shown on its own tab only. The other card stays empty, so
				// the two tabs never claim to hold the same thing.
				bfPaintDropCard( $( this ), ( bfIconIsImage( cur ) && ( 'svg' === kind ) === onSvgTab ) ? cur : '' );
			} );

			if ( bfIconIsImage( cur ) ) {
				bfIconPopTab( onSvgTab ? 'svg' : 'image' );
			} else {
				if ( cur ) {
					$pop.find( '.shapeforms-iconpop__item[data-icon-key="' + cur.replace( /"/g, '' ) + '"]' ).addClass( 'is-active' );
				}
				bfIconPopTab( 'icon' );
			}

			$pop.removeAttr( 'hidden' );

			// Anchored to the trigger in viewport coordinates, and flipped above it
			// when there is no room below — the option rows near the bottom of a long
			// settings panel are exactly where a picker would otherwise open offscreen.
			var r = $btn[ 0 ].getBoundingClientRect();
			var w = $pop.outerWidth();
			var h = $pop.outerHeight();
			var left = Math.min( Math.max( 8, r.left ), Math.max( 8, window.innerWidth - w - 8 ) );
			var top  = r.bottom + 6;

			if ( top + h > window.innerHeight - 8 ) {
				top = Math.max( 8, r.top - h - 6 );
			}

			$pop.css( { left: Math.round( left ) + 'px', top: Math.round( top ) + 'px' } );
			bfIconPopAt = r.top;

			if ( ! $pop.find( '[data-icon-panel="icon"]' ).attr( 'hidden' ) ) {
				$pop.find( '.shapeforms-iconpop__search' ).trigger( 'focus' );
			}
		}

		// Writes one icon: model, trigger and canvas, in that order. Both the grid and
		// the media frames end here, so a built-in glyph and an uploaded file are
		// stored and drawn by exactly the same path.
		function bfApplyIcon( $btn, icon ) {
			var t = $btn.length ? bfOpticonTarget( $btn ) : null;
			// The row's position NOW, not the index stamped into the trigger when the
			// panel was drawn: both editors reorder by drag without re-rendering, so
			// the attribute can point at a different option than the one clicked.
			var $opt = $btn.closest( '.shapeforms-rep-opt, .shapeforms-options-repeater__item' );
			var idx  = $opt.length ? $opt.index() : parseInt( $btn.attr( 'data-opt-index' ), 10 );

			if ( ! t || isNaN( idx ) || idx < 0 ) { return false; }

			bfSetOptionIcon( t.holder, idx, bfNormalizeIcon( icon ) );
			// Repainted in place, not re-rendered: the settings panel is where the
			// user is working, and rebuilding it would scroll them away from the
			// option they just changed.
			bfPaintOpticon( $btn, icon );
			t.commit();
			return true;
		}

		$( document ).on( 'click', '[data-bf-opticon]', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			if ( bfIconPopFor === this ) { bfCloseIconPop(); return; }
			bfOpenIconPop( $( this ) );
		} );

		$( document ).on( 'click', '.shapeforms-iconpop__tab', function ( e ) {
			e.preventDefault();
			bfIconPopTab( $( this ).attr( 'data-icon-tab' ) );
			if ( 'icon' === $( this ).attr( 'data-icon-tab' ) ) {
				bfIconPopover().find( '.shapeforms-iconpop__search' ).trigger( 'focus' );
			}
		} );

		$( document ).on( 'input', '.shapeforms-iconpop__search', function () {
			var q     = $.trim( ( $( this ).val() || '' ).toString().toLowerCase() );
			var $pop  = bfIconPopover();
			var shown = 0;

			$pop.find( '.shapeforms-iconpop__item' ).each( function () {
				var hit = ! q || ( $( this ).attr( 'data-icon-name' ) || '' ).indexOf( q ) !== -1;
				$( this ).toggleClass( 'is-hidden', ! hit );
				if ( hit ) { shown++; }
			} );

			$pop.find( '.shapeforms-iconpop__empty' ).attr( 'hidden', shown > 0 ? true : null );
		} );

		$( document ).on( 'click', '.shapeforms-iconpop__item', function ( e ) {
			e.preventDefault();
			var $btn = bfIconPopFor ? $( bfIconPopFor ) : $();
			bfApplyIcon( $btn, $( this ).attr( 'data-icon-key' ) || '' );
			bfCloseIconPop();
			$btn.trigger( 'focus' );
		} );

		$( document ).on( 'click', '.shapeforms-iconpop__none', function ( e ) {
			e.preventDefault();
			var $btn = bfIconPopFor ? $( bfIconPopFor ) : $();
			bfApplyIcon( $btn, '' );
			bfCloseIconPop();
			$btn.trigger( 'focus' );
		} );

		// The media library. The frame takes over the screen, so the trigger is
		// captured BEFORE the popover closes — by the time 'select' fires there is no
		// open popover left to ask which option this was for.
		$( document ).on( 'click', '[data-icon-media]', function ( e ) {
			e.preventDefault();

			var $btn = bfIconPopFor ? $( bfIconPopFor ) : $();
			var kind = $( this ).attr( 'data-icon-media' );
			var l    = shapeformsLiteBuilder.labels || {};

			if ( ! $btn.length || typeof wp === 'undefined' || ! wp.media ) { return; }

			bfCloseIconPop();

			var frame = wp.media( {
				title: 'svg' === kind ? ( l.chooseSvg || 'Upload or choose an SVG' ) : ( l.chooseImage || 'Upload or choose an image' ),
				button: { text: l.useThisFile || 'Use this file' },
				multiple: false,
				// An SVG is an image as far as the library is concerned, so the SVG tab
				// narrows to that one mime rather than to a different kind of thing.
				library: { type: 'svg' === kind ? 'image/svg+xml' : 'image' }
			} );

			frame.on( 'select', function () {
				var a = frame.state().get( 'selection' ).first();
				if ( ! a ) { return; }
				a = a.toJSON();
				if ( ! a.url ) { return; }

				bfApplyIcon( $btn, { type: 'image', id: Number( a.id ) || 0, url: String( a.url ) } );
				$btn.trigger( 'focus' );
			} );

			frame.open();
		} );

		// Anything that moves the trigger out from under the popover closes it, since
		// it is positioned once rather than tracked.
		$( document ).on( 'mousedown', function ( e ) {
			if ( ! bfIconPopFor ) { return; }
			if ( $( e.target ).closest( '.shapeforms-iconpop, [data-bf-opticon]' ).length ) { return; }
			bfCloseIconPop();
		} );

		$( document ).on( 'keydown', function ( e ) {
			if ( bfIconPopFor && 27 === e.keyCode ) {
				var $btn = $( bfIconPopFor );
				bfCloseIconPop();
				$btn.trigger( 'focus' );
			}
		} );

		$( window ).on( 'resize', function () {
			if ( bfIconPopFor ) { bfCloseIconPop(); }
		} );

		// Scroll does not bubble, so a delegated handler would never see the settings
		// panel scrolling under the popover. A capture-phase listener does, whichever
		// element is doing the scrolling.
		//
		// It closes on the trigger having MOVED, not on the event: bringing a trigger
		// into view and clicking it is one gesture, and the scroll it queues arrives
		// after the click — closing on the event alone would shut the popover the
		// instant it opened.
		document.addEventListener( 'scroll', function () {
			if ( ! bfIconPopFor ) { return; }
			if ( Math.abs( bfIconPopFor.getBoundingClientRect().top - bfIconPopAt ) > 1 ) {
				bfCloseIconPop();
			}
		}, true );

		// Group collapsible, and its default state.
		$( document ).on( 'change', '.shapeforms-rep-group__collapsible', function () {
			var t = repTarget( $( this ) );
			if ( ! t ) { return; }
			t.group.collapsible = $( this ).is( ':checked' );
			t.commit( true );
		} );

		$( document ).on( 'change', '.shapeforms-rep-group__open', function () {
			var t = repTarget( $( this ) );
			if ( ! t ) { return; }
			t.group.open = $( this ).is( ':checked' );
			t.commit( false );
		} );

		// Row card title. Empty means the row renders without a header, which is what
		// every repeater built before this did.
		$( document ).on( 'input', '#shapeforms-setting-rep-row-label', function () {
			var selected = getSelectedFieldLocation();
			if ( ! selected ) { return; }
			selected.field.repeater_row_label = $( this ).val();
			markDirty();
			renderCanvas();
		} );

		// Min/Max decide whether a Remove button can ever exist, and the panel carries
		// its two settings only when it can. The bounds are read from the inputs, not
		// from the field: jQuery runs delegated handlers in REGISTRATION order and the
		// generic settings handler that stores them is registered further down this
		// file, so reading the field here would always be one keystroke behind.
		// Re-renders only on the transition — a rebuild per keystroke would take the
		// input out from under the caret.
		$( document ).on( 'input', '#shapeforms-setting-rep-min, #shapeforms-setting-rep-max', function () {
			var selected = getSelectedFieldLocation();
			if ( ! selected || 'repeater' !== selected.field.type ) { return; }
			var id   = this.id;
			var min  = Math.max( 1, Math.min( 10, parseInt( $( '#shapeforms-setting-rep-min' ).val(), 10 ) || 1 ) );
			var max  = Math.max( 1, Math.min( 20, parseInt( $( '#shapeforms-setting-rep-max' ).val(), 10 ) || 5 ) );
			var was  = $( '#shapeforms-setting-rep-remove-label' ).length > 0;
			if ( was === ( max > min ) ) { return; }
			// The generic handler has not stored these yet; do it here so the rebuilt
			// panel renders the bounds just typed rather than the previous pair.
			selected.field.repeater_min_rows = min;
			selected.field.repeater_max_rows = max;
			markDirty();
			renderSettingsPanel();
			renderCanvas();
			$( '#' + id ).focus();
		} );

		// Drag to reorder groups, and to move a sub-field within or BETWEEN groups.
		// The model is rebuilt from the DOM afterwards rather than tracked during the
		// drag, so a cross-group move needs no special case.
		function setupRepeaterSortables() {
			var list = document.getElementById( 'shapeforms-rep-groups' );
			if ( ! list || typeof Sortable === 'undefined' ) { return; }

			// Both lists are NAMED. canAccept() only compares names when both sides
			// have one, so a nameless list accepts anything the pointer reaches —
			// which put the canvas (name 'shapeforms-fields') and these panel lists in
			// the same pool, and a sub-field could be dropped into the form itself.
			Sortable.create( list, {
				group: { name: 'shapeforms-rep-groups', put: true },
				draggable: '.shapeforms-rep-group',
				handle: '.shapeforms-rep-group__drag',
				onEnd: syncRepeaterFromDom
			} );

			$( '#shapeforms-rep-groups .shapeforms-rep-group__fields' ).each( function () {
				Sortable.create( this, {
					group: { name: 'shapeforms-rep-fields', put: true },
					draggable: '.shapeforms-rep-field-row',
					handle: '.shapeforms-rep-field__drag',
					onEnd: syncRepeaterFromDom
				} );
			} );

			// One sortable per sub-field's option list, each with a name of its own.
			// Options belong to the sub-field they are under, and a shared name would
			// put every list in one pool — an option could then be dragged out of one
			// choice field and into another, which is not a thing a form can express.
			$( '#shapeforms-rep-groups .shapeforms-rep-opts' ).each( function ( i ) {
				Sortable.create( this, {
					group: { name: 'shapeforms-rep-opts-' + i, put: true },
					draggable: '.shapeforms-rep-opt',
					handle: '.shapeforms-rep-opt__drag',
					onEnd: function ( evt ) {
						// Re-read the rows: the collector takes DOM order, so the move
						// and the icons that travelled with it are both already right.
						commitSubOptions( $( evt.item ), false );
					}
				} );
			} );
		}

		function syncRepeaterFromDom() {
			var selected = getSelectedFieldLocation();
			if ( ! selected || 'repeater' !== selected.field.type ) { return; }

			var before = repeaterGroups( selected.field );
			var byId   = {};
			before.forEach( function ( g ) {
				g.fields.forEach( function ( f ) { byId[ f.id ] = f; } );
			} );

			var groups = [];
			$( '#shapeforms-rep-groups .shapeforms-rep-group' ).each( function () {
				var old    = before[ Number( $( this ).data( 'rep-group' ) ) ] || {};
				var fields = [];
				$( this ).find( '.shapeforms-rep-field-row' ).each( function () {
					var f = byId[ $( this ).data( 'rep-id' ) ];
					if ( f ) { fields.push( f ); }
				} );
				groups.push( {
					id:          old.id || 'g_' + Math.random().toString( 36 ).slice( 2, 8 ),
					label:       old.label || '',
					columns:     old.columns || 0,
					collapsible: !! old.collapsible,
					open:        false !== old.open,
					fields:      fields
				} );
			} );

			setRepeaterGroups( selected.field, groups );
			markDirty();
			renderSettingsPanel();
			setupOptionsSortable();
			setupAddressSortable();
			renderCanvas();
		}


		$( '#shapeforms-field-library' ).on(
			'click',
			'.shapeforms-library-item',
			function () {
				addFieldToActiveColumn( $( this ).data( 'field-type' ) );
			}
		);

		$( document ).on(
			'click',
			'.shapeforms-column',
			function ( event ) {
				var $column = $( this );

				if ( $( event.target ).closest( '.shapeforms-canvas-field-actions, .shapeforms-canvas-field' ).length ) {
					return;
				}

				// An explicit pick — this is the one place the author says
				// "put the next field HERE", and the field library honours it.
				setActiveColumn( Number( $column.data( 'row-index' ) ), Number( $column.data( 'column-index' ) ), true );
				renderCanvas();
			}
		);

		$( document ).on(
			'click',
			'.shapeforms-canvas-submit__button',
			function ( event ) {
				event.preventDefault();
				event.stopPropagation();
				state.selectedFieldId = submitButtonId;
				switchEditorView( 'builder' );
				switchSidebarTab( 'settings' );
				renderAll();
			}
		);

		$( document ).on(
			'click',
			'.shapeforms-canvas-field',
			function ( event ) {
				var location;

				if ( $( event.target ).closest( '.shapeforms-canvas-field-actions' ).length ) {
					return;
				}

				state.selectedFieldId = $( this ).data( 'field-id' );
				state.selectedRowIndex = null;
				state.activeSettingsAccordion = 'settings';
				location = getSelectedFieldLocation();

				if ( location ) {
					setActiveColumn( location.rowIndex, location.columnIndex );
					switchEditorView( 'builder' );
					switchSidebarTab( 'settings' );
				}

				renderAll();
			}
		);

		$( document ).on(
			'click',
			'.shapeforms-edit-field',
			function ( event ) {
				var $field = $( this ).closest( '.shapeforms-canvas-field' );

				event.stopPropagation();
				state.selectedFieldId = $field.data( 'field-id' );
				state.selectedRowIndex = null;
				switchEditorView( 'builder' );
				switchSidebarTab( 'settings' );
				renderAll();
			}
		);

		$( document ).on(
			'click',
			'.shapeforms-editor-tab',
			function () {
				switchEditorView( $( this ).data( 'editor-tab' ) );
			}
		);

		$( document ).on(
			'click',
			'.shapeforms-sidebar-tab',
			function () {
				switchSidebarTab( $( this ).data( 'tab' ) );
			}
		);

		$( document ).on(
			'click',
			'.shapeforms-duplicate-field',
			function ( event ) {
				event.stopPropagation();
				duplicateField( $( this ).closest( '.shapeforms-canvas-field' ).data( 'field-id' ) );
			}
		);

		$( document ).on(
			'click',
			'.shapeforms-delete-field',
			function ( event ) {
				event.stopPropagation();
				deleteField( $( this ).closest( '.shapeforms-canvas-field' ).data( 'field-id' ) );
			}
		);


		$( document ).on(
			'click',
			'.shapeforms-row-duplicate',
			function ( event ) {
				event.stopPropagation();
				duplicateRow( Number( $( this ).closest( '.shapeforms-row' ).data( 'row-index' ) ) );
			}
		);


		$( document ).on(
			'click',
			'.shapeforms-row-delete',
			function ( event ) {
				event.stopPropagation();
				deleteRow( Number( $( this ).closest( '.shapeforms-row' ).data( 'row-index' ) ) );
			}
		);


		// Screen settings gear — on a field card when the field is the screen, on
		// the row card when the row is. Both land in the same panel: screen
		// settings live in Field Settings, so a row-screen selects the first
		// question on it to get there. One destination, so the author never has
		// to learn which kind of screen they are looking at.
		$( document ).on(
			'click',
			'.shapeforms-screen-settings',
			function ( event ) {
				event.stopPropagation();

				var $field = $( this ).closest( '.shapeforms-canvas-field' );

				if ( ! $field.length ) {
					// The row card's gear: the screen is the row, and the panel is
					// reached through any question on it.
					var rowIndex = Number( $( this ).closest( '.shapeforms-row' ).data( 'row-index' ) );
					var row      = getAllRows()[ rowIndex ];
					var first    = null;

					if ( row ) {
						row.columns.some( function ( column ) {
							if ( column.fields.length ) {
								first = column.fields[ 0 ];
								return true;
							}
							return false;
						} );
					}

					if ( ! first ) {
						return;
					}

					state.selectedFieldId = first.id;
				} else {
					state.selectedFieldId = $field.data( 'field-id' );
				}

				state.selectedRowIndex = null;
				// The gear points AT the Screen section, so it opens it. Without
				// this the panel opens on Settings and the author has to find the
				// thing the button they just pressed was named after.
				state.activeSettingsAccordion = 'screen';
				switchEditorView( 'builder' );
				switchSidebarTab( 'settings' );
				renderAll();
			}
		);

		// Row settings button click.
		$( document ).on(
			'click',
			'.shapeforms-row-settings',
			function ( event ) {
				event.stopPropagation();
				var rowIndex = Number( $( this ).closest( '.shapeforms-row' ).data( 'row-index' ) );
				if ( state.selectedRowIndex === rowIndex ) {
					state.selectedRowIndex = null;
				} else {
					state.selectedRowIndex = rowIndex;
					state.selectedFieldId = null;
				}
				switchSidebarTab( 'settings' );
				renderAll();
			}
		);

		// Row column width change.
		$( document ).on( 'input', '.shapeforms-row-col-width', function () {
			if ( state.selectedRowIndex === null ) return;
			var row = getAllRows()[ state.selectedRowIndex ];
			if ( ! row ) return;
			var ci = Number( $( this ).data( 'col-index' ) );
			if ( row.columns[ ci ] ) {
				row.columns[ ci ].width = $.trim( $( this ).val() ) || '100%';
				renderCanvas();
			}
		} );

		// Row CSS class input.
		$( document ).on( 'input', '#shapeforms-setting-row-css-class', function () {
			if ( state.selectedRowIndex === null ) return;
			var row = getAllRows()[ state.selectedRowIndex ];
			if ( row ) { row.css_class = $( this ).val(); }
		} );

		// Deselect row when a field is clicked.
		$( document ).on( 'click', '.shapeforms-canvas-field', function () {
			if ( state.selectedRowIndex !== null ) {
				state.selectedRowIndex = null;
			}
		} );

		$( document ).on(
			'input',
			'#shapeforms-setting-label, #shapeforms-setting-placeholder, #shapeforms-setting-default, #shapeforms-setting-button-text, #shapeforms-setting-content, #shapeforms-setting-description, #shapeforms-setting-custom-error, #shapeforms-setting-allowed-types, #shapeforms-setting-max-file-size, #shapeforms-setting-button-icon-gap, #shapeforms-setting-css-class, #shapeforms-setting-auto-populate-key, #shapeforms-setting-min-value, #shapeforms-setting-max-value, #shapeforms-setting-step-value, #shapeforms-setting-mask-custom, #shapeforms-setting-max-stars, #shapeforms-setting-star-color, #shapeforms-setting-star-inactive-color, #shapeforms-setting-star-size, #shapeforms-setting-slider-color, #shapeforms-setting-slider-height, #shapeforms-setting-step-title, #shapeforms-setting-next-text, #shapeforms-setting-prev-text, #shapeforms-setting-btn-color, #shapeforms-setting-btn-text-color, #shapeforms-setting-btn-size, #shapeforms-setting-btn-radius, #shapeforms-setting-progress-color, #shapeforms-setting-progress-style, #shapeforms-setting-button-icon-size, #shapeforms-setting-button-icon-color, #shapeforms-step-progress-style-field, #shapeforms-page-break-progress-style, #shapeforms-page-break-progress-color, #shapeforms-page-break-progress-bg-color, #shapeforms-page-break-btn-color, #shapeforms-page-break-btn-text-color, #shapeforms-page-break-btn-size, #shapeforms-page-break-btn-radius, #shapeforms-page-break-next-text, #shapeforms-page-break-prev-text, #shapeforms-setting-product-style, #shapeforms-setting-qty-linked-product, #shapeforms-setting-qty-min, #shapeforms-setting-qty-max, #shapeforms-setting-qty-default, #shapeforms-setting-amount-min, #shapeforms-setting-amount-max, #shapeforms-setting-amount-default, #shapeforms-calc-formula, #shapeforms-calc-decimals, #shapeforms-calc-prefix, #shapeforms-calc-suffix, #shapeforms-setting-sig-pen-color, #shapeforms-setting-sig-pen-width, #shapeforms-setting-sig-bg-color, #shapeforms-setting-sig-height, #shapeforms-setting-hidden-value, #shapeforms-setting-rep-min, #shapeforms-setting-rep-max, #shapeforms-setting-rep-add-label, #shapeforms-setting-rep-remove-label, #shapeforms-setting-ic-img-height, #shapeforms-setting-matrix-rows, #shapeforms-setting-matrix-cols, #shapeforms-setting-lookup-items, #shapeforms-setting-lookup-placeholder, #shapeforms-setting-lookup-min-chars, #shapeforms-setting-lookup-max-results, #shapeforms-setting-geo-map-height, #shapeforms-setting-rte-height, #shapeforms-setting-pw-placeholder, #shapeforms-setting-dr-placeholder, #shapeforms-setting-dr-separator, #shapeforms-setting-dr-min-days, #shapeforms-setting-dr-max-days, #shapeforms-setting-nps-low, #shapeforms-setting-nps-high, #shapeforms-setting-nps-detractor-color, #shapeforms-setting-nps-passive-color, #shapeforms-setting-nps-promoter-color',
			function () {
				var selected = getSelectedFieldLocation();

				var isSubmitInput = state.selectedFieldId === submitButtonId || ( selected && selected.field && 'submit' === selected.field.type );

				if ( isSubmitInput ) {
					state.formSettings.button_text = $( '#shapeforms-setting-button-text' ).val();
					if ( $( '#shapeforms-setting-button-icon-gap' ).length ) {
						state.formSettings.button_icon_gap = $( '#shapeforms-setting-button-icon-gap' ).val() || '8';
					}
					if ( $( '#shapeforms-setting-button-icon-size' ).length ) {
						state.formSettings.button_icon_size = $( '#shapeforms-setting-button-icon-size' ).val() || '18';
					}
					if ( $( '#shapeforms-setting-button-icon-color' ).length ) {
						var $iconColorInput = $( '#shapeforms-setting-button-icon-color' );
						state.formSettings.button_icon_color = $iconColorInput.val() || '';
						// Live-update the swatch preview in place while dragging the native
						// picker. renderCanvas() repaints only the canvas (so the icon
						// updates instantly) but not the settings panel — updating the panel
						// wholesale would close the open picker — so nudge the swatch directly.
						$iconColorInput.siblings( '.shapeforms-color-preview' ).css( 'background', $iconColorInput.val() || '#ffffff' );
					}
					renderCanvas();
					return;
				}

				if ( ! selected ) {
					return;
				}

				selected.field.label = $( '#shapeforms-setting-label' ).length ? $( '#shapeforms-setting-label' ).val() : selected.field.label;
				selected.field.placeholder = $( '#shapeforms-setting-placeholder' ).length ? $( '#shapeforms-setting-placeholder' ).val() : ( selected.field.placeholder || '' );
				selected.field.default_value = $( '#shapeforms-setting-default' ).length ? $( '#shapeforms-setting-default' ).val() : ( selected.field.default_value || '' );
				selected.field.content = $( '#shapeforms-setting-content' ).length ? $( '#shapeforms-setting-content' ).val() : selected.field.content;
				selected.field.description = $( '#shapeforms-setting-description' ).length ? $( '#shapeforms-setting-description' ).val() : selected.field.description;
				selected.field.custom_error = $( '#shapeforms-setting-custom-error' ).length ? $( '#shapeforms-setting-custom-error' ).val() : ( selected.field.custom_error || '' );
				selected.field.allowed_types = $( '#shapeforms-setting-allowed-types' ).length ? $( '#shapeforms-setting-allowed-types' ).val() : ( selected.field.allowed_types || '' );
				selected.field.max_file_size = $( '#shapeforms-setting-max-file-size' ).length ? $( '#shapeforms-setting-max-file-size' ).val() : ( selected.field.max_file_size || '' );
				selected.field.css_class = $( '#shapeforms-setting-css-class' ).length ? $( '#shapeforms-setting-css-class' ).val() : ( selected.field.css_class || '' );
			selected.field.auto_populate_key = $( '#shapeforms-setting-auto-populate-key' ).length ? $( '#shapeforms-setting-auto-populate-key' ).val() : ( selected.field.auto_populate_key || '' );
				selected.field.min_value = $( '#shapeforms-setting-min-value' ).length ? $( '#shapeforms-setting-min-value' ).val() : selected.field.min_value;
				selected.field.max_value = $( '#shapeforms-setting-max-value' ).length ? $( '#shapeforms-setting-max-value' ).val() : selected.field.max_value;
				selected.field.step_value = $( '#shapeforms-setting-step-value' ).length ? $( '#shapeforms-setting-step-value' ).val() : selected.field.step_value;
				if ( $( '#shapeforms-setting-mask-custom' ).length ) {
					selected.field.mask_pattern = $( '#shapeforms-setting-mask-custom' ).val();
				}
				if ( $( '#shapeforms-setting-max-stars' ).length ) {
					selected.field.max_stars = Number( $( '#shapeforms-setting-max-stars' ).val() ) || 5;
				}
				if ( $( '#shapeforms-setting-star-color' ).length ) {
					selected.field.star_color = $( '#shapeforms-setting-star-color' ).val();
				}
				if ( $( '#shapeforms-setting-star-inactive-color' ).length ) {
					selected.field.star_inactive_color = $( '#shapeforms-setting-star-inactive-color' ).val();
				}
				if ( $( '#shapeforms-setting-star-size' ).length ) {
					selected.field.star_size = $( '#shapeforms-setting-star-size' ).val();
				}
				if ( $( '#shapeforms-setting-slider-color' ).length ) {
					selected.field.slider_color = $( '#shapeforms-setting-slider-color' ).val();
				}
				if ( $( '#shapeforms-setting-slider-height' ).length ) {
					selected.field.slider_height = $( '#shapeforms-setting-slider-height' ).val();
				}
				if ( $( '#shapeforms-setting-step-title' ).length ) {
					selected.field.step_title = $( '#shapeforms-setting-step-title' ).val();
				}
				// Progress style is a form-wide setting (there is one indicator for the
				// whole multi-step form), not a per-field one -- edited from the Page
				// Break panel since that's where users look for it, but stored on
				// formSettings like the submit button's settings are above.
				if ( $( '#shapeforms-page-break-progress-style' ).length ) {
					state.formSettings.step_progress_style = $( '#shapeforms-page-break-progress-style' ).val() || 'bar';
				}
				if ( $( '#shapeforms-page-break-progress-color' ).length ) {
					state.formSettings.step_progress_color = $( '#shapeforms-page-break-progress-color' ).val() || '';
				}
				if ( $( '#shapeforms-page-break-progress-bg-color' ).length ) {
					state.formSettings.step_progress_bg_color = $( '#shapeforms-page-break-progress-bg-color' ).val() || '';
				}
				if ( $( '#shapeforms-page-break-btn-color' ).length ) {
					state.formSettings.step_btn_color = $( '#shapeforms-page-break-btn-color' ).val() || '';
				}
				if ( $( '#shapeforms-page-break-btn-text-color' ).length ) {
					state.formSettings.step_btn_text_color = $( '#shapeforms-page-break-btn-text-color' ).val() || '';
				}
				if ( $( '#shapeforms-page-break-btn-size' ).length ) {
					state.formSettings.step_btn_size = $( '#shapeforms-page-break-btn-size' ).val() || 'medium';
				}
				if ( $( '#shapeforms-page-break-btn-radius' ).length ) {
					state.formSettings.step_btn_radius = '' === $( '#shapeforms-page-break-btn-radius' ).val() ? '' : Math.max( 0, Math.min( 50, parseInt( $( '#shapeforms-page-break-btn-radius' ).val(), 10 ) || 0 ) );
				}
				if ( $( '#shapeforms-page-break-next-text' ).length ) {
					state.formSettings.step_next_text = $( '#shapeforms-page-break-next-text' ).val() || '';
				}
				if ( $( '#shapeforms-page-break-prev-text' ).length ) {
					state.formSettings.step_prev_text = $( '#shapeforms-page-break-prev-text' ).val() || '';
				}
				if ( $( '#shapeforms-setting-product-style' ).length ) {
					selected.field.product_style = $( '#shapeforms-setting-product-style' ).val();
				}
				if ( $( '#shapeforms-setting-qty-linked-product' ).length ) {
					selected.field.linked_product = $( '#shapeforms-setting-qty-linked-product' ).val();
				}
				if ( $( '#shapeforms-setting-qty-min' ).length ) {
					selected.field.qty_min = $( '#shapeforms-setting-qty-min' ).val();
				}
				if ( $( '#shapeforms-setting-qty-max' ).length ) {
					selected.field.qty_max = $( '#shapeforms-setting-qty-max' ).val();
				}
				if ( $( '#shapeforms-setting-qty-default' ).length ) {
					selected.field.qty_default = $( '#shapeforms-setting-qty-default' ).val();
				}
				if ( $( '#shapeforms-setting-amount-limits' ).length ) {
					selected.field.amount_limits = $( '#shapeforms-setting-amount-limits' ).is( ':checked' );
				}
				// Min/Max are only in the DOM while the switch is on. The .length guard
				// means switching it off leaves the stored bounds untouched, so turning
				// it back on restores what was configured rather than a blank pair.
				if ( $( '#shapeforms-setting-amount-min' ).length ) {
					selected.field.amount_min = $( '#shapeforms-setting-amount-min' ).val();
				}
				if ( $( '#shapeforms-setting-amount-max' ).length ) {
					selected.field.amount_max = $( '#shapeforms-setting-amount-max' ).val();
				}
				if ( $( '#shapeforms-setting-amount-default' ).length ) {
					selected.field.amount_default = $( '#shapeforms-setting-amount-default' ).val();
				}
				var calcEditor = document.getElementById( 'shapeforms-calc-formula' );
				if ( calcEditor ) {
					var calcFormulaVal = bfCalcChipsToFormula( calcEditor );
					selected.field.calc_formula = calcFormulaVal;
					// Live validity styling without rebuilding the editor (keeps the caret).
					var calcInvalid = calcFormulaVal && /[^0-9\s\+\-\*\/\(\)\.\{\}a-z0-9_]/i.test( calcFormulaVal );
					$( calcEditor )
						.toggleClass( 'bfcp-valid', !! calcFormulaVal && ! calcInvalid )
						.toggleClass( 'bfcp-invalid', !! calcFormulaVal && !! calcInvalid );
				}
				if ( $( '#shapeforms-calc-decimals' ).length ) {
					selected.field.calc_decimals = Math.max( 0, Math.min( 10, parseInt( $( '#shapeforms-calc-decimals' ).val(), 10 ) || 0 ) );
				}
				if ( $( '#shapeforms-calc-prefix' ).length ) {
					selected.field.calc_prefix = $( '#shapeforms-calc-prefix' ).val();
				}
				if ( $( '#shapeforms-calc-suffix' ).length ) {
					selected.field.calc_suffix = $( '#shapeforms-calc-suffix' ).val();
				}

				// Signature field.
				if ( $( '#shapeforms-setting-sig-pen-color' ).length ) {
					selected.field.sig_pen_color = $( '#shapeforms-setting-sig-pen-color' ).val();
				}
				if ( $( '#shapeforms-setting-sig-pen-width' ).length ) {
					selected.field.sig_pen_width = Math.max( 1, Math.min( 8, parseInt( $( '#shapeforms-setting-sig-pen-width' ).val(), 10 ) || 2 ) );
				}
				if ( $( '#shapeforms-setting-sig-bg-color' ).length ) {
					selected.field.sig_bg_color = $( '#shapeforms-setting-sig-bg-color' ).val();
				}
				if ( $( '#shapeforms-setting-sig-height' ).length ) {
					selected.field.sig_height = Math.max( 80, Math.min( 400, parseInt( $( '#shapeforms-setting-sig-height' ).val(), 10 ) || 160 ) );
				}

				// Hidden field.
				if ( $( '#shapeforms-setting-hidden-value' ).length ) {
					selected.field.hidden_value = $( '#shapeforms-setting-hidden-value' ).val();
				}

				// Repeater field.
				if ( $( '#shapeforms-setting-rep-min' ).length ) {
					selected.field.repeater_min_rows = Math.max( 1, Math.min( 10, parseInt( $( '#shapeforms-setting-rep-min' ).val(), 10 ) || 1 ) );
				}
				if ( $( '#shapeforms-setting-rep-max' ).length ) {
					selected.field.repeater_max_rows = Math.max( 1, Math.min( 20, parseInt( $( '#shapeforms-setting-rep-max' ).val(), 10 ) || 5 ) );
				}
				if ( $( '#shapeforms-setting-rep-add-label' ).length ) {
					selected.field.repeater_add_label = $( '#shapeforms-setting-rep-add-label' ).val();
				}
				if ( $( '#shapeforms-setting-rep-remove-label' ).length ) {
					selected.field.repeater_remove_label = $( '#shapeforms-setting-rep-remove-label' ).val();
				}

				// Image choice image height.
				if ( $( '#shapeforms-setting-ic-img-height' ).length ) {
					selected.field.image_choice_img_height = Math.max( 60, Math.min( 600, parseInt( $( '#shapeforms-setting-ic-img-height' ).val(), 10 ) || 160 ) );
				}

				// Password field.
				if ( $( '#shapeforms-setting-pw-placeholder' ).length ) {
					selected.field.placeholder = $( '#shapeforms-setting-pw-placeholder' ).val();
				}

				// Rich Text.
				if ( $( '#shapeforms-setting-rte-height' ).length ) {
					selected.field.rte_height = Math.max( 100, Math.min( 800, parseInt( $( '#shapeforms-setting-rte-height' ).val(), 10 ) || 200 ) );
				}

				// Date Range.
				if ( $( '#shapeforms-setting-dr-placeholder' ).length ) {
					selected.field.placeholder = $( '#shapeforms-setting-dr-placeholder' ).val();
				}
				if ( $( '#shapeforms-setting-dr-format' ).length ) {
					selected.field.date_range_format = $( '#shapeforms-setting-dr-format' ).val();
				}
				if ( $( '#shapeforms-setting-dr-separator' ).length ) {
					selected.field.date_range_separator = $( '#shapeforms-setting-dr-separator' ).val();
				}
				if ( $( '#shapeforms-setting-dr-min-days' ).length ) {
					selected.field.date_range_min_days = $( '#shapeforms-setting-dr-min-days' ).val();
				}
				if ( $( '#shapeforms-setting-dr-max-days' ).length ) {
					selected.field.date_range_max_days = $( '#shapeforms-setting-dr-max-days' ).val();
				}

				// NPS.
				if ( $( '#shapeforms-setting-nps-low' ).length ) {
					selected.field.nps_low_label = $( '#shapeforms-setting-nps-low' ).val();
				}
				if ( $( '#shapeforms-setting-nps-high' ).length ) {
					selected.field.nps_high_label = $( '#shapeforms-setting-nps-high' ).val();
				}
				if ( $( '#shapeforms-setting-nps-detractor-color' ).length ) {
					selected.field.nps_detractor_color = $( '#shapeforms-setting-nps-detractor-color' ).val();
				}
				if ( $( '#shapeforms-setting-nps-passive-color' ).length ) {
					selected.field.nps_passive_color = $( '#shapeforms-setting-nps-passive-color' ).val();
				}
				if ( $( '#shapeforms-setting-nps-promoter-color' ).length ) {
					selected.field.nps_promoter_color = $( '#shapeforms-setting-nps-promoter-color' ).val();
				}

				// Matrix — convert textareas to JSON arrays.
				if ( $( '#shapeforms-setting-matrix-rows' ).length ) {
					var mRowsText = $( '#shapeforms-setting-matrix-rows' ).val().trim();
					selected.field.matrix_rows = JSON.stringify(
						mRowsText ? mRowsText.split( /\r?\n/ ).map( function(s) { return s.trim(); } ).filter( Boolean ) : []
					);
				}
				if ( $( '#shapeforms-setting-matrix-cols' ).length ) {
					var mColsText = $( '#shapeforms-setting-matrix-cols' ).val().trim();
					selected.field.matrix_columns = JSON.stringify(
						mColsText ? mColsText.split( /\r?\n/ ).map( function(s) { return s.trim(); } ).filter( Boolean ) : []
					);
				}

				// Lookup — convert textarea to JSON array.
				if ( $( '#shapeforms-setting-lookup-placeholder' ).length ) {
					selected.field.placeholder = $( '#shapeforms-setting-lookup-placeholder' ).val();
				}
				if ( $( '#shapeforms-setting-lookup-items' ).length ) {
					var lItemsText = $( '#shapeforms-setting-lookup-items' ).val().trim();
					selected.field.lookup_items = JSON.stringify(
						lItemsText ? lItemsText.split( /\r?\n/ ).map( function(s) { return s.trim(); } ).filter( Boolean ) : []
					);
				}
				if ( $( '#shapeforms-setting-lookup-min-chars' ).length ) {
					selected.field.lookup_min_chars = Math.max( 1, Math.min( 5, parseInt( $( '#shapeforms-setting-lookup-min-chars' ).val(), 10 ) || 2 ) );
				}
				if ( $( '#shapeforms-setting-lookup-max-results' ).length ) {
					selected.field.lookup_max_results = Math.max( 3, Math.min( 20, parseInt( $( '#shapeforms-setting-lookup-max-results' ).val(), 10 ) || 8 ) );
				}

				// Geolocation.
				if ( $( '#shapeforms-setting-geo-map-height' ).length ) {
					selected.field.geo_map_height = Math.max( 150, Math.min( 600, parseInt( $( '#shapeforms-setting-geo-map-height' ).val(), 10 ) || 250 ) );
				}
				if ( $( '#shapeforms-setting-geo-store-format' ).length ) {
					selected.field.geo_store_format = $( '#shapeforms-setting-geo-store-format' ).val();
				}

				if ( optionFieldTypes.indexOf( selected.field.type ) !== -1 ) {
					applyCollectedOptions( selected.field );
				}

				renderCanvas();
			}
		);

		$( document ).on(
			'change',
			'#shapeforms-setting-required, #shapeforms-setting-button-icon-type, #shapeforms-setting-button-icon-dashicon, #shapeforms-setting-button-icon-position, #shapeforms-setting-button-icon-color, #shapeforms-setting-button-color-global, #shapeforms-setting-options-layout, #shapeforms-setting-checkbox-style, #shapeforms-setting-select-searchable, #shapeforms-setting-mask-pattern, #shapeforms-setting-show-middle-name, #shapeforms-setting-show-last-name, #shapeforms-setting-hidden-source, #shapeforms-setting-ic-type, #shapeforms-setting-ic-columns, #shapeforms-setting-rep-columns, #shapeforms-setting-pw-confirm, #shapeforms-setting-lookup-allow-custom, #shapeforms-setting-geo-show-map, #shapeforms-setting-matrix-type, #shapeforms-setting-dr-format, #shapeforms-setting-geo-store-format, #shapeforms-setting-dual-handle',
			function () {
				var selected = getSelectedFieldLocation();
				var isSubmitSel = state.selectedFieldId === submitButtonId || ( selected && selected.field && 'submit' === selected.field.type );

				if ( isSubmitSel ) {
					state.formSettings.button_icon_type = $( '#shapeforms-setting-button-icon-type' ).val() || 'none';
					if ( $( '#shapeforms-setting-button-icon-dashicon' ).length ) {
						state.formSettings.button_icon_dashicon = $( '#shapeforms-setting-button-icon-dashicon' ).val() || 'dashicons-arrow-right-alt';
					}
					state.formSettings.button_icon_position = $( '#shapeforms-setting-button-icon-position' ).val() || 'right';
					// Native <input type="color"> fires 'change' reliably (on picker close)
					// but 'input' inconsistently across browsers, so capture the icon colour
					// here too — the 'input' handler covers live dragging where supported.
					if ( $( '#shapeforms-setting-button-icon-color' ).length ) {
						state.formSettings.button_icon_color = $( '#shapeforms-setting-button-icon-color' ).val() || '';
					}
					renderAll();
					return;
				}

				if ( $( this ).is( '#shapeforms-setting-button-color-global' ) ) {
					state.formSettings.button_color = $( '#shapeforms-setting-button-color-global' ).val() || 'teal';
					renderCanvas();
					return;
				}

				if ( ! selected ) {
					return;
				}

				selected.field.required = $( '#shapeforms-setting-required' ).is( ':checked' );

				if ( ! selected.field.required ) {
					selected.field.custom_error = '';
				}

				if ( $( '#shapeforms-setting-options-layout' ).length ) {
					selected.field.options_layout = $( '#shapeforms-setting-options-layout' ).val() || 'block';
				}

				if ( $( '#shapeforms-setting-checkbox-style' ).length ) {
					selected.field.checkbox_style = $( '#shapeforms-setting-checkbox-style' ).val() || 'default';
				}

				if ( $( '#shapeforms-setting-select-searchable' ).length ) {
					selected.field.select_searchable = $( '#shapeforms-setting-select-searchable' ).is( ':checked' );
				}
				if ( $( '#shapeforms-setting-show-middle-name' ).length ) {
					selected.field.show_middle_name = $( '#shapeforms-setting-show-middle-name' ).is( ':checked' );
				}
				if ( $( '#shapeforms-setting-show-last-name' ).length ) {
					selected.field.show_last_name = $( '#shapeforms-setting-show-last-name' ).is( ':checked' );
				}

				if ( $( '#shapeforms-setting-mask-pattern' ).length ) {
					var maskVal = $( '#shapeforms-setting-mask-pattern' ).val();
					selected.field.mask_pattern = maskVal === 'custom' ? ( selected.field.mask_pattern || '' ) : maskVal;
				}
				// Hidden field source.
				if ( $( '#shapeforms-setting-hidden-source' ).length ) {
					selected.field.hidden_source = $( '#shapeforms-setting-hidden-source' ).val();
					// Re-render panel so the value/param input shows or hides.
					renderSettingsPanel();
					setupOptionsSortable();
					setupAddressSortable();
					return;
				}

				// Image choice settings.
				if ( $( '#shapeforms-setting-ic-type' ).length ) {
					selected.field.image_choice_type = $( '#shapeforms-setting-ic-type' ).val();
				}
				if ( $( '#shapeforms-setting-ic-columns' ).length ) {
					selected.field.image_choice_columns = Number( $( '#shapeforms-setting-ic-columns' ).val() ) || 3;
				}

				// Repeater columns.
				if ( $( '#shapeforms-setting-rep-columns' ).length ) {
					selected.field.repeater_columns = Math.max( 1, Math.min( 4, Number( $( '#shapeforms-setting-rep-columns' ).val() ) || 2 ) );
				}

				// Password confirm toggle.
				if ( $( '#shapeforms-setting-pw-confirm' ).length ) {
					selected.field.confirm_password = $( '#shapeforms-setting-pw-confirm' ).is( ':checked' );
				}

				// Lookup allow custom.
				if ( $( '#shapeforms-setting-lookup-allow-custom' ).length ) {
					selected.field.lookup_allow_custom = $( '#shapeforms-setting-lookup-allow-custom' ).is( ':checked' );
				}

				// Geolocation show map.
				if ( $( '#shapeforms-setting-geo-show-map' ).length ) {
					selected.field.geo_show_map = $( '#shapeforms-setting-geo-show-map' ).is( ':checked' );
				}

				// Matrix type.
				if ( $( '#shapeforms-setting-matrix-type' ).length ) {
					selected.field.matrix_type = $( '#shapeforms-setting-matrix-type' ).val();
				}

				// Date range format.
				if ( $( '#shapeforms-setting-dr-format' ).length ) {
					selected.field.date_range_format = $( '#shapeforms-setting-dr-format' ).val();
				}

				// Geo store format.
				if ( $( '#shapeforms-setting-geo-store-format' ).length ) {
					selected.field.geo_store_format = $( '#shapeforms-setting-geo-store-format' ).val();
				}

				// Slider dual-handle toggle.
				if ( $( '#shapeforms-setting-dual-handle' ).length ) {
					selected.field.dual_handle = $( '#shapeforms-setting-dual-handle' ).is( ':checked' );
				}

				renderAll();
			}
		);

		// ── Screen styling: background media and colours ─────────────────────
		// Mirrors the SVG-upload flow below, with one deliberate difference: it
		// stores attachment.id, not attachment.url. A stored URL breaks when the
		// site moves and cannot produce responsive sources.
		function currentScreenTarget() {
			// Styling belongs to a SCREEN, and a screen is sometimes a row and
			// sometimes a field. The author only ever selects a question, so this
			// resolves from there — and it MUST resolve exactly the way
			// screenSettings() did. If the two ever disagreed the panel would be
			// reading one object while every control wrote to another, and a
			// chosen image or colour would simply never appear.
			if ( ! cvIsOn() ) {
				return null;
			}

			var selected = getSelectedFieldLocation();

			if ( ! selected || ! selected.field ) {
				return null;
			}

			// getAllRows() is what the panel read from, so the picker writes back
			// to the same objects.
			return cvScreenMap().byRow[ selected.rowIndex ]
				? ( getAllRows()[ selected.rowIndex ] || null )
				: selected.field;
		}

		/**
		 * Opens the media library for the screen currently being edited.
		 *
		 * Shared by the drop zone, Replace, and a placement tile clicked before
		 * there is any image — "where" and "which" are one decision, so choosing
		 * a placement first has to be able to finish the job.
		 */
		function openScreenMediaPicker() {
			if ( typeof wp === 'undefined' || ! wp.media ) { return; }

			var row = currentScreenTarget();
			if ( ! row ) { return; }

			var frame = wp.media( {
				title: 'Choose screen image',
				button: { text: 'Use this image' },
				multiple: false,
				library: { type: 'image' }
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				row.cv_media_id = attachment.id;
				// Preview URL only — never persisted; the renderer resolves the
				// attachment fresh on every render.
				row.cv_media_preview = attachment.sizes && attachment.sizes.medium
					? attachment.sizes.medium.url
					: attachment.url;
				// Only when the author expressed no preference. A placement
				// chosen on the way in must survive the pick.
				if ( ! row.cv_media_layout || 'none' === row.cv_media_layout ) {
					row.cv_media_layout = 'background';
				}
				if ( ! row.cv_media_alt && attachment.alt ) {
					row.cv_media_alt = attachment.alt;
				}
				markDirty();
				renderAll();
			} );

			frame.open();
		}

		$( document ).on( 'click', '#shapeforms-row-media-pick', function ( e ) {
			e.preventDefault();
			openScreenMediaPicker();
		} );

		$( document ).on( 'click', '#shapeforms-row-media-remove', function ( e ) {
			e.preventDefault();
			var row = currentScreenTarget();
			if ( ! row ) { return; }
			row.cv_media_id = 0;
			row.cv_media_preview = '';
			row.cv_media_layout = 'none';
			markDirty();
			renderAll();
		} );

		// A placement tile. With an image it switches where that image sits;
		// without one it records the intent and goes straight on to choosing the
		// image, so the empty state is a real entry point rather than a preview
		// of controls the author cannot reach yet.
		$( document ).on( 'click', '.shapeforms-media-place__btn', function ( e ) {
			e.preventDefault();
			var row = currentScreenTarget();
			if ( ! row ) { return; }

			row.cv_media_layout = $( this ).data( 'layout' ) || 'background';
			markDirty();

			if ( ! row.cv_media_id ) {
				openScreenMediaPicker();
				return;
			}

			// Switching placement changes which framing controls apply, so the
			// panel is rebuilt — unlike a range, which must not be.
			renderAll();
		} );

		$( document ).on( 'change input', '#shapeforms-row-media-x, #shapeforms-row-media-y, #shapeforms-row-media-brightness, #shapeforms-row-media-height, #shapeforms-row-media-alt', function () {
			var row = currentScreenTarget();
			if ( ! row ) { return; }
			if ( $( '#shapeforms-row-media-x' ).length )          { row.cv_media_x = Number( $( '#shapeforms-row-media-x' ).val() ); }
			if ( $( '#shapeforms-row-media-y' ).length )          { row.cv_media_y = Number( $( '#shapeforms-row-media-y' ).val() ); }
			if ( $( '#shapeforms-row-media-brightness' ).length ) { row.cv_media_brightness = Number( $( '#shapeforms-row-media-brightness' ).val() ); }
			// An empty box means "use the default", stored as 0 and never as a
			// resolved height — the same contract '' carries for the colours.
			if ( $( '#shapeforms-row-media-height' ).length ) {
				var rawHeight = $.trim( $( '#shapeforms-row-media-height' ).val() );
				row.cv_media_height = rawHeight ? Math.max( 80, Math.min( 2000, Number( rawHeight ) || 0 ) ) : 0;
			}
			if ( $( '#shapeforms-row-media-alt' ).length )        { row.cv_media_alt = $( '#shapeforms-row-media-alt' ).val() || ''; }
			markDirty();
		} );

		// Live readout beside each range, and the track's own fill. Without the
		// readout a slider position is a number the author cannot see; without
		// the fill the track disagrees with the number it is sitting under.
		$( document ).on( 'input', '.shapeforms-row-media__range input[type="range"]', function () {
			var $el = $( this );
			$( '#' + $el.attr( 'id' ) + '-out' ).text( $el.val() + ( $el.attr( 'data-suffix' ) || '' ) );
			$el.css( '--bf-range-p', rangePercent( $el.val(), $el.attr( 'min' ), $el.attr( 'max' ) ) );
		} );

		// ── Per-screen colours ───────────────────────────────────────────────
		// Same target resolution as the media picker above, so there is exactly
		// one answer in this file to "what is this screen?".
		//
		// Scoped to .shapeforms-screen-colour throughout. The Style tab's handlers
		// key off .shapeforms-adv-field and write through the --bf-* variable
		// system; these are flat cv_* keys on a row or field and must not be
		// caught by them.

		/** Repaints one control in place, without rebuilding the panel. */
		function syncScreenColour( $field, value, inherited ) {
			$field.css( '--bf-sw', value || inherited );
			$field.closest( '.shapeforms-screen-colour' ).toggleClass( 'is-custom', !! value );
			$field.find( '.bf-adv-color-reset' ).prop( 'disabled', ! value );
		}

		/** What this colour falls back to: the form's value, or the swatch's own. */
		function screenColourInherited( $field ) {
			return $field.find( '.bf-adv-colorpick' ).val() || '#000000';
		}

		$( document ).on( 'input', '.shapeforms-screen-colour .bf-adv-colorpick', function () {
			var target = currentScreenTarget();
			if ( ! target ) { return; }

			var value = $( this ).val() || '';
			target[ $( this ).data( 'cv-key' ) ] = value;
			// Picking a colour IS the override, so the hex box stops showing its
			// "Default" placeholder and starts showing the value.
			$( this ).closest( '.shapeforms-color-field' ).find( '.bf-screen-hex' ).val( value );
			syncScreenColour( $( this ).closest( '.shapeforms-color-field' ), value, value );
			markDirty();
		} );

		// The panel is rebuilt only once the picker is committed. A colour input
		// fires `input` continuously while the wheel is dragged, and re-rendering
		// under it would tear the picker away on the first movement — the same
		// reason the media ranges never re-render.
		$( document ).on( 'change', '.shapeforms-screen-colour .bf-adv-colorpick', function () {
			renderAll();
		} );

		// Typing a hex. Emptying the box is a reset — the same gesture the Style
		// tab's colour fields answer to, and the reason the box is left empty
		// rather than pre-filled when a screen is inheriting.
		$( document ).on( 'input', '.shapeforms-screen-colour .bf-screen-hex', function () {
			var target = currentScreenTarget();
			if ( ! target ) { return; }

			var $field = $( this ).closest( '.shapeforms-color-field' );
			var typed  = $.trim( $( this ).val() );

			if ( '' === typed ) {
				target[ $( this ).data( 'cv-key' ) ] = '';
				syncScreenColour( $field, '', screenColourInherited( $field ) );
				markDirty();
				return;
			}

			if ( ! /^#[0-9a-fA-F]{6}$/.test( typed ) ) {
				// Mid-typing, not a decision. Leave the stored value alone.
				return;
			}

			target[ $( this ).data( 'cv-key' ) ] = typed;
			$field.find( '.bf-adv-colorpick' ).val( typed );
			syncScreenColour( $field, typed, typed );
			markDirty();
		} );

		$( document ).on( 'click', '.shapeforms-screen-colour .bf-adv-color-reset', function ( event ) {
			event.preventDefault();
			var target = currentScreenTarget();
			if ( ! target ) { return; }
			// '' is INHERIT, and it has to be stored as '' rather than as a copy
			// of the form's colour: a pinned copy stops following the form the
			// day the author changes it, which is the whole failure this design
			// exists to avoid.
			target[ $( this ).data( 'cv-key' ) ] = '';
			markDirty();
			renderAll();
		} );

		// SVG icon upload via WP media library.
		$( document ).on( 'click', '#shapeforms-svg-upload-btn', function ( e ) {
			e.preventDefault();
			var frame = wp.media( {
				title: shapeformsLiteBuilder.labels.uploadSvg || 'Upload SVG',
				button: { text: shapeformsLiteBuilder.labels.useSvg || 'Use this SVG' },
				multiple: false,
				library: { type: 'image/svg+xml' }
			} );
			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				state.formSettings.button_icon_svg = attachment.url || '';
				renderAll();
			} );
			frame.open();
		} );

		// SVG icon remove.
		$( document ).on( 'click', '.shapeforms-svg-remove', function ( e ) {
			e.preventDefault();
			state.formSettings.button_icon_svg = '';
			renderAll();
		} );

		// Image Choice — open WP media library to pick image for an option.
		$( document ).on( 'click', '.shapeforms-ic-option__img-btn', function ( e ) {
			e.preventDefault();
			if ( typeof wp === 'undefined' || ! wp.media ) return;

			var $btn = $( this );
			var idx  = Number( $btn.data( 'ic-index' ) );

			var frame = wp.media( {
				title: 'Choose Image',
				button: { text: 'Use this image' },
				multiple: false,
				library: { type: 'image' }
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				var url = attachment.url || '';
				var selected = getSelectedFieldLocation();
				if ( ! selected || ! Array.isArray( selected.field.image_choice_options ) ) return;
				if ( ! selected.field.image_choice_options[ idx ] ) return;

				selected.field.image_choice_options[ idx ].image_url = url;

				// Update the thumbnail in the button immediately.
				$btn.html( '<img src="' + escapeHtml( url ) + '" alt="">' );

				renderCanvas();
			} );

			frame.open();
		} );

		// Calculation — insert a field-reference chip into the formula editor.
		$( document ).on( 'change', '.bfcp-field-insert', function () {
			var val = $( this ).val();
			if ( !val ) return;
			var editor = document.getElementById( 'shapeforms-calc-formula' );
			if ( !editor ) return;
			editor.focus();
			bfCalcInsertChip( editor, val );
			$( editor ).trigger( 'input' ); // Sync model + canvas + validity.
			$( this ).val( '' ); // Reset dropdown.
		} );

		$( document ).on(
			'input change',
			'input[name="shapeforms-submit-mode"], #shapeforms-redirect-url, #shapeforms-redirect-custom-url, #shapeforms-thank-you-message, #shapeforms-enable-admin-email, #shapeforms-enable-user-email, input[name="shapeforms-admin-email-type"], #shapeforms-admin-email, #shapeforms-field-size-style, #shapeforms-field-border-style, #shapeforms-field-border-width, #shapeforms-field-border-radius, #shapeforms-field-background-color, #shapeforms-field-border-color, #shapeforms-field-text-color, #shapeforms-label-size-style, #shapeforms-label-color-style, #shapeforms-label-subtext-color-style, #shapeforms-error-color-style, #shapeforms-button-size-style, #shapeforms-button-border-style, #shapeforms-button-border-width, #shapeforms-button-border-radius, #shapeforms-button-background-color, #shapeforms-button-border-color, #shapeforms-button-text-color, #shapeforms-field-focus-color, #shapeforms-step-progress-style, #shapeforms-step-progress-color, #shapeforms-step-btn-color, #shapeforms-step-btn-text-color, #shapeforms-step-btn-size, #shapeforms-step-btn-radius, #shapeforms-step-next-text, #shapeforms-step-prev-text, #shapeforms-hide-labels, #shapeforms-hide-placeholders, #shapeforms-dup-enabled, input[name="shapeforms-dup-method"], #shapeforms-dup-field-id, #shapeforms-dup-message, #shapeforms-custom-css, #shapeforms-custom-js, .shapeforms-color-hex, [data-pane="conversational"] input, [data-pane="conversational"] select, [data-pane="conversational"] textarea, [data-pane="conversational-style"] input',
			function ( event ) {
				var needsRerender = false;

				if ( $( 'input[name="shapeforms-submit-mode"]' ).length ) {
					var mode = $( 'input[name="shapeforms-submit-mode"]:checked' ).val() || 'ajax';
					if ( 'ajax' === mode ) {
						state.formSettings.submission_type = 'ajax';
						state.formSettings.redirect_type = 'page';
					} else if ( 'page' === mode ) {
						state.formSettings.submission_type = 'redirect';
						state.formSettings.redirect_type = 'page';
					} else if ( 'custom_url' === mode ) {
						state.formSettings.submission_type = 'redirect';
						state.formSettings.redirect_type = 'custom';
					}
					state.formSettings.enable_ajax = 'ajax' === state.formSettings.submission_type;
					state.formSettings.enable_redirect = 'redirect' === state.formSettings.submission_type;
				}
				if ( $( '#shapeforms-redirect-url' ).length ) {
					state.formSettings.redirect_url = $( '#shapeforms-redirect-url' ).val() || '';
				} else if ( $( '#shapeforms-redirect-custom-url' ).length ) {
					state.formSettings.redirect_url = $( '#shapeforms-redirect-custom-url' ).val() || '';
				}
				// Read through the editor, not the textarea: while Visual is active the
				// textarea holds whatever it was seeded with, so a plain .val() here
				// would overwrite the live message every time an unrelated setting on
				// this panel changed.
				syncThankYouMessage();
				if ( $( '#shapeforms-enable-admin-email' ).length ) {
					state.formSettings.enable_admin_email = $( '#shapeforms-enable-admin-email' ).is( ':checked' );
				}
				if ( $( '#shapeforms-enable-user-email' ).length ) {
					state.formSettings.enable_user_email = $( '#shapeforms-enable-user-email' ).is( ':checked' );
				}
				if ( $( 'input[name="shapeforms-admin-email-type"]' ).length ) {
					state.formSettings.admin_email_type = $( 'input[name="shapeforms-admin-email-type"]:checked' ).val() || 'site_admin';
				}
				if ( $( '#shapeforms-admin-email' ).length ) {
					state.formSettings.admin_email = $( '#shapeforms-admin-email' ).val() || '';
				}
				if ( $( '#shapeforms-field-size-style' ).length ) {
					state.formSettings.field_size = $( '#shapeforms-field-size-style' ).val() || '';
				}
				if ( $( '#shapeforms-field-border-style' ).length ) {
					state.formSettings.field_style = $( '#shapeforms-field-border-style' ).val() || '';
				}
				if ( $( '#shapeforms-field-focus-color' ).length ) {
					state.formSettings.field_focus_color = $( '#shapeforms-field-focus-color' ).val() || '';
				}
				if ( $( '#shapeforms-field-border-width' ).length ) {
					state.formSettings.field_border_width = '' === $( '#shapeforms-field-border-width' ).val() ? '' : Number( $( '#shapeforms-field-border-width' ).val() );
				}
				if ( $( '#shapeforms-field-border-radius' ).length ) {
					state.formSettings.field_border_radius = '' === $( '#shapeforms-field-border-radius' ).val() ? '' : Number( $( '#shapeforms-field-border-radius' ).val() );
				}
				if ( $( '#shapeforms-field-background-color' ).length ) {
					state.formSettings.field_background_color = normalizeStyleColorValue( $( '#shapeforms-field-background-color' ).val() || '#ffffff', '#ffffff' );
				}
				if ( $( '#shapeforms-field-border-color' ).length ) {
					state.formSettings.field_border_color = normalizeStyleColorValue( $( '#shapeforms-field-border-color' ).val() || '#d1d5db', '#d1d5db' );
				}
				if ( $( '#shapeforms-field-text-color' ).length ) {
					state.formSettings.field_text_color = normalizeStyleColorValue( $( '#shapeforms-field-text-color' ).val() || '#111827', '#111827' );
				}
				if ( $( '#shapeforms-label-size-style' ).length ) {
					state.formSettings.label_size = $( '#shapeforms-label-size-style' ).val() || '';
				}
				if ( $( '#shapeforms-label-color-style' ).length ) {
					state.formSettings.label_color = normalizeStyleColorValue( $( '#shapeforms-label-color-style' ).val() || '#4b5563', '#4b5563' );
				}
				if ( $( '#shapeforms-label-subtext-color-style' ).length ) {
					state.formSettings.label_subtext_color = normalizeStyleColorValue( $( '#shapeforms-label-subtext-color-style' ).val() || '#6b7280', '#6b7280' );
				}
				if ( $( '#shapeforms-error-color-style' ).length ) {
					state.formSettings.error_color = normalizeStyleColorValue( $( '#shapeforms-error-color-style' ).val() || '#dc2626', '#dc2626' );
				}
				if ( $( '#shapeforms-button-size-style' ).length ) {
					state.formSettings.button_size = $( '#shapeforms-button-size-style' ).val() || '';
				}
				if ( $( '#shapeforms-button-border-style' ).length ) {
					state.formSettings.button_border_style = $( '#shapeforms-button-border-style' ).val() || '';
				}
				if ( $( '#shapeforms-button-border-width' ).length ) {
					state.formSettings.button_border_width = '' === $( '#shapeforms-button-border-width' ).val() ? '' : Number( $( '#shapeforms-button-border-width' ).val() );
				}
				if ( $( '#shapeforms-button-border-radius' ).length ) {
					state.formSettings.button_border_radius = '' === $( '#shapeforms-button-border-radius' ).val() ? '' : Number( $( '#shapeforms-button-border-radius' ).val() );
				}
				if ( $( '#shapeforms-button-background-color' ).length ) {
					state.formSettings.button_background_color = normalizeStyleColorValue( $( '#shapeforms-button-background-color' ).val() || '#2f80ed', '#2f80ed' );
				}
				if ( $( '#shapeforms-button-border-color' ).length ) {
					state.formSettings.button_border_color = normalizeStyleColorValue( $( '#shapeforms-button-border-color' ).val() || '#2f80ed', '#2f80ed' );
				}
				if ( $( '#shapeforms-button-text-color' ).length ) {
					state.formSettings.button_text_color = normalizeStyleColorValue( $( '#shapeforms-button-text-color' ).val() || '#ffffff', '#ffffff' );
				}

				// Duplicate prevention settings.
				if ( $( '#shapeforms-dup-enabled' ).length ) {
					state.formSettings.dup_enabled = $( '#shapeforms-dup-enabled' ).is( ':checked' );
				}
				if ( $( 'input[name="shapeforms-dup-method"]' ).length ) {
					state.formSettings.dup_method = $( 'input[name="shapeforms-dup-method"]:checked' ).val() || 'email';
				}
				if ( $( '#shapeforms-dup-field-id' ).length ) {
					state.formSettings.dup_field_id = $( '#shapeforms-dup-field-id' ).val() || '';
				}
				if ( $( '#shapeforms-dup-message' ).length ) {
					state.formSettings.dup_message = $( '#shapeforms-dup-message' ).val() || '';
				}

				// Conversational settings. Every read is .length-guarded because the
				// controls only exist while the pane is rendered and expanded — an
				// unguarded read would wipe a saved value the moment the author
				// collapsed the section.
				if ( $( '#shapeforms-cv-enabled' ).length ) {
					state.formSettings.cv_enabled = $( '#shapeforms-cv-enabled' ).is( ':checked' );
				}
				if ( $( '#shapeforms-cv-progress' ).length ) {
					state.formSettings.cv_progress = $( '#shapeforms-cv-progress' ).val() || 'bar';
				}
				if ( $( '#shapeforms-cv-transition' ).length ) {
					state.formSettings.cv_transition = $( '#shapeforms-cv-transition' ).val() || 'slide';
				}
				if ( $( '#shapeforms-cv-key-hint' ).length ) {
					state.formSettings.cv_key_hint = $( '#shapeforms-cv-key-hint' ).is( ':checked' );
				}
				if ( $( '#shapeforms-cv-welcome-enabled' ).length ) {
					state.formSettings.cv_welcome_enabled = $( '#shapeforms-cv-welcome-enabled' ).is( ':checked' );
				}
				if ( $( '#shapeforms-cv-welcome-title' ).length ) {
					state.formSettings.cv_welcome_title = $( '#shapeforms-cv-welcome-title' ).val() || '';
				}
				if ( $( '#shapeforms-cv-welcome-text' ).length ) {
					state.formSettings.cv_welcome_text = $( '#shapeforms-cv-welcome-text' ).val() || '';
				}
				if ( $( '#shapeforms-cv-welcome-btn' ).length ) {
					state.formSettings.cv_welcome_btn = $( '#shapeforms-cv-welcome-btn' ).val() || '';
				}
				if ( $( '#shapeforms-cv-media-hide-mobile' ).length ) {
					state.formSettings.cv_media_hide_mobile = $( '#shapeforms-cv-media-hide-mobile' ).is( ':checked' );
				}
				if ( $( '#shapeforms-cv-next-text' ).length ) {
					state.formSettings.cv_next_text = $( '#shapeforms-cv-next-text' ).val() || '';
				}
				if ( $( '#shapeforms-cv-prev-text' ).length ) {
					state.formSettings.cv_prev_text = $( '#shapeforms-cv-prev-text' ).val() || '';
				}
				if ( $( '#shapeforms-cv-nav-align' ).length ) {
					state.formSettings.cv_nav_align = $( '#shapeforms-cv-nav-align' ).val() || 'left';
				}
				if ( $( '#shapeforms-cv-progress-align' ).length ) {
					state.formSettings.cv_progress_align = $( '#shapeforms-cv-progress-align' ).val() || 'left';
				}
				// A colour keeps the data-cv-unset marker until the author actually
				// picks one, so an untouched swatch stores '' ("inherit the form's
				// style") rather than the #000000 a colour input reports by default.
				[
					[ '#shapeforms-cv-bg',             'cv_bg' ],
					[ '#shapeforms-cv-question-color', 'cv_question_color' ],
					[ '#shapeforms-cv-answer-color',   'cv_answer_color' ],
					[ '#shapeforms-cv-accent',         'cv_accent' ],
					[ '#shapeforms-cv-track-color',    'cv_track_color' ],
					[ '#shapeforms-cv-prev-color',     'cv_prev_color' ],
					[ '#shapeforms-cv-prev-bg',        'cv_prev_bg' ],
					[ '#shapeforms-cv-hint-color',     'cv_hint_color' ],
					[ '#shapeforms-cv-btn-color',      'cv_btn_color' ],
					[ '#shapeforms-cv-btn-text-color', 'cv_btn_text_color' ]
				].forEach( function ( pair ) {
					var $el = $( pair[ 0 ] );
					if ( ! $el.length ) return;
					state.formSettings[ pair[ 1 ] ] = $el.attr( 'data-cv-unset' ) ? '' : ( $el.val() || '' );
				} );

				if (
					$( event.target ).is( 'input[name="shapeforms-submit-mode"]' ) ||
					$( event.target ).is( '#shapeforms-enable-admin-email' ) ||
					$( event.target ).is( '#shapeforms-enable-user-email' ) ||
					$( event.target ).is( 'input[name="shapeforms-admin-email-type"]' ) ||
					$( event.target ).is( '#shapeforms-dup-enabled' ) ||
					$( event.target ).is( 'input[name="shapeforms-dup-method"]' ) ||
					$( event.target ).is( '#shapeforms-cv-enabled' ) ||
					$( event.target ).is( '#shapeforms-cv-progress' ) ||
					$( event.target ).is( '#shapeforms-cv-welcome-enabled' )
				) {
					needsRerender = true;
				}

				renderCanvas();
				renderStylePreview();

				// The Style tab gains and loses its Default Screen Colours section
				// with the master toggle, the Accent background control inside it
				// with the indicator, and the Enter hint control with the hint
				// switch — all three live on the OTHER tab, so without this they
				// change only after a page reload.
				if (
					$( event.target ).is( '#shapeforms-cv-enabled' ) ||
					$( event.target ).is( '#shapeforms-cv-progress' ) ||
					$( event.target ).is( '#shapeforms-cv-key-hint' )
				) {
					renderStylingSettings();
				}

				if ( needsRerender ) {
					renderFormSettings();
				}
			}
		);

		$( '#shapeforms-form-title' ).on(
			'input',
			function () {
				state.formTitle = $( this ).val();
			}
		);

		// A conversational colour becomes "set" the moment the author picks one,
		// which is what distinguishes a deliberate black from an untouched
		// swatch. Both halves of the control count: the swatch and the hex box.
		$( document ).on( 'input', '[id^="shapeforms-cv-"][type="color"]', function () {
			$( this ).removeAttr( 'data-cv-unset' );
		} );

		$( document ).on( 'input', '.shapeforms-color-hex[data-color-for^="shapeforms-cv-"]', function () {
			$( '#' + $( this ).attr( 'data-color-for' ) ).removeAttr( 'data-cv-unset' );
		} );

		// Color picker → update swatch background + hex text input.
		// Excludes advanced (.bf-adv-colorpick) pickers — those have their own
		// alpha-aware handler that composes rgba() and paints the swatch overlay.
		$( document ).on( 'input', '.shapeforms-color-swatch input[type="color"]:not(.bf-adv-colorpick)', function () {
			var val = $( this ).val();
			$( this ).closest( '.shapeforms-color-swatch' ).css( 'background', val );
			$( '[data-color-for="' + $( this ).attr( 'id' ) + '"]' ).val( val );
		} );

		// Hex text input → update color picker + swatch background.
		$( document ).on( 'input', '.shapeforms-color-hex', function () {
			var val = $( this ).val().trim();
			if ( /^#[0-9a-fA-F]{6}$/.test( val ) ) {
				var pickerId = $( this ).data( 'color-for' );
				var $picker = $( '#' + pickerId );
				$picker.val( val );
				$( this ).closest( '.shapeforms-color-field' ).find( '.shapeforms-color-swatch' ).css( 'background', val );
				// Fire input event so field-settings handlers (signature colors, etc.) also update.
				if ( $picker.closest( '#shapeforms-settings-panel' ).length ) {
					$picker.trigger( 'input' );
				}
			}
		} );

		// Design theme card click.
		$( document ).on( 'click', '.shapeforms-theme-card', function () {
			bfApplyThemeWithConflictCheck( $( this ).data( 'theme' ) );
		} );

		// Choice card click — ensure radio toggles reliably on all browsers.
		$( document ).on( 'click', '.shapeforms-choice-card', function ( e ) {
			var $card = $( this );
			var $radio = $card.find( 'input[type="radio"]' );
			if ( ! $radio.length ) return;

			// Prevent double-fire: the label click already checks the radio natively,
			// so just ensure the change event propagates for the delegated handler.
			if ( ! $radio.prop( 'checked' ) ) {
				$radio.prop( 'checked', true );
			}
			$radio.trigger( 'change' );
		} );

		// Field library search.
		$( document ).on( 'input', '#shapeforms-field-search', function () {
			var q = $.trim( $( this ).val() ).toLowerCase();
			var $library = $( '#shapeforms-field-library' );
			var totalVisible = 0;

			if ( ! q ) {
				// Empty search — show everything, remove no-results.
				$library.find( '.shapeforms-library-item' ).css( 'display', '' );
				$library.find( '.shapeforms-library-group' ).css( 'display', '' );
				$( '#shapeforms-field-search-empty' ).hide();
				return;
			}

			// Filter items.
			$library.find( '.shapeforms-library-item' ).each( function () {
				var match = $( this ).text().toLowerCase().indexOf( q ) !== -1;
				$( this ).css( 'display', match ? '' : 'none' );
				if ( match ) totalVisible++;
			} );

			// Hide groups with zero visible items.
			$library.find( '.shapeforms-library-group' ).each( function () {
				var groupHasVisible = false;
				$( this ).find( '.shapeforms-library-item' ).each( function () {
					if ( $( this ).css( 'display' ) !== 'none' ) groupHasVisible = true;
				} );
				$( this ).css( 'display', groupHasVisible ? '' : 'none' );
			} );

			// No results message.
			if ( ! $( '#shapeforms-field-search-empty' ).length ) {
				$library.after( '<p id="shapeforms-field-search-empty" style="display:none;text-align:center;color:#9ca3af;font-size:13px;padding:16px 0;">No fields found.</p>' );
			}
			$( '#shapeforms-field-search-empty' ).css( 'display', totalVisible === 0 ? '' : 'none' );
		} );

		// Column layout change for existing rows.
		$( document ).on( 'click', '.shapeforms-row-layout-btn', function () {
			// Reachable from two panels now. In conversational mode there is no
			// row settings panel — the row's toolbar belongs to the screen — so
			// the same control is offered in screen settings, where the question
			// it is really asking is "how many questions share this screen?".
			var rowIndex = null !== state.selectedRowIndex && typeof state.selectedRowIndex !== 'undefined'
				? state.selectedRowIndex
				: ( getSelectedFieldLocation() || {} ).rowIndex;

			if ( null === rowIndex || typeof rowIndex === 'undefined' ) return;
			var row = getAllRows()[ rowIndex ];
			if ( ! row ) return;

			var newWidths = $( this ).data( 'widths' ).toString().split( ',' );

			if ( cvIsOn() ) {
				// A different question is being asked here, so it gets a different
				// answer. See reflowScreenColumns().
				reflowScreenColumns( rowIndex, newWidths, state.selectedFieldId );
				markDirty();
				setActiveColumn( rowIndex, 0 );
				renderAll();
				return;
			}

			var oldColumns = row.columns;
			var newColumns = [];

			// Build new columns — preserve existing fields by distributing them.
			newWidths.forEach( function ( width, i ) {
				if ( oldColumns[ i ] ) {
					newColumns.push( createColumn( width, oldColumns[ i ].fields ) );
				} else {
					newColumns.push( createColumn( width, [] ) );
				}
			} );

			// If new layout has fewer columns, move orphaned fields to the last column.
			if ( oldColumns.length > newWidths.length ) {
				var lastCol = newColumns[ newColumns.length - 1 ];
				for ( var i = newWidths.length; i < oldColumns.length; i++ ) {
					oldColumns[ i ].fields.forEach( function ( f ) {
						lastCol.fields.push( f );
					} );
				}
			}

			row.columns = newColumns;
			markDirty();
			setActiveColumn( rowIndex, 0 );
			renderAll();
		} );

		$( '#shapeforms-save-form' ).on(
			'click',
			function () {
				saveForm();
			}
		);

		// Any value edit in the builder (field settings, form settings, title, style
		// controls, option repeaters) marks the form dirty so Preview can warn about
		// unsaved changes. Structural changes (add/remove/move field & row, layout,
		// template import) are flagged in their mutation functions via markDirty().
		$( '#shapeforms-builder-main' ).on( 'input change', 'input, textarea, select', markDirty );

		// Preview button. The preview page renders the SAVED form from the database, so an
		// unsaved change can only be previewed by saving first. If there are unsaved changes
		// we ask the user (Save & Preview / Preview last saved / Cancel) instead of silently
		// saving; with no unsaved changes we navigate straight to the preview.
		function shapeformsPreviewUrl( formId ) {
			return 'admin.php?page=shapeforms-lite-preview&form_id=' + formId;
		}

		function shapeformsSaveThenPreview() {
			saveForm( function ( formId ) {
				if ( formId ) {
					window.location.href = shapeformsPreviewUrl( formId );
				}
			} );
		}

		function shapeformsShowPreviewUnsavedDialog() {
			$( '#shapeforms-preview-confirm' ).remove();

			// "Preview last saved" is only meaningful once the form has been saved at least once.
			var canPreviewSaved = !! state.formId;

			var $overlay = $(
				'<div id="shapeforms-preview-confirm" role="dialog" aria-modal="true" aria-labelledby="shapeforms-preview-confirm-title" ' +
					'style="position:fixed;inset:0;z-index:100050;display:flex;align-items:center;justify-content:center;background:rgba(15,23,42,.55)">' +
					'<div style="background:#fff;max-width:460px;width:calc(100% - 40px);border-radius:10px;box-shadow:0 20px 60px rgba(0,0,0,.3);padding:24px">' +
						'<h2 id="shapeforms-preview-confirm-title" style="margin:0 0 8px;font-size:18px;line-height:1.3">' +
							escapeHtml( shapeformsLiteBuilder.labels && shapeformsLiteBuilder.labels.previewUnsavedTitle ? shapeformsLiteBuilder.labels.previewUnsavedTitle : 'You have unsaved changes' ) +
						'</h2>' +
						'<p style="margin:0 0 20px;color:#475569;line-height:1.5">' +
							escapeHtml( shapeformsLiteBuilder.labels && shapeformsLiteBuilder.labels.previewUnsavedBody ? shapeformsLiteBuilder.labels.previewUnsavedBody : 'The preview shows your saved form. Save your changes first to preview your latest work.' ) +
						'</p>' +
						'<div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end">' +
							'<button type="button" class="button" data-bf-prev="cancel">' +
								escapeHtml( shapeformsLiteBuilder.labels && shapeformsLiteBuilder.labels.cancel ? shapeformsLiteBuilder.labels.cancel : 'Cancel' ) +
							'</button>' +
							( canPreviewSaved
								? '<button type="button" class="button" data-bf-prev="saved">' +
									escapeHtml( shapeformsLiteBuilder.labels && shapeformsLiteBuilder.labels.previewSaved ? shapeformsLiteBuilder.labels.previewSaved : 'Preview last saved' ) +
									'</button>'
								: '' ) +
							'<button type="button" class="button button-primary" data-bf-prev="save">' +
								escapeHtml( shapeformsLiteBuilder.labels && shapeformsLiteBuilder.labels.savePreview ? shapeformsLiteBuilder.labels.savePreview : 'Save & Preview' ) +
							'</button>' +
						'</div>' +
					'</div>' +
				'</div>'
			);

			function closeDialog() {
				$overlay.remove();
				$( document ).off( 'keydown.bfpreview' );
			}

			$overlay.on( 'click', function ( e ) {
				var action = $( e.target ).closest( '[data-bf-prev]' ).attr( 'data-bf-prev' );
				if ( e.target === $overlay[ 0 ] ) {
					action = 'cancel'; // clicked the backdrop
				}
				if ( ! action ) {
					return;
				}
				if ( 'cancel' === action ) {
					closeDialog();
				} else if ( 'saved' === action ) {
					window.location.href = shapeformsPreviewUrl( state.formId );
				} else if ( 'save' === action ) {
					closeDialog();
					shapeformsSaveThenPreview();
				}
			} );

			$( document ).on( 'keydown.bfpreview', function ( e ) {
				if ( 'Escape' === e.key ) {
					closeDialog();
				}
			} );

			$( 'body' ).append( $overlay );
			$overlay.find( '[data-bf-prev="save"]' ).trigger( 'focus' );
		}

		$( '#shapeforms-preview-form' ).on(
			'click',
			function ( event ) {
				event.preventDefault();

				if ( hasUnsavedChanges ) {
					shapeformsShowPreviewUnsavedDialog();
				} else if ( state.formId ) {
					window.location.href = shapeformsPreviewUrl( state.formId );
				} else {
					// Brand-new form never saved yet — save so there is something to preview.
					shapeformsSaveThenPreview();
				}
			}
		);

		$( '#shapeforms-builder-shortcode' ).on(
			'click',
			function () {
				copyShortcode();
			}
		);

		$( document ).on( 'click', '#shapeforms-add-row-canvas', function () {
			openRowModal();
		} );

		$( '#shapeforms-open-template-modal' ).on(
			'click',
			function () {
				openTemplateModal();
			}
		);

		$( document ).on(
			'click',
			'[data-template]',
			function () {
				applyTemplate( String( $( this ).data( 'template' ) || 'blank' ) );
			}
		);

		$( document ).on(
			'click',
			'[data-template-option]',
			function () {
				state.selectedTemplate = String( $( this ).data( 'template-option' ) || 'contact' );
				renderTemplateModal();
			}
		);

		// Accordion: toggle a template category open/closed on header click. Purely
		// DOM-driven (no re-render), so categories toggle independently — several can
		// be open at once, and the state persists until the modal is re-rendered.
		$( document ).on(
			'click',
			'[data-template-group-toggle]',
			function () {
				var open = $( this ).closest( '.shapeforms-template-group' )
					.toggleClass( 'is-open' )
					.hasClass( 'is-open' );
				$( this ).attr( 'aria-expanded', open ? 'true' : 'false' );
			}
		);


		$( document ).on(
			'click',
			'.shapeforms-preset',
			function () {
				var widths = String( $( this ).data( 'widths' ) ).split( ',' );

				addRow( widths );
				closeRowModal();
			}
		);

		$( document ).on(
			'click',
			'[data-shapeforms-close-modal]',
			function () {
				closeRowModal();
			}
		);

		$( document ).on(
			'click',
			'[data-shapeforms-close-template-modal]',
			function () {
				closeTemplateModal();
			}
		);

		if ( getAllRows().length ) {
			setActiveColumn( 0, 0 );
		}

		// --- Setup screen for new forms ---
		function closeSetupScreen() {
			$( '#shapeforms-setup-screen' ).attr( 'hidden', true );
			$( '#shapeforms-builder-main' ).removeAttr( 'hidden' );
		}

		$( document ).on( 'click', '#shapeforms-setup-blank', function () {
			closeSetupScreen();
			addRow( [ '100%' ] );
		} );

		$( document ).on( 'click', '#shapeforms-setup-template', function () {
			openTemplateModal();
		} );

		// A shortcut to a blank form with conversational mode pre-enabled — NOT a
		// separate form type. Everything downstream (storage, builder, rendering)
		// is identical to any other form, so the author can switch the mode off
		// again at any point without converting or losing anything.
		$( document ).on( 'click', '#shapeforms-setup-conversational', function () {
			state.formSettings.cv_enabled = true;
			closeSetupScreen();
			addRow( [ '100%' ] );
			// Behaves exactly like Blank Form — straight to the builder canvas.
			// The only difference is that conversational mode starts switched on;
			// jumping to the Settings tab instead would drop the author somewhere
			// they cannot begin building.
			renderFormSettings();
		} );

		// Import template — also closes setup screen if open.
		$( '#shapeforms-import-template' ).on( 'click', function () {
			closeTemplateModal();
			if ( ! $( '#shapeforms-setup-screen' ).is( '[hidden]' ) ) {
				closeSetupScreen();
			}
			applyTemplate( state.selectedTemplate || 'contact' );
		} );

		updateShortcodeDisplay();
		renderAll();

		// Initial render is done — from here on, mutations count as unsaved changes.
		builderReady = true;

		// Canvas is rendered — remove the loading overlay shown for existing forms
		// so the real layout (or the empty state) is revealed without flashing the
		// "Start building your form" placeholder while this script downloaded.
		$( '#shapeforms-canvas-loading' ).fadeOut( 150, function () {
			$( this ).remove();
		} );

		// Expose builder state globally so companion scripts (integrations.js, Pro modules) can read it.
		window.shapeformsBuilderState = state;
	}
);
