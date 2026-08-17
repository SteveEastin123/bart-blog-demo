from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "debates_secondary_keyword_audit.json"

REMOVE_POST_IDS = {
    "49694", "48800", "48090", "47169", "40657", "40007", "39477",
    "39468", "38645", "37308", "36605", "33085", "33030", "26327",
    "25638", "24790", "24309", "22174", "21031", "20504", "20660",
    "20014", "17291", "16248", "14930", "14258", "12737", "12531",
    "12381", "12253", "12013", "10261", "10049", "9508", "9384",
    "9194", "9190", "9150", "9122", "9112", "9086", "9084", "8659",
    "8447", "8155", "8022", "7580", "7360", "7204", "6632", "6578",
    "6285", "4218", "3559", "2352", "2155", "1990", "1984", "1945",
}


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    posts_by_id = {str(post["wpId"]): post for post in posts}
    missing_posts = sorted(REMOVE_POST_IDS - posts_by_id.keys(), key=int)
    if missing_posts:
        raise RuntimeError(f"Missing expected posts: {missing_posts}")

    changes = []
    for post_id in sorted(REMOVE_POST_IDS, key=int):
        post = posts_by_id[post_id]
        keywords = post.get("secondaryKeywords", [])
        if "Debates" not in keywords:
            raise RuntimeError(
                f"Post {post_id} ({post['title']}) no longer has keyword Debates"
            )
        post["secondaryKeywords"] = [
            keyword for keyword in keywords if keyword != "Debates"
        ]
        changes.append({"wpId": post_id, "title": post["title"]})

    retained = [
        {"wpId": str(post["wpId"]), "title": post["title"]}
        for post in posts
        if "Debates" in post.get("secondaryKeywords", [])
    ]

    INDEX_PATH.write_text(
        json.dumps(posts, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    audit = {
        "keyword": "Debates",
        "criterion": (
            "Retain for formal public or classroom debates, direct responses to "
            "debate arguments, and sustained historical or scholarly controversies; "
            "remove from posts that merely discuss a disputed subject, present an "
            "argument, or mention debate incidentally."
        ),
        "before": len(changes) + len(retained),
        "retained": len(retained),
        "removed": len(changes),
        "removedPosts": changes,
        "retainedPosts": retained,
    }
    AUDIT_PATH.write_text(
        json.dumps(audit, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    print(f"Debates: {audit['before']} -> {audit['retained']} posts")
    print(f"Removed assignments: {audit['removed']}")


if __name__ == "__main__":
    main()
