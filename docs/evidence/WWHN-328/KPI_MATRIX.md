# WWHN-328 — Approved KPI Matrix (dashboard aggregate)

- **Endpoint (tái sử dụng, không tạo route mới):** `GET /api/v1/reports/dashboard-quick`
- **Implementation:** `Modules/Warehouse/app/Http/Controllers/OperationalReportController::dashboardQuick()`
  + `dashboardWarehouseScope()` + `buildDashboardQuick()`
- **Route/permission:** `Modules/Warehouse/routes/api.php` → `middleware('permission:report.view')`
- **Matrix version:** v1.1 (2026-08-18) — thay thế v1.0 mô tả trong comment 15571/15579 bằng bản
  đã bổ sung `data_scope`, `scope_warehouse_ids`, `reconciliation_warning_count`.
- **Timezone/cutoff toàn matrix:** `Asia/Ho_Chi_Minh` (`config/app.php` → `APP_TIMEZONE`).
  `stock_movements.occurred_at` là `timestamp` **không mang timezone**, ghi theo giờ ứng dụng,
  nên kỳ được so trực tiếp trên chuỗi `[from_date 00:00:00, to_date 23:59:59]` — inclusive hai đầu.

## 1. Data scope (DEV328-P1-01 — chốt GLOBAL vs WAREHOUSE-SCOPED)

**Quyết định: WAREHOUSE-SCOPED theo người gọi, mặc định suy biến về GLOBAL.**

| Người gọi | `data_scope` | `scope_warehouse_ids` | KPI phản ánh |
|---|---|---|---|
| Super-admin, hoặc role có `scope_ref` rỗng/null | `GLOBAL` | `null` | Toàn hệ thống |
| Role gắn `scope_ref` = danh sách kho | `WAREHOUSE_SCOPED` | danh sách kho đó | Chỉ các kho trong scope |
| Bất kỳ ai gửi `?warehouse_id=X` hợp lệ trong scope | `WAREHOUSE_SCOPED` | `[X]` | Chỉ kho X |
| Role bị giới hạn gửi `?warehouse_id=Y` ngoài scope | — | — | `403 ACCESS_DENIED` |

Nguồn scope là **cùng một `scope_ref`** mà `EnforceInventoryWarehouseScope` và
`ScopesWarehouseData` đang áp cho `/inventory/balances` và `/stock-movements`. Trước bản này
dashboard đọc GLOBAL vô điều kiện, nên một người dùng bị giới hạn kho nhìn thấy tổng KPI và
các dòng đối soát (kèm `warehouse_code`, `product_code`, `product_name`, số lượng) của kho
ngoài phạm vi — mâu thuẫn trực tiếp với chính màn Tra cứu tồn của người đó.

**Ngoại lệ đã ghi nhận có chủ đích:** `warranty_cases_by_status` luôn GLOBAL vì ERD
`warranty_case` không có `warehouse_id`; không có đường suy ra kho cho một ca bảo hành.

## 2. Field set và công thức

| # | Field | Nguồn authoritative | Công thức | Lọc theo period? | Lọc theo kho? |
|---|---|---|---|---|---|
| K0 | `period.from_date` / `period.to_date` | tham số request | mặc định `[hôm nay-30, hôm nay]`, inclusive hai đầu (31 ngày) | — | — |
| K1 | `data_scope` | `app_user_role.scope_ref` | `GLOBAL` nếu không giới hạn, ngược lại `WAREHOUSE_SCOPED` | Không | — |
| K2 | `scope_warehouse_ids` | `app_user_role.scope_ref` | `null` khi GLOBAL; list kho khi scoped | Không | — |
| K3 | `available_qty` | `inventory_balances` | `SUM(available_qty) WHERE stock_status='AVAILABLE'` | **Không** (snapshot hiện tại) | Có |
| K4 | `receipt_qty` | `stock_movements` (ledger) | `max(0, SUM(qty_delta) WHERE movement_type='RECEIPT')` | Có (`occurred_at`) | Có |
| K5 | `issue_qty` | `stock_movements` | `abs(min(0, SUM(qty_delta) WHERE movement_type='ISSUE'))` | Có | Có |
| K6 | `component_issue_qty` | `stock_movements` | `abs(min(0, SUM(qty_delta) WHERE movement_type='COMPONENT_ISSUE'))` | Có | Có |
| K7 | `reversal_net` | `stock_movements` | `SUM(qty_delta) WHERE movement_type='REVERSAL'` — **giữ dấu**, có thể âm | Có | Có |
| K8 | `warranty_cases_by_status` | `warranty_case` | `COUNT(*) GROUP BY status` | **Không** | **Không** (xem ngoại lệ §1) |
| K9 | `reconciliation_warning_count` | RPT-INV-01 builder | `COUNT` dòng `reconciliation_status='MISMATCH'` trong scope | Không (tại thời điểm gọi) | Có |
| K10 | `reconciliation_warnings` | RPT-INV-01 builder | tối đa **50** dòng MISMATCH đầu tiên (mẫu) | Không | Có |

`movement_type='OPENING'` **không** tham gia bất kỳ KPI nào (`DASHBOARD_MOVEMENT_TYPES`).

**Lưu ý đọc số:** `reconciliation_warnings` đã bị cắt trần nên `length` của mảng **không** phải
tổng số cảnh báo. Con số để hiển thị và đối soát là `reconciliation_warning_count`.
Danh sách đầy đủ lấy tại `GET /api/v1/reports/inventory-balance`.

## 3. Expected totals — bộ số kiểm chứng được

Fixture trong `tests/Feature/Wwhn328DashboardQuickContractTest.php::seedTwoWarehouses()`:
Kho A nhập 10, xuất 3, tồn 12. Kho B nhập 4, tồn 9.

| Người gọi | receipt_qty | issue_qty | available_qty | data_scope |
|---|---|---|---|---|
| GLOBAL | 14 | 3 | 21 | `GLOBAL` |
| scope = Kho A | 10 | 3 | 12 | `WAREHOUSE_SCOPED` |
| GLOBAL + `?warehouse_id=Kho B` | 4 | 0 | 9 | `WAREHOUSE_SCOPED` |

## 4. Error contract

| Tình huống | HTTP | `error_code` |
|---|---|---|
| Chưa đăng nhập | 401 | `UNAUTHENTICATED` |
| Thiếu `report.view` | 403 | `ACCESS_DENIED` |
| `warehouse_id` ngoài data-scope | 403 | `ACCESS_DENIED` |
| `to_date < from_date` (kể cả qua alias `from`/`to`) | 422 | `BUSINESS_RULE_ERROR` |
| `from_date`/`to_date` không parse được ngày | 422 | `BUSINESS_RULE_ERROR` |
| `warehouse_id` không phải UUID | 422 | (validation envelope chuẩn) |

`ACCESS_DENIED` và `BUSINESS_RULE_ERROR` là **mã runtime canonical toàn hệ thống** (`CheckPermission`,
`ApiConventionMiddleware`), tương ứng với `PERMISSION_DENIED`/`DATA_SCOPE_DENIED` và
`BUSINESS_RULE_VIOLATION` trong bảng SRS §8.2. Ghi ở đây để không phải diễn giải lại khi review.

## 5. Cache

Key: `wms:{env}:warehouse:report:dashboard-quick:from-<from>:to-<to>:scope-<hash|global>:v<stock:version>`,
TTL policy `dashboard`, invalidate bằng bump `stock:version` sau mọi mutation tồn kho.

`scope` **bắt buộc** nằm trong key: thiếu nó thì payload của người dùng GLOBAL sẽ được trả lại cho
người dùng bị giới hạn kho và ngược lại. Data-scope cũng được giải **trước** khi chạm cache, nếu
không một cache hit sẽ nuốt mất `403` của `warehouse_id` ngoài scope.
