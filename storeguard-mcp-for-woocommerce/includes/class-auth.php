<?php
/**
 * Scoped Bearer API Token Authentication for StoreGuard MCP.
 *
 * Tokens use format: sgmcp_<ro|rw>_<12-hex-id>_<40-hex-secret>
 * Secrets are hashed with wp_hash_password() and IDs/prefixes verified via hash_equals().
 * Raw WordPress passwords or Application Passwords are never requested or stored.
 *
 * @package StoreGuard_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class StoreGuard_MCP_Auth {

	public const OPTION_TOKENS = 'storeguard_mcp_tokens';
	public const SCOPE_READ_ONLY = 'read_only';
	public const SCOPE_READ_WRITE = 'read_write';

	private const TOKEN_REGEX = '/^(sgmcp_ro|sgmcp_rw)_([a-f0-9]{12})_([a-f0-9]{40})$/';

	/**
	 * Fetch all stored token records keyed by token ID.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_tokens(): array {
		$tokens = get_option( self::OPTION_TOKENS, array() );
		return is_array( $tokens ) ? $tokens : array();
	}

	/**
	 * Generate a new scoped Bearer token and persist only its wp_hash_password() hash.
	 *
	 * @param string $label Human-readable label (e.g., "Claude Desktop").
	 * @param string $scope Token scope ("read_only" or "read_write").
	 * @return array{token: string, record: array<string, mixed>}
	 */
	public static function generate_token( string $label, string $scope = self::SCOPE_READ_ONLY ): array {
		$scope = ( self::SCOPE_READ_WRITE === $scope ) ? self::SCOPE_READ_WRITE : self::SCOPE_READ_ONLY;
		$prefix = ( self::SCOPE_READ_WRITE === $scope ) ? 'sgmcp_rw' : 'sgmcp_ro';

		$clean_label = sanitize_text_field( $label );
		if ( '' === $clean_label ) {
			$clean_label = ( self::SCOPE_READ_WRITE === $scope ) ? 'Guarded Read-Write Client' : 'Read-Only Audit Client';
		}

		$id = bin2hex( random_bytes( 6 ) );
		$secret = bin2hex( random_bytes( 20 ) );
		$raw_token = sprintf( '%s_%s_%s', $prefix, $id, $secret );
		$masked = sprintf( '%s_%s_****************%s', $prefix, $id, substr( $secret, -4 ) );

		$record = array(
			'id'           => $id,
			'prefix'       => $prefix,
			'scope'        => $scope,
			'label'        => $clean_label,
			'masked'       => $masked,
			'secret_hash'  => wp_hash_password( $raw_token ),
			'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			'created_by'   => get_current_user_id(),
			'last_used_at' => null,
			'revoked'      => false,
			'revoked_at'   => null,
		);

		$tokens        = self::get_tokens();
		$tokens[ $id ] = $record;
		update_option( self::OPTION_TOKENS, $tokens, false );

		return array(
			'token'  => $raw_token,
			'record' => $record,
		);
	}

	/**
	 * Revoke an existing token by ID.
	 *
	 * @param string $token_id Token 12-hex identifier.
	 * @return bool
	 */
	public static function revoke_token( string $token_id ): bool {
		$token_id = sanitize_key( $token_id );
		$tokens   = self::get_tokens();
		if ( ! isset( $tokens[ $token_id ] ) ) {
			return false;
		}

		$tokens[ $token_id ]['revoked']    = true;
		$tokens[ $token_id ]['revoked_at'] = gmdate( 'Y-m-d H:i:s' );
		return update_option( self::OPTION_TOKENS, $tokens, false );
	}

	/**
	 * Extract Bearer token from REST request or server variables (Apache/Nginx/CGI compatible).
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return string|null
	 */
	public static function extract_bearer_token( WP_REST_Request $request ): ?string {
		$candidates = array(
			$request->get_header( 'authorization' ),
			isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) ) : '',
			isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) : '',
		);

		foreach ( $candidates as $header ) {
			if ( is_string( $header ) && preg_match( '/^\s*Bearer\s+([^\s]+)\s*$/i', $header, $matches ) ) {
				return trim( $matches[1] );
			}
		}

		return null;
	}

	/**
	 * Verify incoming raw token in timing-safe manner.
	 *
	 * @param string $raw_token Incoming Bearer token.
	 * @return array<string, mixed>|null Authenticated token context or null.
	 */
	public static function verify_token( string $raw_token ): ?array {
		$raw_token = trim( $raw_token );
		if ( 1 !== preg_match( self::TOKEN_REGEX, $raw_token, $parts ) ) {
			return null;
		}

		$parsed_prefix = $parts[1];
		$parsed_id     = $parts[2];

		$tokens  = self::get_tokens();
		$matched = null;

		foreach ( $tokens as $record ) {
			if ( ! is_array( $record ) || empty( $record['id'] ) ) {
				continue;
			}
			if ( hash_equals( (string) $record['id'], $parsed_id ) ) {
				$matched = $record;
			}
		}

		if ( null === $matched || ! empty( $matched['revoked'] ) ) {
			return null;
		}

		if ( ! hash_equals( (string) ( $matched['prefix'] ?? '' ), $parsed_prefix ) ) {
			return null;
		}

		$secret_hash = (string) ( $matched['secret_hash'] ?? '' );
		if ( '' === $secret_hash || ! wp_check_password( $raw_token, $secret_hash ) ) {
			return null;
		}

		$expected_scope = ( 'sgmcp_rw' === $parsed_prefix ) ? self::SCOPE_READ_WRITE : self::SCOPE_READ_ONLY;
		if ( ! hash_equals( (string) ( $matched['scope'] ?? '' ), $expected_scope ) ) {
			return null;
		}

		self::touch_last_used( $parsed_id, $tokens );

		return array(
			'id'     => $parsed_id,
			'prefix' => $parsed_prefix,
			'scope'  => $expected_scope,
			'label'  => (string) ( $matched['label'] ?? 'MCP Client' ),
		);
	}

	/**
	 * Authenticate an incoming WP_REST_Request.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return array<string, mixed>|null
	 */
	public static function authenticate_request( WP_REST_Request $request ): ?array {
		$raw = self::extract_bearer_token( $request );
		if ( null === $raw || '' === $raw ) {
			return null;
		}
		return self::verify_token( $raw );
	}

	/**
	 * Update last_used_at at most once every 60 seconds to avoid excessive option writes.
	 *
	 * @param string               $token_id Token ID.
	 * @param array<string, mixed> $tokens   Loaded tokens array.
	 */
	private static function touch_last_used( string $token_id, array $tokens ): void {
		if ( ! isset( $tokens[ $token_id ] ) ) {
			return;
		}

		$last = $tokens[ $token_id ]['last_used_at'] ?? null;
		if ( is_string( $last ) && '' !== $last ) {
			$ts = strtotime( $last . ' UTC' );
			if ( false !== $ts && ( time() - $ts ) < 60 ) {
				return;
			}
		}

		$tokens[ $token_id ]['last_used_at'] = gmdate( 'Y-m-d H:i:s' );
		update_option( self::OPTION_TOKENS, $tokens, false );
	}
}
