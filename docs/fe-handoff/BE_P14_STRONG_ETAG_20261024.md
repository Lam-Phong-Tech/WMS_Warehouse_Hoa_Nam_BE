# P14 Strong ETag - BE/Ops, 24/10/2026

Source/config fix, pending DEV nginx reload and authenticated acceptance. Not App/UAT PASS.

**Latest acceptance:** App supplied authenticated proxy retest after the user's own login: browser-default, gzip and identity all HTTP200 with exact strong ETag `"1"` matching version1, no Content-Encoding and UI accepted. UAT-R7-01 is `PASS_READ_LIVE_APPQR_CURRENT_SESSION` for current shift `12e4c5b1-518e-4146-b9bf-756ac2f65074` only. The old family/shift is not retested/resolved; direct-origin authenticated, local CLEAN, TEST END and native gates stay separate. See `docs/fe-handoff/BE_P14_APPQR_READ_ACCEPTANCE_20261024.md`. Earlier pending/failure statements below remain historical, not the latest read status.

**Latest: R2 App retest still failed with gzip/default despite no-transform.** Read-only inspection then found an unprotected second compression hop: the public DEV TLS nginx1.18.0 inherited global JSON gzip and protected only auth/me, while the inner nginx1.31.3 R2 hashes were correct. The additional DEV TLS vhost rule was deployed by run `40073536629`, source `42ffcc57b9d7e9008ae53ee15e0695f435f6f71d`, at 14:17 +07:00. Before/after loopback reproduction with the actual host binary/routing passed48 cases; no historical per-hop headers are invented from logs. Appqr is a different DNS ingress, not inspected/modified. Original-owner authenticated retest remains OPEN. See `docs/evidence/P14_TLS_GATEWAY_RELEASE_20261024.json` and the TLS gateway response package; do not mark end-to-end PASS from R2 or unauthenticated checks.

Release update: DEV nginx configuration commit `648558227be478ebccb2356818d419937303d516` deployed successfully at 13:28:34 +07:00 on 24/10/2026, run `40070282223`. PHP stays `a9053a21bc35ece367be0b76db727d4749b59855`. Both variants passed 42 transport cases on Windows and independently on the exact serving Linux image (nginx1.31.3). Public origin/proxy health200 and unauthenticated close-check401 preserve no-transform; these are not authenticated ETag acceptance. CI skipped; no migration/seed/login/real END. Details: `docs/evidence/P14_NGINX_ETAG_RELEASE_20261024.json`. The earlier pending-source statements describe the pre-release snapshot.

Input: `BE_P14_STRONG_ETAG_FIX_20261024.zip`, SHA256 `3315551cfb73215537e7b9a4c55acabc30fe708efb56bd0e573e66d795f382c2`.
Both supplied base hashes match the current repository and the last deployed PHP source `a9053a21bc35ece367be0b76db727d4749b59855`.

## Scope

- Default and perf Nginx templates bypass gzip only on UUID P14 `close-check`/`end`, preserving the original strong owner ETag. Do not strip `W/` or synthesize validators at any client or proxy.
- Keep auth/me, unrelated gzip, front-controller routing, original query/If-Match/Request-ID, forwarded headers, X-Powered-By suppression, gateway errors, buffering, perf upstream/timeouts and existing retry limits. Never opt into non-idempotent retries.
- Reviewed adaptation of the supplied patch: concise comments and `Cache-Control: private, no-store, no-transform` on the two P14 paths, including owner errors. This requests outer intermediaries not to recompress the owner representation. The original owner Cache-Control is hidden only there to avoid conflicting duplicate policies. Other middleware/owner headers stay intact.
- The reusable real-Nginx/synthetic-FastCGI test now supports Windows and Linux, P14 gzip/identity/HEAD/POST, query/header forwarding, upstream 409, deliberately weak upstream validators and invalid/other-route controls. Synthetic POST is loopback only, not a real END.

## Deployment Boundary

This is Nginx-only: preserve PHP build SHA, images/vendor, backend/queue processes, configuration flags, routes, schema and application storage. Back up the two templates and rendered config, render and syntax-check, then gracefully reload only green nginx. Fail closed on target/source/overlay drift and roll back only Nginx configuration on failure. No migration, seed, repair, restore, new login, START/END or stock/NFC command.

The deploy workflow uses existing staging credentials without exporting them. The `ci` job remains skipped. Before reload it independently runs the transport cases in a disposable container using the exact Linux nginx image of the serving instance. No real application FastCGI or credentials are mounted into that test.

## Acceptance Still Needed

The user supplied shift `5e8e6150-4c76-4313-879f-d0884ee9c085` and warehouse `01a06f33-6bff-70b8-a089-3c68a83b11a8`, with a prior observation around 13:01 +07 of HTTP200/ETag `W/"2"`. No currently verified token file is available. Do not log in to replace the operator's family.

After deployment, require authenticated GET close-check on that original family, through origin and appqr `/wms-api`, with browser gzip and identity. Verify exact quoted ETag equals body expected_version, unchanged owner identity/contract, private/no-store and no transformed representation. Public 401, health200 and synthetic POST are not this acceptance. Do not END the working shift; real TEST END requires its own approved case/key/body/version/local CLEAN.

NFC historical timestamp provenance (UAT-R7-04), P11 activation, full seed acceptance and other native UAT remain separate. Do not repair data, reassign NFC, change flags or weaken FE validation to close this ticket.
