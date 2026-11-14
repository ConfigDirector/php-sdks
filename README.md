# ConfigDirector PHP SDKs

[![CI][ci-badge]][ci]

PHP packages for [ConfigDirector](https://www.configdirector.com), remote config and feature flags with JSON Schema validation and safe renames of live flags. Start free, no card required.

| Package | Packagist | Description |
|---|---|---|
| [configdirector/server-sdk](packages/server-sdk/) | [![Packagist][sdk-badge]][sdk-packagist] | The PHP server SDK |
| [configdirector/openfeature-server-provider](packages/openfeature-server-provider/) | [![Packagist][provider-badge]][provider-packagist] | [OpenFeature](https://openfeature.dev) server provider, built on the server SDK |

Each package has its own changelog, version, and release. Pick the server SDK to use ConfigDirector directly, or the OpenFeature provider if your application reads flags through the OpenFeature API.

## Streaming process

A PHP request cannot keep a connection open, so by default the server SDK asks ConfigDirector for values once per polling interval per host, and a change to your targeting rules reaches your requests within that interval. To have a change arrive in about a second, run the streaming process on each host:

```sh
CONFIGDIRECTOR_SERVER_KEY=your-server-sdk-key vendor/bin/configdirector-stream
```

It keeps one streaming connection open and writes every change to the local store, the folder on the host where the SDK keeps the values it received and from which your requests read them. It also sends the telemetry your requests collect there. While its connection is open, your requests make no network calls for ConfigDirector. When it is not running, or cannot reach ConfigDirector for longer than your polling interval, they go back to asking by themselves, so nothing depends on it.

| Variable | What to set it to |
|---|---|
| `CONFIGDIRECTOR_SERVER_KEY` | The server SDK key your application uses. Required. |
| `CONFIGDIRECTOR_APP_NAME`, `CONFIGDIRECTOR_APP_VERSION` | The `appName` and `appVersion` of your application's `Metadata`. ConfigDirector shows the connection and the telemetry under them. |
| `CONFIGDIRECTOR_STORAGE_PATH` | The `storagePath` of your application's `ConnectionOptions`, when it sets one. |
| `CONFIGDIRECTOR_BASE_URL` | The URL of your proxy, when your application connects through one. |
| `CONFIGDIRECTOR_LOG_LEVEL` | The lowest PSR-3 level the command writes to standard error. Defaults to `info`. |

Run one streaming process per host, as the system user that runs your application, such as `www-data`. The local store belongs to that user, so a streaming process run as another user keeps a local store that no request reads. Its first log line names the folder it writes to. PHP-FPM under systemd has a `/tmp` of its own, so there give the application and the streaming process the same `storagePath`. A second streaming process on the same local store exits with an error.

The streaming process stops on SIGTERM or SIGINT, after sending the telemetry the local store holds, when the pcntl extension is installed. With [Supervisor](http://supervisord.org):

```ini
[program:configdirector-stream]
command=php /var/www/app/vendor/bin/configdirector-stream
user=www-data
environment=CONFIGDIRECTOR_SERVER_KEY="your-server-sdk-key",CONFIGDIRECTOR_APP_NAME="my-awesome-app"
numprocs=1
autorestart=true
stopsignal=TERM
```

To run it from your own command, such as an Artisan or Symfony Console command, call `ConfigDirector::createStreamingProcess(...)->run()` with the arguments you give `createClient`. The [Laravel sample](samples/server-sdk/laravel/) runs it as `php artisan configdirector:stream`.

## Sample apps

Small, runnable applications live under [`samples/`](samples/), grouped by the package they demonstrate. Start with the [Slim sample](samples/server-sdk/slim/) for the server SDK, or the [Laravel sample](samples/server-sdk/laravel/), which also runs the streaming process as an Artisan command.

## Getting Help

- [Ask a question in Discussions](https://github.com/orgs/ConfigDirector/discussions)
- [Contact support](https://www.configdirector.com/support)

[//]: # "links"
[ci-badge]: https://github.com/ConfigDirector/php-sdks/actions/workflows/ci.yml/badge.svg
[ci]: https://github.com/ConfigDirector/php-sdks/actions/workflows/ci.yml
[sdk-badge]: https://img.shields.io/packagist/v/configdirector/server-sdk
[sdk-packagist]: https://packagist.org/packages/configdirector/server-sdk
[provider-badge]: https://img.shields.io/packagist/v/configdirector/openfeature-server-provider
[provider-packagist]: https://packagist.org/packages/configdirector/openfeature-server-provider
