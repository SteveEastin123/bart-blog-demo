"""Add the July 26-August 3, 2026 downloads to the canonical search index."""

from __future__ import annotations

import json
from datetime import datetime
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
RAW_PATH = ROOT / "data" / "raw" / "posts.jsonl"
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
TOPICS_PATH = ROOT / "data" / "index" / "ehrman_post_topics.json"


ASSIGNMENTS = {
    "50319": {
        "description": (
            "Argues that the Gospel accounts of Jesus' triumphal entry are historically "
            "implausible because Roman authorities would have suppressed a public messianic "
            "acclamation during Passover, while allowing that Jesus did enter Jerusalem before "
            "his arrest."
        ),
        "topics": [
            "Gospel Historical Reliability",
            "Jesus' Passion Narratives",
            "Messiah",
        ],
        "secondaryKeywords": [
            "Triumphal Entry",
            "Passover",
            "Pontius Pilate",
            "Roman Empire",
            "Jerusalem",
            "Exodus",
            "Gospels",
            "Jesus Before the Gospels",
        ],
    },
    "50263": {
        "description": (
            "Announces a Swedish podcast interview about Love Thy Stranger in which Bart "
            "discusses the book's principal arguments and supporting evidence."
        ),
        "topics": ["Media Interviews and Videos"],
        "secondaryKeywords": ["Love Thy Stranger", "Swedish Podcast"],
    },
    "50290": {
        "description": (
            "Explains that religion and politics were inseparable in the Roman world and Judea, "
            "providing the setting for Jesus' opposition to Roman and Jewish authorities and his "
            "expectation that God would replace their systems."
        ),
        "topics": ["Apocalyptic Jesus", "Roman World", "Early Judaism (General)"],
        "secondaryKeywords": [
            "Religion and Politics",
            "Roman Empire",
            "Roman Religion",
            "Emperor Worship",
            "Pontifex Maximus",
            "Jerusalem Temple",
            "Sanhedrin",
            "Jewish Priesthood",
            "Second Temple Judaism",
            "Kingdom of God",
        ],
    },
    "50288": {
        "description": (
            "Explores Jesus' refusal to pursue political reform, arguing that his apocalyptic "
            "expectation of God's imminent kingdom led him to oppose unjust authorities while "
            "focusing on repentance and care for those who suffered."
        ),
        "topics": ["Apocalyptic Jesus", "Jesus' Teachings", "Jesus' Ethics"],
        "secondaryKeywords": [
            "Political Activism",
            "Political Reform",
            "Kingdom of God",
            "Theocracy",
            "Roman Empire",
            "Taxation",
            "Temple Authorities",
            "Messiah",
            "Care for the Poor",
            "Jewish Apocalypticism",
        ],
    },
    "50273": {
        "description": (
            "Argues that Jesus opposed armed resistance to Rome and expected God to overthrow "
            "existing powers, then evaluates the Gospel story of a disciple using a sword at "
            "Jesus' arrest as a likely distorted memory."
        ),
        "topics": [
            "Zealot Hypothesis",
            "Apocalyptic Jesus",
            "Jesus' Teachings",
            "Gospel Historical Reliability",
        ],
        "secondaryKeywords": [
            "Pacifism",
            "Nonviolence",
            "Armed Resistance",
            "Gethsemane",
            "Peter",
            "Reza Aslan",
            "Jesus Before the Gospels",
            "Roman Empire",
            "Kingdom of God",
            "Reimarus",
        ],
    },
}


def load_json(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))


def load_jsonl(path: Path) -> list[dict]:
    return [json.loads(line) for line in path.read_text(encoding="utf-8").splitlines() if line.strip()]


def post_date(post: dict) -> datetime:
    return datetime.strptime(post["dateText"], "%B %d, %Y")


def main() -> None:
    raw_posts = load_jsonl(RAW_PATH)
    posts = load_json(POSTS_PATH)
    topics_data = load_json(TOPICS_PATH)
    valid_topics = {topic["name"] for topic in topics_data["topics"]}
    raw_by_id = {str(post.get("wpId")): post for post in raw_posts}

    missing = set(ASSIGNMENTS) - set(raw_by_id)
    if missing:
        raise ValueError(f"Missing downloaded posts: {sorted(missing)}")

    additions = []
    for wp_id, assignment in ASSIGNMENTS.items():
        raw = raw_by_id[wp_id]
        unknown_topics = set(assignment["topics"]) - valid_topics
        if unknown_topics:
            raise ValueError(f"Unknown topics for {wp_id}: {sorted(unknown_topics)}")
        if len(assignment["topics"]) != len(set(assignment["topics"])):
            raise ValueError(f"Duplicate topics for {wp_id}")
        if len(assignment["secondaryKeywords"]) != len(set(assignment["secondaryKeywords"])):
            raise ValueError(f"Duplicate secondary keywords for {wp_id}")
        overlap = set(assignment["topics"]) & set(assignment["secondaryKeywords"])
        if overlap:
            raise ValueError(f"Topic-keyword overlap for {wp_id}: {sorted(overlap)}")
        additions.append(
            {
                "wpId": wp_id,
                "title": raw["title"],
                "url": raw["url"],
                "dateText": raw["dateText"],
                "author": raw["author"],
                **assignment,
            }
        )

    existing_ids = {str(post.get("wpId")) for post in posts}
    existing_urls = {post.get("url") for post in posts}
    duplicate_ids = set(ASSIGNMENTS) & existing_ids
    duplicate_urls = {post["url"] for post in additions} & existing_urls
    if duplicate_ids or duplicate_urls:
        raise ValueError(
            f"Posts already indexed; IDs={sorted(duplicate_ids)}, URLs={sorted(duplicate_urls)}"
        )

    additions.sort(key=post_date, reverse=True)
    updated = additions + posts
    if len({str(post.get("wpId")) for post in updated}) != len(updated):
        raise ValueError("Duplicate wpId after update")
    if len({post.get("url") for post in updated}) != len(updated):
        raise ValueError("Duplicate URL after update")

    POSTS_PATH.write_text(
        json.dumps(updated, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    print(f"Added {len(additions)} posts; canonical search index now has {len(updated)} posts.")


if __name__ == "__main__":
    main()
