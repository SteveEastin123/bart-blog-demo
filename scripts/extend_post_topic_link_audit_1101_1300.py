"""Record post-topic audit recommendations for posts 1101-1300.

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


def rec(add=(), remove=(), reason=None, description=None):
    value = {
        "add": list(add),
        "remove": list(remove),
        "reason": reason,
    }
    if description is not None:
        value["description"] = description
    return value


RECOMMENDATIONS = {
    "34669": rec(
        add=("Historical Jesus (General)", "How Jesus Became God"),
        reason=(
            "The post centrally contrasts the historical Jesus with the divine Christ "
            "and explains the motivation and argument behind How Jesus Became God."
        ),
        description=(
            "Explains why an atheist scholar remains fascinated by the disparity between "
            "the historical Jesus and the divine Christ whose worship transformed Western civilization."
        ),
    ),
    "34664": rec(
        add=("Problem of Evil and Suffering",),
        reason=(
            "The entire post evaluates God's response to Job as an answer to the problem "
            "of innocent suffering."
        ),
    ),
    "34764": rec(
        add=("Book of Revelation",),
        reason=(
            "The reproduced preface centrally explains Revelation's interpretation, "
            "violence, judgment, and modern effects rather than merely promoting the book."
        ),
    ),
    "34586": rec(
        add=("Problem of Evil and Suffering",),
        reason=(
            "The entire post asks whether the God who subjects innocent Job to suffering "
            "is morally worthy of worship."
        ),
    ),
    "34580": rec(
        add=("Biblical Explanations of Suffering",),
        reason=(
            "The post develops the prose folktale's explanation that innocent suffering "
            "can function as a test of faith."
        ),
    ),
    "34575": rec(
        add=("Hebrew Bible Composition and Sources", "Biblical Explanations of Suffering"),
        reason=(
            "The post argues that Job combines two compositions with different authors "
            "and different explanations of suffering."
        ),
    ),
    "34494": rec(
        add=("Biblical Contradictions",),
        reason=(
            "A major sustained part compares conflicting Synoptic forms of the baptism "
            "and the Gospel of the Ebionites' harmonization of them."
        ),
    ),
    "34490": rec(
        add=("Sexual and Reproductive Ethics",),
        reason=(
            "The post broadly examines Paul, church fathers, and Augustine on sex, "
            "marriage, celibacy, lust, original sin, and contraception."
        ),
    ),
    "34368": rec(
        remove=("Disasters and Human Suffering",),
        reason=(
            "Disasters are examples used to critique the free-will defense, not a primary "
            "or sustained subject of the post."
        ),
    ),
    "34327": rec(
        add=("Ancient Jewish Afterlife Beliefs",),
        remove=("Life After Death (General)",),
        reason=(
            "The post specifically traces the Jewish development of postmortem judgment "
            "and afterlife belief rather than surveying the afterlife generally."
        ),
    ),
    "34541": rec(
        remove=("Early Judaism (General)",),
        reason=(
            "This short lecture announcement concerns archaeological discoveries in a "
            "synagogue; early Judaism is context rather than a sustained subject."
        ),
    ),
    "34332": rec(
        add=("Jesus and Women",),
        remove=("Jesus' Family Traditions",),
        reason=(
            "The opening briefly dismisses evidence that Jesus was married, while the core "
            "examines Martha and Mary and later portrayals of Mary as Christ's bride."
        ),
        description=(
            "Examines how Gospel accounts of Martha and Mary were conflated in later "
            "interpretation and used to portray Mary as Christ's devoted bride."
        ),
    ),
    "34038": rec(
        add=("Forgery (General)",),
        reason=(
            "The post centrally argues that an alleged eyewitness account of Polycarp's "
            "martyrdom is a later forgery."
        ),
    ),
    "34088": rec(
        add=("Jesus' Miracle Stories",),
        remove=("John the Baptist",),
        reason=(
            "John the Baptist appears in only one brief section, while a major portion "
            "examines whether substances could explain Jesus' healings and exorcisms."
        ),
    ),
    "33915": rec(
        add=("Apostolic Fathers",),
        reason=(
            "The post centrally explains manuscript variation across the Apostolic Fathers, "
            "using Ignatius as its principal example."
        ),
    ),
    "33890": rec(
        add=("Apostolic Fathers",),
        reason=(
            "A major portion defines the Apostolic Fathers and describes the author's "
            "translation of that corpus for the Loeb Classical Library."
        ),
    ),
    "33807": rec(
        add=("Textual Criticism Methods",),
        reason=(
            "The post centrally explains how critical Greek texts are constructed from "
            "manuscript witnesses and how translators use their apparatuses."
        ),
    ),
    "33874": rec(
        add=("Paul's Knowledge of Jesus",),
        reason=(
            "The entire post catalogs substantive overlap between Paul's letters and "
            "traditions preserved in the Gospels."
        ),
    ),
    "33796": rec(
        add=("Textual Variants",),
        reason=(
            "The post centrally discusses three major New Testament variants and how the "
            "NRSV presents them."
        ),
    ),
    "33787": rec(
        add=("Textual Variants",),
        reason=(
            "The post explains how the King James Version's late manuscript base includes "
            "important readings absent from earlier witnesses."
        ),
    ),
    "33724": rec(
        add=("Textual Criticism Overview",),
        reason=(
            "The post traces Erasmus's Greek New Testament, its manuscript base, textual "
            "faults, and later influence."
        ),
    ),
    "33718": rec(
        add=("Textual Criticism Overview",),
        reason=(
            "The post explains the first printed Greek New Testament and the manuscripts "
            "used to produce it."
        ),
    ),
    "33712": rec(
        add=("Textual Criticism Methods",),
        reason=(
            "The post explains eclectic critical texts and how editors choose among "
            "competing manuscript readings."
        ),
    ),
    "33619": rec(
        add=("Personal Reflections", "Suffering and Loss of Faith"),
        remove=("Disasters and Human Suffering",),
        reason=(
            "Specific disasters serve as examples within a personal reflection on "
            "suffering, faith, and estrangement from Christianity."
        ),
    ),
    "33580": rec(
        add=("Gospel of Luke", "Biblical Contradictions"),
        reason=(
            "The post compares Matthew and Luke throughout and uses their irreconcilable "
            "birth accounts to argue that Jesus came from Nazareth."
        ),
    ),
    "33569": rec(
        add=("Methods for Studying the Historical Jesus",),
        reason=(
            "A substantial section explains historical criteria and applies them directly "
            "to the question of Jesus' association with Nazareth."
        ),
    ),
    "33535": rec(
        add=("Heaven and Hell Beliefs",),
        reason=(
            "The entire post examines Christ's descent to Hades, who was believed to be "
            "saved there, and early Christian views of the underworld."
        ),
    ),
    "33405": rec(
        add=("Pauline Forgeries",),
        reason=(
            "A major sustained section centers on 2 Thessalonians as a possible Pauline "
            "forgery before the post surveys other New Testament cases."
        ),
    ),
    "33437": rec(
        add=("Mythicism",),
        reason=(
            "A large central section evaluates mythicist claims that Jesus' virgin birth "
            "was modeled on stories about pagan gods."
        ),
    ),
    "33548": rec(
        add=("Proto-Gospel of James",),
        reason=(
            "The announced webinar treats the Infancy Gospel of Thomas and the "
            "Proto-Gospel of James as its two principal texts."
        ),
    ),
    "33421": rec(
        add=("Hebrew Bible Manuscripts",),
        reason=(
            "The core subject is the manuscript evidence and textual transmission behind "
            "the Hebrew Bible rather than only the original-text concept."
        ),
    ),
    "33349": rec(
        add=("1 Thessalonians", "Textual Variants", "Textual Criticism Methods"),
        reason=(
            "The entire post examines a variant in 1 Thessalonians 2:7 and weighs the "
            "internal criteria used to decide between its readings."
        ),
    ),
    "33388": rec(
        add=("Personal Reflections",),
        reason=(
            "The post is an extended personal memorial and reflection on the author's "
            "mother, deconversion, family relationships, and love."
        ),
    ),
    "33317": rec(
        add=("Deconversion",),
        reason=(
            "The autobiographical argument traces how critical Bible study and recognized "
            "errors contributed to the author's departure from evangelical faith."
        ),
    ),
    "33308": rec(
        add=("Gospel of Mark",),
        reason=(
            "The textual variant and its interpretation are examined entirely within "
            "Mark's passion narrative."
        ),
    ),
    "33272": rec(
        add=("Textual Criticism Overview",),
        reason=(
            "The post broadly explains accidental scribal errors and their implications "
            "for reconstructing the earliest attainable text."
        ),
    ),
    "33237": rec(
        add=("Philippians",),
        reason=(
            "The entire interpretation of Paul's possible suicidal thoughts centers on "
            "Philippians 1."
        ),
    ),
    "33032": rec(
        add=("Gospel Historical Reliability",),
        reason=(
            "The central argument assesses and rejects the use of Matthew's earthquake "
            "story as literal historical evidence for dating Jesus' death."
        ),
    ),
    "33023": rec(
        add=("Hebrew Bible Composition and Sources",),
        reason=(
            "The entire argument concerns the composition and authorship of Deuteronomy "
            "and Jeremiah's alleged literary response to it."
        ),
    ),
    "32874": rec(
        add=("Canonical Gospels (General)",),
        reason=(
            "The post presents broad research questions about authorship, sources, "
            "variants, canon, oral tradition, and reliability across all four Gospels."
        ),
    ),
    "32739": rec(
        add=("Jesus' Ethics",),
        reason=(
            "A substantial part contrasts Greek and Roman ethics with Jewish law and "
            "Jesus' religious ethics, especially the command to love one's neighbor."
        ),
        description=(
            "Contrasts Greco-Roman ethics centered on status, reciprocity, and benefaction "
            "with Jewish and Jesus traditions connecting ethics to religion and love of neighbor."
        ),
    ),
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
    if len(tracker.get("posts", [])) not in {1100, 1300}:
        raise ValueError("Tracker must contain the first 1100 or 1300 posts")

    valid_topics = {topic["name"] for topic in topics_data["topics"]}
    entries = list(tracker["posts"][:1100])
    for source_index, post in enumerate(posts[1100:1300], start=1100):
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
        entry["wpId"] for entry in entries[1100:] if entry["status"] == "pending_approval"
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
                "First 1300 canonical search-index posts in current newest-first order"
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
