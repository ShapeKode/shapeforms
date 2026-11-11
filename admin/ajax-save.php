<?php
/**
 * AJAX save handler for builder forms.
 *
 * @package ShapeFormsLite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Saves form definitions via AJAX.
 */
class ShapeForms_Lite_Ajax_Save {

	/**
	 * Main plugin instance.
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
	 * AJAX callback for saving a form.
	 *
	 * @return void
	 */
	public function save_form() {
		check_ajax_referer( 'shapeforms_lite_save_form', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You are not allowed to perform this action.', 'shapeforms' ),
				),
				403
			);
		}

		$form_id           = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0;
		$title             = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw JSON string; sanitize_text_field() would strip HTML used in terms/content fields. Every decoded value is individually sanitized below via sanitize_text_field(), sanitize_key(), wp_kses_post(), absint(), etc.
		$structure_raw     = isset( $_POST['structure'] ) ? wp_unslash( $_POST['structure'] ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- same as above; decoded values sanitized in normalize_form_settings().
		$settings_raw      = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : '';
		$payload           = json_decode( $structure_raw, true );
		$settings_payload  = json_decode( $settings_raw, true );

		if ( ! is_array( $payload ) ) {
			$payload = array();
		}
		if ( ! is_array( $settings_payload ) ) {
			$settings_payload = array();
		}

		$prepared_settings = self::normalize_form_settings( $settings_payload );

		// An empty form (no rows / no fields) is a valid, intentional state: a user
		// must be able to clear a form down to nothing and have that persist on
		// reload. Previously the save was rejected here, so the old fields silently
		// survived in the DB and "came back" after a refresh. prepare_rows()
		// tolerates a missing/empty `rows` key (returns an empty array), and the
		// renderer skips a field-less form gracefully (returns ''), so storing an
		// empty structure is safe.
		$prepared_rows = self::prepare_rows( $payload );

		global $wpdb;

		$table_name = esc_sql( $this->plugin->get_forms_table_name() );
		$data       = array(
			'title'         => $title ? $title : __( 'Untitled Form', 'shapeforms' ),
			'status'        => 'publish',
			'fields_json'   => wp_json_encode(
				array(
					'rows' => $prepared_rows,
				)
			),
			'settings_json' => wp_json_encode( $prepared_settings ),
		);
		$formats    = array( '%s', '%s', '%s', '%s' );

		if ( $form_id > 0 ) {
			// Guard against a phantom "saved" success for a non-existent form id
			// ($wpdb->update returns 0 — not false — when no row matches).
			// Table name is from esc_sql( get_forms_table_name() ); the id is bound via %d.
			$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table_name}` WHERE id = %d", $form_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( ! $exists ) {
				wp_send_json_error(
					array(
						'message' => __( 'The form you are trying to update no longer exists.', 'shapeforms' ),
					),
					404
				);
			}

			$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$table_name,
				$data,
				array( 'id' => $form_id ),
				$formats,
				array( '%d' )
			);

			if ( false === $updated ) {
				$message = __( 'Unable to update the form.', 'shapeforms' );

				// Only expose the raw DB error while debugging — never leak schema/SQL
				// internals to the browser in production.
				if ( ! empty( $wpdb->last_error ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					$message = sprintf(
						/* translators: %s: database error message */
						__( 'Unable to update the form. Database error: %s', 'shapeforms' ),
						sanitize_text_field( $wpdb->last_error )
					);
				}

				wp_send_json_error(
					array(
						'message' => $message,
					),
					500
				);
			}
		} else {
			$data['created_by'] = get_current_user_id();
			$formats[]          = '%d';

			$inserted = $wpdb->insert( $table_name, $data, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

			if ( false === $inserted ) {
				$message = __( 'Unable to save the form.', 'shapeforms' );

				// Only expose the raw DB error while debugging — never leak schema/SQL
				// internals to the browser in production.
				if ( ! empty( $wpdb->last_error ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					$message = sprintf(
						/* translators: %s: database error message */
						__( 'Unable to save the form. Database error: %s', 'shapeforms' ),
						sanitize_text_field( $wpdb->last_error )
					);
				}

				wp_send_json_error(
					array(
						'message' => $message,
					),
					500
				);
			}

			$form_id = (int) $wpdb->insert_id;
		}

		/**
		 * Fires after a form is successfully saved via the builder.
		 *
		 * Pro can use this to sync to external CRM, invalidate caches, etc.
		 *
		 * @param int    $form_id Form ID (newly inserted or updated).
		 * @param array<string, mixed> $data    Saved data array (title, fields_json, settings_json).
		 * @param bool   $is_new  True when a new form was created, false on update.
		 */
		do_action( 'shapeforms_form_saved', $form_id, $data, ! isset( $_POST['form_id'] ) || 0 === absint( $_POST['form_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above.

		wp_send_json_success(
			array(
				'formId'  => $form_id,
				'message' => __( 'Form saved successfully.', 'shapeforms' ),
			)
		);
	}

	/**
	 * Sanitizes the conversational per-screen background media keys.
	 *
	 * Identical on a row and on a field, because a conversational screen is
	 * sometimes one and sometimes the other: a multi-column row is one screen,
	 * so the ROW owns the image; a single-column row produces one screen per
	 * field, so each FIELD owns its own. Whichever is the screen is the one
	 * that gets read at render time — the other is simply ignored.
	 *
	 * Always returns all six keys, so a stored row or field has a complete,
	 * predictable shape and a re-save is byte-stable.
	 *
	 * An attachment ID is stored, never a URL: a URL breaks when the site moves
	 * and cannot produce responsive sources.
	 *
	 * @param array<string, mixed>|mixed $source Raw row or field from the request.
	 * @return array<string, mixed> The seven sanitized cv_media_* keys.
	 */
	private static function sanitize_cv_media( $source ) {
		$source = is_array( $source ) ? $source : array();

		// (int) and not absint() on the percentages: absint() takes the ABSOLUTE
		// value, so -5 came back as 5 — a sign flip wearing a clamp's clothes.
		// The id keeps absint(), which is the WordPress convention for one.
		return array(
			'cv_media_id'         => isset( $source['cv_media_id'] ) ? absint( $source['cv_media_id'] ) : 0,
			'cv_media_layout'     => isset( $source['cv_media_layout'] ) && in_array( $source['cv_media_layout'], array( 'none', 'left', 'right', 'background' ), true ) ? $source['cv_media_layout'] : 'none',
			'cv_media_x'          => isset( $source['cv_media_x'] ) ? max( 0, min( 100, (int) $source['cv_media_x'] ) ) : 50,
			'cv_media_y'          => isset( $source['cv_media_y'] ) ? max( 0, min( 100, (int) $source['cv_media_y'] ) ) : 50,
			'cv_media_brightness' => isset( $source['cv_media_brightness'] ) ? max( 0, min( 100, (int) $source['cv_media_brightness'] ) ) : 100,
			// 0 means USE THE DEFAULT, and is the only value below the minimum
			// that survives: anything else out of range clamps into it. Storing a
			// resolved default instead would pin today's number into every screen
			// and stop them following the stylesheet if it ever changes.
			'cv_media_height'     => isset( $source['cv_media_height'] ) && (int) $source['cv_media_height'] > 0
				? max( 80, min( 2000, (int) $source['cv_media_height'] ) )
				: 0,
			'cv_media_alt'        => isset( $source['cv_media_alt'] ) ? sanitize_text_field( (string) $source['cv_media_alt'] ) : '',
		);
	}

	/**
	 * Sanitizes the conversational per-screen colour overrides.
	 *
	 * Same six names the FORM settings use, deliberately. They live in different
	 * places — `fields_json` rows/fields versus `settings_json` — and never in
	 * the same array, and the identical names make the inheritance obvious at
	 * every layer: the form value is the default, a screen value overrides it.
	 *
	 * `''` means INHERIT, never "no colour". The render layer omits the custom
	 * property entirely for an empty value, so the form's own value cascades in
	 * untouched. Storing a resolved copy of the form colour instead would pin it
	 * — twenty screens would silently keep the old value the day the form
	 * default changed, which is the whole failure this design avoids.
	 *
	 * Placed on whichever of row or field IS the screen, exactly as
	 * sanitize_cv_media() is.
	 *
	 * @param array<string, mixed>|mixed $source Raw row or field from the request.
	 * @return array<string, mixed> The three sanitized cv_* colour keys.
	 */
	private static function sanitize_cv_colours( $source ) {
		$source = is_array( $source ) ? $source : array();
		$out    = array();

		foreach ( array( 'cv_bg', 'cv_question_color', 'cv_answer_color' ) as $key ) {
			// sanitize_hex_color() returns null for anything that is not a
			// literal hex colour, including ''. Both collapse to '' = inherit.
			$out[ $key ] = isset( $source[ $key ] ) && sanitize_hex_color( (string) $source[ $key ] )
				? sanitize_hex_color( (string) $source[ $key ] )
				: '';
		}

		return $out;
	}

	/**
	 * Sanitizes a decoded builder structure (rows -> columns -> fields) into the
	 * trusted, allowlisted shape that gets stored in `fields_json`.
	 *
	 * Shared by the AJAX save path and the JSON importer so both apply identical
	 * field-type allowlisting and per-value sanitization (never trust a raw blob).
	 *
	 * @param array<string, mixed>|mixed $payload Decoded structure (expects a 'rows' array).
	 * @return array<int, array<string, mixed>> Prepared rows (empty array when none valid).
	 */
	public static function prepare_rows( $payload ) {
		$allowed_types = array( 'text', 'name', 'email', 'number', 'textarea', 'select', 'multiselect', 'checkbox', 'radio', 'date', 'time', 'tel', 'url', 'captcha', 'section_break', 'terms_conditions', 'file', 'submit', 'input_mask', 'html_editor', 'paragraph', 'numeric', 'address', 'country', 'star_rating', 'slider_range' );

		/**
		 * Filter the list of allowed field types that can be saved.
		 *
		 * Pro can add new field types (payment, signature, repeater, etc.).
		 *
		 * @param array<int, string> $allowed_types Allowed field type keys.
		 */
		$allowed_types = apply_filters( 'shapeforms_allowed_field_types', $allowed_types );

		$prepared_rows = array();
		$seen_ids      = array();

		if ( ! is_array( $payload ) || empty( $payload['rows'] ) || ! is_array( $payload['rows'] ) ) {
			return $prepared_rows;
		}

		// Builder data is stored as rows -> columns -> fields so the renderer can rebuild the layout exactly.
		// Defensive caps keep a hostile/runaway payload from exhausting memory.
		foreach ( array_slice( $payload['rows'], 0, 200 ) as $row ) {
			if ( empty( $row['columns'] ) || ! is_array( $row['columns'] ) ) {
				continue;
			}

			$prepared_columns = array();

			foreach ( array_slice( $row['columns'], 0, 12 ) as $column ) {
				$width  = isset( $column['width'] ) ? sanitize_text_field( (string) $column['width'] ) : '100%';
				$fields = array();

				// The builder's "Column N Width" is a free-text input, so accept any
				// percentage from 1% to 100% (e.g. 60%), not only the layout presets —
				// otherwise a custom width is silently reset to 100% on save and "lost"
				// after reload. Anything malformed or out of range falls back to 100%.
				if ( preg_match( '/^(\d{1,3}(?:\.\d+)?)%$/', $width, $w_match ) ) {
					$w_num = (float) $w_match[1];
					$width = ( $w_num > 0 && $w_num <= 100 ) ? $w_match[1] . '%' : '100%';
				} else {
					$width = '100%';
				}

				if ( isset( $column['fields'] ) && is_array( $column['fields'] ) ) {
					foreach ( array_slice( $column['fields'], 0, 200 ) as $field ) {
						$field_type = isset( $field['type'] ) ? sanitize_key( (string) $field['type'] ) : '';

						if ( ! in_array( $field_type, $allowed_types, true ) ) {
							continue;
						}

						// Guarantee a unique field id within the form — duplicates break labels,
						// conditional logic and entry keying (last-writer-wins on submit).
						$field_id = ! empty( $field['id'] ) ? sanitize_key( (string) $field['id'] ) : wp_unique_id( 'field_' );
						if ( '' === $field_id || isset( $seen_ids[ $field_id ] ) ) {
							$field_id = wp_unique_id( 'field_' );
						}
						$seen_ids[ $field_id ] = true;

						$options = array();

						if ( isset( $field['options'] ) && is_array( $field['options'] ) ) {
							$options = array_values(
								array_filter(
									array_map(
										static function ( $option ) {
											return sanitize_text_field( (string) ( $option ?? '' ) );
										},
										array_slice( $field['options'], 0, 200 )
									)
								)
							);
						}

						$options_layout = isset( $field['options_layout'] ) && 'inline' === $field['options_layout'] ? 'inline' : 'block';
						$checkbox_style = isset( $field['checkbox_style'] ) && 'switch' === $field['checkbox_style'] ? 'switch' : 'default';

						// Normalize each field before saving so the frontend only has to render trusted values.
						$core_field = array(
							'id'             => $field_id,
							'type'           => $field_type,
							'label'          => isset( $field['label'] ) ? sanitize_text_field( (string) $field['label'] ) : '',
							'placeholder'    => isset( $field['placeholder'] ) ? sanitize_text_field( (string) $field['placeholder'] ) : '',
							'default_value'  => isset( $field['default_value'] ) ? sanitize_text_field( (string) $field['default_value'] ) : '',
							'required'       => ! empty( $field['required'] ),
							'options'        => $options,
							// Positional: entry N is option N's icon. Sanitized against the
							// option count so a removed option cannot leave an icon behind
							// on whatever option takes its place. Only a checkbox or radio
							// has anywhere to put one — a select's options stay list rows
							// whatever the Style says — so nothing else stores any.
							'option_icons'   => in_array( $field_type, array( 'checkbox', 'radio' ), true )
								? shapeforms_lite_sanitize_option_icons( isset( $field['option_icons'] ) ? $field['option_icons'] : array(), count( $options ) )
								: array(),
							'options_layout' => $options_layout,
							'checkbox_style' => $checkbox_style,
							// Per-field Checkbox & Radio treatment. 'inherit' follows the
							// form, which is what every existing form does.
							'choice_style'   => in_array( ( $field['choice_style'] ?? '' ), array( 'default', 'button' ), true ) ? (string) $field['choice_style'] : 'inherit',
							// Per-field Checkbox & Radio overrides. Absent keys mean
							// "inherit the form", so only what was actually set is stored.
							'choice_styles'  => shapeforms_lite_sanitize_choice_style( isset( $field['choice_styles'] ) ? $field['choice_styles'] : array() ),
							'content'        => isset( $field['content'] ) ? wp_kses_post( (string) $field['content'] ) : '',
							'description'    => isset( $field['description'] ) ? sanitize_textarea_field( (string) $field['description'] ) : '',
							'custom_error'   => isset( $field['custom_error'] ) ? sanitize_text_field( (string) $field['custom_error'] ) : '',
							'allowed_types'  => isset( $field['allowed_types'] ) ? sanitize_text_field( (string) $field['allowed_types'] ) : '',
							// '' means "not set — use the site default"; keep it as '' rather than
							// letting absint() flatten it to 0. Both take the same branch in the
							// upload handler, but preserving '' keeps a re-save (and an import,
							// which re-runs this sanitizer) byte-identical to what was stored.
							'max_file_size'  => isset( $field['max_file_size'] ) && '' !== $field['max_file_size'] ? absint( $field['max_file_size'] ) : '',
							'css_class'      => isset( $field['css_class'] ) ? sanitize_html_class( (string) $field['css_class'] ) : '',
							'show_middle_name'  => ! isset( $field['show_middle_name'] ) || ! empty( $field['show_middle_name'] ),
							'show_last_name'    => ! isset( $field['show_last_name'] ) || ! empty( $field['show_last_name'] ),
							'address_order'     => isset( $field['address_order'] ) && is_array( $field['address_order'] ) ? array_values( array_intersect( array_map( 'sanitize_key', $field['address_order'] ), array( 'street', 'city', 'state', 'zip', 'country' ) ) ) : array( 'street', 'city', 'state', 'zip', 'country' ),
							'address_fields'    => isset( $field['address_fields'] ) && is_array( $field['address_fields'] ) ? array(
								'street'  => ! isset( $field['address_fields']['street'] ) || ! empty( $field['address_fields']['street'] ),
								'city'    => ! isset( $field['address_fields']['city'] ) || ! empty( $field['address_fields']['city'] ),
								'state'   => ! isset( $field['address_fields']['state'] ) || ! empty( $field['address_fields']['state'] ),
								'zip'     => ! isset( $field['address_fields']['zip'] ) || ! empty( $field['address_fields']['zip'] ),
								'country' => ! isset( $field['address_fields']['country'] ) || ! empty( $field['address_fields']['country'] ),
							) : array( 'street' => true, 'city' => true, 'state' => true, 'zip' => true, 'country' => true ),
							'label_placement'   => isset( $field['label_placement'] ) && in_array( $field['label_placement'], array( 'top', 'left', 'right', 'bottom', 'hidden' ), true ) ? $field['label_placement'] : 'top',
							'conditional'       => ( function () use ( $field ) {
								$raw     = isset( $field['conditional'] ) && is_array( $field['conditional'] ) ? $field['conditional'] : array();
								$enabled = ! empty( $raw['enabled'] );
								$action  = isset( $raw['action'] ) && 'hide' === $raw['action'] ? 'hide' : 'show';
								$logic   = isset( $raw['logic'] ) && 'OR' === strtoupper( (string) $raw['logic'] ) ? 'OR' : 'AND';

								$allowed_ops = array( 'is', 'is_not', 'contains', 'not_contains', 'not_empty', 'empty', 'starts_with', 'ends_with', 'greater_than', 'less_than' );
								$conditions  = array();
								if ( isset( $raw['conditions'] ) && is_array( $raw['conditions'] ) ) {
									foreach ( $raw['conditions'] as $cond ) {
										if ( ! is_array( $cond ) ) continue;
										$conditions[] = array(
											'field_id' => isset( $cond['field_id'] ) ? sanitize_key( (string) $cond['field_id'] ) : '',
											'operator' => isset( $cond['operator'] ) && in_array( $cond['operator'], $allowed_ops, true ) ? $cond['operator'] : 'is',
											'value'    => isset( $cond['value'] ) ? sanitize_text_field( (string) $cond['value'] ) : '',
										);
									}
								}
								if ( empty( $conditions ) ) {
									$conditions[] = array( 'field_id' => '', 'operator' => 'is', 'value' => '' );
								}

								return array(
									'enabled'    => $enabled,
									'action'     => $action,
									'logic'      => $logic,
									'conditions' => $conditions,
								);
							} )(),
							'select_searchable' => ! empty( $field['select_searchable'] ),
							'select_multiple'   => ! empty( $field['select_multiple'] ),
							'mask_pattern'    => isset( $field['mask_pattern'] ) ? sanitize_text_field( (string) $field['mask_pattern'] ) : '',
							'min_value'       => isset( $field['min_value'] ) ? sanitize_text_field( (string) $field['min_value'] ) : '',
							'max_value'       => isset( $field['max_value'] ) ? sanitize_text_field( (string) $field['max_value'] ) : '',
							'step_value'      => isset( $field['step_value'] ) ? sanitize_text_field( (string) $field['step_value'] ) : '',
							'max_stars'       => isset( $field['max_stars'] ) ? absint( $field['max_stars'] ) : 5,
							'star_color'      => isset( $field['star_color'] ) && sanitize_hex_color( $field['star_color'] ) ? sanitize_hex_color( $field['star_color'] ) : '',
							'star_inactive_color' => isset( $field['star_inactive_color'] ) && sanitize_hex_color( $field['star_inactive_color'] ) ? sanitize_hex_color( $field['star_inactive_color'] ) : '',
							// An empty string means "not set — use the default", so it must survive
							// as ''. Without the '' check, absint( '' ) is 0 and the clamp raises it
							// to the MINIMUM (16), which is not the default (20) — so clearing the
							// box silently shrinks the stars instead of restoring the default.
							'star_size'       => isset( $field['star_size'] ) && '' !== $field['star_size'] ? max( 16, min( 60, absint( $field['star_size'] ) ) ) : '',
							'slider_color'    => isset( $field['slider_color'] ) && sanitize_hex_color( $field['slider_color'] ) ? sanitize_hex_color( $field['slider_color'] ) : '',
							// Same as star_size: createField() seeds this as '', so WITHOUT the ''
							// check every untouched slider was stored as 2 and rendered a 2px track
							// instead of the 8px CSS default (and clearing the box could never
							// restore it). Matches the btn_radius pattern below.
							'slider_height'   => isset( $field['slider_height'] ) && '' !== $field['slider_height'] ? max( 2, min( 20, absint( $field['slider_height'] ) ) ) : '',
							'dual_handle'     => ! empty( $field['dual_handle'] ),
							'step_title'      => isset( $field['step_title'] ) ? sanitize_text_field( (string) $field['step_title'] ) : '',
							'next_text'       => isset( $field['next_text'] ) ? sanitize_text_field( (string) $field['next_text'] ) : 'Next',
							'prev_text'       => isset( $field['prev_text'] ) ? sanitize_text_field( (string) $field['prev_text'] ) : 'Previous',
							'btn_color'       => isset( $field['btn_color'] ) && sanitize_hex_color( $field['btn_color'] ) ? sanitize_hex_color( $field['btn_color'] ) : '',
							'btn_text_color'  => isset( $field['btn_text_color'] ) && sanitize_hex_color( $field['btn_text_color'] ) ? sanitize_hex_color( $field['btn_text_color'] ) : '',
							'btn_size'        => isset( $field['btn_size'] ) && in_array( $field['btn_size'], array( 'small', 'medium', 'large' ), true ) ? $field['btn_size'] : 'medium',
							'btn_radius'      => isset( $field['btn_radius'] ) && '' !== $field['btn_radius'] ? max( 0, min( 50, absint( $field['btn_radius'] ) ) ) : '',
							'progress_color'  => isset( $field['progress_color'] ) && sanitize_hex_color( $field['progress_color'] ) ? sanitize_hex_color( $field['progress_color'] ) : '',
							'progress_style'  => isset( $field['progress_style'] ) && in_array( $field['progress_style'], array( 'bar', 'steps', 'headings' ), true ) ? $field['progress_style'] : 'bar',
							'auto_populate_key' => isset( $field['auto_populate_key'] ) ? sanitize_key( (string) $field['auto_populate_key'] ) : '',
						);

						/**
						 * Filter extra field keys to persist for non-core field types.
						 *
						 * Pro can hook this to save additional field attributes (e.g. payment
						 * amount, currency) that are not part of the core field schema.
						 *
						 * @param array<string, mixed> $extra      Extra key => sanitized-value pairs (start empty).
						 * @param array<string, mixed> $field      Raw field from the request.
						 * @param string               $field_type Field type slug.
						 */
						$extra_keys = apply_filters( 'shapeforms_field_extra_keys', array(), $field, $field_type );

						// ── Conversational: per-screen background media ────────
						// A FIELD carries the image when the field is the screen,
						// which is every field in a single-column row.
						//
						// The keys live on both the row and the field because a
						// screen is sometimes one and sometimes the other, and
						// the image belongs to whichever it is. The plan
						// originally put them on the row alone, reasoning that
						// "a screen IS a row" — true when it was written, and
						// no longer true once single-column rows started
						// producing one screen per field. One image was then
						// stretched across several steps with no way to vary it.
						//
						// There is no ambiguity to resolve: cvScreens() decides
						// which of the two is the screen, and only that one's
						// image is read. A field's image is ignored on a
						// multi-column row, where the row is the screen.
						$fields[] = array_merge( $core_field, $extra_keys, self::sanitize_cv_media( $field ), self::sanitize_cv_colours( $field ) );
					}
				}

				$prepared_columns[] = array(
					'width'  => $width,
					'fields' => $fields,
				);
			}

			if ( empty( $prepared_columns ) ) {
				continue;
			}

			$prepared_rows[] = array_merge(
				array(
					'css_class' => isset( $row['css_class'] ) ? sanitize_html_class( (string) $row['css_class'] ) : '',
					'columns'   => $prepared_columns,
				),
				// ── Conversational: per-screen background media ────────────────
				// A ROW carries the image when the row is the screen — that is,
				// when it has more than one column. See the note on the field
				// copy of these keys for why both exist.
				self::sanitize_cv_media( $row ),
				self::sanitize_cv_colours( $row )
			);
		}

		return $prepared_rows;
	}

	/**
	 * Determines whether the structure contains at least one field.
	 *
	 * @param array<int, array<string, mixed>> $rows Prepared rows.
	 * @return bool
	 */
	public static function structure_has_fields( $rows ) {
		foreach ( $rows as $row ) {
			if ( empty( $row['columns'] ) || ! is_array( $row['columns'] ) ) {
				continue;
			}

			foreach ( $row['columns'] as $column ) {
				if ( ! empty( $column['fields'] ) && is_array( $column['fields'] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Normalizes submission settings payload.
	 *
	 * @param array<string, mixed>|mixed $settings_payload Raw settings payload.
	 * @return array<string, mixed>
	 */
	public static function normalize_form_settings( $settings_payload ) {
		$defaults = array(
			'submission_type'   => 'ajax',
			'enable_ajax'       => true,
			'enable_redirect'   => false,
			'redirect_url'      => '',
			'thank_you_message' => __( 'Thanks! Your form was submitted successfully.', 'shapeforms' ),
			'button_text'       => __( 'Submit', 'shapeforms' ),
			'button_alignment'  => 'left',
			'button_layout'     => 'below',
			'button_color'      => 'teal',
			'field_style'       => '',
			'field_size'        => '',
			'field_focus_color' => '',
			'field_border_width'=> '',
			'field_border_radius'=> '',
			'field_background_color' => '',
			'field_border_color' => '',
			'field_text_color'  => '',
			'label_size'        => '',
			'label_color'       => '',
			'label_subtext_color' => '',
			'error_color'       => '',
			'button_size'       => '',
			'button_border_style' => '',
			'button_border_width' => '',
			'button_border_radius' => '',
			'button_background_color' => '',
			'button_border_color' => '',
			'button_text_color' => '',
			'style'             => array(),
			'admin_email_type'  => 'site_admin',
			'enable_admin_email'=> true,
			'enable_user_email' => true,
			'admin_email'       => '',
			// Conversational mode. Off by default so an existing form renders
			// byte-identically until the author opts in.
			'cv_enabled'          => false,
			'cv_progress'         => 'bar',
			'cv_transition'       => 'slide',
			'cv_key_hint'         => true,
			'cv_next_text'        => '',
			'cv_prev_text'        => '',
			'cv_bg'               => '',
			'cv_question_color'   => '',
			'cv_answer_color'     => '',
			'cv_btn_color'        => '',
			'cv_btn_text_color'   => '',
			'cv_accent'           => '',
			// The progress bar's track and the dots not yet reached. Form-level
			// only, unlike the six above: the progress row lives on the wrapper,
			// outside every screen, so a per-screen value could never apply.
			'cv_track_color'      => '',
			// The Back button: one colour for its text AND its border, which are
			// the same by design, plus its own background. Both fall back to the
			// Button colour and to transparent, which is how it rendered before
			// either existed.
			'cv_prev_color'       => '',
			'cv_prev_bg'          => '',
			// The "press Enter" hint. Form-level for the same reason as the two
			// above — it sits in the nav row, outside every screen. Unset, it is
			// a dimmed version of the answer colour, which is what keeps it
			// legible on a dark screen as well as a light one.
			'cv_hint_color'       => '',
			'cv_nav_align'        => 'left',
			// Only the inline indicators can be aligned; a bar is full width.
			'cv_progress_align'   => 'left',
			'cv_welcome_enabled'  => false,
			'cv_welcome_title'    => '',
			'cv_welcome_text'     => '',
			'cv_welcome_btn'      => '',
			'cv_media_hide_mobile' => true,
		);

		if ( ! is_array( $settings_payload ) ) {
			return $defaults;
		}

		$submission_type = isset( $settings_payload['submission_type'] ) && in_array( $settings_payload['submission_type'], array( 'ajax', 'redirect' ), true )
			? $settings_payload['submission_type']
			: ( ! empty( $settings_payload['enable_redirect'] ) ? 'redirect' : 'ajax' );
		$admin_email_type = isset( $settings_payload['admin_email_type'] ) && in_array( $settings_payload['admin_email_type'], array( 'site_admin', 'custom' ), true )
			? $settings_payload['admin_email_type']
			: ( ! empty( $settings_payload['admin_email'] ) ? 'custom' : 'site_admin' );
		$admin_email = isset( $settings_payload['admin_email'] ) ? sanitize_email( (string) $settings_payload['admin_email'] ) : '';

		$redirect_type = isset( $settings_payload['redirect_type'] ) && in_array( $settings_payload['redirect_type'], array( 'page', 'custom' ), true )
			? $settings_payload['redirect_type']
			: ( ! empty( $settings_payload['redirect_url'] ) ? 'custom' : 'page' );

		$normalized = array(
			'submission_type'   => $submission_type,
			'enable_ajax'       => 'ajax' === $submission_type,
			'enable_redirect'   => 'redirect' === $submission_type,
			'redirect_type'     => $redirect_type,
			// Persist a redirect URL only in redirect mode; AJAX/message mode always
			// stores an empty URL so switching back to AJAX can't leave a stale redirect.
			'redirect_url'      => 'redirect' === $submission_type && isset( $settings_payload['redirect_url'] ) && '' !== $settings_payload['redirect_url'] ? esc_url_raw( (string) $settings_payload['redirect_url'] ) : '',
			// Authored in a rich editor and rendered as markup, so the message is filtered
			// with the post allowlist rather than flattened to plain text. Every read path
			// applies the same filter, so markup reaching the row another way is still
			// filtered before it is output.
			'thank_you_message' => isset( $settings_payload['thank_you_message'] ) ? wp_kses_post( (string) $settings_payload['thank_you_message'] ) : $defaults['thank_you_message'],
			'button_text'       => isset( $settings_payload['button_text'] ) ? sanitize_text_field( (string) $settings_payload['button_text'] ) : $defaults['button_text'],
			'button_alignment'  => isset( $settings_payload['button_alignment'] ) && in_array( $settings_payload['button_alignment'], array( 'left', 'center', 'right' ), true ) ? $settings_payload['button_alignment'] : $defaults['button_alignment'],
			'button_layout'     => isset( $settings_payload['button_layout'] ) && in_array( $settings_payload['button_layout'], array( 'below', 'inline' ), true ) ? $settings_payload['button_layout'] : $defaults['button_layout'],
			'button_icon_type'     => isset( $settings_payload['button_icon_type'] ) && in_array( $settings_payload['button_icon_type'], array( 'none', 'dashicon', 'svg' ), true ) ? $settings_payload['button_icon_type'] : 'none',
			'button_icon_dashicon' => isset( $settings_payload['button_icon_dashicon'] ) ? sanitize_text_field( (string) $settings_payload['button_icon_dashicon'] ) : '',
			'button_icon_svg'      => isset( $settings_payload['button_icon_svg'] ) ? esc_url_raw( (string) $settings_payload['button_icon_svg'] ) : '',
			'button_icon_position' => isset( $settings_payload['button_icon_position'] ) && in_array( $settings_payload['button_icon_position'], array( 'left', 'right' ), true ) ? $settings_payload['button_icon_position'] : 'right',
			'button_icon_gap'      => isset( $settings_payload['button_icon_gap'] ) ? max( 0, min( 30, absint( $settings_payload['button_icon_gap'] ) ) ) : 8,
			'button_icon_size'     => isset( $settings_payload['button_icon_size'] ) ? max( 10, min( 60, absint( $settings_payload['button_icon_size'] ) ) ) : 18,
			'button_icon_color'    => isset( $settings_payload['button_icon_color'] ) && sanitize_hex_color( $settings_payload['button_icon_color'] ) ? sanitize_hex_color( $settings_payload['button_icon_color'] ) : '',
			'button_color'      => isset( $settings_payload['button_color'] ) && in_array( $settings_payload['button_color'], array( 'teal', 'blue', 'green', 'red', 'dark' ), true ) ? $settings_payload['button_color'] : $defaults['button_color'],
			'field_style'       => isset( $settings_payload['field_style'] ) && in_array( $settings_payload['field_style'], array( 'solid', 'dashed', 'none', 'outline', 'soft', 'minimal' ), true ) ? $settings_payload['field_style'] : '',
			'field_size'        => isset( $settings_payload['field_size'] ) && in_array( $settings_payload['field_size'], array( 'small', 'medium', 'large', 'compact', 'comfortable', 'spacious' ), true ) ? $settings_payload['field_size'] : '',
			'field_focus_color' => isset( $settings_payload['field_focus_color'] ) && in_array( $settings_payload['field_focus_color'], array( 'teal', 'blue', 'green', 'dark' ), true ) ? $settings_payload['field_focus_color'] : '',
			'field_border_width'=> isset( $settings_payload['field_border_width'] ) && '' !== $settings_payload['field_border_width'] ? max( 0, min( 10, absint( $settings_payload['field_border_width'] ) ) ) : '',
			'field_border_radius'=> isset( $settings_payload['field_border_radius'] ) && '' !== $settings_payload['field_border_radius'] ? max( 0, min( 50, absint( $settings_payload['field_border_radius'] ) ) ) : '',
			'field_background_color' => isset( $settings_payload['field_background_color'] ) && sanitize_hex_color( $settings_payload['field_background_color'] ) ? sanitize_hex_color( $settings_payload['field_background_color'] ) : '',
			'field_border_color' => isset( $settings_payload['field_border_color'] ) && sanitize_hex_color( $settings_payload['field_border_color'] ) ? sanitize_hex_color( $settings_payload['field_border_color'] ) : '',
			'field_text_color'  => isset( $settings_payload['field_text_color'] ) && sanitize_hex_color( $settings_payload['field_text_color'] ) ? sanitize_hex_color( $settings_payload['field_text_color'] ) : '',
			'label_size'        => isset( $settings_payload['label_size'] ) && in_array( $settings_payload['label_size'], array( 'small', 'medium', 'large' ), true ) ? $settings_payload['label_size'] : '',
			'label_color'       => isset( $settings_payload['label_color'] ) && sanitize_hex_color( $settings_payload['label_color'] ) ? sanitize_hex_color( $settings_payload['label_color'] ) : '',
			'label_subtext_color' => isset( $settings_payload['label_subtext_color'] ) && sanitize_hex_color( $settings_payload['label_subtext_color'] ) ? sanitize_hex_color( $settings_payload['label_subtext_color'] ) : '',
			'error_color'       => isset( $settings_payload['error_color'] ) && sanitize_hex_color( $settings_payload['error_color'] ) ? sanitize_hex_color( $settings_payload['error_color'] ) : '',
			'button_size'       => isset( $settings_payload['button_size'] ) && in_array( $settings_payload['button_size'], array( 'small', 'medium', 'large' ), true ) ? $settings_payload['button_size'] : '',
			'button_border_style' => isset( $settings_payload['button_border_style'] ) && in_array( $settings_payload['button_border_style'], array( 'solid', 'dashed', 'none' ), true ) ? $settings_payload['button_border_style'] : '',
			'button_border_width' => isset( $settings_payload['button_border_width'] ) && '' !== $settings_payload['button_border_width'] ? max( 0, min( 10, absint( $settings_payload['button_border_width'] ) ) ) : '',
			'button_border_radius' => isset( $settings_payload['button_border_radius'] ) && '' !== $settings_payload['button_border_radius'] ? max( 0, min( 50, absint( $settings_payload['button_border_radius'] ) ) ) : '',
			'button_background_color' => isset( $settings_payload['button_background_color'] ) && sanitize_hex_color( $settings_payload['button_background_color'] ) ? sanitize_hex_color( $settings_payload['button_background_color'] ) : '',
			'button_border_color' => isset( $settings_payload['button_border_color'] ) && sanitize_hex_color( $settings_payload['button_border_color'] ) ? sanitize_hex_color( $settings_payload['button_border_color'] ) : '',
			'button_text_color' => isset( $settings_payload['button_text_color'] ) && sanitize_hex_color( $settings_payload['button_text_color'] ) ? sanitize_hex_color( $settings_payload['button_text_color'] ) : '',
			'style'             => self::normalize_style_settings( isset( $settings_payload['style'] ) ? $settings_payload['style'] : array() ),
			'admin_email_type'  => $admin_email_type,
			'enable_admin_email'=> isset( $settings_payload['enable_admin_email'] ) ? (bool) $settings_payload['enable_admin_email'] : $defaults['enable_admin_email'],
			'enable_user_email' => isset( $settings_payload['enable_user_email'] ) ? (bool) $settings_payload['enable_user_email'] : $defaults['enable_user_email'],
			'admin_email'       => 'custom' === $admin_email_type ? $admin_email : '',
			// Multi-step settings (saved by Pro's builder UI, passed through for Pro's rendering).
			'step_progress_style' => isset( $settings_payload['step_progress_style'] ) && in_array( $settings_payload['step_progress_style'], array( 'bar', 'steps', 'headings' ), true ) ? $settings_payload['step_progress_style'] : 'bar',
			'step_progress_color' => isset( $settings_payload['step_progress_color'] ) && sanitize_hex_color( $settings_payload['step_progress_color'] ) ? sanitize_hex_color( $settings_payload['step_progress_color'] ) : '',
			'step_progress_bg_color' => isset( $settings_payload['step_progress_bg_color'] ) && sanitize_hex_color( $settings_payload['step_progress_bg_color'] ) ? sanitize_hex_color( $settings_payload['step_progress_bg_color'] ) : '',
			'step_btn_color'      => isset( $settings_payload['step_btn_color'] ) && sanitize_hex_color( $settings_payload['step_btn_color'] ) ? sanitize_hex_color( $settings_payload['step_btn_color'] ) : '',
			'step_btn_text_color' => isset( $settings_payload['step_btn_text_color'] ) && sanitize_hex_color( $settings_payload['step_btn_text_color'] ) ? sanitize_hex_color( $settings_payload['step_btn_text_color'] ) : '',
			'step_btn_size'       => isset( $settings_payload['step_btn_size'] ) && in_array( $settings_payload['step_btn_size'], array( 'small', 'medium', 'large' ), true ) ? $settings_payload['step_btn_size'] : 'medium',
			'step_btn_radius'     => isset( $settings_payload['step_btn_radius'] ) && '' !== $settings_payload['step_btn_radius'] ? max( 0, min( 50, absint( $settings_payload['step_btn_radius'] ) ) ) : '',
			'step_next_text'      => isset( $settings_payload['step_next_text'] ) ? sanitize_text_field( (string) $settings_payload['step_next_text'] ) : 'Next',
			'step_prev_text'      => isset( $settings_payload['step_prev_text'] ) ? sanitize_text_field( (string) $settings_payload['step_prev_text'] ) : 'Previous',
			'design_theme'        => isset( $settings_payload['design_theme'] ) ? sanitize_key( (string) $settings_payload['design_theme'] ) : '',
			'hide_labels'         => ! empty( $settings_payload['hide_labels'] ),
			'hide_placeholders'   => ! empty( $settings_payload['hide_placeholders'] ),
			// Checkbox/radio presentation: 'default' (box + label) or 'button'
			// (the label becomes a selectable pill). Strict two-value allowlist —
			// anything else collapses to 'default', so an unexpected value can
			// never reach the class the renderer emits.
			'choice_style'        => isset( $settings_payload['choice_style'] ) && 'button' === $settings_payload['choice_style'] ? 'button' : 'default',
			'dup_enabled'         => ! empty( $settings_payload['dup_enabled'] ),
			'dup_method'          => isset( $settings_payload['dup_method'] ) && in_array( $settings_payload['dup_method'], array( 'email', 'ip', 'field' ), true ) ? $settings_payload['dup_method'] : 'email',
			'dup_field_id'        => isset( $settings_payload['dup_field_id'] ) ? sanitize_key( (string) $settings_payload['dup_field_id'] ) : '',
			'dup_message'         => isset( $settings_payload['dup_message'] ) && '' !== trim( (string) $settings_payload['dup_message'] ) ? sanitize_textarea_field( (string) $settings_payload['dup_message'] ) : '',
			// ── Conversational mode ────────────────────────────────────────────
			// Presentation only: these never alter the stored form structure, so
			// toggling cv_enabled off restores the ordinary render exactly. Keys
			// persist while inactive so a form can be switched back and forth
			// without losing its conversational design.
			'cv_enabled'          => ! empty( $settings_payload['cv_enabled'] ),
			// sanitize_title() also lowercases and strips accents, so the stored
			// slug always matches what the rewrite rule will receive.
			'cv_progress'         => isset( $settings_payload['cv_progress'] ) && in_array( $settings_payload['cv_progress'], array( 'bar', 'dots', 'counter', 'percent', 'none' ), true ) ? $settings_payload['cv_progress'] : $defaults['cv_progress'],
			'cv_transition'       => isset( $settings_payload['cv_transition'] ) && in_array( $settings_payload['cv_transition'], array( 'slide', 'fade', 'none' ), true ) ? $settings_payload['cv_transition'] : $defaults['cv_transition'],
			// Absent means "not sent by this payload" — default on. Present and
			// falsy means the author unchecked it.
			'cv_key_hint'         => isset( $settings_payload['cv_key_hint'] ) ? ! empty( $settings_payload['cv_key_hint'] ) : $defaults['cv_key_hint'],
			'cv_next_text'        => isset( $settings_payload['cv_next_text'] ) ? sanitize_text_field( (string) $settings_payload['cv_next_text'] ) : '',
			'cv_prev_text'        => isset( $settings_payload['cv_prev_text'] ) ? sanitize_text_field( (string) $settings_payload['cv_prev_text'] ) : '',
			'cv_bg'               => isset( $settings_payload['cv_bg'] ) && sanitize_hex_color( $settings_payload['cv_bg'] ) ? sanitize_hex_color( $settings_payload['cv_bg'] ) : '',
			'cv_question_color'   => isset( $settings_payload['cv_question_color'] ) && sanitize_hex_color( $settings_payload['cv_question_color'] ) ? sanitize_hex_color( $settings_payload['cv_question_color'] ) : '',
			'cv_answer_color'     => isset( $settings_payload['cv_answer_color'] ) && sanitize_hex_color( $settings_payload['cv_answer_color'] ) ? sanitize_hex_color( $settings_payload['cv_answer_color'] ) : '',
			'cv_btn_color'        => isset( $settings_payload['cv_btn_color'] ) && sanitize_hex_color( $settings_payload['cv_btn_color'] ) ? sanitize_hex_color( $settings_payload['cv_btn_color'] ) : '',
			'cv_btn_text_color'   => isset( $settings_payload['cv_btn_text_color'] ) && sanitize_hex_color( $settings_payload['cv_btn_text_color'] ) ? sanitize_hex_color( $settings_payload['cv_btn_text_color'] ) : '',
			'cv_accent'           => isset( $settings_payload['cv_accent'] ) && sanitize_hex_color( $settings_payload['cv_accent'] ) ? sanitize_hex_color( $settings_payload['cv_accent'] ) : '',
			'cv_track_color'      => isset( $settings_payload['cv_track_color'] ) && sanitize_hex_color( $settings_payload['cv_track_color'] ) ? sanitize_hex_color( $settings_payload['cv_track_color'] ) : '',
			'cv_prev_color'       => isset( $settings_payload['cv_prev_color'] ) && sanitize_hex_color( $settings_payload['cv_prev_color'] ) ? sanitize_hex_color( $settings_payload['cv_prev_color'] ) : '',
			'cv_prev_bg'          => isset( $settings_payload['cv_prev_bg'] ) && sanitize_hex_color( $settings_payload['cv_prev_bg'] ) ? sanitize_hex_color( $settings_payload['cv_prev_bg'] ) : '',
			'cv_hint_color'       => isset( $settings_payload['cv_hint_color'] ) && sanitize_hex_color( $settings_payload['cv_hint_color'] ) ? sanitize_hex_color( $settings_payload['cv_hint_color'] ) : '',
			'cv_nav_align'        => isset( $settings_payload['cv_nav_align'] ) && in_array( $settings_payload['cv_nav_align'], array( 'left', 'center', 'split', 'right' ), true ) ? $settings_payload['cv_nav_align'] : $defaults['cv_nav_align'],
			'cv_progress_align'   => isset( $settings_payload['cv_progress_align'] ) && in_array( $settings_payload['cv_progress_align'], array( 'left', 'center', 'right' ), true ) ? $settings_payload['cv_progress_align'] : $defaults['cv_progress_align'],
			'cv_welcome_enabled'  => ! empty( $settings_payload['cv_welcome_enabled'] ),
			'cv_welcome_title'    => isset( $settings_payload['cv_welcome_title'] ) ? sanitize_text_field( (string) $settings_payload['cv_welcome_title'] ) : '',
			// Authored as a short paragraph and rendered as markup, so it is
			// filtered with the post allowlist rather than flattened — matching
			// how thank_you_message is handled.
			'cv_welcome_text'     => isset( $settings_payload['cv_welcome_text'] ) ? wp_kses_post( (string) $settings_payload['cv_welcome_text'] ) : '',
			'cv_welcome_btn'      => isset( $settings_payload['cv_welcome_btn'] ) ? sanitize_text_field( (string) $settings_payload['cv_welcome_btn'] ) : '',
			// Default ON: a decorative image is the first thing worth dropping on
			// a phone, both for layout and for bandwidth.
			'cv_media_hide_mobile' => isset( $settings_payload['cv_media_hide_mobile'] ) ? ! empty( $settings_payload['cv_media_hide_mobile'] ) : $defaults['cv_media_hide_mobile'],
		);

		/**
		 * Filter extra form-level settings to persist alongside core settings.
		 *
		 * Pro modules can hook here to save their own form-level config keys
		 * (e.g. webhooks, integrations). Each returned key/value is merged into
		 * the final settings array that gets stored as JSON in the DB.
		 *
		 * @param array<string, mixed> $extra           Extra settings to merge (start empty).
		 * @param array<string, mixed> $settings_payload Raw settings payload from the request.
		 */
		$extra = (array) apply_filters( 'shapeforms_form_settings_extra', array(), $settings_payload );

		return array_merge( $normalized, $extra );
	}

	/**
	 * Normalizes the advanced (responsive) style settings written by the Style tab.
	 *
	 * Shape: { desktop: { "--bf-*": value, … }, tablet: {…}, mobile: {…} }. Each
	 * device map holds finished CSS custom-property values keyed by the literal
	 * property name. Every value is validated against a strict safe grammar
	 * (see sanitize_css_value()); anything unrecognised is dropped. Var names must
	 * match the ShapeForms namespace, so a hostile key can never set arbitrary
	 * properties. This keeps the per-form <style> block injection-safe even though
	 * the plugin ships to WordPress.org.
	 *
	 * @param mixed $payload Raw style payload.
	 * @return array<string, array<string, string>> Sanitized { device => { var => value } }.
	 */
	private static function normalize_style_settings( $payload ) {
		$out = array(
			'desktop' => array(),
			'tablet'  => array(),
			'mobile'  => array(),
		);

		if ( ! is_array( $payload ) ) {
			return $out;
		}

		foreach ( array( 'desktop', 'tablet', 'mobile' ) as $device ) {
			if ( empty( $payload[ $device ] ) || ! is_array( $payload[ $device ] ) ) {
				continue;
			}

			foreach ( $payload[ $device ] as $css_var => $value ) {
				if ( ! is_string( $css_var ) || ! preg_match( '/^--bf-[a-z0-9-]+$/', $css_var ) ) {
					continue;
				}

				$clean = self::sanitize_css_value( $value );
				if ( '' !== $clean ) {
					$out[ $device ][ $css_var ] = $clean;
				}
			}
		}

		return $out;
	}

	/**
	 * Validates a single CSS custom-property value against a strict safe grammar.
	 *
	 * Accepts only the character set that legitimately appears in our generated
	 * values — letters, digits, whitespace and `#%().,-_`. Crucially this excludes
	 * `:;{}<>@"'` and `\`, so a value can neither terminate its declaration, close
	 * the rule/`<style>` element, nor smuggle a CSS escape. `url()`, `expression()`,
	 * comments and any non-allowlisted CSS function are rejected outright. This
	 * lets the builder send finished tokens (lengths, hex/rgba colours, 1–4 length
	 * lists, box-shadow strings, linear/radial gradients) while staying safe.
	 *
	 * @param mixed $value Raw value.
	 * @return string The value if it passes, otherwise ''.
	 */
	public static function sanitize_css_value( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = trim( (string) $value );

		if ( '' === $value || strlen( $value ) > 200 ) {
			return '';
		}

		// Hard charset gate: no `:;{}<>@"'` or backslash can appear in a value.
		if ( preg_match( '/[^a-zA-Z0-9#%().,\s_\-]/', $value ) ) {
			return '';
		}

		$lower = strtolower( $value );
		if (
			false !== strpos( $lower, 'url(' ) ||
			false !== strpos( $lower, 'expression' ) ||
			false !== strpos( $lower, 'import' ) ||
			false !== strpos( $lower, '/*' )
		) {
			return '';
		}

		// Only a curated set of CSS functions may appear.
		if ( preg_match_all( '/([a-zA-Z][a-zA-Z-]*)\s*\(/', $value, $matches ) ) {
			$allowed = array( 'rgb', 'rgba', 'hsl', 'hsla', 'linear-gradient', 'radial-gradient', 'calc', 'var', 'color-mix' );
			foreach ( $matches[1] as $fn ) {
				if ( ! in_array( strtolower( $fn ), $allowed, true ) ) {
					return '';
				}
			}
		}

		return $value;
	}
}
