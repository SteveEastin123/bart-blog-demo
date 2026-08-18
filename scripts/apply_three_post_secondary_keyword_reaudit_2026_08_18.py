#!/usr/bin/env python3
"""Apply the approved re-audit of all current three-post secondary keywords."""

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
    / "three_post_secondary_keyword_reaudit_2026_08_18_secondary_keyword_audit.json"
)

EXPECTED_THREE_POST_KEYWORDS = 81
EXPECTED_UNIQUE_KEYWORDS_BEFORE = 925
EXPECTED_UNIQUE_KEYWORDS_AFTER = 920

REPLACEMENTS = {
    "Apotheosis": {
        "replacement": "Apotheosis and Divinization",
        "wpIds": {"10888", "11699", "11701"},
        "reason": (
            "Apotheosis and Divinization describe the same supporting concept on "
            "these posts and should appear as one searchable label."
        ),
    },
    "Divinization": {
        "replacement": "Apotheosis and Divinization",
        "wpIds": {"10888", "11699", "11701", "50232"},
        "reason": (
            "The combined label preserves both common search terms while removing "
            "duplicate assignments."
        ),
    },
    "Goats": {
        "replacement": "Sheep and Goats",
        "wpIds": {"13236", "13323", "13341"},
        "reason": (
            "The posts discuss the single Sheep and Goats teaching, so separate "
            "animal labels create duplicate search entries."
        ),
    },
    "Sheep": {
        "replacement": "Sheep and Goats",
        "wpIds": {"13236", "13323", "13341"},
        "reason": (
            "The posts discuss the single Sheep and Goats teaching, so separate "
            "animal labels create duplicate search entries."
        ),
    },
    "Genre": {
        "replacement": "Gospel Genre",
        "wpIds": {"6937", "7967", "7970"},
        "reason": (
            "All three posts concern the literary genre of the Gospels, making "
            "Gospel Genre more precise than the generic label Genre."
        ),
    },
    "Simon and Schuster": {
        "replacement": "Simon & Schuster",
        "wpIds": {"11198", "12379", "12638"},
        "reason": "Use the publisher's proper name.",
    },
}

FULL_REMOVALS = {
    "Literacy": {
        "wpIds": {"11284", "11503", "11719"},
        "reason": (
            "Every linked post already has the Ancient Literacy topic, which "
            "contains the same search term and covers the subject more precisely."
        ),
    },
    "Textual Scholar": {
        "wpIds": {"26613", "27119", "27266"},
        "reason": (
            "Textual Scholar is a generic description of Bruce Metzger rather than "
            "a subject of these posts; the Bruce Metzger topic identifies them."
        ),
    },
}

PARTIAL_REMOVALS = {
    "Sulpicius Severus": {
        "removeWpIds": {"1966", "29846"},
        "expectedWpIds": {"1966", "29846", "49838"},
        "reason": (
            "Sulpicius Severus appears only inside a narrow subargument about "
            "Tacitus in these broad replies to Richard Carrier."
        ),
    },
}

THEOLOGY_IDS = {"2852", "11893", "12529"}
THEOLOGY_REPLACEMENT_ID = "12529"
THEOLOGY_REPLACEMENT = "Historical Study and Theology"


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
        (keyword for keyword, count in counts_before.items() if count == 3),
        key=str.casefold,
    )
    if len(original_keywords) != EXPECTED_THREE_POST_KEYWORDS:
        raise ValueError(
            f"Expected {EXPECTED_THREE_POST_KEYWORDS} three-post keywords; "
            f"found {len(original_keywords)}"
        )
    if len(counts_before) != EXPECTED_UNIQUE_KEYWORDS_BEFORE:
        raise ValueError(
            f"Expected {EXPECTED_UNIQUE_KEYWORDS_BEFORE} unique keywords; "
            f"found {len(counts_before)}"
        )

    transformations = []
    for old_keyword, details in REPLACEMENTS.items():
        expected_ids = details["wpIds"]
        actual_ids = keyword_post_ids(posts, old_keyword)
        if actual_ids != expected_ids:
            raise ValueError(
                f"Unexpected assignments for {old_keyword!r}: {sorted(actual_ids)}"
            )
        affected_posts = []
        for wp_id in sorted(expected_ids):
            post = by_id[wp_id]
            remove_keyword(post, old_keyword)
            add_keyword(post, details["replacement"])
            affected_posts.append({"wpId": wp_id, "title": post["title"]})
        transformations.append(
            {
                "from": old_keyword,
                "to": details["replacement"],
                "reason": details["reason"],
                "posts": affected_posts,
            }
        )

    removed_posts = []
    for keyword, details in FULL_REMOVALS.items():
        expected_ids = details["wpIds"]
        actual_ids = keyword_post_ids(posts, keyword)
        if actual_ids != expected_ids:
            raise ValueError(
                f"Unexpected assignments for {keyword!r}: {sorted(actual_ids)}"
            )
        for wp_id in sorted(expected_ids):
            post = by_id[wp_id]
            remove_keyword(post, keyword)
            removed_posts.append(
                {
                    "keyword": keyword,
                    "wpId": wp_id,
                    "title": post["title"],
                    "reason": details["reason"],
                }
            )

    theology_ids = keyword_post_ids(posts, "Theology")
    if theology_ids != THEOLOGY_IDS:
        raise ValueError(
            f"Unexpected assignments for 'Theology': {sorted(theology_ids)}"
        )
    for wp_id in sorted(THEOLOGY_IDS):
        post = by_id[wp_id]
        remove_keyword(post, "Theology")
        if wp_id == THEOLOGY_REPLACEMENT_ID:
            add_keyword(post, THEOLOGY_REPLACEMENT)
            transformations.append(
                {
                    "from": "Theology",
                    "to": THEOLOGY_REPLACEMENT,
                    "reason": (
                        "The more precise label connects this post to the existing "
                        "Historical Study and Theology search concept."
                    ),
                    "posts": [{"wpId": wp_id, "title": post["title"]}],
                }
            )
        else:
            removed_posts.append(
                {
                    "keyword": "Theology",
                    "wpId": wp_id,
                    "title": post["title"],
                    "reason": (
                        "The post already has the Historical Study and Theology "
                        "topic, making the broad Theology keyword redundant."
                    ),
                }
            )

    for keyword, details in PARTIAL_REMOVALS.items():
        actual_ids = keyword_post_ids(posts, keyword)
        if actual_ids != details["expectedWpIds"]:
            raise ValueError(
                f"Unexpected assignments for {keyword!r}: {sorted(actual_ids)}"
            )
        for wp_id in sorted(details["removeWpIds"]):
            post = by_id[wp_id]
            remove_keyword(post, keyword)
            removed_posts.append(
                {
                    "keyword": keyword,
                    "wpId": wp_id,
                    "title": post["title"],
                    "reason": details["reason"],
                }
            )

    touched_ids = {
        post["wpId"]
        for transformation in transformations
        for post in transformation["posts"]
    } | {post["wpId"] for post in removed_posts}
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

    replacement_labels = {
        details["replacement"] for details in REPLACEMENTS.values()
    } | {THEOLOGY_REPLACEMENT}
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
            "Every secondary keyword assigned to exactly three posts before this "
            "re-audit"
        ),
        "criterion": (
            "Retain meaningful supporting search terms; remove weak or redundant "
            "assignments; consolidate labels that describe the same searchable "
            "concept; and use precise names."
        ),
        "originalKeywords": original_keywords,
        "auditedKeywords": resulting_keywords,
        "summary": {
            "keywordsAudited": len(original_keywords),
            "linksReviewed": len(original_keywords) * 3,
            "netLinksRemoved": links_before - links_after,
            "uniqueKeywordsBefore": len(counts_before),
            "uniqueKeywordsAfter": len(counts_after),
            "transformations": len(transformations),
            "assignmentsRemovedWithoutReplacement": len(removed_posts),
        },
        "transformations": transformations,
        "removedPosts": removed_posts,
    }
    write_json(POSTS_PATH, posts)
    write_json(AUDIT_PATH, audit)
    print(json.dumps(audit["summary"], indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
