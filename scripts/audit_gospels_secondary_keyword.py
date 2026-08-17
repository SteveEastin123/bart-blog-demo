"""Restrict the Gospels keyword to posts with meaningful collective coverage."""

from __future__ import annotations

import json
import re
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
RAW_PATH = ROOT / "data" / "raw" / "posts.jsonl"
AUDIT_PATH = ROOT / "data" / "audits" / "gospels_secondary_keyword_audit.json"
KEYWORD = "Gospels"

COLLECTIVE_TOPICS = {
    "Canonical Gospels (General)",
    "Eyewitness Reliability",
    "Gospel Authorship",
    "Gospel Dating",
    "Gospel Historical Reliability",
    "Jesus Before the Gospels",
    "Memory and Jesus Traditions",
    "Oral Tradition",
    "Redaction Criticism",
    "Source Criticism",
    "Synoptic Problem",
}
GOSPEL_NAMES = ("Matthew", "Mark", "Luke", "John")

# These posts passed the mechanical collective-coverage test but failed a
# subsequent semantic review. In each case, the Gospels are incidental,
# confined to one Gospel, or secondary to another central subject.
BORDERLINE_REMOVALS = {
    "49237", "48795", "48712", "48614", "48090", "47270", "47188",
    "47108", "47098", "47064", "47063", "47065", "47062", "47058",
    "47057", "47055", "47043", "41835", "41519", "37850", "36834",
    "36803", "35041", "33399", "33124", "32674", "26081", "25439",
    "25436", "22529", "21355", "21324", "21311", "21260", "21187",
    "17657", "17159", "16284", "15868", "15865", "15787", "15676",
    "15674", "15358", "14903", "13773", "13236", "12665", "12282",
    "12277", "11921", "11888", "11857", "11419", "10872", "9956",
    "9303", "9215", "9177", "8011", "6587", "4792", "4042", "3559",
    "2174",
}


def count_term(text: str, term: str) -> int:
    return len(re.findall(rf"\b{re.escape(term)}\b", text, flags=re.IGNORECASE))


def should_retain(post: dict, text: str) -> tuple[bool, dict]:
    plural_mentions = count_term(text, "Gospels")
    name_counts = {name: count_term(text, name) for name in GOSPEL_NAMES}
    sustained_names = sum(count >= 2 for count in name_counts.values())
    has_collective_topic = bool(set(post.get("topics", [])) & COLLECTIVE_TOPICS)
    title_or_description = " ".join(
        (post.get("title", ""), post.get("description", ""))
    )

    retain = str(post.get("wpId", "")) not in BORDERLINE_REMOVALS and any(
        (
            sustained_names >= 2 and plural_mentions >= 2,
            has_collective_topic and plural_mentions >= 2,
            bool(re.search(r"\bGospels\b", title_or_description, re.IGNORECASE)),
        )
    )
    evidence = {
        "pluralGospelsMentions": plural_mentions,
        "gospelNameMentions": name_counts,
        "sustainedGospelNames": sustained_names,
        "collectiveGospelTopic": has_collective_topic,
    }
    return retain, evidence


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    targets = {
        str(post["wpId"]): post
        for post in posts
        if KEYWORD in post.get("secondaryKeywords", [])
    }
    raw_text = {}
    with RAW_PATH.open(encoding="utf-8") as source:
        for line in source:
            raw_post = json.loads(line)
            wp_id = str(raw_post.get("wpId", ""))
            if wp_id in targets:
                raw_text[wp_id] = raw_post.get("text", "")

    missing = sorted(set(targets) - set(raw_text))
    if missing:
        raise RuntimeError(f"Missing local full text for {len(missing)} posts")

    retained = []
    removed = []
    for wp_id, post in targets.items():
        retain, evidence = should_retain(post, raw_text[wp_id])
        record = {
            "wpId": wp_id,
            "title": post["title"],
            "topics": post.get("topics", []),
            "evidence": evidence,
        }
        if retain:
            retained.append(record)
            continue

        post["secondaryKeywords"] = [
            keyword
            for keyword in post.get("secondaryKeywords", [])
            if keyword != KEYWORD
        ]
        removed.append(record)

    INDEX_PATH.write_text(
        json.dumps(posts, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    audit = {
        "keyword": KEYWORD,
        "criterion": (
            "Retain for sustained collective discussion: at least two repeatedly named "
            "canonical Gospels plus two plural mentions; a collective Gospel topic plus "
            "two plural mentions; or an explicit plural Gospels reference in the title "
            "or description. Repeated use of the plural word alone is insufficient."
            " Posts that passed these signals were also removed when a semantic review "
            "found that the Gospels were incidental, limited to one Gospel, or secondary "
            "to another central subject."
        ),
        "before": len(targets),
        "retained": len(retained),
        "removed": len(removed),
        "retainedPosts": retained,
        "removedPosts": removed,
    }
    AUDIT_PATH.write_text(
        json.dumps(audit, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    print(f"Gospels: {len(targets)} -> {len(retained)} ({len(removed)} removed)")


if __name__ == "__main__":
    main()
