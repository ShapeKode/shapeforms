=== ShapeForms - Drag & Drop Form Builder, Contact Form, Survey & Multi-Step Forms ===
Contributors: shapekode, maha25
Tags: contact form, form builder, forms, drag and drop, gutenberg
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight drag-and-drop WordPress form builder — contact forms and more with a shortcode, Gutenberg block, Elementor widget, and email integrations.

== Description ==

**ShapeForms** is a fast, lightweight drag-and-drop form builder for WordPress. Build any form in a visual editor — no code — then embed it with a shortcode, a Gutenberg block or an Elementor widget. Every submission is stored in your own database.

* [Live Demo](https://shapekode.com/) | [Documentation](https://documentation.shapekode.com/docs/shapeforms-user-guide/) | [Roadmap](https://shapekode.com/roadmap/) | [Support](https://shapekode.com/support/) | [Community](https://www.facebook.com/groups/shapekode)

https://www.youtube.com/watch?v=EWum_aGDAMc

= Why ShapeForms? =

* **Drag & drop** — build and reorder fields visually, with styling that updates live.
* **Conversational forms, free** — any form becomes one-question-at-a-time with a single switch. No rebuild, no add-on.
* **Lightweight by design** — assets load only where a form appears, and each form is queried once per request.
* **No lock-in** — export and import forms, entries and settings as JSON at any time.
* **Yours to control** — submissions live in your own database, with tools to export or erase personal data on request.
* **Extend anywhere** — a documented action/filter API covers every stage of the form lifecycle.

Perfect for contact forms, quote requests, registrations, feedback forms, surveys, job applications, event sign-ups and newsletter opt-ins.

= Drag & Drop Form Builder, Contact Form, Survey & Multi-Step Forms =

Drag fields from the sidebar into multi-column rows, reorder with a handle, and configure each field in its own panel.

* **Multi-column layouts** with adjustable widths.
* **Live Style tab** — field, label and button styling with Normal / Hover / Focus / Checked states, design themes, gradients and per-device (desktop / tablet / mobile) values.
* **Custom submit button** — your own text, a Dashicon or custom SVG icon, and icon colour.
* **11 form templates** across General, Business, Events & Booking, and HR & Surveys — import one in a click, then make it yours.

https://www.youtube.com/watch?v=wkY9uTVaYJ0

= AI Form Builder — describe a form and have it built =

Type what you need — "a job application with a CV upload" — and the form is built on the canvas: fields, labels, help text, multi-column rows, page breaks and conditional rules, all editable afterwards.

* **Bring your own key** — Anthropic, OpenAI, Google Gemini or OpenRouter, using your own API key. Prompts go straight from your site to the provider you pick, billed to your account. Gemini has a free tier.
* **Only your real fields** — the model is constrained to the field types your site actually has, so it cannot invent one. Add-ons that register more field types extend it automatically.
* **Nothing is stored or shared** — only the sentence you type is sent. Your forms, entries and visitors are never part of it. See External Services below.

= All the Fields You Need =

* **Standard** — Text, Email, URL, Phone, Number, Numeric, Textarea
* **Choice** — Select, Multi Select, Checkbox, Radio, Country
* **Date & time** — Date and Time pickers (bundled Flatpickr)
* **Rating & range** — Star Rating with per-field colours and size, Slider / Range with an optional dual-handle mode
* **Advanced input** — Input Mask, File Upload with a size cap, stored in your WordPress uploads
* **Identity & address** — Name (first / middle / last), Address (structured multi-line)
* **Layout & content** — Section Break, Paragraph / Static Text, HTML Editor, Terms & Conditions

= Conversational Forms =

Flip one switch in Form Settings and the form you already built becomes a guided sequence of screens — same fields, same layout, same conditional logic, same notifications. Switch it off and it goes back to normal.

* **Works on forms you already have** — nothing is rewritten, and a question whose field is hidden is skipped rather than shown empty.
* **Welcome screen** — open with a title, a short intro and a Start button.
* **Progress your way** — a bar, dots, a counter or a percentage, or none; left, centre or right.
* **Keyboard first** — Enter advances, Shift+Enter starts a new line in a paragraph field.
* **Design per screen** — its own image and its own background, question and answer colours.
* **Degrades safely** — without JavaScript it renders as an ordinary single-page form and still submits.

= Smart Form Features =

* **Conditional Logic** — show or hide any field based on previous answers, with AND / OR rules per field.
* **AJAX Submission** — forms submit without a full page reload.
* **Duplicate Prevention** — optionally block repeat submissions based on a chosen field, such as email address.

= Entries Management =

Every submission is saved to a custom database table, independent of email delivery.

* View, filter and read submissions from **ShapeForms → Entries**.
* Mark entries **Read, Unread, Starred or Spam**, with a dedicated Spam tab.
* **Trash & restore** — an accidental delete is recoverable, with the original status intact.
* **Bulk actions** and **CSV export**, for all entries or just the ones you select.
* **Reports & Analytics** — totals and per-form stats, charted with the browser's native HTML5 Canvas (no external library).

= Spam Protection =

* **Honeypot** — invisible field, catches bots with no setup
* **Math Captcha** — arithmetic challenge, no API keys needed
* **Google reCAPTCHA v2**, **hCaptcha** and **Cloudflare Turnstile** — each needs a site key and secret key from the provider

= Email Marketing Integrations =

* **Email Notifications** — an admin notification per submission, sent with ShapeForms's standard layout. Set a custom "From" name and reply-to, and test delivery, from the settings panel.
* **SMTP** — route outgoing mail through your own SMTP server.
* **Mailchimp** and **Brevo (formerly Sendinblue)** — add contacts to any list with field mapping.

Configure these under **ShapeForms → Settings → Integrations**, then assign one to each form.

= Embed Anywhere =

* **Shortcode** — `[shapeforms id="123"]`
* **Gutenberg Block** — Block API v3, with a styled in-editor preview.
* **Elementor Widget** — native, with full styling controls for fields, labels and buttons.

= Tools, Privacy & Developers =

* **Import & export** — all forms, a single form with its entries, or your global settings, as JSON.
* **Privacy & GDPR** — a personal-data exporter and eraser registered with WordPress's privacy tools, plus optional remove-all-data-on-uninstall. See Privacy below.
* **Translation & multisite** — full `shapeforms` text domain; tables created automatically for each new subsite.
* **Developer API** — documented hooks for validation, submission, entry storage, integrations and rendering, plus custom field-type registration.

= Meet ShapeForms Pro =

**[ShapeForms Pro](https://shapekode.com/)** adds multi-page forms, advanced fields (Rich Text, Signature, Repeater, Calculation and more), a custom email subject and body per form, form scheduling, conversion analytics, webhooks, Stripe and PayPal payments, and 30+ integrations — inside the same builder.

[See everything in ShapeForms Pro →](https://shapekode.com/)

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/shapeforms/`, or install it directly through **Plugins → Add New** in the WordPress admin.
2. Activate the plugin through the **Plugins** screen.
3. Go to **ShapeForms** in the admin sidebar and create your first form.
4. Copy the shortcode shown in the form editor and paste it on any page, or add the form with the Gutenberg block or Elementor widget.

== Frequently Asked Questions ==

= How do I display a form? =

You have three options: paste `[shapeforms id="123"]` into any page or post, insert the **ShapeForms** Gutenberg block, or drag the **ShapeForms** widget into any Elementor layout.

= Can I use conversational mode with a form I already built? =

Yes. Open the form, go to **Form Settings → Conversational** and switch it on. Nothing about your form is rewritten — the same fields, layout, conditional logic and notifications keep working exactly as before; only the way the form is presented changes. Switch it off and the form goes straight back to normal, with your conversational settings kept in case you want them again.

= Does conversational mode work without JavaScript? =

The form still works. Visitors with JavaScript disabled see the ordinary single-page form, complete and fully submittable — they simply do not see it one question at a time.

= Where do I see form submissions? =

Go to **ShapeForms → Entries** in the WordPress admin. Click any row to open the full submission detail. You can mark entries as read, unread, starred, or spam, run bulk actions, and export to CSV.

= Does ShapeForms store submissions in the database? =

Yes. Every submission is saved to a custom table, so you always have a permanent record — independent of whether the notification email is delivered.

= How do I protect my forms from spam? =

Go to **ShapeForms → Settings → Captcha** and choose a provider. Honeypot protection is always active with no setup. You can also enable Math Captcha (no keys needed), Google reCAPTCHA v2, or hCaptcha (both require your own API keys).

= Can I connect forms to Mailchimp or Brevo? =

Yes. Add a connection under **ShapeForms → Settings → Integrations**, then assign it to a form and map the email, first-name, and last-name fields. Subscribers are added automatically on submission.

= How do I enable spam protection? =

Go to **ShapeForms > Settings > Captcha**. Choose your preferred provider (Honeypot is always active). Enter API keys for reCAPTCHA, hCaptcha, or Cloudflare Turnstile if you select those.

= Is ShapeForms multisite compatible? =

Yes. When the plugin is network-activated, it automatically creates the necessary database tables for each new subsite.

= Is ShapeForms GDPR friendly? =

Yes. Submissions stay in your own database, and ShapeForms registers a personal-data exporter and eraser with WordPress's privacy tools so you can fulfil data-subject requests by email address. See the Privacy section below for details.

= Do I need to pay to use the AI form builder? =

Not to ShapeForms — it is part of the free plugin. You do need an API key from one of the supported providers (Anthropic, OpenAI, Google Gemini or OpenRouter), and whatever that provider charges is billed to your own account. Google's Gemini has a free tier, so you can try it without spending anything. Add your key under ShapeForms > Settings > AI.

= What does the AI form builder send, and where? =

Only the description you type into the "Describe your form" box, plus a list of the field types your site supports so the model cannot suggest a field you do not have. It goes from your site directly to the provider you selected — there is no ShapeForms server in between — and the plugin does not store your prompts. Your forms, entries and visitor data are never sent. Full details for each provider are in the External Services section below.

= Is there a Pro version? =

Yes — **ShapeForms Pro** adds payments (Stripe, PayPal), multi-page forms, advanced field types, webhooks, and 30+ integrations. See the **Meet ShapeForms Pro** section above, or visit [shapekode.com/plugins/shapeforms](https://shapekode.com/).

== Screenshots ==

1. Add New Form
2. Drag & Drop Builder
3. Form Preview
4. Form Confirmation Settings
5. Form Global Settings
6. Form Analytics
7. Form Integration
8. Form View On Page

== Bundled Libraries ==

All JavaScript and CSS is bundled locally. Nothing is loaded from a CDN or any remote server, apart from the external services documented below.

**Bundled third-party libraries:**

* **Flatpickr** (`assets/js/flatpickr.min.js`, `assets/css/flatpickr.min.css`) — MIT License — https://github.com/flatpickr/flatpickr — local copy used for the date and time picker fields.

**First-party scripts:**

* `assets/js/sortable.js` — a small drag-and-drop helper written for this plugin (field ordering in the builder). Original ShapeForms code, not the third-party SortableJS library, GPL-licensed with the rest of the plugin.

The Reports chart uses the browser's native HTML5 Canvas API. No charting library is loaded.

== External Services ==

This plugin can optionally connect to the following third-party services. No data is transmitted to any external service unless you have explicitly enabled and configured that service in ShapeForms settings.

= Google reCAPTCHA =

Protects forms from spam and bot submissions.

When a page containing a reCAPTCHA-enabled form is loaded, Google's reCAPTCHA script is loaded from Google's servers. On submission, the plugin sends the reCAPTCHA token and the visitor's IP address to Google's verification API.

* Data sent: reCAPTCHA response token, visitor IP address
* When: Each form submission with reCAPTCHA enabled
* Condition: Only when "Google reCAPTCHA" is selected in ShapeForms > Settings > Captcha and valid keys are entered
* Service provider: Google LLC
* Terms of Service: https://policies.google.com/terms
* Privacy Policy: https://policies.google.com/privacy

= hCaptcha =

Privacy-friendly alternative for spam protection.

When a page with an hCaptcha-enabled form is loaded, the hCaptcha script is loaded from hCaptcha's servers. On submission, the token and visitor IP are sent to hCaptcha's verification API.

* Data sent: hCaptcha response token, visitor IP address
* When: Each form submission with hCaptcha enabled
* Condition: Only when "hCaptcha" is selected in ShapeForms > Settings > Captcha and valid keys are entered
* Service provider: Intuition Machines, Inc.
* Terms of Service: https://www.hcaptcha.com/terms
* Privacy Policy: https://www.hcaptcha.com/privacy

= Cloudflare Turnstile =

Modern, privacy-friendly spam protection with no visual puzzles.

When a page with a Turnstile-enabled form is loaded, the Turnstile script is loaded from Cloudflare's servers. On submission, the token and visitor IP are sent to Cloudflare's verification API.

* Data sent: Turnstile response token, visitor IP address
* When: Each form submission with Turnstile enabled
* Condition: Only when "Cloudflare Turnstile" is selected in ShapeForms > Settings > Captcha and valid keys are entered
* Service provider: Cloudflare, Inc.
* Terms of Service: https://www.cloudflare.com/website-terms/
* Privacy Policy: https://www.cloudflare.com/privacypolicy/

= Mailchimp =

Adds subscribers to a Mailchimp audience on form submission.

* Data sent: Email address, and optionally first name and last name (only fields you map)
* When: Each submission of a form with an active Mailchimp integration
* Condition: Only when a Mailchimp connection with a valid API key is configured under ShapeForms > Settings > Integrations
* Service provider: The Rocket Science Group LLC (Mailchimp)
* Terms of Use: https://mailchimp.com/legal/terms/
* Privacy Policy: https://mailchimp.com/legal/privacy/

= Brevo (formerly Sendinblue) =

Adds contacts to a Brevo list on form submission.

* Data sent: Email address, and optionally first name and last name (only fields you map)
* When: Each submission of a form with an active Brevo integration
* Condition: Only when a Brevo connection with a valid API key is configured under ShapeForms > Settings > Integrations
* Service provider: Brevo SAS
* Terms of Use: https://www.brevo.com/legal/termsofuse/
* Privacy Policy: https://www.brevo.com/legal/privacypolicy/

= AI Form Builder =

The AI Form Builder turns a plain-language description into a form. When you press Generate, the plugin sends the sentence you type, plus a list of the field types your site supports, to the provider you selected, and receives a form structure back. Nothing about your forms, entries or visitors is sent — only the text you type into the "Describe your form" box. Prompts are not stored by the plugin. Only the one provider selected under ShapeForms > Settings > AI is contacted, and only once you have saved your own API key for it.

= AI Form Builder — Anthropic (Claude) =

* Data sent: The description you type, and the list of field types available on your site
* When: Each time you press Generate in the form builder
* Condition: Only when "Anthropic" is selected under ShapeForms > Settings > AI and you have saved your own Anthropic API key
* Service provider: Anthropic PBC (Messages API)
* Terms of Service: https://www.anthropic.com/legal/consumer-terms
* Privacy Policy: https://www.anthropic.com/legal/privacy

= AI Form Builder — OpenAI =

* Data sent: The description you type, and the list of field types available on your site
* When: Each time you press Generate in the form builder
* Condition: Only when "OpenAI" is selected under ShapeForms > Settings > AI and you have saved your own OpenAI API key
* Service provider: OpenAI, L.L.C. (Chat Completions API)
* Terms of Service: https://openai.com/policies/row-terms-of-use/
* Privacy Policy: https://openai.com/policies/row-privacy-policy/

= AI Form Builder — Google Gemini =

* Data sent: The description you type, and the list of field types available on your site
* When: Each time you press Generate in the form builder
* Condition: Only when "Google Gemini" is selected under ShapeForms > Settings > AI and you have saved your own Google AI Studio API key
* Service provider: Google LLC (Gemini API)
* Terms of Service: https://ai.google.dev/gemini-api/terms
* Privacy Policy: https://policies.google.com/privacy

= AI Form Builder — OpenRouter =

OpenRouter is an aggregator: your description is passed on to whichever upstream provider hosts the model you chose, so their terms apply as well. The plugin also fetches OpenRouter's public model list so it can offer you a choice; that request sends nothing about your site.

* Data sent: The description you type, and the list of field types available on your site. Separately, an unauthenticated request for the public model catalogue, which sends nothing about your site
* When: Each time you press Generate in the form builder; the model catalogue is fetched at most once every 12 hours while the AI settings screen is open
* Condition: Only when "OpenRouter" is selected under ShapeForms > Settings > AI and you have saved your own OpenRouter API key
* Service provider: OpenRouter, Inc.
* Terms of Service: https://openrouter.ai/terms
* Privacy Policy: https://openrouter.ai/privacy

== Privacy ==

The AI Form Builder is the one feature that sends anything without a visitor being involved. It is off until you save your own API key, and what leaves the site is the sentence you type into the "Describe your form" box — never your forms, your entries, or anything about your visitors. The description is sent to the provider you chose, is not stored by the plugin, and is not sent anywhere else. See External Services above for each provider's terms.

When a visitor submits a form, ShapeForms stores the submission in your site's own database (no data is sent anywhere unless you have configured one of the external services listed above). Each stored entry includes:

* The values the visitor entered in the form fields (which may include personal data such as name, email address, phone number, address, and any uploaded files).
* The submitter's IP address and browser user-agent string, recorded for spam-prevention and auditing.
* The logged-in user ID, when the form is submitted by a logged-in user.

Entries are retained until you delete them. You can remove individual entries (or all of them) from **ShapeForms > Entries** at any time. If you enable "Remove all data on uninstall" in the settings, all stored forms and entries are deleted when the plugin is uninstalled.

ShapeForms integrates with WordPress's built-in privacy tools: under **Tools > Export Personal Data** and **Tools > Erase Personal Data**, an administrator can export or erase the form entries associated with a given email address to help fulfil data-subject requests.

ShapeForms – Drag &amp; Drop Form Builder uses [Appsero](https://appsero.com) SDK to collect some telemetry data upon user's confirmation. This helps us to troubleshoot problems faster & make product improvements.

Appsero SDK **does not gather any data by default.** The SDK only starts gathering basic telemetry data **when a user allows it via the admin notice**. We collect the data to ensure a great user experience for all our users.

Integrating Appsero SDK **DOES NOT IMMEDIATELY** start gathering data, **without confirmation from users in any case.**

Learn more about how [Appsero collects and uses this data](https://appsero.com/privacy-policy/).

== Changelog ==

= 1.1.8 =
New features:
* New: AI Form Builder — describe a form in plain language and have it built on the canvas, with multi-column rows, multi-step page breaks, help text and conditional show/hide rules.
* New: Choose your AI provider — Anthropic, OpenAI, Google Gemini or OpenRouter — under Settings > AI, using your own API key. Gemini has a free tier.
* New: Pick any OpenRouter model that supports strict JSON schema output; the list is fetched live and grouped by vendor.
* New: The generator only offers field types your site actually has, so add-ons that register more field types extend it automatically.
* New: A Button style for checkboxes and radios turns a choice group into selectable pills, with its own padding, radius, spacing, border and Normal/Hover/Selected states.
* New: Per-option icons for checkboxes and radios in Button style — pick a built-in glyph, or upload your own SVG or image.
* New: A checkbox or radio field can pin its own style and colours instead of always following the form.

Improvements:
* Improved: Settings dropdowns support option groups and type-ahead, so long lists are usable from the keyboard.
* Improved: The new-form and empty-canvas screens centre a row that is not full, instead of leaving the last card stranded on the left.
* Improved: Upgrade prompts name the feature or the add-on instead of saying "Unlock", so nothing reads as being withheld from you.
* Improved: The Tools export format list offers only the formats you have. Excel and PDF are described in a note instead of shown as options you cannot pick.
* Improved: Date and time fields added to a page after it loads now get a real picker instead of a dead read-only box.
* Improved: Fields drawn by an add-on follow the form's choice style, and keep their styling inside the block and Elementor editors.

Fixes:
* Fix: Changing the checkbox and radio Style no longer collapses the group you were editing.

= 1.1.7 =
New features:
* New: Conversational Forms — show any form one question at a time. Turn it on per form; your fields, layout and conditional logic stay as they are.
* New: Conversational forms can open with a welcome screen, and show a progress bar, dots, a counter or a percentage.
* New: Position the Back and Next buttons and the progress indicator, and colour them.
* New: Every screen can have its own image — left, right or behind the question — and its own colours.

Improvements:
* Improve: A required dropdown left empty is now caught before the form is sent.
* Improve: Add-ons can show their own admin notices on ShapeForms's screens.
* Improve: Locked field, template and export prompts have their own switches, so an add-on that is installed but not yet active can word them for activation.
* Improve: The Tools → Entries export format selector no longer disappears when such an add-on is installed.
* Improve: Every upgrade prompt now goes through the same filters, so an add-on can repoint them all at once.
* Improve: A new Set Amount Limits switch on the Custom Amount field. Off by default, so a pay-what-you-want field shows no minimum or maximum it doesn't use.
* Improve: Custom Amount now has its own Placeholder setting, and starts empty instead of pre-filled with 0.00.

Fixes:
* Fix: Field errors now appear under the field they belong to, not only in a summary at the top.
* Fix: The error summary now sits at the foot of the form, next to the submit button.

Developer:
* Developer: New filters — `shapeforms_admin_notices`, `shapeforms_show_locked_fields_teaser`, `shapeforms_show_locked_templates_teaser`, `shapeforms_show_locked_export_teaser`, `shapeforms_export_lock_hint`, plus lock title/text filters for the field library, templates, integrations and upgrade modal. `shapeforms_upgrade_label` gains a context argument.

= 1.1.6 =
New features:
* New: Form Migrator — import your Contact Form 7 forms in one click. Re-import anytime without creating duplicates.
* New: Page Break settings — a title per step, three progress-indicator styles with colours, and Next/Previous button styling.
* New: A checkbox can be shown as a Switch, per field. The tick box stays the default.

Improvements:
* Improve: The template library now lists premium templates with a padlock, and four previously empty categories appear.
* Improve: The Forms list gains search, sortable columns and paging.
* Improve: Templates are grouped into collapsible categories, and one that needs a disabled module says so before you import it.
* Improve: Locked integrations show "Locked" with a link to activate, instead of an upsell.
* Improve: The "ShapeForms Pro is here" notice appears only on ShapeForms's own screens.

Fixes:
* Fix: A form exported with quoted markup or special characters no longer imports with empty fields.
* Fix: A required Terms & Conditions checkbox now shows the red required asterisk.
* Fix: Clearing Slider Height, Star Size or Maximum File Size restores that setting's default instead of its minimum.
* Fix: On Entries, Apply now stays disabled until both a row and an action are chosen.
* Fix: A file dragged onto a File Upload field is no longer ignored.
* Fix: Applying a Design Theme now recolours the whole form.
* Fix: The submit button's alignment is honoured when the button sits as a field in the layout.
* Fix: The User Confirmation email section reveals its options reliably.
* Fix: A checkbox or radio option in the template preview no longer shows a doubled tick.
* Fix: The ShapeForms sidebar item highlights the page you are actually on.

Developer:
* Developer: New `shapeforms_migration_sources` filter — register an importer for another form plugin through the `ShapeForms_Lite_Migration_Source` interface.

= 1.1.5 =
Security:
* Fix: An email field now rejects an invalid address instead of quietly saving a different one. Genuine addresses are unaffected.
* Fix: Cc and Bcc on the confirmation email are re-validated the same way the admin notification's already were.

Developer:
* Developer: New `ShapeForms_Lite::supports( $capability )` — check for a hook instead of comparing version numbers. Declares `admin_email_attachments` and `user_email_attachments`.
* Developer: New `shapeforms_lite_admin_email_attachments` and `shapeforms_lite_user_email_attachments` filters — attach files to either notification. Returned paths are re-validated against the uploads directory.
* Developer: New `shapeforms_lite_admin_email_to` filter — route the admin notification by what was answered. Addresses are re-validated and anything carrying CR/LF is discarded.
* Developer: The Email Notification tab renders `.shapeforms-email-attachment-slot` and `.shapeforms-email-routing-slot` containers for add-ons.

= 1.1.4 =
New features:
* New: Rich thank-you message — write the confirmation in a full visual editor, with a Code view for hand-written HTML. Existing messages keep working.

Security:
* Fix: The confirmation message shown without JavaScript is no longer passed through the page URL, so a crafted link can no longer put fake wording under your form.

Improvements:
* Improve: A new ShapeForms logo, updated everywhere in the admin at once.
* Improve: The plugin's name now spells out what it does, so it is easier to find.
* Improve: ShapeForms has a home of its own at shapekode.com.
* Improve: Padding, margin, radius and border-width controls start with their four sides linked.
* Improve: A Reply-To set on a notification now takes precedence over the site-wide SMTP Reply-To.
* Improve: Form Settings tabs keep a consistent order and use standard WordPress icons.
* Improve: The Add New form screen uses the same toolbar as every other screen.
* Improve: Locked previews for Excel/PDF export, thank-you shortcodes, the custom email editor, premium integrations and premium fields. Free version only.

Fixes:
* Fix: The Integrations tab count badge now shows the connections actually assigned to that form.

Developer:
* Developer: The Email Notification tab renders a `.shapeforms-email-pro-slot` container in each email block for per-email add-on controls.
* Developer: The notification email filters now pass the saved entry ID, plus new `shapeforms_lite_admin_email_headers` and `shapeforms_lite_user_email_headers` filters.
* Developer: New entries-list hooks — `shapeforms_entries_list_columns`, `shapeforms_entries_where_clauses`, `shapeforms_entries_filter_controls` and `shapeforms_entry_status_badge_after`.
* Developer: New bulk-action hooks — `shapeforms_entries_bulk_actions` and `shapeforms_bulk_entry_action`.
* Developer: Form Settings tabs can declare their position with `data-stab-order`.
* Developer: New `shapeforms_show_upgrade_cta` filter — return false to suppress every upgrade prompt.

= 1.1.3 =
New features:
* New: Cloudflare Turnstile captcha — a no-puzzle alternative to reCAPTCHA and hCaptcha, verified server-side on every submission.

Improvements:
* Improve: A shorter, clearer ShapeForms Pro notice that no longer appears on the Upgrade page.
* Improve: Refreshed the Upgrade to Pro page header.
* Improve: With Pro active, every upgrade prompt is hidden.
* Improve: The Entries "Export Selected" actions are now a single dropdown.
* Improve: Tidied the SMTP settings screen.
* Improve: Entries now go to a Trash and can be restored, instead of deleting immediately.

Fixes:
* Fix: The Forms list row-actions menu is no longer clipped by the table card.

Developer:
* Developer: New `shapeforms_entry_detail_sidebar` and `shapeforms_entry_detail_enqueue_assets` actions for the single-entry screen.

= 1.1.2 =
Improvements:
* Improve: Added an Upgrade to Pro page comparing Free and Pro features.
* Improve: The Entries list supports bulk actions — Read, Unread, Starred, Spam or delete.
* Improve: Export just the checked entries to CSV.
* Improve: Tools is split into Forms and Entries tabs, with a standalone entries export.
* Improve: A Documentation menu in the toolbar links to the User and Developer guides.
* Improve: The Elementor widget gains Range Slider styling and input typography controls.
* Improve: Exporting a form bundles the submit button's custom SVG icon, so it survives the move.
* Improve: Importing an export with entries now reports how many entries were imported.

Fixes:
* Fix: Page-redirect type, duplicate prevention and field style no longer reset after saving.
* Fix: Saving one Settings tab no longer clears the others.
* Fix: Custom column widths are kept when a form is saved.
* Fix: Scheduled open and close windows behave correctly.
* Fix: The submit button's Icon Color applies to both Dashicon and custom SVG icons.
* Fix: An icon-only submit button is no longer reset to "Submit" after saving.
* Fix: Tidied spacing and alignment across the field settings panel.
* Fix: The Documentation menu also appears on the Integrations page.
* Fix: Importing entries no longer loses or alters submission data.

Maintenance:
* Update: Removed unused files and empty folders from the plugin package.

= 1.1.1 =
Improvements:
* Improve: Redesigned the Integrations dialog, and a connection now switches on as soon as you save it.
* Improve: The Forms and Entries tables scroll within their card on small screens.
* Improve: The Entries menu shows an unread-submissions count badge.
* Improve: Expanded the User and Developer guides with setup steps and screenshots.

Fixes:
* Fix: Conditional Logic rules now save reliably, including multi-condition rules and the ALL/ANY mode.

Developer:
* Developer: New hooks — `shapeforms_defer_post_save_actions`, `shapeforms_auto_populate_value` and `shapeforms_entry_value_admin_html`, plus a `shapeforms_form_reset` event after a successful AJAX submit.
* Developer: The bundled Appsero SDK moved to `includes/appsero/`.

= 1.1.0 and earlier =
Entries for 1.1.0 and earlier have been trimmed to keep this changelog within the length WordPress.org displays. See the release notes on the plugin page for the full history.

== Upgrade Notice ==

= 1.1.8 =
Adds the AI Form Builder: describe a form in plain language and have it built on the canvas, using your own key with Anthropic, OpenAI, Gemini or OpenRouter. Also adds a Button style for checkboxes and radios, with per-option icons and per-field colours. Recommended for all users.

= 1.1.7 =
Adds extension points so an add-on can show its own admin notices and word the locked field, template and export prompts for license activation rather than purchase. Fixes the Tools export format selector disappearing when an add-on was installed but not yet active. Recommended for all users.

= 1.1.6 =
Important fix: a form exported from one site imported with no fields on another. Also adds the Form Migrator (import your Contact Form 7 forms), Page Break step and progress settings, a Switch style for checkboxes, and search, sorting and paging on the Forms list. Recommended for all users.

= 1.1.5 =
Security: an email field now rejects a malformed address instead of silently correcting it, closing a header-injection route. Cc/Bcc on the visitor's confirmation email are re-validated the same way. Also adds recipient and attachment hooks and a capability API for add-ons.

= 1.1.4 =
Security fix: the no-JavaScript confirmation message is no longer passed through the page URL, so it cannot be spoofed with a crafted link. Also adds a rich thank-you editor, linked dimension controls, and new entries-list and email hooks.

= 1.1.3 =
Adds a Trash for entries (recoverable deletion with restore), Cloudflare Turnstile captcha (server-side verified), a tidier "Export Selected" dropdown, and new entry-detail extension hooks for add-ons. Recommended for all users.

= 1.1.2 =
Adds Entries bulk actions and selective CSV export, a reorganised Tools screen with standalone entry export, an Upgrade to Pro comparison page, Elementor styling additions, and fixes for settings and column widths resetting after save. Recommended for all users.

= 1.1.1 =
Fixes Conditional Logic rule saving, improves the Integrations connect-and-enable flow, makes the Forms and Entries tables mobile-friendly, and adds an unread-entries menu badge. Recommended for all users.

= 1.1.0 =
Security hardening, accessibility improvements, and GDPR export/erase support, plus the live style preview and Elementor refinements. Recommended for all users.
</content>
