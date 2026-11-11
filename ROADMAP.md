# ShapeForms — Product Roadmap (Lite + Pro)

_Last updated: 2026-08-12 (rev 3 — Conversational Forms shipped free in Lite; license enforcement shipped in Pro) · Lite (free): 1.1.7 · Pro (paid): 1.1.4_

> **In one line:** ShapeForms is our free WordPress form builder; ShapeForms Pro is the paid add-on. This roadmap says **what we build next, in what order, and why** — for both.

---

## How to read this

| Symbol | Meaning |
|---|---|
| **Effort: S** | Small — a few days |
| **Effort: M** | Medium — about 1–2 weeks |
| **Effort: L** | Large — 3+ weeks / a mini-project |
| **Priority: High** | Do first — biggest impact on sales or user satisfaction |
| **Priority: Med** | Valuable, but can wait for the ones above |
| ⭐ | **Upgrade driver** — a feature people commonly pay to unlock |

**Release order:** Pro **1.1 → 1.2 → 2.0**. Each release is a themed batch, listed easiest-and-highest-impact first.

---

## Roadmap board — Progress · UpComing · Done

_At-a-glance board view (matches our public roadmap columns). Numbering is per-column. Details for each live in §5 below._

### 🟢 Progress — building now
**▶ Next up: Form Migrator (Import from Contact Form 7) — 🆓 Lite (free).** This is the immediate next build (planned; not yet coded). A free, Lite-side importer that brings a site's existing forms in from other form plugins — **Phase 1 ships Contact Form 7 only**, with a pluggable architecture so WPForms/Gravity/etc. are each just one more source class later. Lives as a third **Settings → Tools → "Migrator"** sub-tab (beside Forms/Entries). It's an **acquisition feature** (mirrors Fluent Forms / WPForms / Forminator, all of which ship it free) — the reason it jumps ahead of the Pro queue. Full plan-of-record: `MIGRATOR-PLAN.md`. Because it's admin-side PHP on the Tools screen (not the React builder), it ships on the **Lite 1.1.x** point line, independent of the 1.2.0 React rewrite.

Everything else from the last wave — Conditional Email Routing and PDF Attachment — is **merged and versioned** (Lite 1.1.5 / Pro 1.1.2) alongside the earlier Custom Email Editor / Entry Approval / Custom Thank-You wave (Pro 1.1.1). The "finish the notifications story" mini-release is complete.

**▶ Pro queue (resumes after the Migrator):** Marketing Tracking → Scheduled Export → Conditional Logic on Pages → Abandonment Tracking → _(Wave-2, migration first)_ Email Confirmation → Custom Entry Statuses → Coupons/Tax/Multi-Currency. Full rationale in `ROADMAP-PROGRESS.md` → **NEXT-BUILD QUEUE**, and §7 below.

**Recommended next mini-releases:** (1) **Form Migrator (CF7)** → next **Lite** free release; (2) then Marketing Tracking + Scheduled Export — both Wave-1, no DB migration, no Lite seam needed; ships as **Pro 1.2.0**.

### 🔵 UpComing — planned next (in release order)

**Next Lite (free) release — building now**
0. **Form Migrator (Import from Contact Form 7)** — 🆓 Free — Move your existing forms into ShapeForms in a few clicks. Phase 1 imports Contact Form 7 forms — fields, labels, required rules, options, and basic notification/confirmation settings — mapped automatically, with a clear report of anything that couldn't be carried over. Lives under Settings → Tools → Migrator; more source plugins (WPForms, Gravity, Ninja, Forminator…) follow, each as a drop-in later.

**Flagship (planning)**
1. **AI Form Builder** — Create complete forms from a plain-language description. Simply describe the form you need, and AI instantly generates the fields, layout, and settings — ready to refine in the drag-and-drop builder.

**Pro 1.1 — managing submissions** _(everything else in 1.1 has shipped — see Done below)_
2. **Custom Entry Statuses** — Define your own submission statuses to match your team's workflow, beyond the built-in read, starred, and spam labels.

**Pro 1.2 — smarter notifications & payments** _(Custom Email Editor, Custom Thank-You, Conditional Email Routing + PDF Attachment shipped — see Done below)_
3. **Marketing Tracking** — Capture UTM parameters and trigger Google Analytics and Meta Pixel events on submission. _(next up)_
4. **Scheduled Export** — Automatically generate and email entry exports on a recurring schedule.
5. **Conditional Logic on Pages** — Show or hide entire pages within multi-step forms based on a user's responses.
6. **Abandonment Tracking** — Record forms that were started but not submitted to reveal where users drop off.
7. **Email Confirmation** — Verify a submitter's email address through a confirmation link before the entry is finalized.
8. **Coupons, Tax & Multi-Currency** — Extend payments with discount codes, automatic tax calculation, and support for multiple currencies.

**Pro 2.0 — marquee features**
11. ~~**Conversational Mode**~~ — **SHIPPED in Lite 1.1.7 (2026-08-12), free.** Deliberately moved out of Pro: FormGent gives it away, so charging for it would have priced us against a free competitor on the one feature buyers use to compare us. See §7 for why it turned out to be low-risk rather than Wave-3.
12. **A/B Testing** — Split traffic between two versions of a form to compare performance and identify the higher-converting design.
13. **User Submission Portal** — Give logged-in users a dedicated area to view and manage their own past submissions.
14. **Subscriptions & Recurring Payments** — Collect recurring payments through Stripe and PayPal for memberships, donations, and retainers.
15. **Embed Modes** — Display forms as popups, slide-ins, or floating buttons to capture leads anywhere on the site.
16. **Premium Template Packs** — Expand the built-in free template library with additional industry-specific, ready-to-use form packs available to Pro users.
17. **Country & IP Blocking** — Restrict submissions from specific countries or IP addresses to reduce fraud and abuse.
18. **REST API & WP-CLI** — Read and create entries programmatically through a REST API and manage the plugin via the command line.
19. **Advanced Actions** — Automatically create posts, register users, or restrict form access by role upon submission.
20. **WooCommerce Integration** — Create WooCommerce orders directly from form submissions.
21. **AI Response Tools** — Use AI to summarize submissions or draft automated responses (separate from the flagship AI Form Builder).
22. **Google Places Autocomplete** — Offer address autocomplete in address fields using Google Places.
23. **Mailchimp Tags & Groups** — Apply granular Mailchimp tags and groups when syncing subscribers.
24. **ActiveCampaign Deals** — Create deals in ActiveCampaign, not just contacts, from submissions.

### ⚫ Done — built

_Tier is marked on each item: **🆓 Free** ships in Lite (WordPress.org); **⭐ Pro** requires the paid add-on._

> **Not all of this is public yet.** The last released tags are **Pro v1.1.0** and **Lite v1.1.3**. Items marked _(Pro 1.1.1 / 1.1.2)_ or _(Lite 1.1.4 / 1.1.5)_ are built and versioned on `development` but **await release** — don't announce them as live.

**Free (Lite) — shipped**
1. **Drag & Drop Builder** — 🆓 Free — Build forms visually with an intuitive drag-and-drop interface.
2. **Gutenberg Block** — 🆓 Free — Add any form to a page or post using a dedicated block in the WordPress editor.
3. **Elementor Widget** — 🆓 Free — Insert and manage forms natively within Elementor.
4. **Advanced Styling System** — 🆓 Free — Control every visual detail with per-field, per-state, and per-device design options.
5. **Conditional Logic** — 🆓 Free — Show or hide fields dynamically based on a user's responses.
6. **Entry Management** — 🆓 Free — Store, filter, and organize submissions, including spam handling and bulk actions.
7. **CSV Export** — 🆓 Free — Export form entries to CSV.
8. **Email & SMTP** — 🆓 Free — Send reliable email notifications with built-in SMTP configuration.
9. **Spam Protection** — 🆓 Free — Guard forms with reCAPTCHA, hCaptcha, and honeypot protection.
10. **GDPR Tools** — 🆓 Free — Export and erase personal data to support privacy compliance.
11. **Template Library** — 🆓 Free — Start from 11 ready-made forms organized by category (General, Business, Events & Booking, HR & Surveys) and import any of them into the builder with a single click.
12. **Cloudflare Turnstile** — 🆓 Free — Protect forms from spam and bots with Cloudflare's privacy-friendly, no-puzzle CAPTCHA — a seamless alternative to traditional challenges.

**Pro (paid) — shipped**
13. **Multi-Step Forms** — ⭐ Pro — Break long forms into multiple steps with a progress indicator.
14. **Payments** — ⭐ Pro — Accept one-time payments through Stripe and PayPal.
15. **Rich Text Field** — ⭐ Pro — Provide a full WYSIWYG editor for long-form input.
16. **Signature Field** — ⭐ Pro — Capture handwritten signatures directly on the form.
17. **Repeater Field** — ⭐ Pro — Let users add repeatable groups of fields as needed.
18. **Date Range Field** — ⭐ Pro — Select a start and end date together in a single field.
19. **Calculation Field** — ⭐ Pro — Automatically calculate values based on other field inputs.
20. **Geolocation Field** — ⭐ Pro — Detect or select a location on a map.
21. **NPS Field** — ⭐ Pro — Measure customer sentiment with a 0–10 Net Promoter Score scale.
22. **Matrix Field** — ⭐ Pro — Collect grid-based responses across rows and columns.
23. **Image Choice Field** — ⭐ Pro — Let users choose from visual, image-based options.
24. **Lookup Field** — ⭐ Pro — Search and select values from a data source as the user types.
25. **Password Field** — ⭐ Pro — Collect masked password input securely, with optional confirm-and-match validation.
26. **30+ Integrations** — ⭐ Pro — Connect forms to popular CRM, email, storage, and messaging services.
27. **Webhooks** — ⭐ Pro — Send submission data to any external URL in real time.
28. **Form Scheduling** — ⭐ Pro — Automatically open and close forms on set dates.
29. **Auto-Populate & Merge Tags** — ⭐ Pro — Pre-fill fields and reuse submitted values dynamically.
30. **Hidden Field** — ⭐ Pro — Pass hidden, pre-filled values through a form without showing them to the visitor.
31. **Analytics** — ⭐ Pro — Track form views and conversion performance.
32. **Entry Approval Workflow** — ⭐ Pro — Hold submissions for admin review before their notifications and integrations run; approve or reject from the entry screen or in bulk, with per-row badges and an approval filter on the Entries list. _(Pro 1.1.1)_
33. **Entry Editing** — ⭐ Pro — Edit a saved submission from the admin using each field's native control, with an edit-history audit trail. _(Pro 1.1.0)_
34. **Entry Notes** — ⭐ Pro — Private, admin-only notes attached to any submission (never shown, exported, or emailed). _(Pro 1.1.0)_
35. **Save & Resume** — ⭐ Pro — Visitors save a partially completed form and return via a private resume link. _(Pro 1.1.0)_
36. **Draft Auto-Save** — ⭐ Pro — Answers are saved in the visitor's browser as they type and restored if the page is reloaded before submitting. _(Pro 1.1.0)_
37. **Form Locking** — ⭐ Pro — Password-protect a form (hashed, verified server-side) before it renders or accepts submissions. _(Pro 1.1.0)_
38. **Excel & PDF Export** — ⭐ Pro — Export entries to Excel (.xlsx) and PDF alongside Lite's CSV export. _(Pro 1.1.0)_
39. **Entry Limit & Cooldown** — ⭐ Pro — Cap total submissions per form and rate-limit repeat submissions per visitor. _(Pro 1.0.0)_
40. **Akismet Spam Filtering** — ⭐ Pro — Check submissions against Akismet and block spam, per form. _(Pro 1.0.0)_
41. **Color Picker Field** — ⭐ Pro — A colour-picker field (swatch, hue map, hex input) that stores a hex colour. _(Pro 1.0.0)_
42. **Import Entries** — ⭐ Pro — Import entries from a ShapeForms export onto a chosen form, with duplicates skipped. _(Pro 1.0.0)_
43. **Custom Email Editor** — ⭐ Pro — Write a custom subject and body for the admin notification and user confirmation emails per form, with a visual/HTML editor, merge tags for any field and entry attribute, and a per-email Reply-To. _(Pro 1.1.1)_
44. **Custom Thank-You** — ⭐ Pro — Personalise the on-screen confirmation with the visitor's own answers using the same merge tags ("Thanks, {field:first_name}!"), inserted from a picker beside the message editor. _(Pro 1.1.1)_
45. **Conditional Email Routing** — ⭐ Pro — Send the admin notification to different people based on the answers, with multi-condition rules (all/any), first-match ordering, per-rule Cc/Bcc, merge-tag recipients, and checkbox-aware matching. _(Pro 1.1.2)_
46. **PDF Attachment** — ⭐ Pro — Attach a per-submission PDF (field label + value) to the admin and/or visitor email, with merge-tag filenames and optional password; a Download PDF button on every entry builds it on demand. Needs Lite 1.1.5. _(Pro 1.1.2)_

---

## 1. The product in one picture

- **ShapeForms (free, on WordPress.org)** = the drag-and-drop form builder + core essentials (build forms, collect entries, email notifications, spam protection, styling). This is how we get installs.
- **ShapeForms Pro (paid)** = the advanced add-on that unlocks power features (advanced fields, payments, multi-step, integrations, automations). This is how we make money.

**Golden rule (decided by the team):** _Anything already in Pro stays in Pro. No Pro feature — and no field type — ever moves to the free version._ We compete on quality, not by giving paid features away.

---

## 2. What already works today (so we don't re-plan it)

**Already in Lite (free):**
Drag-and-drop builder · ~26 field types · **template library** (11 ready-made forms across 4 categories, one-click import) · shows on any page via shortcode, Gutenberg block, **or Elementor** · deep design/styling controls · conditional logic (show/hide fields) · entries list (read/unread/starred/spam, bulk actions) · **CSV export** · email + SMTP (admin + submitter confirmation) · spam protection (reCAPTCHA, hCaptcha, math captcha, honeypot) · 2 integrations (Mailchimp, Brevo) · redirect / custom success message on submit · GDPR export/erase · translations.

**Already in Pro (paid):**
Advanced fields (Rich Text, Signature, Repeater, Date Range, Calculation, Geolocation, NPS, Matrix, Image Choice, Password w/ confirm-match, Lookup, Hidden) · **Multi-step forms** · **Payments** (Stripe + PayPal, one-time, single currency) · **~31 integrations** (HubSpot, Salesforce, Slack, Google Sheets, Zapier, etc.) · webhooks · scheduling (open/close dates) · analytics (views + conversions/conversion rate).

---

## 3. Why we're doing this (the market in 30 seconds)

Newer rivals — especially **FormGent** — give away a lot for free (multi-step, payments, conversational forms, AI builder) to grab installs fast. We are **not** matching that giveaway wholesale. Instead:

- **Where we're already ahead:** works natively in Elementor (rivals don't), far deeper design/styling controls, stronger security, lighter/faster on the page, more mature.
- **Where we've matched the giveaway, deliberately:** **conversational forms, free in Lite since 1.1.7.** The one exception to the paragraph above, and a considered one — it is the feature buyers use to compare form plugins head-to-head, so being absent cost us more than giving it away does. Everything around it (payments, multi-step, entry management) still points at Pro.
- **Where we're behind:** we lack some "conversion" features buyers expect — Save & Resume, entry editing/approval, conditional emails, PDF, A/B testing.
- **Our headline bet:** the **AI Form Builder** (see §5) is the main "AI Powered" hype for launch — a paid flagship meant to make ShapeForms stand out. (FormGent offers basic AI free, so this is a bet on quality over give-away.)

**Strategy:** keep the free/paid split, close the paid-feature gaps in Pro, and market our real strengths.

---

## 4. Free version (Lite) plan — small and deliberate

We are **not** adding paid features to free. Lite's additions are deliberate and take nothing from Pro: an extra spam option (shipped) and now a **Form Migrator** — an acquisition tool, not a Pro capability given away.

| Item | What it is | Why | Priority | Effort | Status |
|---|---|---|---|---|---|
| **Form Migrator (CF7 first)** | Import existing forms from other form plugins into ShapeForms; Phase 1 = Contact Form 7, pluggable for more sources later. Lives under Settings → Tools → Migrator. | **Acquisition** — the switching cost for a site with existing forms is the #1 reason not to try ShapeForms; rivals (Fluent Forms, WPForms, Forminator) all ship this free. Belongs in Lite, not Pro. | High | M | 🔵 Next up (planned) |
| **Cloudflare Turnstile** | One more free spam-protection option (we already offer reCAPTCHA + hCaptcha free) | Modern, no-puzzle captcha users increasingly expect | High | S | ✅ Shipped (Lite) |

> **Note:** CSV export stays free. **Excel and PDF export are Pro** (see below). The Migrator only imports **form structure + basic settings** — entries-migration is a later pass (CF7 stores none by default). No other free-tier features are planned right now.
>
> **Why the Migrator is free, not Pro:** it doesn't compete with any Pro capability — it removes the barrier to _starting_ with ShapeForms at all. Every install it wins is a candidate to upgrade later. Keeping it free is the whole point.

---

## 5. Pro plan — the paid roadmap

### ⭐ Flagship — AI Form Builder _(shipping soon)_
**This is our main marketing hype: "AI Powered."** The user describes the form they want in plain language ("a job application with name, email, CV upload, and 3 screening questions") and AI generates the fields and layout instantly, ready to tweak in the builder.

- **Tier:** Pro (paid). _Note: FormGent gives basic AI form creation away free — we're betting our AI is good enough to sell. Watch conversion; a limited free taste is a fallback if installs stall._
- **Why it matters:** the single biggest attention-grabber in forms right now; headline feature for launch/marketing.
- **Effort:** L. **Priority:** High. **Status:** planning (scoping — next up in UpComing).
- **Build note:** sends the prompt to an AI provider (e.g. OpenAI) and maps the response into ShapeForms's form JSON; needs an API-key setting + guardrails on output.

---

### 🚀 Pro 1.1 — "Managing submissions" _(quick wins, low risk)_ — ✅ **shipped, except Custom entry statuses**
**Goal:** turn entries from a simple list into a real workflow. These reuse systems we already have, so they ship fast.

_Shipped in Pro 1.0.0: entry limit + cooldown, Akismet, colour picker, import entries. Shipped in Pro 1.1.0: save & resume, entry editing, entry notes, Excel/PDF export, form locking. Shipped in Pro 1.1.1: entry approval workflow. **Custom entry statuses is the only one left** (Wave 2 — needs a migration)._

| Feature | What it does | Why it matters | ⭐ | Priority | Effort |
|---|---|---|---|---|---|
| **Save & Resume** | Visitor saves a half-finished form and finishes later via a private link | Big drop-off reducer on long forms; a classic paid feature | ⭐ | High | M |
| **Entry approval workflow** | Submissions arrive as "pending"; admin approves/rejects (with notification), and follow-up actions wait for approval | Moderation gate for testimonials, listings, applications; competitors charge for it | ⭐ | High | M |
| **Entry editing** | Admin can edit a saved submission (fix a typo, correct a phone number) | Frequently requested; today entries are read-only | | High | M |
| **Import entries** | Import submissions from a ShapeForms JSON export (Lite exports entries; Pro adds the import side) | Migrate/restore entries between sites; completes the export↔import round-trip | | Med | S |
| **Excel + PDF export** | Download entries as Excel or PDF (Lite stays CSV-only) | Formats buyers expect; clear reason to upgrade | | High | S |
| **Entry notes** | Private internal notes on a submission | Turns entries into a lightweight CRM/ticket record | | Med | S |
| **Custom entry statuses** | Define your own labels beyond read/unread/starred/spam (e.g. "In progress", "Refunded") | Fits entries to a team's real workflow | | Med | S |
| **Entry limit + cooldown** | Auto-close a form after N submissions; rate-limit repeat submitters | Capacity control + abuse/duplicate protection | | Med | S |
| **Form locking** | Password-protect a form before it's shown | Gate sensitive/private forms; common paid access-control feature | | Med | S |
| **Akismet spam filtering** | Adds the WordPress-standard spam service | Catches spam that captchas miss | | Med | S |
| **Color picker field** | A field where users pick a color | Rounds out the field set (stays a Pro field) | | Med | S |

**Done when:** submissions can be approved/rejected, edited, labelled, limited, exported (Excel/PDF), imported (JSON), and better protected from spam.

---

### 🧩 Pro 1.2 — "Smarter notifications & payments" — 🟡 **in progress** (email half shipped — Custom Email Editor, Custom Thank-You, Conditional Email Routing, PDF Attachment; payments/tracking half queued)
**Goal:** make emails flexible and payments commerce-grade. One groundwork item unlocks the rest.

> **Groundwork — ✅ done (Pro 1.1.1):** the **Custom Email Editor** shipped (per-form custom subject/body, merge-tag engine, per-email Reply-To). It unblocked conditional email routing + PDF attachment, both now **built and versioned (Pro 1.1.2 / Lite 1.1.5)** — see Done. The queue now advances to marketing/export/logic tracking.

| Feature | What it does | Why it matters | ⭐ | Priority | Effort |
|---|---|---|---|---|---|
| **Conditional email routing** _(✅ built, Pro 1.1.2)_ | Send to different people based on answers (e.g. "Sales" vs "Support") | Core business-form need; competitors all have it | ⭐ | High | M |
| **PDF of the submission** _(✅ built, Pro 1.1.2)_ | Auto-generate a PDF of each entry and attach it to the email | High perceived value; common paid feature | ⭐ | High | M |
| **Email confirmation (double opt-in)** | Verify the submitter's email via a token link before the entry counts | Cleaner lists; blocks fake emails | | Med | M |
| **Conditional logic on pages** | Show/hide whole pages in a multi-step form based on answers | Extends multi-step (per-field logic already exists) — smarter long forms | | Med | M |
| **Custom thank-you page** _(✅ shipped in Pro 1.1.1)_ | Personalized confirmation using answers ("Thanks, {first name}") — Lite already does basic redirect + custom success message; this adds merge-tag personalization | Better post-submit experience | | Med | M |
| **Payments: coupons, tax, multi-currency** | Discount codes, tax lines, and more than one currency (today = one currency per form) | Turns basic payments into real checkout | | High | M |
| **Auto-save draft** _(✅ shipped early in Pro 1.1.0)_ | Periodically saves progress in the browser | Prevents lost work on long forms | | Med | S |
| **Abandonment tracking** | Records forms started but not finished | Shows where people give up | | Med | M |
| **Marketing tracking** | Capture UTM source + fire Google/Meta events on submit | Lets marketers measure form performance | | Med | S |
| **Scheduled export** | Auto-email a CSV/Excel export on a schedule | Hands-off reporting for teams | | Med | S |

**Done when:** emails route by answer + carry PDFs, and payments support coupons/tax/currencies.

---

### 🏆 Pro 2.0 — "Marquee features" _(the big upgrades)_
**Goal:** the standout features that make Pro clearly worth buying. Bigger builds, highest ceiling.

| Feature | What it does | Why it matters | ⭐ | Priority | Effort |
|---|---|---|---|---|---|
| ~~**Conversational mode**~~ | ~~One-question-at-a-time forms (Typeform-style)~~ | **✅ Shipped free in Lite 1.1.7** — not a Pro feature. Moved because it is a rival's headline _free_ feature | ⭐ | — | — |
| **A/B testing** | Split traffic between two form versions, pick the winner | Almost no WP form plugin does this well — a differentiator | ⭐ | High | L |
| **User submission portal** | Logged-in users see/manage their own past submissions | Adds a whole self-service layer | ⭐ | Med | L |
| **Subscriptions / recurring payments** | Stripe/PayPal recurring billing | Unlocks memberships, donations, retainers | ⭐ | High | L |
| **Embed modes** | Popup, slide-in, floating-button forms | More ways to capture leads | | Med | M |
| **Premium template packs** | Extra industry-specific form packs on top of the free template library that already ships in Lite (11 templates, one-click import) | More onboarding value + a clear Pro upsell | | Med | M |
| **Country / IP blocking** | Reject submissions from chosen countries or IP addresses | Abuse/fraud control for high-traffic forms | | Med | M |
| **Developer tools** | REST API + WP-CLI (read/create entries, export, cleanup) | Appeals to agencies/developers | | Med | M |
| **Advanced actions** | Create a post/CPT, register a user, or restrict a form by role on submit | Powerful automation use-cases | | Med | M |
| **AI response tools** | Summarize entries or auto-draft replies (separate from the flagship AI Form Builder) | Extends the AI story after launch | | Med | M |
| **More integrations** | WooCommerce, Google Places address autocomplete, richer Mailchimp tags/ActiveCampaign deals | Broaden the ecosystem | | Med | M |

**Done when:** ShapeForms competes head-to-head with the top plugins and offers things they don't. Conversational is done — as a free Lite feature — so A/B testing is now the load-bearing half of this goal.

---

## 6. What we're deliberately NOT doing (for now)

| Item | Why not |
|---|---|
| **SMS / WhatsApp notifications** | Ongoing per-message cost — poor fit for a one-time-license product |
| **Full heatmaps** | Heavy to build; abandonment tracking (1.2) gives most of the value for far less |
| **RTL support** | Mostly handled already via translations; treat as polish, not a headline feature |
| **Standalone one-time respondent links** ("unique URL per respondent") | The private-link mechanism is already covered by **Save & Resume** (Pro 1.1); not planned as a separate feature |

---

## 7. Build plan — organized by risk

We verified every planned item against the actual code. The architecture is safe to extend (Pro adds features through stable Lite hooks; data is stored as field-keyed JSON; database changes are additive with safe defaults). So the plan below is ordered by **risk to existing installs**, not just by marketing tier — build the safe things first, isolate the two dangerous ones for last.

**Golden rule for every item:** all database changes must be **additive with safe defaults** (nullable columns, `DEFAULT 'USD'`, or brand-new tables), applied with the same existence-check migration pattern we already use for the `payment_status` column. Do that and existing forms, existing entries, and free Lite users are never affected.

### Wave 1 — Safe (build first, ship fast) · risk: LOW
Reuse hooks/systems that already exist; live entirely in Pro or use proven patterns. No risk to existing behavior.

- **From Pro 1.1:** Entry Editing · Import Entries · Entry Notes · Entry Limit & Cooldown · Form Locking · Akismet · Color Picker Field · Excel & PDF Export
- **From Pro 1.2:** Custom Email Editor ✅ _(shipped 1.1.1)_ · Custom Thank-You ✅ _(shipped 1.1.1)_ · Draft Auto-Save ✅ _(shipped early, 1.1.0)_ · Conditional Email Routing · PDF Attachment · Conditional Logic on Pages · Abandonment Tracking · Marketing Tracking · Scheduled Export
- **From Pro 2.0:** User Submission Portal · Embed Modes · Country & IP Blocking · REST API & WP-CLI · Advanced Actions · WooCommerce · AI Response Tools · Google Places · Mailchimp Tags & Groups · ActiveCampaign Deals · Premium Template Packs
- **Free (Lite):** **Form Migrator (CF7)** 🔵 _next up_ · Cloudflare Turnstile ✅ _shipped_

> **Migrator risk = LOW.** It only ever _creates new_ ShapeForms forms (never mutates existing ones), and it does so through Lite's own proven save path — `ShapeForms_Lite_Ajax_Save::prepare_rows()` + `::normalize_form_settings()` + `$wpdb->insert`, exactly what the JSON importer already uses. No schema change, no new DB column. The one thing to get right: re-import must **update** the previously-migrated form (idempotency marker) instead of duplicating — see `MIGRATOR-PLAN.md` §9.

### Wave 2 — Needs a deliberate migration or careful design (plan before coding) · risk: MEDIUM
Achievable and backward-safe, but each has one specific thing to get right first.

| Item | Prerequisite / the thing to get right |
|---|---|
| **Entry Approval Workflow** | Reuse the existing `shapeforms_defer_post_save_actions` deferral (same mechanism payments already uses to hold notifications, then re-fire on completion). Add an `approval_status` column + approve/reject UI. **No instant-notification leak** if built this way. |
| **Email Confirmation (double opt-in)** | Hold the entry as unconfirmed via the same deferral pattern + a Pro-owned confirmations table; finalize on link click. |
| **A/B Testing** | Add a variant marker to entries + variant config to forms (additive columns). |
| **Custom Entry Statuses** | ⚠️ The one item that can touch *existing* behavior — statuses are hard-coded in the admin tabs, counts, and the import whitelist. **Store custom labels in a separate `custom_status` column** and leave Lite's base enum untouched, so nothing existing breaks. |
| **Multi-Currency Payments** (part of Coupons/Tax/Multi-Currency) | Add a per-entry `currency` column defaulting old rows to `USD`, so switching currency never silently reinterprets historical orders. |

### Wave 3 — High risk to critical paths (build last, test hard) · risk: HIGH
These touch the two most fragile paths — form rendering and the payment flow. Do them only once Waves 1–2 are stable.

- ~~**Conversational Mode** — the frontend assumes all fields are in the DOM; one-at-a-time rendering can break conditional logic. Requires reworking the conditional-logic evaluator per-page (or disabling it in this mode). Touches the most-used code path.~~
  **RETIRED 2026-08-09 — this risk does not exist.** It assumed the mode would re-render the form. It does not: it post-processes the already-rendered HTML and reveals one row at a time by toggling classes, so **every field stays in the DOM** and the conditional-logic evaluator is untouched. Verified at runtime — a branching form skips its hidden screens in both directions, hidden required fields never block, and every field still posts. The feature also shipped in **Lite, free** rather than as a paid item; see `CONVERSATIONAL-PLAN.md`.
- **Subscriptions & Recurring Payments** — a billing state machine (renewals, cancellations, webhook idempotency) on top of the already-deferred payment flow. Existing one-time payment forms must keep working untouched. Get it wrong → double-charge or orphaned entries.

### The flagship sits across waves
**AI Form Builder** only *creates new* forms — it never mutates existing ones, so it carries no backward-compat risk. The one guardrail: validate the AI's output against the form-JSON schema before saving (add a `shapeforms_validate_form_json()` check) so malformed output can't reach the builder/renderer.

### Why this order
1. **Wave 1 ships the most value for the least risk** — most of Pro 1.1/1.2 and half of 2.0 fall here, so early releases stay safe and fast.
2. **Wave 2 is gated on one migration each** — cheap, but must be designed before coding so historical data stays valid.
3. **Wave 3 is quarantined** — the only two features that can destabilize rendering or billing are built last, in isolation, with the rest of the platform already proven.

> Marketing tiers (1.1 / 1.2 / 2.0) still hold for messaging; this wave view is the *engineering* order within and across them.

---

## 8. Feature-wishlist reconciliation (source: feature brainstorm)

Every item from the original brainstorm, mapped to reality. **Key takeaway: most of the "features to add" already ship** — the brainstorm predated the current build. Legend: ✅ released (public) · 🚢 built + versioned, **awaiting release** · 🚧 in progress (partly built) · 📅 planned (release) · 🆕 newly added to plan · ⏸ parked/deferred.

> **Released vs built.** The last public tags are **Pro v1.1.0** and **Lite v1.1.3**. Everything marked Pro 1.1.1 or Lite 1.1.4 is 🚢 — written, versioned and on `development`, but nobody outside this repo has it yet.

| Brainstormed feature | Status | Where |
|---|---|---|
| Password field | ✅ Shipped (Pro) | Includes confirm-and-match validation (already shipped) |
| Color picker field | ✅ Shipped (Pro) | Pro 1.0.0 — wp-color-picker widget |
| Rich text field | ✅ Shipped (Pro) | — |
| Date range picker | ✅ Shipped (Pro) | — |
| Rating / NPS field | ✅ Shipped (Pro) | — |
| Matrix / grid field | ✅ Shipped (Pro) | — |
| Lookup / autocomplete field | ✅ Shipped (Pro) | — |
| Geolocation field | ✅ Shipped (Pro, map-pick) | — |
| Multi-step forms | ✅ Shipped (Pro) | — |
| Conditional logic on pages | 🆕 → 📅 | Pro 1.2 (per-field logic already shipped) |
| Save & resume | ✅ Shipped (Pro) | Pro 1.1.0 |
| Form locking (password) | ✅ Shipped (Pro) | Pro 1.1.0 — hashed, verified server-side |
| Entry limit | ✅ Shipped (Pro) | Pro 1.0.0 |
| Submission cooldown | ✅ Shipped (Pro) | Pro 1.0.0 — per-visitor rate limit, with entry limit |
| Draft auto-save | ✅ Shipped (Pro) | Pro 1.1.0 — shipped early, ahead of the 1.2 batch |
| Partial / abandonment tracking | 📅 | Pro 1.2 |
| Custom email editor (subject/body + merge tags) | 🚢 Awaiting release | Pro 1.1.1 (unreleased) — not in the original brainstorm; groundwork for the two rows below |
| Conditional email routing | 🚢 Awaiting release | Merged to `development`; Lite seam + Pro module built, verified, versioned (Lite 1.1.5 / Pro 1.1.2). Pending SVN release |
| PDF attachment | 🚢 Awaiting release | Merged to `development`; Lite seam + Pro module built, verified, versioned (Lite 1.1.5 / Pro 1.1.2). Pending SVN release |
| Email confirmation (token) | 🆕 → 📅 | Pro 1.2 |
| WhatsApp / SMS | ⏸ Parked | cost/fit |
| Coupon / discount codes | 📅 | Pro 1.2 |
| Subscription / recurring payments | 📅 | Pro 2.0 |
| Tax calculation | 📅 | Pro 1.2 |
| Multi-currency | 📅 | Pro 1.2 (one currency/form ships today) |
| Entry editing | ✅ Shipped (Pro) | Pro 1.1.0 — with an edit-history audit trail |
| Import entries | ✅ Shipped (Pro) | Pro 1.0.0 — Tools → Entries, dedupe by submission key |
| User submission portal | 📅 | Pro 2.0 |
| Entry notes | ✅ Shipped (Pro) | Pro 1.1.0 — private, never exported or emailed |
| Custom entry statuses | 🆕 → 📅 | Pro 1.1 |
| CSV / Excel scheduled export | 📅 | Pro 1.2 — **scheduled** only; manual Excel/PDF ✅ shipped Pro 1.1.0, CSV ✅ free |
| Entry approval workflow | 🚢 Awaiting release | Pro 1.1.1 (unreleased) — defer → approve/reject, send-once |
| Turnstile captcha | ✅ Shipped (Free/Lite) | **Lite (free)** — no-puzzle Cloudflare CAPTCHA |
| Akismet | ✅ Shipped (Pro) | Pro 1.0.0 — per-form, fail-open |
| Country / IP blocking | 📅 | Pro 2.0 |
| Form token / one-time links | ⏸ | covered by Save & Resume |
| WooCommerce | 📅 | Pro 2.0 |
| Stripe subscriptions | 📅 | Pro 2.0 (= recurring payments) |
| AI Form Builder (generate a form from a prompt) | 🆕 → 📅 | **Flagship — shipping soon (Pro)** |
| OpenAI / AI — auto-respond / summarize submissions | 📅 | Pro 2.0 (AI response tools) |
| Google Maps / Places autocomplete | 📅 | Pro 2.0 |
| Mailchimp groups/tags | 📅 | Pro 2.0 (Mailchimp ✅ shipped) |
| ActiveCampaign deals | 📅 | Pro 2.0 (CRM contacts ✅ shipped) |
| Conversational mode | 📅 | Pro 2.0 |
| Inline embed (popup/slide-in/float) | 📅 | Pro 2.0 |
| Form templates library | ✅ shipped (Free/Lite) | Full categorized library + one-click import already in Lite; only **premium packs** remain 📅 Pro 2.0 |
| Form migrator / import from other plugins | 🆕 → 🔵 next up (Free/Lite) | **Lite** — Settings → Tools → Migrator; Phase 1 = Contact Form 7, pluggable for WPForms/Gravity/etc. later. Structure + basic settings only; entries later. Plan: `MIGRATOR-PLAN.md` |
| RTL support | ⏸ Parked | polish only |
| Custom thank-you page | 🚢 Awaiting release | Pro 1.1.1 (unreleased) — merge tags in the confirmation; basic redirect + custom message ✅ already free in Lite |
| Heatmap / drop-off | ⏸ (heatmap parked) | drop-off = abandonment 📅 Pro 1.2 |
| A/B testing | 📅 | Pro 2.0 |
| UTM / source tracking | 📅 | Pro 1.2 (marketing tracking) |
| Google Analytics / Meta Pixel events | 📅 | Pro 1.2 (marketing tracking) |
| Custom post type creation | 📅 | Pro 2.0 (advanced actions) |
| User registration | 📅 | Pro 2.0 (advanced actions) |
| Post submission (front-end) | 📅 | Pro 2.0 (advanced actions) |
| Role-based form access | 📅 | Pro 2.0 (advanced actions) |
| REST API endpoints | 📅 | Pro 2.0 (developer tools) |
| CLI commands | 📅 | Pro 2.0 (developer tools) |

**Score (re-counted 2026-07-22):** of ~59 items, **~19 are released** (was ~9 when this table was written — Pro 1.0.0 and 1.1.0 landed in between), **5 more are built and awaiting release** (the Pro 1.1.1 trio — custom email editor, entry approval, custom thank-you — plus the Pro 1.1.2 pair: conditional email routing + PDF attachment), **~5 are parked/deferred**, and the rest remain planned. The 📅 rows left are genuinely not built: email confirmation, abandonment tracking, marketing/UTM tracking, scheduled export, conditional logic on pages, custom entry statuses, coupons/tax/multi-currency, and the Pro 2.0 + flagship set.

---

## Appendix — engineering notes (for developers, not PMs)

_Architecture rule:_ every Pro feature ships as a **Pro module that hooks Lite's seams** — Pro never lives inside Lite. Where a needed hook doesn't exist yet, add the Lite seam first.

| Feature | Key hooks / modules |
|---|---|
| Form Migrator (Lite, free) | Pluggable source interface + controller on the existing `render_tools_tab()` router (add `'migrator'` to the sub-tab allowlist + nav + dispatch); each source reads its native structure and returns plain `rows`/`settings` → `ShapeForms_Lite_Ajax_Save::prepare_rows()` + `::normalize_form_settings()` → `$wpdb->insert`/`update`. Extensible via a `shapeforms_migration_sources` filter; `admin_init` form-POST (house style, not AJAX). Never hand-write `fields_json`. |
| AI Form Builder (flagship) | AI provider API (e.g. OpenAI) → map response to ShapeForms form JSON; API-key setting + output guardrails/validation |
| Save & Resume | entries store + `shapeforms_gate_submission`, `shapeforms_form_output` |
| Entry approval | extends the entry status system; gate side-effects on `shapeforms_entry_created` |
| Entry editing | reuse builder renderer against the stored entry schema |
| Excel/PDF export | entries export; PDF lib shared with Pro 1.2 |
| Entry limit / cooldown | `shapeforms_gate_submission` |
| Conditional email / PDF email | **needs a new Lite email-builder seam first** (custom subject/body + merge tags) |
| Thank-you tokens | reuse the existing auto-populate/merge-tag system |
| Abandonment / UTM / A/B | extend the analytics module |
| Conversational mode / embed modes | new front-end render layer |
| Developer tools | new REST + WP-CLI layer |

_Version note:_ Lite **1.2.0** is the jQuery→React rewrite. Land new free items on the React line once it ships, or on a 1.1.x point release — do not back-port React-only UI to the jQuery line.
