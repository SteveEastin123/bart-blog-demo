"""Create the approved Woman Caught in Adultery topic."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
TOPICS_PATH = ROOT / "data" / "index" / "ehrman_post_topics.json"
TRACKER_PATH = ROOT / "data" / "audits" / "ehrman_topic_audit_tracker.json"
AUDIT_DATE = "2026-08-01"

TOPIC = "Woman Caught in Adultery"
KEYWORD = "Pericope Adulterae"
CATEGORY = "Textual Criticism"
DESCRIPTION = (
    "Covers the story of the woman caught in adultery, including its narrative, manuscript "
    "history, absence from the original Gospel of John, possible historicity, and treatment in "
    "Bible translations."
)

POST_IDS = [12317, 15680, 20704, 33796, 13419, 12328, 9008, 11961, 26387, 2274]


def load_json(path: Path) -> object:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value: object) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def main() -> None:
    posts = load_json(POSTS_PATH)
    topic_data = load_json(TOPICS_PATH)
    tracker = load_json(TRACKER_PATH)
    if not isinstance(posts, list) or not isinstance(topic_data, dict) or not isinstance(tracker, dict):
        raise TypeError("Unexpected source JSON shape")

    posts_by_id = {int(post["wpId"]): post for post in posts}
    if len(posts_by_id) != len(posts):
        raise ValueError("Duplicate wpId values in post search index")
    unknown_ids = [wp_id for wp_id in POST_IDS if wp_id not in posts_by_id]
    if unknown_ids:
        raise ValueError(f"Unknown wpId values: {unknown_ids}")

    topic_records = topic_data["topics"]
    if any(record["name"] == TOPIC for record in topic_records):
        raise ValueError(f"Topic already exists: {TOPIC}")
    if any(TOPIC in posts_by_id[wp_id].get("topics", []) for wp_id in POST_IDS):
        raise ValueError(f"One or more posts already use {TOPIC}")

    keywords_added = 0
    for wp_id in POST_IDS:
        post = posts_by_id[wp_id]
        post["topics"].append(TOPIC)
        if KEYWORD not in post.get("secondaryKeywords", []):
            post["secondaryKeywords"].append(KEYWORD)
            keywords_added += 1

    for post in posts:
        post["topics"] = list(dict.fromkeys(post.get("topics", [])))
        post["secondaryKeywords"] = list(dict.fromkeys(post.get("secondaryKeywords", [])))

    anchor = next(record for record in topic_records if record["name"] == "Bloody Sweat Textual Variant")
    insert_at = topic_records.index(anchor) + 1
    topic_records.insert(
        insert_at,
        {
            "name": TOPIC,
            "description": DESCRIPTION,
            "categories": [CATEGORY],
            "displayInBrowser": True,
        },
    )

    actual = [post for post in posts if TOPIC in post.get("topics", [])]
    keyword_posts = [post for post in posts if KEYWORD in post.get("secondaryKeywords", [])]
    if len(actual) != 10:
        raise ValueError(f"Unexpected {TOPIC} count: {len(actual)}")
    if any(post not in keyword_posts for post in actual):
        raise ValueError(f"Not every {TOPIC} post has the {KEYWORD} search term")

    tracker_by_name = {entry["topic"]: entry for entry in tracker["topics"]}
    if TOPIC in tracker_by_name:
        raise ValueError(f"Tracker entry already exists: {TOPIC}")
    new_tracker_entry = {
        "topic": TOPIC,
        "auditSequence": 272,
        "status": "completed",
        "postCountBefore": 0,
        "postCountAfter": len(actual),
        "descriptionBefore": DESCRIPTION,
        "descriptionRecommendation": DESCRIPTION,
        "categoriesBefore": [CATEGORY],
        "categoryRecommendation": "Retain current category placement.",
        "startedAt": AUDIT_DATE,
        "completedAt": AUDIT_DATE,
        "decisions": [
            {
                "wpId": str(post["wpId"]),
                "title": post["title"],
                "decision": "add",
                "confidence": "high",
                "reason": (
                    "The full post treats the woman caught in adultery as a primary or major "
                    "sustained subject."
                ),
            }
            for post in actual
        ],
        "notes": [
            "Created after a full-text review of 70 posts mentioning the passage; "
            "10 treat it as a major subject."
        ],
    }
    ignore_index = next(
        (index for index, entry in enumerate(tracker["topics"]) if entry["topic"] == "Ignore"),
        len(tracker["topics"]),
    )
    tracker["topics"].insert(ignore_index, new_tracker_entry)
    tracker["updatedAt"] = AUDIT_DATE

    write_json(POSTS_PATH, posts)
    write_json(TOPICS_PATH, topic_data)
    write_json(TRACKER_PATH, tracker)
    print(f"Created {TOPIC} with {len(actual)} posts.")
    print(f"Added {KEYWORD} to {keywords_added} posts; all 10 now carry the search term.")


if __name__ == "__main__":
    main()
