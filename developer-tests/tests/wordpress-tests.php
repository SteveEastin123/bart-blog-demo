<?php
/** Test the extracted service and optional endpoint inside staging WordPress. */

use EhrmanBlogDiscovery\Database;
use EhrmanDiscoveryTests\Harness;
use EhrmanDiscoveryTests\Parity_Service;
use EhrmanDiscoveryTests\Plugin;

if (!defined('WP_CLI') || !WP_CLI) { exit(1); }
$check = static function (bool $value, string $message): void {
    if (!$value) { throw new RuntimeException($message); }
};
$check(!class_exists('EhrmanBlogDiscovery\\Parity_Service'), 'Production still includes the parity service.');
$check(!class_exists('EhrmanBlogDiscovery\\Parity_REST_Controller'), 'Production still includes the parity controller.');
$route = '/ehrman-discovery/v1/parity/batch';
$original_server = clone rest_get_server();
$before = Database::counts();
$old_token = getenv('EHRMAN_DISCOVERY_PARITY_TOKEN');
$old_error = Database::client()->last_error;
$http_calls = 0;
$block_http = static function () use (&$http_calls) {
    ++$http_calls;
    throw new RuntimeException('Comparison must not call an external API.');
};
add_filter('pre_http_request', $block_http);
try {
    putenv('EHRMAN_DISCOVERY_PARITY_TOKEN=developer-test-fixture');
    $check(rest_do_request(new WP_REST_Request('POST', $route))->get_status() === 404, 'Core registered the test route with a token set.');
    require_once dirname(__DIR__) . '/test-tools/ehrman-discovery-test-tools.php';
    require_once dirname(__DIR__) . '/test-tools/src/Harness.php';
    Harness::require_test_site();
    putenv('EHRMAN_DISCOVERY_PARITY_TOKEN=');
    Plugin::register_routes();
    $check(rest_do_request(new WP_REST_Request('POST', $route))->get_status() === 404, 'Tokenless add-on exposed a route.');
    putenv('EHRMAN_DISCOVERY_PARITY_TOKEN=developer-test-fixture');
    Plugin::register_routes();
    $call = static function ($payload, ?string $token = 'developer-test-fixture') use ($route) {
        $request = new WP_REST_Request('POST', $route);
        $request->set_header('Content-Type', 'application/json');
        if ($token !== null) { $request->set_header('X-Ehrman-Parity-Token', $token); }
        $request->set_body(json_encode($payload, JSON_THROW_ON_ERROR));
        return rest_do_request($request);
    };
    $cases = json_decode(file_get_contents(dirname(__DIR__) . '/cases/search.json'), true, 512, JSON_THROW_ON_ERROR);
    $payload = ['schemaVersion' => 1, 'cases' => $cases];
    $check($call($payload, null)->get_status() === 403, 'Missing token was accepted.');
    $check($call($payload, 'incorrect')->get_status() === 403, 'Incorrect token was accepted.');
    $valid = $call($payload);
    $check($valid->get_status() === 200, 'Valid endpoint request failed.');
    $check($valid->get_headers()['Cache-Control'] === 'no-store', 'Test response is cacheable.');
    $check($valid->get_data() === (new Parity_Service())->run_batch($cases), 'REST and CLI/service results differ.');
    foreach ($valid->get_data()['results'] as $result) { $check($result['ok'] === true, 'A comparison case failed.'); }
    foreach ([['cases' => []], ['cases' => array_fill(0, 201, $cases[0])], ['schemaVersion' => 9, 'cases' => $cases]] as $bad) {
        $check($call($bad)->get_status() === 400, 'Malformed request was accepted.');
    }
    $malformed = (new Parity_Service())->run_batch([['id' => 'invalid', 'operation' => 'other']]);
    $check($malformed['results'][0]['ok'] === false, 'Invalid operation was not reported.');
    remove_action('rest_api_init', [Plugin::class, 'register_routes']);
    $GLOBALS['wp_rest_server'] = $original_server;
    $check(rest_do_request(new WP_REST_Request('POST', $route))->get_status() === 404, 'Route was not removed.');
    $check(Database::counts() === $before, 'Comparison changed database counts.');
    $check(Database::client()->last_error === $old_error, 'Comparison caused a database error.');
    $check($http_calls === 0, 'Comparison attempted HTTP.');
} finally {
    remove_action('rest_api_init', [Plugin::class, 'register_routes']);
    $GLOBALS['wp_rest_server'] = $original_server;
    remove_filter('pre_http_request', $block_http);
    putenv($old_token === false ? 'EHRMAN_DISCOVERY_PARITY_TOKEN' : 'EHRMAN_DISCOVERY_PARITY_TOKEN=' . $old_token);
}
echo "Developer comparison: production isolation, token checks, invalid requests, no API calls, data unchanged: OK\n";
