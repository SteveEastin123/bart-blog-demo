<?php
/**
 * Shared analytics quick-date filters.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<p data-ebd-analytics-date-shortcuts>
	<?php foreach ( $ehrman_blog_discovery_context['links'] as $period ) : ?>
		<a class="button<?php echo $period['active'] ? ' button-primary' : ''; ?>" href="<?php echo esc_url( $period['url'] ); ?>"<?php echo $period['active'] ? ' aria-current="true"' : ''; ?>><?php echo esc_html( $period['label'] ); ?></a>
	<?php endforeach; ?>
</p>
