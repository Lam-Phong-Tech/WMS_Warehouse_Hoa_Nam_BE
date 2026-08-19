# WWHN-267 — Retest Report vòng R2 (DEV267-P1-05R)

- Task: WWHN-267 · Parent: WWHN-70 · QA source: HN-QA-005-ST01 · Branch: `m1`
- Ngày chạy: 2026-08-19
- Register item: DEV267-P1-05R — retest trên exact candidate: positive / negative / RBAC / API / DB / data reconciliation
- Schema: 1.0.0

## 0. Ranh giới sự thật (truth boundary)

Báo cáo này là **lượt chạy controlled phía DEV trên exact candidate**, dùng làm evidence pack cho QA.
Lượt retest **độc lập** của QA1 (WWHN-264) / QA2 (WWHN-265) vẫn là cổng cuối — QA xác nhận bằng
comment sign-off trên Jira, DEV không tự đánh PASS thay QA. Register R2 ghi rõ **vòng này không
yêu cầu CI/CD** (DEV267-P1-04R2), và GitHub Actions đang hết quota (3.000/3.000 phút) nên **không
lượt CI nào được kích hoạt** — mọi số liệu dưới đây là local controlled run.

## 1. Exact candidate và chứng minh tương đương

- Candidate rule (bất biến): commit cuối cùng trên `m1` chạm `docs/evidence/WWHN-267/**`,
  `tests/Feature/Wwhn267ContractClosureTest.php`, `scripts/verify-wwhn-267-closure.mjs`.
- Thời điểm đo: candidate = `70278a0ff815ee25df43fadb49f22e41f91ff94b`; HEAD lúc chạy = `22564b46898f616c0df2880f797fcc0f1fcf9507`.
- Chứng minh tương đương exact-candidate: `git diff 70278a0 HEAD -- docs/evidence/WWHN-267/ tests/Feature/Wwhn267ContractClosureTest.php scripts/verify-wwhn-267-closure.mjs app/Http/Middleware/ApiConventionMiddleware.php` → **rỗng** (0 dòng). Chạy tại HEAD là tương đương chạy tại candidate cho phạm vi WWHN-267.
- Sửa pin rot: pin cũ `9ea9c18` **không phải ancestor** của `m1` (`git merge-base --is-ancestor` → false); commit in-ancestry tương đương là `4fcaef0` (đã verify → true). Ghi thành defect BE267-D08.
- FE: candidate `a2b555b28e3c680a1c303873c04d7e64d71bb7d2`; FE HEAD lúc chạy `cf0fe8d`; 3 file fix của FE267-D06 không có commit nào sau `a2b555b` (git log từng file — `a2b555b` là commit mới nhất chạm cả 3).

## 2. Môi trường

| Trục | Giá trị |
|---|---|
| OS | Windows 11 (local controlled) |
| PHP | 8.3.30 (cli, ZTS VC++ 2019 x64) |
| DB test BE | sqlite `:memory:` theo `phpunit.xml` (driver mặc định; nhánh PostgreSQL xem ghi chú §6) |
| FE runner | vitest 4.1.10 |
| Node verifier | `scripts/verify-wwhn-267-closure.mjs` (thuần Node, không phụ thuộc autoload PHP) |
| Cây làm việc | BE: sạch trong phạm vi WWHN-267 tại thời điểm đo (chỉ có WIP của phiên WWHN-184 trên `docs/HN_SRS_FRD_v1.12_FULL.md` +1 dòng và evidence WWHN-190 untracked — không chạm file WWHN-267; lượt verifier đầu xác nhận "trên cây làm việc sạch"). SAU khi đo xong, phiên song song WWHN-190 bắt đầu sửa `Modules/Warehouse` (Reversal) — mọi số liệu ở §3 được chạy TRƯỚC drift đó, và chứng minh tương đương §1 so với blob đã commit nên không bị ảnh hưởng. FE: sạch. |

## 3. Kết quả thô (raw)

### 3.1 BE — hợp đồng WWHN-267 (RT267-02 RBAC matrix, RT267-04 pairing, kiểm soát bù DELTA-02)

```
$ php artisan test tests/Feature/Wwhn267ContractClosureTest.php
{"tool":"phpunit","result":"passed","tests":8,"passed":8,"assertions":120,"duration_ms":7418}
```

8 test: 3 pairing (reserved codes đúng status / không dual-status ngoài khai báo / mọi status có
governance), middleware fallback, divergence register khớp mã thật, **RBAC 18 ô cell-by-cell**,
role baseline khớp pin, LEADERSHIP không đọc được warranty PII.

### 3.2 BE — 401 anonymous + phạm vi regression (RT267-01)

```
$ php artisan test tests/Feature/Wwhn268RegressionContractTest.php
{"tool":"phpunit","result":"passed","tests":4,"passed":4,"assertions":70,"duration_ms":1758}
```

Bao gồm `test_protected_surfaces_return_401_auth_required_with_canonical_envelope` trên 6 bề mặt
(inbound/outbound/balances/reversal/warranty/reports) — đúng danh sách endpoint của RT267-01.

### 3.3 BE — DB / data reconciliation (RT267-05)

```
$ php artisan test tests/Feature/InventoryReconciliationIntegrityTest.php tests/Feature/Wwhn204LedgerReconciliationCoverageTest.php
{"tool":"phpunit","result":"passed","tests":8,"passed":8,"assertions":58,"duration_ms":2110}
```

Đối soát ledger ↔ balance projection ↔ subledger; không movement nào sinh từ request bị từ chối.

### 3.4 Node verifier — phát hiện và đóng BE267-D07 trong cùng vòng

Lượt 1 (trước khi regenerate catalog):

```
$ node scripts/verify-wwhn-267-closure.mjs
WWHN-267 closure verifier: FAIL
  - scripts/generate-error-code-catalog.mjs --check thất bại trên cây làm việc sạch: catalog mã lỗi không còn khớp source
```

Fix: `node scripts/generate-error-code-catalog.mjs` → "Đã sinh 248 error code". Diff code-level:
**0 mã thêm/bớt, 0 status đổi** (kiểm bằng grep `"code":` trên diff — rỗng); chỉ trôi vị trí call site.

Lượt 2 (sau regenerate):

```
$ node scripts/verify-wwhn-267-closure.mjs
WWHN-267 closure verifier: PASS
  - register defect đủ trường, không chuỗi độn, fix/bằng chứng trỏ file có thật
  - mọi dòng FIXED có lượt retest PASS; mọi dòng còn chờ có cổng ngoài + kiểm soát bù
  - mọi dòng khai impact hợp đồng/dữ liệu tường minh (DEV267-P1-06)
  - register ghép cặp §8.2 và pin role baseline khớp seeder, khác biệt đều có chủ sở hữu
  - disposition nguồn neo REQ-SRS-037; nhánh canonical m1 chứng minh được không mất commit
```

### 3.5 FE — hợp đồng mã 403 (RT267-06, phần contract)

```
$ npx vitest run src/shared/api/__tests__/wwhn-267-forbidden-code-contract.test.ts
✓ src/shared/api/__tests__/wwhn-267-forbidden-code-contract.test.ts (7 tests) 5ms
Test Files  1 passed (1) · Tests  7 passed (7)
```

## 4. Ánh xạ RT267-01..07 → kết quả vòng R2

| Ca | Trục | Kết quả controlled R2 | Phần còn thuộc QA độc lập |
|---|---|---|---|
| RT267-01 | Negative 401 | PASS (§3.2, 6/6 bề mặt, envelope chuẩn) | Chạy lại trên Beta đã deploy |
| RT267-02 | RBAC matrix | PASS (§3.1, 18/18 ô, mã 403 + request_id) | Chạy lại trên Beta với account thật |
| RT267-03 | Negative no-write (403/404/412) | Được phủ bởi suite hiện có (TC-P1-003/011/027 automated trong Feature suite — không chạy riêng ở vòng này) | QA chạy TC-P1-003/011/027 trên Beta theo §13.3 |
| RT267-04 | Contract pairing | PASS (§3.1 + §3.4 verifier, catalog 248 mã khớp source) | Đối chiếu response thật trong lượt regression |
| RT267-05 | DB reconciliation | PASS (§3.3) | Đối soát trên DB Beta sau lượt negative |
| RT267-06 | UI E2E | PASS phần contract (§3.5, 7/7) | E2E thật trên màn warehouse-config (QA2) |
| RT267-07 | Scope disposition | Ma trận scope mới: `SCOPE_AND_SOURCE_MATRIX.json` — Stocktake giữ dòng với disposition SUPERSEDED_BY_REQ-SRS-037 | QA1 + BA rà bộ ca WWHN-70, đánh nhãn theo ma trận |

## 5. Disposition từng defect sau R2

| Defect | Sev | Trạng thái | Căn cứ |
|---|---|---|---|
| BE267-D01 | P1 | FIXED — retest PASS | §3.1, §3.4 |
| BE267-D02 | P2 | FIXED — retest PASS (mutation check vòng 1 vẫn hiệu lực) | §3.1 |
| BE267-D03 | P1 | FIXED_PENDING_BA_DISPOSITION (W267-EXT-01) | §3.1; đổi tên mã thuộc BA+TL |
| BE267-D04 | P1 | PARTIAL_FIXED_PENDING_BA_DISPOSITION (W267-EXT-02) | §3.1; gỡ quyền thuộc BA+TL |
| BE267-D05 | P1 | FIXED — retest PASS | §3.1 (18 ô) |
| FE267-D06 | P2 | FIXED — retest PASS | §3.5 |
| BE267-D07 | P2 | FIXED trong R2 (regenerate catalog, commit vòng R2) | §3.4 |
| BE267-D08 | P1 | FIXED trong R2 (pin 4fcaef0 in-ancestry + quy trình verify ancestry) | §1 |

## 6. Ghi chú đo lường (kế thừa bẫy đã biết)

- PHPUnit chạy theo **đường dẫn file cụ thể**, không `--filter` — file test dở của ticket khác trong
  `tests/` có thể làm fatal cả suite khi nạp class (bài học W267-EXT-05).
- Nhánh PostgreSQL không chạy ở vòng này: DB `hoa_nam_audit` dùng chung đang có drift migration từ
  phiên song song (bài học 19/08 — không tự `migrate:fresh` DB chung). Phạm vi WWHN-267 (contract/
  RBAC/pairing) không phụ thuộc driver; reconciliation đã PASS trên sqlite.
- Không dùng worktree + junction `vendor` cho lượt PHP: `autoload_psr4.php` chứa đường dẫn tuyệt đối
  làm test nạp source từ cây chính (bẫy đã ghi ở RUN_EVIDENCE vòng 1). Thay bằng chứng minh
  tương đương diff-rỗng ở §1.

## 7. Exit criteria đối chiếu

- [x] Đủ trục positive/negative/RBAC/API/DB/reconciliation có kết quả kèm bằng chứng thô + build/SHA/env (§2, §3)
- [x] Defect mới phát sinh trong lượt chạy (D07, D08) ghi vào `DEFECT_REGISTER.json` đúng schema
- [x] Không dòng FIXED nào thiếu lượt retest PASS tương ứng (§5)
- [ ] QA1/QA2 xác nhận độc lập trên Beta (comment sign-off — ngoài quyền tự đóng của DEV)
