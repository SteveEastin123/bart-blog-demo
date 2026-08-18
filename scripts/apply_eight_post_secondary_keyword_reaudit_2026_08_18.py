"""Apply the approved re-audit of all current eight-post secondary keywords."""

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
    / "eight_post_secondary_keyword_reaudit_2026_08_18_secondary_keyword_audit.json"
)

EXPECTED_UNIQUE_KEYWORDS = 913
EXPECTED_EIGHT_POST_KEYWORDS = {
    "Biblical Inerrancy",
    "Codex Sinaiticus",
    "Death of Jesus",
    "Gospel of Pseudo-Matthew",
    "Jesus' Miracle Stories",
    "Joseph in Genesis",
    "Moody Bible Institute",
    "Mythicism",
    "Princeton Theological Seminary",
    "Pseudepigraphy",
    "Ramsay MacMullen",
    "Richard Carrier",
    "Robert Price",
    "Secret Gospel of Mark",
}

REMOVALS = {
    "Gospel of Pseudo-Matthew": {"3415"},
    "Jesus' Miracle Stories": {"29838", "28977", "14876", "7311"},
    "Joseph in Genesis": {"28913", "28640", "6898"},
    "Mythicism": {"12268", "9131"},
    "Pseudepigraphy": {"8405"},
    "Ramsay MacMullen": {"49737", "4143"},
    "Secret Gospel of Mark": {"36803", "4792", "8748", "6388"},
}

REMOVAL_REASONS = {
    "Gospel of Pseudo-Matthew": (
        "The post only previews a later discussion of the text."
    ),
    "Jesus' Miracle Stories": (
        "The posts mention Jesus' miracles briefly to contrast Jesus with Paul, "
        "rather than discussing the miracle stories themselves."
    ),
    "Joseph in Genesis": (
        "The posts use Joseph only in course logistics or a passing list of "
        "Genesis figures."
    ),
    "Mythicism": (
        "Mythicism appears only in framing material or is absent from the "
        "substantive discussion."
    ),
    "Pseudepigraphy": (
        "The term appears only in a bibliographic syllabus entry."
    ),
    "Ramsay MacMullen": (
        "MacMullen receives only a brief attribution or book recommendation."
    ),
    "Secret Gospel of Mark": (
        "The references occur in a syllabus, event background, or an "
        "introductory mention rather than a meaningful discussion of the text."
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

    actual_eight_post_keywords = {
        keyword for keyword, count in counts_before.items() if count == 8
    }
    if actual_eight_post_keywords != EXPECTED_EIGHT_POST_KEYWORDS:
        missing = sorted(
            EXPECTED_EIGHT_POST_KEYWORDS - actual_eight_post_keywords,
            key=str.casefold,
        )
        unexpected = sorted(
            actual_eight_post_keywords - EXPECTED_EIGHT_POST_KEYWORDS,
            key=str.casefold,
        )
        raise ValueError(
            "Eight-post keyword set changed before cleanup; "
            f"missing={missing}, unexpected={unexpected}"
        )

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
        normalized_topics = {
            normalize(topic) for topic in post.get("topics", [])
        }
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
        "Biblical Inerrancy": 8,
        "Codex Sinaiticus": 8,
        "Death of Jesus": 8,
        "Gospel of Pseudo-Matthew": 7,
        "Jesus' Miracle Stories": 4,
        "Joseph in Genesis": 5,
        "Moody Bible Institute": 8,
        "Mythicism": 6,
        "Princeton Theological Seminary": 8,
        "Pseudepigraphy": 7,
        "Ramsay MacMullen": 6,
        "Richard Carrier": 8,
        "Robert Price": 8,
        "Secret Gospel of Mark": 4,
    }
    actual_result_counts = {
        keyword: counts_after.get(keyword, 0) for keyword in expected_result_counts
    }
    if actual_result_counts != expected_result_counts:
        raise ValueError(f"Unexpected resulting counts: {actual_result_counts}")

    audit = {
        "auditDate": "2026-08-18",
        "scope": (
            "Every secondary keyword assigned to exactly eight posts before "
            "this re-audit"
        ),
        "criterion": (
            "Retain meaningful supporting subjects, people, texts, places, or "
            "concepts; remove passing mentions, lists, event logistics, "
            "bibliographic references, and surrounding context; and avoid "
            "duplicating a post's topic as a secondary keyword."
        ),
        "originalKeywords": sorted(
            EXPECTED_EIGHT_POST_KEYWORDS,
            key=str.casefold,
        ),
        "auditedKeywords": sorted(
            EXPECTED_EIGHT_POST_KEYWORDS,
            key=str.casefold,
        ),
        "summary": {
            "keywordsAudited": len(EXPECTED_EIGHT_POST_KEYWORDS),
            "linksReviewed": len(EXPECTED_EIGHT_POST_KEYWORDS) * 8,
            "assignmentsRetained": (
                len(EXPECTED_EIGHT_POST_KEYWORDS) * 8 - len(removed_posts)
            ),
            "assignmentsRemoved": len(removed_posts),
            "uniqueKeywordsBefore": len(counts_before),
            "uniqueKeywordsAfter": len(counts_after),
            "keywordsRetired": 0,
            "labelsNormalized": 0,
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
