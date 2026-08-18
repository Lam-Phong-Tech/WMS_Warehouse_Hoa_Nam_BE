# WWHN-240 — Evidence pack (exact SHA)

Ngày: 2026-08-18 · Nhánh: `m1` (không tạo nhánh riêng) · Đóng `DEV240-P1-01..P1-05` + `DEV240-P2-01`

---

## 1. Exact SHA candidate

| Repo | Remote | Branch | Exact SHA |
|---|---|---|---|
| BE | `https://github.com/Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE.git` | `m1` | `9640091cf780fce1ef54102f6955971a57c5678b` |
| FE | `https://github.com/Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_FE.git` | `m1` | `79474b8c6cd873f26bc6b42ca1bd9af2171ce7cf` |

**Lưu ý attribution (bắt buộc đọc trước khi review diff).** Thay đổi
`OperationalReportController::buildXnt()` của WWHN-240 nằm trong commit
`c855851b37920237d110f7dffe5dbbad509aebce` — commit của **WWHN-254** (data-scope báo cáo)
chạy song song trên cùng nhánh `m1` và đã gộp nhầm phần XNT của WWHN-240 vào cùng một
commit. Nội dung code là đúng và đã có test khoá; chỉ có ranh giới commit bị trộn. Vì vậy
gate exact-SHA của WWHN-240 phải pin **cả hai**:

```
c855851b37920237d110f7dffe5dbbad509aebce   (chứa buildXnt gross/reversal/net)
9640091cf780fce1ef54102f6955971a57c5678b   (trace chain, OpenAPI, test, decision record)
```

Nói cách khác: `9640091` là SHA phát hành **code** của WWHN-240; `c855851` là ancestor bắt buộc;
`91f53cae3db03b3973345aeddc446745190ca30c` là commit evidence pack (tài liệu, không có code).
Reviewer đọc `git diff c855851~1..9640091 -- Modules/Warehouse/app/Http/Controllers/OperationalReportController.php`
sẽ thấy trọn phần XNT.

## 2. Lỗi gốc đã đóng

### DEV240-P1-02A · `buildXnt()` tính reversal sai ngữ nghĩa

**Trước:** một cột duy nhất
`reversal_net = SUM(qty_delta) WHERE movement_type='REVERSAL'`.
Đảo-nhập có `qty_delta` âm, đảo-xuất có `qty_delta` dương ⇒ hai chiều triệt tiêu lẫn nhau.
Một kỳ có 10 nhập bị đảo hết và 10 xuất bị đảo hết cho `reversal_net = 0`, y hệt một kỳ
không có reversal nào. Trái BR-RPT-01..07 ("hiển thị gross/net") và BR-REV-11 ("report
hiển thị movement gốc, toàn bộ Reversal chain và **net effect**").

**Sau:** phân loại từng reversal theo `movement_type` của giao dịch **gốc** trong correction
chain, phát hành ba tầng cột:

| Tầng | Cột |
|---|---|
| GROSS | `receipt_gross`, `issue_gross`, `component_issue_gross` (+ bí danh cũ `receipt`/`issue`/`component_issue`) |
| REVERSAL | `receipt_reversal`, `issue_reversal`, `component_issue_reversal`, `reversal_unlinked_net`, `reversal_net` |
| NET | `receipt_net`, `issue_net`, `component_issue_net`, `closing` |

kèm `formula_net` và `column_semantics` (bản đồ cột → nhãn GROSS/REVERSAL/NET) ngay trong
payload, để FE/BA/QA không phải suy diễn từ tên cột.

Bốn quyết định kỹ thuật quan trọng:

1. **Không suy chiều từ dấu `qty_delta`.** Đảo một `ISSUE` và đảo một `COMPONENT_ISSUE`
   đều cho delta dương; suy theo dấu sẽ gộp hai cột nghiệp vụ khác nhau làm một.
2. **Đi ngược chain tới gốc** (`resolveCorrectionRootTypes`) vì BR-REV-08 cho phép
   reversal-của-reversal — `original_movement_id` có thể trỏ vào một `REVERSAL` khác.
   Đảo rồi lại đảo ngược ⇒ `receipt_reversal` về 0 và `receipt_net` trở lại gross.
3. **Reversal không truy được gốc** (dữ liệu migrate, gốc `OPENING`, chain đứt) rơi vào
   `reversal_unlinked_net` chứ không bị bỏ, nếu không `closing` sẽ lệch ledger.
4. **Bí danh cũ giữ nguyên nghĩa**, `formula` cũ giữ nguyên chuỗi ⇒ client hiện hành không vỡ.

Bất biến được assert ở mọi ca test:
`closing (theo NET) == closing (theo gross cũ) == SUM(qty_delta) của ledger`.

### DEV240-P1-02B · `traceSource()` chỉ trả một reversal

**Trước:** `StockMovement::where('original_movement_id', $id)->first()` — đúng **một**
reversal. BR-REV-05 cho phép đảo từng phần nhiều lần và BR-REV-08 cho phép
reversal-của-reversal, nên mọi lần đảo thứ hai trở đi biến mất khỏi trace và net effect
không thể tính được từ response.

**Sau:** `correctionChain()` đi ngược `original_movement_id` về gốc rồi duyệt theo bề rộng
gom toàn bộ movement đối ứng. Response bổ sung:

- `correction_chain` — `root_movement_id`, `node_count`, `reversal_count`, `max_depth`,
  `truncated`, `nodes[]` (mỗi node có `role` ORIGINAL/REVERSAL, `depth`, `is_subject`,
  `qty_delta`, `actor`, `reason_code`/`reason_text`, `reversal_document_no`).
- `net_effect` — `basis=LEDGER`, `original_qty`, `reversed_qty`,
  `remaining_reversible_qty`, `net_qty_delta`, `fully_reversed`, `reversal_count`.
- `reversal_movements[]`, `root_movement`.

Khoá cũ `original_movement` / `reversal_movement` giữ nguyên hình dạng (đánh dấu
DEPRECATED trong OpenAPI) nên client hiện hành không vỡ.

Toàn bộ truy vấn chain dùng Eloquent ⇒ global scope `ScopesWarehouseData` còn hiệu lực,
không mắt xích nào rò ra ngoài warehouse data scope.

### DEV240-P1-02C · OpenAPI trace/report chưa khoá được

**Lỗi gốc:** `Modules/Warehouse/app/Http/Controllers/StockMovementController.php` **không**
nằm trong `config/l5-swagger.php → annotations[]`, nên annotation thật của `trace-source`
chưa bao giờ được publish; `RouteSyncProcessor` sinh skeleton chung chung thay cho contract.
Đây đúng lớp lỗi đã ghi trong chính file config cho WWHN-184 (Inbound).

**Đóng bằng:** thêm file vào danh sách scan + catalog schema
`app/Swagger/ReversalNetEffectSchemas.php` (`XntReportLine`, `XntReport`,
`CorrectionChainNode`, `CorrectionChain`, `ReversalNetEffect`,
`StockMovementTraceSource`) với đủ `required` / `nullable` / `type` / `example`, và bổ sung
**403** cho `trace-source`, `inventory-xnt`, `inventory-xnt-enhanced`, `movement-trace`.

Artifact sinh thật:

```
php artisan l5-swagger:generate
```

| Path | operationId | responses |
|---|---|---|
| `/api/v1/stock-movements/{id}/trace-source` | `stock_movement_trace_source` | 200,401,403,404,400,409,412,422,428,500 |
| `/api/v1/reports/inventory-xnt` | `report_inventory_xnt_current` | 200,401,403,422,400,404,409,412,428,500 |
| `/api/v1/reports/inventory-xnt-enhanced` | `report_xnt_enhanced` | 200,401,403,400,404,409,412,422,428,500 |
| `/api/v1/reports/movement-trace` | `report_movement_trace` | 200,401,403,422,400,404,409,412,428,500 |

Bản trích xuất bất biến của WWHN-240 (5 path + 11 schema):
`docs/evidence/WWHN-240/openapi-wwhn240-slice.json`
SHA-256 `5d258a2c19b29b8ed0606cb50fb93e0e4c6b57f85f96660417f9b255db7ebeb3`.

> `storage/api-docs/api-docs.json` **cố ý không nằm trong commit này**: tại thời điểm phát
> hành, file đó đang có thay đổi chưa commit của một luồng khác trên cùng nhánh `m1`.
> Commit artifact tổng thuộc về luồng đó; WWHN-240 pin bản trích xuất ở trên.

### DEV240-P1-05 · FE consume đúng response mới

- `AlignedReportsPage` (SCR-RPT-01 · tab XNT): ba nhóm cột **Nhập / Xuất / Xuất LK**, mỗi
  nhóm gồm `Gross · Đã đảo · Thuần`, cộng `Reversal net` và `Cuối kỳ`. Có fallback về cột
  gross cũ nếu backend chưa phát hành khoá mới.
- `inventoryService`: type `CorrectionChainNode` / `ReversalNetEffect`, normalizer đọc mềm
  `correction_chain` + `net_effect`.
- `TraceModal` (TRACE-01): bảng **Correction chain** (vai trò Gốc/Đảo, movement, Δ tồn,
  thời điểm, người thực hiện, lý do) + khối **Net effect** (SL gốc, đã đảo, còn đảo được,
  tác động ròng, trạng thái đảo). Có cảnh báo khi chain bị cắt.

### DEV240-P2-01 · Trace metadata

Xem `docs/docs_task_jira/WWHN-240.md` §5 — đối chiếu wording lịch sử với ownership hiện
hành, **không** đổi business trace key.

## 3. Bằng chứng test (chạy thật)

```
php vendor/bin/phpunit --filter Wwhn240ReversalNetEffectContractTest
→ 14 tests, 115 assertions, 0 failure
```

| # | Test | Đóng |
|---|---|---|
| 1 | `xnt_tach_reversal_theo_movement_type_goc` | gross/reversal/net ba chiều + bí danh cũ |
| 2 | `xnt_khong_gop_nham_dao_xuat_voi_dao_xuat_linh_kien` | không suy chiều theo dấu delta |
| 3 | `xnt_dao_cua_dao_tra_lai_gross_ban_dau` | BR-REV-08 double reverse |
| 4 | `xnt_reversal_khong_truy_duoc_goc_van_vao_closing` | không đánh mất bút toán |
| 5 | `xnt_cong_bo_ngu_nghia_cot_gross_reversal_net` | `column_semantics` + `formula_net` |
| 6 | `xnt_export_van_tra_xlsx_voi_bo_cot_gross_reversal_net` | BR-EXP-01..05 |
| 7 | `trace_source_tra_du_chain_va_net_effect_cho_movement_goc` | chain + net effect |
| 8 | `trace_source_khong_con_giau_reversal_thu_hai` | BR-REV-05 đảo từng phần |
| 9 | `trace_source_di_nguoc_ve_goc_khi_tra_tu_mot_reversal` | đi ngược chain |
| 10 | `trace_source_danh_dau_fully_reversed_khi_dao_het` | net effect = 0 |
| 11 | `trace_source_movement_khong_ton_tai_tra_404` | 404 |
| 12 | `trace_source_ngoai_warehouse_scope_bi_tu_choi_403` | RBAC data-scope |
| 13 | `trace_source_thieu_inventory_view_bi_tu_choi_403` | RBAC permission |
| 14 | `trace_source_trong_scope_van_doc_duoc_day_du_chain` | đối chứng không chặn nhầm |

Hồi quy nhánh liên quan (`ReportFlow06*`, `Wwhn191`, `Wwhn207`, `Wwhn211`, `Wwhn242`,
`Wwhn180`, `Wwhn328`): **136/138 pass**.

> Hai lỗi còn lại là `SchemaApiModulesTest::test_pv_profile_schema_accepts_only_one_profile_per_sku`
> và `::test_schema_integrity_migration_rolls_back_and_reapplies_cleanly` —
> `UNIQUE constraint failed: pv_product_profile.product_id`, **đỏ sẵn có trên `m1`**, không
> liên quan tới bất kỳ file nào WWHN-240 chạm vào (PV profile ≠ reversal reporting). Đã tách
> thành việc riêng.

FE: `npx vitest run` → **578/578 pass (76 file)**; `npx tsc --noEmit` sạch.

## 4. Cách reviewer verify lại tại đúng SHA này

```bash
git -C <BE> checkout 9640091cf780fce1ef54102f6955971a57c5678b
php vendor/bin/phpunit --filter Wwhn240ReversalNetEffectContractTest
php artisan l5-swagger:generate
```

```bash
git -C <FE> checkout 79474b8c6cd873f26bc6b42ca1bd9af2171ce7cf
npx tsc --noEmit && npx vitest run
```
