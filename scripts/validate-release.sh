#!/usr/bin/env bash

set -euo pipefail

release_version="${1:-}"
if [[ ! "${release_version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "release version must be a stable semantic version (for example, 7.1.0)" >&2
	exit 1
fi

verify_upstream=false
case "${2:-}" in
	"") ;;
	--verify-upstream) verify_upstream=true ;;
	*)
		echo "usage: $0 RELEASE_VERSION [--verify-upstream]" >&2
		exit 1
		;;
esac
if [[ "$#" -gt 2 ]]; then
	echo "usage: $0 RELEASE_VERSION [--verify-upstream]" >&2
	exit 1
fi

wordpress_image="$(sed -nE 's/^ARG WORDPRESS_IMAGE=([^[:space:]]+)$/\1/p' Dockerfile)"
if [[ -z "${wordpress_image}" ]]; then
	echo "WORDPRESS_IMAGE must be defined" >&2
	exit 1
fi

mapfile -t base_images < <(sed -nE 's/^FROM \$\{WORDPRESS_IMAGE\}.*$/WORDPRESS_IMAGE/p' Dockerfile)
if [[ "${#base_images[@]}" -ne 2 ]]; then
	echo "expected exactly two stages based on WORDPRESS_IMAGE" >&2
	exit 1
fi

if [[ ! "${wordpress_image}" =~ ^wordpress:[^@[:space:]]+@sha256:[0-9a-f]{64}$ ]]; then
	echo "WORDPRESS_IMAGE must pin an official image tag and sha256 digest" >&2
	exit 1
fi

base_image="${wordpress_image%@sha256:*}"
if [[ ! "${base_image}" =~ ^wordpress:${release_version}-php[0-9]+\.[0-9]+-apache$ ]]; then
	echo "base image ${base_image} does not match release ${release_version}" >&2
	exit 1
fi

pinned_digest="${wordpress_image##*@}"
if ${verify_upstream}; then
	for command_name in docker jq; do
		if ! command -v "${command_name}" >/dev/null 2>&1; then
			echo "${command_name} is required for upstream image verification" >&2
			exit 1
		fi
	done

	manifest_json=""
	for attempt in 1 2 3; do
		if manifest_json="$(
			docker buildx imagetools inspect "${base_image}" \
				--format '{{json .Manifest}}'
		)"; then
			break
		fi
		if [[ "${attempt}" -eq 3 ]]; then
			echo "unable to resolve official image tag ${base_image}" >&2
			exit 1
		fi
		sleep "$((attempt * 2))"
	done

	if ! resolved_digest="$(
		jq -er '.digest | select(type == "string" and test("^sha256:[0-9a-f]{64}$"))' \
			<<< "${manifest_json}"
	)"; then
		echo "unable to read the registry digest for ${base_image}" >&2
		exit 1
	fi
	if [[ "${resolved_digest}" != "${pinned_digest}" ]]; then
		echo "${base_image} resolves to ${resolved_digest}, not pinned digest ${pinned_digest}" >&2
		exit 1
	fi
fi

php_version="${base_image#wordpress:"${release_version}"-php}"
php_version="${php_version%-apache}"
plugin_version="$(sed -nE 's/^ARG SQLITE_DATABASE_INTEGRATION_VERSION=([^[:space:]]+)$/\1/p' Dockerfile)"
if [[ ! "${plugin_version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "SQLite Database Integration must use a stable semantic version" >&2
	exit 1
fi

plugin_commit="$(sed -nE 's/^ARG SQLITE_DATABASE_INTEGRATION_COMMIT=([^[:space:]]+)$/\1/p' Dockerfile)"
if [[ ! "${plugin_commit}" =~ ^[0-9a-f]{40}$ ]]; then
	echo "SQLite Database Integration must pin a full commit SHA" >&2
	exit 1
fi

rust_toolchain="$(sed -nE 's/^ARG RUST_TOOLCHAIN_VERSION=([^[:space:]]+)$/\1/p' Dockerfile)"
if [[ ! "${rust_toolchain}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "Rust toolchain must use an exact stable version" >&2
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
grep -Fq "org.opencontainers.image.version=\"${release_version}\"" Dockerfile || {
	echo "OCI image version does not match ${release_version}" >&2
	exit 1
}
grep -Fq "## [${release_version}]" CHANGELOG.md || {
	echo "CHANGELOG.md does not contain a ${release_version} release section" >&2
	exit 1
}

printf 'release_version=%s\nwordpress_image=%s\nplugin_version=%s\nplugin_commit=%s\nrust_toolchain=%s\n' \
	"${release_version}" "${wordpress_image}" "${plugin_version}" "${plugin_commit}" "${rust_toolchain}"
if ${verify_upstream}; then
	printf 'wordpress_upstream_digest=%s\n' "${resolved_digest}"
fi
