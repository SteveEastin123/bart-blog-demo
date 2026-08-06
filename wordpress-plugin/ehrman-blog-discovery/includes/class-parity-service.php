<?php

namespace EhrmanBlogDiscovery;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

if (!defined('ABSPATH')) {
    exit;
}

final class Parity_Service
{
    public const SCHEMA_VERSION = 1;
    public const MAX_BATCH_CASES = 200;

    private const SOURCE_FILES = array(
        'data/index/ehrman_post_search_index.json' => 'ehrman_post_search_index.json',
        'data/index/ehrman_post_topics.json' => 'ehrman_post_topics.json',
        'data/index/ehrman_post_categories.json' => 'ehrman_post_categories.json',
        'data/index/ehrman_post_subject_areas.json' => 'ehrman_post_subject_areas.json',
        'data/index/ehrman_post_subject_areas_2.json' => 'ehrman_post_subject_areas_2.json',
    );

    private Search_Service $search;

    public function __construct()
    {
        $this->search = new Search_Service();
    }

    public static function configured_token(): string
    {
        if (defined('EHRMAN_DISCOVERY_PARITY_TOKEN')) {
            return trim((string) constant('EHRMAN_DISCOVERY_PARITY_TOKEN'));
        }

        return trim((string) getenv('EHRMAN_DISCOVERY_PARITY_TOKEN'));
    }

    public function run_batch($cases): array
    {
        if (!is_array($cases) || !array_is_list($cases)) {
            throw new InvalidArgumentException('cases must be a list');
        }
        if (empty($cases)) {
            throw new InvalidArgumentException('cases must not be empty');
        }
        if (count($cases) > self::MAX_BATCH_CASES) {
            throw new InvalidArgumentException(
                'a batch supports at most ' . self::MAX_BATCH_CASES . ' cases'
            );
        }

        $results = array();
        foreach ($cases as $case) {
            if (!is_array($case) || array_is_list($case)) {
                $results[] = array('id' => '', 'ok' => false, 'error' => 'case must be an object');
                continue;
            }
            try {
                $results[] = $this->execute_case($case);
            }
            catch (InvalidArgumentException | RuntimeException $error) {
                $results[] = array(
                    'id' => $this->clean_string($case['id'] ?? null),
                    'ok' => false,
                    'error' => $error->getMessage(),
                );
            }
            catch (Throwable $error) {
                $results[] = array(
                    'id' => $this->clean_string($case['id'] ?? null),
                    'ok' => false,
                    'error' => 'Parity case failed.',
                );
            }
        }

        return array_merge($this->manifest(), array('results' => $results));
    }

    private function execute_case(array $case): array
    {
        $id = $this->clean_string($case['id'] ?? null);
        if ('' === $id) {
            throw new InvalidArgumentException('each case requires a non-empty id');
        }

        $operation = $this->clean_string($case['operation'] ?? null);
        if ('search' === $operation) {
            $result = $this->search_case($case);
        }
        elseif ('suggest' === $operation) {
            $result = $this->suggest_case($case);
        }
        elseif ('browse' === $operation) {
            $result = $this->browse_case();
        }
        else {
            throw new InvalidArgumentException("unknown operation: {$operation}");
        }

        return array_merge(array('id' => $id, 'ok' => true), $result);
    }

    private function search_case(array $case): array
    {
        $terms = $this->string_list($case['terms'] ?? null, 'terms', Search_Service::MAX_TERMS);
        $sort = $this->normalized_sort($case['sort'] ?? null);
        $scope = $case['scope'] ?? array('type' => 'global');
        if (!is_array($scope) || array_is_list($scope)) {
            throw new InvalidArgumentException('scope must be an object');
        }

        $scope_type = $this->clean_string($scope['type'] ?? null) ?: 'global';
        $scope_slug = $this->clean_string($scope['slug'] ?? null);
        $display_terms = $terms;

        if ('global' === $scope_type) {
            $result = $this->search->search($terms, $sort);
        }
        elseif ('category' === $scope_type) {
            if ('' === $scope_slug) {
                throw new InvalidArgumentException('category scope requires a slug');
            }
            $category = $this->record_by_slug('categories', $scope_slug);
            if (null === $category) {
                throw new InvalidArgumentException("unknown category slug: {$scope_slug}");
            }
            $result = $this->search->search($terms, $sort, $scope_slug);
        }
        elseif ('topic' === $scope_type) {
            if ('' === $scope_slug) {
                throw new InvalidArgumentException('topic scope requires a slug');
            }
            $topic = $this->record_by_slug('topics', $scope_slug);
            if (null === $topic) {
                throw new InvalidArgumentException("unknown topic slug: {$scope_slug}");
            }
            $display_terms = empty($terms) ? array((string) $topic['name']) : $terms;
            $ranking_terms = empty($terms) ? $display_terms : $terms;
            $result = $this->search->search($ranking_terms, $sort, '', $scope_slug);
        }
        else {
            throw new InvalidArgumentException("unknown search scope: {$scope_type}");
        }

        return array(
            'operation' => 'search',
            'terms' => $terms,
            'displayTerms' => $display_terms,
            'sort' => $sort,
            'scope' => array('type' => $scope_type, 'slug' => $scope_slug),
            'resultCount' => (int) $result['count'],
            'posts' => $this->serialize_posts($result['posts']),
        );
    }

    private function suggest_case(array $case): array
    {
        $selected = $this->string_list(
            $case['selected'] ?? null,
            'selected',
            Search_Service::MAX_TERMS
        );
        $query = $this->clean_string($case['query'] ?? null);
        $category_slug = $this->clean_string($case['categorySlug'] ?? null);
        $topic_slug = $this->clean_string($case['topicSlug'] ?? null);
        $suggestions = $this->search->suggestions(
            $query,
            $selected,
            $category_slug,
            $topic_slug
        );

        return array(
            'operation' => 'suggest',
            'query' => $query,
            'normalizedQuery' => Search_Service::normalize($query),
            'selected' => $selected,
            'categorySlug' => $category_slug,
            'topicSlug' => $topic_slug,
            'suggestionCount' => count($suggestions),
            'suggestions' => $suggestions,
        );
    }

    private function browse_case(): array
    {
        return array(
            'operation' => 'browse',
            'subjectAreas1' => $this->subject_area_records('browse-topics-1'),
            'subjectAreas2' => $this->subject_area_records('browse-topics-2'),
            'categories' => $this->category_records(),
            'topics' => $this->topic_records(),
        );
    }

    private function subject_area_records(string $path_slug): array
    {
        global $wpdb;
        $tables = Database::tables();
        $path_id = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$tables['browse_paths']} WHERE slug=%s", $path_slug)
        );
        $areas = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$tables['subject_areas']} WHERE browse_path_id=%d ORDER BY id",
                $path_id
            ),
            ARRAY_A
        );
        $records = array();

        foreach ($areas as $area) {
            $categories = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT c.name,c.slug,c.description,COUNT(DISTINCT tc.topic_id) topic_count,"
                    . "COUNT(DISTINCT pt.post_id) post_count FROM {$tables['subject_area_categories']} sac "
                    . "JOIN {$tables['categories']} c ON c.id=sac.category_id "
                    . "LEFT JOIN {$tables['topic_categories']} tc ON tc.category_id=c.id "
                    . "LEFT JOIN {$tables['post_topics']} pt ON pt.topic_id=tc.topic_id "
                    . 'WHERE sac.subject_area_id=%d GROUP BY c.id '
                    . 'ORDER BY sac.position,c.name',
                    (int) $area['id']
                ),
                ARRAY_A
            );
            $topic_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT tc.topic_id) FROM {$tables['subject_area_categories']} sac "
                    . "JOIN {$tables['topic_categories']} tc ON tc.category_id=sac.category_id "
                    . 'WHERE sac.subject_area_id=%d',
                    (int) $area['id']
                )
            );
            $post_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT pt.post_id) FROM {$tables['subject_area_categories']} sac "
                    . "JOIN {$tables['topic_categories']} tc ON tc.category_id=sac.category_id "
                    . "JOIN {$tables['post_topics']} pt ON pt.topic_id=tc.topic_id "
                    . 'WHERE sac.subject_area_id=%d',
                    (int) $area['id']
                )
            );

            $records[] = array(
                'name' => (string) $area['name'],
                'slug' => (string) $area['slug'],
                'description' => (string) $area['description'],
                'categoryCount' => count($categories),
                'topicCount' => $topic_count,
                'postCount' => $post_count,
                'categories' => array_map(
                    static fn(array $category): array => array(
                        'name' => (string) $category['name'],
                        'slug' => (string) $category['slug'],
                        'description' => (string) $category['description'],
                        'topicCount' => (int) $category['topic_count'],
                        'postCount' => (int) $category['post_count'],
                    ),
                    $categories
                ),
            );
        }

        return $records;
    }

    private function category_records(): array
    {
        global $wpdb;
        $tables = Database::tables();
        $categories = $wpdb->get_results(
            "SELECT * FROM {$tables['categories']} ORDER BY name",
            ARRAY_A
        );
        $records = array();

        foreach ($categories as $category) {
            $topics = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT t.name,t.slug,t.description,t.display_in_browser,"
                    . "COUNT(DISTINCT pt.post_id) post_count FROM {$tables['topics']} t "
                    . "JOIN {$tables['topic_categories']} tc ON tc.topic_id=t.id "
                    . "LEFT JOIN {$tables['post_topics']} pt ON pt.topic_id=t.id "
                    . 'WHERE tc.category_id=%d AND t.display_in_browser=1 GROUP BY t.id '
                    . 'ORDER BY CASE WHEN tc.position>0 THEN 0 ELSE 1 END,tc.position,t.name',
                    (int) $category['id']
                ),
                ARRAY_A
            );
            $post_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT pt.post_id) FROM {$tables['post_topics']} pt "
                    . "JOIN {$tables['topic_categories']} tc ON tc.topic_id=pt.topic_id "
                    . 'WHERE tc.category_id=%d',
                    (int) $category['id']
                )
            );
            $records[] = array(
                'name' => (string) $category['name'],
                'slug' => (string) $category['slug'],
                'description' => (string) $category['description'],
                'topicCount' => count($topics),
                'postCount' => $post_count,
                'topics' => array_map(
                    static fn(array $topic): array => array(
                        'name' => (string) $topic['name'],
                        'slug' => (string) $topic['slug'],
                        'description' => (string) $topic['description'],
                        'postCount' => (int) $topic['post_count'],
                    ),
                    $topics
                ),
            );
        }

        return $records;
    }

    private function topic_records(): array
    {
        global $wpdb;
        $tables = Database::tables();
        $topics = $wpdb->get_results(
            "SELECT t.name,t.slug,t.description,t.display_in_browser,"
            . "COUNT(DISTINCT pt.post_id) post_count FROM {$tables['topics']} t "
            . "LEFT JOIN {$tables['post_topics']} pt ON pt.topic_id=t.id "
            . 'GROUP BY t.id ORDER BY t.name',
            ARRAY_A
        );
        $records = array();

        foreach ($topics as $topic) {
            $categories = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT c.name,c.slug FROM {$tables['categories']} c "
                    . "JOIN {$tables['topic_categories']} tc ON tc.category_id=c.id "
                    . "JOIN {$tables['topics']} t ON t.id=tc.topic_id "
                    . 'WHERE t.slug=%s ORDER BY c.name',
                    (string) $topic['slug']
                ),
                ARRAY_A
            );
            $records[] = array(
                'name' => (string) $topic['name'],
                'slug' => (string) $topic['slug'],
                'description' => (string) $topic['description'],
                'displayInBrowser' => (bool) $topic['display_in_browser'],
                'postCount' => (int) $topic['post_count'],
                'categories' => array_map(
                    static fn(array $category): array => array(
                        'name' => (string) $category['name'],
                        'slug' => (string) $category['slug'],
                    ),
                    $categories
                ),
            );
        }

        return $records;
    }

    private function serialize_posts(array $posts): array
    {
        $records = array();
        foreach ($posts as $index => $post) {
            $records[] = array(
                'position' => $index + 1,
                'url' => (string) $post['url'],
                'wpId' => empty($post['source_wp_id']) ? '' : (string) $post['source_wp_id'],
                'title' => (string) $post['title'],
                'dateIso' => substr((string) $post['published_at'], 0, 10),
            );
        }
        return $records;
    }

    private function manifest(): array
    {
        global $wpdb;
        $tables = Database::tables();
        $path_counts = array();
        foreach (array(1 => 'browse-topics-1', 2 => 'browse-topics-2') as $number => $slug) {
            $path_counts[$number] = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$tables['subject_areas']} sa "
                    . "JOIN {$tables['browse_paths']} bp ON bp.id=sa.browse_path_id WHERE bp.slug=%s",
                    $slug
                )
            );
        }

        return array(
            'schemaVersion' => self::SCHEMA_VERSION,
            'implementation' => 'wordpress-mysql',
            'commit' => (string) getenv('RENDER_GIT_COMMIT'),
            'dataFingerprint' => $this->source_fingerprints(),
            'runtime' => array(
                'php' => PHP_VERSION,
                'mysql' => (string) $wpdb->db_version(),
                'wordpress' => get_bloginfo('version'),
            ),
            'counts' => array(
                'posts' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['external_posts']}"),
                'subjectAreas1' => $path_counts[1],
                'subjectAreas2' => $path_counts[2],
                'categories' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['categories']}"),
                'topics' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['topics']}"),
                'secondaryKeywords' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['keywords']}"),
                'searchTerms' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['post_search_terms']}"),
            ),
        );
    }

    private function source_fingerprints(): array
    {
        $importer = new Importer();
        $context = hash_init('sha256');
        $files = array();
        foreach (self::SOURCE_FILES as $relative_path => $filename) {
            $path = $importer->source_directory() . '/' . $filename;
            if (!is_readable($path)) {
                throw new RuntimeException("Unable to fingerprint {$filename}.");
            }
            $digest = hash_file('sha256', $path);
            if (false === $digest) {
                throw new RuntimeException("Unable to fingerprint {$filename}.");
            }
            hash_update($context, $relative_path . "\0" . $digest . "\0");
            $files[] = array(
                'path' => $relative_path,
                'sha256' => $digest,
                'bytes' => (int) filesize($path),
            );
        }

        return array('sha256' => hash_final($context), 'files' => $files);
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

    private function string_list($value, string $field, ?int $maximum = null): array
    {
        if (null === $value) {
            return array();
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException("{$field} must be a list of strings");
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new InvalidArgumentException("{$field} must be a list of strings");
            }
        }
        $values = Search_Service::unique_terms($value);
        if (null !== $maximum && count($values) > $maximum) {
            throw new InvalidArgumentException("{$field} supports at most {$maximum} unique values");
        }
        return $values;
    }

    private function normalized_sort($value): string
    {
        $sort = $this->clean_string($value);
        return in_array($sort, array('ranked', 'newest', 'oldest'), true) ? $sort : 'ranked';
    }

    private function clean_string($value): string
    {
        return null === $value ? '' : trim((string) $value);
    }
}
