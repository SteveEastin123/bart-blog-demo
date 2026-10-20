<?php
/**
 * Post ingestion discard display.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$view = $ehrman_blog_discovery_context;
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm('<?php echo esc_js( __( 'Discard this pending draft and its retained full text?', 'ehrman-blog-discovery' ) ); ?>');"><input type="hidden" name="action" value="ehrman_ingestion_discard"><input type="hidden" name="draft_id" value="<?php echo esc_attr( (string) $view['id'] ); ?>"><?php wp_nonce_field( 'ehrman_ingestion_discard' ); ?><button class="button button-link-delete" type="submit"><?php echo esc_html__( 'Discard draft', 'ehrman-blog-discovery' ); ?></button></form>
