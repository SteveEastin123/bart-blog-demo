"""Create the post-by-post topic-link audit tracker without changing canonical data."""

from __future__ import annotations

import json
from datetime import date
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
TOPICS_PATH = ROOT / "data" / "index" / "ehrman_post_topics.json"
OUTPUT_PATH = (
    ROOT / "data" / "audits" / "ehrman_post_topic_link_audit_tracker.json"
)

RECOMMENDATIONS = {
    "49926": {
        "add": ["Roman Persecution of Christians"],
        "remove": [],
        "reason": (
            "A substantial question-and-response section examines the persecution at "
            "Lyon and explains Roman hostility in terms of Christians' refusal to "
            "participate in public cult."
        ),
    },
    "49838": {
        "add": [],
        "remove": ["Miracle Claims and Apologetics"],
        "reason": (
            "The post narrates hagiographic miracle traditions and their reported role "
            "in conversion, but it does not sustain an apologetic argument for or "
            "against the truth of the miracle claims."
        ),
    },
    "49716": {
        "add": ["Romans"],
        "remove": [],
        "reason": (
            "After the administrative introduction, most of the post is a substantive "
            "study guide to Romans 1-3, including the letter's purpose, argument, and "
            "theology."
        ),
    },
    "49674": {
        "add": ["Ignore"],
        "remove": ["Gospel of Thomas"],
        "reason": (
            "The post only announces a forthcoming webinar and provides logistics; it "
            "does not discuss the Gospel of Thomas itself."
        ),
    },
}


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))[:100]
    topic_data = json.loads(TOPICS_PATH.read_text(encoding="utf-8"))["topics"]
    valid_topics = {topic["name"] for topic in topic_data}

    if len(posts) != 100:
        raise ValueError(f"Expected 100 posts, found {len(posts)}")

    entries = []
    for sequence, post in enumerate(posts, start=1):
        wp_id = str(post["wpId"])
        original = list(post.get("topics", []))
        recommendation = RECOMMENDATIONS.get(
            wp_id, {"add": [], "remove": [], "reason": None}
        )
        added = recommendation["add"]
        removed = recommendation["remove"]

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
                "sourceIndex": sequence - 1,
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

    today = date.today().isoformat()
    tracker = {
        "version": 1,
        "createdAt": today,
        "updatedAt": today,
        "auditScope": "First 100 canonical search-index posts in current newest-first order",
        "auditMethod": (
            "Each full local post text was reviewed individually. Existing topics were "
            "retained only when they represented a primary or major sustained subject; "
            "only existing topics could be added."
        ),
        "sourceFiles": [
            "data/index/ehrman_post_search_index.json",
            "data/index/ehrman_post_topics.json",
            "data/raw/posts.jsonl",
        ],
        "canonicalAssignmentsChanged": False,
        "reviewedPostCount": len(entries),
        "noChangeCount": sum(
            entry["status"] == "reviewed_no_change" for entry in entries
        ),
        "pendingApprovalCount": sum(
            entry["status"] == "pending_approval" for entry in entries
        ),
        "posts": entries,
    }

    OUTPUT_PATH.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT_PATH.write_text(
        json.dumps(tracker, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    print(
        f"Wrote {OUTPUT_PATH.relative_to(ROOT)}: "
        f"{tracker['reviewedPostCount']} reviewed, "
        f"{tracker['pendingApprovalCount']} pending approval"
    )


if __name__ == "__main__":
    main()
