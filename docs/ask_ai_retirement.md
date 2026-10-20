# Ask AI recovery and retirement

Decision: October 18, 2026. Keep Ask AI 2 as the active AI search. Retire the
topic/keyword Ask AI 1 interface and park the section-based Ask AI 3 experiment.
Keep both Browse Topics structures, Keyword Search, ingestion, historical
analytics and evaluation reports. Do not change Ask AI 2 retrieval or review.

## Recovery checkpoint

Git tag: `ask-ai-before-retirement-2026-10-18`.

Checkpoint commit: `97bdfd72d30f460cbc202d04b7f3f9af54a29b9c`. The commit and
annotated tag were pushed to `origin` and verified against GitHub.

The tag preserves the production Ask AI 1 implementation and the experimental
source, tests, manifests and reports under `experiments/`. Experiments are not
included in production Docker images. The historical August metadata-vector
experiment called Ask AI 3 is different from the October section-search version.

Private backup, deliberately NOT uploaded to GitHub:

`wordpress-production/backups/ask-ai-retirement-2026-10-18/recovery.zip`

- Size: 634,460,981 bytes; 7,144 files; all entry SHA-256 hashes verified.
- Archive SHA-256: `bd904ebf6baede1fe4eb3ebff15b1a2918e73cbfb713007f96cecc48e93de5ae`.
- Includes full experimental inputs, section vectors, host runtime packages,
  the current Ask AI 3 runtime/log copied from the local container, canonical
  index/vector data, and the pre-retirement working source/configuration.
- Excludes `.env` credentials, dependency caches, and existing backups.
- Contains a per-file `RECOVERY-MANIFEST.json`; `receipt.json` is alongside it.
- It is not a full WordPress/MariaDB dump. Existing database tables and their
  historical analytics are retained in place by this retirement.

This is a second local copy, not protection against losing this laptop. Store a
copy in an approved private off-device backup. Never publish full posts, private
test inputs, API receipts, credentials or vector archives in the public web root.

An additional `runtime.tar.gz` in the same backup directory preserves all three
installed experimental `/opt/` directories and five experimental plugin folders
before the WordPress container was refreshed. Size: 255,121,624 bytes. Its host
checksum matches the original container checksum:
`50b3e39ac7abf15dc6a9a2363311a9e17169c0d7b9d3ead21aa9d80d9f39b4f5`.

## Restore later

1. Inspect the tag in a separate checkout; do not reset the current branch or
   replace a newer database. Compare it with the current code before restoring
   the needed feature files and route/page registrations.
2. Verify the archive checksum, inspect `RECOVERY-MANIFEST.json`, and extract
   into a separate recovery directory first. Restore only required private data.
   The backup also preserves local MariaDB configuration that was not part of
   the feature checkpoint commit. Supply credentials separately.
3. Ask AI 1 uses the existing canonical topics and keywords. Restore its
   interpreter, term selector, settings, browser assets, public routes,
   page renderer/shortcode and navigation from the checkpoint together.
   Compare the caller changes made during the subsequent backend cleanup;
   restoring only the removed class files is not sufficient.
   Do not change Ask AI 2's shared reviewer or overwrite analytics history.
4. For Ask AI 3, use `experiments/section-search/install-local.ps1` to reinstall
   the base pilot, then `experiments/section-search/guest-access/install-local.ps1`
   to install the all-post guest version. The guest installer requires the base
   pilot to be active. Remove `ask-ai-3` from the retired-URL redirect list in
   `Page_Controller` so the page can be reached again. Preserve the header's
   `ehrman_demo_extra_search_links` hook. Both installers require localhost and
   WordPress environment `local`.
5. Use the retained private packages, not the embedding-generation commands.
   The selected version contains 19,951 approximately 300-word sections for
   3,986 eligible posts using `text-embedding-3-small`, 512 dimensions. Full
   private data is required; GitHub alone cannot reconstruct it without new work.
6. Check source/data fingerprints before running an old benchmark. Frozen reports
   must remain unchanged. Later canonical updates can invalidate an old manifest;
   record a new run instead of editing the historical one.
7. Run offline regression, PHPCS/PHPStan and browser checks before allowing paid
   requests. Live searches incur API charges. Reconfirm compatibility after any
   WordPress, PHP, schema, prompt or model changes.

## Active-demo scope

Retirement removes public entry points, not historical evidence. Ask AI 2 keeps
its name and `/ask-ai-2/` URL. Old Ask AI URLs should redirect to it without
carrying method-specific request IDs or interpreted search terms. Old shortcode
content should render the surviving interface rather than expose a dead form.

The section plugins and their enable options are disabled locally. Their files
and data are retained in the private host backups; the installed plugins are
inactive. Their private files remain excluded from Docker/Render.
The production landing page explains only the selected AI method. Historical
presentation slides and evaluation documents are not rewritten.

The October 18 pass removed the Ask AI 1 REST controller, page renderer and
question template. The October 19 cleanup removes the remaining interpreter,
term selector, interpretation cache writer, obsolete strategy configuration and
browser handlers. `AI_Settings` retains the existing API-key and model resolution;
Ask AI 2 calls `AI_Candidate_Reviewer` directly. The review prompt, cache key,
candidate selection, semantic search and embedding implementations are unchanged.

Historical analytics tables and data remain in place. The separate-method
reporting views are replaced by the unified dashboard described below. The unused
interpretation-feedback writer and reader are removed; current request feedback
is unchanged. Both Browse Topics paths, Keyword Search and ingestion remain active.

All `experiments/` content is excluded from the Docker build context, not just
individual private experiment directories. The installable plugin and its handoff
materials must exclude experimental plugins, section vectors, private full-text
inputs and experiment runtime bundles. The archived experiment source and local
backups remain available for recovery. This cleanup does not rename the remaining
Ask AI search interface or delete historical comparison data.

## Unified analytics dashboard

The October 19 analytics cleanup replaces method tabs and comparison reports with
one **Ask AI Analytics** dashboard at
`/wp-admin/tools.php?page=ehrman-ai-analytics`. Existing method-specific links open
the same dashboard; their old `view` and `interface` parameters are ignored.

- `AI_Analytics_Scope` selects semantic requests while excluding the retired
  `hybrid-metadata-1` two-vector experiment. Ask AI 1 and experimental requests
  remain stored but do not contribute to dashboard totals or CSV exports.
- Each question's total cost and tokens include its embedding request and every
  linked AI review, including retries. The same aggregation feeds the UI and
  question CSV. Index-preparation costs remain separate.
- Feedback, question search, date filters, zero-result filtering, pagination,
  token/cache details and review CSV exports remain available. Failed requests
  without recorded results are not counted as zero-result searches.
- Period totals attribute all linked review costs to the question's submission
  date. Filtered averages include cached and failed submissions.
- Reset deletes only active-method requests, their reviews and linked usage in
  one transaction. It preserves retired-method history, unrelated usage,
  index-preparation costs and vectors. It does not apply the visible filters.
- Reading reports no longer triggers retention cleanup. The existing on-write
  retention policy is unchanged; these records are not a permanent archive.

Regression coverage is in `analytics-dashboard-tests.php`, included by the
standard WordPress acceptance suite. Its fixtures and scoped-reset checks run in
a transaction that is rolled back. `analytics-dashboard-ui.cjs` verifies the
authenticated dashboard, old links, filters, CSV export, invalid nonces and
desktop/mobile layouts. It uses local container credentials in memory only,
does not issue paid searches, and never submits a valid reset request.

Verified locally on October 19, 2026:

- Full WordPress acceptance/regression suite passed, including 74 PHP syntax
  checks and analytics fixtures covering cached/failed searches, review retries,
  filters, pagination, CSV totals/formula protection, more than 5,000 requests
  and reviews, and scoped-reset preservation.
- PHPCS passed for all 69 checked files; PHPStan reported no errors.
- Authenticated analytics browser checks passed at 1440, 1024 and 390 pixels,
  including four legacy view URLs, filtered CSV exports and nonce protection.
- Public-search browser checks passed at the same widths with seven mocked AI
  requests, three mocked feedback submissions and three shared search pages.
- All 19 discovery tables retained identical schemas, indexes, row counts and
  content hashes. The 531 historical requests remain stored; the active dashboard
  includes 261 and excludes 220 taxonomy and 50 two-vector experiment requests.
- Search, embedding and candidate-review implementations were unchanged. No
  paid API calls, data import, committed changes, push or Render deployment.

## Analytics code cleanup

Local checkpoint `385b4a2` preserves the completed retirement and unified
dashboard before the follow-up code cleanup.

- Remove the unused `AI_Feedback` reader, `AI_Usage::report()` and its empty-report
  helper, and `AI_Refinements::count()`. Keep current request feedback, usage
  recording and the separate semantic-index preparation report.
- Remove unused interpretation/review/API-call averages and refinement-rate
  calculations. Name the retained stage count `uncached_stages`, matching the
  dashboard label; this is not a count of provider HTTP calls.
- Simplify `AI_Requests::record()` to semantic submissions only. Its required
  model and pipeline-version inputs are supplied by both controller paths.
  New records explicitly use `semantic` and an empty legacy `selected_terms`
  value. Old records, columns, table definitions and retention rules are unchanged.
- Update comments to distinguish semantic retrieval from candidate review.
  Prompts, model configuration, caches, embeddings, retrieval and ranking are
  unchanged; only the analytics call arguments change in the REST controller.

The request-writing assertions in `ask-ai-cleanup-tests.php` cover successful
retrieval, cached query vectors, candidate-review fallbacks and failed retrieval.
The full analytics suite also verifies the reduced summary shape, costs, tokens,
exports, scope and preservation of historical records.

Verified locally on October 20, 2026: the full WordPress acceptance/regression
suite passed (73 PHP syntax checks), PHPCS passed (68 files), and PHPStan reported
no errors. Analytics and public-search browser suites passed at 1440, 1024 and
390 pixels, with mocked AI requests only. All 19 discovery tables retained
identical schemas, indexes, row counts and content hashes. No data import,
schema migration, paid API request, push or Render deployment was performed.

## October 18 verification

- 130 offline Python experiment tests passed before the checkpoint; archived
  JSON and Python syntax passed, with no credential/private paths staged.
- Local production image rebuilt; no database import or paid AI calls.
- WordPress acceptance/regression suite passed, including 74 PHP syntax checks,
  ingestion, semantic coverage, analytics, keyword/browse search and pagination.
- PHPCS and PHPStan passed for the final active-plugin changes.
- Retirement regressions cover missing `/interpret` and `/refine` routes, the
  legacy shortcode alias, redirects, question punctuation and malformed input.
- Headless browser checks passed at 1440, 1024 and 390 pixels: navigation, old
  links, retired endpoints, image rendering/text bounds, semantic results and
  error states. All six semantic responses were mocked. Screenshots are under
  `.tmp/ask-ai-retirement-ui/`.
  Run `node wordpress-production/tests/ask-ai-retirement-ui.cjs` with the existing
  `playwright-core` dependency and Microsoft Edge installed; the local demo must
  be running on port 8086.
- All 19 discovery tables retain identical row counts and content hashes,
  including 4,446 posts, 4,016 title/summary vectors, 531 requests, 521 refinements
  and 1,189 usage records. WordPress options and one revision of the Ask AI 3
  page changed as expected. Regression fixtures can advance auto-increment values.
- `AI_Interpreter`, `AI_Candidate_Reviewer`, `Embedding_Service`,
  `Semantic_Search_Service` and `Semantic_Ask_AI_REST_Controller` are unchanged.

No Render deployment is part of this task. Unrelated migration, presentation and
canonical-keyword working changes are not included in these feature commits.

## October 19 verification procedure

Completed locally on October 19, 2026:

- WordPress acceptance/regression checks passed, including 73 PHP syntax checks.
- PHPCS passed for all 68 checked files; PHPStan reported no errors.
- Browser checks passed at 1440, 1024 and 390 pixels, including all retained
  search controls, seven mocked semantic requests, three mocked feedback
  submissions, and read-only searches on Keyword Search and both Browse paths.
- All 19 discovery tables retained identical schemas, indexes, row counts and
  content hashes: 4,446 posts, 4,016 vectors, 531 requests, 521 refinements and
  1,189 usage records. Only WordPress options/cache state differed.
- Inspected the packaged `/opt/ehrman-code` and `/opt/ehrman-import` files;
  no experimental plugins, section vectors or full-text experiment inputs were
  included. Archived source and private backups were not deleted or uploaded.
- No paid API calls, data import, commit, push or Render deployment were made.

To repeat the checks:

- Build only the local WordPress image; do not import data or regenerate vectors.
- Run `verify-production-test.ps1`, PHPCS and PHPStan. The included
  `ask-ai-cleanup-tests.php` intercepts provider HTTP calls and checks real
  semantic-candidate wiring, review ordering, cache reuse and broader-result
  fallbacks for empty, failed and malformed review responses. Test records and
  transient caches are removed afterward.
- Run `tests/ask-ai-retirement-ui.cjs` for desktop/mobile layouts, semantic search,
  sorting, broader/refined toggles, pagination, feedback, error recovery, clearing
  and saved-query autorun. It also exercises shared controls on Keyword Search
  and both Browse Topics paths. All semantic and feedback responses are mocked.
- Compare discovery-table row counts and content hashes before/after the checks.
  Regression fixtures may advance auto-increment values without changing data.
