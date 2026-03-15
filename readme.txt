=== ExtractIA for WordPress ===
Contributors: extractia
Tags: document processing, ocr, ai, extraction, forms
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WordPress site to ExtractIA — AI-powered document and image extraction — via shortcodes, Gutenberg blocks, and a REST proxy that keeps your API key server-side.

== Description ==

**ExtractIA for WordPress** lets you embed document extraction workflows directly into your WordPress pages and posts.

= Features =
* **Upload widget** — drag-drop, file picker, or live camera capture
* **Multi-page documents** — combine up to 20 images into a single extraction
* **Template selector** — lets users pick the right extraction template
* **AI summary** — one-click GPT-powered summary of extracted data
* **Export** — copy JSON or download CSV
* **Workflow modes** — show results inline, redirect to a URL, or fire a webhook
* **Usage bar** — live quota display so users know their plan limits
* **OCR Tools** — embed any OCR tool (YES/NO, free text, number extraction…)
* **Gutenberg blocks** — all features available as native blocks
* **Secure** — API key stays on the server; browser uses WP nonces

= Shortcodes =

**[extractia_upload]** — Full upload + extraction widget.
Attributes:
* `template` — Pre-set template ID (skips selector)
* `hide_selector` — `true` to hide template dropdown
* `multipage` — `false` to limit to single image
* `show_summary` — `false` to hide AI summary button
* `class` — Extra CSS class on wrapper
* `title` — Widget heading
* `button_text` — Custom process button label

**[extractia_docs]** — Table of recent processed documents.
Attributes:
* `template` — Filter by template ID
* `limit` — Number of rows (default 10)
* `fields` — Comma-separated fields to show

**[extractia_tool]** — Single OCR tool widget.
Attributes:
* `id` — Tool ID (required)
* `title` — Override tool title
* `class` — Extra CSS class

**[extractia_usage]** — Inline quota + AI credits display.
No attributes.

= Gutenberg Blocks =
* **ExtractIA Upload** — Upload widget with InspectorControls
* **ExtractIA Document List** — Recent documents table
* **ExtractIA OCR Tool** — OCR tool embed
* **ExtractIA Usage Meter** — Quota display

== Installation ==

1. Upload the `extractia-wp` folder to `/wp-content/plugins/`.
2. Activate the plugin through **Plugins → Installed Plugins**.
3. Go to **ExtractIA → Settings** and enter your API key.
4. Click **Test Connection** to confirm the key is valid.
5. Place a shortcode on any page or use the Gutenberg blocks.

== Frequently Asked Questions ==

= Is my API key exposed to the browser? =
No. The plugin uses a PHP REST proxy — the browser calls `/wp-json/extractia/v1/` endpoints using WP nonces, and PHP adds the Bearer token before forwarding to ExtractIA.

= What happens when my quota is exceeded? =
The widget displays a user-friendly message (`quotaExceeded`) with a suggestion to upgrade. The error is not a PHP crash.

= Can I trigger a webhook after each extraction? =
Yes. Set **Workflow Mode** to `webhook` and enter your Webhook URL in Settings. The POST is non-blocking so it won't slow page response.

= How do I filter which result fields are shown? =
In Settings, enter a comma-separated list of field keys under **Result Fields**. The widget will only render those fields in the results table.

= Does this work with multisite? =
Yes. Settings are stored per-site. The uninstaller cleans up all sites.

= What file types are supported? =
JPEG, PNG, WebP, and PDF (single-page PDF for the upload widget; multi-image sequences for multi-page mode).

== Screenshots ==

1. Upload widget with drag-drop zone and camera capture
2. Extraction results with AI summary
3. Admin dashboard with usage meter
4. Plugin settings page
5. Gutenberg block editor experience

== Changelog ==

= 1.0.0 =
* Initial release.
* Upload widget: drag-drop, camera, multipage, AI summary, CSV export.
* Shortcodes: `[extractia_upload]`, `[extractia_docs]`, `[extractia_tool]`, `[extractia_usage]`.
* Gutenberg blocks: upload, document-list, ocr-tool, usage-meter.
* Admin: dashboard, OCR tools runner, subuser management.
* Three workflow modes: inline, redirect, webhook.
* PHP REST proxy to protect API key.

== Upgrade Notice ==

= 1.0.0 =
First release — no upgrade steps required.
