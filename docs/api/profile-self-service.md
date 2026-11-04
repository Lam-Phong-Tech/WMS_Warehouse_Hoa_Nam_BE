# Personal profile self-service

All paths below are under `/api/v1`, require `auth:api`, and scope to the authenticated active user. Query selectors for actor, role, warehouse, or user are rejected. Standard response envelopes remain unchanged. Reads and writes are private and not cacheable.

## Profile and avatar

`GET /auth/me` retains its canonical profile, roles, permissions, warehouse context, session policy and security contracts. Additions are `department` (`department_id`, `department_name`, or null), current IAM-owned `business_profile`, and `avatar` metadata (or null). None of the IAM fields is self-editable.

`PATCH /auth/me` accepts `display_name`, nullable `phone`, and `email`. `current_password` is required and verified only when email changes. Email is normalized to lowercase, validated, and unique including deleted accounts. Existing name/phone constraints remain. Unknown fields are rejected. `If-Match` is required and uses the user version returned by GET; success increments that version once and returns the full profile and new ETag. Missing version is 428 `PRECONDITION_REQUIRED`; stale version is 412 `STALE_VERSION`; missing/wrong password for changed email is 422 `INVALID_CURRENT_PASSWORD`. Email validation/duplicates use the existing validation envelope. Updating email does not claim the address has been independently verified.

`POST /auth/me/avatar` is multipart with required `avatar` plus optional allowed profile fields and `current_password`. PNG, JPEG and WebP are accepted up to 2 MiB and 4096 pixels in each dimension. Raster decoding and re-encoding strip EXIF and trailing content. The image and profile fields commit atomically with one user version increment under the parent user lock. The bounded re-encoded bytes are stored as private longText base64 in `user_profile_avatars`, avoiding public storage ACLs, staging objects, and partial cross-storage commits. `DELETE /auth/me/avatar` accepts no body and uses the same version discipline. Both return the full profile.

Avatar metadata is `{mime_type,size,updated_at,url}`; `url` is `/api/v1/auth/me/avatar`. `GET /auth/me/avatar` returns authenticated binary content with `nosniff`, or 404 if absent. The client must fetch with authorization, then display a blob URL. It must never assume the endpoint is public or send a remote avatar URL as profile input. Raster processing checks each codec independently: missing native support returns 503 `AVATAR_PROCESSING_UNAVAILABLE` for that codec, while supported PNG/JPEG continue working. The frontend cropper submits PNG, including when its source was WebP; no image rebuild is required for this flow.

## Preferences

`GET /auth/preferences` returns the full canonical object with an independent `version` and ETag. The first virtual version is 1; GET does not create a row. `PATCH` takes any supported partial fields, merges partial notification categories/channels, requires `If-Match`, and returns the full object with the preference version incremented once. Profile versions are unaffected. Parent user locks serialize concurrent first writes as well as later writes.

| Field | Values | Default |
| --- | --- | --- |
| locale | vi, en, zh | vi |
| timezone | IANA identifier | Asia/Ho_Chi_Minh |
| date_format | DD/MM/YYYY, MM/DD/YYYY, YYYY-MM-DD | DD/MM/YYYY |
| default_homepage | dashboard, inbound, outbound, inventory | dashboard |
| theme | light, dark, system | system |
| accent | ocean, teal, indigo, graphite | ocean |
| font_size | small, medium, large | medium |
| density | comfortable, compact | comfortable |
| reduced_motion | boolean | false |

`notifications` has `stock_documents`, `inventory`, `warranty`, and `account` categories, each `{in_app,email}`. Defaults are in-app true and email false. Account/security in-app is mandatory; disabling it returns 422 `ACCOUNT_NOTIFICATION_REQUIRED`. Unknown top-level and nested fields are rejected. Home preference never grants access to its module.

`NotificationService` applies preferences when creating recipients and broadcasting: inbound/outbound events map to stock documents; stock/reversal/inventory to inventory; warranty/component to warranty; user/role/security/auth to account. Unmapped events retain existing in-app delivery and default email off. Optional in-app opt-out prevents both recipient rows and broadcasts for that user. Account/security recipients remain mandatory. Explicit email opt-in for an active account with a valid address queues a notification email after commit. Operational delivery requires the existing mail transport and queue worker to be configured; storing a preference does not prove mail delivery. Existing notifications and read/unread state are not deleted or retroactively hidden.

## Client installations and session actions

`X-Device-Id` is an optional client-generated UUID v4 persistent for one client installation. Optional descriptive headers are `X-Device-Label` (120 chars), `X-Client-Platform` (80 chars) and `X-Client-Type` (`web` or `app`). Values are untrusted client declarations. The registry is scoped by user and installation UUID. It does not identify verified physical hardware, infer location, merge Chrome and Edge, grant access, or exempt an account from session replacement. Missing IDs stay unknown. Invalid supplied IDs fail validation and roll back completed-login changes.

New refresh families record nullable installation ID and actual completed-login UTC timestamp. Refresh rotation retains the original identity and timestamp even if later headers are missing or different. Legacy timestamps/identities remain null; no inferred first-login time is presented as fact. Existing user-first token lock order and replay handling remain unchanged.

`GET /auth/sessions` retains existing pagination, mutation target `id` (refresh row UUID), stable `auth_session_id`/`session_id`, and current-family protection. Additions are `device_id`, `client_type`, `platform`, nullable `started_at`, and explicit start-time semantics. `last_active_at` still means login or token refresh, not every API request. Metadata never promises verified device or location.

`GET /auth/devices` returns groups with `device_id` (internal UUID or null), `group_key`, nullable descriptive fields, `metadata_source`, `first_seen_at`, `last_active_at`, activity semantics, `is_current`, `can_revoke`, `session_count`, and full session rows. Unknown legacy families each have their own group key and cannot use a device action. Metadata includes `current_session_id`, identified `active_device_count`, `unknown_device_group_count`, `active_session_count`, `other_session_count`, the unchanged `account_session_policy`, and `device_policy` with identity `CLIENT_INSTALLATION_NOT_PHYSICAL_DEVICE`, null limit numbers and `limits_configured:false`.

`DELETE /auth/devices/{device_id}` revokes all active families belonging to that user's declared installation. It rejects current-containing installations with 409 `CURRENT_DEVICE_PROTECTED`, foreign/unknown IDs with 404, and safely acknowledges an already-revoked installation. Result fields are `device_id`, `revoked:true`, `revoked_session_count`, `already_revoked`, `was_current:false`, `request_receipt_supported:false`.

`POST /auth/sessions/revoke-others` accepts an empty body. Under the parent user lock it revokes all other active own families, retaining the current family. Result fields are `revoked:true`, `revoked_session_count`, `preserved_session_id`, `request_receipt_supported:false`. Repeating it is safe and reports zero changes. Existing `DELETE /auth/sessions/{id}` still revokes one stable family even if an ancestor refresh row was selected; 409 `CURRENT_SESSION_PROTECTED` remains unchanged.

The new bulk/device commands do not implement durable receipts. After an uncertain network outcome, read back the live list and compare stable family IDs; do not automatically resend or claim the read proves which request committed. A new login can occur after a completed revocation; lists are live snapshots.

## Preserved security policy and deployment

The existing `AccountSessionPolicy` is unchanged: when enabled, a committed completed new account login replaces previous account sessions; there are no new three-device or two-session defaults, role exemptions, physical-device exceptions, or pending replacement tokens. MFA enrollment/confirmation/disable/recovery verification and password/logout behavior use existing services and routes. Changing password still requires re-login and revokes every refresh family.

Apply the additive migration before releasing the new backend source and frontend. No historical session identity or start time is backfilled from user-agent/IP guesses. Local SQLite and disposable PostgreSQL tests cover the new APIs; DEV CI includes the feature contracts in SQLite and PostgreSQL plus independent-process account replacement concurrency checks. Original local deployment receiver/config changes are outside this branch.
