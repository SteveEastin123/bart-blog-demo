<?php
/**
 * Publisher documentation and read-only system information.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

$ehrman_blog_discovery_status = is_array( $ehrman_blog_discovery_context['status'] ?? null ) ? $ehrman_blog_discovery_context['status'] : array();
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Info & Documentation', 'ehrman-blog-discovery' ); ?></h1>
	<section aria-labelledby="ehrman-publisher-guide-label">
		<h2 id="ehrman-publisher-guide-label"><?php esc_html_e( 'Ehrman Blog Publisher Guide', 'ehrman-blog-discovery' ); ?></h2>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( EHRMAN_DISCOVERY_PLUGIN_URL . 'docs/ehrman_blog_publisher_guide.html' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Read Guide', 'ehrman-blog-discovery' ); ?></a>
			<a class="button" href="<?php echo esc_url( EHRMAN_DISCOVERY_PLUGIN_URL . 'docs/ehrman_blog_publisher_guide.pdf' ); ?>" download="ehrman_blog_publisher_guide.pdf"><?php esc_html_e( 'Download PDF', 'ehrman-blog-discovery' ); ?></a>
		</p>
	</section>
	<section aria-labelledby="ehrman-system-information-label">
		<h2 id="ehrman-system-information-label"><?php esc_html_e( 'System Information', 'ehrman-blog-discovery' ); ?></h2>
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
	</section>
</div>
