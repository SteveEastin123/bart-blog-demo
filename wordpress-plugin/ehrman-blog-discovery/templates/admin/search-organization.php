<?php
/**
 * Manage Topics page shell and view selection.
 *
 * @package EhrmanBlogDiscovery
 */

use EhrmanBlogDiscovery\Template;
use EhrmanBlogDiscovery\Topic_Organization_Page;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$view         = $ehrman_blog_discovery_context;
$labels       = $view['labels'];
$category_tab = $view['category_tab'];
$view['url']  = static fn( array $args = array() ): string => Topic_Organization_Page::url( array_merge( $view['url_args'], $args ) );
?>
<div class="wrap ehrman-organization">
	<h1><?php esc_html_e( 'Manage Topics', 'ehrman-blog-discovery' ); ?></h1>
	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted navigation template.
	echo Template::render( 'admin/organization-tabs.php', array( 'tab' => $view['tab'] ) );
	?>
	<?php if ( '' !== $view['error'] ) : ?>
		<div class="notice notice-error" role="alert"><p><?php echo esc_html( $view['error'] ); ?></p></div>
	<?php elseif ( 'description' === $view['saved'] ) : ?>
		<div class="notice notice-success" role="status"><p><?php esc_html_e( 'Description saved.', 'ehrman-blog-discovery' ); ?></p></div>
	<?php elseif ( 'delete' === $view['saved'] ) : ?>
		<div class="notice notice-success" role="status"><p><?php esc_html_e( 'Item deleted. Posts and other organization items were not deleted.', 'ehrman-blog-discovery' ); ?></p></div>
	<?php elseif ( in_array( $view['saved'], array( 'created', 'categories', 'links' ), true ) ) : ?>
		<div class="notice notice-success" role="status"><p><?php echo 'created' === $view['saved'] ? esc_html( $labels['created'] ) : ( $category_tab ? esc_html__( 'Relationships saved.', 'ehrman-blog-discovery' ) : esc_html__( 'Category links saved.', 'ehrman-blog-discovery' ) ); ?></p></div>
	<?php endif; ?>
	<?php
	if ( $view['confirming_delete'] ) {
		$template = 'admin/organization-delete.php';
	} elseif ( $view['editing_description'] ) {
		$template = 'admin/organization-edit-description.php';
	} elseif ( $view['is_form'] ) {
		$template = 'admin/organization-form.php';
	} else {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted search template escapes its values.
		echo Template::render( 'admin/organization-search.php', $view );
		$template = 'admin/organization-directory.php';
	}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every selected template escapes its values.
	echo Template::render( $template, $view );
	?>
</div>
