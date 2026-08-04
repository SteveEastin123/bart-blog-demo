"""Apply approved post-topic audit decisions for posts 1501-1750."""

from __future__ import annotations

import json
from collections import Counter
from datetime import date
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
POST_TRACKER_PATH = ROOT / "data" / "audits" / "ehrman_post_topic_link_audit_tracker.json"
TOPIC_TRACKER_PATH = ROOT / "data" / "audits" / "ehrman_topic_audit_tracker.json"


def load_json(path: Path) -> object:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value: object) -> None:
    path.write_text(
        json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )


def main() -> None:
    posts = load_json(POSTS_PATH)
    post_tracker = load_json(POST_TRACKER_PATH)
    topic_tracker = load_json(TOPIC_TRACKER_PATH)
    if not isinstance(posts, list):
        raise TypeError("Unexpected post index shape")
    if not isinstance(post_tracker, dict) or not isinstance(topic_tracker, dict):
        raise TypeError("Unexpected tracker shape")

    posts_by_id = {str(post["wpId"]): post for post in posts}
    pending = [
        entry
        for entry in post_tracker["posts"]
        if entry["status"] == "pending_approval"
    ]
    if len(pending) != 64:
        raise ValueError(f"Expected 64 approved decisions, found {len(pending)}")
    if not all(1501 <= entry["auditSequence"] <= 1750 for entry in pending):
        raise ValueError("Found a pending decision outside posts 1501-1750")

    affected_topics: set[str] = set()
    today = date.today().isoformat()
    description_updates = 0
    for entry in pending:
        wp_id = str(entry["wpId"])
        post = posts_by_id[wp_id]
        if post.get("topics", []) != entry["topicsBefore"]:
            raise ValueError(f"Canonical topics changed unexpectedly for {wp_id}")
        post["topics"] = list(entry["topicsRecommended"])
        affected_topics.update(entry["topicsAdded"])
        affected_topics.update(entry["topicsRemoved"])
        if "descriptionRecommended" in entry:
            if post.get("description") != entry.get("descriptionBefore"):
                raise ValueError(f"Canonical description changed unexpectedly for {wp_id}")
            post["description"] = entry["descriptionRecommended"]
            description_updates += 1
        entry["status"] = "applied"
        entry["appliedAt"] = today

    for post in posts:
        post["topics"] = list(dict.fromkeys(post.get("topics", [])))

    topic_counts = Counter(topic for post in posts for topic in post.get("topics", []))
    topic_entries = {entry["topic"]: entry for entry in topic_tracker["topics"]}
    for topic in affected_topics:
        entry = topic_entries[topic]
        entry["postCountAfter"] = topic_counts[topic]
        note = "Post-by-post topic-link audit for posts 1501-1750 updated this assignment."
        notes = list(entry.get("notes", []))
        if note not in notes:
            notes.append(note)
        entry["notes"] = notes

    for post_entry in pending:
        wp_id = str(post_entry["wpId"])
        title = post_entry["title"]
        for decision_name, topics in (
            ("add", post_entry["topicsAdded"]),
            ("remove", post_entry["topicsRemoved"]),
        ):
            for topic in topics:
                topic_entry = topic_entries[topic]
                if topic_entry["status"] == "excluded":
                    continue
                decisions = [
                    decision
                    for decision in topic_entry.get("decisions", [])
                    if str(decision["wpId"]) != wp_id
                ]
                decisions.append(
                    {
                        "wpId": wp_id,
                        "title": title,
                        "decision": decision_name,
                        "confidence": "high",
                        "reason": post_entry["reason"],
                    }
                )
                topic_entry["decisions"] = decisions

    post_tracker.update(
        {
            "updatedAt": today,
            "canonicalAssignmentsChanged": True,
            "noChangeCount": sum(
                entry["status"] == "reviewed_no_change"
                for entry in post_tracker["posts"]
            ),
            "pendingApprovalCount": 0,
            "appliedChangeCount": sum(
                entry["status"] == "applied" for entry in post_tracker["posts"]
            ),
        }
    )
    topic_tracker["updatedAt"] = today

    write_json(POSTS_PATH, posts)
    write_json(POST_TRACKER_PATH, post_tracker)
    write_json(TOPIC_TRACKER_PATH, topic_tracker)
    print(
        f"Applied {len(pending)} decisions and "
        f"{description_updates} description updates for posts 1501-1750."
    )


if __name__ == "__main__":
    main()
