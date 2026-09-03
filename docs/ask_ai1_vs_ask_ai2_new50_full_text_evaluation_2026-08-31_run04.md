# Ask AI 1 vs. Ask AI 2: New 50-Question Full-Text Evaluation, Run 04

Date: August 31, 2026

## Method

Fifty new questions, with no exact duplicates in prior stored benchmark sets, were submitted to the current Ask AI 1 and Ask AI 2 implementations. Ask AI 1 converted each question into curated topics and secondary keywords, searched those assignments, and used AI refinement. Ask AI 2 used one title-and-summary semantic vector and the same AI-refinement stage.

The local AI search cache was invalidated separately before each method. For every question, the union of both methods' first ten results was evaluated from complete local post text. The grader did not receive method names or rank positions. Grades are `3` direct, `2` strong supporting, `1` marginal, and `0` irrelevant. Grades 2 and 3 count as relevant. An nDCG@10 difference of 0.03 or less is treated as a tie.

## Results

| Outcome | Questions |
|---|---:|
| Ask AI 1 better | 6 |
| Ask AI 2 better | 35 |
| Tied | 9 |

| Mean metric | Ask AI 1 | Ask AI 2 | Change |
|---|---:|---:|---:|
| Precision@5 | 66.5% | 75.6% | 9.1% |
| Precision@10 | 64.0% | 66.1% | 2.1% |
| Average grade@5 | 2.086 | 2.2927 | +0.207 |
| Average grade@10 | 2.0059 | 2.05 | +0.044 |
| Recall@10 within judged pool | 46.6% | 78.0% | 31.4% |
| nDCG@10 | 0.621 | 0.8933 | +0.272 |

Ask AI 1 returned an average of 4.86 posts and had 1 zero-result question. Ask AI 2 returned an average of 9.34 posts and had 0 zero-result questions. The methods shared a mean of 2.7 posts in their first ten results.

## Speed and Search Cost

| Measure | Ask AI 1 | Ask AI 2 |
|---|---:|---:|
| Mean response time | 5.741 seconds | 4.065 seconds |
| Median response time | 4.861 seconds | 3.655 seconds |
| 95th-percentile response time | 9.629 seconds | 7.077 seconds |
| Total API cost for 50 questions | $0.2800 | $0.5011 |
| Average API cost per question | 0.560 cents | 1.002 cents |

Search costs include each method's initial interpretation or embedding call plus its refinement call. The separate blinded grading expense is not part of end-user search cost.

This run also recorded 1 failed or retried API record costing $0.0031. That benchmark-only overhead is excluded from the successful per-question averages above.

## Assessment

Ask AI 2 was the stronger method in this run.

Ask AI 2 won 35 questions to 6, had 0 zero-result questions, changed pooled recall by +31.4 percentage points, and changed mean nDCG@10 by +0.2723 relative to Ask AI 1.

Ask AI 1 won 6 questions and its mean Precision@5 was -9.1 percentage points relative to Ask AI 2. Ask AI 2's average measured API cost was 1.79 times Ask AI 1's when the ratio is defined.

Ask AI 1 returned an average of 4.86 posts and had 1 zero-result question. Ask AI 2 returned an average of 9.34 posts and had 0 zero-result questions.

Ask AI 1 recorded 790,272 cached input tokens while Ask AI 2 recorded 0. Measured costs describe the current production-shaped prompts and observed cache behavior, not an equal-token laboratory comparison.

## Largest Ask AI 2 Improvements

- **Why did God protect Cain with a mark after Cain murdered Abel?**: nDCG 0.0 to 1.0 (+1.000).
- **Why does Jesus curse a fig tree for having no fruit when it was not the season for figs?**: nDCG 0.0 to 1.0 (+1.000).
- **Why do some modern Bible translations use gender-inclusive language when the Greek text uses masculine terms?**: nDCG 0.0 to 0.9668 (+0.967).
- **Are there really more New Testament textual variants than there are words in the New Testament?**: nDCG 0.073 to 0.971 (+0.898).
- **What evidence indicates that Matthew and Luke used Mark rather than Mark using them?**: nDCG 0.1466 to 0.9646 (+0.818).

## Largest Ask AI 2 Regressions

- **What did Jeremiah originally mean by a new covenant, and how did Christians later interpret it?**: nDCG 0.9084 to 0.637 (-0.271).
- **What did speaking in tongues mean in Paul's Corinthian churches?**: nDCG 0.9779 to 0.7674 (-0.210).
- **Why did Paul prefer celibacy while still allowing Christians to marry?**: nDCG 0.7135 to 0.507 (-0.206).
- **What evidence do historians have for the kingdoms of David and Solomon?**: nDCG 0.8553 to 0.7037 (-0.152).
- **How historically reliable is the account of Polycarp's arrest and execution?**: nDCG 0.9924 to 0.9298 (-0.063).

## Question Results

| # | Question | Winner | Ask AI 1 P@5 / nDCG | Ask AI 2 P@5 / nDCG | Overlap |
|---:|---|---|---|---|---:|
| 1 | Why did God protect Cain with a mark after Cain murdered Abel? | **Ask AI 2** | 0.0% / 0.0 | 0.0% / 1.0 | 0 |
| 2 | What is the point of the story in which God commands Abraham to sacrifice Isaac? | **Ask AI 2** | 33.3% / 0.6235 | 66.7% / 0.8811 | 1 |
| 3 | Why does Exodus sometimes say that Pharaoh hardened his heart and elsewhere that God hardened it? | **Ask AI 2** | 100.0% / 0.792 | 80.0% / 0.8481 | 3 |
| 4 | Why do the two biblical versions of the Ten Commandments give different reasons for keeping the Sabbath? | **Tie** | 20.0% / 0.7324 | 50.0% / 0.7089 | 2 |
| 5 | What evidence do historians have for the kingdoms of David and Solomon? | **Ask AI 1** | 100.0% / 0.8553 | 66.7% / 0.7037 | 1 |
| 6 | Why did Hebrew Bible prophets connect worship of God with justice for the poor and oppressed? | **Ask AI 2** | 100.0% / 0.3001 | 100.0% / 1.0 | 1 |
| 7 | What did Jeremiah originally mean by a new covenant, and how did Christians later interpret it? | **Ask AI 1** | 0.0% / 0.9084 | 0.0% / 0.637 | 3 |
| 8 | Is Daniel 12 the earliest clear biblical teaching about a future resurrection of the dead? | **Ask AI 2** | 100.0% / 0.4448 | 80.0% / 0.8829 | 1 |
| 9 | What was Sheol, and did ancient Israelites think people remained conscious there after death? | **Ask AI 2** | 100.0% / 0.6656 | 100.0% / 0.8226 | 4 |
| 10 | Does the divine council in Psalm 82 show that ancient Israelites believed in multiple gods? | **Ask AI 2** | 60.0% / 0.4031 | 80.0% / 0.8004 | 5 |
| 11 | Could the historical Jesus read and write, given literacy rates in first-century Galilee? | **Ask AI 2** | 100.0% / 0.4968 | 80.0% / 0.9719 | 2 |
| 12 | What archaeological and literary evidence shows that Nazareth existed during Jesus' lifetime? | **Ask AI 2** | 100.0% / 0.7639 | 100.0% / 0.9848 | 7 |
| 13 | How much of Jesus' message and ministry may have been shaped by John the Baptist? | **Ask AI 2** | 100.0% / 0.7594 | 80.0% / 0.9802 | 4 |
| 14 | Is there any credible historical evidence that Jesus was married? | **Ask AI 2** | 100.0% / 0.7588 | 100.0% / 1.0 | 3 |
| 15 | Did Jesus publicly claim to be the Messiah before his arrest? | **Tie** | 100.0% / 0.7985 | 100.0% / 0.8055 | 3 |
| 16 | Was Jesus advocating armed resistance against Rome, or was his message nonviolent? | **Ask AI 2** | 100.0% / 0.2536 | 100.0% / 0.9965 | 1 |
| 17 | How can scholars reconstruct the hypothetical Q source when no manuscript of it survives? | **Tie** | 100.0% / 0.9815 | 100.0% / 0.9614 | 7 |
| 18 | What evidence indicates that Matthew and Luke used Mark rather than Mark using them? | **Ask AI 2** | 33.3% / 0.1466 | 100.0% / 0.9646 | 0 |
| 19 | Did the Gospel of Matthew portray Jesus as abolishing Jewish law or intensifying its demands? | **Ask AI 2** | 100.0% / 0.4867 | 100.0% / 0.8801 | 1 |
| 20 | How does Luke give unusual attention to poor people, women, and social outsiders? | **Tie** | 80.0% / 0.7458 | 80.0% / 0.7402 | 2 |
| 21 | Does the Gospel of John have Jesus directly claim that he is God? | **Ask AI 2** | 75.0% / 0.6201 | 100.0% / 0.8887 | 2 |
| 22 | What is the significance of Jesus' transfiguration in the Gospel narratives? | **Ask AI 2** | 20.0% / 0.473 | 66.7% / 0.7059 | 1 |
| 23 | Why are the versions of the Lord's Prayer in Matthew and Luke different? | **Ask AI 2** | 50.0% / 0.3432 | 60.0% / 0.7976 | 1 |
| 24 | Why does Jesus curse a fig tree for having no fruit when it was not the season for figs? | **Ask AI 2** | 0.0% / 0.0 | 50.0% / 1.0 | 0 |
| 25 | What does the tearing of the Temple curtain at Jesus' death mean in the different Gospels? | **Ask AI 2** | 100.0% / 0.4722 | 100.0% / 0.9346 | 2 |
| 26 | Why did Paul persecute followers of Jesus before becoming one himself? | **Ask AI 2** | 50.0% / 0.5705 | 80.0% / 0.9888 | 4 |
| 27 | Can the chronology of Paul's missionary journeys in Acts be reconciled with his own letters? | **Ask AI 2** | 100.0% / 0.4273 | 80.0% / 0.8146 | 0 |
| 28 | Is Paul describing his own experience in Romans 7 when he says he cannot do the good he wants? | **Ask AI 2** | 0.0% / 0.8319 | 0.0% / 1.0 | 3 |
| 29 | Did Paul intend Romans 13 to require obedience to every government? | **Ask AI 2** | 0.0% / 0.8319 | 0.0% / 1.0 | 3 |
| 30 | Why did Paul require women in Corinth to cover their heads while praying or prophesying? | **Ask AI 2** | 40.0% / 0.3676 | 100.0% / 0.8989 | 1 |
| 31 | What did speaking in tongues mean in Paul's Corinthian churches? | **Ask AI 1** | 60.0% / 0.9779 | 50.0% / 0.7674 | 3 |
| 32 | Did Paul's statement that there is no male and female in Christ imply social equality in the church? | **Tie** | 80.0% / 0.9527 | 80.0% / 0.9727 | 7 |
| 33 | Why did Paul prefer celibacy while still allowing Christians to marry? | **Ask AI 1** | 100.0% / 0.7135 | 75.0% / 0.507 | 0 |
| 34 | Did Paul think Jesus was divine before Jesus' resurrection? | **Ask AI 2** | 33.3% / 0.2877 | 100.0% / 1.0 | 1 |
| 35 | How does Hebrews explain Jesus' death through the imagery of sacrifice and high priesthood? | **Ask AI 2** | 50.0% / 0.9385 | 33.3% / 1.0 | 2 |
| 36 | Why did the claim that a first-century fragment of Mark had been discovered become controversial? | **Tie** | 100.0% / 0.9258 | 100.0% / 0.9225 | 7 |
| 37 | Are there really more New Testament textual variants than there are words in the New Testament? | **Ask AI 2** | 0.0% / 0.073 | 100.0% / 0.971 | 0 |
| 38 | What is the earliest surviving manuscript of the New Testament, and how confidently can it be dated? | **Ask AI 2** | 60.0% / 0.5909 | 60.0% / 0.6483 | 1 |
| 39 | Why do some modern Bible translations use gender-inclusive language when the Greek text uses masculine terms? | **Ask AI 2** | 0.0% / 0.0 | 100.0% / 0.9668 | 1 |
| 40 | Why do modern Bibles omit or bracket verses that appear in the King James Version? | **Ask AI 2** | 100.0% / 0.8007 | 100.0% / 0.8805 | 2 |
| 41 | What kinds of textual and translation changes distinguish the updated NRSV from earlier editions? | **Ask AI 2** | 40.0% / 0.4626 | 20.0% / 0.8005 | 3 |
| 42 | What does the Muratorian Fragment reveal about which Christian books were accepted or disputed? | **Ask AI 2** | 100.0% / 0.3623 | 80.0% / 1.0 | 1 |
| 43 | What does the Didache reveal about early Christian worship, ethics, and church organization? | **Tie** | 100.0% / 0.9751 | 100.0% / 1.0 | 7 |
| 44 | Why did the church in Rome write 1 Clement to Christians in Corinth? | **Tie** | 60.0% / 0.9224 | 80.0% / 0.9484 | 3 |
| 45 | What do the letters of Ignatius reveal about bishops, heresy, and martyrdom in the early church? | **Ask AI 1** | 80.0% / 1.0 | 100.0% / 0.9402 | 4 |
| 46 | How historically reliable is the account of Polycarp's arrest and execution? | **Ask AI 1** | 100.0% / 0.9924 | 100.0% / 0.9298 | 9 |
| 47 | Why did the Montanist movement give prominent prophetic roles to women? | **Tie** | 0.0% / 1.0 | 0.0% / 1.0 | 3 |
| 48 | Why do many scholars avoid treating Gnosticism as a single unified religion? | **Ask AI 2** | 100.0% / 0.7712 | 100.0% / 0.8544 | 4 |
| 49 | How does Bart distinguish being an agnostic from being an atheist? | **Ask AI 2** | 100.0% / 0.8014 | 100.0% / 0.9955 | 6 |
| 50 | Why does Bart argue that Revelation should not be read as a prediction of current world events? | **Ask AI 2** | 100.0% / 0.6489 | 100.0% / 0.9603 | 3 |
