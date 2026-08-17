"""Apply approved removals for the Gospel of John secondary keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "gospel_of_john_secondary_keyword_audit.json"
KEYWORD = "Gospel of John"

REVELATION_REMOVALS = {
    "47991", "47987", "47853", "47851", "47849", "47813", "47811",
    "36203", "34830", "34738", "34764", "33813", "33132", "29782",
    "29779", "29776", "29708", "29672", "29668", "29438", "29435",
    "28916", "27798", "27651", "26903", "26777", "26760", "26586",
    "26572", "21924", "16018", "16015", "16003", "15525", "15523",
    "15510", "15508", "8526", "8505", "26631",
}

PERSON_NAME_REMOVALS = {
    "48954", "47159", "38938", "37087", "36814", "35878", "34472",
    "32663", "31948", "21308", "22178", "13186", "12916", "12848",
    "12345", "12280", "11729", "11723", "11498", "10285", "9129",
    "7896", "7129", "4799", "4177", "3519", "2551", "2512",
}

INCIDENTAL_REMOVALS = {
    "49197", "48982", "40007", "35720", "35255", "34033", "32874",
    "32667", "32529", "31890", "31763", "21281", "21223", "12830",
    "9167", "5149", "4747", "3472", "3284", "47061", "28076",
    "12537", "6578", "2624",
}

APPROVED_REMOVALS = (
    REVELATION_REMOVALS | PERSON_NAME_REMOVALS | INCIDENTAL_REMOVALS
)


def removal_reason(wp_id: str) -> str:
    if wp_id in REVELATION_REMOVALS:
        return (
            "John refers to John of Patmos or the Apocalypse of John rather than "
            "to the Gospel of John."
        )
    if wp_id in PERSON_NAME_REMOVALS:
        return (
            "John refers to another person, such as John the Baptist, an apostle, "
            "a church father, or a modern individual."
        )
    return (
        "The Gospel of John appears only in a generic list, promotional reference, "
        "course outline, or brief incidental example."
    )


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    known_post_ids = {str(post.get("wpId", "")) for post in posts}
    unknown = APPROVED_REMOVALS - known_post_ids
    if unknown:
        raise RuntimeError(
            "Approved Gospel of John removals reference unknown posts: "
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
                    "Remove approved cases where John denotes Revelation or another "
                    "person, or where the Gospel appears only incidentally."
                ),
                "approvedRemovalPostIds": sorted(APPROVED_REMOVALS),
                "before": before,
                "retained": after,
                "removed": len(removed),
                "previouslyRemoved": len(APPROVED_REMOVALS) - len(removed),
                "removalCounts": {
                    "revelation": len(REVELATION_REMOVALS),
                    "otherPeople": len(PERSON_NAME_REMOVALS),
                    "incidental": len(INCIDENTAL_REMOVALS),
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
