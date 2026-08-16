"""Perform a conservative contextual pass over unresolved broad personal names."""

from __future__ import annotations

import json
import re
from pathlib import Path

from disambiguate_person_name_keywords_2026_08_16 import (
    BROAD_NAMES,
    CLASSIFIERS,
    INDEX_PATH,
    RAW_PATH,
)


ROOT = Path(__file__).resolve().parents[1]
AUDIT_PATH = ROOT / "data" / "audits" / "person_name_keyword_refinement_2026_08_16.json"

PETER_PERSON_TOPICS = {
    "Acts",
    "Apostolic Death Traditions",
    "Empty Tomb Traditions",
    "Gospel Authorship",
    "Gospel Eyewitness Claims",
    "Papias",
    "Paul and His Opponents",
    "Paul in Acts",
    "Peter the Apostle",
    "Jesus' Resurrection Appearances",
}
JAMES_PERSON_TOPICS = {
    "Galatians",
    "Jesus' Family Traditions",
    "Mythicism",
    "Paul and His Opponents",
    "Paul in Acts",
    "Paul's Knowledge of Jesus",
}
JAMES_LETTER_TOPICS = {
    "Letter of James",
    "Non-Pauline Epistle Authorship",
    "Non-Pauline Epistle Forgeries",
}
BARNABAS_PERSON_TOPICS = {
    "Acts",
    "Paul and His Opponents",
    "Paul in Acts",
}


def count_name(name: str, text: str) -> int:
    return len(re.findall(rf"\b{re.escape(name)}\b", text, flags=re.IGNORECASE))


def add_without_topic_overlap(post: dict, labels: set[str]) -> tuple[list[str], list[str]]:
    topics = set(post.get("topics", []))
    added = []
    represented = []
    for label in sorted(labels):
        if label in topics:
            represented.append(label)
        elif label not in post.get("secondaryKeywords", []):
            post["secondaryKeywords"].append(label)
            added.append(label)
    return added, represented


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

    report = {name: {"resolved": [], "remaining": []} for name in BROAD_NAMES}
    for post in posts:
        wp_id = str(post["wpId"])
        broad_names = [
            name for name in BROAD_NAMES if name in post.get("secondaryKeywords", [])
        ]
        if not broad_names:
            continue
        topics = set(post.get("topics", []))
        text = raw_text[wp_id]
        for broad_name in broad_names:
            labels = CLASSIFIERS[broad_name](topics, text)
            occurrences = count_name(broad_name, text)
            if broad_name == "Peter" and not labels:
                if occurrences >= 2 or topics & PETER_PERSON_TOPICS:
                    labels.add("Peter the Apostle")
            elif broad_name == "James" and not labels:
                if topics & JAMES_PERSON_TOPICS:
                    labels.add("James the Brother of Jesus")
                elif topics & JAMES_LETTER_TOPICS:
                    labels.add("Letter of James")
            elif broad_name == "Barnabas" and not labels:
                if topics & BARNABAS_PERSON_TOPICS:
                    labels.add("Barnabas, Associate of Paul")

            if not labels:
                report[broad_name]["remaining"].append(
                    {"wpId": wp_id, "title": post["title"], "topics": sorted(topics)}
                )
                continue

            post["secondaryKeywords"] = [
                value
                for value in post.get("secondaryKeywords", [])
                if value != broad_name
            ]
            added, represented = add_without_topic_overlap(post, labels)
            report[broad_name]["resolved"].append(
                {
                    "wpId": wp_id,
                    "title": post["title"],
                    "addedKeywords": added,
                    "representedByTopics": represented,
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
            "resolvedThisPass": len(values["resolved"]),
            "stillAmbiguous": len(values["remaining"]),
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
