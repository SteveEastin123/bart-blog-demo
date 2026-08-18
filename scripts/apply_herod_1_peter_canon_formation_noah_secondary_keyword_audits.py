#!/usr/bin/env python3
"""Apply the approved Herod, 1 Peter, Canon Formation, and Noah audits."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = (
    ROOT
    / "data"
    / "audits"
    / "herod_1_peter_canon_formation_noah_secondary_keyword_audit.json"
)

EXPECTED_BEFORE = {
    "Herod": 40,
    "1 Peter": 39,
    "Canon Formation": 39,
    "Noah": 39,
}

HEROD_THE_GREAT = {
    "47257",
    "35685",
    "34100",
    "26454",
    "12690",
    "16304",
    "11613",
    "4218",
    "3589",
    "2405",
    "2304",
}

HEROD_ANTIPAS = {
    "49865",
    "38881",
    "34912",
    "33902",
    "16907",
    "21311",
    "15633",
    "15334",
    "13773",
    "12667",
    "11959",
    "9175",
    "8307",
    "3073",
    "3069",
}

HEROD_AGRIPPA_I = {"9393"}

HEROD_REMOVE_ONLY = {
    "48832",
    "48005",
    "41375",
    "39865",
    "38658",
    "35354",
    "33317",
    "28350",
    "19669",
    "17034",
    "10649",
    "8138",
    "7627",
}

PETER_THE_APOSTLE = {"11921", "9395", "7185"}

FIRST_PETER_REMOVE_ONLY = {
    "46934",
    "28254",
    "13072",
    "6587",
    "6578",
    "6145",
}

NOAH_REMOVALS = {
    "40864",
    "34826",
    "31240",
    "24393",
    "23078",
    "17642",
    "17376",
    "15559",
    "14935",
    "12898",
    "4117",
}

REMOVALS = {
    "Herod": (
        HEROD_THE_GREAT
        | HEROD_ANTIPAS
        | HEROD_AGRIPPA_I
        | HEROD_REMOVE_ONLY
    ),
    "1 Peter": PETER_THE_APOSTLE | FIRST_PETER_REMOVE_ONLY,
    "Canon Formation": set(),
    "Noah": NOAH_REMOVALS,
}

REMOVAL_REASONS = {
    "Herod": (
        "The generic name is either ambiguous among members of the Herodian "
        "dynasty or appears only as an incidental example; precise replacements "
        "are recorded separately where the ruler is a meaningful subject."
    ),
    "1 Peter": (
        "The reference concerns Peter the person, or the letter appears only in "
        "a citation, list, quiz, syllabus, or passing comparison rather than as "
        "a meaningful supporting text."
    ),
    "Noah": (
        "Noah appears only in an ancestor list, brief analogy, humorous phrase, "
        "or one example among many and is not a meaningful supporting subject."
    ),
}

REPLACEMENTS = {
    "Herod the Great": {
        wp_id: (
            "The post meaningfully discusses Herod the Great or uses his reign, "
            "death, building program, or role in Matthew as substantive context."
        )
        for wp_id in HEROD_THE_GREAT
    },
    "Herod Antipas": {
        wp_id: (
            "The ruler discussed in the trial, death, Galilean, or Gospel of "
            "Peter context is specifically Herod Antipas."
        )
        for wp_id in HEROD_ANTIPAS
    },
    "Herod Agrippa I": {
        "9393": (
            "The Acts ruler connected with the death of James son of Zebedee is "
            "specifically Herod Agrippa I."
        )
    },
    "Peter the Apostle": {
        wp_id: (
            "The post discusses Peter's resurrection experience rather than the "
            "canonical letter 1 Peter."
        )
        for wp_id in PETER_THE_APOSTLE
    },
}

EXPECTED_AFTER = {
    "Herod": 0,
    "1 Peter": 30,
    "Canon Formation": 39,
    "Noah": 28,
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

    if len(REMOVALS["Herod"]) != EXPECTED_BEFORE["Herod"]:
        raise ValueError("Herod decisions do not cover all 40 assignments")

    target_ids = set().union(*REMOVALS.values())
    for replacements in REPLACEMENTS.values():
        target_ids.update(replacements)
    unknown_ids = target_ids - set(by_id)
    if unknown_ids:
        raise ValueError(f"Unknown post IDs: {sorted(unknown_ids)}")

    replacement_counts_before = {
        keyword: sum(keyword in post.get("secondaryKeywords", []) for post in posts)
        for keyword in REPLACEMENTS
    }

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
        for wp_id, reason in sorted(replacements.items(), key=lambda item: int(item[0])):
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
        keyword: sum(keyword in post.get("secondaryKeywords", []) for post in posts)
        for keyword in EXPECTED_AFTER
    }
    if final_counts != EXPECTED_AFTER:
        raise ValueError(
            f"Unexpected final counts: {final_counts}; expected {EXPECTED_AFTER}"
        )

    replacement_counts_after = {
        keyword: sum(keyword in post.get("secondaryKeywords", []) for post in posts)
        for keyword in REPLACEMENTS
    }
    for keyword, records in added_records.items():
        expected = replacement_counts_before[keyword] + len(records)
        if replacement_counts_after[keyword] != expected:
            raise ValueError(
                f"Unexpected {keyword!r} count: {replacement_counts_after[keyword]}; "
                f"expected {expected}"
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
            "All existing assignments for Herod, 1 Peter, Canon Formation, and Noah"
        ),
        "criterion": (
            "Retain secondary keywords only when they identify meaningful "
            "supporting subjects, people, texts, places, or concepts; replace "
            "ambiguous people with precise identities and remove passing mentions."
        ),
        "auditedKeywords": list(EXPECTED_BEFORE),
        "summary": {
            "requestedKeywordsAudited": len(EXPECTED_BEFORE),
            "linksReviewed": sum(EXPECTED_BEFORE.values()),
            "linksRemoved": sum(len(value) for value in removed_records.values()),
            "linksAdded": sum(len(value) for value in added_records.values()),
            "keywordsRetired": 1,
        },
        "audits": audits,
        "disambiguatedReplacements": [
            {
                "keyword": keyword,
                "before": replacement_counts_before[keyword],
                "added": len(records),
                "final": replacement_counts_after[keyword],
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
        print(f"{keyword}: {len(records)} precise assignments added")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
