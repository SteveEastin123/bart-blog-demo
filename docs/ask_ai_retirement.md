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
3. Ask AI 1 uses the existing canonical topics and keywords. Restore its public
   routes, page renderer/shortcode and navigation from the checkpoint together.
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

The Ask AI 1 REST controller, page renderer and question template are removed
from the active plugin. The shared `AI_Interpreter` compatibility API, reviewer,
term-selection helpers and historical analytics remain unchanged in this pass.
Removing more shared implementation is a separate refactor, not part of disabling
the retired search interfaces.

## Verification

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
