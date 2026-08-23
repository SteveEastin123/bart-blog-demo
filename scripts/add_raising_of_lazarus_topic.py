import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SEARCH_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
TOPICS_PATH = ROOT / "data" / "index" / "ehrman_post_topics.json"
CATEGORIES_PATH = ROOT / "data" / "index" / "ehrman_post_categories.json"
TRACKER_PATH = ROOT / "data" / "audits" / "ehrman_topic_audit_tracker.json"
AUDIT_PATH = ROOT / "data" / "audits" / "raising_of_lazarus_topic_audit_2026_08_23.json"

TOPIC = "Raising of Lazarus"
DESCRIPTION = (
    "Examines John's account of Jesus raising Lazarus, especially its "
    "comparison with the Synoptic raising of Jairus' daughter and what the "
    "story reveals about John's portrayal of Jesus' signs and identity."
)
CATEGORIES = [
    "Jesus' Birth, Family, and Miracles",
    "Miracles and Supernatural Claims",
]
POSTS = {
    "47096": "The raising of Lazarus is one of the post's two principal narratives, directly compared with the raising of Jairus' daughter.",
    "17818": "The post substantially compares the raising of Lazarus with the raising of Jairus' daughter to explain differences between John and the Synoptics.",
    "13201": "The post substantially compares the raising of Lazarus with the raising of Jairus' daughter to explain John's distinctive portrayal of Jesus' miracles.",
    "7051": "The post substantially compares the raising of Lazarus with the raising of Jairus' daughter in its analysis of John and the Synoptics.",
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
assert not any(TOPIC in post.get("topics", []) for post in search_data), (
    f"{TOPIC} is already assigned to a post."
)

for wp_id in POSTS:
    search_by_id[wp_id].setdefault("topics", []).append(TOPIC)
save(SEARCH_PATH, search_data)

topics_data = load(TOPICS_PATH)
assert not any(topic["name"] == TOPIC for topic in topics_data["topics"]), (
    f"{TOPIC} already exists."
)
topics_data["topics"].append(
    {
        "name": TOPIC,
        "description": DESCRIPTION,
        "categories": CATEGORIES,
        "displayInBrowser": True,
    }
)
topics_data["topics"].sort(key=lambda topic: topic["name"].casefold())
save(TOPICS_PATH, topics_data)

categories_data = load(CATEGORIES_PATH)
categories = {category["name"]: category for category in categories_data["categories"]}
for category_name in CATEGORIES:
    order = categories[category_name]["topicOrder"]
    order.insert(order.index("Jairus' Daughter") + 1, TOPIC)
save(CATEGORIES_PATH, categories_data)

audit = {
    "auditDate": "2026-08-23",
    "method": (
        "Conservative full-text review requiring the raising of Lazarus in "
        "John 11 to be a primary or major sustained subject of each post."
    ),
    "topic": {
        "name": TOPIC,
        "description": DESCRIPTION,
        "categories": CATEGORIES,
        "postCount": len(POSTS),
        "includedWpIds": list(POSTS),
        "notes": [
            "All four posts substantially compare the raising of Lazarus with the raising of Jairus' daughter.",
            "The posts are closely related versions of the same underlying discussion, but the subject is central in each.",
            "Posts about the rich man and Lazarus, Mary and Martha, Gospel authorship, or the Signs Source were excluded unless the raising itself was a major sustained subject.",
            "The broader Lazarus secondary keyword remains unchanged.",
        ],
    },
}
save(AUDIT_PATH, audit)

tracker = load(TRACKER_PATH)
assert not any(item["topic"] == TOPIC for item in tracker["topics"]), (
    f"{TOPIC} is already tracked."
)
tracker["updatedAt"] = "2026-08-23"
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
        "categoriesBefore": CATEGORIES,
        "categoryRecommendation": (
            "Place immediately after Jairus' Daughter under both Jesus' Birth, "
            "Family, and Miracles and Miracles and Supernatural Claims."
        ),
        "startedAt": "2026-08-23",
        "completedAt": "2026-08-23",
        "decisions": [
            {
                "wpId": wp_id,
                "title": search_by_id[wp_id]["title"],
                "decision": "add",
                "confidence": "high",
                "reason": reason,
            }
            for wp_id, reason in POSTS.items()
        ],
        "notes": audit["topic"]["notes"],
    }
)
save(TRACKER_PATH, tracker)

print(f"Added {TOPIC} to {len(POSTS)} posts and two categories.")
