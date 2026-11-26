<?php
/**
 * Search-first controls and the local autocomplete directory.
 *
 * @package EhrmanBlogDiscovery
 */

use EhrmanBlogDiscovery\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$view         = $ehrman_blog_discovery_context;
$labels       = $view['labels'];
$url          = $view['url'];
$category_tab = $view['category_tab'];
$list_url     = $url();
?>
<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="ehrman-organization__search" role="search" data-ebd-topic-search data-match-count="<?php echo esc_attr( $labels['match_count'] ); ?>" data-empty="<?php echo esc_attr( $labels['empty'] ); ?>">
	<input type="hidden" name="page" value="ehrman-search-organization">
	<input type="hidden" name="tab" value="<?php echo esc_attr( $view['tab'] ); ?>">
	<label for="ebd-find-topic"><?php echo esc_html( $labels['search'] ); ?></label>
	<div class="ehrman-organization__search-controls">
		<input id="ebd-find-topic" name="s" type="search" autocomplete="off" aria-controls="ebd-topic-matches" value="<?php echo esc_attr( $view['query'] ); ?>">
		<button type="submit" class="button"><?php echo esc_html( $labels['search'] ); ?></button>
		<a class="button" href="<?php echo esc_url( $url( array( 'view' => 'all' ) ) ); ?>"><?php echo esc_html( $labels['browse'] ); ?></a>
		<a class="button button-primary" href="<?php echo esc_url( $url( array( 'action' => 'new' ) ) ); ?>"><?php echo esc_html( $labels['add'] ); ?></a>
		<?php if ( $view['show_list'] ) : ?>
			<a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Clear Search', 'ehrman-blog-discovery' ); ?></a>
		<?php endif; ?>
	</div>
	<div class="ehrman-organization__matches" data-ebd-topic-results hidden>
		<p role="status" aria-live="polite" data-ebd-topic-status></p>
		<ul id="ebd-topic-matches" aria-label="<?php echo esc_attr( $labels['matching'] ); ?>"></ul>
		<a data-ebd-topic-more hidden><?php esc_html_e( 'View All Matches', 'ehrman-blog-discovery' ); ?></a>
	</div>
	<template data-ebd-topic-directory>
		<?php foreach ( $view['directory'] as $row ) : ?>
			<li><a href="<?php echo esc_url( $url( array( 'edit' => Database::integer( $row['id'] ) ) ) ); ?>">
				<strong data-ebd-topic-name><?php echo esc_html( $row['name'] ); ?></strong>
				<span><?php echo esc_html( implode( ', ', array_column( $row[ $category_tab ? 'subject_areas' : 'categories' ], 'name' ) ) ); ?></span>
				<?php
				if ( $category_tab ) :
					?>
					<span><?php /* translators: %d: Number of linked topics. */ echo esc_html( sprintf( __( '%d topics', 'ehrman-blog-discovery' ), count( $row['topics'] ) + count( $row['protected_topics'] ) ) ); ?></span><?php endif; ?>
			</a></li>
		<?php endforeach; ?>
	</template>
</form>
