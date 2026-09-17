# Production-Equivalent WordPress Package

This directory packages the current Ehrman Blog Discovery WordPress/MySQL
target: the plugin, companion demo theme, five authoritative JSON sources, the
portable title-and-summary vector index, and production acceptance checks in a
reproducible Docker image. Older Python, PHP/SQLite, and WordPress development
demos remain reference implementations.

## Local Acceptance Stack

The isolated production-equivalent stack runs on port `8086` by default:

```powershell
.\wordpress-production\setup-production-test.ps1
```

The reviewer-only hierarchy outline is available at
`http://localhost:8086/structure-review/`. It is linked from the landing page,
excluded from the primary navigation, and marked `noindex, nofollow`.

The script builds the exact WordPress image intended for staging, starts a
fresh MySQL service, installs WordPress when necessary, activates the plugin
and theme, configures permalinks, imports the authoritative JSON, and restores
the validated semantic-vector package.

Ask AI uses the focused two-term interpretation strategy by default. Set
`EHRMAN_DISCOVERY_AI_TERM_STRATEGY=legacy` to restore the previous four-term
interpreter; this switch does not affect regular Keyword Search.

AI-refined results are grouped into direct answers, strongly related posts, and
supporting background. Set `EHRMAN_DISCOVERY_AI_RESULT_GROUPING=ordered` to
restore the previous flat relevance-ordered result list.

Ask AI 2 uses the selected title-and-summary vector pipeline, combining
semantic similarity with lexical and exact topic, alias, and secondary-keyword
ranking signals.

## Administrator Post Ingestion

The plugin includes an administrator-only **Tools > Post Ingestion** workflow
for the future MySQL-authoritative deployment. An administrator enters the
WordPress post ID, title, URL, author, publication date, and complete post text.
The separate ingestion OpenAI project analyzes the full text and returns a
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

- `EHRMAN_INGESTION_OPENAI_API_KEY`: dedicated ingestion-project key; required
  for analysis and the approved post's title-and-summary vector.
- `EHRMAN_INGESTION_OPENAI_MODEL`: defaults to `gpt-5.6-sol`.
- `EHRMAN_INGESTION_REASONING_EFFORT`: defaults to `high`.
- `EHRMAN_DISCOVERY_POST_SOURCE`: defaults to `json`, which permits draft
  analysis but locks approval. Set it to `mysql` only after the source-of-truth
  handoff.

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

Deleting the named volumes is destructive and is needed only when deliberately
repeating a clean-install test:

```powershell
docker compose -f .\wordpress-production\compose.local.yaml down --volumes
```

## Backup And Restore Test

Create a logical MySQL backup:

```powershell
.\wordpress-production\backup-database.ps1
```

Verify that a backup can be restored without touching the working database:

```powershell
.\wordpress-production\test-backup-restore.ps1 -BackupPath <backup.sql>
```

With no `BackupPath`, the restore test creates a temporary backup, restores it
into `ehrman_restore_test`, compares indexed-post counts, and removes both the
temporary database and backup.

## Production Characteristics

- The plugin, theme, and import sources are immutable image content.
- Import sources live outside Apache's document root at `/opt/ehrman-import`.
- A runtime synchronizer refreshes image-managed plugin and theme code and
  removes any legacy public import directory on every container start.
- MySQL stores WordPress and discovery-index data.
- Only `wp-content/uploads` requires a WordPress persistent disk.
- Result links open Bart's existing post URLs; full post bodies are not stored.
- The ingestion workflow retains full post text only in pending administrator
  drafts and removes it after approval.
- `/healthz` does not depend on WordPress installation state.
- The protected parity route is disabled unless a test token is explicitly set.
- WordPress file editing is disabled so deployed code continues to come from Git.

## Ask AI 2 Index

Ask AI 2 uses one title-and-summary vector per eligible post. Clean installs
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

`render-wordpress.yaml` defines the current WordPress web service and private
MySQL target with persistent disks and generated credentials. Render updates
are applied manually after the local production-equivalent checks pass. The
root `render.yaml` belongs to the legacy Python demonstration and is not the
WordPress handoff definition.
