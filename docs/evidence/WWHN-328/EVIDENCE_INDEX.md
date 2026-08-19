# WWHN-88 / 327 / 328 / 329 — Evidence index (DEV328-P1-05R4)

Register R4 mở P1-05 vì `issuelinks=[]`: WWHN-327 và WWHN-328 không có liên kết Jira nào, và
dependency `HN-STR-002-ST01` chưa được map. Tạo native link là thao tác trên Jira (PM/BAS).
File này là **evidence index được duyệt** đóng vai trò tương đương khi native link chưa có, và
vẫn cần thiết sau khi có link vì Jira không pin được SHA.

## 1. Cụm công việc

| Issue | Vai trò trong cụm | Sản phẩm |
|---|---|---|
| `WWHN-88` | story cha — "Dashboard nhanh" | AC nguồn của KPI matrix |
| `WWHN-327` | FE Dashboard enhancement | màn hình đọc `dashboard-quick` |
| `WWHN-328` | BE endpoint tổng hợp (**tái sử dụng**, không tạo route mới) | `GET /api/v1/reports/dashboard-quick` |
| `WWHN-329` | QA2 kiểm thử độc lập | báo cáo fixed-build (hard gate) |
| `HN-STR-002-ST01` | dependency cấu trúc | được map ở §3 |

## 2. Cặp SHA đang có hiệu lực

| Thành phần | Repo | Branch | Exact SHA | Vai trò |
|---|---|---|---|---|
| Backend | `WMS_Warehouse_Hoa_Nam_BE` | `m1` | `__BE_CANDIDATE_SHA__` | candidate WWHN-328 |
| Backend (gốc contract) | `WMS_Warehouse_Hoa_Nam_BE` | `m1` | `c855851b37920237d110f7dffe5dbbad509aebce` | commit xa nhất mà contract hiện hành đã đúng |
| Frontend | `WMS_Warehouse_Hoa_Nam_FE` | `m1` | `16a3eea9f5241ba3ca24e36c53c811a45f9ae45d` | candidate WWHN-327 — đây là **output của WWHN-327 mà WWHN-328 tiêu thụ** |

Superseded, **không** dùng làm nguồn đánh giá nữa: BE `m1-be-2` (mọi SHA), `b8cfcf0`,
`6db0965`, `127c573`; FE `3ddce52`, `99dff42`. Lý do supersede ghi ở `DIFF_MAP.md §3` (BE) và
`R4_CLOSURE.md §2` (FE).

## 3. WWHN-327 tiêu thụ đúng cái gì của WWHN-328

| Hạng mục contract | Nguồn BE | Nơi FE tiêu thụ |
|---|---|---|
| Field set 11 khoá | KPI matrix v1.2 §2 | `src/modules/dashboard/types/index.ts`, `WWHN-327_CLOSURE_REGISTER.json → contractFields` |
| `data_scope` / `scope_warehouse_ids` | KPI matrix §1 | nhãn "Phạm vi dữ liệu" trên `DashboardPage.tsx` |
| Ngoại lệ warranty GLOBAL | KPI matrix §1 | khối `role="note"` chỉ hiện khi `WAREHOUSE_SCOPED` |
| `reconciliation_warning_count` vs mảng có trần 50 | KPI matrix §2 K9/K10 | FE hiển thị `count`, **không** dùng `warnings.length` |
| `report.view` | route `permission:report.view` | `WMS_PERMISSION_CATALOG.DASHBOARD_VIEW` |
| Timezone `Asia/Ho_Chi_Minh`, kỳ mặc định 31 ngày | KPI matrix §5 | `contract.timezone`, `contract.defaultPeriodDays` trong register FE |

Ràng buộc `HN-STR-002-ST01`: dashboard chỉ được đọc số từ **một** endpoint aggregate đã có, không
sinh route mới. Khoá bằng `Wwhn328R4ClosureTest::test_dashboard_aggregate_is_served_by_exactly_one_reused_route`.

## 4. Chỉ mục file bằng chứng

| Nội dung | Đường dẫn |
|---|---|
| KPI matrix (v1.2, có sổ ký) | `docs/evidence/WWHN-328/KPI_MATRIX.md` |
| Đóng register R4 theo từng ID | `docs/evidence/WWHN-328/R4_CLOSURE.md` |
| Diff map trong bundled commit | `docs/evidence/WWHN-328/DIFF_MAP.md` |
| Pin contract mức đối tượng | `docs/evidence/WWHN-328/CONTRACT_PIN.json` |
| Giao thức chạy lại cho QA2 | `docs/evidence/WWHN-328/QA2_RERUN_PROTOCOL.md` |
| Evidence pack vòng trước | `docs/evidence/WWHN-328/EVIDENCE_PACK.md` |
| Gate chạy lại được | `scripts/verify-wwhn-328-closure.mjs`, `.github/workflows/wwhn-328-dashboard-closure.yml` |
| Register FE | `WMS_Warehouse_Hoa_Nam_FE/docs/evidence/WWHN-327/WWHN-327_CLOSURE_REGISTER.json` |
