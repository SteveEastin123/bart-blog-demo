"""Apply approved removals for the Gospel of Mark secondary keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "gospel_of_mark_secondary_keyword_audit.json"
KEYWORD = "Gospel of Mark"

APPROVED_REMOVALS = {
    "49197",  # Mark Goodacre and a passing list of Gospel authors
    "27246",  # Mark Twain rather than the Gospel of Mark
    "19669",  # Mark occurs only in a generic list of Gospel authors
    "2492",   # Mark is a brief analogy for Luke's use of sources
    "40016",  # Mark appears only in a generic list of canonical Gospel names
    "34033",  # Mark appears only in a generic authorship illustration
    "21281",  # Mark appears only in a generic list contrasting Thomas
    "5149",   # Mark appears only in a generic list of anonymous Gospels
    "40440",  # Mark is an incidental analogy in a post about Paul and Acts
    "35872",  # Mark is an incidental analogy for Luke's use of sources
    "33569",  # Mark appears only in generic source-method examples
    "7081",   # Mark appears only in a list of previously illustrated methods
    "7026",   # Mark appears only in a list of previously illustrated methods
    "15701",  # Mark appears only in a generic introduction about reading books separately
    "3453",   # Mark appears only in a generic introduction about reading books separately
    "32674",  # Most Mark references concern Mark Antony, not the Gospel
    "8897",   # Mark refers to Mark Goodacre rather than the Gospel
    "4747",   # Mark is a generic Gospel name and a different person in Colossians
    "38088",  # Mark appears only in a course announcement aside
    "34764",  # Mark appears only in promotional descriptions of other courses
    "17276",  # First-century Mark is incidental to a Dead Sea Scrolls controversy
    "37411",  # Mark 6 is a single incidental citation in a Thomasine discussion
    "28151",  # Mark 9 is a single incidental citation about children
    "15444",  # Mark 6 is a single incidental citation in a Thomasine discussion
    "15046",  # Mark appears only in a broad Synoptic-Gospel aside
    "7411",   # Mark 6 is a single incidental citation in a Thomasine discussion
    "9177",   # Mark is only a brief comparison in a post about Luke
}


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    known_post_ids = {str(post.get("wpId", "")) for post in posts}
    unknown = APPROVED_REMOVALS - known_post_ids
    if unknown:
        raise RuntimeError(
            "Approved Gospel of Mark removals reference unknown posts: "
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
                "reason": (
                    "Mark is a personal name, a generic list entry, or a brief analogy "
                    "rather than a meaningful reference to the Gospel of Mark."
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
                    "Remove approved cases where Mark is a personal name, a generic "
                    "list entry, or a brief analogy rather than a meaningful reference "
                    "to the Gospel of Mark."
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
