<?php
/**
 * Administrator metadata history page.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Provides the protected metadata history, review, and recovery interface. */
final class Post_Ingestion_Page {
	private const PAGE_SLUG = 'ehrman-post-ingestion';

	/** Registers administration hooks. */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_post_ehrman_ingestion_create_metadata', array( self::class, 'handle_create_metadata' ) );
		add_action( 'admin_post_ehrman_ingestion_proposal', array( self::class, 'handle_proposal' ) );
		add_action( 'admin_post_ehrman_ingestion_reanalyze', array( self::class, 'handle_reanalyze' ) );
		add_action( 'admin_post_ehrman_ingestion_retry_embedding', array( self::class, 'handle_retry_embedding' ) );
		add_action( 'admin_post_ehrman_ingestion_discard', array( self::class, 'handle_discard' ) );
	}

	/** Adds the page beneath WordPress Tools. */
	public static function add_page(): void {
		add_management_page(
			__( 'Search Metadata', 'ehrman-blog-discovery' ),
			Post_Ingestion_Admin_Status::menu_title( __( 'Search Metadata', 'ehrman-blog-discovery' ) ),
			'manage_options',
			self::PAGE_SLUG,
			array( self::class, 'render' )
		);
	}

	/** Renders metadata history and retained review and recovery actions. */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$service = new Post_Ingestion_Service();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only navigation; Database::text normalizes the value before absint validates it.
		$draft_id = isset( $_GET['draft'] ) ? absint( Database::text( wp_unslash( $_GET['draft'] ) ) ) : 0;
		$draft    = $draft_id > 0 ? $service->draft( $draft_id ) : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only filters are normalized by the worklist before query/display.
		$input      = Database::associative_row( wp_unslash( $_GET ) ) ?? array();
		$worklist   = new Post_Ingestion_Worklist();
		$indexed_id = absint( Database::text( $input['indexed'] ?? null ) );
		$indexed    = $indexed_id > 0 ? $worklist->indexed_post( $indexed_id ) : null;
		$notice     = get_transient( self::notice_key() );
		if ( false !== $notice ) {
			delete_transient( self::notice_key() );
		}
		$html = Template::render(
			'admin/post-ingestion/page.php',
			array(
				'analysis_configured'    => Post_Ingestion_Service::is_configured(),
				'database_authoritative' => Post_Ingestion_Service::database_is_authoritative(),
				'notice'                 => is_array( $notice ) ? array(
					'success' => ! empty( $notice['success'] ),
					'message' => Database::text( $notice['message'] ?? null ),
				) : null,
				'missing_draft'          => ( null === $draft && $draft_id > 0 ) || ( null === $indexed && $indexed_id > 0 ),
				'draft'                  => null !== $draft ? self::draft_view( $service, $draft ) : null,
				'indexed'                => $indexed,
				'worklist'               => null === $draft && null === $indexed ? self::worklist_view( $worklist->page( $input ) ) : null,
			)
		);
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The trusted templates escape values at their output boundaries.
		echo $html;
	}

	/** Generates metadata only for an explicitly selected, editable, published native post. */
	public static function handle_create_metadata(): void {
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
		if ( null !== $existing || ( new Post_Ingestion_Worklist() )->is_indexed( $post_id ) ) {
			self::redirect_with_notice( 0, false, __( 'This post already has metadata or a generation record. Refresh the list before continuing.', 'ehrman-blog-discovery' ) );
		}
		$result = $service->analyze( Post_Ingestion_Post_Source::input( $post ), get_current_user_id(), $post_id );
		if ( is_wp_error( $result ) ) {
			self::redirect_with_notice( 0, false, $result->get_error_message() );
		}
		self::redirect_with_notice( Database::integer( $result['id'] ?? null ), true, __( 'Metadata generation finished. Review the results below.', 'ehrman-blog-discovery' ) );
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
		self::redirect_with_notice( $draft_id, true, $message );
	}

	/** Handles reanalysis of retained full text. */
	public static function handle_reanalyze(): void {
		self::authorize( 'ehrman_ingestion_reanalyze' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; Database::text normalizes the value before absint validates it.
		$draft_id = isset( $_POST['draft_id'] ) ? absint( Database::text( wp_unslash( $_POST['draft_id'] ) ) ) : 0;
		$result   = ( new Post_Ingestion_Service() )->reanalyze( $draft_id );
		if ( is_wp_error( $result ) ) {
			self::redirect_with_notice( $draft_id, false, $result->get_error_message() );
		}
		self::redirect_with_notice( $draft_id, true, __( 'Metadata generation finished. Review the results before approving.', 'ehrman-blog-discovery' ) );
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
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; Database::text normalizes the value before absint validates it.
		$draft_id = isset( $_POST['draft_id'] ) ? absint( Database::text( wp_unslash( $_POST['draft_id'] ) ) ) : 0;
		$deleted  = ( new Post_Ingestion_Service() )->discard( $draft_id );
		self::redirect_with_notice( 0, $deleted, $deleted ? __( 'Pending draft discarded.', 'ehrman-blog-discovery' ) : __( 'The draft could not be discarded.', 'ehrman-blog-discovery' ) );
	}

	/**
	 * Prepares one draft or approved audit record for display.
	 *
	 * @param Post_Ingestion_Service $service Ingestion service.
	 * @param array<string,mixed>    $draft   Draft record.
	 * @return array<string,mixed> Draft display values.
	 */
	private static function draft_view( Post_Ingestion_Service $service, array $draft ): array {
		$draft_id = Database::integer( $draft['id'] ?? null );
		$status   = Database::text( $draft['status'] ?? null );
		$proposal = Database::associative_row( json_decode( Database::text( $draft['proposal_json'] ?? null ), true ) ) ?? array();
		$active   = Post_Ingestion_Service::analysis_is_active( $draft );
		return array(
			'id'               => $draft_id,
			'status'           => $status,
			'title'            => Database::text( $draft['title'] ?? null ),
			'date'             => Database::text( $draft['date_text'] ?? null ),
			'author'           => Database::text( $draft['author'] ?? null ),
			'wp_id'            => (string) Database::integer( $draft['source_wp_id'] ?? null ),
			'url'              => Database::text( $draft['url'] ?? null ),
			'model'            => Database::text( $draft['model'] ?? null ),
			'usage'            => sprintf(
				/* translators: 1: input token count, 2: output token count, 3: estimated US-dollar cost. */
				__( '%1$s input tokens, %2$s output tokens, approximately $%3$s', 'ehrman-blog-discovery' ),
				number_format_i18n( Database::integer( $draft['input_tokens'] ?? null ) ),
				number_format_i18n( Database::integer( $draft['output_tokens'] ?? null ) ),
				number_format_i18n( (float) Database::text( $draft['estimated_cost_usd'] ?? 0 ), 4 )
			),
			'embedding_status' => Database::text( $draft['embedding_status'] ?? null ),
			'embedding_error'  => Database::text( $draft['embedding_error'] ?? null ),
			'error'            => Database::text( $draft['error_message'] ?? null ),
			'analysis_active'  => $active,
			'analysis_stale'   => $active && Post_Ingestion_Service::analysis_is_stale( $draft ),
			'history_url'      => self::page_url(),
			'refresh_url'      => self::page_url( $draft_id ),
			'approved'         => self::approved_view( $proposal ),
			'review'           => ! $active && 'approved' !== $status && ! empty( $proposal ) ? self::review_view( $draft, $proposal ) : null,
		);
	}

	/**
	 * Prepares editable proposal fields and approval availability.
	 *
	 * @param array<string,mixed> $draft    Draft record.
	 * @param array<string,mixed> $proposal Proposed enrichment.
	 * @return array<string,mixed> Review display values.
	 */
	private static function review_view( array $draft, array $proposal ): array {
		return array(
			'id'                     => Database::text( $draft['id'] ?? null ),
			'revision'               => Post_Ingestion_Review_Workflow::revision( $draft ),
			'action_id'              => Database::integer( $draft['id'] ?? null ),
			'description'            => Database::text( $proposal['description'] ?? null ),
			'summary'                => Database::text( $proposal['searchSummary'] ?? null ),
			'topics'                 => self::string_list( $proposal['topics'] ?? null ),
			'keywords'               => array_merge( self::string_list( $proposal['secondaryKeywords'] ?? null ), self::string_list( $proposal['newSecondaryKeywords'] ?? null ) ),
			'status'                 => Database::text( $draft['status'] ?? null ),
			'warnings'               => self::string_list( $proposal['reviewNotes'] ?? null ),
			'database_authoritative' => Post_Ingestion_Service::database_is_authoritative(),
		);
	}

	/**
	 * Prepares an immutable approved proposal.
	 *
	 * @param array<string,mixed> $proposal Approved proposal.
	 * @return array<string,string> Approved display values.
	 */
	private static function approved_view( array $proposal ): array {
		return array(
			'description' => Database::text( $proposal['description'] ?? null ),
			'summary'     => Database::text( $proposal['searchSummary'] ?? null ),
			'topics'      => implode( ', ', self::string_list( $proposal['topics'] ?? null ) ),
			'keywords'    => implode( ', ', array_merge( self::string_list( $proposal['secondaryKeywords'] ?? null ), self::string_list( $proposal['newSecondaryKeywords'] ?? null ) ) ),
		);
	}

	/**
	 * Prepares paginated native posts, imported metadata, and generation records.
	 *
	 * @param list<array<string,mixed>> $drafts Draft and approval records.
	 * @return list<array<string,string>> History display rows.
	 */
	private static function history_rows( array $drafts ): array {
		$rows = array();
		foreach ( $drafts as $draft ) {
			$status = sanitize_key( Database::text( $draft['workflow_status'] ?? null ) );
			$id     = Database::integer( $draft['id'] ?? null );
			$kind   = Database::text( $draft['kind'] ?? null );
			$url    = 'indexed' === $kind ? add_query_arg( 'indexed', $id, self::page_url() ) : self::page_url( $id );
			$rows[] = array(
				'published' => substr( Database::text( $draft['published_at'] ?? null ), 0, 10 ),
				'title'     => Database::text( $draft['title'] ?? null ),
				'post_id'   => (string) Database::integer( $draft['wordpress_post_id'] ?? null ),
				'kind'      => $kind,
				'status'    => Post_Ingestion_Worklist::statuses()[ $status ] ?? $status,
				'vector'    => str_replace( '_', ' ', Database::text( $draft['embedding_status'] ?? null ) ),
				'url'       => $url,
				'action'    => 'needs_approval' === $status ? __( 'Review and approve', 'ehrman-blog-discovery' ) : __( 'View', 'ehrman-blog-discovery' ),
			);
		}
		return $rows;
	}

	/**
	 * Prepares filter-preserving pagination and display rows.
	 *
	 * @param array<string,mixed> $page Bounded worklist result.
	 * @return array<string,mixed> Template context.
	 */
	private static function worklist_view( array $page ): array {
		$filters              = Post_Ingestion_Worklist::filters( Database::associative_row( $page['filters'] ?? null ) ?? array() );
		$page['rows']         = self::history_rows( Database::associative_rows( $page['rows'] ?? null ) );
		$page['statuses']     = Post_Ingestion_Worklist::statuses();
		$page['previous_url'] = $filters['paged'] > 1 ? add_query_arg( array_merge( $filters, array( 'paged' => $filters['paged'] - 1 ) ), self::page_url() ) : '';
		$page['next_url']     = $filters['paged'] < Database::integer( $page['pages'] ?? null ) ? add_query_arg( array_merge( $filters, array( 'paged' => $filters['paged'] + 1 ) ), self::page_url() ) : '';
		$page['configured']   = Post_Ingestion_Service::is_configured();
		return $page;
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
	 * Returns scalar list values as strings.
	 *
	 * @param mixed $value Candidate list.
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
		wp_safe_redirect( self::page_url( $draft_id ) );
		exit;
	}

	/** Returns the current administrator's notice key. */
	private static function notice_key(): string {
		return 'ehrman_ingestion_notice_' . get_current_user_id();
	}

	/**
	 * Returns the page URL, optionally selecting one draft.
	 *
	 * @param int $draft_id Draft identifier, or zero for the index.
	 */
	public static function page_url( int $draft_id = 0 ): string {
		$url = admin_url( 'tools.php?page=' . self::PAGE_SLUG );
		return $draft_id > 0 ? add_query_arg( 'draft', $draft_id, $url ) : $url;
	}
}
