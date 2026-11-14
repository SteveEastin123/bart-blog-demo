<?php
/**
 * Administrator review and approval of post-ingestion proposals.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Saves reviewed proposals and coordinates approved-post writes. */
final class Post_Ingestion_Review_Workflow {
	private const STATUS_APPROVED = 'approved';
	private const STATUS_READY    = 'ready';

	/**
	 * Draft persistence and vocabulary.
	 *
	 * @var Post_Ingestion_Repository
	 */
	private Post_Ingestion_Repository $repository;

	/**
	 * Proposal validation.
	 *
	 * @var Post_Ingestion_Validator
	 */
	private Post_Ingestion_Validator $validator;

	/**
	 * Transactional approved-post writer.
	 *
	 * @var Post_Ingestion_Approval_Writer
	 */
	private Post_Ingestion_Approval_Writer $approval_writer;

	/**
	 * Approved-post vector generation.
	 *
	 * @var Post_Ingestion_Embedding_Generator
	 */
	private Post_Ingestion_Embedding_Generator $embedding_generator;

	/**
	 * Creates the review workflow.
	 *
	 * @param Post_Ingestion_Repository          $repository          Draft persistence and vocabulary.
	 * @param Post_Ingestion_Validator           $validator           Proposal validation.
	 * @param Post_Ingestion_Approval_Writer     $approval_writer     Transactional approved-post writer.
	 * @param Post_Ingestion_Embedding_Generator $embedding_generator Approved-post vector generator.
	 */
	public function __construct(
		Post_Ingestion_Repository $repository,
		Post_Ingestion_Validator $validator,
		Post_Ingestion_Approval_Writer $approval_writer,
		Post_Ingestion_Embedding_Generator $embedding_generator
	) {
		$this->repository          = $repository;
		$this->validator           = $validator;
		$this->approval_writer     = $approval_writer;
		$this->embedding_generator = $embedding_generator;
	}

	/**
	 * Stores administrator revisions without modifying the live index.
	 *
	 * @param int                 $draft_id Draft identifier.
	 * @param array<string,mixed> $input    Revised proposal fields.
	 * @param string|null         $revision Reviewed proposal revision, when supplied by an interface.
	 * @return array<string,mixed>|WP_Error Updated draft or error.
	 */
	public function save_proposal( int $draft_id, array $input, ?string $revision = null ) {
		$draft = $this->reviewable_draft( $draft_id, $revision );
		if ( is_wp_error( $draft ) ) {
			return $draft;
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
		if ( ! $this->repository->store_proposal( $draft, $encoded, $checked['status'] ) ) {
			return new WP_Error( 'ehrman_ingestion_review_changed', __( 'The metadata changed before the revisions could be saved. Reload its current status.', 'ehrman-blog-discovery' ) );
		}

		$stored = $this->repository->draft( $draft_id );
		return null === $stored
			? new WP_Error( 'ehrman_ingestion_storage_error', __( 'The revised draft could not be reloaded.', 'ehrman-blog-discovery' ) )
			: $stored;
	}

	/**
	 * Applies an approved proposal to the live MySQL search index.
	 *
	 * @param int                      $draft_id            Draft identifier.
	 * @param bool                     $approve_new_keywords Explicit approval for proposed new keywords.
	 * @param array<string,mixed>|null $input Optional reviewed fields from the post editor.
	 * @param string|null              $revision Reviewed proposal revision, when supplied by an interface.
	 * @param bool                     $automatic Internal publishing worker.
	 * @return array<string,mixed>|WP_Error Approved draft or error.
	 */
	public function approve( int $draft_id, bool $approve_new_keywords, ?array $input = null, ?string $revision = null, bool $automatic = false ) {
		$draft = $this->reviewable_draft( $draft_id, $revision, $automatic );
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}
		if ( ! Post_Ingestion_Settings::database_is_authoritative() ) {
			return new WP_Error(
				'ehrman_ingestion_json_authoritative',
				__( 'Approval is disabled in this JSON-managed environment. Ask an administrator to enable database-managed publishing before approving metadata.', 'ehrman-blog-discovery' )
			);
		}
		$proposal = $this->validator->decode_proposal( Database::text( $draft['proposal_json'] ?? null ) );
		if ( null !== $input ) {
			$proposal = $this->validator->proposal_from_input( $input );
		}
		$checked = $this->validator->validate_proposal(
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
		if ( ! $this->repository->vocabulary_matches( Database::text( $draft['taxonomy_version'] ?? null ), $current_taxonomy ) ) {
			return new WP_Error( 'ehrman_ingestion_taxonomy_changed', __( 'The topic or keyword vocabulary changed after analysis. Reanalyze the draft before approval.', 'ehrman-blog-discovery' ) );
		}

		$source_wp_id = Database::integer( $draft['source_wp_id'] ?? null );
		$url          = Database::text( $draft['url'] ?? null );
		$is_revision  = Database::integer( $draft['revision_parent_id'] ?? null ) > 0;
		$duplicate    = $is_revision ? '' : $this->repository->duplicate_message( $source_wp_id, $url );
		if ( '' !== $duplicate ) {
			return new WP_Error( 'ehrman_ingestion_duplicate', $duplicate );
		}

		$vector = null;
		if ( $is_revision ) {
			if ( ! Post_Ingestion_Revisions::matches( $draft, Post_Ingestion_Revisions::live_post( $draft ) ) ) {
				return new WP_Error( 'ehrman_ingestion_review_changed', __( 'The live metadata changed. Cancel this revision and start from the current version.', 'ehrman-blog-discovery' ) );
			}
			$vector = $this->embedding_generator->prepare_revision( $draft, $checked );
			if ( is_wp_error( $vector ) ) {
				return new WP_Error( 'ehrman_ingestion_embedding_failed', __( 'Changes were not approved because the replacement vector could not be created. Existing search metadata is unchanged. ', 'ehrman-blog-discovery' ) . $vector->get_error_message() );
			}
		}
		$post_id = $this->approval_writer->approve( $draft_id, $draft, $checked, $current_taxonomy, $approved_json, $vector, $automatic );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		AI_Usage::invalidate_search_caches();
		if ( ! $is_revision && ! in_array( 'Ignore', $checked['topics'], true ) ) {
			$this->embedding_generator->generate( $draft_id, $source_wp_id );
		}
		$stored = $this->repository->draft( $draft_id );
		return null === $stored
			? new WP_Error( 'ehrman_ingestion_storage_error', __( 'The approved audit record could not be reloaded.', 'ehrman-blog-discovery' ) )
			: $stored;
	}

	/**
	 * Identifies the proposal that the publisher actually reviewed.
	 *
	 * @param array<string,mixed> $draft Stored ingestion record.
	 */
	public static function revision( array $draft ): string {
		$values = array();
		foreach ( array( 'id', 'status', 'proposal_json', 'taxonomy_version', 'analysis_attempt', 'updated_at' ) as $field ) {
			$values[] = Database::text( $draft[ $field ] ?? null );
		}
		return hash( 'sha256', (string) wp_json_encode( $values ) );
	}

	/**
	 * Allows text edits and removal of AI selections without accepting additions.
	 *
	 * @param array<string,mixed> $draft Stored proposal being reviewed.
	 * @param array<string,mixed> $input Submitted text edits and removed labels.
	 * @return array<string,mixed>|WP_Error Complete review input or invalid removals.
	 */
	public static function review_input( array $draft, array $input ): array|WP_Error {
		$proposal         = Database::associative_row( json_decode( Database::text( $draft['proposal_json'] ?? null ), true ) ) ?? array();
		$topics           = Database::strings( $proposal['topics'] ?? array() );
		$keywords         = Database::strings( $proposal['secondaryKeywords'] ?? array() );
		$new              = Database::strings( $proposal['newSecondaryKeywords'] ?? array() );
		$removed_topics   = self::removed_labels( array_key_exists( 'removed_topics', $input ) ? $input['removed_topics'] : array(), $topics );
		$removed_keywords = self::removed_labels( array_key_exists( 'removed_keywords', $input ) ? $input['removed_keywords'] : array(), array_merge( $keywords, $new ) );
		if ( is_wp_error( $removed_topics ) ) {
			return $removed_topics;
		}
		if ( is_wp_error( $removed_keywords ) ) {
			return $removed_keywords;
		}
		$topics = array_values( array_diff( $topics, $removed_topics ) );
		if ( 'ready' === ( $draft['status'] ?? '' ) && empty( $topics ) ) {
			return new WP_Error( 'ehrman_ingestion_invalid_proposal', __( 'Keep at least one topic. Restore a removed topic or regenerate metadata.', 'ehrman-blog-discovery' ) );
		}
		$rationales = array();
		foreach ( is_array( $proposal['topicRationales'] ?? null ) ? $proposal['topicRationales'] : array() as $rationale ) {
			if ( is_array( $rationale ) && in_array( $rationale['topic'] ?? null, $topics, true ) ) {
				$rationales[] = $rationale;
			}
		}
		return array(
			'description'            => $input['description'] ?? $proposal['description'] ?? '',
			'search_summary'         => $input['search_summary'] ?? $proposal['searchSummary'] ?? '',
			'topics'                 => $topics,
			'secondary_keywords'     => implode( "\n", array_diff( $keywords, $removed_keywords ) ),
			'new_secondary_keywords' => implode( "\n", array_diff( $new, $removed_keywords ) ),
			'topic_rationales_json'  => wp_json_encode( $rationales ),
			'review_notes'           => implode( "\n", Database::strings( $proposal['reviewNotes'] ?? array() ) ),
			'status'                 => $draft['status'] ?? 'held',
		);
	}

	/**
	 * Checks removal labels against the saved proposal, never the browser's vocabulary.
	 *
	 * @param mixed    $submitted Requested removals.
	 * @param string[] $allowed   AI-selected labels.
	 * @return list<string>|WP_Error Validated removals.
	 */
	private static function removed_labels( $submitted, array $allowed ): array|WP_Error {
		$error = new WP_Error( 'ehrman_ingestion_invalid_proposal', __( 'Only selections from the current metadata proposal can be removed. Reload metadata and try again.', 'ehrman-blog-discovery' ) );
		if ( ! is_array( $submitted ) || ! array_is_list( $submitted ) ) {
			return $error;
		}
		foreach ( $submitted as $label ) {
			if ( ! is_string( $label ) || ! in_array( $label, $allowed, true ) ) {
				return $error;
			}
		}
		return array_values( array_unique( $submitted ) );
	}

	/**
	 * Applies the same source and proposal checks to every review interface.
	 *
	 * @param int         $draft_id Stored ingestion identifier.
	 * @param string|null $revision Reviewed revision, or null for internal callers.
	 * @param bool        $automatic Internal publishing worker.
	 * @return array<string,mixed>|WP_Error Current draft or a review error.
	 */
	private function reviewable_draft( int $draft_id, ?string $revision, bool $automatic = false ) {
		$draft = $this->repository->draft( $draft_id );
		if ( null === $draft || self::STATUS_APPROVED === $draft['status'] ) {
			return new WP_Error( 'ehrman_ingestion_missing_draft', __( 'The pending ingestion draft was not found.', 'ehrman-blog-discovery' ) );
		}
		if ( $automatic ? ! Post_Ingestion_Automation::authorized( $draft ) : Post_Ingestion_Automation::busy( $draft ) ) {
			return new WP_Error( 'ehrman_ingestion_analysis_active', __( 'Automatic metadata is running or its authorization changed. Reload its status.', 'ehrman-blog-discovery' ) );
		}
		$post = Post_Ingestion_Post_Source::published_post( $draft );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( null !== $post && Post_Ingestion_Post_Source::source_changed( $post, $draft ) ) {
			return new WP_Error( 'ehrman_ingestion_source_changed', __( 'The saved WordPress post changed after analysis. Reanalyze it before approval.', 'ehrman-blog-discovery' ) );
		}
		if ( ! in_array( $draft['status'], array( 'ready', 'held' ), true )
			|| ( null !== $revision && ! hash_equals( self::revision( $draft ), $revision ) ) ) {
			return new WP_Error( 'ehrman_ingestion_review_changed', __( 'The metadata changed while it was being reviewed. Reload the metadata before approving.', 'ehrman-blog-discovery' ) );
		}
		return $draft;
	}
}
