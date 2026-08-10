"""Add the August 4-9, 2026 downloads to the canonical search index."""

from __future__ import annotations

import json
from collections import Counter
from datetime import date, datetime
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
RAW_PATH = ROOT / "data" / "raw" / "posts.jsonl"
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
TOPICS_PATH = ROOT / "data" / "index" / "ehrman_post_topics.json"
POST_TRACKER_PATH = (
    ROOT / "data" / "audits" / "ehrman_post_topic_link_audit_tracker.json"
)
TOPIC_TRACKER_PATH = ROOT / "data" / "audits" / "ehrman_topic_audit_tracker.json"


ASSIGNMENTS = {
    "50317": {
        "description": (
            "Reconstructs the historical core behind the triumphal-entry tradition, "
            "arguing that Jesus expected to rule God's coming kingdom but was "
            "crucified because the authorities mistook his apocalyptic messianic "
            "claim for insurrection."
        ),
        "topics": [
            "Apocalyptic Jesus",
            "Gospel Historical Reliability",
            "Jesus' Passion Narratives",
            "Messiah",
        ],
        "secondaryKeywords": [
            "Triumphal Entry",
            "Passover",
            "Pontius Pilate",
            "Jerusalem",
            "King of the Jews",
            "Kingdom of God",
            "Son of Man",
            "Roman Empire",
            "Psalms of Solomon",
            "Judas Iscariot",
        ],
    },
    "50331": {
        "description": (
            "Examines two Gnostic accounts in which Christ escaped crucifixion: "
            "Basilides's identity-switch story involving Simon of Cyrene and the "
            "Coptic Apocalypse of Peter's claim that only Jesus's physical form "
            "suffered."
        ),
        "topics": [
            "Apocalypse of Peter",
            "Crucifixion of Jesus",
            "Gnostic and Orthodox Conflicts",
            "Gnosticism (General)",
        ],
        "secondaryKeywords": [
            "Basilides",
            "Simon of Cyrene",
            "Irenaeus",
            "Coptic Apocalypse of Peter",
            "Nag Hammadi Library",
            "Docetism",
            "Separationism",
            "Proto-Orthodoxy",
            "Salvation",
        ],
    },
    "50329": {
        "description": (
            "Interprets 'Render unto Caesar' as evidence that Jesus's apocalyptic "
            "expectation made him indifferent to Roman government and economic "
            "systems: Caesar could have his coin, while people owed their lives to "
            "God."
        ),
        "topics": [
            "Apocalyptic Jesus",
            "Gospel Historical Reliability",
            "Jesus' Teachings",
        ],
        "secondaryKeywords": [
            "Render unto Caesar",
            "Taxation",
            "Tiberius",
            "Denarius",
            "Roman Empire",
            "Gospel of Thomas",
            "Kingdom of God",
            "Son of Man",
            "Image of God",
            "Pacifism",
        ],
    },
    "50347": {
        "description": (
            "Argues that the Gospels greatly exaggerate Jesus's Temple action: one man "
            "could not have halted sacrifices amid Passover crowds and Roman security, "
            "but a smaller protest likely drew the authorities' attention."
        ),
        "topics": [
            "Gospel Historical Reliability",
            "Jesus' Passion Narratives",
        ],
        "secondaryKeywords": [
            "Cleansing of the Temple",
            "Jerusalem Temple",
            "Passover",
            "Sacrifice",
            "Money Changers",
            "Pontius Pilate",
            "Roman Empire",
            "Gospel of Mark",
            "Josephus",
            "Temple Authorities",
        ],
    },
    "50364": {
        "description": (
            "Places Jesus's Temple protest within the Jewish prophetic tradition, "
            "showing how Isaiah, Amos, Micah, and Jeremiah condemned sacrifice without "
            "justice and distinguishing this internal Jewish critique from later "
            "Christian anti-Judaism."
        ),
        "topics": [
            "Christian Anti-Judaism",
            "Hebrew Bible Prophets",
            "Jesus' Ethics",
        ],
        "secondaryKeywords": [
            "Isaiah",
            "Amos",
            "Micah",
            "Jeremiah",
            "Jerusalem Temple",
            "Sacrifice",
            "Social Justice",
            "John the Baptist",
            "Essenes",
            "Holocaust",
        ],
    },
}


def load_json(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))


def load_jsonl(path: Path) -> list[dict]:
    return [
        json.loads(line)
        for line in path.read_text(encoding="utf-8").splitlines()
        if line.strip()
    ]


def write_json(path: Path, value) -> None:
    path.write_text(
        json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )


def post_date(post: dict) -> datetime:
    return datetime.strptime(post["dateText"], "%B %d, %Y")


def main() -> None:
    raw_posts = load_jsonl(RAW_PATH)
    posts = load_json(POSTS_PATH)
    topics_data = load_json(TOPICS_PATH)
    post_tracker = load_json(POST_TRACKER_PATH)
    topic_tracker = load_json(TOPIC_TRACKER_PATH)

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
        if len(assignment["secondaryKeywords"]) != len(
            set(assignment["secondaryKeywords"])
        ):
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
            f"Posts already indexed; IDs={sorted(duplicate_ids)}, "
            f"URLs={sorted(duplicate_urls)}"
        )

    additions.sort(key=post_date, reverse=True)
    updated_posts = additions + posts
    if len({str(post.get("wpId")) for post in updated_posts}) != len(updated_posts):
        raise ValueError("Duplicate wpId after update")
    if len({post.get("url") for post in updated_posts}) != len(updated_posts):
        raise ValueError("Duplicate URL after update")

    tracked_ids = {str(entry["wpId"]) for entry in post_tracker["posts"]}
    if set(ASSIGNMENTS) & tracked_ids:
        raise ValueError(
            "One or more downloaded posts are already in the audit tracker"
        )
    next_sequence = max(entry["auditSequence"] for entry in post_tracker["posts"]) + 1
    source_indexes = {
        str(post["wpId"]): index for index, post in enumerate(updated_posts)
    }
    for offset, post in enumerate(additions):
        post_tracker["posts"].append(
            {
                "auditSequence": next_sequence + offset,
                "sourceIndex": source_indexes[post["wpId"]],
                "wpId": post["wpId"],
                "dateText": post["dateText"],
                "title": post["title"],
                "status": "reviewed_no_change",
                "topicsBefore": list(post["topics"]),
                "topicsRecommended": list(post["topics"]),
                "topicsAdded": [],
                "topicsRemoved": [],
                "reason": None,
            }
        )

    today = date.today().isoformat()
    post_tracker.update(
        {
            "updatedAt": today,
            "auditScope": (
                f"All {len(updated_posts)} posts in the canonical search index"
            ),
            "reviewedPostCount": len(post_tracker["posts"]),
            "noChangeCount": sum(
                entry["status"] == "reviewed_no_change"
                for entry in post_tracker["posts"]
            ),
            "pendingApprovalCount": sum(
                entry["status"] == "pending_approval"
                for entry in post_tracker["posts"]
            ),
        }
    )

    topic_counts = Counter(
        topic for post in updated_posts for topic in post.get("topics", [])
    )
    topic_entries = {entry["topic"]: entry for entry in topic_tracker["topics"]}
    audit_note = "Newly downloaded August 2026 posts were reviewed in full."
    for post in additions:
        for topic in post["topics"]:
            entry = topic_entries[topic]
            entry["postCountAfter"] = topic_counts[topic]
            decisions = [
                decision
                for decision in entry.get("decisions", [])
                if str(decision["wpId"]) != post["wpId"]
            ]
            decisions.append(
                {
                    "wpId": post["wpId"],
                    "title": post["title"],
                    "decision": "add",
                    "confidence": "high",
                    "reason": (
                        "The newly downloaded post was reviewed in full and treats "
                        "this topic as a primary or sustained subject."
                    ),
                }
            )
            entry["decisions"] = decisions
            notes = list(entry.get("notes", []))
            if audit_note not in notes:
                notes.append(audit_note)
            entry["notes"] = notes
    topic_tracker["updatedAt"] = today

    write_json(POSTS_PATH, updated_posts)
    write_json(POST_TRACKER_PATH, post_tracker)
    write_json(TOPIC_TRACKER_PATH, topic_tracker)
    print(
        f"Added and audited {len(additions)} posts; canonical search index now has "
        f"{len(updated_posts)} posts."
    )


if __name__ == "__main__":
    main()
