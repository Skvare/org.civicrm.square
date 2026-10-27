# Development guide

The development tools (PHPUnit, PHPCS and the Drupal coding standard) are a
separate Composer project in `tools/`, whose `vendor/` is not committed. The
extension's own `vendor/` holds only what it runs (see [Releases](#releases)).
Run the following from the extension root:

```bash
composer install --working-dir=tools
tools/vendor/bin/phpcs --standard=phpcs.xml.dist CRM square.php settings managed tests
tools/vendor/bin/phpunit --configuration tests/phpunit/phpunit.xml.dist
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

Static analysis needs a CiviCRM source tree in `CIVICRM_CORE` (an extracted
release tarball works); PHPStan 1.12 at level 5. Use `phpstan.neon.dist` for
CiviCRM 6.15 or later, and `phpstan-civicrm-6.10.neon` for 6.10 to 6.14.
Findings that predate it are listed in `phpstan-baseline.neon`, so only new
ones fail.

The extension supports CiviCRM 6.10 and later. Where a core function differs
between the supported versions, call it through `CRM_Square_Compat`, which
checks the running version (`CRM_Utils_System::version()`) and calls the
function the way that version expects.

GitHub Actions (`.github/workflows/ci.yml`) runs:

- the linter, Composer validation and PHPUnit on PHP 8.2, 8.3 and 8.4;
- a check that the committed `vendor/` holds only runtime packages, matches
  `composer.lock`, and appends its autoloader rather than prepending it;
- the headless suite, on a throwaway CiviCRM 6.16.5 Standalone site (see
  below);
- PHPCS, and PHPStan against CiviCRM 6.10.2 and 6.16.5.

The in-memory fakes stand in for, but do not prove, CiviCRM's own
behaviour — the stand-ins in `tests/phpunit/bootstrap.php` once let a
lookup of 'In Progress' in the wrong option group pass. So keep their
option values identical to a fresh CiviCRM 6.16 install, and cover CiviCRM
API behaviour in the headless suite.

## Headless tests

`tests/phpunit-headless/` runs against a real CiviCRM through `cv` and
`Civi\Test::headless()`: status lookups, Contact email lookups,
Payment.create, Contribution.repeattransaction and refunds, the contact
merge hook, processor deletion, the extension's tables, and the calls in
`CRM_Square_Compat`. Square is still mocked at the SDK client.

**Only run it on a site whose `CIVICRM_UF=UnitTests` configuration points at a
dedicated test database** (cv's `TEST_DB_DSN`), such as a civibuild (buildkit)
site: `Civi\Test::headless()` drops every table in the database it is given.
That database must already hold CiviCRM's schema, as a buildkit site's does,
since the test listener boots it before resetting it. The mjwshared extension
must be present on the site.

```bash
cd /path/to/site
CIVICRM_UF=UnitTests /path/to/org.civicrm.square/tools/vendor/bin/phpunit \
  --configuration /path/to/org.civicrm.square/tests/phpunit-headless/phpunit.xml.dist
```

The `headless` job in `.github/workflows/ci.yml` builds such a site from
scratch (CiviCRM Standalone, `cv core:install`, the test database copied from
the new install) and is a recipe for doing the same locally. It runs on
CiviCRM 6.16.5 only: the public 6.10 release, 6.10.2, fails to install with
`cv`, and later 6.10 releases are not public.

Not yet covered: automated tests of `js/square.js` and browser tests of the
card form (including keeping its ZIP/postal code in step with the billing
address's).

## Releases

Sites install a tag's archive (e.g. GitHub's tag zip), so a tag is the
release:

- `vendor/` is committed with only the runtime dependencies: `composer.json`
  has no `require-dev`, the tools being in `tools/`.
- Its autoloader is appended, not prepended (`prepend-autoloader: false`), and
  loaded from `square_civicrm_config()`, so a site's own copies of shared
  libraries such as Symfony and the PSR HTTP interfaces win over the extension's.
- `.gitattributes` leaves tests, tools and CI configuration out of archives.

After changing `composer.json`, run `composer update` (or `composer require`)
and commit `vendor/` with `composer.lock`. CI fails if `vendor/` has
development packages, does not match `composer.lock`, or prepends its
autoloader.

## Code style

The extension uses CiviCRM's two-space, Drupal-derived style. `.editorconfig`
defines editor whitespace rules, and `phpcs.xml.dist` is the authoritative lint
configuration. It intentionally permits CiviCRM's `CRM_*` class names,
snake-case hook functions, the `ExtensionUtil as E` convention, and
underscore-prefixed core-compatible payment properties.

Do not lint or automatically format `vendor/`. Keep credentials, signature
keys, card tokens, and raw webhook bodies out of source, fixtures, and logs.
Update the relevant guide in this directory when changing setup, persistence,
webhook behavior, or development workflow.
