# Docker SQLite WordPress

![](.github/about.jpg)

WordPress with SQLite, ready to use out of the box.

- Based on [official image](https://hub.docker.com/_/wordpress), Easier and more sustainable solution.
- DockerHub Page: https://hub.docker.com/r/soulteary/sqlite-wordpress
- GHCR Page: https://github.com/soulteary/docker-sqlite-wordpress/pkgs/container/sqlite-wordpress
- Ships the optional native [`wp_mysql_parser`](https://wordpress.github.io/sqlite-database-integration/native-extension/) PHP extension for a faster MySQL lexer/parser path.

## Native MySQL Parser Extension

The image bundles [`sqlite-database-integration`](https://github.com/WordPress/sqlite-database-integration) `7.0.2-plugin-v3.0.0-rc.8` together with its optional native Rust extension `wp_mysql_parser`, which is compiled during the Docker build and enabled by default. The SQLite driver automatically detects the extension and switches to the native fast path (roughly 4.8x faster lexer and 15.5x faster parser per upstream benchmarks).

Verify it is loaded inside the container:

```bash
docker exec -it <container> php -m | grep wp_mysql_parser
```

## SQLite Diagnostics Page

The image bundles a read-only diagnostics must-use plugin, `sqlite-diagnostics.php`, dropped into `wp-content/mu-plugins/`. It adds a **Tools → SQLite Diagnostics** page (visible to administrators with the `manage_options` capability) that gathers the SQLite runtime state into one place: whether the native `wp_mysql_parser` extension is loaded and which parse path is active, the SQLite version and source id, PHP/architecture and `pdo_sqlite` details, the SQLite drop-in version and database file path/size, and the bundled `sqlite-database-integration` plugin version. The page performs no writes and probes the SQLite version through an in-memory database, so the live site database is never touched.

Cross-check the same values from the CLI inside the container:

```bash
docker exec -it <container> php -m | grep wp_mysql_parser
docker exec -it <container> php -r 'echo (new PDO("sqlite::memory:"))->query("SELECT sqlite_version()")->fetchColumn(), PHP_EOL;'
docker exec -it <container> php -r 'echo (new PDO("sqlite::memory:"))->query("SELECT sqlite_source_id()")->fetchColumn(), PHP_EOL;'
```

## SELECT id Key Case Fix

The image also bundles a small companion must-use plugin, `sqlite-select-id-key-fix.php`, dropped into `wp-content/mu-plugins/`. WordPress auto-loads files in the mu-plugins root, so it is always active and cannot be accidentally disabled.

It works around a difference between MySQL and SQLite: MySQL echoes back the identifier casing written in the query (e.g. `SELECT P.id` yields the key `id`), while SQLite returns the real declared column name (e.g. `ID`) for an un-aliased column. That mismatch leaves `$item['id']` / `$row->id` empty in some code paths. The plugin conservatively restores the written casing for safe single-table `SELECT` results (`ARRAY_A` / `OBJECT`), leaving anything it cannot fully reason about untouched.

Verify it is present inside the container:

```bash
docker exec -it <container> ls -l /var/www/html/wp-content/mu-plugins/
```

## Articles

- [WordPress SQLite Docker image packaging details](https://soulteary.com/2024/04/21/wordpress-sqlite-docker-image-packaging-details.html)
- [WordPress farewell to MySQL: Docker SQLite WordPress](https://soulteary.com/2024/04/17/say-goodbye-to-mysql-docker-sqlite-wordpress.html)

## Quick Start

You can download GitHub's clean and secure docker image using the following command:

```bash
# Docker Hub: use latest
docker pull soulteary/sqlite-wordpress
# Docker Hub: use specify version
docker pull soulteary/sqlite-wordpress:7.0.2
# GHCR: use latest
docker pull ghcr.io/soulteary/sqlite-wordpress:latest
# GHCR: use specify version
docker pull ghcr.io/soulteary/sqlite-wordpress:7.0.2
```

Use the following command to quickly launch the wordpress with port `8080`:

```bash
docker run --rm -it -p 8080:80 -v `pwd`/wordpress:/var/www/html soulteary/sqlite-wordpress
# or use GHCR
docker run --rm -it -p 8080:80 -v `pwd`/wordpress:/var/www/html ghcr.io/soulteary/sqlite-wordpress:latest
```

You can also use docker compose to start wordpress:

```yaml
version: '3'

services:

  wordpress:
    image: soulteary/sqlite-wordpress:7.0.2
    # or use: ghcr.io/soulteary/sqlite-wordpress:7.0.2
    restart: always
    ports:
      - 8080:80
    volumes:
      - ./wordpress:/var/www/html
```

Save the file as `docker-compose.yml` and then execute `docker compose up`, then use browser access to `localhost:8080`.

![](.github/ready-to-use.jpg)

Use the quick 1-minute initial installation, enjoy.

### Volume / upgrade note

This image is **self-healing**: its entrypoint reconciles the SQLite drop-in
(`wp-content/db.php`) and the SQLite must-use plugins into the live document
root on **every** container start, regardless of the mounted volume's state.

This matters because the stock WordPress entrypoint only seeds a mounted volume
from the image's `/usr/src/wordpress` when the volume looks empty (missing
`index.php` / `wp-includes/version.php`). An **old, already-initialized**
`./wordpress` volume (for example one created by a previous image version, or a
reused named volume) would otherwise **never** receive the SQLite drop-in, so
WordPress would fall back to MySQL and show "Error establishing a database
connection". The bundled entrypoint fixes this by copying `db.php` and the
SQLite mu-plugins in on start when they are missing or stale — no manual volume
reset is required when upgrading.

Verify the SQLite drop-in is present inside the container:

```bash
docker exec -it <container> ls -l /var/www/html/wp-content/db.php
```

## Contributing

Contributions are welcome! Please read the [Contributing Guide](./CONTRIBUTING.md) and the [Code of Conduct](./CODE_OF_CONDUCT.md) before getting started.

## Security

Found a security issue? Please review our [Security Policy](./SECURITY.md) and report it privately instead of opening a public issue.
