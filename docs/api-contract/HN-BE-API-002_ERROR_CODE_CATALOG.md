# HN-BE-API-002 - Error code catalog (backend API v1)

> **Tệp sinh tự động - không sửa tay.** Nguồn: `scripts/generate-error-code-catalog.mjs` quét `app/`, `Modules/`, `bootstrap/`.
> Sửa error code trong code rồi chạy lại `node scripts/generate-error-code-catalog.mjs`. CI chạy `--check` và fail khi lệch.

Task: WWHN-309 - [BE] Hoàn thiện tài liệu kiến trúc/API/vận hành.

## 1. Envelope lỗi

```json
{
  "success": false,
  "message": "Thông điệp tiếng Việt hiển thị được cho người dùng",
  "error_code": "STATE_CONFLICT",
  "errors": { "field": ["chi tiết"] },
  "error": { "code": "STATE_CONFLICT", "details": { "field": ["chi tiết"] } },
  "meta": { "request_id": "uuid", "timestamp": "2026-08-18T10:00:00+07:00" }
}
```

`error_code` và `error.code` **luôn bằng nhau**. `error_code` là khoá FE hiện dùng, `error.code` là dạng lồng theo Annex C.
Không client nào được coi một trong hai là optional. `message` không bao giờ chứa stack trace, câu SQL hay secret.

## 2. Ý nghĩa HTTP status

| HTTP | Mã mặc định khi caller không truyền error code | Ý nghĩa |
| --- | --- | --- |
| 400 | `BAD_REQUEST` | request sai định dạng hoặc không parse được |
| 401 | `UNAUTHENTICATED` | thiếu hoặc hết hạn access token |
| 403 | `ACCESS_DENIED` | thiếu permission hoặc ngoài data scope |
| 404 | `RESOURCE_NOT_FOUND` | không tồn tại hoặc nằm ngoài phạm vi dữ liệu |
| 405 | `METHOD_NOT_ALLOWED` | HTTP method không được hỗ trợ |
| 409 | `STATE_CONFLICT` | xung đột trạng thái, nghiệp vụ, đồng thời hoặc idempotency |
| 412 | `STALE_VERSION` | If-Match không khớp version hiện tại |
| 422 | `BUSINESS_RULE_ERROR` | vi phạm validation hoặc quy tắc nghiệp vụ |
| 428 | `PRECONDITION_REQUIRED` | thiếu If-Match bắt buộc |
| 429 | `TOO_MANY_REQUESTS` | vượt rate limit |
| 500 | `INTERNAL_SERVER_ERROR` | lỗi ngoài dự kiến, giao dịch đã rollback |
| 503 | `SERVICE_UNAVAILABLE` | dependency bắt buộc không sẵn sàng |

## 3. Danh mục error code (247 mã)

### HTTP 400

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `BAD_REQUEST` | 1 | `app/Traits/ApiResponse.php (default map)` |
| `INVALID_EXCEL_FILE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php:579` |
| `INVALID_HEADER` | 2 | `Modules/Warehouse/app/Http/Controllers/ReversalDocumentController.php:206` |
| `INVALID_PATH` | 1 | `Modules/Warehouse/app/Http/Controllers/PvPublicCatalogController.php:99` |
| `INVALID_QUERY` | 7 | `Modules/Warehouse/app/Http/Controllers/PvPublicCatalogController.php:25` |
| `MALFORMED_JSON` | 1 | `app/Http/Middleware/ApiConventionMiddleware.php:34` |

### HTTP 401

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `UNAUTHENTICATED` | 19 | `Modules/Auth/app/Http/Controllers/AuthController.php:97` |

### HTTP 403

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `ACCESS_DENIED` | 7 | `Modules/Auth/app/Http/Controllers/AuthController.php:116` |
| `DATA_SCOPE_DENIED` | 3 | `Modules/Warehouse/app/Http/Middleware/EnforceInboundWarehouseScope.php:84` |
| `PERMISSION_DENIED` | 2 | `Modules/Warehouse/app/Http/Controllers/AlignedWarrantyReportController.php:51` |
| `WAREHOUSE_SCOPE_DENIED` | 1 | `Modules/Warehouse/app/Http/Controllers/ScanController.php:138` |

### HTTP 404

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `COMPONENT_LINE_NOT_FOUND` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php:135` |
| `CONTAINER_ITEM_NOT_ACTIVE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php:285` |
| `CONTAINER_NOT_FOUND` | 4 | `Modules/Warehouse/app/Services/ContainerService.php:75` |
| `DOCUMENT_LINE_NOT_FOUND` | 4 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:51` |
| `INBOUND_SCAN_ENTRY_NOT_FOUND` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php:82` |
| `LABEL_NOT_FOUND` | 3 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:333` |
| `MOVEMENT_NOT_FOUND` | 1 | `Modules/Warehouse/app/Http/Controllers/ReversalDocumentController.php:372` |
| `OUTBOUND_MATCH_NOT_FOUND` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php:170` |
| `PHYSICAL_CODE_NOT_FOUND` | 5 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php:344` |
| `PUTAWAY_NOT_FOUND` | 2 | `Modules/Warehouse/app/Http/Controllers/PutawayController.php:203` |
| `RESOURCE_NOT_FOUND` | 43 | `app/Traits/ApiResponse.php:104` |
| `REVERSAL_NOT_FOUND` | 2 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:227` |
| `SCAN_CODE_NOT_FOUND` | 3 | `Modules/Warehouse/app/Services/ScanClassificationService.php:66` |
| `SKU_NOT_FOUND` | 5 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineItemIdentityController.php:44` |
| `WAREHOUSE_NOT_FOUND` | 1 | `Modules/Warehouse/app/Http/Controllers/ScanController.php:145` |

### HTTP 405

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `METHOD_NOT_ALLOWED` | 2 | `bootstrap/app.php:83` |

### HTTP 409

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `ACTIVE_WARRANTY_EXISTS` | 3 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php:243` |
| `AGENCY_CODE_IMMUTABLE_AFTER_USE` | 1 | `Modules/Warehouse/app/Http/Controllers/AgencyController.php:211` |
| `ALREADY_FULLY_REVERSED` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:340` |
| `BOX_IN_OTHER_ACTIVE_RECEIPT` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineInboundBoxController.php:130` |
| `BOX_NOT_PUTAWAY_ELIGIBLE` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePutawayTaskController.php:115` |
| `BOX_NOT_RECEIVABLE` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineInboundBoxController.php:112` |
| `CONCURRENCY_CONFLICT` | 8 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerController.php:74` |
| `CONTAINER_CLOSED` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php:142` |
| `CONTAINER_CODE_DUPLICATE` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:554` |
| `CONTAINER_IMMUTABLE` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:80` |
| `CONTAINER_NOT_EDITABLE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php:196` |
| `CONTAINER_STATE_CONFLICT` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:158` |
| `CONTAINER_SUBLEDGER_CHANGED` | 1 | `Modules/Warehouse/app/Services/ComponentContainerSubledgerService.php:131` |
| `DEFECT_CODE_IMMUTABLE_AFTER_USE` | 1 | `Modules/Warehouse/app/Http/Controllers/DefectCatalogController.php:202` |
| `DOWNSTREAM_DEPENDENCY_EXISTS` | 4 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:518` |
| `DUPLICATE_CODE_VALUE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePhysicalCodeController.php:144` |
| `DUPLICATE_COMPONENT_ITEM` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php:167` |
| `DUPLICATE_HARDWARE_UID` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePhysicalCodeController.php:134` |
| `DUPLICATE_ITEM_IN_DOCUMENT` | 2 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:671` |
| `DUPLICATE_ITEM_MATCH` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:628` |
| `DUPLICATE_OUTBOUND_REQUEST_NO` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineImportController.php:270` |
| `DUPLICATE_STOCK_EFFECT` | 2 | `Modules/Warehouse/app/Services/AlignedOutboundStockService.php:159` |
| `IMPORT_HAS_BLOCKING_ERRORS` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineImportController.php:213` |
| `INVALID_WARRANTY_TRANSITION` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php:320` |
| `ITEM_ALREADY_IN_CONTAINER` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php:214` |
| `ITEM_ALREADY_MATCHED` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:544` |
| `ITEM_ALREADY_RECEIVING` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:573` |
| `LABEL_SNAPSHOT_STALE` | 1 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:485` |
| `LABEL_STATE_CONFLICT` | 2 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:344` |
| `LEGACY_ITEM_LABEL_NOT_APPLICABLE` | 1 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:415` |
| `MASTER_CODE_IMMUTABLE_AFTER_USE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineMasterController.php:248` |
| `MATCH_NOT_ACTIVE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php:181` |
| `OUTBOUND_REQUEST_ALREADY_USED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php:465` |
| `PACKING_LABEL_ALREADY_CREATED` | 1 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:119` |
| `PARENT_CONTAINER_NOT_AVAILABLE` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:399` |
| `PHYSICAL_CODE_ALREADY_ASSIGNED` | 4 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php:64` |
| `PHYSICAL_CODE_IN_OPEN_OUTBOUND` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePhysicalCodeController.php:228` |
| `PHYSICAL_CODE_NOT_ACTIVE` | 4 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerController.php:52` |
| `PHYSICAL_RETURN_REQUIRED` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:269` |
| `PRINT_FAILED` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php:849` |
| `PUTAWAY_DRAFT_EXISTS` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePutawayTaskController.php:55` |
| `REFERENCE_EXISTS` | 1 | `Modules/Auth/app/Http/Controllers/RoleController.php:196` |
| `REVERSAL_ITEM_ALREADY_REVERSED` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:569` |
| `REVERSAL_NOT_DRAFT` | 4 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:266` |
| `REVERSAL_NOT_PENDING_RETURN` | 2 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:231` |
| `REVERSAL_OF_REVERSAL_FORBIDDEN` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:281` |
| `REVERSAL_POSTED_IMMUTABLE` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:694` |
| `SCAN_CODE_INACTIVE` | 2 | `Modules/Warehouse/app/Services/CanonicalScanClassificationService.php:97` |
| `SCAN_CODE_UNASSIGNED` | 1 | `Modules/Warehouse/app/Services/ScanResolver.php:50` |
| `SCAN_CONTAINER_INACTIVE` | 2 | `Modules/Warehouse/app/Services/ScanClassificationService.php:276` |
| `SCAN_CONTEXT_CONFLICT` | 1 | `Modules/Warehouse/app/Services/ScanClassificationService.php:414` |
| `SCAN_ENTRY_NOT_ACTIVE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php:93` |
| `SCAN_ITEM_ALREADY_RECEIVED` | 1 | `Modules/Warehouse/app/Services/StockDocumentService.php:333` |
| `SCAN_LOCATION_UNKNOWN` | 1 | `Modules/Warehouse/app/Services/ScanClassificationService.php:369` |
| `SCAN_SKU_INACTIVE` | 1 | `Modules/Warehouse/app/Services/CanonicalScanClassificationService.php:42` |
| `SCAN_WAREHOUSE_MISMATCH` | 1 | `Modules/Warehouse/app/Services/ScanClassificationService.php:392` |
| `SCANNED_DOCUMENT_IMMUTABLE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php:274` |
| `SCANNED_LINE_STRUCTURE_IMMUTABLE` | 4 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php:345` |
| `SELF_ESCALATION_DENIED` | 1 | `Modules/Auth/app/Http/Middleware/PreventSelfRoleAssignment.php:27` |
| `SERIAL_ALREADY_CONTAINERIZED` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:298` |
| `SERIAL_NOT_CONTAINERIZABLE` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:265` |
| `SKU_CODE_DUPLICATE` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:437` |
| `SKU_IDENTITY_IMMUTABLE_AFTER_STOCK` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineSkuController.php:180` |
| `SKU_VERSION_CONFLICT` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:465` |
| `SOURCE_KEY_CONFLICT` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineImportController.php:106` |
| `SOURCE_REQUEST_IMMUTABLE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php:263` |
| `STATE_CONFLICT` | 41 | `Modules/Warehouse/app/Exceptions/WarehouseException.php:18` |
| `SYSTEM_ROLE_IMMUTABLE` | 1 | `Modules/Auth/app/Http/Middleware/EnsureMutableRole.php:28` |
| `UNIQUE_CONSTRAINT_CONFLICT` | 1 | `bootstrap/app.php:123` |
| `WAREHOUSE_INACTIVE` | 1 | `Modules/Warehouse/app/Http/Controllers/ScanController.php:148` |

### HTTP 412

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `STALE_VERSION` | 22 | `Modules/Auth/app/Http/Controllers/UserController.php:214` |

### HTTP 422

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `PHYSICAL_CODE_NOT_FOUND` | 5 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php:344` |
| `SKU_NOT_FOUND` | 5 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineItemIdentityController.php:44` |
| `PHYSICAL_CODE_NOT_ACTIVE` | 4 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerController.php:52` |
| `ACTIVE_WAREHOUSE_BASELINE_VIOLATION` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineImportController.php:220` |
| `ATTACHMENT_CONTENT_TYPE_INVALID` | 1 | `app/Support/WarrantyAttachmentScanner.php:48` |
| `ATTACHMENT_CONTENT_TYPE_MISMATCH` | 1 | `app/Support/WarrantyAttachmentScanner.php:55` |
| `ATTACHMENT_LIMIT_EXCEEDED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyAttachmentController.php:67` |
| `ATTACHMENT_MALWARE_DETECTED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyAttachmentController.php:84` |
| `BOX_CODE_CONFLICT` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineInboundBoxController.php:106` |
| `BOX_REQUIRED` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePutawayTaskController.php:110` |
| `BOX_SKU_MISMATCH` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineInboundBoxController.php:109` |
| `BRAND_NOT_ACTIVE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineMasterController.php:86` |
| `BUSINESS_RULE_ERROR` | 3 | `Modules/Warehouse/app/Http/Controllers/OperationalReportController.php:1593` |
| `BUSINESS_RULE_VIOLATION` | 8 | `Modules/Warehouse/app/Http/Controllers/PvAdminResourceController.php:455` |
| `CANCEL_REASON_REQUIRED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyController.php:347` |
| `COMPONENT_BOX_INSUFFICIENT` | 1 | `Modules/Warehouse/app/Services/ComponentContainerSubledgerService.php:38` |
| `COMPONENT_BOX_INVALID` | 1 | `Modules/Warehouse/app/Services/ComponentContainerSubledgerService.php:24` |
| `COMPONENT_BOX_NOT_FOUND` | 1 | `Modules/Warehouse/app/Http/Middleware/EnsureInboundComponentBox.php:48` |
| `COMPONENT_BOX_REQUIRED` | 2 | `Modules/Warehouse/app/Http/Middleware/EnsureInboundComponentBox.php:39` |
| `COMPONENT_CANNOT_HAVE_CHILDREN` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:211` |
| `COMPONENT_ITEM_NOT_AVAILABLE` | 3 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php:159` |
| `COMPONENT_ITEM_TARGET_REQUIRED` | 1 | `Modules/Warehouse/app/Services/ComponentContainerSubledgerService.php:68` |
| `COMPONENT_NOT_ACTIVE` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:238` |
| `COMPONENT_PURPOSE_INVALID` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:403` |
| `COMPONENT_SKU_REQUIRED` | 5 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php:74` |
| `COMPONENT_TARGET_XOR` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php:144` |
| `CONTAINER_CYCLE` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePutawayTaskController.php:140` |
| `CONTAINER_ITEM_TARGET_REQUIRED` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:254` |
| `CONTAINER_ITEM_XOR` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php:202` |
| `CONTAINER_PARENT_CYCLE` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:412` |
| `CONTAINER_PRIMARY_SKU_MISMATCH` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php:224` |
| `CONTAINER_SELF_PARENT` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:387` |
| `CONTAINER_WAREHOUSE_MISMATCH` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:404` |
| `DEACTIVATION_REASON_REQUIRED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePhysicalCodeController.php:212` |
| `DOCUMENT_HAS_NO_LINES` | 4 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php:183` |
| `DUPLICATE_COMPONENT` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:230` |
| `DUPLICATE_CONTAINER_ITEM` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:316` |
| `DUPLICATE_PHYSICAL_CODE` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:291` |
| `EMPTY_CONTAINER_CANNOT_SEAL` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:173` |
| `EMPTY_EXCEL_FILE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php:583` |
| `EMPTY_UPDATE` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineMasterController.php:235` |
| `EXPECTED_QTY_BELOW_SCANNED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php:380` |
| `FULL_POST_REQUIRED` | 6 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:469` |
| `FULL_SCAN_REQUIRED_FOR_LABEL` | 1 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:171` |
| `IMPORT_HAS_NO_VALID_ROWS` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineImportController.php:231` |
| `INBOUND_WAREHOUSE_NOT_CANONICAL` | 1 | `Modules/Warehouse/app/Http/Middleware/EnsureCanonicalInboundWarehouse.php:47` |
| `INSUFFICIENT_AVAILABLE_STOCK` | 3 | `Modules/Warehouse/app/Services/AlignedOutboundStockService.php:179` |
| `INSUFFICIENT_STOCK` | 7 | `Modules/Warehouse/app/Exceptions/WarehouseException.php:12` |
| `INVALID_CARRIER_TYPE` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:380` |
| `INVALID_COMPONENT` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:224` |
| `INVALID_COMPONENT_QUANTITY` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:233` |
| `INVALID_CONTAINER_CODE` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:357` |
| `INVALID_CONTAINER_QUANTITY` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:308` |
| `INVALID_CONTAINER_STATUS` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:369` |
| `INVALID_CONTAINER_TYPE` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:362` |
| `INVALID_EXCEL_TEMPLATE` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php:590` |
| `INVALID_PHYSICAL_CODE` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:374` |
| `INVALID_PHYSICAL_CODE_STATUS` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:383` |
| `INVALID_PHYSICAL_CODE_TYPE` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:377` |
| `INVALID_QUANTITY` | 4 | `Modules/Warehouse/app/Services/AlignedOutboundStockService.php:156` |
| `INVALID_RETURN_CONDITION` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:233` |
| `INVALID_SKU_CODE` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:397` |
| `INVALID_SKU_NAME` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:407` |
| `INVALID_UUID` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:578` |
| `INVALID_VERSION` | 21 | `Modules/Auth/app/Http/Controllers/UserController.php:211` |
| `INVENTORY_SERIAL_NOT_FOUND` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:260` |
| `ITEM_NOT_AVAILABLE` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:530` |
| `ITEM_NOT_FOUND` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php:210` |
| `ITEM_NOT_IN_ORIGINAL_TRANSACTION` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:483` |
| `ITEM_NOT_MATCHED_IN_DOCUMENT` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePhysicalCodeController.php:123` |
| `ITEM_NOT_RECEIVABLE` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:561` |
| `ITEM_REQUIRED` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:59` |
| `ITEM_SKU_MISMATCH` | 3 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineItemIdentityController.php:48` |
| `LABEL_NOT_PRINTED` | 3 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:418` |
| `LABEL_PII_FORBIDDEN` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php:377` |
| `MASTER_NOT_ACTIVE` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineSkuController.php:149` |
| `NO_REVERSIBLE_MOVEMENT` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:247` |
| `NO_STOCK_DOCUMENT` | 1 | `Modules/Warehouse/app/Http/Controllers/ReversalDocumentController.php:395` |
| `NO_UPDATABLE_FIELDS` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php:439` |
| `ORIGINAL_MOVEMENT_INVALID` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:128` |
| `OUTBOUND_RECIPIENT_REQUIRED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineImportController.php:252` |
| `OUTBOUND_REQUEST_EMPTY` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php:470` |
| `OUTBOUND_REQUEST_MULTIPLE_RECIPIENTS` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineImportController.php:258` |
| `OUTBOUND_REQUEST_NOT_ACTIVE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php:462` |
| `OUTBOUND_REQUEST_REQUIRED` | 4 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:43` |
| `PACKAGE_VARIANT_NOT_ACTIVE` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineSkuController.php:122` |
| `PACKING_LABEL_NOT_APPLIED` | 1 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:480` |
| `PACKING_LABEL_REQUIRED` | 1 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:474` |
| `PARENT_CONTAINER_NOT_FOUND` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:396` |
| `PHYSICAL_CODE_ITEM_MISMATCH` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:510` |
| `PHYSICAL_CODE_TARGET_MISMATCH` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php:350` |
| `PHYSICAL_CODE_TARGET_XOR` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePhysicalCodeController.php:34` |
| `PHYSICAL_CODE_UNASSIGNED` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:507` |
| `PHYSICAL_RETURN_CONFIRMATION_REQUIRED` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:222` |
| `PII_EXPORT_REASON_INVALID` | 1 | `Modules/Warehouse/app/Http/Controllers/AlignedWarrantyReportController.php:56` |
| `PRIMARY_PRODUCT_MISMATCH` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:540` |
| `PRIMARY_SKU_REQUIRED` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerController.php:46` |
| `PRODUCT_ITEM_MATCH_REQUIRED` | 2 | `Modules/Warehouse/app/Services/AlignedOutboundStockService.php:137` |
| `PRODUCT_NOT_ACTIVE` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:520` |
| `PRODUCT_REQUIRED` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:514` |
| `PRODUCT_SKU_REQUIRED` | 4 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:55` |
| `PUTAWAY_BOX_REQUIRED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php:50` |
| `PUTAWAY_MUST_USE_TASK_FLOW` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerController.php:61` |
| `QUANTITY_MODE_ITEM_FORBIDDEN` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:166` |
| `QUANTITY_REQUIRED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php:218` |
| `REPORT_EXPORT_ROW_LIMIT_EXCEEDED` | 5 | `Modules/Warehouse/app/Http/Controllers/AlignedImportMatchReportController.php:514` |
| `RETURN_CONDITION_REQUIRED` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:603` |
| `REVERSAL_ITEM_DUPLICATED` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:562` |
| `REVERSAL_ITEM_REQUIRED` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:158` |
| `REVERSAL_LINES_REQUIRED` | 3 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:84` |
| `REVERSAL_QTY_EXCEEDED` | 2 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:143` |
| `RFID_HARDWARE_UID_REQUIRED` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineItemIdentityController.php:31` |
| `SCAN_EXCEEDS_REQUIRED` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php:150` |
| `SCAN_ITEM_MISMATCH` | 1 | `Modules/Warehouse/app/Services/StockDocumentService.php:327` |
| `SCAN_SKU_MISMATCH` | 2 | `Modules/Warehouse/app/Services/StockDocumentService.php:330` |
| `SERIAL_COMPONENT_REQUIRES_ITEM` | 1 | `Modules/Warehouse/app/Services/ComponentContainerSubledgerService.php:29` |
| `SERIAL_PRODUCT_MISMATCH` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:282` |
| `SERIAL_QUANTITY_MUST_BE_ONE` | 4 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php:155` |
| `SERIAL_SCAN_QUANTITY_INVALID` | 1 | `Modules/Warehouse/app/Services/StockDocumentService.php:322` |
| `SERIAL_SCAN_REQUIRED` | 1 | `Modules/Warehouse/app/Services/StockDocumentService.php:319` |
| `SERIAL_WAREHOUSE_MISMATCH` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:274` |
| `SKU_CODE_REQUIRED` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:401` |
| `SKU_COMPONENT_CYCLE` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:266` |
| `SKU_COMPONENT_SELF_REFERENCE` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:227` |
| `SKU_IMPORT_FILE_INVALID` | 1 | `Modules/Warehouse/app/Http/Controllers/ImportController.php:290` |
| `SKU_IMPORT_ROW_LIMIT_EXCEEDED` | 1 | `Modules/Warehouse/app/Http/Controllers/ImportController.php:219` |
| `SKU_NAME_REQUIRED` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:411` |
| `SKU_NOT_ACTIVE` | 3 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php:490` |
| `SKU_PUBLISH_REQUIRES_ACTIVE` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineSkuController.php:203` |
| `TEMP_ONLY_EVIDENCE_REQUIRED` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php:246` |
| `TRAY_NOT_USABLE` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePutawayTaskController.php:130` |
| `TRAY_REQUIRED` | 3 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php:352` |
| `UNKNOWN_MASTER_TYPE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineMasterController.php:195` |
| `VALIDATION_ERROR` | 9 | `app/Repositories/BaseRepository.php:367` |
| `VALIDATION_FAILED` | 10 | `Modules/Warehouse/app/Http/Controllers/StockRequestController.php:286` |
| `WAREHOUSE_NOT_ACTIVE` | 3 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php:47` |
| `WAREHOUSE_REQUIRED` | 2 | `Modules/Warehouse/app/Services/AlignedOutboundStockService.php:64` |
| `WARRANTY_CASE_CODE_EXISTS` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyController.php:135` |
| `WARRANTY_CASE_NOT_ACTIVE` | 5 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php:51` |
| `WARRANTY_DEFECT_NOT_FOUND` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyController.php:533` |
| `WARRANTY_DIAGNOSIS_REQUIRED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyController.php:354` |
| `WARRANTY_HANDOVER_NOTE_REQUIRED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyController.php:375` |
| `WARRANTY_ITEM_NOT_ELIGIBLE` | 4 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php:236` |
| `WARRANTY_ITEM_NOT_FOUND` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyController.php:486` |
| `WARRANTY_TREATMENT_RESULT_REQUIRED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyController.php:365` |

### HTTP 428

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `PRECONDITION_REQUIRED` | 21 | `app/Support/IdempotencyKeyGuard.php:25` |

### HTTP 429

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `TOO_MANY_REQUESTS` | 1 | `app/Traits/ApiResponse.php (default map)` |

### HTTP 500

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `INTERNAL_SERVER_ERROR` | 2 | `bootstrap/app.php:144` |
| `OUTBOUND_MATCH_MISSING` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php:395` |

### HTTP 503

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `HEALTH_CHECK_DEGRADED` | 2 | `app/Http/Controllers/HealthController.php:113` |
| `SERVICE_UNAVAILABLE` | 1 | `app/Traits/ApiResponse.php (default map)` |
| `SKU_IMPORT_REPORT_PERSISTENCE_FAILED` | 1 | `Modules/Warehouse/app/Http/Controllers/ImportController.php:274` |

## 4. Quy tắc dùng cho FE/QA

1. Bắt lỗi theo `error_code`, không bắt theo `message` (message có thể đổi từ ngữ).
2. `412 STALE_VERSION` và `428 PRECONDITION_REQUIRED` thuộc cơ chế optimistic locking `If-Match`: tải lại bản ghi rồi thử lại, không retry mù.
3. `409` là xung đột trạng thái/đồng thời: hiển thị thông điệp và cho người dùng làm mới, không tự động retry vì có thể trùng nghiệp vụ.
4. `422` gắn `errors` theo field: map thẳng vào form.
5. `403` có hai nhánh: thiếu permission (`ACCESS_DENIED`/`PERMISSION_DENIED`) và ngoài phạm vi dữ liệu (`WAREHOUSE_SCOPE_DENIED`/`DATA_SCOPE_DENIED`).
6. Mã mới phải được thêm trong code trước, chạy lại generator, rồi mới dùng ở FE.
