# Releasing

Release tags follow the bundled WordPress version, for example `7.1.0`. A tag publishes the same multi-platform image to Docker Hub and GHCR under both the immutable version tag and the mutable `latest` tag.

## Prepare

1. Update both WordPress `FROM` instructions, the SQLite integration version, README examples, Docker Compose, and `CHANGELOG.md` in a release pull request.
2. Run the local release consistency check:

   ```bash
   ./scripts/validate-release.sh 7.1.0
   ```

3. Complete the runtime checks described in the pull request, including the native parser and pure-PHP fallback paths.
4. Prepare the GitHub Release notes from the matching `CHANGELOG.md` section. Include compatibility and migration notes, not only the generated pull-request list.
5. Merge the release pull request into `main` before creating the tag.

## Publish

Create one annotated tag from the verified `main` commit and push it without moving or reusing an existing release tag. Do not let the GitHub Release form create the tag: that produces a lightweight tag and makes the release visible before its images have passed verification.

```bash
git switch main
git pull --ff-only
git tag -a 7.1.0 -m "Release 7.1.0"
git push origin refs/tags/7.1.0
```

The `Release` workflow validates that the tag is a stable semantic version, matches both WordPress base-image stages and the documentation, and does not already exist in either container registry. It then runs one five-platform matrix and publishes:

- `soulteary/sqlite-wordpress:7.1.0` and `soulteary/sqlite-wordpress:latest`
- `ghcr.io/soulteary/sqlite-wordpress:7.1.0` and `ghcr.io/soulteary/sqlite-wordpress:latest`

The manual workflow entry is only for an unpublished tag selected as the workflow ref. Running it from a branch fails during preflight.

## Verify and announce

1. Confirm that every platform is present in both registries:

   ```bash
   docker buildx imagetools inspect soulteary/sqlite-wordpress:7.1.0
   docker buildx imagetools inspect ghcr.io/soulteary/sqlite-wordpress:7.1.0
   ```

2. Pull the version tag from each registry and run the WordPress installation and SQLite diagnostics smoke tests.
3. Create the GitHub Release from the existing matching tag and copy the full `CHANGELOG.md` entry, including compatibility notes, into its notes. Keep it as a draft until image verification is complete.

Do not force-push or retarget a published release tag. If published contents must change, prepare a new patch release. If a workflow fails, rerun only the failed jobs so successful preflight and digest artifacts remain associated with the same run.
