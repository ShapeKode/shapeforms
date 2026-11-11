/**
 * AI Form Builder — builder integration.
 *
 * Adds a "Create with AI" card beside Lite's Blank Form / Use a Template cards,
 * collects a description, and turns the generated field spec into a real form on
 * the canvas.
 *
 * ── Why this needs no Lite changes ──────────────────────────────────────────
 *
 * Lite already supports third-party templates: `getAllTemplateDefinitions()`
 * merges `shapeformsLiteBuilder.proTemplates` over its own, and `applyTemplate()`
 * runs whatever it finds through `normalizeStructure()` — which assigns field
 * IDs and fills in every default, precisely because add-on templates "arrive as
 * plain PHP-serialized objects without pre-generated IDs" (builder.js).
 *
 * A generated form is structurally identical to a registered template, so instead of
 * reaching into Lite's closure (renderAll, state, normalizeStructure are all
 * private) we register the result as a template and trigger Lite's own
 * `[data-template]` click handler. Lite does the building; we never touch its
 * internals, so this keeps working across Lite releases.
 *
 * @package ShapeFormsLite
 */

( function ( $ ) {
	'use strict';

	var cfg = window.shapeformsLiteAI || {};
	var i18n = cfg.i18n || {};

	// Template key our generated form is registered under. Prefixed so it can
	// never collide with a built-in or add-on template name.
	var TEMPLATE_KEY = '__shapeforms_ai_generated';

	var $modal = null;
	var busy = false;

	// The loading preview, one entry per row. `label` is the width of the
	// placeholder label in percent — varied on purpose, so the bars read as a
	// form with real questions on it rather than as a stack of identical lines.
	// A paired row and a tall one between them cover both things a generated
	// form does that a plain list does not: columns, and long answers.
	//
	// Deliberately only two rows. The preview takes over the space the
	// suggestion chips occupied, and a third row grew the panel by enough
	// (~150px) that the whole dialog jumped the moment Generate was pressed.
	var SKELETON_ROWS = [
		[ { label: 38 }, { label: 30 } ],
		[ { label: 52, tall: true } ]
	];

	/**
	 * Escapes a string for safe insertion into markup.
	 *
	 * @param {string} value Raw value.
	 * @return {string} Escaped value.
	 */
	function esc( value ) {
		return $( '<div/>' ).text( value == null ? '' : String( value ) ).html();
	}

	/**
	 * The sparkle mark used on both cards.
	 *
	 * Dashicons has no sparkle, and the nearest candidates all read as
	 * something else — `superhero-alt` (used here before) is a gem in a shield,
	 * which says "premium" on a feature that is free. A four-point star with a
	 * smaller companion is the mark every mainstream AI affordance now uses, so
	 * it is the one glyph a user reads as "AI" before reading the label.
	 *
	 * The fill is a gradient rather than `currentColor` because every flat hue
	 * in this row is already spoken for — blue is Blank, green is Template,
	 * violet is Conversational. Two hues sweeping across the mark is what makes
	 * this card identifiable at a glance without stealing a sibling's colour.
	 *
	 * Both cards can sit in the DOM at once (the setup screen and the empty
	 * canvas are siblings, one of them hidden), so the gradient id is suffixed
	 * per variant — a duplicate id would make one card paint with the other's
	 * gradient.
	 *
	 * @param {string} variant Either 'setup' or 'start'.
	 * @return {string} Inline SVG markup.
	 */
	function sparkle( variant ) {
		var id = 'shapeforms-ai-spark-' + variant;

		// The viewBox origin is offset so the two stars are optically centred in
		// the tile. Their combined bounding box sits right of and below the
		// centre of a plain 0 0 24 24 box, which reads as a misaligned mark
		// beside the four square dashicons on either side of it.
		return '<svg class="shapeforms-ai-spark" viewBox="1.9 0.45 24 24" aria-hidden="true" focusable="false">' +
			'<defs>' +
				'<linearGradient id="' + id + '" x1="2" y1="2" x2="22" y2="22" gradientUnits="userSpaceOnUse">' +
					'<stop offset="0" stop-color="#4f46e5"></stop>' +
					'<stop offset="0.55" stop-color="#9333ea"></stop>' +
					'<stop offset="1" stop-color="#d946ef"></stop>' +
				'</linearGradient>' +
			'</defs>' +
			'<path fill="url(#' + id + ')" d="M12 2c.9 5.1 2 6.2 7.1 7.1-5.1.9-6.2 2-7.1 7.1-.9-5.1-2-6.2-7.1-7.1 5.1-.9 6.2-2 7.1-7.1Z"></path>' +
			'<path fill="url(#' + id + ')" opacity="0.75" d="M18.5 14.1c.56 3.16 1.24 3.84 4.4 4.4-3.16.56-3.84 1.24-4.4 4.4-.56-3.16-1.24-3.84-4.4-4.4 3.16-.56 3.84-1.24 4.4-4.4Z"></path>' +
		'</svg>';
	}

	/**
	 * Builds the "Create with AI" card, matching the surrounding card markup.
	 *
	 * The two host screens use different card classes (the new-form setup screen
	 * and the empty-canvas state), so the caller passes the class to mirror.
	 *
	 * @param {string} variant Either 'setup' or 'start'.
	 * @return {jQuery} The card element.
	 */
	function buildCard( variant ) {
		var title = esc( i18n.cardTitle || 'Create with AI' );
		var text = esc( i18n.cardText || '' );
		var html;

		if ( 'setup' === variant ) {
			html =
				'<button type="button" class="shapeforms-setup-card shapeforms-ai-card">' +
					'<span class="shapeforms-setup-card__icon shapeforms-ai-card__icon">' +
						sparkle( 'setup' ) +
					'</span>' +
					'<strong>' + title + '</strong>' +
					'<span>' + text + '</span>' +
				'</button>';
		} else {
			html =
				'<button type="button" class="shapeforms-start-card shapeforms-ai-card">' +
					'<span class="shapeforms-start-card__icon shapeforms-ai-card__icon">' +
						sparkle( 'start' ) +
					'</span>' +
					'<span class="shapeforms-start-card__body">' +
						'<strong>' + title + '</strong>' +
						'<span>' + text + '</span>' +
					'</span>' +
					'<span class="shapeforms-start-card__arrow dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>' +
				'</button>';
		}

		return $( html );
	}

	/**
	 * Adds the card to both places Lite offers a starting point.
	 *
	 * Guarded so a re-render cannot produce duplicates.
	 *
	 * @return {void}
	 */
	function injectCards() {
		var $setup = $( '.shapeforms-setup-choices' );
		if ( $setup.length && ! $setup.find( '.shapeforms-ai-card' ).length ) {
			$setup.append( buildCard( 'setup' ) );
		}

		var $start = $( '.shapeforms-start-grid' );
		if ( $start.length && ! $start.find( '.shapeforms-ai-card' ).length ) {
			$start.append( buildCard( 'start' ) );
		}
	}

	/**
	 * Builds the placeholder rows shown while a generation runs.
	 *
	 * Marked `aria-hidden`: it is a picture of a form, not a form, and reading
	 * it out would announce a stack of empty groups. The status line beside it
	 * carries the same news in one sentence.
	 *
	 * @return {string} Markup for the preview rows.
	 */
	function skeletonRows() {
		return SKELETON_ROWS.map( function ( row ) {
			var fields = row.map( function ( field ) {
				var control = 'shapeforms-ai-skeleton__bar shapeforms-ai-skeleton__bar--control';

				if ( field.tall ) {
					control += ' shapeforms-ai-skeleton__bar--tall';
				}

				return '<span class="shapeforms-ai-skeleton__field">' +
						'<span class="shapeforms-ai-skeleton__bar shapeforms-ai-skeleton__bar--label" style="width:' + field.label + '%"></span>' +
						'<span class="' + control + '"></span>' +
					'</span>';
			} ).join( '' );

			return '<span class="shapeforms-ai-skeleton__row">' + fields + '</span>';
		} ).join( '' );
	}

	/**
	 * Builds the prompt modal once and caches it.
	 *
	 * @return {jQuery} The modal element.
	 */
	function getModal() {
		if ( $modal ) {
			return $modal;
		}

		var suggestions = Array.isArray( cfg.suggestions ) ? cfg.suggestions : [];
		var chips = '';

		if ( suggestions.length ) {
			chips += '<div class="shapeforms-ai-modal__suggest-label">' + esc( i18n.suggestLabel || '' ) + '</div>';
			chips += '<div class="shapeforms-ai-modal__suggestions">';
			suggestions.forEach( function ( item ) {
				chips += '<button type="button" class="shapeforms-ai-suggestion">' + esc( item ) + '</button>';
			} );
			chips += '</div>';
		}

		$modal = $(
			'<div class="shapeforms-ai-modal" hidden>' +
				'<div class="shapeforms-ai-modal__backdrop"></div>' +
				'<div class="shapeforms-ai-modal__panel" role="dialog" aria-modal="true" aria-labelledby="shapeforms-ai-modal-title">' +
					'<button type="button" class="shapeforms-ai-modal__close" aria-label="' + esc( i18n.close || 'Close' ) + '">' +
						'<span class="dashicons dashicons-no-alt"></span>' +
					'</button>' +
					'<h2 id="shapeforms-ai-modal-title">' + esc( i18n.modalTitle || '' ) + '</h2>' +
					'<p class="shapeforms-ai-modal__intro">' + esc( i18n.modalIntro || '' ) + '</p>' +
					'<textarea class="shapeforms-ai-modal__input" rows="4" maxlength="' + ( parseInt( cfg.maxChars, 10 ) || 1000 ) + '" ' +
						'placeholder="' + esc( i18n.placeholder || '' ) + '"></textarea>' +
					chips +
					'<div class="shapeforms-ai-modal__skeleton" hidden>' +
						'<p class="shapeforms-ai-skeleton__status" role="status">' +
							'<span class="shapeforms-ai-skeleton__pulse" aria-hidden="true"></span>' +
							'<span class="shapeforms-ai-skeleton__status-text"></span>' +
						'</p>' +
						'<span class="shapeforms-ai-skeleton__form" aria-hidden="true">' +
							skeletonRows() +
						'</span>' +
					'</div>' +
					'<div class="shapeforms-ai-modal__notice" hidden></div>' +
					'<div class="shapeforms-ai-modal__actions">' +
						'<button type="button" class="button shapeforms-ai-modal__cancel">' + esc( i18n.cancel || 'Cancel' ) + '</button>' +
						'<button type="button" class="button button-primary shapeforms-ai-modal__submit">' + esc( i18n.generate || 'Generate' ) + '</button>' +
					'</div>' +
				'</div>' +
			'</div>'
		);

		$( 'body' ).append( $modal );
		return $modal;
	}

	/**
	 * Shows the modal and focuses the input.
	 *
	 * @return {void}
	 */
	function openModal() {
		var $m = getModal();
		$m.removeAttr( 'hidden' );
		$m.find( '.shapeforms-ai-modal__notice' ).attr( 'hidden', true ).text( '' );
		$m.find( '.shapeforms-ai-modal__input' ).trigger( 'focus' );
	}

	/**
	 * Hides the modal, unless a generation is in flight.
	 *
	 * @return {void}
	 */
	function closeModal() {
		if ( busy ) {
			return;
		}
		if ( $modal ) {
			$modal.attr( 'hidden', true );
		}
	}

	/**
	 * Shows an inline message inside the modal.
	 *
	 * @param {string} message Text to show.
	 * @param {string} tone    Either 'error' or 'info'.
	 * @return {void}
	 */
	function notify( message, tone ) {
		if ( ! $modal ) {
			return;
		}
		$modal
			.find( '.shapeforms-ai-modal__notice' )
			.removeClass( 'is-error is-info' )
			.addClass( 'is-' + ( tone || 'error' ) )
			.text( message )
			.removeAttr( 'hidden' );
	}

	/**
	 * Toggles the in-flight state of the modal.
	 *
	 * @param {boolean} state Whether a request is running.
	 * @return {void}
	 */
	function setBusy( state ) {
		busy = state;

		if ( ! $modal ) {
			return;
		}

		$modal.toggleClass( 'is-busy', state );

		// The button keeps its label and only goes disabled. It used to restate
		// "Building your form…", which the status line below now says next to
		// the preview — saying it twice is noise, and the longer label stretched
		// the button enough to shift the row it sits in.
		$modal.find( '.shapeforms-ai-modal__submit' ).prop( 'disabled', state );
		$modal.find( '.shapeforms-ai-modal__cancel, .shapeforms-ai-modal__close, .shapeforms-ai-modal__input' )
			.prop( 'disabled', state );

		var $skeleton = $modal.find( '.shapeforms-ai-modal__skeleton' );
		var $status = $skeleton.find( '.shapeforms-ai-skeleton__status-text' );

		if ( state ) {
			// Whatever the last attempt reported is no longer true the moment a
			// new one starts, so the notice goes before the preview arrives —
			// otherwise a stale failure sits underneath a running generation.
			$modal.find( '.shapeforms-ai-modal__notice' ).attr( 'hidden', true ).text( '' );

			$skeleton.removeAttr( 'hidden' );

			// Filled AFTER unhiding, not baked into the markup: a live region
			// announces changes made while it is rendered, so text that was
			// already sitting inside a hidden element is never read out.
			$status.text( i18n.generating || '' );
		} else {
			$skeleton.attr( 'hidden', true );
			$status.text( '' );
		}
	}

	/**
	 * Converts a validated field spec into Lite's rows -> columns -> fields shape.
	 *
	 * Three things happen here that the flat spec cannot express on its own.
	 *
	 * LAYOUT. Fields are packed onto a row while their widths still fit inside
	 * 100%, so two 50% fields share a line and the next one starts a new row.
	 * Packing rather than trusting a "new row" flag means the model only has to
	 * be right about how wide a field is, never about where a row ends — the
	 * arithmetic is ours and cannot disagree with itself.
	 *
	 * STEPS. `page_break` takes a row of its own and forces the next field onto
	 * a fresh one, which is how Lite represents a step boundary.
	 *
	 * CONDITIONS. The model refers to fields by the `ref` it invented; Lite
	 * refers to them by ID. IDs are therefore assigned here, up front, so a
	 * condition can be resolved to a real target. `normalizeField()` keeps an ID
	 * we supply rather than generating its own, which is what makes this work.
	 *
	 * Only the keys the model supplies are set — Lite's normalizer fills in
	 * every other default, so this must not try to build a complete field object.
	 *
	 * @param {Array} fields Validated fields from the server.
	 * @return {Object} An object with `rows` and the count of skipped fields.
	 */
	function toStructure( fields ) {
		var allowed = Array.isArray( cfg.allowedTypes ) ? cfg.allowedTypes : [];
		var rows = [];
		var skipped = 0;
		var stamp = Date.now();
		var idByRef = {};
		var kept = [];

		// Pass one: drop what this builder cannot render, and give everything
		// that survives its ID. Conditions can only be resolved once every ID
		// exists, so this cannot be folded into the pass below.
		fields.forEach( function ( field, index ) {
			// The server validates this too. Re-checking here means a field type
			// that this builder cannot render is reported to the admin rather
			// than silently vanishing from the form.
			if ( allowed.length && allowed.indexOf( field.type ) === -1 ) {
				skipped++;
				return;
			}

			var id = 'bf_' + stamp + '_' + index;

			if ( field.ref ) {
				idByRef[ field.ref ] = id;
			}

			kept.push( { spec: field, id: id } );
		} );

		// Pass two: build each field, then pack it onto a row.
		var current = null;
		var used = 0;

		kept.forEach( function ( item ) {
			var spec = item.spec;
			var built = {
				id: item.id,
				type: spec.type,
				label: spec.label,
				placeholder: spec.placeholder || '',
				required: !! spec.required,
				options: Array.isArray( spec.options ) ? spec.options : [],
				description: spec.description || '',
				default_value: spec.default_value || '',
				min_value: spec.min || '',
				max_value: spec.max || '',
				step_value: spec.step || ''
			};

			// The server already guarantees the ref names an earlier surviving
			// field. It cannot know which types THIS builder skipped, though, so
			// a condition whose target did not make it is dropped rather than
			// pointed at nothing — a field hidden behind a missing answer would
			// never appear at all.
			var cond = spec.show_if;
			if ( cond && cond.ref && idByRef[ cond.ref ] ) {
				built.conditional = {
					enabled: true,
					action: 'show',
					logic: 'AND',
					conditions: [ {
						field_id: idByRef[ cond.ref ],
						operator: cond.operator || 'is',
						value: typeof cond.value === 'undefined' ? '' : cond.value
					} ]
				};
			}

			var width = parseFloat( spec.width );
			if ( ! ( width > 0 ) || width > 100 ) {
				width = 100;
			}

			// A step boundary is not a column; it owns its row outright.
			var solo = 'page_break' === spec.type;

			// 33.33 x 3 is 99.99, so the tolerance is what lets three thirds
			// share a row instead of spilling the last one.
			if ( solo || ! current || used + width > 100.5 ) {
				current = { columns: [] };
				rows.push( current );
				used = 0;
			}

			current.columns.push( {
				width: solo ? '100%' : ( spec.width || '100%' ),
				fields: [ built ]
			} );
			used += width;

			if ( solo ) {
				current = null;
				used = 0;
			}
		} );

		return { rows: rows, skipped: skipped };
	}

	/**
	 * Hands the structure to Lite by registering it as a template and firing
	 * Lite's own template-apply handler.
	 *
	 * @param {string} title Form title.
	 * @param {Array}  rows  Row structure.
	 * @return {void}
	 */
	function applyStructure( title, rows ) {
		window.shapeformsLiteBuilder = window.shapeformsLiteBuilder || {};
		window.shapeformsLiteBuilder.proTemplates = window.shapeformsLiteBuilder.proTemplates || {};

		window.shapeformsLiteBuilder.proTemplates[ TEMPLATE_KEY ] = {
			title: title || '',
			description: '',
			rows: rows
		};

		// On a brand-new form the setup screen sits over the canvas, and
		// applyTemplate() does not dismiss it — Lite's own "Import template"
		// button closes it first and then applies. Mirror that, otherwise the
		// form builds correctly but stays hidden behind the setup screen.
		var $setup = $( '#shapeforms-setup-screen' );
		if ( $setup.length && ! $setup.is( '[hidden]' ) ) {
			$setup.attr( 'hidden', true );
			$( '#shapeforms-builder-main' ).removeAttr( 'hidden' );
		}

		// Lite binds `[data-template]` clicks on document, so the trigger element
		// has to be in the DOM for the delegated handler to see it.
		var $trigger = $( '<button type="button" data-template="' + TEMPLATE_KEY + '"></button>' )
			.css( { position: 'absolute', left: '-9999px' } )
			.appendTo( 'body' );

		$trigger.trigger( 'click' );
		$trigger.remove();
	}

	/**
	 * Runs a generation end to end.
	 *
	 * @return {void}
	 */
	function generate() {
		if ( busy ) {
			return;
		}

		var $input = getModal().find( '.shapeforms-ai-modal__input' );
		var prompt = $.trim( $input.val() || '' );

		if ( ! prompt ) {
			notify( i18n.emptyPrompt || '', 'error' );
			$input.trigger( 'focus' );
			return;
		}

		setBusy( true );

		$.ajax( {
			url: cfg.endpoint,
			method: 'POST',
			dataType: 'json',
			contentType: 'application/json',
			data: JSON.stringify( { prompt: prompt } ),
			beforeSend: function ( xhr ) {
				xhr.setRequestHeader( 'X-WP-Nonce', cfg.nonce );
			}
		} )
			.done( function ( response ) {
				var fields = response && Array.isArray( response.fields ) ? response.fields : [];

				if ( ! fields.length ) {
					setBusy( false );
					notify( i18n.genericError || '', 'error' );
					return;
				}

				var built = toStructure( fields );

				if ( ! built.rows.length ) {
					setBusy( false );
					notify( i18n.genericError || '', 'error' );
					return;
				}

				// Report dropped fields rather than letting the admin discover a
				// silently incomplete form later.
				if ( built.skipped > 0 ) {
					var template = built.skipped === 1
						? ( i18n.skipped || '' )
						: ( i18n.skippedMany || '' );
					window.console && window.console.warn(
						'ShapeForms AI: ' + template.replace( '%d', built.skipped )
					);
				}

				setBusy( false );
				closeModal();
				applyStructure( response.title, built.rows );
			} )
			.fail( function ( xhr ) {
				setBusy( false );

				var message = i18n.genericError || '';
				if ( xhr && xhr.responseJSON && xhr.responseJSON.message ) {
					message = xhr.responseJSON.message;
				}
				notify( message, 'error' );
			} );
	}

	$( function () {
		if ( ! cfg.endpoint ) {
			return;
		}

		injectCards();

		// The empty-canvas state is re-rendered whenever the canvas changes, so
		// re-inject on any click that might have rebuilt it. Cheap and guarded.
		$( document ).on( 'click', '#shapeforms-canvas-rows, .shapeforms-canvas-panel', function () {
			injectCards();
		} );

		$( document ).on( 'click', '.shapeforms-ai-card', function ( event ) {
			event.preventDefault();
			event.stopPropagation();
			openModal();
		} );

		$( document ).on( 'click', '.shapeforms-ai-modal__cancel, .shapeforms-ai-modal__close, .shapeforms-ai-modal__backdrop', function () {
			closeModal();
		} );

		$( document ).on( 'click', '.shapeforms-ai-modal__submit', function () {
			generate();
		} );

		$( document ).on( 'click', '.shapeforms-ai-suggestion', function () {
			getModal().find( '.shapeforms-ai-modal__input' ).val( $( this ).text() ).trigger( 'focus' );
		} );

		// Ctrl/Cmd+Enter submits, Escape closes.
		$( document ).on( 'keydown', '.shapeforms-ai-modal__input', function ( event ) {
			if ( ( event.metaKey || event.ctrlKey ) && 13 === event.which ) {
				generate();
			}
		} );

		$( document ).on( 'keydown', function ( event ) {
			if ( 27 === event.which && $modal && ! $modal.attr( 'hidden' ) ) {
				closeModal();
			}
		} );
	} );
} )( jQuery );
