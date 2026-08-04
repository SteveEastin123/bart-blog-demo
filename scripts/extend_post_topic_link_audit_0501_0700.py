"""Record post-topic audit recommendations for posts 501-700.

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
    "41062": {
        "add": ["Paul and His Opponents"],
        "remove": [],
        "reason": (
            "The entire post reconstructs Paul's conflict with Barnabas and "
            "Jewish-Christian opponents."
        ),
    },
    "41058": {
        "add": ["Paul and His Opponents"],
        "remove": [],
        "reason": (
            "The sustained dialogue concerns Paul's conflict with John Mark, "
            "Barnabas, Peter, James, and Jewish-Christian opponents."
        ),
    },
    "41054": {
        "add": ["Paul and His Opponents"],
        "remove": [],
        "reason": (
            "The dialogue centers on Paul's disputes with Barnabas, Peter, John "
            "Mark, and the Jerusalem Christians."
        ),
    },
    "41251": {
        "add": ["Jesus' Ethics"],
        "remove": [],
        "reason": (
            "A major part of the post analyzes Jesus' anti-family sayings and "
            "their ethical and apocalyptic rationale."
        ),
    },
    "40647": {
        "add": ["Translation Issues"],
        "remove": [],
        "reason": (
            "The longest of the four substantial responses explains Greek compound "
            "verbs and translation in Genesis 7:17 and Matthew 24:39."
        ),
        "description": (
            "Responds to questions about 1 Clement and Scripture, the sinner's "
            "prayer, the Woman Caught in Adultery, and translating Greek compound "
            "verbs."
        ),
    },
    "40572": {
        "add": ["Historical Methods (General)"],
        "remove": [],
        "reason": (
            "The post offers a sustained methodological critique of how scholars "
            "reconstruct Christian origins and use the Gospels in relation to Paul."
        ),
    },
    "40538": {
        "add": ["Moral Problems in Scripture"],
        "remove": [],
        "reason": (
            "A major section critiques morally troubling biblical portrayals of "
            "God, violence, vengeance, and eternal punishment."
        ),
    },
    "40498": {
        "add": ["Historical Methods (General)"],
        "remove": [],
        "reason": (
            "The historical-critical method and its difference from devotional "
            "reading organize the post from beginning to end."
        ),
    },
    "40436": {
        "add": ["Pauline Authorship"],
        "remove": [],
        "reason": (
            "A central part of the source analysis distinguishes genuine, disputed, "
            "and forged Pauline letters when reconstructing the historical Paul."
        ),
    },
    "40387": {
        "add": ["Moral Problems in Scripture"],
        "remove": [],
        "reason": (
            "The entire post critiques the morality of laws about slavery, women, "
            "violence, execution, and property in Exodus 21."
        ),
    },
    "40285": {
        "add": ["Paul's Life and Career"],
        "remove": [],
        "reason": (
            "Paul is one of the three equal subjects, alongside Peter and Mary "
            "Magdalene, in the post's inquiry into history and legend."
        ),
    },
    "40280": {
        "add": ["Paul's Life and Career"],
        "remove": [],
        "reason": (
            "Paul is one of the book's three equal, sustained subjects, alongside "
            "Peter and Mary Magdalene."
        ),
    },
    "40220": {
        "add": ["Original Text Questions"],
        "remove": [],
        "reason": (
            "A sustained part of the autobiographical account concerns missing "
            "originals and error-filled later manuscript copies."
        ),
    },
    "40205": {
        "add": ["Autobiographical Posts"],
        "remove": [],
        "reason": (
            "Most of the post recounts Bart's religious upbringing, conversion, "
            "Moody years, and path into textual criticism."
        ),
    },
    "40157": {
        "add": ["Roman World"],
        "remove": [],
        "reason": (
            "The post evaluates Roman imperial legislation, government, punishments, "
            "and morality under Christian emperors."
        ),
    },
    "40118": {
        "add": ["Early Christian Diversity"],
        "remove": [],
        "reason": (
            "The post presents diversity as a central historical insight of the "
            "anthology and explains competing early Christian beliefs and texts."
        ),
    },
    "40016": {
        "add": ["Canon Formation", "Early Christian Diversity", "Early Christian Writings"],
        "remove": ["Non-Canonical Gospel Traditions"],
        "reason": (
            "The post surveys lost Gospels, acts, letters, and apocalypses while "
            "centering the formation of the canon and the diversity it suppressed. "
            "Non-canonical Gospels are only one part of this broader survey."
        ),
    },
    "40002": {
        "add": ["Canon Formation", "Early Christian Diversity"],
        "remove": [],
        "reason": (
            "The introduction centrally explains the prolonged formation of the "
            "canon and the competing Christian beliefs represented by excluded "
            "writings."
        ),
    },
    "39923": {
        "add": ["Christology (General)"],
        "remove": [],
        "reason": (
            "A major section evaluates orthodox, Arian, adoptionist, and docetic "
            "accounts of Jesus' divine and human nature."
        ),
    },
    "39925": {
        "add": ["Historical Jesus (General)"],
        "remove": [],
        "reason": (
            "The entire post evaluates the historical plausibility of Gospel crowds, "
            "Jesus' labor and travel, and possible contact with Sepphoris."
        ),
    },
    "39886": {
        "add": ["Church Fathers as Textual Evidence"],
        "remove": [],
        "reason": (
            "The central example reconstructs Didymus's Gospel text from patristic "
            "quotations and explains why that evidence matters."
        ),
    },
    "39844": {
        "add": ["Jesus' Ethics"],
        "remove": [],
        "reason": (
            "The book project's central thesis is that Jesus' teachings on care for "
            "strangers transformed Western moral obligation."
        ),
    },
    "39694": {
        "add": ["Early Christian Orthodoxy and Heresy"],
        "remove": [],
        "reason": (
            "The post repeatedly evaluates hostile heresiological reports about the "
            "Carpocratians against the group's surviving evidence."
        ),
    },
    "39754": {
        "add": ["Fundamentalism"],
        "remove": [],
        "reason": (
            "The post centrally addresses fundamentalist and conservative evangelical "
            "beliefs about inspiration, preservation, and textual variants."
        ),
    },
    "39723": {
        "add": ["Original Text Questions"],
        "remove": [],
        "reason": (
            "The post is organized around whether later copies of Philippians can "
            "recover the original letter Paul sent."
        ),
    },
    "39555": {
        "add": ["Pauline Textual Issues"],
        "remove": [],
        "reason": (
            "The post uses 1 Corinthians 14:34-35 to explain textual interpolation "
            "and whether words attributed to Paul were added later."
        ),
    },
    "39477": {
        "add": ["Textual Criticism Methods"],
        "remove": [],
        "reason": (
            "The post evaluates methodological arguments based on manuscript "
            "abundance and the tenacity of the textual tradition."
        ),
    },
    "39461": {
        "add": ["Gospel of John"],
        "remove": [],
        "reason": (
            "The sustained examples concern editions, sources, dictation, and the "
            "initial text of the Gospel of John."
        ),
    },
    "39498": {
        "add": ["Hebrew Bible Composition and Sources"],
        "remove": [],
        "reason": (
            "Roughly half the post explains the composite authorship and historical "
            "settings of First, Second, and Third Isaiah."
        ),
        "description": (
            "Explains two problems with original texts: recovering New Testament "
            "wording and identifying the composite literary form of books such as "
            "Isaiah."
        ),
    },
    "39094": {
        "add": ["Historical Jesus (General)", "Critical Biblical Scholarship"],
        "remove": [],
        "reason": (
            "Two of the post's three substantial responses address historical claims "
            "about Jesus in Islam and the meaning of critical biblical scholarship."
        ),
    },
    "39098": {
        "add": ["Original Text Questions"],
        "remove": [],
        "reason": (
            "A major part of the post critiques claims that God miraculously preserved "
            "an identifiable original biblical or Quranic text."
        ),
    },
    "39090": {
        "add": [],
        "remove": [],
        "reason": (
            "The existing topic is sound, but the description should identify the "
            "post's central use of the rumor as an example of oral transmission."
        ),
        "description": (
            "Uses a false rumor about Bart converting to Islam to explain how oral "
            "traditions can change even while eyewitnesses are available."
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
    if len(tracker.get("posts", [])) not in {500, 700}:
        raise ValueError("Tracker must contain the first 500 or 700 posts")

    valid_topics = {topic["name"] for topic in topics_data["topics"]}
    entries = list(tracker["posts"][:500])
    for source_index, post in enumerate(posts[500:700], start=500):
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
        entry["wpId"] for entry in entries[500:] if entry["status"] == "pending_approval"
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
                "First 700 canonical search-index posts in current newest-first order"
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
