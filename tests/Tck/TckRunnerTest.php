<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tests\Tck;

use OpenWerk\DecisionModelAndNotation\Tck\CaseOutcome;
use OpenWerk\DecisionModelAndNotation\Tck\CaseResult;
use OpenWerk\DecisionModelAndNotation\Tck\TckRunner;
use PHPUnit\Framework\TestCase;

final class TckRunnerTest extends TestCase
{
    private const string MINI_CORPUS = __DIR__ . '/../fixtures/tck-mini';

    public function testRunsMiniCorpusAndAggregatesOutcomes(): void
    {
        $report = (new TckRunner())->run(self::MINI_CORPUS, ['compliance-level-2']);

        self::assertSame(3, $report->total());
        self::assertSame(1, $report->count(CaseOutcome::Success));
        self::assertSame(1, $report->count(CaseOutcome::Failure));
        self::assertSame(1, $report->count(CaseOutcome::Error));
        self::assertEqualsWithDelta(33.3, $report->passRate(), 0.1);

        $find = static function (CaseOutcome $outcome) use ($report): CaseResult {
            foreach ($report->results as $result) {
                if ($result->outcome === $outcome) {
                    return $result;
                }
            }

            self::fail('no case with outcome ' . $outcome->value);
        };

        $success = $find(CaseOutcome::Success);
        self::assertSame('compliance-level-2/0001-sample', $success->suite);
        self::assertSame('001', $success->caseId);

        $failure = $find(CaseOutcome::Failure);
        self::assertSame('002', $failure->caseId);
        self::assertStringContainsString("expected 'Hello Eve', got 'Hi Eve'", $failure->details[0]);

        self::assertSame('compliance-level-2/0002-broken', $find(CaseOutcome::Error)->suite);
    }

    public function testSuiteFilterLimitsExecution(): void
    {
        $report = (new TckRunner())->run(self::MINI_CORPUS, ['compliance-level-2'], '0001-*');

        self::assertSame(2, $report->total());
        self::assertSame(0, $report->count(CaseOutcome::Error));
    }

    public function testCsvOutputMatchesTckResultShape(): void
    {
        $report = (new TckRunner())->run(self::MINI_CORPUS, ['compliance-level-2'], '0001-*');

        $path = tempnam(sys_get_temp_dir(), 'tck');
        self::assertNotFalse($path);

        try {
            $report->writeCsv($path);
            $csv = file_get_contents($path);

            self::assertNotFalse($csv);
            $lines = explode("\n", trim($csv));
            self::assertSame('test_suite,test_case,result', $lines[0]);
            self::assertSame('compliance-level-2/0001-sample,001,SUCCESS', $lines[1]);
            self::assertSame('compliance-level-2/0001-sample,002,FAILURE', $lines[2]);
        } finally {
            @unlink($path);
        }
    }

    public function testSummaryRendersCountsAndNonPassingCases(): void
    {
        $report = (new TckRunner())->run(self::MINI_CORPUS, ['compliance-level-2']);
        $summary = $report->renderSummary(false);

        self::assertStringContainsString('compliance-level-2: 3 cases, 1 success, 1 failure, 1 error', $summary);
        self::assertStringContainsString('pass rate 33.3%', $summary);
        self::assertStringContainsString('non-passing cases:', $summary);
    }
}
