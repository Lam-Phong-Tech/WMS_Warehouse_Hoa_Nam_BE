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
