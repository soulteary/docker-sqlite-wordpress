# Contributing Guide

Thank you for your interest in contributing to **Docker SQLite WordPress**! This project builds on the official [WordPress image](https://hub.docker.com/_/wordpress), integrating [`sqlite-database-integration`](https://github.com/WordPress/sqlite-database-integration) together with its optional native Rust accelerator extension `wp_mysql_parser`. The goal is to provide a ready-to-use WordPress container image that requires no MySQL.

Before contributing, please read this guide along with the [Code of Conduct](./CODE_OF_CONDUCT.md).

## Table of Contents

- [Ways to Contribute](#ways-to-contribute)
- [Before You Start](#before-you-start)
- [Local Development Environment](#local-development-environment)
- [Building and Verifying the Image](#building-and-verifying-the-image)
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

```bash
docker build -t soulteary/sqlite-wordpress:dev .
docker run --rm -it -p 8080:80 -v "$(pwd)/wordpress:/var/www/html" soulteary/sqlite-wordpress:dev
```

### Multi-Arch Build

This project supports `linux/amd64`, `linux/arm64`, `linux/arm/v7`, `linux/arm/v6`, and `linux/arm/v5`. The native Rust extension is compiled only on `amd64` and `arm64`; the other 32-bit ARM platforms automatically skip it and fall back to the pure-PHP parser (see the comments in the `Dockerfile`).

```bash
docker buildx build --platform linux/amd64,linux/arm64 -t soulteary/sqlite-wordpress:dev .
```

### Verifying Key Functionality

After modifying the `Dockerfile` or the plugin, please confirm the following inside the container:

- Whether the native extension loads correctly per platform (`amd64` / `arm64` should load it; 32-bit ARM should fall back):

```bash
docker exec -it <container> php -m | grep wp_mysql_parser
```

- Whether the companion must-use plugin is in place:

```bash
docker exec -it <container> ls -l /var/www/html/wp-content/mu-plugins/
```

- Whether the SQLite database integration plugin completes installation and can create, read, update, and delete posts normally.

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

Releases are triggered by maintainers pushing a Git tag in the form `x.y.z`, which runs the single `Release` workflow under `.github/workflows/`. It publishes the same multi-arch image under both the immutable version tag and mutable `latest` tag to [Docker Hub](https://hub.docker.com/r/soulteary/sqlite-wordpress) and [GHCR](https://github.com/soulteary/docker-sqlite-wordpress/pkgs/container/sqlite-wordpress). Regular contributors do not need to run the release process manually; maintainers should follow [RELEASING.md](./RELEASING.md).

---

Thanks again for your contribution! If you have any questions, feel free to discuss them in an Issue.
