<?php

/**
 * "How much do I owe at the pay machine?" — the parking garage tariff.
 *
 * PHP date handling meets FEEL: pass DateTimeImmutable objects straight in
 * as inputs. The model subtracts them ('exit - entry' — a real duration,
 * no strtotime(), no /60 anywhere) and reads the tariff sign top to bottom
 * with the FIRST hit policy: first 30 minutes free, day cap at 15 €,
 * long-stay flat after 24 hours.
 *
 * Run with: php examples/parking-garage.php
 */

declare(strict_types=1);

use Brick\Math\BigDecimal;
use OpenWerk\DecisionModelAndNotation\Dmn;
use OpenWerk\DecisionModelAndNotation\Feel\Value\DaysTimeDuration;

require dirname(__DIR__) . '/vendor/autoload.php';

$model = Dmn::fromFile(__DIR__ . '/parking-garage.dmn');

$stays = [
    ['what' => 'quick pharmacy run', 'entry' => '2026-03-06 09:04', 'exit' => '2026-03-06 09:26'],
    ['what' => 'cinema', 'entry' => '2026-03-06 19:35', 'exit' => '2026-03-06 21:20'],
    ['what' => 'shopping and lunch', 'entry' => '2026-03-06 10:12', 'exit' => '2026-03-06 13:58'],
    ['what' => 'a day at the office', 'entry' => '2026-03-06 08:31', 'exit' => '2026-03-06 17:20'],
    ['what' => 'weekend city trip', 'entry' => '2026-03-06 15:00', 'exit' => '2026-03-08 18:30'],
];

$durations = [];
$fees = [];

foreach ($stays as $stay) {
    $inputs = [
        'entry' => new DateTimeImmutable($stay['entry']),
        'exit' => new DateTimeImmutable($stay['exit']),
    ];

    // Any decision in the graph can be asked directly:
    $duration = $model->evaluateDecision('Parking Duration', $inputs)->value();
    $durations[] = $duration instanceof DaysTimeDuration ? (string) $duration : null;

    $fee = $model->evaluateDecision('Parking Fee', $inputs)->value();
    $fees[] = $fee instanceof BigDecimal ? (string) $fee : null;

    printf(
        "%-20s %s → %s  parked %-10s → %s\n",
        $stay['what'],
        $stay['entry'],
        $stay['exit'],
        $duration instanceof DaysTimeDuration ? (string) $duration : '?',
        $fee instanceof BigDecimal ? ($fee->isZero() ? 'free' : $fee . ' €') : '?',
    );
}

// The example doubles as a CI smoke test.
if (
    $model->hasValidationErrors()
    || $durations !== ['PT22M', 'PT1H45M', 'PT3H46M', 'PT8H49M', 'P2DT3H30M']
    || $fees !== ['0.00', '3.00', '8.00', '15.00', '30.00']
) {
    fwrite(STDERR, 'unexpected evaluation result' . PHP_EOL);
    exit(1);
}

echo PHP_EOL, 'OK', PHP_EOL;
