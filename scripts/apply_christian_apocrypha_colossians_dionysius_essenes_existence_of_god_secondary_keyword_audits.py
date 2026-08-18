#!/usr/bin/env python3
"""Apply five approved full-text secondary-keyword audits."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = (
    ROOT
    / "data"
    / "audits"
    / "christian_apocrypha_colossians_dionysius_essenes_existence_of_god_secondary_keyword_audit.json"
)

REMOVALS = {
    "Christian Apocrypha": set(),
    "Colossians": {"8147", "16656", "38583"},
    "Essenes": {
        "6142",
        "6467",
        "12064",
        "12747",
        "13183",
        "21114",
        "28730",
        "41594",
        "41615",
        "50364",
    },
    "Existence of God": {"36302"},
}

DIONYSIUS_REPLACEMENTS = {
    "Dionysius the Renegade": {
        "2536",
        "15099",
        "16678",
        "16715",
        "29035",
        "48945",
    },
    "Dionysius of Alexandria": {
        "15532",
        "16565",
        "33405",
        "47847",
        "49782",
    },
    "Dionysius Exiguus": {
        "2405",
        "28966",
        "3371",
        "11613",
        "16304",
        "47257",
    },
    "Pseudo-Dionysius the Areopagite": {"3295", "40273"},
}

REMOVAL_REASONS = {
    "Colossians": (
        "Colossians appears only in an introductory list of disputed or forged "
        "letters and is not substantively discussed."
    ),
    "Essenes": (
        "The Essenes appear only as a brief example or comparison rather than "
        "as a meaningful supporting subject."
    ),
    "Existence of God": (
        "The post announces a course that will address God's existence but does "
        "not itself discuss the subject."
    ),
}


def load_json(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value) -> None:
    path.write_text(
        json.dumps(value, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
        newline="\n",
    )


def validate_labels(posts: list[dict]) -> None:
    duplicate_keywords = []
    topic_keyword_overlaps = []
    for post in posts:
        normalized_keywords = [
            value.casefold().strip()
            for value in post.get("secondaryKeywords", [])
        ]
        if len(normalized_keywords) != len(set(normalized_keywords)):
            duplicate_keywords.append(str(post["wpId"]))

        normalized_topics = {
            value.casefold().strip() for value in post.get("topics", [])
        }
        if normalized_topics & set(normalized_keywords):
            topic_keyword_overlaps.append(str(post["wpId"]))

    if duplicate_keywords or topic_keyword_overlaps:
        raise ValueError(
            "Validation failed: duplicate keywords "
            f"{duplicate_keywords[:5]}, topic/keyword overlaps "
            f"{topic_keyword_overlaps[:5]}"
        )


def main() -> int:
    posts = load_json(INDEX_PATH)
    by_id = {str(post["wpId"]): post for post in posts}
    audit_results = []

    for keyword, removal_ids in REMOVALS.items():
        matching_posts = [
            post for post in posts if keyword in post.get("secondaryKeywords", [])
        ]
        if len(matching_posts) != 19:
            raise ValueError(
                f"Expected 19 {keyword!r} assignments; found {len(matching_posts)}"
            )

        removed_posts = []
        for wp_id in sorted(removal_ids, key=int):
            post = by_id.get(wp_id)
            if post is None:
                raise ValueError(f"Unknown post ID {wp_id} for {keyword!r}")
            if keyword not in post.get("secondaryKeywords", []):
                raise ValueError(f"Missing {keyword!r} on post {wp_id}")

            post["secondaryKeywords"] = [
                value
                for value in post.get("secondaryKeywords", [])
                if value != keyword
            ]
            removed_posts.append(
                {
                    "wpId": wp_id,
                    "title": post["title"],
                    "topics": post.get("topics", []),
                    "reason": REMOVAL_REASONS[keyword],
                }
            )

        audit_results.append(
            {
                "keyword": keyword,
                "before": 19,
                "retained": 19 - len(removed_posts),
                "removed": len(removed_posts),
                "removedPosts": removed_posts,
            }
        )

    dionysius_posts = [
        post for post in posts if "Dionysius" in post.get("secondaryKeywords", [])
    ]
    if len(dionysius_posts) != 19:
        raise ValueError(
            "Expected 19 'Dionysius' assignments; "
            f"found {len(dionysius_posts)}"
        )

    replacement_ids = set().union(*DIONYSIUS_REPLACEMENTS.values())
    current_dionysius_ids = {str(post["wpId"]) for post in dionysius_posts}
    if replacement_ids != current_dionysius_ids:
        raise ValueError(
            "Dionysius replacement IDs do not match current assignments: "
            f"missing={sorted(current_dionysius_ids - replacement_ids)}, "
            f"extra={sorted(replacement_ids - current_dionysius_ids)}"
        )

    replacement_results = []
    for precise_keyword, post_ids in DIONYSIUS_REPLACEMENTS.items():
        replaced_posts = []
        for wp_id in sorted(post_ids, key=int):
            post = by_id[wp_id]
            keywords = [
                value
                for value in post.get("secondaryKeywords", [])
                if value != "Dionysius"
            ]
            if precise_keyword not in keywords:
                keywords.append(precise_keyword)
            post["secondaryKeywords"] = sorted(keywords, key=str.casefold)
            replaced_posts.append(
                {
                    "wpId": wp_id,
                    "title": post["title"],
                    "topics": post.get("topics", []),
                }
            )

        replacement_results.append(
            {
                "replacementKeyword": precise_keyword,
                "postCount": len(replaced_posts),
                "posts": replaced_posts,
            }
        )

    audit_results.insert(
        2,
        {
            "keyword": "Dionysius",
            "before": 19,
            "retained": 19,
            "removed": 0,
            "renamed": 19,
            "reason": (
                "The generic name conflated four different historical figures; "
                "each assignment was replaced with the precise identity discussed."
            ),
            "replacements": replacement_results,
        },
    )

    validate_labels(posts)

    audit = {
        "auditDate": "2026-08-18",
        "scope": (
            "All existing assignments for Christian Apocrypha, Colossians, "
            "Dionysius, Essenes, and Existence of God"
        ),
        "criterion": (
            "Retain secondary keywords only when they identify meaningful supporting "
            "subjects, people, texts, places, or concepts; remove passing mentions "
            "and disambiguate people who share a name."
        ),
        "auditedKeywords": [
            "Christian Apocrypha",
            "Colossians",
            "Dionysius",
            "Dionysius the Renegade",
            "Dionysius of Alexandria",
            "Dionysius Exiguus",
            "Pseudo-Dionysius the Areopagite",
            "Essenes",
            "Existence of God",
        ],
        "summary": {
            "keywordsAudited": 5,
            "linksReviewed": 95,
            "linksRemoved": 14,
            "linksRetained": 81,
            "linksRenamed": 19,
            "keywordsRetired": 1,
            "replacementKeywordsIntroduced": 4,
        },
        "audits": audit_results,
    }

    write_json(INDEX_PATH, posts)
    write_json(AUDIT_PATH, audit)
    print(json.dumps(audit["summary"], indent=2))
    for item in audit_results:
        suffix = f", {item.get('renamed', 0)} renamed" if item.get("renamed") else ""
        print(
            f"{item['keyword']}: {item['before']} -> {item['retained']} "
            f"({item['removed']} removed{suffix})"
        )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
