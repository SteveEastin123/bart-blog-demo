"""Apply the approved audit of the next 50 secondary keywords."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
RETIREMENT_PATH = (
    ROOT / "data" / "index" / "ehrman_secondary_keyword_retirement_candidates.json"
)

REMOVE_BY_KEYWORD = {
    "Albert Schweitzer": {"49955", "2622"},
    "Anti-Judaism": {"33021", "13330", "3151", "7627"},
    "Apollos": {"49317", "37162", "5055"},
    "Bathsheba": {"36333", "17569", "8232"},
    "Cain": {"48013", "15392", "33532"},
    "Christology (General)": {
        "24080",
        "23386",
        "14950",
        "8679",
        "7434",
        "3898",
        "3883",
        "3821",
        "3809",
        "3801",
    },
    "Ezekiel": {"48332", "48263", "28443", "12239"},
    "Faith": {"29137", "4837"},
    "Gamaliel": {"34332"},
    "Homosexuality": {
        "23130",
        "23125",
        "16162",
        "16136",
        "12305",
        "12301",
        "12299",
        "3729",
        "3723",
    },
    "Joseph in Genesis": {"38971", "26782"},
    "Justin Martyr": {"49051", "27766"},
    "Martyrdom of Polycarp": {"48864", "15392"},
    "Paganism": {"25276"},
    "Papyrus Egerton": {"22087", "7688", "7373", "4273"},
    "Philip": {"40016", "15672", "8326", "8269"},
    "Redaction Criticism": {"9061"},
}

RETIRED = {"Christology (General)"}


def normalized_unique(values: list[str]) -> list[str]:
    """Return values in original order with case-insensitive duplicates removed."""
    result: list[str] = []
    seen: set[str] = set()
    for value in values:
        key = value.casefold().strip()
        if key and key not in seen:
            seen.add(key)
            result.append(value)
    return result


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    removed = 0

    for post in posts:
        post_id = str(post["wpId"])
        updated = []
        for keyword in post.get("secondaryKeywords", []):
            if post_id in REMOVE_BY_KEYWORD.get(keyword, set()):
                removed += 1
                continue
            updated.append(keyword)

        topic_keys = {topic.casefold().strip() for topic in post.get("topics", [])}
        post["secondaryKeywords"] = [
            keyword
            for keyword in normalized_unique(updated)
            if keyword.casefold().strip() not in topic_keys
        ]

    retirement = json.loads(RETIREMENT_PATH.read_text(encoding="utf-8"))
    retirement["keywords"] = sorted(
        set(retirement.get("keywords", [])).union(RETIRED), key=str.casefold
    )

    INDEX_PATH.write_text(
        json.dumps(posts, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    RETIREMENT_PATH.write_text(
        json.dumps(retirement, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    print(f"Removed assignments: {removed}")


if __name__ == "__main__":
    main()
