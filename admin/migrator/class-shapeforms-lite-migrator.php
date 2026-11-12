<?php
/**
 * Form Migrator controller.
 *
 * Renders the Settings → Tools → Migrator sub-tab, registers the available
 * sources, and (from Phase 3) dispatches import requests. The controller is
 * source-agnostic: each source plugin is one class implementing
 * ShapeForms_Lite_Migration_Source, registered through the
 * `shapeforms_migration_sources` filter.
 *
 * @package ShapeFormsLite
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates migrating forms from other plugins into ShapeForms.
 */
class ShapeForms_Lite_Migrator {

	/**
	 * Main plugin instance.
	 *
	 * @var ShapeForms_Lite
	 */
	private $plugin;

	/**
	 * Registered sources, keyed by slug (lazily built).
	 *
	 * @var array<string, ShapeForms_Lite_Migration_Source>|null
	 */
	private $sources = null;

	/**
	 * Option name for the idempotency map ("{slug}:{source_id}" => ShapeForms form ID).
	 */
	const MAP_OPTION = 'shapeforms_migration_map';

	/**
	 * Constructor.
	 *
	 * @param ShapeForms_Lite $plugin Main plugin instance.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'handle_import' ) );

		// Old [boldform] shortcodes and BoldForm blocks keep rendering (as their
		// imported ShapeForms form) after BoldForm is deactivated.
		$this->load_default_sources();
		add_action( 'init', array( 'ShapeForms_Lite_Source_BoldForm', 'register_legacy_embeds' ), 99 );
	}

	/**
	 * All registered sources, keyed by slug.
	 *
	 * @return array<string, ShapeForms_Lite_Migration_Source>
	 */
	public function get_sources() {
		if ( null !== $this->sources ) {
			return $this->sources;
		}

		$this->load_default_sources();

		/**
		 * Filters the list of migration sources.
		 *
		 * Add-ons (or future core sources) can register an importer by appending
		 * an object implementing ShapeForms_Lite_Migration_Source.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, ShapeForms_Lite_Migration_Source> $sources Source objects.
		 */
		$registered = apply_filters( 'shapeforms_migration_sources', array( new ShapeForms_Lite_Source_BoldForm(), new ShapeForms_Lite_Source_CF7() ) );

		$this->sources = array();

		foreach ( (array) $registered as $source ) {
			if ( $source instanceof ShapeForms_Lite_Migration_Source ) {
				$this->sources[ $source->get_slug() ] = $source;
			}
		}

		return $this->sources;
	}

	/**
	 * Sources whose plugin/data is present.
	 *
	 * @return array<string, ShapeForms_Lite_Migration_Source>
	 */
	public function get_available_sources() {
		return array_filter(
			$this->get_sources(),
			static function ( $source ) {
				return $source->is_available();
			}
		);
	}

	/**
	 * Renders the Migrator tab content.
	 *
	 * @return void
	 */
	public function render_tab() {
		$available = $this->get_available_sources();

		if ( empty( $available ) ) {
			// Every registered source (installed or not) so the visitor sees what
			// they can migrate from. New sources added via shapeforms_migration_sources
			// appear here automatically — no per-plugin markup to maintain.
			$all_sources = $this->get_sources();
			?>
			<div class="shapeforms-card shapeforms-card--spaced">
				<div class="shapeforms-migrator-empty">
					<span class="shapeforms-migrator-empty__icon dashicons dashicons-database-import" aria-hidden="true"></span>
					<h3 class="shapeforms-migrator-empty__title"><?php esc_html_e( 'Import your forms into ShapeForms', 'shapeforms' ); ?></h3>
					<p class="shapeforms-migrator-empty__text">
						<?php esc_html_e( 'Already built forms in another plugin? Bring them over in a few clicks — fields, options, and basic settings are mapped for you. Activate one of the supported plugins below and its forms will appear here, ready to import.', 'shapeforms' ); ?>
					</p>

					<?php if ( ! empty( $all_sources ) ) : ?>
						<div class="shapeforms-migrator-empty__sources">
							<span class="shapeforms-migrator-empty__sources-label"><?php esc_html_e( 'Supported sources', 'shapeforms' ); ?></span>
							<ul class="shapeforms-migrator-source-list">
								<?php foreach ( $all_sources as $source ) : ?>
									<li class="shapeforms-migrator-source-chip">
										<span class="dashicons dashicons-marker" aria-hidden="true"></span>
										<span class="shapeforms-migrator-source-chip__name"><?php echo esc_html( $source->get_label() ); ?></span>
										<span class="shapeforms-migrator-source-chip__status"><?php esc_html_e( 'Not detected', 'shapeforms' ); ?></span>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>

					<p class="shapeforms-migrator-empty__hint">
						<?php esc_html_e( 'More form plugins are being added over time.', 'shapeforms' ); ?>
					</p>
				</div>
			</div>
			<?php
			return;
		}

		// Resolve the active source from the URL, defaulting to the first available one.
		$active_slug = isset( $_GET['migrator_source'] ) ? sanitize_key( wp_unslash( $_GET['migrator_source'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $active_slug || ! isset( $available[ $active_slug ] ) ) {
			$active_slug = (string) array_key_first( $available );
		}

		$active_source = $available[ $active_slug ];
		?>
		<div class="shapeforms-card shapeforms-card--spaced">
			<h3><?php esc_html_e( 'Import from another form plugin', 'shapeforms' ); ?></h3>
			<p class="shapeforms-tab-description">
				<?php esc_html_e( 'Bring your existing forms into ShapeForms. Select a source below, then import the forms you want — fields and basic settings are mapped automatically.', 'shapeforms' ); ?>
			</p>

			<?php $this->render_result_notice(); ?>

			<?php if ( count( $available ) > 1 ) : ?>
				<div class="shapeforms-smtp-subtabs shapeforms-migrator-sources">
					<?php foreach ( $available as $slug => $source ) : ?>
						<a
							href="<?php echo esc_url( admin_url( 'admin.php?page=shapeforms-lite-settings&tab=tools&tools_tab=migrator&migrator_source=' . $slug ) ); ?>"
							class="<?php echo $slug === $active_slug ? 'active' : ''; ?>"
						>
							<?php echo esc_html( $source->get_label() ); ?>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php $this->render_source_table( $active_slug, $active_source ); ?>
		</div>
		<?php
	}

	/**
	 * Renders the forms table for one source, as a single import form.
	 *
	 * @param string                         $slug   The active source slug.
	 * @param ShapeForms_Lite_Migration_Source $source The active source.
	 * @return void
	 */
	private function render_source_table( $slug, $source ) {
		$forms = $source->get_forms();

		if ( empty( $forms ) ) {
			?>
			<p class="shapeforms-migrator-empty">
				<?php
				printf(
					/* translators: %s: source plugin name, e.g. Contact Form 7. */
					esc_html__( 'No %s forms were found to import.', 'shapeforms' ),
					esc_html( $source->get_label() )
				);
				?>
			</p>
			<?php
			return;
		}

		// Resolve the imported state of every row in one query (avoids an N+1
		// SELECT per row). Behaviour matches get_mapped_form_id() row-by-row,
		// including the self-healing prune of stale mappings.
		$imported_ids = $this->get_imported_source_ids( $slug, $forms );
		?>
		<form method="post" class="shapeforms-migrator-form">
			<?php wp_nonce_field( 'shapeforms_migrate', 'shapeforms_migrate_nonce' ); ?>
			<input type="hidden" name="migrator_source" value="<?php echo esc_attr( $slug ); ?>">

			<p class="description shapeforms-migrator-note">
				<?php esc_html_e( 'Re-importing a form updates the one you imported before instead of creating a duplicate, and overwrites its fields with the source version.', 'shapeforms' ); ?>
			</p>

			<div class="shapeforms-migrator-table-wrap">
				<table class="widefat striped shapeforms-migrator-table">
					<thead>
						<tr>
							<td class="check-column">
								<input type="checkbox" id="shapeforms-migrator-select-all" aria-label="<?php esc_attr_e( 'Select all forms', 'shapeforms' ); ?>">
							</td>
							<th scope="col"><?php esc_html_e( 'Form Name', 'shapeforms' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Imported', 'shapeforms' ); ?></th>
							<th scope="col" class="shapeforms-migrator-table__action"><?php esc_html_e( 'Action', 'shapeforms' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $forms as $form ) :
							$imported = isset( $imported_ids[ $form['id'] ] );
							?>
							<tr>
								<th scope="row" class="check-column">
									<input
										type="checkbox"
										class="shapeforms-migrator-cb"
										name="shapeforms_migrate_ids[]"
										value="<?php echo esc_attr( (string) $form['id'] ); ?>"
										aria-label="<?php echo esc_attr( $form['title'] ); ?>"
									>
								</th>
								<td><?php echo esc_html( $form['title'] ); ?></td>
								<td>
									<?php if ( $imported ) : ?>
										<span class="shapeforms-migrator-badge shapeforms-migrator-badge--done"><?php esc_html_e( 'Imported', 'shapeforms' ); ?></span>
									<?php else : ?>
										<span class="shapeforms-migrator-badge">&mdash;</span>
									<?php endif; ?>
								</td>
								<td class="shapeforms-migrator-table__action">
									<button type="submit" class="button" name="shapeforms_migrate_single" value="<?php echo esc_attr( (string) $form['id'] ); ?>">
										<?php echo $imported ? esc_html__( 'Re-import', 'shapeforms' ) : esc_html__( 'Import Form', 'shapeforms' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="shapeforms-submit-area">
				<button type="submit" class="button" id="shapeforms-migrator-import-selected" name="shapeforms_migrate_selected" value="1" disabled>
					<?php esc_html_e( 'Import Selected', 'shapeforms' ); ?>
				</button>
				<button type="submit" class="button button-primary" name="shapeforms_migrate_all" value="1">
					<?php esc_html_e( 'Import All Forms', 'shapeforms' ); ?>
				</button>
			</div>
		</form>

		<?php
		wp_add_inline_script(
			'shapeforms-lite-admin',
			'(function(){
				var all=document.getElementById("shapeforms-migrator-select-all");
				var btn=document.getElementById("shapeforms-migrator-import-selected");
				var boxes=document.querySelectorAll(".shapeforms-migrator-cb");
				if(!boxes.length)return;
				function sync(){
					var n=0;
					for(var i=0;i<boxes.length;i++)if(boxes[i].checked)n++;
					if(btn)btn.disabled=(0===n);
					if(all){all.checked=(n>0&&n===boxes.length);all.indeterminate=(n>0&&n<boxes.length);}
				}
				if(all)all.addEventListener("change",function(){
					for(var i=0;i<boxes.length;i++)boxes[i].checked=all.checked;
					sync();
				});
				for(var j=0;j<boxes.length;j++)boxes[j].addEventListener("change",sync);
				sync();
			})();'
		);
	}

	/**
	 * Renders the one-time import result notice, if present.
	 *
	 * @return void
	 */
	private function render_result_notice() {
		$result = get_transient( 'shapeforms_migrator_result_' . get_current_user_id() );

		if ( empty( $result ) || ! is_array( $result ) ) {
			return;
		}

		delete_transient( 'shapeforms_migrator_result_' . get_current_user_id() );

		if ( ! empty( $result['error'] ) ) {
			?>
			<div class="shapeforms-card shapeforms-card--error"><p><?php echo esc_html( (string) $result['error'] ); ?></p></div>
			<?php
			return;
		}

		$created = isset( $result['created'] ) ? (int) $result['created'] : 0;
		$updated = isset( $result['updated'] ) ? (int) $result['updated'] : 0;
		$failed  = isset( $result['failed'] ) ? (int) $result['failed'] : 0;
		$entries = isset( $result['entries'] ) ? (int) $result['entries'] : 0;
		$forms   = isset( $result['forms'] ) && is_array( $result['forms'] ) ? $result['forms'] : array();
		?>
		<div class="shapeforms-card shapeforms-card--success shapeforms-migrator-result">
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of forms created, 2: number updated. */
						__( 'Import complete — %1$d form(s) created, %2$d updated.', 'shapeforms' ),
						$created,
						$updated
					)
				);
				if ( $entries > 0 ) {
					echo ' ';
					echo esc_html(
						sprintf(
							/* translators: %d: number of entries copied. */
							_n( '%d entry copied.', '%d entries copied.', $entries, 'shapeforms' ),
							$entries
						)
					);
				}
				if ( $failed > 0 ) {
					echo ' ';
					echo esc_html(
						sprintf(
							/* translators: %d: number of forms that failed to import. */
							_n( '%d form could not be imported.', '%d forms could not be imported.', $failed, 'shapeforms' ),
							$failed
						)
					);
				}
				?>
			</p>

			<?php foreach ( $forms as $form ) : ?>
				<?php
				$title       = isset( $form['title'] ) ? (string) $form['title'] : '';
				$edit_url    = ! empty( $form['form_id'] ) ? admin_url( 'admin.php?page=shapeforms-lite-builder&form_id=' . (int) $form['form_id'] ) : '';
				$skipped     = isset( $form['skipped'] ) && is_array( $form['skipped'] ) ? $form['skipped'] : array();
				$warnings    = isset( $form['warnings'] ) && is_array( $form['warnings'] ) ? $form['warnings'] : array();
				$row_error   = isset( $form['error'] ) ? (string) $form['error'] : '';
				$row_entries = isset( $form['entries'] ) ? (int) $form['entries'] : 0;
				$notes       = isset( $form['notes'] ) && is_array( $form['notes'] ) ? $form['notes'] : array();
				?>
				<div class="shapeforms-migrator-result__form">
					<strong><?php echo esc_html( $title ); ?></strong>
					<?php if ( '' !== $row_error ) : ?>
						&mdash; <span class="shapeforms-migrator-result__error"><?php echo esc_html( $row_error ); ?></span>
					<?php elseif ( '' !== $edit_url ) : ?>
						&mdash; <a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit form', 'shapeforms' ); ?></a>
						<?php if ( $row_entries > 0 ) : ?>
							<?php
							/* translators: %d: number of entries copied for this form. */
							echo esc_html( sprintf( _n( '(%d entry copied)', '(%d entries copied)', $row_entries, 'shapeforms' ), $row_entries ) );
							?>
						<?php endif; ?>
					<?php endif; ?>
					<?php if ( ! empty( $skipped ) || ! empty( $warnings ) || ! empty( $notes ) ) : ?>
						<?php
						// Warnings sit in the same list as the skip notes but describe a
						// field that DID come across, so they are labelled to keep the
						// distinction clear — "not imported" versus "imported, but look".
						?>
						<ul class="shapeforms-migrator-result__skipped">
							<?php foreach ( $skipped as $note ) : ?>
								<li><?php echo esc_html( (string) $note ); ?></li>
							<?php endforeach; ?>
							<?php foreach ( $warnings as $note ) : ?>
								<li><em><?php esc_html_e( 'Check:', 'shapeforms' ); ?></em> <?php echo esc_html( (string) $note ); ?></li>
							<?php endforeach; ?>
							<?php foreach ( $notes as $note ) : ?>
								<li><?php echo esc_html( (string) $note ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Handles a migrator import POST (single / selected / all).
	 *
	 * @return void
	 */
	public function handle_import() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified below once we know this is a migrator request.
		if ( ! isset( $_POST['shapeforms_migrate_single'] ) && ! isset( $_POST['shapeforms_migrate_selected'] ) && ! isset( $_POST['shapeforms_migrate_all'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'shapeforms_migrate', 'shapeforms_migrate_nonce' );

		$slug      = isset( $_POST['migrator_source'] ) ? sanitize_key( wp_unslash( $_POST['migrator_source'] ) ) : '';
		$available = $this->get_available_sources();

		if ( '' === $slug || ! isset( $available[ $slug ] ) ) {
			$this->redirect_with_result( $slug, array( 'error' => __( 'That import source is not available.', 'shapeforms' ) ) );
			return;
		}

		$source = $available[ $slug ];
		$ids    = $this->resolve_target_ids( $source );

		if ( empty( $ids ) ) {
			$this->redirect_with_result( $slug, array( 'error' => __( 'Select at least one form to import.', 'shapeforms' ) ) );
			return;
		}

		$result = $this->run_import( $slug, $source, $ids );

		$this->redirect_with_result( $slug, $result );
	}

	/**
	 * Resolves the target source-form IDs from the submitted buttons.
	 *
	 * @param ShapeForms_Lite_Migration_Source $source The active source.
	 * @return array<int, string> Source form IDs (as strings).
	 */
	private function resolve_target_ids( $source ) {
		// Nonce is verified in the caller before this runs.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['shapeforms_migrate_all'] ) ) {
			$ids = array();
			foreach ( $source->get_forms() as $form ) {
				$ids[] = (string) $form['id'];
			}
			return $ids;
		}

		if ( isset( $_POST['shapeforms_migrate_single'] ) ) {
			$id = sanitize_text_field( wp_unslash( $_POST['shapeforms_migrate_single'] ) );
			return '' !== $id ? array( $id ) : array();
		}

		if ( isset( $_POST['shapeforms_migrate_ids'] ) && is_array( $_POST['shapeforms_migrate_ids'] ) ) {
			$raw = array_map( 'sanitize_text_field', wp_unslash( $_POST['shapeforms_migrate_ids'] ) );
			return array_values( array_filter( $raw, static function ( $id ) {
				return '' !== $id;
			} ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return array();
	}

	/**
	 * Imports each target form, creating or updating the ShapeForms form.
	 *
	 * @param string                         $slug   Source slug.
	 * @param ShapeForms_Lite_Migration_Source $source The active source.
	 * @param array<int, string>             $ids    Source form IDs.
	 * @return array<string, mixed> Aggregate result for the notice.
	 */
	private function run_import( $slug, $source, $ids ) {
		if ( ! class_exists( 'ShapeForms_Lite_Ajax_Save' ) ) {
			require_once SHAPEFORMS_LITE_PATH . 'admin/ajax-save.php';
		}

		$created = 0;
		$updated = 0;
		$failed  = 0;
		$entries = 0;
		$forms   = array();

		foreach ( $ids as $source_id ) {
			$result = $this->import_one( $slug, $source, $source_id );

			if ( ! $result->is_success() ) {
				++$failed;
				$forms[] = array(
					'title'   => $result->title,
					'error'   => is_wp_error( $result->error ) ? $result->error->get_error_message() : __( 'Import failed.', 'shapeforms' ),
					'skipped' => $result->skipped,
				);
				continue;
			}

			if ( $result->updated ) {
				++$updated;
			} else {
				++$created;
			}

			$entries += $result->entries;

			$forms[] = array(
				'title'    => $result->title,
				'form_id'  => $result->form_id,
				'skipped'  => $result->skipped,
				'warnings' => $result->warnings,
				'entries'  => $result->entries,
				'notes'    => $result->notes,
			);
		}

		return array(
			'created' => $created,
			'updated' => $updated,
			'failed'  => $failed,
			'entries' => $entries,
			'forms'   => $forms,
		);
	}

	/**
	 * Imports a single source form (create or update), returning a result object.
	 *
	 * @param string                         $slug      Source slug.
	 * @param ShapeForms_Lite_Migration_Source $source    The active source.
	 * @param int|string                     $source_id Source form ID.
	 * @return ShapeForms_Lite_Migration_Result
	 */
	private function import_one( $slug, $source, $source_id ) {
		global $wpdb;

		$data   = $source->import_form( $source_id );
		$result = new ShapeForms_Lite_Migration_Result();

		if ( is_wp_error( $data ) ) {
			$result->error = $data;
			return $result;
		}

		$result->title    = isset( $data['title'] ) ? (string) $data['title'] : '';
		$result->skipped  = isset( $data['skipped'] ) && is_array( $data['skipped'] ) ? $data['skipped'] : array();
		$result->warnings = isset( $data['warnings'] ) && is_array( $data['warnings'] ) ? $data['warnings'] : array();

		// Re-sanitize through the builder's own save path — never hand-write fields_json.
		$rows_payload   = array( 'rows' => isset( $data['rows'] ) && is_array( $data['rows'] ) ? $data['rows'] : array() );
		$prepared_rows  = ShapeForms_Lite_Ajax_Save::prepare_rows( $rows_payload );

		// Guard against a form with nothing importable — never create an empty form.
		// The skipped notes are kept so the admin sees why (all fields unsupported).
		if ( 0 === $this->count_fields( $prepared_rows ) ) {
			$result->error = new WP_Error(
				'shapeforms_migrator_no_fields',
				__( 'No fields could be imported from this form.', 'shapeforms' )
			);
			return $result;
		}

		$fields_json   = wp_json_encode( array( 'rows' => $prepared_rows ) );
		$settings_json = wp_json_encode( ShapeForms_Lite_Ajax_Save::normalize_form_settings( isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : array() ) );

		// Never write a form whose JSON failed to encode — that would store an
		// empty, field-less form yet report success.
		if ( false === $fields_json || false === $settings_json ) {
			$result->error = new WP_Error( 'shapeforms_migrator_encode_failed', __( 'The form could not be saved.', 'shapeforms' ) );
			return $result;
		}

		$forms_table  = $this->plugin->get_forms_table_name();
		$existing_id  = $this->get_mapped_form_id( $slug, $source_id );

		if ( $existing_id > 0 ) {
			$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$forms_table,
				array(
					'title'         => sanitize_text_field( $result->title ),
					'fields_json'   => $fields_json,
					'settings_json' => $settings_json,
				),
				array( 'id' => $existing_id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);

			// Only `false` is an error; 0 legitimately means the row was unchanged.
			if ( false === $updated ) {
				$result->error = new WP_Error( 'shapeforms_migrator_update_failed', __( 'The form could not be updated.', 'shapeforms' ) );
				return $result;
			}

			$result->form_id = $existing_id;
			$result->updated = true;
		} else {
			$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$forms_table,
				array(
					'title'         => sanitize_text_field( $result->title ),
					'status'        => 'publish',
					'fields_json'   => $fields_json,
					'settings_json' => $settings_json,
					'created_by'    => get_current_user_id(),
				),
				array( '%s', '%s', '%s', '%s', '%d' )
			);

			if ( ! $inserted ) {
				$result->error = new WP_Error( 'shapeforms_migrator_insert_failed', __( 'The form could not be saved.', 'shapeforms' ) );
				return $result;
			}

			$result->form_id = (int) $wpdb->insert_id;
			$this->mark_imported( $slug, $source_id, $result->form_id );
		}

		// Optional per-source follow-up (e.g. BoldForm copies the form's entries).
		if ( method_exists( $source, 'after_import' ) ) {
			$extra = $source->after_import( $source_id, $result->form_id );

			if ( is_array( $extra ) ) {
				$result->entries = isset( $extra['entries'] ) ? (int) $extra['entries'] : 0;
				$result->notes   = isset( $extra['notes'] ) && is_array( $extra['notes'] ) ? $extra['notes'] : array();
			}
		}

		return $result;
	}

	/**
	 * Reads the idempotency map (source key => ShapeForms form ID).
	 *
	 * @return array<string, int>
	 */
	private function get_migration_map() {
		$map = get_option( self::MAP_OPTION, array() );

		return is_array( $map ) ? $map : array();
	}

	/**
	 * Builds the map key for a source form.
	 *
	 * @param string     $slug      Source slug.
	 * @param int|string $source_id Source form ID.
	 * @return string
	 */
	private function map_key( $slug, $source_id ) {
		return $slug . ':' . $source_id;
	}

	/**
	 * Returns the mapped ShapeForms form ID for a source form, or 0.
	 *
	 * Self-healing: if the mapped form no longer exists, the stale entry is
	 * pruned and 0 is returned (so a re-import creates a fresh form).
	 *
	 * @param string     $slug      Source slug.
	 * @param int|string $source_id Source form ID.
	 * @return int
	 */
	private function get_mapped_form_id( $slug, $source_id ) {
		$map = $this->get_migration_map();
		$key = $this->map_key( $slug, $source_id );

		if ( empty( $map[ $key ] ) ) {
			return 0;
		}

		$form_id = (int) $map[ $key ];

		if ( ! $this->form_exists( $form_id ) ) {
			unset( $map[ $key ] );
			update_option( self::MAP_OPTION, $map, false );
			return 0;
		}

		return $form_id;
	}

	/**
	 * Resolves which of the given source forms have a live imported ShapeForms
	 * form, in a single query, for the listing table.
	 *
	 * This is the batched equivalent of calling get_mapped_form_id() once per
	 * row: it collects the mapped ShapeForms IDs for the rows on screen, checks
	 * them all with one `IN (...)` SELECT, and self-heals the map by pruning
	 * mappings whose target form no longer exists (or is trashed).
	 *
	 * @param string                          $slug  Source slug.
	 * @param array<int, array<string, mixed>> $forms Source forms ({id,title,...}).
	 * @return array<int|string, true> Set of source IDs that are imported.
	 */
	private function get_imported_source_ids( $slug, $forms ) {
		$map = $this->get_migration_map();

		// Map the ShapeForms form IDs of the rows on screen back to their source IDs.
		$form_id_to_source = array();
		foreach ( $forms as $form ) {
			$key = $this->map_key( $slug, $form['id'] );
			if ( ! empty( $map[ $key ] ) ) {
				$form_id_to_source[ (int) $map[ $key ] ] = $form['id'];
			}
		}

		if ( empty( $form_id_to_source ) ) {
			return array();
		}

		global $wpdb;

		$forms_table  = esc_sql( $this->plugin->get_forms_table_name() );
		$ids          = array_map( 'intval', array_keys( $form_id_to_source ) );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// $placeholders is a run of `%d` sized to $ids (all ints), so the query is safe;
		// the sniffs can't see placeholders built dynamically, hence the ignores.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, status FROM `{$forms_table}` WHERE id IN ({$placeholders})", $ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$live_status = array();
		foreach ( (array) $rows as $row ) {
			$live_status[ (int) $row['id'] ] = $row['status'];
		}

		$imported = array();
		$changed  = false;

		foreach ( $form_id_to_source as $form_id => $source_id ) {
			$status = isset( $live_status[ $form_id ] ) ? $live_status[ $form_id ] : null;

			if ( null !== $status && 'trash' !== $status ) {
				$imported[ $source_id ] = true;
			} else {
				// Self-heal: the target form was deleted or trashed — drop the mapping.
				unset( $map[ $this->map_key( $slug, $source_id ) ] );
				$changed = true;
			}
		}

		if ( $changed ) {
			update_option( self::MAP_OPTION, $map, false );
		}

		return $imported;
	}

	/**
	 * Records a source form => ShapeForms form mapping.
	 *
	 * @param string     $slug      Source slug.
	 * @param int|string $source_id Source form ID.
	 * @param int        $form_id   ShapeForms form ID.
	 * @return void
	 */
	private function mark_imported( $slug, $source_id, $form_id ) {
		$map = $this->get_migration_map();
		$map[ $this->map_key( $slug, $source_id ) ] = (int) $form_id;

		update_option( self::MAP_OPTION, $map, false );
	}

	/**
	 * Counts fields across all prepared rows/columns.
	 *
	 * @param array<int, array<string, mixed>> $rows Prepared rows.
	 * @return int
	 */
	private function count_fields( $rows ) {
		$count = 0;

		foreach ( (array) $rows as $row ) {
			if ( empty( $row['columns'] ) || ! is_array( $row['columns'] ) ) {
				continue;
			}
			foreach ( $row['columns'] as $column ) {
				if ( ! empty( $column['fields'] ) && is_array( $column['fields'] ) ) {
					$count += count( $column['fields'] );
				}
			}
		}

		return $count;
	}

	/**
	 * Whether a ShapeForms form row exists (and is not trashed).
	 *
	 * @param int $form_id ShapeForms form ID.
	 * @return bool
	 */
	private function form_exists( $form_id ) {
		global $wpdb;

		$forms_table = esc_sql( $this->plugin->get_forms_table_name() );

		$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM `{$forms_table}` WHERE id = %d", $form_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return null !== $status && 'trash' !== $status;
	}

	/**
	 * Stores the import result in a per-user transient and redirects back.
	 *
	 * @param string               $slug   Source slug.
	 * @param array<string, mixed> $result Result payload for the notice.
	 * @return void
	 */
	private function redirect_with_result( $slug, $result ) {
		set_transient( 'shapeforms_migrator_result_' . get_current_user_id(), $result, MINUTE_IN_SECONDS );

		$url = admin_url( 'admin.php?page=shapeforms-lite-settings&tab=tools&tools_tab=migrator' );

		if ( '' !== $slug ) {
			$url = add_query_arg( 'migrator_source', $slug, $url );
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Requires the source classes bundled with Lite.
	 *
	 * @return void
	 */
	private function load_default_sources() {
		require_once SHAPEFORMS_LITE_PATH . 'admin/migrator/sources/class-shapeforms-lite-source-cf7.php';
		require_once SHAPEFORMS_LITE_PATH . 'admin/migrator/sources/class-shapeforms-lite-source-boldform.php';
	}
}
