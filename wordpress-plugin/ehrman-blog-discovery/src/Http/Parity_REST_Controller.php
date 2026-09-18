<?php
/**
 * Protected parity-test REST endpoint.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Authorizes and executes parity-test batches when explicitly enabled. */
final class Parity_REST_Controller {
	/** Returns whether a parity token enables the test-only route. */
	public function is_enabled(): bool {
		return '' !== Parity_Service::configured_token();
	}

	/**
	 * Authorizes access to the optional parity endpoint.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return true|WP_Error True when authorized, otherwise an error.
	 */
	public function permission( WP_REST_Request $request ) {
		$configured = Parity_Service::configured_token();
		$provided   = (string) $request->get_header( 'X-Ehrman-Parity-Token' );
		if ( '' === $configured || '' === $provided || ! hash_equals( $configured, $provided ) ) {
			return new WP_Error(
				'ehrman_parity_forbidden',
				__( 'Forbidden', 'ehrman-blog-discovery' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Executes a parity-test batch when the protected route is enabled.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response|WP_Error Batch response or validation error.
	 */
	public function batch( WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		/**
		 * Runtime JSON payload.
		 *
		 * @var mixed $payload WordPress stubs narrow this more than runtime does.
		 */
		if ( ! is_array( $payload ) ) {
			return new WP_Error(
				'ehrman_parity_invalid_json',
				__( 'Request body must be a JSON object.', 'ehrman-blog-discovery' ),
				array( 'status' => 400 )
			);
		}
		$schema_version = $payload['schemaVersion'] ?? Parity_Service::SCHEMA_VERSION;
		if ( ( ! is_int( $schema_version ) && ! is_string( $schema_version ) ) || Parity_Service::SCHEMA_VERSION !== (int) $schema_version ) {
			return new WP_Error(
				'ehrman_parity_schema',
				__( 'Unsupported schemaVersion.', 'ehrman-blog-discovery' ),
				array( 'status' => 400 )
			);
		}

		try {
			$response = new WP_REST_Response(
				( new Parity_Service() )->run_batch( $payload['cases'] ?? null ),
				200
			);
			$response->header( 'Cache-Control', 'no-store' );
			return $response;
		} catch ( \InvalidArgumentException | \RuntimeException $error ) {
			return new WP_Error(
				'ehrman_parity_invalid_request',
				$error->getMessage(),
				array( 'status' => 400 )
			);
		}
	}
}
