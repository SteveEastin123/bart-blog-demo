<?php
/**
 * Shared organization navigation.
 *
 * @package EhrmanBlogDiscovery
 */

use EhrmanBlogDiscovery\Topic_Organization_Page;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$active     = $ehrman_blog_discovery_context['tab'];
$navigation = array(
	'subject-areas' => __( 'Subject Areas', 'ehrman-blog-discovery' ),
	'categories'    => __( 'Categories', 'ehrman-blog-discovery' ),
	'topics'        => __( 'Topics', 'ehrman-blog-discovery' ),
	'export'        => __( 'Export', 'ehrman-blog-discovery' ),
);
?>
<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Topics & categories', 'ehrman-blog-discovery' ); ?>">
	<?php foreach ( $navigation as $key => $label ) : ?>
		<a class="nav-tab<?php echo $active === $key ? ' nav-tab-active' : ''; ?>" aria-current="<?php echo $active === $key ? 'page' : 'false'; ?>" href="<?php echo esc_url( Topic_Organization_Page::url( array( 'tab' => $key ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
	<?php endforeach; ?>
</nav>
