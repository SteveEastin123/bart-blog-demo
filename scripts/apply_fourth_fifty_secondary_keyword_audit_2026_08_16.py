"""Apply the approved audit of the fourth 50 higher-frequency keywords."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
RETIREMENT_PATH = (
    ROOT / "data" / "index" / "ehrman_secondary_keyword_retirement_candidates.json"
)

REMOVE_ALL = {
    "Gospel Writers as Interpreters",
    "Teaching",
    "Q&A",
    "Scholarship",
}

NORMALIZE = {"Letters of Paul": "Pauline Letters"}

CONCEPT_TOPIC_EQUIVALENTS = {
    "Early Judaism": "Early Judaism (General)",
    "Gnosticism": "Gnosticism (General)",
    "Original Text": "Original Text Questions",
    "Heresy": "Early Christian Orthodoxy and Heresy",
}

GOSPEL_OF_NICODEMUS_TITLES = {
    "My Favorite Fragment of a Lost Gospel.  Is It the Gospel of Peter??",
    "How Can We Get Behind “False Memories” of Jesus to the Historical Facts?",
    "What Do the Apostles’ Deaths Prove?  Guest Post by Kyle Smith.",
    "Can We Know Anything About Judas Iscariot?",
    "Did Christ Save *Everyone* When He Descended to Hades?",
    "Heaven and Hell at the Scholarly Level (for comparison)",
    "Another Letter Written By Jesus?  Stranger and Stranger…",
    "Want To See My New Book Manuscript?  A Blog Fundraiser",
    "The Roman Standards Worship Jesus?  From the Gospel of Nicodemus",
    "A Gospel of Nicodemus?",
    "A Very Odd Saying of Jesus",
    "My Early Christian Apocrypha Seminar",
    "Jesus in Scholarship and Film",
    "Yet Other Accounts Of the Death of Judas",
    "Guided Tours of Heaven and Hell:  My Scholarly Book",
    "The Scholarly Edition of the Apocryphal Gospels",
    "The Happy News!  No One Stays In Hell!",
    "Did Jesus Write a Letter to Joseph of Arimathea",
    "Now, The Gospel of Peter",
    "Two Rather Bizarre Accounts of How Judas Died",
    "A Very Strange Saying:  From the Gospel of Peter?",
    "Another Translation Project:  The Apocryphal Gospels",
    "Other Accounts of the Death of Judas",
    "The Discovery of the Gospel of Peter",
    "Why Are the Gospels Anonymous?",
    "Apocryphal Gospels: The Scholarly Version",
    "How I Decided to Publish the Apocryphal Gospels",
    "The Gospel of Peter in a Papyrus Fragment?",
    "Gospel of the Savior",
    "The Apocryphal Gospels: Texts and Translations",
    "Personal Reflections: My Apocrypha Seminar at the National Humanities Center",
}

REMOVE_BY_KEYWORD_AND_TITLE = {
    "Q Source": {
        "Luke and John “At a Glance” and Controversial Questions",
        "Matthew and Mark “At a Glance” with Controversial Questions",
        "Paul and the Anachronistic Origins of Early Christianity – Part 1 by Dr. Robyn Faith Walsh",
        "How the Gospels Transformed the Apocalyptic Jesus",
        "After Paul Converted…  Does the Book of Acts Contradict Paul Himself?",
        "Did Jesus Appear to 500 People After His Resurrection?",
        "What Is the Didache & When Was the Didache Written",
        "You Don’t Want To Blaspheme the Spirit!  But What’s It Mean?",
        "The Preaching of Jesus in a Nutshell",
        "Gospel Evidence that Jesus Existed",
        "Mark Goodacre: Questioning the Discovery of the Nag Hammadi Library",
        "The Lost Writings of Papias",
        "My New Project on Memory",
        "Day Two of Jesus and Brian",
        "More Background on Oral Traditions",
        "John from a Redactional Perspective",
        "Paul in Acts: Part 2",
    },
    "Canon Formation": {
        "When Did We Get Chapters and Verses? A Quick Answer",
        "Hebrews and James:  “At a Glance” and “Questions for Reflection”",
        "The Letter of Jude in a Nutshell",
        "Explaining the Triumph of Christianity",
        "Jesus Interrupted:  My Most Thorough Explanation of Critical Scholarship on the New Testament",
        "What Is Sheol in the Hebrew Bible?",
        "What We Knew about the Gospel of Peter Before We Had the Gospel of Peter",
        "The Gospel Before the Gospel: The Proto-Gospel of James",
        "Good Friday or Easter?  CNN OpEd",
        "The Famous Short Stories about Daniel",
        "My Final Exam This Semester!  The Birth of Christianity.",
        "Differences Between John and the Synoptics",
        "The Afterlife in the Hebrew Bible: Sheol",
        "My Original Interest in Textual Criticism",
        "Fifty Ways to Forge a Gospel",
        "My Focus on Christology in The Orthodox Corruption of Scripture",
        "Why Was the Gospel of Mark Attributed to Mark?",
        "Constantine and Christianity",
        "Church Fathers Who Quote the New Testament",
        "Autobiographical.  Metzger and Me:  Metzger’s Faith",
    },
    "Gnosticism": {
        "A Fundamental Issue: Heresy and Orthodoxy in Early Christianity",
        "The Massive Diversity of Early Christianity. My Book: Lost Christianities",
        "The Johannine Letters in Sum",
        "Earliest Christian Diversity",
        "Was Jesus Married?",
        "Is the New Gospel Fragment a Modern Forgery?",
    },
    "Mary Magdalene": {
        "Did Heretics’ Texts Describe Their Incestuous Rituals?",
        "Judas Iscariot?  What’s an Iscariot??",
        "The Name Judas Iscariot: What Does It Mean?",
    },
}

RETIRED = set(NORMALIZE).union(REMOVE_ALL)


def normalized_unique(values: list[str]) -> list[str]:
    """Return values in original order with case-insensitive duplicates removed."""
    result: list[str] = []
    seen: set[str] = set()
    for value in values:
        key = value.casefold().strip()
        if key and key not in seen:
            seen.add(key)
            result.append(value)
    return result


def validate_targets(posts: list[dict[str, object]]) -> None:
    """Ensure every title-based change resolves to one matching assignment."""
    targets = dict(REMOVE_BY_KEYWORD_AND_TITLE)
    targets["Nicodemus"] = GOSPEL_OF_NICODEMUS_TITLES
    for keyword, titles in targets.items():
        for title in titles:
            matches = [
                post
                for post in posts
                if post.get("title") == title
                and keyword in post.get("secondaryKeywords", [])
            ]
            if len(matches) != 1:
                raise ValueError(
                    f"Expected one {keyword!r} assignment for {title!r}; "
                    f"found {len(matches)}"
                )


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    validate_targets(posts)
    removed = 0
    normalized = 0
    split = 0
    topic_duplicates = 0

    for post in posts:
        title = str(post["title"])
        topics = {str(topic) for topic in post.get("topics", [])}
        updated: list[str] = []
        for keyword in post.get("secondaryKeywords", []):
            if keyword in REMOVE_ALL or title in REMOVE_BY_KEYWORD_AND_TITLE.get(
                keyword, set()
            ):
                removed += 1
                continue
            if CONCEPT_TOPIC_EQUIVALENTS.get(keyword) in topics:
                removed += 1
                continue
            if keyword == "Nicodemus" and title in GOSPEL_OF_NICODEMUS_TITLES:
                updated.append("Gospel of Nicodemus")
                split += 1
                continue
            replacement = NORMALIZE.get(keyword, keyword)
            if replacement != keyword:
                normalized += 1
            updated.append(replacement)

        unique = normalized_unique(updated)
        topic_keys = {topic.casefold().strip() for topic in topics}
        filtered = [
            keyword
            for keyword in unique
            if keyword.casefold().strip() not in topic_keys
        ]
        topic_duplicates += len(unique) - len(filtered)
        post["secondaryKeywords"] = filtered

    retirement = json.loads(RETIREMENT_PATH.read_text(encoding="utf-8"))
    retirement["keywords"] = sorted(
        set(retirement.get("keywords", [])).union(RETIRED), key=str.casefold
    )

    INDEX_PATH.write_text(
        json.dumps(posts, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    RETIREMENT_PATH.write_text(
        json.dumps(retirement, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    print(f"Removed assignments: {removed}")
    print(f"Normalized Letters of Paul assignments: {normalized}")
    print(f"Split Nicodemus document assignments: {split}")
    print(f"Removed duplicates after normalization: {topic_duplicates}")


if __name__ == "__main__":
    main()
