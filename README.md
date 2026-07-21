# openwerk/dmn

[![CI](https://github.com/OpenWerk/dmn/actions/workflows/ci.yml/badge.svg)](https://github.com/OpenWerk/dmn/actions/workflows/ci.yml)

A DMN decision engine for PHP: decision tables, (S-)FEEL and decision
requirement graphs, implemented as a pure library with no framework
dependencies.

## What is DMN, and why would I use it?

DMN (Decision Model and Notation) is an OMG standard for describing
business decisions: decision tables, the expression language FEEL and the
graph that wires decisions, inputs and reusable logic together. Models are
plain XML files that non-programmers can read and edit in a modeler, and
their execution semantics are standardized, so the same file produces the
same results in any conformant engine.

You want this when rules change more often than code: shipping fees,
eligibility checks, discounts, risk scoring, routing. Instead of burying
those rules in nested if/else blocks, they live in a table the business
side can review, the engine can explain (every result reports which rules
fired), and you can test like data. See [examples/](examples/) for a set
of small, runnable everyday cases.

## Requirements and installation

- PHP >= 8.4 with `ext-dom` and `ext-libxml`
- [brick/math](https://github.com/brick/math) (installed by Composer)

```bash
composer require openwerk/dmn
```

## Quickstart

```php
use OpenWerk\DecisionModelAndNotation\Dmn;

$model = Dmn::fromFile('fees.dmn');            // parsed, validated, cacheable

$result = $model->evaluateDecision('Fee', [
    'applicant' => ['age' => 34, 'income' => 22000],
]);

$result->value();         // BigDecimal(250.50), numbers are brick/math BigDecimal
$result->matchedRules();  // [MatchedRule{decision: "Fee", ruleIndex: 2, ruleId: "...", tableId: "..."}]
$result->diagnostics();   // null-propagation traces, hit-policy violations, ...
$result->hasErrors();     // true if any error-severity diagnostic was recorded
```

Errors never throw through the evaluation API. A failing expression
evaluates to null per FEEL semantics and the reason lands in the
diagnostics.

Useful things beyond the basics, each covered by tests:

```php
// Introspection: what can this model do, and why did it answer that way?
$model->invocables();                  // decisions, BKMs, decision services,
                                       // with declared parameter/result types
$result->matchedRulesByDecision();     // audit trail grouped per decision
$result->matchedRulesFor('Fee');       // one decision's hits, by name

// Caching: models are immutable value objects. Parse once, serialize into
// any byte store, unserialize on the hot path.
$cache->set('fees', serialize(Dmn::fromFile('fees.dmn')));
$model = unserialize($cache->get('fees'));

// Reproducible time: now() and today() pin to one instant per evaluation,
// and the instant is injectable. Good for tests.
$model->evaluateDecision('Late Fee', $inputs, now: new DateTimeImmutable('2026-01-01T00:00:00Z'));

// Imports: models can import other models, resolved by namespace against
// sibling .dmn files (or a custom ImportResolver). References chain
// through import levels ("mid.lib.Base Level"), and inputs for imported
// models are keyed by namespace.
$model->evaluateDecision('Christmas Bonus',
    ['monthly salary' => 3800],
    importedInputs: ['https://openwerk.example/dmn/seniority' => ['years of service' => 17]],
);
```

Validation is static, position-aware and never silent: unknown references,
duplicate names, cyclic requirements, FEEL syntax errors and full-FEEL
constructs in models that declare S-FEEL all surface through
`$model->validationMessages()` with element id and XML line. DMNDI diagram
content is exposed read-only via `$model->diagrams()` and never affects
evaluation.

## Conformance: check it yourself

The [DMN TCK](https://github.com/dmn-tck/tck) is the industry test suite
for DMN engines ([results site](https://dmn-tck.github.io/tck/)). Its
corpus currently holds 3391 executable cases across two compliance levels.
This engine runs all of them in CI on every build, pinned to a fixed
corpus revision:

| Level | Cases | Pass | Rate |
|---|---|---|---|
| CL2 (decision tables + S-FEEL) | 116 | 116 | 100% |
| CL3 (full FEEL) | 3275 | 3265 | 99.7% |
| Total | 3391 | 3381 | 99.7% |

The ten cases that do not pass are all in one suite,
`0076-feel-external-java`: FEEL functions defined as external Java or PMML
bindings. A pure PHP engine has nothing to bind them to, so this is a
deliberate non-goal; the cases run and report as errors rather than being
skipped.

To reproduce the numbers on your machine:

```bash
composer install
composer tck:fetch       # shallow-clones the pinned corpus into build/tck
php bin/tck-runner --tck build/tck/TestCases --level all --details
```

The summary prints per level; with `--details` every non-passing case is
listed, and you should see exactly the ten `0076-feel-external-java`
entries. `composer tck:results` additionally writes the results in the
TCK's official submission layout
(`build/TestResults/OpenWerk/<version>/tck_results.csv` plus a
`tck_results.properties` with the runner metadata), ready to be
contributed to the upstream results site.

### Documented deviations

Behavior that intentionally differs from a literal reading of the spec:

- External functions (`function(...) external` with Java/PMML bindings)
  evaluate to null with a diagnostic. This is the only deviation that
  costs TCK cases (the ten above).
- Regular expressions (`matches`, `replace`, `split`) translate the
  XPath/XSD dialect onto PCRE: the dot that never matches CR/LF, the
  whitespace-stripping `x` flag, character-class subtraction, block
  escapes and the escapes XSD rejects. `\p{IsXxx}` covers a table of
  common Unicode blocks; exotic blocks error to null with a diagnostic.
- Fractional seconds parse, preserve and print nine digits; arithmetic
  works at microsecond precision and equality on times and date-times at
  millisecond precision (the TCK reference granularity). Non-integer
  exponentiation and the transcendental builtins (`sqrt`, `log`, `exp`,
  `stddev`) go through binary floats.
- The list aliases `and()` / `or()` collide with the FEEL keywords in this
  grammar; use the spec-preferred `all()` / `any()`.
- A `time` with a zone id resolves its offset against 1970-01-01, because
  a time carries no date to resolve daylight saving with.
- When no rule matches and no default output is defined, every hit policy
  yields null with an info diagnostic; a defined default output is
  returned as-is (wrapped in a list for multi-hit policies).
- Caller inputs remain reachable as a fallback scope, and inputs matching
  a decision's name override that decision (the TCK's input-decision
  semantics; also handy for test injection).

## Composer scripts and automation

| Script | What it does |
|---|---|
| `composer check` | The full quality gate: `cs` + `phpstan` + `test` |
| `composer cs` | PSR-12 coding standard check (PHP_CodeSniffer) |
| `composer cs-fix` | Auto-fix coding standard violations where possible |
| `composer phpstan` | Static analysis at level max |
| `composer test` | PHPUnit test suite |
| `composer tck:fetch` | Fetch the pinned DMN TCK corpus into `build/tck` |
| `composer tck` | Run TCK compliance level 2 with details |
| `composer tck:results` | Run both levels, write the official submission layout |

Two GitHub Actions workflows:

- `ci.yml` runs on every push and pull request: `composer validate`, the
  full quality gate on PHP 8.4 and 8.5, every script in `examples/` (they
  double as smoke tests and exit non-zero on unexpected results), and the
  TCK with hard gates (CL2 at 100%, CL3 at 98% minimum).
- `tck-update.yml` runs monthly (and on manual dispatch). It compares the
  pinned corpus revision against the upstream master of dmn-tck/tck; when
  upstream moved, it re-pins `bin/fetch-tck`, runs both levels against the
  new corpus and opens a pull request with the measured results. The
  regular CI gates on that pull request then decide whether the re-pin can
  merge as-is or first needs conformance work.

## Contributing and code quality

Every change must pass the same gate CI runs:

```bash
composer check
```

That is PSR-12 (line limit 120), PHPStan at level max and PHPUnit, with
`bin/`, `src/`, `tests/` and `examples/` all in scope for the linter and
static analysis. New behavior needs tests, and spec-relevant behavior
should be pinned against the DMN specification or a TCK case, not against
incidental implementation details. Anything that deviates from the spec
belongs in the deviations list above.

See [CONTRIBUTING.md](CONTRIBUTING.md) for the spec-first ground rules and
the DCO sign-off.

## Status and roadmap

### External function invocation (`0076-feel-external-java`, 10 cases)  
Java and PMML bindings are out of scope for a pure PHP engine and
documented as a deviation in the README. Revisit only if a
conforming substitute gets specified, for example caller-injected
PHP callables.

### Explicit non-goals 

Modeling and editor UI, DMNDI rendering, persistence
and versioning, BPMN. The engine stays a pure, stateless evaluation
library.

## License and trademarks

[MIT](LICENSE) © 2026 OpenWerk - SCHWARZER digital

"DMN" (Decision Model and Notation) is a trademark of the Object
Management Group (OMG). This project is an independent implementation of
the DMN specification and is not affiliated with or endorsed by OMG.
Conformance claims are made only in DMN TCK terms: compliance level and
pass rate on the pinned corpus.
