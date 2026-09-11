# Production-Equivalent WordPress Package

This directory packages the Ehrman Blog Discovery plugin, companion demo
theme, and five authoritative JSON sources into a reproducible WordPress
Docker image. It exists beside the development Compose stack and does not
change the current Python or PHP demos.

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
and theme, configures permalinks, and imports the authoritative index.

Ask AI uses the focused two-term interpretation strategy by default. Set
`EHRMAN_DISCOVERY_AI_TERM_STRATEGY=legacy` to restore the previous four-term
interpreter; this switch does not affect regular Keyword Search.

AI-refined results are grouped into direct answers, strongly related posts, and
supporting background. Set `EHRMAN_DISCOVERY_AI_RESULT_GROUPING=ordered` to
restore the previous flat relevance-ordered result list.

Ask AI 2 uses the selected single-vector `hybrid` retrieval strategy, combining
title-and-summary similarity with lexical and exact metadata signals. The
experimental topic, alias, and secondary-keyword vectors are disabled unless
`EHRMAN_DISCOVERY_SEMANTIC_RETRIEVAL=hybrid-metadata` is explicitly selected.
Use `semantic` to test title-and-summary similarity alone.

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

Ask AI 2 uses one title-and-summary vector per eligible post. After deploying a
plugin or data update, refresh the semantic index from the WordPress service
shell. The command does not build optional metadata vectors while `hybrid` is
active:

```bash
wp ehrman-discovery embeddings --allow-root --path=/var/www/html
```

On a Render database that previously contained experimental topic, alias, or
secondary-keyword vectors, rebuild the content index and remove those rows with:

```bash
wp ehrman-discovery embeddings --purge-metadata --allow-root --path=/var/www/html
```

The empty metadata table remains part of the plugin schema so the experiment can
still be run locally, but no metadata vectors are stored or loaded in the
selected Render configuration.

## Render Blueprint

`render-wordpress.yaml` is intentionally separate from the active root
`render.yaml`. It defines a new staging WordPress web service and private
MySQL service with persistent disks and generated credentials. Do not activate
it or replace the current PHP service until deployment is explicitly approved.
