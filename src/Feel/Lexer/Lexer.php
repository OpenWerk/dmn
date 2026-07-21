<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Lexer;

use OpenWerk\DecisionModelAndNotation\Exception\FeelParseException;

/**
 * Tokenizer for the supported FEEL subset. Multi-word names are emitted as
 * individual name tokens; the parser recombines them against the names known
 * in scope (FEEL names may contain spaces).
 *
 * @internal
 */
final class Lexer
{
    private const array KEYWORDS = [
        'true' => TokenType::True,
        'false' => TokenType::False,
        'null' => TokenType::Null,
        'and' => TokenType::And,
        'or' => TokenType::Or,
        'not' => TokenType::Not,
        'between' => TokenType::Between,
        'in' => TokenType::In,
        'if' => TokenType::If,
        'then' => TokenType::Then,
        'else' => TokenType::Else,
        'for' => TokenType::For,
        'return' => TokenType::Return,
        'some' => TokenType::Some,
        'every' => TokenType::Every,
        'satisfies' => TokenType::Satisfies,
        'function' => TokenType::Function,
        'external' => TokenType::External,
        'instance' => TokenType::Instance,
        'of' => TokenType::Of,
    ];

    // Name start/part chars per FEEL grammar rules 28-30; the supplementary
    // planes (U+10000-U+EFFFF) admit emoji and rare scripts.
    private const string NAME_PATTERN = "/[\\p{L}_?\\x{10000}-\\x{EFFFF}][\\p{L}\\p{N}_'?\\x{10000}-\\x{EFFFF}]*/Au";

    private const string NUMBER_PATTERN = '/(?:\d+(?:\.\d+)?|\.\d+)(?:[eE][+-]?\d+)?/A';

    private const string WHITESPACE_PATTERN = '/\s+/Au';

    /**
     * @return list<Token>
     */
    public function tokenize(string $source): array
    {
        $tokens = [];
        $length = \strlen($source);
        $position = 0;

        while ($position < $length) {
            if (preg_match(self::WHITESPACE_PATTERN, $source, $match, 0, $position) === 1) {
                $position += \strlen($match[0]);
                continue;
            }

            // FEEL comments: // to end of line, /* ... */ (non-nesting).
            if (substr($source, $position, 2) === '//') {
                $newline = strpos($source, "\n", $position);
                $position = $newline === false ? $length : $newline + 1;
                continue;
            }

            if (substr($source, $position, 2) === '/*') {
                $end = strpos($source, '*/', $position + 2);

                if ($end === false) {
                    throw new FeelParseException('unterminated comment', $source, $position);
                }

                $position = $end + 2;
                continue;
            }

            $char = $source[$position];

            if ($char === '"') {
                $tokens[] = $this->stringToken($source, $position);
                continue;
            }

            if (preg_match(self::NUMBER_PATTERN, $source, $match, 0, $position) === 1) {
                $tokens[] = new Token(TokenType::Number, $match[0], $position);
                $position += \strlen($match[0]);
                continue;
            }

            if (preg_match(self::NAME_PATTERN, $source, $match, 0, $position) === 1) {
                $type = self::KEYWORDS[$match[0]] ?? TokenType::Name;
                $tokens[] = new Token($type, $match[0], $position);
                $position += \strlen($match[0]);
                continue;
            }

            $operator = $this->operatorToken($source, $position);

            if ($operator === null) {
                throw new FeelParseException(
                    sprintf('unexpected character %s', var_export($char, true)),
                    $source,
                    $position,
                );
            }

            $tokens[] = $operator;
            $position += \strlen($operator->text);
        }

        $tokens[] = new Token(TokenType::Eof, '', $length);

        return $tokens;
    }

    private function operatorToken(string $source, int $position): ?Token
    {
        $two = substr($source, $position, 2);

        $type = match ($two) {
            '**' => TokenType::Power,
            '..' => TokenType::DotDot,
            '!=' => TokenType::Ne,
            '<=' => TokenType::Le,
            '>=' => TokenType::Ge,
            default => null,
        };

        if ($type !== null) {
            return new Token($type, $two, $position);
        }

        $one = $source[$position];

        $type = match ($one) {
            '+' => TokenType::Plus,
            '-' => TokenType::Minus,
            '*' => TokenType::Star,
            '/' => TokenType::Slash,
            '=' => TokenType::Eq,
            '<' => TokenType::Lt,
            '>' => TokenType::Gt,
            '(' => TokenType::LParen,
            ')' => TokenType::RParen,
            '[' => TokenType::LBracket,
            ']' => TokenType::RBracket,
            '{' => TokenType::LBrace,
            '}' => TokenType::RBrace,
            ':' => TokenType::Colon,
            ',' => TokenType::Comma,
            '.' => TokenType::Dot,
            '@' => TokenType::At,
            default => null,
        };

        return $type === null ? null : new Token($type, $one, $position);
    }

    private function stringToken(string $source, int &$position): Token
    {
        $start = $position;
        $length = \strlen($source);
        $value = '';
        $position++;

        while ($position < $length) {
            $char = $source[$position];

            if ($char === '"') {
                $position++;

                return new Token(TokenType::String, $value, $start);
            }

            if ($char !== '\\') {
                $value .= $char;
                $position++;
                continue;
            }

            if ($position + 1 >= $length) {
                break;
            }

            $escape = $source[$position + 1];
            $position += 2;

            switch ($escape) {
                case '"':
                    $value .= '"';
                    break;
                case '\\':
                    $value .= '\\';
                    break;
                case '/':
                    $value .= '/';
                    break;
                case 'n':
                    $value .= "\n";
                    break;
                case 'r':
                    $value .= "\r";
                    break;
                case 't':
                    $value .= "\t";
                    break;
                case 'u':
                    $hex = substr($source, $position, 4);

                    if (preg_match('/^[0-9A-Fa-f]{4}$/', $hex) !== 1) {
                        throw new FeelParseException('invalid \u escape sequence', $source, $position - 2);
                    }

                    $codepoint = (int) hexdec($hex);
                    $position += 4;

                    // Combine UTF-16 surrogate pairs into one codepoint.
                    if (
                        $codepoint >= 0xD800 && $codepoint <= 0xDBFF
                        && substr($source, $position, 2) === '\\u'
                        && preg_match('/^[0-9A-Fa-f]{4}$/', substr($source, $position + 2, 4)) === 1
                    ) {
                        $low = (int) hexdec(substr($source, $position + 2, 4));

                        if ($low >= 0xDC00 && $low <= 0xDFFF) {
                            $codepoint = 0x10000 + (($codepoint - 0xD800) << 10) + ($low - 0xDC00);
                            $position += 6;
                        }
                    }

                    $value .= self::codepointToUtf8($codepoint);
                    break;
                case 'U':
                    $hex = substr($source, $position, 6);

                    if (preg_match('/^[0-9A-Fa-f]{6}$/', $hex) !== 1) {
                        throw new FeelParseException('invalid \U escape sequence', $source, $position - 2);
                    }

                    $value .= self::codepointToUtf8((int) hexdec($hex));
                    $position += 6;
                    break;
                default:
                    // Regex escapes (\s, \d, ...) pass through literally so
                    // XPath patterns survive inside FEEL strings.
                    $value .= '\\' . $escape;
                    break;
            }
        }

        throw new FeelParseException('unterminated string literal', $source, $start);
    }

    private static function codepointToUtf8(int $codepoint): string
    {
        // The masks keep the codepoint within Unicode's range and every
        // computed byte in the 0-255 range chr() requires.
        $codepoint &= 0x1FFFFF;

        if ($codepoint > 0x10FFFF) {
            $codepoint = 0xFFFD; // replacement character
        }

        if ($codepoint < 0x80) {
            return \chr($codepoint & 0x7F);
        }

        if ($codepoint < 0x800) {
            return \chr((0xC0 | ($codepoint >> 6)) & 0xFF) . \chr(0x80 | ($codepoint & 0x3F));
        }

        if ($codepoint < 0x10000) {
            return \chr((0xE0 | ($codepoint >> 12)) & 0xFF)
                . \chr(0x80 | (($codepoint >> 6) & 0x3F))
                . \chr(0x80 | ($codepoint & 0x3F));
        }

        return \chr((0xF0 | ($codepoint >> 18)) & 0xFF)
            . \chr(0x80 | (($codepoint >> 12) & 0x3F))
            . \chr(0x80 | (($codepoint >> 6) & 0x3F))
            . \chr(0x80 | ($codepoint & 0x3F));
    }
}
