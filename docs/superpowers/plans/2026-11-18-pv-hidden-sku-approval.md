# Hidden SKU approval implementation plan

**Goal:** New SKUs appear in PV administration as hidden unless explicitly published. The add dialog selects hidden active profiles and Save publishes immediately, as confirmed by the user.

**Architecture:** Preserve existing PostgreSQL SKU/profile synchronization. Provision a hidden profile on application SKU creation when a driver or write path has not already created one. Reuse profile-list server filtering for candidates. Add an atomic approve endpoint with profile-version preconditions and both existing PV permissions. Keep the public preview restricted to active, published SKUs.

**Approach:** Reusing hidden profiles avoids duplicate records. The rejected previous approach (excluding every existing profile and avoiding hidden-profile creation) conflicts with the confirmed flow. Separate save/publish requests could leave partially saved state; use one transaction instead.

**Constraints:** Existing metadata, images, permission separation, and optimistic locking remain in effect. No automatic push, deployment or live-data migration in this implementation task. Do not alter unrelated dirty files.

- [x] Add failing API tests for default hidden creation, server filtering before pagination, approval/version/permissions, and public preview visibility.
- [x] Ensure SKU create paths provision hidden profiles; add filtered candidate query and transactional approval endpoint.
- [x] Add failing frontend interaction tests for hidden-profile selection, metadata preservation, save-and-publish, and permission/error states.
- [x] Update the add dialog and API service/hook; retain ordinary edit without automatic publishing.
- [x] Run focused SQLite and PostgreSQL tests, frontend tests/typecheck, and review the final diff.

**Review focus:** concurrent publication or stale versions; hidden profiles on another page; images preserved on selection; inactive SKUs excluded; permission or validation failure leaves the product hidden; preview cache refreshed after approval/unpublish.

**Review resolutions:** Serialize profile edits, approval and dedicated publication before checking versions; invalidate both server master cache and client PV/master results; provision profiles for ordinary Excel imports as well as both SKU create endpoints. Warm-cache aggregate/import tests reproduced the two missed paths before their fixes. No schema migration or live-data backfill is needed.

**Validation:** SQLite 48 tests / 294 assertions passed. PostgreSQL 20 tests / 144 assertions passed. Frontend catalogue interactions, approval request/preconditions and cache invalidation passed; the stale-version interaction was rerun after correcting its assertion for the globally mocked toast. TypeScript `tsc -b --pretty false` passed. These are focused regression checks, not a full repository suite or a live-server browser check.
