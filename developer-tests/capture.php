<?php
/**
 * Run with wp eval-file capture.php <cases.json> <output.json>.
 *
 * @package EhrmanDiscoveryTests
 */

namespace EhrmanDiscoveryTests;

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}
require_once __DIR__ . '/test-tools/src/Parity_Service.php';
require_once __DIR__ . '/test-tools/src/Harness.php';
/**
 * Arguments supplied by WP-CLI eval-file.
 *
 * @var list<string> $args
 */
Harness::capture( $args );
