"""Apply approved removals for the Roman Empire secondary keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "roman_empire_secondary_keyword_audit.json"
KEYWORD = "Roman Empire"

PASSING_CONTEXT = {"2864", "4711", "11493", "31770"}
PERSONAL_BACKGROUND = {"11515"}
APPROVED_REMOVALS = PASSING_CONTEXT | PERSONAL_BACKGROUND


def removal_reason(wp_id: str) -> str:
    if wp_id in PASSING_CONTEXT:
        return (
            "The Roman Empire appears only as brief geographical, historical, "
            "course, or previous-thread context; the post analyzes another subject."
        )
    if wp_id in PERSONAL_BACKGROUND:
        return (
            "The Christianization of the Roman Empire identifies a research "
            "project behind a canceled trip, but the post is a personal update."
        )
    raise RuntimeError(f"No removal reason configured for post {wp_id}")


def main() -> None:
    if len(APPROVED_REMOVALS) != 5:
        raise RuntimeError("Expected 5 approved Roman Empire removals")

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
                    "Retain Roman Empire when Roman government, imperial power, "
                    "society, administration, religion, expansion, persecution, "
                    "or the empire's relationship to Jews and Christians "
                    "materially supports the post."
                ),
                "approvedRemovalPostIds": sorted(APPROVED_REMOVALS),
                "before": before,
                "retained": after,
                "removed": len(removed),
                "previouslyRemoved": len(APPROVED_REMOVALS) - len(removed),
                "removalCounts": {
                    "passingContext": len(PASSING_CONTEXT),
                    "personalBackground": len(PERSONAL_BACKGROUND),
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
