<?php
/**
 * Search-result pagination links.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$view = $ehrman_blog_discovery_context;
?>
<nav class="ebd-pagination" aria-label="<?php echo esc_attr__( 'Search results pages', 'ehrman-blog-discovery' ); ?>">
	<?php if ( null !== $view['previous'] ) : ?>
		<a class="ebd-pagination-link ebd-pagination-previous" href="<?php echo esc_url( $view['previous'] ); ?>"><?php echo esc_html__( 'Previous', 'ehrman-blog-discovery' ); ?></a>
	<?php else : ?>
		<span class="ebd-pagination-link ebd-pagination-previous is-disabled" aria-disabled="true"><?php echo esc_html__( 'Previous', 'ehrman-blog-discovery' ); ?></span>
	<?php endif; ?>
	<span class="ebd-pagination-pages">
		<?php foreach ( $view['numbers'] as $number ) : ?>
			<?php if ( $number['ellipsis'] ) : ?>
				<span class="ebd-pagination-ellipsis" aria-hidden="true">&hellip;</span>
			<?php endif; ?>
			<?php if ( $number['current'] ) : ?>
				<span class="ebd-pagination-link is-current" aria-current="page"><?php echo esc_html( (string) $number['number'] ); ?></span>
			<?php else : ?>
				<a class="ebd-pagination-link" href="<?php echo esc_url( $number['url'] ); ?>" aria-label="<?php echo esc_attr( $number['label'] ); ?>"><?php echo esc_html( (string) $number['number'] ); ?></a>
			<?php endif; ?>
		<?php endforeach; ?>
	</span>
	<span class="ebd-pagination-status"><?php echo esc_html( $view['status'] ); ?></span>
	<?php if ( null !== $view['next'] ) : ?>
		<a class="ebd-pagination-link ebd-pagination-next" href="<?php echo esc_url( $view['next'] ); ?>"><?php echo esc_html__( 'Next', 'ehrman-blog-discovery' ); ?></a>
	<?php else : ?>
		<span class="ebd-pagination-link ebd-pagination-next is-disabled" aria-disabled="true"><?php echo esc_html__( 'Next', 'ehrman-blog-discovery' ); ?></span>
	<?php endif; ?>
</nav>
