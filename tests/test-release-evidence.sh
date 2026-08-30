#!/usr/bin/env bash

set -Eeuo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${repo_root}"

expected='["linux/amd64","linux/arm/v5","linux/arm/v6","linux/arm/v7","linux/arm64"]'

valid_sbom='{
  "linux/amd64":{"SPDX":{"spdxVersion":"SPDX-2.3"}},
  "linux/arm/v5":{"SPDX":{"spdxVersion":"SPDX-2.3"}},
  "linux/arm/v6":{"SPDX":{"spdxVersion":"SPDX-2.3"}},
  "linux/arm/v7":{"SPDX":{"spdxVersion":"SPDX-2.3"}},
  "linux/arm64":{"SPDX":{"spdxVersion":"SPDX-2.3"}}
}'
valid_provenance='{
  "linux/amd64":{"SLSA":{"buildType":"https://mobyproject.org/buildkit@v1"}},
  "linux/arm/v5":{"SLSA":{"buildType":"https://mobyproject.org/buildkit@v1"}},
  "linux/arm/v6":{"SLSA":{"buildType":"https://mobyproject.org/buildkit@v1"}},
  "linux/arm/v7":{"SLSA":{"buildType":"https://mobyproject.org/buildkit@v1"}},
  "linux/arm64":{"SLSA":{"buildType":"https://mobyproject.org/buildkit@v1"}}
}'

bash ./scripts/validate-buildx-evidence.sh SPDX "${expected}" <<< "${valid_sbom}"
bash ./scripts/validate-buildx-evidence.sh SLSA "${expected}" <<< "${valid_provenance}"

missing_platform="$(jq 'del(.["linux/arm/v5"])' <<< "${valid_sbom}")"
if bash ./scripts/validate-buildx-evidence.sh SPDX "${expected}" \
	<<< "${missing_platform}" >/dev/null 2>&1; then
	echo "evidence with a missing platform unexpectedly passed" >&2
	exit 1
fi

empty_evidence="$(jq '.["linux/arm64"].SLSA = {}' <<< "${valid_provenance}")"
if bash ./scripts/validate-buildx-evidence.sh SLSA "${expected}" \
	<<< "${empty_evidence}" >/dev/null 2>&1; then
	echo "empty per-platform evidence unexpectedly passed" >&2
	exit 1
fi

grep -Fq -- "--format '{{ json .Provenance }}'" .github/workflows/release.yaml
if grep -Fq '.Provenance.SLSA' .github/workflows/release.yaml; then
	echo "multi-platform provenance must be read before selecting each SLSA value" >&2
	exit 1
fi

echo "release evidence validation tests passed"
