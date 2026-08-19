"""Create five topics approved after conservative full-text audits."""

from __future__ import annotations

import json
import re
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
TOPICS_PATH = ROOT / "data" / "index" / "ehrman_post_topics.json"
CATEGORIES_PATH = ROOT / "data" / "index" / "ehrman_post_categories.json"
TRACKER_PATH = ROOT / "data" / "audits" / "ehrman_topic_audit_tracker.json"
AUDIT_PATH = ROOT / "data" / "audits" / "five_strong_candidate_topics_2026_08_19.json"

AUDIT_DATE = "2026-08-19"

TOPIC_SPECS: list[dict[str, Any]] = [
    {
        "name": "Love Thy Stranger",
        "description": (
            "Covers Bart's book Love Thy Stranger, including its argument that Jesus' "
            "teachings reshaped Western ideas about strangers, charity, forgiveness, "
            "and care for people in need."
        ),
        "categories": ["Bart's Books"],
        "post_ids": [
            50263,
            49699,
            49472,
            49394,
            49339,
            49337,
            49334,
            49071,
            48919,
            48737,
            48527,
        ],
        "reviewed_count": 11,
        "candidate_basis": (
            "posts whose titles, descriptions, keywords, or full text identify Love "
            "Thy Stranger as a major subject"
        ),
    },
    {
        "name": "Gospel of the Ebionites",
        "description": (
            "Examines the fragmentary Gospel of the Ebionites, including its "
            "harmonized Gospel traditions, account of Jesus' baptism, and portrayal "
            "of John the Baptist's diet."
        ),
        "categories": ["Non-Canonical Christian Texts"],
        "post_ids": [36829, 36824, 36819, 34494, 4814, 4806, 4803],
        "reviewed_count": 11,
        "candidate_basis": (
            "posts mentioning the Gospel of the Ebionites or discussing the broader "
            "Jewish-Christian Gospel tradition"
        ),
    },
    {
        "name": "Son of God",
        "description": (
            "Examines the meaning and development of the title Son of God, including "
            "its use in the Gospels, adoption and exaltation Christologies, "
            "resurrection traditions, textual variants, and later Christian debates."
        ),
        "categories": ["Christology"],
        "post_ids": [
            47113,
            37841,
            37091,
            35354,
            33310,
            33309,
            23935,
            23381,
            23250,
            16028,
            15925,
            15268,
            13506,
            13504,
            13142,
            12408,
            12375,
            12373,
            12363,
            10270,
            10261,
            10049,
            9399,
            9264,
            9255,
            9220,
            9215,
            9197,
            9020,
            9018,
            7155,
            6967,
            6962,
            6946,
            6942,
            4672,
            3947,
            3816,
            3795,
        ],
        "reviewed_count": 215,
        "candidate_basis": "posts containing the Son of God secondary keyword",
    },
    {
        "name": "Triumphal Entry",
        "description": (
            "Examines Gospel traditions about Jesus' triumphal entry into Jerusalem, "
            "including their historical plausibility, development in memory, "
            "messianic symbolism, and connection with his arrest."
        ),
        "categories": ["Jesus' Death, Burial, and Resurrection"],
        "post_ids": [50317, 50319, 35742, 24729, 24593, 10294, 10292],
        "reviewed_count": 12,
        "candidate_basis": (
            "posts whose titles, descriptions, or full text discuss the triumphal entry"
        ),
    },
    {
        "name": "Gospel of Nicodemus",
        "description": (
            "Covers the Gospel of Nicodemus, also associated with the Acts of Pilate "
            "tradition, including its expanded trial narrative, descent to Hades, "
            "miraculous Roman standards, and later legendary traditions."
        ),
        "categories": ["Non-Canonical Christian Texts"],
        "post_ids": [21368, 21355, 20725, 15763, 15221, 8833],
        "reviewed_count": 15,
        "candidate_basis": "posts containing the Gospel of Nicodemus secondary keyword",
    },
]

CATEGORY_INSERTIONS = [
    ("Bart's Books", "Armageddon", "Love Thy Stranger"),
    ("Non-Canonical Christian Texts", "Jewish Christian Gospels", "Gospel of the Ebionites"),
    ("Non-Canonical Christian Texts", "Gospel of the Ebionites", "Gospel of Nicodemus"),
    ("Christology", "Christology (General)", "Son of God"),
    (
        "Jesus' Death, Burial, and Resurrection",
        "Jesus' Passion Narratives",
        "Triumphal Entry",
    ),
]


def load_json(path: Path) -> object:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value: object) -> None:
    path.write_text(
        json.dumps(value, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
        newline="\n",
    )


def normalize(value: object) -> str:
    """Match the normalization used by the public search implementation."""
    text = str(value or "").strip().lower().replace("&", " and ")
    text = re.sub(r"[^a-z0-9]+", " ", text).strip()
    return re.sub(r"\s+", " ", text)


def add_after(values: list[str], anchor: str, value: str) -> None:
    if value in values:
        raise ValueError(f"Ordered list already contains {value}")
    if anchor not in values:
        raise ValueError(f"Ordered list is missing anchor {anchor}")
    values.insert(values.index(anchor) + 1, value)


def main() -> None:
    posts = load_json(POSTS_PATH)
    topic_data = load_json(TOPICS_PATH)
    category_data = load_json(CATEGORIES_PATH)
    tracker = load_json(TRACKER_PATH)
    if not isinstance(posts, list):
        raise TypeError("Post search index must be a list")
    if not all(isinstance(value, dict) for value in (topic_data, category_data, tracker)):
        raise TypeError("Unexpected metadata JSON shape")

    posts_by_id = {int(post["wpId"]): post for post in posts}
    if len(posts_by_id) != len(posts):
        raise ValueError("Duplicate wpId values in post search index")

    topic_records = topic_data["topics"]
    requested_names = {spec["name"] for spec in TOPIC_SPECS}
    existing_names = {record["name"] for record in topic_records}
    duplicates = sorted(requested_names & existing_names)
    if duplicates:
        raise ValueError(f"Topics already exist: {duplicates}")

    unknown_ids = sorted(
        {
            wp_id
            for spec in TOPIC_SPECS
            for wp_id in spec["post_ids"]
            if wp_id not in posts_by_id
        }
    )
    if unknown_ids:
        raise ValueError(f"Unknown wpId values: {unknown_ids}")

    audit_topics: list[dict[str, object]] = []
    for spec in TOPIC_SPECS:
        name = spec["name"]
        selected_posts = [posts_by_id[wp_id] for wp_id in spec["post_ids"]]
        for post in selected_posts:
            post["topics"] = list(dict.fromkeys([*post.get("topics", []), name]))
            topic_key = normalize(name)
            post["secondaryKeywords"] = [
                keyword
                for keyword in post.get("secondaryKeywords", [])
                if normalize(keyword) != topic_key
            ]

        topic_records.append(
            {
                "name": name,
                "description": spec["description"],
                "categories": spec["categories"],
                "displayInBrowser": True,
            }
        )

        decisions = [
            {
                "wpId": str(post["wpId"]),
                "title": post["title"],
                "decision": "add",
                "confidence": "high",
                "reason": (
                    f"The full post treats {name} as a primary or major sustained subject."
                ),
            }
            for post in selected_posts
        ]
        audit_topics.append(
            {
                "topic": name,
                "description": spec["description"],
                "categories": spec["categories"],
                "candidateBasis": spec["candidate_basis"],
                "reviewedCandidateCount": spec["reviewed_count"],
                "topicPostCount": len(selected_posts),
                "excludedCandidateCount": spec["reviewed_count"] - len(selected_posts),
                "decisions": decisions,
            }
        )

    categories_by_name = {
        category["name"]: category for category in category_data["categories"]
    }
    for category_name, anchor, topic_name in CATEGORY_INSERTIONS:
        if category_name not in categories_by_name:
            raise ValueError(f"Unknown category: {category_name}")
        add_after(categories_by_name[category_name]["topicOrder"], anchor, topic_name)

    expected_counts = {spec["name"]: len(spec["post_ids"]) for spec in TOPIC_SPECS}
    actual_counts = {
        name: sum(name in post.get("topics", []) for post in posts)
        for name in requested_names
    }
    if actual_counts != expected_counts:
        raise ValueError(f"Unexpected topic counts: {actual_counts}")

    redundant = [
        (str(post["wpId"]), topic, keyword)
        for post in posts
        for topic in post.get("topics", [])
        for keyword in post.get("secondaryKeywords", [])
        if normalize(topic) == normalize(keyword)
    ]
    if redundant:
        raise ValueError(f"Topic posts retain same-name keywords: {redundant[:10]}")

    existing_tracker_names = {entry["topic"] for entry in tracker["topics"]}
    tracker_duplicates = sorted(requested_names & existing_tracker_names)
    if tracker_duplicates:
        raise ValueError(f"Tracker entries already exist: {tracker_duplicates}")
    next_sequence = max(
        int(entry["auditSequence"])
        for entry in tracker["topics"]
        if entry.get("auditSequence") is not None
    ) + 1

    tracker_entries: list[dict[str, object]] = []
    for offset, audit_topic in enumerate(audit_topics):
        tracker_entries.append(
            {
                "topic": audit_topic["topic"],
                "auditSequence": next_sequence + offset,
                "status": "completed",
                "postCountBefore": 0,
                "postCountAfter": audit_topic["topicPostCount"],
                "descriptionBefore": audit_topic["description"],
                "descriptionRecommendation": audit_topic["description"],
                "categoriesBefore": audit_topic["categories"],
                "categoryRecommendation": "Retain current category placement.",
                "startedAt": AUDIT_DATE,
                "completedAt": AUDIT_DATE,
                "decisions": audit_topic["decisions"],
                "notes": [
                    (
                        f"Created after a conservative full-text review of "
                        f"{audit_topic['reviewedCandidateCount']} candidates; "
                        f"{audit_topic['topicPostCount']} treat the subject as primary "
                        "or sustained."
                    ),
                    "Identically normalized secondary keywords were removed from topic posts.",
                ],
            }
        )

    ignore_index = next(
        (
            index
            for index, entry in enumerate(tracker["topics"])
            if entry["topic"] == "Ignore"
        ),
        len(tracker["topics"]),
    )
    tracker["topics"][ignore_index:ignore_index] = tracker_entries
    tracker["updatedAt"] = AUDIT_DATE

    audit = {
        "auditDate": AUDIT_DATE,
        "method": (
            "Conservative full-text review; a topic was assigned only when it was a "
            "primary or major sustained subject of the post."
        ),
        "topics": audit_topics,
        "heldCandidate": {
            "name": "Paul and Barnabas",
            "reviewedCandidateCount": 26,
            "directPostCount": 4,
            "decision": "do_not_create",
            "reason": (
                "Only four posts treat Paul and Barnabas as an unequivocal primary "
                "subject, and all four form one guest-post series. Existing topics "
                "already provide adequate coverage."
            ),
        },
    }

    write_json(POSTS_PATH, posts)
    write_json(TOPICS_PATH, topic_data)
    write_json(CATEGORIES_PATH, category_data)
    write_json(TRACKER_PATH, tracker)
    write_json(AUDIT_PATH, audit)

    for name in sorted(actual_counts):
        print(f"Created {name} with {actual_counts[name]} posts.")


if __name__ == "__main__":
    main()
