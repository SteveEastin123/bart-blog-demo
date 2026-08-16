"""Apply the approved audit of the third 50 higher-frequency keywords."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
RETIREMENT_PATH = (
    ROOT / "data" / "index" / "ehrman_secondary_keyword_retirement_candidates.json"
)

REMOVE_ALL = {"Early Church History", "Early Christian Communities"}

NORMALIZE = {
    "Birth Narratives": "Jesus' Birth Narratives",
    "Original New Testament": "Original Text",
}

REMOVE_BY_KEYWORD_AND_TITLE = {
    "Gospel of Matthew": {
        "Anniversary Post #2: Why Were the Gospels Written Anonymously?",
        "Readers’ Mailbag on Revelation:  November 6, 2015",
        "Ancient Forerunners of Modern Gospel Critics",
    },
    "Leviticus": {
        "What Is Sheol in the Hebrew Bible?",
        "Did King David Actually Exist?",
        "Why God Had to Destroy the Outsiders…",
        "Two (Contradictory?) Accounts of Creation in Genesis?",
        "Who Wrote the Pentateuch Anyway?",
        "Did The Israelites Really Conquer Canaan?",
        "The Historical Significance of Contradictions",
        "The Afterlife in the Hebrew Bible: Sheol",
        "Literary Tensions in the Creation Account of Genesis",
        "Who Wrote the Pentateuch?  Early Questions of Authorship.",
    },
    "2 Thessalonians": {
        "Why Does the Author of 1 Peter Sound Like Paul Instead of Peter?",
        "Who Wrote the Pastoral Epistles?  When?  And Why?",
        "The Pastoral Epistle of 1 Timothy in a Nutshell",
        "Paul’s Letter to the Romans in a Nutshell",
        "What Did Paul’s Christian Enemies Write About Him?",
        "What Is Paul’s First Surviving Letter All About?  1 Thessalonians",
        "Lost Christian Writings I’d Love to Get My Hands On!",
        "My Conference on Pseudepigraphy",
        "The Letters of Paul: Mailbag April 1, 2016",
        "Readers’ Mailbag on Revelation:  November 6, 2015",
        "Paul’s Letter to the Thessalonians",
        "Back To the Discovery of Lost Early Christian Writings",
        "Lost Letters of Paul’s Opponents",
        "Lost Christian Writings: The Letters of Paul",
        "Why Would Christian Authors Write Forgeries?",
        "New Boxes Related to Literary Forgery and the NT",
    },
    "Archaeology and Material Evidence": {
        "The Reliability of Eyewitnesses and Abraham Lincoln’s Watch",
        "What I’m Reading These Days.  You?",
        "Were Cut and Paste Jobs Common in Antiquity?  Guest Post by Brent Nongbri",
        "Paul’s Exalted Self-Image: The Fulfillment of Ancient Prophecy",
        "Suggestions for Further Reading on the Pentateuch",
        "On the Accuracy of Oral Traditions",
        "Talks at the Smithsonian, March 21",
        "My SBL Conference",
        "More in Jerusalem",
        "The SBL Meeting",
        "Possibly of Some Interest",
    },
    "Gospel of Judas": {"My UNC Seminar Tomorrow"},
    "Jesus' Teachings": {
        "Does Papias Say Matthew and Mark Wrote OUR Matthew and Mark?",
        "Do the Synoptics Present an Early Character of the Jesus Movement?\u00a0\u00a0–Platinum Post by Ryan Fleming",
        "Annual Appeal 2024: Behind the Mission Pt. 1",
        "Did Jesus Think He Was Going to Atone for the Sins of the World? A Platinum Post by Manuel Fiadeiro",
        "Why Do Historians Treat Jesus Differently from Every Other Historical Figure?",
        "Did the Romans Stage Jesus’ Crucifixion?  Platinum Guest Post by Ryan Fleming",
        "Did Jesus Collaborate with the Romans to Produce His Movement?  Platinum Guest Post by Ryan Fleming",
        "Did the Romans Help Create the Jesus’ Movement?  Platinum Guest Post by Ryan Fleming",
        "A Book That Nearly Became Scripture: The Apocalypse of Peter",
        "Was Paul the Founder of Christianity?  Or Was it Mary, Peter…or Jesus?",
        "Revelation and Ancient Views of Dominance",
        "Why I Am Not A Christian: Is Bart Ehrman a Christian?",
        "What Kind of Book Was Papias Writing?  Guest Post by Stephen Carlson",
        "Today You Will Be With Me in Paradise?",
        "How Did Judas Iscariot Die?  Readers’ Mailbag June 18, 2017",
        "The Apocalyptic Background to Jesus’ Messiahship",
        "Does a Person Need the Holy Spirit to Interpret the Bible?  Is John’s Gospel Accurate?  Readers Mailbag August 7, 2016",
        "The Baptism of Jesus as an Apocalyptic Event",
        "Papias on Matthew and Mark",
        "New Boxes on Problematic Social Values in the New Testament",
        "Followup on the NT Quiz",
    },
    "New Testament Canon": {"An Interesting Scribal Change at the Beginning of Mark"},
    "Conversion": {"My Original Passion for the Bible"},
    "Gospel of Luke": {
        "Anniversary Post #2: Why Were the Gospels Written Anonymously?",
        "Ancient Forerunners of Modern Gospel Critics",
    },
    "Jairus": {
        "What About People Who Come Back From the Dead in the Hebrew Bible?",
        "Those Darn Demons!  Guest Post by Douglas Wadeson",
        "Jesus the Healer: Those Darn Demons.     Platinum guest post by Douglas Wadeson MD",
        "The Roman Standards Worship Jesus?  From the Gospel of Nicodemus",
        "Returning from the Dead in the Hebrew Bible",
    },
    "Judas Iscariot": {
        "Book of Jude:  Who Wrote it?  When? And Why? (part 1)",
        "Jews and Gentiles in Paul’s Churches",
        "Non-Disclosure Agreements",
        "The Ending of Mark in the King James Bible",
        "Publishing with HarperOne",
        "On Falsification and Forgery",
        "Papias on Matthew and Mark",
    },
    "Elijah": {
        "The Book of James in a Nutshell",
        "How Theologians and Historians Approach the Same Bible Differently.  Guest Post by Daniel Kohanski",
        "The Plausibility of the Fourth Gospel: The Chronology of Jesus’s Ministry.  Platinum Guest Post by Dennis Folds",
        "Are the Gospels Too Early To Have Legends About Jesus?  Platinum Guest Post by Bob Seidensticker",
        "Finally: Cephas and Peter.  What Do I Really Think?",
        "Did Paul Think Jesus Was a New Adam, Not a Divine Being?",
        "What Is Repentance in the Bible?  Is there Repentance in the Bible?",
        "Readers’ Mailbag:  December 27, 2015",
    },
    "Wealth": {
        "How Can We Be Happy?  An Age-Old Question.",
        "Does Paul Condemn Slavery?  The Surprising Answer–Paul and Philemon",
        "Does Paul Condemn Slavery?   The Case of Philemon and Onesimus.",
        "Why I Find the Story of Job is Disturbing",
        "Do Textual Variants Actually *Matter* For Much??",
        "Do Textual Variants Really Matter for Anything?",
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
    """Ensure every title-based removal resolves to one matching assignment."""
    for keyword, titles in REMOVE_BY_KEYWORD_AND_TITLE.items():
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
    topic_duplicates = 0

    for post in posts:
        title = str(post["title"])
        updated: list[str] = []
        for keyword in post.get("secondaryKeywords", []):
            if keyword in REMOVE_ALL or title in REMOVE_BY_KEYWORD_AND_TITLE.get(
                keyword, set()
            ):
                removed += 1
                continue
            replacement = NORMALIZE.get(keyword, keyword)
            if replacement != keyword:
                normalized += 1
            updated.append(replacement)

        unique = normalized_unique(updated)
        topic_keys = {str(topic).casefold().strip() for topic in post.get("topics", [])}
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
    print(f"Normalized assignments: {normalized}")
    print(f"Removed topic duplicates after normalization: {topic_duplicates}")


if __name__ == "__main__":
    main()
