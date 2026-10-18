from pathlib import Path
import unittest


class RecoveryRunnerTests(unittest.TestCase):
    def test_only_search_change_is_documented_failed_question_skip(self):
        root = Path(__file__).parent
        original = (root / 'run.cjs').read_text(encoding='utf-8').strip()
        recovery = (root / 'resume-searches.cjs').read_text(encoding='utf-8').strip()
        skip = '      if (question.id === 207) continue; // Documented technical failure after one unchanged-settings retry.\n'
        self.assertEqual(recovery.count(skip), 1)
        self.assertEqual(recovery.replace(skip, ''), original)


if __name__ == '__main__':
    unittest.main()
