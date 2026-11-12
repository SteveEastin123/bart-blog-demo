<?php
/**
 * Overview and post-metadata cost dashboards.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$view     = $ehrman_blog_discovery_context;
$metadata = 'post-metadata' === $view['tab'];
$summary  = $view['summary'];
$overview = $view['overview'];
$unknown  = $metadata ? $summary['unknown'] : $overview['metadata']['unknown'];
?>
<div class="wrap">
	<h1><?php echo esc_html__( 'AI Analytics', 'ehrman-blog-discovery' ); ?></h1>
	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted navigation template escapes links and labels.
	echo $view['navigation'];
	?>
	<h2><?php echo esc_html( $metadata ? __( 'Post Metadata', 'ehrman-blog-discovery' ) : __( 'Combined AI Costs', 'ehrman-blog-discovery' ) ); ?></h2>
	<p>
	<?php
	foreach ( $view['periods'] as $period ) :
		?>
		<a class="button" href="<?php echo esc_url( $period['url'] ); ?>"><?php echo esc_html( $period['label'] ); ?></a> <?php endforeach; ?></p>
	<form method="get" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin:18px 0">
		<input type="hidden" name="page" value="ehrman-ai-analytics">
		<input type="hidden" name="tab" value="<?php echo esc_attr( $view['tab'] ); ?>">
		<label><?php echo esc_html__( 'From', 'ehrman-blog-discovery' ); ?><br><input type="date" name="date_from" value="<?php echo esc_attr( $view['filters']['date_from'] ); ?>"></label>
		<label><?php echo esc_html__( 'To', 'ehrman-blog-discovery' ); ?><br><input type="date" name="date_to" value="<?php echo esc_attr( $view['filters']['date_to'] ); ?>"></label>
		<?php
		if ( $metadata ) :
			?>
			<label><?php echo esc_html__( 'Post title', 'ehrman-blog-discovery' ); ?><br><input type="search" name="search" value="<?php echo esc_attr( $view['filters']['search'] ); ?>"></label><?php endif; ?>
		<button class="button button-primary" type="submit"><?php echo esc_html__( 'Apply filters', 'ehrman-blog-discovery' ); ?></button>
		<a class="button" href="<?php echo esc_url( $view['clear_url'] ); ?>"><?php echo esc_html__( 'Clear', 'ehrman-blog-discovery' ); ?></a>
	</form>
	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The shared partial escapes metric values and labels.
	echo Template::render( 'admin/analytics/metrics.php', array( 'metrics' => $view['metrics'] ) );
	?>
	<?php if ( $unknown > 0 ) : ?>
		<div class="notice notice-warning inline"><p>
		<?php
		/* translators: %s: number of calls with missing usage or pricing. */
		echo esc_html( sprintf( _n( '%s call has unknown cost and is excluded from the dollar totals.', '%s calls have unknown costs and are excluded from the dollar totals.', $unknown, 'ehrman-blog-discovery' ), number_format_i18n( $unknown ) ) );
		?>
		<?php echo esc_html__( 'Totals and averages are incomplete, not zero-cost estimates for those calls.', 'ehrman-blog-discovery' ); ?></p></div>
	<?php endif; ?>
	<?php if ( ! $metadata ) : ?>
		<p><?php echo esc_html__( 'Total estimated cost includes Ask AI and Post Metadata only. Historical bulk/index preparation charges are excluded.', 'ehrman-blog-discovery' ); ?></p>
		<div style="overflow-x:auto;max-width:1200px">
		<table class="widefat striped" style="min-width:560px" data-ebd-cost-overview>
			<thead><tr><th><?php echo esc_html__( 'Activity', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Total cost', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Questions / runs', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Average cost', 'ehrman-blog-discovery' ); ?></th></tr></thead>
			<tbody>
				<tr><td><a href="<?php echo esc_url( $view['activities']['ask-ai'] ); ?>"><?php echo esc_html__( 'Ask AI', 'ehrman-blog-discovery' ); ?></a></td><td><?php echo esc_html( AI_Cost_Renderer::money( $overview['search']['total_cost'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $overview['search']['questions'] ) . ' ' . __( 'questions', 'ehrman-blog-discovery' ) ); ?></td><td><?php echo esc_html( AI_Analytics_Renderer::cents( $overview['search']['average_question'] ) . ' ' . __( 'per question', 'ehrman-blog-discovery' ) ); ?></td></tr>
				<tr><td><a href="<?php echo esc_url( $view['activities']['post-metadata'] ); ?>"><?php echo esc_html__( 'Post Metadata', 'ehrman-blog-discovery' ); ?></a></td><td><?php echo esc_html( AI_Cost_Renderer::money( $overview['metadata']['total'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $overview['metadata']['runs'] ) . ' ' . __( 'generation runs', 'ehrman-blog-discovery' ) ); ?></td><td><?php echo esc_html( AI_Analytics_Renderer::cents( $overview['metadata']['average'] ) . ' ' . __( 'per run', 'ehrman-blog-discovery' ) ); ?></td></tr>
			</tbody>
		</table>
		</div>
		<p><?php echo esc_html__( 'Ask AI includes query embeddings and candidate review. Post Metadata includes metadata generation and post vectors.', 'ehrman-blog-discovery' ); ?></p>
	<?php else : ?>
		<p><?php echo esc_html__( 'Totals and averages include metadata generation and post vectors within the active filters. Retries belong to the same run; generating metadata again starts a new run. Historical bulk/index preparation charges are excluded.', 'ehrman-blog-discovery' ); ?></p>
		<p><?php echo esc_html__( 'Costs are estimates based on API-reported usage and recorded pricing. Confirm billed amounts in the OpenAI usage dashboard.', 'ehrman-blog-discovery' ); ?></p>
		<details style="margin:18px 0" data-ebd-metadata-tokens>
			<summary><strong><?php echo esc_html__( 'Token and cache details', 'ehrman-blog-discovery' ); ?></strong></summary>
			<p>
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The shared partial escapes token values.
			echo Template::render( 'admin/analytics/tokens.php', $view['tokens'] );
			?>
			</p>
			<p><?php echo esc_html__( 'Cached input tokens are provider-side prompt-cache reads and are included in input tokens. Reasoning tokens are included in output tokens. Totals reflect recorded usage within the active filters; unreported usage is not included.', 'ehrman-blog-discovery' ); ?></p>
		</details>
		<h2><?php echo esc_html__( 'Generation runs', 'ehrman-blog-discovery' ); ?></h2>
		<p><a class="button" href="<?php echo esc_url( $view['export_url'] ); ?>"><?php echo esc_html__( 'Export metadata costs CSV', 'ehrman-blog-discovery' ); ?></a></p>
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted partial escapes all event fields.
		echo Template::render( 'admin/analytics/metadata-runs.php', array( 'rows' => $view['runs'] ) );
		?>
		<p><?php echo wp_kses_post( $view['pagination'] ); ?></p>
	<?php endif; ?>
	<p><?php echo esc_html__( 'Costs are estimates based on recorded usage and pricing, not an invoice. Dates use Eastern time. Ask AI groups costs by question submission date; metadata costs use the API-call date. A run spanning the selected dates includes only calls in that range.', 'ehrman-blog-discovery' ); ?></p>
	<p><?php echo esc_html__( 'Historical totals cover retained records only. Older metadata snapshots may omit retries, deleted drafts, and previous generations; their dates may be approximate. Calls made outside this plugin are not included.', 'ehrman-blog-discovery' ); ?></p>
	<p><?php echo esc_html( __( 'Per-attempt metadata tracking enabled:', 'ehrman-blog-discovery' ) . ' ' . $view['since'] ); ?></p>
</div>
