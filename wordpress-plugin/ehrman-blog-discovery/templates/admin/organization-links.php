<?php
/**
 * One existing-vocabulary relationship picker, with a native fallback.
 *
 * @package EhrmanBlogDiscovery
 */

use EhrmanBlogDiscovery\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$group  = $ehrman_blog_discovery_context;
$labels = match ( $group['kind'] ) {
	'subject-area' => array(
		'id'       => 'ebd-category-areas',
		'title'    => __( 'Subject Areas', 'ehrman-blog-discovery' ),
		'add'      => __( 'Add subject area', 'ehrman-blog-discovery' ),
		'selected' => __( 'Selected subject areas', 'ehrman-blog-discovery' ),
		'search'   => __( 'Search existing subject areas', 'ehrman-blog-discovery' ),
		/* translators: %s: Subject-area name. */
		'remove'   => __( 'Remove subject area: %s', 'ehrman-blog-discovery' ),
		'empty'    => __( 'No subject areas available to add.', 'ehrman-blog-discovery' ),
		'warning'  => __( 'Select at least one subject area.', 'ehrman-blog-discovery' ),
	),
	'topic' => array(
		'id'       => 'ebd-category-topics',
		'title'    => __( 'Topics', 'ehrman-blog-discovery' ),
		'add'      => __( 'Add topic', 'ehrman-blog-discovery' ),
		'selected' => __( 'Selected topics', 'ehrman-blog-discovery' ),
		'search'   => __( 'Search existing topics', 'ehrman-blog-discovery' ),
		/* translators: %s: Topic name. */
		'remove'   => __( 'Remove topic: %s', 'ehrman-blog-discovery' ),
		'empty'    => __( 'No topics available to add.', 'ehrman-blog-discovery' ),
		'warning'  => __( 'Select at least one topic.', 'ehrman-blog-discovery' ),
	),
	default => array(
		'id'       => 'ebd-topic-categories',
		'title'    => __( 'Categories', 'ehrman-blog-discovery' ),
		'add'      => __( 'Add category', 'ehrman-blog-discovery' ),
		'selected' => __( 'Selected categories', 'ehrman-blog-discovery' ),
		'search'   => __( 'Search existing categories', 'ehrman-blog-discovery' ),
		/* translators: %s: Category name. */
		'remove'   => __( 'Remove category: %s', 'ehrman-blog-discovery' ),
		'empty'    => __( 'No categories available to add.', 'ehrman-blog-discovery' ),
		'warning'  => __( 'Select at least one category.', 'ehrman-blog-discovery' ),
	),
};
$warning_id = 'category' === $group['kind'] ? 'ebd-category-warning' : $labels['id'] . '-warning';
?>
<fieldset class="ehrman-organization__link-group" data-ebd-link-group data-selected="<?php echo esc_attr( $labels['selected'] ); ?>" data-add="<?php echo esc_attr( $labels['add'] ); ?>" data-search="<?php echo esc_attr( $labels['search'] ); ?>" data-remove="<?php echo esc_attr( $labels['remove'] ); ?>" data-empty="<?php echo esc_attr( $labels['empty'] ); ?>">
	<legend><?php echo esc_html( $labels['title'] ); ?></legend>
	<select id="<?php echo esc_attr( $labels['id'] ); ?>" name="<?php echo esc_attr( $group['name'] ); ?>[]" multiple size="8" required aria-label="<?php echo esc_attr( $labels['title'] ); ?>" aria-describedby="<?php echo esc_attr( $warning_id ); ?>">
		<?php foreach ( $group['options'] as $option ) : ?>
			<option value="<?php echo esc_attr( $option['id'] ); ?>" <?php selected( in_array( Database::integer( $option['id'] ), $group['selected'], true ) ); ?>><?php echo esc_html( $option['name'] ); ?></option>
		<?php endforeach; ?>
	</select>
	<div data-ebd-category-picker></div>
	<p id="<?php echo esc_attr( $warning_id ); ?>" class="ehrman-organization__warning" data-ebd-link-warning role="status" hidden><?php echo esc_html( $labels['warning'] ); ?></p>
</fieldset>
