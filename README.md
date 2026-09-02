# Docker SQLite WordPress

![](.github/about.jpg)

WordPress with SQLite, ready to use out of the box.

> **Latest container release:**
> [`2026.09.02-r2`](https://github.com/soulteary/docker-sqlite-wordpress/releases/tag/2026.09.02-r2)
> is published to Docker Hub and GHCR as matching five-platform indexes at
> `sha256:9a5ceb20d80485de3e71bfe4a454f913408c6cdc995df17c2805e90fea5a049a`.
> The verified `2026.09.02` and `latest` aliases were promoted from the same
> release. Pin the exact CalVer tag or manifest digest for reproducible
> deployments.

- Based on [official image](https://hub.docker.com/_/wordpress), Easier and more sustainable solution.
- DockerHub Page: https://hub.docker.com/r/soulteary/sqlite-wordpress
- GHCR Page: https://github.com/soulteary/docker-sqlite-wordpress/pkgs/container/sqlite-wordpress
- Ships the optional native [`wp_mysql_parser`](https://wordpress.github.io/sqlite-database-integration/native-extension/) PHP extension for a faster MySQL lexer/parser path.

## Native MySQL Parser Extension

The image bundles WordPress `7.1.0` on PHP 8.5/Apache and [`sqlite-database-integration`](https://github.com/WordPress/sqlite-database-integration) `v3.0.1` together with its optional native Rust extension `wp_mysql_parser`. The extension is compiled and enabled on `amd64` and `arm64`; published 32-bit ARM variants use the plugin's pure-PHP fallback. The SQLite driver detects the available implementation automatically (the upstream project reports roughly 4.8x faster lexing and 15.5x faster parsing for the native path).

Verify it is loaded inside the container:

```bash
docker exec -it <container> php -m | grep wp_mysql_parser
```

## SQLite Diagnostics Page

The image bundles a read-only diagnostics must-use plugin, `sqlite-diagnostics.php`, dropped into `wp-content/mu-plugins/`. It adds a **Tools → SQLite Diagnostics** page (visible to administrators with the `manage_options` capability) that gathers the SQLite runtime state into one place: whether the native `wp_mysql_parser` extension is loaded and which parse path is active, the SQLite version and source id, PHP/architecture and `pdo_sqlite` details, the SQLite drop-in version and database file path/size, and the bundled `sqlite-database-integration` plugin version. It also detects the native lexer and parser independently, reads key PRAGMA values (journal mode, synchronous, page/cache sizes, foreign keys, etc.) from the live site connection, and breaks down on-disk storage into the main file plus its WAL and SHM sidecars and their combined total. The page performs no writes and probes the SQLite version through an in-memory database, so the live site database is never modified.

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

- [WordPress SQLite Docker image packaging details, two years later](https://soulteary.com/2026/08/08/wordpress-sqlite-docker-image-packaging-details-two-years-later.html)
- [WordPress SQLite Docker image packaging details](https://soulteary.com/2024/04/21/wordpress-sqlite-docker-image-packaging-details.html)
- [WordPress farewell to MySQL: Docker SQLite WordPress](https://soulteary.com/2024/04/17/say-goodbye-to-mysql-docker-sqlite-wordpress.html)

## Quick Start

Pull the immutable CalVer release for reproducible deployments, or use the
rolling `latest` alias when automatic version movement is intentional:

```bash
# Docker Hub: use an immutable release
docker pull soulteary/sqlite-wordpress:2026.09.02-r2
# GHCR: use an immutable release
docker pull ghcr.io/soulteary/sqlite-wordpress:2026.09.02-r2
# Docker Hub: use latest
docker pull soulteary/sqlite-wordpress
# GHCR: use latest
docker pull ghcr.io/soulteary/sqlite-wordpress:latest
```

Launch the published image on port `8080`:

```bash
docker run --rm -it -p 127.0.0.1:8080:80 \
  -v "$(pwd)/wordpress:/var/www/html" \
  soulteary/sqlite-wordpress:2026.09.02-r2
```

You can also use docker compose to start wordpress:

```yaml
services:

  wordpress:
    image: soulteary/sqlite-wordpress:2026.09.02-r2
    restart: always
    ports:
      # Safe local default. Change this only when intentionally publishing the
      # site through a firewall or TLS reverse proxy.
      - 127.0.0.1:8080:80
    volumes:
      - ./wordpress:/var/www/html
```

Save the file as `docker-compose.yml` and execute `docker compose up -d`,
then use a browser to access `localhost:8080`.

![](.github/ready-to-use.jpg)

Use the quick 1-minute initial installation, enjoy.

## Page Performance Information

The image includes a disabled-by-default `sqlite-wordpress-performance.php`
must-use plugin. Enable it under **Settings → Page Performance** to show the
server-side page generation time, current PHP memory usage, and peak PHP memory
usage in the WordPress toolbar on both front-end and administration pages. A
compact status row is also added to the bottom of normal public pages.

The footer is visible to public visitors while enabled; the toolbar value is
shown only when the WordPress toolbar itself is visible. Metrics are sampled
near the end of page output, so they describe PHP/WordPress generation work and
do not include network transfer, browser rendering, or later asynchronous
requests.

Container operators can override the administrator setting with the exact
lowercase boolean environment variable
`WORDPRESS_PAGE_PERFORMANCE_ENABLED=true` or
`WORDPRESS_PAGE_PERFORMANCE_ENABLED=false`. While the variable is present, the
setting is read-only in WordPress and its stored value is preserved. Remove the
variable and recreate the container to return control to the administration
page. Invalid values keep the display disabled and raise an administrator
notice.

```yaml
services:
  wordpress:
    environment:
      WORDPRESS_PAGE_PERFORMANCE_ENABLED: "true"
```

## SMTP and OwlMail Integration

The image includes a disabled-by-default `sqlite-wordpress-smtp.php` must-use
plugin. It routes WordPress `wp_mail()` calls through SMTP without requiring a
third-party mail plugin. Administrators can configure it under
**Settings → SMTP**. The page covers the transport state, host, port,
encryption, automatic TLS, authentication, username/password, forced From
identity, and connection timeout.

Each setting may instead be controlled by an environment variable. An
environment value overrides only its matching database-backed field; the page
shows the effective value as read-only and preserves the stored value for use
after the override is removed. Boolean environment values must use the exact
lowercase strings `true` or `false`. Invalid enabled SMTP configuration blocks
`wp_mail()` and emits `wp_mail_failed` instead of silently falling back to PHP
`mail()`.

| Setting | Environment variable | Default |
| --- | --- | --- |
| Enable transport | `WORDPRESS_SMTP_ENABLED` | `false` |
| Host | `WORDPRESS_SMTP_HOST` | `owlmail` |
| Port | `WORDPRESS_SMTP_PORT` | `1025` |
| Encryption | `WORDPRESS_SMTP_ENCRYPTION` (`none`, `tls`, `ssl`) | `none` |
| Automatic TLS | `WORDPRESS_SMTP_AUTO_TLS` | `false` |
| Authentication | `WORDPRESS_SMTP_AUTH` | `false` |
| Username | `WORDPRESS_SMTP_USERNAME` | empty |
| Password | `WORDPRESS_SMTP_PASSWORD` | empty |
| Password file | `WORDPRESS_SMTP_PASSWORD_FILE` | empty |
| From email | `WORDPRESS_SMTP_FROM_EMAIL` | empty |
| From name | `WORDPRESS_SMTP_FROM_NAME` | empty |
| Force From | `WORDPRESS_SMTP_FORCE_FROM` | `false` |
| Connection timeout | `WORDPRESS_SMTP_TIMEOUT` | `10` seconds |

Do not configure `WORDPRESS_SMTP_PASSWORD` and
`WORDPRESS_SMTP_PASSWORD_FILE` together. The file form is preferred for
deployments because a direct password is visible in container environment
metadata. A password entered in the administration page is stored in the
WordPress database and is never rendered back into the form.

### Start with OwlMail

The optional [`docker-compose.owlmail.yml`](./docker-compose.owlmail.yml)
overlay starts OwlMail on the same private Compose network, persists its
messages, and publishes only its Web inbox to the host:

```bash
docker compose \
  -f docker-compose.yml \
  -f docker-compose.owlmail.yml \
  up -d
```

Open WordPress at `http://localhost:8080`, visit **Settings → SMTP**, and enable
SMTP. The stored defaults already target `owlmail:1025` with encryption,
automatic TLS, and authentication disabled. View captured messages at
`http://localhost:1080`.

For a fully environment-controlled OwlMail start, export the fields forwarded
by the overlay and recreate the WordPress container:

```bash
export WORDPRESS_SMTP_ENABLED=true
export WORDPRESS_SMTP_HOST=owlmail
export WORDPRESS_SMTP_PORT=1025
export WORDPRESS_SMTP_ENCRYPTION=none
export WORDPRESS_SMTP_AUTO_TLS=false
export WORDPRESS_SMTP_AUTH=false
docker compose \
  -f docker-compose.yml \
  -f docker-compose.owlmail.yml \
  up -d --force-recreate
```

Send a test message through the real WordPress mail path:

```bash
docker compose \
  -f docker-compose.yml \
  -f docker-compose.owlmail.yml \
  exec wordpress php -r \
  'require "/var/www/html/wp-load.php"; exit(wp_mail("test@example.com", "OwlMail test", "Hello from WordPress") ? 0 : 1);'
```

OwlMail's incoming SMTP username/password settings are not currently an access
control boundary. The overlay therefore does not publish port `1025` to the
host. Keep it on a trusted private network; protect the Web inbox separately
when it is exposed beyond loopback. For real delivery, configure a trusted
outbound SMTP service and TLS/authentication settings rather than exposing the
OwlMail listener publicly.

## Image Versions and Supply-chain Evidence

Image releases use CalVer such as `2026.09.02-r2`; WordPress and SQLite
Integration keep their own component versions. Exact `YYYY.MM.DD-rN` tags are
immutable, while the date-only tag and `latest` are verified mutable aliases.
Pin an exact tag or manifest digest in production. See
[VERSIONING.md](./VERSIONING.md) for the complete policy.

Every successfully published container release includes an SPDX SBOM,
maximum-mode SLSA provenance, the source commit in OCI
`org.opencontainers.image.revision`, and a keyless Sigstore signature on each
Docker Hub and GHCR manifest. Verification commands and the expected GitHub
Actions identity are documented in
[RELEASING.md](./RELEASING.md). The repository and bundled-component license
inventory is in [LICENSES.md](./LICENSES.md).

## Deployment Suitability and Migration Boundary

This image is intended for new WordPress installations and sites that already
use SQLite. It is **not** a MySQL or MariaDB migration tool. Pointing an existing
MySQL-backed WordPress volume at this image does not copy its database; the
SQLite integration starts from a separate SQLite database. Migrate the content
with a dedicated, verified migration workflow before switching images.

SQLite supports concurrent readers but only one writer at a time. It is a good
fit for local development and many small or read-heavy sites, but MySQL or
MariaDB may be more appropriate for workloads with sustained concurrent writes.
Before production use, test the expected traffic, themes, plugins, scheduled
jobs, and backup/restore procedure. See the upstream
[`sqlite-database-integration` production and migration guidance](https://github.com/WordPress/sqlite-database-integration/blob/v3.0.1/packages/plugin-sqlite-database-integration/readme.txt)
for the underlying compatibility boundary.

## Emergency Site URL Recovery Tool

The image includes `/tool-update-site-url.php` for recovering a site after an
incorrect domain, scheme, port, or subdirectory was saved in WordPress. The
standalone page remains reachable even when the normal site or `wp-admin`
redirects to the old address. It updates both settings in one SQLite
transaction:

- **WordPress Address (URL)** → the `siteurl` option (where WordPress core files
  are located).
- **Site Address (URL)** → the `home` option (the public visitor-facing URL).

This is an access-recovery tool, not a complete domain-migration utility. It
does not rewrite links in posts, serialized plugin data, media URLs, theme
settings, redirects, CDN configuration, or reverse-proxy rules. Complete those
changes separately after access to WordPress has been restored.

### Security model

The endpoint is disabled by default and returns `404 Not Found` unless both of
these independent conditions are met:

1. `WORDPRESS_SITE_URL_UPDATE_TOOL_ENABLED` is set to the exact lowercase value
   `true`.
2. Exactly one strong recovery credential is configured.

Values such as `1`, `yes`, or `TRUE` do not enable the tool. A Docker secret is
preferred for the token so it does not appear in `docker inspect` output.
Choose exactly one of these credential sources:

| Variable | Minimum length | Notes |
| --- | ---: | --- |
| `WORDPRESS_SITE_URL_UPDATE_TOKEN_FILE` | 32 characters | Preferred. Reads a Docker secret or mounted file. |
| `WORDPRESS_SITE_URL_UPDATE_PASSWORD` | 24 characters | Direct password; visible in container environment metadata. Use a generated value or long passphrase. |

The generic variable `PASSWORD` is intentionally ignored to avoid collisions
with unrelated software. Configure either TOKEN_FILE for a file-backed token or
PASSWORD for a direct environment value; do not configure both supported
sources at the same time.

Authentication is globally throttled across all PHP workers. Five failed
credentials within 15 minutes lock the endpoint for 15 minutes and return
`429 Too Many Requests`. A correct credential reserves one operation for five
minutes so concurrent requests cannot reuse it. Immediately before the SQLite
write, that authorization is permanently consumed: the current PHP worker sets
`WORDPRESS_SITE_URL_UPDATE_TOOL_ENABLED=false`, removes its credential values,
and writes a one-shot state file beside the SQLite database. Other workers and
container restarts continue returning `404`, even though an application cannot
rewrite Docker's configured environment.

The global lock prevents distributed guessing without trusting forwarded IP
headers, but an exposed attacker could deliberately trigger the temporary
lockout. Keep the endpoint on a private network or IP allowlist and add reverse
proxy rate limiting when it must be internet-reachable. Always use TLS outside
the local machine.

The examples below bind the recovery endpoint to `127.0.0.1`. If it must pass
through a reverse proxy, restrict this exact path by source IP, disable proxy
caching, preserve POST requests, and forward the original `Host` and
`X-Forwarded-Proto` headers. Do not publish the endpoint directly on every host
interface merely to complete a repair.

### Preferred TOKEN_FILE example

Create a random token file:

```bash
mkdir -p secrets
openssl rand -hex 32 > secrets/site-url-update-token
chmod 600 secrets/site-url-update-token
```

```yaml
services:

  wordpress:
    image: soulteary/sqlite-wordpress:2026.09.02-r2
    ports:
      - 127.0.0.1:8080:80
    environment:
      WORDPRESS_SITE_URL_UPDATE_TOOL_ENABLED: "true"
      WORDPRESS_SITE_URL_UPDATE_TOKEN_FILE: /run/secrets/site-url-update-token
    secrets:
      - site-url-update-token
    volumes:
      - ./wordpress:/var/www/html

secrets:
  site-url-update-token:
    file: ./secrets/site-url-update-token
```

### PASSWORD Compose example

`WORDPRESS_SITE_URL_UPDATE_PASSWORD` is intended only for short-lived local or
otherwise network-restricted recovery. Generate and export a fresh value rather
than committing it to Compose or `.env`:

```bash
export WORDPRESS_SITE_URL_UPDATE_PASSWORD="$(openssl rand -base64 24)"
```

```yaml
services:

  wordpress:
    image: soulteary/sqlite-wordpress:2026.09.02-r2
    ports:
      - 127.0.0.1:8080:80
    environment:
      WORDPRESS_SITE_URL_UPDATE_TOOL_ENABLED: "true"
      WORDPRESS_SITE_URL_UPDATE_PASSWORD: "${WORDPRESS_SITE_URL_UPDATE_PASSWORD:?export a recovery password first}"
    volumes:
      - ./wordpress:/var/www/html
```

The repository's [`docker-compose.yml`](./docker-compose.yml) contains the same
PASSWORD settings with safe disabled/empty defaults and commented TOKEN_FILE
alternatives. Because direct passwords appear in `docker inspect`, prefer the
TOKEN_FILE example for shared hosts.

### docker run examples

For TOKEN_FILE, mount the generated file read-only and publish the endpoint only
on the loopback interface:

```bash
docker run --rm -it \
  --name sqlite-wordpress-recovery \
  --publish 127.0.0.1:8080:80 \
  --volume "$(pwd)/wordpress:/var/www/html" \
  --volume "$(pwd)/secrets/site-url-update-token:/run/secrets/site-url-update-token:ro" \
  --env WORDPRESS_SITE_URL_UPDATE_TOOL_ENABLED=true \
  --env WORDPRESS_SITE_URL_UPDATE_TOKEN_FILE=/run/secrets/site-url-update-token \
  soulteary/sqlite-wordpress:2026.09.02-r2
```

For PASSWORD, export a fresh value and pass only the variable name so the shell
does not place the secret directly in the command arguments:

```bash
export WORDPRESS_SITE_URL_UPDATE_PASSWORD="$(openssl rand -base64 24)"
docker run --rm -it \
  --name sqlite-wordpress-recovery \
  --publish 127.0.0.1:8080:80 \
  --volume "$(pwd)/wordpress:/var/www/html" \
  --env WORDPRESS_SITE_URL_UPDATE_TOOL_ENABLED=true \
  --env WORDPRESS_SITE_URL_UPDATE_PASSWORD \
  soulteary/sqlite-wordpress:2026.09.02-r2
```

After the update, stop this temporary container and start the normal deployment
without the enable switch or recovery credential. That disabled start performs
the documented cleanup.

### Recovery workflow and automatic shutdown

1. Back up `wp-content/database/`.
2. Configure the exact enable value `true` and exactly one credential, then
   recreate the container.
3. Open `http://localhost:8080/tool-update-site-url.php`. Enter the credential
   and both new addresses. The credential is accepted only in the POST body;
   never append it to the URL.
4. Verify both the public site and `wp-admin` at their new addresses. The first
   authenticated write attempt consumes the authorization and automatically
   hides the endpoint. This remains true even if the database update reports an
   error, so inspect the container logs and current option values before doing
   anything else.
5. Remove `WORDPRESS_SITE_URL_UPDATE_TOOL_ENABLED` and the selected credential
   from Compose, then recreate the container. This disabled start clears the
   internal used-state file while keeping the endpoint disabled.

To deliberately rearm the tool later, first start the container once with the
enable setting removed or set to `false`. Then configure a new credential, set
the exact value `true`, and recreate it again. Merely restarting with the same
enabled configuration never clears a consumed authorization.

The managed authorization state consists of
`wp-content/database/.ht.site-url-update-tool-state` and its stable `.lock`
file. Do not delete, edit, replace, or restore either file while the endpoint is
enabled; the entrypoint removes both safely during the documented disabled
start. A missing or malformed member of an existing state pair fails closed.

### Accepted URL rules

| Rule | Accepted examples / behavior |
| --- | --- |
| Absolute HTTP(S) URL | `https://example.com`, `http://localhost:8080` |
| Maximum length | 2048 bytes per submitted address. |
| Optional port or subdirectory | `https://example.com:8443/wordpress`; explicit ports must be between 1 and 65535. |
| Local and private hosts | Localhost, private IPv4, and bracketed IPv6 addresses are accepted. |
| Internationalized hostnames | Use their ASCII/Punycode form. |
| Trailing slash | Removed before the option is stored. |
| Rejected components | Embedded username/password, query string, fragment, leading, trailing, or internal whitespace, control characters, backslash, or `.` / `..` path segments. |

Both fields are required even when their values are identical. Use different
values only when WordPress core is installed in a subdirectory, for example
`siteurl=https://example.com/wordpress` and `home=https://example.com`.

### Troubleshooting

Check detailed server-side errors with `docker compose logs wordpress`. The
endpoint intentionally avoids returning sensitive configuration details.

| HTTP status | Meaning | Action |
| ---: | --- | --- |
| `404` | Disabled, missing credential, or one-shot authorization already used. | Check the exact enable value and follow the deliberate rearm sequence if a previous attempt consumed it. |
| `403` | Invalid credential before the failure threshold. | Check the selected TOKEN_FILE or PASSWORD source; do not configure both sources. |
| `405` | Unsupported request method. | Open with GET and submit the form with POST. |
| `409` | Another authenticated request is active, Multisite is enabled, or `WP_HOME` / `WP_SITEURL` overrides the database. | Wait for the active request, or correct the unsupported WordPress configuration. |
| `422` | A submitted URL failed validation. | Apply the accepted URL rules above and submit both fields again. |
| `429` | Five failed credentials triggered the 15-minute global lockout. | Wait for `Retry-After`, inspect logs, and restrict network access before retrying. |
| `500` | The update or one-shot state transition failed; the authorization may already have been consumed. | Inspect logs and both option values, confirm whether the endpoint now returns 404, then deliberately rearm only if another attempt is required. |
| `503` | Invalid credential configuration, unreadable state/database directory, or missing WordPress runtime. | Check logs, file mounts, permissions, and mutually exclusive credential settings. |

The tool intentionally refuses WordPress Multisite installations. It also
refuses to write when `WP_HOME` or `WP_SITEURL` is defined in `wp-config.php`,
because those constants override the database values; update or remove the
constants instead.

If WordPress still redirects to the old address after a successful update,
check `WP_HOME` / `WP_SITEURL`, page and object caches, CDN or proxy redirects,
and forwarded `Host` / `X-Forwarded-Proto` headers. The recovery tool changes
only the two database options and cannot repair content URLs or external proxy
configuration.

## Emergency User Password Reset Tool

The image also includes `/tool-reset-user-password.php` for local account
recovery when no administrator can sign in. Once explicitly enabled, the page
loads the single-site WordPress user list into a dropdown (login name and
display name), accepts a new password plus confirmation, and resets the
selected account. It does not display email addresses or existing password
hashes.

The endpoint is disabled by default and returns `404 Not Found` unless
`WORDPRESS_USER_PASSWORD_RESET_TOOL_ENABLED` is exactly `true` and exactly one
independent recovery credential is configured:

| Variable | Minimum length | Notes |
| --- | ---: | --- |
| `WORDPRESS_USER_PASSWORD_RESET_TOKEN_FILE` | 32 characters | Preferred Docker secret or mounted token file. |
| `WORDPRESS_USER_PASSWORD_RESET_PASSWORD` | 24 characters | Direct recovery credential, visible in container metadata. |

It uses the same protection model as the site URL tool: five invalid recovery
credentials in 15 minutes cause a global 15-minute lockout, only one reset may
run at a time, and the first authenticated write attempt consumes the one-shot
authorization before changing SQLite. The user account's new password must be
12–4096 characters and invalidates its existing WordPress login sessions.

For a short-lived loopback-only reset using the direct credential mode:

```bash
export WORDPRESS_USER_PASSWORD_RESET_PASSWORD="$(openssl rand -base64 24)"
export WORDPRESS_USER_PASSWORD_RESET_TOOL_ENABLED=true
docker compose up -d --force-recreate
```

Then open `http://localhost:8080/tool-reset-user-password.php`, choose the user,
enter and confirm the new account password, and submit the recovery credential.
After verifying the new login, remove both environment variables and recreate
the container. This disabled start safely removes
`wp-content/database/.ht.user-password-reset-tool-state` and its `.lock` file,
rearming a future recovery cycle without exposing the endpoint.

For shared hosts, use a new file containing `openssl rand -hex 32`, mount it
read-only, and set `WORDPRESS_USER_PASSWORD_RESET_TOKEN_FILE` instead of the
direct recovery password. Keep the endpoint bound to loopback or restricted by
a TLS reverse proxy and source-IP allowlist while enabled. The tool intentionally
refuses Multisite installations.

## Volume and Upgrade Notes

Back up `wp-content/database/` before upgrading an existing site to the
WordPress 7.1.0 / SQLite Database Integration 3.0.1 image. Version 3.0.1
requires a non-empty `DB_NAME` in custom `wp-config.php` files and uses WAL
journaling by default, so the database's `-wal` and `-shm` sidecar files must
remain on the same persistent volume as the main SQLite file.

### Updating Core in a Persistent Document Root

Mounting all of `/var/www/html` persists WordPress core as well as content. The
official WordPress entrypoint seeds core only when that directory is empty, so
pulling a newer container image does **not** overwrite core in an initialized
volume.

This image keeps that non-destructive behavior. At build time it creates a
deterministic, no-content core ZIP from the exact pinned WordPress base and
stores it read-only under `/usr/src/wordpress-upgrades/`. The managed
`sqlite-local-core-update.php` must-use plugin validates the archive's embedded
SHA-256 and, when WordPress's normal update offer targets exactly that bundled
version, replaces its download package with a private temporary copy of the
local archive. The standard `Core_Upgrader` still performs the update and its
normal checks; `wp-content` is never included in the archive.

WordPress 7.1 reports its stable update offer as `7.1`, while this image records
the bundled package as `7.1.0`. Stable versions that differ only by trailing
zero components are treated as equivalent, so that offer resolves to
`/usr/src/wordpress-upgrades/wordpress-7.1.0-no-content.zip`. Pre-release and
non-numeric version offers are never redirected to a local package.

After changing the image, back up the full site and database, recreate the
container, then run the usual update from **Dashboard → Updates** or WP-CLI.
The integration never downgrades a newer installation, never redirects rollback
packages, and leaves WordPress.org's package URL unchanged if the local archive
is missing or fails validation. To require remote packages instead, set the
exact value:

```yaml
environment:
  WORDPRESS_LOCAL_CORE_UPDATE_ENABLED: "false"
```

Any other explicit value fails closed. Merely starting the new container never
rewrites persisted core; the operator still initiates the normal WordPress
upgrade, keeping backups and maintenance timing under deployment control.

### Safe Backup and Restore

Do not copy only the main SQLite file while WordPress is running. Stop the
container first so the database and its WAL sidecars form one consistent
snapshot. Run the archive helper as container root: the entrypoint makes the
database and recovery state readable by `www-data`, so an unprivileged host
user cannot reliably archive every file from a bind mount. The helper reserves
the requested filename across containers, writes and verifies the archive in
that reservation, and publishes it with an atomic no-overwrite hard link.

For the repository's default `./wordpress` bind mount:

```bash
mkdir -p backups
backup_name="wordpress-database-$(date -u +%Y%m%dT%H%M%SZ).tar.gz"
docker compose stop wordpress &&
docker run --rm --user 0:0 \
  --entrypoint bash \
  --mount type=bind,source="$(pwd)/wordpress",target=/source,readonly \
  --mount type=bind,source="$(pwd)/backups",target=/backup \
  --env "BACKUP_NAME=${backup_name}" \
  soulteary/sqlite-wordpress:2026.09.02-r2 \
  -Eeuo pipefail -c '
    [[ -n "${BACKUP_NAME}" && "${BACKUP_NAME}" != */* ]]
    archive="/backup/${BACKUP_NAME}"
    reservation="/backup/.${BACKUP_NAME}.reserve"
    temporary="${reservation}/archive.tar.gz"
    mkdir -- "${reservation}"
    cleanup() {
      rm -f -- "${temporary}"
      rmdir -- "${reservation}" 2>/dev/null || true
    }
    trap cleanup EXIT
    test ! -e "${archive}" && test ! -L "${archive}"
    tar -C /source/wp-content -czf "${temporary}" database
    tar -tzf "${temporary}" >/dev/null
    ln -- "${temporary}" "${archive}"
    cleanup
    trap - EXIT
  ' &&
docker compose start wordpress
```

For a named volume, replace `<wordpress-volume>` with the volume mounted at
`/var/www/html`:

```bash
mkdir -p backups
wordpress_volume="replace-with-your-wordpress-volume-name"
backup_name="wordpress-database-$(date -u +%Y%m%dT%H%M%SZ).tar.gz"
docker compose stop wordpress &&
docker run --rm --user 0:0 \
  --entrypoint bash \
  --mount "type=volume,source=${wordpress_volume},target=/source,readonly" \
  --mount type=bind,source="$(pwd)/backups",target=/backup \
  --env "BACKUP_NAME=${backup_name}" \
  soulteary/sqlite-wordpress:2026.09.02-r2 \
  -Eeuo pipefail -c '
    [[ -n "${BACKUP_NAME}" && "${BACKUP_NAME}" != */* ]]
    archive="/backup/${BACKUP_NAME}"
    reservation="/backup/.${BACKUP_NAME}.reserve"
    temporary="${reservation}/archive.tar.gz"
    mkdir -- "${reservation}"
    cleanup() {
      rm -f -- "${temporary}"
      rmdir -- "${reservation}" 2>/dev/null || true
    }
    trap cleanup EXIT
    test ! -e "${archive}" && test ! -L "${archive}"
    tar -C /source/wp-content -czf "${temporary}" database
    tar -tzf "${temporary}" >/dev/null
    ln -- "${temporary}" "${archive}"
    cleanup
    trap - EXIT
  ' &&
docker compose start wordpress
```

Restore only while the container is stopped. Keep the current directory as a
recoverable rollback copy instead of deleting it:

```bash
backup_name="replace-with-your-backup.tar.gz"
docker compose stop wordpress &&
docker run --rm --user 0:0 \
  --entrypoint bash \
  --mount type=bind,source="$(pwd)/wordpress",target=/source \
  --mount type=bind,source="$(pwd)/backups",target=/backup,readonly \
  --env "BACKUP_NAME=${backup_name}" \
  soulteary/sqlite-wordpress:2026.09.02-r2 \
  -Eeuo pipefail -c '
    [[ -n "${BACKUP_NAME}" && "${BACKUP_NAME}" != */* ]]
    archive="/backup/${BACKUP_NAME}"
    content=/source/wp-content
    previous="${content}/database.before-restore"
    test -s "${archive}"
    tar -tzf "${archive}" >/dev/null
    test ! -e "${previous}"
    mv -- "${content}/database" "${previous}"
    restored=false
    rollback() {
      if [[ "${restored}" != true ]]; then
        rm -rf -- "${content}/database"
        mv -- "${previous}" "${content}/database"
      fi
    }
    trap rollback EXIT
    tar -C "${content}" -xzf "${archive}"
    test -d "${content}/database"
    restored=true
    trap - EXIT
  ' &&
docker compose up -d
```

For a named volume, use the same image to retain the current database as a
rollback directory before extracting the archive:

```bash
wordpress_volume="replace-with-your-wordpress-volume-name"
backup_name="replace-with-your-backup.tar.gz"
docker compose stop wordpress &&
docker run --rm --user 0:0 \
  --entrypoint bash \
  --mount "type=volume,source=${wordpress_volume},target=/source" \
  --mount type=bind,source="$(pwd)/backups",target=/backup,readonly \
  --env "BACKUP_NAME=${backup_name}" \
  soulteary/sqlite-wordpress:2026.09.02-r2 \
  -Eeuo pipefail -c '
    [[ -n "${BACKUP_NAME}" && "${BACKUP_NAME}" != */* ]]
    archive="/backup/${BACKUP_NAME}"
    content=/source/wp-content
    previous="${content}/database.before-restore"
    test -s "${archive}"
    tar -tzf "${archive}" >/dev/null
    test ! -e "${previous}"
    mv -- "${content}/database" "${previous}"
    restored=false
    rollback() {
      if [[ "${restored}" != true ]]; then
        rm -rf -- "${content}/database"
        mv -- "${previous}" "${content}/database"
      fi
    }
    trap rollback EXIT
    tar -C "${content}" -xzf "${archive}"
    test -d "${content}/database"
    restored=true
    trap - EXIT
  ' &&
docker compose up -d
```

After a restore, verify the database through the active WordPress SQLite driver:

```bash
docker compose exec wordpress php -r '
require "/var/www/html/wp-load.php";
$pdo = $GLOBALS["wpdb"]->get_driver()->get_sqlite_pdo();
$result = $pdo->query("PRAGMA integrity_check")->fetchColumn();
fwrite(STDOUT, $result . PHP_EOL);
exit("ok" === $result ? 0 : 1);
'
```

Backups include the recovery tool's internal state files. After restoring or
cloning a volume, keep the tool disabled during the first container start so its
one-shot state is safely reset before any future recovery session.

These examples protect the SQLite database only. Back up uploads, themes,
plugins, and custom configuration separately when a full site restore is
required.

### SQLite Database Integration 3.0 Compatibility

Custom configurations should also review the upstream
[`3.0.0` breaking changes](https://github.com/WordPress/sqlite-database-integration/blob/v3.0.0/packages/plugin-sqlite-database-integration/readme.txt).
In particular, `WP_SQLITE_AST_DRIVER` and `DATABASE_ENGINE` were removed;
`DATABASE_TYPE`, `FQDBDIR`, and `FQDB` are deprecated; and integrations that
used the old driver classes or `$GLOBALS['@pdo']` must move to the public v3
driver APIs. These considerations apply to custom `wp-config.php` files and
plugins; the image's bundled defaults already use the v3 layout.

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
