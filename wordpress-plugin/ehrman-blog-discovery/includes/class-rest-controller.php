<?php
/**
 * Public REST API endpoints.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers and serves search, suggestion, status, and parity endpoints. */
final class Rest_Controller {

	/** REST namespace shared by all plugin routes. */
	private const REST_NAMESPACE = 'ehrman-discovery/v1';

	/** Maximum AI interpretation and refinement requests per five-minute window. */
	private const AI_RATE_LIMIT = 20;

	/**
	 * Search service used by public REST callbacks.
	 *
	 * @var Search_Service
	 */
	private Search_Service $search;

	/**
	 * Natural-language interpretation service.
	 *
	 * @var AI_Interpreter
	 */
	private AI_Interpreter $interpreter;

	/** Creates the REST controller and its search service. */
	public function __construct() {
		$this->search      = new Search_Service();
		$this->interpreter = new AI_Interpreter();
	}

	/** Registers all public and test-only REST routes. */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => '__return_true',
			)
		);
		if ( '' !== Parity_Service::configured_token() ) {
			register_rest_route(
				self::REST_NAMESPACE,
				'/parity/batch',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'parity_batch' ),
					'permission_callback' => array( $this, 'parity_permission' ),
				)
			);
		}
		register_rest_route(
			self::REST_NAMESPACE,
			'/suggestions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'suggestions' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'search' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/interpret',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'interpret' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/refine',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'refine' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/feedback',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'feedback' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Records anonymous feedback about an interpreted search.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response|WP_Error Storage response or error.
	 */
	public function feedback( WP_REST_Request $request ) {
		if ( 'local' !== wp_get_environment_type() ) {
			$rate_key = 'ebd_feedback_rate_' . hash( 'sha256', $this->request_address() );
			$count    = Database::integer( get_transient( $rate_key ) );
			if ( $count >= 20 ) {
				return new WP_Error( 'ehrman_feedback_rate_limit', __( 'Too much feedback was submitted. Please wait a few minutes and try again.', 'ehrman-blog-discovery' ), array( 'status' => 429 ) );
			}
			set_transient( $rate_key, $count + 1, 5 * MINUTE_IN_SECONDS );
		}

		$raw_helpful = $request->get_param( 'helpful' );
		if ( ! is_bool( $raw_helpful ) ) {
			return new WP_Error( 'ehrman_feedback_invalid', __( 'The feedback response was invalid.', 'ehrman-blog-discovery' ), array( 'status' => 400 ) );
		}
		$request_id = sanitize_text_field( $this->scalar_text( $request->get_param( 'request_id' ) ) );
		$stored     = AI_Requests::set_feedback( $request_id, $raw_helpful );
		if ( ! $stored ) {
			return new WP_Error( 'ehrman_feedback_invalid', __( 'The feedback could not be saved.', 'ehrman-blog-discovery' ), array( 'status' => 400 ) );
		}
		return new WP_REST_Response( array( 'saved' => true ), 201 );
	}

	/**
	 * Converts a natural-language question into approved search terms.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response|WP_Error Interpretation response or error.
	 */
	public function interpret( WP_REST_Request $request ) {
		if ( 'local' !== wp_get_environment_type() ) {
			$rate_key = 'ebd_ai_rate_' . hash( 'sha256', $this->request_address() );
			$count    = Database::integer( get_transient( $rate_key ) );
			if ( $count >= self::AI_RATE_LIMIT ) {
				return new WP_Error( 'ehrman_ai_rate_limit', __( 'You\'ve reached the temporary question limit. Please wait a few minutes before trying again.', 'ehrman-blog-discovery' ), array( 'status' => 429 ) );
			}
			set_transient( $rate_key, $count + 1, 5 * MINUTE_IN_SECONDS );
		}
		$question   = $this->question_text( $request->get_param( 'question' ) );
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
			$rate_key = 'ebd_ai_rate_' . hash( 'sha256', $this->request_address() );
			$count    = Database::integer( get_transient( $rate_key ) );
			if ( $count >= self::AI_RATE_LIMIT ) {
				return new WP_Error( 'ehrman_ai_rate_limit', __( 'You\'ve reached the temporary question limit. Please wait a few minutes before trying again.', 'ehrman-blog-discovery' ), array( 'status' => 429 ) );
			}
			set_transient( $rate_key, $count + 1, 5 * MINUTE_IN_SECONDS );
		}

		$question   = $this->question_text( $request->get_param( 'question' ) );
		$request_id = sanitize_text_field( $this->scalar_text( $request->get_param( 'request_id' ) ) );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $request_id ) ) {
			$request_id = '';
		}
		$terms      = $this->terms( $request->get_param( 'term' ) );
		$term_modes = $this->modes( $request->get_param( 'mode' ) );
		if ( empty( $terms ) ) {
			return new WP_Error( 'ehrman_ai_refine_empty', __( 'There are no search results to refine.', 'ehrman-blog-discovery' ), array( 'status' => 400 ) );
		}

		$original      = $this->search->search( $terms, 'ranked', '', '', 1, 0, $term_modes );
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
				$posts[] = $posts_by_id[ $id ];
			}
		}
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
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Returns a bounded natural-language question.
	 *
	 * @param mixed $value Raw question value.
	 * @return string Sanitized, length-limited question.
	 */
	private function question_text( $value ): string {
		$text = sanitize_text_field( $this->scalar_text( $value ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 800 ) : substr( $text, 0, 800 );
	}

	/** Returns a non-identifying request-address value for short-lived rate limiting. */
	private function request_address(): string {
		$address = isset( $_SERVER['REMOTE_ADDR'] ) && is_scalar( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) )
			: 'unknown';
		return '' === $address ? 'unknown' : $address;
	}

	/**
	 * Returns public plugin and import status information.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response Status response.
	 */
	public function status( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$status = Plugin::status_data();
		unset( $status['database_version'] );
		return new WP_REST_Response( $status, 200 );
	}

	/**
	 * Returns scoped topic and keyword suggestions.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response Suggestion response.
	 */
	public function suggestions( WP_REST_Request $request ): WP_REST_Response {
		$response = new WP_REST_Response(
			$this->search->suggestions(
				$this->bounded_text( $request->get_param( 'q' ) ),
				$this->terms( $request->get_param( 'selected' ) ),
				sanitize_title( $this->scalar_text( $request->get_param( 'category' ) ) ),
				sanitize_title( $this->scalar_text( $request->get_param( 'topic' ) ) ),
				$this->modes( $request->get_param( 'selectedMode' ) )
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
	 * @return WP_REST_Response Search response.
	 */
	public function search( WP_REST_Request $request ): WP_REST_Response {
		$result   = $this->search->search(
			$this->terms( $request->get_param( 'term' ) ),
			sanitize_key( $this->scalar_text( $request->get_param( 'sort' ) ) ),
			sanitize_title( $this->scalar_text( $request->get_param( 'category' ) ) ),
			sanitize_title( $this->scalar_text( $request->get_param( 'topic' ) ) ),
			max( 1, absint( $this->scalar_text( $request->get_param( 'page' ) ) ) ),
			Search_Service::POSTS_PER_PAGE,
			$this->modes( $request->get_param( 'mode' ) )
		);
		$response = new WP_REST_Response( $result, 200 );
		$response->header( 'Cache-Control', 'public, max-age=30' );
		return $response;
	}

	/**
	 * Authorizes access to the optional parity endpoint.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return true|WP_Error True when authorized, otherwise an error.
	 */
	public function parity_permission( WP_REST_Request $request ) {
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
	public function parity_batch( WP_REST_Request $request ) {
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

	/**
	 * Converts a request value into a unique search-term list.
	 *
	 * @param mixed $value Raw request value.
	 * @return array<int,string> Sanitized search terms.
	 */
	private function terms( $value ): array {
		$terms = is_array( $value ) ? $value : ( null === $value || '' === $value ? array() : array( $value ) );
		return Search_Service::unique_terms( array_values( $terms ) );
	}

	/**
	 * Converts a request value into a bounded selected-term mode list.
	 *
	 * @param mixed $value Raw request value.
	 * @return array<int,string> Sanitized modes.
	 */
	private function modes( $value ): array {
		$modes = is_array( $value ) ? $value : ( null === $value || '' === $value ? array() : array( $value ) );
		return array_slice(
			array_values(
				array_map(
					static fn( $mode ): string => sanitize_key( is_scalar( $mode ) ? (string) $mode : '' ),
					$modes
				)
			),
			0,
			Search_Service::MAX_TERMS
		);
	}

	/**
	 * Sanitizes and bounds a single text value.
	 *
	 * @param mixed $value Raw request value.
	 * @return string Sanitized, length-limited text.
	 */
	private function bounded_text( $value ): string {
		$text = sanitize_text_field( $this->scalar_text( $value ) );
		return function_exists( 'mb_substr' )
			? mb_substr( $text, 0, Search_Service::MAX_TERM_LENGTH )
			: substr( $text, 0, Search_Service::MAX_TERM_LENGTH );
	}

	/**
	 * Converts scalar input to text and rejects compound values.
	 *
	 * @param mixed $value Raw request value.
	 * @return string Scalar text or an empty string.
	 */
	private function scalar_text( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
