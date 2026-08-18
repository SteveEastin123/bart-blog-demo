"""Apply the approved re-audit of all current seven-post secondary keywords."""

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
    / "seven_post_secondary_keyword_reaudit_2026_08_18_secondary_keyword_audit.json"
)

EXPECTED_UNIQUE_KEYWORDS_BEFORE = 916
EXPECTED_UNIQUE_KEYWORDS_AFTER = 913

EXPECTED_SEVEN_POST_KEYWORDS = {
    "1 Kings",
    "2 John",
    "Ananias",
    "Apocalypse",
    "Cain",
    "Death",
    "Death of Jesus",
    "Faith",
    "Gospel of Jesus' Wife",
    "Greater Questions of Mary",
    "Judas",
    "Julian",
    "King Abgar",
    "Live Q&A",
    "Love Thy Stranger",
    "Mark Goodacre",
    "Mary of Bethany",
    "Osiris",
    "Papyrus Egerton",
    "PhD",
    "SBL",
    "Smithsonian",
    "Tamar",
}

REMOVALS = {
    "2 John": {"20122", "17095"},
    "Ananias": {
        "38713",
        "38524",
        "36661",
        "35872",
        "11558",
        "10885",
        "2492",
    },
    "Apocalypse": {"11529"},
    "Cain": {"47587", "27512"},
    "Death": {"6967"},
    "Faith": {"24783"},
    "Gospel of Jesus' Wife": {
        "41678",
        "40302",
        "20540",
        "8232",
        "3222",
    },
    "Judas": {"12656", "20531", "12025", "8823", "4265", "4259", "4255"},
    "Julian": {"49780", "32805", "20918", "15125", "15123", "15119", "15115"},
    "King Abgar": {"25436", "15680", "15674"},
    "Live Q&A": {"47109", "40626", "40115", "39917", "38587", "37283", "36799"},
    "Mary of Bethany": {"17579", "6753"},
    "Papyrus Egerton": {"36957", "36803", "21145", "16606", "4858", "4851", "4792"},
    "SBL": {"8242", "6341", "6332", "6299", "3381", "3376", "3356"},
    "Smithsonian": {"12107"},
    "Tamar": {"39869", "16146"},
}

ADDITIONS = {
    "Ananias of Damascus": {"38713", "36661", "35872", "11558", "10885", "2492"},
    "Death of Jesus": {"6967"},
    "Julian the Apostate": {"49780", "32805", "20918", "15123", "15119"},
    "Society of Biblical Literature (SBL)": {"8242", "6341", "6332", "6299", "3356"},
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
    if len(counts_before) != EXPECTED_UNIQUE_KEYWORDS_BEFORE:
        raise ValueError(
            f"Expected {EXPECTED_UNIQUE_KEYWORDS_BEFORE} unique keywords before "
            f"cleanup; found {len(counts_before)}"
        )

    actual_seven_post_keywords = {
        keyword for keyword, count in counts_before.items() if count == 7
    }
    if actual_seven_post_keywords != EXPECTED_SEVEN_POST_KEYWORDS:
        missing = sorted(
            EXPECTED_SEVEN_POST_KEYWORDS - actual_seven_post_keywords,
            key=str.casefold,
        )
        unexpected = sorted(
            actual_seven_post_keywords - EXPECTED_SEVEN_POST_KEYWORDS,
            key=str.casefold,
        )
        raise ValueError(
            "Seven-post keyword set changed before cleanup; "
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
        original_keywords = list(post.get("secondaryKeywords", []))
        updated_keywords: list[str] = []
        for keyword in original_keywords:
            if post_id in REMOVALS.get(keyword, set()):
                removed_posts.append(
                    {
                        "wpId": post_id,
                        "title": str(post["title"]),
                        "keyword": keyword,
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
    if len(counts_after) != EXPECTED_UNIQUE_KEYWORDS_AFTER:
        raise ValueError(
            f"Expected {EXPECTED_UNIQUE_KEYWORDS_AFTER} unique keywords after "
            f"cleanup; found {len(counts_after)}"
        )

    expected_result_counts = {
        "1 Kings": 7,
        "2 John": 5,
        "Ananias": 0,
        "Ananias of Damascus": 6,
        "Apocalypse": 6,
        "Cain": 5,
        "Death": 6,
        "Death of Jesus": 8,
        "Faith": 6,
        "Gospel of Jesus' Wife": 2,
        "Greater Questions of Mary": 7,
        "Judas": 0,
        "Julian": 0,
        "Julian the Apostate": 5,
        "King Abgar": 4,
        "Live Q&A": 0,
        "Love Thy Stranger": 7,
        "Mark Goodacre": 7,
        "Mary of Bethany": 5,
        "Osiris": 7,
        "Papyrus Egerton": 0,
        "PhD": 7,
        "SBL": 0,
        "Society of Biblical Literature (SBL)": 5,
        "Smithsonian": 6,
        "Tamar": 5,
    }
    actual_result_counts = {
        keyword: counts_after.get(keyword, 0) for keyword in expected_result_counts
    }
    if actual_result_counts != expected_result_counts:
        raise ValueError(f"Unexpected resulting counts: {actual_result_counts}")

    surviving_originals = {
        keyword
        for keyword in EXPECTED_SEVEN_POST_KEYWORDS
        if keyword in counts_after
    }
    audited_keywords = sorted(
        surviving_originals.union(ADDITIONS),
        key=str.casefold,
    )
    links_before = sum(counts_before.values())
    links_after = sum(counts_after.values())
    audit = {
        "auditDate": "2026-08-18",
        "scope": (
            "Every secondary keyword assigned to exactly seven posts before "
            "this re-audit"
        ),
        "criterion": (
            "Retain meaningful supporting subjects, people, texts, places, or "
            "concepts; remove passing mentions, lists, event logistics, and "
            "surrounding context; use precise names; and avoid duplicating a "
            "post's topic as a secondary keyword."
        ),
        "originalKeywords": sorted(EXPECTED_SEVEN_POST_KEYWORDS, key=str.casefold),
        "auditedKeywords": audited_keywords,
        "summary": {
            "keywordsAudited": len(EXPECTED_SEVEN_POST_KEYWORDS),
            "linksReviewed": len(EXPECTED_SEVEN_POST_KEYWORDS) * 7,
            "assignmentsRemoved": len(removed_posts),
            "assignmentsAdded": len(added_posts),
            "netLinksRemoved": links_before - links_after,
            "uniqueKeywordsBefore": len(counts_before),
            "uniqueKeywordsAfter": len(counts_after),
            "keywordsRetired": 3,
            "labelsNormalized": 3,
        },
        "retiredKeywords": [
            {
                "keyword": "Judas",
                "reason": (
                    "All seven posts already have the more precise Judas "
                    "Iscariot topic."
                ),
            },
            {
                "keyword": "Live Q&A",
                "reason": (
                    "Assignments described event format or promotional logistics "
                    "rather than a substantive searchable subject."
                ),
            },
            {
                "keyword": "Papyrus Egerton",
                "reason": (
                    "Assignments were syllabus entries, lists, or transitional "
                    "references; substantive posts remain covered by the topic."
                ),
            },
        ],
        "normalizations": [
            {"from": "Ananias", "to": "Ananias of Damascus"},
            {"from": "Julian", "to": "Julian the Apostate"},
            {"from": "SBL", "to": "Society of Biblical Literature (SBL)"},
        ],
        "resultingCounts": actual_result_counts,
        "removedPosts": removed_posts,
        "addedPosts": added_posts,
    }

    write_json(POSTS_PATH, posts)
    write_json(AUDIT_PATH, audit)
    print(json.dumps(audit["summary"], indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
