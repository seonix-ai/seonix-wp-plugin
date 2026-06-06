# Seonix SEO — WordPress Plugin

Connects your WordPress site to **[Seonix](https://seonix.ai)**, the AI agent for organic and AI-search growth. Once connected, Seonix audits your technical SEO in real time, writes SEO-optimized articles, applies one-click fixes, and publishes on a schedule — for Google and AI search engines like ChatGPT, Gemini, and Perplexity.

The plugin is the bridge between your site and the Seonix service: it receives published articles, syncs your site structure for internal linking, serves `/llms.txt` for AI crawlers, and applies SEO fixes. The AI heavy-lifting runs on the Seonix platform.

## What it does

- **Publishes AI-written articles** to WordPress with the standard SEO meta fields every major SEO plugin reads.
- **Syncs your content** (pages, posts, WooCommerce products) so generated articles can link internally.
- **Applies one-click SEO fixes** — broken links, meta titles and descriptions, image alts, redirects, and more — with rollback.
- **Serves `/llms.txt`** so AI crawlers can discover your content.
- **Adds schema.org structured data** (Article, breadcrumbs, FAQ/How-To) to published articles.

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
