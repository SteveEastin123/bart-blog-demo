# Ask AI 1 vs. Ask AI 2: New 50-Question Full-Text Evaluation, Run 03

Date: August 31, 2026

## Method

Fifty new questions, with no exact duplicates in prior stored benchmark sets, were submitted to the current Ask AI 1 and Ask AI 2 implementations. Ask AI 1 converted each question into curated topics and secondary keywords, searched those assignments, and used AI refinement. Ask AI 2 used one title-and-summary semantic vector and the same AI-refinement stage.

The local AI search cache was invalidated separately before each method. For every question, the union of both methods' first ten results was evaluated from complete local post text. The grader did not receive method names or rank positions. Grades are `3` direct, `2` strong supporting, `1` marginal, and `0` irrelevant. Grades 2 and 3 count as relevant. An nDCG@10 difference of 0.03 or less is treated as a tie.

## Results

| Outcome | Questions |
|---|---:|
| Ask AI 1 better | 9 |
| Ask AI 2 better | 31 |
| Tied | 10 |

| Mean metric | Ask AI 1 | Ask AI 2 | Change |
|---|---:|---:|---:|
| Precision@5 | 88.9% | 88.1% | -0.8% |
| Precision@10 | 85.5% | 81.2% | -4.2% |
| Average grade@5 | 2.579 | 2.607 | +0.028 |
| Average grade@10 | 2.4625 | 2.4239 | -0.039 |
| Recall@10 within judged pool | 60.7% | 79.5% | 18.8% |
| nDCG@10 | 0.719 | 0.8902 | +0.171 |

Ask AI 1 returned an average of 7 posts and had 1 zero-result question. Ask AI 2 returned an average of 10.72 posts and had 0 zero-result questions. The methods shared a mean of 3.62 posts in their first ten results.

## Speed and Search Cost

| Measure | Ask AI 1 | Ask AI 2 |
|---|---:|---:|
| Mean response time | 5.023 seconds | 4.340 seconds |
| Median response time | 4.556 seconds | 3.889 seconds |
| 95th-percentile response time | 7.494 seconds | 7.582 seconds |
| Total API cost for 50 questions | $0.2680 | $0.5026 |
| Average API cost per question | 0.536 cents | 1.005 cents |

Search costs include each method's initial interpretation or embedding call plus its refinement call. The separate blinded grading expense is not part of end-user search cost.

## Assessment

Ask AI 2 was the stronger method in this run.

Ask AI 2 won 31 questions to 9, had 0 zero-result questions, changed pooled recall by +18.8 percentage points, and changed mean nDCG@10 by +0.1712 relative to Ask AI 1.

Ask AI 1 won 9 questions and its mean Precision@5 was +0.8 percentage points relative to Ask AI 2. Ask AI 2's average measured API cost was 1.88 times Ask AI 1's when the ratio is defined.

Ask AI 1 returned an average of 7 posts and had 1 zero-result question. Ask AI 2 returned an average of 10.72 posts and had 0 zero-result questions.

Ask AI 1 recorded 806,400 cached input tokens while Ask AI 2 recorded 0. Measured costs describe the current production-shaped prompts and observed cache behavior, not an equal-token laboratory comparison.

## Largest Ask AI 2 Improvements

- **How does 1 Enoch explain the origin of demons and evil through the story of the Watchers?**: nDCG 0.0 to 0.8662 (+0.866).
- **What point was Jesus making with the parable of the Good Samaritan?**: nDCG 0.0819 to 0.9479 (+0.866).
- **Did Jesus expect the destruction of the Jerusalem Temple within his own generation?**: nDCG 0.1288 to 0.8872 (+0.758).
- **How do the accounts of Paul's conversion in Acts differ from Paul's own description in Galatians?**: nDCG 0.2399 to 0.9455 (+0.706).
- **What did Paul mean by justification through faith in his letter to the Romans?**: nDCG 0.2985 to 0.9747 (+0.676).

## Largest Ask AI 2 Regressions

- **Why does the Genesis flood story contain details that appear to come from two different versions?**: nDCG 0.9527 to 0.65 (-0.303).
- **How does Bart prepare for public debates with evangelical or conservative Christian scholars?**: nDCG 0.9721 to 0.6806 (-0.291).
- **Why do the Gospels portray Pontius Pilate as reluctant to execute Jesus when other sources describe him as brutal?**: nDCG 1.0 to 0.7726 (-0.227).
- **Why did Judas betray Jesus, and do the Gospels agree about his motives and death?**: nDCG 0.9888 to 0.7776 (-0.211).
- **How is the kingdom of God presented in the sayings of the Gospel of Thomas?**: nDCG 0.7693 to 0.5866 (-0.183).

## Question Results

| # | Question | Winner | Ask AI 1 P@5 / nDCG | Ask AI 2 P@5 / nDCG | Overlap |
|---:|---|---|---|---|---:|
| 1 | Why does the Genesis flood story contain details that appear to come from two different versions? | **Ask AI 1** | 100.0% / 0.9527 | 80.0% / 0.65 | 3 |
| 2 | Why does Deuteronomy describe some laws and events differently from Exodus and Numbers? | **Ask AI 1** | 80.0% / 0.9363 | 60.0% / 0.7736 | 7 |
| 3 | Why do scholars date the book of Daniel to the time of Antiochus Epiphanes rather than the Babylonian exile? | **Ask AI 2** | 75.0% / 0.7276 | 80.0% / 0.9814 | 3 |
| 4 | How does 1 Enoch explain the origin of demons and evil through the story of the Watchers? | **Ask AI 2** | 0.0% / 0.0 | 80.0% / 0.8662 | 0 |
| 5 | How did early Christians reinterpret passages from the Hebrew Bible as predictions about Jesus? | **Ask AI 2** | 100.0% / 0.8142 | 100.0% / 1.0 | 3 |
| 6 | Did Isaiah 7 originally predict that a virgin would give birth to the Messiah? | **Ask AI 2** | 100.0% / 0.6181 | 100.0% / 1.0 | 3 |
| 7 | Why was the Greek Septuagint so important for New Testament authors and early Christians? | **Ask AI 1** | 100.0% / 0.9613 | 80.0% / 0.8772 | 4 |
| 8 | What are the books of the Maccabees, and why are they absent from many Protestant Bibles? | **Ask AI 2** | 100.0% / 0.3124 | 80.0% / 0.988 | 1 |
| 9 | What did ancient Jewish apocalyptic writers expect God to do at the end of the present age? | **Ask AI 2** | 100.0% / 0.8407 | 100.0% / 0.9135 | 7 |
| 10 | How did Pharisees and Sadducees differ over resurrection, angels, and the authority of oral tradition? | **Ask AI 2** | 100.0% / 0.4693 | 100.0% / 1.0 | 1 |
| 11 | Did Jesus expect the destruction of the Jerusalem Temple within his own generation? | **Ask AI 2** | 100.0% / 0.1288 | 100.0% / 0.8872 | 1 |
| 12 | What did Jesus mean by the kingdom of God, and did he think it was already present or still future? | **Ask AI 2** | 100.0% / 0.8033 | 100.0% / 0.9925 | 6 |
| 13 | Was Jesus' command to love enemies intended as a practical ethic for ordinary life? | **Ask AI 2** | 80.0% / 0.7387 | 80.0% / 0.7803 | 3 |
| 14 | How can historians evaluate claims that Jesus performed miracles without deciding whether miracles are possible? | **Ask AI 1** | 100.0% / 0.9811 | 100.0% / 0.9195 | 7 |
| 15 | What do Jesus' exorcisms reveal about ancient beliefs concerning demons and illness? | **Ask AI 2** | 100.0% / 0.845 | 80.0% / 0.9943 | 3 |
| 16 | How do Matthew and Luke tell different versions of Jesus' temptation in the wilderness? | **Ask AI 2** | 0.0% / 0.4935 | 0.0% / 0.7751 | 0 |
| 17 | Why do the genealogies of Jesus in Matthew and Luke disagree about his ancestors? | **Ask AI 2** | 75.0% / 0.5409 | 80.0% / 0.8558 | 2 |
| 18 | Is there historical evidence that Herod ordered the massacre of infants in Bethlehem? | **Ask AI 2** | 80.0% / 0.7244 | 80.0% / 0.8365 | 4 |
| 19 | What point was Jesus making with the parable of the Good Samaritan? | **Ask AI 2** | 0.0% / 0.0819 | 60.0% / 0.9479 | 0 |
| 20 | Why does John call Jesus' miracles signs, and what are they meant to reveal about him? | **Tie** | 100.0% / 0.8701 | 100.0% / 0.8796 | 4 |
| 21 | What role did Mary Magdalene play among Jesus' followers and in the earliest resurrection traditions? | **Tie** | 100.0% / 0.7656 | 100.0% / 0.7476 | 3 |
| 22 | Why did Judas betray Jesus, and do the Gospels agree about his motives and death? | **Ask AI 1** | 100.0% / 0.9888 | 100.0% / 0.7776 | 7 |
| 23 | Why do the Gospels portray Pontius Pilate as reluctant to execute Jesus when other sources describe him as brutal? | **Ask AI 1** | 100.0% / 1.0 | 100.0% / 0.7726 | 5 |
| 24 | How historically plausible is the claim that Joseph of Arimathea placed Jesus in a known tomb? | **Ask AI 1** | 100.0% / 1.0 | 100.0% / 0.8776 | 4 |
| 25 | Is an empty tomb necessary to explain why Jesus' followers came to believe he had been raised? | **Ask AI 2** | 100.0% / 0.3873 | 100.0% / 0.9899 | 2 |
| 26 | How do the accounts of Paul's conversion in Acts differ from Paul's own description in Galatians? | **Ask AI 2** | 50.0% / 0.2399 | 100.0% / 0.9455 | 0 |
| 27 | What did Paul mean by justification through faith in his letter to the Romans? | **Ask AI 2** | 100.0% / 0.2985 | 100.0% / 0.9747 | 2 |
| 28 | Why were some Christians in Corinth denying a future resurrection of the dead? | **Ask AI 2** | 80.0% / 0.6604 | 100.0% / 0.9524 | 3 |
| 29 | Does the Christ poem in Philippians teach that Jesus existed in heaven before his human life? | **Tie** | 100.0% / 1.0 | 100.0% / 1.0 | 7 |
| 30 | What vocabulary and theology lead many scholars to question whether Paul wrote Colossians? | **Tie** | 80.0% / 0.9969 | 80.0% / 0.9925 | 7 |
| 31 | How do restrictions on women in the Pastoral Epistles differ from women's roles in Paul's undisputed letters? | **Ask AI 2** | 100.0% / 0.4811 | 80.0% / 0.8237 | 1 |
| 32 | Do Paul's speeches in Acts accurately represent the theology found in his letters? | **Ask AI 2** | 100.0% / 0.7267 | 100.0% / 0.9661 | 4 |
| 33 | Does the Letter of James contradict Paul's teaching that people are justified by faith rather than works? | **Tie** | 100.0% / 0.8937 | 100.0% / 0.9026 | 5 |
| 34 | Were Peter and Cephas the same apostle, and why have some scholars argued that they were different people? | **Ask AI 2** | 100.0% / 0.7004 | 100.0% / 0.931 | 6 |
| 35 | Did Paul expect Jesus to return before Paul and most of his converts died? | **Ask AI 2** | 100.0% / 0.6489 | 100.0% / 0.9202 | 3 |
| 36 | Did the Gospel of Mark originally end with the women fleeing the empty tomb in fear? | **Ask AI 2** | 100.0% / 0.6734 | 100.0% / 0.824 | 3 |
| 37 | How did the passage known as the Johannine Comma become part of later copies of 1 John? | **Tie** | 100.0% / 0.9858 | 100.0% / 0.9735 | 7 |
| 38 | Why do scholars conclude that the story of the woman caught in adultery was not originally in John's Gospel? | **Ask AI 2** | 100.0% / 0.8194 | 80.0% / 0.9307 | 3 |
| 39 | Was Luke's account of Jesus sweating blood added by a later scribe, and why would someone add it? | **Tie** | 100.0% / 0.9677 | 100.0% / 0.9966 | 7 |
| 40 | Did Christian scribes alter passages that could be used to support adoptionist views of Jesus? | **Ask AI 2** | 100.0% / 0.8837 | 100.0% / 0.9552 | 4 |
| 41 | How is the kingdom of God presented in the sayings of the Gospel of Thomas? | **Ask AI 1** | 100.0% / 0.7693 | 80.0% / 0.5866 | 1 |
| 42 | Does the Gospel of Judas portray Judas as Jesus' favored disciple or as a condemned figure? | **Ask AI 2** | 100.0% / 0.8552 | 100.0% / 0.9911 | 5 |
| 43 | What was discovered at Nag Hammadi, and how did it change the study of early Christianity? | **Ask AI 2** | 100.0% / 0.5651 | 100.0% / 0.8812 | 3 |
| 44 | How did Constantine's conversion change the legal and political position of Christianity in the Roman Empire? | **Ask AI 2** | 80.0% / 0.8679 | 80.0% / 0.9869 | 7 |
| 45 | What disagreement about Christ led to the conflict between Arius and his opponents at the Council of Nicaea? | **Tie** | 100.0% / 0.9084 | 100.0% / 0.9084 | 5 |
| 46 | How did Augustine develop the doctrine of original sin from his reading of Paul? | **Tie** | 100.0% / 0.5321 | 0.0% / 0.5334 | 0 |
| 47 | Did Origen teach that all rational beings, including the devil, would eventually be saved? | **Tie** | 100.0% / 1.0 | 100.0% / 1.0 | 4 |
| 48 | Why can studying contradictions and manuscript differences create problems for belief in biblical inerrancy? | **Ask AI 2** | 100.0% / 0.7294 | 100.0% / 0.9432 | 3 |
| 49 | How does Bart prepare for public debates with evangelical or conservative Christian scholars? | **Ask AI 1** | 66.7% / 0.9721 | 66.7% / 0.6806 | 3 |
| 50 | How does Bart's blog use membership fees and fundraising campaigns to support charitable causes? | **Ask AI 2** | 100.0% / 0.7927 | 100.0% / 0.8236 | 5 |
