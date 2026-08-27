# Ask AI and Ask AI 2: 50-Question Evaluation

Date: August 26, 2026

## Method

Fifty varied questions were submitted to the local WordPress REST pipelines used by the two public interfaces. Ask AI interpreted each question as controlled topics or keywords, searched those assignments, and refined the matching posts from their titles and search summaries. Ask AI 2 retrieved posts semantically from titles and search summaries and then applied the same AI refinement stage.

Each result set was judged against the question using relevance, coverage, precision, and ranking. A larger result set was not automatically considered better. Temporary upstream throughput errors were retried; the final dataset contains no endpoint failures and every Ask AI 2 result was successfully refined.

## Overall Results

| Outcome | Questions |
|---|---:|
| Ask AI 2 better | 25 |
| Ask AI better | 18 |
| Essentially tied | 7 |

| Runtime measure | Ask AI | Ask AI 2 |
|---|---:|---:|
| Average response time | 3.84 seconds | 2.53 seconds |
| Average displayed posts | 8.2 | 12.0 |
| Zero-result questions | 1 | 0 |

These judgments are editorial relevance assessments, not automated scores.

## Question-by-Question Assessment

| # | Question | Better result | Reason |
|---:|---|---|---|
| 1 | Why do scholars think the Gospels were written anonymously? | Ask AI 2 | Preserved the strongest anonymity posts and added directly useful material on when the Gospels received names and traditional authors. |
| 2 | Was the original ending of Mark lost or added later? | Ask AI 2 | Added closely related manuscript and translation discussions while keeping the key ending-of-Mark posts first. |
| 3 | How do Matthew and Luke tell different stories about Jesus' birth? | Ask AI 2 | Ranked a direct Matthew-Luke contrast first and supplied stronger coverage of Bethlehem, Nazareth, and the competing narratives. |
| 4 | How does Luke's Sermon on the Plain differ from Matthew's Sermon on the Mount? | Ask AI 2 | Ask AI's strict Matthew-plus-Luke intersection returned zero; Ask AI 2 found two pertinent posts. |
| 5 | What does the parable of the rich man and Lazarus teach about the afterlife? | Ask AI 2 | Retained Ask AI's two exact matches and added three posts that materially discuss the parable's wealth and afterlife message. |
| 6 | What historical evidence is there for Jesus' empty tomb? | Ask AI 2 | Offered broader direct evidence concerning burial, women at the tomb, and the Gospel resurrection narratives. |
| 7 | Why do Matthew and Acts describe Judas' death differently? | Ask AI | Its five results remained centered on Judas's conflicting death accounts; Ask AI 2 drifted into betrayal and Paul's knowledge of Judas. |
| 8 | Would Romans normally have allowed a crucified person like Jesus to be buried? | Ask AI | Its results stayed focused on Roman crucifixion and burial policy, while Ask AI 2 introduced less direct Life of Brian and general burial material. |
| 9 | Why is the woman caught in adultery missing from the earliest manuscripts of John? | Ask AI 2 | Added directly relevant posts about omitted passages and translator treatment without losing the strongest textual-variant results. |
| 10 | Did Luke originally say that Jesus sweated blood? | Tie | Both returned extensive, highly focused discussions of the bloody-sweat variant and scribal evidence. |
| 11 | How does Paul's account of his conversion differ from the story in Acts? | Ask AI 2 | Ask AI found two exact posts, but Ask AI 2 added useful discussions of Paul's portrayal and Acts' historical accuracy. |
| 12 | Does Acts accurately describe Paul's conflict over Jewish law in Galatians? | Ask AI 2 | Ask AI's strict Acts-plus-Galatians intersection returned only two indirect specialist posts; Ask AI 2 found the broader historical dispute. |
| 13 | Why do scholars question whether Paul wrote Colossians? | Ask AI | Its six results were all tightly focused on Colossians authorship; Ask AI 2 added only marginal value. |
| 14 | Was 2 Thessalonians forged in Paul's name? | Ask AI 2 | Both were strong, but Ask AI 2 added more directly relevant forgery and theological-argument posts. |
| 15 | What did Paul teach about women speaking and leading in church? | Ask AI | Included the crucial silencing-of-women interpolation and women-apostles discussions in addition to general Pauline views. |
| 16 | What kind of resurrection body did Paul expect believers to have? | Tie | Both emphasized Paul's spiritual body, flesh, resurrection, and eternal-life teaching; Ask AI 2 was broader without a decisive quality gain. |
| 17 | Do James and Paul disagree about faith and works? | Ask AI | Its six results all addressed the James-Paul relationship; Ask AI 2 added authorship and general James posts. |
| 18 | What does the Christ poem in Philippians say about Jesus before his birth? | Ask AI 2 | Added particularly useful incarnation and preexistence material while retaining the main Christ-poem series. |
| 19 | Were Peter and Cephas the same person? | Ask AI 2 | Returned a more complete but still highly focused set of the Peter-Cephas arguments. |
| 20 | What role did John the Baptist play in Jesus' ministry? | Ask AI | Its smaller set concentrated on Jesus' baptism and apocalyptic ministry; Ask AI 2 added broader Jesus and Gospel material. |
| 21 | Was Mary Magdalene really a prostitute? | Tie | Both returned the same four focused posts in nearly the same order. |
| 22 | What do our sources say about Jesus' brothers and sisters? | Ask AI | Its family-traditions set covered James, the brothers, Mark, the Proto-Gospel of James, and mythicist disputes more consistently. |
| 23 | Was Jesus actually raised in Nazareth? | Ask AI 2 | Added relevant Bethlehem-Nazareth source comparisons to Ask AI's three direct Nazareth posts. |
| 24 | Did Jesus expect the world to end during his generation? | Ask AI 2 | Correctly emphasized the apocalyptic Jesus, the end, and the false-prophet question; Ask AI interpreted the request only as Kingdom of God. |
| 25 | How can historians evaluate miracle stories about Jesus? | Ask AI | Its historical-method results were more precise; Ask AI 2 wandered into memory and eyewitness topics. |
| 26 | What did Jesus mean by the Kingdom of God? | Ask AI | Its five posts directly explained the meaning and centrality of the Kingdom; Ask AI 2 added more contextual but less necessary material. |
| 27 | Did Jesus require his followers to give away their wealth? | Ask AI | Stayed focused on voluntary poverty and Jesus' wealth teaching; Ask AI 2 drifted into Paul-Matthew comparisons. |
| 28 | What does the Bible say about same-sex relationships? | Tie | Both returned the same seven central posts; Ask AI 2 added one weakly related interpretation post. |
| 29 | Why does an all-powerful and loving God allow innocent people to suffer? | Tie | Both supplied strong and comprehensive treatments of the problem of suffering. |
| 30 | When did Christians begin believing that souls go immediately to heaven or hell? | Ask AI 2 | Selected five unusually precise posts on the historical transition instead of displaying a larger general afterlife set. |
| 31 | What is the main message of the Book of Revelation? | Ask AI | Its results stayed centered on Revelation itself; Ask AI 2 added generic apocalypse and apocalypticism discussions. |
| 32 | How did Christians decide which books belonged in the New Testament? | Tie | Both returned nearly the same excellent canon-formation sequence. |
| 33 | Could the Gospel of Thomas preserve authentic sayings of Jesus? | Ask AI 2 | Added posts about Thomas's relationship to other Gospels and the historicity of its traditions. |
| 34 | Why does the Gospel of Judas portray Judas as Jesus' favored disciple? | Ask AI 2 | Added Sethian and Gnostic interpretive context; Ask AI included more discovery-history posts that did not answer the question. |
| 35 | What does the Gospel of Mary say about Mary Magdalene and the apostles? | Ask AI 2 | Added the directly relevant conflict between Peter and Mary to the single general Gospel of Mary result. |
| 36 | What childhood miracles does the Infancy Gospel of Thomas attribute to Jesus? | Ask AI 2 | Expanded three exact posts to five still-focused treatments of Jesus as a miraculous and mischievous child. |
| 37 | How did the Proto-Gospel of James influence beliefs about Mary and Jesus' birth? | Ask AI | Its posts more consistently addressed Mary, Jesus' birth, and family traditions; Ask AI 2 included generic orthodoxy material. |
| 38 | Were Gnostic Christians a single unified movement? | Ask AI 2 | Supplemented general Gnosticism posts with early-Christian diversity and distinct Gnostic groups, directly addressing the premise of unity. |
| 39 | Why did Marcion reject the Old Testament and edit Luke and Paul's letters? | Ask AI | Stayed centered on Marcion's theology, Scriptures, and Pauline forgeries; Ask AI 2 drifted into Barnabas and general docetism. |
| 40 | How did Constantine affect the development of Christianity? | Tie | Both returned strong conversion, imperial, Nicaea, and Christian-expansion material. |
| 41 | What was the Arian controversy about, and how did it shape the Trinity? | Ask AI 2 | Combined Arius and Nicaea with a fuller set of Trinity-development posts. |
| 42 | How widespread was Roman persecution of Christians before Constantine? | Ask AI | Its three posts directly traced imperial persecution; Ask AI 2 added several posts about Christian population growth rather than persecution. |
| 43 | What does the martyrdom of Perpetua reveal about early Christian life? | Ask AI 2 | Added Perpetua's family conflict and afterlife visions to Ask AI's single general post. |
| 44 | What are the earliest surviving manuscripts of the New Testament? | Ask AI | Its eight results consistently addressed manuscript survival and evidentiary limits; Ask AI 2 included peripheral Paul and first-century-Mark material. |
| 45 | Did scribes alter New Testament passages to support particular theological beliefs? | Ask AI 2 | Offered broader direct evidence from Orthodox Corruption, anti-Jewish alterations, bloody sweat, and other intentional changes. |
| 46 | Why does the King James Bible contain readings scholars now reject? | Ask AI 2 | Ask AI's two-term intersection yielded one post; Ask AI 2 found the larger KJV, Textus Receptus, Trinity, and omitted-passage discussion. |
| 47 | Why was the Septuagint important to early Christians? | Ask AI 2 | Returned posts about Gentile churches and Christian need for an Old Testament; Ask AI concentrated more narrowly on apocrypha and virgin-birth citations. |
| 48 | What do the Dead Sea Scrolls reveal about Jewish beliefs around the time of Jesus? | Ask AI | Its set covered the Scrolls, Essenes, Jewish sects, Jesus, and Christianity more fully; Ask AI 2 included a first-century-Mark controversy. |
| 49 | How can scholars tell whether an ancient Christian writing was forged? | Ask AI | Better emphasized methods, terminology, reader detection, authorial deception, and concrete forgery tests. |
| 50 | What is the strongest historical evidence that Jesus really existed? | Ask AI | Its five results were tightly focused on key evidence and non-Christian sources; Ask AI 2 added several tangential posts. |

## Improvements Suggested for Ask AI

1. **Broaden only when the controlled candidate pool is too small.** If AND matching produces fewer than roughly five posts, add a limited semantic title-and-summary candidate set before refinement. This would improve questions 4, 12, 35, and 46 without weakening strong single-topic searches.

2. **Treat comparisons as relationships, not simply intersections.** Questions containing words such as *differ*, *compare*, *change*, or *conflict* should search for posts discussing the relationship between the named subjects. Requiring both topic assignments excluded useful posts for the Sermon comparison and the Acts-Galatians question.

3. **Separate required concepts from supporting context.** One controlled topic can define the candidate pool while additional people, texts, or topics boost ranking. They should become hard filters only when the question clearly requires every concept to be a major subject.

4. **Use semantic supplementation for explanatory intent.** For questions asking *why*, *how*, or *when*, supplement taxonomy matches with a small number of semantic candidates whose summaries directly address the requested explanation. This helped Ask AI 2 on questions about Nazareth, apocalyptic expectation, immediate afterlife beliefs, Gnostic diversity, and the Septuagint.

5. **Preserve Ask AI's precision advantage.** Do not globally replace topic-based retrieval or always broaden the results. Ask AI won eighteen questions because the controlled topic produced a cleaner set than semantic retrieval. Hybrid expansion should activate only for low-result, relational, or low-confidence interpretations.

6. **Refine against a mixed but bounded pool.** A practical candidate pool would be the controlled matches plus perhaps the strongest 15-25 semantic additions, deduplicated and capped before AI refinement. The final refinement should continue favoring posts that directly answer the question over merely related background.

7. **Add these questions as a regression suite.** Re-run the same 50 cases after retrieval changes and compare titles, summaries, zero-result frequency, latency, and API cost. Particular sentinel cases are questions 4, 12, 24, 27, 42, and 46 because they expose both recall and precision risks.

## Artifacts

- Raw API results: `.tmp/ask_ai_comparison_50_2026-08-26.json`
- Repeatable runner: `.tmp/compare_ask_ai_50.py`
