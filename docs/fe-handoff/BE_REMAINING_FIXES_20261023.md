# BE Source Hardening, 23/10/2026

Status: SOURCE CANDIDATE, NOT DEPLOYED, NOT FULL APP/UAT PASS.

Follow-up 24/10/2026: `docs/fe-handoff/BE_SECURITY_READ_FIELDS_20261024.md` supersedes the P11 source gap and adds an explicit no-null read representation. Historical test/release statements below remain dated 23/10, not new runtime evidence.

Base remote DEV: `dc9109830c5d389982964dfe601bff712d6323ab`, verified using `git ls-remote wms refs/heads/dev` during this run. Parent local fix: `2c8dba8d9517322eb52bad630bbb54e95e7052b3`. Work is on `codex/be-remaining-hardening-20261023`. The package manifest pins the exact candidate commit, not a live SHA.

## Implemented Source Changes

| Area | Actual correction | Source/contract | Remaining acceptance |
| --- | --- | --- | --- |
| P18/P14 upload durability | Commit native upload intent before the shift's outer transaction. Actor/warehouse/shift lock order matches END. Exception or worker death cannot erase intent. UNKNOWN stays CHECK_ONLY; same-file pending key rotation and stripping the tracked upload contract are rejected. | `AttachmentUploadReceipts`, `P14ShiftOwnerFence`, `P18UploadCrashTest` | Exact release, authenticated upload/storage/scanner/proxy smoke; operator orphan resolution remains required. |
| P14 close-check | Scoped native PENDING/UNKNOWN uploads block END. The check fingerprint includes terminal identities/state/timestamps too, so native changes without a shift-version increment invalidate an old check. No journal is deleted and no local CLEAN is assumed. | `P14ShiftService` | Full live owner matrix, old UNKNOWN/legacy resolution and device CLEAN acceptance remain open. |
| P09/P14 native rejection | A verified P09 FAILED receipt survives the outer shift transaction; it is OWNER_REJECTED, not a successful request or warranty business document. Match native key/request digest, operation, HTTP status and scope. UNKNOWN is not relabelled FAILED. | `P14ShiftOwnerEvidence`, `P09WarrantyHandoverContractTest` | This is not a claim that all P09 crash/UNKNOWN intents now have a precommitted native journal. |
| P13 current Web queue | Successful inbound/outbound RECORD writes an explicit handoff atomically, including P05 source-linked flow. Existing P12 adds `web_queue=WAITING_WEB_POST`, filtering before full-set totals/facets/keyset. Current POSTED/CANCELLED cannot be overwritten by an old receipt. | `docs/contracts/p13-web-post-queue-v1.json` | New additive schema review/deployment, approved new RECORD and read smoke. Old data is NOT backfilled. |
| P18 real physical model | User corrected the earlier rack/bin proposal: one warehouse with actual boxes. Opt-in GET projection on existing container list/detail; tiny COMPONENT parts are quantity-based without individual item barcode. Existing component SKU/BOX owners remain mandatory. | `docs/contracts/p18-single-warehouse-boxes-v1.json` | Actual DEV warehouse register/scope and representative boxes must be read under the approved session. |
| Dependency | Vite updated to 8.3.5-compatible range; actual locked install, build and npm baseline tests executed. | `package.json`, `package-lock.json` | npm audit still fails; no waiver/downgrade/forced incompatible override. |

## FE/App Wiring

P13 uses the existing GET `/api/v1/mini-app/documents?web_queue=WAITING_WEB_POST`. Preserve warehouse/source/status/date/per_page and exact returned cursor. `web_post_handoff` is separate from canonical status, Mini App status and readiness. The detailed owner projection is `p13.current_handoff`; session-private historical `record_handoff` stays private. Missing handoff remains null/NOT_ESTABLISHED. A missing handoff source returns 503, not an empty pending queue. The queue covers explicit new RECORD submissions only, not all historical pending documents.

The additive table is `mini_app_web_post_handoffs`; apply its reviewed migration before running the new RECORD code. No real migration/backfill was run here. `reviewed_at` stays null without an owner source; `posted_at` remains the exact nullable persisted field. P12 cursor v3 is retained, but changed filter binding can require restarting from `cursor=start` after a 409 context-change response.

P18 uses existing GET `/api/v1/containers` or `/api/v1/containers/{container_id}` with `X-Container-Read-Contract: p18-single-warehouse-boxes-v1` and required UUID `warehouse_id`. Optional list filters are `sku_type=COMPONENT|PRODUCT|ALL`, keyword and page/per_page. Existing container/putaway read rights AND `sku.view` are required for this master/classification projection; no permission is granted here. The header is not an upload/move command. Without it, legacy Web GET behavior is unchanged. No endpoint was added.

`contained_qty` is actual active container content, not warehouse on-hand/available/reserved or issue eligibility. Missing/inconsistent contents remain null with an explicit status. Capacity, bins and grid are not invented. `location_hint` is free text only. A second persisted warehouse, even inactive, blocks this single-warehouse global-register projection until real ownership exists. Do not classify a PRODUCT as COMPONENT merely because it is small or lacks a barcode. Existing P19 revalidation/receipts must decide actual issue eligibility.

## Still Open

- P11: user selected reauthentication plus additional MFA. Existing v1 still truthfully declares durable receipt/reconciliation unsupported. A versioned command journal, MFA assurance binding, exact-key read/recovery and audited support treatment of old identity-less UNKNOWN remain unimplemented and need security-owner review. Revoked tokens are not granted access and a new family cannot take over a WMS journal.
- npm audit: eight reported vulnerable packages/dependency nodes, seven high and one moderate, in the Postman/Newman toolchain. These are not eight distinct root advisories. Vite's advisory was removed. Faker/csv major upgrades are not assumed compatible with old consumers; node-forge 1.4.0 has no published patched version in the observed advisory. Do not run untrusted Postman data or claim the release audit gate passed.
- Full seed: no new full-chain seed was run. The prior interrupted PostgreSQL run remains INCOMPLETE_NOT_PASS. Two full runs at coverage14, fixed time/secret and row/field/ledger/file comparisons are still required on an approved isolated clone. No smaller seed is substituted as evidence.
- Real DEV authenticated raw HTTP, exact deployed SHA/schema/flags, NFC receipt/device evidence, scoped approved write datasets and full owner/end/device acceptance remain open. Local fixtures and public health are not substitutes.
- No claim is made that every BE-01 through BE-10 item or every native screen is closed. P08/P23/P19/P11 regression results are retention checks, not new live capability approval.

## Execution Boundary

No push, GitHub workflow/CI, deployment, production activation, runtime flag change, real migration/seed/repair/restore/grant, new admin login, NFC assignment or live stock command was performed. Local tests use disposable SQLite and loopback PostgreSQL, array mail/cache and isolated upload storage. The user's main worktree changes, real product data/images/stock and existing deployment were not modified.

Dependency sources: [Faker advisory](https://github.com/faker-js/faker/security/advisories/GHSA-qxc2-j82w-r537), [csv-parse advisory](https://github.com/advisories/GHSA-8cw4-87c7-c6xx), [node-forge advisory](https://github.com/advisories/GHSA-86w9-cpqp-85rv), [Vite advisory](https://github.com/advisories/GHSA-587h-7wmj-h5pm). Exact local test results and checksums are in the response package's verification manifest. Test groups overlap; do not sum their counts into a unique-suite total.
