from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDITS_DIR = ROOT / "data" / "audits"

AUDITS = {
    "Mary Magdalene": {
        "before": 73,
        "removed_ids": {
            "3054", "3774", "4177", "4591", "4911", "6587", "6595",
            "6967", "7155", "9393", "10049", "11188", "11508", "12255",
            "13225", "13506", "14939", "14941", "15239", "15477", "15482",
            "15490", "15600", "16987", "20556", "21421", "27410", "27447",
            "27871", "27939", "28558", "2915", "31948", "33402", "34597",
            "34998", "38520", "38564", "38568", "38587", "38930", "40002",
            "40118", "40614", "47100", "47137",
        },
        "criterion": (
            "Retain Mary Magdalene when her role in resurrection traditions, "
            "Jesus' ministry, marriage claims, healing traditions, or "
            "non-canonical texts materially supports the post. Remove book-title "
            "and bibliography references, list entries, naming analogies, and "
            "brief appearances that do not make her a meaningful subject."
        ),
    },
    "2 Peter": {
        "before": 71,
        "removed_ids": {
            "6145", "6578", "8405", "8417", "10892", "12131", "15398",
            "15649", "17064", "20492", "20507", "29089", "37565", "37837",
            "38002", "38752", "40436", "47043", "47593", "48021",
        },
        "criterion": (
            "Retain 2 Peter when the letter's authorship, teachings, canonical "
            "history, relationship to other Petrine writings, or evidence about "
            "Paul and early Christianity materially supports the post. Remove "
            "chronological comparisons, bibliographic and syllabus listings, "
            "passing analogies, and false matches to 'Acts 2, Peter'."
        ),
    },
    "Burial": {
        "before": 71,
        "removed_ids": {
            "7560", "7577", "7585", "12456", "14687", "15541", "25333",
            "31177", "35342", "36435", "36533", "36599", "47270", "49563",
        },
        "criterion": (
            "Retain Burial when burial practices, Jesus' burial, Joseph of "
            "Arimathea, Roman or Jewish treatment of bodies, or burial's relation "
            "to the empty tomb materially supports the post. Remove framing "
            "references, narrative details, book titles, and examples whose "
            "actual subject lies elsewhere."
        ),
    },
}


def post_record(post: dict) -> dict:
    return {
        "wpId": str(post["wpId"]),
        "title": post["title"],
        "topics": post.get("topics", []),
    }


def apply_audit(posts: list[dict], keyword: str, config: dict) -> dict:
    assigned = [
        post for post in posts if keyword in post.get("secondaryKeywords", [])
    ]
    if len(assigned) != config["before"]:
        raise RuntimeError(
            f"Expected {config['before']} {keyword} assignments; found "
            f"{len(assigned)}"
        )

    assigned_ids = {str(post["wpId"]) for post in assigned}
    missing = sorted(config["removed_ids"] - assigned_ids, key=int)
    if missing:
        raise RuntimeError(
            f"Expected {keyword} removal candidates without assignment: {missing}"
        )

    retained = []
    removed = []
    for post in assigned:
        if str(post["wpId"]) in config["removed_ids"]:
            post["secondaryKeywords"] = [
                value
                for value in post.get("secondaryKeywords", [])
                if value != keyword
            ]
            removed.append(post_record(post))
        else:
            retained.append(post_record(post))

    return {
        "keyword": keyword,
        "criterion": config["criterion"],
        "before": len(assigned),
        "after": len(retained),
        "retained": len(retained),
        "removed": len(removed),
        "added": 0,
        "retainedPosts": retained,
        "removedPosts": removed,
        "addedPosts": [],
    }


def audit_filename(keyword: str) -> str:
    return keyword.casefold().replace(" ", "_") + "_secondary_keyword_audit.json"


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    results = [
        apply_audit(posts, keyword, config)
        for keyword, config in AUDITS.items()
    ]

    INDEX_PATH.write_text(
        json.dumps(posts, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    for result in results:
        audit_path = AUDITS_DIR / audit_filename(result["keyword"])
        audit_path.write_text(
            json.dumps(result, ensure_ascii=False, indent=2) + "\n",
            encoding="utf-8",
        )
        print(
            f"{result['keyword']}: {result['before']} -> "
            f"{result['after']} posts ({result['removed']} removed)"
        )


if __name__ == "__main__":
    main()
