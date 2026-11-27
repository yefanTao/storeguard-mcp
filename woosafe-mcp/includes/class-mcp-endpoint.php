<?php
/**
 * Streamable HTTP / JSON-RPC 2.0 MCP Endpoint at /wp-json/woosafe-mcp/v1/mcp.
 *
 * Implements MCP protocol methods:
 * - initialize
 * - notifications/initialized (202 Accepted)
 * - ping
 * - tools/list
 * - tools/call
 *
 * @package WooSafe_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WooSafe_MCP_Endpoint {

	public const REST_NAMESPACE = 'woosafe-mcp/v1';
	public const REST_ROUTE     = '/mcp';

	/**
	 * Register REST API routes.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle_post' ),
					'permission_callback' => array( __CLASS__, 'check_bearer_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_get' ),
					'permission_callback' => array( __CLASS__, 'check_bearer_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'handle_delete' ),
					'permission_callback' => array( __CLASS__, 'check_bearer_permission' ),
				),
			)
		);
	}

	/**
	 * Verify scoped Bearer token for every MCP request.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return true|WP_Error
	 */
	public static function check_bearer_permission( WP_REST_Request $request ) {
		$auth = WooSafe_MCP_Auth::authenticate_request( $request );
		if ( null === $auth ) {
			return new WP_Error(
				'woosafe_unauthorized',
				__( 'Missing, invalid, or revoked WooSafe MCP Bearer token.', 'woosafe-mcp' ),
				array( 'status' => 401 )
			);
		}

		$request->set_param( '_woosafe_auth', $auth );
		return true;
	}

	/**
	 * Handle Streamable HTTP GET (server does not open unrequested SSE stream -> 405).
	 */
	public static function handle_get(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => null,
				'error'   => array(
					'code'    => -32000,
					'message' => 'Standalone GET SSE stream not enabled; send JSON-RPC 2.0 requests via POST.',
				),
			),
			405,
			array( 'Allow' => 'POST, DELETE' )
		);
	}

	/**
	 * Handle Streamable HTTP DELETE session termination.
	 */
	public static function handle_delete(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'ok'     => true,
				'status' => 'session_closed',
			),
			200
		);
	}

	/**
	 * Handle JSON-RPC 2.0 POST messages (single or batch, request or notification).
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public static function handle_post( WP_REST_Request $request ): WP_REST_Response {
		$auth_context = $request->get_param( '_woosafe_auth' );
		if ( ! is_array( $auth_context ) ) {
			return self::json_rpc_error_response( null, -32001, 'Unauthorized', null, 401 );
		}

		$raw_body = $request->get_body();
		$decoded  = json_decode( $raw_body, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return self::json_rpc_error_response( null, -32700, 'Parse error: invalid JSON payload.', null, 400 );
		}

		$headers    = array( 'Content-Type' => 'application/json; charset=utf-8' );
		$session_id = $request->get_header( 'mcp-session-id' );

		if ( is_array( $decoded ) && array_is_list( $decoded ) ) {
			if ( empty( $decoded ) ) {
				return self::json_rpc_error_response( null, -32600, 'Invalid Request: empty batch.', null, 400 );
			}
			$responses = array();
			foreach ( $decoded as $item ) {
				if ( ! is_array( $item ) ) {
					$responses[] = self::build_error_object( null, -32600, 'Invalid Request in batch.' );
					continue;
				}
				$single = self::dispatch_message( $item, $auth_context );
				if ( null !== $single ) {
					$responses[] = $single;
				}
			}
			if ( empty( $responses ) ) {
				return new WP_REST_Response( null, 202 );
			}
			return new WP_REST_Response( $responses, 200, $headers );
		}

		if ( ! is_array( $decoded ) ) {
			return self::json_rpc_error_response( null, -32600, 'Invalid Request: expected JSON object.', null, 400 );
		}

		$response_payload = self::dispatch_message( $decoded, $auth_context );
		if ( null === $response_payload ) {
			return new WP_REST_Response( null, 202 );
		}

		if ( 'initialize' === ( $decoded['method'] ?? '' ) && ! $session_id ) {
			$headers['Mcp-Session-Id'] = wp_generate_uuid4();
		} elseif ( $session_id ) {
			$headers['Mcp-Session-Id'] = sanitize_text_field( $session_id );
		}

		return new WP_REST_Response( $response_payload, 200, $headers );
	}

	/**
	 * Dispatch a single decoded JSON-RPC 2.0 request/notification.
	 *
	 * @param array<string, mixed> $msg          Decoded JSON-RPC object.
	 * @param array<string, mixed> $auth_context Authenticated token metadata.
	 * @return array<string, mixed>|null Null for notifications.
	 */
	public static function dispatch_message( array $msg, array $auth_context ): ?array {
		$has_id = array_key_exists( 'id', $msg );
		$id     = $has_id ? $msg['id'] : null;

		if ( '2.0' !== ( $msg['jsonrpc'] ?? '' ) || empty( $msg['method'] ) || ! is_string( $msg['method'] ) ) {
			return $has_id ? self::build_error_object( $id, -32600, 'Invalid Request: jsonrpc "2.0" and string method are required.' ) : null;
		}

		$method = $msg['method'];
		$params = isset( $msg['params'] ) && is_array( $msg['params'] ) ? $msg['params'] : array();

		if ( ! $has_id ) {
			return null;
		}

		switch ( $method ) {
			case 'initialize':
				$client_version = isset( $params['protocolVersion'] ) && is_string( $params['protocolVersion'] )
					? $params['protocolVersion']
					: WOOSAFE_MCP_PROTOCOL_VERSION;
				$supported      = in_array( $client_version, array( '2025-03-26', '2024-11-05' ), true )
					? $client_version
					: WOOSAFE_MCP_PROTOCOL_VERSION;

				return array(
					'jsonrpc' => '2.0',
					'id'      => $id,
					'result'  => array(
						'protocolVersion' => $supported,
						'capabilities'    => array(
							'tools' => array(
								'listChanged' => false,
							),
						),
						'serverInfo'      => array(
							'name'    => 'woosafe-mcp',
							'version' => WOOSAFE_MCP_VERSION,
						),
						'instructions'    => 'WooSafe MCP defaults to Read-Only Audit Mode. Guarded write tools default to dry_run: true and enforce price-delta and batch-size caps with 1-click rollback snapshots.',
					),
				);

			case 'ping':
				return array(
					'jsonrpc' => '2.0',
					'id'      => $id,
					'result'  => new stdClass(),
				);

			case 'tools/list':
				$all_tools = array_merge(
					WooSafe_MCP_Tools_Read::get_definitions(),
					apply_filters( 'woosafe_mcp_write_tool_definitions', array() )
				);
				return array(
					'jsonrpc' => '2.0',
					'id'      => $id,
					'result'  => array(
						'tools' => $all_tools,
					),
				);

			case 'tools/call':
				return self::handle_tools_call( $id, $params, $auth_context );

			default:
				return self::build_error_object( $id, -32601, sprintf( 'Method not found: %s', $method ) );
		}
	}

	/**
	 * Handle MCP tools/call with Guardrails + Audit Logging.
	 */
	private static function handle_tools_call( $id, array $params, array $auth_context ): array {
		$started_at = microtime( true );
		$tool_name  = isset( $params['name'] ) && is_string( $params['name'] ) ? sanitize_key( $params['name'] ) : '';
		$args       = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		if ( '' === $tool_name ) {
			return self::build_error_object( $id, -32602, 'Invalid params: tool "name" is required.' );
		}

		$is_read  = WooSafe_MCP_Tools_Read::is_read_tool( $tool_name );
		$is_write = in_array( $tool_name, WooSafe_MCP_Guardrails::get_registered_write_tools(), true );

		if ( ! $is_read && ! $is_write ) {
			return self::build_error_object( $id, -32601, sprintf( 'Unknown MCP tool: %s', $tool_name ) );
		}

		$gate = WooSafe_MCP_Guardrails::authorize_tool( $tool_name, $is_write, $args, $auth_context );
		if ( empty( $gate['allowed'] ) ) {
			$elapsed = (int) round( ( microtime( true ) - $started_at ) * 1000 );
			WooSafe_MCP_Snapshots::log_audit(
				array(
					'request_id'  => (string) $id,
					'token_id'    => (string) ( $auth_context['id'] ?? '' ),
					'token_scope' => (string) ( $auth_context['scope'] ?? '' ),
					'tool_name'   => $tool_name,
					'mode'        => 'blocked',
					'decision'    => 'denied',
					'reason'      => (string) ( $gate['code'] ?? 'WOOSAFE_BLOCKED' ),
					'summary'     => (string) ( $gate['message'] ?? 'Blocked by WooSafe guardrails' ),
					'duration_ms' => $elapsed,
				)
			);

			return self::build_error_object(
				$id,
				-32003,
				(string) $gate['message'],
				array(
					'guardrail_code' => $gate['code'],
					'remediation'    => $gate['remediation'] ?? '',
				)
			);
		}

		if ( $is_read ) {
			$result = WooSafe_MCP_Tools_Read::execute( $tool_name, $args );
		} else {
			$result = apply_filters(
				'woosafe_mcp_execute_write_tool',
				null,
				$tool_name,
				$args,
				(bool) $gate['dry_run'],
				$gate['settings'],
				$auth_context
			);
			if ( ! is_array( $result ) ) {
				$result = array(
					'error' => array(
						'code'    => 'WOOSAFE_WRITE_HANDLER_MISSING',
						'message' => sprintf( 'No write handler registered for tool "%s".', $tool_name ),
					),
				);
			}
		}

		$elapsed = (int) round( ( microtime( true ) - $started_at ) * 1000 );

		if ( isset( $result['error'] ) && is_array( $result['error'] ) ) {
			$err_code = (string) ( $result['error']['code'] ?? 'WOOSAFE_ERROR' );
			$err_msg  = (string) ( $result['error']['message'] ?? 'Tool execution blocked.' );
			$rpc_code = in_array( $err_code, array( 'WOOSAFE_INVALID_PARAMS', 'WOOSAFE_NO_FIELDS', 'WOOSAFE_INVALID_STATUS' ), true )
				? -32602
				: ( 'WOOSAFE_PRODUCT_NOT_FOUND' === $err_code ? -32004 : -32003 );

			WooSafe_MCP_Snapshots::log_audit(
				array(
					'request_id'  => (string) $id,
					'token_id'    => (string) ( $auth_context['id'] ?? '' ),
					'token_scope' => (string) ( $auth_context['scope'] ?? '' ),
					'tool_name'   => $tool_name,
					'mode'        => (string) ( $gate['mode'] ?? 'blocked' ),
					'decision'    => 'denied',
					'reason'      => $err_code,
					'summary'     => $err_msg,
					'duration_ms' => $elapsed,
				)
			);

			return self::build_error_object( $id, $rpc_code, $err_msg, $result['error'] );
		}

		WooSafe_MCP_Snapshots::log_audit(
			array(
				'request_id'    => (string) $id,
				'token_id'      => (string) ( $auth_context['id'] ?? '' ),
				'token_scope'   => (string) ( $auth_context['scope'] ?? '' ),
				'tool_name'     => $tool_name,
				'mode'          => (string) ( $gate['mode'] ?? 'read' ),
				'decision'      => 'allowed',
				'reason'        => ! empty( $result['dry_run'] ) ? 'DRY_RUN_PREVIEW' : 'OK',
				'summary'       => sprintf( '%s completed (%s)', $tool_name, (string) ( $gate['mode'] ?? 'read' ) ),
				'snapshot_uuid' => $result['snapshot_uuid'] ?? null,
				'duration_ms'   => $elapsed,
			)
		);

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => array(
				'content'           => array(
					array(
						'type' => 'text',
						'text' => wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
					),
				),
				'structuredContent' => $result,
				'isError'           => false,
			),
		);
	}

	/**
	 * Construct a JSON-RPC 2.0 error payload.
	 */
	private static function build_error_object( $id, int $code, string $message, ?array $data = null ): array {
		$error = array(
			'code'    => $code,
			'message' => $message,
		);
		if ( null !== $data ) {
			$error['data'] = $data;
		}
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => $error,
		);
	}

	/**
	 * Build REST response for top-level JSON-RPC errors.
	 */
	private static function json_rpc_error_response( $id, int $code, string $message, ?array $data = null, int $status = 400 ): WP_REST_Response {
		return new WP_REST_Response(
			self::build_error_object( $id, $code, $message, $data ),
			$status,
			array( 'Content-Type' => 'application/json; charset=utf-8' )
		);
	}
}
