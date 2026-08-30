# Ask AI 1 vs. Ask AI 2: Blinded Full-Text Relevance Evaluation

Date: August 29, 2026

## Method

The benchmark used 30 reusable regression questions and 20 newly generated questions selected from a seeded, category-balanced topic sample (seed `20260829`). The complete 50-question set was frozen before either search method ran.

Both current local search pipelines received every question. For each question, the union of both methods' first ten results was shuffled and stripped of method and rank information. A blinded grader read each complete local post and assigned a relevance grade: `3` direct and substantial, `2` strong supporting relevance, `1` marginal context, or `0` not meaningfully relevant. Grades `2` and `3` count as relevant for precision.

Ranking quality uses pooled nDCG@10 with graded relevance. The ideal ranking comes from the best grades in the union of both methods' results. A difference of 0.03 or less is treated as a tie.

## Overall Results

| Outcome | All 50 | Static 30 | New 20 |
|---|---:|---:|---:|
| Ask AI 1 better | 17 | 13 | 4 |
| Ask AI 2 better | 25 | 13 | 12 |
| Tied | 8 | 4 | 4 |

| Mean metric | Ask AI 1 | Ask AI 2 |
|---|---:|---:|
| Precision@5 | 97.2% | 97.2% |
| Precision@10 | 93.9% | 88.3% |
| Average relevance grade@5 | 2.83 | 2.85 |
| Average relevance grade@10 | 2.73 | 2.57 |
| Recall@10 within judged pool | 62.3% | 76.3% |
| nDCG@10 | 0.795 | 0.902 |

## Observed Cost

The local WordPress analytics comparison recorded the following average cost per completed question for this evaluation session:

| Cost measure | Ask AI 1 | Ask AI 2 |
|---|---:|---:|
| Average cost per question | 0.58 cents | 0.95 cents |
| Projected cost per 1,000 questions | $5.80 | $9.50 |
| Projected cost per 10,000 questions | $58.00 | $95.00 |

Ask AI 2 cost an average of 0.37 cents more per question, approximately 64% more than Ask AI 1. Ask AI 1 benefited from prompt caching because it repeatedly supplied a large, stable topic-and-keyword vocabulary. Ask AI 2 sent less repeated text and therefore recorded fewer cached tokens, while its changing candidate titles and summaries increased the uncached input used by its AI review call.

These figures are a snapshot of the model, prompts, OpenAI pricing configuration, caching behavior, and candidate-set sizes used during this run. They should be measured again after material changes to either search method. Cached-token totals should not be treated as an efficiency measure by themselves; total cost per completed question and result quality are the more useful comparison.

## Question Results

| # | Set | Question | Winner | AI 1 P@5 / P@10 / nDCG | AI 2 P@5 / P@10 / nDCG |
|---:|---|---|---|---|---|
| 1 | Static | Why do scholars think the Gospels were written anonymously? | **Ask AI 1** | 100.0% / 90.0% / 0.977 | 100.0% / 100.0% / 0.593 |
| 2 | Static | Was the original ending of Mark lost or added later? | **Ask AI 2** | 66.7% / 66.7% / 0.638 | 80.0% / 66.7% / 0.986 |
| 3 | Static | How do Matthew and Luke tell different stories about Jesus' birth? | **Ask AI 1** | 100.0% / 100.0% / 0.962 | 100.0% / 100.0% / 0.801 |
| 4 | Static | What historical evidence is there for Jesus' empty tomb? | **Ask AI 1** | 100.0% / 80.0% / 0.861 | 100.0% / 90.0% / 0.747 |
| 5 | Static | Why do Matthew and Acts describe Judas' death differently? | **Ask AI 2** | 100.0% / 100.0% / 0.385 | 60.0% / 40.0% / 0.989 |
| 6 | Static | Why is the woman caught in adultery missing from the earliest manuscripts of John? | **Ask AI 2** | 100.0% / 100.0% / 0.723 | 100.0% / 88.9% / 0.992 |
| 7 | Static | How does Paul's account of his conversion differ from the story in Acts? | **Ask AI 2** | 100.0% / 100.0% / 0.448 | 100.0% / 100.0% / 1.000 |
| 8 | Static | Why do scholars question whether Paul wrote Colossians? | **Ask AI 2** | 100.0% / 83.3% / 0.921 | 80.0% / 66.7% / 0.993 |
| 9 | Static | What did Paul teach about women speaking and leading in church? | **Ask AI 1** | 100.0% / 100.0% / 0.960 | 100.0% / 100.0% / 0.915 |
| 10 | Static | Do James and Paul disagree about faith and works? | **Ask AI 2** | 100.0% / 100.0% / 0.920 | 100.0% / 90.0% / 1.000 |
| 11 | Static | What does the Christ poem in Philippians say about Jesus before his birth? | **Ask AI 1** | 100.0% / 100.0% / 1.000 | 100.0% / 100.0% / 0.964 |
| 12 | Static | Were Peter and Cephas the same person? | **Ask AI 2** | 100.0% / 100.0% / 0.801 | 100.0% / 90.0% / 0.933 |
| 13 | Static | Was Mary Magdalene really a prostitute? | **Ask AI 1** | 100.0% / 100.0% / 1.000 | 100.0% / 100.0% / 0.939 |
| 14 | Static | Did Jesus expect the world to end during his generation? | **Ask AI 2** | 100.0% / 100.0% / 0.870 | 100.0% / 100.0% / 0.922 |
| 15 | Static | How can historians evaluate miracle stories about Jesus? | **Tie** | 100.0% / 100.0% / 0.727 | 100.0% / 80.0% / 0.754 |
| 16 | Static | What did Jesus mean by the Kingdom of God? | **Ask AI 2** | 100.0% / 100.0% / 0.769 | 100.0% / 100.0% / 0.947 |
| 17 | Static | Why does an all-powerful and loving God allow innocent people to suffer? | **Tie** | 100.0% / 100.0% / 0.955 | 100.0% / 100.0% / 0.955 |
| 18 | Static | When did Christians begin believing that souls go immediately to heaven or hell? | **Ask AI 1** | 100.0% / 80.0% / 0.985 | 80.0% / 83.3% / 0.811 |
| 19 | Static | What is the main message of the Book of Revelation? | **Ask AI 2** | 100.0% / 100.0% / 0.884 | 100.0% / 100.0% / 1.000 |
| 20 | Static | How did Christians decide which books belonged in the New Testament? | **Tie** | 100.0% / 100.0% / 1.000 | 100.0% / 100.0% / 1.000 |
| 21 | Static | Could the Gospel of Thomas preserve authentic sayings of Jesus? | **Ask AI 1** | 100.0% / 100.0% / 0.890 | 100.0% / 66.7% / 0.800 |
| 22 | Static | Were Gnostic Christians a single unified movement? | **Ask AI 1** | 100.0% / 100.0% / 0.959 | 100.0% / 80.0% / 0.856 |
| 23 | Static | How did Constantine affect the development of Christianity? | **Ask AI 1** | 100.0% / 100.0% / 0.924 | 100.0% / 80.0% / 0.819 |
| 24 | Static | What was the Arian controversy about, and how did it shape the Trinity? | **Ask AI 2** | 100.0% / 100.0% / 0.715 | 100.0% / 100.0% / 0.985 |
| 25 | Static | How widespread was Roman persecution of Christians before Constantine? | **Ask AI 2** | 100.0% / 100.0% / 0.686 | 100.0% / 60.0% / 0.956 |
| 26 | Static | What are the earliest surviving manuscripts of the New Testament? | **Tie** | 100.0% / 100.0% / 0.856 | 100.0% / 80.0% / 0.844 |
| 27 | Static | Did scribes alter New Testament passages to support particular theological beliefs? | **Ask AI 1** | 100.0% / 100.0% / 1.000 | 100.0% / 90.0% / 0.817 |
| 28 | Static | Why does the King James Bible contain readings scholars now reject? | **Ask AI 1** | 100.0% / 90.0% / 1.000 | 100.0% / 70.0% / 0.857 |
| 29 | Static | How can scholars tell whether an ancient Christian writing was forged? | **Ask AI 1** | 100.0% / 100.0% / 0.847 | 100.0% / 70.0% / 0.793 |
| 30 | Static | What is the strongest historical evidence that Jesus really existed? | **Ask AI 2** | 100.0% / 62.5% / 0.632 | 100.0% / 100.0% / 0.955 |
| 31 | Generated | How do scholars decide who wrote works such as 1 Clement and the Letter of Barnabas? | **Ask AI 1** | 100.0% / 85.7% / 0.720 | 80.0% / 80.0% / 0.615 |
| 32 | Generated | What obligations do Christians have to give money to people in need? | **Tie** | 100.0% / 90.0% / 0.880 | 100.0% / 80.0% / 0.898 |
| 33 | Generated | What important early Christian writings have been lost or survived only in fragments? | **Ask AI 2** | 100.0% / 83.3% / 0.612 | 100.0% / 100.0% / 1.000 |
| 34 | Generated | How did anti-Jewish ideas enter early Christian writings? | **Ask AI 2** | 100.0% / 100.0% / 0.946 | 100.0% / 100.0% / 1.000 |
| 35 | Generated | Can several people genuinely share the same religious vision? | **Ask AI 2** | 100.0% / 100.0% / 0.922 | 100.0% / 80.0% / 0.952 |
| 36 | Generated | What did everyday belief and practice look like in the earliest Christian communities? | **Ask AI 2** | 33.3% / 33.3% / 0.173 | 100.0% / 100.0% / 0.993 |
| 37 | Generated | How do historians reconstruct Jesus' life when the surviving sources disagree? | **Ask AI 2** | 100.0% / 100.0% / 0.220 | 100.0% / 100.0% / 0.960 |
| 38 | Generated | How might human memory have changed stories about Jesus before the Gospels were written? | **Ask AI 2** | 100.0% / 100.0% / 0.487 | 100.0% / 100.0% / 0.987 |
| 39 | Generated | Why did early Christians think Jesus' death brought salvation? | **Ask AI 2** | 100.0% / 100.0% / 0.850 | 100.0% / 100.0% / 0.960 |
| 40 | Generated | Why is Codex Sinaiticus important for understanding the Bible's text? | **Ask AI 1** | 100.0% / 100.0% / 0.936 | 100.0% / 83.3% / 0.882 |
| 41 | Generated | Did ancient Jews and Christians believe consciousness continued immediately after death? | **Ask AI 2** | 100.0% / 100.0% / 0.261 | 100.0% / 90.0% / 0.879 |
| 42 | Generated | What leadership role did James, the brother of Jesus, have in the early church? | **Ask AI 2** | 80.0% / 80.0% / 0.697 | 100.0% / 100.0% / 0.876 |
| 43 | Generated | Was the story of Jesus sweating blood originally part of Luke's Gospel? | **Tie** | 100.0% / 100.0% / 1.000 | 100.0% / 100.0% / 1.000 |
| 44 | Generated | Why does Bart describe himself as both an agnostic and an atheist? | **Ask AI 1** | 100.0% / 100.0% / 0.897 | 100.0% / 88.9% / 0.851 |
| 45 | Generated | What can Paul's letters tell us about his life that Acts does not? | **Ask AI 2** | 100.0% / 100.0% / 0.631 | 80.0% / 60.0% / 0.858 |
| 46 | Generated | How do New Testament letters advise Christians to respond to persecution and suffering? | **Tie** | 100.0% / 100.0% / 0.922 | 100.0% / 80.0% / 0.909 |
| 47 | Generated | What different explanations did Paul give for how Christ saves people? | **Ask AI 1** | 100.0% / 100.0% / 0.964 | 100.0% / 90.0% / 0.866 |
| 48 | Generated | Would eyewitness testimony make the Gospel accounts historically reliable? | **Ask AI 2** | 100.0% / 90.0% / 0.622 | 100.0% / 100.0% / 0.859 |
| 49 | Generated | How is the New Testament taught in a critical university course? | **Ask AI 2** | 80.0% / 90.0% / 0.853 | 100.0% / 100.0% / 0.920 |
| 50 | Generated | How did traditions about Mary, the mother of Jesus, develop beyond the New Testament? | **Tie** | 100.0% / 88.9% / 0.880 | 100.0% / 90.0% / 0.907 |

## Ranked Grades

### 1. Why do scholars think the Gospels were written anonymously?

Winner: **Ask AI 1**

- **Ask AI 1:** 1. Anniversary Post #2: Why Were the Gospels Written Anonymously? [3]; 2. Why Are the Gospels Anonymous? [3]; 3. Why Didn’t the Gospel Writers Tell Us Who They Were? [3]; 4. Why Are the Gospels Anonymous? [3]; 5. Why The Gospels Are Anonymous [3]; 6. Why Would an Ancient Author Write a Book Anonymously? [3]; 7. Authors, Authorities, and Who Gets To Write the Bible [2]; 8. Two More Answers from My Pop Quiz [1]; 9. Did the Gospels Originally Have Titles? [2]; 10. When Did the Gospels Get Their Names? [2]
- **Ask AI 2:** 1. Why Are the Gospels Anonymous? [3]; 2. Why Didn’t the Gospel Writers Tell Us Who They Were? [3]; 3. Why Was The Gospel of Matthew Attributed to Matthew? [2]; 4. Why Are the Gospels Anonymous? [3]

### 2. Was the original ending of Mark lost or added later?

Winner: **Ask AI 2**

- **Ask AI 1:** 1. The Ending of Mark in the King James Bible [3]; 2. Snake-Handling and the Gospel of Mark [3]; 3. Mark and the Resurrection [1]
- **Ask AI 2:** 1. The Ending of Mark in the King James Bible [3]; 2. Snake-Handling and the Gospel of Mark [3]; 3. Famous Passages that Are Not Original: How Do Modern Translators Deal with Them? [3]; 4. Mark and the Resurrection [1]; 5. Jesus’ Death and Resurrection in Mark: Another Blast from the Past [3]; 6. The Gospel of Mark in a Nutshell [1]

### 3. How do Matthew and Luke tell different stories about Jesus' birth?

Winner: **Ask AI 1**

- **Ask AI 1:** 1. Another “True” Story that Didn’t Happen?  Jesus’ Birth in Luke [3]; 2. O Little Town of Nazareth? [3]; 3. A Key Contradiction in the Birth Narratives of Jesus [3]; 4. Why It Didn’t Happen that Way.  The Stories of Jesus’ Birth [3]; 5. Jesus’ Birth: Some Comparisons [3]; 6. The Infancy Narratives Compared [3]; 7. The Naivety of the Nativity: Platinum Guest Post by Joel Scheller [3]; 8. How Luke Rewrote Matthew’s Nativity Story     Platinum Guest Post by Dennis J. Folds [3]; 9. A Source for the Birth Narratives in Matthew and Luke? [2]; 10. Why Was Jesus Born of a Virgin in Matthew and Luke? [3]
- **Ask AI 2:** 1. Jesus’ Birth: Some Comparisons [3]; 2. Jesus’ Birth in Matthew and Luke: A Study in Contrasts [3]; 3. Twelve Days of Christmas Day 9: A Key Contradiction in the Birth Narratives of Jesus [3]; 4. Uh, Duh.  What I SHOULD Have Said.  (Bethlehem) [3]; 5. The Naivety of the Nativity: Platinum Guest Post by Joel Scheller [3]; 6. Why Contradictions Matter for Understanding the Life of Jesus [3]; 7. The Infancy Narratives Compared [3]

### 4. What historical evidence is there for Jesus' empty tomb?

Winner: **Ask AI 1**

- **Ask AI 1:** 1. Another Question on the Resurrection [3]; 2. More on the Resurrection [2]; 3. Was Jesus Given a Decent Burial (By Joseph of Arimathea) [2]; 4. The Burial of Jesus: A Blast from the Past [3]; 5. Q & A – Historical Events in Jesus Tradition [3]; 6. Crucified Bodies and Scavengers [1]; 7. Does It Even Matter If Jesus Was Given a Proper Burial? [1]; 8. More Reasons for Thinking Jesus was Not Given a Decent Burial [2]; 9. Women at the Tomb [3]; 10. The Women and the Empty Tomb [3]
- **Ask AI 2:** 1. Another Question on the Resurrection [3]; 2. Was Jesus Given a Decent Burial (By Joseph of Arimathea) [2]; 3. The Burial of Jesus: A Blast from the Past [3]; 4. New Thread on the Burial of Jesus [2]; 5. Literary Problems with the Gospel Accounts of Jesus’ Burial [2]; 6. Argument Against Jesus’ Burial in HJBG, Part 2 [2]; 7. More Reasons for Thinking Jesus was Not Given a Decent Burial [2]; 8. Does Archaeological Evidence Show that Jesus Was Buried on the Day He Died? [1]; 9. The Skeletal Remains of Yehohanan and Their Significance [2]; 10. The Skeletal Remains of Yehohanan: Readers Mailbag October 8, 2017 [2]

### 5. Why do Matthew and Acts describe Judas' death differently?

Winner: **Ask AI 2**

- **Ask AI 1:** 1. But How Did Judas Die? [3]
- **Ask AI 2:** 1. But How Did Judas Die? [3]; 2. The Death of Judas in the NT [3]; 3. Can We Know Anything Historically About How Judas Iscariot Died? [3]; 4. Can We Know Anything About Judas Iscariot? [1]; 5. The Quest for the Historical … Judas Iscariot [1]; 6. Did Judas Really Betray Jesus?  Readers’ Mailbag [1]; 7. How Can Paul Say that Jesus Appeared to The Twelve? [2]; 8. Does Paul Know about Judas Iscariot? [1]; 9. Does Paul Know that Judas Betrayed Jesus? [1]; 10. Why Did Judas Iscariot Betray Jesus? [1]

### 6. Why is the woman caught in adultery missing from the earliest manuscripts of John?

Winner: **Ask AI 2**

- **Ask AI 1:** 1. Did Jesus Write Anything in the New Testament? [3]; 2. The Woman Taken in Adultery in the King James Version [3]; 3. Why Don’t People See Discrepancies in the Bible?  Readers’ Mailbag October 15, 2016 [3]; 4. Intentional Changes of the Text [2]
- **Ask AI 2:** 1. The Woman Taken in Adultery in the King James Version [3]; 2. Did Jesus Write Anything in the New Testament? [3]; 3. Why Don’t People See Discrepancies in the Bible?  Readers’ Mailbag October 15, 2016 [3]; 4. Major Scribal Corruptions in the New Revised Standard Version [2]; 5. Problems with the King James Version: What Were the Translators Translating? [3]; 6. Where Did the King James Bible Come From? [2]; 7. How the Trinity Got Into the New Testament: Part 2 [2]; 8. Intentional Changes of the Text [2]; 9. How Accurate Are our Earliest NT Manuscripts? [1]

### 7. How does Paul's account of his conversion differ from the story in Acts?

Winner: **Ask AI 2**

- **Ask AI 1:** 1. The Conversion of Paul [3]; 2. After Paul Converted…  Does the Book of Acts Contradict Paul Himself? [3]
- **Ask AI 2:** 1. The Conversion of Paul [3]; 2. Paul in Acts: Part 2 [3]; 3. After Paul Converted…  Does the Book of Acts Contradict Paul Himself? [3]; 4. Does the Book of Acts Accurately Portray the Life and Teachings of Paul? [3]; 5. The Historical Accuracy of Acts [3]; 6. Is the Book of Acts Historically Reliable?  The Negative Case. [3]; 7. The Book of Acts is NOT RELIABLE!  The Negative Case [3]

### 8. Why do scholars question whether Paul wrote Colossians?

Winner: **Ask AI 2**

- **Ask AI 1:** 1. Did Paul Write That Letter?  Getting Into the Weeds… [3]; 2. Did Paul Write Colossians? According to Most Scholars No – Paul did Not Write Colossians [3]; 3. Not for the Faint of Heart (Authorship of Colossians) [3]; 4. The Letter to the Colossians: Who, When, and Why? [3]; 5. The DeuteroPauline Epistles “At a Glance,” With Questions for Reflection [2]; 6. Colossians: For Further Reading [1]
- **Ask AI 2:** 1. Did Paul Write Colossians? According to Most Scholars No – Paul did Not Write Colossians [3]; 2. Not for the Faint of Heart (Authorship of Colossians) [3]; 3. The Letter to the Colossians: Who, When, and Why? [3]; 4. Did Paul Write That Letter?  Getting Into the Weeds… [3]; 5. The Letter to the Colossians, in a Nutshell [1]; 6. The DeuteroPauline Epistles “At a Glance,” With Questions for Reflection [2]; 7. Colossians: For Further Reading [1]; 8. Weekly Readers’ Mailbag:  January 16, 2016 [2]; 9. Ephesians:  For Further Reading [1]

### 9. What did Paul teach about women speaking and leading in church?

Winner: **Ask AI 1**

- **Ask AI 1:** 1. The Silencing of Women: 1 Cor. 14:34-35 as an Interpolation [3]; 2. Paul and Women Apostles [3]; 3. Was Paul a Misogynist? [3]; 4. Was Paul a Misogynist? [3]; 5. Women in the Churches of Paul [3]; 6. Paul’s View of Women in the Church [3]; 7. Did Paul Favor Gender Equality? [3]; 8. Were Paul’s Views of Women Oppressive? [2]; 9. Knowing Paul’s Views of Women… [3]; 10. Paul and the Status of Women [3]
- **Ask AI 2:** 1. Knowing Paul’s Views of Women… [3]; 2. The Silencing of Women: 1 Cor. 14:34-35 as an Interpolation [3]; 3. Was Paul a Misogynist? [3]; 4. Women in the Churches of Paul [3]; 5. Was Paul a Misogynist? [3]; 6. Women Apostles in Early Christianity [2]; 7. Paul and Women Apostles [3]; 8. After the New Testament: Women in Early Christianity [2]; 9. Paul, the Pastorals, and Women [3]; 10. Paul the Misogynist?  The Alternative Perspective [3]

### 10. Do James and Paul disagree about faith and works?

Winner: **Ask AI 2**

- **Ask AI 1:** 1. Does James Contradict Paul? [3]; 2. Is the Book of James Attacking the Teachings of Paul? [3]; 3. Is the Author of James Rejecting Paul Himself? [3]; 4. Is James Responding to Paul? [3]; 5. The Close Connections of James and Paul [3]; 6. Why Would Someone Forge the Letter of James? [3]; 7. Hebrews and James:  “At a Glance” and “Questions for Reflection” [3]; 8. The Book of James in a Nutshell [3]
- **Ask AI 2:** 1. Does James Contradict Paul? [3]; 2. Is the Book of James Attacking the Teachings of Paul? [3]; 3. Is the Author of James Rejecting Paul Himself? [3]; 4. Is James Responding to Paul? [3]; 5. Why Would Someone Forge the Letter of James? [3]; 6. The Close Connections of James and Paul [3]; 7. The Book of James in a Nutshell [3]; 8. Hebrews and James:  “At a Glance” and “Questions for Reflection” [3]; 9. One of My Favorite Letters in the New Testament: The Book of James [3]; 10. Was Paul Really at Odds with Peter and James?  Guest Post by Richard Fellows [1]

### 11. What does the Christ poem in Philippians say about Jesus before his birth?

Winner: **Ask AI 1**

- **Ask AI 1:** 1. Is the “Christ Poem” of Philippians Really a Poem?  When Did Jesus Really Become “Equal” With God? [3]; 2. A Fuller Exposition of the Christ Poem in Philippians [3]; 3. But Maybe Paul Doesn’t Believe in the Incarnation…. [3]; 4. Did Paul Think Jesus Was a New Adam, Not a Divine Being? [3]; 5. One of the Most Significant Passages in the NT: Paul’s Christ Poem [3]; 6. One of the Most Important Passages of the NT: Paul’s Christ Poem [3]; 7. How Ancient is the Idea of Christ’s “Incarnation”? [3]; 8. Incarnation Christology, Angels, and Paul [3]; 9. Final Thoughts on the Philippians Christ-Poem [3]; 10. The Pre-pauline “Poem” in Philippians 2 [3]
- **Ask AI 2:** 1. Is the “Christ Poem” of Philippians Really a Poem?  When Did Jesus Really Become “Equal” With God? [3]; 2. One of the Most Important Passages of the NT: Paul’s Christ Poem [3]; 3. One of the Most Significant Passages in the NT: Paul’s Christ Poem [3]; 4. The Pre-pauline “Poem” in Philippians 2 [3]; 5. More on the Philippians Christ-Poem [3]; 6. A Fuller Exposition of the Christ Poem in Philippians [3]; 7. More Comments on Paul’s Rather Astounding Christ Poem [3]; 8. The Most Widely Discussed Passage of Philippians [3]; 9. How Ancient is the Idea of Christ’s “Incarnation”? [3]; 10. Did Paul Really Have *That* Exalted a View of Jesus? [2]

### 12. Were Peter and Cephas the same person?

Winner: **Ask AI 2**

- **Ask AI 1:** 1. Were Peter and Cephas the Same Person? [3]; 2. Flipping a Coin. Cephas and Peter: One Person or Two? [3]; 3. Are Cephas and Peter Two Different People? [3]; 4. Was Cephas Peter?  The Rest of the Argument [3]; 5. Cephas and Peter in the Writings of Paul (Who Knew Them) [3]; 6. Did Paul Know that Peter and Cephas were Two Different People? [3]; 7. Other Suggestions That Peter and Cephas Were Two Different People [3]
- **Ask AI 2:** 1. Were Peter and Cephas the Same Person? [3]; 2. Are Cephas and Peter Two Different People? [3]; 3. Were Cephas and Peter Two Different People?  A Blast from the Past [3]; 4. Finally: Cephas and Peter.  What Do I Really Think? [3]; 5. Some Evidence that Cephas and Peter WERE Two Different People [3]; 6. Did Paul Get Along with the Other Apostles? [1]; 7. Did Paul Know that Peter and Cephas were Two Different People? [3]; 8. Was Cephas Peter?  The Rest of the Argument [3]; 9. Cephas and Peter: Final Arguments, Summary, and Implications [3]; 10. More Hints that Cephas Was Not Peter [3]

### 13. Was Mary Magdalene really a prostitute?

Winner: **Ask AI 1**

- **Ask AI 1:** 1. Was Mary Magdalene a Prostitute? [3]; 2. When Did Mary Magdalene Become a Prostitute? [3]; 3. Mary Magdalene as a Prostitute? [3]; 4. Mary Magdalene in Various Guises [3]; 5. My Book on Peter, Paul, and Mary Magdalene [2]
- **Ask AI 2:** 1. Was Mary Magdalene a Prostitute? [3]; 2. When Did Mary Magdalene Become a Prostitute? [3]; 3. Mary Magdalene as a Prostitute? [3]; 4. Mary Magdalene in Various Guises [3]

### 14. Did Jesus expect the world to end during his generation?

Winner: **Ask AI 2**

- **Ask AI 1:** 1. The Heart of Jesus’ Message [3]; 2. The Preaching of Jesus in a Nutshell [3]; 3. The Teaching of Jesus [3]; 4. The Message of Jesus [3]; 5. How the Gospels Transformed the Apocalyptic Jesus [3]; 6. How Jesus’ Apocalyptic Teachings Were Changed (even in the NT) [3]; 7. The Later De-apocalypticizing of Jesus [3]; 8. Jesus’ Teaching About the Kingdom of God [3]
- **Ask AI 2:** 1. The Preaching of Jesus in a Nutshell [3]; 2. Albert Schweitzer and the Apocalyptic Jesus [3]; 3. Albert Schweitzer and the Apocalyptic Jesus [3]; 4. The Jesus Seminar and the Non-Apocalyptic Jesus. Hey, Why Not? [3]; 5. Did Jesus Believe the End Would Come Within his Lifetime? Maybe Not!  Platinum Post by Rizwan Ahmed [3]; 6. Did Jesus Believe The End Would Come Within His Lifetime? Platinum Post by Rizwan Ahmed [3]; 7. Jesus’ Apocalyptic Message in Matthew [2]; 8. Mark 13:30–a New Argument for an Old Hypothesis. A Platinum Post From Omar Robb [3]; 9. Jesus’s Apocalyptic View of Destruction [3]; 10. The Apocalyptic Background to Jesus’ Messiahship [2]

### 15. How can historians evaluate miracle stories about Jesus?

Winner: **Tie**

- **Ask AI 1:** 1. History is not the Past!  Proving Jesus’ Resurrection and Other Miracles [3]; 2. Was Jesus Considered a Miracle Worker During His *Lifetime*? [3]; 3. Was Jesus Thought To Be a Miracle Worker in His Own Lifetime? [3]; 4. Jesus Healing the Paralyzed.  How Do We Explain the Stories?  Platinum Guest Post by Douglas Wadeson, MD [3]; 5. Jesus the Healer: Those Darn Demons.     Platinum guest post by Douglas Wadeson MD [3]; 6. Those Darn Demons!  Guest Post by Douglas Wadeson [3]
- **Ask AI 2:** 1. Historians and the Problem of Miracle [3]; 2. More on the Historical Problem of Miracles [3]; 3. History is not the Past!  Proving Jesus’ Resurrection and Other Miracles [3]; 4. Can We Get Rid of Our Presuppositions? [3]; 5. Eyewitness Accounts of Miracles [2]; 6. Once More on the Credibility of Miracles: Guest post by Darren Slade [2]; 7. Resurrection Narratives in the Gospels [3]; 8. Q & A about Jesus Before the Gospels, Part 1 [1]; 9. Q & A about Jesus Before the Gospels, Part 3 [1]; 10. Does Understanding “Memory” Have Any Bearing on the Study of the Historical Jesus? [2]

### 16. What did Jesus mean by the Kingdom of God?

Winner: **Ask AI 2**

- **Ask AI 1:** 1. Jesus’ Teaching About the Kingdom of God [3]; 2. The Teaching of Jesus [3]; 3. The Message of Jesus [3]; 4. The Preaching of Jesus in a Nutshell [3]; 5. The Heart of Jesus’ Message [3]
- **Ask AI 2:** 1. Jesus’ Teaching About the Kingdom of God [3]; 2. Who Was Jesus? [3]; 3. The Preaching of Jesus in a Nutshell [3]; 4. The Message of Jesus [3]; 5. The Teaching of Jesus [3]; 6. Jesus’ Apocalyptic Message in Matthew [2]; 7. Was Jesus a Great Moral Teacher? [2]; 8. Jesus’ Teachings on Love and Salvation [2]; 9. Jesus and the Son of Man [2]; 10. What Would an Apocalyptic Jew (Jesus!) Mean By Calling Himself Messiah? [2]

### 17. Why does an all-powerful and loving God allow innocent people to suffer?

Winner: **Tie**

- **Ask AI 1:** 1. The Problem of Suffering? So What’s the Problem? [3]; 2. The Classic “Problem” of Suffering [3]; 3. The Kind of Suffering that is a Problem [3]; 4. Is Suffering a “Problem” for Believers? [3]; 5. Bart Ehrman on Problem of Suffering – UCB [3]; 6. Why Do Some Smart People Just Not Think? [2]; 7. My Struggle With Why There Is Suffering [3]; 8. Facing the Problem of Suffering Head-on [3]; 9. Hurricanes, Suffering, And My Loss of Faith [3]; 10. Bart Behaving Badly: Podcasts on the Problem of Suffering [3]
- **Ask AI 2:** 1. The Problem of Suffering? So What’s the Problem? [3]; 2. The Classic “Problem” of Suffering [3]; 3. Seeing the Problem of Suffering as a PROBLEM [3]; 4. Suffering. Is It Really Worth Talking About? Doesn’t the Bible Give the Right Answer? [3]; 5. My Struggle With Why There Is Suffering [3]; 6. Why Do Some Smart People Just Not Think? [2]; 7. Hurricanes, Suffering, And My Loss of Faith [3]; 8. Human Suffering and the Christian Faith [3]; 9. Leaving the Faith [3]; 10. Bart Ehrman on Problem of Suffering – UCB [3]

### 18. When did Christians begin believing that souls go immediately to heaven or hell?

Winner: **Ask AI 1**

- **Ask AI 1:** 1. Heaven and Hell: When was Heaven and Hell Invented? [3]; 2. (Later) Early Christian Understandings of Heaven and Hell [3]; 3. Heaven and Hell, Part Two [3]; 4. How The Afterlife Changed After Jesus’ Life [3]; 5. Does Your Soul Go To Heaven? [3]; 6. Views of the Afterlife [2]; 7. The Invention of the Afterlife: Request for Ideas! [1]; 8. What I’m Thinking about the Afterlife [1]; 9. Eternal Life and Damnation [2]; 10. Heaven and Hell, Part One [3]
- **Ask AI 2:** 1. (Later) Early Christian Understandings of Heaven and Hell [3]; 2. Heaven and Hell, Part Two [3]; 3. How The Afterlife Changed After Jesus’ Life [3]; 4. Heaven and Hell: When was Heaven and Hell Invented? [3]; 5. The Invention of the Afterlife: Request for Ideas! [1]; 6. Does Your Soul Go To Heaven? [3]

### 19. What is the main message of the Book of Revelation?

Winner: **Ask AI 2**

- **Ask AI 1:** 1. The Book of Revelation as an Apocalypse [3]; 2. Understanding the Book of Revelation as an Apocalypse [3]; 3. The Book of Revelation and the END.   Starting at the Beginning. [3]; 4. The Book of Revelation in a Nutshell [3]; 5. Understanding the Apocalypse as an “Apocalypse” [3]; 6. The Book of Revelation and the Apocalypse Genre [2]; 7. The Historical Background to the Book of Revelation [2]; 8. The Revelation of John at a Glance, with Questions for Reflection [3]
- **Ask AI 2:** 1. The Book of Revelation as an Apocalypse [3]; 2. The Book of Revelation in a Nutshell [3]; 3. The Revelation of John at a Glance, with Questions for Reflection [3]; 4. Understanding the Apocalypse as an “Apocalypse” [3]; 5. Understanding the Book of Revelation as an Apocalypse [3]; 6. The Book of Revelation and the END.   Starting at the Beginning. [3]; 7. The Book of Revelation: When and Why? [3]; 8. The Book of Revelation and the Apocalypse Genre [2]; 9. Apocalypse (the genre) and Apocalypticism (the worldview) [2]; 10. Apocalypticism and Apocalypses [2]

### 20. How did Christians decide which books belonged in the New Testament?

Winner: **Tie**

- **Ask AI 1:** 1. How and When Did Christians Decide What Should Be in the New Testament Canon? [3]; 2. Deciding on Which Books Should Be in the New Testament [3]; 3. How We Got the New Testament (and not some other books!) [3]; 4. How Did They Decide Which Books to Include in the New Testament Canon? [3]; 5. Why and When Did We Get This Canon of the New Testament? [3]; 6. How Did We Get The 27 Books of the New Testament? [3]; 7. How Did We Get *These* 27 Books in the New Testament? [3]; 8. Why Were Some of the Earliest Christian Books Left OUT of the NT? [3]; 9. The Muratorian Canon (The first “list” of Christian canonical books) [3]; 10. When Did We Get the Final Canon of the New Testament? [3]
- **Ask AI 2:** 1. How Did They Decide Which Books to Include in the New Testament Canon? [3]; 2. How and When Did Christians Decide What Should Be in the New Testament Canon? [3]; 3. Deciding on Which Books Should Be in the New Testament [3]; 4. How Did We Get *These* 27 Books in the New Testament? [3]; 5. How Did We Get The 27 Books of the New Testament? [3]; 6. How We Got the New Testament (and not some other books!) [3]; 7. Why and When Did We Get This Canon of the New Testament? [3]; 8. Question on How We Got the Canon of the New Testament [3]; 9. Why Did Early Christians Want a New Canon of Scripture? [3]; 10. Why Did We Get a New Testament? [3]

### 21. Could the Gospel of Thomas preserve authentic sayings of Jesus?

Winner: **Ask AI 1**

- **Ask AI 1:** 1. Thomas, the Synoptic Gospels, and Q [3]; 2. Could the Gospel of Thomas Be Q?  Could it Be Older Than the NT Gospels? [3]; 3. Thomas: The Most Important Gospel Outside the New Testament [3]; 4. The Gospel of Thomas and the Other Gospels [3]; 5. Thomas and the Other Gospels [2]; 6. Q and The Gospel of Thomas [3]
- **Ask AI 2:** 1. Thomas, the Synoptic Gospels, and Q [3]; 2. Could the Gospel of Thomas Be Q?  Could it Be Older Than the NT Gospels? [3]; 3. The Gospel of Thomas and the Other Gospels [3]; 4. Thomas and the Other Gospels [2]; 5. Traditions About Jesus that Are Probably Not Historical [2]; 6. The Jesus Seminar and the Non-Apocalyptic Jesus. Hey, Why Not? [2]; 7. What About Accurately Preserved *Oral* Traditions? [1]; 8. How Reliable are Oral Traditions? [1]; 9. Stories of Jesus Passed on By Word of Mouth.  When Scholars First Took Oral Traditions Seriously. [1]

### 22. Were Gnostic Christians a single unified movement?

Winner: **Ask AI 1**

- **Ask AI 1:** 1. My New Discussion of Gnosticism: Introduction [3]; 2. What Is Gnosticism? [3]; 3. Some of the Difficulties in Understanding Gnosticism [3]; 4. New Discussion of Gnosticism [3]; 5. Our Knowledge of Gnosticism [3]; 6. The Valentinian Gnostics from After The New Testament [2]; 7. Some Other Gnostics [2]; 8. Thomasine Christians and Others, From After the New Testament [3]; 9. Thomasine “Gnostics” and Others [3]
- **Ask AI 2:** 1. Some of the Difficulties in Understanding Gnosticism [3]; 2. New Discussion of Gnosticism [3]; 3. My New Discussion of Gnosticism: Introduction [3]; 4. Our Knowledge of Gnosticism [3]; 5. What Is Gnosticism? [3]; 6. Lost Christianities [1]; 7. How Diverse Was Early Christianity? [2]; 8. A Different Kind of Gnostic:  The Valentinians [2]; 9. The Valentinian Gnostics [2]; 10. The Sethian Gnostics [1]

### 23. How did Constantine affect the development of Christianity?

Winner: **Ask AI 1**

- **Ask AI 1:** 1. The Conversion of the Emperor Constantine [3]; 2. Constantine and the Christian Faith: My Fourth Smithsonian Lecture [2]; 3. The Conversion of Constantine [3]; 4. Constantine and Christianity [3]; 5. The Conversion of Constantine and Beyond [3]; 6. The Emperor Constantine: Some Background [3]; 7. Was Christianity Bound to Take Over the Ancient World? [3]; 8. The Council of Nicaea and The Resulting View of Christ [3]; 9. Constantine and the Battle at the Milvian Bridge [2]
- **Ask AI 2:** 1. Constantine and Christianity [3]; 2. Constantine and the Christian Faith: My Fourth Smithsonian Lecture [2]; 3. The Conversion of the Emperor Constantine [3]; 4. The Conversion of Constantine [3]; 5. The Conversion of Constantine and Beyond [3]; 6. The Council of Nicaea and The Resulting View of Christ [3]; 7. Why Did “Orthodox” Christianity Win: Part 2 [1]; 8. When Christianity Became the “Official” Religion of Rome [2]; 9. The Beginning of the End of Paganism [2]; 10. The Rise of Christian Anti-Judaism, in a Nutshell [1]

### 24. What was the Arian controversy about, and how did it shape the Trinity?

Winner: **Ask AI 2**

- **Ask AI 1:** 1. A Heresy that May Not Sound Heretical to You:  Arius of Alexandria [3]; 2. The Council of Nicaea and The Resulting View of Christ [3]; 3. The Controversies about Christ: Arius and Alexander [3]; 4. The Road from the “Duo of Philo” to the “Trinity of Nicaea”–Guest Post by Omar Robb [3]
- **Ask AI 2:** 1. A Heresy that May Not Sound Heretical to You:  Arius of Alexandria [3]; 2. The Council of Nicaea and The Resulting View of Christ [3]; 3. The Controversies about Christ: Arius and Alexander [3]; 4. You Call *This* a Heresy?  The Views of Arius, In His Own Words [2]; 5. Widespread Misconceptions about the Council of Nicea [3]; 6. The Road from the “Duo of Philo” to the “Trinity of Nicaea”–Guest Post by Omar Robb [3]; 7. The Trinity!  A Final Summation [3]; 8. The Doctrine of the Trinity: Where We Are So Far [2]

### 25. How widespread was Roman persecution of Christians before Constantine?

Winner: **Ask AI 2**

- **Ask AI 1:** 1. Early Persecutions of Christians, in a Nutshell [3]; 2. The First Attempts to Wipe Out Christianity [3]; 3. When Emperors Became More Involved in Christian Persecutions [3]; 4. Guest Post by Dr. Paula Fredriksen Part II: The Politics of Piety [2]
- **Ask AI 2:** 1. Early Persecutions of Christians, in a Nutshell [3]; 2. The First Attempts to Wipe Out Christianity [3]; 3. When Emperors Became More Involved in Christian Persecutions [3]; 4. How Significant Was Early Christianity? [3]; 5. Were Christians Statistically Insignificant in the First 200 years? [3]; 6. The Growth of Early Christianity: A Clarification [1]; 7. The Rise of Christian Anti-Judaism, in a Nutshell [1]; 8. Did the Glories of Martyrdom Lead to Christian Conversions? [2]; 9. Heightened Opposition to Jews in Early Christianity [0]; 10. The Historical Background to the Book of Revelation [1]

### 26. What are the earliest surviving manuscripts of the New Testament?

Winner: **Tie**

- **Ask AI 1:** 1. What We Now Know about the Manuscripts of the New Testament [3]; 2. Introduction to the Manuscripts of the New Testament [3]; 3. The Manuscripts of the New Testament [2]; 4. How Accurate Are our Earliest NT Manuscripts? [3]; 5. The Greek Manuscripts of the New Testament [2]; 6. New Testament Manuscripts:  Good News and Bad News [3]; 7. New Testament Manuscripts:  Good News and Bad News [3]; 8. The Irony of our Earliest Manuscripts [3]
- **Ask AI 2:** 1. What We Now Know about the Manuscripts of the New Testament [3]; 2. New Testament Manuscripts:  Good News and Bad News [3]; 3. The Irony of our Earliest Manuscripts [3]; 4. Introduction to the Manuscripts of the New Testament [3]; 5. The Greek Manuscripts of the New Testament [2]; 6. What the New Fragment of Mark’s Gospel Looks like (the so-called First-Century Mark) [2]; 7. Not a Game-Changer?  Why I’d Still Be Thrilled to Have a First-Century Gospel Fragment [1]; 8. How Would a First-Century Fragment of the Gospels Actually Change What We Know/Think? [1]; 9. How Useful Are Our Earliest New Testament Manuscripts? [3]; 10. The Manuscripts of the New Testament [2]

### 27. Did scribes alter New Testament passages to support particular theological beliefs?

Winner: **Ask AI 1**

- **Ask AI 1:** 1. How Consistent are Orthodox Corruptions of Scripture? [3]; 2. Why Scribes Changed Their Manuscripts [3]; 3. Why Did Scribes Add the Bloody Sweat? [3]; 4. Why Would Later Scribes Be Interested In Having Jesus “Sweat Blood”? [3]; 5. Scribes Who Changed the Voice at Jesus Baptism? [3]; 6. An Important and Relevant Textual Variant in Luke 2 [3]; 7. A Final Post (!) on Luke 3:22 [3]; 8. Did God Mock Jesus on the Cross?  A Scribal Change? [3]; 9. An Intentional Change in Mark 15:34 [3]; 10. Jesus Sweating Blood: Transcriptional Probabilities [3]
- **Ask AI 2:** 1. What is An Orthodox Corruption of Scripture? [3]; 2. Back to the Question:  The Orthodox Corruption of Scripture [3]; 3. How Consistent are Orthodox Corruptions of Scripture? [3]; 4. New Testament Manuscripts That Reveal Later Theological Controversies [3]; 5. Why Scribes Changed Their Manuscripts [3]; 6. Why Intentional Changes of the Text Might Matter [2]; 7. How Can You Know A Scribe’s Intentions? [2]; 8. How Can You Tell If the Text Has Been CHANGED? [2]; 9. Intentional Changes of the Text [1]; 10. Anti-Jewish Alterations of the New Testament Writings? [3]

### 28. Why does the King James Bible contain readings scholars now reject?

Winner: **Ask AI 1**

- **Ask AI 1:** 1. Where Did the King James Bible Come From? [3]; 2. Problems with the King James Version: What Were the Translators Translating? [3]; 3. How the Trinity Got Into the New Testament: Part 2 [3]; 4. The Trinity in the King James Bible [3]; 5. The Ending of Mark in the King James Bible [3]; 6. The Woman Taken in Adultery in the King James Version [3]; 7. Textual Problems with the King James: The Trinity [3]; 8. Problems with Some Bible Translations, including the King James: A Blast from the Past [3]; 9. What Kind of a Text is the King James Bible? [2]; 10. Leading up to the King James Translation [1]
- **Ask AI 2:** 1. Problems with the King James Version: What Were the Translators Translating? [3]; 2. Where Did the King James Bible Come From? [3]; 3. Textual Problems with the King James: The Trinity [3]; 4. The Trinity in the King James Bible [3]; 5. The Ending of Mark in the King James Bible [3]; 6. Printing Errors in the King James Version [1]; 7. Infamous Typos in the King James Bible [1]; 8. Problems with Some Bible Translations, including the King James: A Blast from the Past [3]; 9. What Kind of a Text is the King James Bible? [2]; 10. The King James Bible: Some Intriguing Word Choices [1]

### 29. How can scholars tell whether an ancient Christian writing was forged?

Winner: **Ask AI 1**

- **Ask AI 1:** 1. Were Ancient Readers Interested in Detecting Forgeries? [3]; 2. Forged Books, Anonymous Books, and The Use of Secretaries as Authors in the NT [2]; 3. Forgery Lecture [2]; 4. The Different Terms for Literary Deception [2]; 5. Different Kinds of Literary Deceit [2]; 6. My Lecture in Quebec:  Did Ancient Authors Try To Deceive Their Readers? [3]; 7. My book: Literary Forgery and Counterforgery in Early Christianity! [3]; 8. Forgery for a Scholarly Audience [2]; 9. Video:  Forgery in the New Testament [2]; 10. 2 Thessalonians as a Forgery?  Does the Author “Write” Like Paul? [3]
- **Ask AI 2:** 1. Were Ancient Readers Interested in Detecting Forgeries? [3]; 2. My Lecture in Quebec:  Did Ancient Authors Try To Deceive Their Readers? [3]; 3. Does a Different “Writing Style” Show (convincingly?) That Ephesians is a “Forgery”? [3]; 4. How Did Ancient Writers Use Secretaries?  A Blast from the Past [2]; 5. How Can You Tell If the Text Has Been CHANGED? [2]; 6. Why Scribes Changed Their Manuscripts [1]; 7. How Did Scribes Change Their Manuscripts? [1]; 8. What Motivated Some Ancient Authors to Lie About Themselves? [2]; 9. How Can You Know A Scribe’s Intentions? [1]; 10. A Recent Argument that Ancient Pseudepigraphy Was NOT Deceptive (or Meant to Be) [2]

### 30. What is the strongest historical evidence that Jesus really existed?

Winner: **Ask AI 2**

- **Ask AI 1:** 1. Some Key Evidence for Jesus [3]; 2. Non-Christian Sources for Jesus: An Interview with History.com [3]; 3. Was Jesus a Myth or a Historical Figure? Robert Price and I Debate This Very Interesting Question [3]; 4. What We KNOW about Jesus.  Platinum guest post by Dan Kohanski [3]; 5. Do Any Ancient Jewish Sources Mention Jesus?  Weekly Mailbag [2]; 6. A Recent Interview [1]; 7. Jesus Books [1]; 8. Four Intriguing Topics in the Study of the Historical Jesus [1]
- **Ask AI 2:** 1. Evidence for Jesus Outside the New Testament: Part 2 of My Exchange with Ben Witherington [3]; 2. Some Key Evidence for Jesus [3]; 3. Paul and the Historical Jesus [3]; 4. Paul’s Acquaintances: Jesus’ Disciples and Brother [3]; 5. What Did Paul Know About the Historical Jesus? [3]; 6. Did Jesus Exist?  My Debate with Robert Price [2]; 7. Multiple Attestation for Jesus [3]; 8. The Gospels and the Existence of Jesus [3]; 9. Q & A with Ben Witherington: Part 4 [3]; 10. Non-Christian Sources for Jesus: An Interview with History.com [3]

### 31. How do scholars decide who wrote works such as 1 Clement and the Letter of Barnabas?

Winner: **Ask AI 1**

- **Ask AI 1:** 1. Did “Pope” Clement Write 1 Clement? [3]; 2. Did a “Pope” Write the First-Century Book of 1 Clement? [3]; 3. Why Was the Letter of Barnabas Attributed to Barnabas (Part 2) [2]; 4. Why Was Barnabas Attributed to Barnabas: Part 2 [2]; 5. Why Was The Letter of Barnabas Attributed to Barnabas? [2]; 6. Why Would Anyone Claim Barnabas Wrote “The Epistle of Barnabas”? [2]; 7. Some Interesting Random Questions [1]
- **Ask AI 2:** 1. Deciding on Which Books Should Be in the New Testament [2]; 2. Authors, Authorities, and Who Gets To Write the Bible [3]; 3. The Letter of First Clement: An Overview [3]; 4. Did “Pope” Clement Write 1 Clement? [3]; 5. 1 Clement in a Nutshell [1]

### 32. What obligations do Christians have to give money to people in need?

Winner: **Tie**

- **Ask AI 1:** 1. Love in Action: Christian Views of Charitable Giving [3]; 2. Is It Even Possible to Follow Jesus’ Teaching to “Love Your Neighbor as Yourself” [2]; 3. You Mean Everyone (Except the Truly Destitute) Needs to Give? But How Much? [3]; 4. Softening Jesus’ Message on Giving up (Literally) Everything [3]; 5. “Redemptive Gifts”: Can Giving to Charity Save Your Soul? [3]; 6. Concerns for the Poor in the Jewish Tradition [2]; 7. Were Early Christians Really Charitable?  Or Was It All Talk? [3]; 8. Why I Wrote Love Thy Stranger and Significant Benefits that Can Come Your Way [1]; 9. Did Christians Invent Charity? [3]; 10. Love.  How I’ve Shifted the Focus of My Book on Charity. [2]
- **Ask AI 2:** 1. Love in Action: Christian Views of Charitable Giving [3]; 2. Were Early Christians Really Charitable?  Or Was It All Talk? [3]; 3. You Mean Everyone (Except the Truly Destitute) Needs to Give? But How Much? [3]; 4. Softening Jesus’ Message on Giving up (Literally) Everything [3]; 5. Jesus’ Teachings on Love and Salvation [2]; 6. “Redemptive Gifts”: Can Giving to Charity Save Your Soul? [3]; 7. Concerns for the Poor in the Jewish Tradition [2]; 8. How I Begin My Book on Jesus, Ethics, and Altruism [1]; 9. What the Earliest *Christians* Thought About Wealth [3]; 10. The New Book I’m Writing About Altruism:  Putting It In a Nutshell [1]

### 33. What important early Christian writings have been lost or survived only in fragments?

Winner: **Ask AI 2**

- **Ask AI 1:** 1. Lost Scriptures [3]; 2. Lost Christian Writings I’d Love to Get My Hands On! [3]; 3. Back To the Discovery of Lost Early Christian Writings [3]; 4. ALL the Christian Writings of the First Hundred Years [3]; 5. Finding All the Earliest Christian Texts in One Place [2]; 6. My Early Christian Apocrypha Seminar [1]
- **Ask AI 2:** 1. More Lost Scriptures [3]; 2. ALL the Christian Writings of the First Hundred Years [3]; 3. Paul’s Lost Letters [3]; 4. Lost Christian Writings: The Letters of Paul [3]; 5. Lost Letters of Paul’s Opponents [3]; 6. The Importance of What Is Lost: Paul’s Letters [3]; 7. The Lost Writings of Papias [3]; 8. Papias in a Nutshell.  An Important Figure Among the Apostolic Fathers [3]; 9. The Lost Q Source [3]; 10. Lost Gospels That Are Still Lost 4: Q [3]

### 34. How did anti-Jewish ideas enter early Christian writings?

Winner: **Ask AI 2**

- **Ask AI 1:** 1. When Christians Went on the Attack Against Jews [3]; 2. How “Jews” Became “Children of the Devil” in the New Testament [3]; 3. The Rise of Christian Anti-Judaism, in a Nutshell [3]; 4. Why Christians Needed an Old Testament: Pagan Attacks on the Faith [2]; 5. Should the Old Testament Even Be in the Bible? [3]; 6. The Epistle of Barnabas in a Nutshell (Part 1) [3]; 7. More on Jews, Christians, and the Battle for Scripture [3]; 8. Is the Old Testament a Christian Book? [3]; 9. Anti-Judaism in the Gospels [3]; 10. Anti-Judaism in the Gospels: A Blast From the Past [3]
- **Ask AI 2:** 1. The Rise of Christian Anti-Judaism, in a Nutshell [3]; 2. When Christians Went on the Attack Against Jews [3]; 3. How “Jews” Became “Children of the Devil” in the New Testament [3]; 4. Anti-Judaism in the Gospels [3]; 5. Are the Gospels Anti-Jewish? [3]; 6. Is the Gospel of Luke Anti-Jewish? [3]; 7. Anti-Judaism in the Gospel of Luke [3]; 8. The Epistle of Barnabas in a Nutshell (Part 1) [3]; 9. Melito and arly Christian Anti-Judaism [3]; 10. Heightened Opposition to Jews in Early Christianity [3]

### 35. Can several people genuinely share the same religious vision?

Winner: **Ask AI 2**

- **Ask AI 1:** 1. Are Group Visions Possible? [3]; 2. What Really Happens With Group Visions [3]; 3. How Can “Group Hallucinations” Possibly Happen? [3]; 4. A Final Word (I Think!) on Group Visions [3]; 5. Are “Group Hallucinations” Possible?  The Case of Mary. [3]; 6. Group Visions and Agnostic Jesus Scholars: Mailbag March 12, 2017 [3]; 7. Visions of Mary [2]; 8. Modern Appearances of Jesus [2]; 9. Modern Visions of Jesus [2]
- **Ask AI 2:** 1. Are “Group Hallucinations” Possible?  The Case of Mary. [3]; 2. Are Group Visions Possible? [3]; 3. What Really Happens With Group Visions [3]; 4. A Final Word (I Think!) on Group Visions [3]; 5. How Can “Group Hallucinations” Possibly Happen? [3]; 6. Did Some Disciples Not Believe in the Resurrection? [2]; 7. Group Visions and Agnostic Jesus Scholars: Mailbag March 12, 2017 [3]; 8. Did Disciples Have Visions of Jesus? [3]; 9. Two Versions of Constantine’s Vision [1]; 10. Constantine’s Vision according to Eusebius [1]

### 36. What did everyday belief and practice look like in the earliest Christian communities?

Winner: **Ask AI 2**

- **Ask AI 1:** 1. Demons and Christians in Antiquity: Guest Post by Travis Proctor [2]; 2. How Did Christianity Start? [1]; 3. The Resurrection and the Beginning of the Church [1]
- **Ask AI 2:** 1. An Important Early Christian Writing [3]; 2. The Didache: An Important Early Christian Document in a Nutshell [3]; 3. Intriguing Instructions for How To Run the Church:  More on the Didache [3]; 4. Were Early Christians Really Charitable?  Or Was It All Talk? [2]; 5. You Mean Everyone (Except the Truly Destitute) Needs to Give? But How Much? [3]; 6. Is Christian Love Different from Love? [2]; 7. How Strikingly Few Early Churches Were There?  How Amazingly Many Christian Letters? [2]; 8. How Many Christians Could Read? [2]; 9. Women and Gender: Early Christianity in a Patriarchal World [2]; 10. Why the Spirit Mattered for the Earliest Christians [2]

### 37. How do historians reconstruct Jesus' life when the surviving sources disagree?

Winner: **Ask AI 2**

- **Ask AI 1:** 1. But Did It Really Happen? [3]
- **Ask AI 2:** 1. The Historian’s Wish List [3]; 2. Problems with the Gospels: A Primer for the Study of the Historical Jesus (Part 2) [3]; 3. Knowing What Jesus Said and Did [3]; 4. Question on Mistakes in Ancient Sources [3]; 5. A Return to the Historical Jesus [3]; 6. Rules of Thumb for Reconstructing the History behind the Gospels [3]; 7. Is History Possible? [3]; 8. More Background on Oral Traditions [2]; 9. How Can We Get Behind “False Memories” of Jesus to the Historical Facts? [3]; 10. Why Are Their Differences in the Gospels?  Does it Affect Their Inspiration?  Guest Post by Mike Licona [3]

### 38. How might human memory have changed stories about Jesus before the Gospels were written?

Winner: **Ask AI 2**

- **Ask AI 1:** 1. Q & A about Jesus Before the Gospels, Part 1 [3]; 2. Q & A about Jesus Before the Gospels, Part 3 [3]; 3. Q & A about Jesus Before the Gospels: Part 2 [3]
- **Ask AI 2:** 1. Press Release!  Jesus Before the Gospels [3]; 2. Q & A about Jesus Before the Gospels: Part 2 [3]; 3. Q & A about Jesus Before the Gospels, Part 3 [3]; 4. Q & A about Jesus Before the Gospels, Part 1 [3]; 5. Jesus Before the Gospels in Relation to My Other Books [2]; 6. My Forgotten Book on Memory [3]; 7. Does Understanding “Memory” Have Any Bearing on the Study of the Historical Jesus? [3]; 8. Being Realistic about How Stories about Jesus Spread before the Gospels [3]; 9. Did Early Christians “Invent” Memories of Jesus? [3]; 10. Stories of Jesus Passed on By Word of Mouth.  When Scholars First Took Oral Traditions Seriously. [3]

### 39. Why did early Christians think Jesus' death brought salvation?

Winner: **Ask AI 2**

- **Ask AI 1:** 1. The Death of the Messiah for Salvation [3]; 2. Weekly Readers’ Mailbag:  January 8, 2016 [3]; 3. Readers’ Mailbag November 13, 2015 [2]; 4. Jesus’ Death; Good Scholars; and Writing the First Book: Readers’ Mailbag May 28, 2016 [3]; 5. Jesus’ Death and Resurrection for Salvation; Paul’s Collection; and My Sunday Mornings: Readers’ Mailbag June 11, 2016 [3]; 6. More Interesting Questions from Blog Readers [2]; 7. Did Christians Invent the Idea of “Atonement” / “Vicarious Suffering”? [2]; 8. Platinum Webinar!  Forgiveness vs. Atonement [3]; 9. When Is Forgiveness not Forgiveness? [3]; 10. Did Jesus Think He Was Going to Atone for the Sins of the World? A Platinum Post by Manuel Fiadeiro [3]
- **Ask AI 2:** 1. The Death of the Messiah for Salvation [3]; 2. The Resurrection as a Key to Early Understandings of Jesus [3]; 3. Who Invented the Idea of a Suffering Messiah? [3]; 4. Where Did the Idea of a “Suffering Messiah” Come From? [3]; 5. The Core of Paul’s Gospel [3]; 6. How Did Christianity Start? [3]; 7. A Particular Problem with a Crucified Messiah [3]; 8. How Did Paul Understand Salvation?  The “Judicial” Model [2]; 9. How a Non-Historical Account Can Be Meaningful: The Death of Jesus in Mark [3]; 10. Salvation, Love, and the Jewish Law in Paul.  Are His Views Internally Coherent? [3]

### 40. Why is Codex Sinaiticus important for understanding the Bible's text?

Winner: **Ask AI 1**

- **Ask AI 1:** 1. The Discovery of Codex Sinaiticus: One of the Most Important Manuscripts of the New Testament [3]; 2. Tischendorf and the Discovery of Codex Sinaiticus [3]; 3. My Trip to Saint Catherine’s Monastery on Mount Sinai: Discovery Site of Codex Sinaiticus [3]; 4. St. Catherine’s Monastery [3]; 5. Visiting the Monastery at Mount Sinai: A Blast From the Past [3]
- **Ask AI 2:** 1. The Discovery of Codex Sinaiticus: One of the Most Important Manuscripts of the New Testament [3]; 2. Tischendorf and the Discovery of Codex Sinaiticus [3]; 3. Visiting the Monastery at Mount Sinai: A Blast From the Past [3]; 4. St. Catherine’s Monastery [3]; 5. What We Now Know about the Manuscripts of the New Testament [2]; 6. Introduction to the Manuscripts of the New Testament [1]

### 41. Did ancient Jews and Christians believe consciousness continued immediately after death?

Winner: **Ask AI 2**

- **Ask AI 1:** 1. The Afterlife in the Hebrew Bible: Sheol [3]
- **Ask AI 2:** 1. Life After Death According to Samuel [3]; 2. What’s It Like in Sheol? [3]; 3. Speaking in Churches as an Agnostic; and Jewish Beliefs about Afterlife.  Readers Mailbag August 13, 2016 [2]; 4. Jewish Disagreements About the Afterlife: Pharisees and Sadducees [2]; 5. Possibilities for the Afterlife [2]; 6. Does Your Soul Go To Heaven? [3]; 7. Reviewing the Afterlife [3]; 8. (Later) Early Christian Understandings of Heaven and Hell [3]; 9. Heaven and Hell: When was Heaven and Hell Invented? [2]; 10. Life After Death in Rome, and other Questions.  Readers’ Mailbag May 6, 2016 [1]

### 42. What leadership role did James, the brother of Jesus, have in the early church?

Winner: **Ask AI 2**

- **Ask AI 1:** 1. Paul’s Acquaintances: Jesus’ Disciples and Brother [3]; 2. James the Brother of the Lord [3]; 3. The Accuracy of Paul’s Letter to the Galatians [2]; 4. Carrier and James the Brother of Jesus [1]; 5. One of My Favorite Letters in the New Testament: The Book of James [3]
- **Ask AI 2:** 1. Why Did the Author of James Claim to be James in Particular? [3]; 2. Paul’s Acquaintances: Jesus’ Disciples and Brother [3]; 3. One of My Favorite Letters in the New Testament: The Book of James [3]; 4. Did Paul Get Along with the Other Apostles? [3]; 5. The Accuracy of Paul’s Letter to the Galatians [2]; 6. Did Paul Know Much about the Historical Jesus? [2]

### 43. Was the story of Jesus sweating blood originally part of Luke's Gospel?

Winner: **Tie**

- **Ask AI 1:** 1. Did Jesus Sweat Blood?  Another Problem with the NRSV [3]; 2. Did Scribes Add the Passage of the Bloody Sweat? [3]; 3. Jesus’ Sweating Blood and “intrinsic” evidence [3]; 4. Problems with the NRSV (Part 4) [3]; 5. Problems with the NRSV (Part 5) [3]; 6. An Unexpected Argument Against Jesus’ “Sweating Blood” [3]; 7. Jesus in the Face of Death? [3]; 8. Did Jesus Sweat Blood?  “Intrinsic” Evidence for Textual Variants [3]; 9. When I First Realized the Importance of Textual Criticism: The Bloody Sweat [3]; 10. Jesus’ Lack of Agony [3]
- **Ask AI 2:** 1. Did Jesus Sweat Blood?  Another Problem with the NRSV [3]; 2. Problems with the NRSV (Part 4) [3]; 3. Jesus’ Lack of Agony [3]; 4. Jesus in the Face of Death? [3]; 5. Was Jesus in Agony Before His Arrest?  The Unexpected Answer in Luke. [3]; 6. More on The Bloody Sweat [3]; 7. Did Scribes Add the Passage of the Bloody Sweat? [3]; 8. Why Did Scribes Add the Bloody Sweat? [3]; 9. An Unexpected Argument Against Jesus’ “Sweating Blood” [3]; 10. Did Jesus Sweat Blood?  “Intrinsic” Evidence for Textual Variants [3]

### 44. Why does Bart describe himself as both an agnostic and an atheist?

Winner: **Ask AI 1**

- **Ask AI 1:** 1. Why Would I Call Myself Both an Agnostic or an Atheist?  A Blast From the Past [3]; 2. Why Would I Call Myself Both an Agnostic and an Atheist?  A Blast from the Past [3]; 3. Agnostic or Atheist? [3]; 4. Am I an Agnostic or an Atheist? [3]; 5. On Being an Agnostic Atheist [3]; 6. Readers’ Mailbag December 11, 2015 [3]; 7. A Revelatory Moment about God: Most-Commented Blog Post: #3 [3]; 8. Can You Disprove the Existence of God? [2]
- **Ask AI 2:** 1. Why Would I Call Myself Both an Agnostic and an Atheist?  A Blast from the Past [3]; 2. Why Would I Call Myself Both an Agnostic or an Atheist?  A Blast From the Past [3]; 3. Agnostic or Atheist? [3]; 4. Am I an Agnostic or an Atheist? [3]; 5. On Being an Agnostic Atheist [3]; 6. Fundamentalism and the Truth of the Bible [1]; 7. The Threat of Judgment [2]; 8. My Birdbrain View of Agnosticism [2]; 9. A Revelatory Moment about “God” [3]

### 45. What can Paul's letters tell us about his life that Acts does not?

Winner: **Ask AI 2**

- **Ask AI 1:** 1. Does the Book of Acts Accurately Portray the Life and Teachings of Paul? [3]; 2. Paul the Persecutor and the Historical Jesus [3]; 3. The Quest for the Historical Paul: Sorting Through Our Sources (Part 1). Guest Post by James Tabor [3]; 4. How Do We Know the Chronology of Paul’s Life and Letters? [2]
- **Ask AI 2:** 1. The Life of Paul in a Nutshell [3]; 2. The Quest for the Historical Paul: Sorting Through Our Sources (Part 1). Guest Post by James Tabor [3]; 3. Paul the Persecutor and the Historical Jesus [3]; 4. The Conversion of Paul [3]; 5. Lost Christian Writings: The Letters of Paul [1]; 6. The Significance and Letters of Paul, in a Nutshell [2]; 7. Two Live Lectures, Sunday March 21:  The Death of Jesus and the Life of Paul. [0]; 8. Paul and the Historical Jesus [1]; 9. What Did Paul Know About the Historical Jesus? [1]; 10. Was Paul Authorized to Persecute Christians? [3]

### 46. How do New Testament letters advise Christians to respond to persecution and suffering?

Winner: **Tie**

- **Ask AI 1:** 1. 1 Peter in a Nutshell [3]; 2. The Letter to the Hebrews: In a Nutshell [3]; 3. The Situation Behind the (“Forged”) Book of 1 Peter [3]; 4. The So-Called First Letter of Peter [3]; 5. 1 Thessalonians in a Nutshell [3]; 6. 2 Thessalonians in a Nutshell [3]; 7. 1 Peter: Who Wrote It, When, and Why? [3]
- **Ask AI 2:** 1. The So-Called First Letter of Peter [3]; 2. 1 Peter in a Nutshell [3]; 3. The Letter to the Hebrews: In a Nutshell [3]; 4. 1 Thessalonians in a Nutshell [3]; 5. 2 Thessalonians in a Nutshell [3]; 6. One of My Favorite Letters in the New Testament: The Book of James [1]; 7. Are There Two Letters to the Philippians? [2]; 8. The Situation Behind the (“Forged”) Book of 1 Peter [3]; 9. Hebrews and James:  “At a Glance” and “Questions for Reflection” [2]; 10. Jesus’ Death in Mark and Luke [0]

### 47. What different explanations did Paul give for how Christ saves people?

Winner: **Ask AI 1**

- **Ask AI 1:** 1. Unusually Important for the Letter to the Romans: Paul’s Models of Salvation [3]; 2. Still Other Models of Salvation in Paul [3]; 3. Paul’s Models of Salvation: Contradictory or Complementary? [3]; 4. Other Models of Salvation in Paul [3]; 5. Comparison of Paul’s Two Principal Models of Salvation [3]; 6. How Did Paul Understand Salvation?  The “Judicial” Model [3]; 7. Paul’s “Participationist” Model of Salvation. [3]; 8. Paul’s “Judicial” Model of Salvation [3]; 9. Paul’s “Participationist” Model of Salvation [3]; 10. Why Did Paul Think *Faith* Would Bring Salvation? [2]
- **Ask AI 2:** 1. Unusually Important for the Letter to the Romans: Paul’s Models of Salvation [3]; 2. Comparison of Paul’s Two Principal Models of Salvation [3]; 3. Other Models of Salvation in Paul [3]; 4. Still Other Models of Salvation in Paul [3]; 5. Paul’s “Participationist” Model of Salvation. [3]; 6. Paul’s “Participationist” Model of Salvation [3]; 7. Paul’s Letter to the Romans “At a Glance,” and Questions for Reflection [3]; 8. Paul’s “Exceptional” Letter to the Romans [1]; 9. Paul’s Own (and Only) Gospel [2]; 10. The Core of Paul’s Gospel [2]

### 48. Would eyewitness testimony make the Gospel accounts historically reliable?

Winner: **Ask AI 2**

- **Ask AI 1:** 1. Bart Ehrman vs Richard Bauckham – Round 1 [2]; 2. My Debate with Richard Bauckham – Round 2 [2]; 3. While We’re Talking About the Reliability of Eyewitnesses… [3]; 4. Eyewitnesses and the Gospels: A Blast From the Past [3]; 5. Question about Eyewitnesses and the Gospels [3]; 6. Readers’ Questions on the Accuracy of the Gospels [2]; 7. Who Was Spreading the Stories about Jesus Before the Gospels? [2]; 8. The Gospel of Mark: Who, When, and Why [2]; 9. The Gospel of John:  Who Wrote It, When, and Why? [2]; 10. Papias and the Gospels: Some Background [1]
- **Ask AI 2:** 1. Bart Ehrman vs Richard Bauckham – Round 1 [2]; 2. My Debate with Richard Bauckham – Round 2 [2]; 3. More on the Life of Brian and the Historical Jesus [3]; 4. Eyewitnesses and the Gospels: A Blast From the Past [3]; 5. Question about Eyewitnesses and the Gospels [3]; 6. While We’re Talking About the Reliability of Eyewitnesses… [3]; 7. The Value of Eyewitness Testimony [3]; 8. Eyewitness Testimony: The Importance of Actual Expertise [3]; 9. Eyewitnesses and Guaranteed Accuracy [3]; 10. The Historian’s Wish List [3]

### 49. How is the New Testament taught in a critical university course?

Winner: **Ask AI 2**

- **Ask AI 1:** 1. My New Testament Syllabus [3]; 2. Undergraduate Courses (1): Introduction to the New Testament (Part 1) [3]; 3. Undergraduate Courses (2): Introduction to the New Testament (Part 2) [3]; 4. What’s It Like to Teach at a Research University? [3]; 5. Teaching at Carolina [1]; 6. My Greek New Testament Course [3]; 7. My Graduate Level New Testament Course [3]; 8. My Recitation Debates [3]; 9. Wanna Take My Final Exam In New Testament? [2]; 10. Take A Final Exam on the New Testament! [2]
- **Ask AI 2:** 1. My New Testament Syllabus [3]; 2. Teaching the Bible as a Historical Book [3]; 3. What’s It Like to Teach at a Research University? [3]; 4. The Work of a Professional Scholar 4: Undergraduate Courses [3]; 5. Undergraduate Courses (1): Introduction to the New Testament (Part 1) [3]; 6. Undergraduate Courses (2): Introduction to the New Testament (Part 2) [3]; 7. Writing a Historical-Critical Textbook that Isn’t *Critical* [2]; 8. Can My Undergraduate Students Continue Believing the Bible is Inerrant? [3]; 9. Placing the New Testament in Its Own Historical Context [2]; 10. The Academic Study of the New Testament [3]

### 50. How did traditions about Mary, the mother of Jesus, develop beyond the New Testament?

Winner: **Tie**

- **Ask AI 1:** 1. The Gospel Before the Gospel: The Proto-Gospel of James [3]; 2. How Was Jesus *Really* Born?  The Proto-Gospel of James [3]; 3. You Won’t Find *This* in the New Testament! [3]; 4. A Very Odd Story about the Baby Jesus [3]; 5. Twelve Days of Christmas Day 3: A Different Account of Joseph and Mary! [3]; 6. A Different Account of Joseph and Mary! [3]; 7. An Apocryphal Story of Mary’s Conception of Jesus [3]; 8. The Virgin Birth and Jesus’ Brothers [3]; 9. Jesus’ Mother and Brothers in Mark [1]
- **Ask AI 2:** 1. The Gospel Before the Gospel: The Proto-Gospel of James [3]; 2. How Was Jesus *Really* Born?  The Proto-Gospel of James [3]; 3. Twelve Days of Christmas Day 3: A Different Account of Joseph and Mary! [3]; 4. A Different Account of Joseph and Mary! [3]; 5. Jesus’ Brothers?!?  And the Proto-Gospel of James [3]; 6. Jesus’ (Young?) Mother and (Half?) Brothers? The Proto-Gospel of James [3]; 7. Newsweek Article on Christmas: Part 1 [3]; 8. My Article on Christmas in Newsweek [3]; 9. The Virgin Birth in Matthew and Luke [1]; 10. The Virgin Birth and the Gospel of John: A Blast from the Past [2]

## Limitations

This is a controlled editorial benchmark, not user testing. It uses one blinded grading pass, the current local corpus, and the current implementations at the time of the run. AI interpretation and refinement are nondeterministic, so a later run can differ. The results measure the first ten posts, where ordering matters most, rather than every post returned.

## Artifacts

- Frozen questions: `.tmp/ask_ai_full_text_eval_2026-08-29/questions.json`
- Raw retrieval output: `.tmp/ask_ai_full_text_eval_2026-08-29/retrieval-results.json`
- Blinded full-text grades and metrics: `.tmp/ask_ai_full_text_eval_2026-08-29/full-text-evaluation.json`
