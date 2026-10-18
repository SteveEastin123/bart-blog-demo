# Ask AI 2: Existing vs. Longer Summaries

Run: agent-summaries-2026-10-17. Local pilot only.

## Design

Fresh paired searches for the same 50 previous pilot questions on the same 500 posts. Control: existing short summaries and their vectors. Experimental: 500 agent-written longer summaries and separately generated title/summary vectors. Both use text-embedding-3-small at 512 dimensions, unchanged production hybrid ranking, 100 unique candidates, and the same gpt-5.4-mini reviewer prompt, settings, tier ordering, and fallback.
Both retrieval and reviewer evidence change. This tests the combined effect of longer, newly written summaries, not word count alone. No section vectors or Ask AI 1 are involved. These questions were used earlier: this is a diagnostic pilot, not independent confirmation or representative reader traffic.

## Results

| Measure | Existing summaries | Longer summaries |
|---|---:|---:|
| Top-five relevance (P@5) | 63.6% | 62.0% |
| Top-ten relevance (P@10) | 40.4% | 38.8% |
| Ranking score (nDCG@10 x 100) | 64.9 | 65.1 |
| Relevant posts in top ten | 4.04 | 3.88 |
| Posts displayed in top ten | 5.58 | 5.48 |
| Relevance among displayed posts | 69.0% | 68.3% |
| Mean response time (seconds) | 2.23 | 2.09 |
| Median response time (seconds) | 2.19 | 2.03 |
| 95th-percentile response time (seconds) | 3.11 | 2.70 |
| Total cost (cents/question) | 1.019 | 1.642 |
| Query embedding (cents/question) | 0.000035 | 0.000035 |
| Final AI review (cents/question) | 1.019 | 1.642 |
| Cost without provider-cache discount (cents/question) | 1.019 | 1.642 |
| Review input tokens | 11802 | 20232 |
| Provider-cached input tokens | 0 | 0 |
| Broader-result fallback rate | 0.0% | 0.0% |
| Pre-review candidate P@10 | 32.6% | 33.0% |
| Known relevant pool coverage in 100 candidates | 92.7% | 92.3% |

Longer summaries won **22** questions; existing summaries won **21**; **7** tied. A tie means an absolute nDCG@10 difference of at most 0.03.
Mean longer-minus-existing ranking difference: +0.0028; paired 95% bootstrap interval [-0.0570, +0.0647]. This interval measures question-sampling uncertainty, not grader error or repeated-run variability.
The scores apply to this restricted 500-post corpus and should not be compared directly with earlier full-blog benchmarks. With fewer than ten results often returned, fixed-denominator P@10 can be much lower than relevance among the posts actually displayed.

## Assessment

The longer-summary arm costs 61.1% more per question. Its measured response time is -0.13 seconds different, but one request per arm/question does not establish a reliable speed advantage.
There is no clear ranking improvement: the paired interval includes zero. Retain the existing production summaries and vectors rather than adopting a bulk rewrite on this evidence.
Do not change the download skill or WordPress summary generation based on this pilot alone. Any follow-up should investigate specific retrieval or reviewer errors before assuming that more summary text will help. The optional agent-versus-API summary comparison remains deferred, not automatically approved.

## Full-Post Grading

953 question/post judgments covering 341 distinct posts: 719 verified prior judgments reused and 234 new judgments. Both final top tens and candidate top tens were pooled and deduplicated per question. Newly graded posts were supplied in full, with neutral labels and no method names, ranks, summaries or prior judgments. Grades: 0 irrelevant, 1 marginal, 2 useful/substantial, 3 directly answering. Positive grades require source-verified quotations.
Prior judgments include documented full-text corrections from the earlier run; those corrections were not blind to the earlier outcomes. AI grading is not a human gold standard. Quotation verification establishes textual support, not perfect relevance judgment.
This run also received 8 coordinating-agent full-text spot checks and 3 documented numerical corrections. Selection and blinding details are in comparison-adjudications.json. Original grade files remain unchanged; corrections apply equally to both versions. Before these new corrections: 22 longer-summary wins, 21 existing-summary wins, 7 ties.
P@5/P@10 use fixed denominators; missing result slots count as nonrelevant. nDCG uses a common judged pool for each question, not exhaustive labels for all 500 posts. Candidate coverage refers only to known relevant pooled posts and is not corpus-wide recall.
Questions with no grade-2-or-3 post in the pool: 10, 37. This does not prove there are no relevant posts elsewhere.
Without either the prior or current numerical adjudication corrections, the outcome is 22 longer-summary wins, 21 existing-summary wins, and 7 ties.

## Retrieval Diagnostic

The two versions shared an average of 72.7 of 100 candidates. Identical candidate sets: 0/50; identical ordered lists: 0/50.

## Timing and Cost

Timing covers authenticated HTTP submission through receipt of the full result JSON, including query embedding, retrieval and AI review. It excludes login and browser painting. Searches ran sequentially in seeded randomized order within each question. WordPress result caching was bypassed; OpenAI prompt-cache discounts are reported from actual usage.
100 accepted searches cost an estimated $1.3305. All search attempts cost $1.3305, including 0 extra paid phases. New full-post grading used 86 calls costing $0.6482. Total comparison cost: $1.9786. Earlier creation of the 500 test vectors cost $0.00192022; including that gives $1.9806. Summary writing used Codex, not a separate summary-generation API. Earlier grading costs are not charged again or included.
Rates checked October 17, 2026: embeddings $0.02/million input tokens; reviewer/grader $0.75/million input, $0.075/million cached input, $4.50/million output. Estimates use saved API usage; invoices are authoritative. [Embedding pricing](https://developers.openai.com/api/docs/models/text-embedding-3-small); [reviewer pricing](https://developers.openai.com/api/docs/models/gpt-5.4-mini).

## Groups

| Group | Existing P@5 | Longer P@5 | Existing ranking | Longer ranking |
|---|---:|---:|---:|---:|
| challenge | 36.0% | 36.0% | 52.7 | 57.7 |
| representative | 70.5% | 68.5% | 67.9 | 67.0 |

## Question Results

| # | Question | Existing ranking | Longer ranking | Result |
|---|---|---:|---:|---|
| 1 | How did an early Christian congregation receive and circulate a letter before there was a New Testament? | 39.3 | 19.8 | Existing |
| 2 | Find discussions of how changing audiences affected the way stories about Jesus were told. | 78.4 | 58.4 | Existing |
| 3 | What is the difference between saying a Gospel is anonymous and saying its author was unknown to its first readers? | 81.2 | 81.2 | Tie |
| 4 | How did early Christians explain what happened to righteous people who lived before Jesus? | 48.3 | 86.6 | Longer |
| 5 | What evidence helps distinguish a Christian interpolation from an original passage in a non-Christian source? | 73.4 | 71.0 | Tie |
| 6 | How much could a scribe change a biblical text while believing that the meaning was being preserved? | 83.6 | 71.4 | Existing |
| 7 | What can the language and style of a Greek text tell us about its author's education? | 75.7 | 81.9 | Longer |
| 8 | How did the expectation of God's judgment influence Jesus' instructions about forgiving other people? | 65.1 | 40.7 | Existing |
| 9 | Find posts about the difference between ancient biography and a modern biography when reading the Gospels. | 46.9 | 73.9 | Longer |
| 10 | How did converts maintain relationships with non-Christian relatives who continued to worship other gods? | 53.1 | 0.0 | Existing |
| 11 | What does the New Testament say about people who never heard the Christian message being judged by God? | 74.4 | 50.7 | Existing |
| 12 | How did biblical authors use stories about the past to address crises in their own time? | 60.0 | 61.8 | Tie |
| 13 | Why did some Christians value secret teaching while others emphasized a public tradition handed down by apostles? | 73.6 | 79.7 | Longer |
| 14 | What would count as independent confirmation of an event when several ancient sources report it? | 70.9 | 81.5 | Longer |
| 15 | How do historians evaluate memories of Jesus that were written down by people who never met him? | 82.1 | 79.6 | Tie |
| 16 | How did early Christians decide whether a new prophecy came from God or from a false prophet? | 46.6 | 82.6 | Longer |
| 17 | Find posts explaining whether early Christians expected their resurrection to be physical or merely spiritual. | 80.1 | 92.4 | Longer |
| 18 | How did Paul use Jewish scripture when arguing with gentiles who had not grown up reading it? | 46.9 | 43.7 | Existing |
| 19 | What changed when Christianity became a religion supported by emperors rather than a minority movement? | 89.0 | 78.2 | Existing |
| 20 | Why do scholars distinguish the date a manuscript was copied from the date its work was composed? | 69.7 | 69.7 | Tie |
| 21 | How did stories about martyrs shape Christian identity even for readers who were not facing persecution? | 76.1 | 83.1 | Longer |
| 22 | What were the consequences of translating Jesus' Aramaic teachings into Greek for later readers? | 80.6 | 89.9 | Longer |
| 23 | Find discussions of whether the Bible presents a consistent view of violence commanded by God. | 79.4 | 97.3 | Longer |
| 24 | How did early Christian leaders justify having authority over other believers? | 51.0 | 53.1 | Tie |
| 25 | What makes an interpretation of a parable historically plausible rather than just a useful modern lesson? | 55.7 | 54.4 | Tie |
| 26 | Why did Christians preserve four Gospels instead of replacing them with one combined account? | 88.5 | 77.0 | Existing |
| 27 | How did beliefs about divine justice change when people stopped expecting justice during their own lifetime? | 78.6 | 69.2 | Existing |
| 28 | What can the different ways Jesus prays in the Gospels tell us about how the authors understood him? | 78.7 | 87.4 | Longer |
| 29 | How did the discovery of previously lost Christian books change what scholars could say about early Christianity? | 71.1 | 59.0 | Existing |
| 30 | What evidence is used to distinguish a historical claim about Jesus from a later devotional legend? | 56.3 | 65.5 | Longer |
| 31 | Find posts about the relationship between baptism and forgiveness of sins in early Christianity. | 46.5 | 80.8 | Longer |
| 32 | How did Christian teaching about helping the poor differ from giving gifts to receive honor or a favor in return? | 70.8 | 59.9 | Existing |
| 33 | How do scholars decide whether two biblical passages contradict each other or describe different situations? | 59.7 | 50.0 | Existing |
| 34 | What role did illness and healing play in convincing ancient people to join a religious movement? | 79.7 | 93.1 | Longer |
| 35 | How can a historical approach to the Bible be useful to readers who remain religious? | 67.7 | 88.8 | Longer |
| 36 | What happens when a biblical translation has to choose between two possible meanings of the same Greek word? | 56.7 | 46.9 | Existing |
| 37 | How did Paul distinguish his own advice from a command he believed came from Jesus? | 63.7 | 39.0 | Existing |
| 38 | Find posts explaining how a scholar changes a long-held interpretation when new evidence appears. | 59.9 | 53.7 | Existing |
| 39 | How did ancient Christians describe the relationship between human choices and God's control of history? | 73.9 | 78.5 | Longer |
| 40 | What are the strengths and limits of using personal religious experience as historical evidence? | 83.2 | 48.1 | Existing |
| 41 | Why does Paul say that Christ appeared to five hundred people, and what can historians infer from that claim? | 29.1 | 43.3 | Longer |
| 42 | What does Jesus mean by calling himself lord of the Sabbath in the dispute over picking grain? | 35.6 | 68.6 | Longer |
| 43 | Why does Matthew have some of Jesus' followers doubt even when they see him after the resurrection? | 63.9 | 87.4 | Longer |
| 44 | What is the significance of Paul calling James the brother of the Lord? | 64.7 | 90.2 | Longer |
| 45 | How does the story of the sheep and goats in Matthew connect judgment with helping people in need? | 78.9 | 46.6 | Existing |
| 46 | Why does Jesus' statement that the Father is greater than he is create difficulties for later Christian theology? | 48.6 | 59.4 | Longer |
| 47 | What do competing accounts of Judas' death reveal about how traditions developed? | 61.6 | 37.0 | Existing |
| 48 | Why did Christians debate whether the Shepherd of Hermas should be treated as scripture? | 80.0 | 53.5 | Existing |
| 49 | What does Paul's reference to a thorn in the flesh reveal about weakness and apostolic authority? | 0.0 | 64.0 | Longer |
| 50 | How does Jesus' encounter with the Syrophoenician woman fit the Gospel's treatment of gentiles? | 65.0 | 27.3 | Existing |

## Preservation and Limits

All 112 originally frozen files and the additional comparison inputs retained their checksums. All 19 discovery tables retained their row counts and content fingerprints. Original summaries, vectors and production code were not replaced. This local endpoint is administrator-only and excluded from Render packaging. No commit, push, deployment, skill change or production ingestion change is part of the experiment.
The fixed sample includes deliberately difficult posts and one known stored opening excerpt (post 2359). Longer summaries have not been adopted in production; four exceed the current ingestion validator's 1,200-character limit. That validator is not used to truncate this experiment's reviewer inputs.
Before adoption, confirm any promising improvement on fresh questions and broader data with human review. Then compare agent-written versus API-written summaries on identical posts. Only after approval should the download skill and deployed WordPress ingestion guidance change.
