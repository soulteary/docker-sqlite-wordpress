#!/usr/bin/env bash
set -euo pipefail

mapfile -t project_mu_plugins < <(
	sed -nE 's#^COPY[[:space:]]+([^[:space:]]+)[[:space:]]+\$\{WORDPRESS_PREPARE_DIR\}/wp-content/mu-plugins/[^[:space:]]+\.php$#\1#p' Dockerfile
)

if (( ${#project_mu_plugins[@]} == 0 )); then
	echo "No project-owned MU Plugin sources were found in Dockerfile." >&2
	exit 1
fi

for plugin in "${project_mu_plugins[@]}"; do
	if [[ ! -f "${plugin}" ]]; then
		echo "Project-owned MU Plugin source is missing: ${plugin}" >&2
		exit 1
	fi
	if ! grep -Eq '^[[:space:]]*\*[[:space:]]+Plugin Name:[[:space:]]+[^[:space:]]' "${plugin}"; then
		echo "Project-owned MU Plugin is missing Plugin Name metadata: ${plugin}" >&2
		exit 1
	fi
	if ! grep -Eq '^[[:space:]]*\*[[:space:]]+Author:[[:space:]]+soulteary[[:space:]]*$' "${plugin}"; then
		echo "Project-owned MU Plugin is not attributed to soulteary: ${plugin}" >&2
		exit 1
	fi
	if ! grep -Eq '^[[:space:]]*\*[[:space:]]+Author URI:[[:space:]]+https://soulteary\.com[[:space:]]*$' "${plugin}"; then
		echo "Project-owned MU Plugin has an invalid author URI: ${plugin}" >&2
		exit 1
	fi
	if ! grep -Eq '^[[:space:]]*\*[[:space:]]+Plugin URI:[[:space:]]+https://github\.com/soulteary/docker-sqlite-wordpress[[:space:]]*$' "${plugin}"; then
		echo "Project-owned MU Plugin has an invalid plugin URI: ${plugin}" >&2
		exit 1
	fi
done

echo "Project-owned MU Plugin metadata is valid."
