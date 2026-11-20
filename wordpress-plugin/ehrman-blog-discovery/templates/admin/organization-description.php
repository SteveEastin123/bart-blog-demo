<?php
/**
 * Shared description field and scope-specific writing guidance.
 *
 * @package EhrmanBlogDiscovery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$view   = $ehrman_blog_discovery_context;
$labels = $view['labels'];
?>
<p><label for="ebd-topic-description"><?php esc_html_e( 'Description', 'ehrman-blog-discovery' ); ?></label><textarea id="ebd-topic-description" name="description" rows="4" maxlength="4000" required aria-describedby="<?php echo $view['topic_safeguards'] ? 'ebd-description-guidance ebd-topic-description-safeguards' : 'ebd-description-guidance'; ?>"><?php echo esc_textarea( $view['description'] ); ?></textarea></p>
<p id="ebd-description-guidance" class="description"><?php echo esc_html( $labels['guidance'] ); ?></p>
<details class="ehrman-organization__description-example">
	<summary><?php esc_html_e( 'Description example', 'ehrman-blog-discovery' ); ?></summary>
	<p><strong><?php echo esc_html( $labels['example_name'] ); ?></strong></p>
	<p><?php echo esc_html( $labels['example'] ); ?></p>
</details>
