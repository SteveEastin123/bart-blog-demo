"""Apply approved removals for the Textual Criticism secondary keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = (
    ROOT / "data" / "audits" / "textual_criticism_secondary_keyword_audit.json"
)
KEYWORD = "Textual Criticism"

APPROVED_REMOVALS = {
    "2120",
    "2218",
    "2533",
    "7352",
    "7381",
    "7420",
    "9207",
    "10250",
    "11859",
    "12525",
    "26101",
    "32879",
    "47178",
}

REMOVAL_REASONS = {
    "2120": (
        "Textual criticism appears only in the name and description of a "
        "journal whose editorial board Bart served on."
    ),
    "2218": (
        "Textual criticism describes Bart's scholarly background in an "
        "interview concerned with the historical existence of Jesus."
    ),
    "2533": (
        "Textual criticism appears while identifying one of Bart's scholarly "
        "books in a post about writing and publishing."
    ),
    "7352": (
        "The post explicitly turns away from textual criticism to discuss a "
        "media interview about How Jesus Became God."
    ),
    "7381": (
        "Textual criticism receives brief historical context before the post "
        "turns to oral tradition and form criticism."
    ),
    "7420": (
        "Textual criticism supplies biographical background about Larry "
        "Hurtado and Bart in a discussion centered on Christology."
    ),
    "9207": (
        "The discipline is explained only because criticism of Jesus Before "
        "the Gospels appeared in a textual-criticism forum."
    ),
    "10250": (
        "A reader uses the phrase while asking what critical scholar means; "
        "the response does not discuss textual criticism."
    ),
    "11859": (
        "Textual criticism appears only in a transition away from an earlier "
        "series and into a discussion of the Roman Empire."
    ),
    "12525": (
        "Textual criticism is mentioned as Bart's original specialization in "
        "a post about preparation for teaching."
    ),
    "26101": (
        "Textual criticism describes Bart's scholarly background in an "
        "interview about why he wrote Did Jesus Exist?"
    ),
    "32879": (
        "Textual criticism occurs in bibliographic annotations rather than "
        "as a subject discussed by the post."
    ),
    "47178": (
        "Textual criticism appears only as an example of differing scholarly "
        "interests in a post about the chronology of Paul's life and letters."
    ),
}


def main() -> None:
    if len(APPROVED_REMOVALS) != 13:
        raise RuntimeError("Expected 13 approved Textual Criticism removals")

    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    known_post_ids = {str(post.get("wpId", "")) for post in posts}
    unknown = APPROVED_REMOVALS - known_post_ids
    if unknown:
        raise RuntimeError(
            "Approved Textual Criticism removals reference unknown posts: "
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
                    "Retain Textual Criticism when manuscripts, textual "
                    "variants, scribal alteration, reconstruction of the "
                    "earliest attainable text, or textual-critical methods "
                    "materially support the post."
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
