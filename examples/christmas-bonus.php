<?php

/**
 * "How big is my Christmas bonus?" — one model importing another.
 *
 * Payroll's christmas-bonus.dmn does not copy HR's seniority rules, it
 * IMPORTS seniority.dmn and reads the imported decision through its
 * qualified name 'hr.Seniority Level'. Dmn::fromFile() resolves imports
 * against the sibling .dmn files of the same directory, matched by model
 * namespace.
 *
 * Inputs that belong to the imported model are passed through the third
 * evaluateDecision() argument, keyed by that model's namespace.
 *
 * Run with: php examples/christmas-bonus.php
 */

declare(strict_types=1);

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use OpenWerk\DecisionModelAndNotation\Dmn;

require dirname(__DIR__) . '/vendor/autoload.php';

$model = Dmn::fromFile(__DIR__ . '/christmas-bonus.dmn');

$employees = [
    ['who' => 'new hire, 2 years in', 'monthly salary' => 3100.00, 'years of service' => 2],
    ['who' => 'team lead, 9 years in', 'monthly salary' => 4600.00, 'years of service' => 9],
    ['who' => 'veteran, 17 years in', 'monthly salary' => 3800.00, 'years of service' => 17],
];

$bonuses = [];

foreach ($employees as $employee) {
    $result = $model->evaluateDecision(
        'Christmas Bonus',
        ['monthly salary' => $employee['monthly salary']],
        // Inputs of the imported seniority model, keyed by its namespace:
        ['https://openwerk.example/dmn/seniority' => ['years of service' => $employee['years of service']]],
    );

    $bonus = $result->value();
    $bonuses[] = $bonus instanceof BigDecimal ? (string) $bonus->toScale(2, RoundingMode::HALF_EVEN) : null;

    printf(
        "%-22s salary %7.2f € → bonus %8s €\n",
        $employee['who'],
        $employee['monthly salary'],
        $bonus instanceof BigDecimal ? (string) $bonus->toScale(2, RoundingMode::HALF_EVEN) : '?',
    );
}

// The audit trail crosses the import boundary: the imported decision's hit
// appears qualified by its import name. $result still holds the veteran.
$why = array_map(
    static fn($rule): string => sprintf('%s rule %d', $rule->decision, $rule->ruleIndex),
    $result->matchedRules(),
);

echo PHP_EOL, 'Why the veteran gets 80 %: ', implode(', ', $why), PHP_EOL;

// The example doubles as a CI smoke test.
if (
    $model->hasValidationErrors()
    || $bonuses !== ['620.00', '2300.00', '3040.00']
    || $why !== ['Bonus Percent rule 3', 'hr.Seniority Level rule 3']
) {
    fwrite(STDERR, 'unexpected evaluation result' . PHP_EOL);
    exit(1);
}

echo PHP_EOL, 'OK', PHP_EOL;
