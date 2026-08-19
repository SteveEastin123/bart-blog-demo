"""Create three approved topics from their full-text candidate audits."""

from __future__ import annotations

import json
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
TOPICS_PATH = ROOT / "data" / "index" / "ehrman_post_topics.json"
CATEGORIES_PATH = ROOT / "data" / "index" / "ehrman_post_categories.json"
TRACKER_PATH = ROOT / "data" / "audits" / "ehrman_topic_audit_tracker.json"
AUDIT_PATH = ROOT / "data" / "audits" / "three_new_topics_2026_08_19.json"

AUDIT_DATE = "2026-08-19"

TOPIC_SPECS: list[dict[str, Any]] = [
    {
        "name": "Gospel of Philip",
        "description": (
            "Covers the Gospel of Philip, including its Valentinian Gnostic teachings, Nag "
            "Hammadi context, portrayal of Mary Magdalene, and the passage describing Jesus "
            "kissing her."
        ),
        "categories": [
            "Gnosticism, Orthodoxy, and Heresy",
            "Non-Canonical Christian Texts",
        ],
        "post_ids": [38493, 37384, 37380, 23254, 17615, 14899, 6891],
        "reviewed_count": 8,
        "excluded": [
            {
                "wpId": "40302",
                "title": "A (Modern-Discovered) Gospel That Shows Jesus Was Married With Children?",
                "reason": (
                    "The post critiques claims about Joseph and Aseneth; the Gospel of Philip is "
                    "comparative context rather than a sustained subject."
                ),
            }
        ],
    },
    {
        "name": "Documentary Hypothesis",
        "description": (
            "Covers the Documentary Hypothesis and related source theories for the composition "
            "of the Pentateuch, including JEDP sources, challenges to Mosaic authorship, textual "
            "inconsistencies, and recent scholarship."
        ),
        "categories": ["Authorship", "Hebrew Bible Texts and Traditions"],
        "post_ids": [
            33426,
            25687,
            25682,
            25677,
            25647,
            24315,
            16802,
            15263,
            11611,
            11602,
            11599,
            11587,
            11581,
        ],
        "reviewed_count": 15,
        "excluded": [
            {
                "wpId": "11643",
                "title": "Suggestions for Further Reading on the Pentateuch",
                "reason": (
                    "The post is primarily a reading list rather than a sustained treatment of "
                    "the Documentary Hypothesis."
                ),
            },
            {
                "wpId": "2328",
                "title": "The Hebrew Bible and Its Sources",
                "reason": (
                    "The post primarily recommends introductory resources rather than developing "
                    "the Documentary Hypothesis as a major subject."
                ),
            },
        ],
    },
    {
        "name": "Peter and Cephas",
        "description": (
            "Examines whether Peter and Cephas were the same person, including Paul's usage, "
            "evidence from Galatians and John, and early Christian traditions that distinguish them."
        ),
        "categories": ["New Testament Figures"],
        "post_ids": [50385, 50251, 12120, 12118, 12112],
        "reviewed_count": 5,
        "excluded": [],
    },
]

CATEGORY_INSERTIONS = {
    "Gnosticism, Orthodoxy, and Heresy": ("Valentinian Gnostics", "Gospel of Philip"),
    "Non-Canonical Christian Texts": ("Gospel of Judas", "Gospel of Philip"),
    "Authorship": ("Hebrew Bible Composition and Sources", "Documentary Hypothesis"),
    "Hebrew Bible Texts and Traditions": (
        "Hebrew Bible Composition and Sources",
        "Documentary Hypothesis",
    ),
    "New Testament Figures": ("Peter the Apostle", "Peter and Cephas"),
}


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

        additions = [
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
        exclusions = [
            {
                **decision,
                "decision": "exclude",
                "confidence": "high",
            }
            for decision in spec["excluded"]
        ]
        audit_topics.append(
            {
                "topic": name,
                "description": spec["description"],
                "categories": spec["categories"],
                "reviewedKeywordPostCount": spec["reviewed_count"],
                "topicPostCount": len(selected_posts),
                "decisions": [*additions, *exclusions],
            }
        )

    categories_by_name = {
        category["name"]: category for category in category_data["categories"]
    }
    for category_name, (anchor, topic_name) in CATEGORY_INSERTIONS.items():
        add_after(categories_by_name[category_name]["topicOrder"], anchor, topic_name)

    gnosticism_category = categories_by_name["Gnosticism, Orthodoxy, and Heresy"]
    gnosticism_category["description"] = (
        "Covers Gnostic movements, Nag Hammadi writings, Marcion, the Gospels of Judas and "
        "Philip, Mary Magdalene traditions, and early Christian disputes over doctrine, "
        "scripture, authority, orthodoxy, and heresy."
    )

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
    next_sequence = max(
        int(entry["auditSequence"])
        for entry in tracker["topics"]
        if entry.get("auditSequence") is not None
    ) + 1
    new_tracker_entries = []
    for offset, audit_topic in enumerate(audit_topics):
        additions = [
            decision
            for decision in audit_topic["decisions"]
            if decision["decision"] == "add"
        ]
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
                "decisions": additions,
                "notes": [
                    (
                        f"Created after a full-text review of "
                        f"{audit_topic['reviewedKeywordPostCount']} candidate posts; "
                        f"{audit_topic['topicPostCount']} treat the subject as primary or sustained."
                    ),
                    "Identically named secondary keywords were removed from the topic posts.",
                ],
            }
        )
    ignore_index = next(
        (index for index, entry in enumerate(tracker["topics"]) if entry["topic"] == "Ignore"),
        len(tracker["topics"]),
    )
    tracker["topics"][ignore_index:ignore_index] = new_tracker_entries
    tracker["updatedAt"] = AUDIT_DATE

    audit = {
        "auditDate": AUDIT_DATE,
        "topics": audit_topics,
    }

    write_json(POSTS_PATH, posts)
    write_json(TOPICS_PATH, topic_data)
    write_json(CATEGORIES_PATH, category_data)
    write_json(TRACKER_PATH, tracker)
    write_json(AUDIT_PATH, audit)
    for name, count in actual_counts.items():
        print(f"Created {name} with {count} posts.")


if __name__ == "__main__":
    main()
