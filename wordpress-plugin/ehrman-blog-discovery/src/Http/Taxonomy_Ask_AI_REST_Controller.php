<?php
/**
 * Topic-and-keyword Ask AI REST endpoints.
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

/** Interprets questions and refines topic-and-keyword candidates. */
final class Taxonomy_Ask_AI_REST_Controller {
	private const INTERPRET_RATE_LIMIT = 20;
	private const REFINE_RATE_LIMIT    = 40;

	/**
	 * Creates the controller from the topic-and-keyword search services.
	 *
	 * @param Search_Service $search_service Topic-and-keyword search service.
	 * @param AI_Interpreter $interpreter    AI interpretation and refinement service.
	 */
	public function __construct(
		private Search_Service $search_service,
		private AI_Interpreter $interpreter
	) {}

	/**
	 * Converts a natural-language question into approved search terms.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response|WP_Error Interpretation response or error.
	 */
	public function interpret( WP_REST_Request $request ) {
		if ( 'local' !== wp_get_environment_type() ) {
			$rate_key = 'ebd_ai_rate_' . hash( 'sha256', Rest_Request_Values::request_address() );
			$count    = Database::integer( get_transient( $rate_key ) );
			if ( $count >= self::INTERPRET_RATE_LIMIT ) {
				return new WP_Error( 'ehrman_ai_rate_limit', __( 'You\'ve reached the temporary question limit. Please wait a few minutes before trying again.', 'ehrman-blog-discovery' ), array( 'status' => 429 ) );
			}
			set_transient( $rate_key, $count + 1, 5 * MINUTE_IN_SECONDS );
		}
		$question   = Rest_Request_Values::question( $request->get_param( 'question' ) );
		$request_id = AI_Requests::request_id();
		$result     = $this->interpreter->interpret( $question, $request_id );
		if ( is_wp_error( $result ) ) {
			AI_Requests::record( $request_id, $question, array(), false, false, (string) $result->get_error_code() );
			return $result;
		}
		AI_Requests::record( $request_id, $question, $result['terms'], $result['cache_hit'], true );
		$result['request_id'] = $request_id;
		$response             = new WP_REST_Response( $result, 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Uses post titles and search summaries to narrow an interpreted search.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response|WP_Error Refined search response or error.
	 */
	public function refine( WP_REST_Request $request ) {
		if ( 'local' !== wp_get_environment_type() ) {
			$rate_key = 'ebd_ai_refine_rate_' . hash( 'sha256', Rest_Request_Values::request_address() );
			$count    = Database::integer( get_transient( $rate_key ) );
			if ( $count >= self::REFINE_RATE_LIMIT ) {
				return new WP_Error( 'ehrman_ai_refine_rate_limit', __( 'AI refinement is temporarily busy. Please wait a few minutes and try again.', 'ehrman-blog-discovery' ), array( 'status' => 429 ) );
			}
			set_transient( $rate_key, $count + 1, 5 * MINUTE_IN_SECONDS );
		}

		$question   = Rest_Request_Values::question( $request->get_param( 'question' ) );
		$request_id = sanitize_text_field( Rest_Request_Values::scalar_text( $request->get_param( 'request_id' ) ) );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $request_id ) ) {
			$request_id = '';
		}
		$terms      = Rest_Request_Values::terms( $request->get_param( 'term' ) );
		$term_modes = Rest_Request_Values::modes( $request->get_param( 'mode' ) );
		if ( empty( $terms ) ) {
			return new WP_Error( 'ehrman_ai_refine_empty', __( 'There are no search results to refine.', 'ehrman-blog-discovery' ), array( 'status' => 400 ) );
		}

		$original      = $this->search_service->search( $terms, 'ranked', '', '', 1, 0, $term_modes );
		$refinement_id = AI_Requests::request_id();
		$refinement    = $this->interpreter->refine( $question, $original['posts'], $refinement_id );
		if ( is_wp_error( $refinement ) ) {
			AI_Refinements::record(
				array(
					'refinement_id'   => $refinement_id,
					'request_id'      => $request_id,
					'question'        => $question,
					'original_count'  => Database::integer( $original['count'] ),
					'candidate_count' => min( count( $original['posts'] ), 200 ),
					'succeeded'       => false,
					'error_code'      => (string) $refinement->get_error_code(),
				),
				array()
			);
			return $refinement;
		}

		$posts_by_id = array();
		foreach ( $original['posts'] as $post ) {
			$posts_by_id[ Database::text( $post['id'] ?? null ) ] = $post;
		}
		$posts = array();
		foreach ( $refinement['post_ids'] as $id ) {
			if ( isset( $posts_by_id[ $id ] ) ) {
				$post = $posts_by_id[ $id ];
				if ( isset( $refinement['post_tiers'][ $id ] ) ) {
					$post['relevance_tier'] = $refinement['post_tiers'][ $id ];
				}
				$posts[] = $post;
			}
		}
		$broader_count       = Database::integer( $original['count'] );
		$broader_per_page    = Search_Service::POSTS_PER_PAGE;
		$broader_posts       = array_slice( $original['posts'], 0, $broader_per_page );
		$broader_total_pages = $broader_count > 0 ? (int) ceil( $broader_count / $broader_per_page ) : 0;
		AI_Refinements::record(
			array(
				'refinement_id'   => $refinement_id,
				'request_id'      => $request_id,
				'question'        => $question,
				'original_count'  => Database::integer( $original['count'] ),
				'candidate_count' => $refinement['candidate_count'],
				'cache_hit'       => $refinement['cache_hit'],
				'succeeded'       => true,
				'usage'           => $refinement['usage'],
			),
			$posts
		);
		if ( '' !== $request_id && ! empty( $posts ) ) {
			AI_Requests::set_result_count( $request_id, count( $posts ), true );
		}

		$response = new WP_REST_Response(
			array(
				'posts'           => $posts,
				'terms'           => $original['terms'],
				'sort'            => 'ranked',
				'count'           => count( $posts ),
				'page'            => 1,
				'per_page'        => count( $posts ),
				'total_pages'     => empty( $posts ) ? 0 : 1,
				'refined'         => true,
				'original_count'  => Database::integer( $original['count'] ),
				'candidate_count' => $refinement['candidate_count'],
				'cache_hit'       => $refinement['cache_hit'],
				'refinement_id'   => $refinement_id,
				'broader'         => array(
					'posts'       => $broader_posts,
					'terms'       => $original['terms'],
					'sort'        => 'ranked',
					'count'       => $broader_count,
					'page'        => 1,
					'per_page'    => $broader_per_page,
					'total_pages' => $broader_total_pages,
				),
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
