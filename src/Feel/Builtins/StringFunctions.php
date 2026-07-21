<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Builtins;

use OpenWerk\DecisionModelAndNotation\Feel\Semantics\FeelError;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelNumber;

/**
 * String functions (DMN 1.5, 10.3.4.3). Positions and lengths are
 * codepoint-based. `matches`/`replace`/`split` map the spec's XPath regular
 * expressions onto PCRE (documented deviation for exotic XSD constructs).
 *
 * @internal
 */
final class StringFunctions
{
    private function __construct()
    {
    }

    public static function register(BuiltinCatalog $catalog): void
    {
        $catalog->register(
            'substring',
            [[['string', 'start position', 'length'], 2]],
            static function (array $args, array $provided): mixed {
                $chars = self::chars(Args::string($args[0], 'substring', 'string'));
                $start = Args::integer($args[1], 'substring', 'start position');
                // A fractional length truncates (3.8 → 3) per the TCK's
                // implicit-conversion expectations.
                $length = ($provided[2] ?? false) ? Args::truncatedInteger($args[2], 'substring', 'length') : null;

                if ($start === 0) {
                    throw new FeelError('substring(): start position must not be zero');
                }

                $index = $start > 0 ? $start - 1 : \count($chars) + $start;

                if ($index < 0 || $index >= \count($chars)) {
                    return '';
                }

                $slice = $length === null
                    ? \array_slice($chars, $index)
                    : \array_slice($chars, $index, max(0, $length));

                return implode('', $slice);
            },
        );

        $catalog->register(
            'string length',
            [[['string'], 1]],
            static fn(array $args): mixed => FeelNumber::of(
                \count(self::chars(Args::string($args[0], 'string length', 'string'))),
            ),
        );

        $catalog->register(
            'upper case',
            [[['string'], 1]],
            static function (array $args): mixed {
                $string = Args::string($args[0], 'upper case', 'string');

                return \function_exists('mb_strtoupper') ? mb_strtoupper($string, 'UTF-8') : strtoupper($string);
            },
        );

        $catalog->register(
            'lower case',
            [[['string'], 1]],
            static function (array $args): mixed {
                $string = Args::string($args[0], 'lower case', 'string');

                return \function_exists('mb_strtolower') ? mb_strtolower($string, 'UTF-8') : strtolower($string);
            },
        );

        $catalog->register(
            'substring before',
            [[['string', 'match'], 2]],
            static function (array $args): mixed {
                $string = Args::string($args[0], 'substring before', 'string');
                $match = Args::string($args[1], 'substring before', 'match');
                $position = strpos($string, $match);

                return $position === false ? '' : substr($string, 0, $position);
            },
        );

        $catalog->register(
            'substring after',
            [[['string', 'match'], 2]],
            static function (array $args): mixed {
                $string = Args::string($args[0], 'substring after', 'string');
                $match = Args::string($args[1], 'substring after', 'match');
                $position = strpos($string, $match);

                return $position === false ? '' : substr($string, $position + \strlen($match));
            },
        );

        $catalog->register(
            'contains',
            [[['string', 'match'], 2]],
            static fn(array $args): mixed => str_contains(
                Args::string($args[0], 'contains', 'string'),
                Args::string($args[1], 'contains', 'match'),
            ),
        );

        $catalog->register(
            'starts with',
            [[['string', 'match'], 2]],
            static fn(array $args): mixed => str_starts_with(
                Args::string($args[0], 'starts with', 'string'),
                Args::string($args[1], 'starts with', 'match'),
            ),
        );

        $catalog->register(
            'ends with',
            [[['string', 'match'], 2]],
            static fn(array $args): mixed => str_ends_with(
                Args::string($args[0], 'ends with', 'string'),
                Args::string($args[1], 'ends with', 'match'),
            ),
        );

        $catalog->register(
            'matches',
            [[['input', 'pattern', 'flags'], 2]],
            static function (array $args): mixed {
                $input = Args::string($args[0], 'matches', 'input');
                $regex = self::regex(
                    Args::string($args[1], 'matches', 'pattern'),
                    Args::optionalString($args[2], 'matches', 'flags'),
                    'matches',
                );

                $result = @preg_match($regex, $input);

                if ($result === false) {
                    throw new FeelError('matches(): invalid pattern');
                }

                return $result === 1;
            },
        );

        $catalog->register(
            'replace',
            [[['input', 'pattern', 'replacement', 'flags'], 3]],
            static function (array $args): mixed {
                $input = Args::string($args[0], 'replace', 'input');
                $regex = self::regex(
                    Args::string($args[1], 'replace', 'pattern'),
                    Args::optionalString($args[3], 'replace', 'flags'),
                    'replace',
                );
                $replacement = Args::string($args[2], 'replace', 'replacement');

                // XPath group references are $1..$9; PCRE understands them
                // natively. Escape backslashes not part of an escape.
                $result = @preg_replace($regex, $replacement, $input);

                if ($result === null) {
                    throw new FeelError('replace(): invalid pattern or replacement');
                }

                return $result;
            },
        );

        $catalog->register(
            'split',
            [[['string', 'delimiter'], 2]],
            static function (array $args): mixed {
                $string = Args::string($args[0], 'split', 'string');
                $regex = self::regex(Args::string($args[1], 'split', 'delimiter'), null, 'split');

                $parts = @preg_split($regex, $string);

                if ($parts === false) {
                    throw new FeelError('split(): invalid delimiter pattern');
                }

                return $parts;
            },
        );

        $catalog->register(
            'string join',
            [[['list', 'delimiter'], 1]],
            static function (array $args): mixed {
                $list = Args::listOf($args[0], 'string join', 'list');
                $delimiter = Args::optionalString($args[1], 'string join', 'delimiter') ?? '';
                $parts = [];

                foreach ($list as $item) {
                    if ($item === null) {
                        continue;
                    }

                    if (!\is_string($item)) {
                        throw Args::typeError('string join', 'list', 'a list of strings', $item);
                    }

                    $parts[] = $item;
                }

                return implode($delimiter, $parts);
            },
        );
    }

    /**
     * @return list<string>
     */
    private static function chars(string $string): array
    {
        $chars = preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY);

        return $chars === false ? [] : $chars;
    }

    /**
     * Unicode block ranges for XSD's `\p{IsXxx}` block escapes (block names
     * per the XML Schema datatypes spec). PCRE knows scripts and categories
     * but not blocks, so these translate to explicit codepoint ranges;
     * blocks outside this table error to null with a diagnostic.
     */
    private const array CHAR_BLOCKS = [
        'BasicLatin' => [0x0000, 0x007F],
        'Latin-1Supplement' => [0x0080, 0x00FF],
        'LatinExtended-A' => [0x0100, 0x017F],
        'LatinExtended-B' => [0x0180, 0x024F],
        'IPAExtensions' => [0x0250, 0x02AF],
        'Greek' => [0x0370, 0x03FF],
        'Cyrillic' => [0x0400, 0x04FF],
        'Hebrew' => [0x0590, 0x05FF],
        'Arabic' => [0x0600, 0x06FF],
        'Thai' => [0x0E00, 0x0E7F],
        'Hiragana' => [0x3040, 0x309F],
        'Katakana' => [0x30A0, 0x30FF],
    ];

    private static function regex(string $pattern, ?string $flags, string $function): string
    {
        $modifiers = 'u';
        $dotAll = false;
        $stripWhitespace = false;

        foreach (self::chars($flags ?? '') as $flag) {
            if (!\in_array($flag, ['s', 'm', 'i', 'x'], true)) {
                throw new FeelError(sprintf('%s(): unsupported regex flag %s', $function, var_export($flag, true)));
            }

            if ($flag === 's') {
                $dotAll = true;
            }

            // XSD's x flag strips whitespace from the pattern itself (except
            // inside character classes) — not PCRE's comment mode.
            if ($flag === 'x') {
                $stripWhitespace = true;
                continue;
            }

            $modifiers .= $flag;
        }

        $translated = self::xsdPattern($pattern, $stripWhitespace, $dotAll, $function);

        return '/' . str_replace('/', '\/', $translated) . '/' . $modifiers;
    }

    /**
     * Translates an XPath/XSD regular expression into its PCRE equivalent:
     * whitespace stripping for the x flag, XSD's dot (which matches neither
     * \n nor \r), character-class subtraction (`[A-Z-[OI]]`), block escapes
     * (`\p{IsBasicLatin}`) and the escapes XSD rejects (`\0` anywhere,
     * digit back-references inside character classes). Everything else
     * passes through unchanged.
     */
    private static function xsdPattern(string $pattern, bool $strip, bool $dotAll, string $function): string
    {
        $chars = self::chars($pattern);
        $index = 0;
        $out = '';
        $count = \count($chars);

        while ($index < $count) {
            $char = $chars[$index];

            if ($strip && \in_array($char, [' ', "\t", "\n", "\r"], true)) {
                $index++;
                continue;
            }

            if ($char === '\\') {
                $index++;
                $out .= self::xsdEscape($chars, $index, $strip, $function, inClass: false);
                continue;
            }

            if ($char === '[') {
                $index++;
                $out .= self::xsdClass($chars, $index, $strip, $function);
                continue;
            }

            if ($char === '.') {
                $out .= $dotAll ? '.' : '[^\r\n]';
                $index++;
                continue;
            }

            $out .= $char;
            $index++;
        }

        return $out;
    }

    /**
     * Translates one character class starting after its `[`, including
     * XSD's subtraction syntax `[base-[subtracted]]`, which becomes a
     * negative lookahead guarding the base class.
     *
     * @param list<string> $chars
     */
    private static function xsdClass(array $chars, int &$index, bool $strip, string $function): string
    {
        $count = \count($chars);
        $body = '';
        $subtraction = null;

        while ($index < $count) {
            $char = $chars[$index];

            if ($char === '\\') {
                $index++;
                $body .= self::xsdEscape($chars, $index, $strip, $function, inClass: true);
                continue;
            }

            if ($char === ']') {
                $index++;
                $class = '[' . $body . ']';

                return $subtraction === null ? $class : '(?:(?!' . $subtraction . ')' . $class . ')';
            }

            if ($char === '-' && ($chars[$index + 1] ?? null) === '[') {
                $index += 2;
                $subtraction = self::xsdClass($chars, $index, $strip, $function);
                continue;
            }

            $body .= $char;
            $index++;
        }

        throw new FeelError(sprintf('%s(): unterminated character class', $function));
    }

    /**
     * Translates one escape sequence starting after its backslash. XSD
     * rejects `\0` everywhere and digit back-references inside character
     * classes; `\p{IsXxx}` block escapes become codepoint ranges.
     *
     * @param list<string> $chars
     */
    private static function xsdEscape(array $chars, int &$index, bool $strip, string $function, bool $inClass): string
    {
        $count = \count($chars);

        // The x flag strips whitespace before the pattern is interpreted,
        // so `\ s` reads as `\s` (outside character classes).
        while (!$inClass && $strip && $index < $count && \in_array($chars[$index], [' ', "\t", "\n", "\r"], true)) {
            $index++;
        }

        if ($index >= $count) {
            throw new FeelError(sprintf('%s(): pattern ends inside an escape', $function));
        }

        $next = $chars[$index];
        $index++;

        if ($next === '0') {
            throw new FeelError(sprintf('%s(): invalid escape \0', $function));
        }

        if ($inClass && $next >= '1' && $next <= '9') {
            throw new FeelError(sprintf(
                '%s(): back-reference \%s is not allowed in a character class',
                $function,
                $next,
            ));
        }

        if (($next === 'p' || $next === 'P') && ($chars[$index] ?? null) === '{') {
            return self::xsdProperty($chars, $index, $strip, $function, $next === 'P', $inClass);
        }

        return '\\' . $next;
    }

    /**
     * Translates a `\p{...}` / `\P{...}` property starting at its `{`.
     * Category names pass through to PCRE; XSD block names (`IsXxx`)
     * become explicit codepoint ranges.
     *
     * @param list<string> $chars
     */
    private static function xsdProperty(
        array $chars,
        int &$index,
        bool $strip,
        string $function,
        bool $negated,
        bool $inClass,
    ): string {
        $count = \count($chars);
        $index++;
        $name = '';

        while ($index < $count && $chars[$index] !== '}') {
            if ($strip && !$inClass && \in_array($chars[$index], [' ', "\t", "\n", "\r"], true)) {
                $index++;
                continue;
            }

            $name .= $chars[$index];
            $index++;
        }

        if ($index >= $count) {
            throw new FeelError(sprintf('%s(): unterminated \p{...} escape', $function));
        }

        $index++;

        if (!str_starts_with($name, 'Is')) {
            return ($negated ? '\P' : '\p') . '{' . $name . '}';
        }

        $block = self::CHAR_BLOCKS[substr($name, 2)] ?? null;

        if ($block === null) {
            throw new FeelError(sprintf('%s(): unsupported character block %s', $function, var_export($name, true)));
        }

        $range = sprintf('\x{%04X}-\x{%04X}', $block[0], $block[1]);

        if ($inClass) {
            if ($negated) {
                throw new FeelError(sprintf(
                    '%s(): \P{%s} is not supported inside a character class',
                    $function,
                    $name,
                ));
            }

            return $range;
        }

        return ($negated ? '[^' : '[') . $range . ']';
    }
}
