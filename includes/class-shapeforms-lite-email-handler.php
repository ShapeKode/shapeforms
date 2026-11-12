<?php
/**
 * Email notifications for form submissions.
 *
 * @package ShapeFormsLite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends admin and user submission emails.
 */
class ShapeForms_Lite_Email_Handler {

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
	 * Sends enabled notifications for a saved entry.
	 *
	 * @param object                           $form_record Form record.
	 * @param array<string, mixed>             $settings Form settings.
	 * @param array<string, array<string,mixed>> $entry_data Sanitized entry data.
	 * @param int                              $entry_id ID of the saved entry (0 if unknown).
	 * @return void
	 */
	public function send_notifications( $form_record, $settings, $entry_data, $entry_id = 0 ) {
		$attachments = $this->collect_file_attachments( $entry_data );

		/**
		 * Fires before any notification emails are dispatched.
		 *
		 * Pro can add integrations (Slack, webhook, SMS) here before emails go out.
		 *
		 * @param object                              $form_record Form database row.
		 * @param array<string, mixed>                $settings    Form settings.
		 * @param array<string, array<string, mixed>> $entry_data  Saved entry data.
		 */
		do_action( 'shapeforms_before_notifications', $form_record, $settings, $entry_data );

		if ( ! empty( $settings['enable_admin_email'] ) ) {
			$this->send_admin_email( $form_record, $settings, $entry_data, $attachments, (int) $entry_id );
		}

		if ( ! empty( $settings['enable_user_email'] ) ) {
			$this->send_user_email( $form_record, $entry_data, (int) $entry_id );
		}

		/**
		 * Fires after ShapeForms's built-in notification emails are sent.
		 *
		 * Pro can send additional conditional notifications, webhooks, or third-party pushes.
		 *
		 * @param object                              $form_record Form database row.
		 * @param array<string, mixed>                $settings    Form settings.
		 * @param array<string, array<string, mixed>> $entry_data  Saved entry data.
		 */
		do_action( 'shapeforms_after_notifications', $form_record, $settings, $entry_data );
	}

	/**
	 * Collects file attachment paths from entry data.
	 *
	 * @param array<string, array<string,mixed>> $entry_data Entry data.
	 * @return array<int, string> File paths.
	 */
	private function collect_file_attachments( $entry_data ) {
		$attachments = array();

		foreach ( $entry_data as $field ) {
			if ( ! empty( $field['type'] ) && 'file' === $field['type'] && ! empty( $field['path'] ) && file_exists( $field['path'] ) ) {
				$attachments[] = $field['path'];
			}
		}

		return $attachments;
	}

	/**
	 * Sends admin notification email.
	 *
	 * @param object                             $form_record Form record.
	 * @param array<string, mixed>               $settings    Form settings.
	 * @param array<string, array<string,mixed>> $entry_data  Entry data.
	 * @param array<int, string>                 $attachments File paths to attach.
	 * @param int                                $entry_id    Saved entry ID (0 if unknown).
	 * @return void
	 */
	private function send_admin_email( $form_record, $settings, $entry_data, $attachments = array(), $entry_id = 0 ) {
		// Resolve the recipient, most specific first:
		// 1. the form's own custom admin address,
		// 2. the global "Default email" notification setting,
		// 3. the WordPress site admin email as the final fallback.
		$to = sanitize_email( get_option( 'admin_email' ) );

		$global_settings = get_option( 'shapeforms_lite_settings', array() );
		if ( is_array( $global_settings ) && ! empty( $global_settings['default_email'] ) && is_email( $global_settings['default_email'] ) ) {
			$to = sanitize_email( (string) $global_settings['default_email'] );
		}

		if ( ! empty( $settings['admin_email_type'] ) && 'custom' === $settings['admin_email_type'] && ! empty( $settings['admin_email'] ) && is_email( $settings['admin_email'] ) ) {
			$to = sanitize_email( (string) $settings['admin_email'] );
		}

		/**
		 * Filter the admin-notification recipient, after the form's own settings
		 * have been applied.
		 *
		 * Lets an add-on route the notification somewhere else based on what was
		 * submitted — e.g. sending a "Sales" enquiry to a different inbox than a
		 * "Support" one. Return a single address, or several separated by commas.
		 *
		 * A filtered value is only used when it survives the same validation the
		 * built-in settings get; anything that yields no valid address falls back
		 * to the recipient resolved above, so a notification is never lost.
		 *
		 * @since 1.0.0
		 *
		 * @param string               $to          Resolved recipient address.
		 * @param object               $form_record Form record.
		 * @param array<string, mixed> $entry_data  Saved entry data.
		 * @param int                  $entry_id    Saved entry ID (0 if unknown).
		 */
		$filtered_to = apply_filters(
			'shapeforms_lite_admin_email_to',
			$to,
			$form_record,
			$entry_data,
			(int) $entry_id
		);

		// Re-validate rather than trust: a filter is add-on input, and an invalid or
		// header-injecting value must never reach wp_mail(). A list that yields
		// nothing usable leaves $to exactly as it was.
		$valid_to = self::valid_addresses( $filtered_to );

		if ( ! empty( $valid_to ) ) {
			$to = implode( ', ', array_unique( $valid_to ) );
		}

		$subject = apply_filters(
			'shapeforms_lite_admin_email_subject',
			sprintf(
				/* translators: %s: form title */
				__( 'New submission for %s', 'shapeforms' ),
				! empty( $form_record->title ) ? (string) $form_record->title : __( 'ShapeForms', 'shapeforms' )
			),
			$form_record,
			$entry_data,
			(int) $entry_id
		);
		$message = apply_filters(
			'shapeforms_lite_admin_email_content',
			$this->build_email_template(
				! empty( $form_record->title ) ? (string) $form_record->title : __( 'ShapeForms', 'shapeforms' ),
				$entry_data,
				__( 'A new form submission has been received.', 'shapeforms' )
			),
			$form_record,
			$entry_data,
			(int) $entry_id
		);

		/**
		 * Filter the admin-notification email headers (e.g. to add a Reply-To,
		 * Cc or Bcc). The first entry keeps the HTML content type.
		 *
		 * @since 1.0.0
		 *
		 * @param string[]             $headers     wp_mail headers.
		 * @param object               $form_record Form record.
		 * @param array<string, mixed> $entry_data  Saved entry data.
		 * @param int                  $entry_id    Saved entry ID (0 if unknown).
		 */
		$headers = (array) apply_filters(
			'shapeforms_lite_admin_email_headers',
			array( 'Content-Type: text/html; charset=UTF-8' ),
			$form_record,
			$entry_data,
			(int) $entry_id
		);

		// Cc/Bcc added by a filter carry the same risk as the recipient, and a
		// third-party add-on cannot be assumed to have validated them. Rebuild those
		// header lines from validated addresses, and drop any that survive with none.
		$headers = self::sanitize_address_headers( $headers );

		// $to may now hold a comma-separated list, and sanitize_email() applied to a
		// whole list would mangle it into one invalid address — so validate per
		// address. A single-address $to comes through exactly as it always did.
		$recipients = self::valid_addresses( $to );

		/**
		 * Filter the files attached to the admin notification.
		 *
		 * Receives the visitor's uploaded files, and can add generated documents —
		 * a PDF of the submission, say. Return absolute paths to files that already
		 * exist on disk; wp_mail() reads them while sending, so a path that is not
		 * readable at that moment is simply not attached.
		 *
		 * Paths are re-validated before use and anything outside the uploads
		 * directory is dropped. That is deliberate: this filter's return value ends
		 * up as an outbound email attachment, so an add-on that builds a path from
		 * submitted data — or is simply buggy — could otherwise mail out wp-config.php
		 * or a file from outside the site. Generated attachments belong in uploads
		 * anyway, which is where Lite's own uploaded files already live.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, string>   $attachments Absolute file paths.
		 * @param object               $form_record Form record.
		 * @param array<string, mixed> $entry_data  Saved entry data.
		 * @param int                  $entry_id    Saved entry ID (0 if unknown).
		 */
		$attachments = apply_filters(
			'shapeforms_lite_admin_email_attachments',
			$attachments,
			$form_record,
			$entry_data,
			(int) $entry_id
		);

		wp_mail( implode( ', ', $recipients ), $subject, $message, $headers, self::valid_attachments( $attachments ) );
	}

	/**
	 * Keeps only the attachment paths that are safe to mail out.
	 *
	 * A path has to be a real, readable file that resolves inside the uploads
	 * directory. Symlinks and `../` are handled by comparing realpath() output on
	 * both sides, so a path is judged by where it actually lands rather than by
	 * how it is spelled — `wp-content/uploads/../../wp-config.php` does not pass
	 * a string check but does resolve outside uploads.
	 *
	 * Silently dropping a bad path is the right failure here: the alternative is
	 * refusing to send a notification because one attachment was wrong, and the
	 * notification itself matters more than the file riding along with it.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $attachments Filtered attachment list.
	 * @return array<int, string> Paths that are safe to attach, possibly empty.
	 */
	private static function valid_attachments( $attachments ) {
		$uploads = wp_get_upload_dir();

		// No usable uploads directory means nothing can be proven safe.
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return array();
		}

		$basedir = realpath( $uploads['basedir'] );

		if ( false === $basedir ) {
			return array();
		}

		$basedir .= DIRECTORY_SEPARATOR;
		$valid    = array();

		foreach ( (array) $attachments as $path ) {
			if ( ! is_string( $path ) || '' === $path ) {
				continue;
			}

			$real = realpath( $path );

			if ( false === $real || ! is_file( $real ) || ! is_readable( $real ) ) {
				continue;
			}

			if ( 0 !== strpos( $real, $basedir ) ) {
				continue;
			}

			$valid[] = $real;
		}

		return array_values( array_unique( $valid ) );
	}

	/**
	 * Splits a recipient string into individually validated addresses.
	 *
	 * Two rules, both deliberate:
	 *
	 *  1. A candidate containing CR/LF is discarded, not cleaned. It is a header
	 *     injection attempt and there is no legitimate reading of it.
	 *  2. A candidate must survive sanitize_email() UNCHANGED. That function
	 *     repairs rather than rejects, so "a@evil.com\nBcc: v@x.com" would be
	 *     tidied into the perfectly valid-looking "a@evil.comBccvx.com" — an
	 *     address nobody configured, that mail would really be delivered to. A
	 *     genuine address loses nothing to sanitizing, so "unchanged" cleanly
	 *     separates a real address from a mangled one.
	 *
	 * Note this rejects the display-name form ("Sales <sales@example.com>"), which
	 * sanitize_email() rewrites. Addresses only.
	 *
	 * @since 1.0.0
	 *
	 * Accepts an array as readily as a comma-separated string. A filter whose whole
	 * purpose is "send this to several people" invites returning a list, and casting
	 * an array to string yields "Array" — which validates to nothing, so the routing
	 * would be dropped in silence with a PHP warning on the public submission path.
	 *
	 * @param string|array<int, string>|mixed $list One address, several separated by
	 *                                              commas, or an array of addresses.
	 * @return array<int, string> Valid addresses, possibly empty.
	 */
	private static function valid_addresses( $list ) {
		$valid = array();

		$candidates = is_array( $list )
			? $list
			: explode( ',', is_scalar( $list ) ? (string) $list : '' );

		foreach ( $candidates as $candidate ) {
			$candidate = is_scalar( $candidate ) ? trim( (string) $candidate ) : '';

			if ( '' === $candidate || preg_match( '/[\r\n]/', $candidate ) ) {
				continue;
			}

			$sanitized = sanitize_email( $candidate );

			if ( '' !== $sanitized && $sanitized === $candidate && is_email( $sanitized ) ) {
				$valid[] = $sanitized;
			}
		}

		return array_values( array_unique( $valid ) );
	}

	/**
	 * Re-validates the address lists inside Cc / Bcc headers.
	 *
	 * The headers filter is an open extension point, so its Cc/Bcc values are
	 * add-on input exactly like the recipient is — and an add-on building one from
	 * submitted data may not have applied the checks above. Every other header is
	 * passed through untouched.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string> $headers wp_mail headers.
	 * @return array<int, string>
	 */
	private static function sanitize_address_headers( $headers ) {
		$clean = array();

		foreach ( (array) $headers as $header ) {
			$header = (string) $header;

			if ( ! preg_match( '/^\s*(cc|bcc)\s*:(.*)$/is', $header, $matches ) ) {
				$clean[] = $header;
				continue;
			}

			$addresses = self::valid_addresses( $matches[2] );

			// A Cc/Bcc that validates to nothing is dropped rather than emitted empty.
			if ( ! empty( $addresses ) ) {
				$clean[] = ucfirst( strtolower( $matches[1] ) ) . ': ' . implode( ', ', $addresses );
			}
		}

		return $clean;
	}

	/**
	 * Sends user confirmation email if an email field was submitted.
	 *
	 * @param object                             $form_record Form record.
	 * @param array<string, array<string,mixed>> $entry_data  Entry data.
	 * @param int                                $entry_id    Saved entry ID (0 if unknown).
	 * @param array<int, string>                 $attachments File paths to attach.
	 * @return void
	 */
	private function send_user_email( $form_record, $entry_data, $entry_id = 0, $attachments = array() ) {
		$user_email = $this->detect_user_email( $entry_data );

		if ( ! $user_email ) {
			return;
		}

		$subject = apply_filters(
			'shapeforms_lite_user_email_subject',
			sprintf(
				/* translators: %s: form title */
				__( 'We received your %s submission', 'shapeforms' ),
				! empty( $form_record->title ) ? (string) $form_record->title : __( 'form', 'shapeforms' )
			),
			$form_record,
			$entry_data,
			$user_email,
			(int) $entry_id
		);
		$message = apply_filters(
			'shapeforms_lite_user_email_content',
			$this->build_email_template(
				! empty( $form_record->title ) ? (string) $form_record->title : __( 'ShapeForms', 'shapeforms' ),
				$entry_data,
				__( 'Thank you. We have received your submission.', 'shapeforms' )
			),
			$form_record,
			$entry_data,
			$user_email,
			(int) $entry_id
		);

		/**
		 * Filter the user-confirmation email headers (e.g. to add a Reply-To).
		 *
		 * @since 1.0.0
		 *
		 * @param string[]             $headers     wp_mail headers.
		 * @param object               $form_record Form record.
		 * @param array<string, mixed> $entry_data  Saved entry data.
		 * @param string               $user_email  Detected recipient.
		 * @param int                  $entry_id    Saved entry ID (0 if unknown).
		 */
		$headers = (array) apply_filters(
			'shapeforms_lite_user_email_headers',
			array( 'Content-Type: text/html; charset=UTF-8' ),
			$form_record,
			$entry_data,
			$user_email,
			(int) $entry_id
		);

		/**
		 * Filter the files attached to the user confirmation.
		 *
		 * The counterpart to `shapeforms_lite_admin_email_attachments`, for sending
		 * the submitter their own copy of what they filed — a quote, an
		 * application, a booking.
		 *
		 * Consider carefully what you attach here. This email goes to an address a
		 * stranger typed into a public form, so whatever rides along is delivered
		 * to whoever owns that address — including when it was mistyped. The admin
		 * notification goes to a known inbox; this one does not.
		 *
		 * The list starts EMPTY rather than carrying the visitor's uploads. Mailing
		 * someone their own upload back is rarely wanted and occasionally harmful
		 * (it doubles the delivery of a file that may be large or sensitive), so an
		 * add-on has to ask for anything explicitly.
		 *
		 * Paths are re-validated exactly as on the admin side: real, readable, and
		 * inside the uploads directory.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, string>   $attachments Absolute file paths.
		 * @param object               $form_record Form record.
		 * @param array<string, mixed> $entry_data  Saved entry data.
		 * @param string               $user_email  Detected recipient.
		 * @param int                  $entry_id    Saved entry ID (0 if unknown).
		 */
		$attachments = apply_filters(
			'shapeforms_lite_user_email_attachments',
			(array) $attachments,
			$form_record,
			$entry_data,
			$user_email,
			(int) $entry_id
		);

		// Same treatment as the admin notification's headers. This seam is just as
		// open, reached on the same unauthenticated path, and an add-on building a
		// Bcc from submitted data is no more trustworthy here than there.
		wp_mail( $user_email, $subject, $message, self::sanitize_address_headers( $headers ), self::valid_attachments( $attachments ) );
	}

	/**
	 * Detects a submitted email field.
	 *
	 * @param array<string, array<string,mixed>> $entry_data Entry data.
	 * @return string
	 */
	private function detect_user_email( $entry_data ) {
		foreach ( $entry_data as $field ) {
			if ( empty( $field['type'] ) || 'email' !== $field['type'] || empty( $field['value'] ) ) {
				continue;
			}

			$email = sanitize_email( (string) $field['value'] );

			if ( is_email( $email ) ) {
				return $email;
			}
		}

		return '';
	}

	/**
	 * Builds a simple HTML email template from entry data.
	 *
	 * @param string                              $title Form title.
	 * @param array<string, array<string,mixed>>  $entry_data Entry data.
	 * @param string                              $intro Intro text.
	 * @return string
	 */
	private function build_email_template( $title, $entry_data, $intro ) {
		$rows = '';

		foreach ( $entry_data as $field ) {
			$label = ! empty( $field['label'] ) ? esc_html( (string) $field['label'] ) : esc_html__( 'Field', 'shapeforms' );
			$value = isset( $field['value'] ) ? $field['value'] : '';

			$is_file = ! empty( $field['type'] ) && 'file' === $field['type'];

			// Flatten multi-part values ("John Doe", not "John, , Doe") via the shared helper.
			$value = ShapeForms_Lite::format_field_value( $value, ! empty( $field['type'] ) ? (string) $field['type'] : '' );

			if ( $is_file && ! empty( $value ) ) {
				$file_name  = basename( (string) $value );
				$value_html = '<a href="' . esc_url( (string) $value ) . '" target="_blank">' . esc_html( $file_name ) . '</a>';
			} else {
				// Preserve line breaks from textarea/multi-line values in the HTML email.
				$value_html = nl2br( esc_html( (string) $value ) );
			}

			$rows .= '<tr><td style="padding:8px 12px;border:1px solid #e2e8f0;font-weight:600;">' . $label . '</td><td style="padding:8px 12px;border:1px solid #e2e8f0;">' . $value_html . '</td></tr>';
		}

		return '<div style="font-family:Arial,sans-serif;line-height:1.5;color:#0f172a;">'
			. '<h2 style="margin:0 0 12px;">' . esc_html( $title ) . '</h2>'
			. '<p style="margin:0 0 16px;">' . esc_html( $intro ) . '</p>'
			. '<table style="border-collapse:collapse;width:100%;max-width:680px;">' . $rows . '</table>'
			. '</div>';
	}

}
