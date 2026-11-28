<?php
/**
 * Optional paginated directory for organization search and browsing.
 *
 * @package EhrmanBlogDiscovery
 */

use EhrmanBlogDiscovery\Database;
use EhrmanBlogDiscovery\Topic_Organization_Service;
use EhrmanBlogDiscovery\Template;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$view         = $ehrman_blog_discovery_context;
$labels       = $view['labels'];
$url          = $view['url'];
$category_tab = $view['category_tab'];
$edit_label   = $view['edit_label'];
?>
<?php if ( $view['show_list'] ) : ?>
<p><?php echo esc_html( sprintf( $labels['count'], number_format_i18n( $view['count'] ) ) ); ?></p>
<table class="widefat striped ehrman-organization__topics<?php echo $category_tab ? ' ehrman-organization__categories' : ''; ?>">
	<thead><tr>
		<?php if ( $category_tab ) : ?>
			<th scope="col"><?php esc_html_e( 'Category', 'ehrman-blog-discovery' ); ?></th><th scope="col"><?php esc_html_e( 'Subject Areas', 'ehrman-blog-discovery' ); ?></th><th scope="col"><?php esc_html_e( 'Topics', 'ehrman-blog-discovery' ); ?></th>
		<?php else : ?>
			<th scope="col"><?php echo esc_html( $labels['singular'] ); ?></th><th scope="col"><?php esc_html_e( 'Categories', 'ehrman-blog-discovery' ); ?></th><th scope="col"><?php esc_html_e( 'Posts', 'ehrman-blog-discovery' ); ?></th>
		<?php endif; ?>
		<th scope="col"><?php esc_html_e( 'Action', 'ehrman-blog-discovery' ); ?></th>
	</tr></thead>
	<tbody>
		<?php foreach ( $view['items'] as $row ) : ?>
			<tr>
				<?php if ( $category_tab ) : ?>
					<?php
					$category_context = array(
						'category' => $row,
						'url'      => $url( array( 'edit' => Database::integer( $row['id'] ) ) ),
					);
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Dynamic values are escaped in the trusted template.
					echo Template::render( 'admin/organization-category-row.php', $category_context );
					?>
				<?php else : ?>
				<td><?php echo esc_html( $row['name'] ); ?></td>
				<td><?php echo esc_html( implode( ', ', array_column( $row['categories'], 'name' ) ) ); ?></td>
				<td><?php echo esc_html( number_format_i18n( Database::integer( $row['post_count'] ) ) ); ?></td>
				<?php endif; ?>
				<td>
				<?php if ( 'topics' !== $view['tab'] || Topic_Organization_Service::editable( $row ) ) : ?>
					<a href="<?php echo esc_url( $url( array( 'edit' => Database::integer( $row['id'] ) ) ) ); ?>"><?php echo esc_html( $edit_label ); ?></a>
				<?php else : ?>
					<?php esc_html_e( 'Protected', 'ehrman-blog-discovery' ); ?>
				<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		<?php if ( empty( $view['items'] ) ) : ?>
			<tr><td colspan="4"><?php echo esc_html( $labels['empty'] ); ?></td></tr>
		<?php endif; ?>
	</tbody>
</table>
<nav class="ehrman-organization__pagination" aria-label="<?php echo esc_attr( $labels['pages'] ); ?>">
	<?php
	if ( $view['page_number'] > 1 ) :
		?>
		<a class="button" href="
		<?php
		echo esc_url(
			$url(
				array(
					's'       => $view['query'],
					'view'    => $view['browse_all'] ? 'all' : '',
					'pagenum' => $view['page_number'] - 1,
				)
			)
		);
		?>
		"><?php esc_html_e( 'Previous', 'ehrman-blog-discovery' ); ?></a><?php endif; ?>
	<span><?php /* translators: 1: Current page, 2: Total pages. */ echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'ehrman-blog-discovery' ), $view['page_number'], $view['total_pages'] ) ); ?></span>
	<?php
	if ( $view['page_number'] < $view['total_pages'] ) :
		?>
		<a class="button" href="
		<?php
		echo esc_url(
			$url(
				array(
					's'       => $view['query'],
					'view'    => $view['browse_all'] ? 'all' : '',
					'pagenum' => $view['page_number'] + 1,
				)
			)
		);
		?>
		"><?php esc_html_e( 'Next', 'ehrman-blog-discovery' ); ?></a><?php endif; ?>
</nav>
<?php endif; ?>
