"""Retire the Celsus topic while preserving Celsus as a search keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
TOPICS_PATH = ROOT / "data" / "index" / "ehrman_post_topics.json"
TRACKER_PATH = ROOT / "data" / "audits" / "ehrman_topic_audit_tracker.json"
AUDIT_DATE = "2026-08-01"

CELSUS_POST_IDS = {"49778", "26348", "2249"}
DID_JESUS_EXIST_POST_IDS = {"26348", "2249"}


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

    posts_by_id = {str(post["wpId"]): post for post in posts}
    if set(posts_by_id) & CELSUS_POST_IDS != CELSUS_POST_IDS:
        missing = sorted(CELSUS_POST_IDS - set(posts_by_id))
        raise ValueError(f"Missing expected Celsus posts: {missing}")

    current_celsus_ids = {
        str(post["wpId"])
        for post in posts
        if "Celsus" in post.get("topics", [])
    }
    if current_celsus_ids != CELSUS_POST_IDS:
        raise ValueError(
            f"Expected Celsus on {sorted(CELSUS_POST_IDS)}, found {sorted(current_celsus_ids)}"
        )

    for wp_id in CELSUS_POST_IDS:
        post = posts_by_id[wp_id]
        post["topics"] = [topic for topic in post.get("topics", []) if topic != "Celsus"]
        secondary_keywords = post.setdefault("secondaryKeywords", [])
        append_unique(secondary_keywords, "Celsus")

    for wp_id in DID_JESUS_EXIST_POST_IDS:
        append_unique(posts_by_id[wp_id].setdefault("topics", []), "Did Jesus Exist?")

    topic_entries = topic_data.get("topics")
    if not isinstance(topic_entries, list):
        raise TypeError("Unexpected topics JSON shape")
    celsus_metadata = [topic for topic in topic_entries if topic.get("name") == "Celsus"]
    if len(celsus_metadata) != 1:
        raise ValueError(f"Expected one Celsus metadata record, found {len(celsus_metadata)}")
    topic_data["topics"] = [topic for topic in topic_entries if topic.get("name") != "Celsus"]

    tracker_entries = tracker.get("topics")
    if not isinstance(tracker_entries, list):
        raise TypeError("Unexpected tracker JSON shape")
    tracker_by_name = {entry["topic"]: entry for entry in tracker_entries}

    celsus_tracker = tracker_by_name["Celsus"]
    celsus_tracker.update(
        {
            "postCountAfter": 0,
            "descriptionRecommendation": "Retire the topic and preserve Celsus as a secondary keyword.",
            "categoryRecommendation": "Retire the unlinked topic.",
            "completedAt": AUDIT_DATE,
        }
    )
    for decision in celsus_tracker.get("decisions", []):
        if str(decision.get("wpId")) in CELSUS_POST_IDS:
            decision.update(
                {
                    "decision": "remove",
                    "confidence": "high",
                    "reason": "The small named-person topic was retired; Celsus remains a secondary keyword for search.",
                }
            )
    celsus_tracker.setdefault("notes", []).append(
        "The approved retirement removed Celsus from all three remaining posts and added Celsus as a secondary keyword."
    )

    did_jesus_tracker = tracker_by_name["Did Jesus Exist?"]
    existing_decision_ids = {
        str(decision.get("wpId")) for decision in did_jesus_tracker.get("decisions", [])
    }
    for wp_id in sorted(DID_JESUS_EXIST_POST_IDS):
        if wp_id not in existing_decision_ids:
            did_jesus_tracker.setdefault("decisions", []).append(
                {
                    "wpId": wp_id,
                    "title": posts_by_id[wp_id]["title"],
                    "decision": "add",
                    "confidence": "high",
                    "reason": "The post is part of an interview about Bart's book Did Jesus Exist? and evaluates evidence for Jesus' existence.",
                }
            )
    did_jesus_tracker["postCountAfter"] = sum(
        "Did Jesus Exist?" in post.get("topics", []) for post in posts
    )
    did_jesus_tracker.setdefault("notes", []).append(
        "Two Ben Witherington interview posts were added when the Celsus topic was retired."
    )

    tracker["updatedAt"] = AUDIT_DATE

    if any("Celsus" in post.get("topics", []) for post in posts):
        raise ValueError("Celsus remains assigned as a topic")
    if any(
        "Celsus" not in posts_by_id[wp_id].get("secondaryKeywords", [])
        for wp_id in CELSUS_POST_IDS
    ):
        raise ValueError("Celsus secondary keyword was not added to every expected post")
    if any(not posts_by_id[wp_id].get("topics") for wp_id in CELSUS_POST_IDS):
        raise ValueError("Retiring Celsus left an affected post without a topic")

    write_json(POSTS_PATH, posts)
    write_json(TOPICS_PATH, topic_data)
    write_json(TRACKER_PATH, tracker)

    print("Retired Celsus topic from 3 posts.")
    print("Added Celsus as a secondary keyword to 3 posts.")
    print("Added Did Jesus Exist? to 2 interview posts.")
    print(f"Did Jesus Exist? now has {did_jesus_tracker['postCountAfter']} posts.")


if __name__ == "__main__":
    main()
