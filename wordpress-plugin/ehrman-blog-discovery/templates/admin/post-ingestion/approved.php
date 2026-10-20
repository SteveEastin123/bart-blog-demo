<?php
/**
 * Post ingestion approved display.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$view = $ehrman_blog_discovery_context;
?>
<h2><?php echo esc_html__( 'Approved enrichment', 'ehrman-blog-discovery' ); ?></h2>
<dl>
	<dt><strong><?php echo esc_html__( 'Description', 'ehrman-blog-discovery' ); ?></strong></dt><dd><?php echo esc_html( $view['description'] ); ?></dd>
	<dt><strong><?php echo esc_html__( 'Search summary', 'ehrman-blog-discovery' ); ?></strong></dt><dd><?php echo esc_html( $view['summary'] ); ?></dd>
	<dt><strong><?php echo esc_html__( 'Topics', 'ehrman-blog-discovery' ); ?></strong></dt><dd><?php echo esc_html( $view['topics'] ); ?></dd>
	<dt><strong><?php echo esc_html__( 'Secondary keywords', 'ehrman-blog-discovery' ); ?></strong></dt><dd><?php echo esc_html( $view['keywords'] ); ?></dd>
</dl>
