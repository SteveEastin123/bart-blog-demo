<?php
get_header();
$post_range = ehrman_demo_post_range();
$diagram_directory = get_template_directory() . '/assets/images/';
$diagram_uri = get_template_directory_uri() . '/assets/images/';
$desktop_diagram_path = $diagram_directory . 'ehrman-search-methods.svg';
$mobile_diagram_path = $diagram_directory . 'ehrman-search-methods-mobile.svg';
$desktop_diagram_url = add_query_arg(
    'ver',
    (string) filemtime($desktop_diagram_path),
    $diagram_uri . 'ehrman-search-methods.svg'
);
$mobile_diagram_url = add_query_arg(
    'ver',
    (string) filemtime($mobile_diagram_path),
    $diagram_uri . 'ehrman-search-methods-mobile.svg'
);
?>
<main id="main-content" class="ehrman-main">
    <section class="ehrman-home">
        <div class="ehrman-hero" role="img" aria-label="<?php esc_attr_e('Bart Ehrman lecturing', 'ehrman-discovery-demo'); ?>"></div>
        <section class="ehrman-intro-band" aria-label="<?php esc_attr_e('Demo introduction', 'ehrman-discovery-demo'); ?>">
            <div class="ehrman-intro-inner">
                <p><?php echo wp_kses_post(__('This demo offers two ways to discover posts on Bart\'s blog: <strong>Keyword Search</strong> and <strong>Browse Topics</strong>.', 'ehrman-discovery-demo')); ?></p>
                <p><?php echo wp_kses_post(__('<strong>Keyword Search</strong> works best for readers who already know what they want to find. Readers can optionally select a category and combine up to four topics or secondary keywords.', 'ehrman-discovery-demo')); ?></p>
                <p><?php echo wp_kses_post(__('<strong>Browse Topics</strong> guides readers from subject areas to categories, topics, and related posts. <strong>Browse Topics 1</strong> and <strong>Browse Topics 2</strong> organize the same collection differently; only one will appear in the final version.', 'ehrman-discovery-demo')); ?></p>
                <aside class="ehrman-reviewer-tools" aria-labelledby="ehrman-reviewer-tools-title">
                    <h2 id="ehrman-reviewer-tools-title"><?php esc_html_e('Reviewer Tools', 'ehrman-discovery-demo'); ?></h2>
                    <p><?php esc_html_e('Use the structure review to evaluate how subject areas, categories, and topics are organized.', 'ehrman-discovery-demo'); ?></p>
                    <a href="<?php echo esc_url(add_query_arg('ebd_path', '1', ehrman_demo_page_url('structure-review'))); ?>"><?php esc_html_e('Review subject areas, categories, and topics', 'ehrman-discovery-demo'); ?></a>
                </aside>
                <figure class="ehrman-methods-figure">
                    <picture>
                        <source media="(max-width: 700px)" srcset="<?php echo esc_url($mobile_diagram_url); ?>">
                        <img src="<?php echo esc_url($desktop_diagram_url); ?>" alt="<?php esc_attr_e('Diagram comparing keyword search using topics and secondary keywords with topic browsing through subject areas, categories, topics, and posts', 'ehrman-discovery-demo'); ?>">
                    </picture>
                </figure>
                <?php if ('' !== $post_range) : ?>
                    <p class="ehrman-demo-meta"><?php echo esc_html($post_range); ?></p>
                <?php endif; ?>
            </div>
        </section>
    </section>
</main>
<?php get_footer(); ?>
