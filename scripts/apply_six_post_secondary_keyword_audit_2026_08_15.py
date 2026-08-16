"""Apply the approved audit of secondary keywords used by six posts."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
RETIREMENT_PATH = (
    ROOT / "data" / "index" / "ehrman_secondary_keyword_retirement_candidates.json"
)

REMOVE_BY_KEYWORD = {
    "1 Samuel": {"34084"},
    "Annas": {"47270", "25439", "21368", "21214", "15676", "14935"},
    "Fundamentalism": {"21376", "3616", "2647"},
    "Levi the Patriarch": {"46934", "46927", "33431", "16649", "12052", "4339"},
    "Pseudepigrapha": {"12250"},
    "Richard Bauckham": {"10296", "7377"},
    "Song of Songs": {"48691", "48687", "12248", "12239"},
    "Stephen": {"16240"},
}

NORMALIZE = {
    "Dinesh Dsouza": "Dinesh D'Souza",
    "Heaven and Hell Beliefs": "Heaven and Hell",
    "Nag Hammadi Library": "Nag Hammadi",
    "Original Text of the NT": "Original Text",
    "Writing Process": "Writing a Book",
}

RETIRED = {
    "Annas",
    "Dinesh Dsouza",
    "Heaven and Hell Beliefs",
    "Levi the Patriarch",
    "Nag Hammadi Library",
    "Original Text of the NT",
    "Writing Process",
}


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
    normalized = 0

    for post in posts:
        post_id = str(post["wpId"])
        updated: list[str] = []
        for keyword in post.get("secondaryKeywords", []):
            if post_id in REMOVE_BY_KEYWORD.get(keyword, set()):
                removed += 1
                continue
            replacement = NORMALIZE.get(keyword, keyword)
            if replacement != keyword:
                normalized += 1
            updated.append(replacement)
        topic_keys = {topic.casefold().strip() for topic in post.get("topics", [])}
        post["secondaryKeywords"] = [
            keyword
            for keyword in normalized_unique(updated)
            if keyword.casefold().strip() not in topic_keys
        ]

    retirement = json.loads(RETIREMENT_PATH.read_text(encoding="utf-8"))
    existing = retirement.get("keywords", [])
    retirement["keywords"] = sorted(
        set(existing).union(RETIRED), key=str.casefold
    )
    retirement.pop("retiredKeywords", None)

    INDEX_PATH.write_text(
        json.dumps(posts, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    RETIREMENT_PATH.write_text(
        json.dumps(retirement, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    print(f"Removed assignments: {removed}")
    print(f"Normalized assignments: {normalized}")


if __name__ == "__main__":
    main()
