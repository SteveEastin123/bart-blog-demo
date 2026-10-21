<?php
/**
 * Read-only search-page rendering regressions. No AI calls or fixture writes.
 *
 * @package EhrmanBlogDiscovery
 */

use EhrmanBlogDiscovery\Browse_Service;
use EhrmanBlogDiscovery\Discovery_Markup;
use EhrmanBlogDiscovery\Search_Page_Renderer;
use EhrmanBlogDiscovery\Search_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$search_page_uri = $_SERVER['REQUEST_URI'] ?? null;
$search_page_http_calls = 0;
$search_page_block_http = static function () use ( &$search_page_http_calls ) {
	++$search_page_http_calls;
	return new WP_Error( 'search_page_test', 'Search-page rendering must not call an API.' );
};
$search_page_render = static function ( string $method, array $arguments ): string {
	$renderer = new Search_Page_Renderer( new Browse_Service(), new Search_Service(), new Discovery_Markup() );
	return $renderer->$method( ...$arguments );
};
$search_page_dom = static function ( string $html ): DOMXPath {
	$document = new DOMDocument();
	$previous = libxml_use_internal_errors( true );
	try {
		$document->loadHTML( '<?xml encoding="UTF-8"><div id="test-root">' . $html . '</div>' );
	} finally {
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
	}
	return new DOMXPath( $document );
};
add_filter( 'pre_http_request', $search_page_block_http );
try {
	$_SERVER['REQUEST_URI'] = '/keyword-search/?ebd_keyword%5B%5D=Paul&ebd_term_mode%5B%5D=topic&ebd_sort=newest&ebd_page=5';
	$all_terms = array( 'Paul', 'Jesus', 'Luke', 'Atonement' );
	foreach ( range( 0, 4 ) as $size ) {
		$terms = array_slice( $all_terms, 0, $size );
		foreach ( array( 'ranked', 'newest', 'oldest', 'invalid' ) as $sort ) {
			$dom = $search_page_dom( $search_page_render( 'search_panel', array( $terms, array(), $sort, true, '/keyword-search/' ) ) );
			$assert_same( $size, $dom->query( '//input[@name="ebd_keyword[]"]' )->length, 'Selected term count changed.' );
			$assert_same( $size, $dom->query( '//input[@name="ebd_term_mode[]"]' )->length, 'Selected terms lost their modes.' );
			$assert_same( max( 0, 3 - $size ), $dom->query( '//span[@class="ebd-keyword-slot ebd-keyword-empty"]' )->length, 'Empty term slots changed.' );
			$assert_same( $size === 4 ? 1 : 0, $dom->query( '//input[@class="ebd-keyword-input"][@disabled]' )->length, 'Four-term input limit changed.' );
			$assert_same( $size === 0 ? 'false' : 'true', $dom->evaluate( 'string(//form/@data-ebd-initial-collapse)' ), 'Initial collapse state changed.' );
			$assert_same( 'invalid' === $sort ? 'ranked' : $sort, $dom->evaluate( 'string(//input[@name="ebd_sort"][@checked]/@value)' ), 'Selected sort changed.' );
			$assert_same( 'popular', $dom->evaluate( 'string(//input[@name="ebd_suggestion_order"][@checked]/@value)' ), 'Default suggestion order changed.' );
			$input_id = $dom->evaluate( 'string(//input[@class="ebd-keyword-input"]/@id)' );
			$assert_same( substr( $input_id, 0, -6 ) . '-suggestions', $dom->evaluate( 'string(//input[@class="ebd-keyword-input"]/@aria-controls)' ), 'Autocomplete association changed.' );
		}
	}
	$unsafe = '<img src=x onerror=alert(1)> & "quoted"';
	$categories = array( array( 'slug' => 'test', 'name' => $unsafe, 'post_count' => 7 ) );
	$dom = $search_page_dom( $search_page_render( 'search_panel', array( array( 'Colossians', 'Paul', 'Paul' ), array( 'topic', 'keyword', 'keyword' ), 'ranked', false, 'javascript:alert(1)', '', '', $categories, 'test' ) ) );
	$assert_same( 2, $dom->query( '//input[@name="ebd_keyword[]"]' )->length, 'Duplicate terms were not removed.' );
	$assert_same( 'topic', $dom->evaluate( 'string((//input[@name="ebd_term_mode[]"])[1]/@value)' ), 'Explicit topic mode changed.' );
	$assert_same( 'Topic', $dom->evaluate( 'string((//span[contains(@class,"ebd-selected-term-badge")])[1])' ), 'Topic badge changed.' );
	$assert_same( '', $dom->evaluate( 'string(//form/@action)' ), 'Unsafe form action was not rejected.' );
	$assert_same( $unsafe, $dom->evaluate( 'string(//*[@data-ebd-category-name])' ), 'Category name was not safely preserved.' );
	$assert_same( '7 posts', $dom->evaluate( 'string(//*[@data-ebd-category-count])' ), 'Category count changed.' );
	$assert_same( 'test', $dom->evaluate( 'string(//button[@data-ebd-category-option][@aria-selected="true"]/@value)' ), 'Category selection changed.' );
	$assert_same( 0, $dom->query( '//img|//script|//*[@onerror]' )->length, 'Category content became executable.' );
	$assert_same( 'hover', $dom->evaluate( 'string(//*[@data-ebd-description-mode]//input[@checked]/@value)' ), 'Description mode changed.' );
	$dom = $search_page_dom( $search_page_render( 'search_panel', array( array( $unsafe ), array( 'keyword' ), 'ranked', true, '/', '', '', $categories, 'missing' ) ) );
	$assert_same( '', $dom->evaluate( 'string(//button[@data-ebd-category-option][@aria-selected="true"]/@value)' ), 'Invalid category fallback changed.' );
	$assert_same( 0, $dom->query( '//img|//script|//*[@onerror]' )->length, 'Search terms became executable markup.' );
	$actual_category = ( new Browse_Service() )->category_options()[0]['slug'];
	foreach ( array( array( $actual_category, '' ), array( '', 'topic-scope' ), array( 'missing', '' ) ) as [$category_scope, $topic_scope] ) {
		$dom = $search_page_dom( $search_page_render( 'search_panel', array( array(), array(), 'ranked', true, '/', $category_scope, $topic_scope ) ) );
		$assert_same( $category_scope, $dom->evaluate( 'string(//form/@data-category)' ), 'Fixed category scope changed.' );
		$assert_same( $topic_scope, $dom->evaluate( 'string(//form/@data-topic)' ), 'Fixed topic scope changed.' );
		$assert_same( 0, $dom->query( '//*[@data-ebd-category-combobox]' )->length, 'Browse controls gained a category selector.' );
	}
	$renderer = new Search_Page_Renderer( new Browse_Service(), new Search_Service(), new Discovery_Markup() );
	$twice = $renderer->search_panel( array(), array(), 'ranked', true, '/' ) . $renderer->search_panel( array(), array(), 'ranked', true, '/' );
	$ids = array();
	foreach ( $search_page_dom( $twice )->query( '//*[@id and @id!="test-root"]' ) as $element ) {
		$assert( ! isset( $ids[ $element->getAttribute( 'id' ) ] ), 'Repeated controls have duplicate IDs.' );
		$ids[ $element->getAttribute( 'id' ) ] = true;
	}
	$post = array( 'title' => $unsafe, 'url' => 'javascript:alert(1)', 'author' => '', 'date_text' => 'October 20, 2026', 'description' => $unsafe );
	$result = array( 'posts' => array( $post ), 'terms' => array( 'Paul' ), 'sort' => 'ranked', 'count' => 1, 'page' => 1, 'per_page' => 25, 'total_pages' => 1 );
	$dom = $search_page_dom( $search_page_render( 'results_markup', array( $result, 'Context' ) ) );
	$assert_same( '1 post in Context matches Paul.', $dom->evaluate( 'string(//p[@class="ebd-results-summary"])' ), 'Result summary changed.' );
	$assert_same( $unsafe, $dom->evaluate( 'string(//a[@class="ebd-post-title"])' ), 'Post title was not safely preserved.' );
	$assert_same( $unsafe, $dom->evaluate( 'string(//a/@data-description)' ), 'Description attribute changed.' );
	$assert_same( 'By unknown author | October 20, 2026 | Context', $dom->evaluate( 'string(//p[@class="ebd-post-meta"])' ), 'Post metadata fallback changed.' );
	$assert_same( 0, $dom->query( '//img|//script|//*[@onerror]|//a[starts-with(@href,"javascript:")]' )->length, 'Post content became executable.' );
	$dom = $search_page_dom( $search_page_render( 'results_markup', array( $result, 'paul' ) ) );
	$assert_same( '1 post matches Paul.', $dom->evaluate( 'string(//p[@class="ebd-results-summary"])' ), 'Duplicate context was not suppressed.' );
	$result['terms'] = array();
	$dom = $search_page_dom( $search_page_render( 'results_markup', array( $result, 'Context' ) ) );
	$assert_same( '1 post in Context.', $dom->evaluate( 'string(//p[@class="ebd-results-summary"])' ), 'Context-only summary spacing changed.' );
	$dom = $search_page_dom( $search_page_render( 'results_markup', array( $result, '' ) ) );
	$assert_same( '1 post.', $dom->evaluate( 'string(//p[@class="ebd-results-summary"])' ), 'Unfiltered summary spacing changed.' );
	foreach ( array( 0, 3, 4, 100, 101 ) as $count ) {
		$result['count'] = $count;
		$result['posts'] = $count > 0 ? array( $post ) : array();
		$result['terms'] = array( 'Paul', 'Luke' );
		$dom = $search_page_dom( $search_page_render( 'results_markup', array( $result, '' ) ) );
		$assert_same( in_array( $count, array( 0, 3, 101 ), true ) ? 1 : 0, $dom->query( '//p[@class="ebd-results-guidance"]' )->length, 'Guidance threshold changed.' );
		$assert_same( 0 === $count ? 1 : 0, $dom->query( '//p[@class="ebd-empty"]' )->length, 'Empty result state changed.' );
	}
	foreach ( array( 9, 10 ) as $size ) {
		$result['posts'] = array_fill( 0, $size, $post );
		$result['count'] = $size;
		$dom = $search_page_dom( $search_page_render( 'results_markup', array( $result, '' ) ) );
		$assert_same( 10 === $size ? 1 : 0, $dom->query( '//*[@data-ebd-back-to-top]' )->length, 'Back-to-top threshold changed.' );
	}
	$result['count'] = 250;
	$result['total_pages'] = 10;
	$result['posts'] = array_fill( 0, 25, $post );
	foreach ( array( 1, 5, 10 ) as $page_number ) {
		$result['page'] = $page_number;
		$dom = $search_page_dom( $search_page_render( 'results_markup', array( $result, '' ) ) );
		$assert_same( (string) $page_number, $dom->evaluate( 'string(//*[@aria-current="page"])' ), 'Current page marker changed.' );
		$assert_same( 'Page ' . $page_number . ' of 10', $dom->evaluate( 'string(//*[@class="ebd-pagination-status"])' ), 'Pagination status changed.' );
		$assert_same( $page_number === 1 ? 1 : 0, $dom->query( '//*[@class="ebd-pagination-link ebd-pagination-previous is-disabled"]' )->length, 'Previous-page boundary changed.' );
		$assert_same( $page_number === 10 ? 1 : 0, $dom->query( '//*[@class="ebd-pagination-link ebd-pagination-next is-disabled"]' )->length, 'Next-page boundary changed.' );
		$assert_same( $page_number === 5 ? 2 : 1, $dom->query( '//*[@class="ebd-pagination-ellipsis"]' )->length, 'Pagination gaps changed.' );
		foreach ( $dom->query( '//nav//a' ) as $link ) {
			$href = $link->getAttribute( 'href' );
			parse_str( (string) wp_parse_url( $href, PHP_URL_QUERY ), $query );
			$assert_same( array( 'Paul' ), $query['ebd_keyword'], 'Pagination lost search terms.' );
			$assert_same( array( 'topic' ), $query['ebd_term_mode'], 'Pagination lost term modes.' );
			$assert_same( 'newest', $query['ebd_sort'], 'Pagination lost sorting.' );
			$assert_same( 'ebd-results', wp_parse_url( $href, PHP_URL_FRAGMENT ), 'Pagination lost its anchor.' );
		}
	}
	foreach ( array( '', 'missing-category' ) as $category ) {
		$dom = $search_page_dom( $search_page_render( 'render_keyword_search', array( array(), array(), 'ranked', 1, $category, '/keyword-search/' ) ) );
		$assert_same( '', trim( $dom->evaluate( 'string(//*[@data-ebd-results])' ) ), 'Initial search unexpectedly shows results.' );
		$assert_same( '', $dom->evaluate( 'string(//*[@data-ebd-category]/@value)' ), 'Invalid page category was not cleared.' );
	}
	$expected = ( new Search_Service() )->search( array( 'Textual Variants' ), 'ranked', '', '', 2, Search_Service::POSTS_PER_PAGE, array( 'topic' ) );
	$dom = $search_page_dom( $search_page_render( 'render_keyword_search', array( array( 'Textual Variants' ), array( 'topic' ), 'ranked', 2, '', '/keyword-search/' ) ) );
	$actual_titles = array();
	foreach ( $dom->query( '//a[@class="ebd-post-title"]' ) as $link ) {
		$actual_titles[] = $link->textContent;
	}
	$assert_same( array_column( $expected['posts'], 'title' ), $actual_titles, 'Server-rendered post order differs from search results.' );
	$assert_same( 0, $search_page_http_calls, 'Rendering attempted a provider request.' );
} finally {
	remove_filter( 'pre_http_request', $search_page_block_http );
	if ( null === $search_page_uri ) {
		unset( $_SERVER['REQUEST_URI'] );
	} else {
		$_SERVER['REQUEST_URI'] = $search_page_uri;
	}
}
echo "Search-page rendering: OK\n";
