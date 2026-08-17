"""Apply approved removals for the Son of Man secondary keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "son_of_man_secondary_keyword_audit.json"
KEYWORD = "Son of Man"

BOOK_TITLE_REFERENCES = {"2043", "8497"}
PASSING_OR_PREVIOUS_REFERENCES = {"7434", "15577", "26081", "38524"}
UNFORGIVABLE_SIN_QUOTATIONS = {"15060", "21159", "26033", "34899"}
GENERIC_PSALM_USAGE = {"12138", "38016"}
APPROVED_REMOVALS = (
    BOOK_TITLE_REFERENCES
    | PASSING_OR_PREVIOUS_REFERENCES
    | UNFORGIVABLE_SIN_QUOTATIONS
    | GENERIC_PSALM_USAGE
)


def removal_reason(wp_id: str) -> str:
    if wp_id in BOOK_TITLE_REFERENCES:
        return (
            "Son of Man appears only in the title of Robert Price's book "
            "The Incredible Shrinking Son of Man."
        )
    if wp_id in PASSING_OR_PREVIOUS_REFERENCES:
        return (
            "Son of Man appears only in a passing example, reference to a "
            "different post, list of titles, or unrelated quoted passage."
        )
    if wp_id in UNFORGIVABLE_SIN_QUOTATIONS:
        return (
            "Son of Man appears only in the quoted saying contrasting speech "
            "against the Son of Man with blasphemy against the Holy Spirit."
        )
    if wp_id in GENERIC_PSALM_USAGE:
        return (
            "Psalm 8 uses lowercase son of man to mean an ordinary human "
            "being rather than the apocalyptic or Christological title."
        )
    raise RuntimeError(f"No removal reason configured for post {wp_id}")


def main() -> None:
    if len(APPROVED_REMOVALS) != 12:
        raise RuntimeError("Expected 12 approved Son of Man removals")

    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    known_post_ids = {str(post.get("wpId", "")) for post in posts}
    unknown = APPROVED_REMOVALS - known_post_ids
    if unknown:
        raise RuntimeError(
            "Approved Son of Man removals reference unknown posts: "
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
                    "Retain Son of Man when the title's meaning, identity, "
                    "Danielic background, Gospel usage, historical-Jesus "
                    "question, eschatological role, or textual interpretation "
                    "materially supports the post."
                ),
                "approvedRemovalPostIds": sorted(APPROVED_REMOVALS),
                "before": before,
                "retained": after,
                "removed": len(removed),
                "previouslyRemoved": len(APPROVED_REMOVALS) - len(removed),
                "removalCounts": {
                    "bookTitleReferences": len(BOOK_TITLE_REFERENCES),
                    "passingOrPreviousReferences": len(
                        PASSING_OR_PREVIOUS_REFERENCES
                    ),
                    "unforgivableSinQuotations": len(
                        UNFORGIVABLE_SIN_QUOTATIONS
                    ),
                    "genericPsalmUsage": len(GENERIC_PSALM_USAGE),
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
