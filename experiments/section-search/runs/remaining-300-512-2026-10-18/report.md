# Remaining 300-Word Section Vectors

Completed October 18, 2026. Preparation and packaging only; no search integration.

## Results

| Item | Result |
|---|---:|
| Additional eligible posts | 3,486 |
| Additional section records | 17,315 |
| Distinct title-plus-passage inputs | 17,307 |
| Inputs reused from the original pilot | 10 |
| Newly generated vectors | 17,297 |
| Embedding API requests | 541 |
| Recorded input tokens | 6,278,311 |
| Estimated API cost from actual usage | **$0.12556622 (12.56 cents)** |
| Raw float32 vector bytes in the new package | 35,461,120 |
| Complete compressed package bytes, including manifest | 36,602,879 |

The existing 500-post pilot contributes another 2,636 sections, retained separately.
Together the old pilot and new package cover **3,986 eligible posts and 19,951
300-word section records**. They have not been combined into a live search index.

Embeddings use `text-embedding-3-small`, 512 dimensions. The unchanged splitter
targets 300 words, prefers paragraph boundaries within 20%, and overlaps by 60
words. Final sections can be shorter. New sections range from 61 to 360 words,
with an average of 275.9 words and between 1 and 30 sections per post.

Cost uses actual returned token usage at $0.02 per million input tokens, checked
October 18, 2026: [official model pricing](https://developers.openai.com/api/docs/models/text-embedding-3-small).
This is an estimated API charge, not an invoice total. No generation request
failed or required a manual retry. There were no paid review, grading, or search
calls. Existing pilot vectors were not regenerated.

## Package

Private package directory:
`experiments/section-search/private/remaining-300-512-2026-10-18/package/`

| File | Purpose | Bytes |
|---|---|---:|
| `sections-300-512.jsonl.gz` | Passage text, offsets, post identity, input hash, and vector | 36,291,728 |
| `posts.jsonl.gz` | Post ID, title, URL, source-text hash, and word count | 310,159 |
| `manifest.json` | Model, encoding, counts, checksums, and pilot reference | 992 |

Section vectors are base64-encoded little-endian float32 values. The section
archive SHA-256 is
`c49fd24a36b6ce5fbb28fcc88922b85ebc82ab3b13dd664b9880b39580ed5656`.
All package files must remain together. Future integration must explicitly combine
these remaining posts with the separately retained pilot and recheck source data.

## Eligibility and Exclusions

The build retained the pilot's rules: an existing current title/summary vector,
stored post text of at least 100 words, and no Ignore topic. Of 4,446 canonical
posts:

- 500 already belong to the preserved pilot.
- 3,486 are included in this new package.
- 429 have the Ignore topic and remain excluded.
- 30 have current vectors but fewer than 100 stored words and remain excluded.
- Post 3242, `Forgery and Counterforgery`, has no title/summary vector and only
  41 stored words; it also remains excluded.

Excluded IDs are retained in the private `prepared.json`. No post was downloaded,
rewritten, retagged, or repaired. Coverage validation confirms coverage of the
stored text; it does not independently establish that every original download
contains a complete article. The original pilot's known source limitations remain.

## Verification

- All stored words of every included post are covered in their original order.
- Section offsets, numbering, exact source text, paragraph-size allowance, and
  60-word overlap pass validation.
- Duplicate embedding inputs are shared without dropping section/post records.
- Every vector has 512 finite normalized values and matches its saved API receipt
  or the preserved pilot vector for exactly the same embedding input.
- All 541 receipts reconcile to the usage ledger; no unaccounted receipts remain.
- Package contents, exact manifest fields, encoding, counts, checksums, and pilot
  provenance pass the independent offline audit (`EXPANSION_AUDIT_OK`).
- 38 new offline tests pass: 17 builder tests and 21 independent audit tests.
- The 20 existing section-search tests pass.
- All 3,382 preserved files retain their pre-build hashes, including original
  experiments, canonical index files, raw post text, and production plugin files.
- Full text, receipts, and vectors remain Git-ignored. The experiment directory
  remains excluded from Render images.

`manifest.json`, `usage.json`, `results.json`, and `validation.json` in this report
directory retain source hashes, generator/auditor hashes, receipt hashes, counts,
costs, and verification evidence. The builder and audit are documented under
`experiments/section-search/expansion/README.md`.

## Unchanged and Not Included

Ask AI 2, existing summaries and vectors, production plugin code, and the running
database were not changed by this work. No WordPress installation/import or runtime
index update was performed, so the expanded sections are **not yet searchable**.
No new relevance evaluation, commit, push, deployment, or ingestion/download-skill
change was performed. Live WordPress regression was not rerun because no runtime
code or database data was changed; checks here cover the new offline pipeline and
its preserved inputs.
