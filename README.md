# StoreGuard MCP for WooCommerce — Safety-First MCP Server for WooCommerce HPOS

[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](./LICENSE)
[![WordPress 6.4+](https://img.shields.io/badge/WordPress-6.4%2B-21759b.svg)](#installation)
[![WooCommerce HPOS](https://img.shields.io/badge/WooCommerce-HPOS%20Compatible-7f54b3.svg)](#architecture)

**Live Interactive Dry-Run Diff & Rollback Demo:** https://storeguard-mcp.kittenlabs-hq.workers.dev/

**StoreGuard MCP for WooCommerce** turns your WooCommerce store into a read-only, auditable **Model Context Protocol (MCP)** endpoint (`POST /wp-json/storeguard-mcp-for-woocommerce/v1/mcp`) for **Claude Desktop**, **Cursor**, and any Streamable HTTP JSON-RPC 2.0 MCP client — without exposing WordPress admin passwords or risking blind AI catalog mutations.

---

## Why StoreGuard MCP for WooCommerce?

Standard WooCommerce REST API keys are all-or-nothing. Handing a Read/Write REST key to an AI agent means a single prompt hallucination can overwrite 500 product prices to `$0.01` or publish a 95% off coupon in 400ms with zero diff preview and zero undo table.

**StoreGuard MCP for WooCommerce ships Read-Only by default:**
- **Zero raw WP passwords**: Scoped Bearer tokens (`sgmcp_ro_...`) hashed with `wp_hash_password()` and verified with `hash_equals()`.
- **30-second setup**: Generate a token in **WooCommerce → StoreGuard MCP for WooCommerce** and click **Copy Claude / Cursor Config JSON**.
- **WooCommerce HPOS native**: Declares `custom_order_tables` compatibility and queries exclusively through `wc_get_orders()` and `wc_get_products()`.
- **PII-redacted order metrics**: `order_summary` strips customer emails and street addresses before returning revenue, AOV, status, and payment gateway totals.
- **Persistent Audit Log**: Records every MCP tool call in `wp_storeguard_audit_log`.

---

## Included Free Read-Only Audit Tools (GPLv2)

| Tool | Description | HPOS Safe |
| :--- | :--- | :---: |
| `store_health_audit` | HPOS status, WP/WC/PHP versions, catalog & order posture, active guardrails | Yes |
| `low_stock_report` | Managed-stock SKUs at/below threshold + out-of-stock breakdown | Yes |
| `order_summary` | Gross/net revenue, AOV, status & gateway breakdown (`pii_redacted: true`) | Yes |
| `coupon_audit` | Flags `>40%` discounts, unlimited-use, expired, or unexpiring coupons | Yes |
| `broken_sku_finder` | Missing/duplicate SKUs, `$0` published prices, inverted sales, missing images | Yes |
| `product_seo_audit` | Product titles, Yoast/RankMath SEO meta coverage, short descriptions, alt text | Yes |

---

## 30-Second Installation

1. Download **`storeguard-mcp-for-woocommerce-v1.0.0.zip`** from the [Latest GitHub Release](https://github.com/yefanTao/storeguard-mcp/releases/latest).
2. In WordPress Admin, go to **Plugins → Add New → Upload Plugin**, upload the ZIP, and activate (requires **PHP 8.0+**, **WordPress 6.4+**, **WooCommerce 8.0+**).
3. Open **WooCommerce → StoreGuard MCP for WooCommerce**, click **Generate Read-Only Token**, and copy your MCP config:

### Cursor (`mcp.json`)
```json
{
  "mcpServers": {
    "storeguard-woocommerce": {
      "url": "https://yourstore.com/wp-json/storeguard-mcp-for-woocommerce/v1/mcp",
      "headers": {
        "Authorization": "Bearer sgmcp_ro_..."
      }
    }
  }
}
```

### Claude Desktop (`claude_desktop_config.json`)
```json
{
  "mcpServers": {
    "storeguard-woocommerce": {
      "command": "npx",
      "args": [
        "-y",
        "mcp-remote",
        "https://yourstore.com/wp-json/storeguard-mcp-for-woocommerce/v1/mcp",
        "--header",
        "Authorization: Bearer ${STOREGUARD_MCP_TOKEN}"
      ],
      "env": {
        "STOREGUARD_MCP_TOKEN": "sgmcp_ro_..."
      }
    }
  }
}
```

---

## Guarded Write Add-on (StoreGuard MCP for WooCommerce Pro)

The Free Core plugin includes clean WordPress extension hooks (`storeguard_mcp_registered_write_tools`, `storeguard_mcp_write_tool_definitions`, `storeguard_mcp_execute_write_tool`) and the `wp_storeguard_snapshots` rollback table.

For agencies and store operators who also want **guarded writes** (`update_product_meta_safe`, `bulk_stock_adjust_safe`, `create_draft_coupon_safe`) with mandatory `dry_run: true` diffs, `±25%` price-crash caps, 20-SKU batch limits, and **1-Click Rollback**, see [StoreGuard MCP for WooCommerce Pro](https://storeguard-mcp.kittenlabs-hq.workers.dev/#pricing).

## License

Licensed under the [GPLv2 or later](./LICENSE). Not affiliated with Automattic or WooCommerce.
