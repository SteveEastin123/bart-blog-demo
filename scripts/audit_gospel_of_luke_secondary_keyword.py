"""Apply approved removals for the Gospel of Luke secondary keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "gospel_of_luke_secondary_keyword_audit.json"
KEYWORD = "Gospel of Luke"

ACTS_ONLY_REMOVALS = {
    "47113", "38758", "38713", "38667", "36661", "35872", "17518",
    "15254", "15251", "11332", "11081", "10997", "10885", "10882",
    "3809", "4777", "2492",
}

INCIDENTAL_REMOVALS = {
    "49197", "49081", "47012", "46983", "41256", "40016", "39896",
    "37422", "36963", "35720", "35255", "34241", "34033", "30223",
    "21281", "21086", "19669", "16764", "15705", "15170", "15046",
    "14905", "13196", "12830", "11845", "9399", "9072", "8821",
    "8814", "8803", "8296", "8258", "7081", "7026", "5149", "4858",
    "4763", "4695", "4418", "3631", "2771", "2732", "2597", "2106",
    "17769",
}

APPROVED_REMOVALS = ACTS_ONLY_REMOVALS | INCIDENTAL_REMOVALS


def removal_reason(wp_id: str) -> str:
    if wp_id in ACTS_ONLY_REMOVALS:
        return (
            "Luke refers primarily to the author of Acts; the Gospel of Luke is "
            "not a meaningful supporting subject of the post."
        )
    return (
        "Luke appears only in a passing comparison, generic Gospel list, citation, "
        "transition, promotional material, or otherwise incidental context."
    )


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    known_post_ids = {str(post.get("wpId", "")) for post in posts}
    unknown = APPROVED_REMOVALS - known_post_ids
    if unknown:
        raise RuntimeError(
            "Approved Gospel of Luke removals reference unknown posts: "
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
                    "Remove approved cases where Luke denotes the author of Acts "
                    "rather than the Gospel, or where the Gospel appears only "
                    "incidentally."
                ),
                "approvedRemovalPostIds": sorted(APPROVED_REMOVALS),
                "before": before,
                "retained": after,
                "removed": len(removed),
                "previouslyRemoved": len(APPROVED_REMOVALS) - len(removed),
                "removalCounts": {
                    "actsOnly": len(ACTS_ONLY_REMOVALS),
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
