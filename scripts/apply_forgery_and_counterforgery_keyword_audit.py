from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = (
    ROOT
    / "data"
    / "audits"
    / "forgery_and_counterforgery_secondary_keyword_audit.json"
)

REMOVE_POST_IDS = {"33337", "14968", "12873", "12379", "8626", "9022"}


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    assigned = [
        post
        for post in posts
        if "Forgery and Counterforgery" in post.get("secondaryKeywords", [])
    ]
    assigned_ids = {str(post["wpId"]) for post in assigned}
    missing = sorted(REMOVE_POST_IDS - assigned_ids, key=int)
    if missing:
        raise RuntimeError(f"Expected removal posts without keyword: {missing}")
    if len(assigned) != 89:
        raise RuntimeError(
            f"Expected 89 Forgery and Counterforgery assignments; found {len(assigned)}"
        )

    retained = []
    removed = []
    for post in assigned:
        record = {"wpId": str(post["wpId"]), "title": post["title"]}
        if str(post["wpId"]) not in REMOVE_POST_IDS:
            retained.append(record)
            continue
        post["secondaryKeywords"] = [
            keyword
            for keyword in post.get("secondaryKeywords", [])
            if keyword != "Forgery and Counterforgery"
        ]
        removed.append(record)

    INDEX_PATH.write_text(
        json.dumps(posts, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    audit = {
        "keyword": "Forgery and Counterforgery",
        "criterion": (
            "Retain when a post reproduces, adapts, applies, discusses, or makes "
            "substantive use of Bart's scholarly book Forgery and Counterforgery; "
            "remove incidental publication lists, background comparisons, and "
            "recommendations unrelated to the post's main discussion."
        ),
        "before": len(assigned),
        "retained": len(retained),
        "removed": len(removed),
        "retainedPosts": retained,
        "removedPosts": removed,
    }
    AUDIT_PATH.write_text(
        json.dumps(audit, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    print(
        "Forgery and Counterforgery: "
        f"{audit['before']} -> {audit['retained']} posts"
    )
    print(f"Removed assignments: {audit['removed']}")


if __name__ == "__main__":
    main()
