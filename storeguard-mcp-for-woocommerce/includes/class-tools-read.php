<?php
/**
 * 6 Free Core Read-Only Audit Tools for StoreGuard MCP.
 *
 * Strictly HPOS-compatible: uses wc_get_orders(), wc_get_products(), WC_Coupon,
 * and OrderUtil::custom_orders_table_usage_is_enabled(). Redacts customer PII.
 *
 * @package StoreGuard_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class StoreGuard_MCP_Tools_Read {

	public const TOOL_NAMES = array(
		'store_health_audit',
		'low_stock_report',
		'order_summary',
		'coupon_audit',
		'broken_sku_finder',
		'product_seo_audit',
	);

	/**
	 * MCP tool definitions with JSON schemas and readOnlyHint annotations.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_definitions(): array {
		$ro_annotations = array(
			'readOnlyHint'    => true,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		);

		return array(
			array(
				'name'        => 'store_health_audit',
				'description' => 'Free Read-Only: Inspect WooCommerce HPOS status, WordPress/PHP versions, catalog & order posture, and active StoreGuard MCP guardrail posture.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => new stdClass(),
					'additionalProperties' => false,
				),
				'annotations' => $ro_annotations,
			),
			array(
				'name'        => 'low_stock_report',
				'description' => 'Free Read-Only: List managed-stock products at or below threshold and out-of-stock SKUs.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'threshold'            => array(
							'type'        => 'integer',
							'description' => 'Low-stock threshold (default 5).',
							'minimum'     => 0,
							'maximum'     => 1000,
						),
						'include_out_of_stock' => array(
							'type'        => 'boolean',
							'description' => 'Include 0-stock products (default true).',
						),
						'limit'                => array(
							'type'        => 'integer',
							'description' => 'Maximum products to return (1-100, default 25).',
							'minimum'     => 1,
							'maximum'     => 100,
						),
					),
					'additionalProperties' => false,
				),
				'annotations' => $ro_annotations,
			),
			array(
				'name'        => 'order_summary',
				'description' => 'Free Read-Only (HPOS): Summarize orders, gross/net revenue, AOV, status breakdown, and payment gateways over N days with customer PII redacted.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'days'   => array(
							'type'        => 'integer',
							'description' => 'Lookback window in days (1-90, default 7).',
							'minimum'     => 1,
							'maximum'     => 90,
						),
						'status' => array(
							'type'        => 'string',
							'description' => 'Optional WooCommerce status filter (e.g., processing, completed, on-hold, refunded, all).',
						),
						'limit'  => array(
							'type'        => 'integer',
							'description' => 'Max orders scanned (1-200, default 50).',
							'minimum'     => 1,
							'maximum'     => 200,
						),
					),
					'additionalProperties' => false,
				),
				'annotations' => $ro_annotations,
			),
			array(
				'name'        => 'coupon_audit',
				'description' => 'Free Read-Only: Audit WooCommerce coupons and flag risky discounts (>40% off, unlimited usage, or missing expiry).',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'include_expired' => array(
							'type'        => 'boolean',
							'description' => 'Include expired coupons in audit (default true).',
						),
						'limit'           => array(
							'type'        => 'integer',
							'description' => 'Max coupons to inspect (1-100, default 50).',
							'minimum'     => 1,
							'maximum'     => 100,
						),
					),
					'additionalProperties' => false,
				),
				'annotations' => $ro_annotations,
			),
			array(
				'name'        => 'broken_sku_finder',
				'description' => 'Free Read-Only: Scan catalog via wc_get_products() for missing SKUs, duplicate SKUs, zero prices, inverted sale prices, and missing featured images.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'limit' => array(
							'type'        => 'integer',
							'description' => 'Max products to inspect (1-200, default 50).',
							'minimum'     => 1,
							'maximum'     => 200,
						),
					),
					'additionalProperties' => false,
				),
				'annotations' => $ro_annotations,
			),
			array(
				'name'        => 'product_seo_audit',
				'description' => 'Free Read-Only: Audit published products for SEO title/meta description coverage, short description length, image alt text, and clean slugs.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'limit'  => array(
							'type'        => 'integer',
							'description' => 'Max products to audit (1-100, default 25).',
							'minimum'     => 1,
							'maximum'     => 100,
						),
						'offset' => array(
							'type'        => 'integer',
							'description' => 'Pagination offset (default 0).',
							'minimum'     => 0,
						),
						'search' => array(
							'type'        => 'string',
							'description' => 'Optional product keyword search.',
						),
					),
					'additionalProperties' => false,
				),
				'annotations' => $ro_annotations,
			),
		);
	}

	/**
	 * Check whether tool name belongs to free read tools.
	 */
	public static function is_read_tool( string $name ): bool {
		return in_array( $name, self::TOOL_NAMES, true );
	}

	/**
	 * Execute a read-only audit tool.
	 *
	 * @param string               $name Tool name.
	 * @param array<string, mixed> $args Validated tool arguments.
	 * @return array<string, mixed>
	 */
	public static function execute( string $name, array $args ): array {
		switch ( $name ) {
			case 'store_health_audit':
				return self::store_health_audit();
			case 'low_stock_report':
				return self::low_stock_report( $args );
			case 'order_summary':
				return self::order_summary( $args );
			case 'coupon_audit':
				return self::coupon_audit( $args );
			case 'broken_sku_finder':
				return self::broken_sku_finder( $args );
			case 'product_seo_audit':
				return self::product_seo_audit( $args );
			default:
				return array(
					'error' => array(
						'code'    => 'STOREGUARD_UNKNOWN_TOOL',
						'message' => sprintf( 'Unknown read tool "%s".', $name ),
					),
				);
		}
	}

	/**
	 * Tool 1: store_health_audit
	 *
	 * @return array<string, mixed>
	 */
	private static function store_health_audit(): array {
		$hpos_enabled = false;
		if ( class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) ) {
			$hpos_enabled = (bool) \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}

		$published_count = count(
			wc_get_products(
				array(
					'status' => 'publish',
					'limit'  => 250,
					'return' => 'ids',
				)
			)
		);

		$recent_orders = wc_get_orders(
			array(
				'limit'        => 100,
				'date_created' => '>' . gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) ),
				'return'       => 'ids',
			)
		);

		$settings      = StoreGuard_MCP_Guardrails::get_settings();
		$tokens        = StoreGuard_MCP_Auth::get_tokens();
		$active_tokens = 0;
		foreach ( $tokens as $token ) {
			if ( empty( $token['revoked'] ) ) {
				++$active_tokens;
			}
		}

		return array(
			'tool'              => 'store_health_audit',
			'tier'              => 'free_read_only',
			'store'             => array(
				'site_url'            => home_url( '/' ),
				'wordpress_version'   => get_bloginfo( 'version' ),
				'woocommerce_version' => defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown',
				'php_version'         => PHP_VERSION,
				'hpos_enabled'        => $hpos_enabled,
				'currency'            => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
				'taxes_enabled'       => function_exists( 'wc_tax_enabled' ) ? wc_tax_enabled() : false,
			),
			'catalog_posture'   => array(
				'published_products_sampled' => $published_count,
				'orders_last_7_days_sampled' => is_array( $recent_orders ) ? count( $recent_orders ) : 0,
			),
			'guardrail_posture' => array(
				'safety_mode'         => $settings['mode'],
				'max_price_delta_pct' => $settings['max_price_delta_pct'],
				'max_batch_items'     => $settings['max_batch_items'],
				'allowed_write_tools' => $settings['allowed_write_tools'],
				'active_tokens'       => $active_tokens,
			),
		);
	}

	/**
	 * Tool 2: low_stock_report
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	private static function low_stock_report( array $args ): array {
		$threshold   = isset( $args['threshold'] ) ? max( 0, min( 1000, (int) $args['threshold'] ) ) : 5;
		$include_oos = isset( $args['include_out_of_stock'] ) ? (bool) $args['include_out_of_stock'] : true;
		$limit       = isset( $args['limit'] ) ? max( 1, min( 100, (int) $args['limit'] ) ) : 25;

		$products = wc_get_products(
			array(
				'status' => 'publish',
				'limit'  => 200,
				'return' => 'objects',
			)
		);

		$flagged = array();
		foreach ( $products as $product ) {
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$manages_stock = $product->get_manage_stock();
			$stock_qty     = $product->get_stock_quantity();
			$stock_status  = $product->get_stock_status();

			$is_oos = ( 'outofstock' === $stock_status ) || ( $manages_stock && null !== $stock_qty && $stock_qty <= 0 );
			$is_low = $manages_stock && null !== $stock_qty && $stock_qty > 0 && $stock_qty <= $threshold;

			if ( ( $include_oos && $is_oos ) || $is_low ) {
				$flagged[] = array(
					'product_id'     => $product->get_id(),
					'sku'            => $product->get_sku(),
					'name'           => $product->get_name(),
					'type'           => $product->get_type(),
					'stock_quantity' => $stock_qty,
					'stock_status'   => $stock_status,
					'regular_price'  => $product->get_regular_price(),
					'severity'       => $is_oos ? 'out_of_stock' : 'low_stock',
				);
			}

			if ( count( $flagged ) >= $limit ) {
				break;
			}
		}

		return array(
			'tool'        => 'low_stock_report',
			'tier'        => 'free_read_only',
			'threshold'   => $threshold,
			'total_found' => count( $flagged ),
			'items'       => $flagged,
		);
	}

	/**
	 * Tool 3: order_summary (HPOS-safe + PII-redacted)
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	private static function order_summary( array $args ): array {
		$days   = isset( $args['days'] ) ? max( 1, min( 90, (int) $args['days'] ) ) : 7;
		$limit  = isset( $args['limit'] ) ? max( 1, min( 200, (int) $args['limit'] ) ) : 50;
		$status = isset( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : 'all';

		$query_args = array(
			'limit'        => $limit,
			'date_created' => '>' . gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) ),
			'orderby'      => 'date',
			'order'        => 'DESC',
			'return'       => 'objects',
		);
		if ( '' !== $status && 'all' !== $status ) {
			$query_args['status'] = $status;
		}

		$orders        = wc_get_orders( $query_args );
		$gross_revenue = 0.0;
		$refund_total  = 0.0;
		$by_status     = array();
		$by_gateway    = array();
		$order_rows    = array();

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$total   = (float) $order->get_total();
			$refund  = (float) $order->get_total_refunded();
			$st      = $order->get_status();
			$gateway = $order->get_payment_method() ?: 'none';

			$gross_revenue         += $total;
			$refund_total          += $refund;
			$by_status[ $st ]       = ( $by_status[ $st ] ?? 0 ) + 1;
			$by_gateway[ $gateway ] = ( $by_gateway[ $gateway ] ?? 0 ) + 1;

			$order_rows[] = array(
				'order_id'    => $order->get_id(),
				'status'      => $st,
				'currency'    => $order->get_currency(),
				'total'       => round( $total, 2 ),
				'refunded'    => round( $refund, 2 ),
				'item_count'  => $order->get_item_count(),
				'gateway'     => $gateway,
				'created_gmt' => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
			);
		}

		$count = count( $order_rows );
		$aov   = $count > 0 ? round( $gross_revenue / $count, 2 ) : 0.0;

		return array(
			'tool'          => 'order_summary',
			'tier'          => 'free_read_only',
			'pii_redacted'  => true,
			'window_days'   => $days,
			'order_count'   => $count,
			'gross_revenue' => round( $gross_revenue, 2 ),
			'refund_total'  => round( $refund_total, 2 ),
			'net_revenue'   => round( $gross_revenue - $refund_total, 2 ),
			'aov'           => $aov,
			'by_status'     => $by_status,
			'by_gateway'    => $by_gateway,
			'recent_orders' => array_slice( $order_rows, 0, 20 ),
		);
	}

	/**
	 * Tool 4: coupon_audit
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	private static function coupon_audit( array $args ): array {
		$include_expired = isset( $args['include_expired'] ) ? (bool) $args['include_expired'] : true;
		$limit           = isset( $args['limit'] ) ? max( 1, min( 100, (int) $args['limit'] ) ) : 50;

		$coupon_ids = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'posts_per_page' => $limit,
				'fields'         => 'ids',
			)
		);

		$items      = array();
		$risk_count = 0;
		$now        = time();

		foreach ( $coupon_ids as $coupon_id ) {
			$coupon     = new WC_Coupon( $coupon_id );
			$expiry     = $coupon->get_date_expires();
			$is_expired = ( $expiry && $expiry->getTimestamp() < $now );

			if ( ! $include_expired && $is_expired ) {
				continue;
			}

			$amount        = (float) $coupon->get_amount();
			$discount_type = $coupon->get_discount_type();
			$usage_limit   = $coupon->get_usage_limit();
			$status        = get_post_status( $coupon_id );
			$flags         = array();

			if ( 'percent' === $discount_type && $amount > 40 ) {
				$flags[] = 'high_percent_discount';
			}
			if ( 0 === (int) $usage_limit && 'publish' === $status ) {
				$flags[] = 'unlimited_usage';
			}
			if ( ! $expiry && 'publish' === $status ) {
				$flags[] = 'no_expiration_date';
			}
			if ( $is_expired && 'publish' === $status ) {
				$flags[] = 'expired_still_published';
			}

			if ( ! empty( $flags ) ) {
				++$risk_count;
			}

			$items[] = array(
				'coupon_id'     => $coupon_id,
				'code'          => $coupon->get_code(),
				'status'        => $status,
				'discount_type' => $discount_type,
				'amount'        => $amount,
				'usage_count'   => $coupon->get_usage_count(),
				'usage_limit'   => $usage_limit,
				'expires_gmt'   => $expiry ? $expiry->date( 'Y-m-d' ) : null,
				'risk_flags'    => $flags,
			);
		}

		return array(
			'tool'          => 'coupon_audit',
			'tier'          => 'free_read_only',
			'total_audited' => count( $items ),
			'flagged_risks' => $risk_count,
			'coupons'       => $items,
		);
	}

	/**
	 * Tool 5: broken_sku_finder
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	private static function broken_sku_finder( array $args ): array {
		$limit = isset( $args['limit'] ) ? max( 1, min( 200, (int) $args['limit'] ) ) : 50;

		$products = wc_get_products(
			array(
				'status' => array( 'publish', 'draft' ),
				'limit'  => $limit,
				'return' => 'objects',
			)
		);

		$sku_map = array();
		$issues  = array();

		foreach ( $products as $product ) {
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$sku = trim( (string) $product->get_sku() );
			if ( '' !== $sku ) {
				$sku_map[ $sku ][] = $product->get_id();
			}
		}

		foreach ( $products as $product ) {
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$pid           = $product->get_id();
			$sku           = trim( (string) $product->get_sku() );
			$regular_price = $product->get_regular_price();
			$sale_price    = $product->get_sale_price();
			$flags         = array();

			if ( '' === $sku ) {
				$flags[] = 'missing_sku';
			} elseif ( isset( $sku_map[ $sku ] ) && count( $sku_map[ $sku ] ) > 1 ) {
				$flags[] = 'duplicate_sku';
			}

			if ( 'publish' === $product->get_status() && ( '' === $regular_price || (float) $regular_price <= 0 ) ) {
				$flags[] = 'missing_or_zero_price';
			}

			if ( '' !== $sale_price && '' !== $regular_price && (float) $sale_price >= (float) $regular_price ) {
				$flags[] = 'sale_price_gte_regular_price';
			}

			if ( ! $product->get_image_id() ) {
				$flags[] = 'missing_featured_image';
			}

			if ( ! empty( $flags ) ) {
				$issues[] = array(
					'product_id'    => $pid,
					'name'          => $product->get_name(),
					'sku'           => $sku,
					'status'        => $product->get_status(),
					'regular_price' => $regular_price,
					'sale_price'    => $sale_price,
					'issues'        => $flags,
				);
			}
		}

		return array(
			'tool'             => 'broken_sku_finder',
			'tier'             => 'free_read_only',
			'products_scanned' => count( $products ),
			'issues_found'     => count( $issues ),
			'items'            => $issues,
		);
	}

	/**
	 * Tool 6: product_seo_audit
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	private static function product_seo_audit( array $args ): array {
		$limit  = isset( $args['limit'] ) ? max( 1, min( 100, (int) $args['limit'] ) ) : 25;
		$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;
		$search = isset( $args['search'] ) ? sanitize_text_field( (string) $args['search'] ) : '';

		$query_args = array(
			'status' => 'publish',
			'limit'  => $limit,
			'offset' => $offset,
			'return' => 'objects',
		);
		if ( '' !== $search ) {
			$query_args['s'] = $search;
		}

		$products = wc_get_products( $query_args );
		$results  = array();

		foreach ( $products as $product ) {
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$pid       = $product->get_id();
			$title     = $product->get_name();
			$short     = wp_strip_all_tags( (string) $product->get_short_description() );
			$seo_title = (string) (
				$product->get_meta( '_yoast_wpseo_title' )
				?: $product->get_meta( 'rank_math_title' )
				?: $product->get_meta( '_storeguard_seo_title' )
			);
			$seo_desc  = (string) (
				$product->get_meta( '_yoast_wpseo_metadesc' )
				?: $product->get_meta( 'rank_math_description' )
				?: $product->get_meta( '_storeguard_seo_desc' )
			);

			$image_id  = $product->get_image_id();
			$image_alt = $image_id ? (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) : '';

			$warnings = array();
			if ( strlen( $title ) < 15 ) {
				$warnings[] = 'title_too_short';
			}
			if ( '' === $seo_title ) {
				$warnings[] = 'missing_seo_title';
			}
			if ( '' === $seo_desc ) {
				$warnings[] = 'missing_meta_description';
			}
			if ( strlen( $short ) < 40 ) {
				$warnings[] = 'short_description_thin';
			}
			if ( $image_id && '' === trim( $image_alt ) ) {
				$warnings[] = 'missing_image_alt';
			}

			$score = max( 0, 100 - ( count( $warnings ) * 20 ) );

			$results[] = array(
				'product_id'        => $pid,
				'sku'               => $product->get_sku(),
				'name'              => $title,
				'slug'              => $product->get_slug(),
				'regular_price'     => $product->get_regular_price(),
				'seo_title'         => $seo_title,
				'seo_description'   => $seo_desc,
				'short_desc_length' => strlen( $short ),
				'seo_score'         => $score,
				'warnings'          => $warnings,
			);
		}

		return array(
			'tool'             => 'product_seo_audit',
			'tier'             => 'free_read_only',
			'products_audited' => count( $results ),
			'items'            => $results,
		);
	}
}
