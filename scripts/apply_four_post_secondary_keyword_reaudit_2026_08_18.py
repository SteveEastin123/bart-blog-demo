#!/usr/bin/env python3
"""Apply the approved full-text re-audit of current four-post keywords."""

from __future__ import annotations

import json
from collections import Counter
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = (
    ROOT
    / "data"
    / "audits"
    / "four_post_secondary_keyword_reaudit_2026_08_18_secondary_keyword_audit.json"
)

EXPECTED_FOUR_POST_KEYWORDS = 45
EXPECTED_UNIQUE_KEYWORDS_BEFORE = 920
EXPECTED_UNIQUE_KEYWORDS_AFTER = 919

SOURCE_ASSIGNMENTS = {
    "Academic Journals": {"33126", "2205", "2170", "2120"},
    "Carbon Dating": {"39086", "39128", "8999", "8978"},
    "Demons": {"27838", "27082", "26865", "25333"},
    "Exorcism": {"27838", "27082", "26865", "25333"},
    "James, Son of Alphaeus": {"48814", "35342", "31948", "16338"},
    "Pacifism": {"50329", "50273", "32589", "11793"},
    "Philo": {"50232", "50201", "49520", "13238"},
    "Resurrection Accounts": {"12478", "4027", "4018", "3079"},
    "Susanna": {"50046", "48005", "17579", "6753"},
}

REMOVALS = {
    "Academic Journals": {
        "2205": (
            "The post concerns editorial work for scholarly book series; academic "
            "journals appear only in the opening comparison."
        ),
    },
    "Carbon Dating": {
        "39086": (
            "This version discusses oral memorization and written manuscript "
            "evidence rather than scientific dating."
        ),
        "8999": (
            "This version discusses oral memorization and written manuscript "
            "evidence rather than scientific dating."
        ),
    },
    "James, Son of Alphaeus": {
        "48814": (
            "James son of Alphaeus appears only as an example showing that several "
            "New Testament figures shared the name James."
        ),
        "16338": (
            "James son of Alphaeus is mentioned only to distinguish the possible "
            "author of the letter from other people named James."
        ),
    },
    "Philo": {
        "50201": (
            "Philo appears in a single citation among several Jewish precedents and "
            "is not otherwise discussed."
        ),
    },
    "Susanna": {
        "48005": (
            "Susanna appears only in a quoted list of women from Luke 8."
        ),
        "17579": (
            "Susanna appears only in a brief summary of the women named in Luke 8."
        ),
        "6753": (
            "Susanna appears only in a brief summary of the women named in Luke 8."
        ),
    },
}

TRANSFORMATIONS = [
    {
        "from": ["Carbon Dating"],
        "to": "Radiocarbon Dating",
        "wpIds": {"39128", "8978"},
        "reason": (
            "Radiocarbon Dating is the more precise name for the scientific dating "
            "issue discussed in these two versions of the post."
        ),
    },
    {
        "from": ["Demons", "Exorcism"],
        "to": "Demons and Exorcism",
        "wpIds": {"27838", "27082", "26865", "25333"},
        "reason": (
            "The two labels describe one connected subject and currently return the "
            "same four posts."
        ),
    },
    {
        "from": ["Pacifism"],
        "to": "Jesus and Pacifism",
        "wpIds": {"50329", "50273", "32589", "11793"},
        "reason": "The more precise label identifies the shared subject of all four posts.",
    },
    {
        "from": ["Resurrection Accounts"],
        "to": "Gospel Resurrection Narratives",
        "wpIds": {"12478", "4027", "4018", "3079"},
        "reason": (
            "All four posts specifically examine resurrection narratives in the "
            "canonical Gospels."
        ),
    },
]


def load_json(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value) -> None:
    path.write_text(
        json.dumps(value, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
        newline="\n",
    )


def keyword_post_ids(posts: list[dict], keyword: str) -> set[str]:
    return {
        str(post["wpId"])
        for post in posts
        if keyword in post.get("secondaryKeywords", [])
    }


def remove_keyword(post: dict, keyword: str) -> None:
    post["secondaryKeywords"] = [
        value for value in post.get("secondaryKeywords", []) if value != keyword
    ]


def add_keyword(post: dict, keyword: str) -> None:
    if keyword not in post.get("secondaryKeywords", []):
        post.setdefault("secondaryKeywords", []).append(keyword)


def main() -> int:
    posts = load_json(POSTS_PATH)
    by_id = {str(post["wpId"]): post for post in posts}
    counts_before = Counter(
        keyword
        for post in posts
        for keyword in post.get("secondaryKeywords", [])
    )
    original_keywords = sorted(
        (keyword for keyword, count in counts_before.items() if count == 4),
        key=str.casefold,
    )
    if len(original_keywords) != EXPECTED_FOUR_POST_KEYWORDS:
        raise ValueError(
            f"Expected {EXPECTED_FOUR_POST_KEYWORDS} four-post keywords; "
            f"found {len(original_keywords)}"
        )
    if len(counts_before) != EXPECTED_UNIQUE_KEYWORDS_BEFORE:
        raise ValueError(
            f"Expected {EXPECTED_UNIQUE_KEYWORDS_BEFORE} unique keywords; "
            f"found {len(counts_before)}"
        )

    for keyword, expected_ids in SOURCE_ASSIGNMENTS.items():
        actual_ids = keyword_post_ids(posts, keyword)
        if actual_ids != expected_ids:
            raise ValueError(
                f"Unexpected assignments for {keyword!r}: {sorted(actual_ids)}"
            )

    replacement_labels = {row["to"] for row in TRANSFORMATIONS}
    existing_replacements = {
        label: counts_before[label]
        for label in replacement_labels
        if counts_before[label]
    }
    if existing_replacements:
        raise ValueError(
            f"Replacement labels already exist: {existing_replacements}"
        )

    removed_posts = []
    for keyword, removals in REMOVALS.items():
        for wp_id, reason in removals.items():
            post = by_id[wp_id]
            if keyword not in post.get("secondaryKeywords", []):
                raise ValueError(f"Missing {keyword!r} on post {wp_id}")
            remove_keyword(post, keyword)
            removed_posts.append(
                {
                    "keyword": keyword,
                    "wpId": wp_id,
                    "title": post["title"],
                    "reason": reason,
                }
            )

    transformed_labels = []
    for transformation in TRANSFORMATIONS:
        affected_posts = []
        for wp_id in sorted(transformation["wpIds"], key=int):
            post = by_id[wp_id]
            for old_keyword in transformation["from"]:
                remove_keyword(post, old_keyword)
            add_keyword(post, transformation["to"])
            affected_posts.append({"wpId": wp_id, "title": post["title"]})
        transformed_labels.append(
            {
                "from": transformation["from"],
                "to": transformation["to"],
                "reason": transformation["reason"],
                "posts": affected_posts,
            }
        )

    touched_ids = {post["wpId"] for post in removed_posts} | {
        post["wpId"]
        for transformation in transformed_labels
        for post in transformation["posts"]
    }
    for wp_id in touched_ids:
        by_id[wp_id]["secondaryKeywords"] = sorted(
            by_id[wp_id].get("secondaryKeywords", []), key=str.casefold
        )

    duplicate_keywords = [
        str(post["wpId"])
        for post in posts
        if len(post.get("secondaryKeywords", []))
        != len(
            {
                value.casefold().strip()
                for value in post.get("secondaryKeywords", [])
            }
        )
    ]
    topic_keyword_overlaps = [
        str(post["wpId"])
        for post in posts
        if {value.casefold().strip() for value in post.get("topics", [])}
        & {
            value.casefold().strip()
            for value in post.get("secondaryKeywords", [])
        }
    ]
    if duplicate_keywords or topic_keyword_overlaps:
        raise ValueError(
            "Validation failed: duplicate keywords "
            f"{duplicate_keywords[:5]}, topic/keyword overlaps "
            f"{topic_keyword_overlaps[:5]}"
        )

    counts_after = Counter(
        keyword
        for post in posts
        for keyword in post.get("secondaryKeywords", [])
    )
    if len(counts_after) != EXPECTED_UNIQUE_KEYWORDS_AFTER:
        raise ValueError(
            f"Expected {EXPECTED_UNIQUE_KEYWORDS_AFTER} unique keywords after "
            f"cleanup; found {len(counts_after)}"
        )

    resulting_keywords = sorted(
        ({keyword for keyword in original_keywords if keyword in counts_after})
        | replacement_labels,
        key=str.casefold,
    )
    links_before = sum(counts_before.values())
    links_after = sum(counts_after.values())
    audit = {
        "auditDate": "2026-08-18",
        "scope": (
            "Every secondary keyword assigned to exactly four posts before this "
            "re-audit"
        ),
        "criterion": (
            "Retain meaningful supporting subjects, people, texts, places, or "
            "concepts; remove passing mentions; consolidate duplicate search "
            "concepts; and use precise labels."
        ),
        "originalKeywords": original_keywords,
        "auditedKeywords": resulting_keywords,
        "summary": {
            "keywordsAudited": len(original_keywords),
            "linksReviewed": len(original_keywords) * 4,
            "netLinksRemoved": links_before - links_after,
            "uniqueKeywordsBefore": len(counts_before),
            "uniqueKeywordsAfter": len(counts_after),
            "transformations": len(transformed_labels),
            "assignmentsRemovedWithoutReplacement": len(removed_posts),
        },
        "transformations": transformed_labels,
        "removedPosts": removed_posts,
    }
    write_json(POSTS_PATH, posts)
    write_json(AUDIT_PATH, audit)
    print(json.dumps(audit["summary"], indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
