=== LLMs.txt for WooCommerce ===
Contributors: xpay
Tags: llms.txt, ai discovery, woocommerce, chatgpt, agents
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The llms.txt plugin specialised for WooCommerce. Generates /llms.txt and /llms-full.txt so AI shopping agents can find your products.

== Description ==

**Will ChatGPT recommend your store?** AI shopping agents look for an `/llms.txt` file on your domain to learn what you sell. Most WooCommerce stores don't ship one — and the generic content-walking llms.txt plugins that do walk your blog posts instead of your **products**.

This plugin is purpose-built for WooCommerce stores. It generates two files:

* `/llms.txt` — a card-ready product table (name + price + image + link) for the top N products in your catalog, with discovery links + AI shopping-assistant instructions
* `/llms-full.txt` — your full catalog, one section per product, for AI agents that want everything in one fetch

Files are written to your webroot, refreshed daily via WP-Cron (or on demand), and served straight from your domain. Install, activate, done in 30 seconds. **Free forever.**

= Why commerce-specialised matters =

Generic llms.txt plugins walk your blog posts and pages. AI shopping agents need **products** — with a numeric price and an image per item — to build a shopping card. This plugin reads your live WooCommerce catalog (including variable-product price ranges as "from" prices) so your products are card-ready the moment an agent fetches the file.

It honours WooCommerce visibility settings (hidden / shop-only / search-only products stay out of `/llms.txt`), and adds a per-product **Exclude from llms.txt** checkbox on each product edit screen for fine-grained control.

= What's included =

* Daily WP-Cron refresh + on-demand "Regenerate now"
* 48-hour stale-fallback (catches up on next page load if WP-Cron didn't fire)
* Per-product exclusion via product edit metabox
* WooCommerce `catalog_visibility` honoured (hidden / shop-only / search-only excluded)
* Product description fallback chain: Yoast → Rank Math → AIOSEO → SEOPress → Slim SEO → WC short description
* Variable-product price normalisation (no $0 cards)
* Filesystem-fallback chain so it writes correctly on WP Engine, Flywheel, WP VIP and other hardened hosts
* Version history with one-click restore and pin (see below)

= Version history =

After every refresh the plugin keeps a record of what was generated, so you can compare versions and roll back if a product change or pricing edit went out wrong. **Restore a version** writes that version's body back to your webroot and **pins** it so the next daily refresh doesn't overwrite it — unpin from the Version Control tab to resume daily refresh.

Sync to the xpay.sh version-control API is **on by default** and disclosed in a dismissible admin notice on activation. Toggle it off in **Settings → LLMs.txt → Privacy** at any time. See `== External services ==` below.

= Built by xpay =

From the team behind Agentic Commerce for WooCommerce. No nags, no popups — just `/llms.txt` for your products.

== External services ==

This plugin connects to xpay.sh to provide version history and one-click rollback for your generated `/llms.txt` and `/llms-full.txt`. This integration is **on by default** and disclosed in a dismissible admin notice on activation; switch it off at any time in **Settings → LLMs.txt → Privacy**.

**Service:** xpay.sh LLMs.txt version-control API
**Base URL:** `https://8mf8prh9rg.execute-api.us-east-1.amazonaws.com` (filterable via the `lltxt_backend_base_url` filter or `lltxt_backend_base_url` option)
**Privacy policy:** https://xpay.sh/privacy
**Terms of service:** https://xpay.sh/terms

**Endpoints called, what's sent, when, and why:**

* `POST /v1/llms-txt/snapshot` — After every refresh (daily WP-Cron + on-demand). Sends the rendered `/llms.txt` and `/llms-full.txt` bodies (the same bodies served publicly from your domain) plus the site slug (derived from your domain), home URL, plugin/WordPress/WooCommerce version strings, and a sha256 of a randomly-generated local API key as the `X-Xpay-Api-Key` header. Keeps the version history.
* `GET /v1/llms-txt/versions` — When you open the Version Control tab. Sends the slug and API key hash only.
* `GET /v1/llms-txt/version/{id}` — When you preview or restore a prior version. Sends the slug and API key hash only.
* `POST /v1/llms-txt/recommend` — When you click "Get recommendation" in the Version Control tab. Sends the slug, route, and API key hash.
* `POST /v1/llms-txt/pin` — When you pin or unpin a version. Sends the slug, version id, pinned state, and API key hash.
* `DELETE /v1/llms-txt/merchant` — When you click "Delete my data" in the Privacy tab, or on plugin uninstall (if sync was enabled). Sends the slug and API key hash; wipes all your snapshots from xpay.sh.

**What is NOT sent:** no order data, no customer data, no admin credentials, no analytics, no raw API key. The file bodies are the same bodies served publicly at `yourstore.com/llms.txt`.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` (or install from the WordPress Plugins screen).
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **Settings → LLMs.txt** to preview the generated files and choose how many products to surface.
4. Visit `https://yourstore.com/llms.txt` to confirm it's live.

== Frequently Asked Questions ==

= Does this send my data anywhere? =

By default the plugin syncs each generated `/llms.txt` and `/llms-full.txt` body to xpay.sh so you get version history and one-click rollback. You can disable this in **Settings → LLMs.txt → Privacy**, and the dismissible admin notice on activation discloses it up front. See the **External services** section above for the full list of endpoints, what's sent, and when.

= My host has a read-only filesystem — will it still work? =

Yes. The plugin tries three write strategies (direct file write, `WP_Filesystem`, fopen stream) so it works on WP Engine, Flywheel, WP VIP and most hardened hosts. If a static file still can't be written, the plugin renders the file live on request as a fallback. You'll see write errors in the Diagnostics tab.

= I already have a /llms.txt file on my server — will this overwrite it? =

No. On activation the plugin detects any existing `/llms.txt` (or `/llms-full.txt`) and backs it up to `wp-content/uploads/lltxt-backups/` before doing anything else. The file is marked **merchant-managed** by default, which means the plugin will not overwrite it. Switch the file to **plugin-managed** from the Files tab if you'd rather we keep it fresh.

= Does it work with caching plugins (WP Rocket, LiteSpeed, Cloudflare)? =

Yes. The files are static and cache-friendly. If you change settings, regenerate from the Files tab and purge your cache.

= I want to exclude a specific product from /llms.txt. How? =

Edit the product, tick **Exclude this product from /llms.txt** in the **LLMs.txt for AI Shoppers** sidebar metabox, and save. The next refresh will drop it.

== Screenshots ==

1. The rendered `/llms.txt` with its card-ready product table.
2. Settings: the Files tab with per-file previews and regenerate-now.
3. Version Control tab: history list with one-click restore and pin.
4. Per-product Exclude from llms.txt metabox.
5. Privacy tab: version-history sync toggle + delete-my-data button.

== Changelog ==

= 1.0.0 =
* Initial release — `/llms.txt` and `/llms-full.txt` specialised for WooCommerce, daily WP-Cron refresh, per-product exclusion, WC visibility honoured, SEO-plugin description chain, filesystem-fallback for hardened hosts, version history with restore and pin.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
