"""Apply the approved conservative follow-up audit for four new topics."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
TRACKER_PATH = ROOT / "data" / "audits" / "ehrman_topic_audit_tracker.json"
AUDIT_PATH = ROOT / "data" / "audits" / "new_topic_conservative_followup_2026_08_19.json"
AUDIT_DATE = "2026-08-19"

REMOVALS = {
    "Kingdom of God": {
        36569: (
            "The post evaluates whether Jesus expected the end within his generation; the "
            "kingdom is supporting apocalyptic context rather than a sustained subject."
        ),
        35324: (
            "The post evaluates whether Jesus expected the end within his generation; the "
            "kingdom is supporting apocalyptic context rather than a sustained subject."
        ),
    },
    "Documentary Hypothesis": {
        25647: (
            "The post establishes why Moses probably did not write the Pentateuch but does not "
            "examine the Documentary Hypothesis or JEDP model."
        ),
        11587: (
            "The post surveys early challenges to Mosaic authorship but does not examine the "
            "Documentary Hypothesis or JEDP model."
        ),
    },
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
    unknown_ids = sorted(
        wp_id for decisions in REMOVALS.values() for wp_id in decisions if wp_id not in posts_by_id
    )
    if unknown_ids:
        raise ValueError(f"Unknown wpId values: {unknown_ids}")

    audit_decisions: list[dict[str, object]] = []
    for topic, decisions in REMOVALS.items():
        for wp_id, reason in decisions.items():
            post = posts_by_id[wp_id]
            if topic not in post.get("topics", []):
                raise ValueError(f"Post {wp_id} is missing topic {topic}")
            post["topics"] = [value for value in post["topics"] if value != topic]

            keyword_action = "not restored"
            if topic == "Kingdom of God":
                post["secondaryKeywords"] = list(
                    dict.fromkeys([*post.get("secondaryKeywords", []), topic])
                )
                keyword_action = "restored as a meaningful supporting keyword"

            if not post["topics"]:
                raise ValueError(f"Removing {topic} would leave post {wp_id} without a topic")
            audit_decisions.append(
                {
                    "topic": topic,
                    "wpId": str(wp_id),
                    "title": post["title"],
                    "decision": "remove",
                    "confidence": "high",
                    "reason": reason,
                    "secondaryKeywordAction": keyword_action,
                }
            )

    expected_counts = {
        "Kingdom of God": 25,
        "Gospel of Philip": 7,
        "Documentary Hypothesis": 11,
        "Peter and Cephas": 5,
    }
    actual_counts = {
        topic: sum(topic in post.get("topics", []) for post in posts)
        for topic in expected_counts
    }
    if actual_counts != expected_counts:
        raise ValueError(f"Unexpected topic counts: {actual_counts}")

    redundant = [
        (str(post["wpId"]), topic)
        for post in posts
        for topic in expected_counts
        if topic in post.get("topics", []) and topic in post.get("secondaryKeywords", [])
    ]
    if redundant:
        raise ValueError(f"Topic posts retain same-name keywords: {redundant}")

    tracker_by_name = {entry["topic"]: entry for entry in tracker["topics"]}
    for topic, decisions in REMOVALS.items():
        entry = tracker_by_name[topic]
        entry["postCountAfter"] = expected_counts[topic]
        removed_ids = {str(wp_id) for wp_id in decisions}
        for decision in entry["decisions"]:
            if str(decision["wpId"]) in removed_ids:
                decision.update(
                    {
                        "decision": "remove",
                        "confidence": "high",
                        "reason": decisions[int(decision["wpId"])],
                    }
                )
        entry.setdefault("notes", []).append(
            "A conservative follow-up audit removed two assignments that did not treat the topic "
            "as a major sustained subject."
        )

    for topic in ("Gospel of Philip", "Peter and Cephas"):
        tracker_by_name[topic].setdefault("notes", []).append(
            "A conservative follow-up audit confirmed every current assignment."
        )
    tracker["updatedAt"] = AUDIT_DATE

    audit = {
        "auditDate": AUDIT_DATE,
        "standard": (
            "Retain a topic only when it is a primary or major sustained subject of the full post."
        ),
        "results": [
            {"topic": topic, "postCountAfter": count}
            for topic, count in expected_counts.items()
        ],
        "changes": audit_decisions,
    }

    write_json(POSTS_PATH, posts)
    write_json(TRACKER_PATH, tracker)
    write_json(AUDIT_PATH, audit)
    for topic, count in actual_counts.items():
        print(f"{topic}: {count} posts")
    print("Applied four conservative topic removals.")


if __name__ == "__main__":
    main()
