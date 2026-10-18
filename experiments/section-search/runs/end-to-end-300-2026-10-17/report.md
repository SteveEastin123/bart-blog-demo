# Ask AI 2 vs. 300-Word Section Search

Run: October 17, 2026. Local experiment only.

## Design

50 fresh questions, frozen before either approach ran, on the same 500-post pilot sample. Forty broad/representative-style questions and ten specific challenge questions were authored without consulting sample post contents or search results. They are new questions, not an independent human-authored or representative-user benchmark; subject areas overlap earlier tests.
Both arms use the existing text-embedding-3-small, 512-dimensional vectors and 100 unique candidate posts. No post vectors were regenerated or overwritten. Ask AI 2 uses the existing hybrid title/summary algorithm and unchanged production reviewer prompt. The section arm supplements retrieval with the existing 300-word sections, then reviews titles, summaries, and each candidate's best passage using the previously frozen evidence-aware prompt.
Reviewer model, reasoning, output limit, tier schema and broader-result fallback are identical. This is an end-to-end comparison of two approaches: retrieval, evidence, and reviewer instructions differ. It cannot attribute a gain solely to chunk retrieval or passage evidence.

## Results

| Measure | Ask AI 2 | 300-word sections + passages |
|---|---:|---:|
| Top-five relevance (P@5) | 64.4% | 67.6% |
| Top-ten relevance (P@10) | 38.6% | 43.4% |
| Ranking score (nDCG@10 x 100) | 61.3 | 67.9 |
| Relevant posts in top ten | 3.86 | 4.34 |
| Posts returned in top ten | 5.34 | 5.82 |
| Relevance among displayed top ten | 66.6% | 70.4% |
| Mean full response time (seconds) | 2.74 | 2.78 |
| Median full response time (seconds) | 2.57 | 2.66 |
| 95th-percentile response time (seconds) | 4.24 | 3.99 |
| Total cost (cents/question) | 1.019 | 3.916 |
| Embedding cost (cents/question) | 0.000035 | 0.000035 |
| Review cost (cents/question) | 1.019 | 3.916 |
| Cost without provider cache discount (cents) | 1.019 | 3.916 |
| Review input tokens | 11802 | 50703 |
| Provider-cached input tokens | 0 | 0 |
| Broader-result fallback | 0.0% | 0.0% |
| Pre-review candidate P@10 | 32.6% | 37.8% |
| Coverage of known relevant pooled posts in 100 candidates | 91.8% | 95.8% |

Section wins: **28**; Ask AI 2 wins: **14**; ties: **8**. A tie means an absolute nDCG difference at most 0.03.
Mean section-minus-baseline nDCG difference: +0.0657; paired 95% bootstrap interval [+0.0162, +0.1179].

## Assessment and Next Step

The 300-word section approach shows enough promise in this pilot to justify the next controlled embedding experiment, but not an immediate production replacement. The improvement is larger for the ten specific challenge questions than for the forty broader questions. The direction of the ranking advantage remains the same before and after spot-check corrections.
Keep Ask AI 2 and all existing small/512 vectors unchanged. In the next experiment, use the same 300-word sections and hold retrieval fusion, candidate cap, passage selection, reviewer prompt and model fixed while comparing embedding configurations. First isolate the dimension change (small/512 versus small/1536); compare small/1536 with large/1536 separately to isolate model choice. Preserve separate indexes and use a compatible query embedding for each. These next steps have not been executed by this run.
Operating cost is the main tradeoff: reviewing a matching passage for every candidate increases review input from about 11,800 to 50,700 tokens, while mean response time barely changes. Larger embedding dimensions alone would not remove that passage-review cost. A separate later experiment could test selective passage use; do not change it simultaneously with embeddings. Confirm the chosen approach on broader data and with human review before production adoption.

## Costs and Timing

Time is measured from authenticated HTTP submission through complete result receipt, including query embedding, candidate retrieval and AI review. It excludes browser paint. Calls ran sequentially with seeded randomized arm order for each question. No WordPress result cache was used; provider-cached token counts are reported separately.
Accepted searches: 100 (100 embedding and 100 review calls), estimated cost $2.4678. Extra paid phases outside accepted searches: 0. All search attempts: $2.4678. Independent full-post grading: 286 calls, $2.4666. Total new experiment: $4.9344. Historical vector generation and earlier experiments are excluded.
Prices use actual returned token usage and the rates recorded in the manifest, not invoice totals: small embeddings $0.02/million input; gpt-5.4-mini $0.75/million input, $0.075/million cached input, $4.50/million output. See [embedding pricing](https://developers.openai.com/api/docs/models/text-embedding-3-small) and [reviewer pricing](https://developers.openai.com/api/docs/models/gpt-5.4-mini).

## Grading and Retrieval

886 question/post judgments covering 327 distinct posts. For each question, both final top tens and both pre-review candidate top tens are pooled and deduplicated. Complete stored post text is graded without method names or ranks. Grades: 0 irrelevant, 1 marginal, 2 useful/substantial, 3 directly answering. Supporting quotations are verified against the source. All grades are new for these questions.
P@5 and P@10 have fixed denominators of five and ten; unfilled slots count as nonrelevant. nDCG uses the shared judged pool, not exhaustive relevance labels for all 500 posts. Candidate coverage is coverage of known relevant pooled posts, NOT true corpus-wide recall. Judging the candidate top tens offers a retrieval diagnostic but does not completely measure all 100 candidates.
Questions with no grade-2-or-3 post in the judged pool: 2 (10, 37). This does not establish that no relevant post exists elsewhere in the blog or unjudged sample. nDCG gives partial credit for marginal grade-1 material; P@5/P@10 do not.
Mean shared candidates: 85.7/100; identical sets: 0/50; identical ordered lists: 0/50.
Agent full-text spot checks: 29; grade corrections: 7. Original judgments remain in private files; corrections apply to both arms. These spot checks are not blind to the outcomes.
Separately documented quotation repairs: 3. These retain the grader's numerical score and preserve the original response; each replacement was checked against the full source post.
Before grade corrections: section wins 28, Ask AI 2 wins 16, ties 6; mean nDCG difference +0.0624.

## Question Groups

| Group | Ask AI 2 P@5 | Sections P@5 | Ask AI 2 nDCG | Sections nDCG |
|---|---:|---:|---:|---:|
| representative | 72.5% | 74.0% | 0.6520 | 0.6948 |
| challenge | 32.0% | 42.0% | 0.4567 | 0.6145 |

## Full-Text Examples

These cases illustrate mechanisms and tradeoffs; they were selected after viewing results and are not a separate unbiased sample.
- Question 11, judgment of people who never heard the Christian message: [The Accuracy of Acts: Part 2](https://ehrmanblog.org/the-accuracy-of-acts-part-2/) (4777) was absent from the baseline's 100 candidates, but section retrieval placed it 36th and passage review returned it first. Its full text directly contrasts Acts 17 with Romans 1 on pagan ignorance and accountability.
- Question 17, physical versus spiritual resurrection: [1 Corinthians in a Nutshell](https://ehrmanblog.org/1-corinthians-in-a-nutshell/) (47152) was candidate 74 for the baseline and not selected. Sections moved it to candidate 24 and final rank 2. A substantial final section directly answers the question. Here both candidate order and reviewer evidence/instructions differ; this does not isolate one cause.
- Question 18, Paul's use of Jewish scripture with gentiles: [Salvation, Love, and the Jewish Law in Paul](https://ehrmanblog.org/salvation-love-and-the-jewish-law-in-paul-are-his-views-internally-coherent/) (40161) was candidate 2/final rank 2 for the baseline, but candidate 1/not selected for sections. Full-text review retained grade 2 for useful covenant-and-law background. This is a reviewer-selection loss, not a retrieval miss.

## Question-Level Results

Ranking scores below are nDCG@10 multiplied by 100. The winner uses the prespecified three-point tie tolerance, not displayed rounding.

| # | Question | Ask AI 2 | Sections | Result |
|---|---|---:|---:|---|
| 1 | How did an early Christian congregation receive and circulate a letter before there was a New Testament? | 35.5 | 35.5 | Tie |
| 2 | Find discussions of how changing audiences affected the way stories about Jesus were told. | 74.0 | 73.4 | Tie |
| 3 | What is the difference between saying a Gospel is anonymous and saying its author was unknown to its first readers? | 82.6 | 96.2 | Sections |
| 4 | How did early Christians explain what happened to righteous people who lived before Jesus? | 74.0 | 93.6 | Sections |
| 5 | What evidence helps distinguish a Christian interpolation from an original passage in a non-Christian source? | 61.7 | 85.7 | Sections |
| 6 | How much could a scribe change a biblical text while believing that the meaning was being preserved? | 79.2 | 80.8 | Tie |
| 7 | What can the language and style of a Greek text tell us about its author's education? | 88.4 | 75.3 | Ask AI 2 |
| 8 | How did the expectation of God's judgment influence Jesus' instructions about forgiving other people? | 37.3 | 58.4 | Sections |
| 9 | Find posts about the difference between ancient biography and a modern biography when reading the Gospels. | 65.2 | 63.7 | Tie |
| 10 | How did converts maintain relationships with non-Christian relatives who continued to worship other gods? | 0.0 | 0.0 | Tie |
| 11 | What does the New Testament say about people who never heard the Christian message being judged by God? | 50.8 | 68.7 | Sections |
| 12 | How did biblical authors use stories about the past to address crises in their own time? | 73.6 | 64.4 | Ask AI 2 |
| 13 | Why did some Christians value secret teaching while others emphasized a public tradition handed down by apostles? | 61.0 | 84.8 | Sections |
| 14 | What would count as independent confirmation of an event when several ancient sources report it? | 84.4 | 88.6 | Sections |
| 15 | How do historians evaluate memories of Jesus that were written down by people who never met him? | 76.3 | 90.1 | Sections |
| 16 | How did early Christians decide whether a new prophecy came from God or from a false prophet? | 46.6 | 78.8 | Sections |
| 17 | Find posts explaining whether early Christians expected their resurrection to be physical or merely spiritual. | 56.4 | 89.5 | Sections |
| 18 | How did Paul use Jewish scripture when arguing with gentiles who had not grown up reading it? | 66.2 | 44.1 | Ask AI 2 |
| 19 | What changed when Christianity became a religion supported by emperors rather than a minority movement? | 76.4 | 86.8 | Sections |
| 20 | Why do scholars distinguish the date a manuscript was copied from the date its work was composed? | 68.3 | 66.5 | Tie |
| 21 | How did stories about martyrs shape Christian identity even for readers who were not facing persecution? | 61.6 | 78.3 | Sections |
| 22 | What were the consequences of translating Jesus' Aramaic teachings into Greek for later readers? | 71.6 | 86.0 | Sections |
| 23 | Find discussions of whether the Bible presents a consistent view of violence commanded by God. | 77.1 | 61.8 | Ask AI 2 |
| 24 | How did early Christian leaders justify having authority over other believers? | 66.1 | 53.4 | Ask AI 2 |
| 25 | What makes an interpretation of a parable historically plausible rather than just a useful modern lesson? | 48.2 | 67.5 | Sections |
| 26 | Why did Christians preserve four Gospels instead of replacing them with one combined account? | 86.8 | 81.7 | Ask AI 2 |
| 27 | How did beliefs about divine justice change when people stopped expecting justice during their own lifetime? | 82.9 | 73.3 | Ask AI 2 |
| 28 | What can the different ways Jesus prays in the Gospels tell us about how the authors understood him? | 84.5 | 94.1 | Sections |
| 29 | How did the discovery of previously lost Christian books change what scholars could say about early Christianity? | 68.5 | 75.6 | Sections |
| 30 | What evidence is used to distinguish a historical claim about Jesus from a later devotional legend? | 69.9 | 82.2 | Sections |
| 31 | Find posts about the relationship between baptism and forgiveness of sins in early Christianity. | 64.4 | 60.2 | Ask AI 2 |
| 32 | How did Christian teaching about helping the poor differ from giving gifts to receive honor or a favor in return? | 44.8 | 36.2 | Ask AI 2 |
| 33 | How do scholars decide whether two biblical passages contradict each other or describe different situations? | 57.2 | 62.6 | Sections |
| 34 | What role did illness and healing play in convincing ancient people to join a religious movement? | 92.3 | 72.4 | Ask AI 2 |
| 35 | How can a historical approach to the Bible be useful to readers who remain religious? | 69.0 | 83.8 | Sections |
| 36 | What happens when a biblical translation has to choose between two possible meanings of the same Greek word? | 45.0 | 64.5 | Sections |
| 37 | How did Paul distinguish his own advice from a command he believed came from Jesus? | 63.7 | 24.6 | Ask AI 2 |
| 38 | Find posts explaining how a scholar changes a long-held interpretation when new evidence appears. | 62.2 | 59.4 | Tie |
| 39 | How did ancient Christians describe the relationship between human choices and God's control of history? | 59.9 | 82.8 | Sections |
| 40 | What are the strengths and limits of using personal religious experience as historical evidence? | 74.6 | 53.6 | Ask AI 2 |
| 41 | Why does Paul say that Christ appeared to five hundred people, and what can historians infer from that claim? | 31.2 | 25.9 | Ask AI 2 |
| 42 | What does Jesus mean by calling himself lord of the Sabbath in the dispute over picking grain? | 32.4 | 83.8 | Sections |
| 43 | Why does Matthew have some of Jesus' followers doubt even when they see him after the resurrection? | 60.6 | 88.8 | Sections |
| 44 | What is the significance of Paul calling James the brother of the Lord? | 44.0 | 62.6 | Sections |
| 45 | How does the story of the sheep and goats in Matthew connect judgment with helping people in need? | 84.9 | 89.9 | Sections |
| 46 | Why does Jesus' statement that the Father is greater than he is create difficulties for later Christian theology? | 44.3 | 44.9 | Tie |
| 47 | What do competing accounts of Judas' death reveal about how traditions developed? | 71.6 | 51.9 | Ask AI 2 |
| 48 | Why did Christians debate whether the Shepherd of Hermas should be treated as scripture? | 73.2 | 76.3 | Sections |
| 49 | What does Paul's reference to a thorn in the flesh reveal about weakness and apostolic authority? | 0.0 | 64.0 | Sections |
| 50 | How does Jesus' encounter with the Syrophoenician woman fit the Gospel's treatment of gentiles? | 14.5 | 26.4 | Sections |

## Limits and Preservation

- AI relevance judgments are not a human gold standard. Bootstrap intervals measure question-sampling uncertainty, not grader error or repeated-review variability.
- The sample includes 100 deliberately selected challenge posts. These results do not establish performance on the full blog, and the baseline here is Ask AI 2 restricted to the same 500 posts.
- Earlier test scores use different questions and judgment pools and should not be numerically pooled with this run.
- 74 original source/data files and 19 discovery database tables passed unchanged-content checks, including the 4,016 production vectors. Results, grading evidence, and raw API audits are retained separately. No production model switch, commit, push, or Render deployment is part of this test.

## Verification

- 277 new mocked comparison checks, 438 original pilot checks, and 5,209 evidence-replay checks passed with no paid calls.
- Python unit tests, WordPress Core PHPCS, and PHPStan level 5 passed for the experiment.
- The existing local WordPress acceptance suite passed, including 77 PHP syntax checks, canonical data counts, semantic coverage, ingestion, analytics, public pages, privacy, AND-search, and pagination.
