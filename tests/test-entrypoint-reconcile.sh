#!/usr/bin/env bash

set -Eeuo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
fixture_root="$(mktemp -d)"
trap 'rm -rf "${fixture_root}"' EXIT

prepare_dir="${fixture_root}/prepare"
docroot="${fixture_root}/docroot"
stub_bin="${fixture_root}/bin"
src_content="${prepare_dir}/wp-content"
dst_content="${docroot}/wp-content"
src_plugin="${src_content}/mu-plugins/sqlite-database-integration"
dst_plugin="${dst_content}/mu-plugins/sqlite-database-integration"
managed_previous="${dst_content}/mu-plugins/.sqlite-database-integration.previous"
managed_marker="${managed_previous}.in-place"

assert_no_reconcile_artifacts() {
	test -z "$(
		find "${dst_content}/mu-plugins" -mindepth 1 -maxdepth 1 \
			\( -name '.sqlite-database-integration.new.*' \
			-o -name '.sqlite-database-integration.previous' \
			-o -name '.sqlite-database-integration.previous.in-place' \) \
			-print -quit
	)"
}

mkdir -p "${stub_bin}" "${src_plugin}" "${dst_plugin}" "${dst_content}/database"
chmod 0751 "${src_plugin}"
printf '#!/usr/bin/env bash\nexit 0\n' > "${stub_bin}/docker-ensure-installed.sh"
chmod +x "${stub_bin}/docker-ensure-installed.sh"
printf "#!/usr/bin/env bash\ntest \"\${SQLITE_WORDPRESS_SITE_URL_UPDATE_TOKEN_RESOLVED:-}\" = \"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\"\n" > "${stub_bin}/assert-site-url-token"
chmod +x "${stub_bin}/assert-site-url-token"
printf 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n' > "${fixture_root}/site-url-token"
chmod 600 "${fixture_root}/site-url-token"
recovery_state_file="${dst_content}/database/.ht.site-url-update-tool-state"
recovery_lock_file="${recovery_state_file}.lock"
printf 'used recovery state\n' > "${recovery_state_file}"
printf 'recovery lock\n' > "${recovery_lock_file}"

printf 'new drop-in\n' > "${src_content}/db.php"
printf 'new recovery tool\n' > "${prepare_dir}/tool-update-site-url.php"
printf 'outside drop-in must remain unchanged\n' > "${fixture_root}/outside-drop-in"
ln -s "${fixture_root}/outside-drop-in" "${dst_content}/db.php"
printf 'outside recovery tool must remain unchanged\n' > "${fixture_root}/outside-recovery-tool"
ln -s "${fixture_root}/outside-recovery-tool" "${docroot}/tool-update-site-url.php"
printf 'new integration file\n' > "${src_plugin}/current.php"
printf 'old integration file\n' > "${dst_plugin}/current.php"
printf 'removed upstream file\n' > "${dst_plugin}/stale.php"

for managed_file in \
	"sqlite-database-integration-loader.php" \
	"sqlite-diagnostics.php" \
	"sqlite-local-core-update.php" \
	"sqlite-select-id-key-fix.php"
do
	printf 'new %s\n' "${managed_file}" > "${src_content}/mu-plugins/${managed_file}"
	printf 'old %s\n' "${managed_file}" > "${dst_content}/mu-plugins/${managed_file}"
done
printf 'outside loader must remain unchanged\n' > "${fixture_root}/outside-loader"
rm -f "${dst_content}/mu-plugins/sqlite-database-integration-loader.php"
ln -s "${fixture_root}/outside-loader" "${dst_content}/mu-plugins/sqlite-database-integration-loader.php"
printf 'custom plugin\n' > "${dst_content}/mu-plugins/custom.php"

PATH="${stub_bin}:${PATH}" \
	WORDPRESS_PREPARE_DIR="${prepare_dir}" \
	WORDPRESS_DOCROOT="${docroot}" \
	WORDPRESS_SITE_URL_UPDATE_TOKEN_FILE="${fixture_root}/site-url-token" \
	APACHE_RUN_USER="$(id -u)" \
	APACHE_RUN_GROUP="$(id -g)" \
	bash "${repo_root}/docker-entrypoint-sqlite.sh" assert-site-url-token

cmp "${src_content}/db.php" "${dst_content}/db.php"
test ! -L "${dst_content}/db.php"
grep -Fxq 'outside drop-in must remain unchanged' "${fixture_root}/outside-drop-in"
cmp "${prepare_dir}/tool-update-site-url.php" "${docroot}/tool-update-site-url.php"
test ! -L "${docroot}/tool-update-site-url.php"
test "$(stat -c '%a' "${docroot}/tool-update-site-url.php")" = '644'
grep -Fxq 'outside recovery tool must remain unchanged' "${fixture_root}/outside-recovery-tool"
diff -qr "${src_plugin}" "${dst_plugin}"
test "$(stat -c '%a' "${dst_plugin}")" = "$(stat -c '%a' "${src_plugin}")"
test ! -e "${dst_plugin}/stale.php"
test -f "${dst_content}/mu-plugins/custom.php"

for managed_file in \
	"sqlite-database-integration-loader.php" \
	"sqlite-diagnostics.php" \
	"sqlite-local-core-update.php" \
	"sqlite-select-id-key-fix.php"
do
	cmp "${src_content}/mu-plugins/${managed_file}" "${dst_content}/mu-plugins/${managed_file}"
done

test ! -L "${dst_content}/mu-plugins/sqlite-database-integration-loader.php"
grep -Fxq 'outside loader must remain unchanged' "${fixture_root}/outside-loader"

test -f "${dst_content}/database/.ht.sqlite"
test ! -e "${recovery_state_file}"
test ! -e "${recovery_lock_file}"
assert_no_reconcile_artifacts

# A persisted symlink must be replaced even when its target already has exactly
# the source contents. A plain diff dereferences the link and would otherwise
# incorrectly leave a root-managed symlink in place.
matching_external_plugin="${fixture_root}/matching-external-plugin"
cp -a "${src_plugin}" "${matching_external_plugin}"
rm -rf "${dst_plugin}"
ln -s "${matching_external_plugin}" "${dst_plugin}"
PATH="${stub_bin}:${PATH}" \
	WORDPRESS_PREPARE_DIR="${prepare_dir}" \
	WORDPRESS_DOCROOT="${docroot}" \
	APACHE_RUN_USER="$(id -u)" \
	APACHE_RUN_GROUP="$(id -g)" \
	bash "${repo_root}/docker-entrypoint-sqlite.sh" true
test ! -L "${dst_plugin}"
diff -qr "${src_plugin}" "${dst_plugin}"
diff -qr "${src_plugin}" "${matching_external_plugin}"
assert_no_reconcile_artifacts

# Simulate the EBUSY returned when the managed directory is a separate bind or
# named-volume mount. The fallback must keep the mounted root directory while
# making its contents exactly match the image-managed source.
real_mv="$(command -v mv)"
# The generated wrapper must expand these variables when it runs, not while the
# fixture is being written.
# shellcheck disable=SC2016
printf '%s\n' \
	'#!/usr/bin/env bash' \
	'if [ "${1:-}" = "${SQLITE_TEST_MV_EBUSY_PATH:-}" ]; then' \
	'  echo "mv: cannot move mount point: Device or resource busy" >&2' \
	'  exit 32' \
	'fi' \
	'exec "${SQLITE_TEST_REAL_MV}" "$@"' \
	> "${stub_bin}/mv"
chmod +x "${stub_bin}/mv"
printf 'new mounted integration file\n' > "${src_plugin}/current.php"
printf 'stale mounted integration file\n' > "${dst_plugin}/stale.php"
mounted_root_inode="$(stat -c '%i' "${dst_plugin}")"
PATH="${stub_bin}:${PATH}" \
	SQLITE_TEST_MV_EBUSY_PATH="${dst_plugin}" \
	SQLITE_TEST_REAL_MV="${real_mv}" \
	WORDPRESS_PREPARE_DIR="${prepare_dir}" \
	WORDPRESS_DOCROOT="${docroot}" \
	APACHE_RUN_USER="$(id -u)" \
	APACHE_RUN_GROUP="$(id -g)" \
	bash "${repo_root}/docker-entrypoint-sqlite.sh" true
test "$(stat -c '%i' "${dst_plugin}")" = "${mounted_root_inode}"
diff -qr "${src_plugin}" "${dst_plugin}"
test -f "${dst_content}/mu-plugins/custom.php"
assert_no_reconcile_artifacts

# Emulate interruption immediately after the marker is removed but before its
# recovery backup is deleted. The live copy is already complete, so the next
# startup must treat it as authoritative and finish cleanup without requiring
# manual removal of an invalid marker.
real_rm="$(command -v rm)"
cleanup_fail_file="${fixture_root}/cleanup-failed-once"
# The generated wrapper must expand these variables when it runs, not while the
# fixture is being written.
# shellcheck disable=SC2016
printf '%s\n' \
	'#!/usr/bin/env bash' \
	'set -Eeuo pipefail' \
	'target="${SQLITE_TEST_RM_FAIL_PATH:-}"' \
	'marker="${SQLITE_TEST_RM_MARKER_PATH:-}"' \
	'fail_file="${SQLITE_TEST_RM_FAIL_ONCE_FILE:-}"' \
	'matched=false' \
	'filtered=()' \
	'for argument in "$@"; do' \
	'  if [ -n "${target}" ] && [ "${argument}" = "${target}" ]; then' \
	'    matched=true' \
	'  else' \
	'    filtered+=("${argument}")' \
	'  fi' \
	'done' \
	'if [ "${matched}" = true ] && [ ! -e "${marker}" ] && [ -e "${target}" ] && [ ! -e "${fail_file}" ]; then' \
	'  "${SQLITE_TEST_REAL_RM}" "${filtered[@]}"' \
	'  : > "${fail_file}"' \
	'  exit 73' \
	'fi' \
	'exec "${SQLITE_TEST_REAL_RM}" "$@"' \
	> "${stub_bin}/rm"
chmod +x "${stub_bin}/rm"

printf 'cleanup-order integration file\n' > "${src_plugin}/current.php"
if PATH="${stub_bin}:${PATH}" \
	SQLITE_TEST_MV_EBUSY_PATH="${dst_plugin}" \
	SQLITE_TEST_REAL_MV="${real_mv}" \
	SQLITE_TEST_RM_FAIL_PATH="${managed_previous}" \
	SQLITE_TEST_RM_MARKER_PATH="${managed_marker}" \
	SQLITE_TEST_RM_FAIL_ONCE_FILE="${cleanup_fail_file}" \
	SQLITE_TEST_REAL_RM="${real_rm}" \
	WORDPRESS_PREPARE_DIR="${prepare_dir}" \
	WORDPRESS_DOCROOT="${docroot}" \
	APACHE_RUN_USER="$(id -u)" \
	APACHE_RUN_GROUP="$(id -g)" \
	bash "${repo_root}/docker-entrypoint-sqlite.sh" true; then
	echo "injected post-commit cleanup failure unexpectedly succeeded" >&2
	exit 1
fi
test -f "${cleanup_fail_file}"
test ! -e "${managed_marker}"
test -d "${managed_previous}"
diff -qr "${src_plugin}" "${dst_plugin}"

PATH="${stub_bin}:${PATH}" \
	SQLITE_TEST_MV_EBUSY_PATH="${dst_plugin}" \
	SQLITE_TEST_REAL_MV="${real_mv}" \
	SQLITE_TEST_RM_FAIL_PATH="${managed_previous}" \
	SQLITE_TEST_RM_MARKER_PATH="${managed_marker}" \
	SQLITE_TEST_RM_FAIL_ONCE_FILE="${cleanup_fail_file}" \
	SQLITE_TEST_REAL_RM="${real_rm}" \
	WORDPRESS_PREPARE_DIR="${prepare_dir}" \
	WORDPRESS_DOCROOT="${docroot}" \
	APACHE_RUN_USER="$(id -u)" \
	APACHE_RUN_GROUP="$(id -g)" \
	bash "${repo_root}/docker-entrypoint-sqlite.sh" true
test ! -e "${managed_previous}"
diff -qr "${src_plugin}" "${dst_plugin}"
assert_no_reconcile_artifacts
rm -f -- "${stub_bin}/rm"

# Recover a process interruption during the in-place copy before comparing the
# live tree with the packaged source. The artifact assertion uses a wildcard so
# it checks the child entrypoint's unique staging name, not the parent's PID.
mkdir "${managed_previous}"
cp -a "${src_plugin}/." "${managed_previous}/"
printf 'in-place\n' > "${managed_marker}"
find "${dst_plugin}" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
printf 'partial interrupted copy\n' > "${dst_plugin}/partial.php"
PATH="${stub_bin}:${PATH}" \
	WORDPRESS_PREPARE_DIR="${prepare_dir}" \
	WORDPRESS_DOCROOT="${docroot}" \
	APACHE_RUN_USER="$(id -u)" \
	APACHE_RUN_GROUP="$(id -g)" \
	bash "${repo_root}/docker-entrypoint-sqlite.sh" true
diff -qr "${src_plugin}" "${dst_plugin}"
assert_no_reconcile_artifacts

# An exact enabled start preserves the one-shot latch, so restarting the same
# recovery configuration cannot reopen an authorization that was already used.
printf 'used recovery state\n' > "${recovery_state_file}"
printf 'recovery lock\n' > "${recovery_lock_file}"
PATH="${stub_bin}:${PATH}" \
	WORDPRESS_PREPARE_DIR="${prepare_dir}" \
	WORDPRESS_DOCROOT="${docroot}" \
	WORDPRESS_SITE_URL_UPDATE_TOOL_ENABLED=true \
	WORDPRESS_SITE_URL_UPDATE_TOKEN_FILE="${fixture_root}/site-url-token" \
	APACHE_RUN_USER="$(id -u)" \
	APACHE_RUN_GROUP="$(id -g)" \
	bash "${repo_root}/docker-entrypoint-sqlite.sh" assert-site-url-token
grep -Fxq 'used recovery state' "${recovery_state_file}"
grep -Fxq 'recovery lock' "${recovery_lock_file}"
