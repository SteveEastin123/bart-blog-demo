"""Record post-topic audit recommendations for posts 2551-2850.

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
NOTES_PATH = ROOT / "data" / "audits" / "post_topic_link_audit_2551_2850_working_notes.md"


def rec(add=(), remove=(), reason=None, description=None):
    return {
        "add": list(add),
        "remove": list(remove),
        "reason": reason,
        "description": description,
    }


RECOMMENDATIONS = {
    "15444": rec(
        add=("Gnosticism (General)",),
        reason="The post gives a sustained account of Thomasine Christianity and a second substantial discussion of Gnostic texts that do not fit the major named schools.",
        description="Explains Thomasine Christianity and introduces other Gnostic texts that do not fit neatly into Sethian, Valentinian, or Thomasine groupings.",
    ),
    "15407": rec(
        add=("Church Fathers as Textual Evidence", "Textual Criticism Methods"),
        reason="The core of the post is the use of Didymus's Gospel quotations and a statistical textual-critical method to reconstruct the transmission of the Gospel text in Alexandria.",
    ),
    "15370": rec(
        add=("New Testament Manuscripts", "Textual Criticism Methods"),
        reason="The answer reconstructs the Gospels' earlier form by comparing independent Greek manuscripts, ancient translations, and patristic quotations.",
        description="Explains why independent Greek manuscripts, ancient translations, and patristic quotations show that miracle stories belonged to the Gospels from the start.",
    ),
    "15324": rec(
        add=("Gospel of Luke",),
        reason="Luke's birth account and its direct conflict with Matthew occupy roughly half of the post and are indispensable to its central argument.",
    ),
    "15268": rec(
        add=("Acts",),
        reason="The post examines an internal chronological tension between Luke's Gospel and Acts concerning Jesus' ascension.",
    ),
    "15261": rec(
        add=("Acts",),
        reason="The alleged contradiction is specifically between Luke's Gospel and Acts over when Jesus ascended.",
    ),
    "15285": rec(
        add=("Courses and Teaching",),
        reason="The autobiographical story is wholly centered on a grading incident from an undergraduate New Testament course and the responsibilities of teaching.",
    ),
    "15248": rec(
        add=("Historical Jesus (General)",),
        reason="The post repeatedly contrasts the apocalyptic historical Jesus with the divine Christ proclaimed by later Christians and explains why that historical disparity matters.",
        description="Explains that the historical gap between Jesus as an apocalyptic preacher and the divine Christ proclaimed by Christians drives continuing interest in Jesus.",
    ),
    "14986": rec(
        add=(
            "Gospel of Luke",
            "Textual Variants",
            "Theologically Significant Variants",
        ),
        reason="The post is a detailed textual-critical analysis of the shorter and longer forms of Luke's Last Supper account and the atonement theology affected by the variant.",
    ),
    "14971": rec(
        add=("Afterlife Journeys", "Scholarly Research and Publishing"),
        reason="The post reproduces and explains a scholarly research proposal devoted to early Christian guided tours of heaven and hell.",
    ),
    "14950": rec(
        add=("Exaltation Christology",),
        reason="The post explicitly compares exaltation and incarnation Christologies and explains how the Philippians poem combines the two streams.",
    ),
    "14939": rec(
        add=("Resurrection Arguments and Apologetics",),
        reason="The historical discussion of Peter's martyrdom is framed throughout as an evaluation of the apologetic claim that apostolic deaths prove Jesus' resurrection.",
    ),
    "14935": rec(
        add=("Non-Canonical Gospel Traditions",),
        reason="The post introduces and translates the non-canonical Report of Pilate as a legendary expansion of Jesus' trial, death, and resurrection.",
    ),
    "14923": rec(
        add=("Gospel of Mark",),
        reason="The post's interpretation depends on a sustained comparison of Mark's and Luke's distinct portrayals of Jesus' suffering and death.",
    ),
    "14920": rec(
        add=("Gospel of Mark", "Jesus' Passion Narratives"),
        reason="Most of the post compares Mark's and Luke's Passion narratives to show how their discrepancies preserve different theological messages.",
    ),
    "14734": rec(
        add=("Courses and Teaching",),
        reason="The post presents the objectives, requirements, readings, assignments, and schedule of a Greek New Testament university course.",
    ),
    "13506": rec(
        add=("Gospel of Mark", "Jesus' Passion Narratives", "Christology in the Gospels"),
        reason="The post closely interprets Mark's Passion, empty-tomb ending, and presentation of Jesus as the suffering and vindicated Son of God.",
    ),
    "13605": rec(
        add=("Nazareth",),
        reason="The post is devoted to Mark's story of Jesus' rejection by the people of his hometown, explicitly identified as Nazareth.",
    ),
    "13419": rec(
        add=("Textual Variants",),
        reason="The entire answer concerns how translators present major passages that textual critics judge to be later additions.",
    ),
    "13404": rec(
        add=("Scribal Changes",),
        reason="The post directly examines scribal alteration behind the competing numbers 666 and 616 in Revelation.",
    ),
    "13323": rec(
        add=("Divine Judgment", "Jesus' Teachings", "Apocalyptic Jesus"),
        reason="The post interprets the sheep-and-goats saying as Jesus' apocalyptic teaching about the Son of Man's final judgment, reward, and punishment.",
    ),
    "13240": rec(
        add=("Autobiographical Posts",),
        reason="The post is a sustained personal reflection on how a fundamentalist background shaped and complicated a later scholarly career.",
    ),
    "13227": rec(
        add=("Divine Beings in the Hebrew Bible",),
        reason="A major portion of the post traces Israelite henotheism, later Jewish monotheism, angels, Moses, and other divine beings in Jewish tradition.",
    ),
    "13225": rec(
        add=("Resurrection Arguments and Apologetics",),
        remove=("Apocryphal Acts",),
        reason="The post evaluates an apologetic argument based on apostolic martyrdom; apocryphal Acts are cited only as unreliable evidence for how some apostles died.",
    ),
    "13186": rec(
        remove=("Life After Death (General)",),
        reason="The post explicitly distinguishes Jesus' earthly kingdom proclamation from what happens to souls after death; its sustained subjects are already covered by Apocalyptic Jesus and Jesus' Teachings.",
    ),
    "13183": rec(
        add=("Acts", "Paul in Acts"),
        reason="The post narrates and critically evaluates Acts' portrayal of Paul's Sanhedrin trial as evidence for Jewish disputes about resurrection.",
    ),
    "13137": rec(
        add=("Acts", "Theologically Significant Variants"),
        reason="The argument surveys Luke and Acts and relies centrally on a scribal addition whose atonement language changes Luke's theology.",
    ),
    "12924": rec(
        add=("Afterlife Journeys",),
        reason="The post compares Lucian with an Acts of Thomas story in which a dead man tours heaven and returns to describe a heavenly palace.",
    ),
    "12914": rec(
        add=("Afterlife Journeys",),
        reason="Lucian's Voyage to the Underworld and the moral purpose of journeys among the dead are the post's explicit subject.",
    ),
    "12910": rec(
        add=("Afterlife Journeys",),
        reason="The post defines otherworldly journeys and gives a detailed account of a woman's tour of hell in the Acts of Thomas.",
    ),
    "12898": rec(
        add=("Comparative Ancient Evidence",),
        reason="The post uses Homer, the Epic of Gilgamesh, and the Hebrew Bible to compare ancient conceptions of an undifferentiated afterlife.",
    ),
    "12873": rec(
        add=("Afterlife Journeys", "Scholarly Research and Publishing"),
        reason="The post introduces a planned scholarly monograph on early Christian descents to hell and ascents to heaven and explains the research proposal behind it.",
    ),
    "12862": rec(
        add=("Gospel Authorship", "Gospel of John"),
        reason="The post directly evaluates whether John son of Zebedee could have composed the Greek Gospel attributed to him.",
    ),
    "12830": rec(
        add=("Book of Daniel", "Historical Methods (General)"),
        reason="Half of the post explains the linguistic and historical grounds for dating Daniel to the Maccabean period and distinguishes critical historical reasoning from anti-supernatural bias.",
        description="Corrects a claim about Ehrman's view of Gnosticism and explains why critical scholars date much of Daniel to the Maccabean period.",
    ),
    "12797": rec(
        add=("Resurrection of the Dead",),
        reason="The stated purpose and concluding emphasis of the survey are 2 Maccabees' teaching that faithful martyrs will be raised and rewarded.",
    ),
}


def load_json(path: Path) -> object:
    return json.loads(path.read_text(encoding="utf-8"))


def render_notes(entries: list[dict]) -> str:
    lines = [
        "# Post Topic Link Audit 2551-2850 Working Notes",
        "",
        "Canonical post records remain unchanged until the user approves this batch.",
        "",
        "## Progress",
        "",
        "- Audited through post 2850.",
        "- Canonical post records remain unchanged pending approval.",
        "- Posts not listed below currently require no topic changes.",
        "",
        "## Proposed Changes",
        "",
    ]
    for entry in entries:
        if entry["status"] != "pending_approval":
            continue
        lines.extend(
            [
                f"### Post {entry['auditSequence']} | wpId {entry['wpId']}",
                "",
                f"Title: {entry['title']}",
                "",
            ]
        )
        if entry["topicsRemoved"]:
            lines.append(f"- Remove: {'; '.join(entry['topicsRemoved'])}")
        if entry["topicsAdded"]:
            lines.append(f"- Add: {'; '.join(entry['topicsAdded'])}")
        lines.append(f"- Reason: {entry['reason']}")
        lines.append("")
    return "\n".join(lines).rstrip() + "\n"


def main() -> None:
    posts = load_json(POSTS_PATH)
    topics_data = load_json(TOPICS_PATH)
    tracker = load_json(TRACKER_PATH)
    if not isinstance(posts, list):
        raise TypeError("Unexpected post index shape")
    if not isinstance(topics_data, dict) or not isinstance(tracker, dict):
        raise TypeError("Unexpected topic or tracker shape")
    if len(tracker.get("posts", [])) not in {2550, 2850}:
        raise ValueError("Tracker must contain the first 2550 or 2850 posts")

    valid_topics = {topic["name"] for topic in topics_data["topics"]}
    entries = list(tracker["posts"][:2550])
    descriptions = [
        item
        for item in tracker.get("pendingDescriptionRecommendations", [])
        if item.get("auditSequence", 0) <= 2550
    ]
    batch_entries = []
    for source_index, post in enumerate(posts[2550:2850], start=2550):
        sequence = source_index + 1
        wp_id = str(post["wpId"])
        original = list(post.get("topics", []))
        recommendation = RECOMMENDATIONS.get(
            wp_id,
            {"add": [], "remove": [], "reason": None, "description": None},
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
        entries.append(entry)
        batch_entries.append(entry)
        if recommendation["description"]:
            descriptions.append(
                {
                    "auditSequence": sequence,
                    "wpId": wp_id,
                    "title": post["title"],
                    "descriptionBefore": post.get("description"),
                    "descriptionRecommended": recommendation["description"],
                }
            )

    expected_recommendations = set(RECOMMENDATIONS)
    recorded_recommendations = {
        entry["wpId"] for entry in batch_entries if entry["status"] == "pending_approval"
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
            "auditScope": "First 2850 canonical search-index posts in current newest-first order",
            "reviewedPostCount": len(entries),
            "noChangeCount": sum(entry["status"] == "reviewed_no_change" for entry in entries),
            "pendingApprovalCount": sum(entry["status"] == "pending_approval" for entry in entries),
            "appliedChangeCount": sum(entry["status"] == "applied" for entry in entries),
            "pendingDescriptionRecommendations": descriptions,
        }
    )
    TRACKER_PATH.write_text(
        json.dumps(tracker, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    NOTES_PATH.write_text(render_notes(batch_entries), encoding="utf-8")
    print(
        f"Audited {len(batch_entries)} posts; "
        f"recorded {len(recorded_recommendations)} recommendations."
    )


if __name__ == "__main__":
    main()
