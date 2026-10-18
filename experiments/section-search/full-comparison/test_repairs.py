import contextlib
import hashlib
import io
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import common as c
import repair_quotes


class IdentityRepairTests(unittest.TestCase):
    def run_repair(self, overrides):
        with tempfile.TemporaryDirectory() as directory:
            work = Path(directory)
            ids = sorted(['a', 'b'], key=lambda wp: hashlib.sha256(f'{c.RUN}:1:{wp}'.encode()).hexdigest())
            c.save(work / 'corpus.json', {wp: {'text': 'An unrelated complete post.'} for wp in ids})
            for arm in c.ARMS:
                c.save(work / 'results' / f'q001-{arm}.json', {'posts': [{'wpId': wp} for wp in ids]})
            c.save(work / 'grade-rejections/q001-00-2.json', {'receipt': 'bad-labels'})
            c.save(work / 'grade-receipts/bad-labels.json', {'response': {'output': [{'content': [
                {'type': 'output_text', 'text': json.dumps({'grades': [{'id': 'P99', 'grade': 3}]})}
            ]}]}})
            for index in range(overrides):
                c.save(work / 'rejudgments' / f'post-{index}.json', {
                    'questionId': 1, 'wpId': ids[index], 'id': f'P{index + 1:02}',
                    'grade': 0, 'reason': 'Does not address this question.', 'evidence': '',
                    'receipt': 'bad-labels', 'auditReceipt': f'fresh-{index}',
                    'auditNote': 'Fresh blind individual judgment.'
                })
            output = io.StringIO()
            with patch.object(c, 'WORK', work), patch.object(c, 'HERE', work / 'helpers'), \
                    patch.object(c, 'integrity', return_value={}), contextlib.redirect_stdout(output):
                repair_quotes.main()
            target = work / 'grade-batches/q001-00.json'
            return c.read(target) if target.exists() else None, json.loads(output.getvalue())

    def test_rejects_missing_individual_grades(self):
        for count in (0, 1):
            with self.subTest(individual_grades=count):
                accepted, output = self.run_repair(count)
                self.assertIsNone(accepted)
                self.assertEqual(output['repairedBatches'], 0)
                self.assertEqual(len(output['needsReading']), 1)

    def test_uses_only_complete_fresh_individual_grades(self):
        accepted, output = self.run_repair(2)
        self.assertEqual([g['id'] for g in accepted['grades']], ['P01', 'P02'])
        self.assertEqual([g['grade'] for g in accepted['grades']], [0, 0])
        self.assertTrue(all(g['originalBatchIdentityMismatch'] for g in accepted['grades']))
        self.assertTrue(all(g['auditReceipt'] for g in accepted['grades']))
        self.assertEqual(output['repairedBatches'], 1)
        self.assertEqual(output['needsReading'], [])


if __name__ == '__main__':
    unittest.main()
