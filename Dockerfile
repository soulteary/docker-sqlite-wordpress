FROM wordpress:6.4.3-php8.3-apache
LABEL org.opencontainers.image.authors="soulteary@gmail.com"

SHELL ["/bin/bash", "-o", "pipefail", "-c"]
ENV WORDPRESS_PREPARE_DIR=/usr/src/wordpress

# plugin: https://github.com/WordPress/sqlite-database-integration
ENV SQLITE_DATABASE_INTEGRATION_VERSION=2.1.9
RUN curl -L -o sqlite-database-integration.tar.gz "https://github.com/WordPress/sqlite-database-integration/archive/refs/tags/v${SQLITE_DATABASE_INTEGRATION_VERSION}.tar.gz" && \
    tar zxvf sqlite-database-integration.tar.gz && \
    mkdir -p ${WORDPRESS_PREPARE_DIR}/wp-content/mu-plugins/sqlite-database-integration && \
    cp -r sqlite-database-integration-${SQLITE_DATABASE_INTEGRATION_VERSION}/* ${WORDPRESS_PREPARE_DIR}/wp-content/mu-plugins/sqlite-database-integration/ && \
    rm -rf sqlite-database-integration-${SQLITE_DATABASE_INTEGRATION_VERSION} && \
    rm -rf sqlite-database-integration.tar.gz && \
    mv "${WORDPRESS_PREPARE_DIR}/wp-content/mu-plugins/sqlite-database-integration/db.copy" "${WORDPRESS_PREPARE_DIR}/wp-content/db.php" && \
    sed -i 's#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#/var/www/html/wp-content/mu-plugins/sqlite-database-integration#' "${WORDPRESS_PREPARE_DIR}/wp-content/db.php" && \
    sed -i 's#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#' "${WORDPRESS_PREPARE_DIR}/wp-content/db.php" && \
    mkdir "${WORDPRESS_PREPARE_DIR}/wp-content/database" && \
    touch "${WORDPRESS_PREPARE_DIR}/wp-content/database/.ht.sqlite" && \
    chmod 640 "${WORDPRESS_PREPARE_DIR}/wp-content/database/.ht.sqlite"

# https://github.com/wp-cli/wp-cli/blob/5a91c54f1ced8fac5486181bfd805083b22b7c96/php/WP_CLI/Runner.php#L1073C48-L1073C65
# ENV WP_CLI_ALLOW_ROOT=1
RUN apt update && apt install -y less && apt clean
RUN curl -L -o "wp-cli.phar" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && \
    chmod +x wp-cli.phar && \
    mv wp-cli.phar /usr/local/bin/wp
