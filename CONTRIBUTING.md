# Contributing

## Layout

This repository publishes two Composer packages, each from a directory under `packages/`:

- [server-sdk](packages/server-sdk/), the server SDK: package `configdirector/server-sdk`, namespace
  `ConfigDirector\`. At runtime it depends on the curl and JSON extensions and the PSR-3 logger
  interface (`psr/log`) only.
- [openfeature-server-provider](packages/openfeature-server-provider/), the OpenFeature provider built
  on it: package `configdirector/openfeature-server-provider`, namespace `ConfigDirector\OpenFeature\`.

Each package is a Composer project of its own, with its own `composer.json`, `composer.lock`, `vendor/`
and development tools, so that a release can copy it to a repository of its own (see
[Releasing](#releasing)). Each has its own version, changelog, and release.

The provider requires a released range of the SDK, `^0.1.0`, which is what an application that
installs the provider from Packagist gets. In this working tree it reaches the SDK through a
Composer path repository instead, which reports the SDK in `packages/server-sdk` as `0.1.0` whatever
its `Version` class says, so the provider's lint and tests always run against the SDK as it is
here. When the provider needs a later line of the SDK, change the requirement and the version the
path repository reports together, in the provider's `composer.json`. The provider's release
workflow then refuses to run until Packagist serves an SDK version in that range.

The lock files are committed and resolved for PHP 8.3.36 (`config.platform.php` in each
`composer.json`), so one lock installs on every PHP version CI runs. After changing a package's
`composer.json`, run `composer update` in its directory. After changing the SDK's requirements, also
run `composer update configdirector/server-sdk` in the provider's directory, so it installs them
too.

[`samples/`](samples/) holds sample applications, each a Composer project of its own with a committed
`composer.lock`, under `samples/<package>/<framework>`. A sample requires a released version of the
package it shows, the way an application does. `make samples` runs PHPStan and each sample's tests
against that release. `make samples-local` runs them against the package in this working tree, in
a throwaway copy of the sample, so a change to a package that breaks the code an application
writes against it fails before it is released, and the sample's own files stay as they are.

## Working on the packages

You need PHP 8.3 or later, Composer 2, and Docker for `make check-floor`. Every PHP release we run
ourselves must have the fixes for CVE-2026-109618 and CVE-2026-109619: 8.3.36, 8.4.27, 8.5.12 or a
later release on the same line. On macOS, `brew install php composer` installs a patched PHP 8.5.

```sh
make install       # composer install, every package and sample
make hooks         # pre-push hook that runs make check-all
make check         # lint and test: the fast loop
make test-release  # the release scripts, against throwaway repositories
make samples       # PHPStan and the samples' suites, against the released packages they require
make samples-local # the same, against the packages in this working tree
make check-floor   # lint and the suites on PHP 8.3, in Docker
make check-all     # everything CI runs
```

Targets that run per package take `PACKAGES`, so `make test PACKAGES=server-sdk` runs one suite.
`make install`, `lint`, `format`, `verify-lock` and `audit` also run on every sample.

`make lint` runs PHP-CS-Fixer in dry-run mode with the PER coding style, then PHPStan at its strictest
level with no baseline; a finding is fixed, not ignored. `make format` applies PHP-CS-Fixer's fixes.
PHPUnit fails a run on warnings, notices, deprecations and risky tests.

Each package's `phpstan.dist.neon` also turns on several checks that no PHPStan level includes, such
as a missing `#[\Override]` and a property a constructor leaves unset. The files in
`packages/server-sdk/tests/StaticAnalysis/` are not PHPUnit tests: PHPStan checks each `assertType()`
call in them during `make lint`, which is how the types a caller's static analysis sees from
`getValue` and `evaluate` are tested.

`make verify-php` prints the version of the PHP that runs it and fails when that release lacks the CVE
fixes above; every CI job runs it first. `make verify-lock` fails when a `composer.lock` no longer
matches its `composer.json`. `make audit` runs `composer audit` against each lock file.

`make test-lowest` runs the suites against the lowest version of every dependency that each
`composer.json` allows, installed in a throwaway directory, so the committed lock files stay as they
are. `make check-floor` runs `make verify-php lint test samples samples-local` in the official `php:8.3-cli`
image, pulled again on every run so that it is the newest 8.3 release, on a copy of this working tree
without any `vendor/`. PHP-CS-Fixer is meant to run on the lowest PHP a project supports and warns on a newer one,
so `make check-all` lints there and not on your own PHP; `make check` and `make format` still run it on
your PHP and print that warning.

CI runs the suites on PHP 8.3, 8.4 and 8.5 on Ubuntu, on PHP 8.5 on macOS, and on PHP 8.3 and 8.5
on Windows, and `make test-lowest`, `make samples` and `make samples-local` on PHP 8.3 and 8.5. Locally,
`make check-all` covers your own PHP and, through `make check-floor`, PHP 8.3.

The local store tests of the server SDK start real PHP processes that read and write one store, from
the scripts in `packages/server-sdk/tests/Internal/LocalStore/Processes/`. The tests on file modes,
symbolic links, folder ownership and a writer killed while writing are skipped on Windows. The test
that a folder owned by another system user is refused runs only as the root user, which is what
`make check-floor` runs as, and the test that a folder without write permission is refused runs only
as any other user.

The polling tests of the server SDK poll a scripted SDK server,
`packages/server-sdk/tests/Support/Processes/scripted-sdk-server.php`, which runs as a process of its
own on a local port, answers each request with the next answer of its script and records what it
received. In `PollingRequestsTest` each request is a PHP process as well, started from
`packages/server-sdk/tests/Internal/Polling/Processes/serve-a-request.php`. The test that a server
whose certificate is not trusted is sent no poll needs the openssl extension and is skipped on
Windows.

The telemetry tests of the server SDK send their reports to the same scripted SDK server. In
`ClientTelemetryTest` one client reads and is disposed of, as a request would be, and a second client
with a flush interval of one millisecond stands for the later request that sends the report. In
`ClientTelemetryAcrossRequestsTest` each request is a PHP process, started from
`packages/server-sdk/tests/Processes/serve-a-request-that-reads-a-config.php`, and
`ClientTelemetryOverTimeTest` moves a settable clock instead of waiting. One test waits the five
seconds a report is given for its answer.

`ClientsOfManyProcessesTest` runs 50 PHP processes that serve requests on one local store for about
seven seconds each, from `packages/server-sdk/tests/Internal/Client/Processes/serve-requests-on-an-accelerated-clock.php`.
Their clock runs 60 times faster than real time, so that the minimum polling interval of 60 seconds
passes every second, and the scripted SDK server gives every request the same answer. One test counts
the polls and adds up the evaluations of every telemetry report; the other kills a process every 150
milliseconds meanwhile. The test is about what the processes do to each other, not about how many
requests a machine serves, so it is kept light enough for a CI runner with a few slow cores: every
process loads the SDK's classes and waits for the others before it serves, a request lasts 300 to 500
milliseconds, and about 125 requests end each second. Requests hold the telemetry lock shared while
they add their telemetry, so they do not wait for each other. Only a process that holds the lock
exclusive, to take the telemetry for a report, and waits for the CPU for more than 250 milliseconds
meanwhile would make the others end without their telemetry and log that. The first test expects no
such line and every read in a report exactly once, so telemetry that is lost or reported twice fails
it on any machine, and it passes on a quarter of a CPU (`docker run --cpus=0.25`). The second expects
no such line either, and no read reported more often than it was made, because a process killed
while it adds or sends telemetry loses its own. `AppendedLinesFileProcessesTest` has 50 processes
append a line of one mebibyte each to one file at the same moment and reads every line back whole,
which is what lets requests add their telemetry at the same time. That holds on a local file system,
so the local store does not belong on a network one. On Windows PHP moves to the end of a file and
writes to it in two steps, so there a process locks the telemetry file for its one write
(`AppendedLinesFile::append`) and waits for that lock for as long as the writes ahead of its own
take. Only the Windows jobs of CI run that path, and with a wait limited to 250 milliseconds they
lost up to 20 of the 50 lines. The test in
`ClientTelemetryAcrossRequestsTest` of a request killed while it adds its telemetry has the shell
limit the size of a file, needs `sh`, and is skipped on Windows. `ClientMemoryInALongRunningProcessTest` compares `memory_get_usage()` early
and late in 100,000 reads, 1,000 refreshes of the local store, 100,000 watches and 1,000 clients, the
last with the cycle collector off, so that a client is freed when it is disposed of and not later.
`SourceOfTheSdkTest` reads the source for calls to functions of PHP extensions that `composer.json`
does not require, and fails on one without a `function_exists` check in its file. The development
dependencies bring a polyfill of the ctype extension, so the suite itself cannot notice such a call.
It also fails on `unserialize`, `eval`, `include` or `require` anywhere under `src/`, and when
`composer.json` no longer requires a 64-bit PHP.

`make benchmark` measures what the server SDK costs a request: `createClient`, `initialize()`, reads
and `dispose()`, each request in a PHP process of its own, against a local store that holds 200
configs with rules and segments (`make benchmark CONFIGS=1000` for another number). It runs once with
every script compiled in each process and once with the compiled scripts reused through the OPcache
file cache, which is closer to PHP-FPM. It connects to nothing. Its numbers depend on the machine,
so it is not part of `make check-all`; the scripts are in [`tools/benchmark/`](tools/benchmark/).

The streaming process tests of the server SDK stream from a scripted stream server,
`packages/server-sdk/tests/Support/Processes/scripted-stream-server.php`, which holds one event stream
open, answers heartbeats, telemetry reports and polls meanwhile, records what it received, and sends
or closes the stream when the test tells it to. In `StreamingProcessTest` and `StreamingCommandTest`
the streaming process is a PHP process of its own, started from
`packages/server-sdk/tests/Processes/run-a-streaming-process.php`, or, for the command, from
`packages/server-sdk/tests/Processes/run-the-streaming-command.php`, which hands
`bin/configdirector-stream` the autoloader of the tests as Composer's `vendor/bin` proxy hands it the
application's, and each request is a PHP process as well.
`StreamingProcessOverTimeTest` moves a settable clock instead of waiting, and calls the streaming
process one step at a time. There, a stream shows a sign of life through an event that carries no
payload, because the error it logs tells the test that the bytes arrived. The tests that stop a
streaming process with a signal need the pcntl extension and are skipped without it, as on Windows.
CI installs it on Ubuntu and macOS, and `make check-floor` builds it into its image.

The Laravel sample's `StreamCommandTest` starts `php artisan configdirector:stream` as a PHP process
against a trimmed copy of that server, `samples/server-sdk/laravel/tests/Support/Processes/scripted-stream-server.php`,
which holds one event stream open and sends what the test writes to it. Each request is handled in
the test's own process. Its test that stops the command with SIGTERM needs pcntl too, which CI's
samples job installs.

The provider's tests create it as an application does and read flags through the OpenFeature client
API. A package does not load another package's tests, so the provider has its own trimmed copy of
the scripted SDK server, `packages/openfeature-server-provider/tests/Support/Processes/scripted-sdk-server.php`,
which gives every request the same answer. Its context tests serve offline values and read the
context each evaluation received through the client's `configEvaluated` hook. The tests of the
version the provider reports run a PHP process that declares the provider's `Version` class with a
version of its own.

The checks live in the [Makefile](Makefile). CI and the pre-push hook only call `make` targets, so add
new checks there rather than in either of them.

## How the provider identifies itself

The SDK reports an SDK name and version in the `User-Agent` header and in every request it sends
for values and in every telemetry report: `php-server-sdk` and `ConfigDirector\Version::CURRENT`.
A package built on the SDK, such as the provider, reports its own name and version,
`php-openfeature-server-provider` and `ConfigDirector\OpenFeature\Version::CURRENT`, without the SDK
accepting an arbitrary identity from its caller: the SDK keeps a closed list of the packages allowed
to, the `ConfigDirector\Internal\Identity\Wrapper` enum, and a wrapper creates its client through its
case of that enum. Adding a wrapper package means adding a case to it, releasing the SDK, and
registering the new name in the ConfigDirector capability tables.

## Releasing

Each package is released on its own, and the steps are the same for both. Its version lives in
exactly one place, the `CURRENT` constant of its `Version` class:

| Package | Version | Changelog | Workflow | Read-only repository |
|---|---|---|---|---|
| configdirector/server-sdk | [`Version.php`](packages/server-sdk/src/Version.php) | [CHANGELOG.md](packages/server-sdk/CHANGELOG.md) | [Release server-sdk](.github/workflows/release-server-sdk.yml) | [ConfigDirector/php-server-sdk](https://github.com/ConfigDirector/php-server-sdk) |
| configdirector/openfeature-server-provider | [`Version.php`](packages/openfeature-server-provider/src/Version.php) | [CHANGELOG.md](packages/openfeature-server-provider/CHANGELOG.md) | [Release openfeature-server-provider](.github/workflows/release-openfeature-server-provider.yml) | [ConfigDirector/php-openfeature-server-provider](https://github.com/ConfigDirector/php-openfeature-server-provider) |

Packagist publishes one package per repository and serves a version from a tag named after it. So
each package has a read-only repository of its own, which Packagist reads, and a release pushes
there the commits that changed the package's directory, with that directory as the root, and tags
the last of them `vX.Y.Z`. Nothing else is ever pushed to a read-only repository, and issues and
pull requests belong in this one.

1. In the package's changelog, rename `## [Unreleased]` to `## [X.Y.Z] - YYYY-MM-DD` and open a
   fresh, empty `## [Unreleased]` above it.
2. Set `CURRENT` to match and merge both to `main`.
3. Run the package's release workflow against `main`; it refuses any other branch. It is manual
   (`workflow_dispatch`) by design and releases whatever version `main` declares. It reads
   `CURRENT` and refuses to go on when `vX.Y.Z` already exists in the read-only repository, when
   `<package>/vX.Y.Z` already exists in this one, when the changelog has no section for that
   version (an empty section counts as none), or when the package's `composer.json` requires a
   development version of another package, such as `dev-main`, or a version that Packagist does
   not serve yet. Then it runs `make check-all`. A second job, on a checkout of its own, pushes the
   package and its tag to the read-only repository, tags the released commit `<package>/vX.Y.Z`
   in this repository, creates a GitHub Release here with the changelog section as its notes, and
   waits until Packagist serves the version.
4. Run `composer require configdirector/<package>:^X.Y.Z` in each of the package's samples and
   merge their `composer.json` and `composer.lock`, so the samples install the new version and
   `make samples` checks it.

A version is `X.Y.Z`, or `X.Y.Z-alpha.N`, `X.Y.Z-beta.N` or `X.Y.Z-rc.N` for a prerelease, which is
marked as one on GitHub and which Composer installs only for an application that asks for that
stability. The workflow refuses every other form, because Packagist skips a tag that Composer
cannot read as a version.

Composer and Packagist keep what they downloaded for a version, so a tag is never moved or
deleted: to ship a fix, bump `CURRENT`, add a changelog section and release again. Both workflows
call [release-package.yml](.github/workflows/release-package.yml) with their package's name, so a
change to the release steps is made there once. The checks that run before anything is pushed
live in [release-preflight.sh](.github/scripts/release-preflight.sh) and the push in
[push-package-to-read-only-repository.sh](.github/scripts/push-package-to-read-only-repository.sh);
`make test-release` runs both against throwaway repositories.

The push to the read-only repository is the release. When a step before it fails, nothing is
published: fix the cause and run the workflow again, or only its failed job when the checks had
passed. When a later step fails, the version is out and the workflow refuses to release it again,
so finish by hand what the failed run left. For the server SDK, with the commit the run released,
which its page on GitHub names, and the version's changelog section saved to a file:

```sh
git tag -a server-sdk/vX.Y.Z <released commit> -m 'server-sdk X.Y.Z'
git push origin server-sdk/vX.Y.Z
gh release create server-sdk/vX.Y.Z --repo ConfigDirector/php-sdks --verify-tag \
  --title server-sdk/vX.Y.Z --notes-file <file with the changelog section>
```

If Packagist does not list the version after that, its hook did not fire: a maintainer of the
package can start an update from the package's page on Packagist, and
[The first release of a package](#the-first-release-of-a-package) has the command that checks the
hook.

### What a release publishes

The archive Composer downloads for a version is built by GitHub from the read-only repository, and
the `.gitattributes` file of each package leaves the tests, the lock file and the configuration of
the development tools out of it. `make test-release` lists what the archive of each package holds
and fails on any other entry: `src`, `composer.json`, `CHANGELOG.md`, `LICENSE` and `README.md`
for both, and `bin` for the server SDK. So a new development file goes into `.gitattributes`, and
a new file that an application needs goes into that test. The `LICENSE` of each package is a copy
of the one at the root, because only the files in a package's directory reach its read-only
repository.

### Setting up the read-only repositories

These steps are done once, by an owner of the ConfigDirector organization on GitHub, before the
first release. Each one ends with a command that changes nothing and proves the step.

**1. Create the two read-only repositories.**

```sh
gh repo create ConfigDirector/php-server-sdk --public --disable-issues --disable-wiki \
  --description '[READ ONLY] PHP server SDK for ConfigDirector. Issues and pull requests: https://github.com/ConfigDirector/php-sdks'
gh repo create ConfigDirector/php-openfeature-server-provider --public --disable-issues --disable-wiki \
  --description '[READ ONLY] PHP OpenFeature server provider for ConfigDirector. Issues and pull requests: https://github.com/ConfigDirector/php-sdks'
```

| Setting | What it is for, and what the other choice does |
|---|---|
| `--public` | Packagist reads the repository without a login, and cannot read a private one. |
| No `--add-readme`, `--gitignore` or `--license` | The release pushes the package's own history to `main` and never overwrites another one. A repository created with any of the three holds a commit of its own, and the first release fails at its push with `! [rejected] ... -> main (fetch first)`. |
| `--disable-issues`, `--disable-wiki` | Issues are answered in php-sdks, so the read-only repository offers no place to open one that nobody watches. |

Prove it: each line has to end with `PUBLIC true`, the `true` being that the repository is empty.

```sh
for repository in php-server-sdk php-openfeature-server-provider; do
  gh repo view "ConfigDirector/$repository" --json name,visibility,isEmpty \
    --jq '"\(.name) \(.visibility) \(.isEmpty)"'
done
```

**2. Create the token the workflow pushes with.** It is a fine-grained personal access token, the
kind the CLI's release pushes to the Homebrew tap with. Open
<https://github.com/settings/personal-access-tokens/new?name=php-sdks+release&description=Pushes+the+php-sdks+packages+to+their+read-only+repositories&target_name=ConfigDirector&contents=write&expires_in=366>,
which fills in every row but the repositories, and check each one before **Generate token**:

| Row on the page | Value | What it is for, and what the wrong choice does |
|---|---|---|
| **Token name** | `php-sdks release` | Only tells this token from your others. |
| **Resource owner** | `ConfigDirector` | The token only reaches repositories of this owner. Under your own account the two repositories cannot be selected. |
| **Expiration** | 366 days, the longest the link can ask for | Once it has passed, the push of a release fails as it does for a mistyped token, with `Invalid username or token`, and steps 2 and 3 are done again with a new token. |
| **Repository access** | **Only select repositories** | Keeps the token away from every other repository of the organization. |
| **Selected repositories** | `php-server-sdk` and `php-openfeature-server-provider` | The repositories the token may push to. A release of the package whose repository is left out fails at its push with `The requested URL returned error: 403`. |
| **Permissions** / **Contents** | **Read and write** | Pushing commits and tags. With read-only access the push fails with the same 403. |
| **Permissions** / **Metadata** | **Read-only** | GitHub adds it by itself; leave it. |

Prove it before storing it: the command asks GitHub whether the token may push to each repository,
and pushes nothing. Paste the token at the prompt, which does not show it. Each line has to end
with `200`. `401` means the token is mistyped or expired, and `403` that it may not push to that
repository: the repository was not selected, **Contents** is not **Read and write**, or the token
still waits for an organization owner's approval.

```sh
read -rs TOKEN
for repository in php-server-sdk php-openfeature-server-provider; do
  curl --silent --output /dev/null --write-out "$repository %{http_code}\n" --user "x-access-token:$TOKEN" \
    "https://github.com/ConfigDirector/$repository.git/info/refs?service=git-receive-pack"
done
```

**3. Store the token as an Actions secret of this repository.** In the same shell, so that the
token is never written anywhere else:

```sh
printf '%s' "$TOKEN" | gh secret set READ_ONLY_REPOSITORIES_TOKEN --repo ConfigDirector/php-sdks
unset TOKEN
```

On github.com the same secret is made in this repository's **Settings**, under **Secrets and
variables**, then **Actions**, on the **Secrets** tab, with **New repository secret**:

| Row on the page | Value | What it is for, and what the wrong choice does |
|---|---|---|
| **Name** | `READ_ONLY_REPOSITORIES_TOKEN` | The name the release workflows read. Under any other name, or as an environment secret, the workflow finds nothing and stops before any check runs, saying that the secret is empty or missing. |
| **Secret** | The token of step 2 | What the release pushes with. |

Prove it: the list has to name `READ_ONLY_REPOSITORIES_TOKEN`, updated just now. GitHub never
shows a secret's value again, which is why step 2 proves the token first.

```sh
gh secret list --repo ConfigDirector/php-sdks
```

**4. Check that nobody holds the vendor name on Packagist.** Packagist gives a vendor name to the
maintainers of the first package published under it, and a tag pushed to a read-only repository is
never taken back, so look before the first release. The command has to print
`{"packageNames":[]}` then, and only packages of this repository later.

```sh
curl --silent 'https://packagist.org/packages/list.json?vendor=configdirector'
```

### The first release of a package

A package's name on Packagist comes from the `composer.json` in its repository, so a read-only
repository can be submitted to Packagist only after its first release, not before. That first run
of the workflow ends with a warning that Packagist does not list the package yet, and these steps
follow it, once per package:

| Where | What to do | What it is for, and what goes wrong otherwise |
|---|---|---|
| <https://packagist.org/login/> | **Log in with GitHub**, as a member of the ConfigDirector organization | Packagist adds its hook to the read-only repository through this GitHub login, so that every later tag reaches it at once. Logged in with a Packagist password only, the package is submitted without a hook, and Packagist looks at it once a week. |
| GitHub's authorization page for the Packagist application | Give it access to the ConfigDirector organization | Without it Packagist cannot add the hook to a repository of the organization. |
| <https://packagist.org/packages/submit> | Enter the read-only repository's URL, such as `https://github.com/ConfigDirector/php-server-sdk`, and submit it | Publishes the package under the name in its `composer.json`. The first one reserves the vendor name `configdirector` for its maintainers, so the second package has to be submitted by one of them. |

Prove the submission, with the package's name in `PACKAGE`: the command prints `v0.1.0`, or
whichever version was released.

```sh
PACKAGE=server-sdk
curl --silent "https://repo.packagist.org/p2/configdirector/$PACKAGE.json" | jq --raw-output '.packages[][].version'
```

Prove the hook, with the read-only repository's name: the command prints a URL that starts with
`https://packagist.org/api/github`, followed by `true`. When it prints nothing,
<https://packagist.org/profile/> shows the package with a warning that it is not synced
automatically. Then log out of Packagist and in again with GitHub, check the organization's access,
and open <https://packagist.org/trigger-github-sync/>, or add the hook by hand with the values that
<https://packagist.org/about#how-to-update-packages> lists.

```sh
gh api repos/ConfigDirector/php-server-sdk/hooks --jq '.[] | "\(.config.url) \(.active)"'
```

Last, in an empty folder and on a 64-bit PHP, `composer require configdirector/server-sdk:X.Y.Z`
has to install the version, and `vendor/configdirector/server-sdk` has to hold no `tests`
directory.

### Version line

The packages stay at `0.x` until the SDK functional tests pass against them in dev, which is when
`1.0.0` is released.
