"""Apply approved high-confidence removals for the Paul secondary keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "paul_secondary_keyword_audit.json"
KEYWORD = "Paul"

# Paul appears only as a brief analogy, quotation, list item, or comparison in
# these posts rather than as a meaningful subject or supporting search term.
APPROVED_REMOVALS = {
    "48426",  # The Letter of Polycarp to the Philippians...
    "47590",  # Book of Jude: Who Wrote it? When? And Why? (part 1)
    "47140",  # Guest Post by Dr. Paula Fredriksen Part II...
    "32404",  # You Don't Think Peter Wrote 1 and 2 Peter?
    "16372",  # The Brother of Jesus and the Book of James
    "49016", "47593", "36683", "33028", "33026", "29103", "28517",
    "27266", "29175", "17217", "16549", "16211", "16103", "15738",
    "13229", "11931", "11280", "11201", "10254", "9413", "8821",
    "8626", "8042", "8039", "6875", "6332", "3666", "2611", "2059",
    "2036", "1993", "2422", "15178", "2624", "8417", "21126",
    "47061", "21099", "2023", "32964", "11748", "2000", "32955",
}


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    before = sum(KEYWORD in post.get("secondaryKeywords", []) for post in posts)
    removed = []
    known_post_ids = {str(post.get("wpId", "")) for post in posts}
    unknown = APPROVED_REMOVALS - known_post_ids
    if unknown:
        raise RuntimeError(
            "Approved Paul removals reference unknown posts: "
            + ", ".join(sorted(unknown))
        )

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
                "reason": (
                    "Paul is present only as a brief analogy, quotation, or comparison "
                    "rather than a meaningful subject of the post."
                ),
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
                    "Remove only approved cases where Paul is a brief analogy, quotation, "
                    "list item, or comparison rather than a meaningful subject or "
                    "supporting search term."
                ),
                "approvedRemovalPostIds": sorted(APPROVED_REMOVALS),
                "before": before,
                "retained": after,
                "removed": len(removed),
                "previouslyRemoved": len(APPROVED_REMOVALS) - len(removed),
                "removedPosts": removed,
            },
            ensure_ascii=False,
            indent=2,
        )
        + "\n",
        encoding="utf-8",
    )
    print(f"Paul: {before} -> {after} ({len(removed)} removed)")


if __name__ == "__main__":
    main()
