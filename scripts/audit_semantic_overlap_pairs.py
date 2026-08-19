"""Resolve secondary-keyword and topic labels that look deceptively similar."""

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
    / "semantic_overlap_pairs_2026_08_19_secondary_keyword_audit.json"
)

OLD_LABEL = "Biblical Discrepancies"
CANONICAL_LABEL = "Biblical Contradictions"

PRESERVED_PAIRS = [
    {
        "keyword": "New Testament Canon",
        "topic": "Canon Formation",
        "decision": "retain_distinct",
        "reason": (
            "New Testament Canon identifies the specifically Christian collection or "
            "its boundaries, while Canon Formation covers the historical process by "
            "which writings became scripture, including the Hebrew Bible canon."
        ),
    },
    {
        "keyword": "Eyewitness Testimony",
        "topic": "Eyewitness Reliability",
        "decision": "retain_distinct",
        "reason": (
            "Eyewitness testimony can be meaningful evidence or source context without "
            "the post substantially evaluating the reliability and limits of eyewitness memory."
        ),
    },
    {
        "keyword": "Divine Beings",
        "topic": "Divine Beings in the Hebrew Bible",
        "decision": "retain_distinct",
        "reason": (
            "Divine Beings also supports posts about Greco-Roman religion, Christology, "
            "Gnosticism, and other contexts; the topic is intentionally limited to the "
            "Hebrew Bible and ancient Jewish traditions."
        ),
    },
    {
        "keyword": "Debates",
        "topic": "Public Debates",
        "decision": "retain_distinct",
        "reason": (
            "Debates includes classroom exercises and sustained scholarly disputes, "
            "whereas Public Debates is reserved for public debate events, announcements, "
            "rebuttals, and related exchanges."
        ),
    },
    {
        "keyword": "Textual Criticism",
        "topic": "Textual Criticism Methods",
        "decision": "retain_distinct",
        "reason": (
            "Textual Criticism is the broader field and can materially support posts on "
            "manuscripts, variants, and scribal change; the topic requires sustained "
            "attention to the methods used to evaluate readings and reconstruct texts."
        ),
    },
]


def pair_counts(posts: list[dict[str, object]], keyword: str, topic: str) -> dict[str, int]:
    keyword_ids = {
        str(post.get("wpId", ""))
        for post in posts
        if keyword in post.get("secondaryKeywords", [])
    }
    topic_ids = {
        str(post.get("wpId", ""))
        for post in posts
        if topic in post.get("topics", [])
    }
    overlap = keyword_ids & topic_ids
    return {
        "keywordPosts": len(keyword_ids),
        "topicPosts": len(topic_ids),
        "both": len(overlap),
        "keywordOnly": len(keyword_ids - topic_ids),
        "topicOnly": len(topic_ids - keyword_ids),
    }


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    before_counts = Counter(
        keyword for post in posts for keyword in post.get("secondaryKeywords", [])
    )
    biblical_before = pair_counts(posts, OLD_LABEL, CANONICAL_LABEL)
    changed_posts: list[dict[str, object]] = []

    for post in posts:
        keywords = list(post.get("secondaryKeywords", []))
        if OLD_LABEL not in keywords:
            continue

        has_topic = CANONICAL_LABEL in post.get("topics", [])
        revised: list[str] = []
        seen: set[str] = set()
        for keyword in keywords:
            replacement = CANONICAL_LABEL if keyword == OLD_LABEL else keyword
            replacement_key = normalize_keyword(replacement)
            if keyword == OLD_LABEL and has_topic:
                continue
            if replacement_key and replacement_key not in seen:
                revised.append(replacement)
                seen.add(replacement_key)

        post["secondaryKeywords"] = revised
        changed_posts.append(
            {
                "wpId": str(post.get("wpId", "")),
                "title": post.get("title", ""),
                "action": (
                    "removed_redundant_keyword"
                    if has_topic
                    else "renamed_supporting_keyword"
                ),
            }
        )

    old_remaining = [
        str(post.get("wpId", ""))
        for post in posts
        if OLD_LABEL in post.get("secondaryKeywords", [])
    ]
    if old_remaining:
        raise ValueError(f"{OLD_LABEL} remains on posts: {old_remaining[:10]}")

    duplicate_keywords: list[str] = []
    topic_keyword_overlaps: list[dict[str, str]] = []
    for post in posts:
        keyword_keys = [
            normalize_keyword(keyword)
            for keyword in post.get("secondaryKeywords", [])
            if normalize_keyword(keyword)
        ]
        if len(keyword_keys) != len(set(keyword_keys)):
            duplicate_keywords.append(str(post.get("wpId", "")))

        topic_keys = {
            normalize_keyword(topic)
            for topic in post.get("topics", [])
            if normalize_keyword(topic)
        }
        for keyword in post.get("secondaryKeywords", []):
            if normalize_keyword(keyword) in topic_keys:
                topic_keyword_overlaps.append(
                    {"wpId": str(post.get("wpId", "")), "keyword": keyword}
                )

    if duplicate_keywords:
        raise ValueError(f"Duplicate keywords remain: {duplicate_keywords[:10]}")
    if topic_keyword_overlaps:
        raise ValueError(
            f"Identical topic-keyword overlaps remain: {topic_keyword_overlaps[:10]}"
        )

    after_counts = Counter(
        keyword for post in posts for keyword in post.get("secondaryKeywords", [])
    )
    preserved = []
    for decision in PRESERVED_PAIRS:
        preserved.append(
            {
                **decision,
                "counts": pair_counts(posts, decision["keyword"], decision["topic"]),
            }
        )

    audit = {
        "auditDate": "2026-08-19",
        "scope": (
            "Pairwise semantic review of six secondary-keyword/topic label pairs "
            "using the completed full-text keyword and topic audits."
        ),
        "criterion": (
            "Normalize true synonyms, remove a newly identical secondary keyword when "
            "the post already has that topic, and preserve broad-versus-narrow labels "
            "when they support meaningfully different searches."
        ),
        "auditedKeywords": [
            CANONICAL_LABEL,
            "New Testament Canon",
            "Canon Formation",
            "Eyewitness Testimony",
            "Divine Beings",
            "Debates",
            "Textual Criticism",
        ],
        "normalizedPair": {
            "keyword": OLD_LABEL,
            "topic": CANONICAL_LABEL,
            "decision": "normalize_to_topic_label",
            "reason": (
                "Biblical discrepancies and biblical contradictions describe the same "
                "search concept in this collection."
            ),
            "before": biblical_before,
            "after": pair_counts(posts, CANONICAL_LABEL, CANONICAL_LABEL),
            "renamedSupportingAssignments": sum(
                item["action"] == "renamed_supporting_keyword" for item in changed_posts
            ),
            "removedRedundantAssignments": sum(
                item["action"] == "removed_redundant_keyword" for item in changed_posts
            ),
            "changedPosts": changed_posts,
        },
        "preservedPairs": preserved,
        "summary": {
            "pairsReviewed": 6,
            "pairsNormalized": 1,
            "pairsPreservedAsDistinct": 5,
            "postsChanged": len(changed_posts),
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
