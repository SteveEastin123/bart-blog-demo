<?php
/**
 * Enable or restore the local guest page without changing the frozen pilot.
 *
 * @package EhrmanSectionPublic
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}
if ( ! function_exists( '\EhrmanSectionPilot\local_enabled' ) || ! \EhrmanSectionPilot\local_enabled() ) {
	WP_CLI::error( 'The existing local section pilot must be enabled first.' );
}
$page = get_page_by_path( 'ask-ai-3' );
if ( ! $page || 'page' !== $page->post_type ) {
	WP_CLI::error( 'The existing Ask AI 3 pilot page was not found.' );
}
$operation = $args[0] ?? '';
if ( 'enable' === $operation ) {
	new \EhrmanSectionPublic\Index();
	add_option( 'ehrman_section_public_previous_content', $page->post_content, '', false );
	$result = wp_update_post(
		array(
			'ID'           => $page->ID,
			'post_content' => '[ehrman_section_public]',
		),
		true
	);
	if ( is_wp_error( $result ) ) {
		WP_CLI::error( $result->get_error_message() );
	}
	update_option( 'ehrman_section_public_enabled', '1', false );
	WP_CLI::success( 'Local Ask AI 3 guest access enabled for all 3,986 eligible posts.' );
} elseif ( 'disable' === $operation ) {
	update_option( 'ehrman_section_public_enabled', '0', false );
	$previous = get_option( 'ehrman_section_public_previous_content', false );
	if ( false !== $previous && '[ehrman_section_public]' === $page->post_content ) {
		$result = wp_update_post(
			array(
				'ID'           => $page->ID,
				'post_content' => $previous,
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
	}
	WP_CLI::success( 'Guest link removed and previous pilot page restored.' );
} else {
	WP_CLI::error( 'Expected enable or disable.' );
}
