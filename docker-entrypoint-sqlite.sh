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
DOCROOT=/var/www/html

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

	# The SQLite drop-in itself. Without this file WordPress uses MySQL.
	if [ -f "$src_content/db.php" ]; then
		if ! cmp -s "$src_content/db.php" "$dst_content/db.php"; then
			echo >&2 "sqlite: installing wp-content/db.php SQLite drop-in into $dst_content"
			cp -f "$src_content/db.php" "$dst_content/db.php"
		fi
	fi

	# The must-use plugins that back the drop-in (the driver, the loader that
	# mounts the admin UI, and the SELECT id casing fix). mu-plugins are never
	# persisted selectively by the stock entrypoint, so keep them in sync here.
	if [ -d "$src_content/mu-plugins" ]; then
		mkdir -p "$dst_content/mu-plugins"
		cp -a "$src_content/mu-plugins/." "$dst_content/mu-plugins/"
	fi

	# SQLite needs a writable directory for the database file.
	mkdir -p "$dst_content/database"
	[ -e "$dst_content/database/.ht.sqlite" ] || : > "$dst_content/database/.ht.sqlite"

	# Best-effort ownership/permissions so www-data can create/write the DB.
	if [ "$(id -u)" = '0' ]; then
		user="${APACHE_RUN_USER:-www-data}"
		group="${APACHE_RUN_GROUP:-www-data}"
		user="${user#\#}"
		group="${group#\#}"
		chown -R "$user:$group" "$dst_content/db.php" "$dst_content/mu-plugins" "$dst_content/database" 2>/dev/null || true
	fi
	chmod 755 "$dst_content/database" 2>/dev/null || true
	chmod 640 "$dst_content/database/.ht.sqlite" 2>/dev/null || true
fi

exec "$@"
