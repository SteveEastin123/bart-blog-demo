"""Normalize approved secondary keywords to equivalent topic labels."""

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
    / "strong_topic_keyword_normalization_2026_08_19_secondary_keyword_audit.json"
)

LABEL_CHANGES = {
    "Trade Books": "General-Audience Books",
    "Visions": "Visionary Experiences",
    "Historical Criticism": "Historical Methods (General)",
    "Women in the Church": "Women in Early Christianity",
    "Marcionism": "Marcion",
    "Patristic Evidence": "Church Fathers as Textual Evidence",
    "Torah": "Jewish Law and Torah",
    "Writing a Book": "Writing and Publishing Process",
    "Book Publishing": "Writing and Publishing Process",
}


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    before_counts = Counter(
        keyword for post in posts for keyword in post.get("secondaryKeywords", [])
    )
    renamed: Counter[tuple[str, str]] = Counter()
    removed_as_redundant: Counter[tuple[str, str]] = Counter()
    merged_with_existing: Counter[tuple[str, str]] = Counter()
    changed_post_ids: set[str] = set()

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
            pair = (keyword, replacement)

            if keyword in LABEL_CHANGES:
                changed_post_ids.add(str(post.get("wpId", "")))
                if replacement_key in topic_keys:
                    removed_as_redundant[pair] += 1
                    continue
                if replacement_key in seen:
                    merged_with_existing[pair] += 1
                    continue
                renamed[pair] += 1

            if replacement_key and replacement_key not in seen:
                revised.append(replacement)
                seen.add(replacement_key)

        post["secondaryKeywords"] = revised

    old_labels_remaining = [
        (str(post.get("wpId", "")), keyword)
        for post in posts
        for keyword in post.get("secondaryKeywords", [])
        if keyword in LABEL_CHANGES
    ]
    if old_labels_remaining:
        raise ValueError(f"Old keyword labels remain: {old_labels_remaining[:10]}")

    duplicate_keywords: list[str] = []
    topic_keyword_overlaps: list[dict[str, str]] = []
    for post in posts:
        topic_keys = {
            normalize_keyword(topic)
            for topic in post.get("topics", [])
            if normalize_keyword(topic)
        }
        keyword_keys: list[str] = []
        for keyword in post.get("secondaryKeywords", []):
            keyword_key = normalize_keyword(keyword)
            if keyword_key:
                keyword_keys.append(keyword_key)
            if keyword_key in topic_keys:
                topic_keyword_overlaps.append(
                    {"wpId": str(post.get("wpId", "")), "keyword": keyword}
                )
        if len(keyword_keys) != len(set(keyword_keys)):
            duplicate_keywords.append(str(post.get("wpId", "")))

    if duplicate_keywords:
        raise ValueError(f"Duplicate keywords remain: {duplicate_keywords[:10]}")
    if topic_keyword_overlaps:
        raise ValueError(
            f"Identical topic-keyword overlaps remain: {topic_keyword_overlaps[:10]}"
        )

    after_counts = Counter(
        keyword for post in posts for keyword in post.get("secondaryKeywords", [])
    )
    changes = []
    for old_label, new_label in LABEL_CHANGES.items():
        pair = (old_label, new_label)
        changes.append(
            {
                "from": old_label,
                "to": new_label,
                "before": before_counts[old_label],
                "renamedSupportingAssignments": renamed[pair],
                "removedBecauseTopicMatched": removed_as_redundant[pair],
                "mergedWithExistingKeyword": merged_with_existing[pair],
                "canonicalKeywordPostsAfter": after_counts[new_label],
            }
        )

    audit = {
        "auditDate": "2026-08-19",
        "scope": (
            "Normalize nine high-confidence secondary-keyword labels to equivalent "
            "topic names."
        ),
        "criterion": (
            "Use one canonical label when the keyword and topic describe the same "
            "subject. Remove the secondary keyword when the same post already has the "
            "destination topic; otherwise retain it under the canonical label."
        ),
        "auditedKeywords": sorted(set(LABEL_CHANGES.values()), key=str.casefold),
        "labelChanges": changes,
        "summary": {
            "sourceLabelsNormalized": len(LABEL_CHANGES),
            "canonicalLabels": len(set(LABEL_CHANGES.values())),
            "postsChanged": len(changed_post_ids),
            "assignmentsRenamed": sum(renamed.values()),
            "assignmentsRemovedBecauseTopicMatched": sum(
                removed_as_redundant.values()
            ),
            "assignmentsMergedWithExistingKeyword": sum(
                merged_with_existing.values()
            ),
            "uniqueSecondaryKeywordsBefore": len(before_counts),
            "uniqueSecondaryKeywordsAfter": len(after_counts),
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
