#!/usr/bin/env python3
"""Apply the approved Constantine, DSS, Philippians, and Exodus audits."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = (
    ROOT
    / "data"
    / "audits"
    / "constantine_dss_philippians_exodus_secondary_keyword_audit.json"
)

EXPECTED_BEFORE = {
    "Constantine": 42,
    "Dead Sea Scrolls": 42,
    "Philippians": 41,
    "Exodus": 40,
}

REMOVALS = {
    "Constantine": {
        "1738",
        "3444",
        "6595",
        "14872",
        "17780",
        "27170",
        "28167",
        "32817",
        "36724",
        "40170",
    },
    "Dead Sea Scrolls": {
        "2380",
        "5125",
        "6142",
        "6595",
        "8884",
        "9292",
        "11339",
        "12064",
        "12262",
        "12747",
        "15590",
        "21114",
        "27285",
        "28730",
        "35354",
        "40118",
        "41351",
    },
    "Philippians": {
        "33874",
        "47113",
        "47123",
        "48426",
        "48428",
    },
    "Exodus": {
        "11589",
        "12239",
        "12447",
        "25653",
        "29103",
        "32825",
        "33421",
        "38977",
        "48687",
    },
}

REMOVAL_REASONS = {
    "Constantine": (
        "Constantine appears only in a title, date boundary, course or subject "
        "list, or brief comparison rather than as a meaningful supporting subject."
    ),
    "Dead Sea Scrolls": (
        "The scrolls appear only in credentials, a list, a chronological "
        "comparison, or a formulaic reference to the Essenes and are not discussed."
    ),
    "Philippians": (
        "Philippians appears only in a list or brief comparison, or refers to "
        "Polycarp's audience rather than Paul's canonical letter."
    ),
    "Exodus": (
        "Exodus appears only in a book list, course promotion, broad example, or "
        "passing Pentateuch reference rather than as a meaningful supporting subject."
    ),
}

REPLACEMENTS = {
    "Constantine von Tischendorf": {
        "36724": (
            "The post centrally discusses the manuscript hunter Constantine von "
            "Tischendorf, not Emperor Constantine."
        )
    }
}

EXPECTED_AFTER = {
    "Constantine": 32,
    "Dead Sea Scrolls": 25,
    "Philippians": 36,
    "Exodus": 31,
    "Constantine von Tischendorf": 1,
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
        actual = sum(
            keyword in post.get("secondaryKeywords", []) for post in posts
        )
        if actual != expected:
            raise ValueError(
                f"Expected {expected} {keyword!r} assignments; found {actual}"
            )

    target_ids = set().union(*REMOVALS.values())
    for replacements in REPLACEMENTS.values():
        target_ids.update(replacements)
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

    added_records: dict[str, list[dict]] = {}
    for keyword, replacements in REPLACEMENTS.items():
        records = []
        key = normalized(keyword)
        for wp_id, reason in replacements.items():
            post = by_id[wp_id]
            keyword_keys = {
                normalized(value) for value in post.get("secondaryKeywords", [])
            }
            topic_keys = {normalized(value) for value in post.get("topics", [])}
            if key in keyword_keys:
                raise ValueError(f"Duplicate addition {keyword!r} on post {wp_id}")
            if key in topic_keys:
                raise ValueError(
                    f"Topic/keyword overlap for {keyword!r} on post {wp_id}"
                )
            post.setdefault("secondaryKeywords", []).append(keyword)
            records.append(
                {
                    "wpId": wp_id,
                    "title": post["title"],
                    "topics": post.get("topics", []),
                    "reason": reason,
                }
            )
        added_records[keyword] = records

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
        keyword: sum(
            keyword in post.get("secondaryKeywords", []) for post in posts
        )
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
        "scope": (
            "All existing assignments for Constantine, Dead Sea Scrolls, "
            "Philippians, and Exodus"
        ),
        "criterion": (
            "Retain secondary keywords only when they identify meaningful "
            "supporting subjects, people, texts, places, or concepts; remove "
            "lists, passing examples, unrelated promotions, and ambiguous names."
        ),
        "auditedKeywords": [
            *EXPECTED_BEFORE,
            "Constantine von Tischendorf",
        ],
        "summary": {
            "requestedKeywordsAudited": len(EXPECTED_BEFORE),
            "linksReviewed": sum(EXPECTED_BEFORE.values()),
            "linksRemoved": sum(len(value) for value in removed_records.values()),
            "linksAdded": sum(len(value) for value in added_records.values()),
            "keywordsRetired": 0,
        },
        "audits": audits,
        "disambiguatedReplacements": [
            {
                "keyword": keyword,
                "added": len(records),
                "posts": records,
            }
            for keyword, records in added_records.items()
        ],
    }

    write_json(INDEX_PATH, posts)
    write_json(AUDIT_PATH, audit)

    print(json.dumps(audit["summary"], indent=2))
    for item in audits:
        print(
            f"{item['keyword']}: {item['before']} -> {item['final']} "
            f"({item['removed']} removed)"
        )
    for keyword, records in added_records.items():
        print(f"{keyword}: {len(records)} precise assignment added")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
