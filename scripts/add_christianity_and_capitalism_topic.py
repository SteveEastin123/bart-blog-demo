from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
TOPICS_PATH = ROOT / "data" / "index" / "ehrman_post_topics.json"
CATEGORIES_PATH = ROOT / "data" / "index" / "ehrman_post_categories.json"

TOPIC_NAME = "Christianity and Capitalism"
TOPIC_DESCRIPTION = (
    "Examines capitalism in relation to Jesus' teachings, Christian ethics, "
    "Protestant theology, economic history, and modern interpretations of "
    "biblical traditions."
)
CATEGORY_NAME = "Wealth, Poverty, and Charity"
POST_IDS = {"49859", "49718", "49965", "49955", "50438", "50415", "50460"}


def write_json(path: Path, value: object) -> None:
    path.write_text(
        json.dumps(value, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )


def main() -> None:
    posts = json.loads(POSTS_PATH.read_text(encoding="utf-8"))
    topics_root = json.loads(TOPICS_PATH.read_text(encoding="utf-8"))
    categories_root = json.loads(CATEGORIES_PATH.read_text(encoding="utf-8"))

    posts_by_id = {str(post["wpId"]): post for post in posts}
    missing = sorted(POST_IDS - posts_by_id.keys(), key=int)
    if missing:
        raise RuntimeError(f"Missing expected posts: {missing}")

    topics = topics_root["topics"]
    if any(topic["name"] == TOPIC_NAME for topic in topics):
        raise RuntimeError(f"Topic already exists: {TOPIC_NAME}")
    topics.append(
        {
            "name": TOPIC_NAME,
            "description": TOPIC_DESCRIPTION,
            "categories": [CATEGORY_NAME],
            "displayInBrowser": True,
        }
    )
    topics.sort(key=lambda topic: topic["name"].casefold())

    for post_id in POST_IDS:
        post = posts_by_id[post_id]
        post_topics = post.setdefault("topics", [])
        if TOPIC_NAME not in post_topics:
            post_topics.append(TOPIC_NAME)

    category = next(
        item
        for item in categories_root["categories"]
        if item["name"] == CATEGORY_NAME
    )
    category["description"] = (
        "Covers charity, altruism, care for people in need, and ancient, "
        "biblical, early Christian, and modern Christian approaches to wealth, "
        "poverty, economic systems, giving, and care for the sick."
    )
    order = category["topicOrder"]
    if TOPIC_NAME not in order:
        insertion_point = order.index("Jesus on Wealth and Poverty") + 1
        order.insert(insertion_point, TOPIC_NAME)

    write_json(POSTS_PATH, posts)
    write_json(TOPICS_PATH, topics_root)
    write_json(CATEGORIES_PATH, categories_root)
    print(f"Added {TOPIC_NAME} to {len(POST_IDS)} posts and {CATEGORY_NAME}.")


if __name__ == "__main__":
    main()
