<?php
/**
 * Reusable call-source display for question and review rows.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$source = $ehrman_blog_discovery_context;
if ( $source['cached'] ) {
	echo esc_html__( 'WordPress cache', 'ehrman-blog-discovery' );
	return;
}
?>
<strong><?php echo esc_html( $source['model'] ); ?></strong>
<?php if ( '' !== $source['pricing'] ) : ?>
	<br><small><?php echo esc_html( sprintf( /* translators: %s: pricing version date. */ __( 'Pricing: %s', 'ehrman-blog-discovery' ), $source['pricing'] ) ); ?></small>
<?php endif; ?>
<?php if ( '' !== $source['response_id'] ) : ?>
	<br><code title="<?php echo esc_attr( $source['response_id'] ); ?>"><?php echo esc_html( $source['short_id'] ); ?></code>
<?php endif; ?>
