# 01 — Từ điển dữ liệu và toàn vẹn tham chiếu

Nguồn sự thật: `docs/HoaNam_ERD_Moi_Nhat.md` (`HN-WMS-ERD-DD-001`, 59 entity vật lý) + SRS/FRD
v1.12 §7 và Annex A. Mọi phát biểu dưới đây kiểm được bằng máy — xem `04-verification-and-evidence.md`.

## 1. Quy ước tên bảng — điểm dễ hiểu sai nhất

Hệ thống chạy trên **hai nhánh schema**, và tên canonical **đảo chiều theo driver**:

| Driver | Bảng vật lý | Tên còn lại |
| --- | --- | --- |
| PostgreSQL (DB tham chiếu) | Tên **số nhiều**: `app_users`, `reversal_documents`, `reversal_lines`, `scan_events`, `pv_product_profiles`, `defect_catalogs`… | Tên số ít là **compatibility view** kèm INSTEAD OF trigger ghi xuống bảng số nhiều |
| SQLite (nhánh CI) | Tên **số ít** cho 27 bảng canonical | Migration `2026_08_10_000791` cố ý chỉ chạy trên PostgreSQL |

Hệ quả bắt buộc phải nhớ khi viết code/fixture/truy vấn:

- Dò sự tồn tại của một quan hệ phải dùng `HoaNamErdSchema::relationExists()`, **không** dùng
  `Schema::hasTable()` — `hasTable` chỉ trả lời cho bảng, nên trên một trong hai driver sẽ báo
  thiếu một nửa số aggregate.
- Fixture test phải chọn tên bảng theo `DB::getDriverName()`.
- Bỏ (DROP) một quan hệ phải dùng `HoaNamErdSchema::dropRelation()` — `DROP VIEW` cứng sẽ chết
  `42809` khi tên đó đang là bảng, và làm hỏng `migrate:rollback` giữa chừng (bài học WWHN-300).
- **Bỏ view là bỏ luôn INSTEAD OF trigger gắn trên nó.** Migration nào drop/recreate một
  compatibility view phải dựng lại cầu ghi `trg_hn_legacy_<view>_write` trong cùng bước, nếu không
  mọi INSERT qua tên số ít sẽ im lặng không vào bảng canonical.

## 2. Sửa đổi tài liệu trong ticket này

Xem §0.0 Amendment Register của ERD. WWHN-310 thêm hai mục:

- **ERD-AMD-005** — entity 15 `defect_catalog` → `defect_catalogs`. Chi tiết và phương án đã cân
  nhắc: `docs/decision-records/DEC-DATA-001-defect-catalogs-naming-and-erd-amendments.md`.
- **ERD-AMD-006** — ba mệnh đề `UNIQUE(...)` viết bằng bí danh văn xuôi được viết lại bằng tên cột
  vật lý (`inbound_lines`, `outbound_request_lines`, `component_issue_lines`).

Bản ERD trong repo FE trước đây lệch 5 nhóm nội dung so với bản BE — trong đó có câu
"PRODUCT Post Issue yêu cầu active RFID" đã bị SRS v1.12 thay thế (RFID optional). Hai bản nay
**giống hệt nhau theo checksum**; lệch bản là blocker của gói bàn giao.

## 3. Toàn vẹn tham chiếu đã đóng trong ticket này

| Quan hệ | Trước | Sau |
| --- | --- | --- |
| `warranty_cases.defect_id → defect_catalogs.id` | Không có FK nào | `fk_erd_warranty_cases_defect_id` |
| `defect_catalogs.created_by/updated_by → app_users.id` | Không có FK | `fk_erd_defect_catalogs_created_by/_updated_by` |
| `reversal_lines.original_movement_id → stock_movements.id` | Chỉ neo vào bảng mirror `inventory_movements` | Thêm `fk_canonical_reversal_lines_original_movement_id` (FK mirror giữ nguyên) |
| `reversal_lines.posted_movement_id → stock_movements.id` | Như trên | Thêm `fk_canonical_reversal_lines_posted_movement_id` |
| `inbound_scan_entries` partial UX(item_id) WHERE status=ACTIVE | Thiếu (phía outbound đã có) | `ux_erd_inbound_active_item` |
| `defect_catalogs.status` | Không có CHECK | `chk_defect_catalogs_status` ACTIVE/INACTIVE |

Vì sao FK vào bảng mirror là chưa đủ: `inventory_movements` được sinh ra bằng trigger
`trg_erd_mirror_inventory_movements_from_stock_movements` với `INSERT ... ON CONFLICT (id) DO
NOTHING`. Neo ràng buộc vào tầng phái sinh nghĩa là chuỗi reversal vẫn "hợp lệ" về mặt DB ngay cả
khi movement gốc không có trong ledger authoritative. Mirror giữ nguyên `id` nên FK canonical thêm
vào thoả mãn toàn bộ dữ liệu hiện có; hai FK cũ được giữ lại để không nới lỏng ràng buộc nào.

## 4. Cột định danh kép

Các bảng canonical mang **cả hai** cột định danh: `id` (canonical theo ERD) và `<entity>_id`
(legacy, các truy vấn/model đang dùng). Chúng luôn bằng nhau nhờ trigger BEFORE INSERT/UPDATE:

| Bảng | Cột legacy | Trigger đồng bộ |
| --- | --- | --- |
| `agencies` | `agency_id` | `trg_erd_normalize_runtime_agencies` |
| `containers` | `container_id` | `trg_containers_sync_identity` |
| `item_instances` | `item_id` | (đồng bộ ở migration ERD 1.0) |
| `defect_catalogs` | `defect_id` | `trg_erd_normalize_runtime_defect_catalogs` (WWHN-310) |

Client cũ dùng cột legacy không phải sửa gì. Trên SQLite các bảng này giữ khoá chính legacy và
**không** có cột `id` — chủ trương đã ghi trong `2026_08_17_000100_materialize_erd_columns_on_sqlite`:
thêm một cột `id` luôn NULL nguy hiểm hơn là không có nó.

## 5. Cổng parity — đã sửa hai lỗ hổng của chính cổng

`tests/Feature/ErdPhysicalSchemaParityTest.php` đọc thẳng ERD và đối chiếu với
`information_schema`/`pg_catalog`. Hai lỗ hổng khiến nó báo xanh sai:

1. `parseColumns()` cắt phần chú thích sau tên cột bằng `preg_replace('/\s+.*/', '', $field)`
   **trước khi** trim. Với `PK(user_id, role_id)`, phần tử thứ hai là `" role_id"` — khoảng trắng
   đầu chuỗi khớp ngay và cột bị xoá sạch. Mọi ràng buộc composite của 59 entity vì thế chỉ được
   kiểm cột đầu tiên, và `user_roles` báo lệch PK dù schema vật lý đúng tài liệu.
2. Cổng chỉ kiểm `UNIQUE(...)`/`IDX(...)`/`PK(...)` mà bỏ qua `UX(...)` — dạng viết index duy nhất
   ở cột "Index khuyến nghị". Vì thế partial UX(item_id) của `inbound_scan_entries` thiếu hẳn
   trong schema vật lý mà cổng vẫn xanh.

Sau khi sửa, cổng kiểm đủ: relkind (phải là bảng thật, không phải view), kiểu/nullable/default từng
field, PK/UNIQUE/UX/IDX kể cả composite và partial, FK theo đúng bảng cha ghi trong tài liệu.

## 6. Quy tắc dữ liệu không đổi trong ticket này

- Ledger append-only: `stock_movements` có trigger chặn UPDATE/DELETE; sửa sai đi qua reversal
  (DEC-REV-001, BR-REV-06/07/08/12).
- `inventory_balances` là projection, cập nhật trong cùng transaction với ledger, có optimistic
  version.
- Không hard-delete master: INACTIVE/deactivate thay vì xoá; mã đã được tham chiếu là immutable
  (`agency_code`, `defect_code`, mã master đã gắn SKU).
- PII bảo hành: mask mặc định trên API; xuất PII cần `warranty.pii.export` + lý do ≥5 ký tự + audit;
  purge sau 5 năm trừ legal hold (§13.8).
