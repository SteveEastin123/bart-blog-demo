"""Apply approved removals for the Hell secondary keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "hell_secondary_keyword_audit.json"
KEYWORD = "Hell"

APPROVED_REMOVALS = {
    "4927",
    "9413",
    "14948",
    "16028",
    "17749",
    "17833",
    "30164",
    "30170",
    "32319",
    "32441",
    "34745",
    "38990",
}

IDIOMATIC_OR_HUMOROUS_USES = {"4927", "9413", "16028", "17833", "34745"}
BOOK_TITLES_OR_CITATIONS = {"14948", "17749", "30164", "30170", "32319", "38990"}
BRIEF_BACKGROUND_REFERENCES = {"32441"}


def removal_reason(wp_id: str) -> str:
    if wp_id in IDIOMATIC_OR_HUMOROUS_USES:
        return (
            "Hell appears only in an idiom, metaphor, or brief joke rather "
            "than as a meaningful subject of the post."
        )
    if wp_id in BOOK_TITLES_OR_CITATIONS:
        return (
            "Hell appears only in a book or lecture title, source citation, "
            "or unrelated project reference."
        )
    if wp_id in BRIEF_BACKGROUND_REFERENCES:
        return (
            "Hell appears only in a brief description of another text while "
            "the post addresses a different subject."
        )
    raise RuntimeError(f"No removal reason configured for post {wp_id}")


def main() -> None:
    if len(APPROVED_REMOVALS) != 12:
        raise RuntimeError("Expected 12 approved Hell removals")

    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    known_post_ids = {str(post.get("wpId", "")) for post in posts}
    unknown = APPROVED_REMOVALS - known_post_ids
    if unknown:
        raise RuntimeError(
            "Approved Hell removals reference unknown posts: "
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
                    "Retain Hell when hell itself, punishment, its historical "
                    "development, or beliefs about its inhabitants are a "
                    "meaningful supporting subject of the post."
                ),
                "approvedRemovalPostIds": sorted(APPROVED_REMOVALS),
                "before": before,
                "retained": after,
                "removed": len(removed),
                "previouslyRemoved": len(APPROVED_REMOVALS) - len(removed),
                "removalCounts": {
                    "idiomaticOrHumorousUses": len(IDIOMATIC_OR_HUMOROUS_USES),
                    "bookTitlesOrCitations": len(BOOK_TITLES_OR_CITATIONS),
                    "briefBackgroundReferences": len(BRIEF_BACKGROUND_REFERENCES),
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
