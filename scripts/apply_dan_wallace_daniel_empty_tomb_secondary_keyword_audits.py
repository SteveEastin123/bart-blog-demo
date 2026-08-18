"""Apply the approved Dan Wallace, Daniel, and Empty Tomb keyword audits."""

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
    / "dan_wallace_daniel_empty_tomb_secondary_keyword_audit.json"
)

EXPECTED_UNIQUE_KEYWORDS = 909
EXPECTED_COUNTS = {
    "Dan Wallace": 31,
    "Daniel": 31,
    "Empty Tomb": 31,
}

DANIEL_INCIDENTAL_REMOVALS = {
    "49019",
    "48687",
    "16534",
    "12239",
    "9477",
}
DANIEL_TOPIC_OVERLAPS = {
    "40859",
    "34100",
    "28443",
    "26782",
    "15562",
    "12838",
    "12830",
    "12813",
}
DANIEL_RENAMED_ASSIGNMENTS = {
    "49468",
    "48691",
    "47851",
    "35685",
    "36539",
    "34327",
    "29438",
    "28460",
    "25886",
    "21093",
    "17343",
    "12854",
    "12801",
    "12248",
    "9508",
    "8511",
    "7432",
    "3989",
}

EMPTY_TOMB_INCIDENTAL_REMOVALS = {
    "49563",
    "38520",
    "38488",
    "33317",
    "21324",
    "20531",
}
EMPTY_TOMB_TOPIC_OVERLAPS = {
    "47208",
    "47177",
    "32498",
    "29856",
    "15046",
    "3007",
    "1976",
    "1970",
    "1738",
}
EMPTY_TOMB_RENAMED_ASSIGNMENTS = {
    "49107",
    "46956",
    "47203",
    "37291",
    "35342",
    "35338",
    "24590",
    "22094",
    "21421",
    "15846",
    "12775",
    "12401",
    "8897",
    "7199",
    "7135",
    "3388",
}

REMOVALS = {
    "Dan Wallace": {"8433"},
    "Daniel": (
        DANIEL_INCIDENTAL_REMOVALS
        | DANIEL_TOPIC_OVERLAPS
        | DANIEL_RENAMED_ASSIGNMENTS
    ),
    "Empty Tomb": (
        EMPTY_TOMB_INCIDENTAL_REMOVALS
        | EMPTY_TOMB_TOPIC_OVERLAPS
        | EMPTY_TOMB_RENAMED_ASSIGNMENTS
    ),
}

ADDITIONS = {
    "Book of Daniel": DANIEL_RENAMED_ASSIGNMENTS,
    "Empty Tomb Traditions": EMPTY_TOMB_RENAMED_ASSIGNMENTS,
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


def removal_reason(keyword: str, post_id: str) -> str:
    if keyword == "Dan Wallace":
        return "Wallace is not discussed in the available post text."
    if keyword == "Daniel":
        if post_id in DANIEL_INCIDENTAL_REMOVALS:
            return (
                "Daniel appears only in a list, passing citation, mistaken reader "
                "reference, or broad prophetic context."
            )
        if post_id in DANIEL_TOPIC_OVERLAPS:
            return (
                "The post already has the Book of Daniel topic, making the "
                "topic-aligned secondary keyword redundant."
            )
        return "The supporting keyword is renamed to Book of Daniel."
    if post_id in EMPTY_TOMB_INCIDENTAL_REMOVALS:
        return (
            "The empty tomb appears only as chronology, biographical context, "
            "an apologetic example, or an analogy."
        )
    if post_id in EMPTY_TOMB_TOPIC_OVERLAPS:
        return (
            "The post already has the Empty Tomb Traditions topic, making the "
            "topic-aligned secondary keyword redundant."
        )
    return "The supporting keyword is renamed to Empty Tomb Traditions."


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
                        "reason": removal_reason(keyword, post_id),
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
        post["secondaryKeywords"] = updated_keywords

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
    if len(counts_after) != EXPECTED_UNIQUE_KEYWORDS:
        raise ValueError(
            f"Expected {EXPECTED_UNIQUE_KEYWORDS} unique keywords after cleanup; "
            f"found {len(counts_after)}"
        )

    expected_result_counts = {
        "Dan Wallace": 30,
        "Daniel": 0,
        "Book of Daniel": 18,
        "Empty Tomb": 0,
        "Empty Tomb Traditions": 16,
    }
    actual_result_counts = {
        keyword: counts_after.get(keyword, 0)
        for keyword in expected_result_counts
    }
    if actual_result_counts != expected_result_counts:
        raise ValueError(f"Unexpected resulting counts: {actual_result_counts}")

    audit = {
        "auditDate": "2026-08-18",
        "scope": (
            "Dan Wallace, Daniel, and Empty Tomb secondary-keyword assignments"
        ),
        "criterion": (
            "Retain meaningful supporting people, texts, and traditions; remove "
            "passing mentions, lists, analogies, and mistaken references; use "
            "topic-aligned labels; and remove same-post topic/keyword duplication."
        ),
        "originalKeywords": list(EXPECTED_COUNTS),
        "auditedKeywords": [
            "Dan Wallace",
            "Book of Daniel",
            "Empty Tomb Traditions",
        ],
        "summary": {
            "keywordsAudited": len(EXPECTED_COUNTS),
            "linksReviewed": sum(EXPECTED_COUNTS.values()),
            "assignmentsRemoved": len(removed_posts),
            "assignmentsAdded": len(added_posts),
            "netAssignmentsRemoved": len(removed_posts) - len(added_posts),
            "incidentalAssignmentsRemoved": (
                1
                + len(DANIEL_INCIDENTAL_REMOVALS)
                + len(EMPTY_TOMB_INCIDENTAL_REMOVALS)
            ),
            "topicKeywordOverlapsRemoved": (
                len(DANIEL_TOPIC_OVERLAPS) + len(EMPTY_TOMB_TOPIC_OVERLAPS)
            ),
            "assignmentsRenamed": len(added_posts),
            "uniqueKeywordsBefore": len(counts_before),
            "uniqueKeywordsAfter": len(counts_after),
            "keywordsRetired": 2,
        },
        "retiredKeywords": ["Daniel", "Empty Tomb"],
        "labelChanges": [
            {"from": "Daniel", "to": "Book of Daniel"},
            {"from": "Empty Tomb", "to": "Empty Tomb Traditions"},
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
