# Changelog

## [Unreleased]

## [2026.09.03-r1] - 2026-09-03

### Fixed

- Refreshed the pinned `wordpress:7.1.0-php8.5-apache` manifest digest after
  the official mutable tag changed, restoring the fail-closed upstream image
  check required by image-building pull requests and releases.
- Updated CI image-change detection to track the consolidated `plugins/`
  directory so future project-owned plugin changes cannot skip image smoke
  tests or upstream base-image verification.

### Changed

- Consolidated all project-owned must-use plugin source files under the
  repository's `plugins/` directory while preserving their flat
  `wp-content/mu-plugins/` installation paths and runtime behavior.
- Replaced repository-local CI validation Bash scripts with the SHA-pinned
  `ci-recipes` Go CLI and moved the architecture-independent upstream image
  check out of the three-platform smoke-test matrix. Updated the pinned recipe
  to recognize both the consolidated and legacy MU-plugin source layouts.
- Marked `2026.09.02-r2` as published after both registries, supply-chain
  evidence, signatures, and mutable aliases passed verification; switched the
  Quick Start and Compose example from a local `main` build to the immutable
  release image.
- Corrected the release and contribution guides to consistently describe
  protected lightweight and annotated tag support and the GitHub Release
  publication order.

### Release status

- Prepared as the first immutable release for 2026-09-03. Until its protected
  tag is published and the two registry indexes, evidence, and signatures are
  verified, `2026.09.02-r2` remains the current complete release.

### Compatibility notes

- The source-tree cleanup does not change runtime plugin paths or behavior.
  Existing document-root volumes continue to receive the same managed files in
  the flat `wp-content/mu-plugins/` directory during entrypoint reconciliation.

## [2026.09.02-r2] - 2026-09-02

### Fixed

- Prepared a new immutable revision after the GitHub Releases web form created
  `2026.09.02-r1` as a lightweight tag. The release workflow rejected that tag
  during annotated-tag preflight, before registry login or image construction.
- Aligned the image version, validation examples, deployment examples, and
  release documentation on the same `2026.09.02-r2` release.

### Changed

- Simplified fresh publication to one GitHub web action: publishing a Release
  now creates or selects its protected tag and automatically starts the image
  workflow.
- Accepted protected lightweight and annotated CalVer tags under the same
  immutable-tag, `main` ancestry, version, and registry preflight checks.
- Kept manual workflow dispatch for explicit recovery while removing the tag
  push trigger, preventing duplicate runs when an existing annotated tag is
  later attached to a GitHub Release.

### Release status

- Published to Docker Hub and GHCR as matching five-platform indexes at
  `sha256:9a5ceb20d80485de3e71bfe4a454f913408c6cdc995df17c2805e90fea5a049a`.
  Per-platform SBOM and provenance evidence and both registry signatures were
  verified before the `2026.09.02` and `latest` aliases were promoted.

### Release notes

- This revision contains the SMTP, OwlMail, user password reset, page
  performance, and project-owned MU plugin metadata changes recorded in the
  retained `2026.09.02-r1` source release below.

## [2026.09.02-r1] - 2026-09-02

### Added

- Added a disabled-by-default page performance must-use plugin. It can be
  enabled under Settings → Page Performance or with
  `WORDPRESS_PAGE_PERFORMANCE_ENABLED=true`, and reports server-side generation
  time plus current/peak PHP memory in the front-end/admin toolbar and public
  page footer.
- Added a disabled-by-default SMTP must-use plugin with a Settings → SMTP page,
  per-field environment overrides, password-file support, forced From options,
  and fail-closed handling for invalid enabled configurations.
- Added an optional OwlMail Compose overlay that keeps SMTP private on the
  Compose network, publishes the local Web inbox, and persists captured mail.
- Added a disabled-by-default `/tool-reset-user-password.php` recovery page.
  When explicitly enabled, it lists all single-site WordPress users in a
  dropdown and resets the selected account to a confirmed new password.
- Gave the password reset endpoint independent TOKEN_FILE/PASSWORD credentials,
  persistent global throttling, concurrent-operation exclusion, and one-shot
  state. A consumed reset remains closed across PHP workers and container
  restarts until the tool is deliberately disabled once.

### Changed

- Added consistent WordPress plugin metadata to every project-owned must-use
  plugin: author `soulteary`, author URL `https://soulteary.com`, and this
  repository as the plugin URL.
- Extended the MU plugin metadata regression test so future project-owned
  plugins cannot omit or change those attribution fields unnoticed.

### Release status

- The GitHub source release was created with a lightweight tag. The protected
  release workflow rejected it during annotated-tag preflight, before registry
  login or image construction. Do not use `2026.09.02-r1` as a deployable image
  release.

### Upgrade notes

- Existing whole-document-root volumes receive the new and updated
  project-owned MU plugins when the container is recreated; the entrypoint
  reconciles managed MU plugin files without removing custom plugins.

## [2026.09.01-r1] - 2026-09-01

### Fixed

- Match WordPress core update offers by semantic version so the WordPress 7.1
  API version (`7.1`) resolves to the bundled image package version (`7.1.0`)
  instead of downloading `wordpress-7.1-no-content.zip` from WordPress.org.
- Refresh the pinned `wordpress:7.1.0-php8.5-apache` manifest digest after the
  official tag changed, restoring the multi-architecture image preflight.
- Retry only the transient `no signatures found` result after signing, allowing
  Docker Hub and GHCR time to expose the newly pushed OCI Sigstore referrer
  without producing another image or moving the immutable CalVer manifest.
- Pin Cosign `v3.1.3` explicitly instead of inheriting the install action's
  older default release.

### Release status

- Published to Docker Hub and GHCR as matching five-platform indexes at
  `sha256:a2ab943b2f9c9818528153e5b9ac70f9f84625a1f58a24d14a0bb35b91387666`.
  Per-platform SBOM and provenance evidence and both registry signatures were
  verified before the date alias and `latest` were promoted.

### Upgrade notes

- Recreating a container does not overwrite a persistent `/var/www/html`.
  Back up the site and database, recreate the container, then initiate the
  normal core update from **Dashboard → Updates** or WP-CLI. The WordPress 7.1
  offer uses the bundled `wordpress-7.1.0-no-content.zip` package locally.

## [2026.08.31-r3] - 2026-08-31

### Fixed

- Corrected multi-platform Buildx evidence inspection to read the platform map
  from `.Provenance` before validating each `SLSA` object. The previous
  `.Provenance.SLSA` template failed after publishing the final `r2` indexes.
- Require one non-empty SPDX SBOM and SLSA provenance object for every runtime
  platform, with regression tests for missing platforms and empty evidence.
- Added an explicit resumable publication mode. If both immutable registry
  manifests already exist with the expected source, version, and matching
  digest, a manual tag dispatch can skip rebuilding and finish verification and
  signing without overwriting them.

### Release status

- Published to Docker Hub and GHCR as matching five-platform indexes at
  `sha256:9716786a5213d89f0d77bbad5bd04723aad8791018d5a8811c5974df73eb40c1`.
  Per-platform SBOM and provenance evidence and both registry signatures were
  verified before the date alias and `latest` were promoted.
- `2026.08.31-r2` remains an incomplete, unsigned release and was not promoted.

## [2026.08.31-r2] - 2026-08-31

### Release status

- The tag push built all five platforms and created matching Docker Hub and
  GHCR indexes at
  `sha256:5b8f17f86f88887f0ad765030ee564317ba7ae3dde09b97685eaf230c696a18c`.
  Multi-platform provenance verification then failed before signing, so this
  exact tag is retained for audit but must not be deployed or promoted.

### Fixed

- Prepared a new immutable release revision after `2026.08.31-r1` stopped in
  release preflight before any container image was built. The existing `r1`
  tag and GitHub source release remain in place for audit history and are not
  moved, deleted, or reused.
- Aligned the image version, validation examples, deployment examples, and
  release documentation on the same `2026.08.31-r2` candidate.

### Release notes

- This revision contains the CalVer, SQLite Database Integration 3.0.1,
  persistent-volume core updater, supply-chain evidence, and ARM test changes
  recorded in the retained `2026.08.31-r1` source release below.

## [2026.08.31-r1] - 2026-08-31

### Release status

- The GitHub source release was created with a lightweight tag while its source
  still declared `2026.08.30-r1`. The protected release workflow rejected it
  during preflight and produced no container image for this exact tag. Do not
  use `2026.08.31-r1` as a deployable image release.

### Added

- Adopted immutable CalVer image releases in the form `YYYY.MM.DD-rN`, with
  separately versioned WordPress and SQLite Integration components.
- Bundled a checksummed, no-content WordPress 7.1.0 core update archive and a
  must-use plugin that routes the matching standard core update through the
  image-local package. This lets sites with a persistent `/var/www/html` volume
  update core without downloading the package again.
- Added SPDX SBOM and maximum-mode SLSA provenance attestations, keyless Sigstore
  signatures for both registry manifests, and OCI source revision metadata.
- Added native arm64 image/runtime coverage on a GitHub-hosted ARM runner and a
  32-bit ARM test for the pure-PHP parser fallback.

### Changed

- Upgraded SQLite Database Integration from `3.0.0` to `3.0.1`, including its
  WordPress 7.1 and Multisite compatibility fixes.
- Made exact release tags immutable and moved `latest` plus the date-only alias
  to a separately verified promotion workflow.
- Corrected the image license annotation to `Apache-2.0 AND
  GPL-2.0-or-later`, documented repository and bundled-component ownership, and
  removed the unsupported top-level `MIT` claim.

### Compatibility notes

- Replacing a container image still does not overwrite WordPress core in an
  already initialized whole-document-root volume. Back up the site and use the
  normal WordPress core updater after starting the new image; it will select the
  verified local package only for its exact bundled target version.
- The local core package excludes `wp-content`, will not downgrade a site, and
  can be disabled with `WORDPRESS_LOCAL_CORE_UPDATE_ENABLED=false`.

## [7.1.0] - 2026-08-29

### Added

- Added a disabled-by-default `/tool-update-site-url.php` recovery page that
  requires both an explicit environment enable switch and one strong credential
  supplied through a token file or direct password, then atomically
  updates the WordPress `siteurl` and `home` options after a domain, scheme,
  port, or path change.

### Security

- Added a persistent global authentication throttle: five failed recovery
  credentials in 15 minutes lock the endpoint for 15 minutes.
- Made each enabled recovery authorization one-shot. It is consumed before the
  SQLite write, disables the current PHP worker, and remains locked for every
  worker and container restart until an explicit disabled start rearms it.
- Persisted throttle and one-shot state with a stable file lock, synchronized
  same-directory temporary file, atomic replacement, and directory sync;
  interrupted, empty, missing, or malformed existing state now fails closed.
- Increased the direct recovery password minimum from 16 to 24 characters and
  documented generated credentials, private-network exposure, TLS, IP
  allowlists, and reverse-proxy rate limiting.
- Made the documented URL contract strict at the server boundary: leading,
  trailing, and internal whitespace are rejected, and the 2048-byte and valid
  port-range limits are explicitly documented and tested.
- Removed the redundant direct `WORDPRESS_SITE_URL_UPDATE_TOKEN` mode before
  release. TOKEN_FILE remains the file-backed secret option and PASSWORD remains
  the single direct environment-value option.
- Ignored the documented local `.env` file and `secrets/` directory so recovery
  credentials are not accidentally committed.

### Changed

- Upgraded the runtime to WordPress 7.1.0 on the official PHP 8.5/Apache image.
- Upgraded SQLite Database Integration from `3.0.0-rc.8` to the stable `3.0.0` release.
- Updated the diagnostics page to prefer the public v3 driver API when resolving the live SQLite PDO connection.
- Consolidated versioned and `latest` image publication into one five-platform release matrix.

### Compatibility notes

- Existing sites should back up `wp-content/database/` before upgrading.
- Custom `wp-config.php` files must define a non-empty `DB_NAME`.
- SQLite Database Integration 3.0.0 uses WAL journaling by default; keep the database, `-wal`, and `-shm` files on the same persistent volume.
- The native `wp_mysql_parser` extension is built for amd64 and arm64. Other published platforms use the integration's pure-PHP parser fallback.

[Unreleased]: https://github.com/soulteary/docker-sqlite-wordpress/compare/2026.09.03-r1...HEAD
[2026.09.03-r1]: https://github.com/soulteary/docker-sqlite-wordpress/compare/2026.09.02-r2...2026.09.03-r1
[2026.09.02-r2]: https://github.com/soulteary/docker-sqlite-wordpress/compare/2026.09.02-r1...2026.09.02-r2
[2026.09.02-r1]: https://github.com/soulteary/docker-sqlite-wordpress/compare/2026.09.01-r1...2026.09.02-r1
[2026.09.01-r1]: https://github.com/soulteary/docker-sqlite-wordpress/compare/2026.08.31-r3...2026.09.01-r1
[2026.08.31-r3]: https://github.com/soulteary/docker-sqlite-wordpress/compare/2026.08.31-r2...2026.08.31-r3
[2026.08.31-r2]: https://github.com/soulteary/docker-sqlite-wordpress/compare/2026.08.31-r1...2026.08.31-r2
[2026.08.31-r1]: https://github.com/soulteary/docker-sqlite-wordpress/compare/7.1.0...2026.08.31-r1
[7.1.0]: https://github.com/soulteary/docker-sqlite-wordpress/compare/7.0.2-plugin-v3.0.0-rc.8...7.1.0
