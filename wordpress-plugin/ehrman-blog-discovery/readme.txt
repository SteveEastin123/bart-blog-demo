=== Ehrman Blog Discovery ===
Contributors: steveeastin
Tags: search, topics, discovery
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 0.4.0
License: GPLv2 or later

WordPress foundation for browsing and searching the Ehrman Blog index.

== Description ==

This plugin verifies the WordPress/MySQL runtime and imports the authoritative
post, topic, category, subject-area, and secondary-keyword indexes into
dedicated MySQL tables. It provides scoped keyword search, autocomplete, and
two alternative topic-browsing paths.

== Installation ==

1. Copy the plugin directory to `wp-content/plugins/ehrman-blog-discovery`.
2. Activate Ehrman Blog Discovery in WordPress.
3. Add `[ehrman_discovery_status]` to a page to verify the environment.
4. Use `[ehrman_keyword_search]` and `[ehrman_browse_topics path="1"]` or
   `[ehrman_browse_topics path="2"]` on WordPress pages.

== Changelog ==

= 0.4.0 =
* Added a disabled-by-default, token-protected parity endpoint, bounded search
  inputs, portable slug generation, and Phase 5 validation support.

= 0.3.0 =
* Added MySQL-backed search, ranked results, scoped autocomplete, category and
  topic filtering, both browse paths, external post lists, and WordPress pages.

= 0.2.0 =
* Added versioned MySQL tables, transactional JSON importing, validation,
  import status, a secured administrator action, and WP-CLI commands.

= 0.1.0 =
* Added the Phase 2 plugin foundation, status page, and REST health endpoint.
