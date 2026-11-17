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
	<h1><?php echo esc_html__( 'Ehrman Blog Discovery', 'ehrman-blog-discovery' ); ?></h1>
	<p><?php echo esc_html__( 'The plugin is active and its dedicated MySQL search-index tables are installed.', 'ehrman-blog-discovery' ); ?></p>
	<table class="widefat striped" style="max-width: 760px">
		<tbody>
			<tr><th scope="row"><?php echo esc_html__( 'Plugin version', 'ehrman-blog-discovery' ); ?></th><td><?php echo esc_html( Database::text( $ehrman_blog_discovery_status['plugin_version'] ?? '' ) ); ?></td></tr>
			<tr><th scope="row"><?php echo esc_html__( 'Schema version', 'ehrman-blog-discovery' ); ?></th><td><?php echo esc_html( Database::text( $ehrman_blog_discovery_status['schema_version'] ?? '' ) ); ?></td></tr>
			<tr><th scope="row"><?php echo esc_html__( 'Database connection', 'ehrman-blog-discovery' ); ?></th><td><?php echo ! empty( $ehrman_blog_discovery_status['database_connected'] ) ? esc_html__( 'Connected', 'ehrman-blog-discovery' ) : esc_html__( 'Unavailable', 'ehrman-blog-discovery' ); ?></td></tr>
			<tr><th scope="row"><?php echo esc_html__( 'Import state', 'ehrman-blog-discovery' ); ?></th><td><?php echo esc_html( Database::text( $ehrman_blog_discovery_status['import_state'] ?? '' ) ); ?></td></tr>
			<tr><th scope="row"><?php echo esc_html__( 'Imported posts', 'ehrman-blog-discovery' ); ?></th><td><?php echo esc_html( number_format_i18n( Database::integer( $ehrman_blog_discovery_status['counts']['external_posts'] ?? 0 ) ) ); ?></td></tr>
			<tr><th scope="row"><?php echo esc_html__( 'Topics', 'ehrman-blog-discovery' ); ?></th><td><?php echo esc_html( number_format_i18n( Database::integer( $ehrman_blog_discovery_status['counts']['topics'] ?? 0 ) ) ); ?></td></tr>
			<tr><th scope="row"><?php echo esc_html__( 'Secondary keywords', 'ehrman-blog-discovery' ); ?></th><td><?php echo esc_html( number_format_i18n( Database::integer( $ehrman_blog_discovery_status['counts']['keywords'] ?? 0 ) ) ); ?></td></tr>
		</tbody>
	</table>
	<h2><?php echo esc_html__( 'Publishing settings', 'ehrman-blog-discovery' ); ?></h2>
	<div data-ebd-publishing-settings>
		<table class="form-table" role="presentation"><tbody><tr>
			<th scope="row" id="ehrman-metadata-mode-label"><?php echo esc_html__( 'Metadata generation', 'ehrman-blog-discovery' ); ?></th>
			<td>
				<fieldset aria-labelledby="ehrman-metadata-mode-label" data-ebd-publishing-modes disabled>
					<p><label><input type="radio" name="<?php echo esc_attr( Post_Ingestion_Publishing_Settings::OPTION ); ?>" value="manual" aria-describedby="ehrman-metadata-manual-description" <?php checked( Post_Ingestion_Publishing_Settings::default_mode(), 'manual' ); ?> /> <strong><?php echo esc_html__( 'Manual', 'ehrman-blog-discovery' ); ?></strong></label></p>
					<p class="description" id="ehrman-metadata-manual-description"><?php echo esc_html__( 'Create and review metadata, then approve it. The vector is generated after approval.', 'ehrman-blog-discovery' ); ?></p>
					<p><label><input type="radio" name="<?php echo esc_attr( Post_Ingestion_Publishing_Settings::OPTION ); ?>" value="automatic" aria-describedby="ehrman-metadata-automatic-description" <?php checked( Post_Ingestion_Publishing_Settings::default_mode(), 'automatic' ); ?> /> <strong><?php echo esc_html__( 'Automatic', 'ehrman-blog-discovery' ); ?></strong></label></p>
					<p class="description" id="ehrman-metadata-automatic-description"><?php echo esc_html__( 'Generate and approve metadata and create its vector after a new post is first published, using paid AI requests.', 'ehrman-blog-discovery' ); ?></p>
				</fieldset>
				<p class="description"><?php echo esc_html__( 'Default for new posts. Each post can override this choice before publication. Existing posts are not processed.', 'ehrman-blog-discovery' ); ?></p>
				<p role="status" aria-live="polite" data-ebd-publishing-status></p>
				<div class="notice notice-error inline" role="alert" data-ebd-publishing-error hidden><p></p></div>
				<noscript><p><?php echo esc_html__( 'Publishing settings require JavaScript.', 'ehrman-blog-discovery' ); ?></p></noscript>
			</td>
		</tr></tbody></table>
	</div>
</div>
