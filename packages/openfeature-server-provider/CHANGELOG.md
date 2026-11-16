# Changelog

All notable changes to `configdirector/openfeature-server-provider` are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package uses
[semantic versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-11-15

### Added

- `ConfigDirector\OpenFeature\ConfigDirectorProvider`, an OpenFeature provider backed by the
  ConfigDirector PHP server SDK, registered under the name `ConfigDirectorProvider`. Its
  constructor takes the named arguments of `ConfigDirector::createClient`, `serverSdkKey`,
  `metadata`, `connection`, `logger`, `logLevel`, `telemetry` and `hooks`, and throws
  `ConfigDirectorValidationException` for the same settings. It reads the values ConfigDirector
  serves when it is created, as the SDK's `initialize()` does, and throws
  `ConfigDirectorInitializationException` in offline mode when the values of `connection.file`
  cannot be loaded.
- Boolean, string, integer, float and object flags resolve through the SDK. A value served
  resolves with the reason `TARGETING_MATCH`, and a percentage rollout skipped for a context
  without a targeting key with `DEFAULT`, each with the name of the variation served as the
  variant, or the ID of the value. A config without a value resolves to the in-code default value
  with `DEFAULT`. A missing config, a value that cannot be read as the requested type, and a
  provider that has no values yet resolve to the in-code default value with the reason `ERROR`
  and the error codes `FLAG_NOT_FOUND`, `TYPE_MISMATCH` and `PROVIDER_NOT_READY`.
- The evaluation context becomes the ConfigDirector context: the targeting key, or failing that
  an `id` attribute, becomes its `id`, and the `kind`, `name`, `traits` and `anonymous`
  attributes the fields of those names. An `id` or a `name` that is a number, a boolean or a
  date is read as text, and dates, inside `traits` too, as ISO 8601 text. A context whose `kind`
  is not a string is ignored.
- The provider reports itself to ConfigDirector as `php-openfeature-server-provider` with its own
  version.
- Composer does not install the provider beside `myclabs/php-enum` 1.8.0, which the OpenFeature
  PHP SDK allows and which makes PHP report a deprecation when OpenFeature's error codes load.
- The OpenFeature PHP SDK has no provider events, tracking, initialization step or shutdown, so
  the provider emits no events, has no `track()`, does not fail when no values arrive in time and
  is not shut down; its client disposes of itself.
