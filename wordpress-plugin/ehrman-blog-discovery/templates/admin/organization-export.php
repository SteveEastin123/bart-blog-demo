<?php
/**
 * Export scope and downloads.
 *
 * @package EhrmanBlogDiscovery
 */

use EhrmanBlogDiscovery\Template;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$areas = $ehrman_blog_discovery_context['areas'];
?>
<div class="wrap ehrman-organization">
	<h1><?php esc_html_e( 'Manage Topics', 'ehrman-blog-discovery' ); ?></h1>
	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted navigation template.
	echo Template::render( 'admin/organization-tabs.php', array( 'tab' => 'export' ) );
	?>
	<h2><?php esc_html_e( 'Export Structure', 'ehrman-blog-discovery' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ehrman-organization__export" data-ebd-export>
		<input type="hidden" name="action" value="ehrman_organization_export">
		<?php wp_nonce_field( 'ehrman_organization_export' ); ?>
		<fieldset class="ehrman-organization__export-scope">
			<legend><?php esc_html_e( 'What to export', 'ehrman-blog-discovery' ); ?></legend>
			<label><input type="radio" name="scope" value="all" checked> <?php esc_html_e( 'Entire structure', 'ehrman-blog-discovery' ); ?></label>
			<label><input type="radio" name="scope" value="selected"> <?php esc_html_e( 'Selected Subject Areas', 'ehrman-blog-discovery' ); ?></label>
		</fieldset>
		<fieldset class="ehrman-organization__export-areas" data-ebd-export-areas>
			<legend><?php esc_html_e( 'Subject Areas', 'ehrman-blog-discovery' ); ?></legend>
			<?php foreach ( $areas as $area ) : ?>
				<label><input type="checkbox" name="subject_area_ids[]" value="<?php echo esc_attr( $area['id'] ); ?>"> <span><?php echo esc_html( $area['name'] ); ?></span></label>
			<?php endforeach; ?>
		</fieldset>
		<?php if ( ! $areas ) : ?>
			<p><?php esc_html_e( 'There are no Subject Areas to export.', 'ehrman-blog-discovery' ); ?></p>
		<?php endif; ?>
		<p class="ehrman-organization__actions">
			<button type="submit" name="format" value="pdf" class="button button-primary" disabled data-ebd-export-pdf><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e( 'Download PDF', 'ehrman-blog-discovery' ); ?></button>
			<button type="submit" name="format" value="csv" class="button" <?php disabled( ! $areas ); ?>><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e( 'Download CSV', 'ehrman-blog-discovery' ); ?></button>
		</p>
		<noscript><p><?php esc_html_e( 'PDF download requires JavaScript. CSV is available without it.', 'ehrman-blog-discovery' ); ?></p></noscript>
		<p role="status" aria-live="polite" data-ebd-export-status></p>
	</form>
</div>
