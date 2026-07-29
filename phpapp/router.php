<?php

declare(strict_types=1);

$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
if (str_starts_with($path, '/static/')) {
    $staticRoot = realpath(__DIR__ . '/../webapp/static');
    $requested = realpath(__DIR__ . '/../webapp/static/' . substr($path, strlen('/static/')));
    if ($staticRoot !== false && $requested !== false && str_starts_with($requested, $staticRoot) && is_file($requested)) {
        $mime = match (strtolower(pathinfo($requested, PATHINFO_EXTENSION))) {
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };
        header('Content-Type: ' . $mime);
        readfile($requested);
        return true;
    }
    http_response_code(404);
    echo 'Not found';
    return true;
}

require __DIR__ . '/public/index.php';
return true;
