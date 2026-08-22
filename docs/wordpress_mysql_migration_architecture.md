# WordPress/MySQL Migration Architecture

## Status

This document began as the Phase 1 architecture checkpoint for migrating the
Ehrman search demo to a WordPress plugin backed by MySQL. The approved Phase 2
local environment, Phase 3 schema and importer, and Phase 4 reader interface
live in `wordpress-demo/` and `wordpress-plugin/ehrman-blog-discovery/`. They
remain separate from every current implementation.

The existing systems remain intact:

- Self-contained HTML demo
- PHP/SQLite Render demo
- Python/SQLite reference application
- Authoritative JSON data
- Existing parity and validation tests

The WordPress implementation is being developed alongside these systems and
will not replace them until it has passed parity testing and is explicitly
approved.

The production-equivalent local package now lives in
`wordpress-production/`. It bakes the plugin, companion theme, and five
authoritative JSON sources into an immutable Docker image and has passed the
local acceptance gate documented in
`docs/wordpress_local_production_readiness_report.md`. The inactive staging
Blueprint is `render-wordpress.yaml`; the active root Render configuration is
unchanged.

## Confirmed Product Boundaries

The WordPress plugin will provide:

- Keyword Search
- Browse Topics 1
- Browse Topics 2
- Subject-area, category, and topic result pages
- Category-scoped searches
- Up to four combined topic or secondary-keyword terms
- Ranked, newest-first, and oldest-first sorting
- Autocomplete with post counts and topic/keyword labels
- Post descriptions and external post links

The plugin will not:

- Store or display full Bart Ehrman Blog post content
- Fetch Bart's site while performing searches or autocomplete
- Proxy member-only content
- Replace WordPress authentication, membership, comments, or publishing
- Include the dormant Featured Topics behavior

Searches will use local MySQL index data. Selecting a result will open the
corresponding post URL on Bart's website in a new browser tab.

## Current Reference Baseline

The authoritative data currently validates with these counts:

| Record | Count |
| --- | ---: |
| Posts | 4,390 |
| Browse Topics 1 subject areas | 10 |
| Browse Topics 2 subject areas | 9 |
| Categories | 41 |
| Topic metadata records | 272 |
| Unique post topics | 272 |
| Unique secondary keywords | 1,051 |
| Post-topic links | 8,573 |
| Post-keyword links | 21,586 |

Known, accepted baseline warnings include duplicate post titles, categories
that are intentionally not alphabetical, a category repeated across multiple
subject areas, two visible topics that are not currently linked to categories,
and the hidden `Ignore` topic. Two linked topics are also absent from their
category's explicit ordering list and receive position zero during import.
URLs, not titles, remain the unique post key.

## Authoritative Input Files

The WordPress importer will read these files:

```text
data/index/ehrman_post_search_index.json
data/index/ehrman_post_topics.json
data/index/ehrman_post_categories.json
data/index/ehrman_post_subject_areas.json
data/index/ehrman_post_subject_areas_2.json
```

Their responsibilities are:

| File | Responsibility |
| --- | --- |
| `ehrman_post_search_index.json` | External post metadata, descriptions, topics, and secondary keywords |
| `ehrman_post_topics.json` | Topic descriptions, browser visibility, category relationships, and hidden search aliases |
| `ehrman_post_categories.json` | Category descriptions and ordered topic lists |
| `ehrman_post_subject_areas.json` | Browse Topics 1 hierarchy and ordering |
| `ehrman_post_subject_areas_2.json` | Browse Topics 2 hierarchy and ordering |

The `featuredOrder` property can remain in the source JSON as inactive
historical metadata. The WordPress importer will ignore it, and the MySQL
schema will not contain a featured-topic column.

## Runtime Architecture

```text
Reader browser
    |
    v
WordPress web service (Apache + PHP)
    |
    +-- WordPress theme: site header, footer, and general page layout
    |
    +-- Ehrman Discovery plugin: search, browsing, templates, and REST routes
    |
    v
MySQL private service
    |
    +-- WordPress core tables
    +-- Plugin search-index tables

Result click ---------------------------------> Bart's actual post URL
```

WordPress and MySQL will run in separate containers. In local development they
will be coordinated with Docker Compose. On Render they will be separate web
and private services connected through Render's private network.

## Plugin Structure

The proposed working plugin name is `Ehrman Blog Discovery`. Its source will
live in a new directory and will not alter `phpapp` or `webapp`.

```text
wordpress-plugin/ehrman-blog-discovery/
  ehrman-blog-discovery.php
  includes/
    class-activator.php
    class-database.php
    class-importer.php
    class-search-service.php
    class-browse-service.php
    class-rest-controller.php
    class-page-controller.php
    class-assets.php
  assets/
    css/discovery.css
    js/discovery.js
  uninstall.php
```

Phase 4 keeps the rendering methods in `class-page-controller.php`. This makes
the proof of concept compact while preserving a clear boundary between the
WordPress integration layer, MySQL services, and browser assets. Templates can
be extracted later if the production theme requires overrideable markup.

The plugin will own the search and browse content region. The active WordPress
theme will continue to own the site header, navigation, footer, and global
typography. A small companion demo theme may be used in the Render staging site
to approximate the current demo without coupling production functionality to
that theme.

## WordPress Integration Surface

The plugin will expose shortcodes for straightforward theme integration:

```text
[ehrman_keyword_search]
[ehrman_browse_topics path="1"]
[ehrman_browse_topics path="2"]
```

For the staging demo, activation can create corresponding WordPress pages when
they do not already exist. Page IDs will be saved in WordPress options rather
than hard-coded.

The plugin will use WordPress REST routes for autocomplete and dynamic search:

```text
/wp-json/ehrman-discovery/v1/suggestions
/wp-json/ehrman-discovery/v1/search
/wp-json/ehrman-discovery/v1/status
```

The dedicated parity route is reserved for Phase 5 and will remain disabled
unless a test token is configured.
Administrative import operations will require an authenticated administrator,
the `manage_options` capability, and a valid WordPress nonce.

Phase 4 provisions three ordinary WordPress pages on `init` when they do not
already exist: `/keyword-search/`, `/browse-topics-1/`, and
`/browse-topics-2/`. The pages contain only the plugin shortcodes, so the active
theme retains control of the global site layout.

## MySQL Data Model

The proposed schema is in `docs/wordpress_mysql_schema_proposal.sql`.

Runtime table names will use the active WordPress table prefix. For example,
`wp_ehrman_topics` is illustrative; the actual prefix may not be `wp_`.

The design uses dedicated plugin tables because:

- Topics can belong to multiple categories.
- Categories can appear in multiple subject-area paths.
- Search ranking needs a compact denormalized term index.
- The staging records represent external posts rather than local `wp_posts`.
- Dedicated tables avoid overloading `wp_postmeta` with high-volume joins.

Two browsing alternatives are represented with one shared model:

```text
browse_paths
  -> subject_areas
      -> subject_area_categories
          -> categories
              -> topic_categories
                  -> topics
                      -> post_topics
                          -> external_posts
```

This replaces the SQLite implementation's two separate subject-area table
families with a normalized path model.

The proposal intentionally omits database foreign-key constraints. WordPress
plugins commonly manage relationship integrity in application code because
WordPress's `dbDelta()` upgrade behavior and hosting environments vary. Primary
keys, unique keys, and lookup indexes remain enforced by MySQL.

## External Post Identity

Each indexed post will have an internal plugin ID and two source identifiers:

- `source_wp_id`: Bart's WordPress post ID when available
- `url`: the canonical external URL

The URL will be protected from duplication with a SHA-256 `url_hash` unique
key because MySQL cannot safely create a full unique index over every possible
2,048-character UTF-8 URL.

Titles are not identifiers because duplicate titles are valid in the current
dataset.

## Search Contract

The WordPress plugin must preserve the approved search behavior.

### Term matching

- Search terms are normalized case-insensitively.
- Multiple selected terms use AND/intersection behavior.
- A selected category limits the eligible post population before term filters.
- A selected topic limits the eligible population to posts assigned that topic.
- Whole terms and contained normalized phrases match; substrings inside words do
  not match (`hell` must not match `nutshell`).
- Identical typed values can resolve to a topic or secondary keyword without
  requiring a mouse selection.
- A hidden topic alias resolves to the canonical topic and its post set. The
  alias is shown only after a matching query is typed, and autocomplete displays
  the canonical topic label rather than a duplicate alias label.

### Base weights

| Match source | Base weight | Exact normalized bonus |
| --- | ---: | ---: |
| Topic | 6 | 2 |
| Topic alias | 6 | 2 |
| Secondary keyword | 3 | 2 |

### Ranking boosts

| Evidence | Additional score |
| --- | ---: |
| Full selected phrase in title | 4 |
| Anchor term from a phrase in title | 2 |
| Single selected word in title | 1 |
| Full selected phrase in description | 2 |
| Anchor term from a phrase in description | 1 |

Rank ties are broken by publication date, then URL, using the current demo's
stable ordering rules. Newest-first and oldest-first ignore ranking score.

## Autocomplete Contract

Autocomplete must preserve these behaviors:

- The first box remains empty until the reader types, unless a category has
  been selected.
- A selected category scopes available topics, keywords, and post counts.
- Subsequent boxes reflect the intersection created by earlier selections.
- Already selected terms are excluded from later suggestions.
- Topic and keyword labels are both shown when relevant.
- Suggestions are ordered by the approved matching and post-count rules.
- Selecting or removing a term refreshes result counts and available choices.
- No Featured Topics heading or starter list is produced.

## Import Contract

Phase 3 implements a complete, checksum-aware synchronization of the five
authoritative JSON files. Incremental importing can be added later if the
dataset grows enough to justify the additional complexity.

Before writing, it will validate:

- Required top-level JSON shapes
- Unique post URLs and URL hashes
- Unique source WordPress IDs when present
- Valid, parseable publication dates
- Exact topic-name references
- Exact category-name references
- Exact subject-area category references
- Duplicate topic and keyword assignments within posts
- Maximum field lengths after normalization
- Counts against the source JSON

The importer validates all sources before opening a transaction. It then clears
and repopulates the dedicated plugin tables within one InnoDB transaction. Any
failure rolls back to the previously imported dataset. An unchanged combined
source checksum skips the write entirely, while a forced import performs a
complete replacement without duplicating records.

The importer will record:

- Import version
- Source-data checksum
- Start and completion timestamps
- Record counts
- Validation warnings and failures

No credentials or post bodies will be written to the plugin tables.

The implemented Phase 3 controls include:

- Versioned schema installation and upgrades through `dbDelta()`
- Administrator-only, nonce-protected importing under **Tools > Ehrman Discovery**
- `wp ehrman-discovery import` and `wp ehrman-discovery status` commands
- Stored import version, checksum, timestamps, counts, warnings, and failure state
- Exact post, topic, category, keyword, and relationship-count verification
- Checksum no-op and transaction-rollback verification in the local environment

## Security And WordPress Conventions

The implementation will use:

- `$wpdb->prepare()` for parameterized SQL
- WordPress REST argument validation and sanitization
- `esc_html()`, `esc_attr()`, and `esc_url()` at output boundaries
- Nonces and capability checks for administrative operations
- Bounded request sizes and a maximum of four selected search terms
- Rate-friendly public read endpoints
- Environment variables for parity tokens and deployment secrets
- No credentials committed to Git

Post links open in the current tab so navigation matches the behavior readers
will experience on Bart's blog.

## Performance Plan

The dataset is small enough for MySQL to return searches quickly, but the
plugin will still use:

- Composite indexes on every relationship traversal
- A denormalized normalized-term table for search intersections
- Cached category, topic, and autocomplete dictionaries
- WordPress object-cache integration when available
- Bounded autocomplete result sets
- Query-count and timing instrumentation in development
- Pagination or incremental rendering for very large post lists if needed

The acceptance target is no material perceived slowdown compared with the
current PHP/SQLite Render demo.

## Local And Render Environments

### Local

```text
Docker Compose
  - wordpress (public localhost port)
  - mysql (internal Docker network)
  - persistent named volumes
```

### Render

```text
WordPress Docker web service
  - public `onrender.com` URL
  - persistent WordPress disk

MySQL Docker private service
  - private-network access only
  - persistent database disk
  - scheduled logical backups with `mysqldump`
```

The current `bart-blog-demo` service will remain available during development.

## Acceptance Gates

The WordPress/MySQL implementation must satisfy all of these before handoff:

1. Plugin activates and upgrades without warnings or data loss.
2. Import counts match the authoritative JSON.
3. The 500-case parity suite reports no unapproved behavioral differences.
4. Autocomplete counts and ordering match the reference implementation.
5. Browse Topics 1 and 2 reproduce the approved hierarchy and ordering.
6. Every post link points to the authoritative external URL.
7. No full post bodies are stored locally.
8. Keyboard, mobile, and desktop workflows pass browser testing.
9. MySQL queries use the intended indexes and meet the response-time target.
10. The installation ZIP and documentation work on a clean WordPress instance.

## Phase 1 Approval Decisions

Approval of this architecture confirms these choices:

1. Use one plugin for Keyword Search and both Browse Topics paths.
2. Use dedicated MySQL plugin tables rather than WordPress post metadata.
3. Store external post metadata and indexing data, but not full post bodies.
4. Run searches locally and visit Bart's site only when a result is opened.
5. Preserve existing demos unchanged as reference and fallback systems.
6. Exclude Featured Topics from the WordPress implementation.
7. Use shortcodes and REST routes so the development company can integrate the
   plugin into its final theme without rewriting the core functionality.
