<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tests\Feel;

use OpenWerk\DecisionModelAndNotation\Exception\FeelParseException;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\AnyTest;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\Between;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\Binary;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\BinaryOp;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\ComparisonTest;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\ExpressionTest;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\FunctionCall;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\IntervalTest;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\Name;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\Negation;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\NumberLiteral;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\PathExpression;
use OpenWerk\DecisionModelAndNotation\Feel\Parser\FeelParser;
use OpenWerk\DecisionModelAndNotation\Feel\Parser\NameRegistry;
use PHPUnit\Framework\TestCase;

final class FeelParserTest extends TestCase
{
    /**
     * @param list<string> $names
     */
    private function parser(array $names = []): FeelParser
    {
        $registry = NameRegistry::withBuiltins();

        foreach ($names as $name) {
            $registry->add($name);
        }

        return new FeelParser($registry);
    }

    public function testParsesPrecedence(): void
    {
        // 1 + 2 * 3 ** 2 parses as 1 + (2 * (3 ** 2))
        $node = $this->parser()->parseExpression('1 + 2 * 3 ** 2');

        self::assertInstanceOf(Binary::class, $node);
        self::assertSame(BinaryOp::Add, $node->op);
        $right = $node->right;
        self::assertInstanceOf(Binary::class, $right);
        self::assertSame(BinaryOp::Multiply, $right->op);
        self::assertInstanceOf(Binary::class, $right->right);
        self::assertSame(BinaryOp::Power, $right->right->op);
    }

    public function testUnaryMinusBindsTighterThanPower(): void
    {
        // -2 ** 2 parses as (-2) ** 2 (TCK 0075/0100)
        $node = $this->parser()->parseExpression('-2 ** 2');

        self::assertInstanceOf(Binary::class, $node);
        self::assertSame(BinaryOp::Power, $node->op);
        self::assertInstanceOf(Negation::class, $node->left);
    }

    public function testResolvesNamesWithSpacesFromScope(): void
    {
        $node = $this->parser(['monthly salary'])->parseExpression('monthly salary * 12');

        self::assertInstanceOf(Binary::class, $node);
        self::assertInstanceOf(Name::class, $node->left);
        self::assertSame('monthly salary', $node->left->name);
    }

    public function testResolvesKeywordAdjacentNames(): void
    {
        $node = $this->parser(['is active', 'risk and reward'])
            ->parseExpression('is active and risk and reward');

        self::assertInstanceOf(Binary::class, $node);
        self::assertSame(BinaryOp::And, $node->op);
        self::assertInstanceOf(Name::class, $node->left);
        self::assertSame('is active', $node->left->name);
        self::assertInstanceOf(Name::class, $node->right);
        self::assertSame('risk and reward', $node->right->name);
    }

    public function testLongestKnownNameWins(): void
    {
        $node = $this->parser(['a', 'a b', 'a b c'])->parseExpression('a b c');

        self::assertInstanceOf(Name::class, $node);
        self::assertSame('a b c', $node->name);
    }

    public function testParsesQualifiedPath(): void
    {
        $node = $this->parser(['applicant'])->parseExpression('applicant.address.city');

        self::assertInstanceOf(PathExpression::class, $node);
        self::assertSame('city', $node->property);
        self::assertInstanceOf(PathExpression::class, $node->base);
        self::assertSame('address', $node->base->property);
    }

    public function testParsesDateAndTimeConstructor(): void
    {
        $node = $this->parser()->parseExpression('date and time("2027-01-01T10:00:00")');

        self::assertInstanceOf(FunctionCall::class, $node);
        self::assertSame('date and time', $node->calleeName());
        self::assertCount(1, $node->arguments);
    }

    public function testParsesBetween(): void
    {
        $node = $this->parser(['x'])->parseExpression('x between 1 and 10');

        self::assertInstanceOf(Between::class, $node);
    }

    public function testParsesDashAsAnyTest(): void
    {
        $tests = $this->parser()->parseUnaryTests('-');

        self::assertFalse($tests->negated);
        self::assertCount(1, $tests->tests);
        self::assertInstanceOf(AnyTest::class, $tests->tests[0]);
    }

    public function testParsesComparisonAndIntervalTests(): void
    {
        $tests = $this->parser()->parseUnaryTests('< 25, [25..60], ]0..1[, (5..7]');

        self::assertCount(4, $tests->tests);
        self::assertInstanceOf(ComparisonTest::class, $tests->tests[0]);

        $closed = $tests->tests[1];
        self::assertInstanceOf(IntervalTest::class, $closed);
        self::assertTrue($closed->startClosed);
        self::assertTrue($closed->endClosed);

        $open = $tests->tests[2];
        self::assertInstanceOf(IntervalTest::class, $open);
        self::assertFalse($open->startClosed);
        self::assertFalse($open->endClosed);

        $halfOpen = $tests->tests[3];
        self::assertInstanceOf(IntervalTest::class, $halfOpen);
        self::assertFalse($halfOpen->startClosed);
        self::assertTrue($halfOpen->endClosed);
    }

    public function testParsesNegatedTests(): void
    {
        $tests = $this->parser()->parseUnaryTests('not("red", "green")');

        self::assertTrue($tests->negated);
        self::assertCount(2, $tests->tests);
        self::assertInstanceOf(ExpressionTest::class, $tests->tests[0]);
    }

    public function testParenthesizedExpressionTestIsNotAnInterval(): void
    {
        $tests = $this->parser()->parseUnaryTests('(1 + 2)');

        self::assertCount(1, $tests->tests);
        $test = $tests->tests[0];
        self::assertInstanceOf(ExpressionTest::class, $test);
        self::assertInstanceOf(Binary::class, $test->expression);
    }

    public function testParsesLeadingDotNumber(): void
    {
        $node = $this->parser()->parseExpression('.5');

        self::assertInstanceOf(NumberLiteral::class, $node);
        self::assertSame('0.5', (string) $node->value);
    }

    public function testRejectsTrailingGarbage(): void
    {
        $this->expectException(FeelParseException::class);

        $this->parser()->parseExpression('1 + 2 )');
    }

    public function testRejectsChainedComparison(): void
    {
        $this->expectException(FeelParseException::class);

        $this->parser()->parseExpression('1 < 2 < 3');
    }
}
