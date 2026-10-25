<?php
/**
 * Staging-only entry point for the delivered production regression suite.
 *
 * @package EhrmanDiscoveryTests
 */

namespace EhrmanDiscoveryTests;

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}
require_once __DIR__ . '/test-tools/src/Harness.php';
Harness::require_test_site();
// The release builder supplies this directory; the checkout uses the maintained originals.
require is_file( __DIR__ . '/regressions/plugin-regression-tests.php' )
	? __DIR__ . '/regressions/plugin-regression-tests.php'
	: dirname( __DIR__ ) . '/wordpress-production/tests/plugin-regression-tests.php';
