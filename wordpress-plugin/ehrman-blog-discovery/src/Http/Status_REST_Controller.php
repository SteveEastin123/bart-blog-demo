<?php
/**
 * REST status endpoint.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Returns public plugin and import status. */
final class Status_REST_Controller {
	/**
	 * Returns public plugin and import status information.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 */
	public function status( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		$status = Plugin::status_data();
		unset( $status['database_version'] );
		return new WP_REST_Response( $status, 200 );
	}
}
