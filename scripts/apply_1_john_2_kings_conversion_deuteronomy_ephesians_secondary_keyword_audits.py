"""Apply five approved full-text secondary-keyword audits."""

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
    / "1_john_2_kings_conversion_deuteronomy_ephesians_secondary_keyword_audit.json"
)

EXPECTED_UNIQUE_KEYWORDS = 909
EXPECTED_COUNTS = {
    "1 John": 29,
    "2 Kings": 29,
    "Conversion": 28,
    "Deuteronomy": 28,
    "Ephesians": 28,
}

REMOVALS = {
    "1 John": {
        "35786",
        "35338",
        "9077",
        "4121",
        "20122",
        "2459",
    },
    "2 Kings": {
        "49514",
        "47640",
        "47215",
        "35238",
        "33641",
        "26942",
        "16082",
        "15172",
        "12289",
        "12239",
        "4088",
        "3631",
    },
    "Conversion": {
        "47139",
        "40170",
        "37615",
        "35188",
        "25481",
        "25276",
        "15609",
        "9393",
        "4938",
    },
    "Deuteronomy": {
        "48687",
        "38977",
        "35354",
        "32825",
        "25653",
        "15303",
        "12752",
        "12560",
        "12447",
        "11589",
    },
    "Ephesians": {
        "46973",
        "47246",
        "47155",
        "47123",
        "38583",
        "31186",
        "16656",
        "15607",
        "8147",
    },
}

REMOVAL_REASONS = {
    "1 John": (
        "The apparent reference is a footnote artifact, syllabus entry, "
        "passing doctrinal example, anecdote, or course reference rather "
        "than meaningful discussion of 1 John."
    ),
    "2 Kings": (
        "The book appears only in a broad list, brief analogy, isolated "
        "comparison, or erroneous citation of 1 Kings 11:1 rather than as "
        "a meaningfully discussed text."
    ),
    "Conversion": (
        "Conversion appears only as chronology, course or interview content, "
        "or brief historical background rather than as a meaningful subject."
    ),
    "Deuteronomy": (
        "The book appears only in a canon or Pentateuch list, citation list, "
        "course description, or passing example rather than as a meaningful "
        "source or subject."
    ),
    "Ephesians": (
        "The letter appears only in a list, brief comparison, reference to a "
        "previous post, or—in one case—the word denotes the people of Ephesus "
        "rather than the New Testament letter."
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
        "1 John": 23,
        "2 Kings": 17,
        "Conversion": 19,
        "Deuteronomy": 18,
        "Ephesians": 19,
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
            "1 John, 2 Kings, Conversion, Deuteronomy, and Ephesians "
            "secondary-keyword assignments"
        ),
        "criterion": (
            "Retain texts, events, and concepts that receive meaningful "
            "supporting discussion; remove footnote artifacts, lists, course "
            "or syllabus entries, passing examples, chronological references, "
            "and ambiguous or erroneous matches."
        ),
        "auditedKeywords": list(EXPECTED_COUNTS),
        "summary": {
            "keywordsAudited": len(EXPECTED_COUNTS),
            "linksReviewed": sum(EXPECTED_COUNTS.values()),
            "assignmentsRetained": sum(actual_result_counts.values()),
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
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
