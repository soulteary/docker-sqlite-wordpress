#!/usr/bin/env bash

set -Eeuo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${repo_root}"

failures=0
while IFS= read -r -d '' markdown_file; do
	while IFS= read -r target; do
		case "${target}" in
			""|\#*|http://*|https://*|mailto:*) continue ;;
		esac

		local_target="${target%%#*}"
		if [[ ! -e "$(dirname "${markdown_file}")/${local_target}" ]]; then
			echo "broken local Markdown link in ${markdown_file}: ${target}" >&2
			failures=$((failures + 1))
		fi
	done < <(grep -oE '\]\([^)]*\)' "${markdown_file}" | sed -e 's/^](//' -e 's/)$//' || true)
done < <(find . -type f -name '*.md' \
	-not -path './.git/*' \
	-not -path './wordpress/*' \
	-print0)

mapfile -t release_versions < <(
	sed -nE 's/^ARG WORDPRESS_IMAGE=wordpress:([0-9]+\.[0-9]+\.[0-9]+)-php.*$/\1/p' Dockerfile \
		| sort -u
)
if [[ "${#release_versions[@]}" -ne 1 ]]; then
	echo "could not determine one release version from Dockerfile" >&2
	exit 1
fi
release_version="${release_versions[0]}"
./scripts/validate-release.sh "${release_version}" >/dev/null
for release_doc in CONTRIBUTING.md RELEASING.md; do
	if ! grep -Fq "./scripts/validate-release.sh ${release_version}" "${release_doc}"; then
		echo "${release_doc} does not use release version ${release_version}" >&2
		failures=$((failures + 1))
	fi
done

for stale_marker in \
	"sqlite-wordpress:site-url-recovery" \
	"Until the next release, build the current repository"
do
	if grep -Fq "${stale_marker}" README.md docker-compose.yml; then
		echo "stale pre-release recovery guidance remains: ${stale_marker}" >&2
		failures=$((failures + 1))
	fi
done

if [[ "${failures}" -ne 0 ]]; then
	exit 1
fi

echo "documentation consistency tests passed"
