<?php
/**
 * Discovery administration status and publishing settings.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

$ehrman_blog_discovery_status = is_array( $ehrman_blog_discovery_context['status'] ?? null ) ? $ehrman_blog_discovery_context['status'] : array();
?>
<div class="wrap">
	<h1><?php echo esc_html__( 'Settings and Status', 'ehrman-blog-discovery' ); ?></h1>
	<p><?php echo esc_html__( 'The plugin is active and its dedicated search-index database tables are installed.', 'ehrman-blog-discovery' ); ?></p>
	<table class="widefat striped" style="max-width: 760px">
		<tbody>
			<tr><th scope="row"><?php echo esc_html__( 'Plugin version', 'ehrman-blog-discovery' ); ?></th><td><?php echo esc_html( Database::text( $ehrman_blog_discovery_status['plugin_version'] ?? '' ) ); ?></td></tr>
			<tr><th scope="row"><?php echo esc_html__( 'Schema version', 'ehrman-blog-discovery' ); ?></th><td><?php echo esc_html( Database::text( $ehrman_blog_discovery_status['schema_version'] ?? '' ) ); ?></td></tr>
			<tr><th scope="row"><?php echo esc_html__( 'Database connection', 'ehrman-blog-discovery' ); ?></th><td><?php echo ! empty( $ehrman_blog_discovery_status['database_connected'] ) ? esc_html__( 'Connected', 'ehrman-blog-discovery' ) : esc_html__( 'Unavailable', 'ehrman-blog-discovery' ); ?></td></tr>
			<tr><th scope="row"><?php echo esc_html__( 'Import state', 'ehrman-blog-discovery' ); ?></th><td><?php echo esc_html( ucfirst( Database::text( $ehrman_blog_discovery_status['import_state'] ?? '' ) ) ); ?></td></tr>
			<tr><th scope="row"><?php echo esc_html__( 'Imported posts', 'ehrman-blog-discovery' ); ?></th><td><?php echo esc_html( number_format_i18n( Database::integer( $ehrman_blog_discovery_status['counts']['external_posts'] ?? 0 ) ) ); ?></td></tr>
			<tr><th scope="row"><?php echo esc_html__( 'Topics', 'ehrman-blog-discovery' ); ?></th><td><?php echo esc_html( number_format_i18n( Database::integer( $ehrman_blog_discovery_status['counts']['topics'] ?? 0 ) ) ); ?></td></tr>
			<tr><th scope="row"><?php echo esc_html__( 'Secondary keywords', 'ehrman-blog-discovery' ); ?></th><td><?php echo esc_html( number_format_i18n( Database::integer( $ehrman_blog_discovery_status['counts']['keywords'] ?? 0 ) ) ); ?></td></tr>
		</tbody>
	</table>
	<section class="ehrman-admin-setting" data-ebd-publishing-settings>
		<h2 id="ehrman-metadata-mode-label"><?php esc_html_e( 'Metadata Generation', 'ehrman-blog-discovery' ); ?></h2>
		<fieldset aria-labelledby="ehrman-metadata-mode-label" data-ebd-setting-options data-ebd-publishing-modes disabled>
			<p><label><input type="radio" name="<?php echo esc_attr( Post_Ingestion_Publishing_Settings::OPTION ); ?>" value="manual" aria-describedby="ehrman-metadata-manual-description" <?php checked( Post_Ingestion_Publishing_Settings::default_mode(), 'manual' ); ?>> <strong><?php esc_html_e( 'Manual', 'ehrman-blog-discovery' ); ?></strong></label><span aria-hidden="true">: </span><span class="description" id="ehrman-metadata-manual-description"><?php esc_html_e( 'Create and review metadata, then approve it. The vector is generated after approval.', 'ehrman-blog-discovery' ); ?></span></p>
			<p><label><input type="radio" name="<?php echo esc_attr( Post_Ingestion_Publishing_Settings::OPTION ); ?>" value="automatic" aria-describedby="ehrman-metadata-automatic-description" <?php checked( Post_Ingestion_Publishing_Settings::default_mode(), 'automatic' ); ?>> <strong><?php esc_html_e( 'Automatic', 'ehrman-blog-discovery' ); ?></strong></label><span aria-hidden="true">: </span><span class="description" id="ehrman-metadata-automatic-description"><?php esc_html_e( 'Generate and approve metadata and create its vector after a new post is first published.', 'ehrman-blog-discovery' ); ?></span></p>
		</fieldset>
		<p class="description"><?php esc_html_e( 'Sets default behavior for new posts. Each post can override this choice before publication.', 'ehrman-blog-discovery' ); ?></p>
		<p role="status" aria-live="polite" data-ebd-setting-status></p>
		<div class="notice notice-error inline" role="alert" data-ebd-setting-error hidden><p></p></div>
		<noscript><p><?php esc_html_e( 'Publishing settings require JavaScript.', 'ehrman-blog-discovery' ); ?></p></noscript>
	</section>
	<section class="ehrman-admin-setting" data-ebd-deletion-settings>
		<h2 id="ehrman-deletion-label"><?php esc_html_e( 'Allow Deletion', 'ehrman-blog-discovery' ); ?></h2>
		<fieldset aria-labelledby="ehrman-deletion-label" data-ebd-setting-options disabled>
			<p><label><input type="radio" name="<?php echo esc_attr( Organization_Deletion_Settings::OPTION ); ?>" value="disabled" aria-describedby="ehrman-deletion-disabled-description" <?php checked( ! Organization_Deletion_Settings::enabled() ); ?>> <strong><?php esc_html_e( 'Disabled', 'ehrman-blog-discovery' ); ?></strong></label><span aria-hidden="true">: </span><span class="description" id="ehrman-deletion-disabled-description"><?php esc_html_e( 'Prevent deletion of Subject Areas, Categories, and Topics.', 'ehrman-blog-discovery' ); ?></span></p>
			<p><label><input type="radio" name="<?php echo esc_attr( Organization_Deletion_Settings::OPTION ); ?>" value="enabled" aria-describedby="ehrman-deletion-enabled-description" <?php checked( Organization_Deletion_Settings::enabled() ); ?>> <strong><?php esc_html_e( 'Enabled', 'ehrman-blog-discovery' ); ?></strong></label><span aria-hidden="true">: </span><span class="description" id="ehrman-deletion-enabled-description"><?php esc_html_e( 'Allow administrators to delete Subject Areas, Categories, and unused Topics after confirmation.', 'ehrman-blog-discovery' ); ?></span></p>
		</fieldset>
		<p role="status" aria-live="polite" data-ebd-setting-status></p>
		<div class="notice notice-error inline" role="alert" data-ebd-setting-error hidden><p></p></div>
		<noscript><p><?php esc_html_e( 'Deletion settings require JavaScript.', 'ehrman-blog-discovery' ); ?></p></noscript>
	</section>
</div>
