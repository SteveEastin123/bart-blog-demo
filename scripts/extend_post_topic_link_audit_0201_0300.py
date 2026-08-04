"""Record post-topic audit recommendations for posts 201-300.

This extends the audit tracker only. It does not alter canonical post topics,
descriptions, the standalone demo, or SQLite data.
"""

from __future__ import annotations

import json
from datetime import date
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
TOPICS_PATH = ROOT / "data" / "index" / "ehrman_post_topics.json"
TRACKER_PATH = ROOT / "data" / "audits" / "ehrman_post_topic_link_audit_tracker.json"

RECOMMENDATIONS = {
    "48066": {
        "add": ["Canon Formation", "Dating the Gospels"],
        "remove": [],
        "reason": (
            "The post gives substantial responses about the planned canon book and "
            "the dating of Luke-Acts, in addition to its existing subjects."
        ),
        "description": (
            "Responds to questions about anti-Judaism in 1 Thessalonians, the planned "
            "canon book, Luke's date, and whether Matthew could have written his Gospel."
        ),
    },
    "47869": {
        "add": ["Exaltation Christology", "Jesus' Resurrection Appearances"],
        "remove": [],
        "reason": (
            "Two of the post's four substantial responses explain the increasing "
            "exaltation of Jesus and the evidence that followers believed they saw "
            "him after his death."
        ),
    },
    "47847": {
        "add": ["Canon Formation"],
        "remove": [],
        "reason": (
            "A substantial part of the post explains why Revelation was accepted as "
            "apostolic scripture despite early doubts about its authorship."
        ),
        "description": (
            "Explains why Revelation's author was a Christian prophet named John "
            "rather than John the son of Zebedee, and how the book came to be accepted "
            "as apostolic scripture."
        ),
    },
    "47645": {
        "add": ["Jewish Apocalypticism"],
        "remove": [],
        "reason": (
            "The post presents the Book of the Watchers as an apocalypse centered on "
            "angelic rebellion, cosmic judgment, destruction, and salvation."
        ),
    },
    "47567": {
        "add": [
            "Translation Issues",
            "Virgin Birth",
            "Ancient Secretaries and Authorship",
        ],
        "remove": [],
        "reason": (
            "Each of the post's three questions receives sustained treatment: the "
            "meaning of Paraclete, Isaiah 7:14 and virgin-birth language, and whether "
            "professional scribes composed writings for illiterate authors."
        ),
    },
    "47565": {
        "add": ["Church Fathers as Textual Evidence"],
        "remove": [],
        "reason": (
            "One of the post's three substantial responses directly examines the "
            "reliability and transmission of scriptural quotations in church fathers."
        ),
    },
    "47007": {
        "add": [
            "Theologically Significant Variants",
            "Gnostic and Orthodox Conflicts",
        ],
        "remove": [],
        "reason": (
            "The post is organized around a theologically significant variant in "
            "1 John 4:3 and explains it through conflict between separationist "
            "Gnostics and emerging orthodox Christianity."
        ),
    },
    "47009": {
        "add": ["Gnostic and Orthodox Conflicts"],
        "remove": [],
        "reason": (
            "The overview devotes sustained attention to the secessionists' docetic "
            "Christology and the author's opposition to their claims."
        ),
    },
    "47002": {
        "add": ["Exaltation Christology", "Revelation Authorship"],
        "remove": [],
        "reason": (
            "Two substantial responses discuss Jesus as a human exalted to heaven "
            "and whether Revelation's John was claiming apostolic identity."
        ),
        "description": (
            "Responds to questions about Jesus' burial, pagan analogies to resurrection "
            "and exaltation, Revelation's authorship, and Jewish messianic expectations."
        ),
    },
    "46992": {
        "add": ["Courses and Teaching"],
        "remove": ["Free Will and Predestination", "Free Will Explanations of Suffering"],
        "reason": (
            "The post primarily presents a course and its syllabus. Free will is one "
            "component of that course rather than a sustained subject of the post, "
            "while Courses and Teaching directly describes its purpose."
        ),
    },
    "46972": {
        "add": [],
        "remove": ["Biblical Explanations of Suffering"],
        "reason": (
            "This is an administrative webinar announcement and does not itself "
            "provide substantive discussion of biblical explanations for suffering."
        ),
    },
    "46944": {
        "add": ["Non-Pauline Epistle Authorship", "Non-Pauline Epistle Forgeries"],
        "remove": [],
        "reason": (
            "The post centrally asks whether James the brother of Jesus wrote the "
            "letter and introduces the case that the work was forged in his name."
        ),
    },
}


def load_json(path: Path) -> object:
    return json.loads(path.read_text(encoding="utf-8"))


def main() -> None:
    posts = load_json(POSTS_PATH)
    topics_data = load_json(TOPICS_PATH)
    tracker = load_json(TRACKER_PATH)
    if not isinstance(posts, list):
        raise TypeError("Unexpected post index shape")
    if not isinstance(topics_data, dict) or not isinstance(tracker, dict):
        raise TypeError("Unexpected topic or tracker shape")
    if len(tracker.get("posts", [])) != 200:
        raise ValueError("Tracker must contain exactly the completed first 200 posts")

    valid_topics = {topic["name"] for topic in topics_data["topics"]}
    entries = list(tracker["posts"])
    for source_index, post in enumerate(posts[200:300], start=200):
        sequence = source_index + 1
        wp_id = str(post["wpId"])
        original = list(post.get("topics", []))
        recommendation = RECOMMENDATIONS.get(
            wp_id, {"add": [], "remove": [], "reason": None}
        )
        added = list(recommendation["add"])
        removed = list(recommendation["remove"])

        unknown = (set(original) | set(added) | set(removed)) - valid_topics
        if unknown:
            raise ValueError(f"Unknown topics for {wp_id}: {sorted(unknown)}")
        if not set(removed).issubset(original):
            raise ValueError(f"Cannot remove absent topic from {wp_id}: {removed}")
        if set(added) & set(original):
            raise ValueError(f"Cannot add existing topic to {wp_id}: {added}")

        recommended = [topic for topic in original if topic not in removed]
        recommended.extend(topic for topic in added if topic not in recommended)
        changed = bool(added or removed)
        entry = {
            "auditSequence": sequence,
            "sourceIndex": source_index,
            "wpId": wp_id,
            "dateText": post.get("dateText"),
            "title": post["title"],
            "status": "pending_approval" if changed else "reviewed_no_change",
            "topicsBefore": original,
            "topicsRecommended": recommended,
            "topicsAdded": added,
            "topicsRemoved": removed,
            "reason": recommendation["reason"],
        }
        description = recommendation.get("description")
        if description is not None:
            entry["descriptionBefore"] = post.get("description")
            entry["descriptionRecommended"] = description
        entries.append(entry)

    expected_recommendations = set(RECOMMENDATIONS)
    recorded_recommendations = {
        entry["wpId"] for entry in entries[200:] if entry["status"] == "pending_approval"
    }
    if recorded_recommendations != expected_recommendations:
        raise ValueError(
            "Recommendation mismatch: "
            f"expected {sorted(expected_recommendations)}, "
            f"found {sorted(recorded_recommendations)}"
        )

    tracker["posts"] = entries
    tracker.update(
        {
            "updatedAt": date.today().isoformat(),
            "auditScope": (
                "First 300 canonical search-index posts in current newest-first order"
            ),
            "reviewedPostCount": len(entries),
            "noChangeCount": sum(
                entry["status"] == "reviewed_no_change" for entry in entries
            ),
            "pendingApprovalCount": sum(
                entry["status"] == "pending_approval" for entry in entries
            ),
            "appliedChangeCount": sum(entry["status"] == "applied" for entry in entries),
        }
    )
    TRACKER_PATH.write_text(
        json.dumps(tracker, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    print(
        f"Recorded {len(entries)} reviewed posts: "
        f"{tracker['noChangeCount']} no change, "
        f"{tracker['appliedChangeCount']} applied, "
        f"{tracker['pendingApprovalCount']} pending approval."
    )


if __name__ == "__main__":
    main()
