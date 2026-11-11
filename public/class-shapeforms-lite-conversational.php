<?php
/**
 * Conversational mode — renders an ordinary form one screen at a time.
 *
 * The load-bearing rule of this feature: it POST-PROCESSES the already-rendered
 * form and never re-renders, re-orders or removes anything. Lite's
 * conditional-logic evaluator binds once over the whole form and resolves
 * values with `$form.find('[name=…]')`, so any field pulled out of the DOM
 * silently breaks every rule that references it. Keeping the complete form in
 * the DOM is what makes conditional logic, validation, the honeypot, the nonce
 * and the submit path all keep working untouched — and it is why field types
 * contributed by the paid add-on work here with no change on their side: they
 * are already in the HTML by the time this runs.
 *
 * It also means the stored form is never mutated. Conversational mode is a
 * presentation setting; switching it off restores the ordinary render exactly.
 *
 * @package ShapeForms_Lite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wraps rendered form output in conversational chrome.
 */
class ShapeForms_Lite_Conversational {

	/**
	 * The marker the multi-step add-on looks for in the rendered HTML.
	 *
	 * Stripping the bare comment makes that module early-return, which is how
	 * the two features stay mutually exclusive without Lite ever detecting the
	 * add-on. This is a data contract with Lite's own output, not a dependency:
	 * with no add-on installed the marker simply never appears.
	 *
	 * @var string
	 */
	const PAGE_BREAK_MARKER = '<!--shapeforms-page-break-->';

	/**
	 * The opening tag this class anchors its chrome to.
	 *
	 * Rows are direct children of this element, so the screen list needs no
	 * HTML parsing and no div-depth counting.
	 *
	 * @var string
	 */
	const FIELDS_OPEN = '<div class="shapeforms-lite-form__fields">';

	/**
	 * Plugin instance.
	 *
	 * @var ShapeForms_Lite
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param ShapeForms_Lite $plugin Plugin instance.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Registers (does not enqueue) the conversational assets.
	 *
	 * Enqueueing happens in wrap_form_output(), so a site with no conversational
	 * form never ships these bytes.
	 *
	 * @return void
	 */
	public function register_assets() {
		wp_register_style(
			'shapeforms-lite-conversational',
			SHAPEFORMS_LITE_URL . 'assets/css/conversational.css',
			array( 'shapeforms-lite-frontend' ),
			$this->asset_version( 'assets/css/conversational.css' )
		);

		wp_register_script(
			'shapeforms-lite-conversational',
			SHAPEFORMS_LITE_URL . 'assets/js/conversational.js',
			// jQuery is a dependency for one reason only: Lite fires
			// `shapeforms_form_reset` as a jQuery custom event, which does not
			// dispatch as a native DOM event and so cannot be observed with
			// addEventListener. The engine itself is framework-neutral.
			array( 'jquery', 'shapeforms-lite-frontend' ),
			$this->asset_version( 'assets/js/conversational.js' ),
			true
		);
	}

	/**
	 * Registers and enqueues the assets for the admin form-preview screen.
	 *
	 * The preview is an ADMIN page, so `wp_enqueue_scripts` never fires and
	 * register_assets() never runs there. Without this, the enqueue inside
	 * wrap_form_output() names a handle that was never registered: it enters the
	 * queue, wp_script_is( ..., 'enqueued' ) reports true, and NOTHING is
	 * printed. The chrome renders, the engine never loads, and the preview shows
	 * an ordinary form — which is exactly how this was found.
	 *
	 * Enqueued unconditionally rather than only for conversational forms: this
	 * fires before the form is rendered, so whether the mode is on is not yet
	 * known. The cost is one small stylesheet and one script that no-ops when no
	 * .shapeforms-cv element exists.
	 */
	public function enqueue_preview_assets() {
		$this->register_assets();

		wp_enqueue_style( 'shapeforms-lite-conversational' );
		wp_enqueue_script( 'shapeforms-lite-conversational' );
	}

	/**
	 * Returns a cache-busting version for an asset.
	 *
	 * @param string $relative_path Path relative to the plugin root.
	 * @return string
	 */
	private function asset_version( $relative_path ) {
		$absolute = SHAPEFORMS_LITE_PATH . ltrim( $relative_path, '/' );
		$mtime    = file_exists( $absolute ) ? filemtime( $absolute ) : false;
		return ( false !== $mtime ) ? (string) $mtime : SHAPEFORMS_LITE_VERSION;
	}

	/**
	 * Whether conversational mode is active for this form.
	 *
	 * @param array<string, mixed> $settings Resolved form settings.
	 * @param int                  $form_id  Form ID.
	 * @return bool
	 */
	public function is_enabled( $settings, $form_id = 0 ) {
		$enabled = ! empty( $settings['cv_enabled'] );

		/**
		 * Filters whether conversational mode runs for a form.
		 *
		 * Lets a site disable the feature globally or per form without changing
		 * the stored setting.
		 *
		 * @param bool                 $enabled  Whether conversational mode is active.
		 * @param array<string, mixed> $settings Resolved form settings.
		 * @param int                  $form_id  Form ID.
		 */
		return (bool) apply_filters( 'shapeforms_conversational_enabled', $enabled, $settings, $form_id );
	}

	/**
	 * Whether the current request is a layout canvas that must not be taken over.
	 *
	 * The block editor's REST renderer and the Elementor editor canvas show the
	 * form as a layout object; converting it there would fight the editing
	 * experience. ShapeForms's own Preview screen is deliberately NOT excluded —
	 * that is where an author goes to see the finished result.
	 *
	 * @return bool
	 */
	private function is_layout_canvas() {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		if ( class_exists( '\Elementor\Plugin' ) ) {
			$elementor = \Elementor\Plugin::instance();

			if ( ! empty( $elementor->preview ) && method_exists( $elementor->preview, 'is_preview_mode' ) && $elementor->preview->is_preview_mode() ) {
				return true;
			}

			if ( ! empty( $elementor->editor ) && method_exists( $elementor->editor, 'is_edit_mode' ) && $elementor->editor->is_edit_mode() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Disengages the multi-page renderer when conversational mode owns the form.
	 *
	 * Runs at priority 6 — before the multi-page wrapper at 10. Only the bare
	 * marker comment is removed; the row/column markup a page break emits around
	 * it stays, so the DOM remains balanced and a page break simply becomes one
	 * more screen boundary. That is the intended "mutually exclusive" behaviour.
	 *
	 * @param string               $html        Rendered form HTML.
	 * @param int                  $form_id     Form ID.
	 * @param object               $form_record Form database row.
	 * @param array<string, mixed> $settings    Resolved form settings.
	 * @return string
	 */
	public function stand_down_multi_page( $html, $form_id = 0, $form_record = null, $settings = array() ) {
		// Gated on exactly the same condition as wrap_form_output() below.
		// Standing the multi-step renderer down without then converting the form
		// would leave the embed with neither steps nor screens.
		if ( ! is_array( $settings ) || ! $this->is_enabled( $settings, $form_id ) ) {
			return $html;
		}

		if ( false === strpos( $html, self::PAGE_BREAK_MARKER ) ) {
			return $html;
		}

		return str_replace( self::PAGE_BREAK_MARKER, '', $html );
	}

	/**
	 * Injects the conversational chrome around a rendered form.
	 *
	 * Runs at priority 30 — after every consumer at 20 (payment card element,
	 * save-and-resume, draft autosave), so everything that belongs inside the
	 * form is already present and lands on the correct screen.
	 *
	 * The chrome is emitted as a SIBLING immediately before the fields wrapper
	 * and the engine moves the fields into it at runtime. Wrapping in PHP would
	 * mean locating a matching close tag, i.e. the div-depth counting this
	 * feature deliberately avoids. Moving fields into the chrome (rather than
	 * moving chrome out) also keeps the inline custom properties inherited,
	 * since CSS custom properties cascade by DOM containment.
	 *
	 * @param string               $html        Rendered form HTML.
	 * @param int                  $form_id     Form ID.
	 * @param object               $form_record Form database row.
	 * @param array<string, mixed> $settings    Resolved form settings.
	 * @return string
	 */
	public function wrap_form_output( $html, $form_id = 0, $form_record = null, $settings = array() ) {
		if ( ! is_array( $settings ) || ! $this->is_enabled( $settings, $form_id ) ) {
			return $html;
		}

		if ( $this->is_layout_canvas() ) {
			return $html;
		}

		// An upstream gate (entry limit, scheduling, form locking) may have
		// replaced the form with a notice. Nothing to convert.
		if ( false === strpos( $html, self::FIELDS_OPEN ) ) {
			return $html;
		}

		// One screen is not a conversation. Render the ordinary form rather than
		// dead navigation around a single question.
		if ( $this->count_screens( $html ) < 2 ) {
			return $html;
		}

		wp_enqueue_style( 'shapeforms-lite-conversational' );
		wp_enqueue_script( 'shapeforms-lite-conversational' );

		$chrome = $this->build_chrome( $settings, $form_id );

		// Anchored to a literal opening tag: a single replacement, no parsing.
		// Limited to the first occurrence so a nested form (which Lite never
		// emits, but a filter might) cannot be double-wrapped.
		$position = strpos( $html, self::FIELDS_OPEN );

		return substr_replace( $html, $chrome . self::FIELDS_OPEN, $position, strlen( self::FIELDS_OPEN ) );
	}

	/**
	 * Estimates how many screens the engine will produce — an UPPER bound.
	 *
	 * Rows do not map one-to-one to screens: a single-column row holding several
	 * fields is split into one screen per field, because stacking fields in one
	 * row is what the builder produces by default and treating that as a single
	 * screen makes the whole mode a no-op. Counting rows alone suppressed the
	 * chrome on exactly the forms this feature exists for.
	 *
	 * Deliberately an upper bound, and deliberately not a re-implementation of
	 * the split: the engine measures the live DOM, sees conditional state and
	 * rows contributed by other features, and stands itself down when only one
	 * screen survives. PHP's only job is to avoid emitting chrome for a form
	 * that can never have more than one.
	 *
	 * @param string $html Rendered form HTML.
	 * @return int
	 */
	private function count_screens( $html ) {
		$rows   = substr_count( $html, 'class="shapeforms-lite-form__row' );
		$fields = substr_count( $html, 'class="shapeforms-lite-form__field ' );

		return max( $rows, $fields );
	}

	/**
	 * Builds the chrome markup that precedes the fields wrapper.
	 *
	 * Per-form config travels in a data attribute rather than through
	 * wp_localize_script / wp_add_inline_script: a render-time localize is
	 * silently dropped under block (FSE) themes, which would leave the engine
	 * with no configuration and no error.
	 *
	 * @param array<string, mixed> $settings Resolved form settings.
	 * @param int                  $form_id  Form ID.
	 * @return string
	 */
	private function build_chrome( $settings, $form_id ) {
		$config = array(
			'progress'  => isset( $settings['cv_progress'] ) ? (string) $settings['cv_progress'] : 'bar',
			'transition' => isset( $settings['cv_transition'] ) ? (string) $settings['cv_transition'] : 'slide',
			'keyHint'   => ! empty( $settings['cv_key_hint'] ),
			'tailTypes'  => $this->get_tail_types(),
			'stackTypes' => $this->get_stacking_types(),
			'i18n'       => array(
				/* translators: 1: current question number, 2: total number of questions. Announced to screen readers on each screen change. */
				'screenOf' => __( 'Question %1$s of %2$s', 'shapeforms' ),
				'required' => __( 'Please answer this question before continuing.', 'shapeforms' ),
				/* translators: 1: current question number, 2: total number of questions. Shown as the progress counter, e.g. "2 of 7". */
				'ofTotal'  => __( '%1$s of %2$s', 'shapeforms' ),
				/* translators: %s: completion percentage, e.g. "42". The doubled %% renders a literal percent sign. */
				'percent'  => __( '%s%% completed', 'shapeforms' ),
			),
		);

		$defaults = self::default_labels();

		$next_text = isset( $settings['cv_next_text'] ) && '' !== $settings['cv_next_text']
			? (string) $settings['cv_next_text']
			: $defaults['next'];
		$prev_text = isset( $settings['cv_prev_text'] ) && '' !== $settings['cv_prev_text']
			? (string) $settings['cv_prev_text']
			: $defaults['prev'];

		$style = $this->build_custom_properties( $settings );

		$classes  = 'shapeforms-cv';
		$classes .= ' shapeforms-cv--progress-' . sanitize_html_class( $config['progress'] );
		$classes .= ' shapeforms-cv--motion-' . sanitize_html_class( $config['transition'] );

		// Where the Back/Next pair sits. Left is the default because it lines the
		// buttons up with the question and the answer above them; split pushes
		// them to opposite edges, which is the familiar multi-step look.
		$nav_align = isset( $settings['cv_nav_align'] ) && in_array( $settings['cv_nav_align'], array( 'left', 'center', 'split', 'right' ), true )
			? (string) $settings['cv_nav_align']
			: 'left';
		$classes  .= ' shapeforms-cv--nav-' . sanitize_html_class( $nav_align );

		// Where the indicator sits. Emitted for every indicator so the class is
		// one less thing that depends on another setting, but only the inline
		// ones — dots, counter, percentage — can actually move: a bar is full
		// width, and the builder offers the control accordingly.
		$progress_align = isset( $settings['cv_progress_align'] ) && in_array( $settings['cv_progress_align'], array( 'left', 'center', 'right' ), true )
			? (string) $settings['cv_progress_align']
			: 'left';
		$classes       .= ' shapeforms-cv--progress-align-' . sanitize_html_class( $progress_align );

		// Per-screen media behaviour. Hiding media on small screens is the
		// default: a decorative image is the first thing worth dropping on a
		// phone, both for layout and for bandwidth.
		if ( ! isset( $settings['cv_media_hide_mobile'] ) || ! empty( $settings['cv_media_hide_mobile'] ) ) {
			$classes .= ' shapeforms-cv--media-hide-mobile';
		}

		ob_start();
		?>
		<div
			class="<?php echo esc_attr( $classes ); ?>"
			data-shapeforms-cv="<?php echo esc_attr( (string) wp_json_encode( $config ) ); ?>"
			<?php echo $style ? 'style="' . esc_attr( $style ) . '"' : ''; ?>
		>
			<?php if ( 'none' !== $config['progress'] ) : ?>
			<div class="shapeforms-cv__progress" data-shapeforms-cv-progress>
				<?php if ( 'bar' === $config['progress'] ) : ?>
					<div class="shapeforms-cv__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="<?php esc_attr_e( 'Form progress', 'shapeforms' ); ?>">
						<span class="shapeforms-cv__bar-fill" data-shapeforms-cv-fill></span>
					</div>
				<?php elseif ( 'dots' === $config['progress'] ) : ?>
					<div class="shapeforms-cv__dots" data-shapeforms-cv-dots aria-hidden="true"></div>
				<?php else : ?>
					<span class="shapeforms-cv__count" data-shapeforms-cv-count></span>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<?php echo $this->build_welcome( $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped values in build_welcome(). ?>

			<div class="shapeforms-cv__viewport" data-shapeforms-cv-viewport></div>

			<div class="shapeforms-cv__nav">
				<button type="button" class="shapeforms-cv__btn shapeforms-cv__btn--prev" data-shapeforms-cv-prev hidden>
					<?php echo esc_html( $prev_text ); ?>
				</button>
				<button type="button" class="shapeforms-cv__btn shapeforms-cv__btn--next" data-shapeforms-cv-next>
					<?php echo esc_html( $next_text ); ?>
				</button>
				<?php if ( $config['keyHint'] ) : ?>
					<span class="shapeforms-cv__hint" data-shapeforms-cv-hint>
						<?php
						printf(
							/* translators: %s: the Enter key, shown as a styled key cap. */
							esc_html__( 'press %s', 'shapeforms' ),
							'<kbd>' . esc_html__( 'Enter', 'shapeforms' ) . '</kbd>'
						);
						?>
					</span>
				<?php endif; ?>
			</div>

			<p class="shapeforms-cv__sr" data-shapeforms-cv-live aria-live="polite"></p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The labels a form falls back to when the author has set none.
	 *
	 * One source, because these are stated in three places that must agree: the
	 * rendered form, the Style tab's live preview, and the PLACEHOLDER in the
	 * settings panel — which is the only documentation an author ever reads for
	 * what "leave it empty" gives them. They had already drifted once, the panel
	 * promising one word while every renderer produced another.
	 *
	 * Translated here rather than in the builder, so a localised site shows the
	 * same word in the panel as it renders on the page.
	 *
	 * @return array<string, string>
	 */
	public static function default_labels() {
		return array(
			// Typeform says "OK" and Fluent follows it, but an unlabelled
			// acknowledgement does not tell a first-time visitor that there is
			// more of the form to come. "Next" names the action.
			'next'    => __( 'Next', 'shapeforms' ),
			'prev'    => __( 'Back', 'shapeforms' ),
			'start'   => __( 'Start', 'shapeforms' ),
			// Not a button: the heading a switched-on welcome cover falls back
			// to. Kept here so the panel's placeholder and the page agree, the
			// same reason the three above are.
			'welcome' => __( 'Let’s get started', 'shapeforms' ),
		);
	}

	/**
	 * Builds the optional welcome screen.
	 *
	 * Emitted as ordinary markup ahead of the viewport and hidden by the engine
	 * like any other screen. It is deliberately NOT a form field: it holds no
	 * value, posts nothing, and is invisible to validation — so a visitor
	 * without JavaScript simply sees a heading above the form rather than a
	 * dead-end "Start" button that leads nowhere.
	 *
	 * @param array<string, mixed> $settings Resolved form settings.
	 * @return string
	 */
	private function build_welcome( $settings ) {
		if ( empty( $settings['cv_welcome_enabled'] ) ) {
			return '';
		}

		$title  = isset( $settings['cv_welcome_title'] ) ? (string) $settings['cv_welcome_title'] : '';
		$text   = isset( $settings['cv_welcome_text'] ) ? (string) $settings['cv_welcome_text'] : '';
		$labels = self::default_labels();

		/*
		 * A cover with only a button on it says nothing, so an empty pair used to
		 * suppress the cover outright — which made the switch appear to do
		 * nothing at all, the one behaviour a switch must never have. It falls
		 * back to a heading instead, and deliberately to the SAME words the
		 * panel shows as the field's placeholder: that placeholder is the only
		 * place an author is told what leaving the field empty gives them.
		 *
		 * The text is measured with its tags stripped, so markup alone
		 * ("<p></p>") counts as empty here exactly as it does in the builder.
		 */
		if ( '' === trim( $title ) && '' === trim( wp_strip_all_tags( $text ) ) ) {
			$title = $labels['welcome'];
		}

		$button = isset( $settings['cv_welcome_btn'] ) && '' !== $settings['cv_welcome_btn']
			? (string) $settings['cv_welcome_btn']
			: $labels['start'];

		ob_start();
		?>
		<div class="shapeforms-cv__welcome" data-shapeforms-cv-welcome>
			<?php if ( '' !== trim( $title ) ) : ?>
				<h2 class="shapeforms-cv__welcome-title" tabindex="-1"><?php echo esc_html( $title ); ?></h2>
			<?php endif; ?>

			<?php if ( '' !== trim( wp_strip_all_tags( $text ) ) ) : ?>
				<div class="shapeforms-cv__welcome-text"><?php echo wp_kses_post( $text ); ?></div>
			<?php endif; ?>

			<button type="button" class="shapeforms-cv__btn shapeforms-cv__welcome-btn" data-shapeforms-cv-start>
				<?php echo esc_html( $button ); ?>
			</button>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Field types that are pinned to the final screen rather than getting one.
	 *
	 * A consent box or a captcha stranded in the middle of a conversation leaves
	 * the visitor on a screen they cannot complete, so these always travel to the
	 * end and sit beside the submit button.
	 *
	 * Lite declares only its own types. Everything else is classified by what it
	 * renders (see the engine's structural pass), so field types contributed by
	 * another plugin behave correctly without Lite knowing they exist — and
	 * without Lite ever checking whether that plugin is installed.
	 *
	 * @return string[]
	 */
	private function get_tail_types() {
		/**
		 * Filters the field types pinned to the final conversational screen.
		 *
		 * @param string[] $types Field type slugs.
		 */
		return array_values( array_filter( array_map( 'sanitize_key', (array) apply_filters(
			'shapeforms_conversational_tail_types',
			array( 'captcha', 'terms_conditions' )
		) ) ) );
	}

	/**
	 * Field types large enough that their row must never share a screen side by side.
	 *
	 * @return string[]
	 */
	private function get_stacking_types() {
		/**
		 * Filters the field types that force their row to stack.
		 *
		 * @param string[] $types Field type slugs.
		 */
		return array_values( array_filter( array_map( 'sanitize_key', (array) apply_filters(
			'shapeforms_conversational_stacking_types',
			array( 'name', 'address', 'file' )
		) ) ) );
	}

	/**
	 * Builds the inline custom-property declaration for author colours.
	 *
	 * Emitted on the chrome element so the questions — which the engine moves
	 * inside it — inherit them. An empty value means "inherit the form's
	 * existing style", so the property is omitted rather than set to nothing.
	 *
	 * @param array<string, mixed> $settings Resolved form settings.
	 * @return string
	 */
	private function build_custom_properties( $settings ) {
		$map = array(
			'cv_bg'             => '--bfc-bg',
			'cv_question_color' => '--bfc-question',
			'cv_answer_color'   => '--bfc-answer',
			'cv_btn_color'      => '--bfc-btn',
			'cv_btn_text_color' => '--bfc-btn-text',
			'cv_accent'         => '--bfc-accent',
			// Only cv_bg, cv_question_color and cv_answer_color are ALSO
			// screen-level overrides (see build_cv_colour_style()). Everything
			// else in this map belongs to the wrapper's own chrome — the
			// progress row, the nav, the hint — which sits outside every screen,
			// so a per-screen value could never reach it.
			'cv_track_color'    => '--bfc-track',
			'cv_prev_color'     => '--bfc-prev',
			'cv_prev_bg'        => '--bfc-prev-bg',
			'cv_hint_color'     => '--bfc-hint',
		);

		$parts     = array();
		$hint_set  = false;

		foreach ( $map as $key => $property ) {
			if ( empty( $settings[ $key ] ) ) {
				continue;
			}

			// Re-validate on output: only a literal hex colour can ever reach the
			// style attribute, whatever route the value took into the row.
			$colour = sanitize_hex_color( (string) $settings[ $key ] );

			if ( $colour ) {
				$parts[] = $property . ':' . $colour;

				if ( 'cv_hint_color' === $key ) {
					$hint_set = true;
				}
			}
		}

		// The hint renders dimmed so it does not compete with the button beside
		// it, and that dimming is what makes an author's chosen colour come out
		// wrong — pick red, get pink. Cancelling it here, and only when a colour
		// actually survived sanitising above, is what makes the control honest:
		// the hint is exactly the colour picked, while a form that sets none is
		// byte-identical to before this setting existed.
		//
		// Set from the loop rather than re-tested, so there is one rule for
		// "this colour is in effect" and the two cannot drift. Not a colour, so
		// it cannot be a map entry; the literal is this file's, never an author's.
		if ( $hint_set ) {
			$parts[] = '--bfc-hint-opacity:1';
		}

		return implode( ';', $parts );
	}
}
