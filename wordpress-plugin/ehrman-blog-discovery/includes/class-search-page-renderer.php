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

		return $this->markup->shell(
			$this->search_panel(
				$result['terms'],
				$term_modes,
				$result['sort'],
				true,
				$action,
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
	 * Builds reusable search controls for keyword, browse, and Ask AI views.
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
	public function search_panel(
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
		$edit_label        = $ai_interpretation ? __( 'Review or adjust search terms', 'ehrman-blog-discovery' ) : __( 'Edit search', 'ehrman-blog-discovery' );
		$compact_summary   = '<div class="ebd-search-compact" data-ebd-search-compact hidden><p class="ebd-search-compact-summary">'
			. '<strong>' . esc_html( $summary_label ) . '</strong> '
			. '<span data-ebd-search-summary></span></p><button type="button" class="ebd-search-edit" '
			. 'data-ebd-search-edit aria-controls="' . esc_attr( $id ) . '-controls">'
			. esc_html( $edit_label ) . '</button></div>';
		return '<form class="ebd-search-panel" action="' . esc_url( $action ) . '" method="get" data-ebd-search-form '
			. 'data-category="' . esc_attr( '' !== $category_scope ? $category_scope : $selected_category ) . '" '
			. 'data-topic="' . esc_attr( $topic_scope ) . '" data-ebd-force-initial-collapse="'
			. ( $ai_interpretation ? 'true' : 'false' ) . '" data-ebd-initial-collapse="'
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
			. '<div class="ebd-description-control">' . $this->markup->description_control( $show_descriptions ? 'always' : 'hover', 'posts' ) . '</div>';
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
	public function results_markup( array $result, string $context, string $question = '', string $request_id = '' ): string {
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
			$this->category_option( '', __( 'All categories', 'ehrman-blog-discovery' ), '', null === $selected ),
		);
		foreach ( $categories as $category ) {
			$options[] = $this->category_option(
				Database::text( $category['slug'] ?? null ),
				Database::text( $category['name'] ?? null ),
				$this->markup->plural( Database::integer( $category['post_count'] ?? null ), 'post' ),
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

	/** Builds the control shown after long post-result lists. */
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
		$candidates = array_values( array_filter( $candidates, static fn( int $candidate ): bool => $candidate >= 1 && $candidate <= $total ) );
		sort( $candidates, SORT_NUMERIC );
		$numbers       = array();
		$previous_page = 0;
		foreach ( $candidates as $candidate ) {
			if ( $previous_page > 0 && $candidate > $previous_page + 1 ) {
				$numbers[] = '<span class="ebd-pagination-ellipsis" aria-hidden="true">&hellip;</span>';
			}
			if ( $candidate === $current ) {
				$numbers[] = '<span class="ebd-pagination-link is-current" aria-current="page">' . esc_html( (string) $candidate ) . '</span>';
			} else {
				/* translators: %d: results page number. */
				$label     = sprintf( __( 'Page %d', 'ehrman-blog-discovery' ), $candidate );
				$numbers[] = '<a class="ebd-pagination-link" href="' . esc_url( $this->pagination_url( $candidate ) ) . '" aria-label="' . esc_attr( $label ) . '">' . esc_html( (string) $candidate ) . '</a>';
			}
			$previous_page = $candidate;
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
				. '" data-description="' . esc_attr( $description ) . '">'
				. esc_html( Database::text( $post['title'] ?? null ) ) . '</a><p class="ebd-post-meta">' . esc_html( implode( ' | ', $meta ) )
				. '</p><p class="ebd-post-description">' . esc_html( $description ) . '</p></li>';
		}
		return '<ul class="ebd-post-list">' . implode( '', $items ) . '</ul>';
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
