<?php
/**
 * Safety Guardrail Engine for WooSafe MCP.
 *
 * Enforces:
 * - Default Read-Only Lock (all writes hard-disabled until explicitly enabled)
 * - Per-Tool Write Permission Matrix
 * - Mandatory Dry-Run default (dry_run: true)
 * - Hard Price Delta Percentage Cap (default 25%)
 * - Hard Batch Item Count Cap (default 20)
 * - Coupon Discount Ceilings & Draft-Only Enforcement
 *
 * @package WooSafe_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WooSafe_MCP_Guardrails {

	public const OPTION_SETTINGS = 'woosafe_mcp_settings';
	public const MODE_READ_ONLY  = 'read_only';
	public const MODE_GUARDED    = 'guarded_write';

	public const WRITE_TOOLS = array(
		'update_product_meta_safe',
		'bulk_stock_adjust_safe',
		'create_draft_coupon_safe',
	);

	/**
	 * Return write tools registered by optional add-ons (empty in Read-Only Core).
	 *
	 * @return array<int, string>
	 */
	public static function get_registered_write_tools(): array {
		$tools = apply_filters( 'woosafe_mcp_registered_write_tools', array() );
		if ( ! is_array( $tools ) ) {
			return array();
		}
		return array_values( array_unique( array_map( 'sanitize_key', $tools ) ) );
	}

	/**
	 * Default safe settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'mode'                => self::MODE_READ_ONLY,
			'max_price_delta_pct' => 25.0,
			'max_batch_items'     => 20,
			'max_coupon_percent'  => 50.0,
			'max_coupon_fixed'    => 500.0,
			'allowed_write_tools' => array(
				'update_product_meta_safe' => false,
				'bulk_stock_adjust_safe'   => false,
				'create_draft_coupon_safe' => false,
			),
		);
	}

	/**
	 * Ensure safe default options exist on activation.
	 */
	public static function ensure_defaults(): void {
		if ( false === get_option( self::OPTION_SETTINGS, false ) ) {
			add_option( self::OPTION_SETTINGS, self::defaults(), '', false );
		}
	}

	/**
	 * Retrieve sanitized settings merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_settings(): array {
		$defaults = self::defaults();
		$saved    = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $saved ) ) {
			return $defaults;
		}

		$merged                        = array_merge( $defaults, $saved );
		$merged['mode']                = ( self::MODE_GUARDED === ( $merged['mode'] ?? '' ) ) ? self::MODE_GUARDED : self::MODE_READ_ONLY;
		$merged['max_price_delta_pct'] = self::clamp_float( (float) $merged['max_price_delta_pct'], 1.0, 100.0 );
		$merged['max_batch_items']     = max( 1, min( 100, (int) $merged['max_batch_items'] ) );
		$merged['max_coupon_percent']  = self::clamp_float( (float) $merged['max_coupon_percent'], 1.0, 100.0 );
		$merged['max_coupon_fixed']    = self::clamp_float( (float) $merged['max_coupon_fixed'], 1.0, 5000.0 );

		$tools = array();
		foreach ( array_unique( array_merge( self::WRITE_TOOLS, self::get_registered_write_tools() ) ) as $tool_name ) {
			$tools[ $tool_name ] = ! empty( $saved['allowed_write_tools'][ $tool_name ] );
		}
		$merged['allowed_write_tools'] = $tools;

		return $merged;
	}

	/**
	 * Persist updated guardrail settings from WP Admin.
	 *
	 * @param array<string, mixed> $input Raw admin form values.
	 * @return array<string, mixed> Sanitized saved settings.
	 */
	public static function update_settings( array $input ): array {
		$mode = ( isset( $input['mode'] ) && self::MODE_GUARDED === $input['mode'] )
			? self::MODE_GUARDED
			: self::MODE_READ_ONLY;

		$tools     = array();
		$raw_tools = isset( $input['allowed_write_tools'] ) && is_array( $input['allowed_write_tools'] )
			? $input['allowed_write_tools']
			: array();

		foreach ( array_unique( array_merge( self::WRITE_TOOLS, self::get_registered_write_tools() ) ) as $tool_name ) {
			$tools[ $tool_name ] = ! empty( $raw_tools[ $tool_name ] );
		}

		$clean = array(
			'mode'                => $mode,
			'max_price_delta_pct' => self::clamp_float( (float) ( $input['max_price_delta_pct'] ?? 25.0 ), 1.0, 100.0 ),
			'max_batch_items'     => max( 1, min( 100, (int) ( $input['max_batch_items'] ?? 20 ) ) ),
			'max_coupon_percent'  => self::clamp_float( (float) ( $input['max_coupon_percent'] ?? 50.0 ), 1.0, 100.0 ),
			'max_coupon_fixed'    => self::clamp_float( (float) ( $input['max_coupon_fixed'] ?? 500.0 ), 1.0, 5000.0 ),
			'allowed_write_tools' => $tools,
		);

		update_option( self::OPTION_SETTINGS, $clean, false );
		return $clean;
	}

	/**
	 * Evaluate whether an incoming MCP tool call is permitted by mode, token scope, and matrix.
	 *
	 * @param string               $tool_name    MCP tool identifier.
	 * @param bool                 $is_write     Whether tool can mutate store data.
	 * @param array<string, mixed> $args         Tool arguments.
	 * @param array<string, mixed> $auth_context Authenticated token context.
	 * @return array<string, mixed>
	 */
	public static function authorize_tool( string $tool_name, bool $is_write, array $args, array $auth_context ): array {
		if ( ! $is_write ) {
			return array(
				'allowed' => true,
				'dry_run' => false,
				'mode'    => 'read',
			);
		}

		$scope = (string) ( $auth_context['scope'] ?? WooSafe_MCP_Auth::SCOPE_READ_ONLY );
		if ( WooSafe_MCP_Auth::SCOPE_READ_WRITE !== $scope ) {
			return array(
				'allowed'     => false,
				'code'        => 'WOOSAFE_SCOPE_READ_ONLY',
				'message'     => sprintf( 'Blocked "%s": your Bearer token is scoped to read_only.', $tool_name ),
				'remediation' => 'Generate a Guarded Read-Write token (wsmcp_rw_) in WooCommerce > WooSafe MCP.',
			);
		}

		$settings = self::get_settings();
		if ( self::MODE_GUARDED !== $settings['mode'] ) {
			return array(
				'allowed'     => false,
				'code'        => 'WOOSAFE_MODE_READ_ONLY',
				'message'     => sprintf( 'Blocked "%s": WooSafe MCP is locked in Read-Only Audit Mode (default).', $tool_name ),
				'remediation' => 'Switch Safety Mode to "Guarded Write Mode" and toggle this tool in WooCommerce > WooSafe MCP.',
			);
		}

		if ( empty( $settings['allowed_write_tools'][ $tool_name ] ) ) {
			return array(
				'allowed'     => false,
				'code'        => 'WOOSAFE_TOOL_DISABLED',
				'message'     => sprintf( 'Blocked "%s": tool is disabled in the Per-Tool Permission Matrix.', $tool_name ),
				'remediation' => sprintf( 'Enable "%s" in WooCommerce > WooSafe MCP > Permission Matrix.', $tool_name ),
			);
		}

		// Mandatory Dry-Run default: unless caller explicitly passes dry_run: false, preview only.
		$dry_run = true;
		if ( array_key_exists( 'dry_run', $args ) ) {
			$dry_run = filter_var( $args['dry_run'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			if ( null === $dry_run ) {
				$dry_run = true;
			}
		}

		return array(
			'allowed'  => true,
			'dry_run'  => $dry_run,
			'mode'     => $dry_run ? 'dry_run' : 'write',
			'settings' => $settings,
		);
	}

	/**
	 * Enforce hard price change percentage ceiling.
	 *
	 * @param float  $old_price     Current stored price.
	 * @param float  $new_price     Proposed price.
	 * @param float  $max_delta_pct Configured max percentage delta.
	 * @param string $field_label   Field name for error details.
	 * @return array<string, mixed>|null Guardrail error payload or null if within cap.
	 */
	public static function validate_price_change( float $old_price, float $new_price, float $max_delta_pct, string $field_label = 'regular_price' ): ?array {
		if ( $new_price <= 0 ) {
			return array(
				'code'          => 'WOOSAFE_INVALID_PRICE',
				'field'         => $field_label,
				'old_price'     => $old_price,
				'new_price'     => $new_price,
				'max_delta_pct' => $max_delta_pct,
				'message'       => sprintf( 'Guardrail rejected %s=%.2f: price must be greater than 0.00.', $field_label, $new_price ),
			);
		}

		if ( $old_price > 0 ) {
			$delta_pct = round( ( ( $new_price - $old_price ) / $old_price ) * 100, 2 );
			if ( abs( $delta_pct ) > $max_delta_pct ) {
				return array(
					'code'          => 'WOOSAFE_PRICE_CAP_EXCEEDED',
					'field'         => $field_label,
					'old_price'     => $old_price,
					'new_price'     => $new_price,
					'delta_pct'     => $delta_pct,
					'max_delta_pct' => $max_delta_pct,
					'message'       => sprintf(
						'Guardrail blocked %s change from %.2f to %.2f (%.2f%% delta exceeds hard cap of +/-%.1f%%).',
						$field_label,
						$old_price,
						$new_price,
						$delta_pct,
						$max_delta_pct
					),
				);
			}
		}

		return null;
	}

	/**
	 * Enforce hard batch item count ceiling.
	 *
	 * @param int $count           Number of items in request.
	 * @param int $max_batch_items Configured max items per call.
	 * @return array<string, mixed>|null
	 */
	public static function validate_batch_count( int $count, int $max_batch_items ): ?array {
		if ( $count <= 0 ) {
			return array(
				'code'    => 'WOOSAFE_EMPTY_BATCH',
				'message' => 'Batch adjustments array must include at least 1 product.',
			);
		}

		if ( $count > $max_batch_items ) {
			return array(
				'code'            => 'WOOSAFE_BATCH_CAP_EXCEEDED',
				'requested_count' => $count,
				'max_batch_items' => $max_batch_items,
				'message'         => sprintf(
					'Guardrail blocked batch touching %d products (exceeds hard cap of %d products per AI call).',
					$count,
					$max_batch_items
				),
			);
		}

		return null;
	}

	/**
	 * Enforce coupon discount caps.
	 *
	 * @param string               $discount_type Coupon type.
	 * @param float                $amount        Discount amount.
	 * @param array<string, mixed> $settings      Guardrail settings.
	 * @return array<string, mixed>|null
	 */
	public static function validate_coupon_amount( string $discount_type, float $amount, array $settings ): ?array {
		if ( $amount <= 0 ) {
			return array(
				'code'    => 'WOOSAFE_INVALID_COUPON_AMOUNT',
				'message' => 'Coupon discount amount must be greater than 0.',
			);
		}

		if ( 'percent' === $discount_type && $amount > (float) $settings['max_coupon_percent'] ) {
			return array(
				'code'               => 'WOOSAFE_COUPON_CAP_EXCEEDED',
				'discount_type'      => $discount_type,
				'amount'             => $amount,
				'max_coupon_percent' => (float) $settings['max_coupon_percent'],
				'message'            => sprintf(
					'Guardrail blocked %.1f%% coupon (exceeds hard cap of %.1f%%).',
					$amount,
					(float) $settings['max_coupon_percent']
				),
			);
		}

		if ( 'percent' !== $discount_type && $amount > (float) $settings['max_coupon_fixed'] ) {
			return array(
				'code'             => 'WOOSAFE_COUPON_CAP_EXCEEDED',
				'discount_type'    => $discount_type,
				'amount'           => $amount,
				'max_coupon_fixed' => (float) $settings['max_coupon_fixed'],
				'message'          => sprintf(
					'Guardrail blocked $%.2f fixed coupon (exceeds hard cap of $%.2f).',
					$amount,
					(float) $settings['max_coupon_fixed']
				),
			);
		}

		return null;
	}

	/**
	 * Clamp float to closed [min, max] range.
	 */
	private static function clamp_float( float $value, float $min, float $max ): float {
		return max( $min, min( $max, $value ) );
	}
}
