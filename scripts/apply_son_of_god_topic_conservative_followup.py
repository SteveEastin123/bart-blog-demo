"""Apply the approved extra-conservative Son of God topic follow-up."""

from __future__ import annotations

import json
import re
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
TRACKER_PATH = ROOT / "data" / "audits" / "ehrman_topic_audit_tracker.json"
AUDIT_PATH = (
    ROOT / "data" / "audits" / "son_of_god_topic_conservative_followup_2026_08_19.json"
)

AUDIT_DATE = "2026-08-19"
TOPIC = "Son of God"

REMOVALS = {
    15925: (
        "The post is an autobiographical account of Bart's developing doubts. Son of "
        "God appears in the title but is not analyzed as a sustained subject."
    ),
    12408: (
        "The post is a personal account of Bart's ministry and developing doubts rather "
        "than a sustained examination of divine sonship."
    ),
    12363: (
        "The post examines the Mark 1:2 quotation variant and mentions the Mark 1:1 Son "
        "of God variant only to introduce the next post."
    ),
    3947: (
        "The post is a personal account of Bart's ministry and developing doubts rather "
        "than a sustained examination of divine sonship."
    ),
}

RESTORE_KEYWORD_IDS = {15925, 12408, 3947}

DESCRIPTION_UPDATES = {
    12363: (
        "Examines a scribal alteration in Mark 1:2 that replaces \"Isaiah the prophet\" "
        "with \"the prophets\" to avoid an apparent discrepancy in the Gospel's "
        "opening quotation."
    )
}


def load_json(path: Path) -> object:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value: object) -> None:
    path.write_text(
        json.dumps(value, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
        newline="\n",
    )


def normalize(value: object) -> str:
    text = str(value or "").strip().lower().replace("&", " and ")
    text = re.sub(r"[^a-z0-9]+", " ", text).strip()
    return re.sub(r"\s+", " ", text)


def main() -> None:
    posts = load_json(POSTS_PATH)
    tracker = load_json(TRACKER_PATH)
    if not isinstance(posts, list) or not isinstance(tracker, dict):
        raise TypeError("Unexpected source JSON shape")

    posts_by_id = {int(post["wpId"]): post for post in posts}
    unknown_ids = sorted(set(REMOVALS) - set(posts_by_id))
    if unknown_ids:
        raise ValueError(f"Unknown wpId values: {unknown_ids}")

    changes: list[dict[str, object]] = []
    for wp_id, reason in REMOVALS.items():
        post = posts_by_id[wp_id]
        if TOPIC not in post.get("topics", []):
            raise ValueError(f"Post {wp_id} is missing topic {TOPIC}")

        post["topics"] = [topic for topic in post["topics"] if topic != TOPIC]
        if not post["topics"]:
            raise ValueError(f"Removing {TOPIC} would leave post {wp_id} without a topic")

        keyword_action = "not restored because the reference is incidental"
        if wp_id in RESTORE_KEYWORD_IDS:
            post["secondaryKeywords"] = list(
                dict.fromkeys([*post.get("secondaryKeywords", []), TOPIC])
            )
            keyword_action = "restored as a meaningful supporting keyword"

        description_before = post.get("description", "")
        if wp_id in DESCRIPTION_UPDATES:
            post["description"] = DESCRIPTION_UPDATES[wp_id]

        changes.append(
            {
                "wpId": str(wp_id),
                "title": post["title"],
                "decision": "remove",
                "confidence": "high",
                "reason": reason,
                "secondaryKeywordAction": keyword_action,
                "descriptionBefore": description_before,
                "descriptionAfter": post.get("description", ""),
            }
        )

    actual_count = sum(TOPIC in post.get("topics", []) for post in posts)
    if actual_count != 35:
        raise ValueError(f"Expected 35 {TOPIC} posts, found {actual_count}")

    topic_key = normalize(TOPIC)
    redundant = [
        str(post["wpId"])
        for post in posts
        if TOPIC in post.get("topics", [])
        and any(
            normalize(keyword) == topic_key
            for keyword in post.get("secondaryKeywords", [])
        )
    ]
    if redundant:
        raise ValueError(f"Topic posts retain same-name keywords: {redundant}")

    restored = {
        wp_id
        for wp_id in RESTORE_KEYWORD_IDS
        if TOPIC in posts_by_id[wp_id].get("secondaryKeywords", [])
    }
    if restored != RESTORE_KEYWORD_IDS:
        raise ValueError(f"Unexpected restored keyword set: {sorted(restored)}")
    if TOPIC in posts_by_id[12363].get("secondaryKeywords", []):
        raise ValueError("Incidental Mark 1:2 post retained the Son of God keyword")

    tracker_entry = next(
        (entry for entry in tracker["topics"] if entry["topic"] == TOPIC),
        None,
    )
    if tracker_entry is None:
        raise ValueError(f"Topic audit tracker is missing {TOPIC}")
    tracker_entry["postCountAfter"] = actual_count
    removal_ids = {str(wp_id) for wp_id in REMOVALS}
    for decision in tracker_entry["decisions"]:
        if str(decision["wpId"]) not in removal_ids:
            continue
        wp_id = int(decision["wpId"])
        decision.update(
            {
                "decision": "remove",
                "confidence": "high",
                "reason": REMOVALS[wp_id],
                "secondaryKeywordAction": next(
                    change["secondaryKeywordAction"]
                    for change in changes
                    if change["wpId"] == str(wp_id)
                ),
            }
        )
    tracker_entry.setdefault("notes", []).append(
        "An extra-conservative full-text follow-up removed four assignments; 35 posts "
        "retain the topic because divine sonship drives a substantial part of their argument."
    )
    tracker["updatedAt"] = AUDIT_DATE

    audit = {
        "auditDate": AUDIT_DATE,
        "topic": TOPIC,
        "standard": (
            "Retain the topic only when the meaning, use, textual presence, or "
            "development of Son of God drives a substantial part of the post's argument."
        ),
        "postCountBefore": 39,
        "postCountAfter": actual_count,
        "removedCount": len(changes),
        "retainedCount": actual_count,
        "changes": changes,
        "topicDescriptionChanged": False,
        "topicDescriptionReason": (
            "The existing topic description accurately describes the 35 retained posts."
        ),
    }

    write_json(POSTS_PATH, posts)
    write_json(TRACKER_PATH, tracker)
    write_json(AUDIT_PATH, audit)
    print(f"{TOPIC}: 39 -> {actual_count} posts")
    print(f"Restored {TOPIC} as a secondary keyword on {len(restored)} posts")
    print("Updated one inaccurate post description")


if __name__ == "__main__":
    main()
