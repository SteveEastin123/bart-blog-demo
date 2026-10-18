# Ask AI recovery and retirement

Decision: October 18, 2026. Keep Ask AI 2 as the active AI search. Retire the
topic/keyword Ask AI 1 interface and park the section-based Ask AI 3 experiment.
Keep both Browse Topics structures, Keyword Search, ingestion, historical
analytics and evaluation reports. Do not change Ask AI 2 retrieval or review.

## Recovery checkpoint

Git tag: `ask-ai-before-retirement-2026-10-18`.

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
   pilot to be active. Preserve the header's `ehrman_demo_extra_search_links`
   hook. Both installers require localhost and WordPress environment `local`.
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
and data are retained, and their private files remain excluded from Docker/Render.
The production landing page explains only the selected AI method. Historical
presentation slides and evaluation documents are not rewritten.

No Render deployment is part of this task. Unrelated migration, presentation and
canonical-keyword working changes are not included in these feature commits.
