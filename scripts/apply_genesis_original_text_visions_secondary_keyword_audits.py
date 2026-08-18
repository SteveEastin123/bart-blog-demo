#!/usr/bin/env python3
"""Apply the approved Genesis, Original Text, and Visions keyword audits."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDITS_DIR = ROOT / "data" / "audits"

AUDITS = {
    "Genesis": {
        "criterion": (
            "Retain Genesis when the book, its narratives, its composition, or its "
            "interpretation is a meaningful supporting subject rather than a passing "
            "citation, list entry, or comparison."
        ),
        "remove": {
            "12447",
            "15494",
            "25705",
            "25886",
            "2609",
            "3495",
            "38977",
            "40849",
            "47215",
            "47266",
            "48266",
            "48695",
            "48687",
            "4339",
            "16649",
            "33431",
        },
        "add": set(),
        "before": 57,
        "after": 41,
        "removalReason": (
            "Genesis appears only as a passing citation, a book-list or textbook "
            "reference, or background comparison rather than a meaningful supporting "
            "subject."
        ),
    },
    "Original Text": {
        "criterion": (
            "Retain Original Text when identifying or reconstructing an author's "
            "original wording or textual form is a meaningful supporting subject."
        ),
        "remove": {"7381", "8922", "9207"},
        "add": set(),
        "before": 53,
        "after": 50,
        "removalReason": (
            "The original text is only introductory background or is explicitly "
            "unrelated to the post's substantive subject."
        ),
    },
    "Visions": {
        "criterion": (
            "Retain Visions for meaningful discussion of dreams, revelations, or "
            "visionary experiences, not figurative uses meaning outlook or conception."
        ),
        "remove": {"15937"},
        "add": {
            "11680",
            "11688",
            "12813",
            "15998",
            "16038",
            "34847",
            "46916",
            "47121",
            "47283",
            "47853",
        },
        "before": 53,
        "after": 62,
        "removalReason": (
            "The title uses visions figuratively for an outlook on the end times rather "
            "than a visionary experience."
        ),
        "additionReason": (
            "Dreams, revelations, or visionary experiences are a substantial part of "
            "the post's discussion."
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


def audit_filename(keyword: str) -> str:
    return keyword.casefold().replace(" ", "_") + "_secondary_keyword_audit.json"


def post_record(post: dict, reason: str) -> dict:
    return {
        "wpId": str(post.get("wpId")),
        "title": post.get("title"),
        "topics": post.get("topics", []),
        "reason": reason,
    }


def main() -> int:
    posts = load_json(POSTS_PATH)
    by_id = {str(post.get("wpId")): post for post in posts}

    for keyword, config in AUDITS.items():
        before_posts = [
            post for post in posts if keyword in post.get("secondaryKeywords", [])
        ]
        if len(before_posts) != config["before"]:
            raise ValueError(
                f"Expected {config['before']} {keyword!r} assignments; "
                f"found {len(before_posts)}"
            )

        current_ids = {str(post.get("wpId")) for post in before_posts}
        missing_removals = config["remove"] - current_ids
        unexpected_additions = config["add"] & current_ids
        missing_posts = (config["remove"] | config["add"]) - set(by_id)
        if missing_removals or unexpected_additions or missing_posts:
            raise ValueError(
                f"Unexpected {keyword!r} assignments: missing removals "
                f"{sorted(missing_removals)}, existing additions "
                f"{sorted(unexpected_additions)}, missing posts {sorted(missing_posts)}"
            )

        removed_records = []
        added_records = []
        for wp_id in sorted(config["remove"]):
            post = by_id[wp_id]
            post["secondaryKeywords"] = [
                value
                for value in post.get("secondaryKeywords", [])
                if value != keyword
            ]
            removed_records.append(post_record(post, config["removalReason"]))

        for wp_id in sorted(config["add"]):
            post = by_id[wp_id]
            post.setdefault("secondaryKeywords", []).append(keyword)
            post["secondaryKeywords"] = sorted(
                set(post["secondaryKeywords"]), key=str.casefold
            )
            added_records.append(post_record(post, config["additionReason"]))

        after_posts = [
            post for post in posts if keyword in post.get("secondaryKeywords", [])
        ]
        if len(after_posts) != config["after"]:
            raise ValueError(
                f"Expected {config['after']} final {keyword!r} assignments; "
                f"found {len(after_posts)}"
            )

        audit = {
            "keyword": keyword,
            "criterion": config["criterion"],
            "before": len(before_posts),
            "retained": len(after_posts) - len(added_records),
            "removed": len(removed_records),
            "added": len(added_records),
            "after": len(after_posts),
            "removedPosts": removed_records,
            "addedPosts": added_records,
        }
        write_json(AUDITS_DIR / audit_filename(keyword), audit)
        print(
            f"{keyword}: {len(before_posts)} -> {len(after_posts)} "
            f"({len(removed_records)} removed, {len(added_records)} added)"
        )

    duplicate_keywords = [
        str(post.get("wpId"))
        for post in posts
        if len(post.get("secondaryKeywords", []))
        != len({value.casefold().strip() for value in post.get("secondaryKeywords", [])})
    ]
    overlaps = [
        str(post.get("wpId"))
        for post in posts
        if {value.casefold().strip() for value in post.get("topics", [])}
        & {
            value.casefold().strip()
            for value in post.get("secondaryKeywords", [])
        }
    ]
    if duplicate_keywords or overlaps:
        raise ValueError(
            f"Validation failed: duplicate keywords {duplicate_keywords[:5]}, "
            f"topic/keyword overlaps {overlaps[:5]}"
        )

    write_json(POSTS_PATH, posts)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
