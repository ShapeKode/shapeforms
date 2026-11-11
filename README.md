# ShapeForms

> Lightweight drag‑and‑drop form builder for WordPress — with a shortcode, Gutenberg block, Elementor widget, AJAX submissions, entry management, and email‑marketing integrations.

![WordPress](https://img.shields.io/badge/WordPress-6.3%2B-21759b)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4)
![Version](https://img.shields.io/badge/version-1.1.8-0a9396)
![License](https://img.shields.io/badge/license-GPLv2%2B-green)

ShapeForms is the free tier of **ShapeForms**, a freemium form builder. Build any kind of form visually, embed it anywhere, and manage every submission inside the WordPress dashboard — with zero bloat. **ShapeForms Pro** (a separate plugin) extends Lite through hooks; Lite never depends on Pro.

- **Website:** https://shapekode.com/
- **Support:** https://shapekode.com/support/

---

## Table of contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation (for site owners)](#installation-for-site-owners)
- [Embedding a form](#embedding-a-form)
- [Development](#development)
  - [Prerequisites](#prerequisites)
  - [Setup](#setup)
  - [Building a distributable ZIP](#building-a-distributable-zip)
  - [npm scripts](#npm-scripts)
- [Project structure](#project-structure)
- [Architecture in brief](#architecture-in-brief)
- [Extending ShapeForms](#extending-shapeforms)
- [Contributing](#contributing)
- [License](#license)

---

## Features

- **Drag & drop builder** — compose forms visually, reorder fields, configure each instantly.
- **Many field types** — text, email, number, phone, URL, select/multi‑select, checkbox, radio, country, date & time, star rating, slider/range, input mask, file upload, structured Name & Address, section breaks, paragraphs, HTML, and terms.
- **AJAX submissions** — no full page reload.
- **Entries management** — every submission is stored in the database; view, filter, mark read/unread/spam, and delete.
- **Email notifications** — custom From/Reply‑To/subject/body, plus a test‑email tool.
- **Reports** — totals and per‑form stats via a native HTML5 canvas chart (no external library).
- **Export / Import** — forms, a single form with entries, or global settings as JSON.
- **Multi‑step forms** with progress indicators, and **conditional logic** (AND/OR rules).
- **Spam protection** — honeypot, reCAPTCHA v2, hCaptcha, Cloudflare Turnstile, and math captcha.
- **Email‑marketing integrations** — Mailchimp and Brevo.
- **Privacy (GDPR)** — registers with WordPress's Export / Erase Personal Data tools.
- **Accessibility** — keyboard‑ and screen‑reader‑operable fields (WCAG‑oriented).
- **Embeds** — shortcode, Gutenberg block (Block API v3), and Elementor widget.
- **Translation ready** and **multisite compatible**.

See [`readme.txt`](readme.txt) for the full feature list and the changelog.

---

## Requirements

| | Minimum |
|---|---|
| WordPress | 6.3 |
| PHP | 7.4 |
| Tested up to | WordPress 7.0 |

For development (building the ZIP) you additionally need **Node.js 20+** and **npm** — see below.

---

## Installation (for site owners)

1. Download the latest `shapeforms-lite-<version>.zip` (from a release, or build it yourself — see [Development](#development)).
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, choose the ZIP, and install.
3. Activate the plugin from the **Plugins** screen.
4. Open **ShapeForms** in the admin sidebar and create your first form.

> Installing from this repository directly (e.g. `git clone` into `wp-content/plugins/`) also works for development, but the production ZIP is the recommended way to ship.

---

## Embedding a form

| Method | How |
|---|---|
| **Shortcode** | `[shapeforms id="123"]` |
| **Gutenberg block** | Insert the **ShapeForms** block in any page or post |
| **Elementor widget** | Drag the **ShapeForms** widget into any Elementor layout |

---

## Development

The repository is the plugin itself. The only build step is packaging a clean,
distributable ZIP — there is **no compile/transpile step**; PHP, CSS, and JS are
hand‑maintained and run as‑is.

### Prerequisites

- **Node.js 20+** and **npm** (the build uses [`@wordpress/scripts`](https://www.npmjs.com/package/@wordpress/scripts)).
- A **PHP 7.4+** environment to run the plugin (any local WordPress stack — Local, wp-env, MAMP, Docker, etc.).
- `zip` / `unzip` available on your `PATH` (standard on macOS and Linux).

### Setup

```bash
# 1. Clone into your WordPress plugins directory (recommended for live testing)
git clone https://github.com/Shapekode/shapeforms.git
cd shapeforms

# 2. Install dev dependencies
npm install
```

`npm install` pulls in `@wordpress/scripts` (used only for packaging) into `node_modules/`. Nothing in `node_modules/` ships with the plugin.

### Building a distributable ZIP

```bash
npm run build
```

This produces the installable plugin archive at:

```
build/shapeforms-lite-<version>.zip
```

The build uses `wp-scripts plugin-zip` to bundle only the files listed in `package.json`'s `files` array, then strips development‑only files (`package.json`, `README.md`) from the archive. The result is a clean ZIP ready to upload to any WordPress site or submit to WordPress.org.

> The version in the ZIP name comes from `package.json`. Keep it in sync with the
> `Stable tag` in [`readme.txt`](readme.txt) and the `SHAPEFORMS_LITE_VERSION` constant in
> [`shapeforms.php`](shapeforms.php) when releasing.

To package only when the working tree is clean (a release safeguard):

```bash
npm run release
```

### npm scripts

| Script | What it does |
|---|---|
| `npm install` | Install dev dependencies (`@wordpress/scripts`). Run once after cloning. |
| `npm run build` | Package `build/shapeforms-lite-<version>.zip` (dev‑only files stripped). |
| `npm run release` | Verify the git working tree is clean, then run `build`. |

---

## Project structure

```
shapeforms/
├── shapeforms.php      # Main plugin file: constants, bootstrap, activation hooks
├── uninstall.php          # Opt-in data cleanup on uninstall
├── readme.txt             # WordPress.org readme (description + changelog) — source of truth for releases
├── wpml-config.xml        # WPML/Polylang translation config
├── package.json           # Build tooling (dev-only; stripped from the shipped ZIP)
│
├── includes/              # Core, shared services (loaded on every request)
│   ├── class-shapeforms-lite.php            # Service container + hook map (start here)
│   ├── class-shapeforms-lite-loader.php     # Action/filter registrar
│   ├── class-shapeforms-lite-activator.php  # DB schema (forms + entries tables)
│   ├── class-shapeforms-lite-email-handler.php
│   ├── class-shapeforms-lite-integrations.php   # Mailchimp / Brevo dispatch
│   ├── class-shapeforms-lite-privacy.php        # GDPR export/erase
│   └── appsero/                               # Vendored Appsero SDK (opt-in telemetry)
│
├── admin/                 # wp-admin side: builder, settings, entries, export/import
│   ├── class-shapeforms-lite-admin.php
│   ├── admin-builder.php
│   ├── ajax-save.php                          # Save a form (allowlist + sanitize)
│   ├── class-shapeforms-lite-export-import.php
│   └── class-shapeforms-lite-integrations-page.php
│
├── public/                # Front-end + embeds
│   ├── class-shapeforms-lite-form-handler.php   # Submission processing
│   ├── class-shapeforms-lite-shortcode.php      # Renders a form (reused by block/Elementor)
│   ├── class-shapeforms-lite-block.php          # Gutenberg block (server render)
│   └── class-shapeforms-lite-elementor-widget.php
│
├── blocks/form/           # Gutenberg block sources (block.json, editor assets)
├── assets/                # CSS / JS (builder.js, frontend.js, builder.css, frontend.css)
├── templates/             # PHP view templates (e.g. email body)
├── languages/             # shapeforms.pot and translations
└── uninstall/             # Uninstall helpers
```

**Data model:** two custom tables — `{prefix}shapeforms_forms` and `{prefix}shapeforms_entries` (no custom post type). A form's layout is stored as JSON in the shape `rows → columns → fields`: the builder writes it, the renderer reads it back.

---

## Architecture in brief

- **Bootstrap:** [`shapeforms.php`](shapeforms.php) defines constants and boots the plugin; [`includes/class-shapeforms-lite.php`](includes/class-shapeforms-lite.php) is the service container and the map of every service and hook — the best place to start reading.
- **Save flow:** the builder (`assets/js/builder.js`) posts to `admin/ajax-save.php`, which allowlists field types and sanitizes every value by type before storing the layout JSON.
- **Render flow:** `public/class-shapeforms-lite-shortcode.php` renders a form; the block and Elementor widget both reuse it so output stays identical across embeds.
- **Submit flow:** `public/class-shapeforms-lite-form-handler.php` validates and stores an entry (forms accept submissions only when published), fires notification/integration hooks, then returns the AJAX result.
- **Settings:** a single option array, `shapeforms_lite_settings`; the schema version gate is `shapeforms_lite_db_version`.

---

## Extending ShapeForms

ShapeForms exposes a stable set of actions and filters so add‑ons (and ShapeForms Pro) can extend it without modifying core. Common seams:

| Hook | Purpose |
|---|---|
| `shapeforms_allowed_field_types` (filter) | Register a new field type |
| `shapeforms_validate_field` (filter) | Add server‑side validation for a field |
| `shapeforms_field_library` (filter) | Add a field to the builder palette |
| `shapeforms_entry_created` (action) | React after an entry is saved |
| `shapeforms_gate_submission` (filter) | Reject a submission early (spam, etc.) |
| `shapeforms_integration_dispatched` (action) | Observe an integration call's result |
| `shapeforms_loaded` (action) | Bootstrap an add‑on once Lite is ready |

Shipped hooks are treated as a stable API — arguments are not renamed or reordered; behaviour is deprecated rather than broken.

---

## Contributing

- Branch from and open pull requests against **`development`**. `main` is release‑only.
- Follow the **WordPress Coding Standards** (tabs, Yoda conditions, braces); baseline **PHP 7.4**.
- **Sanitize on input, escape on output.** Use a nonce + `current_user_can( 'manage_options' )` on every privileged/AJAX action.
- No remote code or CDNs; document any external service in `readme.txt`.
- Run `php -l` on changed PHP files and wrap new strings in the `shapeforms` text domain before opening a PR.

---

## License

[GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html). © Shapekode.
