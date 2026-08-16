#!/usr/bin/env python3
"""Apply the approved audit of five-post secondary keywords."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
RETIREMENTS_PATH = (
    ROOT / "data" / "index" / "ehrman_secondary_keyword_retirement_candidates.json"
)

REMOVE_ALL = {
    "Ethics",
    "Jeroboam",
    "Teaching Christianity",
}

REMOVE_BY_KEYWORD = {
    "Apocryphal Acts": {"40440", "21145", "1976"},
    "Augustine": {"20266", "3299"},
    "Book Publishing": {"7657"},
    "Book of Zechariah": {"48332", "9477"},
    "Ecclesiastes": {"20014", "12484", "12482", "1981"},
    "Galilee": {"50116"},
    "Gerd Ludemann": {"2838"},
    "Incarnation Christology": {"37557", "35188", "8408"},
    "Irenaeus": {"50150", "8065"},
    "Jeff Siker": {"50167"},
    "Letter of Barnabas": {"8485"},
    "Messianic Secret": {"47057", "21099", "16162"},
    "Proto-Orthodoxy": {"50331"},
    "Religious Studies": {"11748"},
    "Septuagint": {"35715", "10254"},
    "Susanna": {"32498"},
}

REPLACE = {
    "Bill Oreilly": "Bill O'Reilly",
    "Publishing A Book": "Book Publishing",
}

RETIRED_LABELS = REMOVE_ALL | set(REPLACE)
ACTIVE_LABELS = set(REPLACE.values())


def main() -> None:
    posts = json.loads(POSTS_PATH.read_text(encoding="utf-8"))
    removed = 0
    replaced = 0

    for post in posts:
        wp_id = str(post["wpId"])
        updated = []
        for keyword in post.get("secondaryKeywords", []):
            if keyword in REMOVE_ALL or wp_id in REMOVE_BY_KEYWORD.get(keyword, set()):
                removed += 1
                continue
            replacement = REPLACE.get(keyword, keyword)
            if replacement != keyword:
                replaced += 1
            if replacement not in updated:
                updated.append(replacement)
        post["secondaryKeywords"] = updated

    retirement_data = json.loads(RETIREMENTS_PATH.read_text(encoding="utf-8"))
    retired = retirement_data.setdefault("keywords", [])
    retired[:] = [keyword for keyword in retired if keyword not in ACTIVE_LABELS]
    for keyword in sorted(RETIRED_LABELS):
        if keyword not in retired:
            retired.append(keyword)

    POSTS_PATH.write_text(
        json.dumps(posts, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    RETIREMENTS_PATH.write_text(
        json.dumps(retirement_data, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    print(f"Removed {removed} assignments and normalized {replaced} assignments.")


if __name__ == "__main__":
    main()
