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

$view   = $ehrman_blog_discovery_context;
$label  = ! empty( $view['label'] ) ? $view['label'] : __( 'Regenerate Metadata', 'ehrman-blog-discovery' );
$native = ! empty( $view['native_id'] );
?>
<form method="post" action="<?php echo esc_url( $native ? Post_Ingestion_Page::page_url() : admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm('<?php echo esc_js( __( 'Regenerate metadata using AI? This incurs an API charge and replaces pending edits. Approved metadata stays active until new changes are approved.', 'ehrman-blog-discovery' ) ); ?>');">
	<input type="hidden" name="action" value="<?php echo esc_attr( $native ? 'ehrman_ingestion_prepare_regeneration' : 'ehrman_ingestion_reanalyze' ); ?>">
	<input type="hidden" name="draft_id" value="<?php echo esc_attr( (string) $view['id'] ); ?>">
	<input type="hidden" name="revision" value="<?php echo esc_attr( $view['revision'] ?? '' ); ?>">
	<?php wp_nonce_field( 'ehrman_ingestion_reanalyze' ); ?>
	<button class="button" type="submit"><?php echo esc_html( $label ); ?></button>
</form>
