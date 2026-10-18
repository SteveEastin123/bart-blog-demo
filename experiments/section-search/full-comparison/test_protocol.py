import unittest
import common as c
from grade import check
from report import percentile


class ProtocolTests(unittest.TestCase):
    def test_precision_and_order(self):
        run={'posts':[{'wpId':'a'},{'wpId':'b'}],'submissionToResultsSeconds':1,'costUsd':.01,'broaderFallback':False}
        grades={'a':3,'b':0,'c':2}
        score=c.metrics(run,grades)
        self.assertEqual(score['precisionAt5'],.2)
        self.assertEqual(score['precisionAt10'],.1)
        self.assertEqual(score['displayedPrecisionAt10'],.5)
        reversed_score=c.metrics({**run,'posts':list(reversed(run['posts']))},grades)
        self.assertGreater(score['ndcgAt10'],reversed_score['ndcgAt10'])

    def test_quotes(self):
        row={'grade':2,'reason':'substantial','evidence':'one two'}
        check(row,'zero one two three')
        for quote in ('invented','one ... three',' '.join(['one']*26),''):
            with self.assertRaises(ValueError): check({**row,'evidence':quote},'zero one two three')
        check({'grade':0,'reason':'unrelated','evidence':''},'post')

    def test_cost(self):
        self.assertAlmostEqual(c.phase_cost({'model':c.MODEL,'input_tokens':1000,'cached_input_tokens':200,'output_tokens':100}),.001065)
        self.assertAlmostEqual(c.phase_cost({'model':'text-embedding-3-small','input_tokens':100}),.000002)

    def test_percentile(self):
        self.assertEqual(percentile([1,3,2],.5),2)


if __name__=='__main__': unittest.main()
