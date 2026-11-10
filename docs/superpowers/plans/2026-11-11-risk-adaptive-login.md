# Risk-Adaptive Login Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the fixed 10-failures/60-second login lock with progressive backoff, a five-cycle step-up gate, per-user trusted-device cookies with email verification for new devices, and a risk-triggered client Proof of Work, without breaking MFA, session replacement, the Android body-transport path or shared warehouse scanners.

**Architecture:** A Laravel module service (`LoginThrottle`) owns per-account and per-IP+email state in Valkey. `LoginStepUp` issues a short-lived verification token after five 15-minute cap cycles. `TrustedDeviceService` issues per-user HttpOnly cookies and per-`(user, installation)` trust rows. `DeviceVerification` handles unknown-device OTP. `LoginPow` issues and verifies single-use SHA-256 challenges. React adds discriminated login states and a Security-tab trust card. Every layer has an independent config flag.

**Tech Stack:** PHP 8.3, Laravel 13.30, PostgreSQL 16, Valkey/Redis, PHPUnit 12, Laravel Mail, React 18, TypeScript, Vitest, React Testing Library, Web Worker + WebCrypto (or a compact SHA-256/WASM implementation).

**Spec:** `WMS_Warehouse_Hoa_Nam_BE/docs/superpowers/specs/2026-11-10-risk-adaptive-login-design.md`

## Global Constraints

- Maximum backoff is **900 seconds**. Never grow beyond 15 minutes.
- Five **cap cycles** (not five wrong passwords) trigger `step_up_required`. A cap cycle is one 15-minute wait that then expires and is followed by another wrong password.
- Counter TTL is **86400 seconds** after the last violation. Successful login or successful step-up + correct password clears immediately.
- Free attempts: **3**. Delays: `1,2,4,8,16,30,60,120,300,600,900`.
- Step-up allows **3 password attempts** per verification; a verification allows **5 code attempts**.
- Trust TTL: **30 days** generally, **7 days** for `SUPER_ADMIN/ADMIN/IAM_ADMIN`; maximum **10** active trusts per user.
- A valid trust cookie bypasses both pair and account pre-checks, but **never** the password check or the five-cycle step-up.
- Never issue an access token, refresh token or trust cookie before password + MFA/step-up/device verification is complete.
- Never `sleep()` or `usleep()` in a request. Use `Retry-After` with a 429 response.
- No browser fingerprinting. Device identity is an opaque installation ID plus an HttpOnly cookie; labels are client-declared.
- Do not change the existing `/auth/devices` response or its `CURRENT_DEVICE_PROTECTED` 409 behavior. Trust uses new endpoints.
- Trust/step-up/device/PoW enforcement applies to the **browser cookie transport** only. `X-WMS-Token-Transport: body` (Android/app) keeps password + MFA + backoff and is exempt.
- New feature flags default **off** except `LOGIN_BACKOFF_ENABLED=true` and `LOGIN_STEP_UP_ENABLED=true`.
- Do not log passwords, OTPs, raw trust/installation tokens, token hashes, full emails or raw user agents in new events.
- Every public auth endpoint gets a named rate limiter in `AppServiceProvider`.
- Run the existing auth regressions after each phase: `AuthLoginContractSecurityTest`, `AuthSessionPersistenceTest`, `AuthSessionReplacementTest`, `P10ProfileContractTest`, `ProfileSelfServiceTest`, `WwhnSecurityHardeningTest`; FE: `LoginPage.mfa.test.tsx`, `LoginPage.transport-diagnostic.test.tsx`, `auth.service.test.ts`, `ProfilePage.mfa.test.tsx`.

### Test Fixture Convention

There is no `UserFactory` in this repository. Create users with `User::create([...])` exactly as `tests/Feature/EmployeeFirstLoginPolicyTest.php` does, using `Tests\TestCredentials::PASSWORD`. Add this private helper to each test class that needs a user:

```php
private function makeUser(string $suffix = 'login'): User
{
    return User::create([
        'username' => $suffix.'_'.uniqid(),
        'email' => $suffix.'_'.uniqid().'@example.test',
        'display_name' => 'Login Security Test',
        'password_hash' => Hash::make(TestCredentials::PASSWORD),
        'status' => 'ACTIVE',
        'version' => 1,
        'must_change_password' => false,
    ]);
}
```

All login requests in tests use `'password' => TestCredentials::PASSWORD`.

## Review Focus

1. **Shared browser, two users:** user B must not overwrite or invalidate user A's trust cookie or trust row. Test per-user cookie names and independent logins in Task 10.
2. **Missing `X-Device-Id` or cookies disabled:** a client must never bypass verification by omitting the header. Test installation-cookie fallback and no-cookie OTP every login in Task 8/10.
3. **Shared NAT:** an attacker on the same public IP must not lock a known device; unrelated users on the same IP must not lock each other. Test pair-key isolation and trust bypass in Task 1/4.
4. **MFA + five cycles:** an MFA user must not receive both an MFA challenge and an email OTP for the same login, and a step-up via MFA must satisfy the MFA factor. Test in Task 4/9.
5. **Email failure and slow PoW:** email failure must fail closed with `503`, not issue a session; PoW must not block a normal login and must degrade safely on old browsers. Test in Task 9/16.

---

## Phase A — Backoff, Step-Up And Audit

### Task 1: Login security config and progressive throttle

**Files:**
- Create: `WMS_Warehouse_Hoa_Nam_BE/config/login_security.php`
- Create: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/app/Support/LoginThrottle.php`
- Test: `WMS_Warehouse_Hoa_Nam_BE/tests/Unit/LoginThrottleTest.php`

**Interfaces:**
- `LoginThrottle::state(string $email, string $ip): array{failures:int,cap_hits:int,blocked_until:?int,step_up_required:bool}`
- `LoginThrottle::retryAfter(string $email, string $ip): int`
- `LoginThrottle::registerFailure(string $email, string $ip): array{retry_after:int,cap_hits:int,step_up_required:bool}`
- `LoginThrottle::registerSuccess(string $email): void`
- `LoginThrottle::clear(string $email): void`
- Keys: pair `login-backoff:pair:{sha256(lower(email).'|'.ip)}`; account `login-backoff:account:{sha256(lower(email))}`.
- State stored as a JSON array in Valkey with a 24-hour TTL after the last failure; updates use a short cache lock.

- [ ] **Step 1: Write the failing unit test**

```php
public function test_delays_progress_and_step_up_after_five_cap_cycles(): void
{
    config([
        'login_security.backoff.free_attempts' => 3,
        'login_security.backoff.delays' => [1, 2, 4, 8, 16, 30, 60, 120, 300, 600, 900],
        'login_security.backoff.step_up_after_cycles' => 5,
    ]);
    Cache::flush();
    $throttle = app(LoginThrottle::class);

    foreach ([0, 0, 0, 1, 2, 4, 8, 16, 30, 60, 120, 300, 600, 900] as $index => $expected) {
        $result = $throttle->registerFailure('user@example.test', '10.0.0.5');
        $this->assertSame($expected, $result['retry_after'], "failure #".($index + 1));
        Carbon::setTestNow(now()->addSeconds(max(1, $result['retry_after'])));
    }
    // Four more 15-minute cycles: cap_hits reaches five.
    for ($cycle = 2; $cycle <= 5; $cycle++) {
        Carbon::setTestNow(now()->addSeconds(901));
        $result = $throttle->registerFailure('user@example.test', '10.0.0.5');
        $this->assertSame(900, $result['retry_after'], "cycle #{$cycle}");
    }
    $this->assertTrue($throttle->state('user@example.test', '10.0.0.5')['step_up_required']);
}

public function test_pair_and_account_keys_are_independent_and_success_clears_them(): void
{
    Cache::flush();
    $throttle = app(LoginThrottle::class);
    $throttle->registerFailure('user@example.test', '10.0.0.5');
    $this->assertSame(0, $throttle->retryAfter('user@example.test', '10.0.0.6'));
    $this->assertGreaterThan(0, $throttle->retryAfter('user@example.test', '10.0.0.5'));
    $throttle->registerSuccess('user@example.test');
    $this->assertSame(0, $throttle->retryAfter('user@example.test', '10.0.0.5'));
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Unit/LoginThrottleTest.php`
Expected: FAIL — class/config not found.

- [ ] **Step 3: Implement the config and service**

`config/login_security.php` copies the structure in the spec §10.2. `LoginThrottle` uses `Cache::lock("login-backoff:lock:{$accountKey}", 2)` and reads/writes both keys. Compute `retry_after = max(0, blocked_until - now()->timestamp)`. On a failure, `failures++`; if `failures > free_attempts`, pick `delays[min($failures - free_attempts - 1, count(delays)-1)]`; set `blocked_until = now + delay`; if `delay >= cap_seconds`, `cap_hits++` and set `step_up_required = cap_hits >= step_up_after_cycles`. Store TTL `counter_ttl_seconds`.

- [ ] **Step 4: Run the tests and the config check**

Run: `php artisan test tests/Unit/LoginThrottleTest.php`
Expected: PASS.
Run: `php artisan config:show login_security`
Expected: all keys present with the spec defaults.

- [ ] **Step 5: Commit**

```bash
git add config/login_security.php Modules/Auth/app/Support/LoginThrottle.php tests/Unit/LoginThrottleTest.php
git commit -m "feat(auth): add progressive login throttle"
```

### Task 2: Login OTP table, model and mail

**Files:**
- Create: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/database/migrations/2026_11_11_000100_create_login_device_otps_table.php`
- Create: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/app/Models/LoginDeviceOtp.php`
- Create: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/app/Support/LoginOtpService.php`
- Create: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/app/Mail/LoginOtpMail.php`
- Test: `WMS_Warehouse_Hoa_Nam_BE/tests/Feature/LoginOtpServiceTest.php`

**Interfaces:**
- `LoginOtpService::issue(User $user, string $purpose, string $ip): array{otp_id:string, code:string, expires_in:int}` where purpose is `STEP_UP` or `DEVICE`.
- `LoginOtpService::verify(string $otpId, string $code): bool`
- `LoginOtpService::consume(string $otpId): void`
- `LoginOtpService::invalidate(string $otpId): void`
- `LoginOtpService::purposeOf(string $otpId): ?string`

- [ ] **Step 1: Write the failing test**

```php
public function test_issue_verify_consume_and_attempt_limit(): void
{
    Mail::fake();
    $user = $this->makeUser();
    $issued = app(LoginOtpService::class)->issue($user, 'DEVICE', '10.0.0.5');
    Mail::assertSent(LoginOtpMail::class);

    $this->assertFalse(app(LoginOtpService::class)->verify($issued['otp_id'], '000000'));
    $this->assertTrue(app(LoginOtpService::class)->verify($issued['otp_id'], $issued['code']));
    app(LoginOtpService::class)->consume($issued['otp_id']);
    $this->assertFalse(app(LoginOtpService::class)->verify($issued['otp_id'], $issued['code']));
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/LoginOtpServiceTest.php`
Expected: FAIL — table/model/service missing.

- [ ] **Step 3: Implement migration, model, service and mailable**

Use the migration pattern in `2026_08_27_150000_create_password_reset_otps_table.php`. Columns: `id uuid`, `user_id uuid` (FK `app_users.id` on pgsql, `app_user.user_id` on sqlite), `email varchar(200)`, `purpose varchar(16)`, `otp_hash varchar(255)`, `attempts unsignedTinyInteger default 0`, `expires_at`, `consumed_at nullable`, `ip_address varchar(45) nullable`, timestamps. Index `(user_id, purpose, expires_at)`. Model uses `HasUuids`, casts, and a `user()` relation. Service uses `Hash::make/check`, 6-digit `random_int`, 5-minute TTL, maximum 5 attempts, one active row per purpose (invalidate older rows on issue), and `Mail::to($user->email)->send(new LoginOtpMail($code, $purpose, $ttl))`. The mailable builds inline HTML like `PasswordResetOtpMail`; subject depends on purpose and never includes the code in the subject.

- [ ] **Step 4: Run the test and migration check**

Run: `php artisan test tests/Feature/LoginOtpServiceTest.php`
Expected: PASS.
Run: `php artisan migrate --pretend`
Expected: only the new table, no destructive SQL.

- [ ] **Step 5: Commit**

```bash
git add Modules/Auth/database/migrations/2026_11_11_000100_create_login_device_otps_table.php Modules/Auth/app/Models/LoginDeviceOtp.php Modules/Auth/app/Support/LoginOtpService.php Modules/Auth/app/Mail/LoginOtpMail.php tests/Feature/LoginOtpServiceTest.php
git commit -m "feat(auth): add login OTP storage and mail"
```

### Task 3: Step-up service

**Files:**
- Create: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/app/Support/LoginStepUp.php`
- Test: `WMS_Warehouse_Hoa_Nam_BE/tests/Unit/LoginStepUpTest.php`

**Interfaces:**
- `LoginStepUp::issue(User $user, string $ip): array{token:string,method:string,masked_email:string,expires_in:int}`
- `LoginStepUp::verify(string $token, string $code): array{ok:bool,attempts_left:int,method:string}`
- `LoginStepUp::consumePasswordAttempt(string $token): array{ok:bool,attempts_left:int}`
- `LoginStepUp::clear(string $token): void`
- `LoginStepUp::isVerified(string $token): bool`
- Method is `mfa` when `MfaService::isEnabled($user)`, else `email`. Email uses `LoginOtpService` purpose `STEP_UP`; MFA uses `MfaService::verifySecondFactor`.

- [ ] **Step 1: Write the failing test**

```php
public function test_step_up_uses_mfa_when_enabled_and_email_otherwise(): void
{
    $mfaUser = $this->makeUser();
    UserMfa::query()->create(['user_id' => $mfaUser->user_id, 'secret' => Totp::generateSecret(), 'confirmed_at' => now()]);
    $mfa = app(LoginStepUp::class)->issue($mfaUser, '10.0.0.5');
    $this->assertSame('mfa', $mfa['method']);

    $plain = $this->makeUser();
    Mail::fake();
    $email = app(LoginStepUp::class)->issue($plain, '10.0.0.5');
    $this->assertSame('email', $email['method']);
    Mail::assertSent(LoginOtpMail::class);
}

public function test_password_attempts_are_limited_to_three(): void
{
    $user = $this->makeUser();
    Mail::fake();
    $issued = app(LoginStepUp::class)->issue($user, '10.0.0.5');
    $this->assertSame(3, app(LoginStepUp::class)->consumePasswordAttempt($issued['token'])['attempts_left']);
    $this->assertSame(2, app(LoginStepUp::class)->consumePasswordAttempt($issued['token'])['attempts_left']);
    $this->assertSame(1, app(LoginStepUp::class)->consumePasswordAttempt($issued['token'])['attempts_left']);
    $this->assertFalse(app(LoginStepUp::class)->consumePasswordAttempt($issued['token'])['ok']);
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Unit/LoginStepUpTest.php`
Expected: FAIL — class missing.

- [ ] **Step 3: Implement the service**

Cache payload under `login-step-up:{sha256(token)}` for 300 seconds: `user_id`, `method`, `otp_id`, `ip`, `code_attempts`, `password_attempts=3`, `verified=false`, `password_verified=false`. `verify()` checks the code; on success sets `verified=true` and returns `attempts_left`. `consumePasswordAttempt()` only works when `verified=true`, decrements, and returns `ok=false` at zero. Use the configured values; never accept a client-supplied user id or method.

- [ ] **Step 4: Run the test**

Run: `php artisan test tests/Unit/LoginStepUpTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add Modules/Auth/app/Support/LoginStepUp.php tests/Unit/LoginStepUpTest.php
git commit -m "feat(auth): add five-cycle step-up service"
```

### Task 4: Integrate backoff and step-up into login

**Files:**
- Modify: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/app/Http/Requests/LoginRequest.php`
- Modify: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/app/Http/Controllers/AuthController.php:60-245`
- Test: `WMS_Warehouse_Hoa_Nam_BE/tests/Feature/AuthLoginBackoffTest.php`

**Interfaces:**
- `LoginRequest` accepts `email`, `password`, `step_up_token` (nullable string, max 128).
- `LoginThrottle` from Task 1; `LoginStepUp` from Task 3.
- Login response may include `step_up_required`, `step_up_token`, `method`, `masked_email`, `expires_in`; never tokens in that branch.

- [ ] **Step 1: Write the failing feature test**

```php
public function test_five_cap_cycles_require_step_up_before_tokens(): void
{
    config([
        'login_security.backoff.free_attempts' => 0,
        'login_security.backoff.delays' => [900],
        'login_security.backoff.cap_seconds' => 900,
        'login_security.backoff.step_up_after_cycles' => 1,
        'login_security.backoff.counter_ttl_seconds' => 86400,
    ]);
    Cache::flush();
    $user = $this->makeUser();

    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertStatus(429);
    Carbon::setTestNow(now()->addSeconds(901));

    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertOk()
        ->assertJsonPath('data.step_up_required', true)
        ->assertJsonMissingPath('data.access_token');
}

public function test_successful_login_clears_the_throttle(): void
{
    config(['login_security.backoff.free_attempts' => 0, 'login_security.backoff.delays' => [1]]);
    Cache::flush();
    $user = $this->makeUser();

    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertStatus(429);
    Carbon::setTestNow(now()->addSeconds(2));

    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => TestCredentials::PASSWORD])
        ->assertOk();
    $this->assertSame(0, app(LoginThrottle::class)->retryAfter($user->email, '127.0.0.1'));
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/AuthLoginBackoffTest.php`
Expected: FAIL — 429/step-up fields missing.

- [ ] **Step 3: Implement the login order**

Follow spec §5/§6.5: pair pre-check → account pre-check → step-up gate → password → failure counters/429 → success clear. Gate the block with `login_security.backoff.enabled` and the step-up gate with `login_security.step_up.enabled`. For the step-up gate, call `LoginStepUp::issue()` and return 200 with the step-up payload; do not call `auth()->attempt`. When `step_up_token` is present, call `LoginStepUp::consumePasswordAttempt()` before `auth()->attempt`; if `ok=false`, return `422 STEP_UP_EXPIRED`. On success, `LoginStepUp::clear()`, `LoginThrottle::registerSuccess()`, then continue with MFA/device/session completion. When `step_up_required` first becomes true, emit `SecurityAlertRaised('LOGIN_STEP_UP_REQUIRED', $user->user_id, '...')` and audit `LOGIN_STEP_UP_REQUIRED`; do not emit it on every request. Keep the existing case-sensitive email check and inactive-account 403.

- [ ] **Step 4: Run the feature test and existing regressions**

Run: `php artisan test tests/Feature/AuthLoginBackoffTest.php tests/Feature/AuthLoginContractSecurityTest.php tests/Feature/AuthSessionPersistenceTest.php tests/Feature/AuthSessionReplacementTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add Modules/Auth/app/Http/Requests/LoginRequest.php Modules/Auth/app/Http/Controllers/AuthController.php tests/Feature/AuthLoginBackoffTest.php
git commit -m "feat(auth): gate login with progressive backoff and step-up"
```

### Task 5: Step-up endpoints, routes and rate limiters

**Files:**
- Create: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/app/Http/Controllers/LoginStepUpController.php`
- Modify: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/routes/api.php:20-40`
- Modify: `WMS_Warehouse_Hoa_Nam_BE/app/Providers/AppServiceProvider.php:193-232`
- Test: `WMS_Warehouse_Hoa_Nam_BE/tests/Feature/AuthStepUpEndpointTest.php`

**Interfaces:**
- `POST /api/v1/auth/step-up/verify` `{step_up_token, code}` → 200 `{step_up_verified:true, password_attempts_left:3, expires_in:300}`; 422 on invalid; 423 after five wrong codes.
- `POST /api/v1/auth/step-up/resend` `{step_up_token}` → 200; 429 during the 60-second cooldown; maximum 5 per hour.
- Limiters: `auth-step-up-verify` by IP + token hash; `auth-step-up-resend` by IP + token hash.

- [ ] **Step 1: Write the failing feature test**

```php
public function test_step_up_verify_then_login_issues_tokens_and_clears_state(): void
{
    config([
        'login_security.backoff.free_attempts' => 0,
        'login_security.backoff.delays' => [900],
        'login_security.backoff.cap_seconds' => 900,
        'login_security.backoff.step_up_after_cycles' => 1,
    ]);
    Cache::flush();
    Mail::fake();
    $user = $this->makeUser();

    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertStatus(429);
    Carbon::setTestNow(now()->addSeconds(901));
    $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertOk()->assertJsonPath('data.step_up_required', true)->json('data.step_up_token');
    $code = Mail::sent(LoginOtpMail::class)->first()->otp;

    $this->postJson('/api/v1/auth/step-up/verify', ['step_up_token' => $token, 'code' => $code])
        ->assertOk()
        ->assertJsonPath('data.step_up_verified', true)
        ->assertJsonPath('data.password_attempts_left', 3)
        ->assertJsonMissingPath('data.access_token');

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email, 'password' => TestCredentials::PASSWORD, 'step_up_token' => $token,
    ])->assertOk()->assertJsonPath('data.access_token', fn ($v) => is_string($v) && $v !== '');
    $this->assertSame(0, app(LoginThrottle::class)->retryAfter($user->email, '127.0.0.1'));
}

public function test_five_wrong_step_up_codes_lock_the_challenge(): void
{
    $user = $this->makeUser();
    $token = $this->startStepUp($user);
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/step-up/verify', ['step_up_token' => $token, 'code' => '000000'])
            ->assertStatus($i === 4 ? 423 : 422);
    }
}

private function startStepUp(User $user): string
{
    config([
        'login_security.backoff.free_attempts' => 0,
        'login_security.backoff.delays' => [900],
        'login_security.backoff.cap_seconds' => 900,
        'login_security.backoff.step_up_after_cycles' => 1,
    ]);
    Cache::flush();
    Mail::fake();
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertStatus(429);
    Carbon::setTestNow(now()->addSeconds(901));

    return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertOk()->json('data.step_up_token');
}

public function test_resend_is_rate_limited(): void
{
    $this->postJson('/api/v1/auth/step-up/resend', ['step_up_token' => $token])->assertOk();
    $this->postJson('/api/v1/auth/step-up/resend', ['step_up_token' => $token])->assertStatus(429);
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/AuthStepUpEndpointTest.php`
Expected: FAIL — routes missing.

- [ ] **Step 3: Implement controller, routes and limiters**

Controller validates the body, calls `LoginStepUp`; returns `RateLimitResponse::make` for throttles. Add routes next to `auth/mfa/verify`; do not attach `auth:api` (the user is not authenticated yet). Add limiter closures using `config('rate_limit.auth_mfa_verify_per_minute')` or a dedicated `login_security.step_up` value; use `->response(...)` with a Vietnamese message. Never put the OTP or token in logs.

- [ ] **Step 4: Run the test and route list**

Run: `php artisan test tests/Feature/AuthStepUpEndpointTest.php`
Expected: PASS.
Run: `php artisan route:list --path=auth/step-up`
Expected: both routes present.

- [ ] **Step 5: Commit**

```bash
git add Modules/Auth/app/Http/Controllers/LoginStepUpController.php Modules/Auth/routes/api.php app/Providers/AppServiceProvider.php tests/Feature/AuthStepUpEndpointTest.php
git commit -m "feat(auth): add step-up verification endpoints"
```

### Task 6: Audit events and audited counter-clear command

**Files:**
- Create: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/app/Console/Commands/ClearLoginThrottle.php`
- Modify: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/app/Http/Controllers/AuthController.php` (audit `LOGIN_THROTTLED`, `LOGIN_STEP_UP_REQUIRED`, `LOGIN_STEP_UP_COMPLETED`, `LOGIN_STEP_UP_FAILED`)
- Modify: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/app/Support/LoginStepUp.php` or controller for audit
- Test: `WMS_Warehouse_Hoa_Nam_BE/tests/Feature/LoginSecurityAuditTest.php`

**Interfaces:**
- Command `auth:clear-login-throttle --email=...` clears both keys, writes `LOGIN_THROTTLE_CLEARED` with the operator's identity (CLI user) and no email in the payload (hash only).
- Audit actions as in spec §12.

- [ ] **Step 1: Write the failing test**

```php
public function test_clear_command_removes_state_and_audits_without_email(): void
{
    Cache::flush();
    app(LoginThrottle::class)->registerFailure('user@example.test', '10.0.0.5');
    $this->artisan('auth:clear-login-throttle', ['--email' => 'user@example.test'])->assertSuccessful();
    $this->assertSame(0, app(LoginThrottle::class)->retryAfter('user@example.test', '10.0.0.5'));
    $this->assertDatabaseHas('audit_logs', ['action' => 'LOGIN_THROTTLE_CLEARED']);
    $this->assertStringNotContainsString('user@example.test', json_encode(DB::table('audit_logs')->latest('id')->first()));
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/LoginSecurityAuditTest.php`
Expected: FAIL — command missing.

- [ ] **Step 3: Implement the command and audit calls**

Use `AuditLogger::record()` with `objectType='app_user'`, `objectId` from the user lookup, `actorId='cli'` or the current OS user, `afterData=['action'=>'CLEAR_LOGIN_THROTTLE','email_hash'=>...]`. Register the command in the module's console discovery (existing commands are auto-loaded). Add the audit calls at the points named above, with `result` and `errorCode` matching the existing conventions.

- [ ] **Step 4: Run the test and `artisan list`**

Run: `php artisan test tests/Feature/LoginSecurityAuditTest.php`
Expected: PASS.
Run: `php artisan list auth`
Expected: `auth:clear-login-throttle` present.

- [ ] **Step 5: Commit**

```bash
git add Modules/Auth/app/Console/Commands/ClearLoginThrottle.php Modules/Auth/app/Http/Controllers/AuthController.php Modules/Auth/app/Support/LoginStepUp.php tests/Feature/LoginSecurityAuditTest.php
git commit -m "feat(auth): audit login security events and add counter clear command"
```

---

## Phase B — Trusted Devices

### Task 7: Trusted-device table, model and service

**Files:**
- Create: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/database/migrations/2026_11_11_000200_create_trusted_devices_table.php`
- Create: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/app/Models/TrustedDevice.php`
- Create: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/app/Support/TrustedDeviceService.php`
- Test: `WMS_Warehouse_Hoa_Nam_BE/tests/Unit/TrustedDeviceServiceTest.php`

**Interfaces:**
- `TrustedDeviceService::cookieName(string $email): string` → `wms_trusted_device_` + `substr(hash('sha256', strtolower($email)), 0, 16)`.
- `TrustedDeviceService::findValid(string $email, ?string $rawToken): ?TrustedDevice`
- `TrustedDeviceService::trust(User $user, string $installationId, Request $request): array{model:TrustedDevice, raw_token:string, cookie:Cookie}`
- `TrustedDeviceService::rotateIfNeeded(TrustedDevice $trust): ?string`
- `TrustedDeviceService::revoke(TrustedDevice $trust, string $reason): void`
- `TrustedDeviceService::revokeAllForUser(string $userId, ?string $exceptInstallationId = null): int`
- `TrustedDeviceService::expiredCookie(string $email): Cookie`
- `TrustedDeviceService::ttlDaysFor(User $user): int` → 7 for privileged roles (`MfaService::isPrivileged`), otherwise 30.

- [ ] **Step 1: Write the failing test**

```php
public function test_trust_token_is_hashed_expires_and_is_per_user(): void
{
    $user = $this->makeUser();
    $installationId = (string) Str::uuid();
    DB::table('auth_client_installations')->insert([
        'id' => $installationId, 'user_id' => $user->user_id,
        'client_device_id' => (string) Str::uuid(), 'device_label' => 'Test browser',
        'platform' => 'Windows', 'client_type' => 'web',
        'first_seen_at' => now('UTC'), 'last_seen_at' => now('UTC'),
    ]);
    $issued = app(TrustedDeviceService::class)->trust($user, $installationId, Request::create('/'));
    $this->assertNotSame($issued['raw_token'], $issued['model']->token_hash);
    $this->assertSame(hash('sha256', $issued['raw_token']), $issued['model']->token_hash);
    $this->assertNotSame(
        app(TrustedDeviceService::class)->cookieName('a@example.test'),
        app(TrustedDeviceService::class)->cookieName('b@example.test'),
    );
    Carbon::setTestNow(now()->addDays(31));
    $this->assertNull(app(TrustedDeviceService::class)->findValid($user->email, $issued['raw_token']));
}

public function test_privileged_accounts_get_a_seven_day_trust_ttl(): void
{
    $user = $this->makeUser();
    $this->mock(MfaService::class, fn ($mock) => $mock->shouldReceive('isPrivileged')->with($user)->andReturn(true));
    $this->assertSame(7, app(TrustedDeviceService::class)->ttlDaysFor($user));
}

public function test_trust_cookie_is_http_only_secure_and_rotates_with_grace(): void
{
    $user = $this->makeUser();
    $installationId = (string) Str::uuid();
    DB::table('auth_client_installations')->insert([
        'id' => $installationId, 'user_id' => $user->user_id,
        'client_device_id' => (string) Str::uuid(), 'device_label' => 'Test browser',
        'platform' => 'Windows', 'client_type' => 'web',
        'first_seen_at' => now('UTC'), 'last_seen_at' => now('UTC'),
    ]);
    $issued = app(TrustedDeviceService::class)->trust($user, $installationId, Request::create('/'));
    $this->assertTrue($issued['cookie']->isHttpOnly());
    $this->assertTrue($issued['cookie']->isSecure());
    $this->assertSame('lax', $issued['cookie']->getSameSite());

    Carbon::setTestNow(now()->addDays(8));
    $rotated = app(TrustedDeviceService::class)->rotateIfNeeded($issued['model']);
    $this->assertNotNull($rotated);
    $this->assertNotNull($issued['model']->fresh()->previous_hash);
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Unit/TrustedDeviceServiceTest.php`
Expected: FAIL — table/model missing.

- [ ] **Step 3: Implement migration, model and service**

Create the table exactly as spec §7.1, with partial unique indexes (`WHERE revoked_at IS NULL`). Model uses `HasUuids` and belongsTo `User`. Service reads `login_security.trusted_device`; sets `HttpOnly`, `Secure`, `SameSite`, `Path`, `Max-Age`; stores only SHA-256; enforces max 10 by revoking the oldest expired/least-recently-used; rotation after 7 days keeps `previous_hash` valid for the configured grace; `revoke()` sets `revoked_at`; `revokeAllForUser()` updates all non-revoked rows except the given installation. Use `DB::transaction` for multi-row changes.

- [ ] **Step 4: Run the test and migration check**

Run: `php artisan test tests/Unit/TrustedDeviceServiceTest.php`
Expected: PASS.
Run: `php artisan migrate --pretend`
Expected: additive table only.

- [ ] **Step 5: Commit**

```bash
git add Modules/Auth/database/migrations/2026_11_11_000200_create_trusted_devices_table.php Modules/Auth/app/Models/TrustedDevice.php Modules/Auth/app/Support/TrustedDeviceService.php tests/Unit/TrustedDeviceServiceTest.php
git commit -m "feat(auth): add trusted devices and per-user cookies"
```

### Task 8: Installation identity and encrypted fallback cookie

**Files:**
- Create: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/app/Support/InstallationIdentity.php`
- Create: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/app/Http/Middleware/PersistInstallationCookie.php`
- Modify: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/routes/api.php` (login and device routes)
- Test: `WMS_Warehouse_Hoa_Nam_BE/tests/Feature/InstallationIdentityTest.php`

**Interfaces:**
- `InstallationIdentity::resolve(Request $request, User $user): array{installation_id:string, cookie:?Cookie}`
- Header `X-Device-Id` wins only when no valid installation cookie exists; the cookie is encrypted with `Crypt::encryptString($uuid)`; cookie name `wms_installation_id`, HttpOnly, Secure per config, Path `/api/v1/auth`, one year.
- The middleware reads `$request->attributes->get('wms.installation_cookie')` and attaches it to the response.

- [ ] **Step 1: Write the failing feature test**

```php
public function test_missing_device_header_gets_a_server_issued_installation_cookie(): void
{
    $user = $this->makeUser();
    $response = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => TestCredentials::PASSWORD]);
    $response->assertOk();
    $response->assertCookie('wms_installation_id');
    $this->assertDatabaseCount('auth_client_installations', 1);
}

public function test_same_installation_cookie_is_reused_on_the_next_login(): void
{
    $user = $this->makeUser();
    $first = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => TestCredentials::PASSWORD]);
    $first->assertOk();
    $cookie = $first->getCookie('wms_installation_id');
    $firstId = DB::table('auth_client_installations')->where('user_id', $user->user_id)->value('id');

    $this->withCookie($cookie->getName(), $cookie->getValue())
        ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => TestCredentials::PASSWORD])
        ->assertOk();

    $this->assertSame(1, DB::table('auth_client_installations')->where('user_id', $user->user_id)->count());
    $this->assertSame($firstId, DB::table('auth_client_installations')->where('user_id', $user->user_id)->value('id'));
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/InstallationIdentityTest.php`
Expected: FAIL — cookie missing.

- [ ] **Step 3: Implement resolver and middleware**

Resolve in this order: valid `X-Device-Id` when no installation cookie; decrypted installation cookie; otherwise new UUID. For pgsql, the user key is `app_users.id`; for sqlite, `app_user.user_id` (copy the pattern in `2026_11_04_190000...`). Update `last_seen_at`, label/platform/client type from headers when present. Never let a cookie belonging to another user become a trust row: the installation row is per user, so a cookie UUID for user A is re-created for user B and does not carry trust.

- [ ] **Step 4: Run the test and the login regression**

Run: `php artisan test tests/Feature/InstallationIdentityTest.php tests/Feature/AuthLoginContractSecurityTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add Modules/Auth/app/Support/InstallationIdentity.php Modules/Auth/app/Http/Middleware/PersistInstallationCookie.php Modules/Auth/routes/api.php tests/Feature/InstallationIdentityTest.php
git commit -m "feat(auth): add durable installation identity fallback"
```

### Task 9: Device verification service

**Files:**
- Create: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/app/Support/DeviceVerification.php`
- Test: `WMS_Warehouse_Hoa_Nam_BE/tests/Unit/DeviceVerificationTest.php`

**Interfaces:**
- `DeviceVerification::issue(User $user, string $installationId, string $ip): array{device_token:string,masked_email:string,expires_in:int}`
- `DeviceVerification::verify(string $deviceToken, string $code): array{ok:bool,user:?User,installation_id:?string,attempts_left:int}`
- `DeviceVerification::resend(string $deviceToken): array{ok:bool,expires_in:int}`
- Cache payload: `{user_id, installation_id, otp_id, ip, expires_at}`; 5-minute TTL; OTP purpose `DEVICE`; resend 60s/5-per-hour.

- [ ] **Step 1: Write the failing test**

```php
public function test_device_challenge_verifies_email_otp_and_returns_installation(): void
{
    Mail::fake();
    $user = $this->makeUser();
    $installationId = (string) Str::uuid();
    DB::table('auth_client_installations')->insert([
        'id' => $installationId, 'user_id' => $user->user_id,
        'client_device_id' => (string) Str::uuid(), 'device_label' => 'Test browser',
        'platform' => 'Windows', 'client_type' => 'web',
        'first_seen_at' => now('UTC'), 'last_seen_at' => now('UTC'),
    ]);
    $issued = app(DeviceVerification::class)->issue($user, $installationId, '10.0.0.5');
    $this->assertStringContainsString('***', $issued['masked_email']);
    $code = Mail::sent(LoginOtpMail::class)->first()->otp;
    $result = app(DeviceVerification::class)->verify($issued['device_token'], $code);
    $this->assertTrue($result['ok']);
    $this->assertSame($installationId, $result['installation_id']);
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Unit/DeviceVerificationTest.php`
Expected: FAIL — class missing.

- [ ] **Step 3: Implement the service**

Use cache-only challenge token and `LoginOtpService` for the email code. Mask the email as `first-character + "***@" + domain`. `verify()` checks the token, the OTP, and the attempt limit; on success returns the user and installation; do not issue tokens. `resend()` checks the cooldown and invalidates the previous OTP.

- [ ] **Step 4: Run the test**

Run: `php artisan test tests/Unit/DeviceVerificationTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add Modules/Auth/app/Support/DeviceVerification.php tests/Unit/DeviceVerificationTest.php
git commit -m "feat(auth): add unknown-device verification service"
```

### Task 10: Login trust integration and device endpoints

**Files:**
- Modify: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/app/Http/Controllers/AuthController.php:60-245`
- Modify: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/app/Http/Controllers/MfaController.php:209-304` (trust after MFA success)
- Create: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/app/Http/Controllers/DeviceVerificationController.php`
- Modify: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/routes/api.php`
- Modify: `WMS_Warehouse_Hoa_Nam_BE/app/Providers/AppServiceProvider.php` (`auth-device-verify`, `auth-device-resend`)
- Test: `WMS_Warehouse_Hoa_Nam_BE/tests/Feature/TrustedDeviceLoginTest.php`

**Interfaces:**
- Login with a valid trust cookie skips the pair/account pre-checks and, when MFA is off, issues tokens directly.
- Login without trust and MFA off returns `device_verification_required` + `device_token`; no tokens.
- Login with MFA on always returns the MFA challenge, never a device OTP; MFA success trusts the installation.
- `POST /auth/device/verify` verifies the OTP, calls `TrustedDeviceService::trust()`, completes login via `RefreshTokenService::completeLogin()`, sets the trust cookie, audits `DEVICE_TRUSTED`.
- `POST /auth/device/resend` respects the cooldown.

- [ ] **Step 1: Write the failing feature test**

```php
public function test_unknown_device_requires_email_otp_and_trusted_device_skips_it(): void
{
    Mail::fake();
    $user = $this->makeUser();
    $first = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => TestCredentials::PASSWORD]);
    $first->assertOk()->assertJsonPath('data.device_verification_required', true)->assertJsonMissingPath('data.access_token');
    $token = $first->json('data.device_token');
    $code = Mail::sent(LoginOtpMail::class)->first()->otp;

    $verified = $this->postJson('/api/v1/auth/device/verify', ['device_token' => $token, 'code' => $code]);
    $verified->assertOk()->assertJsonPath('data.access_token', fn ($v) => is_string($v) && $v !== '');
    $cookieName = app(TrustedDeviceService::class)->cookieName($user->email);
    $trustCookie = $verified->getCookie($cookieName);

    $second = $this->withCookie($trustCookie->getName(), $trustCookie->getValue())
        ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => TestCredentials::PASSWORD]);
    $second->assertOk()->assertJsonMissingPath('data.device_verification_required');
}

public function test_trusted_device_bypasses_pair_and_account_backoff(): void
{
    config(['login_security.backoff.free_attempts' => 0, 'login_security.backoff.delays' => [60]]);
    Cache::flush();
    $user = $this->makeUser();
    $installationId = (string) Str::uuid();
    DB::table('auth_client_installations')->insert([
        'id' => $installationId, 'user_id' => $user->user_id,
        'client_device_id' => (string) Str::uuid(), 'device_label' => 'Test browser',
        'platform' => 'Windows', 'client_type' => 'web',
        'first_seen_at' => now('UTC'), 'last_seen_at' => now('UTC'),
    ]);
    $trust = app(TrustedDeviceService::class)->trust($user, $installationId, Request::create('/'));
    app(LoginThrottle::class)->registerFailure($user->email, '127.0.0.1');

    $this->withCookie($trust['cookie']->getName(), $trust['cookie']->getValue())
        ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => TestCredentials::PASSWORD])
        ->assertOk()
        ->assertJsonPath('data.access_token', fn ($v) => is_string($v) && $v !== '');
}

public function test_mfa_user_gets_mfa_challenge_not_device_otp(): void
{
    Mail::fake();
    $user = $this->makeUser();
    UserMfa::query()->create([
        'user_id' => $user->user_id, 'secret' => 'JBSWY3DPEHPK3PXP', 'confirmed_at' => now(),
    ]);

    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => TestCredentials::PASSWORD])
        ->assertOk()
        ->assertJsonPath('data.mfa_required', true)
        ->assertJsonMissingPath('data.device_verification_required');
    Mail::assertNothingSent();
}

public function test_two_users_on_the_same_browser_use_different_cookie_names(): void
{
    Mail::fake();
    $a = $this->makeUser('a');
    $b = $this->makeUser('b');
    $service = app(TrustedDeviceService::class);
    $this->assertNotSame($service->cookieName($a->email), $service->cookieName($b->email));

    // Trust A only, then present A's cookie with B's email: B must still be asked for OTP.
    $installationId = (string) Str::uuid();
    DB::table('auth_client_installations')->insert([
        'id' => $installationId, 'user_id' => $a->user_id,
        'client_device_id' => (string) Str::uuid(), 'device_label' => 'Shared scanner',
        'platform' => 'Windows', 'client_type' => 'web',
        'first_seen_at' => now('UTC'), 'last_seen_at' => now('UTC'),
    ]);
    $trust = $service->trust($a, $installationId, Request::create('/'));
    $this->withCookie($trust['cookie']->getName(), $trust['cookie']->getValue())
        ->postJson('/api/v1/auth/login', ['email' => $b->email, 'password' => TestCredentials::PASSWORD])
        ->assertOk()
        ->assertJsonPath('data.device_verification_required', true);
}

public function test_email_delivery_failure_fails_closed_without_tokens(): void
{
    $user = $this->makeUser();
    Mail::shouldReceive('to->send')->andThrow(new RuntimeException('smtp down'));

    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => TestCredentials::PASSWORD])
        ->assertStatus(503)
        ->assertJsonPath('error_code', 'EMAIL_DELIVERY_UNAVAILABLE');
    $this->assertDatabaseMissing('refresh_tokens', ['user_id' => $user->user_id]);
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/TrustedDeviceLoginTest.php`
Expected: FAIL — device path missing.

- [ ] **Step 3: Implement login trust resolution and device endpoints**

In `AuthController::login`: resolve installation, resolve the trust cookie for the submitted email, pass `trusted` to the pre-check, and after MFA/password choose the branch in spec §5. When the login used a `step_up_token` whose method was `email` or `mfa`, mark that installation trusted before completing login, so the user does not receive a second OTP; attach the trust cookie to the response. If the step-up method was `mfa`, treat the MFA factor as already satisfied for this login and do not issue a second MFA challenge. In `MfaController::verify`: after MFA success, resolve the installation, call `TrustedDeviceService::trust()` when trust is enabled, attach the trust cookie, and audit `DEVICE_TRUSTED`; do not issue a device OTP. `DeviceVerificationController` uses the service, `TrustedDeviceService`, `RefreshTokenService`, `RefreshTokenTransport` and `AuditLogger`. Attach `PersistInstallationCookie` and `PersistRefreshTokenCookie` to the MFA verify and device verify routes. Ensure the device route does not accept `auth:api` and is rate-limited. If `Mail::send` throws, return `503 EMAIL_DELIVERY_UNAVAILABLE`; never issue tokens.

- [ ] **Step 4: Run the feature test and the MFA regression**

Run: `php artisan test tests/Feature/TrustedDeviceLoginTest.php tests/Feature/AuthSessionReplacementTest.php tests/Feature/AuthLoginContractSecurityTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add Modules/Auth/app/Http/Controllers/AuthController.php Modules/Auth/app/Http/Controllers/DeviceVerificationController.php Modules/Auth/routes/api.php app/Providers/AppServiceProvider.php tests/Feature/TrustedDeviceLoginTest.php
git commit -m "feat(auth): require email verification for unknown devices"
```

### Task 11: Trusted-device management and revocation propagation

**Files:**
- Create: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/app/Http/Controllers/TrustedDeviceController.php`
- Modify: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/app/Http/Controllers/P10ProfileController.php:239-297` (revoke-others)
- Modify: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/app/Http/Controllers/P10ProfileController.php:81-130` (email change)
- Modify: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/app/Http/Controllers/UserController.php:230-271` (admin reset/lock)
- Modify: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/app/Http/Controllers/AuthController.php` (changePassword)
- Modify: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/app/Http/Controllers/PasswordResetController.php` (resetPassword)
- Modify: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/routes/api.php`
- Test: `WMS_Warehouse_Hoa_Nam_BE/tests/Feature/TrustedDeviceManagementTest.php`

**Interfaces:**
- `GET /auth/trusted-devices` → `data[]` active trust rows: `id`, `device_label`, `platform`, `client_type`, `is_current`, `trusted_at`, `last_used_at`, `expires_at`, `can_revoke`.
- `DELETE /auth/trusted-devices/{id}` → revoke trust and installation sessions; if current, `was_current: true` and clear the cookie.
- `POST /auth/sessions/revoke-others` adds `trust_revoked_count`.
- Admin password reset/lock, email change, password change and password reset revoke all trusts.

- [ ] **Step 1: Write the failing feature test**

```php
public function test_trusted_device_list_includes_signed_out_trust(): void
{
    $user = $this->makeUser();
    $service = app(TrustedDeviceService::class);
    foreach (['Current browser', 'Signed-out browser'] as $label) {
        $installationId = (string) Str::uuid();
        DB::table('auth_client_installations')->insert([
            'id' => $installationId, 'user_id' => $user->user_id,
            'client_device_id' => (string) Str::uuid(), 'device_label' => $label,
            'platform' => 'Windows', 'client_type' => 'web',
            'first_seen_at' => now('UTC'), 'last_seen_at' => now('UTC'),
        ]);
        $service->trust($user, $installationId, Request::create('/'));
    }
    $token = auth('api')->login($user);
    $list = $this->getJson('/api/v1/auth/trusted-devices', ['Authorization' => "Bearer {$token}"]);
    $list->assertOk()->assertJsonCount(2, 'data');

    $target = $list->json('data.0.id');
    $this->deleteJson('/api/v1/auth/trusted-devices/'.$target, [], ['Authorization' => "Bearer {$token}"])
        ->assertOk()
        ->assertJsonPath('data.revoked', true);
}

public function test_password_change_revokes_all_trusts(): void
{
    $user = $this->makeUser();
    $installationId = (string) Str::uuid();
    DB::table('auth_client_installations')->insert([
        'id' => $installationId, 'user_id' => $user->user_id,
        'client_device_id' => (string) Str::uuid(), 'device_label' => 'Test browser',
        'platform' => 'Windows', 'client_type' => 'web',
        'first_seen_at' => now('UTC'), 'last_seen_at' => now('UTC'),
    ]);
    $trust = app(TrustedDeviceService::class)->trust($user, $installationId, Request::create('/'));
    $token = auth('api')->login($user);

    $this->postJson('/api/v1/auth/change-password', [
        'current_password' => TestCredentials::PASSWORD,
        'new_password' => 'NewPassword#2026',
        'new_password_confirmation' => 'NewPassword#2026',
    ], ['Authorization' => "Bearer {$token}"])->assertOk();

    $this->assertNotNull($trust['model']->fresh()->revoked_at);
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/TrustedDeviceManagementTest.php`
Expected: FAIL — routes missing.

- [ ] **Step 3: Implement controller and revocations**

Use a DB transaction. Resolve the current installation/family; list trusts joined to installations; `DELETE` revokes `TrustedDeviceService::revoke()` and all active families for that installation, then clears the per-user cookie if current. For bulk revoke, call `revokeAllForUser($userId, $currentInstallationId)`. For admin/email change, call `revokeAllForUser($userId)`. Audit `DEVICE_TRUST_REVOKED`, `REVOKE_OTHER_SESSIONS` with `trust_revoked_count`, `ADMIN_RESET`/`ADMIN_LOCK` as appropriate.

- [ ] **Step 4: Run the test and the existing profile regressions**

Run: `php artisan test tests/Feature/TrustedDeviceManagementTest.php tests/Feature/P10ProfileContractTest.php tests/Feature/ProfileSelfServiceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add Modules/Auth/app/Http/Controllers/TrustedDeviceController.php Modules/Auth/app/Http/Controllers/P10ProfileController.php Modules/Auth/app/Http/Controllers/UserController.php Modules/Auth/routes/api.php tests/Feature/TrustedDeviceManagementTest.php
git commit -m "feat(auth): expose and revoke trusted devices"
```

---

## Phase C — Frontend

### Task 12: FE auth types, services, endpoints and feedback classifier

**Files:**
- Modify: `WMS_Warehouse_Hoa_Nam_FE/src/shared/api/endpoints.ts`
- Modify: `WMS_Warehouse_Hoa_Nam_FE/src/modules/auth/types/auth.types.ts`
- Modify: `WMS_Warehouse_Hoa_Nam_FE/src/modules/auth/services/auth.service.ts`
- Modify: `WMS_Warehouse_Hoa_Nam_FE/src/modules/auth/utils/login-feedback.ts`
- Modify: `WMS_Warehouse_Hoa_Nam_FE/src/modules/auth/components/AuthLoginFeedback.tsx`
- Test: `WMS_Warehouse_Hoa_Nam_FE/src/modules/auth/services/auth.service.test.ts` (add cases)
- Test: `WMS_Warehouse_Hoa_Nam_FE/src/modules/auth/utils/login-feedback.test.ts` (create if absent)

**Interfaces:**
- `LoginRequest` adds optional `step_up_token`, `pow`, and `pow_supported`.
- `LoginResult = AuthenticatedLogin | MfaRequiredLogin | StepUpRequired | DeviceVerificationRequired`.
- `StepUpRequired = {kind:"step_up_required"; step_up_required:true; step_up_token:string; method:"mfa"|"email"; masked_email:string; expires_in:number}`.
- `DeviceVerificationRequired = {kind:"device_verification_required"; device_verification_required:true; device_token:string; masked_email:string; expires_in:number}`.
- `authService.verifyStepUp(token, code)`, `resendStepUp(token)`, `verifyDevice(token, code)`, `resendDevice(token)`, `powChallenge(email)`.
- `LoginFeedbackKind` adds `step-up-required`, `device-verification-required`, `pow-required`; `classifyLoginFailure` maps 428 to `pow-required` and 200 challenge payloads to the respective kinds.
- Endpoints: `STEP_UP_VERIFY`, `STEP_UP_RESEND`, `DEVICE_VERIFY`, `DEVICE_RESEND`, `POW_CHALLENGE`, `TRUSTED_DEVICES`.

- [ ] **Step 1: Write the failing tests**

```ts
it("maps a 428 response to pow-required, not service-unavailable", () => {
  const feedback = classifyLoginFailure({
    response: { status: 428, data: { error_code: "POW_REQUIRED" }, headers: {} },
  });
  expect(feedback.kind).toBe("pow-required");
});

it("keeps step-up and device challenges out of the authenticated result", async () => {
  vi.mocked(http.post).mockResolvedValueOnce({
    step_up_required: true, step_up_token: "s", method: "email",
    masked_email: "a***@b.test", expires_in: 300,
  });
  const result = await authService.login({ email: "a@b.test", password: "x" });
  expect(result.kind).toBe("step_up_required");
  expect("access_token" in result).toBe(false);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npx vitest run src/modules/auth/services/auth.service.test.ts src/modules/auth/utils/login-feedback.test.ts`
Expected: FAIL — new kinds/endpoints missing.

- [ ] **Step 3: Implement types, services and classifier**

Keep the discriminated union and the explicit normalization (do not spread the backend payload). Add the new request fields (`pow`, `step_up_token`) as optional. Add the new endpoints. Update `AuthLoginFeedback` with new cases and Vietnamese/English copy; use `auth.login.stepUp*` and `auth.login.device*` keys.

- [ ] **Step 4: Run the tests and typecheck**

Run: `npx vitest run src/modules/auth/services/auth.service.test.ts src/modules/auth/utils/login-feedback.test.ts`
Expected: PASS.
Run: `npm run typecheck`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/shared/api/endpoints.ts src/modules/auth/types/auth.types.ts src/modules/auth/services/auth.service.ts src/modules/auth/utils/login-feedback.ts src/modules/auth/components/AuthLoginFeedback.tsx src/modules/auth/services/auth.service.test.ts src/modules/auth/utils/login-feedback.test.ts
git commit -m "feat(auth): add step-up and device login contracts"
```

### Task 13: FE login page step-up and device verification states

**Files:**
- Modify: `WMS_Warehouse_Hoa_Nam_FE/src/modules/auth/pages/LoginPage.tsx`
- Modify: `WMS_Warehouse_Hoa_Nam_FE/src/modules/auth/hooks/use-login.ts`
- Create: `WMS_Warehouse_Hoa_Nam_FE/src/modules/auth/components/LoginStepUpPanel.tsx`
- Create: `WMS_Warehouse_Hoa_Nam_FE/src/modules/auth/components/DeviceVerificationPanel.tsx`
- Modify: `WMS_Warehouse_Hoa_Nam_FE/src/shared/i18n/messages.ts`, `messages.en.ts` (`auth.login.stepUp*`, `auth.login.device*`)
- Test: `WMS_Warehouse_Hoa_Nam_FE/src/modules/auth/pages/LoginPage.step-up.test.tsx`
- Test: `WMS_Warehouse_Hoa_Nam_FE/src/modules/auth/pages/LoginPage.device.test.tsx`

**Interfaces:**
- On `step_up_required`, render MFA or email code panel; after verify, keep the password in component memory and show a "Tiếp tục đăng nhập" button that submits login with `step_up_token`.
- On `device_verification_required`, render 6-digit OTP panel with masked email, resend countdown, "Quay lại đăng nhập".
- Do not auto-submit a session-creating POST. The existing `attemptInFlight` guard and `AuthLoginFeedback` patterns remain.
- `role="status"` on the panel; focus moves to the first OTP field.

- [ ] **Step 1: Write the failing tests**

```tsx
it("shows the step-up panel without publishing a token", async () => {
  vi.mocked(authService.login).mockResolvedValue({
    kind: "step_up_required", step_up_required: true, step_up_token: "s",
    method: "email", masked_email: "a***@b.test", expires_in: 300,
  });
  renderLogin();
  await submitCredentials();
  expect(await screen.findByText(/xác minh thiết bị/i)).toBeInTheDocument();
  expect(useAuthStore.getState().token).toBeNull();
});

it("verifies the device OTP and only then publishes the token", async () => {
  vi.mocked(authService.login).mockResolvedValue({
    kind: "device_verification_required", device_verification_required: true,
    device_token: "device-token", masked_email: "a***@b.test", expires_in: 300,
  });
  vi.mocked(authService.verifyDevice).mockResolvedValue(AUTHENTICATED);
  renderLogin();
  await submitCredentials();
  const inputs = await screen.findAllByRole("textbox", { name: /^Mã xác minh [1-6]$/ });
  for (const [index, digit] of "123456".split("").entries()) await userEvent.type(inputs[index], digit);
  await userEvent.click(screen.getByRole("button", { name: /xác nhận/i }));
  await waitFor(() => expect(authService.verifyDevice).toHaveBeenCalledWith("device-token", "123456"));
  expect(useAuthStore.getState().token).toBe("access-token");
});
```

Use the `AUTHENTICATED` fixture and `renderLogin`/`submitCredentials` helpers from `LoginPage.mfa.test.tsx`; extract them to a shared test helper if both files need them.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npx vitest run src/modules/auth/pages/LoginPage.step-up.test.tsx src/modules/auth/pages/LoginPage.device.test.tsx`
Expected: FAIL — panels missing.

- [ ] **Step 3: Implement the panels and state transitions**

Reuse `MfaOtpInput` for 6 digits. Add `useStepUpVerify`, `useStepUpResend`, `useDeviceVerify`, `useDeviceResend` mutations in `use-login.ts` with `retry: false`. Extend the `LoginPage` early-return branches for the new challenge kinds. Preserve the existing MFA branch and transport diagnostics. Add i18n keys in both languages.

- [ ] **Step 4: Run the tests and the MFA regression**

Run: `npx vitest run src/modules/auth/pages/LoginPage.step-up.test.tsx src/modules/auth/pages/LoginPage.device.test.tsx src/modules/auth/pages/LoginPage.mfa.test.tsx src/modules/auth/pages/LoginPage.transport-diagnostic.test.tsx`
Expected: PASS.
Run: `npm run typecheck`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/modules/auth/pages/LoginPage.tsx src/modules/auth/hooks/use-login.ts src/modules/auth/components/LoginStepUpPanel.tsx src/modules/auth/components/DeviceVerificationPanel.tsx src/shared/i18n/messages.ts src/shared/i18n/messages.en.ts src/modules/auth/pages/LoginPage.step-up.test.tsx src/modules/auth/pages/LoginPage.device.test.tsx
git commit -m "feat(auth): add step-up and device verification screens"
```

### Task 14: Security tab "Trusted devices" card

**Files:**
- Create: `WMS_Warehouse_Hoa_Nam_FE/src/modules/auth/components/profile/ProfileTrustedDevices.tsx`
- Modify: `WMS_Warehouse_Hoa_Nam_FE/src/modules/auth/pages/ProfilePage.tsx` (security section)
- Modify: `WMS_Warehouse_Hoa_Nam_FE/src/modules/auth/services/profile.service.ts`
- Modify: `WMS_Warehouse_Hoa_Nam_FE/src/modules/auth/types/profile.types.ts`
- Modify: `WMS_Warehouse_Hoa_Nam_FE/src/shared/i18n/messages.personal.ts` (VI and EN)
- Modify: `WMS_Warehouse_Hoa_Nam_FE/src/modules/auth/pages/profile-page.css` if needed
- Test: `WMS_Warehouse_Hoa_Nam_FE/src/modules/auth/components/profile/ProfileTrustedDevices.test.tsx`

**Interfaces:**
- `profileService.getTrustedDevices(): Promise<{data: TrustedDevice[]}>`
- `profileService.revokeTrustedDevice(id): Promise<{revoked:boolean; was_current:boolean; revoked_session_count:number}>`
- `TrustedDevice = {id:string; device_label:string|null; platform:string|null; client_type:string|null; is_current:boolean; trusted_at:string; last_used_at:string|null; expires_at:string; can_revoke:boolean}`.
- The card lists trust rows even when they have no active session; current-device revoke asks for confirmation and then logs out via the existing auth store.

- [ ] **Step 1: Read the UI guide and write the failing test**

Read `WMS_Warehouse_Hoa_Nam_FE/docs/ui/DESIGN_FOUNDATIONS.md` first (AGENTS.md requirement).

```tsx
it("lists a signed-out trusted device and revokes the current device with confirmation", async () => {
  vi.mocked(profileService.getTrustedDevices).mockResolvedValue({
    data: [
      { id: "t1", device_label: "Windows - Chrome", platform: "Windows", client_type: "web",
        is_current: false, trusted_at: "2026-11-01T00:00:00Z", last_used_at: null,
        expires_at: "2026-12-01T00:00:00Z", can_revoke: true },
      { id: "t2", device_label: "Windows - Edge", platform: "Windows", client_type: "web",
        is_current: true, trusted_at: "2026-11-10T00:00:00Z", last_used_at: "2026-11-10T00:00:00Z",
        expires_at: "2026-12-10T00:00:00Z", can_revoke: true },
    ],
  });
  render(<ProfileTrustedDevices />);
  expect(await screen.findByText("Windows - Chrome")).toBeInTheDocument();
  await userEvent.click(screen.getAllByRole("button", { name: /thu hồi/i })[1]);
  expect(await screen.findByRole("dialog")).toHaveTextContent(/đăng xuất/i);
  await userEvent.click(screen.getByRole("button", { name: /thu hồi/i }));
  await waitFor(() => expect(profileService.revokeTrustedDevice).toHaveBeenCalledWith("t2"));
  await waitFor(() => expect(useAuthStore.getState().token).toBeNull());
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npx vitest run src/modules/auth/components/profile/ProfileTrustedDevices.test.tsx`
Expected: FAIL — component missing.

- [ ] **Step 3: Implement the card and service**

Add the card in `ProfilePage` security section between MFA and history. Use `useQuery`/`useMutation` with `profileKeys.trustedDevices(userId)`. Use `UiConfirmDialog`, `UiStatusBadge`, `DeviceIllustration`, `formatDateTime`. On current-device revoke success, call the auth store logout and navigate to login. Add VI/EN copy from spec §7.6, including the client-declared identity note. Ensure mobile layout is one card per device.

- [ ] **Step 4: Run the tests and the profile regression**

Run: `npx vitest run src/modules/auth/components/profile/ProfileTrustedDevices.test.tsx src/modules/auth/pages/ProfilePage.mfa.test.tsx`
Expected: PASS.
Run: `npm run typecheck`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/modules/auth/components/profile/ProfileTrustedDevices.tsx src/modules/auth/pages/ProfilePage.tsx src/modules/auth/services/profile.service.ts src/modules/auth/types/profile.types.ts src/shared/i18n/messages.personal.ts src/modules/auth/components/profile/ProfileTrustedDevices.test.tsx
git commit -m "feat(auth): add trusted devices card to security settings"
```

---

## Phase D — Proof Of Work

### Task 15: PoW service and challenge endpoint

**Files:**
- Create: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/app/Support/LoginPow.php`
- Create: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/app/Http/Controllers/LoginPowController.php`
- Modify: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/routes/api.php`
- Modify: `WMS_Warehouse_Hoa_Nam_BE/app/Providers/AppServiceProvider.php` (`auth-pow-challenge`)
- Test: `WMS_Warehouse_Hoa_Nam_BE/tests/Unit/LoginPowTest.php`
- Test: `WMS_Warehouse_Hoa_Nam_BE/tests/Feature/LoginPowChallengeTest.php`

**Interfaces:**
- `LoginPow::required(string $email, string $ip): bool` — true when `enabled` and failures ≥ trigger.
- `LoginPow::challenge(string $email, string $ip): array{challenge_id:string,nonce:string,difficulty:int,algorithm:string,expires_in:int}`
- `LoginPow::verify(array $pow, string $email, string $ip): bool` — single-use, 120-second TTL, email-hash match, leading-zero-bit check.
- Difficulty: 16 at trigger, 18 after 6 failures, 20 after 9, cap 22; derived from `LoginThrottle::state()->failures`.
- `POST /api/v1/auth/login/pow-challenge` is public and rate-limited 30/minute/IP.

- [ ] **Step 1: Write the failing tests**

```php
public function test_challenge_verifies_leading_zero_bits_once(): void
{
    config(['login_security.pow.enabled' => true, 'login_security.pow.base_difficulty' => 4]);
    $issued = app(LoginPow::class)->challenge('a@b.test', '10.0.0.5');
    $solution = 0;
    while (! str_starts_with(bin2hex(hash('sha256', $issued['nonce'].':'.$solution, true)), '0')) { $solution++; }
    $this->assertTrue(app(LoginPow::class)->verify(
        ['challenge_id' => $issued['challenge_id'], 'solution' => $solution],
        'a@b.test', '10.0.0.5',
    ));
    $this->assertFalse(app(LoginPow::class)->verify(
        ['challenge_id' => $issued['challenge_id'], 'solution' => $solution],
        'a@b.test', '10.0.0.5',
    ));
}

public function test_difficulty_escalates_with_failure_count(): void
{
    config([
        'login_security.pow.enabled' => true,
        'login_security.pow.trigger_failures' => 1,
        'login_security.pow.base_difficulty' => 16,
    ]);
    Cache::flush();
    $throttle = app(LoginThrottle::class);
    for ($i = 0; $i < 9; $i++) {
        $throttle->registerFailure('risk@example.test', '10.0.0.5');
        Carbon::setTestNow(now()->addSeconds(901));
    }
    $issued = app(LoginPow::class)->challenge('risk@example.test', '10.0.0.5');
    $this->assertSame(20, $issued['difficulty']);
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Unit/LoginPowTest.php tests/Feature/LoginPowChallengeTest.php`
Expected: FAIL — class/routes missing.

- [ ] **Step 3: Implement service, controller, route and limiter**

Store the challenge in Valkey keyed by `login-pow:{challenge_id}` with `nonce`, `difficulty`, `email_hash`, `ip_hash`, `expires_at`, `used=false`. The client’s solution is an integer; compute `hash('sha256', $nonce.':'.$solution, true)` and count leading zero bits. Mark used atomically with a cache lock. The controller validates `email`, calls the service, and returns the spec shape. Use `RateLimitResponse::make` for the limiter.

- [ ] **Step 4: Run the tests and route list**

Run: `php artisan test tests/Unit/LoginPowTest.php tests/Feature/LoginPowChallengeTest.php`
Expected: PASS.
Run: `php artisan route:list --path=auth/login/pow`
Expected: the challenge route present.

- [ ] **Step 5: Commit**

```bash
git add Modules/Auth/app/Support/LoginPow.php Modules/Auth/app/Http/Controllers/LoginPowController.php Modules/Auth/routes/api.php app/Providers/AppServiceProvider.php tests/Unit/LoginPowTest.php tests/Feature/LoginPowChallengeTest.php
git commit -m "feat(auth): add conditional proof-of-work challenge"
```

### Task 16: Integrate PoW into login

**Files:**
- Modify: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/app/Http/Requests/LoginRequest.php` (`pow` optional array, `pow_supported` optional boolean)
- Modify: `WMS_Warehouse_Hoa_Nam_BE/Modules/Auth/app/Http/Controllers/AuthController.php`
- Test: `WMS_Warehouse_Hoa_Nam_BE/tests/Feature/LoginPowLoginTest.php`

**Interfaces:**
- When `LoginPow::required()` and no valid `pow` is supplied, return `428 POW_REQUIRED` with a fresh challenge in `error.details`.
- A valid `pow` continues to the password check. A PoW failure does not increment password counters but is rate-limited separately.
- PoW is evaluated after step-up and before password verification.

- [ ] **Step 1: Write the failing feature test**

```php
public function test_pow_is_required_only_after_the_trigger_and_valid_solution_proceeds(): void
{
    config([
        'login_security.pow.enabled' => true,
        'login_security.pow.trigger_failures' => 1,
        'login_security.pow.base_difficulty' => 4,
        'login_security.backoff.free_attempts' => 0,
        'login_security.backoff.delays' => [1],
    ]);
    Cache::flush();
    $user = $this->makeUser();
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertStatus(429);
    Carbon::setTestNow(now()->addSeconds(2));

    $challenge = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email, 'password' => TestCredentials::PASSWORD,
    ])->assertStatus(428)->assertJsonPath('error_code', 'POW_REQUIRED')
      ->json('error.details.challenge');

    $solution = 0;
    while (! str_starts_with(bin2hex(hash('sha256', $challenge['nonce'].':'.$solution, true)), '0')) {
        $solution++;
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => TestCredentials::PASSWORD,
        'pow' => ['challenge_id' => $challenge['challenge_id'], 'solution' => $solution],
    ])->assertOk()->assertJsonPath('data.access_token', fn ($v) => is_string($v) && $v !== '');
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/LoginPowLoginTest.php`
Expected: FAIL — 428 branch missing.

- [ ] **Step 3: Implement the login branch**

Add the `pow` validation and the branch in the order described above. Return 428 with `error_code=POW_REQUIRED`, `Retry-After` only if the challenge endpoint is throttled, and `error.details.challenge`. If the client explicitly sends `pow_supported=false`, accept the login without PoW (Layers 1–2 still apply) and audit `LOGIN_POW_UNAVAILABLE`; do not accept a missing `pow` without that flag. Audit `LOGIN_POW_REQUIRED`/`LOGIN_POW_FAILED`; never log the solution.

- [ ] **Step 4: Run the test and the auth regressions**

Run: `php artisan test tests/Feature/LoginPowLoginTest.php tests/Feature/AuthLoginBackoffTest.php tests/Feature/AuthLoginContractSecurityTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add Modules/Auth/app/Http/Requests/LoginRequest.php Modules/Auth/app/Http/Controllers/AuthController.php tests/Feature/LoginPowLoginTest.php
git commit -m "feat(auth): require proof of work only for elevated risk"
```

### Task 17: FE PoW worker and login UI

**Files:**
- Create: `WMS_Warehouse_Hoa_Nam_FE/src/modules/auth/utils/login-pow.worker.ts`
- Create: `WMS_Warehouse_Hoa_Nam_FE/src/modules/auth/hooks/use-login-pow.ts`
- Modify: `WMS_Warehouse_Hoa_Nam_FE/src/modules/auth/pages/LoginPage.tsx`
- Modify: `WMS_Warehouse_Hoa_Nam_FE/src/modules/auth/services/auth.service.ts` (already has `powChallenge`)
- Modify: `WMS_Warehouse_Hoa_Nam_FE/src/shared/i18n/messages.ts`, `messages.en.ts` (`auth.login.pow*`)
- Test: `WMS_Warehouse_Hoa_Nam_FE/src/modules/auth/pages/LoginPage.pow.test.tsx`
- Test: `WMS_Warehouse_Hoa_Nam_FE/src/modules/auth/utils/login-pow.worker.test.ts` (if the worker logic is exported)

**Interfaces:**
- Worker input `{nonce:string, difficulty:number}`; posts `{solution:number}`; supports cancellation/termination.
- `useLoginPow` returns `{solving, solve(nonce, difficulty), cancel()}`; on failure falls back to `pow_supported=false`.
- Login flow: submit → if 428 `POW_REQUIRED`, show "Đang xác minh thiết bị…", solve, retry once with `pow: {challenge_id, solution}`. Do not loop more than one retry without user action.
- Show the progress state, not a blocking blank screen; keep cancel available.

- [ ] **Step 1: Write the failing test**

```tsx
it("solves the challenge and retries login once with the pow payload", async () => {
  vi.mocked(authService.login)
    .mockRejectedValueOnce({ response: { status: 428, data: { error_code: "POW_REQUIRED", error: { details: { challenge: CHALLENGE } } } } })
    .mockResolvedValueOnce(AUTHENTICATED);
  renderLogin();
  await submitCredentials();
  expect(await screen.findByText(/đang xác minh thiết bị/i)).toBeInTheDocument();
  await waitFor(() => expect(authService.login).toHaveBeenCalledTimes(2));
  expect(vi.mocked(authService.login).mock.calls[1][0].pow).toEqual({ challenge_id: CHALLENGE.challenge_id, solution: expect.any(Number) });
});

it("falls back once with pow_supported=false when the worker is unavailable", async () => {
  vi.stubGlobal("Worker", undefined);
  vi.mocked(authService.login)
    .mockRejectedValueOnce({ response: { status: 428, data: { error_code: "POW_REQUIRED", error: { details: { challenge: CHALLENGE } } } } })
    .mockResolvedValueOnce(AUTHENTICATED);
  renderLogin();
  await submitCredentials();
  await waitFor(() => expect(authService.login).toHaveBeenCalledTimes(2));
  expect(vi.mocked(authService.login).mock.calls[1][0].pow_supported).toBe(false);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npx vitest run src/modules/auth/pages/LoginPage.pow.test.tsx`
Expected: FAIL — worker/hook missing.

- [ ] **Step 3: Implement the worker, hook and UI**

Use a compact SHA-256 implementation in the worker (or a small WASM module if the measured budget requires it). The worker must not block the main thread. Add a 10-second client timeout; on timeout show a retry/cancel message and do not auto-submit. If `Worker`/`crypto.subtle` is unavailable, skip PoW and submit with `pow_supported=false`. Add i18n keys in both languages. Keep the existing login states intact.

- [ ] **Step 4: Run the tests, typecheck and the login regressions**

Run: `npx vitest run src/modules/auth/pages/LoginPage.pow.test.tsx src/modules/auth/pages/LoginPage.step-up.test.tsx src/modules/auth/pages/LoginPage.device.test.tsx src/modules/auth/pages/LoginPage.mfa.test.tsx`
Expected: PASS.
Run: `npm run typecheck`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/modules/auth/utils/login-pow.worker.ts src/modules/auth/hooks/use-login-pow.ts src/modules/auth/pages/LoginPage.tsx src/modules/auth/services/auth.service.ts src/shared/i18n/messages.ts src/shared/i18n/messages.en.ts src/modules/auth/pages/LoginPage.pow.test.tsx src/modules/auth/utils/login-pow.worker.test.ts
git commit -m "feat(auth): add risk-triggered proof-of-work login step"
```

### Task 18: Rollout checklist, flags and final verification

**Files:**
- Modify: `WMS_Warehouse_Hoa_Nam_BE/.env.example` (all `LOGIN_*`, `TRUSTED_DEVICE_*`, `DEVICE_OTP_*` keys with safe defaults)
- Create: `WMS_Warehouse_Hoa_Nam_BE/docs/operations/risk-adaptive-login-rollout.md`
- Test: `WMS_Warehouse_Hoa_Nam_BE/tests/Feature/LoginSecurityFlagsTest.php`

**Interfaces:**
- Flags: `LOGIN_BACKOFF_ENABLED=true`, `LOGIN_STEP_UP_ENABLED=true`, `TRUSTED_DEVICE_ENABLED=false`, `LOGIN_POW_ENABLED=false` by default.
- Rollout doc covers DEV pilot, Gmail pilot, transactional provider prerequisites, prod-blue migration/email/Reverb/scheduler gates, rollback flags and audit checks.

- [ ] **Step 1: Write the failing flag test**

```php
public function test_disabled_layers_do_not_change_the_existing_login_contract(): void
{
    config([
        'login_security.trusted_device.enabled' => false,
        'login_security.pow.enabled' => false,
        'login_security.backoff.enabled' => false,
    ]);
    $user = $this->makeUser();
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => TestCredentials::PASSWORD])
        ->assertOk()
        ->assertJsonMissingPath('data.device_verification_required');
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/LoginSecurityFlagsTest.php`
Expected: FAIL until the flags are wired.

- [ ] **Step 3: Implement flags, env example and rollout doc**

Gate every layer before its behavior. Document the exact commands, thresholds, pilot group, rollback and the checks from spec §14. Do not enable trusted devices or PoW by default. Do not commit real SMTP credentials.

- [ ] **Step 4: Run the full auth regression, the frontend auth suite and the manual DEV checklist**

Run: `php artisan test tests/Feature/AuthLoginContractSecurityTest.php tests/Feature/AuthSessionPersistenceTest.php tests/Feature/AuthSessionReplacementTest.php tests/Feature/WwhnSecurityHardeningTest.php tests/Feature/P10ProfileContractTest.php tests/Feature/ProfileSelfServiceTest.php tests/Feature/AuthLoginBackoffTest.php tests/Feature/AuthStepUpEndpointTest.php tests/Feature/TrustedDeviceLoginTest.php tests/Feature/TrustedDeviceManagementTest.php tests/Feature/LoginPowLoginTest.php`
Expected: PASS.
Run: `npx vitest run src/modules/auth/pages/LoginPage.mfa.test.tsx src/modules/auth/pages/LoginPage.transport-diagnostic.test.tsx src/modules/auth/pages/LoginPage.step-up.test.tsx src/modules/auth/pages/LoginPage.device.test.tsx src/modules/auth/pages/LoginPage.pow.test.tsx src/modules/auth/components/profile/ProfileTrustedDevices.test.tsx src/modules/auth/services/auth.service.test.ts`
Expected: PASS.
Run: `npm run typecheck`
Expected: PASS.

Manual DEV/TEST checklist, recorded in the rollout doc:

- Real inbox receives the device OTP and the step-up OTP; links and tokens are absent from the email.
- Clear cookies → OTP → verify → trust cookie set → logout → next login skips OTP.
- Five cap cycles → step-up → correct password → counters reset; a wrong password three times requires a new step-up.
- Shared browser: two users each keep their own trust cookie and do not trigger each other's OTP.
- Shared NAT: two users on the same public IP do not lock each other; a trusted device bypasses both waits.
- MFA account: no device OTP; step-up uses MFA; no double challenge.
- Oldest scanner: PoW median 0.5–2s, p95 ≤ 3s at 16 bits. If p95 > 3s, lower the base difficulty or keep `LOGIN_POW_ENABLED=false`.
- `php artisan migrate:status`, `php artisan config:show login_security`, queue/scheduler health and no Reverb restart storm after deploy.

- [ ] **Step 5: Commit**

```bash
git add .env.example docs/operations/risk-adaptive-login-rollout.md tests/Feature/LoginSecurityFlagsTest.php
git commit -m "docs(auth): add risk-adaptive login rollout and flags"
```

---

## Self-Review Checklist

- [ ] Spec §6.1–6.6: Task 1, 4, 5, 6 cover keys, delays, cap, five cycles, 429 and step-up.
- [ ] Spec §7.1–7.8: Task 7, 8, 9, 10, 11, 14 cover trust rows, per-user cookies, installation fallback, unknown-device OTP, MFA interaction, bulk/admin revocations and UI.
- [ ] Spec §8.1–8.5: Task 15, 16, 17 cover challenge, difficulty, single-use, 428 and worker fallback.
- [ ] Spec §10: config, migration, API and error codes match the task names and signatures.
- [ ] Spec §15: every acceptance row has a test in Tasks 1, 4, 5, 7, 8, 9, 10, 11, 13, 14, 15, 16, 17, 18.
- [ ] No task uses `sleep()`; every 429 carries `Retry-After`; no token is issued before full verification.
- [ ] Shared browser, missing `X-Device-Id`, shared NAT, MFA + five cycles and email failure each have a test.
- [ ] The existing `/auth/devices` contract and MFA tests remain unchanged.
