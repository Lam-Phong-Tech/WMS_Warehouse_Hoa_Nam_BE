# WWHN-202 — Evidence matrix (DEV202-P1-01R2 / P1-05R2)

| Field | Value |
|---|---|
| Jira task | `WWHN-202` — [DEV] Hỗ trợ đối soát/availability (output "Balance hỗ trợ", HN-OUT-003) |
| Task cha | `WWHN-47` — [OUTBOUND] Post Issue atomic, không âm tồn, idempotent |
| QA song hành | `WWHN-203` |
| Repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` + `…_FE` · branch **`m1`** (không tạo nhánh riêng) |
| Decision record | `DEC-STK-202` — `docs/decision-records/DEC-STK-202-post-issue-error-taxonomy-and-item-attribution.md` |
| Baseline nghiệp vụ | SRS/FRD v1.12 `REQ-SRS-011/012`, `AC-SRS-011/012`, `AC-GWT-OUT-001`, `§7.5`, `§8.1`, `§8.2`, `§13.10 RPT-INV-01`; ERD `stock_movements` (ledger append-only) / `inventory_balances` (projection); `DEC-INV-001` |
| Điểm đánh giá trước | 63/100 |
| Ngày thu bằng chứng | 2026-08-19 |

---

## 1. PR / nhánh / candidate SHA — DEV202-P1-01R2

### 1.1 Nhánh canonical: `m1`. `m1-be-2` đã bị supersede.

Đo trực tiếp, không suy đoán:

```
git merge-base --is-ancestor origin/m1-be-2 m1     → exit 0 (LÀ ancestor)
git rev-list --left-right --count origin/m1-be-2...m1 → 0   765
git rev-parse origin/m1-be-2                        → 60816499066f9f493596e326dc41116ef32f5c4b
```

Nghĩa: `m1` chứa **toàn bộ** lịch sử của `m1-be-2` và đi trước 765 commit tại thời điểm đo;
`m1-be-2` **không có commit nào** mà `m1` thiếu. Supersede comment 14283 **không mất công
việc nào** và **không cần cherry-pick**. Kết luận này trùng với disposition độc lập của
`WWHN-267` (`docs/evidence/WWHN-267/SOURCE_AND_BRANCH_DISPOSITION.json` →
`branchDisposition.disposition = CANONICAL_BRANCH_LOCKED`), đo ở thời điểm khác và cho
cùng kết quả — nên đây không phải kết luận của riêng một phiên làm việc.

Verifier tự chạy lại phép đo này: `scripts/verify-wwhn-202-closure.mjs` check **C5**.
Nếu một ngày nào đó `m1-be-2` nhận commit mới, cổng sẽ đỏ trước khi ai kịp supersede nhầm.

### 1.2 PR = N/A — lý do, và vì sao chấp nhận được

`m1` là nhánh làm việc chung của dự án; tại thời điểm thu bằng chứng có **nhiều phiên sửa
song song trên cùng một working tree** (quan sát được trong chính phiên này: `WWHN-289`
sửa mirror cast PostgreSQL, `WWHN-267` khoá hợp đồng mã lỗi, `WWHN-310` pin ERD document).
PR từ `m1` về `m1` là vô nghĩa; tạo nhánh riêng thì vi phạm ràng buộc "chỉ làm ở nhánh m1".

Thứ thay thế PR ở đây **không phải** lời hứa, mà là hai thứ chạy được:

| Thay cho | Artifact | Cách kiểm |
|---|---|---|
| PR review diff | `docs/evidence/WWHN-202/DEV202_FIX_REGISTER.md` §2 (danh sách file + lý do từng thay đổi) | đọc + `git show <candidate>` |
| CI xanh | `node scripts/verify-wwhn-202-closure.mjs` (7 check) + 2 lượt phpunit (sqlite + PostgreSQL) + 1 lượt vitest | chạy lại tại candidate SHA |

### 1.3 Candidate SHA — quy tắc thay vì hard-pin

Hard-pin `HEAD` sẽ mục ngay ngày hôm sau vì `m1` có nhiều phiên đẩy commit liên tục (bài
học `WWHN-247` / `WWHN-233` / `WWHN-191`). Quy tắc bất biến dùng ở đây:

> **Candidate SHA của WWHN-202 = commit cuối cùng trên `m1` chạm vào một trong các đường dẫn:**
> `docs/evidence/WWHN-202/**`,
> `docs/decision-records/DEC-STK-202-post-issue-error-taxonomy-and-item-attribution.md`,
> `tests/Feature/Wwhn202OutboundBalanceEvidenceTest.php`,
> `scripts/verify-wwhn-202-closure.mjs`.

Lấy giá trị hiện hành:

```bash
git log -1 --format=%H -- docs/evidence/WWHN-202 docs/decision-records/DEC-STK-202-post-issue-error-taxonomy-and-item-attribution.md tests/Feature/Wwhn202OutboundBalanceEvidenceTest.php scripts/verify-wwhn-202-closure.mjs
```

Verifier in ra chính giá trị đó (check **C7**) và có `--expect-sha <sha>` để Tech Lead/QA
đóng băng một giá trị cụ thể khi ký:

```bash
node scripts/verify-wwhn-202-closure.mjs --expect-sha <candidate>
```

| Repo | Candidate SHA (ảnh chụp lúc lập hồ sơ) | Ghi chú |
|---|---|---|
| BE `WMS_Warehouse_Hoa_Nam_BE` | `__BE_CANDIDATE__` | commit `fix(WWHN-202)…` |
| FE `WMS_Warehouse_Hoa_Nam_FE` | `__FE_CANDIDATE__` | commit `feat(WWHN-202)…` |

> Hai giá trị trên chỉ là ảnh chụp. **Nguồn sự thật là lệnh `git log -1` ở trên**, không
> phải hai ô này — đó là toàn bộ mục đích của quy tắc suy-từ-artifact. Nếu có commit bổ
> sung chạm artifact WWHN-202, lệnh trả về giá trị mới và hai ô này coi như lỗi thời.

### 1.4 Ánh xạ evidence cũ sang WWHN-202 — vì sao KHÔNG đổi tên file test

Evidence gốc nằm ở `tests/Feature/Wwhn207OutboundBalanceReconciliationTest.php` (đặt tên
theo `WWHN-207` từ thời `m1-be-2`). Task doc `docs/docs_task_jira/WWHN-202.md` đã ghi rõ
hai task mô tả **cùng một** output "Balance hỗ trợ" của HN-OUT-003.

Đổi tên file sẽ làm **hỏng 8 evidence pack đã đóng băng** đang pin đúng đường dẫn đó:
`WWHN-190`, `WWHN-191`, `WWHN-198`, `WWHN-199`, `WWHN-201`, `WWHN-205`,
`docs/evidence/WWHN-231/BASELINE_ISOLATION.md`, `docs/evidence/WWHN-239/*`. Sửa evidence
đã duyệt để làm đẹp tên file là đánh đổi sai.

Ánh xạ dùng **ràng buộc cơ học**, không phải lời văn:

| Evidence | Thuộc WWHN-202 qua | Chạy bằng |
|---|---|---|
| `tests/Feature/Wwhn207OutboundBalanceReconciliationTest.php` (8 case, giữ tên lịch sử) | annotation `@group wwhn-202` + docblock traceability ở đầu class | `phpunit --group wwhn-202` |
| `tests/Feature/Wwhn202OutboundBalanceEvidenceTest.php` (11 case, **mới**, mang đúng tên task) | `@group wwhn-202` | `phpunit --group wwhn-202` |

Verifier check **C6** bắt buộc cả hai class phải còn `@group wwhn-202` — nên ánh xạ không
thể bị gỡ âm thầm.

---

## 2. Bộ file thuộc WWHN-202

### Backend

| Đường dẫn | Loại | Vai trò |
|---|---|---|
| `tests/Feature/Wwhn202OutboundBalanceEvidenceTest.php` | test (mới) | 11 case: ma trận QA1 + DB before/after + 401/403/404/422 + phân lớp 409/422 + quy tắc item_id |
| `tests/Feature/Wwhn207OutboundBalanceReconciliationTest.php` | test (sửa) | thêm `@group wwhn-202` + docblock ánh xạ; gỡ 2 helper trùng (đưa lên trait) |
| `tests/Feature/Concerns/PreparesOutboundFixtures.php` | test helper (sửa) | thêm `seedLedgerBackedBalance()` + `readyToPostIssueDocumentLedgerBacked()` — dùng chung, không nhân bản |
| `docs/decision-records/DEC-STK-202-….md` | decision (mới) | D-1 phân lớp 409/422 của Post Issue; D-2 quy tắc `stock_movements.item_id` |
| `docs/evidence/WWHN-202/CONTRACT_PIN.json` | pin (mới) | pin slice OpenAPI + phủ Postman + bảng permission + taxonomy |
| `docs/evidence/WWHN-202/openapi-wwhn202-slice.json` | pin (mới) | bản trích 8 operation, chuẩn hoá khoá — đơn vị được băm |
| `docs/evidence/WWHN-202/RECONCILIATION.sql` | evidence (mới) | 6 truy vấn đối soát, quy ước "mọi truy vấn = 0 dòng" |
| `docs/evidence/WWHN-202/RAW_*.log`, `DB_BEFORE_AFTER.json` | evidence (mới) | log thô sqlite + PostgreSQL, ảnh chụp DB trước/sau |
| `docs/evidence/WWHN-202/QA1_RETEST_PACK_WWHN-203.md` | handoff (mới) | ma trận retest QA1 chạy được |
| `scripts/verify-wwhn-202-closure.mjs` | gate (mới) | 7 check, chạy lại được ở bất kỳ SHA nào |
| `scripts/sync-wwhn-202-postman-surfaces.mjs` | tool (mới) | sinh request Postman còn thiếu từ chính OpenAPI, id tất định |
| `docs/postman/WMS_HoaNam API Contract - DEV.postman_collection.json` | contract (sửa) | +4 request (chỉ thêm, 321 dòng, 0 dòng xoá) |
| `docs/docs_task_jira/WWHN-202.md` | task doc (sửa) | §7 Amendment — đính chính phần evidence đã lệch code |

### Frontend

| Đường dẫn | Loại | Vai trò |
|---|---|---|
| `src/shared/api/wwhn-202-balance-contract-pin.ts` | pin (mới) | nửa FE: slice hash + 8 path + permission + taxonomy |
| `src/test/wwhn-202-balance-contract-pin.test.ts` | test (mới) | 22 assertion: path khớp pin, mỗi bề mặt có service thật gọi đúng hằng, normalizer giữ trường đối soát |
| `src/modules/inventory/services/inventoryService.ts` | service (sửa) | thêm `fetchInventoryReconciliation()` — biến `INVENTORY_RECONCILIATION` từ hằng chết thành bề mặt dùng được |

---

## 3. Kết quả chạy thật

| Lượt chạy | Môi trường | Kết quả |
|---|---|---|
| `phpunit tests/Feature/Wwhn202OutboundBalanceEvidenceTest.php` | sqlite `:memory:` | **11 passed, 220 assertions** — `RAW_phpunit_wwhn202_sqlite.log` |
| `phpunit --filter "<23 class outbound/inventory/reversal>"` | sqlite | **125 passed, 1100 assertions, 0 fail** |
| `phpunit <9 class>` (WWHN-202/207/205/204/201/234/199 + reconciliation) | **PostgreSQL 16**, db `hn_wwhn202` | **43 passed, 457 assertions** — `RAW_phpunit_wwhn202_pgsql.log` |
| `migrate:fresh --seed` + `inventory:reconcile --dry-run` | PostgreSQL 16 | **2 dimension kiểm tra, 0 lệch, 0 incident** — `RAW_migrate_fresh_seed_reconcile_pgsql.log` |
| `psql -f RECONCILIATION.sql` | PostgreSQL 16 | **6/6 truy vấn trả 0 dòng** — `RAW_reconciliation_sql_pgsql.log` |
| `node scripts/verify-wwhn-202-closure.mjs` | — | **PASS 7/7 check** |
| `node scripts/sync-wwhn-202-postman-surfaces.mjs --check` | — | **PASS — đủ 8/8 bề mặt** |
| `vitest run src/test/wwhn-202-balance-contract-pin.test.ts` | node | **22 passed** |
| `tsc --noEmit` | node | **0 lỗi** |

Container PostgreSQL: `wms_warehouse_hoa_nam_be-postgres-1` (PostgreSQL 16), database
kiểm thử `hn_wwhn202` (tạo mới trong phiên này, không đụng `hoa_nam_audit`).

---

## 4. Contract pin — DEV202-P1-05R2

| Artifact | Đơn vị pin | Giá trị |
|---|---|---|
| OpenAPI | **slice 8 operation**, chuẩn hoá khoá | `sha256 = b9821645638fdac8b3104c6a24bd59926a1bb3f88e35a86deb604c43ff54920a` |
| OpenAPI version | — | `2.0.0` |
| Postman DEV collection | **độ phủ bề mặt** | 8/8 (trước phiên này: 4/8) |

**Vì sao pin slice chứ không pin cả file.** `storage/api-docs/api-docs.json` sinh tự động
từ annotation của **toàn hệ thống**. Trong chính phiên này, hash cả file đã đổi hai lần vì
task khác thêm annotation ở module khác. Pin cả file ⇒ cổng đỏ hằng ngày vì lý do không
liên quan ⇒ cổng bị bỏ qua ⇒ pin vô dụng. Pin slice chỉ đỏ khi hợp đồng của **đúng 8 bề
mặt này** đổi — tức đúng lúc FE và QA1 phải xác nhận lại.

**Phát hiện thật khi lập pin:** bộ Postman DEV chỉ có **4/8** bề mặt — thiếu `subledger`,
`trace-by-code`, `reversible`, `inventory-reconciliation`. Yêu cầu "QA uses same contract"
vì thế **không thực hiện được** chứ không phải chỉ thiếu tài liệu: QA1 không có request để
gọi 4 bề mặt đó. Đã bổ sung bằng `scripts/sync-wwhn-202-postman-surfaces.mjs` (sinh từ
chính OpenAPI, `id` tất định theo `operationId`, diff **chỉ thêm** 321 dòng / xoá 0 dòng).

**Bảng permission §8.1** — nguồn chung cho FE và QA1, khoá hai đầu:

| Bề mặt | Method + path | Permission | Data scope |
|---|---|---|---|
| balance | `GET /api/v1/inventory/balances` | `inventory.view` | `EnforceInventoryWarehouseScope` |
| subledger | `GET /api/v1/inventory/balances/{id}/subledger` | `inventory.view` | `EnforceInventoryWarehouseScope` |
| movement list | `GET /api/v1/stock-movements` | `inventory.view` | `EnforceInventoryWarehouseScope` |
| movement detail | `GET /api/v1/stock-movements/{id}` | `inventory.view` | `EnforceInventoryWarehouseScope` |
| trace-source | `GET /api/v1/stock-movements/{id}/trace-source` | `inventory.trace` | `EnforceInventoryWarehouseScope` |
| trace-by-code | `GET /api/v1/stock-movements/trace-by-code` | `inventory.trace` | `EnforceInventoryWarehouseScope` |
| reversible | `GET /api/v1/stock-movements/reversible` | **`inventory.reversal.post`** | `EnforceInventoryWarehouseScope` |
| reconcile | `GET /api/v1/reports/inventory-reconciliation` | **`report.view`** | `report.view` |

Hai ô in đậm là chỗ dễ giả định sai nhất (`reversible` **không** dùng `inventory.view`;
`reconcile` **không** dùng `inventory.view`). Khoá ở BE bằng
`Wwhn202OutboundBalanceEvidenceTest::test_read_surface_permission_matrix_matches_the_registered_routes`
(đọc route thật của Laravel) và ở FE bằng `WWHN_202_READ_SURFACES`.

---

## 5. Cách chạy lại toàn bộ bằng chứng

```bash
# BE — sqlite (nhanh)
php vendor/bin/phpunit --group wwhn-202

# BE — PostgreSQL 16 (DB tham chiếu theo Techstack/Dev Guide)
docker exec wms_warehouse_hoa_nam_be-postgres-1 psql -U postgres -c "CREATE DATABASE hn_wwhn202;"
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=hn_wwhn202 \
DB_USERNAME=postgres DB_PASSWORD=postgres \
  php vendor/bin/phpunit tests/Feature/Wwhn202OutboundBalanceEvidenceTest.php \
                         tests/Feature/Wwhn207OutboundBalanceReconciliationTest.php \
                         tests/Feature/Wwhn205StockMovementItemIdInvariantTest.php \
                         tests/Feature/Wwhn201PostIssueAtomicNonNegativeStockTest.php \
                         tests/Feature/Wwhn234PostIssueIdempotencyPayloadTest.php

# BE — đối soát mismatch = 0
docker exec -i wms_warehouse_hoa_nam_be-postgres-1 psql -U postgres -d hn_wwhn202 \
  < docs/evidence/WWHN-202/RECONCILIATION.sql

# Cổng đóng task
node scripts/verify-wwhn-202-closure.mjs
node scripts/sync-wwhn-202-postman-surfaces.mjs --check

# FE
npx vitest run src/test/wwhn-202-balance-contract-pin.test.ts
npx tsc --noEmit
```

> **Lưu ý khi chạy phpunit bằng `--filter` trên `m1`:** nếu một phiên song song đang để lại
> file test hỏng, PHPUnit nạp toàn bộ thư mục `tests/` và chết trước khi lọc. Truyền **đường
> dẫn file cụ thể** (như lệnh PostgreSQL ở trên) để cô lập. Hiện tượng này đã gặp thật trong
> phiên lập hồ sơ với `tests/Feature/Wwhn310Round2ClosureTest.php`.
