<?php

/**
 * "What does shipping cost?" — the question every webshop checkout answers.
 *
 * The tariff (free shipping from 50 €, EU orders from 100 €, flat rate for
 * the rest of the world) lives in shipping-fee.dmn as a decision table the
 * shop owner can read — and change — without touching any PHP code.
 *
 * Also shown here:
 *  - parse once, cache forever: a model survives serialize()/unserialize(),
 *    so a real shop would keep the parsed model in Redis/APCu
 *  - allowed input values as a guard rail: a typo like "moon" does not
 *    throw — it evaluates to null and the diagnostics say why
 *
 * Run with: php examples/shipping-fee.php
 */

declare(strict_types=1);

use Brick\Math\BigDecimal;
use OpenWerk\DecisionModelAndNotation\Dmn;
use OpenWerk\DecisionModelAndNotation\DmnModel;

require dirname(__DIR__) . '/vendor/autoload.php';

$model = Dmn::fromFile(__DIR__ . '/shipping-fee.dmn');

// Parse once, evaluate often: models are immutable value objects, so cache
// the parsed model instead of re-parsing the XML on every request.
$cached = unserialize(serialize($model));

if (!$cached instanceof DmnModel) {
    fwrite(STDERR, 'model did not survive the cache round-trip' . PHP_EOL);
    exit(1);
}

$model = $cached;

$carts = [
    ['destination' => 'domestic', 'cart total' => 49.90],
    ['destination' => 'domestic', 'cart total' => 52.00],
    ['destination' => 'europe', 'cart total' => 75.00],
    ['destination' => 'world', 'cart total' => 130.00],
];

$fees = [];

foreach ($carts as $cart) {
    $result = $model->evaluateDecision('Shipping Fee', $cart);
    $fee = $result->value();
    $fees[] = $fee instanceof BigDecimal ? (string) $fee : null;

    printf(
        "%6.2f € cart to %-8s → shipping: %s\n",
        $cart['cart total'],
        $cart['destination'],
        $fee instanceof BigDecimal ? ($fee->isZero() ? 'FREE' : $fee . ' €') : '(none)',
    );
}

// A typo in the destination does not throw and does not silently match
// nothing — the decision evaluates to null and the diagnostics explain why:
$oops = $model->evaluateDecision('Shipping Fee', ['destination' => 'moon', 'cart total' => 20.00]);

echo PHP_EOL, 'And a cart headed to "moon"?', PHP_EOL;
echo '  value: ', var_export($oops->value(), true), PHP_EOL;

foreach ($oops->diagnostics() as $diagnostic) {
    echo '  ', $diagnostic, PHP_EOL;
}

// The example doubles as a CI smoke test.
if (
    $model->hasValidationErrors()
    || $fees !== ['4.90', '0.00', '12.90', '24.90']
    || $oops->value() !== null
    || !$oops->hasErrors()
) {
    fwrite(STDERR, 'unexpected evaluation result' . PHP_EOL);
    exit(1);
}

echo PHP_EOL, 'OK', PHP_EOL;
