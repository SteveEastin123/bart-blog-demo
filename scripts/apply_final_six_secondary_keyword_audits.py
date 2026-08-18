"""Apply the final six approved full-text secondary-keyword audits."""

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
    / "final_six_secondary_keyword_audit.json"
)

EXPECTED_UNIQUE_KEYWORDS = 911
EXPECTED_COUNTS = {
    "Dissertations": 11,
    "Johannine Community": 11,
    "Judges": 11,
    "Media Coverage": 11,
    "Paganism": 11,
    "Synoptic Problem": 11,
}

REMOVALS = {
    "Dissertations": set(),
    "Johannine Community": set(),
    "Judges": {
        "49514",
        "48687",
        "26942",
        "16082",
        "15172",
        "12239",
        "10312",
        "4088",
    },
    "Media Coverage": {"12101"},
    "Paganism": set(),
    "Synoptic Problem": {
        "48565",
        "32879",
        "32298",
        "21106",
        "15880",
        "7962",
    },
}

REMOVAL_REASONS = {
    "Judges": (
        "Judges appears only in a biblical-book list, a passing reference, or "
        "as the ordinary word for people judging a debate rather than as a "
        "meaningfully treated biblical text."
    ),
    "Media Coverage": (
        "Media attention receives only one passing reference in a broader "
        "reflection on finishing a book and changing work habits."
    ),
    "Synoptic Problem": (
        "The Synoptic Problem appears only as a classroom example, syllabus "
        "entry, bibliography subject, or brief background reference without "
        "meaningful treatment."
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
            f"Expected {EXPECTED_UNIQUE_KEYWORDS} unique keywords; "
            f"found {len(counts_before)}"
        )
    actual_counts = {
        keyword: counts_before.get(keyword, 0) for keyword in EXPECTED_COUNTS
    }
    if actual_counts != EXPECTED_COUNTS:
        raise ValueError(f"Unexpected starting counts: {actual_counts}")

    posts_by_id = {str(post["wpId"]): post for post in posts}
    affected_ids = set().union(*REMOVALS.values())
    missing_ids = sorted(affected_ids - posts_by_id.keys())
    if missing_ids:
        raise ValueError(f"Post IDs missing from search index: {missing_ids}")

    for keyword, post_ids in REMOVALS.items():
        for post_id in post_ids:
            keywords = posts_by_id[post_id].get("secondaryKeywords", [])
            if keyword not in keywords:
                raise ValueError(f"{post_id} does not contain keyword {keyword!r}")

    removed_posts: list[dict[str, str]] = []
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
        post["secondaryKeywords"] = updated_keywords

    expected_removals = sum(len(post_ids) for post_ids in REMOVALS.values())
    if len(removed_posts) != expected_removals:
        raise ValueError(
            f"Expected {expected_removals} removals; applied {len(removed_posts)}"
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
        "Dissertations": 11,
        "Johannine Community": 11,
        "Judges": 3,
        "Media Coverage": 10,
        "Paganism": 11,
        "Synoptic Problem": 5,
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
            "Dissertations, Johannine Community, Judges, Media Coverage, "
            "Paganism, and Synoptic Problem secondary-keyword assignments"
        ),
        "criterion": (
            "Retain meaningful supporting subjects, people, texts, places, and "
            "concepts; remove passing mentions, lists, bibliography-only "
            "references, isolated course entries, ambiguous word matches, and "
            "contextual references that are not useful search signals."
        ),
        "auditedKeywords": list(EXPECTED_COUNTS),
        "summary": {
            "keywordsAudited": len(EXPECTED_COUNTS),
            "linksReviewed": sum(EXPECTED_COUNTS.values()),
            "assignmentsRetained": sum(EXPECTED_COUNTS.values())
            - len(removed_posts),
            "assignmentsRemoved": len(removed_posts),
            "uniqueKeywordsBefore": len(counts_before),
            "uniqueKeywordsAfter": len(counts_after),
        },
        "resultingCounts": actual_result_counts,
        "removedPosts": sorted(
            removed_posts,
            key=lambda item: (item["keyword"].casefold(), item["title"].casefold()),
        ),
    }

    write_json(POSTS_PATH, posts)
    write_json(AUDIT_PATH, audit)
    print(json.dumps(audit["summary"], indent=2))
    print(json.dumps(actual_result_counts, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
