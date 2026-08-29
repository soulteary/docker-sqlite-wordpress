# plugin: https://github.com/WordPress/sqlite-database-integration
# The optional native Rust extension `wp_mysql_parser` accelerates the MySQL
# lexer/parser used by the SQLite driver. It requires the 3.0 monorepo layout.
ARG WORDPRESS_IMAGE=wordpress:7.1.0-php8.5-apache@sha256:6c56ffb8cc06577c604707e3164f7fe736a3db6855336804a2cd4d7c186d6502
ARG SQLITE_DATABASE_INTEGRATION_VERSION=3.0.0
ARG SQLITE_DATABASE_INTEGRATION_COMMIT=8063adb20deb6d4e4736113c5e0e3fa0b03ccea0
ARG RUSTUP_VERSION=1.29.0
ARG RUST_TOOLCHAIN_VERSION=1.98.0

# ---------- Stage 1: build the native Rust extension + resolve plugin symlink ----------
FROM ${WORDPRESS_IMAGE} AS ext-builder
ARG SQLITE_DATABASE_INTEGRATION_VERSION
ARG SQLITE_DATABASE_INTEGRATION_COMMIT
ARG RUSTUP_VERSION
ARG RUST_TOOLCHAIN_VERSION
# Provided automatically by BuildKit (e.g. amd64, arm64, arm). Used to gate the
# Rust build to platforms where rustup ships a reliable toolchain under QEMU.
ARG TARGETARCH

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

RUN apt-get update && apt-get install -y --no-install-recommends \
      curl ca-certificates build-essential pkg-config clang libclang-dev git \
    && rm -rf /var/lib/apt/lists/*

# The native `wp_mysql_parser` extension is an optional accelerator; the plugin
# transparently falls back to its pure-PHP parser when the .so is absent. On
# 32-bit ARM (arm/v5, arm/v6, arm/v7) the rustup installer fails under QEMU
# emulation (missing/mismatched ld-linux-armhf.so.3), so we only build the
# extension on amd64 and arm64 and skip it elsewhere.
RUN case "${TARGETARCH}" in \
      amd64) \
        rustup_target=x86_64-unknown-linux-gnu; \
        rustup_sha256=4acc9acc76d5079515b46346a485974457b5a79893cfb01112423c89aeb5aa10 ;; \
      arm64) \
        rustup_target=aarch64-unknown-linux-gnu; \
        rustup_sha256=9732d6c5e2a098d3521fca8145d826ae0aaa067ef2385ead08e6feac88fa5792 ;; \
      *) \
        echo "Skipping Rust toolchain install on unsupported arch: ${TARGETARCH}"; \
        exit 0 ;; \
    esac && \
    curl --proto '=https' --tlsv1.2 --retry 3 -fsSLo /tmp/rustup-init \
      "https://static.rust-lang.org/rustup/archive/${RUSTUP_VERSION}/${rustup_target}/rustup-init" && \
    echo "${rustup_sha256}  /tmp/rustup-init" | sha256sum -c - && \
    chmod +x /tmp/rustup-init && \
    /tmp/rustup-init -y --no-modify-path --profile minimal \
      --default-toolchain "${RUST_TOOLCHAIN_VERSION}" && \
    /root/.cargo/bin/rustc --version | grep -F "rustc ${RUST_TOOLCHAIN_VERSION} " && \
    rm -f /tmp/rustup-init
ENV PATH="/root/.cargo/bin:${PATH}"

# The 3.0 GitHub tarball excludes the `packages` contents, so we must clone.
# When Rust is unavailable (32-bit ARM), we skip compiling the extension and
# emit an empty marker file so the final stage's COPY still succeeds.
RUN git init /src && \
    git -C /src remote add origin https://github.com/WordPress/sqlite-database-integration.git && \
    git -C /src fetch --depth 1 origin "${SQLITE_DATABASE_INTEGRATION_COMMIT}" && \
    git -C /src checkout --detach FETCH_HEAD && \
    test "$(git -C /src rev-parse HEAD)" = "${SQLITE_DATABASE_INTEGRATION_COMMIT}" && \
    grep -Eq "^Stable tag:[[:space:]]+${SQLITE_DATABASE_INTEGRATION_VERSION}$" \
      /src/packages/plugin-sqlite-database-integration/readme.txt && \
    cp -R /src/packages/plugin-sqlite-database-integration /plugin && \
    rm /plugin/wp-includes/database && \
    cp -R /src/packages/mysql-on-sqlite/src /plugin/wp-includes/database && \
    rm -rf /plugin/composer.json /plugin/vendor /plugin/node_modules && \
    if command -v cargo >/dev/null 2>&1; then \
      cd /src/packages/php-ext-wp-mysql-parser && \
      PHP_CONFIG="$(command -v php-config)" \
      LIBCLANG_PATH="$(dirname "$(find / -name 'libclang.so*' 2>/dev/null | head -n1)")" \
      cargo build --release --locked && \
      cp target/release/libwp_mysql_parser.so /libwp_mysql_parser.so ; \
    else \
      echo "cargo not found; skipping native extension build" && \
      : > /libwp_mysql_parser.so ; \
    fi

# ---------- Stage 2: final WordPress + SQLite runtime image ----------
FROM ${WORDPRESS_IMAGE}
ARG SQLITE_DATABASE_INTEGRATION_VERSION
LABEL org.opencontainers.image.authors="soulteary@gmail.com" \
      org.opencontainers.image.source="https://github.com/soulteary/docker-sqlite-wordpress" \
      org.opencontainers.image.title="Docker SQLite WordPress" \
      org.opencontainers.image.description="WordPress with SQLite, ready to use out of the box" \
      org.opencontainers.image.version="7.1.0" \
      org.opencontainers.image.licenses="GPL-2.0-or-later AND MIT"

SHELL ["/bin/bash", "-o", "pipefail", "-c"]
ENV WORDPRESS_PREPARE_DIR=/usr/src/wordpress
ENV SQLITE_DATABASE_INTEGRATION_VERSION=${SQLITE_DATABASE_INTEGRATION_VERSION}

# details: https://soulteary.com/2024/04/21/wordpress-sqlite-docker-image-packaging-details.html
COPY --from=ext-builder /plugin ${WORDPRESS_PREPARE_DIR}/wp-content/mu-plugins/sqlite-database-integration

# Companion must-use plugin: normalizes SELECT column-name casing (e.g. "P.id")
# that SQLite otherwise returns as the declared column name (e.g. "ID"). Files
# in the mu-plugins root are auto-loaded and cannot be deactivated.
COPY sqlite-select-id-key-fix.php ${WORDPRESS_PREPARE_DIR}/wp-content/mu-plugins/sqlite-select-id-key-fix.php

# Companion must-use plugin: read-only diagnostics page under the Tools menu
# that surfaces the native parser / SQLite / environment / integration state.
# Auto-loaded from the mu-plugins root and cannot be deactivated.
COPY sqlite-diagnostics.php ${WORDPRESS_PREPARE_DIR}/wp-content/mu-plugins/sqlite-diagnostics.php

# mu-plugins only auto-loads .php files in the mu-plugins root; it does NOT
# recurse into subdirectories, so the plugin's own
# sqlite-database-integration/load.php is never executed on its own. This
# root-level loader requires that load.php to mount its admin UI (the SQLite
# health-check / settings page under Settings). The SQLite driver itself loads
# via wp-content/db.php and does not depend on this loader.
COPY sqlite-database-integration-loader.php ${WORDPRESS_PREPARE_DIR}/wp-content/mu-plugins/sqlite-database-integration-loader.php

# Disabled-by-default emergency endpoint for repairing the database-backed
# WordPress Address (`siteurl`) and Site Address (`home`) after a domain change.
# It returns 404 unless explicitly enabled and a strong token is supplied.
COPY tool-update-site-url.php ${WORDPRESS_PREPARE_DIR}/tool-update-site-url.php

# Self-healing entrypoint: the stock WordPress entrypoint only seeds a mounted
# volume when it is empty, so an already-initialized/old volume never receives
# the SQLite drop-in (wp-content/db.php) and WordPress falls back to MySQL
# ("Error establishing a database connection"). This wrapper reconciles the
# SQLite drop-in + mu-plugins into the live docroot on every start.
COPY docker-entrypoint-sqlite.sh /usr/local/bin/docker-entrypoint-sqlite.sh
RUN chmod +x /usr/local/bin/docker-entrypoint-sqlite.sh

RUN mv "${WORDPRESS_PREPARE_DIR}/wp-content/mu-plugins/sqlite-database-integration/db.copy" "${WORDPRESS_PREPARE_DIR}/wp-content/db.php" && \
    sed -i 's#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#/var/www/html/wp-content/mu-plugins/sqlite-database-integration#' "${WORDPRESS_PREPARE_DIR}/wp-content/db.php" && \
    sed -i 's#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#' "${WORDPRESS_PREPARE_DIR}/wp-content/db.php" && \
    mkdir -p "${WORDPRESS_PREPARE_DIR}/wp-content/database" \
             "${WORDPRESS_PREPARE_DIR}/wp-content/plugins" \
             "${WORDPRESS_PREPARE_DIR}/wp-content/themes" \
             "${WORDPRESS_PREPARE_DIR}/wp-content/uploads" \
             "${WORDPRESS_PREPARE_DIR}/wp-content/upgrade" && \
    touch "${WORDPRESS_PREPARE_DIR}/wp-content/database/.ht.sqlite" && \
    chown -R www-data:www-data "${WORDPRESS_PREPARE_DIR}/wp-content" && \
    find "${WORDPRESS_PREPARE_DIR}/wp-content" -type d -exec chmod 755 {} + && \
    chmod 640 "${WORDPRESS_PREPARE_DIR}/wp-content/database/.ht.sqlite"

# Fail the build (instead of silently shipping a broken drop-in) if the upstream
# layout changes: the SQLite loader must be present, its `../database` sibling
# (a symlink upstream) must be materialized, the query-monitor integration
# boot.php required by wp-includes/sqlite/db.php must exist, the generated
# db.php must define SQLITE_DB_DROPIN_VERSION (so constants.php selects the
# sqlite engine and activate.php never overwrites it), and the db.php
# placeholder must have been replaced so it never falls back to the wrong
# `plugins/` path (see #478).
RUN test -f "${WORDPRESS_PREPARE_DIR}/wp-content/mu-plugins/sqlite-database-integration/wp-includes/sqlite/db.php" && \
    test -f "${WORDPRESS_PREPARE_DIR}/wp-content/mu-plugins/sqlite-database-integration/wp-includes/database/load.php" && \
    test -f "${WORDPRESS_PREPARE_DIR}/wp-content/mu-plugins/sqlite-database-integration/integrations/query-monitor/boot.php" && \
    test -f "${WORDPRESS_PREPARE_DIR}/tool-update-site-url.php" && \
    grep -q 'SQLITE_DB_DROPIN_VERSION' "${WORDPRESS_PREPARE_DIR}/wp-content/db.php" && \
    ! grep -q '{SQLITE_IMPLEMENTATION_FOLDER_PATH}' "${WORDPRESS_PREPARE_DIR}/wp-content/db.php"

# Enable the native MySQL parser extension when it was actually built.
# On platforms where the build was skipped, the copied file is empty (a
# placeholder), so we only register the extension when it contains a real .so.
COPY --from=ext-builder /libwp_mysql_parser.so /usr/local/lib/php/extensions/wp_mysql_parser.so
RUN if [ -s /usr/local/lib/php/extensions/wp_mysql_parser.so ]; then \
      echo "extension=/usr/local/lib/php/extensions/wp_mysql_parser.so" > /usr/local/etc/php/conf.d/wp_mysql_parser.ini ; \
    else \
      echo "Native wp_mysql_parser extension not built for this platform; using PHP fallback." && \
      rm -f /usr/local/lib/php/extensions/wp_mysql_parser.so ; \
    fi

# Wrap the stock entrypoint so the SQLite drop-in is (re)installed on any volume
# state; CMD stays the base image's apache2-foreground.
ENTRYPOINT ["docker-entrypoint-sqlite.sh"]
CMD ["apache2-foreground"]
