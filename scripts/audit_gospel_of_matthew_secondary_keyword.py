"""Apply approved removals for the Gospel of Matthew secondary keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "gospel_of_matthew_secondary_keyword_audit.json"
KEYWORD = "Gospel of Matthew"

UNSUPPORTED_REMOVALS = {"39489", "33005", "9098", "4906", "3488"}

GENERIC_REMOVALS = {
    "49197", "48982", "40016", "39578", "35720", "34033", "32529",
    "31763", "21281", "21223", "19669", "9167", "5149",
}

PROMOTIONAL_REMOVALS = {
    "34241", "32874", "24108", "9061", "8417", "6578", "4513",
    "47056",
}

TANGENTIAL_REMOVALS = {
    "40661", "29856", "15705", "15635", "15046", "8821", "7081",
    "7026", "4747",
}

APPROVED_REMOVALS = (
    UNSUPPORTED_REMOVALS
    | GENERIC_REMOVALS
    | PROMOTIONAL_REMOVALS
    | TANGENTIAL_REMOVALS
)


def removal_reason(wp_id: str) -> str:
    if wp_id in UNSUPPORTED_REMOVALS:
        return "The available full post text does not support the Gospel of Matthew keyword."
    if wp_id in GENERIC_REMOVALS:
        return (
            "Matthew appears only in a generic Gospel list, analogy, or unrelated "
            "example rather than as a meaningful supporting subject."
        )
    if wp_id in PROMOTIONAL_REMOVALS:
        return (
            "Matthew appears only in promotional, teaching, syllabus, or other "
            "administrative material."
        )
    return (
        "Matthew appears only in a transition, citation, or brief methodological "
        "reference unrelated to the post's substantive focus."
    )


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    known_post_ids = {str(post.get("wpId", "")) for post in posts}
    unknown = APPROVED_REMOVALS - known_post_ids
    if unknown:
        raise RuntimeError(
            "Approved Gospel of Matthew removals reference unknown posts: "
            + ", ".join(sorted(unknown))
        )

    before = sum(KEYWORD in post.get("secondaryKeywords", []) for post in posts)
    removed = []
    for post in posts:
        wp_id = str(post.get("wpId", ""))
        if wp_id not in APPROVED_REMOVALS:
            continue
        if KEYWORD not in post.get("secondaryKeywords", []):
            continue

        post["secondaryKeywords"] = [
            keyword
            for keyword in post.get("secondaryKeywords", [])
            if keyword != KEYWORD
        ]
        removed.append(
            {
                "wpId": wp_id,
                "title": post.get("title", ""),
                "topics": post.get("topics", []),
                "reason": removal_reason(wp_id),
            }
        )

    after = before - len(removed)
    INDEX_PATH.write_text(
        json.dumps(posts, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    AUDIT_PATH.write_text(
        json.dumps(
            {
                "keyword": KEYWORD,
                "criterion": (
                    "Remove approved cases unsupported by the full text or where "
                    "Matthew appears only generically, promotionally, or tangentially."
                ),
                "approvedRemovalPostIds": sorted(APPROVED_REMOVALS),
                "before": before,
                "retained": after,
                "removed": len(removed),
                "previouslyRemoved": len(APPROVED_REMOVALS) - len(removed),
                "removalCounts": {
                    "unsupported": len(UNSUPPORTED_REMOVALS),
                    "generic": len(GENERIC_REMOVALS),
                    "promotionalOrAdministrative": len(PROMOTIONAL_REMOVALS),
                    "tangential": len(TANGENTIAL_REMOVALS),
                },
                "removedPosts": removed,
            },
            ensure_ascii=False,
            indent=2,
        )
        + "\n",
        encoding="utf-8",
    )
    print(f"{KEYWORD}: {before} -> {after} ({len(removed)} removed)")


if __name__ == "__main__":
    main()
