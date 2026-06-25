=== Seonix SEO ===
Contributors: seonix
Tags: seo, ai, content, automation, technical-seo
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.5.38
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Grow your SEO with real-time site audits, AI-written articles, and one-click technical fixes. An autonomous AI agent publishes on autopilot.

== Description ==

**Seonix SEO is the AI agent for organic and AI-search growth.** Connect your WordPress site and Seonix audits your technical SEO in real time, writes SEO-optimized articles, fixes site issues in one click, and publishes everything on autopilot — for Google and AI search engines like ChatGPT, Gemini, and Perplexity.

Built for site owners who want growth without learning SEO or hiring an agency.

**What Seonix does for your site**

* **Real-time technical SEO audits** — broken links, duplicate content, missing meta descriptions, image alt issues, schema gaps, sitemap problems, and dozens more checks.
* **One-click SEO fixes** — apply AI-suggested fixes for the most common technical issues directly from the Seonix dashboard. Rollback any change if you don't like the result.
* **AI-written articles, SEO-tuned end-to-end** — Seonix learns your site, audience, voice, and topics, then generates articles with optimized titles, meta descriptions, internal links, headings, and schema markup.
* **Autonomous publishing on a schedule** — pick a cadence (daily, every 3 days, weekly), and the AI agent plans, generates, and publishes for you. Pause anytime.
* **AI-search visibility** — content is structured for both Google and the new generation of AI search engines (ChatGPT, Gemini, Perplexity), with built-in `llms.txt` support.
* **Works with the SEO plugin you already use** — Seonix writes the standard meta fields every major WordPress SEO plugin reads, so your existing setup keeps working.
* **WooCommerce-ready** — product pages flow into the AI context for relevant internal linking.

**How the WordPress plugin fits in**

The Seonix WordPress plugin is the bridge between your site and the Seonix service. The plugin handles the WordPress side — receiving published articles, syncing your site structure for internal linking, and applying SEO fixes — while the AI heavy-lifting runs on the Seonix platform.

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

The plugin writes the standard SEO meta keys that every major WordPress SEO plugin reads (meta title, meta description, focus keyword). Whichever SEO plugin you have active will pick up the values without extra setup.

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

1. Your site health score inside WordPress, with SEO, technical, and AI search breakdowns synced from Seonix.
2. Every issue becomes a task with its category, the pages it affects, and its priority.

== Changelog ==

= 2.5.22 =
* Fixed the top bar / nav alignment: the brand and tabs are now centered with the page content (a class name collided with the hero pillar style and pushed them to the left edge).

= 2.5.21 =
* Admin screens now paint the full warm app-shell background, so the white top bar and cards read with proper depth (matches the Seonix Optimizer look).

= 2.5.20 =
* Per-page audit now appears in the block-editor document sidebar (like Yoast), not just at the bottom. Pages added or changed after the last scan are clearly marked "Not scanned yet" instead of showing a misleading "all clear".

= 2.5.18 =
* Admin shell now matches the Seonix app: a flush full-width white top bar (brand + version + connection status) and a Site Health / Settings nav-tab row with the active tab underlined in brand purple. Reconnect moved into Settings. No behaviour change.

= 2.5.16 =
* **Per-page audit in the editor.** A new "Seonix — Page audit" box on the post/page editor shows that page's issues from the last Seonix scan — a traffic light, the SEO / Technical / AI-Search breakdown, and each issue's recommendation — so you can see what to fix without leaving the editor. Read-only (the analysis runs on the Seonix platform); it links straight to the full issue list.
* **IndexNow auto-submit.** Publishing or updating a public post or page now automatically pings IndexNow, so Bing and Yandex re-crawl the changed URL within minutes instead of waiting for a scheduled crawl. The verification key is generated and installed automatically on the first submission — no setup step needed. Drafts, private/non-public content, and pages marked noindex (Yoast, Rank Math, SEOPress) are skipped, and the same URL is not re-submitted more than once per 10 minutes. On by default. Note: Google does not participate in IndexNow.

= 2.5.15 =
* Redesigned the admin UI to match the Seonix design system: a dark Site Health hero with a gradient score ring and per-category pillars, KPI cards (Open issues / Resolved / Came back), the brand purple palette, and a refreshed Issues list and detail dialog. No behaviour change — the connect flow, task sync, filters, and settings work exactly as before; existing connected sites need no reconfiguration.

= 2.5.14 =
* Fixed the Seonix icon in the WordPress admin menu rendering as a solid white square. The menu now uses the real Seonix favicon, embedded in a form that WordPress core's icon repainting (svg-painter.js) cannot flatten, so the brand mark shows correctly in every menu state.

= 2.5.0 =
* **One-click connect.** A new top-level **Seonix** admin menu links your WordPress site to Seonix in a single click — it hands off to https://app.seonix.ai/connect, you pick a project, and the connection completes automatically. No API key to copy. The manual key flow still works from Seonix → Settings. The browser handoff carries a single one-time security code in the URL fragment so it never reaches server logs.
* **Site Health inside WordPress.** The Seonix Dashboard shows your SEO audit as a task list — overall score, open/solved counts, per-category gauges (SEO / Technical / AI Search), and each issue's recommendation and affected URL. Tasks are stored locally and rendered from there, so viewing the Dashboard never calls the Seonix API; a "Refresh tasks" button (and a soft once-a-day auto-refresh) pulls the latest on demand.
* **Structured data (JSON-LD) on published articles.** Seonix adds schema.org markup (Article, breadcrumbs, and FAQ/How-To when present) in the page <head> so search engines and AI answer engines understand your content. Stays out of the way when Yoast, Rank Math, or All in One SEO is active to avoid duplicate markup. Toggle under Seonix → Settings → Structured Data.
* **Redesigned admin UI.** Full-width Problems and Settings tabs matching the Seonix web app, with a Site Health panel (overall score ring plus per-category bars) and a clearer, filterable task list.
* New REST routes `POST /connect/exchange` and `POST /tasks` under both the `seonix/v1` and legacy `content-engine/v1` namespaces. All output is escaped, all input is sanitized, and every state-changing admin action stays capability- and nonce-checked.
* Security hardening and reliability fixes. Uninstall cleans up the new options and the local tasks table. Existing connected sites keep working unchanged — no reconfiguration needed.

= 2.4.2 =
* Yoast SEO integration tightened: removed the last direct reads from Yoast-owned option arrays. Term meta descriptions (`Seonix_Fix_Term_Meta_Description::engine_read`) and the Yoast title-template helper (`Seonix_REST_API::get_yoast_title_template`) now go strictly through Yoast's public class APIs (`WPSEO_Taxonomy_Meta::get_term_meta`, `WPSEO_Options::get`). If those classes are unreachable, we fail cleanly (empty string / null / `412 Precondition Failed`) instead of falling back to `get_option('wpseo_*')` against the underlying option array.
* No new code paths and no migrations — the change only narrows the previously documented fallback.

= 2.4.1 =
* WordPress.org review compliance pass. No behaviour change; existing connected sites continue to work without reconfiguration.
* Removed the deprecated `libxml_disable_entity_loader()` calls from the HTML-to-blocks helper. The remaining `LIBXML_NONET` flag plus libxml2 2.9+ defaults provide the same XXE protection on the WP-supported PHP range (7.4+).
* `llms.txt` and `llms-full.txt` are now served entirely from PHP via rewrite rules — the plugin no longer writes any static files to the WordPress root. ETag/Last-Modified/304 caching keeps the responses cheap, and the dynamic body is always in sync with current published content. Output is escaped on emit.
* The IndexNow key file moved from the WordPress root to `wp-content/uploads/seonix/{key}.txt` and is written via the WordPress Filesystem API (`WP_Filesystem`). The `file_url` returned by the setup endpoint points at the new location and is accepted by IndexNow's `keyLocation` parameter.
* Yoast SEO integration now goes through Yoast's public API only — `WPSEO_Options::get` / `WPSEO_Options::set` for the pagination `noindex-subpages-wpseo` flag, and `WPSEO_Taxonomy_Meta::set_value` for taxonomy term descriptions. No direct `update_option( 'wpseo_*', ... )` calls remain anywhere in the plugin; both fix methods refuse to run unless Yoast SEO is active.
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
* New `pagination_noindex` fix method. Flips the SEO plugin's site-wide `noindex-subpages-wpseo` option to true (so paginated archive subpages render `<meta name="robots" content="noindex, follow">`) and force-rebuilds term indexables by nulling `is_robots_noindex` on term rows in the indexables table. Without this, the live `/category/foo/page/2/` HTML keeps rendering `index, follow` until the SEO plugin's cron rebuild catches up. All other `wpseo_titles` keys are preserved unchanged.

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
