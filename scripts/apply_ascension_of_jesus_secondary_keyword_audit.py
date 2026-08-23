from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = (
    ROOT
    / "data"
    / "audits"
    / "ascension_of_jesus_secondary_keyword_audit.json"
)

KEYWORD = "Ascension of Jesus"
ADD_POST_IDS = {
    "15261",
    "38713",
    "10885",
    "32498",
    "36268",
    "46956",
    "47203",
    "46971",
    "47204",
    "16109",
    "16100",
    "16130",
}


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    posts_by_id = {str(post["wpId"]): post for post in posts}

    missing = sorted(ADD_POST_IDS - posts_by_id.keys(), key=int)
    if missing:
        raise RuntimeError(f"Expected posts are missing from the index: {missing}")

    existing = [
        str(post["wpId"])
        for post in posts
        if KEYWORD in post.get("secondaryKeywords", [])
    ]
    if existing:
        raise RuntimeError(f"{KEYWORD} already exists on posts: {existing}")

    added_posts = []
    for wp_id in sorted(ADD_POST_IDS, key=int, reverse=True):
        post = posts_by_id[wp_id]
        post.setdefault("secondaryKeywords", []).append(KEYWORD)
        added_posts.append({"wpId": wp_id, "title": post["title"]})

    INDEX_PATH.write_text(
        json.dumps(posts, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )

    audit = {
        "keyword": KEYWORD,
        "auditDate": "2026-08-23",
        "criterion": (
            "Assign when Jesus's ascent into heaven, its chronology or location "
            "in Luke-Acts, its textual transmission, or its historical and "
            "theological interpretation is a meaningful supporting subject. "
            "Exclude routine narrative transitions and passing creedal mentions."
        ),
        "topicDecision": {
            "createTopic": False,
            "reason": (
                "Only one post treats the ascension as its dominant subject; the "
                "remaining relevant posts address it within broader discussions."
            ),
        },
        "before": 0,
        "retained": len(added_posts),
        "added": len(added_posts),
        "removed": 0,
        "addedPosts": added_posts,
        "descriptionChanges": [],
        "categoryChanges": [],
    }
    AUDIT_PATH.write_text(
        json.dumps(audit, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    print(f"{KEYWORD}: 0 -> {len(added_posts)} posts")


if __name__ == "__main__":
    main()
