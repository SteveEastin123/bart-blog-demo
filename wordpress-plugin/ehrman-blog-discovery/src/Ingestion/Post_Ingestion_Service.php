<?php
/**
 * Administrator post-ingestion workflow coordination.
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
 * Coordinates validation, analysis, approval, persistence, and vector generation.
 *
 * @phpstan-type ValidatedPost array{source_wp_id:int,title:string,url:string,author:string,date_text:string,published_at:string,post_text:string}
 * @phpstan-type TopicRecord array{id:int,name:string,description:string,aliases:list<string>}
 * @phpstan-type KeywordRecord array{id:int,label:string,normalized:string,count:int}
 * @phpstan-type Vocabulary array{topics:list<TopicRecord>,keywords:list<KeywordRecord>}
 * @phpstan-type TopicRationale array{topic:string,rationale:string}
 * @phpstan-type Proposal array{description:string,searchSummary:string,topics:list<string>,topicRationales:list<TopicRationale>,secondaryKeywords:list<string>,newSecondaryKeywords:list<string>,status:string,reviewNotes:list<string>}
 */
final class Post_Ingestion_Service {
	private const STATUS_ANALYZING = 'analyzing';
	private const STATUS_APPROVED  = 'approved';
	private const STATUS_QUEUED    = 'queued';
	private const STATUS_READY     = 'ready';
	private const STALE_AFTER      = 10 * MINUTE_IN_SECONDS;

	/**
	 * Ingestion persistence.
	 *
	 * @var Post_Ingestion_Repository
	 */
	private Post_Ingestion_Repository $repository;

	/**
	 * Submission and proposal validation.
	 *
	 * @var Post_Ingestion_Validator
	 */
	private Post_Ingestion_Validator $validator;

	/**
	 * OpenAI post analysis.
	 *
	 * @var Post_Ingestion_Analyzer
	 */
	private Post_Ingestion_Analyzer $analyzer;

	/**
	 * Approved-post vector generation.
	 *
	 * @var Post_Ingestion_Embedding_Generator
	 */
	private Post_Ingestion_Embedding_Generator $embedding_generator;

	/**
	 * Transactional approved-post writer.
	 *
	 * @var Post_Ingestion_Approval_Writer
	 */
	private Post_Ingestion_Approval_Writer $approval_writer;

	/**
	 * Creates the ingestion workflow.
	 *
	 * Optional collaborators preserve the simple production constructor while allowing focused tests.
	 *
	 * @param Post_Ingestion_Repository|null          $repository          Optional ingestion persistence.
	 * @param Post_Ingestion_Validator|null           $validator           Optional proposal validator.
	 * @param Post_Ingestion_Analyzer|null            $analyzer            Optional AI analyzer.
	 * @param Post_Ingestion_Embedding_Generator|null $embedding_generator Optional vector generator.
	 * @param Post_Ingestion_Approval_Writer|null     $approval_writer     Optional approved-post writer.
	 */
	public function __construct(
		?Post_Ingestion_Repository $repository = null,
		?Post_Ingestion_Validator $validator = null,
		?Post_Ingestion_Analyzer $analyzer = null,
		?Post_Ingestion_Embedding_Generator $embedding_generator = null,
		?Post_Ingestion_Approval_Writer $approval_writer = null
	) {
		$this->repository          = $repository ?? new Post_Ingestion_Repository();
		$this->validator           = $validator ?? new Post_Ingestion_Validator();
		$this->analyzer            = $analyzer ?? new Post_Ingestion_Analyzer( $this->validator );
		$this->embedding_generator = $embedding_generator ?? new Post_Ingestion_Embedding_Generator( $this->repository );
		$this->approval_writer     = $approval_writer ?? new Post_Ingestion_Approval_Writer();
	}

	/** Returns whether the dedicated ingestion project key is configured. */
	public static function is_configured(): bool {
		return Post_Ingestion_Settings::is_configured();
	}

	/** Returns the configured ingestion model. */
	public static function model_id(): string {
		return Post_Ingestion_Settings::model_id();
	}

	/** Returns the editorial-prompt version recorded with each draft. */
	public static function prompt_version(): string {
		return Post_Ingestion_Settings::prompt_version();
	}

	/** Returns the active post source-of-truth mode. */
	public static function post_source(): string {
		return Post_Ingestion_Settings::post_source();
	}

	/** Returns whether approval may modify the live discovery index. */
	public static function database_is_authoritative(): bool {
		return Post_Ingestion_Settings::database_is_authoritative();
	}

	/**
	 * Validates post metadata, stores a pending draft, and queues its analysis.
	 *
	 * @param array<string,mixed> $input   Submitted metadata and full text.
	 * @param int                 $user_id Administrator user identifier.
	 * @return array<string,mixed>|WP_Error Stored draft or validation error.
	 */
	public function analyze( array $input, int $user_id ) {
		$validated = $this->validator->validate_submission( $input );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		if ( ! self::is_configured() ) {
			return new WP_Error(
				'ehrman_ingestion_not_configured',
				__( 'Configure EHRMAN_INGESTION_OPENAI_API_KEY before analyzing a post.', 'ehrman-blog-discovery' )
			);
		}

		$duplicate = $this->repository->duplicate_message( $validated['source_wp_id'], $validated['url'] );
		if ( '' !== $duplicate ) {
			return new WP_Error( 'ehrman_ingestion_duplicate', $duplicate );
		}
		$pending_duplicate = $this->repository->pending_duplicate_message( $validated['source_wp_id'], $validated['url'] );
		if ( '' !== $pending_duplicate ) {
			return new WP_Error( 'ehrman_ingestion_duplicate', $pending_duplicate );
		}

		$taxonomy      = $this->repository->vocabulary();
		$taxonomy_hash = $this->taxonomy_hash( $taxonomy );
		$draft_id      = $this->repository->create_draft( $validated, $taxonomy_hash, $user_id );
		if ( is_wp_error( $draft_id ) ) {
			return $draft_id;
		}

		return $this->schedule_draft( $draft_id );
	}

	/**
	 * Queues a stored draft after a temporary failure or requested revision.
	 *
	 * @param int $draft_id Draft identifier.
	 * @return array<string,mixed>|WP_Error Updated draft or error.
	 */
	public function reanalyze( int $draft_id ) {
		$draft = $this->draft( $draft_id );
		if ( null === $draft || self::STATUS_APPROVED === $draft['status'] ) {
			return new WP_Error( 'ehrman_ingestion_missing_draft', __( 'The pending ingestion draft was not found.', 'ehrman-blog-discovery' ) );
		}
		if ( ! self::is_configured() ) {
			return new WP_Error(
				'ehrman_ingestion_not_configured',
				__( 'Configure EHRMAN_INGESTION_OPENAI_API_KEY before reanalyzing a post.', 'ehrman-blog-discovery' )
			);
		}
		if ( '' === trim( Database::text( $draft['post_text'] ?? null ) ) ) {
			return new WP_Error( 'ehrman_ingestion_missing_text', __( 'The draft no longer contains full post text.', 'ehrman-blog-discovery' ) );
		}
		$status = sanitize_key( Database::text( $draft['status'] ?? null ) );
		if ( self::STATUS_QUEUED === $status ) {
			return $this->schedule_draft( $draft_id );
		}
		if ( self::STATUS_ANALYZING === $status && ! self::analysis_is_stale( $draft ) ) {
			return new WP_Error( 'ehrman_ingestion_analysis_active', __( 'Analysis is already running in the background.', 'ehrman-blog-discovery' ) );
		}

		$taxonomy      = $this->repository->vocabulary();
		$taxonomy_hash = $this->taxonomy_hash( $taxonomy );
		if ( ! $this->repository->queue_analysis( $draft_id, $taxonomy_hash ) ) {
			return new WP_Error( 'ehrman_ingestion_storage_error', __( 'The ingestion draft could not be queued for analysis.', 'ehrman-blog-discovery' ) );
		}
		Post_Ingestion_Queue::cancel( $draft_id );
		return $this->schedule_draft( $draft_id );
	}

	/**
	 * Refreshes a pending draft from a saved WordPress post before reanalysis.
	 *
	 * @param int                 $draft_id Draft identifier.
	 * @param array<string,mixed> $input    Current saved post values.
	 * @return array<string,mixed>|WP_Error Updated draft or error.
	 */
	public function reanalyze_post( int $draft_id, array $input ) {
		$draft = $this->draft( $draft_id );
		if ( null === $draft || self::STATUS_APPROVED === $draft['status'] ) {
			return new WP_Error( 'ehrman_ingestion_missing_draft', __( 'The pending ingestion draft was not found.', 'ehrman-blog-discovery' ) );
		}
		if ( ! self::is_configured() ) {
			return new WP_Error(
				'ehrman_ingestion_not_configured',
				__( 'Configure EHRMAN_INGESTION_OPENAI_API_KEY before reanalyzing a post.', 'ehrman-blog-discovery' )
			);
		}
		if ( self::analysis_is_active( $draft ) && ! self::analysis_is_stale( $draft ) ) {
			return new WP_Error( 'ehrman_ingestion_analysis_active', __( 'Analysis is already queued or running in the background.', 'ehrman-blog-discovery' ) );
		}
		$validated = $this->validator->validate_submission( $input );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		if ( Database::integer( $draft['source_wp_id'] ?? null ) !== $validated['source_wp_id'] ) {
			return new WP_Error( 'ehrman_ingestion_post_mismatch', __( 'The saved post does not match this ingestion draft.', 'ehrman-blog-discovery' ) );
		}
		if ( ! $this->repository->update_pending_source( $draft_id, $validated ) ) {
			return new WP_Error( 'ehrman_ingestion_storage_error', __( 'The saved post could not be copied into the ingestion draft.', 'ehrman-blog-discovery' ) );
		}
		return $this->reanalyze( $draft_id );
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
		$draft = $this->draft( $draft_id );
		if ( null === $draft ) {
			return new WP_Error( 'ehrman_ingestion_missing_draft', __( 'The queued ingestion draft was not found.', 'ehrman-blog-discovery' ) );
		}
		if ( ! self::is_configured() ) {
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
			$taxonomy_hash = $this->taxonomy_hash( $taxonomy );
			$result        = $this->analyzer->analyze( $input, $taxonomy, $taxonomy_hash, Database::integer( $draft['created_by'] ?? null ) );
			if ( is_wp_error( $result ) ) {
				$this->repository->mark_analysis_error( $draft_id, $result->get_error_message(), $attempt );
				return $result;
			}
			if ( ! $this->repository->store_analysis( $draft_id, $result['proposal'], $result['metrics'], $taxonomy_hash, $attempt ) ) {
				return $this->draft( $draft_id );
			}
		} catch ( Throwable $error ) {
			$message = __( 'Background analysis stopped unexpectedly. Retry the analysis.', 'ehrman-blog-discovery' );
			$this->repository->mark_analysis_error( $draft_id, $message, $attempt );
			return new WP_Error( 'ehrman_ingestion_analysis_failed', $message, $error->getMessage() );
		}

		$stored = $this->draft( $draft_id );
		return null === $stored
			? new WP_Error( 'ehrman_ingestion_storage_error', __( 'The analyzed draft could not be reloaded.', 'ehrman-blog-discovery' ) )
			: $stored;
	}

	/**
	 * Stores administrator revisions without modifying the live index.
	 *
	 * @param int                 $draft_id Draft identifier.
	 * @param array<string,mixed> $input    Revised proposal fields.
	 * @return array<string,mixed>|WP_Error Updated draft or error.
	 */
	public function save_proposal( int $draft_id, array $input ) {
		$draft = $this->draft( $draft_id );
		if ( null === $draft || self::STATUS_APPROVED === $draft['status'] ) {
			return new WP_Error( 'ehrman_ingestion_missing_draft', __( 'The pending ingestion draft was not found.', 'ehrman-blog-discovery' ) );
		}
		$proposal = $this->validator->proposal_from_input( $input );
		$checked  = $this->validator->validate_proposal(
			$proposal,
			$this->repository->vocabulary(),
			true,
			false,
			Database::text( $draft['title'] ?? null )
		);
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		$encoded = wp_json_encode( $checked );
		if ( ! is_string( $encoded ) ) {
			return new WP_Error( 'ehrman_ingestion_storage_error', __( 'The revised proposal could not be encoded.', 'ehrman-blog-discovery' ) );
		}
		$this->repository->store_proposal( $draft_id, $encoded, $checked['status'] );

		$stored = $this->draft( $draft_id );
		return null === $stored
			? new WP_Error( 'ehrman_ingestion_storage_error', __( 'The revised draft could not be reloaded.', 'ehrman-blog-discovery' ) )
			: $stored;
	}

	/**
	 * Applies an approved proposal to the live MySQL search index.
	 *
	 * @param int  $draft_id            Draft identifier.
	 * @param bool $approve_new_keywords Explicit approval for proposed new keywords.
	 * @return array<string,mixed>|WP_Error Approved draft or error.
	 */
	public function approve( int $draft_id, bool $approve_new_keywords ) {
		if ( ! self::database_is_authoritative() ) {
			return new WP_Error(
				'ehrman_ingestion_json_authoritative',
				__( 'Approval is disabled while authoritative post data comes from JSON. Set EHRMAN_DISCOVERY_POST_SOURCE=mysql only after the source-of-truth handoff.', 'ehrman-blog-discovery' )
			);
		}
		$draft = $this->draft( $draft_id );
		if ( null === $draft || self::STATUS_APPROVED === $draft['status'] ) {
			return new WP_Error( 'ehrman_ingestion_missing_draft', __( 'The pending ingestion draft was not found.', 'ehrman-blog-discovery' ) );
		}

		$proposal = $this->validator->decode_proposal( Database::text( $draft['proposal_json'] ?? null ) );
		$checked  = $this->validator->validate_proposal(
			$proposal,
			$this->repository->vocabulary(),
			false,
			false,
			Database::text( $draft['title'] ?? null )
		);
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		if ( self::STATUS_READY !== $checked['status'] ) {
			return new WP_Error( 'ehrman_ingestion_held', __( 'Assign a suitable existing topic and mark the proposal ready before approval.', 'ehrman-blog-discovery' ) );
		}
		if ( ! empty( $checked['newSecondaryKeywords'] ) && ! $approve_new_keywords ) {
			return new WP_Error( 'ehrman_ingestion_new_keywords', __( 'Explicitly approve the proposed new secondary keywords before applying this post.', 'ehrman-blog-discovery' ) );
		}

		$current_taxonomy = $this->repository->vocabulary();
		$approved_json    = wp_json_encode( $checked );
		if ( ! is_string( $approved_json ) ) {
			return new WP_Error( 'ehrman_ingestion_storage_error', __( 'The approved proposal could not be encoded.', 'ehrman-blog-discovery' ) );
		}
		if ( ! hash_equals( Database::text( $draft['taxonomy_version'] ?? null ), $this->taxonomy_hash( $current_taxonomy ) ) ) {
			return new WP_Error( 'ehrman_ingestion_taxonomy_changed', __( 'The topic or keyword vocabulary changed after analysis. Reanalyze the draft before approval.', 'ehrman-blog-discovery' ) );
		}

		$source_wp_id = Database::integer( $draft['source_wp_id'] ?? null );
		$url          = Database::text( $draft['url'] ?? null );
		$duplicate    = $this->repository->duplicate_message( $source_wp_id, $url );
		if ( '' !== $duplicate ) {
			return new WP_Error( 'ehrman_ingestion_duplicate', $duplicate );
		}

		$post_id = $this->approval_writer->approve( $draft_id, $draft, $checked, $current_taxonomy, $approved_json );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		AI_Usage::invalidate_search_caches();
		if ( ! in_array( 'Ignore', $checked['topics'], true ) ) {
			$this->embedding_generator->generate( $draft_id, $source_wp_id );
		}
		$stored = $this->draft( $draft_id );
		return null === $stored
			? new WP_Error( 'ehrman_ingestion_storage_error', __( 'The approved audit record could not be reloaded.', 'ehrman-blog-discovery' ) )
			: $stored;
	}

	/**
	 * Retries vector generation for an approved post.
	 *
	 * @param int $draft_id Draft identifier.
	 * @return array<string,mixed>|WP_Error Updated audit record or error.
	 */
	public function retry_embedding( int $draft_id ) {
		$draft = $this->draft( $draft_id );
		if ( null === $draft || self::STATUS_APPROVED !== $draft['status'] ) {
			return new WP_Error( 'ehrman_ingestion_missing_draft', __( 'The approved ingestion record was not found.', 'ehrman-blog-discovery' ) );
		}
		$this->embedding_generator->generate( $draft_id, Database::integer( $draft['source_wp_id'] ?? null ) );
		$stored = $this->draft( $draft_id );
		return null === $stored
			? new WP_Error( 'ehrman_ingestion_storage_error', __( 'The embedding audit record could not be reloaded.', 'ehrman-blog-discovery' ) )
			: $stored;
	}

	/**
	 * Deletes a pending draft and its retained full text.
	 *
	 * @param int $draft_id Draft identifier.
	 */
	public function discard( int $draft_id ): bool {
		$draft = $this->draft( $draft_id );
		if ( null === $draft || in_array( $draft['status'], array( self::STATUS_ANALYZING, self::STATUS_APPROVED ), true ) ) {
			return false;
		}
		Post_Ingestion_Queue::cancel( $draft_id );
		return $this->repository->delete_draft( $draft_id );
	}

	/**
	 * Returns whether a draft is waiting for or currently undergoing analysis.
	 *
	 * @param array<string,mixed> $draft Draft record.
	 */
	public static function analysis_is_active( array $draft ): bool {
		return in_array(
			sanitize_key( Database::text( $draft['status'] ?? null ) ),
			array( self::STATUS_QUEUED, self::STATUS_ANALYZING ),
			true
		);
	}

	/**
	 * Returns whether an analyzing draft has exceeded the recovery threshold.
	 *
	 * @param array<string,mixed> $draft Draft record.
	 */
	public static function analysis_is_stale( array $draft ): bool {
		if ( self::STATUS_ANALYZING !== sanitize_key( Database::text( $draft['status'] ?? null ) ) ) {
			return false;
		}
		$started = Database::text( $draft['analysis_started_at'] ?? $draft['updated_at'] ?? null );
		$updated = strtotime( $started . ' UTC' );
		return false !== $updated && time() - $updated >= self::STALE_AFTER;
	}

	/**
	 * Returns one ingestion draft.
	 *
	 * @param int $draft_id Draft identifier.
	 * @return array<string,mixed>|null Draft record.
	 */
	public function draft( int $draft_id ): ?array {
		return $this->repository->draft( $draft_id );
	}

	/**
	 * Returns the most recent ingestion record for a WordPress post.
	 *
	 * @param int $source_wp_id Source WordPress post identifier.
	 * @return array<string,mixed>|null Draft or approval record.
	 */
	public function latest_for_post( int $source_wp_id ): ?array {
		return $this->repository->latest_for_source_wp_id( $source_wp_id );
	}

	/**
	 * Returns the most recent ingestion record for each supplied WordPress post.
	 *
	 * @param array<int> $source_wp_ids Source WordPress post identifiers.
	 * @return array<int,array<string,mixed>> Records keyed by WordPress post ID.
	 * @phpstan-param list<int> $source_wp_ids
	 */
	public function latest_for_posts( array $source_wp_ids ): array {
		return $this->repository->latest_for_source_wp_ids( $source_wp_ids );
	}

	/** Returns the number of proposals requiring administrator review. */
	public function review_count(): int {
		return $this->repository->review_count();
	}

	/**
	 * Returns recent pending and approved ingestion records.
	 *
	 * @return list<array<string,mixed>> Draft records.
	 */
	public function drafts(): array {
		return $this->repository->drafts();
	}

	/**
	 * Returns approved vocabulary for review controls.
	 *
	 * @phpstan-return Vocabulary
	 */
	public function vocabulary(): array {
		return $this->repository->vocabulary();
	}

	/**
	 * Returns a stable hash of the current approved vocabulary.
	 *
	 * @param array<string,mixed> $taxonomy Approved vocabulary.
	 * @phpstan-param Vocabulary $taxonomy
	 */
	private function taxonomy_hash( array $taxonomy ): string {
		$taxonomy_json = wp_json_encode( $taxonomy );
		return hash( 'sha256', is_string( $taxonomy_json ) ? $taxonomy_json : '' );
	}

	/**
	 * Schedules a stored draft and returns its current state.
	 *
	 * @param int $draft_id Draft identifier.
	 * @return array<string,mixed>|WP_Error Queued draft or scheduling error.
	 */
	private function schedule_draft( int $draft_id ) {
		$scheduled = Post_Ingestion_Queue::schedule( $draft_id );
		if ( is_wp_error( $scheduled ) ) {
			$this->repository->mark_analysis_error( $draft_id, $scheduled->get_error_message() );
			return $scheduled;
		}
		$stored = $this->draft( $draft_id );
		return null === $stored
			? new WP_Error( 'ehrman_ingestion_storage_error', __( 'The queued draft could not be reloaded.', 'ehrman-blog-discovery' ) )
			: $stored;
	}
}
