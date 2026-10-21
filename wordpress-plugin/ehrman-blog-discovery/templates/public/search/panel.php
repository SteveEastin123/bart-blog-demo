<?php
/**
 * Search controls shared by Keyword Search and Browse Topics.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$view = $ehrman_blog_discovery_context;
?>
<form class="ebd-search-panel" action="<?php echo esc_url( $view['action'] ); ?>" method="get" data-ebd-search-form data-category="<?php echo esc_attr( $view['category'] ); ?>" data-topic="<?php echo esc_attr( $view['topic'] ); ?>" data-ebd-initial-collapse="<?php echo $view['has_search_state'] ? 'true' : 'false'; ?>">
	<div class="ebd-search-compact" data-ebd-search-compact hidden>
		<p class="ebd-search-compact-summary"><strong><?php echo esc_html__( 'Current search:', 'ehrman-blog-discovery' ); ?></strong> <span data-ebd-search-summary></span></p>
		<button type="button" class="ebd-search-edit" data-ebd-search-edit aria-controls="<?php echo esc_attr( $view['id'] ); ?>-controls"><?php echo esc_html__( 'Edit search', 'ehrman-blog-discovery' ); ?></button>
	</div>
	<div id="<?php echo esc_attr( $view['id'] ); ?>-controls" class="ebd-search-controls" data-ebd-search-expanded>
		<?php if ( null !== $view['scope'] ) : ?>
			<div class="ebd-fixed-scope"><strong><?php echo esc_html__( 'Category:', 'ehrman-blog-discovery' ); ?></strong> <?php echo esc_html( $view['scope'] ); ?></div>
		<?php endif; ?>
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The category-filter template escapes its display values.
		echo $view['category_filter'];
		?>
		<p class="ebd-search-instructions"><strong><?php echo esc_html__( 'Select up to four search terms.', 'ehrman-blog-discovery' ); ?></strong> <?php echo esc_html__( 'You can enter topics, keywords, or both. Topics identify a post\'s main subjects, while keywords identify important people, texts, places, and related ideas. Combine multiple terms to narrow your results.', 'ehrman-blog-discovery' ); ?></p>
		<div class="ebd-sort-row ebd-suggestion-order-row" role="radiogroup" aria-label="<?php echo esc_attr__( 'Order autocomplete suggestions', 'ehrman-blog-discovery' ); ?>">
			<span><?php echo esc_html__( 'Order suggestions', 'ehrman-blog-discovery' ); ?></span>
			<?php
			foreach ( array(
				'popular'        => 'Most posts',
				'topics-first'   => 'Topics first',
				'keywords-first' => 'Keywords first',
			) as $value => $label ) :
				?>
				<label class="ebd-sort-choice"><input type="radio" name="ebd_suggestion_order" value="<?php echo esc_attr( $value ); ?>"<?php checked( 'popular', $value ); ?>><span><?php echo esc_html( $label ); ?></span></label>
			<?php endforeach; ?>
		</div>
		<div class="ebd-keyword-grid" data-ebd-chip-list>
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The terms template escapes its display values.
		echo Template::render( 'public/search/terms.php', $view['terms'] );
		?>
		</div>
		<div class="ebd-sort-row ebd-post-sort-row">
			<span><?php echo esc_html__( 'Sort by', 'ehrman-blog-discovery' ); ?></span>
			<?php
			foreach ( array(
				'ranked' => 'Best match',
				'newest' => 'Newest first',
				'oldest' => 'Oldest first',
			) as $value => $label ) :
				?>
				<label class="ebd-sort-choice"><input type="radio" name="ebd_sort" value="<?php echo esc_attr( $value ); ?>"<?php checked( $view['sort'], $value ); ?>><span><?php echo esc_html( $label ); ?></span></label>
			<?php endforeach; ?>
		</div>
		<div class="ebd-search-actions">
			<button type="button" class="ebd-clear" data-ebd-clear><?php echo esc_html__( 'Clear all', 'ehrman-blog-discovery' ); ?></button>
			<button type="button" class="ebd-search-collapse" data-ebd-search-collapse aria-controls="<?php echo esc_attr( $view['id'] ); ?>-controls"<?php echo $view['has_search_state'] ? '' : ' hidden'; ?>><?php echo esc_html__( 'Hide search controls', 'ehrman-blog-discovery' ); ?></button>
		</div>
	</div>
</form>
<div class="ebd-description-control">
<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Discovery_Markup escapes the description-control values.
echo $view['description_control'];
?>
</div>
