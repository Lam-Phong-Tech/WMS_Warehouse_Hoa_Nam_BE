# WWHN-310 — Hoàn thiện tài liệu dữ liệu/migration/báo cáo (BE2)

Cập nhật: 2026-08-18
Repository: `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE`
Nhánh thực hiện: `m1`
Parent: `WWHN-83` `[HANDOVER] Gói bàn giao sản phẩm, kỹ thuật, QA, release và vận hành`
Output: `Data handover` · Role `BE2` · Assignee Placeholder `HN-BE-02`

## 1. Phạm vi và nguồn chuẩn

WWHN-310 là **data handover** của parent WWHN-83: từ điển dữ liệu, migration/seed/nạp dữ liệu
cuối, và danh mục báo cáo — kèm evidence truy vết được. Không tự ký thay BAS/BA/QA/DevOps và không
tự đóng parent WWHN-83.

Nguồn đã đọc và đối chiếu:

1. `docs/HN_SRS_FRD_v1.12_FULL.md` — §7 Data Model/Data Dictionary, §13.4 Governance, §13.8 Privacy
   Contract, §13.10 Report Contract, §13.12 Migration/Dry-run/Reconciliation, Annex A.
2. `docs/HoaNam_ERD_Moi_Nhat.md` (`HN-WMS-ERD-DD-001`) — 59 entity vật lý.
3. `docs/HN_WMS_Wireframe_MVP_ALIGNED_v3.20_v1.12_20260814.html` — màn hình báo cáo, state bắt buộc.
4. `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md` — PostgreSQL reference, migration/seed,
   RBAC theo permission code, CI gates.

Bất biến dữ liệu không được làm sai: ledger `stock_movements` append-only là nguồn authoritative;
`inventory_balances` là projection cùng transaction; không stocktake/adjustment trực tiếp; không
partial posting; chỉ Post Issue mới trừ tồn; RFID optional, không phải completion gate; không có
endpoint public/admin để import dữ liệu đầu kỳ.

## 2. Phát hiện và xử lý (tự audit — ticket chưa có comment đánh giá ngoài)

| Sev | Phát hiện | Xử lý | Trạng thái |
| --- | --- | --- | --- |
| P0 | `warranty_cases.defect_id` không có FK về danh mục lỗi ⇒ defect mồ côi ghi được vào ca bảo hành rồi chảy vào RPT-WAR-01/RPT-WAR-COMP-01 | Migration `2026_08_18_193000` thêm `fk_erd_warranty_cases_defect_id` (validate ngay khi dữ liệu sạch, `NOT VALID` nếu còn bản ghi lịch sử lệch) | CLOSED |
| P1 | Cổng ERD parity giải entity 15 về **compatibility view** `defect_catalog` thay vì bảng thật `defect_catalogs`, che 6 lệch schema của bảng master này | ERD-AMD-005 + `DEC-DATA-001` + migration `2026_08_18_193000` | CLOSED |
| P1 | `parseColumns()` của cổng parity xoá mất mọi cột sau dấu phẩy có khoảng trắng ⇒ **toàn bộ ràng buộc composite** (PK/UNIQUE/IDX nhiều cột) của 59 entity chưa từng được kiểm thật; `user_roles` báo lệch PK dù schema đúng | Trim trước khi cắt chú thích; `user_roles(user_id, role_id)` nay được kiểm đúng | CLOSED |
| P1 | Cổng parity bỏ qua mệnh đề `UX(...)` ⇒ partial `UX(item_id) WHERE status=ACTIVE` của `inbound_scan_entries` (ERD bảng 28) thiếu hẳn dù phía outbound (bảng 41) đã có | Thêm assertion `UX` + migration `2026_08_18_193200` tạo `ux_erd_inbound_active_item` | CLOSED |
| P1 | `reversal_lines.original_movement_id`/`posted_movement_id` chỉ có FK neo vào bảng **mirror** `inventory_movements`, không neo vào ledger authoritative `stock_movements` | Migration `2026_08_18_193100` thêm FK canonical, giữ nguyên FK mirror | CLOSED |
| P1 | Bản ERD ở repo FE lệch 5 nhóm nội dung so với bản BE — còn ghi "PRODUCT Post Issue yêu cầu active RFID", trái SRS v1.12 (RFID optional) | Đồng bộ hai bản; nay trùng checksum | CLOSED |
| P1 | `defect_catalogs.created_at/updated_at` là `timestamp without time zone` trong khi §13.10 chốt mọi báo cáo theo Asia/Ho_Chi_Minh | Chuyển sang `timestamptz` | CLOSED |
| P1 | **Không ghi nổi một lần in nhãn thất bại trên PostgreSQL**: `2026_08_16_136000` dựng lại CHECK `chk_label_print_current_status` (bộ giá trị vòng đời của *nhãn*) lên `label_print_events` — chính CHECK mà `2026_08_10_000769` đã cố ý gỡ — trong khi trigger chuẩn hoá status *sự kiện in* về bộ ERD; giao hai bộ chỉ còn `PRINTED` ⇒ RPT-LABEL-01 không bao giờ có bằng chứng in lỗi | Migration `2026_08_18_193300` gỡ CHECK đặt nhầm bảng, giữ `chk_erd_label_print_events_2` làm nguồn duy nhất | CLOSED |
| P2 | API `defect_code`/`defect_name` validate `max:50`/`max:255` lệch Data Dictionary (80/200) | Store/Update request + OpenAPI về 80/200; cột DB về varchar(80)/varchar(200) | CLOSED |
| P2 | ERD viết ràng buộc bằng bí danh văn xuôi `UNIQUE(document,line_no)` / `UNIQUE(request,line_no)` — máy không giải được về cột vật lý | ERD-AMD-006 viết đúng tên cột ở 3 entity | CLOSED |
| P2 | `defect_catalogs.version` DEFAULT 1, lệch baseline optimistic locking (0) | Đưa về DEFAULT 0 | CLOSED |
| P2 | View `defect_catalog` chiếu `created_by`/`updated_by` thành NULL literal dù bảng thật có dữ liệu | Dựng lại view theo cột thật; cầu ghi INSTEAD OF chuyển tiếp thêm hai cột này | CLOSED |
| P2 | RPT-LABEL-01 tính sai hai cột: `failed_count` chỉ đếm `PRINT_FAILED` nên luôn bằng 0 trên DB tham chiếu (status đã chuẩn hoá thành `FAILED`); `reprint_count = total_attempts − 1` nên một lần in lỗi rồi in lại thành công bị báo "đã in lại 1 lần" | `failed_count` đếm cả hai bộ giá trị; `reprint_count` = số lần in **thành công** trừ 1; `attempt_no`/`event_type` suy từ lịch sử thay vì gán cứng 1/`PRINT` | CLOSED |
| P2 | ERD không có sổ đăng ký sửa đổi; 4 lần amend trước đó không truy vết được | Thêm §0.0 Amendment Register (ERD-AMD-001..006) | CLOSED |

Không còn phát hiện P0/P1 nào thuộc vai trò BE2 mà chưa có disposition.

### Một bẫy kỹ thuật đáng ghi lại

PostgreSQL áp **column default trước** khi chạy BEFORE ROW trigger. Vì thế cột định danh canonical
`id` của các bảng mang định danh kép **không được** có `DEFAULT gen_random_uuid()`: nếu có, trigger
đồng bộ luôn thấy `NEW.id` khác NULL và giữ một UUID ngẫu nhiên thay vì lấy theo `defect_id`, làm
cặp định danh lệch nhau ngay từ bản ghi đầu tiên. Bốn bảng bí danh còn lại
(`agencies`/`containers`/`item_instances`/`physical_codes`) đều để trống default vì đúng lý do này.

Bẫy thứ hai: **bỏ một compatibility view là bỏ luôn INSTEAD OF trigger gắn trên nó**. Migration nào
drop/recreate view phải dựng lại `trg_hn_legacy_<view>_write` trong cùng bước, nếu không mọi INSERT
qua tên số ít im lặng không vào bảng canonical.

## 3. Thay đổi đã áp dụng

### Backend (`WMS_Warehouse_Hoa_Nam_BE`)

| Tệp | Nội dung |
| --- | --- |
| `Modules/Warehouse/database/migrations/2026_08_18_193000_align_defect_catalogs_to_erd_entity_15.php` | `id` canonical + trigger đồng bộ + PK; `timestamptz`; `version` DEFAULT 0; varchar(80)/varchar(200); CHECK status; FK `created_by`/`updated_by`; FK `warranty_cases.defect_id`; dựng lại view + write bridge |
| `Modules/Warehouse/database/migrations/2026_08_18_193100_add_canonical_ledger_fks_to_reversal_lines.php` | FK `original_movement_id`/`posted_movement_id` → `stock_movements(id)` |
| `Modules/Warehouse/database/migrations/2026_08_18_193200_add_documented_partial_unique_on_inbound_scan_entries.php` | `ux_erd_inbound_active_item` partial UNIQUE (ERD bảng 28) |
| `Modules/Warehouse/database/migrations/2026_08_18_193300_restore_label_print_event_status_vocabulary.php` | Gỡ CHECK đặt nhầm bảng; `attempt_no`/`event_type` suy từ lịch sử của cùng nhãn |
| `Modules/Warehouse/app/Http/Controllers/AlignedLabelLogReportController.php` | RPT-LABEL-01: `failed_count` và `reprint_count` tính đúng định nghĩa |
| `Modules/Warehouse/database/migrations/2026_08_17_000100_materialize_erd_columns_on_sqlite.php` | `SKIP_IDENTITY_ALIAS` theo tên entity mới |
| `Modules/Warehouse/app/Http/Requests/StoreDefectCatalogRequest.php`, `UpdateDefectCatalogRequest.php` | `max:80` / `max:200` theo Data Dictionary |
| `Modules/Warehouse/app/Http/Controllers/DefectCatalogController.php` | OpenAPI `maxLength` 80/200 |
| `tests/Feature/ErdPhysicalSchemaParityTest.php` | Sửa `parseColumns()`; thêm kiểm `UX(...)` |
| `tests/Feature/Wwhn310DataHandoverContractTest.php` | Test mới khoá lại toàn bộ lệch đã đóng |
| `docs/HoaNam_ERD_Moi_Nhat.md` | §0.0 Amendment Register; ERD-AMD-005; ERD-AMD-006 |
| `docs/contracts/HoaNam_Current_Baseline_Contract.json` | `defect_catalog` → `defect_catalogs` trong `required_objects` |
| `docs/decision-records/DEC-DATA-001-...md` | Quyết định đổi tên entity + phương án đã cân nhắc |
| `docs/output/BE2-HN-HO-001-data-handover/` | Gói bàn giao 4 phần: từ điển dữ liệu/toàn vẹn, migration/seed, danh mục báo cáo, kiểm chứng/evidence |

### Frontend (`WMS_Warehouse_Hoa_Nam_FE`)

| Tệp | Nội dung |
| --- | --- |
| `docs/HoaNam_ERD_Moi_Nhat.md` | Đồng bộ với bản BE (trước đó lệch 5 nhóm nội dung, trong đó có quy tắc RFID đã bị SRS v1.12 thay thế) |
| `src/modules/master-data/pages/ApiMasterScreens.tsx` | `maxLength` 80/200 cho ô Mã lỗi / Tên lỗi theo Data Dictionary |

Không có thay đổi API contract nào bắt FE phải sửa: tên trường, endpoint báo cáo và permission giữ
nguyên; `defect_id` phía client không đổi.

## 4. Gói bàn giao

`docs/output/BE2-HN-HO-001-data-handover/`

| Tệp | Nội dung |
| --- | --- |
| `00-summary.md` | Phạm vi, nguồn chuẩn, bảng phát hiện/disposition, ranh giới vai trò |
| `01-data-dictionary-and-integrity.md` | Quy ước tên canonical/compat theo driver, sửa đổi tài liệu, toàn vẹn tham chiếu, cột định danh kép, cổng parity |
| `02-migration-and-seed.md` | Kiểm kê migration, 3 mốc phải hiểu, quy ước cho migration mới, seed, runbook nạp dữ liệu cuối, công cụ vận hành |
| `03-report-catalog.md` | 9 báo cáo §13.10 → endpoint/permission/filter/export, quy tắc tính đã hiện thực hoá, điểm đối soát |
| `04-verification-and-evidence.md` | Hai nhánh driver, cổng kiểm chứng, kiểm tra thủ công, evidence cần nộp |

## 5. Ranh giới — phần KHÔNG thuộc BE2

1. Đóng parent `WWHN-83` và disposition tổng thể: BAS/PM.
2. Sign-off tài liệu ERD sau ERD-AMD-005/006: Tech Lead (WWHN-31) + BA/BAS.
3. Nghiệm thu evidence QA/UAT trên đúng closure SHA: QA.
4. Thực thi nạp dữ liệu cuối trên môi trường thật và xác nhận vận hành: DevOps + QL kho.
5. Backend handover kiến trúc/API/vận hành: WWHN-309 (BE1).

## 6. Kết quả kiểm chứng

| Phép đo | Trước | Sau |
| --- | --- | --- |
| SQLite full suite | 667 test — 645 pass, 22 skip, 0 fail | 744 test — 717 pass, 27 skip, **0 fail** |
| PostgreSQL — nhóm cổng dữ liệu/ERD/warranty/master | 5 fail thuộc nhóm OPEN WWHN-31 | **71/71 pass** |
| PostgreSQL full suite | 5 đỏ nhóm WWHN-31 cộng các đỏ có sẵn | 744 test — 721 pass, 5 skip, 18 đỏ **đều có sẵn từ trước**: 17 × `Wwhn328DashboardQuickContractTest` (`uk_warehouses_single_active`) + 1 × `Flow02InboundPutawayAlignmentTest` (`ux_erd_doc_inbound_lines_*`). Đã xác minh bằng cách gỡ 4 migration và khôi phục ERD gốc rồi chạy lại. |
| PostgreSQL — `migrate:fresh --seed --force` | — | Chạy sạch; 0 dòng `defect_catalogs` lệch `id`/`defect_id` |
| FE `typecheck` + `lint` + `test` + `build` | — | typecheck PASS, lint 0 error, **522/522 test PASS**, build PASS |

Nhóm 5 đỏ trước đây trên PostgreSQL (`ErdPhysicalSchemaParityTest` ×4 +
`DocumentationBaselineContractTest` FK ×1) từng được ghi là **OPEN chờ Tech Lead WWHN-31**. Ticket
này đóng cả 5 bằng cách sửa đúng gốc: 3 đỏ là do ERD đặt tên entity 15 lệch quy ước, 1 đỏ là lỗi
parser của chính cổng kiểm (schema vật lý vốn đã đúng), 1 đỏ là thiếu FK thật.

Một đỏ **có sẵn từ trước, KHÔNG sửa ở ticket này**: `Wwhn328DashboardQuickContractTest` (8 test)
tạo kho ACTIVE thứ hai (`KHO-328-B`), vi phạm partial unique `uk_warehouses_single_active` của
REQ-SRS-001 "MVP chỉ có 01 warehouse vận hành" ⇒ SQLSTATE 23505. Chỉ đỏ trên PostgreSQL nên CI
(chạy SQLite) không bắt được. Thuộc WWHN-328, đã ghi nhận kèm cách tái lập; không đụng vào để tránh
xung đột với phiên đang làm ticket đó.

> **Cập nhật 2026-08-18 (đóng ở WWHN-328, không thuộc phạm vi WWHN-310):** đỏ này đã được sửa —
> fixture chuyển `KHO-328-B` sang `INACTIVE` (baseline chỉ chặn kho **ACTIVE** thứ hai, không chặn
> dòng kho thứ hai), đo lại **17/17 pass trên cả SQLite lẫn PostgreSQL**. Đồng thời đính chính con
> số ở đoạn trên: vì vỡ ngay tại `setUp()` nên **toàn bộ 17 test** của class đỏ trên PostgreSQL,
> không phải 8. Chi tiết + lệnh tái lập: `docs/evidence/WWHN-328/EVIDENCE_PACK.md` §4b.

Một đỏ **có sẵn từ trước, không thuộc phạm vi ticket** cũng được sửa vì nằm đúng miền dữ liệu:
`Flow01SystemAndCatalogAlignmentTest::test_inactive_defect_cannot_be_attached_on_temp_only` dựng
fixture warranty case `IDENTIFIED` mà không có `item_id`, vi phạm CHECK
`chk_erd_warranty_identification_shape` nên chỉ chạy được trên SQLite. Đã xác minh đây là đỏ có sẵn
bằng cách chạy lại test trên bản ERD gốc với ba migration của ticket được gỡ ra.

### Pin nguồn (DEV310-P1-01)

Thay đổi **đã commit** trên `m1`. Không có nhánh riêng và không có PR: `m1` là nhánh làm việc chung,
nhiều phiên commit song song — disposition chính thức là **direct-commit**, ranh giới review là
*file set + blob hash tại exact SHA* chứ không phải PR approval.

| Mục | Giá trị |
| --- | --- |
| Nhánh bị supersede | `m1-be-2` @ `60816499066f9f493596e326dc41116ef32f5c4b` — ancestor lịch sử thuần: `git merge-base --is-ancestor 6081649 m1` = true, `git rev-list --count 6081649..m1` = 719, `git rev-list --count m1..6081649` = 0 |
| Nhánh canonical | `m1` |
| Code candidate (vòng 1) | `127c573a7236ad1c25829eb92011a4914360b840` — commit gộp 80 file của một phiên song song, **không mang tên WWHN-310**; identity của ticket xác định theo 20 file + blob hash trong `docs/evidence/WWHN-310/WWHN-310_SOURCE_PIN.json` |
| Final candidate (vòng 2) | ghi trong `WWHN-310_SOURCE_PIN.json` → `final_candidate.sha` (vòng đóng DEV310-P1-01..06) |
| FE candidate | ghi trong cùng file → `frontend.sha` |

Cách xác minh một file thuộc WWHN-310 tại code candidate:

```bash
git rev-parse 127c573a7236ad1c25829eb92011a4914360b840:Modules/Warehouse/database/migrations/2026_08_18_193000_align_defect_catalogs_to_erd_entity_15.php
```

Số liệu kiểm chứng của vòng 2 được đo lại tại final candidate, xem
`docs/evidence/WWHN-310/WWHN-310_ROUND2_REGISTER.md`.

## 7. Definition of Done cho WWHN-310

- Từ điển dữ liệu, migration/seed và danh mục báo cáo nằm trong repo, truy vết được tới exact SHA.
- ERD hai repo giống hệt nhau; mọi sửa đổi có mã ERD-AMD và Decision Record.
- Cổng ERD parity + contract PASS trên PostgreSQL; full suite PASS trên SQLite.
- `migrate:fresh --seed` chạy sạch trên PostgreSQL.
- Không còn P0/P1 thuộc BE2 chưa có disposition.
- Không có drift stocktake / partial-post / RFID-mandatory trong tài liệu bàn giao.
