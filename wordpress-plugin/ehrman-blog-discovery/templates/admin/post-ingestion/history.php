<?php
/**
 * Post ingestion history display.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$view = $ehrman_blog_discovery_context;
?>
<h2><?php echo esc_html__( 'Ingestion history', 'ehrman-blog-discovery' ); ?></h2>
<?php
if ( empty( $view['rows'] ) ) :
	?>
	<p><?php echo esc_html__( 'No ingestion records yet.', 'ehrman-blog-discovery' ); ?></p><?php return; ?><?php endif; ?>
<table class="widefat striped">
	<thead><tr><th><?php echo esc_html__( 'Updated', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Post', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Status', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Vector', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Action', 'ehrman-blog-discovery' ); ?></th></tr></thead>
	<tbody>
	<?php
	foreach ( $view['rows'] as $row ) :
		?>

		<tr><td><?php echo esc_html( $row['updated'] ); ?></td><td><strong><?php echo esc_html( $row['title'] ); ?></strong><br><span class="description"><?php echo esc_html( $row['wp_id'] ); ?></span></td><td><?php echo esc_html( $row['status'] ); ?></td><td><?php echo esc_html( $row['vector'] ); ?></td><td><a class="button button-small" href="<?php echo esc_url( $row['url'] ); ?>"><?php echo esc_html( $row['action'] ); ?></a></td></tr><?php endforeach; ?></tbody>
</table>
