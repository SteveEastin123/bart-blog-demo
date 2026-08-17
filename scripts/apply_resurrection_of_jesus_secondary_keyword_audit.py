from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = (
    ROOT / "data" / "audits" / "resurrection_of_jesus_secondary_keyword_audit.json"
)

REMOVE_POST_IDS = {
    "8269",
    "10590",
    "11302",
    "12004",
    "14920",
    "14963",
    "14973",
    "15609",
    "16923",
    "17310",
    "21403",
    "28205",
    "34764",
    "37557",
    "38645",
    "47043",
}


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    assigned = [
        post
        for post in posts
        if "Resurrection of Jesus" in post.get("secondaryKeywords", [])
    ]
    assigned_ids = {str(post["wpId"]) for post in assigned}
    missing = sorted(REMOVE_POST_IDS - assigned_ids, key=int)
    if missing:
        raise RuntimeError(f"Expected removal posts without keyword: {missing}")
    if len(assigned) != 88:
        raise RuntimeError(
            f"Expected 88 Resurrection of Jesus assignments; found {len(assigned)}"
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
            if keyword != "Resurrection of Jesus"
        ]
        removed.append(record)

    INDEX_PATH.write_text(
        json.dumps(posts, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    audit = {
        "keyword": "Resurrection of Jesus",
        "criterion": (
            "Retain when Jesus' resurrection, resurrection appearances, or the "
            "meaning of resurrection belief is a major or meaningful supporting "
            "subject. Remove incidental references in author biographies, book "
            "titles, promotional lists, chronological background, broad surveys, "
            "or brief contextual explanations unrelated to the post's argument."
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
        f"Resurrection of Jesus: {audit['before']} -> {audit['retained']} posts"
    )
    print(f"Removed assignments: {audit['removed']}")


if __name__ == "__main__":
    main()
