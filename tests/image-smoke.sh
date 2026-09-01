#!/usr/bin/env bash

set -euo pipefail

image="${1:-}"
platform="${2:-}"
parser_mode="${3:-}"
expected_debian_arch="${4:-}"
if [[ -z "${image}" || -z "${platform}" || ! "${parser_mode}" =~ ^(native|fallback)$ || -z "${expected_debian_arch}" ]]; then
	echo "usage: $0 IMAGE PLATFORM native|fallback EXPECTED_DEBIAN_ARCH" >&2
	exit 1
fi

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
wordpress_version="$(sed -nE 's/^ARG WORDPRESS_VERSION=([^[:space:]]+)$/\1/p' "${repo_root}/Dockerfile")"
core_package="/usr/src/wordpress-upgrades/wordpress-${wordpress_version}-no-content.zip"

actual_debian_arch="$(docker run --rm --platform "${platform}" --entrypoint dpkg "${image}" --print-architecture)"
if [[ "${actual_debian_arch}" != "${expected_debian_arch}" ]]; then
	echo "expected ${expected_debian_arch}, got ${actual_debian_arch} for ${platform}" >&2
	exit 1
fi

modules="$(docker run --rm --platform "${platform}" --entrypoint php "${image}" -m)"
if [[ "${parser_mode}" == "native" ]]; then
	grep -Fxq wp_mysql_parser <<< "${modules}"
else
	if grep -Fxq wp_mysql_parser <<< "${modules}"; then
		echo "wp_mysql_parser must not load for ${platform}" >&2
		exit 1
	fi
fi

# Variables in this block are expanded by the container's Bash process.
# shellcheck disable=SC2016
docker run --rm --platform "${platform}" --entrypoint bash "${image}" -ceu '
  package="$1"
  expected_parser="$2"
  test -f /usr/src/wordpress/wp-content/db.php
  test -f /usr/src/wordpress/wp-content/mu-plugins/sqlite-database-integration/load.php
  test -f /usr/src/wordpress/wp-content/mu-plugins/sqlite-database-integration-loader.php
  test -f /usr/src/wordpress/wp-content/mu-plugins/sqlite-diagnostics.php
  test -f /usr/src/wordpress/wp-content/mu-plugins/sqlite-local-core-update.php
  test -f /usr/src/wordpress/wp-content/mu-plugins/sqlite-select-id-key-fix.php
  test -f /usr/src/wordpress/tool-update-site-url.php
  test -f /usr/src/wordpress/tool-reset-user-password.php
  test -s "${package}"
  test -s "${package}.sha256"
  test "$(sha256sum "${package}" | awk "{print \$1}")" = "$(cat "${package}.sha256")"
  test "$(stat -c %u "${package}")" = 0
  test "$(stat -c %u "$(dirname "${package}")")" = 0
  test "$(stat -c %a "${package}")" = 444
  test "$(stat -c %a "$(dirname "${package}")")" = 555
  ! grep -Eq "\{WORDPRESS_(VERSION|CORE_PACKAGE_SHA256)\}" /usr/src/wordpress/wp-content/mu-plugins/sqlite-local-core-update.php
  php -l /usr/src/wordpress/wp-content/db.php
  php -l /usr/src/wordpress/wp-content/mu-plugins/sqlite-local-core-update.php
  php -l /usr/src/wordpress/tool-update-site-url.php
  php -l /usr/src/wordpress/tool-reset-user-password.php
  if [[ "${expected_parser}" == native ]]; then
    test -s /usr/local/lib/php/extensions/wp_mysql_parser.so
  else
    test ! -e /usr/local/lib/php/extensions/wp_mysql_parser.so
  fi
' -- "${core_package}" "${parser_mode}"

package_copy="$(mktemp)"
container_id="$(docker create --platform "${platform}" "${image}")"
cleanup() {
	docker rm -f "${container_id}" >/dev/null 2>&1 || true
	rm -f "${package_copy}" "${package_copy}.files"
}
trap cleanup EXIT
docker cp "${container_id}:${core_package}" "${package_copy}"
unzip -Z1 "${package_copy}" > "${package_copy}.files"
grep -Fxq 'wordpress/wp-admin/includes/update-core.php' "${package_copy}.files"
grep -Fxq 'wordpress/wp-includes/version.php' "${package_copy}.files"
if grep -q '^wordpress/wp-content/' "${package_copy}.files"; then
	echo "bundled core archive unexpectedly contains wp-content" >&2
	exit 1
fi

# Exercise the rendered plugin against the archive actually packaged in this
# platform image; source-level unit tests cannot catch a failed build-time
# placeholder substitution.
# shellcheck disable=SC2016
docker run --rm --platform "${platform}" \
	--env "EXPECTED_WORDPRESS_VERSION=${wordpress_version}" \
	--entrypoint php "${image}" -r '
  define("ABSPATH", "/tmp/");
  function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {}
  require "/usr/src/wordpress/wp-content/mu-plugins/sqlite-local-core-update.php";
  if (SQLITE_WORDPRESS_LOCAL_CORE_VERSION !== getenv("EXPECTED_WORDPRESS_VERSION")) {
    fwrite(STDERR, "Rendered local core target does not match the image.\n");
    exit(1);
  }
  if (!sqlite_wordpress_local_core_package_is_valid()) {
    fwrite(STDERR, "Packaged local core archive failed runtime validation.\n");
    exit(1);
  }
  require "/usr/src/wordpress/wp-includes/version.php";
  $bundled_wordpress_version = $wp_version;
  $GLOBALS["wp_version"] = "0.0.0";
  $remote = "https://downloads.wordpress.org/release/wordpress.zip";
  $update = (object) [
    "current" => $bundled_wordpress_version,
    "packages" => (object) ["full" => $remote, "rollback" => $remote],
  ];
  $filtered = sqlite_wordpress_local_core_update_offer((object) ["updates" => [$update]]);
  if ($filtered->updates[0]->packages->full !== SQLITE_WORDPRESS_LOCAL_CORE_PACKAGE
      || $filtered->updates[0]->packages->rollback !== $remote) {
    fwrite(STDERR, "Rendered local core offer did not preserve its package boundary.\n");
    exit(1);
  }
'

# PHP variables below are intentionally protected from shell expansion.
# shellcheck disable=SC2016
docker run --rm --platform "${platform}" "${image}" php -r '
  require "/var/www/html/wp-load.php";
  $driver = $GLOBALS["wpdb"]->get_driver();
  foreach (["beginTransaction", "commit", "rollBack", "inTransaction"] as $method) {
    if (!method_exists($driver, $method)) {
      fwrite(STDERR, "SQLite driver transaction method unavailable: {$method}\n");
      exit(1);
    }
  }
  $driver->beginTransaction();
  if (!$driver->inTransaction()) {
    fwrite(STDERR, "SQLite driver did not enter a transaction.\n");
    exit(1);
  }
  $driver->rollBack();
'

docker run --rm --platform "${platform}" \
	--volume "${repo_root}/tests/image-smoke-site-url.php:/tmp/image-smoke-site-url.php:ro" \
	"${image}" php /tmp/image-smoke-site-url.php

docker run --rm --platform "${platform}" \
	--volume "${repo_root}/tests/image-smoke-user-password.php:/tmp/image-smoke-user-password.php:ro" \
	"${image}" php /tmp/image-smoke-user-password.php
