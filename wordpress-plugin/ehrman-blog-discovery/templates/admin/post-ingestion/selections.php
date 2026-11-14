<?php
/**
 * Removable AI-selected terms with undo, never a vocabulary picker.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$view = $ehrman_blog_discovery_context;
?>
<div class="ehrman-metadata-selections" data-ebd-selections="<?php echo esc_attr( $view['kind'] ); ?>">
	<ul class="ehrman-metadata-selections__list" data-ebd-selected>
		<?php foreach ( $view['labels'] as $index => $label ) : ?>
			<?php
			/* translators: %s: AI-selected topic or keyword. */
			$remove_label = 'topic' === $view['kind'] ? sprintf( __( 'Remove topic: %s', 'ehrman-blog-discovery' ), $label ) : sprintf( __( 'Remove keyword: %s', 'ehrman-blog-discovery' ), $label );
			/* translators: %s: removed topic or keyword. */
			$restore_label = 'topic' === $view['kind'] ? sprintf( __( 'Restore topic: %s', 'ehrman-blog-discovery' ), $label ) : sprintf( __( 'Restore keyword: %s', 'ehrman-blog-discovery' ), $label );
			?>
			<li class="ehrman-metadata-selections__term" data-ebd-order="<?php echo esc_attr( (string) $index ); ?>">
				<span class="ehrman-metadata-selections__label"><?php echo esc_html( $label ); ?></span>
				<input type="hidden" name="<?php echo esc_attr( $view['field'] ); ?>[]" value="<?php echo esc_attr( $label ); ?>" disabled>
				<button type="button" class="ehrman-metadata-selections__button" data-ebd-term-toggle data-remove-label="<?php echo esc_attr( $remove_label ); ?>" data-restore-label="<?php echo esc_attr( $restore_label ); ?>" aria-label="<?php echo esc_attr( $remove_label ); ?>" title="<?php echo esc_attr( $remove_label ); ?>" hidden><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
			</li>
		<?php endforeach; ?>
	</ul>
	<div data-ebd-removed hidden>
		<span><?php echo esc_html__( 'Removed', 'ehrman-blog-discovery' ); ?></span>
		<ul class="ehrman-metadata-selections__list" data-ebd-removed-list></ul>
	</div>
</div>
