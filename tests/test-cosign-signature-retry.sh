#!/usr/bin/env bash

set -Eeuo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${repo_root}"

fake_bin="$(mktemp -d)"
trap 'rm -rf "${fake_bin}"' EXIT

cat > "${fake_bin}/cosign" <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail

count="$(<"${FAKE_COSIGN_COUNT_FILE}")"
count=$((count + 1))
printf '%s\n' "${count}" > "${FAKE_COSIGN_COUNT_FILE}"
printf '%s\n' "$*" >> "${FAKE_COSIGN_CALLS_FILE}"

case "${FAKE_COSIGN_MODE}" in
	eventual)
		if [[ "${count}" -lt "${FAKE_COSIGN_SUCCESS_AT}" ]]; then
			echo 'Error: no signatures found' >&2
			exit 10
		fi
		;;
	fatal)
		echo 'Error: certificate identity mismatch' >&2
		exit 1
		;;
	missing)
		echo 'Error: no signatures found' >&2
		exit 10
		;;
	*)
		echo "unexpected fake mode: ${FAKE_COSIGN_MODE}" >&2
		exit 2
		;;
esac
EOF
chmod +x "${fake_bin}/cosign"

counter="${fake_bin}/count"
calls="${fake_bin}/calls"
identity='https://github.com/soulteary/docker-sqlite-wordpress/.github/workflows/release.yaml@refs/tags/2026.08.31-r3'
issuer='https://token.actions.githubusercontent.com'
image_ref='soulteary/sqlite-wordpress@sha256:9716786a5213d89f0d77bbad5bd04723aad8791018d5a8811c5974df73eb40c1'

printf '0\n' > "${counter}"
: > "${calls}"
PATH="${fake_bin}:${PATH}" \
	FAKE_COSIGN_COUNT_FILE="${counter}" \
	FAKE_COSIGN_CALLS_FILE="${calls}" \
	FAKE_COSIGN_MODE=eventual \
	FAKE_COSIGN_SUCCESS_AT=3 \
	COSIGN_VERIFY_ATTEMPTS=3 \
	COSIGN_VERIFY_DELAY_SECONDS=0 \
	bash ./scripts/verify-cosign-signature.sh "${image_ref}" "${identity}" "${issuer}"
[[ "$(<"${counter}")" == 3 ]]
grep -Fq -- "verify --certificate-identity ${identity} --certificate-oidc-issuer ${issuer} ${image_ref}" "${calls}"

printf '0\n' > "${counter}"
: > "${calls}"
if PATH="${fake_bin}:${PATH}" \
	FAKE_COSIGN_COUNT_FILE="${counter}" \
	FAKE_COSIGN_CALLS_FILE="${calls}" \
	FAKE_COSIGN_MODE=fatal \
	COSIGN_VERIFY_ATTEMPTS=5 \
	COSIGN_VERIFY_DELAY_SECONDS=0 \
	bash ./scripts/verify-cosign-signature.sh "${image_ref}" "${identity}" "${issuer}" \
	>/dev/null 2>&1; then
	echo "non-transient verification failure unexpectedly retried to success" >&2
	exit 1
fi
[[ "$(<"${counter}")" == 1 ]]

printf '0\n' > "${counter}"
: > "${calls}"
if PATH="${fake_bin}:${PATH}" \
	FAKE_COSIGN_COUNT_FILE="${counter}" \
	FAKE_COSIGN_CALLS_FILE="${calls}" \
	FAKE_COSIGN_MODE=missing \
	COSIGN_VERIFY_ATTEMPTS=2 \
	COSIGN_VERIFY_DELAY_SECONDS=0 \
	bash ./scripts/verify-cosign-signature.sh "${image_ref}" "${identity}" "${issuer}" \
	>/dev/null 2>&1; then
	echo "permanently missing signature unexpectedly passed" >&2
	exit 1
fi
[[ "$(<"${counter}")" == 2 ]]

grep -Fq 'cosign-release: v3.1.3' .github/workflows/release.yaml
grep -Fq 'bash ./scripts/verify-cosign-signature.sh' .github/workflows/release.yaml

echo "cosign signature visibility retry tests passed"
