<?php
/**
 * Expandable AI review details.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$rows = $ehrman_blog_discovery_context['rows'];
?>
<details style="margin:24px 0">
	<summary><strong><?php echo esc_html__( 'AI review details', 'ehrman-blog-discovery' ); ?></strong></summary>
	<p><?php echo esc_html__( 'The latest 100 reviews linked to the filtered questions. The CSV export includes all matching reviews.', 'ehrman-blog-discovery' ); ?></p>
	<div style="overflow-x:auto">
	<table class="widefat striped">
		<thead><tr><th><?php echo esc_html__( 'Date (ET)', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Question', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Results', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Retained posts', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Source', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Tokens', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'AI review cost', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Status', 'ehrman-blog-discovery' ); ?></th></tr></thead>
		<tbody>
		<?php if ( empty( $rows ) ) : ?>
			<tr><td colspan="8"><?php echo esc_html__( 'No AI reviews match these filters.', 'ehrman-blog-discovery' ); ?></td></tr>
		<?php else : ?>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( $row['date'] ); ?></td>
					<td><?php echo esc_html( $row['question'] ); ?></td>
					<td><?php echo esc_html( $row['results'] ); ?></td>
					<td><?php echo esc_html( $row['posts'] ); ?></td>
					<td>
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The trusted partial escapes its display values.
					echo Template::render( 'admin/analytics/source.php', $row['source'] );
					?>
					</td>
					<td>
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The trusted partial escapes its display values.
					echo Template::render( 'admin/analytics/tokens.php', $row['tokens'] );
					?>
					</td>
					<td><?php echo esc_html( $row['cost'] ); ?></td>
					<td><?php echo esc_html( $row['status'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>
	</div>
</details>
