"""Apply the approved strict-majority audit of the Kingdom of God topic."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
TOPICS_PATH = ROOT / "data" / "index" / "ehrman_post_topics.json"
TRACKER_PATH = ROOT / "data" / "audits" / "ehrman_topic_audit_tracker.json"
AUDIT_PATH = ROOT / "data" / "audits" / "kingdom_of_god_strict_majority_audit_2026_08_19.json"

AUDIT_DATE = "2026-08-19"
TOPIC = "Kingdom of God"
DESCRIPTION = (
    "Examines Jesus' proclamation of the coming Kingdom of God, including its imminent "
    "apocalyptic arrival, the judgment and reversal it would bring, who would enter it, "
    "and how later Gospel traditions reshaped that expectation."
)

REMOVALS = {
    50382: "The temple-tax story and Jesus' attitude toward religious obligations dominate the post.",
    50329: "Taxation, government, and Jesus' attitude toward earthly institutions dominate the post.",
    50317: "The triumphal entry, Jesus' public identity, and messiahship dominate the post.",
    50288: "Jesus' relationship to political activism and social reform dominates the post.",
    50249: "Stoic indifference and Jesus' attitude toward society dominate the post.",
    41368: "The historical case that Jesus considered himself the Messiah dominates the post.",
    37655: "The purpose and interpretation of Jesus' miracles dominate the post.",
    31849: "Jesus' teaching about wealth, poverty, and relinquishing possessions dominates the post.",
    29786: "Whether Jesus' ethical teachings are realistic dominates the post.",
    28981: "The comparison between the messages of Jesus and Paul dominates the post.",
    28939: "Whether Jesus' ethical teachings are realistic dominates the post.",
    27343: "The salvation and future reward of the righteous dominate the post.",
    15382: "The theological meaning of Jesus' miracle traditions dominates the post.",
    14882: "The similarities and differences between Jesus and Paul dominate the post.",
    9333: "Jesus' understanding of his own messiahship dominates the post.",
    8812: "Why Jesus performs miracles in the Synoptic Gospels dominates the post.",
    7315: "The similarities and differences between Jesus and Paul dominate the post.",
}


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
    missing_ids = sorted(set(REMOVALS) - set(posts_by_id))
    if missing_ids:
        raise ValueError(f"Unknown wpId values: {missing_ids}")

    before = [post for post in posts if TOPIC in post.get("topics", [])]
    if len(before) != 25:
        raise ValueError(f"Expected 25 current {TOPIC} posts, found {len(before)}")

    decisions: list[dict[str, object]] = []
    for wp_id, reason in REMOVALS.items():
        post = posts_by_id[wp_id]
        if TOPIC not in post.get("topics", []):
            raise ValueError(f"Post {wp_id} is missing topic {TOPIC}")

        post["topics"] = [value for value in post["topics"] if value != TOPIC]
        if not post["topics"]:
            raise ValueError(f"Removing {TOPIC} would leave post {wp_id} without a topic")
        post["secondaryKeywords"] = list(
            dict.fromkeys([*post.get("secondaryKeywords", []), TOPIC])
        )
        decisions.append(
            {
                "wpId": str(wp_id),
                "title": post["title"],
                "decision": "remove",
                "confidence": "high",
                "reason": reason,
                "secondaryKeywordAction": "restored as a meaningful supporting keyword",
            }
        )

    topic_record = next(
        record for record in topic_data["topics"] if record["name"] == TOPIC
    )
    description_before = topic_record["description"]
    topic_record["description"] = DESCRIPTION

    after = [post for post in posts if TOPIC in post.get("topics", [])]
    if len(after) != 8:
        raise ValueError(f"Expected 8 retained {TOPIC} posts, found {len(after)}")
    redundant = [
        str(post["wpId"])
        for post in after
        if TOPIC in post.get("secondaryKeywords", [])
    ]
    if redundant:
        raise ValueError(f"Retained topic posts also have the same-name keyword: {redundant}")

    tracker_entry = next(entry for entry in tracker["topics"] if entry["topic"] == TOPIC)
    tracker_entry["postCountAfter"] = 8
    tracker_entry["descriptionRecommendation"] = DESCRIPTION
    removals_by_id = {str(wp_id): reason for wp_id, reason in REMOVALS.items()}
    for decision in tracker_entry["decisions"]:
        wp_id = str(decision["wpId"])
        if wp_id in removals_by_id:
            decision.update(
                {
                    "decision": "remove",
                    "confidence": "high",
                    "reason": removals_by_id[wp_id],
                }
            )
    tracker_entry.setdefault("notes", []).append(
        "A strict-majority follow-up retained only posts in which the Kingdom of God is the "
        "dominant subject; 17 supporting-topic assignments were returned to secondary keywords."
    )
    tracker["updatedAt"] = AUDIT_DATE

    audit = {
        "auditDate": AUDIT_DATE,
        "topic": TOPIC,
        "standard": (
            "Retain the topic only when the Kingdom of God itself occupies the majority of the "
            "post, rather than serving as context for another dominant subject."
        ),
        "postCountBefore": 25,
        "postCountAfter": 8,
        "descriptionBefore": description_before,
        "descriptionAfter": DESCRIPTION,
        "retained": [
            {"wpId": str(post["wpId"]), "title": post["title"]}
            for post in after
        ],
        "removed": decisions,
    }

    write_json(POSTS_PATH, posts)
    write_json(TOPICS_PATH, topic_data)
    write_json(TRACKER_PATH, tracker)
    write_json(AUDIT_PATH, audit)
    print(f"{TOPIC}: {len(before)} -> {len(after)} posts")
    print(f"Restored {TOPIC} as a secondary keyword on {len(REMOVALS)} removed posts.")
    print("Updated the topic description and audit tracker.")


if __name__ == "__main__":
    main()
