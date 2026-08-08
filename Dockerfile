# plugin: https://github.com/WordPress/sqlite-database-integration
# The optional native Rust extension `wp_mysql_parser` accelerates the MySQL
# lexer/parser used by the SQLite driver. It requires the 3.0 monorepo layout.
ARG SQLITE_DATABASE_INTEGRATION_VERSION=3.0.0-rc.8

# ---------- Stage 1: build the native Rust extension + resolve plugin symlink ----------
FROM wordpress:7.0.2-php8.5-apache AS ext-builder
ARG SQLITE_DATABASE_INTEGRATION_VERSION
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
      amd64|arm64) \
        curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y ;; \
      *) \
        echo "Skipping Rust toolchain install on unsupported arch: ${TARGETARCH}" ;; \
    esac
ENV PATH="/root/.cargo/bin:${PATH}"

# The 3.0 GitHub tarball excludes the `packages` contents, so we must clone.
# When Rust is unavailable (32-bit ARM), we skip compiling the extension and
# emit an empty marker file so the final stage's COPY still succeeds.
RUN git clone --depth 1 --branch "v${SQLITE_DATABASE_INTEGRATION_VERSION}" \
      https://github.com/WordPress/sqlite-database-integration.git /src && \
    cp -R /src/packages/plugin-sqlite-database-integration /plugin && \
    rm /plugin/wp-includes/database && \
    cp -R /src/packages/mysql-on-sqlite/src /plugin/wp-includes/database && \
    rm -rf /plugin/composer.json /plugin/vendor /plugin/node_modules && \
    if command -v cargo >/dev/null 2>&1; then \
      cd /src/packages/php-ext-wp-mysql-parser && \
      PHP_CONFIG="$(command -v php-config)" \
      LIBCLANG_PATH="$(dirname "$(find / -name 'libclang.so*' 2>/dev/null | head -n1)")" \
      cargo build --release && \
      cp target/release/libwp_mysql_parser.so /libwp_mysql_parser.so ; \
    else \
      echo "cargo not found; skipping native extension build" && \
      : > /libwp_mysql_parser.so ; \
    fi

# ---------- Stage 2: final WordPress + SQLite runtime image ----------
FROM wordpress:7.0.2-php8.5-apache
ARG SQLITE_DATABASE_INTEGRATION_VERSION
LABEL org.opencontainers.image.authors="soulteary@gmail.com"

SHELL ["/bin/bash", "-o", "pipefail", "-c"]
ENV WORDPRESS_PREPARE_DIR=/usr/src/wordpress
ENV SQLITE_DATABASE_INTEGRATION_VERSION=${SQLITE_DATABASE_INTEGRATION_VERSION}

# details: https://soulteary.com/2024/04/21/wordpress-sqlite-docker-image-packaging-details.html
COPY --from=ext-builder /plugin ${WORDPRESS_PREPARE_DIR}/wp-content/mu-plugins/sqlite-database-integration

# Companion must-use plugin: normalizes SELECT column-name casing (e.g. "P.id")
# that SQLite otherwise returns as the declared column name (e.g. "ID"). Files
# in the mu-plugins root are auto-loaded and cannot be deactivated.
COPY sqlite-select-id-key-fix.php ${WORDPRESS_PREPARE_DIR}/wp-content/mu-plugins/sqlite-select-id-key-fix.php

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
