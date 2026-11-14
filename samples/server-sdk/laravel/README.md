# Laravel sample

A [Laravel](https://laravel.com) app using the ConfigDirector PHP server SDK. Like the
[Slim sample](../slim/), it has a single `/configs` route that evaluates a handful of configs and
returns them as JSON. It also runs the SDK's streaming process as an Artisan command, so that a
change to your targeting rules reaches the app in about a second instead of at the next poll.

The app is trimmed to what the sample needs: it has no database, views, sessions or front-end
build, and no `APP_KEY` to generate.

## Running it

You need Composer 2 and PHP 8.3.36, 8.4.27, 8.5.12 or a later release on the same line, the first
releases with the fixes for CVE-2026-109618 and CVE-2026-109619. The streaming process stops cleanly
on Ctrl-C when PHP has the pcntl extension.

In a first terminal, start the web server:

```bash
cd samples/server-sdk/laravel
composer install
cp .env.example .env      # optional, the sample runs without a real key
php artisan serve --port=3710
```

Then:

```bash
curl 'http://127.0.0.1:3710/configs?id=user-123&plan=pro'
```

```json
{
  "temporary-feature-flag": true,
  "permanent-kill-switch": false,
  "integer-config": 10,
  "day-of-the-week-config": "Friday",
  "json-value-config": []
}
```

Those are the in-code default values the sample passes in, which is what it serves without a real
key. Put the server SDK key of one of your environments in `.env` as `CONFIGDIRECTOR_SERVER_KEY`,
create configs with these five keys in that project, and the response shows what your targeting
rules serve to the context in the query string.

With a real key in `.env`, start the streaming process in a second terminal:

```bash
cd samples/server-sdk/laravel
php artisan configdirector:stream
```

```
[2026-11-13 19:42:39] production.INFO: [StreamingProcess] Keeping the local store in /tmp/configdirector-1000/3f5c… up to date.
```

Change a targeting rule in ConfigDirector and the next request to `/configs` serves the change. Stop
either one with Ctrl-C.

Query parameters double as the context the configs are evaluated for: `id`, `kind`, `name` and
`anonymous` map to the matching `Context` fields, and anything else becomes a trait:

```
/configs?id=user-123&name=Ada&plan=pro&region=eu
```

Any other route answers `404` with a hint to `GET /configs`.

Run the tests with `vendor/bin/phpunit`.

## One client per request

[`ConfigDirectorServiceProvider`](app/Providers/ConfigDirectorServiceProvider.php) binds the client
in the service container as a scoped binding. The first time a request asks the container for it,
the container creates it and calls `initialize()`; everything else in that request that asks gets
the same client, and the next request gets a client of its own.

```php
$this->app->scoped(
    ConfigDirectorClient::class,
    static function (Application $app): ConfigDirectorClient {
        $settings = $app->make(ConfigDirectorSettings::class);
        $client = ConfigDirector::createClient(
            serverSdkKey: $settings->serverSdkKey,
            metadata: $settings->metadata,
            connection: $settings->connection,
            logger: $app->make(LogManager::class)->channel(ConfigDirectorSettings::LOG_CHANNEL),
        );
        $client->initialize();

        return $client;
    },
);
```

[`ConfigsController`](app/Http/Controllers/ConfigsController.php) takes the client as an argument of
`__invoke()` and reads all five configs through it. Ask for the client in the method that handles the
request rather than in a controller's constructor: Laravel keeps a controller instance on its route,
so in a process that serves more than one request, a client given to the constructor would outlive
the request it was made for.

A PHP request starts with empty memory: PHP-FPM, Apache's PHP module and `php artisan serve` run the
application again for each request and throw away everything it created when the request ends. The
SDK keeps what has to outlive a request outside the client: it keeps the values ConfigDirector
serves in a local store on disk, shared by the PHP processes of one host, and only one request per
polling interval asks ConfigDirector for new ones, after its response is built. A client per
request costs a read of that store, not a connection to ConfigDirector.

## The streaming process

Without it, a request that finds the local store older than the polling interval (5 minutes by
default) asks ConfigDirector for new values. The streaming process holds one streaming connection
for the host instead, writes each change to the local store as it arrives, and sends the telemetry
the requests collected there. While its stream is open, the local store stays fresh and requests
do not poll.

[`ConfigDirectorStreamCommand`](app/Console/Commands/ConfigDirectorStreamCommand.php) is the Artisan
command `configdirector:stream`. It creates the streaming process from the same
[`ConfigDirectorSettings`](app/ConfigDirectorSettings.php) as the client: the same server SDK key,
storage path and metadata, so the command and the requests share one local store, and
ConfigDirector shows the streaming connection under the application's name.

```php
public function handle(ConfigDirectorSettings $settings, LogManager $logManager): int
{
    $streamingProcess = ConfigDirector::createStreamingProcess(
        serverSdkKey: $settings->serverSdkKey,
        metadata: $settings->metadata,
        connection: $settings->connection,
        logger: $logManager->channel(ConfigDirectorSettings::LOG_CHANNEL),
    );

    try {
        $streamingProcess->run();
    } catch (ConfigDirectorException) {
        return self::FAILURE;
    }

    return self::SUCCESS;
}
```

`run()` returns when the process receives SIGTERM or SIGINT, after it has sent the telemetry the
local store holds, and the command exits with 0. It throws when ConfigDirector refuses the key, when
another streaming process already runs on the same local store, or when the local store cannot be
used. The SDK has written the error to the log by then, so the command exits with 1 without
reporting it again. In offline mode there is nothing to stream, and the command refuses to start.

In production, run one per host, as the system user that runs PHP-FPM, under a process
manager that restarts it, such as Supervisor or a systemd service. When it stops, requests go back
to polling once the local store is older than the polling interval.

## What else it demonstrates

**Initialization is explicit and non-fatal.** `initialize()` reads the values from the local store.
Only when the store is still empty, as on the first request after a deploy, does it ask
ConfigDirector for them and wait up to `CONFIGDIRECTOR_TIMEOUT` milliseconds. When it gets none, it
returns, and every read in that request returns its in-code default value.

**In-code default values are the fallback.** Every `getValue()` call passes the value to serve when
ConfigDirector cannot provide one, so it should be the safe choice. Its type also decides how the
config value is read: a `bool`, a `string`, an `int`, a `float`, or an array for a JSON config.

**The SDK writes to a log channel of the application.** The client and the streaming process both
log through the `configdirector` channel defined in [`config/logging.php`](config/logging.php),
which writes to standard error, so its lines show in the terminal of `php artisan serve` and of the
command. `CONFIGDIRECTOR_LOG_LEVEL` sets the lowest level it writes, `info` by default: at `info` the
streaming process writes when it starts and stops, and the client writes only warnings and errors.
Set `debug` to see each evaluation. Point the channel at any other Laravel log driver to send the
SDK's lines elsewhere.

**Settings come from the environment.** [`config/configdirector.php`](config/configdirector.php)
reads the key, base URL, connection mode, timeout and storage path from the `CONFIGDIRECTOR_*`
variables, which Laravel also reads from `.env`, and
[`ConfigDirectorSettings`](app/ConfigDirectorSettings.php) turns them into the settings the client
and the streaming process share.
The sample passes only the options the environment names, so every other option keeps the SDK's
default. A mode the SDK does not have, a timeout that is not a whole number, or a value the SDK
refuses, such as a base URL that is not http or https, makes every request to `/configs` answer
`500`, and the server's terminal shows the message, which names the variable or the value.

The SDK also supports watching configs and subscribing to the client's events; see `watch()` and
`on()` in [`ConfigDirectorClient`](../../../packages/server-sdk/src/ConfigDirectorClient.php).

## Testing

[`tests/Feature/ConfigsRouteTest.php`](tests/Feature/ConfigsRouteTest.php) calls the application
through Laravel's HTTP testing helpers, so no web server runs.

The tests read configs through a **test client** from `ConfigDirector\Testing`: the SDK's real
client over an in-memory connection that the test controls. It needs no server SDK key, never
connects to ConfigDirector and creates no local store. Each test puts the test client's client in
the service container, in place of the one the service provider would create:

```php
$testClient = ConfigDirectorTesting::createTestClient(values: ['integer-config' => 42]);
$testClient->client->initialize();
$this->instance(ConfigDirectorClient::class, $testClient->client);

$response = $this->get('/configs');

$testClient->setValue('integer-config', 7);
```

The first response serves 42 for `integer-config`, and a request handled after `setValue()` gets 7.

`failInitialization()` is how the tests cover a client that gets no values: the application serves
the in-code default values it passes in. To see the context each config was evaluated for, and why
it got its value, a test registers a `configEvaluated` handler on the test client's client before
handling the request.

[`tests/Feature/StreamCommandTest.php`](tests/Feature/StreamCommandTest.php) starts
`php artisan configdirector:stream` against a scripted stream server in
[`tests/Support/`](tests/Support/), with a local store in a temporary folder, and checks that a
request reads what the command wrote there, including a change pushed through the stream.

[`tests/Unit/ConfigDirectorSettingsTest.php`](tests/Unit/ConfigDirectorSettingsTest.php) covers how
the configuration becomes the client's settings.

## Running without a server SDK key

A request that finds no values in the local store and cannot get any, because the key is wrong or
ConfigDirector cannot be reached, leaves the client not ready, and every config falls back to the
in-code default value this app passes in. That is the path a production app takes with a wrong
key, so it is worth seeing: the app keeps serving, on the in-code default values you chose.

With `fake-sample-key` ConfigDirector refuses the key. The request that asked logs that once at
error level, and the SDK does not ask again for one hour: the requests that follow return at once
with the in-code default values above. After you put a real key in `.env`, the next request asks
ConfigDirector with that key, because each key has a local store of its own.

`php artisan configdirector:stream` with `fake-sample-key` logs the refusal and exits with 1.
