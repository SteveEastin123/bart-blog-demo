# Ask AI 1 vs. Ask AI 2: New 50-Question Full-Text Evaluation, Run 02

Date: August 31, 2026

## Method

Fifty new questions, with no exact duplicates in prior stored benchmark sets, were submitted to the current Ask AI 1 and Ask AI 2 implementations. Ask AI 1 converted each question into curated topics and secondary keywords, searched those assignments, and used AI refinement. Ask AI 2 used one title-and-summary semantic vector and the same AI-refinement stage.

The local AI search cache was invalidated separately before each method. For every question, the union of both methods' first ten results was evaluated from complete local post text. The grader did not receive method names or rank positions. Grades are `3` direct, `2` strong supporting, `1` marginal, and `0` irrelevant. Grades 2 and 3 count as relevant. An nDCG@10 difference of 0.03 or less is treated as a tie.

## Results

| Outcome | Questions |
|---|---:|
| Ask AI 1 better | 10 |
| Ask AI 2 better | 35 |
| Tied | 5 |

| Mean metric | Ask AI 1 | Ask AI 2 | Change |
|---|---:|---:|---:|
| Precision@5 | 82.2% | 87.3% | 5.1% |
| Precision@10 | 80.4% | 79.4% | -0.9% |
| Average grade@5 | 2.403 | 2.5787 | +0.176 |
| Average grade@10 | 2.3486 | 2.3528 | +0.004 |
| Recall@10 within judged pool | 54.2% | 86.8% | 32.6% |
| nDCG@10 | 0.6612 | 0.9089 | +0.248 |

Ask AI 1 returned an average of 5.88 posts and had 0 zero-result questions. Ask AI 2 returned an average of 11.46 posts and had 0 zero-result questions. The methods shared a mean of 3.5 posts in their first ten results.

## Speed and Search Cost

| Measure | Ask AI 1 | Ask AI 2 |
|---|---:|---:|
| Mean response time | 4.740 seconds | 3.221 seconds |
| Median response time | 3.809 seconds | 2.937 seconds |
| 95th-percentile response time | 6.771 seconds | 5.128 seconds |
| Total API cost for 50 questions | $0.2798 | $0.5005 |
| Average API cost per question | 0.560 cents | 1.001 cents |

Search costs include each method's initial interpretation or embedding call plus its refinement call. The separate blinded grading expense is not part of end-user search cost.

This run also recorded 7 failed or retried API records costing $0.0187. That benchmark-only overhead is excluded from the successful per-question averages above.

## Assessment

Ask AI 2 was the stronger method in this run.

Ask AI 2 won 35 questions to 10, had 0 zero-result questions, changed pooled recall by +32.6 percentage points, and changed mean nDCG@10 by +0.2477 relative to Ask AI 1.

Ask AI 1 won 10 questions and its mean Precision@5 was -5.1 percentage points relative to Ask AI 2. Ask AI 2's average measured API cost was 1.79 times Ask AI 1's when the ratio is defined.

Ask AI 1 returned an average of 5.88 posts and had 0 zero-result questions. Ask AI 2 returned an average of 11.46 posts and had 0 zero-result questions.

Ask AI 1 recorded 774,144 cached input tokens while Ask AI 2 recorded 0. Measured costs describe the current production-shaped prompts and observed cache behavior, not an equal-token laboratory comparison.

## Largest Ask AI 2 Improvements

- **Why was Paul's collection of money for the Jerusalem believers so important to his mission?**: nDCG 0.0 to 1.0 (+1.000).
- **How did Satan change from a divine accuser in the Hebrew Bible into God's cosmic enemy?**: nDCG 0.0 to 0.9344 (+0.934).
- **How historically reliable are the surviving stories about early Christian martyrs?**: nDCG 0.0709 to 1.0 (+0.929).
- **Why do scholars disagree about whether the Secret Gospel of Mark is ancient or a modern forgery?**: nDCG 0.0559 to 0.9553 (+0.899).
- **How does Matthew change Mark's portrayal of Jesus during his arrest and trial?**: nDCG 0.1157 to 0.9747 (+0.859).

## Largest Ask AI 2 Regressions

- **How did Valentinian Christians explain creation, the human problem, and salvation?**: nDCG 0.8426 to 0.6381 (-0.204).
- **What do the Dead Sea Scrolls reveal about the text of the Hebrew Bible before the medieval manuscripts?**: nDCG 0.9018 to 0.7615 (-0.140).
- **Did Mark originally say that Jesus felt compassion or anger before healing the leper?**: nDCG 1.0 to 0.8688 (-0.131).
- **Was Jesus' entry into Jerusalem on a donkey intended as a public messianic claim?**: nDCG 0.8109 to 0.6871 (-0.124).
- **What social and historical factors help explain Christianity's growth throughout the Roman Empire?**: nDCG 0.8925 to 0.79 (-0.102).

## Question Results

| # | Question | Winner | Ask AI 1 P@5 / nDCG | Ask AI 2 P@5 / nDCG | Overlap |
|---:|---|---|---|---|---:|
| 1 | How do the two creation accounts in Genesis differ in their order of events and portrayal of God? | **Ask AI 2** | 100.0% / 0.7443 | 100.0% / 0.9254 | 4 |
| 2 | What evidence do scholars use to identify different literary sources within the Pentateuch? | **Ask AI 2** | 100.0% / 0.9432 | 100.0% / 1.0 | 5 |
| 3 | Is there historical or archaeological evidence that a large group of Israelites escaped from Egypt? | **Ask AI 2** | 80.0% / 0.8984 | 80.0% / 0.977 | 4 |
| 4 | What does archaeology suggest about the biblical account of Israel's conquest of Canaan? | **Ask AI 2** | 100.0% / 0.6994 | 100.0% / 1.0 | 3 |
| 5 | Did the earliest Israelites deny that other gods existed, or did exclusive monotheism develop later? | **Tie** | 100.0% / 0.9239 | 100.0% / 0.9116 | 5 |
| 6 | How did Satan change from a divine accuser in the Hebrew Bible into God's cosmic enemy? | **Ask AI 2** | 0.0% / 0.0 | 60.0% / 0.9344 | 0 |
| 7 | Why do critical scholars conclude that the book of Isaiah contains writings from more than one period? | **Ask AI 2** | 100.0% / 0.4693 | 100.0% / 1.0 | 1 |
| 8 | What do the Dead Sea Scrolls reveal about the text of the Hebrew Bible before the medieval manuscripts? | **Ask AI 1** | 100.0% / 0.9018 | 80.0% / 0.7615 | 7 |
| 9 | Why do Catholic, Orthodox, and Protestant Bibles contain different collections of Old Testament books? | **Ask AI 2** | 100.0% / 0.1606 | 80.0% / 0.9914 | 1 |
| 10 | Why does Ecclesiastes sound skeptical about divine justice and life after death? | **Ask AI 2** | 100.0% / 0.8932 | 100.0% / 1.0 | 7 |
| 11 | What can historians reasonably know about Jesus' parents, siblings, education, and childhood? | **Ask AI 2** | 100.0% / 0.462 | 80.0% / 0.9607 | 1 |
| 12 | What normally happened to the bodies of people crucified by Roman authorities? | **Ask AI 2** | 100.0% / 0.6732 | 100.0% / 0.9621 | 4 |
| 13 | Was Jesus' entry into Jerusalem on a donkey intended as a public messianic claim? | **Ask AI 1** | 80.0% / 0.8109 | 80.0% / 0.6871 | 6 |
| 14 | When Jesus spoke about the Son of Man, was he referring to himself or to a future heavenly figure? | **Ask AI 1** | 100.0% / 1.0 | 100.0% / 0.9225 | 7 |
| 15 | Did Jesus expect all of his followers to give away their possessions? | **Ask AI 2** | 100.0% / 0.8442 | 100.0% / 0.921 | 5 |
| 16 | Why does Mark describe Jesus healing a blind man in two stages rather than immediately? | **Tie** | 100.0% / 1.0 | 100.0% / 1.0 | 3 |
| 17 | Was Jesus' disruption in the Temple probably the event that led Roman authorities to execute him? | **Ask AI 2** | 100.0% / 0.4781 | 100.0% / 0.6353 | 0 |
| 18 | Does Luke deliberately remove Mark's portrayal of Jesus' death as a ransom or atoning sacrifice? | **Ask AI 1** | 100.0% / 1.0 | 100.0% / 0.9225 | 6 |
| 19 | How does Matthew change Mark's portrayal of Jesus during his arrest and trial? | **Ask AI 2** | 0.0% / 0.1157 | 60.0% / 0.9747 | 1 |
| 20 | Why does John place Jesus' death before the Passover meal rather than afterward? | **Ask AI 2** | 75.0% / 0.8074 | 60.0% / 0.8465 | 3 |
| 21 | Why did Paul travel to Arabia after his conversion, and what might he have done there? | **Ask AI 2** | 50.0% / 0.7474 | 40.0% / 0.951 | 1 |
| 22 | Why was Paul's collection of money for the Jerusalem believers so important to his mission? | **Ask AI 2** | 0.0% / 0.0 | 100.0% / 1.0 | 0 |
| 23 | What do the abuses at the Corinthian communal meal reveal about wealth and status in the church? | **Ask AI 2** | 100.0% / 0.7911 | 100.0% / 0.9586 | 4 |
| 24 | Why did Paul strongly oppose requiring gentile converts to be circumcised? | **Ask AI 2** | 100.0% / 0.5406 | 100.0% / 0.9382 | 2 |
| 25 | Who were Paul's opponents in Galatia, and what were they asking his converts to do? | **Ask AI 2** | 100.0% / 0.6641 | 100.0% / 0.994 | 4 |
| 26 | Why do scholars think that 2 Corinthians may combine portions of several different letters? | **Ask AI 2** | 100.0% / 0.8469 | 100.0% / 1.0 | 7 |
| 27 | What does Paul's recommendation of Phoebe tell us about her authority and role in the Roman church? | **Tie** | 50.0% / 0.847 | 20.0% / 0.864 | 4 |
| 28 | What differences from 1 Thessalonians lead some scholars to think Paul did not write 2 Thessalonians? | **Ask AI 2** | 100.0% / 0.8133 | 100.0% / 0.891 | 9 |
| 29 | Would ancient readers have considered a letter falsely written in Paul's name acceptable or deceptive? | **Ask AI 2** | 80.0% / 0.516 | 100.0% / 0.8885 | 1 |
| 30 | How much did Paul actually know about the earthly life and teachings of Jesus? | **Tie** | 100.0% / 0.9777 | 100.0% / 0.9865 | 9 |
| 31 | How can textual critics reconstruct the New Testament when none of the original manuscripts survive? | **Ask AI 2** | 100.0% / 0.6338 | 100.0% / 0.8883 | 1 |
| 32 | Did Mark originally say that Jesus felt compassion or anger before healing the leper? | **Ask AI 1** | 100.0% / 1.0 | 100.0% / 0.8688 | 4 |
| 33 | How can repeated storytelling reshape people's memories of what Jesus said and did? | **Ask AI 2** | 100.0% / 0.469 | 100.0% / 0.9636 | 2 |
| 34 | What did Papias claim about Mark and Matthew, and how reliable is his testimony? | **Tie** | 100.0% / 0.8925 | 100.0% / 0.9142 | 7 |
| 35 | When and why were the traditional names Matthew, Mark, Luke, and John attached to the Gospels? | **Ask AI 1** | 100.0% / 0.96 | 100.0% / 0.8678 | 7 |
| 36 | Could the use of a secretary explain why letters attributed to Peter were written in polished Greek? | **Ask AI 2** | 100.0% / 0.5678 | 100.0% / 0.998 | 3 |
| 37 | Did ancient authors and readers condemn writings that falsely claimed to be written by famous people? | **Ask AI 1** | 100.0% / 0.9513 | 100.0% / 0.9133 | 4 |
| 38 | Why do scholars disagree about whether the Secret Gospel of Mark is ancient or a modern forgery? | **Ask AI 2** | 0.0% / 0.0559 | 80.0% / 0.9553 | 1 |
| 39 | What punishments and rewards does the Apocalypse of Peter describe for the afterlife? | **Ask AI 2** | 100.0% / 0.6217 | 80.0% / 0.8258 | 2 |
| 40 | Why did some early Christians value the Shepherd of Hermas highly enough to treat it almost as scripture? | **Ask AI 2** | 0.0% / 0.0 | 40.0% / 0.7885 | 0 |
| 41 | What does the Gospel of the Ebionites reveal about Jewish-Christian beliefs and practices? | **Ask AI 2** | 100.0% / 0.9503 | 80.0% / 1.0 | 4 |
| 42 | How did Valentinian Christians explain creation, the human problem, and salvation? | **Ask AI 1** | 60.0% / 0.8426 | 66.7% / 0.6381 | 2 |
| 43 | How and when did Christians begin treating the Holy Spirit as fully divine within the Trinity? | **Ask AI 1** | 80.0% / 0.9316 | 60.0% / 0.8969 | 7 |
| 44 | What social and historical factors help explain Christianity's growth throughout the Roman Empire? | **Ask AI 1** | 80.0% / 0.8925 | 80.0% / 0.79 | 4 |
| 45 | How historically reliable are the surviving stories about early Christian martyrs? | **Ask AI 2** | 0.0% / 0.0709 | 100.0% / 1.0 | 1 |
| 46 | What leadership and ministry roles did women hold in the earliest Christian communities? | **Ask AI 2** | 100.0% / 0.6712 | 100.0% / 0.9758 | 5 |
| 47 | How did Jewish belief in bodily resurrection differ from Greek belief in an immortal soul? | **Ask AI 2** | 100.0% / 0.6003 | 100.0% / 0.7687 | 0 |
| 48 | How did Bart's years at Moody Bible Institute and Wheaton shape his early understanding of biblical inerrancy? | **Ask AI 2** | 100.0% / 0.198 | 40.0% / 0.7054 | 0 |
| 49 | How does Bart turn specialized biblical scholarship into books intended for nonspecialist readers? | **Ask AI 2** | 75.0% / 0.4495 | 100.0% / 0.9496 | 3 |
| 50 | How did early Christians understand wealth, almsgiving, and responsibility toward the poor? | **Ask AI 2** | 100.0% / 0.7323 | 100.0% / 0.9322 | 4 |
