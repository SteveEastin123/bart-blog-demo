"""Apply the approved re-audit of all current ten-post secondary keywords."""

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
    / "ten_post_secondary_keyword_reaudit_2026_08_18_secondary_keyword_audit.json"
)

EXPECTED_UNIQUE_KEYWORDS = 913
EXPECTED_TEN_POST_KEYWORDS = {
    "Apollos",
    "Bloody Sweat",
    "Historical Method",
    "History",
    "James, Son of Zebedee",
    "Loeb Classical Library",
    "Martyrdom of Polycarp",
    "Monotheism",
    "Pericope Adulterae",
    "Salome",
    "Trinity",
}

REMOVALS = {
    "Apollos": {"46933", "27410", "26839", "15482", "12120"},
    "Bloody Sweat": {
        "9070",
        "9068",
        "9063",
        "9061",
        "8085",
        "8065",
        "2759",
        "2727",
        "2721",
        "2699",
    },
    "Historical Method": {
        "19768",
        "7097",
        "7081",
        "7046",
        "7031",
        "7026",
        "6997",
        "6982",
        "6967",
        "3411",
    },
    "History": {
        "12610",
        "12605",
        "12603",
        "12601",
        "12587",
        "12584",
        "12529",
        "11893",
        "8760",
        "2852",
    },
    "James, Son of Zebedee": {"48814", "16338", "12006", "10649"},
    "Martyrdom of Polycarp": {"33915", "3161"},
    "Monotheism": {"15987"},
    "Salome": {
        "47109",
        "38872",
        "38868",
        "34961",
        "32498",
        "31948",
        "22094",
        "13506",
        "6967",
        "4018",
    },
    "Trinity": {"23213", "12408", "10268"},
}

ADDITIONS = {"Salome, Disciple of Jesus": {"38868"}}

REMOVAL_REASONS = {
    "Apollos": (
        "Apollos is only a proposed author, a name in a faction list, or a "
        "brief comparison supporting a discussion centered on someone else."
    ),
    "Bloody Sweat": (
        "The post already has the more precise topic Bloody Sweat Textual "
        "Variant, which remains discoverable from the shorter search phrase."
    ),
    "Historical Method": (
        "The generic label is redundant with Historical Methods (General) or "
        "less useful than the post's more precise method labels."
    ),
    "History": (
        "The label is too broad to improve retrieval; the post already has "
        "more precise history, historicity, memory, myth, or theology labels."
    ),
    "James, Son of Zebedee": (
        "James is mentioned only to distinguish people with the same name or "
        "as a brief example in a broader survey."
    ),
    "Martyrdom of Polycarp": (
        "The post merely announces that a later post will discuss the text."
    ),
    "Monotheism": (
        "Monotheism appears only in a passing description of an explanation "
        "for Christian expansion that the post rejects."
    ),
    "Salome": (
        "The one substantive assignment is replaced by the clearer label "
        "Salome, Disciple of Jesus; elsewhere Salome is merely named in a "
        "quotation, list, or narrative summary."
    ),
    "Trinity": (
        "The doctrine appears only as an analogy, a passing endpoint of early "
        "Christology, or part of a church's proper name."
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

    actual_ten_post_keywords = {
        keyword for keyword, count in counts_before.items() if count == 10
    }
    if actual_ten_post_keywords != EXPECTED_TEN_POST_KEYWORDS:
        missing = sorted(
            EXPECTED_TEN_POST_KEYWORDS - actual_ten_post_keywords,
            key=str.casefold,
        )
        unexpected = sorted(
            actual_ten_post_keywords - EXPECTED_TEN_POST_KEYWORDS,
            key=str.casefold,
        )
        raise ValueError(
            "Ten-post keyword set changed before cleanup; "
            f"missing={missing}, unexpected={unexpected}"
        )

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
                        "reason": REMOVAL_REASONS[keyword],
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

        seen: set[str] = set()
        deduplicated: list[str] = []
        for keyword in updated_keywords:
            key = normalize(keyword)
            if key not in seen:
                seen.add(key)
                deduplicated.append(keyword)
        post["secondaryKeywords"] = deduplicated

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
    expected_unique_after = EXPECTED_UNIQUE_KEYWORDS - 3
    if len(counts_after) != expected_unique_after:
        raise ValueError(
            f"Expected {expected_unique_after} unique keywords after cleanup; "
            f"found {len(counts_after)}"
        )

    expected_result_counts = {
        "Apollos": 5,
        "Bloody Sweat": 0,
        "Historical Method": 0,
        "History": 0,
        "James, Son of Zebedee": 6,
        "Loeb Classical Library": 10,
        "Martyrdom of Polycarp": 8,
        "Monotheism": 9,
        "Pericope Adulterae": 10,
        "Salome": 0,
        "Salome, Disciple of Jesus": 1,
        "Trinity": 7,
    }
    actual_result_counts = {
        keyword: counts_after.get(keyword, 0) for keyword in expected_result_counts
    }
    if actual_result_counts != expected_result_counts:
        raise ValueError(f"Unexpected resulting counts: {actual_result_counts}")

    surviving_originals = {
        keyword
        for keyword in EXPECTED_TEN_POST_KEYWORDS
        if keyword in counts_after
    }
    audited_keywords = sorted(
        surviving_originals.union(ADDITIONS),
        key=str.casefold,
    )
    audit = {
        "auditDate": "2026-08-18",
        "scope": (
            "Every secondary keyword assigned to exactly ten posts before "
            "this re-audit"
        ),
        "criterion": (
            "Retain meaningful supporting subjects, people, texts, places, or "
            "concepts; remove passing mentions, lists, generic labels, and "
            "surrounding context; use precise labels; and avoid duplicating a "
            "post's topic unless an alternate search term is genuinely useful."
        ),
        "originalKeywords": sorted(
            EXPECTED_TEN_POST_KEYWORDS,
            key=str.casefold,
        ),
        "auditedKeywords": audited_keywords,
        "summary": {
            "keywordsAudited": len(EXPECTED_TEN_POST_KEYWORDS),
            "linksReviewed": len(EXPECTED_TEN_POST_KEYWORDS) * 10,
            "assignmentsRemoved": len(removed_posts),
            "assignmentsAdded": len(added_posts),
            "netAssignmentsRemoved": len(removed_posts) - len(added_posts),
            "uniqueKeywordsBefore": len(counts_before),
            "uniqueKeywordsAfter": len(counts_after),
            "keywordsRetired": 4,
            "labelsNormalizedOrRefined": 1,
        },
        "retiredKeywords": [
            "Bloody Sweat",
            "Historical Method",
            "History",
            "Salome",
        ],
        "labelChanges": [
            {"from": "Salome", "to": "Salome, Disciple of Jesus"}
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
