#!/usr/bin/env bash

set -Eeuo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${repo_root}"

release_version="$(sed -nE 's/^ARG IMAGE_VERSION=([^[:space:]]+)$/\1/p' Dockerfile)"
if [[ -z "${release_version}" ]]; then
	echo "could not determine IMAGE_VERSION from Dockerfile" >&2
	exit 1
fi

pending_marker='<!-- release-availability: pending -->'
if grep -Fq "${pending_marker}" README.md; then
	grep -Fq "\`${release_version}\` is prepared but is not published yet" README.md
	grep -Fq 'docker build -t sqlite-wordpress:main .' README.md
	grep -Fq 'image: sqlite-wordpress:main' docker-compose.yml
	grep -Eq '^[[:space:]]+build:$' docker-compose.yml
	echo "release ${release_version} is explicitly documented as pending"
	exit 0
fi

if ! git show-ref --verify --quiet "refs/tags/${release_version}"; then
	echo "README advertises ${release_version}, but the Git tag does not exist" >&2
	exit 1
fi

grep -Fq "image: soulteary/sqlite-wordpress:${release_version}" docker-compose.yml
./scripts/verify-published-release.sh "${release_version}"

echo "published release availability checks passed"
