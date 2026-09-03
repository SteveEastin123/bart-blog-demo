# Ask AI 1 vs. Ask AI 2: Same 50-Question Full-Text Evaluation

Date: August 31, 2026

## Method

The same 50 frozen questions from the August 29 Ask AI 2 versus Ask AI 3 trial were submitted to the current Ask AI 1 and Ask AI 2 implementations. Ask AI 1 converted each question into curated topics and secondary keywords, searched those assignments, and used AI refinement. Ask AI 2 used one title-and-summary semantic vector and the same AI-refinement stage.

The local AI search cache was invalidated separately before each method. For every question, the union of both methods' first ten results was evaluated from complete local post text. The grader did not receive method names or rank positions. Grades are `3` direct, `2` strong supporting, `1` marginal, and `0` irrelevant. Grades 2 and 3 count as relevant. An nDCG@10 difference of 0.03 or less is treated as a tie.

## Results

| Outcome | Questions |
|---|---:|
| Ask AI 1 better | 13 |
| Ask AI 2 better | 32 |
| Tied | 5 |

| Mean metric | Ask AI 1 | Ask AI 2 | Change |
|---|---:|---:|---:|
| Precision@5 | 72.9% | 71.4% | -1.5% |
| Precision@10 | 71.8% | 67.6% | -4.1% |
| Average grade@5 | 2.18 | 2.2167 | +0.037 |
| Average grade@10 | 2.1494 | 2.0861 | -0.063 |
| Recall@10 within judged pool | 55.6% | 84.9% | 29.3% |
| nDCG@10 | 0.6254 | 0.8597 | +0.234 |

Ask AI 1 returned an average of 4.2 posts and had 5 zero-result questions. Ask AI 2 returned an average of 9.12 posts and had 0 zero-result questions. The methods shared a mean of 2.46 posts in their first ten results.

## Speed and Search Cost

| Measure | Ask AI 1 | Ask AI 2 |
|---|---:|---:|
| Mean response time | 4.681 seconds | 3.943 seconds |
| Median response time | 4.309 seconds | 3.562 seconds |
| 95th-percentile response time | 8.465 seconds | 7.648 seconds |
| Total API cost for 50 questions | $0.2532 | $0.4994 |
| Average API cost per question | 0.506 cents | 0.999 cents |

Search costs include each method's initial interpretation or embedding call plus its refinement call. The separate blinded grading expense is not part of end-user search cost.

## Assessment

Ask AI 2 was the stronger method in this run.

Ask AI 2 won 32 questions to 13, had no zero-result questions, improved pooled recall by 29.3 percentage points, and raised mean nDCG@10 by 0.2343. Its mean top-five grade was also slightly higher, showing that the coverage gain was not merely extra volume.

Ask AI 1 had slightly higher binary precision among the posts it did return and cost about half as much in this run. It was particularly effective when a curated topic aligned tightly with the question, including Celsus and the Lazarus questions.

Ask AI 1's topic-and-keyword intersections sometimes became too restrictive: it returned no posts for five answerable questions. Ask AI 2's broader semantic retrieval avoided those gaps, but sometimes admitted adjacent posts or ranked a direct metadata match lower.

Ask AI 1 benefited from 790,272 cached input tokens while Ask AI 2 recorded no cached input tokens. The measured costs therefore describe the current production-shaped prompts and cache behavior, not an equal-token laboratory comparison.

## Largest Ask AI 2 Improvements

- **How did Origen defend Christianity against educated pagan critics?**: nDCG 0.0 to 1.0 (+1.000).
- **Why do scholars think the author of 2 Peter knew the Letter of Jude?**: nDCG 0.0 to 0.9885 (+0.989).
- **Why did Bart write Misquoting Jesus for readers outside the university?**: nDCG 0.0 to 0.984 (+0.984).
- **What evidence suggests that Paul did not write 1 Timothy?**: nDCG 0.0 to 0.9566 (+0.957).
- **Was the Last Supper actually a Passover meal?**: nDCG 0.0 to 0.9336 (+0.934).

## Largest Ask AI 2 Regressions

- **What accusations did the pagan critic Celsus make against Christianity?**: nDCG 0.9173 to 0.5413 (-0.376).
- **What evidence is there that Moses was a historical person?**: nDCG 0.6387 to 0.4309 (-0.208).
- **Why is the raising of Lazarus reported only in the Gospel of John?**: nDCG 0.6457 to 0.4656 (-0.180).
- **What does the parable of the rich man and Lazarus imply about judgment after death?**: nDCG 0.9739 to 0.818 (-0.156).
- **How historically plausible is the Gospel account of Jesus' trial before the Jewish council?**: nDCG 1.0 to 0.8505 (-0.149).

## Question Results

| # | Question | Winner | Ask AI 1 P@5 / nDCG | Ask AI 2 P@5 / nDCG | Overlap |
|---:|---|---|---|---|---:|
| 1 | Why does Matthew trace Jesus' ancestry through Joseph if Jesus was born of a virgin? | **Ask AI 2** | 60.0% / 0.8604 | 80.0% / 0.9316 | 5 |
| 2 | What historical problems surround Luke's census under Quirinius? | **Ask AI 2** | 100.0% / 0.5948 | 100.0% / 0.9258 | 2 |
| 3 | Why does John place the cleansing of the Temple near the beginning of Jesus' ministry? | **Ask AI 1** | 40.0% / 0.8621 | 40.0% / 0.774 | 4 |
| 4 | Was the Last Supper actually a Passover meal? | **Ask AI 2** | 0.0% / 0.0 | 80.0% / 0.9336 | 0 |
| 5 | How do the Gospels differ about who first discovered Jesus' empty tomb? | **Ask AI 2** | 50.0% / 0.4226 | 80.0% / 0.8034 | 0 |
| 6 | Why was Jesus baptized by John if Christians believed Jesus was sinless? | **Ask AI 2** | 100.0% / 0.4852 | 60.0% / 0.7419 | 1 |
| 7 | How historically plausible is the Gospel account of Jesus' trial before the Jewish council? | **Ask AI 1** | 40.0% / 1.0 | 100.0% / 0.8505 | 2 |
| 8 | Is the story of the crowd choosing Barabbas historically credible? | **Ask AI 2** | 100.0% / 0.8805 | 100.0% / 0.998 | 6 |
| 9 | Why does Mark repeatedly portray the disciples as misunderstanding Jesus? | **Tie** | 100.0% / 0.8974 | 100.0% / 0.9117 | 6 |
| 10 | What does John's statement that the Word became flesh mean? | **Ask AI 1** | 100.0% / 0.8653 | 60.0% / 0.8124 | 5 |
| 11 | Is the conversation between Jesus and Nicodemus meant as history or theological symbolism? | **Ask AI 2** | 40.0% / 0.5669 | 100.0% / 0.8547 | 2 |
| 12 | Why is the raising of Lazarus reported only in the Gospel of John? | **Ask AI 1** | 100.0% / 0.6457 | 60.0% / 0.4656 | 0 |
| 13 | What does the parable of the rich man and Lazarus imply about judgment after death? | **Ask AI 1** | 100.0% / 0.9739 | 100.0% / 0.818 | 4 |
| 14 | Why are Matthew's Sermon on the Mount and Luke's Sermon on the Plain different? | **Ask AI 2** | 60.0% / 0.3596 | 60.0% / 0.8357 | 1 |
| 15 | Why do the Gospels say Jesus used parables to conceal his message from some listeners? | **Ask AI 2** | 33.3% / 0.5729 | 40.0% / 0.9983 | 3 |
| 16 | What can the story of Jairus' daughter tell us about how Gospel traditions developed? | **Ask AI 2** | 100.0% / 0.6343 | 80.0% / 0.9245 | 3 |
| 17 | Why do the resurrection accounts disagree about where Jesus appeared to his disciples? | **Ask AI 2** | 100.0% / 0.1496 | 100.0% / 0.7891 | 1 |
| 18 | Did Jesus ascend on Easter day or forty days after the resurrection? | **Tie** | 100.0% / 1.0 | 100.0% / 1.0 | 1 |
| 19 | What did Paul mean when he described Junia as prominent among the apostles? | **Ask AI 2** | 100.0% / 0.1442 | 60.0% / 0.9196 | 0 |
| 20 | Why does Paul permit women to prophesy in one passage but command women to be silent in another? | **Ask AI 2** | 100.0% / 0.5066 | 100.0% / 0.8867 | 1 |
| 21 | What kind of body did Paul think people would have after the resurrection? | **Ask AI 2** | 100.0% / 0.8701 | 100.0% / 0.9202 | 6 |
| 22 | Was Paul's confrontation with Peter at Antioch fundamentally about Jewish law? | **Ask AI 2** | 50.0% / 0.3415 | 80.0% / 0.8146 | 2 |
| 23 | Why is the Letter to the Hebrews anonymous, and could Paul have written it? | **Tie** | 100.0% / 1.0 | 100.0% / 1.0 | 2 |
| 24 | What evidence leads scholars to doubt that Paul wrote Ephesians? | **Ask AI 1** | 80.0% / 0.9301 | 80.0% / 0.8137 | 4 |
| 25 | How does Paul's letter to Philemon address slavery without explicitly condemning it? | **Ask AI 2** | 100.0% / 0.8829 | 100.0% / 0.9812 | 6 |
| 26 | What evidence suggests that Paul did not write 1 Timothy? | **Ask AI 2** | 0.0% / 0.0 | 60.0% / 0.9566 | 0 |
| 27 | Why do scholars think the author of 2 Peter knew the Letter of Jude? | **Ask AI 2** | 0.0% / 0.0 | 40.0% / 0.9885 | 0 |
| 28 | Why does the Letter of Jude quote the Book of 1 Enoch? | **Ask AI 2** | 33.3% / 0.3211 | 25.0% / 0.4856 | 0 |
| 29 | Why does Acts never mention that Paul wrote letters to Christian communities? | **Ask AI 2** | 20.0% / 0.5119 | 40.0% / 0.8649 | 3 |
| 30 | What does Romans 9 imply about divine election and human free will? | **Ask AI 2** | 100.0% / 0.8609 | 25.0% / 1.0 | 1 |
| 31 | Was Marcion the first Christian to assemble a collection resembling a New Testament? | **Ask AI 2** | 100.0% / 0.7914 | 80.0% / 0.8805 | 3 |
| 32 | Did the Council of Nicaea decide which books belonged in the Bible? | **Tie** | 100.0% / 0.9364 | 100.0% / 0.9581 | 7 |
| 33 | How did proto-orthodox Christians come to defeat rival forms of Christianity? | **Ask AI 2** | 75.0% / 0.5446 | 100.0% / 0.9042 | 2 |
| 34 | Why did Marcion believe that Jesus' Father was not the God of the Old Testament? | **Ask AI 2** | 100.0% / 0.3797 | 100.0% / 1.0 | 2 |
| 35 | What does the Gospel of Peter add to the story of Jesus' resurrection? | **Ask AI 1** | 100.0% / 0.9946 | 100.0% / 0.9506 | 6 |
| 36 | What rituals and beliefs are described in the Gospel of Philip? | **Ask AI 2** | 100.0% / 0.7453 | 66.7% / 0.9721 | 1 |
| 37 | What stories about the apostle Thomas appear in the Acts of Thomas? | **Ask AI 1** | 100.0% / 0.9482 | 100.0% / 0.9014 | 7 |
| 38 | Why did church leaders reject the Infancy Gospel of Thomas? | **Ask AI 2** | 33.3% / 0.5496 | 20.0% / 0.8449 | 1 |
| 39 | What accusations did the pagan critic Celsus make against Christianity? | **Ask AI 1** | 100.0% / 0.9173 | 33.3% / 0.5413 | 1 |
| 40 | How did Origen defend Christianity against educated pagan critics? | **Ask AI 2** | 0.0% / 0.0 | 25.0% / 1.0 | 0 |
| 41 | What happened to Christians during the persecution under the emperor Decius? | **Ask AI 2** | 100.0% / 0.7453 | 66.7% / 0.8428 | 1 |
| 42 | What happened to the Christians of Lyons and Vienne? | **Ask AI 1** | 100.0% / 0.834 | 50.0% / 0.7872 | 1 |
| 43 | How do early martyr stories portray women such as Perpetua? | **Ask AI 2** | 50.0% / 0.1976 | 80.0% / 0.8932 | 0 |
| 44 | How have Christian understandings of biblical inspiration changed over time? | **Ask AI 2** | 60.0% / 0.7735 | 80.0% / 0.9526 | 4 |
| 45 | What evidence is there that Moses was a historical person? | **Ask AI 1** | 20.0% / 0.6387 | 0.0% / 0.4309 | 3 |
| 46 | How does the Documentary Hypothesis explain contradictions in the Pentateuch? | **Tie** | 100.0% / 0.9364 | 100.0% / 0.9225 | 3 |
| 47 | Why does the Book of Job offer more than one explanation for innocent suffering? | **Ask AI 1** | 100.0% / 0.9714 | 80.0% / 0.8797 | 6 |
| 48 | How did Bart's study of suffering contribute to his loss of faith? | **Ask AI 1** | 100.0% / 0.8106 | 60.0% / 0.731 | 3 |
| 49 | Why did Bart write Misquoting Jesus for readers outside the university? | **Ask AI 2** | 0.0% / 0.0 | 80.0% / 0.984 | 0 |
| 50 | How accurately does Life of Brian portray religion and politics in first-century Judea? | **Ask AI 2** | 100.0% / 0.4126 | 0.0% / 0.6055 | 1 |
