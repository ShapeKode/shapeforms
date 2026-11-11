<?php
/**
 * Admin builder template.
 *
 * @package ShapeFormsLite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$shapeforms_lite_field_groups = array(
	'basic'    => __( 'Basic Fields', 'shapeforms' ),
	'advanced' => __( 'Advanced Fields', 'shapeforms' ),
);

/**
 * Filter the field group headings shown in the builder sidebar.
 *
 * Pro can add new groups (e.g. 'pro' => 'Pro Fields') so its field types
 * appear under a dedicated section.
 *
 * @param array<string, string> $groups Group key => Label pairs.
 */
$shapeforms_lite_field_groups = apply_filters( 'shapeforms_builder_field_groups', $shapeforms_lite_field_groups );
?>
<?php $shapeforms_lite_is_new_form = ! $form_data['id']; ?>
<div class="wrap shapeforms-builder-page" id="shapeforms-builder-root">

	<?php
	// Notice-placement marker — WordPress relocates admin notices to right after the first
	// .wp-header-end (wp-admin/js/common.js). On the NEW-form setup screen we want the dark
	// header bar on top and the notice BELOW it, so the marker is emitted inside the setup
	// body further down. The existing-form editor keeps it at the very top.
	?>
	<?php if ( ! $shapeforms_lite_is_new_form ) : ?>
		<hr class="wp-header-end">
	<?php endif; ?>

	<!-- Setup screen for new forms -->
	<div class="shapeforms-setup-screen" id="shapeforms-setup-screen"<?php echo ! $shapeforms_lite_is_new_form ? ' hidden' : ''; ?>>
		<?php
		// The Add-New landing shares the exact ShapeForms topbar used on every other admin
		// screen (brand + section nav + Upgrade), so the create flow stays visually
		// consistent and fully navigable instead of showing a near-empty mini header.
		// 'Forms' is the active section (Add New lives under it). The deep field-editing
		// canvas below keeps its own focused builder toolbar — matching how top form
		// plugins strip section nav only once you are actually editing fields.
		$this->render_admin_topbar( 'shapeforms' );
		?>

		<div class="shapeforms-setup-body">
			<?php // New-form marker: relocates the notice here — below the dark header bar, above "Create a New Form". ?>
			<?php if ( $shapeforms_lite_is_new_form ) : ?>
				<hr class="wp-header-end">
			<?php endif; ?>
			<div class="shapeforms-setup-intro">
				<h1><?php esc_html_e( 'Create a New Form', 'shapeforms' ); ?></h1>
				<p><?php esc_html_e( 'Start from scratch or pick a pre-built template.', 'shapeforms' ); ?></p>
			</div>
			<div class="shapeforms-setup-choices">
				<button type="button" class="shapeforms-setup-card" id="shapeforms-setup-blank">
					<span class="shapeforms-setup-card__icon shapeforms-setup-card__icon--blank">
						<span class="dashicons dashicons-plus-alt2"></span>
					</span>
					<strong><?php esc_html_e( 'Blank Form', 'shapeforms' ); ?></strong>
					<span><?php esc_html_e( 'Start with an empty canvas and build your form from scratch.', 'shapeforms' ); ?></span>
				</button>
				<button type="button" class="shapeforms-setup-card" id="shapeforms-setup-template">
					<span class="shapeforms-setup-card__icon shapeforms-setup-card__icon--template">
						<span class="dashicons dashicons-layout"></span>
					</span>
					<strong><?php esc_html_e( 'Use a Template', 'shapeforms' ); ?></strong>
					<span><?php esc_html_e( 'Browse ready-made templates, preview them, and import in one click.', 'shapeforms' ); ?></span>
				</button>
				<?php
				// A shortcut, not a separate kind of form: this creates an ordinary
				// blank form with conversational mode already switched on. There is
				// one builder and one storage model, so the author can turn the mode
				// off again — or on for any existing form — at any time.
				?>
				<button type="button" class="shapeforms-setup-card" id="shapeforms-setup-conversational">
					<span class="shapeforms-setup-card__icon shapeforms-setup-card__icon--conversational">
						<span class="dashicons dashicons-format-chat"></span>
					</span>
					<strong><?php esc_html_e( 'Conversational Form', 'shapeforms' ); ?></strong>
					<span><?php esc_html_e( 'Ask one question at a time. You can switch any form to this later.', 'shapeforms' ); ?></span>
				</button>
			</div>
		</div>
	</div>

	<div class="shapeforms-builder-main" id="shapeforms-builder-main"<?php echo $shapeforms_lite_is_new_form ? ' hidden' : ''; ?>>
	<div class="shapeforms-builder-topbar">
		<div class="shapeforms-builder-title-wrap">
			<div class="shapeforms-builder-title">
				<span class="shapeforms-builder-title__badge"><?php shapeforms_lite_brand_icon( array( 'size' => 22 ) ); ?></span>
				<input
					type="text"
					id="shapeforms-form-title"
					value="<?php echo esc_attr( $form_data['title'] ); ?>"
					placeholder="<?php esc_attr_e( 'Untitled Form', 'shapeforms' ); ?>"
				/>
			</div>

			<div class="shapeforms-editor-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Editor sections', 'shapeforms' ); ?>">
				<button type="button" class="shapeforms-editor-tab is-active" id="shapeforms-editor-tab-builder" data-editor-tab="builder" role="tab" aria-selected="true">
					<?php esc_html_e( 'Builder', 'shapeforms' ); ?>
				</button>
				<button type="button" class="shapeforms-editor-tab" id="shapeforms-editor-tab-style" data-editor-tab="style" role="tab" aria-selected="false">
					<?php esc_html_e( 'Style', 'shapeforms' ); ?>
				</button>
				<button type="button" class="shapeforms-editor-tab" id="shapeforms-editor-tab-settings" data-editor-tab="settings" role="tab" aria-selected="false">
					<?php esc_html_e( 'Settings', 'shapeforms' ); ?>
				</button>
			</div>
		</div>

		<div class="shapeforms-builder-actions">
			<button type="button" class="shapeforms-builder-shortcode<?php echo $form_data['id'] > 0 ? ' is-visible' : ''; ?>" id="shapeforms-builder-shortcode"<?php echo $form_data['id'] > 0 ? '' : ' hidden'; ?>>
				<span class="shapeforms-builder-shortcode__label"><?php esc_html_e( 'Shortcode', 'shapeforms' ); ?></span>
				<code class="shapeforms-builder-shortcode__code"><span class="shapeforms-builder-shortcode__text" id="shapeforms-builder-shortcode-code">[shapeforms id="<?php echo esc_html( (string) $form_data['id'] ); ?>"]</span><span class="dashicons dashicons-admin-page shapeforms-builder-shortcode__copy" aria-hidden="true"></span></code>
			</button>
			<a href="<?php echo $form_data['id'] > 0 ? esc_url( admin_url( 'admin.php?page=shapeforms-lite-preview&form_id=' . absint( $form_data['id'] ) ) ) : '#'; ?>" class="button shapeforms-preview-btn" id="shapeforms-preview-form"<?php echo $form_data['id'] > 0 ? '' : ' style="display:none"'; ?>>
				<span class="dashicons dashicons-visibility"></span>
				<?php esc_html_e( 'Preview', 'shapeforms' ); ?>
			</a>
			<button type="button" class="button button-primary" id="shapeforms-save-form">
				<?php esc_html_e( 'Save Form', 'shapeforms' ); ?>
			</button>
			<span class="shapeforms-builder-status" id="shapeforms-builder-status" aria-live="polite"></span>
		</div>
	</div>

	<div class="shapeforms-editor-view is-active" id="shapeforms-editor-view-builder" data-editor-view="builder">
	<div class="shapeforms-builder-shell">
		<aside class="shapeforms-builder-sidebar shapeforms-builder-sidebar-main">
			<div class="shapeforms-panel shapeforms-sidebar-panel">
				<div class="shapeforms-sidebar-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Builder panels', 'shapeforms' ); ?>">
					<button type="button" class="shapeforms-sidebar-tab is-active" id="shapeforms-tab-library" data-tab="library" role="tab" aria-selected="true">
						<?php esc_html_e( 'Field Library', 'shapeforms' ); ?>
					</button>
					<button type="button" class="shapeforms-sidebar-tab" id="shapeforms-tab-settings" data-tab="settings" role="tab" aria-selected="false">
						<?php esc_html_e( 'Field Settings', 'shapeforms' ); ?>
					</button>
				</div>

				<div class="shapeforms-sidebar-panel__content">
					<section class="shapeforms-sidebar-view is-active" id="shapeforms-sidebar-library" data-tab-panel="library" role="tabpanel" aria-labelledby="shapeforms-tab-library">
						<div class="shapeforms-panel-head">
							<h2><?php esc_html_e( 'Field Library', 'shapeforms' ); ?></h2>
							<p><?php esc_html_e( 'Drag fields into a column or click to insert into the selected column.', 'shapeforms' ); ?></p>
						</div>

						<div class="shapeforms-field-search">
								<input type="text" id="shapeforms-field-search" placeholder="<?php esc_attr_e( 'Search fields...', 'shapeforms' ); ?>" autocomplete="off">
							</div>

							<div class="shapeforms-field-library" id="shapeforms-field-library">
							<?php foreach ( $shapeforms_lite_field_groups as $shapeforms_lite_group_key => $shapeforms_lite_group_label ) : ?>
								<div class="shapeforms-library-group">
									<h3><?php echo esc_html( $shapeforms_lite_group_label ); ?></h3>
									<div class="shapeforms-library-grid">
										<?php
										$shapeforms_lite_all_fields = $this->get_field_library();

										/**
										 * Filter the complete field library used in the builder sidebar.
										 *
										 * Additional field types can be added via this filter.
										 *
										 * @param array<string, array<string, string>> $fields All field definitions.
										 * @param string                               $group  Current group being rendered.
										 */
										$shapeforms_lite_all_fields = apply_filters( 'shapeforms_builder_fields', $shapeforms_lite_all_fields, $shapeforms_lite_group_key );

										foreach ( $shapeforms_lite_all_fields as $shapeforms_lite_field_type => $shapeforms_lite_field_data ) :
											if ( ! isset( $shapeforms_lite_field_data['group'] ) || $shapeforms_lite_group_key !== $shapeforms_lite_field_data['group'] ) :
												continue;
											endif;
										?>
											<button
												type="button"
												class="shapeforms-library-item"
												data-field-type="<?php echo esc_attr( $shapeforms_lite_field_type ); ?>"
												draggable="true"
											>
												<span class="dashicons <?php echo esc_attr( $shapeforms_lite_field_data['icon'] ); ?>"></span>
												<span><?php echo esc_html( $shapeforms_lite_field_data['label'] ); ?></span>
											</button>
										<?php endforeach; ?>
									</div>
								</div>
							<?php endforeach; ?>

								<?php
								// Premium-fields teaser. Advertises the field types the paid add-on
								// adds to this palette: the chips are inert (display only) and the
								// overlay opens the shared upgrade dialog. Shown only while
								// shapeforms_show_locked_fields_teaser is on — the add-on registers its
								// own draggable fields under a "Pro Fields" group (via
								// shapeforms_builder_field_groups) once licensed, so this teaser and the
								// real fields never appear at the same time. Defaults to
								// shapeforms_show_upgrade_cta so a site with no add-on installed sees
								// unchanged behaviour; an add-on that is installed but unlicensed can
								// override this filter independently to keep showing this ONE
								// contextual teaser without reinstating every other CTA (topbar pill,
								// sale notice, etc.) that shapeforms_show_upgrade_cta also controls.
								/**
								 * Filters whether the Field Library advertises its locked premium fields.
								 *
								 * Defaults to shapeforms_show_upgrade_cta, so a site with no add-on installed
								 * behaves exactly as before. An add-on that is installed but not yet
								 * entitled overrides this on its own to keep showing this ONE contextual
								 * teaser without reinstating every other upgrade CTA.
								 *
								 * @since 1.1.7
								 *
								 * @param bool $show Whether to show the teaser. Defaults to shapeforms_show_upgrade_cta.
								 */
								if ( apply_filters( 'shapeforms_show_locked_fields_teaser', apply_filters( 'shapeforms_show_upgrade_cta', true ) ) ) :
									$shapeforms_lite_premium_fields = array(
										array( 'label' => __( 'Page Break', 'shapeforms' ), 'icon' => 'dashicons-layout' ),
										array( 'label' => __( 'Payment Item', 'shapeforms' ), 'icon' => 'dashicons-tag' ),
										array( 'label' => __( 'Quantity', 'shapeforms' ), 'icon' => 'dashicons-plus-alt2' ),
										array( 'label' => __( 'Order Summary', 'shapeforms' ), 'icon' => 'dashicons-list-view' ),
										array( 'label' => __( 'Calculation', 'shapeforms' ), 'icon' => 'dashicons-calculator' ),
										array( 'label' => __( 'Image Choice', 'shapeforms' ), 'icon' => 'dashicons-format-image' ),
										array( 'label' => __( 'Repeater', 'shapeforms' ), 'icon' => 'dashicons-editor-table' ),
										array( 'label' => __( 'Signature', 'shapeforms' ), 'icon' => 'dashicons-edit' ),
										array( 'label' => __( 'Matrix / Grid', 'shapeforms' ), 'icon' => 'dashicons-grid-view' ),
										array( 'label' => __( 'Geolocation', 'shapeforms' ), 'icon' => 'dashicons-location' ),
									);
								?>
								<div class="shapeforms-library-group shapeforms-library-group--locked">
									<h3><?php
										/**
										 * Filters the heading above the locked field group in the Field Library.
										 *
										 * The lock badge, body copy and button beneath it are all filterable, so
										 * without this an add-on could reword the whole card except the one line
										 * at the top of it — leaving a heading that sells an upgrade above a body
										 * that asks for activation.
										 *
										 * @since 1.1.7
										 *
										 * @param string $title Heading text. Default 'Premium Fields'.
										 */
										echo esc_html( apply_filters( 'shapeforms_library_lock_group_title', __( 'Premium Fields', 'shapeforms' ) ) );
									?></h3>
									<div class="shapeforms-library-lockwrap">
										<div class="shapeforms-library-grid shapeforms-library-grid--locked" aria-hidden="true">
											<?php foreach ( $shapeforms_lite_premium_fields as $shapeforms_lite_pf ) : ?>
												<span class="shapeforms-library-item is-locked">
													<span class="dashicons <?php echo esc_attr( $shapeforms_lite_pf['icon'] ); ?>"></span>
													<span><?php echo esc_html( $shapeforms_lite_pf['label'] ); ?></span>
												</span>
											<?php endforeach; ?>
										</div>
										<div class="shapeforms-library-lock">
											<span class="shapeforms-library-lock__badge dashicons dashicons-lock" aria-hidden="true"></span>
											<strong class="shapeforms-library-lock__title"><?php
												/**
												 * Filters the headline on the Field Library's lock overlay.
												 *
												 * Only the add-on that locked these fields knows why they are
												 * locked, so it owns this wording.
												 *
												 * @since 1.1.7
												 *
												 * @param string $title Headline text.
												 */
												echo esc_html( apply_filters( 'shapeforms_library_lock_title', __( 'Premium fields', 'shapeforms' ) ) );
											?></strong>
											<p class="shapeforms-library-lock__text"><?php
												/**
												 * Filters the body copy on the Field Library's lock overlay.
												 *
												 * @since 1.1.7
												 *
												 * @param string $text Body copy.
												 */
												echo esc_html( apply_filters( 'shapeforms_library_lock_text', __( 'Payments, multi-page breaks, repeaters, signatures and more — drag them straight into your forms with an upgrade.', 'shapeforms' ) ) );
											?></p>
											<button type="button" class="shapeforms-upgrade-btn shapeforms-library-lock__cta" aria-haspopup="dialog" data-upgrade-modal="shapeforms-fields-upgrade-modal">
												<span class="dashicons dashicons-lock" aria-hidden="true"></span>
												<?php echo esc_html( apply_filters( 'shapeforms_upgrade_label', __( 'Upgrade Now', 'shapeforms' ), 'button' ) ); ?>
											</button>
										</div>
									</div>
								</div>
								<?php endif; ?>
						</div>
					</section>

					<section class="shapeforms-sidebar-view" id="shapeforms-sidebar-settings" data-tab-panel="settings" role="tabpanel" aria-labelledby="shapeforms-tab-settings" hidden>
						<div class="shapeforms-panel-head">
							<h2><?php esc_html_e( 'Field Settings', 'shapeforms' ); ?></h2>
							<p><?php esc_html_e( 'Update the selected field properties.', 'shapeforms' ); ?></p>
						</div>

						<div class="shapeforms-settings-empty" id="shapeforms-settings-empty">
							<?php esc_html_e( 'Select a field to edit its settings.', 'shapeforms' ); ?>
						</div>

						<div class="shapeforms-settings-panel" id="shapeforms-settings-panel" hidden></div>
					</section>

				</div>
			</div>
		</aside>

		<main class="shapeforms-builder-canvas-wrap">
			<div class="shapeforms-panel shapeforms-canvas-panel">
				<?php if ( ! $shapeforms_lite_is_new_form ) : ?>
					<div class="shapeforms-canvas-loading" id="shapeforms-canvas-loading">
						<span class="shapeforms-canvas-loading__spinner" aria-hidden="true"></span>
						<span class="screen-reader-text"><?php esc_html_e( 'Loading form…', 'shapeforms' ); ?></span>
					</div>
				<?php endif; ?>
				<div class="shapeforms-panel-head shapeforms-panel-head--canvas">
					<div>
						<h2><?php esc_html_e( 'Form Canvas', 'shapeforms' ); ?></h2>
						<p><?php esc_html_e( 'Build layouts with rows and columns, then drop fields into each column.', 'shapeforms' ); ?></p>
					</div>
				</div>

				<div class="shapeforms-canvas-empty" id="shapeforms-canvas-empty">
					<div class="shapeforms-empty-state">
						<span class="shapeforms-empty-state__icon dashicons dashicons-layout" aria-hidden="true"></span>
						<h3><?php esc_html_e( 'Start building your form', 'shapeforms' ); ?></h3>
						<p><?php esc_html_e( 'Start from a blank canvas or open the built-in template gallery.', 'shapeforms' ); ?></p>
						<div class="shapeforms-start-grid">
							<button type="button" class="shapeforms-start-card" data-template="blank">
								<span class="shapeforms-start-card__icon"><span class="dashicons dashicons-welcome-add-page" aria-hidden="true"></span></span>
								<span class="shapeforms-start-card__body">
									<strong><?php esc_html_e( 'Blank Form', 'shapeforms' ); ?></strong>
									<span><?php esc_html_e( 'Choose your layout and build the form from scratch.', 'shapeforms' ); ?></span>
								</span>
								<span class="shapeforms-start-card__arrow dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
							</button>
							<button type="button" class="shapeforms-start-card" id="shapeforms-open-template-modal">
								<span class="shapeforms-start-card__icon"><span class="dashicons dashicons-layout" aria-hidden="true"></span></span>
								<span class="shapeforms-start-card__body">
									<strong><?php esc_html_e( 'Use a Template', 'shapeforms' ); ?></strong>
									<span><?php esc_html_e( 'Browse starter templates, preview them, and import in one click.', 'shapeforms' ); ?></span>
								</span>
								<span class="shapeforms-start-card__arrow dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
							</button>
						</div>
					</div>
				</div>

				<div class="shapeforms-canvas" id="shapeforms-canvas">
					<div class="shapeforms-canvas-rows" id="shapeforms-canvas-rows"></div>
				</div>
			</div>
		</main>
	</div>
	</div>

	<section class="shapeforms-editor-view" id="shapeforms-editor-view-style" data-editor-view="style" hidden>
		<div class="shapeforms-style-shell">
			<div class="shapeforms-panel shapeforms-style-controls">
				<div class="shapeforms-panel-head">
					<h2><?php esc_html_e( 'Form Styling', 'shapeforms' ); ?></h2>
					<p><?php esc_html_e( 'Customize field, label, button, and multi-step styles. Changes preview instantly.', 'shapeforms' ); ?></p>
				</div>
				<div class="shapeforms-settings-panel" id="shapeforms-form-styling-panel"></div>
			</div>
			<aside class="shapeforms-panel shapeforms-style-preview" aria-label="<?php esc_attr_e( 'Form preview', 'shapeforms' ); ?>">
				<div class="shapeforms-panel-head shapeforms-style-preview__head">
					<div>
						<h2><?php esc_html_e( 'Live Preview', 'shapeforms' ); ?></h2>
						<p><?php esc_html_e( 'See your styling applied to the form in real time.', 'shapeforms' ); ?></p>
					</div>
					<div class="shapeforms-device-toggle" role="group" aria-label="<?php esc_attr_e( 'Preview device', 'shapeforms' ); ?>">
						<button type="button" class="shapeforms-device-btn is-active" data-device="desktop" aria-pressed="true" title="<?php esc_attr_e( 'Desktop', 'shapeforms' ); ?>"><span class="dashicons dashicons-desktop" aria-hidden="true"></span></button>
						<button type="button" class="shapeforms-device-btn" data-device="tablet" aria-pressed="false" title="<?php esc_attr_e( 'Tablet', 'shapeforms' ); ?>"><span class="dashicons dashicons-tablet" aria-hidden="true"></span></button>
						<button type="button" class="shapeforms-device-btn" data-device="mobile" aria-pressed="false" title="<?php esc_attr_e( 'Mobile', 'shapeforms' ); ?>"><span class="dashicons dashicons-smartphone" aria-hidden="true"></span></button>
					</div>
				</div>
				<div class="shapeforms-canvas shapeforms-style-preview-canvas" id="shapeforms-style-preview-canvas"></div>
			</aside>
		</div>
	</section>

	<section class="shapeforms-editor-view" id="shapeforms-editor-view-settings" data-editor-view="settings" hidden>
		<div class="shapeforms-panel shapeforms-global-settings-panel">
			<div class="shapeforms-panel-head">
				<h2><?php esc_html_e( 'Form Settings', 'shapeforms' ); ?></h2>
				<p><?php esc_html_e( 'Configure submission behavior, redirect rules, and email notifications for this form.', 'shapeforms' ); ?></p>
			</div>
			<div class="shapeforms-settings-panel" id="shapeforms-form-settings-panel"></div>
		</div>
	</section>

	</div><!-- /.shapeforms-builder-main -->

	<div class="shapeforms-modal" id="shapeforms-row-modal" hidden>
		<div class="shapeforms-modal__backdrop" data-shapeforms-close-modal></div>
		<div class="shapeforms-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="shapeforms-row-modal-title">
			<div class="shapeforms-modal__head">
				<div class="shapeforms-modal__heading">
					<span class="shapeforms-modal__icon" aria-hidden="true"><span class="dashicons dashicons-screenoptions"></span></span>
					<div class="shapeforms-modal__heading-text">
						<h2 id="shapeforms-row-modal-title"><?php esc_html_e( 'Choose Column Layout', 'shapeforms' ); ?></h2>
						<p class="shapeforms-modal__subtitle"><?php esc_html_e( 'Select a layout for your new row.', 'shapeforms' ); ?></p>
					</div>
				</div>
				<button type="button" class="shapeforms-modal__close" data-shapeforms-close-modal aria-label="<?php esc_attr_e( 'Close', 'shapeforms' ); ?>"><span class="dashicons dashicons-no-alt"></span></button>
			</div>
			<div class="shapeforms-column-presets" id="shapeforms-column-presets"></div>
			<div class="shapeforms-modal__foot">
				<span class="shapeforms-modal__hint-icon" aria-hidden="true"><span class="dashicons dashicons-lightbulb"></span></span>
				<span><?php esc_html_e( 'You can change or add more columns anytime later.', 'shapeforms' ); ?></span>
			</div>
		</div>
	</div>

	<div class="shapeforms-modal" id="shapeforms-template-modal" hidden>
		<div class="shapeforms-modal__backdrop" data-shapeforms-close-template-modal></div>
		<div class="shapeforms-modal__dialog shapeforms-modal__dialog--template" role="dialog" aria-modal="true" aria-labelledby="shapeforms-template-modal-title">
			<div class="shapeforms-modal__head">
				<h2 id="shapeforms-template-modal-title"><?php esc_html_e( 'Choose a Template', 'shapeforms' ); ?></h2>
				<button type="button" class="shapeforms-modal__close" data-shapeforms-close-template-modal aria-label="<?php esc_attr_e( 'Close', 'shapeforms' ); ?>"><span class="dashicons dashicons-no-alt"></span></button>
			</div>
			<div class="shapeforms-template-modal">
				<div class="shapeforms-template-list" id="shapeforms-template-list"></div>
				<div class="shapeforms-template-preview">
					<div class="shapeforms-template-preview__head" id="shapeforms-template-preview__head"></div>
					<div class="shapeforms-template-preview__canvas" id="shapeforms-template-preview-canvas"></div>
					<div class="shapeforms-template-preview__actions">
						<button type="button" class="button button-primary" id="shapeforms-import-template"><?php esc_html_e( 'Import Template', 'shapeforms' ); ?></button>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
<?php
/*
 * Upgrade modal for the thank-you message shortcode teaser. Uses the same
 * .shapeforms-upgrade-modal component as the export teasers -- its styles live in
 * settings.css, which the builder loads as a dependency of builder.css. Rendered
 * only while the upgrade CTAs are shown, so it disappears (with the teaser that
 * opens it) once an add-on turns shapeforms_show_upgrade_cta off.
 */
if ( apply_filters( 'shapeforms_show_upgrade_cta', true ) ) :
	?>
	<div class="shapeforms-upgrade-modal" id="shapeforms-shortcode-upgrade-modal" hidden>
		<div class="shapeforms-upgrade-modal__backdrop" data-shapeforms-upgrade-close></div>
		<div class="shapeforms-upgrade-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="shapeforms-shortcode-upgrade-modal-title">
			<button type="button" class="shapeforms-upgrade-modal__close" data-shapeforms-upgrade-close aria-label="<?php esc_attr_e( 'Close', 'shapeforms' ); ?>"><span class="dashicons dashicons-no-alt"></span></button>
			<div class="shapeforms-upgrade-modal__icon" aria-hidden="true"><span class="dashicons dashicons-lock"></span></div>
			<h2 id="shapeforms-shortcode-upgrade-modal-title" class="shapeforms-upgrade-modal__title"><?php esc_html_e( 'Shortcodes in the premium add-on', 'shapeforms' ); ?></h2>
			<p class="shapeforms-upgrade-modal__text"><?php esc_html_e( 'ShapeForms shows the same thank-you message to everyone. Upgrade to write the submitted data straight into it — greet people by name, repeat their answers back, and show their entry details.', 'shapeforms' ); ?></p>
			<ul class="shapeforms-upgrade-modal__list">
				<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Insert any field from this form by name', 'shapeforms' ); ?></li>
				<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Show every answer back as a table with {all_fields}', 'shapeforms' ); ?></li>
				<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Add entry, site and submitter details to the message', 'shapeforms' ); ?></li>
			</ul>
			<div class="shapeforms-upgrade-modal__actions">
				<?php $this->render_upgrade_cta(); ?>
				<button type="button" class="shapeforms-upgrade-modal__dismiss" data-shapeforms-upgrade-close><?php esc_html_e( 'Maybe later', 'shapeforms' ); ?></button>
			</div>
		</div>
	</div>

	<div class="shapeforms-upgrade-modal" id="shapeforms-email-upgrade-modal" hidden>
		<div class="shapeforms-upgrade-modal__backdrop" data-shapeforms-upgrade-close></div>
		<div class="shapeforms-upgrade-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="shapeforms-email-upgrade-modal-title">
			<button type="button" class="shapeforms-upgrade-modal__close" data-shapeforms-upgrade-close aria-label="<?php esc_attr_e( 'Close', 'shapeforms' ); ?>"><span class="dashicons dashicons-no-alt"></span></button>
			<div class="shapeforms-upgrade-modal__icon" aria-hidden="true"><span class="dashicons dashicons-lock"></span></div>
			<h2 id="shapeforms-email-upgrade-modal-title" class="shapeforms-upgrade-modal__title"><?php esc_html_e( 'Email editor in the premium add-on', 'shapeforms' ); ?></h2>
			<p class="shapeforms-upgrade-modal__text"><?php esc_html_e( 'ShapeForms sends these notifications using its standard layout. Upgrade to switch on a custom email per form and write your own subject and message in a visual editor.', 'shapeforms' ); ?></p>
			<ul class="shapeforms-upgrade-modal__list">
				<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Turn a custom email on per notification, per form', 'shapeforms' ); ?></li>
				<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Write the subject and message in a visual editor, or hand-write the HTML', 'shapeforms' ); ?></li>
				<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Insert submitted data, and set a Reply-To that answers the submitter', 'shapeforms' ); ?></li>
			</ul>
			<div class="shapeforms-upgrade-modal__actions">
				<?php $this->render_upgrade_cta(); ?>
				<button type="button" class="shapeforms-upgrade-modal__dismiss" data-shapeforms-upgrade-close><?php esc_html_e( 'Maybe later', 'shapeforms' ); ?></button>
			</div>
		</div>
	</div>

	<div class="shapeforms-upgrade-modal" id="shapeforms-integration-upgrade-modal" hidden>
		<div class="shapeforms-upgrade-modal__backdrop" data-shapeforms-upgrade-close></div>
		<div class="shapeforms-upgrade-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="shapeforms-integration-upgrade-modal-title">
			<button type="button" class="shapeforms-upgrade-modal__close" data-shapeforms-upgrade-close aria-label="<?php esc_attr_e( 'Close', 'shapeforms' ); ?>"><span class="dashicons dashicons-no-alt"></span></button>
			<div class="shapeforms-upgrade-modal__icon" aria-hidden="true"><span class="dashicons dashicons-lock"></span></div>
			<h2 id="shapeforms-integration-upgrade-modal-title" class="shapeforms-upgrade-modal__title"><?php esc_html_e( 'More integrations in the premium add-on', 'shapeforms' ); ?></h2>
			<p class="shapeforms-upgrade-modal__text"><?php esc_html_e( 'ShapeForms sends submissions to Mailchimp and Brevo. Upgrade to connect your forms to CRMs, spreadsheets, messaging apps and automation tools, and send each submission wherever you work.', 'shapeforms' ); ?></p>
			<ul class="shapeforms-upgrade-modal__list">
				<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'CRMs and newsletters — HubSpot, ActiveCampaign, MailerLite, Zoho and more', 'shapeforms' ); ?></li>
				<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Spreadsheets, storage and messaging — Google Sheets, Slack, Telegram, Notion', 'shapeforms' ); ?></li>
				<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Automation — Zapier, Make and Pabbly Connect', 'shapeforms' ); ?></li>
			</ul>
			<div class="shapeforms-upgrade-modal__actions">
				<?php $this->render_upgrade_cta(); ?>
				<button type="button" class="shapeforms-upgrade-modal__dismiss" data-shapeforms-upgrade-close><?php esc_html_e( 'Maybe later', 'shapeforms' ); ?></button>
			</div>
		</div>
	</div>

	<div class="shapeforms-upgrade-modal" id="shapeforms-attachment-upgrade-modal" hidden>
		<div class="shapeforms-upgrade-modal__backdrop" data-shapeforms-upgrade-close></div>
		<div class="shapeforms-upgrade-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="shapeforms-attachment-upgrade-modal-title">
			<button type="button" class="shapeforms-upgrade-modal__close" data-shapeforms-upgrade-close aria-label="<?php esc_attr_e( 'Close', 'shapeforms' ); ?>"><span class="dashicons dashicons-no-alt"></span></button>
			<div class="shapeforms-upgrade-modal__icon" aria-hidden="true"><span class="dashicons dashicons-lock"></span></div>
			<h2 id="shapeforms-attachment-upgrade-modal-title" class="shapeforms-upgrade-modal__title"><?php esc_html_e( 'PDF attachments in the premium add-on', 'shapeforms' ); ?></h2>
			<p class="shapeforms-upgrade-modal__text"><?php esc_html_e( 'ShapeForms puts the submitted answers in the body of the notification. Upgrade to attach a formatted PDF of each submission as well — something you can file, print or forward without retyping anything.', 'shapeforms' ); ?></p>
			<ul class="shapeforms-upgrade-modal__list">
				<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Attach a PDF to the admin notification, the confirmation, or both', 'shapeforms' ); ?></li>
				<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Name each file from the form\'s own fields, and lock it with a password', 'shapeforms' ); ?></li>
				<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Download any entry as a PDF from the entry screen, at any time', 'shapeforms' ); ?></li>
			</ul>
			<div class="shapeforms-upgrade-modal__actions">
				<?php $this->render_upgrade_cta(); ?>
				<button type="button" class="shapeforms-upgrade-modal__dismiss" data-shapeforms-upgrade-close><?php esc_html_e( 'Maybe later', 'shapeforms' ); ?></button>
			</div>
		</div>
	</div>

	<div class="shapeforms-upgrade-modal" id="shapeforms-routing-upgrade-modal" hidden>
		<div class="shapeforms-upgrade-modal__backdrop" data-shapeforms-upgrade-close></div>
		<div class="shapeforms-upgrade-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="shapeforms-routing-upgrade-modal-title">
			<button type="button" class="shapeforms-upgrade-modal__close" data-shapeforms-upgrade-close aria-label="<?php esc_attr_e( 'Close', 'shapeforms' ); ?>"><span class="dashicons dashicons-no-alt"></span></button>
			<div class="shapeforms-upgrade-modal__icon" aria-hidden="true"><span class="dashicons dashicons-lock"></span></div>
			<h2 id="shapeforms-routing-upgrade-modal-title" class="shapeforms-upgrade-modal__title"><?php esc_html_e( 'Conditional routing in the premium add-on', 'shapeforms' ); ?></h2>
			<p class="shapeforms-upgrade-modal__text"><?php esc_html_e( 'ShapeForms sends every admin notification to the same address. Upgrade to route each submission to different people based on what the visitor answered — sales enquiries to one inbox, support to another.', 'shapeforms' ); ?></p>
			<ul class="shapeforms-upgrade-modal__list">
				<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Send to different people based on the answers, with rules checked in order', 'shapeforms' ); ?></li>
				<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Match on any field, and add Cc and Bcc addresses per rule', 'shapeforms' ); ?></li>
				<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Route back to the submitter, or to an address collected in the form', 'shapeforms' ); ?></li>
			</ul>
			<div class="shapeforms-upgrade-modal__actions">
				<?php $this->render_upgrade_cta(); ?>
				<button type="button" class="shapeforms-upgrade-modal__dismiss" data-shapeforms-upgrade-close><?php esc_html_e( 'Maybe later', 'shapeforms' ); ?></button>
			</div>
		</div>
	</div>
	<?php
endif;

// Modal for the Field Library's "Pro Fields" teaser above — deliberately its own
// if-block, gated by shapeforms_show_locked_fields_teaser rather than the shared
// shapeforms_show_upgrade_cta block above, so an add-on can keep this ONE
// contextual teaser+modal on without reinstating the others (shortcode/template/
// email/attachment/routing teasers) it may still want suppressed.
if ( apply_filters( 'shapeforms_show_locked_fields_teaser', apply_filters( 'shapeforms_show_upgrade_cta', true ) ) ) :
	?>
	<div class="shapeforms-upgrade-modal" id="shapeforms-fields-upgrade-modal" hidden>
		<div class="shapeforms-upgrade-modal__backdrop" data-shapeforms-upgrade-close></div>
		<div class="shapeforms-upgrade-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="shapeforms-fields-upgrade-modal-title">
			<button type="button" class="shapeforms-upgrade-modal__close" data-shapeforms-upgrade-close aria-label="<?php esc_attr_e( 'Close', 'shapeforms' ); ?>"><span class="dashicons dashicons-no-alt"></span></button>
			<div class="shapeforms-upgrade-modal__icon" aria-hidden="true"><span class="dashicons dashicons-lock"></span></div>
			<h2 id="shapeforms-fields-upgrade-modal-title" class="shapeforms-upgrade-modal__title"><?php echo esc_html( apply_filters( 'shapeforms_upgrade_modal_title', __( 'Premium fields', 'shapeforms' ), 'fields' ) ); ?></h2>
			<p class="shapeforms-upgrade-modal__text"><?php echo esc_html( apply_filters( 'shapeforms_upgrade_modal_text', __( 'ShapeForms ships all the everyday fields you need. Upgrade to add payment and advanced fields to the same drag-and-drop palette — take payments, split long forms across pages, collect signatures, and more.', 'shapeforms' ), 'fields' ) ); ?></p>
			<ul class="shapeforms-upgrade-modal__list">
				<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Payment Item, Quantity, Custom Amount and Order Summary', 'shapeforms' ); ?></li>
				<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Page Break, Repeater, Signature and Image Choice', 'shapeforms' ); ?></li>
				<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Calculation, Matrix / Grid, Geolocation and more', 'shapeforms' ); ?></li>
			</ul>
			<div class="shapeforms-upgrade-modal__actions">
				<?php $this->render_upgrade_cta(); ?>
				<button type="button" class="shapeforms-upgrade-modal__dismiss" data-shapeforms-upgrade-close><?php esc_html_e( 'Maybe later', 'shapeforms' ); ?></button>
			</div>
		</div>
	</div>
	<?php
endif;

// Modal for the template library's locked rows — its own if-block, gated by
// shapeforms_show_locked_templates_teaser rather than the shared
// shapeforms_show_upgrade_cta block above, for the same reason as the field-library
// modal: an add-on that is installed but not yet entitled still needs this one
// teaser, without reinstating every other CTA it deliberately suppresses.
if ( $this->show_locked_templates_teaser() ) :
	?>
	<div class="shapeforms-upgrade-modal" id="shapeforms-templates-upgrade-modal" hidden>
		<div class="shapeforms-upgrade-modal__backdrop" data-shapeforms-upgrade-close></div>
		<div class="shapeforms-upgrade-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="shapeforms-templates-upgrade-modal-title">
			<button type="button" class="shapeforms-upgrade-modal__close" data-shapeforms-upgrade-close aria-label="<?php esc_attr_e( 'Close', 'shapeforms' ); ?>"><span class="dashicons dashicons-no-alt"></span></button>
			<div class="shapeforms-upgrade-modal__icon" aria-hidden="true"><span class="dashicons dashicons-lock"></span></div>
			<h2 id="shapeforms-templates-upgrade-modal-title" class="shapeforms-upgrade-modal__title"><?php echo esc_html( apply_filters( 'shapeforms_upgrade_modal_title', __( 'The full template library', 'shapeforms' ), 'templates' ) ); ?></h2>
			<p class="shapeforms-upgrade-modal__text"><?php echo esc_html( apply_filters( 'shapeforms_upgrade_modal_text', __( 'ShapeForms includes ready-made forms for everyday jobs. Upgrade to add twenty-eight more across every category — take payments, book appointments, take patients through an intake, and split long forms across steps, all in one click.', 'shapeforms' ), 'templates' ) ); ?></p>
			<ul class="shapeforms-upgrade-modal__list">
				<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Payment & Calculation: orders, donations, subscriptions and live calculators', 'shapeforms' ); ?></li>
				<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Multi-Step: registration, surveys and booking with payment', 'shapeforms' ); ?></li>
				<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Health, Education and Nonprofit: patient intake, enrollment, petitions', 'shapeforms' ); ?></li>
				<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Every template is fully editable once imported', 'shapeforms' ); ?></li>
			</ul>
			<div class="shapeforms-upgrade-modal__actions">
				<?php $this->render_upgrade_cta(); ?>
				<button type="button" class="shapeforms-upgrade-modal__dismiss" data-shapeforms-upgrade-close><?php esc_html_e( 'Maybe later', 'shapeforms' ); ?></button>
			</div>
		</div>
	</div>
	<?php
endif;
