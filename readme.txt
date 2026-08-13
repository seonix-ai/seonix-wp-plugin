=== Seonix SEO – llms.txt, IndexNow & AI Search Visibility (GEO/AEO) ===
Contributors: seonix
Tags: seo, ai-search, llms-txt, indexnow, aeo
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.16.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

llms.txt + IndexNow, free, no account needed. AI search visibility (GEO/AEO) for ChatGPT, Perplexity and Gemini. Site audits, AI articles, SEO fixes.

== Description ==

**Seonix SEO serves llms.txt and pings IndexNow from the moment you activate it — free, no account, no configuration.** That covers the technical layer of generative engine optimization (GEO) and answer engine optimization (AEO): AI assistants like ChatGPT, Perplexity, and Gemini get a machine-readable index of your content to discover and cite, and IndexNow-participating search engines (Bing, Yandex, Seznam, Naver) learn about every publish or update within minutes.

Connect a Seonix account and the plugin becomes a full growth agent: it brings your site audit into WordPress, receives AI-written articles, applies one-click technical fixes, and publishes on autopilot — for Google and AI search alike.

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

= What is llms.txt and why does my site need it? =

llms.txt is a plain-text index of your site written for AI search — the AI counterpart of a sitemap. It gives AI assistants a machine-readable map of your published content so they can discover and cite your pages without parsing every template. This plugin serves `/llms.txt` (a curated index, most important pages first) and `/llms-full.txt` (the full text of your published content) live from your database — always in sync, with ETag/Last-Modified caching, and no static files written to your server.

= Do llms.txt and IndexNow work without a Seonix account? =

Yes. Both work immediately after activation — free, no account, no registration, no API key to obtain. llms.txt and llms-full.txt are generated live from your published content, and the IndexNow verification key is provisioned automatically on the first submission. Every other feature builds on a Seonix connection, but these two never require one.

= What is IndexNow and which search engines support it? =

IndexNow is an open protocol for telling search engines that a URL changed, instead of waiting for the next scheduled crawl. When you publish or update a public post or page, the plugin pings the shared IndexNow API, which distributes the URL to participating engines — Bing, Yandex, Seznam, and Naver — so they re-crawl it within minutes. Google does not participate in IndexNow. Drafts, private content, and pages marked noindex are skipped, and the same URL is not re-submitted more than once per 10 minutes. You can toggle it any time in the plugin settings.

= Does this plugin help with GEO / AEO (AI search optimization)? =

Yes — that is its core focus. Generative engine optimization (GEO) and answer engine optimization (AEO) mean making your content easy for AI engines to find, parse, and quote. Out of the box the plugin covers the technical layer: llms.txt / llms-full.txt for AI-crawler discovery and IndexNow for instant recrawl pings. With a connected Seonix project it goes further: structured data (JSON-LD) on published articles, an AI-search pillar in your site audit with concrete issues to fix, and articles written and structured for both Google results and AI answers.

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

= 2.16.0 =
* New: one-click "hide from search" fixes dispatched from the Seonix dashboard — `set_post_robots_noindex` noindexes a placeholder (stub) page and `set_term_robots_noindex` noindexes a category/tag archive that duplicates a real content page. Both write through the active SEO plugin's own robots settings (Yoast SEO postmeta / Rank Math robots list; term overrides go through Yoast's public taxonomy-meta API), so the change shows up in that plugin's UI and its XML sitemap drops the URL automatically. Sites without a supported SEO plugin report `no_supported_seo_plugin` instead of writing robots state nothing would render.
* New: every robots fix stores an undo snapshot keyed by the dashboard's revert token; `revert_post_robots_noindex` / `revert_term_robots_noindex` restore the exact previous robots value (down to "no override at all"), refuse to clobber a state the owner changed by hand since, and are idempotent on retries. Snapshot storage is bounded (100 entries, oldest pruned first).
* New: robots fixes verify themselves — after writing, the plugin fetches the affected URL server-side (purging the Seonix Optimizer page cache for it first when the Optimizer plugin is installed, plus a cache-buster) and reports whether the rendered HTML or X-Robots-Tag header actually carries the noindex; `verified=false` with a reason when a page cache still serves the old markup.
* Improved: Yoast term robots changes sync the indexables table immediately, so the live archive renders the new robots meta on the next request instead of waiting for Yoast's own rebuild.
* Improved: SEO-fix apply responses now carry top-level `applied` / `verified` fields for methods that self-verify (existing methods are byte-identical on the wire).

= 2.15.0 =
* Improved: llms.txt now follows the llmstxt.org "## Optional" convention — utility pages (contact, privacy, terms) are listed there together with a link to llms-full.txt instead of being dropped entirely.
* Improved: llms.txt sections with a single link fold into their parent category section (or the generic Posts list), so a young site no longer renders dozens of one-link headings.
* Improved: llms.txt fallback descriptions (posts without an SEO meta description) now skip headings and end on a sentence boundary instead of cutting mid-word with an ellipsis.
* Improved: publishing no longer bumps the post's modified date while localizing images — a freshly published article keeps modified = published instead of showing an "updated 9 seconds later" footprint.
* Improved: HowTo structured data from Seonix articles is now emitted alongside Yoast / Rank Math / AIOSEO (neither engine outputs HowTo automatically, so step-by-step guides previously lost the markup).
* Improved: publishing stores the SEO plugin's primary-category meta (deepest assigned category) when it is not already set, keeping breadcrumbs and the llms.txt grouping on the most specific term.
* Improved: sitemap-cache invalidation after publishing now logs failures and falls back to purging Rank Math's cache storage directly when its API is unavailable; the publish response reports what was invalidated.

= 2.14.0 =
* New: Review and ItemList structured data now survive alongside Yoast / Rank Math / AIOSEO — testimonial pages and service-area directories keep their rich-result markup instead of losing it to the anti-duplication trim.
* New: developers can extend the supplemental structured-data allowlist via the `seonix_schema_supplemental_types` filter; the guard against duplicating the SEO plugin's own Article / WebPage / Organization nodes always stays on.
* New: the sitewide business entity (address, phone, service area) now also enriches Rank Math's Organization schema — previously Yoast-only. AIOSEO exposes no comparable filter and is unaffected.
* New: "noindex stub page" one-click fix — a page the site audit flags as a thin placeholder can be hidden from search through your SEO plugin's own robots meta (and its sitemap) until real content is written. One page per click, fully reversible.
* Improved: rolling back a fix now refuses with a clear message when the value was edited after the fix was applied — restoring an old snapshot can no longer silently erase your later changes.

= 2.13.1 =
* New: the llms.txt business block accepts operator extensions via the `seonix_llmstxt_business_extra` filter — add pricing, availability or review facts so AI assistants can quote them directly.

= 2.13.0 =
* New: sitewide business entity — when your Seonix project carries a business profile (address, phone, service area), the plugin enriches your SEO plugin's Organization structured data into a proper LocalBusiness entity and lists the facts in llms.txt, so Google and AI assistants see ONE consistent business instead of scattered fragments. Fully automatic, nothing to configure; profiles without complete address data change nothing.
* Improved: llms.txt is now a curated map instead of a category dump — every post is listed exactly once under its primary category (a post in three nested categories used to appear in every one of them), placeholder drafts ("coming soon" stubs), noindexed content and utility pages (contact, checkout, cart) are excluded, and descriptions prefer your real meta description over an auto-cut excerpt.
* Fixed: llms-full.txt no longer includes the full text of password-protected posts — protected content stayed protected everywhere except this file.
* Fixed: automatic SEO repairs (filling missing image alt text, rewriting broken links, upgrading http:// images to https) no longer change the post's visible "last updated" date — a bulk repair used to re-stamp dozens of old posts as freshly updated, which reads as manufactured freshness to visitors and search engines. Real content updates keep bumping the date as before.

= 2.12.11 =
* Fixed: structured data (FAQ and local-business JSON-LD) no longer loses umlauts, the euro sign and dashes when an article is published — special characters used to be stored as garbage like "Mu00f6belmontage", which stopped Google rich results and gave AI assistants unreadable text.
* Fixed: existing articles with corrupted structured data are repaired automatically the next time the page is viewed — no re-publish needed.
* Fixed: publishing could silently strip backslashes from the article body and stored key takeaways; content is now stored exactly as generated.
* Improved: per-page Service nodes in stored structured data are now kept alongside an active SEO plugin, so service/city pages can reference the site's business entity without duplicating it.

= 2.12.10 =
* Fixed: updating an article whose WordPress post was deleted (for example after restoring the site from a backup) no longer fails with a generic error — the plugin now reports the missing post clearly, and Seonix automatically re-publishes the article as a fresh post.

= 2.12.9 =
* Housekeeping: bundled editor and admin assets are cache-busted by their file modification time, so updated styles and scripts reach the browser reliably without needing a plugin version bump.

= 2.12.8 =
* Improved: the Search appearance previews now match the real search result — mobile is a card with a thumbnail next to the description, desktop is a plain full-width result with a larger title and the breadcrumb URL.
* Fixed: the SEO title and meta description are editable on every site again (Seonix keeps its own copy and syncs it to any active SEO plugin and to your dashboard).
* Fixed: the length meter is green across the whole good range — a 60/60 title now reads green, not amber; it only turns amber when too short and red when it would be clipped.

= 2.12.7 =
* Fixed: the Google preview in Search appearance now gives the title the full width (no thumbnail squeezing it) so it no longer wraps to a clipped column, and reads cleanly on both mobile and desktop.

= 2.12.6 =
* Improved: when an SEO plugin (Yoast, Rank Math, …) owns the SEO title and meta description, the Search appearance preview now updates live as you edit them there, instead of only after a reload.

= 2.12.5 =
* Added: a "Search appearance" section in the editor panel — a live Google and social preview of the page with its SEO title and meta description. On sites with no SEO plugin the fields are editable here and Seonix syncs them to any SEO plugin you add and to your Seonix dashboard; when Yoast, Rank Math or another SEO plugin is active their values are shown.
* Added: each link in the Links section now has edit, remove and open-in-a-new-tab actions on hover.

= 2.12.4 =
* Changed: the Seonix icon in the editor toolbar is a green / amber / red status dot again (the exact issue count stays in the Page issues section, where it belongs).
* Changed: the Links section no longer lists popup and button triggers (Popup Maker "#popmake-…", bare "#") — only real links a visitor can follow.
* Added: click a link in the panel to jump straight to it in the article; a hover icon opens it in a new tab.

= 2.12.3 =
* Changed: the Seonix icon in the editor toolbar now shows the number of open issues on the page — a green tick when there are none — coloured by severity, so a page's status is readable at a glance without opening the panel.
* Added: page issues whose fix lives in the Redirects manager now show a one-click "Open redirect manager" button right inside the editor panel.

= 2.12.2 =
* Improved: redirects now land in ONE hop. Targets are stored and served in your site's canonical form (trailing slash follows your permalink settings), and a redirect whose target is itself redirected is followed to its final destination before responding — visitors and crawlers get a single 301 instead of a chain of two or three. Existing rules are healed automatically, no editing needed.
* Fixed: redirect chains that loop back on themselves are detected and the page is served instead of bouncing the browser.

= 2.12.1 =
* Fixed: Site Health now shows exactly the same numbers as your Seonix dashboard — the same overall and per-pillar scores and the same Active / Fixed / Came back counts. Seonix computes them once and the plugin displays them as-is, so the two screens can never disagree again.
* Changed: opening the dashboard re-syncs from Seonix when the local copy is older than 5 hours (was 24). The Refresh button still forces an immediate sync.

= 2.12.0 =
* Added: a "Links" section in the Seonix panel on the post editor. See the internal and external links in your article at a glance, with counts and anchor text, in both the block editor and the classic editor.

= 2.11.3 =
* Fixed: the "Key takeaways" box at the top of Seonix articles now takes its look from your site — your theme's fonts and colours with a light tint of your brand colour — instead of bringing its own palette. Previously it could also flip to a dark box on light sites when a visitor's device preferred dark mode.
* Fixed: theme stylesheets could distort the box — an oversized uppercase heading, bullet markers overlapping the text, or extra list indentation. It now renders consistently across themes.
* Improved: the broken-link fix now leaves page-builder content (Elementor, Divi, WPBakery and similar) alone instead of editing markup those builders may regenerate, and `og:url` follows the page's real canonical URL.
* Improved: the 404 log folds scanner and bot probes (requests like `/.env` or `/xmlrpc.php` that never existed on your site) into a collapsed "Scanner & bot noise" section with a one-click Dismiss all, so real broken links stay visible.

= 2.11.2 =
* New: a Seonix column in the post and page list tables, and the editor score is cached until the text changes.

= 2.11.1 =
* New: redirects live in the Seonix admin shell, with a 404 log, CSV import/export, and accurate Site Health counts.
* New: a score badge in the editor and the Seonix mark in the toolbar entry.

= 2.11.0 =
* New: a focus keyphrase field in the editor for sites without a separate SEO plugin — set the phrase a page should rank for and the Seonix panel scores the text against it. Sites that already have an SEO plugin keep using that plugin's field, as before.
* New: the plugin now tells your Seonix dashboard which version it is running, so the Integrations page shows the installed version for every connected site and points out when a newer one is available.
* The version travels on the calls the plugin already makes to Seonix — no extra requests, nothing about your content, and nothing sent anywhere else.
* Updates themselves are unchanged: WordPress installs them from the plugin directory as it always has.

= 2.10.0 =
* New: rename a published post and the old link keeps working — Seonix records the redirect for you, as a real rule you can see and edit. Trash a post and its URL stops pretending: a child page points at its parent, anything else returns "410 Gone" so search engines drop it quickly instead of retrying a dead link for months.
* New: pattern redirects. One rule can move a whole section — `^/blog/(\d+)/?$` → `/archiv/$1` covers every post id at once.
* New: 307, 308 and 410 alongside 301 and 302. The 307/308 pair keeps the request method intact, which matters for form endpoints; 410 needs no target at all.
* New: redirects screen rebuilt — add rules, filter by source or status, search paths, and apply bulk enable/disable/delete. Each rule shows its hit count and when it was last used, so you can tell a rule that still earns its place from one that can go.
* Improved: a rule pointing at a page you later removed now sends visitors to that page, which answers "gone" — instead of sending them nowhere.

= 2.9.0 =
* New: Seonix in the toolbar. Every page now carries its own standing in the admin bar — SEO score, readability score, and how many issues the last scan found on it — on the live site as well as in wp-admin, so you can see where a page stands without opening the editor.
* The scores shown are those of the saved version, the one your visitors actually get: typing in the editor updates the panel live, but the toolbar only changes once you save.

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
