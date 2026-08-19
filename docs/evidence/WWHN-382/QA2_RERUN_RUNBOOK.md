# WWHN-382 — Runbook để QA2 chạy lại (DEV328-P1-06R4)

Đích đến: một báo cáo fixed-build đăng **trên chính WWHN-329**, gắn đúng cặp candidate ở
[`CANDIDATE_PIN.json`](./CANDIDATE_PIN.json), với **một** kết luận duy nhất.

DEV **không** điền kết quả thay QA2 trong tài liệu này. Đây là hard gate độc lập: nếu người viết
code tự tick, mục DEV328-P1-07R4 ("BA và QA2 độc lập chấp nhận") sẽ trượt tiếp ở vòng sau vì
cùng một người đứng ở hai vai.

---

## 0. Lấy đúng cặp candidate

```bash
# BE
git -C <BE_REPO> fetch origin m1
git -C <BE_REPO> checkout $(node -p "require('./docs/evidence/WWHN-382/CANDIDATE_PIN.json').candidate.backend.sha")

# FE
git -C <FE_REPO> fetch origin m1
git -C <FE_REPO> checkout $(node -p "require('<BE_REPO>/docs/evidence/WWHN-382/CANDIDATE_PIN.json').candidate.frontend.sha")
```

Xác nhận đang đứng đúng chỗ trước khi đo bất cứ thứ gì:

```bash
node scripts/verify-wwhn-382-closure.mjs --expect-sha <BE candidate sha>
```

Nếu lệnh này đỏ thì mọi số đo phía sau vô nghĩa — dừng lại và báo DEV.

## 1. Môi trường phải ghi vào báo cáo

| Mục | Ghi giá trị thật đã dùng |
|---|---|
| BE SHA | (từ `CANDIDATE_PIN.json`) |
| FE SHA | (từ `CANDIDATE_PIN.json`) |
| Driver DB | `sqlite` hay `pgsql` + version |
| Tên database | ví dụ `hn_wwhn382` |
| Trình duyệt | tên + version (cho mục 8) |
| Viewport | 1440x900 / 768x1024 / 375x812 |
| Bộ dữ liệu | seed nào, hay dữ liệu DEV hiện có |
| Thời điểm đo | ngày giờ, và múi giờ máy đo |

Múi giờ máy đo phải ghi lại vì mục 4 kiểm biên ngày; đo trên máy lệch múi giờ sẽ ra kết quả khác
mà không ai truy được vì sao.

## 2. Chạy tự động — khoá lại toàn bộ hợp đồng

```bash
# SQLite (driver mặc định của phpunit.xml)
php vendor/bin/phpunit --no-coverage tests/Feature/Wwhn328DashboardQuickContractTest.php
php vendor/bin/phpunit --no-coverage tests/Feature/Wwhn328R4ClosureTest.php
```

```bash
# PostgreSQL — DB tham chiếu theo tài liệu. Tạo database mới trước mỗi lượt đo:
#   migrate:fresh của Laravel KHÔNG drop view, trạng thái tồn dư có thể làm migration
#   guard ném lỗi ở test đầu tiên rồi tự lành ở test sau.
docker exec <postgres_container> psql -U postgres -c "DROP DATABASE IF EXISTS hn_wwhn382;" -c "CREATE DATABASE hn_wwhn382;"
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=hn_wwhn382 \
DB_USERNAME=postgres DB_PASSWORD=postgres \
  php vendor/bin/phpunit --no-coverage tests/Feature/Wwhn328R4ClosureTest.php
```

**Bắt buộc chạy cả hai driver.** Hai khiếm khuyết fixture của task này chỉ lộ trên PostgreSQL
(`uk_warehouses_single_active` ở vòng R3, `chk_erd_warranty_identification_shape` ở vòng R4);
SQLite không tạo các ràng buộc đó nên xanh giả.

## 3. Ánh xạ hạng mục register → nơi đã được khoá

Bảng này để QA2 **đối chiếu**, không phải để thay việc đo. Cột phải cho biết nếu hạng mục hỏng
thì cái gì sẽ đỏ.

| # | Hạng mục register R4 | Test khoá | Cách tự kiểm bằng tay |
|---|---|---|---|
| 1 | totals | `test_unscoped_user_gets_global_aggregate` | So với bảng "Expected totals" ở KPI matrix v1.2 §3 |
| 2 | scope | `test_warehouse_scoped_user_only_sees_own_warehouse_totals`, `test_warehouse_id_outside_scope_is_denied` | Đăng nhập 1 user bị giới hạn kho, so `data_scope` + `scope_warehouse_ids` với màn Tra cứu tồn của chính user đó |
| 3 | cache isolation | `test_cache_is_isolated_between_data_scopes`, `test_warehouse_id_denial_is_not_swallowed_by_a_warm_cache` | Gọi bằng user GLOBAL trước, rồi user scoped ngay sau, trong TTL — hai payload phải khác nhau |
| 4 | timezone/boundaries | `test_period_boundaries_are_inclusive_on_both_ends`, `test_datetime_input_is_normalised_to_a_date_before_querying` | Tạo movement lúc `to_date 23:59:59` và `to_date+1 00:00:00`; cái đầu phải vào kỳ, cái sau không |
| 5 | 401/403/422 | `test_requires_authentication`, `test_requires_report_view_permission`, `test_reversed_period_via_alias_is_rejected` | Gọi không token → 401; token thiếu `report.view` → 403; `?from=2026-05-10&to=2026-05-01` → 422 `BUSINESS_RULE_ERROR` |
| 6 | reconciliation | `test_reconciliation_warning_count_matches_the_inventory_balance_report` | Gọi `/reports/dashboard-quick` và `/reports/inventory-balance` bằng **cùng token**; `reconciliation_warning_count` phải bằng `summary.mismatch_count` |
| 7 | warranty | `test_warranty_kpi_stays_global_for_a_warehouse_scoped_user` | Cùng dữ liệu, gọi bằng user GLOBAL và user scoped: KPI nhập/xuất **khác nhau**, `warranty_cases_by_status` **giống nhau** |
| 8 | trần mẫu vs tổng | `test_warning_count_is_the_total_while_the_embedded_sample_is_capped_at_fifty` | Khi có >50 dòng MISMATCH: mảng `reconciliation_warnings` đúng 50 phần tử, `reconciliation_warning_count` lớn hơn 50 |
| 9 | hợp đồng công bố | `test_openapi_operation_declares_exactly_the_runtime_field_set` | So field set response thật với `paths["/api/v1/reports/dashboard-quick"]` trong `storage/api-docs/api-docs.json` |
| 10 | không route mới | `test_dashboard_aggregate_is_served_by_exactly_one_reused_route` | `php artisan route:list --path=reports/dashboard` phải ra đúng 1 dòng |

## 4. Tích hợp FE (mục "FE integration" của register)

Đứng ở FE candidate SHA:

```bash
npm ci
npm run typecheck
npm run test -- src/modules/dashboard
node scripts/verify-wwhn-327-closure.mjs
node scripts/verify-wwhn-382-fe-consumer.mjs
```

Trên giao diện, ba thứ phải nhìn thấy được bằng mắt và cần ảnh chụp trong báo cáo:

1. Dòng **"Phạm vi dữ liệu"** hiển thị đúng `GLOBAL` hay "giới hạn N kho".
2. Khi payload là `WAREHOUSE_SCOPED`, thẻ **Bảo hành** có khối ghi chú ngoại lệ GLOBAL. Khi
   `GLOBAL` thì khối đó **không** hiện.
3. Thẻ **Đối soát tồn kho** hiển thị số lấy từ `reconciliation_warning_count`. Kiểm bằng cách
   dựng >50 dòng MISMATCH: số trên màn hình phải lớn hơn 50, không dừng ở 50.

## 5. Báo cáo cần có gì để đóng được mục này

- Đăng **trên WWHN-329** (không đăng trên WWHN-328/WWHN-382).
- Gắn **đủ cả hai SHA** BE và FE, đủ 40 ký tự.
- Ghi môi trường theo bảng §1.
- **Một** kết luận duy nhất. Kết luận dạng lựa chọn kép bị coi là chưa ký — đó chính là lý do
  các vòng trước không đóng được mục này.
- Nếu có hạng mục hỏng: ghi số hiệu hạng mục ở §3, request/response thật, và kỳ vọng theo KPI
  matrix v1.2 — không mô tả bằng lời chung chung.

## 6. Điều KHÔNG cần chạy lại

Hành vi runtime của endpoint không đổi so với candidate R4 đầu vòng: bốn trong năm fingerprint
hợp đồng giữ nguyên, phần đổi duy nhất là tên nội bộ `dashboardWarehouseScope` →
`reportWarehouseScope` (WWHN-254, không đổi hành vi). Vòng R4 đóng truy vết, hợp đồng và độ phủ
test. Nếu QA2 đã có kết quả xanh cho các kịch bản §3 mục 1–5 ở vòng trước và fingerprint không
lệch, có thể trích dẫn lại và tập trung công sức vào mục 6–10 vốn là phần mới.
