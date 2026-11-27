=== WooSafe MCP ===
Contributors: kittenlabs
Tags: woocommerce, mcp, claude, cursor, hpos
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Safety-first Read-Only MCP server for WooCommerce HPOS. Connects Claude Desktop & Cursor in 30 seconds with 6 free audit tools.

== Description ==

**WooSafe MCP** turns your WooCommerce store into a read-only, auditable Model Context Protocol (MCP) endpoint for Claude Desktop, Cursor, and MCP-compatible AI clients — without exposing WordPress admin passwords or risking accidental store mutations.

= Built for WooCommerce Agencies & Store Operators =

* **100% Read-Only Free Core**: Ships strictly in Read-Only Audit Mode with zero destructive endpoints included in the core plugin.
* **30-Second Claude & Cursor Setup**: Generate a scoped Bearer token (`wsmcp_ro_...`) in WP Admin and click **Copy Claude / Cursor Config JSON** — zero CLI hacks and zero raw WordPress passwords.
* **WooCommerce HPOS Compatible**: Explicitly declares High-Performance Order Storage (`custom_order_tables`) compatibility and uses `wc_get_orders()` and `wc_get_products()` CRUD APIs.
* **PII-Redacted Order Reporting**: `order_summary` aggregates gross/net revenue, AOV, statuses, and gateways without exposing customer emails or street addresses.
* **6 Free Read-Only Audit Tools**:
  * `store_health_audit` — HPOS status, PHP/WP/WC versions, catalog and order posture, guardrail status.
  * `low_stock_report` — SKU-level low stock and out-of-stock report with configurable threshold.
  * `order_summary` — PII-redacted revenue, AOV, order counts by status, and payment gateway breakdown.
  * `coupon_audit` — Flags high-discount (`>40%`), unlimited-use, expired, or unexpiring coupons.
  * `broken_sku_finder` — Detects missing SKUs, duplicate SKUs, zero prices, inverted sale prices, and missing featured images.
  * `product_seo_audit` — Audits titles, Yoast/RankMath/WooSafe SEO meta, short descriptions, and image alt text.
* **Persistent Audit Log**: Every MCP request is logged in `wp_woosafe_audit_log` with tool name, mode, decision, reason, and duration.
* **Extensible Guardrail Architecture**: Optional add-ons (including [WooSafe MCP Pro](https://woosafe-mcp.kittenlabs-hq.workers.dev/)) can register guarded write tools via `woosafe_mcp_registered_write_tools` with mandatory Dry-Run diffs, configurable price-delta & batch caps, and `wp_woosafe_snapshots` 1-click rollback.

== Installation ==

1. Upload `woosafe-mcp` to `/wp-content/plugins/` or install the ZIP via **Plugins > Add New > Upload Plugin**.
2. Activate **WooSafe MCP** through the **Plugins** screen (requires WordPress 6.4+, PHP 8.0+, and WooCommerce 8.0+).
3. Go to **WooCommerce > WooSafe MCP**.
4. Click **Generate Read-Only Token**, then click **Copy Claude / Cursor Config JSON** and paste it into Cursor (`mcp.json`) or Claude Desktop (`claude_desktop_config.json`).

== Frequently Asked Questions ==

= Does this expose my WordPress admin password to Claude or Cursor? =
No. WooSafe MCP issues dedicated scoped Bearer tokens (`wsmcp_ro_...`) hashed with `wp_hash_password()` and verified in constant time via `hash_equals()`. You can revoke any token immediately from WP Admin.

= Does my store data pass through any third-party cloud proxy? =
No. WooSafe MCP runs 100% inside your own WordPress site at `/wp-json/woosafe-mcp/v1/mcp`. Your AI client connects directly to your store.

= Can an AI mutate prices, stock, or orders with the Free Core plugin? =
No. The Free Core plugin only registers 6 read-only audit tools and ships locked in Read-Only Audit Mode.

= Is WooSafe MCP compatible with WooCommerce HPOS? =
Yes. WooSafe MCP declares compatibility with `custom_order_tables` (HPOS) and queries orders and products exclusively via WooCommerce CRUD functions (`wc_get_orders`, `wc_get_products`).

== Screenshots ==

1. WP Admin 30-second setup with scoped Bearer token generator and 1-click Claude Desktop / Cursor JSON copy.
2. Safety Mode posture indicator, HPOS compatibility badge, and configurable guardrail settings.
3. Live MCP Audit Log recording every tool invocation, decision, and execution time.

== Changelog ==

= 1.0.0 =
* Initial public release.
* Streamable HTTP JSON-RPC 2.0 MCP endpoint at `/wp-json/woosafe-mcp/v1/mcp`.
* Scoped Bearer token authentication with `wp_hash_password()` and `hash_equals()`.
* 6 Free Read-Only Audit Tools (`store_health_audit`, `low_stock_report`, `order_summary`, `coupon_audit`, `broken_sku_finder`, `product_seo_audit`).
* Persistent MCP Audit Log (`wp_woosafe_audit_log`) and extensible guardrail/snapshot hooks (`wp_woosafe_snapshots`) for optional Pro write add-ons.
* WooCommerce HPOS (`custom_order_tables`) compatibility declared.
