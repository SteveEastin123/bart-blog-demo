import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SEARCH_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
TOPICS_PATH = ROOT / "data" / "index" / "ehrman_post_topics.json"
CATEGORIES_PATH = ROOT / "data" / "index" / "ehrman_post_categories.json"
TRACKER_PATH = ROOT / "data" / "audits" / "ehrman_topic_audit_tracker.json"
AUDIT_PATH = ROOT / "data" / "audits" / "sermon_on_the_mount_topic_audit_2026_08_22.json"

TOPIC = "Sermon on the Mount"
DESCRIPTION = (
    "Examines Matthew's Sermon on the Mount, including its composition, "
    "relationship to Luke's Sermon on the Plain, the Beatitudes, the Golden "
    "Rule, and its interpretation of Jewish law and ethics."
)
POSTS = {
    "33196": (
        "Did Jesus Give the Sermon on the Mount?",
        "The post directly examines whether Jesus delivered the sermon as Matthew presents it and compares Matthew's collection with Luke's Sermon on the Plain.",
    ),
    "33124": (
        "Little Known Aspects of The Golden Rule as Found in the Sermon on the Mount",
        "The post is devoted to the Golden Rule within Matthew's sermon and explains the sermon's role in Matthew's presentation of Jesus as a new Moses.",
    ),
    "33221": (
        "Is it Possible Jesus Didn’t Teach the Golden Rule?",
        "The post substantially examines the Golden Rule in Matthew and Luke and whether the saying originated with Jesus.",
    ),
    "20822": (
        "How Do We Interpret the Beatitudes?  Guest Post by Julius-Kei Kato",
        "The post is devoted to the Beatitudes, their forms in Matthew's Sermon on the Mount and Luke's Sermon on the Plain, and their apocalyptic interpretation.",
    ),
    "4428": (
        "The Jewish Emphases of Matthew: Part 2",
        "The post substantially analyzes the Sermon on the Mount's treatment of Jewish law, especially Matthew 5:17-48 and the antitheses.",
    ),
    "4455": (
        "The Jewish Emphases of Matthew’s Gospel: Part 3",
        "The post uses the Golden Rule and related Matthean teachings to explain the purpose of Jewish law in Matthew's Gospel.",
    ),
}


def load(path):
    return json.loads(path.read_text(encoding="utf-8"))


def save(path, data):
    path.write_text(
        json.dumps(data, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )


search_data = load(SEARCH_PATH)
search_by_id = {str(post["wpId"]): post for post in search_data}
assert set(POSTS) <= set(search_by_id), "A reviewed post is missing from the search index."

for wp_id in POSTS:
    topics = search_by_id[wp_id].setdefault("topics", [])
    if TOPIC not in topics:
        topics.append(TOPIC)

search_by_id["4428"]["description"] = (
    "Explains how Matthew's Sermon on the Mount intensifies Jewish law by applying its underlying intent to anger, lust, retaliation, and other conduct."
)
search_by_id["4455"]["description"] = (
    "Shows how Matthew's Golden Rule and love commandments present love as the central purpose of Jewish law."
)
save(SEARCH_PATH, search_data)

topics_data = load(TOPICS_PATH)
topics = topics_data["topics"]
topic_record = {
    "name": TOPIC,
    "description": DESCRIPTION,
    "categories": [
        "Canonical Gospels and Acts",
        "Jesus' Teachings and Social World",
    ],
    "displayInBrowser": True,
}
existing_topic = next((topic for topic in topics if topic["name"] == TOPIC), None)
if existing_topic is None:
    topics.append(topic_record)
else:
    existing_topic.update(topic_record)
topics.sort(key=lambda topic: topic["name"].casefold())
save(TOPICS_PATH, topics_data)

categories_data = load(CATEGORIES_PATH)
categories = {category["name"]: category for category in categories_data["categories"]}
canonical = categories["Canonical Gospels and Acts"]
canonical["description"] = (
    "Covers the canonical Gospels and Acts, including their authorship, dating, "
    "sources, historical reliability, Synoptic relationships, accounts of "
    "Jesus' baptism, the Sermon on the Mount, distinctive literary features, "
    "and Acts as Luke's account of early Christianity."
)
canonical_order = canonical["topicOrder"]
canonical_order.remove(TOPIC) if TOPIC in canonical_order else None
canonical_order.insert(canonical_order.index("Gospel of Matthew") + 1, TOPIC)

teachings = categories["Jesus' Teachings and Social World"]
teachings["description"] = (
    "Covers Jesus' teachings, including the Sermon on the Mount, proclamation "
    "of God's kingdom, ethical vision, economic setting, views on wealth and "
    "poverty, and traditions involving women in his life and Gospel portrayals."
)
teachings_order = teachings["topicOrder"]
teachings_order.remove(TOPIC) if TOPIC in teachings_order else None
teachings_order.insert(teachings_order.index("Jesus' Teachings") + 1, TOPIC)
save(CATEGORIES_PATH, categories_data)

excluded = [
    {
        "wpId": "14982",
        "reason": "The Golden Rule is central, but the post's controlling question concerns the Didache's wording and use of Matthew rather than the Sermon on the Mount.",
    },
    {
        "wpId": "13927",
        "reason": "The Lord's Prayer is central, but the post focuses on Pope Francis's translation proposal rather than the sermon as a literary or teaching unit.",
    },
    {
        "wpId": "29786",
        "reason": "The sermon supplies several examples, but the post evaluates the practicality of Jesus' teachings across a wider range of Gospel traditions.",
    },
    {
        "wpId": "28939",
        "reason": "The sermon supplies several examples, but the post evaluates the practicality of Jesus' teachings across a wider range of Gospel traditions.",
    },
    {
        "wpId": "8613",
        "reason": "The post compares Matthew and Paul on Jewish law; the Sermon on the Mount provides supporting evidence but is not its dominant subject.",
    },
    {
        "wpId": "47108",
        "reason": "The sermon is one lecture in a broader overview of an eight-lecture course on the Gospel of Matthew.",
    },
]

audit = {
    "auditDate": "2026-08-22",
    "method": "Conservative full-text review requiring the Sermon on the Mount or one of its defining sections to be a primary or major sustained subject of each post.",
    "topic": {
        "name": TOPIC,
        "description": DESCRIPTION,
        "categories": [
            "Canonical Gospels and Acts",
            "Jesus' Teachings and Social World",
        ],
        "postCount": len(POSTS),
        "includedWpIds": list(POSTS),
        "excludedNearMisses": excluded,
    },
}
save(AUDIT_PATH, audit)

tracker = load(TRACKER_PATH)
assert not any(item["topic"] == TOPIC for item in tracker["topics"]), f"{TOPIC} is already tracked."
tracker["updatedAt"] = "2026-08-22"
tracker["topics"].append(
    {
        "topic": TOPIC,
        "auditSequence": max(
            item["auditSequence"]
            for item in tracker["topics"]
            if isinstance(item.get("auditSequence"), int)
        )
        + 1,
        "status": "completed",
        "postCountBefore": 0,
        "postCountAfter": len(POSTS),
        "descriptionBefore": DESCRIPTION,
        "descriptionRecommendation": DESCRIPTION,
        "categoriesBefore": [
            "Canonical Gospels and Acts",
            "Jesus' Teachings and Social World",
        ],
        "categoryRecommendation": "Place under both Canonical Gospels and Acts and Jesus' Teachings and Social World because the qualified posts examine the sermon as both a Matthean literary unit and a major collection of Jesus' teachings.",
        "startedAt": "2026-08-22",
        "completedAt": "2026-08-22",
        "decisions": [
            {
                "wpId": wp_id,
                "title": title,
                "decision": "add",
                "confidence": "high",
                "reason": reason,
            }
            for wp_id, (title, reason) in POSTS.items()
        ],
        "notes": [
            "Created after a conservative full-text review of posts mentioning the Sermon on the Mount, Beatitudes, Golden Rule, Sermon on the Plain, or related passages.",
            "Excluded posts that merely quote one saying from the sermon or use it as supporting evidence for another subject.",
            "Updated both linked category descriptions and clarified the descriptions of wpIds 4428 and 4455.",
        ],
    }
)
save(TRACKER_PATH, tracker)

print(f"Added {TOPIC} to {len(POSTS)} posts and two categories.")
