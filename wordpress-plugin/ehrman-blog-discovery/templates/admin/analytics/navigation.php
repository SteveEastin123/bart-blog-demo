<?php
/**
 * Shared analytics tabs.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__( 'AI analytics sections', 'ehrman-blog-discovery' ); ?>">
	<?php foreach ( $ehrman_blog_discovery_context['tabs'] as $analytics_tab ) : ?>
		<a class="nav-tab<?php echo $analytics_tab['active'] ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( $analytics_tab['url'] ); ?>" <?php echo $analytics_tab['active'] ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $analytics_tab['label'] ); ?></a>
	<?php endforeach; ?>
</nav>
