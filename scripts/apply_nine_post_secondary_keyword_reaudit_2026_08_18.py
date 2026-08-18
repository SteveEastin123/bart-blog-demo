"""Apply the approved re-audit of all current nine-post secondary keywords."""

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
    / "nine_post_secondary_keyword_reaudit_2026_08_18_secondary_keyword_audit.json"
)

EXPECTED_UNIQUE_KEYWORDS = 913
EXPECTED_NINE_POST_KEYWORDS = {
    "2 Thessalonians",
    "Anti-Judaism",
    "Book Publishing",
    "Coptic Apocalypse of Peter",
    "Docetism",
    "Ezekiel",
    "Gamaliel",
    "Justin Martyr",
    "Letter to the Hebrews",
    "Martin Luther",
    "Micah",
    "Orthodoxy",
    "Philip",
    "Quran",
    "Redaction Criticism",
    "Resurrection Appearances",
    "Ruth",
}

REMOVALS = {
    "2 Thessalonians": {"47267", "38583", "17064", "8350"},
    "Anti-Judaism": {
        "46926",
        "27166",
        "26895",
        "17537",
        "9428",
        "7654",
        "7373",
        "4163",
        "3555",
    },
    "Book Publishing": {"11429"},
    "Coptic Apocalypse of Peter": {"8405", "2268", "2259"},
    "Docetism": {"50331", "14395", "9211"},
    "Ezekiel": {"47640", "34088", "9477"},
    "Gamaliel": {"36333"},
    "Letter to the Hebrews": {
        "50192",
        "49360",
        "40007",
        "38008",
        "33135",
        "32065",
        "12642",
        "12143",
        "2240",
    },
    "Martin Luther": {"39623", "15505", "3411", "2844"},
    "Philip": {
        "38493",
        "37384",
        "37380",
        "23254",
        "21061",
        "17615",
        "14899",
        "8846",
        "6891",
    },
    "Redaction Criticism": {"6951"},
    "Resurrection Appearances": {
        "37577",
        "35786",
        "35338",
        "32529",
        "28740",
        "15528",
        "8946",
        "7254",
        "4317",
    },
    "Ruth": {"48687"},
}

ADDITIONS = {
    "Christian Anti-Judaism": {"46926", "26895", "9428", "7373", "3555"},
    "Hebrews": {
        "50192",
        "49360",
        "40007",
        "38008",
        "33135",
        "32065",
        "12642",
        "12143",
        "2240",
    },
    "Gospel of Philip": {
        "38493",
        "37384",
        "37380",
        "23254",
        "17615",
        "14899",
        "6891",
    },
    "Philip the Apostle": {"8846"},
    "Jesus' Resurrection Appearances": {
        "37577",
        "35786",
        "35338",
        "15528",
        "8946",
        "7254",
        "4317",
    },
}

REMOVAL_REASONS = {
    "2 Thessalonians": (
        "The references occur only in a bibliography, broad list, explicit "
        "exclusion from the discussion, or transition to another subject."
    ),
    "Anti-Judaism": (
        "The old label is either syllabus-only, redundant with the post's "
        "Christian Anti-Judaism topic, or replaced by that more precise label."
    ),
    "Book Publishing": (
        "The post concerns research and writing progress rather than publishing."
    ),
    "Coptic Apocalypse of Peter": (
        "The text appears only in a syllabus or seminar list."
    ),
    "Docetism": (
        "The post concerns separationism or adoptionism, with Docetism absent "
        "or used only as contrasting background."
    ),
    "Ezekiel": (
        "Ezekiel appears only in a brief comparison or general list of prophets."
    ),
    "Gamaliel": (
        "Gamaliel appears only in a name discussion and brief Acts quotation."
    ),
    "Letter to the Hebrews": (
        "The assignment is replaced by the concise label Hebrews, matching the "
        "existing topic."
    ),
    "Martin Luther": (
        "The reference is a broad comparison or identifies Martin Luther King "
        "Jr. rather than Martin Luther."
    ),
    "Philip": (
        "The ambiguous label is replaced by Gospel of Philip or Philip the "
        "Apostle where supported, and otherwise removed."
    ),
    "Redaction Criticism": (
        "The post merely mentions redaction criticism before using a different "
        "method."
    ),
    "Resurrection Appearances": (
        "The label is replaced by Jesus' Resurrection Appearances where "
        "supported, removed when incidental, and omitted when the matching "
        "topic is already present."
    ),
    "Ruth": "Ruth appears only in charts listing biblical books.",
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

    actual_nine_post_keywords = {
        keyword for keyword, count in counts_before.items() if count == 9
    }
    if actual_nine_post_keywords != EXPECTED_NINE_POST_KEYWORDS:
        missing = sorted(
            EXPECTED_NINE_POST_KEYWORDS - actual_nine_post_keywords,
            key=str.casefold,
        )
        unexpected = sorted(
            actual_nine_post_keywords - EXPECTED_NINE_POST_KEYWORDS,
            key=str.casefold,
        )
        raise ValueError(
            "Nine-post keyword set changed before cleanup; "
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
        "2 Thessalonians": 5,
        "Anti-Judaism": 0,
        "Christian Anti-Judaism": 5,
        "Book Publishing": 8,
        "Coptic Apocalypse of Peter": 6,
        "Docetism": 6,
        "Ezekiel": 6,
        "Gamaliel": 8,
        "Justin Martyr": 9,
        "Letter to the Hebrews": 0,
        "Hebrews": 9,
        "Martin Luther": 5,
        "Micah": 9,
        "Orthodoxy": 9,
        "Philip": 0,
        "Gospel of Philip": 8,
        "Philip the Apostle": 1,
        "Quran": 9,
        "Redaction Criticism": 8,
        "Resurrection Appearances": 0,
        "Jesus' Resurrection Appearances": 7,
        "Ruth": 8,
    }
    actual_result_counts = {
        keyword: counts_after.get(keyword, 0) for keyword in expected_result_counts
    }
    if actual_result_counts != expected_result_counts:
        raise ValueError(f"Unexpected resulting counts: {actual_result_counts}")

    surviving_originals = {
        keyword
        for keyword in EXPECTED_NINE_POST_KEYWORDS
        if keyword in counts_after
    }
    audited_keywords = sorted(
        surviving_originals.union(ADDITIONS),
        key=str.casefold,
    )
    audit = {
        "auditDate": "2026-08-18",
        "scope": (
            "Every secondary keyword assigned to exactly nine posts before "
            "this re-audit"
        ),
        "criterion": (
            "Retain meaningful supporting subjects, people, texts, places, or "
            "concepts; remove passing mentions, lists, event logistics, "
            "bibliographic references, and surrounding context; use precise "
            "labels; and avoid duplicating a post's topic."
        ),
        "originalKeywords": sorted(
            EXPECTED_NINE_POST_KEYWORDS,
            key=str.casefold,
        ),
        "auditedKeywords": audited_keywords,
        "summary": {
            "keywordsAudited": len(EXPECTED_NINE_POST_KEYWORDS),
            "linksReviewed": len(EXPECTED_NINE_POST_KEYWORDS) * 9,
            "assignmentsRemoved": len(removed_posts),
            "assignmentsAdded": len(added_posts),
            "netAssignmentsRemoved": len(removed_posts) - len(added_posts),
            "uniqueKeywordsBefore": len(counts_before),
            "uniqueKeywordsAfter": len(counts_after),
            "keywordsRetired": 4,
            "labelsNormalizedOrRefined": 4,
        },
        "retiredKeywords": [
            "Anti-Judaism",
            "Letter to the Hebrews",
            "Philip",
            "Resurrection Appearances",
        ],
        "labelChanges": [
            {"from": "Anti-Judaism", "to": "Christian Anti-Judaism"},
            {"from": "Letter to the Hebrews", "to": "Hebrews"},
            {
                "from": "Philip",
                "to": ["Gospel of Philip", "Philip the Apostle"],
            },
            {
                "from": "Resurrection Appearances",
                "to": "Jesus' Resurrection Appearances",
            },
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
