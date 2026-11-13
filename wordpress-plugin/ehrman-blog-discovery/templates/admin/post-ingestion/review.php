<?php
/**
 * Post ingestion review display.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$view               = $ehrman_blog_discovery_context;
$regenerate_context = array(
	'id'    => $view['action_id'],
	'label' => __( 'Regenerate Metadata', 'ehrman-blog-discovery' ),
);
?>
<h2><?php echo esc_html__( 'Review metadata', 'ehrman-blog-discovery' ); ?></h2>
<?php if ( 'held' === $view['status'] && ! empty( $view['warnings'] ) ) : ?>
	<div class="notice notice-warning inline"><ul>
		<?php foreach ( $view['warnings'] as $warning ) : ?>
			<li><?php echo esc_html( $warning ); ?></li>
		<?php endforeach; ?>
	</ul></div>
<?php endif; ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="ehrman_ingestion_proposal"><input type="hidden" name="draft_id" value="<?php echo esc_attr( $view['id'] ); ?>">
	<input type="hidden" name="revision" value="<?php echo esc_attr( $view['revision'] ); ?>">
	<?php wp_nonce_field( 'ehrman_ingestion_proposal' ); ?>
	<table class="form-table" role="presentation">
		<tr><th scope="row"><label for="description"><?php echo esc_html__( 'Description', 'ehrman-blog-discovery' ); ?></label></th><td><textarea class="large-text" id="description" name="description" rows="2" required><?php echo esc_textarea( $view['description'] ); ?></textarea></td></tr>
		<tr><th scope="row"><label for="search_summary"><?php echo esc_html__( 'Summary', 'ehrman-blog-discovery' ); ?></label></th><td><textarea class="large-text" id="search_summary" name="search_summary" rows="5" required><?php echo esc_textarea( $view['summary'] ); ?></textarea></td></tr>
		<tr><th scope="row"><?php echo esc_html__( 'Topics', 'ehrman-blog-discovery' ); ?></th><td><?php echo esc_html( implode( ', ', $view['topics'] ) ); ?></td></tr>
		<tr><th scope="row"><?php echo esc_html__( 'Keywords', 'ehrman-blog-discovery' ); ?></th><td><?php echo esc_html( implode( ', ', $view['keywords'] ) ); ?></td></tr>
	</table>
	<div class="ehrman-ingestion__actions">
		<button class="button" type="submit" name="ingestion_intent" value="save"><?php echo esc_html__( 'Save revisions', 'ehrman-blog-discovery' ); ?></button>
		<button class="button button-primary" type="submit" name="ingestion_intent" value="approve"<?php disabled( ! $view['database_authoritative'] || 'ready' !== $view['status'] ); ?>><?php echo esc_html__( 'Approve and Add to Search', 'ehrman-blog-discovery' ); ?></button>
	</div>
</form>
<div class="ehrman-ingestion__actions">
	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The trusted partial escapes its display values.
	echo Template::render( 'admin/post-ingestion/reanalyze.php', $regenerate_context );
	?>
	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The trusted partial escapes its display values.
	echo Template::render( 'admin/post-ingestion/discard.php', array( 'id' => $view['action_id'] ) );
	?>
</div>
