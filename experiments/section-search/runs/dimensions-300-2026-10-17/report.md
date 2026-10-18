# Section Vectors: 512 vs. 1,536 Dimensions

Run: dimensions-300-2026-10-17. Local pilot only.

## Recommendation

Keep current Ask AI 2 in production and retain 512-dimensional sections as an experiment. This run does not justify expanding section vectors to 1,536 dimensions: the ranking improvement was small and uncertain, top-five relevance did not improve, and raw vector storage triples. Both section methods cost about 3.8 times the current reference because the reviewer reads passage text.
Do not convert the full corpus or change ingestion/download workflows on this evidence. A later experiment should separately investigate selective passage review or reviewer reliability, using fresh holdout questions and human review.

## Design

Three fresh searches for each of the same 50 previous pilot questions, across the same 500 posts: current Ask AI 2; 300-word sections at 512 dimensions with passage review; the identical sections at 1,536 dimensions with passage review. Both section arms use text-embedding-3-small, the unchanged title/summary 512-dimensional branch, the same fusion and tie rules, 100 unique candidate posts, best-passage selection algorithm, passage-aware reviewer instructions, gpt-5.4-mini low reasoning, 800 output tokens, relevance tiers and fallback.
Only section embedding dimensionality changes between the two section arms. The changed vectors can change candidate order, which passage is selected, and which posts reach final review. Ask AI 2 remains a separate production reference with its original title/summary-only reviewer. Comparisons with that reference therefore also change retrieval evidence and reviewer instructions.
The 1,536-dimensional arm makes two query-embedding requests: 512 dimensions for the unchanged title/summary branch and 1,536 dimensions for the section branch. The controls make one 512-dimensional query request. All paid phases are included in cost and full HTTP timing. Existing vectors were retained; no production data was imported or overwritten.
An offline prefix check found close, but not bitwise-identical, agreement between the saved 512-dimensional vectors and normalized first 512 components of the new vectors: mean cosine 0.9999919, minimum 0.9961691; six of 2,636 pairs were below 0.999. This compares saved and newly generated API configurations, not exact truncations of one generation. Minor generation/numeric variation is a limitation; its cause was not established. See prefix-check.json.
These questions were reused from earlier pilots. This is a controlled diagnostic, not fresh-question confirmation or representative reader traffic.

## Results

| Measure | Ask AI 2 | Sections: 512 | Sections: 1,536 |
|---|---:|---:|---:|
| Top-five relevance (P@5) | 61.2% | 64.8% | 63.2% |
| Top-ten relevance (P@10) | 39.4% | 39.2% | 39.6% |
| Ranking score (nDCG@10 x 100) | 58.9 | 61.0 | 61.5 |
| Relevant posts in top ten | 3.94 | 3.92 | 3.96 |
| Posts displayed in top ten | 5.38 | 5.28 | 5.46 |
| Relevance among displayed top ten | 69.8% | 72.7% | 69.1% |
| Mean full HTTP time (seconds) | 2.76 | 3.05 | 3.51 |
| Median HTTP time (seconds) | 2.61 | 2.91 | 3.41 |
| 95th-percentile HTTP time (seconds) | 4.12 | 4.44 | 4.57 |
| Actual cost (cents/question) | 1.016 | 3.890 | 3.899 |
| Cost without prompt-cache discounts (cents/question) | 1.016 | 3.906 | 3.905 |
| Query embeddings (cents/question) | 0.000035 | 0.000035 | 0.000070 |
| Review input tokens | 11802 | 50697 | 50540 |
| Provider-cached input tokens | 0 | 225 | 92 |
| Broader fallback | 0.0% | 2.0% | 0.0% |
| Candidate top-ten relevance | 32.6% | 37.8% | 38.0% |
| Known relevant pool coverage in 100 candidates | 90.3% | 94.3% | 94.4% |

P@5/P@10 use fixed denominators; missing slots count as nonrelevant. Grades 2 and 3 are relevant. nDCG weights higher relevance and earlier placement using a shared judged pool. Scores and candidate coverage are not exhaustive corpus-wide recall and are not comparable directly with full-blog benchmarks.

## Pairwise Results

| Comparison | First wins | Second wins | Ties | Mean ranking change (second minus first) | Paired 95% interval |
|---|---:|---:|---:|---:|---|
| Ask AI 2 vs 300-word sections / 512 | 20 | 26 | 4 | +2.16 | [-3.37, +7.72] |
| Ask AI 2 vs 300-word sections / 1536 | 16 | 25 | 9 | +2.66 | [-2.54, +8.02] |
| 300-word sections / 512 vs 300-word sections / 1536 | 19 | 21 | 10 | +0.51 | [-3.91, +4.72] |

Tie threshold: absolute nDCG difference no greater than 0.03. Paired bootstrap intervals describe sampling across these questions, not AI grading error or repeat-run variability. The primary planned comparison is 512- versus 1,536-dimensional sections; other pairwise comparisons are exploratory.

## Assessment

The larger section vectors did not demonstrate a clear ranking advantage: the primary paired interval includes zero. Do not adopt 1,536 dimensions across the full blog on this pilot alone.
The section arms shared an average of 94.5 of 100 candidates. Among shared candidates, an average of 17.0 selected passages differed per question.
Neither section arm demonstrated a clear ranking advantage over the Ask AI 2 reference in this run: both exploratory paired intervals include zero. This is weaker evidence than the earlier section pilot, which reinforces the need to measure repeat-run variability and use fresh questions rather than assume that one pilot establishes a reliable improvement.
Changing vector size does not remove the cost of reviewing passage text. Any selective-passage or reviewer-prompt experiment should be separate so its effects can be distinguished.

## Grading

The common pool contains 1281 question/post judgments: 1203 retained prior judgments and 78 new judgments. It includes all retained prior judgments plus final top-ten and candidate top-ten posts from all three arms. New pairs were graded from full stored posts, with neutral IDs and no method labels, ranks, summaries or tags. Grades: 0 irrelevant, 1 marginal, 2 substantial support, 3 directly and substantially answering. Evidence quotations are source-verified. AI judgments are not a human gold standard.
There were 8 additional coordinating-agent full-post checks and 3 current numerical corrections. Original grades and receipts remain unchanged. Earlier documented corrections are inherited; latest metadata-review judgments take precedence for overlapping pairs. All arms use identical grades per question/post. See adjudications.json for selection and blinding.
The eight checks covered newly surfaced pairs chosen by SHA-256 order with distinct questions, before reading API grades or aggregate scores. Three grades changed; these remain judgment calls rather than a human reference standard. Before those corrections, the primary comparison was also 21 wins, 19 losses and 10 ties for 1,536 dimensions, with a +0.74-point ranking difference and an interval spanning zero. The conclusion is unchanged.
One new quotation exceeded 25 words after three automated validation attempts. The coordinator read all three posts in that batch and shortened the last-attempt quotation to an exact contiguous phrase; no numerical grades or reasons changed. All three receipts are retained and included in cost. See evidence-repairs.json and repair_evidence.py.
Older retained grades lacked individual textHash fields. The original full corpus hash was verified against the frozen earlier manifest before binding its existing per-post hashes; no old grade changed. The initial and amended grading-code manifests document that compatibility fix and stronger receipt/ranking audits.
The 500-post sample includes deliberately difficult cases and one known stored opening excerpt (post 2359). The reviewer cannot return posts outside its candidates, and pooled labels do not establish that no relevant unjudged posts exist.

## Cost and Timing

Timing covers HTTP submission through complete JSON receipt, including fixture validation, query embeddings, retrieval and final AI review. It excludes login and browser painting. The isolated harness validates both indexes for every arm, adding common preflight overhead; compare within this run, not directly with earlier benchmark timings. The 1,536-dimensional arm also has an additional query-embedding round trip. There is no WordPress result cache; provider prompt-cache discounts may differ and are shown separately.
Accepted 150 searches: $4.4028. All saved search phases: $4.4028, across 350 paid phases. New grading: $0.2265 across 46 calls. New section-index embeddings: $0.019382. Total new API cost: $4.6487. Earlier experiments and reused judgments are not charged again or included.
The production parser discarded invalid selections in 18 responses, totaling 21 discarded selections. Raw outputs were retained, not retried for more favorable answers.
Rates checked October 17, 2026: embeddings $0.02/million input; reviewer/grader $0.75/million input, $0.075/million cached input, $4.50/million output. Saved usage supports these estimates; invoices are authoritative. [Embedding pricing](https://developers.openai.com/api/docs/models/text-embedding-3-small); [reviewer pricing](https://developers.openai.com/api/docs/models/gpt-5.4-mini).

## Storage

The 2,636 new float32 vectors occupy 16,195,584 bytes (15.45 MiB), versus 5,398,528 bytes (5.15 MiB) for 512 dimensions. The separate compressed JSONL package includes section text and metadata and is 13,053,816 bytes. These are experimental files, not database growth. Keeping both indexes requires storage for both.

## Individual Questions

| # | Question | Ask AI 2 | Sections: 512 | Sections: 1,536 |
|---|---|---:|---:|---:|
| 1 | How did an early Christian congregation receive and circulate a letter before there was a New Testament? | 32.9 | 28.3 | 51.8 |
| 2 | Find discussions of how changing audiences affected the way stories about Jesus were told. | 74.3 | 70.7 | 85.7 |
| 3 | What is the difference between saying a Gospel is anonymous and saying its author was unknown to its first readers? | 75.8 | 78.0 | 78.0 |
| 4 | How did early Christians explain what happened to righteous people who lived before Jesus? | 56.6 | 74.8 | 43.1 |
| 5 | What evidence helps distinguish a Christian interpolation from an original passage in a non-Christian source? | 34.9 | 59.7 | 54.6 |
| 6 | How much could a scribe change a biblical text while believing that the meaning was being preserved? | 56.5 | 75.4 | 58.4 |
| 7 | What can the language and style of a Greek text tell us about its author's education? | 84.4 | 66.0 | 56.2 |
| 8 | How did the expectation of God's judgment influence Jesus' instructions about forgiving other people? | 65.0 | 33.9 | 52.8 |
| 9 | Find posts about the difference between ancient biography and a modern biography when reading the Gospels. | 55.0 | 40.3 | 53.2 |
| 10 | How did converts maintain relationships with non-Christian relatives who continued to worship other gods? | 11.8 | 18.5 | 27.5 |
| 11 | What does the New Testament say about people who never heard the Christian message being judged by God? | 36.7 | 71.4 | 58.7 |
| 12 | How did biblical authors use stories about the past to address crises in their own time? | 62.6 | 60.6 | 80.3 |
| 13 | Why did some Christians value secret teaching while others emphasized a public tradition handed down by apostles? | 66.4 | 78.9 | 67.6 |
| 14 | What would count as independent confirmation of an event when several ancient sources report it? | 81.8 | 84.9 | 60.9 |
| 15 | How do historians evaluate memories of Jesus that were written down by people who never met him? | 82.1 | 86.1 | 82.4 |
| 16 | How did early Christians decide whether a new prophecy came from God or from a false prophet? | 66.9 | 74.5 | 74.5 |
| 17 | Find posts explaining whether early Christians expected their resurrection to be physical or merely spiritual. | 84.0 | 79.2 | 79.2 |
| 18 | How did Paul use Jewish scripture when arguing with gentiles who had not grown up reading it? | 53.3 | 39.6 | 20.4 |
| 19 | What changed when Christianity became a religion supported by emperors rather than a minority movement? | 74.0 | 77.1 | 80.7 |
| 20 | Why do scholars distinguish the date a manuscript was copied from the date its work was composed? | 78.9 | 48.1 | 48.1 |
| 21 | How did stories about martyrs shape Christian identity even for readers who were not facing persecution? | 70.3 | 73.7 | 72.1 |
| 22 | What were the consequences of translating Jesus' Aramaic teachings into Greek for later readers? | 68.2 | 68.7 | 76.0 |
| 23 | Find discussions of whether the Bible presents a consistent view of violence commanded by God. | 58.2 | 88.0 | 58.9 |
| 24 | How did early Christian leaders justify having authority over other believers? | 40.6 | 41.8 | 68.2 |
| 25 | What makes an interpretation of a parable historically plausible rather than just a useful modern lesson? | 52.7 | 65.0 | 60.5 |
| 26 | Why did Christians preserve four Gospels instead of replacing them with one combined account? | 77.0 | 63.1 | 59.6 |
| 27 | How did beliefs about divine justice change when people stopped expecting justice during their own lifetime? | 77.9 | 88.7 | 68.9 |
| 28 | What can the different ways Jesus prays in the Gospels tell us about how the authors understood him? | 66.9 | 78.7 | 78.7 |
| 29 | How did the discovery of previously lost Christian books change what scholars could say about early Christianity? | 62.5 | 47.5 | 74.7 |
| 30 | What evidence is used to distinguish a historical claim about Jesus from a later devotional legend? | 70.5 | 78.4 | 87.0 |
| 31 | Find posts about the relationship between baptism and forgiveness of sins in early Christianity. | 54.9 | 68.7 | 76.0 |
| 32 | How did Christian teaching about helping the poor differ from giving gifts to receive honor or a favor in return? | 65.2 | 36.1 | 44.6 |
| 33 | How do scholars decide whether two biblical passages contradict each other or describe different situations? | 69.6 | 45.2 | 38.8 |
| 34 | What role did illness and healing play in convincing ancient people to join a religious movement? | 84.6 | 65.8 | 73.2 |
| 35 | How can a historical approach to the Bible be useful to readers who remain religious? | 90.1 | 63.3 | 70.5 |
| 36 | What happens when a biblical translation has to choose between two possible meanings of the same Greek word? | 40.6 | 69.7 | 56.2 |
| 37 | How did Paul distinguish his own advice from a command he believed came from Jesus? | 63.7 | 39.0 | 63.7 |
| 38 | Find posts explaining how a scholar changes a long-held interpretation when new evidence appears. | 44.6 | 13.3 | 29.5 |
| 39 | How did ancient Christians describe the relationship between human choices and God's control of history? | 55.0 | 69.6 | 69.6 |
| 40 | What are the strengths and limits of using personal religious experience as historical evidence? | 67.0 | 52.7 | 48.5 |
| 41 | Why does Paul say that Christ appeared to five hundred people, and what can historians infer from that claim? | 30.5 | 16.9 | 40.8 |
| 42 | What does Jesus mean by calling himself lord of the Sabbath in the dispute over picking grain? | 30.5 | 74.7 | 76.7 |
| 43 | Why does Matthew have some of Jesus' followers doubt even when they see him after the resurrection? | 59.7 | 83.2 | 83.2 |
| 44 | What is the significance of Paul calling James the brother of the Lord? | 58.0 | 80.8 | 83.7 |
| 45 | How does the story of the sheep and goats in Matthew connect judgment with helping people in need? | 62.8 | 73.4 | 76.5 |
| 46 | Why does Jesus' statement that the Father is greater than he is create difficulties for later Christian theology? | 31.6 | 50.9 | 38.3 |
| 47 | What do competing accounts of Judas' death reveal about how traditions developed? | 67.8 | 46.0 | 32.6 |
| 48 | Why did Christians debate whether the Shepherd of Hermas should be treated as scripture? | 74.7 | 65.2 | 85.5 |
| 49 | What does Paul's reference to a thorn in the flesh reveal about weakness and apostolic authority? | 0.0 | 40.0 | 56.5 |
| 50 | How does Jesus' encounter with the Syrophoenician woman fit the Gospel's treatment of gentiles? | 12.8 | 56.2 | 12.8 |

## Verification

Local production regression passed, including syntax checks on 77 PHP files, canonical import counts, semantic coverage, ingestion/analytics regressions, public-page responses, private-file protection, search modes and pagination. The experiment helper passed 1,466 mocked WordPress checks (32 mocked HTTP calls), PHPCS WordPress-Core and PHPStan level 5. Offline Python suites passed: 21 dimension tests, 20 section tests, 29 summary tests and 15 metadata-review tests.
The final audit reconciled all 150 saved search results with real provider receipts, replayed relevance-tier ordering and fallback, and checked every new grade against its receipt. The temporary dimension helper was deactivated after the measured searches. See validation.json.

## Preservation

All frozen earlier inputs, results and source hashes remain unchanged. All 19 discovery tables retain identical counts and content fingerprints. Production plugin code, canonical JSON, summaries, title/summary vectors and existing section vectors are unchanged. No commit, push, deployment, ingestion change or download-skill change is included. Full text and raw provider evidence remain in the ignored private directory, excluded from Render packaging.
