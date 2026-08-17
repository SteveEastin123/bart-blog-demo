"""Apply approved removals for the Messiah secondary keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "messiah_secondary_keyword_audit.json"
KEYWORD = "Messiah"

APPROVED_REMOVALS = {
    "1970",
    "20556",
    "2106",
    "21081",
    "21924",
    "9340",
    "11640",
    "14935",
    "15239",
    "15577",
    "15609",
    "23948",
    "24258",
    "32879",
    "38242",
    "47045",
    "48712",
}

REMOVAL_REASONS = {
    "1970": (
        "Messiah appears only in a reader's hypothetical reconstruction of "
        "the disciples' reasoning about the resurrection."
    ),
    "20556": (
        "All occurrences are in citations of Raymond Brown's The Death of "
        "the Messiah rather than in the post's discussion of Judas's name."
    ),
    "2106": (
        "Jesus being acclaimed as messiah is only one example in a "
        "description of an undergraduate New Testament course."
    ),
    "21081": (
        "Messiah appears only in a list of titles distinguished from the "
        "Son of Man designation examined by the post."
    ),
    "21924": (
        "Messiah appears only in a reference back to an earlier discussion "
        "of Matthew's genealogy."
    ),
    "9340": (
        "Messiah occurs in the reader's question, while the response concerns "
        "recurring apocalyptic expectations more broadly."
    ),
    "11640": (
        "Messiah occurs in Josephus's identifying phrase about Jesus rather "
        "than in a discussion of messianic beliefs."
    ),
    "14935": (
        "The phrase their own messiah occurs once while explaining the growth "
        "of anti-Jewish blame in a non-canonical Pilate tradition."
    ),
    "15239": (
        "All occurrences are in citations of Raymond Brown's The Death of "
        "the Messiah rather than in the post's discussion of Judas's name."
    ),
    "15577": (
        "The post briefly notes that Jesus acknowledges being the messiah "
        "before concentrating on whether he claims to be God."
    ),
    "15609": (
        "Messiah is merely one item in a long list of subjects discussed in "
        "the podcast interview."
    ),
    "23948": (
        "Messiah occurs primarily in the advertised lecture title rather "
        "than in a substantive discussion."
    ),
    "24258": (
        "Human messiah is only one element in a brief list of increasingly "
        "exalted understandings of Jesus."
    ),
    "32879": (
        "The occurrences are confined to titles and annotations for Raymond "
        "Brown's books in a suggested-reading list."
    ),
    "38242": (
        "Messiah appears in summaries of fictional thriller plots rather "
        "than as a historical or theological subject."
    ),
    "47045": (
        "Messiah occurs once in a broad definition of the Gospel genre rather "
        "than as a sustained subject."
    ),
    "48712": (
        "Messiah appears only in compressed summaries of Matthew and Mark "
        "within a much broader survey of every New Testament book."
    ),
}


def main() -> None:
    if len(APPROVED_REMOVALS) != 17:
        raise RuntimeError("Expected 17 approved Messiah removals")

    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    known_post_ids = {str(post.get("wpId", "")) for post in posts}
    unknown = APPROVED_REMOVALS - known_post_ids
    if unknown:
        raise RuntimeError(
            "Approved Messiah removals reference unknown posts: "
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
                "reason": REMOVAL_REASONS[wp_id],
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
                    "Retain Messiah when messianic identity, expectations, "
                    "claims, interpretation, or titles materially support "
                    "the post rather than serving as a passing label for Jesus."
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
