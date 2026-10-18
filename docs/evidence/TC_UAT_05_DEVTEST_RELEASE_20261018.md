# TC_UAT_05 - DEV/TEST HTTP Acceptance

- Release: https://github.com/Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE/actions/runs/39124139975 - SUCCESS.
- Application SHA: `9d9e87382eea39deb3f3d660b3955110b526ea3c`.
- Operations SHA: `1b1fb69d` (operations-only descendant).
- Runtime ref: `dev@9d9e8738+uat05+preserved-runtime-hotfixes`.
- Target: `https://khohoanamdev.lptech.info.vn`, project `hoa_nam_green`, DB `wms_hoanam_green`.
- Deploy completed 18/10/2026 18:43:37 +07:00; authenticated observations 18:43:39-18:43:42, Asia/Ho_Chi_Minh.
- Last public health verification 18:44:19 +07:00: HTTP 200, exact application SHA, Request-ID `cd7ed4e6-9fc5-4ae0-a0f0-9814f00f17e3`.

## Deployment

- Preserved all 14 runtime overlays; official overlay files and PHP/nginx/dependency manifests were compared against the previously deployed source before copying runtime overlays.
- Reused verified unchanged runtime images and vendor lock stamp. No dependency reinstall or serving-vendor writes were required.
- Pre-deploy PostgreSQL custom-format backup: 8,406,911 bytes, stored privately on the VPS; no restore performed.
- Exactly one additive migration applied:

```sh
php artisan migrate --force --no-interaction --path=database/migrations/2026_10_18_000001_add_refresh_session_replacement_metadata.php
```

- Applied migration rows increased 217 -> 218; no pending migration remained.
- Effective `AUTH_SINGLE_ACTIVE_SESSION_ENABLED=true`. Config/route caches and Swagger rebuilt; nginx checked. Backend/queue/scheduler were recreated/restarted and their cached source/config/DB identity checked.
- P12/P14/P19, recovery and production activation were not changed. CI and seed jobs were skipped.
- All 111 other public tables were fingerprinted unchanged. Existing refresh fields were compared unchanged after excluding only the new nullable column; all new replacement markers were null before admission. Local media file contents were unchanged.
- Fingerprint comparison occurred before the human-approved admin login A/B smoke. That smoke intentionally changed auth/session/audit state, not product/inventory/business state.
- Release: `/opt/hoa-nam-green/releases/9d9e87382eea39deb3f3d660b3955110b526ea3c-uat05-20261018T114034Z`.
- Private runtime/config/backup evidence: `/opt/hoa-nam-green/backups/deploy-uat05-20261018T114034Z.json`.
- Completion free disk: 1,255,698,432 bytes. No image, volume, media or backup deletion was performed.

## Authenticated HTTP

The user explicitly approved the existing admin account after being informed that its old sessions would be replaced. Two independent HTTP cookie jars were used; no token or password was saved in an evidence artifact. Response PII and credentials are redacted in the job logs.

| Case | HTTP | Result | Request-ID |
| --- | --- | --- | --- |
| Login A | 200 | Session issued | `941d4250-b86a-4489-80c9-7a597350e444` |
| Login B | 200 | Distinct stable family | `b65d74ce-a70b-4208-bc5c-d40e19b5248d` |
| A GET /auth/me after B | 401 | AUTH_SESSION_REPLACED | `bb860587-89cf-4357-a969-f1a4221a9f2a` |
| A empty PATCH /auth/me | 401 | AUTH_SESSION_REPLACED; no profile update | `e0ee4ca8-c392-4187-947a-163e5e1f9459` |
| A POST /auth/refresh | 401 | AUTH_SESSION_REPLACED | `53e61cba-2396-4da3-bf81-1a4f9536d36a` |
| B GET /auth/me after A refresh | 200 | Successor still active | `f92785cc-6511-48ea-84ec-7405aa7f8dc5` |
| B POST /auth/refresh | 200 | Same stable family | `f0e7f511-9520-4f06-bac1-3c476d331866` |
| B original access after rotation | 200 | Same family still valid | `243f7959-4be5-4d1d-bcd7-7ad4a98e2733` |
| GET /auth/sessions | 200 | meta.total=1 active family | `f08a4312-03e6-4c74-b4ab-3e57e11ac212` |
| Logout smoke successor | 200 | CURRENT_AUTH_SESSION_FAMILY; no shift END/draft deletion | `8feeb33b-0d60-47c4-a1a6-374646727eca` |
| B after logout | 401 | UNAUTHENTICATED | `6674dab7-4e01-4194-ab9b-e325198c219e` |

There are 14 observed responses including health and pre-replacement profile checks. Full redacted response bodies and selected security headers are retained in the release logs and local delivery `TC_UAT_05_DEVTEST_RAW_REDACTED.json`. Credential-bearing request headers/bodies were deliberately not recorded.

`GET /auth/me` confirmed `data.account_session_policy.version=auth-session-replacement-v1`, `enabled=true`, no role exemptions. Newly created login families correctly returned `warehouse_context.status=REQUIRED` and the authorized warehouse list; the smoke did not invent a confirmed warehouse context.

## Boundaries

- HTTP/API acceptance: PASS. Physical Android/browser two-device and foreground/idle UI acceptance: PENDING.
- Admin sessions before the smoke are invalid; sign in again explicitly. The successor smoke family was logged out.
- No work shift was ended, no draft/journal was deleted or transferred, and no stock RECORD/POST was called.
- Existing UNKNOWN operations retain their original owner identity. Login replacement is not a recovery protocol.
- This evidence supersedes the earlier source-only NOT_DEPLOYED status for DEV/TEST only, not production or BA/native approval.
