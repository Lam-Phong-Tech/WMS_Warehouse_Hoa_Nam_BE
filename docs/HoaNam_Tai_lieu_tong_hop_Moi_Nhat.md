**HOA NAM TOOLS**

**TÀI LIỆU TRIỂN KHAI HỢP NHẤT**

BRD v3.13 + SRS/FRD v1.6 + BPD v1.5

| **Thuộc tính**     | **Giá trị**                                          |
|--------------------|------------------------------------------------------|
| **Document ID**    | HN-BA-MASTER-DEVTEST-001                             |
| **Version**        | 1.0                                                  |
| **Ngày phát hành** | 04/08/2026                                           |
| **Trạng thái**     | CONSOLIDATED DEV/TEST REFERENCE - REVISION CANDIDATE |
| **Owner**          | Business Analyst - Nguyễn Đình Hùng                  |

**Working reference cho DEV / FE / QA / TEST**

# 0. DOCUMENT CONTROL VÀ CÁCH SỬ DỤNG

| **Nguồn**       | **Phiên bản** | **Vai trò kiểm soát**                                             | **Trạng thái**                                              |
|-----------------|---------------|-------------------------------------------------------------------|-------------------------------------------------------------|
| BRD Hoa Nam     | v3.13         | Scope, business rule, acceptance nghiệp vụ                        | Revision Approval Candidate; effective on WWHN-333 approval |
| SRS/FRD Hoa Nam | v1.6          | Requirement, state, logical data/API/screen, NFR, test design     | Revision Approval Candidate; effective on WWHN-334 DONE     |
| BPD Hoa Nam     | v1.5          | Actor, trigger, input/output, TO-BE process, exception, RACI, KPI | Revision Approval Candidate; effective on WWHN-335 approval |

| **Mục đích —** Đây là tài liệu tra cứu hợp nhất để DEV/FE/QA/Test không phải mở lần lượt ba tài liệu nguồn. Tài liệu không thay thế approval evidence trên Jira; khi có mâu thuẫn, áp dụng thứ tự nguồn tại mục 0.1. |
|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|

## 0.1. Thứ tự ưu tiên khi có mâu thuẫn

1.  Hợp đồng/Phụ lục, Approved Change Request hoặc Decision Record đã có hiệu lực.

2.  BRD v3.13 điều khiển business scope, actor, ý nghĩa chức năng và quyết định MVP.

3.  SRS/FRD v1.6 điều khiển requirement, state, validation, logical API/data, permission, NFR và testability.

4.  BPD v1.5 điều khiển trình tự TO-BE, handoff, stock effect, exception và trách nhiệm vận hành.

5.  Physical ERD/DDL/OpenAPI/Figma/Test Master là downstream artifact; không được tự mở rộng hoặc ghi đè business semantics.

## 0.2. Quy tắc tra cứu

| **Nhu cầu**                                   | **Mục đọc** | **Từ khóa/ID**                                |
|-----------------------------------------------|-------------|-----------------------------------------------|
| Scope và quyết định không được diễn giải khác | Mục 1-2     | DEC-HN-20260804, In/Out scope                 |
| Requirement/Acceptance                        | Mục 4       | REQ-SRS, AC-SRS, TC-SRS                       |
| Luồng vận hành và ngoại lệ                    | Mục 5       | BP-WMS                                        |
| State/stock effect                            | Mục 6       | DRAFT, SCANNING, POSTED, CANCELLED            |
| Rule/validation                               | Mục 7       | FULL-POST, IDEMPOTENCY, RFID, NO-STOCKTAKE    |
| API/permission/screen                         | Mục 8-9     | permission key, HTTP 409/412/422/428          |
| NFR và test                                   | Mục 11-13   | NFR, TC-FULL/CAM/EXCEL/LABEL/RFID/COMP/PV/REV |
| Bàn giao và readiness                         | Mục 14-15   | Ready for Dev/FE/QA/UAT                       |

## 0.3. SCOPE LOCK - nội dung cũ tuyệt đối không triển khai

| **Cảnh báo xung đột tồn dư —** Một số annex/bảng cũ trong bộ tài liệu có thể còn chuỗi remaining_qty, cancelled_remainder_qty, PARTIALLY_POSTED, close-remainder, Stocktake/Variance/Adjustment hoặc Demo scanner. Theo change set 04/08/2026, các nội dung này đã bị supersede. DEV/TEST phải xóa/deprecate khỏi OpenAPI, ERD, UI, permission và test active; không dùng chúng làm baseline. |
|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|

- Không có nhập/xuất một phần, close remainder, thiếu-thừa, over/under workflow hoặc PARTIALLY_POSTED.

- Không có Stocktake Session/Snapshot/Count/Variance/Adjustment, menu kiểm kê, permission kiểm kê hoặc báo cáo kiểm kê.

- Không dùng Demo scanner làm chức năng chính; scan hiện hành là camera Barcode/QR trên Web WMS và nhập tay dự phòng.

- Không tạo tồn tại Agency/đại lý; Agency chỉ là nơi nhận hàng.

- Không dùng camera điện thoại để đọc RFID. RFID được gắn/map bằng thiết bị/cơ chế phù hợp trước Post Issue.

## 0.4. Mục lục tĩnh

6.  1\. Tóm tắt quyết định hiện hành

7.  2\. Phạm vi MVP và ranh giới

8.  3\. Actor và phân quyền

9.  4\. Functional Requirement Master

10. 5\. Quy trình TO-BE chi tiết

11. 6\. State model và Stock Effect

12. 7\. Business Rule và Validation

13. 8\. Logical API/Error/Permission Baseline

14. 9\. Screen/UX Baseline

15. 10\. Logical Data Model tóm tắt

16. 11\. Non-functional Requirements

17. 12\. Báo cáo và Export

18. 13\. Acceptance Criteria/Test Design

19. 14\. Traceability và Readiness

20. 15\. Checklist bàn giao và điểm Tech cần chốt

# 1. TÓM TẮT QUYẾT ĐỊNH HIỆN HÀNH

| **ID**                 | **Quyết định**                         | **Hệ quả triển khai/Test**                                                                         |
|------------------------|----------------------------------------|----------------------------------------------------------------------------------------------------|
| **DEC-HN-20260804-01** | Inbound/Outbound full-post only        | Chỉ Post khi mọi line đủ chính xác; không partial/remainder/thiếu-thừa.                            |
| **DEC-HN-20260804-02** | Không có Stocktake trong MVP           | Không màn/API/entity/permission/report kiểm kê; sai giao dịch dùng reversal/correction.            |
| **DEC-HN-20260804-03** | Quét Barcode/QR bằng camera Web WMS    | HTTPS, camera permission, decode vào đúng context; manual fallback; camera không đọc RFID.         |
| **DEC-HN-20260804-04** | Excel request + match + nhãn từng item | Import, row-level validation, match SKU+qty; scan hợp lệ tạo/in 01 nhãn/item; hỗ trợ In lại.       |
| **DEC-HN-20260804-05** | Linh kiện chỉ xuất cho bảo hành        | Component Issue bắt buộc purpose=WARRANTY và warranty_case_id đang active.                         |
| **DEC-HN-20260804-06** | Catalog độc lập tồn                    | Sản phẩm active/published vẫn hiển thị khi chưa có Balance hoặc qty=0 với trạng thái Chưa có hàng. |
| **DEC-WM-001..003**    | 01 kho tổng; Agency là nơi nhận        | Không tồn/movement tại Agency; Post Issue chỉ giảm kho tổng và ghi nơi nhận.                       |
| **DR-CARRIER-001**     | Barcode/QR inbound; RFID outbound      | RFID map vào cùng item; việc gắn mã không tạo item/SKU/movement.                                   |
| **DEC-INV-001**        | Movement là authoritative ledger       | Balance là projection; item/container là subledger; cùng transaction và đối soát.                  |
| **DR-PLAT-001**        | Web App PV responsive read-only        | Không cart/order/reservation/payment; chỉ active/published và available_qty kho tổng.              |

# 2. PHẠM VI MVP VÀ RANH GIỚI

| **Trong phạm vi MVP**                                               | **Ngoài phạm vi / Later phase**                                      |
|---------------------------------------------------------------------|----------------------------------------------------------------------|
| 01 kho tổng; Agency là nơi nhận, không có tồn riêng                 | Multi-warehouse, warehouse tree, tồn/nhập/xuất tại đại lý            |
| IAM, Role/Permission backend enforcement                            | SSO/enterprise IAM ngoài baseline                                    |
| SKU PRODUCT/COMPONENT; Brand, Category, Model, Power Source mở      | Giá vốn, kế toán, công nợ, bán hàng/order/payment                    |
| Barcode/QR inbound; camera + manual; RFID map outbound              | RFID bulk reading bằng camera, driver/gateway/protocol chuyên sâu    |
| Inbound/Outbound DRAFT -\> SCANNING -\> POSTED/CANCELLED, full-post | Partial, shortage/excess, close remainder                            |
| Excel toa xuất, matching, direct label print/reprint                | CRM/ERP/Sales Order integration; advanced label designer nếu chưa CR |
| Component Issue liên kết bảo hành active                            | Bán/xuất linh kiện cho đại lý; component cost accounting             |
| Inventory query/trace/reconciliation/reversal                       | Physical stocktake/variance/adjustment                               |
| Warranty basic có mã và TEMP_ONLY                                   | Chi phí/SLA/phân công kỹ thuật viên nâng cao                         |
| PV Web responsive read-only; zero-stock catalog                     | Native/offline/cart/order/payment/reservation                        |
| Migration/opening balance bằng controlled job/script                | Public/admin recurring import endpoint                               |

## 2.1. Nguyên tắc tồn kho bắt buộc

- DRAFT, SCANNING, scan, print/reprint, RFID mapping, putaway, warranty receive/update và PV đều stock-neutral.

- Tồn chỉ đổi tại Post Receipt, Post Issue, Post Component Issue, Reversal và Opening Balance commit.

- Không cho âm kho; check availability tại thời điểm commit, không chỉ tại thời điểm mở màn hình.

- Container CARTON/BOX/TRAY không là SKU hoặc stock bucket độc lập; không double count.

- Posted transaction không sửa/xóa; correction/reversal là append-only.

# 3. ACTOR VÀ PHÂN QUYỀN

| **Actor**          | **Trách nhiệm**                                                                  |
|--------------------|----------------------------------------------------------------------------------|
| Admin hệ thống     | Quản lý user/role/permission, master data, cấu hình, publish.                    |
| Quản lý kho        | Inventory control, Post, reversal, reconciliation, reports, print monitoring.    |
| Nhân viên kho      | Create/import/scan/print/map RFID/Post nếu được cấp; putaway/trace.              |
| Nhân viên bảo hành | Tiếp nhận/cập nhật/trả case; tạo Component Issue nếu được cấp.                   |
| Ban lãnh đạo       | Xem báo cáo read-only.                                                           |
| Người xem App PV   | Xem catalog published và available_qty read-only.                                |
| Process/Data Owner | Chị Phương - Accountable cho nghiệp vụ kho, dữ liệu migration và reconciliation. |

| **Action**                 | **Admin** | **QL kho** | **NV kho** | **NV BH** | **Lãnh đạo** | **Permission key**                        |
|----------------------------|-----------|------------|------------|-----------|--------------|-------------------------------------------|
| User/role manage           | A         | D          | D          | D         | D            | iam.user.manage / iam.role.manage         |
| SKU/Catalog/Master         | A         | A          | V          | V         | V            | catalog.manage / sku.manage               |
| Inbound create/scan/post   | A         | A          | A\*        | D         | D            | inbound.create / scan / post              |
| Outbound Excel/create/scan | A         | A          | A          | D         | D            | outbound.import/create/scan               |
| Label print/reprint        | A         | A          | A          | D         | D            | label.print / label.reprint               |
| RFID assign + Post Issue   | A         | A          | A\*        | D         | D            | physical_code.assign_rfid / outbound.post |
| Component issue warranty   | A         | A          | A\*        | A\*       | D            | warranty.component_issue                  |
| Inventory view/trace       | A         | A          | V          | V\*       | V            | inventory.view / inventory.trace          |
| Reversal                   | A         | A          | D          | D         | D            | inventory.reversal.post                   |
| Warranty manage            | A         | A          | A\*        | A         | D            | warranty.manage                           |
| Publish/Reports            | A         | A          | V\*        | V\*       | V            | pv.publish / report.\*                    |

| **Ký hiệu —** A = Allow; V = View; D = Deny; A\*/V\* = chỉ khi được gán quyền. Backend phải enforce permission và scope, không chỉ ẩn nút giao diện. |
|------------------------------------------------------------------------------------------------------------------------------------------------------|

# 4. FUNCTIONAL REQUIREMENT MASTER

| **REQ ID**      | **Requirement hiện hành**                                                                                                                                                                    | **Priority** | **Acceptance focus**                                                                                                                                            | **TC**     |
|-----------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|--------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------|------------|
| **REQ-SRS-001** | Quản lý 01 kho tổng; Agency không phải warehouse.                                                                                                                                            | Must         | AC-SRS-001: không có agency balance/stocktake/movement.                                                                                                         | TC-SRS-001 |
| **REQ-SRS-002** | Quản lý SKU PRODUCT và COMPONENT, serial/quantity mode.                                                                                                                                      | Must         | AC-SRS-002: SKU unique; serial mode khóa sau khi có stock.                                                                                                      | TC-SRS-002 |
| **REQ-SRS-003** | ITEM có Physical_Code duy nhất; dùng Barcode khi nhập kho và phải map RFID/hardware_uid trước Post Issue.                                                                                    | Must         | AC-SRS-003: Post Receipt chấp nhận Barcode với hardware_uid rỗng; trước Post Issue RFID phải được map vào cùng item/Physical_Code; gắn RFID không tạo movement. | TC-SRS-003 |
| **REQ-SRS-004** | CARTON/BOX/TRAY carrier cấu hình linh hoạt.                                                                                                                                                  | Must         | AC-SRS-004: QR/BARCODE/RFID/NFC/MANUAL hợp lệ.                                                                                                                  | TC-SRS-004 |
| **REQ-SRS-005** | Quét Barcode/QR bằng camera điện thoại trên Web WMS cho inbound/outbound/trace/warranty; manual fallback.                                                                                    | Must         | AC-SRS-005: decode điền code_value vào đúng context; denied/unavailable có lỗi rõ + nhập tay.                                                                   | TC-SRS-005 |
| **REQ-SRS-006** | Tạo inbound DRAFT/SCANNING, chưa tăng tồn.                                                                                                                                                   | Must         | AC-SRS-006: draft/scan không sinh movement.                                                                                                                     | TC-SRS-006 |
| **REQ-SRS-007** | Full Post Receipt: chỉ Post toàn bộ một lần khi mọi line scanned_qty = expected_qty.                                                                                                         | Must         | AC-SRS-007: không partial/remainder; lệch thì sửa DRAFT/SCANNING hoặc cancel/recreate.                                                                          | TC-SRS-007 |
| **REQ-SRS-008** | Post Receipt idempotent, atomic và audit.                                                                                                                                                    | Must         | AC-SRS-008: retry không double stock/movement; failure rollback all.                                                                                            | TC-SRS-008 |
| **REQ-SRS-009** | Putaway cập nhật tray mapping, không đổi tồn.                                                                                                                                                | Must         | AC-SRS-009: không Inventory_Movement và available_qty không đổi.                                                                                                | TC-SRS-009 |
| **REQ-SRS-010** | Tạo outbound DRAFT/SCANNING, chưa giảm tồn.                                                                                                                                                  | Must         | AC-SRS-010: draft/scan không có stock effect.                                                                                                                   | TC-SRS-010 |
| **REQ-SRS-011** | Full Post Issue: Post một lần khi mọi line fully matched, đủ tồn và PRODUCT có RFID.                                                                                                         | Must         | AC-SRS-011: không PARTIALLY_POSTED/close remainder; không âm kho.                                                                                               | TC-SRS-011 |
| **REQ-SRS-012** | Post Issue idempotent/concurrent, không âm kho.                                                                                                                                              | Must         | AC-SRS-012: một request commit; request xung đột không trừ lần hai.                                                                                             | TC-SRS-012 |
| **REQ-SRS-013** | Reversal/correction append-only cho Receipt/Issue.                                                                                                                                           | Must         | AC-SRS-013: giữ movement gốc; guard dependency; partial; chain; report net.                                                                                     | TC-SRS-013 |
| **REQ-SRS-014** | Import toa yêu cầu xuất Excel, validate row-level và match theo sku_code + required_qty.                                                                                                     | Must         | AC-SRS-014: file hợp lệ tạo request/outbound; lỗi trả row/field/message; duplicate key không tạo trùng.                                                         | TC-SRS-014 |
| **REQ-SRS-015** | Sau scan item hợp lệ, tạo label snapshot và direct-print 01 nhãn/item; hỗ trợ In lại.                                                                                                        | Must         | AC-SRS-015: không in SĐT; print/reprint stock-neutral; audit attempt/reprint_count.                                                                             | TC-SRS-015 |
| **REQ-SRS-016** | Warranty basic và TEMP_ONLY khi mất mã; lưu thêm customer_address và purchase_date khi có.                                                                                                   | Must         | AC-SRS-016: temp code/no link/no movement/one active case; customer_address được bảo vệ PII; purchase_date không được ở tương lai.                              | TC-SRS-016 |
| **REQ-SRS-017** | Web App responsive list/detail/search/filter.                                                                                                                                                | Must         | AC-SRS-017: desktop/tablet/mobile; active/published only.                                                                                                       | TC-SRS-017 |
| **REQ-SRS-018** | PV hiển thị available_qty kho tổng, read-only.                                                                                                                                               | Must         | AC-SRS-018: không lộ item/container/movement/cost; không stock effect.                                                                                          | TC-SRS-018 |
| **REQ-SRS-019** | PV gợi ý sản phẩm theo ngành nghề bằng rule.                                                                                                                                                 | Must         | AC-SRS-019: priority cấu hình; chỉ active/published; có empty state.                                                                                            | TC-SRS-019 |
| **REQ-SRS-020** | Admin/QL kho publish/unpublish sản phẩm.                                                                                                                                                     | Must         | AC-SRS-020: inactive không được publish; audit thay đổi.                                                                                                        | TC-SRS-020 |
| **REQ-SRS-021** | Migration/opening balance operational-only: controlled dry-run, commit, reconcile và rollback bằng job/script + Import Package tại WWHN-69; không có public/admin import endpoint trong MVP. | Must         | AC-SRS-021: dry-run không đổi dữ liệu; commit chỉ khi checksum/reconciliation PASS; failed reconciliation chặn cutover; rerun idempotent theo migration_run_id. | TC-SRS-021 |
| **REQ-SRS-022** | UAT/cutover/rollback/handover package.                                                                                                                                                       | Must         | AC-SRS-022: script, actual, evidence, defect, waiver và Go/No-Go.                                                                                               | TC-SRS-022 |
| **REQ-SRS-023** | Movement authoritative; Balance projection; subledger reconcile.                                                                                                                             | Must         | AC-SRS-023: ba lớp khớp sau posting; divergence tạo incident.                                                                                                   | TC-SRS-023 |
| **REQ-SRS-024** | HTTP/error/idempotency/security contract.                                                                                                                                                    | Must         | AC-SRS-024: 400/401/403/404/409/412/422/428/500; endpoint permission.                                                                                           | TC-SRS-024 |
| **REQ-SRS-025** | Technical artifacts và approval được quản lý theo readiness gate.                                                                                                                            | Must         | AC-SRS-025: BA draft embedded; WWHN-31/33/40 linked; không công bố Ready sai.                                                                                   | TC-SRS-025 |
| **REQ-SRS-026** | Quản lý identity, user, role, permission và scope assignment.                                                                                                                                | Must         | AC-SRS-026: unique account; inactive deny; least privilege; backend enforcement.                                                                                | TC-SRS-026 |
| **REQ-SRS-027** | Quản lý Agency master: tạo/sửa/ngừng hoạt động; chỉ là nơi nhận.                                                                                                                             | Must         | AC-SRS-027: inactive không chọn cho phiếu mới; không sinh warehouse/stock.                                                                                      | TC-SRS-027 |
| **REQ-SRS-028** | Quản lý Defect Catalog dùng cho warranty/report.                                                                                                                                             | Must         | AC-SRS-028: defect_code unique; inactive không chọn mới; lịch sử giữ nguyên.                                                                                    | TC-SRS-028 |
| **REQ-SRS-029** | Quản lý category/brand/model/power-source/package/version; nhiều hãng và Power Source extensible.                                                                                            | Must         | AC-SRS-029: master unique/active; seed MAINS_DIRECT/BATTERY; thêm loại không cần code change.                                                                   | TC-SRS-029 |
| **REQ-SRS-030** | Inventory query và trace theo SKU/item/carton/box/tray/code.                                                                                                                                 | Must         | AC-SRS-030: hiển thị balance + subledger + movement chain; không double count.                                                                                  | TC-SRS-030 |
| **REQ-SRS-031** | Báo cáo tồn, XNT, lịch sử nhập/xuất, import-match, label log, warranty/component và recipient.                                                                                               | Must         | AC-SRS-031: công thức/filter đúng; không có stocktake report.                                                                                                   | TC-SRS-031 |
| **REQ-SRS-032** | Export Excel/PDF cho báo cáo được cấp quyền.                                                                                                                                                 | Should       | AC-SRS-032: export dùng cùng filter; audit; dữ liệu đúng scope.                                                                                                 | TC-SRS-032 |
| **REQ-SRS-033** | Quản lý PV Industry, Tag, Product Mapping và Recommendation Rule.                                                                                                                            | Must         | AC-SRS-033: mapping many-to-many; priority unique trong scope; inactive bị loại.                                                                                | TC-SRS-033 |
| **REQ-SRS-034** | Quản lý Import Batch và Item Condition tách khỏi SKU. Dữ liệu có thể được tạo/cập nhật bởi flow nghiệp vụ hợp lệ hoặc operational migration; không suy ra recurring Import API.              | Must         | AC-SRS-034: nhiều batch dùng cùng SKU; condition không tạo SKU mới; trace được nguồn/migration_run; không có endpoint import công khai trong MVP.               | TC-SRS-034 |
| **REQ-SRS-035** | Phiếu xuất linh kiện riêng bắt buộc purpose WARRANTY và active warranty_case_id.                                                                                                             | Must         | AC-SRS-035: mục đích khác/case inactive bị chặn; Post trừ tồn đúng một lần.                                                                                     | TC-SRS-035 |
| **REQ-SRS-036** | Catalog/SKU có thể active/published khi chưa có Inventory_Balance; zero stock hiển thị Chưa có hàng.                                                                                         | Must         | AC-SRS-036: published product visible at qty 0; filter brand/power source.                                                                                      | TC-SRS-036 |
| **REQ-SRS-037** | Không expose Stocktake/Variance/Adjustment menu, API, permission, entity hoặc report.                                                                                                        | Must         | AC-SRS-037: deployment v1.6 không có capability legacy.                                                                                                         | TC-SRS-037 |

# 5. QUY TRÌNH TO-BE CHI TIẾT

| **Cách đọc —** Mỗi quy trình gồm mục tiêu/trigger/input/output, luồng chính và ngoại lệ. Stock effect chỉ phát sinh tại các bước Post được ghi rõ. |
|----------------------------------------------------------------------------------------------------------------------------------------------------|

## BP-WMS-01 - Quản lý Catalog/SKU/Physical Code

| **Thuộc tính**      | **Nội dung**                                                                                           |
|---------------------|--------------------------------------------------------------------------------------------------------|
| **Mục tiêu**        | Tạo nền dữ liệu chuẩn để mọi giao dịch scan, nhập, xuất, bảo hành, nhãn và App PV dùng cùng định danh. |
| **Trigger**         | Có SKU/sản phẩm/linh kiện/đại lý/mã vật lý mới hoặc cần cập nhật/ngừng hoạt động.                      |
| **Actor chính**     | Admin, Quản lý kho; NV kho/NV bảo hành ở quyền xem hoặc tạo nhanh theo phân quyền.                     |
| **Đầu vào**         | Product master data, legacy SKU, thông tin brand/category/model/spec, code_value/hardware_uid, Agency. |
| **Tiền điều kiện**  | Người dùng có quyền master data; dữ liệu bắt buộc và quy tắc unique đã được xác định.                  |
| **Đầu ra/Kết thúc** | SKU/Agency/Physical_Code/Container active; alias mã cũ; dữ liệu sẵn sàng cho giao dịch.                |

| **Bước** | **Actor**    | **Hoạt động**                                                                         | **Kết quả hệ thống**                                                                                | **Rule/Guard**                   |
|----------|--------------|---------------------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------|----------------------------------|
| **01**   | Admin/QL kho | Tạo hoặc cập nhật category, brand, model, đơn vị tính, defect catalog và Agency.      | Validate code unique, trạng thái ACTIVE/INACTIVE; Agency không sinh Warehouse/Balance.              | WM-SCOPE-002; BR-AGY-01          |
| **02**   | Admin/QL kho | Tạo SKU PRODUCT hoặc COMPONENT theo format chuẩn.                                     | Validate sku_code unique; lưu sku_type, is_serial_tracked, unit và thuộc tính phân biệt.            | BR-SKU-01..10                    |
| **03**   | Admin/QL kho | Khai báo alias cho mã SKU cũ nếu migration.                                           | Alias unique theo source; không dùng alias làm khóa tồn.                                            | REQ-SRS-021                      |
| **04**   | Admin/QL kho | Tạo/mapping Barcode/QR cho ITEM/CARTON/BOX/TRAY; RFID để trống cho ITEM đến outbound. | code_value unique; carrier mapping không tạo item/movement; Brand/Power Source/catalog độc lập tồn. | DEC-CAMERA; DEC-CATALOG; BR-CODE |
| **05**   | Hệ thống     | Khóa sửa sku_code/serial mode khi đã phát sinh stock/movement.                        | Trả business rule violation nếu cố thay đổi thuộc tính ảnh hưởng dữ liệu vận hành.                  | BR-SKU-10; AC-SRS-002            |
| **06**   | Admin/QL kho | Publish/unpublish hồ sơ sản phẩm cho App PV.                                          | Chỉ SKU active mới publish; lưu profile, ảnh, tags, industries.                                     | REQ-SRS-020; PV-VIS-001          |

### Ngoại lệ bắt buộc

| **Tình huống**                           | **Cách xử lý**                                                                                       |
|------------------------------------------|------------------------------------------------------------------------------------------------------|
| SKU trùng mã                             | Chặn tạo; yêu cầu dùng mã khác hoặc cập nhật bản ghi hiện có.                                        |
| ITEM thiếu Barcode/QR hoặc RFID outbound | Inbound yêu cầu Barcode/QR; Post Issue PRODUCT yêu cầu RFID đã map. Không bắt buộc RFID tại Receipt. |
| Carrier container chưa chốt              | Cho phép cấu hình QR/BARCODE/RFID/NFC/MANUAL theo decision; không block core flow.                   |
| Mã cũ trùng alias                        | Đưa vào error list để BA/QL kho xử lý trước commit migration.                                        |

## BP-WMS-02 - Nhập kho sản phẩm full-post

| **Thuộc tính**      | **Nội dung**                                                                         |
|---------------------|--------------------------------------------------------------------------------------|
| **Mục tiêu**        | Ghi nhận sản phẩm vào kho tổng theo Barcode/QR, exact quantity và full Post một lần. |
| **Trigger**         | Hàng sản phẩm đến kho.                                                               |
| **Actor**           | NV kho; người có inbound.post.                                                       |
| **Đầu vào**         | Phiếu DRAFT, SKU, expected_qty, Barcode/QR.                                          |
| **Tiền điều kiện**  | SKU active; quyền; mã unique.                                                        |
| **Đầu ra/Kết thúc** | Item IN_STOCK; Balance tăng; RECEIPT_POSTED; phiếu POSTED.                           |

| **Bước** | **Actor**      | **Hoạt động**                                                       | **Kết quả hệ thống**                           | **Rule/Guard** |
|----------|----------------|---------------------------------------------------------------------|------------------------------------------------|----------------|
| **01**   | NV kho         | Tạo phiếu DRAFT với SKU + expected_qty.                             | Chưa đổi tồn.                                  | BR-FULL-IN     |
| **02**   | NV kho         | Mở Quét bằng camera hoặc nhập Barcode/QR.                           | Ghi Scan_Event; validate duplicate/SKU/state.  | BR-CAM         |
| **03**   | Hệ thống       | Tính scanned_qty.                                                   | Post chỉ enable khi bằng expected_qty.         | BR-FULL-IN     |
| **04**   | NV kho         | Nếu lệch, sửa expected_qty khi DRAFT/SCANNING hoặc cancel/recreate. | Không có thiếu-thừa/partial.                   | DEC-FULL-POST  |
| **05**   | Người có quyền | Post Receipt.                                                       | Atomic movement + balance + subledger; POSTED. | BR-IDEMP/TXN   |

### Ngoại lệ bắt buộc

| **Tình huống**            | **Cách xử lý**                      |
|---------------------------|-------------------------------------|
| Camera denied/unavailable | Hiện lỗi rõ và nhập tay fallback.   |
| Mã trùng/sai SKU/state    | Chặn; không tăng scanned_qty.       |
| Post lỗi giữa transaction | Rollback toàn bộ; retry idempotent. |

## BP-WMS-03 - Nhập linh kiện và Putaway

| **Thuộc tính**      | **Nội dung**                                                                                               |
|---------------------|------------------------------------------------------------------------------------------------------------|
| **Mục tiêu**        | Nhập linh kiện theo serial hoặc quantity, tăng tồn tại Post Receipt và gán khay sau nhập mà không đổi tồn. |
| **Trigger**         | Linh kiện/phụ tùng được giao đến kho tổng.                                                                 |
| **Actor chính**     | NV kho (R), QL kho/Admin (A).                                                                              |
| **Đầu vào**         | SKU COMPONENT, is_serial_tracked, box code, serial hoặc quantity, expected_qty, tray code khi putaway.     |
| **Tiền điều kiện**  | SKU active; box một SKU; mã/quantity hợp lệ.                                                               |
| **Đầu ra/Kết thúc** | Balance linh kiện tăng; box RECEIVED_PENDING_PUTAWAY/STORED; mapping Box–Tray.                             |

| **Bước** | **Actor**     | **Hoạt động**                                                                | **Kết quả hệ thống**                                                                  | **Rule/Guard**        |
|----------|---------------|------------------------------------------------------------------------------|---------------------------------------------------------------------------------------|-----------------------|
| **01**   | NV kho        | Tạo phiếu nhập linh kiện và dòng expected_qty.                               | DRAFT; chưa đổi tồn.                                                                  | BR-IN-01              |
| **02**   | NV kho        | Scan box và serial nếu is_serial_tracked=true; hoặc nhập quantity nếu false. | Validate một box chỉ chứa một SKU; SCANNING.                                          | BR-COMP-01; BR-CON-02 |
| **03**   | NV kho/QL kho | Post Receipt với khóa yêu cầu nghiệp vụ duy nhất.                            | Tăng Balance; item serial → IN_STOCK hoặc box quantity tăng; movement RECEIPT_POSTED. | REQ-SRS-007/008       |
| **04**   | Hệ thống      | Nếu chưa có tray thực tế, gán trạng thái Chờ xếp khay.                       | Box = RECEIVED_PENDING_PUTAWAY; quantity vẫn tính Available.                          | BR-PTW-01             |
| **05**   | NV kho        | Khi đặt hàng vào khay, tạo Putaway task và scan tray + box.                  | Tạo DRAFT putaway; validate tray active và box hợp lệ.                                | BR-PTW-02             |
| **06**   | NV kho/QL kho | Confirm Putaway.                                                             | Cập nhật tray mapping/location hint; box → STORED; không sinh movement.               | REQ-SRS-009           |

### Ngoại lệ bắt buộc

| **Tình huống**                                        | **Cách xử lý**                                                         |
|-------------------------------------------------------|------------------------------------------------------------------------|
| Scan box nhưng không nhập quantity cho SKU non-serial | Không đủ dữ liệu; yêu cầu quantity thực nhận.                          |
| Box chứa SKU khác                                     | Chặn và yêu cầu box khác.                                              |
| Tray inactive/không tồn tại                           | Không confirm putaway.                                                 |
| Putaway lặp                                           | Cập nhật mapping theo transaction/audit; không được tạo biến động tồn. |

## BP-WMS-04 - Import toa Excel và tạo phiếu xuất

| **Thuộc tính**      | **Nội dung**                                                          |
|---------------------|-----------------------------------------------------------------------|
| **Mục tiêu**        | Nhận toa Excel từ kinh doanh, validate và tạo outbound DRAFT matched. |
| **Trigger**         | Bộ phận kinh doanh gửi file Excel.                                    |
| **Actor**           | NV kho; bộ phận kinh doanh cung cấp file.                             |
| **Đầu vào**         | request_no, customer_name, customer_address, sku_code, required_qty.  |
| **Tiền điều kiện**  | Đúng template; SKU active; source key/checksum.                       |
| **Đầu ra/Kết thúc** | Import VALIDATED/REJECTED; outbound DRAFT và line matched.            |

| **Bước** | **Actor** | **Hoạt động**                                         | **Kết quả hệ thống**                      | **Rule/Guard** |
|----------|-----------|-------------------------------------------------------|-------------------------------------------|----------------|
| **01**   | NV kho    | Upload .xlsx.                                         | Tạo import batch và validate row/field.   | BR-EXCEL       |
| **02**   | Hệ thống  | Resolve SKU, qty, customer/address; group request_no. | Preview + error list.                     | BR-EXCEL       |
| **03**   | NV kho    | Xác nhận consume file hợp lệ.                         | Tạo outbound DRAFT; match SKU + quantity. | BR-MATCH       |
| **04**   | Hệ thống  | Ghi source key/checksum.                              | Import lặp cùng nội dung không tạo trùng. | BR-IDEMP       |

### Ngoại lệ bắt buộc

| **Tình huống**                        | **Cách xử lý**                                 |
|---------------------------------------|------------------------------------------------|
| Sai template/thiếu trường/SKU invalid | Reject theo row/field; không tạo outbound.     |
| Duplicate source key/checksum         | Replay kết quả hoặc conflict nếu payload khác. |

## BP-WMS-05 - Xuất sản phẩm, in nhãn và map RFID

| **Thuộc tính**      | **Nội dung**                                                        |
|---------------------|---------------------------------------------------------------------|
| **Mục tiêu**        | Scan item đúng toa, in nhãn từng item, map RFID và full Post Issue. |
| **Trigger**         | Outbound DRAFT từ toa Excel.                                        |
| **Actor**           | NV kho/người có quyền.                                              |
| **Đầu vào**         | Request snapshot, SKU/qty, item Barcode/QR.                         |
| **Tiền điều kiện**  | Đủ tồn; printer configured; SKU matched.                            |
| **Đầu ra/Kết thúc** | Nhãn dán; item RFID mapped; ISSUE_POSTED; outbound POSTED.          |

| **Bước** | **Actor**      | **Hoạt động**                                  | **Kết quả hệ thống**                     | **Rule/Guard** |
|----------|----------------|------------------------------------------------|------------------------------------------|----------------|
| **01**   | NV kho         | Mở phiếu và kiểm tra customer/address/SKU/qty. | DRAFT/SCANNING; stock-neutral.           | BR-MATCH       |
| **02**   | NV kho         | Quét Barcode/QR bằng camera.                   | Gán item vào line cùng SKU còn thiếu.    | BR-CAM/MATCH   |
| **03**   | Hệ thống       | Tạo Label_Snapshot và direct print.            | Một nhãn/item; không SĐT; stock-neutral. | BR-LABEL       |
| **04**   | NV kho         | Dán nhãn; In lại nếu lỗi/hỏng.                 | Audit print/reprint.                     | BR-LABEL       |
| **05**   | NV kho         | Gắn/map RFID.                                  | Không tạo movement.                      | BR-RFID        |
| **06**   | Người có quyền | Post khi full match + stock + RFID.            | Giảm tồn atomic; POSTED.                 | BR-FULL-OUT    |

### Ngoại lệ bắt buộc

| **Tình huống**             | **Cách xử lý**                               |
|----------------------------|----------------------------------------------|
| Printer/agent lỗi          | Giữ item scanned; PRINT_FAILED; hiện In lại. |
| Sai SKU/trùng/đã xuất      | Reject trước label.                          |
| Thiếu RFID hoặc thiếu line | Chặn Post.                                   |

## BP-WMS-06 - Xuất linh kiện cho bảo hành

| **Thuộc tính**      | **Nội dung**                                                |
|---------------------|-------------------------------------------------------------|
| **Mục tiêu**        | Trừ tồn linh kiện chỉ khi phục vụ một warranty case active. |
| **Trigger**         | Case cần linh kiện sửa chữa.                                |
| **Actor**           | NV bảo hành/NV kho được quyền.                              |
| **Đầu vào**         | warranty_case_id, purpose WARRANTY, SKU/serial/box/qty.     |
| **Tiền điều kiện**  | Case active; đủ tồn.                                        |
| **Đầu ra/Kết thúc** | Component Issue POSTED, link case, movement trừ tồn.        |

| **Bước** | **Actor**      | **Hoạt động**                         | **Kết quả hệ thống**        | **Rule/Guard** |
|----------|----------------|---------------------------------------|-----------------------------|----------------|
| **01**   | Người dùng     | Chọn warranty case active.            | Reject RETURNED/CANCELLED.  | BR-COMP-WAR    |
| **02**   | Người dùng     | Tạo Component Issue purpose WARRANTY. | warranty_case_id bắt buộc.  | BR-COMP-WAR    |
| **03**   | Người dùng     | Scan serial hoặc box + quantity.      | Validate SKU/stock.         | BR-COMP        |
| **04**   | Người có quyền | Post toàn bộ.                         | Trừ tồn, link case, POSTED. | BR-IDEMP/TXN   |

### Ngoại lệ bắt buộc

| **Tình huống**              | **Cách xử lý** |
|-----------------------------|----------------|
| purpose SALE/AGENCY/OTHER   | Chặn.          |
| Case inactive/không tồn tại | Chặn.          |
| Không đủ tồn                | Chặn âm kho.   |

## BP-WMS-07 - Tra cứu tồn và đối soát (không kiểm kê)

| **Thuộc tính**      | **Nội dung**                                                     |
|---------------------|------------------------------------------------------------------|
| **Mục tiêu**        | Tra cứu balance/item/container/movement và phát hiện divergence. |
| **Trigger**         | Người dùng tra cứu hoặc reconciliation job chạy.                 |
| **Actor**           | QL kho/Admin/NV kho theo quyền.                                  |
| **Đầu vào**         | SKU/code/status/movement.                                        |
| **Tiền điều kiện**  | Dữ liệu posting hiện hành.                                       |
| **Đầu ra/Kết thúc** | PASS hoặc incident; không có stocktake/adjustment.               |

| **Bước** | **Actor**      | **Hoạt động**                               | **Kết quả hệ thống**                           | **Rule/Guard** |
|----------|----------------|---------------------------------------------|------------------------------------------------|----------------|
| **01**   | Người dùng     | Tra cứu SKU/item/carton/box/tray/code.      | Hiển thị Balance + subledger + movement chain. | BR-TRACE       |
| **02**   | Job/System     | So ledger, Balance và subledger.            | PASS hoặc divergence incident.                 | DEC-INV        |
| **03**   | QL kho/Tech    | Điều tra source.                            | Không tự update/delete movement.               | BR-RECON       |
| **04**   | Người có quyền | Nếu sai giao dịch, tạo reversal/correction. | Append-only, có audit.                         | BR-REV         |

### Ngoại lệ bắt buộc

| **Tình huống**             | **Cách xử lý**                                      |
|----------------------------|-----------------------------------------------------|
| Divergence không rõ nguồn  | Tạo incident, chặn sign-off phạm vi liên quan.      |
| Sai chứng từ xác định được | Reversal/correction; không dùng adjustment kiểm kê. |

## BP-WMS-08 - Bảo hành máy có mã

| **Thuộc tính**      | **Nội dung**                                                                                  |
|---------------------|-----------------------------------------------------------------------------------------------|
| **Mục tiêu**        | Theo dõi tiếp nhận–xử lý–trả máy có item_code mà không đưa máy vào tồn bán.                   |
| **Trigger**         | Khách/đại lý gửi máy có mã hợp lệ để bảo hành/sửa chữa.                                       |
| **Actor chính**     | NV bảo hành (R), QL kho/Admin (C/A theo quyền).                                               |
| **Đầu vào**         | item_code, customer name/phone, reported defect, phụ kiện/ảnh, ngày nhận.                     |
| **Tiền điều kiện**  | Item ISSUED hoặc RETURNED_TO_CUSTOMER; không có active warranty case khác.                    |
| **Đầu ra/Kết thúc** | Warranty Case RETURNED/CANCELLED; item IN_WARRANTY rồi RETURNED_TO_CUSTOMER; Warranty Events. |

| **Bước** | **Actor**   | **Hoạt động**                                             | **Kết quả hệ thống**                                          | **Rule/Guard** |
|----------|-------------|-----------------------------------------------------------|---------------------------------------------------------------|----------------|
| **01**   | NV bảo hành | Scan/nhập item_code.                                      | Tra cứu item; validate state và active case.                  | BR-WAR-10..13  |
| **02**   | NV bảo hành | Nhập khách/SĐT, bệnh, tình trạng ban đầu và phụ kiện/ảnh. | Validate field bắt buộc; tạo case RECEIVED.                   | BR-WAR-02/04   |
| **03**   | Hệ thống    | Chuyển item → IN_WARRANTY.                                | Không sinh Inventory_Movement; available không tăng.          | BR-WAR-01/11   |
| **04**   | NV bảo hành | Cập nhật CHECKING → REPAIRING → COMPLETED.                | Ghi Warranty_Event và kết quả/confirmed defect.               | State model    |
| **05**   | NV bảo hành | Return to customer.                                       | Case → RETURNED; item → RETURNED_TO_CUSTOMER; không IN_STOCK. | BR-WAR-05/13   |

### Ngoại lệ bắt buộc

| **Tình huống**                     | **Cách xử lý**                                                                   |
|------------------------------------|----------------------------------------------------------------------------------|
| Item không tồn tại                 | Chuyển sang quy trình TEMP_ONLY nếu không thể định danh.                         |
| Item đang IN_STOCK                 | Không dùng warranty flow để lấy hàng kho; cần rule/nghiệp vụ riêng.              |
| Active case đã tồn tại             | Chặn vì item đã có active warranty case; không tạo case thứ hai.                 |
| Chuyển RETURNED khi chưa COMPLETED | Chặn.                                                                            |
| Muốn đưa lại hàng vào tồn bán      | Không tự động; phải có nghiệp vụ kiểm định/nhập/điều chỉnh riêng được phê duyệt. |

## BP-WMS-09 - Bảo hành TEMP_ONLY

| **Thuộc tính**      | **Nội dung**                                                                                           |
|---------------------|--------------------------------------------------------------------------------------------------------|
| **Mục tiêu**        | Quản lý máy mất/hỏng mã hoặc không thể truy vết bằng mã tạm, tránh liên kết sai với máy đã bán.        |
| **Trigger**         | Khách gửi máy nhưng không có/không đọc được/không nhớ item_code.                                       |
| **Actor chính**     | NV bảo hành (R), QL kho/Admin (C).                                                                     |
| **Đầu vào**         | Customer, phone, reported defect, missing_code_reason, manual description, ảnh nếu có, model nếu biết. |
| **Tiền điều kiện**  | Không thể xác nhận item gốc một cách đáng tin cậy.                                                     |
| **Đầu ra/Kết thúc** | Warranty Case TEMP_ONLY với temporary_item_code; sales_trace_status=NOT_TRACEABLE.                     |

| **Bước** | **Actor**   | **Hoạt động**                                               | **Kết quả hệ thống**                                                               | **Rule/Guard** |
|----------|-------------|-------------------------------------------------------------|------------------------------------------------------------------------------------|----------------|
| **01**   | NV bảo hành | Chọn “Tạo case không có mã/TEMP_ONLY”.                      | Bật bộ field bắt buộc cho case không định danh.                                    | BR-WAR-07      |
| **02**   | NV bảo hành | Nhập khách/SĐT, bệnh, lý do mất mã, mô tả nhận diện và ảnh. | Model có thể UNKNOWN_MODEL; không suy đoán item.                                   | BR-WAR-08/09   |
| **03**   | Hệ thống    | Sinh temporary_item_code và warranty_case_code.             | item_code null; identification_status=TEMP_ONLY; sales_trace_status=NOT_TRACEABLE. | REQ-SRS-016    |
| **04**   | NV bảo hành | Xử lý CHECKING → REPAIRING → COMPLETED.                     | Ghi event theo mã tạm.                                                             | State model    |
| **05**   | NV bảo hành | Đối chiếu mã tạm + khách/SĐT + mô tả/ảnh và trả máy.        | Case → RETURNED; không cập nhật Item_Instance hoặc tồn.                            | BR-WAR-08      |

### Ngoại lệ bắt buộc

| **Tình huống**                    | **Cách xử lý**                                                                              |
|-----------------------------------|---------------------------------------------------------------------------------------------|
| Nhân viên cố link theo phỏng đoán | Không cho auto-link; giữ NOT_TRACEABLE.                                                     |
| Thiếu missing_code_reason/mô tả   | Không cho lưu.                                                                              |
| Khách mất mã tạm                  | Tra cứu bằng case code/SĐT/mô tả/ảnh theo quyền.                                            |
| Sau này tìm lại item gốc          | Chỉ link bằng quy trình correction có quyền/audit và bằng chứng; không sửa lịch sử âm thầm. |

## BP-WMS-10 - Reversal/Correction sau Post

| **Thuộc tính**      | **Nội dung**                                                                                                                                                             |
|---------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Mục tiêu**        | Sửa sai giao dịch đã post bằng chứng từ append-only, giữ nguyên lịch sử và chống đảo lặp.                                                                                |
| **Trigger**         | Phát hiện Post Receipt/Issue sai hoặc hàng đã xuất quay lại kho.                                                                                                         |
| **Actor chính**     | Chị Phương – QL kho (A); người có inventory.reversal.post (R); NV kho cung cấp evidence.                                                                                 |
| **Đầu vào**         | Original document/movement, reversal type, quantity, reason, condition hàng, khóa yêu cầu nghiệp vụ duy nhất.                                                            |
| **Tiền điều kiện**  | Giao dịch gốc POSTED; còn reversible_qty; quyền hợp lệ; dependency downstream đã được khóa/đảo theo thứ tự; hàng quay lại có evidence vật lý và condition được xác định. |
| **Đầu ra/Kết thúc** | Reversal POSTED append-only; movement ngược; Balance/subledger và report net effect cập nhật; correction_chain/original reference đầy đủ.                                |

| **Bước** | **Actor**               | **Hoạt động**                                                      | **Kết quả hệ thống**                                                                                                                      | **Rule/Guard**         |
|----------|-------------------------|--------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------|------------------------|
| **01**   | Người có quyền reversal | Tạo request, chọn movement gốc, reason, line/qty và reversal type. | DRAFT; tạo correction_chain_id; không sửa/xóa document/movement gốc.                                                                      | BR-REV-01/02           |
| **02**   | Hệ thống                | Tính reversible_qty và khóa chain.                                 | reversible_qty = original_qty − tổng qty reversal POSTED; chặn vượt qty và concurrent duplicate.                                          | BR-REV-04/05           |
| **03**   | Hệ thống/QL kho         | Kiểm tra downstream dependency.                                    | Reverse Receipt bị chặn nếu item/qty đã Issue/Warranty/Adjustment; phải đảo dependency theo thứ tự ngược hoặc mở correction chain hợp lệ. | BR-REV-06/07           |
| **04**   | NV kho + QL kho         | Xác nhận handoff vật lý và condition với Reverse Issue.            | PENDING_RETURN cho đến khi hàng về; SELLABLE mới cộng available; DEFECTIVE/NON_SELLABLE vào condition/status riêng.                       | BR-REV-03/08           |
| **05**   | Người có quyền reversal | Confirm/Post với business request key duy nhất.                    | Atomic: movement ngược, Balance/subledger/item state, reversal POSTED; rollback toàn bộ nếu một bước lỗi.                                 | DEC-INV-001; BR-REV-09 |
| **06**   | Hệ thống/QL kho         | Đối soát và cập nhật báo cáo.                                      | Ledger–Balance–Subledger khớp; report hiển thị original, reversal, correction chain và net effect.                                        | BR-REV-10/11           |
| **07**   | QL kho                  | Sửa tiếp nếu reversal đã POSTED sai.                               | Không reverse trực tiếp reversal; tạo correction/reversal mới link correction_chain và giữ full audit.                                    | BR-REV-12              |

### Ngoại lệ bắt buộc

| **Tình huống**                                          | **Cách xử lý**                                                                     |
|---------------------------------------------------------|------------------------------------------------------------------------------------|
| Retry cùng key/cùng payload                             | Replay kết quả cũ; không sinh movement/stock effect lần hai.                       |
| Cùng key nhưng payload khác                             | Chặn request identity conflict; stock effect = 0.                                  |
| Reverse Receipt có downstream Issue/Warranty/Adjustment | Chặn; đảo dependency theo thứ tự ngược hoặc xử lý correction chain được phê duyệt. |
| Reverse Issue nhưng hàng chưa về                        | Giữ PENDING_RETURN; không cộng IN_STOCK/available.                                 |
| Hàng về DEFECTIVE/NON_SELLABLE                          | Ghi condition/status riêng; không cộng sellable available; report tách condition.  |
| Reverse-of-reversal                                     | Không thao tác trực tiếp trên reversal POSTED; tạo correction mới link chain.      |
| Hai request reversal đồng thời                          | Lock chain/movement; chỉ một request commit.                                       |
| Reconciliation/report mismatch                          | Tạo incident; block sign-off; không update/delete ledger gốc.                      |

## BP-WMS-11 - Publish và cung cấp tồn cho App PV

| **Thuộc tính**      | **Nội dung**                                                                                   |
|---------------------|------------------------------------------------------------------------------------------------|
| **Mục tiêu**        | Publish catalog read-only và available_qty; zero-stock vẫn hiển thị Chưa có hàng.              |
| **Trigger**         | Admin/QL kho publish sản phẩm hoặc tồn kho thay đổi sau posting.                               |
| **Actor chính**     | Admin/QL kho (publish), App PV/User (read).                                                    |
| **Đầu vào**         | PV Product Profile, published flag, ngành nghề/tag, Inventory_Balance available_qty.           |
| **Tiền điều kiện**  | SKU active; profile đầy đủ; người dùng có quyền publish hoặc đọc public.                       |
| **Đầu ra/Kết thúc** | Danh sách/chi tiết/recommendation; available_qty, Còn hàng/Hết hàng và last_inventory_sync_at. |

| **Bước** | **Actor**    | **Hoạt động**                                                 | **Kết quả hệ thống**                                                                                                        | **Rule/Guard**                                        |
|----------|--------------|---------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------|-------------------------------------------------------|
| **01**   | Admin/QL kho | Cấu hình profile, ảnh, thông số, ngành nghề, tags và publish. | Chỉ active SKU mới published.                                                                                               | PV-VIS-001                                            |
| **02**   | Người xem    | Mở list/search/filter/recommendation.                         | Chỉ trả product active + published; không lộ item/container/movement.                                                       | REQ-SRS-017/019                                       |
| **03**   | Hệ thống     | Đọc available_qty từ Balance kho tổng.                        | Hiển thị số và trạng thái: \>0 Còn hàng; =0 Hết hàng.                                                                       | REQ-SRS-018                                           |
| **04**   | Hệ thống     | Đồng bộ PV cache/read model sau publish hoặc action đổi tồn.  | Freshness target ≤ 60 giây trong điều kiện bình thường; ghi last_inventory_sync_at; App PV không reserve hoặc tạo movement. | BPD-PV-FRESH-001; tham chiếu SRS REQ-SRS-018/BR-PV-14 |
| **05**   | Người xem    | Xem chi tiết và phụ kiện tương thích.                         | Read-only; không order/payment/hold/post issue.                                                                             | DR-PLAT-001                                           |

### Ngoại lệ bắt buộc

| **Tình huống**                                 | **Cách xử lý**                                                                                                                                                                                                   |
|------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Sản phẩm inactive/chưa publish                 | Không xuất hiện kể cả deep link.                                                                                                                                                                                 |
| Balance = 0                                    | Hiển thị Hết hàng; không cho giữ/đặt.                                                                                                                                                                            |
| Cache chậm                                     | Nếu tuổi dữ liệu \> 5 phút, vẫn cho xem read-only nhưng phải hiển thị “Dữ liệu tồn có thể chậm” và last_inventory_sync_at. Nếu chưa từng sync, hiển thị “Tạm thời chưa có dữ liệu tồn”, không suy đoán số lượng. |
| Người dùng cố thực hiện thao tác ghi từ App PV | App PV không cung cấp nghiệp vụ kho/đặt hàng/giữ hàng; từ chối theo permission. Endpoint và technical response thuộc SRS.                                                                                        |
| Profile thiếu dữ liệu hiển thị                 | Không publish hoặc hiển thị empty/placeholder theo rule UI được duyệt.                                                                                                                                           |

## BP-WMS-12 - Báo cáo vận hành

| **Thuộc tính**     | **Nội dung**                                           |
|--------------------|--------------------------------------------------------|
| **Trigger**        | Người dùng xem/export báo cáo hoặc kỳ báo cáo đến hạn. |
| **Actor chính**    | QL kho/Admin/Lãnh đạo; NV kho view nếu được cấp.       |
| **Tiền điều kiện** | Người dùng có quyền report.view; dữ liệu đã post.      |

| **Bước** | **Actor**  | **Hoạt động**                                                         | **Kết quả hệ thống**                                    | **Rule/Guard**    |
|----------|------------|-----------------------------------------------------------------------|---------------------------------------------------------|-------------------|
| **01**   | Người dùng | Chọn báo cáo và filter thời gian/SKU/category/status/agency nơi nhận. | Validate quyền và phạm vi.                              | Permission matrix |
| **02**   | Hệ thống   | Tính tồn đầu kỳ/nhập/xuất/adjust/reversal/cuối kỳ từ ledger.          | Không coi agency là kho; filter agency chỉ là nơi nhận. | BR-SCOPE-04       |
| **03**   | Hệ thống   | Hiển thị tồn hiện tại từ Balance kèm location hint.                   | Không double count container.                           | BR-CON-04         |
| **05**   | Hệ thống   | Hiển thị bảo hành theo SKU/model/defect/status/date.                  | TEMP_ONLY có thể UNKNOWN_MODEL; không suy đoán item.    | REQ-SRS-016       |
| **06**   | Người dùng | Export Excel/PDF nếu thuộc release.                                   | Giữ filter, thời gian tạo và người xuất.                | Should            |

### Ngoại lệ bắt buộc

| **Tình huống**           | **Cách xử lý**                                                                        |
|--------------------------|---------------------------------------------------------------------------------------|
| Kỳ báo cáo có divergence | Hiển thị/cảnh báo incident; không che sai lệch.                                       |
| Filter agency            | Chỉ thống kê xuất cho agency, không tồn agency.                                       |
| Dữ liệu chưa post        | Không tính vào báo cáo tồn/XNT.                                                       |
| Người dùng ngoài scope   | Từ chối do ngoài quyền/phạm vi; cách biểu diễn kỹ thuật theo SRS/permission contract. |

## BP-WMS-13 - Migration và Opening Balance

| **Thuộc tính**      | **Nội dung**                                                                                                                                                     |
|---------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Mục tiêu**        | Chuyển dữ liệu ban đầu từ Excel/kiểm đếm vào hệ thống có dry-run, reconciliation và rollback.                                                                    |
| **Trigger**         | Chuẩn bị UAT/cutover/go-live hoặc nạp master/opening stock.                                                                                                      |
| **Actor chính**     | Data Owner: Chị Phương – Quản lý kho Hoa Nam; BA, Tech Lead/DEV, QA, PM/PO.                                                                                      |
| **Đầu vào**         | Import Package v1.1 tại WWHN-333 attachment 11385; actual Excel/CSV SKU/item/chip/container/agency/opening stock được version/checksum và kiểm soát tại WWHN-69. |
| **Tiền điều kiện**  | Template và owner dữ liệu xác định; backup/rollback plan; code format và unique rule sẵn sàng.                                                                   |
| **Đầu ra/Kết thúc** | BPD design baseline; execution output tại WWHN-69 gồm Migration Batch, opening balance, checksum, error file, reconciliation và sign-off.                        |

| **Bước** | **Actor**        | **Hoạt động**                                                                                 | **Kết quả hệ thống**                                                                                                               | **Rule/Guard**               |
|----------|------------------|-----------------------------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------|------------------------------|
| **01**   | Chị Phương/BA    | Bàn giao Import Template và file dữ liệu theo version; xác định source of truth từng dataset. | Ghi owner, file/version, received_at và SHA-256 tại WWHN-69. Actual file chưa có không chặn BPD Done.                              | BPD-MIG-CTRL-001             |
| **02**   | Tech/BA          | Chạy controlled migration job/script ở chế độ dry-run.                                        | Không đổi dữ liệu thật; xuất valid/invalid/duplicate counts và error file.                                                         | DEC-MIG-API-001; WWHN-69     |
| **03**   | QL kho/BA        | Sửa dữ liệu hoặc xác nhận waiver cho lỗi được phép.                                           | Không commit nếu critical error \> 0 hoặc total pass rate \< 99,5%; mọi waiver non-critical phải có owner/reason/evidence.         | AC-SRS-021                   |
| **04**   | Tech/QL kho      | Commit bằng controlled job/script trong cửa sổ cutover.                                       | Tạo opening data/movement theo thiết kế; audit Migration_Batch; không qua public/admin Import API.                                 | DEC-MIG-API-001; DEC-INV-001 |
| **05**   | QL kho/QA        | Đối soát theo SKU/item/container và kiểm đếm thực tế.                                         | Reconcile 5 cấp: record, business key, quantity theo SKU, item/serial, container mapping; quantity và critical data phải đạt 100%. | BRD P0-8                     |
| **06**   | PM/PO/Khách hàng | Go/No-Go hoặc rollback.                                                                       | Go/No-Go/rollback được thực hiện tại WWHN-69/WWHN-75; reconciliation failed thì No-Go.                                             | REQ-SRS-022                  |

### Ngoại lệ bắt buộc

| **Tình huống**            | **Cách xử lý**                                                                      |
|---------------------------|-------------------------------------------------------------------------------------|
| Duplicate item/chip/code  | Không commit dòng lỗi; đưa error file.                                              |
| SKU cũ không map được     | Giữ pending/error; không tạo SKU suy đoán.                                          |
| Excel lệch kiểm đếm       | Dùng kiểm đếm được QL kho ký xác nhận làm source of truth theo quyết định hiện tại. |
| Reconciliation không khớp | No-Go/rollback.                                                                     |
| File thay đổi sau dry-run | Chạy lại bằng checksum/version mới.                                                 |

# 6. STATE MODEL VÀ STOCK EFFECT

| **Object**                | **From**       | **Action/Guard**                         | **To**    | **Stock effect**  | **Rule**                                 |
|---------------------------|----------------|------------------------------------------|-----------|-------------------|------------------------------------------|
| Inbound/Outbound Document | DRAFT          | Bắt đầu scan                             | SCANNING  | 0                 | Cho sửa dữ liệu cho đến khi Post/Cancel  |
| Inbound/Outbound Document | DRAFT/SCANNING | Cancel                                   | CANCELLED | 0                 | Terminal; muốn làm lại thì tạo phiếu mới |
| Inbound Document          | SCANNING       | Post khi mọi scanned_qty=expected_qty    | POSTED    | +N                | Atomic, idempotent; không Post lần hai   |
| Outbound Document         | SCANNING       | Post khi full match + đủ tồn + RFID      | POSTED    | -N                | Atomic, no-negative, concurrency guard   |
| Component Issue           | DRAFT/SCANNING | Post khi active case + đủ tồn            | POSTED    | -N                | purpose phải WARRANTY                    |
| Putaway                   | DRAFT          | Confirm tray/box mapping                 | CONFIRMED | 0                 | Không movement                           |
| Warranty                  | RECEIVED       | Check/Repair/Complete/Return             | RETURNED  | 0 sellable        | Item không tự quay lại IN_STOCK          |
| Reversal                  | DRAFT          | Post khi dependency/qty/condition hợp lệ | POSTED    | Inverse/condition | Giữ original movement; append-only       |
| PV Publish                | UNPUBLISHED    | Publish active profile                   | PUBLISHED | 0                 | Có thể qty=0                             |

| **Action**                        | **Stock effect**    | **Ghi chú**                                                  |
|-----------------------------------|---------------------|--------------------------------------------------------------|
| Create/Edit/Scan Inbound/Outbound | None                | Không movement/balance change.                               |
| Post Receipt                      | Increase            | Movement + Balance + Item/Box subledger cùng transaction.    |
| Putaway                           | None                | Chỉ mapping box-tray/location hint.                          |
| Excel import/match                | None                | Tạo request/document; chưa trừ tồn.                          |
| Label print/reprint               | None                | Audit print attempt; không stock effect.                     |
| RFID assign                       | None                | Map code vào item; không tạo item/movement.                  |
| Post Issue                        | Decrease            | Không âm kho; item -\> ISSUED.                               |
| Post Component Issue              | Decrease            | Chỉ WARRANTY active case.                                    |
| Warranty receive/update/return    | None sellable       | Không cộng lại tồn bán.                                      |
| Reversal Receipt/Issue            | Inverse/conditional | Reverse Issue chỉ cộng available nếu hàng đã về và SELLABLE. |
| Migration Opening Commit          | Increase/opening    | Chỉ sau dry-run + reconciliation PASS.                       |
| PV view/publish                   | None                | Read-only.                                                   |

# 7. BUSINESS RULE VÀ VALIDATION

## Scope/warehouse

- Chỉ 01 warehouse active trong MVP.

- Agency không dùng làm warehouse_id; không Balance/Movement/Stocktake theo Agency.

- zone_code/location_hint/tray chỉ là locator/hint, không tạo stock bucket.

## SKU/catalog

- sku_code unique; PRODUCT/COMPONENT; tracking SERIAL/QUANTITY.

- Không đổi sku_code/tracking mode sau khi có stock/movement.

- Brand/Power Source là master mở; tối thiểu MAINS_DIRECT/BATTERY.

- Catalog active/published độc lập Balance; zero-stock hiển thị Chưa có hàng.

## Physical code/container

- code_value unique; RFID hardware_uid unique khi có.

- ITEM nhận bằng Barcode/QR; PRODUCT bắt buộc active RFID trước Post Issue.

- Một code map đúng một target; mapping RFID không tạo movement.

- Carton/box/tray không double count; box quantity chỉ cho một SKU trong scope hiện hành.

## Full Post

- Post chỉ khi mọi line exact: scanned_qty = expected/required_qty.

- Nếu lệch: sửa DRAFT/SCANNING hoặc Cancel và tạo lại.

- Không remaining_qty, PARTIALLY_POSTED, close remainder.

## Excel/label

- Validate row/field; blocking error không consume/không tạo outbound.

- Duplicate source key/checksum không tạo trùng.

- Label snapshot bất biến, nguồn tên/địa chỉ từ Excel, tên sản phẩm từ catalog, không in SĐT.

- Printer fail phải ghi PRINT_FAILED và cho In lại; không báo thành công im lặng.

## Inventory/concurrency

- Movement append-only authoritative; Balance projection; subledger reconcile.

- Idempotency-Key cùng payload replay; cùng key khác payload conflict.

- Post dùng transaction + lock/version; không negative stock, không gán item hai phiếu.

- Divergence tạo reconciliation incident, không tự sửa ledger.

## Warranty/privacy

- Một active warranty case cho một item.

- TEMP_ONLY không link nhầm item gốc và không movement.

- PII mask theo role; không raw PII trong log; export PII cần quyền/audit.

- Component Issue chỉ purpose=WARRANTY và case active.

## Reversal

- Không sửa/xóa movement gốc; reversal/correction append-only.

- Không vượt reversible_qty; dependency đảo theo thứ tự ngược.

- Reverse Issue chỉ cộng available khi hàng vật lý về và condition SELLABLE.

- Concurrent reversal chỉ một request thành công.

# 8. LOGICAL API / ERROR / PERMISSION BASELINE

| **Nhóm**        | **Logical operations**                                                                | **Permission**                          | **Critical behavior**                              |
|-----------------|---------------------------------------------------------------------------------------|-----------------------------------------|----------------------------------------------------|
| IAM             | GET/POST/PATCH users, roles, permissions                                              | iam.user.\* / iam.role.\*               | If-Match khi update; inactive deny                 |
| Master/Catalog  | agencies, defects, brands, categories, models, power-sources, skus; publish/unpublish | agency/defect/catalog/sku.\*            | Unique, active/inactive, immutable code after use  |
| Camera/Scan     | Client camera decoder -\> scan operation/context; manual input dùng cùng validation   | Permission scan theo context            | Secure context; denied/unavailable/timeout rõ ràng |
| Inbound         | create/update/scan/post/cancel                                                        | inbound.\*                              | Full-post; Idempotency-Key + If-Match              |
| Outbound Import | upload/validate/preview/errors/consume Excel                                          | outbound.import                         | No partial commit; source key/checksum             |
| Outbound        | create from request/scan/match/assign RFID/post/cancel                                | outbound.\* / physical_code.assign_rfid | Full match + availability + RFID                   |
| Label           | status/reprint/print logs                                                             | label.print / label.reprint             | Snapshot immutable; print stock-neutral            |
| Component Issue | create/scan/post/cancel linked warranty case                                          | warranty.component_issue                | purpose WARRANTY + active case                     |
| Inventory       | balance/trace/movement/reconciliation incident                                        | inventory.view / inventory.trace        | No stocktake operations                            |
| Reversal        | create/check/post                                                                     | inventory.reversal.post                 | Dependency, condition, reversible qty, idempotency |
| Warranty        | receive/update/complete/return/TEMP_ONLY                                              | warranty.manage                         | PII scope + one active case                        |
| Reports/PV      | reports/export; PV list/detail/filter/recommendation                                  | report.\* / pv.\*                       | Published only; available_qty read-only            |
| Migration       | Controlled job/script only                                                            | Operational access                      | Không public/admin import endpoint                 |

## 8.1. HTTP/Error contract

| **HTTP** | **Khi dùng**                                    | **Expected client behavior**                 |
|----------|-------------------------------------------------|----------------------------------------------|
| 400      | Malformed request/file                          | Hiển thị lỗi request; không retry mù.        |
| 401      | Chưa xác thực                                   | Yêu cầu đăng nhập.                           |
| 403      | Thiếu permission                                | Không write; UI permission denied.           |
| 404      | Không tồn tại hoặc masked out-of-scope          | Không lộ resource ngoài scope.               |
| 409      | State/business/idempotency/concurrency conflict | Refresh/resolve; không tạo stock effect.     |
| 412      | If-Match stale                                  | Reload phiên bản mới.                        |
| 422      | Field/business validation                       | Hiển thị row/field/error code.               |
| 428      | Thiếu If-Match                                  | Gửi version bắt buộc.                        |
| 500      | Unexpected server failure                       | Rollback; correlation/request_id để support. |

| **OpenAPI gate —** Các operation trên là logical baseline. Tech/BE phải phát hành machine-readable OpenAPI có version/hash/changelog, xóa obsolete partial/stocktake operation và gắn evidence tại WWHN-31/WWHN-40. |
|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|

# 9. SCREEN / UX BASELINE

| **Screen ID**  | **Màn hình**         | **Controls/Actions**                                                              | **Critical UX rule**               |
|----------------|----------------------|-----------------------------------------------------------------------------------|------------------------------------|
| SCR-IAM-01     | User/Role/Permission | Search, status, assign role/permission, deactivate                                | No self-escalation; audit          |
| SCR-MST-01     | SKU/Catalog/Master   | Brand/category/model/power source/tracking/publish                                | Immutable code/mode after stock    |
| SCR-AGY-01     | Agency               | CRUD/deactivate recipient                                                         | No warehouse/balance fields        |
| SCR-IN-01      | Inbound              | DRAFT, expected qty, camera/manual scan, exact progress, Post/Cancel              | No partial/remaining UI            |
| SCR-PTW-01     | Putaway              | Scan box/tray, location hint, confirm                                             | No stock effect                    |
| SCR-IMP-OUT-01 | Excel Import         | Upload, preview, row errors, consume                                              | Blocking errors stop consume       |
| SCR-OUT-01     | Outbound             | Request snapshot, matching progress, camera scan, label status, RFID status, Post | Post disabled until full + RFID    |
| SCR-LBL-01     | Print/Reprint        | Snapshot preview, printer health, attempt/result                                  | No SĐT; failed must be visible     |
| SCR-COMP-01    | Component Issue      | Select active case, purpose WARRANTY, scan/qty, Post                              | Reject other purpose/inactive case |
| SCR-INV-01     | Inventory/Trace      | Balance, items/box, containers, movement chain, incident                          | No stocktake menu                  |
| SCR-REV-01     | Reversal             | Original, dependency, reversible qty, condition, reason, Post                     | Append-only; concurrency guard     |
| SCR-WAR-01     | Warranty             | IDENTIFIED/TEMP_ONLY, PII, defect, status/events, attachments                     | PII masking; no stock effect       |
| SCR-RPT-01     | Reports/Export       | Date/SKU/status/recipient filters; export                                         | Same filter/scope; audit           |
| SCR-PV-ADM-01  | PV Admin             | Profile/image/tag/industry/publish                                                | Inactive not published             |
| SCR-PV-01      | Public PV            | List/detail/search/filter/recommendation/qty/data_as_of                           | Read-only; zero-stock shown        |

## 9.1. Trạng thái giao diện bắt buộc

- Loading, empty, success, error/retry, validation, permission denied và stale/concurrency conflict phải có trạng thái riêng.

- Màn camera phải hiển thị permission denied/no camera/unsupported/timeout và nút nhập tay.

- Màn Excel phải có preview và row-level error (row, field, error_code, message).

- Màn printer phải hiển thị agent/printer health, PENDING_PRINT/PRINTED/PRINT_FAILED và In lại.

- Màn Post phải thể hiện exact progress và lý do chưa đủ điều kiện.

# 10. LOGICAL DATA MODEL - TÓM TẮT

| **Module**          | **Entity nhóm**                                                              | **Critical rule**                                     |
|---------------------|------------------------------------------------------------------------------|-------------------------------------------------------|
| IAM/Control         | User, Role, Permission, Idempotency, Audit                                   | Backend enforcement; replay guard; append-only audit. |
| Warehouse/Agency    | Warehouse, Agency                                                            | 01 warehouse; Agency recipient only.                  |
| Catalog/Master      | SKU, Brand, Category, Model, Power Source, Defect, Alias                     | Catalog may exist without balance.                    |
| Item/Code/Container | Item_Instance, Physical_Code, Container, Container_Item, Putaway, Scan_Event | Barcode/RFID map target; no double count.             |
| Inbound             | Inbound Document/Line/Scan                                                   | Full-post; scan stock-neutral.                        |
| Inventory           | Movement, Balance, Posting Event, Reconciliation Incident                    | Ledger authoritative; projection/subledger reconcile. |
| Outbound Excel      | Import, Row/Error, Request/Line                                              | Validate and create outbound.                         |
| Outbound/Label      | Document/Line/Match, Label Snapshot, Print Event, Printer Config             | Scan-to-label then RFID then Post.                    |
| Warranty/Component  | Warranty Case/Event/Attachment, Component Issue Document/Line/Item           | Component only WARRANTY active case.                  |
| PV                  | Product Profile/Image, Industry, Tag, Mapping, Recommendation Rule           | Published read model; zero-stock.                     |
| Migration           | Migration Run/Error                                                          | Operational-only evidence.                            |

| **Physical ERD —** Danh sách bảng, từng field, PostgreSQL type, nullable, PK/FK, unique/index và constraint nằm trong file riêng HN-WMS-ERD-DD-001 v1.0. Các chi tiết physical type/index/DDL là TECH-PROPOSAL cần Tech Lead/BE sign-off. |
|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|

# 11. NON-FUNCTIONAL REQUIREMENTS

| **NFR ID**      | **Requirement**                                                                      | **Measure/Test**                                                                                                                                                                                                             |
|-----------------|--------------------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **NFR-SRS-001** | All stock-changing actions idempotent.                                               | Retry 3 times cùng key/payload = một movement/balance change; TC-NFR-001.                                                                                                                                                    |
| **NFR-SRS-002** | Atomic transaction cho document/movement/balance/subledger.                          | Injected failure rollback all; TC-NFR-002.                                                                                                                                                                                   |
| **NFR-SRS-003** | Concurrency prevents negative stock and duplicate reversal.                          | Parallel requests: one success; others conflict/insufficient; TC-NFR-003.                                                                                                                                                    |
| **NFR-SRS-004** | Audit import/scan/print/reprint/RFID assign/Post/reversal/permission/master changes. | Actor/time/reason/request_id/result/before-after where allowed; TC-NFR-004.                                                                                                                                                  |
| **NFR-SRS-005** | Security enforced by backend permission and scope.                                   | 403/404 policy; negative endpoint tests; TC-NFR-005.                                                                                                                                                                         |
| **NFR-SRS-006** | Responsive/browser/device matrix.                                                    | Desktop \>=1280px; tablet \>=768px; mobile \>=360px; Chrome/Edge latest 2 versions, Chrome Android latest 2, Safari iOS latest 2; TC-NFR-006.                                                                                |
| **NFR-SRS-007** | Scan throughput và feedback latency.                                                 | Batch \<=500 mã; batch P95 \<=5 giây; single-scan UI feedback P95 \<=500ms và end-to-end classify P95 \<=1.5 giây trong test environment; TC-NFR-007.                                                                        |
| **NFR-SRS-008** | Observability.                                                                       | X-Request-Id echo/log; stock action log includes idempotency/result; TC-NFR-008.                                                                                                                                             |
| **NFR-SRS-009** | Read/list API latency.                                                               | P95 \<=2 giây, P99 \<=4 giây với page_size\<=50 và approved dataset; TC-NFR-009.                                                                                                                                             |
| **NFR-SRS-010** | Stock action latency và concurrency.                                                 | P95 \<=3 giây; tối thiểu 20 concurrent stock actions và 50 concurrent read users; không âm kho/double movement; TC-NFR-010.                                                                                                  |
| **NFR-SRS-011** | Availability và backup.                                                              | Availability \>=99.5% theo tháng, loại trừ planned maintenance đã thông báo; daily backup; restore test mỗi quý hoặc trước go-live; TC-NFR-011.                                                                              |
| **NFR-SRS-012** | Recovery Point Objective.                                                            | RPO \<=24 giờ; TC-NFR-012.                                                                                                                                                                                                   |
| **NFR-SRS-013** | Recovery Time Objective.                                                             | RTO \<=8 giờ cho dịch vụ core sau sự cố được phân loại; TC-NFR-013.                                                                                                                                                          |
| **NFR-SRS-014** | Retention audit/movement.                                                            | Giữ tối thiểu 5 năm hoặc lâu hơn nếu hợp đồng/pháp luật yêu cầu; không hard-delete ledger; TC-NFR-014.                                                                                                                       |
| **NFR-SRS-015** | Privacy, masking và warranty PII retention.                                          | Full tên/SĐT/địa chỉ chỉ cho warranty.pii.view; masked by default; purchase_date theo scope; export PII cần warranty.pii.export; không raw PII trong app log; retention 5 năm sau đóng case rồi anonymize/purge; TC-NFR-015. |
| **NFR-SRS-016** | Export safety và cache staleness.                                                    | Export private/audited/scope-correct; App PV inventory cache max staleness \<=60 giây, hiển thị data_as_of; TC-NFR-016.                                                                                                      |
| **NFR-SRS-017** | Migration/import capacity.                                                           | Dry-run tối thiểu 10,000 rows/file; error file đầy đủ row/field/code/message; no commit khi critical error \>0; TC-NFR-017.                                                                                                  |
| **NFR-SRS-018** | Retention/purge execution.                                                           | Audit/movement \>=5 years; warranty PII purge/anonymize job có report, retry và legal-hold exclusion; TC-NFR-018.                                                                                                            |
| **NFR-SRS-017** | Responsive camera                                                                    | 360px+; Chrome Android, Safari iOS, Chrome/Edge desktop; HTTPS permission.                                                                                                                                                   |
| **NFR-SRS-018** | Camera feedback                                                                      | Single-code decode P95 \<=1.5s on approved device/environment; manual fallback always available.                                                                                                                             |
| **NFR-SRS-020** | Direct print                                                                         | Scan-to-print command P95 \<=2s excluding printer mechanics; failure observable/retryable; no silent success.                                                                                                                |

## 11.1. Các chỉ tiêu bổ sung cần DEV/QA giữ

| **NFR**            | **Target**                                                                                              |
|--------------------|---------------------------------------------------------------------------------------------------------|
| Responsive/browser | Tối thiểu 360px; Chrome Android, Safari iOS, Chrome/Edge desktop theo browser matrix được duyệt.        |
| Camera decode      | Single-code feedback P95 \<= 1.5s trên thiết bị/môi trường test đã thống nhất.                          |
| Excel              | Tối thiểu 10.000 rows/file; blocking error không partial commit.                                        |
| Direct print       | Scan-to-print command P95 \<= 2s, không tính cơ học máy in; lỗi quan sát/retry được.                    |
| PV freshness       | Cache staleness \<=60s; hiển thị data_as_of/last_inventory_sync_at; degraded warning nếu stale kéo dài. |
| Retention          | Ledger/audit tối thiểu 5 năm hoặc theo hợp đồng/pháp luật; không hard-delete movement POSTED.           |

# 12. BÁO CÁO VÀ EXPORT

| **Báo cáo**             | **Filter chính**                                  | **Công thức/Rule**                                     |
|-------------------------|---------------------------------------------------|--------------------------------------------------------|
| Tồn kho hiện tại        | warehouse/sku/status; available_qty; data_as_of   | Balance projection + reconciliation warning            |
| Nhập-Xuất-Tồn           | date/sku/type/status                              | Opening + Receipt - Issue - Component Issue + Reversal |
| Lịch sử phiếu nhập/xuất | date/document/status/sku/recipient                | DRAFT/SCANNING xem vận hành; chỉ Posted ảnh hưởng tồn  |
| Movement/Trace          | sku/item/code/movement chain                      | Original + reversal/correction + net effect            |
| Excel Import/Match      | batch/request/status/error/sku                    | Pass rate, error rows, matched qty                     |
| Label/Print             | date/printer/result/reprint                       | Snapshot, attempts, failure/reprint rate               |
| Warranty/Component      | date/defect/status/component sku                  | Case status, defect, component issue trace; PII scope  |
| Xuất theo nơi nhận      | date/agency/sku                                   | Agency là chiều báo cáo, không phải stock              |
| PV Catalog              | published/brand/category/power source/stock state | Zero-stock visibility, freshness                       |

- Excel/PDF export phải dùng đúng filter và data scope của màn hình.

- Export PII bảo hành cần permission riêng và audit.

- Không có báo cáo kiểm kê trong build v1.6.

- Không ẩn movement đã reversal; báo cáo phải thể hiện original, reversal/correction và net effect.

# 13. ACCEPTANCE CRITERIA / TEST DESIGN

| **TC ID**          | **Given/When/Then summary**                                                                  |
|--------------------|----------------------------------------------------------------------------------------------|
| **TC-FULL-IN-01**  | Inbound expected 10; quét 9 -\> Post disabled/422; đủ 10 hợp lệ -\> Post đúng một lần.       |
| **TC-FULL-OUT-01** | Outbound required 5; 5 item đúng SKU + RFID -\> Post; không có partial state.                |
| **TC-CAM-01**      | Permission granted -\> code điền đúng field/context; denied/no camera -\> lỗi rõ + nhập tay. |
| **TC-CAM-02**      | Sai SKU/trùng/đã ISSUED/code không tồn tại -\> reject; không label/movement.                 |
| **TC-EXCEL-01**    | Excel hợp lệ tạo request/outbound đúng snapshot customer/address/SKU/qty.                    |
| **TC-EXCEL-02**    | Row thiếu/sai trả row/field/error; blocking batch không consumed/outbound.                   |
| **TC-EXCEL-03**    | Retry cùng source key/checksum trả cùng kết quả; đổi payload cùng key -\> conflict.          |
| **TC-LABEL-01**    | Scan hợp lệ tạo snapshot không có SĐT và gửi direct print một lần.                           |
| **TC-LABEL-02**    | Printer fail -\> PRINT_FAILED; In lại thành công, reprint_count tăng; không đổi tồn.         |
| **TC-RFID-01**     | Post PRODUCT thiếu active RFID bị chặn; map RFID xong mới Post.                              |
| **TC-COMP-01**     | Component issue không active case hoặc purpose khác WARRANTY bị reject.                      |
| **TC-INV-01**      | Sau Post, Movement = Balance delta = eligible subledger; lệch tạo incident.                  |
| **TC-PV-01**       | Published zero-stock hiển thị 0/Chưa có hàng; filter brand/power source hoạt động.           |
| **TC-NOSTK-01**    | Không có menu/API/permission/entity/report stocktake.                                        |
| **TC-REV-01**      | Không sửa/xóa giao dịch Post; reversal tạo inverse movement, giữ original và audit.          |
| **TC-PRIV-01**     | User thiếu PII permission thấy dữ liệu masked; log/export không lộ raw PII.                  |

## 13.1. Negative/regression bắt buộc

- Retry Post cùng Idempotency-Key/payload không tạo movement/tồn lần hai; cùng key khác payload trả conflict.

- Hai session scan/Post cạnh tranh không gán một item hai lần, không âm kho và không duplicate reversal.

- Scan sai SKU, trùng, item đã xuất hoặc code không tồn tại không tạo label/movement.

- Printer reject/agent down không được báo PRINTED; phải cho retry/In lại.

- Không expose route/menu/permission/schema active cho stocktake hoặc partial.

- POSTED document không edit/delete; reversal giữ original/correction chain.

- Agency không có Balance/Movement tăng sau Post Issue.

- Camera/manual input phải dùng cùng server-side validation.

# 14. TRACEABILITY VÀ READINESS

| **Nhóm**             | **Requirement**                | **Process**   | **Evidence/Test focus**                    |
|----------------------|--------------------------------|---------------|--------------------------------------------|
| Foundation/Master    | REQ-SRS-001..005, 026..029,034 | BP-WMS-01     | Unique/inactive/carrier/scope tests        |
| Inbound              | REQ-SRS-006..009               | BP-WMS-02/03  | TC-FULL-IN, camera, idempotency, putaway   |
| Excel/Outbound/Label | REQ-SRS-010..015               | BP-WMS-04/05  | TC-EXCEL/LABEL/RFID/FULL-OUT               |
| Component Warranty   | REQ-SRS-035                    | BP-WMS-06     | TC-COMP + stock/concurrency                |
| Inventory/Reversal   | REQ-SRS-013,023,030            | BP-WMS-07/10  | Reconcile/dependency/condition/concurrency |
| Warranty             | REQ-SRS-016,028                | BP-WMS-08/09  | Identified/TEMP_ONLY/privacy               |
| PV                   | REQ-SRS-017..020,033,036       | BP-WMS-11     | Published/zero-stock/filter/freshness      |
| Reports              | REQ-SRS-031..032               | BP-WMS-12     | Formula/filter/scope/export                |
| Migration/UAT        | REQ-SRS-021..022               | BP-WMS-13     | Dry-run/reconcile/rollback/sign-off        |
| No Stocktake         | REQ-SRS-037                    | Cross-cutting | Absence/regression test                    |

| **Gate**               | **Điều kiện**                                                                                                  |
|------------------------|----------------------------------------------------------------------------------------------------------------|
| Business/SRS baseline  | WWHN-333/334/335 approve current attachment/hash.                                                              |
| Ready for Dev          | Physical ERD/DD + Tech-approved OpenAPI + permission mapping; obsolete partial/stocktake removed.              |
| Ready for FE           | Detailed UX for camera, import preview/error, match progress, print/reprint, RFID status, zero-stock PV.       |
| Ready for QA           | Test Master replaces partial/stocktake tests with FULL/CAM/EXCEL/LABEL/RFID/COMP/PV/REV.                       |
| Ready for UAT          | Excel template, target device/browser, printer model/label size, direct-print service và sample codes đã chốt. |
| Ready for Beta/Go-live | Migration dry-run/reconcile/pass, QA/UAT evidence, backup/rollback, Go/No-Go sign-off.                         |

# 15. CHECKLIST BÀN GIAO VÀ ĐIỂM TECH CẦN CHỐT

| **Workstream** | **Bắt buộc hoàn tất**                                                                                     | **Không được còn**                                         |
|----------------|-----------------------------------------------------------------------------------------------------------|------------------------------------------------------------|
| BE/OpenAPI     | Version/hash/changelog; full-post, import, label, RFID, component warranty, reversal, errors/permissions. | Không còn partial/close-remainder/stocktake operations.    |
| DB/ERD         | Movement append-only, balance projection, subledger, import/match/label/component, constraints/indexes.   | Không stocktake tables; không remaining_qty/partial state. |
| FE Web WMS     | Responsive camera/manual fallback, exact progress, import preview/errors, print/reprint, RFID readiness.  | Không Demo scanner giả định là chức năng chính.            |
| Printer/DevOps | Cơ chế direct-print, agent/service, health/status/retry, printer mapping và label size.                   | Không silent success khi printer reject.                   |
| QA             | TC-FULL/CAM/EXCEL/LABEL/RFID/COMP/INV/PV/REV; permission/privacy/concurrency.                             | Không test case partial/stocktake active.                  |
| PV             | Published zero-stock; filters; available_qty freshness/data_as_of.                                        | Không cart/order/payment/reservation.                      |
| Migration      | Controlled job/script, checksum, dry-run, reconcile, rollback, owner sign-off.                            | Không public/admin recurring import API.                   |

## 15.1. Điểm cần Tech/BE/DevOps chốt trước UAT

- DBMS, exact DDL/index/constraint/partition và ORM mapping; physical schema phải được review độc lập.

- Machine-readable OpenAPI version/hash/changelog và contract-test environment.

- Cơ chế in trực tiếp từ browser: local agent, kiosk hoặc print service; không yêu cầu NV kho nhập lại nội dung nhãn.

- Model máy in, khổ nhãn, driver/agent, mapping máy in và health check.

- Cơ chế/thiết bị map RFID/hardware_uid; camera điện thoại không đọc RFID.

- Browser/device matrix tối thiểu Android + iOS và HTTPS camera permission.

- Availability, backup/RPO/RTO theo technical baseline được phê duyệt.

- File Excel mẫu khách xác nhận và bộ Barcode/QR/RFID mẫu trước UAT.

| **Change Control —** Mọi mở rộng multi-warehouse, order/payment, stocktake, partial, deep RFID integration hoặc advanced label designer phải đi qua Change Request/Decision mới. |
|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
