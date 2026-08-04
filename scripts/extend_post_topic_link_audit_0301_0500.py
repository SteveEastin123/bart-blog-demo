"""Record post-topic audit recommendations for posts 301-500.

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
    "46927": {
        "add": ["Christian Anti-Judaism"],
        "remove": [],
        "reason": (
            "The overview devotes sustained attention to Hebrews' claim that "
            "Christianity supersedes Judaism, its covenant, priesthood, sacrifices, "
            "and scripture."
        ),
    },
    "47283": {
        "add": ["Afterlife Journeys"],
        "remove": [],
        "reason": (
            "The post introduces the Apocalypse of Paul and centers on Paul's guided "
            "journey through heaven and hell."
        ),
    },
    "47274": {
        "add": ["Translation Issues"],
        "remove": [],
        "reason": (
            "The post centrally examines how the Greek word doulos should be "
            "translated and how translation choices represent ancient slavery."
        ),
    },
    "47258": {
        "add": ["Hebrew Bible Composition and Sources"],
        "remove": [],
        "reason": (
            "A substantial part of the post explains the Documentary Hypothesis, "
            "the Pentateuchal sources J, E, D, and P, their dating, and modern "
            "revisions to the theory."
        ),
        "description": (
            "Announces a revised Bible textbook and Hebrew Bible course, then "
            "explains the Documentary Hypothesis and modern views of Pentateuchal "
            "sources."
        ),
    },
    "47255": {
        "add": ["Pauline Authorship"],
        "remove": [],
        "reason": (
            "The post argues that the Pastorals' later church structure and "
            "proto-orthodox setting show that they were written after Paul."
        ),
    },
    "47231": {
        "add": ["Synoptic Problem"],
        "remove": [],
        "reason": (
            "One of the post's three substantial questions examines the minor "
            "agreements between Matthew and Luke and whether Luke copied Matthew."
        ),
        "description": (
            "Responds to reader questions about women's head coverings in "
            "1 Corinthians, missing lines in P46, and minor agreements among the "
            "Synoptic Gospels."
        ),
    },
    "47141": {
        "add": [],
        "remove": ["Conversion"],
        "reason": (
            "The title's 'conversions' are historical transformations of Christianity, "
            "not conversions of people to the faith; the post instead centers on "
            "Christian diversity, historiography, persecution, and imperial change."
        ),
    },
    "47136": {
        "add": ["Courses and Teaching"],
        "remove": [],
        "reason": (
            "The post announces and substantively previews a two-lecture course on "
            "the disciples' doubt and resurrection narratives."
        ),
    },
    "47109": {
        "add": ["Son of Man", "Translation Issues"],
        "remove": [],
        "reason": (
            "In addition to atonement, the post gives substantial responses on "
            "Jesus' Son of Man sayings and the translation of 'Sabbaths' in the "
            "resurrection narratives."
        ),
        "description": (
            "Responds to questions about atonement in Matthew and John, Jesus' Son "
            "of Man sayings, and translating 'Sabbaths' in the resurrection narratives."
        ),
    },
    "47084": {
        "add": ["Gospel of Luke"],
        "remove": [],
        "reason": (
            "Luke and its proposed L source receive the same sustained attention as "
            "Matthew and its proposed M source."
        ),
    },
    "47072": {
        "add": ["Synoptic Problem"],
        "remove": [],
        "reason": (
            "The post uses Matthew's rewriting of Mark to explain and defend Markan "
            "priority, a central issue in the Synoptic Problem."
        ),
    },
    "47064": {
        "add": ["Christian Anti-Judaism"],
        "remove": [],
        "reason": (
            "The entire post examines Luke's anti-Jewish portrayal and its historical "
            "setting."
        ),
    },
    "47059": {
        "add": ["Gospel Authorship"],
        "remove": [],
        "reason": (
            "A major part of the post examines Mark's anonymity, the later attribution "
            "to Mark, and the traditional connection with Peter."
        ),
    },
    "47047": {
        "add": ["Gospel Authorship"],
        "remove": [],
        "reason": (
            "A major part of the post examines Matthew's anonymity, later attribution, "
            "literacy, and why the disciple probably did not write the Gospel."
        ),
    },
    "41916": {
        "add": ["Gospel of Jesus' Wife Fragment", "Proto-Gospel of James"],
        "remove": [],
        "reason": (
            "The two principal non-canonical texts discussed are the Gospel of Jesus' "
            "Wife fragment and the Proto-Gospel of James, whose infancy traditions "
            "receive sustained treatment."
        ),
        "description": (
            "Reposts the first part of a Newsweek article on the Gospel of Jesus' "
            "Wife, the Proto-Gospel of James, and non-canonical traditions about "
            "Jesus' birth."
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
    if len(tracker.get("posts", [])) not in {300, 500}:
        raise ValueError("Tracker must contain the first 300 or 500 posts")

    valid_topics = {topic["name"] for topic in topics_data["topics"]}
    entries = list(tracker["posts"][:300])
    for source_index, post in enumerate(posts[300:500], start=300):
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
        entry["wpId"] for entry in entries[300:] if entry["status"] == "pending_approval"
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
                "First 500 canonical search-index posts in current newest-first order"
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
