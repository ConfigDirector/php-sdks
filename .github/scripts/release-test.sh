#!/usr/bin/env sh
#
# Runs the release scripts against throwaway repositories, one per scenario: the releases they must
# make and every refusal. `make test-release` runs this.

set -eu

scripts_directory=$(cd "$(dirname "$0")" && pwd)
repository_root=$(cd "$scripts_directory/../.." && pwd)
preflight="$scripts_directory/release-preflight.sh"
push_package="$scripts_directory/push-package-to-read-only-repository.sh"
sandbox=$(mktemp -d)
trap 'rm -rf "$sandbox"' EXIT
failures=0
scenario_failed=0

# Nothing from the developer's git configuration (hooks, signing, identity) reaches the throwaway
# repositories.
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_SYSTEM=/dev/null GIT_CONFIG_NOSYSTEM=1
export GIT_AUTHOR_NAME=test GIT_AUTHOR_EMAIL=test@example.com
export GIT_COMMITTER_NAME=test GIT_COMMITTER_EMAIL=test@example.com

version_php() {
    printf "<?php\n\ndeclare(strict_types=1);\n\nnamespace ConfigDirector;\n\nfinal class Version\n{\n    public const string CURRENT = '%s';\n\n    private function __construct() {}\n}\n" "$1"
}

released_changelog() {
    printf '# Changelog\n\n## [Unreleased]\n\n## [%s] - 2026-11-14\n\n### Added\n\n- A client.\n\n## [0.0.1] - 2026-11-01\n\n### Added\n\n- The repository.\n' "$1"
}

composer_json() {
    printf '{\n    "name": "configdirector/server-sdk",\n    "require": {\n        "php": "^8.3",\n        "psr/log": "^2.0 || ^3.0"\n    }\n}\n'
}

# A clone of a bare origin that holds one package with the given Version.php, CHANGELOG.md and
# composer.json, pushed, as the workflow's checkout holds this repository, beside the empty
# read-only repository of that package. Sets $clone and $read_only_repository.
repository_with() {
    directory="$sandbox/$scenario"
    clone="$directory/clone"
    read_only_repository="$directory/read-only"
    git init -q --bare --initial-branch=main "$directory/origin"
    git init -q --bare --initial-branch=main "$read_only_repository"
    git clone -q "$directory/origin" "$clone" 2>/dev/null
    mkdir -p "$clone/packages/$1/src"
    printf '%s\n' "$2" > "$clone/packages/$1/src/Version.php"
    printf '%s\n' "$3" > "$clone/packages/$1/CHANGELOG.md"
    printf '%s\n' "$4" > "$clone/packages/$1/composer.json"
    git -C "$clone" add .
    git -C "$clone" commit -q -m "Prepare the release"
    git -C "$clone" push -q origin HEAD
}

# Runs the preflight from the clone, as the workflow does from its checkout. Sets $status,
# $outputs_file, $notes_file, $stderr_file.
run_preflight() {
    outputs_file="$directory/github-output"
    notes_file="$directory/release-notes.md"
    stderr_file="$directory/stderr"
    : > "$outputs_file"
    status=0
    (cd "$clone" && GITHUB_OUTPUT="$outputs_file" "$preflight" "$1" "$read_only_repository" "$notes_file") \
        > "$directory/stdout" 2> "$stderr_file" || status=$?
}

fail() {
    printf 'FAIL %s: %s\n' "$scenario" "$1"
    [ "$scenario_failed" -eq 1 ] || failures=$((failures + 1))
    scenario_failed=1
}

assert_status() {
    [ "$status" -eq "$1" ] || fail "exit status was $status, expected $1 (stderr: $(cat "$stderr_file"))"
}

assert_outputs() {
    actual=$(cat "$outputs_file")
    [ "$actual" = "$1" ] || fail "outputs were '$actual', expected '$1'"
}

assert_notes() {
    actual=$(cat "$notes_file" 2>/dev/null || true)
    [ "$actual" = "$1" ] || fail "notes were '$actual', expected '$1'"
}

assert_refused_with() {
    assert_status 1
    grep -qF -- "$1" "$stderr_file" || fail "stderr does not mention '$1': $(cat "$stderr_file")"
    assert_outputs ""
    [ ! -e "$notes_file" ] || fail "notes were written for a refused release"
}

push_tag() {
    git -C "$clone" tag "$2"
    git -C "$clone" push -q "$1" "$2"
}

# Commits one file in the clone and pushes it to the origin.
commit() {
    mkdir -p "$(dirname "$clone/$1")"
    printf '%s\n' "$2" > "$clone/$1"
    git -C "$clone" add .
    git -C "$clone" commit -q -m "$3"
    git -C "$clone" push -q origin HEAD
}

# Pushes the package from the given checkout, as the workflow does from its own. Sets $status and
# $stderr_file.
run_push_from() {
    stderr_file="$directory/stderr"
    status=0
    (cd "$1" && "$push_package" "$2" "$3" "$read_only_repository") \
        > "$directory/stdout" 2> "$stderr_file" || status=$?
}

in_the_read_only_repository() {
    git -C "$read_only_repository" "$@" 2>&1 || true
}

assert_in_the_read_only_repository() {
    expected=$1
    shift
    actual=$(in_the_read_only_repository "$@")
    [ "$actual" = "$expected" ] || fail "git $* gave '$actual' in the read-only repository, expected '$expected'"
}

assert_push_refused_with() {
    [ "$status" -ne 0 ] || fail "the push was not refused"
    grep -qF -- "$1" "$stderr_file" || fail "stderr does not mention '$1': $(cat "$stderr_file")"
}

# The top-level names of the archive Composer downloads for a package of this working tree, with
# its uncommitted changes: GitHub builds that archive from the read-only repository, where the
# package's directory is the root and its .gitattributes decides what is left out.
archive_of_package() {
    copy="$sandbox/$scenario"
    mkdir -p "$copy"
    (cd "$repository_root/packages/$1" && git ls-files --cached --others --exclude-standard) | while read -r file; do
        [ -e "$repository_root/packages/$1/$file" ] || continue
        mkdir -p "$(dirname "$copy/$file")"
        cp "$repository_root/packages/$1/$file" "$copy/$file"
    done
    git -C "$copy" init -q
    git -C "$copy" add .
    git -C "$copy" commit -q -m "The package as its read-only repository holds it"
    git -C "$copy" archive HEAD | tar -tf - | cut -d/ -f1 | LC_ALL=C sort -u
}

assert_archive_of_package() {
    actual=$(archive_of_package "$1")
    [ "$actual" = "$2" ] || fail "the archive of $1 holds '$actual', expected '$2'"
}

# Ends a scenario: prints its name when every assertion since the last one held.
pass() {
    [ "$scenario_failed" -eq 1 ] || printf 'ok   %s\n' "$scenario"
    scenario_failed=0
}

scenario=releases_the_version_the_package_declares_with_its_changelog_section_as_notes
repository_with server-sdk "$(version_php 0.1.0)" "$(released_changelog 0.1.0)" "$(composer_json)"
run_preflight server-sdk
assert_status 0
assert_outputs "version=0.1.0
read-only-repository-tag=v0.1.0
monorepo-tag=server-sdk/v0.1.0
prerelease=false"
assert_notes "### Added

- A client."
pass

scenario=names_the_monorepo_tag_after_the_package_directory
repository_with openfeature-server-provider "$(version_php 0.1.0)" "$(released_changelog 0.1.0)" "$(composer_json)"
run_preflight openfeature-server-provider
assert_status 0
assert_outputs "version=0.1.0
read-only-repository-tag=v0.1.0
monorepo-tag=openfeature-server-provider/v0.1.0
prerelease=false"
pass

scenario=marks_a_release_candidate_as_a_prerelease
repository_with server-sdk "$(version_php 0.2.0-rc.1)" "$(released_changelog 0.2.0-rc.1)" "$(composer_json)"
run_preflight server-sdk
assert_status 0
assert_outputs "version=0.2.0-rc.1
read-only-repository-tag=v0.2.0-rc.1
monorepo-tag=server-sdk/v0.2.0-rc.1
prerelease=true"
pass

scenario=marks_a_beta_as_a_prerelease
repository_with server-sdk "$(version_php 0.2.0-beta.2)" "$(released_changelog 0.2.0-beta.2)" "$(composer_json)"
run_preflight server-sdk
assert_status 0
assert_outputs "version=0.2.0-beta.2
read-only-repository-tag=v0.2.0-beta.2
monorepo-tag=server-sdk/v0.2.0-beta.2
prerelease=true"
pass

scenario=marks_an_alpha_as_a_prerelease
repository_with server-sdk "$(version_php 0.2.0-alpha.1)" "$(released_changelog 0.2.0-alpha.1)" "$(composer_json)"
run_preflight server-sdk
assert_status 0
assert_outputs "version=0.2.0-alpha.1
read-only-repository-tag=v0.2.0-alpha.1
monorepo-tag=server-sdk/v0.2.0-alpha.1
prerelease=true"
pass

scenario=refuses_a_version_whose_tag_already_exists_in_the_read_only_repository
repository_with server-sdk "$(version_php 0.1.0)" "$(released_changelog 0.1.0)" "$(composer_json)"
push_tag "$read_only_repository" v0.1.0
run_preflight server-sdk
assert_refused_with "v0.1.0 already exists in $read_only_repository"
pass

scenario=refuses_a_version_whose_tag_already_exists_in_this_repository
repository_with server-sdk "$(version_php 0.1.0)" "$(released_changelog 0.1.0)" "$(composer_json)"
push_tag origin server-sdk/v0.1.0
run_preflight server-sdk
assert_refused_with "server-sdk/v0.1.0 already exists in this repository"
pass

scenario=refuses_a_read_only_repository_it_cannot_read
repository_with server-sdk "$(version_php 0.1.0)" "$(released_changelog 0.1.0)" "$(composer_json)"
read_only_repository="$directory/never-created"
run_preflight server-sdk
assert_refused_with "Could not read the tags of $directory/never-created"
pass

scenario=refuses_when_the_tags_of_this_repository_cannot_be_read
repository_with server-sdk "$(version_php 0.1.0)" "$(released_changelog 0.1.0)" "$(composer_json)"
git -C "$clone" remote set-url origin "$directory/moved-away"
run_preflight server-sdk
assert_refused_with "Could not read the tags of this repository's origin"
pass

scenario=refuses_a_version_without_a_changelog_section
repository_with server-sdk "$(version_php 0.1.0)" "$(released_changelog 0.0.2)" "$(composer_json)"
run_preflight server-sdk
assert_refused_with "no '## [0.1.0]' section"
pass

scenario=refuses_a_version_whose_only_section_is_one_of_its_prereleases
repository_with server-sdk "$(version_php 0.1.0)" "$(released_changelog 0.1.0-rc.1)" "$(composer_json)"
run_preflight server-sdk
assert_refused_with "no '## [0.1.0]' section"
pass

scenario=refuses_a_version_whose_changelog_section_is_empty
repository_with server-sdk "$(version_php 0.1.0)" "$(printf '# Changelog\n\n## [Unreleased]\n\n## [0.1.0] - 2026-11-14\n\n## [0.0.1] - 2026-11-01\n\n- The repository.\n')" "$(composer_json)"
run_preflight server-sdk
assert_refused_with "'## [0.1.0]' section in packages/server-sdk/CHANGELOG.md is empty"
pass

scenario=refuses_the_version_of_a_package_never_prepared_for_a_release
repository_with server-sdk "$(version_php 0.0.0-dev)" "$(released_changelog 0.0.0-dev)" "$(composer_json)"
run_preflight server-sdk
assert_refused_with "Version '0.0.0-dev' must be X.Y.Z"
pass

scenario=refuses_a_version_with_a_v_prefix
repository_with server-sdk "$(version_php v0.1.0)" "$(released_changelog 0.1.0)" "$(composer_json)"
run_preflight server-sdk
assert_refused_with "Version 'v0.1.0' must be X.Y.Z"
pass

scenario=refuses_a_prerelease_suffix_composer_does_not_read
repository_with server-sdk "$(version_php 0.2.0-preview.1)" "$(released_changelog 0.2.0-preview.1)" "$(composer_json)"
run_preflight server-sdk
assert_refused_with "Version '0.2.0-preview.1' must be X.Y.Z"
pass

scenario=refuses_a_package_without_a_version_constant
repository_with server-sdk "$(printf '<?php\n\nfinal class Version {}\n')" "$(released_changelog 0.1.0)" "$(composer_json)"
run_preflight server-sdk
assert_refused_with "Could not read the CURRENT constant from packages/server-sdk/src/Version.php"
pass

scenario=refuses_a_package_that_requires_another_package_from_this_working_tree
repository_with openfeature-server-provider "$(version_php 0.1.0)" "$(released_changelog 0.1.0)" "$(printf '{\n    "name": "configdirector/openfeature-server-provider",\n    "require": {\n        "php": "^8.3",\n        "configdirector/server-sdk": "dev-main"\n    }\n}\n')"
run_preflight openfeature-server-provider
assert_refused_with "configdirector/server-sdk at dev-main"
pass

scenario=refuses_a_package_directory_that_does_not_exist
repository_with server-sdk "$(version_php 0.1.0)" "$(released_changelog 0.1.0)" "$(composer_json)"
run_preflight client-sdk
assert_refused_with "Cannot find packages/client-sdk/src/Version.php"
pass

scenario=pushes_the_package_directory_as_the_root_of_the_read_only_repository_and_tags_it
repository_with server-sdk "$(version_php 0.1.0)" "$(released_changelog 0.1.0)" "$(composer_json)"
run_push_from "$clone" server-sdk v0.1.0
assert_status 0
assert_in_the_read_only_repository "CHANGELOG.md
composer.json
src/Version.php" ls-tree -r --name-only main
assert_in_the_read_only_repository "tag" cat-file -t v0.1.0
assert_in_the_read_only_repository "server-sdk v0.1.0" tag --list --format='%(contents:subject)' v0.1.0
assert_in_the_read_only_repository "$(in_the_read_only_repository rev-parse main)" rev-parse "v0.1.0^{commit}"
pass

scenario=keeps_the_commits_that_changed_the_package_and_no_others
repository_with server-sdk "$(version_php 0.1.0)" "$(released_changelog 0.1.0)" "$(composer_json)"
commit README.md "# The repository" "Describe the repository"
commit packages/server-sdk/src/Client.php "<?php" "Add a client"
commit packages/openfeature-server-provider/src/Provider.php "<?php" "Add a provider"
run_push_from "$clone" server-sdk v0.1.0
assert_status 0
assert_in_the_read_only_repository "Add a client
Prepare the release" log --format=%s main
pass

scenario=adds_the_commits_of_a_later_release_on_top_of_the_earlier_one
repository_with server-sdk "$(version_php 0.1.0)" "$(released_changelog 0.1.0)" "$(composer_json)"
run_push_from "$clone" server-sdk v0.1.0
first_release=$(in_the_read_only_repository rev-parse main)
commit packages/server-sdk/src/Client.php "<?php" "Add a client"
run_push_from "$clone" server-sdk v0.2.0
assert_status 0
assert_in_the_read_only_repository "$first_release" rev-parse "v0.1.0^{commit}"
assert_in_the_read_only_repository "$first_release" rev-parse "v0.2.0^{commit}~1"
assert_in_the_read_only_repository "$(in_the_read_only_repository rev-parse "v0.2.0^{commit}")" rev-parse main
pass

scenario=refuses_a_read_only_repository_that_holds_commits_of_its_own
repository_with server-sdk "$(version_php 0.1.0)" "$(released_changelog 0.1.0)" "$(composer_json)"
git clone -q "$read_only_repository" "$directory/created-with-a-readme" 2>/dev/null
printf '# php-server-sdk\n' > "$directory/created-with-a-readme/README.md"
git -C "$directory/created-with-a-readme" add .
git -C "$directory/created-with-a-readme" commit -q -m "Initial commit"
git -C "$directory/created-with-a-readme" push -q origin HEAD:main
run_push_from "$clone" server-sdk v0.1.0
assert_push_refused_with "rejected"
assert_in_the_read_only_repository "Initial commit" log --format=%s main
assert_in_the_read_only_repository "" tag --list
pass

scenario=moves_nothing_in_the_read_only_repository_when_its_tag_cannot_be_created
repository_with server-sdk "$(version_php 0.1.0)" "$(released_changelog 0.1.0)" "$(composer_json)"
run_push_from "$clone" server-sdk v0.1.0
first_release=$(in_the_read_only_repository rev-parse main)
commit packages/server-sdk/src/Client.php "<?php" "Add a client"
git clone -q "$directory/origin" "$directory/later-checkout" 2>/dev/null
run_push_from "$directory/later-checkout" server-sdk v0.1.0
assert_push_refused_with "rejected"
assert_in_the_read_only_repository "$first_release" rev-parse main
assert_in_the_read_only_repository "$first_release" rev-parse "v0.1.0^{commit}"
pass

scenario=refuses_a_checkout_without_the_full_history
repository_with server-sdk "$(version_php 0.1.0)" "$(released_changelog 0.1.0)" "$(composer_json)"
commit packages/server-sdk/src/Client.php "<?php" "Add a client"
git clone -q --depth 1 "file://$directory/origin" "$directory/shallow-checkout" 2>/dev/null
run_push_from "$directory/shallow-checkout" server-sdk v0.1.0
assert_push_refused_with "needs the full history"
assert_in_the_read_only_repository "" for-each-ref
pass

scenario=the_archive_of_the_server_sdk_holds_what_an_application_runs_and_reads_and_no_tests
assert_archive_of_package server-sdk "CHANGELOG.md
LICENSE
README.md
bin
composer.json
src"
pass

scenario=the_archive_of_the_openfeature_server_provider_holds_what_an_application_runs_and_reads_and_no_tests
assert_archive_of_package openfeature-server-provider "CHANGELOG.md
LICENSE
README.md
composer.json
src"
pass

scenario=the_server_sdk_carries_the_license_of_this_repository
cmp -s "$repository_root/LICENSE" "$repository_root/packages/server-sdk/LICENSE" \
    || fail "packages/server-sdk/LICENSE is not a copy of LICENSE"
pass

scenario=the_openfeature_server_provider_carries_the_license_of_this_repository
cmp -s "$repository_root/LICENSE" "$repository_root/packages/openfeature-server-provider/LICENSE" \
    || fail "packages/openfeature-server-provider/LICENSE is not a copy of LICENSE"
pass

if [ "$failures" -ne 0 ]; then
    printf '\n%s scenario(s) failed\n' "$failures"
    exit 1
fi
