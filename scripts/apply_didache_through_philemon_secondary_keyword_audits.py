"""Apply ten approved full-text secondary-keyword audits."""

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
    / "didache_through_philemon_secondary_keyword_audit.json"
)

EXPECTED_UNIQUE_KEYWORDS = 911
EXPECTED_COUNTS = {
    "Didache": 14,
    "Jesus' Passion Narratives": 14,
    "Karen King": 14,
    "Leviticus": 14,
    "New Revised Standard Version (NRSV)": 14,
    "Pentateuch": 14,
    "Proverbs": 14,
    "Judas Thomas": 13,
    "King Saul": 13,
    "Philemon": 13,
}

REMOVALS = {
    "Didache": {"48021", "37565", "15398", "15392", "11992", "6637"},
    "Jesus' Passion Narratives": {"32879", "8417", "4711"},
    "Karen King": set(),
    "Leviticus": {"48687", "36908"},
    "New Revised Standard Version (NRSV)": {
        "48795",
        "40653",
        "33100",
        "29790",
        "20471",
        "11845",
        "2771",
        "2732",
    },
    "Pentateuch": {"32825", "29744", "28520", "11645", "11613"},
    "Proverbs": {"48687", "12475"},
    "Judas Thomas": {"40016"},
    "King Saul": {"47640", "29744", "27097", "23142"},
    "Philemon": {"47123", "33874", "17064", "3964"},
}

REMOVAL_REASONS = {
    "Didache": (
        "The Didache appears only in a passing comparison, corpus list, or "
        "table-of-contents entry."
    ),
    "Jesus' Passion Narratives": (
        "Passion narratives appear only in a bibliography entry, passing "
        "course example, or one week of a broader syllabus."
    ),
    "Leviticus": (
        "Leviticus is merely listed or cited once while defining a different "
        "subject."
    ),
    "New Revised Standard Version (NRSV)": (
        "The NRSV merely identifies a quoted translation, publication choice, "
        "or earlier discussion and is not evaluated in the post."
    ),
    "Pentateuch": (
        "The Pentateuch serves only as introductory context, a boundary marker, "
        "or a reference to another discussion."
    ),
    "Proverbs": (
        "Proverbs is merely listed or used as a passing contrast with another "
        "biblical book."
    ),
    "Judas Thomas": (
        "Judas Thomas appears only in a list of figures credited with "
        "non-canonical writings."
    ),
    "King Saul": (
        "Saul appears only in a list, historical summary, or brief narrative "
        "setup."
    ),
    "Philemon": (
        "Philemon is merely listed among Pauline letters or refers to the "
        "unrelated mythological character rather than Paul's letter."
    ),
}

EXPECTED_RESULT_COUNTS = {
    "Didache": 8,
    "Jesus' Passion Narratives": 11,
    "Karen King": 14,
    "Leviticus": 12,
    "New Revised Standard Version (NRSV)": 6,
    "Pentateuch": 9,
    "Proverbs": 12,
    "Judas Thomas": 12,
    "King Saul": 9,
    "Philemon": 9,
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
            if keyword not in posts_by_id[post_id].get("secondaryKeywords", []):
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
    actual_result_counts = {
        keyword: counts_after.get(keyword, 0)
        for keyword in EXPECTED_RESULT_COUNTS
    }
    if actual_result_counts != EXPECTED_RESULT_COUNTS:
        raise ValueError(f"Unexpected resulting counts: {actual_result_counts}")

    audit = {
        "auditDate": "2026-08-18",
        "scope": (
            "Ten highest-frequency pending secondary keywords: Didache "
            "through Philemon"
        ),
        "criterion": (
            "Retain keywords that identify meaningful supporting subjects, "
            "people, texts, places, or concepts; remove passing mentions, "
            "lists, bibliographies, isolated syllabus entries, and ambiguous "
            "names that would mislead searchers."
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
