<?php

declare(strict_types=1);

require_once __DIR__ . '/SearchService.php';

function ehrman_source_fingerprints(): array
{
    $relativePaths = [
        'data/index/ehrman_post_search_index.json',
        'data/index/ehrman_post_topics.json',
        'data/index/ehrman_post_categories.json',
        'data/index/ehrman_post_subject_areas.json',
        'data/index/ehrman_post_subject_areas_2.json',
    ];
    $combined = hash_init('sha256');
    $files = [];
    foreach ($relativePaths as $relativePath) {
        $path = EHRMAN_ROOT . '/' . $relativePath;
        $digest = hash_file('sha256', $path);
        if ($digest === false) {
            throw new RuntimeException("Unable to hash {$path}");
        }
        hash_update($combined, $relativePath);
        hash_update($combined, "\0");
        hash_update($combined, $digest);
        hash_update($combined, "\0");
        $files[] = [
            'path' => $relativePath,
            'sha256' => $digest,
            'bytes' => filesize($path),
        ];
    }
    return ['sha256' => hash_final($combined), 'files' => $files];
}

function ehrman_database_counts(PDO $db): array
{
    $queries = [
        'posts' => 'SELECT COUNT(*) FROM posts',
        'subjectAreas1' => 'SELECT COUNT(*) FROM subject_areas',
        'subjectAreas2' => 'SELECT COUNT(*) FROM subject_areas_2',
        'categories' => 'SELECT COUNT(*) FROM categories',
        'topics' => 'SELECT COUNT(*) FROM topics',
        'secondaryKeywords' => 'SELECT COUNT(*) FROM keywords',
        'searchTerms' => 'SELECT COUNT(*) FROM post_search_terms',
    ];
    $counts = [];
    foreach ($queries as $name => $sql) {
        $counts[$name] = (int) ehrman_scalar($db, $sql);
    }
    return $counts;
}

function ehrman_parity_manifest(): array
{
    $db = ehrman_db();
    return [
        'schemaVersion' => 1,
        'implementation' => 'php',
        'commit' => getenv('RENDER_GIT_COMMIT') ?: '',
        'dataFingerprint' => ehrman_source_fingerprints(),
        'runtime' => [
            'php' => PHP_VERSION,
            'sqlite' => (string) ehrman_scalar($db, 'SELECT sqlite_version()'),
        ],
        'counts' => ehrman_database_counts($db),
    ];
}

function ehrman_case_string_list(mixed $value, string $field, ?int $maximum = null): array
{
    if ($value === null) {
        return [];
    }
    if (!is_array($value) || !array_is_list($value)) {
        throw new InvalidArgumentException("{$field} must be a list of strings");
    }
    foreach ($value as $item) {
        if (!is_string($item)) {
            throw new InvalidArgumentException("{$field} must be a list of strings");
        }
    }
    $values = ehrman_unique_terms($value);
    if ($maximum !== null && count($values) > $maximum) {
        throw new InvalidArgumentException("{$field} supports at most {$maximum} unique values");
    }
    return $values;
}

function ehrman_normalized_sort(mixed $value): string
{
    $sort = ehrman_clean_string($value);
    return in_array($sort, ['ranked', 'newest', 'oldest'], true) ? $sort : 'ranked';
}

function ehrman_serialize_posts(array $posts): array
{
    $serialized = [];
    foreach ($posts as $index => $post) {
        $serialized[] = [
            'position' => $index + 1,
            'url' => (string) $post['url'],
            'wpId' => $post['wp_id'] ?: '',
            'title' => (string) $post['title'],
            'dateIso' => (string) $post['date_iso'],
        ];
    }
    return $serialized;
}

function ehrman_search_case(array $case): array
{
    $terms = ehrman_case_string_list($case['terms'] ?? null, 'terms', EHRMAN_MAX_SELECTED_TERMS);
    $sort = ehrman_normalized_sort($case['sort'] ?? null);
    $scope = $case['scope'] ?? ['type' => 'global'];
    if (!is_array($scope) || array_is_list($scope)) {
        throw new InvalidArgumentException('scope must be an object');
    }
    $scopeType = ehrman_clean_string($scope['type'] ?? null) ?: 'global';
    $scopeSlug = ehrman_clean_string($scope['slug'] ?? null);
    $db = ehrman_db();

    if ($scopeType === 'global') {
        [$posts, $cleanTerms] = ehrman_search_posts($terms, $sort);
        $displayTerms = $cleanTerms;
    } elseif ($scopeType === 'category') {
        if ($scopeSlug === '') {
            throw new InvalidArgumentException('category scope requires a slug');
        }
        $category = ehrman_fetch_one($db, 'SELECT * FROM categories WHERE slug = ?', [$scopeSlug]);
        if ($category === null) {
            throw new InvalidArgumentException("unknown category slug: {$scopeSlug}");
        }
        [$posts, $cleanTerms] = ehrman_search_category_posts($db, $category, $terms, $sort);
        $displayTerms = $cleanTerms;
    } elseif ($scopeType === 'topic') {
        if ($scopeSlug === '') {
            throw new InvalidArgumentException('topic scope requires a slug');
        }
        $topic = ehrman_fetch_one($db, 'SELECT * FROM topics WHERE slug = ?', [$scopeSlug]);
        if ($topic === null) {
            throw new InvalidArgumentException("unknown topic slug: {$scopeSlug}");
        }
        [$posts, $cleanTerms, $displayTerms] = ehrman_search_topic_posts($db, $topic, $terms, $sort);
    } else {
        throw new InvalidArgumentException("unknown search scope: {$scopeType}");
    }

    return [
        'operation' => 'search',
        'terms' => $cleanTerms,
        'displayTerms' => $displayTerms,
        'sort' => $sort,
        'scope' => ['type' => $scopeType, 'slug' => $scopeSlug],
        'resultCount' => count($posts),
        'posts' => ehrman_serialize_posts($posts),
    ];
}

function ehrman_suggest_case(array $case): array
{
    $selected = ehrman_case_string_list($case['selected'] ?? null, 'selected', EHRMAN_MAX_SELECTED_TERMS);
    $query = ehrman_clean_string($case['query'] ?? null);
    $categorySlug = ehrman_clean_string($case['categorySlug'] ?? null);
    $topicSlug = ehrman_clean_string($case['topicSlug'] ?? null);
    $suggestions = ehrman_keyword_suggestions($query, $selected, $categorySlug, $topicSlug);
    return [
        'operation' => 'suggest',
        'query' => $query,
        'normalizedQuery' => ehrman_normalize_keyword($query),
        'selected' => $selected,
        'categorySlug' => $categorySlug,
        'topicSlug' => $topicSlug,
        'suggestionCount' => count($suggestions),
        'suggestions' => $suggestions,
    ];
}

function ehrman_subject_area_records(PDO $db, string $areaTable, string $linkTable): array
{
    $areas = ehrman_fetch_all($db, "SELECT * FROM {$areaTable} ORDER BY id");
    $records = [];
    foreach ($areas as $area) {
        $categories = ehrman_fetch_all(
            $db,
            "SELECT c.name, c.slug, c.description, COUNT(DISTINCT tc.topic_id) AS topic_count, "
            . "COUNT(DISTINCT pt.post_id) AS post_count FROM {$linkTable} sac "
            . 'JOIN categories c ON c.id = sac.category_id '
            . 'LEFT JOIN topic_categories tc ON tc.category_id = c.id '
            . 'LEFT JOIN post_topics pt ON pt.topic_id = tc.topic_id '
            . 'WHERE sac.subject_area_id = ? GROUP BY c.id '
            . 'ORDER BY sac.position, c.name COLLATE NOCASE',
            [(int) $area['id']],
        );
        $categoryRecords = [];
        foreach ($categories as $category) {
            $categoryRecords[] = [
                'name' => (string) $category['name'],
                'slug' => (string) $category['slug'],
                'description' => (string) $category['description'],
                'topicCount' => (int) $category['topic_count'],
                'postCount' => (int) $category['post_count'],
            ];
        }
        $topicCount = (int) ehrman_scalar(
            $db,
            "SELECT COUNT(DISTINCT tc.topic_id) FROM {$linkTable} sac "
            . 'JOIN topic_categories tc ON tc.category_id = sac.category_id WHERE sac.subject_area_id = ?',
            [(int) $area['id']],
        );
        $postCount = (int) ehrman_scalar(
            $db,
            "SELECT COUNT(DISTINCT pt.post_id) FROM {$linkTable} sac "
            . 'JOIN topic_categories tc ON tc.category_id = sac.category_id '
            . 'JOIN post_topics pt ON pt.topic_id = tc.topic_id WHERE sac.subject_area_id = ?',
            [(int) $area['id']],
        );
        $records[] = [
            'name' => (string) $area['name'],
            'slug' => (string) $area['slug'],
            'description' => (string) $area['description'],
            'categoryCount' => count($categories),
            'topicCount' => $topicCount,
            'postCount' => $postCount,
            'categories' => $categoryRecords,
        ];
    }
    return $records;
}

function ehrman_category_records(PDO $db): array
{
    $records = [];
    foreach (ehrman_fetch_all($db, 'SELECT * FROM categories ORDER BY name COLLATE NOCASE') as $category) {
        $topics = ehrman_fetch_all(
            $db,
            <<<'SQL'
            SELECT t.name, t.slug, t.description, t.display_in_browser,
                   COUNT(DISTINCT pt.post_id) AS post_count
            FROM topics t
            JOIN topic_categories tc ON tc.topic_id = t.id
            LEFT JOIN post_topics pt ON pt.topic_id = t.id
            WHERE tc.category_id = ? AND t.display_in_browser = 1
            GROUP BY t.id
            ORDER BY CASE WHEN tc.position > 0 THEN 0 ELSE 1 END,
                     tc.position, t.name COLLATE NOCASE
            SQL,
            [(int) $category['id']],
        );
        $topicRecords = [];
        foreach ($topics as $topic) {
            $topicRecords[] = [
                'name' => (string) $topic['name'],
                'slug' => (string) $topic['slug'],
                'description' => (string) $topic['description'],
                'postCount' => (int) $topic['post_count'],
            ];
        }
        $postCount = (int) ehrman_scalar(
            $db,
            'SELECT COUNT(DISTINCT pt.post_id) FROM post_topics pt '
            . 'JOIN topic_categories tc ON tc.topic_id = pt.topic_id WHERE tc.category_id = ?',
            [(int) $category['id']],
        );
        $records[] = [
            'name' => (string) $category['name'],
            'slug' => (string) $category['slug'],
            'description' => (string) $category['description'],
            'topicCount' => count($topics),
            'postCount' => $postCount,
            'topics' => $topicRecords,
        ];
    }
    return $records;
}

function ehrman_topic_records(PDO $db): array
{
    $rows = ehrman_fetch_all(
        $db,
        <<<'SQL'
        SELECT t.name, t.slug, t.description, t.display_in_browser,
               COUNT(DISTINCT pt.post_id) AS post_count
        FROM topics t
        LEFT JOIN post_topics pt ON pt.topic_id = t.id
        GROUP BY t.id
        ORDER BY t.name COLLATE NOCASE
        SQL,
    );
    $records = [];
    foreach ($rows as $topic) {
        $categories = ehrman_fetch_all(
            $db,
            <<<'SQL'
            SELECT c.name, c.slug
            FROM categories c
            JOIN topic_categories tc ON tc.category_id = c.id
            JOIN topics t ON t.id = tc.topic_id
            WHERE t.slug = ?
            ORDER BY c.name COLLATE NOCASE
            SQL,
            [(string) $topic['slug']],
        );
        $records[] = [
            'name' => (string) $topic['name'],
            'slug' => (string) $topic['slug'],
            'description' => (string) $topic['description'],
            'displayInBrowser' => (bool) $topic['display_in_browser'],
            'postCount' => (int) $topic['post_count'],
            'categories' => array_map(static fn(array $category): array => [
                'name' => (string) $category['name'],
                'slug' => (string) $category['slug'],
            ], $categories),
        ];
    }
    return $records;
}

function ehrman_browse_case(): array
{
    $db = ehrman_db();
    return [
        'operation' => 'browse',
        'subjectAreas1' => ehrman_subject_area_records($db, 'subject_areas', 'subject_area_categories'),
        'subjectAreas2' => ehrman_subject_area_records($db, 'subject_areas_2', 'subject_area_2_categories'),
        'categories' => ehrman_category_records($db),
        'topics' => ehrman_topic_records($db),
    ];
}

function ehrman_execute_case(array $case): array
{
    $id = ehrman_clean_string($case['id'] ?? null);
    if ($id === '') {
        throw new InvalidArgumentException('each case requires a non-empty id');
    }
    $operation = ehrman_clean_string($case['operation'] ?? null);
    $result = match ($operation) {
        'search' => ehrman_search_case($case),
        'suggest' => ehrman_suggest_case($case),
        'browse' => ehrman_browse_case(),
        default => throw new InvalidArgumentException("unknown operation: {$operation}"),
    };
    return ['id' => $id, 'ok' => true, ...$result];
}

function ehrman_run_batch(mixed $cases): array
{
    if (!is_array($cases) || !array_is_list($cases)) {
        throw new InvalidArgumentException('cases must be a list');
    }
    if ($cases === []) {
        throw new InvalidArgumentException('cases must not be empty');
    }
    if (count($cases) > EHRMAN_MAX_PARITY_CASES) {
        throw new InvalidArgumentException('a batch supports at most ' . EHRMAN_MAX_PARITY_CASES . ' cases');
    }

    $results = [];
    foreach ($cases as $case) {
        if (!is_array($case) || array_is_list($case)) {
            $results[] = ['id' => '', 'ok' => false, 'error' => 'case must be an object'];
            continue;
        }
        try {
            $results[] = ehrman_execute_case($case);
        } catch (InvalidArgumentException | PDOException $error) {
            $results[] = [
                'id' => ehrman_clean_string($case['id'] ?? null),
                'ok' => false,
                'error' => $error->getMessage(),
            ];
        }
    }
    return [...ehrman_parity_manifest(), 'results' => $results];
}
