"""Apply the approved strict-dominance audits for Mary and Joseph."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
TRACKER_PATH = ROOT / "data" / "audits" / "ehrman_topic_audit_tracker.json"
AUDIT_PATH = ROOT / "data" / "audits" / "mary_joseph_strict_dominance_audit_2026_08_19.json"

AUDIT_DATE = "2026-08-19"

REMOVALS = {
    "Mary, Mother of Jesus": {
        37156: "Jesus' siblings and competing explanations of their relationship dominate the post.",
        36915: "Pantera and the identity of Jesus' biological father dominate the post.",
        36908: "Pantera traditions and the identity of Jesus' biological father dominate the post.",
        36902: "The broader historical question of Jesus' biological father dominates the post.",
        13510: "Jesus' siblings and competing explanations of their relationship dominate the post.",
        5039: "Jesus' siblings and the Proto-Gospel's explanation of them dominate the post.",
    },
    "Joseph, Father of Jesus": {
        37156: "Jesus' siblings and competing explanations of their relationship dominate the post.",
        36915: "Pantera and the identity of Jesus' biological father dominate the post.",
        36908: "Pantera traditions and the identity of Jesus' biological father dominate the post.",
        34743: "The proposed relationship between Abdes Pantera and Jesus dominates the post.",
        34094: "The proposed relationship between Abdes Pantera and Jesus dominates the post.",
        22557: "Mary's conception, Joseph's reaction, and Mary's public vindication dominate the post collectively; Joseph is not the dominant subject.",
        13510: "Jesus' siblings and competing explanations of their relationship dominate the post.",
        5039: "Jesus' siblings and the Proto-Gospel's explanation of them dominate the post.",
    },
}

EXPECTED_BEFORE = {
    "Mary, Mother of Jesus": 17,
    "Joseph, Father of Jesus": 15,
}

EXPECTED_AFTER = {
    "Mary, Mother of Jesus": 11,
    "Joseph, Father of Jesus": 7,
}


def load_json(path: Path) -> object:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value: object) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def main() -> None:
    posts = load_json(POSTS_PATH)
    tracker = load_json(TRACKER_PATH)
    if not isinstance(posts, list) or not isinstance(tracker, dict):
        raise TypeError("Unexpected source JSON shape")

    posts_by_id = {int(post["wpId"]): post for post in posts}
    current_counts = {
        topic: sum(topic in post.get("topics", []) for post in posts)
        for topic in REMOVALS
    }
    if current_counts != EXPECTED_BEFORE:
        raise ValueError(f"Unexpected current topic counts: {current_counts}")

    changes: list[dict[str, object]] = []
    for topic, decisions in REMOVALS.items():
        for wp_id, reason in decisions.items():
            post = posts_by_id.get(wp_id)
            if post is None:
                raise ValueError(f"Unknown wpId: {wp_id}")
            if topic not in post.get("topics", []):
                raise ValueError(f"Post {wp_id} is missing topic {topic}")

            post["topics"] = [value for value in post["topics"] if value != topic]
            if not post["topics"]:
                raise ValueError(f"Removing {topic} would leave post {wp_id} without a topic")
            post["secondaryKeywords"] = list(
                dict.fromkeys([*post.get("secondaryKeywords", []), topic])
            )
            changes.append(
                {
                    "topic": topic,
                    "wpId": str(wp_id),
                    "title": post["title"],
                    "decision": "remove",
                    "confidence": "high",
                    "reason": reason,
                    "secondaryKeywordAction": "restored as a meaningful supporting keyword",
                }
            )

    after_counts = {
        topic: sum(topic in post.get("topics", []) for post in posts)
        for topic in REMOVALS
    }
    if after_counts != EXPECTED_AFTER:
        raise ValueError(f"Unexpected resulting topic counts: {after_counts}")

    redundant = [
        (str(post["wpId"]), topic)
        for post in posts
        for topic in REMOVALS
        if topic in post.get("topics", []) and topic in post.get("secondaryKeywords", [])
    ]
    if redundant:
        raise ValueError(f"Retained topic posts also have the same-name keyword: {redundant}")

    tracker_by_name = {entry["topic"]: entry for entry in tracker["topics"]}
    for topic, decisions in REMOVALS.items():
        entry = tracker_by_name[topic]
        entry["postCountAfter"] = EXPECTED_AFTER[topic]
        removals_by_id = {str(wp_id): reason for wp_id, reason in decisions.items()}
        for decision in entry["decisions"]:
            wp_id = str(decision["wpId"])
            if wp_id in removals_by_id:
                decision.update(
                    {
                        "decision": "remove",
                        "confidence": "high",
                        "reason": removals_by_id[wp_id],
                    }
                )
        entry.setdefault("notes", []).append(
            "A strict-dominance follow-up retained only posts in which the named figure is a "
            "dominant, sustained subject; removed assignments were returned to secondary keywords."
        )
    tracker["updatedAt"] = AUDIT_DATE

    audit = {
        "auditDate": AUDIT_DATE,
        "standard": (
            "Retain the topic only when Mary or Joseph is a dominant, sustained subject of the "
            "full post rather than supporting context for another subject."
        ),
        "results": [
            {
                "topic": topic,
                "postCountBefore": EXPECTED_BEFORE[topic],
                "postCountAfter": EXPECTED_AFTER[topic],
            }
            for topic in REMOVALS
        ],
        "retained": {
            topic: [
                {"wpId": str(post["wpId"]), "title": post["title"]}
                for post in posts
                if topic in post.get("topics", [])
            ]
            for topic in REMOVALS
        },
        "removed": changes,
    }

    write_json(POSTS_PATH, posts)
    write_json(TRACKER_PATH, tracker)
    write_json(AUDIT_PATH, audit)
    for topic in REMOVALS:
        print(f"{topic}: {EXPECTED_BEFORE[topic]} -> {EXPECTED_AFTER[topic]} posts")
    print(f"Recorded {len(changes)} topic removals and restored supporting keywords.")


if __name__ == "__main__":
    main()
