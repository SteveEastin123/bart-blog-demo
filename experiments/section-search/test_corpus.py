import unittest

from corpus import chunks, digest, read, HERE, PRIVATE, ROOT


class ChunkTests(unittest.TestCase):
    def test_all_words_covered_and_overlap(self):
        text = "\n\n".join(" ".join(f"w{i}" for i in range(start, start + 117)) for start in range(0, 1170, 117))
        for size in (300, 400, 500):
            rows = list(chunks(text, size))
            seen = set()
            for row in rows:
                seen.update(range(row["startWord"], row["endWord"]))
                self.assertEqual(row["endWord"] - row["startWord"], len(row["text"].split()))
                self.assertLessEqual(row["endWord"] - row["startWord"], size * 1.2)
            self.assertEqual(seen, set(range(1170)))
            for left, right in zip(rows, rows[1:]):
                self.assertEqual(left["endWord"] - right["startWord"], round(size * .2))

    def test_short_empty_and_single_paragraph(self):
        self.assertEqual(list(chunks("", 300)), [])
        self.assertEqual(len(list(chunks("short complete post", 300))), 1)
        self.assertEqual(len(list(chunks("x " * 1100, 300))), 5)

    def test_questions_frozen_unique(self):
        questions = read(HERE / "questions.json")["questions"]
        self.assertEqual(len(questions), 50)
        self.assertEqual(len({q["question"].lower() for q in questions}), 50)
        historic = set()
        def collect(value):
            if isinstance(value, dict):
                if isinstance(value.get("question"), str):
                    historic.add(value["question"].strip().lower())
                for item in value.values():
                    collect(item)
            elif isinstance(value, list):
                for item in value:
                    collect(item)
        for path in (ROOT / "data/evaluations").glob("ask_ai*.json"):
            collect(read(path))
        self.assertFalse(historic & {q["question"].strip().lower() for q in questions})

    def test_frozen_sample(self):
        if not (HERE / "sample.json").exists():
            self.skipTest("Sample not yet frozen")
        sample = read(HERE / "sample.json")
        self.assertEqual(len(sample["posts"]), 500)
        self.assertEqual(len({p["wpId"] for p in sample["posts"]}), 500)
        self.assertEqual(sum(p["group"] == "representative" for p in sample["posts"]), 400)
        self.assertEqual(sum(p["group"] == "challenge" for p in sample["posts"]), 100)
        for post in sample["posts"]:
            self.assertNotIn("Ignore", post["topics"])
            self.assertGreaterEqual(post["wordCount"], 100)
        corpus = read(PRIVATE / "corpus.json")
        for post in corpus["posts"]:
            self.assertEqual(digest(post["text"]), post["textHash"])


if __name__ == "__main__":
    unittest.main()
