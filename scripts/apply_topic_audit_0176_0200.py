"""Apply the approved full-text topic audits for sequences 176-200."""

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
    "Moral Philosophy": {
        "sequence": 176,
        "remove": [],
        "add": [16742, 31840, 30070],
    },
    "Old Testament Apocrypha": {"sequence": 177, "remove": [], "add": []},
    "Purgatory": {"sequence": 178, "remove": [], "add": []},
    "Septuagint": {
        "sequence": 179,
        "remove": [48795, 25638, 9513],
        "add": [],
    },
    "1 Thessalonians": {"sequence": 180, "remove": [], "add": []},
    "Ancient Secretaries and Authorship": {
        "sequence": 181,
        "remove": [],
        "add": [39714],
    },
    "Bible Translations (General)": {"sequence": 182, "remove": [], "add": []},
    "Life of Brian": {"sequence": 183, "remove": [], "add": []},
    "Luke-Acts Authorship": {"sequence": 184, "remove": [], "add": []},
    "Manuscript Dating": {"sequence": 185, "remove": [], "add": []},
    "Media Coverage and Reviews": {
        "sequence": 186,
        "remove": [15204],
        "add": [38815, 14918, 20749, 20831, 20857],
    },
    "1 Corinthians": {"sequence": 187, "remove": [], "add": []},
    "Early Christianity (General)": {
        "sequence": 188,
        "remove": [38520],
        "add": [40572, 40576, 38658],
    },
    "Eusebius of Caesarea": {"sequence": 189, "remove": [], "add": []},
    "Jesus and Women": {"sequence": 190, "remove": [], "add": []},
    "Moses": {"sequence": 191, "remove": [], "add": []},
    "Non-Pauline Epistle Forgeries": {
        "sequence": 192,
        "remove": [],
        "add": [20455],
    },
    "Paul's Churches and Communities": {
        "sequence": 193,
        "remove": [],
        "add": [47137, 4591],
    },
    "Proto-Gospel of James": {
        "sequence": 194,
        "remove": [22604, 12088, 3428],
        "add": [],
    },
    "Signs in the Gospel of John": {
        "sequence": 195,
        "remove": [49914],
        "add": [8816],
    },
    "Angelic Christology": {"sequence": 196, "remove": [], "add": []},
    "Armageddon": {
        "sequence": 197,
        "remove": [],
        "add": [15993, 38860, 36545],
    },
    "Biblical Debates on Same-Sex Relations": {"sequence": 198, "remove": [], "add": []},
    "Biblical Explanations of Suffering": {
        "sequence": 199,
        "remove": [20660],
        "add": [34656, 46972],
    },
    "Didache": {"sequence": 200, "remove": [], "add": []},
}


REPLACEMENT_ASSIGNMENTS = {
    "Early Judaism (General)": [9513],
    "Problem of Evil and Suffering": [20660],
}


ADD_REASONS = {
    "Moral Philosophy": "The post substantially examines an ethical question about lying or the morality of war.",
    "Ancient Secretaries and Authorship": "Ancient dictation and secretarial practice are central to the post's analysis of Paul's original wording.",
    "Media Coverage and Reviews": "The post substantially presents or responds to public reviews and media reception of Bart's books.",
    "Early Christianity (General)": "The post directly examines the origins or historical reconstruction of the early Christian movement.",
    "Non-Pauline Epistle Forgeries": "The post directly evaluates whether the Johannine epistles are forgeries.",
    "Paul's Churches and Communities": "The post substantially examines people and leadership within Paul's churches.",
    "Signs in the Gospel of John": "The post directly analyzes the proposed Signs Source behind the Gospel of John.",
    "Armageddon": "The post directly concerns Bart's Armageddon book project, publication, or related public lecture.",
    "Biblical Explanations of Suffering": "The post directly examines biblical explanations of suffering or announces a program devoted to that subject.",
}


REMOVE_REASONS = {
    "Septuagint": "The Septuagint appears only as brief supporting context rather than a major sustained subject.",
    "Media Coverage and Reviews": "The post centers on a public debate about suffering, not media coverage or a review.",
    "Early Christianity (General)": "The post centers specifically on Peter in history and legend rather than broad early Christianity.",
    "Proto-Gospel of James": "The Proto-Gospel of James receives only a brief supporting reference within a broader Christmas discussion.",
    "Signs in the Gospel of John": "The post concerns John Spong's general interpretation of John without sustained analysis of Johannine signs.",
    "Biblical Explanations of Suffering": "The post centers on faith, suffering, and the problem of evil rather than biblical explanations of suffering.",
}


REPLACEMENT_REASONS = {
    ("Early Judaism (General)", 9513): (
        "The post substantially traces the development of repentance from the Hebrew Bible "
        "into early Jewish interpretation."
    ),
    ("Problem of Evil and Suffering", 20660): (
        "The post directly examines whether suffering undermines belief in an active, loving God."
    ),
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
        if topic not in metadata_by_name or topic not in tracker_by_name:
            raise ValueError(f"Missing replacement topic or tracker entry for {topic!r}")
        for wp_id in wp_ids:
            if wp_id not in posts_by_id:
                raise ValueError(f"Unknown replacement wpId {wp_id}")
            if topic in posts_by_id[wp_id].get("topics", []):
                raise ValueError(f"Replacement topic {topic!r} already exists on wpId {wp_id}")

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

    for topic, wp_ids in REPLACEMENT_ASSIGNMENTS.items():
        tracker_entry = tracker_by_name[topic]
        tracker_entry["postCountAfter"] = topic_counts[topic]
        decisions = tracker_entry.setdefault("decisions", [])
        existing_ids = {str(decision["wpId"]) for decision in decisions}
        for wp_id in wp_ids:
            if str(wp_id) not in existing_ids:
                decisions.append(
                    {
                        "wpId": str(wp_id),
                        "title": posts_by_id[wp_id]["title"],
                        "decision": "add",
                        "confidence": "high",
                        "reason": REPLACEMENT_REASONS[(topic, wp_id)],
                    }
                )

    untagged_changed = [
        int(post["wpId"])
        for post in posts
        if int(post["wpId"])
        in {wp_id for plan in PLANS.values() for wp_id in plan["remove"]}
        and not post.get("topics")
    ]
    if untagged_changed:
        raise ValueError(f"Approved cleanup left changed posts without topics: {untagged_changed}")

    tracker["updatedAt"] = AUDIT_DATE
    write_json(POSTS_PATH, posts)
    write_json(TOPICS_PATH, topic_data)
    write_json(TRACKER_PATH, tracker)

    print("Applied topic audits 176-200.")
    for topic, plan in PLANS.items():
        print(
            f"{int(plan['sequence']):3d}. {topic}: "
            f"{len(initial_members[topic])} -> {topic_counts[topic]}"
        )
    print("Applied two approved replacement-topic assignments.")


if __name__ == "__main__":
    main()
