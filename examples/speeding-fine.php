<?php

/**
 * "Flashed by a speed camera — what is this going to cost?"
 *
 * speeding-fine.dmn is a (heavily simplified) fine schedule. Two things
 * to see here:
 *
 *  - a table computes its own first column: 'measured speed - 3 - speed
 *    limit' applies the 3 km/h measurement tolerance before the ranges
 *    (0..10], (10..25], > 25 are checked
 *  - one rule row answers three questions at once — fine, points and
 *    driving ban are separate output columns, and the result arrives as
 *    one array keyed by output name
 *
 * Run with: php examples/speeding-fine.php
 */

declare(strict_types=1);

use Brick\Math\BigDecimal;
use OpenWerk\DecisionModelAndNotation\Dmn;

require dirname(__DIR__) . '/vendor/autoload.php';

$model = Dmn::fromFile(__DIR__ . '/speeding-fine.dmn');

$photos = [
    ['measured speed' => 58, 'speed limit' => 50, 'location' => 'urban'],
    ['measured speed' => 89, 'speed limit' => 70, 'location' => 'rural'],
    ['measured speed' => 82, 'speed limit' => 50, 'location' => 'urban'],
    ['measured speed' => 52, 'speed limit' => 50, 'location' => 'urban'],
];

$penalties = [];

foreach ($photos as $photo) {
    $penalty = $model->evaluateDecision('Penalty', $photo)->value();

    if (!is_array($penalty)) {
        $penalties[] = null;
        continue;
    }

    $fine = $penalty['fine'] ?? null;
    $points = $penalty['points'] ?? null;
    $ban = $penalty['ban'] ?? null;

    $penalties[] = [
        $fine instanceof BigDecimal ? (string) $fine : null,
        $points instanceof BigDecimal ? (string) $points : null,
        is_string($ban) ? $ban : null,
    ];

    printf(
        "%d km/h where %d is allowed (%s) → ",
        $photo['measured speed'],
        $photo['speed limit'],
        $photo['location'],
    );

    if ($fine instanceof BigDecimal && $fine->isZero()) {
        echo 'no fine — the tolerance saved you', PHP_EOL;
        continue;
    }

    printf(
        "fine %s €, %s point(s), ban: %s\n",
        $fine instanceof BigDecimal ? (string) $fine : '?',
        $points instanceof BigDecimal ? (string) $points : '?',
        is_string($ban) ? $ban : '?',
    );
}

// The example doubles as a CI smoke test.
if (
    $model->hasValidationErrors()
    || $penalties !== [
        ['30.00', '0', 'none'],
        ['100.00', '1', 'none'],
        ['260.00', '2', '1 month'],
        ['0.00', '0', 'none'],
    ]
) {
    fwrite(STDERR, 'unexpected evaluation result' . PHP_EOL);
    exit(1);
}

echo PHP_EOL, 'OK', PHP_EOL;
