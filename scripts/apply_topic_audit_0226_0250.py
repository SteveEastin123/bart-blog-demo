"""Apply the approved full-text topic audits for sequences 226-250."""

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
    "Dating the Gospels": {"sequence": 226, "remove": [], "add": []},
    "Galatians": {"sequence": 227, "remove": [], "add": [47208]},
    "Mary Magdalene in Gnostic Traditions": {
        "sequence": 228,
        "remove": [],
        "add": [40302],
    },
    "Matthew's Use of Scripture": {"sequence": 229, "remove": [], "add": []},
    "Moral Problems in Scripture": {"sequence": 230, "remove": [], "add": []},
    "Pastoral Epistles": {"sequence": 231, "remove": [], "add": [4613]},
    "Pauline Epistles (General)": {"sequence": 232, "remove": [], "add": []},
    "Redaction Criticism": {"sequence": 233, "remove": [], "add": [27674]},
    "Ancient Miracle Workers": {"sequence": 234, "remove": [49051], "add": []},
    "Celsus": {
        "sequence": 235,
        "remove": [36915, 36902, 28478, 27584, 9268],
        "add": [],
    },
    "Codex Sinaiticus": {"sequence": 236, "remove": [], "add": []},
    "Forged": {"sequence": 237, "remove": [], "add": []},
    "Hebrew Bible Manuscripts": {"sequence": 238, "remove": [], "add": []},
    "Miraculous Martyrdom Traditions": {"sequence": 239, "remove": [], "add": []},
    "Paul's Conversion": {"sequence": 240, "remove": [], "add": []},
    "Persecution in the Epistles": {"sequence": 241, "remove": [], "add": []},
    "Quran Manuscript Fragments": {"sequence": 242, "remove": [], "add": []},
    "Romans": {"sequence": 243, "remove": [], "add": []},
    "Son of Man": {"sequence": 244, "remove": [], "add": [21114]},
    "Book of Daniel": {"sequence": 245, "remove": [], "add": []},
    "Colossians": {"sequence": 246, "remove": [], "add": []},
    "Enoch Traditions": {"sequence": 247, "remove": [], "add": []},
    "Jewish Christian Gospels": {"sequence": 248, "remove": [], "add": [9290]},
    "Letter of Jude": {"sequence": 249, "remove": [], "add": []},
    "Papyrus Egerton": {"sequence": 250, "remove": [], "add": []},
}


ADD_REASONS = {
    "Galatians": "A substantial section directly examines Galatians 2 and Paul's relationship with Peter and James.",
    "Mary Magdalene in Gnostic Traditions": "The post centrally evaluates a modern non-canonical claim identifying Aseneth with Mary Magdalene.",
    "Pastoral Epistles": "The post directly compares Paul's views with teachings about women in the Pastoral Epistles.",
    "Redaction Criticism": "The post explicitly uses redaction criticism to examine Luke's editing of Mark.",
    "Son of Man": "The post substantially examines the reinterpretation of Jesus' Son of Man teaching.",
    "Jewish Christian Gospels": "A substantial section directly explains the Jewish-Christian Gospels.",
}


REMOVE_REASONS = {
    "Ancient Miracle Workers": "The post concerns Quadratus and Christian apologetics rather than Quadratus as a miracle worker.",
    "Celsus": "Celsus is only a source, quotation, or preview rather than a primary or major sustained subject.",
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

    print("Applied topic audits 226-250.")
    for topic, plan in PLANS.items():
        print(
            f"{int(plan['sequence']):3d}. {topic}: "
            f"{len(initial_members[topic])} -> {topic_counts[topic]}"
        )


if __name__ == "__main__":
    main()
