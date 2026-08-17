from __future__ import annotations

import json
from collections import Counter
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
NORMALIZATION_AUDIT_PATH = (
    ROOT / "data" / "audits" / "resurrection_keyword_normalization_audit.json"
)
JESUS_AUDIT_PATH = (
    ROOT / "data" / "audits" / "resurrection_of_jesus_secondary_keyword_audit.json"
)
DEAD_AUDIT_PATH = (
    ROOT / "data" / "audits" / "resurrection_of_the_dead_secondary_keyword_audit.json"
)

GENERIC_KEYWORD = "Resurrection"
JESUS_KEYWORD = "Resurrection of Jesus"
DEAD_KEYWORD = "Resurrection of the Dead"

JESUS_SIGNAL_TOPICS = {
    JESUS_KEYWORD,
    "Meaning of Jesus' Resurrection",
    "Jesus' Resurrection Appearances",
    "Resurrection Arguments and Apologetics",
    "Empty Tomb Traditions",
}

DEAD_SIGNAL_TOPICS = {
    DEAD_KEYWORD,
    "Paul on Resurrection",
    "Ancient Jewish Afterlife Beliefs",
}

# Posts without a decisive topic or existing specific keyword were reviewed
# individually against the full local text.
MANUAL_JESUS_IDS = {
    "49865", "49744", "48174", "47002", "47207", "47176", "47169",
    "47137", "23250", "47113", "47110", "47109", "47098", "47058",
    "41368", "41343", "40554", "38881", "38778", "38639", "37845",
    "37837", "37577", "37422", "37415", "36219", "35786", "35255",
    "35338", "34597", "33532", "33030", "31511", "31498", "30471",
    "29846", "1966", "28887", "28814", "28747", "28558", "28213",
    "28208", "26454", "26576", "26327", "24468", "24080", "23935",
    "23386", "23381", "23292", "23246", "23241", "22090", "21355",
    "21311", "21281", "21274", "17839", "17714", "17387", "17064",
    "16812", "16534", "16162", "16100", "16088", "16054", "15868",
    "15633", "15528", "15448", "15446", "15358", "15268", "15261",
    "15087", "14950", "13773", "13513", "13142", "13137", "13072",
    "12690", "12652", "12613", "12373", "12305", "11999", "11939",
    "11568", "11558", "11556", "11505", "11439", "10576", "10318",
    "10276", "9436", "9393", "9384", "9333", "9288", "9220", "9197",
    "8946", "8816", "8610", "8580", "8309", "8307", "7362", "7348",
    "7291", "7280", "7260", "7214", "7076", "6958", "6478", "5707",
    "4972", "4672", "4255", "4042", "3993", "3898", "3821", "3816",
    "3809", "3795", "3786", "3763", "3284", "3064", "3054", "2838",
    "2304", "2155",
}

MANUAL_DEAD_IDS = {
    "46942", "47219", "47217", "38596", "31181", "27340", "25745",
    "20749", "17494", "16258", "16120", "16098", "15763", "15475",
    "15471", "13186", "12975", "12873", "12428", "11721", "8359",
    "4763",
}

MANUAL_BOTH_IDS = {
    "47530", "11520", "28882", "27397", "25879", "17411", "17095",
    "15082", "14935", "13203", "7285",
}

MANUAL_NEITHER_IDS = {
    "48712", "47043", "39827", "39748", "39286", "33402", "33081",
    "21403", "21255", "20704", "17769", "17553", "14891", "14822",
    "8476", "8085", "7471", "5072", "4607", "4241",
}


def classify(post: dict[str, object]) -> tuple[bool, bool, str]:
    post_id = str(post["wpId"])
    topics = set(post.get("topics", []))
    keywords = set(post.get("secondaryKeywords", []))
    jesus = JESUS_KEYWORD in keywords or bool(topics & JESUS_SIGNAL_TOPICS)
    dead = DEAD_KEYWORD in keywords or bool(topics & DEAD_SIGNAL_TOPICS)

    if jesus or dead:
        return jesus, dead, "specific topic or existing keyword"
    if post_id in MANUAL_JESUS_IDS:
        return True, False, "full-text review"
    if post_id in MANUAL_DEAD_IDS:
        return False, True, "full-text review"
    if post_id in MANUAL_BOTH_IDS:
        return True, True, "full-text review"
    if post_id in MANUAL_NEITHER_IDS:
        return False, False, "full-text review"
    raise RuntimeError(f"No resurrection classification for post {post_id}")


def write_json(path: Path, data: object) -> None:
    path.write_text(
        json.dumps(data, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    assigned = [
        post
        for post in posts
        if GENERIC_KEYWORD in post.get("secondaryKeywords", [])
    ]
    if len(assigned) != 374:
        raise RuntimeError(
            f"Expected 374 {GENERIC_KEYWORD} assignments; found {len(assigned)}"
        )

    manual_ids = (
        MANUAL_JESUS_IDS
        | MANUAL_DEAD_IDS
        | MANUAL_BOTH_IDS
        | MANUAL_NEITHER_IDS
    )
    if len(manual_ids) != 193:
        raise RuntimeError(
            f"Expected 193 unique manual classifications; found {len(manual_ids)}"
        )

    classification_counts: Counter[str] = Counter()
    action_counts: Counter[str] = Counter()
    records = []

    for post in assigned:
        post_id = str(post["wpId"])
        topics = set(post.get("topics", []))
        jesus, dead, evidence = classify(post)
        if jesus and dead:
            classification = "both"
        elif jesus:
            classification = "resurrection_of_jesus"
        elif dead:
            classification = "resurrection_of_the_dead"
        else:
            classification = "neither"
        classification_counts[classification] += 1

        post["secondaryKeywords"] = [
            keyword
            for keyword in post.get("secondaryKeywords", [])
            if keyword != GENERIC_KEYWORD
        ]
        actions = [f"removed {GENERIC_KEYWORD}"]
        action_counts["removed_generic"] += 1

        if jesus and JESUS_KEYWORD not in topics:
            if JESUS_KEYWORD not in post["secondaryKeywords"]:
                post["secondaryKeywords"].append(JESUS_KEYWORD)
                actions.append(f"added {JESUS_KEYWORD}")
                action_counts["added_jesus"] += 1
        elif JESUS_KEYWORD in topics and JESUS_KEYWORD in post["secondaryKeywords"]:
            post["secondaryKeywords"].remove(JESUS_KEYWORD)
            actions.append(f"removed topic-duplicate {JESUS_KEYWORD}")
            action_counts["removed_jesus_topic_duplicate"] += 1

        if dead and DEAD_KEYWORD not in topics:
            if DEAD_KEYWORD not in post["secondaryKeywords"]:
                post["secondaryKeywords"].append(DEAD_KEYWORD)
                actions.append(f"added {DEAD_KEYWORD}")
                action_counts["added_dead"] += 1
        elif DEAD_KEYWORD in topics and DEAD_KEYWORD in post["secondaryKeywords"]:
            post["secondaryKeywords"].remove(DEAD_KEYWORD)
            actions.append(f"removed topic-duplicate {DEAD_KEYWORD}")
            action_counts["removed_dead_topic_duplicate"] += 1

        records.append(
            {
                "wpId": post_id,
                "title": post["title"],
                "classification": classification,
                "evidence": evidence,
                "topics": post.get("topics", []),
                "actions": actions,
            }
        )

    if classification_counts != {
        "resurrection_of_jesus": 268,
        "resurrection_of_the_dead": 61,
        "both": 25,
        "neither": 20,
    }:
        raise RuntimeError(
            f"Unexpected classification totals: {dict(classification_counts)}"
        )

    generic_remaining = [
        post
        for post in posts
        if GENERIC_KEYWORD in post.get("secondaryKeywords", [])
    ]
    if generic_remaining:
        raise RuntimeError("Generic Resurrection keyword remains after normalization")

    duplicate_specific = [
        str(post["wpId"])
        for post in posts
        if (
            JESUS_KEYWORD in post.get("topics", [])
            and JESUS_KEYWORD in post.get("secondaryKeywords", [])
        )
        or (
            DEAD_KEYWORD in post.get("topics", [])
            and DEAD_KEYWORD in post.get("secondaryKeywords", [])
        )
    ]
    if duplicate_specific:
        raise RuntimeError(
            f"Specific resurrection topic/keyword duplicates: {duplicate_specific}"
        )

    INDEX_PATH.write_text(
        json.dumps(posts, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )

    final_jesus = [
        {"wpId": str(post["wpId"]), "title": post["title"]}
        for post in posts
        if JESUS_KEYWORD in post.get("secondaryKeywords", [])
    ]
    final_dead = [
        {"wpId": str(post["wpId"]), "title": post["title"]}
        for post in posts
        if DEAD_KEYWORD in post.get("secondaryKeywords", [])
    ]

    normalization_audit = {
        "retiredKeyword": GENERIC_KEYWORD,
        "replacementKeywords": [JESUS_KEYWORD, DEAD_KEYWORD],
        "criterion": (
            "Use Resurrection of Jesus for the specific event, its appearances, "
            "historical arguments, narratives, and theological meaning. Use "
            "Resurrection of the Dead for collective or future resurrection, "
            "resurrection bodies, and Jewish or Christian end-time beliefs. Use "
            "both only when both concepts receive meaningful treatment; use "
            "neither for incidental or merely catalog-like references."
        ),
        "before": len(assigned),
        "classificationCounts": dict(classification_counts),
        "actionCounts": dict(action_counts),
        "finalSecondaryKeywordCounts": {
            JESUS_KEYWORD: len(final_jesus),
            DEAD_KEYWORD: len(final_dead),
        },
        "posts": records,
    }
    write_json(NORMALIZATION_AUDIT_PATH, normalization_audit)

    jesus_audit = json.loads(JESUS_AUDIT_PATH.read_text(encoding="utf-8"))
    jesus_audit["laterNormalization"] = {
        "sourceKeyword": GENERIC_KEYWORD,
        "addedAssignments": action_counts["added_jesus"],
        "currentAssignments": len(final_jesus),
        "currentPosts": final_jesus,
    }
    write_json(JESUS_AUDIT_PATH, jesus_audit)

    dead_audit = {
        "keyword": DEAD_KEYWORD,
        "criterion": (
            "Retain for meaningful discussion of collective or future "
            "resurrection, resurrection bodies, believers being raised, and "
            "Jewish or Christian end-time resurrection beliefs. Exclude posts "
            "concerned only with Jesus' resurrection."
        ),
        "beforeNormalization": 14,
        "addedFromRetiredResurrection": action_counts["added_dead"],
        "currentAssignments": len(final_dead),
        "currentPosts": final_dead,
    }
    write_json(DEAD_AUDIT_PATH, dead_audit)

    print(f"Retired {GENERIC_KEYWORD}: {len(assigned)} assignments removed")
    print(f"Classifications: {dict(classification_counts)}")
    print(f"Actions: {dict(action_counts)}")
    print(
        "Final secondary keyword counts: "
        f"{JESUS_KEYWORD}={len(final_jesus)}, {DEAD_KEYWORD}={len(final_dead)}"
    )


if __name__ == "__main__":
    main()
