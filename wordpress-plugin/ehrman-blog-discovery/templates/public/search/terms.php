<?php
/**
 * Selected terms and autocomplete slots.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$view = $ehrman_blog_discovery_context;
?>
<?php foreach ( $view['chips'] as $chip ) : ?>
	<span class="ebd-keyword-slot ebd-keyword-chip"><input type="hidden" name="ebd_keyword[]" value="<?php echo esc_attr( $chip['term'] ); ?>"><input type="hidden" name="ebd_term_mode[]" value="<?php echo esc_attr( $chip['type'] ); ?>"><span class="ebd-keyword-chip-content"><span class="ebd-keyword-chip-label"><?php echo esc_html( $chip['term'] ); ?></span><span class="ebd-selected-term-badge is-<?php echo esc_attr( $chip['type'] ); ?>" aria-label="<?php echo esc_attr( $chip['type_accessible_label'] ); ?>"><?php echo esc_html( $chip['type_label'] ); ?></span></span><button type="button" class="ebd-keyword-remove" data-ebd-remove aria-label="<?php echo esc_attr( $chip['remove_label'] ); ?>">&times;</button></span>
<?php endforeach; ?>
<div class="ebd-keyword-slot ebd-keyword-input-wrap"<?php echo $view['input_hidden'] ? ' hidden' : ''; ?>><input id="<?php echo esc_attr( $view['id'] ); ?>-input" class="ebd-keyword-input" type="text" placeholder="Keyword <?php echo esc_attr( (string) $view['input_number'] ); ?>" role="combobox" aria-label="Keyword <?php echo esc_attr( (string) $view['input_number'] ); ?>" autocomplete="off" aria-autocomplete="list" aria-expanded="false" aria-controls="<?php echo esc_attr( $view['id'] ); ?>-suggestions"<?php echo $view['input_hidden'] ? ' disabled' : ''; ?>><ul id="<?php echo esc_attr( $view['id'] ); ?>-suggestions" class="ebd-suggestions" role="listbox" hidden></ul></div>
<?php foreach ( $view['empty_slots'] as $number ) : ?>
	<span class="ebd-keyword-slot ebd-keyword-empty">Keyword <?php echo esc_html( (string) $number ); ?></span>
<?php endforeach; ?>
