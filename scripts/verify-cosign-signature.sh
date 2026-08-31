#!/usr/bin/env bash

set -Eeuo pipefail

image_ref="${1:-}"
certificate_identity="${2:-}"
certificate_issuer="${3:-}"
attempts="${COSIGN_VERIFY_ATTEMPTS:-12}"
delay_seconds="${COSIGN_VERIFY_DELAY_SECONDS:-10}"

if [[ -z "${image_ref}" || -z "${certificate_identity}" || -z "${certificate_issuer}" ]] \
	|| [[ ! "${attempts}" =~ ^[1-9][0-9]*$ ]] \
	|| [[ ! "${delay_seconds}" =~ ^[0-9]+$ ]]; then
	echo "usage: $0 IMAGE_REF CERTIFICATE_IDENTITY CERTIFICATE_ISSUER" >&2
	exit 2
fi

verify_log="$(mktemp)"
trap 'rm -f "${verify_log}"' EXIT

for ((attempt = 1; attempt <= attempts; attempt++)); do
	: > "${verify_log}"
	verify_status=0
	if cosign verify \
		--certificate-identity "${certificate_identity}" \
		--certificate-oidc-issuer "${certificate_issuer}" \
		"${image_ref}" > /dev/null 2>"${verify_log}"; then
		exit 0
	else
		verify_status=$?
	fi

	if ! grep -Fqi 'no signatures found' "${verify_log}"; then
		cat "${verify_log}" >&2
		exit "${verify_status}"
	fi
	if [[ "${attempt}" -eq "${attempts}" ]]; then
		cat "${verify_log}" >&2
		echo "signature referrer for ${image_ref} was not visible after ${attempts} attempts" >&2
		exit "${verify_status}"
	fi

	echo "signature referrer for ${image_ref} is not visible yet; retrying in ${delay_seconds}s (${attempt}/${attempts})" >&2
	sleep "${delay_seconds}"
done
