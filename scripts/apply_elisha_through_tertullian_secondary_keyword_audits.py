#!/usr/bin/env python3
"""Apply ten approved full-text secondary-keyword audits."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = (
    ROOT
    / "data"
    / "audits"
    / "elisha_through_tertullian_secondary_keyword_audit.json"
)

AUDITS = {
    "Elisha": {
        "expected_before": 18,
        "remove": {"32546", "33023", "39619"},
        "reason": (
            "Elisha appears only in a list of prophets or a brief comparison and "
            "is not meaningfully discussed."
        ),
    },
    "Jewish Apocalypticism": {
        "expected_before": 18,
        "remove": {"28517", "28663"},
        "reason": (
            "Jewish apocalypticism is mentioned only to introduce a separate "
            "series on the prophets rather than discussed in the post itself."
        ),
    },
    "New Testament Manuscripts": {
        "expected_before": 18,
        "remove": set(),
        "reason": "All assignments represent meaningful manuscript-related content.",
    },
    "Gabriel": {
        "expected_before": 17,
        "remove": {
            "3433",
            "8350",
            "12625",
            "15324",
            "15687",
            "22610",
            "41900",
            "41908",
        },
        "reason": (
            "Gabriel receives only a brief narrative mention in a broader discussion "
            "of Jesus' birth or the virgin birth."
        ),
    },
    "Gospel of Mary": {
        "expected_before": 17,
        "remove": {
            "2259",
            "7686",
            "12262",
            "13336",
            "16599",
            "27082",
            "28082",
            "37615",
        },
        "reason": (
            "The Gospel of Mary appears only in a reading list, text inventory, "
            "exam term, or footnote rather than as meaningful supporting content."
        ),
    },
    "King James Version": {
        "expected_before": 17,
        "remove": {"12138", "15614", "33890", "38016", "40387"},
        "reason": (
            "The King James Version is only quoted, named parenthetically, or used "
            "as a transition without substantive discussion of the translation."
        ),
    },
    "Oral Tradition": {
        "expected_before": 17,
        "remove": {"11974", "11983", "13605", "14982", "50349"},
        "reason": (
            "Oral tradition is absent or receives only a brief speculative, source, "
            "or contextual reference rather than meaningful discussion."
        ),
    },
    "Pedagogy": {
        "expected_before": 17,
        "remove": {"10307"},
        "reason": (
            "The post evaluates public debates rather than teaching methods or "
            "classroom practice."
        ),
    },
    "Proto-Gospel of James": {
        "expected_before": 17,
        "remove": {
            "3415",
            "6402",
            "6409",
            "12088",
            "12107",
            "12110",
            "16117",
            "16364",
            "22604",
            "35188",
            "37162",
            "41919",
            "46944",
        },
        "reason": (
            "The Proto-Gospel of James appears only in an announcement, list, "
            "passing comparison, or reference to an earlier discussion."
        ),
    },
    "Tertullian": {
        "expected_before": 17,
        "remove": {"6637", "25178", "46925"},
        "reason": (
            "Tertullian appears only in a contents list, a brief verse and note, "
            "or a single source attribution rather than meaningful discussion."
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

        retained = expected_before - len(removed_posts)
        audit_results.append(
            {
                "keyword": keyword,
                "before": expected_before,
                "retained": retained,
                "removed": len(removed_posts),
                "removedPosts": removed_posts,
            }
        )

    validate_labels(posts)

    links_reviewed = sum(item["before"] for item in audit_results)
    links_removed = sum(item["removed"] for item in audit_results)
    audit = {
        "auditDate": "2026-08-18",
        "scope": (
            "All existing assignments for Elisha, Jewish Apocalypticism, New "
            "Testament Manuscripts, Gabriel, Gospel of Mary, King James Version, "
            "Oral Tradition, Pedagogy, Proto-Gospel of James, and Tertullian"
        ),
        "criterion": (
            "Retain secondary keywords only when the complete post text meaningfully "
            "discusses the person, text, tradition, method, or concept; remove lists, "
            "passing mentions, footnotes, announcements, and surrounding context."
        ),
        "auditedKeywords": list(AUDITS),
        "summary": {
            "keywordsAudited": len(AUDITS),
            "linksReviewed": links_reviewed,
            "linksRemoved": links_removed,
            "linksRetained": links_reviewed - links_removed,
            "keywordsRetired": 0,
        },
        "audits": audit_results,
    }

    write_json(INDEX_PATH, posts)
    write_json(AUDIT_PATH, audit)
    print(json.dumps(audit["summary"], indent=2))
    for item in audit_results:
        print(
            f"{item['keyword']}: {item['before']} -> {item['retained']} "
            f"({item['removed']} removed)"
        )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
