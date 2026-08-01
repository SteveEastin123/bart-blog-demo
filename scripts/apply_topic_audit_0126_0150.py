"""Apply the approved full-text topic audits for audit sequences 126-150."""

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
    "Philippians": {"sequence": 126, "remove": [], "add": []},
    "Women in Pauline Traditions": {"sequence": 127, "remove": [], "add": []},
    "Afterlife Journeys": {"sequence": 128, "remove": [], "add": []},
    "Bloody Sweat Textual Variant": {"sequence": 129, "remove": [], "add": []},
    "Book of Job": {"sequence": 130, "remove": [], "add": []},
    "Early Christian Orthodoxy and Heresy": {"sequence": 131, "remove": [], "add": []},
    "Hebrew Bible Prophets": {"sequence": 132, "remove": [], "add": []},
    "Modern Forgery Claims": {"sequence": 133, "remove": [], "add": []},
    "Paul and His Opponents": {"sequence": 134, "remove": [], "add": []},
    "Paul in Acts": {"sequence": 135, "remove": [], "add": []},
    "1 Peter": {
        "sequence": 136,
        "remove": [],
        "add": [46991, 32665, 11738, 11729, 2568, 32660, 11723, 15660],
    },
    "Did Jesus Exist?": {"sequence": 137, "remove": [], "add": []},
    "Free Will and Predestination": {
        "sequence": 138,
        "remove": [28489, 22472, 22355, 12756],
        "add": [],
    },
    "Gospel of Thomas": {"sequence": 139, "remove": [21300, 15417], "add": []},
    "King James Version": {"sequence": 140, "remove": [], "add": []},
    "Pauline Textual Issues": {"sequence": 141, "remove": [], "add": []},
    "Source Criticism": {
        "sequence": 142,
        "remove": [],
        "add": [7076, 17830, 17839, 8814, 8816, 47084, 8556],
    },
    "Adoptionist Christology": {
        "sequence": 143,
        "remove": [],
        "add": [4667, 4660, 3853, 16028],
    },
    "Atonement in Luke-Acts": {"sequence": 144, "remove": [], "add": []},
    "Historical Study of Miracles": {
        "sequence": 145,
        "remove": [26387],
        "add": [49842, 49838, 49818, 37661, 37649, 15386],
    },
    "Paul on Jewish Law": {"sequence": 146, "remove": [], "add": []},
    "Trial of Jesus": {
        "sequence": 147,
        "remove": [],
        "add": [21862, 36539, 35760, 35658, 15929, 20741, 14928],
    },
    "Athanasius of Alexandria": {
        "sequence": 148,
        "remove": [
            48800,
            40657,
            37235,
            32505,
            27493,
            25282,
            17169,
            16787,
            15643,
            12255,
            12253,
            10049,
            8887,
            2108,
        ],
        "add": [],
    },
    "Atheism": {"sequence": 149, "remove": [], "add": [3927]},
    "Disasters and Human Suffering": {"sequence": 150, "remove": [], "add": []},
}


DESCRIPTION_UPDATES = {
    "Athanasius of Alexandria": (
        "Covers Athanasius's 367 CE canon list and its comparison with Didymus the Blind's "
        "wider canon in fourth-century Alexandria."
    )
}


REASONS: dict[tuple[str, int, str], str] = {
    ("1 Peter", 46991, "add"): "The post directly evaluates whether secretaries could account for the authorship of 1 Peter.",
    ("1 Peter", 32665, "add"): "The post directly applies ancient secretarial practice to the authorship problem of 1 Peter.",
    ("1 Peter", 11738, "add"): "The post evaluates whether ancient secretaries composed works such as 1 Peter for their named authors.",
    ("1 Peter", 11729, "add"): "The post examines ancient secretarial practice as evidence bearing directly on 1 Peter's authorship.",
    ("1 Peter", 2568, "add"): "The post uses ancient secretarial practice to evaluate whether Peter could be responsible for 1 Peter.",
    ("1 Peter", 32660, "add"): "The post uses ancient literacy evidence to evaluate whether Peter could have written 1 Peter.",
    ("1 Peter", 11723, "add"): "The post examines literacy in antiquity as a central part of the authorship question for 1 Peter.",
    ("1 Peter", 15660, "add"): "The post directly evaluates whether Peter's education could support his authorship of 1 Peter.",
    ("Free Will and Predestination", 28489, "remove"): "Free will appears only as a brief aside in a post centered on simplistic explanations of suffering.",
    ("Free Will and Predestination", 22472, "remove"): "Free will receives one brief paragraph in a broader discussion of suffering and belief.",
    ("Free Will and Predestination", 22355, "remove"): "Free will is only one item in a list of proposed explanations for suffering.",
    ("Free Will and Predestination", 12756, "remove"): "Free will is mentioned briefly as a contrast to the post's central apocalyptic explanation of suffering.",
    ("Gospel of Thomas", 21300, "remove"): "The post centers on the Acts of Thomas and the twin tradition rather than the Gospel of Thomas.",
    ("Gospel of Thomas", 15417, "remove"): "The post surveys the Nag Hammadi library, with the Gospel of Thomas serving only as one example and a transition.",
    ("Source Criticism", 7076, "add"): "The post directly examines proposed written sources behind the Gospel of John.",
    ("Source Criticism", 17830, "add"): "The post presents evidence that the Gospel of John incorporated earlier sources.",
    ("Source Criticism", 17839, "add"): "The post identifies and evaluates proposed sources used by the Gospel of John.",
    ("Source Criticism", 8814, "add"): "The post directly presents evidence for a signs source used in the Gospel of John.",
    ("Source Criticism", 8816, "add"): "The post reconstructs the character of John's proposed signs source.",
    ("Source Criticism", 47084, "add"): "The post directly explains the proposed M and L sources behind Matthew and Luke.",
    ("Source Criticism", 8556, "add"): "The post evaluates whether Matthew and Luke used sources for their birth narratives.",
    ("Adoptionist Christology", 4667, "add"): "The post evaluates Luke 3:22 and the adoptionist interpretation supported by one textual form.",
    ("Adoptionist Christology", 4660, "add"): "The post explains the adoptionist reading of the voice at Jesus' baptism.",
    ("Adoptionist Christology", 3853, "add"): "A substantial part of the post explains Theodotian adoptionism and its connection with alleged textual alteration.",
    ("Adoptionist Christology", 16028, "add"): "The post directly examines the claim that Jesus was adopted as God's Son.",
    ("Historical Study of Miracles", 26387, "remove"): "The post centers on general historical criteria; its only apparent miracle reference is the name of a proposed signs source.",
    ("Historical Study of Miracles", 49842, "add"): "The post directly evaluates the historical credibility of Augustine's reported miracles.",
    ("Historical Study of Miracles", 49838, "add"): "The post examines biographical and eyewitness evidence offered for early Christian miracles.",
    ("Historical Study of Miracles", 49818, "add"): "The post directly asks how historians should understand miracle claims used to explain Christian conversion.",
    ("Historical Study of Miracles", 37661, "add"): "The post uses historical evidence to ask whether Jesus was regarded as a miracle worker before his death.",
    ("Historical Study of Miracles", 37649, "add"): "The post historically evaluates whether Jesus was considered a miracle worker during his lifetime.",
    ("Historical Study of Miracles", 15386, "add"): "The post historically evaluates whether Jesus was regarded as a miracle worker during his lifetime.",
    ("Trial of Jesus", 21862, "add"): "The post directly evaluates the historical credibility of the Barabbas episode in Jesus' trial.",
    ("Trial of Jesus", 36539, "add"): "The post directly interprets Jesus' statements during his trial before the Sanhedrin and high priest.",
    ("Trial of Jesus", 35760, "add"): "The post directly evaluates the Barabbas episode at Jesus' trial before Pilate.",
    ("Trial of Jesus", 35658, "add"): "The post examines Gospel memories and distortions concerning Jesus' trial before Pilate.",
    ("Trial of Jesus", 15929, "add"): "The post directly evaluates the historical plausibility of Pilate releasing Barabbas during Jesus' trial.",
    ("Trial of Jesus", 20741, "add"): "The post directly asks whether the Gospel writers invented the Barabbas episode in Jesus' trial.",
    ("Trial of Jesus", 14928, "add"): "The post interprets the literary ironies in John's account of Jesus' trial.",
    ("Athanasius of Alexandria", 48800, "remove"): "Athanasius is one later milestone in a broader discussion of canon formation.",
    ("Athanasius of Alexandria", 40657, "remove"): "Athanasius is supporting context in a post centered on why early Christian books were excluded from the canon.",
    ("Athanasius of Alexandria", 37235, "remove"): "Athanasius is incidental to the post's survey of the Nag Hammadi library.",
    ("Athanasius of Alexandria", 32505, "remove"): "Athanasius is one reference in a broader explanation of how the New Testament canon developed.",
    ("Athanasius of Alexandria", 27493, "remove"): "Athanasius is one chronological point in a broader history of canon formation.",
    ("Athanasius of Alexandria", 25282, "remove"): "The post centers on Arius's views; Athanasius supplies opposing context rather than a major subject.",
    ("Athanasius of Alexandria", 17169, "remove"): "Athanasius is one witness in a broader account of how the twenty-seven-book canon developed.",
    ("Athanasius of Alexandria", 16787, "remove"): "Athanasius is one witness in a broader account of why these twenty-seven books entered the canon.",
    ("Athanasius of Alexandria", 15643, "remove"): "Athanasius is only one stage in a broad discussion of decisions about the New Testament canon.",
    ("Athanasius of Alexandria", 12255, "remove"): "The post centers on false claims about Nicaea, reincarnation, and the canon rather than Athanasius.",
    ("Athanasius of Alexandria", 12253, "remove"): "Athanasius is one later figure in a broad history of the New Testament canon.",
    ("Athanasius of Alexandria", 10049, "remove"): "The post centers on Nicaea, Constantine, and claims from The Da Vinci Code rather than Athanasius.",
    ("Athanasius of Alexandria", 8887, "remove"): "Athanasius is incidental to the post's description of the Nag Hammadi collection.",
    ("Athanasius of Alexandria", 2108, "remove"): "Athanasius is one example in a general answer about the formation of the New Testament canon.",
    ("Atheism", 3927, "add"): "The post substantially distinguishes atheism from agnosticism and discusses morality without belief in God.",
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
                f"{topic}: missing removals={missing_removals}, existing additions={existing_additions}, "
                f"unknown posts={unknown_posts}"
            )

    for topic, description in DESCRIPTION_UPDATES.items():
        metadata_by_name[topic]["description"] = description

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

    tracker["updatedAt"] = AUDIT_DATE
    write_json(POSTS_PATH, posts)
    write_json(TOPICS_PATH, topic_data)
    write_json(TRACKER_PATH, tracker)

    print("Applied topic audits 126-150.")
    for topic, plan in PLANS.items():
        print(
            f"{int(plan['sequence']):3d}. {topic}: "
            f"{len(initial_members[topic])} -> {topic_counts[topic]}"
        )


if __name__ == "__main__":
    main()
