# Contributing

## Layout

This repository publishes two Composer packages, each from a directory under `packages/`:

- [server-sdk](packages/server-sdk/), the server SDK: package `configdirector/server-sdk`, namespace
  `ConfigDirector\`. At runtime it depends on the curl and JSON extensions and the PSR-3 logger
  interface (`psr/log`) only.
- [openfeature-server-provider](packages/openfeature-server-provider/), the OpenFeature provider built
  on it: package `configdirector/openfeature-server-provider`, namespace `ConfigDirector\OpenFeature\`.

Each package is a Composer project of its own, with its own `composer.json`, `composer.lock`, `vendor/`
and development tools, so that a release can copy it to a repository of its own (see
[Releasing](#releasing)). Each has its own version, changelog, and release.

The provider reaches the SDK in this working tree through a Composer path repository, which reports
the SDK as `dev-main` on any branch or commit, and the provider requires exactly that version. Before
the provider's first release, its requirement has to name a released version of the SDK instead.

The lock files are committed and resolved for PHP 8.3.36 (`config.platform.php` in each
`composer.json`), so one lock installs on every PHP version CI runs. After changing a package's
`composer.json`, run `composer update` in its directory. After changing the SDK's requirements, also
run `composer update configdirector/server-sdk` in the provider's directory and in each sample's, so
they install them too.

[`samples/`](samples/) holds sample applications, each a Composer project of its own with a committed
`composer.lock`, under `samples/<package>/<framework>`. Until a package's first release, a sample
requires it from this working tree through a path repository, as the provider requires the SDK.

## Working on the packages

You need PHP 8.3 or later, Composer 2, and Docker for `make check-floor`. Every PHP release we run
ourselves must have the fixes for CVE-2026-109618 and CVE-2026-109619: 8.3.36, 8.4.27, 8.5.12 or a
later release on the same line. On macOS, `brew install php composer` installs a patched PHP 8.5.

```sh
make install       # composer install, every package and sample
make hooks         # pre-push hook that runs make check-all
make check         # lint and test: the fast loop
make samples-local # PHPStan and the samples' suites, against the packages in this working tree
make check-floor   # lint and the suites on PHP 8.3, in Docker
make check-all     # everything CI runs
```

Targets that run per package take `PACKAGES`, so `make test PACKAGES=server-sdk` runs one suite.
`make install`, `lint`, `format`, `verify-lock` and `audit` also run on every sample.

`make lint` runs PHP-CS-Fixer in dry-run mode with the PER coding style, then PHPStan at its strictest
level with no baseline; a finding is fixed, not ignored. `make format` applies PHP-CS-Fixer's fixes.
PHPUnit fails a run on warnings, notices, deprecations and risky tests.

`make verify-php` prints the version of the PHP that runs it and fails when that release lacks the CVE
fixes above; every CI job runs it first. `make verify-lock` fails when a `composer.lock` no longer
matches its `composer.json`. `make audit` runs `composer audit` against each lock file.

`make test-lowest` runs the suites against the lowest version of every dependency that each
`composer.json` allows, installed in a throwaway directory, so the committed lock files stay as they
are. `make check-floor` runs `make verify-php lint test samples-local` in the official `php:8.3-cli`
image, pulled again on every run so that it is the newest 8.3 release, on a copy of this working tree
without any `vendor/`. PHP-CS-Fixer is meant to run on the lowest PHP a project supports and warns on a newer one,
so `make check-all` lints there and not on your own PHP; `make check` and `make format` still run it on
your PHP and print that warning.

CI runs the suites on PHP 8.3, 8.4 and 8.5 on Ubuntu, on PHP 8.5 on macOS, and on PHP 8.3 and 8.5
on Windows, and `make test-lowest` and `make samples-local` on PHP 8.3 and 8.5. Locally,
`make check-all` covers your own PHP and, through `make check-floor`, PHP 8.3.

The local store tests of the server SDK start real PHP processes that read and write one store, from
the scripts in `packages/server-sdk/tests/Internal/LocalStore/Processes/`. The tests on file modes,
symbolic links, folder ownership and a writer killed while writing are skipped on Windows. The test
that a folder owned by another system user is refused runs only as the root user, which is what
`make check-floor` runs as, and the test that a folder without write permission is refused runs only
as any other user.

The checks live in the [Makefile](Makefile). CI and the pre-push hook only call `make` targets, so add
new checks there rather than in either of them.

## How the provider identifies itself

The SDK will report an SDK name and version in the `User-Agent` header and in telemetry:
`php-server-sdk` and `ConfigDirector\Version::CURRENT`. A package built on the SDK, such as the
provider, will report its own name and version, `php-openfeature-server-provider` and
`ConfigDirector\OpenFeature\Version::CURRENT`, without the SDK accepting an arbitrary identity from its
caller: the SDK will keep a closed list of the packages allowed to. This list does not exist yet; it
will arrive with the provider. Adding a wrapper package will mean adding it to that list, releasing
the SDK, and registering the new name in the ConfigDirector capability tables.

## Releasing

Each package is released on its own. Its version lives in exactly one place, the `CURRENT` constant
of its `Version` class:

| Package | Version | Changelog |
|---|---|---|
| configdirector/server-sdk | [`Version.php`](packages/server-sdk/src/Version.php) | [CHANGELOG.md](packages/server-sdk/CHANGELOG.md) |
| configdirector/openfeature-server-provider | [`Version.php`](packages/openfeature-server-provider/src/Version.php) | [CHANGELOG.md](packages/openfeature-server-provider/CHANGELOG.md) |

Packagist publishes one package per repository, so a release will copy the package's directory and
its tag to a read-only repository of its own, `ConfigDirector/php-server-sdk` or
`ConfigDirector/php-openfeature-server-provider`, which Packagist reads. The release workflow and the
steps to run it are not written yet; this section will describe them once they are.

### Version line

The packages stay at `0.x` until the SDK functional tests pass against them in dev, which is when
`1.0.0` is released.
