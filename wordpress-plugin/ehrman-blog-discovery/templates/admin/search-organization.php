<?php
/**
 * Topic organization list and forms.
 *
 * @package EhrmanBlogDiscovery
 */

use EhrmanBlogDiscovery\Database;
use EhrmanBlogDiscovery\Topic_Organization_Page;
use EhrmanBlogDiscovery\Topic_Organization_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$view     = $ehrman_blog_discovery_context;
$topic    = $view['topic'];
$values   = $view['values'];
$is_form  = $view['creating'] || null !== $topic;
$topic_id = Database::integer( $topic['id'] ?? null );
$list_url = Topic_Organization_Page::url();
?>
<div class="wrap ehrman-organization">
	<h1><?php esc_html_e( 'Search Organization', 'ehrman-blog-discovery' ); ?></h1>
	<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Search organization', 'ehrman-blog-discovery' ); ?>">
		<a class="nav-tab nav-tab-active" aria-current="page" href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Topics', 'ehrman-blog-discovery' ); ?></a>
	</nav>
	<?php if ( '' !== $view['error'] ) : ?>
		<div class="notice notice-error" role="alert"><p><?php echo esc_html( $view['error'] ); ?></p></div>
	<?php elseif ( in_array( $view['saved'], array( 'created', 'categories' ), true ) ) : ?>
		<div class="notice notice-success" role="status"><p><?php echo 'created' === $view['saved'] ? esc_html__( 'Topic created.', 'ehrman-blog-discovery' ) : esc_html__( 'Category links saved.', 'ehrman-blog-discovery' ); ?></p></div>
	<?php endif; ?>
	<?php if ( $is_form ) : ?>
		<h2><?php echo $view['creating'] ? esc_html__( 'Add Topic', 'ehrman-blog-discovery' ) : esc_html__( 'Edit Category Links', 'ehrman-blog-discovery' ); ?></h2>
		<form method="post" action="<?php echo esc_url( Topic_Organization_Page::url( $view['creating'] ? array( 'action' => 'new' ) : array( 'edit' => $topic_id ) ) ); ?>" class="ehrman-organization__form" data-ebd-topic-form>
			<?php wp_nonce_field( 'ehrman_topic_organization' ); ?>
			<input type="hidden" name="organization_action" value="<?php echo $view['creating'] ? 'create' : 'categories'; ?>">
			<input type="hidden" name="topic_id" value="<?php echo esc_attr( (string) $topic_id ); ?>">
			<input type="hidden" name="revision" value="<?php echo esc_attr( $values['revision'] ); ?>">
			<?php if ( $view['creating'] ) : ?>
				<p><label for="ebd-topic-name"><?php esc_html_e( 'Topic name', 'ehrman-blog-discovery' ); ?></label><input id="ebd-topic-name" name="name" type="text" maxlength="191" required value="<?php echo esc_attr( $values['name'] ); ?>"></p>
				<p><label for="ebd-topic-description"><?php esc_html_e( 'Description', 'ehrman-blog-discovery' ); ?></label><textarea id="ebd-topic-description" name="description" rows="4" maxlength="4000" required><?php echo esc_textarea( $values['description'] ); ?></textarea></p>
			<?php else : ?>
				<dl class="ehrman-organization__definition">
					<dt><?php esc_html_e( 'Topic', 'ehrman-blog-discovery' ); ?></dt><dd><?php echo esc_html( $topic['name'] ); ?></dd>
					<dt><?php esc_html_e( 'Description', 'ehrman-blog-discovery' ); ?></dt><dd><?php echo esc_html( $topic['description'] ); ?></dd>
				</dl>
			<?php endif; ?>
			<p><label for="ebd-topic-categories"><?php esc_html_e( 'Categories', 'ehrman-blog-discovery' ); ?></label></p>
			<select id="ebd-topic-categories" name="category_ids[]" multiple size="8" required aria-describedby="ebd-category-warning">
				<?php foreach ( $view['categories'] as $category ) : ?>
					<option value="<?php echo esc_attr( $category['id'] ); ?>" <?php selected( in_array( Database::integer( $category['id'] ), $values['category_ids'], true ) ); ?>><?php echo esc_html( $category['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<div data-ebd-category-picker></div>
			<p id="ebd-category-warning" class="ehrman-organization__warning" role="status" hidden><?php esc_html_e( 'Select at least one category.', 'ehrman-blog-discovery' ); ?></p>
			<p class="submit ehrman-organization__actions">
				<button type="submit" class="button button-primary"><?php echo $view['creating'] ? esc_html__( 'Create Topic', 'ehrman-blog-discovery' ) : esc_html__( 'Save Category Links', 'ehrman-blog-discovery' ); ?></button>
				<a href="<?php echo esc_url( $list_url ); ?>" class="button"><?php esc_html_e( 'Back to Topics', 'ehrman-blog-discovery' ); ?></a>
			</p>
		</form>
	<?php else : ?>
		<form method="get" action="<?php echo esc_url( admin_url( 'tools.php' ) ); ?>" class="ehrman-organization__search" role="search" data-ebd-topic-search>
			<input type="hidden" name="page" value="ehrman-search-organization">
			<label for="ebd-find-topic"><?php esc_html_e( 'Search topics', 'ehrman-blog-discovery' ); ?></label>
			<div class="ehrman-organization__search-controls">
				<input id="ebd-find-topic" name="s" type="search" autocomplete="off" aria-controls="ebd-topic-matches" value="<?php echo esc_attr( $view['query'] ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Search Topics', 'ehrman-blog-discovery' ); ?></button>
				<a class="button button-primary" href="<?php echo esc_url( Topic_Organization_Page::url( array( 'action' => 'new' ) ) ); ?>"><?php esc_html_e( 'Add Topic', 'ehrman-blog-discovery' ); ?></a>
				<a class="button" href="<?php echo esc_url( Topic_Organization_Page::url( array( 'view' => 'all' ) ) ); ?>"><?php esc_html_e( 'Browse All Topics', 'ehrman-blog-discovery' ); ?></a>
				<?php if ( $view['show_list'] ) : ?>
					<a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Clear Search', 'ehrman-blog-discovery' ); ?></a>
				<?php endif; ?>
			</div>
			<div class="ehrman-organization__matches" data-ebd-topic-results hidden>
				<p role="status" aria-live="polite" data-ebd-topic-status></p>
				<ul id="ebd-topic-matches" aria-label="<?php esc_attr_e( 'Matching topics', 'ehrman-blog-discovery' ); ?>"></ul>
				<a data-ebd-topic-more hidden><?php esc_html_e( 'View All Matches', 'ehrman-blog-discovery' ); ?></a>
			</div>
			<template data-ebd-topic-directory>
				<?php foreach ( $view['directory'] as $row ) : ?>
					<li><a href="<?php echo esc_url( Topic_Organization_Page::url( array( 'edit' => Database::integer( $row['id'] ) ) ) ); ?>">
						<strong data-ebd-topic-name><?php echo esc_html( $row['name'] ); ?></strong>
						<span><?php echo esc_html( implode( ', ', array_column( $row['categories'], 'name' ) ) ); ?></span>
					</a></li>
				<?php endforeach; ?>
			</template>
		</form>
		<?php if ( $view['show_list'] ) : ?>
		<p><?php /* translators: %s: Number of matching topics. */ echo esc_html( sprintf( __( '%s topics', 'ehrman-blog-discovery' ), number_format_i18n( $view['count'] ) ) ); ?></p>
		<table class="widefat striped ehrman-organization__topics">
			<thead><tr><th scope="col"><?php esc_html_e( 'Topic', 'ehrman-blog-discovery' ); ?></th><th scope="col"><?php esc_html_e( 'Categories', 'ehrman-blog-discovery' ); ?></th><th scope="col"><?php esc_html_e( 'Posts', 'ehrman-blog-discovery' ); ?></th><th scope="col"><?php esc_html_e( 'Action', 'ehrman-blog-discovery' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $view['topics'] as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['name'] ); ?></td>
						<td><?php echo esc_html( implode( ', ', array_column( $row['categories'], 'name' ) ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( Database::integer( $row['post_count'] ) ) ); ?></td>
						<td>
						<?php if ( Topic_Organization_Service::editable( $row ) ) : ?>
							<a href="<?php echo esc_url( Topic_Organization_Page::url( array( 'edit' => Database::integer( $row['id'] ) ) ) ); ?>"><?php esc_html_e( 'Edit Category Links', 'ehrman-blog-discovery' ); ?></a>
						<?php else : ?>
							<?php esc_html_e( 'Protected', 'ehrman-blog-discovery' ); ?>
						<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php if ( empty( $view['topics'] ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No topics found.', 'ehrman-blog-discovery' ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<nav class="ehrman-organization__pagination" aria-label="<?php esc_attr_e( 'Topic pages', 'ehrman-blog-discovery' ); ?>">
			<?php
			if ( $view['page_number'] > 1 ) :
				?>
				<a class="button" href="
				<?php
				echo esc_url(
					Topic_Organization_Page::url(
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
					Topic_Organization_Page::url(
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
	<?php endif; ?>
</div>
