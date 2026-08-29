<?php
get_header();
$post_range = ehrman_demo_post_range();
$diagram_directory = get_template_directory() . '/assets/images/';
$diagram_uri = get_template_directory_uri() . '/assets/images/';
$desktop_diagram_path = $diagram_directory . 'ehrman-search-methods.svg';
$mobile_diagram_path = $diagram_directory . 'ehrman-search-methods-mobile.svg';
$desktop_ai_diagram_path = $diagram_directory . 'ehrman-ai-search-methods.svg';
$mobile_ai_diagram_path = $diagram_directory . 'ehrman-ai-search-methods-mobile.svg';
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
$desktop_ai_diagram_url = add_query_arg(
    'ver',
    (string) filemtime($desktop_ai_diagram_path),
    $diagram_uri . 'ehrman-ai-search-methods.svg'
);
$mobile_ai_diagram_url = add_query_arg(
    'ver',
    (string) filemtime($mobile_ai_diagram_path),
    $diagram_uri . 'ehrman-ai-search-methods-mobile.svg'
);
?>
<main id="main-content" class="ehrman-main">
    <section class="ehrman-home">
        <div class="ehrman-hero" role="img" aria-label="<?php esc_attr_e('Bart Ehrman lecturing', 'ehrman-discovery-demo'); ?>"></div>
        <section class="ehrman-intro-band" aria-label="<?php esc_attr_e('Demo introduction', 'ehrman-discovery-demo'); ?>">
            <div class="ehrman-intro-inner">
                <p><?php echo wp_kses_post(__('This demo offers three ways to find posts on Bart\'s blog: <strong>Browse Topics</strong>, <strong>Keyword Search</strong>, and <strong>AI-assisted search</strong>. Two alternative Browse Topics structures and two alternative AI search methods are included for evaluation.', 'ehrman-discovery-demo')); ?></p>
                <p><?php echo wp_kses_post(__('<strong>Browse Topics</strong> guides readers from subject areas to categories, topics, and related posts. <strong>Browse Topics 1</strong> and <strong>Browse Topics 2</strong> organize the same collection differently; only one will appear in the final version.', 'ehrman-discovery-demo')); ?></p>
                <p><?php echo wp_kses_post(__('<strong>Keyword Search</strong> works best for readers who already know what they want to find. Readers can optionally select a category and combine up to four topics or secondary keywords.', 'ehrman-discovery-demo')); ?></p>
                <p><?php echo wp_kses_post(__('<strong>Ask AI 1</strong> and <strong>Ask AI 2</strong> let readers ask a question or describe a subject they want to explore. Each uses a different method to find relevant posts. The approach that produces the most accurate and useful results during testing will be selected for the final version.', 'ehrman-discovery-demo')); ?></p>
                <p><?php echo wp_kses_post(__('<strong>Reviewer Tools</strong> provides an expandable outline of both Browse Topics structures, allowing reviewers to examine how subject areas, categories, and topics are organized before the final structure is selected.', 'ehrman-discovery-demo')); ?></p>
                <figure class="ehrman-methods-figure">
                    <picture>
                        <source media="(max-width: 700px)" srcset="<?php echo esc_url($mobile_diagram_url); ?>">
                        <img src="<?php echo esc_url($desktop_diagram_url); ?>" alt="<?php esc_attr_e('Diagram comparing topic browsing through subject areas, categories, topics, and posts with keyword search using topics and secondary keywords', 'ehrman-discovery-demo'); ?>">
                    </picture>
                </figure>
                <figure class="ehrman-methods-figure ehrman-ai-methods-figure">
                    <picture>
                        <source media="(max-width: 700px)" srcset="<?php echo esc_url($mobile_ai_diagram_url); ?>">
                        <img src="<?php echo esc_url($desktop_ai_diagram_url); ?>" alt="<?php esc_attr_e('Diagram comparing Ask AI 1, which translates a question into curated topics and keywords, with Ask AI 2, which compares the question with post titles and summaries; both approaches use AI to review and rank relevant posts', 'ehrman-discovery-demo'); ?>">
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
