# Ask AI and Hybrid Ask AI 2: 50-Question Evaluation

Date: August 27, 2026

## Method

The same 50 questions used in the August 26 benchmark were submitted to the local WordPress REST pipelines. Ask AI interpreted each question as controlled topics or keywords, searched those assignments, and refined the matching posts. Hybrid Ask AI 2 combined semantic title-and-summary retrieval with lexical matching and topic/keyword metadata boosts, then applied AI refinement.

Each result set was reviewed against the question using relevance, coverage, precision, and ranking. A larger result set was not automatically judged better. Both pipelines completed successfully for all 50 questions, and all 50 Ask AI 2 candidate sets completed the refinement step.

## Overall Results

| Outcome | Questions |
|---|---:|
| Hybrid Ask AI 2 better | 27 |
| Ask AI better | 18 |
| Essentially tied | 5 |

| Runtime measure | Ask AI | Hybrid Ask AI 2 |
|---|---:|---:|
| Average response time | 2.78 seconds | 3.74 seconds |
| Median response time | 2.59 seconds | 3.91 seconds |
| Average displayed posts | 8.0 | 11.2 |
| Zero-result questions | 1 | 0 |
| Endpoint failures | 0 | 0 |

The response-time comparison is directional rather than a controlled load test because repeated questions can benefit differently from application and OpenAI caching.

## Question-by-Question Assessment

| # | Question | Better result | Reason |
|---:|---|---|---|
| 1 | Why do scholars think the Gospels were written anonymously? | Ask AI 2 | Returned a compact set centered on anonymity, original titles, and traditional authorship without losing the strongest posts. |
| 2 | Was the original ending of Mark lost or added later? | Ask AI 2 | Preserved the three direct results and added useful treatments of the additional endings and their presentation in modern Bibles. |
| 3 | How do Matthew and Luke tell different stories about Jesus' birth? | Ask AI 2 | Produced a much fuller but still focused set of direct Matthew-Luke comparisons, Bethlehem-Nazareth conflicts, and infancy narratives. |
| 4 | How does Luke's Sermon on the Plain differ from Matthew's Sermon on the Mount? | Ask AI 2 | Ask AI's strict intersection returned zero; Ask AI 2 found one directly relevant comparison, although coverage remains thin. |
| 5 | What does the parable of the rich man and Lazarus teach about the afterlife? | Ask AI 2 | Kept both exact Ask AI matches and added three directly useful posts about wealth, reversal, and the afterlife. |
| 6 | What historical evidence is there for Jesus' empty tomb? | Ask AI 2 | Covered burial practice, Gospel evidence, women at the tomb, Paul, visions, and the historical limitations of the narratives. |
| 7 | Why do Matthew and Acts describe Judas' death differently? | Ask AI | Its five posts stayed on the conflicting death accounts; Ask AI 2 added broader betrayal material. |
| 8 | Would Romans normally have allowed a crucified person like Jesus to be buried? | Ask AI 2 | The hybrid retrieval removed the earlier drift and returned a strong Roman-practice and Jesus-burial sequence. |
| 9 | Why is the woman caught in adultery missing from the earliest manuscripts of John? | Ask AI 2 | Added relevant scribal-change and translator-treatment posts while retaining the direct textual-variant results. |
| 10 | Did Luke originally say that Jesus sweated blood? | Tie | Both sets were extensive and consistently focused on the bloody-sweat variant and its manuscript evidence. |
| 11 | How does Paul's account of his conversion differ from the story in Acts? | Ask AI 2 | Expanded two exact matches with useful posts on Paul's portrayal and the historical reliability of Acts. |
| 12 | Does Acts accurately describe Paul's conflict over Jewish law in Galatians? | Ask AI 2 | Ask AI returned one post; Ask AI 2 supplied the wider Acts-Paul-Jerusalem conflict needed to address the question. |
| 13 | Why do scholars question whether Paul wrote Colossians? | Tie | Both returned six tightly focused authorship posts with only minor differences in supporting material. |
| 14 | Was 2 Thessalonians forged in Paul's name? | Ask AI 2 | Returned the fullest focused set on style, theology, dependence on 1 Thessalonians, and forgery arguments. |
| 15 | What did Paul teach about women speaking and leading in church? | Ask AI | Its smaller set stayed more precisely on Paul's views, women apostles, and the silencing interpolation. |
| 16 | What kind of resurrection body did Paul expect believers to have? | Ask AI | Its seven results directly addressed Paul's spiritual or bodily resurrection, while Ask AI 2 added general afterlife material. |
| 17 | Do James and Paul disagree about faith and works? | Ask AI | Better maintained the James-Paul relationship; Ask AI 2 introduced broader James and Jerusalem-conflict posts. |
| 18 | What does the Christ poem in Philippians say about Jesus before his birth? | Ask AI 2 | Concentrated more heavily on the poem itself, preexistence, incarnation, and angelic interpretations. |
| 19 | Were Peter and Cephas the same person? | Ask AI 2 | Returned the complete focused sequence of arguments for and against identifying the two names. |
| 20 | What role did John the Baptist play in Jesus' ministry? | Ask AI | Included more directly John-related material; Ask AI 2 leaned too heavily on general apocalyptic-Jesus posts. |
| 21 | Was Mary Magdalene really a prostitute? | Tie | Both returned the same four focused posts in nearly the same order. |
| 22 | What do our sources say about Jesus' brothers and sisters? | Ask AI | Its results stayed more consistently on Jesus' family, James, Mark, the Proto-Gospel of James, and mythicist disputes. |
| 23 | Was Jesus actually raised in Nazareth? | Ask AI | Returned five direct Nazareth and Bethlehem-Nazareth posts; Ask AI 2 broadened too far into general birth narratives. |
| 24 | Did Jesus expect the world to end during his generation? | Ask AI 2 | Ranked direct lifetime and false-prophet questions first and supplied strong apocalyptic context. |
| 25 | How can historians evaluate miracle stories about Jesus? | Ask AI | Its historical-method results were more precise; Ask AI 2 still drifted into memory and oral-tradition background. |
| 26 | What did Jesus mean by the Kingdom of God? | Ask AI | Its five posts directly explained the meaning and centrality of the Kingdom without unnecessary contextual expansion. |
| 27 | Did Jesus require his followers to give away their wealth? | Ask AI | Stayed closer to Jesus' voluntary-poverty and wealth teachings; Ask AI 2 added general charity and Pauline comparisons. |
| 28 | What does the Bible say about same-sex relationships? | Ask AI | Both were strong, but Ask AI avoided an unrelated post about alleged heretical sex rituals. |
| 29 | Why does an all-powerful and loving God allow innocent people to suffer? | Ask AI 2 | Returned a cleaner explanatory set including free-will responses, innocent suffering, and challenges to standard answers. |
| 30 | When did Christians begin believing that souls go immediately to heaven or hell? | Ask AI | Provided broader direct historical coverage of souls, immediate afterlife, and the development of heaven and hell. |
| 31 | What is the main message of the Book of Revelation? | Ask AI | Stayed centered on Revelation itself; Ask AI 2 still included generic apocalypse and apocalypticism posts. |
| 32 | How did Christians decide which books belonged in the New Testament? | Tie | Both returned excellent, comprehensive canon-formation sequences with only small differences in peripheral material. |
| 33 | Could the Gospel of Thomas preserve authentic sayings of Jesus? | Ask AI 2 | Added relevant posts about testing historicity and oral transmission to the direct Thomas-Q results. |
| 34 | Why does the Gospel of Judas portray Judas as Jesus' favored disciple? | Ask AI 2 | Added Sethian and Gnostic interpretive context while retaining the strongest Gospel of Judas posts. |
| 35 | What does the Gospel of Mary say about Mary Magdalene and the apostles? | Tie | Both returned the same single directly relevant post; recall remains limited. |
| 36 | What childhood miracles does the Infancy Gospel of Thomas attribute to Jesus? | Ask AI 2 | Added two focused interpretations of the miraculous and mischievous child Jesus to the four core posts. |
| 37 | How did the Proto-Gospel of James influence beliefs about Mary and Jesus' birth? | Ask AI | Its ten posts remained more focused; Ask AI 2 expanded into many general canonical birth-narrative posts. |
| 38 | Were Gnostic Christians a single unified movement? | Ask AI 2 | Better combined general definitions with distinct Sethian, Thomasine, and broader early-Christian groups. |
| 39 | Why did Marcion reject the Old Testament and edit Luke and Paul's letters? | Ask AI | Supplied a fuller and more consistently Marcion-centered treatment of theology, Scripture, and Paul. |
| 40 | How did Constantine affect the development of Christianity? | Ask AI 2 | Added the expansion of Christianity and decline of paganism to the direct Constantine and conversion posts. |
| 41 | What was the Arian controversy about, and how did it shape the Trinity? | Ask AI 2 | Connected Arius and Nicaea to the subsequent development and formulation of the Trinity. |
| 42 | How widespread was Roman persecution of Christians before Constantine? | Ask AI | Its three posts directly traced persecution; Ask AI 2 added Christian population and Constantine material. |
| 43 | What does the martyrdom of Perpetua reveal about early Christian life? | Ask AI 2 | Added Perpetua's family conflict and visions to the single general martyrdom post. |
| 44 | What are the earliest surviving manuscripts of the New Testament? | Ask AI 2 | The hybrid set was now consistently manuscript-focused and added accuracy, textual transmission, and evidentiary limits. |
| 45 | Did scribes alter New Testament passages to support particular theological beliefs? | Ask AI 2 | Supplied a broad but direct collection of orthodox, anti-Jewish, atonement, Christological, and other intentional changes. |
| 46 | Why does the King James Bible contain readings scholars now reject? | Ask AI 2 | Expanded one exact match into a strong KJV, Textus Receptus, omitted-passage, and later-reading sequence. |
| 47 | Why was the Septuagint important to early Christians? | Ask AI 2 | Better addressed use of the Greek Jewish Bible in Gentile churches and why Christians needed Israel's Scriptures. |
| 48 | What do the Dead Sea Scrolls reveal about Jewish beliefs around the time of Jesus? | Ask AI | Its seven results stayed directly on the Scrolls, Essenes, Judaism, and Jesus; Ask AI 2 drifted into general apocalypticism. |
| 49 | How can scholars tell whether an ancient Christian writing was forged? | Ask AI | Better covered detection methods, terminology, authorial deception, style, and concrete tests. |
| 50 | What is the strongest historical evidence that Jesus really existed? | Ask AI 2 | The hybrid retrieval produced a much stronger evidence set spanning Paul, James, Peter, independent sources, and non-Christian testimony. |

## Comparison With the August 26 Baseline

| Outcome | Semantic-only Ask AI 2 | Hybrid Ask AI 2 |
|---|---:|---:|
| Ask AI 2 better | 25 | 27 |
| Ask AI better | 18 | 18 |
| Tied | 7 | 5 |

The overall change is modest, but several important weaknesses improved materially:

- **Roman burial (question 8):** hybrid retrieval replaced tangential results with direct Roman-practice and Jesus-burial posts.
- **Earliest manuscripts (question 44):** the result set became consistently manuscript-focused instead of including peripheral material.
- **Constantine (question 40):** the hybrid set connected Constantine to Christian expansion and pagan decline, better addressing historical effect.
- **Evidence for Jesus (question 50):** hybrid retrieval added Paul's contacts, independent sources, and multiple attestation while preserving the strongest direct post.

Remaining weaknesses are also clear:

- **Over-broad expansion:** Nazareth (23), Proto-Gospel of James (37), and Dead Sea Scrolls (48) gained too much adjacent material.
- **Method drift:** miracle evaluation (25) still expands from historical method into memory and oral tradition.
- **Vocabulary drift:** Revelation's main message (31) still attracts general apocalypse-genre posts.
- **Thin corpus cases:** Sermon on the Plain versus Mount (4) and Gospel of Mary (35) remain limited even when retrieval works correctly.

## Recommendation

Keep the hybrid Ask AI 2 retrieval. It is the stronger general-purpose interface in this benchmark and fixed several of the semantic-only system's clearest failures. The next improvement should be a conservative precision pass: reduce metadata boosts when the lexical or semantic evidence is weak, and penalize candidates that match only broad neighboring concepts. Questions 23, 25, 31, 37, and 48 should serve as precision regression cases; questions 4, 8, 12, 44, 46, and 50 should serve as recall regression cases.

## Artifacts

- Fresh raw API results: `.tmp/ask_ai_comparison_50_hybrid_2026-08-27.json`
- Previous raw API results: `.tmp/ask_ai_comparison_50_2026-08-26.json`
- Repeatable runner: `.tmp/compare_ask_ai_50.py`
