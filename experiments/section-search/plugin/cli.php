<?php
/** WP-CLI-only experiment bridge. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}
$args = isset( $args ) && is_array( $args ) ? $args : array();

if ( ! \EhrmanSectionPilot\local_enabled() && 'enable' !== ( $args[0] ?? '' ) ) {
	WP_CLI::error( 'The local section pilot is disabled.' );
}

try {
	if ( 'enable' === ( $args[0] ?? '' ) ) {
		if ( 'local' !== wp_get_environment_type() || 'localhost' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			throw new RuntimeException( 'Refusing to enable the pilot outside the localhost environment.' );
		}
		update_option( 'ehrman_section_pilot_enabled', '1', false );
		$page = get_page_by_path( 'ask-ai-3' );
		if ( ! $page ) {
			$id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => 'Ask AI 3: Section Search',
					'post_name'    => 'ask-ai-3',
					'post_content' => '[ehrman_section_pilot]',
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				throw new RuntimeException( $id->get_error_message() );
			}
		}
		echo wp_json_encode(
			array(
				'enabled' => true,
				'url'     => home_url( '/ask-ai-3/' ),
			)
		);
	} elseif ( 'search' === ( $args[0] ?? '' ) ) {
		$input = json_decode( base64_decode( $args[1], true ), true, 512, JSON_THROW_ON_ERROR );
		echo wp_json_encode( ( new \EhrmanSectionPilot\Service() )->search( $input['question'], $input['mode'], $input['evidence'] ?? false ) );
	} elseif ( 'rank' === ( $args[0] ?? '' ) ) {
		$input = json_decode( base64_decode( $args[1], true ), true, 512, JSON_THROW_ON_ERROR );
		echo wp_json_encode( ( new \EhrmanSectionPilot\Index() )->rank( $input['question'], $input['vector'], $input['mode'] ) );
	} else {
		echo wp_json_encode(
			array(
				'sampleHash' => ( new \EhrmanSectionPilot\Index() )->identity(),
				'enabled'    => \EhrmanSectionPilot\local_enabled(),
			)
		);
	}
} catch ( Throwable $error ) {
	WP_CLI::error( $error->getMessage() );
}
