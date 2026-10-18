"""Offline tests for editorial validation; never call a provider."""

import unittest

import agent_work


class SummaryValidationTests(unittest.TestCase):
    def setUp(self):
        self.post = {"text": "Evidence about the first topic. Evidence about another topic."}
        self.row = {
            "searchSummary": "Examines " + "evidence " * 119,
            "sourceQuotes": ["Evidence about the first topic.", "Evidence about another topic."],
        }

    def test_valid_record(self):
        self.assertEqual(agent_work.validate_record(self.row, self.post), 120)

    def test_question_mark_in_book_title_is_not_a_question_opening(self):
        self.row["searchSummary"] = "Explains Did Jesus Exist? " + "evidence " * 116
        self.assertEqual(agent_work.validate_record(self.row, self.post), 120)

    def test_question_opening_rejected(self):
        self.row["searchSummary"] = "Does " + "evidence " * 119
        with self.assertRaises(ValueError):
            agent_work.validate_record(self.row, self.post)

    def test_attributed_verb_opening_with_comma(self):
        self.row["searchSummary"] = "Questions, in a guest essay, " + "evidence " * 115
        self.assertEqual(agent_work.validate_record(self.row, self.post), 120)

    def test_invented_quote_rejected(self):
        self.row["sourceQuotes"][0] = "Not present in the source."
        with self.assertRaises(ValueError):
            agent_work.validate_record(self.row, self.post)

    def test_short_record_needs_explanation(self):
        self.row["searchSummary"] = "Introduces " + "evidence " * 59
        with self.assertRaises(ValueError):
            agent_work.validate_record(self.row, self.post)
        self.row["shortSourceReason"] = "Brief media introduction without transcript."
        self.assertEqual(agent_work.validate_record(self.row, self.post), 60)

    def test_quote_typography_normalizes(self):
        self.post["text"] += " The author's \u201cclaim\u201d is specific."
        self.row["sourceQuotes"][0] = 'The author\u2019s "claim" is specific.'
        self.assertEqual(agent_work.validate_record(self.row, self.post), 120)

    def test_excessive_length_rejected(self):
        self.row["searchSummary"] = "Examines " + "evidence " * 190
        with self.assertRaises(ValueError):
            agent_work.validate_record(self.row, self.post)


if __name__ == "__main__":
    unittest.main()
