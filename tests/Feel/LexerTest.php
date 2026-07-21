<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tests\Feel;

use OpenWerk\DecisionModelAndNotation\Exception\FeelParseException;
use OpenWerk\DecisionModelAndNotation\Feel\Lexer\Lexer;
use OpenWerk\DecisionModelAndNotation\Feel\Lexer\TokenType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LexerTest extends TestCase
{
    /**
     * @return list<TokenType>
     */
    private function types(string $source): array
    {
        return array_map(
            static fn($token): TokenType => $token->type,
            (new Lexer())->tokenize($source),
        );
    }

    public function testTokenizesArithmetic(): void
    {
        self::assertSame(
            [
                TokenType::Number,
                TokenType::Plus,
                TokenType::Number,
                TokenType::Star,
                TokenType::LParen,
                TokenType::Number,
                TokenType::Minus,
                TokenType::Number,
                TokenType::RParen,
                TokenType::Eof,
            ],
            $this->types('1 + 2.5 * (3 - .5)'),
        );
    }

    public function testDistinguishesRangeDotsFromDecimals(): void
    {
        self::assertSame(
            [
                TokenType::LBracket,
                TokenType::Number,
                TokenType::DotDot,
                TokenType::Number,
                TokenType::RBracket,
                TokenType::Eof,
            ],
            $this->types('[4..5.5]'),
        );
    }

    public function testTokenizesKeywordsAndNames(): void
    {
        self::assertSame(
            [
                TokenType::Name,
                TokenType::And,
                TokenType::True,
                TokenType::Or,
                TokenType::Not,
                TokenType::Null,
                TokenType::Between,
                TokenType::In,
                TokenType::Name,
                TokenType::Eof,
            ],
            $this->types('monthly and true or not null between in salary'),
        );
    }

    public function testTokenizesTwoCharacterOperators(): void
    {
        self::assertSame(
            [
                TokenType::Power,
                TokenType::Ne,
                TokenType::Le,
                TokenType::Ge,
                TokenType::Eq,
                TokenType::Eof,
            ],
            $this->types('** != <= >= ='),
        );
    }

    public function testDecodesStringEscapes(): void
    {
        $tokens = (new Lexer())->tokenize('"a\\"b\\\\c\\nd\\u00e9"');

        self::assertSame(TokenType::String, $tokens[0]->type);
        self::assertSame("a\"b\\c\nd\u{e9}", $tokens[0]->text);
    }

    public function testSupportsUnicodeNames(): void
    {
        $tokens = (new Lexer())->tokenize('Gebühr');

        self::assertSame(TokenType::Name, $tokens[0]->type);
        self::assertSame('Gebühr', $tokens[0]->text);
    }

    #[DataProvider('invalidSources')]
    public function testRejectsInvalidInput(string $source): void
    {
        $this->expectException(FeelParseException::class);

        (new Lexer())->tokenize($source);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSources(): iterable
    {
        yield 'unterminated string' => ['"abc'];
        yield 'unknown character' => ['a # b'];
        yield 'bad unicode escape' => ['"\\uZZZZ"'];
    }

    public function testRegexEscapesPassThroughLiterally(): void
    {
        $tokens = (new Lexer())->tokenize('"a\\sb"');

        self::assertSame('a\\sb', $tokens[0]->text);
    }
}
