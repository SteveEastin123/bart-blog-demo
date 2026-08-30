# Original vs. Metadata-Enhanced Ask AI 2

Date: August 29, 2026

## Method

The frozen 50-question benchmark from the August 29 Ask AI evaluation was run twice against Ask AI 2. The control used the original `hybrid` title-and-summary retrieval. The experiment used `hybrid-metadata`, which combines title-and-summary (80%), topic (12%), topic-alias (5%), and secondary-keyword (3%) vectors before the unchanged lexical, exact-metadata, and AI-refinement stages.

For each question, the union of both methods' first ten results was evaluated from complete local post text. Existing blinded full-text grades were reused for the same question/post pair; 120 new pairs were graded blindly without method or rank information. Grades are `3` direct, `2` strong supporting, `1` marginal, and `0` irrelevant. Grades 2 and 3 count as relevant. A pooled nDCG@10 difference of 0.03 or less is a tie.

## Results

| Outcome | All 50 | Static 30 | Generated 20 |
|---|---:|---:|---:|
| Original Ask AI 2 better | 12 | 5 | 7 |
| Metadata Ask AI 2 better | 26 | 19 | 7 |
| Tied | 12 | 6 | 6 |

| Mean metric | Original | Metadata-enhanced | Change |
|---|---:|---:|---:|
| Precision@5 | 97.2% | 96.0% | -1.2% |
| Precision@10 | 88.3% | 90.4% | 2.1% |
| Average grade@5 | 2.85 | 2.80 | -0.05 |
| Average grade@10 | 2.57 | 2.62 | +0.05 |
| Recall@10 within judged pool | 75.7% | 79.9% | 4.2% |
| nDCG@10 | 0.886 | 0.915 | +0.029 |

The two methods shared a mean of 5.98 posts in their first ten results (median 6.0). Only one question had an identical ordered top ten.

Latency is not compared in this run because the original strategy's exact question/candidate refinements were already warm in WordPress from the earlier benchmark, while the metadata strategy generated new refinement requests.

## Assessment

The metadata-enhanced strategy is the stronger overall retrieval method in this run. It won more than twice as many questions as the original strategy (26 to 12), improved mean nDCG@10 by 0.029, increased precision@10 by 2.1 percentage points, and increased pooled recall@10 by 4.2 percentage points.

The tradeoff is visible near the very top of the list: precision@5 decreased by 1.2 percentage points and average grade@5 decreased by 0.05. The static regression set strongly favored metadata (19 wins to 5), while the 20 generated questions split evenly (7 wins each, 6 ties) and slightly favored the original method on mean top-five quality. The new vectors therefore improve breadth and overall ordering, but broad taxonomy signals occasionally outrank more specific title-and-summary evidence.

Manual review confirmed the largest regressions. Broad `Lost Christianities` material displaced more specific lost-writing posts; general manuscript and textual-criticism posts displaced direct Codex Sinaiticus posts; and textbook/course-adjacent posts displaced direct accounts of critical university teaching. These are weighting issues rather than incorrect topic or keyword assignments.

Recommendation: keep `hybrid-metadata` as the leading Ask AI 2 candidate, preserve `hybrid` as the control, and tune the metadata contribution before final selection. The next trial should test a slightly more conservative metadata share while retaining the separate topic, alias, and secondary-keyword vectors.

## Largest Improvements

- **Why do scholars think the Gospels were written anonymously?**: nDCG 0.565 to 0.995 (+0.430).
- **How do Matthew and Luke tell different stories about Jesus' birth?**: nDCG 0.801 to 1.000 (+0.199).
- **How does Paul's account of his conversion differ from the story in Acts?**: nDCG 0.801 to 1.000 (+0.199).
- **What can Paul's letters tell us about his life that Acts does not?**: nDCG 0.693 to 0.881 (+0.188).
- **How do scholars decide who wrote works such as 1 Clement and the Letter of Barnabas?**: nDCG 0.631 to 0.818 (+0.187).

## Largest Regressions

- **What important early Christian writings have been lost or survived only in fragments?**: nDCG 1.000 to 0.637 (-0.363).
- **Why is Codex Sinaiticus important for understanding the Bible's text?**: nDCG 0.824 to 0.512 (-0.312).
- **How is the New Testament taught in a critical university course?**: nDCG 0.955 to 0.750 (-0.205).
- **How did anti-Jewish ideas enter early Christian writings?**: nDCG 1.000 to 0.859 (-0.141).
- **How widespread was Roman persecution of Christians before Constantine?**: nDCG 0.996 to 0.911 (-0.085).

## Question Results

| # | Question | Winner | Original P@5 / nDCG | Metadata P@5 / nDCG | Overlap |
|---:|---|---|---|---|---:|
| 1 | Why do scholars think the Gospels were written anonymously? | **Metadata Ask AI 2** | 100.0% / 0.565 | 100.0% / 0.995 | 3 |
| 2 | Was the original ending of Mark lost or added later? | **Tie** | 80.0% / 0.863 | 80.0% / 0.875 | 5 |
| 3 | How do Matthew and Luke tell different stories about Jesus' birth? | **Metadata Ask AI 2** | 100.0% / 0.801 | 100.0% / 1.000 | 4 |
| 4 | What historical evidence is there for Jesus' empty tomb? | **Original Ask AI 2** | 100.0% / 0.943 | 100.0% / 0.910 | 6 |
| 5 | Why do Matthew and Acts describe Judas' death differently? | **Original Ask AI 2** | 60.0% / 0.949 | 80.0% / 0.907 | 6 |
| 6 | Why is the woman caught in adultery missing from the earliest manuscripts of John? | **Metadata Ask AI 2** | 100.0% / 0.931 | 100.0% / 0.993 | 7 |
| 7 | How does Paul's account of his conversion differ from the story in Acts? | **Metadata Ask AI 2** | 100.0% / 0.801 | 100.0% / 1.000 | 7 |
| 8 | Why do scholars question whether Paul wrote Colossians? | **Tie** | 80.0% / 0.993 | 80.0% / 0.995 | 9 |
| 9 | What did Paul teach about women speaking and leading in church? | **Metadata Ask AI 2** | 100.0% / 0.915 | 100.0% / 0.955 | 6 |
| 10 | Do James and Paul disagree about faith and works? | **Metadata Ask AI 2** | 100.0% / 0.946 | 100.0% / 1.000 | 9 |
| 11 | What does the Christ poem in Philippians say about Jesus before his birth? | **Metadata Ask AI 2** | 100.0% / 0.964 | 100.0% / 1.000 | 8 |
| 12 | Were Peter and Cephas the same person? | **Metadata Ask AI 2** | 100.0% / 0.933 | 100.0% / 1.000 | 8 |
| 13 | Was Mary Magdalene really a prostitute? | **Tie** | 100.0% / 1.000 | 100.0% / 1.000 | 4 |
| 14 | Did Jesus expect the world to end during his generation? | **Metadata Ask AI 2** | 100.0% / 0.922 | 100.0% / 0.962 | 3 |
| 15 | How can historians evaluate miracle stories about Jesus? | **Metadata Ask AI 2** | 100.0% / 0.893 | 100.0% / 0.947 | 7 |
| 16 | What did Jesus mean by the Kingdom of God? | **Metadata Ask AI 2** | 100.0% / 0.864 | 100.0% / 0.955 | 7 |
| 17 | Why does an all-powerful and loving God allow innocent people to suffer? | **Original Ask AI 2** | 100.0% / 0.955 | 100.0% / 0.896 | 7 |
| 18 | When did Christians begin believing that souls go immediately to heaven or hell? | **Metadata Ask AI 2** | 80.0% / 0.736 | 100.0% / 0.830 | 3 |
| 19 | What is the main message of the Book of Revelation? | **Tie** | 100.0% / 0.957 | 100.0% / 0.957 | 7 |
| 20 | How did Christians decide which books belonged in the New Testament? | **Tie** | 100.0% / 1.000 | 100.0% / 1.000 | 8 |
| 21 | Could the Gospel of Thomas preserve authentic sayings of Jesus? | **Metadata Ask AI 2** | 100.0% / 0.763 | 100.0% / 0.943 | 4 |
| 22 | Were Gnostic Christians a single unified movement? | **Metadata Ask AI 2** | 100.0% / 0.820 | 80.0% / 0.901 | 6 |
| 23 | How did Constantine affect the development of Christianity? | **Metadata Ask AI 2** | 100.0% / 0.860 | 100.0% / 0.935 | 7 |
| 24 | What was the Arian controversy about, and how did it shape the Trinity? | **Original Ask AI 2** | 100.0% / 0.940 | 100.0% / 0.897 | 7 |
| 25 | How widespread was Roman persecution of Christians before Constantine? | **Original Ask AI 2** | 100.0% / 0.996 | 100.0% / 0.911 | 5 |
| 26 | What are the earliest surviving manuscripts of the New Testament? | **Tie** | 100.0% / 0.844 | 100.0% / 0.838 | 5 |
| 27 | Did scribes alter New Testament passages to support particular theological beliefs? | **Metadata Ask AI 2** | 100.0% / 0.817 | 100.0% / 0.955 | 5 |
| 28 | Why does the King James Bible contain readings scholars now reject? | **Metadata Ask AI 2** | 100.0% / 0.807 | 100.0% / 0.922 | 5 |
| 29 | How can scholars tell whether an ancient Christian writing was forged? | **Metadata Ask AI 2** | 100.0% / 0.793 | 100.0% / 0.854 | 3 |
| 30 | What is the strongest historical evidence that Jesus really existed? | **Metadata Ask AI 2** | 100.0% / 0.955 | 100.0% / 1.000 | 9 |
| 31 | How do scholars decide who wrote works such as 1 Clement and the Letter of Barnabas? | **Metadata Ask AI 2** | 80.0% / 0.631 | 80.0% / 0.818 | 3 |
| 32 | What obligations do Christians have to give money to people in need? | **Original Ask AI 2** | 100.0% / 0.916 | 100.0% / 0.871 | 6 |
| 33 | What important early Christian writings have been lost or survived only in fragments? | **Original Ask AI 2** | 100.0% / 1.000 | 60.0% / 0.637 | 2 |
| 34 | How did anti-Jewish ideas enter early Christian writings? | **Original Ask AI 2** | 100.0% / 1.000 | 100.0% / 0.859 | 5 |
| 35 | Can several people genuinely share the same religious vision? | **Original Ask AI 2** | 100.0% / 0.952 | 100.0% / 0.915 | 8 |
| 36 | What did everyday belief and practice look like in the earliest Christian communities? | **Metadata Ask AI 2** | 100.0% / 0.932 | 100.0% / 0.980 | 6 |
| 37 | How do historians reconstruct Jesus' life when the surviving sources disagree? | **Tie** | 100.0% / 0.960 | 100.0% / 0.937 | 7 |
| 38 | How might human memory have changed stories about Jesus before the Gospels were written? | **Tie** | 100.0% / 0.951 | 100.0% / 0.951 | 6 |
| 39 | Why did early Christians think Jesus' death brought salvation? | **Tie** | 100.0% / 0.960 | 100.0% / 0.946 | 8 |
| 40 | Why is Codex Sinaiticus important for understanding the Bible's text? | **Original Ask AI 2** | 100.0% / 0.824 | 60.0% / 0.512 | 2 |
| 41 | Did ancient Jews and Christians believe consciousness continued immediately after death? | **Original Ask AI 2** | 100.0% / 0.879 | 100.0% / 0.838 | 7 |
| 42 | What leadership role did James, the brother of Jesus, have in the early church? | **Metadata Ask AI 2** | 100.0% / 0.785 | 100.0% / 0.879 | 5 |
| 43 | Was the story of Jesus sweating blood originally part of Luke's Gospel? | **Tie** | 100.0% / 1.000 | 100.0% / 1.000 | 9 |
| 44 | Why does Bart describe himself as both an agnostic and an atheist? | **Metadata Ask AI 2** | 100.0% / 0.851 | 100.0% / 0.996 | 8 |
| 45 | What can Paul's letters tell us about his life that Acts does not? | **Metadata Ask AI 2** | 80.0% / 0.693 | 80.0% / 0.881 | 3 |
| 46 | How do New Testament letters advise Christians to respond to persecution and suffering? | **Tie** | 100.0% / 0.909 | 100.0% / 0.883 | 7 |
| 47 | What different explanations did Paul give for how Christ saves people? | **Metadata Ask AI 2** | 100.0% / 0.899 | 100.0% / 1.000 | 7 |
| 48 | Would eyewitness testimony make the Gospel accounts historically reliable? | **Metadata Ask AI 2** | 100.0% / 0.795 | 100.0% / 0.860 | 7 |
| 49 | How is the New Testament taught in a critical university course? | **Original Ask AI 2** | 100.0% / 0.955 | 100.0% / 0.750 | 6 |
| 50 | How did traditions about Mary, the mother of Jesus, develop beyond the New Testament? | **Tie** | 100.0% / 0.907 | 100.0% / 0.931 | 7 |

## Ranked Grades

### 1. Why do scholars think the Gospels were written anonymously?

Winner: **Metadata Ask AI 2**

- **Original:** 1. Why Are the Gospels Anonymous? [3]; 2. Why Didn’t the Gospel Writers Tell Us Who They Were? [3]; 3. Why Was The Gospel of Matthew Attributed to Matthew? [2]; 4. Why Are the Gospels Anonymous? [3]
- **Metadata-enhanced:** 1. Why Are the Gospels Anonymous? [3]; 2. Why The Gospels Are Anonymous [3]; 3. Why Didn’t the Gospel Writers Tell Us Who They Were? [3]; 4. Why Are the Gospels Anonymous? [3]; 5. Anniversary Post #2: Why Were the Gospels Written Anonymously? [3]; 6. Why Would an Ancient Author Write a Book Anonymously? [3]; 7. Did the Gospels Originally Have Titles? [2]; 8. When Did the Gospels Get Their Names? [2]; 9. The Identity of “Matthew” [3]; 10. Why Are The Gospels Called Matthew, Mark, Luke, and John? [2]

### 2. Was the original ending of Mark lost or added later?

Winner: **Tie**

- **Original:** 1. The Ending of Mark in the King James Bible [3]; 2. Snake-Handling and the Gospel of Mark [3]; 3. Famous Passages that Are Not Original: How Do Modern Translators Deal with Them? [3]; 4. Mark and the Resurrection [1]; 5. Jesus’ Death and Resurrection in Mark: Another Blast from the Past [3]; 6. The Gospel of Mark in a Nutshell [1]
- **Metadata-enhanced:** 1. The Ending of Mark in the King James Bible [3]; 2. Snake-Handling and the Gospel of Mark [3]; 3. The Gospel of Mark: Are You Interested in a More Extended Discussion? [3]; 4. Jesus’ Death and Resurrection in Mark: Another Blast from the Past [3]; 5. Mark and the Resurrection [1]; 6. The Gospel of Mark in a Nutshell [1]

### 3. How do Matthew and Luke tell different stories about Jesus' birth?

Winner: **Metadata Ask AI 2**

- **Original:** 1. Jesus’ Birth: Some Comparisons [3]; 2. Jesus’ Birth in Matthew and Luke: A Study in Contrasts [3]; 3. Twelve Days of Christmas Day 9: A Key Contradiction in the Birth Narratives of Jesus [3]; 4. Uh, Duh.  What I SHOULD Have Said.  (Bethlehem) [3]; 5. The Naivety of the Nativity: Platinum Guest Post by Joel Scheller [3]; 6. Why Contradictions Matter for Understanding the Life of Jesus [3]; 7. The Infancy Narratives Compared [3]
- **Metadata-enhanced:** 1. Jesus’ Birth: Some Comparisons [3]; 2. Jesus’ Birth in Matthew and Luke: A Study in Contrasts [3]; 3. Twelve Days of Christmas Day 9: A Key Contradiction in the Birth Narratives of Jesus [3]; 4. A Key Contradiction in the Birth Narratives of Jesus [3]; 5. The Infancy Narratives Compared [3]; 6. Another “True” Story that Didn’t Happen?  Jesus’ Birth in Luke [3]; 7. Why It Didn’t Happen that Way.  The Stories of Jesus’ Birth [3]; 8. Bethlehem and Nazareth in Matthew [3]; 9. Bethlehem and Nazareth in Luke: Where Was Jesus Really Born? [3]; 10. O Little Town of Nazareth? [3]

### 4. What historical evidence is there for Jesus' empty tomb?

Winner: **Original Ask AI 2**

- **Original:** 1. Another Question on the Resurrection [3]; 2. Was Jesus Given a Decent Burial (By Joseph of Arimathea) [2]; 3. The Burial of Jesus: A Blast from the Past [3]; 4. New Thread on the Burial of Jesus [2]; 5. Literary Problems with the Gospel Accounts of Jesus’ Burial [2]; 6. Argument Against Jesus’ Burial in HJBG, Part 2 [2]; 7. More Reasons for Thinking Jesus was Not Given a Decent Burial [2]; 8. Does Archaeological Evidence Show that Jesus Was Buried on the Day He Died? [1]; 9. The Skeletal Remains of Yehohanan and Their Significance [2]; 10. The Skeletal Remains of Yehohanan: Readers Mailbag October 8, 2017 [2]
- **Metadata-enhanced:** 1. Another Question on the Resurrection [3]; 2. New Thread on the Burial of Jesus [2]; 3. The Burial of Jesus: A Blast from the Past [3]; 4. Was Jesus Given a Decent Burial (By Joseph of Arimathea) [2]; 5. Argument Against Jesus’ Burial in HJBG, Part 2 [2]; 6. More Reasons for Thinking Jesus was Not Given a Decent Burial [2]; 7. Was Jesus Given Special Treatment? [1]; 8. Back to Whether Jesus Was Really Given a Decent Burial [1]; 9. Resurrection Narratives in the Gospels [2]; 10. Jesus’ Death and Resurrection in Mark: Another Blast from the Past [2]

### 5. Why do Matthew and Acts describe Judas' death differently?

Winner: **Original Ask AI 2**

- **Original:** 1. But How Did Judas Die? [3]; 2. The Death of Judas in the NT [3]; 3. Can We Know Anything Historically About How Judas Iscariot Died? [3]; 4. Can We Know Anything About Judas Iscariot? [1]; 5. The Quest for the Historical … Judas Iscariot [1]; 6. Did Judas Really Betray Jesus?  Readers’ Mailbag [1]; 7. How Can Paul Say that Jesus Appeared to The Twelve? [2]; 8. Does Paul Know about Judas Iscariot? [1]; 9. Does Paul Know that Judas Betrayed Jesus? [1]; 10. Why Did Judas Iscariot Betray Jesus? [1]
- **Metadata-enhanced:** 1. But How Did Judas Die? [3]; 2. The Death of Judas in the NT [3]; 3. Can We Know Anything Historically About How Judas Iscariot Died? [3]; 4. Can We Know Anything About Judas Iscariot? [1]; 5. More on Judas [2]; 6. Did Judas Really Betray Jesus?  Readers’ Mailbag [1]; 7. The Quest for the Historical … Judas Iscariot [1]

### 6. Why is the woman caught in adultery missing from the earliest manuscripts of John?

Winner: **Metadata Ask AI 2**

- **Original:** 1. The Woman Taken in Adultery in the King James Version [3]; 2. Did Jesus Write Anything in the New Testament? [3]; 3. Why Don’t People See Discrepancies in the Bible?  Readers’ Mailbag October 15, 2016 [3]; 4. Major Scribal Corruptions in the New Revised Standard Version [2]; 5. Problems with the King James Version: What Were the Translators Translating? [3]; 6. Where Did the King James Bible Come From? [2]; 7. How the Trinity Got Into the New Testament: Part 2 [2]; 8. Intentional Changes of the Text [2]; 9. How Accurate Are our Earliest NT Manuscripts? [1]
- **Metadata-enhanced:** 1. The Woman Taken in Adultery in the King James Version [3]; 2. Did Jesus Write Anything in the New Testament? [3]; 3. Why Don’t People See Discrepancies in the Bible?  Readers’ Mailbag October 15, 2016 [3]; 4. Where Did the King James Bible Come From? [2]; 5. Problems with the King James Version: What Were the Translators Translating? [3]; 6. Major Scribal Corruptions in the New Revised Standard Version [2]; 7. Are Bible Translators Consistent?  Readers’ Mailbag [2]; 8. Why Do Translators Include Passages They Know Are Not Original? [2]; 9. Famous Passages that Are Not Original: How Do Modern Translators Deal with Them? [2]; 10. Intentional Changes of the Text [2]

### 7. How does Paul's account of his conversion differ from the story in Acts?

Winner: **Metadata Ask AI 2**

- **Original:** 1. The Conversion of Paul [3]; 2. Paul in Acts: Part 2 [3]; 3. After Paul Converted…  Does the Book of Acts Contradict Paul Himself? [3]; 4. Does the Book of Acts Accurately Portray the Life and Teachings of Paul? [3]; 5. The Historical Accuracy of Acts [3]; 6. Is the Book of Acts Historically Reliable?  The Negative Case. [3]; 7. The Book of Acts is NOT RELIABLE!  The Negative Case [3]
- **Metadata-enhanced:** 1. The Conversion of Paul [3]; 2. Paul in Acts: Part 2 [3]; 3. The Book of Acts is NOT RELIABLE!  The Negative Case [3]; 4. Is the Book of Acts Historically Reliable?  The Negative Case. [3]; 5. After Paul Converted…  Does the Book of Acts Contradict Paul Himself? [3]; 6. Does the Book of Acts Accurately Portray the Life and Teachings of Paul? [3]; 7. The Historical Accuracy of Acts [3]; 8. The Acts of the Apostles:  Who Wrote It, When, and Why? [3]; 9. The Life of Paul in a Nutshell [3]; 10. Was Paul Authorized to Persecute Christians? [3]

### 8. Why do scholars question whether Paul wrote Colossians?

Winner: **Tie**

- **Original:** 1. Did Paul Write Colossians? According to Most Scholars No – Paul did Not Write Colossians [3]; 2. Not for the Faint of Heart (Authorship of Colossians) [3]; 3. The Letter to the Colossians: Who, When, and Why? [3]; 4. Did Paul Write That Letter?  Getting Into the Weeds… [3]; 5. The Letter to the Colossians, in a Nutshell [1]; 6. The DeuteroPauline Epistles “At a Glance,” With Questions for Reflection [2]; 7. Colossians: For Further Reading [1]; 8. Weekly Readers’ Mailbag:  January 16, 2016 [2]; 9. Ephesians:  For Further Reading [1]
- **Metadata-enhanced:** 1. Did Paul Write Colossians? According to Most Scholars No – Paul did Not Write Colossians [3]; 2. Not for the Faint of Heart (Authorship of Colossians) [3]; 3. The Letter to the Colossians: Who, When, and Why? [3]; 4. Did Paul Write That Letter?  Getting Into the Weeds… [3]; 5. The Letter to the Colossians, in a Nutshell [1]; 6. The DeuteroPauline Epistles “At a Glance,” With Questions for Reflection [2]; 7. Weekly Readers’ Mailbag:  January 16, 2016 [2]; 8. Colossians: For Further Reading [1]; 9. Ephesians:  For Further Reading [1]

### 9. What did Paul teach about women speaking and leading in church?

Winner: **Metadata Ask AI 2**

- **Original:** 1. Knowing Paul’s Views of Women… [3]; 2. The Silencing of Women: 1 Cor. 14:34-35 as an Interpolation [3]; 3. Was Paul a Misogynist? [3]; 4. Women in the Churches of Paul [3]; 5. Was Paul a Misogynist? [3]; 6. Women Apostles in Early Christianity [2]; 7. Paul and Women Apostles [3]; 8. After the New Testament: Women in Early Christianity [2]; 9. Paul, the Pastorals, and Women [3]; 10. Paul the Misogynist?  The Alternative Perspective [3]
- **Metadata-enhanced:** 1. The Silencing of Women: 1 Cor. 14:34-35 as an Interpolation [3]; 2. The Non-Pauline Oppression of Women [3]; 3. Was Paul a Misogynist? [3]; 4. Paul, the Pastorals, and Women [3]; 5. Knowing Paul’s Views of Women… [3]; 6. Were Paul’s Views of Women Oppressive? [2]; 7. Women in the Churches of Paul [3]; 8. Was Paul a Misogynist? [3]; 9. Did Paul Favor Gender Equality? [3]; 10. Paul’s View of Women in the Church [3]

### 10. Do James and Paul disagree about faith and works?

Winner: **Metadata Ask AI 2**

- **Original:** 1. Does James Contradict Paul? [3]; 2. Is the Book of James Attacking the Teachings of Paul? [3]; 3. Is the Author of James Rejecting Paul Himself? [3]; 4. Is James Responding to Paul? [3]; 5. Why Would Someone Forge the Letter of James? [3]; 6. The Close Connections of James and Paul [3]; 7. The Book of James in a Nutshell [3]; 8. Hebrews and James:  “At a Glance” and “Questions for Reflection” [3]; 9. One of My Favorite Letters in the New Testament: The Book of James [3]; 10. Was Paul Really at Odds with Peter and James?  Guest Post by Richard Fellows [1]
- **Metadata-enhanced:** 1. Does James Contradict Paul? [3]; 2. Is the Book of James Attacking the Teachings of Paul? [3]; 3. Is the Author of James Rejecting Paul Himself? [3]; 4. The Book of James in a Nutshell [3]; 5. Is James Responding to Paul? [3]; 6. The Close Connections of James and Paul [3]; 7. Why Would Someone Forge the Letter of James? [3]; 8. Hebrews and James:  “At a Glance” and “Questions for Reflection” [3]; 9. Why Did the Author of James Claim to be James in Particular? [3]; 10. One of My Favorite Letters in the New Testament: The Book of James [3]

### 11. What does the Christ poem in Philippians say about Jesus before his birth?

Winner: **Metadata Ask AI 2**

- **Original:** 1. Is the “Christ Poem” of Philippians Really a Poem?  When Did Jesus Really Become “Equal” With God? [3]; 2. One of the Most Important Passages of the NT: Paul’s Christ Poem [3]; 3. One of the Most Significant Passages in the NT: Paul’s Christ Poem [3]; 4. The Pre-pauline “Poem” in Philippians 2 [3]; 5. More on the Philippians Christ-Poem [3]; 6. A Fuller Exposition of the Christ Poem in Philippians [3]; 7. More Comments on Paul’s Rather Astounding Christ Poem [3]; 8. The Most Widely Discussed Passage of Philippians [3]; 9. How Ancient is the Idea of Christ’s “Incarnation”? [3]; 10. Did Paul Really Have *That* Exalted a View of Jesus? [2]
- **Metadata-enhanced:** 1. Is the “Christ Poem” of Philippians Really a Poem?  When Did Jesus Really Become “Equal” With God? [3]; 2. One of the Most Important Passages of the NT: Paul’s Christ Poem [3]; 3. One of the Most Significant Passages in the NT: Paul’s Christ Poem [3]; 4. More on the Philippians Christ-Poem [3]; 5. A Fuller Exposition of the Christ Poem in Philippians [3]; 6. Final Thoughts on the Philippians Christ-Poem [3]; 7. How Ancient is the Idea of Christ’s “Incarnation”? [3]; 8. How Jesus Became God: My Change of Direction [3]; 9. More Comments on Paul’s Rather Astounding Christ Poem [3]; 10. The Most Widely Discussed Passage of Philippians [3]

### 12. Were Peter and Cephas the same person?

Winner: **Metadata Ask AI 2**

- **Original:** 1. Were Peter and Cephas the Same Person? [3]; 2. Are Cephas and Peter Two Different People? [3]; 3. Were Cephas and Peter Two Different People?  A Blast from the Past [3]; 4. Finally: Cephas and Peter.  What Do I Really Think? [3]; 5. Some Evidence that Cephas and Peter WERE Two Different People [3]; 6. Did Paul Get Along with the Other Apostles? [1]; 7. Did Paul Know that Peter and Cephas were Two Different People? [3]; 8. Was Cephas Peter?  The Rest of the Argument [3]; 9. Cephas and Peter: Final Arguments, Summary, and Implications [3]; 10. More Hints that Cephas Was Not Peter [3]
- **Metadata-enhanced:** 1. Were Peter and Cephas the Same Person? [3]; 2. Are Cephas and Peter Two Different People? [3]; 3. Were Cephas and Peter Two Different People?  A Blast from the Past [3]; 4. Finally: Cephas and Peter.  What Do I Really Think? [3]; 5. Flipping a Coin. Cephas and Peter: One Person or Two? [3]; 6. Was Cephas Peter?  The Rest of the Argument [3]; 7. Cephas and Peter in the Writings of Paul (Who Knew Them) [3]; 8. Did Paul Know that Peter and Cephas were Two Different People? [3]; 9. Some Evidence that Cephas and Peter WERE Two Different People [3]; 10. More Hints that Cephas Was Not Peter [3]

### 13. Was Mary Magdalene really a prostitute?

Winner: **Tie**

- **Original:** 1. Was Mary Magdalene a Prostitute? [3]; 2. When Did Mary Magdalene Become a Prostitute? [3]; 3. Mary Magdalene as a Prostitute? [3]; 4. Mary Magdalene in Various Guises [3]
- **Metadata-enhanced:** 1. Was Mary Magdalene a Prostitute? [3]; 2. When Did Mary Magdalene Become a Prostitute? [3]; 3. Mary Magdalene as a Prostitute? [3]; 4. Mary Magdalene in Various Guises [3]

### 14. Did Jesus expect the world to end during his generation?

Winner: **Metadata Ask AI 2**

- **Original:** 1. The Preaching of Jesus in a Nutshell [3]; 2. Albert Schweitzer and the Apocalyptic Jesus [3]; 3. Albert Schweitzer and the Apocalyptic Jesus [3]; 4. The Jesus Seminar and the Non-Apocalyptic Jesus. Hey, Why Not? [3]; 5. Did Jesus Believe the End Would Come Within his Lifetime? Maybe Not!  Platinum Post by Rizwan Ahmed [3]; 6. Did Jesus Believe The End Would Come Within His Lifetime? Platinum Post by Rizwan Ahmed [3]; 7. Jesus’ Apocalyptic Message in Matthew [2]; 8. Mark 13:30–a New Argument for an Old Hypothesis. A Platinum Post From Omar Robb [3]; 9. Jesus’s Apocalyptic View of Destruction [3]; 10. The Apocalyptic Background to Jesus’ Messiahship [2]
- **Metadata-enhanced:** 1. The Preaching of Jesus in a Nutshell [3]; 2. The Heart of Jesus’ Message [3]; 3. Was Jesus A Great Moral Teacher?  A Blast From the Past [3]; 4. Jesus’ Teaching About the Kingdom of God [3]; 5. The Teaching of Jesus [3]; 6. Who Was Jesus? [3]; 7. What Would an Apocalyptic Jew (Jesus!) Mean By Calling Himself Messiah? [3]; 8. Albert Schweitzer and the Apocalyptic Jesus [3]; 9. The Apocalyptic Background to Jesus’ Messiahship [2]; 10. Major Perspectives of Ancient Jewish (and Jesus’!) Apocalyptic Views [3]

### 15. How can historians evaluate miracle stories about Jesus?

Winner: **Metadata Ask AI 2**

- **Original:** 1. Historians and the Problem of Miracle [3]; 2. More on the Historical Problem of Miracles [3]; 3. History is not the Past!  Proving Jesus’ Resurrection and Other Miracles [3]; 4. Can We Get Rid of Our Presuppositions? [3]; 5. Eyewitness Accounts of Miracles [2]; 6. Once More on the Credibility of Miracles: Guest post by Darren Slade [2]; 7. Resurrection Narratives in the Gospels [3]; 8. Q & A about Jesus Before the Gospels, Part 1 [1]; 9. Q & A about Jesus Before the Gospels, Part 3 [1]; 10. Does Understanding “Memory” Have Any Bearing on the Study of the Historical Jesus? [2]
- **Metadata-enhanced:** 1. Historians and the Problem of Miracle [3]; 2. More on the Historical Problem of Miracles [3]; 3. History is not the Past!  Proving Jesus’ Resurrection and Other Miracles [3]; 4. What Can We Do About Presuppositions? [3]; 5. Can We Get Rid of Our Presuppositions? [3]; 6. More on the Case Against Miracles: Michael Shermer Guest Post [2]; 7. Eyewitness Accounts of Miracles [2]; 8. Is History Possible? [1]; 9. Resurrection Narratives in the Gospels [3]; 10. Q & A about Jesus Before the Gospels, Part 1 [1]

### 16. What did Jesus mean by the Kingdom of God?

Winner: **Metadata Ask AI 2**

- **Original:** 1. Jesus’ Teaching About the Kingdom of God [3]; 2. Who Was Jesus? [3]; 3. The Preaching of Jesus in a Nutshell [3]; 4. The Message of Jesus [3]; 5. The Teaching of Jesus [3]; 6. Jesus’ Apocalyptic Message in Matthew [2]; 7. Was Jesus a Great Moral Teacher? [2]; 8. Jesus’ Teachings on Love and Salvation [2]; 9. Jesus and the Son of Man [2]; 10. What Would an Apocalyptic Jew (Jesus!) Mean By Calling Himself Messiah? [2]
- **Metadata-enhanced:** 1. Jesus’ Teaching About the Kingdom of God [3]; 2. The Heart of Jesus’ Message [3]; 3. The Preaching of Jesus in a Nutshell [3]; 4. The Message of Jesus [3]; 5. The Teaching of Jesus [3]; 6. The Jesus Seminar and the Non-Apocalyptic Jesus. Hey, Why Not? [3]; 7. Jesus’ Apocalyptic Message in Matthew [2]; 8. The Apocalyptic Context for Jesus’ View of the Messiah [3]; 9. What Would an Apocalyptic Jew (Jesus!) Mean By Calling Himself Messiah? [2]; 10. Jesus and the Son of Man [2]

### 17. Why does an all-powerful and loving God allow innocent people to suffer?

Winner: **Original Ask AI 2**

- **Original:** 1. The Problem of Suffering? So What’s the Problem? [3]; 2. The Classic “Problem” of Suffering [3]; 3. Seeing the Problem of Suffering as a PROBLEM [3]; 4. Suffering. Is It Really Worth Talking About? Doesn’t the Bible Give the Right Answer? [3]; 5. My Struggle With Why There Is Suffering [3]; 6. Why Do Some Smart People Just Not Think? [2]; 7. Hurricanes, Suffering, And My Loss of Faith [3]; 8. Human Suffering and the Christian Faith [3]; 9. Leaving the Faith [3]; 10. Bart Ehrman on Problem of Suffering – UCB [3]
- **Metadata-enhanced:** 1. The Problem of Suffering? So What’s the Problem? [3]; 2. Seeing the Problem of Suffering as a PROBLEM [3]; 3. The Classic “Problem” of Suffering [3]; 4. The Kind of Suffering that is a Problem [3]; 5. Hurricanes, Suffering, And My Loss of Faith [3]; 6. Why Do Some Smart People Just Not Think? [2]; 7. Bart Ehrman on Problem of Suffering – UCB [3]; 8. Past and Present: One of My Debates with Dinesh D’Souza [1]; 9. Suffering and My Blog [3]; 10. My Struggle With Why There Is Suffering [3]

### 18. When did Christians begin believing that souls go immediately to heaven or hell?

Winner: **Metadata Ask AI 2**

- **Original:** 1. (Later) Early Christian Understandings of Heaven and Hell [3]; 2. Heaven and Hell, Part Two [3]; 3. How The Afterlife Changed After Jesus’ Life [3]; 4. Heaven and Hell: When was Heaven and Hell Invented? [3]; 5. The Invention of the Afterlife: Request for Ideas! [1]; 6. Does Your Soul Go To Heaven? [3]
- **Metadata-enhanced:** 1. (Later) Early Christian Understandings of Heaven and Hell [3]; 2. Heaven and Hell, Part Two [3]; 3. Q&A on Heaven and Hell [3]; 4. Does Your Soul Go To Heaven? [3]; 5. Free Course on … Hell! [3]; 6. Heaven and Hell, Finally [3]; 7. Heaven and Hell: Press Release!! [1]

### 19. What is the main message of the Book of Revelation?

Winner: **Tie**

- **Original:** 1. The Book of Revelation as an Apocalypse [3]; 2. The Book of Revelation in a Nutshell [3]; 3. The Revelation of John at a Glance, with Questions for Reflection [3]; 4. Understanding the Apocalypse as an “Apocalypse” [3]; 5. Understanding the Book of Revelation as an Apocalypse [3]; 6. The Book of Revelation and the END.   Starting at the Beginning. [3]; 7. The Book of Revelation: When and Why? [3]; 8. The Book of Revelation and the Apocalypse Genre [2]; 9. Apocalypse (the genre) and Apocalypticism (the worldview) [2]; 10. Apocalypticism and Apocalypses [2]
- **Metadata-enhanced:** 1. The Book of Revelation as an Apocalypse [3]; 2. Understanding the Apocalypse as an “Apocalypse” [3]; 3. Understanding the Book of Revelation as an Apocalypse [3]; 4. The Book of Revelation in a Nutshell [3]; 5. The Revelation of John at a Glance, with Questions for Reflection [3]; 6. Overview of the Book of Revelation [3]; 7. The Book of Revelation and the END.   Starting at the Beginning. [3]; 8. The Book of Revelation and the Apocalypse Genre [2]; 9. Understanding Revelation: A Sine Qua Non (Overlooked by most readers) [2]; 10. Revelation is an Apocalypse.  What Is An Apocalypse? [2]

### 20. How did Christians decide which books belonged in the New Testament?

Winner: **Tie**

- **Original:** 1. How Did They Decide Which Books to Include in the New Testament Canon? [3]; 2. How and When Did Christians Decide What Should Be in the New Testament Canon? [3]; 3. Deciding on Which Books Should Be in the New Testament [3]; 4. How Did We Get *These* 27 Books in the New Testament? [3]; 5. How Did We Get The 27 Books of the New Testament? [3]; 6. How We Got the New Testament (and not some other books!) [3]; 7. Why and When Did We Get This Canon of the New Testament? [3]; 8. Question on How We Got the Canon of the New Testament [3]; 9. Why Did Early Christians Want a New Canon of Scripture? [3]; 10. Why Did We Get a New Testament? [3]
- **Metadata-enhanced:** 1. How Did They Decide Which Books to Include in the New Testament Canon? [3]; 2. How and When Did Christians Decide What Should Be in the New Testament Canon? [3]; 3. Deciding on Which Books Should Be in the New Testament [3]; 4. How Did We Get *These* 27 Books in the New Testament? [3]; 5. How Did We Get The 27 Books of the New Testament? [3]; 6. How We Got the New Testament (and not some other books!) [3]; 7. Why and When Did We Get This Canon of the New Testament? [3]; 8. Why Were Some of the Earliest Christian Books Left OUT of the NT? [3]; 9. When Did We Get the Final Canon of the New Testament? [3]; 10. Question on How We Got the Canon of the New Testament [3]

### 21. Could the Gospel of Thomas preserve authentic sayings of Jesus?

Winner: **Metadata Ask AI 2**

- **Original:** 1. Thomas, the Synoptic Gospels, and Q [3]; 2. Could the Gospel of Thomas Be Q?  Could it Be Older Than the NT Gospels? [3]; 3. The Gospel of Thomas and the Other Gospels [3]; 4. Thomas and the Other Gospels [2]; 5. Traditions About Jesus that Are Probably Not Historical [2]; 6. The Jesus Seminar and the Non-Apocalyptic Jesus. Hey, Why Not? [2]; 7. What About Accurately Preserved *Oral* Traditions? [1]; 8. How Reliable are Oral Traditions? [1]; 9. Stories of Jesus Passed on By Word of Mouth.  When Scholars First Took Oral Traditions Seriously. [1]
- **Metadata-enhanced:** 1. Thomas, the Synoptic Gospels, and Q [3]; 2. Could the Gospel of Thomas Be Q?  Could it Be Older Than the NT Gospels? [3]; 3. Q and The Gospel of Thomas [3]; 4. The Gospel of Thomas and the Other Gospels [3]; 5. The Most Famous Non-Canonical Gospel: The Gospel of Thomas [2]; 6. Our Most Important Gospel from Outside the NT: The Gospel of Thomas [2]; 7. Thomas: The Most Important Gospel Outside the New Testament [3]; 8. Lost Gospels That Are Still Lost 4: Q [1]; 9. Ever Hear of an Agraphon?  An “Unwritten” Saying of Jesus? [1]; 10. Traditions About Jesus that Are Probably Not Historical [2]

### 22. Were Gnostic Christians a single unified movement?

Winner: **Metadata Ask AI 2**

- **Original:** 1. Some of the Difficulties in Understanding Gnosticism [3]; 2. New Discussion of Gnosticism [3]; 3. My New Discussion of Gnosticism: Introduction [3]; 4. Our Knowledge of Gnosticism [3]; 5. What Is Gnosticism? [3]; 6. Lost Christianities [1]; 7. How Diverse Was Early Christianity? [2]; 8. A Different Kind of Gnostic:  The Valentinians [2]; 9. The Valentinian Gnostics [2]; 10. The Sethian Gnostics [1]
- **Metadata-enhanced:** 1. New Discussion of Gnosticism [3]; 2. What Is Gnosticism? [3]; 3. Some of the Difficulties in Understanding Gnosticism [3]; 4. My New Discussion of Gnosticism: Introduction [3]; 5. Lost Christianities [1]; 6. Thomasine Gnostic Christians, and Sundry Others [3]; 7. Thomasine Christians and Others, From After the New Testament [3]; 8. Thomasine “Gnostics” and Others [3]; 9. A Different Kind of Gnostic:  The Valentinians [2]; 10. Doesn’t the New Testament Show that Christianity Was Originally Unified? [1]

### 23. How did Constantine affect the development of Christianity?

Winner: **Metadata Ask AI 2**

- **Original:** 1. Constantine and Christianity [3]; 2. Constantine and the Christian Faith: My Fourth Smithsonian Lecture [2]; 3. The Conversion of the Emperor Constantine [3]; 4. The Conversion of Constantine [3]; 5. The Conversion of Constantine and Beyond [3]; 6. The Council of Nicaea and The Resulting View of Christ [3]; 7. Why Did “Orthodox” Christianity Win: Part 2 [1]; 8. When Christianity Became the “Official” Religion of Rome [2]; 9. The Beginning of the End of Paganism [2]; 10. The Rise of Christian Anti-Judaism, in a Nutshell [1]
- **Metadata-enhanced:** 1. Constantine and Christianity [3]; 2. The Conversion of the Emperor Constantine [3]; 3. The Conversion of Constantine [3]; 4. Constantine and the Christian Faith: My Fourth Smithsonian Lecture [2]; 5. The Conversion of Constantine and Beyond [3]; 6. The Triumph of Christianity: The Ultimate Question [0]; 7. The Beginning of the End of Paganism [2]; 8. Guest Post by Dr. Paula Fredriksen Part II: The Politics of Piety [3]; 9. The Council of Nicaea and The Resulting View of Christ [3]; 10. The Son of God, the Council of Nicea, and the Da Vinci Code [2]

### 24. What was the Arian controversy about, and how did it shape the Trinity?

Winner: **Original Ask AI 2**

- **Original:** 1. A Heresy that May Not Sound Heretical to You:  Arius of Alexandria [3]; 2. The Council of Nicaea and The Resulting View of Christ [3]; 3. The Controversies about Christ: Arius and Alexander [3]; 4. You Call *This* a Heresy?  The Views of Arius, In His Own Words [2]; 5. Widespread Misconceptions about the Council of Nicea [3]; 6. The Road from the “Duo of Philo” to the “Trinity of Nicaea”–Guest Post by Omar Robb [3]; 7. The Trinity!  A Final Summation [3]; 8. The Doctrine of the Trinity: Where We Are So Far [2]
- **Metadata-enhanced:** 1. A Heresy that May Not Sound Heretical to You:  Arius of Alexandria [3]; 2. The Controversies about Christ: Arius and Alexander [3]; 3. The Council of Nicaea and The Resulting View of Christ [3]; 4. You Call *This* a Heresy?  The Views of Arius, In His Own Words [2]; 5. Widespread Misconceptions about the Council of Nicea [3]; 6. The Son of God, the Council of Nicea, and the Da Vinci Code [2]; 7. The Trinity!  A Final Summation [3]; 8. The Doctrine of the Trinity: Where We Are So Far [2]; 9. How Can the Father and the Son Be the SAME?  Can Your Father Also Be Your Son? [1]

### 25. How widespread was Roman persecution of Christians before Constantine?

Winner: **Original Ask AI 2**

- **Original:** 1. Early Persecutions of Christians, in a Nutshell [3]; 2. The First Attempts to Wipe Out Christianity [3]; 3. When Emperors Became More Involved in Christian Persecutions [3]; 4. How Significant Was Early Christianity? [3]; 5. Were Christians Statistically Insignificant in the First 200 years? [3]; 6. The Growth of Early Christianity: A Clarification [1]; 7. The Rise of Christian Anti-Judaism, in a Nutshell [1]; 8. Did the Glories of Martyrdom Lead to Christian Conversions? [2]; 9. Heightened Opposition to Jews in Early Christianity [0]; 10. The Historical Background to the Book of Revelation [1]
- **Metadata-enhanced:** 1. Early Persecutions of Christians, in a Nutshell [3]; 2. The First Attempts to Wipe Out Christianity [3]; 3. When Emperors Became More Involved in Christian Persecutions [3]; 4. How Significant Was Early Christianity? [3]; 5. Were Christians Statistically Insignificant in the First 200 years? [3]

### 26. What are the earliest surviving manuscripts of the New Testament?

Winner: **Tie**

- **Original:** 1. What We Now Know about the Manuscripts of the New Testament [3]; 2. New Testament Manuscripts:  Good News and Bad News [3]; 3. The Irony of our Earliest Manuscripts [3]; 4. Introduction to the Manuscripts of the New Testament [3]; 5. The Greek Manuscripts of the New Testament [2]; 6. What the New Fragment of Mark’s Gospel Looks like (the so-called First-Century Mark) [2]; 7. Not a Game-Changer?  Why I’d Still Be Thrilled to Have a First-Century Gospel Fragment [1]; 8. How Would a First-Century Fragment of the Gospels Actually Change What We Know/Think? [1]; 9. How Useful Are Our Earliest New Testament Manuscripts? [3]; 10. The Manuscripts of the New Testament [2]
- **Metadata-enhanced:** 1. The Irony of our Earliest Manuscripts [3]; 2. How Useful Are Our Earliest New Testament Manuscripts? [3]; 3. How Accurate Are our Earliest NT Manuscripts? [3]; 4. Introduction to the Manuscripts of the New Testament [3]; 5. New Testament Manuscripts:  Good News and Bad News [3]; 6. The Letters of Paul: Mailbag April 1, 2016 [2]; 7. What the New Fragment of Mark’s Gospel Looks like (the so-called First-Century Mark) [2]; 8. Not a Game-Changer?  Why I’d Still Be Thrilled to Have a First-Century Gospel Fragment [1]; 9. Recent Manuscript Discoveries: A Blast from the Past [1]; 10. The Text of the New Testament:  Are the Textual Traditions of Other Ancient Works Relevant?  A Blast From the Past [1]

### 27. Did scribes alter New Testament passages to support particular theological beliefs?

Winner: **Metadata Ask AI 2**

- **Original:** 1. What is An Orthodox Corruption of Scripture? [3]; 2. Back to the Question:  The Orthodox Corruption of Scripture [3]; 3. How Consistent are Orthodox Corruptions of Scripture? [3]; 4. New Testament Manuscripts That Reveal Later Theological Controversies [3]; 5. Why Scribes Changed Their Manuscripts [3]; 6. Why Intentional Changes of the Text Might Matter [2]; 7. How Can You Know A Scribe’s Intentions? [2]; 8. How Can You Tell If the Text Has Been CHANGED? [2]; 9. Intentional Changes of the Text [1]; 10. Anti-Jewish Alterations of the New Testament Writings? [3]
- **Metadata-enhanced:** 1. What is An Orthodox Corruption of Scripture? [3]; 2. Back to the Question:  The Orthodox Corruption of Scripture [3]; 3. How Consistent are Orthodox Corruptions of Scripture? [3]; 4. New Testament Manuscripts That Reveal Later Theological Controversies [3]; 5. Anti-Jewish Alterations of the New Testament Writings? [3]; 6. Scribes Who Changed Their Texts on Purpose [2]; 7. Scribes Who Changed the Voice at Jesus Baptism? [3]; 8. Why Did Scribes Add the Bloody Sweat? [3]; 9. Did Scribes Add the Passage of the Bloody Sweat? [3]; 10. Scribes Who Injected the Idea of Atonement into Luke’s Gospel [3]

### 28. Why does the King James Bible contain readings scholars now reject?

Winner: **Metadata Ask AI 2**

- **Original:** 1. Problems with the King James Version: What Were the Translators Translating? [3]; 2. Where Did the King James Bible Come From? [3]; 3. Textual Problems with the King James: The Trinity [3]; 4. The Trinity in the King James Bible [3]; 5. The Ending of Mark in the King James Bible [3]; 6. Printing Errors in the King James Version [1]; 7. Infamous Typos in the King James Bible [1]; 8. Problems with Some Bible Translations, including the King James: A Blast from the Past [3]; 9. What Kind of a Text is the King James Bible? [2]; 10. The King James Bible: Some Intriguing Word Choices [1]
- **Metadata-enhanced:** 1. Problems with the King James Version: What Were the Translators Translating? [3]; 2. Where Did the King James Bible Come From? [3]; 3. Textual Problems with the King James: The Trinity [3]; 4. The Woman Taken in Adultery in the King James Version [3]; 5. The Ending of Mark in the King James Bible [3]; 6. The Trinity in the King James Bible [3]; 7. Do Most Manuscripts Have the Original Text? [3]; 8. Don’t the MOST Manuscripts Show What An Author Wrote? [3]; 9. Responses to Misquoting Jesus: Readers’ Mailbag [1]; 10. Why Textual Variants Matter Even for Those Who Do NOT Think the Bible is Infallible [1]

### 29. How can scholars tell whether an ancient Christian writing was forged?

Winner: **Metadata Ask AI 2**

- **Original:** 1. Were Ancient Readers Interested in Detecting Forgeries? [3]; 2. My Lecture in Quebec:  Did Ancient Authors Try To Deceive Their Readers? [3]; 3. Does a Different “Writing Style” Show (convincingly?) That Ephesians is a “Forgery”? [3]; 4. How Did Ancient Writers Use Secretaries?  A Blast from the Past [2]; 5. How Can You Tell If the Text Has Been CHANGED? [2]; 6. Why Scribes Changed Their Manuscripts [1]; 7. How Did Scribes Change Their Manuscripts? [1]; 8. What Motivated Some Ancient Authors to Lie About Themselves? [2]; 9. How Can You Know A Scribe’s Intentions? [1]; 10. A Recent Argument that Ancient Pseudepigraphy Was NOT Deceptive (or Meant to Be) [2]
- **Metadata-enhanced:** 1. Were Ancient Readers Interested in Detecting Forgeries? [3]; 2. Video:  Forgery in the New Testament [2]; 3. Forgery Lecture [2]; 4. Forged Books, Anonymous Books, and The Use of Secretaries as Authors in the NT [2]; 5. Does a Different “Writing Style” Show (convincingly?) That Ephesians is a “Forgery”? [3]; 6. 2 Thessalonians: When Scholars Began To Doubt It Was Authentic [2]; 7. Jude as Pseudepigraphic (i.e., forged) [3]; 8. How Would an Early Christian “Know” Which Books Peter Wrote? [2]; 9. New Boxes Related to Literary Forgery and the NT [3]; 10. How Did Ancient Writers Use Secretaries?  A Blast from the Past [2]

### 30. What is the strongest historical evidence that Jesus really existed?

Winner: **Metadata Ask AI 2**

- **Original:** 1. Evidence for Jesus Outside the New Testament: Part 2 of My Exchange with Ben Witherington [3]; 2. Some Key Evidence for Jesus [3]; 3. Paul and the Historical Jesus [3]; 4. Paul’s Acquaintances: Jesus’ Disciples and Brother [3]; 5. What Did Paul Know About the Historical Jesus? [3]; 6. Did Jesus Exist?  My Debate with Robert Price [2]; 7. Multiple Attestation for Jesus [3]; 8. The Gospels and the Existence of Jesus [3]; 9. Q & A with Ben Witherington: Part 4 [3]; 10. Non-Christian Sources for Jesus: An Interview with History.com [3]
- **Metadata-enhanced:** 1. Paul’s Acquaintances: Jesus’ Disciples and Brother [3]; 2. Paul and the Historical Jesus [3]; 3. What Did Paul Know About the Historical Jesus? [3]; 4. Did Paul Know Much about the Historical Jesus? [3]; 5. Evidence for Jesus Outside the New Testament: Part 2 of My Exchange with Ben Witherington [3]; 6. Q & A with Ben Witherington: Part 4 [3]; 7. Some Key Evidence for Jesus [3]; 8. The Gospels and the Existence of Jesus [3]; 9. Multiple Attestation for Jesus [3]; 10. Non-Christian Sources for Jesus: An Interview with History.com [3]

### 31. How do scholars decide who wrote works such as 1 Clement and the Letter of Barnabas?

Winner: **Metadata Ask AI 2**

- **Original:** 1. Deciding on Which Books Should Be in the New Testament [2]; 2. Authors, Authorities, and Who Gets To Write the Bible [3]; 3. The Letter of First Clement: An Overview [3]; 4. Did “Pope” Clement Write 1 Clement? [3]; 5. 1 Clement in a Nutshell [1]
- **Metadata-enhanced:** 1. Did “Pope” Clement Write 1 Clement? [3]; 2. Did a “Pope” Write the First-Century Book of 1 Clement? [3]; 3. 1 Clement in a Nutshell [1]; 4. The Epistle of Barnabas in a Nutshell (Part 1) [2]; 5. Why Was Barnabas Attributed to Barnabas: Part 2 [2]; 6. Why Was the Letter of Barnabas Attributed to Barnabas (Part 2) [2]; 7. How and When Did Christians Decide What Should Be in the New Testament Canon? [1]; 8. Authors, Authorities, and Who Gets To Write the Bible [3]; 9. So Did Secretaries Write the Apostles’ Letters for Them? [1]; 10. Ancient Secretaries (Part 2) [1]

### 32. What obligations do Christians have to give money to people in need?

Winner: **Original Ask AI 2**

- **Original:** 1. Love in Action: Christian Views of Charitable Giving [3]; 2. Were Early Christians Really Charitable?  Or Was It All Talk? [3]; 3. You Mean Everyone (Except the Truly Destitute) Needs to Give? But How Much? [3]; 4. Softening Jesus’ Message on Giving up (Literally) Everything [3]; 5. Jesus’ Teachings on Love and Salvation [2]; 6. “Redemptive Gifts”: Can Giving to Charity Save Your Soul? [3]; 7. Concerns for the Poor in the Jewish Tradition [2]; 8. How I Begin My Book on Jesus, Ethics, and Altruism [1]; 9. What the Earliest *Christians* Thought About Wealth [3]; 10. The New Book I’m Writing About Altruism:  Putting It In a Nutshell [1]
- **Metadata-enhanced:** 1. Were Early Christians Really Charitable?  Or Was It All Talk? [3]; 2. Love in Action: Christian Views of Charitable Giving [3]; 3. You Mean Everyone (Except the Truly Destitute) Needs to Give? But How Much? [3]; 4. “Redemptive Gifts”: Can Giving to Charity Save Your Soul? [3]; 5. What the Earliest *Christians* Thought About Wealth [3]; 6. Jesus’ Teachings on Love and Salvation [2]; 7. Did Jesus Insist on Voluntary Poverty? [3]

### 33. What important early Christian writings have been lost or survived only in fragments?

Winner: **Original Ask AI 2**

- **Original:** 1. More Lost Scriptures [3]; 2. ALL the Christian Writings of the First Hundred Years [3]; 3. Paul’s Lost Letters [3]; 4. Lost Christian Writings: The Letters of Paul [3]; 5. Lost Letters of Paul’s Opponents [3]; 6. The Importance of What Is Lost: Paul’s Letters [3]; 7. The Lost Writings of Papias [3]; 8. Papias in a Nutshell.  An Important Figure Among the Apostolic Fathers [3]; 9. The Lost Q Source [3]; 10. Lost Gospels That Are Still Lost 4: Q [3]
- **Metadata-enhanced:** 1. Lost Christianities [1]; 2. More Lost Christianities [3]; 3. Lost Scriptures [3]; 4. The Massive Diversity of Early Christianity. My Book: Lost Christianities [1]; 5. What Is Actually In the Nag Hammadi Library? [2]; 6. The Contents of the Nag Hammadi Library [2]; 7. Lost Christian Writings I’d Love to Get My Hands On! [3]; 8. Back To the Discovery of Lost Early Christian Writings [3]; 9. Paul’s Lost Letters [3]; 10. Lost Christian Writings: The Letters of Paul [3]

### 34. How did anti-Jewish ideas enter early Christian writings?

Winner: **Original Ask AI 2**

- **Original:** 1. The Rise of Christian Anti-Judaism, in a Nutshell [3]; 2. When Christians Went on the Attack Against Jews [3]; 3. How “Jews” Became “Children of the Devil” in the New Testament [3]; 4. Anti-Judaism in the Gospels [3]; 5. Are the Gospels Anti-Jewish? [3]; 6. Is the Gospel of Luke Anti-Jewish? [3]; 7. Anti-Judaism in the Gospel of Luke [3]; 8. The Epistle of Barnabas in a Nutshell (Part 1) [3]; 9. Melito and arly Christian Anti-Judaism [3]; 10. Heightened Opposition to Jews in Early Christianity [3]
- **Metadata-enhanced:** 1. When Christians Went on the Attack Against Jews [3]; 2. How “Jews” Became “Children of the Devil” in the New Testament [3]; 3. The Epistle of Barnabas in a Nutshell (Part 1) [3]; 4. Why Christians Needed an Old Testament: Pagan Attacks on the Faith [2]; 5. The Jewish Bible in the Gentile Churches [2]; 6. Are the Gospels Anti-Jewish? [3]; 7. Anti-Judaism in the Gospels [3]; 8. Exonerating Pilate to Implicate the Jews [3]; 9. The God Christ and the Jews [2]; 10. Is the Old Testament a Christian Book? [3]

### 35. Can several people genuinely share the same religious vision?

Winner: **Original Ask AI 2**

- **Original:** 1. Are “Group Hallucinations” Possible?  The Case of Mary. [3]; 2. Are Group Visions Possible? [3]; 3. What Really Happens With Group Visions [3]; 4. A Final Word (I Think!) on Group Visions [3]; 5. How Can “Group Hallucinations” Possibly Happen? [3]; 6. Did Some Disciples Not Believe in the Resurrection? [2]; 7. Group Visions and Agnostic Jesus Scholars: Mailbag March 12, 2017 [3]; 8. Did Disciples Have Visions of Jesus? [3]; 9. Two Versions of Constantine’s Vision [1]; 10. Constantine’s Vision according to Eusebius [1]
- **Metadata-enhanced:** 1. Are Group Visions Possible? [3]; 2. How Can “Group Hallucinations” Possibly Happen? [3]; 3. Are “Group Hallucinations” Possible?  The Case of Mary. [3]; 4. Visions of Mary [2]; 5. What Really Happens With Group Visions [3]; 6. A Final Word (I Think!) on Group Visions [3]; 7. Did Some Disciples Not Believe in the Resurrection? [2]; 8. Group Visions and Agnostic Jesus Scholars: Mailbag March 12, 2017 [3]; 9. Two Versions of Constantine’s Vision [1]; 10. Constantine’s Vision(s): What Did He Really See and When? [2]

### 36. What did everyday belief and practice look like in the earliest Christian communities?

Winner: **Metadata Ask AI 2**

- **Original:** 1. An Important Early Christian Writing [3]; 2. The Didache: An Important Early Christian Document in a Nutshell [3]; 3. Intriguing Instructions for How To Run the Church:  More on the Didache [3]; 4. Were Early Christians Really Charitable?  Or Was It All Talk? [2]; 5. You Mean Everyone (Except the Truly Destitute) Needs to Give? But How Much? [3]; 6. Is Christian Love Different from Love? [2]; 7. How Strikingly Few Early Churches Were There?  How Amazingly Many Christian Letters? [2]; 8. How Many Christians Could Read? [2]; 9. Women and Gender: Early Christianity in a Patriarchal World [2]; 10. Why the Spirit Mattered for the Earliest Christians [2]
- **Metadata-enhanced:** 1. The Didache: An Important Early Christian Document in a Nutshell [3]; 2. An Important Early Christian Writing [3]; 3. Intriguing Instructions for How To Run the Church:  More on the Didache [3]; 4. Were Early Christians Really Charitable?  Or Was It All Talk? [2]; 5. You Mean Everyone (Except the Truly Destitute) Needs to Give? But How Much? [3]; 6. Love in Action: Christian Views of Charitable Giving [2]; 7. How Women Came to Be Silenced in Early Christianity: A Blast From the Past [2]; 8. Women and Gender: Early Christianity in a Patriarchal World [2]; 9. 1 Thessalonians at a Glance, and Questions for Reflection [3]; 10. How the “Delay” of the End (Jesus’ Return) Affected Paul’s Communities [2]

### 37. How do historians reconstruct Jesus' life when the surviving sources disagree?

Winner: **Tie**

- **Original:** 1. The Historian’s Wish List [3]; 2. Problems with the Gospels: A Primer for the Study of the Historical Jesus (Part 2) [3]; 3. Knowing What Jesus Said and Did [3]; 4. Question on Mistakes in Ancient Sources [3]; 5. A Return to the Historical Jesus [3]; 6. Rules of Thumb for Reconstructing the History behind the Gospels [3]; 7. Is History Possible? [3]; 8. More Background on Oral Traditions [2]; 9. How Can We Get Behind “False Memories” of Jesus to the Historical Facts? [3]; 10. Why Are Their Differences in the Gospels?  Does it Affect Their Inspiration?  Guest Post by Mike Licona [3]
- **Metadata-enhanced:** 1. The Historian’s Wish List [3]; 2. Problems with the Gospels: A Primer for the Study of the Historical Jesus (Part 2) [3]; 3. More Background on Oral Traditions [2]; 4. Knowing What Jesus Said and Did [3]; 5. Is History Possible? [3]; 6. A Return to the Historical Jesus [3]; 7. Historical Certainty and Jesus [3]; 8. Why Do Historians Treat Jesus Differently from Every Other Historical Figure? [3]; 9. How Do We Know If Jesus Did Something? [3]; 10. Question on Mistakes in Ancient Sources [3]

### 38. How might human memory have changed stories about Jesus before the Gospels were written?

Winner: **Tie**

- **Original:** 1. Press Release!  Jesus Before the Gospels [3]; 2. Q & A about Jesus Before the Gospels: Part 2 [3]; 3. Q & A about Jesus Before the Gospels, Part 3 [3]; 4. Q & A about Jesus Before the Gospels, Part 1 [3]; 5. Jesus Before the Gospels in Relation to My Other Books [2]; 6. My Forgotten Book on Memory [3]; 7. Does Understanding “Memory” Have Any Bearing on the Study of the Historical Jesus? [3]; 8. Being Realistic about How Stories about Jesus Spread before the Gospels [3]; 9. Did Early Christians “Invent” Memories of Jesus? [3]; 10. Stories of Jesus Passed on By Word of Mouth.  When Scholars First Took Oral Traditions Seriously. [3]
- **Metadata-enhanced:** 1. Q & A about Jesus Before the Gospels, Part 1 [3]; 2. Q & A about Jesus Before the Gospels: Part 2 [3]; 3. Q & A about Jesus Before the Gospels, Part 3 [3]; 4. Press Release!  Jesus Before the Gospels [3]; 5. Jesus Before the Gospels in Relation to My Other Books [2]; 6. Did Early Christians “Invent” Memories of Jesus? [3]; 7. Changing the Past in Light of the Present [3]; 8. Proof That Historical Narratives (not just myths) Constantly Change in Oral Cultures [3]; 9. Do People in Oral Cultures Have Better Memories? [3]; 10. How Can We Get Behind “False Memories” of Jesus to the Historical Facts? [3]

### 39. Why did early Christians think Jesus' death brought salvation?

Winner: **Tie**

- **Original:** 1. The Death of the Messiah for Salvation [3]; 2. The Resurrection as a Key to Early Understandings of Jesus [3]; 3. Who Invented the Idea of a Suffering Messiah? [3]; 4. Where Did the Idea of a “Suffering Messiah” Come From? [3]; 5. The Core of Paul’s Gospel [3]; 6. How Did Christianity Start? [3]; 7. A Particular Problem with a Crucified Messiah [3]; 8. How Did Paul Understand Salvation?  The “Judicial” Model [2]; 9. How a Non-Historical Account Can Be Meaningful: The Death of Jesus in Mark [3]; 10. Salvation, Love, and the Jewish Law in Paul.  Are His Views Internally Coherent? [3]
- **Metadata-enhanced:** 1. The Death of the Messiah for Salvation [3]; 2. The Resurrection as a Key to Early Understandings of Jesus [3]; 3. How a Non-Historical Account Can Be Meaningful: The Death of Jesus in Mark [3]; 4. How Did Christianity Start? [3]; 5. The Core of Paul’s Gospel [3]; 6. Who Invented the Idea of a Suffering Messiah? [3]; 7. A Particular Problem with a Crucified Messiah [3]; 8. Where Did the Idea of a “Suffering Messiah” Come From? [3]; 9. Paul’s Own (and Only) Gospel [3]; 10. Paul’s Importance in Early Christianity? [1]

### 40. Why is Codex Sinaiticus important for understanding the Bible's text?

Winner: **Original Ask AI 2**

- **Original:** 1. The Discovery of Codex Sinaiticus: One of the Most Important Manuscripts of the New Testament [3]; 2. Tischendorf and the Discovery of Codex Sinaiticus [3]; 3. Visiting the Monastery at Mount Sinai: A Blast From the Past [3]; 4. St. Catherine’s Monastery [3]; 5. What We Now Know about the Manuscripts of the New Testament [2]; 6. Introduction to the Manuscripts of the New Testament [1]
- **Metadata-enhanced:** 1. Conclusions Drawn from My Study of Didymus [2]; 2. The Discovery of Codex Sinaiticus: One of the Most Important Manuscripts of the New Testament [3]; 3. How Useful Are Our Earliest New Testament Manuscripts? [1]; 4. What We Now Know about the Manuscripts of the New Testament [2]; 5. The Manuscripts of the New Testament [1]; 6. How Accurate Are our Earliest NT Manuscripts? [3]

### 41. Did ancient Jews and Christians believe consciousness continued immediately after death?

Winner: **Original Ask AI 2**

- **Original:** 1. Life After Death According to Samuel [3]; 2. What’s It Like in Sheol? [3]; 3. Speaking in Churches as an Agnostic; and Jewish Beliefs about Afterlife.  Readers Mailbag August 13, 2016 [2]; 4. Jewish Disagreements About the Afterlife: Pharisees and Sadducees [2]; 5. Possibilities for the Afterlife [2]; 6. Does Your Soul Go To Heaven? [3]; 7. Reviewing the Afterlife [3]; 8. (Later) Early Christian Understandings of Heaven and Hell [3]; 9. Heaven and Hell: When was Heaven and Hell Invented? [2]; 10. Life After Death in Rome, and other Questions.  Readers’ Mailbag May 6, 2016 [1]
- **Metadata-enhanced:** 1. Speaking in Churches as an Agnostic; and Jewish Beliefs about Afterlife.  Readers Mailbag August 13, 2016 [2]; 2. Life After Death According to Samuel [3]; 3. What’s It Like in Sheol? [3]; 4. Returning from the Dead in the Hebrew Bible [2]; 5. Jewish Disagreements About the Afterlife: Pharisees and Sadducees [2]; 6. Does Your Soul Go To Heaven? [3]; 7. (Later) Early Christian Understandings of Heaven and Hell [3]; 8. Reviewing the Afterlife [3]; 9. What I’m Thinking about the Afterlife [3]

### 42. What leadership role did James, the brother of Jesus, have in the early church?

Winner: **Metadata Ask AI 2**

- **Original:** 1. Why Did the Author of James Claim to be James in Particular? [3]; 2. Paul’s Acquaintances: Jesus’ Disciples and Brother [3]; 3. One of My Favorite Letters in the New Testament: The Book of James [3]; 4. Did Paul Get Along with the Other Apostles? [3]; 5. The Accuracy of Paul’s Letter to the Galatians [2]; 6. Did Paul Know Much about the Historical Jesus? [2]
- **Metadata-enhanced:** 1. Why Did the Author of James Claim to be James in Particular? [3]; 2. Paul’s Acquaintances: Jesus’ Disciples and Brother [3]; 3. My Work as a Historian and Paul in Conflict with the Jerusalem Church: Readers’ Mailbag August 20, 2016 [3]; 4. The Accuracy of Paul’s Letter to the Galatians [2]; 5. Did Paul Know Much about the Historical Jesus? [2]; 6. What Did Paul Know About the Historical Jesus? [1]; 7. One of My Favorite Letters in the New Testament: The Book of James [3]; 8. Does the Book of James Have the Same Concerns as the Historical James? [3]; 9. Does James (the Book)  Have the Same Concerns as James (the Man)?  Part 2 [1]; 10. The Brother of Jesus and the Book of James [0]

### 43. Was the story of Jesus sweating blood originally part of Luke's Gospel?

Winner: **Tie**

- **Original:** 1. Did Jesus Sweat Blood?  Another Problem with the NRSV [3]; 2. Problems with the NRSV (Part 4) [3]; 3. Jesus’ Lack of Agony [3]; 4. Jesus in the Face of Death? [3]; 5. Was Jesus in Agony Before His Arrest?  The Unexpected Answer in Luke. [3]; 6. More on The Bloody Sweat [3]; 7. Did Scribes Add the Passage of the Bloody Sweat? [3]; 8. Why Did Scribes Add the Bloody Sweat? [3]; 9. An Unexpected Argument Against Jesus’ “Sweating Blood” [3]; 10. Did Jesus Sweat Blood?  “Intrinsic” Evidence for Textual Variants [3]
- **Metadata-enhanced:** 1. Did Jesus Sweat Blood?  Another Problem with the NRSV [3]; 2. Problems with the NRSV (Part 4) [3]; 3. Jesus “Sweating Blood”: Which Text Would *Scribes* Have Preferred? [3]; 4. Jesus’ Lack of Agony [3]; 5. More on The Bloody Sweat [3]; 6. Did Scribes Add the Passage of the Bloody Sweat? [3]; 7. An Unexpected Argument Against Jesus’ “Sweating Blood” [3]; 8. Why Did Scribes Add the Bloody Sweat? [3]; 9. Jesus in the Face of Death? [3]; 10. Was Jesus in Agony Before His Arrest?  The Unexpected Answer in Luke. [3]

### 44. Why does Bart describe himself as both an agnostic and an atheist?

Winner: **Metadata Ask AI 2**

- **Original:** 1. Why Would I Call Myself Both an Agnostic and an Atheist?  A Blast from the Past [3]; 2. Why Would I Call Myself Both an Agnostic or an Atheist?  A Blast From the Past [3]; 3. Agnostic or Atheist? [3]; 4. Am I an Agnostic or an Atheist? [3]; 5. On Being an Agnostic Atheist [3]; 6. Fundamentalism and the Truth of the Bible [1]; 7. The Threat of Judgment [2]; 8. My Birdbrain View of Agnosticism [2]; 9. A Revelatory Moment about “God” [3]
- **Metadata-enhanced:** 1. Why Would I Call Myself Both an Agnostic and an Atheist?  A Blast from the Past [3]; 2. Agnostic or Atheist? [3]; 3. Why Would I Call Myself Both an Agnostic or an Atheist?  A Blast From the Past [3]; 4. Am I an Agnostic or an Atheist? [3]; 5. On Being an Agnostic Atheist [3]; 6. Readers’ Mailbag December 11, 2015 [3]; 7. The Threat of Judgment [2]; 8. A Revelatory Moment about God: Most-Commented Blog Post: #3 [3]; 9. A Revelatory Moment about “God” [3]; 10. My Birdbrain View of Agnosticism [2]

### 45. What can Paul's letters tell us about his life that Acts does not?

Winner: **Metadata Ask AI 2**

- **Original:** 1. The Life of Paul in a Nutshell [3]; 2. The Quest for the Historical Paul: Sorting Through Our Sources (Part 1). Guest Post by James Tabor [3]; 3. Paul the Persecutor and the Historical Jesus [3]; 4. The Conversion of Paul [3]; 5. Lost Christian Writings: The Letters of Paul [1]; 6. The Significance and Letters of Paul, in a Nutshell [2]; 7. Two Live Lectures, Sunday March 21:  The Death of Jesus and the Life of Paul. [0]; 8. Paul and the Historical Jesus [1]; 9. What Did Paul Know About the Historical Jesus? [1]; 10. Was Paul Authorized to Persecute Christians? [3]
- **Metadata-enhanced:** 1. The Life of Paul in a Nutshell [3]; 2. The Life and Message of Paul [3]; 3. The Quest for the Historical Paul: Sorting Through Our Sources (Part 1). Guest Post by James Tabor [3]; 4. Our Controversial Sources About the Controversial Paul [1]; 5. The Historical Accuracy of Acts [3]; 6. After Paul Converted…  Does the Book of Acts Contradict Paul Himself? [3]; 7. Did Paul Get Along with the Other Apostles? [3]; 8. Paul in Acts: Part 3 [3]; 9. How Paul Persecuted the Christians [2]; 10. Paul the Persecutor and the Historical Jesus [3]

### 46. How do New Testament letters advise Christians to respond to persecution and suffering?

Winner: **Tie**

- **Original:** 1. The So-Called First Letter of Peter [3]; 2. 1 Peter in a Nutshell [3]; 3. The Letter to the Hebrews: In a Nutshell [3]; 4. 1 Thessalonians in a Nutshell [3]; 5. 2 Thessalonians in a Nutshell [3]; 6. One of My Favorite Letters in the New Testament: The Book of James [1]; 7. Are There Two Letters to the Philippians? [2]; 8. The Situation Behind the (“Forged”) Book of 1 Peter [3]; 9. Hebrews and James:  “At a Glance” and “Questions for Reflection” [2]; 10. Jesus’ Death in Mark and Luke [0]
- **Metadata-enhanced:** 1. The So-Called First Letter of Peter [3]; 2. 1 Peter in a Nutshell [3]; 3. 1 Thessalonians in a Nutshell [3]; 4. The Letter to the Hebrews: In a Nutshell [3]; 5. 2 Thessalonians in a Nutshell [3]; 6. The Kind of Suffering that is a Problem [0]; 7. Reading the New Testament Letters in CONTEXT [1]; 8. 1 and 2 Peter and Jude “At a Glance,” and Questions for Reflection [3]; 9. Hebrews and James:  “At a Glance” and “Questions for Reflection” [2]; 10. One of My Favorite Letters in the New Testament: The Book of James [1]

### 47. What different explanations did Paul give for how Christ saves people?

Winner: **Metadata Ask AI 2**

- **Original:** 1. Unusually Important for the Letter to the Romans: Paul’s Models of Salvation [3]; 2. Comparison of Paul’s Two Principal Models of Salvation [3]; 3. Other Models of Salvation in Paul [3]; 4. Still Other Models of Salvation in Paul [3]; 5. Paul’s “Participationist” Model of Salvation. [3]; 6. Paul’s “Participationist” Model of Salvation [3]; 7. Paul’s Letter to the Romans “At a Glance,” and Questions for Reflection [3]; 8. Paul’s “Exceptional” Letter to the Romans [1]; 9. Paul’s Own (and Only) Gospel [2]; 10. The Core of Paul’s Gospel [2]
- **Metadata-enhanced:** 1. Unusually Important for the Letter to the Romans: Paul’s Models of Salvation [3]; 2. Other Models of Salvation in Paul [3]; 3. Paul’s Models of Salvation: Contradictory or Complementary? [3]; 4. Still Other Models of Salvation in Paul [3]; 5. Comparison of Paul’s Two Principal Models of Salvation [3]; 6. Paul’s “Participationist” Model of Salvation [3]; 7. Paul’s “Participationist” Model of Salvation. [3]; 8. Paul’s Letter to the Romans “At a Glance,” and Questions for Reflection [3]; 9. Paul’s Letter to the Romans in a Nutshell [3]; 10. The Resurrection in Paul [2]

### 48. Would eyewitness testimony make the Gospel accounts historically reliable?

Winner: **Metadata Ask AI 2**

- **Original:** 1. Bart Ehrman vs Richard Bauckham – Round 1 [2]; 2. My Debate with Richard Bauckham – Round 2 [2]; 3. More on the Life of Brian and the Historical Jesus [3]; 4. Eyewitnesses and the Gospels: A Blast From the Past [3]; 5. Question about Eyewitnesses and the Gospels [3]; 6. While We’re Talking About the Reliability of Eyewitnesses… [3]; 7. The Value of Eyewitness Testimony [3]; 8. Eyewitness Testimony: The Importance of Actual Expertise [3]; 9. Eyewitnesses and Guaranteed Accuracy [3]; 10. The Historian’s Wish List [3]
- **Metadata-enhanced:** 1. Eyewitnesses and the Gospels: A Blast From the Past [3]; 2. Question about Eyewitnesses and the Gospels [3]; 3. Readers’ Questions on the Accuracy of the Gospels [2]; 4. Q & A about Jesus Before the Gospels, Part 1 [3]; 5. Press Release!  Jesus Before the Gospels [3]; 6. More on the Life of Brian and the Historical Jesus [3]; 7. While We’re Talking About the Reliability of Eyewitnesses… [3]; 8. My Debate with Richard Bauckham – Round 2 [2]; 9. Bart Ehrman vs Richard Bauckham – Round 1 [2]; 10. The Historian’s Wish List [3]

### 49. How is the New Testament taught in a critical university course?

Winner: **Original Ask AI 2**

- **Original:** 1. My New Testament Syllabus [3]; 2. Teaching the Bible as a Historical Book [3]; 3. What’s It Like to Teach at a Research University? [3]; 4. The Work of a Professional Scholar 4: Undergraduate Courses [3]; 5. Undergraduate Courses (1): Introduction to the New Testament (Part 1) [3]; 6. Undergraduate Courses (2): Introduction to the New Testament (Part 2) [3]; 7. Writing a Historical-Critical Textbook that Isn’t *Critical* [2]; 8. Can My Undergraduate Students Continue Believing the Bible is Inerrant? [3]; 9. Placing the New Testament in Its Own Historical Context [2]; 10. The Academic Study of the New Testament [3]
- **Metadata-enhanced:** 1. My New Testament Syllabus [3]; 2. The Academic Study of the New Testament [3]; 3. Teaching the Bible as a Historical Book [3]; 4. Placing the New Testament in Its Own Historical Context [2]; 5. Introduction to My Introduction (to the NT) [2]; 6. Writing a Historical-Critical Textbook that Isn’t *Critical* [2]; 7. What’s It Like to Teach at a Research University? [3]; 8. Can My Students Believe in the Inerrancy of the Bible? [3]

### 50. How did traditions about Mary, the mother of Jesus, develop beyond the New Testament?

Winner: **Tie**

- **Original:** 1. The Gospel Before the Gospel: The Proto-Gospel of James [3]; 2. How Was Jesus *Really* Born?  The Proto-Gospel of James [3]; 3. Twelve Days of Christmas Day 3: A Different Account of Joseph and Mary! [3]; 4. A Different Account of Joseph and Mary! [3]; 5. Jesus’ Brothers?!?  And the Proto-Gospel of James [3]; 6. Jesus’ (Young?) Mother and (Half?) Brothers? The Proto-Gospel of James [3]; 7. Newsweek Article on Christmas: Part 1 [3]; 8. My Article on Christmas in Newsweek [3]; 9. The Virgin Birth in Matthew and Luke [1]; 10. The Virgin Birth and the Gospel of John: A Blast from the Past [2]
- **Metadata-enhanced:** 1. How Was Jesus *Really* Born?  The Proto-Gospel of James [3]; 2. The Gospel Before the Gospel: The Proto-Gospel of James [3]; 3. A Very Odd Story about the Baby Jesus [3]; 4. A Different Account of Joseph and Mary! [3]; 5. Newsweek Article on Christmas: Part 1 [3]; 6. Twelve Days of Christmas Day 3: A Different Account of Joseph and Mary! [3]; 7. My Article on Christmas in Newsweek [3]; 8. When Did Mary Magdalene Become a Prostitute? [0]; 9. Jesus’ Brothers?!?  And the Proto-Gospel of James [3]; 10. Was James the Actual Brother of Jesus? [3]
