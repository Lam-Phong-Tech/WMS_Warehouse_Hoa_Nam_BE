# WWHN-254 — Evidence pack báo cáo tích hợp (BE)

Đóng P0/P1/P2 EXACT FIX ACTION REGISTER của review 61/100.
Baseline hiệu lực: **SRS/FRD v1.12** (adopt tại WWHN-334), **ERD HoaNam_ERD_Moi_Nhat**,
**Wireframe MVP ALIGNED v3.20/v1.12 20260814**, **Techstack/Architecture Dev Guide**.

## 0. Định danh candidate

| Hạng mục | Giá trị |
| --- | --- |
| Nhánh | `m1` (không tạo nhánh riêng) |
| BE candidate SHA | `c855851b37920237d110f7dffe5dbbad509aebce` |
| BE evidence/doc SHA | điền sau commit docs kèm theo |
| FE candidate SHA | xem `docs/Task_docs_Jira/WWHN-254.md` bên repo FE |
| Gate exact-SHA | `.github/workflows/flow06-exact-sha.yml` |

**Cách sinh bằng chứng PASS tại đúng SHA đã pin** (đây chính là thứ trước đây không làm
được, nên candidate `bbc93d…` không bao giờ có run xanh):

```bash
gh workflow run flow06-exact-sha.yml --ref m1 -f candidate_sha=<EXACT_SHA>
```

Workflow checkout đúng SHA đó, tự so `git rev-parse HEAD` với SHA yêu cầu và **fail nếu
lệch**, rồi ghi `artifacts/flow06/manifest.txt` gồm `requested_sha`, `resolved_sha`,
`run_id`, `openapi_sha256`, `postman_set_sha256` + `SHA256SUMS`. Artifact này là bằng
chứng "exact SHA + green run + build/artifact + contract hash" mà register yêu cầu.

## 1. DEV254-P1-02 — vì sao run 32041944243 đỏ

Run đó **không** đỏ vì code. Log:

```
Failed to download action 'https://codeload.github.com/shivammathur/setup-php/…' 429 (Too Many Requests)
##[error]Response status code does not indicate success: 503 (Service Unavailable).
##[error]Failed to download archive … after 3 attempts.
```

Đây là flake hạ tầng ở bước setup. Đã sửa tận gốc bằng ba việc:

1. `workflow_dispatch` + input `candidate_sha` → chạy lại được tại đúng candidate.
2. Bước setup PHP retry 3 lần (`continue-on-error` cho 2 lần đầu).
3. Path filter mở rộng sang **toàn bộ surface báo cáo tích hợp**. Trước đây filter chỉ
   liệt kê `Aligned*` + 3 test; `OperationalReportController`, `ReportExportFileName`,
   `config/l5-swagger.php`, `routes/api.php`, `docs/postman/**` nằm ngoài filter — nên
   code báo cáo thay đổi mà gate không hề chạy lại. Đó chính là lý do register ghi
   "integrated report code đã thay đổi" so với candidate đã pin.

## 2. DEV254-P1-03 — defect thật đã tìm ra và đóng

**Data-scope kho không được áp cho báo cáo.** WWHN-328 chỉ áp scope cho
`/reports/dashboard-quick`. Mọi route `/reports/*` còn lại chỉ có
`permission:report.view|report.export`, không có middleware phạm vi kho, nên một tài
khoản bị giới hạn `scope_ref` vẫn đọc **và export** được tồn, XNT, lịch sử chứng từ,
movement và người nhận của toàn hệ thống.

- Vi phạm `AC-SRS-032` — "export dùng cùng filter; audit; **dữ liệu đúng scope**".
- Vi phạm `BR-EXP-01..05` — "export dùng cùng filter/scope với màn hình".
- Cùng lớp lỗi với `DEV328-P1-01` đã đóng cho dashboard, nhưng ở diện rộng hơn.

Đã đóng tại `OperationalReportController`:

| Điểm sửa | Nội dung |
| --- | --- |
| `reportWarehouseScope()` | đổi tên từ `dashboardWarehouseScope()`, dùng chung cho mọi báo cáo; nguồn scope là `User::warehouseScopeIds()` — cùng nguồn `scope_ref` với `/inventory/balances`, `/stock-movements` |
| `inventory-balance` | `whereIn(b.warehouse_id)` + `whereIn(m.warehouse_id)` trước mọi filter người dùng gửi |
| `inventory-xnt` (+ enhanced) | `whereIn(m.warehouse_id)` |
| `movement-trace` (+ `inventory-ledger`) | `whereIn(m.warehouse_id)`; aggregate summary là `clone $query` nên kế thừa |
| `document-history` | nhận dòng khi **một trong hai** đầu kho (`src`/`dst`) nằm trong scope |
| `outbound-by-agency` | `whereIn(sd.src_warehouse_id)` — đại lý là chiều người nhận, kho xuất mới là chiều scope |
| Cache | scope nằm trong cache key và được giải **trước** cache; nếu không, một cache hit của người dùng GLOBAL sẽ trả nguyên payload toàn hệ thống cho người bị giới hạn |
| Payload | thêm `data_scope` = `GLOBAL｜WAREHOUSE_SCOPED` và `scope_warehouse_ids` |
| 403 | `warehouse_id` ngoài scope → `403 ACCESS_DENIED`, đúng contract của `EnforceInventoryWarehouseScope`, kể cả trên route `*/export-excel` |

## 3. DEV254-P1-01 — phần máy kiểm được của rule no-Stocktake

Thẩm quyền: `REQ-SRS-037` (Must) + `AC-SRS-037` "deployment v1.12 không chứa capability
legacy", cùng `REQ-SRS-031` AC "không có stocktake report". `DEC-NO-STOCKTAKE-001`
**không** được trích như approved standalone Decision ID: SRS §3 ghi rõ "formal Decision:
WWHN-25 only", mà WWHN-25 vẫn mở. Xem lại đúng kết luận đã chốt ở WWHN-296.

Guard cũ (`Flow05InventoryControlAlignmentTest`) chỉ soi route / OpenAPI attribute /
permission / bảng DB. Bộ **contract artifact bàn giao** không được kiểm — và thực tế còn:

- `docs/postman/WWHN-131 Stocktake Contract.postman_collection.json` — collection đầy đủ
  session → snapshot → count → review → apply-adjustment;
- `docs/postman/WWHN-131 Stocktake CI.postman_environment.json`;
- `docs/postman/WWHN-150 Scan ReadOnly Contract.postman_collection.json` gửi
  `context: "STOCKTAKE"` — runtime nay chỉ nhận `INBOUND|OUTBOUND|INVENTORY|TRACE|
  WARRANTY_RECEIVE|WARRANTY_TEMP_ONLY` nên request đó trả 422.

Đã gỡ 2 artifact WWHN-131 và đổi `STOCKTAKE` → `INVENTORY` ở collection scan. Thêm test
`test_published_contract_artifacts_carry_no_stocktake_capability` quét `docs/postman` và
`docs/api-contract/releases`, chỉ bắt **surface thực thi** (`/stocktake`, `"stocktake`,
`'stocktake`, `{{stocktake`) chứ không bắt câu văn mô tả việc đã gỡ. Gate CI có bước
tương đương ở `flow06-exact-sha.yml`.

**Ngoại lệ cố ý ghi nhận:** `docs/api-contract/WWHN-150-scan-openapi.yaml` và
`docs/api-contract/WWHN-180-posting-openapi.{json,yaml}` ở thư mục gốc là bản ghi lịch sử
của WWHN-150/180 đã superseded, giữ để truy vết, **không** nằm trong bộ bàn giao hiện
hành. Nếu BA/Tech Lead muốn xoá hẳn thì đó là quyết định thuộc WWHN-150/180, không thuộc
WWHN-254.

## 4. Runbook cho QA1 (WWHN-255) — chạy độc lập trên candidate đã pin

```bash
git -C <BE_REPO> fetch --all && git -C <BE_REPO> checkout <EXACT_SHA>
composer install --no-interaction
php artisan migrate:fresh --seed --force
php artisan test --filter 'ReportFlow06|Flow06|Flow05InventoryControlAlignmentTest|Wwhn328DashboardQuickContractTest|Wwhn254ReportEvidenceTest'
```

Ánh xạ checkpoint QA1 ↔ test đã có (không phải làm tay lại):

| Checkpoint QA1 | Nơi kiểm |
| --- | --- |
| 200/403 theo permission | `ReportFlow06ContractTest::test_reports_require_authentication`, `::test_export_requires_report_export_permission` |
| **403 theo data-scope** | `Wwhn254ReportEvidenceTest::test_requesting_a_warehouse_outside_scope_is_denied_on_every_report` |
| filter / date | `ReportFlow06IntegrationTest` (keyword, doc_type, movement_type, agency, status) |
| **timezone** | `Wwhn254ReportEvidenceTest::test_period_boundary_is_inclusive_in_asia_ho_chi_minh` |
| công thức XNT | `ReportFlow06IntegrationTest::test_xnt_computes_opening_receipt_issue_closing`, `ReportFlow06ContractTest::test_xnt_uses_canonical_movement_semantics_and_exact_formula` |
| Balance↔Movement MISMATCH/MATCH | `::test_reconciliation_balance_vs_ledger_match`, `::test_reconciliation_detects_mismatch`, `ReportFlow06DocsAlignmentTest::test_as_of_returns_ledger_snapshot_without_false_mismatch` |
| import error rows | `ReportFlow06DocsAlignmentTest::test_import_match_reports_request_lines_with_required_matched_scanned`, `::test_import_match_filters_lines_by_sku_code` |
| **export scope** | `Wwhn254ReportEvidenceTest::test_export_applies_the_same_warehouse_scope_as_the_screen` |
| export audit / tên file | `ReportFlow06ContractTest::test_create_report_export_is_audited`, `ReportFlow06DocsAlignmentTest::test_every_export_file_name_carries_a_timestamp` |
| warranty privacy | `ReportFlow06DocsAlignmentTest::test_warranty_report_masks_customer_columns_by_default`, `::test_pii_export_requires_reason_and_records_it_in_audit`, `::test_pii_export_reason_is_rejected_when_too_short` |
| **agency no-stock-bucket** | `Wwhn254ReportEvidenceTest::test_agency_is_never_an_inventory_bucket` |

## 5. Kết quả chạy thật (local, sqlite, ngày 18/08/2026)

```
php artisan test --filter 'ReportFlow06|Flow06|Flow05InventoryControlAlignmentTest|Wwhn328DashboardQuickContractTest|Wwhn254ReportEvidenceTest'
=> tests 109, passed 109, assertions 1065

vendor/bin/pint --test <các tệp đã sửa>  => passed
php -l Modules/Warehouse/app/Http/Controllers/OperationalReportController.php => OK
```

Bộ full suite BE tại thời điểm chạy có 14 đỏ, **không** thuộc luồng báo cáo: chúng nằm ở
`Wwhn300BackendGoLiveCheckTest`, `Wwhn309PermissionCatalogEvidenceTest`,
`DocumentationBaselineContractTest`, `SwaggerRouteSyncTest` (PV controllers),
`CurrentBaselineFlowContractSourceTest`, `SchemaApiModulesTest` — do các thay đổi đang dở
của phiên làm việc song song trên cùng nhánh `m1` (PV / permission catalog / go-live).
Trước khi pin candidate cuối, phải chạy lại full suite ở trạng thái sạch.
