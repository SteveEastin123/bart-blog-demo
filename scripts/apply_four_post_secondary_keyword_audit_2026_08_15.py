#!/usr/bin/env python3
"""Apply the approved audit of the first 50 four-post secondary keywords."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
RETIREMENTS_PATH = (
    ROOT / "data" / "index" / "ehrman_secondary_keyword_retirement_candidates.json"
)

REMOVE_ALL = {
    "Balaam",
    "Biblical Scholarship",
    "Free Will and Predestination",
    "Miriam",
    "Ripley Center",
    "Secretaries",
    "Smithsonian Associates",
}

REMOVE_BY_KEYWORD = {
    "Adoptionism": {"10270", "9211", "9156"},
    "Apocalyptic Jesus": {"26631"},
    "Archaeology": {"11595", "11284", "2380"},
    "Crucified Messiah": {"24762"},
    "Early Christian Diversity": {"8954", "8952"},
    "Edessa": {"25436", "15674"},
    "Ezra": {"21114", "12854"},
    "Gnostics": {"7406", "7401", "3228"},
    "Isis": {"40364"},
    "Jewish Christian Gospels": {"36803", "21145", "4792"},
    "Life of Brian": {"36215", "7654", "4982"},
    "Source Criticism": {"15880", "7392", "7381"},
    "Zacchaeus": {"47084", "32678"},
}

REPLACE_BY_POST = {
    ("9204", "Adoptionism"): "Adoptionist Christology",
    ("9864", "Archaeology"): "Archaeology and Material Evidence",
    ("3169", "Gnostics"): "Gnosticism",
}

RETIRED_LABELS = REMOVE_ALL | {
    "Adoptionism",
    "Archaeology",
    "Gnostics",
}

ACTIVE_LABELS = set(REPLACE_BY_POST.values())


def main() -> None:
    posts = json.loads(POSTS_PATH.read_text(encoding="utf-8"))
    removed = 0
    replaced = 0

    for post in posts:
        wp_id = str(post["wpId"])
        updated = []
        for keyword in post.get("secondaryKeywords", []):
            replacement = REPLACE_BY_POST.get((wp_id, keyword))
            if replacement:
                if replacement not in updated:
                    updated.append(replacement)
                replaced += 1
                continue
            if keyword in REMOVE_ALL or wp_id in REMOVE_BY_KEYWORD.get(keyword, set()):
                removed += 1
                continue
            if keyword not in updated:
                updated.append(keyword)
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
