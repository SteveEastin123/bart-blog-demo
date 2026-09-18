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
	 * Topic-and-keyword Ask AI page renderer.
	 *
	 * @var Taxonomy_Ask_AI_Page_Renderer
	 */
	private Taxonomy_Ask_AI_Page_Renderer $taxonomy_ask_ai_page;

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
		$this->taxonomy_ask_ai_page = new Taxonomy_Ask_AI_Page_Renderer( $this->markup, $this->search_page );
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
		add_shortcode( 'ehrman_ask_question', array( $this, 'ask_question_shortcode' ) );
		add_shortcode( 'ehrman_ask_ai_2', array( $this, 'ask_ai_2_shortcode' ) );
		add_shortcode( 'ehrman_browse_topics', array( $this, 'browse_topics_shortcode' ) );
		add_shortcode( 'ehrman_structure_review', array( $this, 'structure_review_shortcode' ) );
		add_filter( 'wp_robots', array( $this, 'review_page_robots' ) );
		add_action( 'template_redirect', array( $this, 'redirect_legacy_ask_ai' ), 5 );
	}

	/** Creates or reconnects the public discovery pages. */
	public static function ensure_pages(): void {
		$pages = array(
			'keyword_search'   => array( 'Keyword Search', 'keyword-search', '[ehrman_keyword_search]' ),
			'ask_question'     => array( 'Ask AI 1', 'ask-ai', '[ehrman_ask_question]' ),
			'ask_ai_2'         => array( 'Ask AI 2', 'ask-ai-2', '[ehrman_ask_ai_2]' ),
			'browse_1'         => array( 'Browse Topics 1', 'browse-topics-1', '[ehrman_browse_topics path="1"]' ),
			'browse_2'         => array( 'Browse Topics 2', 'browse-topics-2', '[ehrman_browse_topics path="2"]' ),
			'structure_review' => array( 'Structure Review', 'structure-review', '[ehrman_structure_review]' ),
		);

		foreach ( $pages as $key => [$title, $slug, $content] ) {
			$option  = 'ehrman_discovery_page_' . $key;
			$page_id = Database::integer( get_option( $option, 0 ) );
			if ( $page_id > 0 && 'trash' !== get_post_status( $page_id ) ) {
				self::update_managed_page( $key, $page_id, $title, $slug );
				continue;
			}
			$existing = get_page_by_path( $slug, OBJECT, 'page' );
			if ( $existing instanceof \WP_Post ) {
				self::update_managed_page( $key, $existing->ID, $title, $slug );
				update_option( $option, $existing->ID, false );
				continue;
			}
			if ( 'ask_question' === $key ) {
				$legacy = get_page_by_path( 'ask-a-question', OBJECT, 'page' );
				if ( $legacy instanceof \WP_Post ) {
					self::update_managed_page( $key, $legacy->ID, $title, $slug );
					update_option( $option, $legacy->ID, false );
					continue;
				}
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

	/**
	 * Applies title and slug migrations to plugin-managed pages.
	 *
	 * @param string $key     Managed page key.
	 * @param int    $page_id WordPress page ID.
	 * @param string $title   Current canonical title.
	 * @param string $slug    Current canonical slug.
	 */
	private static function update_managed_page( string $key, int $page_id, string $title, string $slug ): void {
		if ( 'ask_question' !== $key ) {
			return;
		}
		$changes = array( 'ID' => $page_id );
		if ( get_the_title( $page_id ) !== $title ) {
			$changes['post_title'] = $title;
		}
		if ( get_post_field( 'post_name', $page_id ) !== $slug ) {
			$changes['post_name'] = $slug;
		}
		if ( count( $changes ) > 1 ) {
			wp_update_post( $changes );
		}
	}

	/** Permanently redirects the former Ask AI path to its canonical URL. */
	public function redirect_legacy_ask_ai(): void {
		if ( is_admin() || ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$request_uri  = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$request_path = wp_parse_url( $request_uri, PHP_URL_PATH );
		$legacy_path  = wp_parse_url( home_url( '/ask-a-question/' ), PHP_URL_PATH );
		if ( ! is_string( $request_path ) || ! is_string( $legacy_path ) || untrailingslashit( $request_path ) !== untrailingslashit( $legacy_path ) ) {
			return;
		}

		// Preserve interpreted-search arguments in old bookmarks and shared links.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect parameters.
		$query_args = map_deep( wp_unslash( $_GET ), 'sanitize_text_field' );
		$target     = empty( $query_args ) ? $this->page_url( 'ask_question' ) : add_query_arg( $query_args, $this->page_url( 'ask_question' ) );
		wp_safe_redirect( $target, 301, 'Ehrman Blog Discovery' );
		exit;
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
	 * Renders the AI-assisted natural-language search interface.
	 *
	 * @return string Question interpretation and post results markup.
	 */
	public function ask_question_shortcode(): string {
		if ( 'complete' !== Plugin::status_data()['import_state'] ) {
			return $this->not_ready();
		}
		Assets::enqueue();
		$question   = $this->request->value( 'ebd_question' );
		$request_id = $this->request->value( 'ebd_ai_request' );
		$terms      = $this->request->terms();
		$term_modes = $this->search->resolve_term_modes( $terms, $this->request->term_modes() );
		$sort       = $this->request->value( 'ebd_sort', 'ranked' );
		$page       = $this->request->page();
		$has_terms  = ! empty( $terms );
		$result     = $has_terms
			? $this->search->search( $terms, $sort, '', '', $page, Search_Service::POSTS_PER_PAGE, $term_modes )
			: array(
				'posts'       => array(),
				'terms'       => array(),
				'sort'        => 'ranked',
				'count'       => 0,
				'page'        => 1,
				'per_page'    => Search_Service::POSTS_PER_PAGE,
				'total_pages' => 0,
			);
		if ( $has_terms && '' !== $request_id ) {
			AI_Requests::set_result_count( $request_id, Database::integer( $result['count'] ) );
		}
		$auto_refine = $has_terms
			&& Database::integer( $result['count'] ) > 0
			&& '' !== trim( $question )
			&& 1 === preg_match( '/^[a-f0-9-]{36}$/', $request_id );

		return $this->taxonomy_ask_ai_page->render(
			$question,
			$request_id,
			$terms,
			$term_modes,
			$result,
			$auto_refine,
			AI_Interpreter::is_configured(),
			$this->page_url( 'ask_question' )
		);
	}

	/**
	 * Renders semantic title-and-summary retrieval followed by AI refinement.
	 *
	 * @return string Ask AI 2 markup.
	 */
	public function ask_ai_2_shortcode(): string {
		if ( 'complete' !== Plugin::status_data()['import_state'] ) {
			return $this->not_ready();
		}
		Assets::enqueue();
		$question = $this->request->value( 'ebd_question' );
		$sort     = $this->request->value( 'ebd_sort', 'ranked' );
		$status   = ( new Semantic_Search_Service() )->status();

		return $this->semantic_ask_ai_page->render(
			$question,
			$sort,
			$status,
			AI_Interpreter::is_configured(),
			Embedding_Service::is_configured(),
			$this->page_url( 'ask_ai_2' )
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
