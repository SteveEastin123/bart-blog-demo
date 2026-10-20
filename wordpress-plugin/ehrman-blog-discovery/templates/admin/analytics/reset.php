<?php
/**
 * Protected analytics reset form and export links.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$view = $ehrman_blog_discovery_context;
?>
<hr style="margin:32px 0 24px">
<h2><?php echo esc_html__( 'Reset test analytics', 'ehrman-blog-discovery' ); ?></h2>
<p><?php echo esc_html__( 'Reset affects all active-method analytics, not only the filtered rows. Export records before resetting; exports follow the current filters. Retired-method history, post vectors and discovery data are preserved.', 'ehrman-blog-discovery' ); ?></p>
<p><a class="button" href="<?php echo esc_url( $view['export_url'] ); ?>"><?php echo esc_html__( 'Export questions CSV', 'ehrman-blog-discovery' ); ?></a> <a class="button" href="<?php echo esc_url( $view['refinement_export_url'] ); ?>"><?php echo esc_html__( 'Export AI reviews CSV', 'ehrman-blog-discovery' ); ?></a></p>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm('<?php echo esc_js( $view['confirmation'] ); ?>');">
	<input type="hidden" name="action" value="ehrman_ai_analytics_reset">
	<?php wp_nonce_field( 'ehrman_ai_analytics_reset' ); ?>
	<?php submit_button( __( 'Reset Test Analytics', 'ehrman-blog-discovery' ), 'delete', 'submit', false ); ?>
</form>
