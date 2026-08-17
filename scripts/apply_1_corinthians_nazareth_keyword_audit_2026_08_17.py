from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "1_corinthians_nazareth_keyword_audit_2026_08_17.json"

REMOVALS = {
    "1 Corinthians": {
        "49317",
        "47165",
        "47123",
        "35715",
    },
    "Nazareth": {
        "48832",
        "47289",
        "41916",
        "41050",
        "40614",
        "33514",
        "29846",
        "28785",
        "27847",
        "23181",
        "22572",
        "21106",
        "20977",
        "17237",
        "13684",
        "13189",
        "12456",
        "12432",
        "12085",
        "12013",
        "12002",
        "11974",
        "8452",
        "8369",
        "6442",
        "6439",
        "6434",
        "4927",
        "4733",
        "4720",
        "3371",
        "894",
    },
}


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    posts_by_id = {str(post["wpId"]): post for post in posts}
    changes: list[dict[str, str]] = []

    for keyword, post_ids in REMOVALS.items():
        for post_id in sorted(post_ids, key=int):
            if post_id not in posts_by_id:
                raise RuntimeError(f"Missing expected post {post_id} for {keyword}")

            post = posts_by_id[post_id]
            keywords = post.get("secondaryKeywords", [])
            if keyword not in keywords:
                raise RuntimeError(
                    f"Post {post_id} ({post['title']}) no longer has keyword {keyword}"
                )

            post["secondaryKeywords"] = [value for value in keywords if value != keyword]
            changes.append(
                {
                    "wpId": post_id,
                    "title": post["title"],
                    "removedSecondaryKeyword": keyword,
                }
            )

    INDEX_PATH.write_text(
        json.dumps(posts, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )

    counts_after = {
        keyword: sum(
            keyword in post.get("secondaryKeywords", []) for post in posts
        )
        for keyword in REMOVALS
    }
    audit = {
        "auditDate": "2026-08-17",
        "scope": ["1 Corinthians", "Nazareth"],
        "criteria": (
            "Retain secondary keywords only when they represent meaningful supporting "
            "subjects rather than passing references, lists, generic names, or titles."
        ),
        "changes": changes,
        "countsAfter": counts_after,
    }
    AUDIT_PATH.write_text(
        json.dumps(audit, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )

    print(f"Removed {len(changes)} secondary-keyword assignments.")
    for keyword, count in counts_after.items():
        print(f"{keyword}: {count} posts remain")


if __name__ == "__main__":
    main()
