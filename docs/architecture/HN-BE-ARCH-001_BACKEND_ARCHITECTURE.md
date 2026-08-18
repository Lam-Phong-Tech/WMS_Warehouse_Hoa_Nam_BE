# HN-BE-ARCH-001 — Kiến trúc backend WMS Hoa Nam (as-built)

| Trường | Giá trị |
| --- | --- |
| Tài liệu | HN-BE-ARCH-001 |
| Task | WWHN-309 — [BE] Hoàn thiện tài liệu kiến trúc/API/vận hành |
| Parent | WWHN-83 — [HANDOVER] Gói bàn giao sản phẩm, kỹ thuật, QA, release và vận hành |
| Repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE`, nhánh `m1` |
| Phạm vi | Kiến trúc **đang chạy thật** của backend, không phải kiến trúc mong muốn |
| Cập nhật | 2026-08-18 |

Tài liệu chuẩn được đối chiếu khi viết bản này:

1. `docs/HN_SRS_FRD_v1.12_FULL.md` — requirement/flow/AC hiện hành.
2. `docs/HoaNam_ERD_Moi_Nhat.md` — schema vật lý.
3. `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md` — chuẩn kiến trúc/quy ước (chương FE là chính, chương API/envelope/RBAC áp dụng cho cả hai phía).
4. `HN_WMS_Wireframe_MVP_ALIGNED_v3.20_v1.12_20260814.html` — baseline màn hình/trạng thái mà API phải phục vụ.
5. `docs/HN-BE-001_BACKEND_STANDARD.md` — chuẩn ngôn ngữ/tech stack backend.

**Phân biệt quan trọng:** HN-BE-001 là *standard* (phải làm gì). Tài liệu này là *as-built* (đang là gì). Khi hai bên lệch, as-built được ghi nguyên trạng kèm ghi chú, không sửa tài liệu để che lệch.

---

## 1. Bất biến nghiệp vụ mà kiến trúc phải bảo vệ

Đây là các ràng buộc không được phá vỡ bởi bất kỳ refactor nào. Mọi mục dưới đây đều bắt nguồn từ SRS v1.12 và các Decision Record đã chốt.

| # | Bất biến | Thực thi ở đâu |
| --- | --- | --- |
| 1 | **Không có Stocktake/sửa tồn trực tiếp trong MVP.** Không route, không permission, không endpoint. | Route table (không tồn tại route stocktake), `scripts/check-api-mapping.mjs` phía FE, contract test |
| 2 | **Full-post, không partial posting.** Nhập/xuất chỉ ghi sổ khi phiếu đã khớp đầy đủ. | `StockLedger`, `CurrentBaselineStockService`, error `FULL_POST_REQUIRED` / `FULL_SCAN_REQUIRED_FOR_LABEL` |
| 3 | **Chỉ Post Receipt / Post Issue / Reversal đã duyệt được thay đổi tồn.** Scan/classify/mapping/putaway là stock-neutral. | `StockLedger` là đường ghi duy nhất; scan chỉ cập nhật `scanned_qty` |
| 4 | **Movement đã post là bất biến.** Sửa sai đi qua Reversal append-only. | `ReversalDocumentController` + `CurrentBaselineReversalService`; error `REVERSAL_POSTED_IMMUTABLE`, `SCANNED_DOCUMENT_IMMUTABLE` |
| 5 | **RFID là tuỳ chọn**, không phải cổng chặn Post Issue. | Không có gate RFID trong `post-issue`; `physical_code.assign_rfid` là hành động riêng |
| 6 | **Outbound: Scan → Packing Label (preview/ready/print + xác nhận đã dán) → Post Issue.** Một nhãn cho mỗi phiếu/lô. | `AlignedOutboundFlowController`, error `PACKING_LABEL_REQUIRED`, `PACKING_LABEL_NOT_APPLIED` |
| 7 | **Audit là append-only** và không được im lặng khi ghi lỗi. | `AUDIT_WRITE_POLICY=outbox_required`, `audit_outbox` + `audit:reconcile` |
| 8 | **App PV là read-only ẩn danh.** | 4 route `/api/v1/pv/*` public, chỉ đọc dữ liệu đã publish |

---

## 2. Tổng quan hệ thống

```text
        Browser (React SPA - repo FE)
                 │  HTTPS, JWT Bearer + refresh cookie HttpOnly
                 ▼
        ┌────────────────────┐
        │ nginx              │  reverse proxy, cổng 8080
        └─────────┬──────────┘
                  ▼
        ┌────────────────────────────────────────────┐
        │ Laravel 13 modular monolith (PHP 8.3)      │
        │  ├── routes/api.php        (v1 platform)   │
        │  ├── Modules/Auth          (IAM, JWT)      │
        │  ├── Modules/Warehouse     (nghiệp vụ WMS) │
        │  └── Modules/AuditLog      (audit trail)   │
        └───┬───────────┬───────────┬────────────────┘
            │           │           │
            ▼           ▼           ▼
      PostgreSQL     Valkey      S3/MinIO
      (nguồn sự      (cache,     (file đính kèm,
       thật)          session,    file export)
                      queue)
            ▲           ▲
            │           │
      ┌─────┴───────────┴──────┐
      │ queue worker           │  php artisan queue:work
      │ scheduler              │  php artisan schedule:work
      └────────────────────────┘
```

**PostgreSQL là nguồn sự thật duy nhất.** Valkey chỉ là cache/queue/session: mất toàn bộ Valkey không làm mất dữ liệu nghiệp vụ (xem §7).

---

## 3. Kiến trúc module

Backend là **modular monolith** dùng `nwidart/laravel-modules`. Ba module:

| Module | Trách nhiệm | Đường dẫn |
| --- | --- | --- |
| `Auth` | User, role, permission, JWT, refresh token, đổi mật khẩu | `Modules/Auth/` |
| `Warehouse` | Toàn bộ nghiệp vụ WMS: master data, inbound, outbound, putaway, inventory, reversal, warranty, label, report, import | `Modules/Warehouse/` |
| `AuditLog` | Đọc audit trail (ghi do `AuditLogger` trong Warehouse/Auth thực hiện) | `Modules/AuditLog/` |

Phân lớp bên trong module `Warehouse`:

```text
Http/Controllers   → validate đầu vào, dựng envelope, khai OpenAPI, gọi service
Http/Middleware    → data-scope theo kho, ràng buộc canonical warehouse/box
Services           → quy tắc nghiệp vụ + biên giao dịch (StockLedger, ...)
Repositories       → truy vấn dữ liệu, khoá bản ghi
Models             → Eloquent, quan hệ, cast
Support            → helper thuần (Idempotency, PII masker, filename, schema)
Database           → migration + seeder
```

**Quy tắc phân lớp:** controller không viết SQL nghiệp vụ, service không dựng HTTP response (ném lỗi qua `ApiError::abort()` để giữ đúng envelope), repository không quyết định quy tắc nghiệp vụ.

### 3.1. Điểm cần biết khi tiếp nhận

- `Modules/Warehouse/app/Http/Controllers` có **55 controller**. Nhóm tiền tố `CurrentBaseline*` là các controller được viết lại theo baseline hợp đồng hiện hành; nhóm `Aligned*` là các controller đã được căn lại theo Annex C. Đây là dấu vết lịch sử, không phải hai kiến trúc song song.
- `StockLedger` là **đường ghi tồn duy nhất**. Thêm bất kỳ đường ghi `stock_movements`/`inventory_balances` nào khác là vi phạm bất biến #3.
- Alias Annex C (`/scans`, `/reversals`, `/putaway-tasks`, ...) dùng **chung handler** với route hiện hành. Đây là chủ ý để FE cũ/mới cùng chạy, không phải trùng lặp code.

---

## 4. Biên request: middleware pipeline

Thứ tự thực thi (khai tại `bootstrap/app.php`):

| Thứ tự | Middleware | Vai trò |
| --- | --- | --- |
| 1 | `NoStoreApiResponses` | Ép `Cache-Control: no-store` cho response API riêng tư (WWHN-325) |
| 2 | `ApiConventionMiddleware` | Sinh `request_id`, chuẩn hoá envelope cho response chưa gắn cờ `X-WMS-Envelope-Ready` |
| 3 | `Wwhn172AggregateLock` | Khoá aggregate cho route mutation + ép `audit.write_policy=fail_closed` trong phạm vi request |
| 4 | `auth:api` | Xác thực JWT |
| 5 | `permission:<code>` (`CheckPermission`) | Kiểm permission; hỗ trợ `A|B` nghĩa là **OR** |
| 6 | `EnforceInventoryWarehouseScope` | Siết data scope theo kho cho nhóm endpoint read/trace |

`EnforceInventoryWarehouseScope` được đăng ký **toàn cục** và tự no-op ngoài phạm vi của nó. Lý do ghi ngay trong `bootstrap/app.php`: đăng ký theo từng route sẽ bị mất im lặng khi có người refactor route — fail-open về bảo mật. Cách hiện tại là fail-closed theo thiết kế.

---

## 5. Contract API

Chi tiết đầy đủ nằm ở `docs/api-contract/HN-BE-API-001_API_CONTRACT_HANDOVER.md`. Tóm tắt phần thuộc kiến trúc:

- **Envelope thành công:** `{success, message, data, meta{request_id, timestamp}, links?}`.
- **Envelope lỗi:** `{success:false, message, error_code, errors, error{code, details}, meta}`. `error_code` và `error.code` luôn bằng nhau.
- **Nguồn sinh envelope:** `App\Traits\ApiResponse` (controller) và `App\Support\ApiError::abort()` (service/repository). Không có đường thứ ba.
- **OpenAPI:** `storage/api-docs/api-docs.json|yaml`, sinh bởi l5-swagger + `App\Swagger\Processors\RouteSyncProcessor`. Processor đọc **route table thật** nên spec không thể thiếu endpoint mà không ai biết.

---

## 6. Giao dịch, đồng thời và idempotency

Ba cơ chế độc lập, dễ nhầm lẫn — phân biệt rõ:

### 6.1. Transaction

Mọi thao tác ghi tồn chạy trong `DB::transaction()`. Đọc bản ghi bị tranh chấp dùng `lockForUpdate()` (pessimistic row lock). Lỗi giữa chừng → rollback toàn bộ, không có trạng thái nửa vời.

### 6.2. Optimistic locking — `If-Match`

- Client gửi `If-Match: <version hiện tại>`.
- Version không khớp → **412 `STALE_VERSION`**.
- Endpoint bắt buộc mà thiếu header → **428 `PRECONDITION_REQUIRED`**.
- Post thành công → `version` tăng 1.

Enforcement nằm **duy nhất ở tầng controller** (các helper `assertVersion` / `assertVersionIfProvided` / `expectedVersion`). Không còn middleware optimistic locking ở tầng route: `App\Http\Middleware\CurrentBaselineIfMatch` đã được **gỡ** theo `DEC-API-IFMATCH-001` — nó không gắn vào route nào nhưng lại là nguồn duy nhất của hai error code (`RESOURCE_ID_REQUIRED`, `VERSION_CONTRACT_MISSING`) được publish cho FE/QA mà không đường chạy nào trả về được. `tests/Feature/CurrentBaselineIfMatchContractTest.php` giữ nguyên tên file và nay khoá chính trạng thái đó.

Muốn đưa optimistic locking về tầng route thì phải làm đủ điều kiện trong `DEC-API-IFMATCH-001`, quan trọng nhất là gỡ helper ở đúng các controller đó để `version` không tăng hai lần cho một request.

### 6.3. Idempotency — `Idempotency-Key`

- `App\Support\IdempotencyKeyGuard`: độ dài 8–100 ký tự.
- `require()` → thiếu header là **428**; `optional()` → thiếu header vẫn chạy (áp dụng cho nhóm warranty stock-neutral theo Annex C §8.1).
- `App\Support\IdempotencyStore`: gửi lại **cùng key + cùng payload** trả về kết quả lần đầu (replay); **cùng key + payload khác** trả **409**.

Từ WWHN-309, hai header này được **tự động khai vào OpenAPI** dựa trên chính source của action (`RouteSyncProcessor::contractHeaders`), nên contract không còn lệch runtime: 27 operation khai `Idempotency-Key`, 58 operation khai `If-Match`.

---

## 7. Cache (Valkey)

- Store: Valkey qua `predis`, DB 1 riêng cho application cache; DB 0 cho session/queue. Cô lập để lệnh xoá cache ứng dụng không bao giờ chạm session/queue.
- TTL tập trung tại `config/wms_cache.php`, đọc qua `App\Support\Cache\CacheTtl`. **Không hard-code TTL rải rác.**
- `CACHE_TTL_JITTER_PERCENT` chống hết hạn đồng loạt; `Cache::lock()` chống stampede trên hot key.
- `CACHE_FAIL_OPEN=true`: Valkey lỗi thì rơi về PostgreSQL thay vì hỏng response.
- `CACHE_PREFIX` phải khác nhau giữa các môi trường dùng chung một Valkey instance.

**Hệ quả kiến trúc:** cache không bao giờ là nguồn sự thật. `/api/v1/health` cố ý **không** coi `cache` là critical check — nếu coi là critical, liveness probe sẽ restart-loop chỉ vì Valkey chập chờn dù API vẫn phục vụ được từ DB.

---

## 8. Audit trail

- Chính sách ghi: `AUDIT_WRITE_POLICY` — `outbox_required` (mặc định), `fail_closed`, `best_effort`.
- Ghi audit lỗi → đẩy vào `audit_outbox` kèm backoff; `audit:reconcile` (chạy nền mỗi 5 phút) rút outbox về `audit_log`.
- Route mutation trong phạm vi `Wwhn172AggregateLock` bị **ép `fail_closed`** theo request rồi khôi phục policy ở `finally`.
- Giá trị nhạy cảm bị che theo `config('audit.sensitive_keys')`.

Không có `audit:reconcile` chạy nền thì outbox phình vô hạn và audit trail của các bản ghi đó không bao giờ hoàn tất — đây là nghĩa vụ vận hành, không phải tuỳ chọn (xem HN-BE-OPS-001 §4).

---

## 9. Bảo mật

| Mặt | Cách làm hiện tại |
| --- | --- |
| Xác thực | JWT (`tymon/jwt-auth`), access token giữ trong bộ nhớ FE, không lưu localStorage |
| Refresh | Cookie HttpOnly `wms_refresh_token`, path `/api/v1/auth`; môi trường ngoài local ép `Secure=true` ở tầng config kể cả khi env deploy còn sót `false` |
| Phân quyền | Theo **permission code**, không theo tên role. 57 permission code đang gác 231 operation |
| Data scope | `EnforceInventoryWarehouseScope` cho nhóm read/trace tồn kho |
| CORS | Origin liệt kê tường minh (`CORS_ALLOWED_ORIGINS`), không wildcard; credentialed CORS là bất biến giao thức vì refresh cookie |
| Cache HTTP | `no-store` cho response API riêng tư |
| Rò rỉ thông tin | Envelope lỗi không chứa stack trace/SQL/secret; PII bảo hành có masker + lệnh purge theo retention |
| Endpoint public | Đúng 7, được allowlist tường minh trong `scripts/generate-endpoint-permission-matrix.mjs`; thêm route public mới mà không khai ở đó sẽ làm generator fail |

---

## 10. Dữ liệu

- PostgreSQL 16. Migration nằm trong `Modules/*/database/migrations`.
- Khoá chính UUID cho hầu hết bảng nghiệp vụ.
- Sổ cái tồn kho: `stock_movements` (append-only) là nguồn sự thật, `inventory_balances` là số dư dẫn xuất. Lệch giữa hai bên được phát hiện bởi `inventory:reconcile` và báo cáo `GET /api/v1/reports/inventory-reconciliation`.
- Chi tiết bảng/khoá/quan hệ: `docs/HoaNam_ERD_Moi_Nhat.md`.

**Lưu ý test:** bộ test mặc định chạy trên SQLite in-memory (`phpunit.xml`), bộ PostgreSQL chạy bằng `phpunit.pgsql.xml`. Một số hành vi phụ thuộc driver (`Schema::hasTable` với view, kiểu cột) khác nhau giữa hai driver — luôn ghi rõ driver khi trích dẫn kết quả test.

---

## 11. Ranh giới không thuộc backend

Để tránh ký chồng vai trò trong gói bàn giao WWHN-83:

| Hạng mục | Chủ sở hữu |
| --- | --- |
| Build/component/UI FE | FE1 — WWHN-311 |
| Tài liệu dữ liệu/migration/báo cáo | BE — WWHN-310 |
| Ops/access/backup/monitor cấp hạ tầng | DevOps — WWHN-313 |
| Test/release evidence | QA — WWHN-312 |
| Handover index/product/training | BAS — WWHN-308 |

Tài liệu này chỉ ký phần **kiến trúc/API/vận hành phía backend**.

---

## 12. Tài liệu liên quan

| Tài liệu | Nội dung |
| --- | --- |
| `docs/api-contract/HN-BE-API-001_API_CONTRACT_HANDOVER.md` | Contract API bàn giao cho FE/QA |
| `docs/api-contract/HN-BE-API-002_ERROR_CODE_CATALOG.md` | 246 error code, sinh tự động từ source |
| `docs/api-contract/HN-BE-API-003_ENDPOINT_PERMISSION_MATRIX.md` | Ma trận endpoint × permission × OpenAPI |
| `docs/ops/HN-BE-OPS-001_OPERATIONS_RUNBOOK.md` | Vận hành: deploy, job nền, health, sự cố |
| `docs/permissions/WMS_PERMISSION_CATALOG_BE1.md` | Danh mục permission chuẩn |
| `docs/decision-records/` | Decision Record đã chốt |
| `README.md` | Build/run/test tại chỗ |
