"""Align related secondary-keyword labels with their matching topic names."""

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
    / "related_topic_label_secondary_keyword_audit.json"
)

LABEL_CHANGES = {
    "Acts of the Apostles": "Acts",
    "Augustine": "Augustine of Hippo",
    "Enoch": "Enoch Traditions",
    "Job": "Book of Job",
    "Martyrdom": "Martyrdom Traditions (General)",
    "Original Text": "Original Text Questions",
    "Charity": "Charity and Altruism",
    "Textbooks": "Textbooks and Teaching Materials",
    "Jesus' Family": "Jesus' Family Traditions",
}


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
    changes = []
    for old_label, new_label in LABEL_CHANGES.items():
        key = (old_label, new_label)
        changes.append(
            {
                "from": old_label,
                "to": new_label,
                "before": before_counts[old_label],
                "renamedAssignments": renamed[key],
                "removedBecauseTopicMatched": removed_as_redundant[key],
                "after": after_counts[new_label],
            }
        )

    audit = {
        "auditDate": "2026-08-19",
        "scope": (
            "Normalize nine secondary keywords to equivalent topic names while "
            "leaving the ambiguous Jude keyword unchanged."
        ),
        "criterion": (
            "Align labels only when the keyword and topic describe the same person, "
            "text, tradition, or subject. Remove the secondary keyword when the same "
            "post already has the matching topic."
        ),
        "auditedKeywords": list(LABEL_CHANGES.values()),
        "labelChanges": changes,
        "unchanged": [
            {
                "keyword": "Jude",
                "topic": "Letter of Jude",
                "reason": (
                    "Jude can refer to the letter, Jesus' brother, or related Judas "
                    "traditions and is not equivalent to the Letter of Jude topic."
                ),
            }
        ],
        "summary": {
            "labelsNormalized": len(LABEL_CHANGES),
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
