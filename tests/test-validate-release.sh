#!/usr/bin/env bash

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${repo_root}"

fake_bin="$(mktemp -d)"
trap 'rm -rf "${fake_bin}"' EXIT

cat > "${fake_bin}/docker" <<'SCRIPT'
#!/usr/bin/env bash
set -euo pipefail

if [[ "$#" -ne 6 ]] \
	|| [[ "$1" != "buildx" ]] \
	|| [[ "$2" != "imagetools" ]] \
	|| [[ "$3" != "inspect" ]] \
	|| [[ "$4" != "${FAKE_EXPECTED_REF}" ]] \
	|| [[ "$5" != "--format" ]]; then
	echo "unexpected docker invocation: $*" >&2
	exit 1
fi
printf '{"digest":"%s"}\n' "${FAKE_MANIFEST_DIGEST}"
SCRIPT
chmod +x "${fake_bin}/docker"

wordpress_image="$(sed -nE 's/^ARG WORDPRESS_IMAGE=([^[:space:]]+)$/\1/p' Dockerfile)"
base_image="${wordpress_image%@sha256:*}"
pinned_digest="${wordpress_image##*@}"

PATH="${fake_bin}:${PATH}" \
	FAKE_EXPECTED_REF="${base_image}" \
	FAKE_MANIFEST_DIGEST="${pinned_digest}" \
	./scripts/validate-release.sh 2026.08.30-r1 --verify-upstream >/dev/null

mismatch_digest="sha256:0000000000000000000000000000000000000000000000000000000000000000"
if PATH="${fake_bin}:${PATH}" \
	FAKE_EXPECTED_REF="${base_image}" \
	FAKE_MANIFEST_DIGEST="${mismatch_digest}" \
	./scripts/validate-release.sh 2026.08.30-r1 --verify-upstream \
	>/dev/null 2>"${fake_bin}/mismatch.log"; then
	echo "digest mismatch unexpectedly passed" >&2
	exit 1
fi
grep -Fq "not pinned digest ${pinned_digest}" "${fake_bin}/mismatch.log"

for invalid_version in 7.1.0 2026.8.30-r1 2026.02.30-r1 2026.08.30-r0; do
	if ./scripts/validate-release.sh "${invalid_version}" >/dev/null 2>"${fake_bin}/invalid.log"; then
		echo "invalid release version unexpectedly passed: ${invalid_version}" >&2
		exit 1
	fi
done

echo "release validation digest tests passed"
