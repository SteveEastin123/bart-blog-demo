# WordPress/MySQL Phase 5 Validation Report

Date: August 6, 2026
Plugin version: 0.4.0
Local stack: WordPress, PHP 8.3, MySQL 8.4, Docker Compose

## Result

Phase 5 passed. WordPress/MySQL reproduced the approved search and browse
behavior with no unapproved parity differences. Security, integrity,
accessibility, responsive-layout, and performance checks also passed.

## 500-Case Parity

The deterministic standard suite covered global, category, and topic searches;
all three sort modes; one- through four-term intersections; autocomplete;
selected-term narrowing; both browse structures; metadata; and ordering.

- Cases compared: 500
- Exact matches: 499
- Approved variances: 1
- Unapproved differences: 0

The approved variance is `suggest-starter-000015`. The reference application
still returns its former Featured Topics list for a blank, unscoped suggestion
request. The WordPress architecture deliberately excludes Featured Topics and
returns no suggestions until a reader types or selects a scope. This decision
was approved before Phase 5.

During parity testing, one genuine defect was found and fixed: WordPress's
default slug rule removed apostrophes while the approved demos replace them
with separators. The importer now uses the authoritative normalization rule,
so deep links and scoped searches match the reference.

## Data Integrity

The imported dataset matched the authoritative source counts:

- 2 browse paths
- 19 subject areas
- 41 categories
- 272 topics
- 4,390 external posts
- 1,051 secondary keywords
- 83 subject-area/category links
- 313 topic/category links
- 8,573 post/topic links
- 21,586 post/keyword links
- 30,159 searchable post terms

Read-only MySQL checks found zero orphaned relationships, missing required post
metadata, duplicate source WordPress IDs, or duplicate normalized keywords.
The plugin stores post metadata and discovery links, not full post bodies.

## Security

- Administrative imports require `manage_options` and a WordPress nonce.
- Public request inputs are sanitized, limited to four terms, and capped at
  the indexed 191-character length.
- Dynamic SQL values use `$wpdb->prepare()` or integer-only identifier lists.
- The parity endpoint is registered only when an environment token is present.
- Missing or incorrect parity tokens return HTTP 403 while enabled.
- Invalid schemas and invalid batches return HTTP 400.
- The parity endpoint sends `Cache-Control: no-store`.
- With no configured token, the parity endpoint returns HTTP 404.
- The public status response no longer exposes the MySQL version.
- No credentials are stored in the plugin or Compose configuration.

## Accessibility And Responsive Behavior

Browser checks covered Keyword Search and both Browse Topics paths.

- Suggestions expose textbox, listbox, and option semantics.
- Search terms, remove buttons, category selection, sorting, and description
  controls have accessible names and keyboard behavior.
- Each tested page has one main landmark and one level-one heading.
- No duplicate IDs, skipped heading levels, missing image alt attributes, or
  browser console errors were found.
- A 390 by 844 mobile viewport had no horizontal overflow; controls remained
  within the viewport and the theme navigation collapsed to its mobile menu.

## Warm Performance

Each case used 3 warmups and 30 measured requests on the local Docker stack.

| Case | Median | P95 | Maximum |
| --- | ---: | ---: | ---: |
| Single-term search (`Luke`, 626 results) | 296.0 ms | 393.7 ms | 418.3 ms |
| Two-term search | 56.9 ms | 79.4 ms | 90.2 ms |
| Three-term search | 62.7 ms | 85.3 ms | 88.4 ms |
| Four-term search | 65.9 ms | 81.2 ms | 82.4 ms |
| Global autocomplete | 107.4 ms | 118.1 ms | 125.2 ms |
| Narrowed autocomplete | 54.7 ms | 58.1 ms | 72.2 ms |
| Category page | 113.6 ms | 121.9 ms | 122.0 ms |
| Topic page | 120.1 ms | 136.4 ms | 137.2 ms |

Relationship traversals use the intended composite indexes. Whole-term phrase
matching scans the small 30,159-row normalized-term index because it supports
matches such as `hell` within `heaven and hell`; the measured worst case remains
under 400 ms at p95 locally. Production caching can reduce repeated requests.

## Reproduction

1. Start the local stack with `wordpress-demo/setup-wordpress.ps1`.
2. Force-import the authoritative JSON with the plugin's WP-CLI command.
3. Run `wordpress-demo/verify-wordpress.ps1 -RequireImport`.
4. Run `wordpress-demo/benchmark-wordpress.ps1`.
5. Temporarily enable `EHRMAN_DISCOVERY_PARITY_TOKEN`, capture the 500 cases,
   and compare with only `suggest-starter-000015` allowed.
6. Remove the token and recreate WordPress; verify the parity route returns 404.
