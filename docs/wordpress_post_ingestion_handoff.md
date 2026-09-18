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
approve a completed proposal first. While JSON remains authoritative,
approval is intentionally locked; after handoff, set the post source to MySQL
to allow approved proposals to update the live index.

## Configuration

Supply secrets as server-side environment variables. Never store them in
WordPress options, source control, JSON, browser code, logs, or exports.

- `EHRMAN_INGESTION_OPENAI_API_KEY`: dedicated administrator-ingestion key.
- `EHRMAN_INGESTION_OPENAI_MODEL`: optional; defaults to `gpt-5.6-sol`.
- `EHRMAN_INGESTION_REASONING_EFFORT`: optional; defaults to `high`.
- `EHRMAN_DISCOVERY_POST_SOURCE`: use `json` during the current development
  phase and `mysql` only after the production source-of-truth transition.

The ingestion key is separate from reader-facing Ask AI configuration so its
model, budget, permissions, and usage can be managed independently.

## Editor Workflow

1. Save and publish the WordPress post normally.
2. Open the **Search Metadata** panel in the block editor.
3. Select **Analyze**. The REST request validates the saved post, creates a
   queued draft, schedules a one-time WordPress cron event, and returns HTTP
   `202` without waiting for the AI response.
4. Continue editing or leave the page. The panel polls every three seconds
   while analysis is active. **Tools > Post Ingestion** provides the full
   review screen and an administrative fallback.
5. When analysis finishes, WordPress displays a persistent administrative
   notice, a count beside **Post Ingestion**, and an **Awaiting approval** or
   **Review required** status on the Posts screen. These reminders remain
   until the proposal is approved or discarded.
6. Review the description, search summary, topics, rationales, secondary
   keywords, new-keyword proposals, and warnings. Reanalyze when the saved
   source changed or the proposal needs a fresh AI pass.
7. Approve only after review. Approval transactionally updates the MySQL
   discovery index and audit record, deletes retained full post text, and then
   requests the title-and-summary vector.
8. If vector generation fails, the post remains approved and searchable by
   nonsemantic methods. Use **Retry Vector** after correcting the API or
   network problem.

## Analysis States And Recovery

| State | Meaning | Administrator action |
| --- | --- | --- |
| `queued` | Waiting for a one-time WordPress cron worker | Usually none; loading status repairs a missing event |
| `analyzing` | A worker atomically claimed the draft | Wait; a second worker cannot claim the same attempt |
| `ready` | Proposal passed validation and is ready for review | Review, revise, reanalyze, or approve |
| `held` | Proposal requires administrator attention | Review warnings and revise or reanalyze |
| `error` | Analysis failed before producing a usable proposal | Correct the reported issue and retry |
| `approved` | Proposal was committed to the live MySQL index | Retry the vector if its status is not complete |

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

Build or refresh the production-equivalent WordPress/MySQL stack:

```powershell
.\wordpress-production\setup-production-test.ps1
```

Run the complete acceptance suite with an available Python runtime:

```powershell
.\wordpress-production\verify-production-test.ps1 `
  -PythonExecutable 'C:\path\to\python.exe'
```

The regression suite covers editor authorization, queued HTTP responses,
missing-event repair, duplicate prevention, atomic worker claims, stale-job
recovery, attempt fencing, full-text redaction, source-change detection,
approval, audit relationships, and vector failure recovery. It uses a
placeholder ingestion key and does not make a paid AI request.

Before production acceptance, also perform one controlled end-to-end analysis
with the real ingestion project, review and approve its proposal, confirm its
vector is current, and verify that the post appears through Browse Topics,
Keyword Search, and Ask AI.
