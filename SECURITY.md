# Security Policy

Thank you for helping keep **Docker SQLite WordPress** and its users safe. This document explains which versions receive security updates, how to report a vulnerability, and what to expect after you do.

## Scope

This project packages the official [WordPress image](https://hub.docker.com/_/wordpress) together with [`sqlite-database-integration`](https://github.com/WordPress/sqlite-database-integration), its optional native Rust accelerator `wp_mysql_parser`, and project-owned entrypoint, diagnostics, compatibility, and recovery components.

This policy covers issues **introduced by this project**, including:

- The `Dockerfile`, `docker-entrypoint-sqlite.sh`, and the way the image is assembled and reconciled (build stages, permissions, bundled files, and persistent volumes).
- The project-owned must-use loader, `sqlite-diagnostics.php`,
  `sqlite-select-id-key-fix.php`, and image-local core update components.
- The disabled-by-default, credential-protected `/tool-update-site-url.php`
  recovery endpoint and its persistent throttle/one-shot state handling.
- The disabled-by-default, credential-protected
  `/tool-reset-user-password.php` endpoint and its independent persistent
  throttle/one-shot state handling.
- The packaging and configuration of the native `wp_mysql_parser` extension.
- The release workflows under `.github/workflows/`.

The following are **out of scope** here and should be reported upstream:

- Vulnerabilities in WordPress core → report via the [WordPress HackerOne program](https://hackerone.com/wordpress).
- Vulnerabilities in `sqlite-database-integration` or the `wp_mysql_parser` extension → report to the [WordPress/sqlite-database-integration project](https://github.com/WordPress/sqlite-database-integration/security).
- Vulnerabilities in third-party plugins or themes you install yourself.

If you are unsure whether an issue belongs here or upstream, report it to us anyway and we will help route it.

## Supported Versions

Security fixes are applied to the latest released image only. Image releases use
CalVer independently from their WordPress, PHP, and SQLite Integration component
versions; the most reliable way to stay secure is to move to the newest exact
CalVer release after testing it.

| Version         | Supported          |
| --------------- | ------------------ |
| Latest release  | :white_check_mark: |
| Older releases  | :x:                |

- `soulteary/sqlite-wordpress:latest` (Docker Hub) and
  `ghcr.io/soulteary/sqlite-wordpress:latest` (GHCR) point to the newest complete
  release after cross-registry verification.
- Exact CalVer tags (for example `2026.08.30-r1`) are immutable snapshots and do
  **not** receive back-ported fixes. Upgrade to a newer tag to pick up security
  updates. The date-only tag is mutable within that date.

## Reporting a Vulnerability

**Please do not open a public GitHub Issue for security vulnerabilities.** Public disclosure before a fix is available puts users at risk.

Instead, use one of the following private channels:

1. **GitHub Security Advisories (preferred).** Open a private report via the repository's [Security → Report a vulnerability](https://github.com/soulteary/docker-sqlite-wordpress/security/advisories/new) page.
2. **Email.** Send details to `soulteary@gmail.com` with a subject line starting with `[SECURITY]`.

To help us triage quickly, please include as much of the following as you can:

- The image tag and platform (architecture, OS) where you observed the issue.
- A clear description of the vulnerability and its potential impact.
- Step-by-step reproduction instructions or a proof of concept.
- Relevant logs or output (e.g. `docker logs <container>`).
- Any suggested remediation, if you have one.

## What to Expect

- **Acknowledgement:** we aim to acknowledge your report within **3 business days**.
- **Assessment:** we will investigate, confirm the issue, and work out a fix or mitigation, keeping you informed of progress.
- **Disclosure:** once a fix is released, we will publish an advisory and, with your consent, credit you for the discovery.
- **Coordination:** if the root cause lies upstream (WordPress core or the SQLite integration), we will help coordinate reporting to the appropriate project.

Please give us a reasonable amount of time to address the issue before any public disclosure. We are grateful for responsible disclosure and for your help protecting the community.

## Security Best Practices for Users

While not vulnerabilities in this image, the following practices reduce your exposure:

- Track the newest release, but pin its exact CalVer tag or manifest digest in
  production. Verify its keyless signature, SBOM, and provenance as documented
  in `RELEASING.md` before promotion.
- Keep WordPress core, plugins, and themes updated from within the WordPress admin.
- Protect the SQLite database file (mounted under `wp-content/database/`) with appropriate filesystem permissions and never expose it directly over the web.
- Keep tested offline backups of the complete `wp-content/database/` directory.
  Stop the container before a filesystem copy so the main database and WAL
  sidecars are captured consistently; follow the README's safe backup and
  restore procedure instead of copying only the main SQLite file.
- A whole `/var/www/html` mount keeps its previous WordPress core when the
  container image changes. Back up the full site, recreate the container, and
  run the standard WordPress updater to consume the matching checksummed local
  core archive; do not assume a container restart upgraded persisted core.
- Run the container behind a reverse proxy with TLS, and avoid exposing it directly to the public internet without hardening.
- Use strong administrator credentials and limit access to the WordPress dashboard.
- Leave the site URL recovery endpoint disabled except during a recovery. Use
  the exact `WORDPRESS_SITE_URL_UPDATE_TOOL_ENABLED=true` switch together with
  exactly one credential source. Prefer `WORDPRESS_SITE_URL_UPDATE_TOKEN_FILE`
  with a randomly generated token; a direct
  `WORDPRESS_SITE_URL_UPDATE_PASSWORD` value is visible in container environment
  metadata. Send credentials only over TLS on untrusted networks, then remove
  the enable switch and credential immediately after the repair.
- The recovery endpoint globally locks for 15 minutes after five invalid
  credentials in one 15-minute window. This blocks distributed guessing but can
  also let an exposed attacker temporarily delay the operator. Restrict the
  endpoint with a private network or reverse-proxy IP allowlist and apply an
  additional proxy rate limit whenever practical.
- Bind temporary recovery deployments to a loopback address by default. When a
  reverse proxy is required, restrict `/tool-update-site-url.php` by source IP,
  disable caching for the route, preserve POST, and forward the original `Host`
  and `X-Forwarded-Proto` headers.
- A recovery authorization is one-shot. It is consumed immediately before the
  database write and remains locked across PHP workers and container restarts.
  Do not rely on the in-process environment change to rewrite Docker Compose;
  remove the enable switch and credential and recreate the container after use.
  Rearm only through the documented disabled-start sequence with a new secret.
- Apply the same restrictions to the user password reset endpoint. Leave
  `WORDPRESS_USER_PASSWORD_RESET_TOOL_ENABLED` disabled normally, prefer
  `WORDPRESS_USER_PASSWORD_RESET_TOKEN_FILE`, and restrict
  `/tool-reset-user-password.php` to loopback or a TLS/IP-allowlisted proxy.
  While enabled, its dropdown reveals WordPress login and display names to
  anyone who can reach the page, so keep the enable window short. Remove the
  reset credential and recreate the container immediately after use.
