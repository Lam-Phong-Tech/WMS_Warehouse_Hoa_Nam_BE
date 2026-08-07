# WWHN-134 — BE1 Soạn nội dung kỹ thuật/nghiệp vụ và lựa chọn đề xuất

## 1. Release remediation

- Contract: `DEC-REV-001`
- Version: `1.3.0-proposed`
- Status: `PROPOSED — PENDING INDEPENDENT SIGN-OFF`
- Branch: `m1`
- Immutable release: `docs/api-contract/releases/DEC-REV-001/1.3.0-proposed/`
- Manifest SHA-256: `bd0e356e6301c4e7e142b57108c69684c2624a64c0ea2349a2be4b046472cf95`
- SHA256SUMS SHA-256: `26e39d4de842f589fdecf797388fc819975f0cdfba18ae764568e01228b55c4f`
- Prior proposal commit: `f9c8c5ca74017c4269cf503bd7d6222b5ad3cc9c`
- Observed parent commit: `7d7091c48798114b5bbf7ff4c0b58617d177c737`

BE1-controlled documentation, governance, traceability and artifact verification are complete. Overall Jira closure remains fail-closed because independent Tech Lead/QA/FE/BAS/PM actions cannot be self-signed by BE1.

## 2. Risk & Action Register disposition

| ID | Sev | Root cause | Remediation in this release | BE status | External closure gate |
|---|---|---|---|---|---|
| BE1-P1-01 | P1 | v1.2 lacked independent acceptance target with exact hashes | Added immutable package, manifest, checksums, exact comment protocol and CI evidence artifact | `READY_FOR_INDEPENDENT_REVIEW` | Tech Lead/QA/FE result on exact SHA + manifest hash |
| BE1-P1-02 | P1 | WWHN-26 has no final outcome | Added formal PM/PO outcome template, allowed vocabulary and fail-closed policy | `READY_FOR_PM_PO_DECISION` | WWHN-26 `APPROVED/REJECTED/CHANGES REQUIRED` |
| BE1-P1-03 | P1 | WWHN-136 remains To Do | Added source register and immutable traceability matrix for WWHN-17/31/33/35/40 | `READY_FOR_BAS_PIN` | BAS `TRACEABILITY-PINNED` with comment IDs/date |
| BE1-P1-04 | P1 | repo paths were mutable self-claims | Added versioned OpenAPI/Postman/schema, changelog, manifest, SHA256SUMS and publisher separation | `BE_COMPLETE` | Tech Lead `PUBLISHED-VERIFIED` |
| BE1-P1-05 | P1 | prior review could not verify commit/files | Added exact-SHA GitHub workflow, commit/diff/tree digest and downloadable evidence artifact | `BE_COMPLETE` | Reviewer verifies workflow/artifact or Jira attachment |
| BE1-P2-01 | P2 | proposal could be confused with implementation evidence | Kept and strengthened downstream boundary; documented actual route/permission divergence | `BE_COMPLETE` | No runtime claim until separate implementation evidence |
| BE1-P2-02 | P2 | closure table lacked enforceable reviewer fields | Added role-separated ledger, allowed outcomes and mandatory exact-hash fields | `BE_COMPLETE` | Each reviewer records own disposition |

## 3. Architecture and technical baseline

Applied from `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md`:

- Laravel Modular Monolith and PostgreSQL;
- transaction + deterministic row-level lock for stock-changing actions;
- append-only inventory movement as the audit source;
- backend RBAC and warehouse/data scope;
- mandatory idempotency and atomic replay snapshot;
- audit/outbox in the same transaction;
- standardized error envelope without stack trace, SQL, secret or internal path.

## 4. Official immutable artifacts

| Artifact | SHA-256 |
|---|---|
| `docs/api-contract/releases/DEC-REV-001/1.3.0-proposed/openapi.yaml` | `c852d2bb19687045a0298ed6135f218afb3636588fd4ce16c0a8de1067f3738a` |
| `docs/api-contract/releases/DEC-REV-001/1.3.0-proposed/reversal.schema.json` | `08146ec2d07155a8fce7c92a2b9b287033675ae2360c2ea18b6035077ec4623f` |
| `docs/api-contract/releases/DEC-REV-001/1.3.0-proposed/postman_collection.json` | `04464880408cde560c58f4b51c45f57b319d7fd3af87d4dd097185856002d93f` |
| `docs/api-contract/releases/DEC-REV-001/1.3.0-proposed/CHANGELOG.md` | `035763c41272e98a79686041144d6b4bbc04a27e88a4c8416330a449cc04821c` |
| `docs/api-contract/releases/DEC-REV-001/1.3.0-proposed/REVIEW_AND_APPROVAL.md` | `151767e4ed2d1f3f2c5d7f74479c60f99053bd1fa2f63eb7e4cdffb1940fa6f4` |
| `docs/api-contract/releases/DEC-REV-001/1.3.0-proposed/TRACEABILITY.md` | `047dfdd77ee635662503089e21fa3a9055995e6c1981e1ede96035a35d714f26` |
| `docs/api-contract/releases/DEC-REV-001/1.3.0-proposed/RELEASE_MANIFEST.json` | `bd0e356e6301c4e7e142b57108c69684c2624a64c0ea2349a2be4b046472cf95` |
| `docs/api-contract/releases/DEC-REV-001/1.3.0-proposed/SHA256SUMS` | `26e39d4de842f589fdecf797388fc819975f0cdfba18ae764568e01228b55c4f` |

Canonical aliases:

- `docs/api-contract/WWHN-134-reversal-openapi.yaml` — byte-identical to immutable `openapi.yaml`;
- `docs/postman/WWHN-134-reversal.postman_collection.json` — byte-identical to immutable `postman_collection.json`.

## 5. Contract summary

- Target endpoint: `POST /api/v1/reversals`
- Target permission: `stock.reversal.create`
- Scope: warehouse/data scope enforced by backend
- Required headers: `Authorization`, `X-Request-Id`, `Idempotency-Key`
- Success: 201 first write; 200 same-key/same-payload replay
- Conflict: 409 `IDEMPOTENCY_ERROR`, `INVALID_STATE`, `REVERSAL_LIMIT_EXCEEDED`, `REVERSAL_DEPENDENCY_CONFLICT`, `CONCURRENT_MODIFICATION`
- Other errors: 401, 403, 404, 422, 428
- Document status remains `DRAFT/CONFIRMED/POSTED/CANCELLED`
- `PENDING_RETURN` remains line/evidence state only

## 6. Runtime divergence — explicit fail-closed disposition

Observed runtime at `7d7091c48798114b5bbf7ff4c0b58617d177c737`:

- `POST /api/v1/inbound-documents/{id}/reverse`
- `POST /api/v1/outbound-documents/{id}/reverse`
- permission `stock_document.reverse`

Target proposal:

- `POST /api/v1/reversals`
- permission `stock.reversal.create`

This release does not claim an alias, migration or deployed runtime endpoint. A separate implementation change and exact-SHA QA/FE evidence are mandatory before runtime Done.

## 7. Publisher and sign-off separation

| Role | Owner | Allowed result | Current |
|---|---|---|---|
| BE1 Author | Quang Nguyễn Thanh | `PROPOSED` | `PROPOSED` |
| BA | Nguyễn Đình Hùng | `REVIEWED-SUFFICIENT / CHANGES REQUIRED` | `PENDING` |
| Tech Lead independent publisher | Tech Lead | `PUBLISHED-VERIFIED / CHANGES REQUIRED` | `PENDING` |
| QA | QA1/QA2 | `REVIEWED-SUFFICIENT / CHANGES REQUIRED` | `PENDING` |
| FE consumer | FE | `REVIEWED-SUFFICIENT / CHANGES REQUIRED` | `PENDING` |
| BAS | Nguyễn Thị Hải Yến | `TRACEABILITY-PINNED / CHANGES REQUIRED` | `PENDING` |
| PM/PO Decision Owner | Nguyễn Quang Đức | `APPROVED / REJECTED / CHANGES REQUIRED` | `PENDING` |

BE1 and CI do not sign for independent roles.

## 8. Traceability

- WWHN-17: business baseline, append-only rule and line-level `PENDING_RETURN`.
- WWHN-31: official OpenAPI/schema/version/hash/changelog/publisher separation.
- WWHN-33: positive/negative/RBAC/idempotency/race/rollback/audit/reconciliation testability.
- WWHN-35: permission, data scope, audit and error envelope.
- WWHN-40: FE/BE/QA consumer contract and compatibility/adoption gap.
- WWHN-136: BAS pins all links with reviewer/date/result.

Detailed matrix: `docs/api-contract/releases/DEC-REV-001/1.3.0-proposed/TRACEABILITY.md`.

## 9. Exact-SHA evidence

Workflow: `.github/workflows/wwhn-134-reversal-decision-contract.yml`

Expected artifact after the release commit:

`wwhn-134-contract-evidence-<exact-40-character-sha>`

The artifact includes release files, manifest/checksums, exact commit metadata, commit stat, changed files, source-tree digest and verification logs.

## 10. Jira comment payload after commit

### WWHN-134 — independent review request

```text
[WWHN-134 DEC-REV-001 1.3.0-proposed RELEASE]
Exact commit SHA: <FULL_SHA>
Immutable path: docs/api-contract/releases/DEC-REV-001/1.3.0-proposed/
RELEASE_MANIFEST.json SHA-256: bd0e356e6301c4e7e142b57108c69684c2624a64c0ea2349a2be4b046472cf95
SHA256SUMS SHA-256: 26e39d4de842f589fdecf797388fc819975f0cdfba18ae764568e01228b55c4f
Workflow/artifact: wwhn-134-contract-evidence-<FULL_SHA>
Requested results:
- Tech Lead: PUBLISHED-VERIFIED | CHANGES REQUIRED
- QA/FE/BA: REVIEWED-SUFFICIENT | CHANGES REQUIRED
Each comment must include reviewer, date, exact SHA, manifest hash and evidence.
Runtime implementation is NOT_CLAIMED.
```

### WWHN-26 — PM/PO formal outcome

```text
[DEC-REV-001 FORMAL OUTCOME REQUIRED]
Exact commit SHA: <FULL_SHA>
Release: 1.3.0-proposed
Manifest SHA-256: bd0e356e6301c4e7e142b57108c69684c2624a64c0ea2349a2be4b046472cf95
Outcome: APPROVED | REJECTED | CHANGES REQUIRED
Decision owner/date/effective scope/conditions/evidence are mandatory.
```

### WWHN-136 — BAS traceability

```text
[DEC-REV-001 TRACEABILITY PIN REQUIRED]
Exact commit SHA: <FULL_SHA>
Manifest SHA-256: bd0e356e6301c4e7e142b57108c69684c2624a64c0ea2349a2be4b046472cf95
Pin WWHN-17/31/33/35/40 using docs/api-contract/releases/DEC-REV-001/1.3.0-proposed/TRACEABILITY.md.
Result: TRACEABILITY-PINNED | CHANGES REQUIRED.
Include BAS reviewer/date/comment IDs.
```

## 11. Closure statement

BE1-controlled scope is complete and reproducibly verifiable. The overall ticket/decision must remain pending until independent reviewers and PM/PO provide their own exact-artifact dispositions. No proposal artifact is used to claim implementation Done.
