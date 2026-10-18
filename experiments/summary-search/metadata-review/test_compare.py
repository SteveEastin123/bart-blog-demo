"""Offline evidence, scope and cost checks for the metadata reviewer pilot."""

import copy
import hashlib
import json
import unittest

import compare as c


class MetadataTests(unittest.TestCase):
    def setUp(self):
        self.source = c.read(c.WORK / 'input.json')
        self.requests = c.read(c.WORK / 'requests.json')
        self.request = self.requests[0]
        p = self.request['metadata'][0]
        self.receipt = {
            'requestHash': hashlib.sha256(json.dumps(self.request['payload'], ensure_ascii=False).encode()).hexdigest(),
            'model': 'gpt-5.4-mini', 'seconds': 1.0,
            'response': {'id': 'resp_test', 'model': 'gpt-5.4-mini-2026-03-17', 'status': 'completed',
                         'usage': {'input_tokens': 1000, 'input_tokens_details': {'cached_tokens': 100}, 'output_tokens': 10},
                         'output': [{'content': [{'type': 'output_text', 'text': json.dumps({'selected_posts': [
                             {'id': p['id'], 'relevance_tier': 'direct'}]})}]}]}}

    def test_original_fixtures_pass(self):
        c.validate_requests(self.requests, self.source)

    def test_changed_title_rejected(self):
        changed = copy.deepcopy(self.requests)
        changed[0]['metadata'][0]['title'] = 'different'
        with self.assertRaises(ValueError):
            c.validate_requests(changed, self.source)

    def test_reordered_candidates_rejected(self):
        changed = copy.deepcopy(self.requests)
        changed[0]['metadata'].reverse()
        with self.assertRaises(ValueError):
            c.validate_requests(changed, self.source)

    def test_keyword_leak_into_baseline_rejected(self):
        changed = copy.deepcopy(self.requests)
        changed[0]['metadata'][0]['secondary_keywords'] = ['Paul']
        with self.assertRaises(ValueError):
            c.validate_requests(changed, self.source)

    def test_setting_change_rejected(self):
        changed = copy.deepcopy(self.requests)
        changed[1]['payload']['max_output_tokens'] = 1600
        with self.assertRaises(ValueError):
            c.validate_requests(changed, self.source)

    def test_receipt_passes(self):
        c.validate_receipt(self.request, self.receipt)

    def test_incomplete_not_empty_result(self):
        self.receipt['response']['status'] = 'incomplete'
        with self.assertRaises(ValueError):
            c.validate_receipt(self.request, self.receipt)

    def test_wrong_request_hash_rejected(self):
        self.receipt['requestHash'] = 'other'
        with self.assertRaises(ValueError):
            c.validate_receipt(self.request, self.receipt)

    def test_missing_usage_rejected(self):
        self.receipt['response']['usage']['input_tokens'] = 0
        with self.assertRaises(ValueError):
            c.validate_receipt(self.request, self.receipt)

    def test_unknown_post_audited_for_production_parser(self):
        self.receipt['response']['output'][0]['content'][0]['text'] = json.dumps({'selected_posts': [{'id': 'absent', 'relevance_tier': 'direct'}]})
        self.assertEqual(c.validate_receipt(self.request, self.receipt), ['unknown_id'])

    def test_duplicate_selection_audited_for_production_parser(self):
        p = self.request['metadata'][0]
        self.receipt['response']['output'][0]['content'][0]['text'] = json.dumps({'selected_posts': [{'id': p['id'], 'relevance_tier': 'direct'}] * 2})
        self.assertEqual(c.validate_receipt(self.request, self.receipt), ['duplicate_id'])

    def test_cache_discount_cost(self):
        self.assertAlmostEqual(c.usage_cost(self.receipt['response']['usage']), .0007275)

    def test_fixed_precision_and_common_pool(self):
        values = c.metrics({'posts': [{'wpId': '1'}], 'costUsd': 0, 'submissionToResultsSeconds': 1, 'broaderFallback': False}, {'1': 3, '2': 2})
        self.assertEqual(values['precisionAt5'], .2)
        self.assertEqual(values['precisionAt10'], .1)
        self.assertLess(values['ndcgAt10'], 1)

    def test_final_evidence_repair_preserves_api_grade(self):
        audit = c.read(c.PUBLIC / 'evidence-repair.json')
        record = next(g for g in c.read(c.WORK / 'grades/q50.json')['grades'] if g['wpId'] == audit['wpId'])
        source = next(p['text'] for p in c.authoring.source()['posts'] if p['wpId'] == audit['wpId'])
        self.assertEqual(record['grade'], 2)
        self.assertEqual(record['evidence'], audit['repairedEvidence'])
        self.assertEqual(record['originalEvidence'], audit['originalEvidence'])
        c.grading.check_grade(record, source, strict=True)
        with self.assertRaises(ValueError):
            c.grading.check_grade({**record, 'evidence': record['originalEvidence']}, source, strict=True)

    def test_all_final_pools_cover_every_returned_top_ten(self):
        corpus = {p['wpId']: p for p in c.authoring.source()['posts']}
        previous = {q['id']: q for q in c.read(c.WORK / 'prior-results.json')['questions']}
        parsed, receipts = c.read(c.WORK / 'parsed.json'), c.read(c.WORK / 'receipts.json')
        for full in self.source['questions']:
            q = {k: full[k] for k in ('id', 'question', 'group')}
            runs = c.question_runs(full, parsed, receipts)
            ids = {g['wpId'] for g in previous[q['id']]['grades']} | {p['wpId'] for r in runs.values() for p in r['posts'][:10]}
            judged = c.read(c.WORK / 'grades' / f"q{q['id']:02}.json")
            c.grading.validate_grades(judged, q, ids, corpus)


if __name__ == '__main__':
    unittest.main()
