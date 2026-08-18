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
    / "biblical_discrepancies_through_archaeology_secondary_keyword_audit.json"
)

EXPECTED_UNIQUE_KEYWORDS = 912
EXPECTED_COUNTS = {
    "Biblical Discrepancies": 16,
    "Historical Criticism": 16,
    "Historicity": 16,
    "Jesus' Birth Narratives": 16,
    "Peter": 16,
    "Amos": 15,
    "Documentary Hypothesis": 15,
    "Jesus' Family": 15,
    "Poverty": 15,
    "Archaeology and Material Evidence": 14,
}

REMOVALS = {
    "Biblical Discrepancies": set(),
    "Historical Criticism": {"12282"},
    "Historicity": {"4335"},
    "Jesus' Birth Narratives": {"47199"},
    "Peter": {
        "50273",
        "47122",
        "38488",
        "34033",
        "33005",
        "32580",
        "27838",
        "21069",
        "21061",
        "12903",
        "11793",
        "11508",
        "7155",
        "6881",
        "6362",
        "4317",
    },
    "Amos": {
        "49472",
        "28520",
        "27131",
        "20867",
        "12752",
        "12186",
        "9479",
    },
    "Documentary Hypothesis": set(),
    "Jesus' Family": {"38100", "36799", "8403", "4247"},
    "Poverty": {"49861", "37422", "30120", "21281", "15448", "4535"},
    "Archaeology and Material Evidence": {"36709", "7601"},
}

ADDITIONS = {
    "Peter the Apostle": {"50273", "11793"},
}

REMOVAL_REASONS = {
    "Historical Criticism": (
        "The post discusses scholarly consensus rather than historical "
        "criticism as a method."
    ),
    "Historicity": (
        "The post addresses contradictions and source composition rather "
        "than whether the narrated events occurred."
    ),
    "Jesus' Birth Narratives": (
        "The birth narrative receives only a passing sentence in a broader "
        "survey of Luke and John."
    ),
    "Peter": (
        "Peter is incidental, ambiguous, part of a title or list, a reference "
        "to First Peter, or a different person; the two substantive apostle "
        "assignments are normalized to Peter the Apostle."
    ),
    "Amos": (
        "Amos appears only in a list, brief comparison, or reference to an "
        "earlier discussion."
    ),
    "Jesus' Family": (
        "Jesus' family is only a brief motif, isolated example, or one session "
        "within a broader event announcement."
    ),
    "Poverty": (
        "Poverty is merely passing or is used metaphorically for material "
        "existence rather than as a meaningful searchable subject."
    ),
    "Archaeology and Material Evidence": (
        "The post evaluates Josephus as textual evidence; archaeology is only "
        "mentioned as a separate argument."
    ),
}

EXPECTED_RESULT_COUNTS = {
    "Biblical Discrepancies": 16,
    "Historical Criticism": 15,
    "Historicity": 15,
    "Jesus' Birth Narratives": 15,
    "Peter": 0,
    "Peter the Apostle": 136,
    "Amos": 8,
    "Documentary Hypothesis": 15,
    "Jesus' Family": 11,
    "Poverty": 9,
    "Archaeology and Material Evidence": 12,
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
    affected_ids = set().union(*REMOVALS.values(), *ADDITIONS.values())
    missing_ids = sorted(affected_ids - posts_by_id.keys())
    if missing_ids:
        raise ValueError(f"Post IDs missing from search index: {missing_ids}")

    for keyword, post_ids in REMOVALS.items():
        for post_id in post_ids:
            if keyword not in posts_by_id[post_id].get("secondaryKeywords", []):
                raise ValueError(f"{post_id} does not contain keyword {keyword!r}")

    for keyword, post_ids in ADDITIONS.items():
        for post_id in post_ids:
            if keyword in posts_by_id[post_id].get("secondaryKeywords", []):
                raise ValueError(f"{post_id} already contains keyword {keyword!r}")

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

    added_posts: list[dict[str, str]] = []
    for keyword, post_ids in ADDITIONS.items():
        for post_id in post_ids:
            post = posts_by_id[post_id]
            post.setdefault("secondaryKeywords", []).append(keyword)
            post["secondaryKeywords"] = sorted(
                post["secondaryKeywords"], key=str.casefold
            )
            added_posts.append(
                {
                    "wpId": post_id,
                    "title": str(post["title"]),
                    "keyword": keyword,
                    "reason": (
                        "The post substantively discusses Peter the Apostle; "
                        "the precise keyword replaces the ambiguous Peter label."
                    ),
                }
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
    if len(counts_after) != EXPECTED_UNIQUE_KEYWORDS - 1:
        raise ValueError(
            f"Expected {EXPECTED_UNIQUE_KEYWORDS - 1} unique keywords after "
            f"retiring Peter; found {len(counts_after)}"
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
            "Ten highest-frequency pending secondary keywords: Biblical "
            "Discrepancies through Archaeology and Material Evidence"
        ),
        "criterion": (
            "Retain keywords that identify meaningful supporting subjects, "
            "people, texts, places, or concepts; remove passing mentions, "
            "lists, isolated examples, ambiguous names, and metaphorical uses "
            "that would mislead searchers."
        ),
        "auditedKeywords": list(EXPECTED_COUNTS),
        "summary": {
            "keywordsAudited": len(EXPECTED_COUNTS),
            "linksReviewed": sum(EXPECTED_COUNTS.values()),
            "assignmentsRetained": sum(EXPECTED_COUNTS.values())
            - len(removed_posts),
            "assignmentsRemoved": len(removed_posts),
            "assignmentsAdded": len(added_posts),
            "uniqueKeywordsBefore": len(counts_before),
            "uniqueKeywordsAfter": len(counts_after),
        },
        "resultingCounts": actual_result_counts,
        "removedPosts": sorted(
            removed_posts,
            key=lambda item: (item["keyword"].casefold(), item["title"].casefold()),
        ),
        "addedPosts": sorted(added_posts, key=lambda item: item["title"].casefold()),
    }

    write_json(POSTS_PATH, posts)
    write_json(AUDIT_PATH, audit)
    print(json.dumps(audit["summary"], indent=2))
    print(json.dumps(actual_result_counts, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
