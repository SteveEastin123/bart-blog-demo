<?php
/**
 * Foreground metadata-generation progress screen.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$view            = $ehrman_blog_discovery_context;
$fallback_action = ! empty( $view['draft_id'] ) ? 'ehrman_ingestion_reanalyze' : 'ehrman_ingestion_create_metadata';
$return_url      = add_query_arg(
	array(
		'status' => 'all',
		'search' => $view['title'],
	),
	Post_Ingestion_Page::page_url()
);
?>
<section class="ehrman-ingestion__progress" data-ebd-metadata-progress aria-labelledby="ehrman-metadata-post-title">
	<h2 id="ehrman-metadata-post-title"><?php echo esc_html( $view['title'] ); ?></h2>
	<div class="ehrman-ingestion__waiting" role="status" aria-live="polite">
		<span class="spinner is-active" aria-hidden="true"></span>
		<strong data-ebd-generation-status><?php echo esc_html__( 'Creating metadata...', 'ehrman-blog-discovery' ); ?></strong>
	</div>
	<p data-ebd-generation-detail><?php echo esc_html__( 'Waiting for the generated metadata. Keep this page open; the results will appear here for review and approval.', 'ehrman-blog-discovery' ); ?></p>
	<div class="notice notice-error inline" data-ebd-generation-error role="alert" hidden><p></p></div>
	<p><a href="<?php echo esc_url( $return_url ); ?>" data-ebd-generation-return><?php echo esc_html__( 'Return to Search Metadata', 'ehrman-blog-discovery' ); ?></a></p>
	<noscript>
		<style>.ehrman-ingestion__waiting, [data-ebd-generation-detail] { display: none; }</style>
		<p><?php echo esc_html__( 'JavaScript is disabled. Continue to generate metadata and wait for the review page to load.', 'ehrman-blog-discovery' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( $fallback_action ); ?>">
			<input type="hidden" name="draft_id" value="<?php echo esc_attr( (string) ( $view['draft_id'] ?? 0 ) ); ?>">
			<input type="hidden" name="revision" value="<?php echo esc_attr( $view['revision'] ?? '' ); ?>">
			<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $view['post_id'] ); ?>">
			<?php wp_nonce_field( $fallback_action ); ?>
			<button class="button button-primary"><?php echo esc_html__( 'Continue', 'ehrman-blog-discovery' ); ?></button>
		</form>
	</noscript>
</section>
