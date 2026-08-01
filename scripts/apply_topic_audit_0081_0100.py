"""Apply the approved full-text topic audits for audit sequences 81-100."""

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
    "Jesus' Passion Narratives": {"sequence": 81, "remove": [], "add": []},
    "Manuscript Discoveries and Controversies": {"sequence": 82, "remove": [], "add": []},
    "Paul's Knowledge of Jesus": {
        "sequence": 83,
        "remove": [29838, 28981, 28977, 14882, 14876, 10318, 7315, 7311],
        "add": [7346],
    },
    "Theologically Significant Variants": {"sequence": 84, "remove": [8087], "add": []},
    "Philippians 2 Christ Poem": {"sequence": 85, "remove": [41519], "add": []},
    "Synoptic Problem": {"sequence": 86, "remove": [], "add": []},
    "Christian Anti-Judaism": {"sequence": 87, "remove": [], "add": []},
    "Comparative Ancient Evidence": {"sequence": 88, "remove": [12336], "add": []},
    "Gospel Eyewitness Claims": {"sequence": 89, "remove": [20320], "add": []},
    "Jesus' Ethics": {"sequence": 90, "remove": [], "add": []},
    "John the Baptist": {"sequence": 91, "remove": [49897, 46970, 46923], "add": []},
    "Public-Facing Scholarship": {"sequence": 92, "remove": [], "add": []},
    "Textual Criticism Methods": {"sequence": 93, "remove": [48697, 20997, 9055], "add": []},
    "Biblical Inerrancy": {"sequence": 94, "remove": [], "add": []},
    "James the Brother of Jesus": {"sequence": 95, "remove": [47593], "add": []},
    "Resurrection Arguments and Apologetics": {"sequence": 96, "remove": [], "add": []},
    "Salvation (General)": {"sequence": 97, "remove": [], "add": []},
    "Christian Interpretation of Jewish Scripture": {
        "sequence": 98,
        "remove": [48797, 32667, 27246],
        "add": [],
    },
    "Church Fathers as Textual Evidence": {
        "sequence": 99,
        "remove": [47565, 40201, 39748, 33173, 12868, 12709, 8964, 8954],
        "add": [],
    },
    "Divine Beings in the Hebrew Bible": {"sequence": 100, "remove": [47673, 17688], "add": []},
}


REPLACEMENT_ADDS: dict[str, list[int]] = {
    "Jesus' Teachings": [28981],
    "Textual Criticism Overview": [48697, 9055],
    "Critical Biblical Scholarship": [20997, 32667],
}


REPLACEMENT_REASONS: dict[tuple[str, int], str] = {
    ("Jesus' Teachings", 28981): "The post directly compares the messages and teachings of Jesus and Paul.",
    ("Textual Criticism Overview", 48697): "The post surveys the historical addition of chapter and verse divisions to biblical texts.",
    ("Textual Criticism Overview", 9055): "The post describes the former state of New Testament textual criticism as a discipline.",
    ("Critical Biblical Scholarship", 20997): "The post examines the relationship between religious commitments and academic textual criticism.",
    ("Critical Biblical Scholarship", 32667): "The post contrasts critical interpretation with claims that the Holy Spirit is required to interpret the Bible.",
}


SPECIFIC_REASONS: dict[tuple[str, int, str], str] = {
    ("Paul's Knowledge of Jesus", 7346, "add"): "The post directly examines teachings of Jesus known to Paul.",
    ("Theologically Significant Variants", 8087, "remove"): "The short post merely links to a media interview and does not sustain discussion of a theologically significant variant.",
    ("Philippians 2 Christ Poem", 41519, "remove"): "Philippians 2 appears only as a brief supporting reference in a multi-question post.",
    ("Comparative Ancient Evidence", 12336, "remove"): "The post concerns John Mill and New Testament textual variants without comparing them with other ancient evidence.",
    ("Gospel Eyewitness Claims", 20320, "remove"): "Eyewitness claims receive only a passing reference in a post centered on theories of Gospel communities.",
    ("John the Baptist", 49897, "remove"): "John the Baptist appears only in a brief terminology question at the end of a broad reader-question post.",
    ("John the Baptist", 46970, "remove"): "John is supporting context in one question about Jesus and the Essenes rather than a major subject of the post.",
    ("John the Baptist", 46923, "remove"): "John is supporting context in one question about Jesus and the Essenes rather than a major subject of the post.",
    ("Textual Criticism Methods", 48697, "remove"): "The post explains the history of chapter and verse divisions rather than a textual-critical method.",
    ("Textual Criticism Methods", 20997, "remove"): "The post concerns the religious demographics of textual critics rather than textual-critical methods.",
    ("Textual Criticism Methods", 9055, "remove"): "The post recounts the former state of the discipline rather than explaining a textual-critical method.",
    ("James the Brother of Jesus", 47593, "remove"): "James appears only incidentally as a family identifier in an argument about Jude's authorship.",
    ("Christian Interpretation of Jewish Scripture", 48797, "remove"): "The post centers on formation of a distinct New Testament canon; Jewish scripture supplies only introductory background.",
    ("Christian Interpretation of Jewish Scripture", 32667, "remove"): "The post concerns modern claims about spiritual guidance in biblical interpretation, not Christian interpretation of Jewish scripture.",
    ("Christian Interpretation of Jewish Scripture", 27246, "remove"): "The post critiques modern ways of reading the Bible generally rather than Christian interpretation of Jewish scripture.",
    ("Divine Beings in the Hebrew Bible", 47673, "remove"): "The post examines angels in Jude and Pauline Christianity rather than divine beings in the Hebrew Bible.",
    ("Divine Beings in the Hebrew Bible", 17688, "remove"): "The post centers on Paul's Christology and an Adamic interpretation of Philippians 2, not divine beings in the Hebrew Bible.",
}


PAUL_MESSAGE_COMPARISONS = {29838, 28981, 28977, 14882, 14876, 10318, 7315, 7311}
PATRISTIC_INCIDENTAL = {47565, 40201, 39748, 33173, 12868, 12709, 8964, 8954}


def load_json(path: Path) -> object:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value: object) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def reason_for(topic: str, wp_id: int, decision: str) -> str:
    specific = SPECIFIC_REASONS.get((topic, wp_id, decision))
    if specific:
        return specific
    if topic == "Paul's Knowledge of Jesus" and wp_id in PAUL_MESSAGE_COMPARISONS and decision == "remove":
        return "The post compares the messages of Paul and Jesus rather than examining what Paul knew about the historical Jesus."
    if topic == "Church Fathers as Textual Evidence" and wp_id in PATRISTIC_INCIDENTAL and decision == "remove":
        return "Patristic evidence is absent or only briefly mentioned; it is not a major sustained subject of the post."
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
                    "reason": REPLACEMENT_REASONS[(topic, wp_id)],
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

    if "--sync-replacements" in sys.argv:
        topic_counts = Counter(topic for post in posts for topic in post.get("topics", []))
        sync_replacement_tracker(posts, tracker_by_name, topic_counts)
        tracker["updatedAt"] = AUDIT_DATE
        write_json(TRACKER_PATH, tracker)
        print("Synchronized replacement-topic tracker counts and decisions.")
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
        if topic not in metadata_by_name:
            raise ValueError(f"Missing replacement-topic metadata for {topic!r}")
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

    topic_counts = Counter(topic for post in posts for topic in post.get("topics", []))
    for topic, plan in PLANS.items():
        initial = initial_members[topic]
        remove = set(plan["remove"])
        add = list(plan["add"])
        expected_after = len(initial) - len(remove) + len(add)
        if topic_counts[topic] != expected_after:
            raise ValueError(f"{topic}: expected {expected_after} posts after update, found {topic_counts[topic]}")

        entry = tracker_by_name[topic]
        decisions = []
        for wp_id in initial:
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
        for wp_id in add:
            decisions.append(
                {
                    "wpId": str(wp_id),
                    "title": posts_by_id[wp_id]["title"],
                    "decision": "add",
                    "confidence": "high",
                    "reason": reason_for(topic, wp_id, "add"),
                }
            )

        entry.update(
            {
                "status": "completed",
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
    for post in posts:
        post["topics"] = list(dict.fromkeys(post.get("topics", [])))

    write_json(POSTS_PATH, posts)
    write_json(TOPICS_PATH, topic_data)
    write_json(TRACKER_PATH, tracker)

    print("Applied topic audits 81-100.")
    for topic, plan in PLANS.items():
        print(
            f"{int(plan['sequence']):3d}. {topic}: "
            f"{len(initial_members[topic])} -> {topic_counts[topic]}"
        )


if __name__ == "__main__":
    main()
