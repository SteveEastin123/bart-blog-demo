<?php
/**
 * Metadata history page layout and notices.
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
	<h1><?php echo esc_html__( 'Search Metadata', 'ehrman-blog-discovery' ); ?></h1>
	<?php if ( ! $view['analysis_configured'] ) : ?>
		<div class="notice notice-warning inline"><p><strong><?php echo esc_html__( 'Analysis is not configured.', 'ehrman-blog-discovery' ); ?></strong> <?php echo esc_html__( 'Set OPENAI_API_KEY on the server. Search and post ingestion use this shared key.', 'ehrman-blog-discovery' ); ?></p></div>
	<?php endif; ?>
	<?php if ( ! $view['database_authoritative'] ) : ?>
		<div class="notice notice-info inline"><p><strong><?php echo esc_html__( 'JSON is the current source of truth.', 'ehrman-blog-discovery' ); ?></strong> <?php echo esc_html__( 'Draft analysis and review are available, but approval is disabled in this environment because a JSON import would replace database-only posts.', 'ehrman-blog-discovery' ); ?></p></div>
	<?php endif; ?>
	<?php if ( null !== $view['notice'] ) : ?>
		<div class="notice <?php echo esc_attr( $view['notice']['success'] ? 'notice-success' : 'notice-error' ); ?> is-dismissible"><p><?php echo esc_html( $view['notice']['message'] ); ?></p></div>
	<?php endif; ?>
	<?php if ( null !== $view['draft'] ) : ?>
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The trusted partial escapes its display values.
		echo Template::render( 'admin/post-ingestion/draft.php', $view['draft'] );
		?>
	<?php elseif ( null !== $view['indexed'] ) : ?>
		<p><a href="<?php echo esc_url( Post_Ingestion_Page::page_url() ); ?>">&larr; <?php echo esc_html__( 'Search Metadata', 'ehrman-blog-discovery' ); ?></a></p>
		<h2><?php echo esc_html( $view['indexed']['title'] ); ?></h2>
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The trusted partial escapes its display fields.
		echo Template::render( 'admin/post-ingestion/approved.php', array_merge( $view['indexed'], array( 'summary' => $view['indexed']['search_summary'] ) ) );
		?>
		<p><a href="<?php echo esc_url( $view['indexed']['url'] ); ?>"><?php echo esc_html__( 'View post', 'ehrman-blog-discovery' ); ?></a></p>
	<?php else : ?>
		<?php if ( $view['missing_draft'] ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html__( 'The requested ingestion record was not found.', 'ehrman-blog-discovery' ); ?></p></div>
		<?php endif; ?>
		<p><a class="button" href="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>"><?php echo esc_html__( 'Posts', 'ehrman-blog-discovery' ); ?></a></p>
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The trusted partial escapes its display values.
		echo Template::render( 'admin/post-ingestion/history.php', $view['worklist'] );
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
	.ehrman-ingestion__filters { display: flex; flex-wrap: wrap; gap: 12px; align-items: end; margin: 18px 0; }
	.ehrman-ingestion__filters label, .ehrman-ingestion__filter { display: flex; flex-direction: column; gap: 4px; max-width: 100%; }
	.ehrman-ingestion__filters input, .ehrman-ingestion__filters select { max-width: 100%; }
	.ehrman-ingestion__table { overflow-x: auto; }
	.ehrman-ingestion__table table { min-width: 620px; }
	.ehrman-ingestion__table td { vertical-align: middle; overflow-wrap: anywhere; }
	.ehrman-ingestion__pagination { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin: 16px 0; }
</style>
