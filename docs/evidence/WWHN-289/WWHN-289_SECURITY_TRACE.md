# WWHN-289 · `DEV289-P1-04` — Security finding → fix → retest trace

Review yêu cầu: *"Pin auth/RBAC/security finding, affected endpoint/package/version; chạy unauthorized/data-scope/replay/secret regression phù hợp"*, closure evidence là *"Finding→SHA→test→retest mapping"*.

- Producer: `tests/Feature/Wwhn289SecurityRegressionProofTest.php`
- Số liệu thô: `build/evidence/wwhn-289/security-regression-proof.json`
- Exact SHA: `WWHN-289_PINS.json` → `change_range.final`

## A. Finding → fix → test (không có finding nào là suy diễn)

### `SEC289-01` — Luồng nhập kho không có data scope

| Mục | Nội dung |
|---|---|
| Điều khoản bị vi phạm | SRS §8.1 *Enforcement standard*: mọi endpoint được bảo vệ phải kiểm tra **exact x-permission + data scope** trước khi service ghi dữ liệu. NFR-SRS-005: *"Security enforced by backend permission and scope"* |
| Endpoint ảnh hưởng | `GET/POST /inbound-documents`, `GET/PATCH /inbound-documents/{id}`, `/cancel`, `/boxes`, `/scan`, `/scans`, `/unscan`, `/post-receipt`, `/reverse`, `/reversals`, `/scan-entries` |
| Hiện trạng trước fix | Chỉ có middleware permission. `EnforceOutboundWarehouseScope` bảo vệ luồng xuất, `EnforceInventoryWarehouseScope` bảo vệ 6 endpoint đọc tồn — luồng nhập **không có gì** |
| Vì sao bị bỏ sót | `EnsureCanonicalInboundWarehouse` trông giống guard kho: nó chặn phiếu nhập dùng kho không phải `KHO-TONG-HN` ACTIVE. Nhưng nó **không đọc `scope_ref` của actor**, nên chỉ là invariant MVP, không phải data scope |
| Tác động | User được cấp `inbound.post` với `scope_ref` trỏ kho khác vẫn Post Receipt được → sinh `stock_movement` + `inventory_balance` ngoài phạm vi dữ liệu của mình |
| Fix | `Modules/Warehouse/app/Http/Middleware/EnforceInboundWarehouseScope.php` (mới), gắn vào cả route canonical lẫn alias Annex C |
| Hành vi sau fix | 403 `DATA_SCOPE_DENIED` đúng §8.2, kèm `meta.request_id`; SUPER_ADMIN / role không gắn `scope_ref` đi qua nguyên trạng |
| Retest | `test_data_scope_dimension_scoped_user_cannot_create_stock_effect_outside_scope` — post-receipt **403**, create **403**, read **403**, `stock_movements` không tăng; **cùng user với scope đúng kho vẫn 200** (chứng minh guard không phải "chặn tất cả") |

### `SEC289-02` — Không tồn tại bất kỳ rate limit / anti-abuse nào

| Mục | Nội dung |
|---|---|
| Điều khoản bị vi phạm | §8.1 bảng *Security*: nhóm PV công khai phải có *"read-only DTO/rate-limit/cache/anti-abuse controls"*. Annex C khai error set của `listPvProducts` / `getPvProduct` / `listPvIndustries` / `listPvRecommendations` là **400, 404, 429, 500** |
| Hiện trạng trước fix | `grep -r throttle` trên toàn bộ `app/`, `Modules/`, `routes/`, `bootstrap/` → **không có kết quả**. `POST /auth/login` không giới hạn số lần thử; `429` là mã lỗi được công bố nhưng không thể xảy ra |
| Tác động | Dò mật khẩu không giới hạn tốc độ trên endpoint xác thực; nhóm endpoint ẩn danh duy nhất của hệ thống không có chống lạm dụng |
| Fix | `config/rate_limit.php` (ngưỡng theo env) · `App\Support\RateLimitResponse` (429 đúng envelope §8.2 + `Retry-After`) · limiter `pv-public`, `auth-refresh` trong `AppServiceProvider` · khoá login theo **lần đăng nhập SAI** trong `AuthController` |
| Chi tiết thiết kế khoá login | Khoá theo `(IP + định danh đăng nhập)`: chỉ theo IP thì một kho NAT chung sẽ tự khoá lẫn nhau; chỉ theo username thì đổi username là thoát khoá. Chỉ đếm lần **thất bại**, đăng nhập đúng `clear()` bộ đếm nên người dùng hợp lệ không bị phạt oan. Tài khoản `INACTIVE/LOCKED` (403) **vẫn tính** là lần hỏng — nếu không, kẻ tấn công dò được mật khẩu của tài khoản đã khoá vô hạn lần. Ghi audit `LOGIN_THROTTLED` |
| Retest | login: 3 lần sai → lần 4 **429 `RATE_LIMIT_EXCEEDED`** có `Retry-After`, **mật khẩu đúng cũng bị chặn trong cửa sổ khoá**, audit có `LOGIN_THROTTLED`. PV: quá ngưỡng → **429** đúng envelope (`success=false`, `error_code`, `error.code`, `meta.request_id`) |

### `SEC289-03` — Mã lỗi 409 idempotency nằm ngoài contract

Chi tiết ở `WWHN-289_DEFECT_FIX_REGISTER.md` §2.1. Vế bảo mật: một mã lỗi ngoài contract khiến client **không phân biệt được** "trùng key do retry" với "trùng key do bị phát lại (replay) với dữ liệu khác" — mà đó chính là tín hiệu để phát hiện lạm dụng khoá idempotency.

## B. Chín nhóm regression đã chạy trên PostgreSQL 16

Register 73/100 (`DEV289-P1-04R`) đòi retest đúng sáu mặt: **unauthorized / RBAC / data-scope / replay / rate-limit / secret**. Artifact ghi chúng thành chín khoá đo được:

| Khoá trong artifact | Mặt của register | Kịch bản | Kết quả đo |
|---|---|---|---|
| `unauthorized_anonymous` | unauthorized | ẩn danh gọi `post-receipt`, `scan`, `GET /inventory/balances` | **401 `UNAUTHENTICATED`** cả ba; `stock_movements` = 0 |
| `unauthorized_underprivileged` | unauthorized | token hợp lệ chỉ có `inbound.view` gọi `post-receipt` (đòi `inbound.post`) | **403 `ACCESS_DENIED`**; không ghi stock effect; document giữ `SCANNING` |
| `rbac_matrix` | RBAC | ma trận permission × operation (`inbound.post`, `inventory.reversal.post`, `inventory.view`) | **6/6 ô DENY** trả 403 với `movement_delta = 0`; ô ALLOW trả 200; `cross_operation_bleed = false` — không permission nào mở nhầm operation khác |
| `data_scope` | data-scope | `scope_ref` trỏ kho khác | **403 `DATA_SCOPE_DENIED`** cho post/create/read; cùng user với scope đúng → **200** |
| `replay` | replay | dùng lại refresh token đã xoay vòng / session đã thu hồi | **401** — `RefreshTokenService::rotate()` revoke bản cũ trong cùng transaction |
| `rate_limit_login` | rate-limit | các lần đăng nhập sai liên tiếp | lần vượt ngưỡng **429 `RATE_LIMIT_EXCEEDED`** + `Retry-After`; **mật khẩu đúng cũng bị chặn** trong cửa sổ khoá; audit `LOGIN_THROTTLED` |
| `rate_limit_public_pv` | rate-limit | gọi nhóm PV ẩn danh quá ngưỡng | **429** đúng envelope §8.2 (`success=false`, `error_code`, `error.code`, `meta.request_id`) |
| `rate_limit_threshold_contract` | rate-limit | đọc ngưỡng từ `config/rate_limit.php`, kiểm `0 = tắt guard` | ngưỡng **do config quyết định**, không hardcode; `zero_disables_guard = true`; `owner_of_numeric_values = "BA + DevOps (DEV chỉ khoá hợp đồng, không tự chốt số)"` |
| `secret` | secret | body login, audit `LOGIN_FAILED`, envelope 404/500 | không chứa `password_hash`, mật khẩu thô, `JWT_SECRET`, `APP_KEY`; không có `"trace"` / `"file"` / `"line"` / `vendor/laravel` / `SQLSTATE` |

## C. Cảnh báo phương pháp (đã suýt tạo ra bằng chứng sai)

Request "ẩn danh" **phải là thao tác đầu tiên** của một test method. `auth('api')->login()` dùng để dựng fixture nhét token vào chính instance JWT mà guard nắm giữ, nên một request không có header `Authorization` gọi **sau** đó vẫn được coi là đã đăng nhập và trả **200**. Bản nháp đầu tiên của test này đã trả 200 ở đúng chỗ đó — nếu tin luôn con số thì register sẽ ghi "ẩn danh Post Receipt được", một P0 giả; nếu sửa bằng cách nới assertion thì sẽ ký một gate rỗng. Cách xử lý đúng là tách thành test method riêng, ghi lại lý do trong chính file test và ở đây.

## D. Giới hạn

Bằng chứng ở §B đo trên **PostgreSQL 16** (DB tham chiếu), tại đúng SHA đã pin, và được CI `.github/workflows/wwhn-289-closure.yml` sinh lại ở exact SHA. Ngưỡng rate limit vẫn là **giá trị mặc định do DEV chọn**: `RATE_LIMIT_PV_PUBLIC_PER_MINUTE=120`, `RATE_LIMIT_AUTH_LOGIN_PER_MINUTE=10`, `RATE_LIMIT_AUTH_REFRESH_PER_MINUTE=30`. Bài `rate_limit_threshold_contract` chỉ khoá **hợp đồng** (ngưỡng đọc từ config, `0` tắt guard), **không** khẳng định con số là đúng cho vận hành — **BA + DevOps chốt số cho UAT và PROD**, đổi bằng env, không sửa code.

Tài liệu này là bằng chứng DEV; nó không thay chữ ký QA1 / Security / Tech Lead.
