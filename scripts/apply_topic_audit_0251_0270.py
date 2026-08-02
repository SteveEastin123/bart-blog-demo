"""Apply the approved full-text topic audits for sequences 251-270."""

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
    "Paul as Persecutor and Persecuted": {"sequence": 251, "remove": [], "add": []},
    "Scholar Tributes and Memorials": {"sequence": 252, "remove": [], "add": []},
    "Arius and Arianism": {"sequence": 253, "remove": [], "add": [35928, 35350]},
    "Ephesians": {"sequence": 254, "remove": [], "add": []},
    "Hebrews": {"sequence": 255, "remove": [], "add": []},
    "Martyrdom Traditions (General)": {"sequence": 256, "remove": [], "add": []},
    "Miracle Traditions (General)": {"sequence": 257, "remove": [], "add": []},
    "Philemon": {"sequence": 258, "remove": [], "add": [41251]},
    "Roman Persecution of Christians": {"sequence": 259, "remove": [49926], "add": []},
    "Suffering and Loss of Faith": {
        "sequence": 260,
        "remove": [],
        "add": [22355, 4403, 20393],
    },
    "Demythologizing the New Testament": {"sequence": 261, "remove": [], "add": [4403]},
    "Jesus and Abgar Legend": {"sequence": 262, "remove": [], "add": []},
    "Martyrdom of Perpetua": {"sequence": 263, "remove": [34882], "add": []},
    "Paul on Jesus' Crucifixion": {"sequence": 264, "remove": [], "add": []},
    "Pauline End-Time Expectations": {"sequence": 265, "remove": [], "add": []},
    "Revelation Authorship": {"sequence": 266, "remove": [], "add": []},
    "Sethian Gnostics": {"sequence": 267, "remove": [], "add": []},
    "Paul and Slavery": {"sequence": 268, "remove": [], "add": [41251, 47001]},
    "Christian Health Care": {"sequence": 269, "remove": [], "add": []},
    "Holy Spirit in Luke-Acts": {"sequence": 270, "remove": [], "add": []},
}


ADD_REASONS = {
    "Arius and Arianism": "The post substantially discusses Arius, Alexander, Nicaea, and the resulting controversy.",
    "Philemon": "A major section examines Philemon, Onesimus, and slavery.",
    "Suffering and Loss of Faith": "The post directly connects suffering with questioning, leaving, or losing Christian faith.",
    "Demythologizing the New Testament": "Demythologized Christian belief and its eventual limits are sustained subjects.",
    "Paul and Slavery": "The post devotes substantial attention to Paul, Philemon, Onesimus, and slavery.",
}


REMOVE_REASONS = {
    "Roman Persecution of Christians": "Roman persecution appears only in the final reader question; historical evaluation of miracles dominates the post.",
    "Martyrdom of Perpetua": "The post concerns purgatory generally and only announces that Perpetua will be examined subsequently.",
}


CONVERSION_ADDITIONS = [28478, 27584]


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

    conversion_before = [int(post["wpId"]) for post in posts if "Conversion" in post.get("topics", [])]
    conversion_tracker = tracker_by_name["Conversion"]
    if conversion_tracker["status"] != "completed":
        raise ValueError("Expected Conversion audit to be completed")
    if any(wp_id in conversion_before for wp_id in CONVERSION_ADDITIONS):
        raise ValueError("A Conversion repair post already has the topic")

    for topic, plan in PLANS.items():
        for wp_id in plan["remove"]:
            post = posts_by_id[wp_id]
            post["topics"] = [value for value in post["topics"] if value != topic]
        for wp_id in plan["add"]:
            posts_by_id[wp_id]["topics"].append(topic)

    for wp_id in CONVERSION_ADDITIONS:
        posts_by_id[wp_id]["topics"].append("Conversion")

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

    if topic_counts["Conversion"] != len(conversion_before) + len(CONVERSION_ADDITIONS):
        raise ValueError("Unexpected Conversion count after repair")
    conversion_tracker["postCountAfter"] = topic_counts["Conversion"]
    conversion_tracker.setdefault("decisions", []).extend(
        {
            "wpId": str(wp_id),
            "title": posts_by_id[wp_id]["title"],
            "decision": "add",
            "confidence": "high",
            "reason": "The post centrally examines why Jews did not convert and the resulting problems for Christian mission.",
        }
        for wp_id in CONVERSION_ADDITIONS
    )
    conversion_tracker.setdefault("notes", []).append(
        "Two substantive guest-post versions were added after the Celsus cleanup left them without topics."
    )

    tracker["updatedAt"] = AUDIT_DATE
    write_json(POSTS_PATH, posts)
    write_json(TOPICS_PATH, topic_data)
    write_json(TRACKER_PATH, tracker)

    print("Applied topic audits 251-270.")
    for topic, plan in PLANS.items():
        print(
            f"{int(plan['sequence']):3d}. {topic}: "
            f"{len(initial_members[topic])} -> {topic_counts[topic]}"
        )
    print(f"Conversion repair: {len(conversion_before)} -> {topic_counts['Conversion']}")


if __name__ == "__main__":
    main()
