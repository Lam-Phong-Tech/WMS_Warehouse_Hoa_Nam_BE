# WWHN-310 — Data handover (BE2)

## Traceability

- Jira: `WWHN-310` `[BE] Hoàn thiện tài liệu dữ liệu/migration/báo cáo`
- Parent: `WWHN-83` `[HANDOVER] Gói bàn giao sản phẩm, kỹ thuật, QA, release và vận hành`
- Parent Temp Key: `HN-HO-001` · Epic Temp Key: `HN-EPIC-10` · Dependency: `HN-HO-001-ST01`
- Output: `Data handover` · Role: `BE2` · Assignee Placeholder: `HN-BE-02`
- Repository/branch: `WMS_Warehouse_Hoa_Nam_BE` @ `m1`
- Ngày: 18/08/2026

## Nguồn chuẩn đã đọc và đối chiếu

1. `docs/HN_SRS_FRD_v1.12_FULL.md` — §7 Data Model/Data Dictionary, §13.4 Data Dictionary
   Governance, §13.8 Privacy Contract, §13.10 Report Contract, §13.12 Migration Template &
   Dry-run/Reconciliation, Annex A.
2. `docs/HoaNam_ERD_Moi_Nhat.md` (`HN-WMS-ERD-DD-001`) — 59 entity vật lý, field/constraint/index.
3. `docs/HN_WMS_Wireframe_MVP_ALIGNED_v3.20_v1.12_20260814.html` — màn hình báo cáo và state bắt buộc.
4. `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md` — PostgreSQL reference, migration/seed,
   API-first, RBAC theo permission code, CI gates.

## Bất biến dữ liệu không được làm sai

- `stock_movements` là ledger authoritative, append-only (DEC-INV-001); `inventory_balances` là
  projection cập nhật trong cùng transaction; `item_instances` và `container_items` là subledger.
- Không có stocktake/adjustment trực tiếp trong MVP; sửa sai chỉ đi qua reversal.
- Không partial posting: inbound/outbound chỉ Post khi khớp đủ toàn bộ document.
- Chỉ `Hoàn tất xuất/Post Issue` mới trừ tồn; Packing Label và RFID là stock-neutral, RFID không
  phải completion gate.
- Không có endpoint public/admin để import dữ liệu đầu kỳ — chỉ console job (`DEC-MIG-API-001`).
- PII bảo hành mask mặc định; xuất PII cần permission riêng + lý do + audit (§13.8).

## Phân loại và xử lý phát hiện

| Sev | Phát hiện | Trạng thái | Bằng chứng |
| --- | --- | --- | --- |
| P0 | `warranty_cases.defect_id` không có FK về danh mục lỗi — defect mồ côi ghi được vào ca bảo hành rồi chảy vào RPT-WAR-01/RPT-WAR-COMP-01 | ĐÃ ĐÓNG | migration `2026_08_18_193000`; `Wwhn310DataHandoverContractTest` |
| P1 | Cổng ERD parity giải entity 15 về **compatibility view** `defect_catalog` thay vì bảng thật `defect_catalogs`, che 6 lệch schema | ĐÃ ĐÓNG | ERD-AMD-005 + `DEC-DATA-001` + migration `2026_08_18_193000` |
| P1 | `parseColumns()` của cổng parity xoá mất mọi cột sau dấu phẩy có khoảng trắng ⇒ **mọi ràng buộc composite** (PK/UNIQUE/IDX nhiều cột) của 59 entity chưa từng được kiểm thật | ĐÃ ĐÓNG | `ErdPhysicalSchemaParityTest::parseColumns` |
| P1 | Cổng parity không kiểm mệnh đề `UX(...)` ⇒ partial UX(item_id) của `inbound_scan_entries` (ERD bảng 28) thiếu hẳn trong schema vật lý dù phía outbound có | ĐÃ ĐÓNG | migration `2026_08_18_193200` + assertion `UX` mới |
| P1 | Quan hệ tài liệu `reversal_lines.original_movement_id / posted_movement_id → stock_movements.id` chỉ được neo vào bảng **mirror** `inventory_movements` | ĐÃ ĐÓNG | migration `2026_08_18_193100` |
| P1 | Bản ERD trong repo FE lệch 5 nhóm nội dung so với bản BE (còn ghi "PRODUCT Post Issue yêu cầu active RFID" trái SRS v1.12) | ĐÃ ĐÓNG | hai bản nay giống hệt nhau theo checksum |
| P1 | `defect_catalogs.created_at/updated_at` là `timestamp without time zone` trong khi mọi báo cáo chốt theo Asia/Ho_Chi_Minh | ĐÃ ĐÓNG | migration `2026_08_18_193000` |
| P1 | Không ghi nổi một lần in nhãn thất bại trên PostgreSQL — hai CHECK chồng nhau trên `label_print_events` chỉ còn giao là `PRINTED`, nên RPT-LABEL-01 không bao giờ có bằng chứng in lỗi | ĐÃ ĐÓNG | migration `2026_08_18_193300`; `Wwhn310DataHandoverContractTest` |
| P2 | RPT-LABEL-01: `failed_count` luôn 0 trên DB tham chiếu; `reprint_count` đếm cả lần in lỗi | ĐÃ ĐÓNG | `AlignedLabelLogReportController` |
| P2 | API `defect_code`/`defect_name` validate `max:50`/`max:255` lệch Data Dictionary (80/200) | ĐÃ ĐÓNG | Store/UpdateDefectCatalogRequest + OpenAPI |
| P2 | ERD viết ràng buộc bằng bí danh văn xuôi `UNIQUE(document,line_no)` / `UNIQUE(request,line_no)` — không giải được về cột vật lý | ĐÃ ĐÓNG | ERD-AMD-006 |
| P2 | `defect_catalogs.version` DEFAULT 1 lệch baseline optimistic locking (0) | ĐÃ ĐÓNG | migration `2026_08_18_193000` |
| P2 | View `defect_catalog` chiếu `created_by`/`updated_by` thành NULL literal dù bảng thật có dữ liệu | ĐÃ ĐÓNG | view dựng lại trong migration `2026_08_18_193000` |
| P2 | ERD không có sổ đăng ký sửa đổi; 4 lần amend trước đó không truy vết được | ĐÃ ĐÓNG | §0.0 Amendment Register (ERD-AMD-001..006) |

Không còn phát hiện P0/P1 nào thuộc vai trò BE2 mà chưa có disposition.

## Nội dung bàn giao

| Tệp | Nội dung |
| --- | --- |
| `01-data-dictionary-and-integrity.md` | 59 entity, quy ước tên canonical/compat theo driver, cổng parity, ràng buộc toàn vẹn đã đóng |
| `02-migration-and-seed.md` | Kiểm kê migration, thứ tự chạy, seed, nạp dữ liệu cuối, rollback |
| `03-report-catalog.md` | 9 báo cáo §13.10 → endpoint/permission/filter/export/đối soát |
| `04-verification-and-evidence.md` | Lệnh tái lập, test bảo vệ, evidence cần nộp |

## Ranh giới — phần KHÔNG thuộc BE2 và không được tự ký

1. Đóng parent `WWHN-83` và disposition tổng thể: BAS/PM.
2. Sign-off tài liệu ERD sau ERD-AMD-005/006: Tech Lead (WWHN-31) + BA/BAS.
3. Nghiệm thu evidence QA/UAT trên đúng closure SHA: QA.
4. Thực thi nạp dữ liệu cuối trên môi trường production và xác nhận vận hành: DevOps + QL kho.
5. Backend handover kiến trúc/API/vận hành: WWHN-309 (BE1).
