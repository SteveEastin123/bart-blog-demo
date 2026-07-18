# Ehrman Blog JSON Integration Guide for WordPress

This document describes the JSON files used by the Ehrman search demo and how a WordPress developer can import them into a production WordPress implementation.

The JSON files define a topic-browsing and keyword-search layer for Bart Ehrman's blog posts. They are intended to support:

- Category browsing
- Topic browsing
- Topic-to-post result pages
- Keyword search with autocomplete
- Post descriptions shown in search or hover states

## Source Files

The current integration package uses three JSON files:

```text
data/index/ehrman_post_categories.json
data/index/ehrman_post_topics.json
data/index/ehrman_post_search_index.json
```

These files are related, but they serve different purposes.

| File | Purpose | Approximate size |
| --- | --- | ---: |
| `ehrman_post_categories.json` | Defines broad category names and descriptions. | 6 KB |
| `ehrman_post_topics.json` | Defines topic names, descriptions, category links, and browser visibility. | 84 KB |
| `ehrman_post_search_index.json` | Defines post-level metadata, topic links, secondary keywords, and descriptions. | 2.5 MB |

## Current Record Counts

As of the current local data:

| Item | Count |
| --- | ---: |
| Categories | 32 |
| Topic metadata records | 291 |
| Topics displayed in browser | 290 |
| Hidden topics | 1 |
| Category-topic links | 348 |
| Posts | 4,373 |
| Unique post URLs | 4,373 |
| Duplicate post titles | 36 |
| Posts with no topics | 0 |
| Posts with no secondary keywords | 263 |

The duplicate-title count is expected. Do not use title as the unique identifier. Use `wpId` when it matches the production WordPress post ID; otherwise use `url`.

## Conceptual Model

The content model has three levels:

1. A **Category** is a broad grouping.
2. A **Topic** is a narrower topic. A topic can belong to more than one category.
3. A **Post** can have one or more topics and zero or more secondary keywords.

```mermaid
flowchart TD
    C["Category"] --> T["Topic"]
    T --> P["Post"]
    P --> K["Secondary Keyword"]
    T --> K2["Topic as Search Keyword"]
```

Topics function as the primary topic tags for posts. Secondary keywords add supporting search terms that are not always broad enough to be topics.

## Entity Relationship Diagram

```mermaid
erDiagram
    CATEGORY ||--o{ THEME_CATEGORY : contains
    THEME ||--o{ THEME_CATEGORY : assigned_to
    THEME ||--o{ POST_THEME : tags
    POST ||--o{ POST_THEME : has
    POST ||--o{ POST_SECONDARY_KEYWORD : has

    CATEGORY {
        string name
        string description
    }

    THEME {
        string name
        string description
        boolean displayInBrowser
    }

    POST {
        string wpId
        string title
        string url
        string dateText
        string author
        string description
    }

    THEME_CATEGORY {
        string topicName
        string categoryName
    }

    POST_THEME {
        string wpId
        string topicName
    }

    POST_SECONDARY_KEYWORD {
        string wpId
        string keyword
    }
```

## File 1: `ehrman_post_categories.json`

### Purpose

This file defines the broad categories shown on the category browsing page.

### Top-Level Shape

```json
{
  "categories": [
    {
      "name": "Acts and Early Christianity",
      "description": "Covers Acts and the earliest Christian movement, including conversion, communities, evangelism, miracles, Paul in Acts, and Christian expansion."
    }
  ]
}
```

### Schema

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `categories` | array | yes | Top-level category records. |
| `categories[].name` | string | yes | Unique display name for the category. |
| `categories[].description` | string | yes | One-sentence category description. |

### WordPress Use

Recommended storage options:

- Custom database table: `ehrman_topic_categories`
- Or custom taxonomy parent terms, if using WordPress taxonomies

Recommended fields:

```text
id
name
slug
description
sort_order
```

Use the JSON order as the default display order unless the site wants alphabetical ordering.

## File 2: `ehrman_post_topics.json`

### Purpose

This file defines all topics, their descriptions, the categories they belong to, and whether they should appear in the browser.

### Top-Level Shape

```json
{
  "topics": [
    {
      "name": "Acts (General)",
      "description": "Posts introducing Acts, its literary features, major topics, theology, and account of Christianity's spread from Jerusalem to Rome.",
      "categories": [
        "Acts and Early Christianity"
      ],
      "displayInBrowser": true
    }
  ]
}
```

### Schema

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `topics` | array | yes | Top-level topic records. |
| `topics[].name` | string | yes | Unique topic name. |
| `topics[].description` | string | yes | One-sentence topic description. |
| `topics[].categories` | array of strings | yes | Category names from `ehrman_post_categories.json`. May contain more than one category. |
| `topics[].displayInBrowser` | boolean | yes | If false, the topic should not appear in category/topic browsing. |

### Important Topic Rules

- Topic names must match exactly between `ehrman_post_topics.json` and post `topics` arrays in `ehrman_post_search_index.json`.
- Category names in `topics[].categories` must match category `name` values exactly.
- A topic can belong to more than one category.
- `Ignore` is a valid topic, but it has `displayInBrowser: false` and should not appear in the browsing UI.

### WordPress Use

Recommended storage options:

- Custom taxonomy `ehrman_topic`
- Or custom table `ehrman_topics`

Because topics can belong to multiple categories, do not assume a simple parent-child taxonomy is enough unless the implementation supports many-to-many relationships.

Recommended tables:

```text
ehrman_topics
- id
- name
- slug
- description
- display_in_browser

ehrman_topic_categories
- topic_id
- category_id
```

## File 3: `ehrman_post_search_index.json`

### Purpose

This is the post-level search index. It contains post metadata, post descriptions, topic assignments, and secondary keywords.

Despite the earlier working filename, this is not merely a keyword file. It is the main post search index.

### Top-Level Shape

This file is a top-level array of post records:

```json
[
  {
    "wpId": "50126",
    "title": "Christian Justifications for Lying",
    "url": "https://ehrmanblog.org/christian-justifications-for-lying/",
    "dateText": "July 9, 2026",
    "author": "BDEhrman",
    "description": "Examines whether early Christian forgers could justify deception as a noble lie, contrasting that view with Augustine's rejection of all lying.",
    "topics": [
      "Forgery (General)",
      "Moral Philosophy"
    ],
    "secondaryKeywords": [
      "Noble Lie",
      "Augustine",
      "Plato",
      "Lying",
      "Christian Forgery",
      "Forged",
      "Forgery and Counterforgery"
    ]
  }
]
```

### Schema

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `wpId` | string | yes | WordPress post ID from the source system, stored as a string. |
| `title` | string | yes | Display title. Not unique. |
| `url` | string | yes | Canonical post URL. Unique in this dataset. |
| `dateText` | string | yes | Human-readable date, such as `July 9, 2026`. |
| `author` | string | yes | Author display name. |
| `description` | string | yes | Brief post description for result pages and hover/display states. |
| `topics` | array of strings | yes | Primary topic topics. Values must match `ehrman_post_topics.json`. |
| `secondaryKeywords` | array of strings | yes | Supporting search keywords. May be empty. |

### WordPress Use

Recommended post matching order:

1. Match by `wpId` if it is confirmed to be the production WordPress post ID.
2. If `wpId` is not reliable in production, match by canonical `url`.
3. Do not match by `title`, because some titles are duplicated.

Recommended storage:

```text
ehrman_post_index
- wp_post_id
- source_wp_id
- canonical_url
- title
- author
- date_text
- description

ehrman_post_topics
- wp_post_id
- topic_id

ehrman_post_secondary_keywords
- wp_post_id
- keyword
- normalized_keyword
```

The `description` field could also be stored as post meta, for example:

```text
_ehrman_search_description
```

## Import Order

Import in this order:

```mermaid
flowchart LR
    A["Load categories JSON"] --> B["Create/update categories"]
    B --> C["Load topics JSON"]
    C --> D["Create/update topics"]
    D --> E["Create topic-category links"]
    E --> F["Load search index JSON"]
    F --> G["Match posts by wpId or URL"]
    G --> H["Create post-topic links"]
    H --> I["Create secondary keyword index"]
    I --> J["Validate counts and missing links"]
```

## Search Behavior

The current demo searches against:

- Topic names from each post's `topics` array
- Secondary keywords from each post's `secondaryKeywords` array

In production, the topic names should be treated as primary search keywords. The secondary keywords should support narrower searches, autocomplete, and ranking.

Recommended search matching:

1. Normalize query terms by lowercasing and removing punctuation.
2. Search exact normalized keyword matches first.
3. Rank posts higher when a query term matches a topic.
4. Rank posts lower when a query term matches only a secondary keyword.
5. For multi-keyword searches, require all entered keywords to match unless the UI explicitly supports "any term" searching.

Example ranking model:

| Match type | Suggested score |
| --- | ---: |
| Topic match | 5 |
| Secondary keyword match | 2 |
| Title match, if added later | 1 |

## Suggested WordPress Architecture

For a production site, use a plugin rather than embedding the full JSON in a static HTML file.

Recommended plugin pieces:

```text
ehrman-topic-browser/
- ehrman-topic-browser.php
- includes/
  - class-importer.php
  - class-search-index.php
  - class-rest-controller.php
  - class-shortcodes.php
- assets/
  - topic-browser.js
  - topic-browser.css
```

Suggested shortcodes or blocks:

```text
[ehrman_categories]
[ehrman_keyword_search]
[ehrman_topic_results]
```

Suggested REST endpoints:

```text
GET /wp-json/ehrman/v1/categories
GET /wp-json/ehrman/v1/categories/{slug}/topics
GET /wp-json/ehrman/v1/topics/{slug}/posts
GET /wp-json/ehrman/v1/search?keywords=paul+resurrection
GET /wp-json/ehrman/v1/keywords?prefix=pa
```

## Validation Checklist

After import, the developer should confirm:

- 32 categories imported.
- 291 topic records imported.
- 290 topics are visible in the browser.
- 1 topic is hidden: `Ignore`.
- 348 category-topic links exist.
- 4,373 post records imported or matched.
- 4,373 unique URLs exist.
- Every post has at least one topic.
- Every post topic exists in the topic metadata.
- Every topic category exists in the category metadata.
- Duplicate titles are allowed.
- URLs or confirmed WordPress IDs are used as the unique post key.

## Display Rules

### Category Page

Show:

- Category name
- Category description
- Topic count
- Post count

### Topic Page

Show:

- Category name or navigation context
- Topic name
- Topic description
- Post count
- Matching posts

### Post Result Rows

Show:

- Linked post title
- `By {author} | {dateText} | {selected topic}`
- Description on hover or when the user enables "display all descriptions"

The selected topic should be the topic the user clicked or searched from, not necessarily the first topic in the post's `topics` array.

## Production Notes

- Do not load and parse the JSON files on every public request.
- Import the JSON into WordPress tables or cached options.
- Build normalized keyword lookup tables for fast autocomplete and search.
- Cache category, topic, and search-result counts.
- Preserve exact topic/category names for display, but create slugs for URLs.
- Keep a re-import process so future JSON updates can refresh the WordPress data.
- Treat the JSON files as source data, not as the live runtime database.

## Minimal Import Pseudocode

```php
$categories = json_decode(file_get_contents('ehrman_post_categories.json'), true)['categories'];
$topics = json_decode(file_get_contents('ehrman_post_topics.json'), true)['topics'];
$posts = json_decode(file_get_contents('ehrman_post_search_index.json'), true);

foreach ($categories as $category) {
    upsert_category($category['name'], $category['description']);
}

foreach ($topics as $topic) {
    $topic_id = upsert_topic(
        $topic['name'],
        $topic['description'],
        $topic['displayInBrowser']
    );

    foreach ($topic['categories'] as $category_name) {
        link_topic_to_category($topic_id, find_category_id($category_name));
    }
}

foreach ($posts as $post_record) {
    $wp_post_id = match_wordpress_post($post_record['wpId'], $post_record['url']);
    if (!$wp_post_id) {
        log_missing_post($post_record);
        continue;
    }

    update_post_description($wp_post_id, $post_record['description']);

    foreach ($post_record['topics'] as $topic_name) {
        link_post_to_topic($wp_post_id, find_topic_id($topic_name));
    }

    foreach ($post_record['secondaryKeywords'] as $keyword) {
        add_secondary_keyword($wp_post_id, $keyword, normalize_keyword($keyword));
    }
}
```

## Handoff Summary

The safest production approach is:

1. Import categories and topics into dedicated WordPress plugin tables.
2. Match search-index post records to existing WordPress posts by `wpId` or URL.
3. Store topic links and secondary keywords in indexed tables.
4. Expose categories, topics, autocomplete, and search through cached REST endpoints.
5. Render the front end through a WordPress plugin, shortcode, or block that matches the site's existing design.

