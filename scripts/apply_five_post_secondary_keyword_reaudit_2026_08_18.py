#!/usr/bin/env python3
"""Apply the approved full-text re-audit of current five-post keywords."""

from __future__ import annotations

import json
from collections import Counter
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = (
    ROOT
    / "data"
    / "audits"
    / "five_post_secondary_keyword_reaudit_2026_08_18_secondary_keyword_audit.json"
)

EXPECTED_FIVE_POST_KEYWORDS = {
    "1 Samuel",
    "Abel",
    "Agnosticism",
    "Bill O'Reilly",
    "Cephas",
    "Consciousness",
    "Da Vinci Code",
    "Desiderius Erasmus",
    "Evangelicalism",
    "Fresh Air",
    "Gospel Quotations",
    "Group Visions",
    "Hallucinations",
    "Healing",
    "Herod Agrippa I",
    "Islam",
    "Jerusalem Temple",
    "Jewish Christianity",
    "Larry Hurtado",
    "Logos",
    "Maccabees",
    "Marriage",
    "Memory Studies",
    "Muhammad",
    "Newsweek",
    "Orthodox Corruption of Scripture",
    "Papyri",
    "Passover",
    "Psalms",
    "Pseudepigrapha",
    "Sacrifice",
    "Sethian Gnostics",
    "Sheol",
    "Stephen",
    "Synoptic Gospels",
    "Thomas the Apostle",
}
EXPECTED_UNIQUE_KEYWORDS_BEFORE = 919
EXPECTED_UNIQUE_KEYWORDS_AFTER = 918

REMOVALS = {
    "Abel": {
        "20971": (
            "Abel is mentioned only as Cain's victim while the post's sustained "
            "subject is Cainite theology and the Gospel of Judas."
        ),
        "8466": (
            "Abel is mentioned only as Cain's victim while the post explains "
            "Cainite reverence for Cain and the Gospel of Judas."
        ),
        "8462": (
            "Abel is mentioned only as Cain's victim in background about the "
            "Cainites and the Gospel of Judas."
        ),
        "3222": (
            "Abel is mentioned only to identify Cain as a murderer in a post "
            "about lost Gospels and the Gospel of Judas."
        ),
    },
    "Herod Agrippa I": {
        "37901": (
            "Herod Agrippa appears only in a single example of angelic violence "
            "in Acts."
        ),
        "35786": (
            "Herod appears only in a parenthetical identification of the ruler "
            "who killed James."
        ),
        "35338": (
            "Herod appears only in a parenthetical identification of the ruler "
            "who killed James."
        ),
        "34597": (
            "Herod Agrippa appears only in the brief biblical example of James's "
            "death."
        ),
        "9393": (
            "Herod appears only in a brief reference to the unexplained death of "
            "James."
        ),
    },
    "Psalms": {
        "9516": (
            "Psalm 22 appears only as the source of one saying in a discussion "
            "about Aramaic words in Mark."
        ),
    },
    "Stephen": {
        "33985": (
            "Stephen appears only in a passing comparison used to introduce the "
            "Martyrdom of Polycarp."
        ),
        "33974": (
            "Stephen appears only as a brief comparison establishing the "
            "Martyrdom of Polycarp as the earliest full martyr narrative outside "
            "the New Testament."
        ),
    },
}


def load_json(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value) -> None:
    path.write_text(
        json.dumps(value, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
        newline="\n",
    )


def keyword_post_ids(posts: list[dict], keyword: str) -> set[str]:
    return {
        str(post["wpId"])
        for post in posts
        if keyword in post.get("secondaryKeywords", [])
    }


def remove_keyword(post: dict, keyword: str) -> None:
    post["secondaryKeywords"] = [
        value for value in post.get("secondaryKeywords", []) if value != keyword
    ]


def main() -> int:
    posts = load_json(POSTS_PATH)
    by_id = {str(post["wpId"]): post for post in posts}
    counts_before = Counter(
        keyword
        for post in posts
        for keyword in post.get("secondaryKeywords", [])
    )
    original_keywords = {
        keyword for keyword, count in counts_before.items() if count == 5
    }
    if original_keywords != EXPECTED_FIVE_POST_KEYWORDS:
        missing = sorted(EXPECTED_FIVE_POST_KEYWORDS - original_keywords, key=str.casefold)
        unexpected = sorted(original_keywords - EXPECTED_FIVE_POST_KEYWORDS, key=str.casefold)
        raise ValueError(
            "Unexpected five-post keyword set; "
            f"missing={missing}, unexpected={unexpected}"
        )
    if len(counts_before) != EXPECTED_UNIQUE_KEYWORDS_BEFORE:
        raise ValueError(
            f"Expected {EXPECTED_UNIQUE_KEYWORDS_BEFORE} unique keywords; "
            f"found {len(counts_before)}"
        )

    for keyword, removals in REMOVALS.items():
        actual_ids = keyword_post_ids(posts, keyword)
        if len(actual_ids) != 5 or not set(removals).issubset(actual_ids):
            raise ValueError(
                f"Unexpected assignments for {keyword!r}: {sorted(actual_ids)}"
            )

    removed_posts = []
    for keyword, removals in REMOVALS.items():
        for wp_id, reason in removals.items():
            post = by_id[wp_id]
            if keyword not in post.get("secondaryKeywords", []):
                raise ValueError(f"Missing {keyword!r} on post {wp_id}")
            remove_keyword(post, keyword)
            post["secondaryKeywords"] = sorted(
                post.get("secondaryKeywords", []), key=str.casefold
            )
            removed_posts.append(
                {
                    "keyword": keyword,
                    "wpId": wp_id,
                    "title": post["title"],
                    "reason": reason,
                }
            )

    duplicate_keywords = [
        str(post["wpId"])
        for post in posts
        if len(post.get("secondaryKeywords", []))
        != len(
            {
                value.casefold().strip()
                for value in post.get("secondaryKeywords", [])
            }
        )
    ]
    topic_keyword_overlaps = [
        str(post["wpId"])
        for post in posts
        if {value.casefold().strip() for value in post.get("topics", [])}
        & {
            value.casefold().strip()
            for value in post.get("secondaryKeywords", [])
        }
    ]
    if duplicate_keywords or topic_keyword_overlaps:
        raise ValueError(
            "Validation failed: duplicate keywords "
            f"{duplicate_keywords[:5]}, topic/keyword overlaps "
            f"{topic_keyword_overlaps[:5]}"
        )

    counts_after = Counter(
        keyword
        for post in posts
        for keyword in post.get("secondaryKeywords", [])
    )
    if len(counts_after) != EXPECTED_UNIQUE_KEYWORDS_AFTER:
        raise ValueError(
            f"Expected {EXPECTED_UNIQUE_KEYWORDS_AFTER} unique keywords after "
            f"cleanup; found {len(counts_after)}"
        )
    expected_result_counts = {
        "Abel": 1,
        "Herod Agrippa I": 0,
        "Psalms": 4,
        "Stephen": 3,
    }
    actual_result_counts = {
        keyword: counts_after.get(keyword, 0) for keyword in expected_result_counts
    }
    if actual_result_counts != expected_result_counts:
        raise ValueError(
            f"Unexpected resulting counts: {actual_result_counts}"
        )

    links_before = sum(counts_before.values())
    links_after = sum(counts_after.values())
    retained_keywords = sorted(
        (keyword for keyword in original_keywords if keyword in counts_after),
        key=str.casefold,
    )
    audit = {
        "auditDate": "2026-08-18",
        "scope": (
            "Every secondary keyword assigned to exactly five posts before this "
            "re-audit"
        ),
        "criterion": (
            "Retain meaningful supporting subjects, people, texts, places, or "
            "concepts and remove assignments based only on passing mentions, "
            "brief comparisons, or incidental examples."
        ),
        "originalKeywords": sorted(original_keywords, key=str.casefold),
        "auditedKeywords": retained_keywords,
        "summary": {
            "keywordsAudited": len(original_keywords),
            "linksReviewed": len(original_keywords) * 5,
            "linksRetained": links_after - (links_before - len(original_keywords) * 5),
            "netLinksRemoved": links_before - links_after,
            "uniqueKeywordsBefore": len(counts_before),
            "uniqueKeywordsAfter": len(counts_after),
            "keywordsRetired": 1,
            "assignmentsRemoved": len(removed_posts),
        },
        "retiredKeywords": [
            {
                "keyword": "Herod Agrippa I",
                "reason": (
                    "All five assignments were based on brief examples or passing "
                    "references rather than meaningful supporting discussion."
                ),
            }
        ],
        "resultingCounts": actual_result_counts,
        "removedPosts": removed_posts,
    }
    write_json(POSTS_PATH, posts)
    write_json(AUDIT_PATH, audit)
    print(json.dumps(audit["summary"], indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
