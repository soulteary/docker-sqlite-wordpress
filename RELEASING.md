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

1. Choose the `Asia/Shanghai` release date and revision. Use `r1` for the first
   release on a date, then increment it for every changed image artifact or
   evidence set.
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
   ./scripts/validate-release.sh 2026.09.01-r1
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
release=2026.09.01-r1
git tag -a "${release}" -m "Release ${release}"
test "$(git cat-file -t "refs/tags/${release}")" = tag
git push origin "refs/tags/${release}"
```

The tag push is the publication trigger. Creating or publishing a GitHub
Release does not trigger the image workflow. Confirm the tag-triggered run in
the Actions page and do not start a second run while it is still active.

The `Release` workflow builds each runtime platform once and publishes the same
manifest digest to Docker Hub and GHCR under the immutable exact tag:

- `soulteary/sqlite-wordpress:2026.09.01-r1`
- `ghcr.io/soulteary/sqlite-wordpress:2026.09.01-r1`

BuildKit emits an SPDX SBOM and maximum-mode SLSA provenance for each platform.
The merged index receives OCI version, source, revision, and license
annotations. GitHub Actions then signs each registry's manifest digest with a
keyless Sigstore identity and verifies both signatures before the release job
succeeds. A registry can accept a signature referrer before making it visible
to an immediate read. The workflow retries only Cosign's transient
`no signatures found` result; identity, certificate, and other verification
failures remain fail-closed.

Afterward, the serialized `Promote latest` workflow selects the numerically
newest complete CalVer release whose two registry indexes match. It promotes
both the date alias (for example `2026.08.31`) and `latest` without rebuilding.
If the newest Git tag is incomplete, promotion scans backward to the newest
complete release rather than blocking all promotion.

Manual `Release` dispatch is allowed only when the selected workflow ref is an
annotated CalVer tag. A fresh publication requires the exact image tag to be
absent from both registries. Running it from a branch fails preflight.
Manual `Promote latest` dispatch from `main` can reconcile mutable aliases.

### Recover from a failed preflight

Do not move, delete, or reuse a public exact CalVer tag after a failed release.
A rerun cannot turn a lightweight tag into an annotated tag or make a tag name
match a different `IMAGE_VERSION`. Keep the failed tag and source release for
audit history, correct the ruleset and source version through a pull request,
increment the revision, and publish a new annotated protected tag. A workflow
that stopped in preflight did not reach the image build or registry publication
jobs.

### Resume after manifest creation

If a release creates the exact manifest in both registries and then fails during
evidence verification or signing, do not start another fresh run. First confirm
that both indexes have the same digest and their OCI version and revision match
the protected source tag. Then open **Actions → Release → Run workflow**, select
that exact tag, enable `resume_existing`, and run it. Resume mode skips platform
builds and manifest creation; it revalidates every platform's SBOM and
provenance, signs the existing digest, and verifies the signatures.

Resume support must already exist in the workflow snapshot referenced by the
tag. Earlier incomplete tags such as `2026.08.31-r2` cannot acquire this logic
without moving the tag, so they remain incomplete and require a new revision.
If only one registry contains the exact tag, the manifests differ, or their OCI
revision does not match the tag commit, resume fails closed and a new revision
is required.

The published `2026.08.31-r3` workflow snapshot includes resume support but
predates the bounded signature visibility retry. If its first resume reaches
`Signing artifact...` and then
reports `no signatures found`, wait for the registry referrer to become visible
and run the same resume again. Resume mode never rebuilds or replaces the exact
manifest; an additional valid signature referrer does not change its digest.

## Verify and announce

Set the exact tag once for all commands:

```bash
release=2026.09.01-r1
```

1. Confirm both registries expose the same five runtime platforms, SBOM, and
   provenance attestations:

   ```bash
   docker buildx imagetools inspect "soulteary/sqlite-wordpress:${release}"
   docker buildx imagetools inspect "ghcr.io/soulteary/sqlite-wordpress:${release}"
   docker buildx imagetools inspect "soulteary/sqlite-wordpress:${release}" --format '{{ json .SBOM }}'
   docker buildx imagetools inspect "soulteary/sqlite-wordpress:${release}" --format '{{ json .Provenance }}'
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
   local core update, diagnostics, and recovery smoke tests. Verify the registry
   availability gate:

   ```bash
   ./scripts/verify-published-release.sh "${release}"
   ```

   Create the GitHub Release from the existing annotated tag only after all
   image checks succeed.

4. If the release pull request carried the `release-availability: pending`
   README marker and local-build Compose configuration, open a documentation
   follow-up that removes the marker and switches Compose to the exact published
   tag. `tests/test-release-availability.sh` rejects that transition until the
   Git tag exists and Docker Hub and GHCR expose the same manifest digest.

Never force-push, delete, or retarget a published release tag. If anything in
the image or its evidence must change, publish a new CalVer revision. Rerun a
failed job directly when its original artifacts remain available; otherwise use
the explicit resume mode only for matching complete manifests in both
registries. Any conflicting or one-sided state requires a new revision.
