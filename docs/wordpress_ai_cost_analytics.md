# AI Cost Analytics

Administrators open **Tools > AI Analytics**. The default Overview tab summarizes
ongoing Ask AI and Post Metadata costs. Ask AI retains question-level
usage and review details. Post Metadata groups API attempts by generation run and
provides a CSV export. Switching tabs preserves the date range.

All three tabs share the same responsive summary-card layout. Overview shows
total estimated cost, Ask AI cost, Post Metadata cost, questions, and metadata
generation runs. Its two-row activity table links to the detail tabs, preserving
only the date range, with total costs in dollars and averages in cents. Historical
bulk/index preparation charges are excluded from the cards, table and Overview
total; their underlying usage records are preserved. New-post vectors remain
included in Post Metadata.

Post Metadata shows three cards: total estimated cost, average cost per generation
run (in cents), and generation runs. Metadata-generation and post-vector costs
remain in each run's expandable details rather than separate summary cards. Tiny
nonzero vector charges retain extra decimal places. The expandable token breakdown uses
the same filtered events as the cards, across all result pages; cached input and
reasoning tokens are subsets, not extra tokens. Unknown-cost warnings remain
visible above the detail table. Each run shows its total cost before the
expandable per-attempt breakdown.

## Accounting

- Ask AI costs include query embeddings and candidate review, including retries.
  Its existing filters and totals exclude retired search methods and experiments.
- Post Metadata includes descriptions, summaries, topic/keyword classification,
  and post-vector generation. An output-limit retry belongs to the same run;
  requesting metadata again starts a new run. Vector retries remain with that run.
- Historical bulk/index-vector calls are retained but excluded from Overview.
  Their unknown costs do not trigger warnings for the two ongoing activities.
  Importing existing vectors does not
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

Legacy vector states `not_requested` and `not_applicable` with no recorded usage or
cost do not create synthetic embedding events. In particular, an Ignore post does
not imply a failed or unknown-cost vector call. Attempted calls whose usage is
missing remain unknown, and recorded charges are preserved.

Unknown usage or unsupported metadata-model pricing is labeled unknown, not free.
Dollar totals sum known costs and warn when incomplete; CSV leaves unknown amounts
blank. Stored estimates use the existing model rate schedules, recorded with a
pricing-version label. They are not a substitute for the provider invoice.

## Verification

`metadata-cost-tests.php` is included in the developer regression package. It
uses mocked Responses/Embeddings calls to cover retries, failed validation,
timeouts, regeneration, deletion retention, historical import idempotence, date
boundaries, totals, bulk-history exclusion/preservation, CSV safety and rendering.
Analytics dashboard tests cover the three tabs, linked date filters,
permission/nonces, exports and desktop/mobile layouts. No paid
calls are needed for these tests.
