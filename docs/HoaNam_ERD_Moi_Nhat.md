**HOA NAM TOOLS**

**ERD & DATA DICTIONARY**

Đề xuất physical schema cho BRD v3.13 / SRS-FRD v1.6 / BPD v1.5

  ------------------------------------------------------------------------------------------
  **Thuộc tính**                      **Giá trị**
  ----------------------------------- ------------------------------------------------------
  **Document ID**                     HN-WMS-ERD-DD-001

  **Version**                         1.0

  **Ngày phát hành**                  04/08/2026

  **Trạng thái**                      DRAFT TECHNICAL PROPOSAL - CẦN TECH LEAD/BE SIGN-OFF

  **Owner**                           Business Analyst - Nguyễn Đình Hùng
  ------------------------------------------------------------------------------------------

**Working reference cho DEV / FE / QA / TEST**

## 0.0. Sổ đăng ký sửa đổi ERD (Amendment Register)

Bảng dưới ghi mọi lần sửa `HN-WMS-ERD-DD-001` sau bản phát hành 04/08/2026. Sửa đổi chỉ được
ghi vào đây khi đã có nguồn baseline cao hơn (SRS/FRD v1.12, Decision Record) hoặc khi nội dung
cũ là mô tả không khớp chính hình dạng vật lý mà chính tài liệu này quy định. Không sửa đổi nào
được đổi business semantics đã chốt mà không có Change Request.

  ------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Mã**              **Ngày**       **Bảng / mục**                    **Nội dung sửa**                                                        **Nguồn**
  ------------------- -------------- --------------------------------- ----------------------------------------------------------------------- ----------------
  ERD-AMD-001         15/08/2026     physical_codes; Product outbound  RFID là optional, mapping stock-neutral, thiếu RFID không chặn Post      SRS v1.12
                                     guard                             Issue; Barcode/QR là luồng hợp lệ.                                      §13.9 /
                                                                                                                                               DEC-RFID-
                                                                                                                                               FALLBACK-001

  ERD-AMD-002         16/08/2026     33. reversal_documents            Bổ sung `condition_status`, `physical_return_status`,                    BR-REV-06/07/12
                                                                       `idempotency_key` là cột vật lý thật (trước đó chỉ tồn tại ở view).

  ERD-AMD-003         16/08/2026     41. label_snapshots;              `customer_address` / `recipient_address` chuyển sang nullable: toa mẫu   SRS v1.12 §15 /
                                     39. outbound_documents            không có địa chỉ vẫn phải Post được.                                    REQ-SRS-014

  ERD-AMD-004         17/08/2026     4. user_roles                     Bổ sung `scope_ref`, `effective_from`, `effective_to` và quy tắc cửa sổ  BR-IAM-08
                                                                       hiệu lực.

  ERD-AMD-005         18/08/2026     15. defect_catalog →              Đổi tên entity thành `defect_catalogs` cho khớp bảng vật lý canonical    WWHN-310 /
                                     15. defect_catalogs               và quy ước số nhiều của 58 entity còn lại. `defect_catalog` (số ít) trở  DEC-DATA-001
                                                                       thành compatibility view. Không đổi field/constraint/semantics.

  ERD-AMD-006         18/08/2026     27. inbound_lines;                Viết đúng tên cột vật lý trong mệnh đề UNIQUE thay cho bí danh văn xuôi  WWHN-310 /
                                     38. outbound_request_lines;       `document`/`request`: `UNIQUE(inbound_document_id,line_no)`,             DEC-DATA-001
                                     49. component_issue_lines         `UNIQUE(outbound_request_id,line_no)`,
                                                                       `UNIQUE(component_issue_document_id,line_no)`. Cùng ràng buộc đã có ở
                                                                       cột "Index khuyến nghị", không đổi ràng buộc.
  ------------------------------------------------------------------------------------------------------------------------------------------------------------

Bản trong `WMS_Warehouse_Hoa_Nam_BE/docs/` và `WMS_Warehouse_Hoa_Nam_FE/docs/` phải giống hệt
nhau; lệch bản là blocker của gói bàn giao dữ liệu (WWHN-310).

# 0. PHẠM VI, GIẢ ĐỊNH VÀ QUY ƯỚC

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Ranh giới phê duyệt ---** Entity, requiredness và business constraint bám bộ BA hiện hành. UUID, độ dài varchar, PostgreSQL type, index name, CHECK, partition, trigger và ORM mapping là TECH-PROPOSAL; Tech Lead/BE phải review và sign-off tại technical baseline.
  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

  ---------------------------------------------------------------------------------------------------------------------------------------------------------
  **Hạng mục**                        **Đề xuất**
  ----------------------------------- ---------------------------------------------------------------------------------------------------------------------
  **DBMS reference**                  PostgreSQL 14+; DBMS khác map kiểu tương đương.

  **Primary key**                     uuid, DEFAULT gen_random_uuid(); audit_logs có thể dùng bigserial.

  **Time**                            timestamptz lưu UTC; UI hiển thị timezone người dùng.

  **Quantity**                        bigint vì đơn vị nguyên; nếu có phân số phải CR sang numeric(18,3).

  **Status**                          varchar + CHECK/lookup table; tránh PostgreSQL ENUM để dễ migration.

  **Concurrency**                     version integer + If-Match; stock-changing transaction dùng row lock phù hợp.

  **Audit/retention**                 Movement/audit append-only; tối thiểu 5 năm hoặc theo hợp đồng/pháp luật.

  **Delete policy**                   Master ACTIVE/INACTIVE; document POSTED/movement/audit không hard-delete.

  **PII**                             Warranty PII masked by role; encryption at rest/column strategy do Tech/Security chốt; không raw PII trong app log.

  **Naming**                          snake_case; PK id; FK \<entity\>\_id; timestamp \*\_at; boolean is\_\*.
  ---------------------------------------------------------------------------------------------------------------------------------------------------------

## 0.1. Entity/field bị loại khỏi baseline

-   Không tạo Stocktake_Session, Stocktake_Snapshot, Stocktake_Count, Variance_Line, Adjustment hoặc report/permission tương ứng.

-   Không tạo remaining_qty, cancelled_remainder_qty, PARTIALLY_POSTED, close_remainder hoặc partial posting event.

-   Không tạo Agency Inventory_Balance/Movement; Agency chỉ là recipient master.

-   Không tạo order/cart/payment/reservation tables trong MVP.

-   Không tạo tồn theo zone/rack/bin/tray; location_hint/tray là locator, không stock bucket.

## 0.2. Trạng thái/check constraint chính

  ------------------------------------------------------------------------------------------------------------------
  **Object**                          **Allowed values / Rule**
  ----------------------------------- ------------------------------------------------------------------------------
  Document status                     DRAFT, SCANNING, POSTED, CANCELLED

  Import status                       UPLOADED, VALIDATED, REJECTED, CONSUMED

  Label/Print                         PENDING_PRINT, PRINTED, PRINT_FAILED

  Warranty                            RECEIVED, CHECKING, REPAIRING, COMPLETED, RETURNED, CANCELLED

  Item                                NEW, IN_STOCK, ISSUED, IN_WARRANTY, RETURNED_TO_CUSTOMER, LOST, ADJUSTED_OUT

  SKU type                            PRODUCT, COMPONENT

  Tracking mode                       SERIAL, QUANTITY

  Carrier type                        BARCODE, QR, RFID, NFC, MANUAL

  Container type                      CARTON, BOX, TRAY

  Movement type                       OPENING, RECEIPT, ISSUE, COMPONENT_ISSUE, REVERSAL

  Stock status                        AVAILABLE, NON_SELLABLE, DEFECTIVE, QUARANTINE

  Full-post guard                     Tất cả line scanned_qty = expected_qty/required_qty trước Post.

  Product outbound guard              RFID optional theo SRS v1.12/DEC-RFID-FALLBACK-001: mapping stock-neutral, Barcode/QR là fallback hợp lệ, thiếu RFID không chặn Post Issue.

  Component guard                     purpose=WARRANTY và warranty case active.

  Balance guard                       quantity \>= 0; unique(warehouse_id, sku_id, stock_status).
  ------------------------------------------------------------------------------------------------------------------

# 1. SƠ ĐỒ QUAN HỆ THEO MODULE

+-----------------------------------------------------------------------------------------------------------------------------+
| ![Image: image1.png](HoaNam_ERD_Moi_Nhat_assets/media/image1.png){width="5.066036745406824in" height="5.590551181102362in"} |
|                                                                                                                             |
| *Hình 1. 01 iam*                                                                                                            |
+=============================================================================================================================+
+-----------------------------------------------------------------------------------------------------------------------------+

+-----------------------------------------------------------------------------------------------------------------------------+
| ![Image: image2.png](HoaNam_ERD_Moi_Nhat_assets/media/image2.png){width="7.867347987751531in" height="5.590551181102362in"} |
|                                                                                                                             |
| *Hình 2. 02 catalog physical*                                                                                               |
+=============================================================================================================================+
+-----------------------------------------------------------------------------------------------------------------------------+

+------------------------------------------------------------------------------------------------------------------------------+
| ![Image: image3.png](HoaNam_ERD_Moi_Nhat_assets/media/image3.png){width="9.645669291338583in" height="5.2530402449693785in"} |
|                                                                                                                              |
| *Hình 3. 03 inbound inventory*                                                                                               |
+==============================================================================================================================+
+------------------------------------------------------------------------------------------------------------------------------+

+------------------------------------------------------------------------------------------------------------------------------+
| ![Image: image4.png](HoaNam_ERD_Moi_Nhat_assets/media/image4.png){width="9.645669291338583in" height="2.3845352143482064in"} |
|                                                                                                                              |
| *Hình 4. 04 outbound label*                                                                                                  |
+==============================================================================================================================+
+------------------------------------------------------------------------------------------------------------------------------+

+-----------------------------------------------------------------------------------------------------------------------------+
| ![Image: image5.png](HoaNam_ERD_Moi_Nhat_assets/media/image5.png){width="9.645669291338583in" height="4.908043525809274in"} |
|                                                                                                                             |
| *Hình 5. 05 warranty pv migration*                                                                                          |
+=============================================================================================================================+
+-----------------------------------------------------------------------------------------------------------------------------+

# 2. DANH MỤC BẢNG HỢP NHẤT

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **STT**        **Nhóm**                               **Tên bảng**                   **Mục đích**                                                   **Trạng thái**
  -------------- -------------------------------------- ------------------------------ -------------------------------------------------------------- --------------------------
  **1**          A. IAM VÀ KIỂM SOÁT                    app_users                      Tài khoản người dùng nội bộ.                                   BASELINE + TECH-PROPOSAL

  **2**          A. IAM VÀ KIỂM SOÁT                    roles                          Vai trò hệ thống.                                              BASELINE + TECH-PROPOSAL

  **3**          A. IAM VÀ KIỂM SOÁT                    permissions                    Danh mục action-level permission.                              BASELINE + TECH-PROPOSAL

  **4**          A. IAM VÀ KIỂM SOÁT                    user_roles                     Gán role cho user.                                             BASELINE + TECH-PROPOSAL

  **5**          A. IAM VÀ KIỂM SOÁT                    role_permissions               Gán permission cho role.                                       BASELINE + TECH-PROPOSAL

  **6**          A. IAM VÀ KIỂM SOÁT                    idempotency_records            Chống lặp cho import/Post/reversal và command quan trọng.      BASELINE + TECH-PROPOSAL

  **7**          A. IAM VÀ KIỂM SOÁT                    audit_logs                     Audit append-only cho action nhạy cảm.                         BASELINE + TECH-PROPOSAL

  **8**          B. KHO, MASTER VÀ CATALOG              warehouses                     Kho tổng Hoa Nam; MVP chỉ một bản ghi active.                  BASELINE + TECH-PROPOSAL

  **9**          B. KHO, MASTER VÀ CATALOG              agencies                       Danh mục nơi nhận hàng, không phải kho.                        BASELINE + TECH-PROPOSAL

  **10**         B. KHO, MASTER VÀ CATALOG              product_categories             Nhóm sản phẩm/linh kiện.                                       BASELINE + TECH-PROPOSAL

  **11**         B. KHO, MASTER VÀ CATALOG              brands                         Hãng sản xuất; hỗ trợ nhiều hãng.                              BASELINE + TECH-PROPOSAL

  **12**         B. KHO, MASTER VÀ CATALOG              power_sources                  Nguồn điện mở; seed MAINS_DIRECT/BATTERY.                      BASELINE + TECH-PROPOSAL

  **13**         B. KHO, MASTER VÀ CATALOG              product_models                 Model master tùy chọn để chuẩn hóa catalog.                    BASELINE + TECH-PROPOSAL

  **14**         B. KHO, MASTER VÀ CATALOG              package_variants               Phiên bản/bộ/đóng gói để phân biệt SKU.                        BASELINE + TECH-PROPOSAL

  **15**         B. KHO, MASTER VÀ CATALOG              defect_catalogs                 Danh mục bệnh/lỗi cho bảo hành và báo cáo.                     BASELINE + TECH-PROPOSAL

  **16**         B. KHO, MASTER VÀ CATALOG              skus                           Loại hàng tồn kho PRODUCT/COMPONENT.                           BASELINE + TECH-PROPOSAL

  **17**         B. KHO, MASTER VÀ CATALOG              sku_aliases                    Mã SKU cũ/legacy để tra cứu và migration.                      BASELINE + TECH-PROPOSAL

  **18**         B. KHO, MASTER VÀ CATALOG              sku_compatible_accessories     Mapping phụ kiện/SKU tương thích cho PV.                       BASELINE + TECH-PROPOSAL

  **19**         C. ITEM, MÃ VẬT LÝ VÀ CONTAINER        import_batches                 Lô nhập nghiệp vụ; không phải batch import Excel toa xuất.     BASELINE + TECH-PROPOSAL

  **20**         C. ITEM, MÃ VẬT LÝ VÀ CONTAINER        item_instances                 Subledger từng item/serial.                                    BASELINE + TECH-PROPOSAL

  **21**         C. ITEM, MÃ VẬT LÝ VÀ CONTAINER        physical_codes                 Mã Barcode/QR/RFID/NFC/MANUAL map item hoặc container.         BASELINE + TECH-PROPOSAL

  **22**         C. ITEM, MÃ VẬT LÝ VÀ CONTAINER        containers                     CARTON/BOX/TRAY; container/locator, không stock bucket.        BASELINE + TECH-PROPOSAL

  **23**         C. ITEM, MÃ VẬT LÝ VÀ CONTAINER        container_items                Content active của container: item serial hoặc quantity SKU.   BASELINE + TECH-PROPOSAL

  **24**         C. ITEM, MÃ VẬT LÝ VÀ CONTAINER        putaway_events                 Lịch sử gán box vào tray/location; stock-neutral.              BASELINE + TECH-PROPOSAL

  **25**         C. ITEM, MÃ VẬT LÝ VÀ CONTAINER        scan_events                    Evidence/diagnostics cho camera/manual scan.                   BASELINE + TECH-PROPOSAL

  **26**         D. INBOUND, INVENTORY VÀ REVERSAL      inbound_documents              Phiếu nhập full-post.                                          BASELINE + TECH-PROPOSAL

  **27**         D. INBOUND, INVENTORY VÀ REVERSAL      inbound_lines                  Dòng SKU và expected/scanned quantity.                         BASELINE + TECH-PROPOSAL

  **28**         D. INBOUND, INVENTORY VÀ REVERSAL      inbound_scan_entries           Chi tiết item/container/quantity đã nhận vào line.             BASELINE + TECH-PROPOSAL

  **29**         D. INBOUND, INVENTORY VÀ REVERSAL      inventory_balances             Projection/cache tồn theo warehouse+SKU+stock status.          BASELINE + TECH-PROPOSAL

  **30**         D. INBOUND, INVENTORY VÀ REVERSAL      inventory_movements            Authoritative append-only ledger.                              BASELINE + TECH-PROPOSAL

  **31**         D. INBOUND, INVENTORY VÀ REVERSAL      posting_events                 Một lần Post command và kết quả transaction.                   BASELINE + TECH-PROPOSAL

  **32**         D. INBOUND, INVENTORY VÀ REVERSAL      reconciliation_incidents       Ghi nhận divergence ledger-balance-subledger.                  BASELINE + TECH-PROPOSAL

  **33**         D. INBOUND, INVENTORY VÀ REVERSAL      reversal_documents             Header reversal/correction append-only.                        BASELINE + TECH-PROPOSAL

  **34**         D. INBOUND, INVENTORY VÀ REVERSAL      reversal_lines                 Dòng movement/qty/condition cần đảo.                           BASELINE + TECH-PROPOSAL

  **35**         E. EXCEL, OUTBOUND, LABEL VÀ PRINTER   outbound_request_imports       Batch upload toa yêu cầu xuất Excel.                           BASELINE + TECH-PROPOSAL

  **36**         E. EXCEL, OUTBOUND, LABEL VÀ PRINTER   outbound_request_import_rows   Raw/normalized row và row-level validation.                    BASELINE + TECH-PROPOSAL

  **37**         E. EXCEL, OUTBOUND, LABEL VÀ PRINTER   outbound_requests              Toa yêu cầu xuất đã consume từ Excel.                          BASELINE + TECH-PROPOSAL

  **38**         E. EXCEL, OUTBOUND, LABEL VÀ PRINTER   outbound_request_lines         Dòng SKU/required quantity của toa.                            BASELINE + TECH-PROPOSAL

  **39**         E. EXCEL, OUTBOUND, LABEL VÀ PRINTER   outbound_documents             Phiếu xuất sản phẩm full-post.                                 BASELINE + TECH-PROPOSAL

  **40**         E. EXCEL, OUTBOUND, LABEL VÀ PRINTER   outbound_lines                 Dòng SKU và required/scanned match.                            BASELINE + TECH-PROPOSAL

  **41**         E. EXCEL, OUTBOUND, LABEL VÀ PRINTER   outbound_item_matches          Item/container quantity được match vào outbound line.          BASELINE + TECH-PROPOSAL

  **42**         E. EXCEL, OUTBOUND, LABEL VÀ PRINTER   label_snapshots                Nội dung nhãn bất biến tại thời điểm scan hợp lệ.              BASELINE + TECH-PROPOSAL

  **43**         E. EXCEL, OUTBOUND, LABEL VÀ PRINTER   printer_configs                Cấu hình printer/agent/khổ nhãn.                               BASELINE + TECH-PROPOSAL

  **44**         E. EXCEL, OUTBOUND, LABEL VÀ PRINTER   label_print_events             Mỗi attempt print/reprint và kết quả.                          BASELINE + TECH-PROPOSAL

  **45**         F. WARRANTY VÀ COMPONENT ISSUE         warranty_cases                 Case bảo hành có mã hoặc TEMP_ONLY.                            BASELINE + TECH-PROPOSAL

  **46**         F. WARRANTY VÀ COMPONENT ISSUE         warranty_events                Lịch sử trạng thái/ghi chú xử lý.                              BASELINE + TECH-PROPOSAL

  **47**         F. WARRANTY VÀ COMPONENT ISSUE         warranty_attachments           Ảnh/tệp private của case.                                      BASELINE + TECH-PROPOSAL

  **48**         F. WARRANTY VÀ COMPONENT ISSUE         component_issue_documents      Phiếu xuất linh kiện chỉ cho bảo hành.                         BASELINE + TECH-PROPOSAL

  **49**         F. WARRANTY VÀ COMPONENT ISSUE         component_issue_lines          Dòng COMPONENT SKU/quantity.                                   BASELINE + TECH-PROPOSAL

  **50**         F. WARRANTY VÀ COMPONENT ISSUE         component_issue_items          Serial item hoặc box quantity được lấy.                        BASELINE + TECH-PROPOSAL

  **51**         G. WEB APP PV                          pv_product_profiles            Hồ sơ public/read-only theo SKU.                               BASELINE + TECH-PROPOSAL

  **52**         G. WEB APP PV                          pv_product_images              Ảnh public theo profile.                                       BASELINE + TECH-PROPOSAL

  **53**         G. WEB APP PV                          pv_industries                  Ngành nghề dùng cho filter/gợi ý.                              BASELINE + TECH-PROPOSAL

  **54**         G. WEB APP PV                          pv_tags                        Tag/bộ lọc public.                                             BASELINE + TECH-PROPOSAL

  **55**         G. WEB APP PV                          pv_product_industries          Mapping profile-ngành nghề.                                    BASELINE + TECH-PROPOSAL

  **56**         G. WEB APP PV                          pv_product_tags                Mapping profile-tag.                                           BASELINE + TECH-PROPOSAL

  **57**         G. WEB APP PV                          pv_recommendation_rules        Rule gợi ý sản phẩm theo ngành nghề.                           BASELINE + TECH-PROPOSAL

  **58**         H. MIGRATION / OPENING BALANCE         migration_runs                 Controlled dry-run/commit/reconcile/rollback evidence.         BASELINE + TECH-PROPOSAL

  **59**         H. MIGRATION / OPENING BALANCE         migration_errors               Row/field error cho migration.                                 BASELINE + TECH-PROPOSAL
  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

# 3. DATA DICTIONARY CHI TIẾT

## A. IAM VÀ KIỂM SOÁT

### 1. app_users

Tài khoản người dùng nội bộ. **\[BASELINE + TECH-PROPOSAL\]**

  --------------------------------------------------------------------------------------------------------------------------------
  **Field**           **PostgreSQL type**   **Null**       **Key / Default**               **Mô tả / Rule**
  ------------------- --------------------- -------------- ------------------------------- ---------------------------------------
  **id**              uuid                  No             PK, DEFAULT gen_random_uuid()   Định danh user.

  **username**        varchar(100)          No             UNIQUE                          Tên đăng nhập chuẩn hóa.

  **display_name**    varchar(200)          No                                             Tên hiển thị.

  **email**           varchar(255)          Yes            UNIQUE NULLS DISTINCT           Email tùy cơ chế xác thực.

  **password_hash**   varchar(255)          Yes                                            Chỉ dùng nếu tự quản lý mật khẩu.

  **status**          varchar(20)           No             CHECK ACTIVE/INACTIVE/LOCKED    Trạng thái.

  **last_login_at**   timestamptz           Yes                                            Đăng nhập gần nhất.

  **created_at**      timestamptz           No             DEFAULT now()                   Thời điểm tạo.

  **created_by**      uuid                  Yes            FK app_users.id                 Actor tạo; null cho system/bootstrap.

  **updated_at**      timestamptz           No             DEFAULT now()                   Cập nhật gần nhất.

  **updated_by**      uuid                  Yes            FK app_users.id                 Actor cập nhật; null cho system.

  **version**         integer               No             DEFAULT 0                       Optimistic locking / If-Match.
  --------------------------------------------------------------------------------------------------------------------------------

  ----------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                                                **Index khuyến nghị**
  ----------------------------------------------------------------------------------- ----------------------------------------
  Không hard-delete user đã có audit/transaction; bootstrap created_by có thể null.   UX(username); IDX(status); IDX(email).

  ----------------------------------------------------------------------------------------------------------------------------

### 2. roles

Vai trò hệ thống. **\[BASELINE + TECH-PROPOSAL\]**

  ----------------------------------------------------------------------------------------------------------------------
  **Field**         **PostgreSQL type**   **Null**       **Key / Default**       **Mô tả / Rule**
  ----------------- --------------------- -------------- ----------------------- ---------------------------------------
  **id**            uuid                  No             PK                      Định danh role.

  **role_code**     varchar(80)           No             UNIQUE                  ADMIN, WAREHOUSE_MANAGER\...

  **role_name**     varchar(150)          No                                     Tên role.

  **description**   text                  Yes                                    Mô tả.

  **status**        varchar(20)           No             CHECK ACTIVE/INACTIVE   Trạng thái.

  **created_at**    timestamptz           No             DEFAULT now()           Thời điểm tạo.

  **created_by**    uuid                  Yes            FK app_users.id         Actor tạo; null cho system/bootstrap.

  **updated_at**    timestamptz           No             DEFAULT now()           Cập nhật gần nhất.

  **updated_by**    uuid                  Yes            FK app_users.id         Actor cập nhật; null cho system.

  **version**       integer               No             DEFAULT 0               Optimistic locking / If-Match.
  ----------------------------------------------------------------------------------------------------------------------

  ----------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                                                     **Index khuyến nghị**
  ---------------------------------------------------------------------------------------- -----------------------------------
  Seed tối thiểu: ADMIN, WAREHOUSE_MANAGER, WAREHOUSE_STAFF, WARRANTY_STAFF, LEADERSHIP.   UX(role_code); IDX(status).

  ----------------------------------------------------------------------------------------------------------------------------

### 3. permissions

Danh mục action-level permission. **\[BASELINE + TECH-PROPOSAL\]**

  -------------------------------------------------------------------------------------------------------------------------
  **Field**            **PostgreSQL type**   **Null**       **Key / Default**       **Mô tả / Rule**
  -------------------- --------------------- -------------- ----------------------- ---------------------------------------
  **id**               uuid                  No             PK                      Định danh.

  **permission_key**   varchar(120)          No             UNIQUE                  Ví dụ outbound.post, label.reprint.

  **description**      text                  Yes                                    Mô tả action.

  **status**           varchar(20)           No             CHECK ACTIVE/INACTIVE   Trạng thái.

  **created_at**       timestamptz           No             DEFAULT now()           Thời điểm tạo.

  **created_by**       uuid                  Yes            FK app_users.id         Actor tạo; null cho system/bootstrap.

  **updated_at**       timestamptz           No             DEFAULT now()           Cập nhật gần nhất.

  **updated_by**       uuid                  Yes            FK app_users.id         Actor cập nhật; null cho system.

  **version**          integer               No             DEFAULT 0               Optimistic locking / If-Match.
  -------------------------------------------------------------------------------------------------------------------------

  -----------------------------------------------------------------------
  **Constraint chính**                **Index khuyến nghị**
  ----------------------------------- -----------------------------------
  Permission được backend enforce.    UX(permission_key); IDX(status).

  -----------------------------------------------------------------------

### 4. user_roles

Gán role cho user. **\[BASELINE + TECH-PROPOSAL\]**

  ----------------------------------------------------------------------------------------------------
  **Field**         **PostgreSQL type**   **Null**       **Key / Default**          **Mô tả / Rule**
  ----------------- --------------------- -------------- -------------------------- ------------------
  **user_id**       uuid                  No             PK part, FK app_users.id   User.

  **role_id**       uuid                  No             PK part, FK roles.id       Role.

  **assigned_at**   timestamptz           No             DEFAULT now()              Thời điểm gán.

  **assigned_by**   uuid                  Yes            FK app_users.id            Người gán.

  **scope_ref**     varchar(100)          Yes                                       Phạm vi gán role; rỗng nghĩa là toàn hệ thống.

  **effective_from** timestamptz          Yes                                       Thời điểm bắt đầu hiệu lực; rỗng nghĩa là không giới hạn.

  **effective_to**  timestamptz           Yes                                       Thời điểm hết hiệu lực; rỗng nghĩa là không giới hạn.
  ----------------------------------------------------------------------------------------------------

  ------------------------------------------------------------------------------------------
  **Constraint chính**                                   **Index khuyến nghị**
  ------------------------------------------------------ -----------------------------------
  PK(user_id, role_id). User/role phải active khi gán.   IDX(role_id).
  Backend chỉ công nhận role assignment đang trong cửa
  sổ hiệu lực; giá trị rỗng coi như không giới hạn.

  ------------------------------------------------------------------------------------------

### 5. role_permissions

Gán permission cho role. **\[BASELINE + TECH-PROPOSAL\]**

  --------------------------------------------------------------------------------------------------------
  **Field**           **PostgreSQL type**   **Null**       **Key / Default**            **Mô tả / Rule**
  ------------------- --------------------- -------------- ---------------------------- ------------------
  **role_id**         uuid                  No             PK part, FK roles.id         Role.

  **permission_id**   uuid                  No             PK part, FK permissions.id   Permission.

  **assigned_at**     timestamptz           No             DEFAULT now()                Thời điểm gán.

  **assigned_by**     uuid                  Yes            FK app_users.id              Người gán.
  --------------------------------------------------------------------------------------------------------

  -----------------------------------------------------------------------
  **Constraint chính**                **Index khuyến nghị**
  ----------------------------------- -----------------------------------
  PK(role_id, permission_id).         IDX(permission_id).

  -----------------------------------------------------------------------

### 6. idempotency_records

Chống lặp cho import/Post/reversal và command quan trọng. **\[BASELINE + TECH-PROPOSAL\]**

  -----------------------------------------------------------------------------------------------------------------------------
  **Field**             **PostgreSQL type**   **Null**       **Key / Default**   **Mô tả / Rule**
  --------------------- --------------------- -------------- ------------------- ----------------------------------------------
  **id**                uuid                  No             PK                  Định danh.

  **scope**             varchar(80)           No                                 INBOUND_POST, OUTBOUND_POST, IMPORT\...

  **idempotency_key**   varchar(200)          No                                 Key client.

  **request_hash**      char(64)              No                                 SHA-256 canonical payload.

  **response_status**   integer               Yes                                HTTP status replay.

  **response_body**     jsonb                 Yes                                Kết quả replay; tránh raw PII nếu không cần.

  **resource_type**     varchar(80)           Yes                                Loại resource.

  **resource_id**       uuid                  Yes                                Resource id.

  **expires_at**        timestamptz           Yes                                Retention.

  **created_at**        timestamptz           No             DEFAULT now()       Lần đầu.

  **created_by**        uuid                  Yes            FK app_users.id     Actor/system.
  -----------------------------------------------------------------------------------------------------------------------------

  ---------------------------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                                  **Index khuyến nghị**
  --------------------------------------------------------------------- -----------------------------------------------------------------------------
  UNIQUE(scope,idempotency_key); cùng key khác hash -\> 409 conflict.   UX(scope,idempotency_key); IDX(expires_at); IDX(resource_type,resource_id).

  ---------------------------------------------------------------------------------------------------------------------------------------------------

### 7. audit_logs

Audit append-only cho action nhạy cảm. **\[BASELINE + TECH-PROPOSAL\]**

  ------------------------------------------------------------------------------------------------------------------------------------
  **Field**         **PostgreSQL type**   **Null**       **Key / Default**             **Mô tả / Rule**
  ----------------- --------------------- -------------- ----------------------------- -----------------------------------------------
  **id**            bigserial             No             PK                            Định danh log.

  **request_id**    varchar(100)          Yes                                          Correlation id.

  **actor_id**      uuid                  Yes            FK app_users.id               Actor/system.

  **action**        varchar(120)          No                                           Action key.

  **entity_type**   varchar(100)          No                                           Loại entity.

  **entity_id**     uuid                  Yes                                          ID entity.

  **result**        varchar(30)           No             CHECK SUCCESS/FAILED/DENIED   Kết quả.

  **before_data**   jsonb                 Yes                                          Snapshot trước, đã loại secret/PII không cần.

  **after_data**    jsonb                 Yes                                          Snapshot sau.

  **metadata**      jsonb                 Yes                                          Browser/device/error/context.

  **created_at**    timestamptz           No             DEFAULT now()                 Thời điểm.
  ------------------------------------------------------------------------------------------------------------------------------------

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                                         **Index khuyến nghị**
  ---------------------------------------------------------------------------- -----------------------------------------------------------------------------------------
  Append-only; không raw password/token/phone/address trong application log.   IDX(entity_type,entity_id); IDX(actor_id,created_at); IDX(request_id); IDX(created_at).

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------

## B. KHO, MASTER VÀ CATALOG

### 8. warehouses

Kho tổng Hoa Nam; MVP chỉ một bản ghi active. **\[BASELINE + TECH-PROPOSAL\]**

  ----------------------------------------------------------------------------------------------------------------------------
  **Field**            **PostgreSQL type**   **Null**       **Key / Default**          **Mô tả / Rule**
  -------------------- --------------------- -------------- -------------------------- ---------------------------------------
  **id**               uuid                  No             PK                         Định danh.

  **warehouse_code**   varchar(30)           No             UNIQUE                     Mã kho.

  **warehouse_name**   varchar(200)          No                                        Tên kho.

  **address**          text                  Yes                                       Địa chỉ.

  **timezone**         varchar(50)           No             DEFAULT Asia/Ho_Chi_Minh   Timezone hiển thị.

  **status**           varchar(20)           No             CHECK ACTIVE/INACTIVE      Trạng thái.

  **created_at**       timestamptz           No             DEFAULT now()              Thời điểm tạo.

  **created_by**       uuid                  Yes            FK app_users.id            Actor tạo; null cho system/bootstrap.

  **updated_at**       timestamptz           No             DEFAULT now()              Cập nhật gần nhất.

  **updated_by**       uuid                  Yes            FK app_users.id            Actor cập nhật; null cho system.

  **version**          integer               No             DEFAULT 0                  Optimistic locking / If-Match.
  ----------------------------------------------------------------------------------------------------------------------------

  -------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                                            **Index khuyến nghị**
  ------------------------------------------------------------------------------- -----------------------------------
  MVP enforce tối đa 1 warehouse ACTIVE bằng partial unique index/config guard.   UX(warehouse_code); IDX(status).

  -------------------------------------------------------------------------------------------------------------------

### 9. agencies

Danh mục nơi nhận hàng, không phải kho. **\[BASELINE + TECH-PROPOSAL\]**

  -----------------------------------------------------------------------------------------------------------------------
  **Field**          **PostgreSQL type**   **Null**       **Key / Default**       **Mô tả / Rule**
  ------------------ --------------------- -------------- ----------------------- ---------------------------------------
  **id**             uuid                  No             PK                      Định danh.

  **agency_code**    varchar(50)           No             UNIQUE                  Mã nơi nhận.

  **agency_name**    varchar(200)          No                                     Tên.

  **contact_name**   varchar(200)          Yes                                    Liên hệ.

  **phone**          varchar(30)           Yes                                    SĐT; internal.

  **address**        text                  Yes                                    Địa chỉ.

  **status**         varchar(20)           No             CHECK ACTIVE/INACTIVE   Inactive không chọn cho phiếu mới.

  **created_at**     timestamptz           No             DEFAULT now()           Thời điểm tạo.

  **created_by**     uuid                  Yes            FK app_users.id         Actor tạo; null cho system/bootstrap.

  **updated_at**     timestamptz           No             DEFAULT now()           Cập nhật gần nhất.

  **updated_by**     uuid                  Yes            FK app_users.id         Actor cập nhật; null cho system.

  **version**        integer               No             DEFAULT 0               Optimistic locking / If-Match.
  -----------------------------------------------------------------------------------------------------------------------

  ------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                       **Index khuyến nghị**
  ---------------------------------------------------------- -------------------------------------------------
  Không có warehouse_id, balance hoặc movement của Agency.   UX(agency_code); IDX(status); IDX(agency_name).

  ------------------------------------------------------------------------------------------------------------

### 10. product_categories

Nhóm sản phẩm/linh kiện. **\[BASELINE + TECH-PROPOSAL\]**

  ------------------------------------------------------------------------------------------------------------------------
  **Field**           **PostgreSQL type**   **Null**       **Key / Default**       **Mô tả / Rule**
  ------------------- --------------------- -------------- ----------------------- ---------------------------------------
  **id**              uuid                  No             PK                      Định danh.

  **category_code**   varchar(80)           No             UNIQUE                  Mã master.

  **category_name**   varchar(200)          No                                     Tên.

  **description**     text                  Yes                                    Mô tả.

  **sort_order**      integer               No             DEFAULT 0               Thứ tự.

  **status**          varchar(20)           No             CHECK ACTIVE/INACTIVE   Trạng thái.

  **created_at**      timestamptz           No             DEFAULT now()           Thời điểm tạo.

  **created_by**      uuid                  Yes            FK app_users.id         Actor tạo; null cho system/bootstrap.

  **updated_at**      timestamptz           No             DEFAULT now()           Cập nhật gần nhất.

  **updated_by**      uuid                  Yes            FK app_users.id         Actor cập nhật; null cho system.

  **version**         integer               No             DEFAULT 0               Optimistic locking / If-Match.
  ------------------------------------------------------------------------------------------------------------------------

  ---------------------------------------------------------------------------------------------------------
  **Constraint chính**                                         **Index khuyến nghị**
  ------------------------------------------------------------ --------------------------------------------
  Code đã được tham chiếu không đổi; deactivate thay vì xóa.   UX(category_code); IDX(status,sort_order).

  ---------------------------------------------------------------------------------------------------------

### 11. brands

Hãng sản xuất; hỗ trợ nhiều hãng. **\[BASELINE + TECH-PROPOSAL\]**

  ----------------------------------------------------------------------------------------------------------------------
  **Field**         **PostgreSQL type**   **Null**       **Key / Default**       **Mô tả / Rule**
  ----------------- --------------------- -------------- ----------------------- ---------------------------------------
  **id**            uuid                  No             PK                      Định danh.

  **brand_code**    varchar(80)           No             UNIQUE                  Mã master.

  **brand_name**    varchar(200)          No                                     Tên.

  **description**   text                  Yes                                    Mô tả.

  **sort_order**    integer               No             DEFAULT 0               Thứ tự.

  **status**        varchar(20)           No             CHECK ACTIVE/INACTIVE   Trạng thái.

  **created_at**    timestamptz           No             DEFAULT now()           Thời điểm tạo.

  **created_by**    uuid                  Yes            FK app_users.id         Actor tạo; null cho system/bootstrap.

  **updated_at**    timestamptz           No             DEFAULT now()           Cập nhật gần nhất.

  **updated_by**    uuid                  Yes            FK app_users.id         Actor cập nhật; null cho system.

  **version**       integer               No             DEFAULT 0               Optimistic locking / If-Match.
  ----------------------------------------------------------------------------------------------------------------------

  ------------------------------------------------------------------------------------------------------
  **Constraint chính**                                         **Index khuyến nghị**
  ------------------------------------------------------------ -----------------------------------------
  Code đã được tham chiếu không đổi; deactivate thay vì xóa.   UX(brand_code); IDX(status,sort_order).

  ------------------------------------------------------------------------------------------------------

### 12. power_sources

Nguồn điện mở; seed MAINS_DIRECT/BATTERY. **\[BASELINE + TECH-PROPOSAL\]**

  ----------------------------------------------------------------------------------------------------------------------------
  **Field**               **PostgreSQL type**   **Null**       **Key / Default**       **Mô tả / Rule**
  ----------------------- --------------------- -------------- ----------------------- ---------------------------------------
  **id**                  uuid                  No             PK                      Định danh.

  **power_source_code**   varchar(80)           No             UNIQUE                  Mã master.

  **power_source_name**   varchar(200)          No                                     Tên.

  **description**         text                  Yes                                    Mô tả.

  **sort_order**          integer               No             DEFAULT 0               Thứ tự.

  **status**              varchar(20)           No             CHECK ACTIVE/INACTIVE   Trạng thái.

  **created_at**          timestamptz           No             DEFAULT now()           Thời điểm tạo.

  **created_by**          uuid                  Yes            FK app_users.id         Actor tạo; null cho system/bootstrap.

  **updated_at**          timestamptz           No             DEFAULT now()           Cập nhật gần nhất.

  **updated_by**          uuid                  Yes            FK app_users.id         Actor cập nhật; null cho system.

  **version**             integer               No             DEFAULT 0               Optimistic locking / If-Match.
  ----------------------------------------------------------------------------------------------------------------------------

  -------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                         **Index khuyến nghị**
  ------------------------------------------------------------ ------------------------------------------------
  Code đã được tham chiếu không đổi; deactivate thay vì xóa.   UX(power_source_code); IDX(status,sort_order).

  -------------------------------------------------------------------------------------------------------------

### 13. product_models

Model master tùy chọn để chuẩn hóa catalog. **\[BASELINE + TECH-PROPOSAL\]**

  ----------------------------------------------------------------------------------------------------------------------
  **Field**         **PostgreSQL type**   **Null**       **Key / Default**       **Mô tả / Rule**
  ----------------- --------------------- -------------- ----------------------- ---------------------------------------
  **id**            uuid                  No             PK                      Định danh.

  **model_code**    varchar(80)           No             UNIQUE                  Mã master.

  **model_name**    varchar(200)          No                                     Tên.

  **description**   text                  Yes                                    Mô tả.

  **sort_order**    integer               No             DEFAULT 0               Thứ tự.

  **status**        varchar(20)           No             CHECK ACTIVE/INACTIVE   Trạng thái.

  **created_at**    timestamptz           No             DEFAULT now()           Thời điểm tạo.

  **created_by**    uuid                  Yes            FK app_users.id         Actor tạo; null cho system/bootstrap.

  **updated_at**    timestamptz           No             DEFAULT now()           Cập nhật gần nhất.

  **updated_by**    uuid                  Yes            FK app_users.id         Actor cập nhật; null cho system.

  **version**       integer               No             DEFAULT 0               Optimistic locking / If-Match.
  ----------------------------------------------------------------------------------------------------------------------

  ------------------------------------------------------------------------------------------------------
  **Constraint chính**                                         **Index khuyến nghị**
  ------------------------------------------------------------ -----------------------------------------
  Code đã được tham chiếu không đổi; deactivate thay vì xóa.   UX(model_code); IDX(status,sort_order).

  ------------------------------------------------------------------------------------------------------

### 14. package_variants

Phiên bản/bộ/đóng gói để phân biệt SKU. **\[BASELINE + TECH-PROPOSAL\]**

  -----------------------------------------------------------------------------------------------------------------------
  **Field**          **PostgreSQL type**   **Null**       **Key / Default**       **Mô tả / Rule**
  ------------------ --------------------- -------------- ----------------------- ---------------------------------------
  **id**             uuid                  No             PK                      Định danh.

  **package_code**   varchar(80)           No             UNIQUE                  Mã master.

  **package_name**   varchar(200)          No                                     Tên.

  **description**    text                  Yes                                    Mô tả.

  **sort_order**     integer               No             DEFAULT 0               Thứ tự.

  **status**         varchar(20)           No             CHECK ACTIVE/INACTIVE   Trạng thái.

  **created_at**     timestamptz           No             DEFAULT now()           Thời điểm tạo.

  **created_by**     uuid                  Yes            FK app_users.id         Actor tạo; null cho system/bootstrap.

  **updated_at**     timestamptz           No             DEFAULT now()           Cập nhật gần nhất.

  **updated_by**     uuid                  Yes            FK app_users.id         Actor cập nhật; null cho system.

  **version**        integer               No             DEFAULT 0               Optimistic locking / If-Match.
  -----------------------------------------------------------------------------------------------------------------------

  --------------------------------------------------------------------------------------------------------
  **Constraint chính**                                         **Index khuyến nghị**
  ------------------------------------------------------------ -------------------------------------------
  Code đã được tham chiếu không đổi; deactivate thay vì xóa.   UX(package_code); IDX(status,sort_order).

  --------------------------------------------------------------------------------------------------------

### 15. defect_catalogs

Danh mục bệnh/lỗi cho bảo hành và báo cáo. **\[BASELINE + TECH-PROPOSAL\]**

  ----------------------------------------------------------------------------------------------------------------------
  **Field**         **PostgreSQL type**   **Null**       **Key / Default**       **Mô tả / Rule**
  ----------------- --------------------- -------------- ----------------------- ---------------------------------------
  **id**            uuid                  No             PK                      Định danh.

  **defect_code**   varchar(80)           No             UNIQUE                  Mã lỗi.

  **defect_name**   varchar(200)          No                                     Tên lỗi.

  **description**   text                  Yes                                    Mô tả.

  **status**        varchar(20)           No             CHECK ACTIVE/INACTIVE   Inactive không chọn case mới.

  **created_at**    timestamptz           No             DEFAULT now()           Thời điểm tạo.

  **created_by**    uuid                  Yes            FK app_users.id         Actor tạo; null cho system/bootstrap.

  **updated_at**    timestamptz           No             DEFAULT now()           Cập nhật gần nhất.

  **updated_by**    uuid                  Yes            FK app_users.id         Actor cập nhật; null cho system.

  **version**       integer               No             DEFAULT 0               Optimistic locking / If-Match.
  ----------------------------------------------------------------------------------------------------------------------

  -------------------------------------------------------------------------------------------
  **Constraint chính**                      **Index khuyến nghị**
  ----------------------------------------- -------------------------------------------------
  Lịch sử case giữ FK dù defect inactive.   UX(defect_code); IDX(status); IDX(defect_name).

  -------------------------------------------------------------------------------------------

### 16. skus

Loại hàng tồn kho PRODUCT/COMPONENT. **\[BASELINE + TECH-PROPOSAL\]**

  --------------------------------------------------------------------------------------------------------------------------------
  **Field**                **PostgreSQL type**   **Null**       **Key / Default**          **Mô tả / Rule**
  ------------------------ --------------------- -------------- -------------------------- ---------------------------------------
  **id**                   uuid                  No             PK                         Định danh.

  **sku_code**             varchar(100)          No             UNIQUE                     Mã SKU chuẩn.

  **sku_name**             varchar(255)          No                                        Tên sản phẩm/linh kiện.

  **sku_type**             varchar(20)           No             CHECK PRODUCT/COMPONENT    Loại.

  **tracking_mode**        varchar(20)           No             CHECK SERIAL/QUANTITY      Cách quản lý.

  **unit_code**            varchar(30)           No             DEFAULT PCS                Đơn vị.

  **brand_id**             uuid                  Yes            FK brands.id               Hãng.

  **category_id**          uuid                  Yes            FK product_categories.id   Nhóm.

  **model_id**             uuid                  Yes            FK product_models.id       Model.

  **power_source_id**      uuid                  Yes            FK power_sources.id        Nguồn điện.

  **package_variant_id**   uuid                  Yes            FK package_variants.id     Bộ/phiên bản.

  **specifications**       jsonb                 Yes                                       Thông số kỹ thuật mở.

  **status**               varchar(20)           No             CHECK ACTIVE/INACTIVE      Trạng thái.

  **created_at**           timestamptz           No             DEFAULT now()              Thời điểm tạo.

  **created_by**           uuid                  Yes            FK app_users.id            Actor tạo; null cho system/bootstrap.

  **updated_at**           timestamptz           No             DEFAULT now()              Cập nhật gần nhất.

  **updated_by**           uuid                  Yes            FK app_users.id            Actor cập nhật; null cho system.

  **version**              integer               No             DEFAULT 0                  Optimistic locking / If-Match.
  --------------------------------------------------------------------------------------------------------------------------------

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                                                                           **Index khuyến nghị**
  -------------------------------------------------------------------------------------------------------------- --------------------------------------------------------------------------------
  sku_code và tracking_mode khóa sau khi có item/movement/balance; catalog có thể tồn tại khi chưa có balance.   UX(sku_code); IDX(sku_type,status); IDX(brand_id,category_id,power_source_id).

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 17. sku_aliases

Mã SKU cũ/legacy để tra cứu và migration. **\[BASELINE + TECH-PROPOSAL\]**

  ------------------------------------------------------------------------------------------------------------------------
  **Field**           **PostgreSQL type**   **Null**       **Key / Default**       **Mô tả / Rule**
  ------------------- --------------------- -------------- ----------------------- ---------------------------------------
  **id**              uuid                  No             PK                      Định danh.

  **sku_id**          uuid                  No             FK skus.id              SKU chuẩn.

  **source_system**   varchar(80)           No                                     Nguồn.

  **alias_code**      varchar(120)          No                                     Mã cũ.

  **status**          varchar(20)           No             CHECK ACTIVE/INACTIVE   Trạng thái.

  **created_at**      timestamptz           No             DEFAULT now()           Thời điểm tạo.

  **created_by**      uuid                  Yes            FK app_users.id         Actor tạo; null cho system/bootstrap.

  **updated_at**      timestamptz           No             DEFAULT now()           Cập nhật gần nhất.

  **updated_by**      uuid                  Yes            FK app_users.id         Actor cập nhật; null cho system.

  **version**         integer               No             DEFAULT 0               Optimistic locking / If-Match.
  ------------------------------------------------------------------------------------------------------------------------

  ---------------------------------------------------------------------------------------------------------
  **Constraint chính**                                         **Index khuyến nghị**
  ------------------------------------------------------------ --------------------------------------------
  UNIQUE(source_system,alias_code). Alias không là khóa tồn.   UX(source_system,alias_code); IDX(sku_id).

  ---------------------------------------------------------------------------------------------------------

### 18. sku_compatible_accessories

Mapping phụ kiện/SKU tương thích cho PV. **\[BASELINE + TECH-PROPOSAL\]**

  ------------------------------------------------------------------------------------------------------
  **Field**              **PostgreSQL type**   **Null**       **Key / Default**       **Mô tả / Rule**
  ---------------------- --------------------- -------------- ----------------------- ------------------
  **sku_id**             uuid                  No             PK part, FK skus.id     Sản phẩm chính.

  **accessory_sku_id**   uuid                  No             PK part, FK skus.id     Phụ kiện.

  **note**               text                  Yes                                    Ghi chú.

  **status**             varchar(20)           No             CHECK ACTIVE/INACTIVE   Trạng thái.

  **created_at**         timestamptz           No             DEFAULT now()           Tạo.

  **created_by**         uuid                  Yes            FK app_users.id         Actor.
  ------------------------------------------------------------------------------------------------------

  --------------------------------------------------------------------------------------------------
  **Constraint chính**                                         **Index khuyến nghị**
  ------------------------------------------------------------ -------------------------------------
  PK(sku_id,accessory_sku_id); sku_id \<\> accessory_sku_id.   IDX(accessory_sku_id); IDX(status).

  --------------------------------------------------------------------------------------------------

## C. ITEM, MÃ VẬT LÝ VÀ CONTAINER

### 19. import_batches

Lô nhập nghiệp vụ; không phải batch import Excel toa xuất. **\[BASELINE + TECH-PROPOSAL\]**

  --------------------------------------------------------------------------------------------------------------------------
  **Field**              **PostgreSQL type**   **Null**       **Key / Default**      **Mô tả / Rule**
  ---------------------- --------------------- -------------- ---------------------- ---------------------------------------
  **id**                 uuid                  No             PK                     Định danh.

  **batch_code**         varchar(80)           No             UNIQUE                 Mã lô.

  **source_name**        varchar(255)          Yes                                   Nhà cung cấp/nguồn.

  **received_date**      date                  Yes                                   Ngày nhận.

  **reference_no**       varchar(120)          Yes                                   Chứng từ nguồn.

  **note**               text                  Yes                                   Ghi chú.

  **migration_run_id**   uuid                  Yes            FK migration_runs.id   Nguồn migration nếu có.

  **status**             varchar(20)           No             CHECK ACTIVE/CLOSED    Trạng thái.

  **created_at**         timestamptz           No             DEFAULT now()          Thời điểm tạo.

  **created_by**         uuid                  Yes            FK app_users.id        Actor tạo; null cho system/bootstrap.

  **updated_at**         timestamptz           No             DEFAULT now()          Cập nhật gần nhất.

  **updated_by**         uuid                  Yes            FK app_users.id        Actor cập nhật; null cho system.

  **version**            integer               No             DEFAULT 0              Optimistic locking / If-Match.
  --------------------------------------------------------------------------------------------------------------------------

  ------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                  **Index khuyến nghị**
  ----------------------------------------------------- ------------------------------------------------------------
  Nhiều batch dùng cùng SKU; batch không tạo SKU mới.   UX(batch_code); IDX(received_date); IDX(migration_run_id).

  ------------------------------------------------------------------------------------------------------------------

### 20. item_instances

Subledger từng item/serial. **\[BASELINE + TECH-PROPOSAL\]**

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Field**                  **PostgreSQL type**   **Null**       **Key / Default**                             **Mô tả / Rule**
  -------------------------- --------------------- -------------- --------------------------------------------- ---------------------------------------------------------------
  **id**                     uuid                  No             PK                                            Định danh item.

  **item_code**              varchar(120)          No             UNIQUE                                        Mã nghiệp vụ nội bộ.

  **sku_id**                 uuid                  No             FK skus.id                                    SKU.

  **serial_number**          varchar(150)          Yes                                                          Serial hãng; bắt buộc/unique theo policy khi tracking SERIAL.

  **import_batch_id**        uuid                  Yes            FK import_batches.id                          Lô nhập.

  **status**                 varchar(30)           No             CHECK item states                             Chỉ IN_STOCK tính available.

  **condition**              varchar(30)           No             CHECK NORMAL/DEFECTIVE/NON_SELLABLE/UNKNOWN   Tình trạng.

  **current_container_id**   uuid                  Yes            FK containers.id                              Container hiện tại, nếu dùng denormalization.

  **location_hint**          varchar(200)          Yes                                                          Vị trí tương đối; không stock bucket.

  **received_at**            timestamptz           Yes                                                          Post Receipt.

  **issued_at**              timestamptz           Yes                                                          Post Issue.

  **migration_run_id**       uuid                  Yes            FK migration_runs.id                          Nguồn migration.

  **created_at**             timestamptz           No             DEFAULT now()                                 Thời điểm tạo.

  **created_by**             uuid                  Yes            FK app_users.id                               Actor tạo; null cho system/bootstrap.

  **updated_at**             timestamptz           No             DEFAULT now()                                 Cập nhật gần nhất.

  **updated_by**             uuid                  Yes            FK app_users.id                               Actor cập nhật; null cho system.

  **version**                integer               No             DEFAULT 0                                     Optimistic locking / If-Match.
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                                                                          **Index khuyến nghị**
  ------------------------------------------------------------------------------------------------------------- -------------------------------------------------------------------------------------------------------------------------------------------
  Tracking SERIAL: serial_number required theo SKU policy; item không thuộc đồng thời nhiều container active.   UX(item_code); partial UX(sku_id,serial_number) WHERE serial_number IS NOT NULL; IDX(sku_id,status,condition); IDX(current_container_id).

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 21. physical_codes

Mã Barcode/QR/RFID/NFC/MANUAL map item hoặc container. **\[BASELINE + TECH-PROPOSAL\]**

  -------------------------------------------------------------------------------------------------------------------------------------------
  **Field**                 **PostgreSQL type**   **Null**       **Key / Default**                    **Mô tả / Rule**
  ------------------------- --------------------- -------------- ------------------------------------ ---------------------------------------
  **id**                    uuid                  No             PK                                   Định danh code.

  **code_value**            varchar(200)          No             UNIQUE                               Giá trị scan/nhập.

  **carrier_type**          varchar(20)           No             CHECK BARCODE/QR/RFID/NFC/MANUAL     Loại carrier.

  **hardware_uid**          varchar(200)          Yes            UNIQUE NULLS DISTINCT                UID phần cứng, chủ yếu RFID.

  **item_id**               uuid                  Yes            FK item_instances.id                 Target item.

  **container_id**          uuid                  Yes            FK containers.id                     Target container.

  **is_primary**            boolean               No             DEFAULT false                        Mã hiển thị chính.

  **status**                varchar(20)           No             CHECK ACTIVE/INACTIVE/LOST/DAMAGED   Trạng thái.

  **assigned_at**           timestamptz           No             DEFAULT now()                        Thời điểm map.

  **assigned_by**           uuid                  Yes            FK app_users.id                      Actor.

  **deactivated_at**        timestamptz           Yes                                                 Ngừng mã.

  **deactivation_reason**   text                  Yes                                                 Lý do.

  **created_at**            timestamptz           No             DEFAULT now()                        Thời điểm tạo.

  **created_by**            uuid                  Yes            FK app_users.id                      Actor tạo; null cho system/bootstrap.

  **updated_at**            timestamptz           No             DEFAULT now()                        Cập nhật gần nhất.

  **updated_by**            uuid                  Yes            FK app_users.id                      Actor cập nhật; null cho system.

  **version**               integer               No             DEFAULT 0                            Optimistic locking / If-Match.
  -------------------------------------------------------------------------------------------------------------------------------------------

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                                                                                                      **Index khuyến nghị**
  ----------------------------------------------------------------------------------------------------------------------------------------- ----------------------------------------------------------------------------------------------------------------------------------------
  CHECK tối đa một trong item_id/container_id khác null (SRS v1.12 §7.3: Physical_Code – Item/Container = 1–0..1; §13.9 cho phép UNASSIGNED trước khi gán). RFID optional tại outbound theo SRS v1.12/DEC-RFID-FALLBACK-001, không phải gate Post Issue. Gắn RFID không movement.   UX(code_value); UX(hardware_uid) WHERE NOT NULL; IDX(item_id,status); IDX(container_id,status); partial UX primary per target/carrier.

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 22. containers

CARTON/BOX/TRAY; container/locator, không stock bucket. **\[BASELINE + TECH-PROPOSAL\]**

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Field**                 **PostgreSQL type**   **Null**       **Key / Default**                                                            **Mô tả / Rule**
  ------------------------- --------------------- -------------- ---------------------------------------------------------------------------- ---------------------------------------
  **id**                    uuid                  No             PK                                                                           Định danh.

  **container_code**        varchar(120)          No             UNIQUE                                                                       Mã nghiệp vụ.

  **container_type**        varchar(20)           No             CHECK CARTON/BOX/TRAY                                                        Loại.

  **primary_sku_id**        uuid                  Yes            FK skus.id                                                                   SKU chính; BOX quantity nên có.

  **parent_container_id**   uuid                  Yes            FK containers.id                                                             Ví dụ BOX thuộc TRAY.

  **status**                varchar(30)           No             CHECK EMPTY/IN_STOCK/PARTIAL/ISSUED/RECEIVED_PENDING_PUTAWAY/STORED/CLOSED   Trạng thái container.

  **zone_code**             varchar(50)           Yes                                                                                         Khu vực phẳng.

  **location_hint**         varchar(200)          Yes                                                                                         Gợi ý vị trí.

  **migration_run_id**      uuid                  Yes            FK migration_runs.id                                                         Nguồn migration.

  **created_at**            timestamptz           No             DEFAULT now()                                                                Thời điểm tạo.

  **created_by**            uuid                  Yes            FK app_users.id                                                              Actor tạo; null cho system/bootstrap.

  **updated_at**            timestamptz           No             DEFAULT now()                                                                Cập nhật gần nhất.

  **updated_by**            uuid                  Yes            FK app_users.id                                                              Actor cập nhật; null cho system.

  **version**               integer               No             DEFAULT 0                                                                    Optimistic locking / If-Match.
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                                           **Index khuyến nghị**
  ------------------------------------------------------------------------------ ------------------------------------------------------------------------------------------------
  Không parent cycle; TRAY là locator; container không cộng tồn ngoài content.   UX(container_code); IDX(container_type,status); IDX(parent_container_id); IDX(primary_sku_id).

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 23. container_items

Content active của container: item serial hoặc quantity SKU. **\[BASELINE + TECH-PROPOSAL\]**

  ----------------------------------------------------------------------------------------------------------------------
  **Field**          **PostgreSQL type**   **Null**       **Key / Default**      **Mô tả / Rule**
  ------------------ --------------------- -------------- ---------------------- ---------------------------------------
  **id**             uuid                  No             PK                     Định danh mapping.

  **container_id**   uuid                  No             FK containers.id       Container.

  **item_id**        uuid                  Yes            FK item_instances.id   Item serial.

  **sku_id**         uuid                  Yes            FK skus.id             SKU quantity.

  **quantity**       bigint                Yes            CHECK \>0              Số lượng khi quantity mode.

  **is_active**      boolean               No             DEFAULT true           Mapping hiện hành.

  **placed_at**      timestamptz           No             DEFAULT now()          Đặt vào.

  **placed_by**      uuid                  Yes            FK app_users.id        Actor.

  **removed_at**     timestamptz           Yes                                   Lấy ra.

  **removed_by**     uuid                  Yes            FK app_users.id        Actor.

  **source_type**    varchar(50)           Yes                                   RECEIPT/PUTAWAY/MIGRATION/CORRECTION.

  **source_id**      uuid                  Yes                                   Reference.

  **created_at**     timestamptz           No             DEFAULT now()          Thời điểm tạo.

  **created_by**     uuid                  Yes            FK app_users.id        Actor tạo; null cho system/bootstrap.

  **updated_at**     timestamptz           No             DEFAULT now()          Cập nhật gần nhất.

  **updated_by**     uuid                  Yes            FK app_users.id        Actor cập nhật; null cho system.

  **version**        integer               No             DEFAULT 0              Optimistic locking / If-Match.
  ----------------------------------------------------------------------------------------------------------------------

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                                                                                                             **Index khuyến nghị**
  ------------------------------------------------------------------------------------------------------------------------------------------------ ------------------------------------------------------------------------------------------------------------------
  CHECK: item_id XOR (sku_id + quantity). Một item chỉ có một active container mapping. Quantity không âm; box quantity SKU phù hợp primary_sku.   Partial UX(item_id) WHERE is_active AND item_id IS NOT NULL; IDX(container_id,is_active); IDX(sku_id,is_active).

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 24. putaway_events

Lịch sử gán box vào tray/location; stock-neutral. **\[BASELINE + TECH-PROPOSAL\]**

  -------------------------------------------------------------------------------------------------------------------------------------
  **Field**              **PostgreSQL type**   **Null**       **Key / Default**                 **Mô tả / Rule**
  ---------------------- --------------------- -------------- --------------------------------- ---------------------------------------
  **id**                 uuid                  No             PK                                Định danh.

  **box_container_id**   uuid                  No             FK containers.id                  BOX.

  **from_tray_id**       uuid                  Yes            FK containers.id                  Tray cũ.

  **to_tray_id**         uuid                  No             FK containers.id                  TRAY đích.

  **location_hint**      varchar(200)          Yes                                              Gợi ý.

  **status**             varchar(20)           No             CHECK DRAFT/CONFIRMED/CANCELLED   Trạng thái.

  **confirmed_at**       timestamptz           Yes                                              Xác nhận.

  **confirmed_by**       uuid                  Yes            FK app_users.id                   Actor.

  **note**               text                  Yes                                              Ghi chú.

  **created_at**         timestamptz           No             DEFAULT now()                     Thời điểm tạo.

  **created_by**         uuid                  Yes            FK app_users.id                   Actor tạo; null cho system/bootstrap.

  **updated_at**         timestamptz           No             DEFAULT now()                     Cập nhật gần nhất.

  **updated_by**         uuid                  Yes            FK app_users.id                   Actor cập nhật; null cho system.

  **version**            integer               No             DEFAULT 0                         Optimistic locking / If-Match.
  -------------------------------------------------------------------------------------------------------------------------------------

  -----------------------------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                                                      **Index khuyến nghị**
  ----------------------------------------------------------------------------------------- -----------------------------------------------------------
  to_tray phải container_type=TRAY, box phải BOX; CONFIRMED không tạo Inventory_Movement.   IDX(box_container_id,created_at); IDX(to_tray_id,status).

  -----------------------------------------------------------------------------------------------------------------------------------------------------

### 25. scan_events

Evidence/diagnostics cho camera/manual scan. **\[BASELINE + TECH-PROPOSAL\]**

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Field**              **PostgreSQL type**   **Null**       **Key / Default**                                                     **Mô tả / Rule**
  ---------------------- --------------------- -------------- --------------------------------------------------------------------- ----------------------------------------------------
  **id**                 uuid                  No             PK                                                                    Định danh.

  **context_type**       varchar(50)           No                                                                                   INBOUND/OUTBOUND/TRACE/WARRANTY/COMPONENT/PUTAWAY.

  **context_id**         uuid                  Yes                                                                                  Resource đang thao tác.

  **scan_source**        varchar(20)           No             CHECK CAMERA/MANUAL/HARDWARE                                          Nguồn.

  **code_value**         varchar(200)          No                                                                                   Giá trị đã gửi server.

  **physical_code_id**   uuid                  Yes            FK physical_codes.id                                                  Resolve result.

  **classification**     varchar(30)           Yes                                                                                  ITEM/CARTON/BOX/TRAY/UNKNOWN.

  **result**             varchar(30)           No             CHECK SUCCESS/DUPLICATE/UNKNOWN/INVALID/WRONG_SKU/WRONG_STATE/ERROR   Kết quả.

  **error_code**         varchar(80)           Yes                                                                                  Mã lỗi.

  **metadata**           jsonb                 Yes                                                                                  Browser/device/latency, không lưu ảnh/video.

  **actor_id**           uuid                  Yes            FK app_users.id                                                       Actor.

  **scanned_at**         timestamptz           No             DEFAULT now()                                                         Thời điểm.
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                                                             **Index khuyến nghị**
  ------------------------------------------------------------------------------------------------ -------------------------------------------------------------------------------------------------------------
  Không lưu camera image/video; code_value theo retention/logging policy. Scan không tự đổi tồn.   IDX(context_type,context_id,scanned_at); IDX(code_value); IDX(result,scanned_at); IDX(actor_id,scanned_at).

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## D. INBOUND, INVENTORY VÀ REVERSAL

### 26. inbound_documents

Phiếu nhập full-post. **\[BASELINE + TECH-PROPOSAL\]**

  ----------------------------------------------------------------------------------------------------------------------------------------
  **Field**           **PostgreSQL type**   **Null**       **Key / Default**                       **Mô tả / Rule**
  ------------------- --------------------- -------------- --------------------------------------- ---------------------------------------
  **id**              uuid                  No             PK                                      Định danh.

  **inbound_no**      varchar(80)           No             UNIQUE                                  Mã phiếu.

  **warehouse_id**    uuid                  No             FK warehouses.id                        Kho tổng.

  **source_name**     varchar(255)          Yes                                                    Nguồn hàng.

  **document_date**   date                  No                                                     Ngày chứng từ.

  **status**          varchar(20)           No             CHECK DRAFT/SCANNING/POSTED/CANCELLED   Không partial.

  **note**            text                  Yes                                                    Ghi chú.

  **posted_at**       timestamptz           Yes                                                    Post Receipt.

  **posted_by**       uuid                  Yes            FK app_users.id                         Actor.

  **cancelled_at**    timestamptz           Yes                                                    Cancel trước Post.

  **cancelled_by**    uuid                  Yes            FK app_users.id                         Actor.

  **cancel_reason**   text                  Yes                                                    Lý do.

  **created_at**      timestamptz           No             DEFAULT now()                           Thời điểm tạo.

  **created_by**      uuid                  Yes            FK app_users.id                         Actor tạo; null cho system/bootstrap.

  **updated_at**      timestamptz           No             DEFAULT now()                           Cập nhật gần nhất.

  **updated_by**      uuid                  Yes            FK app_users.id                         Actor cập nhật; null cho system.

  **version**         integer               No             DEFAULT 0                               Optimistic locking / If-Match.
  ----------------------------------------------------------------------------------------------------------------------------------------

  ---------------------------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                                      **Index khuyến nghị**
  ------------------------------------------------------------------------- -------------------------------------------------------------------------
  POSTED immutable; Post chỉ khi tất cả line exact; no approval workflow.   UX(inbound_no); IDX(warehouse_id,status,document_date); IDX(posted_at).

  ---------------------------------------------------------------------------------------------------------------------------------------------------

### 27. inbound_lines

Dòng SKU và expected/scanned quantity. **\[BASELINE + TECH-PROPOSAL\]**

  --------------------------------------------------------------------------------------------------------------------------------
  **Field**                 **PostgreSQL type**   **Null**       **Key / Default**         **Mô tả / Rule**
  ------------------------- --------------------- -------------- ------------------------- ---------------------------------------
  **id**                    uuid                  No             PK                        Định danh.

  **inbound_document_id**   uuid                  No             FK inbound_documents.id   Header.

  **line_no**               integer               No                                       STT.

  **sku_id**                uuid                  No             FK skus.id                SKU.

  **import_batch_id**       uuid                  Yes            FK import_batches.id      Lô.

  **expected_qty**          bigint                No             CHECK \>0                 Số lượng dự kiến hiện hành.

  **scanned_qty**           bigint                No             DEFAULT 0, CHECK \>=0     Tổng scan/quantity entry.

  **note**                  text                  Yes                                      Ghi chú.

  **created_at**            timestamptz           No             DEFAULT now()             Thời điểm tạo.

  **created_by**            uuid                  Yes            FK app_users.id           Actor tạo; null cho system/bootstrap.

  **updated_at**            timestamptz           No             DEFAULT now()             Cập nhật gần nhất.

  **updated_by**            uuid                  Yes            FK app_users.id           Actor cập nhật; null cho system.

  **version**               integer               No             DEFAULT 0                 Optimistic locking / If-Match.
  --------------------------------------------------------------------------------------------------------------------------------

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                                                                    **Index khuyến nghị**
  ------------------------------------------------------------------------------------------------------- ---------------------------------------------------------------------
  UNIQUE(inbound_document_id,line_no); scanned_qty \<= expected_qty; Post guard scanned_qty=expected_qty mọi line.   UX(inbound_document_id,line_no); IDX(sku_id); IDX(import_batch_id).

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 28. inbound_scan_entries

Chi tiết item/container/quantity đã nhận vào line. **\[BASELINE + TECH-PROPOSAL\]**

  -------------------------------------------------------------------------------------------------------------------
  **Field**             **PostgreSQL type**   **Null**       **Key / Default**      **Mô tả / Rule**
  --------------------- --------------------- -------------- ---------------------- ---------------------------------
  **id**                uuid                  No             PK                     Định danh.

  **inbound_line_id**   uuid                  No             FK inbound_lines.id    Line.

  **scan_event_id**     uuid                  Yes            FK scan_events.id      Evidence.

  **item_id**           uuid                  Yes            FK item_instances.id   Item serial.

  **container_id**      uuid                  Yes            FK containers.id       Carton/box.

  **quantity**          bigint                No             CHECK \>0              1 cho item; N cho quantity box.

  **status**            varchar(20)           No             CHECK ACTIVE/REMOVED   Cho sửa trước Post.

  **captured_at**       timestamptz           No             DEFAULT now()          Thời điểm.

  **captured_by**       uuid                  Yes            FK app_users.id        Actor.

  **removed_at**        timestamptz           Yes                                   Gỡ trước Post.

  **removed_by**        uuid                  Yes            FK app_users.id        Actor.
  -------------------------------------------------------------------------------------------------------------------

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                                                                        **Index khuyến nghị**
  ----------------------------------------------------------------------------------------------------------- ------------------------------------------------------------------------------------------------------------------
  Item active chỉ thuộc một inbound chưa Post; wrong SKU/duplicate/state bị chặn; POSTED entries immutable.   Partial UX(item_id) WHERE status=ACTIVE AND item_id IS NOT NULL; IDX(inbound_line_id,status); IDX(container_id).

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 29. inventory_balances

Projection/cache tồn theo warehouse+SKU+stock status. **\[BASELINE + TECH-PROPOSAL\]**

  --------------------------------------------------------------------------------------------------------------------------------------
  **Field**                **PostgreSQL type**   **Null**       **Key / Default**                                   **Mô tả / Rule**
  ------------------------ --------------------- -------------- --------------------------------------------------- --------------------
  **id**                   uuid                  No             PK                                                  Định danh.

  **warehouse_id**         uuid                  No             FK warehouses.id                                    Kho.

  **sku_id**               uuid                  No             FK skus.id                                          SKU.

  **stock_status**         varchar(30)           No             CHECK AVAILABLE/NON_SELLABLE/DEFECTIVE/QUARANTINE   Bucket trạng thái.

  **quantity**             bigint                No             DEFAULT 0, CHECK \>=0                               Số lượng.

  **last_movement_id**     uuid                  Yes            FK inventory_movements.id                           Movement mới nhất.

  **last_reconciled_at**   timestamptz           Yes                                                                Đối soát gần nhất.

  **updated_at**           timestamptz           No             DEFAULT now()                                       Cập nhật.

  **version**              integer               No             DEFAULT 0                                           Optimistic lock.
  --------------------------------------------------------------------------------------------------------------------------------------

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                                                          **Index khuyến nghị**
  --------------------------------------------------------------------------------------------- ----------------------------------------------------------------------------------
  UNIQUE(warehouse_id,sku_id,stock_status). Cập nhật cùng transaction với movement/subledger.   UX(warehouse_id,sku_id,stock_status); IDX(sku_id,stock_status); IDX(updated_at).

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 30. inventory_movements

Authoritative append-only ledger. **\[BASELINE + TECH-PROPOSAL\]**

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Field**                   **PostgreSQL type**   **Null**       **Key / Default**                                      **Mô tả / Rule**
  --------------------------- --------------------- -------------- ------------------------------------------------------ ------------------------------------------------------
  **id**                      uuid                  No             PK                                                     Định danh.

  **movement_key**            varchar(160)          No             UNIQUE                                                 Business unique movement.

  **warehouse_id**            uuid                  No             FK warehouses.id                                       Kho.

  **sku_id**                  uuid                  No             FK skus.id                                             SKU.

  **item_id**                 uuid                  Yes            FK item_instances.id                                   Item nếu serial.

  **container_id**            uuid                  Yes            FK containers.id                                       Container nếu relevant.

  **movement_type**           varchar(30)           No             CHECK OPENING/RECEIPT/ISSUE/COMPONENT_ISSUE/REVERSAL   Loại.

  **quantity_delta**          bigint                No             CHECK \<\>0                                            Signed delta.

  **stock_status_from**       varchar(30)           Yes                                                                   Bucket nguồn.

  **stock_status_to**         varchar(30)           Yes                                                                   Bucket đích.

  **source_type**             varchar(60)           No                                                                    INBOUND/OUTBOUND/COMPONENT_ISSUE/REVERSAL/MIGRATION.

  **source_id**               uuid                  No                                                                    Document/resource.

  **source_line_id**          uuid                  Yes                                                                   Line.

  **original_movement_id**    uuid                  Yes            FK inventory_movements.id                              Movement gốc khi reversal.

  **correction_chain_id**     uuid                  Yes                                                                   Root chain.

  **idempotency_record_id**   uuid                  Yes            FK idempotency_records.id                              Replay evidence.

  **occurred_at**             timestamptz           No             DEFAULT now()                                          Business posting time.

  **created_by**              uuid                  Yes            FK app_users.id                                        Actor/system.

  **metadata**                jsonb                 Yes                                                                   Reason/condition/reference.
  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                                        **Index khuyến nghị**
  --------------------------------------------------------------------------- --------------------------------------------------------------------------------------------------------------------------------------------------------------------
  Append-only; no UPDATE/DELETE. Tổng movement reconcile balance/subledger.   UX(movement_key); IDX(warehouse_id,sku_id,occurred_at); IDX(item_id,occurred_at); IDX(source_type,source_id); IDX(original_movement_id); IDX(correction_chain_id).

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 31. posting_events

Một lần Post command và kết quả transaction. **\[BASELINE + TECH-PROPOSAL\]**

  ------------------------------------------------------------------------------------------------------------------------------------------------
  **Field**             **PostgreSQL type**   **Null**       **Key / Default**                **Mô tả / Rule**
  --------------------- --------------------- -------------- -------------------------------- ----------------------------------------------------
  **id**                uuid                  No             PK                               Định danh.

  **resource_type**     varchar(50)           No                                              INBOUND/OUTBOUND/COMPONENT_ISSUE/REVERSAL/OPENING.

  **resource_id**       uuid                  No                                              Resource.

  **posting_action**    varchar(50)           No                                              POST_RECEIPT/POST_ISSUE/\...

  **idempotency_key**   varchar(200)          No                                              Key.

  **request_hash**      char(64)              No                                              Payload hash.

  **status**            varchar(20)           No             CHECK STARTED/COMMITTED/FAILED   Kết quả.

  **movement_count**    integer               No             DEFAULT 0                        Số movement.

  **error_code**        varchar(80)           Yes                                             Lỗi.

  **started_at**        timestamptz           No             DEFAULT now()                    Bắt đầu.

  **committed_at**      timestamptz           Yes                                             Commit.

  **actor_id**          uuid                  Yes            FK app_users.id                  Actor.

  **request_id**        varchar(100)          Yes                                             Correlation.
  ------------------------------------------------------------------------------------------------------------------------------------------------

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                                                                            **Index khuyến nghị**
  --------------------------------------------------------------------------------------------------------------- -----------------------------------------------------------------------------------------------------------
  UNIQUE(resource_type,resource_id,posting_action) cho full-post one-time; hoặc enforce theo state/idempotency.   UX(resource_type,resource_id,posting_action); UX(posting_action,idempotency_key); IDX(status,started_at).

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 32. reconciliation_incidents

Ghi nhận divergence ledger-balance-subledger. **\[BASELINE + TECH-PROPOSAL\]**

  ------------------------------------------------------------------------------------------------------------------------------------------------
  **Field**             **PostgreSQL type**   **Null**       **Key / Default**                          **Mô tả / Rule**
  --------------------- --------------------- -------------- ------------------------------------------ ------------------------------------------
  **id**                uuid                  No             PK                                         Định danh.

  **incident_no**       varchar(80)           No             UNIQUE                                     Mã sự cố.

  **warehouse_id**      uuid                  No             FK warehouses.id                           Kho.

  **sku_id**            uuid                  Yes            FK skus.id                                 SKU.

  **item_id**           uuid                  Yes            FK item_instances.id                       Item nếu scope.

  **detected_at**       timestamptz           No             DEFAULT now()                              Phát hiện.

  **ledger_qty**        bigint                Yes                                                       Số ledger.

  **balance_qty**       bigint                Yes                                                       Số projection.

  **subledger_qty**     bigint                Yes                                                       Số item/box.

  **difference_qty**    bigint                Yes                                                       Chênh.

  **severity**          varchar(20)           No             CHECK WARNING/ERROR/CRITICAL               Mức.

  **status**            varchar(20)           No             CHECK OPEN/INVESTIGATING/RESOLVED/WAIVED   Trạng thái.

  **resolution_type**   varchar(50)           Yes                                                       REBUILD_PROJECTION/CORRECTION/WAIVER\...

  **resolution_note**   text                  Yes                                                       Evidence.

  **resolved_at**       timestamptz           Yes                                                       Đóng.

  **resolved_by**       uuid                  Yes            FK app_users.id                            Actor.

  **created_at**        timestamptz           No             DEFAULT now()                              Thời điểm tạo.

  **created_by**        uuid                  Yes            FK app_users.id                            Actor tạo; null cho system/bootstrap.

  **updated_at**        timestamptz           No             DEFAULT now()                              Cập nhật gần nhất.

  **updated_by**        uuid                  Yes            FK app_users.id                            Actor cập nhật; null cho system.

  **version**           integer               No             DEFAULT 0                                  Optimistic locking / If-Match.
  ------------------------------------------------------------------------------------------------------------------------------------------------

  ---------------------------------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                         **Index khuyến nghị**
  ------------------------------------------------------------ --------------------------------------------------------------------------------------------
  Không auto-edit ledger. Resolution phải có evidence/audit.   UX(incident_no); IDX(status,severity,detected_at); IDX(warehouse_id,sku_id); IDX(item_id).

  ---------------------------------------------------------------------------------------------------------------------------------------------------------

### 33. reversal_documents

Header reversal/correction append-only. **\[BASELINE + TECH-PROPOSAL\]**

  ----------------------------------------------------------------------------------------------------------------------------------------
  **Field**                    **PostgreSQL type**   **Null**       **Key / Default**              **Mô tả / Rule**
  ---------------------------- --------------------- -------------- ------------------------------ ---------------------------------------
  **id**                       uuid                  No             PK                             Định danh.

  **reversal_no**              varchar(80)           No             UNIQUE                         Mã chứng từ.

  **warehouse_id**             uuid                  No             FK warehouses.id               Kho.

  **original_document_type**   varchar(50)           No                                            INBOUND/OUTBOUND/COMPONENT_ISSUE.

  **original_document_id**     uuid                  No                                            Document gốc.

  **correction_chain_id**      uuid                  No                                            Chain root.

  **reason_code**              varchar(80)           No                                            Mã lý do.

  **reason_text**              text                  No                                            Chi tiết.

  **status**                   varchar(20)           No             CHECK DRAFT/POSTED/CANCELLED   Trạng thái.

  **posted_at**                timestamptz           Yes                                           Post.

  **posted_by**                uuid                  Yes            FK app_users.id                Actor.

  **cancelled_at**             timestamptz           Yes                                           Cancel draft.

  **cancel_reason**            text                  Yes                                           Lý do.

  **condition_status**         varchar(30)           Yes                                           Condition hàng về (BR-REV-06/07): SELLABLE/DEFECTIVE/NON_SELLABLE.

  **physical_return_status**   varchar(30)           Yes            CHECK PENDING_RETURN/CONFIRMED Xác nhận hàng vật lý đã về kho trước khi cộng tồn (BR-REV-06).

  **idempotency_key**          varchar(200)          Yes                                           Key retry theo BR-REV-12.

  **created_at**               timestamptz           No             DEFAULT now()                  Thời điểm tạo.

  **created_by**               uuid                  Yes            FK app_users.id                Actor tạo; null cho system/bootstrap.

  **updated_at**               timestamptz           No             DEFAULT now()                  Cập nhật gần nhất.

  **updated_by**               uuid                  Yes            FK app_users.id                Actor cập nhật; null cho system.

  **version**                  integer               No             DEFAULT 0                      Optimistic locking / If-Match.
  ----------------------------------------------------------------------------------------------------------------------------------------

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                                                            **Index khuyến nghị**
  ----------------------------------------------------------------------------------------------- ----------------------------------------------------------------------------------------------------------------------
  POSTED immutable; không reverse trực tiếp reversal, sửa tiếp bằng correction mới trong chain.   UX(reversal_no); IDX(original_document_type,original_document_id); IDX(correction_chain_id); IDX(status,created_at).

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 34. reversal_lines

Dòng movement/qty/condition cần đảo. **\[BASELINE + TECH-PROPOSAL\]**

  --------------------------------------------------------------------------------------------------------------------------------------------------
  **Field**                     **PostgreSQL type**   **Null**       **Key / Default**                       **Mô tả / Rule**
  ----------------------------- --------------------- -------------- --------------------------------------- ---------------------------------------
  **id**                        uuid                  No             PK                                      Định danh.

  **reversal_document_id**      uuid                  No             FK reversal_documents.id                Header.

  **line_no**                   integer               No                                                     STT.

  **original_movement_id**      uuid                  No             FK inventory_movements.id               Movement gốc/gần nhất.

  **sku_id**                    uuid                  No             FK skus.id                              SKU.

  **item_id**                   uuid                  Yes            FK item_instances.id                    Item.

  **quantity_to_reverse**       bigint                No             CHECK \>0                               Không vượt reversible qty.

  **return_condition**          varchar(30)           Yes            CHECK SELLABLE/DEFECTIVE/NON_SELLABLE   Bắt buộc khi reverse issue hàng về.

  **downstream_check_result**   jsonb                 Yes                                                    Dependency evidence.

  **posted_movement_id**        uuid                  Yes            FK inventory_movements.id               Movement inverse.

  **created_at**                timestamptz           No             DEFAULT now()                           Thời điểm tạo.

  **created_by**                uuid                  Yes            FK app_users.id                         Actor tạo; null cho system/bootstrap.

  **updated_at**                timestamptz           No             DEFAULT now()                           Cập nhật gần nhất.

  **updated_by**                uuid                  Yes            FK app_users.id                         Actor cập nhật; null cho system.

  **version**                   integer               No             DEFAULT 0                               Optimistic locking / If-Match.
  --------------------------------------------------------------------------------------------------------------------------------------------------

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                                                                                **Index khuyến nghị**
  ------------------------------------------------------------------------------------------------------------------- ----------------------------------------------------------------------------
  UNIQUE(reversal_document_id,line_no); tổng posted reverse per original \<= original abs qty; lock original scope.   UX(reversal_document_id,line_no); IDX(original_movement_id); IDX(item_id).

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## E. EXCEL, OUTBOUND, LABEL VÀ PRINTER

### 35. outbound_request_imports

Batch upload toa yêu cầu xuất Excel. **\[BASELINE + TECH-PROPOSAL\]**

  ------------------------------------------------------------------------------------------------------------------------------------------------
  **Field**              **PostgreSQL type**   **Null**       **Key / Default**                            **Mô tả / Rule**
  ---------------------- --------------------- -------------- -------------------------------------------- ---------------------------------------
  **id**                 uuid                  No             PK                                           Định danh.

  **import_no**          varchar(80)           No             UNIQUE                                       Mã batch.

  **source_file_name**   varchar(255)          No                                                          Tên file.

  **source_checksum**    char(64)              No                                                          SHA-256 file.

  **source_key**         varchar(160)          No                                                          Key business/client.

  **status**             varchar(20)           No             CHECK UPLOADED/VALIDATED/REJECTED/CONSUMED   Trạng thái.

  **total_rows**         integer               No             DEFAULT 0                                    Số dòng.

  **valid_rows**         integer               No             DEFAULT 0                                    Hợp lệ.

  **error_rows**         integer               No             DEFAULT 0                                    Lỗi.

  **uploaded_at**        timestamptz           No             DEFAULT now()                                Upload.

  **uploaded_by**        uuid                  Yes            FK app_users.id                              Actor.

  **validated_at**       timestamptz           Yes                                                         Validate.

  **consumed_at**        timestamptz           Yes                                                         Tạo request/outbound.

  **consumed_by**        uuid                  Yes            FK app_users.id                              Actor.

  **created_at**         timestamptz           No             DEFAULT now()                                Thời điểm tạo.

  **created_by**         uuid                  Yes            FK app_users.id                              Actor tạo; null cho system/bootstrap.

  **updated_at**         timestamptz           No             DEFAULT now()                                Cập nhật gần nhất.

  **updated_by**         uuid                  Yes            FK app_users.id                              Actor cập nhật; null cho system.

  **version**            integer               No             DEFAULT 0                                    Optimistic locking / If-Match.
  ------------------------------------------------------------------------------------------------------------------------------------------------

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                                                                       **Index khuyến nghị**
  ---------------------------------------------------------------------------------------------------------- -------------------------------------------------------------------------------
  UNIQUE(source_key); duplicate checksum/key không tạo trùng; REJECTED không consume; file limit theo NFR.   UX(import_no); UX(source_key); IDX(source_checksum); IDX(status,uploaded_at).

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 36. outbound_request_import_rows

Raw/normalized row và row-level validation. **\[BASELINE + TECH-PROPOSAL\]**

  --------------------------------------------------------------------------------------------------------------------------------------------------------
  **Field**               **PostgreSQL type**   **Null**       **Key / Default**                **Mô tả / Rule**
  ----------------------- --------------------- -------------- -------------------------------- ----------------------------------------------------------
  **id**                  uuid                  No             PK                               Định danh.

  **import_id**           uuid                  No             FK outbound_request_imports.id   Batch.

  **row_no**              integer               No                                              1-based Excel row.

  **request_no**          varchar(100)          Yes                                             Mã toa.

  **customer_name**       varchar(200)          Yes                                             Bắt buộc khi valid; PII.

  **customer_address**    text                  Yes                                             Bắt buộc khi valid; PII.

  **sku_code**            varchar(100)          Yes                                             Resolve SKU.

  **required_qty**        bigint                Yes            CHECK \>0 when valid             Số lượng.

  **product_name_raw**    varchar(255)          Yes                                             Chỉ review; catalog là source.

  **note**                text                  Yes                                             Ghi chú.

  **resolved_sku_id**     uuid                  Yes            FK skus.id                       SKU resolve.

  **validation_status**   varchar(20)           No             CHECK VALID/ERROR/WARNING        Kết quả.

  **error_field**         varchar(100)          Yes                                             Field lỗi chính; có thể dùng errors jsonb cho nhiều lỗi.

  **error_code**          varchar(80)           Yes                                             Mã lỗi.

  **error_message**       text                  Yes                                             Thông báo.

  **errors**              jsonb                 Yes                                             Danh sách row/field errors.

  **raw_data**            jsonb                 Yes                                             Raw row, mask PII theo policy.

  **created_at**          timestamptz           No             DEFAULT now()                    Tạo.
  --------------------------------------------------------------------------------------------------------------------------------------------------------

  -----------------------------------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                                 **Index khuyến nghị**
  -------------------------------------------------------------------- --------------------------------------------------------------------------------------
  UNIQUE(import_id,row_no). Blocking error =\> batch không CONSUMED.   UX(import_id,row_no); IDX(validation_status); IDX(request_no); IDX(resolved_sku_id).

  -----------------------------------------------------------------------------------------------------------------------------------------------------------

### 37. outbound_requests

Toa yêu cầu xuất đã consume từ Excel. **\[BASELINE + TECH-PROPOSAL\]**

  ------------------------------------------------------------------------------------------------------------------------------------
  **Field**              **PostgreSQL type**   **Null**       **Key / Default**                **Mô tả / Rule**
  ---------------------- --------------------- -------------- -------------------------------- ---------------------------------------
  **id**                 uuid                  No             PK                               Định danh.

  **request_no**         varchar(100)          No             UNIQUE                           Mã toa/source.

  **import_id**          uuid                  No             FK outbound_request_imports.id   Nguồn Excel.

  **customer_name**      varchar(200)          No                                              Snapshot PII.

  **customer_address**   text                  Yes                                             Snapshot PII; nullable theo SRS v1.12 §15 (REQ-SRS-014: address/phone của toa được phép trống, receiver_name bắt buộc).

  **agency_id**          uuid                  Yes            FK agencies.id                   Nếu map nơi nhận.

  **status**             varchar(20)           No             CHECK OPEN/CONSUMED/CANCELLED    Trạng thái.

  **note**               text                  Yes                                             Ghi chú.

  **source_checksum**    char(64)              No                                              Trace.

  **created_at**         timestamptz           No             DEFAULT now()                    Thời điểm tạo.

  **created_by**         uuid                  Yes            FK app_users.id                  Actor tạo; null cho system/bootstrap.

  **updated_at**         timestamptz           No             DEFAULT now()                    Cập nhật gần nhất.

  **updated_by**         uuid                  Yes            FK app_users.id                  Actor cập nhật; null cho system.

  **version**            integer               No             DEFAULT 0                        Optimistic locking / If-Match.
  ------------------------------------------------------------------------------------------------------------------------------------

  ----------------------------------------------------------------------------------------------------------
  **Constraint chính**                        **Index khuyến nghị**
  ------------------------------------------- --------------------------------------------------------------
  Mỗi request_no duy nhất; không tạo stock.   UX(request_no); IDX(import_id); IDX(agency_id); IDX(status).

  ----------------------------------------------------------------------------------------------------------

### 38. outbound_request_lines

Dòng SKU/required quantity của toa. **\[BASELINE + TECH-PROPOSAL\]**

  --------------------------------------------------------------------------------------------------------------------------------
  **Field**                 **PostgreSQL type**   **Null**       **Key / Default**         **Mô tả / Rule**
  ------------------------- --------------------- -------------- ------------------------- ---------------------------------------
  **id**                    uuid                  No             PK                        Định danh.

  **outbound_request_id**   uuid                  No             FK outbound_requests.id   Header.

  **line_no**               integer               No                                       STT.

  **sku_id**                uuid                  No             FK skus.id                SKU.

  **required_qty**          bigint                No             CHECK \>0                 Số lượng.

  **note**                  text                  Yes                                      Ghi chú.

  **created_at**            timestamptz           No             DEFAULT now()             Thời điểm tạo.

  **created_by**            uuid                  Yes            FK app_users.id           Actor tạo; null cho system/bootstrap.

  **updated_at**            timestamptz           No             DEFAULT now()             Cập nhật gần nhất.

  **updated_by**            uuid                  Yes            FK app_users.id           Actor cập nhật; null cho system.

  **version**               integer               No             DEFAULT 0                 Optimistic locking / If-Match.
  --------------------------------------------------------------------------------------------------------------------------------

  ----------------------------------------------------------------------------------------------
  **Constraint chính**                           **Index khuyến nghị**
  ---------------------------------------------- -----------------------------------------------
  UNIQUE(outbound_request_id,line_no); match theo SKU+qty.   UX(outbound_request_id,line_no); IDX(sku_id).

  ----------------------------------------------------------------------------------------------

### 39. outbound_documents

Phiếu xuất sản phẩm full-post. **\[BASELINE + TECH-PROPOSAL\]**

  ----------------------------------------------------------------------------------------------------------------------------------------------
  **Field**                 **PostgreSQL type**   **Null**       **Key / Default**                       **Mô tả / Rule**
  ------------------------- --------------------- -------------- --------------------------------------- ---------------------------------------
  **id**                    uuid                  No             PK                                      Định danh.

  **outbound_no**           varchar(80)           No             UNIQUE                                  Mã phiếu.

  **warehouse_id**          uuid                  No             FK warehouses.id                        Kho.

  **outbound_request_id**   uuid                  Yes            FK outbound_requests.id                 Toa nguồn.

  **agency_id**             uuid                  Yes            FK agencies.id                          Nơi nhận master.

  **recipient_name**        varchar(200)          No                                                     Snapshot customer/recipient.

  **recipient_address**     text                  Yes                                                    Snapshot địa chỉ; nullable theo SRS v1.12 §15 (REQ-SRS-014 toa mẫu không có địa chỉ).

  **document_date**         date                  No                                                     Ngày.

  **status**                varchar(20)           No             CHECK DRAFT/SCANNING/POSTED/CANCELLED   Không partial.

  **posted_at**             timestamptz           Yes                                                    Post Issue.

  **posted_by**             uuid                  Yes            FK app_users.id                         Actor.

  **cancelled_at**          timestamptz           Yes                                                    Cancel.

  **cancel_reason**         text                  Yes                                                    Lý do.

  **note**                  text                  Yes                                                    Ghi chú.

  **created_at**            timestamptz           No             DEFAULT now()                           Thời điểm tạo.

  **created_by**            uuid                  Yes            FK app_users.id                         Actor tạo; null cho system/bootstrap.

  **updated_at**            timestamptz           No             DEFAULT now()                           Cập nhật gần nhất.

  **updated_by**            uuid                  Yes            FK app_users.id                         Actor cập nhật; null cho system.

  **version**               integer               No             DEFAULT 0                               Optimistic locking / If-Match.
  ----------------------------------------------------------------------------------------------------------------------------------------------

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                                             **Index khuyến nghị**
  -------------------------------------------------------------------------------- --------------------------------------------------------------------------------------------------------------------
  POSTED immutable; Agency inactive không chọn cho phiếu mới; no agency balance.   UX(outbound_no); IDX(warehouse_id,status,document_date); IDX(outbound_request_id); IDX(agency_id); IDX(posted_at).

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 40. outbound_lines

Dòng SKU và required/scanned match. **\[BASELINE + TECH-PROPOSAL\]**

  ------------------------------------------------------------------------------------------------------------------------------------------
  **Field**                      **PostgreSQL type**   **Null**       **Key / Default**              **Mô tả / Rule**
  ------------------------------ --------------------- -------------- ------------------------------ ---------------------------------------
  **id**                         uuid                  No             PK                             Định danh.

  **outbound_document_id**       uuid                  No             FK outbound_documents.id       Header.

  **line_no**                    integer               No                                            STT.

  **outbound_request_line_id**   uuid                  Yes            FK outbound_request_lines.id   Dòng toa.

  **sku_id**                     uuid                  No             FK skus.id                     SKU.

  **required_qty**               bigint                No             CHECK \>0                      Số lượng cần.

  **scanned_qty**                bigint                No             DEFAULT 0, CHECK \>=0          Đã match.

  **note**                       text                  Yes                                           Ghi chú.

  **created_at**                 timestamptz           No             DEFAULT now()                  Thời điểm tạo.

  **created_by**                 uuid                  Yes            FK app_users.id                Actor tạo; null cho system/bootstrap.

  **updated_at**                 timestamptz           No             DEFAULT now()                  Cập nhật gần nhất.

  **updated_by**                 uuid                  Yes            FK app_users.id                Actor cập nhật; null cho system.

  **version**                    integer               No             DEFAULT 0                      Optimistic locking / If-Match.
  ------------------------------------------------------------------------------------------------------------------------------------------

  ------------------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                       **Index khuyến nghị**
  ---------------------------------------------------------- -------------------------------------------------------------------------------
  scanned_qty \<= required_qty; Post guard equal mọi line.   UX(outbound_document_id,line_no); IDX(sku_id); IDX(outbound_request_line_id).

  ------------------------------------------------------------------------------------------------------------------------------------------

### 41. outbound_item_matches

Item/container quantity được match vào outbound line. **\[BASELINE + TECH-PROPOSAL\]**

  ---------------------------------------------------------------------------------------------------------------------------------
  **Field**              **PostgreSQL type**   **Null**       **Key / Default**             **Mô tả / Rule**
  ---------------------- --------------------- -------------- ----------------------------- ---------------------------------------
  **id**                 uuid                  No             PK                            Định danh.

  **outbound_line_id**   uuid                  No             FK outbound_lines.id          Line.

  **scan_event_id**      uuid                  Yes            FK scan_events.id             Scan evidence.

  **item_id**            uuid                  Yes            FK item_instances.id          Item PRODUCT/serial component.

  **container_id**       uuid                  Yes            FK containers.id              Carton/box nếu dùng.

  **quantity**           bigint                No             CHECK \>0                     1 item hoặc quantity.

  **status**             varchar(20)           No             CHECK ACTIVE/REMOVED/POSTED   Trạng thái.

  **matched_at**         timestamptz           No             DEFAULT now()                 Match.

  **matched_by**         uuid                  Yes            FK app_users.id               Actor.

  **removed_at**         timestamptz           Yes                                          Gỡ trước Post.

  **removed_by**         uuid                  Yes            FK app_users.id               Actor.

  **created_at**         timestamptz           No             DEFAULT now()                 Thời điểm tạo.

  **created_by**         uuid                  Yes            FK app_users.id               Actor tạo; null cho system/bootstrap.

  **updated_at**         timestamptz           No             DEFAULT now()                 Cập nhật gần nhất.

  **updated_by**         uuid                  Yes            FK app_users.id               Actor cập nhật; null cho system.

  **version**            integer               No             DEFAULT 0                     Optimistic locking / If-Match.
  ---------------------------------------------------------------------------------------------------------------------------------

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                                                                                                    **Index khuyến nghị**
  --------------------------------------------------------------------------------------------------------------------------------------- -------------------------------------------------------------------------------------------------------------------
  Một item chỉ active ở một outbound/component issue; availability/state checked khi match và commit. PRODUCT phải match concrete item; RFID optional và không phải Post gate.   Partial UX(item_id) WHERE status=ACTIVE AND item_id IS NOT NULL; IDX(outbound_line_id,status); IDX(container_id).

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 42. label_snapshots

Packing Label snapshot bất biến theo **phiếu/lô xuất**; current flow tạo đúng 01 canonical snapshot/outbound document. Legacy item-level snapshot chỉ giữ để audit/history. **\[BASELINE + TECH-PROPOSAL\]**

| **Field** | **PostgreSQL type** | **Null** | **Key / Default** | **Mô tả / Rule** |
|---|---|---|---|---|
| **id** | uuid | No | PK | Định danh snapshot. |
| **outbound_document_id** | uuid | No | UNIQUE, FK outbound_documents.id | Đúng 01 Packing Label canonical / phiếu-lô xuất. |
| **outbound_item_match_id** | uuid | Yes | FK outbound_item_matches.id | Legacy compatibility only; current write = NULL. |
| **sku_id** | uuid | Yes | FK skus.id | Legacy compatibility only; current write = NULL. |
| **item_id** | uuid | Yes | FK item_instances.id | Legacy compatibility only; current write = NULL. |
| **label_payload** | jsonb | No |  | Immutable payload gồm label_code, outbound_no, recipient, customer_phone, item_count, sku_count, sku_summary. |
| **status** | varchar(20) | No | CHECK PENDING_PRINT/PRINTED/PRINT_FAILED | Latest print status. |
| **applied_at** | timestamptz | Yes |  | Thời điểm xác nhận đã dán Packing Label. |
| **applied_by** | uuid | Yes | FK app_users.id | Actor xác nhận dán. |
| **version** | integer | No | DEFAULT 1 | Optimistic/version tracking. |
| **created_at** | timestamptz | No | DEFAULT now() | Tạo snapshot. |
| **created_by** | uuid | Yes | FK app_users.id | Actor tạo. |
| **updated_at** | timestamptz | No | DEFAULT now() | Chỉ metadata/status, không sửa immutable payload. |
| **updated_by** | uuid | Yes | FK app_users.id | Actor cập nhật metadata/status. |

**Constraint chính:** `UNIQUE(outbound_document_id)` cho current canonical flow; chỉ tạo sau full scan; payload không đổi sau tạo; payload **có customer_phone**; print/reprint/apply stock-neutral; Post Issue mới trừ tồn. Legacy `outbound_item_match_id/sku_id/item_id` nullable để bảo toàn history nhưng không dùng cho current write.

**Index khuyến nghị:** `UX(outbound_document_id)`; `IDX(outbound_document_id,status)`; `IDX(applied_at)`; legacy index giữ khi cần migration/audit.

### 43. printer_configs

Cấu hình printer/agent/khổ nhãn. **\[BASELINE + TECH-PROPOSAL\]**

  -------------------------------------------------------------------------------------------------------------------------------------------------
  **Field**                **PostgreSQL type**   **Null**       **Key / Default**                 **Mô tả / Rule**
  ------------------------ --------------------- -------------- --------------------------------- -------------------------------------------------
  **id**                   uuid                  No             PK                                Định danh.

  **printer_code**         varchar(80)           No             UNIQUE                            Mã logical.

  **printer_name**         varchar(200)          No                                               Tên.

  **agent_endpoint**       varchar(500)          Yes                                              Endpoint/service ref; secret tách secret store.

  **location_hint**        varchar(200)          Yes                                              Vị trí.

  **label_width_mm**       numeric(8,2)          No                                               Rộng.

  **label_height_mm**      numeric(8,2)          No                                               Cao.

  **dpi**                  integer               Yes                                              Độ phân giải.

  **status**               varchar(20)           No             CHECK ACTIVE/INACTIVE/UNHEALTHY   Trạng thái.

  **last_health_at**       timestamptz           Yes                                              Health check.

  **last_health_result**   varchar(50)           Yes                                              Kết quả.

  **config**               jsonb                 Yes                                              Driver/mapping non-secret.

  **created_at**           timestamptz           No             DEFAULT now()                     Thời điểm tạo.

  **created_by**           uuid                  Yes            FK app_users.id                   Actor tạo; null cho system/bootstrap.

  **updated_at**           timestamptz           No             DEFAULT now()                     Cập nhật gần nhất.

  **updated_by**           uuid                  Yes            FK app_users.id                   Actor cập nhật; null cho system.

  **version**              integer               No             DEFAULT 0                         Optimistic locking / If-Match.
  -------------------------------------------------------------------------------------------------------------------------------------------------

  -----------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                                          **Index khuyến nghị**
  ----------------------------------------------------------------------------- -----------------------------------------------------
  Không lưu credential thô; chỉ printer active/healthy được chọn theo policy.   UX(printer_code); IDX(status); IDX(last_health_at).

  -----------------------------------------------------------------------------------------------------------------------------------

### 44. label_print_events

Mỗi attempt print/reprint và kết quả. **\[BASELINE + TECH-PROPOSAL\]**

  --------------------------------------------------------------------------------------------------------------------------
  **Field**               **PostgreSQL type**   **Null**       **Key / Default**                   **Mô tả / Rule**
  ----------------------- --------------------- -------------- ----------------------------------- -------------------------
  **id**                  uuid                  No             PK                                  Định danh.

  **label_snapshot_id**   uuid                  No             FK label_snapshots.id               Nhãn.

  **printer_id**          uuid                  Yes            FK printer_configs.id               Máy in.

  **attempt_no**          integer               No             CHECK \>0                           Thứ tự.

  **event_type**          varchar(20)           No             CHECK PRINT/REPRINT                 Loại.

  **status**              varchar(20)           No             CHECK PENDING/SENT/PRINTED/FAILED   Kết quả.

  **job_id**              varchar(160)          Yes                                                Job từ agent.

  **request_id**          varchar(100)          Yes                                                Correlation.

  **error_code**          varchar(80)           Yes                                                Lỗi.

  **error_message**       text                  Yes                                                Thông báo.

  **requested_at**        timestamptz           No             DEFAULT now()                       Yêu cầu.

  **completed_at**        timestamptz           Yes                                                Hoàn tất.

  **actor_id**            uuid                  Yes            FK app_users.id                     Actor.

  **metadata**            jsonb                 Yes                                                Agent response/latency.
  --------------------------------------------------------------------------------------------------------------------------

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                                                                          **Index khuyến nghị**
  ------------------------------------------------------------------------------------------------------------- --------------------------------------------------------------------------------------------------------
  UNIQUE(label_snapshot_id,attempt_no). Print/reprint không stock effect; không báo PRINTED khi agent reject.   UX(label_snapshot_id,attempt_no); IDX(status,requested_at); IDX(printer_id,requested_at); IDX(job_id).

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## F. WARRANTY VÀ COMPONENT ISSUE

### 45. warranty_cases

Case bảo hành có mã hoặc TEMP_ONLY. **\[BASELINE + TECH-PROPOSAL\]**

  ---------------------------------------------------------------------------------------------------------------------------------------------
  **Field**                        **PostgreSQL type**   **Null**       **Key / Default**               **Mô tả / Rule**
  -------------------------------- --------------------- -------------- ------------------------------- ---------------------------------------
  **id**                           uuid                  No             PK                              Định danh.

  **case_no**                      varchar(80)           No             UNIQUE                          Mã case.

  **identification_status**        varchar(20)           No             CHECK IDENTIFIED/TEMP_ONLY      Chế độ.

  **item_id**                      uuid                  Yes            FK item_instances.id            Null cho TEMP_ONLY.

  **temporary_item_code**          varchar(120)          Yes            UNIQUE NULLS DISTINCT           Bắt buộc TEMP_ONLY.

  **sales_trace_status**           varchar(30)           No             CHECK TRACEABLE/NOT_TRACEABLE   Trace.

  **customer_name**                varchar(200)          No                                             PII.

  **customer_phone**               varchar(30)           No                                             PII normalized.

  **customer_address**             text                  Yes                                            PII.

  **purchase_date**                date                  Yes            CHECK \<= current_date          Ngày mua nếu có.

  **defect_id**                    uuid                  Yes            FK defect_catalogs.id            Danh mục lỗi.

  **reported_defect**              text                  No                                             Khách phản ánh.

  **confirmed_defect**             text                  Yes                                            Kết quả kiểm tra.

  **missing_code_reason**          text                  Yes                                            Bắt buộc TEMP_ONLY.

  **manual_product_description**   text                  Yes                                            Bắt buộc TEMP_ONLY.

  **accessories_received**         text                  Yes                                            Phụ kiện đi kèm.

  **received_condition**           text                  Yes                                            Tình trạng tiếp nhận.

  **status**                       varchar(20)           No             CHECK warranty states           Trạng thái.

  **received_at**                  timestamptz           No             DEFAULT now()                   Tiếp nhận.

  **completed_at**                 timestamptz           Yes                                            Hoàn tất sửa.

  **returned_at**                  timestamptz           Yes                                            Trả khách.

  **cancel_reason**                text                  Yes                                            Hủy.

  **created_at**                   timestamptz           No             DEFAULT now()                   Thời điểm tạo.

  **created_by**                   uuid                  Yes            FK app_users.id                 Actor tạo; null cho system/bootstrap.

  **updated_at**                   timestamptz           No             DEFAULT now()                   Cập nhật gần nhất.

  **updated_by**                   uuid                  Yes            FK app_users.id                 Actor cập nhật; null cho system.

  **version**                      integer               No             DEFAULT 0                       Optimistic locking / If-Match.
  ---------------------------------------------------------------------------------------------------------------------------------------------

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                                                                                                                                                            **Index khuyến nghị**
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- -----------------------------------------------------------------------------------------------------------------------------------------------------------------------
  IDENTIFIED: item_id required, temp null. TEMP_ONLY: item null, temporary code/reason/description required. Một active case/item (not RETURNED/CANCELLED). Warranty không tăng sellable stock.   UX(case_no); UX(temporary_item_code) WHERE NOT NULL; partial UX(item_id) WHERE active; IDX(status,received_at); IDX(defect_id); controlled exact/masked phone search.

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 46. warranty_events

Lịch sử trạng thái/ghi chú xử lý. **\[BASELINE + TECH-PROPOSAL\]**

  --------------------------------------------------------------------------------------------------------------------------------------
  **Field**              **PostgreSQL type**   **Null**       **Key / Default**      **Mô tả / Rule**
  ---------------------- --------------------- -------------- ---------------------- ---------------------------------------------------
  **id**                 uuid                  No             PK                     Định danh.

  **warranty_case_id**   uuid                  No             FK warranty_cases.id   Case.

  **event_type**         varchar(50)           No                                    RECEIVE/CHECK/REPAIR/COMPLETE/RETURN/CANCEL/NOTE.

  **from_status**        varchar(20)           Yes                                   Trước.

  **to_status**          varchar(20)           Yes                                   Sau.

  **note**               text                  Yes                                   Ghi chú.

  **event_data**         jsonb                 Yes                                   Structured data, tránh raw PII không cần.

  **occurred_at**        timestamptz           No             DEFAULT now()          Thời điểm.

  **actor_id**           uuid                  Yes            FK app_users.id        Actor.
  --------------------------------------------------------------------------------------------------------------------------------------

  -------------------------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                 **Index khuyến nghị**
  ---------------------------------------------------- --------------------------------------------------------------------------------------------
  Append-only event history; transition phải hợp lệ.   IDX(warranty_case_id,occurred_at); IDX(event_type,occurred_at); IDX(actor_id,occurred_at).

  -------------------------------------------------------------------------------------------------------------------------------------------------

### 47. warranty_attachments

Ảnh/tệp private của case. **\[BASELINE + TECH-PROPOSAL\]**

  ------------------------------------------------------------------------------------------------------------------------
  **Field**              **PostgreSQL type**   **Null**       **Key / Default**                      **Mô tả / Rule**
  ---------------------- --------------------- -------------- -------------------------------------- ---------------------
  **id**                 uuid                  No             PK                                     Định danh.

  **warranty_case_id**   uuid                  No             FK warranty_cases.id                   Case.

  **file_name**          varchar(255)          No                                                    Tên.

  **content_type**       varchar(100)          No                                                    MIME.

  **file_size_bytes**    bigint                No             CHECK \>0                              Kích thước.

  **storage_key**        varchar(500)          No             UNIQUE                                 Private object key.

  **checksum**           char(64)              Yes                                                   Integrity.

  **attachment_type**    varchar(30)           No             CHECK INTAKE/DIAGNOSTIC/RETURN/OTHER   Loại.

  **status**             varchar(20)           No             CHECK ACTIVE/DELETED/QUARANTINED       Trạng thái.

  **uploaded_at**        timestamptz           No             DEFAULT now()                          Upload.

  **uploaded_by**        uuid                  Yes            FK app_users.id                        Actor.

  **deleted_at**         timestamptz           Yes                                                   Logical delete.

  **deleted_by**         uuid                  Yes            FK app_users.id                        Actor.
  ------------------------------------------------------------------------------------------------------------------------

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                                                                     **Index khuyến nghị**
  -------------------------------------------------------------------------------------------------------- ---------------------------------------------------------------
  Private/signed access; malware/content-type validation; audit download/export; log attachment_id only.   UX(storage_key); IDX(warranty_case_id,status); IDX(checksum).

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 48. component_issue_documents

Phiếu xuất linh kiện chỉ cho bảo hành. **\[BASELINE + TECH-PROPOSAL\]**

  ---------------------------------------------------------------------------------------------------------------------------------------------
  **Field**                **PostgreSQL type**   **Null**       **Key / Default**                       **Mô tả / Rule**
  ------------------------ --------------------- -------------- --------------------------------------- ---------------------------------------
  **id**                   uuid                  No             PK                                      Định danh.

  **component_issue_no**   varchar(80)           No             UNIQUE                                  Mã phiếu.

  **warehouse_id**         uuid                  No             FK warehouses.id                        Kho.

  **warranty_case_id**     uuid                  No             FK warranty_cases.id                    Case active.

  **purpose**              varchar(20)           No             CHECK purpose=WARRANTY                  Khóa nghiệp vụ.

  **status**               varchar(20)           No             CHECK DRAFT/SCANNING/POSTED/CANCELLED   Trạng thái.

  **posted_at**            timestamptz           Yes                                                    Post.

  **posted_by**            uuid                  Yes            FK app_users.id                         Actor.

  **cancelled_at**         timestamptz           Yes                                                    Cancel.

  **cancel_reason**        text                  Yes                                                    Lý do.

  **note**                 text                  Yes                                                    Ghi chú.

  **created_at**           timestamptz           No             DEFAULT now()                           Thời điểm tạo.

  **created_by**           uuid                  Yes            FK app_users.id                         Actor tạo; null cho system/bootstrap.

  **updated_at**           timestamptz           No             DEFAULT now()                           Cập nhật gần nhất.

  **updated_by**           uuid                  Yes            FK app_users.id                         Actor cập nhật; null cho system.

  **version**              integer               No             DEFAULT 0                               Optimistic locking / If-Match.
  ---------------------------------------------------------------------------------------------------------------------------------------------

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                                  **Index khuyến nghị**
  --------------------------------------------------------------------- -------------------------------------------------------------------------------------------------
  Case phải active tại create và commit; POSTED immutable; full-post.   UX(component_issue_no); IDX(warranty_case_id,status); IDX(warehouse_id,status); IDX(posted_at).

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 49. component_issue_lines

Dòng COMPONENT SKU/quantity. **\[BASELINE + TECH-PROPOSAL\]**

  ------------------------------------------------------------------------------------------------------------------------------------------------
  **Field**                         **PostgreSQL type**   **Null**       **Key / Default**                 **Mô tả / Rule**
  --------------------------------- --------------------- -------------- --------------------------------- ---------------------------------------
  **id**                            uuid                  No             PK                                Định danh.

  **component_issue_document_id**   uuid                  No             FK component_issue_documents.id   Header.

  **line_no**                       integer               No                                               STT.

  **sku_id**                        uuid                  No             FK skus.id                        Phải sku_type=COMPONENT.

  **required_qty**                  bigint                No             CHECK \>0                         Cần.

  **scanned_qty**                   bigint                No             DEFAULT 0, CHECK \>=0             Đã chọn.

  **note**                          text                  Yes                                              Ghi chú.

  **created_at**                    timestamptz           No             DEFAULT now()                     Thời điểm tạo.

  **created_by**                    uuid                  Yes            FK app_users.id                   Actor tạo; null cho system/bootstrap.

  **updated_at**                    timestamptz           No             DEFAULT now()                     Cập nhật gần nhất.

  **updated_by**                    uuid                  Yes            FK app_users.id                   Actor cập nhật; null cho system.

  **version**                       integer               No             DEFAULT 0                         Optimistic locking / If-Match.
  ------------------------------------------------------------------------------------------------------------------------------------------------

  -------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                        **Index khuyến nghị**
  ----------------------------------------------------------- -------------------------------------------------------
  UNIQUE(component_issue_document_id,line_no); scanned\<=required; Post exact.   UX(component_issue_document_id,line_no); IDX(sku_id).

  -------------------------------------------------------------------------------------------------------------------

### 50. component_issue_items

Serial item hoặc box quantity được lấy. **\[BASELINE + TECH-PROPOSAL\]**

  ----------------------------------------------------------------------------------------------------------------------------------------
  **Field**                     **PostgreSQL type**   **Null**       **Key / Default**             **Mô tả / Rule**
  ----------------------------- --------------------- -------------- ----------------------------- ---------------------------------------
  **id**                        uuid                  No             PK                            Định danh.

  **component_issue_line_id**   uuid                  No             FK component_issue_lines.id   Line.

  **scan_event_id**             uuid                  Yes            FK scan_events.id             Evidence.

  **item_id**                   uuid                  Yes            FK item_instances.id          Serial component.

  **container_id**              uuid                  Yes            FK containers.id              Box quantity.

  **quantity**                  bigint                No             CHECK \>0                     Số lượng.

  **status**                    varchar(20)           No             CHECK ACTIVE/REMOVED/POSTED   Trạng thái.

  **selected_at**               timestamptz           No             DEFAULT now()                 Chọn.

  **selected_by**               uuid                  Yes            FK app_users.id               Actor.

  **created_at**                timestamptz           No             DEFAULT now()                 Thời điểm tạo.

  **created_by**                uuid                  Yes            FK app_users.id               Actor tạo; null cho system/bootstrap.

  **updated_at**                timestamptz           No             DEFAULT now()                 Cập nhật gần nhất.

  **updated_by**                uuid                  Yes            FK app_users.id               Actor cập nhật; null cho system.

  **version**                   integer               No             DEFAULT 0                     Optimistic locking / If-Match.
  ----------------------------------------------------------------------------------------------------------------------------------------

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                                                                     **Index khuyến nghị**
  -------------------------------------------------------------------------------------------------------- --------------------------------------------------------------------------------------------------
  No-negative; item/box available; một item active một command; posted creates COMPONENT_ISSUE movement.   Partial UX(item_id) WHERE status=ACTIVE; IDX(component_issue_line_id,status); IDX(container_id).

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## G. WEB APP PV

### 51. pv_product_profiles

Hồ sơ public/read-only theo SKU. **\[BASELINE + TECH-PROPOSAL\]**

  ---------------------------------------------------------------------------------------------------------------------------------
  **Field**                    **PostgreSQL type**   **Null**       **Key / Default**       **Mô tả / Rule**
  ---------------------------- --------------------- -------------- ----------------------- ---------------------------------------
  **id**                       uuid                  No             PK                      Định danh.

  **sku_id**                   uuid                  No             UNIQUE, FK skus.id      Một profile/SKU.

  **display_name**             varchar(255)          No                                     Tên hiển thị.

  **short_description**        text                  Yes                                    Mô tả ngắn.

  **description**              text                  Yes                                    Mô tả.

  **public_specifications**    jsonb                 Yes                                    Thông số public.

  **published**                boolean               No             DEFAULT false           Publish.

  **featured**                 boolean               No             DEFAULT false           Nổi bật.

  **sort_order**               integer               No             DEFAULT 0               Thứ tự.

  **seo_slug**                 varchar(180)          Yes            UNIQUE NULLS DISTINCT   Deep link.

  **published_at**             timestamptz           Yes                                    Thời điểm.

  **published_by**             uuid                  Yes            FK app_users.id         Actor.

  **last_inventory_sync_at**   timestamptz           Yes                                    Freshness read model.

  **created_at**               timestamptz           No             DEFAULT now()           Thời điểm tạo.

  **created_by**               uuid                  Yes            FK app_users.id         Actor tạo; null cho system/bootstrap.

  **updated_at**               timestamptz           No             DEFAULT now()           Cập nhật gần nhất.

  **updated_by**               uuid                  Yes            FK app_users.id         Actor cập nhật; null cho system.

  **version**                  integer               No             DEFAULT 0               Optimistic locking / If-Match.
  ---------------------------------------------------------------------------------------------------------------------------------

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                                                      **Index khuyến nghị**
  ----------------------------------------------------------------------------------------- -----------------------------------------------------------------------------------------------------------
  Chỉ SKU active được publish; published không phụ thuộc balance; zero-stock vẫn visible.   UX(sku_id); UX(seo_slug) WHERE NOT NULL; IDX(published,featured,sort_order); IDX(last_inventory_sync_at).

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 52. pv_product_images

Ảnh public theo profile. **\[BASELINE + TECH-PROPOSAL\]**

  --------------------------------------------------------------------------------------------------------------------------
  **Field**         **PostgreSQL type**   **Null**       **Key / Default**           **Mô tả / Rule**
  ----------------- --------------------- -------------- --------------------------- ---------------------------------------
  **id**            uuid                  No             PK                          Định danh.

  **profile_id**    uuid                  No             FK pv_product_profiles.id   Profile.

  **storage_url**   varchar(1000)         No                                         Public/CDN URL hoặc key.

  **alt_text**      varchar(255)          Yes                                        Accessibility.

  **sort_order**    integer               No             DEFAULT 0                   Thứ tự.

  **is_primary**    boolean               No             DEFAULT false               Ảnh chính.

  **status**        varchar(20)           No             CHECK ACTIVE/INACTIVE       Trạng thái.

  **created_at**    timestamptz           No             DEFAULT now()               Thời điểm tạo.

  **created_by**    uuid                  Yes            FK app_users.id             Actor tạo; null cho system/bootstrap.

  **updated_at**    timestamptz           No             DEFAULT now()               Cập nhật gần nhất.

  **updated_by**    uuid                  Yes            FK app_users.id             Actor cập nhật; null cho system.

  **version**       integer               No             DEFAULT 0                   Optimistic locking / If-Match.
  --------------------------------------------------------------------------------------------------------------------------

  ------------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                 **Index khuyến nghị**
  ------------------------------------ -----------------------------------------------------------------------------------------------
  Tối đa một primary active/profile.   IDX(profile_id,status,sort_order); partial UX(profile_id) WHERE is_primary AND status=ACTIVE.

  ------------------------------------------------------------------------------------------------------------------------------------

### 53. pv_industries

Ngành nghề dùng cho filter/gợi ý. **\[BASELINE + TECH-PROPOSAL\]**

  ------------------------------------------------------------------------------------------------------------------------
  **Field**           **PostgreSQL type**   **Null**       **Key / Default**       **Mô tả / Rule**
  ------------------- --------------------- -------------- ----------------------- ---------------------------------------
  **id**              uuid                  No             PK                      Định danh.

  **industry_code**   varchar(80)           No             UNIQUE                  Mã.

  **industry_name**   varchar(200)          No                                     Tên.

  **description**     text                  Yes                                    Mô tả.

  **status**          varchar(20)           No             CHECK ACTIVE/INACTIVE   Trạng thái.

  **sort_order**      integer               No             DEFAULT 0               Thứ tự.

  **created_at**      timestamptz           No             DEFAULT now()           Thời điểm tạo.

  **created_by**      uuid                  Yes            FK app_users.id         Actor tạo; null cho system/bootstrap.

  **updated_at**      timestamptz           No             DEFAULT now()           Cập nhật gần nhất.

  **updated_by**      uuid                  Yes            FK app_users.id         Actor cập nhật; null cho system.

  **version**         integer               No             DEFAULT 0               Optimistic locking / If-Match.
  ------------------------------------------------------------------------------------------------------------------------

  -------------------------------------------------------------------------------------------
  **Constraint chính**                           **Index khuyến nghị**
  ---------------------------------------------- --------------------------------------------
  Inactive bị loại khỏi public/recommendation.   UX(industry_code); IDX(status,sort_order).

  -------------------------------------------------------------------------------------------

### 54. pv_tags

Tag/bộ lọc public. **\[BASELINE + TECH-PROPOSAL\]**

  ---------------------------------------------------------------------------------------------------------------------
  **Field**        **PostgreSQL type**   **Null**       **Key / Default**       **Mô tả / Rule**
  ---------------- --------------------- -------------- ----------------------- ---------------------------------------
  **id**           uuid                  No             PK                      Định danh.

  **tag_code**     varchar(80)           No             UNIQUE                  Mã.

  **tag_name**     varchar(200)          No                                     Tên.

  **status**       varchar(20)           No             CHECK ACTIVE/INACTIVE   Trạng thái.

  **sort_order**   integer               No             DEFAULT 0               Thứ tự.

  **created_at**   timestamptz           No             DEFAULT now()           Thời điểm tạo.

  **created_by**   uuid                  Yes            FK app_users.id         Actor tạo; null cho system/bootstrap.

  **updated_at**   timestamptz           No             DEFAULT now()           Cập nhật gần nhất.

  **updated_by**   uuid                  Yes            FK app_users.id         Actor cập nhật; null cho system.

  **version**      integer               No             DEFAULT 0               Optimistic locking / If-Match.
  ---------------------------------------------------------------------------------------------------------------------

  ---------------------------------------------------------------------------
  **Constraint chính**                **Index khuyến nghị**
  ----------------------------------- ---------------------------------------
  Inactive bị loại khỏi public.       UX(tag_code); IDX(status,sort_order).

  ---------------------------------------------------------------------------

### 55. pv_product_industries

Mapping profile-ngành nghề. **\[BASELINE + TECH-PROPOSAL\]**

  --------------------------------------------------------------------------------------------------------------
  **Field**         **PostgreSQL type**   **Null**       **Key / Default**                    **Mô tả / Rule**
  ----------------- --------------------- -------------- ------------------------------------ ------------------
  **profile_id**    uuid                  No             PK part, FK pv_product_profiles.id   Profile.

  **industry_id**   uuid                  No             PK part, FK pv_industries.id         Industry.

  **priority**      integer               No             DEFAULT 0                            Ưu tiên.

  **created_at**    timestamptz           No             DEFAULT now()                        Tạo.

  **created_by**    uuid                  Yes            FK app_users.id                      Actor.
  --------------------------------------------------------------------------------------------------------------

  -----------------------------------------------------------------------
  **Constraint chính**                **Index khuyến nghị**
  ----------------------------------- -----------------------------------
  PK(profile_id,industry_id).         IDX(industry_id,priority).

  -----------------------------------------------------------------------

### 56. pv_product_tags

Mapping profile-tag. **\[BASELINE + TECH-PROPOSAL\]**

  -------------------------------------------------------------------------------------------------------------
  **Field**        **PostgreSQL type**   **Null**       **Key / Default**                    **Mô tả / Rule**
  ---------------- --------------------- -------------- ------------------------------------ ------------------
  **profile_id**   uuid                  No             PK part, FK pv_product_profiles.id   Profile.

  **tag_id**       uuid                  No             PK part, FK pv_tags.id               Tag.

  **created_at**   timestamptz           No             DEFAULT now()                        Tạo.

  **created_by**   uuid                  Yes            FK app_users.id                      Actor.
  -------------------------------------------------------------------------------------------------------------

  -----------------------------------------------------------------------
  **Constraint chính**                **Index khuyến nghị**
  ----------------------------------- -----------------------------------
  PK(profile_id,tag_id).              IDX(tag_id).

  -----------------------------------------------------------------------

### 57. pv_recommendation_rules

Rule gợi ý sản phẩm theo ngành nghề. **\[BASELINE + TECH-PROPOSAL\]**

  ----------------------------------------------------------------------------------------------------------------------------
  **Field**            **PostgreSQL type**   **Null**       **Key / Default**          **Mô tả / Rule**
  -------------------- --------------------- -------------- -------------------------- ---------------------------------------
  **id**               uuid                  No             PK                         Định danh.

  **industry_id**      uuid                  No             FK pv_industries.id        Ngành.

  **sku_id**           uuid                  Yes            FK skus.id                 Selector SKU cụ thể.

  **category_id**      uuid                  Yes            FK product_categories.id   Selector category.

  **tag_id**           uuid                  Yes            FK pv_tags.id              Selector tag.

  **priority**         integer               No             CHECK \>=0                 Ưu tiên trong scope.

  **status**           varchar(20)           No             CHECK ACTIVE/INACTIVE      Trạng thái.

  **effective_from**   timestamptz           Yes                                       Hiệu lực.

  **effective_to**     timestamptz           Yes                                       Hết hiệu lực.

  **created_at**       timestamptz           No             DEFAULT now()              Thời điểm tạo.

  **created_by**       uuid                  Yes            FK app_users.id            Actor tạo; null cho system/bootstrap.

  **updated_at**       timestamptz           No             DEFAULT now()              Cập nhật gần nhất.

  **updated_by**       uuid                  Yes            FK app_users.id            Actor cập nhật; null cho system.

  **version**          integer               No             DEFAULT 0                  Optimistic locking / If-Match.
  ----------------------------------------------------------------------------------------------------------------------------

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                                                                               **Index khuyến nghị**
  ------------------------------------------------------------------------------------------------------------------ -------------------------------------------------------------------------------
  Ít nhất một selector sku/category/tag; sản phẩm cuối phải active+published; priority unique trong scope đã chọn.   IDX(industry_id,status,priority); IDX(sku_id); IDX(category_id); IDX(tag_id).

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## H. MIGRATION / OPENING BALANCE

### 58. migration_runs

Controlled dry-run/commit/reconcile/rollback evidence. **\[BASELINE + TECH-PROPOSAL\]**

  ------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Field**                   **PostgreSQL type**   **Null**       **Key / Default**                                           **Mô tả / Rule**
  --------------------------- --------------------- -------------- ----------------------------------------------------------- -------------------------------
  **id**                      uuid                  No             PK                                                          Định danh.

  **migration_run_code**      varchar(80)           No             UNIQUE                                                      Mã run.

  **package_version**         varchar(50)           No                                                                         Import Package version.

  **source_file_name**        varchar(255)          No                                                                         File.

  **source_checksum**         char(64)              No                                                                         Checksum.

  **mode**                    varchar(20)           No             CHECK DRY_RUN/COMMIT/ROLLBACK                               Chế độ.

  **status**                  varchar(30)           No             CHECK CREATED/RUNNING/PASSED/FAILED/COMMITTED/ROLLED_BACK   Trạng thái.

  **total_rows**              integer               No             DEFAULT 0                                                   Tổng.

  **valid_rows**              integer               No             DEFAULT 0                                                   Hợp lệ.

  **error_rows**              integer               No             DEFAULT 0                                                   Lỗi.

  **reconciliation_status**   varchar(20)           Yes            CHECK PASS/FAIL/PENDING                                     Đối soát.

  **reconciliation_result**   jsonb                 Yes                                                                        Kết quả.

  **started_at**              timestamptz           Yes                                                                        Bắt đầu.

  **finished_at**             timestamptz           Yes                                                                        Kết thúc.

  **executed_by**             uuid                  Yes            FK app_users.id                                             Operator/system.

  **approved_by**             uuid                  Yes            FK app_users.id                                             Data Owner/approver evidence.

  **approved_at**             timestamptz           Yes                                                                        Sign-off.

  **rollback_of_run_id**      uuid                  Yes            FK migration_runs.id                                        Rollback target.

  **note**                    text                  Yes                                                                        Ghi chú.

  **created_at**              timestamptz           No             DEFAULT now()                                               Tạo.
  ------------------------------------------------------------------------------------------------------------------------------------------------------------

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                                                                        **Index khuyến nghị**
  ----------------------------------------------------------------------------------------------------------- -----------------------------------------------------------------------------------------------------------
  COMMIT chỉ khi dry-run + checksum + reconciliation PASS; rerun idempotent theo run/key; không public API.   UX(migration_run_code); IDX(source_checksum); IDX(status,mode); IDX(created_at); IDX(rollback_of_run_id).

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 59. migration_errors

Row/field error cho migration. **\[BASELINE + TECH-PROPOSAL\]**

  ----------------------------------------------------------------------------------------------------------------------------------------------------------
  **Field**              **PostgreSQL type**   **Null**       **Key / Default**              **Mô tả / Rule**
  ---------------------- --------------------- -------------- ------------------------------ ---------------------------------------------------------------
  **id**                 uuid                  No             PK                             Định danh.

  **migration_run_id**   uuid                  No             FK migration_runs.id           Run.

  **dataset**            varchar(80)           No                                            SKU/ITEM/PHYSICAL_CODE/CONTAINER/OPENING_BALANCE/AGENCY.

  **row_no**             integer               Yes                                           Dòng.

  **field_name**         varchar(120)          Yes                                           Field.

  **error_code**         varchar(80)           No                                            DUPLICATE/UNKNOWN_FK/INVALID_ENUM/REQUIRED/RECON_MISMATCH\...

  **severity**           varchar(20)           No             CHECK WARNING/ERROR/CRITICAL   Mức.

  **message**            text                  No                                            Thông báo.

  **raw_value_masked**   text                  Yes                                           Giá trị đã mask nếu PII.

  **suggested_action**   text                  Yes                                           Cách xử lý.

  **created_at**         timestamptz           No             DEFAULT now()                  Tạo.
  ----------------------------------------------------------------------------------------------------------------------------------------------------------

  ------------------------------------------------------------------------------------------------------------------------------------------
  **Constraint chính**                                                     **Index khuyến nghị**
  ------------------------------------------------------------------------ -----------------------------------------------------------------
  Critical/error phải 0 trước commit theo acceptance; không lưu raw PII.   IDX(migration_run_id,dataset,row_no); IDX(severity,error_code).

  ------------------------------------------------------------------------------------------------------------------------------------------

# 4. CROSS-TABLE TRANSACTION VÀ CONSTRAINT BẮT BUỘC

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Transaction**                     **Bắt buộc**
  ----------------------------------- -------------------------------------------------------------------------------------------------------------------------------------------------------
  Post Receipt                        Lock inbound/version; validate exact; insert movement; update balance; update item/container subledger; posting event/audit; commit cùng transaction.

  Post Issue                          Lock outbound/lines/items/balance; validate exact + availability + RFID; movement/balance/item state; posting event/audit; commit.

  Post Component Issue                Lock case active, document/line, component items/boxes, balance; movement/balance; link case; commit.

  Reversal                            Lock original movement/reversible scope; dependency/condition check; create reversal document/movement; update balance/subledger; retain original.

  Excel consume                       Validate all blocking errors=0; lock source key; create request/lines/outbound atomically; duplicate replay.

  Scan-to-label                       Match item under lock/unique active constraint; create immutable label snapshot; enqueue print attempt; no stock change.

  RFID mapping                        Unique hardware_uid/code; map same item; audit; no movement.

  Reconciliation                      Compute ledger vs balance vs subledger; mismatch creates incident; never auto-update ledger.

  Migration commit                    Checksum unchanged; dry-run/reconcile PASS; controlled transaction/batches; rollback evidence; no public endpoint.
  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

# 5. INDEX/SECURITY/RETENTION CHECKLIST CHO TECH REVIEW

-   Xác nhận UUID vs BIGINT/UUIDv7 theo stack; không thay đổi business key uniqueness.

-   Xác nhận partial unique indexes cho active item match, active warranty case/item, primary image/code và một warehouse ACTIVE.

-   Xác nhận lock order thống nhất để tránh deadlock: document -\> lines -\> item/container -\> balance -\> movement.

-   Xác nhận immutable enforcement cho inventory_movements, label payload, POSTED document và warranty_events (append-only).

-   Xác nhận partition/index strategy cho inventory_movements, audit_logs, scan_events, print_events theo volume.

-   Xác nhận PII encryption/masking/search strategy; không index plaintext phone/address nếu không cần.

-   Xác nhận cascade/restrict: không cascade delete authoritative/history; master deactivate; attachment logical delete/private storage.

-   Xác nhận RLS/data scope nếu dùng; backend permission vẫn bắt buộc.

-   Xác nhận backup/RPO/RTO và restore test theo technical baseline.

-   Phát hành DDL migration + ERD diagram + Data Dictionary version/hash/changelog tại WWHN-31 trước Ready for Dev.

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Kết luận ---** Tài liệu này là đề xuất đủ chi tiết để BE/DBA bắt đầu review schema. Chỉ sau khi Tech Lead/BE xác nhận DBMS, DDL, index và constraint thì mới trở thành physical baseline triển khai.
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
