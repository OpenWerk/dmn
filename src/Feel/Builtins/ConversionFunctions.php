<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Builtins;

use Brick\Math\RoundingMode;
use OpenWerk\DecisionModelAndNotation\Feel\Semantics\FeelError;
use OpenWerk\DecisionModelAndNotation\Feel\Semantics\Ops;
use OpenWerk\DecisionModelAndNotation\Feel\Value\DaysTimeDuration;
use OpenWerk\DecisionModelAndNotation\Feel\Value\EmptyContext;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelDate;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelDateTime;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelNumber;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelRange;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelTime;
use OpenWerk\DecisionModelAndNotation\Feel\Value\TemporalParser;
use OpenWerk\DecisionModelAndNotation\Feel\Value\YearsMonthsDuration;

/**
 * Conversion functions: date, time, date and time, duration, number,
 * string, context (DMN 1.5, 10.3.4.1).
 *
 * @internal
 */
final class ConversionFunctions
{
    private function __construct()
    {
    }

    public static function register(BuiltinCatalog $catalog): void
    {
        $catalog->register(
            'date',
            [[['from'], 1], [['year', 'month', 'day'], 3]],
            static function (array $args): mixed {
                if (\count($args) === 3) {
                    return new FeelDate(
                        Args::integer($args[0], 'date', 'year'),
                        Args::integer($args[1], 'date', 'month'),
                        Args::integer($args[2], 'date', 'day'),
                    );
                }

                $from = Args::unwrapSingleton($args[0]);

                if (\is_string($from)) {
                    return TemporalParser::date($from);
                }

                if ($from instanceof FeelDate) {
                    return $from;
                }

                if ($from instanceof FeelDateTime) {
                    return $from->datePart();
                }

                throw Args::typeError('date', 'from', 'a string, date or date and time', $from);
            },
        );

        $catalog->register(
            'time',
            [[['from'], 1], [['hour', 'minute', 'second', 'offset'], 3]],
            static function (array $args): mixed {
                if (\count($args) === 4) {
                    return self::timeFromParts(array_values($args));
                }

                $from = Args::unwrapSingleton($args[0]);

                if (\is_string($from)) {
                    return TemporalParser::time($from);
                }

                if ($from instanceof FeelTime) {
                    return $from;
                }

                if ($from instanceof FeelDateTime) {
                    return $from->timePart();
                }

                if ($from instanceof FeelDate) {
                    // Midnight UTC per the specification's date coercion.
                    return new FeelTime(0, 0, 0, 0, 0);
                }

                throw Args::typeError('time', 'from', 'a string, time, date or date and time', $from);
            },
        );

        $catalog->register(
            'date and time',
            [[['from'], 1], [['date', 'time'], 2]],
            static function (array $args): mixed {
                if (\count($args) === 2) {
                    $date = $args[0] instanceof FeelDateTime ? $args[0]->datePart() : $args[0];

                    if (!$date instanceof FeelDate) {
                        throw Args::typeError('date and time', 'date', 'a date (and time)', $args[0]);
                    }

                    if (!$args[1] instanceof FeelTime) {
                        throw Args::typeError('date and time', 'time', 'a time', $args[1]);
                    }

                    return FeelDateTime::fromDateAndTime($date, $args[1]);
                }

                $from = Args::unwrapSingleton($args[0]);

                if (\is_string($from)) {
                    return TemporalParser::dateAndTime($from);
                }

                if ($from instanceof FeelDateTime) {
                    return $from;
                }

                if ($from instanceof FeelDate) {
                    return FeelDateTime::fromDateAndTime($from, new FeelTime(0, 0, 0));
                }

                throw Args::typeError('date and time', 'from', 'a string, date or date and time', $from);
            },
        );

        $catalog->register(
            'duration',
            [[['from'], 1]],
            static function (array $args): mixed {
                $from = Args::unwrapSingleton($args[0]);

                if (\is_string($from)) {
                    return TemporalParser::duration($from);
                }

                if ($from instanceof YearsMonthsDuration || $from instanceof DaysTimeDuration) {
                    return $from;
                }

                throw Args::typeError('duration', 'from', 'an ISO-8601 duration string', $from);
            },
        );

        $catalog->register(
            'number',
            [[['from', 'grouping separator', 'decimal separator'], 1]],
            static function (array $args): mixed {
                $from = Args::string($args[0], 'number', 'from');
                $grouping = Args::optionalString($args[1], 'number', 'grouping separator');
                $decimal = Args::optionalString($args[2], 'number', 'decimal separator');

                if ($grouping !== null && !\in_array($grouping, [' ', ',', '.'], true)) {
                    throw new FeelError('number(): grouping separator must be space, comma or period');
                }

                if ($decimal !== null && !\in_array($decimal, [',', '.'], true)) {
                    throw new FeelError('number(): decimal separator must be comma or period');
                }

                if ($grouping !== null && $grouping === $decimal) {
                    throw new FeelError('number(): grouping and decimal separators must differ');
                }

                if ($grouping !== null) {
                    $from = str_replace($grouping, '', $from);
                }

                if ($decimal !== null && $decimal !== '.') {
                    $from = str_replace($decimal, '.', $from);
                }

                return FeelNumber::of(trim($from));
            },
        );

        $catalog->register(
            'string',
            [[['from'], 1]],
            static function (array $args): mixed {
                if ($args[0] === null) {
                    return null;
                }

                return self::stringify($args[0], false);
            },
        );

        $catalog->register(
            'range',
            [[['from'], 1]],
            static function (array $args): mixed {
                $from = trim(Args::string($args[0], 'range', 'from'));

                if ($from === '' || !\in_array($from[0], ['[', '('], true) && $from[0] !== ']') {
                    throw new FeelError('range(): from must be a range literal string');
                }

                $last = $from[\strlen($from) - 1];

                if (!\in_array($last, [']', ')', '['], true)) {
                    throw new FeelError('range(): from must end with a range bound');
                }

                $inner = substr($from, 1, -1);
                $parts = preg_split('/\.\./', $inner, 2);

                if ($parts === false || \count($parts) !== 2) {
                    throw new FeelError('range(): from must contain ".." between the endpoints');
                }

                $startIncluded = $from[0] === '[';
                $endIncluded = $last === ']';
                $start = self::rangeEndpoint($parts[0]);
                $end = self::rangeEndpoint($parts[1]);

                // A descending interval is not a valid range (TCK 1156).
                if ($start !== null && $end !== null && Ops::compare($start, $end) > 0) {
                    throw new FeelError('range(): the start endpoint must not exceed the end');
                }

                return FeelRange::of($startIncluded, $start, $end, $endIncluded);
            },
        );

        $catalog->register(
            'context',
            [[['entries'], 1]],
            static function (array $args): mixed {
                $entries = Args::listOf($args[0], 'context', 'entries');
                $context = [];

                foreach ($entries as $entry) {
                    if (!Ops::isContext($entry)) {
                        throw Args::typeError('context', 'entries', 'a list of key/value contexts', $entry);
                    }

                    \assert(\is_array($entry));
                    $key = $entry['key'] ?? null;

                    if (!\is_string($key) || !\array_key_exists('value', $entry)) {
                        throw new FeelError('context(): every entry needs a string key and a value');
                    }

                    if (\array_key_exists($key, $context)) {
                        throw new FeelError(sprintf('context(): duplicate key %s', var_export($key, true)));
                    }

                    $context[$key] = $entry['value'];
                }

                return $context === [] ? EmptyContext::instance() : $context;
            },
        );
    }

    /**
     * @param list<mixed> $args
     */
    private static function timeFromParts(array $args): FeelTime
    {
        $seconds = Args::number($args[2], 'time', 'second');
        $wholeSeconds = $seconds->toBigDecimal()->toScale(0, RoundingMode::DOWN)->toInt();
        $micro = $seconds->minus(FeelNumber::of($wholeSeconds))
            ->multipliedBy(FeelNumber::of(1_000_000))
            ->toInt();

        $offsetSeconds = null;

        if ($args[3] !== null) {
            if (!$args[3] instanceof DaysTimeDuration) {
                throw Args::typeError('time', 'offset', 'a days and time duration', $args[3]);
            }

            if ($args[3]->micros !== 0) {
                throw new FeelError('time(): offset must be whole seconds');
            }

            $offsetSeconds = $args[3]->seconds;
        }

        return new FeelTime(
            Args::integer($args[0], 'time', 'hour'),
            Args::integer($args[1], 'time', 'minute'),
            $wholeSeconds,
            $micro,
            $offsetSeconds,
        );
    }

    /**
     * Evaluates one endpoint of a range literal string. Only literal
     * endpoints are allowed: numbers, strings, at-literals and temporal
     * constructors over literal arguments.
     */
    private static function rangeEndpoint(string $text): mixed
    {
        $text = trim($text);

        if ($text === '') {
            throw new FeelError('range(): endpoints must not be empty');
        }

        $parser = new \OpenWerk\DecisionModelAndNotation\Feel\Parser\FeelParser(
            \OpenWerk\DecisionModelAndNotation\Feel\Parser\NameRegistry::withBuiltins(),
        );
        $evaluator = new \OpenWerk\DecisionModelAndNotation\Feel\Evaluator\FeelEvaluator(
            new \OpenWerk\DecisionModelAndNotation\Diagnostics\DiagnosticCollector(),
        );

        try {
            $node = $parser->parseExpression($text);
        } catch (\OpenWerk\DecisionModelAndNotation\Exception\FeelParseException) {
            throw new FeelError(sprintf('range(): invalid endpoint %s', var_export($text, true)));
        }

        if (!self::isLiteralEndpoint($node)) {
            throw new FeelError(sprintf('range(): endpoint %s is not a literal', var_export($text, true)));
        }

        $value = $evaluator->evaluate($node, \OpenWerk\DecisionModelAndNotation\Feel\Evaluator\Scope::empty());

        if ($value === null) {
            throw new FeelError(sprintf('range(): endpoint %s is not a constant', var_export($text, true)));
        }

        return $value;
    }

    private static function isLiteralEndpoint(\OpenWerk\DecisionModelAndNotation\Feel\Ast\Node $node): bool
    {
        if (
            $node instanceof \OpenWerk\DecisionModelAndNotation\Feel\Ast\NumberLiteral
            || $node instanceof \OpenWerk\DecisionModelAndNotation\Feel\Ast\StringLiteral
            || $node instanceof \OpenWerk\DecisionModelAndNotation\Feel\Ast\BooleanLiteral
            || $node instanceof \OpenWerk\DecisionModelAndNotation\Feel\Ast\AtLiteral
        ) {
            return true;
        }

        if ($node instanceof \OpenWerk\DecisionModelAndNotation\Feel\Ast\Negation) {
            return self::isLiteralEndpoint($node->operand);
        }

        if ($node instanceof \OpenWerk\DecisionModelAndNotation\Feel\Ast\FunctionCall) {
            $name = $node->calleeName();

            if (!\in_array($name, ['date', 'time', 'date and time', 'duration'], true)) {
                return false;
            }

            foreach ($node->arguments as $argument) {
                if (!self::isLiteralEndpoint($argument)) {
                    return false;
                }
            }

            return $node->namedArguments === [];
        }

        return false;
    }

    /**
     * FEEL string conversion; nested values inside lists and contexts keep
     * their literal (quoted) form.
     */
    public static function stringify(mixed $value, bool $nested): string
    {
        if ($value === null) {
            return 'null';
        }

        if (\is_string($value)) {
            return $nested ? var_export($value, true) : $value;
        }

        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        if ($value instanceof FeelRange) {
            return ($value->startIncluded ? '[' : '(')
                . self::stringify($value->start, true)
                . '..'
                . self::stringify($value->end, true)
                . ($value->endIncluded ? ']' : ')');
        }

        if ($value instanceof EmptyContext) {
            return '{}';
        }

        if (\is_array($value)) {
            $parts = [];

            if (Ops::isList($value)) {
                foreach ($value as $item) {
                    $parts[] = self::stringify($item, true);
                }

                return '[' . implode(', ', $parts) . ']';
            }

            foreach ($value as $key => $item) {
                $parts[] = $key . ': ' . self::stringify($item, true);
            }

            return '{' . implode(', ', $parts) . '}';
        }

        return Ops::typeName($value);
    }
}
