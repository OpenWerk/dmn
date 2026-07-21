# Examples

Everyday situations, each solved by one small DMN model (`.dmn`) plus a
runnable PHP script (`.php`) that feeds it inputs and prints what comes
back, including *why* (matched rules, diagnostics). None of them require
anything beyond `composer install`:

```bash
composer install
php examples/shipping-fee.php
```

Suggested reading order:

| Example | Everyday question | What it shows |
|---|---|---|
| [`quickstart.php`](quickstart.php) | Start here | The API tour: parse, validate, evaluate, inspect. |
| [`shipping-fee.php`](shipping-fee.php) | What does shipping cost for this cart? | Your first decision table: rules a shop owner can read and edit without touching PHP. Allowed input values catch typos with a diagnostic instead of an exception, and the parsed model survives `serialize()`/`unserialize()`: parse once, cache, evaluate hot. |
| [`vacation-days.php`](vacation-days.php) | How many days off do I get? | COLLECT hit policy with SUM aggregation: every matching rule stacks its bonus on the base entitlement, and `matchedRules()` explains the total. Input names with spaces (`years of service`) are plain FEEL. |
| [`gym-membership.php`](gym-membership.php) | What will the gym cost per month? | A decision requirement graph: "Member Category" feeds "Monthly Fee", resolved automatically in one call. FIRST hit policy makes the student rate beat the adult catch-all, and intermediate decisions can be evaluated in isolation. |
| [`parking-garage.php`](parking-garage.php) | How much do I owe at the pay machine? | FEEL temporal types: pass `DateTimeImmutable` objects in, subtract them in the model (`exit - entry`), and compare the stay against `duration("PT30M")` literals. The tariff table reads top to bottom like the sign at the exit. |
| [`speeding-fine.php`](speeding-fine.php) | Flashed by a camera, what now? | Compound results: one rule row answers fine, points and driving ban at once, returned as an array keyed by output name. The table computes its own input (`measured speed - 3 - speed limit`). |
| [`christmas-bonus.php`](christmas-bonus.php) | How big is my Christmas bonus? | Model imports: payroll's model imports HR's shared [`seniority.dmn`](seniority.dmn) (matched by namespace among sibling files), reads its decision as `hr.Seniority Level`, and receives the imported model's inputs through the `importedInputs` argument. |

Every script doubles as a smoke test: it exits non-zero if the model stops
producing the expected results, and CI runs them all.
