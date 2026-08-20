"""Retire Holy Spirit in Luke-Acts while preserving useful search access."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
TOPICS_PATH = ROOT / "data" / "index" / "ehrman_post_topics.json"
TRACKER_PATH = ROOT / "data" / "audits" / "ehrman_topic_audit_tracker.json"
AUDIT_PATH = ROOT / "data" / "audits" / "holy_spirit_in_luke_acts_retirement_2026_08_19.json"

TOPIC = "Holy Spirit in Luke-Acts"
KEYWORD = "Holy Spirit"
SPIRIT_POST_ID = "26011"
PENTECOST_POST_ID = "25906"
EXPECTED_IDS = {SPIRIT_POST_ID, PENTECOST_POST_ID}
AUDIT_DATE = "2026-08-19"


def load_json(path: Path) -> object:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value: object) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def append_unique(values: list[str], value: str) -> None:
    if value.casefold() not in {item.casefold() for item in values}:
        values.append(value)


def main() -> None:
    posts = load_json(POSTS_PATH)
    topic_data = load_json(TOPICS_PATH)
    tracker = load_json(TRACKER_PATH)
    if not isinstance(posts, list) or not isinstance(topic_data, dict) or not isinstance(tracker, dict):
        raise TypeError("Unexpected source JSON shape")

    assigned_ids = {
        str(post["wpId"])
        for post in posts
        if TOPIC in post.get("topics", [])
    }
    if assigned_ids != EXPECTED_IDS:
        raise ValueError(f"Expected {TOPIC} on {sorted(EXPECTED_IDS)}, found {sorted(assigned_ids)}")

    posts_by_id = {str(post["wpId"]): post for post in posts}
    for wp_id in EXPECTED_IDS:
        post = posts_by_id[wp_id]
        post["topics"] = [name for name in post.get("topics", []) if name != TOPIC]
        append_unique(post.setdefault("secondaryKeywords", []), KEYWORD)

    append_unique(posts_by_id[PENTECOST_POST_ID].setdefault("topics", []), "Acts")
    posts_by_id[PENTECOST_POST_ID]["secondaryKeywords"] = [
        keyword
        for keyword in posts_by_id[PENTECOST_POST_ID].get("secondaryKeywords", [])
        if keyword != "Acts"
    ]

    topic_entries = topic_data.get("topics")
    if not isinstance(topic_entries, list):
        raise TypeError("Unexpected topic metadata shape")
    matches = [entry for entry in topic_entries if entry.get("name") == TOPIC]
    if len(matches) != 1:
        raise ValueError(f"Expected one {TOPIC} metadata record, found {len(matches)}")
    topic_data["topics"] = [entry for entry in topic_entries if entry.get("name") != TOPIC]

    tracker_entries = tracker.get("topics")
    if not isinstance(tracker_entries, list):
        raise TypeError("Unexpected topic tracker shape")
    tracker_entry = next(entry for entry in tracker_entries if entry.get("topic") == TOPIC)
    tracker_entry.update(
        {
            "postCountAfter": 0,
            "descriptionRecommendation": "Retire the topic and preserve Holy Spirit as a secondary keyword.",
            "categoryRecommendation": "Retire the unlinked two-post topic.",
            "completedAt": AUDIT_DATE,
        }
    )
    for decision in tracker_entry.get("decisions", []):
        if str(decision.get("wpId")) in EXPECTED_IDS:
            decision.update(
                {
                    "decision": "remove",
                    "confidence": "high",
                    "reason": "The small unlinked topic was retired; Holy Spirit remains a secondary keyword for search.",
                }
            )
    tracker_entry.setdefault("notes", []).append(
        "Retired the topic, added Holy Spirit as a secondary keyword to both posts, and assigned Acts to the Pentecost post."
    )
    tracker["updatedAt"] = AUDIT_DATE

    if any(TOPIC in post.get("topics", []) for post in posts):
        raise ValueError(f"{TOPIC} remains assigned to a post")
    if any(KEYWORD not in posts_by_id[wp_id].get("secondaryKeywords", []) for wp_id in EXPECTED_IDS):
        raise ValueError("Holy Spirit was not added to both affected posts")
    if "Acts" not in posts_by_id[PENTECOST_POST_ID].get("topics", []):
        raise ValueError("Acts was not assigned to the Pentecost post")
    if any(not posts_by_id[wp_id].get("topics") for wp_id in EXPECTED_IDS):
        raise ValueError("The retirement left an affected post without a topic")

    audit = {
        "auditDate": AUDIT_DATE,
        "retiredTopic": TOPIC,
        "replacementKeyword": KEYWORD,
        "affectedPosts": [
            {
                "wpId": wp_id,
                "title": posts_by_id[wp_id]["title"],
                "topicsAfter": posts_by_id[wp_id]["topics"],
                "secondaryKeywordsAfter": posts_by_id[wp_id]["secondaryKeywords"],
            }
            for wp_id in sorted(EXPECTED_IDS)
        ],
    }

    write_json(POSTS_PATH, posts)
    write_json(TOPICS_PATH, topic_data)
    write_json(TRACKER_PATH, tracker)
    write_json(AUDIT_PATH, audit)

    print(f"Retired {TOPIC} from {len(EXPECTED_IDS)} posts.")
    print(f"Added {KEYWORD} as a secondary keyword to both posts.")
    print("Added Acts to The Coming of the Spirit on the Day of Pentecost.")


if __name__ == "__main__":
    main()
