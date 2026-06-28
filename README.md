# LLMs.txt for WooCommerce

Auto-generate `llms.txt`, `catalog.json`, `agents.md` and 10 more AI-discovery
files so ChatGPT, Claude, Perplexity and Google AI can find, understand, and
sell your WooCommerce products.

> This `README.md` mirrors `readme.txt` (the WordPress.org source of truth).

## What it does

Most WooCommerce stores don't emit the files AI shopping agents look for. This
plugin ships **13 of them** — generated locally in PHP, refreshed daily via
WP-Cron, and served straight from your own domain. **Free forever, zero
phone-home.**

Unlike generic content-walking `llms.txt` plugins, it's **commerce-aware**: it
reads your live catalog (products, prices, stock, variations, images,
categories) and never reports a `$0` price for a variable product, so your
items render as real AI shopping cards instead of dropping to prose.

## The 13 files

| File / route | What it carries |
|---|---|
| `/llms.txt` | Discovery links + card-ready product table + AI shopping instructions |
| `/index.md` | Human-readable index: product count, categories, how to query |
| `/llms-full.txt` | Full catalog dump, one section per product |
| `/catalog.json` | Machine-readable ACP-compatible product feed |
| `/products.json` | Shopify-shape feed for crawler compatibility |
| `/agents.md` | agents.md emerging-standard discovery manifest |
| `/sitemap-ai.xml` | Sitemap optimized for AI crawlers |
| `/feed/google-shopping.xml` | Google Merchant Center RSS 2.0 product feed |
| `/.well-known/agent-card.json` | A2A agent card |
| `/.well-known/mcp.json` | MCP descriptor (points at local catalog) |
| `/.well-known/ucp` | Universal Commerce Protocol business profile |
| `robots.txt` directives | Toggle which AI crawlers may access your store |
| `<head>` discovery + JSON-LD | Discovery links injected into your storefront |

## Install

1. Upload to `/wp-content/plugins/` or install from the Plugins screen.
2. Activate via **Plugins**.
3. Go to **Settings → LLMs.txt** to preview files, toggle AI bots, and pick which products to surface.
4. Visit `https://yourstore.com/llms.txt` to confirm it's live.

## Admin tabs

- **Files** — preview / regenerate each file, last-generated time, Regenerate All
- **AI Bots** — allow/disallow ChatGPT-User, Claude-User, PerplexityBot, GoogleOther, OAI-SearchBot, GPTBot
- **Catalog** — top-N count, ordering, category include/exclude
- **Diagnostics** — file preview, test-as-ChatGPT-User loopback fetch, refresh log

## Requirements

- WordPress 6.0+
- WooCommerce 7.0+
- PHP 7.4+

## License

GPL-2.0-or-later. Built by [xpay](https://xpay.sh).
