#!/usr/bin/env python3
"""Apply the approved full-text re-audit of current six-post keywords."""

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
    / "six_post_secondary_keyword_reaudit_2026_08_18_secondary_keyword_audit.json"
)

EXPECTED_SIX_POST_KEYWORDS = {
    "1 Chronicles",
    "Aaron",
    "Angels",
    "Aquila",
    "Aramaic",
    "Bathsheba",
    "Dinesh D'Souza",
    "Ebionites",
    "Evangelism",
    "First-Century Mark",
    "Gospel According to the Hebrews",
    "Grace",
    "Hosea",
    "Imperial Cult",
    "Introduction to the New Testament",
    "John Shelby Spong",
    "Keith Hopkins",
    "King of the Jews",
    "Marcion",
    "Martin Goodman",
    "Mummy Masks",
    "Palaeography",
    "Papias",
    "Syllabus",
    "Terry Gross",
    "Textus Receptus",
    "Thomas",
    "Walter Bauer",
}
EXPECTED_UNIQUE_KEYWORDS_BEFORE = 918
EXPECTED_UNIQUE_KEYWORDS_AFTER = 916

REMOVALS = {
    "1 Chronicles": {
        "3472": (
            "The book appears only in a humorous aside contrasting its long "
            "genealogies with the genealogies in Matthew and Luke."
        ),
    },
    "Aaron": {
        "46934": (
            "Aaron appears only in a brief explanation that Jewish priests "
            "descended through the tribe of Levi."
        ),
        "41046": (
            "Aaron appears only in two quoted family and narrative references "
            "within a speculative proposal about Moses."
        ),
        "38971": (
            "Aaron appears only in two quoted family and narrative references "
            "within a speculative proposal about Moses."
        ),
        "32990": (
            "Aaron appears only in a brief retelling of the golden-calf story."
        ),
    },
    "Aquila": {
        "40178": "Aquila appears only once as Prisca's husband in a list of women.",
        "39264": "Aquila appears only once as Prisca's husband in a list of women.",
        "25875": (
            "Aquila appears only once as part of the background to Paul's work "
            "in Corinth."
        ),
        "25709": (
            "Aquila appears only once as part of the background to Paul's "
            "Corinthian community."
        ),
        "7627": "Aquila appears only once as Prisca's husband in a list of women.",
        "4591": "Aquila appears only once as Prisca's husband in a list of women.",
    },
    "Bathsheba": {
        "39869": (
            "Bathsheba appears only in a brief list of women from Matthew's "
            "genealogy within a remembrance of John Shelby Spong."
        ),
    },
    "Evangelism": {
        "15925": (
            "Door-to-door evangelism is only autobiographical background for a "
            "post about doubts concerning Jesus as the Son of God."
        ),
        "12408": (
            "Door-to-door evangelism is only autobiographical background for a "
            "post about Pastor Goranson and belief in the Son of God."
        ),
        "11803": (
            "Door-to-door evangelism is one brief example among several Moody "
            "ministry assignments in a post about learning to teach."
        ),
        "3947": (
            "Door-to-door evangelism is only autobiographical background for a "
            "post about Pastor Goranson and belief in the Son of God."
        ),
    },
    "First-Century Mark": {
        "17505": (
            "The post already carries the more precise topic First-Century Mark "
            "Fragment."
        ),
        "16884": (
            "The post already carries the more precise topic First-Century Mark "
            "Fragment."
        ),
        "16322": (
            "The shortened label is being replaced with the precise label "
            "First-Century Mark Fragment."
        ),
        "16288": (
            "The shortened label is being replaced with the precise label "
            "First-Century Mark Fragment."
        ),
        "15187": (
            "The post already carries the more precise topic First-Century Mark "
            "Fragment."
        ),
        "15146": (
            "The post already carries the more precise topic First-Century Mark "
            "Fragment."
        ),
    },
    "Introduction to the New Testament": {
        "4727": (
            "The quiz was given in a seminar about Jesus rather than the course "
            "Introduction to the New Testament."
        ),
    },
    "Marcion": {
        "4121": (
            "Marcion appears only once in a list of competing early "
            "Christologies."
        ),
    },
    "Martin Goodman": {
        "7475": (
            "Goodman is only one of several speakers briefly noted in a "
            "conference recap."
        ),
        "6277": (
            "Goodman appears only in the speaker list for a conference "
            "announcement."
        ),
    },
    "Textus Receptus": {
        "12275": (
            "The post discusses Erasmus's first published Greek New Testament "
            "but does not discuss the Textus Receptus."
        ),
        "12272": (
            "The post discusses the earliest printed Greek New Testaments but "
            "does not discuss the Textus Receptus."
        ),
    },
    "Thomas": {
        "33399": (
            "Thomas appears only in lists of famous names used for forged "
            "writings."
        ),
        "15672": (
            "The ambiguous Thomas label is redundant because the post already "
            "uses the precise keyword Judas Thomas."
        ),
        "13934": (
            "Thomas appears only in the title of a Mark Goodacre book cited in "
            "the contributor biography."
        ),
        "8890": (
            "Thomas appears only in the title of a Mark Goodacre book cited in "
            "the contributor biography."
        ),
        "8326": (
            "Thomas appears only in a list of apostolic names used as claims of "
            "authority."
        ),
        "7504": (
            "Thomas appears only in the title of a Mark Goodacre book cited in "
            "the contributor biography."
        ),
    },
}

ADDITIONS = {
    "First-Century Mark Fragment": {
        "16322": (
            "The first-century Mark controversy is a meaningful example in the "
            "post's investigation of acquiring ancient manuscripts."
        ),
        "16288": (
            "The post directly asks how a hypothetical first-century copy of "
            "Mark could be dated and identified as an original."
        ),
    },
    "Fresh Air": {
        "12339": (
            "The post shares a Fresh Air interview hosted by Terry Gross."
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


def add_keyword(post: dict, keyword: str) -> None:
    if keyword not in post.get("secondaryKeywords", []):
        post.setdefault("secondaryKeywords", []).append(keyword)


def main() -> int:
    posts = load_json(POSTS_PATH)
    by_id = {str(post["wpId"]): post for post in posts}
    counts_before = Counter(
        keyword
        for post in posts
        for keyword in post.get("secondaryKeywords", [])
    )
    original_keywords = {
        keyword for keyword, count in counts_before.items() if count == 6
    }
    if original_keywords != EXPECTED_SIX_POST_KEYWORDS:
        missing = sorted(EXPECTED_SIX_POST_KEYWORDS - original_keywords, key=str.casefold)
        unexpected = sorted(original_keywords - EXPECTED_SIX_POST_KEYWORDS, key=str.casefold)
        raise ValueError(
            "Unexpected six-post keyword set; "
            f"missing={missing}, unexpected={unexpected}"
        )
    if len(counts_before) != EXPECTED_UNIQUE_KEYWORDS_BEFORE:
        raise ValueError(
            f"Expected {EXPECTED_UNIQUE_KEYWORDS_BEFORE} unique keywords; "
            f"found {len(counts_before)}"
        )
    if counts_before.get("First-Century Mark Fragment", 0):
        raise ValueError("First-Century Mark Fragment already exists as a keyword")

    for keyword, removals in REMOVALS.items():
        actual_ids = keyword_post_ids(posts, keyword)
        if len(actual_ids) != 6 or not set(removals).issubset(actual_ids):
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
            removed_posts.append(
                {
                    "keyword": keyword,
                    "wpId": wp_id,
                    "title": post["title"],
                    "reason": reason,
                }
            )

    added_posts = []
    for keyword, additions in ADDITIONS.items():
        for wp_id, reason in additions.items():
            post = by_id[wp_id]
            if keyword in post.get("secondaryKeywords", []):
                raise ValueError(f"Unexpected existing {keyword!r} on post {wp_id}")
            add_keyword(post, keyword)
            added_posts.append(
                {
                    "keyword": keyword,
                    "wpId": wp_id,
                    "title": post["title"],
                    "reason": reason,
                }
            )

    touched_ids = {row["wpId"] for row in removed_posts + added_posts}
    for wp_id in touched_ids:
        by_id[wp_id]["secondaryKeywords"] = sorted(
            by_id[wp_id].get("secondaryKeywords", []), key=str.casefold
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
        "1 Chronicles": 5,
        "Aaron": 2,
        "Aquila": 0,
        "Bathsheba": 5,
        "Evangelism": 2,
        "First-Century Mark": 0,
        "First-Century Mark Fragment": 2,
        "Fresh Air": 6,
        "Introduction to the New Testament": 5,
        "Marcion": 5,
        "Martin Goodman": 4,
        "Textus Receptus": 4,
        "Thomas": 0,
    }
    actual_result_counts = {
        keyword: counts_after.get(keyword, 0) for keyword in expected_result_counts
    }
    if actual_result_counts != expected_result_counts:
        raise ValueError(f"Unexpected resulting counts: {actual_result_counts}")

    links_before = sum(counts_before.values())
    links_after = sum(counts_after.values())
    audited_keywords = sorted(
        ({keyword for keyword in original_keywords if keyword in counts_after}
         | {"First-Century Mark Fragment"}),
        key=str.casefold,
    )
    audit = {
        "auditDate": "2026-08-18",
        "scope": (
            "Every secondary keyword assigned to exactly six posts before this "
            "re-audit"
        ),
        "criterion": (
            "Retain meaningful supporting subjects, people, texts, places, or "
            "concepts; remove passing mentions and list entries; replace "
            "ambiguous or imprecise labels; and avoid duplicating a post's topic "
            "as a secondary keyword."
        ),
        "originalKeywords": sorted(original_keywords, key=str.casefold),
        "auditedKeywords": audited_keywords,
        "summary": {
            "keywordsAudited": len(original_keywords),
            "linksReviewed": len(original_keywords) * 6,
            "assignmentsRemoved": len(removed_posts),
            "assignmentsAdded": len(added_posts),
            "netLinksRemoved": links_before - links_after,
            "uniqueKeywordsBefore": len(counts_before),
            "uniqueKeywordsAfter": len(counts_after),
            "keywordsRetired": 3,
            "labelsIntroduced": 1,
        },
        "retiredKeywords": [
            {
                "keyword": "Aquila",
                "reason": "All six assignments were based on single passing mentions.",
            },
            {
                "keyword": "First-Century Mark",
                "reason": (
                    "The shortened label was replaced where needed by the more "
                    "precise First-Century Mark Fragment label."
                ),
            },
            {
                "keyword": "Thomas",
                "reason": (
                    "The generic label was ambiguous and all assignments were "
                    "passing, bibliographic, or already represented by Judas Thomas."
                ),
            },
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
