<?php

/**
 * "What will the gym cost me per month?" — the January question.
 *
 * gym-membership.dmn chains two decisions, exactly like the sign at the
 * counter: first "Member Category" sorts you in (FIRST hit policy — the
 * first matching row wins, so a 20-year-old student pays the student rate,
 * not the adult one), then "Monthly Fee" looks up your price by category
 * and contract length.
 *
 * evaluateDecision('Monthly Fee', ...) resolves the whole chain on its own;
 * you can just as well ask for the intermediate 'Member Category' alone —
 * handy for testing one step in isolation.
 *
 * Run with: php examples/gym-membership.php
 */

declare(strict_types=1);

use Brick\Math\BigDecimal;
use OpenWerk\DecisionModelAndNotation\Dmn;

require dirname(__DIR__) . '/vendor/autoload.php';

$model = Dmn::fromFile(__DIR__ . '/gym-membership.dmn');

echo 'Decisions in this model: ', implode(', ', $model->decisionNames()), PHP_EOL, PHP_EOL;

$people = [
    ['who' => '20, studying, 12-month deal', 'age' => 20, 'student' => true, 'contract months' => 12],
    ['who' => '41, office job, 24 months', 'age' => 41, 'student' => false, 'contract months' => 24],
    ['who' => '70, retired, month-to-month', 'age' => 70, 'student' => false, 'contract months' => 1],
    ['who' => '14, plays handball, 12 months', 'age' => 14, 'student' => false, 'contract months' => 12],
];

$categories = [];
$fees = [];

foreach ($people as $person) {
    $inputs = [
        'age' => $person['age'],
        'student' => $person['student'],
        'contract months' => $person['contract months'],
    ];

    // The intermediate decision, evaluated on its own:
    $category = $model->evaluateDecision('Member Category', $inputs)->value();
    $categories[] = is_string($category) ? $category : null;

    // The end of the chain — 'Member Category' is resolved automatically:
    $fee = $model->evaluateDecision('Monthly Fee', $inputs)->value();
    $fees[] = $fee instanceof BigDecimal ? (string) $fee : null;

    printf(
        "%-30s → %-7s rate, %s €/month\n",
        $person['who'],
        is_string($category) ? $category : '?',
        $fee instanceof BigDecimal ? (string) $fee : '?',
    );
}

// The example doubles as a CI smoke test.
if (
    $model->hasValidationErrors()
    || $categories !== ['student', 'adult', 'senior', 'junior']
    || $fees !== ['24.90', '29.90', '24.90', '19.90']
) {
    fwrite(STDERR, 'unexpected evaluation result' . PHP_EOL);
    exit(1);
}

echo PHP_EOL, 'OK', PHP_EOL;
