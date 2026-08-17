"""Apply approved removals for the Salvation secondary keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "salvation_secondary_keyword_audit.json"
KEYWORD = "Salvation"

APPROVED_REMOVALS = {
    "4709",
    "6958",
    "15334",
    "16103",
    "28517",
    "32825",
    "48685",
}

UNRELATED_PREVIEWS = {"28517", "32825", "48685"}
INCIDENTAL_REFERENCES = {"4709", "15334", "16103"}
FALSE_TITLE_MATCHES = {"6958"}


def removal_reason(wp_id: str) -> str:
    if wp_id in UNRELATED_PREVIEWS:
        return (
            "Salvation appears only while previewing or listing a different "
            "subject and is not developed in the post."
        )
    if wp_id in INCIDENTAL_REFERENCES:
        return (
            "Salvation is used only as an incidental example or to explain "
            "what the post's actual subject does not address."
        )
    if wp_id in FALSE_TITLE_MATCHES:
        return (
            "The match comes from the television title Snake Salvation rather "
            "than a discussion of salvation."
        )
    raise RuntimeError(f"No removal reason configured for post {wp_id}")


def main() -> None:
    if len(APPROVED_REMOVALS) != 7:
        raise RuntimeError("Expected 7 approved Salvation removals")

    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    known_post_ids = {str(post.get("wpId", "")) for post in posts}
    unknown = APPROVED_REMOVALS - known_post_ids
    if unknown:
        raise RuntimeError(
            "Approved Salvation removals reference unknown posts: "
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
                    "Retain Salvation when the means, meaning, recipients, "
                    "timing, or theology of salvation is a meaningful "
                    "supporting subject of the post."
                ),
                "approvedRemovalPostIds": sorted(APPROVED_REMOVALS),
                "before": before,
                "retained": after,
                "removed": len(removed),
                "previouslyRemoved": len(APPROVED_REMOVALS) - len(removed),
                "removalCounts": {
                    "unrelatedPreviews": len(UNRELATED_PREVIEWS),
                    "incidentalReferences": len(INCIDENTAL_REFERENCES),
                    "falseTitleMatches": len(FALSE_TITLE_MATCHES),
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
