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
    / "genealogies_joshua_titus_2_timothy_didymus_secondary_keyword_audit.json"
)

AUDITS = {
    "Genealogies of Jesus": {
        "expected_before": 19,
        "remove": {"3488", "21924"},
        "reason": (
            "The post merely refers readers back to an earlier genealogy discussion "
            "instead of discussing Jesus' genealogies itself."
        ),
    },
    "Joshua": {
        "expected_before": 19,
        "remove": {"12239", "28520", "48687"},
        "reason": (
            "Joshua appears only in a list, chart, or book-range label rather than "
            "as meaningful supporting content."
        ),
    },
    "Titus": {
        "expected_before": 19,
        "remove": {
            "4618",
            "8147",
            "8334",
            "8599",
            "14958",
            "16656",
            "38583",
            "39548",
        },
        "reason": (
            "Titus appears only in a list or brief collective reference to disputed "
            "Pauline or Pastoral letters and is not substantively discussed."
        ),
    },
    "2 Timothy": {
        "expected_before": 18,
        "remove": {
            "4618",
            "8147",
            "8334",
            "8405",
            "8599",
            "14958",
            "16656",
            "17064",
            "38583",
            "39548",
            "47246",
        },
        "reason": (
            "2 Timothy appears only in a list, syllabus entry, overview, or preview "
            "rather than as meaningful supporting content."
        ),
    },
    "Didymus the Blind": {
        "expected_before": 18,
        "remove": {
            "2624",
            "4799",
            "11904",
            "12873",
            "12916",
            "21126",
            "26546",
            "34494",
            "36814",
            "47061",
        },
        "reason": (
            "Didymus is only named in a list, book title, source attribution, or "
            "autobiographical aside rather than meaningfully discussed."
        ),
    },
}

JOSHUA_REPLACEMENT_POST = "48263"
JOSHUA_REPLACEMENT = "Joshua the High Priest"


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

    for keyword, config in AUDITS.items():
        matching_posts = [
            post for post in posts if keyword in post.get("secondaryKeywords", [])
        ]
        expected_before = config["expected_before"]
        if len(matching_posts) != expected_before:
            raise ValueError(
                f"Expected {expected_before} {keyword!r} assignments; "
                f"found {len(matching_posts)}"
            )

        removed_posts = []
        for wp_id in sorted(config["remove"], key=int):
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
                    "reason": config["reason"],
                }
            )

        renamed = 0
        replacements = []
        if keyword == "Joshua":
            post = by_id[JOSHUA_REPLACEMENT_POST]
            if keyword not in post.get("secondaryKeywords", []):
                raise ValueError(
                    f"Missing {keyword!r} on post {JOSHUA_REPLACEMENT_POST}"
                )
            keywords = [
                value
                for value in post.get("secondaryKeywords", [])
                if value != keyword
            ]
            if JOSHUA_REPLACEMENT not in keywords:
                keywords.append(JOSHUA_REPLACEMENT)
            post["secondaryKeywords"] = sorted(keywords, key=str.casefold)
            renamed = 1
            replacements.append(
                {
                    "wpId": JOSHUA_REPLACEMENT_POST,
                    "title": post["title"],
                    "from": keyword,
                    "to": JOSHUA_REPLACEMENT,
                    "reason": (
                        "The post discusses the postexilic high priest in Zechariah, "
                        "not Joshua son of Nun."
                    ),
                }
            )

        retained = expected_before - len(removed_posts)
        result = {
            "keyword": keyword,
            "before": expected_before,
            "retained": retained,
            "removed": len(removed_posts),
            "removedPosts": removed_posts,
        }
        if renamed:
            result["renamed"] = renamed
            result["remainingUnderOriginalKeyword"] = retained - renamed
            result["replacements"] = replacements
        audit_results.append(result)

    validate_labels(posts)

    audit = {
        "auditDate": "2026-08-18",
        "scope": (
            "All existing assignments for Genealogies of Jesus, Joshua, Titus, "
            "2 Timothy, and Didymus the Blind"
        ),
        "criterion": (
            "Retain secondary keywords only when they identify meaningful supporting "
            "subjects, people, texts, places, or concepts; remove passing mentions, "
            "lists, charts, previews, and incidental autobiographical references."
        ),
        "auditedKeywords": [
            "Genealogies of Jesus",
            "Joshua",
            "Joshua the High Priest",
            "Titus",
            "2 Timothy",
            "Didymus the Blind",
        ],
        "summary": {
            "keywordsAudited": 5,
            "linksReviewed": 93,
            "linksRemoved": 34,
            "linksRetained": 59,
            "linksRenamed": 1,
            "keywordsRetired": 0,
            "replacementKeywordsIntroduced": 1,
        },
        "audits": audit_results,
    }

    write_json(INDEX_PATH, posts)
    write_json(AUDIT_PATH, audit)
    print(json.dumps(audit["summary"], indent=2))
    for item in audit_results:
        renamed = f", {item.get('renamed', 0)} renamed" if item.get("renamed") else ""
        print(
            f"{item['keyword']}: {item['before']} -> {item['retained']} "
            f"({item['removed']} removed{renamed})"
        )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
