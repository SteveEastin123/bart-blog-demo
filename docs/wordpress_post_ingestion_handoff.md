# WordPress Post Ingestion Handoff

This guide describes the administrator workflow that prepares newly published
WordPress posts for the Ehrman Blog Discovery index. It is intended for the
development team that will install, operate, and maintain the production
plugin.

## Purpose And Boundaries

The normal WordPress post remains the source for its ID, title, URL, author,
publication date, and complete text. The ingestion workflow generates and
stores only discovery metadata: a description, search summary, topics,
secondary keywords, review evidence, and a title-and-summary vector.

Analysis never changes live discovery data. An administrator must review and
approve a completed proposal first. Database-managed approval is enabled by default;
no source-mode setting is needed for production. Explicit JSON-managed mode locks
approval. The local preparation copy remains JSON-managed;
Render testing and the eventual production installation use database-managed mode.

## Configuration

Supply secrets as server-side environment variables. Never store them in
WordPress options, source control, JSON, browser code, logs, or exports.

- `OPENAI_API_KEY`: shared with Ask AI and embeddings. No separate ingestion key
  is needed. An optional server-side PHP constant with the same name overrides
  the environment; a blank constant disables AI calls.
- `EHRMAN_INGESTION_OPENAI_MODEL`: optional; defaults to `gpt-5.6-sol`.
- `EHRMAN_INGESTION_REASONING_EFFORT`: optional; defaults to `high`.
- `EHRMAN_DISCOVERY_POST_SOURCE`: optional. Unset or blank defaults to `mysql`,
  enabling database-managed approval on MariaDB too. Set `json` explicitly to
  disable approval in the local handoff-preparation copy. An optional PHP constant
  of the same name overrides the environment. Unrecognized values keep approval
  disabled. This controls data ownership, not the database product or automatic approval.

Search and ingestion still use separate model settings and usage records.
Metadata analysis and post-vector metrics stay on the ingestion draft, not in
reader-search analytics. The old `EHRMAN_INGESTION_OPENAI_API_KEY` is no longer
read; configure `OPENAI_API_KEY` before upgrading a server that only has the old
key. Check for queued ingestion jobs before enabling the shared key.

## Editor Workflow

1. Save and publish the WordPress post normally.
2. Open the **Search Metadata** panel in the block editor.
3. Select **Create Metadata**. The REST request validates the saved post, creates a
   queued draft, schedules a one-time WordPress cron event, and returns HTTP
   `202` without waiting for the AI response.
4. The panel displays **Creating metadata...** and polls every three seconds
   while analysis is active. Results appear in the same panel. Leaving the page
   does not cancel the job. **Tools > Metadata History** remains the history and
   administrative fallback, not a required step in routine publishing.
5. When analysis finishes, WordPress displays a persistent administrative
   notice, a count beside **Metadata History**, and an **Awaiting approval** or
   **Review required** status on the Posts screen. These reminders remain
   until the proposal is approved or discarded.
6. Edit the description, search summary, existing topics, secondary keywords,
   proposed new keywords, and review notes directly in the editor panel. Approve
   proposed new keyword labels explicitly. A held proposal requires resolving
   its issues and marking it ready; existing server-side validation still applies.
   **Regenerate Metadata** requests a fresh AI pass. Topic rationale evidence and
   the extended history remain available on the Tools page.
7. Select **Approve and Add to Search** after review. The submitted edits are
   validated and approved in one request, without a separate save action.
   A revision check rejects metadata that changed since the publisher loaded it.
   Validation errors leave the saved proposal and discovery index unchanged and
   retain edits in the panel. Unsaved edits prompt before leaving or regenerating.
   Approval transactionally updates the MariaDB
   discovery index and audit record, deletes retained full post text, and then
   requests the title-and-summary vector.
8. The panel displays **Added to Search** and the vector status. If vector
   generation fails, the post remains approved and searchable by
   nonsemantic methods. Use **Retry Vector** after correcting the API or
   network problem.

The panel still requires a saved, published post, administrator permissions and
database-authoritative mode for approval. Merely opening it never starts a paid
AI request. This workflow is for initial indexing, not automatic regeneration of
metadata after subsequent content edits.

Publishing first remains the chosen workflow. Unpublished posts show only the
publish-first prerequisite; the save-changes reminder appears only for published
posts with unsaved edits. Draft analysis and prepublication approval are not enabled.

Metadata History no longer provides a standalone form for entering a WordPress
ID or copying post content. New metadata generation starts from the normal post
editor, which supplies all post fields automatically. The history page retains
existing records, review and approval, pending-analysis retries, vector retries,
and draft discard actions. Its URL remains
`tools.php?page=ehrman-post-ingestion` so existing history and review links work.
The retired manual-entry `admin_post_ehrman_ingestion_analyze` action is no longer
registered; the post-editor REST analysis endpoint is unchanged.

## Native Post Lifecycle

Metadata created from the WordPress editor explicitly records its native
`wordpress_post_id`. Imported records retain a separate `source_wp_id`; these
IDs are not assumed to refer to local WordPress posts in a demo installation.
Existing unlinked records are not automatically reassigned during upgrade.

- Unpublishing, making private, or trashing an editor-indexed post hides it from
  all three searches' post results and result counts. Its approved metadata and vector
  remain stored for restoration. Pending jobs are canceled and late analysis
  responses cannot overwrite the canceled attempt.
- Autocomplete suggestions and their counts use stored index metadata without
  publication-status checks. Retained terms from unpublished posts can still
  appear there, including when a category, topic, or selected term narrows the
  suggestions. Selecting a suggestion does not bypass result visibility checks.
- Republishing restores visibility without a new AI call. Content edits still
  require a separate metadata review; restoration does not regenerate metadata.
- If a publisher chooses **Delete Permanently** in WordPress, the plugin removes
  only that native post's linked discovery record, assignments, vector and
  ingestion history. Shared topic/keyword definitions and reader analytics remain.
  Failed cleanup is retried through WordPress cron and reported to administrators;
  the removed post stays hidden from actual post results even while cleanup is
  pending. Autocomplete metadata remains until cleanup succeeds.
- Approval and vector writes recheck their source so deletion during a slow AI
  call cannot recreate an orphan record.

There is no test-post cleanup command or automatic deletion of Render test posts.
Render test posts can remain there. The handoff is built from approved local JSON
and its validated vector package, never from the Render database.

## Analysis States And Recovery

| State | Meaning | Administrator action |
| --- | --- | --- |
| `queued` | Waiting for a one-time WordPress cron worker | Usually none; loading status repairs a missing event |
| `analyzing` | A worker atomically claimed the draft | Wait; a second worker cannot claim the same attempt |
| `ready` | Proposal passed validation and is ready for review | Review, revise, reanalyze, or approve |
| `held` | Proposal requires administrator attention | Review warnings and revise or reanalyze |
| `error` | Analysis failed before producing a usable proposal | Correct the reported issue and retry |
| `approved` | Proposal was committed to the live discovery index | Retry the vector if its status is not complete |

An `analyzing` draft is considered stalled after ten minutes. The editor and
fallback page then offer a fresh attempt. Each worker receives an incrementing
attempt number; a late response from an older attempt cannot overwrite newer
work. Duplicate submissions for the same WordPress post or canonical URL are
rejected before another AI request can be scheduled.

WordPress cron depends on site traffic unless production uses a real cron
runner. For predictable processing, invoke `wp-cron.php` from the platform
scheduler and disable request-driven cron only after that scheduler is proven.

## Security And Data Retention

- Every editor REST action requires an authenticated administrator who can
  edit the specific post.
- WordPress REST nonces protect browser requests; the fallback forms use
  action-specific nonces.
- REST responses use `Cache-Control: no-store` and never include retained full
  post text.
- Full post text exists only in a pending ingestion draft and is deleted after
  approval. It is not included in the portable vector package.
- API errors shown to administrators are sanitized and do not expose the key.

## Verification

Run static checks from the plugin directory:

```powershell
cd wordpress-plugin\ehrman-blog-discovery
composer check
```

Build or refresh the production-equivalent WordPress/MariaDB stack:

```powershell
.\wordpress-production\setup-production-test.ps1
```

Run the server-side regression suite with an available Python runtime:

```powershell
.\wordpress-production\verify-production-test.ps1 `
  -PythonExecutable 'C:\path\to\python.exe'
```

The regression suite covers editor authorization, queued HTTP responses,
missing-event repair, duplicate prevention, atomic worker claims, stale-job
recovery, attempt fencing, full-text redaction, source-change detection,
inline approval validation, revision checks, audit relationships, vector
failure recovery, native-post ownership, publication visibility, restoration,
and deletion during an in-flight embedding request. It uses a shared placeholder
key and does not make a paid AI request.

The repository's `wordpress-production/verify-all.ps1` also runs
`tests/post-ingestion-editor-ui.cjs` on its isolated WordPress site. These
browser checks use real editor components with mocked ingestion responses to
verify progress, inline edits, approval, regeneration, failure recovery, new
keyword consent, and desktop/mobile layouts without paid API calls.

`wordpress-production/verify-render-rebuild.ps1` is also entirely local. It
verifies one-time initial seeding, preservation of database-only editorial data
through container recreation and repeated initialization, and rejection of
test-post vectors from the approved local handoff package. It does not deploy
to Render or change the normal local demo.

Before production acceptance, also perform one controlled end-to-end analysis
with the configured OpenAI project, review and approve its proposal, confirm its
vector is current, and verify that the post appears through Browse Topics,
Keyword Search, and Ask AI.

### Local Verification: November 7, 2026

Plugin 0.10.11 and schema 1.11.0 passed all 30 stages of the full local regression
suite, including PHPCS, PHPStan, the extracted developer-test package, clean
installation, editor/browser tests, database integrity, and backup/restore.
All 22 approved baseline searches retained their results and order; all 4,016
title-and-summary vectors remained current. Private evidence is in
`.tmp/full-regression/fb3c06fb0f3f4b0bb34fd81cae402b17/`.

The separate local rebuild rehearsal passed initial seeding, rejection of an
extra test-post vector without replacing the approved package, and preservation
of database-only editorial data across container recreation and repeated
initialization. All 18 discovery tables matched before and after. Private
evidence is in `.tmp/render-rebuild/6efcf96456d645f1b12da9864da90b42/`.

The normal local demo and Render were not changed. No Render test posts were
deleted, and no paid AI calls, commits, pushes, or deployments were performed.
Live API and Render acceptance testing remains deferred until authorized.

### Default Approval Verification: November 7, 2026

Plugin 0.10.12 defaults to database-managed approval without a source-mode
override. All 17 configuration cases and all 30 full regression stages passed
on MariaDB. Coverage includes actual inline approval with the setting unset,
the explicit JSON lock, initial installation under the default, verified no-op
reruns, and rejection of attempts to replace later editorial data. Evidence:
`.tmp/full-regression/c0cda2eea910469ebb8cce38aafd754d/`.

The local rebuild rehearsal also passed with the source-mode variable omitted:
4,446 posts and 4,016 vectors were seeded, test-post vectors were rejected from
the approved package, and all 18 discovery tables were preserved across restart
and repeated initialization. Evidence:
`.tmp/render-rebuild/32890fab0cbe43cead26ec14e6682924/`.
The normal local demo retains its explicit JSON override. No deployment,
commit, push, real AI request, or change to Render test posts was performed.
