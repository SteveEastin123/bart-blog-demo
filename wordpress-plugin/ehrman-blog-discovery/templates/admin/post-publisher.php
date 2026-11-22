<?php
/**
 * Native publisher metadata preferences and review container.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$view  = $ehrman_blog_discovery_context;
$modes = array(
	'manual'    => __( 'Manual', 'ehrman-blog-discovery' ),
	'automatic' => __( 'Automatic', 'ehrman-blog-discovery' ),
);
wp_nonce_field( 'ehrman-publisher-' . $view['post_id'], 'ehrman_publisher_nonce' );
?>
<fieldset class="ehrman-publisher-mode" <?php disabled( $view['locked'] ); ?>>
	<legend><?php esc_html_e( 'Metadata Generation', 'ehrman-blog-discovery' ); ?></legend>
	<?php foreach ( $modes as $value => $label ) : ?>
		<label>
			<input type="radio" name="ehrman_metadata_mode" value="<?php echo esc_attr( $value ); ?>" <?php checked( $view['mode'], $value ); ?>>
			<?php echo esc_html( $label ); ?>
			<?php if ( $view['default'] === $value ) : ?>
				<span class="description"><?php esc_html_e( '(site default)', 'ehrman-blog-discovery' ); ?></span>
			<?php endif; ?>
		</label>
	<?php endforeach; ?>
</fieldset>
<div id="ehrman-post-metadata-root" class="ehrman-ingestion-editor"></div>
<noscript><p><a href="<?php echo esc_url( Post_Ingestion_Page::page_url() ); ?>"><?php esc_html_e( 'Post Metadata', 'ehrman-blog-discovery' ); ?></a></p></noscript>
