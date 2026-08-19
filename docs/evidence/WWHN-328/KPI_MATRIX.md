# WWHN-328 — Approved KPI Matrix (dashboard aggregate)

- **Endpoint (tái sử dụng, không tạo route mới):** `GET /api/v1/reports/dashboard-quick`
- **Implementation:** `Modules/Warehouse/app/Http/Controllers/OperationalReportController::dashboardQuick()`
  + `reportWarehouseScope()` + `buildDashboardQuick()`
- **Route/permission:** `Modules/Warehouse/routes/api.php` → `middleware('permission:report.view')`
- **Matrix version:** v1.2 (2026-08-19) — thay thế v1.1 (2026-08-18) và v1.0 (comment 15571/15579).
  Vòng đóng register: **R4** — hồ sơ đóng tại [`../WWHN-382/R4_CLOSURE.md`](../WWHN-382/R4_CLOSURE.md).
- **Trạng thái:** phát hành để ký. Bản này không còn chỗ trống, không còn phương án kép; mỗi dòng
  KPI đều có một test khoá lại (cột "Test khoá") nên verdict là kiểm chứng được chứ không phải ý kiến.

## 0. Thay đổi so với v1.1 (đọc phần này nếu đã duyệt v1.1)

| # | Thay đổi | Vì sao |
|---|---|---|
| C1 | `dashboardWarehouseScope()` → `reportWarehouseScope()` | WWHN-254 DEV254-P1-03 mở data-scope ra **mọi** `/reports/*`; v1.1 trỏ tới một method **không còn tồn tại** trong mã nguồn. Hành vi không đổi, tên đổi. |
| C2 | Bổ sung ánh xạ "nguồn authoritative ↔ ERD" ở mức bảng vật lý | Trả lời trước câu "vì sao đọc `stock_movements` chứ không phải `inventory_movements` của ERD". |
| C3 | Timezone/cutoff đổi từ khẳng định sang **số đo trên DB tham chiếu** | v1.1 nói `occurred_at` "không mang timezone" mà không kèm bằng chứng. §5 nay có kết quả truy vấn `information_schema`. |
| C4 | Ghi rõ `warranty_case` là **view** trên PostgreSQL | Người đọc ERD tìm bảng `warranty_cases` sẽ không thấy tên mà mã nguồn dùng. |
| C5 | Ghi rõ nhánh `[]` (fail-closed) là **không tới được** ở endpoint này | Chú thích trong mã có nhắc `[]`; nếu không nói rõ, review sẽ đòi test cho một nhánh chết. |
| C6 | Thêm §7 bất biến đối soát dashboard ↔ RPT-INV-01 | Register R4 bắt QA2 kiểm "reconciliation" nhưng chưa từng có định nghĩa "đúng" để đối chiếu. |
| C7 | Thêm cột "Test khoá" cho từng dòng | Không có cột này thì matrix chỉ là văn bản; có rồi thì mỗi dòng đỏ/xanh được ở CI. |

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
Ngoại lệ này được **khai trên UI** (WWHN-327: khối `role="note"` chỉ hiện khi
`data_scope=WAREHOUSE_SCOPED`) và được khoá bằng test
`Wwhn328R4ClosureTest::test_warranty_kpi_stays_global_for_a_warehouse_scoped_user`.

**Nhánh `[]` (scope rỗng, fail-closed) không tới được ở endpoint này.** `User::warehouseScopeIds()`
chỉ trả `[]` khi người dùng **không có role active nào**; mà không có role active thì
`permission:report.view` đã chặn ở `403` trước khi vào controller. Giữ nhánh `[]` trong mã là để
đồng quy ước với `ScopesWarehouseData`, không phải một trạng thái cần nghiệm thu ở dashboard.

## 2. Field set và công thức

| # | Field | Nguồn authoritative | Công thức | Lọc theo period? | Lọc theo kho? | Test khoá |
|---|---|---|---|---|---|---|
| K0 | `period.from_date` / `period.to_date` | tham số request | mặc định `[hôm nay-30, hôm nay]`, inclusive hai đầu (31 ngày) | — | — | `test_default_period_is_the_last_thirty_days_inclusive` |
| K1 | `data_scope` | `app_user_role.scope_ref` | `GLOBAL` nếu không giới hạn, ngược lại `WAREHOUSE_SCOPED` | Không | — | `test_unscoped_user_gets_global_aggregate`, `test_warehouse_scoped_user_only_sees_own_warehouse_totals` |
| K2 | `scope_warehouse_ids` | `app_user_role.scope_ref` | `null` khi GLOBAL; list kho khi scoped | Không | — | `test_warehouse_id_filter_narrows_global_user` |
| K3 | `available_qty` | `inventory_balances` | `SUM(available_qty) WHERE stock_status='AVAILABLE'` | **Không** (snapshot hiện tại) | Có | `test_warehouse_scoped_user_only_sees_own_warehouse_totals` |
| K4 | `receipt_qty` | `stock_movements` (ledger) | `max(0, SUM(qty_delta) WHERE movement_type='RECEIPT')` | Có (`occurred_at`) | Có | `test_period_boundaries_are_inclusive_on_both_ends` |
| K5 | `issue_qty` | `stock_movements` | `abs(min(0, SUM(qty_delta) WHERE movement_type='ISSUE'))` | Có | Có | `test_unscoped_user_gets_global_aggregate` |
| K6 | `component_issue_qty` | `stock_movements` | `abs(min(0, SUM(qty_delta) WHERE movement_type='COMPONENT_ISSUE'))` | Có | Có | `test_field_set_is_exactly_the_signed_off_contract` |
| K7 | `reversal_net` | `stock_movements` | `SUM(qty_delta) WHERE movement_type='REVERSAL'` — **giữ dấu**, có thể âm | Có | Có | `test_reversal_keeps_its_sign_and_opening_is_excluded` |
| K8 | `warranty_cases_by_status` | `warranty_case` | `COUNT(*) GROUP BY status` | **Không** | **Không** (xem ngoại lệ §1) | `test_warranty_kpi_stays_global_for_a_warehouse_scoped_user` |
| K9 | `reconciliation_warning_count` | RPT-INV-01 builder | `COUNT` dòng `reconciliation_status='MISMATCH'` trong scope | Không (tại thời điểm gọi) | Có | `test_reconciliation_warning_count_matches_the_inventory_balance_report` |
| K10 | `reconciliation_warnings` | RPT-INV-01 builder | tối đa **50** dòng MISMATCH đầu tiên (mẫu) | Không | Có | `test_warning_count_is_the_total_while_the_embedded_sample_is_capped_at_fifty` |

`movement_type='OPENING'` **không** tham gia bất kỳ KPI nào (`DASHBOARD_MOVEMENT_TYPES`).

**Ánh xạ nguồn ↔ ERD.** `stock_movements` là **tên bảng vật lý runtime** của ERD entity 30
`inventory_movements` ("Authoritative append-only ledger"). `warranty_case` là **view tương thích**
trên PostgreSQL (bảng vật lý là `warranty_cases`; migration `2026_08_10_000791` đảo chiều số
ít/số nhiều theo driver) và là **bảng vật lý** trên SQLite. Dashboard đọc đúng tên mà
`LedgerReconciliationService` (RPT-INV-01) và `/stock-movements` đang đọc, nên hai màn hình
**không thể lệch nguồn**, chỉ có thể lệch bộ lọc — và bộ lọc đã bị §7 khoá.

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

## 5. Timezone / cutoff — đo trên DB tham chiếu, không phải khẳng định suông

- Ứng dụng chạy `config('app.timezone') = Asia/Ho_Chi_Minh` (`config/app.php`), nên `now()` và mọi
  giá trị `occurred_at` do ứng dụng ghi đều là **giờ Việt Nam**.
- Cột được truy vấn, đo trên DB tham chiếu `hoa_nam_audit` (PostgreSQL 16):

  ```
  SELECT table_name, column_name, data_type, datetime_precision
    FROM information_schema.columns
   WHERE column_name = 'occurred_at' AND table_schema = 'public';

   stock_movements | occurred_at | timestamp without time zone | 0
  ```

  Hai tính chất quan trọng rút ra:
  1. `timestamp **without** time zone` ⇒ so sánh với chuỗi `'YYYY-MM-DD HH:MM:SS'` **không** đi qua
     `TimeZone` của phiên PostgreSQL (phiên hiện đang là `UTC`, và `config/database.php` cố ý không
     set `timezone` cho connection `pgsql`). Không có dịch 7 giờ ở biên kỳ.
  2. `datetime_precision = 0` ⇒ không có phần giây lẻ, nên `<= 'to_date 23:59:59'` **không** đánh
     rơi bản ghi kiểu `23:59:59.4`. Biên `to_date` là inclusive thật sự.
- Kỳ được so trực tiếp trên chuỗi `[from_date 00:00:00, to_date 23:59:59]` — **inclusive hai đầu**.
- `period()` chuẩn hoá đầu vào về **date-only** trước khi ghép chuỗi. Rule `date` của Laravel vẫn
  cho qua `"2026-08-01T10:00:00"`; nếu không chuẩn hoá thì chuỗi ghép ra `"2026-08-01T10:00:00 00:00:00"`,
  SQL không so được và KPI **lặng lẽ trả 0** thay vì báo lỗi.
  Khoá bằng `test_datetime_input_is_normalised_to_a_date_before_querying`.
- Cảnh báo cho lần đổi schema sau: nếu `occurred_at` được chuyển sang `timestamptz` để khớp ERD §0,
  **cả hai tính chất trên mất hiệu lực cùng lúc** (dịch timezone + giây lẻ). Khi đó biên phải đổi
  sang `occurred_at >= from 00:00:00 AND occurred_at < to+1 ngày 00:00:00` và matrix này phải lên v1.3.

## 6. Cache

Key: `wms:{env}:warehouse:report:dashboard-quick:from-<from>:to-<to>:scope-<hash|global>:v<stock:version>`,
TTL policy `dashboard`, invalidate bằng bump `stock:version` sau mọi mutation tồn kho.

`scope` **bắt buộc** nằm trong key: thiếu nó thì payload của người dùng GLOBAL sẽ được trả lại cho
người dùng bị giới hạn kho và ngược lại. Data-scope cũng được giải **trước** khi chạm cache, nếu
không một cache hit sẽ nuốt mất `403` của `warehouse_id` ngoài scope.
Khoá bằng `test_cache_is_isolated_between_data_scopes` và
`test_warehouse_id_denial_is_not_swallowed_by_a_warm_cache`.

`warranty_case` không đi qua `mutateBalance()` nên KPI bảo hành chỉ được bảo vệ bởi TTL ngắn của
policy `dashboard`. Đây là đánh đổi có chủ đích cho một KPI đếm ca bảo hành, không phải thiếu sót.

## 7. Bất biến đối soát dashboard ↔ RPT-INV-01 (mới ở v1.2)

Với **cùng một người gọi** và không truyền `warehouse_id`:

| Bất biến | Vế trái (dashboard-quick) | Vế phải (`/reports/inventory-balance`) |
|---|---|---|
| I1 | `data.reconciliation_warning_count` | `data.summary.mismatch_count` |
| I2 | `data.data_scope` | `data.data_scope` |
| I3 | `data.scope_warehouse_ids` | `data.scope_warehouse_ids` |

Ba bất biến này là định nghĩa "đối soát đúng" mà QA2 cần khi chạy lại (DEV328-P1-06R4). Chúng đúng
**by construction** vì `buildDashboardQuick()` gọi lại chính `buildInventoryBalance()` — nhưng
"by construction" đã từng bị phá bởi một lần refactor, nên chúng được khoá bằng
`Wwhn328R4ClosureTest::test_reconciliation_warning_count_matches_the_inventory_balance_report`.

## 8. Ràng buộc phạm vi

`GET /api/v1/reports/dashboard-quick` là **endpoint aggregate duy nhất**; WWHN-328 chốt tái sử dụng,
không tạo route mới. Khoá bằng
`Wwhn328R4ClosureTest::test_dashboard_aggregate_is_served_by_exactly_one_reused_route`
(assert đúng một route khớp `reports/dashboard*`).

OpenAPI công bố cho FE/QA phải là **đúng** field set runtime, không phải một bản mô tả song song —
khoá bằng `test_openapi_operation_declares_exactly_the_runtime_field_set`.

## 9. Verdict và sổ ký (DEV328-P1-01R4)

Matrix này phát hành ở trạng thái **decisive**: mỗi ô đều là một khẳng định đơn, không có
phương án kép, không có chỗ trống chờ điền. Ba vòng review trước bị trả lại vì comment ký để
nguyên dạng lựa chọn (`[GLOBAL / WAREHOUSE-SCOPED]`, `VERIFIED PASS / CHANGES REQUIRED`) —
reviewer đọc dual verdict là **chưa ký**. Vì vậy quy ước của v1.2:

> Một comment ký chỉ hợp lệ khi nêu **một** kết luận, **một** version matrix (`v1.2`) và
> **một** cặp SHA. Comment còn dấu `/` giữa hai lựa chọn được coi là chưa ký.

| Vai trò | Ký cái gì | Nguồn để đọc trước khi ký |
|---|---|---|
| BA Main / BAS | §1 data-scope, §2 công thức K0–K10, §3 expected totals, §4 error contract, ngoại lệ warranty GLOBAL | file này (v1.2) |
| Tech Lead | §5 timezone/cutoff, §6 cache, §7 bất biến đối soát, contract OpenAPI/Postman | file này + `CONTRACT_PIN.json` |
| QA2 | không ký matrix — chạy lại và ra verdict trên WWHN-329 | `QA2_RERUN_PROTOCOL.md` |

**Phía DEV tự khai (không thay chữ ký của vai trò khác):** toàn bộ nội dung matrix v1.2 đã được
khoá bằng test chạy thật tại candidate — 17 case `Wwhn328DashboardQuickContractTest` + 5 case
`Wwhn328R4ClosureTest`, xem `R4_CLOSURE.md §4`. Không có dòng nào trong matrix chỉ tồn tại
dưới dạng văn bản.
