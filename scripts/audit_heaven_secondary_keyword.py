"""Apply approved removals for the Heaven secondary keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "heaven_secondary_keyword_audit.json"
KEYWORD = "Heaven"

KINGDOM_OF_HEAVEN_REMOVALS = {
    "15451", "16245", "23276", "23938", "30857", "35255", "3661",
    "36683", "37428", "4428", "4469", "47176", "8610", "8613", "8713",
}

BOOK_TITLE_ADMIN_IDIOM_REMOVALS = {
    "12693", "15502", "15609", "15798", "17749", "17833", "23678",
    "26592", "28005", "28010", "28014", "29035", "32319", "8049",
}

APPROVED_REMOVALS = {
    "12050", "12413", "12693", "12916", "14948", "15451", "15502",
    "15609", "15674", "15680", "15798", "16206", "16245", "17159",
    "17247", "17749", "17833", "21260", "22557", "22945", "23276",
    "23678", "23938", "25436", "26592", "27664", "28005", "28010",
    "28014", "28208", "29035", "2925", "30164", "30857", "32319",
    "32441", "33005", "34494", "34652", "34664", "35255", "3661",
    "36683", "36819", "37167", "37428", "38093", "4428", "4469", "47176",
    "4543", "4660", "4667", "47027", "47239", "4799", "4803", "5067",
    "6942", "7348", "7845", "8049", "8401", "8610", "8613", "8713",
    "9068", "9070", "9418",
}

INCIDENTAL_REFERENCE_REMOVALS = (
    APPROVED_REMOVALS
    - KINGDOM_OF_HEAVEN_REMOVALS
    - BOOK_TITLE_ADMIN_IDIOM_REMOVALS
)


def removal_reason(wp_id: str) -> str:
    if wp_id in KINGDOM_OF_HEAVEN_REMOVALS:
        return (
            "The post uses Matthew's expression 'kingdom of heaven' for the "
            "kingdom of God but does not discuss heaven as a destination or realm."
        )
    if wp_id in BOOK_TITLE_ADMIN_IDIOM_REMOVALS:
        return (
            "Heaven appears only in a book title, announcement, list of subjects, "
            "or idiomatic expression rather than as a meaningful supporting subject."
        )
    return (
        "Heaven appears only as an incidental location, direction, formula, or "
        "detail in a quoted narrative rather than as a meaningful supporting subject."
    )


def main() -> None:
    if len(KINGDOM_OF_HEAVEN_REMOVALS) != 15:
        raise RuntimeError("Expected 15 kingdom-of-heaven removals")
    if len(BOOK_TITLE_ADMIN_IDIOM_REMOVALS) != 14:
        raise RuntimeError("Expected 14 book-title, administrative, or idiom removals")
    if len(INCIDENTAL_REFERENCE_REMOVALS) != 40:
        raise RuntimeError("Expected 40 incidental-reference removals")

    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    known_post_ids = {str(post.get("wpId", "")) for post in posts}
    unknown = APPROVED_REMOVALS - known_post_ids
    if unknown:
        raise RuntimeError(
            "Approved Heaven removals reference unknown posts: "
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
                    "Retain Heaven where heaven, a heavenly realm, ascent, "
                    "postmortem destiny, or heavenly beings and locations materially "
                    "support the post."
                ),
                "approvedRemovalPostIds": sorted(APPROVED_REMOVALS),
                "before": before,
                "retained": after,
                "removed": len(removed),
                "previouslyRemoved": len(APPROVED_REMOVALS) - len(removed),
                "removalCounts": {
                    "kingdomOfHeaven": len(KINGDOM_OF_HEAVEN_REMOVALS),
                    "incidentalReference": len(INCIDENTAL_REFERENCE_REMOVALS),
                    "bookTitleAdministrativeOrIdiom": len(
                        BOOK_TITLE_ADMIN_IDIOM_REMOVALS
                    ),
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
