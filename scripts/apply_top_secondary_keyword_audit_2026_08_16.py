"""Apply the approved audit of the most frequently used secondary keywords."""

from __future__ import annotations

import json
import re
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
RAW_PATH = ROOT / "data" / "raw" / "posts.jsonl"
CATEGORIES_PATH = ROOT / "data" / "index" / "ehrman_post_categories.json"
AUDIT_PATH = ROOT / "data" / "audits" / "top_secondary_keyword_audit_2026_08_16.json"

GOSPEL_NORMALIZATIONS = {
    "Mark": "Gospel of Mark",
    "Luke": "Gospel of Luke",
    "Matthew": "Gospel of Matthew",
    "John": "Gospel of John",
}

TERM_PATTERNS = {
    "Paul": re.compile(r"\bPaul(?:'s|ine)?\b", re.IGNORECASE),
    "Early Christianity": re.compile(
        r"\bearly Christian(?:ity|s)?\b", re.IGNORECASE
    ),
    "Resurrection": re.compile(
        r"\bresurrect(?:ion|ed|ing)?\b|\braised from the dead\b", re.IGNORECASE
    ),
}

CONTEXT_PATTERNS = {
    "Paul": re.compile(
        r"Paul|Pauline|Romans|Corinthians|Galatians|Philippians|"
        r"Thessalonians|Philemon|Ephesians|Colossians|Pastoral Epistles",
        re.IGNORECASE,
    ),
    "Early Christianity": re.compile(
        r"Early Christian|Christian Origins|Rise of Christianity|"
        r"Apostolic Fathers|Church History",
        re.IGNORECASE,
    ),
    "Resurrection": re.compile(
        r"Resurrection|Empty Tomb|Afterlife|Visionary Experiences",
        re.IGNORECASE,
    ),
}

FATHER_PATTERN = re.compile(
    r"\b(?:Ignatius|Clement|Polycarp|Papias|Eusebius|Athanasius|Didymus|"
    r"Augustine|Origen|Tertullian|Irenaeus|Jerome)\b",
    re.IGNORECASE,
)
CHURCH_FATHERS_PATTERN = re.compile(
    r"\bchurch fathers?\b|\bpatristic\b", re.IGNORECASE
)
HISTORICAL_JESUS_PATTERN = re.compile(r"\bhistorical Jesus\b", re.IGNORECASE)


def category_topics(categories: list[dict], name: str) -> set[str]:
    for category in categories:
        if category.get("name") == name:
            return set(category.get("topicOrder", []))
    raise RuntimeError(f"Category not found: {name}")


def post_context(post: dict) -> str:
    return " ".join(
        [post.get("title", ""), post.get("description", ""), *post.get("topics", [])]
    )


def remove_keyword(post: dict, keyword: str) -> bool:
    original = post.get("secondaryKeywords", [])
    revised = [value for value in original if value != keyword]
    if revised == original:
        return False
    post["secondaryKeywords"] = revised
    return True


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    categories_root = json.loads(CATEGORIES_PATH.read_text(encoding="utf-8"))
    categories = categories_root["categories"]
    historical_topics = category_topics(categories, "Historical Jesus")
    church_father_topics = category_topics(categories, "Church Fathers")

    target_ids = {
        str(post["wpId"])
        for post in posts
        if set(post.get("secondaryKeywords", []))
        & {
            *GOSPEL_NORMALIZATIONS,
            *GOSPEL_NORMALIZATIONS.values(),
            *TERM_PATTERNS,
            "Historical Jesus",
            "Church Fathers",
        }
    }
    raw_text = {}
    with RAW_PATH.open(encoding="utf-8") as source:
        for line in source:
            raw_post = json.loads(line)
            wp_id = str(raw_post.get("wpId", ""))
            if wp_id in target_ids:
                raw_text[wp_id] = raw_post.get("text", "")
    missing = sorted(target_ids - set(raw_text))
    if missing:
        raise RuntimeError(f"Missing local full text for {len(missing)} posts")

    changes = {
        "normalizedGospelKeywords": {value: [] for value in GOSPEL_NORMALIZATIONS.values()},
        "removedExactTopicKeywordOverlaps": {
            value: [] for value in GOSPEL_NORMALIZATIONS.values()
        },
        "removedIncidentalAssignments": {
            "Paul": [],
            "Early Christianity": [],
            "Resurrection": [],
            "Historical Jesus": [],
            "Church Fathers": [],
        },
    }

    for post in posts:
        wp_id = str(post["wpId"])
        text = raw_text.get(wp_id, "")
        keywords = post.get("secondaryKeywords", [])

        normalized = []
        for keyword in keywords:
            replacement = GOSPEL_NORMALIZATIONS.get(keyword, keyword)
            if replacement != keyword:
                changes["normalizedGospelKeywords"][replacement].append(
                    {"wpId": wp_id, "title": post["title"], "from": keyword}
                )
            if replacement not in normalized:
                normalized.append(replacement)
        post["secondaryKeywords"] = normalized

        for keyword in GOSPEL_NORMALIZATIONS.values():
            if keyword in post.get("topics", []) and remove_keyword(post, keyword):
                changes["removedExactTopicKeywordOverlaps"][keyword].append(
                    {"wpId": wp_id, "title": post["title"]}
                )

        context = post_context(post)
        for keyword, pattern in TERM_PATTERNS.items():
            if keyword not in post.get("secondaryKeywords", []):
                continue
            occurrences = len(pattern.findall(text))
            if occurrences < 2 and not CONTEXT_PATTERNS[keyword].search(context):
                remove_keyword(post, keyword)
                changes["removedIncidentalAssignments"][keyword].append(
                    {
                        "wpId": wp_id,
                        "title": post["title"],
                        "fullTextMentions": occurrences,
                    }
                )

        if "Historical Jesus" in post.get("secondaryKeywords", []):
            has_category_topic = bool(set(post.get("topics", [])) & historical_topics)
            if not has_category_topic and not HISTORICAL_JESUS_PATTERN.search(
                f"{text} {post.get('title', '')} {post.get('description', '')}"
            ):
                remove_keyword(post, "Historical Jesus")
                changes["removedIncidentalAssignments"]["Historical Jesus"].append(
                    {"wpId": wp_id, "title": post["title"]}
                )

        if "Church Fathers" in post.get("secondaryKeywords", []):
            has_category_topic = bool(set(post.get("topics", [])) & church_father_topics)
            combined = f"{text} {post.get('title', '')} {post.get('description', '')}"
            father_mentions = len(FATHER_PATTERN.findall(text))
            if (
                not has_category_topic
                and not CHURCH_FATHERS_PATTERN.search(combined)
                and father_mentions < 2
                and not FATHER_PATTERN.search(
                    f"{post.get('title', '')} {post.get('description', '')}"
                )
            ):
                remove_keyword(post, "Church Fathers")
                changes["removedIncidentalAssignments"]["Church Fathers"].append(
                    {"wpId": wp_id, "title": post["title"]}
                )

    duplicate_posts = [
        str(post["wpId"])
        for post in posts
        if len(post.get("secondaryKeywords", []))
        != len(set(post.get("secondaryKeywords", [])))
    ]
    overlaps = [
        {
            "wpId": str(post["wpId"]),
            "labels": sorted(
                set(post.get("topics", [])) & set(post.get("secondaryKeywords", []))
            ),
        }
        for post in posts
        if set(post.get("topics", [])) & set(post.get("secondaryKeywords", []))
    ]
    if duplicate_posts or overlaps:
        raise RuntimeError(
            f"Integrity failure: duplicates={len(duplicate_posts)}, overlaps={len(overlaps)}"
        )

    INDEX_PATH.write_text(
        json.dumps(posts, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    summary = {
        "normalizedGospelKeywords": {
            key: len(records)
            for key, records in changes["normalizedGospelKeywords"].items()
        },
        "removedExactTopicKeywordOverlaps": {
            key: len(records)
            for key, records in changes["removedExactTopicKeywordOverlaps"].items()
        },
        "removedIncidentalAssignments": {
            key: len(records)
            for key, records in changes["removedIncidentalAssignments"].items()
        },
    }
    AUDIT_PATH.write_text(
        json.dumps({"summary": summary, "changes": changes}, ensure_ascii=False, indent=2)
        + "\n",
        encoding="utf-8",
    )
    print(json.dumps(summary, indent=2))


if __name__ == "__main__":
    main()
