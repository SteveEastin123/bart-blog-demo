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

$view    = $ehrman_blog_discovery_context;
$filters = $view['filters'];
?>
<form method="get" class="ehrman-ingestion__filters">
	<input type="hidden" name="page" value="ehrman-post-ingestion">
	<div class="ehrman-ingestion__filter">
		<label for="ehrman-metadata-status"><?php echo esc_html__( 'Status', 'ehrman-blog-discovery' ); ?></label>
		<select id="ehrman-metadata-status" name="status">
			<?php foreach ( $view['statuses'] as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['status'], $key ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>
	<label><?php echo esc_html__( 'Post title', 'ehrman-blog-discovery' ); ?><input type="search" name="search" value="<?php echo esc_attr( $filters['search'] ); ?>"></label>
	<label><?php echo esc_html__( 'Published from', 'ehrman-blog-discovery' ); ?><input type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>"></label>
	<label><?php echo esc_html__( 'Published through', 'ehrman-blog-discovery' ); ?><input type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>"></label>
	<button class="button button-primary" type="submit"><?php echo esc_html__( 'Apply filters', 'ehrman-blog-discovery' ); ?></button>
	<a class="button" href="<?php echo esc_url( Post_Ingestion_Page::page_url() ); ?>"><?php echo esc_html__( 'Clear', 'ehrman-blog-discovery' ); ?></a>
</form>
<p>
<?php
	/* translators: %s: number of matching metadata records. */
	echo esc_html( sprintf( _n( '%s record', '%s records', $view['total'], 'ehrman-blog-discovery' ), number_format_i18n( $view['total'] ) ) );
?>
</p>
<?php
if ( empty( $view['rows'] ) ) :
	?>
	<p><?php echo esc_html__( 'No posts match these filters.', 'ehrman-blog-discovery' ); ?></p><?php return; ?><?php endif; ?>
<div class="ehrman-ingestion__table">
<table class="widefat striped" data-ebd-metadata-worklist>
	<thead><tr><th><?php echo esc_html__( 'Published', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Post', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Status', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Vector', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Action', 'ehrman-blog-discovery' ); ?></th></tr></thead>
	<tbody>
	<?php foreach ( $view['rows'] as $row ) : ?>
		<tr>
			<td><?php echo esc_html( $row['published'] ); ?></td>
			<td><strong><?php echo esc_html( $row['title'] ); ?></strong></td>
			<td><?php echo esc_html( $row['status'] ); ?></td>
			<td><?php echo esc_html( $row['vector'] ); ?></td>
			<td>
				<?php if ( 'post' === $row['kind'] ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="ehrman_ingestion_create_metadata">
						<input type="hidden" name="post_id" value="<?php echo esc_attr( $row['post_id'] ); ?>">
						<?php wp_nonce_field( 'ehrman_ingestion_create_metadata' ); ?>
						<button class="button button-small" <?php disabled( ! $view['configured'] || ! current_user_can( 'edit_post', (int) $row['post_id'] ) ); ?>><?php echo esc_html__( 'Create Metadata', 'ehrman-blog-discovery' ); ?></button>
					</form>
				<?php else : ?>
					<a class="button button-small" href="<?php echo esc_url( $row['url'] ); ?>"><?php echo esc_html( $row['action'] ); ?></a>
				<?php endif; ?>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
</div>
<nav class="ehrman-ingestion__pagination" aria-label="<?php echo esc_attr__( 'Metadata pages', 'ehrman-blog-discovery' ); ?>">
	<?php if ( '' !== $view['previous_url'] ) : ?>
		<a class="button" rel="prev" href="<?php echo esc_url( $view['previous_url'] ); ?>"><?php echo esc_html__( 'Previous', 'ehrman-blog-discovery' ); ?></a>
	<?php endif; ?>
	<span>
	<?php
		/* translators: 1: current page, 2: total pages. */
		echo esc_html( sprintf( __( 'Page %1$s of %2$s', 'ehrman-blog-discovery' ), number_format_i18n( $filters['paged'] ), number_format_i18n( $view['pages'] ) ) );
	?>
	</span>
	<?php if ( '' !== $view['next_url'] ) : ?>
		<a class="button" rel="next" href="<?php echo esc_url( $view['next_url'] ); ?>"><?php echo esc_html__( 'Next', 'ehrman-blog-discovery' ); ?></a>
	<?php endif; ?>
</nav>
