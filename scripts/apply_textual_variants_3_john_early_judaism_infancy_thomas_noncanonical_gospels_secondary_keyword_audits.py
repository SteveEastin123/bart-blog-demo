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
    / "textual_variants_3_john_early_judaism_infancy_thomas_"
    "noncanonical_gospels_secondary_keyword_audit.json"
)

EXPECTED_UNIQUE_KEYWORDS = 908
EXPECTED_COUNTS = {
    "Textual Variants": 23,
    "3 John": 22,
    "Early Judaism": 22,
    "Infancy Gospel of Thomas": 22,
    "Non-Canonical Gospels": 22,
}

REMOVALS = {
    "Textual Variants": {"39777", "16169", "12373"},
    "3 John": {
        "40686",
        "32505",
        "20122",
        "17830",
        "17813",
        "17064",
        "15818",
        "15713",
    },
    "Early Judaism": {
        "36377",
        "35824",
        "21209",
        "20836",
        "15738",
        "9315",
        "7732",
        "7475",
    },
    "Infancy Gospel of Thomas": {
        "49350",
        "35410",
        "35188",
        "21260",
        "21106",
        "16606",
        "7688",
        "6637",
        "5016",
        "4711",
        "3216",
        "2268",
    },
    "Non-Canonical Gospels": {"35410", "33161", "7648"},
}

REMOVAL_REASONS = {
    "Textual Variants": (
        "Textual variants appear only as a transition, contrast, or subject "
        "of a different discussion rather than a meaningfully treated issue."
    ),
    "3 John": (
        "The post merely lists 3 John, uses it as a passing comparison, or "
        "mentions it while introducing a different Johannine subject."
    ),
    "Early Judaism": (
        "Early Judaism identifies an academic field, conference specialty, "
        "or area of blog coverage rather than a subject discussed in the post."
    ),
    "Infancy Gospel of Thomas": (
        "The text appears only in a passing example, reading list, table of "
        "contents, or brief reference to another class or publication."
    ),
    "Non-Canonical Gospels": (
        "Non-canonical Gospels appear only in a list or brief transition and "
        "are not meaningfully discussed."
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
        "Textual Variants": 20,
        "3 John": 14,
        "Early Judaism": 14,
        "Infancy Gospel of Thomas": 10,
        "Non-Canonical Gospels": 19,
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
            "Textual Variants, 3 John, Early Judaism, Infancy Gospel of "
            "Thomas, and Non-Canonical Gospels secondary-keyword assignments"
        ),
        "criterion": (
            "Retain meaningful supporting textual issues, biblical writings, "
            "historical settings, and non-canonical texts; remove field lists, "
            "transitions, passing comparisons, bibliographies, tables of "
            "contents, and isolated course or publication references."
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
