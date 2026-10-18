# Full-corpus Ask AI comparison

Run `full-corpus-250-2026-10-18` compares current Ask AI 2 with the expanded Ask AI 3 on the same 3,986 eligible posts. This local-only experiment is excluded from Render packaging and does not write discovery tables or live analytics.

## Five batches

1. Original run 1: questions 1-50.
2. Original run 2: questions 51-100.
3. Original run 3: questions 101-150.
4. Original run 4: questions 151-200.
5. Newly authored specific-detail questions: 201-250.

All questions were frozen before paid searches. The new questions deliberately challenge specific-detail retrieval; they are not a representative traffic sample and answers are not guaranteed in the corpus. Earlier data and judgments are not overwritten or reused.

## Matched controls

- Same corpus, 100 candidates, `text-embedding-3-small`/512, and pinned `gpt-5.4-mini-2026-03-17` review model.
- Ask AI 2 preserves title/summary hybrid ranking, including existing lexical and assigned-term boosts. Ask AI 3 additionally fuses section ranking and sends a matching passage to the reviewer.
- Same review settings and output parser; each arm retains its existing prompt. This compares complete search methods, not just vector retrieval.
- WordPress query/result caches bypassed. Provider caching is measured and billed normally. Each query's arm order alternates; pairs are sequential.
- Browser form submission through final rendered list is measured with an identical frontend for both arms. Authentication/page load are excluded. Backend phases are also saved. File integrity checks and experimental file loading affect timing.
- Full-text grading uses an opaque, shuffled, deduplicated pool of final top-ten posts. The grader sees the question, full post text and title, not search method, candidate scores, summary, or rank.
- This is automated AI grading, not a human reading every result. Each question/post pair receives one accepted grade applied to both methods. Failed evidence checks can require additional full-text grading calls; their original responses and costs are retained. The main agent investigates flagged cases, rather than claiming to have personally read every post.
- Grades 0-3; grades 2/3 relevant. Fixed-denominator precision@5/@10, pooled nDCG@10, tie threshold 0.03. No corpus-wide recall claim.
- Rejected evidence quotations remain in private receipts. `repair_quotes.py` can correct only capitalization/whitespace to match a literal source passage; it cannot change grades or fabricate missing evidence. Other failures require source review.
- If a grading response has missing, duplicated, or incorrect post labels, none of that batch's scores are accepted. Every expected post must receive a fresh blind individual judgment before the batch can be assembled. Unit tests verify that partial replacements cannot pass.
- Search and grading spending guards are $25 and $20 respectively. These are stop limits, not estimates or authorizations for unrelated requests.

## Operations

Run `prepare.py` once before searches, then `install-local.ps1`. Preflight revisions are preserved separately; never revise questions or retrieval after paid searches start.

For each batch, run `run.cjs --approve-api --limit=50` (then 100, 150, 200, 250). Existing results are skipped; a claimed but unfinished request stops rather than silently repeating a paid call. Server-completed results can be recovered without another charge, but missing browser timing is not invented.

Run `grade.py --approve-api --limit 50` with the same successive limits. Grade receipts and accepted batches are resumable. Copy `/opt/ehrman-full-comparison/requests.jsonl` into the private run folder, then run `audit.py` and `report.py`. The report contains batch results and separate original-200/challenge-50 aggregates.

Question 207 subsequently exhausted the unchanged reviewer output limit in both methods. Ask AI 2 completed one retry; Ask AI 3 did not. The recovery runner `resume-searches.cjs` differs from the frozen runner only by skipping this documented failed pair; a unit test enforces that constraint. `grade_remaining.py` reuses the original grading function while excluding question 207 from paired relevance metrics. Preserve its successful Ask AI 2 result, all three failed-attempt receipts, and `search-failures.json`; do not fabricate an empty Ask AI 3 result or count this failure as a quality tie. Final reporting must distinguish 250 attempted questions from 249 completed pairs.

Take database fingerprints before/after and run `install-local.ps1 -Disable` when finished. The public Ask AI 3 trial stays available; only the administrator-only comparison routes are disabled.

For the completed run, execute `audit.py`, `report.py`, and then `finalize.py` after the after-database fingerprint is available. Finalization requires every nonfailed pair to be graded and adds the completion/reliability notes without changing the frozen reporting or grading functions.

Do not commit private full posts, provider responses, credentials or browser sessions. Keep public protocol, questions, aggregate results and per-question grades for future comparisons.

## Interpretation limits

The original 200 questions are a regression set used in earlier development, not an unseen holdout. The 50 new specific-detail questions are a separate diagnostic set. Neither should be presented as a random sample of all reader questions.

The grader and search reviewer use the same pinned model, although the grader receives full posts and no method labels or ranks. Blind grading reduces method-label bias; it does not eliminate model errors or replace reader testing. Confidence intervals describe variation across questions, not uncertainty in the AI grades.

Only the union of the two final top-ten lists is graded. Pooled nDCG measures relevance and ordering within that pool, not whether the search found every relevant post in the corpus. Precision uses fixed denominators of five and ten, so returning fewer useful posts lowers those scores. Historical runs with different denominator rules are not directly comparable.

Search cost includes the query embedding and candidate review. Evaluation grading is reported separately; prior index-building cost is excluded. Browser timing includes the local experiment's integrity checks and file loading, and is not a production capacity benchmark.

Any failed paid search is retained in `search-failures.json` and reconciled against raw receipts. A diagnosed incomplete response may receive one explicitly recorded retry with unchanged settings. Successful-result timing does not include a failed attempt or manual diagnosis time; first-attempt reliability and retry-inclusive cost must be reported separately. Costs are token-based estimates at the frozen prices, not an invoice reconciliation.
