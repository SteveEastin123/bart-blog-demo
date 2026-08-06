<?php

namespace EhrmanBlogDiscovery;

if (!defined('ABSPATH')) {
    exit;
}

final class Page_Controller
{
    private Browse_Service $browse;

    private Search_Service $search;

    private int $instance = 0;

    public function __construct()
    {
        $this->browse = new Browse_Service();
        $this->search = new Search_Service();
    }

    public function register(): void
    {
        add_shortcode('ehrman_keyword_search', array($this, 'keyword_search_shortcode'));
        add_shortcode('ehrman_browse_topics', array($this, 'browse_topics_shortcode'));
    }

    public static function ensure_pages(): void
    {
        $pages = array(
            'keyword_search' => array('Keyword Search', 'keyword-search', '[ehrman_keyword_search]'),
            'browse_1' => array('Browse Topics 1', 'browse-topics-1', '[ehrman_browse_topics path="1"]'),
            'browse_2' => array('Browse Topics 2', 'browse-topics-2', '[ehrman_browse_topics path="2"]'),
        );

        foreach ($pages as $key => [$title, $slug, $content]) {
            $option = 'ehrman_discovery_page_' . $key;
            $page_id = (int) get_option($option, 0);
            if ($page_id > 0 && 'trash' !== get_post_status($page_id)) {
                continue;
            }
            $existing = get_page_by_path($slug, OBJECT, 'page');
            if ($existing instanceof \WP_Post) {
                update_option($option, $existing->ID, false);
                continue;
            }
            $page_id = wp_insert_post(
                array(
                    'post_title' => $title,
                    'post_name' => $slug,
                    'post_content' => $content,
                    'post_status' => 'publish',
                    'post_type' => 'page',
                    'comment_status' => 'closed',
                ),
                true
            );
            if (!is_wp_error($page_id)) {
                update_option($option, (int) $page_id, false);
            }
        }
        update_option('ehrman_discovery_pages_version', EBD_VERSION, false);
    }

    public function keyword_search_shortcode(): string
    {
        if ('complete' !== Plugin::status_data()['import_state']) {
            return $this->not_ready();
        }
        Assets::enqueue();
        $terms = $this->request_terms();
        $sort = $this->request_value('ebd_sort', 'ranked');
        $category_slug = sanitize_title($this->request_value('ebd_category'));
        $categories = $this->browse->category_options();
        $category = $this->find_by_slug($categories, $category_slug);
        if (null === $category) {
            $category_slug = '';
        }
        $has_request = !empty($terms) || '' !== $category_slug;
        $result = $has_request
            ? $this->search->search($terms, $sort, $category_slug)
            : array('posts' => array(), 'terms' => array(), 'sort' => 'ranked', 'count' => 0);
        $context = null === $category ? '' : (string) $category['name'];

        return $this->shell(
            $this->search_panel(
                $result['terms'],
                $result['sort'],
                true,
                $this->page_url('keyword_search'),
                '',
                '',
                $categories,
                $category_slug
            )
            . '<div class="ebd-results" data-ebd-results data-context="' . esc_attr($context) . '">'
            . ($has_request ? $this->results_markup($result, $context) : '')
            . '</div>',
            'keyword-search'
        );
    }

    public function browse_topics_shortcode(array $attributes): string
    {
        if ('complete' !== Plugin::status_data()['import_state']) {
            return $this->not_ready();
        }
        Assets::enqueue();
        $attributes = shortcode_atts(array('path' => '1'), $attributes, 'ehrman_browse_topics');
        $path_number = '2' === (string) $attributes['path'] ? 2 : 1;
        $subject_slug = sanitize_title($this->request_value('ebd_subject'));
        $category_slug = sanitize_title($this->request_value('ebd_category'));
        $topic_slug = sanitize_title($this->request_value('ebd_topic'));
        $view = sanitize_key($this->request_value('ebd_view'));

        if ('' !== $topic_slug) {
            return $this->render_topic_posts($path_number, $subject_slug, $category_slug, $topic_slug);
        }
        if ('' !== $category_slug && 'posts' === $view) {
            return $this->render_category_posts($path_number, $subject_slug, $category_slug);
        }
        if ('' !== $category_slug) {
            return $this->render_category($path_number, $subject_slug, $category_slug);
        }
        if ('' !== $subject_slug) {
            return $this->render_subject_area($path_number, $subject_slug);
        }
        return $this->render_subject_areas($path_number);
    }

    private function render_subject_areas(int $path_number): string
    {
        $areas = $this->browse->subject_areas($path_number);
        $items = array();
        foreach ($areas as $area) {
            $url = $this->browse_url($path_number, array('ebd_subject' => $area['slug']));
            $meta = $this->plural((int) $area['category_count'], 'category', 'categories') . ' &bull; '
                . $this->plural((int) $area['topic_count'], 'topic') . ' &bull; '
                . $this->plural((int) $area['post_count'], 'post');
            $items[] = $this->browse_item(
                (string) $area['name'],
                $url,
                $meta,
                (string) $area['description'],
                true
            );
        }
        return $this->shell(
            $this->heading(__('Choose a Subject Area', 'ehrman-blog-discovery'), $this->plural(count($areas), 'subject area'))
            . $this->description_toggle(false, 'browse')
            . '<ul class="ebd-item-list">' . implode('', $items) . '</ul>',
            'browse'
        );
    }

    private function render_subject_area(int $path_number, string $subject_slug): string
    {
        $area = $this->browse->subject_area($path_number, $subject_slug);
        if (null === $area) {
            return $this->not_found();
        }
        $categories = $this->browse->subject_area_categories((int) $area['id']);
        $counts = $this->browse->subject_area_counts((int) $area['id']);
        $items = array();
        foreach ($categories as $category) {
            $url = $this->browse_url(
                $path_number,
                array('ebd_subject' => $subject_slug, 'ebd_category' => $category['slug'])
            );
            $meta = $this->plural((int) $category['topic_count'], 'topic') . ' &bull; '
                . $this->plural((int) $category['post_count'], 'post');
            $items[] = $this->browse_item(
                (string) $category['name'],
                $url,
                $meta,
                (string) $category['description'],
                true
            );
        }
        $meta = $this->plural((int) $counts['category_count'], 'category', 'categories') . ' &bull; '
            . $this->plural((int) $counts['topic_count'], 'topic') . ' &bull; '
            . $this->plural((int) $counts['post_count'], 'post');
        $breadcrumbs = array(
            array('Browse Topics ' . $path_number, $this->browse_url($path_number)),
            array((string) $area['name'], ''),
        );
        return $this->shell(
            $this->heading((string) $area['name'], $meta, $breadcrumbs)
            . $this->description_toggle(false, 'browse')
            . '<ul class="ebd-item-list">' . implode('', $items) . '</ul>',
            'browse'
        );
    }

    private function render_category(int $path_number, string $subject_slug, string $category_slug): string
    {
        $category = $this->browse->category($category_slug);
        if (null === $category) {
            return $this->not_found();
        }
        $area = $this->browse->primary_subject_area($path_number, (int) $category['id'], $subject_slug);
        $topics = $this->browse->category_topics((int) $category['id']);
        $post_count = $this->browse->category_post_count((int) $category['id']);
        $items = array();
        foreach ($topics as $topic) {
            $args = array('ebd_category' => $category_slug, 'ebd_topic' => $topic['slug']);
            if (null !== $area) {
                $args['ebd_subject'] = $area['slug'];
            }
            $items[] = $this->browse_item(
                (string) $topic['name'],
                $this->browse_url($path_number, $args),
                $this->plural((int) $topic['post_count'], 'post'),
                (string) $topic['description'],
                true
            );
        }
        $post_args = array('ebd_category' => $category_slug, 'ebd_view' => 'posts');
        if (null !== $area) {
            $post_args['ebd_subject'] = $area['slug'];
        }
        $actions = '<a class="ebd-primary-link" href="' . esc_url($this->browse_url($path_number, $post_args)) . '">'
            . esc_html(sprintf(__('View all %s in this category', 'ehrman-blog-discovery'), $this->plural($post_count, 'post')))
            . '</a>';
        $breadcrumbs = $this->category_breadcrumbs($path_number, $category, $area);
        $breadcrumbs[count($breadcrumbs) - 1][1] = '';
        return $this->shell(
            $this->heading(
                (string) $category['name'],
                $this->plural(count($topics), 'topic') . ' &bull; ' . $this->plural($post_count, 'post'),
                $breadcrumbs,
                $actions
            )
            . $this->description_toggle(false, 'browse')
            . '<ul class="ebd-item-list">' . implode('', $items) . '</ul>',
            'browse'
        );
    }

    private function render_topic_posts(
        int $path_number,
        string $subject_slug,
        string $category_slug,
        string $topic_slug
    ): string {
        $topic = $this->browse->topic($topic_slug);
        if (null === $topic) {
            return $this->not_found();
        }
        $category = $this->browse->topic_category((int) $topic['id'], $category_slug);
        $area = null === $category
            ? null
            : $this->browse->primary_subject_area($path_number, (int) $category['id'], $subject_slug);
        $terms = $this->request_terms();
        if (empty($terms)) {
            $terms = array((string) $topic['name']);
        }
        $sort = $this->request_value('ebd_sort', 'ranked');
        $result = $this->search->search($terms, $sort, '', $topic_slug);
        $breadcrumbs = null === $category
            ? array()
            : array_merge(
                $this->category_breadcrumbs($path_number, $category, $area),
                array(array((string) $topic['name'], ''))
            );
        return $this->shell(
            $this->heading((string) $topic['name'], $this->plural((int) $result['count'], 'post'), $breadcrumbs, '', true)
            . $this->search_panel(
                $result['terms'],
                $result['sort'],
                true,
                $this->browse_url(
                    $path_number,
                    array_filter(
                        array(
                            'ebd_subject' => null === $area ? '' : $area['slug'],
                            'ebd_category' => null === $category ? '' : $category['slug'],
                            'ebd_topic' => $topic_slug,
                        )
                    )
                ),
                '',
                $topic_slug
            )
            . '<div class="ebd-results" data-ebd-results data-context="' . esc_attr((string) $topic['name']) . '">'
            . $this->results_markup($result, (string) $topic['name']) . '</div>',
            'posts'
        );
    }

    private function render_category_posts(int $path_number, string $subject_slug, string $category_slug): string
    {
        $category = $this->browse->category($category_slug);
        if (null === $category) {
            return $this->not_found();
        }
        $area = $this->browse->primary_subject_area($path_number, (int) $category['id'], $subject_slug);
        $terms = $this->request_terms();
        $sort = $this->request_value('ebd_sort', 'ranked');
        $result = $this->search->search($terms, $sort, $category_slug);
        $breadcrumbs = array_merge(
            $this->category_breadcrumbs($path_number, $category, $area),
            array(array(__('Posts', 'ehrman-blog-discovery'), ''))
        );
        $form_args = array('ebd_category' => $category_slug, 'ebd_view' => 'posts');
        if (null !== $area) {
            $form_args['ebd_subject'] = $area['slug'];
        }
        return $this->shell(
            $this->heading((string) $category['name'], $this->plural((int) $result['count'], 'post'), $breadcrumbs, '', true)
            . $this->search_panel(
                $result['terms'],
                $result['sort'],
                true,
                $this->browse_url($path_number, $form_args),
                $category_slug
            )
            . '<div class="ebd-results" data-ebd-results data-context="' . esc_attr((string) $category['name']) . '">'
            . $this->results_markup($result, (string) $category['name']) . '</div>',
            'posts'
        );
    }

    private function search_panel(
        array $terms,
        string $sort,
        bool $show_descriptions,
        string $action,
        string $category_scope = '',
        string $topic_scope = '',
        ?array $category_options = null,
        string $selected_category = ''
    ): string {
        $this->instance++;
        $id = 'ebd-search-' . $this->instance;
        $terms = Search_Service::unique_terms($terms);
        $sort = in_array($sort, array('ranked', 'newest', 'oldest'), true) ? $sort : 'ranked';
        $chips = array();
        foreach ($terms as $term) {
            $chips[] = '<span class="ebd-keyword-slot ebd-keyword-chip"><input type="hidden" name="ebd_keyword[]" value="'
                . esc_attr($term) . '"><span>' . esc_html($term) . '</span><button type="button" '
                . 'class="ebd-keyword-remove" data-ebd-remove aria-label="' . esc_attr(sprintf(__('Remove %s', 'ehrman-blog-discovery'), $term))
                . '">&times;</button></span>';
        }
        $next = count($terms) + 1;
        $input_hidden = count($terms) >= Search_Service::MAX_TERMS;
        $chips[] = '<div class="ebd-keyword-slot ebd-keyword-input-wrap"'
            . ($input_hidden ? ' hidden' : '') . '><input id="' . esc_attr($id)
            . '-input" class="ebd-keyword-input" type="text" placeholder="Keyword ' . min($next, 4)
            . '" aria-label="Keyword ' . min($next, 4) . '" autocomplete="off" aria-autocomplete="list" '
            . 'aria-expanded="false" aria-controls="'
            . esc_attr($id) . '-suggestions"' . ($input_hidden ? ' disabled' : '') . '><ul id="'
            . esc_attr($id) . '-suggestions" class="ebd-suggestions" role="listbox" hidden></ul></div>';
        if (!$input_hidden) {
            $next++;
        }
        while ($next <= Search_Service::MAX_TERMS) {
            $chips[] = '<span class="ebd-keyword-slot ebd-keyword-empty">Keyword ' . $next . '</span>';
            $next++;
        }

        $scope = '';
        if ('' !== $category_scope) {
            $category = $this->browse->category($category_scope);
            if (null !== $category) {
                $scope = '<div class="ebd-fixed-scope"><strong>' . esc_html__('Category:', 'ehrman-blog-discovery')
                    . '</strong> ' . esc_html((string) $category['name']) . '</div>';
            }
        }
        $category_filter = null === $category_options
            ? ''
            : $this->category_filter($id, $category_options, $selected_category);
        $sort_options = array();
        foreach (array('ranked' => 'Best match', 'newest' => 'Newest first', 'oldest' => 'Oldest first') as $value => $label) {
            $sort_options[] = '<label class="ebd-sort-choice"><input type="radio" name="ebd_sort" value="'
                . esc_attr($value) . '"' . checked($sort, $value, false) . '><span>' . esc_html($label) . '</span></label>';
        }
        return '<form class="ebd-search-panel" action="' . esc_url($action) . '" method="get" data-ebd-search-form '
            . 'data-category="' . esc_attr('' !== $category_scope ? $category_scope : $selected_category) . '" '
            . 'data-topic="' . esc_attr($topic_scope) . '">' . $scope . $category_filter
            . '<p class="ebd-search-instructions"><strong>' . esc_html__('Select up to four search terms.', 'ehrman-blog-discovery')
            . '</strong> ' . esc_html__('You can enter topics, keywords, or both. Topics identify a post\'s main subjects, while keywords identify important people, texts, places, and related ideas. Combine multiple terms to narrow your results.', 'ehrman-blog-discovery')
            . '</p><div class="ebd-keyword-grid" data-ebd-chip-list>' . implode('', $chips) . '</div>'
            . '<div class="ebd-sort-row"><span>' . esc_html__('Sort by', 'ehrman-blog-discovery') . '</span>'
            . implode('', $sort_options) . '</div><button type="button" class="ebd-clear" data-ebd-clear>'
            . esc_html__('Clear all', 'ehrman-blog-discovery') . '</button></form>'
            . '<div class="ebd-description-control">' . $this->description_toggle($show_descriptions, 'posts') . '</div>';
    }

    private function category_filter(string $id, array $categories, string $selected_slug): string
    {
        $selected = $this->find_by_slug($categories, $selected_slug);
        $name = null === $selected ? __('All categories', 'ehrman-blog-discovery') : (string) $selected['name'];
        $count = null === $selected ? '' : $this->plural((int) $selected['post_count'], 'post');
        $options = array(
            $this->category_option('', __('All categories', 'ehrman-blog-discovery'), '', null === $selected),
        );
        foreach ($categories as $category) {
            $options[] = $this->category_option(
                (string) $category['slug'],
                (string) $category['name'],
                $this->plural((int) $category['post_count'], 'post'),
                (string) $category['slug'] === $selected_slug
            );
        }
        return '<div class="ebd-category-filter"><div class="ebd-category-heading">'
            . '<span class="ebd-category-label" id="' . esc_attr($id) . '-category-label">'
            . esc_html__('Category', 'ehrman-blog-discovery') . '</span>'
            . '<span class="ebd-category-badge">' . esc_html__('Recommended', 'ehrman-blog-discovery') . '</span></div>'
            . '<p class="ebd-category-help" id="' . esc_attr($id) . '-category-help">'
            . esc_html__('Choose a category to narrow your suggestions and improve your results.', 'ehrman-blog-discovery')
            . '</p><div class="ebd-category-combobox" data-ebd-category-combobox>'
            . '<input type="hidden" name="ebd_category" value="' . esc_attr($selected_slug) . '" data-ebd-category>'
            . '<button type="button" class="ebd-category-toggle" data-ebd-category-toggle aria-haspopup="listbox" '
            . 'aria-expanded="false" aria-labelledby="' . esc_attr($id) . '-category-label '
            . esc_attr($id) . '-category-name" aria-describedby="' . esc_attr($id) . '-category-help"><span id="' . esc_attr($id)
            . '-category-name" data-ebd-category-name>' . esc_html($name) . '</span>'
            . '<span class="ebd-category-count" data-ebd-category-count>' . esc_html($count) . '</span>'
            . '<span aria-hidden="true">&#9662;</span></button><ul class="ebd-category-options" role="listbox" '
            . 'data-ebd-category-options hidden>' . implode('', $options) . '</ul></div></div>';
    }

    private function category_option(string $slug, string $name, string $count, bool $selected): string
    {
        return '<li role="presentation"><button type="button" role="option" data-ebd-category-option value="'
            . esc_attr($slug) . '" data-label="' . esc_attr($name) . '" data-count="' . esc_attr($count)
            . '" aria-selected="' . ($selected ? 'true' : 'false') . '"><span>' . esc_html($name)
            . '</span><span>' . esc_html($count) . '</span></button></li>';
    }

    private function results_markup(array $result, string $context): string
    {
        $terms = $result['terms'] ?? array();
        $count = (int) ($result['count'] ?? 0);
        $summary = '<p class="ebd-results-summary" aria-live="polite"><strong>'
            . esc_html($this->plural($count, 'post')) . '</strong>';
        $context_is_term = false;
        foreach ($terms as $term) {
            if (Search_Service::normalize((string) $term) === Search_Service::normalize($context)) {
                $context_is_term = true;
                break;
            }
        }
        if ('' !== $context && !$context_is_term) {
            $summary .= ' ' . esc_html__('in', 'ehrman-blog-discovery') . ' <strong>' . esc_html($context) . '</strong>';
        }
        if (!empty($terms)) {
            $summary .= ' ' . esc_html(1 === $count ? 'matches' : 'match') . ' <strong>'
                . esc_html(implode(' + ', $terms)) . '</strong>';
        }
        $summary .= '.</p>';
        return $summary . $this->post_list($result['posts'] ?? array(), $context);
    }

    private function post_list(array $posts, string $context): string
    {
        if (empty($posts)) {
            return '<p class="ebd-empty">' . esc_html__('No posts matched this request.', 'ehrman-blog-discovery') . '</p>';
        }
        $items = array();
        foreach ($posts as $post) {
            $description = (string) ($post['description'] ?? '');
            $meta = array(
                sprintf(__('By %s', 'ehrman-blog-discovery'), '' !== (string) $post['author'] ? $post['author'] : __('unknown author', 'ehrman-blog-discovery')),
                (string) $post['date_text'],
            );
            if ('' !== $context) {
                $meta[] = $context;
            }
            $items[] = '<li class="ebd-post-item"><a class="ebd-post-title" href="' . esc_url($post['url'])
                . '" target="_blank" rel="noopener" data-description="' . esc_attr($description) . '">'
                . esc_html($post['title']) . '</a><p class="ebd-post-meta">' . esc_html(implode(' | ', $meta))
                . '</p><p class="ebd-post-description">' . esc_html($description) . '</p></li>';
        }
        return '<ul class="ebd-post-list">' . implode('', $items) . '</ul>';
    }

    private function heading(
        string $title,
        string $meta,
        array $breadcrumbs = array(),
        string $actions = '',
        bool $dynamic_result_count = false
    ): string
    {
        return '<header class="ebd-content-header">' . $this->breadcrumbs($breadcrumbs) . '<h2>'
            . esc_html($title) . '</h2><p class="ebd-count-line"'
            . ($dynamic_result_count ? ' data-ebd-result-count' : '') . '>' . wp_kses_post($meta) . '</p>'
            . ('' === $actions ? '' : '<div class="ebd-actions">' . $actions . '</div>') . '</header>';
    }

    private function breadcrumbs(array $items): string
    {
        if (empty($items)) {
            return '';
        }
        $crumbs = array();
        foreach ($items as [$label, $url]) {
            $crumbs[] = '' === $url
                ? '<li aria-current="page">' . esc_html($label) . '</li>'
                : '<li><a href="' . esc_url($url) . '">' . esc_html($label) . '</a></li>';
        }
        return '<nav class="ebd-breadcrumbs" aria-label="' . esc_attr__('Breadcrumb', 'ehrman-blog-discovery')
            . '"><ol>' . implode('', $crumbs) . '</ol></nav>';
    }

    private function category_breadcrumbs(int $path_number, array $category, ?array $area): array
    {
        $items = array(array('Browse Topics ' . $path_number, $this->browse_url($path_number)));
        if (null !== $area) {
            $items[] = array(
                (string) $area['name'],
                $this->browse_url($path_number, array('ebd_subject' => $area['slug']))
            );
        }
        $args = array('ebd_category' => $category['slug']);
        if (null !== $area) {
            $args['ebd_subject'] = $area['slug'];
        }
        $items[] = array((string) $category['name'], $this->browse_url($path_number, $args));
        return $items;
    }

    private function browse_item(
        string $title,
        string $url,
        string $meta,
        string $description,
        bool $navigation_row = false
    ): string
    {
        if ($navigation_row) {
            return '<li class="ebd-list-item ebd-navigation-item"><a class="ebd-item-title ebd-navigation-link" href="'
                . esc_url($url) . '" data-description="' . esc_attr($description) . '"><span class="ebd-navigation-name">'
                . '<span class="ebd-navigation-chevron" aria-hidden="true">&#8250;</span><span>' . esc_html($title)
                . '</span></span><span class="ebd-item-meta">' . wp_kses_post($meta) . '</span></a>'
                . '<p class="ebd-item-description" hidden>' . esc_html($description) . '</p></li>';
        }

        return '<li class="ebd-list-item"><div class="ebd-item-row"><a class="ebd-item-title" href="'
            . esc_url($url) . '" data-description="' . esc_attr($description) . '">' . esc_html($title)
            . '</a><p class="ebd-item-meta">' . wp_kses_post($meta) . '</p></div><p class="ebd-item-description" hidden>'
            . esc_html($description) . '</p></li>';
    }

    private function description_toggle(bool $checked, string $scope): string
    {
        $this->instance++;
        $id = 'ebd-descriptions-' . $this->instance;
        return '<label class="ebd-description-toggle" for="' . esc_attr($id) . '"><input id="'
            . esc_attr($id) . '" type="checkbox" data-ebd-description-toggle data-scope="' . esc_attr($scope) . '"'
            . checked($checked, true, false) . '><span>' . esc_html__('Show descriptions', 'ehrman-blog-discovery')
            . '</span></label>';
    }

    private function shell(string $content, string $view): string
    {
        return '<section class="ebd-discovery ebd-view-' . esc_attr($view) . '">' . $content . '</section>';
    }

    private function not_ready(): string
    {
        Assets::enqueue();
        return '<div class="ebd-notice">' . esc_html__('Discovery data has not been imported yet.', 'ehrman-blog-discovery') . '</div>';
    }

    private function not_found(): string
    {
        return $this->shell('<p class="ebd-empty">' . esc_html__('The requested discovery page could not be found.', 'ehrman-blog-discovery') . '</p>', 'error');
    }

    private function request_terms(): array
    {
        $raw = isset($_GET['ebd_keyword']) ? wp_unslash($_GET['ebd_keyword']) : array();
        return Search_Service::unique_terms(is_array($raw) ? $raw : array($raw));
    }

    private function request_value(string $name, string $default = ''): string
    {
        if (!isset($_GET[$name]) || is_array($_GET[$name])) {
            return $default;
        }
        return sanitize_text_field(wp_unslash((string) $_GET[$name]));
    }

    private function page_url(string $key): string
    {
        $page_id = (int) get_option('ehrman_discovery_page_' . $key, 0);
        $url = $page_id > 0 ? get_permalink($page_id) : false;
        return false === $url ? home_url('/') : $url;
    }

    private function browse_url(int $path_number, array $args = array()): string
    {
        $url = $this->page_url(2 === $path_number ? 'browse_2' : 'browse_1');
        return empty($args) ? $url : add_query_arg($args, $url);
    }

    private function find_by_slug(array $records, string $slug): ?array
    {
        foreach ($records as $record) {
            if ((string) ($record['slug'] ?? '') === $slug) {
                return $record;
            }
        }
        return null;
    }

    private function plural(int $count, string $singular, string $plural = ''): string
    {
        $word = 1 === $count ? $singular : ('' !== $plural ? $plural : $singular . 's');
        return number_format_i18n($count) . ' ' . $word;
    }
}
