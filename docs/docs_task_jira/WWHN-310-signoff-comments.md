# WWHN-310 — Bộ comment xác nhận (sign-off) để dán lên Jira

Nguyên tắc: **mỗi vai trò một comment duy nhất**, nội dung là đọc + duyệt, không phát sinh việc mới.
Bằng chứng chi tiết ở `docs/docs_task_jira/WWHN-310.md` và `docs/output/BE2-HN-HO-001-data-handover/`.

---

## 1. BE2 — người thực hiện (comment của bạn)

> ✅ **BE2 — Data handover WWHN-310: hoàn tất, đã đóng P0/P1/P2 trong phạm vi BE2**
>
> Xác nhận đã rà soát toàn bộ tầng dữ liệu/migration/báo cáo theo SRS/FRD v1.12 (§7, §13.4, §13.8,
> §13.10, §13.12, Annex A), ERD `HN-WMS-ERD-DD-001`, Wireframe ALIGNED v3.20 và Techstack Guide.
>
> Đã đóng: **1 P0** (`warranty_cases.defect_id` không có FK về danh mục lỗi), **7 P1** (cổng ERD
> parity giải entity 15 về view thay vì bảng thật; `parseColumns` bỏ mọi cột sau dấu phẩy khiến
> ràng buộc composite chưa từng được kiểm; cổng bỏ qua `UX(...)` nên thiếu partial UNIQUE của
> `inbound_scan_entries`; `reversal_lines` chỉ neo FK vào bảng mirror thay vì ledger authoritative;
> ERD hai repo lệch nhau; `defect_catalogs` dùng timestamp không timezone; **không ghi nổi một lần
> in nhãn thất bại trên PostgreSQL** nên RPT-LABEL-01 không bao giờ có bằng chứng in lỗi),
> **6 P2** (giới hạn field lệch Data Dictionary, ràng buộc viết bằng bí danh văn xuôi, `version`
> default lệch, view chiếu `created_by/updated_by` thành NULL, RPT-LABEL-01 tính sai
> `failed_count`/`reprint_count`, ERD chưa có sổ đăng ký sửa đổi).
>
> Bàn giao: `docs/docs_task_jira/WWHN-310.md`, `docs/output/BE2-HN-HO-001-data-handover/` (4 phần),
> `docs/decision-records/DEC-DATA-001`, ERD §0.0 Amendment Register (ERD-AMD-001..006).
> Test khoá lại: `tests/Feature/Wwhn310DataHandoverContractTest.php`.
>
> Không còn blocker P0/P1 thuộc vai trò BE2. Các gate còn lại thuộc BAS/PM, Tech Lead, QA, DevOps.

---

## 2. Tech Lead / BE1 — xác nhận baseline kỹ thuật (WWHN-31)

> ✅ **Tech Lead/BE1 sign-off — physical schema & ERD amendment**
>
> Tôi xác nhận đã đọc và duyệt:
> 1. **ERD-AMD-005** — entity 15 đổi tên `defect_catalog` → `defect_catalogs` (bảng canonical),
>    tên số ít giữ vai trò compatibility view; không đổi field/constraint/semantics.
> 2. **ERD-AMD-006** — ba mệnh đề `UNIQUE(...)` viết bằng tên cột vật lý thật thay cho bí danh
>    `document`/`request`; đúng ràng buộc đã có ở cột "Index khuyến nghị".
> 3. Bốn migration `2026_08_18_193000/193100/193200/193300`: định danh `id` + trigger đồng bộ,
>    `timestamptz`, `version` DEFAULT 0, varchar(80)/varchar(200), FK
>    `warranty_cases.defect_id → defect_catalogs.id`, FK `reversal_lines.*_movement_id →
>    stock_movements.id`, partial UNIQUE `inbound_scan_entries(item_id) WHERE status=ACTIVE`, và gỡ
>    CHECK `chk_label_print_current_status` bị đặt nhầm lên `label_print_events` (giữ nguyên trên
>    `label_snapshots`) để sự kiện in `PENDING/SENT/FAILED` ghi được trở lại.
> 4. `DEC-DATA-001` là quyết định chính thức cho hai amendment trên.
>
> Xác nhận không có thay đổi business semantics và không cần Change Request nghiệp vụ.

---

## 3. BA / BAS — xác nhận baseline tài liệu

> ✅ **BA/BAS sign-off — Data Dictionary baseline**
>
> Tôi xác nhận đã đọc và duyệt:
> 1. `HN-WMS-ERD-DD-001` bổ sung **§0.0 Amendment Register** ghi lại ERD-AMD-001..006 (gồm cả 4
>    lần amend trước đây chưa được truy vết).
> 2. Hai amendment mới của WWHN-310 (ERD-AMD-005 đổi tên entity 15; ERD-AMD-006 viết đúng tên cột
>    trong mệnh đề UNIQUE) **không đổi** entity, requiredness, uniqueness, authority hay stock
>    semantics đã chốt.
> 3. Bản ERD trong repo FE đã được đồng bộ với bản BE — trước đó bản FE còn quy tắc
>    "PRODUCT Post Issue yêu cầu active RFID" đã bị SRS v1.12 thay thế (RFID optional).
>
> Xác nhận baseline tài liệu dữ liệu dùng được cho FE/BE/QA; không có TBD ảnh hưởng P0/P1 còn mở.

---

## 4. QA — xác nhận evidence và khả năng kiểm thử

> ✅ **QA sign-off — evidence & testability của gói bàn giao dữ liệu**
>
> Tôi xác nhận đã đọc và duyệt:
> 1. `docs/output/BE2-HN-HO-001-data-handover/04-verification-and-evidence.md` — cách chạy trên hai
>    driver (SQLite CI / PostgreSQL tham chiếu), danh sách cổng kiểm chứng, lệnh tái lập và bộ
>    evidence phải nộp.
> 2. Bảng phát hiện P0/P1/P2 và disposition tương ứng trong `docs/docs_task_jira/WWHN-310.md`.
> 3. `tests/Feature/Wwhn310DataHandoverContractTest.php` khoá lại toàn bộ lệch đã đóng.
> 4. Danh mục báo cáo §13.10 → endpoint/permission/filter/export tại `03-report-catalog.md`, gồm
>    định nghĩa mới của RPT-LABEL-01 (`total_attempts` / `failed_count` / `reprint_count`) và ghi
>    chú `inventory-ledger` cố ý không nằm trong `/reports/hub`.
> 5. Hai đỏ **có sẵn từ trước** đã được nêu rõ kèm bằng chứng: fixture warranty của
>    `Flow01SystemAndCatalogAlignmentTest` (đã sửa trong ticket này) và
>    `Wwhn328DashboardQuickContractTest` tạo kho ACTIVE thứ hai, vi phạm
>    `uk_warehouses_single_active` (REQ-SRS-001 — thuộc WWHN-328, chưa sửa ở ticket này).
>
> Xác nhận đầu ra đủ để dựng scenario và nghiệm thu; kết luận PASS/FAIL sẽ chạy trên đúng closure SHA.

---

## 5. DevOps — xác nhận ảnh hưởng migration/cutover

> ✅ **DevOps sign-off — migration & cutover impact**
>
> Tôi xác nhận đã đọc và duyệt:
> 1. Ba migration mới của WWHN-310 và quy ước bắt buộc trong
>    `02-migration-and-seed.md`: `down()` phải chịu được đối tượng đã biến mất; DDL phụ thuộc dữ
>    liệu phải fail-closed có thông điệp hành động được, không tự cắt dữ liệu; FK trên dữ liệu lịch
>    sử có thể lệch được tạo `NOT VALID` để không làm hỏng lần migrate của cutover.
> 2. Runbook nạp dữ liệu cuối `docs/migration/FINAL_DATA_LOAD_RUNBOOK.md` giữ nguyên hợp đồng
>    DRY_RUN/COMMIT/ROLLBACK và exit code làm cutover gate.
> 3. Không phát sinh endpoint import public/admin nào (giữ `DEC-MIG-API-001`).
>
> Xác nhận thay đổi này an toàn cho quy trình migrate/rollback hiện hành.

---

## 6. FE1 — xác nhận đã nhận đầu vào

> ✅ **FE1 sign-off — đã nhận baseline dữ liệu**
>
> Tôi xác nhận đã đọc và duyệt:
> 1. Bản ERD trong repo FE đã đồng bộ với bản BE (trùng checksum) và là bản dùng để tra cứu.
> 2. Không có thay đổi API contract nào bắt FE phải sửa: tên trường, endpoint báo cáo và permission
>    giữ nguyên; `defect_id` phía client không đổi.
> 3. Thay đổi duy nhất ảnh hưởng UI là giới hạn nhập của màn hình Danh mục lỗi
>    (`defect_code` 80 ký tự, `defect_name` 200 ký tự) theo Data Dictionary — đã áp dụng ở FE.
>
> Xác nhận FE nhận đủ đầu vào dữ liệu để triển khai/kiểm thử.
