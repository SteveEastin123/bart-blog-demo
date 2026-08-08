<?php
/**
 * Uninstall behavior for the Ehrman Blog Discovery plugin.
 *
 * @package EhrmanBlogDiscovery
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Preserve imported search data by default. Any future destructive cleanup
// must be an explicit administrator choice rather than an uninstall side effect.
