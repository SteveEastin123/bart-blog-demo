from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "authorship_secondary_keyword_audit.json"


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    assigned = [
        post
        for post in posts
        if "Authorship" in post.get("secondaryKeywords", [])
    ]
    if len(assigned) != 101:
        raise RuntimeError(
            f"Expected 101 Authorship assignments; found {len(assigned)}"
        )

    retained = [
        {"wpId": str(post["wpId"]), "title": post["title"]}
        for post in assigned
    ]
    audit = {
        "keyword": "Authorship",
        "criterion": (
            "Retain when identifying who wrote a text, evaluating attribution or "
            "anonymity, assessing pseudonymous authorship, reconstructing textual "
            "composition, or explaining the evidence used to identify an author "
            "is a meaningful subject of the post."
        ),
        "before": len(assigned),
        "retained": len(retained),
        "removed": 0,
        "retainedPosts": retained,
        "removedPosts": [],
    }
    AUDIT_PATH.write_text(
        json.dumps(audit, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    print(f"Authorship: {audit['before']} -> {audit['retained']} posts")
    print("Removed assignments: 0")


if __name__ == "__main__":
    main()
