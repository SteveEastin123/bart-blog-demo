<?php
/** Optional demo plugin lifecycle checks. Run through WP-CLI; no AI calls. */

use EhrmanBlogDiscovery\Database;
use EhrmanBlogDiscovery\Page_Controller;
use EhrmanReviewerTools\Plugin as Reviewer_Plugin;

$check = static function (bool $value, string $message): void {
    if (!$value) {
        throw new RuntimeException($message);
    }
};
$check(shortcode_exists('ehrman_structure_review'), 'The optional review shortcode is unavailable.');
$check(!method_exists(Page_Controller::class, 'structure_review_shortcode'), 'Production still owns Reviewer Tools.');
$check(!class_exists('EhrmanBlogDiscovery\\Structure_Review_Renderer'), 'Production still ships the review renderer.');
$wpdb = Database::client();
$page_id = (int) get_option('ehrman_discovery_page_structure_review');
$page_content = get_post_field('post_content', $page_id);
$counts = Database::counts();
$original_query = $GLOBALS['wp_query'];
$wpdb->query('START TRANSACTION');
try {
    Reviewer_Plugin::activate();
    Reviewer_Plugin::activate();
    $check((int) get_option('ehrman_discovery_page_structure_review') === $page_id, 'Activation changed the existing page ID.');
    $check(get_post_status($page_id) === 'publish', 'The demo page is not published.');
    $GLOBALS['wp_query'] = new WP_Query(['page_id' => $page_id]);
    $robots = Reviewer_Plugin::robots([]);
    $check(!empty($robots['noindex']) && !empty($robots['nofollow']), 'The review page is indexable.');
    foreach ([['ebd_path' => '1'], ['ebd_path' => '2'], ['ebd_view' => 'categories'], ['ebd_view' => 'topics']] as $query) {
        $before_get = $_GET;
        try {
            $_GET = $query;
            $html = Reviewer_Plugin::render();
            $check(str_contains($html, 'data-ebd-review-tree'), 'Missing review tree.');
            $check(str_contains($html, 'data-ebd-review-pdf') && str_contains($html, 'data-ebd-review-csv'), 'Missing export controls.');
            $check(!str_contains($html, '[ehrman_structure_review]'), 'Raw shortcode leaked.');
        } finally {
            $_GET = $before_get;
        }
    }
    Reviewer_Plugin::deactivate();
    $check(get_post_status($page_id) === 'draft', 'Deactivation did not hide the page.');
    Page_Controller::ensure_pages();
    $check(get_post_status($page_id) === 'draft', 'Production recreated the review page.');
    ob_start();
    Reviewer_Plugin::navigation();
    Reviewer_Plugin::evaluation_note();
    $check(ob_get_clean() === '', 'Draft review page still appears in the demo navigation.');
    Reviewer_Plugin::activate();
    $check((int) get_option('ehrman_discovery_page_structure_review') === $page_id, 'Reactivation created a duplicate page.');
    $check(get_post_field('post_content', $page_id) === $page_content, 'Page content was altered.');
    $check(Database::counts() === $counts, 'Reviewer Tools changed discovery data.');
    // Fresh installation must not need the old managed page or option.
    wp_update_post(['ID' => $page_id, 'post_name' => 'reviewer-lifecycle-fixture']);
    delete_option('ehrman_discovery_page_structure_review');
    Reviewer_Plugin::activate();
    $created_id = (int) get_option('ehrman_discovery_page_structure_review');
    $check($created_id > 0 && $created_id !== $page_id, 'Fresh installation did not create a page.');
    $check(get_post_field('post_name', $created_id) === 'structure-review', 'Fresh page has the wrong slug.');
    $check(get_post_field('post_content', $created_id) === '[ehrman_structure_review]', 'Fresh page has the wrong content.');
    // Never overwrite an unrelated page at the reserved slug.
    wp_update_post(['ID' => $created_id, 'post_content' => 'Unrelated page content']);
    $die_handler = static fn() => static function ($message): void { throw new RuntimeException((string) $message); };
    add_filter('wp_die_handler', $die_handler);
    try {
        $blocked = false;
        try {
            Reviewer_Plugin::activate();
        } catch (RuntimeException $error) {
            $blocked = str_contains($error->getMessage(), 'unrelated content');
        }
        $check($blocked, 'Activation overwrote a conflicting page.');
    } finally {
        remove_filter('wp_die_handler', $die_handler);
    }
} finally {
    $GLOBALS['wp_query'] = $original_query;
    $wpdb->query('ROLLBACK');
    wp_cache_flush();
}
echo "Reviewer Tools lifecycle, four views, exports, robots and data isolation: OK\n";
