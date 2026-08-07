# WWHN-180 — G1 API/Data Contract Minutes Template

> Trạng thái: **PROPOSED**. BA phải lập minutes thực tế trên WWHN-40 và dẫn chiếu WWHN-181/182/183. Không dùng file này như approval có sẵn.

## 1. Thông tin phiên review

- Date/time: `<ISO-8601 +07:00>`
- Chair/BA: `<name>`
- Participants: `BA / BE1 / FE1 / QA2 / PM or Tech Lead`
- Branch: `m1`
- Exact BE SHA: `<build-metadata.json.commit_sha>`
- CI run URL: `<url>`
- Artifact: `api-data-evidence-<exact_sha>`
- Artifact digest: `<SHA256SUMS.sha256>`
- Deployment SHA/environment: `<sha/environment or N/A>`

## 2. Decision matrix

| Blocker/Question | Locked endpoint/schema/rule | Test/evidence | Independent reviewer | Result | Remaining action |
|---|---|---|---|---|---|
| Scan stock effect | Inbound/outbound scan is validate/classify only; `stock_effect=NONE` | OpenAPI, Postman, DB before/after | FE1 + QA2 | PENDING | `<action>` |
| Receipt posting | `post-receipt`, required idempotency, exactly-once stock effect | PHPUnit/Newman/race/reconcile | QA2 | PENDING | `<action>` |
| Issue posting | `post-issue`, required idempotency, exactly-once stock effect | PHPUnit/Newman/race/reconcile | QA2 | PENDING | `<action>` |
| Replay/conflict | Same key/same payload replay; same key/different payload 409; different-key double-post 409 | Atomicity tests + Postman + DB delta | QA2 | PENDING | `<action>` |
| Timeout after commit | Business write and response snapshot commit atomically | Atomicity/raw timeout evidence | QA2 | PENDING | `<action>` |
| RBAC mapping | Runtime route, permission catalog and FE guard agree | permission-catalog + FE mapping | FE1 + BA | PENDING | `<action>` |
| Reconciliation | Balance equals signed ledger by dimension | reconciliation report | QA2 | PENDING | `<action>` |
| Artifact integrity | Exact SHA and both manifests pass | build metadata + checksum | BA/Tech Lead | PENDING | `<action>` |

## 3. Required independent records

- BA G1 decision comment: `<URL>`
- FE1 mapping sign-off: `<URL>`
- QA2 WWHN-182 retest evidence: `<URL>`
- WWHN-183 closure comment: `<URL>`
- Parent WWHN-40 minutes: `<URL>`

Mỗi record phải ghi cùng exact SHA và artifact digest. Self-review của BE1 không thay thế reviewer độc lập.

## 4. Final decision

- Decision: `GO | CONDITIONAL_GO | NO_GO`
- Conditions: `<NONE hoặc danh sách có owner/deadline/evidence>`
- P0 open count: `<number>`
- P1 open count: `<number>`
- Approved contract version: `<version hoặc NOT_APPROVED>`
- Effective deployment SHA: `<sha hoặc NOT_DEPLOYED>`
- Decision owner: `<name>`
- Decision timestamp: `<ISO-8601 +07:00>`

Quy tắc: chỉ được ghi `GO/APPROVED/Done/100%` khi BA, FE1 và QA2 đã PASS độc lập trên cùng exact SHA/digest và không còn P0/P1 blocker.
