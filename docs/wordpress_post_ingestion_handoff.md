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

Analysis produces a validated proposal before live discovery data changes.
Manual mode requires administrator review and approval. Opt-in Automatic mode
approves a valid proposal and creates its vector after first publication.
Database-managed approval is enabled by default;
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

## Publishing Mode

**Tools > Ehrman Discovery > Publishing settings** sets the site default:
**Manual** (the installation default) or **Automatic**. These radio buttons save
immediately and show Saving/Saved feedback; there is no separate Save button.
Only administrators can change the setting, through WordPress's authenticated
`/wp/v2/settings` endpoint. Failed saves restore the confirmed setting and show
an error. A lost response is checked before the selection is restored.
The **Metadata mode** selector in the post editor's Search Metadata
panel offers **Use site default**, **Manual**, or **Automatic** before first
publication. Save the choice with the post; the selector locks after publication.
It does not change the separate JSON/database-authority setting.

Automatic mode queues one background job when a new post is first published.
Publication itself does not wait for OpenAI. The job generates and validates
metadata, approves it through the existing transactional writer, and generates
the title-and-summary vector. Validated new keywords are included. Ignore-only
posts do not receive vectors. The editor checks status and shows progress and
completion; Search Metadata history identifies the approval as **Automatic**.
Usage and costs are recorded in the existing post-ingestion analytics.

Automatic mode requires a working WordPress cron runner, database-managed mode,
the shared OpenAI key, and an administrator authorized to edit the post.
Scheduled publication retains the authorizing administrator from the saved post.
Permissions, the effective mode, the saved post and vocabulary are rechecked
before approval. Changing the site default does not process existing posts.
Updates, autosaves, restores and later republication never regenerate metadata.
Existing imported records are not automatically linked or overwritten.

Invalid/held output, changed source, missing configuration or revoked permissions
stop automatic approval and leave a recoverable status. There are no automatic
job retries; the existing one-time retry for truncated model output still applies.
If the initial vector fails, approved metadata remains available to Browse Topics
and Keyword Search; **Retry Vector** retries only that stage. A queue or worker
stalled for ten minutes becomes actionable in **Needs attention/Failed**. Use
manual regeneration or review to recover; do not repeatedly republish the post.
Automatic processing never prevents the article from being published.

Schema 1.16.0 adds automatic-work status and approval-mode audit columns in place.
Existing installations and posts stay Manual unless explicitly opted in.

## Manual Editor Workflow

1. Save and publish the WordPress post normally.
2. Open the **Search Metadata** panel in the block editor.
3. Select **Create Metadata**. The REST request validates the saved post, persists
   a draft, and runs analysis in that request. It returns HTTP `200` with the
   completed metadata, or an error if generation fails. New requests do not
   schedule a WordPress cron job.
4. The panel displays **Creating metadata...** while waiting, then shows the
   results and approval button in the same panel. Keep the page open until
   generation finishes. **Tools > Search Metadata** provides a worklist and
   administrative fallback, not a required step in routine publishing.
5. When analysis finishes, WordPress displays a persistent administrative
   notice, a count beside **Search Metadata**, and an **Awaiting approval** or
   **Review required** status on the Posts screen. These reminders remain
   until the proposal is approved or discarded.
6. Review **Description**, **Summary**, the AI-selected **Topics**, and one
   combined **Keywords** list. Description and Summary remain editable. Topics
   and keywords come from the stored AI result. In both the editor panel and
   Tools review page, an X removes a term from this proposal and its undo button
   restores it before saving or approving. Removal does not delete shared terms
   or change other posts. At least one topic must remain for a ready proposal;
   all keywords may be removed. There is no vocabulary dropdown, separate
   new-keyword form, or Reviewer Notes field. Overall approval includes only the
   retained selections, including retained new keywords. The server validates
   removals against the saved proposal and preserves its review-state rules.
   A held result cannot be approved; its
   blocking warnings remain visible. Correct the source or vocabulary as needed,
   then use **Regenerate Metadata** for a fresh AI pass.
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
AI request. Later revisions are explicit actions, not automatic regeneration
after subsequent content edits.

### Revising Approved Metadata

Both the post editor and Tools record offer **Edit Metadata** and **Regenerate
Metadata** for the latest approved metadata linked to an editable, published
WordPress post. Imported records without a native link remain read-only.

- **Edit Metadata** copies the approved proposal into a separate pending revision,
  without an AI call. Edit Description or Summary and remove/restore selected
  topics and keywords. It does not introduce manual vocabulary pickers.
- **Regenerate Metadata** asks for confirmation of the API charge, reads the current
  saved post, and generates a new proposal. Tools opens the progress screen first;
  the editor shows progress inline. Neither schedules background generation.
- **Approve Changes** validates the pending revision. The existing live metadata
  and vector remain active until the replacement can be committed atomically.
  A changed title or summary requires a replacement vector; unchanged input reuses
  a valid current vector without a paid call. An Ignore assignment removes the
  vector. Vector or storage failure leaves the existing live version unchanged.
- **Cancel Changes** discards only the pending revision, retaining approved history
  and live search data. Recorded API usage is retained even if changes are cancelled.
  Both the Tools form and post-editor action require the displayed revision token.
  If another tab saves or regenerates the proposal, an older tab cannot discard it;
  reload the current metadata before cancelling. The database deletion checks the
  same revision fields atomically, and a rejected cancellation leaves queued work intact.

Approved records are immutable history, linked through `revision_parent_id`.
`revision_base_hash` detects intervening live metadata changes. Only one pending
revision may be opened for the current approved record. Old history links lead
to the latest record instead of offering actions on obsolete versions. Approval
also rechecks the saved post and pending proposal after any vector request.
No full post text is retained on an approved history record. Schema 1.15.0 adds
the revision fields in place; it does not reimport or replace discovery data.

Publishing first remains the chosen workflow. Unpublished posts show only the
publish-first prerequisite; the save-changes reminder appears only for published
posts with unsaved edits. Draft analysis and prepublication approval are not enabled.

**Tools > Search Metadata** defaults to **All** records. The **Needs attention**
filter shows published posts whose metadata has not been generated, proposals
requiring approval, and failures (including pending vector retries). Filters also
expose Generating and Complete records, with title search and inclusive
publication-date bounds.
For WordPress-linked posts, status filters use only the latest metadata record,
so an older failure or pending vector retry does not remain actionable after a
newer revision supersedes it. **All** retains every historical version and its
original status. Direct history links and cost reporting are unchanged. Unlinked
legacy records remain independent; matching source IDs alone do not establish
that they belong to the same local post.
Results are ordered by publication date, newest first, with deterministic
tie-breakers. Counts and 25-row pages are queried in the database; list queries
do not load post bodies, proposals, or vector payloads. Pagination preserves
filters and retains access to records older than the former 100-row limit.

The normal post editor remains the primary workflow. Administrators may also
select **Create Metadata** for an unprocessed published post on the worklist.
This nonce-protected POST checks administrator and post-edit permissions, reads
the saved post server-side, and opens a progress screen immediately with the post
title, a spinner, and **Creating metadata...**. After the screen is displayed, its
client makes the protected REST analysis request and waits for completion.
Generation still runs in that foreground server request, not a scheduled job.
The client then opens the saved record for review and approval. It never accepts
manually supplied post text or metadata.
The redirect opens that post's metadata record regardless of the list's active
filters. If generation fails after creating a record, or the same Create Metadata
action is submitted again, the page opens that existing record for review or
recovery rather than returning to a list that may no longer show the post.
The client checks for an existing record before generation and checks again
after a request failure; it never automatically retries generation. If the saved
record is still being analyzed, the progress screen stays open and checks its
status every three seconds until it can open review or error recovery. These
read-only checks do not start AI requests. A server-reported stalled record opens
the recovery controls; a ten-minute client wait limit prevents indefinite polling.
If no record can be retrieved or status checks keep failing, the page displays an
error and a link back to the post's status.
Without JavaScript, an explicit Continue button uses the existing synchronous
form handler.
Opening, filtering, or paging through the list does not start an AI request.
The page retains review and approval, pending-analysis retries, vector retries,
and draft discard actions, plus read-only inspection of imported metadata. Its URL remains
`tools.php?page=ehrman-post-ingestion` so existing history and review links work.
The retired manual-entry `admin_post_ehrman_ingestion_analyze` action is no longer
registered; the post-editor REST analysis endpoint is unchanged.

Completed metadata is detected from discovery-index records, not merely the
presence of an ingestion history row. Explicit native ownership takes priority.
For imported records without native ownership, source IDs are matched only when
their URL belongs to the current site's home URL (HTTP/HTTPS variants accepted).
A source ID from another site cannot claim an unrelated demo post. On a staging
site with a different hostname, imported source IDs are deliberately not assumed
to be native IDs. This display-only recognition never changes imported ownership.

Both review interfaces use the same workflow checks: a linked WordPress post must
still be published and match the analyzed source, and submitted edits must match
the reviewed proposal revision. A rejected approval does not save the submitted
edits. Retrying analysis from Search Metadata refreshes the draft from the current
saved WordPress post. Legacy external drafts without a native-post link continue
to use their retained source text; source IDs are never assumed to be local IDs.

Vocabulary approval fingerprints cover topic and keyword definitions, including
topic aliases, but exclude keyword usage counts. Approving or removing another
post that uses existing keywords therefore does not invalidate newly generated
metadata or manual revisions. Usage counts remain available to the generation
prompt. Actual vocabulary changes still require regeneration.

Older pending drafts retain their original count-bearing fingerprint and can be
approved only if that full snapshot still matches. If it no longer matches,
regenerate metadata: the old hash cannot distinguish count-only changes from
definition changes. No upgrade rewrites pending drafts or approved history.

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
| `queued` | Persisted draft awaiting its atomic claim, or a legacy queued job | Regenerate if a legacy job is stranded; status reads do not start work |
| `analyzing` | A worker atomically claimed the draft | Wait; a second worker cannot claim the same attempt |
| `ready` | Proposal passed validation and is ready for review | Review, revise, reanalyze, or approve |
| `held` | Proposal failed approval requirements | Correct the source or vocabulary, then regenerate |
| `error` | Analysis failed before producing a usable proposal | Correct the reported issue and retry |
| `approved` | Proposal was committed to the live discovery index | Edit or regenerate a separate revision; retry a pending vector on the latest record |

An `analyzing` draft is considered stalled after ten minutes. The editor and
fallback page then offer a fresh attempt. Each worker receives an incrementing
attempt number; a late response from an older attempt cannot overwrite newer
work. Duplicate submissions for the same WordPress post or canonical URL are
rejected before another AI request is started.

Manual generation requests do not depend on WordPress cron. Automatic publication
and legacy queued jobs use the one-time cron callback, with atomic claims to
prevent duplicate processing. The host must run WordPress cron reliably before
enabling Automatic mode.

Allow sufficient HTTP/proxy time for foreground generation: the upstream request
has a 180-second timeout and may retry once if structured output is truncated.
A timeout is reported as a failure, not approval-ready metadata. Reload status
before retrying after a browser/proxy disconnect, because PHP may have completed
the request. A fatal interruption remains recoverable through the ten-minute
stalled-attempt check. Manual generation never automatically approves or indexes
a proposal.

Prompt version 9 supplies the exact description/summary opening-verb list from
`Post_Ingestion_Proposal_Validator` to the model. The generation instructions and
validator therefore use one source of truth for this house-style rule. Invalid
output still requires regeneration; this change does not add automatic paid
retries or bypass validation. Automatic mode uses the same validator.

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

The regression suite covers Manual defaults, per-post overrides saved during REST
publication, scheduled publication, automatic approval and vectors, duplicate-job
protection, permission/configuration changes, and failure recovery. It also covers
editor authorization, completed foreground responses,
timeout recovery, read-only status requests, duplicate prevention, atomic worker claims, stale-job
recovery, attempt fencing, full-text redaction, source-change detection,
inline approval validation, revision checks, audit relationships, vector
failure recovery, native-post ownership, publication visibility, restoration,
and deletion during an in-flight embedding request. It uses a shared placeholder
key and does not make a paid AI request.

The repository's `wordpress-production/verify-all.ps1` also runs
`tests/post-ingestion-editor-ui.cjs` and `tests/post-ingestion-revision-ui.cjs`
on its isolated WordPress site. The revision checks use a synthetic vector and
intercept regeneration, so they do not make paid API calls. These
browser checks use real editor components with mocked ingestion responses to
verify progress, inline text edits, overall approval, regeneration, failure recovery,
combined keywords, and desktop/mobile layouts without paid API calls.

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
