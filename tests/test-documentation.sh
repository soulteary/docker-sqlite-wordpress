#!/usr/bin/env bash

set -Eeuo pipefail

export LC_ALL=C

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${repo_root}"

markdown_visible_lines() {
	awk '
		{
			line = $0
			stripped = line
			sub(/^[[:space:]]*/, "", stripped)
			marker = ""
			if (substr(stripped, 1, 3) == "```") {
				marker = "```"
			} else if (substr(stripped, 1, 3) == "~~~") {
				marker = "~~~"
			}
			if (marker != "") {
				if (fence == "") {
					fence = marker
				} else if (marker == fence) {
					fence = ""
				}
				next
			}
			if (fence == "") {
				print line
			}
		}
	' "$1"
}

markdown_targets() {
	markdown_visible_lines "$1" \
		| { grep -oE '\]\([^)]*\)' || true; } \
		| sed -e 's/^](//' -e 's/)$//'
}

github_heading_slug() {
	local heading="$1"

	heading="${heading,,}"
	printf '%s\n' "${heading}" \
		| sed -E \
			-e 's/<[^>]*>//g' \
			-e 's/[^[:alnum:] _-]//g' \
			-e 's/[[:space:]]+/-/g' \
			-e 's/^-+//' \
			-e 's/-+$//'
}

markdown_anchor_exists() {
	local markdown_file="$1"
	local wanted_anchor="$2"
	local line heading base_anchor anchor duplicate_count
	declare -A anchor_counts=()

	while IFS= read -r line; do
		if [[ ! "${line}" =~ ^#{1,6}[[:space:]]+(.+)$ ]]; then
			continue
		fi

		heading="${BASH_REMATCH[1]}"
		heading="$(sed -E 's/[[:space:]]+#+[[:space:]]*$//' <<< "${heading}")"
		base_anchor="$(github_heading_slug "${heading}")"
		if [[ -z "${base_anchor}" ]]; then
			continue
		fi

		duplicate_count="${anchor_counts["${base_anchor}"]:-0}"
		anchor="${base_anchor}"
		if (( duplicate_count > 0 )); then
			anchor="${base_anchor}-${duplicate_count}"
		fi
		anchor_counts["${base_anchor}"]=$((duplicate_count + 1))

		if [[ "${anchor}" == "${wanted_anchor}" ]]; then
			return 0
		fi
	done < <(markdown_visible_lines "${markdown_file}")

	return 1
}

failures=0
documentation_fixture="$(mktemp)"
trap 'rm -f -- "${documentation_fixture}"' EXIT
printf '%s\n' \
	'# Visible heading' \
	'[visible](#visible-heading)' \
	'```markdown' \
	'# Hidden heading' \
	'[example only](./does-not-exist.md)' \
	'```' \
	> "${documentation_fixture}"
if markdown_targets "${documentation_fixture}" | grep -Fq './does-not-exist.md'; then
	echo "fenced Markdown example was treated as a live link" >&2
	failures=$((failures + 1))
fi
if ! markdown_anchor_exists "${documentation_fixture}" 'visible-heading' \
	|| markdown_anchor_exists "${documentation_fixture}" 'hidden-heading'; then
	echo "fenced Markdown headings were not filtered correctly" >&2
	failures=$((failures + 1))
fi

while IFS= read -r -d '' markdown_file; do
	while IFS= read -r target; do
		if [[ -z "${target}" || "${target}" =~ ^[A-Za-z][A-Za-z0-9+.-]*: ]]; then
			continue
		fi

		if [[ "${target}" == '<'*'>' ]]; then
			target="${target#<}"
			target="${target%>}"
		elif [[ "${target}" =~ ^([^[:space:]]+)[[:space:]]+[\"\'] ]]; then
			target="${BASH_REMATCH[1]}"
		fi

		local_target="${target%%#*}"
		fragment=""
		if [[ "${target}" == *#* ]]; then
			fragment="${target#*#}"
		fi
		local_target="${local_target%%\?*}"

		if [[ -z "${local_target}" ]]; then
			linked_file="${markdown_file}"
		else
			linked_file="$(dirname "${markdown_file}")/${local_target}"
		fi

		if [[ ! -e "${linked_file}" ]]; then
			echo "broken local Markdown link in ${markdown_file}: ${target}" >&2
			failures=$((failures + 1))
			continue
		fi

		if [[ -n "${fragment}" && "${linked_file}" == *.md ]] \
			&& ! markdown_anchor_exists "${linked_file}" "${fragment}"; then
			echo "broken Markdown anchor in ${markdown_file}: ${target}" >&2
			failures=$((failures + 1))
		fi
	done < <(markdown_targets "${markdown_file}")
done < <(find . -type f -name '*.md' \
	-not -path './.git/*' \
	-not -path './wordpress/*' \
	-print0)

mapfile -t release_versions < <(
	sed -nE 's/^ARG IMAGE_VERSION=([^[:space:]]+)$/\1/p' Dockerfile \
		| sort -u
)
if [[ "${#release_versions[@]}" -ne 1 ]]; then
	echo "could not determine one image release version from Dockerfile" >&2
	exit 1
fi
release_version="${release_versions[0]}"
if [[ ! "${release_version}" =~ ^[0-9]{4}\.(0[1-9]|1[0-2])\.(0[1-9]|[12][0-9]|3[01])-r[1-9][0-9]*$ ]]; then
	echo "Dockerfile IMAGE_VERSION is not CalVer: ${release_version}" >&2
	failures=$((failures + 1))
fi

for release_doc in CONTRIBUTING.md RELEASING.md; do
	if ! grep -Fq "./scripts/validate-release.sh ${release_version}" "${release_doc}"; then
		echo "${release_doc} does not use image release ${release_version}" >&2
		failures=$((failures + 1))
	fi
done
if ! grep -Fq "soulteary/sqlite-wordpress:${release_version}" README.md; then
	echo "README.md does not reference image release ${release_version}" >&2
	failures=$((failures + 1))
fi

if grep -Fq '<!-- release-availability: pending -->' README.md; then
	grep -Fq 'image: sqlite-wordpress:main' docker-compose.yml || {
		echo "pending release documentation must use the local main image" >&2
		failures=$((failures + 1))
	}
else
	grep -Fq "image: soulteary/sqlite-wordpress:${release_version}" docker-compose.yml || {
		echo "docker-compose.yml does not use image release ${release_version}" >&2
		failures=$((failures + 1))
	}
fi

for required_build_guidance in \
	'TARGET_PLATFORM=linux/amd64' \
	'--load' \
	'--output type=oci,dest=' \
	'bash tests/test-documentation.sh'
do
	if ! grep -Fq -- "${required_build_guidance}" CONTRIBUTING.md; then
		echo "CONTRIBUTING.md is missing: ${required_build_guidance}" >&2
		failures=$((failures + 1))
	fi
done

if [[ "${failures}" -ne 0 ]]; then
	exit 1
fi

echo "documentation consistency tests passed"
