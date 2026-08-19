<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function ehrman_clean_term_mode(mixed $mode): string
{
    $clean = is_scalar($mode) ? strtolower(trim((string) $mode)) : '';
    return in_array($clean, ['topic', 'topic-keyword', 'keyword'], true) ? $clean : '';
}

function ehrman_resolve_term_modes(PDO $db, array $terms, array $requestedModes = []): array
{
    $cleanTerms = ehrman_unique_terms($terms);
    if ($cleanTerms === []) {
        return [];
    }
    $normalized = array_map('ehrman_normalize_keyword', $cleanTerms);
    $rows = ehrman_fetch_all(
        $db,
        'SELECT normalized, MAX(CASE WHEN kind IN (\'topic\', \'alias\') THEN 1 ELSE 0 END) AS has_topic, '
        . 'MAX(CASE WHEN kind = \'secondary\' THEN 1 ELSE 0 END) AS has_keyword '
        . 'FROM post_search_terms WHERE normalized IN (' . ehrman_placeholders(count($normalized)) . ') '
        . 'GROUP BY normalized',
        $normalized,
    );
    $inferred = [];
    foreach ($rows as $row) {
        $hasTopic = (int) $row['has_topic'] === 1;
        $hasKeyword = (int) $row['has_keyword'] === 1;
        $inferred[(string) $row['normalized']] = $hasTopic && $hasKeyword
            ? 'topic-keyword'
            : ($hasTopic ? 'topic' : 'keyword');
    }
    $modes = [];
    foreach ($cleanTerms as $index => $term) {
        $requested = ehrman_clean_term_mode($requestedModes[$index] ?? '');
        $modes[] = $requested !== ''
            ? $requested
            : ($inferred[ehrman_normalize_keyword($term)] ?? 'keyword');
    }
    return $modes;
}

function ehrman_find_post_ids_for_term(PDO $db, string $term, string $mode = 'topic-keyword'): array
{
    $normalized = ehrman_normalize_keyword($term);
    if ($normalized === '') {
        return [];
    }
    if (ehrman_clean_term_mode($mode) === 'topic') {
        $rows = ehrman_fetch_all(
            $db,
            <<<'SQL'
            SELECT post_id, MAX(weight + 2) AS score
            FROM post_search_terms
            WHERE normalized = ? AND kind IN ('topic', 'alias')
            GROUP BY post_id
            SQL,
            [$normalized],
        );
    } else {
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
    }
    $matches = [];
    foreach ($rows as $row) {
        $matches[(int) $row['post_id']] = (int) $row['score'];
    }
    return $matches;
}

function ehrman_ranking_text_match_term(string $term): string
{
    $normalizedTerm = ehrman_normalize_keyword($term);
    if (str_ends_with($normalizedTerm, ' general')) {
        return rtrim(substr($normalizedTerm, 0, -strlen(' general')));
    }
    return $normalizedTerm;
}

function ehrman_ranking_anchor_token(string $term): string
{
    static $stopwords = [
        'a' => true,
        'an' => true,
        'and' => true,
        'as' => true,
        'at' => true,
        'belief' => true,
        'beliefs' => true,
        'by' => true,
        'for' => true,
        'from' => true,
        'general' => true,
        'in' => true,
        'into' => true,
        'issue' => true,
        'issues' => true,
        'of' => true,
        'on' => true,
        'or' => true,
        'overview' => true,
        'question' => true,
        'questions' => true,
        'the' => true,
        'to' => true,
        'tradition' => true,
        'traditions' => true,
        'with' => true,
    ];
    $normalizedTerm = ehrman_ranking_text_match_term($term);
    if (!str_contains($normalizedTerm, ' ')) {
        return '';
    }
    $tokens = array_values(array_filter(
        explode(' ', $normalizedTerm),
        static fn (string $token): bool => strlen($token) >= 4 && !isset($stopwords[$token]),
    ));
    return $tokens !== [] ? (string) end($tokens) : '';
}

function ehrman_title_match_boost(string $title, string $term): int
{
    $normalizedTitle = ehrman_normalize_keyword($title);
    $normalizedTerm = ehrman_ranking_text_match_term($term);
    if ($normalizedTitle === '' || $normalizedTerm === '') {
        return 0;
    }
    if (str_contains(" {$normalizedTitle} ", " {$normalizedTerm} ")) {
        return 4;
    }
    if (!str_contains($normalizedTerm, ' ') && in_array($normalizedTerm, explode(' ', $normalizedTitle), true)) {
        return 1;
    }
    $anchor = ehrman_ranking_anchor_token($term);
    if ($anchor !== '' && in_array($anchor, explode(' ', $normalizedTitle), true)) {
        return 2;
    }
    return 0;
}

function ehrman_description_match_boost(string $description, string $term): int
{
    $normalizedDescription = ehrman_normalize_keyword($description);
    $normalizedTerm = ehrman_ranking_text_match_term($term);
    if ($normalizedDescription === '' || $normalizedTerm === '') {
        return 0;
    }
    if (str_contains(" {$normalizedDescription} ", " {$normalizedTerm} ")) {
        return 2;
    }
    $anchor = ehrman_ranking_anchor_token($term);
    if ($anchor !== '' && in_array($anchor, explode(' ', $normalizedDescription), true)) {
        return 1;
    }
    return 0;
}

function ehrman_sort_posts(array $posts, string $sort, array $rankingTerms, ?array $scores = null): array
{
    $sort = in_array($sort, ['ranked', 'newest', 'oldest'], true) ? $sort : 'ranked';
    $rankedScores = $scores ?? [];
    if ($sort === 'ranked') {
        foreach ($posts as $post) {
            $postId = (int) $post['id'];
            $score = (int) ($rankedScores[$postId] ?? 0);
            foreach ($rankingTerms as $term) {
                $score += ehrman_title_match_boost((string) $post['title'], $term);
                $score += ehrman_description_match_boost((string) ($post['description'] ?? ''), $term);
            }
            $rankedScores[$postId] = $score;
        }
    }
    usort($posts, static function (array $left, array $right) use ($sort, $rankedScores): int {
        if ($sort === 'ranked') {
            $leftScore = (int) ($rankedScores[(int) $left['id']] ?? 0);
            $rightScore = (int) ($rankedScores[(int) $right['id']] ?? 0);
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

function ehrman_search_posts(array $terms, string $sort, string $categorySlug = '', array $termModes = []): array
{
    $sort = in_array($sort, ['ranked', 'newest', 'oldest'], true) ? $sort : 'ranked';
    $cleanTerms = ehrman_unique_terms($terms);
    $categorySlug = trim($categorySlug);
    if ($cleanTerms === [] && $categorySlug === '') {
        return [[], []];
    }
    $db = ehrman_db();
    $termModes = ehrman_resolve_term_modes($db, $cleanTerms, $termModes);
    if ($categorySlug !== '') {
        $category = ehrman_fetch_one($db, 'SELECT * FROM categories WHERE slug = ?', [$categorySlug]);
        if ($category === null) {
            return [[], $cleanTerms];
        }
        return ehrman_search_category_posts($db, $category, $cleanTerms, $sort, $termModes);
    }
    $matches = null;
    foreach ($cleanTerms as $index => $term) {
        $matches = ehrman_intersect_scores(
            $matches,
            ehrman_find_post_ids_for_term($db, $term, $termModes[$index] ?? 'topic-keyword'),
        );
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
    return [ehrman_sort_posts($posts, $sort, $cleanTerms, $matches), $cleanTerms];
}

function ehrman_search_topic_posts(PDO $db, array $topic, array $terms, string $sort, array $termModes = []): array
{
    $cleanTerms = ehrman_unique_terms($terms);
    $termModes = ehrman_resolve_term_modes($db, $cleanTerms, $termModes);
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
    $filterTerms = [];
    $filterModes = [];
    foreach ($cleanTerms as $index => $term) {
        if (ehrman_normalize_keyword($term) === $topicNormalized) {
            continue;
        }
        $filterTerms[] = $term;
        $filterModes[] = $termModes[$index] ?? 'topic-keyword';
    }
    $scores = null;
    if ($filterTerms !== []) {
        $scores = [];
        foreach ($posts as $post) {
            $scores[(int) $post['id']] = 0;
        }
        foreach ($filterTerms as $index => $term) {
            $scores = ehrman_intersect_scores(
                $scores,
                ehrman_find_post_ids_for_term($db, $term, $filterModes[$index] ?? 'topic-keyword'),
            );
        }
        $posts = array_values(array_filter(
            $posts,
            static fn(array $post): bool => isset($scores[(int) $post['id']]),
        ));
    }
    $displayTerms = $cleanTerms !== [] ? $cleanTerms : [(string) $topic['name']];
    return [ehrman_sort_posts($posts, $sort, $displayTerms, $scores), $cleanTerms, $displayTerms];
}

function ehrman_search_category_posts(PDO $db, array $category, array $terms, string $sort, array $termModes = []): array
{
    $cleanTerms = ehrman_unique_terms($terms);
    $termModes = ehrman_resolve_term_modes($db, $cleanTerms, $termModes);
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
    foreach ($cleanTerms as $index => $term) {
        $scores = ehrman_intersect_scores(
            $scores,
            ehrman_find_post_ids_for_term($db, $term, $termModes[$index] ?? 'topic-keyword'),
        );
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
        <<<'SQL'
        SELECT t.name AS label, t.description, t.featured_order, COUNT(DISTINCT pt.post_id) AS post_count
        FROM topics t
        JOIN post_topics pt ON pt.topic_id = t.id
        WHERE t.featured_order IS NOT NULL AND t.display_in_browser = 1
        GROUP BY t.id, t.name, t.description, t.featured_order
        ORDER BY t.featured_order
        SQL,
    );
    $suggestions = [];
    foreach ($rows as $row) {
        $label = (string) $row['label'];
        $suggestions[] = [
            'label' => $label,
            'normalized' => ehrman_normalize_keyword($label),
            'postCount' => (int) $row['post_count'],
            'mode' => 'topic',
            'isTopic' => true,
            'isCombined' => false,
            'description' => (string) ($row['description'] ?? ''),
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
    array $selectedModes = [],
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
    $selectedModes = ehrman_resolve_term_modes($db, $selected, $selectedModes);
    $limitClause = $categorySlug !== '' && $q === '' && $selectedNormalized === [] && $topicSlug === ''
        ? ''
        : ($selectedNormalized !== [] ? 'LIMIT 192' : 'LIMIT 48');

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
    foreach ($selected as $index => $value) {
        $matches = array_fill_keys(
            array_keys(ehrman_find_post_ids_for_term($db, $value, $selectedModes[$index] ?? 'topic-keyword')),
            true,
        );
        $selectedIds = ehrman_intersect_id_sets($selectedIds, $matches);
    }
    $contextPostCount = $selected !== [] && $selectedIds !== null ? count($selectedIds) : null;

    $prefixLike = $q . '%';
    $wordPrefixLike = '% ' . $q . '%';
    $where = "normalized <> 'ignore'";
    $params = [];
    if ($q === '') {
        $where .= " AND kind <> 'alias'";
    }
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
            $where .= " AND (kind NOT IN ('topic', 'alias') OR label IN (" . ehrman_placeholders(count($allowedCategoryTopics)) . '))';
            array_push($params, ...$allowedCategoryTopics);
        } else {
            $where .= " AND kind NOT IN ('topic', 'alias')";
        }
    }
    if ($selectedNormalized !== []) {
        $where .= ' AND normalized NOT IN (' . ehrman_placeholders(count($selectedNormalized)) . ')';
        array_push($params, ...$selectedNormalized);
    }

    $rows = ehrman_fetch_all(
        $db,
        'SELECT COALESCE(MIN(CASE WHEN kind IN (\'topic\', \'alias\') THEN label END), MIN(label)) AS label, '
        . 'normalized, COUNT(DISTINCT post_id) AS post_count, '
        . "MAX(CASE WHEN kind IN ('topic', 'alias') THEN 1 ELSE 0 END) AS has_topic, "
        . "MAX(CASE WHEN kind = 'secondary' THEN 1 ELSE 0 END) AS has_keyword, "
        . 'CASE WHEN normalized = ? THEN 3 WHEN normalized LIKE ? THEN 2 '
        . 'WHEN normalized LIKE ? THEN 1 ELSE 1 END AS match_quality '
        . "FROM post_search_terms WHERE {$where} GROUP BY normalized "
        . 'ORDER BY match_quality DESC, post_count DESC, has_topic DESC, label COLLATE NOCASE ' . $limitClause,
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
        "SELECT DISTINCT post_id, normalized, kind FROM post_search_terms {$countWhere}",
        $countParams,
    );
    $matchingPosts = array_fill_keys(array_keys($candidateNormalized), []);
    $topicPosts = array_fill_keys(array_keys($candidateNormalized), []);
    foreach ($countRows as $countRow) {
        $indexedValue = (string) $countRow['normalized'];
        $postId = (int) $countRow['post_id'];
        foreach ($candidateNormalized as $candidate => $_) {
            if ($indexedValue === $candidate || str_contains(" {$indexedValue} ", " {$candidate} ")) {
                $matchingPosts[$candidate][$postId] = true;
            }
            if ($indexedValue === $candidate && in_array((string) $countRow['kind'], ['topic', 'alias'], true)) {
                $topicPosts[$candidate][$postId] = true;
            }
        }
    }

    $topicDescriptions = [];
    foreach (
        ehrman_fetch_all($db, 'SELECT name, description FROM topics WHERE display_in_browser = 1')
        as $topicRow
    ) {
        $topicDescriptions[ehrman_normalize_keyword((string) $topicRow['name'])] =
            (string) ($topicRow['description'] ?? '');
    }
    $suggestions = [];
    foreach ($rows as $row) {
        $normalized = (string) $row['normalized'];
        $postCount = count($matchingPosts[$normalized]);
        if ($postCount === 0) {
            continue;
        }
        $hasTopic = (bool) $row['has_topic'];
        $hasKeyword = (bool) $row['has_keyword'];
        $base = [
            'label' => (string) $row['label'],
            'normalized' => $normalized,
            'matchQuality' => (int) $row['match_quality'],
            'description' => $hasTopic
                ? ($topicDescriptions[ehrman_normalize_keyword((string) $row['label'])] ?? '')
                : '',
        ];
        $topicCount = count($topicPosts[$normalized] ?? []);
        if ($hasTopic && $topicCount > 0 && ($contextPostCount === null || $topicCount < $contextPostCount)) {
            $suggestions[] = $base + [
                'postCount' => $topicCount,
                'mode' => 'topic',
                'typeRank' => 3,
            ];
        }
        if ($hasTopic && $hasKeyword && ($contextPostCount === null || $postCount < $contextPostCount)) {
            $suggestions[] = $base + [
                'postCount' => $postCount,
                'mode' => 'topic-keyword',
                'typeRank' => 2,
            ];
        } elseif (!$hasTopic && ($contextPostCount === null || $postCount < $contextPostCount)) {
            $suggestions[] = $base + [
                'postCount' => $postCount,
                'mode' => 'keyword',
                'typeRank' => 1,
            ];
        }
    }
    usort($suggestions, static function (array $left, array $right): int {
        foreach (['matchQuality', 'postCount', 'typeRank'] as $field) {
            $comparison = (int) $right[$field] <=> (int) $left[$field];
            if ($comparison !== 0) {
                return $comparison;
            }
        }
        return strcasecmp((string) $left['label'], (string) $right['label']);
    });
    if ($limitClause !== '') {
        $suggestions = array_slice($suggestions, 0, 48);
    }
    return array_map(static fn(array $suggestion): array => [
        'label' => $suggestion['label'],
        'normalized' => $suggestion['normalized'],
        'postCount' => $suggestion['postCount'],
        'mode' => $suggestion['mode'],
        'isTopic' => $suggestion['mode'] === 'topic',
        'isCombined' => $suggestion['mode'] === 'topic-keyword',
        'description' => $suggestion['description'],
    ], $suggestions);
}
