import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SEARCH_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
TOPICS_PATH = ROOT / "data" / "index" / "ehrman_post_topics.json"
CATEGORIES_PATH = ROOT / "data" / "index" / "ehrman_post_categories.json"
TOPIC_TRACKER_PATH = ROOT / "data" / "audits" / "ehrman_topic_audit_tracker.json"
KEYWORD_TRACKER_PATH = ROOT / "data" / "audits" / "ehrman_secondary_keyword_audit_tracker.json"
AUDIT_PATH = ROOT / "data" / "audits" / "jairus_daughter_topic_audit_2026_08_23.json"

TOPIC = "Jairus' Daughter"
OLD_KEYWORD = "Jairus"
DESCRIPTION = (
    "Examines the Gospel accounts of Jesus raising Jairus' daughter, including "
    "differences between Mark and Matthew over when she dies, the Aramaic "
    "command \"Talitha cumi,\" oral transmission, and comparisons with the "
    "raising of Lazarus in John."
)

CORE_POSTS = {
    "47096": "The post directly compares the raising of Jairus' daughter in Mark with the raising of Lazarus in John.",
    "35436": "The story is the post's sustained test case for whether Jesus traditions were memorized and transmitted without significant change.",
    "31511": "The post devotes a major section to the wording, chronology, and narrative compression of the story in Mark and Matthew.",
    "31505": "The post devotes a major section to reconciling the different forms of the story in Mark and Matthew.",
    "31498": "The story is one of the post's principal examples in its debate over Gospel contradictions.",
    "17818": "The post substantially compares the raising of Jairus' daughter with the raising of Lazarus to explain differences between Mark and John.",
    "16923": "The story is a sustained example in the post's evaluation of whether narrative variation is compatible with biblical inerrancy.",
    "16165": "The story supplies the post's principal example in its discussion of attempts to reconcile Gospel contradictions.",
    "16151": "The story is a sustained case study in the post's discussion of evidence, bias, and Gospel contradictions.",
    "16149": "The story is the post's principal example in its reflection on bias and recognition of Gospel contradictions.",
    "16130": "The post gives sustained attention to reconciling the chronology and Greek wording of the story in Mark and Matthew.",
    "16109": "The post devotes a major section to the chronology, Greek grammar, and narrative forms of the story.",
    "16100": "The post gives sustained attention to attempts to reconcile the accounts in Mark, Matthew, and Luke.",
    "16088": "The post devotes a major section to the Greek wording and narrative differences in Mark and Matthew.",
    "16063": "The story is one of the post's principal cases, with sustained discussion of Greek wording and Matthew's narrative compression.",
    "16054": "The story is one of the post's principal examples in opening the debate over Gospel contradictions.",
    "13201": "The post substantially compares the raising of Jairus' daughter with the raising of Lazarus to explain the different portrayals of Jesus' miracles.",
    "7051": "The post substantially compares the raising of Jairus' daughter with the raising of Lazarus in John.",
}

SUPPORTING_POSTS = {
    "11992": "The story supports a broader argument that some Gospel traditions circulated in Aramaic.",
    "9516": "The story and the words Talitha cumi support a broader response about Aramaic expressions in Mark.",
    "4893": "The story appears as one substantial comparative exercise among many course assignments.",
    "4388": "The story is a meaningful example in a broader autobiographical discussion of discrepancies and changing beliefs.",
    "4071": "The story is a meaningful example in a broader autobiographical discussion of discrepancies and deconversion.",
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
expected_ids = set(CORE_POSTS) | set(SUPPORTING_POSTS)
assert expected_ids <= set(search_by_id), "A reviewed post is missing from the search index."

old_keyword_posts = {
    str(post["wpId"])
    for post in search_data
    if OLD_KEYWORD in post.get("secondaryKeywords", [])
}
assert old_keyword_posts == expected_ids, (
    f"Expected {len(expected_ids)} {OLD_KEYWORD} assignments, found "
    f"{len(old_keyword_posts)} with a different set of posts."
)

for wp_id in expected_ids:
    post = search_by_id[wp_id]
    keywords = post.setdefault("secondaryKeywords", [])
    post["secondaryKeywords"] = [
        TOPIC if keyword == OLD_KEYWORD else keyword for keyword in keywords
    ]

for wp_id in CORE_POSTS:
    post = search_by_id[wp_id]
    if TOPIC not in post.setdefault("topics", []):
        post["topics"].append(TOPIC)
    post["secondaryKeywords"] = [
        keyword for keyword in post["secondaryKeywords"] if keyword != TOPIC
    ]

save(SEARCH_PATH, search_data)

topics_data = load(TOPICS_PATH)
topics = topics_data["topics"]
assert not any(topic["name"] == TOPIC for topic in topics), f"{TOPIC} already exists."
topics.append(
    {
        "name": TOPIC,
        "description": DESCRIPTION,
        "categories": [
            "Jesus' Birth, Family, and Miracles",
            "Miracles and Supernatural Claims",
        ],
        "displayInBrowser": True,
    }
)
topics.sort(key=lambda topic: topic["name"].casefold())
save(TOPICS_PATH, topics_data)

categories_data = load(CATEGORIES_PATH)
categories = {category["name"]: category for category in categories_data["categories"]}
for category_name in (
    "Jesus' Birth, Family, and Miracles",
    "Miracles and Supernatural Claims",
):
    order = categories[category_name]["topicOrder"]
    order.insert(order.index("Jesus' Miracle Stories") + 1, TOPIC)
save(CATEGORIES_PATH, categories_data)

audit = {
    "auditDate": "2026-08-23",
    "method": "Conservative full-text review requiring the raising of Jairus' daughter or substantive differences among its Gospel forms to be a primary or major sustained subject.",
    "topic": {
        "name": TOPIC,
        "description": DESCRIPTION,
        "categories": [
            "Jesus' Birth, Family, and Miracles",
            "Miracles and Supernatural Claims",
        ],
        "postCount": len(CORE_POSTS),
        "includedWpIds": list(CORE_POSTS),
    },
    "keywordNormalization": {
        "from": OLD_KEYWORD,
        "to": TOPIC,
        "removedFromTopicPosts": len(CORE_POSTS),
        "retainedAsSupportingKeywordWpIds": list(SUPPORTING_POSTS),
        "broaderUnionPostCount": len(expected_ids),
    },
}
save(AUDIT_PATH, audit)

topic_tracker = load(TOPIC_TRACKER_PATH)
assert not any(item["topic"] == TOPIC for item in topic_tracker["topics"]), f"{TOPIC} is already tracked."
topic_tracker["updatedAt"] = "2026-08-23"
topic_tracker["topics"].append(
    {
        "topic": TOPIC,
        "auditSequence": max(
            item["auditSequence"]
            for item in topic_tracker["topics"]
            if isinstance(item.get("auditSequence"), int)
        )
        + 1,
        "status": "completed",
        "postCountBefore": 0,
        "postCountAfter": len(CORE_POSTS),
        "descriptionBefore": DESCRIPTION,
        "descriptionRecommendation": DESCRIPTION,
        "categoriesBefore": [
            "Jesus' Birth, Family, and Miracles",
            "Miracles and Supernatural Claims",
        ],
        "categoryRecommendation": "Place after Jesus' Miracle Stories in both linked categories because the topic is a specific canonical miracle tradition examined through historical, literary, and comparative questions.",
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
            for wp_id, reason in CORE_POSTS.items()
        ],
        "notes": [
            "Created after a conservative full-text review of references to Jairus, his daughter, Talitha cumi, Mark 5:21-43, and Matthew 9:18-26.",
            "Renamed the Jairus secondary keyword to Jairus' Daughter and removed it from the 18 posts receiving the identically named topic.",
            "Retained Jairus' Daughter as a supporting secondary keyword on five broader posts, producing a 23-post union when the keyword is selected.",
            "Existing category descriptions already cover miracle and healing traditions and required no changes.",
        ],
    }
)
save(TOPIC_TRACKER_PATH, topic_tracker)

keyword_tracker = load(KEYWORD_TRACKER_PATH)
keyword_entry = next(
    item for item in keyword_tracker["keywords"] if item["keyword"] == OLD_KEYWORD
)
keyword_entry["keyword"] = TOPIC
keyword_entry["postCount"] = len(SUPPORTING_POSTS)
keyword_entry["frequencyBand"] = "5-24"
if AUDIT_PATH.name not in keyword_entry["auditEvidence"]:
    keyword_entry["auditEvidence"].append(AUDIT_PATH.name)
keyword_tracker["updatedAt"] = "2026-08-23"
save(KEYWORD_TRACKER_PATH, keyword_tracker)

print(
    f"Added {TOPIC} to {len(CORE_POSTS)} posts; retained the renamed keyword "
    f"on {len(SUPPORTING_POSTS)} supporting posts."
)
