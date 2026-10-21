<?php
/**
 * Linked search-result posts.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$view = $ehrman_blog_discovery_context;
?>
<?php if ( empty( $view['posts'] ) ) : ?>
	<p class="ebd-empty"><?php echo esc_html__( 'No posts matched this request.', 'ehrman-blog-discovery' ); ?></p>
<?php else : ?>
	<ul class="ebd-post-list">
		<?php foreach ( $view['posts'] as $item ) : ?>
			<li class="ebd-post-item"><a class="ebd-post-title" href="<?php echo esc_url( $item['url'] ); ?>" data-description="<?php echo esc_attr( $item['description'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a><p class="ebd-post-meta"><?php echo esc_html( $item['meta'] ); ?></p><p class="ebd-post-description"><?php echo esc_html( $item['description'] ); ?></p></li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>
