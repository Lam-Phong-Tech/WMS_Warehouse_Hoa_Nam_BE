# Risk-Adaptive Login Defense: Progressive Backoff, Trusted Devices, Conditional Proof Of Work

Date: 2026-11-10, Asia/Ho_Chi_Minh. Revision 2: 2026-11-11.
Status: APPROVED_FOR_PLANNING on 2026-11-11. Implementation, migration, config and deploy are still separately gated by the plan and release approval.
Baseline inspected live: DEV/TEST `khohoanamfe.lptech.info.vn`, backend build `ee0b4988407f09d4d064e55efc402de8d6e8b4e4` (Laravel 13.30, PHP 8.3.36, PostgreSQL 16, Valkey). Prod-blue build `f6221061...` is older and is not the screenshot environment.

## 1. Decision And Confirmed Rules

On 2026-11-10 the user selected **all three layers**:

1. progressive/exponential backoff for failed passwords;
2. trusted-device cookies plus email verification for unknown devices;
3. conditional client-side Proof of Work (PoW) as an additional cost layer.

The layers are complementary and independently switchable. Layer 1 is the primary server-side control; Layer 2 restores normal speed for known devices and adds a second factor for unknown devices when MFA is not enabled; Layer 3 raises attacker CPU cost only when risk is already elevated.

The user confirmed these operating rules on 2026-11-11:

| Rule | Confirmed behavior |
|---|---|
| Maximum wait | 15 minutes for the next attempt. Never grows to 30 minutes or more. |
| After the 15-minute wait | If the next attempt is still wrong, do **not** add another wait. Require device verification instead: MFA if enabled, otherwise an email code. |
| Trusted device | Bypasses the account/pair wait, but still requires the correct password. At the 5-cycle mark it must still complete one step-up verification. |
| Cycle definition | One cycle = one time the 15-minute cap is applied. Five cycles means five cap events, not five wrong passwords. |
| Five cycles | Raise a security alert and require one step-up verification. No hard lock; no further automatic lock. |
| Counter lifetime | 24 hours with no violation. A successful login or successful step-up resets immediately. |
| Trusted-device step-up | Confirmed by the user on 2026-11-11: a trusted device skips the wait, but at five cycles it still verifies once (MFA or email OTP) before entering. |

The user selected the direction and these rules. This document records the proposed behavior, thresholds and contracts. Nothing is implemented or approved for production until the user approves this document, the open decisions in §19 are resolved, and the plan is written.

## 2. Live Evidence That Grounds This Design

Measured read-only on 2026-11-10:

- The screenshot environment is DEV/TEST `khohoanamfe.lptech.info.vn`; its API is proxied to the dev backend (DB `wms_hoanam_green`, 83 accounts). Prod-blue (DB `wms_hoanam_blue`, 77 accounts) is a separate 30/10 snapshot and currently has `APP_ENV=testing`, Mailpit mail and no `auth_client_installations` table.
- Current login throttle: key `(IP + lower(email))`, 10 failures, 60-second decay. A live probe produced 10 × `401`, then the 11th request returned `429` with `Retry-After: 58`. After 60 seconds the counter starts from zero again.
- No per-account counter independent of IP, no device verification, no progressive delay, no PoW.
- Audit already records `LOGIN_FAILED` and `LOGIN_THROTTLED`; the probe produced 24 failed and 2 throttled rows in the last hour.
- DEV has 36 `auth_client_installations` rows and 15 live refresh sessions. `user_mfa` has 3 rows but **0 confirmed**; `MFA_ENFORCE_FOR_PRIVILEGED_ROLES=false`.
- DEV mail uses a personal Gmail SMTP account. Prod-blue uses Mailpit and cannot deliver real email. A transactional email provider with SPF/DKIM is a prerequisite for Layer 2 outside a pilot.
- The FE static nginx has no Content-Security-Policy blocking scripts or workers, so a Web Worker / WebCrypto PoW is technically possible.
- Operational issues that must not be conflated with this feature: prod-blue/green Reverb containers have restarted ~16,000 times; prod-green Reverb fails on a missing `cache` table; the scheduler logged `proc_open(): Resource temporarily unavailable` on 2026-11-10 01:00.

## 3. Goals, Non-Goals And Anti-Goals

Goals:

- Make sequential password guessing against one account or one source progressively infeasible without imposing a permanent account lock.
- Preserve normal login speed and ergonomics for known devices and for warehouse staff on low-end scanners.
- Add a real second factor for unknown browsers when the account has not enabled TOTP MFA.
- Give users a visible, revocable "Trusted devices" inventory in the Security tab, including devices that are currently signed out.
- Keep shared-NAT and shared-scanner warehouses usable: never lock a whole site out because one person mistypes; never let one user's trust cookie overwrite another's.
- Keep every layer independently switchable and observable.

Non-goals:

- Physical device attestation, biometrics, WebAuthn/passkeys, or hardware fingerprinting.
- Replacing MFA. Device trust never bypasses an enabled TOTP/recovery challenge.
- Preventing a determined attacker with a large botnet and stolen passwords from ever succeeding; the goal is cost and detection, not an absolute guarantee.
- Permanently locking accounts. Maximum backoff is bounded and the five-cycle state is self-service recoverable.
- Making the Android/app token-transport path depend on a browser cookie in this release.
- An operator "mark this source as high risk" admin surface. Layer 3 is failure-count-only in this release; a manual risk-marking UI/API is explicitly out of scope.
- Changing the existing account-session-replacement policy, shift/draft ownership or realtime behavior.

Anti-goals:

- A server-side `sleep()`/`usleep()` that holds a PHP-FPM worker while an attacker queues requests.
- A hard "10 failures = account locked until admin unlocks" rule that lets anyone DoS a known account.
- Browser fingerprinting (canvas/fonts/hardware) as a security signal.
- A PoW prompt on every successful login.
- Trusting a client-declared `X-Device-Id` alone as proof of a physical device.

## 4. Approaches Considered

| Approach | Verdict |
|---|---|
| Keep the fixed 10/60 throttle | Rejected: resets completely, one IP key, no per-account pressure, trivial to rotate IPs. |
| Permanent lock after N failures | Rejected: account DoS; support burden; no recovery path for a night shift. |
| Server-side increasing sleep | Rejected: ties up workers and turns a login attack into a PHP-FPM exhaustion attack. |
| Progressive cooldown + `Retry-After` | Selected as Layer 1. Cheap, stateless workers, observable, testable. |
| Browser fingerprint as device identity | Rejected: unstable, privacy-invasive, easy to spoof, wrong semantics for shared scanners. |
| Opaque installation ID + HttpOnly trust cookie | Selected as Layer 2. Existing `auth_client_installations` and `X-Device-Id` can be reused. |
| One trust cookie for the whole browser | Rejected: on a shared scanner user B overwrites user A, forcing an OTP every shift. |
| Per-user trust cookies on the same browser | Selected: cookie name includes a hash of the email, so each user keeps their own trust. |
| Email OTP on every new device | Selected as the unknown-device second factor, but only after a correct password and only when MFA is not enabled. |
| PoW on every login | Rejected: punishes low-end scanners and normal users; attacker with GPU still wins. |
| PoW only after elevated risk | Selected as Layer 3, behind a flag, web-only, measured on real devices. |
| WebAuthn/passkeys | Deferred; separate design and enrollment/support project. |

## 5. Layered Login State Machine

The order is deliberate. Cheap checks happen before expensive ones, and no step issues a token until every required factor has passed.

1. Resolve the installation identity and, if the submitted email has a valid trust cookie, resolve `trusted=true`. The cookie is read by the per-user name derived from the email (§7.2).
2. Pair-key pre-check: if `(IP + email)` is blocked and `trusted=false`, return `429` + `Retry-After`. A valid trust cookie bypasses this pre-check.
3. Account-key pre-check: if the account key is blocked and `trusted=false`, return `429`. A valid trust cookie bypasses this pre-check. The password is still required in both cases.
4. If `step_up_required=true` (five cap cycles reached), do not issue tokens. Return a step-up challenge (§6.5). This applies to trusted and untrusted devices. Do not check or reveal password correctness before step-up.
5. If risk requires PoW, require a valid single-use challenge solution before password verification (`428 POW_REQUIRED`), after step-up has passed.
6. Verify email/password exactly as today, including the case-sensitive email check.
7. On failure: increment the pair and account counters; compute the delay; return `401` or, when a delay now applies, `429` + `Retry-After`; audit; generic message.
8. On success: clear both counters.
9. If MFA is enabled: issue the existing MFA challenge; do not issue access/refresh tokens. On MFA success, complete login and, if the device is not yet trusted, trust it (no second email OTP).
10. If MFA is not enabled and the device is trusted for this user: complete login.
11. If MFA is not enabled and the device is not trusted: issue a short-lived device challenge and email a 6-digit code. Do not issue access/refresh tokens.
12. On valid device OTP: mark the installation trusted, issue the trust cookie, then complete login.
13. If the account is not ACTIVE, keep the existing `403 ACCESS_DENIED` behavior and do not send OTP.

No layer changes the existing session-replacement policy: completion, not the password step, is where a session is created or replaced.

## 6. Layer 1 — Progressive Backoff And Step-Up

### 6.1 Keys

- Pair key: `login-backoff:pair:{sha256(lower(email) + "|" + ip)}`.
- Account key: `login-backoff:account:{sha256(lower(email))}`.
- Both are created for every login attempt, including non-existent emails, so response behavior does not reveal whether an account exists.
- Neither key stores a raw email or IP. TTL is **24 hours** after the last failure (`LOGIN_BACKOFF_COUNTER_TTL_SECONDS=86400`).
- State lives in Valkey/Redis, not PostgreSQL: `{failures: int, cap_hits: int, blocked_until: unix_ts, step_up_required: bool}`. Updates are serialized with a short cache lock so concurrent requests cannot both read the same count.

### 6.2 Delay sequence and cycles

Free attempts: 3. From the 4th consecutive failure:

`1, 2, 4, 8, 16, 30, 60, 120, 300, 600, 900` seconds, then the 15-minute cap.

The sequence is configurable (`LOGIN_BACKOFF_DELAYS=1,2,4,8,16,30,60,120,300,600,900`). The cap is 900 seconds for both keys. The account key and pair key compute independently; the effective wait is the larger `blocked_until`.

A **cycle** is counted each time the 15-minute cap is applied: the account was blocked for 15 minutes, the wait expired, and another wrong password occurred. `cap_hits` increments once per such event, not on every request during the wait.

After **5 cap hits**:

- set `step_up_required=true`;
- do not apply another 15-minute wait;
- raise `LOGIN_STEP_UP_REQUIRED` and notify administrators;
- the next login attempt follows §6.5.

The 24-hour TTL expires the whole state if there is no new violation. A successful login or a completed step-up plus correct password clears it immediately.

### 6.3 What a blocked attempt returns

```
HTTP/1.1 429 Too Many Requests
Retry-After: 60
Content-Type: application/json

{
  "success": false,
  "message": "Bạn đã đăng nhập sai quá nhiều lần. Vui lòng thử lại sau 60 giây.",
  "error_code": "RATE_LIMIT_EXCEEDED",
  "errors": [],
  "error": {
    "code": "RATE_LIMIT_EXCEEDED",
    "details": { "retry_after_seconds": 60 }
  }
}
```

Rules:

- The same message is used for existing and non-existing emails.
- No `sleep` in PHP. The worker is released immediately.
- `Retry-After` is integer seconds, never a fabricated duration.
- A successful login clears both keys and records `LOGIN` as today.
- The response must not say "account locked" and must not disclose the account status.
- An already-authenticated session is not affected by login backoff. Backoff is a login gate, not a session revocation.

### 6.4 Shared NAT and account DoS

- Pair key protects one source from one identifier; shared warehouse NAT does not lock unrelated users because the key includes the identifier.
- Account key is global across IPs but is bounded at 15 minutes.
- A valid trust cookie bypasses **both** pre-checks. This is deliberate: a known device on a shared NAT must not be blocked because someone else on the same NAT mistyped the victim's email. The password is still required, and the five-cycle step-up still applies. The trade-off is documented in §11.
- A legitimate user on an untrusted device during an active attack can still be blocked for up to 15 minutes. The support path is: wait, use a known device, complete the step-up when it is offered, or ask an administrator to clear the counters.
- Administrators can clear counters with an audited artisan command (`php artisan auth:clear-login-throttle --email=...`). No new admin UI or permission is added in this release.

### 6.5 Step-up after five cap cycles

Step-up is a **one-time verification gate**, not another wait. It applies to trusted and untrusted devices.

1. The user submits email and password as usual.
2. If `step_up_required=true`, the server does not issue tokens and does not reveal whether the password was correct. It returns `200` with a challenge:

```json
{
  "success": true,
  "message": "Vì lý do bảo mật, vui lòng xác minh thiết bị để tiếp tục.",
  "data": {
    "step_up_required": true,
    "step_up_token": "...",
    "method": "mfa",
    "masked_email": "k***@gmail.com",
    "expires_in": 300
  }
}
```

- `method=mfa` when the account has confirmed MFA. No email is sent; the user enters a TOTP or recovery code.
- `method=email` when MFA is not enabled. A 6-digit code is sent to the registered email.

3. `POST /api/v1/auth/step-up/verify` with `{step_up_token, code}` verifies the code. On success:

- the step-up challenge is consumed;
- a short-lived `step_up_verified` state is stored (5 minutes, **3 password attempts**);
- the response returns `{step_up_verified: true, password_attempts_left: 3, expires_in: 300}`;
- no access/refresh token is issued yet.

4. The user submits the login again with `{email, password, step_up_token}`. The server verifies the step-up state, decrements the password attempts, and checks the password.

- Correct password: clear all counters and step-up state, then continue with MFA (if the step-up method was email and MFA is enabled) or the normal device/MFA flow.
- Wrong password: `401`; decrement attempts. At zero, the step-up state is invalid and a new step-up is required. No 15-minute wait is added.
- A step-up via email also counts as device verification for that installation: mark it trusted and set the trust cookie after the correct password, so the user does not receive a second email OTP.
- A step-up via MFA counts as the MFA factor for this login; do not ask for a second MFA challenge.

5. If the step-up code is wrong, increment its attempt counter. After 5 wrong codes, invalidate it and require a new step-up. Rate-limit by IP + step-up token + user.
6. `POST /api/v1/auth/step-up/resend` re-sends the email code with a 60-second cooldown and a maximum of 5 per hour. MFA accounts do not use resend.
7. A successful step-up does not reset the counters until the password is also correct. This prevents an attacker who only controls the inbox from resetting the throttle and then brute-forcing.
8. If the account becomes `LOCKED`/`INACTIVE` while a step-up is pending, the login returns `403 ACCESS_DENIED`; no token is issued.

### 6.6 Interaction with the existing limiter

Replace the current `RateLimiter::tooManyAttempts`/`hit(..., 60)` block in `AuthController::login` with a `LoginThrottle` service. Keep a coarse IP limiter on `/auth/login` (e.g. 60 requests/minute/IP) to protect bcrypt and the cache. The backoff service owns the per-account and per-pair state.

## 7. Layer 2 — Trusted Devices And Email Verification

### 7.1 Identity model

A trusted device is **not** a physical device. It is a browser/installation that completed a full login for a specific user and received an opaque trust cookie. The UI and API must say this explicitly, matching the existing `CLIENT_DECLARED_INSTALLATION_NOT_VERIFIED_PHYSICAL_DEVICE` semantics.

- Reuse `auth_client_installations` as the installation inventory.
- Add a new `trusted_devices` table for trust state, so trust can be revoked independently without deleting installation history:

```text
trusted_devices
  id                 uuid primary key
  user_id            uuid not null references app_user
  installation_id    uuid not null references auth_client_installations(id)
  token_hash         char(64) not null              -- SHA-256 of the raw cookie token
  previous_hash      char(64) null                  -- rotation grace
  previous_valid_until timestamptz null
  trusted_at         timestamptz not null
  expires_at         timestamptz not null
  last_used_at       timestamptz null
  revoked_at         timestamptz null
  user_agent         varchar(512) null
  ip_address         varchar(64) null
  created_at/updated_at
```

Indexes: unique partial `(user_id, installation_id) WHERE revoked_at IS NULL`; unique partial `(token_hash) WHERE revoked_at IS NULL`; index `(user_id, expires_at)`.

### 7.2 Installation identity and cookies

The trust row and device challenge need an installation ID even when the browser does not send `X-Device-Id` (for example, older browsers without `crypto.randomUUID`, or a client that omits the header).

Resolution order:

1. `X-Device-Id` header, if present and a valid UUID.
2. A server-issued `wms_installation_id` cookie, encrypted with the application key and resolved to an installation row.
3. If neither exists, generate a UUID, create the installation row, and set the encrypted installation cookie.

The installation cookie is `HttpOnly`, `Secure` (configurable for local HTTP), `SameSite` from config, `Path=/api/v1/auth`, `Max-Age` one year. It is the durable identity; the client-declared header cannot switch an existing installation to a new trust row. The header remains useful for labels/platform metadata.

Per-user trust cookie:

- Name: `wms_trusted_device_` + the first 16 hex characters of `sha256(lower(email))`. The server computes the name from the submitted email, so a shared browser can hold one trust cookie per user without overwriting another user's cookie.
- Value: 32 random bytes, base64url, prefix `v1.`; no user id, email, role or expiry is encoded in the cookie.
- Attributes: `HttpOnly`, `Secure`, `SameSite` from config, `Path=/api/v1/auth`, `Max-Age` = 30 days.
- Server stores only `hash('sha256', token)`.
- Rotation: when a trust is older than 7 days and is used successfully, issue a new token and keep `previous_hash` valid for 10 minutes for concurrent tabs. Do not rotate on every request.
- Logout does **not** clear the trust cookie. "Revoke device" in the Security tab does.
- Password reset, password change, email change, admin password reset and account lock/disable revoke all trusts by default. This means the first login after a reset needs a new email OTP; record this as a deliberate decision.
- Maximum 10 active trusts per user. If the limit is reached, the oldest expired/least-recently-used trust is revoked first, and the event is audited. Do not silently stop trusting new devices.

### 7.3 Unknown-device flow (MFA disabled)

1. Password is correct.
2. No valid trust cookie for this user/installation.
3. Generate a `device_token` (64 random chars, cache-only, 5-minute TTL) bound to `{user_id, installation_id, email, otp_id}`. Do not issue access/refresh tokens.
4. Create a `login_device_otps` row: hashed 6-digit code, 5-minute expiry, 0 attempts, request IP.
5. Send a short email: code, 5-minute validity, "Nếu không phải bạn, hãy đổi mật khẩu và liên hệ quản trị viên." No links, no tokens.
6. Return `200`:

```json
{
  "success": true,
  "message": "Thiết bị này chưa được tin cậy. Nhập mã xác minh đã gửi tới email đăng ký.",
  "data": {
    "device_verification_required": true,
    "device_token": "...",
    "expires_in": 300,
    "masked_email": "k***@gmail.com"
  }
}
```

7. `POST /api/v1/auth/device/verify` with `{device_token, code}`. On success: consume the OTP, mark the installation trusted, issue the trust cookie, complete login (create/replace session as today), audit `DEVICE_TRUSTED`, and return the normal login payload.
8. On failure: `422 DEVICE_OTP_INVALID` with a generic message. Increment OTP attempts; after 5, invalidate the OTP and require a resend. Rate-limit by IP + device token + user.
9. `POST /api/v1/auth/device/resend` with `{device_token}`: 60-second cooldown, maximum 5 per hour per user, invalidates the previous OTP. Same generic response whether or not the email exists (the token itself is unguessable and bound to a user).
10. If email delivery fails: return `503 EMAIL_DELIVERY_UNAVAILABLE` with a support message; do not issue a session. Log the provider error without the code. This is fail-closed.

### 7.4 MFA-enabled flow

- Password correct → existing MFA challenge. No device OTP is sent.
- MFA success (TOTP or recovery code) → complete login; if the device is not trusted, create a trust automatically (`TRUST_AFTER_MFA=true`) and set the cookie.
- Rationale: MFA is stronger than email OTP; asking for both is friction without a security gain. Device trust must never be a reason to skip MFA.
- If MFA is required but not enrolled, the existing `EnforceMfaEnrollment` path remains unchanged. Do not invent a device-trust bypass.

### 7.5 Transport scope

- Trusted-device enforcement applies to the **browser cookie transport** only (`X-WMS-Token-Transport: cookie`, or no explicit body transport). The Android/app body-transport path continues to use password + MFA + backoff and is exempt in this release.
- This prevents an app release from being blocked by a web-only cookie feature. A later app release can add device attestation separately.
- Do not accept `X-Device-Id` alone as trust. It remains a client-declared label for inventory, not proof.

### 7.6 Security tab UI ("Thiết bị tin cậy")

Place a new card in `ProfilePage` → `section=security`, below the MFA card and before "Lịch sử quyền và vai trò".

Vietnamese copy:

> **Thiết bị tin cậy**
> Các thiết bị đã xác minh và được phép đăng nhập mà không cần mã xác thực qua email. Khi đăng nhập từ thiết bị lạ, hệ thống sẽ gửi mã xác minh tới email đăng ký của bạn.
>
> [icon] Windows · Chrome — **Thiết bị hiện tại**
> Hoạt động gần nhất: 10/11/2026 23:27 · Tin cậy đến: 10/12/2026
> **Thu hồi**
>
> *Danh tính thiết bị do trình duyệt khai báo và được xác minh bằng cookie bảo mật; không phải nhận diện phần cứng vật lý.*

Empty state:

> Chưa có thiết bị tin cậy. Thiết bị sẽ được ghi nhận sau lần đăng nhập thành công tiếp theo.

Revoke confirmation:

> Thu hồi thiết bị này?
> Thiết bị sẽ phải xác minh lại bằng mã email ở lần đăng nhập tới. Phiên đang hoạt động trên thiết bị này cũng sẽ bị đăng xuất.

The card lists **trust rows, including devices that are currently signed out**. A trust that exists after logout must remain visible and revocable. It is not the same list as active sessions.

API:

- `GET /api/v1/auth/trusted-devices` returns active trust rows: `id`, `device_label`, `platform`, `client_type`, `is_current`, `trusted_at`, `last_used_at`, `expires_at`, `can_revoke`.
- `DELETE /api/v1/auth/trusted-devices/{id}` revokes that trust and the installation's active sessions. If it is the current device, it also clears the trust cookie and returns `was_current: true`; the FE logs out immediately.
- Do **not** overload `/auth/devices` or change its existing `CURRENT_DEVICE_PROTECTED` 409 contract in this release. The new trust endpoint is separate so the existing active-session panel and its tests remain stable.
- Never return the raw trust token, hash or OTP in any GET response.

### 7.7 Bulk and administrative revocation

The following actions also revoke trust, because a user or administrator who revokes a session expects the device not to log back in without a new OTP:

- `POST /auth/sessions/revoke-others` revokes trust for **other** installations, preserves the current device's trust, and adds `trust_revoked_count` to the response and audit.
- Admin password reset (`UserController::update` with a new password) revokes all trusts for that user.
- Admin lock/disable/status change revokes all trusts for that user.
- Email change revokes all trusts (the per-user cookie name changes; the old cookie becomes unreadable for the new email).
- Password change/reset revokes all trusts.

Each path records an audit event with the actor and scope, without tokens or OTPs.

### 7.8 Email prerequisite

Layer 2 outside a pilot requires a transactional email provider with SPF, DKIM and a monitored bounce/complaint channel. The current DEV Gmail account is acceptable only for a small pilot. Prod-blue Mailpit is not acceptable. The feature flag stays off until delivery is verified end-to-end.

## 8. Layer 3 — Conditional Proof Of Work

### 8.1 When it is required

- Only for the browser cookie transport.
- Only when the pair or account failure count is at least `LOGIN_POW_TRIGGER_FAILURES` (default 3). **Failure-count-only**: manual/operator risk marking is out of scope for this release.
- Never on the first normal login, never after a successful login, never for MFA verification, refresh, logout, step-up verification or device OTP.
- A valid trusted device does not automatically skip PoW if the failure/risk signal is high; PoW is about the attempt pattern.
- Step-up is evaluated first. If `step_up_required=true`, complete step-up before requiring PoW.

### 8.2 Challenge

`POST /api/v1/auth/login/pow-challenge` (public, rate-limited 30/minute/IP):

```json
{
  "email": "user@example.com"
}
```

Response:

```json
{
  "success": true,
  "data": {
    "challenge_id": "uuid-or-128-bit-hex",
    "nonce": "32-hex",
    "difficulty": 16,
    "algorithm": "sha256-leading-zero-bits",
    "expires_in": 120
  }
}
```

- The challenge is stored in Valkey for 120 seconds: `{nonce, difficulty, email_hash, ip_hash, used: false}`. `challenge_id` is random and unguessable; no HMAC is needed because the state is server-side.
- Difficulty is derived server-side from the failure counters, not from the client: 16 bits at trigger, 18 after 6 failures, 20 after 9, cap 22. Configurable.
- The endpoint creates a challenge for any syntactically valid email, existing or not, and returns the same shape.

### 8.3 Client solve

- A Web Worker computes `SHA-256(nonce + ":" + counter)` for counter = 0,1,2,… until the digest has `difficulty` leading zero bits.
- Use a compact SHA-256 implementation or a small WASM module; measure on the oldest supported scanner. Target median 0.5–2 seconds, p95 ≤ 3 seconds at 16 bits. Do not use a naive per-hash `crypto.subtle.digest` loop if it cannot meet the budget.
- Show a non-blocking status: "Đang xác minh thiết bị…" with a cancel option; keep the login form disabled while solving.
- If Web Workers/WebCrypto are unavailable (old browser, insecure context), skip PoW and send `pow_supported=false`; the server still applies Layers 1–2. PoW is a cost layer, not a security boundary, so this fallback is acceptable and audited.
- On success, submit the login request with `pow: {challenge_id, solution}`.
- On failure/timeout, allow one retry with a fresh challenge; after 5 invalid solutions, `429` and backoff.

### 8.4 Server verification

- Look up the challenge, reject if missing/expired/used, verify the email hash matches the login email, verify the digest has the required leading zero bits, mark used atomically, then continue to password verification.
- The verification cost is one SHA-256, independent of difficulty; this is why PoW shifts cost to the client.
- Invalid/missing PoW when required → `428 POW_REQUIRED` with a fresh challenge, and audit `LOGIN_POW_FAILED`. Update the FE classifier to treat `428` as a distinct state, not as a service outage.
- A PoW failure does not count as a password failure, but it is rate-limited separately (e.g. 10 invalid solutions per 10 minutes per IP+email).
- The login request accepts optional `pow`; absence is only valid when the server did not require it.

### 8.5 Why not everywhere

- Low-end warehouse scanners may take 5–15× longer than a developer laptop. A mandatory 2-second PoW can become a 20-second login on the floor, which drives shared-password workarounds.
- A GPU/botnet can solve SHA-256 PoW far faster than a browser; PoW raises cost but does not stop a funded attacker.
- Layers 1–2 are the actual security controls. Layer 3 is a speed bump that makes repeated guessing and credential-stuffing campaigns more expensive, and it provides a useful signal.

## 9. Interaction With Existing Auth Features

- **Account session replacement**: creation/replacement happens only at full completion (login, MFA success, device OTP success, step-up completion followed by correct password). A failed password, a pending MFA challenge, a pending step-up or a pending device challenge must not replace an existing session.
- **MFA**: device trust never bypasses MFA. MFA success may create trust automatically. Recovery codes count as MFA success. A step-up via MFA counts as the MFA factor for that login.
- **Refresh**: trusted-device, step-up and PoW checks do not apply to `/auth/refresh`. A live refresh token remains sufficient to rotate, subject to existing replay/revocation rules.
- **Logout**: ends the current session but does not revoke trust. A separate "revoke device" action revokes trust and the device's sessions.
- **Change password/reset**: revokes refresh tokens as today, and revokes all trusted devices by default. Audit both.
- **Bulk revoke-others**: revokes other sessions and other trusts; current trust remains.
- **Admin reset/lock/disable**: revokes all trusts.
- **Email change**: revokes all trusts.
- **Account status**: `LOCKED`/`INACTIVE` keeps the existing `403 ACCESS_DENIED` path; no OTP is sent; no trust is created.
- **Session inventory**: `GET /auth/devices` and `GET /auth/device-history` keep their current contracts. Trust metadata lives in the new `/auth/trusted-devices` endpoint.
- **Admin/IAM**: no new permission is required to view/revoke one's own devices. Counter clearing is an audited artisan command, not a new UI/permission.

## 10. Data, Config And Contracts

### 10.1 New migration

`2026_11_11_XXXXXX_create_trusted_devices_and_login_device_otps.php`:

- `trusted_devices` as in §7.1.
- `login_device_otps`: `id uuid`, `user_id uuid`, `email varchar(200)`, `otp_hash varchar(255)`, `attempts smallint default 0`, `expires_at timestamptz`, `consumed_at timestamptz null`, `ip_address varchar(64) null`, `created_at`.
- No change to `app_user`, `refresh_tokens` or `auth_client_installations` columns is required, except ensuring the existing installations table exists on every environment (prod-blue currently lacks it; the latest migration set creates it).
- Migration must be additive and reversible; test on a copy of the DEV DB before applying.

### 10.2 Config file

Create `config/login_security.php`:

```php
return [
    'backoff' => [
        'enabled' => (bool) env('LOGIN_BACKOFF_ENABLED', true),
        'free_attempts' => (int) env('LOGIN_BACKOFF_FREE_ATTEMPTS', 3),
        'delays' => array_map('intval', explode(',', (string) env(
            'LOGIN_BACKOFF_DELAYS', '1,2,4,8,16,30,60,120,300,600,900'
        ))),
        'cap_seconds' => (int) env('LOGIN_BACKOFF_CAP_SECONDS', 900),
        'counter_ttl_seconds' => (int) env('LOGIN_BACKOFF_COUNTER_TTL_SECONDS', 86400),
        'step_up_after_cycles' => (int) env('LOGIN_STEP_UP_AFTER_CYCLES', 5),
        'alert_after_cycles' => (int) env('LOGIN_ALERT_AFTER_CYCLES', 5),
    ],
    'step_up' => [
        'enabled' => (bool) env('LOGIN_STEP_UP_ENABLED', true),
        'token_ttl_seconds' => (int) env('LOGIN_STEP_UP_TOKEN_TTL_SECONDS', 300),
        'password_attempts' => (int) env('LOGIN_STEP_UP_PASSWORD_ATTEMPTS', 3),
        'max_code_attempts' => (int) env('LOGIN_STEP_UP_MAX_CODE_ATTEMPTS', 5),
        'otp_resend_cooldown_seconds' => (int) env('LOGIN_STEP_UP_RESEND_COOLDOWN_SECONDS', 60),
        'otp_max_per_hour' => (int) env('LOGIN_STEP_UP_OTP_MAX_PER_HOUR', 5),
    ],
    'trusted_device' => [
        'enabled' => (bool) env('TRUSTED_DEVICE_ENABLED', false),
        'cookie_name_prefix' => (string) env('TRUSTED_DEVICE_COOKIE_PREFIX', 'wms_trusted_device_'),
        'cookie_path' => (string) env('TRUSTED_DEVICE_COOKIE_PATH', '/api/v1/auth'),
        'cookie_same_site' => (string) env('TRUSTED_DEVICE_COOKIE_SAME_SITE', 'lax'),
        'cookie_secure' => (bool) env('TRUSTED_DEVICE_COOKIE_SECURE', true),
        'ttl_days' => (int) env('TRUSTED_DEVICE_TTL_DAYS', 30),
        'max_per_user' => (int) env('TRUSTED_DEVICE_MAX_PER_USER', 10),
        'rotate_after_days' => (int) env('TRUSTED_DEVICE_ROTATE_AFTER_DAYS', 7),
        'rotation_grace_minutes' => (int) env('TRUSTED_DEVICE_ROTATION_GRACE_MINUTES', 10),
        'revoke_on_password_change' => (bool) env('TRUSTED_DEVICE_REVOKE_ON_PASSWORD_CHANGE', true),
        'enforce_transports' => ['cookie'],
    ],
    'device_otp' => [
        'ttl_minutes' => (int) env('DEVICE_OTP_TTL_MINUTES', 5),
        'max_attempts' => (int) env('DEVICE_OTP_MAX_ATTEMPTS', 5),
        'resend_cooldown_seconds' => (int) env('DEVICE_OTP_RESEND_COOLDOWN_SECONDS', 60),
        'max_per_hour' => (int) env('DEVICE_OTP_MAX_PER_HOUR', 5),
        'challenge_ttl_seconds' => (int) env('DEVICE_OTP_CHALLENGE_TTL_SECONDS', 300),
    ],
    'pow' => [
        'enabled' => (bool) env('LOGIN_POW_ENABLED', false),
        'trigger_failures' => (int) env('LOGIN_POW_TRIGGER_FAILURES', 3),
        'base_difficulty' => (int) env('LOGIN_POW_BASE_DIFFICULTY', 16),
        'max_difficulty' => (int) env('LOGIN_POW_MAX_DIFFICULTY', 22),
        'challenge_ttl_seconds' => (int) env('LOGIN_POW_CHALLENGE_TTL_SECONDS', 120),
        'max_solve_ms' => (int) env('LOGIN_POW_MAX_SOLVE_MS', 3000),
    ],
];
```

All thresholds are config-driven; `0`/`false` disables the layer. No hard-coded magic numbers in controllers.

### 10.3 API summary

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/api/v1/auth/login` | public | Add optional `pow` and `step_up_token`; may return `428`, `429`, MFA, step-up or device challenge |
| POST | `/api/v1/auth/login/pow-challenge` | public | Issue a single-use PoW challenge |
| POST | `/api/v1/auth/step-up/verify` | public + step-up token | Verify MFA or email code, return `step_up_verified` + password attempts |
| POST | `/api/v1/auth/step-up/resend` | public + step-up token | Resend the email code with cooldown |
| POST | `/api/v1/auth/device/verify` | public + device token | Verify email OTP, trust device, complete login |
| POST | `/api/v1/auth/device/resend` | public + device token | Resend device OTP with cooldown |
| GET | `/api/v1/auth/trusted-devices` | auth:api | List active trust rows, including signed-out devices |
| DELETE | `/api/v1/auth/trusted-devices/{id}` | auth:api | Revoke trust and the installation's sessions |

All public endpoints get dedicated named rate limiters in `AppServiceProvider`; do not rely on the generic API limiter.

### 10.4 Error codes

- `RATE_LIMIT_EXCEEDED` — 429, with `retry_after_seconds`.
- `POW_REQUIRED` — 428, with challenge details.
- `POW_INVALID` — 428 with a fresh challenge, `error_code=POW_INVALID`.
- `STEP_UP_REQUIRED` — 200 with `data.step_up_required=true` (not an error; mirrors MFA).
- `STEP_UP_INVALID` — 422, generic message.
- `STEP_UP_EXPIRED` — 422, ask the user to log in again.
- `STEP_UP_LOCKED` — 423 after too many wrong codes.
- `DEVICE_VERIFICATION_REQUIRED` — 200 with `device_verification_required=true`.
- `DEVICE_OTP_INVALID` — 422, generic message.
- `DEVICE_CHALLENGE_EXPIRED` — 422, ask the user to log in again.
- `EMAIL_DELIVERY_UNAVAILABLE` — 503, fail closed.
- `TRUSTED_DEVICE_LIMIT_REACHED` — 409 if the policy chooses to deny rather than evict; default is evict the oldest.

## 11. Security, Privacy And Abuse Analysis

- **Enumeration**: unknown emails receive the same 401/429/step-up shapes; OTP is only sent after a correct password or after the five-cycle step-up state; step-up responses do not reveal password correctness; device challenge responses say "email đăng ký" without repeating the address.
- **Account DoS**: pair key blocks before bcrypt; account key is capped at 15 minutes. A valid trust cookie bypasses both waits for known devices. An attacker on the same NAT can still force step-up after five cycles, but cannot permanently lock the account; the user can step up and reset. This is a known trade-off, monitored via `LOGIN_THROTTLED` and `LOGIN_STEP_UP_REQUIRED` alerts.
- **Trust cookie theft**: HttpOnly, hashed at rest, rotatable, revocable, 30-day TTL. It is a bearer credential; TLS, HSTS and the existing CSP posture remain required. A stolen cookie bypasses the waits but still needs the password and the five-cycle step-up.
- **Shared scanner / shared browser**: per-user trust cookie names prevent one user from overwriting another's trust. The installation cookie is shared, but trust rows are per `(user, installation)`, so user B does not inherit user A's trust. Each user still needs their own password; MFA still applies.
- **Missing `X-Device-Id`**: the encrypted installation cookie provides a durable fallback; a client that disables cookies and sends no header gets an OTP on every login rather than a bypass.
- **XSS**: the trust and installation cookies are HttpOnly, but an XSS can still issue requests. Existing CSP, output encoding and dependency review remain the controls.
- **CSRF**: trust, installation and step-up cookies are only consumed by `/auth/login`, `/auth/step-up/*`, `/auth/device/*` and `/auth/trusted-devices`; the existing Origin allowlist middleware must cover these routes. `SameSite=Lax/None` follows the refresh-cookie policy.
- **OTP brute force**: 6 digits, 5 attempts, 5-minute TTL, hashed, one active OTP per challenge, rate-limited by IP + token + user, resend cooldown. Codes are never logged.
- **Email bombing**: OTP is sent only after a correct password or the step-up state, max 5 per hour per user, generic response for resend. An attacker who knows a password can spam the inbox within that bound; alerts cover it.
- **PoW farming**: challenges are rate-limited, single-use, 120-second TTL, bound to email hash; invalid solutions are rate-limited; verification cost is cheap.
- **Timing**: use constant-time comparisons for hashes; do not branch on whether an email exists; run the same password hash path for existing and non-existing users where feasible.
- **Logging**: never log passwords, OTPs, raw trust/installation tokens, token hashes, full email addresses or raw user agents in new events. Use ids/hashes and masked email. Existing audit retention applies.
- **Privacy**: no fingerprinting, no canvas, no font enumeration, no precise location. Device label is client-declared and editable; the UI says so.

## 12. Observability

New audit actions:

- `LOGIN_THROTTLED` (extend existing): `scope=pair|account`, `failure_count`, `cap_hits`, `retry_after_seconds`, `trust_cookie_valid`, no email.
- `LOGIN_STEP_UP_REQUIRED`, `LOGIN_STEP_UP_COMPLETED`, `LOGIN_STEP_UP_FAILED`, `LOGIN_STEP_UP_RESEND`.
- `DEVICE_TRUSTED`, `DEVICE_TRUST_REVOKED`, `DEVICE_TRUST_EXPIRED`, `DEVICE_VERIFICATION_SENT`, `DEVICE_VERIFICATION_FAILED`, `DEVICE_OTP_RESEND`.
- `LOGIN_POW_REQUIRED`, `LOGIN_POW_FAILED`, `LOGIN_POW_SOLVED` (difficulty, client-reported solve ms, not trusted for security decisions).
- `LOGIN_THROTTLE_CLEARED` for the audited admin CLI.

Dashboards/alerts:

- 429 rate by IP/account; top throttled identifiers (hashed).
- Step-up required/completed/failed rates; five-cycle alerts.
- OTP send success/failure and provider latency; email bounce/complaint rate.
- Trust cookie validation failures (possible theft/rotation bugs).
- PoW solve p50/p95 by user agent family; alert if p95 > 5s or failure rate > 10%.
- Account-level lockout/step-up events > 5 cycles in 24h.

## 13. UI And i18n

Login page states (new):

- `pow-required` / `pow-solving`: "Đang xác minh thiết bị…" (non-blocking progress, cancel).
- `step-up-required`: MFA code or 6-digit email code, masked email, resend countdown, "Tiếp tục đăng nhập" after verification.
- `device-verification`: 6-digit input, masked email, resend countdown, "Quay lại đăng nhập".
- `rate-limited`: existing countdown component, extended to the new delay values; never auto-submits when it reaches zero.
- `device-trusted` success toast: "Thiết bị đã được tin cậy trong 30 ngày."

Security tab:

- New "Thiết bị tin cậy" card and copy in §7.6. It lists trust rows even when the device has no active session.
- Current-device revoke is allowed through the new trust endpoint, with the confirmation in §7.6; it ends the current session and returns the user to the login page.
- Mobile layout: one card per device, no horizontal table.
- Accessibility: status announced with `role="status"`, OTP input labelled, countdown not relied on alone, focus moves to the OTP field on state change.
- i18n keys: `auth.login.pow*`, `auth.login.stepUp*`, `auth.device.*`, `auth.personal.trustedDevices.*`; Vietnamese and English must both be complete.

## 14. Rollout And Rollback

1. **Phase 1 — Backoff + step-up** on DEV/TEST. Flags `LOGIN_BACKOFF_ENABLED=true`, `LOGIN_STEP_UP_ENABLED=true`. Verify live 429/Retry-After, five-cycle step-up, audit, shared-NAT behavior and no worker exhaustion. Then deploy the same code to prod-blue after its migration gap is closed.
2. **Phase 2 — Trusted devices** on DEV/TEST, flag off by default. Pilot 5–10 users with the existing Gmail SMTP. Verify email delivery, OTP flow, per-user cookies on a shared browser, installation-cookie fallback, revoke, password reset, bulk revoke, multi-tab rotation. Move to a transactional provider before enabling for all users. Then prod-blue.
3. **Phase 3 — PoW** on DEV/TEST, flag off. Enable only for elevated risk; measure on the oldest scanner. Do not enable globally until p95 ≤ 3s and the support burden is understood.
4. **Prod-blue prerequisites**: run the missing migrations (including `auth_client_installations`), replace Mailpit with a real provider, re-verify `APP_ENV`/`APP_URL`, and fix the Reverb restart loop and scheduler errors before claiming "production".

Rollback:

- Turn the layer flag off. Layer 1 falls back to the current 10/60 limiter; Layer 2 stops enforcing device OTP (existing trust rows remain, cookies are ignored); Layer 3 stops requiring PoW.
- Do not delete `trusted_devices` or OTP rows on rollback; expire them naturally.
- A rollback must not revoke live sessions or trusts automatically.
- Keep the audit trail intact.

## 15. Testing And Acceptance Matrix

Unit:

- Backoff sequence, free attempts, cap, five-cycle counter, reset on success, pair vs account independence, concurrent updates, 24-hour TTL.
- Trust token generation/hash/expiry/rotation grace/revoke; max devices; per-user isolation; per-user cookie naming; installation-cookie encryption/fallback; cookie attributes.
- OTP hash, expiry, attempts, resend cooldown, one-active-code rule; step-up token/password-attempt accounting.
- PoW challenge single-use/expiry/email binding/difficulty; leading-zero-bit verification; invalid solutions.

Feature/API:

- 10 failures → `429` with the correct `Retry-After`; 3 failures → next attempt requires PoW when enabled.
- Five cap cycles → `STEP_UP_REQUIRED`; MFA method for MFA accounts; email method otherwise; no tokens before step-up; 3 password attempts; reset on correct password.
- Correct password on a trusted device with MFA off → session; with MFA on → MFA challenge, no device OTP; trusted device still steps up at five cycles.
- Correct password on an unknown device with MFA off → no tokens, OTP sent, verify → session + trust cookie; wrong OTP → 422, 5 attempts → invalid.
- Wrong password never sends device OTP; unknown email never sends OTP; inactive account never sends OTP; step-up response does not reveal password correctness.
- Trusted device bypasses both pair and account waits; invalid/expired/revoked cookie does not bypass.
- Shared browser: user A and user B each retain their own trust cookie and trust row; B's login does not invalidate A's trust.
- Missing `X-Device-Id`: installation cookie fallback works; no-cookie client gets OTP each login, not a bypass.
- Device OTP completion respects session replacement; a failed/pending challenge does not replace a session.
- Current-device trust revoke ends the session and clears the cookie; other-device trust revoke ends its sessions; bulk revoke-others revokes other trusts; admin reset/lock revokes all trusts.
- PoW missing/invalid → 428; valid → login proceeds; replay/expired → rejected; challenge endpoint rate-limited; operator risk marking is absent.
- All new public endpoints have Origin/CSRF and rate-limit coverage.
- Audit events and no secret leakage in logs/responses.

Frontend:

- Login page state transitions and countdown; no automatic replay.
- PoW worker progress/cancel/fallback; step-up and device OTP input/paste/resend; masked email.
- Security tab card: current device, signed-out trust rows, expiry, revoke confirmation, empty state, error states, mobile layout.
- i18n vi/en complete; accessibility checks.

Live DEV/TEST acceptance:

- Real browser: clear cookies → OTP email arrives → verify → trust cookie set → logout → next login skips OTP.
- Five cycles → step-up → correct password → counters reset.
- Revoke current device → session ends; revoke another device → its session ends.
- Shared browser with two users → both keep their own trust.
- Shared NAT: two users on the same public IP do not lock each other.
- Low-end scanner: PoW p95 within budget or flag stays off.
- `php artisan migrate:status`, `config:show login_security`, queue/scheduler health after deployment.

## 16. Performance And Capacity

- Backoff, trust and step-up checks add at most a few Valkey reads and one write per login; target < 5 ms p95.
- No added bcrypt work on blocked requests (pair key is checked first); step-up password attempts add bounded bcrypt work.
- Email OTP is sent only on unknown-device logins, resends or five-cycle step-up. Volume is low on current data (four logins/7 days on prod-blue, 15 live sessions on DEV); a transactional provider free tier is sufficient.
- PoW adds client CPU only on elevated-risk logins; server verification is one SHA-256.
- The trust and installation cookies add a few hundred bytes to auth requests.

## 17. Risks And Mitigations

| Risk | Mitigation |
|---|---|
| Email provider unavailable or lands in spam | Feature flag off; fail closed with 503; transactional provider + SPF/DKIM; pilot; resend cooldown. |
| Users clear cookies / private browsing | Trust is convenience, not a requirement; OTP fallback; support copy. |
| Shared scanners and shift changes | Per-user trust cookies; trust per `(user, installation)`; each user still needs their own password; MFA still applies; trust can be revoked. |
| Shared NAT attacker blocks a known device | Trust cookie bypasses both pair and account waits; five-cycle step-up remains; alerts. |
| Account lockout DoS | 15-minute cap; trust-cookie bypass; five-cycle step-up is self-service; no permanent lock; alerts. |
| PoW too slow on old scanners | Risk-triggered only; measure; lower difficulty; disable flag; fallback. |
| Trust cookie theft | HttpOnly/Secure, hash at rest, rotation, revoke list, 30-day TTL, password change revokes all. |
| Two browser tabs rotate the cookie simultaneously | Rotation grace window; do not rotate on every request. |
| Missing `X-Device-Id` / old browsers | Encrypted installation cookie fallback; no-cookie clients get OTP, not a bypass. |
| Prod-blue is older and lacks tables | Run migrations first; verify schema; deploy latest build; separate release gate. |
| Reverb/scheduler instability distracts from rollout | Treat as separate operational blockers; do not enable Phase 2 until auth logs and queues are healthy. |

## 18. Reviewer Questions Resolved In This Revision

A fresh reviewer read the first draft and the auth code. These are the resolutions, recorded so the builder does not have to guess:

1. **What populates the Trusted devices card?** Trust rows, not only active sessions. Add a separate `GET /auth/trusted-devices`; do not overload `GET /auth/devices`, because a signed-out trust would otherwise disappear and could not be revoked. The card lists trust rows with `sessions` independent of `/auth/devices`.
2. **Can the current device's trust be revoked?** Yes, through the new `DELETE /auth/trusted-devices/{id}`. It revokes sessions for that installation, clears the trust cookie, returns `was_current: true`, and the FE logs out. The existing `/auth/devices/{id}` `CURRENT_DEVICE_PROTECTED` 409 contract is unchanged.
3. **What if `X-Device-Id` is absent?** Add an encrypted, HttpOnly `wms_installation_id` cookie as the durable fallback. If no header and no cookie exist, generate a UUID, create the installation and set the cookie. A client that disables cookies gets OTP every login; it does not bypass verification.
4. **Shared browser and one trust cookie?** Use per-user cookie names (`wms_trusted_device_<email-hash-prefix>`) and per-`(user, installation)` trust rows. User B's login cannot overwrite user A's trust. Test this explicitly on a shared scanner.
5. **Does trust bypass the pair key too?** Yes. A valid trust cookie bypasses both the pair and account pre-checks, because the pair key would otherwise let someone on the same NAT block a known device. The password is still required, and the five-cycle step-up still applies. This trade-off is recorded in §11.
6. **Which actions revoke trust?** Per-device revoke, password change/reset, email change, admin password reset, admin lock/disable, and `POST /auth/sessions/revoke-others` (other devices only; current trust preserved). Each is audited.
7. **Is operator risk marking in scope for PoW?** No. Layer 3 is failure-count-only in this release. An admin counter-clear is an audited artisan command, not a new UI/permission. A manual risk-marking surface is a future extension.

## 19. Open Decisions And Approval Record

User decisions recorded:

- **2026-11-10: all three layers selected.** This approves the direction, not the thresholds, email provider, production activation or implementation.
- **2026-11-11: 15-minute cap, step-up after the wait, five cap cycles, 24-hour counter, trusted-device step-up.** Confirmed by the user.

Decisions approved by the user on 2026-11-11:

1. **Email provider.** Use the existing DEV Gmail for a 5–10 user pilot; before broad rollout, move to a transactional provider with SPF/DKIM. Prod-blue Mailpit must be replaced. The exact provider is an operational follow-up, not a design blocker.
2. **Password change/reset and bulk revoke.** Approved. Password change/reset, email change, admin reset and admin lock revoke all trusts; `revoke-others` revokes other devices' trust and preserves only the current device.
3. **Privileged roles.** Approved. MFA remains mandatory for `SUPER_ADMIN/ADMIN/IAM_ADMIN`; their trust TTL is 7 days instead of 30.
4. **Android/app.** Approved. The body-transport path is exempt from device OTP and PoW in this release; it keeps backoff + MFA.
5. **Rollout scope.** Approved. DEV/TEST first; prod-blue only after migrations, real email, and Reverb/scheduler fixes. Production activation remains a separate approval.

Builder calls recorded for review (already in the design):

- Separate `/auth/trusted-devices` endpoint rather than changing `/auth/devices`.
- Current-device trust revoke allowed with confirmation and immediate logout.
- Per-user trust cookie names and per-`(user, installation)` trust rows for shared scanners.
- Encrypted installation-cookie fallback for missing `X-Device-Id`.
- Trust bypasses both pre-checks, but not the five-cycle step-up.
- Bulk/admin revocations also revoke trust.
- PoW failure-count-only; no operator risk-marking UI; audited artisan counter-clear.
- Step-up allows 3 password attempts per verification and resets only after a correct password.

Approval gates:

- Design approved by the user on 2026-11-11. The implementation plan is the next gate; code does not start until the plan is reviewed.
- A written implementation plan is produced with the `writing-plans` skill and reviewed by the user.
- Security review of the backoff/step-up/OTP/PoW contracts and the account-DoS trade-off.
- Test evidence on DEV/TEST, then a separate release approval for prod-blue.
- No migration, config change, deploy or feature flag activation before those gates.
