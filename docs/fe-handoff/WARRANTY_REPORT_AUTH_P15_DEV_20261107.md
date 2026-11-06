# Warranty Report, P15 And Controlled Auth Release

Scope: DEV/TEST only. User authorized source test/push/deploy on 2026-11-07.
No seed, database repair, historical rewrite, production activation or real migration.

## Warranty Report

The old raw string date comparison excluded aware ISO timestamps on SQLite and
used unqualified day bounds on PostgreSQL. All warranty table, full-set summary,
defect/SKU/component charts and export queries now share read-only normalization.

- Query dates and default days use Asia/Ho_Chi_Minh.
- Explicit source offsets retain the instant; legacy naive source fields use VN.
- Source columns are normalized separately before received/created COALESCE.
- The upper bound is exclusive next midnight, preserving the last microsecond.
- Turnaround counts elapsed whole days between actual instants.
- Stored values and existing wire source fields are not rewritten.
- Only affected internal cache namespaces change; PII and warehouse guards remain.

## P15

See P15_OWNER_PROJECTION_FIX_20261106.md. P04 actor comes from the exact persisted
draft owner/family/context. Historical response receipts remain unchanged.
Cancellation continues to depend on the latest SAVE/version/request/permission.

## Auth

Contract: auth-session-policy-v2 / controlled-multi-session-r1.
See docs/contracts/auth-controlled-multi-session-v1.json.

- Maximum two distinct active stable families/account, no automatic eviction.
- Third full authenticated login returns 409 AUTH_SESSION_LIMIT_REACHED.
- Rotation remains within its family. Revoked refresh replay quarantines only
  that persisted family, not the other device or an unknown token's alleged owner.
- Logout stays family-local; password/security revoke-all stays account-wide.
- Actor, warehouse, shift, draft and command identity remain family-scoped.
- App must recognize v2 in policy metadata and its foreground/pre-command guards.
  Do not share tokens between Web/Android or take over drafts from another family.
- Existing sessions are not replaced simply by activating the policy.

## Release Evidence

This file describes source behavior, not live signoff. The final external delivery
contains exact source/shared-dev/runtime SHA, actual QA counts/skips, schema,
routes/flags, sanitized HTTP and deployment receipts.

Normal notification delivery remains ON; canary/self-test remain OFF.
The deferred TC15/P08 v2 source is excluded. Native enrollment and live dual-device
acceptance are separate and must not replay the spent Android TEST intent.
