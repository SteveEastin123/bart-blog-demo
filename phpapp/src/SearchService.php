<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const EHRMAN_STARTER_TOPICS = [
    'Gospel of Luke',
    'Gospel of Mark',
    'Gospel of Matthew',
    'Gospel of John',
    "Jesus' Teachings",
    'Historical Jesus (General)',
    'Pauline Letters',
    'Textual Variants',
    'Scribal Changes',
    'Original Text Questions',
    'Biblical Contradictions',
    'Canon',
    'Revelation',
    'Birth Narrative',
    'Resurrection of Jesus',
    'Heaven/Hell',
    'Literary Forgery (General)',
    'Early Christianity (General)',
];

function ehrman_find_post_ids_for_term(PDO $db, string $term): array
{
    $normalized = ehrman_normalize_keyword($term);
    if ($normalized === '') {
        return [];
    }
    $rows = ehrman_fetch_all(
        $db,
        <<<'SQL'
        SELECT post_id, MAX(weight + CASE WHEN normalized = ? THEN 2 ELSE 0 END) AS score
        FROM post_search_terms
        WHERE normalized = ? OR (' ' || normalized || ' ') LIKE ?
        GROUP BY post_id
        SQL,
        [$normalized, $normalized, "% {$normalized} %"],
    );
    $matches = [];
    foreach ($rows as $row) {
        $matches[(int) $row['post_id']] = (int) $row['score'];
    }
    return $matches;
}

function ehrman_title_match_boost(string $title, string $term): int
{
    $normalizedTitle = ehrman_normalize_keyword($title);
    $normalizedTerm = ehrman_normalize_keyword($term);
    if ($normalizedTitle === '' || $normalizedTerm === '') {
        return 0;
    }
    if (str_contains(" {$normalizedTitle} ", " {$normalizedTerm} ")) {
        return 2;
    }
    if (!str_contains($normalizedTerm, ' ') && in_array($normalizedTerm, explode(' ', $normalizedTitle), true)) {
        return 1;
    }
    return 0;
}

function ehrman_sort_posts(array $posts, string $sort, array $rankingTerms, ?array $scores = null): array
{
    $sort = in_array($sort, ['ranked', 'newest', 'oldest'], true) ? $sort : 'ranked';
    usort($posts, static function (array $left, array $right) use ($sort, $rankingTerms, $scores): int {
        if ($sort === 'ranked') {
            $leftScore = (int) ($scores[(int) $left['id']] ?? 0);
            $rightScore = (int) ($scores[(int) $right['id']] ?? 0);
            foreach ($rankingTerms as $term) {
                $leftScore += ehrman_title_match_boost((string) $left['title'], $term);
                $rightScore += ehrman_title_match_boost((string) $right['title'], $term);
            }
            if ($leftScore !== $rightScore) {
                return $rightScore <=> $leftScore;
            }
        }

        $dateComparison = strcmp((string) $left['date_iso'], (string) $right['date_iso']);
        if ($dateComparison !== 0) {
            return $sort === 'oldest' ? $dateComparison : -$dateComparison;
        }
        $urlComparison = strcasecmp((string) $left['url'], (string) $right['url']);
        return $sort === 'oldest' ? $urlComparison : -$urlComparison;
    });
    return $posts;
}

function ehrman_search_posts(array $terms, string $sort): array
{
    $sort = in_array($sort, ['ranked', 'newest', 'oldest'], true) ? $sort : 'ranked';
    $cleanTerms = ehrman_unique_terms($terms);
    if ($cleanTerms === []) {
        return [[], []];
    }
    $db = ehrman_db();
    $matches = null;
    foreach ($cleanTerms as $term) {
        $matches = ehrman_intersect_scores($matches, ehrman_find_post_ids_for_term($db, $term));
    }
    if ($matches === null || $matches === []) {
        return [[], $cleanTerms];
    }

    $postIds = array_keys($matches);
    $posts = ehrman_fetch_all(
        $db,
        'SELECT p.* FROM posts p WHERE p.id IN (' . ehrman_placeholders(count($postIds)) . ')',
        $postIds,
    );
    foreach ($posts as $post) {
        $postId = (int) $post['id'];
        foreach ($cleanTerms as $term) {
            $matches[$postId] += ehrman_title_match_boost((string) $post['title'], $term);
        }
    }
    return [ehrman_sort_posts($posts, $sort, [], $matches), $cleanTerms];
}

function ehrman_search_topic_posts(PDO $db, array $topic, array $terms, string $sort): array
{
    $cleanTerms = ehrman_unique_terms($terms);
    $posts = ehrman_fetch_all(
        $db,
        <<<'SQL'
        SELECT p.*
        FROM posts p
        JOIN post_topics pt ON pt.post_id = p.id
        WHERE pt.topic_id = ?
        ORDER BY p.date_iso DESC, p.url COLLATE NOCASE DESC
        SQL,
        [(int) $topic['id']],
    );
    $topicNormalized = ehrman_normalize_keyword($topic['name']);
    $filterTerms = array_values(array_filter(
        $cleanTerms,
        static fn(string $term): bool => ehrman_normalize_keyword($term) !== $topicNormalized,
    ));
    $scores = null;
    if ($filterTerms !== []) {
        $scores = [];
        foreach ($posts as $post) {
            $scores[(int) $post['id']] = 0;
        }
        foreach ($filterTerms as $term) {
            $scores = ehrman_intersect_scores($scores, ehrman_find_post_ids_for_term($db, $term));
        }
        $posts = array_values(array_filter(
            $posts,
            static fn(array $post): bool => isset($scores[(int) $post['id']]),
        ));
    }
    $displayTerms = $cleanTerms !== [] ? $cleanTerms : [(string) $topic['name']];
    return [ehrman_sort_posts($posts, $sort, $displayTerms, $scores), $cleanTerms, $displayTerms];
}

function ehrman_search_category_posts(PDO $db, array $category, array $terms, string $sort): array
{
    $cleanTerms = ehrman_unique_terms($terms);
    $posts = ehrman_fetch_all(
        $db,
        <<<'SQL'
        SELECT DISTINCT p.*
        FROM posts p
        JOIN post_topics pt ON pt.post_id = p.id
        JOIN topic_categories tc ON tc.topic_id = pt.topic_id
        WHERE tc.category_id = ?
        ORDER BY p.date_iso DESC, p.url COLLATE NOCASE DESC
        SQL,
        [(int) $category['id']],
    );
    $scores = [];
    foreach ($posts as $post) {
        $scores[(int) $post['id']] = 0;
    }
    foreach ($cleanTerms as $term) {
        $scores = ehrman_intersect_scores($scores, ehrman_find_post_ids_for_term($db, $term));
    }
    if ($cleanTerms !== []) {
        $posts = array_values(array_filter(
            $posts,
            static fn(array $post): bool => isset($scores[(int) $post['id']]),
        ));
    }
    return [ehrman_sort_posts($posts, $sort, $cleanTerms, $cleanTerms === [] ? null : $scores), $cleanTerms];
}

function ehrman_starter_suggestions(PDO $db): array
{
    $rows = ehrman_fetch_all(
        $db,
        'SELECT label, normalized, COUNT(DISTINCT post_id) AS post_count '
        . 'FROM post_search_terms WHERE kind = \'topic\' AND label IN ('
        . ehrman_placeholders(count(EHRMAN_STARTER_TOPICS))
        . ') GROUP BY label, normalized',
        EHRMAN_STARTER_TOPICS,
    );
    $byLabel = [];
    foreach ($rows as $row) {
        $byLabel[(string) $row['label']] = $row;
    }
    $suggestions = [];
    foreach (EHRMAN_STARTER_TOPICS as $label) {
        if (!isset($byLabel[$label])) {
            continue;
        }
        $row = $byLabel[$label];
        $suggestions[] = [
            'label' => (string) $row['label'],
            'normalized' => (string) $row['normalized'],
            'postCount' => (int) $row['post_count'],
            'isTopic' => true,
        ];
    }
    return $suggestions;
}

function ehrman_id_set(array $rows): array
{
    $ids = [];
    foreach ($rows as $row) {
        $ids[(int) $row['post_id']] = true;
    }
    return $ids;
}

function ehrman_intersect_id_sets(?array $left, array $right): array
{
    return $left === null ? $right : array_intersect_key($left, $right);
}

function ehrman_keyword_suggestions(
    string $queryText,
    array $selected = [],
    string $categorySlug = '',
    string $topicSlug = '',
): array {
    $db = ehrman_db();
    $q = ehrman_normalize_keyword($queryText);
    $selected = array_values(array_filter(
        array_map('ehrman_clean_string', $selected),
        static fn(string $value): bool => $value !== '',
    ));
    $selectedNormalized = [];
    foreach ($selected as $value) {
        $normalized = ehrman_normalize_keyword($value);
        if ($normalized !== '') {
            $selectedNormalized[$normalized] = true;
        }
    }
    $selectedNormalized = array_keys($selectedNormalized);
    sort($selectedNormalized, SORT_STRING);
    $categorySlug = trim($categorySlug);
    $topicSlug = trim($topicSlug);

    if ($q === '' && $selectedNormalized === [] && $categorySlug === '' && $topicSlug === '') {
        return ehrman_starter_suggestions($db);
    }

    $selectedIds = null;
    $allowedCategoryTopics = [];
    if ($categorySlug !== '') {
        $category = ehrman_fetch_one($db, 'SELECT id FROM categories WHERE slug = ?', [$categorySlug]);
        if ($category === null) {
            return [];
        }
        $selectedIds = ehrman_id_set(ehrman_fetch_all(
            $db,
            'SELECT DISTINCT pt.post_id FROM post_topics pt '
            . 'JOIN topic_categories tc ON tc.topic_id = pt.topic_id WHERE tc.category_id = ?',
            [(int) $category['id']],
        ));
        $topicRows = ehrman_fetch_all(
            $db,
            'SELECT t.name FROM topics t JOIN topic_categories tc ON tc.topic_id = t.id '
            . 'WHERE tc.category_id = ? AND t.display_in_browser = 1',
            [(int) $category['id']],
        );
        $allowedCategoryTopics = array_map(static fn(array $row): string => (string) $row['name'], $topicRows);
    }
    if ($topicSlug !== '') {
        $topic = ehrman_fetch_one($db, 'SELECT id FROM topics WHERE slug = ?', [$topicSlug]);
        if ($topic === null) {
            return [];
        }
        $topicIds = ehrman_id_set(ehrman_fetch_all(
            $db,
            'SELECT post_id FROM post_topics WHERE topic_id = ?',
            [(int) $topic['id']],
        ));
        $selectedIds = ehrman_intersect_id_sets($selectedIds, $topicIds);
    }
    foreach ($selected as $value) {
        $matches = array_fill_keys(array_keys(ehrman_find_post_ids_for_term($db, $value)), true);
        $selectedIds = ehrman_intersect_id_sets($selectedIds, $matches);
    }

    $prefixLike = $q . '%';
    $wordPrefixLike = '% ' . $q . '%';
    $where = "normalized <> 'ignore'";
    $params = [];
    if ($q !== '') {
        $where .= ' AND (normalized LIKE ? OR normalized LIKE ?)';
        array_push($params, $prefixLike, $wordPrefixLike);
    }
    if ($selectedIds !== null) {
        if ($selectedIds === []) {
            return [];
        }
        $ids = array_keys($selectedIds);
        sort($ids, SORT_NUMERIC);
        $where .= ' AND post_id IN (' . ehrman_placeholders(count($ids)) . ')';
        array_push($params, ...$ids);
    }
    if ($categorySlug !== '') {
        if ($allowedCategoryTopics !== []) {
            $where .= " AND (kind <> 'topic' OR label IN (" . ehrman_placeholders(count($allowedCategoryTopics)) . '))';
            array_push($params, ...$allowedCategoryTopics);
        } else {
            $where .= " AND kind <> 'topic'";
        }
    }
    if ($selectedNormalized !== []) {
        $where .= ' AND normalized NOT IN (' . ehrman_placeholders(count($selectedNormalized)) . ')';
        array_push($params, ...$selectedNormalized);
    }

    $rows = ehrman_fetch_all(
        $db,
        'SELECT COALESCE(MIN(CASE WHEN kind = \'topic\' THEN label END), MIN(label)) AS label, '
        . 'normalized, COUNT(DISTINCT post_id) AS post_count, '
        . "MAX(CASE WHEN kind = 'topic' THEN 1 ELSE 0 END) AS is_topic, "
        . 'CASE WHEN normalized = ? THEN 3 WHEN normalized LIKE ? THEN 2 '
        . 'WHEN normalized LIKE ? THEN 1 ELSE 1 END AS match_quality '
        . "FROM post_search_terms WHERE {$where} GROUP BY normalized "
        . 'ORDER BY match_quality DESC, is_topic DESC, post_count DESC, label COLLATE NOCASE LIMIT 48',
        [$q, $prefixLike, $wordPrefixLike, ...$params],
    );

    $candidateNormalized = [];
    foreach ($rows as $row) {
        $candidateNormalized[(string) $row['normalized']] = true;
    }
    $countParams = [];
    $countWhere = '';
    if ($selectedIds !== null) {
        $ids = array_keys($selectedIds);
        sort($ids, SORT_NUMERIC);
        $countWhere = 'WHERE post_id IN (' . ehrman_placeholders(count($ids)) . ')';
        $countParams = $ids;
    }
    $countRows = ehrman_fetch_all(
        $db,
        "SELECT DISTINCT post_id, normalized FROM post_search_terms {$countWhere}",
        $countParams,
    );
    $matchingPosts = array_fill_keys(array_keys($candidateNormalized), []);
    foreach ($countRows as $countRow) {
        $indexedValue = (string) $countRow['normalized'];
        $postId = (int) $countRow['post_id'];
        foreach ($candidateNormalized as $candidate => $_) {
            if ($indexedValue === $candidate || str_contains(" {$indexedValue} ", " {$candidate} ")) {
                $matchingPosts[$candidate][$postId] = true;
            }
        }
    }

    $suggestions = [];
    foreach ($rows as $row) {
        $normalized = (string) $row['normalized'];
        $postCount = count($matchingPosts[$normalized]);
        if ($postCount === 0) {
            continue;
        }
        $suggestions[] = [
            'label' => (string) $row['label'],
            'normalized' => $normalized,
            'postCount' => $postCount,
            'isTopic' => (bool) $row['is_topic'],
            'matchQuality' => (int) $row['match_quality'],
        ];
    }
    usort($suggestions, static function (array $left, array $right): int {
        foreach (['matchQuality', 'isTopic', 'postCount'] as $field) {
            $comparison = (int) $right[$field] <=> (int) $left[$field];
            if ($comparison !== 0) {
                return $comparison;
            }
        }
        return strcasecmp((string) $left['label'], (string) $right['label']);
    });
    return array_map(static fn(array $suggestion): array => [
        'label' => $suggestion['label'],
        'normalized' => $suggestion['normalized'],
        'postCount' => $suggestion['postCount'],
        'isTopic' => $suggestion['isTopic'],
    ], $suggestions);
}
