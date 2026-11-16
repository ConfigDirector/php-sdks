# Sample apps

Small, self-contained applications showing how to use the ConfigDirector PHP packages with
different web frameworks, grouped by the package they demonstrate.

## [server-sdk](server-sdk/)

| Sample | Framework | Description |
|---|---|---|
| [slim/](server-sdk/slim/) | [Slim](https://www.slimframework.com) | Minimal PSR-7 app: one client per request from the container, per-request evaluation |
| [laravel/](server-sdk/laravel/) | [Laravel](https://laravel.com) | A client per request from a scoped binding, and the streaming process as an Artisan command |

## [openfeature-server-provider](openfeature-server-provider/)

| Sample | Framework | Description |
|---|---|---|
| [slim/](openfeature-server-provider/slim/) | [Slim](https://www.slimframework.com) | Minimal PSR-7 app: the provider set on the OpenFeature API once per request, per-request evaluation context |

Each sample is an independent Composer project with its own `composer.json` and `composer.lock`,
and requires a released version of the package it shows from Packagist, the way your application
does. `make samples` runs PHPStan and the tests of every sample against that release, and
`make samples-local` against the packages as they are in your checkout.

To run one:

```bash
cd samples/server-sdk/slim
composer install
php -S 127.0.0.1:3700 -t public
```

> The samples need a real server SDK key to resolve configs. Without one the client or the
> provider has no values, and every config falls back to the in-code default value the sample
> passes in, which is also what a production app sees when it has no values from ConfigDirector.
