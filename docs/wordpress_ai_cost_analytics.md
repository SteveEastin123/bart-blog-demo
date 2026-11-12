# AI Cost Analytics

Administrators open **Tools > AI Analytics**. The default Overview tab separates
Ask AI, Post Metadata, and bulk/index-vector costs. Ask AI retains question-level
usage and review details. Post Metadata groups API attempts by generation run and
provides a CSV export. Switching tabs preserves the date range.

## Accounting

- Ask AI costs include query embeddings and candidate review, including retries.
  Its existing filters and totals exclude retired search methods and experiments.
- Post Metadata includes descriptions, summaries, topic/keyword classification,
  and post-vector generation. An output-limit retry belongs to the same run;
  requesting metadata again starts a new run. Vector retries remain with that run.
- Bulk/index-vector calls remain separate. Importing existing vectors does not
  create a billable event. External scripts and agent work are not included.
- Usage events are stored in `ai_usage`, independent of `ingestion_drafts`.
  Deleting a post or restarting its analysis does not delete these costs. No full
  post text, account information, or API key is stored in cost events.
- Ask AI's test-analytics reset still removes active search history and its costs.
  It does not reset metadata-generation or bulk-index costs. The Overview reflects
  retained records, not a lifetime billing statement.

## Dates and Completeness

Date filters use America/New_York, including daylight-saving transitions. Ask AI
uses question submission dates and includes linked reviews. Metadata and bulk
embeddings use event dates. A run spanning the selected period contributes only
the calls inside that period; its displayed average is the recorded cost divided
by the distinct runs represented.

Schema 1.13.0 adds explicit event purposes, run IDs, known-cost flags and a unique
historical-event key. Existing draft costs are copied once into labeled historical
snapshots. Existing request history and draft values are preserved. Earlier
regenerations, overwritten costs, deleted drafts and missing responses cannot be
reconstructed. Historical timestamps may be approximate. Repeating installation
does not duplicate the snapshots.

Unknown usage or unsupported metadata-model pricing is labeled unknown, not free.
Dollar totals sum known costs and warn when incomplete; CSV leaves unknown amounts
blank. Stored estimates use the existing model rate schedules, recorded with a
pricing-version label. They are not a substitute for the provider invoice.

## Verification

`metadata-cost-tests.php` is included in the developer regression package. It
uses mocked Responses/Embeddings calls to cover retries, failed validation,
timeouts, regeneration, deletion retention, historical import idempotence, date
boundaries, totals, CSV safety and rendering. Analytics dashboard tests cover
the three tabs, permission/nonces, exports and desktop/mobile layouts. No paid
calls are needed for these tests.
