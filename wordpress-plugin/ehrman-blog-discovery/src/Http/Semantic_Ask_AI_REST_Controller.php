<?php
/**
 * Semantic Ask AI REST endpoint.
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

/** Retrieves semantic candidates and refines them for the reader. */
final class Semantic_Ask_AI_REST_Controller {
	private const RATE_LIMIT = 20;

	/**
	 * Creates the controller from semantic retrieval and review services.
	 *
	 * @param AI_Interpreter          $interpreter     Shared AI candidate-review service.
	 * @param Semantic_Search_Service $semantic_search Semantic candidate retrieval service.
	 */
	public function __construct(
		private AI_Interpreter $interpreter,
		private Semantic_Search_Service $semantic_search
	) {}

	/**
	 * Retrieves title-and-summary matches and immediately refines them with AI.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response|WP_Error Semantic search response or error.
	 */
	public function semantic_search( WP_REST_Request $request ) {
		if ( 'local' !== wp_get_environment_type() ) {
			$rate_key = 'ebd_ai_semantic_rate_' . hash( 'sha256', Rest_Request_Values::request_address() );
			$count    = Database::integer( get_transient( $rate_key ) );
			if ( $count >= self::RATE_LIMIT ) {
				return new WP_Error( 'ehrman_ai_semantic_rate_limit', __( 'You\'ve reached the temporary question limit. Please wait a few minutes before trying again.', 'ehrman-blog-discovery' ), array( 'status' => 429 ) );
			}
			set_transient( $rate_key, $count + 1, 5 * MINUTE_IN_SECONDS );
		}

		$question   = Rest_Request_Values::question( $request->get_param( 'question' ) );
		$request_id = AI_Requests::request_id();
		$candidates = $this->semantic_search->search( $question, $request_id );
		if ( is_wp_error( $candidates ) ) {
			AI_Requests::record(
				$request_id,
				$question,
				array(),
				false,
				false,
				(string) $candidates->get_error_code(),
				'semantic',
				AI_Interpreter::model_id(),
				Semantic_Search_Service::pipeline_version()
			);
			return $candidates;
		}

		AI_Requests::record(
			$request_id,
			$question,
			array(),
			$candidates['cache_hit'],
			true,
			'',
			'semantic',
			AI_Interpreter::model_id(),
			Semantic_Search_Service::pipeline_version()
		);
		$broader       = $candidates['posts'];
		$broader_count = count( $broader );
		$refinement_id = AI_Requests::request_id();
		$refinement    = $this->interpreter->refine( $question, $broader, $refinement_id );
		if ( is_wp_error( $refinement ) ) {
			AI_Refinements::record(
				array(
					'refinement_id'   => $refinement_id,
					'request_id'      => $request_id,
					'question'        => $question,
					'original_count'  => $broader_count,
					'candidate_count' => $broader_count,
					'succeeded'       => false,
					'error_code'      => (string) $refinement->get_error_code(),
				),
				array()
			);
			AI_Requests::set_result_count( $request_id, $broader_count, true );
			$response = new WP_REST_Response(
				$this->semantic_response(
					array_slice( $broader, 0, Search_Service::POSTS_PER_PAGE ),
					$broader,
					$request_id,
					$refinement_id,
					false,
					__( 'AI refinement was unavailable, so the broader semantic matches are shown.', 'ehrman-blog-discovery' )
				),
				200
			);
			$response->header( 'Cache-Control', 'no-store' );
			return $response;
		}

		$posts_by_id = array();
		foreach ( $broader as $post ) {
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
		AI_Refinements::record(
			array(
				'refinement_id'   => $refinement_id,
				'request_id'      => $request_id,
				'question'        => $question,
				'original_count'  => $broader_count,
				'candidate_count' => $refinement['candidate_count'],
				'cache_hit'       => $refinement['cache_hit'],
				'succeeded'       => true,
				'usage'           => $refinement['usage'],
			),
			$posts
		);
		$refined = ! empty( $posts );
		$count   = $refined ? count( $posts ) : $broader_count;
		AI_Requests::set_result_count( $request_id, $count, true );
		$response = new WP_REST_Response(
			$this->semantic_response(
				$refined ? $posts : array_slice( $broader, 0, Search_Service::POSTS_PER_PAGE ),
				$broader,
				$request_id,
				$refinement_id,
				$refined,
				$refined ? '' : __( 'AI did not select a narrower set, so the broader semantic matches are shown.', 'ehrman-blog-discovery' )
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Builds the Ask AI 2 REST response.
	 *
	 * @param list<array<string,mixed>> $posts         Initially displayed posts.
	 * @param list<array<string,mixed>> $broader       Semantic candidate posts.
	 * @param string                    $request_id    Parent request identifier.
	 * @param string                    $refinement_id Refinement identifier.
	 * @param bool                      $refined       Whether the displayed set was refined.
	 * @param string                    $notice        Optional fallback notice.
	 * @return array<string,mixed> Response payload.
	 */
	private function semantic_response( array $posts, array $broader, string $request_id, string $refinement_id, bool $refined, string $notice ): array {
		$broader_count = count( $broader );
		$count         = $refined ? count( $posts ) : $broader_count;
		return array(
			'posts'           => $posts,
			'terms'           => array(),
			'sort'            => 'ranked',
			'count'           => $count,
			'page'            => 1,
			'per_page'        => $refined ? count( $posts ) : Search_Service::POSTS_PER_PAGE,
			'total_pages'     => $refined ? ( empty( $posts ) ? 0 : 1 ) : (int) ceil( $broader_count / Search_Service::POSTS_PER_PAGE ),
			'refined'         => $refined,
			'semantic'        => true,
			'original_count'  => $broader_count,
			'candidate_count' => $broader_count,
			'request_id'      => $request_id,
			'refinement_id'   => $refinement_id,
			'notice'          => $notice,
			'broader'         => array(
				'posts'       => $broader,
				'terms'       => array(),
				'sort'        => 'ranked',
				'count'       => $broader_count,
				'page'        => 1,
				'per_page'    => Search_Service::POSTS_PER_PAGE,
				'total_pages' => (int) ceil( $broader_count / Search_Service::POSTS_PER_PAGE ),
				'semantic'    => true,
			),
		);
	}
}
