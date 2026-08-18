# WWHN-328 — Evidence Pack (candidate 2026-08-18)

Đóng P0/P1/P2 EXACT FIX ACTION REGISTER trong comment `15602` (WWHN-328) và các mục
liên đới ở comment `15623` (WWHN-327).

## 1. Candidate pin (DEV328-P1-02, DEV328-P1-03)

| Thành phần | Repo | Branch | Exact SHA |
|---|---|---|---|
| Backend | `WMS_Warehouse_Hoa_Nam_BE` | `m1` | `6db0965940a6ae8b22a591d50705f258326b172d` |
| Frontend | `WMS_Warehouse_Hoa_Nam_FE` | `m1` | `3ddce525e2e3b3af29723d1ec97e3fc9eff175b3` |

**Supersession:** `m1-be-2` (`608164…`) và `b8cfcf01eb0ef01661bb58a7c1bb08825e35e8f3` là
**historical/superseded**, không còn là nguồn đánh giá. Lý do bắt buộc phải rời khỏi `b8cfc`:
sau SHA đó dashboard runtime đã đổi hai lần — thêm Valkey application caching (`0b32578`) và
bản này (data-scope + cache key + aggregate). Mọi kết quả test gắn với `b8cfc` phải retest.

**Cả hai commit là bundled commit** (`gỡ cờ 12`, chứa thêm WWHN-330/WWHN-331 do luồng làm việc
song song). Danh sách file **thuộc WWHN-328** trong commit BE `6db0965`:

| File | git blob |
|---|---|
| `Modules/Warehouse/app/Http/Controllers/OperationalReportController.php` | `17c16a4ca7ff3a5d24d4ac150a7c82d8cbb6b83a` |
| `tests/Feature/Wwhn328DashboardQuickContractTest.php` (mới, 17 test) | `431bfabe6d2abda78475a8d377b0251ddd5bbb7d` |
| `storage/api-docs/api-docs.json` | `50b81fd51a5075a81bbb42f2624c011d091138d4` |
| `storage/api-docs/api-docs.yaml` | (sha256 bên dưới) |
| `docs/postman/WMS_HoaNam API Contract - DEV.postman_collection.json` | `2c7efe3e937fe2c8ee6e17565a5daa6ac4133812` |

File **thuộc WWHN-327/328** trong commit FE `3ddce52` (phần dashboard contract):

| File | git blob |
|---|---|
| `src/modules/dashboard/types/index.ts` | `760bfff7cf4f2ed24df6f78755f89936b7e8e31f` |
| `src/modules/dashboard/pages/DashboardPage.tsx` | `89d8386bbe4b1d94e4641ca947c38c3cd3e7cd8b` |
| `src/modules/dashboard/pages/DashboardPage.test.tsx` | `e88dcf85b2619592f9ae482da82f69e3027f844a` |

## 2. Contract hashes (DEV328-P1-04)

| Artifact | sha256 |
|---|---|
| `storage/api-docs/api-docs.json` | `78acdca8e217ee22b6888100b1e6bc32d4e75630725a4bffc51cda014c9c51a8` |
| `storage/api-docs/api-docs.yaml` | `9656300e449a58c76ef23cc6683147fc624547a4588c663f6b5888f41f26f966` |
| `docs/postman/WMS_HoaNam API Contract - DEV.postman_collection.json` | `e752f6e6832132cb2406a01d9756ea4fb4bf8fa86f5a99658458a17d8316fcf7` |

Field set, công thức, data-scope, timezone/cutoff, expected totals và error contract:
xem [`KPI_MATRIX.md`](./KPI_MATRIX.md) (matrix v1.1).

## 3. Khiếm khuyết đã đóng ở candidate này

| ID | Mức | Vấn đề | Sửa |
|---|---|---|---|
| DS-01 | P0 | `dashboard-quick` trả KPI + dòng đối soát toàn hệ thống cho cả người dùng bị giới hạn kho, trong khi `/inventory/balances` và `/stock-movements` của chính họ đã bị `EnforceInventoryWarehouseScope` cắt theo `scope_ref`. Dòng MISMATCH mang `warehouse_code`/`product_code`/`product_name`/số lượng của kho ngoài phạm vi. | `dashboardWarehouseScope()` đọc cùng nguồn `scope_ref`; mọi aggregate lọc theo scope; thêm `data_scope`/`scope_warehouse_ids` vào response. |
| DS-02 | P0 | Cache key `dashboard-quick` chỉ gồm `from`/`to`/`stock:version`. Khi thêm data-scope mà không thêm scope vào key thì payload của người dùng GLOBAL sẽ được phục vụ cho người dùng bị giới hạn kho. | Scope vào cache key; scope được giải **trước** cache nên `403` của `warehouse_id` ngoài scope không bị cache hit nuốt mất. |
| PERF-01 | P1 | `->get(['movement_type','qty_delta'])` kéo **toàn bộ** movement của kỳ về PHP rồi mới `sum()` trong collection — bộ nhớ tăng tuyến tính theo số movement. | `GROUP BY movement_type` + `SUM(qty_delta)` tại SQL; loại `OPENING` ngay ở `WHERE`. |
| PAY-01 | P1 | `reconciliation_warnings` nhúng **toàn bộ** dòng MISMATCH của RPT-INV-01; một lần lệch đối soát diện rộng thổi payload dashboard to bằng cả báo cáo tồn kho. FE lại đếm cảnh báo bằng `length` của mảng. | Trần 50 dòng mẫu + `reconciliation_warning_count` là tổng thật; FE đọc `count`. |
| TZ-01 | P1 | Rule `date` cho qua `"2026-08-01T10:00:00"`; chuỗi ghép thành `"2026-08-01T10:00:00 00:00:00"` nên `whereBetween` không so được và KPI **lặng lẽ trả 0** thay vì báo lỗi. | `period()` chuẩn hoá về date-only — một chỗ cho cả 5 báo cáo dùng `period()`. |

## 4. Kết quả kiểm thử tại đúng candidate

Chạy cục bộ tại BE `6db0965` / FE `3ddce52`, ngày 2026-08-18.

| Gate | Lệnh | Kết quả |
|---|---|---|
| BE contract test (mới) | `php vendor/bin/phpunit tests/Feature/Wwhn328DashboardQuickContractTest.php` | **17 passed**, 61 assertions |
| BE full suite | `php vendor/bin/phpunit` | **667 tests, 645 passed, 22 skipped, 0 failed**, 7003 assertions |
| BE style | `./vendor/bin/pint --test <file WWHN-328>` | **passed** |
| FE typecheck | `npx tsc --noEmit` | **passed** |
| FE unit | `npx vitest run` | **67 files, 508 passed** |
| FE format gate | `node scripts/verify-format-baseline.mjs` | **PASS** (0 drift) |
| FE lint | `npm run lint` | **0 errors** (25 warning `react-hooks/set-state-in-effect` tồn tại từ trước, không thuộc WWHN-328) |

**Ghi chú CI:** trước candidate này `npm run format:check` **đỏ trên m1** — baseline
`docs/evidence/format-baseline/known-prettier-debt.json` có `knownDebt: []` (chính sách "không nợ
format") nhưng 24 file đang lệch Prettier, và gate này chạy ở 6 workflow gồm
`exact-sha-evidence.yml`. Đã chạy `npm run format` để gate xanh trở lại; nếu không thì không thể
có "green CI at exact SHA" cho bất kỳ task nào, không riêng WWHN-328.

Ngoài ra `php artisan` đang fatal do `App\Console\Commands\MigrationFinalDataLoad::fail()` che
`Illuminate\Console\Command::fail()` (phải là `public`) — đã đổi tên thành `failWith()` để
`l5-swagger:generate` và mọi lệnh artisan chạy được.

## 5. Phần KHÔNG thuộc phạm vi sửa code (cần người ký)

| ID | Nội dung | Ai đóng |
|---|---|---|
| DEV328-P1-01 | Business owner ký matrix KPI v1.1 với một verdict duy nhất | BA Main/BAS |
| DEV328-P1-05 | Tạo Jira issue link WWHN-328 ↔ WWHN-327 (hiện `issuelinks=[]`) và map dependency HN-STR-002-ST01 | PM/BAS |
| DEV328-P1-06 | QA2 đăng fixed-build report **trên chính WWHN-329**, một verdict duy nhất, gắn cặp SHA BE/FE ở §1 | QA2 |
| DEV328-P1-07 | BA và QA2 xác nhận **cùng một** candidate, không dùng chung assignee DEV/FE để tự accept | BA Main + QA2 |
| DEV328-P2-01 | Chuyển narrative BE2/Duy Khánh/`m1-be-2` sang mục historical/superseded | PM/BAS |
