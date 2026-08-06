<?php

namespace EhrmanBlogDiscovery;

if (!defined('ABSPATH')) {
    exit;
}

final class Search_Service
{
    public const MAX_TERMS = 4;
    public const MAX_TERM_LENGTH = 191;

    public function search(
        array $terms,
        string $sort = 'ranked',
        string $category_slug = '',
        string $topic_slug = ''
    ): array {
        $terms = self::unique_terms($terms);
        $sort = self::clean_sort($sort);
        $category_slug = sanitize_title($category_slug);
        $topic_slug = sanitize_title($topic_slug);
        $eligible = null;
        $topic = null;

        if ('' !== $category_slug) {
            $category = $this->record_by_slug('categories', $category_slug);
            if (null === $category) {
                return $this->search_result(array(), $terms, $sort);
            }
            $eligible = $this->category_post_ids((int) $category['id']);
        }

        if ('' !== $topic_slug) {
            $topic = $this->record_by_slug('topics', $topic_slug);
            if (null === $topic) {
                return $this->search_result(array(), $terms, $sort);
            }
            $eligible = self::intersect_id_sets($eligible, $this->topic_post_ids((int) $topic['id']));
        }

        if (null === $eligible && empty($terms)) {
            return $this->search_result(array(), $terms, $sort);
        }

        $filter_terms = $terms;
        if (null !== $topic) {
            $topic_normalized = self::normalize((string) $topic['name']);
            $filter_terms = array_values(
                array_filter(
                    $terms,
                    static fn(string $term): bool => self::normalize($term) !== $topic_normalized
                )
            );
        }

        $scores = null === $eligible ? null : array_fill_keys(array_keys($eligible), 0);
        foreach ($filter_terms as $term) {
            $scores = self::intersect_scores($scores, $this->post_scores_for_term($term));
        }

        if (null === $scores) {
            $scores = array();
        }
        if (empty($scores)) {
            return $this->search_result(array(), $terms, $sort);
        }

        $posts = $this->posts_by_ids(array_keys($scores));
        $posts = $this->sort_posts($posts, $sort, $terms, $scores);

        return $this->search_result($posts, $terms, $sort);
    }

    public function suggestions(
        string $query,
        array $selected = array(),
        string $category_slug = '',
        string $topic_slug = ''
    ): array {
        global $wpdb;

        $tables = Database::tables();
        $query_normalized = self::normalize($query);
        $selected = self::unique_terms($selected);
        $selected_normalized = array_values(
            array_unique(array_map(array(self::class, 'normalize'), $selected))
        );
        sort($selected_normalized, SORT_STRING);
        $category_slug = sanitize_title($category_slug);
        $topic_slug = sanitize_title($topic_slug);

        if ('' === $query_normalized && empty($selected) && '' === $category_slug && '' === $topic_slug) {
            return array();
        }

        $eligible = null;
        $allowed_category_topics = array();
        if ('' !== $category_slug) {
            $category = $this->record_by_slug('categories', $category_slug);
            if (null === $category) {
                return array();
            }
            $eligible = $this->category_post_ids((int) $category['id']);
            $sql = "SELECT t.name FROM {$tables['topics']} t "
                . "JOIN {$tables['topic_categories']} tc ON tc.topic_id=t.id "
                . 'WHERE tc.category_id=%d AND t.display_in_browser=1';
            $allowed_category_topics = $wpdb->get_col($wpdb->prepare($sql, (int) $category['id']));
        }

        if ('' !== $topic_slug) {
            $topic = $this->record_by_slug('topics', $topic_slug);
            if (null === $topic) {
                return array();
            }
            $eligible = self::intersect_id_sets($eligible, $this->topic_post_ids((int) $topic['id']));
        }

        foreach ($selected as $term) {
            $eligible = self::intersect_id_sets(
                $eligible,
                array_fill_keys(array_keys($this->post_scores_for_term($term)), true)
            );
        }
        if (is_array($eligible) && empty($eligible)) {
            return array();
        }

        $where = array("normalized <> 'ignore'");
        $params = array($query_normalized, $query_normalized . '%', '% ' . $query_normalized . '%');
        if ('' !== $query_normalized) {
            $where[] = '(normalized LIKE %s OR normalized LIKE %s)';
            $params[] = $query_normalized . '%';
            $params[] = '% ' . $query_normalized . '%';
        }
        if (is_array($eligible)) {
            $ids = array_keys($eligible);
            sort($ids, SORT_NUMERIC);
            $where[] = 'post_id IN (' . self::integer_list($ids) . ')';
        }
        if ('' !== $category_slug) {
            if (!empty($allowed_category_topics)) {
                $where[] = "(kind <> 'topic' OR label IN ("
                    . implode(',', array_fill(0, count($allowed_category_topics), '%s')) . '))';
                array_push($params, ...$allowed_category_topics);
            } else {
                $where[] = "kind <> 'topic'";
            }
        }
        if (!empty($selected_normalized)) {
            $where[] = 'normalized NOT IN ('
                . implode(',', array_fill(0, count($selected_normalized), '%s')) . ')';
            array_push($params, ...$selected_normalized);
        }

        $limit = '' !== $category_slug && '' === $query_normalized && empty($selected) && '' === $topic_slug
            ? ''
            : ' LIMIT 48';
        $sql = "SELECT COALESCE(MIN(CASE WHEN kind='topic' THEN label END),MIN(label)) label, "
            . 'normalized, COUNT(DISTINCT post_id) post_count, '
            . "MAX(CASE WHEN kind='topic' THEN 1 ELSE 0 END) is_topic, "
            . 'CASE WHEN normalized=%s THEN 3 WHEN normalized LIKE %s THEN 2 '
            . 'WHEN normalized LIKE %s THEN 1 ELSE 1 END match_quality '
            . "FROM {$tables['post_search_terms']} WHERE " . implode(' AND ', $where)
            . ' GROUP BY normalized ORDER BY match_quality DESC,post_count DESC,is_topic DESC,label ASC'
            . $limit;
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        if (empty($rows)) {
            return array();
        }

        $candidate_normalized = array_fill_keys(array_column($rows, 'normalized'), true);
        $count_where = '';
        if (is_array($eligible)) {
            $ids = array_keys($eligible);
            sort($ids, SORT_NUMERIC);
            $count_where = ' WHERE post_id IN (' . self::integer_list($ids) . ')';
        }
        $count_rows = $wpdb->get_results(
            "SELECT DISTINCT post_id,normalized FROM {$tables['post_search_terms']}{$count_where}",
            ARRAY_A
        );
        $matching_posts = array_fill_keys(array_keys($candidate_normalized), array());
        foreach ($count_rows as $count_row) {
            $indexed = (string) $count_row['normalized'];
            $post_id = (int) $count_row['post_id'];
            foreach ($candidate_normalized as $candidate => $_unused) {
                if ($indexed === $candidate || str_contains(" {$indexed} ", " {$candidate} ")) {
                    $matching_posts[$candidate][$post_id] = true;
                }
            }
        }

        $description_rows = $wpdb->get_results(
            "SELECT name,description FROM {$tables['topics']} WHERE display_in_browser=1",
            ARRAY_A
        );
        $topic_descriptions = array();
        foreach ($description_rows as $row) {
            $topic_descriptions[self::normalize((string) $row['name'])] = (string) $row['description'];
        }

        $suggestions = array();
        foreach ($rows as $row) {
            $normalized = (string) $row['normalized'];
            $post_count = count($matching_posts[$normalized] ?? array());
            if (0 === $post_count) {
                continue;
            }
            $is_topic = 1 === (int) $row['is_topic'];
            $suggestions[] = array(
                'label' => (string) $row['label'],
                'normalized' => $normalized,
                'postCount' => $post_count,
                'isTopic' => $is_topic,
                'matchQuality' => (int) $row['match_quality'],
                'description' => $is_topic ? ($topic_descriptions[$normalized] ?? '') : '',
            );
        }

        usort(
            $suggestions,
            static function (array $left, array $right): int {
                foreach (array('matchQuality', 'postCount', 'isTopic') as $field) {
                    $comparison = (int) $right[$field] <=> (int) $left[$field];
                    if (0 !== $comparison) {
                        return $comparison;
                    }
                }
                return strcasecmp((string) $left['label'], (string) $right['label']);
            }
        );

        return array_map(
            static fn(array $item): array => array(
                'label' => $item['label'],
                'normalized' => $item['normalized'],
                'postCount' => $item['postCount'],
                'isTopic' => $item['isTopic'],
                'description' => $item['description'],
            ),
            $suggestions
        );
    }

    public static function normalize($value): string
    {
        $text = strtolower(str_replace('&', ' and ', trim((string) $value)));
        $text = trim((string) preg_replace('/[^a-z0-9]+/', ' ', $text));
        return (string) preg_replace('/\s+/', ' ', $text);
    }

    public static function unique_terms(array $terms): array
    {
        $values = array();
        $seen = array();
        foreach ($terms as $term) {
            $value = sanitize_text_field((string) $term);
            $value = function_exists('mb_substr')
                ? mb_substr($value, 0, self::MAX_TERM_LENGTH)
                : substr($value, 0, self::MAX_TERM_LENGTH);
            $normalized = self::normalize($value);
            if ('' === $normalized || isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;
            $values[] = $value;
            if (count($values) >= self::MAX_TERMS) {
                break;
            }
        }
        return $values;
    }

    private function record_by_slug(string $table_key, string $slug): ?array
    {
        global $wpdb;
        $tables = Database::tables();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$tables[$table_key]} WHERE slug=%s LIMIT 1", $slug),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    private function category_post_ids(int $category_id): array
    {
        global $wpdb;
        $tables = Database::tables();
        $sql = "SELECT DISTINCT pt.post_id FROM {$tables['post_topics']} pt "
            . "JOIN {$tables['topic_categories']} tc ON tc.topic_id=pt.topic_id WHERE tc.category_id=%d";
        return array_fill_keys(array_map('intval', $wpdb->get_col($wpdb->prepare($sql, $category_id))), true);
    }

    private function topic_post_ids(int $topic_id): array
    {
        global $wpdb;
        $tables = Database::tables();
        $sql = "SELECT post_id FROM {$tables['post_topics']} WHERE topic_id=%d";
        return array_fill_keys(array_map('intval', $wpdb->get_col($wpdb->prepare($sql, $topic_id))), true);
    }

    private function post_scores_for_term(string $term): array
    {
        global $wpdb;
        $tables = Database::tables();
        $normalized = self::normalize($term);
        if ('' === $normalized) {
            return array();
        }
        $sql = "SELECT post_id,MAX(weight+CASE WHEN normalized=%s THEN 2 ELSE 0 END) score "
            . "FROM {$tables['post_search_terms']} WHERE normalized=%s "
            . "OR CONCAT(' ',normalized,' ') LIKE %s GROUP BY post_id";
        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, $normalized, $normalized, "% {$normalized} %"),
            ARRAY_A
        );
        $matches = array();
        foreach ($rows as $row) {
            $matches[(int) $row['post_id']] = (int) $row['score'];
        }
        return $matches;
    }

    private function posts_by_ids(array $post_ids): array
    {
        global $wpdb;
        if (empty($post_ids)) {
            return array();
        }
        $tables = Database::tables();
        $sql = "SELECT * FROM {$tables['external_posts']} WHERE id IN (" . self::integer_list($post_ids) . ')';
        return $wpdb->get_results($sql, ARRAY_A);
    }

    private function sort_posts(array $posts, string $sort, array $terms, array $scores): array
    {
        if ('ranked' === $sort) {
            foreach ($posts as $post) {
                $post_id = (int) $post['id'];
                $score = (int) ($scores[$post_id] ?? 0);
                foreach ($terms as $term) {
                    $score += self::title_boost((string) $post['title'], $term);
                    $score += self::description_boost((string) $post['description'], $term);
                }
                $scores[$post_id] = $score;
            }
        }

        usort(
            $posts,
            static function (array $left, array $right) use ($sort, $scores): int {
                if ('ranked' === $sort) {
                    $comparison = (int) ($scores[(int) $right['id']] ?? 0)
                        <=> (int) ($scores[(int) $left['id']] ?? 0);
                    if (0 !== $comparison) {
                        return $comparison;
                    }
                }
                $date_comparison = strcmp((string) $left['published_at'], (string) $right['published_at']);
                if (0 !== $date_comparison) {
                    return 'oldest' === $sort ? $date_comparison : -$date_comparison;
                }
                $url_comparison = strcasecmp((string) $left['url'], (string) $right['url']);
                return 'oldest' === $sort ? $url_comparison : -$url_comparison;
            }
        );
        return $posts;
    }

    private static function title_boost(string $title, string $term): int
    {
        $title = self::normalize($title);
        $term = self::ranking_term($term);
        if ('' === $title || '' === $term) {
            return 0;
        }
        if (str_contains(" {$title} ", " {$term} ")) {
            return 4;
        }
        if (!str_contains($term, ' ') && in_array($term, explode(' ', $title), true)) {
            return 1;
        }
        $anchor = self::ranking_anchor($term);
        return '' !== $anchor && in_array($anchor, explode(' ', $title), true) ? 2 : 0;
    }

    private static function description_boost(string $description, string $term): int
    {
        $description = self::normalize($description);
        $term = self::ranking_term($term);
        if ('' === $description || '' === $term) {
            return 0;
        }
        if (str_contains(" {$description} ", " {$term} ")) {
            return 2;
        }
        $anchor = self::ranking_anchor($term);
        return '' !== $anchor && in_array($anchor, explode(' ', $description), true) ? 1 : 0;
    }

    private static function ranking_term(string $term): string
    {
        $normalized = self::normalize($term);
        return str_ends_with($normalized, ' general')
            ? rtrim(substr($normalized, 0, -strlen(' general')))
            : $normalized;
    }

    private static function ranking_anchor(string $term): string
    {
        $stopwords = array_fill_keys(
            array('a', 'an', 'and', 'as', 'at', 'belief', 'beliefs', 'by', 'for', 'from', 'general',
                'in', 'into', 'issue', 'issues', 'of', 'on', 'or', 'overview', 'question', 'questions',
                'the', 'to', 'tradition', 'traditions', 'with'),
            true
        );
        $term = self::ranking_term($term);
        if (!str_contains($term, ' ')) {
            return '';
        }
        $tokens = array_values(
            array_filter(
                explode(' ', $term),
                static fn(string $token): bool => strlen($token) >= 4 && !isset($stopwords[$token])
            )
        );
        return empty($tokens) ? '' : (string) end($tokens);
    }

    private static function intersect_scores(?array $left, array $right): array
    {
        if (null === $left) {
            return $right;
        }
        $intersection = array();
        foreach ($left as $post_id => $score) {
            if (isset($right[$post_id])) {
                $intersection[$post_id] = (int) $score + (int) $right[$post_id];
            }
        }
        return $intersection;
    }

    private static function intersect_id_sets(?array $left, array $right): array
    {
        return null === $left ? $right : array_intersect_key($left, $right);
    }

    private static function integer_list(array $values): string
    {
        $values = array_map('intval', $values);
        return empty($values) ? '0' : implode(',', $values);
    }

    private static function clean_sort(string $sort): string
    {
        return in_array($sort, array('ranked', 'newest', 'oldest'), true) ? $sort : 'ranked';
    }

    private function search_result(array $posts, array $terms, string $sort): array
    {
        return array(
            'posts' => array_values($posts),
            'terms' => array_values($terms),
            'sort' => $sort,
            'count' => count($posts),
        );
    }
}
