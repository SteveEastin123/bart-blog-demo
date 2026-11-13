<?php
/**
 * Read-only discovery administration status view.
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
	<h2><?php echo esc_html__( 'AI analytics', 'ehrman-blog-discovery' ); ?></h2>
	<p><?php echo esc_html__( 'Questions, token usage, refinement activity, and estimated costs are consolidated on the AI Search Analytics page.', 'ehrman-blog-discovery' ); ?></p>
	<p><a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=ehrman-ai-analytics' ) ); ?>"><?php echo esc_html__( 'Open AI Search Analytics', 'ehrman-blog-discovery' ); ?></a></p>
</div>
