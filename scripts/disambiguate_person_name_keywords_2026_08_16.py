"""Disambiguate broad personal-name secondary keywords when evidence is explicit."""

from __future__ import annotations

import json
import re
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
RAW_PATH = ROOT / "data" / "raw" / "posts.jsonl"
AUDIT_PATH = ROOT / "data" / "audits" / "person_name_keyword_disambiguation_2026_08_16.json"
BROAD_NAMES = ("Peter", "Mary", "James", "Herod", "Barnabas", "Thomas")


def matches(pattern: str, text: str) -> bool:
    return bool(re.search(pattern, text, flags=re.IGNORECASE))


def classify_peter(topics: set[str], text: str) -> set[str]:
    labels = set()
    topic_labels = {
        "Peter the Apostle",
        "Gospel of Peter",
        "Apocalypse of Peter",
        "1 Peter",
        "2 Peter",
    }
    labels.update(topics & topic_labels)
    patterns = {
        "Gospel of Peter": r"\bGospel of Peter\b",
        "Apocalypse of Peter": r"\b(?:Coptic )?Apocalypse of Peter\b",
        "1 Peter": r"\b(?:1|First) Peter\b",
        "2 Peter": r"\b(?:2|Second) Peter\b",
        "Peter the Apostle": (
            r"\b(?:apostle|disciple|Simon|Cephas) Peter\b|"
            r"\bPeter,? (?:the )?(?:apostle|disciple)\b|"
            r"\b(?:Peter and Paul|Paul and Peter|Simon Peter|Peter's denial)\b"
        ),
    }
    for label, pattern in patterns.items():
        if matches(pattern, text):
            labels.add(label)
    return labels


def classify_mary(topics: set[str], text: str) -> set[str]:
    labels = set()
    if topics & {"Mary Magdalene", "Mary Magdalene in Gnostic Traditions"}:
        labels.add("Mary Magdalene")
    if "Gospel of Mary" in topics:
        labels.add("Gospel of Mary")
    if topics & {
        "Jesus' Birth Narratives",
        "Virgin Birth",
        "Jesus' Family Traditions",
        "Proto-Gospel of James",
    } and matches(r"\bMary\b", text):
        labels.add("Mary, Mother of Jesus")
    patterns = {
        "Mary Magdalene": r"\bMary Magdalene\b|\bMagdalene\b",
        "Mary, Mother of Jesus": (
            r"\b(?:Virgin Mary|Mary,? (?:the )?mother of Jesus|Jesus['’]s mother Mary|"
            r"Mary,? (?:the )?mother of (?:the Lord|Christ))\b"
        ),
        "Mary of Bethany": (
            r"\bMary of Bethany\b|\bMary,? (?:the )?sister of (?:Martha|Lazarus)\b"
        ),
        "Gospel of Mary": r"\bGospel of Mary\b",
    }
    for label, pattern in patterns.items():
        if matches(pattern, text):
            labels.add(label)
    return labels


def classify_james(topics: set[str], text: str) -> set[str]:
    labels = set()
    topic_labels = {"James the Brother of Jesus", "Letter of James"}
    labels.update(topics & topic_labels)
    patterns = {
        "James the Brother of Jesus": (
            r"\bJames,? (?:the )?brother of (?:Jesus|the Lord)\b|"
            r"\bJames the Just\b|\bJesus['’]s brother James\b"
        ),
        "Letter of James": (
            r"\b(?:Letter|Epistle|Book) of James\b|\bJames['’]s epistle\b"
        ),
        "James, Son of Zebedee": r"\bJames,? (?:the )?son of Zebedee\b",
        "James, Son of Alphaeus": r"\bJames,? (?:the )?son of Alphaeus\b",
        "Proto-Gospel of James": r"\bProto-?Gospel of James\b",
    }
    for label, pattern in patterns.items():
        if matches(pattern, text):
            labels.add(label)
    return labels


def classify_herod(topics: set[str], text: str) -> set[str]:
    labels = set()
    patterns = {
        "Herod the Great": r"\bHerod the Great\b",
        "Herod Antipas": r"\bHerod Antipas\b|\bAntipas\b",
        "Herod Agrippa I": r"\b(?:Herod )?Agrippa I\b|\bAgrippa the First\b",
        "Herod Agrippa II": r"\b(?:Herod )?Agrippa II\b|\bAgrippa the Second\b",
    }
    for label, pattern in patterns.items():
        if matches(pattern, text):
            labels.add(label)
    if not labels and matches(r"\bHerod\b", text):
        if topics & {"Jesus' Birth Narratives", "Gospel of Matthew", "Virgin Birth"} or matches(
            r"\b(?:Bethlehem|magi|wise men|slaughter of the innocents|flight to Egypt)\b",
            text,
        ):
            labels.add("Herod the Great")
        elif topics & {"John the Baptist", "Trial of Jesus"} or matches(
            r"\b(?:Herodias|Salome|tetrarch|behead(?:ed|ing)? John|John['’]s head)\b",
            text,
        ):
            labels.add("Herod Antipas")
        elif matches(r"\bActs 12\b|\beaten by worms\b|\bkilled James\b", text):
            labels.add("Herod Agrippa I")
        elif matches(r"\bActs 2[56]\b|\b(?:Festus|Berenice)\b", text):
            labels.add("Herod Agrippa II")
    return labels


def classify_barnabas(topics: set[str], text: str) -> set[str]:
    labels = set()
    if "Letter of Barnabas" in topics or matches(
        r"\b(?:Letter|Epistle) of Barnabas\b|\bPseudo-?Barnabas\b", text
    ):
        labels.add("Letter of Barnabas")
    if matches(
        r"\b(?:Paul and Barnabas|Barnabas and Paul|apostle Barnabas|Barnabas,? "
        r"(?:the )?(?:apostle|companion|coworker|missionary))\b",
        text,
    ):
        labels.add("Barnabas, Associate of Paul")
    return labels


def classify_thomas(topics: set[str], text: str) -> set[str]:
    labels = set()
    topic_labels = {
        "Gospel of Thomas",
        "Infancy Gospel of Thomas",
        "Acts of Thomas",
    }
    labels.update(topics & topic_labels)
    patterns = {
        "Gospel of Thomas": r"\bGospel of Thomas\b",
        "Infancy Gospel of Thomas": r"\bInfancy Gospel of Thomas\b",
        "Acts of Thomas": r"\bActs of Thomas\b",
        "Thomas the Apostle": (
            r"\bThomas,? (?:the )?(?:apostle|disciple)\b|\bDoubting Thomas\b|"
            r"\bThomas called (?:Didymus|the Twin)\b"
        ),
    }
    for label, pattern in patterns.items():
        if matches(pattern, text):
            labels.add(label)
    if "Apocryphal Acts" in topics and matches(
        r"\bActs of (?:John,? )?Thomas\b|\bActs of John, Thomas, Peter", text
    ):
        labels.add("Acts of Thomas")
    if matches(r"\bGospel(?:s)? of [^.]{0,80}\bThomas\b", text):
        labels.add("Gospel of Thomas")
    return labels


CLASSIFIERS = {
    "Peter": classify_peter,
    "Mary": classify_mary,
    "James": classify_james,
    "Herod": classify_herod,
    "Barnabas": classify_barnabas,
    "Thomas": classify_thomas,
}


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    targets = {
        str(post["wpId"]): post
        for post in posts
        if set(post.get("secondaryKeywords", [])) & set(BROAD_NAMES)
    }
    raw_text = {}
    with RAW_PATH.open(encoding="utf-8") as source:
        for line in source:
            raw_post = json.loads(line)
            wp_id = str(raw_post.get("wpId", ""))
            if wp_id in targets:
                raw_text[wp_id] = raw_post.get("text", "")
    missing = sorted(set(targets) - set(raw_text))
    if missing:
        raise RuntimeError(f"Missing local full text for {len(missing)} posts")

    report = {
        name: {"before": 0, "resolved": [], "ambiguous": []} for name in BROAD_NAMES
    }
    for post in posts:
        wp_id = str(post["wpId"])
        broad_in_post = [
            name for name in BROAD_NAMES if name in post.get("secondaryKeywords", [])
        ]
        if not broad_in_post:
            continue
        topics = set(post.get("topics", []))
        text = raw_text[wp_id]
        for broad_name in broad_in_post:
            report[broad_name]["before"] += 1
            labels = CLASSIFIERS[broad_name](topics, text)
            if not labels:
                report[broad_name]["ambiguous"].append(
                    {"wpId": wp_id, "title": post["title"], "topics": sorted(topics)}
                )
                continue

            post["secondaryKeywords"] = [
                keyword
                for keyword in post.get("secondaryKeywords", [])
                if keyword != broad_name
            ]
            added = []
            represented_by_topic = []
            for label in sorted(labels):
                if label in topics:
                    represented_by_topic.append(label)
                elif label not in post["secondaryKeywords"]:
                    post["secondaryKeywords"].append(label)
                    added.append(label)
            report[broad_name]["resolved"].append(
                {
                    "wpId": wp_id,
                    "title": post["title"],
                    "addedKeywords": added,
                    "representedByTopics": represented_by_topic,
                }
            )

    duplicate_posts = [
        str(post["wpId"])
        for post in posts
        if len(post.get("secondaryKeywords", []))
        != len(set(post.get("secondaryKeywords", [])))
    ]
    exact_overlaps = [
        str(post["wpId"])
        for post in posts
        if set(post.get("topics", [])) & set(post.get("secondaryKeywords", []))
    ]
    if duplicate_posts or exact_overlaps:
        raise RuntimeError(
            f"Integrity failure: duplicates={len(duplicate_posts)}, "
            f"exact overlaps={len(exact_overlaps)}"
        )

    INDEX_PATH.write_text(
        json.dumps(posts, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    summary = {
        name: {
            "before": values["before"],
            "resolved": len(values["resolved"]),
            "stillAmbiguous": len(values["ambiguous"]),
        }
        for name, values in report.items()
    }
    AUDIT_PATH.write_text(
        json.dumps({"summary": summary, "details": report}, ensure_ascii=False, indent=2)
        + "\n",
        encoding="utf-8",
    )
    print(json.dumps(summary, indent=2))


if __name__ == "__main__":
    main()
