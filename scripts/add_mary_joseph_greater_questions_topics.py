"""Create three approved topics from their conservative full-text audits."""

from __future__ import annotations

import json
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
TOPICS_PATH = ROOT / "data" / "index" / "ehrman_post_topics.json"
CATEGORIES_PATH = ROOT / "data" / "index" / "ehrman_post_categories.json"
TRACKER_PATH = ROOT / "data" / "audits" / "ehrman_topic_audit_tracker.json"
AUDIT_PATH = ROOT / "data" / "audits" / "mary_joseph_greater_questions_topics_2026_08_19.json"

AUDIT_DATE = "2026-08-19"

TOPIC_SPECS: list[dict[str, Any]] = [
    {
        "name": "Mary, Mother of Jesus",
        "description": (
            "Covers Mary, the mother of Jesus, including her Gospel portrayal, virginity "
            "and family traditions, apocryphal accounts of her life, questions about "
            "Jesus' parentage, and reported visions of Mary."
        ),
        "categories": [
            "Jesus' Birth, Family, and Miracles",
            "New Testament Figures",
            "Women, Gender, and Sexuality",
        ],
        "post_ids": [
            22529,
            41835,
            22557,
            8391,
            13510,
            37140,
            14905,
            21016,
            4885,
            37156,
            5039,
            13608,
            36902,
            36908,
            36915,
            4002,
            9677,
        ],
        "reviewed_count": 103,
        "exclusion_summary": (
            "Excluded general birth narratives, genealogies, Christmas posts, and "
            "vision discussions in which Mary is only an example rather than a major subject."
        ),
    },
    {
        "name": "Joseph, Father of Jesus",
        "description": (
            "Covers Joseph, the father of Jesus in Christian tradition, including his role "
            "in canonical and apocryphal accounts, traditions about his age, family, and "
            "death, and debates over whether he was Jesus' biological father."
        ),
        "categories": [
            "Jesus' Birth, Family, and Miracles",
            "New Testament Figures",
        ],
        "post_ids": [
            48830,
            48832,
            22529,
            41835,
            22557,
            37676,
            36902,
            36908,
            36915,
            34743,
            34094,
            13510,
            37156,
            5039,
            13608,
        ],
        "reviewed_count": 102,
        "exclusion_summary": (
            "Excluded general birth narratives, genealogies, contradiction discussions, "
            "and infancy stories in which Joseph appears only as a narrative character."
        ),
    },
    {
        "name": "Greater Questions of Mary",
        "description": (
            "Examines the lost Greater Questions of Mary, Epiphanius's account of its "
            "revelation to Mary Magdalene, its alleged use by the Phibionites, and whether "
            "the text or the sexual rituals attributed to it ever existed."
        ),
        "categories": [
            "Gnosticism, Orthodoxy, and Heresy",
            "Non-Canonical Christian Texts",
        ],
        "post_ids": [35992, 29928, 29925, 20951, 20946, 12032, 3279],
        "reviewed_count": 7,
        "exclusion_summary": "All seven keyword posts qualified for the topic.",
    },
]

CATEGORY_INSERTIONS = [
    ("New Testament Figures", "Peter and Cephas", "Mary, Mother of Jesus"),
    ("New Testament Figures", "Mary, Mother of Jesus", "Joseph, Father of Jesus"),
    (
        "Jesus' Birth, Family, and Miracles",
        "Virgin Birth",
        "Mary, Mother of Jesus",
    ),
    (
        "Jesus' Birth, Family, and Miracles",
        "Mary, Mother of Jesus",
        "Joseph, Father of Jesus",
    ),
    ("Women, Gender, and Sexuality", "Jesus and Women", "Mary, Mother of Jesus"),
    (
        "Non-Canonical Christian Texts",
        "Gospel of Philip",
        "Greater Questions of Mary",
    ),
    (
        "Gnosticism, Orthodoxy, and Heresy",
        "Mary Magdalene in Gnostic Traditions",
        "Greater Questions of Mary",
    ),
]


def load_json(path: Path) -> object:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value: object) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def add_after(values: list[str], anchor: str, value: str) -> None:
    if value in values:
        raise ValueError(f"Ordered list already contains {value}")
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
    existing_topic_names = {record["name"] for record in topic_records}
    requested_topic_names = {spec["name"] for spec in TOPIC_SPECS}
    duplicates = sorted(existing_topic_names & requested_topic_names)
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
            post["secondaryKeywords"] = [
                keyword for keyword in post.get("secondaryKeywords", []) if keyword != name
            ]

        topic_records.append(
            {
                "name": name,
                "description": spec["description"],
                "categories": spec["categories"],
                "displayInBrowser": True,
            }
        )

        audit_topics.append(
            {
                "topic": name,
                "description": spec["description"],
                "categories": spec["categories"],
                "reviewedCandidatePostCount": spec["reviewed_count"],
                "topicPostCount": len(selected_posts),
                "exclusionSummary": spec["exclusion_summary"],
                "decisions": [
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
                ],
            }
        )

    topic_records.sort(key=lambda record: record["name"].casefold())

    categories_by_name = {
        category["name"]: category for category in category_data["categories"]
    }
    for category_name, anchor, topic_name in CATEGORY_INSERTIONS:
        add_after(categories_by_name[category_name]["topicOrder"], anchor, topic_name)

    actual_counts = {
        spec["name"]: sum(spec["name"] in post.get("topics", []) for post in posts)
        for spec in TOPIC_SPECS
    }
    expected_counts = {spec["name"]: len(spec["post_ids"]) for spec in TOPIC_SPECS}
    if actual_counts != expected_counts:
        raise ValueError(f"Unexpected topic counts: {actual_counts}")
    redundant = [
        (str(post["wpId"]), topic)
        for post in posts
        for topic in requested_topic_names
        if topic in post.get("topics", []) and topic in post.get("secondaryKeywords", [])
    ]
    if redundant:
        raise ValueError(f"Topic posts retain same-name keywords: {redundant}")

    existing_tracker_names = {entry["topic"] for entry in tracker["topics"]}
    if requested_topic_names & existing_tracker_names:
        raise ValueError("One or more tracker entries already exist")
    next_sequence = (
        max(
            int(entry["auditSequence"])
            for entry in tracker["topics"]
            if entry.get("auditSequence") is not None
        )
        + 1
    )
    new_tracker_entries = []
    for offset, audit_topic in enumerate(audit_topics):
        new_tracker_entries.append(
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
                        f"Created after a full-text review of "
                        f"{audit_topic['reviewedCandidatePostCount']} candidate posts; "
                        f"{audit_topic['topicPostCount']} treat the subject as primary or sustained."
                    ),
                    audit_topic["exclusionSummary"],
                    "Identically named secondary keywords were removed from the topic posts.",
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
    tracker["topics"][ignore_index:ignore_index] = new_tracker_entries
    tracker["updatedAt"] = AUDIT_DATE

    audit = {"auditDate": AUDIT_DATE, "topics": audit_topics}

    write_json(POSTS_PATH, posts)
    write_json(TOPICS_PATH, topic_data)
    write_json(CATEGORIES_PATH, category_data)
    write_json(TRACKER_PATH, tracker)
    write_json(AUDIT_PATH, audit)
    for name, count in actual_counts.items():
        print(f"Created {name} with {count} posts.")


if __name__ == "__main__":
    main()
