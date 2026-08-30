# Contributing Guide

Thank you for your interest in contributing to **Docker SQLite WordPress**! This project builds on the official [WordPress image](https://hub.docker.com/_/wordpress), integrating [`sqlite-database-integration`](https://github.com/WordPress/sqlite-database-integration) together with its optional native Rust accelerator extension `wp_mysql_parser`. The goal is to provide a ready-to-use WordPress container image that requires no MySQL.

Before contributing, please read this guide along with the [Code of Conduct](./CODE_OF_CONDUCT.md).

## Table of Contents

- [Ways to Contribute](#ways-to-contribute)
- [Before You Start](#before-you-start)
- [Local Development Environment](#local-development-environment)
- [Building and Verifying the Image](#building-and-verifying-the-image)
  - [Repository Tests](#repository-tests)
- [Reporting Issues](#reporting-issues)
- [Submitting a Pull Request](#submitting-a-pull-request)
- [Code Style Conventions](#code-style-conventions)
- [Versioning and Releases](#versioning-and-releases)

## Ways to Contribute

There are many ways to get involved with this project:

- Report bugs or suggest improvements (open an Issue)
- Improve documentation (README, this guide, code comments, etc.)
- Fix defects or implement new features (open a Pull Request)
- Help test image behavior across platforms (especially `arm64` and 32-bit ARM)

## Before You Start

Make sure you have the following set up locally:

- [Docker](https://docs.docker.com/get-docker/) 20.10+ (with [Buildx](https://docs.docker.com/build/buildx/) support)
- [QEMU](https://docs.docker.com/build/building/multi-platform/) enabled, if you want to build multi-arch images
- Git

Fork the repository and clone it locally:

```bash
git clone https://github.com/<your-username>/docker-sqlite-wordpress.git
cd docker-sqlite-wordpress
```

## Local Development Environment

You can quickly spin up a WordPress instance for verification using `docker compose`:

```bash
docker compose up
```

Once started, visit `http://localhost:8080` to reach the WordPress setup wizard. The SQLite database file is persisted to the mounted `./wordpress` directory.

> Tip: If you want to verify an image you built locally, build it as described in the next section and temporarily replace the `image` field in `docker-compose.yml` with your local image tag.

## Building and Verifying the Image

### Local Single-Arch Build

Build and load the platform that matches your Docker host. Set
`TARGET_PLATFORM` to `linux/amd64` or `linux/arm64` as appropriate. Selecting a
different architecture requires emulation and does not test the native
extension on your host architecture.

```bash
TARGET_PLATFORM=linux/amd64
docker buildx build \
  --load \
  --platform "${TARGET_PLATFORM}" \
  --tag soulteary/sqlite-wordpress:dev \
  .
docker run --rm -it -p 127.0.0.1:8080:80 \
  -v "$(pwd)/wordpress:/var/www/html" \
  soulteary/sqlite-wordpress:dev
```

### Multi-Arch Build

This project supports `linux/amd64`, `linux/arm64`, `linux/arm/v7`, `linux/arm/v6`, and `linux/arm/v5`. The native Rust extension is compiled only on `amd64` and `arm64`; the other 32-bit ARM platforms automatically skip it and fall back to the pure-PHP parser (see the comments in the `Dockerfile`).

Buildx cannot load a multi-platform result into the classic local Docker image
store. Export an OCI archive explicitly when checking the complete platform
matrix locally:

```bash
docker buildx build \
  --platform linux/amd64,linux/arm64,linux/arm/v7,linux/arm/v6,linux/arm/v5 \
  --output type=oci,dest=/tmp/sqlite-wordpress-dev.oci \
  .
```

Use `--push` with an authorized test registry only when remote publication is
intentional. Never push a release tag from a contributor build.

### Repository Tests

Run the complete fast test set before opening a pull request:

```bash
bash tests/test-entrypoint-reconcile.sh
bash tests/test-documentation.sh
bash tests/test-validate-release.sh
php tests/test-sqlite-local-core-update.php
php tests/test-sqlite-select-id-key-fix.php
php tests/test-tool-update-site-url.php
./scripts/validate-release.sh 2026.08.30-r1
```

To reproduce the remaining lint and configuration checks:

```bash
mapfile -d '' shell_files < <(find . -type f -name '*.sh' -print0)
shellcheck "${shell_files[@]}"

while IFS= read -r -d '' php_file; do
  php -l "${php_file}"
done < <(find . -type f -name '*.php' -print0)

actionlint
docker compose config --quiet
```

CI additionally lints every PHP and shell file, runs ShellCheck and actionlint,
and smoke-tests amd64, native arm64, and the 32-bit ARM pure-PHP fallback when
packaged runtime files change. Changes to
`tool-update-site-url.php`, its entrypoint state handling, or its documentation
must preserve these security properties:

- the endpoint is a 404 unless the exact enable switch and one valid credential
  source are configured;
- the fifth invalid credential in 15 minutes starts a 15-minute global lockout;
- only one authenticated operation can run, and a write attempt consumes the
  authorization before updating SQLite;
- the persistent used state blocks new PHP workers and container restarts;
- state replacement is atomic and synchronized, while interrupted, empty,
  missing, malformed, or symbolic-link state fails closed;
- starting once with the enable value absent or not exactly `true` clears the
  used state without enabling the endpoint.

After building `soulteary/sqlite-wordpress:dev`, run the exact built-image
recovery smoke test used by CI:

```bash
docker run --rm \
  --volume "${PWD}/tests/image-smoke-site-url.php:/tmp/image-smoke-site-url.php:ro" \
  soulteary/sqlite-wordpress:dev php /tmp/image-smoke-site-url.php
```

### Verifying Key Functionality

After modifying the `Dockerfile`, integration, or recovery tool, please confirm
the following inside the container:

- Whether the native extension loads correctly per platform (`amd64` / `arm64` should load it; 32-bit ARM should fall back):

```bash
docker exec -it <container> php -m | grep wp_mysql_parser
```

- Whether the companion must-use plugin is in place:

```bash
docker exec -it <container> ls -l /var/www/html/wp-content/mu-plugins/
```

- Whether the SQLite database integration plugin completes installation and can create, read, update, and delete posts normally.
- Whether the bundled core package passes its SHA-256 check and the local core
  update plugin redirects only the exact matching forward/reinstall offer.
- Whether `/tool-update-site-url.php` is a 404 by default, accepts each
  documented credential mode when enabled, updates both options atomically, and
  becomes a 404 again immediately after one authenticated write attempt.

## Reporting Issues

Please search for existing issues before opening a new one. When reporting a bug, try to provide:

- The image tag used and the platform it runs on (architecture, OS)
- Steps to reproduce, plus expected vs. actual behavior
- Relevant logs (e.g. `docker logs <container>`)
- If it relates to the native extension, include the output of `php -m | grep wp_mysql_parser`

## Submitting a Pull Request

1. Create a feature branch from `main` with a name that clearly reflects its intent, e.g. `fix/sqlite-id-casing` or `feat/php-8.5-upgrade`.
2. Keep commits focused—one PR should solve one thing.
3. For commit messages, use a concise verb prefix (such as `fix:`, `feat:`, `docs:`, `chore:`) that explains the "why" rather than just the "what".
4. Complete the [build and verification](#building-and-verifying-the-image) steps locally to ensure the image builds and runs correctly.
5. If your change affects usage, update `README.md` accordingly.
6. Push your branch and open a PR, describing the motivation, how you tested it, and the scope of impact.

## Code Style Conventions

- **Dockerfile**: Keep the multi-stage build structure clear; keep necessary comments for non-obvious trade-offs (such as skipping the Rust build per platform).
- **PHP** (`sqlite-select-id-key-fix.php`, etc.): Follow the WordPress coding standards and keep behavior conservative and safe to fall back on, avoiding destructive changes for cases that cannot be fully reasoned about.
- **YAML workflows**: Keep pull-request validation and the tag-driven `Release` workflow consistent with the supported platform matrix and release policy.
- Comments should explain intent and constraints, not restate what the code already expresses.

## Versioning and Releases

Releases are triggered by maintainers pushing a protected annotated tag in the
CalVer form `YYYY.MM.DD-rN`. The workflow publishes the same signed multi-arch
manifest under an immutable exact tag to
[Docker Hub](https://hub.docker.com/r/soulteary/sqlite-wordpress) and
[GHCR](https://github.com/soulteary/docker-sqlite-wordpress/pkgs/container/sqlite-wordpress),
then separately promotes the mutable date and `latest` aliases. Component
versions remain independent. Regular contributors do not need to run the
release process manually; see [VERSIONING.md](./VERSIONING.md) and maintainers'
[RELEASING.md](./RELEASING.md).

---

Thanks again for your contribution! If you have any questions, feel free to discuss them in an Issue.
