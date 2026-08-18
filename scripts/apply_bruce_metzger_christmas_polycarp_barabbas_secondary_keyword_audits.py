"""Apply the approved Metzger, Christmas, Polycarp, and Barabbas audits."""

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
    / "bruce_metzger_christmas_polycarp_barabbas_secondary_keyword_audit.json"
)

EXPECTED_UNIQUE_KEYWORDS = 909
EXPECTED_COUNTS = {
    "Bruce Metzger": 32,
    "Christmas": 32,
    "Polycarp": 32,
    "Barabbas": 31,
}

REMOVALS = {
    "Bruce Metzger": {
        "47567",
        "47086",
        "39455",
        "36803",
        "21855",
        "21145",
        "17191",
        "12697",
        "11643",
        "9674",
        "8271",
        "4792",
        "2792",
        "2732",
        "2721",
        "2624",
        "2533",
    },
    "Christmas": {
        "49062",
        "47032",
        "23276",
        "16293",
        "14395",
        "8087",
        "7326",
        "2764",
    },
    "Polycarp": {
        "48864",
        "48295",
        "33890",
        "15392",
        "14874",
        "13326",
        "3147",
    },
    "Barabbas": {
        "35658",
        "35649",
        "32865",
        "28208",
        "17813",
        "15837",
        "13504",
        "13196",
        "9583",
        "7348",
        "7046",
        "6962",
        "4893",
    },
}

REMOVAL_REASONS = {
    "Bruce Metzger": (
        "Metzger appears only in a bibliography, course assignment, generic "
        "biographical aside, quotation attribution, or transition rather than "
        "as a meaningful supporting subject."
    ),
    "Christmas": (
        "Christmas appears only as a date, gift, joke, transition, or passing "
        "reference rather than as a meaningful seasonal or interpretive subject."
    ),
    "Polycarp": (
        "Polycarp or a work associated with him appears only in a list, syllabus, "
        "dating reference, or general survey rather than as a meaningful subject."
    ),
    "Barabbas": (
        "Barabbas appears only in an event summary, list, course description, or "
        "general Passion narrative rather than as a meaningfully discussed figure."
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
        "Bruce Metzger": 15,
        "Christmas": 24,
        "Polycarp": 25,
        "Barabbas": 18,
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
            "Bruce Metzger, Christmas, Polycarp, and Barabbas "
            "secondary-keyword assignments"
        ),
        "criterion": (
            "Retain meaningful supporting people, events, texts, traditions, or "
            "seasonal subjects; remove passing mentions, lists, citations, course "
            "logistics, generic summaries, and incidental context."
        ),
        "auditedKeywords": list(EXPECTED_COUNTS),
        "summary": {
            "keywordsAudited": len(EXPECTED_COUNTS),
            "linksReviewed": sum(EXPECTED_COUNTS.values()),
            "assignmentsRemoved": len(removed_posts),
            "assignmentsRetained": sum(actual_result_counts.values()),
            "uniqueKeywordsBefore": len(counts_before),
            "uniqueKeywordsAfter": len(counts_after),
            "keywordsRetired": 0,
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
