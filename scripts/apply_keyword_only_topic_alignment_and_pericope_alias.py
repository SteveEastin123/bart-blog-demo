"""Apply the approved keyword-only topic audit and Pericope search alias."""

from __future__ import annotations

from collections import Counter
import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
TOPICS_PATH = ROOT / "data" / "index" / "ehrman_post_topics.json"
AUDIT_PATH = (
    ROOT
    / "data"
    / "audits"
    / "keyword_only_topic_alignment_and_pericope_alias_2026_08_19_secondary_keyword_audit.json"
)

EXPECTED_UNIQUE_KEYWORDS = 908
CANONICAL_TOPIC = "Woman Caught in Adultery"
SEARCH_ALIAS = "Pericope Adulterae"
EXPECTED_ALIAS_POSTS = 10

KEYWORD_AUDITS = {
    "Biblical Discrepancies": {
        "expected_keyword_only_ids": {"50235"},
        "topic": "Biblical Contradictions",
        "add_topic_ids": set(),
        "remove_keyword_ids": set(),
    },
    "New Testament Canon": {
        "expected_keyword_only_ids": {
            "40572",
            "37235",
            "15532",
            "15417",
            "4947",
            "2268",
        },
        "topic": "Canon Formation",
        "add_topic_ids": set(),
        "remove_keyword_ids": {"4947"},
    },
    "Eyewitness Testimony": {
        "expected_keyword_only_ids": {
            "50150",
            "40554",
            "38752",
            "38718",
            "35561",
            "26382",
            "20545",
            "17501",
            "17480",
            "17477",
            "16952",
            "15366",
            "15332",
            "15133",
            "12413",
            "11999",
            "10892",
            "10711",
            "10296",
            "9518",
            "8839",
            "8757",
            "8236",
            "7491",
            "6194",
            "4768",
            "4343",
            "2263",
        },
        "topic": "Eyewitness Reliability",
        "add_topic_ids": {
            "38752",
            "38718",
            "20545",
            "17501",
            "17480",
            "17477",
            "16952",
            "12413",
            "10892",
            "10711",
            "8757",
            "7491",
            "4768",
        },
        "remove_keyword_ids": {"50150", "4343"},
    },
}

DECISION_REASONS = {
    "50235": (
        "Biblical discrepancies are a meaningful cause in the post's account of "
        "doubt, but the post does not sustain an analysis of contradictions."
    ),
    "40572": (
        "The New Testament canon meaningfully frames the argument about reading "
        "Paul through later Christian categories, but canon formation is not the main subject."
    ),
    "37235": (
        "Canon boundaries help explain the Nag Hammadi collection and its concealment, "
        "but the post centers on the library and its contents."
    ),
    "15532": (
        "Revelation's disputed place in the canon is meaningful evidence in the authorship "
        "discussion, but canon formation is not the post's primary subject."
    ),
    "15417": (
        "The developing canon helps explain why the Nag Hammadi writings may have been hidden, "
        "but the discovery and collection remain central."
    ),
    "4947": (
        "The canon appears only in an opening correction of a Constantine myth; the sustained "
        "discussion concerns Constantine, persecution, and conversion."
    ),
    "2268": (
        "Canon formation is one substantial unit in the seminar description, but the post "
        "surveys several forms of Christian apocrypha rather than centering on the canon."
    ),
    "50150": (
        "The work is merely introduced as an alleged eyewitness account; the post centers on "
        "Polycarp's martyrdom narrative and its miraculous features."
    ),
    "40554": (
        "Eyewitness reports are a meaningful methodological example in a broader discussion "
        "of presuppositions and miracle claims."
    ),
    "38752": (
        "The rebuttal repeatedly evaluates whether Acts rests on eyewitness knowledge, making "
        "eyewitness reliability a major part of the argument."
    ),
    "38718": (
        "The affirmative case repeatedly appeals to the author's claimed eyewitness presence, "
        "making eyewitness reliability a major subject."
    ),
    "35561": (
        "Eyewitness access is an important premise in the post's account of how Jesus traditions "
        "circulated, while oral transmission remains the dominant subject."
    ),
    "26382": (
        "Near-eyewitness testimony from Peter and James supports the evidence for Jesus, but the "
        "post's main focus is the historical case for Jesus' existence."
    ),
    "20545": (
        "The post gives sustained attention to source proximity and eyewitness access when "
        "evaluating whether Judas was invented."
    ),
    "17501": (
        "The argument about the Acts author's identity substantially turns on whether the 'we' "
        "passages establish eyewitness participation."
    ),
    "17480": (
        "The credibility and evidentiary force of eyewitness miracle reports are sustained "
        "subjects of the guest post."
    ),
    "17477": (
        "The authorship discussion substantially evaluates whether Luke-Acts came from an "
        "eyewitness companion of Paul."
    ),
    "16952": (
        "The historicity of Judas is assessed through source proximity and eyewitness access, "
        "making eyewitness reliability a sustained issue."
    ),
    "15366": (
        "Eyewitness reports are meaningful evidence within the post's broader historical "
        "argument about miracle claims."
    ),
    "15332": (
        "Eyewitness proximity is a meaningful criterion on the historian's ideal-source list, "
        "but the post addresses historical evidence more broadly."
    ),
    "15133": (
        "The alleged manuscript's supposed connection to eyewitness testimony is materially "
        "addressed, but the post centers on the manuscript claim and apologetic misuse."
    ),
    "12413": (
        "The post directly examines the reliability of group reports, false memory, and claimed "
        "eyewitness visions."
    ),
    "11999": (
        "Eyewitness reports are a meaningful example in a broader treatment of presuppositions "
        "and historical miracle claims."
    ),
    "10892": (
        "The rebuttal repeatedly evaluates whether Acts rests on eyewitness knowledge, making "
        "eyewitness reliability a major part of the argument."
    ),
    "10711": (
        "The affirmative argument repeatedly relies on the Acts author's claimed eyewitness "
        "presence, making eyewitness reliability a major subject."
    ),
    "10296": (
        "The reliability of eyewitness memory is a meaningful issue in the response to reviews "
        "of Jesus Before the Gospels, but the post is not chiefly a study of eyewitnesses."
    ),
    "9518": (
        "Eyewitness testimony is an explicit component of the book's subject and marketing "
        "description, while the post primarily announces Jesus Before the Gospels."
    ),
    "8839": (
        "Papias's reported chain of oral and eyewitness transmission materially supports the "
        "discussion, though the saying and Papias remain central."
    ),
    "8757": (
        "The post directly uses a claimed eyewitness report to examine memory and the historical "
        "credibility of a Jewish miracle story."
    ),
    "8236": (
        "Luke's claim to rely on eyewitness traditions is materially evaluated within the larger "
        "argument for anonymous Gospel authorship."
    ),
    "7491": (
        "The post gives sustained attention to eyewitness memory and transmission when comparing "
        "Brian's Jesus with the historical Jesus."
    ),
    "6194": (
        "Eyewitness reports are meaningful evidence within the broader methodological argument "
        "about what historians can conclude about miracles."
    ),
    "4768": (
        "The authorship discussion substantially turns on whether the 'we' passages establish an "
        "eyewitness companion of Paul."
    ),
    "4343": (
        "Joshua's lack of eyewitness status is only an opening observation; contradictions, "
        "archaeology, and the conquest's historicity dominate the post."
    ),
    "2263": (
        "Near-eyewitness testimony is meaningful evidence in the exchange, but the post centers "
        "on the broader historical case for Jesus."
    ),
}


def normalize(value: str) -> str:
    """Normalize labels consistently with the search implementations."""
    text = value.casefold().replace("&", " and ")
    return " ".join("".join(char if char.isalnum() else " " for char in text).split())


def read_json(path: Path) -> object:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value: object) -> None:
    path.write_text(
        json.dumps(value, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )


def keyword_counts(posts: list[dict[str, object]]) -> Counter[str]:
    return Counter(
        keyword
        for post in posts
        for keyword in post.get("secondaryKeywords", [])
    )


def main() -> int:
    posts = read_json(POSTS_PATH)
    topics_document = read_json(TOPICS_PATH)
    if not isinstance(posts, list):
        raise TypeError("Post search index must be a JSON array")
    if not isinstance(topics_document, dict) or not isinstance(topics_document.get("topics"), list):
        raise TypeError("Topic index must contain a topics array")

    counts_before = keyword_counts(posts)
    if len(counts_before) != EXPECTED_UNIQUE_KEYWORDS:
        raise ValueError(
            f"Expected {EXPECTED_UNIQUE_KEYWORDS} unique keywords; found {len(counts_before)}"
        )
    if counts_before.get(SEARCH_ALIAS) != EXPECTED_ALIAS_POSTS:
        raise ValueError(
            f"Expected {EXPECTED_ALIAS_POSTS} {SEARCH_ALIAS!r} links; "
            f"found {counts_before.get(SEARCH_ALIAS, 0)}"
        )

    topic_records = {
        str(topic.get("name", "")): topic
        for topic in topics_document["topics"]
        if isinstance(topic, dict)
    }
    for topic_name in {
        CANONICAL_TOPIC,
        *(str(audit["topic"]) for audit in KEYWORD_AUDITS.values()),
    }:
        if topic_name not in topic_records:
            raise ValueError(f"Missing topic metadata for {topic_name!r}")

    canonical_record = topic_records[CANONICAL_TOPIC]
    existing_aliases = canonical_record.get("aliases", [])
    if existing_aliases not in ([], None):
        raise ValueError(
            f"Expected no existing aliases on {CANONICAL_TOPIC!r}; found {existing_aliases!r}"
        )

    posts_by_id = {str(post["wpId"]): post for post in posts}
    all_expected_ids = {
        post_id
        for audit in KEYWORD_AUDITS.values()
        for post_id in audit["expected_keyword_only_ids"]
    }
    if len(all_expected_ids) != 35:
        raise ValueError(f"Expected 35 keyword-only posts; configured {len(all_expected_ids)}")
    missing_ids = sorted(all_expected_ids - posts_by_id.keys())
    if missing_ids:
        raise ValueError(f"Reviewed posts are missing from the index: {missing_ids}")

    for keyword, audit in KEYWORD_AUDITS.items():
        topic_name = str(audit["topic"])
        actual_keyword_only_ids = {
            str(post["wpId"])
            for post in posts
            if keyword in post.get("secondaryKeywords", [])
            and topic_name not in post.get("topics", [])
        }
        expected_ids = audit["expected_keyword_only_ids"]
        if actual_keyword_only_ids != expected_ids:
            raise ValueError(
                f"Keyword-only set for {keyword!r} changed; "
                f"missing={sorted(expected_ids - actual_keyword_only_ids)}, "
                f"unexpected={sorted(actual_keyword_only_ids - expected_ids)}"
            )

    removed_keywords: list[dict[str, str]] = []
    added_topics: list[dict[str, str]] = []
    retained_keywords: list[dict[str, str]] = []
    alias_removed: list[dict[str, str]] = []

    for post in posts:
        post_id = str(post["wpId"])
        title = str(post["title"])
        topics = list(post.get("topics", []))
        keywords = list(post.get("secondaryKeywords", []))

        if SEARCH_ALIAS in keywords:
            if CANONICAL_TOPIC not in topics:
                raise ValueError(
                    f"Post {post_id} has {SEARCH_ALIAS!r} without {CANONICAL_TOPIC!r}"
                )
            keywords.remove(SEARCH_ALIAS)
            alias_removed.append({"wpId": post_id, "title": title})

        for keyword, audit in KEYWORD_AUDITS.items():
            if post_id not in audit["expected_keyword_only_ids"]:
                continue
            topic_name = str(audit["topic"])
            reason = DECISION_REASONS[post_id]
            if post_id in audit["remove_keyword_ids"]:
                keywords.remove(keyword)
                removed_keywords.append(
                    {"wpId": post_id, "title": title, "keyword": keyword, "reason": reason}
                )
                continue
            retained_keywords.append(
                {"wpId": post_id, "title": title, "keyword": keyword, "reason": reason}
            )
            if post_id in audit["add_topic_ids"]:
                topics.append(topic_name)
                added_topics.append(
                    {"wpId": post_id, "title": title, "topic": topic_name, "reason": reason}
                )

        post["topics"] = list(dict.fromkeys(topics))
        post["secondaryKeywords"] = list(dict.fromkeys(keywords))

    if len(alias_removed) != EXPECTED_ALIAS_POSTS:
        raise ValueError(f"Expected {EXPECTED_ALIAS_POSTS} alias keyword removals; got {len(alias_removed)}")
    if len(removed_keywords) != 3:
        raise ValueError(f"Expected 3 audited keyword removals; got {len(removed_keywords)}")
    if len(added_topics) != 13:
        raise ValueError(f"Expected 13 topic additions; got {len(added_topics)}")
    if len(retained_keywords) != 32:
        raise ValueError(f"Expected 32 retained keyword links; got {len(retained_keywords)}")

    canonical_record["aliases"] = [SEARCH_ALIAS]

    duplicate_keyword_posts: list[str] = []
    duplicate_topic_posts: list[str] = []
    topic_keyword_overlap_posts: list[str] = []
    for post in posts:
        normalized_topics = [normalize(str(topic)) for topic in post.get("topics", [])]
        normalized_keywords = [
            normalize(str(keyword)) for keyword in post.get("secondaryKeywords", [])
        ]
        if len(normalized_topics) != len(set(normalized_topics)):
            duplicate_topic_posts.append(str(post["wpId"]))
        if len(normalized_keywords) != len(set(normalized_keywords)):
            duplicate_keyword_posts.append(str(post["wpId"]))
        if set(normalized_topics).intersection(normalized_keywords):
            topic_keyword_overlap_posts.append(str(post["wpId"]))
    if duplicate_topic_posts or duplicate_keyword_posts or topic_keyword_overlap_posts:
        raise ValueError(
            "Post label validation failed: "
            f"duplicate_topics={duplicate_topic_posts}, "
            f"duplicate_keywords={duplicate_keyword_posts}, "
            f"topic_keyword_overlaps={topic_keyword_overlap_posts}"
        )

    counts_after = keyword_counts(posts)
    if SEARCH_ALIAS in counts_after:
        raise ValueError(f"{SEARCH_ALIAS!r} still exists as a secondary keyword")
    if len(counts_after) != EXPECTED_UNIQUE_KEYWORDS - 1:
        raise ValueError(
            f"Expected {EXPECTED_UNIQUE_KEYWORDS - 1} unique keywords after alias cleanup; "
            f"found {len(counts_after)}"
        )

    audit = {
        "auditDate": "2026-08-19",
        "scope": (
            "Full local-text review of the 35 posts that used Biblical Discrepancies, "
            "New Testament Canon, or Eyewitness Testimony as a secondary keyword without "
            "the corresponding canonical topic, plus the Pericope Adulterae alias cleanup."
        ),
        "criterion": (
            "Add a topic only when it is a primary or major sustained subject; retain a "
            "secondary keyword when it is a meaningful supporting subject; remove labels "
            "based only on passing mentions or incidental context."
        ),
        "auditedKeywords": [
            "Biblical Discrepancies",
            "New Testament Canon",
            "Eyewitness Testimony",
        ],
        "summary": {
            "keywordOnlyPostsReviewed": len(all_expected_ids),
            "secondaryKeywordLinksRetained": len(retained_keywords),
            "secondaryKeywordLinksRemoved": len(removed_keywords),
            "topicLinksAdded": len(added_topics),
            "pericopeKeywordLinksRemoved": len(alias_removed),
            "topicAliasesAdded": 1,
            "uniqueSecondaryKeywordsBefore": len(counts_before),
            "uniqueSecondaryKeywordsAfter": len(counts_after),
        },
        "aliasChange": {
            "canonicalTopic": CANONICAL_TOPIC,
            "alias": SEARCH_ALIAS,
            "behavior": (
                "The alias remains searchable but is no longer exposed as a separate "
                "secondary keyword. Autocomplete should display the canonical topic label."
            ),
            "removedFromPosts": sorted(alias_removed, key=lambda item: item["title"].casefold()),
        },
        "topicLinksAdded": sorted(added_topics, key=lambda item: item["title"].casefold()),
        "secondaryKeywordLinksRemoved": sorted(
            removed_keywords,
            key=lambda item: (item["keyword"].casefold(), item["title"].casefold()),
        ),
        "secondaryKeywordLinksRetained": sorted(
            retained_keywords,
            key=lambda item: (item["keyword"].casefold(), item["title"].casefold()),
        ),
    }

    write_json(POSTS_PATH, posts)
    write_json(TOPICS_PATH, topics_document)
    write_json(AUDIT_PATH, audit)
    print(json.dumps(audit["summary"], indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
