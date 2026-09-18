<?php
/**
 * Search-feedback REST endpoint.
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

/** Records anonymous feedback about interpreted searches. */
final class Feedback_REST_Controller {
	private const RATE_LIMIT = 20;

	/**
	 * Records one helpful or unhelpful response.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 */
	public function feedback( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( 'local' !== wp_get_environment_type() ) {
			$rate_key = 'ebd_feedback_rate_' . hash( 'sha256', Rest_Request_Values::request_address() );
			$count    = Database::integer( get_transient( $rate_key ) );
			if ( $count >= self::RATE_LIMIT ) {
				return new WP_Error( 'ehrman_feedback_rate_limit', __( 'Too much feedback was submitted. Please wait a few minutes and try again.', 'ehrman-blog-discovery' ), array( 'status' => 429 ) );
			}
			set_transient( $rate_key, $count + 1, 5 * MINUTE_IN_SECONDS );
		}

		$raw_helpful = $request->get_param( 'helpful' );
		if ( ! is_bool( $raw_helpful ) ) {
			return new WP_Error( 'ehrman_feedback_invalid', __( 'The feedback response was invalid.', 'ehrman-blog-discovery' ), array( 'status' => 400 ) );
		}
		$request_id = sanitize_text_field( Rest_Request_Values::scalar_text( $request->get_param( 'request_id' ) ) );
		$stored     = AI_Requests::set_feedback( $request_id, $raw_helpful );
		if ( ! $stored ) {
			return new WP_Error( 'ehrman_feedback_invalid', __( 'The feedback could not be saved.', 'ehrman-blog-discovery' ), array( 'status' => 400 ) );
		}
		return new WP_REST_Response( array( 'saved' => true ), 201 );
	}
}
