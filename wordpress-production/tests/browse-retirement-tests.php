<?php
/** Browse retirement fixtures. Temporary tables and rolled-back pages; no API calls. */

use EhrmanBlogDiscovery\Browse_Upgrade;
use EhrmanBlogDiscovery\Database;
use EhrmanBlogDiscovery\Page_Controller;

$browse_tables = Database::tables();
$temporary = [];
try {
    // Session-local tables shadow the real hierarchy without changing live data.
    foreach (['browse_paths', 'subject_areas', 'subject_area_categories'] as $key) {
        $table = $browse_tables[$key];
        $definition = $wpdb->get_row("SHOW CREATE TABLE $table", ARRAY_N);
        $assert(is_array($definition), 'Could not read fixture schema.');
        $assert(false !== $wpdb->query(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $definition[1])), 'Could not create upgrade fixture.');
        $temporary[] = $table;
    }
    foreach ([1, 2] as $id) {
        $wpdb->insert($browse_tables['browse_paths'], ['id' => $id, 'name' => "Browse Topics $id", 'slug' => "browse-topics-$id", 'description' => 'Fixture']);
        $wpdb->insert($browse_tables['subject_areas'], ['id' => $id, 'browse_path_id' => $id, 'name' => "Area $id", 'slug' => "area-$id", 'description' => 'Fixture']);
        $wpdb->insert($browse_tables['subject_area_categories'], ['subject_area_id' => $id, 'category_id' => 1]);
    }
    $fail_query = static fn(string $sql): string => str_starts_with($sql, "DELETE FROM {$browse_tables['browse_paths']}") ? 'INVALID BROWSE UPGRADE FIXTURE' : $sql;
    add_filter('query', $fail_query);
    $suppressed = $wpdb->suppress_errors();
    try {
        $failed = false;
        try { Browse_Upgrade::migrate_data(); } catch (RuntimeException $error) { $failed = true; }
        $assert($failed, 'Injected migration failure did not propagate.');
        foreach ($temporary as $table) {
            $assert_same(2, (int) $wpdb->get_var("SELECT COUNT(*) FROM $table"), 'Failed upgrade did not roll back.');
        }
    } finally { remove_filter('query', $fail_query); $wpdb->suppress_errors($suppressed); }
    Browse_Upgrade::migrate_data();
    Browse_Upgrade::migrate_data();
    $assert_same('browse-topics', $wpdb->get_var("SELECT slug FROM {$browse_tables['browse_paths']} WHERE id=1"), 'First structure was not renamed.');
    foreach ($temporary as $table) { $assert_same(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM $table"), 'Retired hierarchy rows remain.'); }
    $assert_same(1, (int) $wpdb->get_var("SELECT browse_path_id FROM {$browse_tables['subject_areas']}"), 'Surviving structure changed ID.');
} finally {
    foreach (array_reverse($temporary) as $table) { $wpdb->query("DROP TEMPORARY TABLE $table"); }
}
$counts_before = Database::counts();
$saved_post = $GLOBALS['post'] ?? null;
foreach (['fresh', 'legacy', 'both', 'conflict'] as $scenario) {
    $wpdb->query('START TRANSACTION');
    try {
        foreach (['browse-topics', 'browse-topics-1', 'browse-topics-2'] as $slug) {
            $existing = get_page_by_path($slug, OBJECT, 'page');
            if ($existing instanceof WP_Post) { wp_update_post(['ID' => $existing->ID, 'post_name' => 'hidden-browse-fixture-' . $existing->ID]); }
        }
        delete_option('ehrman_discovery_page_browse_topics');
        delete_option('ehrman_discovery_page_browse_1');
        $new = static fn(string $slug, string $content): int => wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => $slug, 'post_name' => $slug, 'post_content' => $content]);
        $first = $scenario === 'fresh' ? 0 : $new('browse-topics-1', 'Introduction. [ehrman_browse_topics path="1"] Extra text.');
        if ($first) { update_option('ehrman_discovery_page_browse_1', $first); }
        $second = $new('browse-topics-2', '[ehrman_browse_topics path="2"]');
        $canonical = in_array($scenario, ['both', 'conflict'], true) ? $new('browse-topics', $scenario === 'conflict' ? 'Unrelated content' : '[ehrman_browse_topics]') : 0;
        $GLOBALS['post'] = get_post($second);
        Page_Controller::ensure_pages();
        if ($scenario === 'conflict') {
            $assert_same('Unrelated content', get_post_field('post_content', $canonical), 'Unrelated page overwritten.');
            $assert_same(0, (int) get_option('ehrman_discovery_page_browse_topics'), 'Conflicting page adopted.');
            continue;
        }
        $id = (int) get_option('ehrman_discovery_page_browse_topics');
        $assert($id > 0 && $id !== $second, 'Canonical page was not created correctly.');
        $assert_same('Browse Topics', get_the_title($id), 'Title incorrect.');
        $assert_same(home_url('/browse-topics/'), get_permalink($id), 'URL incorrect.');
        $assert_same('draft', get_post_status($second), 'Second page still public.');
        if ($first) { $assert_same($canonical ?: $first, $id, 'First page ID not preserved.'); }
        if ($scenario === 'legacy') { $assert_same('Introduction. [ehrman_browse_topics] Extra text.', get_post_field('post_content', $id), 'Surrounding content lost.'); }
        $page_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");
        Page_Controller::ensure_pages();
        $assert_same($page_count, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}"), 'Repeat upgrade created pages.');
        $assert_same($counts_before, Database::counts(), 'Page upgrade changed discovery data.');
    } finally { $wpdb->query('ROLLBACK'); wp_cache_flush(); $GLOBALS['post'] = $saved_post; }
}
echo "Browse retirement: transactional upgrade, rollback, page reuse, conflict protection and idempotence OK\n";
