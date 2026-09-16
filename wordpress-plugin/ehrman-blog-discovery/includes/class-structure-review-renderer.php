<?php
/**
 * Reviewer-only structure page rendering.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Renders subject-area, category, and topic review indexes. */
final class Structure_Review_Renderer {

	/**
	 * Creates the renderer with its data and markup collaborators.
	 *
	 * @param Browse_Service   $browse Browse-taxonomy data service.
	 * @param Discovery_Markup $markup Shared discovery-page markup.
	 */
	public function __construct(
		private Browse_Service $browse,
		private Discovery_Markup $markup
	) {}

	/**
	 * Renders the selected structure-review view.
	 *
	 * @param int    $path_number Browse Topics path number.
	 * @param string $review_view Optional categories or topics index.
	 * @param string $base_url    Structure Review page URL.
	 * @return string Structure-review markup.
	 */
	public function render( int $path_number, string $review_view, string $base_url ): string {
		$category_index = 'categories' === $review_view;
		$topic_index    = 'topics' === $review_view;
		$areas          = $category_index || $topic_index ? array() : $this->browse->subject_areas( $path_number );
		$sections       = array();
		$topics         = array();
		$heading        = __( 'Category and Topic Review', 'ehrman-blog-discovery' );

		if ( $topic_index ) {
			$topics   = $this->browse->topics();
			$sections = $this->topic_sections( $topics );
			$meta     = $this->markup->plural( count( $topics ), 'topic' );
			$intro    = __( 'Review every topic alphabetically, including its assigned category and post count.', 'ehrman-blog-discovery' );
			$heading  = __( 'All Topics', 'ehrman-blog-discovery' );
		} elseif ( $category_index ) {
			$categories = $this->browse->categories();
			$sections   = $this->category_sections( $categories );
			$meta       = $this->markup->plural( count( $categories ), 'category', 'categories' );
			$intro      = __( 'Expand a category to review its assigned topics and post counts.', 'ehrman-blog-discovery' );
			$heading    = __( 'All Categories', 'ehrman-blog-discovery' );
		} else {
			foreach ( $areas as $area ) {
				$categories        = $this->browse->subject_area_categories( Database::integer( $area['id'] ?? null ) );
				$category_sections = $this->category_sections( $categories );
				$area_meta         = $this->markup->plural( Database::integer( $area['category_count'] ?? null ), 'category', 'categories' ) . ' &bull; '
					. $this->markup->plural( Database::integer( $area['topic_count'] ?? null ), 'topic' ) . ' &bull; '
					. $this->markup->plural( Database::integer( $area['post_count'] ?? null ), 'post' );
				$sections[]        = '<details class="ebd-review-area" open><summary><span class="ebd-review-name">'
					. '<span class="ebd-review-badge is-subject">' . esc_html__( 'Subject Area', 'ehrman-blog-discovery' ) . '</span><span>'
					. esc_html( Database::text( $area['name'] ?? null ) ) . '</span></span><span class="ebd-review-meta">'
					. wp_kses_post( $area_meta ) . '</span></summary><p class="ebd-review-description" hidden>'
					. esc_html( Database::text( $area['description'] ?? null ) ) . '</p><div class="ebd-review-categories">'
					. implode( '', $category_sections ) . '</div></details>';
			}
			$meta  = $this->markup->plural( count( $areas ), 'subject area' ) . ' &bull; '
				. $this->markup->plural( count( $this->browse->categories() ), 'category', 'categories' );
			$intro = __( 'Expand a subject area and its categories to review where topics are currently assigned.', 'ehrman-blog-discovery' );
		}

		$path_links  = '<nav class="ebd-review-paths" aria-label="' . esc_attr__( 'Browse Topics structure', 'ehrman-blog-discovery' ) . '">'
			. $this->path_link( $base_url, 1, 1 === $path_number && ! $category_index && ! $topic_index )
			. $this->path_link( $base_url, 2, 2 === $path_number && ! $category_index && ! $topic_index )
			. $this->category_link( $base_url, $category_index )
			. $this->topic_link( $base_url, $topic_index )
			. '</nav>';
		$topic_tools = $topic_index ? $this->topic_tools( $topics ) : '';
		$controls    = '<div class="ebd-review-controls">'
			. ( $topic_index ? '' : '<button type="button" data-ebd-review-expand>'
				. esc_html__( 'Expand all', 'ehrman-blog-discovery' ) . '</button><button type="button" data-ebd-review-collapse>'
				. esc_html__( 'Collapse all', 'ehrman-blog-discovery' ) . '</button>' )
			. '<button type="button" data-ebd-review-pdf>'
			. esc_html__( 'Download PDF', 'ehrman-blog-discovery' ) . '</button><button type="button" data-ebd-review-csv>'
			. esc_html__( 'Download CSV', 'ehrman-blog-discovery' ) . '</button>'
			. $this->markup->description_control( 'hover', 'review' ) . '</div>';

		return $this->markup->shell(
			$this->markup->heading( $heading, $meta )
			. '<p class="ebd-review-intro">'
			. esc_html( $intro )
			. '</p>' . $path_links . $topic_tools . $controls . '<div class="ebd-review-tree'
			. ( $category_index ? ' is-category-index' : '' )
			. ( $topic_index ? ' is-topic-index' : '' ) . '" data-ebd-review-tree>'
			. implode( '', $sections ) . '</div>',
			'structure-review'
		);
	}

	/**
	 * Builds one Browse Topics path selector.
	 *
	 * @param string $base_url    Structure Review page URL.
	 * @param int    $path_number Browse Topics path number.
	 * @param bool   $active      Whether the path is active.
	 */
	private function path_link( string $base_url, int $path_number, bool $active ): string {
		$url = add_query_arg( 'ebd_path', $path_number, $base_url );
		return '<a class="ebd-review-path' . ( $active ? ' is-active' : '' ) . '" href="' . esc_url( $url ) . '"'
			. ( $active ? ' aria-current="page"' : '' ) . '>Browse Topics ' . $path_number . '</a>';
	}

	/**
	 * Builds the categories selector.
	 *
	 * @param string $base_url Structure Review page URL.
	 * @param bool   $active   Whether the category index is active.
	 */
	private function category_link( string $base_url, bool $active ): string {
		$url = add_query_arg( 'ebd_view', 'categories', $base_url );
		return '<a class="ebd-review-path is-category-link' . ( $active ? ' is-active' : '' ) . '" href="'
			. esc_url( $url ) . '"' . ( $active ? ' aria-current="page"' : '' ) . '>'
			. esc_html__( 'Categories', 'ehrman-blog-discovery' ) . '</a>';
	}

	/**
	 * Builds the topics selector.
	 *
	 * @param string $base_url Structure Review page URL.
	 * @param bool   $active   Whether the topic index is active.
	 */
	private function topic_link( string $base_url, bool $active ): string {
		$url = add_query_arg( 'ebd_view', 'topics', $base_url );
		return '<a class="ebd-review-path is-topic-link' . ( $active ? ' is-active' : '' ) . '" href="'
			. esc_url( $url ) . '"' . ( $active ? ' aria-current="page"' : '' ) . '>'
			. esc_html__( 'Topics', 'ehrman-blog-discovery' ) . '</a>';
	}

	/**
	 * Builds expandable category sections and their topic rows.
	 *
	 * @param array<int,array<string,mixed>> $categories Category records and counts.
	 * @return array<int,string> Category-section markup.
	 */
	private function category_sections( array $categories ): array {
		$sections = array();
		foreach ( $categories as $category ) {
			$topics      = $this->browse->category_topics( Database::integer( $category['id'] ?? null ) );
			$topic_items = array();
			foreach ( $topics as $topic ) {
				$topic_items[] = '<li class="ebd-review-topic"><div class="ebd-review-topic-row"><span class="ebd-review-name ebd-review-topic-name">'
					. '<span class="ebd-review-badge is-topic">' . esc_html__( 'Topic', 'ehrman-blog-discovery' ) . '</span><span>'
					. esc_html( Database::text( $topic['name'] ?? null ) ) . '</span></span><span class="ebd-review-meta">'
					. esc_html( $this->markup->plural( Database::integer( $topic['post_count'] ?? null ), 'post' ) )
					. '</span></div><p class="ebd-review-description" hidden>'
					. esc_html( Database::text( $topic['description'] ?? null ) ) . '</p></li>';
			}

			$category_meta = $this->markup->plural( Database::integer( $category['topic_count'] ?? null ), 'topic' ) . ' &bull; '
				. $this->markup->plural( Database::integer( $category['post_count'] ?? null ), 'post' );
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
	 * Builds alphabetical topic groups for the all-topics index.
	 *
	 * @param array<int,array<string,mixed>> $topics Topic records, categories, and counts.
	 * @return array<int,string> Topic-group markup.
	 */
	private function topic_sections( array $topics ): array {
		$groups = array();
		foreach ( $topics as $topic ) {
			$name            = Database::text( $topic['name'] ?? null );
			$description     = Database::text( $topic['description'] ?? null );
			$category_names  = array_values( array_filter( explode( '||', Database::text( $topic['category_names'] ?? null ) ) ) );
			$category_count  = count( $category_names );
			$category_text   = empty( $category_names ) ? __( 'No category', 'ehrman-blog-discovery' ) : implode( ', ', $category_names );
			$category_prefix = 1 === $category_count ? __( 'Category:', 'ehrman-blog-discovery' ) : __( 'Categories:', 'ehrman-blog-discovery' );
			$letter          = $this->topic_letter( $name );
			$meta            = $category_prefix . ' ' . $category_text . ' &bull; '
				. $this->markup->plural( Database::integer( $topic['post_count'] ?? null ), 'post' );
			$search_text     = implode( ' ', array( $name, $description, $category_text ) );

			$groups[ $letter ][] = '<li class="ebd-review-topic ebd-review-topic-index-item" data-ebd-review-topic-item data-ebd-review-search="'
				. esc_attr( $search_text ) . '" data-ebd-review-categories="' . esc_attr( $category_text )
				. '"><div class="ebd-review-topic-row"><span class="ebd-review-name ebd-review-topic-name">'
				. '<span class="ebd-review-badge is-topic">' . esc_html__( 'Topic', 'ehrman-blog-discovery' ) . '</span><span>'
				. esc_html( $name ) . '</span></span><span class="ebd-review-meta">' . wp_kses_post( $meta )
				. '</span></div><p class="ebd-review-description" hidden>' . esc_html( $description ) . '</p></li>';
		}

		$sections = array();
		foreach ( $groups as $letter => $items ) {
			$anchor     = 'ebd-review-topics-' . sanitize_title( $letter );
			$sections[] = '<section class="ebd-review-topic-group" id="' . esc_attr( $anchor )
				. '" data-ebd-review-topic-group><h2>' . esc_html( $letter )
				. '</h2><ul class="ebd-review-topic-list">' . implode( '', $items ) . '</ul></section>';
		}
		return $sections;
	}

	/**
	 * Builds the filter and available-letter index for all topics.
	 *
	 * @param array<int,array<string,mixed>> $topics Topic records.
	 */
	private function topic_tools( array $topics ): string {
		$letters = array();
		foreach ( $topics as $topic ) {
			$letter             = $this->topic_letter( Database::text( $topic['name'] ?? null ) );
			$letters[ $letter ] = true;
		}
		$links = array();
		foreach ( array_keys( $letters ) as $letter ) {
			$links[] = '<a href="#ebd-review-topics-' . esc_attr( sanitize_title( $letter ) ) . '">' . esc_html( $letter ) . '</a>';
		}

		return '<div class="ebd-review-topic-tools"><div class="ebd-review-topic-filter"><label for="ebd-review-topic-filter">'
			. esc_html__( 'Filter topics', 'ehrman-blog-discovery' ) . '</label><input id="ebd-review-topic-filter" type="search" '
			. 'placeholder="' . esc_attr__( 'Search by topic or category', 'ehrman-blog-discovery' )
			. '" data-ebd-review-topic-search><button type="button" data-ebd-review-topic-clear disabled>'
			. esc_html__( 'Clear', 'ehrman-blog-discovery' ) . '</button><span class="ebd-review-topic-status" aria-live="polite" data-ebd-review-topic-status>'
			. esc_html( $this->markup->plural( count( $topics ), 'topic' ) . ' ' . __( 'shown', 'ehrman-blog-discovery' ) )
			. '</span></div><nav class="ebd-review-topic-letters" aria-label="' . esc_attr__( 'Topic letters', 'ehrman-blog-discovery' )
			. '">' . implode( '', $links ) . '</nav></div>';
	}

	/**
	 * Returns the alphabetical review group for a topic name.
	 *
	 * @param string $name Topic name.
	 */
	private function topic_letter( string $name ): string {
		$first = strtoupper( substr( trim( $name ), 0, 1 ) );
		return 1 === preg_match( '/^[A-Z]$/', $first ) ? $first : '0-9';
	}
}
