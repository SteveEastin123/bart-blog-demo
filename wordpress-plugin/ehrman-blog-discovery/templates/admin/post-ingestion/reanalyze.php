<?php
/**
 * Post ingestion reanalyze display.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$view  = $ehrman_blog_discovery_context;
$label = ! empty( $view['label'] ) ? $view['label'] : __( 'Reanalyze full text', 'ehrman-blog-discovery' );
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ehrman_ingestion_reanalyze"><input type="hidden" name="draft_id" value="<?php echo esc_attr( (string) $view['id'] ); ?>"><?php wp_nonce_field( 'ehrman_ingestion_reanalyze' ); ?><button class="button" type="submit"><?php echo esc_html( $label ); ?></button></form>
