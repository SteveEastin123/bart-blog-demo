<?php
/**
 * Keyword-search category combobox.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$view = $ehrman_blog_discovery_context;
?>
<div class="ebd-category-filter">
	<div class="ebd-category-heading"><span class="ebd-category-label" id="<?php echo esc_attr( $view['id'] ); ?>-category-label"><?php echo esc_html__( 'Category', 'ehrman-blog-discovery' ); ?></span><span class="ebd-category-badge"><?php echo esc_html__( 'Recommended', 'ehrman-blog-discovery' ); ?></span></div>
	<p class="ebd-category-help" id="<?php echo esc_attr( $view['id'] ); ?>-category-help"><?php echo esc_html__( 'Choose a category to narrow your suggestions and improve your results.', 'ehrman-blog-discovery' ); ?></p>
	<div class="ebd-category-combobox" data-ebd-category-combobox>
		<input type="hidden" name="ebd_category" value="<?php echo esc_attr( $view['slug'] ); ?>" data-ebd-category>
		<button type="button" class="ebd-category-toggle" data-ebd-category-toggle aria-haspopup="listbox" aria-expanded="false" aria-labelledby="<?php echo esc_attr( $view['id'] ); ?>-category-label <?php echo esc_attr( $view['id'] ); ?>-category-name" aria-describedby="<?php echo esc_attr( $view['id'] ); ?>-category-help"><span id="<?php echo esc_attr( $view['id'] ); ?>-category-name" data-ebd-category-name><?php echo esc_html( $view['name'] ); ?></span><span class="ebd-category-count" data-ebd-category-count><?php echo esc_html( $view['count'] ); ?></span><span aria-hidden="true">&#9662;</span></button>
		<ul class="ebd-category-options" role="listbox" data-ebd-category-options hidden>
			<?php foreach ( $view['options'] as $option ) : ?>
				<li role="presentation"><button type="button" role="option" data-ebd-category-option value="<?php echo esc_attr( $option['slug'] ); ?>" data-label="<?php echo esc_attr( $option['name'] ); ?>" data-count="<?php echo esc_attr( $option['count'] ); ?>" aria-selected="<?php echo $option['selected'] ? 'true' : 'false'; ?>"><span><?php echo esc_html( $option['name'] ); ?></span><span><?php echo esc_html( $option['count'] ); ?></span></button></li>
			<?php endforeach; ?>
		</ul>
	</div>
</div>
