# Changelog

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
