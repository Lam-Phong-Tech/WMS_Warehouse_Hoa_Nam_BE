# Self-service login history

`GET /api/v1/auth/device-history?page=1&per_page=6` requires the existing authenticated current session. Only the caller's ended login families are returned; user/account filter parameters are rejected. Page is 1–100000, per_page is 1–100; the default is six records. The response uses the standard data/meta/links pagination envelope and `Cache-Control: private, no-store`.

Each record uses the safe profile-session fields, with `is_current: false`, `can_revoke: false`, `status: SIGNED_OUT | EXPIRED`, and `ended_at`. Completion of a login, not token refresh, identifies a history entry. A family's unrevoked/latest descendant is selected before filtering ended families, so a rotated ancestor is not reported as a logout. Expiry before revocation is reported as EXPIRED. No raw refresh tokens, token hashes or replacement credentials are serialized.

Existing active device/session endpoints and revocation/replacement rules are unchanged. History is read-only and does not contribute to active counts or revoke targets. Login metadata is client-declared installation/request data, not verified physical hardware. Legacy records may not have a login start date or device identity; they remain explicitly unavailable.

Retention follows the existing `auth:prune-refresh-tokens` job and `refresh_token.prune_after_days` (default 30 days after expiry/revocation). The endpoint does not create an indefinite login archive or recover already-pruned records. No migration or configuration change is needed for this feature. Deploy this backend route before the corresponding frontend; history failures are handled independently from active device inventory.
