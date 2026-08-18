"""Apply five approved secondary-keyword audits."""

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
    / "gospel_of_nicodemus_origen_atonement_herod_antipas_"
    "virgin_birth_secondary_keyword_audit.json"
)

EXPECTED_UNIQUE_KEYWORDS = 909
EXPECTED_COUNTS = {
    "Gospel of Nicodemus": 31,
    "Origen": 31,
    "Atonement": 30,
    "Herod Antipas": 30,
    "Virgin Birth": 30,
}

REMOVALS = {
    "Gospel of Nicodemus": {
        "38877",
        "35410",
        "34597",
        "21317",
        "21145",
        "21106",
        "16599",
        "15633",
        "13771",
        "13336",
        "8307",
        "8269",
        "7686",
        "6320",
        "3801",
        "2259",
    },
    "Origen": {
        "49051",
        "36814",
        "12916",
        "6637",
        "4799",
        "2435",
    },
    "Herod Antipas": {
        "49865",
        "36902",
        "25202",
        "21368",
        "32682",
        "14935",
        "9337",
        "3512",
    },
    "Virgin Birth": {
        "39748",
        "37557",
        "27626",
        "27450",
        "17247",
        "15780",
        "12970",
        "12270",
        "7466",
        "4403",
        "3648",
        "1970",
    },
}

ORIGEN_RENAMED_ASSIGNMENTS = {
    "49778",
    "49016",
    "46984",
    "46936",
    "47141",
    "40170",
    "37841",
    "34145",
    "32072",
    "31840",
    "30070",
    "15755",
    "27766",
    "25226",
    "17793",
    "16580",
    "15991",
    "15818",
    "15763",
    "15752",
    "15748",
    "15635",
    "8304",
    "7648",
    "4513",
}

REMOVAL_REASONS = {
    "Gospel of Nicodemus": (
        "The text appears only as a comparison, list entry, syllabus item, or "
        "passing example rather than as a meaningful subject or source."
    ),
    "Origen": (
        "Origen appears only in a list of apologists, sources, readings, or "
        "canon authorities rather than as a meaningfully discussed figure."
    ),
    "Herod Antipas": (
        "The reference is only a date formula, passing historical comparison, "
        "list entry, or generic reference to Herod."
    ),
    "Virgin Birth": (
        "The virgin birth appears only as one item in a list of doctrines, "
        "supernatural claims, or former beliefs."
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
    if counts_before.get("Origen of Alexandria", 0):
        raise ValueError("Origen of Alexandria already exists before normalization")

    posts_by_id = {str(post["wpId"]): post for post in posts}
    affected_ids = set().union(*REMOVALS.values(), ORIGEN_RENAMED_ASSIGNMENTS)
    missing_ids = sorted(affected_ids - posts_by_id.keys())
    if missing_ids:
        raise ValueError(f"Post IDs missing from search index: {missing_ids}")

    for keyword, post_ids in REMOVALS.items():
        for post_id in post_ids:
            keywords = posts_by_id[post_id].get("secondaryKeywords", [])
            if keyword not in keywords:
                raise ValueError(f"{post_id} does not contain keyword {keyword!r}")
    for post_id in ORIGEN_RENAMED_ASSIGNMENTS:
        keywords = posts_by_id[post_id].get("secondaryKeywords", [])
        if "Origen" not in keywords:
            raise ValueError(f"{post_id} does not contain keyword 'Origen'")

    audited_origen_ids = REMOVALS["Origen"] | ORIGEN_RENAMED_ASSIGNMENTS
    if len(audited_origen_ids) != EXPECTED_COUNTS["Origen"]:
        raise ValueError("The Origen audit does not cover every current assignment")

    removed_posts: list[dict[str, str]] = []
    renamed_posts: list[dict[str, str]] = []
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
            if keyword == "Origen" and post_id in ORIGEN_RENAMED_ASSIGNMENTS:
                updated_keywords.append("Origen of Alexandria")
                renamed_posts.append(
                    {
                        "wpId": post_id,
                        "title": str(post["title"]),
                        "from": "Origen",
                        "to": "Origen of Alexandria",
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
    if len(renamed_posts) != len(ORIGEN_RENAMED_ASSIGNMENTS):
        raise ValueError(
            "Expected "
            f"{len(ORIGEN_RENAMED_ASSIGNMENTS)} Origen renames; "
            f"applied {len(renamed_posts)}"
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
        "Gospel of Nicodemus": 15,
        "Origen": 0,
        "Origen of Alexandria": 25,
        "Atonement": 30,
        "Herod Antipas": 22,
        "Virgin Birth": 18,
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
            "Gospel of Nicodemus, Origen, Atonement, Herod Antipas, and "
            "Virgin Birth secondary-keyword assignments"
        ),
        "criterion": (
            "Retain meaningful supporting people, texts, traditions, and "
            "theological concepts; remove passing comparisons, lists, syllabus "
            "entries, date formulas, and incidental doctrinal examples; and "
            "use a complete, unambiguous name for Origen of Alexandria."
        ),
        "originalKeywords": list(EXPECTED_COUNTS),
        "auditedKeywords": [
            "Gospel of Nicodemus",
            "Origen of Alexandria",
            "Atonement",
            "Herod Antipas",
            "Virgin Birth",
        ],
        "summary": {
            "keywordsAudited": len(EXPECTED_COUNTS),
            "linksReviewed": sum(EXPECTED_COUNTS.values()),
            "assignmentsRemoved": len(removed_posts),
            "assignmentsRenamed": len(renamed_posts),
            "uniqueKeywordsBefore": len(counts_before),
            "uniqueKeywordsAfter": len(counts_after),
            "keywordsRetired": 1,
        },
        "retiredKeywords": ["Origen"],
        "labelChanges": [
            {"from": "Origen", "to": "Origen of Alexandria"},
        ],
        "resultingCounts": actual_result_counts,
        "removedPosts": sorted(
            removed_posts,
            key=lambda item: (item["keyword"].casefold(), item["title"].casefold()),
        ),
        "renamedPosts": sorted(
            renamed_posts,
            key=lambda item: item["title"].casefold(),
        ),
    }

    write_json(POSTS_PATH, posts)
    write_json(AUDIT_PATH, audit)
    print(json.dumps(audit["summary"], indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
