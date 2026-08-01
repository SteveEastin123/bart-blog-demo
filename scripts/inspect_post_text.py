import argparse
import json
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "raw" / "posts.jsonl"


def main():
    sys.stdout.reconfigure(encoding="utf-8")
    parser = argparse.ArgumentParser()
    parser.add_argument("wp_ids", nargs="+")
    args = parser.parse_args()
    wanted = set(args.wp_ids)

    found = {}
    with POSTS_PATH.open(encoding="utf-8") as handle:
        for line in handle:
            post = json.loads(line)
            wp_id = str(post.get("wpId", ""))
            if wp_id in wanted:
                found[wp_id] = post

    for wp_id in args.wp_ids:
        post = found.get(wp_id)
        if not post:
            print(f"\n## {wp_id}: NOT FOUND")
            continue
        print(f"\n## {wp_id}: {post.get('title', '')}")
        print(post.get("text", "").strip())


if __name__ == "__main__":
    main()
