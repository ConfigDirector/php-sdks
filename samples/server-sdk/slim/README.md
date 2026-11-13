# Slim sample

A minimal [Slim](https://www.slimframework.com) app using the ConfigDirector PHP server SDK. It
mirrors the Flask sample in the Python SDK and the Gin sample in the Go SDKs: a single `/configs`
route that evaluates a handful of configs and returns them as JSON.

## Running it

You need Composer 2 and PHP 8.3.36, 8.4.27, 8.5.12 or a later release on the same line, the first
releases with the fixes for CVE-2026-109618 and CVE-2026-109619.

```bash
cd samples/server-sdk/slim
composer install
cp .env.example .env      # optional, the sample runs without a real key
php -S 127.0.0.1:3700 -t public
```

Then:

```bash
curl 'http://127.0.0.1:3700/configs?id=user-123&plan=pro'
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

Query parameters double as the context the configs are evaluated for: `id`, `kind`, `name` and
`anonymous` map to the matching `Context` fields, and anything else becomes a trait:

```
/configs?id=user-123&name=Ada&plan=pro&region=eu
```

Any other route answers `404` with a hint to `GET /configs`. Stop the server with Ctrl-C.

Run the tests with `vendor/bin/phpunit`.

## One client per request

This is the most important thing the sample shows. [`SampleApplication.php`](src/SampleApplication.php)
registers the client in the application's container. The first time a request asks the container
for it, the container creates it and calls `initialize()`; everything else in that request that
asks gets the same client.

```php
$container = new Container([
    ConfigDirectorClient::class => fn(): ConfigDirectorClient => self::initializedClient($settings),
]);
```

[`ConfigsAction`](src/ConfigsAction.php) receives that client in its constructor and reads all five
configs through it.

In Go or Python an application creates one client for the process and shares it until the process
ends. A PHP request starts with empty memory: PHP-FPM, Apache's PHP module and the built-in
server above run the application again for each request and throw away everything it created when
the request ends. One client per request is therefore the most a PHP application can share, and the
SDK keeps what has to outlive a request outside the client: it keeps the values ConfigDirector
serves in a local store on disk, shared by the PHP processes of one host, and only one request per
polling interval asks ConfigDirector for new ones, after its response is built. A client per
request costs a read of that store, not a connection to ConfigDirector.

Within a request, create the client once and read through it as often as you need. Never create one
per read: each new client has to be initialized again before it serves values, and the handlers and
watches registered on one client never see another's reads.

## What else it demonstrates

**Initialization is explicit and non-fatal.** `initialize()` reads the values from the local store.
Only when the store is still empty, as on the first request after a deploy, does it ask
ConfigDirector for them and wait up to `CONFIGDIRECTOR_TIMEOUT` milliseconds. When it gets none, it
returns, and every read in that request returns its in-code default value. Creating the client
makes no network call and touches no file.

**In-code default values are the fallback.** Every `getValue()` call passes the value to serve when
ConfigDirector cannot provide one, so it should be the safe choice. Its type also decides how the
config value is read: a `bool`, a `string`, an `int`, a `float`, or an array for a JSON config.

**The context is per request; so is the client.** `contextFrom()` in
[`ConfigsAction.php`](src/ConfigsAction.php) maps query parameters onto a `Context`. A real
application would build it from the signed-in user.

**Logging goes through `error_log`.** The SDK writes warnings and errors through PHP's `error_log`,
which the built-in server prints in its terminal. `CONFIGDIRECTOR_LOG_LEVEL` sets the lowest level it
writes, as a PSR-3 level name such as `debug`. To send the SDK's lines to your application's own log,
pass a PSR-3 logger, such as a Monolog logger, as `logger:` to `ConfigDirector::createClient()`.

**Settings come from the environment.** [`ConfigDirectorSettings.php`](src/ConfigDirectorSettings.php)
reads the key, connection mode, timeout, base URL and log level from the `CONFIGDIRECTOR_*` variables,
and [`public/index.php`](public/index.php) adds the ones in a `.env` file, without overriding a
variable already set in the environment. The sample passes only the options the environment names,
so every other option keeps the SDK's default. A mode the SDK does not have, or a timeout that is not
a whole number, stops every request with a message that names the variable. A value the SDK refuses,
such as a base URL that is not http or https or a log level that is not a PSR-3 level, makes every
request to `/configs` answer `500`, and the server's terminal shows the SDK's message.

The SDK also supports watching configs and subscribing to the client's events; see `watch()` and
`on()` in [`ConfigDirectorClient`](../../../packages/server-sdk/src/ConfigDirectorClient.php).

## Testing

[`tests/SampleApplicationTest.php`](tests/SampleApplicationTest.php) calls the application in process:
it creates the Slim app with `SampleApplication::create()`, builds each request with Slim's PSR-7
factory and passes it to `$app->handle()`, so no web server runs.

The tests read configs through a **test client** from `ConfigDirector\Testing`: the SDK's real
client over an in-memory connection that the test controls. It needs no server SDK key, never
connects to ConfigDirector and creates no local store. Each test puts the test client's client in
the application's container, in place of the one the application would create:

```php
$testClient = ConfigDirectorTesting::createTestClient(values: ['integer-config' => 42]);
$testClient->client->initialize();

$app = SampleApplication::create(ConfigDirectorSettings::fromEnvironment([]));
$app->getContainer()->set(ConfigDirectorClient::class, $testClient->client);

$response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/configs'));

$testClient->setValue('integer-config', 7);
```

The first response serves 42 for `integer-config`, and a request handled after `setValue()` gets 7.

`failInitialization()` is how the tests cover a client that gets no values: the application serves
the in-code default values it passes in.

To see the context each config was evaluated for, and why it got its value, a test registers a
`configEvaluated` handler on the test client's client before handling the request.

The test of the debug log level uses the client the application creates, in offline mode, which
never connects either.

[`tests/ConfigDirectorSettingsTest.php`](tests/ConfigDirectorSettingsTest.php) covers how the
environment becomes the client's settings.

## Running without a server SDK key

A request that finds no values in the local store and cannot get any, because the key is wrong or
ConfigDirector cannot be reached, leaves the client not ready, and every config falls back to the
in-code default value this app passes in. That is the path a production app takes with a wrong
key, so it is worth seeing: the app keeps serving, on the in-code default values you chose.

With `fake-sample-key` ConfigDirector refuses the key. The request that asked logs that once at
error level, and the SDK does not ask again for one hour: the requests that follow return at once
with the in-code default values above. After you put a real key in `.env`, the next request asks
ConfigDirector with that key, because each key has a local store of its own.
