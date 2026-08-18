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
    / "jairus_reza_aslan_scribal_practices_barnabas_forgiveness_"
    "secondary_keyword_audit.json"
)

EXPECTED_UNIQUE_KEYWORDS = 909
EXPECTED_COUNTS = {
    "Jairus": 28,
    "Reza Aslan": 28,
    "Scribal Practices": 28,
    "Barnabas": 27,
    "Forgiveness": 27,
}
EXPECTED_TARGET_COUNTS = {
    "Letter of Barnabas": 13,
    "Barnabas, Associate of Paul": 22,
}

REMOVALS = {
    "Jairus": {
        "49622",
        "36424",
        "15315",
        "7556",
        "5075",
    },
    "Reza Aslan": {
        "38778",
        "30471",
        "15087",
        "7214",
        "6444",
        "6925",
        "4900",
    },
    "Scribal Practices": {
        "9086",
        "9075",
    },
    "Barnabas": {
        "46926",
        "40215",
        "32502",
        "8405",
        "8294",
        "6578",
        "4747",
    },
    "Forgiveness": {
        "49865",
        "34088",
        "29776",
        "16146",
    },
}

LETTER_OF_BARNABAS_IDS = {
    "48800",
    "40170",
    "40016",
    "39587",
    "38938",
    "34145",
    "15991",
    "14980",
    "14874",
    "13346",
    "12253",
    "8508",
    "7648",
    "3299",
    "3205",
    "3199",
}

BARNABAS_ASSOCIATE_IDS = {
    "46933",
    "31948",
    "26835",
    "26811",
}

REMOVAL_REASONS = {
    "Jairus": (
        "Jairus or his daughter appears only as a brief example rather than "
        "as a meaningfully discussed narrative or Gospel comparison."
    ),
    "Reza Aslan": (
        "Aslan appears only as a comparison, transition, or future reading "
        "rather than as a meaningfully discussed author or argument."
    ),
    "Scribal Practices": (
        "The post concerns original-text questions or reactions among textual "
        "critics and mentions scribes only incidentally."
    ),
    "Barnabas": (
        "The name denotes a different modern scholar or appears only in a "
        "list, syllabus, or passing authorship comparison."
    ),
    "Forgiveness": (
        "Forgiveness appears only as a rhetorical example, isolated quotation, "
        "negative contrast, or passing theological reference."
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
    actual_target_counts = {
        keyword: counts_before.get(keyword, 0)
        for keyword in EXPECTED_TARGET_COUNTS
    }
    if actual_target_counts != EXPECTED_TARGET_COUNTS:
        raise ValueError(
            f"Unexpected Barnabas target counts: {actual_target_counts}"
        )

    barnabas_audit_ids = (
        REMOVALS["Barnabas"]
        | LETTER_OF_BARNABAS_IDS
        | BARNABAS_ASSOCIATE_IDS
    )
    if len(barnabas_audit_ids) != EXPECTED_COUNTS["Barnabas"]:
        raise ValueError("The Barnabas audit does not cover every assignment")

    posts_by_id = {str(post["wpId"]): post for post in posts}
    affected_ids = set().union(
        *REMOVALS.values(),
        LETTER_OF_BARNABAS_IDS,
        BARNABAS_ASSOCIATE_IDS,
    )
    missing_ids = sorted(affected_ids - posts_by_id.keys())
    if missing_ids:
        raise ValueError(f"Post IDs missing from search index: {missing_ids}")

    for keyword, post_ids in REMOVALS.items():
        for post_id in post_ids:
            keywords = posts_by_id[post_id].get("secondaryKeywords", [])
            if keyword not in keywords:
                raise ValueError(f"{post_id} does not contain keyword {keyword!r}")
    for post_id in LETTER_OF_BARNABAS_IDS | BARNABAS_ASSOCIATE_IDS:
        keywords = posts_by_id[post_id].get("secondaryKeywords", [])
        if "Barnabas" not in keywords:
            raise ValueError(f"{post_id} does not contain keyword 'Barnabas'")

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
            replacement = None
            if keyword == "Barnabas" and post_id in LETTER_OF_BARNABAS_IDS:
                replacement = "Letter of Barnabas"
            elif keyword == "Barnabas" and post_id in BARNABAS_ASSOCIATE_IDS:
                replacement = "Barnabas, Associate of Paul"
            if replacement:
                updated_keywords.append(replacement)
                renamed_posts.append(
                    {
                        "wpId": post_id,
                        "title": str(post["title"]),
                        "from": keyword,
                        "to": replacement,
                    }
                )
                continue
            updated_keywords.append(keyword)
        post["secondaryKeywords"] = updated_keywords

    expected_removals = sum(len(post_ids) for post_ids in REMOVALS.values())
    expected_renames = len(LETTER_OF_BARNABAS_IDS | BARNABAS_ASSOCIATE_IDS)
    if len(removed_posts) != expected_removals:
        raise ValueError(
            f"Expected {expected_removals} removals; applied {len(removed_posts)}"
        )
    if len(renamed_posts) != expected_renames:
        raise ValueError(
            f"Expected {expected_renames} renames; applied {len(renamed_posts)}"
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
            f"Expected {EXPECTED_UNIQUE_KEYWORDS - 1} unique keywords after cleanup; "
            f"found {len(counts_after)}"
        )

    expected_result_counts = {
        "Jairus": 23,
        "Reza Aslan": 21,
        "Scribal Practices": 26,
        "Barnabas": 0,
        "Letter of Barnabas": 29,
        "Barnabas, Associate of Paul": 26,
        "Forgiveness": 23,
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
            "Jairus, Reza Aslan, Scribal Practices, Barnabas, and Forgiveness "
            "secondary-keyword assignments"
        ),
        "criterion": (
            "Retain meaningful supporting people, narratives, textual activity, "
            "and theological concepts; remove brief examples, transitions, "
            "lists, syllabi, and passing references; and disambiguate Barnabas "
            "the associate of Paul from the Letter of Barnabas."
        ),
        "originalKeywords": list(EXPECTED_COUNTS),
        "auditedKeywords": [
            "Jairus",
            "Reza Aslan",
            "Scribal Practices",
            "Letter of Barnabas",
            "Barnabas, Associate of Paul",
            "Forgiveness",
        ],
        "summary": {
            "keywordsAudited": len(EXPECTED_COUNTS),
            "linksReviewed": sum(EXPECTED_COUNTS.values()),
            "assignmentsRetained": sum(EXPECTED_COUNTS.values())
            - len(removed_posts),
            "assignmentsRemoved": len(removed_posts),
            "assignmentsRenamed": len(renamed_posts),
            "uniqueKeywordsBefore": len(counts_before),
            "uniqueKeywordsAfter": len(counts_after),
            "keywordsRetired": 1,
        },
        "retiredKeywords": ["Barnabas"],
        "labelChanges": [
            {"from": "Barnabas", "to": "Letter of Barnabas"},
            {"from": "Barnabas", "to": "Barnabas, Associate of Paul"},
        ],
        "resultingCounts": actual_result_counts,
        "removedPosts": sorted(
            removed_posts,
            key=lambda item: (item["keyword"].casefold(), item["title"].casefold()),
        ),
        "renamedPosts": sorted(
            renamed_posts,
            key=lambda item: (item["to"].casefold(), item["title"].casefold()),
        ),
    }

    write_json(POSTS_PATH, posts)
    write_json(AUDIT_PATH, audit)
    print(json.dumps(audit["summary"], indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
