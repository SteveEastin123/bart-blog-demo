<?php
/**
 * Optional staging-only test endpoint registration.
 *
 * @package EhrmanDiscoveryTests
 */

namespace EhrmanDiscoveryTests;

use EhrmanBlogDiscovery\Search_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keeps test endpoints unavailable on production and without explicit credentials. */
final class Plugin {
	/** Registers the legacy comparison contract only in a configured test site. */
	public static function register_routes(): void {
		if ( ! in_array( wp_get_environment_type(), array( 'local', 'development', 'staging' ), true )
			|| ! class_exists( Search_Service::class ) ) {
			return;
		}
		$controller = new Parity_REST_Controller();
		if ( ! $controller->is_enabled() ) {
			return;
		}
		register_rest_route(
			'ehrman-discovery/v1',
			'/parity/batch',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'batch' ),
				'permission_callback' => array( $controller, 'permission' ),
			)
		);
	}
}
