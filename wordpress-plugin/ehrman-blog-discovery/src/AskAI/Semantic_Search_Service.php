<?php
/**
 * Semantic search request coordination.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Coordinates query embeddings, caching, candidate retrieval and ranking. */
final class Semantic_Search_Service {
	public const CANDIDATE_LIMIT   = 100;
	public const PIPELINE_VERSION  = 'hybrid-1';
	private const RETRIEVAL_METHOD = 'hybrid';
	private const CACHE_SECONDS    = DAY_IN_SECONDS;

	/**
	 * Embedding API client.
	 *
	 * @var Embedding_Service
	 */
	private Embedding_Service $embeddings;

	/**
	 * Eligible candidate and assigned-term database reads.
	 *
	 * @var Semantic_Post_Repository
	 */
	private Semantic_Post_Repository $repository;

	/**
	 * Deterministic semantic and hybrid scoring.
	 *
	 * @var Semantic_Post_Ranker
	 */
	private Semantic_Post_Ranker $ranker;

	/**
	 * Creates the semantic search service.
	 *
	 * @param Embedding_Service|null $embeddings Optional workflow-specific embedding client.
	 */
	public function __construct( ?Embedding_Service $embeddings = null ) {
		$this->embeddings = $embeddings ?? new Embedding_Service();
		$this->repository = new Semantic_Post_Repository();
		$this->ranker     = new Semantic_Post_Ranker();
	}

	/**
	 * Returns the strongest semantic title-and-summary matches.
	 *
	 * @param string $question   Reader question.
	 * @param string $request_id Correlation identifier for usage analytics.
	 * @param int    $limit      Maximum candidate count.
	 * @return array{posts:list<array<string,mixed>>,count:int,cache_hit:bool,stale:int,strategy:string}|WP_Error Candidates or error.
	 */
	public function search( string $question, string $request_id = '', int $limit = self::CANDIDATE_LIMIT ) {
		$question = sanitize_text_field( $question );
		if ( '' === trim( $question ) ) {
			return new WP_Error( 'ehrman_semantic_empty', __( 'Enter a question to search.', 'ehrman-blog-discovery' ), array( 'status' => 400 ) );
		}
		$limit     = max( 1, min( self::CANDIDATE_LIMIT, $limit ) );
		$cache_key = 'ebd_semantic_query_' . hash( 'sha256', Search_Service::normalize( $question ) . '|' . Embedding_Service::model_id() . '|' . Embedding_Service::dimensions() . '|' . AI_Usage::cache_version() );
		$cached    = get_transient( $cache_key );
		$cache_hit = is_array( $cached ) && count( $cached ) === Embedding_Service::dimensions();
		if ( $cache_hit ) {
			$query_vector = array_map( static fn( mixed $value ): float => is_scalar( $value ) ? (float) $value : 0.0, array_values( $cached ) );
			AI_Usage::record_cache_hit( Embedding_Service::model_id(), $request_id );
		} else {
			$vectors = $this->embeddings->embed( array( $question ), $request_id );
			if ( is_wp_error( $vectors ) ) {
				return $vectors;
			}
			$query_vector = $vectors[0];
			set_transient( $cache_key, $query_vector, self::CACHE_SECONDS );
		}

		$query_norm = Semantic_Post_Ranker::vector_norm( $query_vector );
		if ( $query_norm <= 0.0 ) {
			return new WP_Error( 'ehrman_semantic_invalid_vector', __( 'Semantic search could not prepare this question.', 'ehrman-blog-discovery' ), array( 'status' => 502 ) );
		}
		$matches = $this->ranker->semantic_matches( $query_vector, $query_norm, $this->repository->candidate_rows() );
		$ranked  = $matches['posts'];
		$stale   = $matches['stale'];
		if ( empty( $ranked ) ) {
			return new WP_Error( 'ehrman_semantic_index_empty', __( 'The semantic search index has not been built yet.', 'ehrman-blog-discovery' ), array( 'status' => 503 ) );
		}
		$ranked = $this->ranker->hybrid_rank( $question, $ranked, $this->repository->assigned_terms() );
		$posts  = array_slice( $ranked, 0, $limit );
		return array(
			'posts'     => $posts,
			'count'     => count( $posts ),
			'cache_hit' => $cache_hit,
			'stale'     => $stale,
			'strategy'  => self::RETRIEVAL_METHOD,
		);
	}

	/** Returns the analytics version for the selected retrieval pipeline. */
	public static function pipeline_version(): string {
		return self::PIPELINE_VERSION;
	}
}
