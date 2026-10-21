# NFC Timestamp DEV/TEST Release - 21/10/2026

Status: `DEVTEST_DEPLOYED_PENDING_OWNER_HTTP_AND_NEW_TEST_WRITE_ACCEPTANCE`.

## Release

- Target: https://khohoanamdev.lptech.info.vn, project `hoa_nam_green`, DB `wms_hoanam_green`.
- Exact application SHA: `8be640aa74704b12fe891f8a6db5969901efd8b2`.
- Tested source fix: `6bfa66dc5d0edaddd91b67e59811e269983c5d71`, an ancestor of the deployed commit. Runtime and feature-test files match the tested fix; later changes are deployment operations only.
- Previous application SHA: `e2442df9cb03a50f57847aca0bcba78f0b2b2717`.
- Release: https://github.com/Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE/actions/runs/39557980205 - SUCCESS.
- Completed 21/10/2026 13:57:58 +07:00, Asia/Ho_Chi_Minh. GitHub run completed 13:58:17.
- Release directory: `/opt/hoa-nam-green/releases/8be640aa74704b12fe891f8a6db5969901efd8b2-nfc-time-20261021T065447Z`.
- Private operations/backup report: `/opt/hoa-nam-green/backups/deploy-nfc-time-20261021T065447Z.json`.

## Verification

- Preserved all 14 runtime overlays, existing PHP image/vendor volume and unchanged dependency locks. Source packaging permits exactly the seven NFC timestamp runtime changes.
- No new image or dependency rebuild was needed. Backend, queue and scheduler were recreated/restarted and checked for exact application SHA and cached configuration.
- Applied migration count remains 218 with no pending migrations. `AUTO_MIGRATE=false`; no migrate, seed, repair or restore command ran.
- Config/route/event/view caches were cleared locally in the release; config and route caches rebuilt. Package discovery, Swagger generation and nginx validation succeeded.
- HTTP admission and application workers were paused before a private PostgreSQL backup, full-table fingerprints and local file manifest. No database restore occurred.
- All 113 public-table fingerprints matched before/after. All 13 local `storage/app` file/link manifest entries matched. MinIO service/volume was retained; this is not a fresh byte-level scan of its objects.
- Existing activation/protection settings were retained. Production activation was not enabled. CI and unrelated deploy/owner-smoke jobs were SKIPPED; the release result is not full CI/audit PASS.
- Application evidence remains 69 local PostgreSQL tests / 1,032 assertions and 69 SQLite tests / 1,012 assertions. Deployment-operation tests: 17 PASS.

## Selected NFC Receipt And Binding

The post-switch probe ran in a PostgreSQL READ ONLY transaction before admission reopened. It did not call prepare/confirm/deactivate or create a new session/key.

- Original receipt id 271, persisted response_code 201 and confirmation SUCCEEDED retained. Its original Request-ID remains `590efbfb-ce83-4329-b64a-ecd49d70c457`.
- Actor/session/warehouse/item/tag comparisons remain true without exporting actor names, session credentials or operation keys.
- Receipt body fingerprint remains `a100c78e2fa6f339e5af8c22029e6934`, matching both earlier read-only observations.
- Loaded contract: `nfc-timestamps-v1`.
- Writer value: `2026-10-21 13:57:40.416703+07:00`.
- Actual prepared PDO binding: `2026-10-21 13:57:40.416703+07:00`, identical including offset and fractional precision.
- Existing assignment serialized: `2026-10-21T12:09:01.000000Z`; deactivated_at remains null. This preserves the previously miswritten instant. It still means 19:09:01 in Vietnam; no repair or historical-receipt rewrite was performed.

This probe verifies deployed source/loading/binding and persisted values. It is not authenticated raw HTTP from the APK owner, nor a new physical NFC write/read acceptance.

## Public HTTP

Measured 21/10/2026 13:58:48 +07:00 without credentials:

| GET | HTTP | Request-ID |
| --- | --- | --- |
| `/api/v1/health` | 200, exact deployed SHA | `db679f09-a216-4adf-8b85-0c919b095a77` |
| `/api/v1/mini-app/nfc-tags/d641ce1b-cf3a-4b76-8d32-fec41634c059` | 401 UNAUTHENTICATED | `b02f85fd-fb0b-4c16-a7dd-ee2ea57c55d5` |

The raw file retains HTTP Date, all non-credential response headers and the unmodified response-body strings. These checks do not substitute for original-owner GET list/detail/confirmation.

Raw evidence: `docs/evidence/NFC_TIME_DEVTEST_RELEASE_RAW_20261021.json`.
SHA256: `8fec4ec9c2560ccd3f2bffa9a0eef062e109a628d1d896ead155f44e22d09fea`.

## Capacity Cleanup

Preflight initially found 772,743,168 free bytes, below the release requirement. Two read-only preflights made no deployment changes. The existing 960 MiB reserve was retained.

Removed exactly seven inspected retired September CI/seed images, with full IDs and tags pinned in `tools/deploy/nfc-unused-images-20261021.json`. Before removal, live container and retained rollback references plus exact tags were rechecked. No force/prune or container/volume/media/backup deletion was used.

The running/rollback image `69aad9b47acd...` and retained prior release images `7f6bfc79...`, `aebe6099...`, `d6a8fb1f...` were protected. Removed image binaries were not separately backed up; old source remains available for rebuilding, which does not guarantee reproducing the same image ID.

Actual free bytes increased from 771,805,184 to 1,293,365,248 after cleanup, approximately 497 MiB recovered. After deployment: 1,170,890,752 bytes, approximately 1.09 GiB. Image logical sizes were not summed to claim reclaimed space.

## Remaining Acceptance

The user has no exported original APK token or operation key. No replacement login, new key or repeated confirm was used. Raw authenticated owner GETs and a separately approved new TEST NFC write/read remain pending. Existing wrong row/receipt correction requires a separate data-owner decision. P07 and P01-P24 are not marked fully PASS by this release.
