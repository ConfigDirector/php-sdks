.PHONY: install hooks verify-php verify-lock format lint test test-lowest test-release samples samples-local \
	check-floor audit benchmark check check-all clean

# Every package this repository publishes, each in a directory under packages/ named after its Composer
# package. Narrow a target to one of them with, for example, `make test PACKAGES=server-sdk`.
PACKAGES := server-sdk openfeature-server-provider

# Sample applications, each a Composer project under samples/<package>/<framework> that requires a released
# version of that package, the way an application does.
SAMPLES := samples/server-sdk/slim samples/server-sdk/laravel samples/openfeature-server-provider/slim

# Every Composer project in this repository: the packages, then the samples. `install`, `verify-lock`, `format`,
# `lint`, `audit` and `clean` run on each of them; `test` and `test-lowest` run on the packages only.
PROJECTS := $(addprefix packages/,$(PACKAGES)) $(SAMPLES)

# The official image of the oldest PHP line the packages support. `check-floor` runs the lint and the suites
# in it.
FLOOR_IMAGE := php:8.3-cli
FLOOR_TAG := configdirector-php-sdks-floor

install:
	@for project in $(PROJECTS); do \
		printf '\n\033[1m==> install %s\033[0m\n' "$$project"; \
		(cd "$$project" && composer install --no-interaction --no-progress) || exit 1; \
	done

hooks:
	git config core.hooksPath .githooks
	@echo "pre-push hook installed. Bypass a single push with 'git push --no-verify'."

# Fails on a PHP release without the security fixes that tools/verify-php.php lists, and prints the
# running PHP version either way.
verify-php:
	@printf '\n\033[1m==> verify-php\033[0m\n'
	@php tools/verify-php.php

# Asserts each composer.lock is still consistent with its composer.json, which is what CI installs from.
verify-lock:
	@for project in $(PROJECTS); do \
		printf '\n\033[1m==> verify-lock %s\033[0m\n' "$$project"; \
		(cd "$$project" && composer validate --strict) || exit 1; \
	done

format: install
	@for project in $(PROJECTS); do \
		(cd "$$project" && php vendor/bin/php-cs-fixer fix) || exit 1; \
	done

lint: install
	@for project in $(PROJECTS); do \
		printf '\n\033[1m==> lint %s\033[0m\n' "$$project"; \
		(cd "$$project" \
			&& php vendor/bin/php-cs-fixer check --diff \
			&& php vendor/bin/phpstan analyse --no-progress) || exit 1; \
	done

test:
	@for package in $(PACKAGES); do \
		printf '\n\033[1m==> test %s\033[0m\n' "$$package"; \
		(cd "packages/$$package" \
			&& composer install --no-interaction --no-progress \
			&& php vendor/bin/phpunit) || exit 1; \
	done

# The same tests against the lowest version of every dependency that composer.json allows. Without
# this a floor such as `psr/log: ^2.0` is only ever a guess, because the lock picks the newest. Composer
# resolves into a throwaway directory, so the committed lock and the package's vendor/ stay as they are.
test-lowest:
	@for package in $(PACKAGES); do \
		printf '\n\033[1m==> test-lowest %s\033[0m\n' "$$package"; \
		lowest=$$(mktemp -d); \
		cp "packages/$$package/composer.json" "$$lowest/composer.json"; \
		(cd "packages/$$package" \
			&& COMPOSER="$$lowest/composer.json" COMPOSER_VENDOR_DIR="$$lowest/vendor" \
				composer update --prefer-lowest --prefer-stable --no-interaction --no-progress \
			&& php "$$lowest/vendor/bin/phpunit" --bootstrap "$$lowest/vendor/autoload.php"); \
		status=$$?; rm -rf "$$lowest"; [ "$$status" -eq 0 ] || exit "$$status"; \
	done

# The release workflow's scripts decide the version, the tags and the notes of a release, refuse one
# they must not make, and push a package to its read-only repository. This runs them against throwaway
# repositories, one per scenario, and checks what the archive Composer downloads holds for each package.
test-release:
	@printf '\n\033[1m==> test-release\033[0m\n'
	.github/scripts/release-test.sh

# PHPStan and the suite of each sample, against the released package its composer.lock names, the way an
# application installs it.
samples:
	@for sample in $(SAMPLES); do \
		printf '\n\033[1m==> samples %s (released package)\033[0m\n' "$$sample"; \
		(cd "$$sample" \
			&& composer install --no-interaction --no-progress \
			&& php vendor/bin/phpstan analyse --no-progress \
			&& php vendor/bin/phpunit) || exit 1; \
	done

# The same checks against the packages in this working tree, which is what catches a change to a package
# that breaks the code an application writes against it; `samples` cannot, because a sample's lock names
# a release. Each sample is copied to a throwaway directory, where its requirement becomes the package's
# directory here, so the sample's own composer.json, composer.lock and vendor/ stay as they are. Composer
# reads the path repositories of a package only when that package is the project it installs, so the
# copy gets the package's own path repositories too, at the versions they report, which the package's
# requirements accept: the provider's sample then also installs the server SDK from here. The package's
# dependencies may move within the ranges it requires, and every other dependency stays at the version
# the sample's lock names.
samples-local:
	@for sample in $(SAMPLES); do \
		printf '\n\033[1m==> samples-local %s (working-tree packages)\033[0m\n' "$$sample"; \
		package=$$(basename "$$(dirname "$$sample")"); \
		copy=$$(mktemp -d); \
		tar -C "$$sample" --exclude=./vendor --exclude=./.env --exclude=./.phpunit.cache -cf - . | tar -C "$$copy" -xf - \
		&& (cd "$$copy" \
			&& composer config --list --working-dir "$(CURDIR)/packages/$$package" \
				| sed -n 's|^\[repositories\.[^]]*\.options\.versions\.configdirector/\([^]]*\)\] \(.*\)$$|\1 \2|p' \
				| while read -r dependency version; do \
					composer config --no-interaction "repositories.working-tree-$$dependency" \
						"{\"type\": \"path\", \"url\": \"$(CURDIR)/packages/$$dependency\", \"options\": {\"versions\": {\"configdirector/$$dependency\": \"$$version\"}}}" \
						< /dev/null || exit 1; \
				done \
			&& composer config repositories.working-tree \
				"{\"type\": \"path\", \"url\": \"$(CURDIR)/packages/$$package\", \"options\": {\"versions\": {\"configdirector/$$package\": \"dev-main\"}}}" \
			&& composer require --no-update --no-interaction "configdirector/$$package:dev-main" \
			&& composer update "configdirector/$$package" --with-dependencies --no-interaction --no-progress \
			&& php vendor/bin/phpstan analyse --no-progress \
			&& php vendor/bin/phpunit); \
		status=$$?; rm -rf "$$copy"; [ "$$status" -eq 0 ] || exit "$$status"; \
	done

# The lint and the suites on the oldest supported PHP line, in its official image, from a copy of this
# working tree without any vendor/, so nothing installed for the local PHP leaks in and nothing is
# written back. The lint runs here rather than on the local PHP because PHP-CS-Fixer is meant to run on
# the lowest PHP a project supports, as it does in CI. --pull fetches the image's latest patch release,
# which verify-php then checks.
check-floor:
	@printf '\n\033[1m==> check-floor %s\033[0m\n' "$(FLOOR_IMAGE)"
	docker build --pull --quiet --build-arg PHP_IMAGE=$(FLOOR_IMAGE) --tag $(FLOOR_TAG) - < tools/floor.Dockerfile
	docker run --rm --volume "$(CURDIR)":/source:ro --env COMPOSER_ROOT_VERSION=dev-main $(FLOOR_TAG) sh -c '\
		mkdir /work \
		&& tar -C /source --exclude=./.git --exclude=vendor --exclude=.phpunit.cache -cf - . | tar -C /work -xf - \
		&& make -C /work verify-php lint test samples samples-local'

audit:
	@for project in $(PROJECTS); do \
		printf '\n\033[1m==> audit %s\033[0m\n' "$$project"; \
		(cd "$$project" && composer audit --locked) || exit 1; \
	done

# What the server SDK costs a request: createClient, initialize(), reads and dispose() in a new PHP process
# for each request, against a local store that holds CONFIGS configs with rules and segments. It connects
# to nothing, and its numbers depend on the machine, so no check runs it.
CONFIGS := 200
benchmark:
	@(cd packages/server-sdk && composer install --no-interaction --no-progress --quiet)
	@php tools/benchmark/run.php "$(CURDIR)/packages/server-sdk/vendor/autoload.php" $(CONFIGS)

# The fast loop while working.
check: lint test

# Everything CI runs. The pre-push hook calls this.
check-all: verify-php verify-lock test test-lowest test-release samples samples-local check-floor audit
	@printf '\n\033[1m✓ all checks passed\033[0m\n'

clean:
	@for project in $(PROJECTS); do \
		rm -rf "$$project/vendor" "$$project/.phpunit.cache" "$$project/.php-cs-fixer.cache"; \
	done
	-docker image rm $(FLOOR_TAG)
