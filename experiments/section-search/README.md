# Local Section-Vector Pilot

Ask AI 3 is an isolated experiment, not a replacement for Ask AI 2. It searches a
frozen 500-post sample and compares the existing title/summary retrieval method
with that method supplemented by 300-, 400-, or 500-word sections.

## Scope and Safety

- Administrator-only page: http://localhost:8086/ask-ai-3/.
- Disabled by default. Requires WordPress environment `local`, localhost, and an
  explicit enable option. REST requests require administrator privileges and a
  valid WordPress cookie/nonce.
- No changes to production plugin classes, production vectors, ingestion, or
  ordinary search analytics. Experiment requests have a separate private log.
- The entire experiment directory is excluded from the Docker build context;
  it is not included in Render images. The experiment plugin is copied into the
  running local container only.
- Full posts, generated vectors, API responses, and raw results are under ignored
  `private/`. The installed index is outside the web root. No keys are embedded in
  the plugin, browser, reports, or sample manifest.
- A local rebuild that recreates the container removes the installed experiment
  files. Reinstall from the retained private host files; do not regenerate vectors.
- Each live search makes a paid embedding call and a paid review call. Comparing
  with baseline runs two searches. There is deliberately no WordPress result cache.
  Provider prompt caching is allowed and reported in usage.

## Frozen Design

`sample.json` records every selected post and the sample hash. `questions.json`
contains 50 new questions (40 representative and 10 challenge questions), plus
five separate development questions. Questions were fixed before search results.

Selection starts with posts having current 512-dimensional title/summary vectors
and stored full text, excluding ignored posts and texts shorter than 100 words.
400 posts are selected with seeded proportional category/length stratification.
100 additional challenge posts are drawn from 150 nominations after AI inspection
of complete text for substantive details, multiple subjects, contextual arguments,
or incidental mentions that could confuse retrieval. This is a deliberately
enriched pilot, not an unbiased estimate of performance across the whole blog.

Section boundaries prefer nearby paragraph ends. Targets are approximately
300/400/500 words, with 60/80/100 words of overlap. All section embeddings use
`text-embedding-3-small`, 512 dimensions, and title-plus-passage input. Identical
inputs are embedded once and reused. There are 2,636 / 2,017 / 1,630 sections,
6,283 section records, and 6,195 unique embedding inputs.

Each query uses the same model and embedding dimensions. All four arms restrict
retrieval to the same 500 posts and select 100 unique parent posts. Section modes
add a fixed reciprocal-rank contribution (weight 1, constant 60) using the best
section per parent. Multiple sections never occupy multiple final post slots.
Best-section scoring can still favor long posts because they have more chances
to match; inspect that effect before scaling up.

The first benchmark keeps the current candidate reviewer, its prompt, model,
output limit, title/summary inputs, and broader-result fallback unchanged. The
optional UI setting that also supplies matching passages to the reviewer is a
separate experiment and is not included in the four-arm results.

`Baseline.php` deliberately calls existing private, pure scoring/prompt helpers
through reflection so the pilot does not change production APIs. This is test-only
coupling, not a recommended production integration. Source fingerprints stop the
experiment if those implementations change. Summary hashes stop it if the frozen
posts no longer match the live index. Revalidate after a canonical data rebuild.

## Reinstall and Test

With the existing local MariaDB/WordPress stack running:

```powershell
./experiments/section-search/install-local.ps1
python experiments/section-search/test_corpus.py
python experiments/section-search/test_evaluate.py
node experiments/section-search/ui-test.cjs
```

The installer does not rebuild the stack, import data, call OpenAI, commit, push,
or deploy Render. Its PHP tests mock API calls. UI tests use mocked search
responses and test administrator access, anonymous denial, mobile layout, and
desktop layout. Node requires the repository's Playwright installation and Edge.

Disable without deleting data:

```powershell
docker compose -f wordpress-production/compose.local.yaml exec -T wordpress wp option update ehrman_section_pilot_enabled 0 --allow-root --path=/var/www/html
```

## Reproduce This Run

Use Python 3.10+ and NumPy for corpus preparation; the HTTP evaluation uses the
standard library. Credentials are read from the ignored local `.env` file or
environment variables. Never put credentials on command lines or in reports.

Completed data preparation is resumable through `corpus.py` and `api.py`. Preserve
the private corpus and raw responses; the public manifest alone is not enough to
reconstruct the private full-text dataset. `api.py package` regenerates runtime
files from existing embeddings without calling the API.

```powershell
# These two commands make paid calls. Run only for an explicitly authorized evaluation.
python experiments/section-search/evaluate.py run
python experiments/section-search/evaluate.py grade
python experiments/section-search/evaluate.py audit
# Reporting does not call the API.
python experiments/section-search/evaluate.py report
```

Saved successful requests are resumed, not repeated. Resume files must match the
question and sample hash. To run another evaluation, preserve this directory's
results first and use a new run identity/directory; do not overwrite an old run.

Full-post grading pools the top ten from all four arms, removes duplicate posts,
shuffles them behind opaque labels, and sends complete stored post text without
method names or ranks. Grades are 0 irrelevant, 1 marginal, 2 useful/substantial,
3 directly answering. Exact evidence quotations are checked against source text.
An AI judge is not a human gold standard; review close cases and disagreements.
The evidence audit regrades failed quotation checks without revealing earlier
grades. Raw API responses, original judgments, and audit revisions are retained.
`adjudications.json` records the agent's full-text spot checks and explicit
corrections. Quote checks normalize typography, case, and whitespace and allow
ordered excerpts with explicit ellipses; they do not accept paraphrases.

P@5 and P@10 use fixed denominators, treating missing slots as nonrelevant. nDCG@10
uses graded relevance and a pooled ideal list, not exhaustive blog-wide judgments.
Win/loss/tie uses an absolute nDCG difference tolerance of 0.03. Timing covers
HTTP submission through complete result receipt, not browser paint. Costs include
both query embedding and candidate review; separate preparation/grading costs are
not per-question operating costs.

The initial monolithic runtime loader exceeded PHP's 128 MB limit on the second
benchmark question. Runtime storage was changed to small metadata plus streamed
compressed section records, without changing vectors or ranking. Initial runs
are retained separately and excluded from final timing comparisons.

## Files

- `corpus.py`, `api.py`: sampling, chunking, resumable preparation, packaging.
- `plugin/`: local WordPress page, protected REST endpoints, ranker, private log.
- `evaluate.py`: HTTP comparison, blinded full-post grading, metrics/report.
- `sample.json`, `questions.json`: reproducible public experiment specification.
- `results.json`, `report.md`: final judgments, costs, times, and interpretation.
- `private/`: private evidence, vectors, complete text, screenshots, raw runs.

Export the server-side audit log before rebuilding the local container:

```powershell
docker compose -f wordpress-production/compose.local.yaml cp wordpress:/opt/ehrman-section-pilot/requests.jsonl experiments/section-search/private/server-requests.jsonl
```

Do not select a production section size solely from this pilot. Confirm any
promising result on the full eligible corpus and fresh held-out questions.

## Follow-Up: Matching Passage Evidence

The October 17 follow-up has its own directory,
`runs/evidence-300-2026-10-17/`, and private records under
`private/evidence-300-2026-10-17/`. The original four-arm report is not overwritten.

It replays the original 300-word arm's exact 100 candidates, order, titles,
summaries, and best passages for all 50 questions. Both arms receive the same
evidence-aware instructions; only the second arm receives the passage field.
This is a fresh summary-only control, not the original production prompt.
Model, reasoning, output limit, tiers, and fallback remain fixed. Candidate and
prompt fingerprints are checked before accepting paired results.

The protected `review-evidence` REST route accepts only a frozen question number
and an evidence toggle. It cannot accept arbitrary candidates or a file path.
Private fixture fingerprints and the original production source guards are
checked. The endpoint never reads or writes production result caches or analytics.
Every API response, including incomplete responses, is retained privately.

Timing measures the final review request only, not embedding/retrieval or browser
paint. Review cost is measured from usage. An explicitly labeled estimated query
cost adds the original embedding cost, which is not charged again in the replay.
Prior full-post relevance grades are frozen and reused where available; newly
returned top-ten posts are graded from full text without method or rank labels.
Quotation checks must pass. Scores use the joint top-ten pool from this test.

```powershell
# No API calls: prepare immutable fixtures, then install the local endpoint.
python experiments/section-search/evidence.py prepare
./experiments/section-search/install-local.ps1
# Paid calls, only for an authorized evaluation. Completed results are resumed.
python experiments/section-search/evidence.py run
python experiments/section-search/evidence.py grade
# Preserve the server response audit before recreating the container.
docker compose -f wordpress-production/compose.local.yaml cp wordpress:/opt/ehrman-section-pilot/evidence-300-2026-10-17/responses.jsonl experiments/section-search/private/evidence-300-2026-10-17/server-responses.jsonl
# No API calls.
python experiments/section-search/evidence.py report
python -m unittest discover -s experiments/section-search -p 'test_*.py'
```

This does not change the Ask AI 3 UI's existing optional evidence mode or the
production Ask AI 2 prompt. The controlled test has separate explicit instructions
so evidence is not silently appended to a summary under a summary-only prompt.

## Follow-Up: Fresh-Question End-to-End Comparison

`runs/end-to-end-300-2026-10-17/` preserves a separate 50-question comparison of
Ask AI 2 restricted to the same 500-post sample versus the 300-word section
approach. Questions are fixed before either arm runs; they are not authored from
the sample's full posts or retrieved results. New questions can cover familiar
subjects, and are not a representative sample of real reader traffic.

The control keeps the production hybrid retrieval and summary-only reviewer
prompt. The section arm adds the existing section retrieval contribution and
supplies best-matching passages with the earlier evidence-aware prompt. Model,
512-dimensional embeddings, candidate cap, reasoning, output limit, and fallback
remain fixed. This compares complete approaches, not the isolated effect of one
change. It generates no new post vectors and changes no production settings.

The local administrator-only `compare-300` endpoint accepts a frozen question ID
and one of two allowlisted arms. It verifies fixture and source fingerprints and
records both API phases privately. Timing covers HTTP submission through final
JSON receipt, including embedding, retrieval, and review, but not browser paint.
Arm order is seeded and randomized within each question. Completed requests are
resumed without calling them again; no WordPress search-result cache is used.

The full-post grader receives the union of each arm's final top ten and pre-review
candidate top ten, without method names or ranks. All grades are new for these
questions. Candidate coverage is limited to known relevant posts in this pool;
it is not exhaustive corpus-wide recall. P@5/P@10 keep fixed denominators.
Failed evidence checks retry with the same grading rubric. If three saved attempts
fail, grading stops for source review. Explicit corrections in
`evidence-repairs.json` are tied to the exact response hash and post ID, must match
the original quotation, and must verify against that post. They never change a
numerical grade or overwrite the API response. Grade changes from full-text spot
checks are recorded separately in `adjudications.json`. The report includes the
original-score sensitivity comparison as well as the corrected results.

```powershell
# No paid calls: freeze questions/source/data, install endpoint, run mocked tests.
python experiments/section-search/comparison.py prepare
./experiments/section-search/install-local.ps1
# Paid calls for an explicitly authorized run.
python experiments/section-search/comparison.py run
python experiments/section-search/comparison.py grade
# Retain private raw audit data before recreating the container.
docker compose -f wordpress-production/compose.local.yaml cp wordpress:/opt/ehrman-section-pilot/requests.jsonl experiments/section-search/private/end-to-end-300-2026-10-17/server-requests.jsonl
# No paid calls.
python experiments/section-search/comparison.py report
python experiments/section-search/comparison.py verify
```

The existing Ask AI 3 UI is unchanged; its older optional evidence setting is not
the controlled endpoint's evidence-aware prompt. The new comparison is accessed
through the test runner. Preserve reports and raw data before starting another
run; use a new run identity rather than overwriting this one.
