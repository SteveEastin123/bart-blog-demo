"""Create the approved Acts of Thomas topic and split it from Apocryphal Acts."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
TOPICS_PATH = ROOT / "data" / "index" / "ehrman_post_topics.json"
TRACKER_PATH = ROOT / "data" / "audits" / "ehrman_topic_audit_tracker.json"
AUDIT_DATE = "2026-08-01"

TOPIC = "Acts of Thomas"
CATEGORY = "Non-Canonical Christian Texts"
DESCRIPTION = (
    "Covers the Acts of Thomas, including Thomas's mission to India, identification as Jesus' "
    "twin, ascetic teachings, views of wealth, and journeys to the afterlife."
)
APOCRYPHAL_ACTS_DESCRIPTION = (
    "Covers apocryphal acts attributed to apostles, including legendary missionary stories, "
    "miracle traditions, chastity, wealth, and traditions about Peter, Paul, and other apostles."
)

ADD_IDS = [
    37435,
    37167,
    30969,
    30975,
    21300,
    17368,
    16473,
    16467,
    16425,
    15459,
    15457,
    5067,
    12924,
    12910,
]

REMOVE_APOCRYPHAL_ACTS_IDS = [
    37435,
    37167,
    30969,
    30975,
    21300,
    16467,
    15459,
    15457,
    5067,
]

REMOVE_SAME_NAME_KEYWORD_IDS = [12910]


def load_json(path: Path) -> object:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value: object) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def main() -> None:
    posts = load_json(POSTS_PATH)
    topic_data = load_json(TOPICS_PATH)
    tracker = load_json(TRACKER_PATH)
    if not isinstance(posts, list) or not isinstance(topic_data, dict) or not isinstance(tracker, dict):
        raise TypeError("Unexpected source JSON shape")

    posts_by_id = {int(post["wpId"]): post for post in posts}
    if len(posts_by_id) != len(posts):
        raise ValueError("Duplicate wpId values in post search index")
    unknown_ids = [wp_id for wp_id in ADD_IDS if wp_id not in posts_by_id]
    if unknown_ids:
        raise ValueError(f"Unknown wpId values: {unknown_ids}")

    topic_records = topic_data["topics"]
    if any(record["name"] == TOPIC for record in topic_records):
        raise ValueError(f"Topic already exists: {TOPIC}")
    if any(TOPIC in posts_by_id[wp_id].get("topics", []) for wp_id in ADD_IDS):
        raise ValueError(f"One or more posts already use {TOPIC}")
    missing_general = [
        wp_id
        for wp_id in REMOVE_APOCRYPHAL_ACTS_IDS
        if "Apocryphal Acts" not in posts_by_id[wp_id].get("topics", [])
    ]
    if missing_general:
        raise ValueError(f"Posts missing Apocryphal Acts: {missing_general}")
    missing_keywords = [
        wp_id
        for wp_id in REMOVE_SAME_NAME_KEYWORD_IDS
        if TOPIC not in posts_by_id[wp_id].get("secondaryKeywords", [])
    ]
    if missing_keywords:
        raise ValueError(f"Posts missing the same-name secondary keyword: {missing_keywords}")

    for wp_id in ADD_IDS:
        posts_by_id[wp_id]["topics"].append(TOPIC)
    for wp_id in REMOVE_APOCRYPHAL_ACTS_IDS:
        post = posts_by_id[wp_id]
        post["topics"] = [value for value in post["topics"] if value != "Apocryphal Acts"]
    for wp_id in REMOVE_SAME_NAME_KEYWORD_IDS:
        post = posts_by_id[wp_id]
        post["secondaryKeywords"] = [
            value for value in post.get("secondaryKeywords", []) if value != TOPIC
        ]

    for post in posts:
        post["topics"] = list(dict.fromkeys(post.get("topics", [])))
        post["secondaryKeywords"] = list(dict.fromkeys(post.get("secondaryKeywords", [])))
    newly_untagged = [
        wp_id for wp_id in REMOVE_APOCRYPHAL_ACTS_IDS if not posts_by_id[wp_id]["topics"]
    ]
    if newly_untagged:
        raise ValueError(f"The split would leave posts without topics: {newly_untagged}")

    apocryphal_metadata = next(
        record for record in topic_records if record["name"] == "Apocryphal Acts"
    )
    apocryphal_metadata["description"] = APOCRYPHAL_ACTS_DESCRIPTION
    insert_at = topic_records.index(apocryphal_metadata) + 1
    topic_records.insert(
        insert_at,
        {
            "name": TOPIC,
            "description": DESCRIPTION,
            "categories": [CATEGORY],
            "displayInBrowser": True,
        },
    )

    actual_new = [post for post in posts if TOPIC in post.get("topics", [])]
    actual_general = [post for post in posts if "Apocryphal Acts" in post.get("topics", [])]
    if len(actual_new) != 14 or len(actual_general) != 15:
        raise ValueError(
            f"Unexpected topic counts: {TOPIC}={len(actual_new)}, Apocryphal Acts={len(actual_general)}"
        )

    tracker_by_name = {entry["topic"]: entry for entry in tracker["topics"]}
    if TOPIC in tracker_by_name:
        raise ValueError(f"Tracker entry already exists: {TOPIC}")
    apocryphal_tracker = tracker_by_name["Apocryphal Acts"]
    apocryphal_tracker["postCountAfter"] = len(actual_general)
    apocryphal_tracker["descriptionRecommendation"] = APOCRYPHAL_ACTS_DESCRIPTION
    removed_ids = {str(wp_id) for wp_id in REMOVE_APOCRYPHAL_ACTS_IDS}
    for decision in apocryphal_tracker["decisions"]:
        if str(decision["wpId"]) in removed_ids:
            decision.update(
                {
                    "decision": "remove",
                    "confidence": "high",
                    "reason": "The post is now classified under the more specific Acts of Thomas topic.",
                }
            )
    apocryphal_tracker["notes"] = [
        "Full-text audit completed; approved decisions applied.",
        "Nine Thomas-specific posts were moved to the dedicated Acts of Thomas topic.",
    ]

    new_tracker_entry = {
        "topic": TOPIC,
        "auditSequence": 271,
        "status": "completed",
        "postCountBefore": 0,
        "postCountAfter": len(actual_new),
        "descriptionBefore": DESCRIPTION,
        "descriptionRecommendation": DESCRIPTION,
        "categoriesBefore": [CATEGORY],
        "categoryRecommendation": "Retain current category placement.",
        "startedAt": AUDIT_DATE,
        "completedAt": AUDIT_DATE,
        "decisions": [
            {
                "wpId": str(post["wpId"]),
                "title": post["title"],
                "decision": "add",
                "confidence": "high",
                "reason": "The full post treats the Acts of Thomas as a primary or major sustained subject.",
            }
            for post in actual_new
        ],
        "notes": [
            "Created after a full-text review of 36 posts mentioning the Acts of Thomas; "
            "14 treat the text as a major subject."
        ],
    }
    ignore_index = next(
        (index for index, entry in enumerate(tracker["topics"]) if entry["topic"] == "Ignore"),
        len(tracker["topics"]),
    )
    tracker["topics"].insert(ignore_index, new_tracker_entry)
    tracker["updatedAt"] = AUDIT_DATE

    write_json(POSTS_PATH, posts)
    write_json(TOPICS_PATH, topic_data)
    write_json(TRACKER_PATH, tracker)
    print(f"Created {TOPIC} with {len(actual_new)} posts.")
    print(f"Apocryphal Acts now has {len(actual_general)} posts.")
    print(f"Removed the same-name secondary keyword from {len(REMOVE_SAME_NAME_KEYWORD_IDS)} post.")


if __name__ == "__main__":
    main()
