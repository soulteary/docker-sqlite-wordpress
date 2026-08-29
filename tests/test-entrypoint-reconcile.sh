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

mkdir -p "${stub_bin}" "${src_plugin}" "${dst_plugin}" "${dst_content}/database"
printf '#!/usr/bin/env bash\nexit 0\n' > "${stub_bin}/docker-ensure-installed.sh"
chmod +x "${stub_bin}/docker-ensure-installed.sh"
printf "#!/usr/bin/env bash\ntest \"\${SQLITE_WORDPRESS_SITE_URL_UPDATE_TOKEN_RESOLVED:-}\" = \"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\"\n" > "${stub_bin}/assert-site-url-token"
chmod +x "${stub_bin}/assert-site-url-token"
printf 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n' > "${fixture_root}/site-url-token"
chmod 600 "${fixture_root}/site-url-token"

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
test ! -e "${dst_plugin}/stale.php"
test -f "${dst_content}/mu-plugins/custom.php"

for managed_file in \
	"sqlite-database-integration-loader.php" \
	"sqlite-diagnostics.php" \
	"sqlite-select-id-key-fix.php"
do
	cmp "${src_content}/mu-plugins/${managed_file}" "${dst_content}/mu-plugins/${managed_file}"
done

test ! -L "${dst_content}/mu-plugins/sqlite-database-integration-loader.php"
grep -Fxq 'outside loader must remain unchanged' "${fixture_root}/outside-loader"

test -f "${dst_content}/database/.ht.sqlite"
test ! -e "${dst_content}/mu-plugins/.sqlite-database-integration.previous"
test ! -e "${dst_content}/mu-plugins/.sqlite-database-integration.new.$$"
