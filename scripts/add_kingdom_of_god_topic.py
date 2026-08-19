"""Create the approved Kingdom of God topic from the full-text audit."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
TOPICS_PATH = ROOT / "data" / "index" / "ehrman_post_topics.json"
CATEGORIES_PATH = ROOT / "data" / "index" / "ehrman_post_categories.json"
TRACKER_PATH = ROOT / "data" / "audits" / "ehrman_topic_audit_tracker.json"
AUDIT_PATH = ROOT / "data" / "audits" / "kingdom_of_god_topic_creation.json"

AUDIT_DATE = "2026-08-19"
TOPIC = "Kingdom of God"
CATEGORY = "Jesus' Teachings and Social World"
DESCRIPTION = (
    "Covers Jesus' proclamation of the coming Kingdom of God, including its apocalyptic "
    "meaning, who would enter it, and its implications for ethics, wealth, miracles, and "
    "messianic expectations."
)
CATEGORY_DESCRIPTION = (
    "Covers Jesus' teachings, proclamation of God's kingdom, ethical vision, economic setting, "
    "views on wealth and poverty, and traditions involving women in his life and Gospel portrayals."
)

POST_TITLES = [
    "Jesus’ Teaching About the Kingdom of God",
    "The Preaching of Jesus in a Nutshell",
    "The Teaching of Jesus",
    "The Heart of Jesus’ Message",
    "The Messages of Jesus and Paul:  Basically the Same or Fundamentally Different?",
    "Jesus and Paul Compared and Contrasted",
    "Jesus and Paul: Similarities and Differences",
    "On the Flipside: The Glorious Salvation of Saints in the Teachings of Jesus",
    "Did Jesus Insist on Voluntary Poverty?",
    "The Indifference of Jesus",
    "Paying Your Religious Dues:  The Studied Indifference of Jesus",
    "Thoughts on Jesus and Activism.  What Do You Think?",
    "Render Unto Caesar: Jesus’s Indifference to Human Governments and Economies",
    "Are the Teachings of Jesus Realistic?   Platinum guest post by Douglas Wadeson",
    "Are the Teachings of Jesus Realistic?  Guest Post by Douglas Wadeson",
    "Why Jesus Does Miracles",
    "The Message of Jesus’ Miracles",
    "What’s the *Point* of Jesus’ Miracles?",
    "Jesus’ Claim to Be the Messiah",
    "Why Should We Think Jesus Called Himself the Messiah?",
    "The Triumphal Entry and Jesus’s Mistaken Identity",
    "The Later De-apocalypticizing of Jesus",
    "How Jesus’ Apocalyptic Teachings Were Changed (even in the NT)",
    "How the Gospels Transformed the Apocalyptic Jesus",
    "Did Jesus Believe The End Would Come Within His Lifetime? Platinum Post by Rizwan Ahmed",
    "Did Jesus Believe the End Would Come Within his Lifetime? Maybe Not!  Platinum Post by Rizwan Ahmed",
    "The Message of Jesus",
]


def load_json(path: Path) -> object:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value: object) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def main() -> None:
    posts = load_json(POSTS_PATH)
    topic_data = load_json(TOPICS_PATH)
    category_data = load_json(CATEGORIES_PATH)
    tracker = load_json(TRACKER_PATH)
    if not all(isinstance(value, (list, dict)) for value in (posts, topic_data, category_data, tracker)):
        raise TypeError("Unexpected source JSON shape")
    if not isinstance(posts, list):
        raise TypeError("Post search index must be a list")

    posts_by_title: dict[str, list[dict[str, object]]] = {}
    for post in posts:
        posts_by_title.setdefault(str(post["title"]), []).append(post)

    missing_titles = [title for title in POST_TITLES if title not in posts_by_title]
    duplicate_titles = [title for title in POST_TITLES if len(posts_by_title.get(title, [])) != 1]
    if missing_titles:
        raise ValueError(f"Missing audited post titles: {missing_titles}")
    if duplicate_titles:
        raise ValueError(f"Audited titles are not unique: {duplicate_titles}")

    selected_posts = [posts_by_title[title][0] for title in POST_TITLES]
    if len(selected_posts) != 27:
        raise ValueError(f"Expected 27 audited posts, found {len(selected_posts)}")

    topic_records = topic_data["topics"]
    if any(record["name"] == TOPIC for record in topic_records):
        raise ValueError(f"Topic already exists: {TOPIC}")
    if any(TOPIC in post.get("topics", []) for post in posts):
        raise ValueError(f"One or more posts already use {TOPIC}")

    missing_keyword = [
        str(post["wpId"])
        for post in selected_posts
        if TOPIC not in post.get("secondaryKeywords", [])
    ]
    if missing_keyword:
        raise ValueError(f"Audited topic posts are missing the existing keyword: {missing_keyword}")

    for post in selected_posts:
        post["topics"] = list(dict.fromkeys([*post.get("topics", []), TOPIC]))
        post["secondaryKeywords"] = [
            keyword for keyword in post.get("secondaryKeywords", []) if keyword != TOPIC
        ]

    anchor = next(record for record in topic_records if record["name"] == "Jesus' Teachings")
    topic_records.insert(
        topic_records.index(anchor) + 1,
        {
            "name": TOPIC,
            "description": DESCRIPTION,
            "categories": [CATEGORY],
            "displayInBrowser": True,
        },
    )

    category = next(record for record in category_data["categories"] if record["name"] == CATEGORY)
    category["description"] = CATEGORY_DESCRIPTION
    if TOPIC in category["topicOrder"]:
        raise ValueError(f"Category order already contains {TOPIC}")
    anchor_index = category["topicOrder"].index("Jesus' Teachings")
    category["topicOrder"].insert(anchor_index + 1, TOPIC)

    actual = [post for post in posts if TOPIC in post.get("topics", [])]
    redundant = [
        post
        for post in posts
        if TOPIC in post.get("topics", []) and TOPIC in post.get("secondaryKeywords", [])
    ]
    if len(actual) != 27:
        raise ValueError(f"Unexpected {TOPIC} topic count: {len(actual)}")
    if redundant:
        raise ValueError("Topic posts retain the identically named secondary keyword")

    tracker_by_name = {entry["topic"]: entry for entry in tracker["topics"]}
    if TOPIC in tracker_by_name:
        raise ValueError(f"Tracker entry already exists: {TOPIC}")
    next_sequence = max(
        int(entry["auditSequence"])
        for entry in tracker["topics"]
        if entry.get("auditSequence") is not None
    ) + 1
    decisions = [
        {
            "wpId": str(post["wpId"]),
            "title": post["title"],
            "decision": "add",
            "confidence": "high",
            "reason": (
                "The full post treats the Kingdom of God, its arrival, or its implications as "
                "a primary or major sustained subject."
            ),
        }
        for post in selected_posts
    ]
    tracker_entry = {
        "topic": TOPIC,
        "auditSequence": next_sequence,
        "status": "completed",
        "postCountBefore": 0,
        "postCountAfter": 27,
        "descriptionBefore": DESCRIPTION,
        "descriptionRecommendation": DESCRIPTION,
        "categoriesBefore": [CATEGORY],
        "categoryRecommendation": "Retain current category placement.",
        "startedAt": AUDIT_DATE,
        "completedAt": AUDIT_DATE,
        "decisions": decisions,
        "notes": [
            "Created after a full-text review of 94 posts carrying the Kingdom of God keyword; "
            "27 treat the kingdom as a primary or major sustained subject.",
            "The identically named secondary keyword was removed from the 27 topic posts.",
        ],
    }
    ignore_index = next(
        (index for index, entry in enumerate(tracker["topics"]) if entry["topic"] == "Ignore"),
        len(tracker["topics"]),
    )
    tracker["topics"].insert(ignore_index, tracker_entry)
    tracker["updatedAt"] = AUDIT_DATE

    audit = {
        "auditDate": AUDIT_DATE,
        "topic": TOPIC,
        "category": CATEGORY,
        "description": DESCRIPTION,
        "reviewedKeywordPostCount": 94,
        "topicPostCount": 27,
        "sameNameKeywordsRemoved": 27,
        "decisions": decisions,
    }

    write_json(POSTS_PATH, posts)
    write_json(TOPICS_PATH, topic_data)
    write_json(CATEGORIES_PATH, category_data)
    write_json(TRACKER_PATH, tracker)
    write_json(AUDIT_PATH, audit)
    print(f"Created {TOPIC} with {len(actual)} posts in {CATEGORY}.")
    print(f"Removed {TOPIC} as a redundant secondary keyword from 27 topic posts.")


if __name__ == "__main__":
    main()
