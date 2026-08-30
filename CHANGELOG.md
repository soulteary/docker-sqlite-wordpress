# Changelog

## [Unreleased]

## [2026.08.30-r1] - 2026-08-30

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

[Unreleased]: https://github.com/soulteary/docker-sqlite-wordpress/compare/2026.08.30-r1...HEAD
[2026.08.30-r1]: https://github.com/soulteary/docker-sqlite-wordpress/compare/7.1.0...2026.08.30-r1
[7.1.0]: https://github.com/soulteary/docker-sqlite-wordpress/compare/7.0.2-plugin-v3.0.0-rc.8...7.1.0
