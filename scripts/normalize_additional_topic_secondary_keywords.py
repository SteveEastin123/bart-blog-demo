"""Normalize additional secondary keywords to equivalent topic names."""

from __future__ import annotations

import json
from collections import Counter
from pathlib import Path

from ehrman_demo_data import normalize_keyword


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = (
    ROOT
    / "data"
    / "audits"
    / "additional_topic_label_secondary_keyword_audit.json"
)

BROAD_SEARCH_CHANGES = {
    "Jewish Law": "Jewish Law and Torah",
    "Isaiah": "Book of Isaiah",
    "Non-Canonical Gospels": "Non-Canonical Gospel Traditions",
    "Dead Sea Scrolls": "Dead Sea Scrolls and Essenes",
    "Trinity": "Development of the Trinity",
    "Media Coverage": "Media Coverage and Reviews",
    "Miracle Claims": "Miracle Claims and Apologetics",
    "Predestination": "Free Will and Predestination",
    "Gospel of Jesus' Wife": "Gospel of Jesus' Wife Fragment",
    "Athanasius": "Athanasius of Alexandria",
}

CLEANUP_ONLY_CHANGES = {
    "Problem of Evil": "Problem of Evil and Suffering",
    "Bible and Same-Sex Relations": "Biblical Debates on Same-Sex Relations",
    "Arius": "Arius and Arianism",
}

LABEL_CHANGES = {**BROAD_SEARCH_CHANGES, **CLEANUP_ONLY_CHANGES}


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    before_counts = Counter(
        keyword
        for post in posts
        for keyword in post.get("secondaryKeywords", [])
    )
    renamed = Counter()
    removed_as_redundant = Counter()
    changed_posts: set[str] = set()

    for post in posts:
        topic_keys = {
            normalize_keyword(topic)
            for topic in post.get("topics", [])
            if normalize_keyword(topic)
        }
        revised: list[str] = []
        seen: set[str] = set()

        for keyword in post.get("secondaryKeywords", []):
            replacement = LABEL_CHANGES.get(keyword, keyword)
            replacement_key = normalize_keyword(replacement)

            if keyword in LABEL_CHANGES:
                changed_posts.add(str(post.get("wpId", "")))
                if replacement_key in topic_keys:
                    removed_as_redundant[(keyword, replacement)] += 1
                    continue
                renamed[(keyword, replacement)] += 1

            if not replacement_key or replacement_key in seen:
                continue
            seen.add(replacement_key)
            revised.append(replacement)

        post["secondaryKeywords"] = revised

    old_labels_remaining = [
        (post.get("wpId"), keyword)
        for post in posts
        for keyword in post.get("secondaryKeywords", [])
        if keyword in LABEL_CHANGES
    ]
    if old_labels_remaining:
        raise ValueError(f"Old keyword labels remain: {old_labels_remaining[:10]}")

    overlaps = []
    duplicate_keywords = []
    for post in posts:
        topic_keys = {
            normalize_keyword(topic)
            for topic in post.get("topics", [])
            if normalize_keyword(topic)
        }
        keyword_keys = [
            normalize_keyword(keyword)
            for keyword in post.get("secondaryKeywords", [])
            if normalize_keyword(keyword)
        ]
        if len(keyword_keys) != len(set(keyword_keys)):
            duplicate_keywords.append(post.get("wpId"))
        if topic_keys.intersection(keyword_keys):
            overlaps.append(post.get("wpId"))
    if duplicate_keywords:
        raise ValueError(f"Duplicate keywords remain: {duplicate_keywords[:10]}")
    if overlaps:
        raise ValueError(f"Topic-keyword overlaps remain: {overlaps[:10]}")

    after_counts = Counter(
        keyword
        for post in posts
        for keyword in post.get("secondaryKeywords", [])
    )

    def change_records(changes: dict[str, str]) -> list[dict[str, object]]:
        records = []
        for old_label, new_label in changes.items():
            key = (old_label, new_label)
            records.append(
                {
                    "from": old_label,
                    "to": new_label,
                    "before": before_counts[old_label],
                    "renamedAssignments": renamed[key],
                    "removedBecauseTopicMatched": removed_as_redundant[key],
                    "after": after_counts[new_label],
                }
            )
        return records

    audit = {
        "auditDate": "2026-08-19",
        "scope": (
            "Normalize ten secondary keywords for broader topic-plus-keyword search "
            "and clean up three labels fully duplicated by topic assignments."
        ),
        "criterion": (
            "Align labels only when the topic name preserves the keyword's meaning "
            "and remains discoverable from the original wording. Remove the "
            "secondary keyword when the same post already has the matching topic."
        ),
        "auditedKeywords": list(LABEL_CHANGES.values()),
        "broaderSearchChanges": change_records(BROAD_SEARCH_CHANGES),
        "cleanupOnlyChanges": change_records(CLEANUP_ONLY_CHANGES),
        "preservedAlternativeTerms": [
            "Biblical Discrepancies",
            "Pericope Adulterae",
            "New Testament Canon",
            "Eyewitness Testimony",
            "Divine Beings",
            "Debates",
            "Textual Criticism",
        ],
        "summary": {
            "labelsNormalizedForBroaderSearch": len(BROAD_SEARCH_CHANGES),
            "labelsCleanedUpAsRedundant": len(CLEANUP_ONLY_CHANGES),
            "postsChanged": len(changed_posts),
            "assignmentsRenamed": sum(renamed.values()),
            "assignmentsRemovedBecauseTopicMatched": sum(
                removed_as_redundant.values()
            ),
            "uniqueKeywordsBefore": len(before_counts),
            "uniqueKeywordsAfter": len(after_counts),
        },
    }

    INDEX_PATH.write_text(
        json.dumps(posts, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
        newline="\n",
    )
    AUDIT_PATH.write_text(
        json.dumps(audit, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
        newline="\n",
    )
    print(json.dumps(audit, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
