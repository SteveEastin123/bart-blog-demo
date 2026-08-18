"""Apply the approved Nicodemus, 2 Corinthians, and Mary keyword audits."""

from __future__ import annotations

from collections import Counter
import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = (
    ROOT
    / "data"
    / "audits"
    / "nicodemus_2_corinthians_mary_secondary_keyword_audit.json"
)

EXPECTED_UNIQUE_KEYWORDS = 910
EXPECTED_COUNTS = {"Nicodemus": 35, "2 Corinthians": 34, "Mary": 33}

REMOVALS = {
    "Nicodemus": {
        "47098",
        "36424",
        "26081",
        "25439",
        "17813",
        "15676",
        "13196",
        "13189",
        "11734",
        "8763",
        "8296",
        "7556",
        "7046",
        "7031",
        "6578",
        "4743",
    },
    "2 Corinthians": {
        "47245",
        "47169",
        "47123",
        "33874",
        "17064",
        "16452",
        "16254",
        "16251",
        "15573",
        "8353",
        "8344",
        "2597",
    },
    "Mary": {
        "48832",
        "47122",
        "47096",
        "40016",
        "38093",
        "37901",
        "35270",
        "34332",
        "31511",
        "31505",
        "31498",
        "21893",
        "21255",
        "21223",
        "17247",
        "16146",
        "16088",
        "16063",
        "16054",
        "15307",
        "13611",
        "13227",
        "11294",
        "8657",
        "8401",
        "8326",
        "7206",
        "6104",
        "6362",
        "4317",
        "3512",
        "3495",
        "2106",
    },
}

ADDITIONS = {
    "Mary, Mother of Jesus": {
        "48832",
        "38093",
        "37901",
        "35270",
        "31511",
        "31505",
        "31498",
        "21893",
        "21255",
        "16146",
        "16088",
        "16063",
        "16054",
        "15307",
        "13611",
        "8657",
        "8401",
        "7206",
        "3495",
    },
    "Mary of Bethany": {"34332"},
}

REMOVAL_REASONS = {
    "Nicodemus": (
        "Nicodemus is only listed, briefly mentioned, or named in a text title "
        "rather than functioning as meaningful supporting evidence."
    ),
    "2 Corinthians": (
        "The letter appears only in a list, citation, statistical comparison, "
        "or analogy rather than as a meaningful supporting subject."
    ),
    "Mary": (
        "The ambiguous label is replaced by Mary, Mother of Jesus or Mary of "
        "Bethany where the identity is substantive, and otherwise removed."
    ),
}


def normalize(value: str) -> str:
    """Normalize a label for duplicate and topic-overlap checks."""
    return " ".join(value.casefold().split())


def read_json(path: Path) -> object:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value: object) -> None:
    path.write_text(
        json.dumps(value, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )


def keyword_counts(posts: list[dict[str, object]]) -> Counter[str]:
    return Counter(
        keyword
        for post in posts
        for keyword in post.get("secondaryKeywords", [])
    )


def main() -> int:
    posts = read_json(POSTS_PATH)
    if not isinstance(posts, list):
        raise TypeError("Post search index must be a JSON array")

    counts_before = keyword_counts(posts)
    if len(counts_before) != EXPECTED_UNIQUE_KEYWORDS:
        raise ValueError(
            f"Expected {EXPECTED_UNIQUE_KEYWORDS} unique keywords before cleanup; "
            f"found {len(counts_before)}"
        )
    actual_counts = {
        keyword: counts_before.get(keyword, 0) for keyword in EXPECTED_COUNTS
    }
    if actual_counts != EXPECTED_COUNTS:
        raise ValueError(f"Unexpected starting counts: {actual_counts}")

    posts_by_id = {str(post["wpId"]): post for post in posts}
    affected_ids = set().union(*REMOVALS.values(), *ADDITIONS.values())
    missing_ids = sorted(affected_ids - posts_by_id.keys())
    if missing_ids:
        raise ValueError(f"Post IDs missing from search index: {missing_ids}")

    for keyword, post_ids in REMOVALS.items():
        for post_id in post_ids:
            keywords = posts_by_id[post_id].get("secondaryKeywords", [])
            if keyword not in keywords:
                raise ValueError(f"{post_id} does not contain keyword {keyword!r}")
    for keyword, post_ids in ADDITIONS.items():
        for post_id in post_ids:
            keywords = posts_by_id[post_id].get("secondaryKeywords", [])
            if keyword in keywords:
                raise ValueError(f"{post_id} already contains keyword {keyword!r}")

    removed_posts: list[dict[str, str]] = []
    added_posts: list[dict[str, str]] = []
    for post in posts:
        post_id = str(post["wpId"])
        updated_keywords: list[str] = []
        for keyword in post.get("secondaryKeywords", []):
            if post_id in REMOVALS.get(keyword, set()):
                removed_posts.append(
                    {
                        "wpId": post_id,
                        "title": str(post["title"]),
                        "keyword": keyword,
                        "reason": REMOVAL_REASONS[keyword],
                    }
                )
                continue
            updated_keywords.append(keyword)

        for keyword, post_ids in ADDITIONS.items():
            if post_id in post_ids:
                updated_keywords.append(keyword)
                added_posts.append(
                    {
                        "wpId": post_id,
                        "title": str(post["title"]),
                        "keyword": keyword,
                    }
                )

        seen: set[str] = set()
        deduplicated: list[str] = []
        for keyword in updated_keywords:
            key = normalize(keyword)
            if key not in seen:
                seen.add(key)
                deduplicated.append(keyword)
        post["secondaryKeywords"] = deduplicated

    expected_removals = sum(len(post_ids) for post_ids in REMOVALS.values())
    expected_additions = sum(len(post_ids) for post_ids in ADDITIONS.values())
    if len(removed_posts) != expected_removals:
        raise ValueError(
            f"Expected {expected_removals} removals; applied {len(removed_posts)}"
        )
    if len(added_posts) != expected_additions:
        raise ValueError(
            f"Expected {expected_additions} additions; applied {len(added_posts)}"
        )

    duplicate_posts: list[str] = []
    overlap_posts: list[str] = []
    for post in posts:
        normalized_keywords = [
            normalize(keyword) for keyword in post.get("secondaryKeywords", [])
        ]
        if len(normalized_keywords) != len(set(normalized_keywords)):
            duplicate_posts.append(str(post["wpId"]))
        normalized_topics = {normalize(topic) for topic in post.get("topics", [])}
        if normalized_topics.intersection(normalized_keywords):
            overlap_posts.append(str(post["wpId"]))
    if duplicate_posts:
        raise ValueError(f"Duplicate secondary keywords on posts: {duplicate_posts}")
    if overlap_posts:
        raise ValueError(f"Topic/keyword overlaps on posts: {overlap_posts}")

    counts_after = keyword_counts(posts)
    expected_unique_after = EXPECTED_UNIQUE_KEYWORDS - 1
    if len(counts_after) != expected_unique_after:
        raise ValueError(
            f"Expected {expected_unique_after} unique keywords after cleanup; "
            f"found {len(counts_after)}"
        )

    expected_result_counts = {
        "Nicodemus": 19,
        "2 Corinthians": 22,
        "Mary": 0,
        "Mary, Mother of Jesus": 103,
        "Mary of Bethany": 6,
    }
    actual_result_counts = {
        keyword: counts_after.get(keyword, 0) for keyword in expected_result_counts
    }
    if actual_result_counts != expected_result_counts:
        raise ValueError(f"Unexpected resulting counts: {actual_result_counts}")

    audit = {
        "auditDate": "2026-08-18",
        "scope": "Nicodemus, 2 Corinthians, and Mary secondary-keyword assignments",
        "criterion": (
            "Retain meaningful supporting subjects, people, or texts; remove "
            "passing mentions, lists, citations, and ambiguous labels; and "
            "replace broad names with precise identities when supported."
        ),
        "originalKeywords": ["Nicodemus", "2 Corinthians", "Mary"],
        "auditedKeywords": [
            "Nicodemus",
            "2 Corinthians",
            "Mary, Mother of Jesus",
            "Mary of Bethany",
        ],
        "summary": {
            "keywordsAudited": 3,
            "linksReviewed": sum(EXPECTED_COUNTS.values()),
            "assignmentsRemoved": len(removed_posts),
            "assignmentsAdded": len(added_posts),
            "netAssignmentsRemoved": len(removed_posts) - len(added_posts),
            "uniqueKeywordsBefore": len(counts_before),
            "uniqueKeywordsAfter": len(counts_after),
            "keywordsRetired": 1,
            "labelsNormalizedOrRefined": 1,
        },
        "retiredKeywords": ["Mary"],
        "labelChanges": [
            {
                "from": "Mary",
                "to": ["Mary, Mother of Jesus", "Mary of Bethany"],
            }
        ],
        "resultingCounts": actual_result_counts,
        "removedPosts": sorted(
            removed_posts,
            key=lambda item: (item["keyword"].casefold(), item["title"].casefold()),
        ),
        "addedPosts": sorted(
            added_posts,
            key=lambda item: (item["keyword"].casefold(), item["title"].casefold()),
        ),
    }

    write_json(POSTS_PATH, posts)
    write_json(AUDIT_PATH, audit)
    print(json.dumps(audit["summary"], indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
