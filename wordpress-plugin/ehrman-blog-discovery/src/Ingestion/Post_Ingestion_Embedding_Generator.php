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
				__( 'The shared OpenAI API key is not configured. Set OPENAI_API_KEY on the server.', 'ehrman-blog-discovery' )
			);
			return;
		}

		$draft = $this->repository->draft( $draft_id );
		if ( null === $draft ) {
			return;
		}
		$client  = new Embedding_Service( $key, false, AI_Metadata_Usage::context( $draft ) );
		$service = new Semantic_Index_Service( $client );
		$result  = $service->build_post_embedding( $source_wp_id, true );
		if ( is_wp_error( $result ) ) {
			$this->repository->mark_embedding_pending( $draft_id, $result->get_error_message(), Embedding_Service::model_id() );
			return;
		}
		$this->repository->mark_embedding_complete( $draft_id, $result['metrics'] );
	}

	/**
	 * Prepares a revision vector without touching the live vector or metadata.
	 *
	 * @param array<string,mixed> $draft Pending revision.
	 * @param array<string,mixed> $proposal Reviewed proposal.
	 * @return array<string,mixed>|\WP_Error Prepared vector row and new-call metrics.
	 */
	public function prepare_revision( array $draft, array $proposal ): array|\WP_Error {
		if ( in_array( 'Ignore', Database::strings( $proposal['topics'] ?? array() ), true ) ) {
			return array(
				'row'     => null,
				'metrics' => array(),
			);
		}
		$post = array(
			'title'          => $draft['title'],
			'search_summary' => $proposal['searchSummary'],
		);
		$wpdb = Database::client();
		$row  = Database::associative_row( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE source_wp_id=%d', Database::tables()['post_embeddings'], Database::integer( $draft['source_wp_id'] ?? null ) ), ARRAY_A ) );
		if ( null !== $row && Semantic_Index_Service::content_hash( $post ) === $row['content_hash']
			&& Embedding_Service::model_id() === $row['model'] && Embedding_Service::dimensions() === Database::integer( $row['dimensions'] )
			&& strlen( Database::text( $row['embedding'] ?? null ) ) === 4 * Embedding_Service::dimensions()
			&& (float) Database::text( $row['embedding_norm'] ?? null ) > 0 ) {
			return array(
				'row'     => $row,
				'metrics' => array(),
			);
		}
		$client  = new Embedding_Service( Post_Ingestion_Settings::api_key(), false, AI_Metadata_Usage::context( $draft ) );
		$vectors = $client->embed( array( Semantic_Index_Service::content_text( $post ) ) );
		if ( is_wp_error( $vectors ) ) {
			return $vectors;
		}
		$norm = sqrt( array_sum( array_map( static fn( float $value ): float => $value * $value, $vectors[0] ) ) );
		return array(
			'row'     => array(
				'source_wp_id'   => Database::integer( $draft['source_wp_id'] ?? null ),
				'content_hash'   => Semantic_Index_Service::content_hash( $post ),
				'model'          => Embedding_Service::model_id(),
				'dimensions'     => Embedding_Service::dimensions(),
				'embedding'      => pack( 'g*', ...$vectors[0] ),
				'embedding_norm' => number_format( $norm, 12, '.', '' ),
				'updated_at'     => current_time( 'mysql', true ),
			),
			'metrics' => $client->last_metrics(),
		);
	}
}
