<?php
/**
 * Export / Import handler for forms, entries, and settings.
 *
 * @package ShapeFormsLite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles JSON export/import from the Settings > Tools tab.
 */
class ShapeForms_Lite_Export_Import {

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
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'handle_export' ) );
		add_action( 'admin_init', array( $this, 'handle_import' ) );
	}

	/**
	 * Renders the Tools tab content inside the settings page.
	 *
	 * @param array<string, mixed> $settings Global plugin settings.
	 * @return void
	 */
	public function render_tools_tab( $settings ) {
		global $wpdb;

		$tools_sub = isset( $_GET['tools_tab'] ) ? sanitize_key( wp_unslash( $_GET['tools_tab'] ) ) : 'forms'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// Back-compat: legacy export/import deep-links now live under the Forms tab.
		if ( in_array( $tools_sub, array( 'export', 'import' ), true ) ) {
			$tools_sub = 'forms';
		}
		$tools_sub = in_array( $tools_sub, array( 'forms', 'entries', 'migrator' ), true ) ? $tools_sub : 'forms';

		$notice         = '';
		$skipped_notice = '';

		if ( isset( $_GET['shapeforms_imported'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$forms_count   = absint( $_GET['shapeforms_imported'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$entries_count = isset( $_GET['shapeforms_entries_imported'] ) ? absint( $_GET['shapeforms_entries_imported'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$skipped_count = isset( $_GET['shapeforms_import_skipped'] ) ? absint( $_GET['shapeforms_import_skipped'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$tools_sub     = 'forms';

			if ( $skipped_count > 0 ) {
				$skipped_notice = sprintf(
					/* translators: %d: number of forms that could not be read from the import file */
					_n( '%d form was skipped because its layout could not be read from the file.', '%d forms were skipped because their layout could not be read from the file.', $skipped_count, 'shapeforms' ),
					$skipped_count
				);
			}

			if ( $entries_count > 0 ) {
				$notice = sprintf(
					/* translators: 1: number of forms imported, 2: number of entries imported */
					_n( '%1$d form and %2$d entries imported successfully.', '%1$d forms and %2$d entries imported successfully.', $forms_count, 'shapeforms' ),
					$forms_count,
					$entries_count
				);
			} else {
				$notice = sprintf(
					/* translators: %d: number of forms imported */
					_n( '%d form imported successfully.', '%d forms imported successfully.', $forms_count, 'shapeforms' ),
					$forms_count
				);
			}
		}

		$forms_table = esc_sql( $this->plugin->get_forms_table_name() );
		$forms       = $wpdb->get_results( $wpdb->prepare( "SELECT id, title FROM `{$forms_table}` WHERE status != %s ORDER BY title ASC", 'trash' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		?>

		<div class="shapeforms-smtp-subtabs">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=shapeforms-lite-settings&tab=tools&tools_tab=forms' ) ); ?>" class="<?php echo 'forms' === $tools_sub ? 'active' : ''; ?>">
				<?php esc_html_e( 'Forms', 'shapeforms' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=shapeforms-lite-settings&tab=tools&tools_tab=entries' ) ); ?>" class="<?php echo 'entries' === $tools_sub ? 'active' : ''; ?>">
				<?php esc_html_e( 'Entries', 'shapeforms' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=shapeforms-lite-settings&tab=tools&tools_tab=migrator' ) ); ?>" class="<?php echo 'migrator' === $tools_sub ? 'active' : ''; ?>">
				<?php esc_html_e( 'Migrator', 'shapeforms' ); ?>
			</a>
		</div>

		<?php if ( 'forms' === $tools_sub ) : ?>

			<?php if ( $notice ) : ?>
				<div class="shapeforms-card shapeforms-card--success">
					<p><?php echo esc_html( $notice ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $skipped_notice ) : ?>
				<div class="shapeforms-card shapeforms-card--spaced">
					<p><strong><?php echo esc_html( $skipped_notice ); ?></strong></p>
				</div>
			<?php endif; ?>

			<div class="shapeforms-card shapeforms-card--spaced">
				<h3><?php esc_html_e( 'Export Forms & Settings', 'shapeforms' ); ?></h3>
				<p class="shapeforms-tab-description"><?php esc_html_e( 'Download a JSON file to migrate forms and settings — optionally with their entries — to another site.', 'shapeforms' ); ?></p>

				<form method="post">
					<?php wp_nonce_field( 'shapeforms_lite_export', 'shapeforms_export_nonce' ); ?>

					<div class="shapeforms-field-row">
						<div class="shapeforms-field-label"><?php esc_html_e( 'What to export', 'shapeforms' ); ?></div>
						<div class="shapeforms-field-control">
							<div class="shapeforms-radio-list">
								<label>
									<input type="radio" name="shapeforms_export_scope" value="all" checked>
									<?php esc_html_e( 'All forms + settings', 'shapeforms' ); ?>
								</label>
								<label>
									<input type="radio" name="shapeforms_export_scope" value="single">
									<?php esc_html_e( 'Single form', 'shapeforms' ); ?>
								</label>
								<label>
									<input type="radio" name="shapeforms_export_scope" value="settings_only">
									<?php esc_html_e( 'Plugin settings only', 'shapeforms' ); ?>
								</label>
							</div>
						</div>
					</div>

					<div class="shapeforms-field-row" id="shapeforms-export-form-select" style="display:none;">
						<div class="shapeforms-field-label"><label for="shapeforms-export-form-id"><?php esc_html_e( 'Select form', 'shapeforms' ); ?></label></div>
						<div class="shapeforms-field-control">
							<select id="shapeforms-export-form-id" name="shapeforms_export_form_id" style="max-width:100%;">
								<option value="0"><?php esc_html_e( '-- Select --', 'shapeforms' ); ?></option>
								<?php foreach ( $forms as $form ) : ?>
									<option value="<?php echo absint( $form['id'] ); ?>">
										<?php
										/* translators: %d: form ID number */
										echo esc_html( $form['title'] ? $form['title'] : sprintf( __( 'Form #%d', 'shapeforms' ), (int) $form['id'] ) );
										?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>

					<div class="shapeforms-field-row" id="shapeforms-export-entries-row">
						<div class="shapeforms-field-label"><?php esc_html_e( 'Include entries', 'shapeforms' ); ?></div>
						<div class="shapeforms-field-control">
							<label class="shapeforms-toggle">
								<input type="checkbox" name="shapeforms_export_entries" value="1">
								<span><?php esc_html_e( 'Include form submission entries in the export', 'shapeforms' ); ?></span>
							</label>
						</div>
					</div>

					<div class="shapeforms-submit-area">
						<button type="submit" name="shapeforms_export" class="button button-primary"><?php esc_html_e( 'Download Export File', 'shapeforms' ); ?></button>
					</div>
				</form>

				<?php
				wp_add_inline_script(
					'shapeforms-lite-admin',
					'(function(){
						var radios=document.querySelectorAll("input[name=\'shapeforms_export_scope\']");
						var formSelect=document.getElementById("shapeforms-export-form-select");
						var entriesRow=document.getElementById("shapeforms-export-entries-row");
						function toggle(){
							var checked=document.querySelector("input[name=\'shapeforms_export_scope\']:checked");
							if(!checked)return;
							if(formSelect)formSelect.style.display=checked.value==="single"?"":"none";
							if(entriesRow)entriesRow.style.display=checked.value==="settings_only"?"none":"";
						}
						for(var i=0;i<radios.length;i++)radios[i].addEventListener("change",toggle);
						toggle();
					})();'
				);
				?>
			</div>

			<div class="shapeforms-card shapeforms-card--spaced">
				<h3><?php esc_html_e( 'Import Forms & Settings', 'shapeforms' ); ?></h3>
				<p class="shapeforms-tab-description"><?php esc_html_e( 'Upload a full form export to restore forms, their entries, and settings.', 'shapeforms' ); ?></p>

				<form method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'shapeforms_lite_import', 'shapeforms_import_nonce' ); ?>

					<div class="shapeforms-field-row">
						<div class="shapeforms-field-label"><label for="shapeforms-import-file"><?php esc_html_e( 'JSON File', 'shapeforms' ); ?></label></div>
						<div class="shapeforms-field-control">
							<input type="file" id="shapeforms-import-file" name="shapeforms_import_file" accept=".json" required>
							<p class="description"><?php esc_html_e( 'Only .json files exported from ShapeForms are accepted.', 'shapeforms' ); ?></p>
						</div>
					</div>

					<div class="shapeforms-submit-area">
						<button type="submit" name="shapeforms_import" class="button button-primary"><?php esc_html_e( 'Upload & Import', 'shapeforms' ); ?></button>
					</div>
				</form>
			</div>

		<?php elseif ( 'migrator' === $tools_sub ) : ?>

			<?php $this->plugin->get_migrator()->render_tab(); ?>

		<?php else : ?>

			<div class="shapeforms-card shapeforms-card--spaced">
				<h3><?php esc_html_e( 'Export Entries', 'shapeforms' ); ?></h3>
				<p class="shapeforms-tab-description">
					<?php
					echo esc_html(
						/**
						 * Filters the Tools → Entries export panel description. An add-on
						 * can replace it when it adds CSV/Excel/PDF format choices to this panel.
						 *
						 * @since 1.0.0
						 *
						 * @param string $description Default panel description.
						 */
						apply_filters(
							'shapeforms_tools_entries_export_description',
							__( 'Download form submission entries as a JSON file. Upgrade to also export them as Excel or PDF.', 'shapeforms' )
						)
					);
					?>
				</p>

				<form method="post">
					<?php wp_nonce_field( 'shapeforms_lite_export', 'shapeforms_export_nonce' ); ?>
					<input type="hidden" name="shapeforms_export_scope" value="entries_only">
					<?php
					/**
					 * Fires at the top of the Tools → Entries export form so add-ons can add
					 * export options (such as a CSV/Excel/PDF format selector). Runs
					 * inside the form, before the "Which entries" scope control, so a handler's
					 * inputs post together with the export.
					 *
					 * @since 1.0.0
					 */
					do_action( 'shapeforms_tools_entries_export_fields' );
					?>

					<div class="shapeforms-field-row">
						<div class="shapeforms-field-label"><?php esc_html_e( 'Which entries', 'shapeforms' ); ?></div>
						<div class="shapeforms-field-control">
							<div class="shapeforms-radio-list">
								<label>
									<input type="radio" name="shapeforms_entries_scope" value="all" checked>
									<?php esc_html_e( 'All forms', 'shapeforms' ); ?>
								</label>
								<label>
									<input type="radio" name="shapeforms_entries_scope" value="single">
									<?php esc_html_e( 'Single form', 'shapeforms' ); ?>
								</label>
							</div>
						</div>
					</div>

					<div class="shapeforms-field-row" id="shapeforms-entries-form-select" style="display:none;">
						<div class="shapeforms-field-label"><label for="shapeforms-entries-form-id"><?php esc_html_e( 'Select form', 'shapeforms' ); ?></label></div>
						<div class="shapeforms-field-control">
							<select id="shapeforms-entries-form-id" name="shapeforms_export_form_id" style="max-width:100%;">
								<option value="0"><?php esc_html_e( '-- Select --', 'shapeforms' ); ?></option>
								<?php foreach ( $forms as $form ) : ?>
									<option value="<?php echo absint( $form['id'] ); ?>">
										<?php
										/* translators: %d: form ID number */
										echo esc_html( $form['title'] ? $form['title'] : sprintf( __( 'Form #%d', 'shapeforms' ), (int) $form['id'] ) );
										?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>

					<div class="shapeforms-submit-area">
						<button type="submit" name="shapeforms_export" class="button button-primary"><?php esc_html_e( 'Download Export File', 'shapeforms' ); ?></button>
					</div>
				</form>

				<?php
				wp_add_inline_script(
					'shapeforms-lite-admin',
					'(function(){
						var radios=document.querySelectorAll("input[name=\'shapeforms_entries_scope\']");
						var formSelect=document.getElementById("shapeforms-entries-form-select");
						function toggle(){
							var checked=document.querySelector("input[name=\'shapeforms_entries_scope\']:checked");
							if(!checked)return;
							if(formSelect)formSelect.style.display=checked.value==="single"?"":"none";
						}
						for(var i=0;i<radios.length;i++)radios[i].addEventListener("change",toggle);
						toggle();
					})();'
				);
				?>
			</div>

			<?php
			/**
			 * Fires after the Tools → Entries "Export Entries" card so an add-on can
			 * render its own card in this tab — for example an "Import Entries" card
			 * that attaches a ShapeForms entries export onto a chosen form.
			 *
			 * @since 1.0.0
			 *
			 * @param array<int, array<string, mixed>> $forms Non-trashed forms as { id, title },
			 *        so a handler can offer a target-form selector without a second query.
			 */
			do_action( 'shapeforms_tools_entries_after', $forms );
			?>

		<?php endif; ?>
		<?php
	}

	/**
	 * Handles the JSON export.
	 *
	 * @return void
	 */
	public function handle_export() {
		if ( ! isset( $_POST['shapeforms_export'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['shapeforms_export_nonce'] ?? '' ) ), 'shapeforms_lite_export' ) ) {
			return;
		}

		global $wpdb;

		$scope   = isset( $_POST['shapeforms_export_scope'] ) ? sanitize_key( wp_unslash( $_POST['shapeforms_export_scope'] ) ) : 'all';
		$form_id = isset( $_POST['shapeforms_export_form_id'] ) ? absint( $_POST['shapeforms_export_form_id'] ) : 0;

		$data = array(
			'plugin'      => 'shapeforms',
			'version'     => SHAPEFORMS_LITE_VERSION,
			'exported_at' => gmdate( 'Y-m-d H:i:s' ),
			'site_url'    => home_url(),
		);

		$forms_table   = esc_sql( $this->plugin->get_forms_table_name() );
		$entries_table = esc_sql( $this->plugin->get_entries_table_name() );

		if ( 'single' === $scope && $form_id > 0 ) {
			$data['forms'] = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$forms_table}` WHERE id = %d", $form_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( ! empty( $_POST['shapeforms_export_entries'] ) ) {
				$data['entries'] = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$entries_table}` WHERE form_id = %d ORDER BY id ASC", $form_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			}

			$data['settings'] = $this->scrub_sensitive_settings( get_option( 'shapeforms_lite_settings', array() ) );

		} elseif ( 'settings_only' === $scope ) {
			$data['settings'] = $this->scrub_sensitive_settings( get_option( 'shapeforms_lite_settings', array() ) );

		} elseif ( 'entries_only' === $scope ) {
			if ( $form_id > 0 ) {
				$data['entries'] = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$entries_table}` WHERE form_id = %d ORDER BY id ASC", $form_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			} else {
				$data['entries'] = $wpdb->get_results( "SELECT * FROM `{$entries_table}` ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			}

		} else {
			$data['forms'] = $wpdb->get_results( "SELECT * FROM `{$forms_table}` WHERE status != 'trash' ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( ! empty( $_POST['shapeforms_export_entries'] ) ) {
				$data['entries'] = $wpdb->get_results( "SELECT * FROM `{$entries_table}` ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			}

			$data['settings'] = $this->scrub_sensitive_settings( get_option( 'shapeforms_lite_settings', array() ) );
		}

		// Bundle any local SVG button icons referenced by the exported forms so the
		// icon travels inside the export and can be recreated on the destination site.
		// A button icon is stored only as a URL, which would 404 (or hot-link the
		// source site) after importing elsewhere; embedding the bytes makes it portable.
		if ( ! empty( $data['forms'] ) && is_array( $data['forms'] ) ) {
			$bundled_media = $this->collect_form_media( $data['forms'] );
			if ( ! empty( $bundled_media ) ) {
				$data['media'] = $bundled_media;
			}
		}

		$filename = ( 'entries_only' === $scope ? 'shapeforms-entries-' : 'shapeforms-export-' ) . gmdate( 'Y-m-d' ) . '.json';

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Expires: 0' );

		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * Removes credential/secret and destructive keys from a settings array so they
	 * never leave the site in an export file, nor are trusted from an import file.
	 * Drops the uninstall flag, every SMTP/mail credential (smtp_*), and any
	 * credential-like key (*password, *secret, *_key) via a pattern sweep so future
	 * sensitive keys are covered automatically.
	 *
	 * @param mixed $settings Raw settings array.
	 * @return array<string, mixed> Settings with sensitive keys removed.
	 */
	private function scrub_sensitive_settings( $settings ) {
		if ( ! is_array( $settings ) ) {
			return array();
		}
		foreach ( array_keys( $settings ) as $setting_key ) {
			$setting_key = (string) $setting_key;
			if (
				'uninstall_data' === $setting_key
				|| 0 === strpos( $setting_key, 'smtp_' )
				// Substring matches for credential-like names anywhere in the key…
				|| preg_match( '/(password|secret|token|credential|oauth|client_id|client_secret|api[_-]?key|apikey)/i', $setting_key )
				// …plus common credential suffixes (e.g. *_key, *_secret, *_token, *_auth).
				|| preg_match( '/_(key|secret|token|auth)$/i', $setting_key )
			) {
				unset( $settings[ $setting_key ] );
			}
		}
		return $settings;
	}

	/**
	 * Collects the bytes of every local SVG button icon referenced by the given forms
	 * so they can be embedded in the export and recreated on the destination site.
	 *
	 * Only reads .svg files that live inside THIS site's uploads directory (a realpath
	 * containment check blocks path traversal), and caps each file at 256 KB. The map
	 * is keyed by the exact icon URL as stored in the form settings, so the importer
	 * can match and rewrite it.
	 *
	 * @param array<int, array<string, mixed>> $forms Raw exported form rows.
	 * @return array<string, array{filename: string, mime: string, data: string}>
	 */
	private function collect_form_media( $forms ) {
		$media      = array();
		$upload_dir = wp_upload_dir();
		$base_url   = isset( $upload_dir['baseurl'] ) ? (string) $upload_dir['baseurl'] : '';
		$base_dir   = isset( $upload_dir['basedir'] ) ? (string) $upload_dir['basedir'] : '';
		$base_real  = ( '' !== $base_dir ) ? realpath( $base_dir ) : false;

		if ( '' === $base_url || false === $base_real ) {
			return $media;
		}

		foreach ( $forms as $form ) {
			$settings = isset( $form['settings_json'] ) ? json_decode( (string) $form['settings_json'], true ) : null;
			if ( ! is_array( $settings ) ) {
				continue;
			}

			$url = isset( $settings['button_icon_svg'] ) ? (string) $settings['button_icon_svg'] : '';
			if ( '' === $url || isset( $media[ $url ] ) ) {
				continue;
			}

			// Must be an SVG inside this site's uploads directory.
			$path_only = (string) wp_parse_url( $url, PHP_URL_PATH );
			if ( 0 !== strpos( $url, $base_url ) || 'svg' !== strtolower( (string) pathinfo( $path_only, PATHINFO_EXTENSION ) ) ) {
				continue;
			}

			$real = realpath( $base_dir . substr( $url, strlen( $base_url ) ) );
			if ( false === $real || 0 !== strpos( $real, $base_real ) || ! is_file( $real ) || ! is_readable( $real ) ) {
				continue;
			}

			// An icon is a few KB; refuse to bloat the export with anything large.
			if ( filesize( $real ) > 256 * 1024 ) {
				continue;
			}

			$bytes = file_get_contents( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $bytes || '' === $bytes ) {
				continue;
			}

			$media[ $url ] = array(
				'filename' => basename( $real ),
				'mime'     => 'image/svg+xml',
				'data'     => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			);
		}

		return $media;
	}

	/**
	 * Recreates bundled SVG icons as fresh files in this site's uploads directory and
	 * returns an old-URL => new-local-URL map for the importer to rewrite settings with.
	 *
	 * Each SVG is sanitized (script/handler stripping) before it is written, because the
	 * file is directly servable at its URL. Anything that fails to decode, sanitize, or
	 * write is skipped (the icon then falls back to its original URL — no worse than before).
	 *
	 * @param array<string, mixed> $media Bundled media map from the export file.
	 * @return array<string, string> old URL => new local URL.
	 */
	private function restore_media( $media ) {
		$map        = array();
		$upload_dir = wp_upload_dir();

		if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['path'] ) || empty( $upload_dir['url'] ) ) {
			return $map;
		}

		foreach ( $media as $old_url => $item ) {
			if ( ! is_array( $item ) || empty( $item['data'] ) ) {
				continue;
			}

			// Only SVG icons are bundled/restored.
			if ( 'image/svg+xml' !== ( isset( $item['mime'] ) ? (string) $item['mime'] : '' ) ) {
				continue;
			}

			$bytes = base64_decode( (string) $item['data'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $bytes || '' === $bytes || strlen( $bytes ) > 256 * 1024 ) {
				continue;
			}

			$bytes = $this->sanitize_svg_markup( $bytes );
			if ( '' === $bytes ) {
				continue;
			}

			$name = isset( $item['filename'] ) ? sanitize_file_name( (string) $item['filename'] ) : 'imported-icon.svg';
			if ( 'svg' !== strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
				$name .= '.svg';
			}
			$name = wp_unique_filename( $upload_dir['path'], $name );
			$dest = trailingslashit( $upload_dir['path'] ) . $name;

			if ( ! $this->write_file( $dest, $bytes ) ) {
				continue;
			}

			$map[ (string) $old_url ] = trailingslashit( $upload_dir['url'] ) . $name;
		}

		return $map;
	}

	/**
	 * Strips active content (script elements, on* event handlers, javascript: hrefs)
	 * from SVG markup so a restored icon file cannot execute if opened directly.
	 *
	 * @param string $svg Raw SVG markup.
	 * @return string Sanitized SVG, or '' when the input is not a valid <svg> document.
	 */
	private function sanitize_svg_markup( $svg ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return '';
		}

		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		// LIBXML_NONET blocks network access; entities are not expanded (no NOENT).
		$loaded = $dom->loadXML( $svg, LIBXML_NONET );
		libxml_clear_errors();

		if ( ! $loaded || ! $dom->documentElement || 'svg' !== strtolower( $dom->documentElement->nodeName ) ) {
			return '';
		}

		// Remove every <script> element (iterate backwards over the live list).
		$scripts = $dom->getElementsByTagName( 'script' );
		for ( $i = $scripts->length - 1; $i >= 0; $i-- ) {
			$node = $scripts->item( $i );
			if ( $node && $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}

		// Strip event-handler attributes and javascript: hrefs from every element.
		$xpath = new \DOMXPath( $dom );
		$nodes = $xpath->query( '//*' );
		if ( $nodes ) {
			foreach ( $nodes as $el ) {
				if ( ! $el->attributes ) {
					continue;
				}
				for ( $j = $el->attributes->length - 1; $j >= 0; $j-- ) {
					$attr  = $el->attributes->item( $j );
					$aname = strtolower( $attr->nodeName );
					$aval  = trim( (string) $attr->nodeValue );
					if (
						0 === strpos( $aname, 'on' )
						|| ( in_array( $aname, array( 'href', 'xlink:href' ), true ) && 0 === stripos( $aval, 'javascript:' ) )
					) {
						$el->removeAttribute( $attr->nodeName );
					}
				}
			}
		}

		$out = $dom->saveXML( $dom->documentElement );

		return is_string( $out ) ? $out : '';
	}

	/**
	 * Writes bytes to a path via WP_Filesystem (standards-compliant, no direct fopen).
	 *
	 * @param string $path     Absolute destination path.
	 * @param string $contents File contents.
	 * @return bool True on success.
	 */
	private function write_file( $path, $contents ) {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( empty( $wp_filesystem ) ) {
			WP_Filesystem();
		}
		if ( ! $wp_filesystem ) {
			return false;
		}

		return (bool) $wp_filesystem->put_contents( $path, $contents, FS_CHMOD_FILE );
	}

	/**
	 * Handles the JSON import.
	 *
	 * @return void
	 */
	public function handle_import() {
		if ( ! isset( $_POST['shapeforms_import'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['shapeforms_import_nonce'] ?? '' ) ), 'shapeforms_lite_import' ) ) {
			return;
		}

		if ( empty( $_FILES['shapeforms_import_file']['tmp_name'] ) ) {
			return;
		}

		// Confirm this is a genuine PHP upload, succeeded, and is a sane size before reading it.
		$upload_error = isset( $_FILES['shapeforms_import_file']['error'] ) ? (int) $_FILES['shapeforms_import_file']['error'] : UPLOAD_ERR_NO_FILE;
		$upload_size  = isset( $_FILES['shapeforms_import_file']['size'] ) ? (int) $_FILES['shapeforms_import_file']['size'] : 0;
		$file         = sanitize_text_field( wp_unslash( $_FILES['shapeforms_import_file']['tmp_name'] ) );

		if ( UPLOAD_ERR_OK !== $upload_error || ! is_uploaded_file( $file ) ) {
			wp_die( esc_html__( 'Unable to read the import file.', 'shapeforms' ) );
		}

		// Cap at 5 MB — an export of forms/entries/settings is far smaller.
		if ( $upload_size <= 0 || $upload_size > 5 * 1024 * 1024 ) {
			wp_die( esc_html__( 'The import file is empty or too large.', 'shapeforms' ) );
		}

		$json = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( ! $json ) {
			wp_die( esc_html__( 'Unable to read the import file.', 'shapeforms' ) );
		}

		$data = json_decode( $json, true );

		if ( ! is_array( $data ) || empty( $data['plugin'] ) || 'shapeforms' !== $data['plugin'] ) {
			wp_die( esc_html__( 'Invalid ShapeForms export file.', 'shapeforms' ) );
		}

		$imported = $this->import_data( $data );

		wp_safe_redirect(
			add_query_arg(
				array(
					'shapeforms_imported'         => (int) $imported['forms'],
					'shapeforms_entries_imported' => (int) $imported['entries'],
					'shapeforms_import_skipped'   => (int) $imported['skipped'],
				),
				admin_url( 'admin.php?page=shapeforms-lite-settings&tab=tools&tools_tab=forms' )
			)
		);
		exit;
	}

	/**
	 * Imports parsed export data into the database.
	 *
	 * @param array<string, mixed> $data Parsed JSON data.
	 * @return array{forms:int,skipped:int,entries:int} Count of forms imported, forms
	 *                                                  skipped as unreadable, and entries imported.
	 */
	private function import_data( $data ) {
		global $wpdb;

		ShapeForms_Lite_Activator::activate();

		// The importer reuses the builder's save-path sanitizers so imported forms get the
		// exact same field-type allowlisting and per-value sanitization (never trust the file).
		if ( ! class_exists( 'ShapeForms_Lite_Ajax_Save' ) ) {
			require_once SHAPEFORMS_LITE_PATH . 'admin/ajax-save.php';
		}

		$forms_imported   = 0;
		$forms_skipped    = 0;
		$entries_imported = 0;
		$id_map           = array();

		// Recreate any bundled SVG icons as local files first, so each form's icon URL
		// can be rewritten to the new local copy (keeping the icon working post-import).
		$media_map = ( ! empty( $data['media'] ) && is_array( $data['media'] ) ) ? $this->restore_media( $data['media'] ) : array();

		if ( ! empty( $data['forms'] ) && is_array( $data['forms'] ) ) {
			$forms_table = $this->plugin->get_forms_table_name();

			foreach ( $data['forms'] as $form ) {
				$old_id = isset( $form['id'] ) ? (int) $form['id'] : 0;

				// Decode then re-sanitize the structure/settings via the builder's own sanitizers,
				// so an imported file cannot store unvalidated field types or raw values.
				//
				// NOT wp_unslash()'d: these come from a json_decode()'d file, which WordPress
				// never slashed. Unslashing strips the legitimate backslash escapes INSIDE the
				// nested JSON string (\" around HTML attributes, \uXXXX for typographic
				// characters), so json_decode() then fails and the form imports with zero
				// fields and default settings. Same reasoning as entry_data_json below.
				$structure_raw     = isset( $form['fields_json'] ) ? (string) $form['fields_json'] : '';
				$settings_raw      = isset( $form['settings_json'] ) ? (string) $form['settings_json'] : '';
				$structure_decoded = '' !== $structure_raw ? json_decode( $structure_raw, true ) : array();
				$settings_decoded  = '' !== $settings_raw ? json_decode( $settings_raw, true ) : array();

				// A form whose layout is present but unreadable would otherwise import as a
				// silently empty form. Skip it and report the count instead of creating a shell.
				if ( '' !== $structure_raw && ! is_array( $structure_decoded ) ) {
					++$forms_skipped;
					continue;
				}

				if ( ! is_array( $settings_decoded ) ) {
					$settings_decoded = array();
				}

				// Point the button icon at the freshly-recreated local file (if bundled),
				// before normalize_form_settings() esc_url_raw()s it into the trusted row.
				if ( ! empty( $media_map ) && is_array( $settings_decoded )
					&& isset( $settings_decoded['button_icon_svg'] )
					&& isset( $media_map[ (string) $settings_decoded['button_icon_svg'] ] )
				) {
					$settings_decoded['button_icon_svg'] = $media_map[ (string) $settings_decoded['button_icon_svg'] ];
				}

				$fields_json       = wp_json_encode( array( 'rows' => ShapeForms_Lite_Ajax_Save::prepare_rows( is_array( $structure_decoded ) ? $structure_decoded : array() ) ) );
				$settings_json     = wp_json_encode( ShapeForms_Lite_Ajax_Save::normalize_form_settings( is_array( $settings_decoded ) ? $settings_decoded : array() ) );
				$status            = isset( $form['status'] ) ? sanitize_key( (string) $form['status'] ) : 'draft';
				$status            = in_array( $status, array( 'draft', 'publish', 'trash' ), true ) ? $status : 'draft';

				$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$forms_table,
					array(
						'title'         => isset( $form['title'] ) ? sanitize_text_field( (string) $form['title'] ) : '',
						'status'        => $status,
						'fields_json'   => $fields_json,
						'settings_json' => $settings_json,
						'created_by'    => get_current_user_id(),
					),
					array( '%s', '%s', '%s', '%s', '%d' )
				);

				if ( $inserted ) {
					$id_map[ $old_id ] = (int) $wpdb->insert_id;
					++$forms_imported;
				}
			}
		}

		if ( ! empty( $data['entries'] ) && is_array( $data['entries'] ) ) {
			$entries_table = $this->plugin->get_entries_table_name();

			foreach ( $data['entries'] as $entry ) {
				$old_form_id = isset( $entry['form_id'] ) ? (int) $entry['form_id'] : 0;
				$new_form_id = isset( $id_map[ $old_form_id ] ) ? $id_map[ $old_form_id ] : $old_form_id;

				$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$entries_table,
					array(
						'form_id'         => $new_form_id,
						// NOT wp_unslash()'d: the value comes from a json_decode()'d file, which
						// WordPress never slashes. Unslashing would strip the legitimate \" escapes
						// inside serialized/nested-JSON field values and break the whole blob's
						// decode, dropping every field in the entry.
						'entry_data_json' => $this->sanitize_entry_data_json( isset( $entry['entry_data_json'] ) ? (string) $entry['entry_data_json'] : '' ),
						'submission_key'  => isset( $entry['submission_key'] ) ? sanitize_text_field( (string) $entry['submission_key'] ) : '',
						'user_id'         => isset( $entry['user_id'] ) ? absint( $entry['user_id'] ) : 0,
						'user_ip'         => isset( $entry['user_ip'] ) ? sanitize_text_field( (string) $entry['user_ip'] ) : '',
						'user_agent'      => isset( $entry['user_agent'] ) ? sanitize_textarea_field( (string) $entry['user_agent'] ) : '',
						'status'          => in_array( isset( $entry['status'] ) ? sanitize_key( (string) $entry['status'] ) : 'unread', array( 'unread', 'read', 'spam', 'starred' ), true ) ? sanitize_key( (string) $entry['status'] ) : 'unread',
						'created_at'      => $this->sanitize_import_datetime( isset( $entry['created_at'] ) ? (string) $entry['created_at'] : '' ),
					),
					array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
				);

				if ( $inserted ) {
					++$entries_imported;
				}
			}
		}

		if ( ! empty( $data['settings'] ) && is_array( $data['settings'] ) ) {
			$existing = get_option( 'shapeforms_lite_settings', array() );

			if ( ! is_array( $existing ) ) {
				$existing = array();
			}

			$imported_settings = $data['settings'];

			// Never let an imported file carry secrets, re-route outgoing mail, or flip
			// the destructive uninstall flag. Drop the uninstall flag, every SMTP/mail
			// credential (smtp_*), and any credential-like key (*_key, *secret, *password)
			// — a pattern sweep so future sensitive keys are covered automatically.
			$imported_settings = $this->scrub_sensitive_settings( $imported_settings );

			// Sanitize every imported value by depth before merging into the trusted option.
			$imported_settings = map_deep( $imported_settings, 'sanitize_text_field' );

			update_option( 'shapeforms_lite_settings', array_merge( $existing, $imported_settings ), false );
		}

		return array(
			'forms'   => $forms_imported,
			'skipped' => $forms_skipped,
			'entries' => $entries_imported,
		);
	}

	/**
	 * Re-sanitizes an imported entry's data JSON instead of storing it verbatim.
	 *
	 * Decodes the blob and sanitizes each field's label, type and value (recursively
	 * for structured values such as name/address/checkbox), then re-encodes.
	 *
	 * @param string $raw Raw (unslashed) entry_data_json from the import file.
	 * @return string Sanitized JSON, or '{}' when the input is not decodable.
	 */
	private function sanitize_entry_data_json( $raw ) {
		$decoded = json_decode( (string) $raw, true );

		if ( ! is_array( $decoded ) ) {
			return '{}';
		}

		$clean = array();

		foreach ( $decoded as $field_id => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$type = isset( $item['type'] ) ? sanitize_key( (string) $item['type'] ) : '';

			$entry = array(
				'label' => isset( $item['label'] ) ? sanitize_text_field( (string) $item['label'] ) : '',
				'type'  => $type,
				'value' => isset( $item['value'] ) ? $this->sanitize_entry_value( $item['value'], $type ) : '',
			);

			if ( isset( $item['path'] ) ) {
				$entry['path'] = sanitize_text_field( (string) $item['path'] );
			}

			$clean[ sanitize_key( (string) $field_id ) ] = $entry;
		}

		return wp_json_encode( $clean );
	}

	/**
	 * Recursively sanitizes a single entry value (scalar, list, or assoc map).
	 *
	 * @param mixed  $value Raw value.
	 * @param string $type  The field type, passed through so add-ons can preserve
	 *                      richer content for their own field types.
	 * @return string|int|float|bool|array<int|string, mixed>
	 */
	private function sanitize_entry_value( $value, $type = '' ) {
		if ( is_array( $value ) ) {
			$out = array();

			foreach ( $value as $key => $sub ) {
				$out_key         = is_int( $key ) ? $key : sanitize_key( (string) $key );
				$out[ $out_key ] = $this->sanitize_entry_value( $sub, $type );
			}

			return $out;
		}

		// Preserve numeric and boolean scalars exactly. Casting them to string (as
		// sanitize_text_field requires) would change their JSON type and, for floats,
		// silently truncate precision. Numbers and booleans cannot carry injection.
		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
			return $value;
		}

		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$sanitized = sanitize_text_field( (string) $value );

		/**
		 * Filters an imported entry field's sanitized value.
		 *
		 * The default strips all HTML via sanitize_text_field(). Add-ons can hook
		 * this to preserve richer content for their own field types (for example an
		 * HTML-capable field) by returning a value sanitized appropriately for that
		 * type. Return the given default to leave the value unchanged.
		 *
		 * @since 1.0.0
		 *
		 * @param string $sanitized The default-sanitized (tag-stripped) value.
		 * @param mixed  $value     The raw value from the import file.
		 * @param string $type      The field type key.
		 */
		return apply_filters( 'shapeforms_import_entry_value', $sanitized, $value, $type );
	}

	/**
	 * Validates an imported MySQL datetime, falling back to "now" when malformed.
	 *
	 * Prevents a bad value from being written into a NOT NULL datetime column
	 * (strict SQL mode would reject it and silently drop the entry).
	 *
	 * @param string $value Raw datetime string.
	 * @return string Valid 'Y-m-d H:i:s' datetime.
	 */
	private function sanitize_import_datetime( $value ) {
		$value = sanitize_text_field( (string) $value );
		$dt    = '' !== $value ? DateTime::createFromFormat( 'Y-m-d H:i:s', $value ) : false;

		if ( $dt instanceof DateTime && $dt->format( 'Y-m-d H:i:s' ) === $value ) {
			return $value;
		}

		return current_time( 'mysql', true );
	}
}
