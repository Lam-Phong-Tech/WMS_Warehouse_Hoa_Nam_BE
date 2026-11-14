# Authentication email language

**Goal:** Password reset and login/device OTP email subjects and bodies follow the selected Vietnamese, English or Chinese interface language.

**Architecture:** The frontend sends `Accept-Language` from the current language store on each public recovery request and auth API request. The backend negotiates vi/en/zh and sets the Mailable locale before sending or queueing. Laravel translation catalogs provide all email prose; Vietnamese is the default for missing or unsupported preferences. A resend uses the language selected for that request.

**Scope:** Authentication OTP emails, including password reset, DEVICE and STEP_UP. Preserve authentication contracts, OTP lifetimes, account enumeration protection and queue behavior. Do not send real emails during verification. Preserve pre-existing workspace changes.

## Tasks

- [x] Add frontend regression tests: recovery send/resend and auth requests read the current locale at request time.
- [x] Observe tests fail; add Accept-Language to both transports; verify existing transport tests.
- [x] Add backend tests: locale negotiation; subject/body/OTP/TTL for all three languages; queued reset mail retains locale; login/device resend follows the current request locale; default remains vi.
- [x] Observe failures; add AuthMailLocale, three auth_mail catalogs, and set locale at the two mail dispatch points.
- [x] Run focused backend/FE regression tests, TypeScript and relevant linters. Review the final change and record results.

## Decisions

- The user's screenshot and request specify the desired behavior sufficiently; no new UI or product choice is required.
- Work in the current repositories to preserve the ongoing local auth-label fix; do not include unrelated backend evidence files.
- `Accept-Language` avoids changing JSON payload contracts and works before login; request language takes priority over any stored account preference.
- The locale belongs to each Mailable rather than mutable global application state, so queued mail cannot inherit another request's language.

## Verification results

- RED: recovery request language was undefined; real Axios request language was undefined; backend mail locale was null and subjects/bodies stayed Vietnamese.
- GREEN: FE `vitest run` on password-recovery.service, axios.language, axios and auth.service: 48/48 passed.
- GREEN: BE PHPUnit on AuthMailLocalization, PasswordResetOtpFlow, LoginOtpService, AuthStepUpEndpoint and TrustedDeviceLogin: 24/24 passed, 201 assertions, using SQLite `:memory:` and fake mail.
- ESLint passed for the four changed frontend files. PHP syntax checks passed for changed dispatch/mail code; Pint passed for new catalogs/helper and localization/login/step-up tests. Existing controller and reset-mail formatting issues are outside this change.
- Verification ruling: test requests must be replaced between simulated sends because Symfony caches parsed Accept-Language per Request; production receives a fresh Request for every send/resend. Frontend Axios tests unmock the suite-wide Axios fake and use the real interceptors with a test adapter.
- TypeScript and frontend test formatting checks passed.
- Independent final review found no actionable issues and independently passed AuthMailLocalizationTest (5 tests, 93 assertions). Queue serialization and Laravel locale restoration were also checked against installed framework sources.
- Verification limits: no production SMTP delivery or live mailbox test; no deployment/worker restart; no visual verification across email clients. These require deploying both frontend and backend. Unrelated pre-existing workspace changes were preserved.
