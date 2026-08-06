<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="screen-reader-text" href="#main-content"><?php esc_html_e('Skip to content', 'ehrman-discovery-demo'); ?></a>
<header class="ehrman-site-header">
    <div class="ehrman-utility">
        <div class="ehrman-utility-inner">
            <div class="ehrman-tagline"><?php esc_html_e('Engaging Discussions about Early Christianity', 'ehrman-discovery-demo'); ?></div>
            <div class="ehrman-utility-actions" aria-label="<?php esc_attr_e('Site utility controls', 'ehrman-discovery-demo'); ?>">
                <div class="ehrman-site-search" aria-disabled="true">
                    <input type="search" placeholder="<?php esc_attr_e('Search...', 'ehrman-discovery-demo'); ?>" aria-label="<?php esc_attr_e('Site search', 'ehrman-discovery-demo'); ?>" disabled>
                    <button type="button" disabled><?php esc_html_e('All', 'ehrman-discovery-demo'); ?></button>
                </div>
                <span class="ehrman-utility-item" aria-disabled="true"><?php esc_html_e('Join Now!', 'ehrman-discovery-demo'); ?></span>
                <span class="ehrman-utility-item" aria-disabled="true"><?php esc_html_e('Login', 'ehrman-discovery-demo'); ?></span>
                <span class="ehrman-utility-item" aria-disabled="true"><?php esc_html_e('Account', 'ehrman-discovery-demo'); ?></span>
            </div>
        </div>
    </div>
    <div class="ehrman-brand-row">
        <a class="ehrman-brand" href="<?php echo esc_url(home_url('/')); ?>">
            <span class="ehrman-brand-photo" aria-hidden="true"></span>
            <span class="ehrman-brand-copy">
                <span class="ehrman-brand-title"><?php esc_html_e('The Bart Ehrman Blog:', 'ehrman-discovery-demo'); ?></span>
                <span class="ehrman-brand-subtitle"><?php esc_html_e('The History & Literature of Early Christianity', 'ehrman-discovery-demo'); ?></span>
            </span>
        </a>
        <nav class="ehrman-navigation" aria-label="<?php esc_attr_e('Primary navigation', 'ehrman-discovery-demo'); ?>">
            <span class="is-disabled" aria-disabled="true"><?php esc_html_e('Join!', 'ehrman-discovery-demo'); ?></span>
            <span class="is-disabled" aria-disabled="true"><?php esc_html_e('Recent Posts', 'ehrman-discovery-demo'); ?></span>
            <a href="<?php echo esc_url(ehrman_demo_page_url('keyword-search')); ?>"<?php echo is_page('keyword-search') ? ' aria-current="page"' : ''; ?>><?php esc_html_e('Keyword Search', 'ehrman-discovery-demo'); ?></a>
            <a href="<?php echo esc_url(ehrman_demo_page_url('browse-topics-1')); ?>"<?php echo is_page('browse-topics-1') ? ' aria-current="page"' : ''; ?>><?php esc_html_e('Browse Topics 1', 'ehrman-discovery-demo'); ?></a>
            <a href="<?php echo esc_url(ehrman_demo_page_url('browse-topics-2')); ?>"<?php echo is_page('browse-topics-2') ? ' aria-current="page"' : ''; ?>><?php esc_html_e('Browse Topics 2', 'ehrman-discovery-demo'); ?></a>
            <span class="is-disabled" aria-disabled="true"><?php esc_html_e('Forum', 'ehrman-discovery-demo'); ?></span>
            <span class="is-disabled" aria-disabled="true"><?php esc_html_e('About Blog', 'ehrman-discovery-demo'); ?></span>
            <span class="is-disabled" aria-disabled="true"><?php esc_html_e('About Bart', 'ehrman-discovery-demo'); ?></span>
            <span class="is-disabled" aria-disabled="true"><?php esc_html_e('Help', 'ehrman-discovery-demo'); ?></span>
        </nav>
    </div>
</header>
