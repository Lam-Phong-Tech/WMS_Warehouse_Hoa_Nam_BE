# [BE1] WWHN-218 — Tăng cường phân quyền/idempotency/concurrency

## Kiểm soát

- Parent Temp Key: `HN-SEC-001`; Epic Temp Key: `HN-EPIC-03`; Dependency: `HN-QA-003`.
- Task cha: `WWHN-54` — [HARDEN] Phân quyền, idempotency, concurrency và audit tăng cường.
- Role: BE1 — Quang Nguyễn Thanh. Assignee Placeholder: `HN-BE-01`.
- Repo/branch: `Lam-Phong-Tech/Hoa_Nam_Backend` / `m1`.
- Ngày rà soát: 2026-08-14.
- Status: `REVIEWED / EVIDENCE_PARTIALLY_STALE` — code đáp ứng phần lớn Acceptance Criteria nhưng còn 4 mục cần disposition trước khi đóng (xem "Blocker / Open point").

BE1 không tự ghi `APPROVED`/`Done`/`100%`. Việc đóng task cần FE1 xác nhận đã nhận đủ contract/evidence và QA/BA xác nhận không còn P0/P1 blocker theo đúng DoD của `WWHN-218`.

## Tóm tắt kết quả rà soát

Toàn bộ 4 hạng mục hardening (RBAC, idempotency, concurrency, audit) **đã có triển khai trong code**, không phải xây mới từ đầu. Rà soát dựa trên đọc trực tiếp source, không chạy `php artisan test`/`vendor/bin/phpunit` (chưa thực thi trong phiên này).

| Hạng mục | Trạng thái | Ghi chú |
|---|---|---|
| Phân quyền (RBAC) | Đầy đủ | Enforce tại BE middleware, có permission catalog trong seeder |
| Idempotency | Đầy đủ | Header contract + lease lock + replay + conflict 409 nhất quán toàn bộ action ghi |
| Concurrency | Đầy đủ ở tầng service đang active | Test hiện có chỉ là static source assertion, chưa có test race-condition thật |
| Audit | Đầy đủ, có fail-closed | Ghi thủ công trong service, không có safety-net tự động (observer/event) |

## 1. Phân quyền (RBAC)

- Middleware: `App/Support` chuẩn hoá lỗi; enforcement chính tại `Modules/Auth/app/Http/Middleware/CheckPermission.php:15-42` — đăng ký alias `permission:<CODE>`, xác thực JWT, `401 UNAUTHENTICATED` khi chưa đăng nhập, `403 ACCESS_DENIED` khi thiếu quyền.
- 100% route gán quyền qua `->middleware('permission:xxx')`, không dùng Gate/Policy rời rạc.
- Permission catalog tập trung: `Modules/Auth/database/seeders/AuthDatabaseSeeder.php` (`PERMISSIONS` const dòng 18-92, `ROLES` mapping dòng 94-211), có lớp alias tương thích FE tại `WmsPermissionCompatibilitySeeder.php:18-27`.
- Công cụ evidence: `php artisan evidence:permission-catalog` (`app/Console/Commands/ExportPermissionCatalog.php`) xuất `build/evidence/api-data/permission-catalog.json`.
- Đối chiếu route quan trọng (`Modules/Warehouse/routes/api.php`):

| Action | Endpoint | Permission | Dòng |
|---|---|---|---|
| Post receipt | `POST inbound-documents/{id}/post-receipt` | `inbound.post` | 66 |
| Reverse inbound | `POST inbound-documents/{id}/reverse` | `inventory.reversal.post` | 67 |
| Post issue | `POST outbound-documents/{id}/post-issue` | `outbound.post` | 82 |
| Reverse outbound | `POST outbound-documents/{id}/reverse` | `inventory.reversal.post` | 83 |
| Reversal document (store) | `POST reversal-documents` | `inventory.reversal.post` | 94 |
| Report view | `GET reports/*` | `report.view` | 101-121 |
| Report export | `GET reports/*/export-excel` | `report.export` (tách biệt độc lập với view) | 103-121 |

## 2. Idempotency

- Guard chuẩn hoá: `app/Support/IdempotencyKeyGuard.php:13-47` — bắt buộc header `Idempotency-Key`, thiếu → `428 PRECONDITION_REQUIRED`; ngoài 8-100 ký tự (trim) → `422 VALIDATION_ERROR`.
- Store/replay: `app/Support/IdempotencyStore.php`, bảng `idempotency_keys` (`database/migrations/2026_01_01_000010_create_idempotency_keys_table.php:30` — unique key `(idem_key, operation, user_id)`).
  - Cùng key/cùng payload → replay response đã lưu; cùng key/khác payload → `409 IDEMPOTENCY_ERROR`.
  - Lease-based lock `LOCK_MINUTES = 5`, `lockForUpdate()` trong transaction; business write + posting event + response snapshot cùng transaction.
  - Request hash: canonical JSON + SHA-256 (có fallback MD5 legacy).
  - Response lỗi (non-2xx) không cache, lease được giải phóng để cho phép retry.
- Áp dụng nhất quán cho toàn bộ action ghi đang active: post-receipt/post-issue/reverse (`CurrentBaselineStockFlowController.php`), transfer (`TransferController.php`), warranty (`CurrentBaselineWarrantyController.php`, `WarrantyController.php`), import outbound-request, SKU governance, item identity, component flow/operations.

## 3. Concurrency

- Cơ chế đang active (route thực tế trỏ tới `CurrentBaselineStockFlowController` → `CurrentBaselineStockService`/`CurrentBaselineReversalService`):
  - Pessimistic lock `lockForUpdate()` trên document/line/balance trước khi ghi ledger.
  - Optimistic concurrency bổ sung bằng cột `version` + header `If-Match` → `PRECONDITION_FAILED` khi version lệch (`CurrentBaselineStockFlowController.php:200-252`).
  - `VERSION_CONFLICT` (409) khi update `inventory_balances` thất bại do version đổi giữa lúc đọc và ghi.
  - Reversal: `REVERSAL_QTY_EXCEEDED`, `REVERSAL_CONCURRENT_CONFLICT`, `REVERSAL_OF_REVERSAL_FORBIDDEN` trong `CurrentBaselineReversalService.php`.
- Test hiện có (`tests/Feature/StockDocumentConcurrencyGuardTest.php`): **static source assertion** (`assertStringContainsString` trên nội dung file service/controller), không mô phỏng 2 request đồng thời thật. Việc test race-condition thực tế hiện nằm ở script Node ngoài PHPUnit (`scripts/run-wwhn172-concurrency.mjs`, `scripts/run-wwhn150-concurrent-scan.mjs`), không chạy trong `php artisan test`.

## 4. Audit log

- `Modules/AuditLog/app/Models/AuditLog.php:31-40`: bảng `audit_log` append-only enforced ở tầng model (`updating`/`deleting` throw `LogicException`).
- Migration `2026_01_01_000040_create_audit_log_table.php:15-30`: `actor_id`, `action`, `object_type`, `object_id`, `reason`, `request_id`, `before_data`, `after_data`.
- Service ghi duy nhất: `Modules/AuditLog/app/Services/AuditLogger.php` — fail-safe qua `AuditOutbox` nếu insert `audit_log` lỗi; nếu cả hai lỗi thì throw để chặn request (`write_policy` mặc định `outbox_required`, có mode `fail_closed`). Có `sanitize()` loại bỏ sensitive keys.
- Coverage: post/reverse (`StockDocumentService` — legacy, xem mục "Ghi chú kỹ thuật"), thay đổi phân quyền (`RoleController::ASSIGN_PERMISSIONS`, `UserController::ASSIGN_ROLES`), export báo cáo (`OperationalReportController::REPORT_EXPORTED`), và ~42 điểm gọi khác trong `Modules/Warehouse`.
- `Modules/AuditLog/app/Providers/EventServiceProvider.php` không có listener nào đăng ký (`$listen = []`) — xác nhận 100% audit call là gọi thủ công trong service/controller, không có observer/event tự động bắt các thay đổi nghiệp vụ.

## Test evidence hiện có

| Hạng mục | File |
|---|---|
| RBAC | `AuthorizationTest.php`, `PermissionCatalogTest.php`, `IamRolePermissionReadinessTest.php`, `PermissionAndValidationTest.php`, `Wwhn180PermissionAliasContractTest.php` |
| Idempotency | `Wwhn180IdempotencyAtomicityTest.php`, `StockDocumentIdempotencyTest.php`, `WarrantyIdempotencyKeyContractTest.php` |
| Concurrency | `StockDocumentConcurrencyGuardTest.php` (static assertion, xem mục 3) |
| Audit | `AuthRbacAuditHardeningTest.php`, `SecurityRbacAuditNegativeTest.php`, `Wwhn172AuditFailClosedTest.php`, `FoundationAuthRbacAuditTest.php` |

`tests/Unit` chỉ có `ExampleTest.php` mặc định — toàn bộ coverage 4 hạng mục nằm ở `tests/Feature`. `artisan`, `phpunit.xml`, `vendor/bin/phpunit` đều sẵn sàng để chạy; **chưa chạy suite trong phiên rà soát này**, cần QA/CI xác nhận PASS trước khi đóng task.

## Ghi chú kỹ thuật (không phải bug, cần disposition)

1. **Code trùng lặp không active**: `Modules/Warehouse/app/Services/StockDocumentService.php` (post/reverse dùng bảng `posting_event` riêng) và `Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockController.php` không được route nào trong `Modules/Warehouse/routes/*.php` trỏ tới — route thật dùng `CurrentBaselineStockFlowController` + `CurrentBaselineStockService`/`CurrentBaselineReversalService` + `IdempotencyStore`. Hai file cũ vẫn còn audit log call và lock riêng, nhưng không nằm trên đường ghi dữ liệu thực tế. Đề xuất: xác nhận với team có an toàn để dọn dẹp (xoá hoặc archive) hay giữ lại vì lý do khác trước khi coi là dead code chính thức.
2. **Evidence permission catalog lỗi thời**: `docs/permissions/WMS_PERMISSION_CATALOG_BE1.json` và `docs/evidence/WWHN-180/permission-catalog.json` là snapshot cũ (`generated_at_utc` 2026-08-03, `active_permission_count: 31`) trong khi `AuthDatabaseSeeder::PERMISSIONS` hiện tại đã nhiều hơn đáng kể. Cần chạy lại `php artisan evidence:permission-catalog` trước khi gắn evidence vào `HN-SEC-001`.
3. **Route "apply-adjustment" không tồn tại**: yêu cầu gốc của `WWHN-54`/QA có nhắc "adjustment"/concurrency guard cho điều chỉnh tồn kho, nhưng codebase hiện tại không có route hay service tên "adjust"/"stocktake" độc lập — nghiệp vụ điều chỉnh tồn kho hiện chỉ đi qua reversal/correction (`reversal-documents`, permission `inventory.reversal.post`). Cần BA/QA xác nhận đây là đã map đúng sang reversal hay là tính năng chưa được xây (ngoài scope tự ý mở rộng của BE1).
4. **Thiếu test race-condition thực sự trong PHPUnit**: `StockDocumentConcurrencyGuardTest.php` chỉ kiểm tra sự hiện diện của `lockForUpdate()`/`version`/mã lỗi trong source code, không spawn 2 request đồng thời qua HTTP/DB thật. Việc test race-condition thực tế hiện phụ thuộc script Node riêng ngoài CI test chuẩn (`scripts/run-wwhn172-concurrency.mjs`, `scripts/run-wwhn150-concurrent-scan.mjs`) — cần QA xác nhận các script này có chạy trong pipeline CI hay chỉ chạy thủ công.

## Blocker / Open point

| ID | Nội dung | Owner đề xuất | Disposition |
|---|---|---|---|
| BE1-OP-01 | Regenerate `permission-catalog.json` evidence khớp seeder hiện tại | BE1 | Mở |
| BE1-OP-02 | Xác nhận dọn dẹp hay giữ `StockDocumentService`/`CurrentBaselineStockController` (dead code) | BE1 + Tech Lead | Mở |
| BE1-OP-03 | Làm rõ route "apply-adjustment" đã map sang reversal hay chưa được xây | BA/QA | Mở, cần disposition trước khi đóng `HN-SEC-001` |
| BE1-OP-04 | Xác nhận script concurrency Node có chạy trong CI hay chỉ manual; cân nhắc bổ sung PHPUnit race-condition test thật | BE1 + DevOps | Mở |

## Output đầu ra

Core tăng cường (RBAC/idempotency/concurrency/audit) đã tồn tại và hoạt động đúng baseline nghiệp vụ được rà soát ở trên. Chưa phát hiện lỗ hổng chức năng (missing permission check, missing idempotency guard, missing lock, missing audit) tại các endpoint state-changing đã khảo sát. Các điểm ở mục "Blocker / Open point" cần disposition từ BA/QA/Tech Lead trước khi FE1/QA nhận đủ evidence và đóng `HN-SEC-001`.

## Definition of Done

- Code, review, unit/integration test: **đã có sẵn**, chưa chạy suite trong phiên này — cần CI/QA xác nhận PASS.
- Contract/API: không có thay đổi contract trong phiên rà soát này (chỉ đọc và đối chiếu); nếu BE1-OP-02/03 dẫn tới thay đổi code, phải cập nhật OpenAPI/Postman tương ứng.
- FE/QA nhận đủ đầu ra: **chưa** — cần BE1 đóng 4 open point ở trên trước khi bàn giao chính thức.
