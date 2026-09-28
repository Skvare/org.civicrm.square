# Development guide

Run the following from the extension root:

```bash
vendor/bin/phpcs --standard=phpcs.xml.dist CRM square.php settings managed tests
vendor/bin/phpunit --configuration tests/phpunit/phpunit.xml.dist
CIVICRM_CORE=/path/to/civicrm phpstan analyse -c phpstan.neon.dist
```

PHPUnit treats deprecations, notices and warnings as failures. The suite
runs without a CiviCRM database: `tests/phpunit/bootstrap.php` loads real
CiviCRM first if `CIVICRM_BOOTSTRAP_FILE` is set, then defines minimal
stand-ins for whatever is missing. CiviCRM data access is confined to small
protected methods (e.g. `CRM_Square_Reconciler`'s find/record methods, and
`CRM_Core_Payment_SquareIPN`'s queue methods), which tests replace with
in-memory fakes (`SquareLedgerTestCase.php`) that mimic the core behaviour
the logic relies on. Square is mocked at the SDK client.

Static analysis needs a CiviCRM 6.16 source tree in `CIVICRM_CORE` (an
extracted release tarball works); PHPStan 1.12 at level 5. Findings that
predate it are listed in `phpstan-baseline.neon`, so only new ones fail.

GitHub Actions (`.github/workflows/ci.yml`) runs the linter, Composer
validation and PHPUnit on PHP 8.2, 8.3 and 8.4, and PHPCS and PHPStan
against CiviCRM 6.16.5.

Not yet covered: tests against a real CiviCRM database (e.g. `cv` and
`Civi\Test` headless tests on a buildkit site) and browser tests of the
card form. The in-memory fakes stand in for, but do not prove, CiviCRM's own
behaviour.

The extension uses CiviCRM's two-space, Drupal-derived style. `.editorconfig`
defines editor whitespace rules, and `phpcs.xml.dist` is the authoritative lint
configuration. It intentionally permits CiviCRM's `CRM_*` class names,
snake-case hook functions, the `ExtensionUtil as E` convention, and
underscore-prefixed core-compatible payment properties.

Do not lint or automatically format `vendor/`. Keep credentials, signature
keys, card tokens, and raw webhook bodies out of source, fixtures, and logs.
Update the relevant guide in this directory when changing setup, persistence,
webhook behavior, or development workflow.
