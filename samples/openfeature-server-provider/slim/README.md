# Slim sample

A minimal [Slim](https://www.slimframework.com) app reading ConfigDirector configs through
[OpenFeature](https://openfeature.dev), using the ConfigDirector OpenFeature PHP provider. It is the
OpenFeature counterpart of the [server SDK's Slim sample](../../server-sdk/slim/): the same `/configs`
route, evaluating the same five configs for the context a request names and returning them as JSON.

## Running it

You need Composer 2 and PHP 8.3.36, 8.4.27, 8.5.12 or a later release on the same line, the first
releases with the fixes for CVE-2026-109618 and CVE-2026-109619.

```bash
cd samples/openfeature-server-provider/slim
composer install
cp .env.example .env      # optional, the sample runs without a real key
php -S 127.0.0.1:3720 -t public
```

Then:

```bash
curl 'http://127.0.0.1:3720/configs?id=user-123&plan=pro'
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

Query parameters double as the evaluation context: `id` becomes the targeting key, `kind`, `name`
and `anonymous` become the attributes of the same name, and anything else becomes a trait in the
`traits` attribute:

```
/configs?id=user-123&name=Ada&plan=pro&region=eu
```

Any other route answers `404` with a hint to `GET /configs`. Stop the server with Ctrl-C.

Run the tests with `vendor/bin/phpunit`.

## One provider per request

This is the most important thing the sample shows. [`SampleApplication.php`](src/SampleApplication.php)
registers the provider and the OpenFeature client in the application's container. The first time a
request asks the container for the client, the container creates the provider, sets it on the
OpenFeature API and returns the API's client; everything else in that request that asks gets the
same client.

```php
$container = new Container([
    Provider::class => fn(): Provider => self::configDirectorProvider($settings),
    Client::class => fn(Provider $provider): Client => self::clientAfterSettingTheProvider($provider),
]);
```

```php
private static function clientAfterSettingTheProvider(Provider $provider): Client
{
    $api = OpenFeatureAPI::getInstance();
    $api->setProvider($provider);

    return $api->getClient(self::APP_NAME, self::APP_VERSION);
}
```

[`ConfigsAction`](src/ConfigsAction.php) receives that client in its constructor and reads all five
configs through it.

In Go or Python an application sets the provider once when the process starts and shares it until
the process ends. A PHP request starts with empty memory: PHP-FPM, Apache's PHP module and the
built-in server above run the application again for each request and throw away everything it
created when the request ends, the provider and the OpenFeature API included. Setting the provider
once per request is therefore the most a PHP application can share, and the SDK keeps what has to
outlive a request outside the provider: it keeps the values ConfigDirector serves in a local store
on disk, shared by the PHP processes of one host, and only one request per polling interval asks
ConfigDirector for new ones, after its response is built. Creating a provider per request costs a
read of that store, not a connection to ConfigDirector.

Within a request, set the provider once and read through the client as often as you need. Never
create a provider per read: each new provider reads the values again before it serves them.

## What else it demonstrates

**Creating the provider reads the values, and never fails for want of them.** The provider reads the
values from the local store when it is created. Only when the store is still empty, as on the first
request after a deploy, does it ask ConfigDirector for them and wait up to `CONFIGDIRECTOR_TIMEOUT`
milliseconds. When it gets none, it is created anyway, and every read in that request returns its
in-code default value.

**In-code default values are the fallback.** Every read passes the value to serve when
ConfigDirector cannot provide one, so it should be the safe choice. The method decides the type the
config value is read as: `getBooleanValue`, `getIntegerValue`, `getFloatValue`, `getStringValue`,
and `getObjectValue` for a JSON config.

**The evaluation context is per request; so is the provider.** `evaluationContextFrom()` in
[`ConfigsAction.php`](src/ConfigsAction.php) maps query parameters onto an OpenFeature
`EvaluationContext`. A real application would build it from the signed-in user.

**Logging goes through `error_log`.** The SDK under the provider writes warnings and errors through
PHP's `error_log`, which the built-in server prints in its terminal. `CONFIGDIRECTOR_LOG_LEVEL` sets
the lowest level it writes, as a PSR-3 level name such as `debug`. To send those lines to your
application's own log, pass a PSR-3 logger, such as a Monolog logger, as `logger:` to the provider.
OpenFeature's `setLogger()` does not reach the provider.

**Settings come from the environment.** [`ConfigDirectorSettings.php`](src/ConfigDirectorSettings.php)
reads the key, connection mode, timeout, base URL and log level from the `CONFIGDIRECTOR_*`
variables, the same ones the server SDK's sample reads, and [`public/index.php`](public/index.php)
adds the ones in a `.env` file, without overriding a variable already set in the environment. The
sample passes only the options the environment names, so every other option keeps the SDK's
default. A mode the SDK does not have, or a timeout that is not a whole number, stops every request
with a message that names the variable. A value the SDK refuses, such as a base URL that is not http
or https or a log level that is not a PSR-3 level, makes every request to `/configs` answer `500`,
and the server's terminal shows the SDK's message.

The OpenFeature PHP SDK has no provider events and no tracking yet, so this sample has neither. The
[provider's README](../../../packages/openfeature-server-provider/README.md#what-the-provider-leaves-out)
says what to use instead.

## Testing

[`tests/SampleApplicationTest.php`](tests/SampleApplicationTest.php) calls the application in process:
it creates the Slim app with `SampleApplication::create()`, builds each request with Slim's PSR-7
factory and passes it to `$app->handle()`, so no web server runs.

The OpenFeature PHP SDK ships no in-memory provider, so the tests read configs through the
ConfigDirector provider in **offline mode**, with the values each test needs. It needs no server SDK
key, never connects to ConfigDirector and creates no local store. Each test puts that provider in
the application's container, in place of the one the application would create, and the application
sets it on the OpenFeature API as it does its own:

```php
$provider = new ConfigDirectorProvider(
    connection: new ConnectionOptions(mode: ConnectionMode::Offline, values: ['integer-config' => 42]),
);

$app = SampleApplication::create(ConfigDirectorSettings::fromEnvironment([]));
$app->getContainer()->set(Provider::class, $provider);

$response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/configs'));
```

The response serves 42 for `integer-config`, and the in-code default value of each config the
provider has no value for. A provider in offline mode with no values is how the tests cover configs
that are not served at all: the application serves the in-code default values it passes in. To
serve other values, a test creates another provider.

The OpenFeature API is one per PHP process, and each application in the tests sets its own provider
on the first request it handles, so a test never reads through the provider of the test before it.

To see the context each config was evaluated for, a test gives the provider a `configEvaluated`
handler through `hooks:`, which receives the ConfigDirector context the evaluation context became.

The test of the debug log level uses the provider the application creates, in offline mode, which
never connects either.

[`tests/ConfigDirectorSettingsTest.php`](tests/ConfigDirectorSettingsTest.php) covers how the
environment becomes the provider's settings.

## Running without a server SDK key

A request that finds no values in the local store and cannot get any, because the key is wrong or
ConfigDirector cannot be reached, creates a provider without values, and every config falls back to
the in-code default value this app passes in. That is the path a production app takes with a wrong
key, so it is worth seeing: the app keeps serving, on the in-code default values you chose.

With `fake-sample-key` ConfigDirector refuses the key. The request that asked logs that once at
error level, and the SDK does not ask again for one hour: the requests that follow return at once
with the in-code default values above. After you put a real key in `.env`, the next request asks
ConfigDirector with that key, because each key has a local store of its own.
