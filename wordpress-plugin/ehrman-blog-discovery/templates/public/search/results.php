<?php
/**
 * Search summary, guidance, posts and navigation.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$view = $ehrman_blog_discovery_context;
// Keep inline spacing explicit so optional phrases do not add a space before the period.
echo '<p class="ebd-results-summary" aria-live="polite"><strong>' . esc_html( $view['summary_label'] ) . '</strong>';
if ( '' !== $view['context'] ) {
	echo ' ' . esc_html__( 'in', 'ehrman-blog-discovery' ) . ' <strong>' . esc_html( $view['context'] ) . '</strong>';
}
if ( $view['has_terms'] ) {
	echo ' ' . esc_html( $view['match_label'] ) . ' <strong>' . esc_html( $view['terms'] ) . '</strong>';
}
echo '.</p>';
?>
<?php if ( '' !== $view['guidance'] ) : ?>
	<p class="ebd-results-guidance"><?php echo esc_html( $view['guidance'] ); ?></p>
<?php endif; ?>
<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The post-list template escapes its display values.
echo $view['posts'];
?>
<?php if ( $view['back_to_top'] ) : ?>
	<p class="ebd-back-to-top"><button type="button" data-ebd-back-to-top><span aria-hidden="true">&uarr;</span> <?php echo esc_html__( 'Back to top', 'ehrman-blog-discovery' ); ?></button></p>
<?php endif; ?>
<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The pagination template escapes its display values.
echo $view['pagination'];
?>
