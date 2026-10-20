<?php
/**
 * Unfiltered standard-period cost totals.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$rows = $ehrman_blog_discovery_context['rows'];
?>
<h2><?php echo esc_html__( 'Cost by period', 'ehrman-blog-discovery' ); ?></h2>
<p><?php echo esc_html__( 'Active-method totals by question submission date, independent of the filters above.', 'ehrman-blog-discovery' ); ?></p>
<div style="overflow-x:auto;max-width:1050px">
<table class="widefat striped" style="max-width:1050px">
	<thead><tr><th><?php echo esc_html__( 'Period', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Questions', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'AI reviews', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Uncached stages', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Estimated cost', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Average per question', 'ehrman-blog-discovery' ); ?></th></tr></thead>
	<tbody>
	<?php foreach ( $rows as $row ) : ?>
		<tr><th scope="row"><?php echo esc_html( $row['label'] ); ?></th><td><?php echo esc_html( $row['questions'] ); ?></td><td><?php echo esc_html( $row['reviews'] ); ?></td><td><?php echo esc_html( $row['uncached_stages'] ); ?></td><td><?php echo esc_html( $row['total_cost'] ); ?></td><td><?php echo esc_html( $row['average_cost'] ); ?></td></tr>
	<?php endforeach; ?>
	</tbody>
</table>
</div>
