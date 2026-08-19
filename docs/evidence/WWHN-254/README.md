# WWHN-254 — Evidence pack báo cáo tích hợp (BE)

Đóng P0/P1/P2 EXACT FIX ACTION REGISTER của review 61/100.
Baseline hiệu lực: **SRS/FRD v1.12** (adopt tại WWHN-334), **ERD HoaNam_ERD_Moi_Nhat**,
**Wireframe MVP ALIGNED v3.20/v1.12 20260814**, **Techstack/Architecture Dev Guide**.

## 0. Định danh candidate

**Round 2 (19/08/2026):** candidate `c855851b…` đã DRIFT 125 commit (BE) / 48 commit (FE)
kể từ 18/08 — review 64/100 bắt đúng gap này ở DEV254-P1-02R. Đã re-pin sang SHA dưới đây
sau khi soát lại toàn bộ report-scope không bị lệch (`git diff --stat` rỗng cho
`*Report*`, `config/l5-swagger.php`, `docs/postman`, `docs/api-contract/releases`,
`routes/api.php`) và tìm/sửa 1 regression thật do ticket khác gây ra (mục 6).

| Hạng mục | Giá trị |
| --- | --- |
| Nhánh | `m1` (không tạo nhánh riêng) |
| BE candidate SHA (Round 2) | `e02903f1fc6b7635c2deac84a0284f49ddfb0fdd` |
| BE candidate SHA (Round 1, superseded) | `c855851b37920237d110f7dffe5dbbad509aebce` |
| FE candidate SHA | xem `docs/Task_docs_Jira/WWHN-254.md` bên repo FE |
| Gate exact-SHA | `.github/workflows/flow06-exact-sha.yml` — **CHƯA chạy lại qua GitHub Actions ở Round 2** (xem mục 6, lý do là chỉ thị của chủ dự án tạm dừng mọi tiêu hao CI-minutes) |

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

## 6. Round 2 (19/08/2026) — re-pin sau drift 125 commit, đóng DEV254-P1-02R

### 6.1 Regression thật tìm ra và đã sửa: REQ-SRS-037 guard báo nhầm

`WWHN-190` (Post Receipt R2, commit `d9f1769`) thêm release pack mới
`docs/api-contract/releases/WWHN-190/1.8.0/` (`openapi.json`, `openapi.yaml`,
`CHANGELOG.md`). Pack này dùng đúng mẫu `x-removed-surfaces` + `x-supersedes` để **ghi lại**
việc đã gỡ `/stocktake/sessions/{id}/apply-adjustment` theo DISP-STK-236 §3 (immutable
handoff, superseded chứ không sửa nội dung cũ) — bằng chứng TUÂN THỦ REQ-SRS-037, không
phải capability còn sống. Nhưng guard `test_published_contract_artifacts_carry_no_stocktake_capability`
(chính guard do WWHN-254 thêm) so needle `/stocktake` trên toàn bộ nội dung file kể cả field
tường thuật (`description`, `x-removed-surfaces.path`) nên báo nhầm 3 file là vi phạm.

Sửa tại `tests/Feature/Flow05InventoryControlAlignmentTest.php` (commit `e02903f1`): thêm
`stripStocktakeNarrativeFields()` — trước khi so needle, với `.json`/`.yaml` thì
`json_decode`/`Symfony\Yaml::parse` rồi gỡ khoá `description|summary|reason|declared_in|
notes|title` + toàn bộ khối `x-removed-surfaces`/`x-supersedes`; với `.md` (văn xuôi thuật
lại, không phải hợp đồng máy đọc) bỏ qua bước quét nội dung. Path/tag/enum/permission SỐNG
nằm ngoài các khoá này vẫn bị bắt nguyên vẹn — không nới lỏng guard, chỉ sửa đúng chỗ hổng.
Test lại: `Flow05InventoryControlAlignmentTest` 10/10 pass; đây là điều kiện tiên quyết để
pin được candidate Round 2 (full suite phải sạch ở report/no-Stocktake scope trước khi pin).

### 6.2 Hai lỗi hạ tầng tìm ra trong chính `flow06-exact-sha.yml` — CHƯA sửa (ngoài phạm vi cho phép)

Theo chỉ thị hiện hành của chủ dự án, **không được sửa/chạy bất cứ gì thuộc GitHub Actions**
(ngân sách CI đã dùng hết 3000/3000 phút, tránh phát sinh phí). Hai lỗi dưới đây nằm
trong file `.github/workflows/flow06-exact-sha.yml` nên KHÔNG được sửa ở vòng này — ghi lại
đúng nguyên nhân + bản vá cần áp dụng, để Tech Lead áp 1 lần khi có ngân sách CI trở lại,
tránh phải điều tra lại từ đầu:

1. **Bước "Current-baseline documentation semantics" trỏ vào file đã bị xoá.**
   `docs/HoaNam_Tai_lieu_tong_hop_Moi_Nhat.md` bị gỡ tại commit `7c2ecd4` (16/08, đổi sang
   `docs/HoaNam_Tai_Lieu_Tong_hop_Dev_Test_v1.0_20260812_Moi_Nhat.md`), rồi file đó cũng
   không còn tồn tại trong cây hiện tại — nội dung đã được hợp nhất vào 4 tài liệu baseline
   chính thức (`HN_SRS_FRD_v1.12_FULL.md`, `HoaNam_ERD_Moi_Nhat.md`,
   `HN_WMS_Wireframe_MVP_ALIGNED_v3.20_v1.12_20260814.html`,
   `WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md`). Bước `run:` này sẽ **luôn fail ngay**
   (`grep: No such file or directory`) ở bất kỳ lần chạy nào kể cả khi CI có ngân sách trở
   lại. Đã xác nhận lại từng assertion vẫn đúng về nội dung, chỉ đổi chỗ neo:
   - `'01 nhãn/item'` / `'PRODUCT bắt buộc active RFID trước Post Issue'` — vẫn KHÔNG xuất
     hiện ở cả 4 tài liệu baseline (đã grep xác nhận).
   - `'đúng 01 Packing Label cho phiếu/lô'` — nội dung tương đương nay nằm ở
     `docs/HoaNam_ERD_Moi_Nhat.md:2044` dạng *"Đúng 01 Packing Label canonical / phiếu-lô
     xuất."* (đổi chữ hoa + chèn "canonical /" khi hợp nhất baseline).
   - `'UNIQUE(outbound_document_id)'`, `'customer_phone'` — vẫn ở `HoaNam_ERD_Moi_Nhat.md`.
   - `"REPORT_EXPORT: 'report.export'"` — vẫn ở `WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md`.
   **Bản vá cần áp (không tự áp ở vòng này):** đổi 5 dòng `grep … docs/HoaNam_Tai_lieu_tong_hop_Moi_Nhat.md`
   trong `flow06-exact-sha.yml` sang trỏ đúng 2 file baseline còn tồn tại ở trên, và cập
   nhật literal `đúng 01 Packing Label cho phiếu/lô` → `Đúng 01 Packing Label canonical / phiếu-lô xuất`.
2. **Bước "Exact SHA evidence summary" tính hash Postman sai với file có khoảng trắng trong
   tên.** Lệnh `find docs/postman -type f | sort | xargs sha256sum | sha256sum` không
   null-delimit; nhiều file Postman bàn giao có khoảng trắng trong tên (vd.
   `"WMS_HoaNam API Contract - DEV.postman_collection.json"`), khiến `xargs` tách thành
   nhiều "đường dẫn" rác không tồn tại → `sha256sum` lỗi từng phần → cả bước fail (shell
   step của GitHub Actions chạy với `-eo pipefail`). Tính lại đúng cục bộ bằng
   `find … -print0 | sort -z | xargs -0 sha256sum | sha256sum` (mục 6.3). **Bản vá cần áp:**
   đổi đúng 1 dòng lệnh trong bước "Exact SHA evidence summary" sang biến thể `-print0`/`-z`/`-0`.

### 6.3 Bằng chứng chạy thật Round 2 (19/08/2026, local — không qua GitHub Actions)

| Hạng mục | Kết quả |
| --- | --- |
| sqlite — report scope (`ReportFlow06\|Flow06\|Flow05InventoryControlAlignmentTest\|Wwhn328DashboardQuickContractTest\|Wwhn254ReportEvidenceTest\|Wwhn237VarianceReportDeferredGateTest`) | 119/119 pass, 2555 assertion |
| sqlite — full suite (`php artisan test`, sau khi sửa 6.1) | 1078/1123 pass; **9 fail còn lại đều ngoài phạm vi WWHN-254** — 7× `Wwhn300BackendGoLiveCheckTest` (migration `2026_08_19_130000_sync_inbound_lines_mirror_deletes` của phiên song song chưa dispositioned vào `fail_forward_allowlist.php`), 1× `DocumentationBaselineContractTest` (health thiếu 400, pre-existing), 1× `Wwhn309PermissionCatalogEvidenceTest` (catalog doc stale, pre-existing). `ReportFlow06DocsAlignmentTest::test_swagger_publishes_report_filters_and_export_request_body` cũng rớt trong lượt full-suite này nhưng **pass lại khi chạy riêng** (flaky do state chung khi nhiều phiên cùng ghi `storage/api-docs/`, không phải regression) |
| PostgreSQL 16 (container tạm `wwhn254-pg-verify`, KHÔNG dùng container CI dùng chung `wms_warehouse_hoa_nam_be-postgres-1`, đã xoá sau khi xong) — `migrate:fresh --seed --force` + report scope | 92/92 pass, 1003 assertion |
| `vendor/bin/pint --test` đúng danh sách file trong bước "Flow 06 Pint / syntax" của gate | passed |
| Doc-semantics (grep, mục 6.2, trỏ đúng 2 file baseline hiện hành thay vì file đã xoá) | cả 5 assertion đều đúng |
| REQ-SRS-037 no-Stocktake trong `docs/postman` + `docs/api-contract/releases` | 0 offender (sau fix 6.1) |
| `openapi_sha256` (tại `e02903f1`, `php artisan l5-swagger:generate`) | `14b226e5bcddb6e486878b6130e058d1fe49d176b7973275fce055bc79127af8` |
| `postman_set_sha256` (tính đúng, null-delimited — xem 6.2 mục 2) | `d5841748b60e69ce235e8f4f1f7acf0580f33506cf71cc1e7cd955c4bff15c26` |
| FE — `npx tsc --noEmit`, `npx vitest run` (tại FE SHA `f9f2e65e`) | typecheck sạch; 701/701 test (90 file), gồm `flow06-report-pagination-export.test.ts` 7/7 |

**Điều CHƯA làm ở Round 2, cố ý:** chưa `gh workflow run flow06-exact-sha.yml` (chưa có
run GitHub Actions PASS chính thức cho SHA `e02903f1`) vì (a) chỉ thị chủ dự án tạm dừng
mọi hoạt động tiêu tốn CI-minutes, và (b) kể cả khi được phép chạy, gate sẽ tự fail ở 2 lỗi
hạ tầng tại mục 6.2 cho tới khi ai đó có quyền sửa `.yml` áp bản vá đã nêu. Bằng chứng local
ở trên (sqlite + PG16 thật, không mock) là bản thay thế tạm thời cho tới khi có run CI chính
thức; Tech Lead cần đối chiếu lại khi ngân sách CI mở lại.
