#!/usr/bin/env bash

set -euo pipefail

release_version="${1:-}"
if [[ ! "${release_version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "release version must be a stable semantic version (for example, 7.1.0)" >&2
	exit 1
fi

mapfile -t base_images < <(sed -nE 's/^FROM (wordpress:[^[:space:]]+).*$/\1/p' Dockerfile)
if [[ "${#base_images[@]}" -ne 2 ]]; then
	echo "expected exactly two official WordPress base-image references" >&2
	exit 1
fi

if [[ "${base_images[0]}" != "${base_images[1]}" ]]; then
	echo "builder and runtime stages must use the same WordPress image" >&2
	exit 1
fi

base_image="${base_images[0]}"
if [[ ! "${base_image}" =~ ^wordpress:${release_version}-php[0-9]+\.[0-9]+-apache$ ]]; then
	echo "base image ${base_image} does not match release ${release_version}" >&2
	exit 1
fi

php_version="${base_image#wordpress:"${release_version}"-php}"
php_version="${php_version%-apache}"
plugin_version="$(sed -nE 's/^ARG SQLITE_DATABASE_INTEGRATION_VERSION=([^[:space:]]+)$/\1/p' Dockerfile)"
if [[ ! "${plugin_version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "SQLite Database Integration must use a stable semantic version" >&2
	exit 1
fi

grep -Fq "WordPress \`${release_version}\` on PHP ${php_version}/Apache" README.md || {
	echo "README runtime version does not match ${base_image}" >&2
	exit 1
}
grep -Fq "soulteary/sqlite-wordpress:${release_version}" README.md || {
	echo "README does not contain the ${release_version} image tag" >&2
	exit 1
}
grep -Fq "image: soulteary/sqlite-wordpress:${release_version}" docker-compose.yml || {
	echo "docker-compose.yml does not use the ${release_version} image tag" >&2
	exit 1
}
grep -Fq "## [${release_version}]" CHANGELOG.md || {
	echo "CHANGELOG.md does not contain a ${release_version} release section" >&2
	exit 1
}

printf 'release_version=%s\nbase_image=%s\nplugin_version=%s\n' \
	"${release_version}" "${base_image}" "${plugin_version}"
