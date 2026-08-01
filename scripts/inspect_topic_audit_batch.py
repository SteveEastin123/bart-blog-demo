import argparse
import json
import re
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
TRACKER_PATH = ROOT / "data" / "audits" / "ehrman_topic_audit_tracker.json"


def load_json(path):
    with path.open(encoding="utf-8") as handle:
        return json.load(handle)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("start", type=int)
    parser.add_argument("end", type=int)
    parser.add_argument("--candidates", action="store_true")
    args = parser.parse_args()

    index = load_json(INDEX_PATH)
    tracker = load_json(TRACKER_PATH)["topics"]
    posts = index["posts"] if isinstance(index, dict) else index

    tracked = {
        item["auditSequence"]: item
        for item in tracker
        if item.get("auditSequence") is not None
        and args.start <= item["auditSequence"] <= args.end
    }

    for sequence in range(args.start, args.end + 1):
        item = tracked[sequence]
        topic = item["topic"]
        linked = [post for post in posts if topic in post.get("topics", [])]
        print(f"\n## {sequence}. {topic} ({len(linked)})")
        print(f"DESCRIPTION: {item.get('descriptionBefore', '')}")
        for post in linked:
            other_topics = [value for value in post.get("topics", []) if value != topic]
            print(
                "POST|{wp_id}|{title}|OTHER={other}|KW={keywords}|DESC={description}".format(
                    wp_id=post.get("wpId", ""),
                    title=post.get("title", ""),
                    other="; ".join(other_topics),
                    keywords="; ".join(post.get("secondaryKeywords", [])),
                    description=re.sub(r"\s+", " ", post.get("description", "")).strip(),
                )
            )

        if args.candidates:
            linked_ids = {str(post.get("wpId", "")) for post in linked}
            words = [
                word.casefold()
                for word in re.findall(r"[A-Za-z0-9]+", topic)
                if word.casefold()
                not in {"a", "an", "and", "in", "of", "on", "the", "to", "general"}
            ]
            candidates = []
            for post in posts:
                if str(post.get("wpId", "")) in linked_ids:
                    continue
                title = post.get("title", "")
                description = post.get("description", "")
                haystack = f"{title} {description}".casefold()
                keyword_values = [value.casefold() for value in post.get("secondaryKeywords", [])]
                score = 0
                if topic.casefold() in keyword_values:
                    score += 100
                title_hits = sum(word in title.casefold() for word in words)
                description_hits = sum(word in description.casefold() for word in words)
                if words and title_hits == len(words):
                    score += 60
                elif title_hits >= min(2, len(words)):
                    score += 25
                if words and description_hits == len(words):
                    score += 30
                elif description_hits >= min(2, len(words)):
                    score += 12
                if score:
                    candidates.append((score, post))

            for score, post in sorted(
                candidates,
                key=lambda value: (-value[0], value[1].get("title", "").casefold()),
            )[:12]:
                print(
                    "CANDIDATE|{score}|{wp_id}|{title}|TOPICS={topics}|KW={keywords}|DESC={description}".format(
                        score=score,
                        wp_id=post.get("wpId", ""),
                        title=post.get("title", ""),
                        topics="; ".join(post.get("topics", [])),
                        keywords="; ".join(post.get("secondaryKeywords", [])),
                        description=re.sub(r"\s+", " ", post.get("description", "")).strip(),
                    )
                )


if __name__ == "__main__":
    main()
