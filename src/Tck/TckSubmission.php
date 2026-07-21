<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tck;

/**
 * Writes a report in the DMN TCK's official submission layout, as consumed
 * by the TCK's reporter (`runners/dmn-tck-reporter`):
 *
 *   <root>/OpenWerk/<version>/tck_results.csv
 *   <root>/OpenWerk/<version>/tck_results.properties
 *
 * The CSV rows are `testFolder,testFile,testCase,result,comment`, where the
 * reporter derives the suite from the last two segments of `testFolder` and
 * understands the results SUCCESS, ERROR and IGNORED. This engine's
 * FAILURE (a value mismatch) is reported as ERROR — the reporter has no
 * separate failure kind — with the distinction preserved in the comment.
 *
 * @internal
 */
final class TckSubmission
{
    private const string VENDOR_NAME = 'OpenWerk';
    private const string VENDOR_URL = 'https://github.com/openwerk';
    private const string PRODUCT_NAME = 'openwerk/dmn';
    private const string PRODUCT_URL = 'https://github.com/OpenWerk/dmn';
    private const string PRODUCT_COMMENT = 'Pure PHP framework-agnostic DMN decision engine.';
    private const string INSTRUCTIONS_URL = 'https://github.com/OpenWerk/dmn#conformance-check-it-yourself';

    private function __construct()
    {
    }

    /**
     * Writes the submission files and returns the version directory.
     */
    public static function write(
        TckReport $report,
        string $rootDirectory,
        string $productVersion,
        string $lastUpdate,
    ): string {
        $directory = rtrim($rootDirectory, '/') . '/' . self::VENDOR_NAME . '/' . $productVersion;

        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new TckFormatException(
                sprintf('cannot create submission directory %s', var_export($directory, true)),
            );
        }

        self::writeFile($directory . '/tck_results.csv', self::csv($report));
        self::writeFile($directory . '/tck_results.properties', self::properties($productVersion, $lastUpdate));

        return $directory;
    }

    private static function csv(TckReport $report): string
    {
        $lines = ['"Test Folder","Test Suite","Test Case","Result","Comment"'];

        foreach ($report->results as $result) {
            $comment = match ($result->outcome) {
                CaseOutcome::Success => '',
                CaseOutcome::Failure => 'value mismatch',
                CaseOutcome::Error => $result->details[0] ?? 'evaluation error',
            };

            $lines[] = sprintf(
                '%s,%s,%s,%s,"%s"',
                'TestCases/' . $result->suite,
                $result->testFile ?? '',
                $result->caseId,
                $result->outcome === CaseOutcome::Success ? 'SUCCESS' : 'ERROR',
                str_replace(['"', "\n", "\r"], ["'", ' ', ' '], $comment),
            );
        }

        return implode("\n", $lines) . "\n";
    }

    private static function properties(string $productVersion, string $lastUpdate): string
    {
        $lines = [
            'vendor.name=' . self::VENDOR_NAME,
            'vendor.url=' . self::VENDOR_URL,
            'product.name=' . self::PRODUCT_NAME,
            'product.url=' . self::PRODUCT_URL,
            'product.version=' . $productVersion,
            'product.comment=' . self::PRODUCT_COMMENT,
            'instructions.url=' . self::INSTRUCTIONS_URL,
            'last.update=' . $lastUpdate,
        ];

        return implode("\n", $lines) . "\n";
    }

    private static function writeFile(string $path, string $content): void
    {
        if (@file_put_contents($path, $content) === false) {
            throw new TckFormatException(sprintf('cannot write %s', var_export($path, true)));
        }
    }
}
