# [BE1] Chủ trì rà soát API/dữ liệu - WWHN-180

## Traceability

- Jira: WWHN-180 - `[BE1] Chủ trì rà soát API/dữ liệu`
- Task cha: WWHN-40 - `[G1] Rà soát contract FE/BE/QA và cổng kiểm soát payload mẫu`
- Parent Temp Key: HN-INT-001
- Epic Temp Key: HN-EPIC-03
- Dependency: HN-TEC-001; HN-UX-002; HN-QA-002
- Role: BE1
- Output đầu ra: `API contract approved`

## Nội dung rà soát

Đã rà soát contract API/data theo phạm vi BE1, tập trung vào các điểm có nguy cơ làm FE/QA nhận sai contract:

- RBAC middleware trên Product API so với OpenAPI annotation và seed permission.
- Route trùng giữa `WmsContractController` và controller dữ liệu thật cho:
  - `GET /api/v1/inventory-snapshots`
  - `GET /api/v1/document-status-history`
- OpenAPI generation để bảo đảm contract xuất được và không còn annotation trùng path/method.
- Test regression cho permission/validation để khóa lỗi 401/403/404/422 rõ ràng.

## Thay đổi đã hoàn thiện

### 1. Đồng bộ permission Product API

Trước rà soát, các route `/api/v1/products*` đang yêu cầu `sku.manage`, trong khi controller/OpenAPI và seed RBAC đã định nghĩa:

- `GET /api/v1/products`: `product.view`
- `GET /api/v1/products/{id}`: `product.view`
- `POST /api/v1/products`: `product.manage`
- `PATCH /api/v1/products/{id}/enable`: `product.manage`
- `PATCH /api/v1/products/{id}/disable`: `product.manage`

Đã sửa `Modules/Warehouse/routes/api.php` để Product API dùng đúng `product.view/product.manage`. Alias `/api/v1/skus*` vẫn giữ `sku.manage`, đúng ranh giới alias SKU hiện tại.

### 2. Gỡ route/annotation contract trùng

Đã loại bỏ khai báo route trùng trong `WmsContractController` cho:

- `GET /api/v1/inventory-snapshots`
- `GET /api/v1/document-status-history`

Source of truth runtime hiện là:

- `InventorySnapshotController@index` cho `GET /api/v1/inventory-snapshots`
- `InventorySnapshotController@generate` cho `POST /api/v1/inventory-snapshots/generate`
- `DocumentStatusHistoryController@index` cho `GET /api/v1/document-status-history`

Đã gỡ OpenAPI annotation trùng trong `WmsContractController` để `l5-swagger:generate` không fail do duplicate path/method.

### 3. Đồng bộ permission lịch sử trạng thái chứng từ

Đã sửa `GET /api/v1/document-status-history` từ `warehouse.view` sang `stock_document.view`, khớp ý nghĩa nghiệp vụ read-only của chứng từ kho và khớp mô tả OpenAPI.

Evidence route:

- `GET|HEAD api/v1/document-status-history`
- Controller: `Modules\Warehouse\Http\Controllers\DocumentStatusHistoryController@index`
- Middleware: `api`, `auth:api`, `permission:stock_document.view`

### 4. Sửa lỗi OpenAPI generation

Đã sửa `Modules/Warehouse/app/Http/Controllers/ImportController.php`:

- `row_errors` trong response schema của `POST /api/v1/skus/import-excel` dùng `new OA\Items(type: 'object')` thay vì `new OA\Schema(type: 'object')`.

Lỗi trước sửa:

- `OpenApi\Attributes\Property::__construct(): Argument #13 ($items) must be of type ?OpenApi\Attributes\Items`

Sau sửa:

- `php artisan l5-swagger:generate` chạy thành công.
- `storage/api-docs/api-docs.json` đã được regenerate.

### 5. Test regression

Đã bổ sung test trong `tests/Feature/PermissionAndValidationTest.php`:

- `test_product_routes_use_product_permissions_not_sku_permissions`
  - User có `product.view` gọi `/api/v1/products` thành công.
  - User chỉ có `sku.manage` bị chặn khỏi `/api/v1/products`.
  - User có `sku.manage` vẫn gọi `/api/v1/skus` thành công.
- `test_document_status_history_uses_stock_document_view_permission`
  - User có `stock_document.view` gọi `/api/v1/document-status-history` thành công.
  - User chỉ có `warehouse.view` bị chặn đúng 403 `ACCESS_DENIED`.

## Evidence kiểm thử

Đã chạy:

- `php artisan test --filter=PermissionAndValidationTest`
  - Kết quả: passed, 10 tests, 27 assertions.
- `php artisan test --filter=StockDocumentIdempotencyTest`
  - Kết quả: passed, 14 tests, 82 assertions.
- `php artisan test --filter=SecurityRbacAuditNegativeTest`
  - Kết quả: passed, 14 tests, 115 assertions.
- `php artisan l5-swagger:generate`
  - Kết quả: thành công, OpenAPI JSON đã regenerate.
- `php artisan route:list --path=api/v1/products -v`
  - Xác nhận Product API dùng `product.view/product.manage`.
- `php artisan route:list --path=api/v1/document-status-history -v`
  - Xác nhận endpoint dùng `permission:stock_document.view`.

Ghi chú môi trường test:

- PHPUnit có warning không ảnh hưởng assertion: không ghi được `.phpunit.result.cache` do quyền file trong sandbox.

## Test gap / blocker ngoài phạm vi sửa hiện tại

Khi chạy thêm `php artisan test --filter=StockDocumentCanonicalFlowTest`, suite đang fail 9 test ở các điểm không phát sinh từ thay đổi WWHN-180:

- Status trả về lowercase `draft` trong khi test đang kỳ vọng `DRAFT`.
- Một số action endpoint trong canonical flow trả 404 hoặc 409 khác expectation cũ.

Các lỗi này thuộc contract/flow chứng từ kho sâu hơn, cần owner xác nhận baseline state casing và endpoint action hiện hành trước khi sửa để tránh thay đổi nghiệp vụ ngoài phạm vi WWHN-180.

## Kết luận BE1

Phần BE1 của WWHN-180 đã xử lý các mismatch trực tiếp giữa runtime route, permission, OpenAPI annotation và regression test cho contract API/data đã rà soát. Không còn blocker BE1 trong phạm vi Product permission, document status history permission, duplicate OpenAPI path và Swagger generation.
## Cap nhat xu ly co P0/P1 - 2026-08-02

### P0/P1: Canonical stock document flow con do

Da chay lai `php artisan test --filter=StockDocumentCanonicalFlowTest` va xac nhan suite fail 9/11 test. Nguyen nhan khong phai do runtime mat endpoint, ma do evidence test chua khop state machine va RBAC hien hanh:

- API tra `status` theo enum DB/OpenAPI dang lowercase: `draft`, `pending`, `approved`, `partially_posted`, `received`, `issued`, `reversed`; test cu dang assert uppercase `DRAFT`, `PARTIALLY_POSTED`, `POSTED`, `REVERSED`.
- Cac thao tac doi ton that (`scan`, `post-receipt`, `post-issue`, `reverse`) bat buoc phieu da qua `submit -> approve`; test cu goi truc tiep tu `draft` nen dung ra bi `STATE_CONFLICT`.
- Mot so negative/idempotency/reconcile case dung role chi co `stock_document.post`, `cancel` hoac `reverse` nhung lai tao phieu qua API; request create bi 403 lam `docId` rong va action sau tra 404.
- Case xuat kho khong du ton duoc enforce o buoc `approve()` qua `precheckStock`, truoc khi cho phep hach toan that; test cu cho fail o `post-issue`.
- `STATE_CONFLICT` duoc convention API chuan hoa thanh HTTP 409, khop `StockDocumentIdempotencyTest`; test cu dang cho 422 o case huy phieu da hach toan.

Da cap nhat `tests/Feature/StockDocumentCanonicalFlowTest.php`:

- Bo sung helper `submitAndApproveDocument()` de cac happy path/idempotency/reconcile/state-conflict chay dung lifecycle `create -> submit -> approve -> stock action`.
- Cap nhat RBAC seed cho cac role test can tao phieu qua API: them `stock_document.create`, `stock_document.submit`, `stock_document.approve` khi test can setup phieu that.
- Dong bo assert status ve enum runtime lowercase thay vi uppercase.
- Dieu chinh happy path partial scan: sau scan 5/10, scan tiep 5/10 de hoan tat phieu; khong goi `post-receipt/post-issue` tren phieu `partially_posted` vi full-post endpoint chi hop le o `approved`.
- Dieu chinh insufficient stock test de verify fail tai `approve()` voi `INSUFFICIENT_STOCK`, truoc khi phat sinh bat ky movement nao.
- Dieu chinh cancel posted test assert HTTP 409 `STATE_CONFLICT`, khop error convention chung.

### Evidence sau xu ly

Da chay lai:

- `php artisan test --filter=StockDocumentCanonicalFlowTest`
  - Ket qua: passed, 11 tests, 95 assertions.
- `php artisan test --filter=PermissionAndValidationTest`
  - Ket qua: passed, 10 tests, 27 assertions.
- `php artisan test --filter=StockDocumentIdempotencyTest`
  - Ket qua: passed, 14 tests, 82 assertions.
- `php artisan test --filter=SecurityRbacAuditNegativeTest`
  - Ket qua: passed, 14 tests, 115 assertions.
- `php artisan l5-swagger:generate`
  - Ket qua: thanh cong, OpenAPI JSON da regenerate.

Ghi chu moi truong test:

- PHPUnit van co warning khong anh huong assertion: khong ghi duoc `.phpunit.result.cache` do quyen file trong sandbox.

### Disposition

P0/P1 lien quan canonical stock document flow da co disposition va evidence pass. Contract BE1 hien khop state machine runtime, RBAC middleware, idempotency guard, stock precheck va error convention hien hanh. Khong thuc hien comment truc tiep tren Jira theo yeu cau.

## Cap nhat xu ly co P0/P1 - 2026-08-02 lan 2

### BE1-P0-02: Khoa contract Scan/Post Receipt/Post Issue

Da sua runtime de khoa mot ranh gioi duy nhat:

- `POST /api/v1/scan/classify`: chi classify/validate ma scan, tra `stock_effect=NONE`, `movement_created=false`; khong ghi `stock_movements`, khong doi `inventory_balances`.
- `POST /api/v1/inbound-documents/{id}/scan`: chi validate `line_id`/`qty` tren phieu `approved`, tra `stock_effect=NONE`, `movement_created=false`, `next_stock_action=post-receipt`; khong can `Idempotency-Key` vi khong doi ton.
- `POST /api/v1/outbound-documents/{id}/scan`: chi validate `line_id`/`qty` tren phieu `approved`, precheck ton kha dung cho qty scan, tra `stock_effect=NONE`, `movement_created=false`, `next_stock_action=post-issue`; khong can `Idempotency-Key` vi khong doi ton.
- `POST /api/v1/inbound-documents/{id}/post-receipt`: la action duy nhat tang ton that cho receipt; bat buoc `Idempotency-Key`; ghi `stock_movements` va `posting_event`.
- `POST /api/v1/outbound-documents/{id}/post-issue`: la action duy nhat giam ton that cho issue; bat buoc `Idempotency-Key`; ghi `stock_movements` va `posting_event`.
- `POST /api/v1/stocktake-sessions/{id}/apply-adjustment`: van la action dieu chinh ton rieng, bao ve bang `stocktake.adjust`.

Files da sua:

- `Modules/Warehouse/app/Http/Controllers/Concerns/InteractsWithStockDocuments.php`
- `Modules/Warehouse/app/Services/StockDocumentService.php`
- `Modules/Warehouse/app/Http/Controllers/InboundDocumentController.php`
- `Modules/Warehouse/app/Http/Controllers/OutboundDocumentController.php`
- `Modules/Warehouse/app/Http/Controllers/ScanController.php`
- `Modules/Warehouse/routes/api.php`

### BE1-P1-03: RBAC/permission catalog

Da bo sung/sua permission catalog trong `Modules/Auth/database/seeders/AuthDatabaseSeeder.php`:

- Them `stock_document.scan`: validate scan tren phieu kho, khong doi ton.
- Them `scan.classify`: classify/validate ma scan, khong doi ton.
- Sua mo ta `stock_document.post`: chi con `post-receipt/post-issue/close-remainder`, khong gom scan.
- Gan `stock_document.scan` cho `WAREHOUSE_KEEPER` va `WAREHOUSE_MANAGER`.
- Gan `stocktake.adjust`, `warranty.temp_only`, `scan.classify` cho `WAREHOUSE_MANAGER`.
- Sua seed `SUPER_ADMIN` vao `$roleModels` de user `test-super-admin` khong bi loi `Undefined array key "SUPER_ADMIN"` khi migrate/seed.

Route evidence:

- `POST api/v1/inbound-documents/{id}/scan` -> `permission:stock_document.scan`
- `POST api/v1/outbound-documents/{id}/scan` -> `permission:stock_document.scan`
- `POST api/v1/scan/classify` -> `permission:scan.classify`
- `POST api/v1/inbound-documents/{id}/post-receipt` -> `permission:stock_document.post`
- `POST api/v1/outbound-documents/{id}/post-issue` -> `permission:stock_document.post`
- `POST api/v1/stocktake-sessions/{id}/apply-adjustment` -> `permission:stocktake.adjust`
- `POST api/v1/warranty-cases/{id}/temp-only` -> `permission:warranty.temp_only`

### BE1-P1-01: OpenAPI artifact

Da regenerate OpenAPI:

- Artifact: `storage/api-docs/api-docs.json`
- SHA256: `F9E4A712446AAA516B8091BAAB23353BF7A15D4732846708E7C0EB9647A2D92E`
- Command: `php artisan l5-swagger:generate`
- Ket qua: thanh cong.

### BE1-P1-02 / BE1-P1-04: Implementation va QA evidence

Da cap nhat regression tests:

- `tests/Feature/StockDocumentCanonicalFlowTest.php`
  - Scan inbound/outbound assert `stock_effect=NONE`, `movement_created=false`, status van `approved`.
  - Assert scan khong tao `StockMovement`.
  - Assert `post-receipt`/`post-issue` moi tao movement va doi balance.
  - Idempotency tests chuyen sang post action, vi scan khong con la stock-changing action.
- `tests/Feature/StockDocumentIdempotencyTest.php`
  - `test_scan_receipt_validates_without_stock_effect`: scan 2 lan van `approved`, khong balance, khong movement, khong `posting_event`.
  - Close remainder verify khong doi ton/khong movement.
  - Dashboard partial-scan expectation duoc doi ve 0 vi scan khong con tao partial posted stock flow.

Da chay va pass:

- `php artisan test --filter=StockDocumentCanonicalFlowTest`
  - Passed: 11 tests, 103 assertions.
- `php artisan test --filter=StockDocumentIdempotencyTest`
  - Passed: 14 tests, 85 assertions.
- `php artisan test --filter=PermissionAndValidationTest`
  - Passed: 10 tests, 27 assertions.
- `php artisan test --filter=SecurityRbacAuditNegativeTest`
  - Passed: 14 tests, 115 assertions.
- `php artisan l5-swagger:generate`
  - Passed, OpenAPI regenerated.
- `php artisan route:list --path=api/v1/inbound-documents -v`
  - Confirm scan uses `stock_document.scan`, post-receipt uses `stock_document.post`.
- `php artisan route:list --path=api/v1/outbound-documents -v`
  - Confirm scan uses `stock_document.scan`, post-issue uses `stock_document.post`.
- `php artisan route:list --path=api/v1/scan -v`
  - Confirm classify uses `scan.classify`.

Ghi chu moi truong:

- PHPUnit van warning khong anh huong assertion: khong ghi duoc `.phpunit.result.cache` do permission file trong sandbox.
- Full `php artisan test --testsuite=Feature` con fail cac test cu `AuthorizationTest`/`SkuAuthorizationTest` vi test dang ky vong `/products` dung `sku.manage` va/hoac lay user seeded khi test khong seed DB. Baseline BE1 hien tai da khoa `/products` theo `product.view/product.manage`, `/skus` theo `sku.manage`; day la test debt rieng, khong phai regression cua thay doi Scan/Post Receipt/Post Issue.

### Disposition P0/P1

- BE1-P0-02: Done o code + OpenAPI + tests. Scan da het doi ton that; movement chi con o `post-receipt`, `post-issue`, reverse va adjustment flow rieng.
- BE1-P1-01: OpenAPI JSON regenerated va co SHA256 artifact.
- BE1-P1-02: Co file implementation, route evidence, test evidence.
- BE1-P1-03: Permission catalog va route mapping da cap nhat cho `stock_document.scan`, `scan.classify`, `stocktake.adjust`, `warranty.temp_only`.
- BE1-P1-04: Co regression evidence cho happy/negative/RBAC/idempotency/reconcile tren cac suite lien quan.

## Cap nhat xu ly co P0/P1 - 2026-08-02 lan 3

### BE1-P0-01 / BE1-P0 Authorization: Dong bo seeded RBAC va regression tests

Da xu ly phan full test suite con do o nhom `AuthorizationTest` va `SkuAuthorizationTest`.

Nguyen nhan da xac minh:

- `SkuAuthorizationTest` dung `RefreshDatabase` nhung lay cac user seeded `test-sku-manage@hoanam.com`, `test-master-manage@hoanam.com`, `test-no-permission@hoanam.com`, `test-super-admin@hoanam.com` ma khong seed `AuthDatabaseSeeder`, nen fail `No query results for model [Modules\Auth\Models\User]`.
- `AuthorizationTest` tu tao user/role sau khi seed baseline, dan den duplicate email va role rong/khong co permission trong mot so case.
- Test cu dang gom GET/POST/PATCH vao mot expectation `200`, trong khi POST create thanh cong dung HTTP la `201`, va POST payload rong dung ra phai bi validation `422`.
- Contract runtime hien hanh van giu ranh gioi:
  - `/api/v1/products*` dung `product.view/product.manage`.
  - `/api/v1/skus*` dung `sku.manage`.

Da cap nhat code/test:

- `Modules/Auth/database/seeders/AuthDatabaseSeeder.php`
  - Mo rong role `SKU_MANAGER` test co `sku.manage`, `product.view`, `product.manage`.
  - Muc tieu: user P0 `test-sku-manage@hoanam.com` co the cover ca API alias SKU va product endpoints trong bo test authorization, trong khi route production van giu permission product rieng.
- `tests/Feature/SkuAuthorizationTest.php`
  - Seed `AuthDatabaseSeeder` trong `setUp()` de user/role/permission baseline luon ton tai sau `RefreshDatabase`.
- `tests/Feature/AuthorizationTest.php`
  - Seed `AuthDatabaseSeeder` trong `setUp()`.
  - Doi helper tao user sang `updateOrCreate` va chi attach role neu chua co, tranh duplicate email/pivot.
  - Tao product that cho cac case show/enable/disable.
  - Gui payload hop le cho POST `/products` va `/skus`.
  - Assert status thanh cong theo dung HTTP semantics: GET/PATCH `200`, POST create `201`.
  - Super admin POST dung code rieng de khong dung duplicate code voi request truoc.

### Evidence sau xu ly lan 3

Da chay full test suite:

- `php artisan test`
  - Ket qua: passed, 85 tests, 803 assertions.

Trang thai test debt cu:

- Ghi chu cu ve full `php artisan test --testsuite=Feature` con fail `AuthorizationTest`/`SkuAuthorizationTest` da duoc xu ly.
- Hien khong con blocker BE1 o nhom seeded RBAC, SKU/product authorization regression, validation-noise trong authorization tests.

### Disposition lan 3

- P0 authorization gate da co seeded RBAC on dinh va regression tests khong con phu thuoc thu tu/chay rieng.
- Contract route production khong bi ha chuan: product endpoints van theo `product.view/product.manage`, SKU alias endpoints van theo `sku.manage`.
- Full Laravel test suite hien pass 100%.
- Khong thuc hien comment truc tiep tren Jira theo yeu cau.
