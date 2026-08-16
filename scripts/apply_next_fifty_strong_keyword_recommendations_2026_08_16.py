"""Apply approved high-confidence naming changes from the next-50 audit."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = (
    ROOT
    / "data"
    / "audits"
    / "next_fifty_strong_keyword_recommendations_2026_08_16.json"
)

NORMALIZATIONS = {
    "Acts": "Acts of the Apostles",
    "Hebrew Bible": "Hebrew Bible / Old Testament",
    "Old Testament": "Hebrew Bible / Old Testament",
    "Romans": "Letter to the Romans",
    "Revelation": "Book of Revelation",
}

EQUIVALENT_TOPICS = {
    "Acts of the Apostles": {"Acts"},
    "Letter to the Romans": {"Romans"},
    "Book of Revelation": {"Book of Revelation"},
}


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    normalized_records = {label: [] for label in set(NORMALIZATIONS.values())}
    topic_overlap_records = {label: [] for label in EQUIVALENT_TOPICS}

    for post in posts:
        revised = []
        for keyword in post.get("secondaryKeywords", []):
            replacement = NORMALIZATIONS.get(keyword, keyword)
            if replacement != keyword:
                normalized_records[replacement].append(
                    {
                        "wpId": str(post["wpId"]),
                        "title": post["title"],
                        "from": keyword,
                    }
                )
            if replacement not in revised:
                revised.append(replacement)
        post["secondaryKeywords"] = revised

        topics = set(post.get("topics", []))
        for keyword, equivalent_topics in EQUIVALENT_TOPICS.items():
            if keyword in post["secondaryKeywords"] and topics & equivalent_topics:
                post["secondaryKeywords"] = [
                    value for value in post["secondaryKeywords"] if value != keyword
                ]
                topic_overlap_records[keyword].append(
                    {"wpId": str(post["wpId"]), "title": post["title"]}
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
    report = {
        "summary": {
            "normalized": {
                label: len(records) for label, records in normalized_records.items()
            },
            "removedEquivalentTopicOverlaps": {
                label: len(records) for label, records in topic_overlap_records.items()
            },
        },
        "normalizedPosts": normalized_records,
        "removedEquivalentTopicOverlapPosts": topic_overlap_records,
    }
    AUDIT_PATH.write_text(
        json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    print(json.dumps(report["summary"], indent=2))


if __name__ == "__main__":
    main()
