# WWHN-238 — QA disposition (đóng `DEV237-P1-04`)

| Field | Value |
|---|---|
| QA ticket | `WWHN-238` — test truy vấn/báo cáo chênh lệch kiểm kê |
| Parent DEV ticket | `WWHN-237` |
| Disposition | **DEFERRED / N/A cho MVP v1.12** |
| Trạng thái Jira cần đặt | `Deferred` (hoặc `Won't Do` kèm lý do) — **không** để `Blocked`, **không** để `Pending` |
| Căn cứ | `REQ-SRS-037` (Must), `AC-SRS-031` "không có stocktake report", `DISP-STK-237` |
| Điều kiện re-open | Đúng bộ 4 điều kiện tại `DISP-STK-237 §2` |

## 1. Vì sao không phải Blocked

`Blocked` nghĩa là công việc hợp lệ đang chờ một phụ thuộc. Ở đây capability mà `WWHN-238` định
test **đã bị controlling source loại khỏi phạm vi**, nên không có gì để chờ và không có build nào
sẽ chứa nó. Để `Blocked`/`Pending` khiến mỗi vòng rà soát sau lại mở lại đúng câu hỏi này —
chính là điều đã xảy ra ở `WWHN-232` và `WWHN-235` trước khi có `DISP-STK-230` / `DISP-STK-233`.

QA1 vì vậy **không** chạy exact-build test cho chức năng variance. Thay vào đó phạm vi QA của
`WWHN-237` là **negative gate + regression của đường thay thế**.

## 2. Phạm vi QA thật sự phải chạy

### 2.1 Negative gate — capability không tồn tại

| # | Kiểm | Cách chạy | Kỳ vọng |
|---|---|---|---|
| N1 | Route/API | `Wwhn237VarianceReportDeferredGateTest::test_no_route_exposes_a_variance_or_stocktake_surface` | 0 route chứa mảnh kiểm kê |
| N2 | Report code lạ | `test_variance_report_codes_are_not_routable` | `/reports/stocktake-variance` không có route chuyên biệt; dispatcher trả `404 RESOURCE_NOT_FOUND` |
| N3 | Registry/hub/export | `test_report_registry_hub_and_export_paths_carry_no_variance_code` | 9 report code MVP, 0 mã variance |
| N4 | KPI dashboard | `test_dashboard_quick_has_no_stocktake_variance_field` | không có `stocktake_variance` |
| N5 | Permission | `test_no_variance_permission_is_seeded` | 0 key `%stocktake%` / `%variance%` |
| N6 | Entity | `test_no_variance_entity_survives_migration` | 0 bảng `stocktake_*` / `variance_*` |
| N7 | Contract bàn giao | `node scripts/verify-wwhn237-variance-report-disposition.mjs` | PASS, historical drift chỉ trong allowlist |
| N8 | Hợp đồng FE | `npm run wwhn-237:verify` | PASS 17 check |

### 2.2 Regression — đường thay thế vẫn sống

| # | Kiểm | Cách chạy | Kỳ vọng |
|---|---|---|---|
| R1 | Reconciliation còn tính chênh lệch ledger-vs-balance | `test_reconciliation_variance_between_balance_and_ledger_stays_available` | công thức `variance = COALESCE(balance.on_hand_qty,0) - SUM(qty_delta)` và `reconciliation_status` còn nguyên; `/reports/inventory-reconciliation` giữ `report.view` |
| R2 | Reversal append-only là đường sửa sai duy nhất | `test_append_only_reversal_stays_the_only_correction_path` | 3 endpoint reverse còn sống, `reversal-documents` giữ `inventory.reversal.post` |
| R3 | Không hồi quy luồng tồn kho | `php artisan test --filter=Flow05InventoryControlAlignmentTest` | PASS |
| R4 | Neo governance | `test_disposition_record_and_controlling_requirement_stay_traceable` | disposition tồn tại, SRS còn phát biểu REQ-SRS-037 |

> R1 là mục dễ làm sai nhất: `variance` của **reconciliation** (số dư chiếu vs sổ ledger) là cơ
> chế mà chính `AC-SRS-037` chỉ định làm thay thế, nên **phải còn**. Chỉ `variance` của **kiểm kê**
> (đếm tay vs hệ thống) mới bị cấm. Gỡ nhầm R1 là một P0 mới, không phải "dọn sạch hơn".

## 3. Bằng chứng QA1 phải nộp

```text
Exact BE SHA:  <sha>          (docs/evidence/WWHN-237/SOURCE_REGISTER.json)
Exact FE SHA:  <sha>          (docs/evidence/WWHN-237/FE_CLOSURE_IDENTITY.json)
Driver/env:    sqlite :memory: (mặc định phpunit.xml) hoặc PostgreSQL — ghi rõ cái nào
Raw log:       docs/evidence/WWHN-237/logs/*.txt
Kết quả:       N1..N8 PASS/FAIL · R1..R4 PASS/FAIL
DB/API:        docs/evidence/WWHN-237/CONTRACT_NEGATIVE_LOCK.json
```

Không dùng kết quả của `WWHN-236`/`WWHN-233` thay cho `WWHN-238`: ba ticket khoá ba chiều khác
nhau (hành động / đếm-đếm lại / truy vấn-báo cáo).

## 4. Khi nào QA1 mở lại phạm vi dương

Chỉ khi đủ bộ 4 điều kiện ở `DISP-STK-237 §2`. Khi đó `WWHN-238` quay lại đúng nguyên văn yêu cầu
ban đầu — công thức variance, RBAC, stale/version conflict, pagination, reconcile — và phải đóng
bằng evidence dương trên artifact chạy thật, không phải bằng tài liệu này.
