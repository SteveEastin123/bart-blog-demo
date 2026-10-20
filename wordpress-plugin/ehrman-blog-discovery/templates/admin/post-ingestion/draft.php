<?php
/**
 * Post ingestion draft display.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$view          = $ehrman_blog_discovery_context;
$retry_context = array(
	'id'    => $view['id'],
	'label' => __( 'Retry analysis', 'ehrman-blog-discovery' ),
);
?>
<p><a href="<?php echo esc_url( $view['history_url'] ); ?>">&larr; <?php echo esc_html__( 'New submission and ingestion history', 'ehrman-blog-discovery' ); ?></a></p>
<h2><?php echo esc_html( $view['title'] ); ?></h2>
<table class="widefat striped ehrman-ingestion__meta">
	<tbody>
		<tr><th><?php echo esc_html__( 'Status', 'ehrman-blog-discovery' ); ?></th><td><span class="ehrman-ingestion__status"><?php echo esc_html( $view['status'] ); ?></span></td></tr>
		<tr><th><?php echo esc_html__( 'Publication', 'ehrman-blog-discovery' ); ?></th><td><?php echo esc_html( $view['date'] ); ?> &middot; <?php echo esc_html( $view['author'] ); ?></td></tr>
		<tr><th><?php echo esc_html__( 'WordPress ID', 'ehrman-blog-discovery' ); ?></th><td><?php echo esc_html( $view['wp_id'] ); ?></td></tr>
		<tr><th><?php echo esc_html__( 'URL', 'ehrman-blog-discovery' ); ?></th><td><a href="<?php echo esc_url( $view['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $view['url'] ); ?></a></td></tr>
		<tr><th><?php echo esc_html__( 'Analysis', 'ehrman-blog-discovery' ); ?></th><td><?php echo esc_html( $view['model'] ); ?> &middot; <?php echo esc_html( $view['usage'] ); ?></td></tr>
		<?php
		if ( 'approved' === $view['status'] ) :
			?>
			<tr><th><?php echo esc_html__( 'Semantic vector', 'ehrman-blog-discovery' ); ?></th><td><?php echo esc_html( $view['embedding_status'] ); ?>
			<?php
			if ( '' !== $view['embedding_error'] ) :
				?>
			: <?php echo esc_html( $view['embedding_error'] ); ?><?php endif; ?></td></tr><?php endif; ?>
	</tbody>
</table>
<?php if ( '' !== $view['error'] ) : ?>
<div class="notice notice-error inline"><p><?php echo esc_html( $view['error'] ); ?></p></div>
<?php endif; ?>
<?php if ( $view['analysis_active'] ) : ?>
	<?php if ( $view['analysis_stale'] ) : ?>
		<div class="notice notice-warning inline"><p><?php echo esc_html__( 'Background analysis appears to have stopped. Start a fresh attempt below.', 'ehrman-blog-discovery' ); ?></p></div>
		<div class="ehrman-ingestion__actions">
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The trusted partial escapes its display values.
			echo Template::render( 'admin/post-ingestion/reanalyze.php', $retry_context );
			?>
		</div>
	<?php else : ?>
		<div class="notice notice-info inline"><p><?php echo esc_html__( 'Analysis is running in the background. This post will not be added to search until the results are reviewed and approved. You may leave this page and return later.', 'ehrman-blog-discovery' ); ?></p></div>
		<p><a class="button" href="<?php echo esc_url( $view['refresh_url'] ); ?>"><?php echo esc_html__( 'Refresh status', 'ehrman-blog-discovery' ); ?></a></p>
	<?php endif; ?>
	<?php return; ?>
<?php endif; ?>
<?php if ( 'approved' === $view['status'] ) : ?>
	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The trusted partial escapes its display values.
	echo Template::render( 'admin/post-ingestion/approved.php', $view['approved'] );
	?>
	<?php if ( 'pending' === $view['embedding_status'] ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ehrman_ingestion_retry_embedding"><input type="hidden" name="draft_id" value="<?php echo esc_attr( (string) $view['id'] ); ?>">
			<?php wp_nonce_field( 'ehrman_ingestion_retry_embedding' ); ?>
			<?php submit_button( __( 'Retry semantic vector', 'ehrman-blog-discovery' ), 'secondary', 'submit', false ); ?>
		</form>
	<?php endif; ?>
	<?php return; ?>
<?php endif; ?>
<?php if ( 'ready' === $view['status'] ) : ?>
	<div class="notice notice-warning inline"><p><strong><?php echo esc_html__( 'Search metadata is ready for review.', 'ehrman-blog-discovery' ); ?></strong> <?php echo esc_html__( 'Review and approve the results before this post can appear in search.', 'ehrman-blog-discovery' ); ?></p></div>
<?php elseif ( 'held' === $view['status'] ) : ?>
	<div class="notice notice-warning inline"><p><strong><?php echo esc_html__( 'Search metadata requires review.', 'ehrman-blog-discovery' ); ?></strong> <?php echo esc_html__( 'Resolve the review notes and mark the proposal ready before approval.', 'ehrman-blog-discovery' ); ?></p></div>
<?php endif; ?>

<?php if ( null === $view['review'] ) : ?>
	<div class="ehrman-ingestion__actions">
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The trusted partial escapes its display values.
		echo Template::render( 'admin/post-ingestion/reanalyze.php', array( 'id' => $view['id'] ) );
		?>
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The trusted partial escapes its display values.
		echo Template::render( 'admin/post-ingestion/discard.php', array( 'id' => $view['id'] ) );
		?>
	</div>
	<?php return; ?>
<?php endif; ?>
<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The trusted partial escapes its display values.
	echo Template::render( 'admin/post-ingestion/review.php', $view['review'] );
?>
