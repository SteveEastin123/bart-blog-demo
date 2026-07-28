# Python-to-PHP Search Parity Test Plan

## Purpose

This plan defines how to preserve the current Python/Render behavior as the
reference contract for a future PHP implementation. The PHP implementation
must be deployed separately and compared with Python before any cutover.

This phase documents behavior only. It does not add test endpoints, alter the
search implementation, or change either Render deployment.

## Reference System

The reference system is the Python WSGI application in `webapp/app.py`, built
from these authoritative inputs:

- `data/index/ehrman_post_search_index.json`
- `data/index/ehrman_post_topics.json`
- `data/index/ehrman_post_categories.json`
- `data/index/ehrman_post_subject_areas.json`
- `data/index/ehrman_post_subject_areas_2.json`

Before capturing a baseline, record:

- Git commit deployed by Render
- SHA-256 fingerprint of every authoritative input
- SQLite schema version and database-build version
- Counts for posts, topics, secondary keywords, categories, and both subject-area sets
- Python and SQLite versions

The baseline is valid only when both implementations report the same data
fingerprint.

## Current Search Contract

### Normalization

1. Trim surrounding whitespace.
2. Convert text to lowercase.
3. Replace `&` with `and`.
4. Replace other non-ASCII letters, punctuation, and separators with spaces.
5. Collapse repeated spaces.
6. Remove empty and duplicate selected terms after normalization.
7. Accept no more than four selected terms through the interface.

### Search matching

- A selected term can match an exact normalized topic or secondary keyword.
- A selected word or phrase can also match a complete word sequence inside a
  broader indexed term. For example, `hell` can match `heaven and hell`, but it
  must not match `nutshell`.
- Multiple selected terms use AND semantics. A post must match every selected
  term.
- Topics have base weight 5.
- Secondary keywords have base weight 3.
- An exact normalized match adds 2.
- A matching term in the post title adds a title boost.
- The same normalized term is selected only once.

### Sorting

- `ranked` sorts by accumulated relevance descending, date descending, then
  post URL descending.
- `newest` sorts by date descending, then post URL descending.
- `oldest` sorts by date ascending, then post URL ascending.
- An unrecognized sort value defaults to `ranked`.

Post URL is the approved portable tie-breaker because it remains stable across
the Python, PHP, and WordPress databases.

### Scoped searching

- A topic page begins with posts assigned to that topic.
- Additional selected terms narrow that topic's posts.
- A category page begins with the union of posts assigned to topics in that
  category.
- Additional selected terms narrow that category's posts.
- Removing a selected term regenerates the result set.
- Changing the selected sort option immediately reorders existing results.

### Autocomplete

- Blank, unscoped search starts with the configured starter-topic list.
- Typed text matches the beginning of an indexed term or the beginning of any
  word in that term.
- `Ignore` is excluded.
- Already selected normalized terms are excluded.
- Later suggestions are restricted to posts matching all terms already
  selected.
- Topic-page suggestions are restricted to posts assigned to that topic.
- Category-page suggestions are restricted to that category's posts, and topic
  suggestions are limited to topics assigned to that category.
- Suggestions are ordered by match quality, topic status, matching-post count,
  and label.
- Each suggestion reports its label, normalized value, matching-post count,
  and whether it is a topic.
- At most 48 suggestions are returned.

### Browse behavior

The contract includes both Browse Topics structures:

- ordered subject areas and their descriptions
- ordered categories within each subject area
- ordered topics within each category
- category and topic post counts
- ordered post results for category and topic pages
- breadcrumbs and source-path context

## Current Test-Corpus Size

The current SQLite build contains:

- 4,385 total posts
- 3,948 posts with at least one searchable term other than `Ignore`
- 1,708 normalized searchable terms other than `Ignore`
- 33,534 distinct term pairs that co-occur in at least one post
- 194,748 distinct co-occurring triples
- 602,916 distinct co-occurring four-term sets
- 6,901 distinct autocomplete word-prefix inputs
- an average of 7.45 searchable terms per searchable post
- a maximum of 24 searchable terms on one post

These counts must be regenerated when the source data changes.

## Proposed Machine-Readable Contract

Phase 2 should add a read-only parity endpoint to Python. The endpoint must be
disabled unless a Render environment variable provides a test token. Requests
must supply that token in a header. The PHP service will implement the same
contract.

Suggested endpoint:

```text
POST /api/parity/batch
X-Ehrman-Parity-Token: <secret>
```

Suggested request:

```json
{
  "schemaVersion": 1,
  "cases": [
    {
      "id": "search-luke-atonement-ranked",
      "operation": "search",
      "terms": ["Luke", "Atonement"],
      "sort": "ranked"
    },
    {
      "id": "suggest-luke-selected",
      "operation": "suggest",
      "query": "at",
      "selected": ["Luke"]
    }
  ]
}
```

Suggested response envelope:

```json
{
  "schemaVersion": 1,
  "implementation": "python",
  "commit": "<git commit>",
  "dataFingerprint": "<sha256>",
  "results": []
}
```

Search results should use each post URL as the unique portable key and include:

- ordered URLs
- result count
- WordPress IDs when available
- titles and ISO dates for diagnostics

Suggestion results should include, in exact order:

- label
- normalized value
- post count
- topic/keyword designation

Browse results should include ordered slugs, names, descriptions, counts, and
relationships.

The endpoint should accept bounded batches, return no private post content,
and be unavailable when the test token is not configured.

## Test Matrix

### Tier 1: Data and build invariants

Run locally and on both Render services:

- authoritative-file fingerprints
- database counts
- uniqueness and foreign-key checks
- URL, WordPress-ID, title, author, date, and description presence
- orphaned category/topic/subject-area relationships
- duplicate normalized labels
- database rebuild reproducibility

### Tier 2: Exhaustive deterministic search

- Search all 1,708 normalized terms using `ranked`.
- Search all 1,708 normalized terms using `newest` and `oldest` to verify sort
  behavior.
- Search all 33,534 co-occurring pairs using `ranked`.
- Test the same selected terms in reversed order for a deterministic sample of
  pairs.
- Test duplicate selections, empty terms, whitespace, punctuation, ampersands,
  case changes, and invalid sort values.
- Verify that non-co-occurring pairs return zero for a deterministic negative
  sample.

### Tier 3: Multi-term interaction coverage

The implementation uses the same iterative intersection for two, three, and
four terms. Testing every co-occurring triple and quadruple would add 797,664
cases without exercising a different branch of the algorithm. Instead:

- include every known regression query
- include all triples and quadruples attached to posts with the largest term
  lists, bounded to a documented deterministic set
- include at least 5,000 co-occurring triples
- include at least 2,000 co-occurring quadruples
- include matching and non-matching cases
- include reordered terms and one duplicated term

The sampling seed and generation algorithm must be stored so the same cases
are used for Python and PHP.

### Tier 4: Exhaustive autocomplete

- Test all 6,901 distinct global word-prefix inputs.
- Test blank unscoped starter suggestions.
- Test an empty query after every selected normalized term.
- Test selected-term narrowing for all co-occurring pairs.
- Test exclusion of already selected terms.
- Test suggestion limits and ordering.
- Test every category scope and every topic scope.
- Test category/topic scopes combined with selected terms.

### Tier 5: Browse and metadata

- Test every subject area in Browse Topics 1 and Browse Topics 2.
- Test every category and topic.
- Verify names, descriptions, order, links, and counts.
- Verify the union of topic posts shown by each category.
- Verify all post metadata and original-blog URLs.
- Verify category and topic filtering with all three sort modes.

### Tier 6: Browser behavior

Use a smaller end-to-end browser suite for behavior that JSON cannot prove:

- selecting and removing up to four terms
- duplicate-term prevention
- enabling and disabling Search
- autocomplete placement, keyboard behavior, badges, and counts
- immediate sort changes
- category- and topic-scoped filtering
- description visibility and hover behavior
- desktop and mobile layouts
- breadcrumbs for both Browse Topics structures
- functional links to original blog posts

## Baseline Artifacts

Store these artifacts outside the application data files:

- `cases.jsonl`: deterministic input cases
- `python-render-baseline.jsonl.gz`: complete normalized Python responses
- `python-render-digests.jsonl`: count and SHA-256 digest for every ordered result
- `baseline-manifest.json`: commit, data fingerprints, runtime versions, counts,
  generation seed, and generation date
- `comparison-report.html`: human-readable PHP-versus-Python differences

The digest file can be committed if reasonably sized. The larger compressed
baseline should be retained as a release artifact or other versioned test
artifact rather than inflating the normal Git history.

## Comparison Rules

- Compare semantic JSON values, not raw JSON formatting.
- Use URLs as portable post identities.
- Require exact result counts and result sets.
- Require exact ranked order after the tie-break decision is approved.
- Require exact suggestion labels, types, counts, and order.
- Require exact subject-area, category, and topic relationships and order.
- Report metadata differences separately from matching and ranking differences.
- Treat every unexplained difference as a failure.

## Performance Measurements

Correctness and performance should be reported separately. For both Render
services, record warm-response median and 95th-percentile times for:

- single-term search
- two-, three-, and four-term search
- global autocomplete
- narrowed autocomplete
- category and topic pages

Cold-start time should be reported separately because Render runtime startup
can obscure search-engine performance.

## Acceptance Criteria

PHP is eligible for WordPress integration only when:

1. Python local and Python Render agree on all contract cases.
2. Python and PHP report identical source-data fingerprints.
3. PHP matches every Python result set and count.
4. PHP matches the approved ranked order.
5. PHP matches autocomplete output and ordering.
6. PHP matches browse relationships, order, counts, and post metadata.
7. All browser regression tests pass on desktop and mobile.
8. Every remaining difference is documented and explicitly approved.

The Python Render service remains available until the PHP comparison is
accepted and a later cutover is explicitly approved.

## Approved Phase 2 Decisions

The following decisions were approved before implementing the Python test
harness:

- the current behavior contract
- exhaustive singles and co-occurring pairs plus deterministic triple/quadruple coverage
- URL as the portable post identity and final sorting tie-breaker
- the protected batch endpoint design
- storage of baseline digests in Git and the larger compressed baseline outside normal Git history
