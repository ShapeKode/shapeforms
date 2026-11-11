/**
 * ShapeForms — conversational mode engine.
 *
 * Presents an already-rendered form one screen at a time. It never re-renders,
 * re-orders away, or removes anything: every field stays in the DOM, which is
 * what keeps conditional logic, validation, the honeypot, the nonce and the
 * submit path working untouched — and what lets field types from other plugins
 * work here with no change on their side.
 *
 * FRAMEWORK-NEUTRAL BY DESIGN. The engine below uses only querySelector,
 * classList, addEventListener and dataset. jQuery appears exactly once, at the
 * bottom, to observe `shapeforms_form_reset` — a jQuery custom event, which does
 * not dispatch as a native DOM event and so cannot be heard any other way.
 * That listener is the whole port cost if the frontend ever drops jQuery.
 */
( function () {
	'use strict';

	/** Rows are direct children of this element, so screens need no parsing. */
	var FIELDS = '.shapeforms-lite-form__fields';
	var ROW    = '.shapeforms-lite-form__row';
	var COLUMN = '.shapeforms-lite-form__column';
	var FIELD  = '.shapeforms-lite-form__field';
	var MEDIA  = '.shapeforms-lite-form__row-media';

	/** Ids for inline error messages, so each can be referenced by aria-describedby. */
	var errorSeq = 0;

	/**
	 * Controls that own the arrow keys for their own value. Binding screen
	 * navigation to arrows while focus is inside one of these would steal the
	 * key from the control.
	 */
	function ownsArrowKeys( el ) {
		if ( ! el ) {
			return false;
		}
		var tag = ( el.tagName || '' ).toLowerCase();
		if ( 'select' === tag || 'textarea' === tag ) {
			return true;
		}
		if ( 'input' === tag ) {
			var type = ( el.type || '' ).toLowerCase();
			if ( [ 'number', 'range', 'date', 'time', 'datetime-local', 'month', 'week', 'radio', 'checkbox' ].indexOf( type ) !== -1 ) {
				return true;
			}
		}
		// Lite's custom select widget moves through options with the arrows.
		return !! el.closest( '.bf-select' );
	}

	/**
	 * Whether an element is a real, reachable control.
	 *
	 * Deliberately excludes hidden inputs: they carry values but are not
	 * questions, which is what distinguishes a hidden-field row (skip it) from a
	 * question row (show it).
	 */
	function isFocusableControl( el ) {
		var tag = ( el.tagName || '' ).toLowerCase();
		if ( 'input' === tag ) {
			return 'hidden' !== ( el.type || '' ).toLowerCase();
		}
		if ( [ 'select', 'textarea', 'button' ].indexOf( tag ) !== -1 ) {
			// The native <select> behind Lite's custom widget is display:none and
			// is not the thing the visitor interacts with, but it still counts as
			// a control — its widget sibling is what receives focus.
			return true;
		}
		return el.hasAttribute( 'tabindex' ) && '-1' !== el.getAttribute( 'tabindex' );
	}

	/** Direct children matching a selector — never descendants. */
	function childrenMatching( parent, selector ) {
		var out = [];
		for ( var i = 0; i < parent.children.length; i++ ) {
			var child = parent.children[ i ];
			if ( child.matches && child.matches( selector ) ) {
				out.push( child );
			}
		}
		return out;
	}

	/**
	 * Splits a single-column row holding several fields into one row per field.
	 *
	 * A row is the unit of layout, not the unit of conversation. A row with more
	 * than one column is left whole, because side-by-side columns are a design
	 * the author made on purpose — First / Last name belong on one screen. A
	 * single column stacking several fields carries no such intent: that is
	 * simply what the builder produces when fields are dropped one after
	 * another, and it is the shape almost every form starts in. Treating it as
	 * one screen is what made a three-question form present as an ordinary form.
	 *
	 * Fields are MOVED, never cloned. Lite's conditional-logic evaluator binds to
	 * these exact nodes; a clone would leave it toggling a copy that is no longer
	 * on the page, silently breaking every rule on the form.
	 *
	 * There is no opt-out. A "Put every field on its own screen" switch used to
	 * override the paragraph above and split multi-column rows too; it was
	 * removed, so a row the author built as a pair is always presented as one.
	 *
	 * Returns the rows that replaced the original, in document order.
	 */
	/**
	 * Gives a screen the background image its own field carries.
	 *
	 * A no-op when the field has none, which leaves whatever the screen
	 * inherited from its row in place — the fallback that keeps forms built
	 * before per-field images looking unchanged.
	 *
	 * The LAYOUT lives on the screen, not on the figure: the CSS positions the
	 * figure against the row box, so a per-field image has to move the row's
	 * classes and custom properties too, not just swap the picture.
	 */
	function adoptFieldMedia( screen, figure ) {
		if ( ! figure ) {
			return;
		}

		// A screen shows one image. Drop whatever it inherited first.
		var existing = childrenMatching( screen, MEDIA );
		for ( var i = 0; i < existing.length; i++ ) {
			existing[ i ].parentNode.removeChild( existing[ i ] );
		}

		var stale = [];
		for ( var c = 0; c < screen.classList.length; c++ ) {
			if ( 0 === screen.classList[ c ].indexOf( 'shapeforms-lite-form__row--media' ) ) {
				stale.push( screen.classList[ c ] );
			}
		}
		stale.forEach( function ( name ) {
			screen.classList.remove( name );
		} );

		( figure.getAttribute( 'data-bf-media-class' ) || '' ).split( /\s+/ ).forEach( function ( name ) {
			if ( name ) {
				screen.classList.add( name );
			}
		} );

		// setProperty, not a cssText append: these are custom properties, and
		// the screen may already carry the row's values for them.
		( figure.getAttribute( 'data-bf-media-style' ) || '' ).split( ';' ).forEach( function ( decl ) {
			var at = decl.indexOf( ':' );
			if ( at > 0 ) {
				screen.style.setProperty( decl.slice( 0, at ).trim(), decl.slice( at + 1 ).trim() );
			}
		} );

		figure.removeAttribute( 'hidden' );
		figure.classList.remove( 'shapeforms-lite-form__row-media--field' );
		figure.removeAttribute( 'data-bf-media-for' );
		screen.insertBefore( figure, screen.firstChild );
	}

	/**
	 * Moves a field's colour overrides onto the screen that field owns.
	 *
	 * They are rendered as a data attribute on the field rather than as its own
	 * inline style, because a screen background has to paint the whole screen
	 * and the nav buttons live outside the question box. Put on the screen, all
	 * six behave the same way — which is the point of using one mechanism.
	 *
	 * Applied per property, not by overwriting the style attribute: the screen
	 * may already carry its row's values, and the field's must win over exactly
	 * the ones it sets.
	 */
	function adoptFieldColours( screen, field ) {
		if ( ! screen || ! field ) {
			return;
		}

		var style = field.getAttribute( 'data-bf-screen-style' );

		if ( ! style ) {
			return;
		}

		style.split( ';' ).forEach( function ( decl ) {
			var at = decl.indexOf( ':' );

			if ( at > 0 ) {
				screen.style.setProperty( decl.slice( 0, at ).trim(), decl.slice( at + 1 ).trim() );
			}
		} );
	}

	function splitRow( row ) {
		var columns = childrenMatching( row, COLUMN );

		if ( ! columns.length || 1 !== columns.length ) {
			// The ROW is the screen, so the row's own image describes it and any
			// per-field images it holds are not this screen's to use.
			return [ row ];
		}

		// One column, read in document order.
		var fields = [];
		for ( var c = 0; c < columns.length; c++ ) {
			fields = fields.concat( childrenMatching( columns[ c ], FIELD ) );
		}

		if ( ! fields.length ) {
			return [ row ];
		}

		// Media is DISTRIBUTED, not copied.
		//
		// The row may hold several figures: its own, plus one per field that
		// carries an image of its own. Every screen below belongs to exactly one
		// field, so it takes that field's figure when there is one and falls
		// back to the row's when there is not — which is how a form built before
		// per-field images existed keeps looking the way it did.
		var rowMedia = null;
		var byField  = {};
		var figures  = childrenMatching( row, MEDIA );

		for ( var m = 0; m < figures.length; m++ ) {
			var owner = figures[ m ].getAttribute( 'data-bf-media-for' );

			if ( owner ) {
				byField[ owner ] = figures[ m ];
				figures[ m ].parentNode.removeChild( figures[ m ] );
			} else {
				rowMedia = figures[ m ];
			}
		}

		// Flagged even when nothing is split: one field in a single-column row
		// is still THAT FIELD's screen, and the flag is what records whose
		// image wins. A multi-column row returned above without it.
		row.setAttribute( 'data-bf-field-screen', '1' );

		if ( fields.length < 2 ) {
			adoptFieldMedia( row, byField[ fields[ 0 ].getAttribute( 'data-bf-field-id' ) ] );
			adoptFieldColours( row, fields[ 0 ] );

			return [ row ];
		}

		// The first field keeps the original row, so the row's own attributes and
		// anything else the column happens to hold stay exactly where they were.
		var out = [ row ];
		var ref = row;

		for ( var i = 1; i < fields.length; i++ ) {
			var next   = row.cloneNode( false );
			var column = columns[ 0 ].cloneNode( false );

			if ( rowMedia ) {
				next.appendChild( rowMedia.cloneNode( true ) );
			}

			// One question owns the whole screen, so the authored column width is
			// dropped. Keeping it would leave a narrow column rendering as a
			// part-width screen with dead space beside the question.
			column.style.width = '100%';

			column.appendChild( fields[ i ] );
			next.appendChild( column );
			ref.parentNode.insertBefore( next, ref.nextSibling );

			adoptFieldMedia( next, byField[ fields[ i ].getAttribute( 'data-bf-field-id' ) ] );
			adoptFieldColours( next, fields[ i ] );

			out.push( next );
			ref = next;
		}

		// The first field kept the original row, so it is served last — after
		// the loop above has finished reading that row's classes and style to
		// clone from.
		adoptFieldMedia( row, byField[ fields[ 0 ].getAttribute( 'data-bf-field-id' ) ] );
		adoptFieldColours( row, fields[ 0 ] );

		// The original row keeps only its first column, now also full width; the
		// others have been emptied and would otherwise hold open a share of the
		// row that nothing occupies.
		if ( columns.length > 1 ) {
			columns[ 0 ].style.width = '100%';

			for ( var e = 1; e < columns.length; e++ ) {
				if ( columns[ e ].parentNode ) {
					columns[ e ].parentNode.removeChild( columns[ e ] );
				}
			}
		}

		return out;
	}

	/** A row's controls, excluding submit buttons (which are chrome, not questions). */
	function controlsIn( row ) {
		var out  = [];
		var list = row.querySelectorAll( 'input, select, textarea, button, [tabindex]' );
		for ( var i = 0; i < list.length; i++ ) {
			var el = list[ i ];
			if ( isSubmitControl( el ) ) {
				continue;
			}
			if ( isFocusableControl( el ) ) {
				out.push( el );
			}
		}
		return out;
	}

	function isSubmitControl( el ) {
		if ( el.classList && el.classList.contains( 'shapeforms-lite-form__submit' ) ) {
			return true;
		}
		var tag = ( el.tagName || '' ).toLowerCase();
		return ( 'button' === tag || 'input' === tag ) && 'submit' === ( el.type || '' ).toLowerCase();
	}

	/**
	 * Reads the field type Lite encodes in the field wrapper's class list.
	 *
	 * Returns an empty string for anything that is not a field wrapper, which is
	 * why classification never depends on this alone.
	 */
	function fieldTypesIn( row ) {
		var types  = [];
		var fields = row.querySelectorAll( FIELD );
		for ( var i = 0; i < fields.length; i++ ) {
			var classes = fields[ i ].className.split( /\s+/ );
			for ( var j = 0; j < classes.length; j++ ) {
				var m = /^shapeforms-lite-form__field--(.+)$/.exec( classes[ j ] );
				if ( m ) {
					types.push( m[ 1 ] );
				}
			}
		}
		return types;
	}

	/**
	 * Classifies a row by WHAT IT RENDERS, not by what it is called.
	 *
	 * This is why field types this build has never heard of behave correctly: a
	 * hidden field and a stripped page break both render no control and no text,
	 * so both are skipped without Lite knowing either type exists.
	 *
	 *   no control, no text  -> 'static'    (stays in the DOM, still posts)
	 *   no control, has text -> 'statement' (a screen with nothing to answer)
	 *   has a control        -> 'question'
	 */
	function classify( row ) {
		var hasControl = controlsIn( row ).length > 0;
		if ( hasControl ) {
			return 'question';
		}
		return row.textContent.trim().length > 0 ? 'statement' : 'static';
	}

	/**
	 * Whether every field in this row is currently hidden by conditional logic.
	 *
	 * Read from the inline display style, NOT from offset dimensions: the
	 * evaluator hides with an inline style, and everything inside a screen the
	 * engine has hidden measures zero — a visibility test would report the whole
	 * form hidden and skip every screen.
	 */
	function isConditionallyHidden( row ) {
		var fields = row.querySelectorAll( FIELD );
		if ( ! fields.length ) {
			return false;
		}
		for ( var i = 0; i < fields.length; i++ ) {
			if ( 'none' !== fields[ i ].style.display ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * One conversational form.
	 *
	 * @param {HTMLElement} chrome The .shapeforms-cv element emitted by PHP.
	 */
	function Engine( chrome ) {
		this.chrome = chrome;
		this.form   = chrome.closest( 'form' );

		if ( ! this.form ) {
			return;
		}

		this.fields = this.form.querySelector( FIELDS );

		if ( ! this.fields ) {
			return;
		}

		try {
			this.config = JSON.parse( chrome.getAttribute( 'data-shapeforms-cv' ) || '{}' );
		} catch ( e ) {
			this.config = {};
		}

		this.i18n     = this.config.i18n || {};
		this.viewport = chrome.querySelector( '[data-shapeforms-cv-viewport]' );
		this.welcome  = chrome.querySelector( '[data-shapeforms-cv-welcome]' );
		this.nextBtn  = chrome.querySelector( '[data-shapeforms-cv-next]' );
		this.prevBtn  = chrome.querySelector( '[data-shapeforms-cv-prev]' );
		this.live     = chrome.querySelector( '[data-shapeforms-cv-live]' );
		this.message  = this.form.querySelector( '[data-shapeforms-message]' );
		this.index    = 0;

		this.build();

		// The welcome screen counts towards "is there anything to navigate", so
		// it is applied before the single-screen guard below.
		if ( this.welcome ) {
			this.screens.unshift( this.welcome );
			this.welcome.classList.add( 'shapeforms-cv-screen', 'shapeforms-cv-screen--welcome' );
		}

		if ( this.screens.length < 2 ) {
			// Nothing to navigate. Put the form back where it was rendered and
			// stand down, rather than wrapping a single question in dead chrome.
			// Both moves build() made are undone, in reverse order: the actions
			// block was lifted onto the last screen and must go back to sitting
			// after the fields wrapper, not nested inside a row.
			if ( this.actionsOrigin && this.actionsOrigin.parent ) {
				this.actionsOrigin.parent.insertBefore( this.actionsOrigin.node, this.actionsOrigin.before );
			}
			if ( this.origin && this.origin.parent ) {
				this.origin.parent.insertBefore( this.fields, this.origin.before );
			}
			// The `novalidate` build() set is deliberately NOT undone here. Lite
			// turns native validation off on every form of its own accord
			// (frontend.js, "only when JS is loaded"), so removing it would put
			// this form in a state no other Lite form is ever in — and jQuery
			// ready, which runs just after this, would set it straight back.
			this.chrome.classList.add( 'shapeforms-cv--inert' );
			return;
		}

		this.bind();
		this.chrome.classList.add( 'is-ready' );
		this.show( 0, true );
	}

	/**
	 * Moves the questions into the chrome and works out the screen list.
	 *
	 * Fields move INTO the chrome rather than the chrome moving out to them:
	 * the author's colours are inline custom properties on the chrome element,
	 * and custom properties cascade by DOM containment.
	 */
	Engine.prototype.build = function () {
		var self = this;

		// Remembered so a form that turns out to have only one screen can be put
		// back exactly where it was, rather than left sitting inside conversational
		// chrome that will never activate.
		this.origin = { parent: this.fields.parentNode, before: this.fields.nextSibling };

		if ( this.viewport ) {
			this.viewport.appendChild( this.fields );
		}

		// Snapshot the row list before splitting: splitRow() inserts siblings, so
		// walking this.fields.children live would revisit the rows it just made.
		var sourceRows = childrenMatching( this.fields, ROW );
		var rows       = [];

		sourceRows.forEach( function ( row ) {
			rows = rows.concat( splitRow( row ) );
		} );

		var tailTypes  = this.config.tailTypes || [];
		var stackTypes = this.config.stackTypes || [];
		var questions  = [];
		var tailRows   = [];

		rows.forEach( function ( row ) {
			var kind = classify( row );

			if ( 'static' === kind ) {
				// Never a screen, never removed — its value still posts.
				row.classList.add( 'shapeforms-cv-static' );
				return;
			}

			var types  = fieldTypesIn( row );
			var isTail = types.some( function ( t ) {
				return tailTypes.indexOf( t ) !== -1;
			} );

			if ( isTail ) {
				tailRows.push( row );
				return;
			}

			// Stated as a class rather than inferred in CSS: the column count
			// drives both the type scale and the stacking rules, and a class is
			// testable and does not depend on selector-level feature support.
			var columns = row.querySelectorAll( '.shapeforms-lite-form__column' ).length;
			row.classList.add( 'shapeforms-cv-screen--cols-' + ( columns > 3 ? 'many' : columns ) );

			// A row stacks for ONE reason only: it carries a control that
			// genuinely cannot share a line (a signature pad, an address block,
			// a file drop). Column COUNT is deliberately not a reason, and nor
			// is author preference — the switch that used to ask for one field
			// per screen has been removed.
			//
			// It used to be — three or more columns stacked at every width, on
			// the argument that three questions side by side is a form row, not
			// a conversation. That argument is about readability, and it is now
			// made where readability actually lives: the type scale shrinks with
			// the column count, and narrow viewports stack everything. Silently
			// re-laying-out a row the author built as three columns was the
			// wrong tool — the builder shows three, so the form renders three.
			if ( types.some( function ( t ) { return stackTypes.indexOf( t ) !== -1; } ) ) {
				row.classList.add( 'shapeforms-cv-screen--stack' );
			}

			row.classList.add( 'shapeforms-cv-screen' );
			questions.push( row );
		} );

		this.screens = questions;

		// Consent and captcha travel to the end so the visitor is never asked to
		// agree to something halfway through, and never lands on a screen they
		// cannot submit from.
		//
		// They only earn a screen of their own when there is something to answer.
		// The submit button is not a question: on a form with no consent or
		// captcha it joins the last question rather than becoming a dead extra
		// step showing nothing but a button.
		var last = null;

		if ( tailRows.length ) {
			last = document.createElement( 'div' );
			last.className = 'shapeforms-lite-form__row shapeforms-cv-screen shapeforms-cv-screen--tail';

			tailRows.forEach( function ( row ) {
				last.appendChild( row );
			} );

			this.fields.appendChild( last );
			this.screens.push( last );
		} else if ( this.screens.length ) {
			last = this.screens[ this.screens.length - 1 ];
		}

		// The submit button sits outside the fields wrapper, or inside a row of
		// its own when the author placed an explicit Submit field. Either way it
		// belongs on the final screen rather than on every screen.
		//
		// WHICH screen is final is not settled here. Conditional logic can take
		// the last screen away as the visitor answers, and the button would go
		// with it — see placeActions(), which is re-run on every render.
		var actions = this.form.querySelector( '.shapeforms-lite-form__actions' );

		if ( actions && last ) {
			this.actions       = actions;
			this.actionsOrigin = { parent: actions.parentNode, before: actions.nextSibling, node: actions };
			last.appendChild( actions );
		}

		// The browser validates the WHOLE form on submit. An invalid required
		// control inside a display:none screen cannot be focused, so the browser
		// refuses to submit and reports nothing to the visitor. Setting this from
		// JS (never in PHP) means a visitor without JS keeps native validation.
		//
		// Lite's frontend already does this to every form for its own reasons, so
		// this is belt-and-braces rather than the only guard — but it is not
		// redundant: it runs on DOMContentLoaded, ahead of jQuery ready, and it
		// still holds if jQuery never arrives at all.
		this.form.setAttribute( 'novalidate', 'novalidate' );
	};

	/** Screens not currently ruled out by conditional logic. */
	Engine.prototype.reachable = function () {
		return this.screens.filter( function ( screen ) {
			return ! isConditionallyHidden( screen );
		} );
	};

	/**
	 * Reachable screens that count towards progress.
	 *
	 * The welcome screen is a cover, not a question: counting it would make a
	 * five-question form announce "Question 1 of 6" before anything was asked.
	 */
	Engine.prototype.countable = function () {
		var welcome = this.welcome;
		return this.reachable().filter( function ( screen ) {
			return screen !== welcome;
		} );
	};

	/** Whether the screen in view is the welcome cover. */
	Engine.prototype.onWelcome = function () {
		return !! this.welcome && this.screens[ this.index ] === this.welcome;
	};

	/**
	 * Puts the submit button on the last screen the visitor can actually reach.
	 *
	 * build() parks it on the final screen so it appears with the last question
	 * rather than under every one. Conditional logic can then take that screen
	 * away — and the only way to submit the form goes with it, sitting inside a
	 * display:none screen while the engine's own Next button has correctly stood
	 * down because there is nothing after here. The visitor is left looking at a
	 * form with no button on it.
	 *
	 * Re-homed on every render rather than fixed once, because "the end" is a
	 * property of the answers, not of the markup.
	 */
	Engine.prototype.placeActions = function () {
		if ( ! this.actions ) {
			return;
		}

		// countable(), not reachable(): the welcome cover carries its own Start
		// button and is not somewhere a form gets submitted from.
		var open = this.countable();
		var host = open.length ? open[ open.length - 1 ] : this.screens[ this.screens.length - 1 ];

		if ( host && this.actions.parentNode !== host ) {
			host.appendChild( this.actions );
		}
	};

	/**
	 * Recomputes everything that depends on WHICH screens are currently open.
	 *
	 * Progress used to be recomputed alone when an answer changed. The navigation
	 * was not, so a visitor whose answer removed the remaining screens saw the
	 * counter drop to "1 of 1" while the button still read OK — and pressing it
	 * ran off the end of the screen list and submitted the form. Advance and
	 * submit must never be the same button wearing the same label.
	 */
	Engine.prototype.refresh = function () {
		this.placeActions();
		this.renderProgress();
		this.renderNav();
	};

	Engine.prototype.bind = function () {
		var self = this;

		if ( this.nextBtn ) {
			this.nextBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				self.next();
			} );
		}

		if ( this.prevBtn ) {
			this.prevBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				self.prev();
			} );
		}

		var start = this.chrome.querySelector( '[data-shapeforms-cv-start]' );
		if ( start ) {
			start.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				self.next();
			} );
		}

		// Capture phase: this runs before the delegated submit handler that owns
		// the AJAX request, so a submit from a non-final screen can be turned
		// into "advance" before anything else reacts to it.
		this.form.addEventListener( 'submit', function ( e ) {
			if ( ! self.isLast() ) {
				e.preventDefault();
				e.stopPropagation();
				self.next();
			}
		}, true );

		// Enter on a closed custom select, in CAPTURE.
		//
		// The hint next to the button promises Enter advances, and on a dropdown
		// screen it did nothing at all: onKeydown() below stands aside for
		// anything inside .bf-select, and the widget's own trigger handler
		// treats Enter-while-closed as "open the list". So an author picked an
		// option, pressed Enter, and the list they had just finished with opened
		// again — every time, with no way forward but the mouse.
		//
		// CAPTURE IS THE WHOLE POINT and is not interchangeable with the bubble
		// listener below. The trigger's handler runs at the target and adds
		// `is-open` before the event reaches the form, so a bubble-phase check
		// of that class always reads "open" and would stand aside exactly when
		// it should not.
		//
		// Kept as its own listener rather than folded into onKeydown(): moving
		// that one to capture would reorder every key it handles against every
		// control on the screen, which is a great deal of blast radius for one
		// widget.
		this.form.addEventListener( 'keydown', function ( e ) {
			self.onSelectEnter( e );
		}, true );

		this.form.addEventListener( 'keydown', function ( e ) {
			self.onKeydown( e );
		} );

		// The same keys, for when NOTHING on the page holds focus.
		//
		// Both listeners above are on the form, so they only ever see a key if
		// focus is inside it — and focus leaves the form more easily than it
		// looks. Clicking an option in the custom select re-renders the list,
		// detaching the very element that was clicked, and focus falls back to
		// <body>; clicking the page background does the same. The screen then
		// sits there with "press Enter" beside the button and no listener
		// anywhere to hear it.
		//
		// Guarded to the one state where acting cannot take a key from anything
		// else: nothing is focused at all. With focus on a real control, the
		// form-level listeners run exactly as before and this stands down — so
		// this adds a case rather than changing one.
		document.addEventListener( 'keydown', function ( e ) {
			var active = document.activeElement;

			if ( active && active !== document.body && active !== document.documentElement ) {
				return;
			}

			// More than one conversational form on the page and no focus to say
			// which one is being answered: there is no right answer, so do
			// nothing rather than advance the wrong form.
			if ( document.querySelectorAll( '.shapeforms-cv.is-ready' ).length > 1 ) {
				return;
			}

			if ( ! document.body.contains( self.form ) ) {
				return;
			}

			self.onKeydown( e );
		} );

		// Conditional logic can change which screens exist as the visitor
		// answers, so everything that depends on the screen list is recomputed
		// rather than fixed at init — the count, the navigation, and where the
		// submit button lives.
		this.form.addEventListener( 'change', function () {
			self.refresh();
		} );
	};

	/**
	 * Enter on Lite's custom select, decided by what the visitor is actually
	 * looking at.
	 *
	 *   panel open      -> the widget keeps it. Enter picks the highlighted
	 *                      option, which is the only thing it can sensibly mean.
	 *   closed, no answer yet -> the widget keeps it. Enter opens the list,
	 *                      which is help rather than an obstacle: there is
	 *                      nothing to advance past yet, and on a required screen
	 *                      advancing would only raise an error.
	 *   closed, answered -> the ENGINE takes it. The question is done, and Enter
	 *                      means what the hint says it means.
	 *
	 * The answered test reads the trigger's own content rather than the <select>
	 * behind it: that element is a SIBLING of .bf-select, not a child, so there
	 * is no reliable way down to it from here — and the trigger is what the
	 * visitor is reading anyway.
	 */
	Engine.prototype.onSelectEnter = function ( e ) {
		if ( 'Enter' !== e.key || ! e.target || ! e.target.closest || ! this.config.keyHint ) {
			return;
		}

		var wrap = e.target.closest( '.bf-select' );

		if ( ! wrap || wrap.classList.contains( 'is-open' ) ) {
			return;
		}

		// A single select shows .bf-select__value once chosen and a
		// .bf-select__placeholder until then; a multiple shows one tag per
		// choice. Either counts as answered.
		if ( ! wrap.querySelector( '.bf-select__value, .bf-select__tag' ) ) {
			return;
		}

		e.preventDefault();
		// Without this the trigger's own handler still runs and opens the list
		// behind the screen that just advanced.
		e.stopPropagation();

		if ( this.isLast() ) {
			this.submit();
		} else {
			this.next();
		}
	};

	Engine.prototype.onKeydown = function ( e ) {
		var target = e.target;

		if ( 'Enter' === e.key ) {
			var tag = ( target.tagName || '' ).toLowerCase();

			// A textarea owns Enter for newlines; Shift+Enter advances instead.
			if ( 'textarea' === tag && ! e.shiftKey ) {
				return;
			}

			// Let buttons and the custom select do their own thing.
			if ( 'button' === tag || target.closest( '.bf-select' ) ) {
				return;
			}

			// The switch decides whether Enter moves between screens at all.
			//
			// It used to only draw the hint beside the button, which made it a
			// label for a behaviour it did not control: an author who turned it
			// off to stop visitors skipping ahead by accident got a form that
			// still skipped ahead, silently. Now the two agree — the hint is
			// shown exactly when the key does something.
			//
			// SWALLOWED, not merely ignored. A bare Enter in a text input asks
			// the browser to submit the form, and the capture-phase submit
			// listener above turns a submit on a non-final screen into "advance"
			// — so returning without preventDefault would leave the switch doing
			// nothing at all, which is the fault this is fixing.
			//
			// The LAST screen is left alone. There is no next screen to refuse,
			// and Enter submitting a completed form is ordinary HTML behaviour
			// that every other ShapeForms form has; withdrawing it here would be
			// conversational mode taking something away rather than declining to
			// add something.
			if ( ! this.config.keyHint ) {
				if ( ! this.isLast() ) {
					e.preventDefault();
				}
				return;
			}

			e.preventDefault();

			if ( this.isLast() ) {
				this.submit();
			} else {
				this.next();
			}
			return;
		}

		if ( 'ArrowDown' === e.key || 'ArrowUp' === e.key ) {
			if ( ownsArrowKeys( target ) ) {
				return;
			}
			e.preventDefault();
			if ( 'ArrowDown' === e.key ) {
				this.next();
			} else {
				this.prev();
			}
		}
	};

	Engine.prototype.isLast = function () {
		var reachable = this.reachable();
		return reachable.length ? this.screens[ this.index ] === reachable[ reachable.length - 1 ] : true;
	};

	/** Steps through the screen list, skipping anything conditional logic hid. */
	Engine.prototype.seek = function ( from, direction ) {
		var i = from + direction;
		while ( i >= 0 && i < this.screens.length ) {
			if ( ! isConditionallyHidden( this.screens[ i ] ) ) {
				return i;
			}
			i += direction;
		}
		return -1;
	};

	Engine.prototype.next = function () {
		if ( ! this.validateCurrent() ) {
			return;
		}

		var target = this.seek( this.index, 1 );

		if ( -1 === target ) {
			this.submit();
			return;
		}

		this.show( target );
	};

	Engine.prototype.prev = function () {
		var target = this.seek( this.index, -1 );
		if ( -1 !== target ) {
			// Going back never validates — the visitor is allowed to leave a
			// question unanswered while they reconsider an earlier one.
			this.clearError();
			this.show( target );
		}
	};

	Engine.prototype.submit = function () {
		if ( ! this.validateCurrent() ) {
			return;
		}

		// Hand over to the form's own submit path (AJAX or a real POST). The
		// capture-phase interceptor above stands aside on the final screen.
		if ( typeof this.form.requestSubmit === 'function' ) {
			this.form.requestSubmit();
		} else {
			this.form.submit();
		}
	};

	/**
	 * Validates only the screen in view.
	 *
	 * Uses the browser's own constraint validation where it applies, plus one
	 * explicit case it cannot cover: a required dropdown. Lite renders a hidden
	 * native <select> with no `required` attribute alongside a custom widget
	 * that carries aria-required, so constraint validation reports it valid and
	 * a required dropdown would otherwise be walked straight past.
	 */
	Engine.prototype.validateCurrent = function () {
		var screen = this.screens[ this.index ];

		if ( ! screen ) {
			return true;
		}

		var fields  = screen.querySelectorAll( FIELD );
		var invalid = null;

		for ( var i = 0; i < fields.length && ! invalid; i++ ) {
			var field = fields[ i ];

			// A field conditional logic has hidden is not being asked.
			if ( 'none' === field.style.display ) {
				continue;
			}

			invalid = this.firstInvalidIn( field );
		}

		if ( ! invalid ) {
			this.clearError();
			return true;
		}

		this.showError( invalid );
		return false;
	};

	Engine.prototype.firstInvalidIn = function ( field ) {
		// The required-dropdown case constraint validation cannot see.
		var nativeSelect = field.querySelector( 'select[data-shapeforms-select]' );
		var trigger      = field.querySelector( '[aria-required="true"]' );

		if ( nativeSelect && trigger && ! nativeSelect.value ) {
			return field.querySelector( '.bf-select__trigger' ) || nativeSelect;
		}

		var controls = field.querySelectorAll( 'input, select, textarea' );

		for ( var i = 0; i < controls.length; i++ ) {
			var control = controls[ i ];

			// Conditional logic disables the required controls it hides; a
			// disabled control is not being asked either.
			if ( control.disabled ) {
				continue;
			}

			var type = ( control.type || '' ).toLowerCase();

			// A hidden input backs widgets like star ratings and sliders, so it
			// is only skipped when it carries no requirement of its own.
			if ( 'hidden' === type && ! control.hasAttribute( 'required' ) ) {
				continue;
			}

			// BARRED FROM CONSTRAINT VALIDATION, but still required. The spec
			// bars two kinds of control from validation entirely — a hidden
			// input and a readonly one — and `checkValidity()` answers true for
			// both however empty they are.
			//
			// A required date or time field produces BOTH at once. flatpickr
			// runs in altInput mode, which retypes the original input to hidden
			// (keeping its `required` attribute) and inserts a readonly text
			// input beside it with `required` copied across. Neither could ever
			// report invalid, so an empty required Date Picker was walked
			// straight past. A required star rating or slider, which is also
			// backed by a hidden input, had the same hole.
			//
			// Lite's own validator reads the VALUE rather than the constraint,
			// which is why an ordinary form already catches this and only the
			// conversational screens did not. Same test here.
			if ( control.hasAttribute( 'required' ) && ( 'hidden' === type || control.readOnly ) ) {
				if ( '' === String( null === control.value || undefined === control.value ? '' : control.value ).trim() ) {
					return this.focusableFor( control, field );
				}

				continue;
			}

			if ( typeof control.checkValidity === 'function' && ! control.checkValidity() ) {
				return control;
			}
		}

		return null;
	};

	/**
	 * The control to report an error against when the failing one cannot take
	 * focus. showError() focuses whatever it is handed, and a hidden input
	 * silently swallows that — leaving the message on screen with the cursor
	 * nowhere. flatpickr's visible partner lives in the same field wrapper, as
	 * does the control of any other widget backed by a hidden input.
	 */
	Engine.prototype.focusableFor = function ( control, field ) {
		if ( 'hidden' !== ( control.type || '' ).toLowerCase() ) {
			return control;
		}

		return field.querySelector( 'input:not([type="hidden"]), select:not([data-shapeforms-select]), textarea, button:not([type="submit"])' ) || control;
	};

	Engine.prototype.showError = function ( control ) {
		var field = control.closest( FIELD );
		var text  = ( field && field.getAttribute( 'data-error' ) )
			|| control.validationMessage
			|| this.i18n.required
			|| 'Please answer this question before continuing.';

		// UNDER THE FIELD, not in the form's message bar at the top of the screen.
		// A screen shows one question, so a banner above it names a field the
		// visitor can already see and puts the complaint furthest from the control
		// that has to change — on a tall screen it can sit off-frame entirely.
		//
		// Same element, same class and same ARIA wiring an ordinary Lite form
		// uses, so the message inherits whatever the theme already styles for a
		// field error, and Lite's own "clear it the moment the visitor edits the
		// field" listener — delegated on document for
		// `.shapeforms-lite-form__field.is-invalid :input` — picks this up too. That
		// covers a date arriving from the flatpickr calendar, which fires `change`
		// on the original input rather than any typing.
		this.chrome.classList.add( 'has-error' );

		if ( field ) {
			field.classList.add( 'shapeforms-cv-field--invalid', 'is-invalid' );

			if ( ! field.querySelector( '.shapeforms-lite-form__field-error' ) ) {
				var id  = 'shapeforms-cv-error-' + ( ++errorSeq );
				var box = document.createElement( 'div' );

				box.className = 'shapeforms-lite-form__field-error';
				box.id        = id;
				box.setAttribute( 'role', 'alert' );
				box.textContent = text;
				field.appendChild( box );

				// Mirrors Lite's markup so its clear-on-edit handler, which reads
				// the id back off the wrapper, can undo this exactly.
				field.setAttribute( 'data-bf-error-id', id );
				control.setAttribute( 'aria-invalid', 'true' );

				var described = control.getAttribute( 'aria-describedby' );
				control.setAttribute( 'aria-describedby', described ? described + ' ' + id : id );
			}
		}

		// role="alert" announces the message itself; the live region carries the
		// same words for the case where the field never received one.
		if ( this.live && ! field ) {
			this.live.textContent = text;
		}

		try {
			control.focus( { preventScroll: false } );
		} catch ( e ) {
			control.focus();
		}
	};

	Engine.prototype.clearError = function () {
		// Kept: an earlier build wrote field errors here, and a form that scrolled
		// away mid-error could otherwise leave a stale banner behind.
		if ( this.message ) {
			this.message.textContent = '';
			this.message.classList.remove( 'is-visible', 'is-error' );
		}

		this.chrome.classList.remove( 'has-error' );

		var boxes = this.chrome.querySelectorAll( '.shapeforms-lite-form__field-error' );
		for ( var i = 0; i < boxes.length; i++ ) {
			if ( boxes[ i ].parentNode ) {
				boxes[ i ].parentNode.removeChild( boxes[ i ] );
			}
		}

		var flagged = this.chrome.querySelectorAll( '.shapeforms-cv-field--invalid, ' + FIELD + '.is-invalid' );
		for ( var j = 0; j < flagged.length; j++ ) {
			flagged[ j ].classList.remove( 'shapeforms-cv-field--invalid', 'is-invalid' );
			flagged[ j ].removeAttribute( 'data-bf-error-id' );
		}

		// Swept separately: Lite's clear-on-edit handler picks the control it
		// considers the field's own, which for a widget is not always the one
		// showError() flagged, so an aria-invalid could otherwise outlive its
		// message.
		var marked = this.chrome.querySelectorAll( '[aria-invalid="true"]' );
		for ( var k = 0; k < marked.length; k++ ) {
			marked[ k ].removeAttribute( 'aria-invalid' );
		}
	};

	/**
	 * Reveals one screen.
	 *
	 * @param {number}  index   Screen index.
	 * @param {boolean} initial True on first paint, when focus must not move.
	 */
	Engine.prototype.show = function ( index, initial ) {
		var self = this;

		this.index = index;

		this.screens.forEach( function ( screen, i ) {
			var active = i === index;
			screen.classList.toggle( 'is-active', active );
			// display:none keeps an inactive screen out of the accessibility tree
			// as well as out of sight.
			screen.style.display = active ? '' : 'none';
			screen.setAttribute( 'aria-hidden', active ? 'false' : 'true' );
			if ( active ) {
				self.current = screen;
			}
		} );

		this.refresh();

		if ( ! initial ) {
			this.moveFocus();
			this.announce();
		}
	};

	Engine.prototype.moveFocus = function () {
		var screen = this.screens[ this.index ];
		if ( ! screen ) {
			return;
		}

		var controls = controlsIn( screen );

		for ( var i = 0; i < controls.length; i++ ) {
			var control = controls[ i ];
			if ( control.disabled || 'none' === control.style.display ) {
				continue;
			}
			// The native select behind the custom widget is display:none; focus
			// the widget the visitor can actually see.
			if ( 'select' === ( control.tagName || '' ).toLowerCase() && control.hasAttribute( 'data-shapeforms-select' ) ) {
				var widget = screen.querySelector( '.bf-select__trigger' );
				if ( widget ) {
					widget.focus();
					return;
				}
			}
			control.focus();
			return;
		}

		// A statement screen has nothing to answer, so focus its heading instead
		// of leaving focus behind on the previous screen.
		var heading = screen.querySelector( 'h1, h2, h3, h4, h5, h6' ) || screen;
		heading.setAttribute( 'tabindex', '-1' );
		heading.focus();
	};

	Engine.prototype.announce = function () {
		if ( ! this.live ) {
			return;
		}

		var reachable = this.countable();
		var position  = reachable.indexOf( this.screens[ this.index ] ) + 1;
		var template  = this.i18n.screenOf || 'Question %1$s of %2$s';

		if ( position < 1 ) {
			return;
		}

		this.live.textContent = template
			.replace( '%1$s', String( position ) )
			.replace( '%2$s', String( reachable.length ) );
	};

	Engine.prototype.renderNav = function () {
		if ( this.prevBtn ) {
			this.prevBtn.hidden = -1 === this.seek( this.index, -1 );
		}

		// The final screen carries the form's real submit button, so the
		// engine's own advance button stands down there.
		if ( this.nextBtn ) {
			this.nextBtn.hidden = this.isLast();
		}

		this.chrome.classList.toggle( 'is-last', this.isLast() );
		this.chrome.classList.toggle( 'is-first', -1 === this.seek( this.index, -1 ) );

		// The cover carries its own Start button, so the progress indicator and
		// the navigation row both stand down while it is showing.
		this.chrome.classList.toggle( 'is-welcome', this.onWelcome() );
	};

	Engine.prototype.renderProgress = function () {
		var style = this.config.progress || 'bar';

		if ( 'none' === style ) {
			return;
		}

		var reachable = this.countable();
		var total     = reachable.length;
		var position  = reachable.indexOf( this.screens[ this.index ] ) + 1;

		if ( total < 1 ) {
			return;
		}

		var ratio = Math.round( ( position / total ) * 100 );

		var fill = this.chrome.querySelector( '[data-shapeforms-cv-fill]' );
		if ( fill ) {
			fill.style.width = ratio + '%';
			var bar = fill.parentNode;
			if ( bar && bar.setAttribute ) {
				bar.setAttribute( 'aria-valuenow', String( ratio ) );
			}
		}

		var count = this.chrome.querySelector( '[data-shapeforms-cv-count]' );
		if ( count ) {
			if ( 'percent' === style ) {
				// The `%%` escape has to be collapsed here. The string is written
				// for PHP's sprintf, where `%%` prints one percent sign; nothing
				// does that on the way to JavaScript, so a plain substitution
				// renders "25%% completed" to the visitor.
				count.textContent = ( this.i18n.percent || '%s%% completed' )
					.replace( '%s', String( ratio ) )
					.replace( /%%/g, '%' );
			} else {
				count.textContent = ( this.i18n.ofTotal || '%1$s of %2$s' )
					.replace( '%1$s', String( position ) )
					.replace( '%2$s', String( total ) );
			}
		}

		var dots = this.chrome.querySelector( '[data-shapeforms-cv-dots]' );
		if ( dots ) {
			// Rebuilt rather than patched: conditional logic can change the
			// number of screens between renders.
			dots.textContent = '';
			for ( var i = 0; i < total; i++ ) {
				var dot = document.createElement( 'span' );
				dot.className = 'shapeforms-cv__dot' + ( i < position ? ' is-done' : '' );
				dots.appendChild( dot );
			}
		}
	};

	/** Returns to the first screen after a successful submission. */
	Engine.prototype.reset = function () {
		this.clearError();
		var first = isConditionallyHidden( this.screens[ 0 ] ) ? this.seek( -1, 1 ) : 0;
		this.show( -1 === first ? 0 : first, true );
	};

	var engines = [];

	function init() {
		var nodes = document.querySelectorAll( '.shapeforms-cv' );

		for ( var i = 0; i < nodes.length; i++ ) {
			if ( nodes[ i ].getAttribute( 'data-shapeforms-cv-init' ) ) {
				continue;
			}
			nodes[ i ].setAttribute( 'data-shapeforms-cv-init', '1' );
			engines.push( new Engine( nodes[ i ] ) );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	/**
	 * ── The jQuery bridge ────────────────────────────────────────────────────
	 *
	 * The ONLY jQuery in this file, and the only part that would need replacing
	 * if the frontend stopped using jQuery. Lite signals a completed submission
	 * with `$( document ).trigger( 'shapeforms_form_reset', [ $form ] )`; a jQuery
	 * custom event never reaches addEventListener, so it cannot be observed
	 * natively.
	 */
	if ( window.jQuery ) {
		window.jQuery( document ).on( 'shapeforms_form_reset', function ( event, $form ) {
			var formEl = $form && $form[ 0 ] ? $form[ 0 ] : null;

			engines.forEach( function ( engine ) {
				if ( engine && engine.form && engine.screens && ( ! formEl || engine.form === formEl ) ) {
					engine.reset();
				}
			} );
		} );
	}
} )();
