# Changelog

All notable changes to `configdirector/server-sdk` are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package uses
[semantic versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `ConfigDirector::createClient(...)` creates a `ConfigDirectorClient` from the named arguments
  `serverSdkKey`, `metadata`, `connection`, `logger`, `logLevel`, `telemetry` and `hooks`, without
  making a network call or touching a file. It throws `ConfigDirectorValidationException` when the
  server SDK key is missing or blank, `connection.url` is not an http or https URL or
  `connection.storagePath` is not an absolute path, all three outside offline mode only, when
  `connection.timeout` or `telemetry.flushInterval` is zero or less, or when
  `telemetry.eventQueueLimit` is outside 100 to 100,000.
- The option objects `Metadata` (`appName`, `appVersion`), `ConnectionOptions` (`mode`,
  `pollingInterval` in seconds, `timeout` in milliseconds, `url`, `file`, `values`, `storagePath`),
  `TelemetryOptions` (`eventQueueLimit`, `flushInterval` in milliseconds) and `ClientHooks`, and the
  `ConnectionMode` enum with `Polling`, the default, and `Offline`. A `pollingInterval` below 60
  seconds is raised to 60 and a warning is logged.
- `initialize()` reads the values ConfigDirector serves and makes the client ready. The SDK keeps
  them in a local store, a folder shared by the PHP processes of one host, so a request reads them
  from there without connecting to ConfigDirector. When the local store is empty, `initialize()`
  asks ConfigDirector for the values and waits up to `connection.timeout`, and requests that
  arrive meanwhile wait for that same answer. `isReady()` says whether the client has values to
  serve. On a disposed client `initialize()` logs a warning and the client stays not ready.
- When `initialize()` returns without values, every read returns its in-code default value. It
  logs a warning when the wait reached `connection.timeout`. When its own request to
  ConfigDirector failed, or the local store cannot be used, the error logged for that is the only
  line. After a failed request, the requests that follow do not wait and log one line at debug
  level, until the host asks ConfigDirector again.
- The values in the local store are served for `connection.pollingInterval`. The request that
  finds them older asks ConfigDirector for them again when its client is disposed of, after the
  application's own code, so a host asks once per polling interval however many requests it
  serves. A client that has lived longer than `telemetry.flushInterval`, as in a queue worker,
  asks during the read that finds them older, and looks at the local store again at most once a
  second, so it serves what another process of the host received.
- A request to ConfigDirector takes at most `connection.timeout`, and one that gets no answer is
  sent again once within that time. After a failed request the host does not ask again for one
  polling interval. After a 4xx answer other than 429 it does not ask for one hour, or for the
  polling interval when that is longer, and after a 429 for the longer of its `Retry-After`,
  counted for at most one hour, and the polling interval. Each failed request logs one error with
  a `ConfigDirectorConnectionException`.
- The local store is a folder named after the SHA-256 digest of the server SDK key, inside
  `connection.storagePath`, which the SDK creates when it is missing. Without a `storagePath` it
  is inside `<system temp directory>/configdirector-<user ID>` on Linux and macOS, where the user
  ID is the number of the system user running PHP and only that user can read the folder, and
  inside `<system temp directory>\configdirector` on Windows. Web workers and command-line jobs
  that run as different system users each keep a local store of their own.
- A local store folder that is a symbolic link, belongs to another system user, or cannot be
  created or written to is not used: the client never asks ConfigDirector for values, every read
  returns its in-code default value, and one error that names the folder is logged for each
  client. A file of the local store that cannot be read counts as an empty local store, with one
  error for each client.
- A config or segment that the SDK cannot read in the values it received is skipped, and every
  other config is served. A read of a skipped config returns its in-code default value with the
  reason `config-state-missing`. The request that received the values logs a warning that names
  the config or segment, and requests that read them from the local store log it at debug level.
  Fields the SDK does not know are ignored, and a config of a type it does not know is served
  under that type.
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
- A client disposes of itself, so calling `dispose()` is optional: when the script ends, after
  the application's own shutdown functions have run, or as soon as nothing refers to the client any
  longer, whichever comes first.
- `on($eventName, $handler)` subscribes to the events `clientReady`, `configsUpdated`,
  `configEvaluated` and `connectionError`. `off($eventName, $handler)` removes that handler, or
  every handler of the event when no handler is given. An unknown event name throws
  `ConfigDirectorValidationException`.
- `clientReady` handlers are called with no argument when the client first receives values.
  `configsUpdated` handlers receive a `ConfigsUpdatedEvent` with `keys` and `removedKeys`. The
  first values a client receives name every config in `keys`. Later values name in `keys` only
  the configs that are new or changed, and in `removedKeys` the configs that are gone. A config
  counts as changed when anything the SDK reads of it changed, such as its targeting rules or its
  variations, or when a segment its targeting rules use changed. `configEvaluated` handlers
  receive a
  `ConfigEvaluatedEvent` with the `evaluation` of each `getValue`, `evaluate` and watch, before the
  read returns; `getAllConfigs` fires none. `connectionError` handlers receive a
  `ConnectionErrorEvent` with the `error`, a `ConfigDirectorConnectionException` whose `status` is
  the HTTP status ConfigDirector answered with. It fires when ConfigDirector refuses a request
  with a 4xx status other than 429, in the process that sent the request: during `initialize()`,
  when the client is disposed of, or during a read of a client that has lived longer than
  `telemetry.flushInterval`.
- `ClientHooks` takes one handler or a list of handlers for each event, as the named arguments
  `clientReady`, `configsUpdated`, `configEvaluated` and `connectionError`, registered before any
  event can fire. An entry of a list that is not callable is ignored and a warning is logged.
- The client receives values during `initialize()`, and after that only when it has lived longer
  than `telemetry.flushInterval` and a read finds changed values in the local store. Each time, it
  calls the `configsUpdated` handlers, then the watches on the keys the event names, then the
  `clientReady` handlers when the client has just become ready.
- `watch($configKey, $defaultValue, $callback, $context)` calls the callback with the config's
  value each time the client receives values in which the config is new or changed, whether or
  not the value changed for the watch's context, and with the in-code default value when the
  config is removed. It returns a function
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
- `ConfigDirectorException` is the base class of every exception of the SDK, including
  `ConfigDirectorValidationException` and `ConfigDirectorConnectionException`.
