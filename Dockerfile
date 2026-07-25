# plugin: https://github.com/WordPress/sqlite-database-integration
# The optional native Rust extension `wp_mysql_parser` accelerates the MySQL
# lexer/parser used by the SQLite driver. It requires the 3.0 monorepo layout.
ARG SQLITE_DATABASE_INTEGRATION_VERSION=3.0.0-rc.7

# ---------- Stage 1: build the native Rust extension + resolve plugin symlink ----------
FROM wordpress:7.0.2-php8.5-apache AS ext-builder
ARG SQLITE_DATABASE_INTEGRATION_VERSION

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

RUN apt-get update && apt-get install -y --no-install-recommends \
      curl ca-certificates build-essential pkg-config clang libclang-dev git \
    && rm -rf /var/lib/apt/lists/*

RUN curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y
ENV PATH="/root/.cargo/bin:${PATH}"

# The 3.0 GitHub tarball excludes the `packages` contents, so we must clone.
RUN git clone --depth 1 --branch "v${SQLITE_DATABASE_INTEGRATION_VERSION}" \
      https://github.com/WordPress/sqlite-database-integration.git /src && \
    cd /src/packages/php-ext-wp-mysql-parser && \
    PHP_CONFIG="$(command -v php-config)" \
    LIBCLANG_PATH="$(dirname "$(find / -name 'libclang.so*' 2>/dev/null | head -n1)")" \
    cargo build --release && \
    cp target/release/libwp_mysql_parser.so /libwp_mysql_parser.so && \
    cp -R /src/packages/plugin-sqlite-database-integration /plugin && \
    rm /plugin/wp-includes/database && \
    cp -R /src/packages/mysql-on-sqlite/src /plugin/wp-includes/database && \
    rm -rf /plugin/composer.json /plugin/vendor /plugin/node_modules

# ---------- Stage 2: final WordPress + SQLite runtime image ----------
FROM wordpress:7.0.2-php8.5-apache
ARG SQLITE_DATABASE_INTEGRATION_VERSION
LABEL org.opencontainers.image.authors="soulteary@gmail.com"

SHELL ["/bin/bash", "-o", "pipefail", "-c"]
ENV WORDPRESS_PREPARE_DIR=/usr/src/wordpress
ENV SQLITE_DATABASE_INTEGRATION_VERSION=${SQLITE_DATABASE_INTEGRATION_VERSION}

# details: https://soulteary.com/2024/04/21/wordpress-sqlite-docker-image-packaging-details.html
COPY --from=ext-builder /plugin ${WORDPRESS_PREPARE_DIR}/wp-content/mu-plugins/sqlite-database-integration
RUN mv "${WORDPRESS_PREPARE_DIR}/wp-content/mu-plugins/sqlite-database-integration/db.copy" "${WORDPRESS_PREPARE_DIR}/wp-content/db.php" && \
    sed -i 's#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#/var/www/html/wp-content/mu-plugins/sqlite-database-integration#' "${WORDPRESS_PREPARE_DIR}/wp-content/db.php" && \
    sed -i 's#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#' "${WORDPRESS_PREPARE_DIR}/wp-content/db.php" && \
    mkdir "${WORDPRESS_PREPARE_DIR}/wp-content/database" && \
    touch "${WORDPRESS_PREPARE_DIR}/wp-content/database/.ht.sqlite" && \
    chmod 640 "${WORDPRESS_PREPARE_DIR}/wp-content/database/.ht.sqlite"

# Enable the native MySQL parser extension. The plugin auto-detects the
# pre-declared WP_MySQL_Native_* classes and switches to the fast path.
COPY --from=ext-builder /libwp_mysql_parser.so /usr/local/lib/php/extensions/wp_mysql_parser.so
RUN echo "extension=/usr/local/lib/php/extensions/wp_mysql_parser.so" > /usr/local/etc/php/conf.d/wp_mysql_parser.ini
