from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = (
    ROOT / "data" / "audits" / "gospel_of_thomas_secondary_keyword_audit.json"
)

REMOVE_POST_IDS = {
    "2036",
    "2259",
    "4927",
    "5016",
    "6402",
    "6409",
    "6417",
    "8269",
    "8932",
    "12107",
    "12110",
    "12262",
    "12391",
    "21214",
    "21220",
    "21223",
    "21300",
    "22087",
    "30975",
    "33026",
    "33548",
    "35182",
    "35188",
    "35410",
    "37380",
    "37565",
}


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    assigned = [
        post
        for post in posts
        if "Gospel of Thomas" in post.get("secondaryKeywords", [])
    ]
    assigned_ids = {str(post["wpId"]) for post in assigned}
    missing = sorted(REMOVE_POST_IDS - assigned_ids, key=int)
    if missing:
        raise RuntimeError(f"Expected removal posts without keyword: {missing}")
    if len(assigned) != 88:
        raise RuntimeError(
            f"Expected 88 Gospel of Thomas assignments; found {len(assigned)}"
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
            if keyword != "Gospel of Thomas"
        ]
        removed.append(record)

    INDEX_PATH.write_text(
        json.dumps(posts, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    audit = {
        "keyword": "Gospel of Thomas",
        "criterion": (
            "Retain when the Coptic sayings Gospel is a meaningful subject, "
            "source, comparison, historical witness, or sustained teaching "
            "example; remove references that concern the Infancy Gospel of "
            "Thomas, Acts of Thomas, or Thomas the apostle, as well as passing "
            "lists, brief comparisons, exam choices, and background details."
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
    print(f"Gospel of Thomas: {audit['before']} -> {audit['retained']} posts")
    print(f"Removed assignments: {audit['removed']}")


if __name__ == "__main__":
    main()
