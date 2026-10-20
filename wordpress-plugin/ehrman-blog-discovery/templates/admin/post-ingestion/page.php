<?php
/**
 * Post ingestion page layout and notices.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$view = $ehrman_blog_discovery_context;
?>
<div class="wrap ehrman-ingestion">
	<h1><?php echo esc_html__( 'Post Ingestion', 'ehrman-blog-discovery' ); ?></h1>
	<p><?php echo esc_html__( 'Prepare a complete Bart Ehrman Blog post for the discovery index. Analysis creates a reviewable draft; it never changes live search data.', 'ehrman-blog-discovery' ); ?></p>
	<?php if ( ! $view['analysis_configured'] ) : ?>
		<div class="notice notice-warning inline"><p><strong><?php echo esc_html__( 'Analysis is not configured.', 'ehrman-blog-discovery' ); ?></strong> <?php echo esc_html__( 'Add the separate ingestion-project key as EHRMAN_INGESTION_OPENAI_API_KEY.', 'ehrman-blog-discovery' ); ?></p></div>
	<?php endif; ?>
	<?php if ( ! $view['database_authoritative'] ) : ?>
		<div class="notice notice-info inline"><p><strong><?php echo esc_html__( 'JSON is the current source of truth.', 'ehrman-blog-discovery' ); ?></strong> <?php echo esc_html__( 'Draft analysis and review are available, but approval is locked because a JSON import would replace database-only posts. After the handoff, set EHRMAN_DISCOVERY_POST_SOURCE=mysql to enable approval.', 'ehrman-blog-discovery' ); ?></p></div>
	<?php endif; ?>
	<?php if ( null !== $view['notice'] ) : ?>
		<div class="notice <?php echo esc_attr( $view['notice']['success'] ? 'notice-success' : 'notice-error' ); ?> is-dismissible"><p><?php echo esc_html( $view['notice']['message'] ); ?></p></div>
	<?php endif; ?>
	<?php if ( null !== $view['draft'] ) : ?>
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The trusted partial escapes its display values.
		echo Template::render( 'admin/post-ingestion/draft.php', $view['draft'] );
		?>
	<?php else : ?>
		<?php if ( $view['missing_draft'] ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html__( 'The requested ingestion record was not found.', 'ehrman-blog-discovery' ); ?></p></div>
		<?php endif; ?>
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The trusted partial escapes its display values.
		echo Template::render( 'admin/post-ingestion-new.php', array( 'analysis_configured' => $view['analysis_configured'] ) );
		?>
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The trusted partial escapes its display values.
		echo Template::render( 'admin/post-ingestion/history.php', array( 'rows' => $view['history'] ) );
		?>
	<?php endif; ?>
</div>
<style>
	.ehrman-ingestion .form-table th { width: 180px; }
	.ehrman-ingestion .regular-text { width: min(100%, 680px); }
	.ehrman-ingestion textarea.large-text { max-width: 920px; }
	.ehrman-ingestion__meta { max-width: 920px; }
	.ehrman-ingestion__actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 18px 0; }
	.ehrman-ingestion__actions form { margin: 0; }
	.ehrman-ingestion__new { border-left: 4px solid #d63638; padding-left: 12px; }
	.ehrman-ingestion__status { display: inline-block; font-weight: 600; text-transform: capitalize; }
	.ehrman-ingestion__rationales { margin: 0; max-width: 920px; }
	.ehrman-ingestion__rationales li { margin-bottom: 8px; }
</style>
