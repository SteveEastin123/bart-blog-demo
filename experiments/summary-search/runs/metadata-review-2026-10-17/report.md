# Ask AI 2: Topics and Keywords in Candidate Review

Run: metadata-review-2026-10-17.

## Design

Three fresh reviews per question, for 50 existing pilot questions: titles/current short summaries; those fields plus assigned topics; those fields plus assigned topics and secondary keywords. All arms receive exactly the same original 100 candidates in the same order from the frozen 500-post Ask AI 2 pilot. No longer summaries, section vectors, new embeddings, new retrieval or Ask AI 1 changes are involved.
The baseline uses the production prompt. Both enriched arms share one replacement instruction permitting labels as supplementary, fallible context. Topics are described as central subjects and secondary keywords as supporting context; matching labels alone do not prove relevance. Existing direct/related/background criteria, model, low reasoning effort, 800 output-token limit, parser, tier ordering and empty-selection fallback remain unchanged. Thus this tests adding metadata with minimal instructions to use it, not a metadata-only change under identical baseline instructions.
The WordPress CLI exports requests and parses responses through the real production helpers. Paid review calls are sent directly from the experiment runner. No public endpoint or plugin is installed, and no production cache or analytics entries are written.

## Results

| Measure | Titles + summaries | + Topics | + Topics + keywords |
|---|---:|---:|---:|
| Top-five relevance (P@5) | 66.0% | 60.0% | 61.2% |
| Top-ten relevance (P@10) | 39.4% | 37.2% | 38.0% |
| Ranking score (nDCG@10 x 100) | 62.0 | 60.0 | 59.5 |
| Relevant posts in top ten | 3.94 | 3.72 | 3.80 |
| Displayed posts in top ten | 5.48 | 5.48 | 5.78 |
| Relevance among displayed posts | 70.2% | 64.2% | 62.5% |
| Mean review API response time (seconds) | 2.20 | 2.10 | 2.11 |
| Measured review cost (cents/question) | 0.225 | 1.113 | 1.287 |
| Estimated question cost incl. unchanged query embedding (cents) | 0.225 | 1.113 | 1.287 |
| Review cost without provider-cache discounts (cents/question) | 1.011 | 1.113 | 1.287 |
| Review input tokens | 11802 | 13350 | 15628 |
| Provider-cached input tokens | 11638 | 0 | 0 |
| Broader fallback rate | 0.0% | 0.0% | 2.0% |

P@5/P@10 have fixed denominators: unfilled slots count as nonrelevant. Grades 2 and 3 are relevant. nDCG uses the same pooled full-post judgments for all three arms, weighting directly answering posts and earlier ranks more strongly. These restricted-corpus scores are not directly comparable with full-blog benchmarks.

## Pairwise Outcomes

| Comparison | First wins | Second wins | Ties | Mean ranking change, second minus first | Paired 95% interval |
|---|---:|---:|---:|---:|---|
| Titles + summaries vs + Topics | 20 | 19 | 11 | -2.00 | [-5.48, +1.36] |
| Titles + summaries vs + Topics + keywords | 22 | 19 | 9 | -2.55 | [-6.21, +1.23] |
| + Topics vs + Topics + keywords | 19 | 16 | 15 | -0.55 | [-4.60, +3.53] |

A tie is an absolute nDCG@10 difference no greater than 0.03. Intervals are paired question-level bootstrap estimates, not measures of grader error or repeated-run variability. Three pairwise comparisons are exploratory and not adjusted for multiple comparisons.

## Recommendation

Keep titles and summaries as the production reviewer input. The baseline had the best average top-five relevance, top-ten relevance and ranking score in this pilot. Neither metadata addition demonstrated an improvement; all three primary ranking-difference intervals include zero, so this is not proof of a universal advantage for the baseline.
Without provider-cache discounts, topics increased average review cost by 10.1%, and topics plus keywords by 27.3%. The small measured time differences do not establish a reliable speed advantage.
A plausible explanation is that broad topic and keyword labels encourage matches that are related to the subject but do not answer the specific question. That is a hypothesis supported by the full-text spot checks, not a causal conclusion from this test. More metadata did not reliably overcome that problem even when the prompt described labels as fallible clues.
Do not expand production inputs based on this result. Before another broad experiment, inspect specific missed or misranked posts to distinguish retrieval failures from review failures. Any later proposed improvement should be confirmed with fresh questions and human judgments on a broader corpus.

## Sensitivity Checks

Numerical full-post adjudications were decided before inspecting aggregate quality results. Before the current corrections, the pairwise outcomes were:

- Titles + summaries vs + Topics: 22 first wins, 18 second wins, 10 ties.
- Titles + summaries vs + Topics + keywords: 22 first wins, 19 second wins, 9 ties.
- + Topics vs + Topics + keywords: 18 first wins, 17 second wins, 15 ties.

As a post-hoc diagnostic, removing whole questions with any discarded model selection or fallback leaves 37 questions. This outcome-dependent subset is not the primary analysis and is not confirmatory. The baseline still has the higher average ranking score than either enriched arm. Details and excluded question IDs are saved in results.json.

## Grading and Limitations

The common pool contains 1072 question/post judgments: 953 reused, verified prior full-post judgments and 119 newly graded pairs. The pool includes all prior judgments for these questions plus newly returned top-ten posts. New grades use complete stored post text, neutral labels, and no method names, ranks, summaries or topic/keyword labels. Evidence quotations are checked against the source text. Grades: 0 irrelevant, 1 marginal, 2 useful/substantial, 3 directly and substantially answering.
There are 10 additional coordinating-agent full-text spot checks and 4 current corrections. See adjudications.json for selection and blinding details. Original grade files are preserved, and corrections apply equally to every arm. Earlier documented corrections are inherited. AI grades are not a human gold standard.
The final grading batch required one offline evidence repair: all three saved attempts omitted literal emphasis asterisks in a quoted source sentence. After reading both complete stored posts, the coordinator restored the exact quotation without changing the saved numerical API grades. A separately documented relevance adjudication is applied in the report. The three raw receipts remain unchanged and all attempts are included in cost; see evidence-repair.json and final-batch-source-check.json.
The same questions have been used in earlier pilots. This is diagnostic, not independent confirmation. The 500-post sample includes deliberately difficult cases and one known stored opening excerpt (post 2359). The reviewer cannot recover relevant posts missing from the frozen candidate lists. Absent tags also do not prove a post lacks relevant discussion.

## Timing and Costs

All 150 accepted reviews are fresh calls, run sequentially in seeded randomized order within each question. Saved raw provider receipts support request identity, usage and cost. No WordPress result cache is used. Re-running the command resumes from saved receipts rather than paying again.
18 responses contained selections discarded by the production parser (unknown IDs/tiers or duplicates); 20 selections were discarded. Raw responses are preserved, not retried for better answers. See responseWarnings in results.json and validation-note.json for the early validation correction.
Timing measures successful direct review API submission through complete response receipt. It excludes vector search, query embedding, WordPress page rendering and browser painting, so it is not end-to-end reader response time. Provider prompt caching is permitted and can differ across arms, particularly because the unchanged baseline inputs were used in earlier tests. Compare both measured and no-discount costs; do not attribute all observed latency differences to metadata.
The estimated total question cost adds the previously measured query-embedding cost, identical across arms, to each new review cost. No new embedding calls were made or charged in this experiment. These estimates do not add server hosting costs.
Accepted review cost: $1.3122. All review attempts: $1.3122 across 150 saved calls. New grading: $0.3323 across 49 saved calls. Total new API cost: $1.6445. Earlier retrieval and grading costs are not charged again or included.
Rates checked October 17, 2026: $0.75/million input tokens, $0.075/million cached input tokens and $4.50/million output tokens for gpt-5.4-mini. Provider invoices are authoritative. [Official model pricing](https://developers.openai.com/api/docs/models/gpt-5.4-mini).

## Question Results

| # | Question | Titles + summaries | + Topics | + Topics + keywords |
|---|---|---:|---:|---:|
| 1 | How did an early Christian congregation receive and circulate a letter before there was a New Testament? | 50.0 | 45.1 | 25.2 |
| 2 | Find discussions of how changing audiences affected the way stories about Jesus were told. | 79.5 | 78.3 | 72.1 |
| 3 | What is the difference between saying a Gospel is anonymous and saying its author was unknown to its first readers? | 75.8 | 84.3 | 89.8 |
| 4 | How did early Christians explain what happened to righteous people who lived before Jesus? | 48.6 | 60.3 | 61.5 |
| 5 | What evidence helps distinguish a Christian interpolation from an original passage in a non-Christian source? | 72.0 | 61.2 | 76.7 |
| 6 | How much could a scribe change a biblical text while believing that the meaning was being preserved? | 61.5 | 64.7 | 65.2 |
| 7 | What can the language and style of a Greek text tell us about its author's education? | 72.4 | 75.1 | 70.9 |
| 8 | How did the expectation of God's judgment influence Jesus' instructions about forgiving other people? | 61.6 | 20.8 | 34.1 |
| 9 | Find posts about the difference between ancient biography and a modern biography when reading the Gospels. | 53.3 | 32.5 | 56.4 |
| 10 | How did converts maintain relationships with non-Christian relatives who continued to worship other gods? | 17.0 | 25.2 | 12.1 |
| 11 | What does the New Testament say about people who never heard the Christian message being judged by God? | 72.9 | 78.9 | 53.6 |
| 12 | How did biblical authors use stories about the past to address crises in their own time? | 59.9 | 75.2 | 66.1 |
| 13 | Why did some Christians value secret teaching while others emphasized a public tradition handed down by apostles? | 79.9 | 71.1 | 68.1 |
| 14 | What would count as independent confirmation of an event when several ancient sources report it? | 86.6 | 62.0 | 81.5 |
| 15 | How do historians evaluate memories of Jesus that were written down by people who never met him? | 76.0 | 81.5 | 80.0 |
| 16 | How did early Christians decide whether a new prophecy came from God or from a false prophet? | 66.9 | 84.7 | 44.1 |
| 17 | Find posts explaining whether early Christians expected their resurrection to be physical or merely spiritual. | 80.9 | 71.5 | 72.4 |
| 18 | How did Paul use Jewish scripture when arguing with gentiles who had not grown up reading it? | 34.8 | 38.9 | 36.6 |
| 19 | What changed when Christianity became a religion supported by emperors rather than a minority movement? | 75.4 | 89.9 | 82.5 |
| 20 | Why do scholars distinguish the date a manuscript was copied from the date its work was composed? | 64.2 | 62.7 | 55.9 |
| 21 | How did stories about martyrs shape Christian identity even for readers who were not facing persecution? | 82.6 | 69.9 | 89.0 |
| 22 | What were the consequences of translating Jesus' Aramaic teachings into Greek for later readers? | 72.8 | 66.6 | 61.4 |
| 23 | Find discussions of whether the Bible presents a consistent view of violence commanded by God. | 77.1 | 61.7 | 64.2 |
| 24 | How did early Christian leaders justify having authority over other believers? | 56.7 | 58.4 | 55.7 |
| 25 | What makes an interpretation of a parable historically plausible rather than just a useful modern lesson? | 67.1 | 67.7 | 33.4 |
| 26 | Why did Christians preserve four Gospels instead of replacing them with one combined account? | 74.8 | 89.4 | 69.6 |
| 27 | How did beliefs about divine justice change when people stopped expecting justice during their own lifetime? | 85.5 | 73.3 | 73.8 |
| 28 | What can the different ways Jesus prays in the Gospels tell us about how the authors understood him? | 92.9 | 73.5 | 68.4 |
| 29 | How did the discovery of previously lost Christian books change what scholars could say about early Christianity? | 62.7 | 68.8 | 59.1 |
| 30 | What evidence is used to distinguish a historical claim about Jesus from a later devotional legend? | 49.9 | 57.9 | 65.9 |
| 31 | Find posts about the relationship between baptism and forgiveness of sins in early Christianity. | 76.5 | 64.8 | 49.8 |
| 32 | How did Christian teaching about helping the poor differ from giving gifts to receive honor or a favor in return? | 48.7 | 65.2 | 44.6 |
| 33 | How do scholars decide whether two biblical passages contradict each other or describe different situations? | 27.8 | 15.4 | 62.5 |
| 34 | What role did illness and healing play in convincing ancient people to join a religious movement? | 79.7 | 84.6 | 80.9 |
| 35 | How can a historical approach to the Bible be useful to readers who remain religious? | 80.9 | 83.4 | 87.0 |
| 36 | What happens when a biblical translation has to choose between two possible meanings of the same Greek word? | 51.3 | 46.1 | 46.9 |
| 37 | How did Paul distinguish his own advice from a command he believed came from Jesus? | 63.7 | 63.7 | 63.7 |
| 38 | Find posts explaining how a scholar changes a long-held interpretation when new evidence appears. | 65.6 | 42.6 | 44.1 |
| 39 | How did ancient Christians describe the relationship between human choices and God's control of history? | 73.5 | 64.1 | 82.8 |
| 40 | What are the strengths and limits of using personal religious experience as historical evidence? | 75.8 | 69.9 | 75.4 |
| 41 | Why does Paul say that Christ appeared to five hundred people, and what can historians infer from that claim? | 29.1 | 29.1 | 33.5 |
| 42 | What does Jesus mean by calling himself lord of the Sabbath in the dispute over picking grain? | 28.4 | 28.4 | 34.4 |
| 43 | Why does Matthew have some of Jesus' followers doubt even when they see him after the resurrection? | 54.4 | 63.7 | 63.7 |
| 44 | What is the significance of Paul calling James the brother of the Lord? | 48.5 | 52.9 | 65.1 |
| 45 | How does the story of the sheep and goats in Matthew connect judgment with helping people in need? | 71.8 | 84.0 | 71.8 |
| 46 | Why does Jesus' statement that the Father is greater than he is create difficulties for later Christian theology? | 36.0 | 38.0 | 44.3 |
| 47 | What do competing accounts of Judas' death reveal about how traditions developed? | 57.9 | 46.0 | 43.9 |
| 48 | Why did Christians debate whether the Shepherd of Hermas should be treated as scripture? | 78.1 | 89.4 | 92.3 |
| 49 | What does Paul's reference to a thorn in the flesh reveal about weakness and apostolic authority? | 22.5 | 0.0 | 22.5 |
| 50 | How does Jesus' encounter with the Syrophoenician woman fit the Gospel's treatment of gentiles? | 20.0 | 19.2 | 20.0 |

## Preservation

All original frozen inputs remain unchanged. All 19 discovery tables retain their row counts and content fingerprints. Production PHP, canonical JSON, summaries, vectors, download skill and WordPress ingestion guidance are unchanged. Nothing was committed, pushed or deployed. Private full text, inputs and raw receipts stay in the existing ignored private directory, outside Render packaging.
No variant has been adopted. Any promising result requires fresh-question confirmation and human review before production changes.
