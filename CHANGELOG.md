# Changelog

## [Unreleased]

### Added

- Added a disabled-by-default `/tool-update-site-url.php` recovery page that
  requires both an explicit environment enable switch and one strong credential
  supplied through a token file, direct token, or password, then atomically
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
- Ignored the documented local `.env` file and `secrets/` directory so recovery
  credentials are not accidentally committed.

## [7.1.0] - 2026-08-29

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

[7.1.0]: https://github.com/soulteary/docker-sqlite-wordpress/compare/7.0.2-plugin-v3.0.0-rc.8...7.1.0
