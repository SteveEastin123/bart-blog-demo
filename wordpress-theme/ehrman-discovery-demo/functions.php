<?php

if (!defined('ABSPATH')) {
    exit;
}

function ehrman_demo_setup(): void
{
    add_theme_support('title-tag');
    add_theme_support('html5', array('search-form', 'style', 'script'));
}
add_action('after_setup_theme', 'ehrman_demo_setup');

function ehrman_demo_assets(): void
{
    $version = wp_get_theme()->get('Version');
    wp_enqueue_style('ehrman-discovery-demo', get_stylesheet_uri(), array(), $version);
}
add_action('wp_enqueue_scripts', 'ehrman_demo_assets');

function ehrman_demo_page_url(string $slug): string
{
    return home_url('/' . trim($slug, '/') . '/');
}

function ehrman_demo_post_range(): string
{
    global $wpdb;

    $table = $wpdb->prefix . 'ehrman_external_posts';
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($exists !== $table) {
        return '';
    }

    $range = $wpdb->get_row("SELECT MIN(published_at) AS first_post, MAX(published_at) AS last_post, COUNT(*) AS post_count FROM {$table}", ARRAY_A);
    if (!is_array($range) || empty($range['first_post']) || empty($range['last_post'])) {
        return '';
    }

    $first = wp_date('F j, Y', strtotime((string) $range['first_post']));
    $last = wp_date('F j, Y', strtotime((string) $range['last_post']));
    return sprintf(
        /* translators: 1: first post date, 2: latest post date, 3: post count. */
        __('Posts from %1$s through %2$s (%3$s posts)', 'ehrman-discovery-demo'),
        $first,
        $last,
        number_format_i18n((int) $range['post_count'])
    );
}
