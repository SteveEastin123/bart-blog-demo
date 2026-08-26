# WordPress Developer Handoff Package

This document defines the materials to provide to the development company for integrating the Ehrman Blog Discovery functionality into Bart's production WordPress site.

## 1. Installable WordPress Plugin

- `ehrman-blog-discovery.zip`
- Access to the corresponding source-code repository or tagged release

The plugin contains Keyword Search, Browse Topics, Structure Review, autocomplete, result ranking, category filtering, MySQL integration, data importing, REST endpoints, and administrative tools.

## 2. Discovery Data

Provide the five authoritative JSON files:

- `ehrman_post_search_index.json`
- `ehrman_post_topics.json`
- `ehrman_post_categories.json`
- `ehrman_post_subject_areas.json`
- `ehrman_post_subject_areas_2.json`

The data includes post metadata, concise descriptions, optional AI-refinement summaries, secondary keywords, organizational structures, and relationships among posts, topics, categories, and subject areas. It does not include the full text of Bart's posts.

## 3. Integration and Maintenance Guide

Provide a consolidated guide explaining how to:

- Install and configure the plugin
- Import the JSON data
- Match imported records to existing WordPress posts using WordPress IDs or URLs
- Add the search and browsing pages to the production site
- Integrate the plugin with the existing WordPress theme
- Use the plugin's shortcodes, REST endpoints, administrative tools, and WP-CLI commands
- Update the discovery data when new posts are added
- Back up, validate, and roll back an import

Include the database schema and technical architecture as appendices.

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

## Summary

The focused handoff package consists of the plugin, authoritative discovery data, integration instructions, validation materials, and a working reference implementation.
