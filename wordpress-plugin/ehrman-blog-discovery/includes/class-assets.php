<?php

namespace EhrmanBlogDiscovery;

if (!defined('ABSPATH')) {
    exit;
}

final class Assets
{
    private static bool $localized = false;

    public static function enqueue(): void
    {
        wp_enqueue_style(
            'ehrman-blog-discovery',
            EBD_PLUGIN_URL . 'assets/css/discovery.css',
            array(),
            EBD_VERSION
        );
        wp_enqueue_script(
            'ehrman-blog-discovery',
            EBD_PLUGIN_URL . 'assets/js/discovery.js',
            array(),
            EBD_VERSION,
            true
        );

        if (self::$localized) {
            return;
        }
        self::$localized = true;
        wp_localize_script(
            'ehrman-blog-discovery',
            'EhrmanDiscovery',
            array(
                'suggestionsUrl' => esc_url_raw(rest_url('ehrman-discovery/v1/suggestions')),
                'searchUrl' => esc_url_raw(rest_url('ehrman-discovery/v1/search')),
                'statusUrl' => esc_url_raw(rest_url('ehrman-discovery/v1/status')),
                'strings' => array(
                    'topic' => __('Topic', 'ehrman-blog-discovery'),
                    'keyword' => __('Keyword', 'ehrman-blog-discovery'),
                    'post' => __('post', 'ehrman-blog-discovery'),
                    'posts' => __('posts', 'ehrman-blog-discovery'),
                    'noResults' => __('No posts matched this request.', 'ehrman-blog-discovery'),
                    'requestFailed' => __('The search could not be completed. Please try again.', 'ehrman-blog-discovery'),
                    'unknownAuthor' => __('unknown author', 'ehrman-blog-discovery'),
                ),
            )
        );
    }
}
