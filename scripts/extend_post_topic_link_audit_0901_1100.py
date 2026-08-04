"""Record post-topic audit recommendations for posts 901-1100.

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
    "35702": {
        "add": ["Problem of Evil and Suffering"],
        "remove": [],
        "reason": (
            "Most of the post develops a broad argument about why suffering exists "
            "and whether it bears on God's existence, in addition to its section on Job."
        ),
    },
    "36829": {
        "add": ["Jewish Christian Gospels"],
        "remove": [],
        "reason": (
            "The post is a sustained analysis of the Gospel of the Ebionites and "
            "its Jewish-Christian orientation."
        ),
    },
    "36687": {
        "add": ["Paul's Life and Career"],
        "remove": [],
        "reason": (
            "The retelling extends from Paul's persecution and conversion through "
            "his mission, conflicts, and relationship with Jerusalem leaders."
        ),
    },
    "36824": {
        "add": ["Jewish Christian Gospels"],
        "remove": [],
        "reason": (
            "The post's central evidence and interpretation concern alterations in "
            "the Gospel of the Ebionites, not merely John the Baptist."
        ),
    },
    "35689": {
        "add": ["Paul's Life and Career"],
        "remove": [],
        "reason": (
            "A substantial portion explores Paul's motivations, travel, relations "
            "with James, and historical influence beyond the style of his letters."
        ),
    },
    "36803": {
        "add": [],
        "remove": ["Apocryphal Acts"],
        "reason": (
            "The syllabus surveys apocryphal gospels, epistles, acts, and apocalypses; "
            "Apocryphal Acts is only one segment, while the broader existing topics fit."
        ),
    },
    "36704": {
        "add": ["Mythicism", "Burial of Jesus", "Resurrection of Jesus"],
        "remove": [],
        "reason": (
            "The post devotes major sections to the mythicist argument from silence "
            "and to the logic connecting Jesus' burial with resurrection claims."
        ),
    },
    "36569": {
        "add": ["Gospel of Mark"],
        "remove": [],
        "reason": (
            "The argument repeatedly analyzes Mark's sayings, literary construction, "
            "and apocalyptic claims to assess what can be attributed to Jesus."
        ),
    },
    "36563": {
        "add": ["Roman Crucifixion and Burial"],
        "remove": [],
        "reason": (
            "The entire post uses Petronius to explain the Roman practice of guarding "
            "crucified bodies against removal and burial."
        ),
    },
    "36424": {
        "add": ["Redaction Criticism"],
        "remove": [],
        "reason": (
            "The core argument is that Matthew and Luke altered Mark's order, "
            "contents, and meaning, illustrating how Gospel authors edited sources."
        ),
    },
    "35707": {
        "add": ["Rise of Christianity"],
        "remove": [],
        "reason": (
            "The post's opening problem and proposed model concern Christianity's "
            "rapid spread from Palestine into the Greco-Roman world."
        ),
    },
    "36197": {
        "add": ["Sexual and Reproductive Ethics"],
        "remove": [],
        "reason": (
            "The post is wholly devoted to classical pederasty, sexual desire, and "
            "ancient gender hierarchy and currently has no topical assignment."
        ),
    },
    "36435": {
        "add": ["Gospel of Mark", "Gospel of Luke"],
        "remove": [],
        "reason": (
            "The post's central comparison is between Mark's and Luke's portrayals "
            "of Jesus' death and their different theological meanings."
        ),
    },
    "35986": {
        "add": ["Gnosticism (General)"],
        "remove": [],
        "reason": (
            "The post explains Basilides' Gnostic understanding of Christ, suffering, "
            "crucifixion, and related Gnostic texts."
        ),
    },
    "35711": {
        "add": ["Moral Problems in Scripture"],
        "remove": [],
        "reason": (
            "The entire post is an ethical critique of Abraham's actions in Genesis "
            "and Scripture's presentation of him as exemplary."
        ),
    },
    "35694": {
        "add": ["Zealot Hypothesis"],
        "remove": [],
        "reason": (
            "The post's main historical reconstruction asks whether Jesus pursued a "
            "planned anti-Roman revolutionary strategy and evaluates the zealot hypothesis."
        ),
    },
    "35755": {
        "add": ["Jesus' Passion Narratives", "Zealot Hypothesis"],
        "remove": [],
        "reason": (
            "The entire post evaluates the Gospel arrest story and whether armed "
            "resistance implies that Jesus promoted violent rebellion."
        ),
    },
    "35679": {
        "add": ["Apocalyptic Jesus", "Jesus on Wealth and Poverty"],
        "remove": [],
        "reason": (
            "The post explains Jesus' radical demands concerning wealth and the poor "
            "as ethics grounded in an imminent apocalyptic kingdom."
        ),
    },
    "35649": {
        "add": ["Jesus' Passion Narratives"],
        "remove": [],
        "reason": (
            "The post surveys the historically secure outline and disputed details "
            "of Jesus' final week and the Passion accounts."
        ),
    },
    "35553": {
        "add": ["Oral Tradition"],
        "remove": [],
        "reason": (
            "The post directly examines how stories about Jesus circulated orally, "
            "changed, and spread before the Gospels."
        ),
    },
    "35362": {
        "add": ["Rise of Christianity"],
        "remove": [],
        "reason": (
            "The post uses Christianity's rapid geographic expansion as its main "
            "evidence that Jesus was an extraordinary founder."
        ),
    },
    "35436": {
        "add": ["Oral Tradition", "Memory and Jesus Traditions"],
        "remove": [],
        "reason": (
            "The entire post critiques verbatim memorization as a model for oral "
            "transmission and explains how Gospel traditions changed."
        ),
    },
    "35410": {
        "add": ["Gospel of Mark", "Oral Tradition"],
        "remove": [],
        "reason": (
            "The post traces the scholarly turn to Mark as the earliest Gospel and "
            "then to oral traditions behind the written Gospels."
        ),
    },
    "35404": {
        "add": ["Oral Tradition"],
        "remove": [],
        "reason": (
            "The post explains how early Christians shaped and transmitted Jesus "
            "traditions by word of mouth before the Gospels."
        ),
    },
    "35255": {
        "add": ["Paul and His Opponents"],
        "remove": [],
        "reason": (
            "The post's central question is whether Matthew opposed Paul's teaching "
            "about Torah observance, framed alongside Paul's Judaizing opponents."
        ),
    },
    "35212": {
        "add": ["Oral Tradition"],
        "remove": [],
        "reason": (
            "A sustained final section asks how stories about Jesus circulated and "
            "changed by word of mouth before the Gospels were written."
        ),
    },
    "35159": {
        "add": ["Crucifixion of Jesus", "Book of Revelation"],
        "remove": [],
        "reason": (
            "The post's argument contrasts the suffering and death of Jesus with the "
            "conquering Christ and victorious saints portrayed in Revelation."
        ),
    },
    "35129": {
        "add": ["Life After Death (General)"],
        "remove": [],
        "reason": (
            "The post is a sustained presentation of Epicurus on death, the soul's "
            "dissolution, the absence of an afterlife, and why death should not be feared."
        ),
    },
    "34953": {
        "add": ["Gospel of Mark"],
        "remove": [],
        "reason": (
            "The post substantially compares the alleged Secret Mark passages with "
            "canonical Mark's vocabulary, variants, and textual form."
        ),
    },
    "34961": {
        "add": ["Gospel of Mark"],
        "remove": [],
        "reason": (
            "The post repeatedly uses passages and narrative puzzles in canonical "
            "Mark to test whether Secret Mark preserves an earlier form."
        ),
    },
    "34912": {
        "add": ["Resurrection of Jesus"],
        "remove": [],
        "reason": (
            "The post's defining subject is the Gospel of Peter's unique narrative "
            "of Jesus emerging from the tomb."
        ),
    },
    "34830": {
        "add": ["2 Thessalonians", "Armageddon"],
        "remove": [],
        "reason": (
            "The post is an Armageddon excerpt whose second half centrally interprets "
            "2 Thessalonians as requiring a rebuilt temple before Jesus returns."
        ),
    },
    "34826": {
        "add": ["Armageddon"],
        "remove": [],
        "reason": (
            "The post is explicitly an excerpt from Armageddon explaining how modern "
            "evangelical prophecy readings shape support for Israel."
        ),
    },
    "34751": {
        "add": ["Armageddon"],
        "remove": [],
        "reason": (
            "The post is explicitly an excerpt from Armageddon explaining Paul's "
            "teaching and why it does not describe a pre-tribulation rapture."
        ),
    },
    "34745": {
        "add": ["Armageddon", "Pauline End-Time Expectations"],
        "remove": [],
        "reason": (
            "The post is an Armageddon excerpt and substantially analyzes Paul's "
            "expectation in 1 Thessalonians that the dead will rise when Jesus returns."
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
    if len(tracker.get("posts", [])) not in {900, 1100}:
        raise ValueError("Tracker must contain the first 900 or 1100 posts")

    valid_topics = {topic["name"] for topic in topics_data["topics"]}
    entries = list(tracker["posts"][:900])
    for source_index, post in enumerate(posts[900:1100], start=900):
        sequence = source_index + 1
        wp_id = str(post["wpId"])
        original = list(post.get("topics", []))
        recommendation = RECOMMENDATIONS.get(
            wp_id, {"add": [], "remove": [], "reason": None}
        )
        added = list(recommendation["add"])
        removed = list(recommendation["remove"])
        description = recommendation.get("description")

        unknown = (set(original) | set(added) | set(removed)) - valid_topics
        if unknown:
            raise ValueError(f"Unknown topics for {wp_id}: {sorted(unknown)}")
        if not set(removed).issubset(original):
            raise ValueError(f"Cannot remove absent topic from {wp_id}: {removed}")
        if set(added) & set(original):
            raise ValueError(f"Cannot add existing topic to {wp_id}: {added}")

        recommended = [topic for topic in original if topic not in removed]
        recommended.extend(topic for topic in added if topic not in recommended)
        changed = bool(added or removed or description is not None)
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
        if description is not None:
            entry["descriptionBefore"] = post.get("description")
            entry["descriptionRecommended"] = description
        entries.append(entry)

    expected_recommendations = set(RECOMMENDATIONS)
    recorded_recommendations = {
        entry["wpId"] for entry in entries[900:] if entry["status"] == "pending_approval"
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
                "First 1100 canonical search-index posts in current newest-first order"
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
