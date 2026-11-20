<?php
/**
 * Compact category directory row with optional topic disclosure.
 *
 * @package EhrmanBlogDiscovery
 */

use EhrmanBlogDiscovery\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$view        = $ehrman_blog_discovery_context;
$category    = $view['category'];
$area_names  = array_column( $category['subject_areas'], 'name' );
$topic_names = array_column( array_merge( $category['topics'], $category['protected_topics'] ), 'name' );
sort( $area_names, SORT_NATURAL | SORT_FLAG_CASE );
sort( $topic_names, SORT_NATURAL | SORT_FLAG_CASE );
?>
<td>
	<a class="ehrman-organization__category-name" href="<?php echo esc_url( $view['url'] ); ?>"><?php echo esc_html( $category['name'] ); ?></a>
	<span class="ehrman-organization__post-count"><?php /* translators: %s: Number of posts. */ echo esc_html( sprintf( __( '%s posts', 'ehrman-blog-discovery' ), number_format_i18n( Database::integer( $category['post_count'] ) ) ) ); ?></span>
</td>
<td class="ehrman-organization__category-areas">
	<span class="ehrman-organization__mobile-label" aria-hidden="true"><?php esc_html_e( 'Subject Areas', 'ehrman-blog-discovery' ); ?></span>
	<span><?php echo $area_names ? esc_html( implode( '; ', $area_names ) ) : esc_html__( 'None linked', 'ehrman-blog-discovery' ); ?></span>
</td>
<td class="ehrman-organization__category-topics">
	<span class="ehrman-organization__mobile-label" aria-hidden="true"><?php esc_html_e( 'Topics', 'ehrman-blog-discovery' ); ?></span>
	<?php if ( count( $topic_names ) > 3 ) : ?>
		<details class="ehrman-organization__topic-disclosure">
			<summary>
				<span class="ehrman-organization__topic-preview"><?php echo esc_html( implode( '; ', array_slice( $topic_names, 0, 3 ) ) ); ?></span>
				<span class="ehrman-organization__topic-toggle"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span><span class="ehrman-organization__show-all"><?php /* translators: %d: Total linked topics. */ echo esc_html( sprintf( __( 'Show all %d topics', 'ehrman-blog-discovery' ), count( $topic_names ) ) ); ?></span><span class="ehrman-organization__show-fewer"><?php esc_html_e( 'Show fewer', 'ehrman-blog-discovery' ); ?></span></span>
			</summary>
			<p class="ehrman-organization__topic-full-list"><?php echo esc_html( implode( '; ', $topic_names ) ); ?></p>
		</details>
	<?php else : ?>
		<span class="ehrman-organization__topic-preview"><?php echo $topic_names ? esc_html( implode( '; ', $topic_names ) ) : esc_html__( 'None linked', 'ehrman-blog-discovery' ); ?></span>
	<?php endif; ?>
</td>
