#!/usr/bin/env bash
# Self-healing entrypoint for the SQLite WordPress image.
#
# The official WordPress entrypoint only seeds a mounted volume from
# ${WORDPRESS_PREPARE_DIR} when the volume looks empty (missing index.php and
# wp-includes/version.php). A volume created by an older image version — or any
# already-initialized volume — therefore never receives the SQLite drop-in
# (wp-content/db.php) nor the SQLite mu-plugins. WordPress then falls back to
# MySQL and shows "Error establishing a database connection".
#
# This wrapper first runs the stock entrypoint logic (core copy + wp-config
# generation) without starting the server, then unconditionally reconciles the
# SQLite bits into the live docroot before handing off to the real command.
set -Eeuo pipefail

: "${WORDPRESS_PREPARE_DIR:=/usr/src/wordpress}"
: "${WORDPRESS_DOCROOT:=/var/www/html}"
DOCROOT="${WORDPRESS_DOCROOT}"

# Resolve a Docker secret while the entrypoint still has permission to read a
# root-owned file. The value is exported only to the container process tree; it
# is not added to the image or Docker's configured environment metadata.
if [ -n "${WORDPRESS_SITE_URL_UPDATE_TOKEN_FILE:-}" ] \
	&& [ -f "${WORDPRESS_SITE_URL_UPDATE_TOKEN_FILE}" ] \
	&& [ -r "${WORDPRESS_SITE_URL_UPDATE_TOKEN_FILE}" ]; then
	resolved_site_url_update_token="$(< "${WORDPRESS_SITE_URL_UPDATE_TOKEN_FILE}")"
	export SQLITE_WORDPRESS_SITE_URL_UPDATE_TOKEN_RESOLVED="${resolved_site_url_update_token}"
	unset resolved_site_url_update_token
fi

# Run the stock entrypoint's setup so it performs its volume seeding /
# wp-config generation but does NOT start Apache/php-fpm. The stock script only
# runs that setup when invoked as apache2*/php-fpm or under the name
# docker-ensure-installed.sh; the latter is the intended hook for "prepare the
# volume, then run this other command", so we use it with a no-op (true).
docker-ensure-installed.sh true

src_content="${WORDPRESS_PREPARE_DIR}/wp-content"
dst_content="${DOCROOT}/wp-content"

if [ -d "$src_content" ] && [ -d "$DOCROOT" ]; then
	mkdir -p "$dst_content"

	# Keep the disabled-by-default recovery endpoint available on existing
	# volumes too. Never follow a persisted symlink while copying as root.
	managed_root_file="tool-update-site-url.php"
	if [ -f "${WORDPRESS_PREPARE_DIR}/${managed_root_file}" ]; then
		if [ -L "${DOCROOT}/${managed_root_file}" ] || ! cmp -s "${WORDPRESS_PREPARE_DIR}/${managed_root_file}" "${DOCROOT}/${managed_root_file}"; then
			rm -f "${DOCROOT}/${managed_root_file}"
			cp -f "${WORDPRESS_PREPARE_DIR}/${managed_root_file}" "${DOCROOT}/${managed_root_file}"
		fi
	else
		rm -f "${DOCROOT}/${managed_root_file}"
	fi

	# The SQLite drop-in itself. Without this file WordPress uses MySQL.
	if [ -f "$src_content/db.php" ]; then
		if [ -L "$dst_content/db.php" ] || ! cmp -s "$src_content/db.php" "$dst_content/db.php"; then
			echo >&2 "sqlite: installing wp-content/db.php SQLite drop-in into $dst_content"
			# Never follow a user-provided symlink while running as root.
			rm -f "$dst_content/db.php"
			cp -f "$src_content/db.php" "$dst_content/db.php"
		fi
	fi

	# Replace the managed integration subtree instead of merging it. A recursive
	# copy leaves PHP files removed by a newer upstream version in persisted
	# volumes. Stage the replacement beside the live directory and keep a backup
	# until the rename succeeds; unrelated user mu-plugins remain untouched.
	src_mu_plugins="$src_content/mu-plugins"
	dst_mu_plugins="$dst_content/mu-plugins"
	managed_plugin="sqlite-database-integration"
	managed_src="$src_mu_plugins/$managed_plugin"
	managed_dst="$dst_mu_plugins/$managed_plugin"
	managed_tmp="$dst_mu_plugins/.${managed_plugin}.new.$$"
	managed_previous="$dst_mu_plugins/.${managed_plugin}.previous"

	if [ -d "$src_mu_plugins" ]; then
		mkdir -p "$dst_mu_plugins"

		# Recover a replacement interrupted after the old tree was moved aside.
		if [ ! -e "$managed_dst" ] && [ ! -L "$managed_dst" ] && [ -e "$managed_previous" ]; then
			mv "$managed_previous" "$managed_dst"
		fi

		if [ -d "$managed_src" ] && { [ ! -d "$managed_dst" ] || ! diff -qr "$managed_src" "$managed_dst" >/dev/null 2>&1; }; then
			echo >&2 "sqlite: replacing managed mu-plugin directory $managed_plugin"
			rm -rf "$managed_tmp"
			cp -a "$managed_src" "$managed_tmp"
			rm -rf "$managed_previous"
			if [ -e "$managed_dst" ] || [ -L "$managed_dst" ]; then
				mv "$managed_dst" "$managed_previous"
			fi
			if mv "$managed_tmp" "$managed_dst"; then
				rm -rf "$managed_previous"
			else
				rm -rf "$managed_tmp"
				if [ -e "$managed_previous" ]; then
					mv "$managed_previous" "$managed_dst"
				fi
				exit 1
			fi
		fi

		# Root-level mu-plugin files are managed individually so custom siblings
		# are preserved. Remove a destination symlink before copying as root.
		managed_mu_plugin_files=(
			"sqlite-database-integration-loader.php"
			"sqlite-diagnostics.php"
			"sqlite-select-id-key-fix.php"
		)
		for managed_file in "${managed_mu_plugin_files[@]}"; do
			if [ -f "$src_mu_plugins/$managed_file" ]; then
				if [ -L "$dst_mu_plugins/$managed_file" ] || ! cmp -s "$src_mu_plugins/$managed_file" "$dst_mu_plugins/$managed_file"; then
					rm -f "$dst_mu_plugins/$managed_file"
					cp -f "$src_mu_plugins/$managed_file" "$dst_mu_plugins/$managed_file"
				fi
			else
				rm -f "$dst_mu_plugins/$managed_file"
			fi
		done
	fi

	# SQLite needs a writable directory for the database file.
	mkdir -p "$dst_content/database"
	[ -e "$dst_content/database/.ht.sqlite" ] || : > "$dst_content/database/.ht.sqlite"

	# A successful recovery request creates a persistent one-shot latch beside the
	# SQLite database. Keep it while the exact enable switch remains active so a
	# container restart cannot reopen the endpoint. A deliberately disabled start
	# clears the latch and rearms a future enable cycle.
	recovery_state_file="$dst_content/database/.ht.site-url-update-tool-state"
	recovery_lock_file="${recovery_state_file}.lock"
	if [ "${WORDPRESS_SITE_URL_UPDATE_TOOL_ENABLED:-}" != 'true' ]; then
		for recovery_path in "$recovery_state_file" "$recovery_lock_file"; do
			if [ -e "$recovery_path" ] || [ -L "$recovery_path" ]; then
				if [ -d "$recovery_path" ] && [ ! -L "$recovery_path" ]; then
					echo >&2 "sqlite: recovery state path must not be a directory: $recovery_path"
					exit 1
				fi
				rm -f -- "$recovery_path"
			fi
		done
	fi

	# Best-effort ownership/permissions so www-data can create/write the DB.
	if [ "$(id -u)" = '0' ]; then
		user="${APACHE_RUN_USER:-www-data}"
		group="${APACHE_RUN_GROUP:-www-data}"
		user="${user#\#}"
		group="${group#\#}"
		# Keep the security-sensitive recovery endpoint root-owned and read-only
		# to the web process. WordPress-owned content remains writable as before.
		chown root:root "${DOCROOT}/${managed_root_file}" 2>/dev/null || true
		chown -R "$user:$group" "$dst_content/db.php" "$dst_content/mu-plugins" "$dst_content/database" 2>/dev/null || true
	fi
	chmod 644 "${DOCROOT}/${managed_root_file}" 2>/dev/null || true
	chmod 755 "$dst_content/database" 2>/dev/null || true
	chmod 640 "$dst_content/database/.ht.sqlite" 2>/dev/null || true
fi

exec "$@"
