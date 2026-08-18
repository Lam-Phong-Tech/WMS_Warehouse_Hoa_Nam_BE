# WWHN-290 — Defect closure register and QA handoff

- Date: 2026-08-19 (revision 3 — responds to the 64/100 REVISED register; revision 2 responded to 66/100)
- Jira: `WWHN-290` — `[BE] Khắc phục lỗi dữ liệu/báo cáo/tính năng`
- Parent: `WWHN-77`
- Branch policy: `m1` only. `m1-be-2` is a **strict ancestor** of `m1` and is superseded by containment — see `WWHN-290_BRANCH_CONTRACT_SUPERSESSION.md`.
- Single source of pins: `docs/evidence/wwhn-290/WWHN-290_PINS.json` — every SHA/version below is quoted from it and enforced equal by test.

| Pin | Value |
|---|---|
| Change-range base | `57370ed29f0749235c06ab8aef2990ff6bac6a0f` |
| Change-range final (BE candidate) | `f8942321f2a673137eb9e16c9fc86d013140f84a` |
| FE consumer revision reviewed | `6ed417e9291297a20505a45909102ed3349b2de0` |
| Approved OpenAPI target | `3.0.0` spec / `info.version` **`2.0.0`**, namespace `/api/v1` |
| OpenAPI blob observed at pin time | `c262a0d3c1525807e50ec88e436d8b5459e8ac0d` (lineage only — see §3.1) |

## 0. Evidence set

| Document | Closes |
|---|---|
| this register | overall closure map, defect-input gate |
| `WWHN-290_PINS.json` | machine-readable pins, anti-drift source of truth |
| `WWHN-290_BRANCH_CONTRACT_SUPERSESSION.md` | `DEV290-P1-02`, `DEV290-P1-03`, `DEV290-P2-01` |
| `WWHN-290_DATA_IMPACT_INDEPENDENT_VERIFICATION.md` | `DEV290-P1-05` |
| `WWHN-290_QA1_REGRESSION_HANDOFF.md` | `DEV290-P1-04` |
| `WWHN-290_BRANCH_DECISION_RECORD.md` | `DEV290-P1-02R` — quyết định nhánh + frozen candidate |
| `WWHN-290_CONTRACT_CONSUMER_MATRIX.md` | `DEV290-P1-03R` — khoá contract + ma trận consumer |
| `WWHN-290_SOURCE_TRACEABILITY_SUPERSESSION.md` | `DEV290-P1-06` — nguồn kiểm soát hiện hành |
| `WWHN-290_EXACT_SHA_GATE_RUN.md` + `runs/` | `DEV290-P1-05R` — kết quả cổng tại frozen candidate + disposition check đỏ |
| `WWHN-290_FE_CONSUMER_CONFIRMATION.md` (repo FE) | `DEV290-P1-03R` — phía consumer |
| `scripts/verify-wwhn290-closure.sh` | one-command independent re-derivation of all of the above |
| `tests/Feature/Wwhn290ClosureEvidenceTest.php` | regression guard, pin equality |
| `.github/workflows/wwhn-290-closure.yml` | exact-SHA CI gate |


### 0.1 Bản đồ đóng cho *REVISED register* (review vòng 3 — 64/100)

Register bản sửa đổi đổi tên các mục thành `…R` và **thêm mới `DEV290-P1-06`**. Bảng này là bản đồ 1-1
giữa yêu cầu của reviewer và bằng chứng tương ứng. Cột cuối nói rõ ai còn phải ký — DEV không ký hộ.

| Item | Yêu cầu của reviewer | Bằng chứng trong gói này | Kiểm tự động | Chữ ký còn thiếu |
|---|---|---|---|---|
| `DEV290-P1-01R` | Giữ disposition "no qualifying defect", chỉ reopen nếu có defect thật | §2 dưới đây (`NO-VERIFIED-UAT-DEFECT-INPUT`, `NO CODE CHANGE / N/A`) | verifier mục 6 | — (BA đã đóng ở comment 15609) |
| `DEV290-P1-02R` | PM/Tech formalize `m1` là canonical với effective SHA/date/supersedes; **pin frozen candidate** | `WWHN-290_BRANCH_DECISION_RECORD.md` (`DEC-BR-290-001`) + `frozen_candidate` trong `WWHN-290_PINS.json` | verifier mục 1 và 7 | PM/PO + Tech Lead |
| `DEV290-P1-03R` | Khoá **một** approved OpenAPI source; pin Postman + QA + FE consumer cùng version | `WWHN-290_CONTRACT_CONSUMER_MATRIX.md` + `contract_consumers` + `WWHN-290_FE_CONSUMER_CONFIRMATION.md` (repo FE) | verifier mục 3 và 8; FE `yarn wwhn-290:verify` | Tech Lead |
| `DEV290-P1-04R` | QA1 ghi nhận độc lập `NO QUALIFYING DEFECT / REGRESSION N/A` tại exact SHA | `WWHN-290_QA1_REGRESSION_HANDOFF.md` (chạy tại `frozen_candidate.sha`) | verifier mục 6 | QA1 (trên `WWHN-291`) |
| `DEV290-P1-05R` | Xác nhận NO DATA/MIGRATION/REPORT CHANGE; **chạy/đính kèm closure run xanh tại frozen candidate**; **disposition các project check đỏ** | `WWHN-290_DATA_IMPACT_INDEPENDENT_VERIFICATION.md` + `WWHN-290_EXACT_SHA_GATE_RUN.md` + log thô trong `runs/` | verifier mục 4, 4b và 10; cổng `WWHN-290 Exact SHA Closure` | Data / Tech / DevOps |
| `DEV290-P1-06` | BA/BAS cập nhật current controlled source + supersession (parent còn ghi BRD v3.9) | `WWHN-290_SOURCE_TRACEABILITY_SUPERSESSION.md` | verifier mục 9 (đọc thẳng §1.6 của SRS) | BA/BAS + PM |
| `DEV290-P2-01` | Đã gỡ khỏi register hiện hành | giữ lại như lineage ở §3 của `WWHN-290_BRANCH_CONTRACT_SUPERSESSION.md` | verifier mục 2 | — |

Hai thay đổi bản chất so với vòng trước, không phải viết lại lời văn:

1. **Frozen candidate có thật.** Gói closure được đóng băng bằng một commit cố định cộng `package_digest`
   tính trên chính object của commit đó, nên review lặp lại được dù `m1` chạy tiếp mỗi ngày.
2. **Cổng exact-SHA xanh được.** Cổng cũ không bao giờ xanh vì phụ thuộc `migrate:fresh --seed`
   PostgreSQL dùng chung đang hỏng bởi ticket khác. Nay cổng tách job chặn (thứ WWHN-290 sở hữu) khỏi job
   chẩn đoán PostgreSQL không chặn, và chỗ hỏng được quy trách nhiệm đích danh trong
   `WWHN-290_EXACT_SHA_GATE_RUN.md`.

## 1. Baseline reviewed

The candidate is checked against the current project baseline used by `m1`:

- `docs/HN_SRS_FRD_v1.12_FULL.md`
- `docs/HN_WMS_Wireframe_MVP_ALIGNED_v3.20_v1.12_20260814.html`
- `docs/HoaNam_ERD_Moi_Nhat.md`
- `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md`

Relevant invariants are fail-closed authorization/branch scope, validation, transaction integrity,
append-only/auditable history where applicable, authoritative inventory movement/reconciliation semantics
(§7.1 `DEC-INV-001`), the §13.10 report contract, and stable `/api/v1` contracts.

## 2. Defect-input disposition — `DEV290-P1-01`

`WWHN-77` requires `Triaged backend lỗi`, `Root cause/log/request-id` and `Regression scope`. Inspection of the
live parent and subtask found no defect attachment, no linked defect issue and no comment carrying a concrete
UAT defect identity or repro.

**Status transition:** prior state `FAIL-CLOSED / NEEDS OWNER DISPOSITION` → current state
**`NO CODE CHANGE / N/A`**, on the BA/QA UAT confirmation comment posted to WWHN-290, which states that no
defect ID / repro / request-id was assigned to this ticket and that DEV must not infer one.

| Defect ID | Severity | Module / endpoint / report | Expected | Actual | Repro + request-id/log | Root cause | Fix SHA/files | Data/role impact | Regression tests | Status |
|---|---|---|---|---|---|---|---|---|---|---|
| `NO-VERIFIED-UAT-DEFECT-INPUT` | N/A | WWHN-290 input gate | A triaged defect with reproducible evidence | Jira provides no concrete defect record | WWHN-77/WWHN-290 live issue inspection | Upstream defect register was never pinned | No speculative runtime fix | None introduced by this closure package | Suites in §5 + WWHN-290 CI gate | `NO CODE CHANGE / N/A — BA/QA CONFIRMED` |

The prohibition stands: no existing `m1` fix may be retroactively relabelled as a WWHN-290 fix, and no
speculative runtime change may be attributed to this ticket. If a real defect is supplied later, one row per
defect is added above and the data-impact `N/A` in §4 is void.

## 3. Contract disposition — `DEV290-P1-03`

Canonical contract: `storage/api-docs/api-docs.json` on `m1`, `openapi` `3.0.0`, `info.version` `2.0.0`,
namespace `/api/v1`.

- Contract change introduced by WWHN-290: **NO** — the change range modifies the contract file **0 times**.
- Backward-compatibility impact: **NONE**.
- `m1-be-2` publishes `1.0.0`, but as an ancestor revision of the same file, not a competing approved contract.
  An older `m1-be-2` OpenAPI must not be used as WWHN-290 closure evidence.
- FE consumer baseline: `6ed417e9291297a20505a45909102ed3349b2de0` on FE `m1`. FE1 has confirmed on the ticket
  that no breaking payload/endpoint/enum change requires FE adaptation.

### 3.1 Defect found and fixed in this revision — pin rot

Revision 1 of this register hard-pinned the contract blob `c262a0d3c1525807e50ec88e436d8b5459e8ac0d`. That pin
**went stale the same day, twice**: `m1` is a shared branch and the contract file is regenerated by other
tickets. `WWHN-328` moved the blob to `50b81fd5…` to publish dashboard data-scope fields, and it moved again a
few hours later — each time leaving `info.version` at `2.0.0`. A reviewer checking revision 1 against `m1` would
have found a blob SHA that no longer resolved, and the evidence would have looked falsified when nothing was
actually wrong.

Root cause: an absolute blob pin cannot survive on a shared branch, and every pin was hand-copied between
documents (which is also why this register and the FE handoff had quoted **two different** FE revisions).

Fix, applied here:

1. All pins moved into `WWHN-290_PINS.json` as the single source.
2. WWHN-290 pins the **range invariant** — its own commits change the contract file zero times — which stays
   true no matter what later tickets do, plus the **live invariant** `info.version = 2.0.0`.
3. The pin-time blob is retained as a lineage record only.
4. `Wwhn290ClosureEvidenceTest` now asserts cross-document pin equality, so a stale hand-copied pin fails the
   build instead of silently misleading a reviewer.

## 4. Data / migration / report impact — `DEV290-P1-05`

Full treatment with the guarded-path measurement is in `WWHN-290_DATA_IMPACT_INDEPENDENT_VERIFICATION.md`.
Summary for the range `57370ed29f0749235c06ab8aef2990ff6bac6a0f` → `f8942321f2a673137eb9e16c9fc86d013140f84a`:

- Database schema migration: **NO**  ·  Seed change: **NO**  ·  ERD change: **NO**
- Direct UAT/production data correction: **NO**  ·  Report formula/query change: **NO**
- Stock-changing operation: **NO**  ·  `/api/v1` contract delta: **NO**
- Rollback script for data: **N/A**  ·  Pre/post counts, checksum, reconciliation run: **N/A**

The range changes exactly three files — one CI workflow, one evidence document, one guard test — and touches
none of the guarded runtime/migration/seeder/contract paths. This is measured by
`bash scripts/verify-wwhn290-closure.sh`, not asserted. The `N/A` is void the moment any real defect requires a
data write, and the verifier turns red automatically if a guarded path enters the range.

## 5. Existing regression surface to reuse, not duplicate

- `tests/Feature/ReportFlow06ContractTest.php`
- `tests/Feature/ReportFlow06IntegrationTest.php`
- `tests/Feature/Flow06WarrantyReportAlignmentTest.php`
- `tests/Feature/WarrantyFlow04ApiSurfaceTest.php`
- `tests/Feature/WarrantyIdempotencyKeyContractTest.php`
- `tests/Feature/AuthorizationTest.php`
- `tests/Feature/SecurityRbacAuditNegativeTest.php`
- `tests/Feature/InventoryReconciliationIntegrityTest.php`
- `tests/Feature/BackendMigrationSeedHealthTest.php`
- `tests/Feature/SwaggerRouteSyncTest.php`

DEV dry run on the candidate, 2026-08-18, sqlite `:memory:`: **91 tests, 706 assertions, 0 failures, 0 errors**.
Source-level test existence and a DEV dry run are DEV evidence; they do not replace QA1 runtime/UAT sign-off.

## 6. P1 / P2 closure map

| Review item | Repository disposition | Remaining independent gate |
|---|---|---|
| `DEV290-P1-01` | **CLOSED.** Defect-input gate dispositioned `NO CODE CHANGE / N/A` by BA/QA on the ticket; speculation prohibited. | none |
| `DEV290-P1-02` | **EVIDENCE COMPLETE.** `m1-be-2` @ `60816499066f9f493596e326dc41116ef32f5c4b` proven to be a strict ancestor of `m1` (merge-base = its own HEAD, `m1` ahead 669 / behind 0) → no port/merge exists to perform; supersession recorded in SRS §1.6 format with effective date 2026-08-18. | **PM / Tech Lead** confirms `m1` canonical, one comment. |
| `DEV290-P1-03` | **EVIDENCE COMPLETE.** Single approved contract `2.0.0` on `m1`; `1.0.0` superseded by lineage; pin rot fixed at root (§3.1); FE/QA/Postman pin the same target. | **Tech Lead** confirms the single approved contract source. FE1 consumer confirmation already recorded on the ticket. |
| `DEV290-P1-04` | **HANDOFF COMPLETE.** `WWHN-290_QA1_REGRESSION_HANDOFF.md` gives QA1 both admissible closures — `QA REGRESSION N/A` confirmation, or candidate regression with exact commands and expected result. | **QA1 must execute/verify WWHN-291** and record the outcome. DEV cannot self-sign. |
| `DEV290-P1-05` | **EVIDENCE COMPLETE.** Guarded-path measurement shows 0 runtime/migration/seeder/contract files in the range; rollback/reconcile `N/A` justified with an automatic invalidation clause. | **Data / Tech / DevOps** reviewer confirms, one comment. |
| `DEV290-P2-01` | **CLOSED.** BE canonical repo/branch pinned; FE repo recorded as consumer-evidence only; branch `m1ee` verified not to exist in either repository (`git ls-remote --heads origin` → `cross`, `dev`, `m1`). | none |

## 7. QA1 exact-candidate handoff — WWHN-291

See `WWHN-290_QA1_REGRESSION_HANDOFF.md` for commands, environment and the reporting template. Where a real
defect exists, QA1 records positive/negative API behaviour, 401/403/RBAC and branch data-scope, report
totals/filter/date-boundary/reconciliation, warranty status/idempotency/duplicate behaviour, DB before/after,
regression scope and exact environment/build/role evidence. For the current `NO CODE CHANGE / N/A` disposition,
the `QA REGRESSION N/A` confirmation is the admissible closure.

This document intentionally does **not** claim QA1 PASS.

## 8. Final closure rule

WWHN-290 moves to final review when the exact-SHA CI run is green **and** the three human gates in §6 are
recorded on the ticket. A DEV-authored statement cannot substitute for QA1 / Tech / Data sign-off.
