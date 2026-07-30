<?php

declare(strict_types=1);

require_once __DIR__ . '/SearchService.php';

function ehrman_html(mixed $value): string
{
    return htmlspecialchars($value === null ? '' : (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ehrman_pluralize(int $count, string $singular, ?string $plural = null): string
{
    $word = $count === 1 ? $singular : ($plural ?? $singular . 's');
    return number_format($count) . ' ' . $word;
}

function ehrman_query_params(): array
{
    $params = [];
    foreach (explode('&', (string) ($_SERVER['QUERY_STRING'] ?? '')) as $part) {
        if ($part === '') {
            continue;
        }
        [$rawKey, $rawValue] = array_pad(explode('=', $part, 2), 2, '');
        $key = urldecode($rawKey);
        $value = urldecode($rawValue);
        $params[$key] ??= [];
        $params[$key][] = $value;
    }
    return $params;
}

function ehrman_query_first(array $query, string $key, string $default = ''): string
{
    return isset($query[$key][0]) ? (string) $query[$key][0] : $default;
}

function ehrman_header(string $active = ''): string
{
    $links = [
        ['Join!', '#', 'disabled'],
        ['Recent Posts', '#', 'disabled'],
        ['Keyword Search', '/keyword-search', 'keyword-search'],
        ['Browse Topics 1', '/browse-topics-1', 'browse-topics-1'],
        ['Browse Topics 2', '/browse-topics-2', 'browse-topics-2'],
        ['Forum', '#', 'disabled'],
        ['About Blog', '#', 'disabled'],
        ['About Bart', '#', 'disabled'],
        ['Help', '#', 'disabled'],
    ];
    $items = [];
    foreach ($links as [$label, $href, $key]) {
        $classes = ['site-menu-link'];
        if ($key === 'disabled') {
            $classes[] = 'disabled-link';
            $items[] = '<span class="' . implode(' ', $classes) . '" aria-disabled="true">'
                . ehrman_html($label) . '</span>';
            continue;
        }
        $classes[] = 'primary-menu-link';
        if ($active === $key) {
            $classes[] = 'active';
        }
        $items[] = '<a class="' . implode(' ', $classes) . '" href="' . ehrman_html($href) . '">'
            . ehrman_html($label) . '</a>';
    }
    return '<header class="site-header">'
        . '<div class="site-utility"><div class="site-utility-inner">'
        . '<div class="site-tagline">Engaging Discussions about Early Christianity</div>'
        . '<div class="site-utility-actions" aria-label="Site utility links">'
        . '<form class="site-search" action="#" aria-label="Site search" aria-disabled="true">'
        . '<input type="search" placeholder="Search..." aria-label="Search" disabled><button type="button" disabled>All</button></form>'
        . '<span class="site-utility-link site-join-now" aria-disabled="true">Join Now!</span>'
        . '<span class="site-utility-link site-login" aria-disabled="true">Login</span>'
        . '<span class="site-utility-link" aria-disabled="true">Account</span></div></div></div>'
        . '<div class="site-top"><a class="site-brand" href="/">'
        . '<span class="site-logo-mark" aria-hidden="true"></span><span class="site-logo-copy">'
        . '<span class="site-logo-title">The Bart Ehrman Blog:</span>'
        . '<span class="site-logo-subtitle">The History &amp; Literature of Early Christianity</span></span></a>'
        . '<nav class="site-menu" aria-label="Site navigation">' . implode('', $items) . '</nav></div></header>';
}

function ehrman_render_page(string $title, string $body, string $active = ''): string
{
    $fullTitle = $title !== '' ? $title . ' | Bart Blog Demo' : 'Bart Blog Demo';
    $stylesUrl = ehrman_static_asset_url('styles.css');
    $scriptUrl = ehrman_static_asset_url('site.js');
    return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . ehrman_html($fullTitle) . '</title><link rel="stylesheet" href="' . ehrman_html($stylesUrl) . '">'
        . '</head><body><a class="skip-link" href="#main-content">Skip to content</a>'
        . ehrman_header($active)
        . '<main id="main-content" class="page-shell" tabindex="-1">' . $body . '</main>'
        . '<script src="' . ehrman_html($scriptUrl) . '"></script></body></html>';
}

function ehrman_static_asset_url(string $filename): string
{
    static $versions = [];
    $url = '/static/' . rawurlencode($filename);
    if (!array_key_exists($filename, $versions)) {
        $path = dirname(__DIR__, 2) . '/webapp/static/' . $filename;
        $hash = is_file($path) ? hash_file('sha256', $path) : false;
        $versions[$filename] = $hash === false ? '' : substr($hash, 0, 12);
    }
    return $versions[$filename] === '' ? $url : $url . '?v=' . $versions[$filename];
}

function ehrman_description_toggle(bool $checked = false, string $scope = 'browse'): string
{
    return '<label class="hover-help description-check"><input type="checkbox" data-description-toggle '
        . 'data-description-scope="' . ehrman_html($scope) . '"' . ($checked ? ' checked' : '') . '>'
        . '<span>Show descriptions</span></label>';
}

function ehrman_breadcrumb_nav(array $items): string
{
    if ($items === []) {
        return '';
    }
    $crumbs = [];
    $mobileBack = null;
    foreach ($items as [$label, $href]) {
        if ($href !== null && $href !== '') {
            $content = '<a href="' . ehrman_html($href) . '">' . ehrman_html($label) . '</a>';
            $mobileBack = [$label, $href];
        } else {
            $content = '<span aria-current="page">' . ehrman_html($label) . '</span>';
        }
        $crumbs[] = '<li>' . $content . '</li>';
    }
    $mobile = $mobileBack === null ? '' : '<a class="mobile-breadcrumb" href="'
        . ehrman_html($mobileBack[1]) . '"><span aria-hidden="true">&lsaquo;</span> '
        . ehrman_html($mobileBack[0]) . '</a>';
    return '<nav class="breadcrumbs" aria-label="Breadcrumb"><ol>' . implode('', $crumbs)
        . '</ol>' . $mobile . '</nav>';
}

function ehrman_content_page(
    string $title,
    string $countLine,
    string $description = '',
    string $inner = '',
    string $actions = '',
    bool $descriptionFirst = false,
    bool $toggleDescriptions = false,
    bool $descriptionsChecked = false,
    array $breadcrumbs = [],
): string {
    $descriptionHtml = $description === '' ? '' : '<p class="content-description">' . ehrman_html($description) . '</p>';
    $countHtml = '<p class="count-line">' . ehrman_html($countLine) . '</p>';
    $headerMeta = $descriptionFirst ? $descriptionHtml . $countHtml : $countHtml . $descriptionHtml;
    $actionsHtml = $actions === '' ? '' : '<div class="content-actions">' . $actions . '</div>';
    $toggle = $toggleDescriptions ? ehrman_description_toggle($descriptionsChecked, 'browse') : '';
    return '<section class="content-page"><div class="content-header">'
        . ehrman_breadcrumb_nav($breadcrumbs) . '<h1>' . ehrman_html($title) . '</h1>'
        . $headerMeta . $actionsHtml . $toggle . '</div>' . $inner . '</section>';
}

function ehrman_subject_area_config(int $set): array
{
    return $set === 2
        ? ['subject_areas_2', 'subject_area_2_categories', 'Browse Topics 2', '/browse-topics-2', 'browse-topics-2']
        : ['subject_areas', 'subject_area_categories', 'Browse Topics 1', '/browse-topics-1', 'browse-topics-1'];
}

function ehrman_subject_area_set(mixed $value): int
{
    return (string) $value === '2' ? 2 : 1;
}

function ehrman_primary_subject_area(PDO $db, int $categoryId, string $slug, int $set): ?array
{
    [$areaTable, $linkTable] = ehrman_subject_area_config($set);
    if ($slug !== '') {
        $row = ehrman_fetch_one(
            $db,
            "SELECT sa.name, sa.slug FROM {$areaTable} sa JOIN {$linkTable} sac ON sac.subject_area_id = sa.id "
            . 'WHERE sac.category_id = ? AND sa.slug = ? ORDER BY sac.position LIMIT 1',
            [$categoryId, $slug],
        );
        if ($row !== null) {
            return $row;
        }
    }
    return ehrman_fetch_one(
        $db,
        "SELECT sa.name, sa.slug FROM {$areaTable} sa JOIN {$linkTable} sac ON sac.subject_area_id = sa.id "
        . 'WHERE sac.category_id = ? ORDER BY sa.id, sac.position LIMIT 1',
        [$categoryId],
    );
}

function ehrman_category_context_query(string $subjectAreaSlug, int $set): string
{
    return $subjectAreaSlug === '' ? '' : '?' . http_build_query([
        'subject-area' => $subjectAreaSlug,
        'subject-area-set' => $set,
    ]);
}

function ehrman_category_href(array $category, string $subjectAreaSlug, int $set): string
{
    return '/categories/' . $category['slug'] . ehrman_category_context_query($subjectAreaSlug, $set);
}

function ehrman_category_posts_href(array $category, string $subjectAreaSlug, int $set): string
{
    return '/categories/' . $category['slug'] . '/posts' . ehrman_category_context_query($subjectAreaSlug, $set);
}

function ehrman_topic_href(array $topic, array $category, string $subjectAreaSlug, int $set): string
{
    $params = ['category' => $category['slug']];
    if ($subjectAreaSlug !== '') {
        $params['subject-area'] = $subjectAreaSlug;
        $params['subject-area-set'] = (string) $set;
    }
    return '/topics/' . $topic['slug'] . '?' . http_build_query($params);
}

function ehrman_category_breadcrumbs(PDO $db, array $category, ?string $current, string $subjectAreaSlug, int $set): array
{
    [, , $browseLabel, $browsePath] = ehrman_subject_area_config($set);
    $area = ehrman_primary_subject_area($db, (int) $category['id'], $subjectAreaSlug, $set);
    $items = [[$browseLabel, $browsePath]];
    if ($area !== null) {
        $items[] = [$area['name'], $browsePath . '/' . $area['slug']];
    }
    if ($current !== null) {
        $items[] = [$category['name'], ehrman_category_href($category, $area['slug'] ?? '', $set)];
        $items[] = [$current, null];
    } else {
        $items[] = [$category['name'], null];
    }
    return $items;
}

function ehrman_home_page(): string
{
    $db = ehrman_db();
    $dates = ehrman_fetch_one(
        $db,
        "SELECT (SELECT date_text FROM posts WHERE date_iso <> '' ORDER BY date_iso ASC LIMIT 1) AS first_date, "
        . "(SELECT date_text FROM posts WHERE date_iso <> '' ORDER BY date_iso DESC LIMIT 1) AS last_date",
    );
    $dateRange = 'Posts from ' . $dates['first_date'] . ' - ' . $dates['last_date'];
    $body = '<section class="site-home"><section class="site-hero" aria-label="Bart Ehrman lecturing"></section>'
        . '<section class="site-demo-note" aria-label="Demo description">'
        . "<p>This demo offers two ways to discover posts on Bart's blog: <strong>Keyword Search</strong> and <strong>Browse Topics</strong>.</p>"
        . '<p><strong>Keyword Search</strong> works best for readers who already know what they want to find. Readers can combine up to four topics or keywords to narrow the results.</p>'
        . '<p><strong>Browse Topics</strong> supports exploration by guiding readers from subject areas to categories, topics, and related posts. '
        . '<strong>Browse Topics 1</strong> and <strong>Browse Topics 2</strong> organize the same collection differently; both are included for evaluation, but only one will appear in the final version.</p>'
        . '<figure class="search-methods-figure"><img class="search-methods-image" src="/static/ehrman-search-methods.png" '
        . 'alt="Diagram comparing topic browsing with keyword search"></figure>'
        . '<p class="site-demo-date-range">' . ehrman_html($dateRange) . '</p>'
        . '<p class="site-demo-version">Version 2.0</p></section></section>';
    return ehrman_render_page('Home', $body);
}

function ehrman_subject_areas_page(int $set): string
{
    [$areaTable, $linkTable, $browseLabel, $browsePath, $active] = ehrman_subject_area_config($set);
    $rows = ehrman_fetch_all(
        ehrman_db(),
        "SELECT sa.name, sa.slug, sa.description, COUNT(DISTINCT sac.category_id) AS category_count, "
        . "COUNT(DISTINCT tc.topic_id) AS topic_count, COUNT(DISTINCT pt.post_id) AS post_count "
        . "FROM {$areaTable} sa LEFT JOIN {$linkTable} sac ON sac.subject_area_id = sa.id "
        . 'LEFT JOIN topic_categories tc ON tc.category_id = sac.category_id '
        . 'LEFT JOIN post_topics pt ON pt.topic_id = tc.topic_id GROUP BY sa.id ORDER BY sa.id',
    );
    $items = [];
    foreach ($rows as $row) {
        $meta = ehrman_pluralize((int) $row['category_count'], 'category', 'categories') . ' &bull; '
            . ehrman_pluralize((int) $row['topic_count'], 'topic') . ' &bull; '
            . ehrman_pluralize((int) $row['post_count'], 'post');
        $items[] = '<li class="list-item"><div class="browse-item-row">'
            . '<a class="item-title" href="' . $browsePath . '/' . ehrman_html($row['slug']) . '" data-description="'
            . ehrman_html($row['description']) . '">' . ehrman_html($row['name']) . '</a>'
            . '<p class="item-meta">' . $meta . '</p></div><p class="item-description" hidden>'
            . ehrman_html($row['description']) . '</p></li>';
    }
    $body = ehrman_content_page(
        $browseLabel,
        ehrman_pluralize(count($rows), 'subject area'),
        inner: '<ul class="item-list">' . implode('', $items) . '</ul>',
        toggleDescriptions: true,
    );
    return ehrman_render_page($browseLabel, $body, $active);
}

function ehrman_subject_area_page(string $slug, int $set): ?string
{
    [$areaTable, $linkTable, $browseLabel, $browsePath, $active] = ehrman_subject_area_config($set);
    $db = ehrman_db();
    $area = ehrman_fetch_one($db, "SELECT * FROM {$areaTable} WHERE slug = ?", [$slug]);
    if ($area === null) {
        return null;
    }
    $categories = ehrman_fetch_all(
        $db,
        "SELECT c.name, c.slug, c.description, COUNT(DISTINCT tc.topic_id) AS topic_count, "
        . "COUNT(DISTINCT pt.post_id) AS post_count FROM {$linkTable} sac "
        . 'JOIN categories c ON c.id = sac.category_id '
        . 'LEFT JOIN topic_categories tc ON tc.category_id = c.id '
        . 'LEFT JOIN post_topics pt ON pt.topic_id = tc.topic_id '
        . 'WHERE sac.subject_area_id = ? GROUP BY c.id ORDER BY sac.position, c.name COLLATE NOCASE',
        [(int) $area['id']],
    );
    $counts = ehrman_fetch_one(
        $db,
        "SELECT COUNT(DISTINCT sac.category_id) AS category_count, COUNT(DISTINCT tc.topic_id) AS topic_count, "
        . "COUNT(DISTINCT pt.post_id) AS post_count FROM {$linkTable} sac "
        . 'LEFT JOIN topic_categories tc ON tc.category_id = sac.category_id '
        . 'LEFT JOIN post_topics pt ON pt.topic_id = tc.topic_id WHERE sac.subject_area_id = ?',
        [(int) $area['id']],
    );
    $items = [];
    foreach ($categories as $category) {
        $href = '/categories/' . $category['slug'] . '?' . http_build_query([
            'subject-area' => $area['slug'],
            'subject-area-set' => $set,
        ]);
        $items[] = '<li class="list-item"><div class="browse-item-row">'
            . '<a class="item-title" href="' . ehrman_html($href) . '" data-description="'
            . ehrman_html($category['description']) . '">' . ehrman_html($category['name']) . '</a>'
            . '<p class="item-meta">' . ehrman_pluralize((int) $category['topic_count'], 'topic') . ' &bull; '
            . ehrman_pluralize((int) $category['post_count'], 'post') . '</p></div>'
            . '<p class="item-description" hidden>' . ehrman_html($category['description']) . '</p></li>';
    }
    $countLine = ehrman_pluralize((int) $counts['category_count'], 'category', 'categories') . ' • '
        . ehrman_pluralize((int) $counts['topic_count'], 'topic') . ' • '
        . ehrman_pluralize((int) $counts['post_count'], 'post');
    $body = ehrman_content_page(
        (string) $area['name'],
        $countLine,
        inner: '<ul class="item-list">' . implode('', $items) . '</ul>',
        toggleDescriptions: true,
        breadcrumbs: [[$browseLabel, $browsePath], [$area['name'], null]],
    );
    return ehrman_render_page((string) $area['name'], $body, $active);
}

function ehrman_category_page(string $slug, array $query): ?string
{
    $subjectAreaSlug = ehrman_query_first($query, 'subject-area');
    $set = ehrman_subject_area_set(ehrman_query_first($query, 'subject-area-set', '1'));
    [, , , , $active] = ehrman_subject_area_config($set);
    $db = ehrman_db();
    $category = ehrman_fetch_one($db, 'SELECT * FROM categories WHERE slug = ?', [$slug]);
    if ($category === null) {
        return null;
    }
    $topics = ehrman_fetch_all(
        $db,
        <<<'SQL'
        SELECT t.name, t.slug, t.description, COUNT(DISTINCT pt.post_id) AS post_count
        FROM topics t
        JOIN topic_categories tc ON tc.topic_id = t.id
        LEFT JOIN post_topics pt ON pt.topic_id = t.id
        WHERE tc.category_id = ? AND t.display_in_browser = 1
        GROUP BY t.id
        ORDER BY CASE WHEN tc.position > 0 THEN 0 ELSE 1 END, tc.position, t.name COLLATE NOCASE
        SQL,
        [(int) $category['id']],
    );
    $postCount = (int) ehrman_scalar(
        $db,
        'SELECT COUNT(DISTINCT pt.post_id) FROM post_topics pt '
        . 'JOIN topic_categories tc ON tc.topic_id = pt.topic_id WHERE tc.category_id = ?',
        [(int) $category['id']],
    );
    $items = [];
    foreach ($topics as $topic) {
        $items[] = '<li class="list-item"><div class="browse-item-row">'
            . '<a class="item-title" href="' . ehrman_html(ehrman_topic_href($topic, $category, $subjectAreaSlug, $set))
            . '" data-description="' . ehrman_html($topic['description']) . '">' . ehrman_html($topic['name']) . '</a>'
            . '<p class="item-meta">' . ehrman_pluralize((int) $topic['post_count'], 'post') . '</p></div>'
            . '<p class="item-description" hidden>' . ehrman_html($topic['description']) . '</p></li>';
    }
    $actions = '<a class="category-posts-link" href="'
        . ehrman_html(ehrman_category_posts_href($category, $subjectAreaSlug, $set)) . '">View all '
        . ehrman_html(ehrman_pluralize($postCount, 'post')) . ' in this category</a>';
    $body = ehrman_content_page(
        (string) $category['name'],
        ehrman_pluralize(count($topics), 'topic') . ' • ' . ehrman_pluralize($postCount, 'post'),
        inner: '<ul class="item-list">' . implode('', $items) . '</ul>',
        actions: $actions,
        toggleDescriptions: true,
        breadcrumbs: ehrman_category_breadcrumbs($db, $category, null, $subjectAreaSlug, $set),
    );
    return ehrman_render_page((string) $category['name'], $body, $active);
}

function ehrman_keyword_panel(
    array $prefill = [],
    string $sort = 'ranked',
    bool $descriptionsChecked = false,
    bool $refreshOnRemove = false,
    bool $sortCurrentPage = false,
    string $formAction = '/keyword-results',
    string $scopeLabel = '',
    string $scopeSlug = '',
    string $scopeTopicSlug = '',
): string {
    $values = array_slice(ehrman_unique_terms($prefill), 0, 4);
    $sort = in_array($sort, ['ranked', 'newest', 'oldest'], true) ? $sort : 'ranked';
    $sortOptions = [];
    foreach (['ranked' => 'Best match', 'newest' => 'Newest first', 'oldest' => 'Oldest first'] as $value => $label) {
        $sortOptions[] = '<label class="sort-choice"><input type="radio" name="sort" value="' . $value . '"'
            . ($value === $sort ? ' checked' : '') . '><span>' . $label . '</span></label>';
    }
    $chips = [];
    foreach ($values as $value) {
        $chips[] = '<span class="keyword-slot keyword-chip"><input type="hidden" name="keyword" value="'
            . ehrman_html($value) . '"><span>' . ehrman_html($value) . '</span>'
            . '<button type="button" class="keyword-chip-remove" data-remove-keyword aria-label="Remove '
            . ehrman_html($value) . '">x</button></span>';
    }
    $nextIndex = count($values) + 1;
    $entry = '<div class="keyword-slot keyword-input-wrap"' . (count($values) >= 4 ? ' hidden' : '') . '>'
        . '<input class="keyword-input" name="keyword" value="" placeholder="Keyword ' . min($nextIndex, 4)
        . '" autocomplete="off"' . ($values === [] ? ' autofocus' : '')
        . (count($values) >= 4 ? ' disabled' : '') . '><ul class="keyword-suggestion-list" hidden></ul></div>';
    $emptySlots = [];
    $firstEmpty = $nextIndex + (count($values) >= 4 ? 0 : 1);
    for ($index = $firstEmpty; $index <= 4; $index++) {
        $emptySlots[] = '<span class="keyword-slot keyword-empty-slot">Keyword ' . $index . '</span>';
    }
    $attributes = $refreshOnRemove ? ' data-refresh-on-remove="true"' : '';
    $attributes .= $sortCurrentPage ? ' data-sort-current-page="true"' : '';
    $attributes .= $scopeSlug !== '' ? ' data-category-slug="' . ehrman_html($scopeSlug) . '"' : '';
    $attributes .= $scopeTopicSlug !== '' ? ' data-topic-slug="' . ehrman_html($scopeTopicSlug) . '"' : '';
    $scopeMarkup = $scopeLabel === '' ? '' : '<div class="keyword-scope-row" aria-label="Search scope">'
        . '<span class="keyword-scope"><strong>Category:</strong> ' . ehrman_html($scopeLabel) . '</span></div>';
    return '<form class="keyword-search-panel" action="' . ehrman_html($formAction)
        . '" method="get" data-keyword-form' . $attributes . '>'
        . '<label><strong>Enter up to four search terms.</strong> Topics identify major subjects covered in a post. '
        . 'Keywords identify significant people, texts, places, or supporting ideas discussed in the post. '
        . 'Combine either type to narrow your results.</label>' . $scopeMarkup
        . '<div class="keyword-grid"><div class="keyword-slot-grid" data-keyword-chip-list>'
        . implode('', $chips) . $entry . implode('', $emptySlots) . '</div></div>'
        . '<div class="sort-row"><span class="sort-label">Sort by</span>' . implode('', $sortOptions) . '</div>'
        . '<div class="keyword-action-row"><button type="submit">Search</button>'
        . '<button type="button" class="keyword-clear-button" data-clear-keywords>Clear all</button></div></form>'
        . '<div class="search-description-toggle">'
        . ehrman_description_toggle($descriptionsChecked, 'posts') . '</div>';
}

function ehrman_results_summary(int $postCount, array $terms, string $scopeLabel = ''): string
{
    $cleanTerms = ehrman_unique_terms($terms);
    if ($cleanTerms === []) {
        return '';
    }
    $verb = $postCount === 1 ? 'matches' : 'match';
    $scope = $scopeLabel === '' ? '' : ' in ' . ehrman_html($scopeLabel);
    return '<p class="results-summary" aria-live="polite"><strong>'
        . ehrman_html(ehrman_pluralize($postCount, 'post')) . '</strong>' . $scope . ' ' . $verb
        . ' <strong>' . ehrman_html(implode(' + ', $cleanTerms)) . '</strong>.</p>';
}

function ehrman_post_list(array $posts, string $contextTopic = ''): string
{
    if ($posts === []) {
        return '<p class="empty">No posts matched this request.</p>';
    }
    $items = [];
    foreach ($posts as $post) {
        $topicText = $contextTopic !== '' ? $contextTopic : (string) ($post['context_topic'] ?? '');
        $meta = [(string) ($post['author'] ?? '') !== '' ? 'By ' . ehrman_html($post['author']) : 'By unknown author'];
        $meta[] = ehrman_html($post['date_text']);
        if ($topicText !== '') {
            $meta[] = ehrman_html($topicText);
        }
        $description = ehrman_html($post['description']);
        $items[] = '<li class="post-item"><a class="post-title" href="' . ehrman_html($post['url'])
            . '" target="_blank" rel="noopener" data-description="' . $description . '">'
            . ehrman_html($post['title']) . '</a><p class="post-meta">' . implode(' | ', $meta) . '</p>'
            . '<p class="post-description" hidden>' . $description . '</p></li>';
    }
    return '<ul class="post-list">' . implode('', $items) . '</ul>';
}

function ehrman_topic_context_category(PDO $db, int $topicId, string $requestedSlug): ?array
{
    if ($requestedSlug !== '') {
        $category = ehrman_fetch_one(
            $db,
            'SELECT c.* FROM categories c JOIN topic_categories tc ON tc.category_id = c.id '
            . 'WHERE tc.topic_id = ? AND c.slug = ? LIMIT 1',
            [$topicId, $requestedSlug],
        );
        if ($category !== null) {
            return $category;
        }
    }
    return ehrman_fetch_one(
        $db,
        'SELECT c.* FROM categories c JOIN topic_categories tc ON tc.category_id = c.id '
        . 'WHERE tc.topic_id = ? ORDER BY c.name COLLATE NOCASE LIMIT 1',
        [$topicId],
    );
}

function ehrman_keyword_search_page(): string
{
    $body = ehrman_content_page(
        'Keyword Search',
        'Search posts by keyword',
        inner: ehrman_keyword_panel(descriptionsChecked: true),
    );
    return ehrman_render_page('Keyword Search', $body, 'keyword-search');
}

function ehrman_keyword_results_page(array $query): string
{
    $terms = $query['keyword'] ?? [];
    $sort = ehrman_query_first($query, 'sort', 'ranked');
    [$posts, $cleanTerms] = ehrman_search_posts($terms, $sort);
    $title = $cleanTerms === [] ? 'Keyword Search' : 'Keywords: ' . implode(' + ', $cleanTerms);
    $inner = ehrman_keyword_panel($cleanTerms, $sort, true, true)
        . ehrman_results_summary(count($posts), $cleanTerms)
        . ehrman_post_list($posts, 'Keyword Search');
    $body = ehrman_content_page($title, ehrman_pluralize(count($posts), 'post'), inner: $inner);
    return ehrman_render_page($title, $body, 'keyword-search');
}

function ehrman_topic_posts_page(string $slug, array $query): ?string
{
    $sort = ehrman_query_first($query, 'sort', 'ranked');
    $requestedCategory = ehrman_query_first($query, 'category');
    $subjectAreaSlug = ehrman_query_first($query, 'subject-area');
    $set = ehrman_subject_area_set(ehrman_query_first($query, 'subject-area-set', '1'));
    [, , , , $active] = ehrman_subject_area_config($set);
    $db = ehrman_db();
    $topic = ehrman_fetch_one($db, 'SELECT * FROM topics WHERE slug = ?', [$slug]);
    if ($topic === null) {
        return null;
    }
    $category = ehrman_topic_context_category($db, (int) $topic['id'], $requestedCategory);
    [$posts, , $displayTerms] = ehrman_search_topic_posts($db, $topic, $query['keyword'] ?? [], $sort);
    $formAction = $category === null
        ? '/topics/' . $topic['slug']
        : ehrman_topic_href($topic, $category, $subjectAreaSlug, $set);
    $breadcrumbs = $category === null
        ? []
        : ehrman_category_breadcrumbs($db, $category, (string) $topic['name'], $subjectAreaSlug, $set);
    $inner = ehrman_keyword_panel(
        $displayTerms,
        $sort,
        true,
        true,
        true,
        $formAction,
        scopeTopicSlug: (string) $topic['slug'],
    ) . ehrman_results_summary(count($posts), $displayTerms)
        . ehrman_post_list($posts, (string) $topic['name']);
    $body = ehrman_content_page(
        (string) $topic['name'],
        ehrman_pluralize(count($posts), 'post'),
        inner: $inner,
        breadcrumbs: $breadcrumbs,
    );
    return ehrman_render_page((string) $topic['name'], $body, $active);
}

function ehrman_category_posts_page(string $slug, array $query): ?string
{
    $sort = ehrman_query_first($query, 'sort', 'ranked');
    $subjectAreaSlug = ehrman_query_first($query, 'subject-area');
    $set = ehrman_subject_area_set(ehrman_query_first($query, 'subject-area-set', '1'));
    [, , , , $active] = ehrman_subject_area_config($set);
    $db = ehrman_db();
    $category = ehrman_fetch_one($db, 'SELECT * FROM categories WHERE slug = ?', [$slug]);
    if ($category === null) {
        return null;
    }
    [$posts, $cleanTerms] = ehrman_search_category_posts($db, $category, $query['keyword'] ?? [], $sort);
    $formAction = ehrman_category_posts_href($category, $subjectAreaSlug, $set);
    $inner = ehrman_keyword_panel(
        $cleanTerms,
        $sort,
        true,
        true,
        formAction: $formAction,
        scopeLabel: (string) $category['name'],
        scopeSlug: (string) $category['slug'],
    ) . ehrman_results_summary(count($posts), $cleanTerms, (string) $category['name'])
        . ehrman_post_list($posts, (string) $category['name']);
    $body = ehrman_content_page(
        (string) $category['name'],
        ehrman_pluralize(count($posts), 'post'),
        inner: $inner,
        breadcrumbs: ehrman_category_breadcrumbs($db, $category, 'Posts', $subjectAreaSlug, $set),
    );
    return ehrman_render_page((string) $category['name'], $body, $active);
}

function ehrman_not_found_page(): string
{
    return ehrman_render_page(
        'Page Not Found',
        ehrman_content_page('Page Not Found', 'The requested page could not be found.'),
    );
}
