# Ask AI 2 vs. Ask AI 3: New 50-Question Full-Text Evaluation

Date: August 29, 2026

## Method

Fifty new questions were frozen before retrieval. Ask AI 2 used one title-and-summary vector. Ask AI 3 used the same vector plus independently weighted topic, topic-alias, and secondary-keyword vectors. Both methods used the same AI refinement stage.

For each question, the union of both methods' first ten results was evaluated from complete local post text. The grader did not receive method names or rank positions. Grades are `3` direct, `2` strong supporting, `1` marginal, and `0` irrelevant. Grades 2 and 3 count as relevant. An nDCG@10 difference of 0.03 or less is treated as a tie.

## Results

| Outcome | Questions |
|---|---:|
| Ask AI 2 better | 17 |
| Ask AI 3 better | 13 |
| Tied | 20 |

| Mean metric | Ask AI 2 | Ask AI 3 | Change |
|---|---:|---:|---:|
| Precision@5 | 73.0% | 71.4% | -1.7% |
| Precision@10 | 65.8% | 64.3% | -1.5% |
| Average grade@5 | 2.2507 | 2.2303 | -0.020 |
| Average grade@10 | 2.0834 | 2.0387 | -0.045 |
| Recall@10 within judged pool | 85.0% | 85.7% | 0.7% |
| nDCG@10 | 0.8862 | 0.8956 | +0.009 |

The methods shared a mean of 4.94 posts in their first ten results (median 5). The grader evaluated 487 question/post pairs in 74 blinded calls.

## Assessment

This run is best treated as a practical tie, with a slight Ask AI 2 advantage for the posts readers see first. Ask AI 2 won 17 questions to Ask AI 3's 13, produced higher precision at both five and ten results, and had higher average relevance grades. Ask AI 3 had slightly better recall and nDCG, but its mean nDCG advantage was only 0.0094.

Manual review confirms a consistent tradeoff. Ask AI 3 made large gains when topic metadata identified a specific subject, including the Temple incident, Moses, and Jesus' genealogy. It regressed when broad topic metadata elevated general, conference, or background posts above direct title-and-summary matches, most visibly for the Gospel of Peter, Life of Brian, the Sermon on the Mount, and 1 Timothy.

This is the same broad pattern seen in the earlier 50-question run: the metadata vectors improve coverage and overall ordering but can slightly weaken the very top of the list. The next experiment should lower the metadata contribution or apply it as a bounded boost rather than allowing it to displace strong content-vector matches.

## Largest Ask AI 3 Improvements

- **Why does John place the cleansing of the Temple near the beginning of Jesus' ministry?**: nDCG 0.322 to 0.9094 (+0.587).
- **What evidence is there that Moses was a historical person?**: nDCG 0.6084 to 0.9477 (+0.339).
- **Why does Matthew trace Jesus' ancestry through Joseph if Jesus was born of a virgin?**: nDCG 0.7284 to 0.9684 (+0.240).
- **How did Origen defend Christianity against educated pagan critics?**: nDCG 0.796 to 1 (+0.204).
- **How historically plausible is the Gospel account of Jesus' trial before the Jewish council?**: nDCG 0.7774 to 0.9594 (+0.182).

## Largest Ask AI 3 Regressions

- **What does the Gospel of Peter add to the story of Jesus' resurrection?**: nDCG 0.9677 to 0.7125 (-0.255).
- **How accurately does Life of Brian portray religion and politics in first-century Judea?**: nDCG 0.787 to 0.6055 (-0.182).
- **Why are Matthew's Sermon on the Mount and Luke's Sermon on the Plain different?**: nDCG 0.9305 to 0.7782 (-0.152).
- **What evidence suggests that Paul did not write 1 Timothy?**: nDCG 0.9534 to 0.8172 (-0.136).
- **How have Christian understandings of biblical inspiration changed over time?**: nDCG 0.8954 to 0.7627 (-0.133).

## Question Results

| # | Question | Winner | Ask AI 2 P@5 / nDCG | Ask AI 3 P@5 / nDCG | Overlap |
|---:|---|---|---|---|---:|
| 1 | Why does Matthew trace Jesus' ancestry through Joseph if Jesus was born of a virgin? | **Ask AI 3** | 40.0% / 0.7284 | 60.0% / 0.9684 | 4 |
| 2 | What historical problems surround Luke's census under Quirinius? | **Tie** | 100.0% / 0.9455 | 100.0% / 0.9455 | 8 |
| 3 | Why does John place the cleansing of the Temple near the beginning of Jesus' ministry? | **Ask AI 3** | 20.0% / 0.322 | 60.0% / 0.9094 | 2 |
| 4 | Was the Last Supper actually a Passover meal? | **Ask AI 2** | 80.0% / 0.9183 | 75.0% / 0.8004 | 4 |
| 5 | How do the Gospels differ about who first discovered Jesus' empty tomb? | **Ask AI 3** | 60.0% / 0.8312 | 80.0% / 0.8877 | 5 |
| 6 | Why was Jesus baptized by John if Christians believed Jesus was sinless? | **Tie** | 60.0% / 0.6515 | 60.0% / 0.6316 | 3 |
| 7 | How historically plausible is the Gospel account of Jesus' trial before the Jewish council? | **Ask AI 3** | 66.7% / 0.7774 | 40.0% / 0.9594 | 2 |
| 8 | Is the story of the crowd choosing Barabbas historically credible? | **Ask AI 2** | 100.0% / 0.998 | 100.0% / 0.9636 | 9 |
| 9 | Why does Mark repeatedly portray the disciples as misunderstanding Jesus? | **Tie** | 100.0% / 1 | 100.0% / 1 | 9 |
| 10 | What does John's statement that the Word became flesh mean? | **Ask AI 2** | 100.0% / 0.931 | 100.0% / 0.8111 | 6 |
| 11 | Is the conversation between Jesus and Nicodemus meant as history or theological symbolism? | **Tie** | 80.0% / 0.9034 | 60.0% / 0.8882 | 7 |
| 12 | Why is the raising of Lazarus reported only in the Gospel of John? | **Tie** | 60.0% / 0.7808 | 40.0% / 0.8074 | 6 |
| 13 | What does the parable of the rich man and Lazarus imply about judgment after death? | **Ask AI 3** | 80.0% / 0.8279 | 100.0% / 0.9368 | 4 |
| 14 | Why are Matthew's Sermon on the Mount and Luke's Sermon on the Plain different? | **Ask AI 2** | 80.0% / 0.9305 | 80.0% / 0.7782 | 4 |
| 15 | Why do the Gospels say Jesus used parables to conceal his message from some listeners? | **Ask AI 3** | 40.0% / 0.9696 | 40.0% / 1 | 4 |
| 16 | What can the story of Jairus' daughter tell us about how Gospel traditions developed? | **Ask AI 2** | 80.0% / 0.9004 | 100.0% / 0.7912 | 6 |
| 17 | Why do the resurrection accounts disagree about where Jesus appeared to his disciples? | **Tie** | 80.0% / 0.8067 | 100.0% / 0.8311 | 5 |
| 18 | Did Jesus ascend on Easter day or forty days after the resurrection? | **Ask AI 3** | 100.0% / 0.9173 | 50.0% / 1 | 1 |
| 19 | What did Paul mean when he described Junia as prominent among the apostles? | **Ask AI 3** | 100.0% / 0.8243 | 100.0% / 0.9921 | 5 |
| 20 | Why does Paul permit women to prophesy in one passage but command women to be silent in another? | **Tie** | 100.0% / 0.9253 | 100.0% / 0.9547 | 8 |
| 21 | What kind of body did Paul think people would have after the resurrection? | **Tie** | 100.0% / 0.9357 | 100.0% / 0.9456 | 6 |
| 22 | Was Paul's confrontation with Peter at Antioch fundamentally about Jewish law? | **Ask AI 3** | 80.0% / 0.8119 | 80.0% / 0.8674 | 5 |
| 23 | Why is the Letter to the Hebrews anonymous, and could Paul have written it? | **Ask AI 2** | 80.0% / 1 | 100.0% / 0.8988 | 3 |
| 24 | What evidence leads scholars to doubt that Paul wrote Ephesians? | **Tie** | 80.0% / 1 | 80.0% / 0.9782 | 6 |
| 25 | How does Paul's letter to Philemon address slavery without explicitly condemning it? | **Tie** | 100.0% / 0.9783 | 100.0% / 0.9812 | 7 |
| 26 | What evidence suggests that Paul did not write 1 Timothy? | **Ask AI 2** | 60.0% / 0.9534 | 60.0% / 0.8172 | 6 |
| 27 | Why do scholars think the author of 2 Peter knew the Letter of Jude? | **Ask AI 2** | 60.0% / 0.9352 | 40.0% / 0.8662 | 4 |
| 28 | Why does the Letter of Jude quote the Book of 1 Enoch? | **Tie** | 40.0% / 0.9663 | 40.0% / 0.9621 | 5 |
| 29 | Why does Acts never mention that Paul wrote letters to Christian communities? | **Ask AI 3** | 20.0% / 0.8726 | 20.0% / 0.9935 | 6 |
| 30 | What does Romans 9 imply about divine election and human free will? | **Ask AI 2** | 25.0% / 0.9568 | 33.3% / 0.9086 | 2 |
| 31 | Was Marcion the first Christian to assemble a collection resembling a New Testament? | **Ask AI 2** | 80.0% / 0.89 | 60.0% / 0.8547 | 4 |
| 32 | Did the Council of Nicaea decide which books belonged in the Bible? | **Ask AI 2** | 100.0% / 1 | 100.0% / 0.9371 | 6 |
| 33 | How did proto-orthodox Christians come to defeat rival forms of Christianity? | **Tie** | 100.0% / 0.9421 | 100.0% / 0.9432 | 6 |
| 34 | Why did Marcion believe that Jesus' Father was not the God of the Old Testament? | **Ask AI 2** | 100.0% / 0.9966 | 100.0% / 0.9607 | 9 |
| 35 | What does the Gospel of Peter add to the story of Jesus' resurrection? | **Ask AI 2** | 100.0% / 0.9677 | 80.0% / 0.7125 | 6 |
| 36 | What rituals and beliefs are described in the Gospel of Philip? | **Tie** | 50.0% / 1 | 50.0% / 1 | 2 |
| 37 | What stories about the apostle Thomas appear in the Acts of Thomas? | **Ask AI 2** | 80.0% / 0.9306 | 100.0% / 0.8261 | 6 |
| 38 | Why did church leaders reject the Infancy Gospel of Thomas? | **Ask AI 3** | 20.0% / 0.7193 | 25.0% / 0.8091 | 3 |
| 39 | What accusations did the pagan critic Celsus make against Christianity? | **Tie** | 100.0% / 1 | 50.0% / 1 | 1 |
| 40 | How did Origen defend Christianity against educated pagan critics? | **Ask AI 3** | 33.3% / 0.796 | 25.0% / 1 | 2 |
| 41 | What happened to Christians during the persecution under the emperor Decius? | **Tie** | 66.7% / 1 | 66.7% / 1 | 3 |
| 42 | What happened to the Christians of Lyons and Vienne? | **Tie** | 50.0% / 1 | 33.3% / 1 | 2 |
| 43 | How do early martyr stories portray women such as Perpetua? | **Ask AI 2** | 80.0% / 0.9747 | 80.0% / 0.8601 | 5 |
| 44 | How have Christian understandings of biblical inspiration changed over time? | **Ask AI 2** | 80.0% / 0.8954 | 80.0% / 0.7627 | 6 |
| 45 | What evidence is there that Moses was a historical person? | **Ask AI 3** | 20.0% / 0.6084 | 60.0% / 0.9477 | 5 |
| 46 | How does the Documentary Hypothesis explain contradictions in the Pentateuch? | **Tie** | 100.0% / 1 | 100.0% / 1 | 6 |
| 47 | Why does the Book of Job offer more than one explanation for innocent suffering? | **Tie** | 80.0% / 0.9824 | 100.0% / 0.9628 | 7 |
| 48 | How did Bart's study of suffering contribute to his loss of faith? | **Tie** | 80.0% / 0.8757 | 80.0% / 0.854 | 7 |
| 49 | Why did Bart write Misquoting Jesus for readers outside the university? | **Tie** | 80.0% / 0.6455 | 80.0% / 0.6691 | 2 |
| 50 | How accurately does Life of Brian portray religion and politics in first-century Judea? | **Ask AI 2** | 80.0% / 0.787 | 0.0% / 0.6055 | 7 |
