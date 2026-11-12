<?php
/**
 * Metadata generation runs with expandable attempt costs.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div style="overflow-x:auto">
<table class="widefat striped" style="min-width:720px" data-ebd-metadata-runs>
	<thead><tr><th><?php echo esc_html__( 'Latest call (ET)', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Post', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Total cost', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Generation details', 'ehrman-blog-discovery' ); ?></th></tr></thead>
	<tbody>
	<?php
	if ( empty( $ehrman_blog_discovery_context['rows'] ) ) :
		?>
		<tr><td colspan="4"><?php echo esc_html__( 'No metadata runs match these filters.', 'ehrman-blog-discovery' ); ?></td></tr><?php endif; ?>
	<?php foreach ( $ehrman_blog_discovery_context['rows'] as $row ) : ?>
		<tr>
			<td><?php echo esc_html( AI_Cost_Renderer::date( $row['created_at'] ) ); ?></td>
			<td style="overflow-wrap:anywhere"><?php echo esc_html( $row['post_title'] ); ?><br><small><?php echo esc_html( __( 'Source post ID:', 'ehrman-blog-discovery' ) . ' ' . $row['post_id'] ); ?></small></td>
			<td><strong><?php echo esc_html( $row['summary']['unknown'] === $row['summary']['calls'] ? __( 'Unknown', 'ehrman-blog-discovery' ) : AI_Cost_Renderer::money( $row['summary']['total'] ) ); ?></strong>
			<?php
			if ( $row['summary']['unknown'] > 0 ) :
				?>
				<br><?php echo esc_html__( 'Incomplete usage', 'ehrman-blog-discovery' ); ?><?php endif; ?>
			<?php
			if ( $row['summary']['historical'] > 0 ) :
				?>
				<br><?php echo esc_html__( 'Historical snapshot', 'ehrman-blog-discovery' ); ?><?php endif; ?>
			</td>
			<td><details><summary><?php echo esc_html( number_format_i18n( $row['summary']['calls'] ) . ' ' . __( 'recorded attempts / snapshots', 'ehrman-blog-discovery' ) ); ?></summary>
			<ul>
			<?php foreach ( $row['calls'] as $call ) : ?>
				<li style="overflow-wrap:anywhere">
					<strong><?php echo esc_html( 'post_embedding' === $call['purpose'] ? __( 'Post vector', 'ehrman-blog-discovery' ) : __( 'Metadata generation', 'ehrman-blog-discovery' ) ); ?></strong>
					<?php echo esc_html( AI_Cost_Renderer::date( $call['created_at'] ) ); ?><br>
					<?php echo esc_html( $call['model'] ); ?>:
					<?php echo esc_html( 1 === (int) $call['cost_known'] ? AI_Cost_Renderer::money( (float) $call['estimated_cost_usd'] ) : __( 'Cost unknown', 'ehrman-blog-discovery' ) ); ?>;
					<?php echo esc_html( 1 === (int) $call['request_succeeded'] ? __( 'Success', 'ehrman-blog-discovery' ) : __( 'Failed', 'ehrman-blog-discovery' ) ); ?><br>
					<?php echo esc_html( number_format_i18n( $call['input_tokens'] ) . ' ' . __( 'input tokens;', 'ehrman-blog-discovery' ) . ' ' . number_format_i18n( $call['cached_input_tokens'] ) . ' ' . __( 'cached;', 'ehrman-blog-discovery' ) . ' ' . number_format_i18n( $call['output_tokens'] ) . ' ' . __( 'output tokens', 'ehrman-blog-discovery' ) ); ?>
				</li>
			<?php endforeach; ?>
			</ul></details></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
</div>
