#!/usr/bin/env bash

set -Eeuo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${repo_root}"

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
	local line stripped_line fence_marker="" current_marker heading base_anchor anchor duplicate_count
	declare -A anchor_counts=()

	while IFS= read -r line; do
		stripped_line="${line#"${line%%[![:space:]]*}"}"
		current_marker=""
		case "${stripped_line}" in
			'```'*) current_marker='```' ;;
			'~~~'*) current_marker='~~~' ;;
		esac
		if [[ -n "${current_marker}" ]]; then
			if [[ -z "${fence_marker}" ]]; then
				fence_marker="${current_marker}"
			elif [[ "${current_marker}" == "${fence_marker}" ]]; then
				fence_marker=""
			fi
			continue
		fi
		if [[ -n "${fence_marker}" ]]; then
			continue
		fi

		if [[ ! "${line}" =~ ^#{1,6}[[:space:]]+(.+)$ ]]; then
			continue
		fi

		heading="${BASH_REMATCH[1]}"
		heading="${heading% #}"
		base_anchor="$(github_heading_slug "${heading}")"
		if [[ -z "${base_anchor}" ]]; then
			continue
		fi

		duplicate_count="${anchor_counts[${base_anchor}]:-0}"
		anchor="${base_anchor}"
		if (( duplicate_count > 0 )); then
			anchor="${base_anchor}-${duplicate_count}"
		fi
		anchor_counts["${base_anchor}"]=$((duplicate_count + 1))

		if [[ "${anchor}" == "${wanted_anchor}" ]]; then
			return 0
		fi
	done < "${markdown_file}"

	return 1
}

failures=0
while IFS= read -r -d '' markdown_file; do
	while IFS= read -r target; do
		case "${target}" in
			""|http://*|https://*|mailto:*) continue ;;
		esac

		local_target="${target%%#*}"
		fragment=""
		if [[ "${target}" == *#* ]]; then
			fragment="${target#*#}"
		fi

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

		if [[ -n "${fragment}" ]] && ! markdown_anchor_exists "${linked_file}" "${fragment}"; then
			echo "broken Markdown anchor in ${markdown_file}: ${target}" >&2
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
