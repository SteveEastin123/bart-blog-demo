<?php
/** Isolated guard test: no WordPress/database/network needed. */
namespace EhrmanBlogDiscovery {
    class Search_Service {}
}
namespace EhrmanDiscoveryTests {
    define('ABSPATH', '/test/');
    function wp_get_environment_type(): string { return 'production'; }
    require dirname(__DIR__) . '/test-tools/src/Plugin.php';
    require dirname(__DIR__) . '/test-tools/src/Harness.php';
    // Parity_REST_Controller is intentionally absent: the production guard must return first.
    Plugin::register_routes();
    try {
        Harness::require_test_site();
    } catch (\RuntimeException $error) {
        if (str_contains($error->getMessage(), 'not production')) {
            echo "Production guards: OK\n";
            exit(0);
        }
    }
    throw new \RuntimeException('Developer CLI allowed production.');
}
