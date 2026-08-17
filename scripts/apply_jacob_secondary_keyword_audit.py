from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "jacob_secondary_keyword_audit.json"

REMOVE_POST_IDS = {
    "3222",
    "3631",
    "3917",
    "4094",
    "4469",
    "4505",
    "4885",
    "8613",
    "11568",
    "11619",
    "12287",
    "12943",
    "13186",
    "13513",
    "14905",
    "14935",
    "15676",
    "17642",
    "20337",
    "20711",
    "21016",
    "24163",
    "25439",
    "26922",
    "27056",
    "27131",
    "27343",
    "27402",
    "28262",
    "28633",
    "29861",
    "31240",
    "33434",
    "33693",
    "35358",
    "36268",
    "36333",
    "36799",
    "36902",
    "36915",
    "37140",
    "37850",
    "48332",
    "48832",
    "48868",
}


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    assigned = [
        post for post in posts if "Jacob" in post.get("secondaryKeywords", [])
    ]
    assigned_ids = {str(post["wpId"]) for post in assigned}
    missing = sorted(REMOVE_POST_IDS - assigned_ids, key=int)
    if missing:
        raise RuntimeError(f"Expected removal posts without keyword: {missing}")
    if len(assigned) != 88:
        raise RuntimeError(f"Expected 88 Jacob assignments; found {len(assigned)}")

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
            if keyword != "Jacob"
        ]
        removed.append(record)

    INDEX_PATH.write_text(
        json.dumps(posts, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    audit = {
        "keyword": "Jacob",
        "criterion": (
            "Retain when the biblical patriarch Jacob, his family or descendants, "
            "his identification with Israel, a tradition centered on him, or the "
            "different biblical Jacob named as Joseph's father is meaningfully "
            "discussed; remove unrelated people named Jacob and appearances found "
            "only in lists, quotations, genealogical chains, collective expressions, "
            "or brief background examples."
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
    print(f"Jacob: {audit['before']} -> {audit['retained']} posts")
    print(f"Removed assignments: {audit['removed']}")


if __name__ == "__main__":
    main()
