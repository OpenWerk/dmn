# Contributing to openwerk/dmn

Thanks for your interest in contributing!

## Quality gate

Every change must pass the full gate, the same one CI runs on PHP 8.4
and 8.5:

```bash
composer install
composer check   # = composer cs + composer phpstan + composer test
```

- **Coding standard:** PSR-12, enforced with PHP_CodeSniffer (`composer cs`,
  auto-fix with `composer cs-fix`).
- **Static analysis:** PHPStan at level `max` (`composer phpstan`).
- **Tests:** PHPUnit (`composer test`). New behavior needs tests; spec
  semantics (FEEL types, hit policies, null propagation) need tests pinned to
  the DMN specification, not to incidental implementation behavior.

## Spec-first rules

- The OMG DMN 1.5 specification is the contract. If an implementation choice
  deviates from it, the deviation must be documented in the README.
- The [DMN TCK](https://github.com/dmn-tck/tck) is the primary harness:
  `composer tck:fetch` once, then `composer tck`. CI gates compliance
  level 2 at 100% and level 3 at 98%; a change that drops a passing case
  is a regression. The only expected failures are the ten external-function
  cases documented in the README.
- The engine stays a pure, stateless evaluation library: no modeling UI, no
  DMNDI rendering, no persistence, no BPMN, no framework coupling.

## Developer Certificate of Origin

By contributing you certify the [Developer Certificate of Origin
(DCO)](https://developercertificate.org/). Sign your commits with
`git commit --signoff`.
