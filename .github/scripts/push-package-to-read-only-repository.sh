#!/usr/bin/env sh
#
# Releases one package to its read-only repository, which Packagist reads: pushes the commits that
# changed the package's directory, with that directory as the root, to the `main` branch there,
# and puts the given tag on the last of them. The branch and the tag move together or not at all,
# and a branch that holds any other commit is never overwritten.
# `make test-release` runs it against throwaway repositories.
#
# Usage: push-package-to-read-only-repository.sh <package> <tag> <read-only-repository-url>, from
# the repository root of a checkout with the full history.

set -eu

package=${1:-}
tag=${2:-}
read_only_repository_url=${3:-}

if [ -z "$package" ] || [ -z "$tag" ] || [ -z "$read_only_repository_url" ]; then
    echo "usage: $0 <package> <tag> <read-only-repository-url>" >&2
    exit 2
fi

if [ "$(git rev-parse --is-shallow-repository)" = true ]; then
    echo "Releasing $package needs the full history of this repository, and this checkout is" >&2
    echo "shallow. The commits pushed from it would not be the ones $read_only_repository_url holds." >&2
    exit 1
fi

package_history=$(git subtree split --quiet --prefix="packages/$package" HEAD)

git tag --annotate "$tag" "$package_history" --message "$package $tag"
git push --atomic "$read_only_repository_url" "$package_history:refs/heads/main" "refs/tags/$tag"

echo "Pushed $package to $read_only_repository_url as $tag ($package_history)."
