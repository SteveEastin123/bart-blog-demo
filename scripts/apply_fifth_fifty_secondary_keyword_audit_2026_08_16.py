"""Apply the approved audit of the fifth 50 higher-frequency keywords."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
RETIREMENT_PATH = (
    ROOT / "data" / "index" / "ehrman_secondary_keyword_retirement_candidates.json"
)

NORMALIZE = {
    "Pilate": "Pontius Pilate",
    "Forged": "Forgery",
    "Joseph the Father of Jesus": "Joseph, Father of Jesus",
}

REMOVE_BY_KEYWORD_AND_TITLE = {
    "Resurrection of Jesus": {"Is There Any Point Doing a Public Debate?"},
    "Forgery and Counterforgery": {"Problems with Luke as the Author of Luke"},
    "Debates": {"Follow-up Apologies for the Post on Dinesh D’Souza"},
    "How Jesus Became God": {
        "Some Flak (Already!) Over My New Book",
        "How I’m Writing This Book",
        "Sketch of My Memory Book",
    },
}

RETIRED = set(NORMALIZE)


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


def validate_targets(posts: list[dict[str, object]]) -> None:
    """Ensure every selective removal resolves to one matching assignment."""
    for keyword, titles in REMOVE_BY_KEYWORD_AND_TITLE.items():
        for title in titles:
            matches = [
                post
                for post in posts
                if post.get("title") == title
                and keyword in post.get("secondaryKeywords", [])
            ]
            if len(matches) != 1:
                raise ValueError(
                    f"Expected one {keyword!r} assignment for {title!r}; "
                    f"found {len(matches)}"
                )


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    validate_targets(posts)
    removed = 0
    normalized = 0
    deduplicated = 0
    topic_duplicates = 0

    for post in posts:
        title = str(post["title"])
        updated: list[str] = []
        for keyword in post.get("secondaryKeywords", []):
            if title in REMOVE_BY_KEYWORD_AND_TITLE.get(keyword, set()):
                removed += 1
                continue
            replacement = NORMALIZE.get(keyword, keyword)
            if replacement != keyword:
                normalized += 1
            updated.append(replacement)

        unique = normalized_unique(updated)
        deduplicated += len(updated) - len(unique)
        topic_keys = {
            str(topic).casefold().strip() for topic in post.get("topics", [])
        }
        filtered = [
            keyword
            for keyword in unique
            if keyword.casefold().strip() not in topic_keys
        ]
        topic_duplicates += len(unique) - len(filtered)
        post["secondaryKeywords"] = filtered

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
    print(f"Normalized assignments: {normalized}")
    print(f"Deduplicated assignments: {deduplicated}")
    print(f"Removed topic duplicates: {topic_duplicates}")


if __name__ == "__main__":
    main()
