# WordPress Developer Handoff Package

This document defines the materials to provide to the development company for integrating the Ehrman Blog Discovery functionality into Bart's production WordPress site.

## 1. Installable WordPress Plugin

- `ehrman-blog-discovery.zip`
- Access to the corresponding source-code repository or tagged release

The plugin contains Keyword Search, Browse Topics, the selected title-and-summary semantic Ask AI search, Structure Review, autocomplete, result ranking, category filtering, database integration, data importing, REST endpoints, and administrative tools.

The retired topic/keyword Ask AI interpreter and experimental section-search
plugins are not part of the production plugin. Package the active plugin directory,
not the repository root. Both Browse Topics structures remain until the final
structure is selected. A single Ask AI Analytics dashboard reports on the active
semantic method; retired-method records are retained locally but excluded from
the dashboard and its exports. Historical test data is not part of the discovery
data package.

## 2. Discovery Data

Provide the five authoritative JSON files:

- `ehrman_post_search_index.json`
- `ehrman_post_topics.json`
- `ehrman_post_categories.json`
- `ehrman_post_subject_areas.json`
- `ehrman_post_subject_areas_2.json`

The data includes post metadata, concise descriptions, optional AI-refinement summaries, secondary keywords, organizational structures, and relationships among posts, topics, categories, and subject areas. It does not include the full text of Bart's posts.

Also provide `ehrman_post_embeddings.jsonl.gz`, the versioned, compressed
title-and-summary vector index. The plugin imports it by WordPress post ID and
rejects stale, malformed, duplicate, or incompatible records before changing
the database. The vector package does not contain full post text.

## 3. Integration and Maintenance Guide

Provide a consolidated guide explaining how to:

- Install and configure the plugin
- Import the JSON data and portable vector package
- Match imported records to existing WordPress posts using WordPress IDs or URLs
- Add the search and browsing pages to the production site
- Integrate the plugin with the existing WordPress theme
- Use the plugin's shortcodes, REST endpoints, administrative tools, and WP-CLI commands
- Update the discovery data when new posts are added
- Back up, validate, and roll back an import

Include the database schema and technical architecture as appendices.

### Analytics Presentation

`src/Analytics/AI_Analytics_Renderer.php` loads reports and prepares display values.
The dashboard HTML lives in `templates/admin/analytics/`: `dashboard.php` composes
the filters, question and review tables, period totals, and reset controls.
The source and token partials are reused in the summary and detail displays.
Templates receive explicit context through `Template::render()` and escape values
where they are output; they do not query or modify the database.

Keep authorization in `AI_Analytics_Page`, calculations in `AI_Analytics_Report`,
and CSV/reset operations in their existing handlers. Presentation edits should
not change those behaviors. The analytics PHP and browser regression tests in
`wordpress-production/tests/` cover these boundaries, including escaping,
pagination, empty states, cached/failed requests, exports, and reset protection.

### Post Ingestion Presentation

`src/Ingestion/Post_Ingestion_Page.php` retains page authorization, form handlers,
notice consumption, and display-data preparation. Its remaining page, draft,
review, approved-result, history, and action markup lives in
`templates/admin/post-ingestion/`; the existing new-submission template remains
at `templates/admin/post-ingestion-new.php`. Templates escape display values and
render nonce-protected forms; they do not query the database or run ingestion.

Analysis, review, approval, background processing, and vector generation remain
in the existing ingestion services. The production regression suite exercises
the page's workflow states, form nonces, JSON-mode approval lock, escaping,
history, and notices with rolled-back fixtures and no API calls.

## 4. Testing and Acceptance Package

- JSON and relationship validation scripts
- Expected database-count checks
- Search-parity test cases and results
- WordPress coding-standard and static-analysis configuration
- Security-scan results
- Accessibility, responsive-layout, and browser-testing checklist
- Final production acceptance checklist

These materials allow the developers to verify that their implementation behaves like the approved demo.

## 5. Working Reference Materials

- Live demonstration website
- Companion demo theme as an optional visual reference
- Search and browsing diagrams
- Presentation deck
- Screenshots and examples of expected behavior

The production site should continue using Bart's existing WordPress theme. The demo theme is only a reference for the intended layout and interactions.

## Materials Not Required

Do not include:

- Render deployment documentation
- Bart's complete post contents
- Passwords or hosting credentials
- Internal auditing files and temporary working scripts
- The old Python, PHP, or standalone HTML implementations
- `experiments/`, including Ask AI 3 and other experimental plugins, section
  vectors, benchmark inputs/results, private full-text data and runtime bundles
- Local recovery archives and database backups

The demo Docker build excludes all of `experiments/` through `.dockerignore` and
copies only the production plugin, demo theme, explicit canonical data files and
regression checks. The repository retains experimental source for recovery; that
does not make it part of the installable plugin or the handoff package.

## Summary

The focused handoff package consists of the plugin, authoritative discovery data, integration instructions, validation materials, and a working reference implementation.
