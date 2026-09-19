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
	 * @return array<string,mixed>|WP_Error Updated draft or error.
	 */
	public function save_proposal( int $draft_id, array $input ) {
		$draft = $this->repository->draft( $draft_id );
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

		$stored = $this->repository->draft( $draft_id );
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
		if ( ! Post_Ingestion_Settings::database_is_authoritative() ) {
			return new WP_Error(
				'ehrman_ingestion_json_authoritative',
				__( 'Approval is disabled while authoritative post data comes from JSON. Set EHRMAN_DISCOVERY_POST_SOURCE=mysql only after the source-of-truth handoff.', 'ehrman-blog-discovery' )
			);
		}
		$draft = $this->repository->draft( $draft_id );
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
		if ( ! hash_equals( Database::text( $draft['taxonomy_version'] ?? null ), $this->repository->vocabulary_hash( $current_taxonomy ) ) ) {
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
		$stored = $this->repository->draft( $draft_id );
		return null === $stored
			? new WP_Error( 'ehrman_ingestion_storage_error', __( 'The approved audit record could not be reloaded.', 'ehrman-blog-discovery' ) )
			: $stored;
	}
}
