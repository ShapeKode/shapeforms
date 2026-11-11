jQuery(
	function ( $ ) {
		// Monotonic counter giving every error message <div> a unique id, so the
		// aria-describedby link is unambiguous even when a form is embedded more than
		// once on a page (wrappers share their data-bf-field-id across embeds).
		var bfErrorSeq = 0;

		/**
		 * Returns the operable control(s) inside a field wrapper that should carry
		 * aria-invalid / aria-describedby. For custom selects the native <select> is
		 * display:none (not focusable), so the visible .bf-select__trigger is used;
		 * the star rating exposes its role="radiogroup" container; composite name /
		 * address / choice groups expose all their visible inputs.
		 *
		 * @param {jQuery} $wrapper The .shapeforms-lite-form__field element.
		 * @return {jQuery} The control element(s) to annotate.
		 */
		function getInvalidControls( $wrapper ) {
			var $trigger = $wrapper.find( '.bf-select__trigger' );
			if ( $trigger.length ) {
				return $trigger;
			}
			var $stars = $wrapper.find( '.shapeforms-lite-star-rating' );
			if ( $stars.length ) {
				return $stars;
			}
			var $controls = $wrapper.find( 'input:not([type="hidden"]), textarea, select:not([style*="display:none"])' );
			return $controls.length ? $controls : $wrapper;
		}

		// Strip the error association from a wrapper: remove aria-invalid and unlink
		// the aria-describedby pointer we added (leaving any pre-existing tokens alone).
		function clearFieldErrorState( $wrapper ) {
			var errorId = $wrapper.data( 'bf-error-id' );
			getInvalidControls( $wrapper ).each( function () {
				var $c = $( this );
				$c.removeAttr( 'aria-invalid' );
				if ( errorId ) {
					var tokens = ( $c.attr( 'aria-describedby' ) || '' ).split( /\s+/ ).filter( function ( t ) {
						return t && t !== errorId;
					} );
					if ( tokens.length ) {
						$c.attr( 'aria-describedby', tokens.join( ' ' ) );
					} else {
						$c.removeAttr( 'aria-describedby' );
					}
				}
			} );
			$wrapper.removeData( 'bf-error-id' ).removeAttr( 'data-bf-error-id' );
		}

		function clearFieldErrors( $form ) {
			$form.find( '.is-invalid' ).each( function () {
				clearFieldErrorState( $( this ) );
			} );
			$form.find( '.shapeforms-lite-form__field-error' ).remove();
			$form.find( '.is-invalid' ).removeClass( 'is-invalid' );
		}

		/**
		 * Empties the validation summary that sits beside the submit button.
		 *
		 * Separate from clearFieldErrors() because the two are cleared at
		 * different moments: the field messages also go on every edit, while the
		 * summary only makes sense to clear when the form is submitted again.
		 *
		 * @param {jQuery} $form The form element.
		 * @return {void}
		 */
		function clearFootMessage( $form ) {
			$form.find( '[data-shapeforms-message-foot]' )
				.removeClass( 'is-visible is-error is-success' )
				.text( '' );
		}

		function showFieldError( $wrapper, message ) {
			$wrapper.addClass( 'is-invalid' );

			// Unique id for the message so the control can reference it via
			// aria-describedby. role="alert" (an assertive live region) makes SRs
			// announce the freshly-inserted text.
			var errorId = 'shapeforms-error-' + ( ++bfErrorSeq );
			$wrapper.data( 'bf-error-id', errorId );
			$wrapper.append(
				'<div class="shapeforms-lite-form__field-error" id="' + errorId + '" role="alert">' + $( '<span />' ).text( message ).html() + '</div>'
			);

			getInvalidControls( $wrapper ).each( function () {
				var $c = $( this );
				$c.attr( 'aria-invalid', 'true' );
				var existing = $c.attr( 'aria-describedby' );
				$c.attr( 'aria-describedby', existing ? existing + ' ' + errorId : errorId );
			} );
		}

		function showFieldErrors( $form, errors ) {
			if ( ! errors || typeof errors !== 'object' ) {
				return;
			}

			$.each(
				errors,
				function ( fieldId, message ) {
					var $wrapper = $form.find( '[name="shapeforms_' + fieldId + '"], [name="shapeforms_' + fieldId + '[]"]' ).first().closest( '.shapeforms-lite-form__field' );

					if ( $wrapper.length ) {
						showFieldError( $wrapper, message );
					}
				}
			);

			focusFirstInvalid( $form );
		}

		/**
		 * Marks where the first problem is WITHOUT moving the page.
		 *
		 * This used to focus the first invalid control, which pulled the viewport
		 * up to it — press Submit at the foot of a long form and the page jumped
		 * to the top, which reads as the form having reloaded and losing the
		 * answers rather than as an answer to the button.
		 *
		 * Nothing is lost by staying put. Each field's message is inserted with
		 * role="alert", an assertive live region, so a screen reader announces the
		 * failures as they appear whether or not anything is focused, and the
		 * summary beside the button says how many there are.
		 *
		 * @param {jQuery} $form The form element.
		 * @return {void}
		 */
		function focusFirstInvalid( $form ) {
			// Intentionally does not call focus(). Kept as a named seam so the
			// decision has one place to live and can be reversed in one line —
			// `$target[0].focus( { preventScroll: true } )` restores focus for
			// keyboard users without bringing the jump back with it.
		}

		/**
		 * Runs the client-side pre-submit validation pass over a form.
		 *
		 * This is a UX enhancement only — the server (form handler) remains the
		 * authoritative validation gate. Marks invalid fields and returns the result.
		 *
		 * @param {jQuery} $form The form element.
		 * @return {boolean} True when every checked field is valid.
		 */
		function validateClientSide( $form ) {
			var valid = true;

			// Validate all fields.
			$form.find( '.shapeforms-lite-form__field' ).each(
				function () {
					var $wrapper = $( this );
					var $required = $wrapper.find( 'input[required], textarea[required], select[required]' ).first();
					var $email = $wrapper.find( 'input[type="email"]' ).first();

					// A required dropdown is invisible to the selector above. The
					// native <select> is rendered display:none behind a custom
					// widget and carries no `required` attribute — the requirement
					// lives on the widget as aria-required. Without this fallback a
					// required dropdown left empty passes client-side validation
					// and is only caught by the server.
					if ( ! $required.length && $wrapper.find( '[aria-required="true"]' ).length ) {
						$required = $wrapper.find( 'select[data-shapeforms-select]' ).first();
					}

					// Required check.
					if ( $required.length && $wrapper.data( 'error' ) ) {
						var val = $required.val();
						var type = $required.attr( 'type' ) || '';
						var isEmpty = false;

						if ( 'checkbox' === type || 'radio' === type ) {
							var name = $required.attr( 'name' );
							isEmpty = ! $form.find( 'input[name="' + name + '"]:checked' ).length;
						} else {
							isEmpty = ! val || ! $.trim( val );
						}

						if ( isEmpty ) {
							showFieldError( $wrapper, $wrapper.data( 'error' ) );
							valid = false;
							return;
						}
					}

					// Email format check.
					if ( $email.length ) {
						var emailVal = $.trim( $email.val() || '' );
						if ( emailVal && ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( emailVal ) ) {
							showFieldError( $wrapper, 'Please enter a valid email address.' );
							valid = false;
						}
					}
				}
			);

			return valid;
		}

		// Disable native browser validation only when JS is loaded — keeps HTML5 required as fallback.
		$( '.shapeforms-lite-form' ).attr( 'novalidate', 'novalidate' );

		// ── File upload drop-zone ──
		// The native <input type="file"> is hidden but stretched over the zone for its
		// click target. Its native drag-and-drop is NOT enough on its own: once a
		// 'dragover' listener calls preventDefault() (required below so the zone shows
		// the drag-over highlight instead of the browser's reject cursor), the browser
		// treats the drop as handled by page script and no longer auto-populates the
		// input — so the 'drop' handler must manually transfer the files itself.
		$( document ).on( 'change', '.shapeforms-lite-form__file-input', function () {
			var $zone = $( this ).closest( '.shapeforms-lite-form__file' );
			var $text = $zone.find( '.shapeforms-lite-form__file-text' );
			var files = this.files;
			if ( files && files.length ) {
				$text.text( files[0].name + ( files.length > 1 ? ' (+' + ( files.length - 1 ) + ')' : '' ) );
				$zone.addClass( 'has-file' );
			} else {
				$text.text( $text.data( 'placeholder' ) || '' );
				$zone.removeClass( 'has-file' );
			}
		} );
		$( document ).on( 'dragover dragenter', '.shapeforms-lite-form__file', function ( e ) {
			e.preventDefault();
			$( this ).addClass( 'is-dragover' );
		} );
		$( document ).on( 'dragleave dragend', '.shapeforms-lite-form__file', function () {
			$( this ).removeClass( 'is-dragover' );
		} );
		$( document ).on( 'drop', '.shapeforms-lite-form__file', function ( e ) {
			e.preventDefault();
			$( this ).removeClass( 'is-dragover' );

			var dt    = e.originalEvent && e.originalEvent.dataTransfer;
			var input = this.querySelector( '.shapeforms-lite-form__file-input' );

			if ( input && dt && dt.files && dt.files.length ) {
				input.files = dt.files;
				input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}
		} );

		// ── Live email validation ──
		$( document ).on( 'blur', '.shapeforms-lite-form input[type="email"]', function () {
			var $input   = $( this );
			var $wrapper = $input.closest( '.shapeforms-lite-form__field' );
			var val      = $.trim( $input.val() || '' );

			// Clear any previous email error on this field (incl. its ARIA association).
			clearFieldErrorState( $wrapper );
			$wrapper.find( '.shapeforms-lite-form__field-error' ).remove();
			$wrapper.removeClass( 'is-invalid' );

			if ( val && ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( val ) ) {
				showFieldError( $wrapper, shapeformsLiteFrontend.invalidEmail || 'Please enter a valid email address.' );
			}
		} );

		// Clear a field's error (and its ARIA association) as soon as the user edits
		// it, so a corrected field no longer announces as invalid before re-submit.
		$( document ).on( 'input change', '.shapeforms-lite-form__field.is-invalid :input', function () {
			var $wrapper = $( this ).closest( '.shapeforms-lite-form__field.is-invalid' );
			if ( ! $wrapper.length ) {
				return;
			}
			clearFieldErrorState( $wrapper );
			$wrapper.find( '.shapeforms-lite-form__field-error' ).remove();
			$wrapper.removeClass( 'is-invalid' );
		} );

		// ── Flatpickr date/time pickers ──
		// Scoped and re-runnable, and exposed as window.shapeformsInitPickers, so markup
		// added after load — a repeater row, a step revealed by the multi-page module —
		// gets real pickers instead of a dead readonly box. Already-upgraded inputs are
		// skipped: flatpickr stores its instance on the element as _flatpickr.
		function initShapeformsPickers( $scope ) {
			if ( typeof flatpickr === 'undefined' ) {
				return;
			}

			var $container = $scope && $scope.length ? $scope : $( document );

			$container.find( 'input[data-shapeforms-picker="date"]' ).addBack( 'input[data-shapeforms-picker="date"]' ).each( function () {
				if ( this._flatpickr ) { return; }
				flatpickr( this, {
					dateFormat: 'Y-m-d',
					altInput: true,
					altFormat: 'F j, Y',
					allowInput: false,
					disableMobile: true
				} );
			} );

			$container.find( 'input[data-shapeforms-picker="time"]' ).addBack( 'input[data-shapeforms-picker="time"]' ).each( function () {
				if ( this._flatpickr ) { return; }
				flatpickr( this, {
					enableTime: true,
					noCalendar: true,
					dateFormat: 'H:i',
					altInput: true,
					altFormat: 'h:i K',
					allowInput: false,
					disableMobile: true,
					minuteIncrement: 15
				} );
			} );
		}

		window.shapeformsInitPickers = initShapeformsPickers;

		initShapeformsPickers( $( document ) );

		/**
		 * Finishes a successful submission: redirect or confirmation message,
		 * form reset, and the submit button restored.
		 *
		 * Split out and exposed as window.shapeformsCompleteSubmission so that an
		 * extension which took over the flow (see the shapeforms_form_submitted
		 * event) can finish it without reimplementing any of this. One
		 * implementation means the immediate and deferred paths cannot drift.
		 *
		 * @param {jQuery} $form    The submitted form.
		 * @param {Object} response The server response.
		 * @return {void}
		 */
		function completeSubmission( $form, response ) {
			var $message = $form.find( '[data-shapeforms-message]' );
			var $submit = $form.find( '.shapeforms-lite-form__submit' );
			var message = response && response.data && response.data.message ? response.data.message : shapeformsLiteFrontend.successText;
			var redirectUrl = response && response.data && response.data.redirectUrl ? response.data.redirectUrl : '';

			// Fallback: read redirect URL from form data attribute.
			if ( ! redirectUrl ) {
				redirectUrl = $form.data( 'redirect-url' ) || '';
			}

			if ( redirectUrl ) {
				window.location.href = redirectUrl;
				return;
			}

			// The thank-you message is authored in a rich editor, so it is
			// inserted as markup. It is server-authored and filtered with
			// the post allowlist on every read, so it carries no script or
			// event handlers. The error branch stays .text() -- those
			// messages can quote submitted values back at the visitor.
			$message
				.addClass( 'is-visible is-success' )
				.html( message );

			$form.trigger( 'reset' );

			// Restore the button. On the immediate path .always() has already
			// done this; on the deferred path this is the only thing that does.
			$submit.prop( 'disabled', false );

			if ( $submit.data( 'shapeformsLabel' ) ) {
				$submit.text( $submit.data( 'shapeformsLabel' ) );
			}

			// A native reset reverts inputs to their HTML defaults but
			// leaves custom widgets (e.g. Pro calculation/payment
			// displays, signature pads, date-range/NPS visuals,
			// image-choice tiles, extra repeater rows) showing their
			// stale state. Announce the reset so those widgets can
			// re-sync. Lite stays Pro-agnostic — it only fires the
			// event; each field type handles its own re-sync.
			$( document ).trigger( 'shapeforms_form_reset', [ $form ] );
		}

		window.shapeformsCompleteSubmission = completeSubmission;

		$( document ).on(
			'submit',
			'.shapeforms-lite-form',
			function ( event ) {
				var $form = $( this );

				// Inside a builder editor preview (Gutenberg/Elementor) the form is
				// shown live for styling only — never submit it, which would create a
				// real entry straight from the editor.
				if ( $form.is( '[data-shapeforms-preview]' ) ) {
					event.preventDefault();
					return;
				}

				var $message = $form.find( '[data-shapeforms-message]' );

				// The validation summary lives beside the button, not above the
				// form. Falls back to the top element so a form rendered by an
				// older cached template still reports the failure somewhere.
				var $messageFoot = $form.find( '[data-shapeforms-message-foot]' );
				if ( ! $messageFoot.length ) {
					$messageFoot = $message;
				}

				var $submit = $form.find( '.shapeforms-lite-form__submit' );
				var submitText = $submit.text();
				var enableAjax = '1' === String( $form.data( 'enable-ajax' ) || '0' );

				clearFieldErrors( $form );
				clearFootMessage( $form );

				if ( ! enableAjax ) {
					// Non-AJAX: validate client-side, allow native submit if valid.
					if ( ! validateClientSide( $form ) ) {
						event.preventDefault();
						$messageFoot.addClass( 'is-visible is-error' ).text( shapeformsLiteFrontend.errorText );
						focusFirstInvalid( $form );
					}
					return;
				}

				event.preventDefault();

				if ( ! validateClientSide( $form ) ) {
					$messageFoot
						.addClass( 'is-visible is-error' )
						.text( shapeformsLiteFrontend.errorText );
					focusFirstInvalid( $form );
					return;
				}

				$message
					.removeClass( 'is-visible is-success is-error' )
					.text( shapeformsLiteFrontend.submittingText );

				// Remembered on the element so completeSubmission() can restore the
				// label on the deferred path, where .always() stands down.
				$submit.data( 'shapeformsLabel', submitText );
				$submit.prop( 'disabled', true ).text( shapeformsLiteFrontend.submittingText );

				$.ajax( {
					url: shapeformsLiteFrontend.ajaxUrl,
					type: 'POST',
					data: new FormData( $form[0] ),
					processData: false,
					contentType: false
				} )
					.done(
						function ( response ) {
							// Announce the response before acting on it. A submission is
							// not always final at this point -- an extension may still have
							// work to do that decides the outcome (Pro's payment module
							// saves the entry as pending, then takes the payment). Calling
							// preventDefault() on this event hands the rest of the flow --
							// confirmation, redirect, reset, and the button state -- to
							// that listener, which finishes by calling
							// window.shapeformsCompleteSubmission().
							//
							// Lite stays extension-agnostic: it only announces and offers
							// the completion routine, and knows nothing about what the
							// listener is waiting for.
							var submitted = $.Event( 'shapeforms_form_submitted' );
							$( document ).trigger( submitted, [ $form, response ] );

							if ( submitted.isDefaultPrevented() ) {
								// Suppresses the .always() button reset below: the listener
								// owns the button until it completes or fails.
								$form.data( 'shapeformsSubmitHeld', true );
								return;
							}

							completeSubmission( $form, response );
						}
					)
					.fail(
						function ( xhr ) {
							var response = xhr && xhr.responseJSON ? xhr.responseJSON : null;
							var message = response && response.data && response.data.message ? response.data.message : shapeformsLiteFrontend.errorText;
							var errors = response && response.data && response.data.errors ? response.data.errors : null;

							// Foot, for the same reason the client-side summary goes
							// there: it answers the button just pressed, and it
							// arrives alongside the per-field messages this rejection
							// puts on the fields themselves. Success keeps the top.
							$messageFoot
								.addClass( 'is-visible is-error' )
								.text( message );

							showFieldErrors( $form, errors );

							// Counterpart to shapeforms_form_submitted. A listener waiting on
							// the outcome of this submission needs to hear about a rejection
							// too, or it waits forever for a success that is not coming.
							$( document ).trigger( 'shapeforms_form_failed', [ $form, response ] );
						}
					)
					.always(
						function () {
							// A listener that took over the submission keeps the button
							// disabled while it works -- re-enabling here would invite a
							// second submit in the middle of, say, a card confirmation.
							if ( $form.data( 'shapeformsSubmitHeld' ) ) {
								$form.removeData( 'shapeformsSubmitHeld' );
								return;
							}

							$submit.prop( 'disabled', false ).text( submitText );
						}
					);
			}
		);

		// Conditional Logic — rule engine.
		( function () {
			/**
			 * Read the current value of a form field by name.
			 *
			 * @param {jQuery} $form
			 * @param {string} fieldName   e.g. "shapeforms_field_abc"
			 * @return {string}
			 */
			function getFieldVal( $form, fieldName ) {
				var $el = $form.find( '[name="' + fieldName + '"], [name="' + fieldName + '[]"]' );
				if ( ! $el.length ) return '';
				if ( $el.is( ':checkbox' ) ) {
					var vals = [];
					$el.filter( ':checked' ).each( function () { vals.push( $( this ).val() ); } );
					return vals.join( ', ' );
				}
				if ( $el.is( ':radio' ) ) {
					return $el.filter( ':checked' ).val() || '';
				}
				return $el.val() || '';
			}

			/**
			 * Mirrors PHP is_numeric() closely enough for conditional-logic parity: a
			 * non-empty string that coerces to a finite number. Rejects values like
			 * "12abc" that parseFloat() alone would accept as 12.
			 *
			 * @param {string} s
			 * @return {boolean}
			 */
			function bfIsNumeric( s ) {
				return '' !== s && ! isNaN( s ) && ! isNaN( parseFloat( s ) );
			}

			/**
			 * Test one condition against the current form state. Operator semantics are
			 * kept byte-for-byte in sync with the PHP evaluate_condition() server gate.
			 *
			 * @param {jQuery} $form
			 * @param {{field_id:string, operator:string, value:string}} cond
			 * @return {boolean}
			 */
			function testCondition( $form, cond ) {
				var raw = String( getFieldVal( $form, cond.field_id ) );
				var cv  = String( cond.value || '' );
				switch ( cond.operator ) {
					case 'is':           return raw === cv;
					case 'is_not':       return raw !== cv;
					case 'contains':     return raw.toLowerCase().indexOf( cv.toLowerCase() ) !== -1;
					case 'not_contains': return raw.toLowerCase().indexOf( cv.toLowerCase() ) === -1;
					case 'starts_with':  return raw.toLowerCase().indexOf( cv.toLowerCase() ) === 0;
					// Match PHP: empty needle is never an "ends_with" match, and require
					// raw to be at least as long as the value before comparing the tail.
					case 'ends_with':    return '' !== cv && raw.length >= cv.length && raw.toLowerCase().slice( -cv.length ) === cv.toLowerCase();
					// Match PHP: both sides must be numeric (is_numeric), else false.
					case 'greater_than': return bfIsNumeric( raw ) && bfIsNumeric( cv ) && parseFloat( raw ) > parseFloat( cv );
					case 'less_than':    return bfIsNumeric( raw ) && bfIsNumeric( cv ) && parseFloat( raw ) < parseFloat( cv );
					case 'not_empty':    return raw.length > 0;
					case 'empty':        return raw.length === 0;
					default:             return false;
				}
			}

			// ── Multi-condition engine ─────────────────────────────────────────
			$( '.shapeforms-lite-form__field[data-bf-conditions]' ).each( function () {
				var $self  = $( this );
				var $form  = $self.closest( 'form' );
				var ruleset;
				try { ruleset = JSON.parse( $self.attr( 'data-bf-conditions' ) ); } catch ( e ) { return; }
				if ( ! ruleset || ! ruleset.conditions ) return;

				var conditions = ruleset.conditions;
				var action     = ruleset.action === 'hide' ? 'hide' : 'show';
				var logic      = ruleset.logic === 'OR' ? 'OR' : 'AND';

				// Collect watched field names.
				var watched = {};
				conditions.forEach( function ( c ) { if ( c.field_id ) watched[ c.field_id ] = true; } );

				function evaluate() {
					var results = conditions.map( function ( c ) { return testCondition( $form, c ); } );
					var conditionMet = logic === 'OR'
						? results.some( Boolean )
						: results.every( Boolean );

					var visible = action === 'show' ? conditionMet : ! conditionMet;
					$self.toggle( visible );
					$self.find( '[required]' ).prop( 'disabled', ! visible );
				}

				Object.keys( watched ).forEach( function ( fieldName ) {
					$form.on( 'input change', '[name="' + fieldName + '"], [name="' + fieldName + '[]"]', evaluate );
				} );
				evaluate();
			} );

			// ── Legacy single-rule fallback (data-cond-field) ──────────────────
			$( '.shapeforms-lite-form__field[data-cond-field]' ).each( function () {
				var $target   = $( this );
				var action    = $target.data( 'cond-action' ) || 'show';
				var fieldName = String( $target.data( 'cond-field' ) || '' );
				var operator  = $target.data( 'cond-operator' ) || 'is';
				var condValue = String( $target.data( 'cond-value' ) || '' );
				var $form     = $target.closest( 'form' );

				function legacyEval() {
					var cond = { field_id: fieldName, operator: operator, value: condValue };
					var match = testCondition( $form, cond );
					var visible = action === 'show' ? match : ! match;
					$target.toggle( visible );
					$target.find( '[required]' ).prop( 'disabled', ! visible );
				}

				$form.on( 'input change', '[name="' + fieldName + '"], [name="' + fieldName + '[]"]', legacyEval );
				legacyEval();
			} );
		}() );

		// Star Rating.
		$( '.shapeforms-lite-star-rating' ).each( function () {
			var $rating = $( this );
			var $field = $( '#' + $rating.data( 'field' ) );
			var $stars = $rating.find( '.shapeforms-lite-star' );
			var maxStars = $stars.length;
			// The rating to restore on a form reset (after a successful submit). Captured
			// at init so the reset doesn't depend on the browser having cleared the hidden
			// input — selecting a star sets the value property, which a native reset does
			// not always revert in time for our handler.
			var defaultVal = parseInt( $field.val(), 10 ) || 0;

			// Paint the cumulative fill for a given value.
			function paintStars( val ) {
				$stars.each( function () {
					$( this ).toggleClass( 'is-active', $( this ).data( 'value' ) <= val );
				} );
			}

			// Commit a rating: update the hidden field + visuals + ARIA/roving state.
			// Shared by mouse and keyboard so both paths behave identically.
			function selectStar( val ) {
				val = Math.max( 0, Math.min( maxStars, parseInt( val, 10 ) || 0 ) );
				$field.val( val ).trigger( 'change' );
				paintStars( val );
				$stars.each( function () {
					var v = $( this ).data( 'value' );
					$( this ).attr( 'aria-checked', v === val ? 'true' : 'false' );
					// Roving tabindex: keep exactly one star in the tab order.
					$( this ).attr( 'tabindex', ( v === val || ( 0 === val && 1 === v ) ) ? '0' : '-1' );
				} );
			}

			$stars.on( 'mouseenter', function () {
				var val = $( this ).data( 'value' );
				$stars.each( function () {
					$( this ).toggleClass( 'is-hover', $( this ).data( 'value' ) <= val );
				} );
			} );

			$rating.on( 'mouseleave', function () {
				$stars.removeClass( 'is-hover' );
			} );

			$stars.on( 'click', function () {
				selectStar( $( this ).data( 'value' ) );
				this.focus();
			} );

			// Keyboard support (WCAG 2.1.1): Arrow keys move & select, Home/End jump,
			// Space/Enter select the focused star.
			$stars.on( 'keydown', function ( e ) {
				var current = parseInt( $field.val(), 10 ) || 0;
				var next;
				switch ( e.key ) {
					case 'ArrowRight':
					case 'ArrowUp':
						next = Math.min( maxStars, current + 1 );
						break;
					case 'ArrowLeft':
					case 'ArrowDown':
						next = Math.max( 0, current - 1 );
						break;
					case 'Home':
						next = 1;
						break;
					case 'End':
						next = maxStars;
						break;
					case ' ':
					case 'Spacebar':
					case 'Enter':
						next = $( this ).data( 'value' );
						break;
					default:
						return;
				}
				e.preventDefault();
				selectStar( next );
				// Focus the star that is now tab-focusable.
				var focusVal = next > 0 ? next : 1;
				$stars.filter( function () {
					return $( this ).data( 'value' ) === focusVal;
				} ).trigger( 'focus' );
			} );

			// Re-sync the star visuals + ARIA when the parent form is reset (e.g.
			// after a successful AJAX submission); a native reset clears the hidden
			// input but not this custom layer. Deferred so the reset applies first.
			$rating.closest( 'form' ).on( 'reset', function () {
				window.setTimeout( function () {
					// Explicitly restore the captured default instead of reading the hidden
					// input, so the visuals clear even if the native reset hasn't reverted it.
					$field.val( defaultVal );
					paintStars( defaultVal );
					$stars.each( function () {
						var v = $( this ).data( 'value' );
						$( this ).attr( 'aria-checked', v === defaultVal ? 'true' : 'false' );
						$( this ).attr( 'tabindex', ( v === defaultVal || ( 0 === defaultVal && 1 === v ) ) ? '0' : '-1' );
					} );
				}, 0 );
			} );
		} );

		// Slider Range value display (single handle).
		$( '.shapeforms-lite-slider:not(.shapeforms-lite-slider--dual) input[type="range"]' ).on( 'input', function () {
			$( this ).closest( '.shapeforms-lite-slider' ).find( '.shapeforms-lite-slider__value' ).text( $( this ).val() );
		} );

		// Slider Range — dual handle (min + max).
		/**
		 * Wires up a dual-handle (min/max) range slider: keeps the two thumbs from
		 * crossing, updates the visible value label, and writes the combined
		 * "lo - hi" value into the field's hidden input on change.
		 *
		 * @param {HTMLElement} el The slider container element.
		 * @return {void}
		 */
		function initDualSlider( el ) {
			var $w = $( el );
			if ( $w.data( 'bfDualReady' ) ) {
				return;
			}
			var $min    = $w.find( '.shapeforms-lite-slider__input--min' );
			var $max    = $w.find( '.shapeforms-lite-slider__input--max' );
			var $fill   = $w.find( '.shapeforms-lite-slider__fill' );
			var $val    = $w.find( '.shapeforms-lite-slider__value' );
			var $hidden = $w.find( 'input[type="hidden"]' );
			var $track  = $w.find( '.shapeforms-lite-slider__track' );
			if ( ! $min.length || ! $max.length ) {
				return;
			}
			$w.data( 'bfDualReady', true );

			var rMin = parseFloat( $min.attr( 'min' ) );
			var rMax = parseFloat( $min.attr( 'max' ) );
			var span = ( rMax - rMin ) || 1;

			function update() {
				var lo = parseFloat( $min.val() );
				var hi = parseFloat( $max.val() );
				if ( lo > hi ) {
					var t = lo; lo = hi; hi = t;
				}
				$fill.css( { left: ( ( lo - rMin ) / span * 100 ) + '%', width: ( ( hi - lo ) / span * 100 ) + '%' } );
				$val.text( lo + ' – ' + hi );
				$hidden.val( lo + ' - ' + hi );
			}

			// Keep the thumbs from crossing.
			$min.on( 'input', function () {
				if ( parseFloat( $min.val() ) > parseFloat( $max.val() ) ) {
					$min.val( $max.val() );
				}
				update();
			} );
			$max.on( 'input', function () {
				if ( parseFloat( $max.val() ) < parseFloat( $min.val() ) ) {
					$max.val( $min.val() );
				}
				update();
			} );

			// Raise whichever thumb is nearest the pointer so overlapping thumbs stay grabbable.
			$track.on( 'pointerdown', function ( e ) {
				var rect  = this.getBoundingClientRect();
				var pct   = ( e.clientX - rect.left ) / rect.width * 100;
				var loPct = ( parseFloat( $min.val() ) - rMin ) / span * 100;
				var hiPct = ( parseFloat( $max.val() ) - rMin ) / span * 100;
				var minOnTop = Math.abs( pct - loPct ) <= Math.abs( pct - hiPct );
				$min.css( 'z-index', minOnTop ? 5 : 4 );
				$max.css( 'z-index', minOnTop ? 4 : 5 );
			} );

			update();
		}

		$( '.shapeforms-lite-slider--dual' ).each( function () {
			initDualSlider( this );
		} );

		// The block (ServerSideRender) and Elementor editors — plus popups/AJAX —
		// inject the slider markup after load, so DOM-ready init misses it. Watch
		// for sliders added later and initialise them so the fill tracks the handles
		// there too (otherwise the server-rendered fill never updates on drag).
		if ( window.MutationObserver ) {
			// Debounced so a burst of DOM mutations triggers at most one rescan per
			// 200ms instead of running querySelectorAll on every mutation batch.
			// initDualSlider is idempotent (bfDualReady guard), so re-scanning every
			// dual slider only initialises the newly-added ones.
			var dualObserverTimer = null;
			var dualObserver = new MutationObserver( function () {
				if ( dualObserverTimer ) {
					return;
				}
				dualObserverTimer = setTimeout( function () {
					dualObserverTimer = null;
					$( '.shapeforms-lite-slider--dual' ).each( function () {
						initDualSlider( this );
					} );
				}, 200 );
			} );
			dualObserver.observe( document.body, { childList: true, subtree: true } );
		}

		// Input Mask.
		$( 'input[data-mask]' ).each( function () {
			var $input = $( this );
			var mask = $input.data( 'mask' );
			if ( ! mask ) return;

			$input.on( 'input', function () {
				var raw = $input.val().replace( /[^\dA-Za-z]/g, '' );
				var result = '';
				var ri = 0;

				for ( var mi = 0; mi < mask.length && ri < raw.length; mi++ ) {
					var mc = mask[ mi ];
					if ( mc === '9' ) {
						if ( /\d/.test( raw[ ri ] ) ) { result += raw[ ri ]; ri++; } else { ri++; mi--; }
					} else if ( mc === 'A' ) {
						if ( /[A-Za-z]/.test( raw[ ri ] ) ) { result += raw[ ri ]; ri++; } else { ri++; mi--; }
					} else if ( mc === '*' ) {
						result += raw[ ri ]; ri++;
					} else {
						result += mc;
						if ( raw[ ri ] === mc ) ri++;
					}
				}
				$input.val( result );
			} );
		} );

		// Custom select & multiselect dropdown.
		// The .bf-select HTML is rendered by PHP. JS only attaches behaviour.
		function initShapeformsSelects( $scope ) {
			var $container = $scope || $( document );
			$container.find( '.bf-select[data-shapeforms-custom-select]' ).each( function () {
				if ( $( this ).data( 'bf-select-init' ) ) return;
				$( this ).data( 'bf-select-init', true );
				bindSelectBehaviour( $( this ) );
			} );
		}

		/**
		 * Binds the custom select UI to its hidden native <select>: open/close the
		 * panel, render single/multi selections, optional search filtering, and
		 * mirror every choice back to the native control so the form submits the
		 * real value. Keeps the visible trigger and the native value in sync.
		 *
		 * @param {jQuery} $wrap The .bf-select wrapper element.
		 * @return {void}
		 */
		function bindSelectBehaviour( $wrap ) {
			var $select     = $wrap.prev( 'select[data-shapeforms-select]' );
			var $trigger    = $wrap.find( '.bf-select__trigger' );
			var $panel      = $wrap.find( '.bf-select__panel' );
			var $list       = $wrap.find( '.bf-select__list' );
			var $search     = $wrap.find( '.bf-select__panel-search' );
			var isMultiple  = $wrap.data( 'multiple' ) === 1 || $wrap.data( 'multiple' ) === '1';
			var isSearchable = $search.length > 0;

			var options = [];
			var selected = [];
			var placeholderText = '';

			$select.find( 'option' ).each( function () {
				var val = $( this ).val();
				if ( val === '' ) {
					placeholderText = $( this ).text();
				} else {
					options.push( { value: val, text: $( this ).text() } );
					if ( $( this ).prop( 'selected' ) ) selected.push( val );
				}
			} );

			// Snapshot the initial selection so the custom UI can be restored when the
			// form is reset (a native reset reverts the hidden <select> to this state).
			var defaultSelected = selected.slice();

			if ( ! placeholderText ) {
				placeholderText = isMultiple ? 'Select options\u2026' : 'Select\u2026';
			}

			var esc = function ( str ) { return $( '<span>' ).text( str ).html(); };
			var checkSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';

			// Index of the keyboard-highlighted option (within the currently rendered
			// list) and a stable id for the listbox so we can wire aria-activedescendant.
			var activeIndex = -1;
			var listId = $list.attr( 'id' );
			if ( ! listId ) {
				listId = 'bf-select-list-' + Math.floor( Math.random() * 1e9 );
				$list.attr( 'id', listId );
			}

			function findOpt( val ) {
				for ( var i = 0; i < options.length; i++ ) {
					if ( options[ i ].value === val ) return options[ i ];
				}
				return null;
			}

			function syncToSelect() {
				$select.find( 'option' ).each( function () {
					var v = $( this ).val();
					if ( v === '' ) return;
					$( this ).prop( 'selected', selected.indexOf( v ) !== -1 );
				} );
				$select.trigger( 'change' );
			}

			function renderTrigger() {
				var arrow = '<span class="bf-select__arrow"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></span>';

				if ( isMultiple ) {
					if ( ! selected.length ) {
						$trigger.html( '<span class="bf-select__placeholder">' + esc( placeholderText ) + '</span>' + arrow );
					} else {
						var html = '<span class="bf-select__tags">';
						selected.forEach( function ( v ) {
							var o = findOpt( v );
							if ( o ) {
								html += '<span class="bf-select__tag">' + esc( o.text ) + '<button type="button" class="bf-select__tag-x" data-val="' + esc( v ) + '" aria-label="Remove">&times;</button></span>';
							}
						} );
						html += '</span>' + arrow;
						$trigger.html( html );
					}
				} else {
					var o = selected.length ? findOpt( selected[ 0 ] ) : null;
					if ( o ) {
						$trigger.html( '<span class="bf-select__value">' + esc( o.text ) + '</span>' + arrow );
					} else {
						$trigger.html( '<span class="bf-select__placeholder">' + esc( placeholderText ) + '</span>' + arrow );
					}
				}
			}

			function renderList( filter ) {
				var q = ( filter || '' ).toLowerCase();
				var html = '';

				var visibleIdx = 0;
				options.forEach( function ( opt ) {
					if ( q && opt.text.toLowerCase().indexOf( q ) === -1 ) return;
					var active = selected.indexOf( opt.value ) !== -1;
					html += '<div class="bf-select__option' + ( active ? ' is-active' : '' ) + '" id="' + listId + '-opt-' + visibleIdx + '" role="option" aria-selected="' + active + '" data-val="' + esc( opt.value ) + '">';
					visibleIdx++;
					if ( isMultiple ) {
						html += '<span class="bf-select__check">' + ( active ? checkSvg : '' ) + '</span>';
					}
					html += '<span class="bf-select__option-text">' + esc( opt.text ) + '</span>';
					if ( ! isMultiple && active ) {
						html += '<span class="bf-select__active-mark">' + checkSvg + '</span>';
					}
					html += '</div>';
				} );

				$list.html( html || '<div class="bf-select__empty">No results found</div>' );
			}

			function open() {
				if ( $wrap.hasClass( 'is-open' ) ) return;
				$wrap.addClass( 'is-open' );
				$trigger.attr( 'aria-expanded', 'true' );
				if ( isSearchable ) {
					$search.val( '' ).focus();
				}
				renderList();
			}

			function close() {
				$wrap.removeClass( 'is-open' );
				$trigger.attr( 'aria-expanded', 'false' );
				$list.find( '.bf-select__option' ).removeClass( 'is-keyboard-active' );
				$trigger.removeAttr( 'aria-activedescendant' );
				if ( isSearchable ) {
					$search.removeAttr( 'aria-activedescendant' );
				}
				activeIndex = -1;
			}

			// Highlight option `idx` (clamped) for keyboard users and expose it via
			// aria-activedescendant so screen readers announce the focused option.
			function setActive( idx ) {
				var $opts = $list.find( '.bf-select__option' );
				$opts.removeClass( 'is-keyboard-active' );
				if ( ! $opts.length ) {
					activeIndex = -1;
					$trigger.removeAttr( 'aria-activedescendant' );
					if ( isSearchable ) {
						$search.removeAttr( 'aria-activedescendant' );
					}
					return;
				}
				idx = Math.max( 0, Math.min( $opts.length - 1, idx ) );
				activeIndex = idx;
				var $active = $opts.eq( idx ).addClass( 'is-keyboard-active' );
				var id = $active.attr( 'id' );
				$trigger.attr( 'aria-activedescendant', id );
				if ( isSearchable ) {
					$search.attr( 'aria-activedescendant', id );
				}
				if ( $active[ 0 ] && $active[ 0 ].scrollIntoView ) {
					$active[ 0 ].scrollIntoView( { block: 'nearest' } );
				}
			}

			// Shared listbox keyboard handling for the trigger and the search input.
			// Returns true when the key was handled (so callers can stop further work).
			function navKeydown( e ) {
				var $opts = $list.find( '.bf-select__option' );
				switch ( e.key ) {
					case 'ArrowDown':
						e.preventDefault();
						if ( ! $wrap.hasClass( 'is-open' ) ) {
							open();
							setActive( 0 );
						} else {
							setActive( activeIndex + 1 );
						}
						return true;
					case 'ArrowUp':
						e.preventDefault();
						if ( ! $wrap.hasClass( 'is-open' ) ) {
							open();
							setActive( $opts.length - 1 );
						} else {
							setActive( activeIndex - 1 );
						}
						return true;
					case 'Home':
						if ( $wrap.hasClass( 'is-open' ) ) {
							e.preventDefault();
							setActive( 0 );
							return true;
						}
						return false;
					case 'End':
						if ( $wrap.hasClass( 'is-open' ) ) {
							e.preventDefault();
							setActive( $opts.length - 1 );
							return true;
						}
						return false;
					case 'Enter':
						if ( $wrap.hasClass( 'is-open' ) && activeIndex >= 0 && $opts.eq( activeIndex ).length ) {
							e.preventDefault();
							toggle( $opts.eq( activeIndex ).data( 'val' ) + '' );
							return true;
						}
						return false;
					default:
						return false;
				}
			}

			function toggle( val ) {
				if ( isMultiple ) {
					var idx = selected.indexOf( val );
					if ( idx !== -1 ) {
						selected.splice( idx, 1 );
					} else {
						selected.push( val );
					}
					renderTrigger();
				} else {
					selected = [ val ];
					close();
					renderTrigger();
					// Focus goes back to the combobox, which is what ARIA's
					// listbox pattern asks for and what every native <select>
					// does. Without it a mouse pick leaves focus on <body>: the
					// option element the click landed on is re-rendered away, so
					// there is nothing left to hold it.
					//
					// That is not only a keyboard-navigation nicety. Anything
					// listening for keys on the FORM — conversational mode's
					// "press Enter to continue" among them — stops receiving them
					// entirely once focus has left the form, so the next key the
					// visitor presses goes nowhere at all.
					//
					// Single select only: a multiple keeps its panel open so more
					// can be chosen, and pulling focus out of the list mid-way
					// would undo the choice the visitor is still making.
					$trigger.trigger( 'focus' );
				}
				syncToSelect();
				renderList( isSearchable ? $search.val() : '' );
			}

			// Events.
			$trigger.on( 'click', function ( e ) {
				if ( $( e.target ).closest( '.bf-select__tag-x' ).length ) return;
				$wrap.hasClass( 'is-open' ) ? close() : open();
			} );

			$trigger.on( 'keydown', function ( e ) {
				if ( e.key === 'Escape' ) { close(); return; }
				// Arrow/Home/End/Enter navigation over the options.
				if ( navKeydown( e ) ) { return; }
				// Enter/Space: open when closed, select the highlighted option when open,
				// otherwise close. (Searchable selects move focus to the search box, so
				// Space there types instead of reaching this handler.)
				if ( e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar' ) {
					e.preventDefault();
					var $opts = $list.find( '.bf-select__option' );
					if ( ! $wrap.hasClass( 'is-open' ) ) {
						open();
						setActive( 0 );
					} else if ( activeIndex >= 0 && $opts.eq( activeIndex ).length ) {
						toggle( $opts.eq( activeIndex ).data( 'val' ) + '' );
					} else {
						close();
					}
				}
			} );

			if ( isSearchable ) {
				$search.on( 'input', function () {
					renderList( $( this ).val() );
					// After filtering, highlight the first match for keyboard users.
					setActive( 0 );
				} );

				$search.on( 'keydown', function ( e ) {
					if ( e.key === 'Escape' ) { close(); $trigger.trigger( 'focus' ); return; }
					navKeydown( e );
				} );
			}

			$trigger.on( 'click', '.bf-select__tag-x', function ( e ) {
				e.stopPropagation();
				toggle( $( this ).data( 'val' ) + '' );
			} );

			$list.on( 'click', '.bf-select__option', function ( e ) {
				// Stop the click reaching the global outside-click handler. toggle()
				// re-renders the list, detaching this element, which would otherwise be
				// read as an "outside" click and wrongly close the (multi-select) panel.
				e.stopPropagation();
				toggle( $( this ).data( 'val' ) + '' );
			} );

			// Restore the visible selection when the parent form is reset (e.g. after a
			// successful AJAX submission). A native form reset reverts the hidden
			// <select> but not this custom trigger/tags layer, so re-sync from the
			// initial selection. Deferred so the native reset applies first.
			$wrap.closest( 'form' ).on( 'reset', function () {
				window.setTimeout( function () {
					selected = defaultSelected.slice();
					syncToSelect();
					renderTrigger();
					renderList( '' );
				}, 0 );
			} );

			// Paint the trigger and option list from the JS selection on init so the
			// visible trigger, the dropdown panel, and the hidden <select> always agree.
			// The server-rendered trigger can otherwise lag the real selection, leaving
			// an option checked in the panel but absent from the trigger.
			renderTrigger();
			renderList( '' );
		}

		// Single delegated outside-click handler for ALL custom selects (replaces the
		// per-instance $(document).on('click') that bindSelectBehaviour used to add,
		// which leaked one handler per select). Closes every open select except the one
		// the click landed inside — preserving the "only one open at a time" behaviour.
		$( document ).on( 'click.bfSelectClose', function ( e ) {
			var $inside = $( e.target ).closest( '.bf-select' );
			$( '.bf-select.is-open' ).each( function () {
				if ( this !== $inside[ 0 ] ) {
					var $sel = $( this );
					$sel.removeClass( 'is-open' );
					$sel.find( '.bf-select__trigger' )
						.attr( 'aria-expanded', 'false' )
						.removeAttr( 'aria-activedescendant' );
					$sel.find( '.bf-select__option' ).removeClass( 'is-keyboard-active' );
				}
			} );
		} );

		// Initialize on page load.
		initShapeformsSelects();

		// Expose globally for external callers.
		window.shapeformsInitSelects = initShapeformsSelects;

		// Re-initialize when Elementor editor re-renders a widget.
		function bindElementor() {
			if ( window.elementorFrontend && elementorFrontend.hooks ) {
				elementorFrontend.hooks.addAction( 'frontend/element_ready/shapeforms.default', function ( $scope ) {
					initShapeformsSelects( $scope );
				} );
			}
		}

		if ( window.elementorFrontend ) {
			bindElementor();
		} else {
			$( window ).on( 'elementor/frontend/init', bindElementor );
		}

		// Fallback: observe DOM for .bf-select added dynamically (Elementor/Gutenberg AJAX render).
		// Skip on builder page (no forms to init) and debounce to avoid performance issues.
		if ( typeof MutationObserver !== 'undefined' && ! document.getElementById( 'shapeforms-builder-root' ) ) {
			var bfObserverTimer = null;
			var bfObserver = new MutationObserver( function () {
				if ( bfObserverTimer ) return;
				bfObserverTimer = setTimeout( function () {
					bfObserverTimer = null;
					initShapeformsSelects();
				}, 200 );
			} );
			bfObserver.observe( document.body, { childList: true, subtree: true } );
		}
	}
);
