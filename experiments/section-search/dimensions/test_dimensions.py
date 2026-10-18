"""Offline validation of isolated section embeddings and comparison contracts."""

import copy
import json
import unittest

import common as c
import embed
import compare
import score


class EmbeddingTests(unittest.TestCase):
    def setUp(self):
        self.rows = [{'hash': 'a', 'input': 'Title: Test\nPassage: Test passage'}]
        self.receipt = {'requestHash': c.sha(json.dumps(embed.payload(self.rows), ensure_ascii=False)),
                        'model': embed.MODEL, 'response': {'model': embed.MODEL,
                        'usage': {'prompt_tokens': 10, 'total_tokens': 10},
                        'data': [{'index': 0, 'embedding': [1.] + [0.]*1535}]}}

    def test_correct_vector(self):
        self.assertEqual(set(embed.checked(self.receipt, self.rows)), {'a'})

    def test_wrong_dimension(self):
        self.receipt['response']['data'][0]['embedding'] = [1.] + [0.]*511
        with self.assertRaises(ValueError):
            embed.checked(self.receipt, self.rows)

    def test_wrong_model(self):
        self.receipt['response']['model'] = 'text-embedding-3-large'
        with self.assertRaises(ValueError):
            embed.checked(self.receipt, self.rows)

    def test_wrong_hash(self):
        self.receipt['requestHash'] = 'wrong'
        with self.assertRaises(ValueError):
            embed.checked(self.receipt, self.rows)

    def test_duplicate_index(self):
        self.receipt['response']['data'] *= 2
        with self.assertRaises(ValueError):
            embed.checked(self.receipt, self.rows)

    def test_zero_vector(self):
        with self.assertRaises(ValueError):
            embed.validate_vector([0.]*1536)

    def test_nonfinite_vector(self):
        with self.assertRaises(ValueError):
            embed.validate_vector([float('nan')]+[0.]*1535)

    def test_no_source_text_change(self):
        source = copy.deepcopy(self.rows)
        embed.checked(self.receipt, self.rows)
        self.assertEqual(source, self.rows)


class SearchContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.corpus = {p['wpId']:p for p in c.authoring.source()['posts']}
        cls.q = c.read(c.PUBLIC/'questions.json')['questions'][0]
        cls.manifest = c.read(c.PUBLIC/'manifest.json')
        cls.original = c.read(c.previous.WORK/'searches/q01-ask-ai-2.json')

    def setUp(self):
        self.run = copy.deepcopy(self.original)
        self.run.update({'run':c.RUN,'test':False})
        for p in self.run['candidates']+self.run['posts']:
            p['passage']=''

    def check(self):
        compare.validate(self.run,self.q,'ask-ai-2',self.manifest,self.corpus,{})

    def test_valid_control(self):
        self.check()

    def test_no_cache(self):
        self.run['cacheHit']=True
        with self.assertRaises(ValueError):
            self.check()

    def test_no_duplicate_candidates(self):
        self.run['candidates'][1]=self.run['candidates'][0]
        with self.assertRaises(ValueError):
            self.check()

    def test_no_missing_cost_phase(self):
        self.run['usage']=self.run['usage'][1:]
        with self.assertRaises(ValueError):
            self.check()

    def test_no_changed_summary(self):
        self.run['candidates'][0]['summary']='changed'
        with self.assertRaises(ValueError):
            self.check()

    def test_no_unaccounted_cost(self):
        self.run['costUsd']+=.01
        with self.assertRaises(ValueError):
            self.check()


class ScoringAuditTests(unittest.TestCase):
    def setUp(self):
        self.posts = [{'id': str(i)} for i in range(1, 31)]
        self.run = {'candidates': self.posts, 'posts': [self.posts[1], self.posts[0]],
                    'tiers': {'2': 'direct', '1': 'background'}, 'broaderFallback': False}
        self.selected = [{'id': '1', 'relevance_tier': 'background'}, {'id': '2', 'relevance_tier': 'direct'}]

    def test_production_tier_reordering(self):
        self.assertEqual(score.replay_selection(self.run, self.selected), [])

    def test_reordered_saved_posts_rejected(self):
        self.run['posts'].reverse()
        with self.assertRaises(ValueError):
            score.replay_selection(self.run, self.selected)

    def test_invalid_choices_removed(self):
        rows = self.selected + [{'id': '2', 'relevance_tier': 'direct'}, {'id': '999', 'relevance_tier': 'direct'}]
        self.assertEqual(score.replay_selection(self.run, rows), ['duplicate_id', 'unknown_id'])

    def test_empty_selection_fallback(self):
        self.run.update(posts=self.posts[:25], tiers=[], broaderFallback=True)
        self.assertEqual(score.replay_selection(self.run, []), [])

    def test_wrong_fallback_rejected(self):
        self.run.update(posts=self.posts[:25], tiers=[], broaderFallback=False)
        with self.assertRaises(ValueError):
            score.replay_selection(self.run, [])

    def test_legacy_grade_binds_verified_corpus_hash(self):
        row = {'wpId': '1', 'grade': 0, 'reason': 'Unrelated', 'evidence': ''}
        result = score.retained_grade(row, {'text': 'Unrelated text.', 'textHash': 'frozen'})
        self.assertEqual(result['textHash'], 'frozen')
        self.assertNotIn('textHash', row)

    def test_legacy_conflicting_hash_rejected(self):
        row = {'wpId': '1', 'grade': 0, 'reason': 'Unrelated', 'evidence': '', 'textHash': 'other'}
        with self.assertRaises(ValueError):
            score.retained_grade(row, {'text': 'Unrelated text.', 'textHash': 'frozen'})


if __name__ == '__main__':
    unittest.main()
