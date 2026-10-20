<?php
/**
 * Analytics dashboard layout and summary.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$view           = $ehrman_blog_discovery_context;
$filter_context = array(
	'filters'   => $view['filters'],
	'clear_url' => $view['clear_url'],
);
$reset_context  = array(
	'export_url'            => $view['export_url'],
	'refinement_export_url' => $view['refinement_export_url'],
	'confirmation'          => $view['reset_confirmation'],
);
?>
<div class="wrap">
	<h1><?php echo esc_html__( 'Ask AI Analytics', 'ehrman-blog-discovery' ); ?></h1>
	<?php if ( $view['was_reset'] ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Active Ask AI analytics and search caches were reset. Retired-method history, post vectors and index-preparation history were preserved.', 'ehrman-blog-discovery' ); ?></p></div>
	<?php endif; ?>
	<p><?php echo esc_html__( 'Reports include the active title-and-summary search only. Retired methods and experiments are excluded. Dates use Eastern time; no account, IP address or browser identifier is stored with a request.', 'ehrman-blog-discovery' ); ?></p>
	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The trusted partial escapes its display values.
	echo Template::render( 'admin/analytics/filters.php', $filter_context );
	?>
	<div style="display:flex;gap:12px;flex-wrap:wrap;margin:18px 0" data-ebd-analytics-metrics>
		<?php foreach ( $view['metrics'] as $metric ) : ?>
			<div style="min-width:140px;padding:14px 18px;border:1px solid #c3c4c7;background:#fff"><strong style="display:block;font-size:22px"><?php echo esc_html( $metric[1] ); ?></strong><span><?php echo esc_html( $metric[0] ); ?></span></div>
		<?php endforeach; ?>
	</div>
	<p><?php echo esc_html( $view['cost_details'] ); ?></p>
	<p><?php echo esc_html__( 'Costs are estimates based on API-reported usage and recorded pricing. Confirm billed amounts in the OpenAI usage dashboard. Index preparation is reported separately.', 'ehrman-blog-discovery' ); ?></p>
	<details style="margin:18px 0">
		<summary><strong><?php echo esc_html__( 'Token and cache details', 'ehrman-blog-discovery' ); ?></strong></summary>
		<p>
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The trusted partial escapes its display values.
		echo Template::render( 'admin/analytics/tokens.php', $view['tokens'] );
		?>
		</p>
		<p><?php echo esc_html( $view['cache_details'] ); ?></p>
	</details>
	<h2><?php echo esc_html__( 'Questions', 'ehrman-blog-discovery' ); ?></h2>
	<p><a class="button" href="<?php echo esc_url( $view['export_url'] ); ?>"><?php echo esc_html__( 'Export questions CSV', 'ehrman-blog-discovery' ); ?></a> <a class="button" href="<?php echo esc_url( $view['refinement_export_url'] ); ?>"><?php echo esc_html__( 'Export AI reviews CSV', 'ehrman-blog-discovery' ); ?></a></p>
	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The trusted partial escapes its display values.
	echo Template::render( 'admin/analytics/questions.php', array( 'rows' => $view['questions'] ) );
	?>
	<?php if ( '' !== $view['pagination'] ) : ?>
		<div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post( $view['pagination'] ); ?></div></div>
	<?php endif; ?>
	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The trusted partial escapes its display values.
	echo Template::render( 'admin/analytics/reviews.php', array( 'rows' => $view['reviews'] ) );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The trusted partial escapes its display values.
	echo Template::render( 'admin/analytics/periods.php', array( 'rows' => $view['periods'] ) );
	?>
	<p><strong><?php echo esc_html__( 'Index preparation:', 'ehrman-blog-discovery' ); ?></strong>
	<?php echo esc_html( $view['index_note'] ); ?>
	</p>
	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The trusted partial escapes its display values.
	echo Template::render( 'admin/analytics/reset.php', $reset_context );
	?>
</div>
