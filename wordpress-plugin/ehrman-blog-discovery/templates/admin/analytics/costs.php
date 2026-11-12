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
	<p style="font-size:20px"><strong><?php echo esc_html__( 'Total estimated cost:', 'ehrman-blog-discovery' ); ?> <?php echo esc_html( AI_Cost_Renderer::money( $metadata ? $summary['total'] : $overview['total'] ) ); ?></strong></p>
	<?php if ( ! $metadata ) : ?>
		<div style="overflow-x:auto">
		<table class="widefat striped" data-ebd-cost-overview>
			<thead><tr><th><?php echo esc_html__( 'Activity', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Total cost', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Volume', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Average cost', 'ehrman-blog-discovery' ); ?></th></tr></thead>
			<tbody>
				<tr><td><?php echo esc_html__( 'Ask AI', 'ehrman-blog-discovery' ); ?></td><td><?php echo esc_html( AI_Cost_Renderer::money( $overview['search']['total_cost'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $overview['search']['questions'] ) . ' ' . __( 'questions', 'ehrman-blog-discovery' ) ); ?></td><td><?php echo esc_html( AI_Cost_Renderer::money( $overview['search']['average_question'] ) . ' ' . __( 'per question', 'ehrman-blog-discovery' ) ); ?></td></tr>
				<tr><td><?php echo esc_html__( 'Post Metadata', 'ehrman-blog-discovery' ); ?></td><td><?php echo esc_html( AI_Cost_Renderer::money( $overview['metadata']['total'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $overview['metadata']['runs'] ) . ' ' . __( 'generation runs', 'ehrman-blog-discovery' ) ); ?></td><td><?php echo esc_html( AI_Cost_Renderer::money( $overview['metadata']['average'] ) . ' ' . __( 'per run', 'ehrman-blog-discovery' ) ); ?></td></tr>
				<tr><td><?php echo esc_html__( 'Bulk / index vectors', 'ehrman-blog-discovery' ); ?></td><td><?php echo esc_html( AI_Cost_Renderer::money( $overview['index']['total'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $overview['index']['calls'] ) . ' ' . __( 'recorded calls', 'ehrman-blog-discovery' ) ); ?></td><td><?php echo esc_html__( 'Not included in per-question or per-run averages', 'ehrman-blog-discovery' ); ?></td></tr>
			</tbody>
		</table>
		</div>
		<p><?php echo esc_html__( 'Ask AI includes query embeddings and candidate review. Post Metadata includes metadata generation and post vectors. Bulk vector generation is separate; importing existing vectors incurs no new API charge.', 'ehrman-blog-discovery' ); ?></p>
	<?php else : ?>
		<p><?php echo esc_html( __( 'Metadata generation:', 'ehrman-blog-discovery' ) . ' ' . AI_Cost_Renderer::money( $summary['metadata'] ) . ' | ' . __( 'Post vectors:', 'ehrman-blog-discovery' ) . ' ' . AI_Cost_Renderer::money( $summary['vectors'] ) ); ?></p>
		<p><?php echo esc_html( number_format_i18n( $summary['runs'] ) . ' ' . __( 'generation runs; average recorded cost per run:', 'ehrman-blog-discovery' ) . ' ' . AI_Cost_Renderer::money( $summary['average'] ) ); ?></p>
		<p><a class="button" href="<?php echo esc_url( $view['export_url'] ); ?>"><?php echo esc_html__( 'Export metadata costs CSV', 'ehrman-blog-discovery' ); ?></a></p>
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted partial escapes all event fields.
		echo Template::render( 'admin/analytics/metadata-runs.php', array( 'rows' => $view['runs'] ) );
		?>
		<p><?php echo wp_kses_post( $view['pagination'] ); ?></p>
	<?php endif; ?>
	<?php $unknown = $metadata ? $summary['unknown'] : $overview['metadata']['unknown'] + $overview['index']['unknown']; ?>
	<?php if ( $unknown > 0 ) : ?>
		<div class="notice notice-warning inline"><p>
		<?php
		/* translators: %s: number of calls with missing usage or pricing. */
		echo esc_html( sprintf( _n( '%s call has unknown cost and is excluded from the dollar totals.', '%s calls have unknown costs and are excluded from the dollar totals.', $unknown, 'ehrman-blog-discovery' ), number_format_i18n( $unknown ) ) );
		?>
		<?php echo esc_html__( 'Totals and averages are incomplete, not zero-cost estimates for those calls.', 'ehrman-blog-discovery' ); ?></p></div>
	<?php endif; ?>
	<p><?php echo esc_html__( 'Costs are estimates based on recorded usage and pricing, not an invoice. Dates use Eastern time. Ask AI groups costs by question submission date; metadata and bulk-vector costs use the API-call date. A run spanning the selected dates includes only calls in that range.', 'ehrman-blog-discovery' ); ?></p>
	<p><?php echo esc_html__( 'Historical totals cover retained records only. Older metadata snapshots may omit retries, deleted drafts, and previous generations; their dates may be approximate. Calls made outside this plugin are not included.', 'ehrman-blog-discovery' ); ?></p>
	<p><?php echo esc_html( __( 'Per-attempt metadata tracking enabled:', 'ehrman-blog-discovery' ) . ' ' . $view['since'] ); ?></p>
</div>
