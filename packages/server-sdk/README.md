# ConfigDirector PHP Server SDK

[![CI][ci-badge]][ci] [![Packagist][packagist-badge]][packagist]

PHP server SDK for [ConfigDirector](https://www.configdirector.com), remote config and feature flags with JSON Schema validation and safe renames of live flags. Start free, no card required.

## Install

```sh
composer require configdirector/server-sdk
```

The SDK needs a 64-bit PHP 8.3 or later with the curl and JSON extensions.

## Configure

```php
use ConfigDirector\ConfigDirector;
use ConfigDirector\Metadata;

// The server SDK key is a secret. Do not commit it to your source code.
$client = ConfigDirector::createClient(
    serverSdkKey: 'YOUR-SERVER-SDK-KEY',
    metadata: new Metadata(appName: 'my-awesome-app', appVersion: '1.0.0'),
);
$client->initialize();
```

Create one client per request, for example in your framework's container, and read through it as
often as you need. Creating a client makes no network call. A PHP request cannot keep a connection
open, so the SDK keeps the values ConfigDirector serves in a **local store**, a folder on the host
that its PHP processes share. `initialize()` reads the values from there, and one request per
polling interval asks ConfigDirector for new ones, after your own code has run. Only when the local
store is still empty, as on the first request of a new host or container, does `initialize()` ask
ConfigDirector itself and wait up to the connection timeout.

`createClient` takes named arguments, all optional. Only an offline client can leave out the key:

| Argument | What it sets |
| --- | --- |
| `serverSdkKey` | The server SDK key of your environment. |
| `metadata` | `new Metadata(appName:, appVersion:)`, which you can use in targeting rules. |
| `connection` | `new ConnectionOptions(pollingInterval:, timeout:, url:, storagePath:)`: how often a host asks for new values, in seconds (300 by default, 60 at least); how long `initialize()` waits on an empty local store, in milliseconds (3,000 by default); the URL of your proxy; and the folder the local store is kept in. |
| `logger` | A PSR-3 logger, such as a Monolog logger. Without one the SDK writes warnings and errors through `error_log`. |
| `logLevel` | The lowest level written when no `logger` is given, as a PSR-3 level such as `Psr\Log\LogLevel::DEBUG`. |
| `telemetry` | `new TelemetryOptions(eventQueueLimit:, flushInterval:)`. These rarely need changing. |
| `hooks` | `new ClientHooks(clientReady:, configsUpdated:, configEvaluated:, connectionError:)`: handlers for the client's events, registered before any event can fire. |

`createClient` throws `ConfigDirectorValidationException` for a setting it cannot use, such as a
missing key. Every exception the SDK throws extends `ConfigDirectorException`.

## Retrieve a value

```php
$newCheckout = $client->getValue('new-checkout', false);
```

The second argument is the **in-code default value**: what the read returns when ConfigDirector has
no value to serve, for example before the client has values or when no config has that key. Its
type decides the type of the value returned, and static analysis sees it too: a `bool`, a `string`,
an `int`, a `float`, or an array for a JSON config.

`evaluate()` takes the same arguments and returns the value with the reason it was served:

```php
$evaluation = $client->evaluate('checkout-button-color', 'blue');

$evaluation->value;          // 'green'
$evaluation->reason;         // EvaluationReason::FoundMatch
$evaluation->isDefaultValue; // false
$evaluation->variation?->name;
```

`getAllConfigs()` returns what every config serves, keyed by config key, as text and without
recording an evaluation.

## Evaluation context

Pass a `Context` to say who or what a config is evaluated for, so that your targeting rules apply:

```php
use ConfigDirector\Context;

$context = new Context(
    id: 'user-123',
    name: 'Ada Lovelace',
    traits: ['plan' => 'pro', 'region' => 'eu'],
);

$newCheckout = $client->getValue('new-checkout', false, $context);
```

The `id` places the context in a share of a percentage rollout, so it should identify the user
among contexts of the same kind. `kind` names what the context is, such as `organization` or
`device`, and is `user` when left out. A context created with `anonymous: true` is used for
targeting but is not saved and does not appear in the dashboard.

## Watch

```php
$stopWatching = $client->watch(
    'max-items',
    20,
    function (int $maxItems): void {
        // Called with the config's value when the client first gets it, and again each time the config changes.
    },
    $context,
);

$client->initialize();

$stopWatching();
```

A client receives values during `initialize()`, so register a watch before it. A long-lived client,
such as one in a queue worker, also receives the values that changed in the local store while it
reads. A web request keeps the values it initialized with until it ends.

`on()` registers a handler for one of the client's events: `clientReady`, `configsUpdated`,
`configEvaluated` and `connectionError`.

```php
use ConfigDirector\ConfigEvaluatedEvent;

$client->on('configEvaluated', function (ConfigEvaluatedEvent $event): void {
    // $event->evaluation is what evaluate() returns.
});
```

## Track events

```php
$client->track('checkout-completed', $context, value: 49.9, details: ['plan' => 'pro']);
```

`track()` reports that something happened, so ConfigDirector can count it, for example as the
metric of an experiment. An event counts for an experiment when it is tracked for a context with
the `id` the experiment's config was evaluated for. Events are not sent one by one: the SDK collects
them on the host and sends them together when a request ends, every 30 seconds by default. `track()`
never throws.

## Run without a connection

In offline mode the client never connects to ConfigDirector and sends nothing. It needs no server
SDK key, and it serves the values you give it to every context:

```php
use ConfigDirector\ConfigDirector;
use ConfigDirector\ConnectionMode;
use ConfigDirector\ConnectionOptions;

$client = ConfigDirector::createClient(
    connection: new ConnectionOptions(
        mode: ConnectionMode::Offline,
        values: ['new-checkout' => true, 'max-items' => 20],
    ),
);
$client->initialize();
```

Give `file: __DIR__ . '/flags.json'` in place of `values` to read them from a JSON file with one
value per config key. Without either, every read returns its in-code default value.

## Test your code

`ConfigDirector\Testing` creates a **test client**: the SDK's real client over an in-memory
connection that your test controls, with no server SDK key, no network connection, no local store
and no telemetry. Seed values, change them mid-test so watches and `configsUpdated` handlers fire,
and hold or fail initialization to test how your code behaves without values. It ships in the
package, so there is nothing else to install.

```php
use ConfigDirector\Testing\ConfigDirectorTesting;

$testClient = ConfigDirectorTesting::createTestClient(values: ['new-checkout' => true]);
$service = new CheckoutService($testClient->client);
$testClient->client->initialize();

self::assertTrue($service->isNewCheckoutEnabled('user-123'));

$testClient->setValue('new-checkout', false);
self::assertFalse($service->isNewCheckoutEnabled('user-123'));
```

`$testClient->client` is a `ConfigDirectorClient`, so it goes anywhere your code accepts one. It
starts not initialized, like a production client, because the code under test usually owns the
call to `initialize()`.

### Values

`createTestClient`, `setValue` and `replaceValues` take PHP values, and the config type follows
from each value: a `bool` is a boolean config, an `int` an integer config, a `float` a float
config, a `string` a string config and an array a JSON config. Every context receives the same
value. `null`, a float that is not finite, an object other than `stdClass` or a blank key throw
`ConfigDirectorValidationException`, and nothing changes.

Reads behave as they do in production: a value set as `true` and read with a string in-code default
value returns that in-code default value with the reason `type-mismatch`.

### Controls

| Control | Effect |
| --- | --- |
| `setValue($key, $value)` | Stores the value and, once the client has initialized, delivers it. Watches on the key, `configsUpdated` handlers and reads see it before the call returns. A value equal to the one the key has fires nothing. |
| `removeValue($key)` | Removes the value. Reads return their in-code default value with the reason `config-state-missing`, watches on the key receive it, and `configsUpdated` handlers find the key in `removedKeys`. |
| `replaceValues($values)` | Replaces every stored value, and cancels a `holdInitialization()` or `failInitialization()` that is still waiting for its `initialize()`. Use it to reset a test client shared across tests. |
| `holdInitialization()` | The next `initialize()` waits for the client's timeout and returns without values, as a production client does when ConfigDirector does not answer in time. |
| `completeInitialization()` | Delivers the stored values to an initialization that was held, so the client is ready when the call returns. |
| `failInitialization()` | The next `initialize()` fails as a refused server SDK key does: it returns at once without values, logs an error, and `connectionError` handlers run. |

### Tracked events

`$testClient->trackedEvents()` returns every event the code under test tracked, in call order, as
`TrackedEvent` objects with `name`, `context`, `value` and `details`:

```php
$testClient->client->track('checkout-completed', new Context(id: 'user-123'), value: 49.9);

$trackedEvent = $testClient->trackedEvents()[0];

self::assertSame('checkout-completed', $trackedEvent->name);
self::assertSame(49.9, $trackedEvent->value);
```

The record follows the rules of a real report: an event whose name does not have 1 to 150
characters is not recorded, and a value or details the SDK would leave out are `null`.
`clearTrackedEvents()` forgets the record.

### Options

`createTestClient(values:, timeout:, logger:)` takes `timeout`, in milliseconds, which is how long
a held `initialize()` waits (3,000 by default), and `logger`, a PSR-3 logger.

## The streaming process

By default a change to your targeting rules reaches your requests within one polling interval. To
have it arrive in about a second, run the streaming process on each host:

```sh
CONFIGDIRECTOR_SERVER_KEY=your-server-sdk-key vendor/bin/configdirector-stream
```

It keeps one streaming connection open, writes every change to the local store your requests read,
and sends the telemetry they collect there. While it runs, your requests make no network calls for
ConfigDirector. When it stops, they go back to asking by themselves, so nothing depends on it.

| Variable | What to set it to |
| --- | --- |
| `CONFIGDIRECTOR_SERVER_KEY` | The server SDK key your application uses. Required. |
| `CONFIGDIRECTOR_APP_NAME`, `CONFIGDIRECTOR_APP_VERSION` | The `appName` and `appVersion` of your application's `Metadata`. |
| `CONFIGDIRECTOR_STORAGE_PATH` | The `storagePath` of your application's `ConnectionOptions`, when it sets one. |
| `CONFIGDIRECTOR_BASE_URL` | The URL of your proxy, when your application connects through one. |
| `CONFIGDIRECTOR_LOG_LEVEL` | The lowest PSR-3 level the command writes to standard error. Defaults to `info`. |

Run one streaming process per host, as the system user that runs your application, such as
`www-data`: the local store belongs to that user. It stops on SIGTERM or SIGINT, after sending the
telemetry the local store holds, when the pcntl extension is installed. To run it from your own
command, such as an Artisan or Symfony Console command, call
`ConfigDirector::createStreamingProcess(...)->run()` with the arguments you give `createClient`, apart
from `hooks`, which it does not take.

## Documentation

Refer to the [official documentation for the PHP SDK](https://docs.configdirector.com/sdks/server/php).

There is also [a quickstart guide for ConfigDirector and any of our SDKs](https://docs.configdirector.com/getting-started/quickstart).

## Sample apps

[`samples/server-sdk/`](https://github.com/ConfigDirector/php-sdks/tree/main/samples/server-sdk)
holds small, runnable applications built on this SDK, one per web framework. Start with
[`slim`](https://github.com/ConfigDirector/php-sdks/tree/main/samples/server-sdk/slim), or see
[`laravel`](https://github.com/ConfigDirector/php-sdks/tree/main/samples/server-sdk/laravel), which
also runs the streaming process as an Artisan command.

## Contributing

This package is developed in [ConfigDirector/php-sdks](https://github.com/ConfigDirector/php-sdks),
under `packages/server-sdk`. [ConfigDirector/php-server-sdk](https://github.com/ConfigDirector/php-server-sdk)
is a read-only copy of that directory, which Packagist installs from, so please open issues and
pull requests in php-sdks.

## Getting Help

- [Ask a question in Discussions](https://github.com/orgs/ConfigDirector/discussions)
- [Contact support](https://www.configdirector.com/support)

[//]: # "links"
[ci-badge]: https://github.com/ConfigDirector/php-sdks/actions/workflows/ci.yml/badge.svg
[ci]: https://github.com/ConfigDirector/php-sdks/actions/workflows/ci.yml
[packagist-badge]: https://img.shields.io/packagist/v/configdirector/server-sdk
[packagist]: https://packagist.org/packages/configdirector/server-sdk
