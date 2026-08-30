# Licensing

This repository and the container assembled from it contain separately
licensed components. The repository license does not replace the licenses of
software copied into the image.

## Project-owned material

Unless a file says otherwise, the Dockerfile, workflows, shell/PHP helpers,
tests, and documentation authored in this repository are licensed under the
[Apache License 2.0](./LICENSE).

## Bundled application components

| Component | License | Source |
| --- | --- | --- |
| WordPress core | `GPL-2.0-or-later` | [wordpress/wordpress-develop](https://github.com/WordPress/wordpress-develop) |
| SQLite Database Integration, including `wp_mysql_parser` | `GPL-2.0-or-later` | [WordPress/sqlite-database-integration](https://github.com/WordPress/sqlite-database-integration) |
| Project-owned packaging and runtime helpers | `Apache-2.0` | This repository |

The image also inherits PHP, Apache HTTP Server, Debian packages, libraries,
and other transitive components from the pinned official WordPress base image.
Those components retain their own licenses. The release SBOM is the inventory
to consult for the exact packages in a particular image; it does not alter any
license terms.

The OCI `org.opencontainers.image.licenses` annotation therefore describes the
top-level material intentionally assembled here as:

```text
Apache-2.0 AND GPL-2.0-or-later
```

It is not a claim that every transitive package has one of only those two
licenses. No top-level MIT-licensed project component was found during this
inventory, so `MIT` is not included in the image annotation.

All trademarks belong to their respective owners. “WordPress” and the
WordPress logo are trademarks of the WordPress Foundation; this project is an
independent container packaging project and is not an official WordPress
distribution.
