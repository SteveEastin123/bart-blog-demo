<?php
/**
 * Background post-ingestion analysis.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use Throwable;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Claims queued drafts, runs AI analysis, and records its outcome.
 *
 * @phpstan-type TopicRecord array{id:int,name:string,description:string,aliases:list<string>}
 * @phpstan-type KeywordRecord array{id:int,label:string,normalized:string,count:int}
 * @phpstan-type Vocabulary array{topics:list<TopicRecord>,keywords:list<KeywordRecord>}
 */
final class Post_Ingestion_Analysis_Worker {
	/**
	 * Draft persistence.
	 *
	 * @var Post_Ingestion_Repository
	 */
	private Post_Ingestion_Repository $repository;

	/**
	 * AI post analyzer.
	 *
	 * @var Post_Ingestion_Analyzer
	 */
	private Post_Ingestion_Analyzer $analyzer;

	/**
	 * Creates the background worker.
	 *
	 * @param Post_Ingestion_Repository $repository Draft persistence.
	 * @param Post_Ingestion_Analyzer   $analyzer   AI post analyzer.
	 */
	public function __construct( Post_Ingestion_Repository $repository, Post_Ingestion_Analyzer $analyzer ) {
		$this->repository = $repository;
		$this->analyzer   = $analyzer;
	}

	/**
	 * Claims and processes one queued draft from the background worker.
	 *
	 * @param int $draft_id Draft identifier.
	 * @return array<string,mixed>|WP_Error|null Updated draft, error, or null when another worker claimed it.
	 */
	public function process_queued( int $draft_id ) {
		$attempt = $this->repository->claim_analysis( $draft_id );
		if ( $attempt < 1 ) {
			return null;
		}
		$draft = $this->repository->draft( $draft_id );
		if ( null === $draft ) {
			return new WP_Error( 'ehrman_ingestion_missing_draft', __( 'The queued ingestion draft was not found.', 'ehrman-blog-discovery' ) );
		}
		if ( ! Post_Ingestion_Settings::is_configured() ) {
			$error = new WP_Error( 'ehrman_ingestion_not_configured', __( 'Configure EHRMAN_INGESTION_OPENAI_API_KEY before analyzing a post.', 'ehrman-blog-discovery' ) );
			$this->repository->mark_analysis_error( $draft_id, $error->get_error_message(), $attempt );
			return $error;
		}
		$input = array(
			'source_wp_id' => Database::integer( $draft['source_wp_id'] ?? null ),
			'title'        => Database::text( $draft['title'] ?? null ),
			'url'          => Database::text( $draft['url'] ?? null ),
			'author'       => Database::text( $draft['author'] ?? null ),
			'date_text'    => Database::text( $draft['date_text'] ?? null ),
			'published_at' => Database::text( $draft['published_at'] ?? null ),
			'post_text'    => Database::text( $draft['post_text'] ?? null ),
		);
		if ( '' === trim( $input['post_text'] ) ) {
			$error = new WP_Error( 'ehrman_ingestion_missing_text', __( 'The draft no longer contains full post text.', 'ehrman-blog-discovery' ) );
			$this->repository->mark_analysis_error( $draft_id, $error->get_error_message(), $attempt );
			return $error;
		}

		try {
			$taxonomy      = $this->repository->vocabulary();
			$taxonomy_hash = $this->repository->vocabulary_hash( $taxonomy );
			$result        = $this->analyzer->analyze( $input, $taxonomy, $taxonomy_hash, Database::integer( $draft['created_by'] ?? null ) );
			if ( is_wp_error( $result ) ) {
				$this->repository->mark_analysis_error( $draft_id, $result->get_error_message(), $attempt );
				return $result;
			}
			if ( ! $this->repository->store_analysis( $draft_id, $result['proposal'], $result['metrics'], $taxonomy_hash, $attempt ) ) {
				return $this->repository->draft( $draft_id );
			}
		} catch ( Throwable $error ) {
			$message = __( 'Background analysis stopped unexpectedly. Retry the analysis.', 'ehrman-blog-discovery' );
			$this->repository->mark_analysis_error( $draft_id, $message, $attempt );
			return new WP_Error( 'ehrman_ingestion_analysis_failed', $message, $error->getMessage() );
		}

		$stored = $this->repository->draft( $draft_id );
		return null === $stored
			? new WP_Error( 'ehrman_ingestion_storage_error', __( 'The analyzed draft could not be reloaded.', 'ehrman-blog-discovery' ) )
			: $stored;
	}
}
