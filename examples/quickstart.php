<?php

/**
 * Quickstart: parse a DMN model and evaluate a decision requirement chain.
 *
 * Run with: php examples/quickstart.php
 */

declare(strict_types=1);

use OpenWerk\DecisionModelAndNotation\Dmn;

require dirname(__DIR__) . '/vendor/autoload.php';

$model = Dmn::fromFile(dirname(__DIR__) . '/tests/fixtures/fees.dmn');

echo 'Model: ', $model->name() ?? '(unnamed)', PHP_EOL;
echo 'Decisions: ', implode(', ', $model->decisionNames()), PHP_EOL;

foreach ($model->validationMessages() as $message) {
    echo 'validation: ', $message, PHP_EOL;
}

$result = $model->evaluateDecision('Discounted Fee', [
    'applicant' => ['age' => 34, 'income' => 51000],
]);

$fee = $result->feelValue();
echo 'Discounted Fee: ', $fee instanceof Stringable ? (string) $fee : var_export($fee, true), PHP_EOL;

foreach ($result->matchedRules() as $rule) {
    echo sprintf('  matched: %s, rule %d (%s)%s', $rule->decision, $rule->ruleIndex, $rule->ruleId ?? '-', PHP_EOL);
}

foreach ($result->diagnostics() as $diagnostic) {
    echo '  diagnostic: ', $diagnostic, PHP_EOL;
}

// The example doubles as a CI smoke test.
if (!$fee instanceof Stringable || (string) $fee !== '90' || $result->hasErrors()) {
    fwrite(STDERR, 'unexpected evaluation result' . PHP_EOL);
    exit(1);
}

echo 'OK', PHP_EOL;
