"""Apply the approved full-text topic audits for audit sequences 71-80."""

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
    "Resurrection of Jesus": {
        "sequence": 71,
        "remove": [47869, 47002, 15268, 3064],
        "add": [6376],
    },
    "Triumph of Christianity": {
        "sequence": 72,
        "remove": [15140],
        "add": [40344],
    },
    "Agnosticism": {
        "sequence": 73,
        "remove": [],
        "add": [],
    },
    "Apocalypse of Peter": {
        "sequence": 74,
        "remove": [6376],
        "add": [],
        "description": (
            "Covers two distinct works called the Apocalypse of Peter: the Greek/Ethiopic "
            "apocalypse with tours of heaven and hell and canon debates, and the Coptic/Gnostic "
            "apocalypse with its separationist view of Christ."
        ),
    },
    "Crucifixion of Jesus": {
        "sequence": 75,
        "remove": [],
        "add": [11439],
    },
    "Didymus the Blind": {
        "sequence": 76,
        "remove": [22231, 16580, 13343, 9141, 8447, 4644],
        "add": [],
    },
    "Early Christian Writings": {
        "sequence": 77,
        "remove": [
            50148,
            48954,
            48949,
            48090,
            48063,
            39582,
            35549,
            34961,
            35041,
            34953,
            22529,
            16612,
            16026,
            14982,
            14980,
            2282,
        ],
        "add": [46993, 40657],
        "description": (
            "Covers broad surveys, anthologies, courses, and lost collections involving early "
            "Christian writings within and beyond the New Testament."
        ),
    },
    "Acts": {
        "sequence": 78,
        "remove": [],
        "add": [47112, 17477, 15602, 15254, 13072],
    },
    "Dead Sea Scrolls and Essenes": {
        "sequence": 79,
        "remove": [48693, 12186, 12248],
        "add": [],
    },
    "First-Century Mark Fragment": {
        "sequence": 80,
        "remove": [17505, 16314],
        "add": [41678, 41662, 41656, 20540, 12454, 8459, 8440],
    },
}


REPLACEMENT_ADDS: dict[str, list[int]] = {
    "Church Fathers as Textual Evidence": [4644],
    "Modern Forgery Claims": [35041],
    "Textual Criticism Overview": [16612],
}


SPECIFIC_REASONS: dict[tuple[str, int, str], str] = {
    ("Resurrection of Jesus", 47869, "remove"): "Resurrection is only one section of a multi-question post.",
    ("Resurrection of Jesus", 47002, "remove"): "Resurrection is only one section of a multi-question post.",
    ("Resurrection of Jesus", 15268, "remove"): "The post centers on Luke-Acts contradictions and uses resurrection timing as one example.",
    ("Resurrection of Jesus", 3064, "remove"): "Resurrection research illustrates a broader discussion of researching and writing books.",
    ("Resurrection of Jesus", 6376, "add"): "The post is sustainedly about competing early Christian understandings of Jesus' resurrected body.",
    ("Triumph of Christianity", 15140, "remove"): "The interview is mainly autobiographical; the book appears only near the end.",
    ("Triumph of Christianity", 40344, "add"): "The post directly explains the thesis of The Triumph of Christianity.",
    ("Apocalypse of Peter", 6376, "remove"): "The Coptic Apocalypse of Peter is only a brief comparator in a post about bodily resurrection.",
    ("Crucifixion of Jesus", 11439, "add"): "A substantial section compares New Testament interpretations of Jesus' crucifixion.",
    ("Early Christian Writings", 16612, "remove"): "The post concerns the later creation of biblical chapter and verse divisions.",
    ("Early Christian Writings", 2282, "remove"): "The post recommends modern scholarly reading rather than examining early Christian writings as a corpus.",
    ("Early Christian Writings", 46993, "add"): "The course assignments sustain attention to a broad range of early Christian writings.",
    ("Early Christian Writings", 40657, "add"): "The post broadly examines early Christian literature and why some writings entered the canon while others did not.",
    ("Dead Sea Scrolls and Essenes", 48693, "remove"): "The Scrolls provide only brief background for a broader question about textual accuracy.",
    ("Dead Sea Scrolls and Essenes", 12186, "remove"): "The Scrolls are mentioned only as background to Hebrew Bible manuscript transmission.",
    ("Dead Sea Scrolls and Essenes", 12248, "remove"): "The Scrolls supply one supporting example in a post centered on formation of the Hebrew Bible.",
    ("First-Century Mark Fragment", 17505, "remove"): "The post merely recommends an outside article about the controversy.",
    ("First-Century Mark Fragment", 16314, "remove"): "The controversy introduces a different investigation into a stolen medieval manuscript.",
}


EARLY_WRITING_SPECIFIC = {
    50148,
    48954,
    48949,
    48090,
    48063,
    39582,
    22529,
    16026,
    14982,
    14980,
}
EARLY_WRITING_MODERN_FORGERY = {35549, 34961, 35041, 34953}


def load_json(path: Path) -> object:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value: object) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def reason_for(topic: str, wp_id: int, decision: str) -> str:
    specific = SPECIFIC_REASONS.get((topic, wp_id, decision))
    if specific:
        return specific
    if topic == "Didymus the Blind" and decision == "remove":
        return "Didymus is only an example or autobiographical reference in a post centered on a broader subject."
    if topic == "Early Christian Writings" and decision == "remove":
        if wp_id in EARLY_WRITING_SPECIFIC:
            return "The post centers on a particular named writing already represented by a more precise topic."
        if wp_id in EARLY_WRITING_MODERN_FORGERY:
            return "The post centers on the modern Secret Gospel of Mark controversy rather than early Christian writings broadly."
    if topic == "Acts" and decision == "add":
        return "Acts is a primary and sustained subject of the full post."
    if topic == "First-Century Mark Fragment" and decision == "add":
        return "The post is a central installment in the First-Century Mark claim, mummy-mask, or manuscript-fraud controversy."
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
            raise ValueError(f"Missing replacement-topic metadata or tracker entry for {topic!r}")
        for wp_id in wp_ids:
            if wp_id not in posts_by_id:
                raise ValueError(f"Replacement topic {topic!r}: unknown wpId {wp_id}")
            if topic in posts_by_id[wp_id].get("topics", []):
                raise ValueError(f"Replacement topic {topic!r} already exists on wpId {wp_id}")
            posts_by_id[wp_id]["topics"].append(topic)

    for topic, plan in PLANS.items():
        for wp_id in plan["remove"]:
            post = posts_by_id[wp_id]
            post["topics"] = [value for value in post["topics"] if value != topic]
            if not post["topics"]:
                raise ValueError(f"Removing {topic!r} would leave wpId {wp_id} without a topic")
        for wp_id in plan["add"]:
            posts_by_id[wp_id]["topics"].append(topic)

        description = plan.get("description")
        if description:
            metadata_by_name[topic]["description"] = description

    topic_counts = Counter(topic for post in posts for topic in post.get("topics", []))
    for topic in REPLACEMENT_ADDS:
        entry = tracker_by_name[topic]
        if entry["status"] == "pending":
            entry["postCountBefore"] = topic_counts[topic]
    for topic, plan in PLANS.items():
        initial = initial_members[topic]
        remove = set(plan["remove"])
        add = list(plan["add"])
        expected_after = len(initial) - len(remove) + len(add)
        if topic_counts[topic] != expected_after:
            raise ValueError(f"{topic}: expected {expected_after} posts after update, found {topic_counts[topic]}")

        entry = tracker_by_name[topic]
        metadata = metadata_by_name[topic]
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
                "postCountBefore": len(initial),
                "postCountAfter": expected_after,
                "descriptionRecommendation": metadata["description"],
                "categoryRecommendation": "Retain current category links.",
                "startedAt": AUDIT_DATE,
                "completedAt": AUDIT_DATE,
                "decisions": decisions,
                "notes": [
                    f"Reviewed all {len(initial)} linked posts against the full local text.",
                    "Checked unlinked posts conservatively for strong omissions.",
                    "Approved recommendations were applied to live topic assignments.",
                ],
            }
        )

    tracker["updatedAt"] = AUDIT_DATE
    write_json(POSTS_PATH, posts)
    write_json(TOPICS_PATH, topic_data)
    write_json(TRACKER_PATH, tracker)

    print("Applied topic audits 71-80")
    for topic in PLANS:
        print(f"{topic}: {len(initial_members[topic])} -> {topic_counts[topic]}")


if __name__ == "__main__":
    main()
