<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tests\Engine;

use OpenWerk\DecisionModelAndNotation\Dmn;
use OpenWerk\DecisionModelAndNotation\DmnModel;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelDateTime;
use PHPUnit\Framework\TestCase;

/**
 * now() and today() pin to one instant per evaluation run. The instant is
 * injectable through the `now` argument of evaluateDecision() and
 * evaluateService(), making time-dependent decisions reproducible.
 */
final class EvaluationClockTest extends TestCase
{
    public function testInjectedClockPinsNowAndToday(): void
    {
        $result = $this->clockModel()->evaluateDecision(
            'Stamp',
            now: new \DateTimeImmutable('2026-07-17T10:34:56', new \DateTimeZone('UTC')),
        );

        self::assertFalse($result->hasErrors());
        self::assertSame(['2026-07-17T10:34:56Z', '2026-07-17'], $result->value());
    }

    public function testInjectedClockKeepsItsZone(): void
    {
        $berlin = $this->clockModel()->evaluateDecision(
            'Stamp',
            now: new \DateTimeImmutable('2026-07-17T12:34:56', new \DateTimeZone('Europe/Berlin')),
        );

        self::assertSame(['2026-07-17T12:34:56@Europe/Berlin', '2026-07-17'], $berlin->value());

        $offset = $this->clockModel()->evaluateDecision(
            'Stamp',
            now: new \DateTimeImmutable('2026-07-17T23:30:00+02:00'),
        );

        // today() reports the calendar date in the clock's own zone.
        self::assertSame(['2026-07-17T23:30:00+02:00', '2026-07-17'], $offset->value());
    }

    public function testInjectedEvaluationsAreReproducible(): void
    {
        $model = $this->clockModel();
        $now = new \DateTimeImmutable('2027-01-01T00:00:00', new \DateTimeZone('UTC'));

        self::assertSame(
            $model->evaluateDecision('Stamp', now: $now)->value(),
            $model->evaluateDecision('Stamp', now: $now)->value(),
        );
    }

    public function testServiceRunsShareTheInjectedClock(): void
    {
        $result = $this->clockModel()->evaluateService(
            'Stamp Service',
            now: new \DateTimeImmutable('2026-07-17T10:34:56', new \DateTimeZone('UTC')),
        );

        self::assertFalse($result->hasErrors());
        self::assertSame(['2026-07-17T10:34:56Z', '2026-07-17'], $result->value());
    }

    public function testDefaultClockStillTicks(): void
    {
        $result = Dmn::fromString($this->xml())->evaluateDecision('Now Value');

        self::assertFalse($result->hasErrors());
        self::assertInstanceOf(FeelDateTime::class, $result->value());
    }

    private function clockModel(): DmnModel
    {
        return Dmn::fromString($this->xml());
    }

    private function xml(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <definitions xmlns="https://www.omg.org/spec/DMN/20230324/MODEL/" id="t" name="T" namespace="urn:t">
              <decision id="stamp" name="Stamp">
                <variable name="Stamp"/>
                <literalExpression><text>[string(now()), string(today())]</text></literalExpression>
              </decision>
              <decision id="nowValue" name="Now Value">
                <variable name="Now Value"/>
                <literalExpression><text>now()</text></literalExpression>
              </decision>
              <decisionService id="stampService" name="Stamp Service">
                <outputDecision href="#stamp"/>
              </decisionService>
            </definitions>
            XML;
    }
}
