<?php
/**
 * Keyword-search REST endpoints.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Serves suggestions and topic-and-keyword search results. */
final class Search_REST_Controller {
	/**
	 * Creates the controller with its search service.
	 *
	 * @param Search_Service $search_service Topic-and-keyword search service.
	 */
	public function __construct( private Search_Service $search_service ) {}

	/**
	 * Returns scoped topic and keyword suggestions.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 */
	public function suggestions( WP_REST_Request $request ): WP_REST_Response {
		$response = new WP_REST_Response(
			$this->search_service->suggestions(
				Rest_Request_Values::bounded_text( $request->get_param( 'q' ) ),
				Rest_Request_Values::terms( $request->get_param( 'selected' ) ),
				sanitize_title( Rest_Request_Values::scalar_text( $request->get_param( 'category' ) ) ),
				sanitize_title( Rest_Request_Values::scalar_text( $request->get_param( 'topic' ) ) ),
				Rest_Request_Values::modes( $request->get_param( 'selectedMode' ) )
			),
			200
		);
		$response->header( 'Cache-Control', 'public, max-age=30' );
		return $response;
	}

	/**
	 * Searches posts with the supplied terms and scope.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 */
	public function search( WP_REST_Request $request ): WP_REST_Response {
		$result   = $this->search_service->search(
			Rest_Request_Values::terms( $request->get_param( 'term' ) ),
			sanitize_key( Rest_Request_Values::scalar_text( $request->get_param( 'sort' ) ) ),
			sanitize_title( Rest_Request_Values::scalar_text( $request->get_param( 'category' ) ) ),
			sanitize_title( Rest_Request_Values::scalar_text( $request->get_param( 'topic' ) ) ),
			max( 1, absint( Rest_Request_Values::scalar_text( $request->get_param( 'page' ) ) ) ),
			Search_Service::POSTS_PER_PAGE,
			Rest_Request_Values::modes( $request->get_param( 'mode' ) )
		);
		$response = new WP_REST_Response( $result, 200 );
		$response->header( 'Cache-Control', 'public, max-age=30' );
		return $response;
	}
}
