# Completion and reliability notes

All 250 questions were attempted against both methods. There are 249 completed paired relevance comparisons.

Question 207 exhausted the unchanged 800-token reviewer limit in both methods. Ask AI 2 completed one retry; Ask AI 3 also failed its retry. Its missing post list is a technical failure, not a zero-relevance result or a tie. The original 200-question cohort is complete; the new challenge cohort has 49 comparable pairs out of 50 attempted.

Successful-result latency excludes failed attempts and manual diagnosis time. For question 207, the failed HTTP attempts took 5.18 seconds for Ask AI 2 and 8.00 plus 6.98 seconds for Ask AI 3; none produced a final list.

| Cohort | Method | First-attempt completions | Eventual completions | Reported cost per attempted question, including failures |
|---|---|---:|---:|---:|
| original-200 | ask-ai-2 | 200/200 | 200/200 | $0.01004 |
| original-200 | ask-ai-3 | 200/200 | 200/200 | $0.03906 |
| challenge-50 | ask-ai-2 | 49/50 | 50/50 | $0.01021 |
| challenge-50 | ask-ai-3 | 49/50 | 49/50 | $0.03876 * |

* One failed Ask AI 3 review reported all-zero usage despite an incomplete response. Its actual billed cost is unverified; the reported total is not proof that this call was free. All costs are token-based estimates at frozen prices, not invoice reconciliation.

Reported search-call cost, including failed attempts: $12.26981, subject to that anomaly. Full-post grading and rechecks: $10.91626. Index construction and hosting are excluded.

2546 question/post pairs have accepted full-text grades. One accepted grade is applied to both methods; unsupported quotations or incorrect labels can require additional blind individual rechecks. These are AI judgments, not a human reading every post. Exact quotations alone do not prove relevance.

The original 200 questions are a development/regression set, not an unseen holdout. The 50 new questions are deliberately specific diagnostic cases, not a representative traffic sample. The grader and reviewer use the same model; confidence intervals do not capture grader uncertainty.

All 105 frozen inputs and 3382 earlier experiment files passed their hash checks. Discovery database fingerprints are unchanged. Unknown or duplicate reviewer IDs were discarded by the existing parser and retained in the audit.

[Full comparison](report.md) | [Source spot checks](source-spotchecks.md) | [Detailed protocol](../../full-comparison/README.md) | [Failure receipts index](search-failures.json)

## Useful-result coverage

7 challenge pairs had no grade-2/3 result from either method. Their ranking winners were {"tie": 2, "ask-ai-3": 4, "ask-ai-2": 1}. Such wins reflect ordering of marginally related posts, not the delivery of a substantially useful answer.

Among the 42 challenge pairs where at least one method returned a relevant post, Ask AI 3 won 23, Ask AI 2 won 11, and 8 tied. This is a secondary diagnostic; the predefined full-cohort win counts are unchanged.

Ask AI 3 alone returned relevant posts on questions [217, 225, 244]; Ask AI 2 alone did so on []. This concerns the returned top tens, not whether relevant posts exist elsewhere in the corpus.
