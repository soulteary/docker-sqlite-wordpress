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

if [ -n "${WORDPRESS_USER_PASSWORD_RESET_TOKEN_FILE:-}" ] \
	&& [ -f "${WORDPRESS_USER_PASSWORD_RESET_TOKEN_FILE}" ] \
	&& [ -r "${WORDPRESS_USER_PASSWORD_RESET_TOKEN_FILE}" ]; then
	resolved_user_password_reset_token="$(< "${WORDPRESS_USER_PASSWORD_RESET_TOKEN_FILE}")"
	export SQLITE_WORDPRESS_USER_PASSWORD_RESET_TOKEN_RESOLVED="${resolved_user_password_reset_token}"
	unset resolved_user_password_reset_token
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
	managed_root_files=(
		"tool-update-site-url.php"
		"tool-reset-user-password.php"
	)
	for managed_root_file in "${managed_root_files[@]}"; do
		if [ -f "${WORDPRESS_PREPARE_DIR}/${managed_root_file}" ]; then
			if [ -L "${DOCROOT}/${managed_root_file}" ] || ! cmp -s "${WORDPRESS_PREPARE_DIR}/${managed_root_file}" "${DOCROOT}/${managed_root_file}"; then
				rm -f "${DOCROOT}/${managed_root_file}"
				cp -f "${WORDPRESS_PREPARE_DIR}/${managed_root_file}" "${DOCROOT}/${managed_root_file}"
			fi
		else
			rm -f "${DOCROOT}/${managed_root_file}"
		fi
	done

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
	managed_previous="$dst_mu_plugins/.${managed_plugin}.previous"
	managed_in_place_marker="${managed_previous}.in-place"

	clear_managed_directory() {
		find "$1" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
	}

	if [ -d "$src_mu_plugins" ]; then
		mkdir -p "$dst_mu_plugins"

		# An in-place replacement is used when the managed directory is itself a
		# mount point and therefore cannot be renamed. Restore its saved contents
		# before retrying if the previous container stopped during that operation.
		if [ -e "$managed_in_place_marker" ] || [ -L "$managed_in_place_marker" ]; then
			if [ -L "$managed_in_place_marker" ] \
				|| [ ! -f "$managed_in_place_marker" ] \
				|| [ ! -d "$managed_previous" ] \
				|| [ -L "$managed_previous" ] \
				|| [ ! -d "$managed_dst" ] \
				|| [ -L "$managed_dst" ]; then
				echo >&2 "sqlite: invalid interrupted in-place replacement state"
				exit 1
			fi
			echo >&2 "sqlite: restoring interrupted in-place mu-plugin replacement"
			if clear_managed_directory "$managed_dst" \
				&& cp -a "$managed_previous/." "$managed_dst/"; then
				rm -f -- "$managed_in_place_marker"
				rm -rf -- "$managed_previous"
			else
				echo >&2 "sqlite: could not restore interrupted mu-plugin replacement"
				exit 1
			fi
		fi

		# Recover a replacement interrupted after the old tree was moved aside.
		if [ ! -e "$managed_dst" ] && [ ! -L "$managed_dst" ] && [ -e "$managed_previous" ]; then
			if [ -d "$managed_previous" ] && [ ! -L "$managed_previous" ]; then
				mv "$managed_previous" "$managed_dst"
			else
				echo >&2 "sqlite: invalid interrupted mu-plugin replacement state"
				exit 1
			fi
		elif { [ -e "$managed_dst" ] || [ -L "$managed_dst" ]; } \
			&& { [ -e "$managed_previous" ] || [ -L "$managed_previous" ]; }; then
			# The live rename completed but cleanup did not. The live directory is
			# authoritative because a normal rename is atomic.
			rm -rf -- "$managed_previous"
		fi

		if [ -d "$managed_src" ] \
			&& { [ -L "$managed_dst" ] || [ ! -d "$managed_dst" ] || ! diff -qr "$managed_src" "$managed_dst" >/dev/null 2>&1; }; then
			echo >&2 "sqlite: replacing managed mu-plugin directory $managed_plugin"
			managed_tmp="$(mktemp -d "$dst_mu_plugins/.${managed_plugin}.new.XXXXXX")"
			cp -a "$managed_src/." "$managed_tmp/"
			chmod --reference="$managed_src" "$managed_tmp"
			rm -rf -- "$managed_previous"
			rm -f -- "$managed_in_place_marker"
			managed_in_place=false
			managed_moved_previous=false
			if [ -e "$managed_dst" ] || [ -L "$managed_dst" ]; then
				if mv "$managed_dst" "$managed_previous"; then
					managed_moved_previous=true
				elif [ -d "$managed_dst" ] \
					&& [ ! -L "$managed_dst" ] \
					&& [ ! -e "$managed_previous" ] \
					&& [ ! -L "$managed_previous" ]; then
					echo >&2 "sqlite: managed mu-plugin directory cannot be renamed; reconciling it in place"
					mkdir "$managed_previous"
					if cp -a "$managed_dst/." "$managed_previous/"; then
						: > "$managed_in_place_marker"
						managed_in_place=true
					else
						rm -rf -- "$managed_tmp" "$managed_previous"
						exit 1
					fi
				else
					rm -rf -- "$managed_tmp"
					echo >&2 "sqlite: could not move or safely reconcile managed mu-plugin directory"
					exit 1
				fi
			fi

			if [ "$managed_in_place" = true ]; then
				if clear_managed_directory "$managed_dst" \
					&& cp -a "$managed_tmp/." "$managed_dst/"; then
					# Removing the marker commits the live tree. Keep the backup
					# recoverable until that commit record is gone.
					rm -f -- "$managed_in_place_marker"
					rm -rf -- "$managed_tmp" "$managed_previous"
				else
					echo >&2 "sqlite: in-place mu-plugin replacement failed; restoring previous contents"
					if clear_managed_directory "$managed_dst" \
						&& cp -a "$managed_previous/." "$managed_dst/"; then
						# The restored tree becomes authoritative once the marker is
						# removed; cleanup of its backup may safely resume on restart.
						rm -f -- "$managed_in_place_marker"
						rm -rf -- "$managed_previous"
					else
						echo >&2 "sqlite: rollback failed; saved contents remain at $managed_previous"
					fi
					rm -rf -- "$managed_tmp"
					exit 1
				fi
			elif mv "$managed_tmp" "$managed_dst"; then
				rm -rf -- "$managed_previous"
			else
				rm -rf -- "$managed_tmp"
				if [ "$managed_moved_previous" = true ] && [ -e "$managed_previous" ]; then
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
			"sqlite-local-core-update.php"
			"sqlite-select-id-key-fix.php"
			"sqlite-wordpress-smtp.php"
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
	clear_recovery_state_if_disabled() {
		local recovery_enabled="$1"
		local recovery_state_file="$2"
		local recovery_lock_file
		local recovery_path

		recovery_lock_file="${recovery_state_file}.lock"
		if [ "${recovery_enabled}" = 'true' ]; then
			return
		fi
		for recovery_path in "$recovery_state_file" "$recovery_lock_file"; do
			if [ -e "$recovery_path" ] || [ -L "$recovery_path" ]; then
				if [ -d "$recovery_path" ] && [ ! -L "$recovery_path" ]; then
					echo >&2 "sqlite: recovery state path must not be a directory: $recovery_path"
					exit 1
				fi
				rm -f -- "$recovery_path"
			fi
		done
	}
	clear_recovery_state_if_disabled \
		"${WORDPRESS_SITE_URL_UPDATE_TOOL_ENABLED:-false}" \
		"$dst_content/database/.ht.site-url-update-tool-state"
	clear_recovery_state_if_disabled \
		"${WORDPRESS_USER_PASSWORD_RESET_TOOL_ENABLED:-false}" \
		"$dst_content/database/.ht.user-password-reset-tool-state"

	# Best-effort ownership/permissions so www-data can create/write the DB.
	if [ "$(id -u)" = '0' ]; then
		user="${APACHE_RUN_USER:-www-data}"
		group="${APACHE_RUN_GROUP:-www-data}"
		user="${user#\#}"
		group="${group#\#}"
		# Keep the security-sensitive recovery endpoint root-owned and read-only
		# to the web process. WordPress-owned content remains writable as before.
		chown root:root "${managed_root_files[@]/#/${DOCROOT}/}" 2>/dev/null || true
		chown -R "$user:$group" "$dst_content/db.php" "$dst_content/mu-plugins" "$dst_content/database" 2>/dev/null || true
	fi
	chmod 644 "${managed_root_files[@]/#/${DOCROOT}/}" 2>/dev/null || true
	chmod 755 "$dst_content/database" 2>/dev/null || true
	chmod 640 "$dst_content/database/.ht.sqlite" 2>/dev/null || true
fi

exec "$@"
