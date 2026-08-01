"""Apply the approved full-text topic audits for sequences 151-175."""

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
    "Jewish Apocalypticism": {
        "sequence": 151,
        "remove": [],
        "add": [12854, 12826, 12795, 12744, 26631],
    },
    "Johannine Epistles": {"sequence": 152, "remove": [], "add": []},
    "Letter of James": {"sequence": 153, "remove": [], "add": []},
    "Nazareth": {"sequence": 154, "remove": [], "add": []},
    "Non-Pauline Epistle Authorship": {
        "sequence": 155,
        "remove": [],
        "add": [46984, 46968, 46974, 20455, 16364, 15654, 32404, 32397],
    },
    "Social Class in Antiquity": {
        "sequence": 156,
        "remove": [],
        "add": [32739, 30688],
    },
    "Zealot Hypothesis": {"sequence": 157, "remove": [], "add": [6555]},
    "Divine Judgment": {"sequence": 158, "remove": [], "add": []},
    "Genealogies of Jesus": {"sequence": 159, "remove": [], "add": []},
    "Paul's Life and Career": {
        "sequence": 160,
        "remove": [],
        "add": [47178, 15251],
    },
    "Ancient Jewish Afterlife Beliefs": {
        "sequence": 161,
        "remove": [],
        "add": [2517, 1981],
    },
    "Augustine of Hippo": {
        "sequence": 162,
        "remove": [50126, 49854, 41262, 28966, 24409, 16117, 16114, 16111, 15818, 8147],
        "add": [],
    },
    "Crucified Messiah": {
        "sequence": 163,
        "remove": [34105],
        "add": [21859, 15065, 41314, 12050, 9286],
    },
    "Gnosticism (General)": {"sequence": 164, "remove": [], "add": []},
    "Gospel of Judas": {"sequence": 165, "remove": [], "add": []},
    "Jesus' Resurrection Appearances": {"sequence": 166, "remove": [], "add": []},
    "Meaning of Jesus' Resurrection": {"sequence": 167, "remove": [], "add": []},
    "Messianic Secret": {"sequence": 168, "remove": [], "add": []},
    "Petrine Authorship and Forgeries": {
        "sequence": 169,
        "remove": [],
        "add": [46984, 46968, 15654],
    },
    "Q Source": {
        "sequence": 170,
        "remove": [],
        "add": [47167, 47070, 47066, 13938, 13936, 13934, 13932, 8584, 8576],
    },
    "Biblical Numerology": {"sequence": 171, "remove": [], "add": [21778, 8503]},
    "Exaltation Christology": {
        "sequence": 172,
        "remove": [47215, 16136],
        "add": [23935, 23386, 10276, 3821],
    },
    "Forgery and Counterforgery": {"sequence": 173, "remove": [2361], "add": []},
    "Hebrew Bible Historical Reliability": {
        "sequence": 174,
        "remove": [28520],
        "add": [12494],
    },
    "Miracle Claims and Apologetics": {
        "sequence": 175,
        "remove": [33317, 32259, 16915],
        "add": [49926, 40554, 17376],
    },
}


DESCRIPTION_UPDATES = {
    "Augustine of Hippo": (
        "Covers substantive discussions of Augustine's views on miracles, lying, sexuality, "
        "war, and eternal punishment."
    ),
    "Miracle Claims and Apologetics": (
        "Covers arguments for and against miracle claims, including eyewitness testimony, "
        "signs from God, historical presuppositions, resurrection arguments, and apologetic "
        "defenses."
    ),
}


REPLACEMENT_ASSIGNMENTS = {
    "Moral Philosophy": [24409],
    "Christology (General)": [16136],
    "Writing and Publishing Process": [2361],
    "Textbooks and Teaching Materials": [2361],
}


ADD_REASONS = {
    "Jewish Apocalypticism": "The post substantially examines Jewish apocalyptic expectations, texts, or historical origins.",
    "Non-Pauline Epistle Authorship": "The post directly investigates the authorship of a non-Pauline New Testament epistle.",
    "Social Class in Antiquity": "The post substantially discusses class distinctions or upper-class privilege in the ancient world.",
    "Zealot Hypothesis": "The post directly evaluates Jesus' action in the Temple as evidence used in the Zealot hypothesis.",
    "Paul's Life and Career": "The post directly reconstructs Paul's chronology, life, or teachings from his letters and Acts.",
    "Ancient Jewish Afterlife Beliefs": "The post substantially explains Jewish beliefs about resurrection, death, or the afterlife.",
    "Crucified Messiah": "The post directly examines how Jesus' messiahship was understood in relation to suffering, death, or resurrection.",
    "Petrine Authorship and Forgeries": "The post directly evaluates Petrine authorship or pseudonymous attribution.",
    "Q Source": "The post substantially addresses the Synoptic literary relationship for which Q is a central proposed source.",
    "Biblical Numerology": "The post substantially examines the literary or interpretive use of numbers in biblical texts.",
    "Exaltation Christology": "The post substantially examines a view in which Jesus was exalted or adopted to divine status.",
    "Hebrew Bible Historical Reliability": "The post directly evaluates the historical existence of David and the evidence for that judgment.",
    "Miracle Claims and Apologetics": "The post directly evaluates arguments, assumptions, or apologetic claims concerning miracles.",
}


REMOVE_REASONS = {
    "Augustine of Hippo": "Augustine is incidental or supplies limited historical context rather than a major sustained subject.",
    "Crucified Messiah": "The post centers on the Muslim denial of Jesus' crucifixion rather than the Christian paradox of a crucified Messiah.",
    "Exaltation Christology": "The post does not sustain an examination of exaltation Christology as a major subject.",
    "Forgery and Counterforgery": "The post announces a prospective book and discusses writing plans rather than the contents of Forgery and Counterforgery.",
    "Hebrew Bible Historical Reliability": "The post concerns prophecy and predictions rather than the historical reliability of Hebrew Bible narratives.",
    "Miracle Claims and Apologetics": "Miracle apologetics is incidental or absent rather than a major sustained subject of the post.",
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

    for topic, wp_ids in REPLACEMENT_ASSIGNMENTS.items():
        if topic not in metadata_by_name:
            raise ValueError(f"Missing replacement topic metadata for {topic!r}")
        for wp_id in wp_ids:
            if wp_id not in posts_by_id:
                raise ValueError(f"Unknown replacement wpId {wp_id}")
            if topic in posts_by_id[wp_id].get("topics", []):
                raise ValueError(f"Replacement topic {topic!r} already exists on wpId {wp_id}")

    for topic, description in DESCRIPTION_UPDATES.items():
        metadata_by_name[topic]["description"] = description

    for topic, wp_ids in REPLACEMENT_ASSIGNMENTS.items():
        for wp_id in wp_ids:
            posts_by_id[wp_id]["topics"].append(topic)

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

    remaining_untagged = [int(post["wpId"]) for post in posts if not post.get("topics")]
    if 28966 not in remaining_untagged:
        raise ValueError("Expected the Common Era post to remain without a suitable existing topic")

    tracker["updatedAt"] = AUDIT_DATE
    write_json(POSTS_PATH, posts)
    write_json(TOPICS_PATH, topic_data)
    write_json(TRACKER_PATH, tracker)

    print("Applied topic audits 151-175.")
    for topic, plan in PLANS.items():
        print(
            f"{int(plan['sequence']):3d}. {topic}: "
            f"{len(initial_members[topic])} -> {topic_counts[topic]}"
        )
    print("Applied four approved replacement-topic assignments to three posts.")


if __name__ == "__main__":
    main()
