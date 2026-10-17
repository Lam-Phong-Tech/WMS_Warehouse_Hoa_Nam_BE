# Green DEV/TEST Deployment Preflight

- Application candidate: `898d447eba868f0fb2ea6362b2e4766534ddf76a`.
- Verification run: `39052882961`. All application test, audit, isolated
  PostgreSQL seed/OpenAPI and local smoke steps succeeded. The run failed only
  at artifact upload because GitHub Actions storage quota was exhausted.
- A failed distribution step is not a successful overall CI run. Preserve this
  distinction in release evidence; do not relax application gates or assert
  full row/field seed acceptance from these results.
- The `devtest_preflight_only` manual input performs read-only inspection. It
  never deploys, migrates, seeds, restarts services or activates features.
- Staging environment protection permits only `dev`. Keep that protection;
  do not copy credentials out of GitHub or use the generic UAT deployment for
  `/opt/hoa-nam-green`.
- Inspect the retained CI artifact before checkout or cleanup of the shared
  self-hosted workspace. Verify its SHA256 and source provenance before any
  later promotion. Never print `.env`, tokens, passwords or Docker environment
  arrays.
- Green target: `/opt/hoa-nam-green/current`, project `hoa_nam_green`, database
  `wms_hoanam_green`, origin `https://khohoanamdev.lptech.info.vn`.
- Preserve runtime overlays and rollback resources. Dependency changes require
  a fresh vendor volume, not writes to the currently serving vendor volume.
- Product, inventory and media data remain out of scope for this preflight.

## Verified Promotion

The separate `devtest_deploy_only` manual mode promotes the pinned application
SHA only with `GREEN-898d447e-NO-SEED`, `deploy_uat=false`, and
`run_uat_seed=false`. It verifies every application gate in run `39052882961`
through the GitHub API and confirms that the sole failed step is artifact
upload with the storage-quota error. It does not turn that run into CI PASS.

The retained runtime image, image-inspect ID and image tar checksum must match.
Source comes from `git archive` of the tested application SHA, never the CI
`.env`, generated fixture database or untracked workspace files. Runtime
overlays have pinned hashes and unchanged migration manifests. Dependencies
are prepared in a new private volume with networking disabled. Existing image
references, vendor volumes and release directories remain rollback resources.

The green-only promotion takes a backup and compares all public base-table
fingerprints and local media contents while application services are stopped.
It refreshes config/routes/Swagger without seeding, migration, trigger changes
or activation changes. Failure rolls back application code only, never the
database. Health success is not authenticated owner acceptance.
