=== ShapeForms - Drag & Drop Form Builder, Contact Form, Survey & Multi-Step Forms ===
Contributors: shapekode, maha25
Tags: contact form, form builder, forms, drag and drop, gutenberg
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight drag-and-drop WordPress form builder — contact forms and more with a shortcode, Gutenberg block, Elementor widget, and email integrations.

== Description ==

**ShapeForms** is a fast, lightweight drag-and-drop form builder for WordPress. Build any form in a visual editor — no code — then embed it with a shortcode, a Gutenberg block or an Elementor widget. Every submission is stored in your own database.

* [Website](https://shapekode.com/) | [Support](https://shapekode.com/support/)

= Why ShapeForms? =

* **Drag & drop** — build and reorder fields visually, with styling that updates live.
* **Conversational forms, free** — any form becomes one-question-at-a-time with a single switch. No rebuild, no add-on.
* **Lightweight by design** — assets load only where a form appears, and each form is queried once per request.
* **No lock-in** — export and import forms, entries and settings as JSON at any time.
* **Switch over easily** — import your forms from BoldForm (with their entries and settings) or Contact Form 7 under Settings > Tools > Migrator.
* **Yours to control** — submissions live in your own database, with tools to export or erase personal data on request.
* **Extend anywhere** — a documented action/filter API covers every stage of the form lifecycle.

Perfect for contact forms, quote requests, registrations, feedback forms, surveys, job applications, event sign-ups and newsletter opt-ins.

= Drag & Drop Form Builder, Contact Form, Survey & Multi-Step Forms =

Drag fields from the sidebar into multi-column rows, reorder with a handle, and configure each field in its own panel.

* **Multi-column layouts** with adjustable widths.
* **Live Style tab** — field, label and button styling with Normal / Hover / Focus / Checked states, design themes, gradients and per-device (desktop / tablet / mobile) values.
* **Custom submit button** — your own text, a Dashicon or custom SVG icon, and icon colour.
* **11 form templates** across General, Business, Events & Booking, and HR & Surveys — import one in a click, then make it yours.


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

= 1.0.0 =
* Initial release.
