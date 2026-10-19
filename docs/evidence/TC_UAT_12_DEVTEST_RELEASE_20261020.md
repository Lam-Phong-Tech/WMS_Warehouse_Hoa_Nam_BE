# TC_UAT_12 DEV/TEST Release - 20/10/2026

Status: `DEPLOYED_PENDING_AUTHENTICATED_TC12_AND_FE_ACCEPTANCE`.

## Exact Release

- Origin: https://khohoanamdev.lptech.info.vn, project `hoa_nam_green`, DB `wms_hoanam_green`.
- Application SHA: `22272d9ea65bf4e52d39d632310eb820a6df65f0`.
- Source patch: `1375dfaa58dc212f4ca4ff52fe8ca68a2fb28a43`; current-name user decision: `9c7edbee8ec1616a84f07bac570fe7ae653b9d8f`.
- These commits are ancestors of the deployed application SHA. Runtime PHP, dependencies, migrations and feature tests are unchanged from the locally tested TC12 source.
- Previous application: `9d9e87382eea39deb3f3d660b3955110b526ea3c`.
- Release run: https://github.com/Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE/actions/runs/39291985944, SUCCESS.
- Runtime deployment completed 20/10/2026 00:17:15 +07:00; GitHub run completed 00:17:32, Asia/Ho_Chi_Minh.
- Release: `/opt/hoa-nam-green/releases/22272d9ea65bf4e52d39d632310eb820a6df65f0-tc12-20261019T171410Z`.
- Private operations report: `/opt/hoa-nam-green/backups/deploy-tc12-20261019T171410Z.json`.

## Data And Runtime Safety

- All 14 previously reviewed runtime overlays were verified and preserved. The source package permits only the four TC12 runtime changes relative to the previous application; auth source and owner writes are unchanged.
- Reused the verified existing PHP image and dependency volume, with unchanged `composer.lock` and vendor stamp. This was a code-only deployment, not a fresh dependency/image build.
- Preserved previous release and rollback images. Removed only the two explicitly inspected, unreferenced images `postgres:16` and `node:22-alpine`, with full image-ID/tag/container/rollback rechecks and no force/prune.
- No unrelated container, Docker volume, PostgreSQL data, backup or media deletion was performed. Only the named application containers were recreated for the release.
- Free capacity after image cleanup: 1,327,529,984 bytes. After deployment: 1,206,095,872 bytes, approximately 1.12 GiB.
- Paused HTTP admission and stopped application services before taking a private PostgreSQL backup, DB fingerprints and local media manifest. No database restore was performed.
- All 113 public tables matched before/after deployment. All 13 local `storage/app` file/link manifest entries matched. This is not a new byte-level verification of MinIO objects; the MinIO volume/service was not changed.
- All 218 migrations remain applied, no pending migration. No migration or seed command ran; `AUTO_MIGRATE=false` was verified.
- `package:discover`, `config:clear`, `route:clear`, `event:clear`, `view:clear`, `config:cache`, `route:cache`, `l5-swagger:generate` and nginx validation succeeded.
- Backend, queue and scheduler were restarted/recreated, checked for exact SHA and cached config. Existing P12/P14/session policy, recovery restrictions and protected-DB controls were preserved. Production activation was not changed or enabled.
- CI was explicitly SKIPPED. The SUCCESS release result is not a claim of full CI/audit PASS. Local deployment-safety tests: 16 PASS; prior application tests remain the evidence in the TC12 source-verification report.

## Public Read Evidence

No credentials were sent in these requests. Invalid all-zero detail UUIDs were used only to verify that authentication is enforced, not as document/warehouse fixtures.

| Observation, Asia/Ho_Chi_Minh | GET | HTTP | Request-ID |
| --- | --- | --- | --- |
| 20/10/2026 00:17:48.977 +07:00 | `/api/v1/health` | 200, exact application SHA | `7a3f2de6-f465-49bf-9c45-339754c2eced` |
| 20/10/2026 00:17:49.270 +07:00 | `/api/v1/mini-app/inbound-documents/00000000-0000-0000-0000-000000000000` | 401 | `6a94c0c1-6f63-4370-969d-c4b9cc6a7773` |
| 20/10/2026 00:17:49.488 +07:00 | `/api/v1/mini-app/outbound-documents/00000000-0000-0000-0000-000000000000` | 401 | `eceaf5a4-3999-4629-a86f-9ec7525ab2dc` |

Local raw evidence: `.tmp/tc12-deployed-22272d9e/TC12_DEVTEST_RELEASE_RAW.json` in the primary workspace. SHA256: `30f3f6d8646f228a64a980ec118d91d4feb61ba939c8f245c5a0c67ea3201a0e`.
This file includes safe response headers/bodies, release metadata and selected deployment events. It does not contain an authenticated TC12 supplier/creator response.

## Remaining Acceptance

No scoped token file was supplied for authenticated detail smoke. No new admin login, refresh, revoke, START/END, RECORD/POST or upload was executed. Existing operator sessions were not intentionally replaced.

FE should use `data.p12.owner_projection.creator.current_display_name`, clearly labelled as the current directory name, with `current_display_name_source=app_user.display_name`. `iam.user.view` remains required. Historical name/role remain null; supplier names still come from the P04 RECORD snapshot, not current lookup.

Authenticated GETs for a real existing supplier-bearing inbound document and an outbound document, warehouse/permission negative cases, and appqr UI verification remain pending. Public health/401 checks do not close TC12 or replace authenticated smoke. No production or full UAT PASS is claimed.
