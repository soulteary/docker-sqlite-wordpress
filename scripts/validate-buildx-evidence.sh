#!/usr/bin/env bash

set -Eeuo pipefail

field="${1:-}"
expected_platforms="${2:-}"

if [[ "${field}" != "SPDX" && "${field}" != "SLSA" ]] \
	|| ! jq -e 'type == "array" and length > 0 and all(.[]; type == "string" and length > 0)' \
		<<< "${expected_platforms}" >/dev/null 2>&1; then
	echo "usage: $0 SPDX|SLSA EXPECTED_PLATFORMS_JSON" >&2
	exit 2
fi

if ! jq -e \
	--arg field "${field}" \
	--argjson expected "${expected_platforms}" \
	'type == "object"
	 and (keys | sort) == ($expected | sort)
	 and all(.[]; .[$field] | type == "object" and length > 0)' \
	>/dev/null; then
	echo "evidence must contain one non-empty ${field} object for every expected platform" >&2
	exit 1
fi
