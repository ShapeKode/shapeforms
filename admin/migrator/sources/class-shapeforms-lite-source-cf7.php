<?php
/**
 * Contact Form 7 migration source.
 *
 * Phase 1 implements detection and form listing; field parsing/mapping and the
 * mail/confirmation mapping are added in later phases.
 *
 * @package ShapeFormsLite
 * @since   1.1.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports Contact Form 7 forms into ShapeForms.
 */
class ShapeForms_Lite_Source_CF7 implements ShapeForms_Lite_Migration_Source {

	/**
	 * The CF7 form post type.
	 */
	const POST_TYPE = 'wpcf7_contact_form';

	/**
	 * Machine slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'cf7';
	}

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function get_label() {
		return __( 'Contact Form 7', 'shapeforms' );
	}

	/**
	 * Whether Contact Form 7 (or its surviving data) is present.
	 *
	 * True when CF7 is active (registers its post type) OR its class exists,
	 * so forms can still be migrated from a deactivated CF7 whose data remains.
	 *
	 * @return bool
	 */
	public function is_available() {
		return post_type_exists( self::POST_TYPE ) || class_exists( 'WPCF7_ContactForm' );
	}

	/**
	 * Lists Contact Form 7 forms.
	 *
	 * A light query (id + title only); the tag template is parsed lazily during
	 * import. The "imported" flag is resolved against the migration marker.
	 *
	 * @return array<int, array{id:int, title:string, imported:bool}>
	 */
	public function get_forms() {
		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'numberposts'      => -1,
				'post_status'      => 'any',
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);

		$forms = array();

		foreach ( $posts as $post ) {
			$title   = '' !== trim( (string) $post->post_title )
				? $post->post_title
				/* translators: %d: Contact Form 7 form ID. */
				: sprintf( __( 'Contact Form #%d', 'shapeforms' ), (int) $post->ID );
			$forms[] = array(
				'id'       => (int) $post->ID,
				'title'    => $title,
				'imported' => false,
			);
		}

		return $forms;
	}

	/**
	 * Converts one Contact Form 7 form into a ShapeForms structure.
	 *
	 * @param int|string $source_id CF7 form post ID.
	 * @return array{title:string, rows:array, settings:array, skipped:array<int, string>, source_id:(int|string)}|WP_Error
	 */
	public function import_form( $source_id ) {
		$post_id = (int) $source_id;
		$post    = get_post( $post_id );

		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return new WP_Error(
				'shapeforms_migrator_source_missing',
				__( 'That Contact Form 7 form could not be found.', 'shapeforms' )
			);
		}

		$title = '' !== trim( (string) $post->post_title )
			? $post->post_title
			/* translators: %d: Contact Form 7 form ID. */
			: sprintf( __( 'Contact Form #%d', 'shapeforms' ), $post_id );

		$form_template = (string) get_post_meta( $post_id, '_form', true );
		$tags          = $this->get_normalized_tags( $post_id, $form_template );

		$mapped      = array();
		$skipped     = array();
		$warnings    = array();
		$button_text = '';

		foreach ( $tags as $tag ) {
			if ( ! empty( $tag['is_submit'] ) ) {
				// CF7's submit is a tag; ShapeForms renders its own submit button, so
				// carry only its label across to the button text.
				if ( '' !== $tag['submit_label'] ) {
					$button_text = $tag['submit_label'];
				}
				continue;
			}

			$field = $this->map_tag( $tag, $skipped );

			if ( null !== $field ) {
				if ( ! empty( $tag['recovered'] ) ) {
					$warnings[] = sprintf(
						/* translators: 1: CF7 tag name, e.g. acceptance-required. 2: CF7 tag base type, e.g. acceptance. */
						__( '"%1$s" was imported as an optional field. Its Contact Form 7 tag is written as [%2$s*…], which Contact Form 7 cannot read — there is no starred %2$s tag, so it shows as plain text and is not required there either. To make it required, tick Required on the field here, or drop the * in Contact Form 7 (an %2$s tag is required unless you add the "optional" option) and import again.', 'shapeforms' ),
						'' !== (string) $tag['name'] ? $tag['name'] : $tag['basetype'],
						$tag['basetype']
					);
				}

				$mapped[] = array(
					// -1, not 0: a tag with no recorded line is unlocatable, and 0 is a
					// real first line that build_rows() must be free to group.
					'line'  => isset( $tag['line'] ) ? (int) $tag['line'] : -1,
					'field' => $field,
				);
			}
		}

		// Fields on the same CF7 line become one multi-column ShapeForms row (preserving
		// the side-by-side layout); a field on its own line is a full-width row.
		$rows = $this->build_rows( $mapped );

		$settings = array( 'submission_type' => 'ajax' );

		if ( '' !== $button_text ) {
			$settings['button_text'] = $button_text;
		}

		// Map the recipient + confirmation message from CF7's mail/messages props.
		$settings = array_merge( $settings, $this->map_settings( $post_id, $skipped ) );

		return array(
			'title'     => $title,
			'rows'      => $rows,
			'settings'  => $settings,
			'skipped'   => $skipped,
			'warnings'  => $warnings,
			'source_id' => $post_id,
		);
	}

	/**
	 * Groups mapped fields into rows, side-by-side fields (same CF7 line) into one row.
	 *
	 * @param array<int, array{line:int, field:array<string, mixed>}> $mapped Line-tagged fields in order.
	 * @return array<int, array<string, mixed>> ShapeForms rows.
	 */
	private function build_rows( $mapped ) {
		$rows  = array();
		$count = count( $mapped );
		$i     = 0;

		while ( $i < $count ) {
			$group = array( $mapped[ $i ]['field'] );
			$line  = $mapped[ $i ]['line'];
			$j     = $i + 1;

			// Collect the following fields that share this line. A negative line means
			// the tag could not be located in the template, so it is never grouped —
			// otherwise two unlocatable fields would merge into one row by accident.
			if ( $line >= 0 ) {
				while ( $j < $count && $mapped[ $j ]['line'] === $line ) {
					$group[] = $mapped[ $j ]['field'];
					++$j;
				}
			}

			// More than four on a line would give unreadably narrow columns — stack those.
			if ( count( $group ) > 4 ) {
				foreach ( $group as $single ) {
					$rows[] = $this->make_row( array( $single ) );
				}
			} else {
				$rows[] = $this->make_row( $group );
			}

			$i = $j;
		}

		return $rows;
	}

	/**
	 * Builds one row whose fields are split into equal-width columns.
	 *
	 * @param array<int, array<string, mixed>> $fields Fields for this row.
	 * @return array<string, mixed>
	 */
	private function make_row( $fields ) {
		$width   = $this->column_width( count( $fields ) );
		$columns = array();

		foreach ( $fields as $field ) {
			$columns[] = array(
				'width'  => $width,
				'fields' => array( $field ),
			);
		}

		return array(
			'css_class' => '',
			'columns'   => $columns,
		);
	}

	/**
	 * Equal column width for N columns ("50%", "33.33%", "25%", …).
	 *
	 * @param int $n Column count.
	 * @return string
	 */
	private function column_width( $n ) {
		if ( $n <= 1 ) {
			return '100%';
		}

		$width = rtrim( rtrim( number_format( 100 / $n, 2, '.', '' ), '0' ), '.' );

		return $width . '%';
	}

	/**
	 * Maps CF7's mail recipient and confirmation message into ShapeForms settings.
	 *
	 * Recipient + confirmation message only (matching what Fluent Forms migrates);
	 * a custom email subject/body is a premium capability and is reported, not mapped.
	 *
	 * @param int                $post_id CF7 form post ID.
	 * @param array<int, string> $skipped Accumulating notes (by reference).
	 * @return array<string, mixed> Settings additions.
	 */
	private function map_settings( $post_id, &$skipped ) {
		$mail     = array();
		$messages = array();

		if ( class_exists( 'WPCF7_ContactForm' ) ) {
			$cf7 = WPCF7_ContactForm::get_instance( $post_id );

			if ( $cf7 && method_exists( $cf7, 'prop' ) ) {
				$mail_prop     = $cf7->prop( 'mail' );
				$messages_prop = $cf7->prop( 'messages' );
				$mail          = is_array( $mail_prop ) ? $mail_prop : array();
				$messages      = is_array( $messages_prop ) ? $messages_prop : array();
			}
		}

		// Fallback to raw meta when the API is unavailable (CF7 deactivated).
		if ( empty( $mail ) ) {
			$meta = get_post_meta( $post_id, '_mail', true );
			$mail = is_array( $meta ) ? $meta : array();
		}
		if ( empty( $messages ) ) {
			$meta     = get_post_meta( $post_id, '_messages', true );
			$messages = is_array( $meta ) ? $meta : array();
		}

		$settings = array();

		// Recipient: a bare literal email becomes the custom admin address; a CF7
		// mail-tag (e.g. [_site_admin_email]) or anything else stays the site admin.
		$recipient = isset( $mail['recipient'] ) ? trim( (string) $mail['recipient'] ) : '';

		if ( '' !== $recipient && false === strpos( $recipient, '[' ) && is_email( $recipient ) ) {
			$settings['admin_email_type'] = 'custom';
			$settings['admin_email']      = sanitize_email( $recipient );
		} elseif ( '' !== $recipient && false === strpos( $recipient, '[' ) ) {
			// A literal address list ("sales@x.com, ops@y.com") is not a single valid
			// address, so it cannot be carried over — say so rather than quietly
			// dropping every recipient back to the site administrator.
			$skipped[] = sprintf(
				/* translators: %s: the recipient value configured in Contact Form 7. */
				__( 'The notification goes to more than one address in Contact Form 7 ("%s"). Notifications will go to the site administrator instead — set the address under Form Settings → Email Notification.', 'shapeforms' ),
				$recipient
			);
		}

		// Confirmation message.
		if ( ! empty( $messages['mail_sent_ok'] ) ) {
			$message                       = (string) $messages['mail_sent_ok'];
			$settings['thank_you_message'] = wp_kses_post( $message );

			// CF7 mail-tags like [your-name] are not interpolated by ShapeForms, so a
			// customised message carrying them would show the literal token.
			if ( preg_match( '/\[[a-z_][a-z0-9._-]*\]/i', $message ) ) {
				$skipped[] = __( 'The confirmation message uses Contact Form 7 tags (e.g. [your-name]) that ShapeForms can\'t fill in — edit it under Form Settings → Confirmation.', 'shapeforms' );
			}
		}

		// CF7 always carries a custom email subject/body, which ShapeForms cannot
		// recreate on the free tier — tell the admin where to set it up.
		if ( ! empty( $mail['subject'] ) || ! empty( $mail['body'] ) ) {
			$skipped[] = __( 'Email subject and body were not imported — set them up under Form Settings → Email Notification.', 'shapeforms' );
		}

		return $settings;
	}

	/**
	 * Reads a CF7 form's tags into a normalized, source-path-agnostic shape.
	 *
	 * Prefers CF7's own parser when the plugin is active (robust for pipes and
	 * edge cases); falls back to regex-scanning the `_form` meta so migration
	 * still works when CF7 is deactivated but its data survives.
	 *
	 * @param int    $post_id       CF7 form post ID.
	 * @param string $form_template Raw `_form` meta (tag template).
	 * @return array<int, array<string, mixed>> Normalized tags.
	 */
	private function get_normalized_tags( $post_id, $form_template ) {
		if ( class_exists( 'WPCF7_ContactForm' ) ) {
			$cf7 = WPCF7_ContactForm::get_instance( $post_id );

			if ( $cf7 && method_exists( $cf7, 'scan_form_tags' ) ) {
				return $this->recover_unscanned_tags(
					$this->normalize_api_tags( $cf7->scan_form_tags(), $form_template ),
					$form_template
				);
			}
		}

		return $this->normalize_regex_tags( $form_template );
	}

	/**
	 * Adds back the named fields CF7's own scanner silently dropped.
	 *
	 * CF7 builds its scan regex from the tag types it has REGISTERED, and a type is
	 * only starrable if the starred spelling was registered too. `acceptance` never
	 * was — CF7 treats acceptance as required by default and uses an `optional`
	 * option for the inverse — so `[acceptance* consent]` matches no registered type
	 * at all. CF7 leaves it as literal text on its own front end and scan_form_tags()
	 * never reports it, which meant the field vanished from the import with nothing
	 * said, while `[acceptance consent]` beside it came across fine. It also made the
	 * two scan paths disagree: the regex fallback below (used when CF7 is inactive)
	 * does accept the star, so the same form imported differently depending on
	 * whether CF7 happened to be switched on.
	 *
	 * This is additive — every tag CF7 did parse is kept exactly as it parsed it, and
	 * only a NAMED tag whose base type CF7 actually knows is recovered. Square
	 * brackets in the form's prose, and another plugin's shortcodes, stay ignored
	 * rather than turning into fields or skip notes.
	 *
	 * @param array<int, array<string, mixed>> $api_tags      Tags CF7's scanner returned.
	 * @param string                           $form_template Raw `_form` meta.
	 * @return array<int, array<string, mixed>>
	 */
	private function recover_unscanned_tags( $api_tags, $form_template ) {
		$known = array();

		foreach ( $api_tags as $tag ) {
			if ( empty( $tag['is_submit'] ) && '' !== (string) $tag['name'] ) {
				$known[ (string) $tag['name'] ] = true;
			}
		}

		foreach ( $this->normalize_regex_tags( $form_template ) as $tag ) {
			$name = isset( $tag['name'] ) ? (string) $tag['name'] : '';

			// A nameless construct is one of CF7's own output tags ([response],
			// [count]) — the scanner already reported those, so only a named field
			// can legitimately be missing here.
			if ( ! empty( $tag['is_submit'] ) || '' === $name || isset( $known[ $name ] ) ) {
				continue;
			}

			if ( ! $this->cf7_knows_tag_type( $tag['basetype'] ) ) {
				continue;
			}

			$known[ $name ]   = true;
			$tag['recovered'] = true;
			$api_tags         = $this->insert_by_line( $api_tags, $tag );
		}

		return $api_tags;
	}

	/**
	 * Inserts a recovered tag at its real position in the template.
	 *
	 * Appending would put it after the submit button, and build_rows() groups
	 * consecutive fields that share a line into one row — so document order has to
	 * hold. A tag with no `line` (the submit) sorts last.
	 *
	 * @param array<int, array<string, mixed>> $tags Tags in document order.
	 * @param array<string, mixed>             $tag  The tag to place.
	 * @return array<int, array<string, mixed>>
	 */
	private function insert_by_line( $tags, $tag ) {
		// Clamped at 0: an unlocatable tag (-1) has no meaningful position, so place it
		// with the first line rather than ahead of everything.
		$line = max( 0, isset( $tag['line'] ) ? (int) $tag['line'] : 0 );

		foreach ( $tags as $index => $existing ) {
			$existing_line = isset( $existing['line'] ) ? (int) $existing['line'] : PHP_INT_MAX;

			if ( $existing_line > $line ) {
				array_splice( $tags, $index, 0, array( $tag ) );

				return $tags;
			}
		}

		$tags[] = $tag;

		return $tags;
	}

	/**
	 * Whether CF7 has a form-tag type registered under this base name.
	 *
	 * @param string $basetype Tag base type, e.g. 'acceptance'.
	 * @return bool
	 */
	private function cf7_knows_tag_type( $basetype ) {
		if ( ! class_exists( 'WPCF7_FormTagsManager' ) ) {
			return false;
		}

		$manager = WPCF7_FormTagsManager::get_instance();

		return method_exists( $manager, 'tag_type_exists' ) && $manager->tag_type_exists( (string) $basetype );
	}

	/**
	 * Normalizes WPCF7_FormTag objects into the shared tag shape.
	 *
	 * @param array<int, object> $tags          CF7 form tags.
	 * @param string             $form_template Raw `_form` meta for label extraction.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_api_tags( $tags, $form_template ) {
		$normalized = array();

		foreach ( (array) $tags as $tag ) {
			if ( ! is_object( $tag ) || ! isset( $tag->basetype ) ) {
				continue;
			}

			$basetype = (string) $tag->basetype;
			$values   = isset( $tag->values ) && is_array( $tag->values ) ? array_map( 'strval', $tag->values ) : array();

			if ( 'submit' === $basetype ) {
				$normalized[] = array(
					'is_submit'    => true,
					'submit_label' => isset( $values[0] ) ? $values[0] : '',
				);
				continue;
			}

			$labels = isset( $tag->labels ) && is_array( $tag->labels ) ? array_map( 'strval', $tag->labels ) : array();
			$name   = isset( $tag->name ) ? (string) $tag->name : '';

			$normalized[] = array(
				'is_submit' => false,
				'basetype'  => $basetype,
				'required'  => method_exists( $tag, 'is_required' ) ? (bool) $tag->is_required() : ( isset( $tag->type ) && '*' === substr( (string) $tag->type, -1 ) ),
				'name'      => $name,
				'label'     => $this->extract_label( $form_template, $name ),
				'values'    => $values,
				'labels'    => $labels,
				'options'   => isset( $tag->options ) && is_array( $tag->options ) ? array_map( 'strval', $tag->options ) : array(),
				'consent'   => 'acceptance' === $basetype ? $this->extract_acceptance_text( $form_template, $name ) : '',
				'line'      => $this->tag_line( $form_template, $name ),
			);
		}

		return $normalized;
	}

	/**
	 * Regex-scans a `_form` template into the shared tag shape (CF7-inactive fallback).
	 *
	 * @param string $form_template Raw `_form` meta.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_regex_tags( $form_template ) {
		$normalized = array();

		if ( ! preg_match_all( '/\[([a-z][0-9a-z_]*)(\*?)([^\]]*)\]/i', $form_template, $matches, PREG_SET_ORDER ) ) {
			return $normalized;
		}

		foreach ( $matches as $match ) {
			$basetype = strtolower( $match[1] );
			$required = '*' === $match[2];
			$rest     = trim( $match[3] );

			// Split the remainder into whitespace-separated tokens, keeping quoted
			// strings (option/choice values) intact.
			preg_match_all( '/"[^"]*"|\'[^\']*\'|\S+/', $rest, $token_match );
			$tokens = $token_match[0];

			$name    = '';
			$options = array();
			$values  = array();
			$labels  = array();

			foreach ( $tokens as $index => $token ) {
				$is_quoted = ( '"' === $token[0] || "'" === $token[0] );

				if ( $is_quoted ) {
					$raw = trim( $token, "\"'" );
					// A pipe splits "Label|value"; store the visible label side.
					if ( false !== strpos( $raw, '|' ) ) {
						$parts    = explode( '|', $raw, 2 );
						$labels[] = $parts[0];
						$values[] = $parts[1];
					} else {
						$labels[] = $raw;
						$values[] = $raw;
					}
					continue;
				}

				if ( 0 === $index ) {
					$name = $token;
					continue;
				}

				$options[] = $token;
			}

			if ( 'submit' === $basetype ) {
				$normalized[] = array(
					'is_submit'    => true,
					'submit_label' => isset( $values[0] ) ? $values[0] : '',
				);
				continue;
			}

			$normalized[] = array(
				'is_submit' => false,
				'basetype'  => $basetype,
				'required'  => $required,
				'name'      => $name,
				'label'     => $this->extract_label( $form_template, $name ),
				'values'    => $values,
				'labels'    => $labels,
				'options'   => $options,
				'consent'   => 'acceptance' === $basetype ? $this->extract_acceptance_text( $form_template, $name ) : '',
				'line'      => $this->tag_line( $form_template, $name ),
			);
		}

		return $normalized;
	}

	/**
	 * Maps ONE normalized CF7 tag to a ShapeForms field array, or null to skip it.
	 *
	 * Unsupported tags are appended to $skipped so the admin sees what was left out.
	 *
	 * @param array<string, mixed> $tag     Normalized tag.
	 * @param array<int, string>   $skipped Accumulating skip notes (by reference).
	 * @return array<string, mixed>|null Field array, or null when skipped.
	 */
	private function map_tag( $tag, &$skipped ) {
		$basetype = (string) $tag['basetype'];
		$name     = (string) $tag['name'];
		$label    = '' !== (string) $tag['label'] ? (string) $tag['label'] : $this->humanize( $name );
		$required = ! empty( $tag['required'] );
		$options  = (array) $tag['options'];
		$values   = (array) $tag['values'];
		$labels   = (array) $tag['labels'];

		// Choice display list: prefer the visible labels (pipe left-hand side), else values.
		$choices = ! empty( $labels ) ? $labels : $values;

		// For text-like fields, a quoted value is either a placeholder (when the
		// tag carries the placeholder/watermark option) or a default value.
		$is_placeholder = $this->has_opt( $options, 'placeholder' ) || $this->has_opt( $options, 'watermark' );
		$first_value    = isset( $values[0] ) ? (string) $values[0] : '';
		$placeholder    = $is_placeholder ? $first_value : '';
		$default_value  = ( ! $is_placeholder && '' !== $first_value ) ? $first_value : '';

		switch ( $basetype ) {
			case 'text':
			case 'email':
			case 'url':
			case 'tel':
			case 'textarea':
			case 'date':
				return array(
					'type'          => $basetype,
					'label'         => $label,
					'placeholder'   => $placeholder,
					'default_value' => $default_value,
					'required'      => $required,
				);

			case 'number':
				return array(
					'type'          => 'number',
					'label'         => $label,
					'placeholder'   => $placeholder,
					'default_value' => $default_value,
					'required'      => $required,
					'min_value'     => $this->opt_value( $options, 'min' ),
					'max_value'     => $this->opt_value( $options, 'max' ),
				);

			case 'range':
				return array(
					'type'       => 'slider_range',
					'label'      => $label,
					'required'   => $required,
					'min_value'  => $this->opt_value( $options, 'min' ),
					'max_value'  => $this->opt_value( $options, 'max' ),
					'step_value' => $this->opt_value( $options, 'step' ),
				);

			case 'select':
				// A CF7 multi-select maps to ShapeForms's dedicated "multiselect" type —
				// the "select" renderer only goes multiple when the TYPE is multiselect
				// (a select_multiple flag on a plain select is ignored).
				return array(
					'type'     => $this->has_opt( $options, 'multiple' ) ? 'multiselect' : 'select',
					'label'    => $label,
					'required' => $required,
					'options'  => $choices,
				);

			case 'checkbox':
				return array(
					'type'     => 'checkbox',
					'label'    => $label,
					'required' => $required,
					'options'  => $choices,
				);

			case 'radio':
				return array(
					'type'     => 'radio',
					'label'    => $label,
					'required' => $required,
					'options'  => $choices,
				);

			case 'acceptance':
				// CF7's consent SENTENCE sits between [acceptance]…[/acceptance] (after
				// the tag) — that becomes the checkbox copy (ShapeForms's terms `content`).
				// The wrapping <label> text, if any, is the heading; never humanize the
				// tag name into a heading for a consent field (leave it blank instead).
				$consent = isset( $tag['consent'] ) ? (string) $tag['consent'] : '';
				$heading = (string) $tag['label'];

				if ( '' === $consent ) {
					$consent = '' !== $heading ? $heading : __( 'I agree to the terms.', 'shapeforms' );
				}

				// CF7 acceptance is required by default; the `optional` option makes it
				// submittable unchecked. The star plays NO part — CF7 has no starred
				// acceptance tag, so a recovered `[acceptance* …]` is one CF7 could not
				// parse and therefore does not enforce at all: verified by running its own
				// submission pipeline, where ticking only the plain box sends the form and
				// ticking only the starred one still returns `acceptance_missing`. Marking
				// a recovered tag required would impose an obligation the source form does
				// not have, so it comes in unticked-friendly and the import report explains
				// how to make it required for real.
				return array(
					'type'     => 'terms_conditions',
					'label'    => $heading,
					'content'  => $consent,
					'required' => empty( $tag['recovered'] ) && ! $this->has_opt( $options, 'optional' ),
				);

			case 'file':
				return array(
					'type'          => 'file',
					'label'         => $label,
					'required'      => $required,
					'allowed_types' => $this->normalize_filetypes( $this->opt_value( $options, 'filetypes' ) ),
					'max_file_size' => $this->normalize_limit_mb( $this->opt_value( $options, 'limit' ) ),
				);

			case 'hidden':
				$skipped[] = $this->skip_note( $label, __( 'ShapeForms has no hidden field type.', 'shapeforms' ) );
				return null;

			case 'recaptcha':
				$skipped[] = $this->skip_note( __( 'reCAPTCHA', 'shapeforms' ), __( 'spam protection is configured site-wide in ShapeForms, not as a form field.', 'shapeforms' ) );
				return null;

			case 'quiz':
			case 'captchac':
			case 'captchar':
				$skipped[] = $this->skip_note( '' !== $label ? $label : __( 'CAPTCHA', 'shapeforms' ), __( 'add ShapeForms\'s own CAPTCHA field if you need one.', 'shapeforms' ) );
				return null;

			default:
				$skipped[] = $this->skip_note( '' !== $label ? $label : $name, sprintf( /* translators: %s: CF7 field type. */ __( 'the "%s" field type is not supported yet.', 'shapeforms' ), $basetype ) );
				return null;
		}
	}

	/**
	 * Extracts a field's visible label from the `_form` template.
	 *
	 * Takes the free text immediately preceding the tag within its wrapping
	 * element (usually a `<label>`), falling back to an empty string.
	 *
	 * @param string $form_template Raw `_form` meta.
	 * @param string $name          Field name to locate.
	 * @return string Cleaned label, or '' when none can be derived.
	 */
	private function extract_label( $form_template, $name ) {
		if ( '' === $name || '' === $form_template ) {
			return '';
		}

		if ( ! preg_match( '/\[[a-z][0-9a-z_]*\*?\s+' . preg_quote( $name, '/' ) . '[\s\]\/]/i', $form_template, $match, PREG_OFFSET_CAPTURE ) ) {
			return '';
		}

		$before = substr( $form_template, 0, (int) $match[0][1] );

		// A <br> between the caption and its field is a line break, not a container
		// boundary. `Your name<br>[text* your-name]` is one of the commonest Contact
		// Form 7 layouts, and cutting at that <br>'s ">" threw the caption away and
		// left the field labelled from its tag name instead. Drop any trailing <br>
		// (including <br /> and repeats) before looking for the real boundary.
		$before = (string) preg_replace( '#(?:<br\s*/?>\s*)+$#i', '', $before );

		// Cut at the nearest preceding tag boundary — the end of an HTML tag (>)
		// or the end of the previous CF7 tag (]) — so only this field's own label
		// text remains.
		$cut = max( (int) strrpos( $before, '>' ), (int) strrpos( $before, ']' ) );
		if ( $cut > 0 ) {
			$before = substr( $before, $cut + 1 );
		}

		$label = trim( wp_strip_all_tags( $before ) );
		$label = preg_replace( '/\s+/', ' ', $label );
		$label = rtrim( (string) $label, " \t\n\r:*" );

		return trim( $label );
	}

	/**
	 * Returns the zero-based line number of a tag within the `_form` template.
	 *
	 * Used to group fields that share a line into one multi-column ShapeForms row,
	 * mirroring CF7's side-by-side inline layout.
	 *
	 * @param string $form_template Raw `_form` meta.
	 * @param string $name          Field name to locate.
	 * @return int Line number, or 0 when the tag can't be located.
	 */
	private function tag_line( $form_template, $name ) {
		// -1 means "could not locate", which is NOT the same as line 0. Returning 0
		// for both made build_rows() distrust every genuine first line, so
		// `[text a] [text b]` written on line one was stacked instead of sharing a
		// row — while the identical markup one line lower grouped correctly.
		if ( '' === $name || '' === $form_template ) {
			return -1;
		}

		if ( ! preg_match( '/\[[a-z][0-9a-z_]*\*?\s+' . preg_quote( $name, '/' ) . '[\s\]\/]/i', $form_template, $match, PREG_OFFSET_CAPTURE ) ) {
			return -1;
		}

		return substr_count( substr( $form_template, 0, (int) $match[0][1] ), "\n" );
	}

	/**
	 * Extracts an acceptance field's consent text (between the opening tag and
	 * `[/acceptance]`, or up to the next tag when self-closed).
	 *
	 * @param string $form_template Raw `_form` meta.
	 * @param string $name          Acceptance field name.
	 * @return string Cleaned consent text, or '' when none.
	 */
	private function extract_acceptance_text( $form_template, $name ) {
		if ( '' === $name || '' === $form_template ) {
			return '';
		}

		$quoted = preg_quote( $name, '/' );

		// Prefer the full body up to [/acceptance]; fall back to text up to the next
		// tag for a self-closed acceptance. HTML (e.g. a linked privacy policy) is
		// kept — the terms field's content is wp_kses_post'd downstream.
		// `\*?` matches the invalid-but-common `[acceptance* …]` spelling, whose
		// consent sentence would otherwise be lost and replaced by the generic
		// default — see recover_unscanned_tags() for why that spelling reaches here.
		if ( preg_match( '/\[acceptance\*?\s+' . $quoted . '[^\]]*\](.*?)\[\/acceptance\]/is', $form_template, $match )
			|| preg_match( '/\[acceptance\*?\s+' . $quoted . '[^\]]*\]([^\[]*)/i', $form_template, $match )
		) {
			return trim( (string) preg_replace( '/\s+/', ' ', $match[1] ) );
		}

		return '';
	}

	/**
	 * Derives a readable label from a field name (e.g. "your-message" → "Your message").
	 *
	 * CF7 auto-generates names like "email-167" for unnamed fields; humanizing those
	 * verbatim yields a meaningless "Email 167", so a bare "type-number" name is
	 * mapped to a clean type word instead.
	 *
	 * @param string $name Field name.
	 * @return string
	 */
	private function humanize( $name ) {
		$name = (string) $name;

		if ( preg_match( '/^([a-z]+)-\d+$/', $name, $match ) ) {
			$friendly = array(
				'text'     => __( 'Text', 'shapeforms' ),
				'email'    => __( 'Email', 'shapeforms' ),
				'url'      => __( 'Website', 'shapeforms' ),
				'tel'      => __( 'Phone', 'shapeforms' ),
				'number'   => __( 'Number', 'shapeforms' ),
				'date'     => __( 'Date', 'shapeforms' ),
				'textarea' => __( 'Message', 'shapeforms' ),
				'select'   => __( 'Selection', 'shapeforms' ),
				'checkbox' => __( 'Options', 'shapeforms' ),
				'radio'    => __( 'Choice', 'shapeforms' ),
				'file'     => __( 'File', 'shapeforms' ),
				'range'    => __( 'Range', 'shapeforms' ),
			);

			return isset( $friendly[ $match[1] ] ) ? $friendly[ $match[1] ] : ucfirst( $match[1] );
		}

		$name = str_replace( array( '-', '_' ), ' ', $name );
		$name = trim( (string) preg_replace( '/\s+/', ' ', $name ) );

		return '' !== $name ? ucfirst( $name ) : '';
	}

	/**
	 * Reads a `key:value` option value from a tag's raw option list.
	 *
	 * @param array<int, string> $options Raw option strings.
	 * @param string             $key     Option key.
	 * @return string Value, or '' when absent.
	 */
	private function opt_value( $options, $key ) {
		$prefix = $key . ':';

		foreach ( $options as $option ) {
			if ( 0 === strpos( $option, $prefix ) ) {
				return substr( $option, strlen( $prefix ) );
			}
		}

		return '';
	}

	/**
	 * Whether a tag carries a bare or valued option (e.g. "multiple", "placeholder").
	 *
	 * @param array<int, string> $options Raw option strings.
	 * @param string             $key     Option key.
	 * @return bool
	 */
	private function has_opt( $options, $key ) {
		foreach ( $options as $option ) {
			if ( $option === $key || 0 === strpos( $option, $key . ':' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Converts a CF7 `filetypes:` list to a comma list of EXTENSIONS for ShapeForms.
	 *
	 * CF7 accepts both extensions ("jpg|png|pdf") and MIME types ("image/*",
	 * "application/pdf"), but ShapeForms's file field validates by extension — so a
	 * raw "image/*" would match nothing and reject every upload. MIME wildcards are
	 * expanded to a common extension set; "type/subtype" keeps the subtype; unknown
	 * MIME globs are dropped (leaving those unrestricted rather than blocking all).
	 *
	 * @param string $filetypes Raw filetypes option value.
	 * @return string Comma-separated extensions, or '' when none.
	 */
	private function normalize_filetypes( $filetypes ) {
		if ( '' === $filetypes ) {
			return '';
		}

		$mime_map = array(
			'image/*' => array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp' ),
			'audio/*' => array( 'mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac' ),
			'video/*' => array( 'mp4', 'mov', 'avi', 'webm', 'mkv', 'm4v' ),
		);

		$out = array();

		foreach ( preg_split( '/[|,]/', $filetypes ) as $token ) {
			$token = strtolower( trim( (string) $token ) );

			if ( '' === $token ) {
				continue;
			}

			if ( isset( $mime_map[ $token ] ) ) {
				$out = array_merge( $out, $mime_map[ $token ] );
			} elseif ( false !== strpos( $token, '/' ) ) {
				// "application/pdf" → pdf, "image/jpeg" → jpeg; drop "*/*" and non-extension subtypes.
				$sub = substr( $token, strpos( $token, '/' ) + 1 );
				if ( '' !== $sub && preg_match( '/^[a-z0-9]+$/', $sub ) ) {
					$out[] = $sub;
				}
			} else {
				$out[] = ltrim( $token, '.' );
			}
		}

		return implode( ',', array_values( array_unique( array_filter( $out ) ) ) );
	}

	/**
	 * Converts a CF7 `limit:` value (bytes, or "1mb"/"512kb") to whole megabytes.
	 *
	 * @param string $limit Raw limit option value.
	 * @return int Megabytes (0 when unset/unparseable).
	 */
	private function normalize_limit_mb( $limit ) {
		$limit = strtolower( trim( (string) $limit ) );

		if ( '' === $limit || ! preg_match( '/^(\d+)\s*(mb|kb|b)?$/', $limit, $match ) ) {
			return 0;
		}

		$value = (int) $match[1];
		$unit  = isset( $match[2] ) ? $match[2] : 'b';

		switch ( $unit ) {
			case 'mb':
				return $value;
			case 'kb':
				return (int) max( 1, round( $value / 1024 ) );
			default:
				return (int) max( 1, round( $value / ( 1024 * 1024 ) ) );
		}
	}

	/**
	 * Builds a human "not migrated" note for the skipped list.
	 *
	 * @param string $label  Field label or name.
	 * @param string $reason Why it was skipped.
	 * @return string
	 */
	private function skip_note( $label, $reason ) {
		if ( '' !== $label ) {
			return sprintf(
				/* translators: 1: field label, 2: reason it was not migrated. */
				__( '%1$s — %2$s', 'shapeforms' ),
				$label,
				$reason
			);
		}

		return $reason;
	}
}
