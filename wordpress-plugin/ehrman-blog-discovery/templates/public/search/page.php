<?php
/**
 * Keyword-search page content.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$view = $ehrman_blog_discovery_context;
?>
<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The search-panel template escapes its display values.
echo $view['panel'];
?>
<div id="ebd-results" class="ebd-results" data-ebd-results data-context="<?php echo esc_attr( $view['context'] ); ?>">
<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The results template escapes its display values.
echo $view['results'];
?>
</div>
