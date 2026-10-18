# Improved-Summary Pilot

Local experiment only. Compare unchanged Ask AI 2 with the same implementation
using fuller summaries and separately generated title/summary embeddings. The
existing 500-post pilot and 50 questions from `end-to-end-300-2026-10-17` are reused.
This is a diagnostic comparison, not a fresh-question confirmation benchmark.

## Agent Summary Status

Completed `agent-summaries-2026-10-17`: 500 agent-written summaries, with no
summary-generation API calls. The first 250 were written by the coordinating agent;
five authorized Codex helper agents each wrote 50 of the remaining posts.
Writers read the full stored text, without old summaries or benchmark inputs.

- Combined frozen output: `private/agent-summaries-2026-10-17/summaries.json`.
- Average length: 137.32 words; median: 141; range: 57-160.
- 474 summaries meet the normal 120-180-word target; 26 have documented
  shorter-source exceptions. Post 2359 contains only a stored opening excerpt.
- All 500 IDs and supporting quotations pass offline validation. Eight
  validator tests pass, and all 112 files frozen in the input manifest retain
  their original hashes. Automated checks do not prove every summary claim.
- The coordinating agent additionally checked 14 helper-written summaries
  against their full source articles. The review record lists those IDs.
- Two identical-summary pairs reflect reposts of the same substantive text:
  17830/7071 and 11611/25687. All post IDs remain distinct and the sample is
  unchanged.
- Four summaries exceed the current ingestion validator's 1,200-character
  limit by 2-23 characters. This experimental output is not an approved
  production import.

See `runs/agent-summaries-2026-10-17/quality-review.json` for checks and caveats.
That review is the original, pre-embedding snapshot. The existing eight API
summary samples remain separate and unchanged.

## Embedding Status

After separate user approval, generated all 500 experimental title/summary
embeddings with `text-embedding-3-small`, 512 dimensions. The summaries were
not rewritten through the API, and audit quotations were not embedded.

- Frozen package: `private/agent-summaries-2026-10-17/fixtures/improved.json`.
- Manifest and receipts: `runs/agent-summaries-2026-10-17/embedding-manifest.json`
  and `embedding-results.json`. Raw provider responses stay in ignored `private/`.
- 16 successful requests; 96,011 provider-reported input tokens.
- Estimated embedding cost: **$0.00192022 total**, at $0.02 per million input
  tokens ([official pricing](https://developers.openai.com/api/docs/models/text-embedding-3-small),
  checked October 17, 2026). The provider invoice remains authoritative.
- Raw float32 vectors: 1,024,000 bytes. JSON package with summaries and base64
  vectors: 2,099,909 bytes. This is experimental file storage, not database growth.
- All 500 vectors passed identity, dimension, finite-value, normalization,
  packed-size and saved-response checks. All 112 preserved input hashes and the
  frozen agent-summary hash remain unchanged. Seventeen offline unit tests pass.

No canonical summaries, existing vectors, download skill, or production ingestion
settings were changed. Nothing was committed or deployed. The embedding receipt
is a historical snapshot preceding the separately authorized comparison below.

## Comparison Complete

All 100 fresh searches completed: 50 paired questions, unchanged production
reviewer prompt/settings, identical 500-post scope and 100-candidate limits.
The local experimental plugin is now deactivated; its files and results remain
available for inspection and reproduction. Normal Ask AI 2 was not replaced.

| Measure | Existing summaries | Longer summaries |
|---|---:|---:|
| P@5 | 63.6% | 62.0% |
| P@10 | 40.4% | 38.8% |
| nDCG@10 x 100 | 64.9 | 65.1 |
| Average HTTP response time | 2.23 seconds | 2.09 seconds |
| Average cost per question | 1.019 cents | 1.642 cents |
| Questions won | 21 | 22 |

Seven questions tied. The paired ranking difference's 95% bootstrap interval
includes zero. There is **no clear quality improvement**, and longer summaries
cost about **61% more**. Recommendation: retain the existing production summaries
and vectors; do not change the download skill or WordPress summary generation
on this evidence. This is a restricted-corpus diagnostic, not a full-blog score.
Missing result slots count against fixed-denominator precision.

Grading covered 953 question/post pairs: 719 verified prior full-post judgments
reused, plus 234 newly blinded full-post judgments. Eight coordinating-agent
spot checks produced three documented corrections. These are AI judgments, not
an independently human-graded gold standard. All 19 discovery-table counts and
fingerprints match before and after both the comparison and final validation.

- Report: `runs/agent-summaries-2026-10-17/comparison-report.md`.
- Detailed metrics, per-question results and audit totals: `comparison-results.json`
  in the same directory.
- Frozen design: `comparison-manifest.json`; spot checks: `comparison-adjudications.json`.
- Validation and cleanup record: `comparison-verification.json`.
- Searches cost $1.3305; new grading cost $0.6482. Total including the earlier
  experimental vector generation: **$1.9806**. Summary writing used Codex.

## Metadata Review Comparison

The separately approved `metadata-review-2026-10-17` experiment is complete.
It reused the exact original short-summary candidate lists and compared three
fresh final reviews per question: titles/summaries, plus topics, and plus
topics/secondary keywords. No vectors or retrieval behavior changed.

| Measure | Titles + summaries | + Topics | + Topics + keywords |
|---|---:|---:|---:|
| P@5 | 66.0% | 60.0% | 61.2% |
| P@10 | 39.4% | 37.2% | 38.0% |
| nDCG@10 x 100 | 62.0 | 60.0 | 59.5 |
| Review cost without cache discounts, cents | 1.011 | 1.113 | 1.287 |

Neither metadata addition showed a clear improvement. Recommendation: keep the
existing reviewer inputs. All 150 reviews and 1,072 pooled full-post judgments
are recorded; total new API cost including grading was $1.6445. Actual baseline
cost benefited from a warm provider prompt cache, so use the no-discount row
for a fairer input-cost comparison. Timings in this run cover only the final
review API request, not end-to-end search.

See [the full report](runs/metadata-review-2026-10-17/report.md),
[saved results](runs/metadata-review-2026-10-17/results.json), and
[experiment instructions](metadata-review/README.md). The earlier runs remain
separate and preserved. Production code, data and skills were not changed.

## Frozen Design

- Active summary run: `agent-summaries-2026-10-17`. The Codex agent reads full
  posts and authors the summaries here, without summary-generation API calls.
  Writer inputs contain only the post ID, title and full text, not benchmark
  questions, results, grades, old summaries, topics or keywords. The target is
  normally 120-180 words, with shorter summaries when the source cannot support
  that length. Preserve uncertainty, guest authorship and debate positions.
- Embeddings: `text-embedding-3-small`, 512 dimensions, exactly
  `Title: {title}\nSummary: {summary}`. Existing vectors are never overwritten.
- Search: same 500 posts, hybrid ranking, 100 unique candidates, unchanged
  production review prompt/model/settings, tiers and fallback in both arms.
- Fresh paired searches in seeded randomized arm order; no WordPress result
  cache. Record provider caching, both paid phases and full HTTP response time.
- Full-post grading: reuse frozen, verified question/post judgments when already
  available; grade new pooled top-ten posts blindly from full text. Missing
  result slots count against P@5 and P@10. nDCG uses a common judged pool.
- Preserve prior runs and production code/data. Full text, drafts, vectors,
  provider responses, credentials and raw logs stay in ignored `private/`.
- Better coverage, summary length and the generation process change together;
  this does not isolate the effect of word count alone.

Check
faithfulness and coverage without looking at search scores. Freeze the generated
index before any measured searches. Do not tune the prompt to benchmark outcomes.
Exact supporting quotations accompany each summary for auditing, but must not
be included in embeddings or candidate-review inputs.

The earlier `improved-summaries-2026-10-17` run contains eight API-written samples.
Keep those separate and unchanged; they are not agent-written output. Bulk API
summary generation was declined. The 500 experimental embeddings were approved
and completed separately. The subsequent search and grading calls were separately
authorized and have now completed. Any new paid run still requires authorization.

## Conditional Adoption Requested By Steve

If Steve approves the longer summaries after seeing the results:

1. First compare agent-written and API-written summaries on the same posts for
   faithfulness, coverage, style, and downstream search quality. Only adopt the
   API workflow if it produces sufficiently comparable results.
2. Update the `ehrman-post-ingestion` download skill's editorial summary guidance
   and any helper validation to the adopted specification.
   Continue agent-based summary writing in the download workflow.
3. Update WordPress `Post_Ingestion_Analyzer` summary generation to that same
   specification, without changing topic/keyword selection rules.
   API summary generation is intended for the deployed WordPress post workflow,
   not for rewriting this pilot through the API.
4. Align `Post_Ingestion_Proposal_Validator`, admin/editor help, warnings, tests,
   and documentation. Current guidance is 60-80 words/three sentences and the
   proposal validator has a 1,200-character maximum; check schema and every
   downstream length constraint before changing these.
5. Regenerate title/summary vectors only for changed summaries, preserve rollback
   data, and refresh the portable vector package through the approved workflow.
6. Treat a full-corpus summary rewrite as a separate approved migration. Do not
   silently replace the existing 4,000+ summaries based on this pilot.

These adoption changes are recorded, not implemented or automatically approved.

## Commands

Use the repository Python runtime. All `agent_work.py` commands are offline.
Manually author each batch after reading complete posts; do not manufacture
summaries from titles, excerpts, existing summaries or extraction templates.

```powershell
python experiments/summary-search/agent_work.py prepare
python experiments/summary-search/agent_work.py show --batch batch-01 --offset 0 --count 3
python experiments/summary-search/agent_work.py validate --batch batch-01 --complete
python experiments/summary-search/agent_work.py validate
# Only when all 500 summaries have been authored and reviewed:
python experiments/summary-search/agent_work.py validate --complete
python experiments/summary-search/agent_work.py verify
```

The separate `embed_agent.py` command reads the frozen agent summary package,
verifies its reviewed checksum, and never writes canonical data or the database.
`plan` and `verify` are offline. Generation resumes from saved provider responses.

```powershell
python experiments/summary-search/embed_agent.py plan
# Only after explicit user approval for paid embedding calls:
python experiments/summary-search/embed_agent.py embed --approve-api
python experiments/summary-search/embed_agent.py verify
python -m unittest discover -s experiments/summary-search -p 'test_*.py'
```

The older `generate.py` API-authoring prototype is not part of this agent-written
comparison. The active comparison uses `compare_agent.py`, `score_agent.py`, and
the local-only `plugin/summary-pilot.php`. The installer is local-Docker-only and
runs mocked PHP tests before any measured calls. It does not deploy to Render.

```powershell
python experiments/summary-search/compare_agent.py verify
# Local installation only, when intentionally repeating the experiment:
./experiments/summary-search/install-local.ps1
# Paid phases require explicit user approval; completed results are reused:
python experiments/summary-search/compare_agent.py run --approve-api
python experiments/summary-search/score_agent.py grade --approve-api
# Recalculate reports offline from frozen results and documented corrections:
python experiments/summary-search/score_agent.py report
```

Preserve database fingerprints and the private server request audit when running
a new comparison. Do not delete completed result files to trigger paid reruns;
create a separately identified experiment instead.
