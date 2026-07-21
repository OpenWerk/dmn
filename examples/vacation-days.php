<?php

/**
 * "How many vacation days do I get?" — the question HR answers all year.
 *
 * vacation-days.dmn uses the COLLECT hit policy with SUM aggregation:
 * every rule that matches contributes days, and the engine adds them up —
 * 24 base days, +5 for minors and 60+, +2 after ten years of service,
 * +3 more after thirty. No if/else pyramid, just rows in a table.
 *
 * matchedRules() shows exactly which bonuses made up the total, so the
 * answer is not only a number but also its own explanation.
 *
 * Run with: php examples/vacation-days.php
 */

declare(strict_types=1);

use Brick\Math\BigDecimal;
use OpenWerk\DecisionModelAndNotation\Dmn;

require dirname(__DIR__) . '/vendor/autoload.php';

$model = Dmn::fromFile(__DIR__ . '/vacation-days.dmn');

// Rule index → what the row means, for the friendly printout below.
$meaning = [
    1 => '24 base',
    2 => '+5 under 18 / 60 and over',
    3 => '+2 for 10+ years',
    4 => '+3 for 30+ years',
];

$employees = [
    ['who' => 'apprentice, 17, first year', 'age' => 17, 'years of service' => 0],
    ['who' => 'new hire, 28', 'age' => 28, 'years of service' => 3],
    ['who' => 'team lead, 45, 12 years in', 'age' => 45, 'years of service' => 12],
    ['who' => 'veteran, 61, 33 years in', 'age' => 61, 'years of service' => 33],
];

$days = [];

foreach ($employees as $employee) {
    $result = $model->evaluateDecision('Vacation Days', [
        'age' => $employee['age'],
        'years of service' => $employee['years of service'],
    ]);

    $total = $result->value();
    $days[] = $total instanceof BigDecimal ? (string) $total : null;

    $because = [];

    foreach ($result->matchedRules() as $rule) {
        $because[] = $meaning[$rule->ruleIndex] ?? sprintf('rule %d', $rule->ruleIndex);
    }

    printf(
        "%-28s → %s days  (%s)\n",
        $employee['who'],
        $total instanceof BigDecimal ? (string) $total : '?',
        implode(', ', $because),
    );
}

// The example doubles as a CI smoke test.
if ($model->hasValidationErrors() || $days !== ['29', '24', '26', '34']) {
    fwrite(STDERR, 'unexpected evaluation result' . PHP_EOL);
    exit(1);
}

echo PHP_EOL, 'OK', PHP_EOL;
