"""Add defensible existing topics to three posts left topicless by the audit."""

from __future__ import annotations

import json
from collections import Counter
from datetime import date
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
POST_TRACKER_PATH = ROOT / "data" / "audits" / "ehrman_post_topic_link_audit_tracker.json"
TOPIC_TRACKER_PATH = ROOT / "data" / "audits" / "ehrman_topic_audit_tracker.json"

ADDITIONS = {
    "48903": {
        "topics": ["Personal Reflections"],
        "reason": "The post is a sustained personal reflection on Yeats's poem, Christian imagery, modern culture, and religious literacy.",
    },
    "48697": {
        "topics": ["Hebrew Bible Manuscripts", "New Testament Manuscripts"],
        "reason": "The post traces how chapter and verse divisions developed in manuscripts and printed editions of both the Hebrew Bible and New Testament.",
    },
    "15564": {
        "topics": ["Bible Translations (General)"],
        "reason": "The post centrally examines digital Bibles, including access to many translations, comparison of versions, and changing forms of biblical reading.",
    },
}


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
    tracked_by_id = {str(entry["wpId"]): entry for entry in post_tracker["posts"]}
    topic_entries = {entry["topic"]: entry for entry in topic_tracker["topics"]}
    valid_topics = set(topic_entries)
    today = date.today().isoformat()

    for wp_id, change in ADDITIONS.items():
        post = posts_by_id[wp_id]
        if post.get("topics"):
            raise ValueError(f"Expected topicless canonical post {wp_id}")
        unknown = set(change["topics"]) - valid_topics
        if unknown:
            raise ValueError(f"Unknown topics for {wp_id}: {sorted(unknown)}")
        post["topics"] = list(change["topics"])

        tracker_entry = tracked_by_id[wp_id]
        tracker_entry["topicsRecommended"] = list(change["topics"])
        tracker_entry["topicsAdded"] = list(change["topics"])
        tracker_entry["reason"] = change["reason"]
        tracker_entry["status"] = "applied"
        tracker_entry["appliedAt"] = today

        for topic in change["topics"]:
            topic_entry = topic_entries[topic]
            decisions = [
                decision
                for decision in topic_entry.get("decisions", [])
                if str(decision["wpId"]) != wp_id
            ]
            decisions.append(
                {
                    "wpId": wp_id,
                    "title": post["title"],
                    "decision": "add",
                    "confidence": "high",
                    "reason": change["reason"],
                }
            )
            topic_entry["decisions"] = decisions

    topic_counts = Counter(topic for post in posts for topic in post.get("topics", []))
    note = "Final post-by-post audit validation assigned this topic to a formerly topicless post."
    for change in ADDITIONS.values():
        for topic in change["topics"]:
            topic_entry = topic_entries[topic]
            topic_entry["postCountAfter"] = topic_counts[topic]
            notes = list(topic_entry.get("notes", []))
            if note not in notes:
                notes.append(note)
            topic_entry["notes"] = notes

    post_tracker.update(
        {
            "updatedAt": today,
            "canonicalAssignmentsChanged": True,
            "noChangeCount": sum(
                entry["status"] == "reviewed_no_change"
                for entry in post_tracker["posts"]
            ),
            "pendingApprovalCount": sum(
                entry["status"] == "pending_approval"
                for entry in post_tracker["posts"]
            ),
            "appliedChangeCount": sum(
                entry["status"] == "applied" for entry in post_tracker["posts"]
            ),
        }
    )
    topic_tracker["updatedAt"] = today

    write_json(POSTS_PATH, posts)
    write_json(POST_TRACKER_PATH, post_tracker)
    write_json(TOPIC_TRACKER_PATH, topic_tracker)
    print(f"Assigned existing topics to {len(ADDITIONS)} topicless posts.")


if __name__ == "__main__":
    main()
