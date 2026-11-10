# Production-Equivalent WordPress Package

This directory packages the current Ehrman Blog Discovery WordPress/database
target: the plugin, companion demo theme, five authoritative JSON sources, the
portable title-and-summary vector index, and production acceptance checks in a
reproducible Docker image. Older Python, PHP/SQLite, and WordPress development
demos remain reference implementations.

Local builds and GitHub Actions use **MariaDB 13.0.2**, pinned by image digest in
`compose.local.yaml`. The Oracle MySQL compatibility job and its Compose file
have been retired; previous configurations remain in Git history. Existing
database volumes and backups are not deleted by that change. WordPress and PHP
versions are unchanged. The Render blueprint now targets a fresh MariaDB service;
no remote deployment or resource deletion is performed by these local scripts. See
[`docs/wordpress_mariadb_migration.md`](../docs/wordpress_mariadb_migration.md)
for the migration evidence and rollback procedure.

## Local Acceptance Stack

### Full Local Regression

Run from the repository root with PowerShell 7.2 or newer:

```powershell
.\wordpress-production\verify-all.ps1 -PythonExecutable 'C:\path\to\python.exe'
```

With Python on PATH, `verify-all.ps1` alone is sufficient. Optional
`-NodeExecutable` and `-PhpExecutable` accept full executable paths. PHP defaults
to the existing `.tools/php-8.4/php.exe` when present, otherwise `php` on PATH.
Prerequisites: Docker Desktop running, Python 3.10+, Node with `npm ci` completed,
PHP with Composer dependencies installed in `wordpress-plugin/ehrman-blog-discovery`,
Git, and Microsoft Edge for the existing Playwright browser suites. The runner
does not install missing dependencies or silently skip unavailable checks.

Provide an approved deterministic search capture with `-BaselinePath`.
The default is `.tmp/test-tools-approved-baseline.json`, used by this workstation.
A new checkout needs its approved reference supplied separately; the runner
never generates or overwrites an approved baseline. Data changes may require
review and explicit approval of a replacement reference.

The command runs data/diagram checks, frontend and developer-package unit tests,
PHPCS/PHPStan for all three packages, the production PHP/REST suite, the extracted
developer-test ZIP against the approved baseline, fresh installation/rollback,
five browser suites, production-only browser checks without Reviewer Tools,
served landing-page dates/counts, discovery-table fingerprints and backup/restore.
The new runner, browser configuration and count checker also have focused tests.

Testing uses uniquely named **isolated MariaDB stacks on temporary loopback ports**.
It builds current source and imports only into those test databases; the normal
demo is neither stopped nor reimported. A nonfunctional test key enables the
Ask AI form for browser tests; real keys are never used. The same fake key is
used for ingestion; WordPress outbound HTTP and scheduled jobs are disabled, and browser AI
responses are mocked/blocked.
Discovery-table content and schema must match before/after; fixture-induced
auto-increment changes and WordPress core lifecycle changes are not treated as
discovery-data loss. Existing safety guards remain in each underlying script.

Every stage writes a private log plus a consolidated `report.json` and readable
`report.txt` under `.tmp/full-regression/<run-id>/`. The report identifies the
commit, uncommitted file list, baseline checksum, tested URL and stage durations.
The first failure stops dependent work, marks remaining stages skipped and exits
nonzero. Cleanup is attempted even on failure; cleanup failures also fail the run.
Services are stopped and isolated volumes retained for investigation. Reports,
screenshots and backups must not be published or committed.

Landing checks are read-only and reject stale desktop/mobile count labels or
an incorrect served post date range/total. Regenerate stale count-bearing SVGs
with `scripts/build_search_methods_diagram.py` using the theme's desktop/mobile
output paths, review the diff, and rerun. The runner never repairs assets merely
to make a test pass. Standalone checker:

```powershell
python -B scripts/check_wordpress_landing.py --base-url http://localhost:8086
```

This local command does not deploy Render, run live OpenAI calls, grade relevance,
or test Oracle MySQL. CI database checks also use MariaDB only; browser tests
still run locally. The narrower scripts below remain available.

### Existing Demo Checks

The test-only comparison service is delivered separately in `developer-tests/`.
It is excluded from this image and the production plugin. Use
`verify-developer-tests.ps1` to package, extract and exercise that delivery against
the local demo; the script copies it only into a private temporary directory.
See [developer test instructions](../developer-tests/README.md). The production
comparison route stays absent even if an old parity token is configured.

The isolated production-equivalent stack runs on port `8086` by default:

```powershell
.\wordpress-production\setup-production-test.ps1
```

The reviewer-only hierarchy outline is available at
`http://localhost:8086/structure-review/`. It is supplied by the optional
`wordpress-demo-plugins/ehrman-reviewer-tools` companion, not the production
handoff plugin. Existing demos need `wp plugin activate ehrman-reviewer-tools`
once after updating the image; new setups activate it during initialization.
It is linked from the primary navigation while the add-on is active and marked
`noindex, nofollow`.

The script builds the exact WordPress image intended for staging, starts a
MariaDB service (preserving existing volumes), installs WordPress when necessary, activates the plugin
and theme, configures permalinks, imports the authoritative JSON, and restores
the validated semantic-vector package.

Ask AI 1's topic-and-keyword interpreter is retired. The former
`EHRMAN_DISCOVERY_AI_TERM_STRATEGY` setting is no longer used. Regular Keyword
Search and Browse Topics still use the complete curated vocabulary.

AI-refined results are grouped into direct answers, strongly related posts, and
supporting background. Set `EHRMAN_DISCOVERY_AI_RESULT_GROUPING=ordered` to
restore the previous flat relevance-ordered result list.

Ask AI uses the selected title-and-summary vector pipeline, combining
semantic similarity with lexical and exact topic, alias, and secondary-keyword
ranking signals.

## Administrator Post Ingestion

The normal local copy stays `EHRMAN_DISCOVERY_POST_SOURCE=json` for downloaded
post preparation. The Render configuration is prepared for `mysql`
(database-managed, including MariaDB), but this source change is not deployment
authorization. Do not deploy until explicitly approved. Render publishing tests
may retain test posts indefinitely; the handoff uses local JSON and validated
vectors, not Render's database. In database-managed mode, initialization seeds
an entirely empty discovery database once and preserves existing editorial data
on subsequent runs. Routine deploys do not reload JSON.

Editor-created records track their native WordPress ownership. Normal WordPress
Trash/unpublish hides them from search without discarding metadata or vectors;
republishing restores them. Permanent deletion, only when chosen in WordPress,
removes the owned records. There is no automatic test-post deletion feature.

Autocomplete deliberately reads the stored search index without checking native
WordPress publication status on each keystroke. Suggestions and their counts can
therefore include metadata retained for trashed, draft, or private posts. Actual
Keyword Search, Browse Topics, and Ask AI results still enforce publication
visibility. Permanent-deletion cleanup removes the owned autocomplete metadata.

The plugin includes an administrator-only **Tools > Post Ingestion** workflow
for the future database-authoritative deployment. An administrator enters the
WordPress post ID, title, URL, author, publication date, and complete post text.
The configured ingestion model analyzes the full text using the shared OpenAI key and returns a
reviewable description, search summary, existing topics, existing secondary
keywords, explicitly marked new-keyword proposals, a readiness decision, and
review notes. Analysis only creates a draft; no live search data changes before
explicit approval.

Descriptions and search summaries follow the existing index house style: each
begins with an active, present-tense verb and a declarative statement rather
than an author name, `This post`, or a question. Descriptions are normally
18-23 words. Search summaries are normally three sentences and 60-80 words;
unusual lengths or sentence counts are retained with visible review warnings.
Topic and keyword selection favors precision without imposing fixed limits.
The API selects the complete, nonredundant topic set: every primary, sustained
subject and substantial independent section must be represented, while a text,
person, or concept used only as evidence does not become a topic. Before
selecting keywords, the model performs an internal topic-coverage check.
Every proposed topic must also pass three tests: it must cover sustained
material, be necessary to describe that material's principal subject, and meet
a reader's reasonable expectation when browsing that topic. The review draft
shows one short AI rationale for every proposed topic; these explanations are
retained as review evidence but are not added to the canonical post record.
Secondary keywords may capture meaningful supporting sources only when each is
discussed beyond a passing reference, materially contributes to the post, and
would satisfy a reader who searched for that label. Topic aliases are supplied
during analysis, and alias-overlap warnings are shown during review. The
validator also warns when a keyword matches the name or alias of an unassigned
topic, since that may reveal a missing topic without proving that the topic
should be assigned. Strong warnings appear first when that label is also present
in the title, description, or opening summary sentence; supporting-only matches
receive advisory warnings. A final internal audit checks topic coverage and
redundancy, keyword search value, and whether every new keyword is unavoidable
before the structured proposal is returned.
The Responses API allows up to 16,000 generated tokens for the initial analysis,
including reasoning tokens. If OpenAI reports that this ceiling was reached,
the service retries once with a 32,000-token ceiling and records the combined
usage and estimated cost of both attempts.

Configure the workflow with these environment variables:

- `OPENAI_API_KEY`: one server-side key for Ask AI, post-ingestion analysis,
  and embeddings. An optional PHP constant of the same name takes precedence;
  an explicitly blank constant disables all AI calls. Old per-feature keys are
  no longer read. Move their value into `OPENAI_API_KEY` before updating if the
  shared variable is not already configured. Check pending ingestion jobs first.
- `EHRMAN_DISCOVERY_AI_MODEL`: search model; defaults to `gpt-5.4-mini`.
- `EHRMAN_INGESTION_OPENAI_MODEL`: defaults to `gpt-5.6-sol`.
- `EHRMAN_INGESTION_REASONING_EFFORT`: defaults to `high`.
- `EHRMAN_DISCOVERY_POST_SOURCE`: the plugin defaults to `mysql` when unset or
  blank, enabling database-managed approval. Local Compose explicitly defaults
  to `json` to protect the handoff-preparation data; that mode permits analysis
  and review but locks approval. The legacy value `mysql` also covers MariaDB;
  it does not select a database product. Production needs no setting change after
  initial installation. Administrators still review and approve each proposal.

Sharing the key does not combine the plugin's usage records: reader calls remain
in search analytics, while metadata analysis and post-vector metrics remain on
the ingestion draft. Model choices remain independent.

Pending drafts retain the supplied full text so they can be revised or
reanalyzed. Approval writes the normalized post and search relationships in a
transaction, retains the proposal and AI audit metadata, deletes the full post
text, and then generates the title-and-summary vector. A vector failure does
not roll back the approved post; it leaves a visible pending state with a retry
action. While JSON remains authoritative, continue using the existing download
skill and rebuild/import workflow for live data changes.

Published WordPress posts can be analyzed from the block editor's **Search
Metadata** panel. Analysis runs through a one-time WordPress cron event and the
panel polls for status, so a slow API response does not block publishing or
editing. Atomic claims, a ten-minute stalled-job threshold, and attempt fencing
make retries recoverable without allowing an older response to replace newer
work. See `docs/wordpress_post_ingestion_handoff.md` for the complete operator
and developer guide.

Validate the running stack with the bundled Python runtime path when `python`
is not on `PATH`:

```powershell
.\wordpress-production\verify-production-test.ps1 `
  -PythonExecutable 'C:\path\to\python.exe'
```

Stop the stack without deleting its data:

```powershell
docker compose -f .\wordpress-production\compose.local.yaml down
```

Never delete the working database or uploads volumes to test a clean install.
Use a separate Compose project name and unused port instead. CI deletes only
its own ephemeral runner volumes.

## Backup And Restore Test

Create a logical database backup (MySQL and MariaDB are supported):

```powershell
.\wordpress-production\backup-database.ps1
```

Verify that a backup can be restored without touching the working database:

```powershell
.\wordpress-production\test-backup-restore.ps1 -BackupPath <backup.sql>
```

Stop web/cron writes before the restore test to keep its comparison stable.
With no `BackupPath`, the test creates and retains a new backup. It restores
into a uniquely named scratch database, compares every table's row count and
extended content checksum, and removes only that scratch database. Existing
backups are never overwritten. Backups contain private WordPress data and
must not be committed or included in a public handoff.

The Oracle MySQL test stack is no longer maintained. Historical migration and
rollback instructions are retained in `docs/wordpress_mariadb_migration.md`;
they require the old Compose configuration from Git history. Do not run setup
during a historical rollback: it force-imports canonical JSON.

## Production Characteristics

- The plugin, theme, and import sources are immutable image content.
- Import sources live outside Apache's document root at `/opt/ehrman-import`.
- A runtime synchronizer refreshes image-managed plugin and theme code and
  removes any legacy public import directory on every container start.
- MariaDB stores WordPress and discovery-index data in the local and target Render configurations.
- Only `wp-content/uploads` requires a WordPress persistent disk.
- Result links open Bart's existing post URLs; full post bodies are not stored.
- The ingestion workflow retains full post text only in pending administrator
  drafts and removes it after approval.
- `/healthz` does not depend on WordPress installation state.
- The production plugin does not register the test-only parity route.
- Set `EHRMAN_MIGRATION_ISOLATED=1` only for a migration/test copy to disable
  automatic WP-Cron and external WordPress HTTP requests. Leave it unset or
  `0` on the active demo. It is not a substitute for network-level isolation.
- WordPress file editing is disabled so deployed code continues to come from Git.

## Ask AI Index

Ask AI uses one title-and-summary vector per eligible post. Clean installs
restore `/opt/ehrman-import/ehrman_post_embeddings.jsonl.gz` automatically.
The package can be exported and imported explicitly without an OpenAI API call:

```bash
wp ehrman-discovery embeddings export --file=/tmp/ehrman_post_embeddings.jsonl.gz --allow-root --path=/var/www/html
wp ehrman-discovery embeddings import --file=/opt/ehrman-import/ehrman_post_embeddings.jsonl.gz --allow-root --path=/var/www/html
```

Import validates the format version, model, dimensions, post IDs,
title-and-summary hashes, binary vector lengths, and norms before making one
transactional update. Repeating an import skips unchanged rows.

After approving new posts or changing summaries, build only missing or stale
title-and-summary vectors from the WordPress service shell:

```bash
wp ehrman-discovery embeddings --allow-root --path=/var/www/html
```

## Render Blueprint

`render-wordpress.yaml` retains the WordPress web service and defines a new private
MariaDB target with a separate 5 GB disk. Verify its web service name matches the
actual live service before syncing, so the current URL is preserved. Disable
Blueprint Auto Sync separately from service auto-deploy. Run initialization
manually when switching an existing web service to the fresh database; its
initial-deployment hook does not run again. The old MySQL resource must be explicitly
deleted only after verification and approval. See
[`the Render rebuild runbook`](../docs/wordpress_render_deployment_runbook.md).

After the full regression has built the current image, rehearse a fresh install
and container recreation using Render's database/web port 10000 locally:

```powershell
.\wordpress-production\verify-render-rebuild.ps1 -PythonExecutable '<python>'
```

This uses a unique isolated stack, blocked external API calls, disposable credentials,
and private evidence under `.tmp/render-rebuild/`. Containers are stopped afterward
and volumes retained. The normal demo and Render are untouched. The root
`render.yaml` belongs to the legacy Python demonstration, not this deployment.
