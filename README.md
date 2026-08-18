# Seonix SEO — WordPress Plugin

Connects your WordPress site to **[Seonix](https://seonix.ai)**, the AI agent for organic and AI-search growth. Once connected, Seonix audits your technical SEO in real time, writes SEO-optimized articles, applies one-click fixes, and publishes on a schedule — for Google and AI search engines like ChatGPT, Gemini, and Perplexity.

The plugin is the bridge between your site and the Seonix service: it receives published articles, syncs your site structure for internal linking, serves `/llms.txt` for AI crawlers, and applies SEO fixes. The AI heavy-lifting runs on the Seonix platform.

## What it does

- **Publishes AI-written articles** to WordPress with the standard SEO meta fields every major SEO plugin reads.
- **Syncs your content** (pages, posts, WooCommerce products) so generated articles can link internally.
- **Applies one-click SEO fixes** — broken links, meta titles and descriptions, image alts, redirects, and more — with rollback.
- **Manages redirects natively** — 301/302 rules created by Seonix fixes, synced from the Seonix dashboard, or added by the site owner are stored in the plugin's own table and served on the front end. No third-party redirect plugin required (see `includes/redirects/`).
- **Serves `/llms.txt`** so AI crawlers can discover your content.
- **Adds schema.org structured data** (Article, breadcrumbs, FAQ/How-To) to published articles.

## Redirect manager (developer notes)

Module: `includes/redirects/` — store (`Seonix_Redirects_Store`), front-end runner (`Seonix_Redirects_Runner`, hooked on `template_redirect` priority 1), REST controller (`Seonix_Redirects_Controller`), and the wp-admin screen (`Seonix_Redirects_Admin`, **Seonix → Redirects**).

### Storage

Table `{$wpdb->prefix}seonix_redirects`, created via dbDelta on activation and re-applied by the version-gated upgrade in `seonix_init()` (`seonix_db_version` option):

| Column        | Type                     | Notes |
| ------------- | ------------------------ | ----- |
| `id`          | BIGINT UNSIGNED PK AI    | |
| `seonix_id`   | CHAR(36) NULL UNIQUE     | UUID for rows managed by the Seonix service; NULL for locally-created ("Local") rows |
| `from_path`   | VARCHAR(191) NOT NULL    | Normalized: leading `/`, path only (no scheme/host/query), trailing slash stored as given |
| `to_url`      | TEXT NOT NULL            | Relative path or absolute http(s) URL |
| `status_code` | SMALLINT UNSIGNED, 301   | 301 or 302 only |
| `enabled`     | TINYINT(1), 1            | |
| `hits`        | BIGINT UNSIGNED, 0       | Incremented on every served redirect |
| `created_at` / `updated_at` | DATETIME   | DB-managed |
| `deleted_at`  | DATETIME NULL            | Tombstone: set when a Seonix-managed rule is deleted locally, so the service learns about the deletion; pruned during sync (max 200, max 90 days) |

`from_path` uniqueness is enforced in code among `deleted_at IS NULL` rows, on the runtime **match key** (url-decoded, lower-cased, trailing-slash-insensitive).

### Matching (front end)

The request path is compared on the match key — query strings never participate and are re-appended to the target verbatim. Admin, login, REST, XML-RPC, favicon and robots requests are never redirected; self-redirects are skipped; a target that is itself redirected is flattened one hop, and two-rule cycles (A→B, B→A) abort the redirect. The compiled rule map is cached (wp_cache + `seonix_redirects_map` transient) and invalidated on every write. External-host targets are explicitly allow-listed for `wp_safe_redirect()` — rules only enter the table through authenticated surfaces.

### REST API

Registered under `seonix/v1` **and** the legacy `content-engine/v1`, authenticated with the plugin's Bearer connection token (same `Seonix_Auth::validate_request` as every other machine endpoint):

```
GET /wp-json/seonix/v1/redirects
→ 200 {
    "items":      [ {"id":123, "seonix_id":"<uuid>|null", "from_path":"/a", "to_url":"/b",
                     "status_code":301, "enabled":true, "hits":5,
                     "created_at":"2026-07-15 10:00:00", "updated_at":"..."} ],
    "tombstones": [ {"seonix_id":"<uuid>", "deleted_at":"..."} ],
    "version":    "<plugin version>"
  }
```

`items` excludes tombstoned rows; `tombstones` lists only rows that have a `seonix_id`.

```
POST /wp-json/seonix/v1/redirects/sync
body { "upsert": [ {"seonix_id":"<uuid>", "from_path":"/a", "to_url":"/b", "status_code":301, "enabled":true} ],
       "delete_seonix_ids": ["<uuid>", ...] }
→ 200 { items, tombstones, version, "applied":n, "deleted":n,
        "errors": [ {"seonix_id":"<uuid>", "code":"from_path_conflict"|"invalid", "message":"..."} ] }
```

Sync semantics: `upsert` matches by `seonix_id` (insert or update; an upsert with a tombstoned row's `seonix_id` resurrects it by clearing `deleted_at`); a `from_path` colliding with a *different* active row is skipped with an `errors[]` entry (`from_path_conflict`); invalid items (`from_path` without leading `/` or with a scheme, empty `to_url`, status code outside 301/302, target equal to source) are skipped with code `invalid`. `delete_seonix_ids` hard-deletes (service-initiated deletions need no tombstone). Deletions are applied before upserts so a rule can move between UUIDs in one payload. Rate limit: 60 sync calls/minute per token.

The seo-fix `redirect` method writes into this table (rows with `seonix_id` NULL) and no longer requires the Redirection plugin; rollback of history entries created by pre-2.7.0 versions still reverses against the Redirection table. `GET /seo-fix/capabilities` advertises the module as `"redirects": {"version": 1}`.

## Content inventory (developer notes)

Seonix needs to know what already exists on the site — it is what internal-link
suggestions, cluster planning and "don't write this twice" are built on. There
are two roads, and they carry the same item shape (`Seonix_Sync::format_item`):

**Push.** `push_full_sync()` POSTs every published page/post/product to
`{engine}/api/plugin/sync`. Fires on the weekly `seonix_weekly_sync` cron, on
**Sync now** in Settings → Seonix, and — since 2.17.0 — on a one-off
`seonix_engine_url_sync` event scheduled a minute after a verify hands the site
a new `engine_url`. That last one closes a real hole: the weekly cron is
scheduled at *activation*, when no engine URL exists yet, so its first firing
pushed nothing and the next was seven days later. A site connected today used to
appear empty in Seonix for a week.

**Pull** (2.17.0+). `GET /wp-json/seonix/v1/inventory?page=1&per_page=100`,
Bearer-authenticated like every machine route:

```
→ 200 {
    "items": [ {"wp_id":11, "content_type":"page", "title":"About",
                "slug":"about", "url":"https://…/about/", "status":"publish",
                "updated_at":"2026-08-01T10:30:00+00:00"} ],
    "has_more": true
  }
```

Published content only, ID-ordered so pagination stays stable while the site is
being edited, `per_page` clamped to 100, and `product` included only when
WooCommerce is active. Deliberately not `/posts?post_type=…`: that route returns
full post content plus SEO meta per row, which for an inventory of a few
thousand posts is tens of megabytes the caller discards. Term and meta caches
are switched off for the query — the route reads seven scalar columns.

## Installation

1. Upload the `seonix` folder to `/wp-content/plugins/`, or install the `.zip` via **Plugins → Add New → Upload Plugin**.
2. Activate **Seonix SEO** through the **Plugins** menu.
3. Open the **Seonix** menu in wp-admin and click **Connect to Seonix** — pick your project in the Seonix app and the connection completes in one click.

Prefer manual setup? On **Seonix → Settings**, copy the auto-generated API key (`sx_…`) and paste it into your Seonix project's WordPress channel. No WordPress passwords either way.

## Requirements

- WordPress 6.2+
- PHP 7.4+
- A [Seonix](https://seonix.ai) account

## Links

- Service: [seonix.ai](https://seonix.ai)
- Terms of Service: [seonix.ai/terms-of-use](https://seonix.ai/terms-of-use)
- Privacy Policy: [seonix.ai/privacy-policy](https://seonix.ai/privacy-policy)

## License

GPL-2.0-or-later. See [`LICENSE`](LICENSE).
