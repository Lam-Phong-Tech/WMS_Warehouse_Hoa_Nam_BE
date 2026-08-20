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

## 3. Danh mục error code (255 mã)

### HTTP 400

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `BAD_REQUEST` | 1 | `app/Traits/ApiResponse.php (default map)` |
| `INVALID_EXCEL_FILE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php:801` |
| `INVALID_HEADER` | 2 | `Modules/Warehouse/app/Http/Controllers/ReversalDocumentController.php:251` |
| `INVALID_PATH` | 1 | `Modules/Warehouse/app/Http/Controllers/PvPublicCatalogController.php:99` |
| `INVALID_QUERY` | 7 | `Modules/Warehouse/app/Http/Controllers/PvPublicCatalogController.php:25` |
| `MALFORMED_JSON` | 1 | `app/Http/Middleware/ApiConventionMiddleware.php:34` |

### HTTP 401

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `UNAUTHENTICATED` | 19 | `Modules/Auth/app/Http/Controllers/AuthController.php:100` |

### HTTP 403

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `ACCESS_DENIED` | 7 | `Modules/Auth/app/Http/Controllers/AuthController.php:122` |
| `DATA_SCOPE_DENIED` | 4 | `Modules/Warehouse/app/Http/Middleware/EnforceComponentIssueWarehouseScope.php:81` |
| `PERMISSION_DENIED` | 3 | `Modules/Warehouse/app/Http/Controllers/AlignedWarrantyReportController.php:57` |
| `WAREHOUSE_SCOPE_DENIED` | 1 | `Modules/Warehouse/app/Http/Controllers/ScanController.php:154` |

### HTTP 404

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `COMPONENT_LINE_NOT_FOUND` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php:192` |
| `CONTAINER_ITEM_NOT_ACTIVE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php:454` |
| `CONTAINER_NOT_FOUND` | 4 | `Modules/Warehouse/app/Services/ContainerService.php:75` |
| `DOCUMENT_LINE_NOT_FOUND` | 4 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:82` |
| `INBOUND_SCAN_ENTRY_NOT_FOUND` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php:161` |
| `LABEL_NOT_FOUND` | 3 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:410` |
| `MOVEMENT_NOT_FOUND` | 1 | `Modules/Warehouse/app/Http/Controllers/ReversalDocumentController.php:453` |
| `OUTBOUND_MATCH_NOT_FOUND` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php:249` |
| `PHYSICAL_CODE_NOT_FOUND` | 6 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php:421` |
| `PUTAWAY_NOT_FOUND` | 2 | `Modules/Warehouse/app/Http/Controllers/PutawayController.php:246` |
| `RESOURCE_NOT_FOUND` | 43 | `app/Traits/ApiResponse.php:104` |
| `REVERSAL_NOT_FOUND` | 2 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:286` |
| `SCAN_CODE_NOT_FOUND` | 3 | `Modules/Warehouse/app/Services/ScanClassificationService.php:66` |
| `SKU_NOT_FOUND` | 7 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineItemIdentityController.php:73` |
| `UNKNOWN_CODE` | 3 | `Modules/Warehouse/app/Http/Controllers/ScanController.php:200` |
| `WAREHOUSE_NOT_FOUND` | 1 | `Modules/Warehouse/app/Http/Controllers/ScanController.php:161` |

### HTTP 405

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `METHOD_NOT_ALLOWED` | 2 | `bootstrap/app.php:83` |

### HTTP 409

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `ACTIVE_WARRANTY_EXISTS` | 3 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php:295` |
| `AGENCY_CODE_IMMUTABLE_AFTER_USE` | 1 | `Modules/Warehouse/app/Http/Controllers/AgencyController.php:211` |
| `ALREADY_FULLY_REVERSED` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:602` |
| `BOX_IN_OTHER_ACTIVE_RECEIPT` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineInboundBoxController.php:160` |
| `BOX_NOT_PUTAWAY_ELIGIBLE` | 4 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePutawayTaskController.php:141` |
| `BOX_NOT_RECEIVABLE` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineInboundBoxController.php:142` |
| `CONCURRENCY_CONFLICT` | 19 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerController.php:74` |
| `CONTAINER_CLOSED` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php:230` |
| `CONTAINER_CODE_DUPLICATE` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:554` |
| `CONTAINER_IMMUTABLE` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:80` |
| `CONTAINER_NOT_EDITABLE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php:338` |
| `CONTAINER_STATE_CONFLICT` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:158` |
| `CONTAINER_SUBLEDGER_CHANGED` | 1 | `Modules/Warehouse/app/Services/ComponentContainerSubledgerService.php:131` |
| `DEFECT_CODE_IMMUTABLE_AFTER_USE` | 1 | `Modules/Warehouse/app/Http/Controllers/DefectCatalogController.php:243` |
| `DOWNSTREAM_DEPENDENCY_EXISTS` | 7 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:661` |
| `DUPLICATE_CODE_VALUE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePhysicalCodeController.php:172` |
| `DUPLICATE_COMPONENT_ITEM` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php:224` |
| `DUPLICATE_HARDWARE_UID` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePhysicalCodeController.php:162` |
| `DUPLICATE_ITEM_IN_DOCUMENT` | 3 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:762` |
| `DUPLICATE_ITEM_MATCH` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:908` |
| `DUPLICATE_OUTBOUND_REQUEST_NO` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineImportController.php:366` |
| `DUPLICATE_STOCK_EFFECT` | 2 | `Modules/Warehouse/app/Services/AlignedOutboundStockService.php:159` |
| `IDEMPOTENCY_CONFLICT` | 1 | `app/Http/Middleware/ApiConventionMiddleware.php:192` |
| `IMPORT_HAS_BLOCKING_ERRORS` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineImportController.php:309` |
| `INVALID_WARRANTY_TRANSITION` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php:372` |
| `ITEM_ALREADY_IN_CONTAINER` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php:356` |
| `ITEM_ALREADY_MATCHED` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:816` |
| `ITEM_ALREADY_RECEIVING` | 2 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:356` |
| `ITEM_ALREADY_REGISTERED` | 2 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:312` |
| `LABEL_SNAPSHOT_STALE` | 1 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:562` |
| `LABEL_STATE_CONFLICT` | 2 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:421` |
| `LEGACY_ITEM_LABEL_NOT_APPLICABLE` | 1 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:492` |
| `MASTER_CODE_IMMUTABLE_AFTER_USE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineMasterController.php:579` |
| `MATCH_NOT_ACTIVE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php:260` |
| `OUTBOUND_REQUEST_ALREADY_USED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php:492` |
| `PACKING_LABEL_ALREADY_CREATED` | 1 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:176` |
| `PARENT_CONTAINER_NOT_AVAILABLE` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:399` |
| `PHYSICAL_CODE_ALREADY_ASSIGNED` | 4 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php:123` |
| `PHYSICAL_CODE_IN_OPEN_OUTBOUND` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePhysicalCodeController.php:256` |
| `PHYSICAL_CODE_NOT_ACTIVE` | 4 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerController.php:52` |
| `PHYSICAL_RETURN_REQUIRED` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:328` |
| `PRINT_FAILED` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php:1086` |
| `PUTAWAY_DRAFT_EXISTS` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePutawayTaskController.php:81` |
| `REFERENCE_EXISTS` | 1 | `Modules/Auth/app/Http/Controllers/RoleController.php:196` |
| `REVERSAL_ITEM_ALREADY_REVERSED` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:697` |
| `REVERSAL_NOT_DRAFT` | 4 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:325` |
| `REVERSAL_NOT_PENDING_RETURN` | 2 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:290` |
| `REVERSAL_OF_REVERSAL_FORBIDDEN` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:348` |
| `REVERSAL_POSTED_IMMUTABLE` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:826` |
| `SCAN_CODE_INACTIVE` | 2 | `Modules/Warehouse/app/Services/CanonicalScanClassificationService.php:97` |
| `SCAN_CODE_UNASSIGNED` | 1 | `Modules/Warehouse/app/Services/ScanResolver.php:50` |
| `SCAN_CONTAINER_INACTIVE` | 2 | `Modules/Warehouse/app/Services/ScanClassificationService.php:276` |
| `SCAN_CONTEXT_CONFLICT` | 1 | `Modules/Warehouse/app/Services/ScanClassificationService.php:414` |
| `SCAN_ENTRY_NOT_ACTIVE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php:172` |
| `SCAN_ITEM_ALREADY_RECEIVED` | 1 | `Modules/Warehouse/app/Services/StockDocumentService.php:332` |
| `SCAN_LOCATION_UNKNOWN` | 1 | `Modules/Warehouse/app/Services/ScanClassificationService.php:369` |
| `SCAN_SKU_INACTIVE` | 1 | `Modules/Warehouse/app/Services/CanonicalScanClassificationService.php:42` |
| `SCAN_WAREHOUSE_MISMATCH` | 1 | `Modules/Warehouse/app/Services/ScanClassificationService.php:392` |
| `SCANNED_DOCUMENT_IMMUTABLE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php:301` |
| `SCANNED_LINE_STRUCTURE_IMMUTABLE` | 4 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php:372` |
| `SELF_ESCALATION_DENIED` | 1 | `Modules/Auth/app/Http/Middleware/PreventSelfRoleAssignment.php:27` |
| `SERIAL_ALREADY_CONTAINERIZED` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:298` |
| `SERIAL_NOT_CONTAINERIZABLE` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:265` |
| `SKU_CODE_DUPLICATE` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:437` |
| `SKU_IDENTITY_IMMUTABLE_AFTER_STOCK` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineSkuController.php:299` |
| `SKU_VERSION_CONFLICT` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:465` |
| `SOURCE_KEY_CONFLICT` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineImportController.php:161` |
| `SOURCE_REQUEST_IMMUTABLE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php:290` |
| `STATE_CONFLICT` | 40 | `Modules/Warehouse/app/Exceptions/WarehouseException.php:18` |
| `SYSTEM_ROLE_IMMUTABLE` | 1 | `Modules/Auth/app/Http/Middleware/EnsureMutableRole.php:28` |
| `UNIQUE_CONSTRAINT_CONFLICT` | 1 | `bootstrap/app.php:123` |
| `WAREHOUSE_INACTIVE` | 1 | `Modules/Warehouse/app/Http/Controllers/ScanController.php:164` |

### HTTP 412

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `STALE_VERSION` | 23 | `Modules/Auth/app/Http/Controllers/UserController.php:214` |

### HTTP 422

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `PHYSICAL_CODE_NOT_FOUND` | 6 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php:421` |
| `SKU_NOT_FOUND` | 7 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineItemIdentityController.php:73` |
| `UNKNOWN_CODE` | 3 | `Modules/Warehouse/app/Http/Controllers/ScanController.php:200` |
| `PHYSICAL_CODE_NOT_ACTIVE` | 4 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerController.php:52` |
| `ACTIVE_WAREHOUSE_BASELINE_VIOLATION` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineImportController.php:316` |
| `ATTACHMENT_CONTENT_TYPE_INVALID` | 1 | `app/Support/WarrantyAttachmentScanner.php:48` |
| `ATTACHMENT_CONTENT_TYPE_MISMATCH` | 1 | `app/Support/WarrantyAttachmentScanner.php:55` |
| `ATTACHMENT_LIMIT_EXCEEDED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyAttachmentController.php:93` |
| `ATTACHMENT_MALWARE_DETECTED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyAttachmentController.php:110` |
| `BOX_CODE_CONFLICT` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineInboundBoxController.php:136` |
| `BOX_REQUIRED` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePutawayTaskController.php:136` |
| `BOX_SKU_MISMATCH` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineInboundBoxController.php:139` |
| `BRAND_NOT_ACTIVE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineMasterController.php:192` |
| `BUSINESS_RULE_ERROR` | 3 | `Modules/Warehouse/app/Http/Controllers/OperationalReportController.php:1633` |
| `BUSINESS_RULE_VIOLATION` | 8 | `Modules/Warehouse/app/Http/Controllers/PvAdminResourceController.php:455` |
| `CANCEL_REASON_REQUIRED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyController.php:407` |
| `COMPONENT_BOX_INSUFFICIENT` | 1 | `Modules/Warehouse/app/Services/ComponentContainerSubledgerService.php:38` |
| `COMPONENT_BOX_INVALID` | 1 | `Modules/Warehouse/app/Services/ComponentContainerSubledgerService.php:24` |
| `COMPONENT_BOX_NOT_FOUND` | 1 | `Modules/Warehouse/app/Http/Middleware/EnsureInboundComponentBox.php:48` |
| `COMPONENT_BOX_REQUIRED` | 2 | `Modules/Warehouse/app/Http/Middleware/EnsureInboundComponentBox.php:39` |
| `COMPONENT_CANNOT_HAVE_CHILDREN` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:211` |
| `COMPONENT_ITEM_NOT_AVAILABLE` | 3 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php:216` |
| `COMPONENT_ITEM_TARGET_REQUIRED` | 1 | `Modules/Warehouse/app/Services/ComponentContainerSubledgerService.php:68` |
| `COMPONENT_NOT_ACTIVE` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:238` |
| `COMPONENT_PURPOSE_INVALID` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:665` |
| `COMPONENT_SKU_REQUIRED` | 5 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php:107` |
| `COMPONENT_TARGET_XOR` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php:201` |
| `CONTAINER_CYCLE` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePutawayTaskController.php:166` |
| `CONTAINER_ITEM_TARGET_REQUIRED` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:254` |
| `CONTAINER_ITEM_XOR` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php:344` |
| `CONTAINER_PARENT_CYCLE` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:412` |
| `CONTAINER_PRIMARY_SKU_MISMATCH` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php:366` |
| `CONTAINER_SELF_PARENT` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:387` |
| `CONTAINER_STATUS_LIFECYCLE_MANAGED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php:252` |
| `CONTAINER_STATUS_NOT_CREATABLE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php:532` |
| `CONTAINER_WAREHOUSE_MISMATCH` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:404` |
| `DEACTIVATION_REASON_REQUIRED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePhysicalCodeController.php:240` |
| `DOCUMENT_HAS_NO_LINES` | 4 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php:210` |
| `DUPLICATE_COMPONENT` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:230` |
| `DUPLICATE_CONTAINER_ITEM` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:316` |
| `DUPLICATE_PHYSICAL_CODE` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:291` |
| `EMPTY_CONTAINER_CANNOT_SEAL` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:173` |
| `EMPTY_EXCEL_FILE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php:805` |
| `EMPTY_UPDATE` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineMasterController.php:566` |
| `EXPECTED_QTY_BELOW_SCANNED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php:407` |
| `FULL_POST_REQUIRED` | 6 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:546` |
| `FULL_SCAN_REQUIRED_FOR_LABEL` | 1 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:248` |
| `IMPORT_HAS_NO_VALID_ROWS` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineImportController.php:327` |
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
| `INVALID_EXCEL_TEMPLATE` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php:812` |
| `INVALID_PAYLOAD` | 4 | `Modules/Warehouse/app/Http/Controllers/ScanController.php:183` |
| `INVALID_PHYSICAL_CODE` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:374` |
| `INVALID_PHYSICAL_CODE_STATUS` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:383` |
| `INVALID_PHYSICAL_CODE_TYPE` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:377` |
| `INVALID_QUANTITY` | 5 | `Modules/Warehouse/app/Services/AlignedOutboundStockService.php:156` |
| `INVALID_RETURN_CONDITION` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:495` |
| `INVALID_SKU_CODE` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:397` |
| `INVALID_SKU_NAME` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:407` |
| `INVALID_UUID` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:578` |
| `INVALID_VERSION` | 22 | `Modules/Auth/app/Http/Controllers/UserController.php:211` |
| `INVENTORY_SERIAL_NOT_FOUND` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:260` |
| `ITEM_NOT_AVAILABLE` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:802` |
| `ITEM_NOT_FOUND` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php:352` |
| `ITEM_NOT_IN_ORIGINAL_TRANSACTION` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:609` |
| `ITEM_NOT_MATCHED_IN_DOCUMENT` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePhysicalCodeController.php:151` |
| `ITEM_NOT_RECEIVABLE` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:833` |
| `ITEM_REQUIRED` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:63` |
| `ITEM_SKU_MISMATCH` | 3 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineItemIdentityController.php:77` |
| `LABEL_NOT_PRINTED` | 3 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:495` |
| `LABEL_PII_FORBIDDEN` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php:429` |
| `LEGACY_CODE_DISABLED` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:282` |
| `MASTER_NOT_ACTIVE` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineSkuController.php:268` |
| `NO_REVERSIBLE_MOVEMENT` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:509` |
| `NO_STOCK_DOCUMENT` | 1 | `Modules/Warehouse/app/Http/Controllers/ReversalDocumentController.php:483` |
| `NO_UPDATABLE_FIELDS` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php:543` |
| `ORIGINAL_MOVEMENT_INVALID` | 2 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:176` |
| `OUTBOUND_RECIPIENT_REQUIRED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineImportController.php:348` |
| `OUTBOUND_REQUEST_EMPTY` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php:497` |
| `OUTBOUND_REQUEST_MULTIPLE_RECIPIENTS` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineImportController.php:354` |
| `OUTBOUND_REQUEST_NOT_ACTIVE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php:489` |
| `OUTBOUND_REQUEST_REQUIRED` | 4 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:70` |
| `PACKAGE_VARIANT_NOT_ACTIVE` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineSkuController.php:241` |
| `PACKING_LABEL_NOT_APPLIED` | 1 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:557` |
| `PACKING_LABEL_REQUIRED` | 1 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:551` |
| `PARENT_CONTAINER_NOT_FOUND` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:396` |
| `PHYSICAL_CODE_ITEM_MISMATCH` | 2 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:293` |
| `PHYSICAL_CODE_TARGET_MISMATCH` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php:427` |
| `PHYSICAL_CODE_TARGET_XOR` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePhysicalCodeController.php:35` |
| `PHYSICAL_CODE_UNASSIGNED` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:779` |
| `PHYSICAL_RETURN_CONFIRMATION_REQUIRED` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:281` |
| `PII_EXPORT_REASON_INVALID` | 2 | `Modules/Warehouse/app/Http/Controllers/AlignedWarrantyReportController.php:62` |
| `PRIMARY_PRODUCT_MISMATCH` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:540` |
| `PRIMARY_SKU_REQUIRED` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerController.php:46` |
| `PRODUCT_ITEM_MATCH_REQUIRED` | 2 | `Modules/Warehouse/app/Services/AlignedOutboundStockService.php:137` |
| `PRODUCT_NOT_ACTIVE` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:520` |
| `PRODUCT_REQUIRED` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:514` |
| `PRODUCT_SKU_REQUIRED` | 5 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:86` |
| `PUTAWAY_MUST_USE_TASK_FLOW` | 3 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerController.php:61` |
| `QUANTITY_MODE_ITEM_FORBIDDEN` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:214` |
| `QUANTITY_REQUIRED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php:360` |
| `REPORT_EXPORT_ROW_LIMIT_EXCEEDED` | 5 | `Modules/Warehouse/app/Http/Controllers/AlignedImportMatchReportController.php:515` |
| `RETURN_CONDITION_REQUIRED` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:731` |
| `REVERSAL_ITEM_DUPLICATED` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:690` |
| `REVERSAL_ITEM_REQUIRED` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:206` |
| `REVERSAL_LINES_REQUIRED` | 3 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:104` |
| `REVERSAL_QTY_EXCEEDED` | 2 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:191` |
| `RFID_HARDWARE_UID_REQUIRED` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineItemIdentityController.php:60` |
| `SCAN_EXCEEDS_REQUIRED` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php:207` |
| `SCAN_ITEM_MISMATCH` | 1 | `Modules/Warehouse/app/Services/StockDocumentService.php:326` |
| `SCAN_SKU_MISMATCH` | 2 | `Modules/Warehouse/app/Services/StockDocumentService.php:329` |
| `SERIAL_COMPONENT_REQUIRES_ITEM` | 1 | `Modules/Warehouse/app/Services/ComponentContainerSubledgerService.php:29` |
| `SERIAL_PRODUCT_MISMATCH` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:282` |
| `SERIAL_QUANTITY_MUST_BE_ONE` | 4 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php:212` |
| `SERIAL_SCAN_QUANTITY_INVALID` | 1 | `Modules/Warehouse/app/Services/StockDocumentService.php:321` |
| `SERIAL_SCAN_REQUIRED` | 1 | `Modules/Warehouse/app/Services/StockDocumentService.php:318` |
| `SERIAL_WAREHOUSE_MISMATCH` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:274` |
| `SKU_CODE_REQUIRED` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:401` |
| `SKU_COMPONENT_CYCLE` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:266` |
| `SKU_COMPONENT_SELF_REFERENCE` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:227` |
| `SKU_IMPORT_FILE_INVALID` | 1 | `Modules/Warehouse/app/Http/Controllers/ImportController.php:290` |
| `SKU_IMPORT_ROW_LIMIT_EXCEEDED` | 1 | `Modules/Warehouse/app/Http/Controllers/ImportController.php:219` |
| `SKU_INACTIVE` | 1 | `Modules/Warehouse/app/Services/ProductIdentityResolver.php:34` |
| `SKU_NAME_REQUIRED` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:411` |
| `SKU_NOT_ACTIVE` | 3 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php:517` |
| `SKU_PUBLISH_REQUIRES_ACTIVE` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineSkuController.php:322` |
| `TEMP_ONLY_EVIDENCE_REQUIRED` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php:298` |
| `TRAY_NOT_USABLE` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePutawayTaskController.php:156` |
| `TRAY_REQUIRED` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePutawayTaskController.php:152` |
| `UNKNOWN_MASTER_TYPE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineMasterController.php:526` |
| `UNSUPPORTED_PAYLOAD_VERSION` | 3 | `Modules/Warehouse/app/Http/Controllers/ScanController.php:178` |
| `VALIDATION_ERROR` | 10 | `app/Repositories/BaseRepository.php:446` |
| `VALIDATION_FAILED` | 10 | `Modules/Warehouse/app/Http/Controllers/StockRequestController.php:286` |
| `WAREHOUSE_NOT_ACTIVE` | 3 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php:80` |
| `WAREHOUSE_REQUIRED` | 2 | `Modules/Warehouse/app/Services/AlignedOutboundStockService.php:64` |
| `WARRANTY_CASE_CODE_EXISTS` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyController.php:170` |
| `WARRANTY_CASE_NOT_ACTIVE` | 5 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php:84` |
| `WARRANTY_DEFECT_NOT_FOUND` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyController.php:623` |
| `WARRANTY_DIAGNOSIS_REQUIRED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyController.php:414` |
| `WARRANTY_HANDOVER_NOTE_REQUIRED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyController.php:435` |
| `WARRANTY_ITEM_NOT_ELIGIBLE` | 4 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php:288` |
| `WARRANTY_ITEM_NOT_FOUND` | 4 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyController.php:546` |
| `WARRANTY_TREATMENT_RESULT_REQUIRED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyController.php:425` |
| `WRONG_SKU` | 5 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyController.php:576` |

### HTTP 428

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `PRECONDITION_REQUIRED` | 22 | `app/Support/IdempotencyKeyGuard.php:25` |

### HTTP 429

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `TOO_MANY_REQUESTS` | 1 | `app/Traits/ApiResponse.php (default map)` |

### HTTP 500

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `INTERNAL_SERVER_ERROR` | 2 | `bootstrap/app.php:144` |

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
