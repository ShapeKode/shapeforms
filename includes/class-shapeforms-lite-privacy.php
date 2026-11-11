<?php
/**
 * WordPress privacy (GDPR) integration for stored form entries.
 *
 * Registers a personal-data exporter and eraser so that a site admin can fulfil
 * a visitor's "export my data" / "erase my data" request from
 * Tools → Export Personal Data / Erase Personal Data. Both work by matching the
 * requested email address against the values stored inside each entry's
 * `entry_data_json` payload.
 *
 * @package ShapeFormsLite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles personal-data export and erasure for ShapeForms entries.
 */
class ShapeForms_Lite_Privacy {

	/**
	 * Privacy data group identifier used for both the exporter and eraser.
	 *
	 * @var string
	 */
	const GROUP_ID = 'shapeforms';

	/**
	 * Number of entries processed per export/erase page.
	 *
	 * @var int
	 */
	const PER_PAGE = 100;

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
	 * Registers the personal-data exporter.
	 *
	 * Hooked to the `wp_privacy_personal_data_exporters` filter.
	 *
	 * @param array<string, array<string, mixed>> $exporters Registered exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_exporter( $exporters ) {
		if ( ! is_array( $exporters ) ) {
			$exporters = array();
		}

		$exporters[ self::GROUP_ID ] = array(
			'exporter_friendly_name' => __( 'ShapeForms Form Entries', 'shapeforms' ),
			'callback'               => array( $this, 'export_entries' ),
		);

		return $exporters;
	}

	/**
	 * Registers the personal-data eraser.
	 *
	 * Hooked to the `wp_privacy_personal_data_erasers` filter.
	 *
	 * @param array<string, array<string, mixed>> $erasers Registered erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_eraser( $erasers ) {
		if ( ! is_array( $erasers ) ) {
			$erasers = array();
		}

		$erasers[ self::GROUP_ID ] = array(
			'eraser_friendly_name' => __( 'ShapeForms Form Entries', 'shapeforms' ),
			'callback'             => array( $this, 'erase_entries' ),
		);

		return $erasers;
	}

	/**
	 * Exports every entry whose submission contains the given email address.
	 *
	 * Returns the structure WordPress expects from a personal-data exporter:
	 * `array( 'data' => array<int, array>, 'done' => bool )`. Results are
	 * paginated by PER_PAGE; the caller increments `$page` until `done` is true.
	 *
	 * @param string $email_address Email address being exported.
	 * @param int    $page          1-based page number.
	 * @return array<string, mixed>
	 */
	public function export_entries( $email_address, $page = 1 ) {
		$email = sanitize_email( (string) $email_address );
		$page  = max( 1, (int) $page );

		$export_items = array();

		if ( '' === $email || ! is_email( $email ) ) {
			return array(
				'data' => $export_items,
				'done' => true,
			);
		}

		$entries = $this->get_entries_page( $page );

		foreach ( $entries as $entry ) {
			$decoded = $this->decode_entry_data( $entry->entry_data_json );

			if ( ! $this->entry_matches_email( $decoded, $email ) ) {
				continue;
			}

			$export_items[] = array(
				'group_id'    => self::GROUP_ID,
				'group_label' => __( 'ShapeForms Form Entries', 'shapeforms' ),
				'item_id'     => 'shapeforms-entry-' . (int) $entry->id,
				'data'        => $this->build_export_item_data( $entry, $decoded ),
			);
		}

		// `done` is true once a page returns fewer rows than the page size, meaning
		// there are no further entries to scan.
		$done = count( $entries ) < self::PER_PAGE;

		return array(
			'data' => $export_items,
			'done' => $done,
		);
	}

	/**
	 * Erases (deletes) every entry whose submission contains the given email.
	 *
	 * Returns the structure WordPress expects from a personal-data eraser:
	 * `array( 'items_removed' => int, 'items_retained' => bool, 'messages' => array, 'done' => bool )`.
	 * Matching rows are the visitor's own submissions, so they are deleted
	 * outright via a prepared `$wpdb->delete`.
	 *
	 * @param string $email_address Email address being erased.
	 * @param int    $page          1-based page number.
	 * @return array<string, mixed>
	 */
	public function erase_entries( $email_address, $page = 1 ) {
		global $wpdb;

		$email = sanitize_email( (string) $email_address );
		$page  = max( 1, (int) $page );

		$items_removed  = 0;
		$items_retained = false;
		$messages       = array();

		if ( '' === $email || ! is_email( $email ) ) {
			return array(
				'items_removed'  => 0,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$table = $this->plugin->get_entries_table_name();

		// Scan the whole table once using an id cursor (WHERE id > cursor), NOT OFFSET:
		// deleting matched rows shrinks the table, which would make OFFSET paging skip
		// rows. An id cursor is unaffected by deletions, so no entry is missed. All
		// matches are erased in this single invocation, so we always report done = true.
		$last_id = 0;

		do {
			$entries = $this->get_entries_after( $last_id );

			foreach ( $entries as $entry ) {
				$last_id = (int) $entry->id;

				$decoded = $this->decode_entry_data( $entry->entry_data_json );

				if ( ! $this->entry_matches_email( $decoded, $email ) ) {
					continue;
				}

				$deleted = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$table,
					array( 'id' => (int) $entry->id ),
					array( '%d' )
				);

				if ( false !== $deleted && $deleted > 0 ) {
					$items_removed++;
				} else {
					$items_retained = true;
					$messages[]     = sprintf(
						/* translators: %d: entry ID that could not be deleted. */
						__( 'ShapeForms entry #%d could not be removed.', 'shapeforms' ),
						(int) $entry->id
					);
				}
			}
		} while ( count( $entries ) === self::PER_PAGE );

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => $items_retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Fetches a single page of entry rows ordered by ID.
	 *
	 * Entries are scanned form-agnostically because the email may live in any
	 * email-type or email-valued field of any form. The query is prepared and
	 * paginated to keep memory bounded.
	 *
	 * @param int $page 1-based page number.
	 * @return array<int, object> Entry rows (id, form_id, entry_data_json, user_ip, user_agent, created_at).
	 */
	private function get_entries_page( $page ) {
		global $wpdb;

		$table  = esc_sql( $this->plugin->get_entries_table_name() );
		$offset = ( max( 1, (int) $page ) - 1 ) * self::PER_PAGE;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, form_id, entry_data_json, user_ip, user_agent, created_at FROM `{$table}` ORDER BY id ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::PER_PAGE,
				$offset
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Fetches the next page of entry rows with an id greater than a cursor.
	 *
	 * Used by the eraser: because it advances by the last id seen (not an OFFSET),
	 * deleting matched rows mid-scan never causes later rows to be skipped.
	 *
	 * @param int $last_id Exclusive lower-bound entry ID (0 to start).
	 * @return array<int, object> Entry rows ordered by ascending ID.
	 */
	private function get_entries_after( $last_id ) {
		global $wpdb;

		$table = esc_sql( $this->plugin->get_entries_table_name() );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, form_id, entry_data_json, user_ip, user_agent, created_at FROM `{$table}` WHERE id > %d ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $last_id,
				self::PER_PAGE
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Decodes an entry's stored JSON payload into a keyed field array.
	 *
	 * @param string $entry_data_json Raw JSON string from the entries table.
	 * @return array<string, array<string, mixed>> Field array keyed by field_id.
	 */
	private function decode_entry_data( $entry_data_json ) {
		$decoded = json_decode( (string) $entry_data_json, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Determines whether a decoded entry contains the given email address.
	 *
	 * Matches when any field is of type `email` and holds the address, or when
	 * any stored value is itself a valid email equal to the requested one. The
	 * comparison is case-insensitive on the address.
	 *
	 * @param array<string, array<string, mixed>> $decoded Decoded entry data.
	 * @param string                              $email   Sanitized email to match.
	 * @return bool
	 */
	private function entry_matches_email( $decoded, $email ) {
		$needle = strtolower( $email );

		foreach ( $decoded as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['value'] ) ) {
				continue;
			}

			$type = isset( $field['type'] ) ? (string) $field['type'] : '';

			foreach ( $this->flatten_values( $field['value'] ) as $value ) {
				$value = trim( (string) $value );

				if ( '' === $value ) {
					continue;
				}

				// Match an email-type field directly, or any value that is itself a
				// valid email matching the requested address.
				if ( 'email' === $type && strtolower( $value ) === $needle ) {
					return true;
				}

				if ( is_email( $value ) && strtolower( $value ) === $needle ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Flattens a stored field value into a list of scalar strings.
	 *
	 * Field values may be scalars (text, email) or arrays (checkbox, multiselect,
	 * name, address). This returns every leaf scalar so each can be inspected.
	 *
	 * @param mixed $value Stored field value.
	 * @return array<int, string>
	 */
	private function flatten_values( $value ) {
		if ( is_array( $value ) ) {
			$flat = array();
			foreach ( $value as $item ) {
				$flat = array_merge( $flat, $this->flatten_values( $item ) );
			}
			return $flat;
		}

		return is_scalar( $value ) ? array( (string) $value ) : array();
	}

	/**
	 * Builds the WordPress export "data" rows for a single entry.
	 *
	 * Each field becomes a `name => value` pair (using the stored label when
	 * available, otherwise the field key), followed by metadata rows for the
	 * form name, submission date, IP address and user agent.
	 *
	 * @param object                              $entry   Entry database row.
	 * @param array<string, array<string, mixed>> $decoded Decoded entry data.
	 * @return array<int, array<string, string>>
	 */
	private function build_export_item_data( $entry, $decoded ) {
		$data = array();

		foreach ( $decoded as $field_key => $field ) {
			if ( ! is_array( $field ) || ! isset( $field['value'] ) ) {
				continue;
			}

			$label = ! empty( $field['label'] )
				? (string) $field['label']
				: (string) $field_key;

			$type = isset( $field['type'] ) ? (string) $field['type'] : '';

			$data[] = array(
				'name'  => $label,
				'value' => ShapeForms_Lite::format_field_value( $field['value'], $type ),
			);
		}

		$form_name = $this->get_form_title( (int) $entry->form_id );

		if ( '' !== $form_name ) {
			$data[] = array(
				'name'  => __( 'Form', 'shapeforms' ),
				'value' => $form_name,
			);
		}

		$data[] = array(
			'name'  => __( 'Submitted on', 'shapeforms' ),
			'value' => (string) $entry->created_at,
		);

		if ( ! empty( $entry->user_ip ) ) {
			$data[] = array(
				'name'  => __( 'IP address', 'shapeforms' ),
				'value' => (string) $entry->user_ip,
			);
		}

		if ( ! empty( $entry->user_agent ) ) {
			$data[] = array(
				'name'  => __( 'User agent', 'shapeforms' ),
				'value' => (string) $entry->user_agent,
			);
		}

		return $data;
	}

	/**
	 * Resolves a form's title, returning an empty string when it cannot be found.
	 *
	 * @param int $form_id Form ID.
	 * @return string
	 */
	private function get_form_title( $form_id ) {
		global $wpdb;

		if ( $form_id <= 0 ) {
			return '';
		}

		$table = esc_sql( $this->plugin->get_forms_table_name() );

		$title = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT title FROM `{$table}` WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$form_id
			)
		);

		return null === $title ? '' : (string) $title;
	}
}
