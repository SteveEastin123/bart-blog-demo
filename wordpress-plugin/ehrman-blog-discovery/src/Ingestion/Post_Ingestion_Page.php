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

	/**
	 * Authorized post selected for the progress screen.
	 *
	 * @var \WP_Post|null
	 */
	private static ?\WP_Post $progress_post = null;

	/**
	 * Record explicitly selected for regeneration.
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $progress_draft = null;

	/** Registers administration hooks. */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'load-tools_page_' . self::PAGE_SLUG, array( self::class, 'prepare_progress' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_progress' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_review' ) );
	}

	/** Prepares the authorized selection for the progress screen. */
	public static function prepare_progress(): void {
		self::$progress_post  = null;
		self::$progress_draft = null;
		$context              = Post_Ingestion_Admin_Actions::progress_context();
		if ( null !== $context ) {
			self::$progress_post  = $context['post'];
			self::$progress_draft = $context['draft'];
		}
	}

	/** Loads the foreground request client only for an authorized progress screen. */
	public static function enqueue_progress(): void {
		if ( null === self::$progress_post ) {
			return;
		}
		$path = EHRMAN_DISCOVERY_PLUGIN_DIR . 'assets/js/post-ingestion-progress.js';
		wp_enqueue_script(
			'ehrman-post-ingestion-progress',
			EHRMAN_DISCOVERY_PLUGIN_URL . 'assets/js/post-ingestion-progress.js',
			array( 'wp-api-fetch', 'wp-i18n' ),
			is_file( $path ) ? (string) filemtime( $path ) : EHRMAN_DISCOVERY_VERSION,
			true
		);
		wp_localize_script(
			'ehrman-post-ingestion-progress',
			'EhrmanMetadataProgress',
			array(
				'postId'           => self::$progress_post->ID,
				'restPath'         => '/ehrman-discovery/v1/ingestion/post/' . self::$progress_post->ID,
				'nonce'            => wp_create_nonce( 'wp_rest' ),
				'pageUrl'          => self::page_url(),
				'action'           => null === self::$progress_draft ? 'analyze' : 'reanalyze',
				'expectedDraftId'  => Database::integer( self::$progress_draft['id'] ?? null ),
				'expectedRevision' => null === self::$progress_draft ? '' : Post_Ingestion_Review_Workflow::revision( self::$progress_draft ),
			)
		);
	}

	/**
	 * Loads selection controls only on the protected metadata page.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function enqueue_review( string $hook ): void {
		if ( 'tools_page_' . self::PAGE_SLUG !== $hook || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$path = EHRMAN_DISCOVERY_PLUGIN_DIR . 'assets/js/post-ingestion-review.js';
		Post_Ingestion_Editor::enqueue_term_picker();
		wp_enqueue_script( 'ehrman-post-ingestion-review', EHRMAN_DISCOVERY_PLUGIN_URL . 'assets/js/post-ingestion-review.js', array( 'ehrman-metadata-term-picker' ), is_file( $path ) ? (string) filemtime( $path ) : EHRMAN_DISCOVERY_VERSION, true );
		Post_Ingestion_Editor::enqueue_selection_styles();
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
		$notice     = get_transient( Post_Ingestion_Admin_Actions::notice_key() );
		if ( false !== $notice ) {
			delete_transient( Post_Ingestion_Admin_Actions::notice_key() );
		}
		$html = Template::render(
			'admin/post-ingestion/page.php',
			array(
				'progress'               => null !== self::$progress_post ? array(
					'post_id'  => self::$progress_post->ID,
					'title'    => self::$progress_post->post_title,
					'draft_id' => Database::integer( self::$progress_draft['id'] ?? null ),
					'revision' => null === self::$progress_draft ? '' : Post_Ingestion_Review_Workflow::revision( self::$progress_draft ),
				) : null,
				'analysis_configured'    => Post_Ingestion_Service::is_configured(),
				'database_authoritative' => Post_Ingestion_Service::database_is_authoritative(),
				'notice'                 => is_array( $notice ) ? array(
					'success' => ! empty( $notice['success'] ),
					'message' => Database::text( $notice['message'] ?? null ),
				) : null,
				'missing_draft'          => ( null === $draft && $draft_id > 0 ) || ( null === $indexed && $indexed_id > 0 ),
				'draft'                  => null !== $draft ? self::draft_view( $service, $draft ) : null,
				'indexed'                => $indexed,
				'worklist'               => null === self::$progress_post && null === $draft && null === $indexed ? self::worklist_view( $worklist->page( $input ) ) : null,
			)
		);
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The trusted templates escape values at their output boundaries.
		echo $html;
	}

	/**
	 * Prepares one draft or approved audit record for display.
	 *
	 * @param Post_Ingestion_Service $service Ingestion service.
	 * @param array<string,mixed>    $draft   Draft record.
	 * @return array<string,mixed> Draft display values.
	 */
	private static function draft_view( Post_Ingestion_Service $service, array $draft ): array {
		$draft_id   = Database::integer( $draft['id'] ?? null );
		$status     = Database::text( $draft['status'] ?? null );
		$proposal   = Database::associative_row( json_decode( Database::text( $draft['proposal_json'] ?? null ), true ) ) ?? array();
		$active     = Post_Ingestion_Service::analysis_is_active( $draft );
		$native_id  = Database::integer( $draft['wordpress_post_id'] ?? null );
		$latest     = $service->latest_for_post( $native_id );
		$parent_id  = Database::integer( $draft['revision_parent_id'] ?? null );
		$can_revise = $native_id > 0 && 'publish' === get_post_status( $native_id ) && current_user_can( 'edit_post', $native_id ) && Database::integer( $latest['id'] ?? null ) === $draft_id;
		return array(
			'is_revision'      => $parent_id > 0,
			'automatic'        => Post_Ingestion_Automation::active( $draft ),
			'approval_mode'    => Database::text( $draft['approval_mode'] ?? 'manual' ),
			'parent_url'       => $parent_id > 0 ? self::page_url( $parent_id ) : '',
			'can_revise'       => $can_revise,
			'latest_url'       => null !== $latest && Database::integer( $latest['id'] ) !== $draft_id ? self::page_url( Database::integer( $latest['id'] ) ) : '',
			'regenerate'       => array(
				'id'        => $draft_id,
				'native_id' => $native_id,
				'revision'  => Post_Ingestion_Review_Workflow::revision( $draft ),
				'label'     => __( 'Regenerate Metadata', 'ehrman-blog-discovery' ),
			),
			'id'               => $draft_id,
			'revision'         => Post_Ingestion_Review_Workflow::revision( $draft ),
			'status'           => $status,
			'title'            => Database::text( $draft['title'] ?? null ),
			'date'             => Database::text( $draft['date_text'] ?? null ),
			'author'           => Database::text( $draft['author'] ?? null ),
			'wp_id'            => (string) Database::integer( $draft['source_wp_id'] ?? null ),
			'url'              => Database::text( $draft['url'] ?? null ),
			'model'            => 'imported' === ( $draft['approval_mode'] ?? '' ) ? __( 'Imported metadata (no AI generation)', 'ehrman-blog-discovery' ) : ( $parent_id > 0 && 0 === Database::integer( $draft['analysis_attempt'] ?? null ) ? __( 'Manual revision', 'ehrman-blog-discovery' ) : Database::text( $draft['model'] ?? null ) ),
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
			'is_revision'            => Database::integer( $draft['revision_parent_id'] ?? null ) > 0,
			'native_id'              => Database::integer( $draft['wordpress_post_id'] ?? null ),
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
				'vector'    => ucfirst( str_replace( '_', ' ', Database::text( $draft['embedding_status'] ?? null ) ) ),
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
	 * Returns the page URL, optionally selecting one draft.
	 *
	 * @param int $draft_id Draft identifier, or zero for the index.
	 */
	public static function page_url( int $draft_id = 0 ): string {
		$url = admin_url( 'tools.php?page=' . self::PAGE_SLUG );
		return $draft_id > 0 ? add_query_arg( 'draft', $draft_id, $url ) : $url;
	}
}
