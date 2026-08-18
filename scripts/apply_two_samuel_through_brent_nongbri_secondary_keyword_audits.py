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
    / "two_samuel_through_brent_nongbri_secondary_keyword_audit.json"
)

EXPECTED_UNIQUE_KEYWORDS = 911
EXPECTED_COUNTS = {
    "2 Samuel": 12,
    "Gospel of the Ebionites": 12,
    "Professional Scholarship": 12,
    "Repentance": 12,
    "Rodney Stark": 12,
    "Women in the Church": 12,
    "Writing a Book": 12,
    "Acts of Thomas": 11,
    "Albert Schweitzer": 11,
    "Brent Nongbri": 11,
}

REMOVALS = {
    "2 Samuel": {
        "49514",
        "47640",
        "16082",
        "15172",
        "12239",
        "4088",
    },
    "Gospel of the Ebionites": {"4273"},
    "Professional Scholarship": set(),
    "Repentance": {"47109", "6467"},
    "Rodney Stark": {"2296"},
    "Women in the Church": {"9122", "4602", "4598"},
    "Writing a Book": set(),
    "Acts of Thomas": {"35786", "35338", "21403", "21145", "6637"},
    "Albert Schweitzer": {"35324", "28730", "12747", "7392", "6537", "6142"},
    "Brent Nongbri": {"16314"},
}

REMOVAL_REASONS = {
    "2 Samuel": (
        "The book appears only in a list, a passing comparison, or a single "
        "illustrative reference rather than as a meaningfully treated text."
    ),
    "Gospel of the Ebionites": (
        "The Gospel appears only in a list of texts planned for a scholarly "
        "commentary."
    ),
    "Repentance": (
        "Repentance is confined to a brief part of a multipart question or a "
        "passing comparison in a broader critique."
    ),
    "Rodney Stark": (
        "Stark and his book appear only among several recommended studies and "
        "are not discussed."
    ),
    "Women in the Church": (
        "The phrase appears only in a list of scholarly disputes, or the post "
        "concerns women in Jesus' ministry rather than women in churches."
    ),
    "Acts of Thomas": (
        "The work appears only in a footnote, syllabus, contents list, or "
        "unrelated apocryphal-acts discussion."
    ),
    "Albert Schweitzer": (
        "Schweitzer receives only a brief attribution or historical reference "
        "rather than meaningful discussion of him or his work."
    ),
    "Brent Nongbri": (
        "Nongbri appears in lists of scholars following the manuscript "
        "controversy but is not a sustained subject of the post."
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
        "2 Samuel": 6,
        "Gospel of the Ebionites": 11,
        "Professional Scholarship": 12,
        "Repentance": 10,
        "Rodney Stark": 11,
        "Women in the Church": 9,
        "Writing a Book": 12,
        "Acts of Thomas": 6,
        "Albert Schweitzer": 5,
        "Brent Nongbri": 10,
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
            "2 Samuel through Brent Nongbri secondary-keyword assignments, "
            "covering the ten highest-frequency pending keywords"
        ),
        "criterion": (
            "Retain meaningful supporting subjects, people, texts, places, and "
            "concepts; remove passing mentions, lists, bibliography-only "
            "references, isolated course entries, and contextual references "
            "that are not useful search signals."
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
