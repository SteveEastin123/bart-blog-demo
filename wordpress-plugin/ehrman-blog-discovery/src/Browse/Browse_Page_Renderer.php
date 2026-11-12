<?php
/**
 * Browse Topics page rendering.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Renders Browse Topics navigation and post-result views. */
final class Browse_Page_Renderer {

	/**
	 * Browse URL builder supplied by the page controller.
	 *
	 * @var \Closure(array<string,mixed>):string
	 */
	private \Closure $browse_url;

	/**
	 * Creates the renderer with its data and presentation collaborators.
	 *
	 * @param Browse_Service       $browse      Browse-taxonomy data service.
	 * @param Search_Service       $search      Post-search service.
	 * @param Discovery_Markup     $markup      Shared discovery-page markup.
	 * @param Search_Page_Renderer $search_page Shared search-page renderer.
	 * @param callable             $browse_url  Browse URL builder.
	 * @phpstan-param callable(array<string,mixed>):string $browse_url
	 */
	public function __construct(
		private Browse_Service $browse,
		private Search_Service $search,
		private Discovery_Markup $markup,
		private Search_Page_Renderer $search_page,
		callable $browse_url
	) {
		$this->browse_url = \Closure::fromCallable( $browse_url );
	}

	/**
	 * Renders the requested Browse Topics view.
	 *
	 * @param string            $subject_slug Subject-area slug.
	 * @param string            $category_slug Category slug.
	 * @param string            $topic_slug   Topic slug.
	 * @param string            $view         Optional category view.
	 * @param array<int,string> $terms        Selected search terms.
	 * @param array<int,string> $term_modes   Search modes aligned with selected terms.
	 * @param string            $sort         Requested sort mode.
	 * @param int               $page         Requested result page.
	 * @return string Browse interface markup.
	 */
	public function render(
		string $subject_slug,
		string $category_slug,
		string $topic_slug,
		string $view,
		array $terms,
		array $term_modes,
		string $sort,
		int $page
	): string {
		if ( '' !== $topic_slug ) {
			return $this->render_topic_posts( $subject_slug, $category_slug, $topic_slug, $terms, $term_modes, $sort, $page );
		}
		if ( '' !== $category_slug && 'posts' === $view ) {
			return $this->render_category_posts( $subject_slug, $category_slug, $terms, $term_modes, $sort, $page );
		}
		if ( '' !== $category_slug ) {
			return $this->render_category( $subject_slug, $category_slug );
		}
		if ( '' !== $subject_slug ) {
			return $this->render_subject_area( $subject_slug );
		}
		return $this->render_subject_areas();
	}

	/**
	 * Renders the subject-area index for a browse path.
	 *
	 * @return string Subject-area list markup.
	 */
	private function render_subject_areas(): string {
		$areas = $this->alphabetical_records( $this->browse->subject_areas() );
		$items = array();
		foreach ( $areas as $area ) {
			$url     = $this->url( array( 'ebd_subject' => Database::text( $area['slug'] ?? null ) ) );
			$meta    = $this->markup->plural( Database::integer( $area['category_count'] ?? null ), 'category', 'categories' ) . ' &bull; '
				. $this->markup->plural( Database::integer( $area['topic_count'] ?? null ), 'topic' ) . ' &bull; '
				. $this->markup->plural( Database::integer( $area['post_count'] ?? null ), 'post' );
			$items[] = $this->browse_item(
				Database::text( $area['name'] ?? null ),
				$url,
				$meta,
				Database::text( $area['description'] ?? null ),
				Database::integer( $area['post_count'] ?? null )
			);
		}
		return $this->markup->shell(
			$this->markup->heading( __( 'Choose a Subject Area', 'ehrman-blog-discovery' ), $this->markup->plural( count( $areas ), 'subject area' ) )
			. $this->browse_controls()
			. '<ul class="ebd-item-list" data-ebd-browse-list>' . implode( '', $items ) . '</ul>',
			'browse'
		);
	}

	/**
	 * Renders the categories within a subject area.
	 *
	 * @param string $subject_slug Subject-area slug.
	 * @return string Category list markup.
	 */
	private function render_subject_area( string $subject_slug ): string {
		$area = $this->browse->subject_area( $subject_slug );
		if ( null === $area ) {
			return $this->not_found();
		}
		$area_id    = Database::integer( $area['id'] ?? null );
		$categories = $this->alphabetical_records( $this->browse->subject_area_categories( $area_id ) );
		$counts     = $this->browse->subject_area_counts( $area_id );
		$items      = array();
		foreach ( $categories as $category ) {
			$url     = $this->url(
				array(
					'ebd_subject'  => $subject_slug,
					'ebd_category' => Database::text( $category['slug'] ?? null ),
				)
			);
			$meta    = $this->markup->plural( Database::integer( $category['topic_count'] ?? null ), 'topic' ) . ' &bull; '
				. $this->markup->plural( Database::integer( $category['post_count'] ?? null ), 'post' );
			$items[] = $this->browse_item(
				Database::text( $category['name'] ?? null ),
				$url,
				$meta,
				Database::text( $category['description'] ?? null ),
				Database::integer( $category['post_count'] ?? null )
			);
		}
		$meta        = $this->markup->plural( (int) $counts['category_count'], 'category', 'categories' ) . ' &bull; '
			. $this->markup->plural( (int) $counts['topic_count'], 'topic' ) . ' &bull; '
			. $this->markup->plural( (int) $counts['post_count'], 'post' );
		$breadcrumbs = array(
			array( 'Browse Topics', $this->url() ),
			array( Database::text( $area['name'] ?? null ), '' ),
		);
		return $this->markup->shell(
			$this->markup->heading( Database::text( $area['name'] ?? null ), $meta, $breadcrumbs )
			. $this->browse_controls()
			. '<ul class="ebd-item-list" data-ebd-browse-list>' . implode( '', $items ) . '</ul>',
			'browse'
		);
	}

	/**
	 * Renders the topics within a category.
	 *
	 * @param string $subject_slug Subject-area slug.
	 * @param string $category_slug Category slug.
	 * @return string Topic list markup.
	 */
	private function render_category( string $subject_slug, string $category_slug ): string {
		$category = $this->browse->category( $category_slug );
		if ( null === $category ) {
			return $this->not_found();
		}
		$category_id = Database::integer( $category['id'] ?? null );
		$area        = $this->browse->primary_subject_area( $category_id, $subject_slug );
		$topics      = $this->alphabetical_records( $this->browse->category_topics( $category_id ) );
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
				$this->url( $args ),
				$this->markup->plural( Database::integer( $topic['post_count'] ?? null ), 'post' ),
				Database::text( $topic['description'] ?? null ),
				Database::integer( $topic['post_count'] ?? null )
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
		$view_all_label                              = sprintf( __( 'View all %s in this category', 'ehrman-blog-discovery' ), $this->markup->plural( $post_count, 'post' ) );
		$actions                                     = '<a class="ebd-primary-link" href="' . esc_url( $this->url( $post_args ) ) . '">'
			. esc_html( $view_all_label )
			. '</a>';
		$breadcrumbs                                 = $this->category_breadcrumbs( $category, $area );
		$breadcrumbs[ count( $breadcrumbs ) - 1 ][1] = '';
		/**
		 * Validated breadcrumb tuples.
		 *
		 * @var list<array{0:string,1:string}> $breadcrumbs
		 */
		return $this->markup->shell(
			$this->markup->heading(
				Database::text( $category['name'] ?? null ),
				$this->markup->plural( count( $topics ), 'topic' ) . ' &bull; ' . $this->markup->plural( $post_count, 'post' ),
				$breadcrumbs,
				$actions
			)
			. $this->browse_controls()
			. '<ul class="ebd-item-list" data-ebd-browse-list>' . implode( '', $items ) . '</ul>',
			'browse'
		);
	}

	/**
	 * Renders and filters the posts assigned to a topic.
	 *
	 * @param string            $subject_slug Subject-area slug.
	 * @param string            $category_slug Category slug.
	 * @param string            $topic_slug   Topic slug.
	 * @param array<int,string> $terms        Selected search terms.
	 * @param array<int,string> $term_modes   Search modes aligned with selected terms.
	 * @param string            $sort         Requested sort mode.
	 * @param int               $page         Requested result page.
	 * @return string Topic-post view markup.
	 */
	private function render_topic_posts(
		string $subject_slug,
		string $category_slug,
		string $topic_slug,
		array $terms,
		array $term_modes,
		string $sort,
		int $page
	): string {
		$topic = $this->browse->topic( $topic_slug );
		if ( null === $topic ) {
			return $this->not_found();
		}
		$category = $this->browse->topic_category( Database::integer( $topic['id'] ?? null ), $category_slug );
		$area     = null === $category
			? null
			: $this->browse->primary_subject_area( Database::integer( $category['id'] ?? null ), $subject_slug );
		if ( empty( $terms ) ) {
			$terms      = array( Database::text( $topic['name'] ?? null ) );
			$term_modes = array( Search_Service::TERM_MODE_TOPIC );
		}
		$result      = $this->search->search( $terms, $sort, '', $topic_slug, $page, Search_Service::POSTS_PER_PAGE, $term_modes );
		$breadcrumbs = null === $category
			? array()
			: array_merge(
				$this->category_breadcrumbs( $category, $area ),
				array( array( Database::text( $topic['name'] ?? null ), '' ) )
			);
		/**
		 * Validated breadcrumb tuples.
		 *
		 * @var list<array{0:string,1:string}> $breadcrumbs
		 */
		$action = $this->url(
			array_filter(
				array(
					'ebd_subject'  => null === $area ? '' : Database::text( $area['slug'] ?? null ),
					'ebd_category' => null === $category ? '' : Database::text( $category['slug'] ?? null ),
					'ebd_topic'    => $topic_slug,
				)
			)
		);
		return $this->markup->shell(
			$this->markup->heading( Database::text( $topic['name'] ?? null ), $this->markup->plural( $result['count'], 'post' ), $breadcrumbs, '', true )
			. $this->search_page->search_panel( $result['terms'], $term_modes, $result['sort'], true, $action, '', $topic_slug )
			. '<div id="ebd-results" class="ebd-results" data-ebd-results data-context="' . esc_attr( Database::text( $topic['name'] ?? null ) ) . '">'
			. $this->search_page->results_markup( $result, Database::text( $topic['name'] ?? null ) ) . '</div>',
			'posts'
		);
	}

	/**
	 * Renders and filters all posts connected to a category.
	 *
	 * @param string            $subject_slug Subject-area slug.
	 * @param string            $category_slug Category slug.
	 * @param array<int,string> $terms        Selected search terms.
	 * @param array<int,string> $term_modes   Search modes aligned with selected terms.
	 * @param string            $sort         Requested sort mode.
	 * @param int               $page         Requested result page.
	 * @return string Category-post view markup.
	 */
	private function render_category_posts(
		string $subject_slug,
		string $category_slug,
		array $terms,
		array $term_modes,
		string $sort,
		int $page
	): string {
		$category = $this->browse->category( $category_slug );
		if ( null === $category ) {
			return $this->not_found();
		}
		$area        = $this->browse->primary_subject_area( Database::integer( $category['id'] ?? null ), $subject_slug );
		$result      = $this->search->search( $terms, $sort, $category_slug, '', $page, Search_Service::POSTS_PER_PAGE, $term_modes );
		$breadcrumbs = array_merge(
			$this->category_breadcrumbs( $category, $area ),
			array( array( __( 'Posts', 'ehrman-blog-discovery' ), '' ) )
		);
		$form_args   = array(
			'ebd_category' => $category_slug,
			'ebd_view'     => 'posts',
		);
		if ( null !== $area ) {
			$form_args['ebd_subject'] = Database::text( $area['slug'] ?? null );
		}
		return $this->markup->shell(
			$this->markup->heading( Database::text( $category['name'] ?? null ), $this->markup->plural( $result['count'], 'post' ), $breadcrumbs, '', true )
			. $this->search_page->search_panel( $result['terms'], $term_modes, $result['sort'], true, $this->url( $form_args ), $category_slug, '' )
			. '<div id="ebd-results" class="ebd-results" data-ebd-results data-context="' . esc_attr( Database::text( $category['name'] ?? null ) ) . '">'
			. $this->search_page->results_markup( $result, Database::text( $category['name'] ?? null ) ) . '</div>',
			'posts'
		);
	}

	/**
	 * Builds breadcrumbs leading to a category.
	 *
	 * @param array<string,mixed>      $category    Category record.
	 * @param array<string,mixed>|null $area        Subject-area record.
	 * @return array<int,array{0:string,1:string}> Breadcrumb labels and URLs.
	 */
	private function category_breadcrumbs( array $category, ?array $area ): array {
		$items = array( array( 'Browse Topics', $this->url() ) );
		if ( null !== $area ) {
			$items[] = array(
				Database::text( $area['name'] ?? null ),
				$this->url( array( 'ebd_subject' => Database::text( $area['slug'] ?? null ) ) ),
			);
		}
		$args = array( 'ebd_category' => Database::text( $category['slug'] ?? null ) );
		if ( null !== $area ) {
			$args['ebd_subject'] = Database::text( $area['slug'] ?? null );
		}
		$items[] = array( Database::text( $category['name'] ?? null ), $this->url( $args ) );
		return $items;
	}

	/**
	 * Sorts reader-facing lists without changing stored taxonomy positions.
	 *
	 * @param array<int,array<string,mixed>> $records Browse records.
	 * @return list<array<string,mixed>> Alphabetical browse records.
	 */
	private function alphabetical_records( array $records ): array {
		usort(
			$records,
			static fn( array $left, array $right ): int => strnatcasecmp( Database::text( $left['name'] ?? null ), Database::text( $right['name'] ?? null ) )
		);
		return $records;
	}

	/**
	 * Builds the shared description and sort controls for all browse levels.
	 *
	 * @return string Accessible browse controls.
	 */
	private function browse_controls(): string {
		$sort_id = wp_unique_id( 'ebd-browse-sort-' );
		return '<div class="ebd-browse-controls">' . $this->markup->description_control( 'hover', 'browse' )
			. '<div class="ebd-browse-sort" data-ebd-browse-sort-control hidden><label for="' . esc_attr( $sort_id ) . '">' . esc_html__( 'Sort by:', 'ehrman-blog-discovery' ) . '</label>'
			. '<select id="' . esc_attr( $sort_id ) . '" data-ebd-browse-sort><option value="alphabetical">' . esc_html__( 'Alphabetical (A-Z)', 'ehrman-blog-discovery' ) . '</option>'
			. '<option value="posts">' . esc_html__( 'Most posts', 'ehrman-blog-discovery' ) . '</option></select></div></div>';
	}

	/**
	 * Builds one subject-area, category, or topic navigation item.
	 *
	 * @param string $title       Item title.
	 * @param string $url         Destination URL.
	 * @param string $meta        Count metadata.
	 * @param string $description Hover and expanded description.
	 * @param int    $post_count  Distinct post count for client-side sorting.
	 * @return string Navigation-item markup.
	 */
	private function browse_item( string $title, string $url, string $meta, string $description, int $post_count ): string {
		return '<li class="ebd-list-item ebd-navigation-item" data-ebd-post-count="' . esc_attr( (string) $post_count ) . '"><a class="ebd-item-title ebd-navigation-link" href="'
			. esc_url( $url ) . '" data-description="' . esc_attr( $description ) . '"><span class="ebd-navigation-name">'
			. '<span>' . esc_html( $title ) . '</span><span class="ebd-navigation-arrow" aria-hidden="true">&#8594;</span>'
			. '</span><span class="ebd-item-meta">' . wp_kses_post( $meta ) . '</span></a>'
			. '<p class="ebd-item-description" hidden>' . esc_html( $description ) . '</p></li>';
	}

	/**
	 * Invokes the controller's browse URL builder.
	 *
	 * @param array<string,mixed> $args        Optional query arguments.
	 * @return string Browse URL.
	 */
	private function url( array $args = array() ): string {
		return ( $this->browse_url )( $args );
	}

	/**
	 * Returns the requested-view-not-found notice.
	 *
	 * @return string Not-found notice markup.
	 */
	private function not_found(): string {
		return $this->markup->shell( '<p class="ebd-empty">' . esc_html__( 'The requested discovery page could not be found.', 'ehrman-blog-discovery' ) . '</p>', 'error' );
	}
}
