=== Agentic Commerce – LLMs.txt for WooCommerce ===
Contributors: xpaysh
Tags: llms.txt, woocommerce, ai shopping agents, ai search, generative engine optimization
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Commerce-aware /llms.txt for your WooCommerce store. Generates a machine-readable catalog AI shopping agents can read — auto-refreshed, free.

== Description ==

A shopper asks an AI assistant: *"Where can I buy a waterproof bike light under $50?"*

For an AI shopping agent to consider your store as an answer, it needs a machine-readable summary of what you sell. The emerging convention for this is a file called `/llms.txt` at your domain root.

This plugin generates a commerce-aware `/llms.txt` and `/llms-full.txt` directly from your live WooCommerce catalog — including real prices, real images, real stock, and per-variation data — and refreshes them automatically when your catalog changes.

= What you get =

* **A `/llms.txt` file** built from your live WooCommerce catalog — top products, prices, images, and links in the format AI shopping agents read.
* **A `/llms-full.txt` file** with your full catalog, one section per product, for agents that want everything in one fetch.
* **Daily auto-refresh.** When you add products, change prices, or update stock, your files update themselves. No schedules to wire up.
* **Version history with one-click rollback.** Every refresh is stored locally on your site so you can compare and restore. Pin a version when you've got it just right.
* **Free.** No tier-gates, no premium upsells.

= Commerce-aware fields =

This plugin reads your live WooCommerce catalog and emits product-shaped data:

* **Real prices**, including "from" prices for variable products
* **Real images**, including products with size/colour variations
* **WooCommerce visibility honoured** — hidden, shop-only, and search-only products stay out by default
* **Per-product *Exclude from llms.txt* checkbox** on every product edit screen
* **Smart description fallback** — pulls from Yoast SEO, Rank Math, AIOSEO, SEOPress, or Slim SEO if available; falls back to the WooCommerce short description otherwise
* **Non-destructive take-over of an existing `/llms.txt`** — any existing file is backed up to `wp-content/uploads/lltxt-backups/` first. Restore your version any time from the Files tab; the backup is preserved on uninstall.

= About =

Built by the team behind *Agentic Commerce for WooCommerce*. Open source: [github.com/xpaysh/agentic-commerce-llms-txt](https://github.com/xpaysh/agentic-commerce-llms-txt)

== External services ==

This plugin can communicate with the xpay.sh install-tracking endpoint, but every request is **off by default** and only happens after you explicitly enable the install ping under **Settings → Agentic Commerce → Privacy**. No personal data, no product data, and no order data are ever transmitted.

What is sent (only when you have opted in): your site URL, a derived slug, your WordPress / WooCommerce / plugin versions, and your active product count.

When a request can occur (each only when the toggle is ON):

* **On opt-in** — a single POST so the backend recognises this site
* **Weekly heartbeat** — one POST per week via WP-Cron
* **On plugin deactivation** — one POST marking the install dormant
* **On plugin uninstall** — one POST marking the install removed
* **When you click "Delete my install info"** — one POST asking the backend to delete this install's row

What is **never** sent: product titles, prices, images, SKUs, descriptions, customer or order data of any kind, and no data at all if you have not enabled the toggle.

**Endpoint:** `https://llmstxt-api.xpay.sh/v1/llms-txt/installs`
**Privacy policy:** https://www.xpay.sh/legal/privacy-policy/

== Installation ==

1. Install from **Plugins → Add New** in your WordPress admin, or upload the ZIP at **Plugins → Add New → Upload Plugin**.
2. Activate. WooCommerce must be active.
3. Visit `https://yourstore.com/llms.txt` to see your generated file.

Settings live at **Settings → Agentic Commerce** if you want to tune which products are featured.

== Frequently Asked Questions ==

= What is /llms.txt? =

`/llms.txt` is an emerging convention for a machine-readable summary file at the root of a website that AI assistants can fetch to understand what the site offers. For commerce sites, this means a structured list of products, prices, images, and stock.

= I already have a /llms.txt — what happens to it? =

Your file is preserved. On activation, the plugin saves your original to a timestamped backup inside your WordPress uploads folder, then takes over `/llms.txt` so the generated catalog is served. **Restore your original any time** with one click from the Files tab → *Restore my version*. The backup is never deleted, even on plugin uninstall.

= Does it slow my store down? =

No. Files are generated by a background job (daily, or whenever you click Regenerate), stored as plain static files in your webroot, and served by your web server — not by WordPress on every request.

= Does it work with WP Engine / Flywheel / hardened hosts? =

Yes. The plugin tries multiple write strategies so it works on WP Engine, Flywheel, WP VIP, and most managed hosts where direct file access is restricted. If a file can't be written, you'll see the error and a fix in the Diagnostics tab.

= Does it work with my SEO plugin? =

Yes. The plugin reads your existing product meta descriptions from Yoast SEO, Rank Math, AIOSEO, SEOPress, or Slim SEO — whichever you have. Nothing in your SEO plugin's setup needs to change.

= How do I keep one specific product out of /llms.txt? =

Edit the product. In the right-hand sidebar there's an *LLMs.txt for AI Shoppers* box with an *Exclude this product* checkbox. Tick it, save, done. The next refresh will drop it.

= Is it really free? =

Yes. No paid tier, no premium upsell, no "pro version" in the admin.

= Does this send my data anywhere? =

By default, no. The plugin includes an optional install ping (your URL, your WordPress / WooCommerce / plugin versions, and your product count) that is **OFF by default**. You can opt in under **Settings → Agentic Commerce → Privacy**, and when enabled it runs on activation and weekly. Your generated `/llms.txt` versions are stored only in your own WordPress database; no product or order data ever leaves your site.

== Screenshots ==

1. The Files tab — `/llms.txt` and `/llms-full.txt` auto-generated from your live WooCommerce catalog.
2. Version Control — every refresh kept locally on your site, one-click restore or pin.
3. Catalog tab — pick how many products to feature, which categories to include or exclude.
4. Diagnostics — preview the rendered file body and the refresh log.

== Changelog ==

= 1.1.0 =
* New AI Commerce dashboard home: one-glance file status, a live AI Playground preview of your store, and a one-click Agent-Readiness audit.
* Native card layout on the plugin home screen; existing tabs and file generation are unchanged.

= 1.0.0 =
* Commerce-aware `/llms.txt` and `/llms-full.txt` generation for WooCommerce — daily auto-refresh, per-product exclusion, SEO-plugin description fallback, managed-host compatibility.
* Version Control with one-click restore and pin; non-destructive take-over of any existing `/llms.txt`.

== Upgrade Notice ==

= 1.1.0 =
Adds the AI Commerce dashboard: a live AI Playground preview of your store and a one-click Agent-Readiness audit.

= 1.0.0 =
Commerce-aware /llms.txt and /llms-full.txt for your WooCommerce store.
