from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "memory_secondary_keyword_audit.json"

REMOVE_POST_IDS = {
    "48986", "48176", "47001", "47163", "39718", "39468", "33524",
    "29842", "25282", "22813", "22325", "20983", "20014", "19917",
    "17477", "17368", "16987", "16467", "16309", "15732", "14685",
    "12850", "12584", "12341", "12122", "11546", "9956", "9086",
    "9055", "8801", "8773", "8469", "7960", "7669", "7459", "7439",
    "7415", "7170", "6585", "6341", "4701", "4691", "2609",
}


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    posts_by_id = {str(post["wpId"]): post for post in posts}
    missing_posts = sorted(REMOVE_POST_IDS - posts_by_id.keys(), key=int)
    if missing_posts:
        raise RuntimeError(f"Missing expected posts: {missing_posts}")

    removed = []
    for post_id in sorted(REMOVE_POST_IDS, key=int):
        post = posts_by_id[post_id]
        keywords = post.get("secondaryKeywords", [])
        if "Memory" not in keywords:
            raise RuntimeError(
                f"Post {post_id} ({post['title']}) no longer has keyword Memory"
            )
        post["secondaryKeywords"] = [
            keyword for keyword in keywords if keyword != "Memory"
        ]
        removed.append({"wpId": post_id, "title": post["title"]})

    retained = [
        {"wpId": str(post["wpId"]), "title": post["title"]}
        for post in posts
        if "Memory" in post.get("secondaryKeywords", [])
    ]
    INDEX_PATH.write_text(
        json.dumps(posts, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )

    audit = {
        "keyword": "Memory",
        "criterion": (
            "Retain when memory is a meaningful subject involving eyewitness recall, "
            "false or collective memory, oral transmission, remembered traditions, or "
            "historical reconstruction; remove ordinary expressions, incidental personal "
            "recollections, memorial labels, titles, and passing references."
        ),
        "before": len(removed) + len(retained),
        "retained": len(retained),
        "removed": len(removed),
        "removedPosts": removed,
        "retainedPosts": retained,
    }
    AUDIT_PATH.write_text(
        json.dumps(audit, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    print(f"Memory: {audit['before']} -> {audit['retained']} posts")
    print(f"Removed assignments: {audit['removed']}")


if __name__ == "__main__":
    main()
