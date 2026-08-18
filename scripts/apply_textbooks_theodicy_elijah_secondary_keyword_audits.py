#!/usr/bin/env python3
"""Apply the approved Textbooks, Theodicy, and Elijah keyword audits."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = (
    ROOT
    / "data"
    / "audits"
    / "textbooks_theodicy_elijah_secondary_keyword_audit.json"
)

EXPECTED_BEFORE = {
    "Textbooks": 37,
    "Theodicy": 37,
    "Elijah": 36,
}

REMOVALS = {
    "Textbooks": {
        "40572",
        "33896",
        "31181",
        "21333",
        "21154",
        "20020",
        "15902",
        "15505",
        "15156",
        "15003",
        "14766",
        "12379",
        "7397",
        "7373",
        "4273",
        "3942",
        "3774",
        "3156",
    },
    "Theodicy": {
        "12467",
        "12299",
    },
    "Elijah": {
        "47640",
        "47064",
        "47058",
        "39619",
        "36333",
        "33023",
        "32546",
        "21139",
        "21072",
        "15868",
        "15834",
        "15072",
        "13502",
        "11985",
        "10268",
        "9264",
        "9177",
        "7197",
        "6951",
        "2517",
    },
}

REMOVAL_REASONS = {
    "Textbooks": (
        "Textbooks are only a publication type, previous project, course "
        "requirement, source, or incidental example rather than a meaningful "
        "subject of the post."
    ),
    "Theodicy": (
        "The post does not meaningfully examine suffering or evil in relation "
        "to God's goodness, power, justice, or proposed theological explanations."
    ),
    "Elijah": (
        "Elijah is only a passing comparison, a name in a list, or briefly "
        "present in a cited biblical scene rather than a meaningful supporting figure."
    ),
}

EXPECTED_AFTER = {
    "Textbooks": 19,
    "Theodicy": 35,
    "Elijah": 16,
}


def load_json(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value) -> None:
    path.write_text(
        json.dumps(value, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
        newline="\n",
    )


def normalized(value: str) -> str:
    return value.casefold().strip()


def main() -> int:
    posts = load_json(INDEX_PATH)
    by_id = {str(post["wpId"]): post for post in posts}

    for keyword, expected in EXPECTED_BEFORE.items():
        actual = sum(keyword in post.get("secondaryKeywords", []) for post in posts)
        if actual != expected:
            raise ValueError(
                f"Expected {expected} {keyword!r} assignments; found {actual}"
            )

    target_ids = set().union(*REMOVALS.values())
    unknown_ids = target_ids - set(by_id)
    if unknown_ids:
        raise ValueError(f"Unknown post IDs: {sorted(unknown_ids)}")

    removed_records: dict[str, list[dict]] = {}
    for keyword, post_ids in REMOVALS.items():
        records = []
        for wp_id in sorted(post_ids, key=int):
            post = by_id[wp_id]
            keywords = post.get("secondaryKeywords", [])
            if keyword not in keywords:
                raise ValueError(f"Missing {keyword!r} on post {wp_id}")
            post["secondaryKeywords"] = [
                value for value in keywords if value != keyword
            ]
            records.append(
                {
                    "wpId": wp_id,
                    "title": post["title"],
                    "topics": post.get("topics", []),
                    "reason": REMOVAL_REASONS[keyword],
                }
            )
        removed_records[keyword] = records

    duplicate_keyword_posts = []
    topic_keyword_overlap_posts = []
    for post in posts:
        keyword_keys = [
            normalized(value) for value in post.get("secondaryKeywords", [])
        ]
        if len(keyword_keys) != len(set(keyword_keys)):
            duplicate_keyword_posts.append(str(post["wpId"]))
        topic_keys = {normalized(value) for value in post.get("topics", [])}
        if topic_keys & set(keyword_keys):
            topic_keyword_overlap_posts.append(str(post["wpId"]))

    if duplicate_keyword_posts or topic_keyword_overlap_posts:
        raise ValueError(
            "Validation failed: duplicate keywords "
            f"{duplicate_keyword_posts[:5]}, topic/keyword overlaps "
            f"{topic_keyword_overlap_posts[:5]}"
        )

    final_counts = {
        keyword: sum(keyword in post.get("secondaryKeywords", []) for post in posts)
        for keyword in EXPECTED_AFTER
    }
    if final_counts != EXPECTED_AFTER:
        raise ValueError(
            f"Unexpected final counts: {final_counts}; expected {EXPECTED_AFTER}"
        )

    audits = []
    for keyword, before in EXPECTED_BEFORE.items():
        removed = removed_records[keyword]
        audits.append(
            {
                "keyword": keyword,
                "before": before,
                "retained": before - len(removed),
                "removed": len(removed),
                "final": final_counts[keyword],
                "removedPosts": removed,
            }
        )

    audit = {
        "auditDate": "2026-08-18",
        "scope": "All existing assignments for Textbooks, Theodicy, and Elijah",
        "criterion": (
            "Retain secondary keywords only when the complete post text "
            "meaningfully discusses textbooks, the theological problem of "
            "suffering and evil, or Elijah; remove passing publication labels, "
            "adjacent series context, undeveloped comparisons, and names in lists."
        ),
        "auditedKeywords": list(EXPECTED_BEFORE),
        "summary": {
            "requestedKeywordsAudited": len(EXPECTED_BEFORE),
            "linksReviewed": sum(EXPECTED_BEFORE.values()),
            "linksRemoved": sum(len(value) for value in removed_records.values()),
            "linksAdded": 0,
            "keywordsRetired": 0,
        },
        "audits": audits,
    }

    write_json(INDEX_PATH, posts)
    write_json(AUDIT_PATH, audit)

    print(json.dumps(audit["summary"], indent=2))
    for item in audits:
        print(
            f"{item['keyword']}: {item['before']} -> {item['final']} "
            f"({item['removed']} removed)"
        )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
