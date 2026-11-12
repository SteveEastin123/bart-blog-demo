<?php
/**
 * Shared summary cards for search and post-metadata analytics.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,180px),1fr));gap:12px;max-width:1200px;margin:18px 0" data-ebd-analytics-metrics>
	<?php foreach ( $ehrman_blog_discovery_context['metrics'] as $metric ) : ?>
		<div style="min-width:0;padding:14px 18px;border:1px solid #c3c4c7;background:#fff;overflow-wrap:anywhere"><strong style="display:block;font-size:22px"><?php echo esc_html( $metric[1] ); ?></strong><span><?php echo esc_html( $metric[0] ); ?></span></div>
	<?php endforeach; ?>
</div>
