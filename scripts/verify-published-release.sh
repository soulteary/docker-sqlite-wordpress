#!/usr/bin/env bash

set -Eeuo pipefail

release_version="${1:-}"
if [[ ! "${release_version}" =~ ^[0-9]{4}\.[0-9]{2}\.[0-9]{2}-r[1-9][0-9]*$ ]]; then
	echo "usage: $0 YYYY.MM.DD-rN" >&2
	exit 1
fi

for command_name in curl jq; do
	if ! command -v "${command_name}" >/dev/null 2>&1; then
		echo "${command_name} is required to verify a published release" >&2
		exit 1
	fi
done

curl_args=(
	--proto '=https'
	--tlsv1.2
	--retry 3
	--retry-all-errors
	--connect-timeout 10
	--max-time 60
	--fail
	--silent
	--show-error
)

dockerhub_json="$(
	curl "${curl_args[@]}" \
		"https://hub.docker.com/v2/repositories/soulteary/sqlite-wordpress/tags/${release_version}"
)"
dockerhub_digest="$(
	jq -er '.digest | select(type == "string" and test("^sha256:[0-9a-f]{64}$"))' \
		<<< "${dockerhub_json}"
)"

ghcr_token="$(
	curl "${curl_args[@]}" \
		"https://ghcr.io/token?service=ghcr.io&scope=repository:soulteary/sqlite-wordpress:pull" \
		| jq -er '.token | select(type == "string" and length > 0)'
)"
ghcr_headers="$(
	curl "${curl_args[@]}" \
		--dump-header - \
		--output /dev/null \
		--header "Authorization: Bearer ${ghcr_token}" \
		--header 'Accept: application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json' \
		"https://ghcr.io/v2/soulteary/sqlite-wordpress/manifests/${release_version}"
)"
ghcr_digest="$(
	awk 'tolower($1) == "docker-content-digest:" { gsub("\r", "", $2); print $2 }' \
		<<< "${ghcr_headers}" \
		| tail -n 1
)"

if [[ ! "${ghcr_digest}" =~ ^sha256:[0-9a-f]{64}$ ]]; then
	echo "GHCR did not return a valid manifest digest for ${release_version}" >&2
	exit 1
fi
if [[ "${dockerhub_digest}" != "${ghcr_digest}" ]]; then
	echo "registry digest mismatch for ${release_version}" >&2
	echo "Docker Hub: ${dockerhub_digest}" >&2
	echo "GHCR:       ${ghcr_digest}" >&2
	exit 1
fi

printf 'published_release=%s\nmanifest_digest=%s\n' \
	"${release_version}" "${dockerhub_digest}"
