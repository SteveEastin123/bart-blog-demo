"""Apply the approved full-text topic audits for sequences 201-225."""

from __future__ import annotations

import json
from collections import Counter
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
TOPICS_PATH = ROOT / "data" / "index" / "ehrman_post_topics.json"
TRACKER_PATH = ROOT / "data" / "audits" / "ehrman_topic_audit_tracker.json"
AUDIT_DATE = "2026-08-01"


PLANS: dict[str, dict[str, object]] = {
    "Gospel of Jesus' Wife Fragment": {"sequence": 201, "remove": [], "add": []},
    "Wealth and Poverty in Antiquity": {
        "sequence": 202,
        "remove": [41540, 41515, 41509],
        "add": [],
    },
    "Women in Early Christianity": {"sequence": 203, "remove": [], "add": []},
    "2 Corinthians": {"sequence": 204, "remove": [], "add": []},
    "Apostolic Death Traditions": {"sequence": 205, "remove": [], "add": []},
    "Atonement (General)": {"sequence": 206, "remove": [], "add": []},
    "Free Will Explanations of Suffering": {
        "sequence": 207,
        "remove": [28489, 22472, 22355, 12756],
        "add": [],
    },
    "Historical Study and Theology": {
        "sequence": 208,
        "remove": [],
        "add": [15505, 47026],
    },
    "Letter of Barnabas": {"sequence": 209, "remove": [], "add": []},
    "Martyrdom of Polycarp": {"sequence": 210, "remove": [], "add": []},
    "Paul on Resurrection": {
        "sequence": 211,
        "remove": [],
        "add": [28740, 9452, 15552, 30167, 47147, 11941],
    },
    "Sexual and Reproductive Ethics": {
        "sequence": 212,
        "remove": [49580, 28262],
        "add": [],
    },
    "Valentinian Gnostics": {
        "sequence": 213,
        "remove": [40170, 20951, 7648],
        "add": [],
    },
    "2 Peter": {"sequence": 214, "remove": [], "add": [50167]},
    "Apostolic Fathers Attribution": {
        "sequence": 215,
        "remove": [8244, 3559],
        "add": [],
    },
    "Book of Isaiah": {"sequence": 216, "remove": [], "add": []},
    "Composite Pauline Letters": {
        "sequence": 217,
        "remove": [],
        "add": [22288, 14685, 49897],
    },
    "Dating Ancient Texts": {"sequence": 218, "remove": [], "add": []},
    "Early Christian Teachings on Wealth": {
        "sequence": 219,
        "remove": [49014, 14249],
        "add": [],
    },
    "Ecclesiastes": {"sequence": 220, "remove": [], "add": []},
    "Infancy Gospel of Thomas": {"sequence": 221, "remove": [15787], "add": []},
    "Miracle Stories in Non-Canonical Texts": {
        "sequence": 222,
        "remove": [15459],
        "add": [],
    },
    "Pauline Salvation Models": {
        "sequence": 223,
        "remove": [47090],
        "add": [38171, 47123],
    },
    "Textual Criticism Overview": {
        "sequence": 224,
        "remove": [48697],
        "add": [33171, 11807, 9049, 11826, 15850],
    },
    "Visionary Experiences": {
        "sequence": 225,
        "remove": [15912],
        "add": [3007],
    },
}


ADD_REASONS = {
    "Historical Study and Theology": "The post directly compares historical and theological approaches to biblical texts.",
    "Paul on Resurrection": "Paul's understanding of Jesus' resurrection or believers' resurrection is a major sustained subject.",
    "2 Peter": "A substantial section directly examines the textual problem in 2 Peter 3:10.",
    "Composite Pauline Letters": "The post substantially examines whether a Pauline letter combines multiple earlier letters.",
    "Pauline Salvation Models": "The post substantially explains Paul's judicial and participationist understandings of salvation.",
    "Textual Criticism Overview": "The post substantially introduces textual criticism, its central problems, or its development as a field.",
    "Visionary Experiences": "The post substantially addresses Paul's vision and visionary resurrection appearances.",
}


REMOVE_REASONS = {
    "Wealth and Poverty in Antiquity": "Wealth or poverty is an example within a broader ethical discussion rather than a major sustained subject.",
    "Free Will Explanations of Suffering": "Free will is brief, set aside, or presented as only one among several explanations of suffering.",
    "Sexual and Reproductive Ethics": "Sexual or reproductive ethics is incidental to the post's principal subject.",
    "Valentinian Gnostics": "Valentinians are illustrative within a broader discussion rather than a major sustained subject.",
    "Apostolic Fathers Attribution": "The post addresses Gospel attribution or document dating rather than attribution of an Apostolic Fathers text.",
    "Early Christian Teachings on Wealth": "Wealth and giving form only one component of a broader survey of the text's teachings.",
    "Infancy Gospel of Thomas": "The post centers on Luke's boyhood narrative rather than the Infancy Gospel of Thomas.",
    "Miracle Stories in Non-Canonical Texts": "The post centers on twin identity and sexual renunciation rather than miracle stories.",
    "Pauline Salvation Models": "The post does not substantially examine Paul's models of salvation.",
    "Textual Criticism Overview": "The post concerns the history of chapter and verse divisions rather than textual criticism.",
    "Visionary Experiences": "The post concerns scholarly interest in the origins of afterlife journeys rather than visionary experiences.",
}


def load_json(path: Path) -> object:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value: object) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def reason_for(topic: str, decision: str) -> str:
    if decision == "retain":
        return f"The full post treats {topic} as a primary or major sustained subject."
    if decision == "add":
        return ADD_REASONS[topic]
    return REMOVE_REASONS[topic]


def main() -> None:
    posts = load_json(POSTS_PATH)
    topic_data = load_json(TOPICS_PATH)
    tracker = load_json(TRACKER_PATH)
    if not isinstance(posts, list) or not isinstance(topic_data, dict) or not isinstance(tracker, dict):
        raise TypeError("Unexpected source JSON shape")

    posts_by_id = {int(post["wpId"]): post for post in posts}
    metadata_by_name = {topic["name"]: topic for topic in topic_data["topics"]}
    tracker_by_name = {entry["topic"]: entry for entry in tracker["topics"]}
    if len(posts_by_id) != len(posts):
        raise ValueError("Duplicate wpId values in post search index")

    initial_members: dict[str, list[int]] = {}
    for topic, plan in PLANS.items():
        if topic not in metadata_by_name or topic not in tracker_by_name:
            raise ValueError(f"Missing metadata or tracker entry for {topic!r}")
        tracker_entry = tracker_by_name[topic]
        if tracker_entry["status"] != "pending":
            raise ValueError(f"Expected pending tracker status for {topic!r}")
        if int(tracker_entry["auditSequence"]) != int(plan["sequence"]):
            raise ValueError(f"Unexpected audit sequence for {topic!r}")

        current = [int(post["wpId"]) for post in posts if topic in post.get("topics", [])]
        initial_members[topic] = current
        remove = list(plan["remove"])
        add = list(plan["add"])
        missing_removals = [wp_id for wp_id in remove if wp_id not in current]
        existing_additions = [wp_id for wp_id in add if wp_id in current]
        unknown_posts = [wp_id for wp_id in remove + add if wp_id not in posts_by_id]
        if missing_removals or existing_additions or unknown_posts:
            raise ValueError(
                f"{topic}: missing removals={missing_removals}, "
                f"existing additions={existing_additions}, unknown posts={unknown_posts}"
            )

    for topic, plan in PLANS.items():
        for wp_id in plan["remove"]:
            post = posts_by_id[wp_id]
            post["topics"] = [value for value in post["topics"] if value != topic]
        for wp_id in plan["add"]:
            posts_by_id[wp_id]["topics"].append(topic)

    for post in posts:
        post["topics"] = list(dict.fromkeys(post.get("topics", [])))

    topic_counts = Counter(topic for post in posts for topic in post.get("topics", []))
    for topic, plan in PLANS.items():
        expected_after = len(initial_members[topic]) - len(plan["remove"]) + len(plan["add"])
        if topic_counts[topic] != expected_after:
            raise ValueError(f"{topic}: expected {expected_after}, found {topic_counts[topic]}")

        removed = set(plan["remove"])
        decisions = []
        for wp_id in initial_members[topic]:
            decision = "remove" if wp_id in removed else "retain"
            decisions.append(
                {
                    "wpId": str(wp_id),
                    "title": posts_by_id[wp_id]["title"],
                    "decision": decision,
                    "confidence": "high",
                    "reason": reason_for(topic, decision),
                }
            )
        for wp_id in plan["add"]:
            decisions.append(
                {
                    "wpId": str(wp_id),
                    "title": posts_by_id[wp_id]["title"],
                    "decision": "add",
                    "confidence": "high",
                    "reason": reason_for(topic, "add"),
                }
            )

        tracker_by_name[topic].update(
            {
                "status": "completed",
                "postCountBefore": len(initial_members[topic]),
                "postCountAfter": topic_counts[topic],
                "descriptionRecommendation": metadata_by_name[topic]["description"],
                "categoryRecommendation": "Retain current category placement.",
                "startedAt": AUDIT_DATE,
                "completedAt": AUDIT_DATE,
                "decisions": decisions,
                "notes": ["Full-text audit completed; approved decisions applied."],
            }
        )

    tracker["updatedAt"] = AUDIT_DATE
    write_json(POSTS_PATH, posts)
    write_json(TOPICS_PATH, topic_data)
    write_json(TRACKER_PATH, tracker)

    print("Applied topic audits 201-225.")
    for topic, plan in PLANS.items():
        print(
            f"{int(plan['sequence']):3d}. {topic}: "
            f"{len(initial_members[topic])} -> {topic_counts[topic]}"
        )


if __name__ == "__main__":
    main()
