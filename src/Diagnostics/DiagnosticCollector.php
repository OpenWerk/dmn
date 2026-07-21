<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Diagnostics;

/**
 * Mutable collector used internally during a single evaluation run.
 * The public API only ever exposes the immutable list of diagnostics.
 *
 * @internal
 */
final class DiagnosticCollector
{
    /** @var list<Diagnostic> */
    private array $diagnostics = [];

    private ?string $decision = null;

    private bool $muted = false;

    /**
     * A collector that drops everything: for probe evaluations whose
     * findings are never reported (and must not accumulate).
     */
    public static function muted(): self
    {
        $collector = new self();
        $collector->muted = true;

        return $collector;
    }

    public function add(Severity $severity, string $code, string $message): void
    {
        if ($this->muted) {
            return;
        }

        $this->diagnostics[] = new Diagnostic($severity, $code, $message, $this->decision);
    }

    public function info(string $code, string $message): void
    {
        $this->add(Severity::Info, $code, $message);
    }

    public function warning(string $code, string $message): void
    {
        $this->add(Severity::Warning, $code, $message);
    }

    public function error(string $code, string $message): void
    {
        $this->add(Severity::Error, $code, $message);
    }

    /**
     * Tags all diagnostics added while $fn runs with the given decision name.
     *
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     */
    public function withDecision(string $decision, callable $fn): mixed
    {
        $previous = $this->decision;
        $this->decision = $decision;

        try {
            return $fn();
        } finally {
            $this->decision = $previous;
        }
    }

    /**
     * @return list<Diagnostic>
     */
    public function all(): array
    {
        return $this->diagnostics;
    }
}
