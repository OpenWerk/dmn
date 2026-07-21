<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tck;

use OpenWerk\DecisionModelAndNotation\Feel\Semantics\Ops;

/**
 * Aggregated results of a TCK run: per-level counts, pass rate, the TCK
 * result CSV and a human-readable summary.
 *
 * @internal
 */
final class TckReport
{
    /**
     * @param list<CaseResult> $results
     */
    public function __construct(public readonly array $results)
    {
    }

    public function total(): int
    {
        return \count($this->results);
    }

    public function count(CaseOutcome $outcome): int
    {
        $count = 0;

        foreach ($this->results as $result) {
            if ($result->outcome === $outcome) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Percentage of successful cases (0.0 - 100.0); 100.0 for an empty run.
     */
    public function passRate(): float
    {
        if ($this->results === []) {
            return 100.0;
        }

        return $this->count(CaseOutcome::Success) * 100.0 / $this->total();
    }

    public function writeCsv(string $path): void
    {
        $lines = ["test_suite,test_case,result"];

        foreach ($this->results as $result) {
            $lines[] = sprintf('%s,%s,%s', $result->suite, $result->caseId, $result->outcome->value);
        }

        if (@file_put_contents($path, implode("\n", $lines) . "\n") === false) {
            throw new TckFormatException(sprintf('cannot write results CSV to %s', var_export($path, true)));
        }
    }

    public function renderSummary(bool $withDetails): string
    {
        $byLevel = [];

        foreach ($this->results as $result) {
            $level = explode('/', $result->suite)[0];
            $byLevel[$level][] = $result;
        }

        $out = [];

        foreach ($byLevel as $level => $results) {
            $report = new self($results);
            $out[] = sprintf(
                '%s: %d cases, %d success, %d failure, %d error — pass rate %.1f%%',
                $level,
                $report->total(),
                $report->count(CaseOutcome::Success),
                $report->count(CaseOutcome::Failure),
                $report->count(CaseOutcome::Error),
                $report->passRate(),
            );
        }

        $unsuccessful = [];

        foreach ($this->results as $result) {
            if ($result->outcome !== CaseOutcome::Success) {
                $unsuccessful[] = $result;
            }
        }

        if ($unsuccessful !== []) {
            $out[] = '';
            $out[] = 'non-passing cases:';

            foreach ($unsuccessful as $result) {
                $out[] = sprintf('  %s [%s] %s', $result->suite, $result->caseId, $result->outcome->value);

                if ($withDetails) {
                    foreach ($result->details as $detail) {
                        $out[] = '    - ' . $detail;
                    }
                }
            }
        }

        return implode("\n", $out);
    }

    /**
     * Human-readable rendering of a FEEL value for mismatch messages.
     */
    public static function describe(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (\is_string($value)) {
            return var_export($value, true);
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        if (\is_array($value)) {
            $parts = [];

            if (Ops::isList($value)) {
                foreach ($value as $item) {
                    $parts[] = self::describe($item);
                }

                return '[' . implode(', ', $parts) . ']';
            }

            foreach ($value as $key => $item) {
                $parts[] = $key . ': ' . self::describe($item);
            }

            return '{' . implode(', ', $parts) . '}';
        }

        return get_debug_type($value);
    }
}
