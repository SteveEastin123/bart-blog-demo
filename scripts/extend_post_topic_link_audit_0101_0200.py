"""Record post-topic audit recommendations for posts 101-200.

This extends the audit tracker only. It does not alter canonical post topics,
descriptions, the standalone demo, or SQLite data.
"""

from __future__ import annotations

import json
from datetime import date
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
TOPICS_PATH = ROOT / "data" / "index" / "ehrman_post_topics.json"
TRACKER_PATH = ROOT / "data" / "audits" / "ehrman_post_topic_link_audit_tracker.json"

RECOMMENDATIONS = {
    "49078": {
        "add": [],
        "remove": ["Gospel Eyewitness Claims"],
        "reason": (
            "The post surveys Papias and his importance for early Christian history. "
            "Gospel authorship is a significant strand, but claims that the Gospels "
            "derive from eyewitness testimony are not a sustained subject."
        ),
    },
    "48977": {
        "add": ["Textual Variants"],
        "remove": [],
        "reason": (
            "The post substantially analyzes competing manuscript forms of Luke "
            "22:19-20 and the reasons scribes may have changed the passage."
        ),
    },
    "48814": {
        "add": ["Canon Formation"],
        "remove": [],
        "reason": (
            "The post explains how early Christians connected apostolic authorship "
            "with scriptural authority and the emerging canon."
        ),
    },
    "48795": {
        "add": [],
        "remove": ["Papias"],
        "reason": (
            "Papias appears in only one of four separate reader questions and is not "
            "a sustained subject of the post."
        ),
    },
    "48760": {
        "add": ["Ignore"],
        "remove": ["Development of the Trinity"],
        "reason": (
            "The post is an administrative webinar announcement and does not discuss "
            "the historical development of the Trinity."
        ),
    },
}


def load_json(path: Path) -> object:
    return json.loads(path.read_text(encoding="utf-8"))


def main() -> None:
    posts = load_json(POSTS_PATH)
    topics_data = load_json(TOPICS_PATH)
    tracker = load_json(TRACKER_PATH)
    if not isinstance(posts, list):
        raise TypeError("Unexpected post index shape")
    if not isinstance(topics_data, dict) or not isinstance(tracker, dict):
        raise TypeError("Unexpected topic or tracker shape")
    if len(tracker.get("posts", [])) != 100:
        raise ValueError("Tracker must contain exactly the completed first 100 posts")

    valid_topics = {topic["name"] for topic in topics_data["topics"]}
    entries = list(tracker["posts"])
    for source_index, post in enumerate(posts[100:200], start=100):
        sequence = source_index + 1
        wp_id = str(post["wpId"])
        original = list(post.get("topics", []))
        recommendation = RECOMMENDATIONS.get(
            wp_id, {"add": [], "remove": [], "reason": None}
        )
        added = list(recommendation["add"])
        removed = list(recommendation["remove"])

        unknown = (set(original) | set(added) | set(removed)) - valid_topics
        if unknown:
            raise ValueError(f"Unknown topics for {wp_id}: {sorted(unknown)}")
        if not set(removed).issubset(original):
            raise ValueError(f"Cannot remove absent topic from {wp_id}: {removed}")
        if set(added) & set(original):
            raise ValueError(f"Cannot add existing topic to {wp_id}: {added}")

        recommended = [topic for topic in original if topic not in removed]
        recommended.extend(topic for topic in added if topic not in recommended)
        changed = bool(added or removed)
        entries.append(
            {
                "auditSequence": sequence,
                "sourceIndex": source_index,
                "wpId": wp_id,
                "dateText": post.get("dateText"),
                "title": post["title"],
                "status": "pending_approval" if changed else "reviewed_no_change",
                "topicsBefore": original,
                "topicsRecommended": recommended,
                "topicsAdded": added,
                "topicsRemoved": removed,
                "reason": recommendation["reason"],
            }
        )

    expected_recommendations = set(RECOMMENDATIONS)
    recorded_recommendations = {
        entry["wpId"] for entry in entries[100:] if entry["status"] == "pending_approval"
    }
    if recorded_recommendations != expected_recommendations:
        raise ValueError(
            "Recommendation mismatch: "
            f"expected {sorted(expected_recommendations)}, "
            f"found {sorted(recorded_recommendations)}"
        )

    tracker["posts"] = entries
    tracker.update(
        {
            "updatedAt": date.today().isoformat(),
            "auditScope": (
                "First 200 canonical search-index posts in current newest-first order"
            ),
            "reviewedPostCount": len(entries),
            "noChangeCount": sum(
                entry["status"] == "reviewed_no_change" for entry in entries
            ),
            "pendingApprovalCount": sum(
                entry["status"] == "pending_approval" for entry in entries
            ),
            "appliedChangeCount": sum(
                entry["status"] == "applied" for entry in entries
            ),
        }
    )
    TRACKER_PATH.write_text(
        json.dumps(tracker, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    print(
        f"Recorded {len(entries)} reviewed posts: "
        f"{tracker['noChangeCount']} no change, "
        f"{tracker['appliedChangeCount']} applied, "
        f"{tracker['pendingApprovalCount']} pending approval."
    )


if __name__ == "__main__":
    main()
