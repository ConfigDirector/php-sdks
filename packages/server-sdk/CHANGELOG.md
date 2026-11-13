# Changelog

All notable changes to `configdirector/server-sdk` are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package uses
[semantic versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `ConfigDirector::createClient(...)` creates a `ConfigDirectorClient` from the named arguments
  `serverSdkKey`, `metadata`, `connection`, `logger`, `logLevel`, `telemetry` and `hooks`, without
  making a network call. It throws `ConfigDirectorValidationException` when the server SDK key is
  missing or blank or `connection.url` is not an http or https URL, both outside offline mode
  only, when `connection.timeout` or `telemetry.flushInterval` is zero or less, or when
  `telemetry.eventQueueLimit` is outside 100 to 100,000.
- The option objects `Metadata` (`appName`, `appVersion`), `ConnectionOptions` (`mode`,
  `pollingInterval` in seconds, `timeout` in milliseconds, `url`, `file`, `values`, `storagePath`),
  `TelemetryOptions` (`eventQueueLimit`, `flushInterval` in milliseconds) and `ClientHooks`, and the
  `ConnectionMode` enum with `Polling`, the default, and `Offline`. A `pollingInterval` below 60
  seconds is raised to 60 and a warning is logged.
- `initialize()` waits for values up to `connection.timeout` and makes the client ready. When no
  values arrive in time it logs a warning and returns. `isReady()` says whether the client has
  values to serve. On a disposed client `initialize()` logs a warning and the client stays not
  ready.
- `getValue($configKey, $defaultValue, $context)` returns a config's value as the type of the
  in-code default value: a `bool`, a `string`, an `int`, a `float`, or an array for a JSON config,
  whether it holds a JSON object or a JSON array. An `int` read accepts a whole number written as
  `26.0` or `1e3`. A `null` in-code default value, or one of any other type, throws
  `ConfigDirectorValidationException`.
- `evaluate(...)` takes the same arguments and returns a `ConfigEvaluation` with `key`, `value`,
  `valueId`, `variation` (a `Variation` with `id` and `name`), `isDefaultValue`, `reason` and
  `context`.
- The `EvaluationReason` enum, with the text every ConfigDirector SDK uses: `found-match`,
  `rollout-skipped-no-identifier`, `config-state-missing`, `client-not-ready`, `type-mismatch`,
  `value-missing`, `invalid-number`, `invalid-json` and `invalid-boolean`. Every reason but the first
  two returns the in-code default value.
- `Context` (`id`, `kind`, `name`, `traits`, `anonymous`) is the user or other entity a config is
  evaluated for. A context whose `kind` is not 1 to 64 characters of lowercase letters, digits, `-`
  and `_` is ignored whole: the config is evaluated as if no context was given, the evaluation's
  `context` is `null`, and an error is logged once for each invalid kind. After 20 different
  invalid kinds a client logs one more error and then no longer logs them.
- `getAllConfigs(context:, configKeys:)` returns a `ConfigState` for every config, or for the listed
  config keys, keyed by config key. It is empty until the client is ready. A `ConfigState` has
  `id`, `key`, `type`, `value` (the served text), `valueId`, `variation`, `ruleId` and `shareId`.
- `dispose()` can be called more than once. Afterwards the client is not ready, every read returns
  its in-code default value with the reason `client-not-ready`, `getAllConfigs` is empty, and every
  event handler, hook and watch is removed.
- `on($eventName, $handler)` subscribes to the events `clientReady`, `configsUpdated`,
  `configEvaluated` and `connectionError`. `off($eventName, $handler)` removes that handler, or
  every handler of the event when no handler is given. An unknown event name throws
  `ConfigDirectorValidationException`.
- `clientReady` handlers are called with no argument when the client first receives values.
  `configsUpdated` handlers receive a `ConfigsUpdatedEvent` with `keys`, the config keys received,
  and `removedKeys`, the keys no longer received. `configEvaluated` handlers receive a
  `ConfigEvaluatedEvent` with the `evaluation` of each `getValue`, `evaluate` and watch, before the
  read returns; `getAllConfigs` fires none. `connectionError` handlers receive a
  `ConnectionErrorEvent` with the `error`; nothing fires it yet, because the client does not connect
  to ConfigDirector yet.
- `ClientHooks` takes one handler or a list of handlers for each event, as the named arguments
  `clientReady`, `configsUpdated`, `configEvaluated` and `connectionError`, registered before any
  event can fire. An entry of a list that is not callable is ignored and a warning is logged.
- The client receives values during `initialize()`. Each time, it calls the `configsUpdated`
  handlers, then the watches on the keys received or removed, then the `clientReady` handlers when
  the client has just become ready.
- `watch($configKey, $defaultValue, $callback, $context)` calls the callback with the config's
  value each time the client receives values that include the config, whether or not the value
  changed, and with the in-code default value when the config is removed. It returns a function
  that removes that watch. `unwatch($configKey, $callback)` removes the first watch on the key with
  that callback, or every watch on the key when no callback is given, and `unwatchAll()` removes
  every watch.
- A handler or watch that throws is logged at error level, with the exception under the PSR-3
  `exception` key, and ignored: the other handlers still run and the read returns its value. The
  default logger ends the line with the exception's class and message.
- `logger` takes a PSR-3 logger. Without one the SDK writes warnings and errors through
  `error_log`, and `logLevel` takes a PSR-3 level, such as `Psr\Log\LogLevel::DEBUG`, to write
  more or less. A `logLevel` that is not a PSR-3 level throws `ConfigDirectorValidationException`.
  `logLevel` has no effect when a `logger` is given.
- `ConfigDirectorException` is the base class of every exception the SDK throws.
