# Delta hợp đồng route → permission (WWHN-300 `security.route_default_deny`) — 10/09/2026

Tài liệu review cho lần pin lại `database/golive/route_permission_contract.php`. Diff của file đó (sinh bằng
`php artisan wms:golive-check --write-route-contract`) phải **bằng đúng** các dòng liệt kê ở bảng A và bảng B —
không hơn, không kém.

| | Giá trị |
|---|---|
| Hợp đồng đã pin | `c33afcc` (25/08/2026) — 288 route |
| Runtime đo tại | `e5dbe0d` (10/09/2026) — 370 route `api/v1` |
| Route chưa có trong hợp đồng | 82 (bảng A) |
| Route đổi permission | 14 (bảng B) |
| Route có trong hợp đồng nhưng mất khỏi runtime | 0 |
| `unauthenticated_non_public` / `authenticated_without_permission` | `[]` / `[]` — mọi route ẩn danh nằm trong `BackendGoLiveCheck::PUBLIC_ROUTES`, mọi route đã xác thực mà không có `permission:` nằm trong `SELF_SERVICE_ROUTES`, cả hai danh sách đều có lý do từng route |
| Kết luận AppSec (tĩnh) | 35 OK · 46 OK-with-note · 15 CONCERN · **0 FAIL** |
| Pin lại hợp đồng | Không đổi permission nào của code — chỉ ghi nhận hiện trạng. Sign-off Tech Lead: **PENDING** |

## 1. Cách đo (tái lập được)

- Chụp `php artisan route:list --json --path=api/v1` ở 58 commit theo thứ tự topo từ `81782ec` tới
  `e5dbe0d`: mọi commit (kể cả merge) chạm `Modules/*/routes/*.php`, `routes/*.php`, `app/Providers`,
  `Modules/*/app/Providers`, `bootstrap/*.php`, cộng `c33afcc` và `e5dbe0d`. Mỗi snapshot chạy trên bản sao
  cục bộ trong container (`git archive` + vendor volume), SQLite `:memory:`.
- 4 commit không boot được (`f93446b`, `4a18c75`, `652fc7d`, `76e51fa` — lỗi PHP fatal giữa chừng GĐ1); không
  route nào của delta này có commit đưa vào nằm trong khoảng đó.
- Kiểm chéo: snapshot `c33afcc` trùng khít hợp đồng đã pin (đúng: 288/288 route và guard);
  snapshot `e5dbe0d` trùng khít file do `--write-route-contract` sinh (đúng: 370/370).
- "Commit đưa vào" là commit đầu tiên mà route (bảng A) hoặc guard mới (bảng B) có mặt liên tục tới HEAD.
- Rà soát AppSec là **tĩnh** (đọc mã tại `e5dbe0d`), không chạy runtime; mọi tham chiếu `file:dòng` ở dưới là
  tại commit đó. Viết tắt file dùng trong bảng:

**File aliases**

| Alias | Path |
|---|---|
| SEED | Modules/Auth/database/seeders/AuthDatabaseSeeder.php |
| BA | docs/evidence/RBAC-BA-20260906/BAN_GIAO_BA_TAI_KHOAN_VA_PHAN_QUYEN.md |
| PIN | docs/evidence/WWHN-267/ROLE_BASELINE_PIN.json |
| WR | Modules/Warehouse/routes/api.php |
| MR | Modules/Warehouse/routes/mini_app.php |
| DAC | Modules/Warehouse/app/Http/Controllers/DocumentAttachmentController.php |
| CFC | Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php |
| MCS | Modules/Warehouse/app/Http/Controllers/MiniAppComponentIssueStagingController.php |
| NFC | Modules/Warehouse/app/Http/Controllers/MiniAppNfcController.php |
| ORC | Modules/Warehouse/app/Http/Controllers/OperationalReportController.php |
| BAM | Modules/Warehouse/app/Http/Controllers/BaM1ReportController.php |
| ALL | Modules/Warehouse/app/Http/Controllers/AlignedLabelLogReportController.php |
| UC | Modules/Auth/app/Http/Controllers/UserController.php |
| PRC | Modules/Auth/app/Http/Controllers/PasswordResetController.php |
| MFA | Modules/Auth/app/Http/Controllers/MfaController.php |
| MFS | Modules/Auth/app/Support/MfaService.php |
| ASP | app/Providers/AppServiceProvider.php |

**Role abbreviations** (production roles only): AD=ADMIN, WM=WAREHOUSE_MANAGER, WS=WAREHOUSE_STAFF, WaS=WARRANTY_STAFF, LD=LEADERSHIP, VW=VIEWER.

**Role and permission assumptions**

- **SUPER_ADMIN bypass.** SUPER_ADMIN passes every permission check (Modules/Auth/app/Models/User.php:199-202), so it is left out of the role columns.
- **Guard semantics.** `permission:A|B` passes if the user holds any one of the keys (Modules/Auth/app/Http/Middleware/CheckPermission.php:41-55).
- **Role→key sets** come from SEED:162-469.
- **Compatibility seeder.** It adds keys only to WAREHOUSE_KEEPER and SKU_MANAGER, and neither is a production role (WmsPermissionCompatibilitySeeder.php:58-65).
- **Deprecated and test roles** are not seeded in production (SEED:492, 504, 530-534). They are named only where they matter.
- **Per-user grants.** Individual `user_permission` grants can extend any row below (User.php:178-196). Those grants cannot be listed statically.

## 2. Bảng A — 82 route chưa có trong hợp đồng

| # | Route | Guard | Handler | Commit đưa vào | Vai production truy cập được | Kết luận | Đánh giá |
|---|---|---|---|---|---|---|---|
| 1 | `DELETE api/v1/component-issue-documents/{id}/lines/{lineId}` | `permission:warranty.component_issue` | CurrentBaselineComponentFlowController@destroyLine | `2d9864f` 2026-09-08 | AD, WaS | **OK-with-note** | Tác động: W, no stock change (removes an unscanned line) · Kiểm soát trong handler: `lockMutableDocument`: DRAFT/SCANNING only, If-Match version, warranty case in CHECKING/REPAIRING (838-851, 39). `lockLine` binds the line to the document (853-865). Refuses lines that have scans (657-663). · Ghi chú: No `AuditLogger` record, only a version bump (666). |
| 2 | `DELETE api/v1/document-attachments/{attachmentId}` | `permission:inbound.create\|inbound.scan\|outbound.scan\|outbound.post\|inventory.reversal.post` | DocumentAttachmentController@destroy | `368943c` 2026-08-27 | AD, WS (handler narrows by type) | **CONCERN** | Tác động: W (soft delete + audit, 386-398) · Kiểm soát trong handler: Permission checked per `document_type` (61-74, 607-624): INBOUND needs inbound.create or inbound.scan; OUTBOUND needs outbound.scan or outbound.post; REVERSAL needs inventory.reversal.post. Warehouse scope checked (632-655). · Ghi chú: The parent document's status is not checked, so evidence on POSTED/CANCELLED documents can be deleted. The scope check lets the request through when the warehouse cannot be resolved (634-641, 652). The test covers only an INBOUND attachment against an outbound-only actor (tests/Feature/WwhnSec09HardeningTest.php:159-203). → E4 |
| 3 | `DELETE api/v1/mini-app/document-attachments/{attachmentId}` | `permission:inbound.create\|inbound.scan\|outbound.scan\|outbound.post` | DocumentAttachmentController@destroy | `368943c` 2026-08-27 | AD, WS | **CONCERN** | Tác động: W · Kiểm soát trong handler: Same as above. REVERSAL is unreachable here because the route has no reversal key. · Ghi chú: → E4 |
| 4 | `DELETE api/v1/mini-app/warranty-component-issues/{id}/lines/{lineId}` | `permission:warranty.component_issue` | CurrentBaselineComponentFlowController@destroyLine | `2d9864f` 2026-09-08 | AD, WaS | **OK-with-note** | Tác động: W, no stock change (removes an unscanned line) · Kiểm soát trong handler: `lockMutableDocument`: DRAFT/SCANNING only, If-Match version, warranty case in CHECKING/REPAIRING (838-851, 39). `lockLine` binds the line to the document (853-865). Refuses lines that have scans (657-663). · Ghi chú: No `AuditLogger` record, only a version bump (666). |
| 5 | `DELETE api/v1/users/{id}/permissions/{permissionId}` | `permission:iam.user.manage` | UserController@revokePermission | `368943c` 2026-08-27 | AD | **OK-with-note** | Tác động: W · Kiểm soát trong handler: Grant must exist (474-476). Delegation check on the revoked key (478-480). Audit (486-494). · Ghi chú: Same self-check weakness, but revoking your own grant gains nothing. |
| 6 | `DELETE api/v1/warranty-component-issues/{id}/lines/{lineId}` | `permission:warranty.component_issue` | CurrentBaselineComponentFlowController@destroyLine | `2d9864f` 2026-09-08 | AD, WaS | **OK-with-note** | Tác động: W, no stock change (removes an unscanned line) · Kiểm soát trong handler: `lockMutableDocument`: DRAFT/SCANNING only, If-Match version, warranty case in CHECKING/REPAIRING (838-851, 39). `lockLine` binds the line to the document (853-865). Refuses lines that have scans (657-663). · Ghi chú: No `AuditLogger` record, only a version bump (666). |
| 7 | `GET\|HEAD api/v1/audit-logs/me` | `SELF_SERVICE` | AuditLogController@myHistory | `368943c` 2026-08-27 | any | **OK** | Tác động: R · Kiểm soát trong handler: Hard-coded `object_type='user' AND object_id=<caller>` (105), applied before client filters (app/Repositories/BaseRepository.php:129-136). Client filters only narrow results (AND), and the `q` search is parenthesised (Modules/AuditLog/app/Repositories/AuditLogRepository.php:29-61; BaseRepository.php:432-459). Scoped queries skip the repository cache (BaseRepository.php:82-83). |
| 8 | `GET\|HEAD api/v1/auth/mfa/status` | `SELF_SERVICE` | MfaController@status | `2d9864f` 2026-09-08 | any | **OK** | Tác động: R · Kiểm soát trong handler: Uses `$request->user()` only |
| 9 | `GET\|HEAD api/v1/document-attachments/pending-evidence` | `permission:inventory.view` | DocumentAttachmentController@pendingEvidence | `368943c` 2026-08-27 | all 6 | **CONCERN** | Tác động: R (document id/no/warehouse_id/status of DRAFT inbound and reversal documents) · Kiểm soát trong handler: none. No warehouse scope and no split by document type. · Ghi chú: WaS has no `inbound.view` (the BA doc measures 403 for WaS on inbound documents, BA:197), yet can list inbound draft documents across all warehouses here. → E5 |
| 10 | `GET\|HEAD api/v1/document-attachments/{attachmentId}/download` | `permission:inbound.view\|outbound.view\|inventory.view` | DocumentAttachmentController@download | `368943c` 2026-08-27 | all 6 (WaS can reach REVERSAL only) | **OK-with-note** | Tác động: R (file) + audit (351-358) · Kiểm soát trong handler: Per-type check (61-74): INBOUND needs inbound.view/create/scan; OUTBOUND needs outbound.view/scan/post; REVERSAL needs inventory.view or reversal.post. Warehouse scope checked (632-655). · Ghi chú: A user with only `inventory.view` gets REVERSAL files only, which matches the reversal read gate. |
| 11 | `GET\|HEAD api/v1/inbound-documents/{id}/attachments` | `permission:inbound.view` | DocumentAttachmentController@indexInbound | `368943c` 2026-08-27 | AD, WM, WS, LD, VW | **OK** | Tác động: R (metadata only; the storage path is not exposed, 658-675) · Kiểm soát trong handler: `EnforceInboundWarehouseScope` checks the route `{id}` (EnforceInboundWarehouseScope.php:52-61). Document must be a receipt (571-579). |
| 12 | `GET\|HEAD api/v1/mini-app/document-attachments/pending-evidence` | `permission:inventory.view` | DocumentAttachmentController@pendingEvidence | `368943c` 2026-08-27 | all 6 | **CONCERN** | Tác động: R · Kiểm soát trong handler: none · Ghi chú: → E5 |
| 13 | `GET\|HEAD api/v1/mini-app/document-attachments/{attachmentId}/download` | `permission:inbound.view\|outbound.view\|inventory.view` | DocumentAttachmentController@download | `368943c` 2026-08-27 | all 6 | **OK-with-note** | Tác động: R · Kiểm soát trong handler: Per-type check (61-74): INBOUND needs inbound.view/create/scan; OUTBOUND needs outbound.view/scan/post; REVERSAL needs inventory.view or reversal.post. Warehouse scope checked (632-655). · Ghi chú: A user with only `inventory.view` gets REVERSAL files only, which matches the reversal read gate. |
| 14 | `GET\|HEAD api/v1/mini-app/inbound-documents/{id}/attachments` | `permission:inbound.view` | DocumentAttachmentController@indexInbound | `368943c` 2026-08-27 | AD, WM, WS, LD, VW | **OK** | Tác động: R · Kiểm soát trong handler: `EnforceInboundWarehouseScope` checks the route `{id}` (EnforceInboundWarehouseScope.php:52-61). Document must be a receipt (571-579). |
| 15 | `GET\|HEAD api/v1/mini-app/items` | `permission:physical_code.assign_rfid\|item_instance.view\|inventory.view` | MiniAppNfcController@listItems | `0fc4f64` 2026-09-04 | all 6 | **OK-with-note** | Tác động: R (items, serials, NFC payload) · Kiểm soát trong handler: none (no warehouse scope) · Ghi chú: LD gets the serial worklist through `inventory.view` even though it lacks `item_instance.view`. |
| 16 | `GET\|HEAD api/v1/mini-app/nfc-tags` | `permission:physical_code.assign_rfid\|inventory.trace` | MiniAppNfcController@listTags | `0fc4f64` 2026-09-04 | all 6 | **OK-with-note** | Tác động: R (chip hardware UIDs, serials) · Kiểm soát trong handler: none · Ghi chú: No scope. |
| 17 | `GET\|HEAD api/v1/mini-app/outbound-documents/{id}/attachments` | `permission:outbound.view` | DocumentAttachmentController@indexOutbound | `368943c` 2026-08-27 | AD, WM, WS, LD, VW | **OK** | Tác động: R · Kiểm soát trong handler: `EnforceOutboundWarehouseScope` (EnforceOutboundWarehouseScope.php:38-47). Document must be an issue. |
| 18 | `GET\|HEAD api/v1/mini-app/products` | `permission:sku.view` | MiniAppProductController@index | `368943c` 2026-08-27 | all 6 | **OK-with-note** | Tác động: R (SKU + `available_qty`) · Kiểm soát trong handler: Warehouse fixed server-side; a client `warehouse_id` cannot override it (41, 54-56, 171-182) · Ghi chú: Stock quantity is served under `sku.view`; today every sku.view role also holds `inventory.view`. The actor's scope is ignored (always KHO-TONG-HN). |
| 19 | `GET\|HEAD api/v1/mini-app/products/{id}` | `permission:sku.view` | MiniAppProductController@show | `368943c` 2026-08-27 | all 6 | **OK-with-note** | Tác động: R · Kiểm soát trong handler: Warehouse fixed server-side; a client `warehouse_id` cannot override it (41, 54-56, 171-182) · Ghi chú: Stock quantity is served under `sku.view`; today every sku.view role also holds `inventory.view`. The actor's scope is ignored (always KHO-TONG-HN). |
| 20 | `GET\|HEAD api/v1/notifications` | `SELF_SERVICE` | NotificationController@index | `3ec27f3` 2026-08-26 | any | **OK** | Tác động: R · Kiểm soát trong handler: `where user_id = caller` (59). Cache key includes the actor (77). |
| 21 | `GET\|HEAD api/v1/notifications/unread-count` | `SELF_SERVICE` | NotificationController@unreadCount | `3ec27f3` 2026-08-26 | any | **OK** | Tác động: R · Kiểm soát trong handler: user filter (112) |
| 22 | `GET\|HEAD api/v1/outbound-documents/{id}/attachments` | `permission:outbound.view` | DocumentAttachmentController@indexOutbound | `368943c` 2026-08-27 | AD, WM, WS, LD, VW | **OK** | Tác động: R · Kiểm soát trong handler: `EnforceOutboundWarehouseScope` (EnforceOutboundWarehouseScope.php:38-47). Document must be an issue. |
| 23 | `GET\|HEAD api/v1/public/categories` | `PUBLIC_ANONYMOUS` | PublicCatalogController@categories | `b6d9a34` 2026-08-30 | ẩn danh | **OK** | Tác động: R · Kiểm soát trong handler: domain parameter validated |
| 24 | `GET\|HEAD api/v1/public/config` | `PUBLIC_ANONYMOUS` | PublicAppController@config | `b6d9a34` 2026-08-30 | ẩn danh | **OK** | Tác động: R (static config: hotline, privacy version, flags) · Kiểm soát trong handler: none needed |
| 25 | `GET\|HEAD api/v1/public/facets` | `PUBLIC_ANONYMOUS` | PublicCatalogController@facets | `b6d9a34` 2026-08-30 | ẩn danh | **OK** | Tác động: R (counts over published products) · Kiểm soát trong handler: same published filter |
| 26 | `GET\|HEAD api/v1/public/health/version` | `PUBLIC_ANONYMOUS` | PublicAppController@healthVersion | `b6d9a34` 2026-08-30 | ẩn danh | **OK** | Tác động: R · Kiểm soát trong handler: No commit/build/framework info (119-122) |
| 27 | `GET\|HEAD api/v1/public/home` | `PUBLIC_ANONYMOUS` | PublicAppController@home | `b6d9a34` 2026-08-30 | ẩn danh | **OK** | Tác động: R · Kiểm soát trong handler: Only products that are published, ACTIVE and in stock (Modules/Warehouse/app/Services/PublicCatalogReadModel.php:486-508). Output field whitelist is stated in the code (31-35) but not verified field by field. |
| 28 | `GET\|HEAD api/v1/public/products` | `PUBLIC_ANONYMOUS` | PublicCatalogController@products | `b6d9a34` 2026-08-30 | ẩn danh | **OK-with-note** | Tác động: R · Kiểm soát trong handler: Published filter. `ids[]` mode: at most 50 UUIDs, no mixing with other query parameters (118-158). · Ghi chú: `ids[]` mode also returns out-of-stock published items with `availability` IN_STOCK/PREORDER (ReadModel:72-88, 924). This is a binary stock signal, and it is intentional. No quantities are exposed. |
| 29 | `GET\|HEAD api/v1/public/products/{slug}` | `PUBLIC_ANONYMOUS` | PublicCatalogController@product | `b6d9a34` 2026-08-30 | ẩn danh | **OK** | Tác động: R · Kiểm soát trong handler: 404 hides unpublished and out-of-stock products |
| 30 | `GET\|HEAD api/v1/public/products/{slug}/related` | `PUBLIC_ANONYMOUS` | PublicCatalogController@related | `b6d9a34` 2026-08-30 | ẩn danh | **OK** | Tác động: R · Kiểm soát trong handler: 404 hides unpublished and out-of-stock products |
| 31 | `GET\|HEAD api/v1/pv/product-images/{key}` | `PUBLIC_ANONYMOUS` | PvProductImageController@show | `d019d63` 2026-09-07 | ẩn danh | **OK-with-note** | Tác động: R (file) · Kiểm soát trong handler: Key restricted to `UUID.(jpg\\|jpeg\\|png\\|webp)` in the route (WR:82-83) and re-checked in the handler (48, 164-166). Fixed prefix. `nosniff` header (187-190). · Ghi chú: Any object under the prefix is served, including orphaned or unpublished uploads, if the key is known. Keys are random UUIDs. |
| 32 | `GET\|HEAD api/v1/reports/dashboard-overview` | `permission:report.view` | OperationalReportController@dashboardOverview | `b6d9a34` 2026-08-30 | all 6 | **CONCERN (needs BA decision)** | Tác động: R (aggregate) · Kiểm soát trong handler: Warehouse scope resolved before the cache lookup for the stock blocks (698, 735-760, 926-929). Warranty blocks are GLOBAL (1062-1113). `customer_name` is always masked (1078-1102). · Ghi chú: Cross-domain aggregate behind `report.view` alone. See Section D and E7. |
| 33 | `GET\|HEAD api/v1/reports/export/{export_job_id}` | `permission:report.export` | OperationalReportController@exportStatus | `368943c` 2026-08-27 | AD, WM | **OK-with-note** | Tác động: R (may run a pending job synchronously, 321) · Kiểm soát trong handler: `ownedExportJob`: id must be a UUID and `requested_by` must equal the caller, otherwise 404 (361-371) · Ghi chú: Safe against BOLA. The job runs under the requester's identity (Modules/Warehouse/app/Support/ReportExportJobRunner.php:81-107). Per-screen permissions are not checked anywhere on the export path (Section D). |
| 34 | `GET\|HEAD api/v1/reports/export/{export_job_id}/download` | `permission:report.export` | OperationalReportController@exportDownload | `368943c` 2026-08-27 | AD, WM | **OK-with-note** | Tác động: R (file) · Kiểm soát trong handler: Owned by caller, READY, not expired (339-350) · Ghi chú: Safe against BOLA. The job runs under the requester's identity (Modules/Warehouse/app/Support/ReportExportJobRunner.php:81-107). Per-screen permissions are not checked anywhere on the export path (Section D). |
| 35 | `GET\|HEAD api/v1/reports/inventory-xnt-daily` | `permission:report.view` | OperationalReportController@inventoryXntDaily | `368943c` 2026-08-27 | all 6 | **OK-with-note** | Tác động: R · Kiểm soát trong handler: `reportWarehouseScope` (1845, 1865) · Ghi chú: RPT-01 flow data is readable by WaS through `report.view`. This is intentional: the endpoint belongs to the Dashboard group (WR:258-262). |
| 36 | `GET\|HEAD api/v1/reports/warranty-component-usage` | `permission:report.warranty.view` | BaM1ReportController@componentUsage | `368943c` 2026-08-27 | AD, WM, WaS, LD, VW | **OK-with-note** | Tác động: R (top components used in warranty repairs; no PII) · Kiểm soát trong handler: No warehouse filter found. `reportScopeIds` is used only to build the cache key (Modules/Warehouse/app/Http/Controllers/Concerns/CachesReportPayload.php:51-101). · Ghi chú: The guard changed from `report.view` to this key in 38b51a6. WS loses access (Section D). |
| 37 | `GET\|HEAD api/v1/reversal-documents/{id}/attachments` | `permission:inventory.view` | DocumentAttachmentController@indexReversal | `368943c` 2026-08-27 | all 6 | **OK-with-note** | Tác động: R · Kiểm soát trong handler: `EnforceReversalWarehouseScope` (EnforceReversalWarehouseScope.php:60-66) · Ghi chú: Reversal evidence metadata (file names, notes, uploader ids) is visible to every `inventory.view` role. |
| 38 | `GET\|HEAD api/v1/skus/lookup` | `permission:sku.view` | ProductController@lookup | `d019d63` 2026-09-07 | all 6 | **OK** | Tác động: R (SKU master incl. min/max stock) · Kiểm soát trong handler: global reference data |
| 39 | `GET\|HEAD api/v1/users/{id}/permissions` | `permission:iam.user.view` | UserController@listPermissionOverrides | `368943c` 2026-08-27 | AD | **OK** | Tác động: R · Kiểm soát trong handler: none needed (IAM admin read) · Ghi chú: WM lost all IAM keys under DELTA-01/06 (PIN:154), which matches the BA doc. |
| 40 | `PATCH api/v1/component-issue-documents/{id}/lines/{lineId}` | `permission:warranty.component_issue` | CurrentBaselineComponentFlowController@updateLine | `2d9864f` 2026-09-08 | AD, WaS | **OK-with-note** | Tác động: W, no stock change (required_qty, note) · Kiểm soát trong handler: Same locks. `required_qty` cannot go below `scanned_qty` (598-604). · Ghi chú: No audit record. |
| 41 | `PATCH api/v1/inbound-documents/{id}/lines/{lineId}/sku` | `permission:inbound.scan` | InboundSkuInfoController@complete | `38b51a6` 2026-09-07 | AD, WS | **CONCERN** | Tác động: **W: SKU master data** (sku_name, unit, brand/category/model/power_source) · Kiểm soát trong handler: Receipt must be DRAFT/SCANNING, locked (96-106). Line must belong to the document (108-114). SKU is editable only if its info is incomplete **or it has never entered stock** (127-134, 205-212). Masters must be ACTIVE (236-239). Audit (152-168). · Ghi chú: The "never entered stock" branch admits any catalog SKU, because draft lines accept any existing SKU (Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php:125, 153). Master links can also be cleared (230-233). No BA evidence found for giving SKU edits to `inbound.scan`; the rationale appears only in the 38b51a6 message and the docblock (19-27). → E3 |
| 42 | `PATCH api/v1/mini-app/items/{itemId}/nfc` | `permission:physical_code.assign_rfid` | MiniAppNfcController@confirm | `0fc4f64` 2026-09-04 | AD | **CONCERN** | Tác động: W (`physical_codes` row, no stock change) · Kiểm soát trong handler: Read-back payload must match the item (331-338). Reservation (341, 695-711). Idempotency-Key (351-357). hardware_uid locked and unique (361-380). Replacing a chip requires a reason (347-349). Audit (419-434). · Ghi chú: The client-supplied `code_value` (310, 344) is only checked for duplicates in `physical_codes` (395-399). → E9 |
| 43 | `PATCH api/v1/mini-app/warranty-component-issues/{id}/lines/{lineId}` | `permission:warranty.component_issue` | CurrentBaselineComponentFlowController@updateLine | `2d9864f` 2026-09-08 | AD, WaS | **OK-with-note** | Tác động: W, no stock change (required_qty, note) · Kiểm soát trong handler: Same locks. `required_qty` cannot go below `scanned_qty` (598-604). · Ghi chú: No audit record. |
| 44 | `PATCH api/v1/notifications/{id}/read` | `SELF_SERVICE` | NotificationController@markRead | `3ec27f3` 2026-08-26 | any | **OK** | Tác động: W (own row) · Kiểm soát trong handler: `where user_id AND id` (139-142) |
| 45 | `PATCH api/v1/warranty-component-issues/{id}/lines/{lineId}` | `permission:warranty.component_issue` | CurrentBaselineComponentFlowController@updateLine | `2d9864f` 2026-09-08 | AD, WaS | **OK-with-note** | Tác động: W, no stock change (required_qty, note) · Kiểm soát trong handler: Same locks. `required_qty` cannot go below `scanned_qty` (598-604). · Ghi chú: No audit record. |
| 46 | `POST api/v1/auth/forgot-password` | `PUBLIC_ANONYMOUS` | PasswordResetController@forgotPassword | `368943c` 2026-08-27 | ẩn danh | **CONCERN** | Tác động: W (OTP row + queued email, Modules/Auth/app/Mail/PasswordResetOtpMail.php:15) · Kiểm soát trong handler: Always returns the same message (55, 91). Only ACTIVE users get an OTP (70). · Ghi chú: No per-IP or per-account total cap. The bcrypt hash, insert and dispatch run only for existing users (70-89), which creates a timing oracle. → E12 |
| 47 | `POST api/v1/auth/mfa/confirm` | `SELF_SERVICE` | MfaController@confirm | `2d9864f` 2026-09-08 | any | **OK-with-note** | Tác động: W · Kiểm soát trong handler: TOTP checked against the caller's own pending record (MFS:121-145) · Ghi chú: Same step-up note. |
| 48 | `POST api/v1/auth/mfa/disable` | `SELF_SERVICE` | MfaController@disable | `2d9864f` 2026-09-08 | any | **OK** | Tác động: W · Kiểm soát trong handler: Requires password + TOTP or recovery code (174-180). Refused for MFA-required roles when enforcement is on (167-172). Failed attempts lock the account (MFS:299-310). |
| 49 | `POST api/v1/auth/mfa/enroll` | `SELF_SERVICE` | MfaController@enroll | `2d9864f` 2026-09-08 | any | **OK-with-note** | Tác động: W (own `user_mfa`) · Kiểm soát trong handler: Uses the token user only. A confirmed MFA record is never overwritten (MFS:84-88). · Ghi chú: No password step-up. A hijacked session can bind the attacker's authenticator to an account that has no MFA yet. |
| 50 | `POST api/v1/auth/mfa/verify` | `PUBLIC_ANONYMOUS` | MfaController@verify | `2d9864f` 2026-09-08 | ẩn danh | **OK** | Tác động: W (issues tokens) · Kiểm soát trong handler: The challenge is single-use and deleted even on failure (MFS:201-204). Account status must still be ACTIVE (230-239). Account lockout (243-251; MFS:299-310). TOTP replay guard (MFS:265-276). |
| 51 | `POST api/v1/auth/reset-password` | `PUBLIC_ANONYMOUS` | PasswordResetController@resetPassword | `368943c` 2026-08-27 | ẩn danh | **OK-with-note** | Tác động: W (password) · Kiểm soát trong handler: Requires a verified, unconsumed, hashed token (196-206). Strong-password rule (ResetPasswordRequest.php:27-35). Revokes refresh tokens and invalidates existing access tokens (224-225). · Ghi chú: `CredentialPolicy` (the list of leaked passwords) is not applied here. It is used only in BackendGoLiveCheck, BootstrapSuperAdmin, SeedUatData and SeedEnvironment. |
| 52 | `POST api/v1/auth/verify-otp` | `PUBLIC_ANONYMOUS` | PasswordResetController@verifyOtp | `368943c` 2026-08-27 | ẩn danh | **OK-with-note** | Tác động: W (issues reset_token) · Kiểm soát trong handler: Latest unconsumed OTP only, at most 5 attempts (125-142). OTP must be 6 characters (VerifyOtpRequest.php:18). · Ghi chú: The attempts check and increment are not atomic (134-141). The limiter bounds the impact. |
| 53 | `POST api/v1/broadcasting/auth` | `SELF_SERVICE` | BroadcastAuthController@authenticate | `3ec27f3` 2026-08-26 | any | **OK** | Tác động: R (channel signature) · Kiểm soát trong handler: Only channel `user.{userId}`, with a strict equality check (routes/channels.php:8-10). Undefined channels are denied. · Ghi chú: The OpenAPI example channel name `private-notifications.<id>` does not match the real channel (documentation only). |
| 54 | `POST api/v1/component-issue-documents/record` | `permission:warranty.component_issue` | MiniAppComponentIssueStagingController@record | `2d9864f` 2026-09-08 | AD, WaS | **OK-with-note** | Tác động: W. **Stock decreases** when `post_immediately`, which defaults to true (221, 296-309). · Kiểm soát trong handler: Payload field allowlist (766-792). Warehouse must be ACTIVE (759-764). Delegates to the canonical store/scan/post (238-305), which checks case status and scan rules. Idempotency-Key required (223-231). Audit (315-330). Scope middleware checks the body `warehouse_id`. · Ghi chú: Scanned code resolution is not tied to `warehouse_id` (540-575). Low risk while there is one warehouse. |
| 55 | `POST api/v1/component-issue-documents/resolve-code` | `permission:warranty.component_issue` | MiniAppComponentIssueStagingController@resolveCode | `2d9864f` 2026-09-08 | AD, WaS | **OK-with-note** | Tác động: R · Kiểm soát trong handler: Warehouse must exist and be ACTIVE (133, 139). The cache key has no actor, but the payload carries no PII (148-154). · Ghi chú: Resolves any item or box regardless of warehouse. Returns the status of any `warranty_case_id`. |
| 56 | `POST api/v1/component-issue-documents/{id}/lines` | `permission:warranty.component_issue` | CurrentBaselineComponentFlowController@storeLine | `2d9864f` 2026-09-08 | AD, WaS | **OK-with-note** | Tác động: W, no stock change · Kiểm soát trong handler: `lockMutableDocument`. Only ACTIVE COMPONENT SKUs accepted (519, 867-875). · Ghi chú: No audit record. |
| 57 | `POST api/v1/component-issue-documents/{id}/unscan` | `permission:warranty.component_issue` | CurrentBaselineComponentFlowController@unscan | `2d9864f` 2026-09-08 | AD, WaS | **OK** | Tác động: W, no stock change (scan row set to REMOVED) · Kiểm soát trong handler: `lockMutableDocument`. Scan row must belong to the document (921-926). Audit `UNSCAN_COMPONENT_ISSUE` (450-459). |
| 58 | `POST api/v1/inbound-documents/{id}/attachments` | `permission:inbound.create\|inbound.scan` | DocumentAttachmentController@storeInbound | `368943c` 2026-08-27 | AD, WS | **OK-with-note** | Tác động: W, no stock change (file + row + audit) · Kiểm soát trong handler: Receipt must exist and not be POSTED/CANCELLED (128-130). File type checked by magic bytes (app/Support/DocumentAttachmentScanner.php:36-59). 15 MB and 20-file caps (441-452). Scope middleware. · Ghi chú: The malware scan returns UNSCANNED when `documents.attachment_scan_command` is not set (DocumentAttachmentScanner.php:87-90); production config was not verified. The status comparison is case-sensitive, while pendingEvidence also matches lower-case statuses (277). |
| 59 | `POST api/v1/mini-app/component-issue-documents/record` | `permission:warranty.component_issue` | MiniAppComponentIssueStagingController@record | `2d9864f` 2026-09-08 | AD, WaS | **OK-with-note** | Tác động: W. **Stock decreases** when `post_immediately`, which defaults to true (221, 296-309). · Kiểm soát trong handler: Payload field allowlist (766-792). Warehouse must be ACTIVE (759-764). Delegates to the canonical store/scan/post (238-305), which checks case status and scan rules. Idempotency-Key required (223-231). Audit (315-330). Scope middleware checks the body `warehouse_id`. · Ghi chú: Scanned code resolution is not tied to `warehouse_id` (540-575). Low risk while there is one warehouse. |
| 60 | `POST api/v1/mini-app/component-issue-documents/resolve-code` | `permission:warranty.component_issue` | MiniAppComponentIssueStagingController@resolveCode | `2d9864f` 2026-09-08 | AD, WaS | **OK-with-note** | Tác động: R · Kiểm soát trong handler: Warehouse must exist and be ACTIVE (133, 139). The cache key has no actor, but the payload carries no PII (148-154). · Ghi chú: Resolves any item or box regardless of warehouse. Returns the status of any `warranty_case_id`. |
| 61 | `POST api/v1/mini-app/inbound-documents/{id}/attachments` | `permission:inbound.create\|inbound.scan` | DocumentAttachmentController@storeInbound | `368943c` 2026-08-27 | AD, WS | **OK-with-note** | Tác động: W · Kiểm soát trong handler: Receipt must exist and not be POSTED/CANCELLED (128-130). File type checked by magic bytes (app/Support/DocumentAttachmentScanner.php:36-59). 15 MB and 20-file caps (441-452). Scope middleware. · Ghi chú: The malware scan returns UNSCANNED when `documents.attachment_scan_command` is not set (DocumentAttachmentScanner.php:87-90); production config was not verified. The status comparison is case-sensitive, while pendingEvidence also matches lower-case statuses (277). |
| 62 | `POST api/v1/mini-app/items/{itemId}/nfc/prepare` | `permission:physical_code.assign_rfid` | MiniAppNfcController@prepare | `0fc4f64` 2026-09-04 | AD | **OK** | Tác động: W (cache reservation + audit) · Kiểm soát trong handler: Assignability checks (894-907). Reservation is an atomic compare-and-set (660-688). · Ghi chú: WS and WM lack `physical_code.*`, which matches BA (BA:121-122, 95-98). |
| 63 | `POST api/v1/mini-app/nfc-tags/{physicalCodeId}/deactivate` | `permission:physical_code.assign_rfid` | MiniAppNfcController@deactivate | `0fc4f64` 2026-09-04 | AD | **OK-with-note** | Tác động: W · Kiểm soát trong handler: Must be an ACTIVE NFC tag (607-615). Reason required (599-602). Audit (627-636). · Ghi chú: No scope. |
| 64 | `POST api/v1/mini-app/nfc/resolve-code` | `permission:physical_code.assign_rfid` | MiniAppNfcController@resolveCode | `0fc4f64` 2026-09-04 | AD | **OK** | Tác động: R · Kiểm soát trong handler: read-only |
| 65 | `POST api/v1/mini-app/nfc/resolve-tag` | `permission:physical_code.assign_rfid\|inventory.trace\|warranty.view` | MiniAppNfcController@resolveTag | `0fc4f64` 2026-09-04 | all 6 (raw PII: AD, WM, WS, LD, VW) | **OK-with-note** | Tác động: R (item, last POSTED issue with recipient, active warranty case) · Kiểm soát trong handler: Recipient name/address/phone are raw only if the caller has `outbound.view`, masked otherwise (1014-1028) · Ghi chú: This PII gate uses `outbound.view`, while RPT-07 uses `warranty.pii.view` (ORC:2304). No scope. |
| 66 | `POST api/v1/mini-app/outbound-documents/{id}/attachments` | `permission:outbound.scan\|outbound.post` | DocumentAttachmentController@storeOutbound | `368943c` 2026-08-27 | AD, WS | **OK-with-note** | Tác động: W · Kiểm soát trong handler: Issue must not be POSTED/CANCELLED (189-191). Scope middleware. · Ghi chú: Same scanner note. |
| 67 | `POST api/v1/mini-app/warranty-component-issues/record` | `permission:warranty.component_issue` | MiniAppComponentIssueStagingController@record | `2d9864f` 2026-09-08 | AD, WaS | **OK-with-note** | Tác động: W. **Stock decreases** when `post_immediately`, which defaults to true (221, 296-309). · Kiểm soát trong handler: Payload field allowlist (766-792). Warehouse must be ACTIVE (759-764). Delegates to the canonical store/scan/post (238-305), which checks case status and scan rules. Idempotency-Key required (223-231). Audit (315-330). Scope middleware checks the body `warehouse_id`. · Ghi chú: Scanned code resolution is not tied to `warehouse_id` (540-575). Low risk while there is one warehouse. |
| 68 | `POST api/v1/mini-app/warranty-component-issues/resolve-code` | `permission:warranty.component_issue` | MiniAppComponentIssueStagingController@resolveCode | `2d9864f` 2026-09-08 | AD, WaS | **OK-with-note** | Tác động: R · Kiểm soát trong handler: Warehouse must exist and be ACTIVE (133, 139). The cache key has no actor, but the payload carries no PII (148-154). · Ghi chú: Resolves any item or box regardless of warehouse. Returns the status of any `warranty_case_id`. |
| 69 | `POST api/v1/mini-app/warranty-component-issues/{id}/lines` | `permission:warranty.component_issue` | CurrentBaselineComponentFlowController@storeLine | `2d9864f` 2026-09-08 | AD, WaS | **OK-with-note** | Tác động: W, no stock change · Kiểm soát trong handler: `lockMutableDocument`. Only ACTIVE COMPONENT SKUs accepted (519, 867-875). · Ghi chú: No audit record. |
| 70 | `POST api/v1/mini-app/warranty-component-issues/{id}/unscan` | `permission:warranty.component_issue` | CurrentBaselineComponentFlowController@unscan | `2d9864f` 2026-09-08 | AD, WaS | **OK** | Tác động: W, no stock change (scan row set to REMOVED) · Kiểm soát trong handler: `lockMutableDocument`. Scan row must belong to the document (921-926). Audit `UNSCAN_COMPONENT_ISSUE` (450-459). |
| 71 | `POST api/v1/notifications/read-all` | `SELF_SERVICE` | NotificationController@markAllRead | `3ec27f3` 2026-08-26 | any | **OK** | Tác động: W (own rows) · Kiểm soát trong handler: user filter (173-176) |
| 72 | `POST api/v1/outbound-documents/{id}/attachments` | `permission:outbound.scan\|outbound.post` | DocumentAttachmentController@storeOutbound | `368943c` 2026-08-27 | AD, WS | **OK-with-note** | Tác động: W · Kiểm soát trong handler: Issue must not be POSTED/CANCELLED (189-191). Scope middleware. · Ghi chú: Same scanner note. |
| 73 | `POST api/v1/public/quote-requests` | `PUBLIC_ANONYMOUS` | PublicQuoteRequestController@store | `b6d9a34` 2026-08-30 | ẩn danh | **CONCERN** | Tác động: W (encrypted PII lead rows + request_ip/user_agent, 490-546) · Kiểm soát trong handler: Idempotency-Key 16-128 chars (192-227). Strict validation plus consent and privacy-version checks (236-306). Products must be publicly visible (429-479). Returns only request_id and status. · Ghi chú: The limiter key contains the client-supplied phone, so rotating the phone defeats it. There is no per-IP fallback. → E10 |
| 74 | `POST api/v1/pv-admin/catalog-import` | `permission:pv.master.manage+sku.manage` | PvCatalogImportController@import | `01d2884` 2026-08-31 | AD | **OK** | Tác động: W (SKU + App PV profile, atomic) · Kiểm soát trong handler: `published_to_app` forced to false (docblock 17-20, 35-36). Forbidden fields rejected. Media: https + host allowlist + no redirects (Modules/Warehouse/app/Services/PvCatalogImportService.php:39, 420-424; not verified in depth). |
| 75 | `POST api/v1/pv-admin/product-images` | `permission:pv.master.manage` | PvProductImageController@store | `d019d63` 2026-09-07 | AD | **OK-with-note** | Tác động: W (object storage) · Kiểm soát trong handler: Image type checked by magic bytes (93-102). Malware scan (104-109). Random UUID key (111). Audit (122-136). · Ghi chú: Whether the scanner is configured was not verified. The image is public by key as soon as it is uploaded. |
| 76 | `POST api/v1/reversal-documents/{id}/attachments` | `permission:inventory.reversal.post` | DocumentAttachmentController@storeReversal | `368943c` 2026-08-27 | AD | **OK-with-note** | Tác động: W · Kiểm soát trong handler: Reversal must not be CANCELLED (249-251). Scope middleware. · Ghi chú: Same scanner note. Adding files to a POSTED reversal is allowed (additive only). |
| 77 | `POST api/v1/telemetry/vitals` | `PUBLIC_ANONYMOUS` | TelemetryController@vitals | `d94ab90` 2026-09-07 | ẩn danh | **CONCERN** | Tác động: W (cache counters with a 7-day TTL, 61) · Kiểm soát trong handler: Metric allowlist and value caps (41-58, 104-112). `route` and `build_id` checked by regex (86-98). · Ghi chú: Any `build_id`/`route` that passes the regex creates new keys, so key count is unbounded. The keys land in the shared cache store. → E11 |
| 78 | `POST api/v1/users/{id}/permissions` | `permission:iam.user.manage` | UserController@grantPermission | `368943c` 2026-08-27 | AD | **CONCERN** | Tác động: W (IAM grant) · Kiểm soát trong handler: Delegation: only keys the actor holds (409-414; PermissionDelegation.php:67-97). Only ACTIVE permissions (GrantUserPermissionRequest.php:21-26). Reason + audit required (425-433). Super-admin targets blocked (ProtectSuperAdminTarget.php:31-48). Self-grant blocked by exact string comparison (PreventSelfRoleAssignment.php:22-31). · Ghi chú: An upper-case UUID can likely get past the self check on PostgreSQL: `user_id` is a `uuid` column (Modules/Auth/database/migrations/2026_01_01_000020_create_app_user_table.php:17) and the route has no `whereUuid`. AD can grant iam.\*, audit_log.view_all and warranty.pii.view to any non-super-admin with no extra control. warranty.pii.export can only be granted by SUPER_ADMIN, because AD does not hold it (SEED:179-183). → E6 |
| 79 | `POST api/v1/warranty-component-issues/record` | `permission:warranty.component_issue` | MiniAppComponentIssueStagingController@record | `2d9864f` 2026-09-08 | AD, WaS | **OK-with-note** | Tác động: W. **Stock decreases** when `post_immediately`, which defaults to true (221, 296-309). · Kiểm soát trong handler: Payload field allowlist (766-792). Warehouse must be ACTIVE (759-764). Delegates to the canonical store/scan/post (238-305), which checks case status and scan rules. Idempotency-Key required (223-231). Audit (315-330). Scope middleware checks the body `warehouse_id`. · Ghi chú: Scanned code resolution is not tied to `warehouse_id` (540-575). Low risk while there is one warehouse. |
| 80 | `POST api/v1/warranty-component-issues/resolve-code` | `permission:warranty.component_issue` | MiniAppComponentIssueStagingController@resolveCode | `2d9864f` 2026-09-08 | AD, WaS | **OK-with-note** | Tác động: R · Kiểm soát trong handler: Warehouse must exist and be ACTIVE (133, 139). The cache key has no actor, but the payload carries no PII (148-154). · Ghi chú: Resolves any item or box regardless of warehouse. Returns the status of any `warranty_case_id`. |
| 81 | `POST api/v1/warranty-component-issues/{id}/lines` | `permission:warranty.component_issue` | CurrentBaselineComponentFlowController@storeLine | `2d9864f` 2026-09-08 | AD, WaS | **OK-with-note** | Tác động: W, no stock change · Kiểm soát trong handler: `lockMutableDocument`. Only ACTIVE COMPONENT SKUs accepted (519, 867-875). · Ghi chú: No audit record. |
| 82 | `POST api/v1/warranty-component-issues/{id}/unscan` | `permission:warranty.component_issue` | CurrentBaselineComponentFlowController@unscan | `2d9864f` 2026-09-08 | AD, WaS | **OK** | Tác động: W, no stock change (scan row set to REMOVED) · Kiểm soát trong handler: `lockMutableDocument`. Scan row must belong to the document (921-926). Audit `UNSCAN_COMPONENT_ISSUE` (450-459). |

## 3. Bảng B — 14 route đổi permission

| Route | Đã pin (`c33afcc`) | Runtime | Commit | Loại thay đổi | Vai production truy cập được | Kết luận | Ghi chú |
|---|---|---|---|---|---|---|---|
| `GET\|HEAD api/v1/containers` | `permission:container.view` | `permission:container.view\|putaway.view\|putaway.manage` | `38b51a6` 2026-09-07 | MỞ RỘNG quyền đọc — không tìm thấy bằng chứng BA duyệt; **BA confirmation PENDING** | AD, WM, WS, LD, VW (**gain: LD**) | **CONCERN (no approval)** | → C1, E1 |
| `GET\|HEAD api/v1/containers/{id}` | `permission:container.view` | `permission:container.view\|putaway.view\|putaway.manage` | `38b51a6` 2026-09-07 | MỞ RỘNG quyền đọc — không tìm thấy bằng chứng BA duyệt; **BA confirmation PENDING** | AD, WM, WS, LD, VW (**gain: LD**) | **CONCERN (no approval)** | → C1, E1 |
| `GET\|HEAD api/v1/label-print-events` | `permission:label.print` | `permission:label.print\|packing_label.print\|report.packing_label.view` | `38b51a6` 2026-09-07 | MỞ RỘNG quyền đọc — không tìm thấy bằng chứng BA duyệt; **BA confirmation PENDING** | all 6 (**gain: WM, WS, WaS, LD, VW**) | **CONCERN (no approval)** | → C2, E2 |
| `GET\|HEAD api/v1/reports/document-history` | `permission:report.view` | `permission:report.inventory_flow.view\|report.outbound_flow.view\|report.recipient.view\|report.io_history.view` | `38b51a6` 2026-09-07 | Tách quyền báo cáo theo màn — DELTA-07 (ROLE_BASELINE_PIN.json, disposition `APPLIED_PENDING_SRS_ROW`) | AD, WM, WS, LD, VW | **OK** | Matches DELTA-07. |
| `GET\|HEAD api/v1/reports/import-match` | `permission:report.view` | `permission:report.import_quality.view` | `38b51a6` 2026-09-07 | Tách quyền báo cáo theo màn — DELTA-07 (ROLE_BASELINE_PIN.json, disposition `APPLIED_PENDING_SRS_ROW`) | all 6 | **OK-with-note** | Not reviewed in depth. |
| `GET\|HEAD api/v1/reports/inventory-balance` | `permission:report.view` | `permission:report.stock_reconcile.view` | `38b51a6` 2026-09-07 | Tách quyền báo cáo theo màn — DELTA-07 (ROLE_BASELINE_PIN.json, disposition `APPLIED_PENDING_SRS_ROW`) | all 6 | **OK** | — |
| `GET\|HEAD api/v1/reports/inventory-ledger` | `permission:report.view` | `permission:report.inventory_flow.view\|report.outbound_flow.view\|report.io_history.view` | `38b51a6` 2026-09-07 | Tách quyền báo cáo theo màn — DELTA-07 (ROLE_BASELINE_PIN.json, disposition `APPLIED_PENDING_SRS_ROW`) | AD, WM, WS, LD, VW | **OK-with-note** | RPT-08 residual: WS still reaches it through its RPT-01 key. Documented in PIN:251. |
| `GET\|HEAD api/v1/reports/inventory-reconciliation` | `permission:report.view` | `permission:report.stock_reconcile.view` | `38b51a6` 2026-09-07 | Tách quyền báo cáo theo màn — DELTA-07 (ROLE_BASELINE_PIN.json, disposition `APPLIED_PENDING_SRS_ROW`) | all 6 | **OK** | — |
| `GET\|HEAD api/v1/reports/label-log` | `permission:report.view` | `permission:report.packing_label.view` | `38b51a6` 2026-09-07 | Tách quyền báo cáo theo màn — DELTA-07 (ROLE_BASELINE_PIN.json, disposition `APPLIED_PENDING_SRS_ROW`) | all 6 | **CONCERN** | → E8 |
| `GET\|HEAD api/v1/reports/movement-trace` | `permission:report.view` | `permission:report.inventory_flow.view\|report.outbound_flow.view\|report.stock_reconcile.view\|report.traceability.view` | `38b51a6` 2026-09-07 | Tách quyền báo cáo theo màn — DELTA-07 (ROLE_BASELINE_PIN.json, disposition `APPLIED_PENDING_SRS_ROW`) | all 6 | **OK** | — |
| `GET\|HEAD api/v1/reports/outbound-by-agency` | `permission:report.view` | `permission:report.recipient.view` | `38b51a6` 2026-09-07 | Tách quyền báo cáo theo màn — DELTA-07 (ROLE_BASELINE_PIN.json, disposition `APPLIED_PENDING_SRS_ROW`) | AD, WM, LD, VW | **OK** | — |
| `GET\|HEAD api/v1/reports/outbound-by-recipient` | `permission:report.view` | `permission:report.recipient.view` | `38b51a6` 2026-09-07 | Tách quyền báo cáo theo màn — DELTA-07 (ROLE_BASELINE_PIN.json, disposition `APPLIED_PENDING_SRS_ROW`) | AD, WM, LD, VW | **OK-with-note** | Missing scope filter. Low risk while there is one warehouse. |
| `GET\|HEAD api/v1/reports/warranty-defect-sku-breakdown` | `permission:report.view` | `permission:report.warranty.view` | `38b51a6` 2026-09-07 | Tách quyền báo cáo theo màn — DELTA-07 (ROLE_BASELINE_PIN.json, disposition `APPLIED_PENDING_SRS_ROW`) | AD, WM, WaS, LD, VW | **OK** | — |
| `GET\|HEAD api/v1/reports/warranty-defects` | `permission:report.view` | `permission:report.warranty.view` | `38b51a6` 2026-09-07 | Tách quyền báo cáo theo màn — DELTA-07 (ROLE_BASELINE_PIN.json, disposition `APPLIED_PENDING_SRS_ROW`) | AD, WM, WaS, LD, VW | **OK** | — |

Ghi chú chung cho 11 route báo cáo: so với `report.view` của seeder hiện tại, không vai production nào ĐƯỢC THÊM
quyền; `WAREHOUSE_STAFF` mất outbound-by-agency, outbound-by-recipient và hai báo cáo bảo hành; `WARRANTY_STAFF` mất
document-history, inventory-ledger và hai báo cáo xuất theo nơi nhận. So với hợp đồng đã pin (khi `WARRANTY_STAFF`
chưa có `report.view`), `WARRANTY_STAFF` được thêm các màn của DELTA-07.

## 4. Ba lần mở rộng quyền đọc — BA confirmation PENDING

Không tìm thấy bằng chứng BA duyệt cho cả ba. Pin lại ở đây chỉ ghi nhận hiện trạng của code và **không** thay đổi
permission; nếu BA không xác nhận, sửa route (thu hẹp lại) là một thay đổi code riêng.

#### C1. GET api/v1/containers and GET api/v1/containers/{id}

**(a) Roles that gain access**

- **Production:** LEADERSHIP only. LD holds `putaway.view` but not `container.view` (SEED:347-363).
  - LD was in the same position at the pinned commit (`c33afcc` SEED:185-193), so the gain is real relative to the contract.
- **Already had `container.view`:** AD (SEED:187), WS (SEED:243), VW (SEED:376) and **WM (SEED:440)**.
  - The route comment says WM was not granted `container.view`, which is factually wrong (WR:360-363).
- **No change:**
  - WaS has no putaway/container keys.
  - Deprecated and test roles already hold `container.view` (SEED:279, 300, 465).
  - The compatibility seeder adds no container/putaway keys (WmsPermissionCompatibilitySeeder.php:58-65).
- **Also gaining:** any account with a per-user grant of `putaway.view` or `putaway.manage`.

**(b) Handler behaviour**

- Both handlers are read-only.
  - `index` pages through containers with a filter whitelist of `container_type`/`status`/`zone_code` (ContainerController.php:49-54; ContainerRepository.php:13).
  - `show` returns the container plus its `items` (93-102).
- No warehouse scope is applied. None is applicable: the `containers` table has no warehouse column, and no migration adds one (grep found none).
- Data returned: container codes, types, zones and locations, primary SKU, and contained items with SKU and quantity. No PII.

**(c) Approval evidence**

- **No explicit BA approval was found** for giving LEADERSHIP container data or for widening these routes. What exists:
  - **Code comment:** WR:360-362 says "vai giám sát chỉ-đọc (LEADERSHIP, WAREHOUSE_MANAGER) được BA cấp putaway.view chứ không cấp container.view". This is an engineering rationale, not a BA decision, and it is wrong about WM.
  - **Commit 38b51a6 message:** covers only the inbound new-SKU gate and "gác route báo cáo theo màn". It does not mention containers.
  - **Commit history:** `git log --all` has no other message referencing containers/putaway.view gating.
  - **Closest BA statements:**
    - BA:151-152, LEADERSHIP may see "tổng quan khay".
    - BA:208-214, §4.2 "Xem khay": `leadership-01` = 200, measured on the putaway surface, not on /containers.
    - PIN:168, DELTA-02 resolution: "ô 'Putaway/xếp khay xem | Lãnh đạo'" ✅.
    - BA:89 (WM row) lists `container.view` and `putaway.view` together for "Xem box/khay/container".
  - **FE consumer confirmed:** C:/LAM_PHONG_TECH/HoaNam_FE/WMS_Warehouse_Hoa_Nam_FE/src/modules/putaway/services/putawayService.ts:162,174 reads `API_ENDPOINTS.CONTAINERS`.
  - **Stale docs:** docs/api-contract/HN-BE-API-003_ENDPOINT_PERMISSION_MATRIX.md:312,314 still say `container.view`. The generated catalog shows the new guard (docs/permissions/WMS_PERMISSION_CATALOG_BE1.json:1352,1386); it is not an approval.
- **Verdict:** this is an engineering inference that approved "putaway/tray view" for LD implies reading containers. It needs BA or Tech Lead sign-off.

#### C2. GET api/v1/label-print-events

**(a) Roles that gain access**

- **Production:**
  - WS, through `packing_label.print` (SEED:248) and `report.packing_label.view` (SEED:230).
  - **WaS**, through `report.packing_label.view` (SEED:338).
  - LD (SEED:355), VW (SEED:368) and WM (SEED:441), all through `REPORT_SCREENS_ALL`.
- **Deprecated:** WAREHOUSE_APPROVER (SEED:277).
- **Already had `label.print`:** AD (SEED:172), WAREHOUSE_KEEPER (SEED:297), INBOUND_ONLY_TEST (SEED:462).
- The route comment (WR:408-411) lists LD, WM, VW and "NV kho". **It leaves out WARRANTY_STAFF**, the only gaining role without `outbound.view`.

**(b) Handler behaviour**

- Read-only.
- Runs `DB::table('label_print_events')` over all columns, with optional `label_snapshot_id`/`status` filters and up to 100 rows per page. Adds `reason` from `metadata` (CurrentBaselineOperationsController.php:655-682).
- **No warehouse scope and no output column whitelist.**
- Base columns: id, label_snapshot_id, printer_config_id, status, error_message, printed_at, plus audit columns such as created_by (Modules/Warehouse/database/migrations/2026_08_09_000400_align_latest_erd_baseline.php:280-289). Later ERD migrations add further columns; the full runtime column list was not verified.
- No customer PII in the base schema. `metadata.reason` is free text.

**(c) Approval evidence**

- **No explicit BA approval for this endpoint was found.** What exists:
  - **DELTA-07** (PIN:247-253) approves RPT-05 screen access for WS (6/9 screens) and WaS (4/9). Its list of re-gated endpoints (PIN:250) names "reports/inventory-balance, reports/import-match, reports/label-log, reports/warranty-\*, reports/outbound-by-\*". **label-print-events is not on that list.**
  - **Commit 38b51a6 message:** does not mention label-print-events. No other commit message references it.
  - **Code comment:** WR:408-413 argues this is a read endpoint for RPT-05 and should be gated by the screen's read permission. This is engineering rationale, not a BA line.
  - **BA indirect:**
    - BA:113, WS "Nhãn đóng gói: xem trước / in / in lại".
    - SEED:411-412, WM must not run Preview/In/Reprint as a business action. Reading print logs is not such an action.
  - **FE consumer confirmed:** packing-label-api.ts:218-223 (RPT-05) reads `/label-print-events`. The FE comment at packing-label-api.ts:36 still says "permission `label.print`" and is stale.
  - **Stale docs:** HN-BE-API-003_ENDPOINT_PERMISSION_MATRIX.md:336 still says `label.print`.
- **Verdict:** this follows the BA-approved RPT-05 screen access (DELTA-07) by inference only. Record it explicitly in DELTA-07 and narrow the response.

---

## 5. `GET reports/dashboard-overview` — gác chỉ bằng `report.view`

Route mới (bảng A), ghi bù thành amendment `WWHN-328-AMD-001` (`docs/evidence/WWHN-328/AGGREGATE_ROUTE_AMENDMENTS.json`,
BA + Tech Lead: PENDING). Cổng giữ nguyên `report.view`; câu hỏi quyền theo màn chuyển cho BA.

- **Who can call it:** all 6 production roles.
- **What it aggregates** (ORC:920-1189):
  - **Inventory flow and stock (scoped to the caller's warehouses):**
    - `kpi.on_hand` / `receipt_today` / `issue_today` / `receipt_month` / `issue_month`, each split into product/component (1145-1155).
    - `flow_daily` (receipt/issue per day), `stock_trend` (closing qty), `stock_structure` (sku_type × stock_status) (1007-1040, 987-1005).
    - `top_sku.best` / `top_sku.worst` with SKU code, name and issue qty (1042-1060).
    - This is RPT-01/01B/02-level data.
  - **Warranty (GLOBAL, no warehouse scope):**
    - `warranty.by_status` counts (1062-1068) and `overdue_count` (1072-1076).
    - `overdue_sample`: up to 50 rows of warranty_case_code, masked customer_name, status, estimated_completion_date, days_overdue (1088-1107).
    - `warranty_received` for today/week/month (1109-1113, 1156-1166).
    - This is RPT-06 / warranty-module data.
  - **Data quality:** `issues.inventory_mismatch` (from RPT-02), `import_error` (import_reports, RPT-04), `print_failed` (label_snapshots, RPT-05), `sku_pending_info` (SKU master) (1115-1141, 1181-1187).
- **Consequences:**
  - WS and VW have no `warranty.view`; the BA doc measures 403 on warranty surfaces for them (BA:196, 199). They still see warranty case codes, statuses, due dates and counts.
  - WaS lacks the RPT-01/01B/08 screens but sees inventory flow KPIs.
- **BA must decide** whether the Dashboard is intentionally cross-domain for every role holding `report.view`. The 38b51a6 message deliberately kept `report.view` so role dashboards would not go blank.

#### D.4 Governance drift: WaS and reports

- **Saying WaS gets no reports:**
  - BA:139 "Báo cáo: KHÔNG được xem".
  - BA:276-286 §6②, "BA chốt: không cấp".
  - PIN:227-231, DELTA-06 decision #2, "KHÔNG".
- **Saying WaS gets 4/9 screens:**
  - SEED:327-338.
  - PIN:99 and DELTA-07 (PIN:243-253), which describe a later BA reversal on 07/09.
- **Recommendation:** update the BA handover doc and add a superseded note to DELTA-06 decision #2. A reviewer must not be able to "fix" the seeder back.

---

## 6. CONCERN và khuyến nghị (không đổi code trong commit pin hợp đồng)

Items are ordered by severity (the heading carries the level). They cover 15 CONCERN routes and there are no FAILs.

#### E1. Containers widening has no explicit BA approval (M1, M2) — Low; approval gap

**Evidence**

- See Section C1.
- LD gains read access to containers and their items (ContainerController.php:49-54, 93-102).
- The route comment misstates WM's permissions (WR:360-363 vs SEED:440).

**Recommendations**

- Get BA/Tech Lead sign-off and record it as a new DELTA entry citing the "Putaway/xếp khay xem | Lãnh đạo" matrix cell (PIN:168).
- One of the following:
  - grant `container.view` to LEADERSHIP explicitly in SEED and revert the OR-gate, which keeps the role/permission map legible; or
  - keep the OR-gate and fix the comment.
- Update HN-BE-API-003 lines 312 and 314.

#### E2. label-print-events widening has no explicit BA approval; response is too broad (M3) — Low

**Evidence**

- See Section C2.
- 5 production roles gain access, including WaS, which has no `outbound.view`.
- The handler returns raw table rows with no column whitelist and no scope (CurrentBaselineOperationsController.php:663-681).
- DELTA-07's endpoint list omits this route (PIN:250).

**Recommendations**

- Add the endpoint explicitly to DELTA-07 with BA sign-off.
- Select an explicit column list, excluding audit internals such as created_by/updated_by unless RPT-05 needs them.
- Drop `packing_label.print` from the OR, since `report.packing_label.view` already covers WS.
- Update HN-BE-API-003:336 and the FE comment at packing-label-api.ts:36.

#### E3. An `inbound.scan` holder can edit SKU master data of any SKU that never entered stock (N41) — Medium-Low

**Evidence**

- The guard is only `inbound.scan` (WR:126-133).
- The handler allows edits when info is missing **or** the SKU never entered stock (InboundSkuInfoController.php:127-134, 205-212).
- Draft inbound lines accept **any existing SKU** (CurrentBaselineDocumentController.php:125, 153).
- So a WS user can put any unstocked catalog SKU (for example newly imported catalogue SKUs) on their own DRAFT receipt and then:
  - rename it or change its unit (149); and
  - clear brand/category/model/power_source by sending `null` (230-233).
- Public App PV uses `display_name ?: sku_name` (PublicCatalogReadModel.php:220, 902), so a rename can reach published items when they have no display_name.
- An audit record exists (152-168).

**Recommendations**

- Allow edits only while `SkuInboundInfoRule::missingFields` is non-empty (SkuInboundInfoRule.php:27-45); drop the "never entered stock" branch. Alternatively, require provenance: the SKU was created by the scan flow for this document.
- Do not accept `null` for master links that are already set.
- Refuse SKUs with `published_to_app = true`.
- Add negative tests.

#### E4. Attachment delete can remove evidence of closed documents; scope check fails open (N2, N3) — Low-Medium

**Evidence**

- `destroy` checks type permission and scope but **not the parent document's status** (DAC:372-405).
- The store endpoints do block POSTED/CANCELLED (DAC:128-130, 189-191, 249-251), so delete is inconsistent with them.
- Effect: WS (`inbound.scan`) can soft-delete source-document evidence of a POSTED receipt, and AD can delete evidence of a POSTED reversal. The reversal post requires at least one ACTIVE evidence file.
- `assertWarehouseScope` returns without checking when the warehouse cannot be resolved (DAC:634-641, 652).
- The db2760b test covers only an INBOUND attachment against an outbound-only actor (WwhnSec09HardeningTest.php:159-203).

**Recommendations**

- Return 409 on delete when the parent is POSTED/CANCELLED (and for REVERSAL, POSTED).
- Fail closed for scoped users when the warehouse cannot be resolved.
- Add tests:
  - an `inbound.scan`-only actor deleting REVERSAL/OUTBOUND evidence gets 403;
  - WaS downloading INBOUND evidence gets 403;
  - an out-of-scope download/delete gets 403;
  - deleting evidence of a POSTED document gets 409.

#### E5. pending-evidence list has no warehouse scope and no type-permission split (N9, N12) — Low

**Evidence**

- `DAC:269-324` lists DRAFT inbound documents (`dst_warehouse_id`) and DRAFT reversals across **all** warehouses to any `inventory.view` holder.
- That includes WaS, which BA measures as 403 on inbound documents (BA:197).
- Data returned: document id/no, warehouse id, status and creation time.

**Recommendations**

- Filter by `warehouseScopeIds()`.
- Include INBOUND rows only when the actor has inbound.view, inbound.create or inbound.scan.
- Include REVERSAL rows only when the actor has inventory.view or inventory.reversal.post, matching the `download` type map (DAC:61-74).

#### E6. The self-grant block can likely be bypassed with an upper-case UUID; privileged keys have no extra control (N78) — Low

**Evidence**

- `PreventSelfRoleAssignment` compares the raw route `id` string with the actor's `user_id` exactly (PreventSelfRoleAssignment.php:22-31).
- `app_user.user_id` is a `uuid` column (migration :17), and `find()` on PostgreSQL matches regardless of letter case. The route has no `whereUuid` (Modules/Auth/routes/api.php:73-76).
- So `POST /users/<OWN-ID-UPPERCASE>/permissions` probably passes the self check.
  - This was not run: the tests use sqlite (case-sensitive strings), which would not catch it.
- **Impact is bounded by PermissionDelegation:** a user can only copy keys they already hold (PermissionDelegation.php:67-97). They can still make role-derived privileges persistent as user-level grants that survive later role removal.
- **Related routes (not in scope):**
  - `POST users/{id}/roles` uses the same middleware.
  - grep shows no PermissionDelegation call in `UC@assignRoles` (UC:293-342).
  - Not verified whether AssignUserRoleRequest enforces delegation.
- **Privileged keys:** AD can grant iam.\*, audit_log.view_all and warranty.pii.view to any non-super-admin account. Only a reason is required (GrantUserPermissionRequest.php:21-26; UC:425-433).
  - BA principle 9 treats Audit Log as a sensitive permission (SEED:357-360).

**Recommendations**

- Compare case-insensitively (or load the target user and compare `user_id`), and add `->whereUuid('id')` on user routes.
- Deny iam.\*, audit_log.\* and warranty.pii.\* through user_permission unless the actor is SUPER_ADMIN, or require dual control.
- Alert on `GRANT_USER_PERMISSION` for sensitive keys.

#### E7. dashboard-overview shows cross-domain data behind `report.view` (N32) — Low; needs BA decision

**Evidence**

- See D.3. The route is gated by `report.view` only (WR:303).
- Warranty case codes, statuses, due dates, overdue counts and receipt counts are GLOBAL and visible to WS and VW (ORC:1062-1113, 1156-1180). BA measures both as 403 on warranty surfaces (BA:196, 199).
- Inventory flow KPIs and top-SKU data are visible to WaS, which lacks RPT-01/01B.
- Names are masked (ORC:1078-1102).

**Recommendation.** BA should choose one:

- **(a)** Accept the Dashboard as cross-domain and record it in DELTA-07; or
- **(b)** Filter blocks by permission in the builder:
  - the warranty block only for warranty.view or report.warranty.view;
  - flow blocks only for report.inventory_flow.view or report.outbound_flow.view.

  With (b), the cache key must then include the permission variant.

#### E8. RPT-05 label-log screen data returns raw customer phone (M9) — Low

**Evidence**

- `ALL@build` puts `customer_phone` from `label_payload` into each row unmasked (ALL:281).
- The export masks it, with a code comment saying why (ALL:43-50, 65, 91).
- The phone originates from the packing label (Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:238, 353).
- Readers: every `report.packing_label.view` holder, including WaS, which has no `outbound.view`, and LD/VW/WM, whom BA describes as "PII bị che".
- No warehouse scope.
- Other surfaces handle the same data differently:
  - RPT-07 masks unless the caller has `warranty.pii.view`, with the PII variant in the cache key (ORC:2283-2304, 2347-2356).
  - BAM always masks (BAM:202).

**Recommendations**

- Mask `customer_phone` in the index unless the actor holds the PII permission, and add a PII variant to any cache key.
- Align the recipient-PII rule across RPT-05, RPT-07, outbound-by-recipient and NFC resolve-tag; resolve-tag uses `outbound.view` (NFC:1014).

#### E9. NFC confirm lets a client-chosen `code_value` shadow another item's serial (N42) — Low

**Evidence**

- `confirm` accepts an optional client `code_value` (NFC:310, 344).
- It checks only for duplicates in `physical_codes` (NFC:395-399), not against item serials.
- `ProductIdentityResolver::findItemByUnique` looks up `physical_codes.code_value` **before** `item_instances.serial_number` (Modules/Warehouse/app/Services/ProductIdentityResolver.php:55-71).
- So an `assign_rfid` holder can attach alias "SER-B" to item A. Later scans of item B's serial, raw or inside a composite QR when the SKU matches, then resolve to item A.
- `identityOccupied()` exists (ProductIdentityResolver.php:78-87) but is not called here.
- Only AD holds `assign_rfid` in production.

**Recommendations**

- Ignore the client `code_value` and always use `NFC-<UID>`; or
- reject values that do not start with `NFC-` or for which `identityOccupied()` is true.

#### E10. The anonymous quote-request limiter can be bypassed by rotating `phone` (N73) — Medium

**Evidence**

- The limiter key is IP + sha1(request phone) (ASP:164-168).
- `ApiRateLimit` skips `/api/v1/public/*` and routes that declare a throttle (ApiRateLimit.php:59-62; ApiAuthContext.php:60-61), so there is no per-IP cap.
- Each accepted request writes encrypted PII rows plus request_ip/user_agent (PublicQuoteRequestController.php:490-546).
- An attacker with one IP and changing phone numbers can spam unbounded leads.
- The go-live allowlist relies on this throttle as its compensating control (BackendGoLiveCheck.php:98-100).

**Recommendations**

- Add a second limiter keyed by IP only, for example ≤10/min and ≤50/day, alongside the phone-keyed one.
- Consider a bot challenge, and a global per-phone cap independent of IP.

#### E11. Anonymous telemetry can inflate cache keys in the shared Valkey store (N77) — Medium-Low

**Evidence**

- Each request writes `wms:rum:{day}:{build_id}:{route}:{metric}:*` with a 7-day TTL through the default cache store (TelemetryController.php:61, 114-131).
- `build_id` is free-form within `^[A-Za-z0-9._-]{1,64}$`, and `route` is any path-shaped string within the regex (86-98).
- So the number of distinct keys is attacker-controlled: roughly 9 keys per request, at 120 requests/min per IP (routes/api.php:19-21).
- The default cache is `redis` (.env.example:112), and Valkey runs with `maxmemory 256mb`, `volatile-lru` (docker-compose.yml:241-246).
- Under pressure, eviction hits **other TTL keys in the same store**:
  - rate-limiter counters;
  - MFA challenge and TOTP replay markers (MFS:167-172, 268-276);
  - NFC reservations;
  - report caches.
- Not load-tested.
- The `bump` get-then-put is also not atomic (127-131); this affects accuracy only.

**Recommendations**

- Allowlist `build_id` (current and previous build ids) and known route patterns; or hash them into a bounded bucket set.
- Put RUM counters in a separate Redis DB/store with its own memory cap.
- Use atomic INCR + EXPIRE.
- Add a daily key-count ceiling.

#### E12. forgot-password has no aggregate throttle and a user-existence timing oracle (N46) — Low

**Evidence**

- The only limiter is 3/min keyed by IP + email (PRC:57-64). `ApiRateLimit` skips the path (ApiAuthContext.php:56).
- There is no per-IP total cap, so one IP can target many accounts. There is no per-account total cap, so many IPs can email-bomb one victim.
- For existing ACTIVE users the handler runs a bcrypt `Hash::make`, a DB insert, an audit write and a queued mail dispatch (PRC:70-89; PasswordResetOtpMail.php:15). For unknown emails it returns immediately.
- The response text is the same, but the timing difference reveals whether an account exists.

**Recommendations**

- Add per-IP and per-email total limiters (e.g. 10/hour per email).
- Do the same work on both branches, for example always dispatching a job that decides whether to send.
- Optionally add a challenge after N requests.

#### Non-blocking notes (OK-with-note, recommended)

- Component-issue `storeLine`/`updateLine`/`destroyLine` write no audit record (CFC:508-552, 580-629, 647-681).
- The attachment and PV image malware scan returns UNSCANNED when no scanner is configured (DocumentAttachmentScanner.php:87-90). Verify production config.
- MFA enroll/confirm have no password step-up (MFA:69-136).
- reset-password does not apply `CredentialPolicy`.
- verify-otp attempts counter is not atomic (PRC:132-141).
- BaM1 reports and several Mini App NFC/product reads have no warehouse filter. Low risk while there is one warehouse.
- NFC resolve-tag gates recipient PII on `outbound.view`, unlike RPT-07 (`warranty.pii.view`).

## 7. Sign-off

| Hạng mục | Người duyệt | Trạng thái |
|---|---|---|
| Rà soát AppSec tĩnh 96 route (tài liệu này) | AppSec reviewer | Xong — tĩnh, chưa chạy runtime |
| Pin lại `database/golive/route_permission_contract.php` theo đúng bảng A + B | Tech Lead | **PENDING** |
| `GET containers`, `GET containers/{id}` mở thêm `putaway.view\|putaway.manage` (LEADERSHIP được thêm quyền đọc) | BA | **PENDING** |
| `GET label-print-events` mở thêm `packing_label.print\|report.packing_label.view` (5 vai production được thêm quyền đọc) | BA | **PENDING** |
| `GET reports/dashboard-overview` chỉ gác `report.view` (mục 5) | BA | **PENDING** |
| 11 route báo cáo tách quyền theo màn | BA (DELTA-07) | Đã áp theo DELTA-07 — disposition `APPLIED_PENDING_SRS_ROW` (nguồn: ROLE_BASELINE_PIN.json) |
| Xử lý các CONCERN mục 6 (ưu tiên E3, E4, E10, E11) | Tech Lead + Security | **PENDING** — ngoài phạm vi commit này |
