<?php
/**
 * Protected actions for the administrator Search Metadata page.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Dispatches admin forms to ingestion services without rendering page markup. */
final class Post_Ingestion_Admin_Actions {
	/** Registers the existing protected admin-post actions. */
	public static function register(): void {
		add_action( 'admin_post_ehrman_ingestion_create_metadata', array( self::class, 'handle_create_metadata' ) );
		add_action( 'admin_post_ehrman_ingestion_proposal', array( self::class, 'handle_proposal' ) );
		add_action( 'admin_post_ehrman_ingestion_reanalyze', array( self::class, 'handle_reanalyze' ) );
		add_action( 'admin_post_ehrman_ingestion_edit_metadata', array( self::class, 'handle_edit_metadata' ) );
		add_action( 'admin_post_ehrman_ingestion_retry_embedding', array( self::class, 'handle_retry_embedding' ) );
		add_action( 'admin_post_ehrman_ingestion_discard', array( self::class, 'handle_discard' ) );
	}

	/**
	 * Authorizes a progress request without starting metadata generation.
	 *
	 * @return array{post: \WP_Post, draft: array<string,mixed>|null}|null Authorized selection.
	 */
	public static function progress_context(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only detects the form action; creation_post verifies its nonce and permissions below.
		$action = isset( $_POST['action'] ) ? sanitize_key( Database::text( wp_unslash( $_POST['action'] ) ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strict comparison validates the method; it is never stored or displayed.
		if ( 'POST' !== wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return null;
		}
		if ( 'ehrman_ingestion_prepare_metadata' === $action ) {
			return array(
				'post'  => self::creation_post(),
				'draft' => null,
			);
		} elseif ( 'ehrman_ingestion_prepare_regeneration' === $action ) {
			self::authorize( 'ehrman_ingestion_reanalyze' );
			$draft = self::selected_draft();
			$post  = Post_Ingestion_Post_Source::published_post( $draft );
			if ( is_wp_error( $post ) || null === $post || ! current_user_can( 'edit_post', $post->ID ) ) {
				self::redirect_with_notice( Database::integer( $draft['id'] ), false, __( 'Regeneration requires an editable, published WordPress post.', 'ehrman-blog-discovery' ) );
			}
			return array(
				'post'  => $post,
				'draft' => $draft,
			);
		}
		return null;
	}

	/** Generates metadata only for an explicitly selected, editable, published native post. */
	public static function handle_create_metadata(): void {
		$post    = self::creation_post();
		$service = new Post_Ingestion_Service();
		$result  = $service->analyze( Post_Ingestion_Post_Source::input( $post ), get_current_user_id(), $post->ID );
		if ( is_wp_error( $result ) ) {
			$draft = $service->latest_for_post( $post->ID );
			self::redirect_with_notice( Database::integer( $draft['id'] ?? null ), false, $result->get_error_message() );
		}
		self::redirect_with_notice( Database::integer( $result['id'] ?? null ), true, __( 'Metadata generation finished. Review the results below.', 'ehrman-blog-discovery' ) );
	}

	/** Validates a creation request before either progress display or the no-JavaScript fallback. */
	private static function creation_post(): \WP_Post {
		self::authorize( 'ehrman_ingestion_create_metadata' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked above; absint validates the trusted identifier, never submitted post content.
		$post_id = isset( $_POST['post_id'] ) ? absint( Database::text( wp_unslash( $_POST['post_id'] ) ) ) : 0;
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to manage search metadata for this post.', 'ehrman-blog-discovery' ), '', array( 'response' => 403 ) );
		}
		$post = Post_Ingestion_Post_Source::published_post( array( 'wordpress_post_id' => $post_id ) );
		if ( is_wp_error( $post ) || null === $post ) {
			self::redirect_with_notice( 0, false, __( 'Publish the post before creating search metadata.', 'ehrman-blog-discovery' ) );
		}
		$service  = new Post_Ingestion_Service();
		$existing = $service->latest_for_post( $post_id );
		if ( null !== $existing ) {
			self::redirect_with_notice( Database::integer( $existing['id'] ?? null ), false, __( 'This post already has a metadata record. Review its current status below.', 'ehrman-blog-discovery' ) );
		}
		if ( ( new Post_Ingestion_Worklist() )->is_indexed( $post_id ) ) {
			self::redirect_with_notice( 0, false, __( 'This post already has metadata or a generation record. Refresh the list before continuing.', 'ehrman-blog-discovery' ) );
		}
		if ( ! Post_Ingestion_Service::is_configured() ) {
			self::redirect_with_notice( 0, false, __( 'Metadata generation is not configured. Set OPENAI_API_KEY on the server.', 'ehrman-blog-discovery' ) );
		}
		return $post;
	}

	/** Handles proposal revisions or final approval. */
	public static function handle_proposal(): void {
		self::authorize( 'ehrman_ingestion_proposal' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; Database::text normalizes the value before absint validates it.
		$draft_id = isset( $_POST['draft_id'] ) ? absint( Database::text( wp_unslash( $_POST['draft_id'] ) ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; Database::text normalizes the value before sanitize_key validates it.
		$intent   = isset( $_POST['ingestion_intent'] ) ? sanitize_key( Database::text( wp_unslash( $_POST['ingestion_intent'] ) ) ) : 'save';
		$service  = new Post_Ingestion_Service();
		$input    = self::posted_values();
		$revision = Database::text( $input['revision'] ?? null );
		$draft    = $service->draft( $draft_id );
		if ( null === $draft ) {
			self::redirect_with_notice( 0, false, __( 'The pending ingestion draft was not found.', 'ehrman-blog-discovery' ) );
		}
		$input = Post_Ingestion_Review_Workflow::review_input( $draft, $input );
		if ( is_wp_error( $input ) ) {
			self::redirect_with_notice( $draft_id, false, $input->get_error_message() );
		}
		if ( 'approve' !== $intent ) {
			$saved = $service->save_proposal( $draft_id, $input, $revision );
			if ( is_wp_error( $saved ) ) {
				self::redirect_with_notice( $draft_id, false, $saved->get_error_message() );
			}
			self::redirect_with_notice( $draft_id, true, __( 'Draft revisions saved. Live search data was not changed.', 'ehrman-blog-discovery' ) );
		}
		$approved = $service->approve( $draft_id, true, $input, $revision );
		if ( is_wp_error( $approved ) ) {
			self::redirect_with_notice( $draft_id, false, $approved->get_error_message() );
		}
		$embedding_status = Database::text( $approved['embedding_status'] ?? null );
		$message          = 'complete' === $embedding_status || 'not_applicable' === $embedding_status
			? __( 'Post approved and added to the live index.', 'ehrman-blog-discovery' )
			: __( 'Post approved and added to the live index. Its semantic vector is pending and can be retried below.', 'ehrman-blog-discovery' );
		if ( Database::integer( $approved['revision_parent_id'] ?? null ) > 0 ) {
			$message = __( 'Metadata changes approved and applied to search.', 'ehrman-blog-discovery' );
		}
		self::redirect_with_notice( $draft_id, true, $message );
	}

	/** Handles reanalysis of retained full text. */
	public static function handle_reanalyze(): void {
		self::authorize( 'ehrman_ingestion_reanalyze' );
		$draft   = self::selected_draft();
		$service = new Post_Ingestion_Service();
		$result  = $service->reanalyze( Database::integer( $draft['id'] ) );
		if ( is_wp_error( $result ) ) {
			$current = $service->latest_for_post( Database::integer( $draft['wordpress_post_id'] ?? null ) ) ?? $draft;
			self::redirect_with_notice( Database::integer( $current['id'] ), false, $result->get_error_message() );
		}
		self::redirect_with_notice( Database::integer( $result['id'] ), true, __( 'Metadata generation finished. Review the results before approving.', 'ehrman-blog-discovery' ) );
	}

	/** Creates an editable revision without changing the approved search entry. */
	public static function handle_edit_metadata(): void {
		self::authorize( 'ehrman_ingestion_edit_metadata' );
		$draft  = self::selected_draft();
		$result = ( new Post_Ingestion_Service() )->edit_metadata( Database::integer( $draft['id'] ) );
		if ( is_wp_error( $result ) ) {
			self::redirect_with_notice( Database::integer( $draft['id'] ), false, $result->get_error_message() );
		}
		self::redirect_with_notice( Database::integer( $result['id'] ), true, __( 'Edit the revision below. Approved metadata stays active until you approve these changes.', 'ehrman-blog-discovery' ) );
	}

	/**
	 * Validates the submitted record and revision token.
	 *
	 * @return array<string,mixed> Selected record.
	 */
	private static function selected_draft(): array {
		$input = self::posted_values();
		$draft = ( new Post_Ingestion_Service() )->draft( absint( Database::text( $input['draft_id'] ?? null ) ) );
		if ( null === $draft || ! hash_equals( Post_Ingestion_Review_Workflow::revision( $draft ), Database::text( $input['revision'] ?? null ) ) ) {
			self::redirect_with_notice( Database::integer( $draft['id'] ?? null ), false, __( 'The metadata record changed. Reload it before continuing.', 'ehrman-blog-discovery' ) );
		}
		return $draft;
	}

	/** Handles a vector-generation retry. */
	public static function handle_retry_embedding(): void {
		self::authorize( 'ehrman_ingestion_retry_embedding' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; Database::text normalizes the value before absint validates it.
		$draft_id = isset( $_POST['draft_id'] ) ? absint( Database::text( wp_unslash( $_POST['draft_id'] ) ) ) : 0;
		$result   = ( new Post_Ingestion_Service() )->retry_embedding( $draft_id );
		if ( is_wp_error( $result ) ) {
			self::redirect_with_notice( $draft_id, false, $result->get_error_message() );
		}
		$success = 'complete' === Database::text( $result['embedding_status'] ?? null );
		$message = $success ? __( 'The semantic vector was generated.', 'ehrman-blog-discovery' ) : Database::text( $result['embedding_error'] ?? null );
		self::redirect_with_notice( $draft_id, $success, $message );
	}

	/** Handles permanent deletion of an unapproved draft. */
	public static function handle_discard(): void {
		self::authorize( 'ehrman_ingestion_discard' );
		$draft     = self::selected_draft();
		$draft_id  = Database::integer( $draft['id'] );
		$service   = new Post_Ingestion_Service();
		$parent_id = Database::integer( $draft['revision_parent_id'] ?? null );
		$deleted   = $service->discard( $draft_id, Post_Ingestion_Review_Workflow::revision( $draft ) );
		self::redirect_with_notice( $deleted ? $parent_id : $draft_id, $deleted, $deleted ? __( 'Pending changes discarded. Approved metadata was not changed.', 'ehrman-blog-discovery' ) : __( 'The draft could not be discarded. Reload its current status before trying again.', 'ehrman-blog-discovery' ) );
	}

	/**
	 * Verifies administrator capability and a request-specific nonce.
	 *
	 * @param string $nonce_action Nonce action name.
	 */
	private static function authorize( string $nonce_action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage post ingestion.', 'ehrman-blog-discovery' ) );
		}
		check_admin_referer( $nonce_action );
	}

	/**
	 * Returns unslashed posted values.
	 *
	 * @return array<string,mixed> Submitted values.
	 */
	private static function posted_values(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each handler verifies its nonce; the service sanitizes each field by type.
		return Database::associative_row( wp_unslash( $_POST ) ) ?? array();
	}

	/**
	 * Stores a notice and redirects back to the relevant page.
	 *
	 * @param int    $draft_id Draft identifier, or zero for the index.
	 * @param bool   $success  Whether the action succeeded.
	 * @param string $message  User-facing notice.
	 */
	private static function redirect_with_notice( int $draft_id, bool $success, string $message ): never {
		set_transient(
			self::notice_key(),
			array(
				'success' => $success,
				'message' => sanitize_text_field( $message ),
			),
			MINUTE_IN_SECONDS
		);
		wp_safe_redirect( Post_Ingestion_Page::page_url( $draft_id ) );
		exit;
	}

	/** Returns the current administrator's notice key. */
	public static function notice_key(): string {
		return 'ehrman_ingestion_notice_' . get_current_user_id();
	}
}
