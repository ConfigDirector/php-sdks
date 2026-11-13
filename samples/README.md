# Sample apps

Small, self-contained applications showing how to use the ConfigDirector PHP packages with
different web frameworks, grouped by the package they demonstrate.

## [server-sdk](server-sdk/)

| Sample | Framework | Description |
|---|---|---|
| [slim/](server-sdk/slim/) | [Slim](https://www.slimframework.com) | Minimal PSR-7 app: one client per request from the container, per-request evaluation |

Each sample is an independent Composer project with its own `composer.json` and `composer.lock`.
Until the SDK's first release, a sample requires it from this working tree through a Composer path
repository, so it runs the SDK as it is in your checkout. `make samples-local` runs PHPStan and the
tests of every sample that way.

To run one:

```bash
cd samples/server-sdk/slim
composer install
php -S 127.0.0.1:3700 -t public
```

> The SDK does not connect to ConfigDirector yet, so every sample evaluates the same sample configs
> built into the SDK, whatever key it is given. Once it connects, the samples will need a real
> server SDK key to resolve configs. Without one the client will stay not ready and every config
> will fall back to the in-code default value the sample passes in, which is also what a production
> app sees when it has no values from ConfigDirector.
