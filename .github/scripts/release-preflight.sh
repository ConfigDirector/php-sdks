#!/usr/bin/env sh
#
# Decides what the release workflow releases for one package, and fails when nothing must be
# released: reads the `CURRENT` constant of the package's `Version` class, refuses a version whose
# tag already exists in the package's read-only repository or in this one, refuses a package that
# requires a development version of another package, and takes the release notes from the
# package's CHANGELOG.md section for that version.
# Writes `version`, `read-only-repository-tag`, `monorepo-tag` and `prerelease` to $GITHUB_OUTPUT
# when it is set, and the notes to the given file. `make test-release` runs it against throwaway
# repositories.
#
# Usage: release-preflight.sh <package> <read-only-repository-url> <notes-file>, from the
# repository root.

set -eu

package=${1:-}
read_only_repository_url=${2:-}
notes_file=${3:-}

if [ -z "$package" ] || [ -z "$read_only_repository_url" ] || [ -z "$notes_file" ]; then
    echo "usage: $0 <package> <read-only-repository-url> <notes-file>" >&2
    exit 2
fi

version_file="packages/$package/src/Version.php"
changelog="packages/$package/CHANGELOG.md"
manifest="packages/$package/composer.json"

for file in "$version_file" "$changelog" "$manifest"; do
    if [ ! -f "$file" ]; then
        echo "Cannot find $file. Run this from the repository root." >&2
        exit 1
    fi
done

version=$(sed -n "s/^    public const string CURRENT = '\([^']*\)';\$/\1/p" "$version_file")

if [ -z "$version" ]; then
    echo "Could not read the CURRENT constant from $version_file." >&2
    exit 1
fi

if ! printf '%s\n' "$version" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+(-(alpha|beta|rc)\.[0-9]+)?$'; then
    echo "Version '$version' must be X.Y.Z with no v prefix, such as 1.2.3, or X.Y.Z-alpha.N," >&2
    echo "X.Y.Z-beta.N or X.Y.Z-rc.N. Packagist skips a tag that Composer cannot read as a version," >&2
    echo "so a release takes these forms only. Set CURRENT in $version_file." >&2
    exit 1
fi

case "$version" in
    *-*) prerelease=true ;;
    *) prerelease=false ;;
esac

read_only_repository_tag="v$version"
monorepo_tag="$package/v$version"

if ! tags_in_the_read_only_repository=$(git ls-remote --tags "$read_only_repository_url" "refs/tags/$read_only_repository_tag"); then
    echo "Could not read the tags of $read_only_repository_url. Create the read-only repository of" >&2
    echo "$package first: see Releasing in CONTRIBUTING.md." >&2
    exit 1
fi

if [ -n "$tags_in_the_read_only_repository" ]; then
    echo "$read_only_repository_tag already exists in $read_only_repository_url, so $package $version has been" >&2
    echo "released before, and Packagist serves it from that tag. To ship a change, bump CURRENT in" >&2
    echo "$version_file, open a new $changelog section, and run this again." >&2
    echo "To finish a release that stopped after its push, see Releasing in CONTRIBUTING.md." >&2
    exit 1
fi

if ! tags_in_this_repository=$(git ls-remote --tags origin "refs/tags/$monorepo_tag"); then
    echo "Could not read the tags of this repository's origin." >&2
    exit 1
fi

if [ -n "$tags_in_this_repository" ]; then
    echo "$monorepo_tag already exists in this repository, while $read_only_repository_url has no" >&2
    echo "$read_only_repository_tag. A release tags this repository last, so that tag was made by hand. Bump" >&2
    echo "CURRENT in $version_file, open a new $changelog section, and run this again." >&2
    exit 1
fi

# shellcheck disable=SC2016
development_requirements=$(php -r '
    $manifest = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    foreach ($manifest["require"] ?? [] as $name => $constraint) {
        if (str_contains($constraint, "dev")) {
            echo "  $name at $constraint\n";
        }
    }
' "$manifest")

if [ -n "$development_requirements" ]; then
    echo "$manifest requires a development version, which an application cannot install:" >&2
    echo "$development_requirements" >&2
    echo "Composer ignores the repositories of a package it installs as a dependency, the path" >&2
    echo "repository of this working tree included. Require a released version before releasing" >&2
    echo "$package." >&2
    exit 1
fi

heading="## [$version]"

if ! awk -v heading="$heading" '
    /^## / {
        if (inside) exit
        inside = ($0 == heading || index($0, heading " ") == 1)
        if (inside) found = 1
        next
    }
    inside { lines[++count] = $0 }
    END {
        if (!found) exit 3
        first = 1
        while (first <= count && lines[first] ~ /^[[:space:]]*$/) first++
        last = count
        while (last >= first && lines[last] ~ /^[[:space:]]*$/) last--
        for (i = first; i <= last; i++) print lines[i]
    }
' "$changelog" > "$notes_file"; then
    rm -f "$notes_file"
    echo "$changelog has no '$heading' section. Rename its '## [Unreleased]' heading to" >&2
    echo "'$heading - YYYY-MM-DD' and open a fresh one above it before releasing." >&2
    exit 1
fi

if [ ! -s "$notes_file" ]; then
    rm -f "$notes_file"
    echo "The '$heading' section in $changelog is empty, so there are no release notes to publish." >&2
    exit 1
fi

if [ -n "${GITHUB_OUTPUT:-}" ]; then
    {
        echo "version=$version"
        echo "read-only-repository-tag=$read_only_repository_tag"
        echo "monorepo-tag=$monorepo_tag"
        echo "prerelease=$prerelease"
    } >> "$GITHUB_OUTPUT"
fi

echo "Releasing $package $version as $read_only_repository_tag in $read_only_repository_url and as"
echo "$monorepo_tag in this repository, with these notes:"
echo
cat "$notes_file"
