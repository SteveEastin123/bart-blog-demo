<?php
/**
 * Discovery status and JSON-import administration view.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

$ehrman_blog_discovery_status            = is_array( $ehrman_blog_discovery_context['status'] ?? null ) ? $ehrman_blog_discovery_context['status'] : array();
$ehrman_blog_discovery_notice            = $ehrman_blog_discovery_context['notice'] ?? null;
$ehrman_blog_discovery_notice_class      = Database::text( $ehrman_blog_discovery_context['notice_class'] ?? '' );
$ehrman_blog_discovery_sources_available = true === ( $ehrman_blog_discovery_context['sources_available'] ?? false );
?>
<div class="wrap">
	<h1><?php echo esc_html__( 'Ehrman Blog Discovery', 'ehrman-blog-discovery' ); ?></h1>
	<?php if ( is_array( $ehrman_blog_discovery_notice ) ) : ?>
		<div class="notice <?php echo esc_attr( $ehrman_blog_discovery_notice_class ); ?> is-dismissible">
			<p><?php echo esc_html( Database::text( $ehrman_blog_discovery_notice['message'] ?? '' ) ); ?></p>
		</div>
	<?php endif; ?>
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
	<p><?php echo esc_html__( 'Questions, feedback, token usage, refinement activity, and estimated costs are consolidated on the AI Search Analytics page.', 'ehrman-blog-discovery' ); ?></p>
	<p><a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=ehrman-ai-analytics' ) ); ?>"><?php echo esc_html__( 'Open AI Search Analytics', 'ehrman-blog-discovery' ); ?></a></p>
	<h2><?php echo esc_html__( 'Authoritative JSON import', 'ehrman-blog-discovery' ); ?></h2>
	<p><?php echo esc_html( $ehrman_blog_discovery_sources_available ? __( 'All five authoritative JSON files are available.', 'ehrman-blog-discovery' ) : __( 'One or more authoritative JSON files are unavailable.', 'ehrman-blog-discovery' ) ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="ehrman_discovery_import">
		<?php wp_nonce_field( 'ehrman_discovery_import' ); ?>
		<?php submit_button( __( 'Import authoritative JSON', 'ehrman-blog-discovery' ), 'primary', 'submit', true, $ehrman_blog_discovery_sources_available ? array() : array( 'disabled' => 'disabled' ) ); ?>
	</form>
</div>
