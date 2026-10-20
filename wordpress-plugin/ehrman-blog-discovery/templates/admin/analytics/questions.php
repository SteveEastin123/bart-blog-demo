<?php
/**
 * Filtered questions with combined costs, tokens and source details.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$rows = $ehrman_blog_discovery_context['rows'];
?>
<div style="overflow-x:auto">
<table class="widefat striped" data-ebd-analytics-questions>
	<thead><tr><th><?php echo esc_html__( 'Date (ET)', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Question', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Total cost', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Results', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Feedback', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Source', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Total tokens', 'ehrman-blog-discovery' ); ?></th></tr></thead>
	<tbody>
	<?php if ( empty( $rows ) ) : ?>
		<tr><td colspan="7"><?php echo esc_html__( 'No requests match these filters.', 'ehrman-blog-discovery' ); ?></td></tr>
	<?php else : ?>
		<?php foreach ( $rows as $row ) : ?>
			<tr>
				<td><?php echo esc_html( $row['date'] ); ?></td>
				<td><?php echo esc_html( $row['question'] ); ?>
					<?php if ( $row['failed'] ) : ?>
						<br><strong><?php echo esc_html__( 'Failed', 'ehrman-blog-discovery' ); ?></strong>: <?php echo esc_html( $row['error'] ); ?>
					<?php endif; ?>
				</td>
				<td><strong><?php echo esc_html( $row['total_cost'] ); ?></strong>
					<br><small><?php echo esc_html( $row['cost_details'] ); ?></small>
				</td>
				<td><?php echo esc_html( $row['results'] ); ?></td>
				<td><?php echo esc_html( $row['feedback'] ); ?></td>
				<td>
					<details><summary><?php echo esc_html__( 'Embedding and AI review', 'ehrman-blog-discovery' ); ?></summary>
					<p><?php echo esc_html__( 'Embedding:', 'ehrman-blog-discovery' ); ?><br>
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The trusted partial escapes its display values.
					echo Template::render( 'admin/analytics/source.php', $row['source'] );
					?>
					</p>
					<?php foreach ( $row['reviews'] as $review ) : ?>
						<p><?php echo esc_html__( 'AI review:', 'ehrman-blog-discovery' ); ?><br>
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The trusted partial escapes its display values.
						echo Template::render( 'admin/analytics/source.php', $review );
						?>
						<?php if ( $review['failed'] ) : ?>
							<br><?php echo esc_html__( 'Failed; broader results returned.', 'ehrman-blog-discovery' ); ?>
						<?php endif; ?>
						</p>
					<?php endforeach; ?>
					<?php if ( empty( $row['reviews'] ) ) : ?>
						<p><?php echo esc_html__( 'No AI review recorded.', 'ehrman-blog-discovery' ); ?></p>
					<?php endif; ?>
					</details>
				</td>
				<td>
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The trusted partial escapes its display values.
				echo Template::render( 'admin/analytics/tokens.php', $row['tokens'] );
				?>
				</td>
			</tr>
		<?php endforeach; ?>
	<?php endif; ?>
	</tbody>
</table>
</div>
