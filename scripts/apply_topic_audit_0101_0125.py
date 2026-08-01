"""Apply the approved full-text topic audits for audit sequences 101-125."""

from __future__ import annotations

import json
import sys
from collections import Counter
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
TOPICS_PATH = ROOT / "data" / "index" / "ehrman_post_topics.json"
TRACKER_PATH = ROOT / "data" / "audits" / "ehrman_topic_audit_tracker.json"
AUDIT_DATE = "2026-08-01"


PLANS: dict[str, dict[str, object]] = {
    "Early Christian Diversity": {"sequence": 101, "remove": [], "add": []},
    "Misquoting Jesus": {"sequence": 102, "remove": [], "add": []},
    "Pentateuch": {"sequence": 103, "remove": [], "add": [25647, 29107, 12560]},
    "Textbooks and Teaching Materials": {"sequence": 104, "remove": [], "add": []},
    "Charity and Altruism": {"sequence": 105, "remove": [], "add": []},
    "Christology (General)": {"sequence": 106, "remove": [], "add": []},
    "Life After Death (General)": {"sequence": 107, "remove": [], "add": []},
    "Mary Magdalene": {"sequence": 108, "remove": [], "add": []},
    "New Testament Manuscripts": {"sequence": 109, "remove": [], "add": [34121, 12865]},
    "Peter the Apostle": {"sequence": 110, "remove": [], "add": []},
    "Roman Crucifixion and Burial": {"sequence": 111, "remove": [], "add": []},
    "Apocryphal Acts": {"sequence": 112, "remove": [], "add": []},
    "Jesus' Family Traditions": {
        "sequence": 113,
        "remove": [],
        "add": [37435, 37167, 37156, 21300, 15457, 13510, 5067],
    },
    "Scholarly Research and Publishing": {"sequence": 114, "remove": [12395], "add": []},
    "Archaeology and Material Evidence": {"sequence": 115, "remove": [], "add": []},
    "Empty Tomb Traditions": {"sequence": 116, "remove": [16873], "add": []},
    "Gospel of Peter": {"sequence": 117, "remove": [], "add": []},
    "Historical Jesus (General)": {"sequence": 118, "remove": [], "add": [47735]},
    "Jesus on Wealth and Poverty": {"sequence": 119, "remove": [], "add": []},
    "Methods for Studying the Historical Jesus": {"sequence": 120, "remove": [], "add": []},
    "2 Thessalonians": {"sequence": 121, "remove": [], "add": [47530]},
    "Constantine": {"sequence": 122, "remove": [15125, 15119, 2301], "add": []},
    "Genesis": {"sequence": 123, "remove": [], "add": [12560]},
    "Hebrew Bible Composition and Sources": {"sequence": 124, "remove": [], "add": []},
    "Pauline Authorship": {"sequence": 125, "remove": [47246], "add": []},
}


REPLACEMENT_ADDS: dict[str, list[int]] = {
    "Courses and Teaching": [36803],
    "Early Christian Writings": [36803],
    "Writing and Publishing Process": [12395],
}


REASONS: dict[tuple[str, int, str], str] = {
    ("Pentateuch", 25647, "add"): "The post directly asks who wrote the Pentateuch and evaluates Mosaic authorship.",
    ("Pentateuch", 29107, "add"): "A substantial lecture summary directly examines Pentateuchal authorship and source theories.",
    ("Pentateuch", 12560, "add"): "The post directly examines Mosaic authorship, source tensions, and contradictions in the Pentateuch.",
    ("New Testament Manuscripts", 34121, "add"): "The post directly uses New Testament manuscripts to examine later theological controversies.",
    ("New Testament Manuscripts", 12865, "add"): "The post directly examines whether every surviving New Testament manuscript can preserve a secondary reading.",
    ("Jesus' Family Traditions", 37435, "add"): "The post directly examines the tradition that Jesus had a twin brother.",
    ("Jesus' Family Traditions", 37167, "add"): "The post directly evaluates claims about Jesus' brothers and the tradition that Thomas was his twin.",
    ("Jesus' Family Traditions", 37156, "add"): "The post directly examines traditions about Jesus' brothers in the Proto-Gospel of James.",
    ("Jesus' Family Traditions", 21300, "add"): "The post directly examines the tradition that Jesus had a twin brother.",
    ("Jesus' Family Traditions", 15457, "add"): "The post directly examines the tradition that Jesus had a twin brother.",
    ("Jesus' Family Traditions", 13510, "add"): "The post directly examines traditions about Jesus' mother and brothers.",
    ("Jesus' Family Traditions", 5067, "add"): "The post directly evaluates mythicist claims about Jesus' brother and twin traditions.",
    ("Scholarly Research and Publishing", 12395, "remove"): "The post concerns pitching and planning a general-audience book rather than academic research or scholarly publishing.",
    ("Empty Tomb Traditions", 16873, "remove"): "The post centers on doubt and resurrection appearances; the empty tomb is only one supporting example.",
    ("Historical Jesus (General)", 47735, "add"): "The entire post surveys four major questions in historical Jesus research.",
    ("2 Thessalonians", 47530, "add"): "The post directly summarizes 2 Thessalonians alongside Ephesians and Colossians.",
    ("Constantine", 15125, "remove"): "The post centers on Theodosius and the establishment of Christianity as Rome's official religion decades after Constantine.",
    ("Constantine", 15119, "remove"): "The post centers on Constantine's successors and their anti-pagan policies after his death.",
    ("Constantine", 2301, "remove"): "Constantine provides a chronological boundary, but the post centers on Christian growth before his conversion.",
    ("Genesis", 12560, "add"): "The post's sustained examples and source analysis center on the two creation accounts in Genesis.",
    ("Pauline Authorship", 47246, "remove"): "The post briefly states the authorship consensus but postpones authorship analysis while summarizing 1 Timothy's contents.",
    ("Courses and Teaching", 36803, "add"): "The post presents a graduate seminar syllabus, assignments, and weekly readings.",
    ("Early Christian Writings", 36803, "add"): "The seminar broadly surveys early Christian apocryphal gospels, acts, letters, and apocalypses.",
    ("Writing and Publishing Process", 12395, "add"): "The post discusses pitching a trade book, preparing its prospectus, and planning its research.",
}


def load_json(path: Path) -> object:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value: object) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def reason_for(topic: str, wp_id: int, decision: str) -> str:
    specific = REASONS.get((topic, wp_id, decision))
    if specific:
        return specific
    if decision == "retain":
        return f"The full post treats {topic} as a primary or major sustained subject."
    raise ValueError(f"Missing reason for {topic!r}, wpId {wp_id}, decision {decision!r}")


def sync_replacement_tracker(
    posts: list[dict[str, object]],
    tracker_by_name: dict[str, dict[str, object]],
    topic_counts: Counter[str],
) -> None:
    posts_by_id = {int(post["wpId"]): post for post in posts}
    for topic, wp_ids in REPLACEMENT_ADDS.items():
        entry = tracker_by_name[topic]
        if entry["status"] == "pending":
            entry["postCountBefore"] = topic_counts[topic]
            continue
        if entry["status"] != "completed":
            continue
        entry["postCountAfter"] = topic_counts[topic]
        decisions = entry.setdefault("decisions", [])
        existing = {str(decision["wpId"]) for decision in decisions}
        for wp_id in wp_ids:
            if str(wp_id) in existing:
                continue
            decisions.append(
                {
                    "wpId": str(wp_id),
                    "title": posts_by_id[wp_id]["title"],
                    "decision": "add",
                    "confidence": "high",
                    "reason": REASONS[(topic, wp_id, "add")],
                }
            )


def main() -> None:
    posts = load_json(POSTS_PATH)
    topic_data = load_json(TOPICS_PATH)
    tracker = load_json(TRACKER_PATH)
    if not isinstance(posts, list) or not isinstance(topic_data, dict) or not isinstance(tracker, dict):
        raise TypeError("Unexpected source JSON shape")

    posts_by_id = {int(post["wpId"]): post for post in posts}
    if len(posts_by_id) != len(posts):
        raise ValueError("Duplicate wpId values in post search index")
    metadata_by_name = {topic["name"]: topic for topic in topic_data["topics"]}
    tracker_by_name = {topic["topic"]: topic for topic in tracker["topics"]}

    if "--sync-before-counts" in sys.argv:
        for topic, plan in PLANS.items():
            entry = tracker_by_name[topic]
            entry["postCountBefore"] = (
                int(entry["postCountAfter"]) + len(plan["remove"]) - len(plan["add"])
            )
        write_json(TRACKER_PATH, tracker)
        print("Synchronized audit 101-125 pre-audit counts.")
        return

    initial_members: dict[str, list[int]] = {}
    for topic, plan in PLANS.items():
        if topic not in metadata_by_name or topic not in tracker_by_name:
            raise ValueError(f"Missing metadata or tracker entry for {topic!r}")
        if tracker_by_name[topic]["status"] != "pending":
            raise ValueError(f"Expected pending tracker status for {topic!r}")
        current = [int(post["wpId"]) for post in posts if topic in post.get("topics", [])]
        initial_members[topic] = current
        remove = list(plan["remove"])
        add = list(plan["add"])
        missing_removals = [wp_id for wp_id in remove if wp_id not in current]
        existing_additions = [wp_id for wp_id in add if wp_id in current]
        unknown_posts = [wp_id for wp_id in remove + add if wp_id not in posts_by_id]
        if missing_removals or existing_additions or unknown_posts:
            raise ValueError(
                f"{topic}: missing removals={missing_removals}, existing additions={existing_additions}, "
                f"unknown posts={unknown_posts}"
            )

    for topic, wp_ids in REPLACEMENT_ADDS.items():
        if topic not in metadata_by_name or topic not in tracker_by_name:
            raise ValueError(f"Missing replacement topic {topic!r}")
        for wp_id in wp_ids:
            if wp_id not in posts_by_id:
                raise ValueError(f"Replacement topic {topic!r}: unknown wpId {wp_id}")
            if topic in posts_by_id[wp_id].get("topics", []):
                raise ValueError(f"Replacement topic {topic!r} already exists on wpId {wp_id}")

    for topic, wp_ids in REPLACEMENT_ADDS.items():
        for wp_id in wp_ids:
            posts_by_id[wp_id]["topics"].append(topic)

    for topic, plan in PLANS.items():
        for wp_id in plan["remove"]:
            post = posts_by_id[wp_id]
            post["topics"] = [value for value in post["topics"] if value != topic]
            if not post["topics"]:
                raise ValueError(f"Removing {topic!r} would leave wpId {wp_id} without a topic")
        for wp_id in plan["add"]:
            posts_by_id[wp_id]["topics"].append(topic)

    for post in posts:
        post["topics"] = list(dict.fromkeys(post.get("topics", [])))

    topic_counts = Counter(topic for post in posts for topic in post.get("topics", []))
    for topic, plan in PLANS.items():
        expected_after = len(initial_members[topic]) - len(plan["remove"]) + len(plan["add"])
        if topic_counts[topic] != expected_after:
            raise ValueError(f"{topic}: expected {expected_after}, found {topic_counts[topic]}")

        remove = set(plan["remove"])
        decisions = []
        for wp_id in initial_members[topic]:
            decision = "remove" if wp_id in remove else "retain"
            decisions.append(
                {
                    "wpId": str(wp_id),
                    "title": posts_by_id[wp_id]["title"],
                    "decision": decision,
                    "confidence": "high",
                    "reason": reason_for(topic, wp_id, decision),
                }
            )
        for wp_id in plan["add"]:
            decisions.append(
                {
                    "wpId": str(wp_id),
                    "title": posts_by_id[wp_id]["title"],
                    "decision": "add",
                    "confidence": "high",
                    "reason": reason_for(topic, wp_id, "add"),
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

    sync_replacement_tracker(posts, tracker_by_name, topic_counts)
    tracker["updatedAt"] = AUDIT_DATE

    write_json(POSTS_PATH, posts)
    write_json(TRACKER_PATH, tracker)

    print("Applied topic audits 101-125.")
    for topic, plan in PLANS.items():
        print(
            f"{int(plan['sequence']):3d}. {topic}: "
            f"{len(initial_members[topic])} -> {topic_counts[topic]}"
        )


if __name__ == "__main__":
    main()
