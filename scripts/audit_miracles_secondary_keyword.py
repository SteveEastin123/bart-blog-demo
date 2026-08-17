"""Apply approved removals for the Miracles secondary keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "miracles_secondary_keyword_audit.json"
KEYWORD = "Miracles"

PASSING_CONTRAST = {"28977", "14876", "7311"}
INCIDENTAL_CONTEXT = {"47008", "17247", "13608", "4924", "30220"}
SUBJECT_LIST = {"15609"}
APPROVED_REMOVALS = PASSING_CONTRAST | INCIDENTAL_CONTEXT | SUBJECT_LIST


def removal_reason(wp_id: str) -> str:
    if wp_id in PASSING_CONTRAST:
        return (
            "Miracles appear only in a passing contrast between Jesus' deeds and "
            "the message being analyzed; the post does not examine miracles."
        )
    if wp_id in INCIDENTAL_CONTEXT:
        return (
            "Miracles supply brief narrative, theological, or illustrative context, "
            "but the post analyzes another subject."
        )
    if wp_id in SUBJECT_LIST:
        return (
            "Miracles are only one item in a broad list of subjects covered by "
            "the interview."
        )
    raise RuntimeError(f"No removal reason configured for post {wp_id}")


def main() -> None:
    if len(APPROVED_REMOVALS) != 9:
        raise RuntimeError("Expected 9 approved Miracles removals")

    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    known_ids = {str(post.get("wpId", "")) for post in posts}
    unknown = APPROVED_REMOVALS - known_ids
    if unknown:
        raise RuntimeError("Unknown post IDs: " + ", ".join(sorted(unknown)))

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

    after = sum(KEYWORD in post.get("secondaryKeywords", []) for post in posts)
    INDEX_PATH.write_text(
        json.dumps(posts, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    AUDIT_PATH.write_text(
        json.dumps(
            {
                "keyword": KEYWORD,
                "criterion": (
                    "Retain Miracles when miracle stories, supernatural claims, "
                    "historical evaluation, Gospel signs, miracle workers, magic, "
                    "conversion, or related beliefs materially support the post."
                ),
                "approvedRemovalPostIds": sorted(APPROVED_REMOVALS),
                "before": before,
                "retained": after,
                "removed": len(removed),
                "previouslyRemoved": len(APPROVED_REMOVALS) - len(removed),
                "removalCounts": {
                    "passingContrast": len(PASSING_CONTRAST),
                    "incidentalContext": len(INCIDENTAL_CONTEXT),
                    "subjectList": len(SUBJECT_LIST),
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
