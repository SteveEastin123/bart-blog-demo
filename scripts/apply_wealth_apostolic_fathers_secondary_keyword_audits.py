#!/usr/bin/env python3
"""Apply the approved Wealth and Apostolic Fathers keyword audits."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = (
    ROOT
    / "data"
    / "audits"
    / "wealth_apostolic_fathers_secondary_keyword_audit.json"
)

EXPECTED_BEFORE = {
    "Wealth": 38,
    "Apostolic Fathers": 37,
}

REMOVAL_REASONS = {
    "Wealth": {
        "31899": "Wealth occurs only in an analogy between evolution and free-market economics.",
        "30695": "Wealth appears once while describing material discussed elsewhere rather than in this interview announcement.",
        "29782": "Material wealth is only a brief illustration within a broader comparison of Revelation and Jesus.",
        "26635": "Wealth occurs mainly in the quoted text of Revelation 18; the post concerns Pompeii and Revelation rather than wealth.",
    },
    "Apostolic Fathers": {
        "48949": "The label only identifies the Didache parenthetically while discussing a composite forged work.",
        "37565": "The collection is only a classification for 1 Clement and the Didache in a broader argument about canon and diversity.",
        "36732": "The label only classifies Barnabas and Hermas while the post discusses Codex Sinaiticus and Saint Catherine's Monastery.",
        "27644": "The collection is mentioned only as an earlier translation project.",
        "27618": "The collection is mentioned only as an earlier translation project.",
        "21209": "The phrase appears only in lists of possible examination and research fields.",
        "17780": "The phrase appears only in a Great Courses title in a promotional post.",
        "16599": "The Apostolic Fathers edition merely supplies background for a different publishing project.",
        "14804": "The phrase appears only in a list of Great Courses titles.",
        "13336": "The prior Apostolic Fathers edition is background to the Apocryphal Gospels translation project.",
        "12873": "The phrase appears only in a list of previously published books.",
        "12709": "The phrase appears only in Michael Holmes's credentials.",
        "8942": "The phrase appears only in a general list of subjects covered by the blog.",
        "8874": "The label only classifies Barnabas and Hermas while the post discusses Codex Sinaiticus and Saint Catherine's Monastery.",
        "7732": "The phrase appears only in lists of possible examination and research fields.",
        "7686": "The collection is mentioned only as the project preceding the Apocryphal Gospels edition.",
        "7681": "The prior Apostolic Fathers edition supplies background for the Apocryphal Gospels publishing project.",
        "3209": "The Apostolic Fathers project is career background rather than a substantive subject of the post.",
        "2693": "The collection is mentioned only as an earlier translation project.",
    },
}

EXPECTED_AFTER = {
    "Wealth": 34,
    "Apostolic Fathers": 18,
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

    target_ids = {
        wp_id
        for removals in REMOVAL_REASONS.values()
        for wp_id in removals
    }
    unknown_ids = target_ids - set(by_id)
    if unknown_ids:
        raise ValueError(f"Unknown post IDs: {sorted(unknown_ids)}")

    removed_records: dict[str, list[dict]] = {}
    for keyword, removals in REMOVAL_REASONS.items():
        records = []
        for wp_id in sorted(removals, key=int):
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
                    "reason": removals[wp_id],
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
        "scope": "All existing assignments for Wealth and Apostolic Fathers",
        "criterion": (
            "Retain secondary keywords only when the complete post text "
            "meaningfully discusses wealth or the Apostolic Fathers collection, "
            "its writings, or its scholarly use; remove passing labels, lists, "
            "bibliographic references, and incidental examples."
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
