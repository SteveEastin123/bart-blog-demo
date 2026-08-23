import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SEARCH_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
TOPIC_TRACKER_PATH = ROOT / "data" / "audits" / "ehrman_topic_audit_tracker.json"
KEYWORD_TRACKER_PATH = ROOT / "data" / "audits" / "ehrman_secondary_keyword_audit_tracker.json"
AUDIT_PATH = ROOT / "data" / "audits" / "sermon_on_the_mount_topic_reaudit_2026_08_23.json"

TOPIC = "Sermon on the Mount"
KEYWORD = "Golden Rule"
REMOVE_ID = "4455"
RETAIN_IDS = ["33196", "33124", "33221", "20822", "4428"]
DESCRIPTION = (
    "Examines Matthew's Sermon on the Mount, including its composition, "
    "relationship to Luke's Sermon on the Plain, the Beatitudes, the Golden "
    "Rule, and its interpretation of Jewish law and ethics."
)


def load(path):
    return json.loads(path.read_text(encoding="utf-8"))


def save(path, data):
    path.write_text(
        json.dumps(data, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )


search_data = load(SEARCH_PATH)
search_by_id = {str(post["wpId"]): post for post in search_data}
post = search_by_id[REMOVE_ID]
assert TOPIC in post.get("topics", []), f"{TOPIC} is not assigned to {REMOVE_ID}."
assert KEYWORD not in post.get("secondaryKeywords", []), (
    f"{KEYWORD} is already assigned to {REMOVE_ID}."
)

post["topics"] = [name for name in post["topics"] if name != TOPIC]
post.setdefault("secondaryKeywords", []).append(KEYWORD)
save(SEARCH_PATH, search_data)

audit = {
    "auditDate": "2026-08-23",
    "method": (
        "Conservative full-text re-audit requiring the Sermon on the Mount or "
        "one of its defining sections to be a primary or major sustained "
        "subject of each post."
    ),
    "topic": {
        "name": TOPIC,
        "descriptionBefore": DESCRIPTION,
        "descriptionAfter": DESCRIPTION,
        "postCountBefore": 6,
        "postCountAfter": len(RETAIN_IDS),
        "retainedWpIds": RETAIN_IDS,
        "removed": [
            {
                "wpId": REMOVE_ID,
                "title": post["title"],
                "reason": (
                    "The Sermon on the Mount supplies one supporting example, "
                    "but the post's broader subject is Matthew's understanding "
                    "of Jewish law."
                ),
                "secondaryKeywordAdded": KEYWORD,
            }
        ],
        "addedWpIds": [],
        "categoryChanges": [],
    },
}
save(AUDIT_PATH, audit)

topic_tracker = load(TOPIC_TRACKER_PATH)
topic_entry = next(item for item in topic_tracker["topics"] if item["topic"] == TOPIC)
topic_entry.update(
    {
        "status": "completed",
        "postCountBefore": 6,
        "postCountAfter": len(RETAIN_IDS),
        "descriptionBefore": DESCRIPTION,
        "descriptionRecommendation": DESCRIPTION,
        "startedAt": "2026-08-23",
        "completedAt": "2026-08-23",
        "decisions": [
            {
                "wpId": wp_id,
                "title": search_by_id[wp_id]["title"],
                "decision": "retain",
                "confidence": "high",
                "reason": (
                    "The Sermon on the Mount or one of its defining sections "
                    "is a primary or major sustained subject of the post."
                ),
            }
            for wp_id in RETAIN_IDS
        ]
        + [
            {
                "wpId": REMOVE_ID,
                "title": post["title"],
                "decision": "remove",
                "confidence": "high",
                "reason": audit["topic"]["removed"][0]["reason"],
            }
        ],
        "notes": [
            "A conservative full-text re-audit retained five of the six linked posts.",
            "Removed the topic from wpId 4455 because the sermon is supporting evidence for a broader discussion of Jewish law in Matthew.",
            "Added Golden Rule as a secondary keyword to wpId 4455 to preserve its meaningful supporting subject.",
            "The topic description and both category assignments remain accurate.",
        ],
    }
)
topic_tracker["updatedAt"] = "2026-08-23"
save(TOPIC_TRACKER_PATH, topic_tracker)

keyword_tracker = load(KEYWORD_TRACKER_PATH)
keyword_entry = next(
    item for item in keyword_tracker["keywords"] if item["keyword"] == KEYWORD
)
keyword_entry["postCount"] = 2
if AUDIT_PATH.name not in keyword_entry["auditEvidence"]:
    keyword_entry["auditEvidence"].append(AUDIT_PATH.name)
keyword_tracker["updatedAt"] = "2026-08-23"
save(KEYWORD_TRACKER_PATH, keyword_tracker)

print(
    f"Retained {TOPIC} on {len(RETAIN_IDS)} posts; removed it from {REMOVE_ID} "
    f"and added {KEYWORD} as a secondary keyword."
)
