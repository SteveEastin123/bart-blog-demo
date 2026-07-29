<?php

declare(strict_types=1);

const EHRMAN_ROOT = __DIR__ . '/../..';
const EHRMAN_MAX_PARITY_CASES = 200;
const EHRMAN_MAX_SELECTED_TERMS = 4;

function ehrman_db_path(): string
{
    $configured = getenv('EHRMAN_DB_PATH');
    return $configured !== false && trim($configured) !== ''
        ? $configured
        : EHRMAN_ROOT . '/webapp/data/ehrman_search.db';
}

function ehrman_db(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) {
        return $connection;
    }

    $path = ehrman_db_path();
    if (!is_file($path)) {
        throw new RuntimeException("SQLite database not found: {$path}");
    }
    $connection = new PDO('sqlite:' . $path);
    $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $connection->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
    return $connection;
}

function ehrman_fetch_all(PDO $db, string $sql, array $params = []): array
{
    $statement = $db->prepare($sql);
    $statement->execute(array_values($params));
    return $statement->fetchAll();
}

function ehrman_fetch_one(PDO $db, string $sql, array $params = []): ?array
{
    $statement = $db->prepare($sql);
    $statement->execute(array_values($params));
    $row = $statement->fetch();
    return $row === false ? null : $row;
}

function ehrman_scalar(PDO $db, string $sql, array $params = []): mixed
{
    $statement = $db->prepare($sql);
    $statement->execute(array_values($params));
    return $statement->fetchColumn();
}

function ehrman_clean_string(mixed $value): string
{
    return $value === null ? '' : trim((string) $value);
}

function ehrman_normalize_keyword(mixed $value): string
{
    $text = strtolower(str_replace('&', ' and ', ehrman_clean_string($value)));
    $text = trim((string) preg_replace('/[^a-z0-9]+/', ' ', $text));
    return (string) preg_replace('/\s+/', ' ', $text);
}

function ehrman_unique_terms(?array $terms): array
{
    $values = [];
    $seen = [];
    foreach ($terms ?? [] as $term) {
        $value = ehrman_clean_string($term);
        $normalized = ehrman_normalize_keyword($value);
        if ($value === '' || $normalized === '' || isset($seen[$normalized])) {
            continue;
        }
        $seen[$normalized] = true;
        $values[] = $value;
    }
    return $values;
}

function ehrman_placeholders(int $count): string
{
    return implode(',', array_fill(0, $count, '?'));
}

function ehrman_intersect_scores(?array $scores, array $next): array
{
    if ($scores === null) {
        return $next;
    }
    $intersection = [];
    foreach ($scores as $postId => $score) {
        if (isset($next[$postId])) {
            $intersection[$postId] = $score + $next[$postId];
        }
    }
    return $intersection;
}

function ehrman_json_response(array $payload, int $status = 200, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    foreach ($headers as $header) {
        header($header);
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}
