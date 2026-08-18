#!/usr/bin/env python3
"""Apply the approved audits of five secondary keywords."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "five_secondary_keyword_audit.json"

AUDITS = {
    "1 Thessalonians": {
        "expected_before": 52,
        "remove": {
            "3012",
            "4753",
            "4763",
            "17487",
            "17494",
            "47123",
            "47217",
            "47219",
        },
        "reason": (
            "The letter is only listed or used as one of several statistical "
            "comparators rather than discussed as a meaningful subject."
        ),
    },
    "Craig Evans": {
        "expected_before": 52,
        "remove": {
            "7533",
            "7536",
            "7553",
            "7560",
            "36276",
            "36403",
            "36435",
        },
        "reason": (
            "The post either precedes the substantive response to Evans or pauses "
            "that series to discuss a different subject."
        ),
    },
    "Lazarus": {
        "expected_before": 52,
        "remove": {
            "4598",
            "7046",
            "8803",
            "8814",
            "12450",
            "12840",
            "13196",
            "13684",
            "14935",
            "15181",
            "17813",
            "21106",
            "29381",
            "38983",
            "47098",
        },
        "reason": (
            "Lazarus appears only in a passing example, list, citation title, or "
            "brief comparison rather than as meaningful supporting content."
        ),
    },
    "Isaac": {
        "expected_before": 51,
        "remove": {
            "3222",
            "3917",
            "4094",
            "4335",
            "4469",
            "4505",
            "4885",
            "8613",
            "11599",
            "11619",
            "12563",
            "12943",
            "13186",
            "14905",
            "14935",
            "15303",
            "15559",
            "15676",
            "16639",
            "20711",
            "21016",
            "24163",
            "25439",
            "25677",
            "27056",
            "27343",
            "27402",
            "31240",
            "33426",
            "34141",
            "36268",
            "37140",
            "38971",
            "40538",
            "40864",
            "48868",
        },
        "reason": (
            "Isaac occurs only in a patriarchal list, quoted formula, genealogy, "
            "or passing example rather than being meaningfully discussed."
        ),
        "reason_overrides": {
            "34141": (
                "The post refers to the twentieth-century scholar Jules Isaac, "
                "not the biblical Isaac."
            )
        },
    },
    "Martyrdom": {
        "expected_before": 50,
        "remove": {
            "3161",
            "15392",
            "15482",
            "27410",
            "33161",
            "34882",
            "48295",
        },
        "reason": (
            "Martyrdom is only previewed, listed, or supplied as brief biographical "
            "context rather than meaningfully discussed."
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

        removal_ids = config["remove"]
        unknown_ids = removal_ids - set(by_id)
        if unknown_ids:
            raise ValueError(
                f"Unknown post IDs for {keyword!r}: {sorted(unknown_ids)}"
            )

        removed_posts = []
        for wp_id in sorted(removal_ids, key=int):
            post = by_id[wp_id]
            if keyword not in post.get("secondaryKeywords", []):
                raise ValueError(f"Missing {keyword!r} on post {wp_id}")

            post["secondaryKeywords"] = [
                value
                for value in post.get("secondaryKeywords", [])
                if value != keyword
            ]
            reason = config.get("reason_overrides", {}).get(
                wp_id, config["reason"]
            )
            removed_posts.append(
                {
                    "wpId": wp_id,
                    "title": post["title"],
                    "topics": post.get("topics", []),
                    "reason": reason,
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

    links_reviewed = sum(item["before"] for item in audit_results)
    links_removed = sum(item["removed"] for item in audit_results)
    audit = {
        "auditDate": "2026-08-18",
        "scope": (
            "All existing assignments for 1 Thessalonians, Craig Evans, Lazarus, "
            "Isaac, and Martyrdom"
        ),
        "criterion": (
            "Retain secondary keywords only when they identify meaningful supporting "
            "subjects, people, texts, places, or concepts; remove passing mentions, "
            "lists, credits, previews, and name collisions."
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
