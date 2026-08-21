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
	private const BACK_TO_TOP_THRESHOLD = 10;

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
	 * Counter used to generate unique control identifiers.
	 *
	 * @var int
	 */
	private int $instance = 0;

	/** Creates the page controller and its data services. */
	public function __construct() {
		$this->browse = new Browse_Service();
		$this->search = new Search_Service();
	}

	/** Registers the plugin's public shortcodes. */
	public function register(): void {
		add_shortcode( 'ehrman_keyword_search', array( $this, 'keyword_search_shortcode' ) );
		add_shortcode( 'ehrman_ask_question', array( $this, 'ask_question_shortcode' ) );
		add_shortcode( 'ehrman_browse_topics', array( $this, 'browse_topics_shortcode' ) );
		add_shortcode( 'ehrman_structure_review', array( $this, 'structure_review_shortcode' ) );
		add_filter( 'wp_robots', array( $this, 'review_page_robots' ) );
	}

	/** Creates or reconnects the public discovery pages. */
	public static function ensure_pages(): void {
		$pages = array(
			'keyword_search'   => array( 'Keyword Search', 'keyword-search', '[ehrman_keyword_search]' ),
			'ask_question'     => array( 'Ask a Question', 'ask-a-question', '[ehrman_ask_question]' ),
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
		$terms         = $this->request_terms();
		$term_modes    = $this->request_term_modes( $terms );
		$sort          = $this->request_value( 'ebd_sort', 'ranked' );
		$page          = $this->request_page();
		$category_slug = sanitize_title( $this->request_value( 'ebd_category' ) );
		$categories    = $this->browse->category_options();
		$category      = $this->find_by_slug( $categories, $category_slug );
		if ( null === $category ) {
			$category_slug = '';
		}
		$has_request = ! empty( $terms ) || '' !== $category_slug;
		$result      = $has_request
			? $this->search->search( $terms, $sort, $category_slug, '', $page, Search_Service::POSTS_PER_PAGE, $term_modes )
			: array(
				'posts'       => array(),
				'terms'       => array(),
				'sort'        => 'ranked',
				'count'       => 0,
				'page'        => 1,
				'per_page'    => Search_Service::POSTS_PER_PAGE,
				'total_pages' => 0,
			);
		$context     = null === $category ? '' : Database::text( $category['name'] ?? null );

		return $this->shell(
			$this->search_panel(
				$result['terms'],
				$term_modes,
				$result['sort'],
				true,
				$this->page_url( 'keyword_search' ),
				'',
				'',
				$categories,
				$category_slug
			)
			. '<div id="ebd-results" class="ebd-results" data-ebd-results data-context="' . esc_attr( $context ) . '">'
			. ( $has_request ? $this->results_markup( $result, $context ) : '' )
			. '</div>',
			'keyword-search'
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
		$question   = $this->request_value( 'ebd_question' );
		$request_id = sanitize_text_field( $this->request_value( 'ebd_ai_request' ) );
		$terms      = $this->request_terms();
		$term_modes = $this->request_term_modes( $terms );
		$sort       = $this->request_value( 'ebd_sort', 'ranked' );
		$page       = $this->request_page();
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

		return $this->shell(
			$this->question_panel( $question, $result['sort'] )
			. ( $has_terms
				? $this->search_panel(
					$terms,
					$term_modes,
					$result['sort'],
					true,
					$this->page_url( 'ask_question' ),
					'',
					'',
					null,
					'',
					'<input type="hidden" name="ebd_question" value="' . esc_attr( $question ) . '"><input type="hidden" name="ebd_ai_request" value="' . esc_attr( $request_id ) . '">',
					true
				)
				: '<div class="ebd-description-control">' . $this->description_control( 'always', 'posts' ) . '</div>' )
			. '<div id="ebd-results" class="ebd-results" data-ebd-results>'
			. ( $has_terms ? $this->results_markup( $result, '', $question, $request_id ) : '' )
			. '</div>',
			'ask-question'
		);
	}

	/**
	 * Builds the Ask a Question form and interpretation review.
	 *
	 * @param string $question Reader question.
	 * @param string $sort     Selected result order.
	 * @return string Question form markup.
	 */
	private function question_panel( string $question, string $sort ): string {
		++$this->instance;
		$id           = 'ebd-question-' . $this->instance;
		$sort_options = array();
		foreach ( array(
			'ranked' => 'Best match',
			'newest' => 'Newest first',
			'oldest' => 'Oldest first',
		) as $value => $label ) {
			$sort_options[] = '<label class="ebd-sort-choice"><input type="radio" name="ebd_sort" value="' . esc_attr( $value )
				. '"' . checked( $sort, $value, false ) . '><span>' . esc_html( $label ) . '</span></label>';
		}
		$configured_message = AI_Interpreter::is_configured()
			? ''
			: '<p class="ebd-question-configuration">' . esc_html__( 'Local AI credentials must be configured before questions can be interpreted.', 'ehrman-blog-discovery' ) . '</p>';
		$review_markup      = '<section class="ebd-question-review" data-ebd-question-review hidden><h3>'
			. esc_html__( 'Review the interpreted search', 'ehrman-blog-discovery' ) . '</h3><p>'
			. esc_html__( 'These are the topics and keywords selected from your question. Remove any that do not reflect what you intended.', 'ehrman-blog-discovery' )
			. '</p><ul data-ebd-question-terms></ul><div class="ebd-sort-row"><span>'
			. esc_html__( 'Sort by', 'ehrman-blog-discovery' ) . '</span>' . implode( '', $sort_options )
			. '</div><div class="ebd-question-actions"><button type="submit" class="ebd-question-search" data-ebd-question-search disabled>'
			. esc_html__( 'Search posts', 'ehrman-blog-discovery' ) . '</button></div></section>';

		return '<form class="ebd-question-panel" action="' . esc_url( $this->page_url( 'ask_question' ) )
			. '" method="get" data-ebd-question-form><input type="hidden" name="ebd_ai_request" value="" data-ebd-ai-request><div id="' . esc_attr( $id )
			. '-controls" data-ebd-question-expanded><label for="' . esc_attr( $id ) . '"><strong>'
			. esc_html__( 'What would you like to find?', 'ehrman-blog-discovery' ) . '</strong></label><p class="ebd-question-help">'
			. esc_html__( 'Ask a question. AI will identify relevant topics and keywords to find matching posts on Bart\'s blog. It searches the blog but does not generate answers or summarize Bart\'s views.', 'ehrman-blog-discovery' )
			. '</p><textarea id="' . esc_attr( $id ) . '" name="ebd_question" rows="3" maxlength="800" required '
			. 'placeholder="' . esc_attr__( 'Example: What does Luke say about Jesus\' death and atonement?', 'ehrman-blog-discovery' )
			. '" data-ebd-question-input>' . esc_textarea( $question ) . '</textarea><div class="ebd-question-actions">'
			. '<button type="button" class="ebd-question-interpret" data-ebd-question-interpret'
			. ( AI_Interpreter::is_configured() ? '' : ' disabled' ) . '>' . esc_html__( 'Submit', 'ehrman-blog-discovery' )
			. '</button><button type="button" class="ebd-question-clear" data-ebd-question-clear>'
			. esc_html__( 'Clear', 'ehrman-blog-discovery' ) . '</button></div>' . $configured_message
			. '<p class="ebd-question-status" data-ebd-question-status aria-live="polite"></p>'
			. $review_markup . '</div></form>';
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
		$subject_slug  = sanitize_title( $this->request_value( 'ebd_subject' ) );
		$category_slug = sanitize_title( $this->request_value( 'ebd_category' ) );
		$topic_slug    = sanitize_title( $this->request_value( 'ebd_topic' ) );
		$view          = sanitize_key( $this->request_value( 'ebd_view' ) );

		if ( '' !== $topic_slug ) {
			return $this->render_topic_posts( $path_number, $subject_slug, $category_slug, $topic_slug );
		}
		if ( '' !== $category_slug && 'posts' === $view ) {
			return $this->render_category_posts( $path_number, $subject_slug, $category_slug );
		}
		if ( '' !== $category_slug ) {
			return $this->render_category( $path_number, $subject_slug, $category_slug );
		}
		if ( '' !== $subject_slug ) {
			return $this->render_subject_area( $path_number, $subject_slug );
		}
		return $this->render_subject_areas( $path_number );
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
		$path_number    = '2' === $this->request_value( 'ebd_path' ) ? 2 : 1;
		$category_index = 'categories' === sanitize_key( $this->request_value( 'ebd_view' ) );
		$areas          = $category_index ? array() : $this->browse->subject_areas( $path_number );
		$sections       = array();

		if ( $category_index ) {
			$categories = $this->browse->categories();
			$sections   = $this->structure_review_categories( $categories );
			$meta       = $this->plural( count( $categories ), 'category', 'categories' );
			$intro      = __( 'Expand a category to review its assigned topics and post counts.', 'ehrman-blog-discovery' );
		} else {
			foreach ( $areas as $area ) {
				$categories        = $this->browse->subject_area_categories( Database::integer( $area['id'] ?? null ) );
				$category_sections = $this->structure_review_categories( $categories );
				$area_meta         = $this->plural( Database::integer( $area['category_count'] ?? null ), 'category', 'categories' ) . ' &bull; '
					. $this->plural( Database::integer( $area['topic_count'] ?? null ), 'topic' ) . ' &bull; '
					. $this->plural( Database::integer( $area['post_count'] ?? null ), 'post' );
				$sections[]        = '<details class="ebd-review-area" open><summary><span class="ebd-review-name">'
					. '<span class="ebd-review-badge is-subject">' . esc_html__( 'Subject Area', 'ehrman-blog-discovery' ) . '</span><span>'
					. esc_html( Database::text( $area['name'] ?? null ) ) . '</span></span><span class="ebd-review-meta">'
					. wp_kses_post( $area_meta ) . '</span></summary><p class="ebd-review-description" hidden>'
					. esc_html( Database::text( $area['description'] ?? null ) ) . '</p><div class="ebd-review-categories">'
					. implode( '', $category_sections ) . '</div></details>';
			}
			$meta  = $this->plural( count( $areas ), 'subject area' ) . ' &bull; '
				. $this->plural( count( $this->browse->categories() ), 'category', 'categories' );
			$intro = __( 'Expand a subject area and its categories to review where topics are currently assigned.', 'ehrman-blog-discovery' );
		}

		$path_links = '<nav class="ebd-review-paths" aria-label="' . esc_attr__( 'Browse Topics structure', 'ehrman-blog-discovery' ) . '">'
			. $this->structure_review_path_link( 1, 1 === $path_number && ! $category_index )
			. $this->structure_review_path_link( 2, 2 === $path_number && ! $category_index )
			. $this->structure_review_category_link( $category_index )
			. '</nav>';
		$controls   = '<div class="ebd-review-controls"><button type="button" data-ebd-review-expand>'
			. esc_html__( 'Expand all', 'ehrman-blog-discovery' ) . '</button><button type="button" data-ebd-review-collapse>'
			. esc_html__( 'Collapse all', 'ehrman-blog-discovery' ) . '</button><button type="button" data-ebd-review-pdf>'
			. esc_html__( 'Download PDF', 'ehrman-blog-discovery' ) . '</button><button type="button" data-ebd-review-csv>'
			. esc_html__( 'Download CSV', 'ehrman-blog-discovery' ) . '</button>'
			. $this->description_control( 'hover', 'review' ) . '</div>';

		return $this->shell(
			$this->heading(
				__( 'Category and Topic Review', 'ehrman-blog-discovery' ),
				$meta
			)
			. '<p class="ebd-review-intro">'
			. esc_html( $intro )
			. '</p>' . $path_links . $controls . '<div class="ebd-review-tree'
			. ( $category_index ? ' is-category-index' : '' ) . '" data-ebd-review-tree>'
			. implode( '', $sections ) . '</div>',
			'structure-review'
		);
	}

	/**
	 * Renders the subject-area index for a browse path.
	 *
	 * @param int $path_number Browse-path number.
	 * @return string Subject-area list markup.
	 */
	private function render_subject_areas( int $path_number ): string {
		$areas = $this->browse->subject_areas( $path_number );
		$items = array();
		foreach ( $areas as $area ) {
			$url     = $this->browse_url( $path_number, array( 'ebd_subject' => Database::text( $area['slug'] ?? null ) ) );
			$meta    = $this->plural( Database::integer( $area['category_count'] ?? null ), 'category', 'categories' ) . ' &bull; '
				. $this->plural( Database::integer( $area['topic_count'] ?? null ), 'topic' ) . ' &bull; '
				. $this->plural( Database::integer( $area['post_count'] ?? null ), 'post' );
			$items[] = $this->browse_item(
				Database::text( $area['name'] ?? null ),
				$url,
				$meta,
				Database::text( $area['description'] ?? null ),
				true
			);
		}
		return $this->shell(
			$this->heading( __( 'Choose a Subject Area', 'ehrman-blog-discovery' ), $this->plural( count( $areas ), 'subject area' ) )
			. $this->description_control( 'hover', 'browse' )
			. '<ul class="ebd-item-list">' . implode( '', $items ) . '</ul>',
			'browse'
		);
	}

	/**
	 * Renders the categories within a subject area.
	 *
	 * @param int    $path_number Browse-path number.
	 * @param string $subject_slug Subject-area slug.
	 * @return string Category list markup.
	 */
	private function render_subject_area( int $path_number, string $subject_slug ): string {
		$area = $this->browse->subject_area( $path_number, $subject_slug );
		if ( null === $area ) {
			return $this->not_found();
		}
		$area_id    = Database::integer( $area['id'] ?? null );
		$categories = $this->browse->subject_area_categories( $area_id );
		$counts     = $this->browse->subject_area_counts( $area_id );
		$items      = array();
		foreach ( $categories as $category ) {
			$url     = $this->browse_url(
				$path_number,
				array(
					'ebd_subject'  => $subject_slug,
					'ebd_category' => Database::text( $category['slug'] ?? null ),
				)
			);
			$meta    = $this->plural( Database::integer( $category['topic_count'] ?? null ), 'topic' ) . ' &bull; '
				. $this->plural( Database::integer( $category['post_count'] ?? null ), 'post' );
			$items[] = $this->browse_item(
				Database::text( $category['name'] ?? null ),
				$url,
				$meta,
				Database::text( $category['description'] ?? null ),
				true
			);
		}
		$meta        = $this->plural( (int) $counts['category_count'], 'category', 'categories' ) . ' &bull; '
			. $this->plural( (int) $counts['topic_count'], 'topic' ) . ' &bull; '
			. $this->plural( (int) $counts['post_count'], 'post' );
		$breadcrumbs = array(
			array( 'Browse Topics ' . $path_number, $this->browse_url( $path_number ) ),
			array( Database::text( $area['name'] ?? null ), '' ),
		);
		return $this->shell(
			$this->heading( Database::text( $area['name'] ?? null ), $meta, $breadcrumbs )
			. $this->description_control( 'hover', 'browse' )
			. '<ul class="ebd-item-list">' . implode( '', $items ) . '</ul>',
			'browse'
		);
	}

	/**
	 * Renders the topics within a category.
	 *
	 * @param int    $path_number  Browse-path number.
	 * @param string $subject_slug Subject-area slug.
	 * @param string $category_slug Category slug.
	 * @return string Topic list markup.
	 */
	private function render_category( int $path_number, string $subject_slug, string $category_slug ): string {
		$category = $this->browse->category( $category_slug );
		if ( null === $category ) {
			return $this->not_found();
		}
		$category_id = Database::integer( $category['id'] ?? null );
		$area        = $this->browse->primary_subject_area( $path_number, $category_id, $subject_slug );
		$topics      = $this->browse->category_topics( $category_id );
		$post_count  = $this->browse->category_post_count( $category_id );
		$items       = array();
		foreach ( $topics as $topic ) {
			$args = array(
				'ebd_category' => $category_slug,
				'ebd_topic'    => Database::text( $topic['slug'] ?? null ),
			);
			if ( null !== $area ) {
				$args['ebd_subject'] = Database::text( $area['slug'] ?? null );
			}
			$items[] = $this->browse_item(
				Database::text( $topic['name'] ?? null ),
				$this->browse_url( $path_number, $args ),
				$this->plural( Database::integer( $topic['post_count'] ?? null ), 'post' ),
				Database::text( $topic['description'] ?? null ),
				true
			);
		}
		$post_args = array(
			'ebd_category' => $category_slug,
			'ebd_view'     => 'posts',
		);
		if ( null !== $area ) {
			$post_args['ebd_subject'] = Database::text( $area['slug'] ?? null );
		}
		/* translators: %s: formatted number of posts. */
		$view_all_label                              = sprintf( __( 'View all %s in this category', 'ehrman-blog-discovery' ), $this->plural( $post_count, 'post' ) );
		$actions                                     = '<a class="ebd-primary-link" href="' . esc_url( $this->browse_url( $path_number, $post_args ) ) . '">'
			. esc_html( $view_all_label )
			. '</a>';
		$breadcrumbs                                 = $this->category_breadcrumbs( $path_number, $category, $area );
		$breadcrumbs[ count( $breadcrumbs ) - 1 ][1] = '';
		/**
		 * Validated breadcrumb tuples.
		 *
		 * @var list<array{0:string,1:string}> $breadcrumbs
		 */
		return $this->shell(
			$this->heading(
				Database::text( $category['name'] ?? null ),
				$this->plural( count( $topics ), 'topic' ) . ' &bull; ' . $this->plural( $post_count, 'post' ),
				$breadcrumbs,
				$actions
			)
			. $this->description_control( 'hover', 'browse' )
			. '<ul class="ebd-item-list">' . implode( '', $items ) . '</ul>',
			'browse'
		);
	}

	/**
	 * Renders and filters the posts assigned to a topic.
	 *
	 * @param int    $path_number  Browse-path number.
	 * @param string $subject_slug Subject-area slug.
	 * @param string $category_slug Category slug.
	 * @param string $topic_slug   Topic slug.
	 * @return string Topic-post view markup.
	 */
	private function render_topic_posts(
		int $path_number,
		string $subject_slug,
		string $category_slug,
		string $topic_slug
	): string {
		$topic = $this->browse->topic( $topic_slug );
		if ( null === $topic ) {
			return $this->not_found();
		}
		$category   = $this->browse->topic_category( Database::integer( $topic['id'] ?? null ), $category_slug );
		$area       = null === $category
			? null
			: $this->browse->primary_subject_area( $path_number, Database::integer( $category['id'] ?? null ), $subject_slug );
		$terms      = $this->request_terms();
		$term_modes = $this->request_term_modes( $terms );
		if ( empty( $terms ) ) {
			$terms      = array( Database::text( $topic['name'] ?? null ) );
			$term_modes = array( Search_Service::TERM_MODE_TOPIC );
		}
		$sort        = $this->request_value( 'ebd_sort', 'ranked' );
		$page        = $this->request_page();
		$result      = $this->search->search( $terms, $sort, '', $topic_slug, $page, Search_Service::POSTS_PER_PAGE, $term_modes );
		$breadcrumbs = null === $category
			? array()
			: array_merge(
				$this->category_breadcrumbs( $path_number, $category, $area ),
				array( array( Database::text( $topic['name'] ?? null ), '' ) )
			);
		/**
		 * Validated breadcrumb tuples.
		 *
		 * @var list<array{0:string,1:string}> $breadcrumbs
		 */
		return $this->shell(
			$this->heading( Database::text( $topic['name'] ?? null ), $this->plural( $result['count'], 'post' ), $breadcrumbs, '', true )
			. $this->search_panel(
				$result['terms'],
				$term_modes,
				$result['sort'],
				true,
				$this->browse_url(
					$path_number,
					array_filter(
						array(
							'ebd_subject'  => null === $area ? '' : Database::text( $area['slug'] ?? null ),
							'ebd_category' => null === $category ? '' : Database::text( $category['slug'] ?? null ),
							'ebd_topic'    => $topic_slug,
						)
					)
				),
				'',
				$topic_slug
			)
			. '<div id="ebd-results" class="ebd-results" data-ebd-results data-context="' . esc_attr( Database::text( $topic['name'] ?? null ) ) . '">'
			. $this->results_markup( $result, Database::text( $topic['name'] ?? null ) ) . '</div>',
			'posts'
		);
	}

	/**
	 * Renders and filters all posts connected to a category.
	 *
	 * @param int    $path_number  Browse-path number.
	 * @param string $subject_slug Subject-area slug.
	 * @param string $category_slug Category slug.
	 * @return string Category-post view markup.
	 */
	private function render_category_posts( int $path_number, string $subject_slug, string $category_slug ): string {
		$category = $this->browse->category( $category_slug );
		if ( null === $category ) {
			return $this->not_found();
		}
		$area        = $this->browse->primary_subject_area( $path_number, Database::integer( $category['id'] ?? null ), $subject_slug );
		$terms       = $this->request_terms();
		$term_modes  = $this->request_term_modes( $terms );
		$sort        = $this->request_value( 'ebd_sort', 'ranked' );
		$page        = $this->request_page();
		$result      = $this->search->search( $terms, $sort, $category_slug, '', $page, Search_Service::POSTS_PER_PAGE, $term_modes );
		$breadcrumbs = array_merge(
			$this->category_breadcrumbs( $path_number, $category, $area ),
			array( array( __( 'Posts', 'ehrman-blog-discovery' ), '' ) )
		);
		$form_args   = array(
			'ebd_category' => $category_slug,
			'ebd_view'     => 'posts',
		);
		if ( null !== $area ) {
			$form_args['ebd_subject'] = Database::text( $area['slug'] ?? null );
		}
		return $this->shell(
			$this->heading( Database::text( $category['name'] ?? null ), $this->plural( $result['count'], 'post' ), $breadcrumbs, '', true )
			. $this->search_panel(
				$result['terms'],
				$term_modes,
				$result['sort'],
				true,
				$this->browse_url( $path_number, $form_args ),
				$category_slug
			)
			. '<div id="ebd-results" class="ebd-results" data-ebd-results data-context="' . esc_attr( Database::text( $category['name'] ?? null ) ) . '">'
			. $this->results_markup( $result, Database::text( $category['name'] ?? null ) ) . '</div>',
			'posts'
		);
	}

	/**
	 * Builds the reusable search controls for keyword and browse views.
	 *
	 * @param array<int,string>                   $terms             Selected search terms.
	 * @param array<int,string>                   $term_modes        Search modes aligned with selected terms.
	 * @param string                              $sort              Requested sort mode.
	 * @param bool                                $show_descriptions Whether descriptions are initially visible.
	 * @param string                              $action            Form action URL.
	 * @param string                              $category_scope    Fixed category slug.
	 * @param string                              $topic_scope       Fixed topic slug.
	 * @param array<int,array<string,mixed>>|null $category_options Optional category choices.
	 * @param string                              $selected_category Selected category slug.
	 * @param string                              $extra_fields      Additional hidden form fields.
	 * @param bool                                $ai_interpretation Whether terms came from Ask AI.
	 * @return string Search-panel markup.
	 */
	private function search_panel(
		array $terms,
		array $term_modes,
		string $sort,
		bool $show_descriptions,
		string $action,
		string $category_scope = '',
		string $topic_scope = '',
		?array $category_options = null,
		string $selected_category = '',
		string $extra_fields = '',
		bool $ai_interpretation = false
	): string {
		++$this->instance;
		$id         = 'ebd-search-' . $this->instance;
		$terms      = Search_Service::unique_terms( $terms );
		$sort       = in_array( $sort, array( 'ranked', 'newest', 'oldest' ), true ) ? $sort : 'ranked';
		$chips      = array();
		$term_modes = $this->search->resolve_term_modes( $terms, $term_modes );
		foreach ( $terms as $index => $term ) {
			$type = $term_modes[ $index ] ?? Search_Service::TERM_MODE_KEYWORD;
			if ( Search_Service::TERM_MODE_TOPIC === $type ) {
				$type_label = __( 'Topic', 'ehrman-blog-discovery' );
			} else {
				$type_label = __( 'Keyword', 'ehrman-blog-discovery' );
			}
			/* translators: %s: selected search term. */
			$remove_label = sprintf( __( 'Remove %s', 'ehrman-blog-discovery' ), $term );
			/* translators: %s: search term type, either Topic or Keyword. */
			$type_accessible_label = sprintf( __( 'Term type: %s', 'ehrman-blog-discovery' ), $type_label );
			$chips[]               = '<span class="ebd-keyword-slot ebd-keyword-chip"><input type="hidden" name="ebd_keyword[]" value="'
				. esc_attr( $term ) . '"><input type="hidden" name="ebd_term_mode[]" value="' . esc_attr( $type )
				. '"><span class="ebd-keyword-chip-content"><span class="ebd-keyword-chip-label">'
				. esc_html( $term ) . '</span><span class="ebd-selected-term-badge is-' . esc_attr( $type )
				. '" aria-label="' . esc_attr( $type_accessible_label ) . '">' . esc_html( $type_label )
				. '</span></span><button type="button" '
				. 'class="ebd-keyword-remove" data-ebd-remove aria-label="' . esc_attr( $remove_label )
				. '">&times;</button></span>';
		}
		$next         = count( $terms ) + 1;
		$input_hidden = count( $terms ) >= Search_Service::MAX_TERMS;
		$chips[]      = '<div class="ebd-keyword-slot ebd-keyword-input-wrap"'
			. ( $input_hidden ? ' hidden' : '' ) . '><input id="' . esc_attr( $id )
			. '-input" class="ebd-keyword-input" type="text" placeholder="Keyword ' . min( $next, 4 )
			. '" role="combobox" aria-label="Keyword ' . min( $next, 4 ) . '" autocomplete="off" aria-autocomplete="list" '
			. 'aria-expanded="false" aria-controls="'
			. esc_attr( $id ) . '-suggestions"' . ( $input_hidden ? ' disabled' : '' ) . '><ul id="'
			. esc_attr( $id ) . '-suggestions" class="ebd-suggestions" role="listbox" hidden></ul></div>';
		if ( ! $input_hidden ) {
			++$next;
		}
		while ( $next <= Search_Service::MAX_TERMS ) {
			$chips[] = '<span class="ebd-keyword-slot ebd-keyword-empty">Keyword ' . $next . '</span>';
			++$next;
		}

		$scope = '';
		if ( '' !== $category_scope ) {
			$category = $this->browse->category( $category_scope );
			if ( null !== $category ) {
				$scope = '<div class="ebd-fixed-scope"><strong>' . esc_html__( 'Category:', 'ehrman-blog-discovery' )
					. '</strong> ' . esc_html( Database::text( $category['name'] ?? null ) ) . '</div>';
			}
		}
		$category_filter = null === $category_options
			? ''
			: $this->category_filter( $id, $category_options, $selected_category );
		$sort_options    = array();
		foreach ( array(
			'ranked' => 'Best match',
			'newest' => 'Newest first',
			'oldest' => 'Oldest first',
		) as $value => $label ) {
			$sort_options[] = '<label class="ebd-sort-choice"><input type="radio" name="ebd_sort" value="'
				. esc_attr( $value ) . '"' . checked( $sort, $value, false ) . '><span>' . esc_html( $label ) . '</span></label>';
		}
		$has_search_state = ! empty( $terms ) || '' !== $category_scope || '' !== $topic_scope || '' !== $selected_category;
		$suggestion_order = '<div class="ebd-sort-row ebd-suggestion-order-row" role="radiogroup" aria-label="'
			. esc_attr__( 'Order autocomplete suggestions', 'ehrman-blog-discovery' ) . '"><span>'
			. esc_html__( 'Order suggestions', 'ehrman-blog-discovery' ) . '</span>';
		foreach ( array(
			'popular'        => 'Most posts',
			'topics-first'   => 'Topics first',
			'keywords-first' => 'Keywords first',
		) as $value => $label ) {
			$suggestion_order .= '<label class="ebd-sort-choice"><input type="radio" name="ebd_suggestion_order" value="'
				. esc_attr( $value ) . '"' . checked( 'popular', $value, false ) . '><span>'
				. esc_html( $label ) . '</span></label>';
		}
		$suggestion_order .= '</div>';
		$summary_label     = $ai_interpretation ? __( 'Interpreted as:', 'ehrman-blog-discovery' ) : __( 'Current search:', 'ehrman-blog-discovery' );
		$edit_label        = $ai_interpretation ? __( 'Adjust search', 'ehrman-blog-discovery' ) : __( 'Edit search', 'ehrman-blog-discovery' );
		$compact_summary   = '<div class="ebd-search-compact" data-ebd-search-compact hidden><p class="ebd-search-compact-summary">'
			. '<strong>' . esc_html( $summary_label ) . '</strong> '
			. '<span data-ebd-search-summary></span></p><button type="button" class="ebd-search-edit" '
			. 'data-ebd-search-edit aria-controls="' . esc_attr( $id ) . '-controls">'
			. esc_html( $edit_label ) . '</button></div>';
		return '<form class="ebd-search-panel" action="' . esc_url( $action ) . '" method="get" data-ebd-search-form '
			. 'data-category="' . esc_attr( '' !== $category_scope ? $category_scope : $selected_category ) . '" '
			. 'data-topic="' . esc_attr( $topic_scope ) . '" data-ebd-initial-collapse="'
			. ( $has_search_state ? 'true' : 'false' ) . '">' . $extra_fields . $compact_summary
			. '<div id="' . esc_attr( $id ) . '-controls" class="ebd-search-controls" data-ebd-search-expanded>' . $scope . $category_filter
			. '<p class="ebd-search-instructions"><strong>' . esc_html__( 'Select up to four search terms.', 'ehrman-blog-discovery' )
			. '</strong> ' . esc_html__( 'You can enter topics, keywords, or both. Topics identify a post\'s main subjects, while keywords identify important people, texts, places, and related ideas. Combine multiple terms to narrow your results.', 'ehrman-blog-discovery' )
			. '</p>' . $suggestion_order . '<div class="ebd-keyword-grid" data-ebd-chip-list>' . implode( '', $chips ) . '</div>'
			. '<div class="ebd-sort-row ebd-post-sort-row"><span>' . esc_html__( 'Sort by', 'ehrman-blog-discovery' ) . '</span>'
			. implode( '', $sort_options ) . '</div><div class="ebd-search-actions"><button type="button" class="ebd-clear" data-ebd-clear>'
			. esc_html__( 'Clear all', 'ehrman-blog-discovery' ) . '</button><button type="button" class="ebd-search-collapse" '
			. 'data-ebd-search-collapse aria-controls="' . esc_attr( $id ) . '-controls"'
			. ( $has_search_state ? '' : ' hidden' ) . '>' . esc_html__( 'Hide search controls', 'ehrman-blog-discovery' )
			. '</button></div></div></form>'
			. '<div class="ebd-description-control">' . $this->description_control( $show_descriptions ? 'always' : 'hover', 'posts' ) . '</div>';
	}

	/**
	 * Builds the category selector used by standalone keyword search.
	 *
	 * @param string                         $id            Unique control prefix.
	 * @param array<int,array<string,mixed>> $categories    Available categories.
	 * @param string                         $selected_slug Selected category slug.
	 * @return string Category-filter markup.
	 */
	private function category_filter( string $id, array $categories, string $selected_slug ): string {
		$selected = $this->find_by_slug( $categories, $selected_slug );
		$name     = null === $selected ? __( 'All categories', 'ehrman-blog-discovery' ) : Database::text( $selected['name'] ?? null );
		$count    = null === $selected ? '' : $this->plural( Database::integer( $selected['post_count'] ?? null ), 'post' );
		$options  = array(
			$this->category_option( '', __( 'All categories', 'ehrman-blog-discovery' ), '', null === $selected ),
		);
		foreach ( $categories as $category ) {
			$options[] = $this->category_option(
				Database::text( $category['slug'] ?? null ),
				Database::text( $category['name'] ?? null ),
				$this->plural( Database::integer( $category['post_count'] ?? null ), 'post' ),
				Database::text( $category['slug'] ?? null ) === $selected_slug
			);
		}
		return '<div class="ebd-category-filter"><div class="ebd-category-heading">'
			. '<span class="ebd-category-label" id="' . esc_attr( $id ) . '-category-label">'
			. esc_html__( 'Category', 'ehrman-blog-discovery' ) . '</span>'
			. '<span class="ebd-category-badge">' . esc_html__( 'Recommended', 'ehrman-blog-discovery' ) . '</span></div>'
			. '<p class="ebd-category-help" id="' . esc_attr( $id ) . '-category-help">'
			. esc_html__( 'Choose a category to narrow your suggestions and improve your results.', 'ehrman-blog-discovery' )
			. '</p><div class="ebd-category-combobox" data-ebd-category-combobox>'
			. '<input type="hidden" name="ebd_category" value="' . esc_attr( $selected_slug ) . '" data-ebd-category>'
			. '<button type="button" class="ebd-category-toggle" data-ebd-category-toggle aria-haspopup="listbox" '
			. 'aria-expanded="false" aria-labelledby="' . esc_attr( $id ) . '-category-label '
			. esc_attr( $id ) . '-category-name" aria-describedby="' . esc_attr( $id ) . '-category-help"><span id="' . esc_attr( $id )
			. '-category-name" data-ebd-category-name>' . esc_html( $name ) . '</span>'
			. '<span class="ebd-category-count" data-ebd-category-count>' . esc_html( $count ) . '</span>'
			. '<span aria-hidden="true">&#9662;</span></button><ul class="ebd-category-options" role="listbox" '
			. 'data-ebd-category-options hidden>' . implode( '', $options ) . '</ul></div></div>';
	}

	/**
	 * Builds one category selector option.
	 *
	 * @param string $slug     Category slug.
	 * @param string $name     Category name.
	 * @param string $count    Formatted post count.
	 * @param bool   $selected Whether the option is selected.
	 * @return string Category-option markup.
	 */
	private function category_option( string $slug, string $name, string $count, bool $selected ): string {
		return '<li role="presentation"><button type="button" role="option" data-ebd-category-option value="'
			. esc_attr( $slug ) . '" data-label="' . esc_attr( $name ) . '" data-count="' . esc_attr( $count )
			. '" aria-selected="' . ( $selected ? 'true' : 'false' ) . '"><span>' . esc_html( $name )
			. '</span><span>' . esc_html( $count ) . '</span></button></li>';
	}

	/**
	 * Builds a result summary and post list.
	 *
	 * @param array{posts:list<array<string,mixed>>,terms:list<string>,sort:string,count:int,page:int,per_page:int,total_pages:int} $result Search result payload.
	 * @param string                                                                                                                $context Topic or category context.
	 * @param string                                                                                                                $question Ask AI question, when applicable.
	 * @param string                                                                                                                $request_id Ask AI request identifier.
	 * @return string Result markup.
	 */
	private function results_markup( array $result, string $context, string $question = '', string $request_id = '' ): string {
		$terms         = $result['terms'];
		$count         = $result['count'];
		$page          = max( 1, Database::integer( $result['page'] ) );
		$per_page      = max( 0, Database::integer( $result['per_page'] ) );
		$total_pages   = max( 0, Database::integer( $result['total_pages'] ) );
		$range_start   = $count > 0 ? ( ( $page - 1 ) * $per_page ) + 1 : 0;
		$range_end     = min( $count, $range_start + count( $result['posts'] ) - 1 );
		$summary_label = $total_pages > 1
			? sprintf(
				/* translators: 1: first visible post, 2: last visible post, 3: total matching posts. */
				__( 'Showing %1$d-%2$d of %3$d posts', 'ehrman-blog-discovery' ),
				$range_start,
				$range_end,
				$count
			)
			: $this->plural( $count, 'post' );
		$summary         = '<p class="ebd-results-summary" aria-live="polite"><strong>'
			. esc_html( $summary_label ) . '</strong>';
		$context_is_term = false;
		foreach ( $terms as $term ) {
			if ( Search_Service::normalize( $term ) === Search_Service::normalize( $context ) ) {
				$context_is_term = true;
				break;
			}
		}
		if ( '' !== $context && ! $context_is_term ) {
			$summary .= ' ' . esc_html__( 'in', 'ehrman-blog-discovery' ) . ' <strong>' . esc_html( $context ) . '</strong>';
		}
		if ( ! empty( $terms ) ) {
			$summary .= ' ' . esc_html( 1 === $count ? 'matches' : 'match' ) . ' <strong>'
				. esc_html( implode( ' + ', $terms ) ) . '</strong>';
		}
		$summary    .= '.</p>';
		$guidance    = $this->results_guidance( $count, count( $terms ) );
		$back_to_top = count( $result['posts'] ) >= self::BACK_TO_TOP_THRESHOLD
			? $this->back_to_top_markup()
			: '';
		$feedback    = '' !== trim( $question ) && '' !== $request_id ? $this->feedback_markup( $request_id ) : '';
		return $summary . $guidance . $feedback . $this->post_list( $result['posts'], $context ) . $back_to_top . $this->pagination_markup( $result );
	}

	/**
	 * Builds the Ask AI interpretation feedback control.
	 *
	 * @param string $request_id Ask AI request identifier.
	 * @return string Feedback control markup.
	 */
	private function feedback_markup( string $request_id ): string {
		return '<section class="ebd-ai-feedback" data-ebd-ai-feedback data-request-id="' . esc_attr( $request_id ) . '"><span>'
			. esc_html__( 'Were these search results helpful?', 'ehrman-blog-discovery' )
			. '</span><button type="button" data-ebd-feedback-value="yes">' . esc_html__( 'Yes', 'ehrman-blog-discovery' )
			. '</button><button type="button" data-ebd-feedback-value="no">' . esc_html__( 'No', 'ehrman-blog-discovery' )
			. '</button><span class="ebd-ai-feedback-status" data-ebd-feedback-status aria-live="polite"></span></section>';
	}

	/**
	 * Provides a next step only when a result set is unusually broad or narrow.
	 *
	 * @param int $count      Matching post count.
	 * @param int $term_count Number of selected search terms.
	 * @return string Contextual search guidance markup.
	 */
	private function results_guidance( int $count, int $term_count ): string {
		$message = '';
		if ( $count > 100 ) {
			$message = __( 'Many posts match. Add another topic or keyword to narrow the results.', 'ehrman-blog-discovery' );
		} elseif ( 0 === $count && $term_count > 0 ) {
			$message = __( 'No posts match all the selected terms. Remove a term or try a different search.', 'ehrman-blog-discovery' );
		} elseif ( $count <= 3 && $term_count > 1 ) {
			$message = __( 'Only a few posts match all the selected terms. Remove a term to broaden the results.', 'ehrman-blog-discovery' );
		}
		return '' === $message ? '' : '<p class="ebd-results-guidance">' . esc_html( $message ) . '</p>';
	}

	/**
	 * Builds the control shown after long post-result lists.
	 *
	 * @return string Back-to-top markup.
	 */
	private function back_to_top_markup(): string {
		return '<p class="ebd-back-to-top"><button type="button" data-ebd-back-to-top>'
			. '<span aria-hidden="true">&uarr;</span> ' . esc_html__( 'Back to top', 'ehrman-blog-discovery' ) . '</button></p>';
	}

	/**
	 * Builds accessible links for a paginated result set.
	 *
	 * @param array{posts:list<array<string,mixed>>,terms:list<string>,sort:string,count:int,page:int,per_page:int,total_pages:int} $result Search result payload.
	 * @return string Pagination navigation markup.
	 */
	private function pagination_markup( array $result ): string {
		$current = max( 1, Database::integer( $result['page'] ) );
		$total   = max( 0, Database::integer( $result['total_pages'] ) );
		if ( $total <= 1 ) {
			return '';
		}

		$previous = $current > 1
			? '<a class="ebd-pagination-link ebd-pagination-previous" href="' . esc_url( $this->pagination_url( $current - 1 ) ) . '">' . esc_html__( 'Previous', 'ehrman-blog-discovery' ) . '</a>'
			: '<span class="ebd-pagination-link ebd-pagination-previous is-disabled" aria-disabled="true">' . esc_html__( 'Previous', 'ehrman-blog-discovery' ) . '</span>';
		$next     = $current < $total
			? '<a class="ebd-pagination-link ebd-pagination-next" href="' . esc_url( $this->pagination_url( $current + 1 ) ) . '">' . esc_html__( 'Next', 'ehrman-blog-discovery' ) . '</a>'
			: '<span class="ebd-pagination-link ebd-pagination-next is-disabled" aria-disabled="true">' . esc_html__( 'Next', 'ehrman-blog-discovery' ) . '</span>';

		$candidates = array_unique( array( 1, $current - 1, $current, $current + 1, $total ) );
		$candidates = array_values( array_filter( $candidates, static fn( int $page ): bool => $page >= 1 && $page <= $total ) );
		sort( $candidates, SORT_NUMERIC );
		$numbers       = array();
		$previous_page = 0;
		foreach ( $candidates as $page ) {
			if ( $previous_page > 0 && $page > $previous_page + 1 ) {
				$numbers[] = '<span class="ebd-pagination-ellipsis" aria-hidden="true">&hellip;</span>';
			}
			if ( $page === $current ) {
				$numbers[] = '<span class="ebd-pagination-link is-current" aria-current="page">' . esc_html( (string) $page ) . '</span>';
			} else {
				/* translators: %d: results page number. */
				$label     = sprintf( __( 'Page %d', 'ehrman-blog-discovery' ), $page );
				$numbers[] = '<a class="ebd-pagination-link" href="' . esc_url( $this->pagination_url( $page ) ) . '" aria-label="' . esc_attr( $label ) . '">' . esc_html( (string) $page ) . '</a>';
			}
			$previous_page = $page;
		}

		/* translators: 1: current results page, 2: total results pages. */
		$status = sprintf( __( 'Page %1$d of %2$d', 'ehrman-blog-discovery' ), $current, $total );
		return '<nav class="ebd-pagination" aria-label="' . esc_attr__( 'Search results pages', 'ehrman-blog-discovery' ) . '">'
			. $previous . '<span class="ebd-pagination-pages">' . implode( '', $numbers ) . '</span>'
			. '<span class="ebd-pagination-status">' . esc_html( $status ) . '</span>' . $next . '</nav>';
	}

	/**
	 * Builds a URL for one page of the current read-only search request.
	 *
	 * @param int $page Results page number.
	 * @return string Pagination URL.
	 */
	private function pagination_url( int $page ): string {
		$url = remove_query_arg( 'ebd_page' );
		if ( $page > 1 ) {
			$url = add_query_arg( 'ebd_page', $page, $url );
		}
		return $url . '#ebd-results';
	}

	/**
	 * Builds linked post results with metadata and descriptions.
	 *
	 * @param array<int,array<string,mixed>> $posts   Search-result posts.
	 * @param string                         $context Topic or category context.
	 * @return string Post-list markup.
	 */
	private function post_list( array $posts, string $context ): string {
		if ( empty( $posts ) ) {
			return '<p class="ebd-empty">' . esc_html__( 'No posts matched this request.', 'ehrman-blog-discovery' ) . '</p>';
		}
		$items = array();
		foreach ( $posts as $post ) {
			$description = Database::text( $post['description'] ?? null );
			$post_author = Database::text( $post['author'] ?? null );
			$author      = '' !== $post_author ? $post_author : __( 'unknown author', 'ehrman-blog-discovery' );
			/* translators: %s: post author name. */
			$byline = sprintf( __( 'By %s', 'ehrman-blog-discovery' ), $author );
			$meta   = array(
				$byline,
				Database::text( $post['date_text'] ?? null ),
			);
			if ( '' !== $context ) {
				$meta[] = $context;
			}
			$items[] = '<li class="ebd-post-item"><a class="ebd-post-title" href="' . esc_url( Database::text( $post['url'] ?? null ) )
				. '" target="_blank" rel="noopener" data-description="' . esc_attr( $description ) . '">'
				. esc_html( Database::text( $post['title'] ?? null ) ) . '</a><p class="ebd-post-meta">' . esc_html( implode( ' | ', $meta ) )
				. '</p><p class="ebd-post-description">' . esc_html( $description ) . '</p></li>';
		}
		return '<ul class="ebd-post-list">' . implode( '', $items ) . '</ul>';
	}

	/**
	 * Builds a content heading with optional navigation and actions.
	 *
	 * @param string                              $title                Heading text.
	 * @param string                              $meta                 Count or context markup.
	 * @param array<int,array{0:string,1:string}> $breadcrumbs Breadcrumb labels and URLs.
	 * @param string                              $actions              Optional action markup.
	 * @param bool                                $dynamic_result_count Whether JavaScript may update the count.
	 * @return string Heading markup.
	 */
	private function heading(
		string $title,
		string $meta,
		array $breadcrumbs = array(),
		string $actions = '',
		bool $dynamic_result_count = false
	): string {
		return '<header class="ebd-content-header">' . $this->breadcrumbs( $breadcrumbs ) . '<h2>'
			. esc_html( $title ) . '</h2><p class="ebd-count-line"'
			. ( $dynamic_result_count ? ' data-ebd-result-count' : '' ) . '>' . wp_kses_post( $meta ) . '</p>'
			. ( '' === $actions ? '' : '<div class="ebd-actions">' . $actions . '</div>' ) . '</header>';
	}

	/**
	 * Builds accessible breadcrumb navigation.
	 *
	 * @param array<int,array{0:string,1:string}> $items Breadcrumb labels and URLs.
	 * @return string Breadcrumb markup.
	 */
	private function breadcrumbs( array $items ): string {
		if ( empty( $items ) ) {
			return '';
		}
		$crumbs = array();
		foreach ( $items as [$label, $url] ) {
			$crumbs[] = '' === $url
				? '<li aria-current="page">' . esc_html( $label ) . '</li>'
				: '<li><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
		}
		return '<nav class="ebd-breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'ehrman-blog-discovery' )
			. '"><ol>' . implode( '', $crumbs ) . '</ol></nav>';
	}

	/**
	 * Builds breadcrumbs leading to a category.
	 *
	 * @param int                      $path_number Browse-path number.
	 * @param array<string,mixed>      $category    Category record.
	 * @param array<string,mixed>|null $area        Subject-area record.
	 * @return array<int,array{0:string,1:string}> Breadcrumb labels and URLs.
	 */
	private function category_breadcrumbs( int $path_number, array $category, ?array $area ): array {
		$items = array( array( 'Browse Topics ' . $path_number, $this->browse_url( $path_number ) ) );
		if ( null !== $area ) {
			$items[] = array(
				Database::text( $area['name'] ?? null ),
				$this->browse_url( $path_number, array( 'ebd_subject' => Database::text( $area['slug'] ?? null ) ) ),
			);
		}
		$args = array( 'ebd_category' => Database::text( $category['slug'] ?? null ) );
		if ( null !== $area ) {
			$args['ebd_subject'] = Database::text( $area['slug'] ?? null );
		}
		$items[] = array( Database::text( $category['name'] ?? null ), $this->browse_url( $path_number, $args ) );
		return $items;
	}

	/**
	 * Builds one subject-area, category, or topic navigation item.
	 *
	 * @param string $title          Item title.
	 * @param string $url            Destination URL.
	 * @param string $meta           Count metadata.
	 * @param string $description    Hover and expanded description.
	 * @param bool   $navigation_row Whether to render the full-row navigation style.
	 * @return string Navigation-item markup.
	 */
	private function browse_item(
		string $title,
		string $url,
		string $meta,
		string $description,
		bool $navigation_row = false
	): string {
		if ( $navigation_row ) {
			return '<li class="ebd-list-item ebd-navigation-item"><a class="ebd-item-title ebd-navigation-link" href="'
				. esc_url( $url ) . '" data-description="' . esc_attr( $description ) . '"><span class="ebd-navigation-name">'
				. '<span>' . esc_html( $title ) . '</span><span class="ebd-navigation-arrow" aria-hidden="true">&#8594;</span>'
				. '</span><span class="ebd-item-meta">' . wp_kses_post( $meta ) . '</span></a>'
				. '<p class="ebd-item-description" hidden>' . esc_html( $description ) . '</p></li>';
		}

		return '<li class="ebd-list-item"><div class="ebd-item-row"><a class="ebd-item-title" href="'
			. esc_url( $url ) . '" data-description="' . esc_attr( $description ) . '">' . esc_html( $title )
			. '</a><p class="ebd-item-meta">' . wp_kses_post( $meta ) . '</p></div><p class="ebd-item-description" hidden>'
			. esc_html( $description ) . '</p></li>';
	}

	/**
	 * Builds the description display-mode control.
	 *
	 * @param string $default_mode Initial display mode.
	 * @param string $scope        Description scope identifier.
	 * @return string Description-mode markup.
	 */
	private function description_control( string $default_mode, string $scope ): string {
		++$this->instance;
		$id           = 'ebd-descriptions-' . $this->instance;
		$default_mode = in_array( $default_mode, array( 'always', 'hover', 'hidden' ), true ) ? $default_mode : 'hover';
		$options      = array(
			'always' => __( 'Always', 'ehrman-blog-discovery' ),
			'hover'  => __( 'On hover', 'ehrman-blog-discovery' ),
			'hidden' => __( 'Hidden', 'ehrman-blog-discovery' ),
		);
		$choices      = array();
		foreach ( $options as $value => $label ) {
			$choice_class = 'ebd-description-choice' . ( 'hover' === $value ? ' ebd-description-choice-hover' : '' );
			$choices[]    = '<label class="' . esc_attr( $choice_class ) . '"><input type="radio" name="' . esc_attr( $id )
				. '" value="' . esc_attr( $value ) . '"' . checked( $default_mode, $value, false )
				. '><span>' . esc_html( $label ) . '</span></label>';
		}
		return '<div class="ebd-description-mode" role="radiogroup" aria-labelledby="' . esc_attr( $id )
			. '-label" data-ebd-description-mode data-scope="' . esc_attr( $scope ) . '" data-default-mode="'
			. esc_attr( $default_mode ) . '"><span id="' . esc_attr( $id ) . '-label" class="ebd-description-mode-label">'
			. esc_html__( 'Show descriptions:', 'ehrman-blog-discovery' ) . '</span>' . implode( '', $choices ) . '</div>';
	}

	/**
	 * Wraps rendered content in the discovery page shell.
	 *
	 * @param string $content Rendered page content.
	 * @param string $view    View-specific CSS identifier.
	 * @return string Wrapped markup.
	 */
	private function shell( string $content, string $view ): string {
		return '<section class="ebd-discovery ebd-view-' . esc_attr( $view ) . '">' . $content . '</section>';
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
	 * Returns the requested-view-not-found notice.
	 *
	 * @return string Not-found notice markup.
	 */
	private function not_found(): string {
		return $this->shell( '<p class="ebd-empty">' . esc_html__( 'The requested discovery page could not be found.', 'ehrman-blog-discovery' ) . '</p>', 'error' );
	}

	/**
	 * Reads and sanitizes selected search terms from the public query string.
	 *
	 * @return array<int,string> Unique search terms.
	 */
	private function request_terms(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Public read-only values are sanitized immediately below.
		$raw = isset( $_GET['ebd_keyword'] ) ? wp_unslash( $_GET['ebd_keyword'] ) : array();
		$raw = is_array( $raw ) ? $raw : array( $raw );
		$raw = array_values( array_filter( $raw, 'is_scalar' ) );
		return Search_Service::unique_terms(
			array_map( static fn( $value ): string => sanitize_text_field( Database::text( $value ) ), $raw )
		);
	}

	/**
	 * Reads selected-term modes and resolves missing legacy values.
	 *
	 * @param array<int,string> $terms Sanitized selected terms.
	 * @return array<int,string> Modes aligned with the selected terms.
	 */
	private function request_term_modes( array $terms ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Public read-only values are sanitized below.
		$raw = isset( $_GET['ebd_term_mode'] ) ? wp_unslash( $_GET['ebd_term_mode'] ) : array();
		$raw = is_array( $raw ) ? $raw : array( $raw );
		$raw = array_values(
			array_map(
				static fn( $value ): string => sanitize_key( is_scalar( $value ) ? (string) $value : '' ),
				$raw
			)
		);
		return $this->search->resolve_term_modes( $terms, $raw );
	}

	/**
	 * Reads the requested results page from the public query string.
	 *
	 * @return int Positive results page number.
	 */
	private function request_page(): int {
		return max( 1, Database::integer( $this->request_value( 'ebd_page', '1' ) ) );
	}

	/**
	 * Reads one sanitized scalar value from the public query string.
	 *
	 * @param string $name    Query parameter name.
	 * @param string $fallback Fallback value.
	 * @return string Sanitized query value.
	 */
	private function request_value( string $name, string $fallback = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only search parameters.
		if ( ! isset( $_GET[ $name ] ) || is_array( $_GET[ $name ] ) ) {
			return $fallback;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Public read-only value is converted, unslashed, and sanitized immediately below.
		$raw_value = Database::text( $_GET[ $name ] );
		return sanitize_text_field( wp_unslash( $raw_value ) );
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

	/**
	 * Builds one Browse Topics path selector for the structure-review page.
	 *
	 * @param int  $path_number Link path number.
	 * @param bool $active      Whether this path is active.
	 * @return string Path-selector link markup.
	 */
	private function structure_review_path_link( int $path_number, bool $active ): string {
		$url = add_query_arg( 'ebd_path', $path_number, $this->page_url( 'structure_review' ) );
		return '<a class="ebd-review-path' . ( $active ? ' is-active' : '' ) . '" href="' . esc_url( $url ) . '"'
			. ( $active ? ' aria-current="page"' : '' ) . '>Browse Topics ' . $path_number . '</a>';
	}

	/**
	 * Builds the all-categories selector for the structure-review page.
	 *
	 * @param bool $active Whether the category index is active.
	 * @return string Category-index link markup.
	 */
	private function structure_review_category_link( bool $active ): string {
		$url = add_query_arg( 'ebd_view', 'categories', $this->page_url( 'structure_review' ) );
		return '<a class="ebd-review-path is-category-link' . ( $active ? ' is-active' : '' ) . '" href="'
			. esc_url( $url ) . '"' . ( $active ? ' aria-current="page"' : '' ) . '>'
			. esc_html__( 'All Categories', 'ehrman-blog-discovery' ) . '</a>';
	}

	/**
	 * Builds expandable category sections and their topic rows.
	 *
	 * @param array<int,array<string,mixed>> $categories Category records and counts.
	 * @return array<int,string> Category-section markup.
	 */
	private function structure_review_categories( array $categories ): array {
		$sections = array();
		foreach ( $categories as $category ) {
			$topics      = $this->browse->category_topics( Database::integer( $category['id'] ?? null ) );
			$topic_items = array();
			foreach ( $topics as $topic ) {
				$topic_items[] = '<li class="ebd-review-topic"><div class="ebd-review-topic-row"><span class="ebd-review-name ebd-review-topic-name">'
					. '<span class="ebd-review-badge is-topic">' . esc_html__( 'Topic', 'ehrman-blog-discovery' ) . '</span><span>'
					. esc_html( Database::text( $topic['name'] ?? null ) ) . '</span></span><span class="ebd-review-meta">'
					. esc_html( $this->plural( Database::integer( $topic['post_count'] ?? null ), 'post' ) )
					. '</span></div><p class="ebd-review-description" hidden>'
					. esc_html( Database::text( $topic['description'] ?? null ) ) . '</p></li>';
			}

			$category_meta = $this->plural( Database::integer( $category['topic_count'] ?? null ), 'topic' ) . ' &bull; '
				. $this->plural( Database::integer( $category['post_count'] ?? null ), 'post' );
			$sections[]    = '<details class="ebd-review-category"><summary><span class="ebd-review-name">'
				. '<span class="ebd-review-badge is-category">' . esc_html__( 'Category', 'ehrman-blog-discovery' ) . '</span><span>'
				. esc_html( Database::text( $category['name'] ?? null ) ) . '</span></span><span class="ebd-review-meta">'
				. wp_kses_post( $category_meta ) . '</span></summary><p class="ebd-review-description" hidden>'
				. esc_html( Database::text( $category['description'] ?? null ) ) . '</p><ul class="ebd-review-topic-list">'
				. implode( '', $topic_items ) . '</ul></details>';
		}
		return $sections;
	}

	/**
	 * Finds a record by slug in an in-memory record list.
	 *
	 * @param array<int,array<string,mixed>> $records Records to search.
	 * @param string                         $slug    Requested slug.
	 * @return array<string,mixed>|null Matching record when found.
	 */
	private function find_by_slug( array $records, string $slug ): ?array {
		foreach ( $records as $record ) {
			if ( Database::text( $record['slug'] ?? null ) === $slug ) {
				return $record;
			}
		}
		return null;
	}

	/**
	 * Formats a localized count with the appropriate noun form.
	 *
	 * @param int    $count    Numeric count.
	 * @param string $singular Singular noun.
	 * @param string $plural   Optional irregular plural noun.
	 * @return string Formatted count and noun.
	 */
	private function plural( int $count, string $singular, string $plural = '' ): string {
		$word = 1 === $count ? $singular : ( '' !== $plural ? $plural : $singular . 's' );
		return number_format_i18n( $count ) . ' ' . $word;
	}
}
