# 300-Word Passage Evidence Review

Run: October 17, 2026. Local experiment only.

## Matched Comparison

50 reused pilot questions and the exact 100 ordered candidates and best-matching 300-word sections from each original query. Both arms rerun the reviewer with the same evidence-aware prompt, model (gpt-5.4-mini), low reasoning effort, 800-token output limit, tier schema, and fallback behavior. Only the matching_passage field differs. No vectors were regenerated and no production code was changed.

The prompt allows a substantive passage to establish relevance even if the detail is absent from a title or summary, while retaining scope, overview, and incidental-mention safeguards. The summary-only arm also receives these instructions; it is a fresh control, not an unchanged replay of the old production prompt.

## Results

| Measure | Titles + summaries | Titles + summaries + section |
|---|---:|---:|
| P@5 | 57.6% | 65.6% |
| P@10 | 40.2% | 43.8% |
| Ranking score (nDCG@10 x 100) | 76.2 | 79.9 |
| Relevant posts in top 10 | 4.02 | 4.38 |
| Returned posts in top 10 | 6.22 | 6.10 |
| Relevant fraction of displayed top 10 | 61.7% | 68.1% |
| Review response time (seconds) | 2.04 | 2.08 |
| Review cost (cents/question) | 1.012 | 3.908 |
| Estimated query cost including embedding (cents) | 1.012 | 3.908 |
| Review cost with no provider cache discount (cents) | 1.012 | 3.908 |
| Input tokens | 11891 | 50733 |
| Cached input tokens | 0 | 0 |
| Broader-result fallback rate | 0.0% | 0.0% |

Passage wins: **22**; summary wins: **15**; ties: **13** (nDCG difference tolerance 0.03).
Mean passage-minus-summary nDCG difference: +0.0378; paired 95% bootstrap interval [-0.0093, +0.0874].

## Timing and Cost Scope

Timing is authenticated HTTP submission through receipt of the complete REVIEW response. It excludes query embedding, candidate retrieval, and browser paint because candidates are frozen. It is NOT full search response time and must not be compared directly with the previous end-to-end timings.
Review costs use actual response token counts and provider cached-token discounts. The estimated per-query total adds each original query's embedding cost, which was not charged again in this replay. One-time vectors, earlier tests, and grading are excluded from per-query costs. No WordPress result cache is used.
Recorded review attempts: 100; accepted reviews: 100. Review attempts $2.4602; new full-post grading $0.2683; total new experiment $2.7285. Prices are estimates, not invoices.
Rates checked in [official OpenAI documentation](https://developers.openai.com/api/docs/models/gpt-5.4-mini): $0.75/million input, $0.075/million cached input, $4.50/million output.

## Judgment and Limitations

436 pooled question/post judgments: 335 reused frozen blinded full-post grades (including documented adjudications), and 101 newly graded from complete stored posts. Every returned top-ten post is graded; all supporting quotations are verified. Judges receive neither method labels nor ranks.
The coding agent spot-checked 6 complete posts from large wins/losses and corrected 2 grades. These spot checks were not blinded to outcomes; shared grades apply to both arms. Original API judgments remain unchanged in private files and are retained as preAdjudication in results.json. See adjudications.json for the reasoning.
P@5 and P@10 use fixed denominators; unfilled slots count as nonrelevant. nDCG uses the same pooled ideal for both arms, not exhaustive judgments of all 500 posts. Original four-arm scores have a different pool and must not be compared numerically as though it were unchanged.
Automated grading is not a human gold standard. Bootstrap intervals measure question-sampling uncertainty, not judge error or repeat-run LLM variability. This reuses development/pilot questions and is not held-out validation. The enriched 500-post sample contains 100 challenge posts. Keep Ask AI 2 unchanged until broader, fresh-question validation supports a production decision.

## Question Groups

| Group | Summary nDCG@10 | Passage nDCG@10 |
|---|---:|---:|
| representative | 0.7498 | 0.8042 |
| challenge | 0.8092 | 0.7807 |

## Assessment

Passage-assisted review improved fixed-slot P@5 by 8.0 percentage points and P@10 by 3.6 points in this sample. Review cost was 3.86 times the control cost. Review latency was similar, but that is one run and not a full-search timing comparison.
The paired ranking interval crosses zero. This is a promising observed relevance improvement, not a statistically conclusive ranking win or a production replacement recommendation. The representative subset improved while the ten challenge questions did not. Keep Ask AI 2 unchanged; retain this variant for further evaluation before expanding to the full corpus.
A concrete benefit: for the question about hearing texts read aloud in the Roman world, passage review selected The Digital Bible (post 15564). Its full text explicitly connects low literacy to reading Paul's letters aloud; the broad summary concentrates on digital technology and omits that detail.
Extra evidence does not guarantee better selection. For the question about what converts gave up, passage review omitted useful posts about wealth and asceticism. A single best-matching passage can still be incomplete, and the reviewer remains variable.

## Verification

The local replay checks cover 50 identical candidate lists, identical prompts/settings, passage presence, mocked review selection, anonymous access denial, and unchanged production analytics/vector counts. PHP coding standards, PHPStan level 5, Python integrity tests, and the existing WordPress acceptance suite passed. Raw results and original four-arm results are preserved. No commit, push, or Render deployment was performed.
