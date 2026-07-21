<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tests\Tck;

use OpenWerk\DecisionModelAndNotation\Tck\TckRunner;
use OpenWerk\DecisionModelAndNotation\Tck\TckSubmission;
use PHPUnit\Framework\TestCase;

final class TckSubmissionTest extends TestCase
{
    private const string MINI_CORPUS = __DIR__ . '/../fixtures/tck-mini';

    public function testWritesTheOfficialSubmissionLayout(): void
    {
        $report = (new TckRunner())->run(self::MINI_CORPUS, ['compliance-level-2']);
        $root = sys_get_temp_dir() . '/tck-submission-' . bin2hex(random_bytes(4));

        try {
            $directory = TckSubmission::write($report, $root, '1.0.0', '2026-07-17');

            self::assertSame($root . '/OpenWerk/1.0.0', $directory);

            $csv = (string) file_get_contents($directory . '/tck_results.csv');
            $lines = explode("\n", trim($csv));

            // Header plus one row per case; rows carry the reporter's five
            // fields with the level/suite in the folder segment.
            self::assertSame('"Test Folder","Test Suite","Test Case","Result","Comment"', $lines[0]);
            self::assertSame(
                'TestCases/compliance-level-2/0001-sample,0001-sample-test-01,001,SUCCESS,""',
                $lines[1],
            );
            self::assertSame(
                'TestCases/compliance-level-2/0001-sample,0001-sample-test-01,002,ERROR,"value mismatch"',
                $lines[2],
            );

            $properties = (string) file_get_contents($directory . '/tck_results.properties');

            foreach (
                [
                    'vendor.name=OpenWerk',
                    'product.name=openwerk/dmn',
                    'product.url=https://github.com/OpenWerk/dmn',
                    'product.version=1.0.0',
                    'last.update=2026-07-17',
                    'instructions.url=',
                    'product.comment=',
                    'vendor.url=',
                ] as $expected
            ) {
                self::assertStringContainsString($expected, $properties);
            }
        } finally {
            @unlink($root . '/OpenWerk/1.0.0/tck_results.csv');
            @unlink($root . '/OpenWerk/1.0.0/tck_results.properties');
            @rmdir($root . '/OpenWerk/1.0.0');
            @rmdir($root . '/openwerk');
            @rmdir($root);
        }
    }

    public function testErrorsKeepTheirFirstDetailAsComment(): void
    {
        $report = (new TckRunner())->run(self::MINI_CORPUS, ['compliance-level-2']);
        $root = sys_get_temp_dir() . '/tck-submission-' . bin2hex(random_bytes(4));

        try {
            $directory = TckSubmission::write($report, $root, 'dev', '2026-07-17');
            $csv = (string) file_get_contents($directory . '/tck_results.csv');

            // The broken suite reports ERROR with a loading comment; quotes
            // inside comments are downgraded so the row stays parseable.
            self::assertMatchesRegularExpression(
                '/^TestCases\/compliance-level-2\/0002-broken,0002-broken-test-01,[^,]+,ERROR,"[^"]*"$/m',
                $csv,
            );
            self::assertStringNotContainsString('FAILURE', $csv);
        } finally {
            @unlink($root . '/OpenWerk/dev/tck_results.csv');
            @unlink($root . '/OpenWerk/dev/tck_results.properties');
            @rmdir($root . '/OpenWerk/dev');
            @rmdir($root . '/openwerk');
            @rmdir($root);
        }
    }
}
