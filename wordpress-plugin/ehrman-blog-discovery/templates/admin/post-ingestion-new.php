<?php
/**
 * New post-ingestion submission form.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

$ehrman_blog_discovery_analysis_configured = true === ( $ehrman_blog_discovery_context['analysis_configured'] ?? false );
?>
<h2><?php echo esc_html__( 'Analyze a post', 'ehrman-blog-discovery' ); ?></h2>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="ehrman_ingestion_analyze">
	<?php wp_nonce_field( 'ehrman_ingestion_analyze' ); ?>
	<table class="form-table" role="presentation">
		<tr><th scope="row"><label for="source_wp_id"><?php echo esc_html__( 'WordPress post ID', 'ehrman-blog-discovery' ); ?></label></th><td><input class="regular-text" id="source_wp_id" name="source_wp_id" type="number" min="1" required></td></tr>
		<tr><th scope="row"><label for="title"><?php echo esc_html__( 'Title', 'ehrman-blog-discovery' ); ?></label></th><td><input class="regular-text" id="title" name="title" type="text" required></td></tr>
		<tr><th scope="row"><label for="url"><?php echo esc_html__( 'URL', 'ehrman-blog-discovery' ); ?></label></th><td><input class="regular-text" id="url" name="url" type="url" required></td></tr>
		<tr><th scope="row"><label for="author"><?php echo esc_html__( 'Author', 'ehrman-blog-discovery' ); ?></label></th><td><input class="regular-text" id="author" name="author" type="text" required></td></tr>
		<tr><th scope="row"><label for="date"><?php echo esc_html__( 'Publication date', 'ehrman-blog-discovery' ); ?></label></th><td><input id="date" name="date" type="date" required></td></tr>
		<tr><th scope="row"><label for="post_text"><?php echo esc_html__( 'Complete post text', 'ehrman-blog-discovery' ); ?></label></th><td><textarea class="large-text" id="post_text" name="post_text" rows="20" required></textarea><p class="description"><?php echo esc_html__( 'The full text is retained only while the draft is pending and is deleted after approval.', 'ehrman-blog-discovery' ); ?></p></td></tr>
	</table>
	<?php submit_button( __( 'Analyze post', 'ehrman-blog-discovery' ), 'primary', 'submit', true, $ehrman_blog_discovery_analysis_configured ? array() : array( 'disabled' => 'disabled' ) ); ?>
</form>
