<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/ParityService.php';
require_once __DIR__ . '/../src/ViewService.php';

function phpapp_error(string $message, int $status): never
{
    ehrman_json_response(['error' => $message], $status, ['Cache-Control: no-store']);
}

function phpapp_parity_endpoint(): never
{
    $configuredToken = getenv('EHRMAN_PARITY_TEST_TOKEN') ?: '';
    if ($configuredToken === '') {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not found';
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        phpapp_error('POST required', 405);
    }
    $providedToken = (string) ($_SERVER['HTTP_X_EHRMAN_PARITY_TOKEN'] ?? '');
    if ($providedToken === '' || !hash_equals($configuredToken, $providedToken)) {
        phpapp_error('Forbidden', 403);
    }
    $contentLength = filter_var(
        $_SERVER['CONTENT_LENGTH'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]],
    );
    if ($contentLength === false || $contentLength === null) {
        phpapp_error('JSON request body required', 400);
    }
    if ($contentLength > 2_000_000) {
        phpapp_error('Request body too large', 413);
    }
    $body = file_get_contents('php://input');
    if ($body === false || $body === '') {
        phpapp_error('Request body unavailable', 400);
    }
    try {
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        phpapp_error('Invalid JSON request body', 400);
    }
    if (!is_array($payload) || array_is_list($payload)) {
        phpapp_error('JSON request body must be an object', 400);
    }
    if (isset($payload['schemaVersion']) && $payload['schemaVersion'] !== 1) {
        phpapp_error('Unsupported schemaVersion', 400);
    }
    try {
        $response = ehrman_run_batch($payload['cases'] ?? null);
    } catch (InvalidArgumentException $error) {
        phpapp_error($error->getMessage(), 400);
    }
    ehrman_json_response($response, 200, ['Cache-Control: no-store']);
}

function phpapp_health_endpoint(): never
{
    $db = ehrman_db();
    $counts = ehrman_database_counts($db);
    $staticDirectory = EHRMAN_ROOT . '/webapp/static';
    $staticFiles = array_values(array_filter(
        scandir($staticDirectory) ?: [],
        static fn(string $name): bool => $name !== '.' && $name !== '..' && is_file($staticDirectory . '/' . $name),
    ));
    sort($staticFiles, SORT_NATURAL | SORT_FLAG_CASE);
    ehrman_json_response([
        'status' => 'ok',
        'commit' => getenv('RENDER_GIT_COMMIT') ?: '',
        'databaseExists' => is_file(ehrman_db_path()),
        'staticFiles' => $staticFiles,
        'counts' => [
            'posts' => $counts['posts'],
            'subjectAreas' => $counts['subjectAreas1'],
            'subjectAreas2' => $counts['subjectAreas2'],
            'categories' => $counts['categories'],
            'topics' => $counts['topics'],
            'keywords' => $counts['secondaryKeywords'],
        ],
    ]);
}

function phpapp_keywords_endpoint(): never
{
    $query = ehrman_query_params();
    ehrman_json_response(ehrman_keyword_suggestions(
        ehrman_query_first($query, 'q'),
        array_map('strval', $query['selected'] ?? []),
        ehrman_query_first($query, 'category'),
        ehrman_query_first($query, 'topic'),
    ));
}

function phpapp_html_response(string $html, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

function phpapp_page(string $path): never
{
    $query = ehrman_query_params();
    $page = null;
    if ($path === '/' || $path === '') {
        $page = ehrman_home_page();
    } elseif (in_array($path, ['/subject-areas', '/browse-by-topic', '/browse-topics-1'], true)) {
        $page = ehrman_subject_areas_page(1);
    } elseif ($path === '/browse-topics-2') {
        $page = ehrman_subject_areas_page(2);
    } elseif ($path === '/keyword-search') {
        $page = ehrman_keyword_search_page();
    } elseif ($path === '/keyword-results') {
        $page = ehrman_keyword_results_page($query);
    } elseif (preg_match('#^/categories/([^/]+)/posts$#', $path, $matches) === 1) {
        $page = ehrman_category_posts_page($matches[1], $query);
    } elseif (preg_match('#^/(?:subject-areas|browse-by-topic|browse-topics-1)/([^/]+)$#', $path, $matches) === 1) {
        $page = ehrman_subject_area_page($matches[1], 1);
    } elseif (preg_match('#^/browse-topics-2/([^/]+)$#', $path, $matches) === 1) {
        $page = ehrman_subject_area_page($matches[1], 2);
    } elseif (preg_match('#^/categories/([^/]+)$#', $path, $matches) === 1) {
        $page = ehrman_category_page($matches[1], $query);
    } elseif (preg_match('#^/topics/([^/]+)$#', $path, $matches) === 1) {
        $page = ehrman_topic_posts_page($matches[1], $query);
    }
    phpapp_html_response($page ?? ehrman_not_found_page(), $page === null ? 404 : 200);
}

$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
try {
    if ($path === '/api/parity/batch') {
        phpapp_parity_endpoint();
    }
    if ($path === '/api/keywords') {
        phpapp_keywords_endpoint();
    }
    if ($path === '/healthz') {
        phpapp_health_endpoint();
    }
    phpapp_page($path);
} catch (Throwable $error) {
    if (str_starts_with($path, '/api/') || $path === '/healthz') {
        error_log($error->__toString());
        phpapp_error('Internal server error', 500);
    }
    error_log($error->__toString());
    phpapp_html_response(
        ehrman_render_page(
            'Internal Server Error',
            ehrman_content_page('Internal Server Error', 'The requested page could not be loaded.'),
        ),
        500,
    );
}
