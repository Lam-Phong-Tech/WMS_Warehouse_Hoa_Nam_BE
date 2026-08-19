# WWHN-292 — FE292-P1-04: BE authorization enforcement — RAW direct-API evidence

Mục đích: đóng gap "BE 401/403 chỉ là self-assertion" bằng bằng chứng RAW gọi thẳng API
(bỏ qua hoàn toàn UI/FE), chứng minh backend tự enforce authentication, function permission
và warehouse data scope, kèm `request_id` cho từng probe và một lượt verification độc lập.

## 1. Env identity

| Trường | Giá trị |
| --- | --- |
| Thời điểm chạy probe | 2026-08-19 11:41–11:43 UTC (18:41–18:43 +07:00) |
| BE SHA (git rev-parse HEAD lúc bắt đầu phiên) | `5db3cb06a33aef726466e54801c7e087915d1801` (nhánh `m1`) |
| BE SHA (HEAD tại thời điểm ghi file này) | `958ef1d1ce9d21841aa66645842b2f6b6572126e` |
| Khác biệt giữa 2 SHA | `git diff --name-only 5db3cb0..958ef1d` = 2 commit docs/scripts của phiên song song (WWHN-184/WWHN-222): `docs/evidence/WWHN-184/RC_PIN.json`, `docs/evidence/WWHN-222/TEST_EVIDENCE.md`, `scripts/verify-wwhn184-candidate-pin.mjs`. **KHÔNG có file application code nào đổi** (`git diff --stat 5db3cb0..958ef1d -- Modules app routes config database composer.json composer.lock` = rỗng), nên runtime code của mọi probe là như nhau ở cả hai SHA. |
| Working tree lúc chạy | Có file modified/untracked của các phiên song song (docs/evidence WWHN-184/222/244/289, build/evidence wwhn-289, test WWHN-240, `database/golive/fail_forward_allowlist.php`). Không file nào nằm trên code path của auth/permission/data-scope middleware hay các route được probe (`Modules/Auth`, `Modules/Warehouse` sạch). |
| APP_ENV | `local` |
| Server | `php artisan serve --host=127.0.0.1 --port=8292` (PHP built-in server, local) |
| PHP | 8.3.30 (cli, ZTS Visual C++ 2019 x64) — thấy được trong header `X-Powered-By` của mọi response |
| DB driver | pgsql |
| DB engine | PostgreSQL 16.14 on x86_64-pc-linux-musl (container docker `wms_warehouse_hoa_nam_be-postgres-1`) |
| DB database | `hoa_nam_wwhn292_evidence` — DB **cô lập, tạo mới riêng cho evidence này** (không đụng `wms_hoanam` dev chính, không đụng `hoa_nam_audit`). Dựng bằng `CREATE DATABASE` + `php artisan migrate --force` + `php artisan db:seed --force` (env `DB_DATABASE`/`DB_PASSWORD` override qua shell, không sửa `.env`). |
| Cache | redis (valkey container, port 6379) — như cấu hình `.env` |

### Chuẩn bị scenario data-scope (chỉ trên DB cô lập)

Seeder mặc định để `user_roles.scope_ref = NULL` (= không giới hạn kho, theo
`Modules/Auth/app/Models/User.php::warehouseScopeIds()` dòng 134–153: `scope_ref` NULL/rỗng
→ trả `null` = unrestricted). Để dựng scenario "token bị giới hạn scope kho", trên DB cô lập:

1. `UPDATE user_roles SET scope_ref = '01a019d1-fdbb-7278-a8a3-156f9b7f0734'` (id kho
   `KHO-TONG-HN`) cho user `viewer01` → viewer01 chỉ được truy cập KHO-TONG-HN.
2. Chèn warehouse thứ hai `KHO-B-292` id `01a019d1-0000-7000-8000-292000000001` với
   `status = 'INACTIVE'` — schema có unique constraint `uk_warehouses_single_active`
   (hệ thống chủ đích chỉ cho 1 kho ACTIVE), nên kho thứ hai phải INACTIVE. Middleware
   data-scope so sánh `warehouse_id` yêu cầu với danh sách scope của user, không phụ thuộc
   status kho, nên scenario vẫn đúng bản chất "truy vấn kho ngoài scope".

Tài khoản dùng: `viewer01` (role `VIEWER`, seeder `Modules/Auth/database/seeders/AuthDatabaseSeeder.php`,
mật khẩu fixture mặc định của seeder). Permissions của VIEWER gồm `inventory.view` … nhưng
**không** có `iam.user.view` (xác nhận bằng query `role_permissions` và bằng payload login bên dưới).

### Route và middleware bị probe (trích code, nhánh m1, hai SHA trên)

- `Modules/Auth/routes/api.php` dòng 29: `Route::get('users', …)->middleware('permission:iam.user.view')`
- `Modules/Warehouse/routes/api.php` dòng 153–154: `Route::get('inventory/balances', …)->middleware(['permission:inventory.view', EnforceInventoryWarehouseScope::class])`
- `Modules/Warehouse/app/Http/Middleware/EnforceInventoryWarehouseScope.php` dòng 46–51: nếu request có `warehouse_id` nằm ngoài `warehouseScopeIds()` → `forbidden()` (dòng 175–193) trả 403 `ACCESS_DENIED` kèm `meta.request_id`.

## 2. Bảng probe

| # | Probe | Account / role / permission liên quan | Request | Expected | Actual (RUN-1) | request_id (RUN-1) |
| --- | --- | --- | --- | --- | --- | --- |
| A | AUTHZ-401 | (không token) | `GET /api/v1/inventory/balances` không có `Authorization` | 401 + error envelope + request_id | **401** `UNAUTHENTICATED` | `4049819e-6e7a-45ba-b7ca-bcdc66fe2a60` |
| B | AUTHZ-403-PERMISSION | `viewer01` / VIEWER / có `inventory.view`, **không** có `iam.user.view` | `GET /api/v1/users` với Bearer viewer01 | 403 + mã lỗi + request_id | **403** `ACCESS_DENIED` | `ef50fe1e-cb0d-4904-959f-f2fb8efaa5f0` |
| C | AUTHZ-403-DATA-SCOPE | `viewer01` scope_ref = KHO-TONG-HN (`01a019d1-fdbb…0734`) | `GET /api/v1/inventory/balances?warehouse_id=01a019d1-0000-7000-8000-292000000001` (KHO-B-292, ngoài scope) | deny (403) + request_id | **403** `ACCESS_DENIED`, message "Kho yêu cầu nằm ngoài data scope hiện hành." | `bec59e72-b996-4bcf-b494-b80b161f3cb4` |
| D | POSITIVE CONTROL | cùng token viewer01, kho trong scope | `GET /api/v1/inventory/balances?warehouse_id=01a019d1-fdbb-7278-a8a3-156f9b7f0734` (KHO-TONG-HN) | 200 + data | **200** `success:true`, trả balance của KHO-TONG-HN | `47a3ec1f-06bb-48de-8757-54adbcbb2b0f` |

Positive control D chứng minh token viewer01 hợp lệ và có `inventory.view` → các deny ở B/C
là do authorization thật (permission / data scope), không phải token hỏng.

Mã lỗi hệ thống dùng chung một vocabulary: 401 → `UNAUTHENTICATED`, 403 → `ACCESS_DENIED`
(cả tầng permission middleware lẫn tầng data-scope middleware; message phân biệt nguyên nhân).

## 3. Raw transcript — RUN-1 (curl, primary)

```text
===== RUN-1 (primary) start: 2026-08-19T11:41:39Z (UTC) / 2026-08-19T18:41:39+0700 (local) =====

--- LOGIN viewer01 ---
$ curl -sS -X POST http://127.0.0.1:8292/api/v1/auth/login -H "Content-Type: application/json" -d '{"username":"viewer01","password":"<seed-default>"}'
HTTP 200 (login) — access_token acquired; response (token redacted):
{
    "success": true,
    "message": "Đăng nhập thành công",
    "data": {
        "access_token": "<REDACTED>",
        "refresh_token": "<REDACTED>",
        "token_type": "bearer",
        "expires_in": 3600,
        "user": {
            "user_id": "01a019d1-f81a-7383-ae01-d7e632c1211c",
            "username": "viewer01",
            "display_name": "Viewer 01",
            "email": "viewer01@gmail.com",
            "roles": [
                {
                    "role_id": "01a019d1-f054-72c4-9dd8-ab967f58b205",
                    "role_code": "VIEWER",
                    "role_name": "Viewer",
                    "scope_ref": "01a019d1-fdbb-7278-a8a3-156f9b7f0734"
                }
            ],
            "permissions": [
                "audit_log.view",
                "agency.view",
                "catalog.view",
                "container.view",
                "defect.view",
                "import_batch.view",
                "inbound.view",
                "inventory.view",
                "item_instance.view",
                "outbound.view",
                "physical_code.view",
                "product.view",
                "product_master.view",
                "report.view",
                "sku.view",
                "stock_document.view",
                "warehouse.view"
            ],
            "warehouse_scope_ids": [
                "01a019d1-fdbb-7278-a8a3-156f9b7f0734"
            ]
        }
    },
    "meta": {
        "request_id": "ea64188d-d485-4533-833f-7f3e3585a590",
        "timestamp": "2026-08-19T18:41:41+07:00"
    }
}

--- AUTHZ-401: GET /api/v1/inventory/balances without Authorization header ---
$ curl -sS -D - http://127.0.0.1:8292/api/v1/inventory/balances -H "Accept: application/json"
HTTP/1.1 401 Unauthorized
Host: 127.0.0.1:8292
Connection: close
X-Powered-By: PHP/8.3.30
Cache-Control: no-store, private
Date: Wed, 19 Aug 2026 11:41:43 GMT
Content-Type: application/json
Vary: Origin
X-Request-ID: 4049819e-6e7a-45ba-b7ca-bcdc66fe2a60
Pragma: no-cache
Expires: 0

{"success":false,"message":"Chưa xác thực","error_code":"UNAUTHENTICATED","errors":[],"error":{"code":"UNAUTHENTICATED","details":[]},"meta":{"request_id":"4049819e-6e7a-45ba-b7ca-bcdc66fe2a60","timestamp":"2026-08-19T18:41:43+07:00"}}

--- AUTHZ-403-PERMISSION: viewer01 (VIEWER role, KHÔNG có iam.user.view) GET /api/v1/users ---
$ curl -sS -D - http://127.0.0.1:8292/api/v1/users -H "Accept: application/json" -H "Authorization: Bearer <viewer01>"
HTTP/1.1 403 Forbidden
Host: 127.0.0.1:8292
Connection: close
X-Powered-By: PHP/8.3.30
Cache-Control: no-store, private
Date: Wed, 19 Aug 2026 11:41:45 GMT
Content-Type: application/json
Vary: Origin
X-Request-ID: ef50fe1e-cb0d-4904-959f-f2fb8efaa5f0
Pragma: no-cache
Expires: 0

{"success":false,"message":"Bạn không có quyền thực hiện thao tác này.","error_code":"ACCESS_DENIED","errors":[],"error":{"code":"ACCESS_DENIED","details":[]},"meta":{"request_id":"ef50fe1e-cb0d-4904-959f-f2fb8efaa5f0","timestamp":"2026-08-19T18:41:45+07:00"}}

--- AUTHZ-403-DATA-SCOPE: viewer01 (scope_ref=KHO-TONG-HN 01a019d1-fdbb-7278-a8a3-156f9b7f0734) GET /api/v1/inventory/balances?warehouse_id=01a019d1-0000-7000-8000-292000000001 (KHO-B-292, ngoài scope) ---
$ curl -sS -D - "http://127.0.0.1:8292/api/v1/inventory/balances?warehouse_id=01a019d1-0000-7000-8000-292000000001" -H "Accept: application/json" -H "Authorization: Bearer <viewer01>"
HTTP/1.0 403 Forbidden
Host: 127.0.0.1:8292
Connection: close
X-Powered-By: PHP/8.3.30
Cache-Control: no-store, private
Date: Wed, 19 Aug 2026 11:41:46 GMT
Content-Type: application/json
Vary: Origin
X-Request-ID: bec59e72-b996-4bcf-b494-b80b161f3cb4
Pragma: no-cache
Expires: 0

{"success":false,"message":"Kho yêu cầu nằm ngoài data scope hiện hành.","error_code":"ACCESS_DENIED","errors":[],"error":{"code":"ACCESS_DENIED","details":[]},"meta":{"request_id":"bec59e72-b996-4bcf-b494-b80b161f3cb4","timestamp":"2026-08-19T18:41:46+07:00"}}

--- POSITIVE-CONTROL: viewer01 (có inventory.view, scope KHO-TONG-HN) GET /api/v1/inventory/balances?warehouse_id=01a019d1-fdbb-7278-a8a3-156f9b7f0734 ---
$ curl -sS -D - "http://127.0.0.1:8292/api/v1/inventory/balances?warehouse_id=01a019d1-fdbb-7278-a8a3-156f9b7f0734" -H "Accept: application/json" -H "Authorization: Bearer <viewer01>"
HTTP/1.1 200 OK
Host: 127.0.0.1:8292
Connection: close
X-Powered-By: PHP/8.3.30
Cache-Control: no-store, private
Date: Wed, 19 Aug 2026 11:41:49 GMT
Content-Type: application/json
Vary: Origin
X-Request-ID: 47a3ec1f-06bb-48de-8757-54adbcbb2b0f
Pragma: no-cache
Expires: 0

{"success":true,"data":[{"id":"ddfe9fdc-54aa-4d18-9603-d24a51722bb4","warehouse_id":"01a019d1-fdbb-7278-a8a3-156f9b7f0734","warehouse_name":"Kho tổng Hòa Nam","warehouse_code":"KHO-TONG-HN","sku_id":"01a019d1-ff1a-72cc-9a36-59f11f78a9e3","product_id":"01a019d1-ff1a-72cc-9a36-59f11f78a9e3","sku_code":"HN-PRD-SEED-001","product_code":"HN-PRD-SEED-001","product_name":"Máy bơm PV mẫu Hòa Nam","sku_type":"PRODUCT","unit":"cái","stock_status":"AVAILABLE","on_hand_qty":2,"available_qty":2,"reserved_qty":0,"ledger_total":2,"reconciliation_status":"MATCH","mismatch_reason":null,"variance":0,"item_subledger_count":2,"container_subledger_count":0,"container_code":null,"location_hint":null,"incident_id":null,"incident_status":null,"data_as_of":"2026-08-19T18:41:49+07...<body truncated for evidence file; full body returned HTTP 200 with data array>

===== RUN-1 (primary) end: 2026-08-19T11:41:49Z (UTC) =====
```

Ghi chú transcript: access/refresh token bị redact chủ đích (JWT sống); dòng lệnh login hiển thị
`<seed-default>` thay cho mật khẩu fixture của seeder — giá trị thật nằm trong
`Modules/Auth/database/seeders/AuthDatabaseSeeder.php` (`DEFAULT_PASSWORD`).

## 4. QA1 independent verification

Lượt verification độc lập thứ hai: tool khác (PowerShell 5.1 `Invoke-WebRequest` /
`Invoke-RestMethod` thay vì curl), script khác, login mới (token mới), timestamp khác
(~60s sau RUN-1). Kết quả **khớp RUN-1 trên cả 4 probe**: cùng HTTP status, cùng
`error_code`, cùng semantics; `request_id` khác nhau từng request (đúng kỳ vọng — mỗi
request một id, chứng minh đây là các lần gọi thật, không copy).

```text
===== QA1 independent verification start: 2026-08-19T11:42:45Z (UTC) - tool: PowerShell 5.1 Invoke-WebRequest =====

--- LOGIN viewer01 (POST http://127.0.0.1:8292/api/v1/auth/login) ---
HTTP 200 | login request_id: 15cb3b77-845e-48dc-bd66-7f103125ed18 | roles: VIEWER scope_ref=01a019d1-fdbb-7278-a8a3-156f9b7f0734 | warehouse_scope_ids: 01a019d1-fdbb-7278-a8a3-156f9b7f0734

--- AUTHZ-401 (no token) GET /api/v1/inventory/balances ---
PS> Invoke-WebRequest -Method GET -Uri http://127.0.0.1:8292/api/v1/inventory/balances
HTTP 401 | X-Request-ID: d5db8dd3-1813-4829-bb74-9154e520c08f
BODY: {"success":false,"message":"Chưa xác thực","error_code":"UNAUTHENTICATED","errors":[],"error":{"code":"UNAUTHENTICATED","details":[]},"meta":{"request_id":"d5db8dd3-1813-4829-bb74-9154e520c08f","timestamp":"2026-08-19T18:42:47+07:00"}}

--- AUTHZ-403-PERMISSION viewer01 GET /api/v1/users ---
PS> Invoke-WebRequest -Method GET -Uri http://127.0.0.1:8292/api/v1/users -Headers @{Authorization='Bearer <viewer01>'}
HTTP 403 | X-Request-ID: a171165f-ce48-4667-9568-2fab98725755
BODY: {"success":false,"message":"Bạn không có quyền thực hiện thao tác này.","error_code":"ACCESS_DENIED","errors":[],"error":{"code":"ACCESS_DENIED","details":[]},"meta":{"request_id":"a171165f-ce48-4667-9568-2fab98725755","timestamp":"2026-08-19T18:42:47+07:00"}}

--- AUTHZ-403-DATA-SCOPE viewer01 GET /api/v1/inventory/balances?warehouse_id=01a019d1-0000-7000-8000-292000000001 (KHO-B-292 ngoai scope) ---
PS> Invoke-WebRequest -Method GET -Uri http://127.0.0.1:8292/api/v1/inventory/balances?warehouse_id=01a019d1-0000-7000-8000-292000000001 -Headers @{Authorization='Bearer <viewer01>'}
HTTP 403 | X-Request-ID: 07f2fa03-5fb9-4a07-91d7-69f2238be45e
BODY: {"success":false,"message":"Kho yêu cầu nằm ngoài data scope hiện hành.","error_code":"ACCESS_DENIED","errors":[],"error":{"code":"ACCESS_DENIED","details":[]},"meta":{"request_id":"07f2fa03-5fb9-4a07-91d7-69f2238be45e","timestamp":"2026-08-19T18:42:48+07:00"}}

--- POSITIVE-CONTROL viewer01 GET /api/v1/inventory/balances?warehouse_id=01a019d1-fdbb-7278-a8a3-156f9b7f0734 (KHO-TONG-HN trong scope) ---
PS> Invoke-WebRequest -Method GET -Uri http://127.0.0.1:8292/api/v1/inventory/balances?warehouse_id=01a019d1-fdbb-7278-a8a3-156f9b7f0734 -Headers @{Authorization='Bearer <viewer01>'}
HTTP 200 | X-Request-ID: ff78e57c-6d7a-4199-b824-4aa77ab63199
BODY: {"success":true,"data":[{"id":"ddfe9fdc-54aa-4d18-9603-d24a51722bb4","warehouse_id":"01a019d1-fdbb-7278-a8a3-156f9b7f0734","warehouse_name":"Kho tổng Hòa Nam","warehouse_code":"KHO-TONG-HN",...<truncated>

===== QA1 end: 2026-08-19T11:42:49Z (UTC) =====
```

Đối chiếu RUN-1 vs QA1:

| Probe | RUN-1 | QA1 | Khớp? |
| --- | --- | --- | --- |
| A AUTHZ-401 | 401 UNAUTHENTICATED, rid `4049819e…` | 401 UNAUTHENTICATED, rid `d5db8dd3…` | ✔ (status + code) |
| B AUTHZ-403-PERMISSION | 403 ACCESS_DENIED, rid `ef50fe1e…` | 403 ACCESS_DENIED, rid `a171165f…` | ✔ |
| C AUTHZ-403-DATA-SCOPE | 403 ACCESS_DENIED (data scope message), rid `bec59e72…` | 403 ACCESS_DENIED (data scope message), rid `07f2fa03…` | ✔ |
| D POSITIVE CONTROL | 200, cùng balance id `ddfe9fdc…`, rid `47a3ec1f…` | 200, cùng balance id `ddfe9fdc…`, rid `ff78e57c…` | ✔ |

## 5. Ranh giới trung thực

- Đây là **local runtime evidence** tại exact BE SHA nêu trên (`php artisan serve` trên máy dev,
  PostgreSQL 16.14 trong docker), **không phải** môi trường UAT/staging do DevOps vận hành.
  Kết luận đúng cho code tại SHA này; hạ tầng thật (nginx/php-fpm, DB managed) có thể thêm/bớt
  header nhưng authorization logic là của application code đã probe.
- DB là bản migrate + seed cô lập (`hoa_nam_wwhn292_evidence`) với 2 chỉnh scenario có chủ đích
  (scope_ref cho viewer01 + warehouse INACTIVE thứ hai) như mô tả ở mục 1 — cần thiết vì seeder
  mặc định không có user bị giới hạn scope và schema chỉ cho phép 1 kho ACTIVE.
- Probe C dùng warehouse thứ hai ở trạng thái INACTIVE (ràng buộc `uk_warehouses_single_active`).
  Middleware `EnforceInventoryWarehouseScope` deny dựa trên so khớp `warehouse_id` với scope list
  trước khi chạm dữ liệu, không phân nhánh theo status kho, nên deny này đại diện đúng cho
  scenario "kho ngoài scope".
- Trong lúc chạy có phiên làm việc song song trên cùng working tree: HEAD tiến từ `5db3cb0`
  → `958ef1d` (2 commit chỉ chạm docs/evidence + scripts), và 1 file `database/golive/…`
  bị modified — đã kiểm tra không file nào thuộc code path của các probe.
- Server đã được dừng sau khi thu evidence; DB `hoa_nam_wwhn292_evidence` giữ lại để tái lập
  (có thể drop khi không cần).
