<?php
/**
 * Administrator post-ingestion page.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Provides the protected draft, review, approval, and audit interface. */
final class Post_Ingestion_Page {
	private const PAGE_SLUG = 'ehrman-post-ingestion';

	/** Registers administration hooks. */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_post_ehrman_ingestion_analyze', array( self::class, 'handle_analyze' ) );
		add_action( 'admin_post_ehrman_ingestion_proposal', array( self::class, 'handle_proposal' ) );
		add_action( 'admin_post_ehrman_ingestion_reanalyze', array( self::class, 'handle_reanalyze' ) );
		add_action( 'admin_post_ehrman_ingestion_retry_embedding', array( self::class, 'handle_retry_embedding' ) );
		add_action( 'admin_post_ehrman_ingestion_discard', array( self::class, 'handle_discard' ) );
	}

	/** Adds the page beneath WordPress Tools. */
	public static function add_page(): void {
		add_management_page(
			__( 'Post Ingestion', 'ehrman-blog-discovery' ),
			__( 'Post Ingestion', 'ehrman-blog-discovery' ),
			'manage_options',
			self::PAGE_SLUG,
			array( self::class, 'render' )
		);
	}

	/** Renders the submission, review, and audit interface. */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$service = new Post_Ingestion_Service();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only navigation; Database::text normalizes the value before absint validates it.
		$draft_id = isset( $_GET['draft'] ) ? absint( Database::text( wp_unslash( $_GET['draft'] ) ) ) : 0;
		$draft    = $draft_id > 0 ? $service->draft( $draft_id ) : null;
		$notice   = get_transient( self::notice_key() );
		if ( false !== $notice ) {
			delete_transient( self::notice_key() );
		}
		?>
		<div class="wrap ehrman-ingestion">
			<h1><?php echo esc_html__( 'Post Ingestion', 'ehrman-blog-discovery' ); ?></h1>
			<p><?php echo esc_html__( 'Prepare a complete Bart Ehrman Blog post for the discovery index. Analysis creates a reviewable draft; it never changes live search data.', 'ehrman-blog-discovery' ); ?></p>
			<?php self::render_configuration_notice(); ?>
			<?php self::render_notice( $notice ); ?>
			<?php if ( null !== $draft ) : ?>
				<?php self::render_draft( $service, $draft ); ?>
			<?php else : ?>
				<?php if ( $draft_id > 0 ) : ?>
					<div class="notice notice-error"><p><?php echo esc_html__( 'The requested ingestion record was not found.', 'ehrman-blog-discovery' ); ?></p></div>
				<?php endif; ?>
				<?php self::render_new_form(); ?>
				<?php self::render_draft_list( $service->drafts() ); ?>
			<?php endif; ?>
		</div>
		<style>
			.ehrman-ingestion .form-table th { width: 180px; }
			.ehrman-ingestion .regular-text { width: min(100%, 680px); }
			.ehrman-ingestion textarea.large-text { max-width: 920px; }
			.ehrman-ingestion__meta { max-width: 920px; }
			.ehrman-ingestion__actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 18px 0; }
			.ehrman-ingestion__actions form { margin: 0; }
			.ehrman-ingestion__new { border-left: 4px solid #d63638; padding-left: 12px; }
			.ehrman-ingestion__status { display: inline-block; font-weight: 600; text-transform: capitalize; }
			.ehrman-ingestion__rationales { margin: 0; max-width: 920px; }
			.ehrman-ingestion__rationales li { margin-bottom: 8px; }
		</style>
		<?php
	}

	/** Handles a new analysis request. */
	public static function handle_analyze(): void {
		self::authorize( 'ehrman_ingestion_analyze' );
		$service = new Post_Ingestion_Service();
		$result  = $service->analyze( self::posted_values(), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			self::redirect_with_notice( 0, false, $result->get_error_message() );
		}
		$draft_id = Database::integer( $result['id'] ?? null );
		self::redirect_with_notice( $draft_id, true, __( 'Analysis queued. Refresh this page in a moment to review the results.', 'ehrman-blog-discovery' ) );
	}

	/** Handles proposal revisions or final approval. */
	public static function handle_proposal(): void {
		self::authorize( 'ehrman_ingestion_proposal' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; Database::text normalizes the value before absint validates it.
		$draft_id = isset( $_POST['draft_id'] ) ? absint( Database::text( wp_unslash( $_POST['draft_id'] ) ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; Database::text normalizes the value before sanitize_key validates it.
		$intent  = isset( $_POST['ingestion_intent'] ) ? sanitize_key( Database::text( wp_unslash( $_POST['ingestion_intent'] ) ) ) : 'save';
		$service = new Post_Ingestion_Service();
		$saved   = $service->save_proposal( $draft_id, self::posted_values() );
		if ( is_wp_error( $saved ) ) {
			self::redirect_with_notice( $draft_id, false, $saved->get_error_message() );
		}
		if ( 'approve' !== $intent ) {
			self::redirect_with_notice( $draft_id, true, __( 'Draft revisions saved. Live search data was not changed.', 'ehrman-blog-discovery' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; Database::text normalizes the value before sanitize_text_field validates it.
		$approve_new = isset( $_POST['approve_new_keywords'] ) && '1' === sanitize_text_field( Database::text( wp_unslash( $_POST['approve_new_keywords'] ) ) );
		$approved    = $service->approve( $draft_id, $approve_new );
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
		self::redirect_with_notice( $draft_id, true, __( 'Reanalysis queued. Refresh this page in a moment to review the results.', 'ehrman-blog-discovery' ) );
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

	/** Renders configuration and source-of-truth status. */
	private static function render_configuration_notice(): void {
		if ( ! Post_Ingestion_Service::is_configured() ) {
			?>
			<div class="notice notice-warning inline"><p><strong><?php echo esc_html__( 'Analysis is not configured.', 'ehrman-blog-discovery' ); ?></strong> <?php echo esc_html__( 'Add the separate ingestion-project key as EHRMAN_INGESTION_OPENAI_API_KEY.', 'ehrman-blog-discovery' ); ?></p></div>
			<?php
		}
		if ( ! Post_Ingestion_Service::database_is_authoritative() ) {
			?>
			<div class="notice notice-info inline"><p><strong><?php echo esc_html__( 'JSON is the current source of truth.', 'ehrman-blog-discovery' ); ?></strong> <?php echo esc_html__( 'Draft analysis and review are available, but approval is locked because a JSON import would replace database-only posts. After the handoff, set EHRMAN_DISCOVERY_POST_SOURCE=mysql to enable approval.', 'ehrman-blog-discovery' ); ?></p></div>
			<?php
		}
	}

	/** Renders a new post submission form. */
	private static function render_new_form(): void {
		?>
		<h2><?php echo esc_html__( 'Analyze a post', 'ehrman-blog-discovery' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ehrman_ingestion_analyze">
			<?php wp_nonce_field( 'ehrman_ingestion_analyze' ); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="source_wp_id"><?php echo esc_html__( 'WordPress post ID', 'ehrman-blog-discovery' ); ?></label></th><td><input class="regular-text" id="source_wp_id" name="source_wp_id" type="number" min="1" required></td></tr>
				<tr><th scope="row"><label for="title"><?php echo esc_html__( 'Title', 'ehrman-blog-discovery' ); ?></label></th><td><input class="regular-text" id="title" name="title" type="text" required></td></tr>
				<tr><th scope="row"><label for="url"><?php echo esc_html__( 'URL', 'ehrman-blog-discovery' ); ?></label></th><td><input class="regular-text" id="url" name="url" type="url" required></td></tr>
				<tr><th scope="row"><label for="author"><?php echo esc_html__( 'Author', 'ehrman-blog-discovery' ); ?></label></th><td><input class="regular-text" id="author" name="author" type="text" required></td></tr>
				<tr><th scope="row"><label for="date"><?php echo esc_html__( 'Publication date', 'ehrman-blog-discovery' ); ?></label></th><td><input id="date" name="date" type="date" required></td></tr>
				<tr><th scope="row"><label for="post_text"><?php echo esc_html__( 'Complete post text', 'ehrman-blog-discovery' ); ?></label></th><td><textarea class="large-text" id="post_text" name="post_text" rows="20" required></textarea><p class="description"><?php echo esc_html__( 'The full text is retained only while the draft is pending and is deleted after approval.', 'ehrman-blog-discovery' ); ?></p></td></tr>
			</table>
			<?php submit_button( __( 'Analyze post', 'ehrman-blog-discovery' ), 'primary', 'submit', true, Post_Ingestion_Service::is_configured() ? array() : array( 'disabled' => 'disabled' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Renders one draft or approved audit record.
	 *
	 * @param Post_Ingestion_Service $service Ingestion service.
	 * @param array<string,mixed>    $draft   Draft record.
	 */
	private static function render_draft( Post_Ingestion_Service $service, array $draft ): void {
		$draft_id = Database::integer( $draft['id'] ?? null );
		$status   = Database::text( $draft['status'] ?? null );
		$proposal = Database::associative_row( json_decode( Database::text( $draft['proposal_json'] ?? null ), true ) ) ?? array();
		?>
		<p><a href="<?php echo esc_url( self::page_url() ); ?>">&larr; <?php echo esc_html__( 'New submission and ingestion history', 'ehrman-blog-discovery' ); ?></a></p>
		<h2><?php echo esc_html( Database::text( $draft['title'] ?? null ) ); ?></h2>
		<table class="widefat striped ehrman-ingestion__meta">
			<tbody>
				<tr><th><?php echo esc_html__( 'Status', 'ehrman-blog-discovery' ); ?></th><td><span class="ehrman-ingestion__status"><?php echo esc_html( $status ); ?></span></td></tr>
				<tr><th><?php echo esc_html__( 'Publication', 'ehrman-blog-discovery' ); ?></th><td><?php echo esc_html( Database::text( $draft['date_text'] ?? null ) ); ?> &middot; <?php echo esc_html( Database::text( $draft['author'] ?? null ) ); ?></td></tr>
				<tr><th><?php echo esc_html__( 'WordPress ID', 'ehrman-blog-discovery' ); ?></th><td><?php echo esc_html( (string) Database::integer( $draft['source_wp_id'] ?? null ) ); ?></td></tr>
				<tr><th><?php echo esc_html__( 'URL', 'ehrman-blog-discovery' ); ?></th><td><a href="<?php echo esc_url( Database::text( $draft['url'] ?? null ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( Database::text( $draft['url'] ?? null ) ); ?></a></td></tr>
				<tr><th><?php echo esc_html__( 'Analysis', 'ehrman-blog-discovery' ); ?></th><td><?php echo esc_html( Database::text( $draft['model'] ?? null ) ); ?> &middot; <?php /* translators: 1: input token count, 2: output token count, 3: estimated US-dollar cost. */ echo esc_html( sprintf( __( '%1$s input tokens, %2$s output tokens, approximately $%3$s', 'ehrman-blog-discovery' ), number_format_i18n( Database::integer( $draft['input_tokens'] ?? null ) ), number_format_i18n( Database::integer( $draft['output_tokens'] ?? null ) ), number_format_i18n( (float) Database::text( $draft['estimated_cost_usd'] ?? 0 ), 4 ) ) ); ?></td></tr>
				<?php
				if ( 'approved' === $status ) :
					?>
					<tr><th><?php echo esc_html__( 'Semantic vector', 'ehrman-blog-discovery' ); ?></th><td><?php echo esc_html( Database::text( $draft['embedding_status'] ?? null ) ); ?>
					<?php
					if ( '' !== Database::text( $draft['embedding_error'] ?? null ) ) :
						?>
					: <?php echo esc_html( Database::text( $draft['embedding_error'] ?? null ) ); ?><?php endif; ?></td></tr><?php endif; ?>
			</tbody>
		</table>
		<?php if ( '' !== Database::text( $draft['error_message'] ?? null ) ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( Database::text( $draft['error_message'] ?? null ) ); ?></p></div>
		<?php endif; ?>
		<?php if ( Post_Ingestion_Service::analysis_is_active( $draft ) ) : ?>
			<?php if ( Post_Ingestion_Service::analysis_is_stale( $draft ) ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html__( 'Background analysis appears to have stopped. Start a fresh attempt below.', 'ehrman-blog-discovery' ); ?></p></div>
				<div class="ehrman-ingestion__actions"><?php self::render_reanalyze_form( $draft_id, __( 'Retry analysis', 'ehrman-blog-discovery' ) ); ?></div>
			<?php else : ?>
				<div class="notice notice-info inline"><p><?php echo esc_html__( 'Analysis is running in the background. You may leave this page and return later.', 'ehrman-blog-discovery' ); ?></p></div>
				<p><a class="button" href="<?php echo esc_url( self::page_url( $draft_id ) ); ?>"><?php echo esc_html__( 'Refresh status', 'ehrman-blog-discovery' ); ?></a></p>
			<?php endif; ?>
			<?php return; ?>
		<?php endif; ?>
		<?php if ( 'approved' === $status ) : ?>
			<?php self::render_approved_proposal( $proposal ); ?>
			<?php if ( 'pending' === Database::text( $draft['embedding_status'] ?? null ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ehrman_ingestion_retry_embedding"><input type="hidden" name="draft_id" value="<?php echo esc_attr( (string) $draft_id ); ?>">
					<?php wp_nonce_field( 'ehrman_ingestion_retry_embedding' ); ?>
					<?php submit_button( __( 'Retry semantic vector', 'ehrman-blog-discovery' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
			<?php return; ?>
		<?php endif; ?>

		<?php if ( empty( $proposal ) ) : ?>
			<div class="ehrman-ingestion__actions">
				<?php self::render_reanalyze_form( $draft_id ); ?>
				<?php self::render_discard_form( $draft_id ); ?>
			</div>
			<?php return; ?>
		<?php endif; ?>
		<?php self::render_review_form( $service, $draft, $proposal ); ?>
		<?php
	}

	/**
	 * Renders editable proposal fields and approval controls.
	 *
	 * @param Post_Ingestion_Service $service  Ingestion service.
	 * @param array<string,mixed>    $draft    Draft record.
	 * @param array<string,mixed>    $proposal Proposed enrichment.
	 */
	private static function render_review_form( Post_Ingestion_Service $service, array $draft, array $proposal ): void {
		$vocabulary   = $service->vocabulary();
		$topics       = self::string_list( $proposal['topics'] ?? null );
		$rationales   = self::topic_rationale_list( $proposal['topicRationales'] ?? null );
		$keywords     = self::string_list( $proposal['secondaryKeywords'] ?? null );
		$new_keywords = self::string_list( $proposal['newSecondaryKeywords'] ?? null );
		$notes        = self::string_list( $proposal['reviewNotes'] ?? null );
		?>
		<h2><?php echo esc_html__( 'Review proposal', 'ehrman-blog-discovery' ); ?></h2>
		<p><?php echo esc_html__( 'Revise any field as needed. Saving remains a draft; approval is the only action that writes to the live index.', 'ehrman-blog-discovery' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ehrman_ingestion_proposal"><input type="hidden" name="draft_id" value="<?php echo esc_attr( Database::text( $draft['id'] ?? null ) ); ?>">
			<input type="hidden" name="topic_rationales_json" value="<?php echo esc_attr( (string) wp_json_encode( $rationales ) ); ?>">
			<?php wp_nonce_field( 'ehrman_ingestion_proposal' ); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="description"><?php echo esc_html__( 'Description', 'ehrman-blog-discovery' ); ?></label></th><td><textarea class="large-text" id="description" name="description" rows="2" required><?php echo esc_textarea( Database::text( $proposal['description'] ?? null ) ); ?></textarea><p class="description"><?php echo esc_html__( 'Begin with an active, present-tense verb. Use one declarative sentence, generally 18-23 words and fewer than 30.', 'ehrman-blog-discovery' ); ?></p></td></tr>
				<tr><th scope="row"><label for="search_summary"><?php echo esc_html__( 'Search summary', 'ehrman-blog-discovery' ); ?></label></th><td><textarea class="large-text" id="search_summary" name="search_summary" rows="5" required><?php echo esc_textarea( Database::text( $proposal['searchSummary'] ?? null ) ); ?></textarea><p class="description"><?php echo esc_html__( 'Begin with an active verb and a declarative statement. Normally use three sentences and about 60-80 words.', 'ehrman-blog-discovery' ); ?></p></td></tr>
				<tr><th scope="row"><label for="topics"><?php echo esc_html__( 'Topics', 'ehrman-blog-discovery' ); ?></label></th><td><select class="regular-text" id="topics" name="topics[]" multiple size="12">
				<?php
				foreach ( $vocabulary['topics'] as $topic ) :
					?>
					<option value="<?php echo esc_attr( $topic['name'] ); ?>"<?php selected( in_array( $topic['name'], $topics, true ) ); ?> title="<?php echo esc_attr( $topic['description'] ); ?>"><?php echo esc_html( $topic['name'] ); ?></option><?php endforeach; ?></select><p class="description"><?php echo esc_html__( 'Select only primary, sustained subjects. Hold a substantive post when no existing topic fits.', 'ehrman-blog-discovery' ); ?></p></td></tr>
				<?php if ( ! empty( $rationales ) ) : ?>
					<tr><th scope="row"><?php echo esc_html__( 'AI topic rationales', 'ehrman-blog-discovery' ); ?></th><td><ul class="ehrman-ingestion__rationales">
					<?php foreach ( $rationales as $rationale ) : ?>
						<li><strong><?php echo esc_html( $rationale['topic'] ); ?>:</strong> <?php echo esc_html( $rationale['rationale'] ); ?></li>
					<?php endforeach; ?>
					</ul><p class="description"><?php echo esc_html__( 'These explanations document the original AI proposal; revise the topic selections above whenever the evidence does not support them.', 'ehrman-blog-discovery' ); ?></p></td></tr>
				<?php endif; ?>
				<tr><th scope="row"><label for="secondary_keywords"><?php echo esc_html__( 'Existing secondary keywords', 'ehrman-blog-discovery' ); ?></label></th><td><textarea class="large-text" id="secondary_keywords" name="secondary_keywords" rows="6"><?php echo esc_textarea( implode( "\n", $keywords ) ); ?></textarea><p class="description"><?php echo esc_html__( 'Use one exact existing label per line.', 'ehrman-blog-discovery' ); ?></p></td></tr>
				<tr class="ehrman-ingestion__new"><th scope="row"><label for="new_secondary_keywords"><?php echo esc_html__( 'NEW secondary keywords', 'ehrman-blog-discovery' ); ?></label></th><td><textarea class="large-text" id="new_secondary_keywords" name="new_secondary_keywords" rows="3"><?php echo esc_textarea( implode( "\n", $new_keywords ) ); ?></textarea><p class="description"><?php echo esc_html__( 'Use only when no existing exact term, variant, or reasonable synonym fits.', 'ehrman-blog-discovery' ); ?></p></td></tr>
				<tr><th scope="row"><label for="status"><?php echo esc_html__( 'Decision', 'ehrman-blog-discovery' ); ?></label></th><td><select id="status" name="status"><option value="ready"<?php selected( 'ready', Database::text( $proposal['status'] ?? null ) ); ?>><?php echo esc_html__( 'Ready', 'ehrman-blog-discovery' ); ?></option><option value="held"<?php selected( 'held', Database::text( $proposal['status'] ?? null ) ); ?>><?php echo esc_html__( 'Held', 'ehrman-blog-discovery' ); ?></option></select></td></tr>
				<tr><th scope="row"><label for="review_notes"><?php echo esc_html__( 'Review notes', 'ehrman-blog-discovery' ); ?></label></th><td><textarea class="large-text" id="review_notes" name="review_notes" rows="4"><?php echo esc_textarea( implode( "\n", $notes ) ); ?></textarea><p class="description"><?php echo esc_html__( 'Required for ambiguity, a new keyword, or no suitable topic.', 'ehrman-blog-discovery' ); ?></p></td></tr>
				<?php
				if ( ! empty( $new_keywords ) ) :
					?>
					<tr class="ehrman-ingestion__new"><th scope="row"><?php echo esc_html__( 'New-keyword approval', 'ehrman-blog-discovery' ); ?></th><td><label><input type="checkbox" name="approve_new_keywords" value="1"> <?php echo esc_html__( 'I explicitly approve creating the NEW secondary keyword labels shown above.', 'ehrman-blog-discovery' ); ?></label></td></tr><?php endif; ?>
			</table>
			<div class="ehrman-ingestion__actions">
				<button class="button" type="submit" name="ingestion_intent" value="save"><?php echo esc_html__( 'Save revisions', 'ehrman-blog-discovery' ); ?></button>
				<button class="button button-primary" type="submit" name="ingestion_intent" value="approve"<?php disabled( ! Post_Ingestion_Service::database_is_authoritative() ); ?>><?php echo esc_html__( 'Approve and add to index', 'ehrman-blog-discovery' ); ?></button>
			</div>
		</form>
		<div class="ehrman-ingestion__actions">
			<?php self::render_reanalyze_form( Database::integer( $draft['id'] ?? null ) ); ?>
			<?php self::render_discard_form( Database::integer( $draft['id'] ?? null ) ); ?>
		</div>
		<?php
	}

	/**
	 * Renders an immutable approved proposal.
	 *
	 * @param array<string,mixed> $proposal Approved proposal.
	 */
	private static function render_approved_proposal( array $proposal ): void {
		?>
		<h2><?php echo esc_html__( 'Approved enrichment', 'ehrman-blog-discovery' ); ?></h2>
		<dl>
			<dt><strong><?php echo esc_html__( 'Description', 'ehrman-blog-discovery' ); ?></strong></dt><dd><?php echo esc_html( Database::text( $proposal['description'] ?? null ) ); ?></dd>
			<dt><strong><?php echo esc_html__( 'Search summary', 'ehrman-blog-discovery' ); ?></strong></dt><dd><?php echo esc_html( Database::text( $proposal['searchSummary'] ?? null ) ); ?></dd>
			<dt><strong><?php echo esc_html__( 'Topics', 'ehrman-blog-discovery' ); ?></strong></dt><dd><?php echo esc_html( implode( ', ', self::string_list( $proposal['topics'] ?? null ) ) ); ?></dd>
			<dt><strong><?php echo esc_html__( 'Secondary keywords', 'ehrman-blog-discovery' ); ?></strong></dt><dd><?php echo esc_html( implode( ', ', array_merge( self::string_list( $proposal['secondaryKeywords'] ?? null ), self::string_list( $proposal['newSecondaryKeywords'] ?? null ) ) ) ); ?></dd>
		</dl>
		<?php
	}

	/**
	 * Renders recent draft and approval history.
	 *
	 * @param list<array<string,mixed>> $drafts Draft and approval records.
	 */
	private static function render_draft_list( array $drafts ): void {
		?>
		<h2><?php echo esc_html__( 'Ingestion history', 'ehrman-blog-discovery' ); ?></h2>
		<?php
		if ( empty( $drafts ) ) :
			?>
			<p><?php echo esc_html__( 'No ingestion records yet.', 'ehrman-blog-discovery' ); ?></p><?php return; ?><?php endif; ?>
		<table class="widefat striped">
			<thead><tr><th><?php echo esc_html__( 'Updated', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Post', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Status', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Vector', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Action', 'ehrman-blog-discovery' ); ?></th></tr></thead>
			<tbody>
			<?php
			foreach ( $drafts as $draft ) :
				?>
				<tr><td><?php echo esc_html( get_date_from_gmt( Database::text( $draft['updated_at'] ?? null ), 'M j, Y g:i A' ) ); ?></td><td><strong><?php echo esc_html( Database::text( $draft['title'] ?? null ) ); ?></strong><br><span class="description"><?php echo esc_html( '#' . Database::text( $draft['source_wp_id'] ?? null ) ); ?></span></td><td><?php echo esc_html( ucfirst( Database::text( $draft['status'] ?? null ) ) ); ?></td><td><?php echo esc_html( str_replace( '_', ' ', Database::text( $draft['embedding_status'] ?? null ) ) ); ?></td><td><a class="button button-small" href="<?php echo esc_url( self::page_url( Database::integer( $draft['id'] ?? null ) ) ); ?>"><?php echo esc_html__( 'Review', 'ehrman-blog-discovery' ); ?></a></td></tr><?php endforeach; ?></tbody>
		</table>
		<?php
	}

	/**
	 * Renders the reanalysis action.
	 *
	 * @param int    $draft_id Draft identifier.
	 * @param string $label    Optional button label.
	 */
	private static function render_reanalyze_form( int $draft_id, string $label = '' ): void {
		$label = '' !== $label ? $label : __( 'Reanalyze full text', 'ehrman-blog-discovery' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ehrman_ingestion_reanalyze"><input type="hidden" name="draft_id" value="<?php echo esc_attr( (string) $draft_id ); ?>"><?php wp_nonce_field( 'ehrman_ingestion_reanalyze' ); ?><button class="button" type="submit"><?php echo esc_html( $label ); ?></button></form>
		<?php
	}

	/**
	 * Renders the draft-discard action.
	 *
	 * @param int $draft_id Draft identifier.
	 */
	private static function render_discard_form( int $draft_id ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm('<?php echo esc_js( __( 'Discard this pending draft and its retained full text?', 'ehrman-blog-discovery' ) ); ?>');"><input type="hidden" name="action" value="ehrman_ingestion_discard"><input type="hidden" name="draft_id" value="<?php echo esc_attr( (string) $draft_id ); ?>"><?php wp_nonce_field( 'ehrman_ingestion_discard' ); ?><button class="button button-link-delete" type="submit"><?php echo esc_html__( 'Discard draft', 'ehrman-blog-discovery' ); ?></button></form>
		<?php
	}

	/**
	 * Renders a one-time action notice.
	 *
	 * @param mixed $notice Stored notice value.
	 */
	private static function render_notice( $notice ): void {
		if ( ! is_array( $notice ) ) {
			return;
		}
		$class = ! empty( $notice['success'] ) ? 'notice-success' : 'notice-error';
		?>
		<div class="notice <?php echo esc_attr( $class ); ?> is-dismissible"><p><?php echo esc_html( Database::text( $notice['message'] ?? null ) ); ?></p></div>
		<?php
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
	 * Returns displayable topic-rationale records.
	 *
	 * @param mixed $value Candidate rationale records.
	 * @return list<array{topic:string,rationale:string}> Rationale records.
	 */
	private static function topic_rationale_list( $value ): array {
		$rationales = array();
		foreach ( is_array( $value ) ? $value : array() as $item ) {
			$row       = Database::associative_row( $item );
			$topic     = Database::text( $row['topic'] ?? null );
			$rationale = Database::text( $row['rationale'] ?? null );
			if ( '' !== $topic && '' !== $rationale ) {
				$rationales[] = array(
					'topic'     => $topic,
					'rationale' => $rationale,
				);
			}
		}
		return $rationales;
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
