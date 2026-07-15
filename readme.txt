=== Seonix SEO ===
Contributors: seonix
Tags: seo, ai, content, automation, technical-seo
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI search visibility out of the box — llms.txt and IndexNow, no account needed. Connect Seonix for site audits, AI articles, and SEO fixes.

== Description ==

**Seonix SEO gives your site AI-search visibility from the moment you activate it — and becomes a full growth agent when you connect a Seonix account.** Two features work immediately, free, with no account. Connect a Seonix project and the plugin brings your site audit into WordPress, receives AI-written articles, applies one-click technical fixes, and publishes on autopilot — for Google and AI search engines like ChatGPT, Gemini, and Perplexity.

**Free — works right after activation, no account needed**

* **llms.txt for AI search** — your site serves `/llms.txt` and `/llms-full.txt`, a machine-readable index of your published content that AI assistants use to discover and cite your pages. Generated live, always in sync with your content.
* **IndexNow auto-submit** — publishing or updating a post pings IndexNow, so participating search engines (Bing, Yandex, Seznam, Naver) re-crawl the changed URL within minutes. The verification key is provisioned automatically; toggle it any time from the plugin settings.

**Free with a Seonix account**

* **Site Health inside WordPress** — your site's SEO audit as a task list: overall score, SEO / technical / AI-search breakdowns, and every issue explained (what it means, why it matters, how to fix it). Checks cover broken links, duplicate content, missing meta descriptions, image alt issues, schema gaps, sitemap problems, and dozens more.
* **Page audit in the editor** — the current page's issues from the last scan, in the block editor sidebar and the classic editor.
* **Structured data (JSON-LD)** on articles published through Seonix — and it stays silent when another SEO plugin already outputs schema.

**What requires a paid Seonix plan**

* **One-click SEO fixes** — apply AI-suggested fixes for the most common technical issues directly from WordPress or the Seonix dashboard. Rollback any change if you don't like the result.
* **AI-written articles, SEO-tuned end-to-end** — Seonix learns your site, audience, voice, and topics, then generates articles with optimized titles, meta descriptions, internal links, headings, and schema markup.
* **Autonomous publishing on a schedule** — pick a cadence (daily, every 3 days, weekly), and the AI agent plans, generates, and publishes for you. Pause anytime.

**Plays well with your stack**

* **Works alongside your existing SEO plugin** — Seonix writes SEO titles and descriptions into your SEO plugin’s own storage (and syncs your edits back), so your current setup keeps working. No SEO plugin? Seonix renders the meta tags itself.
* **WooCommerce-ready** — product pages flow into the AI context for relevant internal linking.

**How the WordPress plugin fits in**

The Seonix WordPress plugin is the bridge between your site and the Seonix service. The plugin handles the WordPress side — serving llms.txt, pinging IndexNow, receiving published articles, syncing your site structure for internal linking, and applying SEO fixes — while the AI heavy-lifting runs on the Seonix platform.

**How it works:**

1. Install and activate this plugin.
2. Open the **Seonix** menu in your WordPress admin and click "Connect to Seonix" — pick your project in the Seonix app and the connection completes in one click.
3. Seonix analyzes your site and starts publishing, syncing, and applying fixes via the REST API.

Prefer to connect manually? Copy the auto-generated API key from `Seonix → Settings` and paste it into your Seonix project's WordPress channel. No WordPress passwords either way.

== External Service ==

This plugin connects your WordPress site to the **Seonix service** (https://seonix.ai), a third-party SaaS platform that generates and publishes SEO-optimized articles to your site. Using Seonix requires a Seonix account.

**What the service does**

Seonix uses AI to generate articles based on your site's topic, optimize them for SEO (titles, meta descriptions, internal links, schema), and publish them back to WordPress through this plugin's REST API. It also consumes a lightweight snapshot of your existing pages, posts, and WooCommerce products so generated articles can include relevant internal links.

**How you connect**

There are two ways to link your site, and BOTH require an explicit action by you:

* **One-click connect (recommended):** from the Seonix Dashboard inside wp-admin, click "Connect to Seonix". Your browser opens `https://app.seonix.ai/connect`, where you sign in (or sign up) and pick the project to link. Seonix then calls back to your site's REST API to finish the handshake. Your site URL is passed in the link; a single one-time security code is passed in the URL fragment so it stays out of server logs. No WordPress password leaves your site.
* **Manual connect:** copy the API key from `Seonix > Settings` and paste it into the Seonix dashboard. Seonix calls back to your REST API using the key.

**When the plugin contacts Seonix**

The plugin does NOT contact any external server until you connect it to a Seonix project:

1. Install and activate the plugin — no external request is made.
2. Connect via either method above. Until a connection succeeds, no outbound calls leave your site (clicking "Connect to Seonix" only opens the Seonix site in your browser — that browser navigation is initiated by you, not a background request from the plugin).
3. Seonix calls back to your WordPress REST API to complete the handshake. After that, the Seonix engine URL is stored on your site.
4. From that point on, the plugin can push a one-time site snapshot and per-post events to that engine URL, and can pull your SEO task list back from it.

Until step 3 succeeds, no outbound calls leave your site.

**What data is sent**

* **Site snapshot** (manual "Sync Now" button or weekly cron): for each public page, post, and WooCommerce product — `wp_id`, `content_type`, `title`, `slug`, `url`, `status`, `updated_at`. Post content body is NOT sent.
* **Per-post events** on save/delete: same shape as above plus an action flag (`created` / `updated` / `deleted`).
* **Connect handshake**: when Seonix completes the connection it reads your site name and site URL and stores the plugin's API key so it can call your site later.
* Outbound calls go only to the Seonix endpoint authorized during connection.

**What data is received**

* **SEO tasks**: after Seonix scans your site (on connect and weekly), it sends your site's audit results — a list of SEO/technical/AI-search issues with a title, description, recommendation, severity, and the affected URL — which the plugin stores locally and shows on the Seonix Dashboard. The plugin can also pull this same list on demand via the "Refresh tasks" button. No personal data is received; the tasks describe your own site's content and configuration.

**Terms and Privacy**

By installing the plugin and connecting it to a Seonix project, you accept the Seonix Terms of Service and Privacy Policy:

* Service: https://seonix.ai
* Terms of Service: https://seonix.ai/terms-of-use
* Privacy Policy: https://seonix.ai/privacy-policy

== Installation ==

1. Upload the `seonix` folder to `/wp-content/plugins/` (or install the .zip through Plugins > Add New > Upload Plugin)
2. Activate the plugin through the Plugins menu
3. Open the **Seonix** menu and click "Connect to Seonix" to link your site in one click
4. Prefer manual setup? On `Seonix > Settings`, copy the API key and paste it into the Seonix dashboard (`Channels > WordPress`)

== Frequently Asked Questions ==

= How do I connect my site? =

The easiest way is the **Seonix** menu in your WordPress admin: click "Connect to Seonix", choose your project in the Seonix app, and the connection completes automatically — no key to copy. You can also connect manually by pasting the API key (see below) into the Seonix dashboard.

= How does authentication work? =

On activation, the plugin generates a unique API key (`sx_` followed by 64 hex characters). This key authenticates every request between Seonix and your site. No WordPress passwords or application passwords are involved. The same key authenticates outbound calls from the plugin back to the Seonix backend. One-click connect exchanges this key for you behind the scenes; with manual connect you paste it once into the Seonix dashboard.

= What SEO plugins are supported? =

All major WordPress SEO plugins. Seonix detects which one is active and writes the SEO title, meta description, and focus keyword into that plugin’s own native storage — no extra setup, and you keep editing the values where you always did. Edits made in your SEO plugin sync back to the Seonix dashboard. If you run no SEO plugin at all, Seonix renders the meta tags itself and hands everything over automatically the day you install one.

= Can I change the API key? =

Yes. Go to `Seonix > Settings` and click "Regenerate Key". You will need to update the key in your Seonix project afterward (or simply use "Connect to Seonix" again).

= What data is sent to Seonix? =

See the "External Service" section above. Briefly: only basic metadata (title, URL, slug, status, modification time) about your pages, posts, and WooCommerce products. Post content body is never sent. No data is sent until you connect the plugin to a Seonix project.

= Can I connect to multiple Seonix projects? =

Currently, each WordPress site connects to one Seonix project at a time.

= What happens if I deactivate the plugin? =

The API key and settings are preserved. Reactivate the plugin to resume publishing.

= How do I disconnect my site from Seonix? =

Go to `Seonix > Settings` and click "Regenerate Key". The previous key becomes invalid, so the Seonix backend can no longer call your site or receive sync data. To remove all stored options and the local task data entirely, delete the plugin from the Plugins screen.

== Screenshots ==

1. Your site health score inside WordPress, with SEO, technical, and AI-search breakdowns synced from Seonix.
2. Every issue becomes a prioritized task showing its category and the pages it affects.
3. Built-in llms.txt and IndexNow — AI-search discovery and instant search-engine pings that work without a Seonix account.

== Changelog ==

= 2.8.1 =
* Fix: the editor panel's live SEO and readability scores failed with a server error in 2.8.0 — the scoring class shipped in the package but was never loaded. Scores work again; nothing else was affected.

= 2.8.0 =
* New: live SEO and readability scores while you write. The Seonix panel in the editor sidebar now scores the text you have in front of you — not the last saved version — and updates as you type, so you can fix things before publishing instead of after.
* New: each score opens into the checks behind it, so the number always comes with a reason: what passed, what didn't, and what to change.
* Improved: the panel reads your focus keyphrase and meta description as they are right now, straight from the editor, so the score reflects work you can see on screen even before you save.
* Note: scoring is done by the Seonix engine, so the panel needs the site connected to a Seonix project. Nothing is sent anywhere until you type, and WordPress — not your browser — makes the call.

= 2.7.0 =
* New: built-in redirect manager. Redirects created by Seonix's one-click SEO fixes are now served by this plugin directly — no separate redirect plugin required (fixes applied by older versions stay reversible).
* New: Redirects screen in wp-admin (Seonix → Redirects) — every rule with its hit count, plus add your own 301/302 redirects, disable or delete them.
* New: redirects managed in the Seonix dashboard sync into the plugin over its REST API. The plugin is the source of truth for what's live: edit or delete a rule here and the dashboard adopts that on the next sync instead of overwriting you.
* Improved: redirect matching ignores trailing slashes and letter case, carries the original query string over to the target, and never loops — self-redirects and two-rule cycles are detected and skipped, and chained rules are flattened to their final target in a single hop.
* New: one-click fix for Chrome's new "Agentic Browsing" audit. Clickable cards that are only a link overlay, and dropdowns whose only cue is their first option, reach AI agents and screen readers with no readable name — Lighthouse fails the page for it. Seonix names them from the page itself: a card's heading where the link can be matched to it unambiguously, an image's alt text, or the link's own destination. Nothing visible on the page changes, and switching the fix off removes every added name.
* New: your contact and search forms can describe themselves to AI browsing agents using the emerging WebMCP markup, so an assistant can tell what a form does and what each field expects. Markup only — no JavaScript is added to your pages, and browsers without WebMCP support simply ignore it.

= 2.6.0 =
* New: SEO titles and meta descriptions written by Seonix now land inside whichever SEO plugin your site runs, using each plugin's own native storage — so you keep editing them in the tools you already use.
* New: no SEO plugin? Seonix now renders the SEO title, meta description, and social-share tags itself (Auto mode — it steps aside automatically the moment you activate an SEO plugin, and copies everything you had into it).
* New: edits you make to SEO titles/descriptions in your SEO plugin sync back to the Seonix dashboard, with a note showing the change came from WordPress.
* New: structured data (JSON-LD) received from Seonix can include FAQ and HowTo blocks; your SEO plugin's sitemap cache is refreshed right after each publish.
* Improved: one-click SEO fixes for titles and descriptions now work on sites without any SEO plugin installed.

= 2.5.42 =
* Improved: undoing an automatic fix for a broken link or a missing image alt now reverses exactly that change wherever it was applied across your site — and leaves any later edits to those pages untouched, instead of restoring an old copy of the whole page.
* Fixed: a broken-link fix could be reported as failed even when it actually succeeded on the page; this no longer happens.

= 2.5.41 =
* Improved llms.txt (the AI-discovery file): pages are now ordered by importance — your home page and main service pages first, instead of buried among sub-pages — so AI assistants can tell which pages matter most.
* Fixed: llms.txt no longer shows a stray "&amp;" in place of "&", and invisible characters are stripped from headings.
* Fixed: /llms.txt now serves directly instead of redirecting to /llms.txt/.
* Structured data can now include a LocalBusiness block (business name, phone, address, service area) alongside your existing SEO plugin, so Google and AI answer engines can attribute your business — shown when your Seonix project has those details.

= 2.5.40 =
* Fixed: the "Upgrade this project" button no longer shows on paid plans (it was staying visible due to a CSS cascade issue).
* Fixed: the brand buttons no longer render with invisible text on hover.

= 2.5.39 =
* The Seonix mark in the block-editor sidebar now reads green when a track has no open issues, instead of greying out on pages that have not been scanned yet.

= 2.5.38 =
* New: one-click SEO fixes from the plugin. Fixable issues — page titles, meta descriptions, image alt text, mixed content and paginated-archive noindex among them — now show a "Fix it for me" button that applies the change through the connected Seonix service. Available on paid Seonix plans.
* Per-page audit detail in the block editor sidebar: each issue now explains what it means, why it matters, and how to fix it, mirroring the Seonix dashboard.
* Supplemental FAQ / Q&A structured data is now emitted alongside your existing SEO plugin instead of conflicting with it.
* Site Health issue counts now match the Seonix dashboard exactly.
* Bundled the admin-interface fonts with the plugin (no external CDN requests).
* Housekeeping: corrected the readme stable tag and added database-escaping annotations for WordPress.org compliance.

= 2.5.23 – 2.5.37 =
* Internal iterations: admin UI and editor-panel refinements, sync reliability fixes, and WordPress.org compliance housekeeping. See the GitHub releases for per-version detail.

= 2.5.22 =
* Fixed the top bar / nav alignment: the brand and tabs are now centered with the page content (a class name collided with the hero pillar style and pushed them to the left edge).

= 2.5.21 =
* Admin screens now paint the full warm app-shell background, so the white top bar and cards read with proper depth (matches the Seonix Optimizer look).

= 2.5.20 =
* Per-page audit now appears in the block-editor document sidebar, not just at the bottom. Pages added or changed after the last scan are clearly marked "Not scanned yet" instead of showing a misleading "all clear".

= 2.5.18 =
* Admin shell now matches the Seonix app: a flush full-width white top bar (brand + version + connection status) and a Site Health / Settings nav-tab row with the active tab underlined in brand purple. Reconnect moved into Settings. No behaviour change.

= 2.5.16 =
* **Per-page audit in the editor.** A new "Seonix — Page audit" box on the post/page editor shows that page's issues from the last Seonix scan — a traffic light, the SEO / Technical / AI-Search breakdown, and each issue's recommendation — so you can see what to fix without leaving the editor. Read-only (the analysis runs on the Seonix platform); it links straight to the full issue list.
* **IndexNow auto-submit.** Publishing or updating a public post or page now automatically pings IndexNow, so Bing and Yandex re-crawl the changed URL within minutes instead of waiting for a scheduled crawl. The verification key is generated and installed automatically on the first submission — no setup step needed. Drafts, private/non-public content, and pages marked noindex by your SEO plugin are skipped, and the same URL is not re-submitted more than once per 10 minutes. On by default. Note: Google does not participate in IndexNow.

= 2.5.15 =
* Redesigned the admin UI to match the Seonix design system: a dark Site Health hero with a gradient score ring and per-category pillars, KPI cards (Open issues / Resolved / Came back), the brand purple palette, and a refreshed Issues list and detail dialog. No behaviour change — the connect flow, task sync, filters, and settings work exactly as before; existing connected sites need no reconfiguration.

= 2.5.14 =
* Fixed the Seonix icon in the WordPress admin menu rendering as a solid white square. The menu now uses the real Seonix favicon, embedded in a form that WordPress core's icon repainting (svg-painter.js) cannot flatten, so the brand mark shows correctly in every menu state.

= 2.5.0 =
* **One-click connect.** A new top-level **Seonix** admin menu links your WordPress site to Seonix in a single click — it hands off to https://app.seonix.ai/connect, you pick a project, and the connection completes automatically. No API key to copy. The manual key flow still works from Seonix → Settings. The browser handoff carries a single one-time security code in the URL fragment so it never reaches server logs.
* **Site Health inside WordPress.** The Seonix Dashboard shows your SEO audit as a task list — overall score, open/solved counts, per-category gauges (SEO / Technical / AI Search), and each issue's recommendation and affected URL. Tasks are stored locally and rendered from there, so viewing the Dashboard never calls the Seonix API; a "Refresh tasks" button (and a soft once-a-day auto-refresh) pulls the latest on demand.
* **Structured data (JSON-LD) on published articles.** Seonix adds schema.org markup (Article, breadcrumbs, and FAQ/How-To when present) in the page <head> so search engines and AI answer engines understand your content. Stays out of the way when another SEO plugin is already outputting structured data, to avoid duplicate markup. Toggle under Seonix → Settings → Structured Data.
* **Redesigned admin UI.** Full-width Problems and Settings tabs matching the Seonix web app, with a Site Health panel (overall score ring plus per-category bars) and a clearer, filterable task list.
* New REST routes `POST /connect/exchange` and `POST /tasks` under both the `seonix/v1` and legacy `content-engine/v1` namespaces. All output is escaped, all input is sanitized, and every state-changing admin action stays capability- and nonce-checked.
* Security hardening and reliability fixes. Uninstall cleans up the new options and the local tasks table. Existing connected sites keep working unchanged — no reconfiguration needed.

= 2.4.2 =
* SEO-plugin integration tightened: term meta descriptions and the title-template helper now go strictly through the active SEO plugin's public class APIs. If those classes are unreachable, we fail cleanly (empty string / null / `412 Precondition Failed`) instead of reading its stored options directly.
* No new code paths and no migrations — the change only narrows the previously documented fallback.

= 2.4.1 =
* WordPress.org review compliance pass. No behaviour change; existing connected sites continue to work without reconfiguration.
* Removed the deprecated `libxml_disable_entity_loader()` calls from the HTML-to-blocks helper. The remaining `LIBXML_NONET` flag plus libxml2 2.9+ defaults provide the same XXE protection on the WP-supported PHP range (7.4+).
* `llms.txt` and `llms-full.txt` are now served entirely from PHP via rewrite rules — the plugin no longer writes any static files to the WordPress root. ETag/Last-Modified/304 caching keeps the responses cheap, and the dynamic body is always in sync with current published content. Output is escaped on emit.
* The IndexNow key file moved from the WordPress root to `wp-content/uploads/seonix/{key}.txt` and is written via the WordPress Filesystem API (`WP_Filesystem`). The `file_url` returned by the setup endpoint points at the new location and is accepted by IndexNow's `keyLocation` parameter.
* SEO-plugin integration now goes through public APIs only for the pagination noindex-subpages flag and taxonomy term descriptions. No direct option writes remain anywhere in the plugin; both fix methods refuse to run unless a supported SEO plugin is active.
* Renamed `assets/icon-256x256.png` → `assets/seonix-logo.png` and `assets/icon-64x64.png` → `assets/seonix-logo-small.png` so they don't clash with WordPress.org plugin-directory catalog asset names. The directory banners/icons live in SVN `/assets/`, not in the plugin zip.

= 2.4.0 =
* Rebrand release. Plugin name updated to "Seonix SEO – Real-time AI Agent for Technical SEO, AI Content & Autonomous Growth" so the WordPress.org listing reflects what the plugin and connected Seonix service actually do: real-time technical SEO audits, AI-generated content, one-click fixes, and autonomous publishing for Google and AI search engines (ChatGPT, Gemini, Perplexity).
* readme.txt: rewritten short description and Description body with a full overview of Seonix capabilities (real-time technical SEO audits, AI content, autonomous publishing, AI-search visibility). Plugin tags updated for the SEO category in the WordPress.org directory.
* No code or behaviour change. The plugin REST API surface, auth flow, sync contract, and SEO Fix subsystem are identical to 2.3.2 — safe to update.

= 2.3.2 =
* Corrected the Terms of Service and Privacy Policy URLs in the External Service disclosure (`https://seonix.ai/terms-of-use` and `https://seonix.ai/privacy-policy`). The previous `/terms` and `/privacy` paths returned 404.

= 2.3.1 =
* Self-configure on verify: when the Seonix backend calls `GET /wp-json/seonix/v1/verify`, the plugin now persists `engine_url`, `project_id`, and `project_name` from the request's query string. Outbound sync (`/api/plugin/sync`, `/api/plugin/content-event`) and the Settings → Seonix UI always reflect the backend that completed the last successful verify — operators no longer need to edit options by hand when a site is moved between Seonix projects or between dev/prod backends. Older backends that don't pass the new params are still accepted (empty values are skipped).
* Internal: `Seonix_Sync::is_safe_url` is now `public static` so the REST controller reuses the same SSRF guard that protects outbound sync.
* Plugin Check compliance: the uninstall-time `DROP TABLE` now passes the table name through `$wpdb->prepare( '...%i', $table )` so PluginCheck's UnescapedDBParameter sniff is satisfied. Tested up to WordPress 7.0; minimum bumped to WordPress 6.2 (required for the `%i` SQL identifier placeholder).

= 2.3.0 =
* WordPress.org submission prep: complete plugin header with Plugin URI, Author URI, License URI, and Domain Path; readme.txt fully discloses the external service (Seonix SaaS), the exact data sent, and links to Terms of Service and Privacy Policy.
* i18n: empty `/languages/seonix.pot` scaffold shipped for translators; translations for WordPress.org-hosted plugins are auto-loaded by core since WP 4.6, so no explicit `load_plugin_textdomain()` call is needed.
* Hardened uninstall: legacy `@unlink()` replaced with WordPress's `wp_delete_file()`; `seonix_migrated_from_ce` option and `{$wpdb->prefix}seonix_seo_fix_history` table are now dropped on uninstall.
* Internal: enqueue handles renamed from legacy `ce-admin` to `seonix-admin` for consistency. No behaviour change.
* Tested up to WordPress 6.8 (bumped to 7.0 in 2.3.1).

= 2.2.6 =
* Security audit fixes (internal hardening; no API or behaviour change for callers).

= 2.2.5 =
* Fixed: `featured_image_alt` from Seonix now persists as the WordPress alt-text attribute on the imported featured-image attachment (`_wp_attachment_image_alt` post meta). Previously the field was silently dropped.
* Performance: trimmed REST responses across every endpoint to ship only fields the Seonix backend actually decodes. Largest wins: `GET /posts` list no longer does per-row `get_the_terms()` lookups or a global `wp_count_posts()`; `/llms-status` no longer walks `wp_count_posts()` x 2; `/cache/purge` returns 204 No Content; `/seo-fix/dry-run` skips `sprintf` of the now-unused diff string per fix method.

= 2.2.4 =
* New `pagination_noindex` fix method. Flips the SEO plugin's site-wide noindex-subpages option to true (so paginated archive subpages render `<meta name="robots" content="noindex, follow">`) and force-rebuilds the affected term records so the change takes effect immediately. Without this, the live `/category/foo/page/2/` HTML keeps rendering `index, follow` until the SEO plugin's cron rebuild catches up. All other stored title and meta settings are preserved unchanged.

= 2.2.3 =
* Fixed: `term_meta_description` now syncs to the indexables table so the live archive page actually renders the new description on installs that use indexables (v14+).

= 2.2.2 =
* New SEO fix method `term_meta_description` for taxonomy archive pages (category / tag / custom taxonomies). The plugin resolves the archive URL to a term and writes the description through the active SEO plugin's term-meta layer. Fixes the gap where `meta_description_missing` issues on `/category/...` and `/tag/...` URLs survived auto-fix runs because the legacy `meta_description` method only handled posts.
* Broken-link fix gained an optional `mode` parameter. Default `mode=rewrite` is unchanged. New `mode=remove_link` strips every `<a href="$old_url">TEXT</a>` (absolute and matching relative href) down to its inner TEXT — used as the fallback when the AI matcher can't find a confident redirect target. Deep-mode rewrites apply to the new mode too.
* Backwards-compatible with older Seonix backends: they simply won't dispatch to the new method/mode.

= 2.2.1 =
* Per-post snapshot (`GET /seonix/v1/posts` and `/seonix/v1/posts/{id}`) now includes the active SEO plugin's per-post-type title template (e.g. `%%title%% %%sep%% %%sitename%%`) and the site `blogname`. The Seonix backend uses these to size the AI title-suggester's character budget so a meta title plus the appended sitename suffix stays under the rendered `<title>` length limit. Returns `null` when no compatible SEO plugin is installed.

= 2.2.0 =
* Key takeaways callout block. The plugin now accepts `key_takeaways[]` and `key_takeaways_title` in the publish payload and renders them as a styled `<aside class="seonix-key-takeaways">` block above the article body. Bundled stylesheet (`assets/seonix-content.css`) is enqueued on singular post pages so the block looks consistent across themes.
* Per-tenant brand accent. The payload also accepts `accent_color` (canonical 7-character hex). Set as `--seonix-accent` on the `<aside>` so the callout matches the project palette out of the box. Themes can override `--seonix-accent` globally at `:root` if they prefer their own colour.
* Takeaways and brand accent are persisted in `_seonix_key_takeaways` / `_seonix_key_takeaways_title` / `_seonix_brand_accent` post meta for downstream consumers (themes, AMP, llms.txt).

= 2.1.0 =
* SEO Fix subsystem: REST routes under `/seonix/v1/seo-fix/*` (capabilities, dry-run, apply, rollback, history) backed by per-method classes (SSL mixed content, redirect, broken link, meta title, meta description, image alt).
* Cache purger so applied fixes invalidate page cache without manual clears.
* `llms.txt` full-content variant alongside the index, with ETag and Last-Modified for efficient AI-crawler revalidation.
* Hardened auth header detection: `X-Seonix-Key` preferred, `X-CE-Key` kept as legacy alias, `Authorization: Bearer` continues to work.
* PHPUnit unit tests for sync, REST controller, registry, history, cache purger, and individual fix methods.
* Internal cleanups across the sync class and admin page; no behaviour changes there.

= 2.0.0 =
* Complete rewrite with API key authentication (replaces handshake flow). New keys are `sx_<64 hex>`; legacy `ce_<64 hex>` keys remain accepted.
* Multi-file architecture for better maintainability.
* SEO meta set via `meta_input` during `wp_insert_post` so the active SEO plugin picks up the values immediately without an extra save.
* Added support for an additional SEO plugin alongside the two already covered.
* Multi-category support with automatic creation.
* Robust MIME type detection for featured images.
* Configurable post author setting.
* IndexNow setup and status endpoints.
* Improved admin settings page with card-based design.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 2.5.0 =
One-click connect, Site Health inside WordPress, and JSON-LD structured data on published articles. Existing connected sites keep working — no reconfiguration needed.

= 2.4.0 =
Rebrand release: new plugin name and a rewritten WordPress.org listing description. No code or behaviour change — safe to update.

= 2.3.2 =
Fixes the Terms of Service and Privacy Policy URLs in the readme (they previously returned 404). No code or behaviour change.

= 2.3.1 =
Plugin self-configures from the verify request — fixes stale `engine_url` after a site is moved between Seonix backends. No action needed on existing sites; the new behaviour kicks in on the next Verify from the Seonix dashboard.

= 2.3.0 =
WordPress.org compliance release: full disclosure of the Seonix external service, links to Terms of Service and Privacy Policy, proper i18n setup, and improved uninstall. No API changes.
