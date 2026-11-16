# ConfigDirector OpenFeature PHP Provider

[![CI][ci-badge]][ci] [![Packagist][packagist-badge]][packagist]

[OpenFeature](https://openfeature.dev) PHP provider for [ConfigDirector](https://www.configdirector.com), remote config and feature flags with JSON Schema validation and safe renames of live flags. Start free, no card required. It wraps the [ConfigDirector PHP server SDK](https://packagist.org/packages/configdirector/server-sdk).

## Install

```sh
composer require configdirector/openfeature-server-provider
```

The OpenFeature PHP SDK (`open-feature/sdk`) and the ConfigDirector PHP server SDK come with it.
Like the SDK, the provider needs a 64-bit PHP 8.3 or later with the curl and JSON extensions.

## Retrieve a value

```php
use ConfigDirector\Metadata;
use ConfigDirector\OpenFeature\ConfigDirectorProvider;
use OpenFeature\OpenFeatureAPI;

// The server SDK key is a secret. Do not commit it to your source code.
$api = OpenFeatureAPI::getInstance();
$api->setProvider(new ConfigDirectorProvider(
    serverSdkKey: 'YOUR-SERVER-SDK-KEY',
    metadata: new Metadata(appName: 'my-awesome-app', appVersion: '1.0.0'),
));
$client = $api->getClient();

$newCheckout = $client->getBooleanValue('new-checkout', false);
```

Creating the provider reads the values ConfigDirector serves, the way the SDK's `initialize()`
does. A PHP request cannot keep a connection open, so the SDK keeps those values in a **local
store**, a folder on the host that its PHP processes share, and one request per polling interval
asks ConfigDirector for new ones, after your own code has run. Only when the local store is still
empty, as on the first request of a new host or container, does the provider ask ConfigDirector
itself and wait up to the connection timeout, 3 seconds by default.

If no values arrive in time, or ConfigDirector rejects the server SDK key, flags resolve to their
in-code default values with the error code `PROVIDER_NOT_READY` and the SDK logs why. The
constructor does not throw for that, so your application keeps serving.

The provider takes the named arguments of the SDK's `ConfigDirector::createClient`: `serverSdkKey`,
`metadata`, `connection`, `logger`, `logLevel`, `telemetry` and `hooks`. The
[server SDK's README](https://github.com/ConfigDirector/php-sdks/tree/main/packages/server-sdk#configure)
says what each one sets. The constructor throws `ConfigDirectorValidationException` for a setting it
cannot use, such as a missing key. OpenFeature's `setLogger()` changes nothing here: give your
logger as `logger`.

## Evaluation context

The OpenFeature evaluation context becomes the ConfigDirector context that targeting rules read:

| OpenFeature | ConfigDirector |
|---|---|
| The targeting key, or failing that an `id` attribute | `id` |
| `kind` attribute | `kind` |
| `name` attribute | `name` |
| `traits` attribute, an array | `traits` |
| `anonymous` attribute, a boolean | `anonymous` |

```php
use OpenFeature\implementation\flags\Attributes;
use OpenFeature\implementation\flags\EvaluationContext;

$user = new EvaluationContext('user-123', new Attributes(['name' => 'Ada', 'traits' => ['plan' => 'pro']]));
$newCheckout = $client->getBooleanValue('new-checkout', false, $user);

$device = new EvaluationContext('kiosk-7', new Attributes(['kind' => 'device']));
$newCheckout = $client->getBooleanValue('new-checkout', false, $device);
```

Other attributes are not used, so put the traits your targeting rules read inside `traits`. An
`id` or a `name` that is a number, a boolean or a date is read as text. A date is written in
ISO 8601, inside `traits` too, so that date conditions can compare it.

Without a `kind` attribute the context is a `user`, and a `kind` inside `traits` is an ordinary
trait. A kind has 1 to 64 characters, using only lowercase letters, digits, `-` and `_`. When the
kind is invalid, the whole context is ignored: the flag still resolves, to the value for no
context, and the SDK logs an error. A `kind` that is not a string is ignored the same way, without
that error.

A context with neither a targeting key nor an `id` attribute skips percentage rollouts: the
targeting rules after the rollout are tried, and the flag resolves to the default targeting rule
value when none applies. Give an anonymous context a stable targeting key, such as a session ID,
to keep it in a rollout.

## Evaluation details

| Outcome | Reason | Error code |
|---|---|---|
| A value was served | `TARGETING_MATCH`, with the variation's name as the variant when it has one, otherwise the value's ID | |
| A percentage rollout was skipped because the context has no targeting key or `id` | `DEFAULT`, with the variant of the default targeting rule value | |
| The config has no value | `DEFAULT` | |
| No config has that key | `ERROR` | `FLAG_NOT_FOUND` |
| The value cannot be read as the requested type | `ERROR` | `TYPE_MISMATCH` |
| The provider has no values yet | `ERROR` | `PROVIDER_NOT_READY` |

For the three outcomes with an error code, the OpenFeature PHP SDK runs your hooks' `after` stage
and not their `error` stage, as it does for any provider that reports an error in the evaluation
details.

## What the provider leaves out

The OpenFeature PHP SDK has no provider events, no tracking, no step that initializes a provider
and no shutdown yet. So, unlike the other ConfigDirector OpenFeature providers, this one:

- **Emits no events**, such as `PROVIDER_READY` or `PROVIDER_CONFIGURATION_CHANGED`. In a
  long-running process, the `configsUpdated` handler of the `hooks` argument tells you when values
  change.
- **Has no `track()`.** To report an event, such as a completed checkout, create a client with
  `ConfigDirector::createClient(...)` and the same server SDK key, and call its `track()`.
- **Does not fail when no values arrive in time.** Elsewhere OpenFeature then reports an error
  status; here flags resolve with `PROVIDER_NOT_READY`, as described above.
- **Is not shut down.** Its client disposes of itself when the script ends, or as soon as nothing
  refers to the provider any longer.

## Test your code

The OpenFeature PHP SDK ships no in-memory provider. In a test, set a provider in offline mode with
the values the test needs. It needs no server SDK key, never connects to ConfigDirector and sends
nothing:

```php
use ConfigDirector\ConnectionMode;
use ConfigDirector\ConnectionOptions;
use ConfigDirector\OpenFeature\ConfigDirectorProvider;
use OpenFeature\OpenFeatureAPI;

$api = OpenFeatureAPI::getInstance();
$api->setProvider(new ConfigDirectorProvider(
    connection: new ConnectionOptions(mode: ConnectionMode::Offline, values: ['new-checkout' => true]),
));

self::assertTrue($api->getClient()->getBooleanValue('new-checkout', false));
```

To change a value during a test, set a new provider with the new values.

## Documentation

Refer to the [official documentation for the OpenFeature PHP provider](https://docs.configdirector.com/sdks/openfeature/php).

There is also [a quickstart guide for ConfigDirector and any of our SDKs](https://docs.configdirector.com/getting-started/quickstart).

## Contributing

This package is developed in [ConfigDirector/php-sdks](https://github.com/ConfigDirector/php-sdks),
under `packages/openfeature-server-provider`.
[ConfigDirector/php-openfeature-server-provider](https://github.com/ConfigDirector/php-openfeature-server-provider)
is a read-only copy of that directory, which Packagist installs from, so please open issues and
pull requests in php-sdks.

## Getting Help

- [Ask a question in Discussions](https://github.com/orgs/ConfigDirector/discussions)
- [Contact support](https://www.configdirector.com/support)

[//]: # "links"
[ci-badge]: https://github.com/ConfigDirector/php-sdks/actions/workflows/ci.yml/badge.svg
[ci]: https://github.com/ConfigDirector/php-sdks/actions/workflows/ci.yml
[packagist-badge]: https://img.shields.io/packagist/v/configdirector/openfeature-server-provider
[packagist]: https://packagist.org/packages/configdirector/openfeature-server-provider
