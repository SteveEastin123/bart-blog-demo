<?php
/** Deterministic engine-parity capture; no external API calls or persistent writes. */

define('DISABLE_WP_CRON', true);
define('WP_HTTP_BLOCK_EXTERNAL', true);
require '/var/www/html/wp-load.php';

use EhrmanBlogDiscovery\AI_Usage;
use EhrmanBlogDiscovery\Browse_Service;
use EhrmanBlogDiscovery\Database;
use EhrmanBlogDiscovery\Embedding_Service;
use EhrmanBlogDiscovery\Search_Service;
use EhrmanBlogDiscovery\Semantic_Search_Service;

add_filter('pre_http_request', static fn() => new WP_Error('migration_no_network', 'External requests are disabled.'), PHP_INT_MAX);
$wpdb = Database::client();
$tables = Database::tables();
$search = new Search_Service();
$browse = new Browse_Service();
$hashes = [];
$capture = static function (string $key, mixed $result) use (&$hashes): void {
    if (is_wp_error($result)) {
        throw new RuntimeException($key . ': ' . $result->get_error_message());
    }
    // Preserve binary/non-UTF-8 fields too; do not silently replace bytes.
    $hashes[$key] = hash('sha256', serialize($result));
};
$wpdb->query('START TRANSACTION');
try {
    foreach ([1, 2] as $path) {
        $areas = $browse->subject_areas($path);
        $capture('browse-path-' . $path, $areas);
        foreach ($areas as $area) {
            $capture('browse-area-' . $area['id'], $browse->subject_area_categories((int) $area['id']));
        }
    }
    $capture('category-index', $browse->categories());
    $capture('topic-index', $browse->topics());
    foreach ($browse->categories() as $category) {
        $capture('category-topics-' . $category['id'], $browse->category_topics((int) $category['id']));
        $capture('category-search-' . $category['id'], $search->search([], 'ranked', $category['slug']));
    }
    $topics = $wpdb->get_col("SELECT name FROM {$tables['topics']} ORDER BY id");
    foreach ($topics as $topic) {
        foreach (['ranked', 'oldest', 'newest'] as $sort) {
            foreach (['topic', 'topic-keyword'] as $mode) {
                $capture('topic-' . $topic . '-' . $sort . '-' . $mode, $search->search([$topic], $sort, '', '', 1, 0, [$mode]));
            }
        }
    }
    $keywords = $wpdb->get_col("SELECT label FROM {$tables['keywords']} ORDER BY id");
    foreach ($keywords as $keyword) {
        $capture('keyword-' . $keyword, $search->search([$keyword], 'ranked', '', '', 1, 0, ['keyword']));
    }
    foreach ([['Luke', 'Atonement'], ['Women', 'Paul'], ['Textual Criticism', 'Bruce Metzger'], ['Colossians', 'Raised Up']] as $terms) {
        $capture('combined-' . implode('|', $terms), $search->search($terms));
    }
    // Fixed existing post vectors exercise the entire semantic retrieval path on
    // both engines. These are parity fixtures, not relevance-quality judgments.
    $fixtures = $wpdb->get_results("SELECT p.title, e.embedding FROM {$tables['post_embeddings']} e JOIN {$tables['external_posts']} p ON p.source_wp_id=e.source_wp_id ORDER BY e.source_wp_id LIMIT 20", ARRAY_A);
    if (count($fixtures) !== 20) {
        throw new RuntimeException('Expected 20 semantic parity fixtures.');
    }
    foreach ($fixtures as $index => $fixture) {
        $question = sanitize_text_field($fixture['title']);
        $key = 'ebd_semantic_query_' . hash('sha256', Search_Service::normalize($question) . '|' . Embedding_Service::model_id() . '|' . Embedding_Service::dimensions() . '|' . AI_Usage::cache_version());
        $vector = array_values(unpack('g*', $fixture['embedding']));
        if (count($vector) !== Embedding_Service::dimensions()) {
            throw new RuntimeException('Invalid semantic fixture dimensions.');
        }
        set_transient($key, $vector, 600);
        $capture('semantic-' . $index, (new Semantic_Search_Service())->search($question, 'migration-parity-' . $index));
    }
    if ($wpdb->last_error) {
        throw new RuntimeException('Search parity encountered a database error.');
    }
} finally {
    $wpdb->query('ROLLBACK');
}
ksort($hashes);
echo json_encode(['case_count' => count($hashes), 'hashes' => $hashes], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
