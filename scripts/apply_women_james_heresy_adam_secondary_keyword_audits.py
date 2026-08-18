#!/usr/bin/env python3
"""Apply the approved Women, James, Heresy, James-brother, and Adam audits."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
RETIREMENT_PATH = (
    ROOT / "data" / "index" / "ehrman_secondary_keyword_retirement_candidates.json"
)
AUDIT_PATH = (
    ROOT
    / "data"
    / "audits"
    / "women_james_heresy_adam_secondary_keyword_audit.json"
)

EXPECTED_BEFORE = {
    "Women": 47,
    "James": 45,
    "Heresy": 44,
    "James the Brother of Jesus": 43,
    "Adam": 42,
}

REMOVALS = {
    "Women": {"17205"},
    "James": "all",
    "Heresy": {
        "33161",
        "23479",
        "15850",
        "8954",
        "36829",
        "35986",
        "4814",
        "28558",
    },
    "James the Brother of Jesus": {
        "36908",
        "34743",
        "34094",
        "27490",
        "13608",
        "9393",
        "4255",
    },
    "Adam": {
        "46926",
        "25682",
        "11602",
        "21893",
        "17124",
        "17041",
        "8466",
    },
}

ADDITIONS = {
    "James the Brother of Jesus": {
        "38587",
        "36693",
        "35689",
        "26835",
    },
    "James, Son of Zebedee": {
        "47100",
        "34597",
        "11707",
        "8285",
    },
    "Letter of James": {
        "50192",
        "47043",
        "41262",
        "39587",
        "32663",
        "32580",
        "16656",
        "14980",
        "8000",
        "4056",
    },
    "Letter of Peter to James": {"32441"},
    "King James Version": {"15912"},
    "James White": {"6285"},
}

REASONS = {
    "Women": (
        "Women appear only within examples about divorce and adultery; the "
        "post's sustained subject is sexual ethics."
    ),
    "James": (
        "The generic name combines several biblical figures, writings, a Bible "
        "translation, modern people, and incidental name occurrences."
    ),
    "Heresy": (
        "Heresy appears only in a source designation, book title, subject list, "
        "transition, or passing remark."
    ),
    "James the Brother of Jesus": (
        "The post concerns another James or mentions Jesus' brother only in a "
        "passing list, citation, or comparison."
    ),
    "Adam": (
        "Adam is an author's name or appears only in a passing example, phrase, "
        "genealogy, or source summary."
    ),
}

ADDITION_REASONS = {
    "James the Brother of Jesus": (
        "The post meaningfully discusses James the Just, Jesus' brother, rather "
        "than an unspecified James."
    ),
    "James, Son of Zebedee": (
        "The post meaningfully discusses James son of Zebedee rather than Jesus' "
        "brother or an unspecified James."
    ),
    "Letter of James": (
        "The post meaningfully discusses the canonical Letter of James."
    ),
    "Letter of Peter to James": (
        "The post identifies the non-canonical Letter of Peter to James."
    ),
    "King James Version": (
        "The reference is to the King James Version rather than a person named "
        "James."
    ),
    "James White": (
        "The post meaningfully discusses Bart's debate with James White."
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


def normalized(value: str) -> str:
    return value.casefold().strip()


def main() -> int:
    posts = load_json(INDEX_PATH)
    by_id = {str(post["wpId"]): post for post in posts}
    before = {
        keyword: [
            post
            for post in posts
            if keyword in post.get("secondaryKeywords", [])
        ]
        for keyword in EXPECTED_BEFORE
    }

    for keyword, expected in EXPECTED_BEFORE.items():
        actual = len(before[keyword])
        if actual != expected:
            raise ValueError(
                f"Expected {expected} {keyword!r} assignments; found {actual}"
            )

    resolved_removals: dict[str, set[str]] = {}
    for keyword, targets in REMOVALS.items():
        if targets == "all":
            resolved_removals[keyword] = {
                str(post["wpId"]) for post in before[keyword]
            }
        else:
            resolved_removals[keyword] = set(targets)

    all_target_ids = set().union(*resolved_removals.values(), *ADDITIONS.values())
    unknown_ids = all_target_ids - set(by_id)
    if unknown_ids:
        raise ValueError(f"Unknown post IDs: {sorted(unknown_ids)}")

    removed_records: dict[str, list[dict]] = {}
    for keyword, post_ids in resolved_removals.items():
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
                    "reason": REASONS[keyword],
                }
            )
        removed_records[keyword] = records

    added_records: dict[str, list[dict]] = {}
    for keyword, post_ids in ADDITIONS.items():
        records = []
        key = normalized(keyword)
        for wp_id in sorted(post_ids, key=int):
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
                    "reason": ADDITION_REASONS[keyword],
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
        for keyword in set(EXPECTED_BEFORE) | set(ADDITIONS)
    }
    expected_final = {
        "Women": 46,
        "James": 0,
        "Heresy": 36,
        "James the Brother of Jesus": 40,
        "Adam": 35,
        "James, Son of Zebedee": None,
        "Letter of James": None,
        "Letter of Peter to James": None,
        "King James Version": None,
        "James White": None,
    }
    for keyword, expected in expected_final.items():
        if expected is not None and final_counts[keyword] != expected:
            raise ValueError(
                f"Expected final count {expected} for {keyword!r}; "
                f"found {final_counts[keyword]}"
            )

    retirement = load_json(RETIREMENT_PATH)
    retirement["keywords"] = sorted(
        set(retirement.get("keywords", [])).union({"James"}),
        key=str.casefold,
    )

    audits = []
    for keyword, expected in EXPECTED_BEFORE.items():
        removed = removed_records[keyword]
        added = added_records.get(keyword, [])
        audits.append(
            {
                "keyword": keyword,
                "before": expected,
                "retainedFromOriginal": expected - len(removed),
                "removed": len(removed),
                "added": len(added),
                "final": final_counts[keyword],
                "removedPosts": removed,
                "addedPosts": added,
            }
        )

    audit = {
        "auditDate": "2026-08-18",
        "scope": (
            "All existing assignments for Women, James, Heresy, James the "
            "Brother of Jesus, and Adam"
        ),
        "criterion": (
            "Retain secondary keywords only when they identify meaningful "
            "supporting subjects, people, texts, places, or concepts; remove "
            "passing mentions and name collisions, and replace ambiguous James "
            "assignments only when the exact referent is meaningful."
        ),
        "auditedKeywords": list(EXPECTED_BEFORE),
        "summary": {
            "keywordsAudited": len(EXPECTED_BEFORE),
            "linksReviewed": sum(EXPECTED_BEFORE.values()),
            "linksRemoved": sum(len(value) for value in removed_records.values()),
            "linksAdded": sum(len(value) for value in added_records.values()),
            "keywordsRetired": 1,
            "retiredKeywords": ["James"],
        },
        "audits": audits,
        "preciseJamesReplacements": [
            {
                "keyword": keyword,
                "added": len(records),
                "posts": records,
            }
            for keyword, records in added_records.items()
        ],
    }

    write_json(INDEX_PATH, posts)
    write_json(RETIREMENT_PATH, retirement)
    write_json(AUDIT_PATH, audit)

    print(json.dumps(audit["summary"], indent=2))
    for item in audits:
        print(
            f"{item['keyword']}: {item['before']} -> {item['final']} "
            f"({item['removed']} removed, {item['added']} added)"
        )
    for keyword, records in added_records.items():
        if keyword not in EXPECTED_BEFORE:
            print(f"{keyword}: {len(records)} precise assignments added")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
