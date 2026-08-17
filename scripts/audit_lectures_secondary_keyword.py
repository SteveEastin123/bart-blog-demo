"""Apply approved removals for the Lectures secondary keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "lectures_secondary_keyword_audit.json"
KEYWORD = "Lectures"

TRAVEL_OR_SCHEDULING = {
    "4171", "8876", "15330", "16236", "36737", "39832", "47209"
}
INCIDENTAL_MENTIONS = {
    "25141", "28082", "34764", "38171", "3853", "4295", "7197",
    "14210", "21187",
}
BIOGRAPHICAL_BACKGROUND = {"12299", "16764", "39896"}
APPROVED_REMOVALS = (
    TRAVEL_OR_SCHEDULING | INCIDENTAL_MENTIONS | BIOGRAPHICAL_BACKGROUND
)


def removal_reason(wp_id: str) -> str:
    if wp_id in TRAVEL_OR_SCHEDULING:
        return (
            "Lecturing supplies only travel, scheduling, or opening context; "
            "the post does not present or meaningfully describe a lecture."
        )
    if wp_id in INCIDENTAL_MENTIONS:
        return (
            "A lecture or course is mentioned incidentally while the post's "
            "substantive subject lies elsewhere."
        )
    if wp_id in BIOGRAPHICAL_BACKGROUND:
        return (
            "Lectures appear only as biographical background and are not a "
            "meaningful subject of the post."
        )
    raise RuntimeError(f"No removal reason configured for post {wp_id}")


def main() -> None:
    if len(APPROVED_REMOVALS) != 19:
        raise RuntimeError("Expected 19 approved Lectures removals")

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
                    "Retain Lectures for lecture announcements, recorded or "
                    "published lectures, lecture summaries, public talks, "
                    "conference presentations, lecture-based courses, or posts "
                    "substantially developed from lecture content."
                ),
                "approvedRemovalPostIds": sorted(APPROVED_REMOVALS),
                "before": before,
                "retained": after,
                "removed": len(removed),
                "previouslyRemoved": len(APPROVED_REMOVALS) - len(removed),
                "removalCounts": {
                    "travelOrScheduling": len(TRAVEL_OR_SCHEDULING),
                    "incidentalMentions": len(INCIDENTAL_MENTIONS),
                    "biographicalBackground": len(BIOGRAPHICAL_BACKGROUND),
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
