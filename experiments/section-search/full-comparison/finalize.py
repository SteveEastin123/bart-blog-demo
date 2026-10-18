"""Close the attempted test set without fabricating results for technical failures."""
import collections
import json
import common as c


def main():
    manifest = c.integrity()
    questions = c.read(c.PUBLIC / 'questions.json')['questions']
    report = c.read(c.PUBLIC / 'results.json')
    failures = c.read(c.PUBLIC / 'search-failures.json')
    audit = c.read(c.PUBLIC / 'audit.json')
    excluded = {(x['questionId'], x['arm']) for x in failures['qualityExclusions']}
    excluded_questions = {number for number, _ in excluded}
    if {q['id'] for q in report['questions']} != {q['id'] for q in questions} - excluded_questions:
        raise ValueError('Not all nonfailed pairs have accepted full-post grades')
    if audit['databaseUnchanged'] is not True:
        raise ValueError('Final discovery database fingerprint has not passed')
    groups = {}
    for group in ('original-200', 'challenge-50'):
        chosen = [q for q in questions if q['group'] == group]
        ids = {q['id'] for q in chosen}
        groups[group] = {}
        for arm in c.ARMS:
            completed = []
            for q in chosen:
                path = c.WORK / 'results' / f'q{q["id"]:03}-{arm}.json'
                if not path.exists():
                    if (q['id'], arm) not in excluded:
                        raise ValueError('Unexplained missing search result')
                    continue
                result = c.read(path)
                c.validate(result, q, arm, manifest)
                completed.append(result)
            failed = [f for f in failures['failures'] if f['questionId'] in ids and f['arm'] == arm]
            cost = sum(r['costUsd'] for r in completed) + sum(f['costUsd'] for f in failed)
            groups[group][arm] = {
                'questionsAttempted': len(chosen), 'completedSearches': len(completed),
                'firstAttemptFailures': sum(f['attempt'] == 1 for f in failed),
                'failedAttemptsIncludingRetries': len(failed),
                'reportedSearchCostIncludingFailuresUsd': cost,
                'reportedCostPerAttemptedQuestionUsd': cost / len(chosen),
                'unverifiedFailedReviewCosts': sum(bool(f.get('reviewUsageAnomaly')) for f in failed)
            }
    coverage = {}
    for group in ('original-200', 'challenge-50'):
        rows = [q for q in report['questions'] if q['group'] == group]
        unsupported = [q for q in rows if all(q['metrics'][arm]['relevantTop10'] == 0 for arm in c.ARMS)]
        supported = [q for q in rows if q not in unsupported]
        coverage[group] = {
            'neitherReturnedRelevantPosts': [q['id'] for q in unsupported],
            'rankingWinnersWithoutRelevantPosts': dict(collections.Counter(q['winner'] for q in unsupported)),
            'pairsWithAtLeastOneRelevantPost': len(supported),
            'winnersWhenAtLeastOneRelevantPostReturned': dict(collections.Counter(q['winner'] for q in supported)),
            'onlyOneMethodReturnedRelevantPosts': {
                arm: [q['id'] for q in rows if q['metrics'][arm]['relevantTop10'] > 0
                      and q['metrics'][c.ARMS[1] if arm == c.ARMS[0] else c.ARMS[0]]['relevantTop10'] == 0]
                for arm in c.ARMS
            }
        }
    summary = {
        'questionsAttempted': len(questions), 'pairedQualityComparisons': report['completedQuestions'],
        'completedSearches': audit['verifiedSearches'], 'failedAttempts': audit['failedSearchAttempts'],
        'reportedSearchCostUsd': audit['providerCostUsd'], 'gradingCostUsd': report['audit']['gradingCostUsd'],
        'fullPostQuestionPairsGraded': report['audit']['gradedQuestionPostPairs'],
        'groups': groups, 'answerCoverageDiagnostics': coverage, 'databaseUnchanged': True,
        'finalHarnessSourceHashes': {
            str(path.relative_to(c.ROOT)).replace('\\', '/'): c.digest(path)
            for path in sorted(c.HERE.rglob('*'))
            if path.is_file() and path.suffix in {'.py', '.cjs', '.php', '.ps1', '.md', '.json', '.xml', '.neon'}
        },
        'reviewerOutputRejectionCounts': dict(collections.Counter(
            row['reason'] for warning in audit['reviewerOutputWarnings'] for row in warning['rejectedReviewerRows']))
    }
    c.save(c.PUBLIC / 'completion.json', summary, frozen=False)
    lines = ['# Completion and reliability notes', '',
             f'All {len(questions)} questions were attempted against both methods. There are {report["completedQuestions"]} completed paired relevance comparisons.', '',
             'Question 207 exhausted the unchanged 800-token reviewer limit in both methods. Ask AI 2 completed one retry; Ask AI 3 also failed its retry. Its missing post list is a technical failure, not a zero-relevance result or a tie. The original 200-question cohort is complete; the new challenge cohort has 49 comparable pairs out of 50 attempted.', '',
             'Successful-result latency excludes failed attempts and manual diagnosis time. For question 207, the failed HTTP attempts took 5.18 seconds for Ask AI 2 and 8.00 plus 6.98 seconds for Ask AI 3; none produced a final list.', '',
             '| Cohort | Method | First-attempt completions | Eventual completions | Reported cost per attempted question, including failures |',
             '|---|---|---:|---:|---:|']
    for group, arms in groups.items():
        for arm, values in arms.items():
            total = values['questionsAttempted']
            uncertain = ' *' if values['unverifiedFailedReviewCosts'] else ''
            lines.append(f'| {group} | {arm} | {total-values["firstAttemptFailures"]}/{total} | {values["completedSearches"]}/{total} | ${values["reportedCostPerAttemptedQuestionUsd"]:.5f}{uncertain} |')
    lines += ['', '* One failed Ask AI 3 review reported all-zero usage despite an incomplete response. Its actual billed cost is unverified; the reported total is not proof that this call was free. All costs are token-based estimates at frozen prices, not invoice reconciliation.', '',
              f'Reported search-call cost, including failed attempts: ${audit["providerCostUsd"]:.5f}, subject to that anomaly. Full-post grading and rechecks: ${report["audit"]["gradingCostUsd"]:.5f}. Index construction and hosting are excluded.', '',
              f'{report["audit"]["gradedQuestionPostPairs"]} question/post pairs have accepted full-text grades. One accepted grade is applied to both methods; unsupported quotations or incorrect labels can require additional blind individual rechecks. These are AI judgments, not a human reading every post. Exact quotations alone do not prove relevance.', '',
              'The original 200 questions are a development/regression set, not an unseen holdout. The 50 new questions are deliberately specific diagnostic cases, not a representative traffic sample. The grader and reviewer use the same model; confidence intervals do not capture grader uncertainty.', '',
              f'All {audit["frozenInputsUnchanged"]} frozen inputs and {audit["historicalFilesUnchanged"]} earlier experiment files passed their hash checks. Discovery database fingerprints are unchanged. Unknown or duplicate reviewer IDs were discarded by the existing parser and retained in the audit.', '',
              '[Full comparison](report.md) | [Source spot checks](source-spotchecks.md) | [Detailed protocol](../../full-comparison/README.md) | [Failure receipts index](search-failures.json)', '']
    challenge = coverage['challenge-50']
    wins = challenge['winnersWhenAtLeastOneRelevantPostReturned']
    lines += ['## Useful-result coverage', '',
              f'{len(challenge["neitherReturnedRelevantPosts"])} challenge pairs had no grade-2/3 result from either method. Their ranking winners were {json.dumps(challenge["rankingWinnersWithoutRelevantPosts"])}. Such wins reflect ordering of marginally related posts, not the delivery of a substantially useful answer.', '',
              f'Among the {challenge["pairsWithAtLeastOneRelevantPost"]} challenge pairs where at least one method returned a relevant post, Ask AI 3 won {wins.get("ask-ai-3", 0)}, Ask AI 2 won {wins.get("ask-ai-2", 0)}, and {wins.get("tie", 0)} tied. This is a secondary diagnostic; the predefined full-cohort win counts are unchanged.', '',
              f'Ask AI 3 alone returned relevant posts on questions {challenge["onlyOneMethodReturnedRelevantPosts"]["ask-ai-3"]}; Ask AI 2 alone did so on {challenge["onlyOneMethodReturnedRelevantPosts"]["ask-ai-2"]}. This concerns the returned top tens, not whether relevant posts exist elsewhere in the corpus.', '']
    (c.PUBLIC / 'completion-notes.md').write_text('\n'.join(lines), encoding='utf-8')
    path = c.PUBLIC / 'report.md'
    text = path.read_text(encoding='utf-8')
    text = text.replace('Fresh AI grades read the complete posts', 'The AI grader receives the complete posts')
    text = text.replace('| Average cost per question |', '| Average successful-search cost |')
    text = text.replace('| Average submit-to-display |', '| Average successful submit-to-display |')
    text = text.replace('each question/post pair graded once;', 'one accepted grade per question/post pair is applied to both methods, with fresh rechecks when evidence or labels fail validation;')
    text = text.replace('Separate full-text grading cost so far:', 'Full-text grading cost, including rechecks:')
    banner = '\n**Final status:** All 250 questions were attempted. Paired relevance grades are available for 249; question 207 had an unresolved Ask AI 3 technical failure. The challenge tables cover 49 completed pairs, not all 50. [Read the reliability and complete cost accounting notes](completion-notes.md).\n'
    if '**Final status:**' not in text:
        text = text.replace('\n\nBoth methods searched', banner + '\nBoth methods searched', 1)
    if '### Useful-Result Caveat' not in text:
        caveat = (f'### Useful-Result Caveat\n\n{len(challenge["neitherReturnedRelevantPosts"])} challenge pairs had no substantially relevant result from either method; their ranking wins can reflect marginal posts only. '
                  f'Among the {challenge["pairsWithAtLeastOneRelevantPost"]} pairs with a relevant result from at least one method, Ask AI 3 won {wins.get("ask-ai-3", 0)}, Ask AI 2 won {wins.get("ask-ai-2", 0)}, and {wins.get("tie", 0)} tied. '
                  'The predefined 49-pair totals above are unchanged. [Coverage details](completion-notes.md).\n\n')
        text = text.replace('## How to interpret these results', caveat + '## How to interpret these results', 1)
    path.write_text(text, encoding='utf-8')
    print(json.dumps(summary))


if __name__ == '__main__':
    main()
