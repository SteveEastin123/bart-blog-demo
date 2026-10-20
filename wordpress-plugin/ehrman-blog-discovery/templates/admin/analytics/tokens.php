<?php
/**
 * Reusable token total and breakdown.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tokens = $ehrman_blog_discovery_context;
?>
<strong><?php echo esc_html( $tokens['total'] ); ?></strong>
<br><small><?php echo esc_html( $tokens['details'] ); ?></small>
