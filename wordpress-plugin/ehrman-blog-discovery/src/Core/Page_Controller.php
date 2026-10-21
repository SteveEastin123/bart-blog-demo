<?php
/**
 * Front-end page and shortcode rendering.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Builds the keyword-search and hierarchical browsing interfaces. */
final class Page_Controller {
	/**
	 * Hierarchical browsing data service.
	 *
	 * @var Browse_Service
	 */
	private Browse_Service $browse;

	/**
	 * Post search and suggestion service.
	 *
	 * @var Search_Service
	 */
	private Search_Service $search;

	/**
	 * Sanitized public discovery request.
	 *
	 * @var Discovery_Request
	 */
	private Discovery_Request $request;

	/**
	 * Shared discovery-page markup.
	 *
	 * @var Discovery_Markup
	 */
	private Discovery_Markup $markup;

	/**
	 * Keyword-search and shared result renderer.
	 *
	 * @var Search_Page_Renderer
	 */
	private Search_Page_Renderer $search_page;

	/**
	 * Semantic Ask AI page renderer.
	 *
	 * @var Semantic_Ask_AI_Page_Renderer
	 */
	private Semantic_Ask_AI_Page_Renderer $semantic_ask_ai_page;

	/**
	 * Browse Topics page renderer.
	 *
	 * @var Browse_Page_Renderer
	 */
	private Browse_Page_Renderer $browse_page;

	/**
	 * Reviewer-only structure page renderer.
	 *
	 * @var Structure_Review_Renderer
	 */
	private Structure_Review_Renderer $structure_review;

	/** Creates the page controller and its data services. */
	public function __construct() {
		$this->browse               = new Browse_Service();
		$this->search               = new Search_Service();
		$this->request              = new Discovery_Request();
		$this->markup               = new Discovery_Markup();
		$this->search_page          = new Search_Page_Renderer( $this->browse, $this->search, $this->markup );
		$this->semantic_ask_ai_page = new Semantic_Ask_AI_Page_Renderer( $this->markup );
		$this->browse_page          = new Browse_Page_Renderer(
			$this->browse,
			$this->search,
			$this->markup,
			$this->search_page,
			\Closure::fromCallable( array( $this, 'browse_url' ) )
		);
		$this->structure_review     = new Structure_Review_Renderer( $this->browse, $this->markup );
	}

	/** Registers the plugin's public shortcodes. */
	public function register(): void {
		add_shortcode( 'ehrman_keyword_search', array( $this, 'keyword_search_shortcode' ) );
		add_shortcode( 'ehrman_ask_ai', array( $this, 'ask_ai_shortcode' ) );
		add_shortcode( 'ehrman_ask_question', array( $this, 'ask_question_shortcode' ) );
		// Compatibility for content embedded before the public name was simplified.
		add_shortcode( 'ehrman_ask_ai_2', array( $this, 'ask_ai_shortcode' ) );
		add_shortcode( 'ehrman_browse_topics', array( $this, 'browse_topics_shortcode' ) );
		add_shortcode( 'ehrman_structure_review', array( $this, 'structure_review_shortcode' ) );
		add_filter( 'wp_robots', array( $this, 'review_page_robots' ) );
		add_action( 'template_redirect', array( $this, 'redirect_legacy_ask_ai' ), 5 );
	}

	/** Creates or reconnects the public discovery pages. */
	public static function ensure_pages(): void {
		if ( ! self::migrate_ask_ai_page() ) {
			return;
		}
		$pages = array(
			'keyword_search'   => array( 'Keyword Search', 'keyword-search', '[ehrman_keyword_search]' ),
			'ask_ai'           => array( 'Ask AI', 'ask-ai', '[ehrman_ask_ai]' ),
			'browse_1'         => array( 'Browse Topics 1', 'browse-topics-1', '[ehrman_browse_topics path="1"]' ),
			'browse_2'         => array( 'Browse Topics 2', 'browse-topics-2', '[ehrman_browse_topics path="2"]' ),
			'structure_review' => array( 'Structure Review', 'structure-review', '[ehrman_structure_review]' ),
		);

		foreach ( $pages as $key => [$title, $slug, $content] ) {
			$option  = 'ehrman_discovery_page_' . $key;
			$page_id = Database::integer( get_option( $option, 0 ) );
			if ( $page_id > 0 && 'trash' !== get_post_status( $page_id ) ) {
				continue;
			}
			$existing = get_page_by_path( $slug, OBJECT, 'page' );
			if ( $existing instanceof \WP_Post ) {
				update_option( $option, $existing->ID, false );
				continue;
			}
			$page_id = wp_insert_post(
				array(
					'post_title'     => $title,
					'post_name'      => $slug,
					'post_content'   => $content,
					'post_status'    => 'publish',
					'post_type'      => 'page',
					'comment_status' => 'closed',
				),
				true
			);
			if ( ! is_wp_error( $page_id ) ) {
				update_option( $option, (int) $page_id, false );
			}
		}
		update_option( 'ehrman_discovery_pages_version', EHRMAN_DISCOVERY_VERSION, false );
	}

	/** Reuses an existing Ask AI page without deleting legacy content or history. */
	private static function migrate_ask_ai_page(): bool {
		$page      = get_page_by_path( 'ask-ai', OBJECT, 'page' );
		$legacy_id = Database::integer( get_option( 'ehrman_discovery_page_ask_ai_2', 0 ) );
		$legacy    = $legacy_id > 0 ? get_post( $legacy_id ) : null;
		if ( ! $legacy instanceof \WP_Post || 'page' !== $legacy->post_type || 'trash' === $legacy->post_status ) {
			$legacy = get_page_by_path( 'ask-ai-2', OBJECT, 'page' );
		}
		if ( ! $page instanceof \WP_Post ) {
			$page = $legacy;
		}
		if ( ! $page instanceof \WP_Post ) {
			return true;
		}
		$content = str_replace( array( '[ehrman_ask_question]', '[ehrman_ask_ai_2]' ), '[ehrman_ask_ai]', $page->post_content );
		if ( 'Ask AI' !== $page->post_title || 'ask-ai' !== $page->post_name || $content !== $page->post_content ) {
			$result = wp_update_post(
				array(
					'ID'           => $page->ID,
					'post_title'   => 'Ask AI',
					'post_name'    => 'ask-ai',
					'post_content' => wp_slash( $content ),
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				return false;
			}
		}
		update_option( 'ehrman_discovery_page_ask_ai', $page->ID, false );
		// Keep the superseded managed page recoverable, but out of public page lists.
		if ( $legacy instanceof \WP_Post && $legacy->ID !== $page->ID && has_shortcode( $legacy->post_content, 'ehrman_ask_ai_2' ) ) {
			$result = wp_update_post(
				array(
					'ID'           => $legacy->ID,
					'post_title'   => 'Ask AI (legacy link)',
					'post_status'  => 'draft',
					'post_content' => wp_slash( str_replace( '[ehrman_ask_ai_2]', '[ehrman_ask_ai]', $legacy->post_content ) ),
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				return false;
			}
		}
		return true;
	}

	/** Redirects retired interfaces to the surviving search page. */
	public function redirect_legacy_ask_ai(): void {
		$target = $this->retired_ask_ai_target();
		if ( '' !== $target ) {
			wp_safe_redirect( $target, 302, 'Ehrman Blog Discovery' );
			exit;
		}
	}

	/**
	 * Resolves retired URLs, retaining only the reader's question.
	 *
	 * @return string Surviving page URL, or empty text for an unrelated request.
	 */
	public function retired_ask_ai_target(): string {
		if ( is_admin() || ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}
		$request_uri  = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$request_path = wp_parse_url( $request_uri, PHP_URL_PATH );
		$retired      = false;
		foreach ( array( 'ask-a-question', 'ask-ai-1', 'ask-ai-2', 'ask-ai-3' ) as $slug ) {
			$legacy_path = wp_parse_url( home_url( '/' . $slug . '/' ), PHP_URL_PATH );
			if ( is_page( $slug ) || ( is_string( $request_path ) && is_string( $legacy_path ) && untrailingslashit( $request_path ) === untrailingslashit( $legacy_path ) ) ) {
				$retired = true;
				break;
			}
		}
		if ( ! $retired ) {
			return '';
		}
		// Old method-specific IDs and term filters must not enter the semantic workflow.
		$question = $this->request->value( 'ebd_question' );
		$url      = $this->page_url( 'ask_ai' );
		return '' === $question ? $url : add_query_arg( 'ebd_question', rawurlencode( $question ), $url );
	}

	/**
	 * Excludes the reviewer-only structure page from search-engine indexing.
	 *
	 * @param array<string,bool|string> $robots Existing robot directives.
	 * @return array<string,bool|string> Updated robot directives.
	 */
	public function review_page_robots( array $robots ): array {
		$page_id = Database::integer( get_option( 'ehrman_discovery_page_structure_review', 0 ) );
		if ( $page_id > 0 && is_page( $page_id ) ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	/**
	 * Renders the standalone keyword-search interface.
	 *
	 * @return string Search interface markup.
	 */
	public function keyword_search_shortcode(): string {
		if ( 'complete' !== Plugin::status_data()['import_state'] ) {
			return $this->not_ready();
		}
		Assets::enqueue();
		$terms         = $this->request->terms();
		$term_modes    = $this->search->resolve_term_modes( $terms, $this->request->term_modes() );
		$sort          = $this->request->value( 'ebd_sort', 'ranked' );
		$page          = $this->request->page();
		$category_slug = $this->request->slug( 'ebd_category' );
		return $this->search_page->render_keyword_search(
			$terms,
			$term_modes,
			$sort,
			$page,
			$category_slug,
			$this->page_url( 'keyword_search' )
		);
	}

	/**
	 * Keeps legacy shortcode content usable after Ask AI 1 retirement.
	 *
	 * @return string Ask AI markup.
	 */
	public function ask_question_shortcode(): string {
		return $this->ask_ai_shortcode();
	}

	/**
	 * Renders semantic title-and-summary retrieval followed by AI refinement.
	 *
	 * @return string Ask AI markup.
	 */
	public function ask_ai_shortcode(): string {
		if ( 'complete' !== Plugin::status_data()['import_state'] ) {
			return $this->not_ready();
		}
		Assets::enqueue();
		$question = $this->request->value( 'ebd_question' );
		$sort     = $this->request->value( 'ebd_sort', 'ranked' );
		$status   = ( new Semantic_Index_Service() )->status();

		return $this->semantic_ask_ai_page->render(
			$question,
			$sort,
			$status,
			AI_Settings::is_configured(),
			Embedding_Service::is_configured(),
			$this->page_url( 'ask_ai' )
		);
	}

	/**
	 * Renders the requested browse-path view.
	 *
	 * @param array<string> $attributes Shortcode attributes.
	 * @param string|null   $content    Enclosed shortcode content.
	 * @param string        $tag        Shortcode tag.
	 * @return string Browse interface markup.
	 */
	public function browse_topics_shortcode( array $attributes, ?string $content = null, string $tag = '' ): string {
		unset( $content, $tag );
		if ( 'complete' !== Plugin::status_data()['import_state'] ) {
			return $this->not_ready();
		}
		Assets::enqueue();
		$attributes    = shortcode_atts( array( 'path' => '1' ), $attributes, 'ehrman_browse_topics' );
		$path_number   = '2' === Database::text( $attributes['path'] ) ? 2 : 1;
		$subject_slug  = $this->request->slug( 'ebd_subject' );
		$category_slug = $this->request->slug( 'ebd_category' );
		$topic_slug    = $this->request->slug( 'ebd_topic' );
		$view          = $this->request->key( 'ebd_view' );
		$terms         = $this->request->terms();

		return $this->browse_page->render(
			$path_number,
			$subject_slug,
			$category_slug,
			$topic_slug,
			$view,
			$terms,
			$this->search->resolve_term_modes( $terms, $this->request->term_modes() ),
			$this->request->value( 'ebd_sort', 'ranked' ),
			$this->request->page()
		);
	}

	/**
	 * Renders the reviewer-only subject-area, category, and topic outline.
	 *
	 * @return string Structure-review markup.
	 */
	public function structure_review_shortcode(): string {
		if ( 'complete' !== Plugin::status_data()['import_state'] ) {
			return $this->not_ready();
		}
		Assets::enqueue();
		return $this->structure_review->render(
			'2' === $this->request->value( 'ebd_path' ) ? 2 : 1,
			$this->request->key( 'ebd_view' ),
			$this->page_url( 'structure_review' )
		);
	}

	/**
	 * Returns the notice shown before discovery data is imported.
	 *
	 * @return string Not-ready notice markup.
	 */
	private function not_ready(): string {
		Assets::enqueue();
		return '<div class="ebd-notice">' . esc_html__( 'Discovery data has not been imported yet.', 'ehrman-blog-discovery' ) . '</div>';
	}

	/**
	 * Resolves a managed discovery page URL.
	 *
	 * @param string $key Managed page option key.
	 * @return string Page permalink or the site home URL.
	 */
	private function page_url( string $key ): string {
		$page_id = Database::integer( get_option( 'ehrman_discovery_page_' . $key, 0 ) );
		$url     = $page_id > 0 ? get_permalink( $page_id ) : false;
		return false === $url ? home_url( '/' ) : $url;
	}

	/**
	 * Builds a browse-path URL with optional query arguments.
	 *
	 * @param int                 $path_number Browse-path number.
	 * @param array<string,mixed> $args        Optional query arguments.
	 * @return string Browse URL.
	 */
	private function browse_url( int $path_number, array $args = array() ): string {
		$url = $this->page_url( 2 === $path_number ? 'browse_2' : 'browse_1' );
		return empty( $args ) ? $url : add_query_arg( $args, $url );
	}
}
