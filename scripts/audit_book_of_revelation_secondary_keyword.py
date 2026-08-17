"""Apply approved removals for the Book of Revelation secondary keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "book_of_revelation_secondary_keyword_audit.json"
KEYWORD = "Book of Revelation"

APPROVED_REMOVALS = {
    "11529", "11533", "11729", "11738", "11961", "12004", "12563",
    "12605", "12633", "12636", "12665", "12854", "13419", "13934",
    "15263", "15398", "15494", "15609", "15701", "21061", "22087",
    "2328", "2374", "24315", "2551", "25931", "2609", "26915",
    "27056", "27073", "28666", "28747", "28809", "32665", "33974",
    "3453", "34678", "34826", "37317", "38306", "39754", "40576",
    "40849", "46991", "47177", "47258", "47266", "48021", "4821",
    "48295", "48864", "48954", "50165", "50192", "7031", "7260",
    "7266", "7469", "8862", "8993",
}


def main() -> None:
    if len(APPROVED_REMOVALS) != 60:
        raise RuntimeError("Expected 60 approved Book of Revelation removals")

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
                "reason": (
                    "The biblical Book of Revelation is only incidental, listed "
                    "as an example, or confused with revelation in a generic sense."
                ),
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
                    "Retain Book of Revelation when its authorship, date, canon "
                    "status, imagery, theology, textual history, interpretation, "
                    "modern influence, or related research materially supports "
                    "the post."
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
    print(f"{KEYWORD}: {before} -> {after} ({len(removed)} removed)")


if __name__ == "__main__":
    main()
