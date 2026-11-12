# ConfigDirector PHP SDKs

[![CI][ci-badge]][ci]

PHP packages for [ConfigDirector](https://www.configdirector.com), remote config and feature flags with JSON Schema validation and safe renames of live flags. Start free, no card required.

| Package | Packagist | Description |
|---|---|---|
| [configdirector/server-sdk](packages/server-sdk/) | [![Packagist][sdk-badge]][sdk-packagist] | The PHP server SDK |
| [configdirector/openfeature-server-provider](packages/openfeature-server-provider/) | [![Packagist][provider-badge]][provider-packagist] | [OpenFeature](https://openfeature.dev) server provider, built on the server SDK |

Each package has its own changelog, version, and release. Pick the server SDK to use ConfigDirector directly, or the OpenFeature provider if your application reads flags through the OpenFeature API.

## Sample apps

Small, runnable applications will live under `samples/`, grouped by the package they demonstrate.

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
