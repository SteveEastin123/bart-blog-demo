<?php
/**
 * Ask AI page upgrades, using rolled-back WordPress page fixtures and no API calls.
 *
 * @package EhrmanBlogDiscovery
 */

use EhrmanBlogDiscovery\Page_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$page_id = (int) get_option( 'ehrman_discovery_page_ask_ai', 0 );
$assert( $page_id > 0, 'The managed Ask AI page is missing.' );
$assert_same( 'Ask AI', get_the_title( $page_id ), 'The managed page retained a numbered title.' );
$assert_same( home_url( '/ask-ai/' ), get_permalink( $page_id ), 'The canonical Ask AI URL is incorrect.' );
$original_post = $GLOBALS['post'] ?? null;
$new_page = static function ( string $slug, string $shortcode ): int {
	$result = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Old search title', 'post_name' => $slug, 'post_content' => wp_slash( "Reader's introduction. " . $shortcode . ' Additional content.' ) ), true );
	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( $result->get_error_message() );
	}
	return (int) $result;
};
foreach ( array( 'fresh', 'legacy-only', 'both', 'stale-option' ) as $scenario ) {
	$assert( false !== $wpdb->query( 'START TRANSACTION' ), 'Could not start the page-upgrade fixture transaction.' );
	try {
		foreach ( array( 'ask-ai', 'ask-ai-2' ) as $slug ) {
			$existing = get_page_by_path( $slug, OBJECT, 'page' );
			if ( $existing instanceof WP_Post ) {
				wp_update_post( array( 'ID' => $existing->ID, 'post_name' => 'naming-test-hidden-' . $existing->ID ) );
			}
		}
		delete_option( 'ehrman_discovery_page_ask_ai' );
		delete_option( 'ehrman_discovery_page_ask_ai_2' );
		$legacy_id = 0;
		$canonical_id = 0;
		if ( 'fresh' !== $scenario ) {
			$legacy_id = $new_page( 'ask-ai-2', '[ehrman_ask_ai_2]' );
			update_option( 'ehrman_discovery_page_ask_ai_2', 'stale-option' === $scenario ? 2147483647 : $legacy_id );
		}
		if ( 'both' === $scenario ) {
			$canonical_id = $new_page( 'ask-ai', '[ehrman_ask_question]' );
		}
		// get_post(0) must not accidentally select the current global page.
		$GLOBALS['post'] = get_post( $page_id );
		Page_Controller::ensure_pages();
		$managed_id = (int) get_option( 'ehrman_discovery_page_ask_ai', 0 );
		$managed = get_post( $managed_id );
		$assert( $managed instanceof WP_Post && 'ask-ai' === $managed->post_name, 'Upgrade did not create the canonical page: ' . $scenario );
		$assert_same( 'Ask AI', $managed->post_title, 'Upgrade did not rename the title.' );
		$assert_same( 'publish', $managed->post_status, 'Upgrade lost the published page.' );
		$assert( has_shortcode( $managed->post_content, 'ehrman_ask_ai' ), 'Upgrade did not install the canonical shortcode.' );
		if ( $legacy_id > 0 ) {
			$assert_same( $canonical_id > 0 ? $canonical_id : $legacy_id, $managed_id, 'Upgrade created a duplicate instead of reusing a page.' );
			$assert_same( "Reader's introduction. [ehrman_ask_ai] Additional content.", $managed->post_content, 'Upgrade changed surrounding page content.' );
		}
		if ( 'both' === $scenario ) {
			$assert_same( 'draft', get_post_status( $legacy_id ), 'The superseded page remained published.' );
			$assert_same( 'Ask AI (legacy link)', get_the_title( $legacy_id ), 'The retained draft kept its numbered title.' );
		}
		$before_repeat = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );
		Page_Controller::ensure_pages();
		$assert_same( $managed_id, (int) get_option( 'ehrman_discovery_page_ask_ai', 0 ), 'Repeated upgrade changed the managed page ID.' );
		$assert_same( $before_repeat, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ), 'Repeated upgrade created pages or revisions.' );
	} finally {
		$wpdb->query( 'ROLLBACK' );
		wp_cache_flush();
		$GLOBALS['post'] = $original_post;
	}
}
echo "Ask AI naming and page upgrades: OK\n";
