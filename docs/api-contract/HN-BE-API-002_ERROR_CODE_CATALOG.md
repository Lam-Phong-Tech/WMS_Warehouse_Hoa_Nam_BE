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

## 3. Danh mục error code (293 mã)

### HTTP 400

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `BAD_REQUEST` | 1 | `app/Traits/ApiResponse.php (default map)` |
| `IDEMPOTENCY_KEY_INVALID` | 2 | `Modules/Warehouse/app/Http/Controllers/PublicApi/PublicQuoteRequestController.php:161` |
| `INVALID_CURSOR` | 2 | `Modules/Warehouse/app/Services/PublicCatalogReadModel.php:550` |
| `INVALID_DOMAIN` | 1 | `Modules/Warehouse/app/Http/Controllers/PublicApi/Concerns/ParsesPublicQuery.php:215` |
| `INVALID_EXCEL_FILE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php:853` |
| `INVALID_HEADER` | 2 | `Modules/Warehouse/app/Http/Controllers/ReversalDocumentController.php:303` |
| `INVALID_PATH` | 1 | `Modules/Warehouse/app/Http/Controllers/PvPublicCatalogController.php:99` |
| `INVALID_QUERY` | 9 | `app/Support/PublicApiEnvelope.php:131` |
| `INVALID_SORT` | 1 | `Modules/Warehouse/app/Http/Controllers/PublicApi/Concerns/ParsesPublicQuery.php:171` |
| `MALFORMED_JSON` | 1 | `app/Http/Middleware/ApiConventionMiddleware.php:59` |

### HTTP 401

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `UNAUTHENTICATED` | 12 | `Modules/Auth/app/Http/Controllers/AuthController.php:121` |

### HTTP 403

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `ACCESS_DENIED` | 7 | `Modules/Auth/app/Http/Controllers/AuthController.php:181` |
| `ACCOUNT_DISABLED` | 1 | `Modules/Auth/app/Http/Middleware/EnforceActiveAccountStatus.php:28` |
| `DATA_SCOPE_DENIED` | 5 | `Modules/Warehouse/app/Http/Controllers/DocumentAttachmentController.php:601` |
| `PASSWORD_CHANGE_REQUIRED` | 1 | `Modules/Auth/app/Http/Middleware/EnforcePasswordChangeRequired.php:33` |
| `PERMISSION_DELEGATION_DENIED` | 1 | `Modules/Auth/app/Support/PermissionDelegation.php:91` |
| `PERMISSION_DENIED` | 4 | `Modules/Warehouse/app/Http/Controllers/AlignedWarrantyReportController.php:57` |
| `SUPER_ADMIN_GRANT_DENIED` | 1 | `Modules/Auth/app/Http/Middleware/PreventSuperAdminRoleGrant.php:55` |
| `SUPER_ADMIN_TARGET_PROTECTED` | 1 | `Modules/Auth/app/Http/Middleware/ProtectSuperAdminTarget.php:44` |
| `WAREHOUSE_SCOPE_DENIED` | 1 | `Modules/Warehouse/app/Http/Controllers/ScanController.php:159` |

### HTTP 404

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `CARTON_NOT_FOUND` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php:275` |
| `COMPONENT_LINE_NOT_FOUND` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php:194` |
| `CONTAINER_ITEM_NOT_ACTIVE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php:456` |
| `CONTAINER_NOT_FOUND` | 4 | `Modules/Warehouse/app/Services/ContainerService.php:75` |
| `DOCUMENT_LINE_NOT_FOUND` | 6 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:88` |
| `INBOUND_SCAN_ENTRY_NOT_FOUND` | 4 | `Modules/Warehouse/app/Http/Controllers/BaM1InboundScanCorrectionController.php:51` |
| `LABEL_NOT_FOUND` | 5 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:447` |
| `MOVEMENT_NOT_FOUND` | 1 | `Modules/Warehouse/app/Http/Controllers/ReversalDocumentController.php:518` |
| `OUTBOUND_MATCH_NOT_FOUND` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php:352` |
| `PHYSICAL_CODE_NOT_FOUND` | 9 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php:443` |
| `PRODUCT_NOT_FOUND` | 1 | `app/Support/PublicApiError.php:44` |
| `PUTAWAY_NOT_FOUND` | 2 | `Modules/Warehouse/app/Http/Controllers/PutawayController.php:248` |
| `RESOURCE_NOT_FOUND` | 63 | `app/Support/PublicApiEnvelope.php:132` |
| `REVERSAL_NOT_FOUND` | 2 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:297` |
| `SCAN_CODE_NOT_FOUND` | 3 | `Modules/Warehouse/app/Services/ScanClassificationService.php:66` |
| `SKU_NOT_FOUND` | 12 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineItemIdentityController.php:76` |
| `UNKNOWN_CODE` | 3 | `Modules/Warehouse/app/Http/Controllers/ScanController.php:205` |
| `WAREHOUSE_NOT_FOUND` | 3 | `Modules/Warehouse/app/Http/Controllers/MiniAppInboundStagingController.php:530` |

### HTTP 405

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `METHOD_NOT_ALLOWED` | 3 | `app/Support/PublicApiEnvelope.php:133` |

### HTTP 409

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `ACTIVE_WARRANTY_EXISTS` | 3 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php:297` |
| `AGENCY_CODE_IMMUTABLE_AFTER_USE` | 1 | `Modules/Warehouse/app/Http/Controllers/AgencyController.php:211` |
| `ALREADY_FULLY_REVERSED` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:620` |
| `ATOMIC_OUTBOUND_SCAN_FAILED` | 1 | `Modules/Warehouse/app/Http/Controllers/MiniAppOutboundStagingController.php:207` |
| `ATOMIC_RECORD_INCOMPLETE` | 2 | `Modules/Warehouse/app/Http/Controllers/MiniAppInboundStagingController.php:235` |
| `BOX_IN_OTHER_ACTIVE_RECEIPT` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineInboundBoxController.php:162` |
| `BOX_NOT_PUTAWAY_ELIGIBLE` | 4 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePutawayTaskController.php:143` |
| `BOX_NOT_RECEIVABLE` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineInboundBoxController.php:144` |
| `CARTON_NOT_USABLE` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:897` |
| `CONCURRENCY_CONFLICT` | 19 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerController.php:76` |
| `CONTAINER_CLOSED` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php:232` |
| `CONTAINER_CODE_DUPLICATE` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:554` |
| `CONTAINER_IMMUTABLE` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:80` |
| `CONTAINER_NOT_EDITABLE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php:340` |
| `CONTAINER_STATE_CONFLICT` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:158` |
| `CONTAINER_SUBLEDGER_CHANGED` | 1 | `Modules/Warehouse/app/Services/ComponentContainerSubledgerService.php:134` |
| `DEFECT_CODE_IMMUTABLE_AFTER_USE` | 1 | `Modules/Warehouse/app/Http/Controllers/DefectCatalogController.php:243` |
| `DOWNSTREAM_DEPENDENCY_EXISTS` | 7 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:692` |
| `DUPLICATE_CODE_VALUE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePhysicalCodeController.php:213` |
| `DUPLICATE_COMPONENT_ITEM` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php:226` |
| `DUPLICATE_HARDWARE_UID` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePhysicalCodeController.php:203` |
| `DUPLICATE_ITEM_IN_BATCH` | 2 | `Modules/Warehouse/app/Http/Controllers/MiniAppInboundStagingController.php:322` |
| `DUPLICATE_ITEM_IN_DOCUMENT` | 4 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:835` |
| `DUPLICATE_ITEM_MATCH` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:996` |
| `DUPLICATE_OUTBOUND_REQUEST_NO` | 3 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineImportController.php:399` |
| `DUPLICATE_STOCK_EFFECT` | 2 | `Modules/Warehouse/app/Services/AlignedOutboundStockService.php:160` |
| `IDEMPOTENCY_CONFLICT` | 3 | `app/Http/Middleware/ApiConventionMiddleware.php:224` |
| `IMPORT_HAS_BLOCKING_ERRORS` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineImportController.php:342` |
| `INVALID_WARRANTY_TRANSITION` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php:374` |
| `ITEM_ALREADY_IN_CONTAINER` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php:358` |
| `ITEM_ALREADY_MATCHED` | 2 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:839` |
| `ITEM_ALREADY_RECEIVING` | 3 | `Modules/Warehouse/app/Http/Controllers/MiniAppInboundStagingController.php:565` |
| `ITEM_ALREADY_REGISTERED` | 4 | `Modules/Warehouse/app/Http/Controllers/MiniAppInboundStagingController.php:407` |
| `LABEL_STATE_CONFLICT` | 2 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:458` |
| `LEGACY_ITEM_LABEL_NOT_APPLICABLE` | 1 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:529` |
| `MASTER_CODE_IMMUTABLE_AFTER_USE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineMasterController.php:581` |
| `MATCH_NOT_ACTIVE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php:363` |
| `OUTBOUND_DOCUMENT_NOT_FOUND` | 1 | `Modules/Warehouse/app/Http/Controllers/MiniAppOutboundStagingController.php:242` |
| `OUTBOUND_DRAFT_CREATE_FAILED` | 2 | `Modules/Warehouse/app/Http/Controllers/MiniAppOutboundStagingController.php:180` |
| `OUTBOUND_REQUEST_ALREADY_USED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php:566` |
| `PACKING_LABEL_ALREADY_CREATED` | 1 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:182` |
| `PACKING_LABEL_NOT_CREATED` | 1 | `Modules/Warehouse/app/Http/Controllers/MiniAppOutboundStagingController.php:237` |
| `PARENT_CONTAINER_NOT_AVAILABLE` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:399` |
| `PHYSICAL_CODE_ALREADY_ASSIGNED` | 4 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php:125` |
| `PHYSICAL_CODE_IN_OPEN_OUTBOUND` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePhysicalCodeController.php:326` |
| `PHYSICAL_CODE_NOT_ACTIVE` | 4 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerController.php:54` |
| `PHYSICAL_RETURN_REQUIRED` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:344` |
| `PRINT_FAILED` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php:1148` |
| `PUTAWAY_DRAFT_EXISTS` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePutawayTaskController.php:83` |
| `REFERENCE_EXISTS` | 1 | `Modules/Auth/app/Http/Controllers/RoleController.php:198` |
| `REVERSAL_ITEM_ALREADY_REVERSED` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:728` |
| `REVERSAL_NOT_DRAFT` | 4 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:341` |
| `REVERSAL_NOT_PENDING_RETURN` | 2 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:301` |
| `REVERSAL_OF_REVERSAL_FORBIDDEN` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:372` |
| `REVERSAL_POSTED_IMMUTABLE` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:857` |
| `SCAN_CODE_INACTIVE` | 2 | `Modules/Warehouse/app/Services/CanonicalScanClassificationService.php:97` |
| `SCAN_CODE_UNASSIGNED` | 1 | `Modules/Warehouse/app/Services/ScanResolver.php:50` |
| `SCAN_CONTAINER_INACTIVE` | 2 | `Modules/Warehouse/app/Services/ScanClassificationService.php:276` |
| `SCAN_CONTEXT_CONFLICT` | 1 | `Modules/Warehouse/app/Services/ScanClassificationService.php:417` |
| `SCAN_ENTRY_NOT_ACTIVE` | 2 | `Modules/Warehouse/app/Http/Controllers/BaM1InboundScanCorrectionController.php:62` |
| `SCAN_ITEM_ALREADY_RECEIVED` | 1 | `Modules/Warehouse/app/Services/StockDocumentService.php:332` |
| `SCAN_LOCATION_UNKNOWN` | 1 | `Modules/Warehouse/app/Services/ScanClassificationService.php:372` |
| `SCAN_SKU_INACTIVE` | 1 | `Modules/Warehouse/app/Services/CanonicalScanClassificationService.php:42` |
| `SCAN_WAREHOUSE_MISMATCH` | 1 | `Modules/Warehouse/app/Services/ScanClassificationService.php:395` |
| `SCANNED_DOCUMENT_IMMUTABLE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php:364` |
| `SCANNED_LINE_STRUCTURE_IMMUTABLE` | 4 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php:435` |
| `SELF_ESCALATION_DENIED` | 1 | `Modules/Auth/app/Http/Middleware/PreventSelfRoleAssignment.php:27` |
| `SERIAL_ALREADY_CONTAINERIZED` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:298` |
| `SERIAL_NOT_CONTAINERIZABLE` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:265` |
| `SKU_CODE_DUPLICATE` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:437` |
| `SKU_IDENTITY_IMMUTABLE_AFTER_STOCK` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineSkuController.php:302` |
| `SKU_VERSION_CONFLICT` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:465` |
| `SOURCE_KEY_CONFLICT` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineImportController.php:194` |
| `SOURCE_REQUEST_IMMUTABLE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php:353` |
| `STATE_CONFLICT` | 50 | `Modules/Warehouse/app/Exceptions/WarehouseException.php:18` |
| `SYSTEM_ROLE_IMMUTABLE` | 1 | `Modules/Auth/app/Http/Middleware/EnsureMutableRole.php:28` |
| `UNIQUE_CONSTRAINT_CONFLICT` | 1 | `bootstrap/app.php:160` |
| `WAREHOUSE_INACTIVE` | 2 | `Modules/Warehouse/app/Http/Controllers/MiniAppInboundStagingController.php:533` |

### HTTP 412

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `STALE_VERSION` | 27 | `Modules/Auth/app/Http/Controllers/UserController.php:228` |

### HTTP 422

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `CARTON_NOT_FOUND` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php:275` |
| `PHYSICAL_CODE_NOT_FOUND` | 9 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php:443` |
| `SKU_NOT_FOUND` | 12 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineItemIdentityController.php:76` |
| `UNKNOWN_CODE` | 3 | `Modules/Warehouse/app/Http/Controllers/ScanController.php:205` |
| `WAREHOUSE_NOT_FOUND` | 3 | `Modules/Warehouse/app/Http/Controllers/MiniAppInboundStagingController.php:530` |
| `PHYSICAL_CODE_NOT_ACTIVE` | 4 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerController.php:54` |
| `WAREHOUSE_INACTIVE` | 2 | `Modules/Warehouse/app/Http/Controllers/MiniAppInboundStagingController.php:533` |
| `ACTIVE_WAREHOUSE_BASELINE_VIOLATION` | 3 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineImportController.php:349` |
| `AGENCY_CODE_IMMUTABLE` | 1 | `Modules/Warehouse/app/Http/Controllers/BaM1RecipientController.php:261` |
| `ATTACHMENT_CONTENT_TYPE_INVALID` | 5 | `app/Support/DocumentAttachmentScanner.php:44` |
| `ATTACHMENT_CONTENT_TYPE_MISMATCH` | 3 | `app/Support/DocumentAttachmentScanner.php:51` |
| `ATTACHMENT_LIMIT_EXCEEDED` | 3 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyAttachmentController.php:220` |
| `ATTACHMENT_MALWARE_DETECTED` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyAttachmentController.php:145` |
| `ATTACHMENT_TOO_LARGE` | 3 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyAttachmentController.php:209` |
| `ATTACHMENT_VIDEO_TOO_LONG` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyAttachmentController.php:247` |
| `BOX_CODE_CONFLICT` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineInboundBoxController.php:138` |
| `BOX_REQUIRED` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePutawayTaskController.php:138` |
| `BOX_SKU_MISMATCH` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineInboundBoxController.php:141` |
| `BRAND_NOT_ACTIVE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineMasterController.php:194` |
| `BUSINESS_RULE_ERROR` | 11 | `Modules/Auth/app/Http/Controllers/PasswordResetController.php:135` |
| `BUSINESS_RULE_VIOLATION` | 8 | `Modules/Warehouse/app/Http/Controllers/PvAdminResourceController.php:496` |
| `CANCEL_REASON_REQUIRED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyController.php:442` |
| `CARTON_NO_ACTIVE_SCANS` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php:297` |
| `CARTON_SKU_MISMATCH` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:914` |
| `CARTON_TYPE_INVALID` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php:280` |
| `COMPONENT_BOX_INSUFFICIENT` | 1 | `Modules/Warehouse/app/Services/ComponentContainerSubledgerService.php:41` |
| `COMPONENT_BOX_INVALID` | 1 | `Modules/Warehouse/app/Services/ComponentContainerSubledgerService.php:27` |
| `COMPONENT_BOX_NOT_FOUND` | 1 | `Modules/Warehouse/app/Http/Middleware/EnsureInboundComponentBox.php:48` |
| `COMPONENT_BOX_REQUIRED` | 2 | `Modules/Warehouse/app/Http/Middleware/EnsureInboundComponentBox.php:39` |
| `COMPONENT_CANNOT_HAVE_CHILDREN` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:211` |
| `COMPONENT_ITEM_NOT_AVAILABLE` | 3 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php:218` |
| `COMPONENT_ITEM_TARGET_REQUIRED` | 1 | `Modules/Warehouse/app/Services/ComponentContainerSubledgerService.php:71` |
| `COMPONENT_NOT_ACTIVE` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:238` |
| `COMPONENT_PURPOSE_INVALID` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:683` |
| `COMPONENT_SKU_REQUIRED` | 6 | `Modules/Warehouse/app/Http/Controllers/BaM1InboundComponentController.php:46` |
| `COMPONENT_TARGET_XOR` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php:203` |
| `CONSENT_REQUIRED` | 1 | `Modules/Warehouse/app/Http/Controllers/PublicApi/PublicQuoteRequestController.php:239` |
| `CONTAINER_CYCLE` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePutawayTaskController.php:168` |
| `CONTAINER_ITEM_TARGET_REQUIRED` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:254` |
| `CONTAINER_ITEM_XOR` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php:346` |
| `CONTAINER_PARENT_CYCLE` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:412` |
| `CONTAINER_PRIMARY_SKU_MISMATCH` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php:368` |
| `CONTAINER_SELF_PARENT` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:387` |
| `CONTAINER_STATUS_LIFECYCLE_MANAGED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php:254` |
| `CONTAINER_STATUS_NOT_CREATABLE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php:534` |
| `CONTAINER_WAREHOUSE_MISMATCH` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:404` |
| `DEACTIVATION_REASON_REQUIRED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePhysicalCodeController.php:310` |
| `DOCUMENT_HAS_NO_LINES` | 4 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php:270` |
| `DUPLICATE_COMPONENT` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:230` |
| `DUPLICATE_CONTAINER_ITEM` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:316` |
| `DUPLICATE_PHYSICAL_CODE` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:291` |
| `EMPTY_CONTAINER_CANNOT_SEAL` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:173` |
| `EMPTY_EXCEL_FILE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php:857` |
| `EMPTY_UPDATE` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineMasterController.php:568` |
| `EVIDENCE_REQUIRED` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:352` |
| `EXPECTED_QTY_BELOW_SCANNED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php:470` |
| `FULL_POST_REQUIRED` | 6 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:585` |
| `FULL_SCAN_REQUIRED_FOR_LABEL` | 1 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:257` |
| `IMPORT_HAS_NO_VALID_ROWS` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineImportController.php:360` |
| `INBOUND_SCAN_DRIVEN` | 1 | `Modules/Warehouse/app/Http/Controllers/BaM1InboundDocumentController.php:294` |
| `INBOUND_WAREHOUSE_NOT_CANONICAL` | 2 | `Modules/Warehouse/app/Http/Controllers/MiniAppInboundStagingController.php:536` |
| `INSUFFICIENT_AVAILABLE_STOCK` | 3 | `Modules/Warehouse/app/Services/AlignedOutboundStockService.php:180` |
| `INSUFFICIENT_STOCK` | 8 | `Modules/Warehouse/app/Exceptions/WarehouseException.php:12` |
| `INVALID_CARRIER_TYPE` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:380` |
| `INVALID_COMPONENT` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:224` |
| `INVALID_COMPONENT_QUANTITY` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:233` |
| `INVALID_CONTAINER_CODE` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:357` |
| `INVALID_CONTAINER_QUANTITY` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:308` |
| `INVALID_CONTAINER_STATUS` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:369` |
| `INVALID_CONTAINER_TYPE` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:362` |
| `INVALID_EXCEL_TEMPLATE` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php:864` |
| `INVALID_IDENTIFIER_FORMAT` | 1 | `bootstrap/app.php:179` |
| `INVALID_PAYLOAD` | 7 | `Modules/Warehouse/app/Http/Controllers/MiniAppInboundStagingController.php:384` |
| `INVALID_PAYLOAD_ENCODING` | 1 | `app/Http/Middleware/ApiConventionMiddleware.php:41` |
| `INVALID_PHYSICAL_CODE` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:374` |
| `INVALID_PHYSICAL_CODE_STATUS` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:383` |
| `INVALID_PHYSICAL_CODE_TYPE` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:377` |
| `INVALID_QUANTITY` | 6 | `Modules/Warehouse/app/Services/AlignedOutboundStockService.php:157` |
| `INVALID_RETURN_CONDITION` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:513` |
| `INVALID_SKU_CODE` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:397` |
| `INVALID_SKU_NAME` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:407` |
| `INVALID_UUID` | 2 | `Modules/Warehouse/app/Http/Controllers/ImportReportController.php:64` |
| `INVALID_VERSION` | 24 | `Modules/Auth/app/Http/Controllers/UserController.php:225` |
| `INVENTORY_SERIAL_NOT_FOUND` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:260` |
| `ITEM_NOT_AVAILABLE` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:820` |
| `ITEM_NOT_FOUND` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php:354` |
| `ITEM_NOT_IN_ORIGINAL_TRANSACTION` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:640` |
| `ITEM_NOT_MATCHED_IN_DOCUMENT` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePhysicalCodeController.php:192` |
| `ITEM_NOT_RECEIVABLE` | 1 | `Modules/Warehouse/app/Http/Controllers/MiniAppInboundStagingController.php:545` |
| `ITEM_REQUIRED` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:71` |
| `ITEM_SKU_MISMATCH` | 3 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineItemIdentityController.php:80` |
| `LABEL_NOT_PRINTED` | 2 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:532` |
| `LABEL_PII_FORBIDDEN` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php:436` |
| `LEGACY_CODE_DISABLED` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:299` |
| `MASTER_NOT_ACTIVE` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineSkuController.php:271` |
| `MINI_APP_COMPONENT_FLOW_REQUIRES_BOX` | 1 | `Modules/Warehouse/app/Http/Controllers/MiniAppInboundStagingController.php:311` |
| `NO_REVERSIBLE_MOVEMENT` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:527` |
| `NO_STOCK_DOCUMENT` | 1 | `Modules/Warehouse/app/Http/Controllers/ReversalDocumentController.php:548` |
| `NO_UPDATABLE_FIELDS` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php:550` |
| `ORIGINAL_MOVEMENT_INVALID` | 2 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:187` |
| `OUTBOUND_EXPECTED_QTY_MISMATCH` | 1 | `Modules/Warehouse/app/Http/Controllers/MiniAppOutboundDraftController.php:131` |
| `OUTBOUND_RECIPIENT_REQUIRED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineImportController.php:381` |
| `OUTBOUND_REQUEST_EMPTY` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php:571` |
| `OUTBOUND_REQUEST_MULTIPLE_RECIPIENTS` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineImportController.php:387` |
| `OUTBOUND_REQUEST_NOT_ACTIVE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php:563` |
| `OUTBOUND_REQUEST_REQUIRED` | 4 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:76` |
| `PACKAGE_VARIANT_NOT_ACTIVE` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineSkuController.php:244` |
| `PARENT_CONTAINER_NOT_FOUND` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:396` |
| `PERMISSION_ALREADY_GRANTED` | 1 | `Modules/Auth/app/Http/Controllers/UserController.php:374` |
| `PHYSICAL_CODE_ITEM_MISMATCH` | 2 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:310` |
| `PHYSICAL_CODE_TARGET_MISMATCH` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php:449` |
| `PHYSICAL_CODE_TARGET_XOR` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePhysicalCodeController.php:64` |
| `PHYSICAL_CODE_UNASSIGNED` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php:797` |
| `PHYSICAL_RETURN_CONFIRMATION_REQUIRED` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:292` |
| `PII_EXPORT_REASON_INVALID` | 3 | `Modules/Warehouse/app/Http/Controllers/AlignedWarrantyReportController.php:62` |
| `PRIMARY_PRODUCT_MISMATCH` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:540` |
| `PRIMARY_SKU_REQUIRED` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerController.php:48` |
| `PRIVACY_VERSION_MISMATCH` | 1 | `Modules/Warehouse/app/Http/Controllers/PublicApi/PublicQuoteRequestController.php:251` |
| `PRODUCT_ITEM_MATCH_REQUIRED` | 2 | `Modules/Warehouse/app/Services/AlignedOutboundStockService.php:138` |
| `PRODUCT_NOT_ACTIVE` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:520` |
| `PRODUCT_REQUIRED` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:514` |
| `PRODUCT_SKU_REQUIRED` | 8 | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php:92` |
| `PUTAWAY_MUST_USE_TASK_FLOW` | 3 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerController.php:63` |
| `QUANTITY_MODE_ITEM_FORBIDDEN` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:225` |
| `QUANTITY_REQUIRED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php:362` |
| `REPORT_EXPORT_ROW_LIMIT_EXCEEDED` | 5 | `Modules/Warehouse/app/Http/Controllers/AlignedImportMatchReportController.php:570` |
| `RETURN_CONDITION_REQUIRED` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:762` |
| `REVERSAL_ITEM_DUPLICATED` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:721` |
| `REVERSAL_ITEM_REQUIRED` | 1 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:217` |
| `REVERSAL_LINES_REQUIRED` | 3 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:115` |
| `REVERSAL_QTY_EXCEEDED` | 2 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php:202` |
| `RFID_HARDWARE_UID_REQUIRED` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineItemIdentityController.php:63` |
| `SCAN_COUNT_MISMATCH` | 1 | `Modules/Warehouse/app/Http/Controllers/MiniAppOutboundStagingController.php:126` |
| `SCAN_EXCEEDS_REQUIRED` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php:209` |
| `SCAN_ITEM_MISMATCH` | 1 | `Modules/Warehouse/app/Services/StockDocumentService.php:326` |
| `SCAN_SKU_MISMATCH` | 2 | `Modules/Warehouse/app/Services/StockDocumentService.php:329` |
| `SERIAL_COMPONENT_REQUIRES_ITEM` | 1 | `Modules/Warehouse/app/Services/ComponentContainerSubledgerService.php:32` |
| `SERIAL_PRODUCT_MISMATCH` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:282` |
| `SERIAL_QUANTITY_MUST_BE_ONE` | 4 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php:214` |
| `SERIAL_SCAN_QUANTITY_INVALID` | 1 | `Modules/Warehouse/app/Services/StockDocumentService.php:321` |
| `SERIAL_SCAN_REQUIRED` | 1 | `Modules/Warehouse/app/Services/StockDocumentService.php:318` |
| `SERIAL_WAREHOUSE_MISMATCH` | 1 | `Modules/Warehouse/app/Services/ContainerService.php:274` |
| `SKU_CODE_REQUIRED` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:401` |
| `SKU_COMPONENT_CYCLE` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:266` |
| `SKU_COMPONENT_SELF_REFERENCE` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:227` |
| `SKU_IMPORT_FILE_INVALID` | 1 | `Modules/Warehouse/app/Http/Controllers/ImportController.php:322` |
| `SKU_IMPORT_ROW_LIMIT_EXCEEDED` | 1 | `Modules/Warehouse/app/Http/Controllers/ImportController.php:251` |
| `SKU_INACTIVE` | 4 | `Modules/Warehouse/app/Http/Controllers/MiniAppInboundStagingController.php:432` |
| `SKU_NAME_REQUIRED` | 1 | `Modules/Warehouse/app/Services/SkuAggregateService.php:411` |
| `SKU_NOT_ACTIVE` | 5 | `Modules/Warehouse/app/Http/Controllers/BaM1InboundComponentController.php:43` |
| `SKU_NOT_SERIAL_TRACKED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineItemIdentityController.php:203` |
| `SKU_PUBLISH_REQUIRES_ACTIVE` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineSkuController.php:325` |
| `TEMP_ONLY_EVIDENCE_REQUIRED` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php:300` |
| `TRAY_NOT_USABLE` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePutawayTaskController.php:158` |
| `TRAY_REQUIRED` | 2 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselinePutawayTaskController.php:154` |
| `UNKNOWN_MASTER_TYPE` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineMasterController.php:528` |
| `UNSUPPORTED_PAYLOAD_VERSION` | 5 | `Modules/Warehouse/app/Http/Controllers/MiniAppInboundStagingController.php:379` |
| `VALIDATION_ERROR` | 20 | `app/Repositories/BaseRepository.php:446` |
| `VALIDATION_FAILED` | 13 | `app/Support/PublicApiEnvelope.php:135` |
| `WAREHOUSE_NOT_ACTIVE` | 6 | `Modules/Warehouse/app/Http/Controllers/BaM1InboundDocumentController.php:308` |
| `WAREHOUSE_REQUIRED` | 2 | `Modules/Warehouse/app/Services/AlignedOutboundStockService.php:65` |
| `WARRANTY_CASE_CODE_EXISTS` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyController.php:184` |
| `WARRANTY_CASE_NOT_ACTIVE` | 5 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php:86` |
| `WARRANTY_DEFECT_NOT_FOUND` | 3 | `Modules/Warehouse/app/Http/Controllers/BaM1WarrantyController.php:93` |
| `WARRANTY_DIAGNOSIS_REQUIRED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyController.php:449` |
| `WARRANTY_HANDOVER_NOTE_REQUIRED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyController.php:470` |
| `WARRANTY_ITEM_NOT_ELIGIBLE` | 4 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php:290` |
| `WARRANTY_ITEM_NOT_FOUND` | 4 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyController.php:596` |
| `WARRANTY_TREATMENT_RESULT_REQUIRED` | 1 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyController.php:460` |
| `WRONG_SKU` | 7 | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyController.php:626` |

### HTTP 428

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `IDEMPOTENCY_KEY_REQUIRED` | 1 | `Modules/Warehouse/app/Http/Controllers/PublicApi/PublicQuoteRequestController.php:152` |
| `PRECONDITION_REQUIRED` | 25 | `app/Support/IdempotencyKeyGuard.php:25` |

### HTTP 429

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `RATE_LIMIT_EXCEEDED` | 1 | `app/Support/PublicApiEnvelope.php:136` |
| `TOO_MANY_REQUESTS` | 1 | `app/Traits/ApiResponse.php (default map)` |

### HTTP 500

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `INTERNAL_SERVER_ERROR` | 2 | `bootstrap/app.php:249` |
| `SCAN_EVIDENCE_NOT_FOUND` | 1 | `Modules/Warehouse/app/Http/Controllers/MiniAppInboundStagingController.php:621` |

### HTTP 503

| Error code | Số lần dùng | Vị trí đầu tiên |
| --- | --- | --- |
| `FEATURE_DISABLED` | 1 | `Modules/Warehouse/app/Http/Controllers/PublicApi/PublicQuoteRequestController.php:128` |
| `HEALTH_CHECK_DEGRADED` | 2 | `app/Http/Controllers/HealthController.php:113` |
| `SERVICE_UNAVAILABLE` | 3 | `app/Support/PublicApiEnvelope.php:137` |
| `SKU_IMPORT_REPORT_PERSISTENCE_FAILED` | 1 | `Modules/Warehouse/app/Http/Controllers/ImportController.php:306` |
| `SKU_IMPORT_TEMP_STORAGE_FAILED` | 1 | `Modules/Warehouse/app/Http/Controllers/ImportController.php:154` |
| `SKU_IMPORT_TEMPLATE_GENERATION_FAILED` | 1 | `Modules/Warehouse/app/Http/Controllers/ImportController.php:63` |

## 4. Quy tắc dùng cho FE/QA

1. Bắt lỗi theo `error_code`, không bắt theo `message` (message có thể đổi từ ngữ).
2. `412 STALE_VERSION` và `428 PRECONDITION_REQUIRED` thuộc cơ chế optimistic locking `If-Match`: tải lại bản ghi rồi thử lại, không retry mù.
3. `409` là xung đột trạng thái/đồng thời: hiển thị thông điệp và cho người dùng làm mới, không tự động retry vì có thể trùng nghiệp vụ.
4. `422` gắn `errors` theo field: map thẳng vào form.
5. `403` có hai nhánh: thiếu permission (`ACCESS_DENIED`/`PERMISSION_DENIED`) và ngoài phạm vi dữ liệu (`WAREHOUSE_SCOPE_DENIED`/`DATA_SCOPE_DENIED`).
6. Mã mới phải được thêm trong code trước, chạy lại generator, rồi mới dùng ở FE.
