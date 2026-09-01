#!/usr/bin/env bash

set -euo pipefail

workflow=.github/workflows/release.yaml

grep -Eq '^[[:space:]]+release:$' "${workflow}"
grep -Eq '^[[:space:]]+- published$' "${workflow}"
if grep -Eq '^[[:space:]]+push:$' "${workflow}"; then
	echo "fresh releases must have one trigger; tag pushes cannot also publish" >&2
	exit 1
fi

grep -Fq "ref: \${{ github.event.release.tag_name || github.ref }}" "${workflow}"
grep -Fq "RELEASE_EVENT_TAG: \${{ github.event.release.tag_name || '' }}" "${workflow}"
grep -Eq '^[[:space:]]+tag\|commit\)' "${workflow}"
grep -Fq 'RELEASE_REF_PROTECTED' "${workflow}"

echo "release trigger policy tests passed"
