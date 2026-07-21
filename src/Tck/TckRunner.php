<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tck;

use OpenWerk\DecisionModelAndNotation\Dmn;
use OpenWerk\DecisionModelAndNotation\DmnModel;

/**
 * Executes the DMN TCK corpus (github.com/dmn-tck/tck) against the engine
 * and produces a {@see TckReport}.
 *
 * The corpus layout is `<TestCases>/compliance-level-N/<suite>/` with one
 * model file (named by each test file's `<modelName>`) plus one or more
 * `*-test-*.xml` files per suite.
 *
 * @internal
 */
final class TckRunner
{
    /**
     * @param list<string> $levelDirs   e.g. ["compliance-level-2"]
     * @param string|null  $suiteFilter fnmatch pattern against the suite directory name
     */
    public function run(string $testCasesDir, array $levelDirs, ?string $suiteFilter = null): TckReport
    {
        $results = [];

        foreach ($levelDirs as $levelDir) {
            $levelPath = rtrim($testCasesDir, '/') . '/' . $levelDir;

            if (!is_dir($levelPath)) {
                throw new TckFormatException(sprintf(
                    'TCK level directory %s does not exist',
                    var_export($levelPath, true),
                ));
            }

            foreach ($this->suiteDirs($levelPath) as $suiteDir) {
                if ($suiteFilter !== null && !fnmatch($suiteFilter, basename($suiteDir))) {
                    continue;
                }

                $suite = $levelDir . '/' . basename($suiteDir);

                foreach ($this->runSuite($suite, $suiteDir) as $result) {
                    $results[] = $result;
                }
            }
        }

        return new TckReport($results);
    }

    /**
     * @return list<string>
     */
    private function suiteDirs(string $levelPath): array
    {
        $entries = @scandir($levelPath);

        if ($entries === false) {
            return [];
        }

        $dirs = [];

        foreach ($entries as $entry) {
            $path = $levelPath . '/' . $entry;

            if ($entry !== '.' && $entry !== '..' && is_dir($path)) {
                $dirs[] = $path;
            }
        }

        sort($dirs);

        return $dirs;
    }

    /**
     * @return list<CaseResult>
     */
    private function runSuite(string $suite, string $suiteDir): array
    {
        $testFiles = glob($suiteDir . '/*-test-*.xml');

        if ($testFiles === false || $testFiles === []) {
            return [];
        }

        sort($testFiles);
        $results = [];

        foreach ($testFiles as $testFile) {
            $testFileBase = basename($testFile, '.xml');

            try {
                $file = TckTestCasesFile::fromFile($testFile);
                $model = Dmn::fromFile($suiteDir . '/' . $file->modelName);
            } catch (\Throwable $exception) {
                $results[] = new CaseResult(
                    $suite,
                    basename($testFile),
                    CaseOutcome::Error,
                    ['cannot load suite: ' . $exception->getMessage()],
                    $testFileBase,
                );

                continue;
            }

            foreach ($file->testCases as $testCase) {
                $results[] = $this->runCase($suite, $model, $testCase, $testFileBase);
            }
        }

        return $results;
    }

    private function runCase(string $suite, DmnModel $model, TckTestCase $testCase, string $testFileBase): CaseResult
    {
        $details = [];
        $failed = false;

        foreach ($testCase->expectations as $expectation) {
            try {
                if ($testCase->invocableName !== null) {
                    // Direct invocation: the result node names the service
                    // itself or one of its output decisions.
                    try {
                        $result = $model->evaluateService(
                            $testCase->invocableName,
                            $testCase->inputs,
                            $testCase->namespacedInputs,
                        );
                    } catch (\OpenWerk\DecisionModelAndNotation\Exception\UnknownDecisionException) {
                        $result = $model->evaluateDecision(
                            $testCase->invocableName,
                            $testCase->inputs,
                            $testCase->namespacedInputs,
                        );
                    }
                } else {
                    try {
                        $result = $model->evaluateDecision(
                            $expectation->decision,
                            $testCase->inputs,
                            $testCase->namespacedInputs,
                        );
                    } catch (\OpenWerk\DecisionModelAndNotation\Exception\UnknownDecisionException) {
                        // result nodes may reference decision services by name
                        $result = $model->evaluateService(
                            $expectation->decision,
                            $testCase->inputs,
                            $testCase->namespacedInputs,
                        );
                    }
                }
            } catch (\Throwable $exception) {
                return new CaseResult($suite, $testCase->id, CaseOutcome::Error, [sprintf(
                    '%s: %s',
                    $expectation->decision,
                    $exception->getMessage(),
                )], $testFileBase);
            }

            $actual = $result->feelValue();

            if (
                $testCase->invocableName !== null
                && $expectation->decision !== $testCase->invocableName
                && \is_array($actual)
                && \array_key_exists($expectation->decision, $actual)
            ) {
                $actual = $actual[$expectation->decision];
            }

            if (!TckComparator::equals($expectation->expected, $actual)) {
                $failed = true;
                $details[] = sprintf(
                    '%s: expected %s, got %s',
                    $expectation->decision,
                    TckReport::describe($expectation->expected),
                    TckReport::describe($actual),
                );

                foreach ($result->diagnostics() as $diagnostic) {
                    $details[] = 'diagnostic: ' . $diagnostic;
                }
            }
        }

        return new CaseResult(
            $suite,
            $testCase->id,
            $failed ? CaseOutcome::Failure : CaseOutcome::Success,
            $details,
            $testFileBase,
        );
    }
}
