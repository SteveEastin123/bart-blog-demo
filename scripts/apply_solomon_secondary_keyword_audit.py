from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "solomon_secondary_keyword_audit.json"

RETAIN_POST_IDS = {
    "50201", "48838", "47002", "47215", "40864", "36640", "35354",
    "32825", "31779", "25712", "23968", "23142", "21859", "20492",
    "20003", "16085", "15559", "15065", "12494", "12467", "9399",
    "3997", "3868",
}


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    assigned = [
        post for post in posts if "Solomon" in post.get("secondaryKeywords", [])
    ]
    assigned_ids = {str(post["wpId"]) for post in assigned}
    missing_retained = sorted(RETAIN_POST_IDS - assigned_ids, key=int)
    if missing_retained:
        raise RuntimeError(
            f"Expected retained posts without Solomon: {missing_retained}"
        )
    if len(assigned) != 90:
        raise RuntimeError(f"Expected 90 Solomon assignments; found {len(assigned)}")

    retained = []
    removed = []
    for post in assigned:
        record = {"wpId": str(post["wpId"]), "title": post["title"]}
        if str(post["wpId"]) in RETAIN_POST_IDS:
            retained.append(record)
            continue
        post["secondaryKeywords"] = [
            keyword
            for keyword in post.get("secondaryKeywords", [])
            if keyword != "Solomon"
        ]
        removed.append(record)

    INDEX_PATH.write_text(
        json.dumps(posts, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    audit = {
        "keyword": "Solomon",
        "criterion": (
            "Retain when Solomon, the Solomonic kingdom, writings traditionally "
            "associated with Solomon, or Solomon's role in Jewish wisdom, messianic, "
            "and divine-sonship traditions is meaningfully discussed; remove passing "
            "references in lists, genealogies, quotations, and historical summaries."
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
    print(f"Solomon: {audit['before']} -> {audit['retained']} posts")
    print(f"Removed assignments: {audit['removed']}")


if __name__ == "__main__":
    main()
