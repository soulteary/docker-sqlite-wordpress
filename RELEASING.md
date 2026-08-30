# Releasing

Image releases use the immutable CalVer form `YYYY.MM.DD-rN`, as defined in
[VERSIONING.md](./VERSIONING.md). Component versions such as WordPress `7.1.0`
are recorded independently and do not determine the image tag.

## Protect release tags

Configure a GitHub tag ruleset for `????.??.??-r*` before publishing:

- restrict tag creation to release maintainers;
- block tag updates and deletion, including force pushes;

Separately protect `main` with required pull requests and CI checks. Configure
the `release` Actions environment used by the manifest/signing job with required
reviewers when an explicit publication approval is desired.

Repository rulesets cannot be installed by a pull request. An administrator
must configure and verify this policy in **Settings → Rules → Rulesets**. The
workflow independently requires GitHub's `ref_protected` signal and rejects
branch dispatches, lightweight tags, tags outside `main`, and tags whose target
does not match the checked-out commit. Server-side tag protection remains the
authoritative control.

## Prepare

1. Choose the UTC release date and revision. Use `r1` for the first release on
   a date, then increment it for every changed image artifact or evidence set.
   Check that the exact tag is absent from Git, Docker Hub, and GHCR.
2. Update the pinned WordPress base digest, `WORDPRESS_VERSION`, SQLite
   Integration version/ref, README and Compose examples, and `CHANGELOG.md` in a
   release pull request. Do not change an already published exact tag.
3. Run the local release checks:

   ```bash
   bash tests/test-entrypoint-reconcile.sh
   bash tests/test-validate-release.sh
   php tests/test-sqlite-local-core-update.php
   php tests/test-sqlite-select-id-key-fix.php
   php tests/test-tool-update-site-url.php
   ./scripts/validate-release.sh 2026.08.30-r1
   ```

4. Let pull-request CI test amd64, native arm64, and the 32-bit ARM pure-PHP
   fallback. Verify the local core archive and updater, SQLite CRUD, diagnostics,
   and site URL recovery behavior.
5. Merge the verified release pull request to `main`. Prepare GitHub Release
   notes from its matching changelog section, including upgrade and compatibility
   notes.

## Publish

Create an annotated tag at the verified `main` commit and push that exact ref.
Do not let the GitHub Release form create a lightweight tag.

```bash
git switch main
git pull --ff-only
git tag -a 2026.08.30-r1 -m "Release 2026.08.30-r1"
git push origin refs/tags/2026.08.30-r1
```

The `Release` workflow builds each runtime platform once and publishes the same
manifest digest to Docker Hub and GHCR under the immutable exact tag:

- `soulteary/sqlite-wordpress:2026.08.30-r1`
- `ghcr.io/soulteary/sqlite-wordpress:2026.08.30-r1`

BuildKit emits an SPDX SBOM and maximum-mode SLSA provenance for each platform.
The merged index receives OCI version, source, revision, and license
annotations. GitHub Actions then signs each registry's manifest digest with a
keyless Sigstore identity and verifies both signatures before the release job
succeeds.

Afterward, the serialized `Promote latest` workflow selects the numerically
newest complete CalVer release whose two registry indexes match. It promotes
both the date alias (for example `2026.08.30`) and `latest` without rebuilding.
If the newest Git tag is incomplete, promotion scans backward to the newest
complete release rather than blocking all promotion.

Manual `Release` dispatch is allowed only when the selected workflow ref is an
unpublished annotated CalVer tag. Running it from a branch fails preflight.
Manual `Promote latest` dispatch from `main` can reconcile mutable aliases.

## Verify and announce

Set the exact tag once for all commands:

```bash
release=2026.08.30-r1
```

1. Confirm both registries expose the same five runtime platforms, SBOM, and
   provenance attestations:

   ```bash
   docker buildx imagetools inspect "soulteary/sqlite-wordpress:${release}"
   docker buildx imagetools inspect "ghcr.io/soulteary/sqlite-wordpress:${release}"
   docker buildx imagetools inspect "soulteary/sqlite-wordpress:${release}" --format '{{ json .SBOM }}'
   docker buildx imagetools inspect "soulteary/sqlite-wordpress:${release}" --format '{{ json .Provenance.SLSA }}'
   ```

2. Verify the keyless signatures with the workflow identity and GitHub OIDC
   issuer:

   ```bash
   identity="https://github.com/soulteary/docker-sqlite-wordpress/.github/workflows/release.yaml@refs/tags/${release}"
   issuer="https://token.actions.githubusercontent.com"
   cosign verify \
     --certificate-identity "${identity}" \
     --certificate-oidc-issuer "${issuer}" \
     "soulteary/sqlite-wordpress:${release}"
   cosign verify \
     --certificate-identity "${identity}" \
     --certificate-oidc-issuer "${issuer}" \
     "ghcr.io/soulteary/sqlite-wordpress:${release}"
   ```

3. Pull the exact tag from each registry and repeat the installation, SQLite,
   local core update, diagnostics, and recovery smoke tests. Create the GitHub
   Release from the existing annotated tag only after image verification.

Never force-push, delete, or retarget a published release tag. If anything in
the image or its evidence must change, publish a new CalVer revision. A failed
job may be rerun for the same tag only when it has not partially published a
conflicting exact tag; otherwise diagnose and release a new revision.
