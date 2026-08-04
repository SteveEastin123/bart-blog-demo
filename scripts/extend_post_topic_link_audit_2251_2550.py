"""Record post-topic audit recommendations for posts 2251-2550.

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
NOTES_PATH = ROOT / "data" / "audits" / "post_topic_link_audit_2251_2550_working_notes.md"


def rec(add=(), remove=(), reason=None, description=None):
    return {
        "add": list(add),
        "remove": list(remove),
        "reason": reason,
        "description": description,
    }


RECOMMENDATIONS = {
    "16757": rec(
        add=("Archaeology and Material Evidence",),
        reason="The post evaluates four models for Israel's emergence in Canaan largely by comparing them with the archaeological record.",
    ),
    "16749": rec(
        add=("Archaeology and Material Evidence",),
        reason="Archaeological evidence for Canaanite cities and their destruction is a major part of the post's assessment of Joshua's conquest narrative.",
    ),
    "16473": rec(
        add=("Afterlife Journeys",),
        reason="The post compares tours of heaven and hell in the Acts of Thomas, Apocalypse of Peter, and Apocalypse of Paul.",
    ),
    "16425": rec(
        add=("Afterlife Journeys",),
        reason="The post is devoted to two guided journeys through heaven and hell in the Acts of Thomas.",
    ),
    "16381": rec(
        add=("Afterlife Journeys",),
        reason="Ancient otherworldly journeys are both the subject of the featured conference paper and the post's principal long-term research project.",
    ),
    "16377": rec(
        add=(
            "James the Brother of Jesus",
            "Non-Pauline Epistle Authorship",
            "Non-Pauline Epistle Forgeries",
        ),
        reason="The post's sustained argument is that James the brother of Jesus did not write the letter and that it was forged in his name.",
    ),
    "16322": rec(
        add=("Manuscript Discoveries and Controversies",),
        reason="The post focuses on provenance, possible Nazi looting, acquisition ethics, and restitution questions involving ancient manuscripts.",
    ),
    "16318": rec(
        add=("Manuscript Discoveries and Controversies",),
        reason="The provenance and possible Nazi-era looting of Christian manuscripts are the post's central subjects.",
    ),
    "16288": rec(
        add=("Original Text Questions",),
        reason="The post asks whether scholars could ever identify a New Testament manuscript as an author's original copy.",
    ),
    "16167": rec(
        add=("Modern Forgery Claims",),
        reason="The post exposes a nineteenth-century document claiming to preserve Pilate's official death sentence for Jesus as a modern forgery.",
    ),
    "16159": rec(
        add=("New Testament Manuscripts", "Textual Criticism Methods"),
        reason="The answer uses independent manuscripts, ancient versions, and patristic quotations to reconstruct whether miracle stories belonged to the Gospels from the start.",
    ),
    "15989": rec(
        add=("Conversion",),
        reason="Constantine's conversion and its historical significance are the lecture's central focus.",
    ),
    "16098": rec(
        add=("Afterlife Journeys",),
        reason="The post summarizes Paul's guided tour of the blessed and damned in the Apocalypse of Paul.",
    ),
    "15995": rec(
        add=("Afterlife Journeys",),
        reason="Plato's story of Er returning from a journey through postmortem rewards and punishments supplies the post's principal evidence.",
    ),
    "15919": rec(
        add=("Critical Biblical Scholarship",),
        remove=("Ignore",),
        reason="This substantive post traces how Protestant theology shaped critical scholarship's concern with recovering religious origins.",
    ),
    "15902": rec(
        add=("Afterlife Journeys", "Scholarly Research and Publishing"),
        remove=("Heaven and Hell Beliefs",),
        reason="The post introduces a scholarly book project on ancient otherworldly journeys and discusses its research, title, audience, and publication rather than surveying heaven-and-hell beliefs.",
    ),
    "15876": rec(
        add=("Historical Jesus (General)",),
        reason="The interview evaluates Josephus, Tacitus, archaeology, and other non-Christian evidence for Jesus as a historical figure.",
    ),
    "15841": rec(
        add=("Gospel of Mark",),
        reason="The entire post explains the evidence that Mark was the earliest Gospel and a source for Matthew and Luke.",
    ),
    "15839": rec(
        add=("Methods for Studying the Historical Jesus",),
        reason="The post traces the beginning of the historical Jesus quest and explains how scholars critically used Gospel sources to reconstruct Jesus' life.",
    ),
    "15837": rec(
        add=("Gospel of Mark", "Crucifixion of Jesus"),
        reason="The post closely analyzes Mark's presentation of Jesus' suffering, Passion, and death as the Gospel's organizing focus.",
    ),
    "15809": rec(
        add=("Ignore",),
        remove=("Gospel of Mark",),
        reason="This brief correction retracts a prior claim about Mark 7 rather than offering a sustained treatment of Mark's Gospel.",
        description="Retracts an earlier claim about Mark 7 after readers pointed to contrary evidence in the Letter of Aristeas.",
    ),
    "15801": rec(
        add=("Original Text Questions",),
        reason="The post's central question is which surviving form best represents the Apocalypse of Peter as originally written.",
    ),
    "15795": rec(
        add=("Gospel of John", "Gospel Historical Reliability"),
        reason="The post compares John's divine claims for Jesus with the Synoptic Gospels and argues that those claims do not derive from the historical Jesus.",
    ),
    "15787": rec(
        add=("Gospel of Luke",),
        reason="The post explains Luke's boyhood story as a feature of Greco-Roman biography that anticipates Jesus' adult identity and mission.",
        description="Explains Luke's story of the twelve-year-old Jesus as a feature of Greco-Roman biography that anticipates his adult identity and mission.",
    ),
    "15765": rec(
        add=("Textual Criticism Methods",),
        reason="The post explains how the UBS and Nestle-Aland critical Greek texts were reconstructed from manuscripts, versions, and patristic evidence for use by translators.",
    ),
    "15740": rec(
        add=("Afterlife Journeys", "Modern End-Times Interpretation"),
        reason="Two of the post's four major research goals are a scholarly study of otherworldly journeys and a trade book on modern readings of Revelation as end-times prediction.",
    ),
    "15711": rec(
        add=("Petrine Authorship and Forgeries",),
        reason="The post compares ancient judgments about the authorship of 1 Peter, the Gospel of Peter, and other writings attributed to Peter.",
    ),
    "15676": rec(
        add=("Non-Canonical Gospel Traditions", "Crucifixion of Jesus"),
        reason="The post translates a non-canonical Passion account whose sustained narrative concerns Jesus' trial, betrayal, and crucifixion.",
    ),
    "15674": rec(
        add=("Non-Canonical Gospel Traditions",),
        reason="The post introduces and translates the late Narrative of Joseph of Arimathea, including its legendary Passion traditions and a letter written by Jesus from the cross.",
        description="Introduces the Narrative of Joseph of Arimathea, in which Jesus writes from the cross to the guardians of paradise.",
    ),
    "15626": rec(
        add=("Petrine Authorship and Forgeries",),
        reason="The introduction argues that 2 Peter is a late pseudonymous work falsely attributed to the apostle.",
    ),
    "15604": rec(
        add=("Gospel of Luke", "Translation Issues"),
        reason="The post examines the punctuation and translation of Luke 23:43 as evidence for Luke's view that believers enter paradise immediately at death.",
    ),
    "15577": rec(
        add=("Christology in the Gospels", "Gospel of John"),
        reason="The post compares the Synoptic Gospels with John's distinctive portrayal of Jesus explicitly claiming divinity.",
    ),
    "15564": rec(
        remove=("Bible Translations (General)",),
        reason="The post concerns how digital media changes access to and reading of the Bible; translation comparison is only one supporting feature, and no existing topic precisely represents the main subject.",
    ),
    "15543": rec(
        add=("Jesus on Wealth and Poverty", "Gospel of Luke", "Jesus' Teachings"),
        reason="The post interprets Luke's Lazarus parable as Jesus' teaching about wealth, poverty, care for the poor, and postmortem judgment.",
    ),
    "15541": rec(
        add=(
            "Wealth and Poverty in Antiquity",
            "Comparative Ancient Evidence",
            "Gospel of Luke",
        ),
        reason="The post compares Luke's rich-man-and-Lazarus parable with an Egyptian tale of reversed wealth and poverty in the afterlife.",
    ),
    "15539": rec(
        add=("Jesus on Wealth and Poverty",),
        reason="The post emphasizes the parable's reversal of the rich man and poor Lazarus and its implications for the use of wealth.",
    ),
    "15532": rec(
        add=("Book of Revelation",),
        reason="The post is wholly devoted to Revelation's authorship, writing style, eschatology, and canonical reception.",
    ),
    "15477": rec(
        add=("Resurrection Arguments and Apologetics", "Apostolic Death Traditions"),
        remove=("Apocryphal Acts",),
        reason="The post evaluates the apologetic claim that the disciples died for resurrection belief and the historical evidence for apostolic martyrdom; apocryphal Acts are only cited as unreliable sources.",
    ),
    "15466": rec(
        add=("Heaven and Hell", "Eternal Punishment", "Apocalyptic Jesus"),
        remove=("Life After Death (General)",),
        reason="The post presents a book argument that Jesus' apocalyptic teaching envisioned the annihilation of sinners rather than eternal torment, making the specific topics more useful than general life after death.",
        description="Argues that Jesus taught the annihilation of sinners rather than their eternal torment and seeks feedback on that reading.",
    ),
}


def load_json(path: Path) -> object:
    return json.loads(path.read_text(encoding="utf-8"))


def render_notes(entries: list[dict]) -> str:
    lines = [
        "# Post Topic Link Audit 2251-2550 Working Notes",
        "",
        "Canonical post records remain unchanged until the user approves this batch.",
        "",
        "## Progress",
        "",
        "- Audited through post 2550.",
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
    if len(tracker.get("posts", [])) not in {2250, 2550}:
        raise ValueError("Tracker must contain the first 2250 or 2550 posts")

    valid_topics = {topic["name"] for topic in topics_data["topics"]}
    entries = list(tracker["posts"][:2250])
    descriptions = [
        item
        for item in tracker.get("pendingDescriptionRecommendations", [])
        if item.get("auditSequence", 0) <= 2250
    ]
    batch_entries = []
    for source_index, post in enumerate(posts[2250:2550], start=2250):
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
            "auditScope": "First 2550 canonical search-index posts in current newest-first order",
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
        f"Recorded {len(entries)} reviewed posts: "
        f"{tracker['noChangeCount']} no change, "
        f"{tracker['appliedChangeCount']} applied, "
        f"{tracker['pendingApprovalCount']} pending approval; "
        f"batch recommendations: {len(recorded_recommendations)}."
    )


if __name__ == "__main__":
    main()
