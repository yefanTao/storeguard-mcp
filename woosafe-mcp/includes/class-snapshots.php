<?php
/**
 * Pre-write JSON Snapshot Storage, Audit Log, and 1-Click Rollback Engine.
 *
 * Uses WooCommerce CRUD APIs (wc_get_product, WC_Coupon) so rollbacks work identically
 * under both HPOS (custom_order_tables) and legacy storage without raw postmeta SQL writes.
 *
 * @package WooSafe_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WooSafe_MCP_Snapshots {

	/**
	 * Snapshots table name.
	 */
	public static function table_snapshots(): string {
		global $wpdb;
		return $wpdb->prefix . 'woosafe_snapshots';
	}

	/**
	 * Audit log table name.
	 */
	public static function table_audit_log(): string {
		global $wpdb;
		return $wpdb->prefix . 'woosafe_audit_log';
	}

	/**
	 * Create database tables via dbDelta on plugin activation.
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();

		$snapshots_table = self::table_snapshots();
		$audit_table     = self::table_audit_log();

		$sql_snapshots = "CREATE TABLE {$snapshots_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			snapshot_uuid VARCHAR(36) NOT NULL,
			tool_name VARCHAR(80) NOT NULL,
			target_type VARCHAR(32) NOT NULL,
			target_ids LONGTEXT NOT NULL,
			before_state LONGTEXT NOT NULL,
			after_state LONGTEXT NOT NULL,
			summary VARCHAR(255) NOT NULL DEFAULT '',
			token_id VARCHAR(32) NOT NULL DEFAULT '',
			status VARCHAR(24) NOT NULL DEFAULT 'committed',
			created_at DATETIME NOT NULL,
			rolled_back_at DATETIME NULL,
			rolled_back_by BIGINT(20) UNSIGNED NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY snapshot_uuid (snapshot_uuid),
			KEY status_created (status, created_at)
		) {$charset_collate};";

		$sql_audit = "CREATE TABLE {$audit_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			request_id VARCHAR(64) NOT NULL DEFAULT '',
			token_id VARCHAR(32) NOT NULL DEFAULT '',
			token_scope VARCHAR(20) NOT NULL DEFAULT '',
			tool_name VARCHAR(80) NOT NULL,
			mode VARCHAR(20) NOT NULL,
			decision VARCHAR(20) NOT NULL,
			reason VARCHAR(255) NOT NULL DEFAULT '',
			summary TEXT NOT NULL,
			snapshot_uuid VARCHAR(36) NULL,
			duration_ms INT(11) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY tool_created (tool_name, created_at),
			KEY decision_created (decision, created_at)
		) {$charset_collate};";

		dbDelta( $sql_snapshots );
		dbDelta( $sql_audit );
	}

	/**
	 * Persist a pre-write / post-write snapshot pair.
	 *
	 * @param array<string, mixed> $data Snapshot payload.
	 * @return string Generated snapshot UUID.
	 */
	public static function record_snapshot( array $data ): string {
		global $wpdb;

		$uuid = wp_generate_uuid4();
		$row  = array(
			'snapshot_uuid' => $uuid,
			'tool_name'     => sanitize_text_field( (string) ( $data['tool_name'] ?? '' ) ),
			'target_type'   => sanitize_text_field( (string) ( $data['target_type'] ?? 'product' ) ),
			'target_ids'    => wp_json_encode( $data['target_ids'] ?? array() ),
			'before_state'  => wp_json_encode( $data['before_state'] ?? array() ),
			'after_state'   => wp_json_encode( $data['after_state'] ?? array() ),
			'summary'       => sanitize_text_field( (string) ( $data['summary'] ?? '' ) ),
			'token_id'      => sanitize_key( (string) ( $data['token_id'] ?? '' ) ),
			'status'        => 'committed',
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			self::table_snapshots(),
			$row,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $uuid;
	}

	/**
	 * Append an entry to the persistent audit log.
	 *
	 * @param array<string, mixed> $entry Log entry fields.
	 */
	public static function log_audit( array $entry ): void {
		global $wpdb;

		$row = array(
			'request_id'    => sanitize_text_field( (string) ( $entry['request_id'] ?? '' ) ),
			'token_id'      => sanitize_key( (string) ( $entry['token_id'] ?? '' ) ),
			'token_scope'   => sanitize_key( (string) ( $entry['token_scope'] ?? '' ) ),
			'tool_name'     => sanitize_text_field( (string) ( $entry['tool_name'] ?? '' ) ),
			'mode'          => sanitize_key( (string) ( $entry['mode'] ?? 'read' ) ),
			'decision'      => sanitize_key( (string) ( $entry['decision'] ?? 'allowed' ) ),
			'reason'        => sanitize_text_field( (string) ( $entry['reason'] ?? '' ) ),
			'summary'       => sanitize_textarea_field( (string) ( $entry['summary'] ?? '' ) ),
			'snapshot_uuid' => isset( $entry['snapshot_uuid'] ) ? sanitize_text_field( (string) $entry['snapshot_uuid'] ) : null,
			'duration_ms'   => absint( $entry['duration_ms'] ?? 0 ),
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			self::table_audit_log(),
			$row,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Fetch recent snapshots for WP Admin rollback UI.
	 *
	 * @param int $limit Max rows.
	 * @return array<int, object>
	 */
	public static function get_recent_snapshots( int $limit = 25 ): array {
		global $wpdb;
		$limit = max( 1, min( 100, $limit ) );
		$table = self::table_snapshots();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Fetch recent audit log rows for WP Admin inspection.
	 *
	 * @param int $limit Max rows.
	 * @return array<int, object>
	 */
	public static function get_recent_audit_logs( int $limit = 50 ): array {
		global $wpdb;
		$limit = max( 1, min( 200, $limit ) );
		$table = self::table_audit_log();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Restore a committed snapshot to its exact pre-write state.
	 *
	 * @param string $snapshot_uuid Snapshot UUID.
	 * @param int    $user_id       Acting admin user ID.
	 * @return array<string, mixed>
	 */
	public static function rollback_snapshot( string $snapshot_uuid, int $user_id = 0 ): array {
		global $wpdb;

		$snapshot_uuid = sanitize_text_field( $snapshot_uuid );
		$table         = self::table_snapshots();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$snapshot = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE snapshot_uuid = %s LIMIT 1", $snapshot_uuid ) );

		if ( ! $snapshot ) {
			return array(
				'ok'      => false,
				'message' => 'Snapshot not found.',
			);
		}

		if ( 'committed' !== $snapshot->status ) {
			return array(
				'ok'      => false,
				'message' => sprintf( 'Snapshot status is "%s" and cannot be rolled back again.', $snapshot->status ),
			);
		}

		$before_state = json_decode( (string) $snapshot->before_state, true );
		if ( ! is_array( $before_state ) ) {
			return array(
				'ok'      => false,
				'message' => 'Corrupted snapshot before_state payload.',
			);
		}

		$restored = array();
		if ( 'coupon' === $snapshot->target_type ) {
			$restored = self::restore_coupon_state( $before_state );
		} else {
			$restored = self::restore_products_state( $before_state );
		}

		if ( empty( $restored['ok'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array( 'status' => 'rollback_failed' ),
				array( 'snapshot_uuid' => $snapshot_uuid ),
				array( '%s' ),
				array( '%s' )
			);
			return $restored;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'status'         => 'rolled_back',
				'rolled_back_at' => gmdate( 'Y-m-d H:i:s' ),
				'rolled_back_by' => absint( $user_id ),
			),
			array( 'snapshot_uuid' => $snapshot_uuid ),
			array( '%s', '%s', '%d' ),
			array( '%s' )
		);

		self::log_audit(
			array(
				'request_id'    => 'admin-rollback-' . wp_generate_uuid4(),
				'token_id'      => 'wp_admin_' . absint( $user_id ),
				'token_scope'   => 'admin',
				'tool_name'     => (string) $snapshot->tool_name,
				'mode'          => 'rollback',
				'decision'      => 'allowed',
				'reason'        => 'Admin triggered 1-click snapshot rollback.',
				'summary'       => (string) ( $restored['summary'] ?? 'Rolled back snapshot ' . $snapshot_uuid ),
				'snapshot_uuid' => $snapshot_uuid,
				'duration_ms'   => 0,
			)
		);

		return array(
			'ok'             => true,
			'snapshot_uuid'  => $snapshot_uuid,
			'restored_count' => $restored['count'] ?? 1,
			'summary'        => $restored['summary'] ?? 'Snapshot rolled back cleanly.',
		);
	}

	/**
	 * Restore one or more WooCommerce products from snapshot records.
	 *
	 * @param array<int, array<string, mixed>> $items Product snapshot list.
	 * @return array<string, mixed>
	 */
	private static function restore_products_state( array $items ): array {
		$count = 0;

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['product_id'] ) ) {
				continue;
			}

			$product = wc_get_product( absint( $item['product_id'] ) );
			if ( ! $product ) {
				return array(
					'ok'      => false,
					'message' => sprintf( 'Product #%d no longer exists.', absint( $item['product_id'] ) ),
				);
			}

			if ( array_key_exists( 'regular_price', $item ) ) {
				$product->set_regular_price( (string) $item['regular_price'] );
			}
			if ( array_key_exists( 'sale_price', $item ) ) {
				$product->set_sale_price( (string) $item['sale_price'] );
			}
			if ( array_key_exists( 'sku', $item ) ) {
				$product->set_sku( (string) $item['sku'] );
			}
			if ( array_key_exists( 'short_description', $item ) ) {
				$product->set_short_description( (string) $item['short_description'] );
			}
			if ( array_key_exists( 'status', $item ) ) {
				$product->set_status( (string) $item['status'] );
			}
			if ( array_key_exists( 'manage_stock', $item ) ) {
				$product->set_manage_stock( (bool) $item['manage_stock'] );
			}
			if ( array_key_exists( 'stock_quantity', $item ) ) {
				$product->set_stock_quantity( null === $item['stock_quantity'] ? null : (int) $item['stock_quantity'] );
			}
			if ( array_key_exists( 'stock_status', $item ) ) {
				$product->set_stock_status( (string) $item['stock_status'] );
			}

			if ( isset( $item['seo_meta'] ) && is_array( $item['seo_meta'] ) ) {
				foreach ( $item['seo_meta'] as $meta_key => $meta_val ) {
					if ( '' === (string) $meta_val ) {
						$product->delete_meta_data( (string) $meta_key );
					} else {
						$product->update_meta_data( (string) $meta_key, (string) $meta_val );
					}
				}
			}

			$product->save();
			++$count;
		}

		return array(
			'ok'      => true,
			'count'   => $count,
			'summary' => sprintf( 'Restored %d product(s) to pre-write snapshot state.', $count ),
		);
	}

	/**
	 * Restore or trash a coupon created by create_draft_coupon_safe.
	 *
	 * @param array<string, mixed> $state Snapshot state.
	 * @return array<string, mixed>
	 */
	private static function restore_coupon_state( array $state ): array {
		$coupon_id = absint( $state['coupon_id'] ?? 0 );
		if ( 0 === $coupon_id ) {
			return array(
				'ok'      => false,
				'message' => 'Missing coupon_id in snapshot.',
			);
		}

		if ( 'created' === ( $state['action'] ?? '' ) ) {
			$coupon = new WC_Coupon( $coupon_id );
			if ( $coupon->get_id() > 0 ) {
				$coupon->delete( false );
			}
			return array(
				'ok'      => true,
				'count'   => 1,
				'summary' => sprintf( 'Moved AI-created draft coupon #%d (%s) to trash.', $coupon_id, (string) ( $state['code'] ?? '' ) ),
			);
		}

		return array(
			'ok'      => false,
			'message' => 'Unsupported coupon rollback action.',
		);
	}
}
