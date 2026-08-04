"""Record post-topic audit recommendations for posts 1501-1750.

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


def rec(add=(), remove=(), reason=None):
    return {"add": list(add), "remove": list(remove), "reason": reason}


RECOMMENDATIONS = {
    "29150": rec(
        add=("Canon Formation",),
        reason=(
            "The post centrally asks why the Apocalypse of Peter was excluded from the "
            "New Testament and therefore substantially concerns canon formation."
        ),
    ),
    "29117": rec(
        add=("Galatians",),
        reason=(
            "The proposed location of Damascus is developed primarily through Paul's "
            "statements about Arabia and Damascus in Galatians."
        ),
    ),
    "29032": rec(
        add=("Jesus' Family Traditions",),
        reason=(
            "The post gives sustained attention to sayings about Jesus dividing families "
            "and rejecting conventional family obligations."
        ),
    ),
    "29016": rec(
        add=("Afterlife Journeys",),
        reason=(
            "The scholarly comparison gives sustained attention to ancient journeys through "
            "heaven, hell, and other postmortem realms."
        ),
    ),
    "28981": rec(
        add=(
            "Apocalyptic Jesus",
            "Pauline End-Time Expectations",
            "Pauline Salvation Models",
        ),
        reason=(
            "The post's central comparison concerns Jesus' apocalyptic proclamation and "
            "Paul's end-time and salvation message."
        ),
    ),
    "28977": rec(
        add=("Pauline Salvation Models", "Apocalyptic Jesus"),
        reason=(
            "The post directly compares Jesus' apocalyptic teaching with Paul's account of "
            "salvation and therefore develops both subjects at length."
        ),
    ),
    "28971": rec(
        add=("Fundamentalism",),
        reason=(
            "The guest post substantially reflects on rethinking beliefs inherited from a "
            "fundamentalist Christian setting."
        ),
    ),
    "28907": rec(
        add=("Apocalyptic Jesus",),
        reason=(
            "The post presents Jesus' proclamation of God's imminent kingdom as the heart of "
            "his message."
        ),
    ),
    "28966": rec(
        add=("Modern End-Times Interpretation",),
        reason=(
            "The post explains how chronological reform and the Common Era interacted with "
            "later calculations of the end of the world."
        ),
    ),
    "28939": rec(
        add=("Jesus' Ethics", "Apocalyptic Jesus"),
        reason=(
            "The post evaluates the practicality of Jesus' ethical demands in relation to his "
            "expectation of the imminent kingdom."
        ),
    ),
    "28809": rec(
        add=("Pauline Salvation Models", "Paul on Jewish Law"),
        remove=("Salvation (General)",),
        reason=(
            "The post specifically explains Paul's innovation in salvation and the law; the "
            "Pauline topics are more precise than the broad salvation label."
        ),
    ),
    "28747": rec(
        add=("Early Christian Diversity",),
        remove=("Early Christianity (General)",),
        reason=(
            "The post asks how decisive Paul was by comparing multiple forms and trajectories "
            "of earliest Christianity, making diversity the more precise topic."
        ),
    ),
    "28740": rec(
        add=("Jesus' Resurrection Appearances",),
        reason=(
            "The post examines whether Paul's experience and claims generated belief in Jesus' "
            "resurrection, with appearances central to the argument."
        ),
    ),
    "28478": rec(
        add=("Christian Anti-Judaism",),
        reason=(
            "The post centrally explains Christian reactions to Jewish nonacceptance of Jesus "
            "and the anti-Jewish consequences of those reactions."
        ),
    ),
    "28460": rec(
        add=("Modern End-Times Interpretation",),
        reason=(
            "The proposed Revelation book is framed around modern readings of the book as a "
            "prediction of events in the reader's own time."
        ),
    ),
    "28633": rec(
        add=("Hebrew Bible Composition and Sources",),
        reason=(
            "The post examines the composition and development of the Abraham and Isaac story "
            "rather than merely recounting it."
        ),
    ),
    "28353": rec(
        add=("Biblical Contradictions",),
        reason=(
            "The post's better-fundamentalist proposal is developed through sustained discussion "
            "of contradictions and other problems in the Bible."
        ),
    ),
    "28350": rec(
        add=("Biblical Inerrancy",),
        reason=(
            "The post's personal reflection focuses on leaving a form of fundamentalism defined "
            "by biblical inerrancy."
        ),
    ),
    "28014": rec(
        add=("Afterlife Journeys",),
        reason=(
            "Lucian's satire develops its argument about wealth through an extended journey to "
            "and depiction of the underworld."
        ),
    ),
    "28010": rec(
        add=("Afterlife Journeys",),
        reason=(
            "The satire's treatment of wealth is inseparable from its sustained underworld "
            "journey and postmortem reversal."
        ),
    ),
    "28151": rec(
        add=("Jesus' Family Traditions",),
        reason=(
            "The post continues a sustained analysis of sayings in which Jesus divides families "
            "or challenges family obligations."
        ),
    ),
    "27838": rec(
        add=("Historical Study of Miracles",),
        reason=(
            "The post evaluates demon-possession and exorcism accounts through historical and "
            "medical explanations."
        ),
    ),
    "27743": rec(
        add=("Acts", "Galatians"),
        remove=("Jewish Law and Torah",),
        reason=(
            "The proposed solution is built from the relationship between Acts and Galatians in "
            "reconstructing Paul's dispute over Jewish law; the Pauline law topic already covers "
            "that aspect more precisely."
        ),
    ),
    "27847": rec(
        add=("Ignore",),
        remove=("Public-Facing Scholarship",),
        reason=(
            "This is primarily an event and book-club announcement rather than a substantive "
            "discussion of scholarship."
        ),
    ),
    "27835": rec(
        add=("Jesus' Family Traditions",),
        reason=(
            "The post substantially analyzes sayings about Jesus disrupting family ties and "
            "conventional obligations."
        ),
    ),
    "27644": rec(
        add=("Translation Issues",),
        reason=(
            "The post centers on the interpretive decisions and uncertainties involved in "
            "translating the NRSV."
        ),
    ),
    "27618": rec(
        add=("Original Text Questions", "Translation Issues"),
        reason=(
            "The post asks which textual form translators should translate and explains how "
            "uncertainty about an original text affects translation."
        ),
    ),
    "27544": rec(
        add=("Translation Issues",),
        reason=(
            "The evaluation of the NRSV gives sustained attention to the choices and problems "
            "involved in producing a translation."
        ),
    ),
    "27490": rec(
        add=("Galatians",),
        remove=("Jewish Law and Torah",),
        reason=(
            "The argument is specifically grounded in Galatians and Paul's relations with the "
            "Jerusalem apostles; the existing Pauline law topic is more precise than the broad "
            "Jewish-law label."
        ),
    ),
    "27584": rec(
        add=("Christian Anti-Judaism",),
        reason=(
            "The guest post centrally traces how Christian responses to Jewish indifference to "
            "Jesus contributed to anti-Jewish claims."
        ),
    ),
    "27402": rec(
        add=("Apocalyptic Jesus",),
        reason=(
            "Jesus' teaching about eternal life is interpreted through his expectation of the "
            "coming kingdom and apocalyptic judgment."
        ),
    ),
    "27343": rec(
        add=("Apocalyptic Jesus",),
        reason=(
            "The salvation of the saints is explained as part of Jesus' apocalyptic expectation "
            "of the coming kingdom."
        ),
    ),
    "27340": rec(
        add=("Apocalyptic Jesus",),
        reason=(
            "The discussion of Gehenna is grounded in Jesus' apocalyptic teaching about imminent "
            "judgment and the coming kingdom."
        ),
    ),
    "27472": rec(
        add=("Ignore",),
        remove=("Book of Revelation", "Courses and Teaching"),
        reason=(
            "This is a logistical reminder for an upcoming lecture and does not itself provide "
            "substantive treatment of Revelation or teaching."
        ),
    ),
    "27378": rec(
        add=("Ignore",),
        remove=("Critical Biblical Scholarship",),
        reason=(
            "This is a link and announcement for a webinar recording rather than a substantive "
            "discussion of biblical scholarship."
        ),
    ),
    "27073": rec(
        add=("Archaeology and Material Evidence",),
        reason=(
            "Archaeological evidence is central to evaluating whether Israel's conquest of the "
            "promised land occurred as narrated."
        ),
    ),
    "27056": rec(
        add=("Moral Problems in Scripture",),
        reason=(
            "The post centrally confronts the moral problem of divinely commanded destruction "
            "in the Jericho narrative."
        ),
    ),
    "27163": rec(
        add=("Historical Study of Miracles",),
        reason=(
            "The guest post evaluates Jesus' healing stories through historical and medical "
            "questions about treatment and harm."
        ),
    ),
    "26858": rec(
        add=("Pauline Salvation Models", "Paul on Jewish Law"),
        remove=("Salvation (General)",),
        reason=(
            "The post specifically explains why Paul connected faith, salvation, and freedom "
            "from the Jewish law; the Pauline topics are more precise."
        ),
    ),
    "27082": rec(
        add=("Historical Study of Miracles",),
        reason=(
            "The guest post assesses Jesus' healing and possession stories using historical and "
            "medical explanations."
        ),
    ),
    "27012": rec(
        remove=("Paul and His Opponents",),
        reason=(
            "The post's sustained question is whether Cephas and Peter were the same person, not "
            "a conflict between Paul and an opponent."
        ),
    ),
    "27031": rec(
        add=("Gospel of Luke", "Biblical Contradictions"),
        reason=(
            "The nativity argument depends on Luke's account and its conflicts with Matthew's "
            "birth narrative."
        ),
    ),
    "26839": rec(
        remove=("Paul and His Opponents",),
        reason=(
            "The post asks whether Cephas and Peter were distinct people; Pauline opposition is "
            "not its sustained subject."
        ),
    ),
    "26835": rec(
        remove=("Paul and His Opponents",),
        reason=(
            "The evidence is directed toward distinguishing Cephas from Peter rather than "
            "analyzing Paul's opponents."
        ),
    ),
    "26811": rec(
        remove=("Paul and His Opponents",),
        reason=(
            "The post's central issue is the identity of Cephas and Peter, not Paul's conflict "
            "with an opposing teacher or faction."
        ),
    ),
    "26959": rec(
        add=("Historical Study of Miracles",),
        reason=(
            "The guest post examines paralysis-healing stories through historical and medical "
            "explanations."
        ),
    ),
    "26865": rec(
        add=("Historical Study of Miracles",),
        reason=(
            "The guest post evaluates demonic-possession and healing traditions through "
            "historical and medical explanations."
        ),
    ),
    "26667": rec(
        add=("Blog Updates and Fundraising",),
        reason=(
            "The lecture-series notice is also explicitly a blog fundraiser, making fundraising "
            "a major purpose of the post."
        ),
    ),
    "26586": rec(
        add=("Book of Revelation",),
        reason=(
            "The distinction between apocalypse and apocalypticism is developed specifically to "
            "situate and interpret the book of Revelation."
        ),
    ),
    "26546": rec(
        add=("Writing and Publishing Process",),
        reason=(
            "The recollection substantially concerns Metzger's detailed criticism of Bart's "
            "dissertation proposal and the resulting effort to become a better writer."
        ),
    ),
    "26526": rec(
        add=("Jewish Apocalypticism",),
        reason=(
            "The post's principal answer explains why belief in Jesus' resurrection arose from "
            "Jewish apocalypticism rather than pagan fertility-god traditions."
        ),
    ),
    "26509": rec(
        add=("Mythicism",),
        reason=(
            "The post directly evaluates mythicist claims that Jesus' resurrection was modeled "
            "on pagan gods and that early Christians invented a nonhistorical Christ."
        ),
    ),
    "26454": rec(
        add=("Mythicism",),
        reason=(
            "Both questions directly evaluate mythicist claims that Jesus traditions were "
            "invented from Jewish or pagan literary models."
        ),
    ),
    "26449": rec(
        add=("Archaeology and Material Evidence",),
        reason=(
            "A major part evaluates archaeological evidence for first-century Nazareth as a "
            "direct test of a mythicist claim."
        ),
    ),
    "26387": rec(
        add=("Mythicism",),
        reason=(
            "The historical criteria are presented throughout as a response to mythicist claims "
            "about the sources and existence of Jesus."
        ),
    ),
    "26382": rec(
        add=("Mythicism",),
        reason=(
            "The post assembles historical evidence for Jesus specifically to answer mythicist "
            "denials of his existence."
        ),
    ),
    "26348": rec(
        add=("Methods for Studying the Historical Jesus",),
        reason=(
            "The post evaluates literary, non-Christian, archaeological, and later evidence for "
            "Jesus using explicit historical-source criteria."
        ),
    ),
    "26343": rec(
        add=("Did Jesus Exist?", "Mythicism"),
        reason=(
            "The exchange substantively presents the evidence and historical reasoning of Did "
            "Jesus Exist? in direct response to mythicist arguments."
        ),
    ),
    "26081": rec(
        add=("Gospel of John",),
        reason=(
            "Most of the post analyzes John's distinctive speeches, Christology, signs, and "
            "Farewell Discourse as the setting for the Spirit's arrival."
        ),
    ),
    "26033": rec(
        add=("Apocalyptic Jesus",),
        reason=(
            "The interpretation of the unforgivable sin depends substantially on Jesus' "
            "apocalyptic meaning of the present age and the age to come."
        ),
    ),
    "26338": rec(
        add=("Ignore",),
        remove=("Non-Canonical Gospel Traditions",),
        reason=(
            "This is a logistical fundraising-lecture announcement and does not itself develop "
            "the noncanonical infancy traditions it advertises."
        ),
    ),
    "26011": rec(
        add=("Development of the Trinity",),
        reason=(
            "The post explicitly uses early Christian ideas about the Spirit in Jesus' life to "
            "explain a step toward later Trinitarian thought."
        ),
    ),
    "25919": rec(
        add=("1 Corinthians",),
        reason=(
            "The central analysis closely reads 1 Corinthians 12-14 to explain spiritual gifts, "
            "authority, and community organization."
        ),
    ),
    "25914": rec(
        add=("Misquoting Jesus",),
        reason=(
            "A major portion uses Misquoting Jesus, its claims, and responses to it as the central "
            "case study for accusations of sensationalism."
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
    if len(tracker.get("posts", [])) not in {1500, 1750}:
        raise ValueError("Tracker must contain the first 1500 or 1750 posts")

    valid_topics = {topic["name"] for topic in topics_data["topics"]}
    entries = list(tracker["posts"][:1500])
    for source_index, post in enumerate(posts[1500:1750], start=1500):
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
        entries.append(
            {
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
        )

    expected_recommendations = set(RECOMMENDATIONS)
    recorded_recommendations = {
        entry["wpId"] for entry in entries[1500:] if entry["status"] == "pending_approval"
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
                "First 1750 canonical search-index posts in current newest-first order"
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
