<?php
/**
 * Protected REST actions for post-editor search metadata ingestion.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Adapts editor REST requests to the existing ingestion workflow. */
final class Post_Ingestion_REST_Controller {
	private const REST_NAMESPACE = 'ehrman-discovery/v1';
	private const REST_BASE      = '/ingestion/post/(?P<post_id>\d+)';

	/** Registers the editor workflow endpoints. */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_BASE,
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'status' ),
				'permission_callback' => array( self::class, 'permission' ),
				'args'                => self::post_id_args(),
			)
		);

		foreach ( array( 'analyze', 'reanalyze', 'approve', 'retry-embedding' ) as $action ) {
			$callback = str_replace( '-', '_', $action );
			register_rest_route(
				self::REST_NAMESPACE,
				self::REST_BASE . '/' . $action,
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, $callback ),
					'permission_callback' => array( self::class, 'permission' ),
					'args'                => self::post_id_args(),
				)
			);
		}
	}

	/**
	 * Requires an administrator who can edit the selected post.
	 *
	 * WordPress cookie authentication also requires the standard REST nonce.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|WP_Error Permission result.
	 */
	public static function permission( WP_REST_Request $request ) {
		$post_id = self::request_post_id( $request );
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'ehrman_ingestion_forbidden',
				__( 'You are not allowed to manage search metadata for this post.', 'ehrman-blog-discovery' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Returns the current ingestion and vector state for one post.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public static function status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post = self::post( self::request_post_id( $request ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$draft = ( new Post_Ingestion_Service() )->latest_for_post( $post->ID );
		if ( null !== $draft && 'queued' === sanitize_key( Database::text( $draft['status'] ?? null ) ) ) {
			Post_Ingestion_Queue::schedule( Database::integer( $draft['id'] ?? null ) );
		}
		return self::response( $post, $draft );
	}

	/**
	 * Analyzes the saved, published WordPress post.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public static function analyze( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post = self::post( self::request_post_id( $request ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( 'publish' !== $post->post_status ) {
			return new WP_Error(
				'ehrman_ingestion_unpublished',
				__( 'Publish the post before generating search metadata.', 'ehrman-blog-discovery' ),
				array( 'status' => 409 )
			);
		}

		$service = new Post_Ingestion_Service();
		if ( null !== $service->latest_for_post( $post->ID ) ) {
			return new WP_Error(
				'ehrman_ingestion_exists',
				__( 'This post already has an ingestion record. Use Reanalyze or Review instead.', 'ehrman-blog-discovery' ),
				array( 'status' => 409 )
			);
		}
		$result = $service->analyze( Post_Ingestion_Post_Source::input( $post ), get_current_user_id(), $post->ID );
		if ( is_wp_error( $result ) ) {
			return self::service_error( $result );
		}
		return self::response( $post, $result, 202 );
	}

	/**
	 * Repeats AI analysis for the post's latest pending record.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public static function reanalyze( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post = self::post( self::request_post_id( $request ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( 'publish' !== $post->post_status ) {
			return new WP_Error(
				'ehrman_ingestion_unpublished',
				__( 'Publish the post before regenerating search metadata.', 'ehrman-blog-discovery' ),
				array( 'status' => 409 )
			);
		}
		$service = new Post_Ingestion_Service();
		$draft   = $service->latest_for_post( $post->ID );
		if ( null === $draft ) {
			return self::missing_record();
		}
		if ( Post_Ingestion_Service::analysis_is_active( $draft ) && ! Post_Ingestion_Service::analysis_is_stale( $draft ) ) {
			return self::service_error( new WP_Error( 'ehrman_ingestion_analysis_active', __( 'Analysis is already queued or running in the background.', 'ehrman-blog-discovery' ) ) );
		}
		$result = $service->reanalyze( Database::integer( $draft['id'] ?? null ) );
		if ( is_wp_error( $result ) ) {
			return self::service_error( $result );
		}
		return self::response( $post, $result, 202 );
	}

	/**
	 * Approves the current proposal and adds it to the discovery index.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public static function approve( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post = self::post( self::request_post_id( $request ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( 'publish' !== $post->post_status ) {
			return new WP_Error(
				'ehrman_ingestion_unpublished',
				__( 'Publish the post before approving search metadata.', 'ehrman-blog-discovery' ),
				array( 'status' => 409 )
			);
		}
		$service = new Post_Ingestion_Service();
		$draft   = $service->latest_for_post( $post->ID );
		if ( null === $draft ) {
			return self::missing_record();
		}
		$input = null;
		if ( $request->has_param( 'proposal' ) ) {
			$input = Database::associative_row( $request->get_param( 'proposal' ) );
			if ( null === $input ) {
				return new WP_Error( 'ehrman_ingestion_invalid_proposal', __( 'Reviewed metadata must be an object.', 'ehrman-blog-discovery' ), array( 'status' => 400 ) );
			}
			$proposal                       = Database::associative_row( json_decode( Database::text( $draft['proposal_json'] ?? null ), true ) ) ?? array();
			$input['topic_rationales_json'] = wp_json_encode( $proposal['topicRationales'] ?? array() );
		}
		$result = $service->approve(
			Database::integer( $draft['id'] ?? null ),
			rest_sanitize_boolean( Database::text( $request->get_param( 'approve_new_keywords' ) ) ),
			$input,
			null !== $input ? Database::text( $request->get_param( 'revision' ) ) : null
		);
		if ( is_wp_error( $result ) ) {
			return self::service_error( $result );
		}
		return self::response( $post, $result );
	}

	/**
	 * Retries semantic-vector generation for the approved post.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public static function retry_embedding( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post = self::post( self::request_post_id( $request ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$service = new Post_Ingestion_Service();
		$draft   = $service->latest_for_post( $post->ID );
		if ( null === $draft ) {
			return self::missing_record();
		}
		$result = $service->retry_embedding( Database::integer( $draft['id'] ?? null ) );
		if ( is_wp_error( $result ) ) {
			return self::service_error( $result );
		}
		return self::response( $post, $result );
	}

	/**
	 * Returns a WordPress post or a REST-ready error.
	 *
	 * @param int $post_id WordPress post identifier.
	 * @return WP_Post|WP_Error Post or error.
	 */
	private static function post( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
			return new WP_Error(
				'ehrman_ingestion_post_missing',
				__( 'The requested WordPress post was not found.', 'ehrman-blog-discovery' ),
				array( 'status' => 404 )
			);
		}
		return $post;
	}

	/**
	 * Returns a no-store editor response.
	 *
	 * @param WP_Post                  $post  WordPress post.
	 * @param array<string,mixed>|null $draft  Ingestion record.
	 * @param int                      $status HTTP response status.
	 */
	private static function response( WP_Post $post, ?array $draft, int $status = 200 ): WP_REST_Response {
		$draft_id       = null === $draft ? 0 : Database::integer( $draft['id'] ?? null );
		$source_changed = null !== $draft
			&& 'approved' !== sanitize_key( Database::text( $draft['status'] ?? null ) )
			&& Post_Ingestion_Post_Source::source_changed( $post, $draft );
		$response       = new WP_REST_Response(
			array(
				'postId'                => $post->ID,
				'postStatus'            => $post->post_status,
				'configured'            => Post_Ingestion_Service::is_configured(),
				'databaseAuthoritative' => Post_Ingestion_Service::database_is_authoritative(),
				'model'                 => Post_Ingestion_Service::model_id(),
				'reviewUrl'             => Post_Ingestion_Page::page_url( $draft_id ),
				'sourceChanged'         => $source_changed,
				'draft'                 => self::draft_payload( $draft ),
			),
			$status
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Creates the safe subset of an ingestion record needed by the editor.
	 *
	 * @param array<string,mixed>|null $draft Ingestion record.
	 * @return array<string,mixed>|null Editor payload.
	 */
	private static function draft_payload( ?array $draft ): ?array {
		if ( null === $draft ) {
			return null;
		}
		$proposal = Database::associative_row( json_decode( Database::text( $draft['proposal_json'] ?? null ), true ) ) ?? array();
		return array(
			'id'                   => Database::integer( $draft['id'] ?? null ),
			'revision'             => Post_Ingestion_Review_Workflow::revision( $draft ),
			'status'               => sanitize_key( Database::text( $draft['status'] ?? null ) ),
			'updatedAt'            => Database::text( $draft['updated_at'] ?? null ),
			'description'          => Database::text( $proposal['description'] ?? null ),
			'searchSummary'        => Database::text( $proposal['searchSummary'] ?? null ),
			'topics'               => self::string_list( $proposal['topics'] ?? null ),
			'secondaryKeywords'    => self::string_list( $proposal['secondaryKeywords'] ?? null ),
			'newSecondaryKeywords' => self::string_list( $proposal['newSecondaryKeywords'] ?? null ),
			'reviewNotes'          => self::string_list( $proposal['reviewNotes'] ?? null ),
			'errorMessage'         => Database::text( $draft['error_message'] ?? null ),
			'embeddingStatus'      => sanitize_key( Database::text( $draft['embedding_status'] ?? null ) ),
			'embeddingError'       => Database::text( $draft['embedding_error'] ?? null ),
			'estimatedCostUsd'     => (float) Database::text( $draft['estimated_cost_usd'] ?? null ),
			'embeddingCostUsd'     => (float) Database::text( $draft['embedding_estimated_cost_usd'] ?? null ),
			'analysisActive'       => Post_Ingestion_Service::analysis_is_active( $draft ),
			'analysisStalled'      => Post_Ingestion_Service::analysis_is_stale( $draft ),
		);
	}

	/**
	 * Returns scalar array values as strings.
	 *
	 * @param mixed $value Candidate values.
	 * @return list<string> String values.
	 */
	private static function string_list( $value ): array {
		$strings = array();
		foreach ( is_array( $value ) ? $value : array() as $item ) {
			if ( is_scalar( $item ) ) {
				$strings[] = (string) $item;
			}
		}
		return $strings;
	}

	/**
	 * Returns REST argument definitions shared by each route.
	 *
	 * @return array<string,array<string,mixed>> Route arguments.
	 */
	private static function post_id_args(): array {
		return array(
			'post_id' => array(
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => static fn( $value ): bool => absint( Database::text( $value ) ) > 0,
			),
		);
	}

	/**
	 * Extracts the sanitized WordPress post identifier from a REST request.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	private static function request_post_id( WP_REST_Request $request ): int {
		return absint( Database::text( $request->get_param( 'post_id' ) ) );
	}

	/**
	 * Adds an appropriate REST status to a workflow error.
	 *
	 * @param WP_Error $error Workflow error.
	 */
	private static function service_error( WP_Error $error ): WP_Error {
		$code   = (string) $error->get_error_code();
		$status = str_contains( $code, 'unavailable' ) || str_contains( $code, 'response' ) ? 502 : 400;
		if ( str_contains( $code, 'active' ) || in_array( $code, array( 'ehrman_ingestion_source_changed', 'ehrman_ingestion_review_changed', 'ehrman_ingestion_unpublished' ), true ) ) {
			$status = 409;
		} elseif ( str_contains( $code, 'queue' ) ) {
			$status = 503;
		}
		$error->add_data( array( 'status' => $status ) );
		return $error;
	}

	/** Returns a standard missing-workflow error. */
	private static function missing_record(): WP_Error {
		return new WP_Error(
			'ehrman_ingestion_missing_draft',
			__( 'This post does not have an ingestion record yet.', 'ehrman-blog-discovery' ),
			array( 'status' => 404 )
		);
	}
}
