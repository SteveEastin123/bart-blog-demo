<?php
/**
 * Search controls and result-page rendering.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Renders keyword search and shared post-search controls and results. */
final class Search_Page_Renderer {
	private const BACK_TO_TOP_THRESHOLD = 10;

	/**
	 * Creates the renderer with its data and presentation collaborators.
	 *
	 * @param Browse_Service   $browse Browse-taxonomy data service.
	 * @param Search_Service   $search Post-search service.
	 * @param Discovery_Markup $markup Shared discovery-page markup.
	 */
	public function __construct(
		private Browse_Service $browse,
		private Search_Service $search,
		private Discovery_Markup $markup
	) {}

	/**
	 * Renders the standalone keyword-search interface.
	 *
	 * @param array<int,string> $terms          Selected search terms.
	 * @param array<int,string> $term_modes     Search modes aligned with selected terms.
	 * @param string            $sort           Requested sort mode.
	 * @param int               $page           Requested result page.
	 * @param string            $category_slug  Selected category slug.
	 * @param string            $action          Form action URL.
	 * @return string Keyword-search markup.
	 */
	public function render_keyword_search(
		array $terms,
		array $term_modes,
		string $sort,
		int $page,
		string $category_slug,
		string $action
	): string {
		$categories = $this->browse->category_options();
		$category   = $this->find_by_slug( $categories, $category_slug );
		if ( null === $category ) {
			$category_slug = '';
		}
		$has_request = ! empty( $terms ) || '' !== $category_slug;
		$result      = $has_request
			? $this->search->search( $terms, $sort, $category_slug, '', $page, Search_Service::POSTS_PER_PAGE, $term_modes )
			: $this->empty_result();
		$context     = null === $category ? '' : Database::text( $category['name'] ?? null );
		$panel       = $this->search_panel(
			$result['terms'],
			$term_modes,
			$result['sort'],
			true,
			$action,
			'',
			'',
			$categories,
			$category_slug
		);
		return $this->markup->shell(
			Template::render(
				'public/search/page.php',
				array(
					'panel'   => $panel,
					'context' => $context,
					'results' => $has_request ? $this->results_markup( $result, $context ) : '',
				)
			),
			'keyword-search'
		);
	}

	/**
	 * Builds reusable search controls for keyword and browse views.
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
	 * @return string Search-panel markup.
	 */
	public function search_panel(
		array $terms,
		array $term_modes,
		string $sort,
		bool $show_descriptions,
		string $action,
		string $category_scope = '',
		string $topic_scope = '',
		?array $category_options = null,
		string $selected_category = ''
	): string {
		$id         = $this->markup->next_control_id( 'ebd-search' );
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
			$chips[]               = array(
				'term'                  => $term,
				'type'                  => $type,
				'type_label'            => $type_label,
				'type_accessible_label' => $type_accessible_label,
				'remove_label'          => $remove_label,
			);
		}
		$next         = count( $terms ) + 1;
		$input_hidden = count( $terms ) >= Search_Service::MAX_TERMS;
		$input_number = min( $next, 4 );
		if ( ! $input_hidden ) {
			++$next;
		}
		$empty_slots = array();
		while ( $next <= Search_Service::MAX_TERMS ) {
			$empty_slots[] = $next;
			++$next;
		}

		$scope = null;
		if ( '' !== $category_scope ) {
			$category = $this->browse->category( $category_scope );
			if ( null !== $category ) {
				$scope = Database::text( $category['name'] ?? null );
			}
		}
		$category_filter = null === $category_options
			? ''
			: $this->category_filter( $id, $category_options, $selected_category );
		return Template::render(
			'public/search/panel.php',
			array(
				'id'                  => $id,
				'action'              => $action,
				'category'            => '' !== $category_scope ? $category_scope : $selected_category,
				'topic'               => $topic_scope,
				'has_search_state'    => ! empty( $terms ) || '' !== $category_scope || '' !== $topic_scope || '' !== $selected_category,
				'scope'               => $scope,
				'category_filter'     => $category_filter,
				'terms'               => array(
					'id'           => $id,
					'chips'        => $chips,
					'input_hidden' => $input_hidden,
					'input_number' => $input_number,
					'empty_slots'  => $empty_slots,
				),
				'sort'                => $sort,
				'description_control' => $this->markup->description_control( $show_descriptions ? 'always' : 'hover', 'posts' ),
			)
		);
	}

	/**
	 * Builds a result summary and post list.
	 *
	 * @param array{posts:list<array<string,mixed>>,terms:list<string>,sort:string,count:int,page:int,per_page:int,total_pages:int} $result Search result payload.
	 * @param string                                                                                                                $context Topic or category context.
	 * @return string Result markup.
	 */
	public function results_markup( array $result, string $context ): string {
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
			: $this->markup->plural( $count, 'post' );
		$context_is_term = false;
		foreach ( $terms as $term ) {
			if ( Search_Service::normalize( $term ) === Search_Service::normalize( $context ) ) {
				$context_is_term = true;
				break;
			}
		}
		return Template::render(
			'public/search/results.php',
			array(
				'summary_label' => $summary_label,
				'context'       => '' !== $context && ! $context_is_term ? $context : '',
				'terms'         => implode( ' + ', $terms ),
				'has_terms'     => ! empty( $terms ),
				'match_label'   => 1 === $count ? 'matches' : 'match',
				'guidance'      => $this->results_guidance( $count, count( $terms ) ),
				'posts'         => $this->post_list( $result['posts'], $context ),
				'back_to_top'   => count( $result['posts'] ) >= self::BACK_TO_TOP_THRESHOLD,
				'pagination'    => $this->pagination_markup( $result ),
			)
		);
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
		$count    = null === $selected ? '' : $this->markup->plural( Database::integer( $selected['post_count'] ?? null ), 'post' );
		$options  = array(
			array(
				'slug'     => '',
				'name'     => __( 'All categories', 'ehrman-blog-discovery' ),
				'count'    => '',
				'selected' => null === $selected,
			),
		);
		foreach ( $categories as $category ) {
			$options[] = array(
				'slug'     => Database::text( $category['slug'] ?? null ),
				'name'     => Database::text( $category['name'] ?? null ),
				'count'    => $this->markup->plural( Database::integer( $category['post_count'] ?? null ), 'post' ),
				'selected' => Database::text( $category['slug'] ?? null ) === $selected_slug,
			);
		}
		return Template::render(
			'public/search/category-filter.php',
			array(
				'id'      => $id,
				'slug'    => $selected_slug,
				'name'    => $name,
				'count'   => $count,
				'options' => $options,
			)
		);
	}

	/**
	 * Provides a next step only when a result set is unusually broad or narrow.
	 *
	 * @param int $count      Matching post count.
	 * @param int $term_count Number of selected search terms.
	 * @return string Contextual search guidance text.
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
		return $message;
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

		$candidates = array_unique( array( 1, $current - 1, $current, $current + 1, $total ) );
		$candidates = array_values( array_filter( $candidates, static fn( int $candidate ): bool => $candidate >= 1 && $candidate <= $total ) );
		sort( $candidates, SORT_NUMERIC );
		$numbers       = array();
		$previous_page = 0;
		foreach ( $candidates as $candidate ) {
			/* translators: %d: results page number. */
			$label         = sprintf( __( 'Page %d', 'ehrman-blog-discovery' ), $candidate );
			$numbers[]     = array(
				'ellipsis' => $previous_page > 0 && $candidate > $previous_page + 1,
				'current'  => $candidate === $current,
				'number'   => $candidate,
				'url'      => $candidate === $current ? '' : $this->pagination_url( $candidate ),
				'label'    => $label,
			);
			$previous_page = $candidate;
		}

		/* translators: 1: current results page, 2: total results pages. */
		$status = sprintf( __( 'Page %1$d of %2$d', 'ehrman-blog-discovery' ), $current, $total );
		return Template::render(
			'public/search/pagination.php',
			array(
				'previous' => $current > 1 ? $this->pagination_url( $current - 1 ) : null,
				'next'     => $current < $total ? $this->pagination_url( $current + 1 ) : null,
				'numbers'  => $numbers,
				'status'   => $status,
			)
		);
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
			$items[] = array(
				'url'         => Database::text( $post['url'] ?? null ),
				'title'       => Database::text( $post['title'] ?? null ),
				'description' => $description,
				'meta'        => implode( ' | ', $meta ),
			);
		}
		return Template::render( 'public/search/post-list.php', array( 'posts' => $items ) );
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
	 * Returns an empty search-result payload.
	 *
	 * @return array{posts:list<array<string,mixed>>,terms:list<string>,sort:string,count:int,page:int,per_page:int,total_pages:int}
	 */
	private function empty_result(): array {
		return array(
			'posts'       => array(),
			'terms'       => array(),
			'sort'        => 'ranked',
			'count'       => 0,
			'page'        => 1,
			'per_page'    => Search_Service::POSTS_PER_PAGE,
			'total_pages' => 0,
		);
	}
}
