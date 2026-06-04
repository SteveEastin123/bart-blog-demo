import csv
import json
from pathlib import Path

ROOT = Path(r"C:\Users\charl\OneDrive\Documents\Bart Ehrman Blog")
POSTS_PATH = ROOT / "data" / "raw" / "posts.jsonl"
CONTROLLED_PATH = ROOT / "data" / "index" / "posts_with_controlled_tags.jsonl"
OUT_PATH = ROOT / "data" / "index" / "ehrman_posts_full_tagging_index.csv"


def load_jsonl(path):
    return [
        json.loads(line)
        for line in path.read_text(encoding="utf-8").splitlines()
        if line.strip()
    ]


def join_list(value):
    return "; ".join(value or [])


def main():
    posts_by_url = {post["url"]: post for post in load_jsonl(POSTS_PATH)}
    controlled_rows = load_jsonl(CONTROLLED_PATH)

    fieldnames = [
        "title",
        "url",
        "date",
        "author",
        "wordCount",
        "summary",
        "legacyCategories",
        "legacyTags",
        "controlledTags",
        "contentAreaTags",
        "themeTags",
        "methodTags",
        "formatTags",
        "needsTagReview",
        "sourceArchive",
        "sourceArchiveTitle",
        "wpId",
    ]

    with OUT_PATH.open("w", encoding="utf-8-sig", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fieldnames)
        writer.writeheader()
        for controlled in controlled_rows:
            post = posts_by_url.get(controlled["url"], {})
            groups = controlled.get("controlledTagGroups", {})
            writer.writerow({
                "title": controlled.get("title", ""),
                "url": controlled.get("url", ""),
                "date": controlled.get("date", ""),
                "author": controlled.get("author") or post.get("author", ""),
                "wordCount": controlled.get("wordCount", ""),
                "summary": post.get("summary", ""),
                "legacyCategories": join_list(controlled.get("legacyCategories")),
                "legacyTags": join_list(controlled.get("legacyTags")),
                "controlledTags": join_list(controlled.get("controlledTags")),
                "contentAreaTags": join_list(groups.get("content_area")),
                "themeTags": join_list(groups.get("theme")),
                "methodTags": join_list(groups.get("method")),
                "formatTags": join_list(groups.get("format")),
                "needsTagReview": controlled.get("needsTagReview", False),
                "sourceArchive": post.get("sourceArchive", ""),
                "sourceArchiveTitle": post.get("sourceArchiveTitle", ""),
                "wpId": post.get("wpId", ""),
            })

    print(json.dumps({
        "rows": len(controlled_rows),
        "output": str(OUT_PATH),
    }, indent=2))


if __name__ == "__main__":
    main()
