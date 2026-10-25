<?php
/** Destructive fixture tests: ONLY an empty, disposable installation-test site. */

use EhrmanBlogDiscovery\Data_Installer;
use EhrmanBlogDiscovery\Database;
use EhrmanBlogDiscovery\Embedding_Service;
use EhrmanBlogDiscovery\Importer;
use EhrmanBlogDiscovery\Semantic_Index_Service;

if (!defined('WP_CLI') || !WP_CLI || getenv('EHRMAN_INSTALLATION_TEST') !== '1'
    || !in_array(wp_get_environment_type(), ['local', 'staging', 'development'], true)
    || get_option('blogname') !== 'Ehrman installation verification'
    || array_sum(Database::counts()) !== 0) {
    throw new RuntimeException('Installation tests require an empty disposable site, the verification title and EHRMAN_INSTALLATION_TEST=1.');
}
$check = static function (bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
};
$wpdb = Database::client();
$tables = Database::tables();
$directory = sys_get_temp_dir() . '/ehrman-install-fixtures-' . wp_generate_uuid4();
mkdir($directory, 0700);
$topic = ['name' => 'Test Topic', 'description' => 'A topic.', 'categories' => ['Test Category'], 'aliases' => []];
$post = ['wpId' => '900001', 'title' => 'Installation fixture', 'url' => 'https://example.test/installation-fixture/',
    'author' => 'Fixture Author', 'dateText' => 'October 1, 2026', 'description' => 'Explains fixture installation.',
    'searchSummary' => 'Explains deterministic installation and database rollback.', 'topics' => ['Test Topic'], 'secondaryKeywords' => ['Testing']];
$ignore = array_replace($post, ['wpId' => '900002', 'title' => 'Announcement', 'url' => 'https://example.test/announcement/', 'topics' => ['Ignore']]);
$no_summary = array_replace($post, ['wpId' => '900003', 'title' => 'Legacy unsummarized post', 'url' => 'https://example.test/unsummarized/']);
unset($no_summary['searchSummary']);
$documents = [
    'ehrman_post_search_index.json' => [$post, $ignore, $no_summary],
    'ehrman_post_topics.json' => ['topics' => [$topic, ['name' => 'Ignore', 'description' => 'Informational.', 'categories' => [], 'aliases' => [], 'displayInBrowser' => false]]],
    'ehrman_post_categories.json' => ['categories' => [['name' => 'Test Category', 'description' => 'A category.', 'topicOrder' => ['Test Topic']]]],
    'ehrman_post_subject_areas.json' => ['subjectAreas' => [['name' => 'Test Area One', 'description' => 'An area.', 'categories' => ['Test Category']]]],
    'ehrman_post_subject_areas_2.json' => ['subjectAreas' => [['name' => 'Test Area Two', 'description' => 'An area.', 'categories' => ['Test Category']]]],
];
$write_documents = static function (array $docs) use ($directory): void {
    foreach ($docs as $name => $data) { file_put_contents($directory . '/' . $name, json_encode($data, JSON_THROW_ON_ERROR)); }
};
$write_documents($documents);
$dimensions = Embedding_Service::dimensions();
$header = ['format' => 'ehrman-post-embeddings', 'version' => 1, 'contentBasis' => 'title-summary-v1',
    'model' => Embedding_Service::model_id(), 'dimensions' => $dimensions, 'count' => 1, 'createdAt' => gmdate('c')];
$record = ['type' => 'embedding', 'sourceWpId' => 900001,
    'contentHash' => Semantic_Index_Service::content_hash(['title' => $post['title'], 'search_summary' => $post['searchSummary']]),
    'model' => $header['model'], 'dimensions' => $dimensions,
    'embedding' => base64_encode(pack('g*', 1.0, ...array_fill(0, $dimensions - 1, 0.0))),
    'embeddingNorm' => 1.0, 'updatedAt' => '2026-10-01 00:00:00'];
$write_vectors = static function (array $lines) use ($directory): void {
    $handle = gzopen($directory . '/ehrman_post_embeddings.jsonl.gz', 'wb');
    foreach ($lines as $line) { gzwrite($handle, json_encode($line, JSON_THROW_ON_ERROR) . "\n"); }
    gzclose($handle);
};
$write_vectors([$header, $record]);
$snapshot = static function () use ($wpdb, $tables): string {
    $all = [];
    foreach ($tables as $name => $table) {
        if (!Database::table_exists($table)) { $all[$name] = null; continue; }
        $rows = $wpdb->get_results("SELECT * FROM {$table}", ARRAY_A);
        $encoded = array_map('serialize', $rows);
        sort($encoded);
        $all[$name] = $encoded;
    }
    $all['options'] = $wpdb->get_results("SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name IN ('ehrman_discovery_install_receipt','ehrman_discovery_import_checksum','ehrman_discovery_import_version','ehrman_discovery_import_status','ehrman_discovery_last_import_summary') ORDER BY option_name", ARRAY_A);
    return hash('sha256', serialize($all));
};
$expect_failure = static function (callable $work, string $message) use ($check, $snapshot): void {
    $before = $snapshot();
    try { $work(); } catch (Throwable $error) {
        $check(str_contains($error->getMessage(), $message), 'Unexpected error: ' . $error->getMessage());
        $check($snapshot() === $before, 'Failure changed data or installation bookkeeping: ' . $message);
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $message);
};
$calls = 0;
$block_http = static function () use (&$calls) { ++$calls; throw new RuntimeException('Installation must not make API calls.'); };
add_filter('pre_http_request', $block_http);
$saved_source = getenv('EHRMAN_DISCOVERY_POST_SOURCE');
putenv('EHRMAN_DISCOVERY_POST_SOURCE=json');
$installer = new Data_Installer();
try {
    // Remove only the empty fixture site's plugin tables to verify schema creation by the command service.
    foreach ($tables as $table) { $wpdb->query("DROP TABLE {$table}"); }
    $empty = $snapshot();
    $installer->install($directory, false, true);
    $check($snapshot() === $empty, 'Validate-only created tables or changed options.');
    file_put_contents($directory . '/ehrman_post_search_index.json', '{invalid');
    $expect_failure(fn() => $installer->install($directory), 'Invalid JSON');
    $write_documents($documents);
    $bad_docs = $documents;
    $bad_docs['ehrman_post_search_index.json'][0]['topics'] = ['Missing Topic'];
    $write_documents($bad_docs);
    $expect_failure(fn() => $installer->install($directory), 'unknown topic');
    $write_documents($documents);
    foreach ([[$header, $record, $record], [$header], [$header, array_replace($record, ['contentHash' => str_repeat('0', 64)])],
        [array_replace($header, ['dimensions' => $dimensions + 1]), $record]] as $index => $lines) {
        $write_vectors($lines);
        $messages = ['Duplicate sourceWpId', 'header declares', 'stale content hash', 'dimensions do not match'];
        $expect_failure(fn() => $installer->install($directory), $messages[$index]);
    }
    $write_vectors([$header, $record]);
    $result = $installer->install($directory);
    $check(!$result['skipped'] && $result['posts'] === 3 && $result['vectors'] === 1, 'Fresh install counts incorrect.');
    $check((bool) array_filter($result['warnings'], fn($warning) => str_contains($warning, '900003')), 'Missing-summary exclusion was not reported.');
    $check((new Semantic_Index_Service())->status()['ready'], 'Fresh index is not ready.');
    $baseline = $snapshot();
    $check($installer->install($directory)['skipped'], 'Identical rerun was not skipped.');
    $check($snapshot() === $baseline, 'Rerun changed IDs, data or bookkeeping.');
    putenv('EHRMAN_DISCOVERY_POST_SOURCE=mysql');
    $expect_failure(fn() => $installer->install($directory, true), 'Package import is disabled');
    $expect_failure(fn() => (new Importer($directory))->import(true), 'Package import is disabled');
    putenv('EHRMAN_DISCOVERY_POST_SOURCE=json');
    $wpdb->query("UPDATE {$tables['external_posts']} SET description='Editorial change' WHERE source_wp_id=900001");
    $expect_failure(fn() => $installer->install($directory, true), 'changed after installation');
    $wpdb->update($tables['external_posts'], ['description' => $post['description']], ['source_wp_id' => 900001]);
    $check($snapshot() === $baseline, 'Fixture restoration failed.');
    // A second connection holds the install lock, without touching any rows.
    $other = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    $lock = 'ehrman-install-' . hash('sha1', DB_NAME . ':' . $wpdb->prefix);
    $check((string) $other->get_var($other->prepare('SELECT GET_LOCK(%s,0)', $lock)) === '1', 'Test could not acquire lock.');
    try { $expect_failure(fn() => $installer->install($directory), 'Another installation'); }
    finally { $other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)', $lock)); $other->close(); }
    // New valid metadata keeps the same vector hash; a late receipt write failure must roll back both.
    $new_docs = $documents;
    $new_docs['ehrman_post_search_index.json'][0]['description'] = 'Explains a changed description.';
    $write_documents($new_docs);
    $write_vectors([$header, array_replace($record, ['embedding' => base64_encode(pack('g*', 0.0, 1.0, ...array_fill(0, $dimensions - 2, 0.0)))])]);
    $expect_failure(fn() => $installer->install($directory), 'already exists');
    $fail_receipt = static function (string $sql): string {
        return str_starts_with($sql, 'INSERT INTO') && str_contains($sql, "'ehrman_discovery_install_receipt'")
            ? 'INSERT INTO deliberately_missing_installation_test_table VALUES (1)' : $sql;
    };
    $saved_errors = $wpdb->suppress_errors(true);
    add_filter('query', $fail_receipt);
    try { $expect_failure(fn() => $installer->install($directory, true), 'Could not store installation bookkeeping'); }
    finally { remove_filter('query', $fail_receipt); $wpdb->suppress_errors($saved_errors); }
    $check($snapshot() === $baseline, 'Late failure did not roll back all metadata and vectors.');
    $result = $installer->install($directory, true);
    $check(!$result['skipped'], 'Explicit JSON replacement did not run.');
    $check($installer->install($directory)['skipped'], 'Replacement was not repeatable.');
    $wpdb->query("ALTER TABLE {$tables['post_embeddings']} ENGINE=MyISAM");
    $expect_failure(fn() => $installer->install($directory), 'use InnoDB');
    $wpdb->query("ALTER TABLE {$tables['post_embeddings']} ENGINE=InnoDB");
    $check($calls === 0, 'Installation made an external call.');
    echo "Installation fixture tests: fresh schema, validation, no-op rerun, source protection, drift, lock, rollback and storage-engine guards: OK\n";
} finally {
    remove_filter('pre_http_request', $block_http);
    putenv($saved_source === false ? 'EHRMAN_DISCOVERY_POST_SOURCE' : 'EHRMAN_DISCOVERY_POST_SOURCE=' . $saved_source);
}
