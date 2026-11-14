# Changelog

All notable changes to `configdirector/server-sdk` are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package uses
[semantic versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `ConfigDirector::createClient(...)` creates a `ConfigDirectorClient` from the named arguments
  `serverSdkKey`, `metadata`, `connection`, `logger`, `logLevel`, `telemetry` and `hooks`, without
  making a network call or touching a file. It throws `ConfigDirectorValidationException` when,
  outside offline mode only, the server SDK key is missing or blank, `connection.file` or
  `connection.values` is given, `connection.url` is not an http or https URL,
  `connection.storagePath` is not an absolute path or `connection.timeout` is zero or less; when
  `telemetry.flushInterval` is zero or less; or when `telemetry.eventQueueLimit` is outside 100 to
  100,000.
- The option objects `Metadata` (`appName`, `appVersion`), `ConnectionOptions` (`mode`,
  `pollingInterval` in seconds, `timeout` in milliseconds, `url`, `file`, `values`, `storagePath`),
  `TelemetryOptions` (`eventQueueLimit`, `flushInterval` in milliseconds) and `ClientHooks`, and the
  `ConnectionMode` enum with `Polling`, the default, and `Offline`. A `pollingInterval` below 60
  seconds is raised to 60 and a warning is logged.
- In offline mode (`ConnectionMode::Offline`) the client needs no server SDK key, never connects
  to ConfigDirector, creates no local store and sends nothing, so the application is invisible to
  the ConfigDirector dashboard. It serves one value per config key to every context, without
  targeting rules: the values of `connection.values`, or those of the JSON object in the file
  `connection.file`, which `initialize()` reads on every call. Without either, every read returns
  its in-code default value. `pollingInterval`, `timeout`, `url` and `storagePath` have no effect.
- In offline mode a served value is a match (`found-match`) with no `valueId` and no `variation`,
  a config key without a value returns its in-code default value with `config-state-missing`, and
  `getAllConfigs` returns a `ConfigState` with the id `offline:<key>` for each value.
  `initialize()` calls the `configsUpdated` handlers with every config key, then the watches, then
  the `clientReady` handlers. A later call names in `keys` only the configs whose value changed in
  the file, and in `removedKeys` those gone from it.
- A value's config type follows from the value: a `bool` is a boolean config, an `int` an integer
  config, a `float` a float config, served as plain decimal text such as `25` for `25.0` or
  `0.0000001` for `1.0E-7`, a `string` a string config, and an array a JSON config, whether a list
  or a map. A JSON object in the file stays a JSON object. An array can hold `null`, booleans,
  numbers, strings, arrays and `stdClass` objects.
- `createClient` throws `ConfigDirectorValidationException` in offline mode when both
  `connection.file` and `connection.values` are given, or when `connection.values` has a blank
  config key or holds a value the SDK cannot serve, such as `null`, a float that is not finite or
  an object other than `stdClass`.
- `ConfigDirectorInitializationException`, which `initialize()` throws in offline mode when
  `connection.file` is a URL or another stream such as `php://`, is missing, cannot be read, does
  not hold a JSON object, or holds a value the SDK cannot serve. A relative path is resolved against
  the working directory. A client that had no values stays not ready, and one that had values
  keeps serving them.
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
  invalid kinds a client logs one more error and then no longer logs them. A client that has lived
  longer than `telemetry.flushInterval` logs them again each time it has added its telemetry to
  the local store, and an offline client and a test client log an invalid kind on every read.
- `getAllConfigs(context:, configKeys:)` returns a `ConfigState` for every config, or for the listed
  config keys, keyed by config key. It is empty until the client is ready. A `ConfigState` has
  `id`, `key`, `type`, `value` (the served text), `valueId`, `variation`, `ruleId` and `shareId`.
- Each `getValue`, `evaluate` and watch delivery is reported to ConfigDirector as an evaluation:
  the config key, the value returned, why it was returned and, for a context with an `id` that is
  not anonymous, the context's kind and `id`. The dashboard shows this activity, and experiments
  count their exposures from it. A read that returns the in-code default value is reported with
  its reason, a read made before `initialize()` included. `getAllConfigs` and reads after
  `dispose()` are not reported. A value longer than 500 characters, and every JSON value, is
  reported as a value ID and not as its text.
- A context with an `id` that is not anonymous is sent to ConfigDirector with its kind, name and
  traits, and appears in the dashboard. An anonymous context, or one without an `id`, is never
  sent, and its evaluations name no context. A context with an invalid kind is counted under the
  first 128 characters of that kind, and nothing else of it is sent.
- A host sends its telemetry once per `telemetry.flushInterval`, however many requests it serves.
  When a client is disposed of it adds what it evaluated and tracked to the local store, without a
  request to ConfigDirector. The first request to end at least a flush interval after the host's last
  report, or after its first client was created when it has sent none, sends what every process
  stored as one report, after it has asked for values when that is due too. It waits up to 5
  seconds for the answer, so under PHP-FPM the end of the response waits as well unless the
  application has called `fastcgi_finish_request()`. A client that was never initialized, read
  nothing and tracked nothing creates no local store and sends nothing.
- A client that has lived longer than `telemetry.flushInterval`, as in a queue worker, adds what
  it evaluated and tracked to the local store once per flush interval, during the read or the
  `track()` call that finds it due, and sends the host's report in that call when it is due,
  waiting up to 5 seconds for the answer. Disposing of such a client sends what the local store
  holds at once.
- `telemetry.eventQueueLimit` limits what one report holds, and what one client holds until it is
  disposed of: 70% of it, rounded up, for evaluations, where reads of one config that return the
  same result for the same context are one entry with a count, and 30%, rounded down, for
  contexts. The oldest entries over the limit are left out, and ConfigDirector receives a count
  of them. The telemetry kept in the local store is limited to 8 MiB, and what no longer fits is
  counted as dropped too.
- `track($eventName, $context, value:, details:)` reports that something happened, such as a
  completed checkout, with the host's next telemetry report, under the name, the context's kind
  and `id`, the value, the details and the time of the call. It never throws, and does nothing in
  offline mode or after `dispose()`. The context's `id` is sent only when it is not empty and the
  context is not anonymous, a context with an invalid kind is logged and counted as for a read and
  the event is sent without it, and the context is not sent with its name and traits.
- `track()` drops an event whose name does not have 1 to 150 characters. It sends an event without
  its value when the value is not finite, and without its details when they are a list, have more
  than 20 top-level keys, are larger than 1,024 bytes as JSON or cannot be written as JSON. An
  empty details array is sent as an empty object. A client holds at most 1,000 tracked events until
  it adds them to the local store, and a report holds at most 1,000: the first ones the host's
  requests tracked. Each cause logs one
  warning until the client next adds its telemetry to the local store, and the request that sends
  a report logs one warning when the host tracked more than a report holds.
- When ConfigDirector refuses a telemetry report with a 4xx status other than 413 and 429, no
  process of the host collects or sends telemetry for one hour, and the process that sent the
  report logs one warning. No `connectionError` handler is called. A report answered with any
  other status that is not 2xx, or not answered within 5 seconds, is dropped with one warning and
  never sent again, and the next report counts what it held as dropped. When the telemetry file of
  the local store cannot be written or locked, each client logs one warning that names the file.
  A client whose local store cannot be used reports nothing.
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
  than `telemetry.flushInterval` and a read finds changed values in the local store, or when the
  test client it belongs to changes the values it serves. Each time, it calls the `configsUpdated`
  handlers, then the watches on the keys the event names, then the `clientReady` handlers when the
  client has just become ready. It takes in one set of values at a time: values that arrive while
  its handlers and watches run, such as a change a handler makes, are taken in once those have run.
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
  `ConfigDirectorValidationException`, `ConfigDirectorInitializationException` and
  `ConfigDirectorConnectionException`.
- `ConfigDirector\Testing\ConfigDirectorTesting::createTestClient(values:, timeout:, logger:)`
  returns a `TestClient`: the SDK's real client, as `$testClient->client`, over an in-memory
  connection that the test controls. It needs no server SDK key, never connects to
  ConfigDirector, creates no local store and sends nothing. `values` takes the values
  `connection.values` takes, `timeout` defaults to 3,000 milliseconds and `logger` to the SDK's
  `error_log` logger. It throws `ConfigDirectorValidationException` for a blank config key, a value
  the SDK cannot serve, or a `timeout` of zero or less.
- A test client's client starts not initialized, and `initialize()` serves the values at once.
  Every context receives the same value, as a match (`found-match`) without a `valueId` or a
  `variation`, and `getAllConfigs` gives each config the id `test-config:<key>`.
- `setValue($key, $value)`, `removeValue($key)` and `replaceValues($values)` change the values a
  test client serves. While its client is connected, from an `initialize()` that serves the values
  or a `completeInitialization()` until its `dispose()` or an `initialize()` that is held or
  fails, the change reaches the client before the call returns: the `configsUpdated` handlers and
  the watches of the configs whose value changed run inside the call. A change made from inside a
  handler or a watch reaches the client after the change being handled, and one made while the
  client is not connected reaches it once it is connected again. `setValue` and `replaceValues`
  throw `ConfigDirectorValidationException` for a blank config key or a value the SDK cannot
  serve, and change nothing then. `replaceValues` also cancels a hold or a failure that no
  `initialize()` has used yet.
- `holdInitialization()` makes the next `initialize()` wait for the timeout, log the warning a
  production client logs then, and return without values. `completeInitialization()` then
  delivers the values and makes the client ready, calling the `clientReady` handlers once; called
  before `initialize()`, it cancels the hold. `failInitialization()` makes the next `initialize()`,
  or a held one that has returned, fail at once as a refused server SDK key does: an error is
  logged and the `connectionError` handlers receive a `ConfigDirectorConnectionException` with the
  status 401. In both cases a client that was not ready stays not ready, and one that was ready
  keeps serving the values it had, until the next `initialize()` serves the values.
- `trackedEvents()` returns a copy of the events the code under test tracked through a test
  client's client, in call order, each a `ConfigDirector\Testing\TrackedEvent` with `name`,
  `context`, `value` and `details`, as the SDK would report them: an event the SDK would drop is
  not recorded, a value or details it would leave out are `null`, a context with an invalid kind is
  `null`, and details are recorded as the SDK would send them, read back as arrays. Each event the
  SDK would not report as it was tracked logs a warning, the limit of 1,000 events does not apply,
  nothing is recorded after `dispose()`, and nothing is sent. `clearTrackedEvents()` forgets them,
  and `replaceValues` leaves them as they are.
- `ConfigDirector::createStreamingProcess(...)` creates a `StreamingProcess` from the named
  arguments `serverSdkKey`, `metadata`, `connection`, `logger`, `logLevel` and `telemetry`, without
  making a network call or touching a file. Its `run()` keeps one streaming connection to
  ConfigDirector open for a host and writes every change to the local store, so a change to the
  targeting rules reaches the host's requests in about a second, where they otherwise wait for
  the next polling interval. `createStreamingProcess` throws `ConfigDirectorValidationException`
  for what `createClient` rejects outside offline mode, and for `ConnectionMode::Offline`.
  `connection.pollingInterval` and `connection.timeout` change nothing about a streaming process.
- The command `vendor/bin/configdirector-stream` runs a streaming process from the environment
  variables `CONFIGDIRECTOR_SERVER_KEY`, which is required, and `CONFIGDIRECTOR_BASE_URL`,
  `CONFIGDIRECTOR_STORAGE_PATH`, `CONFIGDIRECTOR_APP_NAME`, `CONFIGDIRECTOR_APP_VERSION` and
  `CONFIGDIRECTOR_LOG_LEVEL`. A blank variable counts as not set, and the command takes no
  arguments and reads no `.env` file. It writes its log to standard error, whatever PHP's
  `error_log` setting, from the level `info`, so its first line names the folder of the local
  store it keeps up to date. It exits with 0 after SIGTERM or SIGINT and with 1 otherwise: when
  the key variable is not set or a setting is rejected, with the reason on standard error, and
  when the streaming process stops because of a failure.
- While the connection of a streaming process is open, no request of the host asks
  ConfigDirector for values. A request reads a change once the streaming process has written
  it to the local store, and a client that has lived longer than `telemetry.flushInterval` reads
  it within a second. When the connection is down for longer than the polling interval of the
  host's clients, their requests ask ConfigDirector by themselves again until it is back, and
  they do the same once the streaming process has stopped.
- While a streaming process runs, no request of the host sends telemetry: each client still
  adds what it evaluated and tracked to the local store, and the streaming process sends one
  report per `telemetry.flushInterval`, whether or not its connection is open. The reports carry
  the `metadata` of the streaming process, so give it the `appName` and `appVersion` of the
  application. When ConfigDirector refuses a report with a 4xx status other than 413 and 429, the
  streaming process logs the warning a client logs, sends nothing for one hour and keeps
  running. Once the streaming process is no longer running, the requests send again.
- `run()` returns after SIGTERM or SIGINT, once it has sent the telemetry the local store holds.
  This needs the pcntl extension, which the package suggests. While it runs, `run()` handles the
  two signals itself, and it puts back the handlers that were there before it returns. Without
  the extension, and on Windows, a signal ends the PHP process at once, and the requests send
  what the local store holds.
- `run()` throws `ConfigDirectorConnectionException`, with the `status`, when ConfigDirector
  refuses the connection with a 4xx status other than 429, as it does for a server SDK key it
  does not know. It throws `ConfigDirectorException` when another streaming process already
  runs on the same local store, because a host needs one connection only, or when the local
  store cannot be used. Each logs one error first. A streaming process that was killed does not
  keep the next one from starting.
- A streaming process opens its connection again by itself when ConfigDirector cannot be reached
  or answers with another status, when the connection is closed, and when nothing arrives on it
  for 45 seconds or no answer arrives within 30. Before each attempt it waits: 1 to 2 seconds
  before the first, then twice as long with each attempt that fails, up to between 256 and 512
  seconds, where the exact time within each range is drawn at random. The count starts over
  once a connection has opened. When ConfigDirector names a time to wait, in the `Retry-After`
  of a 429 or in the stream, the streaming process waits that long instead, for at least one
  second and at most one hour. The first five attempts in a row are logged at `info` and the
  later ones as warnings.
- When the streaming process cannot write the values it received to the local store, it logs a
  warning and opens its connection again to receive them once more, waiting longer before each
  attempt until they are written.
