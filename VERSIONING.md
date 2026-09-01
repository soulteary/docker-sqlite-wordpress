# Image Versioning

This repository versions the **assembled container image**, not only the
WordPress component inside it. WordPress, SQLite Database Integration, the base
image, project-owned code, and release metadata can all change independently,
so WordPress versions are recorded as component metadata instead of being
reused as image release tags.

## CalVer format

Immutable releases use:

```text
YYYY.MM.DD-rN
```

- `YYYY.MM.DD` is the project release date in `Asia/Shanghai` (UTC+08:00).
- `rN` is a positive revision beginning at `r1`.
- Leading zeroes are required for month and day.
- The date must be a real calendar date.

Examples:

```text
2026.09.01-r1
2026.09.01-r2
2026.09.04-r1
```

Use another revision on the same project release date whenever any published
image content or release evidence changes, including a WordPress or plugin
update, a base image rebuild, project code, labels, SBOM/provenance, or a
packaging fix. Start at `r1` again on a new date. Documentation-only changes do
not require an image release.

The release date identifies when the artifact is prepared for publication; it
does not claim that every bundled component was released on that date. Publish
late fixes under their actual release date, and never reuse or move a tag.

## Tags and mutability

| Tag | Example | Mutability | Intended use |
| --- | --- | --- | --- |
| Exact release | `2026.09.01-r1` | Immutable | Deployments, rollback, audit, and signatures |
| Date alias | `2026.09.01` | Mutable within that date | Follow the newest revision published for one date |
| Rolling alias | `latest` | Mutable | Follow the newest complete release |

Production deployments should pin an exact release tag, or preferably its
manifest digest. An exact release is never overwritten, even when a component
update retains the same WordPress version. For example, images that bundle
WordPress `7.1.0` with SQLite Database Integration `3.0.0` and `3.0.1` receive
different CalVer tags.

The date and `latest` aliases are promoted only after both registries contain
the same complete multi-platform manifest and supply-chain attestations. They
are convenience pointers, not reproducible release identifiers.

## Component versions

Component versions remain visible independently:

- `WORDPRESS_VERSION` and the pinned official WordPress base image identify the
  WordPress/PHP runtime.
- `SQLITE_DATABASE_INTEGRATION_VERSION` and
  `SQLITE_DATABASE_INTEGRATION_COMMIT` identify SQLite Database Integration.
- OCI `org.opencontainers.image.version` identifies the CalVer image release.
- OCI `org.opencontainers.image.revision` identifies the source commit.
- OCI base/component annotations and the generated SBOM describe the remaining
  inputs.

See [RELEASING.md](./RELEASING.md) for tag protection, publication, signature,
and verification procedures.
