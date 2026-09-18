<?php
/**
 * Post-ingestion semantic-vector generation.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Generates an approved post vector and records its audit status. */
final class Post_Ingestion_Embedding_Generator {
	/**
	 * Ingestion persistence.
	 *
	 * @var Post_Ingestion_Repository
	 */
	private Post_Ingestion_Repository $repository;

	/**
	 * Creates the vector generator.
	 *
	 * @param Post_Ingestion_Repository|null $repository Optional ingestion persistence.
	 */
	public function __construct( ?Post_Ingestion_Repository $repository = null ) {
		$this->repository = $repository ?? new Post_Ingestion_Repository();
	}

	/**
	 * Generates a content vector and records a visible pending state on failure.
	 *
	 * @param int $draft_id     Draft identifier.
	 * @param int $source_wp_id Source WordPress post identifier.
	 */
	public function generate( int $draft_id, int $source_wp_id ): void {
		$key = Post_Ingestion_Settings::api_key();
		if ( '' === $key ) {
			$this->repository->mark_embedding_pending(
				$draft_id,
				__( 'The ingestion API key is not configured.', 'ehrman-blog-discovery' )
			);
			return;
		}

		$client  = new Embedding_Service( $key, false );
		$service = new Semantic_Index_Service( $client );
		$result  = $service->build_post_embedding( $source_wp_id, true );
		if ( is_wp_error( $result ) ) {
			$this->repository->mark_embedding_pending( $draft_id, $result->get_error_message(), Embedding_Service::model_id() );
			return;
		}
		$this->repository->mark_embedding_complete( $draft_id, $result['metrics'] );
	}
}
