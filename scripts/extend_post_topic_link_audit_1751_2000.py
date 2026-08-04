"""Record post-topic audit recommendations for posts 1751-2000.

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
    return {
        "add": list(add),
        "remove": list(remove),
        "reason": reason,
        "description": description,
    }


RECOMMENDATIONS = {
    "26112": rec(
        add=("Heaven and Hell Beliefs",),
        remove=("Life After Death (General)",),
        reason="The post surveys contemporary belief in heaven and hell, making the specific topic more accurate than the broad afterlife topic.",
    ),
    "26018": rec(
        add=("Blog Updates and Fundraising",),
        reason="The post announces online lectures and explicitly presents the series as a blog fundraiser.",
    ),
    "25879": rec(
        add=("1 Corinthians", "Pauline Salvation Models"),
        reason="The post gives sustained attention to 1 Corinthians 15 and Paul's crucifixion-and-resurrection message of salvation to the Corinthians.",
    ),
    "25875": rec(
        add=("1 Corinthians",),
        reason="The reconstruction is developed primarily from 1 Corinthians and the problems addressed in that letter.",
    ),
    "25872": rec(
        add=("1 Corinthians",),
        reason="The post focuses on 1 Corinthians and problems within the Corinthian church.",
    ),
    "25745": rec(
        add=("1 Thessalonians",),
        reason="The argument is developed through a close reading of 1 Thessalonians 4-5.",
    ),
    "25705": rec(
        add=("Pauline End-Time Expectations", "Paul on Resurrection"),
        reason="Much of the post explains Paul's resurrection-based expectation of the imminent end as background for the Spirit's place in later Trinitarian thought.",
    ),
    "25333": rec(
        add=("Mary Magdalene", "Historical Study of Miracles"),
        reason="The post gives sustained attention to Mary Magdalene and to historical, medical, and social explanations of healing and demon-possession traditions.",
    ),
    "25638": rec(
        add=("Genesis", "Translation Issues"),
        reason="Most of the post examines the translation of Genesis 1:1-2 and how Christians later interpreted it.",
    ),
    "25517": rec(
        add=("Petrine Authorship and Forgeries", "Jewish Law and Torah"),
        reason="The post centers on the forged Letter of Peter to James and its dispute over Mosaic law and Paul.",
    ),
    "25439": rec(
        add=("Non-Canonical Gospel Traditions",),
        reason="The post translates and discusses the Narrative of Joseph of Arimathea, a later noncanonical gospel text.",
    ),
    "25436": rec(
        add=("Non-Canonical Gospel Traditions",),
        reason="The post introduces and translates the Narrative of Joseph of Arimathea, a later noncanonical gospel text.",
    ),
    "25141": rec(
        add=("Historical Jesus (General)",),
        reason="The post directly evaluates the historical question of whether Jesus could read using ancient literacy evidence and Gospel accounts.",
    ),
    "25446": rec(
        add=("Ignore",),
        remove=("Christian Anti-Judaism",),
        reason="This is an event and fundraising announcement; anti-Judaism is only the subject of one short lecture description.",
    ),
    "25034": rec(
        remove=("Gnostic and Orthodox Conflicts",),
        reason="The post compares Marcion's theology with Gnostic teachings but does not substantially discuss conflict between Gnostics and proto-orthodox Christians.",
    ),
    "24590": rec(
        add=("Visionary Experiences",),
        reason="The post argues at length that visions led some disciples to believe Jesus had been raised.",
    ),
    "24957": rec(
        add=("Heaven and Hell Beliefs", "Resurrection of the Dead"),
        reason="The post contrasts Jesus' expectation of bodily resurrection with later Christian belief in souls rewarded in heaven or punished in hell.",
    ),
    "25148": rec(
        add=("Ignore",),
        remove=("Moral Philosophy",),
        reason="This is a lecture and fundraising announcement; Pauline ethics appears only in the brief description of the advertised lecture.",
    ),
    "24729": rec(
        add=("Christian Anti-Judaism",),
        reason="A substantial part explains the triumphal-entry memory through later Christian antagonism toward Jews and portrayals of Jewish rejection.",
    ),
    "24528": rec(
        add=("Pauline Epistles (General)",),
        reason="The post's first and sustained example concerns Paul's surviving and lost letters.",
    ),
    "24762": rec(
        add=("Blog Updates and Fundraising",),
        remove=("Crucifixion of Jesus", "Paul's Life and Career", "Resurrection of Jesus"),
        reason="The post announces two lectures and raises funds for the blog; the removed topics occur only in short lecture descriptions.",
    ),
    "24468": rec(
        add=("Apostolic Fathers",),
        remove=("Incarnation Christology",),
        reason="The post explains Ignatius's opposition to docetism rather than incarnation Christology; Ignatius and his letters occupy most of the discussion.",
    ),
    "24393": rec(
        add=("Genesis",),
        reason="The post uses the two creation accounts in Genesis 1-3 as its sustained case study for understanding the Bible's composition and strangeness.",
    ),
    "24547": rec(
        add=("Comparative Ancient Evidence",),
        reason="The entire post compares passages in Matthew with ancient Buddhist writings.",
    ),
    "24163": rec(
        remove=("Christology (General)",),
        reason="Christology supplies the thread's background, but this post focuses on anthropomorphic appearances of God and angels in the Hebrew Bible.",
    ),
    "24086": rec(
        add=("Exaltation Christology",),
        reason="The changed interpretation rests on the argument that the Synoptic Gospels portray Jesus through forms of exaltation Christology.",
    ),
    "24080": rec(
        add=("Exaltation Christology",),
        remove=("Divine Beings in the Hebrew Bible",),
        reason="The post compares exaltation and incarnation Christologies against ancient concepts of divinity rather than examining Hebrew Bible divine beings.",
    ),
    "24247": rec(
        add=("Blog Updates and Fundraising", "Courses and Teaching"),
        remove=("Gnosticism (General)", "Gospel of John"),
        reason="This is a lecture and fundraising announcement; Gnosticism and John appear only in short descriptions of the advertised lectures.",
    ),
    "23997": rec(
        add=("General-Audience Books",),
        reason="The post introduces and excerpts a book written to make critical biblical scholarship accessible to general readers.",
    ),
    "24238": rec(
        add=("Comparative Ancient Evidence",),
        reason="The post is explicitly structured around comparisons between Luke and ancient Buddhist writings.",
    ),
    "23968": rec(
        add=("Christian Interpretation of Jewish Scripture",),
        reason="The post compares John's Logos with Proverbs, Wisdom of Solomon, Genesis, and other Jewish traditions.",
    ),
    "23938": rec(
        add=("Charity and Altruism", "Jesus on Wealth and Poverty"),
        reason="The post gives sustained attention to Jesus' teachings on wealth, poverty, generosity, and practical care for disadvantaged people.",
    ),
    "23935": rec(
        add=("Theologically Significant Variants",),
        reason="The argument repeatedly turns on the baptismal wording in Luke 3:22 and its Christological alteration by scribes.",
    ),
    "24108": rec(
        add=("Blog Updates and Fundraising",),
        remove=("Gospel of John",),
        reason="This is an event and fundraising announcement; John appears only in a brief lecture description.",
    ),
    "23289": rec(
        add=("Writing and Publishing Process",),
        reason="Several interview questions directly concern writing style, transitions, research, and book production.",
    ),
    "23948": rec(
        add=("Blog Updates and Fundraising",),
        remove=("Early Judaism (General)", "Gospel of Matthew", "Jewish Law and Torah"),
        reason="This is an event and fundraising announcement; the removed subjects occur only in one brief lecture description.",
    ),
    "23386": rec(
        add=("Theologically Significant Variants",),
        reason="The entire post develops Luke 3:22's baptismal variant and its adoptionist or exaltation implications.",
    ),
    "23381": rec(
        add=("Exaltation Christology", "Incarnation Christology"),
        reason="The post traces movement from resurrection, baptism, and birth exaltation views to preexistent incarnation views.",
    ),
    "23678": rec(
        remove=("Miracle Traditions (General)",),
        reason="The essay focuses overwhelmingly on the meaning and problem of suffering; miracle stories are limited supporting examples.",
    ),
    "23663": rec(
        add=("Blog Updates and Fundraising",),
        remove=("Canonical Gospels (General)",),
        reason="This is an event and fundraising announcement; Gospel content appears only in brief lecture descriptions.",
    ),
    "23521": rec(
        add=("Blog Updates and Fundraising",),
        remove=("Canonical Gospels (General)",),
        reason="This is an event and fundraising announcement; Gospel subjects appear only in brief lecture descriptions.",
    ),
    "23246": rec(
        add=("Source Criticism",),
        reason="Most of the post explains how scholars identify preliterary creeds, hymns, and traditions quoted within New Testament writings.",
    ),
    "23213": rec(
        add=("Atheism",),
        reason="The post centrally explains a materialist, non-supernatural worldview and its implications for consciousness and free will.",
    ),
    "23135": rec(
        remove=("Christology (General)",),
        reason="The post itself concerns divine titles for Israelite kings; Christology is only the wider series background.",
    ),
    "23276": rec(
        add=("Jesus on Wealth and Poverty",),
        reason="The argument repeatedly centers Jesus' teaching about wealth, poverty, care for the needy, and the prosperity gospel.",
    ),
    "23049": rec(
        add=("Greco-Roman Religious Culture",),
        reason="Most of the post explains Greco-Roman traditions of human divinization as background to Christian claims about Jesus.",
    ),
    "23181": rec(
        add=("Comparative Ancient Evidence",),
        reason="The entire proposal compares Buddhist texts and traditions with Luke.",
    ),
    "22991": rec(
        add=("Greco-Roman Religious Culture",),
        reason="A major portion frames Jesus' divinity in relation to emperor worship and Greco-Roman divine-human traditions.",
    ),
    "22950": rec(
        add=("Textual Variants", "King James Version"),
        reason="The post centrally traces the Johannine Comma through Erasmus's editions into the King James Bible.",
    ),
    "22945": rec(
        add=("Textual Variants", "New Testament Manuscripts"),
        reason="The post centers on the Johannine Comma's manuscript history and early printed Greek New Testament editions.",
    ),
    "22813": rec(
        add=("Jesus' Ethics", "Jesus on Wealth and Poverty"),
        reason="Much of the post reflects on Jesus' teaching about peace, wealth, poverty, marginalization, and care for others.",
    ),
    "22670": rec(
        add=("Biblical Contradictions",),
        reason="A sustained part compares Luke with Matthew and argues that their birth accounts conflict and are historically implausible.",
    ),
    "22663": rec(
        add=("Biblical Contradictions",),
        reason="The post explicitly sets up and evaluates contradictions between Matthew and Luke's birth accounts.",
    ),
    "22610": rec(
        add=("Fundamentalism",),
        reason="A major sustained argument concerns fundamentalist claims that belief in the virgin birth is required for Christian identity.",
    ),
    "22604": rec(
        add=("Biblical Contradictions",),
        reason="The article centrally lays out contradictions and historical problems in Matthew and Luke.",
    ),
    "22572": rec(
        add=("Proto-Gospel of James",),
        reason="Most of this installment explains the Proto-Gospel's birth and infancy traditions.",
    ),
    "22720": rec(
        add=("Blog Updates and Fundraising",),
        remove=("Ignore",),
        reason="The post is explicitly a year-end donation appeal for the blog's charities.",
    ),
    "22529": rec(
        add=("Jesus' Birth Narratives",),
        reason="The post introduces and translates a noncanonical account of Mary and Joseph leading into Jesus' birth.",
    ),
    "22231": rec(
        add=("Ancient Literacy",),
        remove=("Church Fathers as Textual Evidence",),
        reason="The central subject is oral reading and performance of written Christian texts in largely illiterate congregations; Didymus is supporting evidence.",
    ),
    "22094": rec(
        add=("Jesus' Resurrection Appearances",),
        reason="The core comparison concerns competing traditions about whether Peter or Mary first saw and proclaimed the risen Jesus.",
    ),
    "22062": rec(
        add=("Gospel of Luke", "Biblical Contradictions"),
        reason="The post directly compares both Gospels and emphasizes irreconcilable genealogical differences.",
    ),
    "22055": rec(
        remove=("Heaven and Hell",),
        reason="This is only an event announcement; Heaven and Hell appears as the lecture topic without substantive treatment.",
    ),
    "21862": rec(
        add=("Christian Anti-Judaism",),
        reason="The explanation of the Barabbas tradition centrally involves the Gospel tendency to shift blame from Pilate to Jews.",
    ),
    "21433": rec(
        add=("Theologically Significant Variants",),
        reason="The post argues at length that textual variants affect passages and books of theological significance.",
    ),
    "21421": rec(
        add=("Apostolic Death Traditions", "Resurrection Arguments and Apologetics"),
        remove=("Apocryphal Acts",),
        reason="The post evaluates the apologetic claim that the apostles died for resurrection belief; apocryphal Acts supply only unreliable supporting traditions.",
    ),
    "21415": rec(
        add=("Fundamentalism",),
        reason="A sustained part examines conflicts between critical biblical scholarship and fundamentalist commitments in evangelical institutions.",
    ),
    "21398": rec(
        add=("Resurrection Arguments and Apologetics",),
        remove=("Canonical Gospels (General)",),
        reason="The post directly challenges an apologetic argument about women discovering the empty tomb; the broad Gospel topic is less precise.",
    ),
    "21392": rec(
        add=("Blog Updates and Fundraising", "Personal Reflections"),
        remove=("Ignore",),
        reason="The post reflects on a milestone birthday while celebrating the blog's having raised one million dollars for charity.",
        description="Reflects on Bart's sixty-fifth birthday while celebrating the blog's having raised one million dollars for charity.",
    ),
    "21385": rec(
        add=("Archaeology and Material Evidence", "Luke-Acts Authorship"),
        reason="The post evaluates an archaeological argument for eyewitness authorship of Acts and explains why accurate geography does not establish eyewitness testimony.",
    ),
    "21376": rec(
        remove=("Agnosticism",),
        reason="The post explicitly presents an atheist and materialist outlook; agnosticism is not a sustained position in the discussion.",
    ),
    "21368": rec(
        add=("Non-Canonical Gospel Traditions", "Trial of Jesus"),
        reason="The post introduces and translates the Gospel of Nicodemus's expanded trial scene before Pilate.",
    ),
    "21355": rec(
        add=("Non-Canonical Gospel Traditions",),
        reason="The post is a sustained introduction to the Gospel of Nicodemus, its date, contents, manuscript tradition, and purposes.",
    ),
    "21324": rec(
        add=("Christian Anti-Judaism",),
        reason="The selected Gospel of Peter material and its introduction give sustained attention to the text's hostile portrayal of Jews.",
    ),
    "21317": rec(
        add=("Manuscript Discoveries and Controversies",),
        reason="Papyrus Oxyrhynchus 4009 and the debate over whether it preserves the Gospel of Peter are central to the post.",
    ),
    "21311": rec(
        add=("Christian Anti-Judaism",),
        reason="The overview repeatedly emphasizes the Gospel of Peter's transfer of blame from Pilate to Jews.",
    ),
    "21300": rec(
        add=("Comparative Ancient Evidence",),
        reason="The post substantially compares Thomas-as-Jesus's-twin traditions with Greco-Roman stories of divine and mortal twins.",
    ),
    "21255": rec(
        add=("Courses and Teaching", "Canonical Gospels (General)", "Biblical Contradictions"),
        reason="The post uses a class quiz to discuss noncanonical Gospels, the character of canonical Gospels, and a sustained Gospel contradiction.",
    ),
    "21245": rec(
        add=("Atonement in Luke-Acts",),
        reason="The comparison of Mark and Luke gives sustained attention to their different presentations of Jesus' death as atonement.",
    ),
    "21223": rec(
        add=("Gospel of Mark", "Gospel of Luke"),
        reason="The entire post compares Mark's distressed Jesus with Luke's calm and controlled Jesus in the passion narrative.",
    ),
    "21220": rec(
        add=("Dating Ancient Texts",),
        reason="A substantial part examines the evidence and uncertainty involved in dating the Infancy Gospel of Thomas.",
    ),
    "21214": rec(
        add=("Miracle Stories in Non-Canonical Texts",),
        reason="The post introduces the Infancy Gospel of Thomas through a sustained selection of its miracle stories about the boy Jesus.",
    ),
    "21202": rec(
        add=("Gospel Authorship",),
        reason="A major part explains the anonymity of the canonical Gospels and the later attribution of their traditional names.",
    ),
    "21163": rec(
        remove=("Heaven and Hell Beliefs",),
        reason="The guest post is specifically about Paul's ascent to paradise and ancient heavenly journeys, not a broader heaven-and-hell belief system.",
    ),
    "21159": rec(
        add=("Apocalyptic Jesus",),
        remove=("Eternal Punishment",),
        reason="The post explains the saying through Jesus' apocalyptic expectation of God's earthly kingdom; eternal punishment is not its sustained subject.",
        description="Explains the unforgivable sin in Matthew, why the passage does not teach purgatory, and how Jesus' apocalyptic expectation clarifies the saying.",
    ),
    "21145": rec(
        remove=("Academic Careers and University Life",),
        reason="The post presents a course syllabus on early Christian apocrypha rather than discussing academic careers or university life as a subject.",
    ),
    "21139": rec(
        add=("Apocalyptic Jesus",),
        reason="The explanation identifies Jesus' Son of Man teaching with his expectation of an imminent cosmic judge and earthly kingdom.",
    ),
    "21069": rec(
        add=("Media Interviews and Videos",),
        reason="The post shares a recorded lecture on the history of heaven and hell.",
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
    if len(tracker.get("posts", [])) not in {1750, 2000}:
        raise ValueError("Tracker must contain the first 1750 or 2000 posts")

    valid_topics = {topic["name"] for topic in topics_data["topics"]}
    entries = list(tracker["posts"][:1750])
    descriptions = []
    for source_index, post in enumerate(posts[1750:2000], start=1750):
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
        entry["wpId"] for entry in entries[1750:] if entry["status"] == "pending_approval"
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
            "auditScope": "First 2000 canonical search-index posts in current newest-first order",
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
    print(
        f"Recorded {len(entries)} reviewed posts: "
        f"{tracker['noChangeCount']} no change, "
        f"{tracker['appliedChangeCount']} applied, "
        f"{tracker['pendingApprovalCount']} pending approval, "
        f"{len(descriptions)} description recommendations."
    )


if __name__ == "__main__":
    main()
