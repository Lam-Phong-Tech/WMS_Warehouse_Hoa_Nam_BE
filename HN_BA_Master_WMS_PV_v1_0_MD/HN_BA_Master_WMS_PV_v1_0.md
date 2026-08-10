**DOCUMENT ID: HN-BA-MASTER-001 \| VERSION 1.0 \| 29/07/2026**

**CONSOLIDATED IMPLEMENTATION & TEST REFERENCE - SOURCE STATUS RETAINED**

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Thuộc tính**                      **Giá trị**
  ----------------------------------- -------------------------------------------------------------------------------------------------------------------------------------------------------------
  Mục đích                            Gộp BRD, SRS/FRD và BPD vào một file duy nhất để DEV/FE/QA/Test tra cứu, triển khai và thiết kế kiểm thử mà không phải mở ba tài liệu riêng.

  Owner                               Business Analyst - Nguyễn Đình Hùng

  Nguồn nhúng                         BRD v3.11; SRS/FRD v1.3; BPD v1.2 (đúng theo metadata/nội dung của ba file đính kèm).

  Phạm vi                             01 kho tổng Hoa Nam; SKU/Physical_Code; nhập, xuất, tồn, kiểm kê, reversal, bảo hành cơ bản; Web App PV read-only; migration/UAT gate.

  Cách sử dụng                        Đọc Phần 1 để nắm baseline nhanh; dùng Navigation Pane/TOC để nhảy tới phần nguồn chi tiết; tìm theo REQ-SRS, BR-, AC-, TC-, BP-WMS hoặc mã chức năng F-WM.

  Giá trị kiểm soát                   Bản hợp nhất là working reference cho triển khai/kiểm thử; không thay thế approval evidence hoặc source precedence của từng artifact gốc.
  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Cảnh báo phiên bản nguồn:** Ba file hiện có tự nhận diện lần lượt là BRD v3.11, SRS/FRD v1.3 và BPD v1.2. Bản Master này không tự nâng SRS lên v1.4 hoặc BPD lên v1.3. Khi có các file phiên bản mới hơn, cần regenerate Master và cập nhật Source/Supersession Register.
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

# 1. HƯỚNG DẪN SỬ DỤNG BẢN HỢP NHẤT

## 1.1. Thứ tự ưu tiên và xử lý mâu thuẫn

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Mức**                 **Nguồn/loại quyết định**                                      **Cách dùng**
  ----------------------- -------------------------------------------------------------- -------------------------------------------------------------------------------------------------------------------
  1                       Hợp đồng/Phụ lục/Approved Change/Decision Record có hiệu lực   Điều khiển phạm vi cam kết và thay đổi đã được phê duyệt.

  2                       BRD v3.11                                                      Điều khiển business scope, actor, nguyên tắc nghiệp vụ, quyết định MVP và nghĩa của chức năng.

  3                       SRS/FRD v1.3                                                   Điều khiển requirement, state, data/API/error/permission, AC/TC, NFR và readiness gate ở mức triển khai/kiểm thử.

  4                       BPD v1.2                                                       Điều khiển trình tự vận hành TO-BE, handoff, exception, RACI, stock effect và process evidence.

  Mâu thuẫn               Không tự suy đoán hoặc chọn thuận tiện                         DEV/TEST ghi open point, dẫn đúng ID/section và yêu cầu BA/PM disposition trước khi thay đổi semantics.
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 1.2. Bản đồ tra cứu nhanh cho DEV và TEST

  -----------------------------------------------------------------------------------------------------------------------------------------------------------
  **Nhu cầu tra cứu**                               **Phần nên đọc đầu tiên**                       **ID/từ khóa nên tìm**
  ------------------------------------------------- ----------------------------------------------- ---------------------------------------------------------
  Scope, Must/Out-of-scope, actor, quyết định MVP   Phần A - BRD                                    DEC-WM, WM-SCOPE, F-WM, MoSCoW

  Requirement và Acceptance Criteria                Phần B - SRS/FRD §4 và §13.2                    REQ-SRS, AC-SRS, AC-GWT

  Quy trình chi tiết và exception                   Phần C - BPD §5                                 BP-WMS-01..13

  State và điểm làm đổi tồn                         SRS §6; BPD §6.1-6.2; BRD §14.5-14.6            DRAFT, PARTIALLY_POSTED, POSTED, APPLIED, reversal

  Data model/ERD/Data Dictionary                    SRS §7; BRD §5                                  Inventory_Movement, Balance, Item_Instance, Container

  API/error/idempotency/concurrency                 SRS §8, §13.3, §13.5                            Idempotency-Key, If-Match, 409/412/422/428

  Permission và negative test                       SRS §3, §8; BRD §23.3-23.4                      permission key, backend enforcement

  Stocktake                                         BPD §5.7; SRS §5.3; BRD §7.5/8.4                snapshot, count_cutoff_at, expected_at_count

  Reversal/correction                               BPD §5.10; SRS §5.5; BRD §6.4/8.3               append-only, downstream dependency, reversible_qty

  App PV                                            BRD §2.4/6.8; BPD §5.11; SRS REQ-017..020/033   published_to_app, available_qty, last_inventory_sync_at

  Migration/UAT/Go-live                             BPD §5.13; SRS REQ-021/022 và §13.12-13.14      dry-run, checksum, reconcile, rollback, Go/No-Go
  -----------------------------------------------------------------------------------------------------------------------------------------------------------

## 1.3. Baseline bắt buộc không được diễn giải khác

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **ID nhanh**            **Baseline thống nhất**                                                                     **Hệ quả DEV/TEST**
  ----------------------- ------------------------------------------------------------------------------------------- ----------------------------------------------------------------------------------------------------------------
  BASE-01                 MVP chỉ quản lý 01 kho tổng; Agency/đại lý là nơi nhận, không phải warehouse.               Không tạo balance/movement/stocktake cho agency; Post Issue chỉ giảm kho tổng.

  BASE-02                 SKU là loại hàng; item/carton/box/tray dùng Physical_Code riêng.                            Không dùng container làm SKU hoặc stock bucket; không double count.

  BASE-03                 ITEM bắt buộc carrier chip; CARTON/BOX/TRAY carrier linh hoạt.                              Nghiệp vụ xử lý code_value; driver/protocol phần cứng chuyên sâu ngoài MVP.

  BASE-04                 Tồn chỉ đổi tại Post Receipt, Post Issue, Apply Adjustment hoặc Reversal đã post.           DRAFT/SCANNING/Putaway/Warranty/App PV không tự tạo Inventory_Movement.

  BASE-05                 Inbound/Outbound hỗ trợ partial multi-post và close remainder.                              Có PARTIALLY_POSTED; không over-receipt/over-issue; close remainder không đổi tồn.

  BASE-06                 Mọi action đổi tồn phải idempotent và atomic.                                               Retry cùng key/payload không double stock; key cũ/payload khác bị conflict; rollback toàn transaction khi lỗi.

  BASE-07                 Inventory_Movement là authoritative ledger; Balance là projection; item/box là subledger.   Ba lớp phải reconcile; lệch tạo incident, không sửa/xóa ledger gốc.

  BASE-08                 Stocktake không khóa toàn kho; dùng snapshot + net movement đến cutoff.                     Test movement warning, stale version, partial approval và adjustment idempotent.

  BASE-09                 Reversal/correction append-only và có dependency guard.                                     Giữ movement gốc; partial reversal không vượt reversible_qty; đảo dependency theo thứ tự ngược.

  BASE-10                 Warranty có mã không làm tăng tồn bán; TEMP_ONLY không gắn item gốc.                        Một active case/item; RETURNED_TO_CUSTOMER không tự trở thành IN_STOCK; TEMP_ONLY không sinh stock movement.

  BASE-11                 App PV là Web App responsive read-only.                                                     Chỉ active/published; hiển thị available_qty kho tổng; không cart/order/reservation/payment/stock effect.

  BASE-12                 zone_code/location_hint/tray chỉ là locator/hint.                                           Không tạo balance theo zone/tray; Putaway chỉ cập nhật mapping.

  BASE-13                 Backend phải enforce action-level permission và data scope.                                 Không chỉ ẩn nút UI; test 401/403/404 masking, permission denied và scope.

  BASE-14                 Migration và UAT evidence là downstream release gate.                                       Dry-run/reconciliation/rollback và actual test evidence không được giả định pass chỉ vì BA document Done.
  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 1.4. Ma trận Requirement - Process - Test để bắt đầu triển khai

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Nhóm**              **Requirement chính**             **Process chính**   **Test focus**
  --------------------- --------------------------------- ------------------- ------------------------------------------------------------------------------------------------------
  Foundation/Master     REQ-SRS-001..005, 026..029, 034   BP-WMS-01           Scope 01 kho; unique master/code; serial mode; agency không sinh tồn; inactive guard.

  Inbound               REQ-SRS-006..009                  BP-WMS-02..03       Draft no stock; scan batch; partial receipt; idempotency; rollback; Putaway no movement.

  Outbound              REQ-SRS-010..012                  BP-WMS-04..05       Partial issue; availability at commit; item/box lock; no negative stock; agency no balance.

  Reversal              REQ-SRS-013                       BP-WMS-10           Append-only; permission/reason; dependency order; partial qty; report net effect.

  Stocktake/Inventory   REQ-SRS-014..015, 023, 030..032   BP-WMS-06..07, 12   Snapshot/cutoff formula; warning/ack; stale guard; 3-layer reconcile; reports/export scope.

  Warranty              REQ-SRS-016, 028                  BP-WMS-08..09       Valid item state; one active case; TEMP_ONLY; privacy/masking; no stock effect.

  App PV                REQ-SRS-017..020, 033             BP-WMS-11           Responsive; published only; available_qty read-only; rule recommendation; cache freshness/degraded.

  Migration/UAT         REQ-SRS-021..022                  BP-WMS-13           Dry-run no change; checksum/error file; commit/reconcile; rollback reference; UAT evidence/Go-No-Go.

  Technical contract    REQ-SRS-024..025                  Cross-process       HTTP/error/If-Match/idempotency/security; readiness gate not falsely marked ready.
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 1.5. Stock effect và test ưu tiên

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Action/Process**                       **Stock effect**                            **Test tối thiểu**
  ---------------------------------------- ------------------------------------------- ---------------------------------------------------------------------------
  Create/Edit/Scan Inbound hoặc Outbound   None                                        Không sinh movement; không đổi balance/subledger.

  Post Receipt                             Increase                                    Partial/full; retry; conflict payload; rollback; item/carton/box state.

  Post Issue                               Decrease                                    Available/remaining guard; concurrency; không âm; agency không tăng tồn.

  Putaway                                  None                                        Box-tray mapping thay đổi; available_qty và movement giữ nguyên.

  Stocktake Count/Review                   None                                        Snapshot/cutoff/warning/approval line; scan trùng không tính trùng.

  Apply Adjustment                         Increase/Decrease                           Approved line only; stale guard; idempotent; atomic.

  Warranty receive/update/return           None                                        Item state đổi nhưng sellable available không tăng; TEMP_ONLY không link.

  Post Reversal                            Opposite of original                        Dependency/reversible qty/condition; giữ movement gốc; correction chain.

  App PV publish/view                      None                                        Read-only; active/published; cache timestamp; không tạo nghiệp vụ kho.

  Migration commit/opening                 Opening movement/balance by approved plan   Chỉ sau dry-run PASS; reconcile; failed reconciliation block go-live.
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Quy tắc bàn giao:** DEV không dùng riêng BRD để suy ra API/DB; TEST không dùng riêng SRS để bỏ qua ngoại lệ/handoff trong BPD. Mọi test case quan trọng phải trace tối thiểu Source/Decision -\> REQ/BR -\> AC -\> TC -\> evidence.
  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

# **MỤC LỤC ĐIỀU HƯỚNG (NHẤP ĐỂ MỞ PHẦN)**

*Mục lục tĩnh, hoạt động không cần cập nhật field. Dùng Navigation Pane hoặc Ctrl+F để tra cứu ID chi tiết.*

[**[1. HƯỚNG DẪN SỬ DỤNG BẢN HỢP NHẤT]{.underline}**](#hướng-dẫn-sử-dụng-bản-hợp-nhất)

> [[1.1. Thứ tự ưu tiên và xử lý mâu thuẫn]{.underline}](#thứ-tự-ưu-tiên-và-xử-lý-mâu-thuẫn)
>
> [[1.2. Bản đồ tra cứu nhanh cho DEV và TEST]{.underline}](#bản-đồ-tra-cứu-nhanh-cho-dev-và-test)
>
> [[1.3. Baseline bắt buộc không được diễn giải khác]{.underline}](#baseline-bắt-buộc-không-được-diễn-giải-khác)
>
> [[1.4. Ma trận Requirement - Process - Test để bắt đầu triển khai]{.underline}](#ma-trận-requirement---process---test-để-bắt-đầu-triển-khai)
>
> [[1.5. Stock effect và test ưu tiên]{.underline}](#stock-effect-và-test-ưu-tiên)

[**[PHẦN A. BRD v3.11 - BUSINESS SCOPE, RULE VÀ ACCEPTANCE BASELINE]{.underline}**](#phần-a.-brd-v3.11---business-scope-rule-và-acceptance-baseline)

> [[1. Tóm tắt điều chỉnh theo khảo sát thực tế]{.underline}](#tóm-tắt-điều-chỉnh-theo-khảo-sát-thực-tế)
>
> [[2. Tổng quan module và phạm vi]{.underline}](#tổng-quan-module-và-phạm-vi)
>
> [[3. Actors và phân quyền MVP]{.underline}](#actors-và-phân-quyền-mvp)
>
> [[4. Nguyên tắc SKU và mã quản lý vật lý]{.underline}](#nguyên-tắc-sku-và-mã-quản-lý-vật-lý)
>
> [[5. Mô hình dữ liệu nghiệp vụ]{.underline}](#mô-hình-dữ-liệu-nghiệp-vụ)
>
> [[6. Phân rã chức năng module]{.underline}](#phân-rã-chức-năng-module)
>
> [[7. Luồng nghiệp vụ chi tiết]{.underline}](#luồng-nghiệp-vụ-chi-tiết)
>
> [[8. Business rules và validation]{.underline}](#business-rules-và-validation)
>
> [[9. Báo cáo kho và báo cáo bảo hành]{.underline}](#báo-cáo-kho-và-báo-cáo-bảo-hành)
>
> [[10. Phạm vi MVP, ngoài MVP và MoSCoW]{.underline}](#phạm-vi-mvp-ngoài-mvp-và-moscow)
>
> [[11. Acceptance criteria và test scenario gợi ý]{.underline}](#acceptance-criteria-và-test-scenario-gợi-ý)
>
> [[12. Open points cần khách xác nhận]{.underline}](#open-points-cần-khách-xác-nhận)
>
> [[13. Phụ lục thuật ngữ và công thức tồn kho]{.underline}](#phụ-lục-thuật-ngữ-và-công-thức-tồn-kho)
>
> [[14. Phụ lục bổ sung]{.underline}](#phụ-lục-bổ-sung)
>
> [[15. Wireframe / screen flow cho các màn chính]{.underline}](#wireframe-screen-flow-cho-các-màn-chính)
>
> [[16. Field-level UI spec]{.underline}](#field-level-ui-spec)
>
> [[17. Data import template]{.underline}](#data-import-template)
>
> [[18. NFR cơ bản]{.underline}](#nfr-cơ-bản)
>
> [[19. Backlog (dạng user story)]{.underline}](#backlog-dạng-user-story)
>
> [[20. HISTORICAL P0 CLOSURE PACKAGE - v3.10 (SUPERSEDED BY v3.11)]{.underline}](#historical-p0-closure-package---v3.10-superseded-by-v3.11)
>
> [[21. P0 REMEDIATION PACKAGE - REVIEW 22/07/2026]{.underline}](#p0-remediation-package---review-22072026)
>
> [[22. P0 FINAL CLOSURE REGISTER - 23/07/2026]{.underline}](#p0-final-closure-register---23072026)
>
> [[23. P1 FINAL CLOSURE PACKAGE - BRD v3.11]{.underline}](#p1-final-closure-package---brd-v3.11)

[**[PHẦN B. SRS/FRD v1.3 - IMPLEMENTATION, DATA, API VÀ TEST BASELINE]{.underline}**](#phần-b.-srsfrd-v1.3---implementation-data-api-và-test-baseline)

> [[SRS/FRD - HỆ THỐNG QUẢN LÝ KHO & WEB APP XEM SẢN PHẨM]{.underline}](#srsfrd---hệ-thống-quản-lý-kho-web-app-xem-sản-phẩm)
>
> [[0. DOCUMENT CONTROL]{.underline}](#document-control)
>
> [[1. PURPOSE, SCOPE AND SOURCE]{.underline}](#purpose-scope-and-source)
>
> [[2. DECISION RECORDS AND GOVERNANCE]{.underline}](#decision-records-and-governance)
>
> [[3. ACTORS, IDENTITY AND ACTION-LEVEL PERMISSION]{.underline}](#actors-identity-and-action-level-permission)
>
> [[4. FUNCTIONAL REQUIREMENTS]{.underline}](#functional-requirements)
>
> [[5. CORE FLOW SPECIFICATION AND BUSINESS RULES]{.underline}](#core-flow-specification-and-business-rules)
>
> [[6. STATE MODEL]{.underline}](#state-model)
>
> [[7. DATA MODEL AND DATA DICTIONARY]{.underline}](#data-model-and-data-dictionary)
>
> [[8. API, ERROR, PERMISSION AND UI BASELINE]{.underline}](#api-error-permission-and-ui-baseline)
>
> [[9. NON-FUNCTIONAL REQUIREMENTS]{.underline}](#non-functional-requirements)
>
> [[10. TRACEABILITY MASTER]{.underline}](#traceability-master)
>
> [[11. TECHNICAL BASELINE AND READINESS GATE]{.underline}](#technical-baseline-and-readiness-gate)
>
> [[12. CHANGE LOG]{.underline}](#change-log)
>
> [[13. P1 IMPLEMENTATION READINESS BASELINE]{.underline}](#p1-implementation-readiness-baseline)

[**[PHẦN C. BPD v1.2 - PROCESS, BPMN, EXCEPTION VÀ OPERATION BASELINE]{.underline}**](#phần-c.-bpd-v1.2---process-bpmn-exception-và-operation-baseline)

> [[0. DOCUMENT CONTROL]{.underline}](#document-control-1)
>
> [[1. MỤC TIÊU, PHẠM VI VÀ NGUỒN]{.underline}](#mục-tiêu-phạm-vi-và-nguồn)
>
> [[2. AS-IS Ở MỨC DISCOVERY]{.underline}](#as-is-ở-mức-discovery)
>
> [[3. KIẾN TRÚC QUY TRÌNH TO-BE]{.underline}](#kiến-trúc-quy-trình-to-be)
>
> [[4. ACTOR, RACI VÀ PHÂN TÁCH TRÁCH NHIỆM]{.underline}](#actor-raci-và-phân-tách-trách-nhiệm)
>
> [[5. QUY TRÌNH NGHIỆP VỤ TO-BE CHI TIẾT]{.underline}](#quy-trình-nghiệp-vụ-to-be-chi-tiết)
>
> [[5.2. BP-WMS-02 – Nhập kho sản phẩm/máy theo thùng]{.underline}](#bp-wms-02-nhập-kho-sản-phẩmmáy-theo-thùng)
>
> [[5.3. BP-WMS-03 – Nhập kho linh kiện và Putaway]{.underline}](#bp-wms-03-nhập-kho-linh-kiện-và-putaway)
>
> [[5.4. BP-WMS-04 – Xuất kho sản phẩm]{.underline}](#bp-wms-04-xuất-kho-sản-phẩm)
>
> [[5.5. BP-WMS-05 – Xuất kho linh kiện]{.underline}](#bp-wms-05-xuất-kho-linh-kiện)
>
> [[5.6. BP-WMS-06 – Tra cứu tồn và đối soát]{.underline}](#bp-wms-06-tra-cứu-tồn-và-đối-soát)
>
> [[5.7. BP-WMS-07 – Kiểm kê và điều chỉnh]{.underline}](#bp-wms-07-kiểm-kê-và-điều-chỉnh)
>
> [[5.8. BP-WMS-08 – Bảo hành máy có mã]{.underline}](#bp-wms-08-bảo-hành-máy-có-mã)
>
> [[5.9. BP-WMS-09 – Bảo hành TEMP_ONLY]{.underline}](#bp-wms-09-bảo-hành-temp_only)
>
> [[5.10. BP-WMS-10 – Reversal và Correction sau Post]{.underline}](#bp-wms-10-reversal-và-correction-sau-post)
>
> [[5.11. BP-WMS-11 – Publish và cung cấp tồn cho App PV]{.underline}](#bp-wms-11-publish-và-cung-cấp-tồn-cho-app-pv)
>
> [[5.12. BP-WMS-12 – Báo cáo vận hành]{.underline}](#bp-wms-12-báo-cáo-vận-hành)
>
> [[5.13. BP-WMS-13 – Migration và Opening Balance]{.underline}](#bp-wms-13-migration-và-opening-balance)
>
> [[5.14. End-to-End Scenario – Nhập → Tồn → Xuất → Bảo hành → Trả/Reversal → App PV/Báo cáo]{.underline}](#end-to-end-scenario-nhập-tồn-xuất-bảo-hành-trảreversal-app-pvbáo-cáo)
>
> [[6. QUY TẮC KIỂM SOÁT XUYÊN QUY TRÌNH]{.underline}](#quy-tắc-kiểm-soát-xuyên-quy-trình)
>
> [[7. INPUT/OUTPUT, BIỂU MẪU VÀ CHỈ SỐ QUY TRÌNH]{.underline}](#inputoutput-biểu-mẫu-và-chỉ-số-quy-trình)
>
> [[8. TRACEABILITY VÀ GATE]{.underline}](#traceability-và-gate)
>
> [[9. DOWNSTREAM FOLLOW-UP VÀ WAIVER REGISTER]{.underline}](#downstream-follow-up-và-waiver-register)
>
> [[10. PHỤ LỤC THUẬT NGỮ]{.underline}](#phụ-lục-thuật-ngữ)
>
> [[11. KẾT LUẬN]{.underline}](#kết-luận)

# PHẦN A. BRD v3.11 - BUSINESS SCOPE, RULE VÀ ACCEPTANCE BASELINE

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Nguồn được nhúng nguyên nội dung:** File \[DOC\]\[BA\]\[BRD\] Tài liệu yêu cầu nghiệp vụ WMS(2).docx; tiêu đề nội bộ BRD v3.11. Nội dung được nhúng theo đúng thứ tự nguồn, bao gồm cả closure/history để bảo toàn traceability.
  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

**\
BRD - MODULE QUẢN LÝ KHO & APP XEM SẢN PHẨM**

**Hoa Nam Tools**

VERSION 3.11 \| FINAL APPROVAL CANDIDATE \| BASELINE EFFECTIVE ON WWHN-333 DONE

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **TRẠNG THÁI TÀI LIỆU**
  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  FINAL APPROVAL CANDIDATE. BRD v3.11 trở thành APPROVED/BASELINE với ngày hiệu lực 23/07/2026 khi PM/PO transition Jira WWHN-333 sang DONE. Lịch sử transition, attachment v3.11 tại thời điểm DONE và comment review (nếu có) là formal approval evidence. OpenAPI, physical ERD, Figma chi tiết, TestCase Master và execution evidence là các artifact hậu BRD, bắt buộc trước Ready for Dev/QA nhưng không phải điều kiện tiên quyết để BA hoàn tất BRD.

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 1. Tóm tắt điều chỉnh theo khảo sát thực tế

Sau khảo sát thực tế và vòng rà soát P0 ngày 22/07/2026, BRD chốt một baseline Beta duy nhất: quản lý 01 kho tổng, Stocktake, bảo hành cơ bản và App PV Web App responsive đều là Must. Hệ thống dùng một lớp Physical_Code làm nguồn định danh; ITEM bắt buộc dùng chip; CARTON/BOX/TRAY dùng carrier cấu hình linh hoạt (QR, Barcode, RFID/NFC hoặc Manual), không mặc định bắt buộc chip; Physical_Code là định danh nghiệp vụ duy nhất và tích hợp protocol phần cứng chuyên sâu thuộc P2. SKU mới dùng format chuẩn thống nhất; mã cũ chỉ giữ dưới dạng alias. Mọi action làm đổi tồn gồm Post Receipt, Post Issue và Apply Adjustment bắt buộc idempotent. Migration được quản lý tại WWHN-69, owner Duy Khánh Nguyễn; UAT được quản lý tại WWHN-75, UAT Owner Nguyễn Đình Hùng, QA Owner Đặng Thị Soi và PM/PO Nguyễn Quang Đức. Hai ticket được kích hoạt sau khi WWHN-333 hoàn tất baseline.

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Nhóm nội dung**   **BRD cũ theo giả định**                                               **Cập nhật theo khảo sát thực tế**
  ------------------- ---------------------------------------------------------------------- -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  Mô hình kho         Có xu hướng thiết kế nhiều kho/đại lý và phân quyền theo kho/đại lý.   MVP quản lý 1 kho tổng. Đại lý/cửa hàng con là đối tượng nhận hàng/đối tác xuất; có chức năng tạo đại lý nhưng không quản lý tồn đại lý trên hệ thống.

  Vị trí trong kho    Loại khỏi MVP nhưng vẫn có nhiều giả định về scan point/cửa kho.       Không quản lý giá, kệ, bin, sơ đồ kho. Hàng được để tự do tại chỗ trống. Với linh kiện, khay chỉ là mã hỗ trợ tìm kiếm hộp linh kiện.

  Bao bì/thùng/hộp    Package/carton tracking bị đưa sang Phase 2/optional.                  Đưa vào MVP vì thực tế sản phẩm nằm trong thùng lớn có mã/chip; linh kiện nằm trong box/khay có mã/chip. Hộp đựng 1 sản phẩm chỉ là vỏ đựng và dùng cùng mã với sản phẩm, không tính là container/SKU riêng.

  SKU                 SKU chủ yếu đại diện model/máy.                                        SKU dùng chung cho sản phẩm và linh kiện. SKU là loại hàng; mã máy, mã thùng, mã hộp, mã khay là mã quản lý vật lý riêng.

  Sản phẩm vật lý     Theo item-level/máy vật lý.                                            Giữ item-level cho máy/sản phẩm có dán mã. Bổ sung mapping máy thuộc thùng nào để hỗ trợ xuất nguyên thùng hoặc xuất lẻ.

  Linh kiện           Chưa được tách rõ trong MVP.                                           Tách quản lý linh kiện riêng. Linh kiện có thể có serial hoặc chỉ quản lý số lượng trong hộp/khay nếu quá nhỏ không dán mã.

  Bảo hành            Bảo hành cơ bản, chưa quản lý linh kiện/chi phí/SLA.                   Giữ đơn giản: tiếp nhận về phòng bảo hành kho tổng, ghi tên/SĐT người gửi, bệnh máy, trạng thái xử lý, trả khách. Nếu mất chip/mã máy và không thể định danh chính xác, tạo case TEMP_ONLY theo mã tạm, không truy vết về máy đã bán và không làm tăng tồn bán.

  Báo cáo             Tồn kho, nhập/xuất, scan, bảo hành.                                    Bổ sung rõ báo cáo xuất nhập tồn và báo cáo số lượng máy loại A bị bệnh B trong một khoảng thời gian.

  Máy quét/chip       Chưa chốt rõ thiết bị, có xu hướng chỉ nhập/scan mã thủ công.          MVP quản lý chip/mã cho sản phẩm, thùng/carton, box, tray. Hiện chưa có máy quét thật nên bổ sung Demo máy quét; luồng nghiệp vụ vẫn dùng thuật ngữ scan.

  Putaway linh kiện   Có thể gắn box/tray ngay khi nhập.                                     Post nhập làm tăng tồn ngay và để trạng thái Chờ xếp khay. Khi nhân viên thật sự đưa box vào khay mới thực hiện Putaway/Xếp khay để cập nhật vị trí.
  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 1.1. Nguyên tắc thiết kế mới

-   **Ưu tiên đơn giản:** thiết kế đúng nhu cầu kho tổng hiện tại, tránh mở rộng scope sang nhiều kho/đại lý, bán hàng hoặc kế toán.

-   **Không double count tồn kho:** thùng/hộp/khay là container/locator, không phải SKU và không phải đơn vị tồn độc lập nếu bên trong đã có item hoặc quantity.

-   Hỗ trợ vận hành hiện tại và tương lai: nghiệp vụ thống nhất dùng thuật ngữ “scan”. MVP sử dụng một Physical_Code duy nhất cho từng item/carton/box/tray; ITEM bắt buộc dùng chip. CARTON/BOX/TRAY dùng carrier cấu hình linh hoạt theo Decision Record; có thể là QR, Barcode, RFID/NFC hoặc Manual. Mọi carrier cùng map về một code_value duy nhất. Giao diện Demo máy quét và nhập tay dùng chung flow; driver/gateway/protocol phần cứng chuyên sâu thuộc P2.

-   **Tách sản phẩm và linh kiện:** sản phẩm bán ra quản lý theo SKU + item/serial + thùng; linh kiện quản lý theo SKU + serial nếu có hoặc số lượng theo hộp/khay.

-   Nguyên tắc cập nhật tồn: Phiếu nhập và phiếu xuất không có bước chờ duyệt/duyệt. Tồn chỉ thay đổi khi Post Receipt, Post Issue hoặc Apply Adjustment thành công. Cả ba action bắt buộc có Idempotency-Key, transaction boundary, audit và duplicate protection; retry cùng key/cùng payload trả lại kết quả cũ, không tạo movement hoặc cập nhật tồn lần hai.

-   Nguyên tắc container: Xem tồn theo SKU/trạng thái; container/carton/box/tray chỉ là thông tin truy vết, vị trí gợi ý và mapping chứa hàng, không phải lớp tồn độc lập.

-   Nguyên tắc linh kiện serial: MVP Must hỗ trợ cả 2 mode của linh kiện: is_serial_tracked = true thì scan serial; is_serial_tracked = false thì quản lý quantity theo box. Không bắt buộc mọi linh kiện đều có serial.

### 1.2. Các nghiệp vụ cần chốt và cách chốt theo BA

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **STT**                **Yêu cầu đã chốt**                                                                                 **Cách sửa/bổ sung trong BRD**
  ---------------------- --------------------------------------------------------------------------------------------------- ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  1                      Máy quét phạm vi rộng có thể quét nhiều mã/chip trong một lần để tiết kiệm thời gian kiểm kê.       Bổ sung rule Scan batch/Wide-range scan: một lần scan có thể trả về nhiều mã; hệ thống nhận danh sách mã, phân loại mã thùng/mã sản phẩm/mã box/mã khay, đối chiếu và cảnh báo trùng/lạ/thiếu.

  2                      Hiện chưa có máy quét nhưng cần triển khai như đã có máy quét; thêm chức năng demo máy quét.        Luồng nghiệp vụ vẫn ghi “scan”. UI có nút Demo máy quét để giả lập dữ liệu scan; nhập tay là fallback cho cùng field, không tách flow nghiệp vụ.

  3                      MVP dùng một Physical_Code; ITEM bắt buộc dùng chip; CARTON/BOX/TRAY cấu hình carrier linh hoạt.    Physical_Code độc lập carrier. ITEM: RFID/NFC chip bắt buộc. CARTON/BOX/TRAY: QR, BARCODE, RFID/NFC hoặc MANUAL theo cấu hình. Hardware protocol/driver/gateway không thuộc MVP.

  4                      Kiểm kê không cần chặn nhập/xuất trong hệ thống.                                                    Không khóa toàn kho. Khi tạo phiên ghi stocktake_started_at và snapshot. Với từng count ghi count_cutoff_at; expected_at_count = snapshot_qty + net_movement_after_snapshot_to_cutoff; variance_to_apply = actual_qty - expected_at_count. Movement giao cắt phải hiển thị/ack; hỗ trợ partial approval. Apply Adjustment idempotent và chạy trong một transaction.

  5                      Thêm chức năng tạo đại lý.                                                                          Đổi “Danh mục đối tượng nhận hàng” thành “Quản lý đại lý/cửa hàng con” ở MVP Must; đại lý chỉ là đối tượng nhận hàng, không quản lý tồn.

  6                      Bảo hành có thể bị mất chip/mã máy và không thể truy vết chính xác về máy đã bán.                   Bổ sung case bảo hành TEMP_ONLY: hệ thống sinh temporary_item_code/mã temp để dán hoặc ghi trên máy, item_code để trống, không liên kết với máy đã bán, không dùng để xác nhận lịch sử bán hàng. Vẫn bắt buộc có tên/SĐT khách, bệnh máy, lý do không có mã và mô tả nhận diện máy.

  7                      Máy quét đọc được cả mã thùng và mã các sản phẩm trong thùng trong một lần.                         Bổ sung rule phân loại mã sau scan batch: system_code_type = CARTON/ITEM/BOX/TRAY/UNKNOWN; thùng và item được nhận cùng batch, hệ thống tự map và validate item thuộc thùng.

  8                      Linh kiện có mã riêng cần có bước xếp khay/gán khay sau nhập.                                       Bổ sung Putaway/Xếp khay sau Post Receipt: tồn tăng ngay khi post nhập; khay chỉ cập nhật vị trí tìm kiếm, không làm tăng/giảm tồn.

  9                      Hộp đựng 1 sản phẩm chỉ là vỏ đựng; mã trên hộp và mã trên sản phẩm là cùng một mã.                 Làm rõ thuật ngữ: hộp đựng đơn chiếc của sản phẩm không phải container riêng; không tạo mã hộp sản phẩm độc lập. Chỉ thùng lớn/carton chứa nhiều sản phẩm mới có mã container riêng.

  10                     Bảo hành scan/nhập mã máy nếu có; nếu mất chip/mã và khách không nhớ mã thì quản lý theo mã temp.   Cập nhật flow bảo hành và field dữ liệu: item_code optional, temporary_item_code bắt buộc với case TEMP_ONLY, identification_status = TEMP_ONLY, missing_code_reason và manual_product_description bắt buộc. Không cố truy vết máy đã bán khi thiếu định danh gốc.

  11                     Linh kiện có thể có serial hoặc không có serial.                                                    Chốt MVP Must hỗ trợ cả 2 mode linh kiện: is_serial_tracked = true thì scan serial; is_serial_tracked = false thì quản lý quantity theo box. Không bắt buộc mọi linh kiện đều có serial.

  12                     Nhập/xuất không cần duyệt và không có trạng thái chờ duyệt.                                         Cập nhật toàn BRD: Phiếu nhập và phiếu xuất dùng DRAFT/SCANNING/PARTIALLY_POSTED/POSTED/CLOSED/CANCELLED; không có bước gửi duyệt/duyệt. Bỏ mọi action gửi duyệt/duyệt/từ chối duyệt cho nhập/xuất. Chỉ Adjustment kiểm kê cần QL kho bấm Áp dụng; action này đồng thời xác nhận/duyệt và post tồn, sau đó phiên chuyển APPLIED.

  13                     Post Receipt, Post Issue và Apply Adjustment kiểm kê phải idempotent.                               Idempotency-Key bắt buộc với cả ba action đổi tồn. Retry cùng key/cùng payload trả kết quả cũ; cùng key/payload khác trả 409; không sinh duplicate movement hoặc cập nhật tồn lần hai.

  14                     Item bảo hành có mã máy cần state riêng.                                                            Luồng item có mã hỗ trợ nhiều chu kỳ: ISSUED hoặc RETURNED_TO_CUSTOMER → IN_WARRANTY → RETURNED_TO_CUSTOMER. Một item chỉ được có tối đa một warranty case active tại một thời điểm.

  15                     App PV phải hiển thị số lượng tồn.                                                                  Chốt App PV hiển thị available_qty từ kho tổng theo SKU, kèm trạng thái Còn hàng/Hết hàng. Không hiển thị serial/item/container/box/tray chi tiết và không tạo nghiệp vụ kho.
  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 1.3. Decision Log - Xác nhận scope MVP kho tổng

Mục này dùng để đóng điểm P0-blocker của PM liên quan đến quyết định “MVP chỉ quản lý 1 kho tổng, không quản lý tồn đại lý”. Theo khảo sát thực tế, khách hàng đã xác nhận quyết định này, vì vậy blocker được chuyển sang trạng thái Resolved và cần được trace rõ trong Scope, Data Model, API/TC và Test Scenario.

DEC-WM-001 - BASELINE DECISION READY FOR FORMAL APPROVAL: MVP chỉ quản lý 01 kho tổng Hoa Nam. Đại lý/cửa hàng con chỉ là đối tượng nhận hàng/đối tác xuất hàng, không phải kho nghiệp vụ trong MVP. Formal approval evidence được kiểm soát bằng BRD v3.11, comment phê duyệt và transition WWHN-333 sang DONE.

DEC-WM-002 - BASELINE DECISION READY FOR FORMAL APPROVAL: Không quản lý tồn kho tại đại lý/cửa hàng con. Không có Inventory_Balance theo agency, không có kiểm kê đại lý, không có movement tăng tồn tại đại lý sau khi xuất. Quyết định có hiệu lực cùng BRD v3.11 khi WWHN-333 DONE.

DEC-WM-003 - BASELINE DECISION READY FOR FORMAL APPROVAL: Khi Post Issue cho đại lý, hệ thống chỉ giảm tồn kho tổng và ghi nhận agency_id/nơi nhận trên phiếu xuất để phục vụ truy vết/báo cáo xuất cho ai. Quyết định có hiệu lực cùng BRD v3.11 khi WWHN-333 DONE.

P0 content status: RESOLVED AND INCLUDED IN FORMAL APPROVAL PACKAGE. Các quyết định scope đã được đồng bộ tại Scope, Data Model, Rule, AC/TC và Traceability. WWHN-333 DONE cùng attachment/comment v3.11 là formal approval evidence; không cần một vòng ký riêng cho từng DEC-WM.

### 1.4. Decision Log bổ sung - P1 sign-off scope MVP và out-of-scope

Mục này bổ sung trace cho các rủi ro P1 trước khi DEV/TEST triển khai sâu. Các điểm đã được khách hàng/đối tác chốt được ghi thành Decision Log để không mở lại scope khi estimate, thiết kế API hoặc viết test case.

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Mã / trạng thái**   **Quyết định scope / out-of-scope đã chốt**                                                                                                                                   **Hướng xử lý cho Dev/Test**
  --------------------- ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- -------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  DEC-WM-004\           App PV là app xem/tra cứu sản phẩm. Không đặt mua, không giữ hàng, không thanh toán, không tạo phiếu xuất và không tạo nghiệp vụ kho trong MVP.                               Không build cart/order/reservation/payment API. Test xác nhận App PV read-only và không sinh Stock Movement.
  Confirmed                                                                                                                                                                                           

  DEC-WM-005\           Bảo hành MVP chỉ quản lý tiếp nhận, bệnh máy, trạng thái xử lý và trả khách; không quản lý chi phí bảo hành, người sửa, phân công sửa, chi phí linh kiện thay thế hoặc SLA.   Không estimate bảng/API chi phí, nhân công, SLA trong MVP. Nếu khách yêu cầu sau này, mở Change Request/Phase 2 riêng.
  Confirmed                                                                                                                                                                                           

  DEC-WM-006\           App PV hiển thị available_qty theo SKU tại kho tổng cho chủ đại lý/cửa hàng và khách hàng xem sản phẩm.                                                                       Đây là hành vi hiển thị đã chốt, không coi là blocker nhạy cảm. Chỉ hiển thị số lượng tổng theo SKU; không hiển thị serial/item/container/movement/giá vốn.
  Confirmed                                                                                                                                                                                           

  DEC-WM-007\           Chip/mã/QR/Barcode/RFID được tách rõ: MVP quản lý dữ liệu mã vật lý và flow scan/demo; tích hợp phần cứng thật chuyên sâu không thuộc MVP.                                    Dev thiết kế code_value/code_type/carrier_type và scan_source; chưa cần protocol thiết bị. Hardware model/protocol là open point Phase 2, không block MVP demo scanner.
  MVP boundary                                                                                                                                                                                        

  DEC-WM-008\           MVP chỉ quản lý 01 kho tổng; đại lý/cửa hàng con là đối tượng nhận hàng, không phải kho và không có tồn đại lý.                                                               Không tạo Inventory_Balance theo agency; không có stocktake/movement tăng tồn đại lý. Trace vào API, ERD, AC/TC để tránh hiểu nhầm multi-warehouse.
  Đã chốt                                                                                                                                                                                             

  DEC-WM-009\           Không quản lý giá vốn, giá bán, kế toán kho, công nợ trong MVP.                                                                                                               Không estimate báo cáo giá trị tồn/COGS/công nợ. Nếu có field đơn giá tham khảo thì không dùng để tính tồn hoặc báo cáo tài chính MVP.
  Confirmed                                                                                                                                                                                           
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

P1 status: các quyết định scope chính đã được chuyển thành Decision Log. BA chỉ cần attach evidence khách hàng/đối tác đã chốt vào Jira/Confluence nếu quy trình dự án yêu cầu; không phải blocker nội dung BRD.

## 2. Tổng quan module và phạm vi

### 2.1. Mục tiêu module

-   Quản lý nhập kho, xuất kho, tồn kho và kiểm kê cho kho tổng Hoa Nam.

-   Chuẩn hóa SKU cho sản phẩm và linh kiện để báo cáo đúng theo loại hàng.

-   Quản lý từng sản phẩm có mã dán riêng, đồng thời biết sản phẩm đó đang/đã thuộc thùng nào.

-   Quản lý chip/mã vật lý cho sản phẩm, thùng lớn/carton, hộp/box linh kiện và khay/tray để hỗ trợ quét, tìm kiếm và đối soát. Với hộp đựng đơn chiếc của một sản phẩm, mã dán trên hộp và mã trên sản phẩm là cùng một mã item, không tạo container riêng.

-   Hỗ trợ xuất nguyên thùng hoặc xuất lẻ từng sản phẩm; với linh kiện, chỉ xuất linh kiện, không xuất khay.

-   Quản lý kiểm kê bằng scan chip/mã hoặc Demo máy quét, đối chiếu thiếu thừa so với snapshot tồn hệ thống tại thời điểm bắt đầu kiểm kê và ghi nhận điều chỉnh sau xác nhận. Khi tạo phiên kiểm kê, hệ thống ghi stocktake_started_at và snapshot tồn; trong lúc kiểm kê không cần chức năng khóa nhập/xuất vì vận hành thực tế sẽ tạm dừng xuất hàng và hàng nhập mới chưa đưa ngay vào khu kiểm kê.

-   Quản lý bảo hành/sửa chữa cơ bản tại phòng bảo hành kho tổng: thông tin khách gửi, bệnh máy, trạng thái xử lý và trả máy.

-   Duy trì App xem sản phẩm như scope đã chốt và bổ sung rõ vai trò tra cứu cho chủ đại lý/cửa hàng hoặc khách hàng: xem danh sách/chi tiết sản phẩm, thông số kỹ thuật, hình ảnh, phụ kiện tương thích, số lượng tồn khả dụng tại kho tổng, tìm kiếm/lọc theo nhiều tiêu chí và gợi ý sản phẩm theo ngành nghề sử dụng.

### 2.2. Phạm vi trong MVP

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Nhóm**           **Trong phạm vi MVP**
  ------------------ -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  Kho tổng           Quản lý một kho tổng duy nhất theo quyết định DEC-WM-001. Trong MVP, mọi Inventory_Balance, Inventory_Movement, Stocktake và báo cáo XNT chỉ tính trên kho tổng. Không quản lý tồn kho đại lý/cửa hàng con.

  Danh mục SKU       Tạo/sửa/ngừng hoạt động SKU sản phẩm và SKU linh kiện; khai báo SKU có quản lý serial hay không.

  Mã vật lý          Quản lý chip/mã máy/item, mã thùng/carton, mã hộp/box linh kiện, mã khay/tray; cho phép scan thật, Demo máy quét hoặc nhập tay cùng một field.

  Nhập kho           Ghi nhận hàng về kho bằng phiếu nhập; scan thùng/sản phẩm/linh kiện/box; Post Receipt tăng tồn. Với linh kiện chưa xếp khay, ghi trạng thái Chờ xếp khay và cập nhật khay bằng Putaway sau nhập.

  Xuất kho           Ghi nhận xuất kho từ kho tổng cho đại lý/cửa hàng con/đối tượng nhận hàng; scan thùng để xuất nguyên thùng hoặc scan từng máy để xuất lẻ; xuất linh kiện theo mã/quantity. Sau Post Issue chỉ giảm tồn kho tổng và ghi agency_id/nơi nhận trên phiếu xuất, không tạo tồn tại đại lý.

  Tồn kho            Xem tồn theo SKU, loại hàng và trạng thái tại kho tổng. Container/carton/box/tray chỉ là thông tin truy vết, vị trí gợi ý và mapping chứa hàng; không phải bucket/lớp tồn độc lập. Không có filter/màn hình tồn đại lý trong MVP.

  Kiểm kê            Tạo phiên kiểm kê, ghi stocktake_started_at và snapshot tồn hệ thống tại thời điểm bắt đầu, scan batch/scan phạm vi rộng hoặc demo scanner, nhập số lượng kiểm đếm với linh kiện không có serial, đối chiếu thiếu/thừa theo snapshot. Không cần khóa nhập/xuất trên hệ thống; nếu có movement phát sinh trong thời gian phiên mở thì cảnh báo để QL kho xem trước khi áp dụng adjustment.

  Bảo hành           Tiếp nhận, cập nhật trạng thái, ghi bệnh máy, trả khách. Nếu mất/hỏng chip, không có mã máy hoặc khách không nhớ mã, cho phép tạo case TEMP_ONLY theo temporary_item_code/mã temp; case này không gắn item_code và không truy vết về máy đã bán.

  Báo cáo            Báo cáo xuất nhập tồn, lịch sử nhập/xuất, kiểm kê, bảo hành và thống kê bệnh theo loại máy trong khoảng thời gian. Báo cáo XNT tính trên kho tổng; agency chỉ là chiều phân tích nơi nhận nếu cần, không phải kho.

  App xem sản phẩm   Web App responsive cho desktop/tablet/mobile. Danh sách/chi tiết sản phẩm đã publish; tìm kiếm/lọc/gợi ý theo ngành nghề; xem available_qty kho tổng. Không đặt mua/giữ hàng/thanh toán. Native app và offline thuộc P2/Future.

  Đại lý             Tạo/sửa/ngừng hoạt động đại lý/cửa hàng con làm master data đối tượng nhận hàng khi xuất kho; agency_id không được dùng như warehouse_id và không tạo tồn kho đại lý.

  Demo máy quét      Giao diện demo scanner cho phép bấm nút để giả lập quét được một hoặc nhiều mã/chip trong nhập, xuất, kiểm kê, tra cứu và bảo hành.
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

#### 2.2.1. Quy tắc scope kho tổng và đại lý đã chốt

WM-SCOPE-001: Trong MVP, Warehouse chỉ đại diện cho 01 kho tổng Hoa Nam. Có thể vẫn giữ bảng Warehouse để mở rộng tương lai nhưng UI/API P0 không có flow nhiều kho hoặc cây kho.

WM-SCOPE-002: Agency/Dealer là master data đối tượng nhận hàng, không phải warehouse. agency_id chỉ xuất hiện trên phiếu xuất/báo cáo xuất theo nơi nhận; không được dùng như warehouse_id trong Inventory_Balance, Stocktake hoặc Movement.

WM-SCOPE-003: Xuất hàng cho đại lý không tạo tồn đại lý. Sau Post Issue, available_qty kho tổng giảm và Inventory_Movement = ISSUE_POSTED tại kho tổng; không sinh Inventory_Balance hoặc Movement tăng tồn cho agency.

WM-SCOPE-004: App PV chỉ đọc available_qty từ kho tổng, không hiển thị tồn theo đại lý, không giữ hàng, không đặt mua/thanh toán và không tạo nghiệp vụ kho. App PV Beta triển khai dưới dạng web responsive; native app và offline không thuộc Beta.

WM-SCOPE-005: App PV hiển thị available_qty theo SKU tại kho tổng cho chủ đại lý/cửa hàng và khách hàng xem sản phẩm theo quyết định DEC-WM-006. Việc hiển thị số lượng này là hành vi nghiệp vụ đã được đối tác chốt, không cần xử lý như rủi ro nhạy cảm trong MVP.

WM-SCOPE-006: App PV không có đặt mua, giữ hàng, thanh toán, giỏ hàng, tạo đơn hoặc tạo phiếu xuất trong MVP. Mọi nghiệp vụ xuất hàng vẫn thực hiện trong WM bằng Post Issue tại kho tổng.

WM-SCOPE-007: Bảo hành MVP không quản lý chi phí bảo hành, người sửa, phân công sửa, chi phí linh kiện thay thế hoặc SLA. Các nội dung này là Phase 2/Change Request nếu khách yêu cầu sau.

WM-SCOPE-008: Hardware scanner thật, protocol RFID/NFC, driver thiết bị, gateway đọc chip hoặc tích hợp phần cứng chuyên sâu không thuộc MVP. MVP chỉ chuẩn hóa dữ liệu mã vật lý, scan batch và Demo máy quét để thay thế input thiết bị thật.

### 2.3. Ngoài phạm vi MVP

-   Không quản lý giá vốn, giá bán, kế toán kho, chi phí sửa chữa hoặc công nợ.

-   Không quản lý vị trí kệ/ngăn/bin, sơ đồ kho hoặc chỉ đường trong kho.

-   Không quản lý tồn kho tại đại lý và cửa hàng con; hệ thống chỉ ghi nhận kho tổng đã xuất cho ai.

• Không phát hành native mobile app, App Store/Google Play hoặc chế độ offline trong Beta; nền tảng Beta là web responsive.

-   Không xây luồng bán hàng, đặt mua, giữ hàng, thanh toán trên App xem sản phẩm.

-   Không quản lý người sửa, phân công sửa chữa, chi phí linh kiện thay thế hoặc SLA bảo hành.

-   Giai đoạn đầu chưa có máy quét thật nhưng vẫn triển khai luồng nghiệp vụ như đã có máy quét. UI phải có ô scan/nhập mã và nút Demo máy quét để giả lập một lần quét trả về một hoặc nhiều mã/chip. Khi có máy quét phạm vi rộng, thiết bị chỉ thay thế bước demo/nhập tay bằng dữ liệu scan thật.

-   MVP quản lý Physical_Code cho mọi đối tượng cần quét. ITEM bắt buộc carrier chip; CARTON/BOX/TRAY được cấu hình carrier linh hoạt và không mặc định bắt buộc chip. Không làm tích hợp phần cứng chuyên sâu ở giai đoạn đầu; chỉ cần chuẩn hóa dữ liệu chip/mã và flow scan/demo scanner.

· Không quản lý tồn đại lý/cửa hàng con: không có màn hình tồn đại lý, không có nhập/xuất nội bộ tại đại lý, không có kiểm kê đại lý, không có báo cáo XNT theo đại lý như một kho. Đại lý chỉ xuất hiện trong danh mục đối tượng nhận hàng và trên phiếu xuất từ kho tổng.

### 2.4. Ranh giới giữa Quản lý kho và App xem sản phẩm

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Hạng mục**       **Module WM - Quản lý kho**                                                                                                                                                                                                                                 **Module PV - App xem sản phẩm**
  ------------------ ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  Dữ liệu chính      SKU, item/serial, container, tồn kho, nhập/xuất, kiểm kê, bảo hành.                                                                                                                                                                                         Danh sách/chi tiết sản phẩm được publish, thông số kỹ thuật, hình ảnh, phụ kiện tương thích, ngành nghề sử dụng, tag/bộ lọc, available_qty và trạng thái tồn tổng quan từ kho tổng.

  Tác động tồn kho   Tồn kho chỉ thay đổi khi nghiệp vụ được post/xác nhận cuối cùng: Post Receipt, Post Issue hoặc Adjustment kiểm kê đã được QL kho áp dụng và chuyển APPLIED. Phiếu nhập/xuất không có bước chờ duyệt/duyệt; các bước lưu nháp/scanning không cập nhật tồn.   Chỉ đọc available_qty/tồn đã được WM post/xác nhận; không tạo nghiệp vụ kho, không giữ hàng, không trừ tồn và không tạo tồn tại đại lý/cửa hàng con.

  Quét mã            Quét để nhập, xuất, kiểm kê, tra cứu item/container, tiếp nhận bảo hành.                                                                                                                                                                                    Có thể tra cứu sản phẩm bằng mã nếu cần, nhưng không post nghiệp vụ.

  Bảo hành           Tạo/cập nhật case bảo hành/sửa chữa, ghi bệnh và trạng thái.                                                                                                                                                                                                Chỉ hiển thị chính sách bảo hành hoặc thông tin được phép public.

  Báo cáo            Xuất nhập tồn, chênh lệch kiểm kê, tình trạng bảo hành, bệnh máy.                                                                                                                                                                                           Có thể xem lượt xem/tìm kiếm sản phẩm, ngành nghề được quan tâm và sản phẩm hay được tra cứu nếu sau này cần; không thay thế báo cáo kho.
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

#### 2.4.1. Quy tắc phân quyền/hiển thị App PV và dữ liệu tồn kho

App PV được chốt là kênh tra cứu sản phẩm cho chủ đại lý/cửa hàng và khách hàng. Vì đối tác đã chốt hiển thị số lượng tồn khả dụng, BRD không xử lý available_qty như dữ liệu nhạy cảm cần che trong MVP. Tuy nhiên hệ thống vẫn phải kiểm soát phạm vi hiển thị để không lộ dữ liệu vận hành nội bộ.

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Rule**     **Nội dung kiểm soát**                                                                                 **Expected result cho Dev/Test**
  ------------ ------------------------------------------------------------------------------------------------------ ------------------------------------------------------------------------------------------------------------------
  PV-VIS-001   Chỉ hiển thị sản phẩm active và published_to_app = true.                                               Sản phẩm inactive/chưa publish không xuất hiện ở list, search, filter, recommendation hoặc deep link.

  PV-VIS-002   Chỉ hiển thị available_qty tổng theo SKU tại kho tổng và trạng thái Còn hàng/Hết hàng.                 Không hiển thị serial/item_code, carton_code, box_code, tray_code, movement, phiếu nhập/xuất hoặc tồn đại lý.

  PV-VIS-003   Đối tượng xem gồm chủ đại lý/cửa hàng, khách hàng hoặc người xem sản phẩm được phép truy cập App PV.   MVP dùng cùng dữ liệu public/published; nếu cần role đại lý riêng thì đưa Phase 2.

  PV-VIS-004   App PV là read-only với tồn kho.                                                                       Không có API tạo đơn, giữ hàng, trừ tồn, tạo phiếu xuất, tạo agency stock hoặc cập nhật Inventory_Balance.

  PV-VIS-005   Không hiển thị dữ liệu thương mại/nội bộ không thuộc scope App PV.                                     Không lộ giá vốn, chi phí bảo hành, stock movement, danh sách serial, lịch sử khách hàng hoặc thông tin kiểm kê.

  PV-VIS-006   Nếu App PV dùng cache, cần hiển thị hoặc log thời điểm đồng bộ tồn cuối cùng.                          Có thể thêm last_inventory_sync_at/last_updated để tránh hiểu nhầm nếu số lượng có độ trễ; không block core WM.
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 3. Actors và phân quyền MVP

  ------------------------------------------------------------------------------------------------------------------------------------
  **Actor**            **Vai trò**                                                                                 **Mức tương tác**
  -------------------- ------------------------------------------------------------------------------------------- -------------------
  Admin hệ thống       Cấu hình tài khoản, quyền, danh mục SKU, danh mục bệnh, dữ liệu app và tham số hệ thống.    Cao

  Quản lý kho tổng     Theo dõi tồn kho, duyệt/khóa adjustment kiểm kê, xem báo cáo, xử lý sai lệch.               Cao

  Nhân viên kho        Nhập kho, xuất kho, scan/nhập mã, kiểm kê, tra cứu item/container.                          Cao

  Nhân viên bảo hành   Tiếp nhận máy bảo hành/sửa chữa, cập nhật bệnh/trạng thái, trả máy cho khách.               Trung bình

  Ban lãnh đạo         Xem báo cáo tồn kho, nhập xuất tồn, kiểm kê, thống kê bảo hành.                             Trung bình

  Người xem App PV     Xem danh sách/chi tiết sản phẩm, thông số, phụ kiện, available_qty và tình trạng có hàng.   Trung bình
  ------------------------------------------------------------------------------------------------------------------------------------

### 3.1. Ma trận phân quyền đề xuất

  ---------------------------------------------------------------------------------------------------------------------------------
  **Chức năng**                    **Admin**   **QL kho**   **NV kho**         **NV bảo hành**   **Lãnh đạo**   **App PV**
  -------------------------------- ----------- ------------ ------------------ ----------------- -------------- -------------------
  Quản lý SKU sản phẩm/linh kiện   Có          Có           Xem                Xem               Xem            Chỉ xem published

  Quản lý mã thùng/hộp/khay        Có          Có           Có                 Không             Xem            Không

  Tạo phiếu nhập                   Có          Có           Có                 Không             Không          Không

  Post nhập kho                    Có          Có           Có nếu được cấp    Không             Không          Không

  Tạo phiếu xuất                   Có          Có           Có                 Không             Không          Không

  Post xuất kho                    Có          Có           Có nếu được cấp    Không             Không          Không

  Tạo phiên kiểm kê                Có          Có           Có                 Không             Không          Không

  Duyệt điều chỉnh kiểm kê         Có          Có           Không              Không             Không          Không

  Tiếp nhận/cập nhật bảo hành      Có          Có           Có nếu được giao   Có                Không          Không

  Xem báo cáo kho                  Có          Có           Có nếu cấp quyền   Không             Có             Không

  Publish sản phẩm lên App PV      Có          Có           Không              Không             Xem            Không

  Quản lý đại lý/cửa hàng con      Có          Có           Xem nếu được cấp   Không             Xem            Không

  Demo máy quét                    Có          Có           Có                 Có ở bảo hành     Không          Không

  Putaway/Xếp khay linh kiện       Có          Có           Có                 Không             Xem            Không
  ---------------------------------------------------------------------------------------------------------------------------------

## 4. Nguyên tắc SKU và mã quản lý vật lý

Điểm cần chốt quan trọng nhất: SKU không phải mã từng máy, không phải mã thùng, không phải mã hộp và không phải mã khay. SKU là mã định danh cho một loại hàng cần quản lý tồn kho. Mã máy, mã thùng, mã hộp và mã khay là mã vật lý phục vụ quét, tìm kiếm, mapping chứa hàng và truy vết.

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Quy tắc ngắn gọn: SKU dùng để biết “đây là loại hàng gì”. Mã sản phẩm/thùng/hộp/khay dùng để biết “hàng này là cái nào, đang nằm trong container nào, còn số lượng bao nhiêu hoặc dùng để tìm kiếm ở đâu”.**
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 4.1. Phân biệt SKU và các mã vật lý

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Đối tượng thực tế**              **Có phải SKU không?**   **Cách quản lý đúng**
  ---------------------------------- ------------------------ ---------------------------------------------------------------------------------------------------------------------------------------------
  Máy model A màu đen bản 128GB      Có                       Product SKU

  Máy cụ thể có mã dán trên thân     Không                    Item/Serial/Product Instance

  Thùng chứa 20 máy model A          Không                    Carton/Container Code

  Linh kiện màn hình A               Có                       Component SKU

  Một linh kiện cụ thể có mã dán     Không                    Component Serial/Instance

  Hộp chứa 100 linh kiện cùng loại   Không                    Box Code + quantity

  Khay chứa nhiều hộp linh kiện      Không                    Tray/Locator Code

  Hộp đựng 1 sản phẩm                Không                    Không tạo mã hộp riêng. Mã dán trên hộp và mã trên sản phẩm là cùng item_code/chip_code, chỉ dán ở 2 vị trí để dễ quét.

  Chip/mã vật lý                     Không                    Là mã định danh vật lý cho item/container/locator. Hệ thống phân loại code_type để biết đó là PRODUCT_ITEM, CARTON, COMPONENT_BOX hay TRAY.
  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 4.2. Nhóm SKU cần xây dựng

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Nhóm SKU**   **Mục đích**                                                                                                 **Ví dụ**                                                                          **Quản lý serial?**
  -------------- ------------------------------------------------------------------------------------------------------------ ---------------------------------------------------------------------------------- -------------------------------------------------------------------------------------------------------------------------------------
  PRODUCT        Sản phẩm/máy bán ra đại lý, công trình hoặc bán lẻ. SKU đại diện đúng một biến thể bán được/kiểm tồn được.   DCH-DRILL-DJZ10A-220V-V1; DCH-GRINDER-DSM02-850W-V1; DCH-DRILL-DJZ10A-20V-KIT-V1   Thường có. Mỗi máy có mã item/serial riêng.

  COMPONENT      Linh kiện/phụ tùng dùng để bán, thay thế hoặc phục vụ bảo hành/sửa chữa.                                     LK-DCH-PIN-20V-V1; LK-DCH-SAC-20V-V1; LK-DCH-OC-VIT-M2-V1                          Có hoặc không tùy linh kiện. MVP Must hỗ trợ 2 mode: is_serial_tracked = true thì scan serial; false thì quản lý quantity theo box.
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

#### 4.2.1. Chuẩn Product Master Data và quy tắc tạo sku_code

Đối với Hoa Nam, SKU cần được chuẩn hóa theo Product Master Data để tránh nhầm giữa các biến thể model, điện áp/công suất, bộ phụ kiện và phiên bản. SKU không chứa batch nhập khẩu, mã container, mã item, tình trạng hàng hoặc kênh phân phối; các thông tin này được quản lý bằng entity/field riêng.

• Quy tắc format PRODUCT: BRAND-CATEGORY-MODEL-POWER_OR_VOLTAGE-VERSION

hoặc BRAND-CATEGORY-MODEL-POWER_OR_VOLTAGE-PACKAGE-VERSION.

• Quy tắc format COMPONENT: LK-BRAND-COMPONENT_TYPE-MODEL_OR_FAMILY-SPEC-VERSION.

• Ví dụ PRODUCT: DCH-DRILL-DJZ10A-220V-V1; DCH-GRINDER-DSM02-850W-V1; DCH-DRILL-DJZ10A-20V-KIT-V1.

• Ví dụ COMPONENT: LK-DCH-PIN-20V-V1; LK-DCH-SAC-20V-V1; LK-DCH-OC-VIT-M2-V1.

• Chỉ tạo SKU mới khi khác brand, category, model, điện áp/công suất, version hoặc package/kit làm thay đổi bản chất sản phẩm cần bán, kiểm tồn hoặc báo cáo.

• Không tạo SKU mới chỉ vì khác lô nhập, container nhập khẩu, thùng, item serial, tình trạng hàng demo/lỗi/bảo hành hoặc kênh xuất.

#### 4.2.2. Các thuộc tính không đưa trực tiếp vào sku_code

• Import batch/container nhập khẩu: quản lý bằng Import_Batch, gồm import_batch_code, shipment_container_code, supplier_batch_no và import_period.

• Item/serial/mã máy: quản lý bằng Item_Instance và Physical_Code.

• Carton/box/tray: quản lý bằng Container và Container_Item.

• Hàng demo/lỗi/bảo hành: quản lý bằng item_condition, item_status hoặc stock_status như NORMAL, DEMO, DEFECTIVE, WARRANTY_RETURN.

• Kênh phân phối: quản lý trên Outbound_Document bằng sales_channel và agency_id/receiver_id.

### 4.3. Cấu trúc SKU Master đề xuất

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Field**                          **Bắt buộc?**                           **Ý nghĩa**
  ---------------------------------- --------------------------------------- --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  sku_id                             Có                                      ID hệ thống, khóa chính.

  sku_code                           Có                                      Mã SKU duy nhất toàn hệ thống theo Product Master Data, ví dụ DCH-DRILL-DJZ10A-220V-V1 hoặc LK-DCH-PIN-20V-V1.

  sku_name                           Có                                      Tên loại hàng.

  sku_type                           Có                                      PRODUCT hoặc COMPONENT.

  category_code/category_name        Có                                      Nhóm hàng chuẩn hóa để tạo SKU, lọc, báo cáo và App PV: DRILL, GRINDER, SAW, PIN, SAC...

  brand_code/brand_name/model_code   Có với PRODUCT; nên có với COMPONENT    Thương hiệu và model là thành phần định danh SKU. Ví dụ brand_code = DCH, model_code = DJZ10A.

  unit                               Có                                      Đơn vị tính chính: cái, bộ, hộp...

  is_serial_tracked                  Có                                      Quyết định cách quản lý tồn. true: quản lý từng item/serial và bắt buộc scan serial trong nhập/xuất/kiểm kê. false: quản lý quantity theo box; không bắt buộc mọi linh kiện đều có serial.

  default_container_type             Có                                      CARTON với sản phẩm; BOX/TRAY với linh kiện.

  warranty_applicable                Tùy loại                                SKU có áp dụng bảo hành/sửa chữa không.

  specification                      Tùy loại                                Thông số kỹ thuật mở rộng, dùng cho App PV.

  published_to_app                   Có với sản phẩm                         Sản phẩm có hiển thị trên App PV hay không.

  status                             Có                                      ACTIVE/INACTIVE.

  power_or_voltage                   Có nếu là thông số phân biệt sản phẩm   Điện áp/công suất/dòng pin: 20V, 12V, 220V, 850W... dùng để tránh nhầm biến thể.

  package_variant/kit_type           Có nếu có nhiều cấu hình bán            Phân biệt BARE/KIT/SET/BODY_ONLY nếu cùng model nhưng khác bộ phụ kiện đi kèm.

  version                            Có                                      Phiên bản/đời sản phẩm: V1, V2, GEN2... Mặc định V1 nếu chưa có nhiều version.

  import_batch_code                  Không thuộc SKU Master                  Lô/đợt nhập khẩu quản lý ở Import_Batch hoặc chứng từ nhập, không đưa vào sku_code.

  item_condition/stock_condition     Không thuộc SKU Master                  Tình trạng hàng NORMAL/DEMO/DEFECTIVE/WARRANTY_RETURN quản lý ở item/tồn, không tách SKU.

  sales_channel                      Không thuộc SKU Master                  Kênh phân phối DEALER/PROJECT/RETAIL quản lý ở phiếu xuất/báo cáo, không đưa vào sku_code.

  legacy_sku_code/sku_alias          Không                                   Mã SKU cũ từ Excel được giữ để tra cứu/migration. Dữ liệu mới bắt buộc dùng sku_code chuẩn; alias phải unique theo source và không được dùng làm khóa tồn.
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 4.4. Ví dụ SKU sản phẩm và linh kiện

  -----------------------------------------------------------------------------------------------------------------------------------------------------
  **sku_code**                  **sku_name**                                        **sku_type**   **unit**   **serial_tracked**   **container_rule**
  ----------------------------- --------------------------------------------------- -------------- ---------- -------------------- --------------------
  DCH-DRILL-DJZ10A-220V-V1      Máy khoan Dongcheng DJZ10A 220V bản V1              PRODUCT        cái        Yes                  CARTON

  DCH-DRILL-DJZ10A-20V-KIT-V1   Bộ máy khoan pin Dongcheng DJZ10A 20V kèm pin/sạc   PRODUCT        bộ         Yes                  CARTON

  DCH-GRINDER-DSM02-850W-V1     Máy mài góc Dongcheng DSM02 850W                    PRODUCT        cái        Yes                  CARTON

  LK-DCH-PIN-20V-V1             Pin 20V Dongcheng                                   COMPONENT      cái        Yes                  BOX/TRAY

  LK-DCH-SAC-20V-V1             Sạc pin 20V Dongcheng                               COMPONENT      cái        Yes hoặc No          BOX/TRAY

  LK-DCH-OC-VIT-M2-V1           Ốc vít M2 dùng cho máy Dongcheng                    COMPONENT      cái        No                   BOX/TRAY
  -----------------------------------------------------------------------------------------------------------------------------------------------------

### 4.5. Chuẩn hóa khái niệm chip/mã/QR/Barcode/RFID và scanner

Để tránh hiểu nhầm giữa “chip”, “mã”, “QR”, “barcode” và “RFID”, BRD chốt nguyên tắc: nghiệp vụ quản lý theo mã vật lý trong hệ thống; QR/Barcode/RFID chỉ là phương tiện mang hoặc đọc mã. Thiết bị quét thật là kênh nhập dữ liệu scan, không làm thay đổi nghiệp vụ post nhập/xuất/kiểm kê.

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Khái niệm**                          **Định nghĩa / phạm vi MVP**                                                                                                                                           **Cách dùng trong nghiệp vụ**
  -------------------------------------- ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- -----------------------------------------------------------------------------------------------------------------------------------------------------
  Mã vật lý / Physical_Code              Giá trị mã duy nhất được hệ thống lưu để định danh item, carton, box, tray hoặc mã tạm bảo hành. MVP: Có.                                                              Là source of truth để scan, tra cứu, nhập, xuất, kiểm kê, bảo hành.

  QR / Barcode                           Hình thức in/encode code_value trên tem để camera/máy quét đọc được. MVP: Có ở mức dữ liệu/tem/scan input.                                                             Không phải entity tồn kho riêng; đọc ra code_value rồi xử lý theo code_type.

  RFID/NFC chip                          Carrier chip được dán cho item, carton, box và tray cần quét trong Beta. Hệ thống lưu mapping code_value/hardware_uid; không khóa nghiệp vụ vào một vendor thiết bị.   Chip đọc ra code_value/hardware_uid rồi map về Physical_Code. Driver, gateway và protocol chuyên sâu là P2; QR/Barcode cùng code_value là fallback.

  Demo máy quét                          UI giả lập thao tác scan, có thể trả về một hoặc nhiều code_value trong một lần. MVP: Có.                                                                              source = DEMO_SCANNER; dùng cùng flow scan với nhập tay/scan thật.

  Máy quét thật / Hardware integration   Thiết bị, driver, protocol RFID/NFC/barcode gateway, kết nối phần cứng. MVP: không tích hợp chuyên sâu.                                                                Phase 2/Need API riêng; trong MVP chỉ cần input nhận danh sách code_value.

  Scan batch / Wide-range scan           Một lần scan trả về nhiều mã/chip cùng lúc. MVP: Có.                                                                                                                   Tạo Scan_Batch và nhiều Scan_Event; hệ thống phân loại ITEM/CARTON/BOX/TRAY/UNKNOWN.
  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

**Data convention tối thiểu cho mã vật lý:**

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Field**                 **Áp dụng cho**         **Ý nghĩa / rule**
  ------------------------- ----------------------- --------------------------------------------------------------------------------------------------------------------------
  code_value                Physical_Code           Giá trị mã nghiệp vụ duy nhất toàn hệ thống; dùng để scan/tra cứu.

  code_type                 Physical_Code           ITEM, CARTON, COMPONENT_SERIAL, BOX, TRAY, TEMP_WARRANTY, UNKNOWN.

  carrier_type              Physical_Code           QR, BARCODE, RFID, NFC, PLAIN_LABEL, MANUAL_CODE hoặc TEMP_CODE.

  hardware_uid              Physical_Code           Optional; UID chip/thẻ nếu thiết bị trả về mã kỹ thuật khác code_value. Không dùng trực tiếp làm item_code nếu chưa map.

  encoded_payload_version   Physical_Code           Optional; version cấu trúc payload nếu sau này encode nhiều field vào QR/RFID.

  scan_source               Scan_Batch/Scan_Event   REAL_SCANNER, DEMO_SCANNER hoặc MANUAL_INPUT.

  raw_payload               Scan_Event              Optional; dữ liệu raw từ thiết bị để debug/tích hợp phần cứng Phase 2.
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 5. Mô hình dữ liệu nghiệp vụ

Mô hình dữ liệu nên tách rõ 4 lớp: SKU, item/serial, container/locator và tồn kho/movement. Cách tách này giúp hệ thống hỗ trợ cả sản phẩm có mã từng máy, xuất nguyên thùng, xuất lẻ, linh kiện có serial và linh kiện nhỏ chỉ quản lý số lượng.

### 5.1. Entity chính

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Entity**               **Mục đích**                                             **Ghi chú thiết kế**
  ------------------------ -------------------------------------------------------- ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  Warehouse                Thông tin kho tổng Hoa Nam.                              MVP chỉ vận hành 01 kho tổng theo DEC-WM-001. Có thể giữ bảng Warehouse để mở rộng tương lai, nhưng P0 không có warehouse tree, không có nhiều kho và không có tồn đại lý.

  SKU                      Danh mục loại hàng: sản phẩm hoặc linh kiện.             Một SKU có nhiều item hoặc nhiều box quantity.

  Item_Instance            Quản lý từng máy/từng linh kiện có mã riêng.             Áp dụng khi is_serial_tracked = true.

  Container                Quản lý thùng, hộp, khay.                                container_type = CARTON/BOX/TRAY. Container không phải SKU.

  Container_Item           Mapping item hoặc SKU quantity nằm trong container.      Dùng cho sản phẩm trong thùng, linh kiện trong hộp, hộp nằm trong khay.

  Inventory_Balance        Tồn hiện tại theo SKU tại kho tổng.                      Unique key MVP: warehouse_id + sku_id + stock_status, trong đó warehouse_id luôn là kho tổng. Không có agency_id trong bảng tồn. Dữ liệu tổng hợp từ item status hoặc quantity trong box.

  Inventory_Movement       Lịch sử biến động tồn kho.                               Mọi post nhập/xuất/adjustment phải sinh movement tại kho tổng. Xuất cho đại lý chỉ ghi agency_id/nơi nhận ở chứng từ nguồn, không sinh movement tăng tồn cho đại lý.

  Inbound_Document         Phiếu nhập kho.                                          Ghi nguồn nhập, ngày nhập, người tạo, trạng thái.

  Inbound_Line             Dòng dự kiến/ghi nhận nhập theo SKU.                     Có expected_qty và received_qty.

  Outbound_Document        Phiếu xuất kho.                                          Ghi agency_id/đại lý/đơn vị nhận, ngày xuất, người tạo, trạng thái. Phiếu xuất không tạo warehouse hoặc tồn kho cho nơi nhận.

  Outbound_Line            Dòng xuất theo SKU.                                      Có planned_qty và issued_qty.

  Scan_Event               Lưu lịch sử scan/nhập mã.                                Ai quét, mã gì, ở nghiệp vụ nào, kết quả valid/invalid.

  Stocktake_Session        Phiên kiểm kê.                                           Khi tạo phiên, hệ thống ghi stocktake_started_at và snapshot tồn hệ thống tại thời điểm bắt đầu để làm baseline đối soát.

  Stocktake_Count          Kết quả kiểm đếm thực tế.                                Với serial: danh sách mã; với quantity-only: số lượng đếm.

  Stocktake_Variance       Chênh lệch thiếu/thừa.                                   Chờ quản lý xác nhận trước khi điều chỉnh tồn.

  Warranty_Case            Phiếu bảo hành/sửa chữa.                                 Ghi khách gửi, SĐT, SKU/model nếu biết, serial nếu có, bệnh, trạng thái. Với case mất mã, quản lý bằng temporary_item_code/mã temp, item_code để trống và không truy vết máy đã bán.

  Warranty_Event           Lịch sử trạng thái bảo hành.                             RECEIVED, CHECKING, REPAIRING, COMPLETED, RETURNED.

  Defect_Catalog           Danh mục bệnh/lỗi máy.                                   Dùng cho báo cáo “máy loại A bị bệnh B”.

  Agency                   Danh mục đại lý/cửa hàng con/đối tượng nhận hàng.        Dùng làm nơi nhận trên phiếu xuất. Không tạo Inventory_Balance, không tạo Stocktake, không tạo Inventory_Movement như một kho.

  Scan_Batch               Ghi nhận một lần scan có thể trả về nhiều mã/chip.       Dùng cho máy quét phạm vi rộng và Demo máy quét; một batch có nhiều Scan_Event.

  Putaway_Task             Nghiệp vụ xếp khay/gán vị trí khay sau nhập linh kiện.   Không làm tăng/giảm tồn, chỉ cập nhật vị trí box/SKU trong tray.

  Stocktake_Snapshot       Ảnh chụp tồn hệ thống tại thời điểm bắt đầu kiểm kê.     Ghi stocktake_session_id, stocktake_started_at, sku_id, item_code/container_code nếu có, system_qty/system_status. Dùng làm baseline so sánh; không phải tồn mới.

  PV_Product_Profile       Hồ sơ sản phẩm publish lên App PV.                       Tham chiếu SKU; lưu mô tả bán hàng, hình ảnh, thông số hiển thị, trạng thái publish và thứ tự hiển thị. Không thay thế SKU Master.

  PV_Industry              Danh mục ngành nghề/nhóm nhu cầu sử dụng.                Ví dụ: Ngành gỗ, Ngành kim loại, Công trường xây dựng, Sửa chữa ô tô, Dụng cụ vườn & không dây.

  PV_Product_Industry      Mapping sản phẩm phù hợp với ngành nghề.                 Một sản phẩm có thể thuộc nhiều ngành; dùng để lọc và gợi ý sản phẩm theo ngành nghề.

  PV_Filter_Tag            Tag/bộ lọc hiển thị trên App PV.                         Ví dụ: không dây, 20V MAX, 12V MAX, máy khoan, máy cưa, máy mài, phụ kiện tương thích, còn hàng.

  PV_Recommendation_Rule   Rule gợi ý sản phẩm theo ngành nghề.                     MVP dùng rule cấu hình, không dùng AI: industry_id + category/tag + priority/sort_order + danh sách SKU ưu tiên.

  Import_Batch             Quản lý lô/đợt nhập khẩu hoặc container nhập khẩu lớn.   Không phải SKU. Dùng import_batch_code, shipment_container_code, supplier_batch_no, import_period để trace hàng theo đợt nhập mà không làm phình SKU.

  Item_Condition           Chuẩn hóa tình trạng hàng ở mức item/tồn.                NORMAL, DEMO, DEFECTIVE, WARRANTY_RETURN, NON_SELLABLE. Không dùng tình trạng hàng để tạo SKU mới.

  Inventory_Reversal       Chứng từ đảo append-only cho Receipt/Issue đã Post.      Gồm reversal_id, reversal_type, original_document_id, reason_code, reason_text, status, created_by, confirmed_by, idempotency_key và audit timestamps. Không sửa/xóa document/movement gốc.

  Reversal_Line            Dòng đảo tham chiếu movement gốc.                        Bắt buộc original_movement_id/reversal_of_id; qty đảo không vượt phần còn có thể đảo; sinh movement ngược khi Confirm/Post reversal.

  Migration_Batch          Quản lý dry-run/commit dữ liệu ban đầu.                  Lưu source_file, checksum, batch_status, valid/invalid/duplicate counts, committed_at, reconciliation_status và rollback_reference.

  UAT_Execution_Record     Bằng chứng thực thi UAT.                                 Lưu UAT script, actor, actual result, evidence link, defect ID, sign-off và ngày thực hiện; có thể quản lý ngoài hệ thống bằng Test Management/Jira.
  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 5.2. Relationship/cardinality nghiệp vụ

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Quan hệ**                               **Cardinality**         **Diễn giải**
  ----------------------------------------- ----------------------- --------------------------------------------------------------------------------------------------------------------------------------------------------
  Warehouse - Inventory_Balance             1 - N                   Một kho tổng có nhiều dòng tồn theo SKU.

  SKU - Item_Instance                       1 - N                   Một SKU sản phẩm/linh kiện có thể có nhiều item/serial.

  SKU - Container                           1 - N hoặc 1 - 0..N     Carton/box thường gắn một SKU chính; tray không gắn SKU chính vì chứa nhiều hộp khác loại.

  Container(CARTON) - Item_Instance         1 - N                   Một thùng sản phẩm chứa nhiều máy cùng SKU.

  Container(BOX) - SKU quantity             1 - 1 SKU, N quantity   Một hộp linh kiện chứa nhiều linh kiện giống nhau.

  Container(TRAY) - Container(BOX)          1 - N                   Một khay chứa nhiều hộp linh kiện, có thể khác SKU.

  Inbound_Document - Inbound_Line           1 - N                   Một phiếu nhập có nhiều dòng SKU.

  Outbound_Document - Outbound_Line         1 - N                   Một phiếu xuất có nhiều dòng SKU.

  Document/Line - Scan_Event                1 - N                   Một chứng từ có nhiều lần quét/nhập mã.

  SKU - Inventory_Movement                  1 - N                   Mỗi dòng movement luôn gắn SKU để báo cáo xuất nhập tồn.

  Item_Instance - Warranty_Case             0..1 - N                Một máy có mã có thể có nhiều lần bảo hành. Với case TEMP_ONLY không có mã máy, Warranty_Case không gắn Item_Instance và không truy vết về máy đã bán.

  Defect_Catalog - Warranty_Case            1 - N                   Một loại bệnh có thể xuất hiện trong nhiều case bảo hành.

  Agency - Outbound_Document                1 - N                   Một đại lý/cửa hàng con có thể nhận nhiều phiếu xuất; phiếu xuất có thể chọn một nơi nhận.

  Scan_Batch - Scan_Event                   1 - N                   Một lần scan phạm vi rộng/demo scanner có thể sinh nhiều dòng mã quét.

  Putaway_Task - Container(BOX)             1 - N                   Một lần xếp khay có thể gán một hoặc nhiều box vào một tray.

  Stocktake_Session - Stocktake_Snapshot    1 - N                   Một phiên kiểm kê có nhiều dòng snapshot theo SKU/item/box tại thời điểm stocktake_started_at.

  SKU - Import_Batch                        1 - N                   Một SKU có thể xuất hiện trong nhiều lô/đợt nhập khẩu; batch không làm phát sinh SKU mới.

  Import_Batch - Item/Carton/Inbound_Line   1 - N                   Một batch có thể gồm nhiều item/carton/dòng nhập để trace container nhập khẩu và báo cáo theo đợt nhập.
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 5.3. Trạng thái chính

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Đối tượng**                                     **Trạng thái đề xuất**                                                                                                    **Ý nghĩa**
  ------------------------------------------------- ------------------------------------------------------------------------------------------------------------------------- --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  Item/Serial sản phẩm                              NEW, IN_STOCK, ISSUED, IN_WARRANTY, RETURNED_TO_CUSTOMER, LOST, ADJUSTED_OUT                                              Theo dõi vòng đời từng máy có mã. Chu kỳ bảo hành lặp: ISSUED hoặc RETURNED_TO_CUSTOMER → IN_WARRANTY → RETURNED_TO_CUSTOMER. Chỉ IN_STOCK tính Available; mỗi item tối đa một warranty case active.

  Carton                                            EMPTY, IN_STOCK, PARTIAL, ISSUED, CLOSED                                                                                  Thùng còn nguyên, đã xuất một phần hoặc đã xuất hết.

  Component serial (khi is_serial_tracked = true)   IN_STOCK, ISSUED, LOST, ADJUSTED_OUT                                                                                      Áp dụng với SKU linh kiện có quản lý serial. IN_WARRANTY_USE bị loại khỏi MVP; tiêu hao/linh kiện thay thế cho bảo hành là Phase 2/Change Request.

  Box linh kiện                                     RECEIVED_PENDING_PUTAWAY, STORED, PARTIAL, EMPTY, CLOSED                                                                  Box đã nhập nhưng chưa đặt khay; đã xếp khay; còn một phần; đã hết; đã đóng.

  Tray                                              ACTIVE, INACTIVE                                                                                                          Khay chỉ phục vụ tìm kiếm vị trí hộp.

  Phiếu nhập                                        DRAFT, SCANNING, PARTIALLY_POSTED, POSTED, CLOSED, CANCELLED                                                              Cho phép post nhiều lần. PARTIALLY_POSTED khi còn remaining_qty; POSTED khi đã post đủ; CLOSED khi đóng phần còn lại; CANCELLED chỉ trước lần post đầu tiên.

  Phiếu xuất                                        DRAFT, SCANNING, PARTIALLY_POSTED, POSTED, CLOSED, CANCELLED                                                              Cho phép post nhiều lần. Không cho xuất vượt remaining_qty hoặc available_qty. CLOSED khi đủ hoặc remainder bị hủy có lý do.

  Phiên kiểm kê                                     DRAFT, COUNTING, REVIEWING, APPLIED, CANCELLED                                                                            DRAFT tạo phiên; COUNTING dùng snapshot tại stocktake_started_at; REVIEWING xem variance/cảnh báo movement; APPLIED là trạng thái cuối sau khi QL kho bấm Áp dụng adjustment, hệ thống đã sinh ADJUST_IN/ADJUST_OUT và cập nhật tồn; CANCELLED là hủy trước khi áp dụng.

  Warranty case                                     RECEIVED, CHECKING, REPAIRING, COMPLETED, RETURNED, CANCELLED                                                             Quản lý tiến độ bảo hành/sửa chữa cơ bản.

  Putaway task                                      DRAFT, CONFIRMED, CANCELLED                                                                                               DRAFT khi chuẩn bị xếp khay; CONFIRMED chỉ cập nhật vị trí, không tác động tồn; CANCELLED không thay đổi vị trí.

  Warranty case không có mã                         case_status dùng chung RECEIVED, CHECKING, REPAIRING, COMPLETED, RETURNED, CANCELLED; identification_status = TEMP_ONLY   Dùng khi chip/mã máy bị mất/hỏng hoặc khách không nhớ mã. Case quản lý theo temporary_item_code/mã temp, không gắn item_code và không truy vết máy đã bán.

  Inventory Reversal                                DRAFT, CONFIRMED, POSTED, CANCELLED                                                                                       DRAFT chưa đổi tồn; CONFIRMED đã kiểm tra; POSTED sinh movement ngược và khóa chỉnh sửa; CANCELLED chỉ hợp lệ trước POSTED.
  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 6. Phân rã chức năng module

### 6.1. Epic A - Cấu hình kho tổng và danh mục

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Mã**     **Chức năng**                 **Mô tả**                                                                                                   **Ưu tiên**
  ---------- ----------------------------- ----------------------------------------------------------------------------------------------------------- -------------
  F-WM-1.1   Cấu hình kho tổng             Tạo/sửa thông tin kho tổng, địa chỉ, trạng thái hoạt động.                                                  Must

  F-WM-1.2   Cấu hình người dùng/quyền     Quản lý role Admin, QL kho, NV kho, NV bảo hành, lãnh đạo.                                                  Must

  F-WM-1.3   Quản lý đại lý/cửa hàng con   Tạo/sửa/ngừng hoạt động đại lý/cửa hàng con làm nơi nhận hàng khi xuất kho; không quản lý tồn của đại lý.   Must

  F-WM-1.4   Danh mục bệnh/lỗi             Tạo danh mục bệnh máy để chuẩn hóa báo cáo bảo hành.                                                        Must
  ------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 6.2. Epic B - SKU sản phẩm và linh kiện

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Mã**     **Chức năng**                  **Mô tả**                                                                                                                                       **Ưu tiên**
  ---------- ------------------------------ ----------------------------------------------------------------------------------------------------------------------------------------------- -------------
  F-WM-2.1   Quản lý SKU sản phẩm           Tạo/sửa SKU sản phẩm theo Product Master Data: brand, category, model, voltage/power, package/kit, version, thông số kỹ thuật và publish app.   Must

  F-WM-2.2   Quản lý SKU linh kiện          Tạo/sửa SKU linh kiện, khai báo có serial hay chỉ quản lý quantity.                                                                             Must

  F-WM-2.3   Quản lý category/brand/model   Quản lý category/brand/model/power/version/package để lọc, tìm kiếm, báo cáo, hiển thị app và tạo sku_code chuẩn.                               Must

  F-WM-2.4   Quản lý phụ kiện tương thích   Giữ scope App PV: hiển thị phụ kiện/linh kiện tương thích, không tạo đơn hàng.                                                                  Must

  F-WM-2.5   Import SKU ban đầu             Import danh mục sản phẩm/linh kiện từ Excel template nếu cần.                                                                                   Should

  F-WM-2.6   Quản lý Import Batch           Ghi nhận lô/đợt nhập khẩu, container nhập khẩu và mapping batch với item/carton/dòng nhập; không đưa batch vào sku_code.                        Should
  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 6.3. Epic C - Mã item, thùng, hộp, khay

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Mã**     **Chức năng**                                **Mô tả**                                                                                                                                             **Ưu tiên**
  ---------- -------------------------------------------- ----------------------------------------------------------------------------------------------------------------------------------------------------- -------------
  F-WM-3.1   Quản lý item/serial sản phẩm                 Tạo item cho từng máy có mã/chip, gắn SKU và thùng lớn/carton. Hộp đơn chiếc dùng cùng mã item, không tạo container riêng.                            Must

  F-WM-3.2   Quản lý component serial theo cấu hình SKU   Với SKU linh kiện is_serial_tracked = true: tạo/scan item serial riêng. Với is_serial_tracked = false: không tạo serial, quản lý quantity theo box.   Must

  F-WM-3.3   Quản lý carton/thùng                         Tạo mã/chip thùng lớn/carton, gắn SKU sản phẩm và danh sách máy trong thùng.                                                                          Must

  F-WM-3.4   Quản lý box/hộp linh kiện                    Tạo mã hộp, gắn SKU linh kiện và số lượng hoặc serial linh kiện.                                                                                      Must

  F-WM-3.5   Quản lý tray/khay                            Tạo mã/chip khay, gắn danh sách box trong khay để hỗ trợ tìm linh kiện.                                                                               Must

  F-WM-3.6   Tra cứu bằng mã                              Quét/nhập mã item/carton/box/tray để xem thông tin và trạng thái.                                                                                     Must

  F-WM-3.7   Phân loại mã scan                            Sau mỗi lần scan/demo scanner, hệ thống phân loại mã là ITEM/CARTON/BOX/TRAY/UNKNOWN để xử lý đúng nghiệp vụ.                                         Must

  F-WM-3.8   Demo máy quét                                Giao diện bấm nút demo để sinh một hoặc nhiều mã/chip như máy quét phạm vi rộng.                                                                      Must
  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 6.4. Epic D - Nhập kho

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Mã**     **Chức năng**               **Mô tả**                                                                                                                                                                                                 **Ưu tiên**
  ---------- --------------------------- --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- -------------
  F-WM-4.1   Tạo phiếu nhập              Ghi nguồn hàng, ngày nhập, SKU dự kiến, số lượng dự kiến.                                                                                                                                                 Must

  F-WM-4.2   Scan/nhập sản phẩm          Scan thùng lớn và/hoặc từng mã máy; hỗ trợ scan batch để nhận đồng thời mã thùng và mã các sản phẩm trong thùng.                                                                                          Must

  F-WM-4.3   Scan/nhập linh kiện         Scan box/serial linh kiện, nhập quantity với linh kiện không mã; chưa bắt buộc gán khay tại thời điểm nhập.                                                                                               Must

  F-WM-4.4   Post Receipt                Xác nhận nhập, tăng tồn, cập nhật trạng thái item/container. Linh kiện chưa xếp khay có trạng thái Chờ xếp khay.                                                                                          Must

  F-WM-4.5   Xử lý mã mới                Cho phép tạo item/container nếu mã chưa có nhưng SKU hợp lệ.                                                                                                                                              Should

  F-WM-4.6   Putaway/Xếp khay sau nhập   Sau Post Receipt, nhân viên scan mã khay + box/linh kiện để cập nhật vị trí khay. Không làm tăng/giảm tồn.                                                                                                Must

  F-WM-4.7   Demo scan nhập kho          Bấm nút demo để giả lập scan một hoặc nhiều mã/chip trong màn nhập kho.                                                                                                                                   Must

  F-WM-4.8   Reverse Receipt sau Post    Tạo reversal append-only tham chiếu phiếu/movement nhập gốc, nêu lý do và quyền thực hiện. Khi post reversal, giảm tồn bằng movement RECEIPT_REVERSED; không sửa/xóa movement gốc; bắt buộc idempotent.   Must
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 6.5. Epic E - Xuất kho

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Mã**     **Chức năng**                            **Mô tả**                                                                                                                                                                                                            **Ưu tiên**
  ---------- ---------------------------------------- -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- -------------
  F-WM-5.1   Tạo phiếu xuất                           Ghi nơi nhận là đại lý/cửa hàng con/đối tượng khác; không tạo tồn cho nơi nhận.                                                                                                                                      Must

  F-WM-5.2   Xuất nguyên thùng                        Scan mã thùng, hệ thống đưa toàn bộ máy còn trong thùng vào phiếu xuất.                                                                                                                                              Must

  F-WM-5.3   Xuất lẻ sản phẩm                         Scan từng mã máy để xuất một vài sản phẩm trong thùng.                                                                                                                                                               Must

  F-WM-5.4   Xuất linh kiện                           Scan box hoặc serial, nhập số lượng xuất; không xuất khay.                                                                                                                                                           Must

  F-WM-5.5   Post Issue                               Xác nhận xuất, giảm tồn, cập nhật item/container/movement. Post Issue bắt buộc idempotent để tránh click/gọi API lặp làm trừ tồn hai lần.                                                                            Must

  F-WM-5.6   Chặn xuất sai                            Chặn mã không tồn, không trong kho, đã xuất, đang bảo hành, đã trả khách, thiếu số lượng.                                                                                                                            Must

  F-WM-5.7   Reverse Issue / xác nhận hàng quay lại   Tạo chứng từ đảo tham chiếu phiếu/movement xuất gốc. Chỉ cộng hàng về IN_STOCK sau khi xác nhận hàng vật lý đã quay lại kho và đủ điều kiện bán; sinh ISSUE_REVERSED; không sửa movement gốc; bắt buộc idempotent.   Must

  F-WM-5.8   Correction/Return Document               Dùng chứng từ nghiệp vụ riêng cho trả hàng/sửa sai hợp lệ; lưu lý do, người xác nhận, original_document_id và original_movement_id. Không dùng stocktake để che lỗi Post.                                            Must
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 6.6. Epic F - Tồn kho và kiểm kê

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Mã**     **Chức năng**                            **Mô tả**                                                                                                                                                                                                               **Ưu tiên**
  ---------- ---------------------------------------- ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- -------------
  F-WM-6.1   Xem tồn kho                              Tồn theo SKU, loại hàng và trạng thái. Container/carton/box/tray chỉ dùng để truy vết, mapping chứa hàng và gợi ý vị trí, không phải lớp tồn độc lập.                                                                   Must

  F-WM-6.2   Tra cứu vị trí linh kiện                 Tìm SKU linh kiện và xem đang ở box nào, tray nào.                                                                                                                                                                      Must

  F-WM-6.3   Tạo phiên kiểm kê                        Kiểm kê toàn kho, theo SKU hoặc theo nhóm sản phẩm/linh kiện; khi tạo phiên phải ghi stocktake_started_at và snapshot tồn hệ thống làm baseline đối soát.                                                               Must

  F-WM-6.4   Scan kiểm kê                             Scan batch item/carton/box/tray hoặc dùng Demo máy quét; nhập quantity với linh kiện không serial.                                                                                                                      Must

  F-WM-6.5   Đối soát thiếu/thừa                      Tính expected_at_count = snapshot_qty + net_movement_after_snapshot_to_count_cutoff; variance_to_apply = actual_qty - expected_at_count. Không lấy actual trừ snapshot trực tiếp khi có movement.                       Must

  F-WM-6.6   Áp dụng adjustment kiểm kê               QL kho có thể duyệt toàn bộ hoặc partial approval theo variance line. Apply chỉ xử lý dòng APPROVED; sinh ADJUST_IN/OUT và cập nhật tồn trong cùng transaction. Phiên chỉ APPLIED khi mọi dòng đã xử lý/waive hợp lệ.   Must

  F-WM-6.7   Kiểm kê không khóa hệ thống              Không khóa toàn kho; chốt snapshot và count_cutoff_at theo dòng. Transaction đang mở/giao cắt được phân loại theo posted_at/committed_at. Có record/session version để phát hiện stale review.                          Must

  F-WM-6.8   Cảnh báo biến động trong phiên kiểm kê   Hiển thị movement sau snapshot đến count_cutoff/apply, yêu cầu QL kho xem và ack trước khi duyệt. Ví dụ snapshot 100, issue -10, actual 90 thì expected 90 và variance_to_apply = 0.                                    Must

  F-WM-6.9   Partial approval và stale session        Mỗi variance line có status PENDING/APPROVED/WAIVED/APPLIED; cập nhật dùng version/If-Match. Session/version cũ trả conflict, không áp dụng trên dữ liệu stale.                                                         Must
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 6.7. Epic G - Bảo hành/sửa chữa cơ bản

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Mã**     **Chức năng**                   **Mô tả**                                                                                                                                                                                                                                        **Ưu tiên**
  ---------- ------------------------------- ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ -------------
  F-WM-7.1   Tiếp nhận bảo hành              Ghi tên/SĐT người gửi, mã máy nếu có, SKU/model nếu biết, bệnh máy, ngày nhận; nếu mất/hỏng chip hoặc khách không nhớ mã máy thì tạo case TEMP_ONLY theo temporary_item_code, không gắn item_code.                                               Must

  F-WM-7.2   Cập nhật trạng thái             RECEIVED/CHECKING/REPAIRING/COMPLETED/RETURNED/CANCELLED.                                                                                                                                                                                        Must

  F-WM-7.3   Ghi kết quả xử lý               Ghi bệnh xác nhận, ghi chú sửa/bảo hành xong, ngày trả khách.                                                                                                                                                                                    Must

  F-WM-7.4   Tra cứu lịch sử bảo hành        Xem lịch sử theo mã máy, SĐT, SKU, bệnh, ngày nhận/trả.                                                                                                                                                                                          Must

  F-WM-7.5   Quản lý chi phí/người sửa       Không thuộc MVP.                                                                                                                                                                                                                                 Won't

  F-WM-7.6   Tạo case bảo hành không có mã   Khi chip/mã bị mất, hỏng, không tìm thấy hoặc khách không nhớ mã máy, cho phép tạo Warranty Case TEMP_ONLY. Hệ thống sinh temporary_item_code/mã temp để quản lý nội bộ; không liên kết máy đã bán và không dùng để truy vết lịch sử bán hàng.   Must
  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 6.8. Epic H - App xem sản phẩm

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Mã**      **Chức năng**                             **Mô tả**                                                                                                                                                                                                                                                                       **Ưu tiên**
  ----------- ----------------------------------------- ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- -------------
  F-PV-1.1    Danh sách sản phẩm                        Hiển thị sản phẩm active, published_to_app = true; có ảnh đại diện, tên, SKU/model, nhóm hàng, available_qty, tình trạng có hàng và tag ngành nghề.                                                                                                                             Must

  F-PV-1.2    Chi tiết sản phẩm                         Hiển thị SKU, model, mô tả, thông số kỹ thuật, hình ảnh, bảo hành, phụ kiện đi kèm/tương thích, ngành nghề phù hợp, available_qty và tình trạng có hàng.                                                                                                                        Must

  F-PV-1.3    Tìm kiếm nhanh                            Tìm theo tên sản phẩm, SKU, model, category, từ khóa tiếng Việt không dấu/có dấu và mã sản phẩm nếu cần.                                                                                                                                                                        Must

  F-PV-1.4    Bộ lọc sản phẩm                           Lọc theo ngành nghề, nhóm máy, loại máy, dòng pin 20V/12V MAX, có dây/không dây, phụ kiện tương thích, trạng thái còn hàng/hết hàng và khoảng available_qty nếu cần.                                                                                                            Must

  F-PV-1.5    Xem tồn kho trên App PV                   Hiển thị số lượng tồn khả dụng available_qty từ kho tổng theo SKU, kèm trạng thái Còn hàng/Hết hàng. Còn hàng nếu available_qty \> 0; Hết hàng nếu available_qty = 0; không hiển thị trạng thái trung gian trong MVP. Không hiển thị serial/item/container/box/tray chi tiết.   Must

  F-PV-1.6    Gợi ý theo ngành nghề                     Người dùng chọn ngành nghề, hệ thống gợi ý danh sách sản phẩm phù hợp bằng rule cấu hình; không cần AI trong MVP.                                                                                                                                                               Must

  F-PV-1.7    Nhóm ngành nghề chuẩn                     Cấu hình sẵn nhóm: Ngành gỗ, Ngành kim loại, Công trường xây dựng, Sửa chữa ô tô, Dụng cụ vườn & không dây.                                                                                                                                                                     Must

  F-PV-1.8    Sản phẩm liên quan/phụ kiện tương thích   Từ chi tiết sản phẩm, hiển thị phụ kiện hoặc máy liên quan để chủ đại lý/khách dễ chọn đúng bộ sản phẩm.                                                                                                                                                                        Must

  F-PV-1.9    Quản trị publish sản phẩm                 Admin/QL kho cấu hình published_to_app, ảnh, mô tả, tag, ngành nghề, thứ tự hiển thị và sản phẩm nổi bật.                                                                                                                                                                       Must

  F-PV-1.10   Theo dõi lượt xem/tìm kiếm cơ bản         Ghi nhận sản phẩm/ngành nghề được xem hoặc tìm kiếm để phục vụ cải thiện danh mục sau này.                                                                                                                                                                                      Should

  F-PV-1.11   Đặt mua/giữ hàng/thanh toán               Không thuộc MVP; App PV chỉ hỗ trợ xem và tra cứu sản phẩm/tồn kho dạng available_qty, không đặt mua/giữ hàng/thanh toán.                                                                                                                                                       Won't
  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

#### 6.8.1. Phạm vi chi tiết App PV

App PV là kênh tra cứu sản phẩm dành cho chủ đại lý/cửa hàng hoặc khách hàng. Mục tiêu của App PV là giúp người dùng tìm nhanh sản phẩm đang có trong kho tổng, xem được số lượng tồn khả dụng theo SKU, hiểu sản phẩm dùng cho ngành nghề nào, xem thông số/phụ kiện tương thích và biết tình trạng còn hàng để liên hệ đặt hàng qua kênh ngoài hệ thống. App PV không phải module bán hàng, không tạo đơn, không giữ hàng và không làm thay đổi tồn kho.

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Nhóm nội dung**                   **Yêu cầu bổ sung cho App PV**
  ----------------------------------- ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  Đối tượng sử dụng                   Chủ đại lý/cửa hàng, khách hàng hoặc người xem sản phẩm được phép truy cập app.

  Dữ liệu hiển thị                    Chỉ hiển thị SKU/product profile active và published_to_app = true. Không hiển thị sản phẩm inactive hoặc chưa publish.

  Tồn kho hiển thị                    Lấy từ Inventory_Balance của kho tổng sau khi WM đã post. Bắt buộc hiển thị available_qty theo SKU, kèm trạng thái Còn hàng/Hết hàng. Đây là quyết định đã chốt với đối tác cho chủ đại lý/cửa hàng và khách hàng xem; không hiển thị serial/item/container/box/tray, movement, giá vốn hoặc tồn đại lý.

  Tìm kiếm                            Tìm theo tên sản phẩm, SKU, model, category, từ khóa ngành nghề, từ khóa không dấu/có dấu.

  Bộ lọc                              Ngành nghề, nhóm máy, loại máy, dòng pin, có dây/không dây, phụ kiện tương thích, tình trạng còn hàng/hết hàng.

  Gợi ý                               Gợi ý theo ngành nghề bằng rule cấu hình: ngành nghề + category/tag + sản phẩm ưu tiên + tồn khả dụng. Không dùng AI trong MVP.

  Giới hạn                            Không đặt mua, không thanh toán, không giữ hàng, không tạo phiếu xuất, không tạo tồn đại lý/cửa hàng con.
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

#### 6.8.2. Danh mục ngành nghề và gợi ý sản phẩm

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Ngành nghề**             **Nhu cầu thường gặp**                                                    **Nhóm sản phẩm gợi ý mặc định**
  -------------------------- ------------------------------------------------------------------------- -----------------------------------------------------------------------------------------------------------------
  Ngành gỗ                   Cắt, soi, chà nhám, hoàn thiện bề mặt gỗ.                                 Máy cưa tròn không dây, cưa lọng, máy chà nhám, máy phay, phụ kiện lưỡi cưa/giấy nhám phù hợp.

  Ngành kim loại             Cắt, mài, đánh bóng, siết/mở bu lông trong gia công kim loại.             Máy mài góc các loại, máy cắt, máy siết bu lông, đá cắt/đá mài/phụ kiện tương thích.

  Công trường xây dựng       Khoan bê tông, đục phá, cắt đá/gạch, lắp đặt tại công trình.              Máy khoan búa, máy phá bê tông, máy cắt đá, mũi khoan/mũi đục/phụ kiện đi kèm.

  Sửa chữa ô tô              Sửa chữa, tháo lắp, đánh bóng, xử lý chi tiết nhỏ trong garage.           Máy mài khuôn, cờ lê bánh cóc, máy đánh bóng, máy siết bu lông, bộ đầu khẩu/phụ kiện liên quan.

  Dụng cụ vườn & không dây   Làm vườn, chăm sóc cây, công việc cần cơ động không phụ thuộc dây điện.   Hệ thống pin 20V và 12V MAX, máy cắt cỏ, máy thổi bụi, máy cưa xích, máy khoan/siết pin và sạc/pin tương thích.
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

#### 6.8.3. Bộ lọc và thông tin hiển thị trên App PV

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Khu vực màn hình**    **Thông tin/Control cần có**                                                                                                              **Expected result**
  ----------------------- ----------------------------------------------------------------------------------------------------------------------------------------- ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  Trang danh sách         Search box, filter ngành nghề, nhóm máy, dòng pin, tình trạng hàng, sort theo mới nhất/phổ biến/còn hàng.                                 Người dùng lọc được danh sách sản phẩm phù hợp và thấy available_qty + tình trạng có hàng ngay trên card.

  Product card            Ảnh, tên sản phẩm, SKU/model, tag ngành nghề, tag dòng pin/công suất nếu có, available_qty và tình trạng có hàng.                         Card ngắn gọn, đủ thông tin để quyết định mở chi tiết.

  Chi tiết sản phẩm       Ảnh, mô tả, thông số kỹ thuật, phụ kiện tương thích, ngành nghề phù hợp, sản phẩm liên quan, available_qty và trạng thái tồn tổng quan.   Người dùng hiểu sản phẩm dùng cho việc gì, còn bao nhiêu hàng khả dụng tại kho tổng và có hàng hay không.

  Gợi ý theo ngành nghề   Chọn ngành nghề hoặc vào landing ngành nghề; hiển thị nhóm sản phẩm gợi ý theo rule.                                                      Danh sách gợi ý ưu tiên sản phẩm published, active và còn hàng.

  Tồn kho hiển thị        Hiển thị available_qty dạng số lượng, kèm trạng thái Còn hàng/Hết hàng.                                                                   Hiển thị available_qty đã chốt cho App PV; không làm lộ serial/item/container; không nhầm thành tồn đại lý; không có đặt hàng/giữ hàng/thanh toán. Nếu dữ liệu cache, hiển thị thời điểm cập nhật cuối cùng nếu UI hỗ trợ.

  Empty state             Không có sản phẩm phù hợp bộ lọc.                                                                                                         Hiển thị thông báo và nút xóa bộ lọc/quay lại tất cả sản phẩm.
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 6.9. Epic I - Báo cáo

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Mã**     **Báo cáo**                  **Mô tả**                                                                                                                                            **Ưu tiên**
  ---------- ---------------------------- ---------------------------------------------------------------------------------------------------------------------------------------------------- -------------
  F-RP-1.1   Báo cáo tồn hiện tại         Tồn theo SKU, loại hàng và trạng thái; có thể hiển thị container/carton/box/tray như thông tin truy vết/vị trí, không cộng thành bucket tồn riêng.   Must

  F-RP-1.2   Báo cáo nhập xuất tồn        Số đầu kỳ, nhập, xuất, điều chỉnh, cuối kỳ theo khoảng thời gian.                                                                                    Must

  F-RP-1.3   Báo cáo lịch sử nhập/xuất    Lọc theo ngày, SKU, chứng từ, người thao tác, nơi nhận.                                                                                              Must

  F-RP-1.4   Báo cáo kiểm kê              Thiếu/thừa/điều chỉnh theo phiên kiểm kê.                                                                                                            Must

  F-RP-1.5   Báo cáo bảo hành theo bệnh   Trong khoảng thời gian, máy loại A bị bệnh B là bao nhiêu.                                                                                           Must

  F-RP-1.6   Export Excel/PDF             Tải dữ liệu báo cáo để đối soát.                                                                                                                     Should
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 7. Luồng nghiệp vụ chi tiết

### 7.1. Luồng nhập kho sản phẩm/máy theo thùng

1\. Nhân viên kho tạo phiếu nhập, chọn nguồn nhập và SKU sản phẩm dự kiến.

2\. Nhân viên scan/nhập mã thùng. Nếu thùng chưa tồn tại, hệ thống tạo container type CARTON và gắn SKU sản phẩm.

3\. Nhân viên scan/nhập từng mã máy trong thùng. Hệ thống tạo hoặc cập nhật Item_Instance, gắn item với SKU và carton_code.

4\. Hệ thống kiểm tra tất cả item trong cùng một carton phải cùng SKU. Nếu khác SKU, chặn hoặc yêu cầu tách thùng.

5\. Nhân viên kiểm tra số lượng thực nhận so với phiếu nhập.

6\. Khi Post Receipt, hệ thống cập nhật item_status = IN_STOCK, carton_status = IN_STOCK/PARTIAL tùy số lượng, tăng tồn SKU và ghi Inventory_Movement = RECEIPT_POSTED.

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Trường hợp**                                           **Cách xử lý**
  -------------------------------------------------------- -----------------------------------------------------------------------------------------------------------------------------------------------------------
  Thùng có sẵn mã và danh sách item đã biết                Scan thùng có thể load danh sách item. Người dùng kiểm tra và post.

  Thùng mới, item mới                                      Tạo thùng trước, scan/nhập từng item trong thùng, sau đó post.

  Mã máy chưa có trong hệ thống                            Cho phép tạo item nhanh nếu SKU hợp lệ và mã chưa trùng.

  Mã máy trùng                                             Chặn tạo mới, hiển thị item đang thuộc phiếu/kho/trạng thái nào.

  Máy quét phạm vi rộng đọc cả thùng và item trong thùng   Hệ thống nhận một scan_batch gồm mã thùng và nhiều mã sản phẩm, tự phân loại code_type, map item vào thùng, cảnh báo mã lạ/trùng/khác SKU trước khi post.

  Hộp đựng một sản phẩm có cùng mã với sản phẩm            Không tạo container box riêng. Scan mã trên hộp hay trên thân sản phẩm đều trả về cùng item_code/chip_code.
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 7.2. Luồng nhập kho linh kiện

1\. Nhân viên tạo phiếu nhập linh kiện và chọn SKU linh kiện.

2\. Nếu linh kiện có mã riêng: scan/nhập từng mã linh kiện và gắn vào box/hộp linh kiện nếu cần; chưa bắt buộc gán khay tại thời điểm nhập.

3\. Nếu linh kiện không có mã riêng: scan/nhập mã box, chọn SKU linh kiện, nhập quantity thực nhận.

4\. Khi Post Receipt, hệ thống tăng tồn theo SKU linh kiện và ghi trạng thái vị trí là RECEIVED_PENDING_PUTAWAY/Chờ xếp khay nếu box chưa được đặt vào khay thực tế.

5\. Sau khi nhân viên thực tế mang hộp linh kiện vào khay, nhân viên mở chức năng Putaway/Xếp khay, scan mã khay + mã box/linh kiện, nhập quantity nếu là linh kiện không serial, rồi xác nhận để cập nhật vị trí khay. Putaway chỉ cập nhật vị trí tìm kiếm, không tăng/giảm tồn.

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Rule box/khay linh kiện: Một box chỉ nên chứa một SKU linh kiện để tránh nhầm lẫn khi xuất/kiểm kê. Một tray có thể chứa nhiều box linh kiện khác SKU. Post Receipt tăng tồn ngay và để vị trí là Chờ xếp khay nếu chưa đặt vào khay. Putaway/Xếp khay sau nhập chỉ cập nhật vị trí box trong tray, không làm tăng/giảm tồn.**
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 7.3. Luồng xuất kho sản phẩm

1\. Nhân viên tạo phiếu xuất, chọn nơi nhận là đại lý/cửa hàng con/đối tượng khác.

2\. Nếu xuất nguyên thùng: scan mã thùng. Hệ thống tự lấy toàn bộ item còn IN_STOCK trong thùng vào phiếu xuất.

3\. Nếu xuất lẻ: scan từng mã máy cần xuất. Hệ thống kiểm tra máy đang còn trong kho tổng và chưa bị xuất/bảo hành/đã trả khách/lost.

4\. Nhân viên kiểm tra danh sách item và số lượng theo SKU.

5\. Khi Post Issue, hệ thống cập nhật item_status = ISSUED, giảm tồn SKU, cập nhật carton_status = PARTIAL hoặc ISSUED, ghi Inventory_Movement = ISSUE_POSTED. Post Issue bắt buộc idempotent để retry/click lại không trừ tồn thêm lần nữa.

  --------------------------------------------------------------------------------------------------------
  **Tình huống**                        **Validation bắt buộc**
  ------------------------------------- ------------------------------------------------------------------
  Scan thùng đã xuất hết                Báo lỗi: thùng không còn item khả dụng để xuất.

  Scan thùng xuất một phần trước đó     Chỉ đưa các item còn IN_STOCK vào phiếu.

  Scan item không thuộc thùng đã chọn   Cho phép nếu phiếu xuất lẻ; cảnh báo nếu đang xuất nguyên thùng.

  Scan item đang bảo hành               Chặn xuất vì item không phải tồn khả dụng.

  Scan item đã xuất                     Chặn trùng, không giảm tồn lần nữa.
  --------------------------------------------------------------------------------------------------------

### 7.4. Luồng xuất kho linh kiện

1\. Nhân viên tạo phiếu xuất linh kiện hoặc thêm dòng linh kiện vào phiếu xuất.

2\. Nhân viên tìm SKU linh kiện, hệ thống hiển thị box/tray đang có hàng để dễ tìm trong kho.

3\. Nếu SKU linh kiện có is_serial_tracked = true: scan từng mã linh kiện cần xuất.

4\. Nếu linh kiện không có serial: scan box, nhập số lượng xuất. Hệ thống không cho xuất vượt quantity còn lại trong box.

5\. Không cho xuất khay. Mã khay chỉ hỗ trợ tìm kiếm hộp và kiểm kê.

6\. Post Issue để giảm tồn linh kiện, cập nhật box quantity/status và ghi movement.

### 7.5. Luồng kiểm kê kho

1\. QL kho/NV kho tạo phiên theo phạm vi. Hệ thống ghi stocktake_started_at, session_version và Stocktake_Snapshot trong cùng transaction.

2\. Nhân viên scan batch item/carton/box/tray hoặc nhập quantity. Với mỗi lần xác nhận count, hệ thống ghi count_cutoff_at.

3\. Hệ thống tập hợp net_movement đã commit sau snapshot đến count_cutoff_at. Transaction chưa commit tại cutoff không được tính; khi commit sau đó phải xuất hiện trong movement warning.

4\. Công thức bắt buộc: expected_at_count = snapshot_qty + net_movement_after_snapshot_to_count_cutoff; variance_to_apply = actual_qty - expected_at_count.

5\. Ví dụ kiểm chứng: snapshot 100; xuất sau snapshot = -10; actual 90; expected_at_count = 90; variance_to_apply = 0. Hệ thống không tạo adjustment -10 lần nữa.

6\. Màn Review hiển thị actual, snapshot, expected, movement list, variance và trạng thái line. QL kho phải ack movement warning trước khi duyệt.

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Loại hàng**                 **Cách kiểm kê**                                                                                                     **Kết quả đối soát**
  ----------------------------- -------------------------------------------------------------------------------------------------------------------- --------------------------------------------------------------------------------------------------------------------------------------------------------------------
  Sản phẩm có mã từng máy       Scan từng mã máy hoặc scan thùng để load danh sách máy trong thùng.                                                  Thiếu/thừa theo từng item code.

  Thùng sản phẩm                Scan thùng, kiểm tra số item còn trong thùng.                                                                        Thùng thiếu item, thùng thừa item, item không thuộc thùng.

  Linh kiện có serial           Scan từng mã linh kiện hoặc scan box để xem danh sách serial.                                                        Thiếu/thừa theo serial.

  Linh kiện không serial        Scan box, nhập quantity thực đếm.                                                                                    Chênh lệch quantity theo SKU/box.

  Khay linh kiện                Scan tray để xem các box bên trong.                                                                                  Sai lệch box trong tray; không điều chỉnh tồn trực tiếp theo tray.

  Scan batch/Wide-range scan    Một lần scan/demo scanner có thể trả về mã thùng, mã item, mã box, mã tray.                                          Hệ thống phân loại code_type, cảnh báo mã trùng/mã lạ/mã không thuộc phạm vi kiểm kê.

  Nguyên tắc vận hành kiểm kê   Không khóa nhập/xuất trên hệ thống.                                                                                  Thực tế tạm dừng xuất và hàng nhập mới chưa đưa vào kho kiểm kê; hệ thống ghi snapshot tại stocktake_started_at, chỉ điều chỉnh tồn sau khi QL kho duyệt variance.

  Snapshot kiểm kê              Khi tạo phiên, hệ thống ghi stocktake_started_at và snapshot tồn hệ thống theo SKU/item/box trong phạm vi kiểm kê.   Đối soát thiếu/thừa dựa trên snapshot. Nếu có movement phát sinh sau thời điểm snapshot, hệ thống cảnh báo “Có biến động trong phiên kiểm kê”.
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

7\. QL kho được partial approve từng variance line. Dòng chưa duyệt giữ PENDING/REVIEWING; dòng không áp dụng phải WAIVED kèm lý do.

8\. Apply Adjustment chỉ xử lý các dòng APPROVED, bắt buộc Idempotency-Key và If-Match/session_version. Cập nhật balance, movement và line status trong cùng transaction.

9\. Phiên chỉ chuyển APPLIED khi tất cả dòng đã APPLIED hoặc WAIVED hợp lệ. Retry không tạo double adjustment; stale version trả 409/412 theo API convention.

### 7.6. Luồng bảo hành/sửa chữa cơ bản

1\. Khách/đại lý gửi máy cần bảo hành/sửa chữa về phòng bảo hành của kho tổng.

2\. Nhân viên bảo hành scan/nhập mã máy nếu có. Item hợp lệ khi đang ISSUED hoặc RETURNED_TO_CUSTOMER và không có warranty case active khác. Hệ thống kiểm tra unique active-case trước khi tạo case.

3\. Nếu máy bị mất chip/mã, mã bị hỏng/mờ, không tìm thấy mã trên hệ thống hoặc khách không nhớ mã máy, nhân viên chọn “Tạo case không có mã/TEMP_ONLY”. Hệ thống sinh temporary_item_code/mã temp để dán hoặc ghi trên máy và dùng mã này để quản lý nội bộ trong suốt quá trình tiếp nhận - sửa chữa - trả máy.

4\. Nhân viên ghi thông tin người gửi: tên, SĐT, ngày nhận, tình trạng ban đầu, phụ kiện đi kèm nếu có, bệnh/lỗi khách báo, missing_code_reason và manual_product_description đối với case TEMP_ONLY.

5\. Hệ thống tạo Warranty_Case = RECEIVED. Với case có mã, item_status chuyển từ ISSUED hoặc RETURNED_TO_CUSTOMER sang IN_WARRANTY. Một item chỉ có tối đa một case active. TEMP_ONLY không gắn item và không làm tăng tồn bán.

6\. Trong quá trình xử lý, nhân viên cập nhật trạng thái CHECKING/REPAIRING/COMPLETED và ghi bệnh xác nhận hoặc ghi chú xử lý.

7\. Khi trả máy, Warranty_Case = RETURNED và item_status = RETURNED_TO_CUSTOMER. Trạng thái này cho phép mở một warranty case mới trong lần bảo hành sau, nhưng vẫn không tính Available Stock. Case TEMP_ONLY trả theo mã tạm.

Nguyên tắc chốt cho case TEMP_ONLY: hệ thống chỉ đảm bảo quản lý quá trình tiếp nhận - kiểm tra - sửa chữa - trả máy theo mã tạm; không đảm bảo và không cố truy vết chính xác về máy đã bán trước đó, vì định danh gốc đã mất.

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Thông tin case bảo hành cần có**   **Bắt buộc?**       **Ghi chú**
  ------------------------------------ ------------------- -------------------------------------------------------------------------------------------------------------------------------------------
  warranty_case_code                   Có                  Mã phiếu bảo hành/sửa chữa.

  customer_name                        Có                  Tên người gửi bảo hành.

  customer_phone                       Có                  Số điện thoại để tra cứu/trả máy.

  item_code/serial_number              Nếu có              Scan mã/chip máy nếu có. Nếu mất/hỏng chip, mã mờ, không tìm thấy hoặc khách không nhớ mã máy thì để trống và chuyển sang case TEMP_ONLY.

  sku_id/model_name                    Nếu biết            Dùng để thống kê máy loại A bị bệnh B. Nếu không xác định được, cho phép chọn/nhập “Chưa xác định/UNKNOWN_MODEL”.

  reported_defect                      Có                  Bệnh khách báo ban đầu.

  confirmed_defect                     Nên có              Bệnh/lỗi sau khi kiểm tra.

  case_status                          Có                  RECEIVED/CHECKING/REPAIRING/COMPLETED/RETURNED/CANCELLED.

  received_date/returned_date          Có                  Dùng cho báo cáo theo thời gian.

  repair_cost/technician_id            Không               Ngoài MVP.

  missing_code_reason                  Có nếu mất mã       Lý do không có mã/chip: chip hỏng, rơi mất, mã mờ, khách không nhớ mã, không tìm thấy trên hệ thống...

  manual_product_description           Có nếu mất mã       Mô tả nhận diện máy thay cho item_code: model ước lượng, màu, ngoại hình, phụ kiện đi kèm, dấu hiệu nhận biết.

  temporary_item_code                  Có nếu mất mã       Mã temp do hệ thống sinh để dán/ghi trên máy. Đây là mã quản lý nội bộ của ca bảo hành, không phải item_code/mã máy đã bán.

  identification_status                Có                  IDENTIFIED hoặc TEMP_ONLY. TEMP_ONLY nghĩa là không có định danh gốc, chỉ quản lý theo mã temp.

  sales_trace_status                   Có nếu mất mã       Giá trị NOT_TRACEABLE. Dùng để thể hiện hệ thống không truy được máy đã bán và không liên kết lịch sử bán hàng.

  received_images                      Nên có nếu mất mã   Ảnh máy khi tiếp nhận để giảm nhầm lẫn khi sửa/trả khách.
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 7.7. Luồng App PV xem sản phẩm và gợi ý theo ngành nghề

1\. Người dùng mở App PV, hệ thống hiển thị danh sách sản phẩm đã publish và active.

2\. Người dùng tìm kiếm theo tên sản phẩm, SKU, model hoặc từ khóa ngành nghề; hệ thống trả về sản phẩm khớp điều kiện.

3\. Người dùng lọc theo ngành nghề, nhóm máy, dòng pin, trạng thái còn hàng/hết hàng, khoảng số lượng tồn khả dụng nếu cần hoặc phụ kiện tương thích.

4\. Nếu người dùng chọn ngành nghề, hệ thống hiển thị nhóm sản phẩm gợi ý theo rule cấu hình. Ví dụ Ngành gỗ gợi ý máy cưa tròn không dây, cưa lọng, máy chà nhám, máy phay; Ngành kim loại gợi ý máy mài góc, máy cắt, máy siết bu lông.

5\. Người dùng mở chi tiết sản phẩm để xem ảnh, mô tả, thông số kỹ thuật, phụ kiện tương thích, ngành nghề phù hợp, available_qty và tình trạng có hàng tại kho tổng.

6\. App PV đọc tồn khả dụng đã post từ WM và hiển thị available_qty theo SKU tại kho tổng kèm trạng thái Còn hàng/Hết hàng. Còn hàng nếu available_qty \> 0; Hết hàng nếu available_qty = 0. App PV không giữ hàng, không trừ tồn, không tạo đơn và không tạo phiếu xuất.

7\. Nếu sản phẩm hết hàng hoặc không tìm thấy, hệ thống hiển thị gợi ý sản phẩm liên quan/phụ kiện tương thích hoặc nút xóa bộ lọc.

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Tình huống**                            **Cách xử lý**
  ----------------------------------------- ---------------------------------------------------------------------------------------------------------------------------------
  Người dùng lọc Ngành gỗ                   Hiển thị sản phẩm gắn industry = WOOD hoặc tag ngành gỗ; ưu tiên sản phẩm còn hàng và được cấu hình nổi bật.

  Người dùng lọc Dụng cụ vườn & không dây   Hiển thị nhóm máy dùng pin 20V/12V MAX, máy vườn và phụ kiện pin/sạc tương thích.

  Sản phẩm còn tồn trong kho tổng           Hiển thị available_qty và trạng thái Còn hàng/Hết hàng theo tồn khả dụng đã post của kho tổng. Còn hàng nếu available_qty \> 0.

  Sản phẩm hết tồn                          Hiển thị available_qty = 0 và trạng thái Hết hàng; không cho đặt mua/giữ hàng trong MVP.

  Sản phẩm inactive hoặc chưa publish       Không hiển thị trên App PV dù vẫn tồn tại trong WM/SKU Master.

  Tồn thay đổi do kho post nhập/xuất        App PV cập nhật lại available_qty và tình trạng có hàng theo Inventory_Balance đã post, không tự tạo movement.
  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 8. Business rules và validation

### 8.0. Rule scope kho tổng và đại lý

BR-SCOPE-01: MVP chỉ quản lý 01 kho tổng, không quản lý tồn đại lý/cửa hàng con. Không hiển thị chức năng tạo kho đại lý/kho con; không có filter tồn theo đại lý; tất cả tồn/movement/stocktake thuộc kho tổng.

BR-SCOPE-02: Đại lý/cửa hàng con là master data nơi nhận hàng, không phải kho. Tạo đại lý không sinh Warehouse, không sinh Inventory_Balance và không có trạng thái tồn của đại lý.

BR-SCOPE-03: Phiếu xuất cho đại lý chỉ giảm tồn kho tổng và lưu agency_id để truy vết. Post Issue idempotent; available_qty kho tổng giảm đúng một lần; không có movement tăng tồn tại đại lý.

BR-SCOPE-04: Báo cáo XNT tính theo kho tổng. Đại lý chỉ là chiều thống kê nơi nhận nếu cần; nếu lọc agency thì kết quả là lịch sử xuất cho agency, không phải tồn tại agency.

BR-SCOPE-05: App PV chỉ hiển thị available_qty từ kho tổng. Không hiển thị serial/item/container, không hiển thị tồn đại lý, không tạo giữ hàng/đặt mua/thanh toán.

### 8.1. Rule SKU và mã

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Mã rule**   **Nội dung rule**                                                                                                             **Validation/Expected result**
  ------------- ----------------------------------------------------------------------------------------------------------------------------- ---------------------------------------------------------------------------------------------------------------------------------------------------------------
  BR-SKU-01     sku_code là duy nhất toàn hệ thống.                                                                                           Không cho tạo SKU trùng mã.

  BR-SKU-02     SKU chỉ đại diện loại hàng, không đại diện item/container.                                                                    Không cho dùng mã item/carton/box/tray làm SKU.

  BR-SKU-03     sku_type bắt buộc là PRODUCT hoặc COMPONENT.                                                                                  Dùng để tách flow sản phẩm và linh kiện.

  BR-SKU-04     is_serial_tracked quyết định cách quản lý tồn.                                                                                true: tồn dựa trên item/serial và cần scan serial trong nhập/xuất/kiểm kê; false: tồn dựa trên quantity theo box. Không bắt buộc mọi linh kiện đều có serial.

  BR-CODE-01    item_code/serial phải duy nhất nếu được dùng làm mã quét.                                                                     Chặn nhập trùng mã máy/linh kiện serial.

  BR-CODE-02    container_code phải duy nhất trong toàn hệ thống.                                                                             Không cho trùng mã thùng/hộp/khay.

  BR-CODE-03    Physical_Code/code_value là source of truth của nghiệp vụ scan; QR/Barcode/RFID chỉ là carrier/phương tiện đọc mã.            Mọi nghiệp vụ nhập/xuất/kiểm kê/bảo hành xử lý theo code_value + code_type, không theo tên thiết bị hoặc loại tem.

  BR-CODE-04    Mỗi mã vật lý phải có carrier_type để biết mã đang được dán/in/chứa trên QR, Barcode, RFID/NFC, nhãn thường hoặc mã tạm.      Không bắt buộc tích hợp phần cứng thật để tạo dữ liệu mã; carrier_type giúp mở rộng sau.

  BR-CODE-05    Nếu RFID/NFC trả về hardware_uid khác code_value, hệ thống phải map hardware_uid về Physical_Code trước khi post nghiệp vụ.   Không cho dùng UID chưa map để tăng/giảm tồn hoặc định danh máy đã bán.

  BR-CODE-06    scan_source phải phân biệt REAL_SCANNER, DEMO_SCANNER và MANUAL_INPUT.                                                        Scan_Event/Scan_Batch ghi đúng nguồn nhập để audit/debug; kết quả nghiệp vụ không đổi nếu code hợp lệ.

  BR-CODE-07    Tích hợp phần cứng thật chuyên sâu là Phase 2; MVP chỉ yêu cầu endpoint/flow nhận danh sách code_value sau scan.              Không block DEV/TEST MVP khi chưa chốt model thiết bị, driver, protocol hoặc vendor.

  BR-SKU-05     sku_code phải tuân theo cấu trúc Product Master Data đã chuẩn hóa.                                                            PRODUCT: BRAND-CATEGORY-MODEL-POWER\[-PACKAGE\]-VERSION. COMPONENT: LK-BRAND-TYPE-MODEL/SPEC-VERSION.

  BR-SKU-06     Batch nhập khẩu/container nhập khẩu không được đưa trực tiếp vào sku_code.                                                    Cùng model/biến thể nhưng nhập nhiều đợt vẫn là cùng SKU; batch được quản lý bằng import_batch_code/shipment_container_code.

  BR-SKU-07     Tình trạng hàng demo/lỗi/bảo hành không làm phát sinh SKU mới.                                                                Quản lý bằng item_condition, item_status hoặc stock_status; báo cáo có thể lọc theo condition nhưng tồn SKU không bị tách sai.

  BR-SKU-08     Kênh phân phối không được đưa vào sku_code.                                                                                   Đại lý/công trình/bán lẻ được quản lý trên phiếu xuất bằng sales_channel và receiver/agency, không tạo SKU theo kênh.

  BR-SKU-09     Chỉ tạo SKU mới khi khác biệt làm thay đổi bản chất sản phẩm cần bán, kiểm tồn hoặc báo cáo.                                  Khác brand/category/model/power/version/package_variant thì tạo SKU mới; khác batch/item/carton/condition/channel thì không tạo SKU mới.

  BR-SKU-10     sku_code không được sửa sau khi SKU đã phát sinh item, container, tồn hoặc movement.                                          UI disable field; API trả lỗi nếu cố đổi sku_code của SKU đã có dữ liệu vận hành.
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 8.2. Rule thùng, hộp, khay

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Mã rule**   **Nội dung rule**                                                                      **Validation/Expected result**
  ------------- -------------------------------------------------------------------------------------- -------------------------------------------------------------------------------------------------------------------------------------------------------
  BR-CON-01     Một thùng sản phẩm chỉ chứa sản phẩm cùng một SKU.                                     Nếu scan item khác SKU vào thùng, hệ thống chặn hoặc yêu cầu tạo thùng khác.

  BR-CON-02     Một box linh kiện chỉ chứa một SKU linh kiện.                                          Nếu muốn chứa SKU khác, tạo box khác.

  BR-CON-03     Một tray có thể chứa nhiều box linh kiện khác SKU.                                     Tray không có quantity tồn độc lập, chỉ là locator/mapping chứa box.

  BR-CON-04     Không double count tồn giữa container và item/quantity bên trong.                      Tồn sản phẩm tính theo item; tồn linh kiện không serial tính theo quantity trong box. Container/carton/box/tray không được cộng như bucket tồn riêng.

  BR-CON-05     Xuất nguyên thùng chỉ xuất các item còn IN_STOCK trong thùng.                          Item đã xuất trước đó không bị trừ tồn lần nữa.

  BR-CON-06     Không có nghiệp vụ xuất khay.                                                          Khay chỉ dùng để tìm kiếm/kiểm kê box linh kiện.

  BR-CON-07     Hộp đựng đơn chiếc của sản phẩm không phải container độc lập.                          Scan mã trên hộp hoặc trên sản phẩm đều là cùng một item_code/chip_code; không double count và không tạo mã hộp sản phẩm riêng.

  BR-CON-08     Sau scan batch, hệ thống phải phân loại được mã thùng, mã sản phẩm, mã box, mã khay.   UNKNOWN code phải cảnh báo và không được tự động post nếu chưa được người dùng xử lý.
  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 8.3. Rule nhập/xuất/tồn

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Mã rule**    **Nội dung rule**                                                                                                   **Validation/Expected result**
  -------------- ------------------------------------------------------------------------------------------------------------------- -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  BR-IN-01       Phiếu nhập ở DRAFT/SCANNING chưa làm tăng tồn.                                                                      Chỉ Post Receipt mới tăng tồn.

  BR-IN-02       Post Receipt phải idempotent.                                                                                       Không được post 2 lần làm tăng tồn 2 lần.

  BR-OUT-01      Phiếu xuất ở DRAFT/SCANNING chưa làm giảm tồn.                                                                      Chỉ Post Issue mới giảm tồn.

  BR-OUT-02      Không cho xuất item không còn trong kho tổng.                                                                       Chặn item đã ISSUED/LOST/IN_WARRANTY/RETURNED_TO_CUSTOMER; chỉ item IN_STOCK mới được xuất và tính Available.

  BR-OUT-03      Không cho xuất quantity linh kiện vượt số lượng còn lại trong box.                                                  Hiển thị số lượng khả dụng và yêu cầu nhập lại.

  BR-OUT-04      Xuất cho đại lý chỉ ghi nhận agency_id/nơi nhận trên phiếu xuất, không tạo tồn đại lý trong hệ thống.               Sau Post Issue chỉ giảm available_qty kho tổng, sinh ISSUE_POSTED tại kho tổng; không phát sinh warehouse_balance/Inventory_Balance hoặc movement tăng tồn cho đại lý.

  BR-INV-01      Stock balance phải khớp tổng item/quantity sau post.                                                                Có job/check đối soát nội bộ nếu cần.

  BR-SCAN-01     Mọi luồng nghiệp vụ ghi là scan; nhập tay và Demo máy quét chỉ là cách cung cấp dữ liệu scan.                       Không tách luồng nghiệp vụ riêng cho nhập tay/demo scanner.

  BR-SCAN-02     Một scan_batch có thể chứa nhiều mã/chip.                                                                           Hệ thống xử lý từng mã, cảnh báo trùng/lạ/sai trạng thái và chỉ post khi dữ liệu hợp lệ.

  BR-PTW-01      Sau Post Receipt linh kiện, hệ thống tăng tồn ngay và ghi vị trí Chờ xếp khay nếu chưa putaway.                     Inventory_Balance tăng theo SKU; location_hint = RECEIVED_PENDING_PUTAWAY.

  BR-PTW-02      Putaway/Xếp khay không làm tăng hoặc giảm tồn.                                                                      Chỉ cập nhật tray_code/container mapping/location hint, không sinh movement tăng/giảm tồn.

  BR-AGY-01      Đại lý/cửa hàng con là master data nơi nhận hàng, không phải warehouse.                                             Tạo/sửa/ngừng hoạt động Agency không sinh Warehouse, không sinh tồn và không được dùng agency_id như warehouse_id.

  BR-INOUT-02    Tồn kho chỉ thay đổi ở bước post/xác nhận cuối cùng.                                                                Post Receipt tăng tồn; Post Issue giảm tồn; Adjustment kiểm kê chỉ cập nhật tồn khi QL kho bấm Áp dụng adjustment và phiên chuyển APPLIED. Phiếu nhập/xuất không có duyệt/chờ duyệt; DRAFT/SCANNING không cập nhật tồn.

  BR-COMP-01     MVP hỗ trợ 2 mode linh kiện theo is_serial_tracked.                                                                 true: scan serial; false: quản lý quantity theo box. Không bắt buộc mọi linh kiện đều có serial.

  BR-PV-GEN-01   App PV chỉ hiển thị sản phẩm active và published_to_app = true.                                                     Ẩn sản phẩm inactive/chưa publish khỏi danh sách, tìm kiếm và gợi ý.

  BR-PV-GEN-02   App PV chỉ đọc tồn khả dụng từ kho tổng đã post.                                                                    Không tạo movement, không giữ hàng, không trừ tồn, không tạo tồn đại lý/cửa hàng con.

  BR-PV-GEN-03   Gợi ý ngành nghề dùng rule cấu hình.                                                                                Không cần AI trong MVP; ưu tiên sản phẩm còn hàng, active, published và có priority cao.

  BR-OUT-05      Post Issue phải idempotent.                                                                                         Client/API phải gửi Idempotency-Key; retry/click/gọi API lặp với cùng key trả lại kết quả đã xử lý, không giảm tồn lần hai, không sinh duplicate ISSUE_POSTED movement.

  BR-INOUT-01    Phiếu nhập và phiếu xuất không có bước chờ duyệt/duyệt.                                                             State hợp lệ của phiếu nhập/xuất là DRAFT/SCANNING/PARTIALLY_POSTED/POSTED/CLOSED/CANCELLED. Không hiển thị/không triển khai Submit/Approve/Reject cho nhập/xuất.

  BR-SCOPE-01    MVP chỉ quản lý 01 kho tổng, không quản lý tồn đại lý/cửa hàng con.                                                 Tồn/movement/stocktake/báo cáo XNT chỉ thuộc kho tổng.

  BR-SCOPE-02    Agency/Dealer là master data nơi nhận hàng, không phải warehouse.                                                   Không tạo Warehouse hoặc Inventory_Balance khi tạo Agency.

  BR-SCOPE-03    Post Issue cho agency chỉ giảm tồn kho tổng.                                                                        Không sinh movement tăng tồn agency; retry idempotent không giảm tồn lần hai.

  BR-REV-01      Mọi sửa sai sau Post phải dùng reversal append-only.                                                                Không UPDATE/DELETE Inventory_Movement gốc; reversal ghi original_document_id và original_movement_id/reversal_of_id.

  BR-REV-02      Reverse Receipt/Reverse Issue bắt buộc có quyền, lý do, audit và Idempotency-Key.                                   Retry không sinh movement đảo trùng; cùng key payload khác trả 409.

  BR-REV-03      Reverse Issue chỉ cộng hàng về Available sau xác nhận hàng vật lý đã quay lại kho và trạng thái/condition hợp lệ.   Nếu hàng chưa về, hỏng hoặc thiếu thì không cộng IN_STOCK; xử lý theo return/correction document.

  BR-REV-04      Không dùng stocktake để che lỗi Post.                                                                               Stocktake chỉ điều chỉnh chênh lệch kiểm đếm; lỗi chứng từ phải trace bằng reversal/correction.
  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 8.4. Rule kiểm kê

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Mã rule**   **Nội dung rule**                                                                                                  **Validation/Expected result**
  ------------- ------------------------------------------------------------------------------------------------------------------ --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  BR-COUNT-01   Kết quả kiểm kê chưa duyệt không tự cập nhật tồn.                                                                  Chỉ tạo variance ở trạng thái REVIEWING.

  BR-COUNT-02   Điều chỉnh tồn chỉ được tạo khi QL kho bấm Áp dụng adjustment.                                                     Khi áp dụng thành công, phiên chuyển APPLIED và hệ thống sinh movement ADJUST_IN/ADJUST_OUT.

  BR-COUNT-03   Scan trùng trong cùng phiên kiểm kê phải cảnh báo.                                                                 Không tính trùng item/serial; với quantity-only cho phép cập nhật lại quantity.

  BR-COUNT-04   Với linh kiện không serial, bắt buộc nhập quantity thực tế.                                                        Không đủ dữ liệu nếu chỉ scan box.

  BR-COUNT-05   Scan tray không thay thế việc đếm quantity trong box.                                                              Tray chỉ giúp liệt kê box cần kiểm.

  BR-COUNT-06   Không cần chức năng khóa nhập/xuất trên hệ thống trong thời gian kiểm kê.                                          Quy trình vận hành ngoài hệ thống tạm dừng xuất và giữ hàng nhập mới chưa đưa vào kho kiểm kê; hệ thống không cần auto-block nghiệp vụ.

  BR-COUNT-07   Scan kiểm kê có thể là scan batch hoặc Demo máy quét.                                                              Một lần scan có thể trả về nhiều mã, hệ thống phân loại và đối soát từng mã.

  BR-COUNT-08   Khi tạo phiên kiểm kê, hệ thống phải ghi stocktake_started_at và snapshot tồn hệ thống.                            Snapshot là baseline để đối chiếu thiếu/thừa; không dùng tồn hiện tại đã thay đổi sau thời điểm bắt đầu để tính variance.

  BR-COUNT-10   Nếu có movement phát sinh trong thời gian phiên kiểm kê mở, hệ thống phải cảnh báo trước khi áp dụng adjustment.   Hiển thị “Có biến động trong phiên kiểm kê” và cho QL kho xem danh sách movement phát sinh sau stocktake_started_at trước khi áp dụng adjustment.

  BR-COUNT-11   Apply Adjustment kiểm kê phải idempotent.                                                                          Khi QL kho áp dụng adjustment và chuyển phiên sang APPLIED, API bắt buộc có Idempotency-Key. Retry/click/gọi API lặp không tạo ADJUST_IN/ADJUST_OUT lần hai và không cập nhật tồn lần hai.

  BR-COUNT-09   Variance phải tính theo expected_at_count tại cutoff.                                                              expected_at_count = snapshot_qty + net_movement_after_snapshot_to_count_cutoff; variance_to_apply = actual_qty - expected_at_count.

  BR-COUNT-12   Cho phép partial approval theo variance line.                                                                      Chỉ line APPROVED được Apply; line WAIVED phải có lý do; session chỉ APPLIED khi không còn line PENDING.

  BR-COUNT-13   Apply dùng session version/If-Match để chống stale review.                                                         Version cũ không cập nhật tồn; trả conflict/precondition failed theo API convention.
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 8.5. Rule bảo hành

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Mã rule**   **Nội dung rule**                                                                                                       **Validation/Expected result**
  ------------- ----------------------------------------------------------------------------------------------------------------------- --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  BR-WAR-01     Tiếp nhận bảo hành không làm tăng tồn bán.                                                                              Không tạo RECEIPT_POSTED vào stock balance.

  BR-WAR-02     Case bảo hành phải có tên và SĐT người gửi.                                                                             Thiếu thông tin thì không lưu case.

  BR-WAR-03     Case bảo hành phải có item/serial hoặc temporary_item_code.                                                             Nếu có mã máy thì gắn item_code/serial_number. Nếu không có mã thì hệ thống sinh temporary_item_code, identification_status = TEMP_ONLY và không bắt buộc truy vết máy đã bán.

  BR-WAR-04     Bệnh/lỗi nên chọn từ danh mục chuẩn nhưng cho phép ghi chú tự do.                                                       Vừa báo cáo được, vừa không chặn vận hành.

  BR-WAR-05     Chỉ case COMPLETED mới được chuyển RETURNED.                                                                            Tránh trả máy khi chưa ghi kết quả xử lý.

  BR-WAR-06     Không quản lý chi phí sửa và người sửa trong MVP.                                                                       Các field này không bắt buộc và không hiển thị ở flow MVP.

  BR-WAR-07     Nếu chip/mã máy bị mất/hỏng, không tìm thấy hoặc khách không nhớ mã, cho phép tạo case TEMP_ONLY không gắn item_code.   Case phải có temporary_item_code, warranty_case_code, tên/SĐT khách, bệnh máy, missing_code_reason và mô tả nhận diện máy. Không làm tăng tồn bán.

  BR-WAR-08     Case TEMP_ONLY không truy vết về máy đã bán và không được tự động link với Item_Instance theo suy đoán.                 sales_trace_status = NOT_TRACEABLE. Không dùng case TEMP_ONLY để xác nhận máy đã bán theo mã máy; chỉ quản lý tiếp nhận - sửa chữa - trả máy theo mã temp.

  BR-WAR-09     Không bắt buộc SKU/model chính xác với case TEMP_ONLY.                                                                  Nếu không xác định được model, cho phép chọn UNKNOWN_MODEL/Chưa xác định và bắt buộc nhập manual_product_description để nhận diện khi trả máy.

  BR-WAR-10     Máy có mã hỗ trợ nhiều chu kỳ bảo hành.                                                                                 Luồng item_status: ISSUED hoặc RETURNED_TO_CUSTOMER → IN_WARRANTY → RETURNED_TO_CUSTOMER. Warranty_Case vẫn dùng state tiến độ riêng.

  BR-WAR-11     ISSUED, IN_WARRANTY và RETURNED_TO_CUSTOMER không tính vào Available Stock.                                             Available quantity chỉ đếm item_status = IN_STOCK. Tiếp nhận/trả bảo hành không làm tăng tồn bán và không đưa máy trở lại available trừ khi có nghiệp vụ nhập/điều chỉnh riêng được BA chốt sau.

  BR-WAR-12     Một item chỉ có tối đa một warranty case active.                                                                        Tạo case đồng thời phải được chặn bằng unique constraint/transactional check; trả 409 WARRANTY_ACTIVE_CASE_EXISTS.

  BR-WAR-13     RETURNED_TO_CUSTOMER là trạng thái đủ điều kiện mở lần bảo hành tiếp theo.                                              Không chuyển item về IN_STOCK; chỉ cho phép tạo case mới nếu case trước đã RETURNED/CANCELLED.
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 8.6. Rule App PV xem sản phẩm

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Mã rule**             **Nội dung rule**                                                                                                    **Validation/Expected result**
  ----------------------- -------------------------------------------------------------------------------------------------------------------- -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  BR-PV-01                App PV chỉ hiển thị sản phẩm active và published_to_app = true.                                                      Sản phẩm inactive/chưa publish không xuất hiện trong danh sách, tìm kiếm, gợi ý.

  BR-PV-02                App PV chỉ đọc tồn kho đã được WM post/xác nhận.                                                                     Không tạo Stock Movement, không giữ hàng, không trừ tồn và không tạo tồn đại lý.

  BR-PV-03                Tồn hiển thị trên App PV là available_qty theo SKU tại kho tổng, kèm trạng thái tổng quan.                           Bắt buộc hiển thị available_qty; không hiển thị serial/item/container/box/tray chi tiết; không cộng container thành tồn riêng.

  BR-PV-04                Tìm kiếm phải hỗ trợ tên, SKU, model, category và từ khóa có dấu/không dấu.                                          Kết quả trả về các sản phẩm phù hợp điều kiện và còn publish.

  BR-PV-05                Bộ lọc ngành nghề dùng mapping PV_Product_Industry hoặc tag cấu hình.                                                Một sản phẩm có thể thuộc nhiều ngành; lọc ngành nào thì sản phẩm phù hợp ngành đó xuất hiện.

  BR-PV-06                Gợi ý theo ngành nghề dùng rule cấu hình, không dùng AI trong MVP.                                                   Danh sách gợi ý ưu tiên sản phẩm active, published, có tồn và có priority cao.

  BR-PV-07                Phụ kiện tương thích chỉ là thông tin tham khảo trên App PV.                                                         Không tự động tạo combo, không tạo đơn hàng, không giữ tồn phụ kiện.

  BR-PV-08                Nếu không có sản phẩm theo bộ lọc, hệ thống hiển thị empty state rõ ràng.                                            Người dùng có thể xóa bộ lọc hoặc quay lại tất cả sản phẩm.

  BR-PV-09                App PV phải hiển thị số lượng tồn khả dụng.                                                                          Product card và màn chi tiết phải có available_qty từ kho tổng đã post, kèm trạng thái Còn hàng/Hết hàng. Quy tắc trạng thái: Còn hàng nếu available_qty \> 0; Hết hàng nếu available_qty = 0. Không hiển thị trạng thái trung gian trong MVP vì chưa có rule ngưỡng.

  BR-PV-10                App PV hiển thị available_qty theo SKU tại kho tổng cho chủ đại lý/cửa hàng và khách hàng theo quyết định đã chốt.   Không coi việc hiển thị số lượng là lỗi bảo mật/sensitivity blocker trong MVP; test phải verify đúng số lượng kho tổng.

  BR-PV-11                App PV chỉ có quyền đọc dữ liệu published/public.                                                                    Không cần phân quyền tồn chi tiết riêng cho đại lý trong MVP; nếu cần login/role theo đại lý thì đưa Phase 2.

  BR-PV-12                App PV không hiển thị dữ liệu vận hành nội bộ.                                                                       Ẩn serial/item/container/box/tray, movement, phiếu kho, kiểm kê, giá vốn, chi phí bảo hành và tồn đại lý.

  BR-PV-13                API App PV không được làm thay đổi tồn kho.                                                                          Không có reserve, order, payment, post issue hoặc adjustment từ App PV.

  BR-PV-14                Nếu App PV hiển thị tồn từ cache, cần có last_updated/last_inventory_sync_at trong response hoặc log đồng bộ.        Giảm rủi ro người dùng hiểu nhầm khi available_qty có độ trễ so với WM.
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 9. Báo cáo kho và báo cáo bảo hành

### 9.1. Báo cáo xuất nhập tồn

Báo cáo xuất nhập tồn là báo cáo cốt lõi cho kho tổng. Báo cáo cần lọc theo khoảng thời gian, SKU, loại hàng PRODUCT/COMPONENT, category và trạng thái.

  ------------------------------------------------------------------------------------
  **Chỉ tiêu**         **Công thức/ý nghĩa**
  -------------------- ---------------------------------------------------------------
  Tồn đầu kỳ           Tồn của SKU tại thời điểm bắt đầu kỳ báo cáo.

  Nhập trong kỳ        Tổng quantity từ movement RECEIPT_POSTED trong kỳ.

  Xuất trong kỳ        Tổng quantity từ movement ISSUE_POSTED trong kỳ.

  Điều chỉnh tăng      Tổng quantity từ ADJUST_IN sau kiểm kê.

  Điều chỉnh giảm      Tổng quantity từ ADJUST_OUT sau kiểm kê.

  Tồn cuối kỳ          Tồn đầu kỳ + nhập - xuất + điều chỉnh tăng - điều chỉnh giảm.
  ------------------------------------------------------------------------------------

### 9.2. Báo cáo tồn hiện tại

  --------------------------------------------------------------------------------------------
  **Cột báo cáo**           **Mô tả**
  ------------------------- ------------------------------------------------------------------
  SKU code/name             Loại hàng tồn kho.

  SKU type                  PRODUCT hoặc COMPONENT.

  Available quantity        Số lượng khả dụng hiện tại.

  Container/location hint   Với sản phẩm: thùng còn hàng. Với linh kiện: box/tray đang chứa.

  Serial tracked            Cho biết tồn được quản lý theo từng item hay quantity.

  Last movement date        Ngày phát sinh movement gần nhất.
  --------------------------------------------------------------------------------------------

### 9.3. Báo cáo bảo hành theo bệnh

Báo cáo này trả lời câu hỏi nghiệp vụ: “Trong khoảng thời gian X, số lượng máy loại A bị bệnh B là bao nhiêu?”.

  --------------------------------------------------------------------------------------------------
  **Bộ lọc**                **Cột kết quả**
  ------------------------- ------------------------------------------------------------------------
  Từ ngày - đến ngày        SKU/model, tên máy, bệnh/lỗi, số lượng case, số đã trả, số đang xử lý.

  SKU/model/máy             Phân tích bệnh thường gặp theo từng loại máy.

  Bệnh/lỗi                  Lọc theo defect_code/defect_name.

  Trạng thái case           RECEIVED/CHECKING/REPAIRING/COMPLETED/RETURNED.
  --------------------------------------------------------------------------------------------------

### 9.4. Báo cáo/analytics App PV cơ bản

Trong MVP, analytics App PV không bắt buộc để vận hành kho, nhưng nên ghi nhận tối thiểu để lãnh đạo biết sản phẩm/ngành nghề nào được quan tâm nhiều. Dữ liệu này không thay thế báo cáo kho và không được dùng để cập nhật tồn.

  ---------------------------------------------------------------------------------------------------------------------------------
  **Chỉ tiêu**                        **Ý nghĩa**
  ----------------------------------- ---------------------------------------------------------------------------------------------
  Lượt xem sản phẩm                   Sản phẩm nào được chủ đại lý/khách xem nhiều.

  Từ khóa tìm kiếm phổ biến           Nhu cầu tìm kiếm thực tế của người dùng App PV.

  Ngành nghề được quan tâm            Ngành gỗ/kim loại/công trường/sửa chữa ô tô/dụng cụ vườn & không dây được xem nhiều hay ít.

  Sản phẩm xem nhiều nhưng hết hàng   Gợi ý nhập hàng hoặc ưu tiên giới thiệu sản phẩm thay thế.
  ---------------------------------------------------------------------------------------------------------------------------------

## 10. Phạm vi MVP, ngoài MVP và MoSCoW

### 10.1. Tiêu chí đủ MVP

-   Quản lý được một kho tổng, SKU sản phẩm, SKU linh kiện và trạng thái active/inactive.

-   Quản lý được mã máy/item, mã thùng/carton, mã hộp/box, mã khay/tray.

-   Nhập kho được sản phẩm theo thùng và từng máy; nhập được linh kiện theo serial hoặc theo quantity trong box.

-   Xuất kho được nguyên thùng, xuất lẻ từng máy và xuất linh kiện theo quantity/serial.

-   Tồn kho không bị double count giữa thùng/hộp/khay và item/quantity bên trong.

-   Kiểm kê bằng scan/nhập mã, đối soát thiếu/thừa và chỉ điều chỉnh sau khi duyệt.

-   Bảo hành ghi nhận được khách gửi, SĐT, máy/SKU nếu có, bệnh, trạng thái và ngày trả khách; trường hợp mất mã máy quản lý bằng temporary_item_code/mã temp, không truy vết về máy đã bán.

-   Báo cáo được xuất nhập tồn và số lượng máy theo bệnh trong khoảng thời gian.

-   App xem sản phẩm cho chủ đại lý/cửa hàng hoặc khách hàng tra cứu danh sách sản phẩm đã publish, chi tiết sản phẩm, thông số, phụ kiện tương thích, số lượng tồn khả dụng theo kho tổng, bộ lọc theo ngành nghề/nhóm máy/dòng pin và gợi ý sản phẩm theo ngành nghề.

### 10.2. MoSCoW

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Phân loại**   **Chức năng**                                                                                                                                                                                                                                                                                                                                                                          **Lý do**
  --------------- -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- --------------------------------------------------------------------------------------------------------------------------------------------------------------
  Must            SKU sản phẩm/linh kiện, 2 mode linh kiện theo is_serial_tracked, item/serial, thùng lớn/carton, hộp/box linh kiện, khay/tray, chip/mã vật lý, demo máy quét, tạo đại lý, nhập, xuất, putaway linh kiện, tồn, kiểm kê, bảo hành, báo cáo cơ bản., App PV xem sản phẩm: danh sách/chi tiết/tìm kiếm/lọc/tồn khả dụng tổng quan/gợi ý ngành nghề/phụ kiện tương thích/quản trị publish.   Đây là nghiệp vụ lõi đã chốt sau khảo sát và 10 yêu cầu mới. Nếu thiếu chip/mã, demo scan, đại lý hoặc putaway thì flow thực tế không khớp vận hành Hoa Nam.

  Should          Import Excel, export Excel/PDF, bulk import serial nâng cao, dashboard nâng cao., analytics App PV cơ bản: lượt xem, từ khóa tìm kiếm, ngành nghề quan tâm.                                                                                                                                                                                                                            Tăng hiệu quả vận hành nhưng có thể triển khai sau bản chạy đầu nếu thiếu effort.

  Could           In tem mã từ hệ thống, tích hợp phần cứng sâu với máy quét thật, cấu hình rule scan nâng cao, lịch sử vòng đời chi tiết.                                                                                                                                                                                                                                                               Hữu ích nhưng không chặn flow nhập/xuất/kiểm kê.

  Won't           Quản lý nhiều kho/đại lý có tồn riêng, tồn cửa hàng con, kệ/bin chuyên sâu, giá vốn, bán hàng, thanh toán, chi phí bảo hành/SLA/người sửa., đặt mua/giữ hàng/thanh toán trên App PV.                                                                                                                                                                                                   Không phù hợp với khảo sát thực tế và mục tiêu đơn giản của MVP.
  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 11. Acceptance criteria và test scenario gợi ý

### 11.1. Acceptance criteria cấp module

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Mã AC**         **Acceptance criteria**
  ----------------- ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  AC-01             Khi tạo SKU, hệ thống bắt buộc phân loại PRODUCT/COMPONENT và không cho trùng sku_code.

  AC-02             Khi nhập sản phẩm theo thùng, hệ thống lưu được thùng chứa những mã máy nào và tăng tồn theo số máy.

  AC-03             Khi xuất nguyên thùng, hệ thống tự xuất toàn bộ máy còn khả dụng trong thùng và giảm tồn đúng một lần.

  AC-04             Khi xuất lẻ một vài máy trong thùng, hệ thống chỉ giảm tồn các máy được scan và cập nhật thùng còn một phần.

  AC-05             Khi nhập linh kiện không có serial, hệ thống cho nhập quantity theo box; sau Post Receipt tồn tăng và location = Chờ xếp khay. Việc gắn box vào tray thực hiện bằng Putaway riêng và không làm thay đổi tồn.

  AC-06             Khi xuất linh kiện không có serial, hệ thống không cho xuất vượt quantity còn lại trong box.

  AC-07             Khi kiểm kê, hệ thống tạo danh sách thiếu/thừa dựa trên snapshot tại stocktake_started_at và chưa cập nhật tồn trước khi phiên chuyển APPLIED.

  AC-08             Khi tiếp nhận bảo hành, case có tên/SĐT người gửi, SKU/model, bệnh và trạng thái; không làm tăng tồn bán.

  AC-09             Báo cáo xuất nhập tồn hiển thị đúng tồn đầu kỳ, nhập, xuất, điều chỉnh và tồn cuối kỳ theo SKU.

  AC-10             Báo cáo bảo hành lọc được số lượng máy theo SKU/model và bệnh trong khoảng thời gian.

  AC-11             Khi bấm Demo máy quét trong nhập/xuất/kiểm kê, hệ thống giả lập danh sách mã/chip và xử lý giống dữ liệu scan thật.

  AC-12             Khi scan batch gồm mã thùng và mã sản phẩm trong thùng, hệ thống phân loại đúng code_type, map item vào thùng và không tính trùng tồn.

  AC-13             Khi Post nhập linh kiện, tồn tăng ngay và vị trí hiển thị Chờ xếp khay nếu chưa putaway; khi xếp khay chỉ cập nhật vị trí, không đổi tồn.

  AC-14             Khi kiểm kê, hệ thống không khóa chức năng nhập/xuất; nếu có movement phát sinh trong phiên kiểm kê, hệ thống hiển thị cảnh báo “Có biến động trong phiên kiểm kê”; kết quả kiểm kê chỉ làm thay đổi tồn khi QL kho bấm Áp dụng adjustment và phiên chuyển APPLIED.

  AC-15             Khi nhân viên bảo hành không tìm thấy chip/mã máy hoặc khách không nhớ mã máy, hệ thống cho phép tạo case TEMP_ONLY không gắn item_code; hệ thống sinh temporary_item_code/mã temp, bắt buộc missing_code_reason và thông tin nhận diện máy, đồng thời ghi sales_trace_status = NOT_TRACEABLE.

  AC-16             Khi tạo phiếu xuất, hệ thống cho phép chọn đại lý/cửa hàng con từ danh mục đại lý làm nơi nhận và không tạo tồn kho cho đại lý.

  AC-17             Khi tạo phiên kiểm kê, hệ thống ghi stocktake_started_at và snapshot tồn hệ thống. Khi review variance, Tester phải thấy baseline snapshot, tồn thực đếm, chênh lệch và danh sách movement phát sinh trong phiên nếu có.

  AC-18             Khi SKU linh kiện có is_serial_tracked = true, nhập/xuất/kiểm kê bắt buộc scan serial; khi is_serial_tracked = false, hệ thống quản lý theo quantity trong box và không yêu cầu serial.

  AC-19             Khi người dùng mở App PV, hệ thống chỉ hiển thị sản phẩm active và published_to_app = true.

  AC-20             Khi người dùng tìm theo tên/SKU/model/từ khóa có dấu hoặc không dấu, hệ thống trả về đúng sản phẩm phù hợp và giữ điều kiện lọc.

  AC-21             Khi người dùng lọc theo ngành nghề, hệ thống hiển thị sản phẩm được mapping với ngành nghề đó; một sản phẩm có thể thuộc nhiều ngành.

  AC-22             Khi người dùng xem chi tiết sản phẩm, hệ thống hiển thị thông số kỹ thuật, hình ảnh, bảo hành, phụ kiện tương thích, ngành nghề phù hợp, available_qty và tình trạng có hàng.

  AC-23             Khi App PV hiển thị tồn kho, dữ liệu phải lấy từ tồn khả dụng đã post của kho tổng và không hiển thị serial/container chi tiết.

  AC-24             Khi người dùng chọn ngành gỗ/kim loại/công trường/sửa chữa ô tô/dụng cụ vườn & không dây, hệ thống gợi ý nhóm sản phẩm phù hợp theo rule cấu hình.

  AC-25             App PV không cho đặt mua, giữ hàng, thanh toán, tạo phiếu xuất hoặc làm thay đổi tồn kho trong MVP.

  AC-26             Khi tạo/xử lý phiếu nhập hoặc phiếu xuất, hệ thống không hiển thị/không cho gọi action gửi duyệt, duyệt, từ chối duyệt; trạng thái hợp lệ là DRAFT/SCANNING/PARTIALLY_POSTED/POSTED/CLOSED/CANCELLED.

  AC-27             Khi người dùng click Post Issue nhiều lần hoặc API bị retry với cùng Idempotency-Key, hệ thống chỉ trừ tồn một lần, chỉ chuyển item sang ISSUED một lần và chỉ sinh một movement ISSUE_POSTED.

  AC-28             Khi QL kho gọi Apply Adjustment kiểm kê nhiều lần với cùng Idempotency-Key, hệ thống chỉ cập nhật tồn một lần và chỉ sinh movement ADJUST_IN/ADJUST_OUT một lần.

  AC-29             Khi máy có mã đã xuất được tiếp nhận bảo hành, item_status chuyển ISSUED → IN_WARRANTY; khi trả khách, chuyển IN_WARRANTY → RETURNED_TO_CUSTOMER; cả 3 trạng thái không được tính vào Available Stock.

  AC-30             Khi người dùng mở danh sách hoặc chi tiết sản phẩm trên App PV, hệ thống hiển thị available_qty theo SKU tại kho tổng kèm trạng thái Còn hàng/Hết hàng và không hiển thị serial/container chi tiết.

  AC-SCOPE-01       Khi người dùng xem tồn kho, hệ thống chỉ hiển thị tồn kho tổng theo SKU/trạng thái; không có filter hoặc màn hình tồn đại lý/cửa hàng con trong MVP.

  AC-SCOPE-02       Khi tạo đại lý/cửa hàng con, hệ thống chỉ tạo master data nơi nhận hàng và không sinh Warehouse, Inventory_Balance, Stocktake hoặc Movement cho đại lý.

  AC-SCOPE-03       Khi Post Issue cho đại lý, hệ thống giảm tồn kho tổng đúng một lần, lưu agency_id trên phiếu xuất và không tạo tồn đại lý.

  AC-SCOPE-04       Khi xem báo cáo XNT, hệ thống tính tồn đầu kỳ/nhập/xuất/điều chỉnh/tồn cuối kỳ trên kho tổng; nếu lọc theo đại lý thì chỉ là thống kê lịch sử xuất cho nơi nhận, không phải tồn tại đại lý.

  AC-SCOPE-05       Khi App PV hiển thị tồn sản phẩm, available_qty luôn đọc từ kho tổng đã post và không hiển thị tồn đại lý, serial/item/container hoặc trạng thái giữ hàng.

  AC-31             Khi tạo SKU PRODUCT, hệ thống bắt buộc có các thành phần Product Master Data tối thiểu: brand_code, category_code, model_code, power_or_voltage nếu áp dụng, version và package_variant nếu có.

  AC-32             Khi tạo SKU, hệ thống không cho đưa import_batch_code, carton_code, item_code, item_condition hoặc sales_channel vào sku_code.

  AC-33             Khi cùng một model được nhập ở nhiều batch/container khác nhau, hệ thống vẫn giữ một SKU và quản lý khác biệt bằng Import_Batch/Inbound/Carton/Item.

  AC-34             Khi hàng cùng SKU có tình trạng DEMO/DEFECTIVE/WARRANTY_RETURN, hệ thống quản lý bằng condition/status và không tách SKU mới.

  AC-P0-DM-001      Stocktake, Warranty cơ bản và App PV Web App responsive là Must/Beta; native/offline và hardware protocol chuyên sâu thuộc P2.

  AC-P0-IDEMP-001   Post Receipt, Post Issue và Apply Adjustment bắt buộc Idempotency-Key; retry cùng key/cùng payload không thay đổi tồn lần hai.

  AC-P0-STK-001     Variance dùng expected_at_count theo snapshot + net movement đến count cutoff; ví dụ 100 - 10, actual 90 cho variance 0.

  AC-P0-STK-002     Hỗ trợ partial approval; chỉ variance APPROVED được Apply; stale session/version bị chặn.

  AC-P0-WAR-001     Item ở ISSUED hoặc RETURNED_TO_CUSTOMER được tạo warranty mới nếu không có active case; tối đa một active case/item.

  AC-P0-REV-001     Reverse Receipt/Issue tạo movement ngược append-only có lý do, quyền, audit, idempotency và link movement gốc.

  AC-P0-MIG-001     Dry-run không đổi dữ liệu; opening balance được đối soát theo SKU/item/package/warehouse và QL kho ký xác nhận.

  AC-P0-UAT-001     UAT script có precondition, steps, expected, actual, evidence, defect và sign-off; Go/No-Go do PM/PO quyết định.
  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 11.2. Test scenario gợi ý

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **TC ID**                 **Scenario**                                                               **Expected result**
  ------------------------- -------------------------------------------------------------------------- ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  TC-SKU-01                 Tạo SKU sản phẩm hợp lệ.                                                   Tạo thành công, sku_type=PRODUCT, status=ACTIVE.

  TC-SKU-02                 Tạo SKU trùng mã.                                                          Bị chặn và báo sku_code đã tồn tại.

  TC-IN-01                  Nhập một thùng có 20 máy cùng SKU.                                         Tạo carton, tạo 20 item, tồn SKU tăng 20.

  TC-IN-02                  Scan một máy khác SKU vào thùng đang chứa SKU A.                           Bị chặn hoặc yêu cầu tạo thùng khác.

  TC-OUT-01                 Xuất nguyên thùng còn đủ 20 máy.                                           20 item chuyển ISSUED, tồn giảm 20, carton ISSUED.

  TC-OUT-02                 Xuất lẻ 3 máy trong thùng 20 máy.                                          3 item ISSUED, tồn giảm 3, carton PARTIAL còn 17.

  TC-OUT-03                 Scan lại item đã xuất.                                                     Bị chặn, không giảm tồn lần hai.

  TC-COM-01                 Nhập box linh kiện không serial quantity 500 chưa xếp khay.                Tồn linh kiện tăng 500, box/location hiển thị Chờ xếp khay, box_status = RECEIVED_PENDING_PUTAWAY, chưa có tray_code.

  TC-COM-02                 Xuất 120 linh kiện từ box còn 500.                                         Tồn giảm 120, box còn 380.

  TC-COM-03                 Xuất 600 linh kiện từ box còn 500.                                         Bị chặn do vượt tồn.

  TC-STK-01                 Kiểm kê thiếu 1 máy so với hệ thống.                                       Sinh variance thiếu 1 item, chưa giảm tồn trước khi duyệt.

  TC-STK-02                 Duyệt điều chỉnh thiếu 1 máy.                                              Sinh ADJUST_OUT, item chuyển ADJUSTED_OUT/LOST theo cấu hình.

  TC-WAR-01                 Tạo case bảo hành có mã máy.                                               Case gắn item, ghi khách, SĐT, bệnh, status RECEIVED; tồn bán không tăng.

  TC-WAR-02                 Tạo case bảo hành không tìm thấy mã máy/khách không nhớ mã.                Hệ thống tạo case TEMP_ONLY, sinh temporary_item_code, item_code rỗng, sales_trace_status = NOT_TRACEABLE; không truy vấn/link máy đã bán.

  TC-RP-01                  Lọc báo cáo máy A bị bệnh B trong tháng.                                   Hiển thị số case đúng theo SKU/model và defect.

  TC-SCAN-01                Bấm Demo máy quét trả về 1 mã sản phẩm trong màn nhập.                     Hệ thống tạo/nhận item đúng như scan thật, ghi Scan_Event source=DEMO_SCANNER.

  TC-SCAN-02                Bấm Demo máy quét trả về mã thùng và 20 mã sản phẩm trong cùng batch.      Hệ thống phân loại 1 CARTON + 20 ITEM, map item vào thùng, cảnh báo nếu có mã trùng/lạ.

  TC-PUT-01                 Post nhập 100 linh kiện vào box chưa xếp khay.                             Tồn SKU tăng 100, box/location hiển thị Chờ xếp khay, chưa có tray_code.

  TC-PTW-02                 Putaway/Xếp khay box linh kiện vào TRAY01 sau Post Receipt.                tray_code cập nhật TRAY01, location_hint cập nhật theo khay, tồn SKU không đổi, không sinh movement tăng/giảm tồn.

  TC-COUNT-LOCK-01          Tạo phiên kiểm kê và thử tạo phiếu nhập/xuất khác.                         Hệ thống không cần tự động khóa; vẫn theo quyền bình thường. Quy trình vận hành ngoài hệ thống chịu trách nhiệm tạm dừng xuất/đưa hàng mới vào kho.

  TC-WAR-NOCODE-01          Tạo case bảo hành khi chip/mã máy bị mất.                                  Cho phép lưu case với item_code rỗng, temporary_item_code được sinh tự động, bắt buộc missing_code_reason, manual_product_description, tên/SĐT khách và reported_defect.

  TC-AGY-01                 Tạo đại lý và chọn đại lý trên phiếu xuất.                                 Đại lý được lưu làm nơi nhận trên phiếu xuất; hệ thống không tạo tồn kho đại lý.

  TC-WAR-NOCODE-02          Kiểm tra case TEMP_ONLY không truy vết máy đã bán.                         Case không gắn Item_Instance, không hiển thị lịch sử bán hàng theo máy, không cập nhật item_status và không làm tăng/giảm tồn bán.

  TC-PV-01                  Mở danh sách App PV với dữ liệu có sản phẩm published và chưa published.   Chỉ sản phẩm active + published_to_app = true được hiển thị.

  TC-PV-02                  Tìm kiếm sản phẩm bằng tên, SKU, model và từ khóa không dấu.               Kết quả trả về đúng sản phẩm phù hợp; không hiển thị sản phẩm inactive/chưa publish.

  TC-PV-03                  Lọc Ngành gỗ.                                                              Hiển thị máy cưa tròn không dây, cưa lọng, máy chà nhám, máy phay hoặc sản phẩm được mapping ngành gỗ.

  TC-PV-04                  Lọc Ngành kim loại.                                                        Hiển thị máy mài góc, máy cắt, máy siết bu lông hoặc sản phẩm được mapping ngành kim loại.

  TC-PV-05                  Lọc Công trường xây dựng.                                                  Hiển thị máy khoan búa, máy phá bê tông, máy cắt đá hoặc sản phẩm được mapping công trường.

  TC-PV-06                  Lọc Sửa chữa ô tô.                                                         Hiển thị máy mài khuôn, cờ lê bánh cóc, máy đánh bóng hoặc sản phẩm được mapping sửa chữa ô tô.

  TC-PV-07                  Lọc Dụng cụ vườn & không dây / dòng pin 20V và 12V MAX.                    Hiển thị nhóm máy không dây, máy vườn, pin/sạc hoặc sản phẩm được mapping tương ứng.

  TC-PV-08                  Xem chi tiết sản phẩm.                                                     Hiển thị ảnh, thông số kỹ thuật, bảo hành, phụ kiện tương thích, ngành nghề phù hợp, available_qty và tình trạng có hàng.

  TC-PV-09                  Kiểm tra App PV không tác động tồn.                                        Không có phiếu xuất, không có Stock Movement, không reserve/giữ hàng khi người dùng xem/lọc/tìm kiếm sản phẩm.

  TC-PV-10                  Sản phẩm hết tồn nhưng vẫn published.                                      Hiển thị trạng thái Hết hàng; không cho đặt mua/giữ hàng trong MVP.

  TC-INOUT-STATE-01         Kiểm tra phiếu nhập/xuất không có duyệt/chờ duyệt.                         Không có button Gửi duyệt/Duyệt/Từ chối duyệt; API Submit/Approve/Reject cho nhập/xuất không thuộc scope; phiếu chỉ đi DRAFT/SCANNING/PARTIALLY_POSTED/POSTED/CLOSED/CANCELLED.

  TC-OUT-IDEMP-01           Click/Gọi Post Issue 2 lần cùng Idempotency-Key.                           Lần đầu xuất thành công; lần lặp trả kết quả đã xử lý, tồn chỉ giảm một lần, item chỉ chuyển ISSUED một lần, chỉ có một ISSUE_POSTED movement.

  TC-COUNT-IDEMP-01         Apply Adjustment kiểm kê 2 lần cùng Idempotency-Key.                       Adjustment chỉ áp dụng một lần, chỉ sinh một ADJUST_IN/ADJUST_OUT movement, tồn không bị tăng/giảm lặp.

  TC-WAR-03                 Máy đã ISSUED được tiếp nhận bảo hành rồi trả khách.                       Khi tiếp nhận: item_status = IN_WARRANTY; khi trả: item_status = RETURNED_TO_CUSTOMER; Available Stock không tăng ở cả hai bước.

  TC-PV-11                  Mở App PV danh sách và chi tiết sản phẩm còn tồn.                          Product card và chi tiết hiển thị available_qty từ kho tổng đã post, kèm trạng thái còn hàng; không hiển thị serial/container.

  TC-SCOPE-001              Xem tồn kho sau khi có dữ liệu kho tổng và danh mục đại lý.                Chỉ hiển thị tồn kho tổng theo SKU/trạng thái; không có tồn theo đại lý/cửa hàng con.

  TC-SCOPE-002              Tạo đại lý/cửa hàng con mới.                                               Đại lý được tạo làm master data nơi nhận hàng; hệ thống không sinh Warehouse, Inventory_Balance, Stocktake hoặc Movement cho đại lý.

  TC-SCOPE-003              Tạo phiếu xuất từ kho tổng cho đại lý và Post Issue.                       Phiếu xuất lưu agency_id/nơi nhận; available_qty kho tổng giảm; sinh ISSUE_POSTED tại kho tổng; không tạo tồn đại lý.

  TC-SCOPE-004              Gọi lại Post Issue cùng Idempotency-Key cho phiếu xuất đại lý.             Trả lại kết quả cũ, không giảm tồn lần hai và không sinh movement trùng.

  TC-SCOPE-005              Mở báo cáo XNT và lọc theo đại lý nếu có filter nơi nhận.                  Báo cáo thể hiện lịch sử xuất cho đại lý/nơi nhận, không coi đại lý là kho và không hiển thị tồn cuối kỳ tại đại lý.

  TC-SCOPE-006              App PV xem sản phẩm có tồn khả dụng.                                       App hiển thị available_qty từ kho tổng và trạng thái Còn hàng/Hết hàng; không hiển thị tồn đại lý hoặc chi tiết serial/container.

  TC-SKU-03                 Tạo SKU PRODUCT theo format DCH-DRILL-DJZ10A-220V-V1.                      Tạo thành công; hệ thống lưu brand_code=DCH, category_code=DRILL, model_code=DJZ10A, power_or_voltage=220V, version=V1.

  TC-SKU-04                 Tạo SKU có batch trong mã, ví dụ DCH-DRILL-DJZ10A-220V-V1-2026Q1.          Bị cảnh báo/chặn theo rule nếu 2026Q1 là batch nhập khẩu; yêu cầu nhập batch ở import_batch_code.

  TC-SKU-05                 Nhập cùng SKU ở hai batch khác nhau.                                       Tồn vẫn cộng về cùng SKU; hệ thống trace được import_batch_code riêng trên item/carton/inbound.

  TC-SKU-06                 Đánh dấu một item là DEMO hoặc DEFECTIVE.                                  Không tạo SKU mới; item_condition/status thay đổi và Available/Non-sellable được tính theo rule tồn.

  TC-P0-IDEMP-RECEIPT-001   Retry Post Receipt cùng Idempotency-Key/cùng payload.                      Trả response cũ; không tăng balance/movement lần hai.

  TC-P0-STK-CUTOFF-001      Snapshot 100; issue -10 sau snapshot; count 90.                            expected_at_count=90; variance_to_apply=0; không sinh adjustment.

  TC-P0-STK-PARTIAL-001     Phiên có 3 variance, duyệt 2 và waive 1.                                   Chỉ 2 dòng sinh movement; session APPLIED khi dòng waive có reason hợp lệ.

  TC-P0-STK-STALE-001       Hai QL mở cùng màn review; người A Apply trước.                            Người B dùng version cũ bị chặn; không sinh duplicate adjustment.

  TC-P0-WAR-REPEAT-001      Item RETURNED_TO_CUSTOMER mở lần bảo hành thứ hai.                         Tạo case mới và chuyển IN_WARRANTY nếu không có active case.

  TC-P0-WAR-ACTIVE-001      Hai request đồng thời tạo warranty cho cùng item.                          Một request thành công; request còn lại 409; chỉ một active case.

  TC-P0-REV-RECEIPT-001     Reverse Receipt đã Post.                                                   Sinh RECEIPT_REVERSED, giảm đúng tồn, movement gốc giữ nguyên.

  TC-P0-REV-ISSUE-001       Reverse Issue sau khi hàng quay lại kho.                                   Sinh ISSUE_REVERSED, item IN_STOCK nếu condition hợp lệ; retry không cộng lần hai.
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 12. Open points cần khách xác nhận

Các điểm dưới đây được phân loại theo mức ảnh hưởng để PM/DEV/TEST biết điểm nào chặn estimate/API, điểm nào chỉ ảnh hưởng UI hoặc Phase 2. Không gom tất cả vào “cần xác nhận” vì dễ làm đội triển khai hiểu nhầm là toàn bộ đều blocker.

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Mã / phân loại**      **Điểm mở / quyết định cần trace**                                                                   **Status / Owner**               **Hướng xử lý cho Dev/Test**
  ----------------------- ---------------------------------------------------------------------------------------------------- -------------------------------- ---------------------------------------------------------------------------------------------------------------------
  OP-P1-001\              Customer sign-off cho 1 kho tổng, không tồn đại lý, App PV không đặt hàng, không chi phí bảo hành.   Resolved / BA                    Trace DEC-WM-001..009 vào Jira/AC/TC. Không block DEV/TEST.
  RESOLVED                                                                                                                                                      

  OP-P1-002\              Quy chuẩn format code_value: prefix, độ dài, checksum, sinh mã hay dùng mã nhà sản xuất.             Open / BA + Tech Lead            Nếu chưa chốt, dùng VARCHAR(100), unique toàn hệ thống; format chi tiết chốt trước API freeze.
  DEV_CLARIFICATION                                                                                                                                             

  OP-P1-003\              Model thiết bị scan thật, protocol RFID/NFC/barcode gateway, driver, cơ chế đẩy dữ liệu.             Open / PM + Tech Lead + Vendor   Không block MVP. MVP dùng Demo scanner/manual input và endpoint nhận list code_value.
  PHASE_LATER_ESTIMATE                                                                                                                                          

  OP-P1-004\              Carrier nào dùng cho từng đối tượng: QR, barcode, RFID/NFC, nhãn thường hay mã temp.                 Partially resolved / BA          Thiết kế carrier_type optional/required theo Physical_Code; default có thể là QR/BARCODE hoặc MANUAL_CODE.
  DEV_CLARIFICATION                                                                                                                                             

  OP-P1-005\              App PV hiển thị available_qty có nhạy cảm kinh doanh không?                                          Resolved / Customer + Partner    Đối tác đã chốt cho đại lý/khách xem số lượng. Test chỉ cần kiểm soát không lộ serial/item/container/movement/cost.
  RESOLVED                                                                                                                                                      

  OP-P1-006\              Có cần đăng nhập/role riêng cho đại lý khác khách thường trên App PV không?                          Open / PM + Customer             MVP dùng cùng dữ liệu published. Role đại lý, tồn riêng theo đại lý hoặc giá riêng đưa Phase 2.
  UI_ONLY / PHASE_LATER                                                                                                                                         

  OP-P1-007\              Độ trễ đồng bộ available_qty lên App PV: realtime hay cache theo lịch?                               Open / BA + Tech Lead            Không block core WM. Nên có last_inventory_sync_at/last_updated nếu dùng cache.
  UI/API CLARIFICATION                                                                                                                                          

  OP-P1-008\              Quản lý chi phí bảo hành, người sửa, linh kiện thay thế, SLA.                                        Resolved / Customer + BA         Không estimate trong MVP; nếu phát sinh tạo CR/Phase 2.
  RESOLVED OUT-OF-SCOPE                                                                                                                                         

  OP-P1-009\              Danh sách ngành nghề, tag, mapping sản phẩm gợi ý ai quản trị?                                       Open / BA + Admin nghiệp vụ      Không block WM/API. Admin/QL kho có màn cấu hình mapping; danh sách có thể cập nhật sau.
  UI_ONLY                                                                                                                                                       

  OP-P1-010\              Có cần in tem mã từ hệ thống trong MVP không?                                                        Open / PM + Customer             Không block nếu khách dùng mã/tem có sẵn; nếu cần in tem thì tách ticket riêng.
  SHOULD/FUTURE                                                                                                                                                 
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 13. Phụ lục thuật ngữ và công thức tồn kho

### 13.1. Thuật ngữ

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Thuật ngữ**              **Định nghĩa**
  -------------------------- ------------------------------------------------------------------------------------------------------------------------------------------------------
  SKU                        Mã loại hàng cần quản lý tồn kho, có thể là sản phẩm hoặc linh kiện.

  Item/Serial                Một sản phẩm hoặc linh kiện cụ thể có mã riêng.

  Carton/Thùng               Container chứa nhiều sản phẩm cùng SKU. Có mã để scan/xuất nguyên thùng.

  Box/Hộp                    Container chứa nhiều linh kiện cùng SKU. Có thể chứa serial hoặc chỉ quantity.

  Tray/Khay                  Locator chứa nhiều box linh kiện, dùng để tìm kiếm trong kho, không xuất trực tiếp.

  Stock balance              Tồn hiện tại theo SKU trong kho tổng.

  Movement                   Dòng lịch sử làm thay đổi tồn: nhập, xuất, điều chỉnh.

  Warranty case              Phiếu bảo hành/sửa chữa cơ bản cho máy khách gửi về phòng bảo hành.

  Chip/mã vật lý             Mã dùng để scan nhận diện item/container/locator. MVP quản lý dữ liệu chip/mã; phần cứng scan thật có thể tích hợp sau.

  Scan batch                 Một lần scan phạm vi rộng hoặc Demo máy quét trả về nhiều mã/chip.

  Demo máy quét              Giao diện giả lập máy quét: bấm nút để hệ thống tạo danh sách mã/chip như thiết bị scan thật.

  Hộp đựng 1 sản phẩm        Vỏ đựng đơn chiếc của sản phẩm; dùng cùng mã với sản phẩm, không phải container riêng.

  Putaway/Xếp khay           Bước sau nhập linh kiện để gán box/linh kiện vào khay thực tế; không làm tăng/giảm tồn.

  Đại lý                     Đối tượng nhận hàng khi xuất kho; không phải kho và không có tồn kho trên hệ thống.

  App PV                     App xem sản phẩm dùng cho chủ đại lý/cửa hàng hoặc khách hàng tra cứu sản phẩm, thông số, phụ kiện, tồn khả dụng tổng quan và gợi ý theo ngành nghề.

  Ngành nghề                 Nhóm nhu cầu sử dụng sản phẩm như Ngành gỗ, Ngành kim loại, Công trường xây dựng, Sửa chữa ô tô, Dụng cụ vườn & không dây.

  Gợi ý theo ngành nghề      Danh sách sản phẩm được đề xuất theo rule cấu hình dựa trên ngành nghề, category/tag, priority và tình trạng có hàng.

  Tồn hiển thị trên App PV   Tồn khả dụng tổng quan theo SKU tại kho tổng; chỉ đọc từ WM, không phải tồn của đại lý/cửa hàng con và không hiển thị serial/container chi tiết.
  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 13.2. Công thức tồn kho khuyến nghị

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Loại tồn**                **Công thức/cách tính**
  --------------------------- -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  Sản phẩm serial-tracked     Available quantity = count(Item_Instance where sku_id = X and item_status = IN_STOCK). Các trạng thái ISSUED, IN_WARRANTY, RETURNED_TO_CUSTOMER, LOST, ADJUSTED_OUT không tính Available.

  Carton remaining quantity   Số item còn IN_STOCK trong carton. Không cộng thêm carton như một đơn vị tồn riêng.

  Linh kiện serial-tracked    Available quantity = count(Component Item where sku_id = X and status = IN_STOCK), chỉ áp dụng khi is_serial_tracked = true.

  Linh kiện quantity-only     Available quantity = sum(Box quantity remaining where sku_id = X and box_status in RECEIVED_PENDING_PUTAWAY, STORED, PARTIAL), áp dụng khi is_serial_tracked = false. Linh kiện đã Post Receipt nhưng đang Chờ xếp khay vẫn được tính Available.

  Tồn cuối kỳ                 Tồn đầu kỳ + nhập trong kỳ - xuất trong kỳ + adjustment tăng - adjustment giảm.

  Bảo hành                    Warranty không cộng vào tồn bán. Chu kỳ lặp: ISSUED hoặc RETURNED_TO_CUSTOMER → IN_WARRANTY → RETURNED_TO_CUSTOMER; các trạng thái này không tính Available. Tối đa một active case/item.

  Linh kiện chờ xếp khay      Available quantity vẫn tính vào tồn sau Post Receipt. Location hint = Chờ xếp khay; chưa có tray_code cho đến khi putaway.

  Putaway/Xếp khay            Không thay đổi Available quantity. Chỉ cập nhật mapping Box/Tray hoặc location_hint.

  Hộp đựng 1 sản phẩm         Không có quantity riêng. Scan mã trên hộp hay sản phẩm đều tính cùng một item, tránh double count.

  Snapshot kiểm kê            stocktake_snapshot là ảnh chụp tại stocktake_started_at. expected_at_count = snapshot_qty + net_movement_after_snapshot_to_count_cutoff. variance_to_apply = actual_qty - expected_at_count. Movement sau cutoff đến Apply phải hiển thị/ack và có thể yêu cầu recount.
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 13.3. Kết luận chốt nghiệp vụ

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  Kết luận: Với Hoa Nam, mô hình SKU nên chốt theo Product Master Data. SKU là mã loại hàng dùng chung cho sản phẩm và linh kiện, được chuẩn hóa theo brand, category, model, voltage/power, package/kit nếu có và version. Mỗi máy cụ thể quản lý bằng Item/Serial; thùng, hộp và khay là Container/Locator Code; batch nhập khẩu/container nhập khẩu là Import_Batch; hàng demo/lỗi/bảo hành là item_condition/item_status; đại lý/công trình/bán lẻ là sales_channel/nơi nhận trên phiếu xuất. Không đưa batch, mã item, container, condition hoặc channel vào sku_code để tránh phình SKU và sai báo cáo tồn. Hệ thống chỉ quản lý kho tổng, không quản lý tồn đại lý/cửa hàng con trong MVP.
  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 14. Phụ lục bổ sung

Phụ lục này gom các thay đổi nghiệp vụ mới để Dev/Test dễ trace khi triển khai. Nội dung trong phụ lục có hiệu lực thay thế các mô tả cũ nếu có mâu thuẫn về chip, scan, kiểm kê, đại lý, putaway và bảo hành không có mã.

### 14.1. Business rules bổ sung

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Mã rule**            **Nội dung rule**                                                                                                          **Expected result/validation**
  ---------------------- -------------------------------------------------------------------------------------------------------------------------- ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  BR-SCAN-ADD-01         Luồng nghiệp vụ dùng thống nhất thuật ngữ scan.                                                                            Nhập tay và Demo máy quét là cách nhập dữ liệu scan, không tạo flow nghiệp vụ riêng.

  BR-SCAN-ADD-02         Demo máy quét phải có ở các màn nhập, xuất, kiểm kê, tra cứu và bảo hành nếu cần scan.                                     Bấm nút demo trả về một hoặc nhiều mã/chip; dữ liệu được ghi Scan_Event source = DEMO_SCANNER.

  BR-SCAN-ADD-03         Một lần scan phạm vi rộng có thể trả về nhiều mã/chip.                                                                     Hệ thống tạo scan_batch, xử lý từng mã, cảnh báo mã trùng, mã lạ, mã sai trạng thái.

  BR-SCAN-ADD-04         Hệ thống phải phân biệt mã thùng với mã sản phẩm và các mã vật lý khác.                                                    Mỗi mã có code_type: ITEM, CARTON, COMPONENT_SERIAL, BOX, TRAY, UNKNOWN.

  BR-CARRIER-01          Physical_Code là carrier-agnostic. ITEM bắt buộc dùng chip; CARTON/BOX/TRAY cấu hình carrier linh hoạt.                    carrier_type hỗ trợ QR/BARCODE/RFID/NFC/MANUAL. Hardware protocol không thuộc MVP; mọi carrier map về một code_value duy nhất.

  BR-CON-09              Hộp đựng 1 sản phẩm chỉ là vỏ đựng.                                                                                        Mã trên hộp và trên sản phẩm là cùng item_code/chip_code; không tạo container riêng, không double count tồn.

  BR-COUNT-ADD-01        Kiểm kê không cần khóa nhập/xuất bằng hệ thống.                                                                            Hệ thống vẫn cho phép nghiệp vụ theo quyền; quy trình thực tế tạm dừng xuất và giữ hàng nhập mới ngoài khu kiểm kê. Khi tạo phiên phải ghi stocktake_started_at và snapshot tồn.

  BR-AGY-ADD-01          Đại lý/cửa hàng con là danh mục nơi nhận hàng.                                                                             Cho phép tạo đại lý, chọn agency_id trên phiếu xuất; không tạo tồn kho đại lý/cửa hàng con.

  BR-PTW-ADD-01          Post nhập linh kiện làm tăng tồn ngay.                                                                                     Nếu chưa xếp khay, location_hint = Chờ xếp khay/RECEIVED_PENDING_PUTAWAY.

  BR-PTW-ADD-02          Putaway/Xếp khay chỉ cập nhật vị trí.                                                                                      Không sinh movement tăng/giảm tồn; chỉ cập nhật tray_code hoặc container mapping.

  BR-WAR-ADD-01          Bảo hành được tạo case TEMP_ONLY khi mất/hỏng chip, không tìm thấy mã hoặc khách không nhớ mã máy.                         item_code optional; hệ thống sinh temporary_item_code, identification_status = TEMP_ONLY, sales_trace_status = NOT_TRACEABLE; bắt buộc customer_name, customer_phone, reported_defect, missing_code_reason và mô tả nhận diện máy.

  BR-COUNT-ADD-02        Kiểm kê phải có snapshot tồn tại thời điểm bắt đầu.                                                                        Tạo Stocktake_Snapshot theo phạm vi kiểm kê; variance được tính từ snapshot_qty, không tính từ tồn hiện tại đã thay đổi sau đó.

  BR-COUNT-ADD-03        Có movement phát sinh trong phiên kiểm kê phải cảnh báo trước khi áp dụng adjustment.                                      Hiển thị “Có biến động trong phiên kiểm kê” và danh sách movement phát sinh sau stocktake_started_at để QL kho xem trước khi áp dụng adjustment.

  BR-COMP-ADD-01         MVP hỗ trợ linh kiện serial-tracked và quantity-only.                                                                      true: scan serial. false: quản lý quantity theo box. Không bắt buộc mọi LK có serial.

  BR-INOUT-ADD-01        Phiếu nhập/xuất không có duyệt/chờ duyệt; chỉ Adjustment kiểm kê cần QL kho duyệt.                                         Chỉ Post Receipt/Post Issue/Adjustment kiểm kê ở trạng thái APPLIED mới cập nhật tồn và Movement. DRAFT/SCANNING của nhập/xuất không cập nhật tồn.

  BR-OUT-ADD-01          Post Issue phải idempotent.                                                                                                Gọi Post Issue lặp với cùng Idempotency-Key không trừ tồn thêm, không đổi item_status thêm lần nữa, không sinh duplicate movement.

  BR-COUNT-ADD-04        Apply Adjustment kiểm kê phải idempotent.                                                                                  Gọi Apply Adjustment lặp với cùng Idempotency-Key trả kết quả cũ, không tạo thêm ADJUST_IN/ADJUST_OUT và không cập nhật tồn lần hai.

  BR-WAR-ADD-02          Item bảo hành có mã máy có luồng ISSUED → IN_WARRANTY → RETURNED_TO_CUSTOMER.                                              Các trạng thái ISSUED, IN_WARRANTY, RETURNED_TO_CUSTOMER đều không tính Available Stock; chỉ IN_STOCK mới tính Available.

  BR-PV-ADD-01           App PV hiển thị available_qty.                                                                                             Product card và chi tiết sản phẩm hiển thị số lượng tồn khả dụng theo SKU tại kho tổng, không hiển thị serial/container chi tiết.

  BR-P1-SIGNOFF-01       Các quyết định scope P1 phải có Decision Log và trạng thái Confirmed/Resolved trước khi DEV/TEST triển khai sâu.           Nếu một điểm đã được DEC-WM chốt thì không đưa lại vào blocker estimate; chỉ cần trace vào Jira/AC/TC.

  BR-P1-CODE-01          Tách rõ dữ liệu mã vật lý, carrier QR/Barcode/RFID, demo scanner và hardware integration thật.                             DEV không thiết kế theo cách coi QR/RFID là SKU/container riêng; TEST có case scan source và code_type.

  BR-P1-OPEN-01          Open points phải được phân loại theo ảnh hưởng: BLOCKER_ESTIMATE, DEV_CLARIFICATION, UI_ONLY, PHASE_LATER hoặc RESOLVED.   PM nhìn được điểm nào chặn estimate/API, điểm nào chỉ ảnh hưởng UI/phase sau.

  BR-P1-PV-01            available_qty trên App PV đã được đối tác chốt là thông tin được phép hiển thị cho đại lý/khách hàng.                      Không cần che số lượng theo sensitivity trong MVP; chỉ che dữ liệu chi tiết nội bộ.
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 14.2. Luồng Putaway/Xếp khay linh kiện sau nhập

1\. Nhân viên tạo phiếu nhập linh kiện, scan/nhập box hoặc serial/quantity.

2\. Khi Post Receipt, hệ thống tăng tồn theo SKU linh kiện và ghi box/linh kiện ở trạng thái RECEIVED_PENDING_PUTAWAY nếu chưa có khay.

3\. Nhân viên thực tế mang box linh kiện vào khay.

4\. Nhân viên mở màn Putaway/Xếp khay, scan mã khay và scan mã box/linh kiện.

5\. Với linh kiện không serial, nhân viên nhập hoặc xác nhận số lượng trong box.

6\. Hệ thống cập nhật box/linh kiện vào tray tương ứng, trạng thái STORED.

7\. Tồn kho không đổi; không sinh movement tăng/giảm tồn.

### 14.3. Luồng bảo hành khi mất/hỏng chip hoặc không tìm thấy mã

1\. Nhân viên bảo hành scan/nhập mã máy nếu khách còn mã/chip.

2\. Nếu không có mã, chip bị hỏng/rơi mất, mã không tìm thấy trên hệ thống hoặc khách không nhớ mã máy, nhân viên chọn “Tạo case không có mã/TEMP_ONLY”.

3\. Hệ thống bắt buộc nhập tên/SĐT người gửi, bệnh/lỗi khách báo, missing_code_reason và manual_product_description. SKU/model nhập nếu biết; nếu không biết chọn “Chưa xác định/UNKNOWN_MODEL”.

4\. Hệ thống sinh temporary_item_code/mã temp để dán hoặc ghi trên máy, đồng thời sinh warranty_case_code cho phiếu bảo hành. temporary_item_code dùng thay item_code trong quá trình xử lý nội bộ.

5\. Case TEMP_ONLY không gắn item_code, không liên kết Item_Instance và không truy vết về máy đã bán. Khi trả máy, nhân viên đối chiếu temporary_item_code, thông tin khách/SĐT và mô tả/ảnh tiếp nhận.

6\. Không được tự động hoặc suy đoán link case TEMP_ONLY với một máy đã bán chỉ dựa trên model/mô tả/khách nhớ không chắc chắn. Nếu không có định danh gốc, hệ thống giữ sales_trace_status = NOT_TRACEABLE.

### 14.4. Data fields bổ sung khuyến nghị

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Entity**               **Field**                                                                          **Bắt buộc?**                                     **Ghi chú**
  ------------------------ ---------------------------------------------------------------------------------- ------------------------------------------------- --------------------------------------------------------------------------------------------------------------------------------------
  Physical_Code            code_value                                                                         Có                                                Giá trị mã/chip duy nhất trong hệ thống.

  Physical_Code            code_type                                                                          Có                                                ITEM/CARTON/COMPONENT_SERIAL/BOX/TRAY/UNKNOWN.

  Scan_Batch               scan_source                                                                        Có                                                REAL_SCANNER/DEMO_SCANNER/MANUAL_INPUT.

  Scan_Event               scan_batch_id                                                                      Có nếu scan batch                                 Liên kết nhiều mã trong một lần scan.

  Agency                   agency_code, agency_name, phone, address, status                                   Có                                                Dùng làm nơi nhận hàng trên phiếu xuất.

  Putaway_Task             tray_code, box_code, sku_id, quantity, confirmed_by, confirmed_at                  Có                                                Cập nhật vị trí khay cho linh kiện sau nhập.

  Warranty_Case            missing_code_reason                                                                Có nếu mất mã                                     Lý do mất/hỏng/không tìm thấy chip/mã hoặc khách không nhớ mã máy.

  Warranty_Case            manual_product_description                                                         Có nếu mất mã                                     Mô tả nhận diện máy thay cho item_code: model/màu/ngoại hình/phụ kiện/dấu hiệu nhận biết.

  Warranty_Case            temporary_item_code                                                                Có nếu mất mã                                     Mã temp do hệ thống sinh để dán/ghi trên máy và quản lý nội bộ trong quá trình sửa/trả máy.

  Warranty_Case            identification_status                                                              Có                                                IDENTIFIED/TEMP_ONLY.

  Warranty_Case            sales_trace_status                                                                 Có nếu mất mã                                     NOT_TRACEABLE; thể hiện không truy được máy đã bán và không link lịch sử bán hàng.

  Warranty_Case            received_images                                                                    Nên có nếu mất mã                                 Ảnh tiếp nhận giúp nhận diện khi xử lý/trả máy.

  Stocktake_Session        stocktake_started_at                                                               Có                                                Thời điểm bắt đầu phiên kiểm kê và thời điểm chụp snapshot tồn hệ thống.

  Stocktake_Snapshot       snapshot_qty, system_status, item_code/container_code                              Có                                                Baseline tồn hệ thống tại stocktake_started_at để so sánh với thực đếm.

  Stocktake_Session        movement_warning_flag                                                              Có                                                true nếu có Inventory_Movement phát sinh sau stocktake_started_at và trước khi phiên kiểm kê chuyển APPLIED/CANCELLED.

  PV_Product_Profile       sku_id, display_name, short_description, detail_description                        Có                                                Thông tin hiển thị trên App PV, tham chiếu SKU Master.

  PV_Product_Profile       published_to_app, app_status, sort_order, featured_flag                            Có                                                Kiểm soát sản phẩm có hiển thị trên App PV và thứ tự hiển thị.

  PV_Product_Profile       image_urls/specification/warranty_summary                                          Nên có                                            Dữ liệu chi tiết sản phẩm cho App PV.

  PV_Industry              industry_code, industry_name, status, sort_order                                   Có                                                Danh mục ngành nghề: WOOD, METAL, CONSTRUCTION, AUTO_REPAIR, GARDEN_CORDLESS.

  PV_Product_Industry      sku_id, industry_id, priority, reason_text                                         Có                                                Mapping sản phẩm phù hợp ngành nghề và lý do gợi ý.

  PV_Filter_Tag            tag_code, tag_name, tag_group, status                                              Có                                                Tag lọc: 20V MAX, 12V MAX, không dây, máy cưa, máy mài, phụ kiện tương thích...

  PV_Recommendation_Rule   industry_id, category_id/tag_id, sku_id, priority, active_flag                     Có                                                Rule gợi ý sản phẩm theo ngành nghề trong MVP.

  PV_View_Event            sku_id, keyword, industry_id, event_type, created_at                               Should                                            Ghi nhận lượt xem/tìm kiếm cơ bản nếu triển khai analytics App PV.

  Item_Instance            item_status                                                                        Có                                                Trạng thái item: NEW/IN_STOCK/ISSUED/IN_WARRANTY/RETURNED_TO_CUSTOMER/LOST/ADJUSTED_OUT. Chỉ IN_STOCK được tính vào Available Stock.

  Idempotency_Key          idempotency_key, operation, document_id, request_hash, response_snapshot, status   Có với Post Receipt/Post Issue/Apply Adjustment   Chống xử lý trùng khi click/retry API. Cùng key + cùng operation trả lại kết quả cũ, không cập nhật tồn/movement lần hai.

  PV_Product_Profile       available_qty_display                                                              Có trên App PV                                    Giá trị đọc từ Inventory_Balance.available_qty của kho tổng đã post, dùng để hiển thị số lượng tồn trên product card và chi tiết.

  Physical_Code            carrier_type                                                                       Có                                                QR/BARCODE/RFID/NFC/PLAIN_LABEL/MANUAL_CODE/TEMP_CODE. Phân biệt carrier đọc/in mã với code_value nghiệp vụ.

  Physical_Code            hardware_uid                                                                       Không                                             UID kỹ thuật của chip/thẻ nếu thiết bị trả về khác code_value. Không dùng post nghiệp vụ nếu chưa map với Physical_Code.

  Physical_Code            encoded_payload_version                                                            Không                                             Version cấu trúc payload khi encode QR/Barcode/RFID; giúp mở rộng nếu sau này nhúng thêm dữ liệu ngoài code_value.

  Scan_Batch               scanner_device_id                                                                  Không trong MVP                                   ID thiết bị thật nếu có tích hợp Phase 2; null với DEMO_SCANNER/MANUAL_INPUT.

  Scan_Event               raw_payload                                                                        Không                                             Dữ liệu raw từ thiết bị scan để debug/tích hợp phần cứng; không thay thế code_value đã chuẩn hóa.

  PV_Product_Profile       show_available_qty                                                                 Có                                                MVP mặc định true theo DEC-WM-006; cho phép hiển thị số lượng tồn khả dụng trên App PV.

  PV_Product_Profile       last_inventory_sync_at                                                             Nên có nếu dùng cache                             Thời điểm tồn kho hiển thị trên App PV được cập nhật gần nhất.

  SKU                      brand_code, category_code, model_code                                              Có với PRODUCT                                    Các thành phần định danh chính của sku_code và Product Master Data.

  SKU                      power_or_voltage                                                                   Có nếu phân biệt sản phẩm                         Ví dụ 20V, 12V, 220V, 850W; dùng để tránh nhầm biến thể.

  SKU                      package_variant / kit_type                                                         Có nếu có nhiều cấu hình bán                      BARE/KIT/SET/BODY_ONLY; phân biệt sản phẩm thân máy trần và bộ có phụ kiện.

  SKU                      version                                                                            Có                                                V1/V2/GEN2; mặc định V1 nếu chưa có nhiều phiên bản.

  Import_Batch             import_batch_code, shipment_container_code, supplier_batch_no, import_period       Có nếu quản lý nhập khẩu theo batch/container     Trace lô/đợt nhập khẩu mà không đưa batch vào sku_code.

  Item_Instance            item_condition                                                                     Có nếu có hàng demo/lỗi/bảo hành                  NORMAL/DEMO/DEFECTIVE/WARRANTY_RETURN/NON_SELLABLE; không tách SKU mới theo tình trạng hàng.

  Outbound_Document        sales_channel                                                                      Có nếu cần báo cáo kênh                           DEALER/PROJECT/RETAIL/INTERNAL/WARRANTY; không đưa kênh phân phối vào sku_code.
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 14.5. State transition matrix chi tiết

Mục này bổ sung ma trận trạng thái để Dev/Test trace rõ Current State → Action → Actor → Guard → Next State → Stock Effect → Movement. Nguyên tắc: DRAFT/SCANNING/COUNTING/REVIEWING chỉ là trạng thái nghiệp vụ, không cập nhật tồn cho tới khi action post/xác nhận cuối cùng được thực hiện thành công trong transaction.

Ký hiệu Stock Effect: +N = tăng available_qty theo SKU; -N = giảm available_qty theo SKU; 0 = không thay đổi tồn. Container/carton/box/tray chỉ cập nhật trạng thái/mapping, không được cộng thêm như một đơn vị tồn độc lập.

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Đối tượng**   **Current State**              **Action**                                          **Actor**                        **Guard / Điều kiện bắt buộc**                                                                                                                                       **Next State**                 **Stock Effect**                                                                                                                                              **Movement**
  --------------- ------------------------------ --------------------------------------------------- -------------------------------- -------------------------------------------------------------------------------------------------------------------------------------------------------------------- ------------------------------ ------------------------------------------------------------------------------------------------------------------------------------------------------------- ----------------------------------------------------------------------------------------------------
  Phiếu nhập      Không có phiếu                 Create receipt document                             NV kho / QL kho / Admin          Có quyền nhập kho; SKU hợp lệ; kho tổng active; quantity \> 0; NCC optional nhưng nếu chọn phải active.                                                              DRAFT                          0                                                                                                                                                             Không sinh Inventory_Movement; chỉ ghi audit CREATE_RECEIPT.

  Phiếu nhập      DRAFT                          Scan/nhập mã thùng, item, box, linh kiện            NV kho                           Mã scan hợp lệ hoặc được phép tạo mới; item cùng carton phải cùng SKU; line qty không âm; duplicate code trong cùng phiếu bị cảnh báo/chặn.                          SCANNING                       0                                                                                                                                                             Không sinh Inventory_Movement; ghi Scan_Event/Scan_Batch.

  Phiếu nhập      DRAFT / SCANNING               Cancel receipt                                      NV kho / QL kho                  Chưa có posting_event/posted_qty; bắt buộc nhập lý do hủy; người dùng có quyền hủy.                                                                                  CANCELLED                      0                                                                                                                                                             Không sinh Inventory_Movement; ghi audit CANCEL_RECEIPT.

  Phiếu nhập      SCANNING / PARTIALLY_POSTED    Post Receipt                                        NV kho / QL kho / Admin          Idempotency-Key bắt buộc; confirmed_lines không vượt remaining_qty; không over-receipt trong MVP; scan/mapping hợp lệ; transaction lock theo SKU/item/box.           PARTIALLY_POSTED hoặc POSTED   \+ posted_qty của lần post vào Inventory_Balance.available_qty; cập nhật item/box tương ứng. PARTIALLY_POSTED nếu còn remaining_qty; POSTED khi đủ.           RECEIPT_POSTED theo từng posting event/SKU/item/box; retry cùng key không sinh duplicate movement.

  Phiếu xuất      Không có phiếu                 Create issue document                               NV kho / QL kho / Admin          Có quyền xuất kho; nơi nhận là đại lý/cửa hàng con/đối tượng hợp lệ; SKU active; planned_qty \> 0.                                                                   DRAFT                          0                                                                                                                                                             Không sinh Inventory_Movement; audit CREATE_ISSUE.

  Phiếu xuất      DRAFT                          Scan item/carton/box hoặc nhập quantity linh kiện   NV kho                           Mã phải đang IN_STOCK tại kho tổng; không thuộc warranty/lost/adjusted_out; scan carton chỉ lấy item còn IN_STOCK; quantity linh kiện không vượt box_qty khả dụng.   SCANNING                       0                                                                                                                                                             Không sinh Inventory_Movement; ghi Scan_Event/Scan_Batch.

  Phiếu xuất      DRAFT / SCANNING               Cancel issue                                        NV kho / QL kho                  Chưa có posting_event/issued_qty; bắt buộc nhập lý do hủy; người dùng có quyền hủy.                                                                                  CANCELLED                      0                                                                                                                                                             Không sinh Inventory_Movement; audit CANCEL_ISSUE.

  Phiếu xuất      SCANNING / PARTIALLY_POSTED    Post Issue                                          NV kho / QL kho / Admin          Idempotency-Key bắt buộc; issued_qty không vượt remaining_qty hoặc available_qty; row-level lock theo SKU/item/box; không âm kho.                                    PARTIALLY_POSTED hoặc POSTED   \- issued_qty của lần post khỏi Inventory_Balance.available_qty; cập nhật item/carton/box tương ứng. PARTIALLY_POSTED nếu còn remaining_qty; POSTED khi đủ.   ISSUE_POSTED theo từng posting event/SKU/item/box; retry cùng key không trừ tồn lần 2.

  Phiên kiểm kê   Không có phiên                 Create stocktake session                            QL kho / NV kho được cấp quyền   Có quyền kiểm kê; phạm vi kiểm kê hợp lệ; hệ thống ghi stocktake_started_at và Stocktake_Snapshot làm baseline.                                                      DRAFT                          0                                                                                                                                                             Không sinh Inventory_Movement; audit CREATE_STOCKTAKE.

  Phiên kiểm kê   DRAFT                          Start counting                                      NV kho / QL kho                  Snapshot đã tạo; phạm vi kiểm kê không rỗng; chưa cancel/apply.                                                                                                      COUNTING                       0                                                                                                                                                             Không sinh Inventory_Movement; ghi audit START_COUNTING.

  Phiên kiểm kê   COUNTING                       Submit count / Review variance                      NV kho / QL kho                  Có dữ liệu count; serial/box/quantity hợp lệ; hệ thống đối chiếu với snapshot tại stocktake_started_at, không so trực tiếp với tồn hiện tại.                         REVIEWING                      0                                                                                                                                                             Không sinh Inventory_Movement; tạo Stocktake_Variance.

  Phiên kiểm kê   REVIEWING                      Apply adjustment                                    QL kho / Admin                   Có quyền áp dụng; Idempotency-Key bắt buộc; variance đã review; nếu có movement phát sinh trong phiên phải hiển thị cảnh báo và QL kho xác nhận tiếp tục.            APPLIED                        +/- variance_qty vào Inventory_Balance.available_qty; item thiếu -\> ADJUSTED_OUT; item thừa hợp lệ -\> IN_STOCK; box quantity cập nhật theo count.           ADJUST_IN / ADJUST_OUT; sinh trong cùng transaction với cập nhật tồn và chuyển APPLIED.

  Phiên kiểm kê   DRAFT / COUNTING / REVIEWING   Cancel stocktake                                    QL kho / Admin                   Phiên chưa APPLIED; bắt buộc nhập lý do hủy.                                                                                                                         CANCELLED                      0                                                                                                                                                             Không sinh Inventory_Movement; audit CANCEL_STOCKTAKE.

  Warranty case   Không có case                  Receive warranty with item_code                     NV bảo hành                      Có tên/SĐT người gửi; item_code tồn tại; item_status đang ISSUED hoặc trạng thái được phép nhận bảo hành; không phải TEMP_ONLY.                                      RECEIVED                       0; không tăng tồn bán.                                                                                                                                        Không sinh Inventory_Movement; ghi Warranty_Event RECEIVED; item_status ISSUED -\> IN_WARRANTY.

  Warranty case   Không có case                  Receive warranty TEMP_ONLY                          NV bảo hành                      Thiếu/mất/hỏng mã; bắt buộc customer_name, phone, missing_code_reason, manual_product_description; hệ thống sinh temporary_item_code.                                RECEIVED                       0; không tăng tồn bán; không truy vết về máy đã bán.                                                                                                          Không sinh Inventory_Movement; ghi Warranty_Event RECEIVED_TEMP_ONLY.

  Warranty case   RECEIVED                       Start checking                                      NV bảo hành                      Case chưa RETURNED/CANCELLED; có quyền cập nhật.                                                                                                                     CHECKING                       0                                                                                                                                                             Không sinh Inventory_Movement; Warranty_Event CHECKING.

  Warranty case   CHECKING                       Start repairing                                     NV bảo hành                      Có kết quả kiểm tra hoặc ghi chú xử lý; case chưa RETURNED/CANCELLED.                                                                                                REPAIRING                      0                                                                                                                                                             Không sinh Inventory_Movement; Warranty_Event REPAIRING.

  Warranty case   REPAIRING / CHECKING           Complete repair/check                               NV bảo hành                      Bắt buộc ghi kết quả xử lý hoặc bệnh xác nhận.                                                                                                                       COMPLETED                      0                                                                                                                                                             Không sinh Inventory_Movement; Warranty_Event COMPLETED.

  Warranty case   COMPLETED                      Return to customer                                  NV bảo hành                      Xác nhận người nhận/đối chiếu item_code hoặc temporary_item_code; case chưa RETURNED.                                                                                RETURNED                       0; item có mã -\> RETURNED_TO_CUSTOMER; TEMP_ONLY trả theo mã temp.                                                                                           Không sinh Inventory_Movement; Warranty_Event RETURNED.

  Putaway task    Không có task                  Create putaway task                                 NV kho / QL kho                  Box/linh kiện đã POSTED nhập; trạng thái RECEIVED_PENDING_PUTAWAY hoặc cần chuyển khay; tray active.                                                                 DRAFT                          0                                                                                                                                                             Không sinh Inventory_Movement; audit CREATE_PUTAWAY.

  Putaway task    DRAFT                          Confirm putaway                                     NV kho / QL kho                  Scan tray + box hợp lệ; box thuộc SKU/quantity đã nhập; tray active; không xuất khay.                                                                                CONFIRMED                      0; chỉ cập nhật vị trí box/linh kiện trong tray; box -\> STORED.                                                                                              Không sinh Inventory_Movement; ghi Putaway_Event/Scan_Event.

  Putaway task    DRAFT                          Cancel putaway                                      NV kho / QL kho                  Task chưa CONFIRMED; bắt buộc lý do nếu theo cấu hình.                                                                                                               CANCELLED                      0                                                                                                                                                             Không sinh Inventory_Movement; audit CANCEL_PUTAWAY.

  Phiếu nhập      PARTIALLY_POSTED               Close remainder                                     QL kho / Admin                   Còn remaining_qty; có quyền close remainder; bắt buộc reason; không có posting đang xử lý.                                                                           CLOSED                         0; cancelled_remainder_qty = remaining_qty.                                                                                                                   Không sinh Inventory_Movement; ghi audit CLOSE_INBOUND_REMAINDER.

  Phiếu xuất      PARTIALLY_POSTED               Close remainder                                     QL kho / Admin                   Còn remaining_qty; có quyền close remainder; bắt buộc reason; không có posting đang xử lý.                                                                           CLOSED                         0; cancelled_remainder_qty = remaining_qty.                                                                                                                   Không sinh Inventory_Movement; ghi audit CLOSE_OUTBOUND_REMAINDER.
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 14.6. Stock movement mapping chi tiết

Mục này bổ sung mapping movement để Dev tạo API/DB transaction và QA sinh test case. Inventory_Movement là append-only, không update/delete. Mỗi movement phải có movement_key/idempotency_key duy nhất để chống retry/click lặp gây tăng/giảm tồn hai lần.

Các nghiệp vụ scan, lưu nháp, putaway, bảo hành và App PV không làm thay đổi tồn kho thì không sinh Inventory_Movement tăng/giảm tồn; các nghiệp vụ này chỉ ghi Scan_Event, Warranty_Event, Putaway_Event hoặc Audit Outbox tương ứng.

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Nghiệp vụ**                                      **movement_type**      **qty_delta**                                  **Object affected**                                                   **Trước / Sau**                                                                                                                                                                      **Idempotency / chống trùng**
  -------------------------------------------------- ---------------------- ---------------------------------------------- --------------------------------------------------------------------- ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ -------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  Post Receipt - sản phẩm có serial/item             RECEIPT_POSTED         +1 mỗi item hoặc +received_qty theo dòng SKU   Inventory_Balance(sku_id), Item_Instance, Container_Item, Carton      Trước: phiếu SCANNING, item NEW/SCANNED, available=N. Sau: phiếu POSTED, item IN_STOCK, carton IN_STOCK/PARTIAL, available=N+qty.                                                    Idempotency-Key bắt buộc ở Post Receipt. Unique movement_key = receipt_id + line_id + item_code + movement_type. Retry trả movement cũ, không cộng tồn lần 2.

  Post Receipt - linh kiện có serial                 RECEIPT_POSTED         +1 mỗi component serial                        Inventory_Balance(sku_id), Item_Instance(component), Box nếu có       Trước: component serial chưa vào tồn. Sau: component serial IN_STOCK, box RECEIVED_PENDING_PUTAWAY/STORED, available=N+1.                                                            movement_key = receipt_id + line_id + component_serial + movement_type. Chặn serial đã thuộc phiếu khác.

  Post Receipt - linh kiện quantity-only theo box    RECEIPT_POSTED         +received_qty của SKU trong box                Inventory_Balance(sku_id), Container(BOX), Container_Item(quantity)   Trước: box mới hoặc chưa post, available=N. Sau: box quantity=received_qty, box RECEIVED_PENDING_PUTAWAY nếu chưa xếp khay, available=N+received_qty.                                movement_key = receipt_id + line_id + box_code + sku_id + movement_type. Retry không cộng thêm quantity.

  Scan nhập/xuất/kiểm kê khi chưa post               NO_STOCK_MOVEMENT      0                                              Scan_Event, Scan_Batch                                                Trước/Sau: tồn không đổi; chỉ ghi mã đã scan, source=REAL_SCANNER/DEMO/MANUAL và kết quả valid/invalid.                                                                              scan_event_id hoặc scan_batch_id + code_value unique trong ngữ cảnh nếu cần chống trùng trong một lần scan.

  Post Issue - xuất lẻ sản phẩm/item                 ISSUE_POSTED           -1 mỗi item                                    Inventory_Balance(sku_id), Item_Instance, Carton                      Trước: phiếu SCANNING, item IN_STOCK, available=N. Sau: phiếu POSTED, item ISSUED, carton PARTIAL/ISSUED, available=N-1.                                                             Idempotency-Key bắt buộc ở Post Issue. movement_key = issue_id + line_id + item_code + movement_type. Row-level lock item/SKU.

  Post Issue - xuất nguyên thùng/carton              ISSUE_POSTED           \- số item IN_STOCK trong carton theo SKU      Inventory_Balance(sku_id), Item_Instance list, Carton                 Trước: carton IN_STOCK/PARTIAL, item trong carton IN_STOCK. Sau: các item được xuất -\> ISSUED; carton ISSUED nếu hết hàng hoặc PARTIAL nếu còn; available giảm đúng số item xuất.   movement_key theo từng item: issue_id + carton_code + item_code + movement_type; chặn scan lại carton đã ISSUED/hết item khả dụng.

  Post Issue - linh kiện có serial                   ISSUE_POSTED           -1 mỗi component serial                        Inventory_Balance(sku_id), Item_Instance(component), Box nếu có       Trước: component serial IN_STOCK. Sau: component serial ISSUED; không có luồng tiêu hao linh kiện bảo hành trong MVP; available=N-1.                                                 movement_key = issue_id + line_id + component_serial + movement_type. Chặn serial không còn IN_STOCK.

  Post Issue - linh kiện quantity-only theo box      ISSUE_POSTED           -issued_qty                                    Inventory_Balance(sku_id), Container(BOX), Container_Item(quantity)   Trước: box_qty=Nbox, available=N. Sau: box_qty=Nbox-issued_qty; box PARTIAL/EMPTY/CLOSED; available=N-issued_qty.                                                                    movement_key = issue_id + line_id + box_code + sku_id + movement_type. Lock box/SKU, chặn issued_qty \> box_qty hoặc âm kho.

  Apply stocktake - thừa sản phẩm có serial hợp lệ   ADJUST_IN              +1 mỗi item thừa được QL kho áp dụng           Inventory_Balance(sku_id), Item_Instance, Stocktake_Variance          Trước: variance SURPLUS, item chưa tính vào available hoặc trạng thái phù hợp. Sau: item IN_STOCK, available=N+1, phiên APPLIED.                                                     Idempotency-Key bắt buộc ở Apply Adjustment. movement_key = stocktake_session_id + variance_id + item_code + ADJUST_IN.

  Apply stocktake - thiếu sản phẩm có serial         ADJUST_OUT             -1 mỗi item thiếu được QL kho áp dụng          Inventory_Balance(sku_id), Item_Instance, Stocktake_Variance          Trước: item IN_STOCK theo snapshot nhưng không đếm thấy. Sau: item ADJUSTED_OUT/LOST theo lý do, available=N-1, phiên APPLIED.                                                       movement_key = stocktake_session_id + variance_id + item_code + ADJUST_OUT. Retry không trừ tiếp.

  Apply stocktake - thừa linh kiện quantity-only     ADJUST_IN              +variance_qty                                  Inventory_Balance(sku_id), Container(BOX), Stocktake_Variance         Trước: variance_to_apply \> 0, trong đó variance_to_apply = actual_qty - expected_at_count. Sau: box quantity và available tăng theo variance_qty; phiên APPLIED.                    movement_key = stocktake_session_id + variance_id + box_code + sku_id + ADJUST_IN.

  Apply stocktake - thiếu linh kiện quantity-only    ADJUST_OUT             -variance_qty                                  Inventory_Balance(sku_id), Container(BOX), Stocktake_Variance         Trước: variance_to_apply \< 0, trong đó variance_to_apply = actual_qty - expected_at_count. Sau: box quantity và available giảm theo variance_qty; phiên APPLIED.                    movement_key = stocktake_session_id + variance_id + box_code + sku_id + ADJUST_OUT. Chặn giảm làm âm nếu dữ liệu đã biến động ngoài kiểm soát mà chưa được QL xác nhận.

  Tiếp nhận bảo hành máy đã xuất                     NO_STOCK_MOVEMENT      0                                              Warranty_Case, Warranty_Event, Item_Instance                          Trước: item ISSUED. Sau: Warranty_Case RECEIVED, item IN_WARRANTY. Available không đổi vì item đã không nằm trong tồn bán.                                                           warranty_case_code unique. Nếu retry tạo case với cùng idempotency_key thì trả case cũ, không tạo duplicate case/event.

  Trả máy bảo hành cho khách                         NO_STOCK_MOVEMENT      0                                              Warranty_Case, Warranty_Event, Item_Instance                          Trước: case COMPLETED, item IN_WARRANTY hoặc TEMP_ONLY. Sau: case RETURNED; item có mã -\> RETURNED_TO_CUSTOMER; TEMP_ONLY trả theo temporary_item_code. Available không đổi.        event_key = warranty_case_id + RETURNED. Không sinh movement nhập/xuất kho.

  Putaway/Xếp khay linh kiện sau nhập                NO_STOCK_MOVEMENT      0                                              Putaway_Task, Container(BOX), Container(TRAY), Container_Item         Trước: box RECEIVED_PENDING_PUTAWAY. Sau: box STORED, mapping box -\> tray được cập nhật. Available không đổi.                                                                       putaway_task_id + box_code + tray_code unique. Retry không tạo mapping trùng.

  App PV xem danh sách/chi tiết sản phẩm             NO_STOCK_MOVEMENT      0                                              PV_Product_Profile, Inventory_Balance read-only                       Trước/Sau: tồn không đổi. App chỉ đọc available_qty đã post từ WM, không giữ hàng, không trừ tồn, không tạo tồn đại lý.                                                              Không dùng idempotency movement; có thể ghi analytics view/search nếu sau này cần nhưng không liên quan Inventory_Movement.

  Reverse Receipt                                    RECEIPT_REVERSED       Âm                                             SKU/Item/Box/Carton                                                   IN_STOCK → trạng thái trước receipt hoặc ADJUSTED_OUT                                                                                                                                Bắt buộc original_movement_id; không sửa RECEIPT_POSTED gốc.

  Reverse Issue                                      ISSUE_REVERSED         Dương                                          SKU/Item/Box/Carton                                                   ISSUED → IN_STOCK nếu hàng đã về và condition hợp lệ                                                                                                                                 Bắt buộc original_movement_id và xác nhận hàng vật lý.

  Correction/Return                                  RETURN_IN/RETURN_OUT   ±                                              SKU/Item                                                              Theo reason/condition                                                                                                                                                                Dùng cho nghiệp vụ trả hàng/sửa sai hợp lệ; append-only.
  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

Rule kiểm soát bắt buộc: mọi movement làm thay đổi tồn phải được tạo trong cùng transaction với cập nhật Inventory_Balance và cập nhật trạng thái chứng từ/phiên kiểm kê. Nếu transaction lỗi thì rollback toàn bộ: không được có trường hợp tồn đã đổi nhưng không có movement, hoặc có movement nhưng tồn chưa đổi.

### 14.7. Trace đóng P0-blocker scope kho tổng / tồn đại lý

Mục này giúp PM/Dev/Test trace nhanh quyết định DEC-WM-001 vào các phần cần kiểm soát trước khi handoff. Nếu có mô tả cũ hoặc wireframe cũ gợi ý multi-warehouse/tồn đại lý, nội dung dưới đây được ưu tiên áp dụng cho MVP Hoa Nam.

Trace 1 - PM issue: Chưa xác nhận MVP chỉ quản lý 1 kho tổng, không quản lý tồn đại lý. Decision: DEC-WM-001/002 đã được khách xác nhận sau khảo sát. Rule liên quan: WM-SCOPE-001..004, BR-SCOPE-01..05, BR-OUT-04, BR-AGY-01. TC/AC liên quan: AC-SCOPE-01..05, TC-SCOPE-001..006. Status: Resolved / không còn blocker.

Trace 2 - Data/API impact: Warehouse = 01 kho tổng; Agency không phải warehouse; Inventory_Balance không có agency_id; Post Issue chỉ giảm kho tổng; không tạo API tồn đại lý, không tạo stocktake đại lý và không tạo movement tăng tồn agency.

Trace 3 - App PV impact: App PV chỉ đọc available_qty theo SKU từ kho tổng, read-only; không hiển thị tồn đại lý và không tạo nghiệp vụ giữ hàng/đặt mua/thanh toán.

### 14.8. Trace xử lý P1-risk trước DEV/TEST triển khai sâu

Bảng này gom nhanh các chỉnh sửa P1 đã bổ sung vào BRD để PM/DEV/TEST trace khi review tài liệu, thiết kế API/DB và sinh test case.

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **P1 issue**                                       **Nội dung đã cập nhật trong BRD**                                                                                                                            **Vị trí cập nhật chính**    **Status**
  -------------------------------------------------- ------------------------------------------------------------------------------------------------------------------------------------------------------------- ---------------------------- ---------------------------------------------
  Thiếu customer sign-off cho MVP/out-of-scope       Bổ sung Decision Log DEC-WM-004..009 cho App PV không đặt hàng, không chi phí bảo hành, hiển thị available_qty, hardware boundary và scope kho tổng/đại lý.   1.4, 2.2.1, 10.2, 12         Resolved

  Khái niệm chip/mã/RFID/QR chưa chặt                Tách Physical_Code, QR/Barcode, RFID/NFC chip, Demo scanner, Scan batch và Hardware integration thật; bổ sung data convention và business rules.              4.5, 8.1, 14.1, 14.4         Resolved for MVP; hardware detail = Phase 2

  Open points chưa phân loại ảnh hưởng               Thay bảng Open points bằng bảng có phân loại RESOLVED / DEV_CLARIFICATION / UI_ONLY / PHASE_LATER_ESTIMATE.                                                   12\. Open points             Resolved

  App PV và dữ liệu tồn kho cần kiểm soát hiển thị   Ghi rõ đối tác đã chốt hiển thị available_qty cho đại lý/khách; vẫn che serial/item/container/movement/cost và không cho App PV mutate tồn.                   2.4.1, 6.8, 8.6, 11.1/11.2   Resolved
  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 15. Wireframe / screen flow cho các màn chính

*Quy ước low-fi: mỗi màn tối thiểu có Header, Search/Filter, Data area, Action bar, Empty/Error state. Các action làm thay đổi tồn chỉ được thực hiện ở bước post/xác nhận cuối cùng. Demo scanner và manual input dùng cùng flow scan.*

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Screen ID**   **Màn hình**                  **Actor**                          **Entry point**                       **Nội dung/action chính**                                                                                                             **Next screen / data effect**                                                                                         **Empty/Error/Permission state**
  --------------- ----------------------------- ---------------------------------- ------------------------------------- ------------------------------------------------------------------------------------------------------------------------------------- --------------------------------------------------------------------------------------------------------------------- ---------------------------------------------------------------------------------------------
  SKU-01          Danh sách SKU                 Admin / QL kho / NV kho xem        Menu Danh mục \> SKU                  Search theo sku_code/tên/model; filter sku_type, category, serial_tracked, status, published_to_app; action Tạo/Sửa/Disable/Import.   SKU-02 Form SKU; SKU-03 Import SKU; PV Admin nếu published_to_app = true.                                             Empty: chưa có SKU. Error: không tải được danh sách. No permission: ẩn Tạo/Sửa/Import.

  SKU-02          Form tạo/sửa SKU              Admin / QL kho                     Từ SKU list hoặc import lỗi cần sửa   Nhập thông tin SKU, chọn PRODUCT/COMPONENT, cấu hình is_serial_tracked, publish app, thông số kỹ thuật.                               Save thành công quay lại list/detail. Nếu COMPONENT + is_serial_tracked=false thì flow nhập/xuất theo quantity box.   Duplicate sku_code báo lỗi cạnh field. Disable sku_type nếu SKU đã phát sinh item/movement.

  IN-01           Danh sách phiếu nhập          NV kho / QL kho                    Menu Kho \> Nhập kho                  Tạo phiếu, filter trạng thái DRAFT/SCANNING/PARTIALLY_POSTED/POSTED/CLOSED/CANCELLED, mở chi tiết, xem movement.                      IN-02 Tạo/scan; IN-03 Review/Post Receipt; Putaway nếu linh kiện chưa xếp khay.                                       Không có action duyệt/chờ duyệt. Phiếu POSTED chỉ xem, không sửa.

  IN-02           Phiếu nhập - Scan/nhập mã     NV kho                             Tạo mới hoặc mở phiếu DRAFT           Header phiếu; dòng SKU; scan carton/item/box/component; nút Demo máy quét; bảng mã valid/invalid.                                     Post Receipt khi dữ liệu hợp lệ. Với linh kiện quantity-only phải có box_code + quantity.                             Trùng mã, khác SKU trong carton, quantity \<=0 hiển thị lỗi và không post.

  OUT-01          Danh sách phiếu xuất          NV kho / QL kho                    Menu Kho \> Xuất kho                  Tạo phiếu xuất, chọn nơi nhận agency, filter trạng thái, xem movement.                                                                OUT-02 Scan xuất; OUT-03 Post Issue.                                                                                  Không có duyệt. Không tạo tồn đại lý. Phiếu POSTED không sửa/hủy thường.

  OUT-02          Phiếu xuất - Scan/nhập mã     NV kho                             Tạo mới hoặc mở DRAFT                 Scan carton để xuất nguyên thùng, scan item để xuất lẻ, scan box/component và nhập quantity linh kiện.                                Post Issue với Idempotency-Key. Tạo movement ISSUE_POSTED.                                                            Chặn item đã ISSUED/IN_WARRANTY/LOST; chặn xuất vượt box quantity; chặn âm kho.

  STK-01          Danh sách phiên kiểm kê       QL kho / NV kho được cấp           Menu Kho \> Kiểm kê                   Tạo phiên, filter trạng thái DRAFT/COUNTING/REVIEWING/APPLIED/CANCELLED, xem variance.                                                STK-02 Count; STK-03 Review variance; STK-04 Apply adjustment.                                                        Tạo phiên phải có stocktake_started_at + snapshot. Không khóa nhập/xuất bằng hệ thống.

  STK-02          Màn kiểm đếm                  NV kho                             Mở phiên COUNTING                     Scan item/carton/box/tray; Demo scanner; nhập quantity với linh kiện không serial; danh sách mã trùng/lạ.                             Submit count chuyển REVIEWING.                                                                                        Scan tray chỉ load box bên trong, không tạo tồn riêng. Scan trùng item cảnh báo.

  WAR-01          Danh sách bảo hành            NV bảo hành / QL kho               Menu Bảo hành                         Search theo case_code, item_code, temporary_item_code, SĐT, SKU/model, defect, status.                                                WAR-02 Receive case; WAR-03 Update status; WAR-04 Return.                                                             Case TEMP_ONLY vẫn tìm được theo mã temp/SĐT. Không hiển thị chi phí/người sửa trong MVP.

  WAR-02          Tiếp nhận bảo hành            NV bảo hành                        Tạo case mới                          Scan item nếu có; nếu mất mã chọn TEMP_ONLY; nhập tên/SĐT, bệnh máy, mô tả nhận diện, ảnh nếu có.                                     Case RECEIVED; item có mã chuyển IN_WARRANTY; TEMP_ONLY sinh temporary_item_code.                                     Thiếu SĐT/tên/bệnh hoặc thiếu missing_code_reason với TEMP_ONLY thì không lưu.

  PV-01           App PV - Danh sách sản phẩm   Chủ đại lý/cửa hàng / khách hàng   Mở App PV                             Search, filter ngành nghề/nhóm máy/dòng pin/còn hàng, sort; card có ảnh, tên, SKU/model, available_qty, trạng thái.                   PV-02 Product detail; PV-03 Industry recommendation.                                                                  Chỉ hiển thị active + published_to_app. Không có cart/order/reserve/payment.

  PV-02           App PV - Chi tiết sản phẩm    Người xem App PV                   Từ product card/deep link             Ảnh, mô tả, thông số, bảo hành, phụ kiện tương thích, ngành nghề, available_qty kho tổng, trạng thái Còn/Hết hàng.                    Xem sản phẩm liên quan hoặc quay lại list.                                                                            Không hiển thị serial/item/container/movement/giá vốn/tồn đại lý.

  WM-IN-REV       Reverse Receipt               QL kho/Admin                       Phiếu nhập POSTED                     Xem movement gốc, chọn dòng/qty đảo, lý do, confirm/post reversal                                                                     Loading/empty/error/403/409/422/success                                                                               Must

  WM-OUT-REV      Reverse Issue                 QL kho/Admin                       Phiếu xuất POSTED                     Xác nhận hàng quay lại, condition, dòng/qty đảo, post reversal                                                                        Loading/empty/error/403/409/422/success                                                                               Must

  WM-IMPORT       Migration/Opening Balance     QL kho/PM                          Menu cấu hình dữ liệu                 Upload, validate, dry-run, error export, commit, reconciliation, rollback                                                             Loading/empty/error/403/validation/progress/success                                                                   Must

  WM-UAT          UAT Evidence Register         Tester/BA/PM                       Hồ sơ UAT/Jira                        Theo dõi script, result, evidence, defect, sign-off                                                                                   Loading/empty/error/403/filter/export                                                                                 Must
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Module**   **Screen flow low-fi**                                                                                                                                          **Rule trọng tâm**
  ------------ --------------------------------------------------------------------------------------------------------------------------------------------------------------- ---------------------------------------------------------------------------------------------------------------------
  SKU          \[SKU List\] -\> \[Create/Edit SKU Form\] -\> \[Save\] -\> \[SKU Detail/List\]\                                                                                 SKU active mới được dùng trong nhập/xuất/App PV. sku_type, sku_code không nên đổi khi đã có movement.
               \[Import SKU\] -\> \[Dry-run Validate\] -\> \[Import Result\] -\> \[Fix failed rows\]                                                                           

  Nhập kho     \[Inbound List\] -\> \[Create Draft\] -\> \[Scan/Manual/Demo Scanner\] -\> \[Review scanned lines\] -\> \[Post Receipt\] -\> \[Movement + Balance updated\]\    DRAFT/SCANNING không tăng tồn. Post Receipt idempotent, tăng tồn đúng một lần. Putaway không đổi tồn.
               Nếu component chưa xếp khay: \[Post Receipt\] -\> \[Putaway Task\] -\> \[Confirm Tray\]                                                                         

  Xuất kho     \[Outbound List\] -\> \[Create Draft + agency\] -\> \[Scan carton/item/box\] -\> \[Validate available\] -\> \[Post Issue\] -\> \[Movement + Balance updated\]   Chỉ xuất từ kho tổng. Post Issue giảm tồn kho tổng, không tạo tồn agency. Idempotency-Key bắt buộc.

  Kiểm kê      \[Stocktake List\] -\> \[Create Session + Snapshot\] -\> \[Counting\] -\> \[Review Variance + Movement Warning\] -\> \[Apply Adjustment\] -\> \[APPLIED\]       Variance tính theo snapshot tại stocktake_started_at. Apply adjustment mới đổi tồn và sinh ADJUST_IN/OUT.

  Bảo hành     \[Warranty List\] -\> \[Receive Identified / TEMP_ONLY\] -\> \[CHECKING\] -\> \[REPAIRING\] -\> \[COMPLETED\] -\> \[RETURNED\]                                  Tiếp nhận/trả bảo hành không tăng tồn bán. TEMP_ONLY không truy vết máy đã bán và quản lý bằng temporary_item_code.

  App PV       \[Home/List\] -\> \[Search/Filter/Industry\] -\> \[Product Detail\] -\> \[Related/Recommendation\]                                                              Read-only. Hiển thị available_qty kho tổng theo SKU. Không tạo order/reserve/payment/movement.
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 16. Field-level UI spec

*Cột “Enable/Disable” mô tả hành vi UI. Backend vẫn phải validate lại toàn bộ rule, không chỉ dựa vào trạng thái control trên giao diện.*

### 16.1. SKU Form / SKU Detail

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Field/Control**            **Bắt buộc?**                    **Format / Allowed values**                                                                                                                            **Enable / Disable rule**                                                        **Validation message**                                                                                                         **Ghi chú BA**
  ---------------------------- -------------------------------- ------------------------------------------------------------------------------------------------------------------------------------------------------ -------------------------------------------------------------------------------- ------------------------------------------------------------------------------------------------------------------------------ -------------------------------------------------------------------------------------
  sku_code                     Có                               A-Z/0-9/-/\_; PRODUCT: {BRAND}-{CATEGORY}-{MODEL}-{POWER/VOLTAGE}-{PACKAGE?}-{VERSION}; COMPONENT: LK-{BRAND}-{TYPE}-{MODEL/FAMILY}-{SPEC}-{VERSION}   Enable khi tạo mới; disable khi SKU đã có item/movement                          Mã SKU không được để trống / Mã SKU đã tồn tại / Sai định dạng mã SKU / Không được đưa batch, condition hoặc channel vào SKU   Duy nhất toàn hệ thống; ưu tiên hệ thống gợi ý mã từ các field Product Master Data.

  sku_name                     Có                               Text tối đa 255 ký tự                                                                                                                                  Enable nếu có quyền sửa SKU                                                      Tên SKU không được để trống                                                                                                    Tên hiển thị ở WM và App PV nếu chưa có display_name riêng.

  sku_type                     Có                               PRODUCT hoặc COMPONENT                                                                                                                                 Enable khi tạo mới; disable sau khi đã phát sinh item/movement                   Vui lòng chọn loại SKU                                                                                                         Quyết định flow sản phẩm hay linh kiện.

  category_id                  Có                               Dropdown category active                                                                                                                               Enable theo quyền master data                                                    Danh mục không hợp lệ hoặc đã ngừng hoạt động                                                                                  Dùng cho lọc/báo cáo/App PV.

  brand_code / model_code      Có với PRODUCT                   Text/code tối đa 100 ký tự; chỉ A-Z/0-9/-/\_ với code                                                                                                  Enable khi tạo; hạn chế sửa nếu đã phát sinh dữ liệu                             Brand/model không được để trống với SKU sản phẩm / Sai định dạng code                                                          Là thành phần tạo sku_code và tìm kiếm App PV.

  unit                         Có                               Dropdown: cái, bộ, hộp...                                                                                                                              Enable khi tạo; hạn chế sửa nếu đã có tồn                                        Đơn vị tính không hợp lệ                                                                                                       Không làm unit conversion trong MVP nếu chưa chốt.

  is_serial_tracked            Có                               Boolean true/false                                                                                                                                     Disable nếu SKU đã có tồn hoặc movement                                          Vui lòng chọn cách quản lý serial                                                                                              true: quản lý từng serial; false: quản lý quantity theo box.

  default_container_type       Có                               CARTON/BOX/TRAY/NONE                                                                                                                                   Enable theo sku_type                                                             Container mặc định không phù hợp loại SKU                                                                                      PRODUCT thường CARTON; COMPONENT thường BOX/TRAY.

  published_to_app             Có với PRODUCT                   Boolean                                                                                                                                                Enable cho Admin/QL kho; hidden với COMPONENT nếu không publish linh kiện        Không thể publish SKU inactive                                                                                                 Chỉ active + published mới lên App PV.

  specification / images       Không, nên có nếu publish        Textarea/JSON/Upload URL                                                                                                                               Enable khi published_to_app hoặc chuẩn bị publish                                Ảnh/URL không hợp lệ                                                                                                           Dữ liệu hiển thị chi tiết App PV.

  status                       Có                               ACTIVE/INACTIVE                                                                                                                                        Disable nếu người dùng không có quyền; không cho INACTIVE nếu còn nghiệp vụ mở   Không thể ngừng hoạt động SKU đang có chứng từ mở                                                                              Inactive không hiển thị App PV và không chọn trong chứng từ mới.

  power_or_voltage             Có nếu áp dụng                   Text/code: 20V, 12V, 220V, 850W...                                                                                                                     Enable khi tạo; hạn chế sửa nếu đã có item/movement                              Điện áp/công suất không hợp lệ                                                                                                 Dùng phân biệt biến thể sản phẩm.

  package_variant / kit_type   Không/Có nếu có nhiều cấu hình   Enum đề xuất: BARE, KIT, SET, BODY_ONLY, NONE                                                                                                          Enable khi tạo; hạn chế sửa nếu đã có item/movement                              Cấu hình bộ phụ kiện không hợp lệ                                                                                              Không dùng để quản lý container; chỉ là biến thể bán được.

  version                      Có                               V1/V2/GEN2 hoặc theo master version                                                                                                                    Enable khi tạo; hạn chế sửa nếu đã có item/movement                              Version không hợp lệ                                                                                                           Mặc định V1 nếu chưa có nhiều đời sản phẩm.

  import_batch_code            Không trên SKU Form              Không nhập tại form SKU                                                                                                                                Không hiển thị hoặc read-only nếu có context nhập hàng                           Batch phải nhập ở phiếu nhập/import batch, không đưa vào SKU                                                                   Batch thuộc Import_Batch/Inbound, không thuộc SKU Master.
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 16.2. Phiếu nhập / Scan nhập

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Field/Control**        **Bắt buộc?**   **Format / Allowed values**                                   **Enable / Disable rule**                                      **Validation message**                                                   **Ghi chú BA**
  ------------------------ --------------- ------------------------------------------------------------- -------------------------------------------------------------- ------------------------------------------------------------------------ ----------------------------------------------------------
  receipt_code             Hệ thống        Auto code PN-YYYY-#### hoặc UUID                              Read-only                                                      Không tạo được mã phiếu                                                  Không cho sửa sau khi tạo.

  receipt_date             Có              Date, không lớn hơn ngày hiện tại nếu rule vận hành yêu cầu   Enable ở DRAFT; disable POSTED/CANCELLED                       Ngày nhập không hợp lệ                                                   Có thể default = hôm nay.

  source_note / supplier   Không           Dropdown supplier active hoặc text note                       Enable ở DRAFT/SCANNING                                        Nhà cung cấp không hợp lệ                                                Supplier optional trong MVP.

  sku_id dòng nhập         Có              Dropdown SKU active                                           Enable ở DRAFT/SCANNING; disable sau POSTED                    SKU không tồn tại hoặc đã inactive                                       PRODUCT/COMPONENT quyết định scan rule.

  expected_qty             Có              Number integer \> 0                                           Enable ở DRAFT/SCANNING                                        Số lượng nhập phải lớn hơn 0                                             Dùng để đối chiếu scan/received_qty.

  scan_input               Không           Text code_value hoặc scan batch list                          Enable khi trạng thái DRAFT/SCANNING và có line SKU            Mã không hợp lệ / Mã đã tồn tại / Mã khác SKU trong thùng                Dùng chung cho manual input, real scanner, demo scanner.

  demo_scanner_button      Không           Button                                                        Enable khi form có SKU/line hợp lệ; disable POSTED/CANCELLED   Không sinh được dữ liệu demo                                             Tạo Scan_Batch source=DEMO_SCANNER.

  post_receipt_button      Có action       Button action                                                 Enable khi có ít nhất 1 dòng valid, không có lỗi blocking      Không thể post vì còn dòng lỗi / Phiếu đã post / Thiếu Idempotency-Key   Tăng tồn, sinh RECEIPT_POSTED, idempotent.
  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 16.3. Phiếu xuất / Scan xuất

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Field/Control**      **Bắt buộc?**           **Format / Allowed values**                                **Enable / Disable rule**                                                                                           **Validation message**                                               **Ghi chú BA**
  ---------------------- ----------------------- ---------------------------------------------------------- ------------------------------------------------------------------------------------------------------------------- -------------------------------------------------------------------- -------------------------------------------------------------
  issue_code             Hệ thống                Auto code PX-YYYY-#### hoặc UUID                           Read-only                                                                                                           Không tạo được mã phiếu                                              Không cho sửa sau khi tạo.

  agency_id / receiver   Có                      Dropdown Agency active hoặc đối tượng nhận hợp lệ          Enable ở DRAFT; disable sau POSTED                                                                                  Vui lòng chọn nơi nhận hàng                                          Không tạo tồn agency.

  issue_date             Có                      Date                                                       Enable DRAFT/SCANNING                                                                                               Ngày xuất không hợp lệ                                               Default hôm nay.

  sku_id dòng xuất       Có                      Dropdown SKU active có tồn hoặc nhập mã scan tự nhận SKU   Enable DRAFT/SCANNING                                                                                               SKU không hợp lệ                                                     Có thể auto từ mã scan.

  planned_qty            Có                      Integer \> 0                                               Enable DRAFT/SCANNING                                                                                               Số lượng xuất phải lớn hơn 0                                         Đối chiếu issued_qty.

  scan_carton/item/box   Có theo flow            Text/list code_value                                       Enable DRAFT/SCANNING                                                                                               Mã không còn trong kho / Mã đang bảo hành / Đã xuất / Vượt tồn box   Chỉ item IN_STOCK hoặc box có quantity khả dụng.

  issued_qty             Hệ thống/nhập với box   Integer \>=0                                               Auto với serial/carton; nhập với component quantity-only; enable với quantity-only box; read-only với serial scan   Không được xuất vượt số lượng còn lại trong box                      Không xuất tray.

  post_issue_button      Có action               Button action                                              Enable khi scan/qty valid và đủ tồn                                                                                 Không đủ tồn / Phiếu đã post / Thiếu Idempotency-Key                 Giảm tồn kho tổng, sinh ISSUE_POSTED, không tạo tồn đại lý.
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 16.4. Kiểm kê

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Field/Control**         **Bắt buộc?**          **Format / Allowed values**                             **Enable / Disable rule**                    **Validation message**                                                    **Ghi chú BA**
  ------------------------- ---------------------- ------------------------------------------------------- -------------------------------------------- ------------------------------------------------------------------------- ---------------------------------------
  stocktake_code            Hệ thống               Auto code KK-YYYY-####                                  Read-only                                    Không tạo được mã phiên                                                   Gắn với snapshot.

  scope_type                Có                     ALL_WAREHOUSE / SKU_GROUP / SKU_LIST / COMPONENT_ONLY   Enable khi tạo DRAFT                         Phạm vi kiểm kê không hợp lệ                                              MVP vẫn là kho tổng.

  stocktake_started_at      Hệ thống               Datetime ISO                                            Read-only sau khi tạo                        Không tạo được snapshot tồn                                               Thời điểm baseline snapshot.

  snapshot_summary          Hệ thống               Số SKU/item/box trong phạm vi                           Read-only                                    Snapshot rỗng hoặc lỗi tạo snapshot                                       Dùng review variance.

  count_scan_input          Có khi counting        code_value/list scan batch                              Enable COUNTING; disable APPLIED/CANCELLED   Mã trùng / Mã lạ / Không thuộc phạm vi kiểm kê                            Demo scanner được dùng như scan thật.

  actual_qty                Có với quantity-only   Integer \>=0                                            Enable khi SKU/component không serial        Vui lòng nhập số lượng thực đếm                                           Không bắt quantity với serial item.

  variance_reason           Nên có khi lệch        Dropdown/text                                           Enable REVIEWING                             Vui lòng nhập lý do chênh lệch nếu cấu hình bắt buộc                      Hỗ trợ audit.

  apply_adjustment_button   Có action              Button action                                           Chỉ QL kho/Admin; enable ở REVIEWING         Có biến động trong phiên kiểm kê - cần xác nhận / Thiếu Idempotency-Key   Sinh ADJUST_IN/OUT và chuyển APPLIED.
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 16.5. Bảo hành

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Field/Control**            **Bắt buộc?**            **Format / Allowed values**                                     **Enable / Disable rule**                       **Validation message**                      **Ghi chú BA**
  ---------------------------- ------------------------ --------------------------------------------------------------- ----------------------------------------------- ------------------------------------------- ----------------------------------------------------------
  warranty_case_code           Hệ thống                 Auto code BH-YYYY-####                                          Read-only                                       Không tạo được mã case                      Dùng tra cứu.

  customer_name                Có                       Text 2-255 ký tự                                                Enable khi tạo/sửa trước RETURNED               Tên người gửi không được để trống           Bắt buộc cả case có mã và TEMP_ONLY.

  customer_phone               Có                       Số điện thoại 9-15 số, cho phép +84                             Enable khi tạo/sửa trước RETURNED               Số điện thoại không hợp lệ                  Dùng tra cứu/trả máy.

  item_code                    Nếu có                   Physical code ITEM                                              Enable ở receive; disable nếu chọn TEMP_ONLY    Mã máy không tồn tại hoặc không hợp lệ      Nếu hợp lệ gắn Item_Instance và chuyển item IN_WARRANTY.

  is_temp_only                 Không, action lựa chọn   Checkbox/Button “Tạo case không có mã”                          Enable khi chưa gắn item_code                   Không thể vừa gắn item_code vừa TEMP_ONLY   Sinh temporary_item_code.

  temporary_item_code          Có nếu TEMP_ONLY         Auto TEMP-BH-####                                               Read-only                                       Không sinh được mã tạm                      Không phải item_code máy đã bán.

  missing_code_reason          Có nếu TEMP_ONLY         Dropdown: mất chip, mã mờ, khách không nhớ, không tìm thấy...   Enable khi TEMP_ONLY                            Vui lòng chọn lý do không có mã             Bắt buộc để tránh truy vết sai.

  manual_product_description   Có nếu TEMP_ONLY         Textarea                                                        Enable khi TEMP_ONLY                            Vui lòng nhập mô tả nhận diện máy           Model ước lượng, màu, phụ kiện, dấu hiệu nhận biết.

  reported_defect              Có                       Dropdown defect + note                                          Enable trước COMPLETED/RETURNED                 Vui lòng nhập bệnh máy                      Dùng báo cáo bệnh.

  case_status                  Hệ thống/action          RECEIVED/CHECKING/REPAIRING/COMPLETED/RETURNED/CANCELLED        Action theo state; RETURNED chỉ sau COMPLETED   Không thể chuyển trạng thái không hợp lệ    Không sinh movement tồn bán.
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 16.6. App PV / Quản trị publish

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Field/Control**                **Bắt buộc?**       **Format / Allowed values**                         **Enable / Disable rule**       **Validation message**                               **Ghi chú BA**
  -------------------------------- ------------------- --------------------------------------------------- ------------------------------- ---------------------------------------------------- --------------------------------------------------
  display_name                     Có khi publish      Text tối đa 255 ký tự                               Enable với quyền Admin/QL kho   Tên hiển thị không được để trống khi publish         Có thể lấy mặc định từ sku_name.

  published_to_app                 Có                  Boolean                                             Enable với SKU active           Không thể publish SKU inactive                       Chỉ published mới hiển thị trên App PV.

  industry_ids                     Có nếu dùng gợi ý   Multi-select PV_Industry active                     Enable khi publish              Vui lòng chọn ít nhất một ngành nghề nếu bật gợi ý   Một sản phẩm có thể nhiều ngành.

  filter_tags                      Không/Nên có        Multi-select tags                                   Enable khi publish              Tag không hợp lệ                                     Dùng lọc 20V/12V, máy cưa, máy mài...

  image_urls                       Nên có              Upload/URL image                                    Enable khi publish              Ảnh không hợp lệ                                     Product card nên có ảnh đại diện.

  available_qty                    Hệ thống            Integer \>=0 đọc từ WM                              Read-only trên App PV           Không đọc được tồn khả dụng                          Hiển thị trên card/detail theo kho tổng đã post.

  stock_status_display             Hệ thống            Còn hàng nếu available_qty \> 0; Hết hàng nếu = 0   Read-only                       Không xác định trạng thái tồn                        Không có trạng thái trung gian trong MVP.

  order/reserve/payment controls   Không               Không hiển thị                                      Không tồn tại/disabled          Không áp dụng                                        App PV read-only, không tạo movement.
  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 17. Data import template

*Import nên có 2 bước: Dry-run validate trước, Confirm import sau. File lỗi trả về từng dòng với error_code/error_message. Tất cả import phải chạy trong transaction theo batch hoặc có cơ chế rollback rõ ràng nếu một dòng lỗi blocking.*

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Rule import chung**   **Expected behavior**
  ----------------------- -------------------------------------------------------------------------------------------------------------------------------------------
  File format             Hỗ trợ .xlsx là chính; .csv UTF-8 nếu cần. Header phải đúng template, không phụ thuộc thứ tự cột nếu backend map bằng header name.

  Dry-run                 Validate toàn bộ dữ liệu, không ghi DB, trả về số dòng hợp lệ/lỗi và file error report.

  Duplicate policy        Nếu mã đã tồn tại: báo lỗi mặc định. Chỉ update khi người dùng chọn chế độ UPDATE_EXISTING và có quyền.

  Reference check         sku_code/category/industry/tray/carton/box tham chiếu phải tồn tại hoặc nằm cùng batch import hợp lệ.

  Import result           Hiển thị created_count, updated_count, skipped_count, failed_count, batch_id, imported_by, imported_at.

  Audit                   Ghi audit_outbox cho IMPORT_START, IMPORT_DRY_RUN, IMPORT_COMMIT, IMPORT_FAILED; không ghi Inventory_Movement nếu import chỉ master data.
  -------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 17.1. Template import SKU

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Column**               **Required?**                    **Example**                              **Validation**                                           **Note**
  ------------------------ -------------------------------- ---------------------------------------- -------------------------------------------------------- -------------------------------------------------------------------------------------
  sku_code                 Có                               DCH-DRILL-DJZ10A-220V-V1                 Unique, A-Z/0-9/-/\_; đúng format Product Master Data    Không được trùng; không chứa batch/container/condition/channel.

  sku_name                 Có                               Máy khoan Dongcheng DJZ10A 220V bản V1   Text \<=255                                              Tên hiển thị nghiệp vụ.

  sku_type                 Có                               PRODUCT / COMPONENT                      Enum                                                     PRODUCT cho máy; COMPONENT cho linh kiện.

  category_code            Có                               DRILL                                    Phải tồn tại hoặc import trước                           Dùng filter/báo cáo/App PV và cấu thành SKU.

  brand_code               Có với PRODUCT                   DCH                                      Phải tồn tại hoặc import trước; A-Z/0-9/-/\_             Brand code là thành phần tạo SKU.

  model_code               Có với PRODUCT                   DJZ10A                                   Text/code \<=100; A-Z/0-9/-/\_                           Model code là thành phần định danh SKU.

  unit                     Có                               cái                                      Text/dropdown                                            Đơn vị chính.

  is_serial_tracked        Có                               TRUE/FALSE                               Boolean                                                  TRUE bắt scan serial; FALSE quantity box.

  default_container_type   Có                               CARTON/BOX/NONE                          Enum                                                     PRODUCT thường CARTON; COMPONENT thường BOX.

  warranty_applicable      Không                            TRUE/FALSE                               Boolean                                                  Dùng flow bảo hành.

  published_to_app         Có với PRODUCT                   TRUE/FALSE                               Boolean                                                  Chỉ active+published lên App PV.

  status                   Có                               ACTIVE/INACTIVE                          Enum                                                     Mặc định ACTIVE.

  power_or_voltage         Có nếu áp dụng                   220V / 20V / 850W                        Text/code \<=50                                          Dùng phân biệt công suất/điện áp/dòng pin.

  package_variant          Không/Có nếu có nhiều cấu hình   BARE / KIT / SET                         Enum hoặc code master                                    Phân biệt thân máy trần và bộ kèm phụ kiện nếu cùng model.

  version                  Có                               V1                                       Text/code \<=20                                          Mặc định V1 nếu chưa có nhiều version.

  import_batch_code        Không                            IMP-2026Q1-001                           Không import trong SKU template nếu chỉ tạo master SKU   Batch nhập khẩu phải nằm ở template Import_Batch/Inbound, không nằm trong sku_code.
  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 17.2. Template import Item / Product Instance

  -----------------------------------------------------------------------------------------------------------------------------------------
  **Column**       **Required?**   **Example**                       **Validation**            **Note**
  ---------------- --------------- --------------------------------- ------------------------- --------------------------------------------
  item_code        Có              ITEM-000001                       Unique Physical_Code      Mã máy/sản phẩm cụ thể.

  sku_code         Có              DCH-DRILL-DJZ10A-220V-V1          SKU PRODUCT active        Item phải thuộc SKU sản phẩm.

  carton_code      Không           CTN-0001                          Container CARTON nếu có   Mapping máy thuộc thùng.

  carrier_type     Có              QR/BARCODE/RFID/NFC/PLAIN_LABEL   Enum                      Phương tiện mang mã.

  hardware_uid     Không           E200001...                        Text \<=100               UID kỹ thuật nếu RFID/NFC khác code_value.

  item_status      Có              NEW/IN_STOCK                      Enum import ban đầu       Nếu import tồn đầu kỳ thì IN_STOCK.

  warehouse_code   Có              KHO-TONG                          MVP chỉ kho tổng          Không nhận agency.

  note             Không           Ghi chú                           Text \<=500               Không dùng tính tồn.
  -----------------------------------------------------------------------------------------------------------------------------------------

### 17.3. Template import Carton / Thùng sản phẩm

  -------------------------------------------------------------------------------------------------------------------------------------
  **Column**          **Required?**   **Example**                **Validation**         **Note**
  ------------------- --------------- -------------------------- ---------------------- -----------------------------------------------
  carton_code         Có              CTN-0001                   Unique Physical_Code   Mã thùng lớn.

  sku_code            Có              DCH-DRILL-DJZ10A-220V-V1   SKU PRODUCT active     Một carton chỉ chứa một SKU.

  warehouse_code      Có              KHO-TONG                   MVP chỉ kho tổng       Không dùng agency.

  expected_item_qty   Không/Nên có    20                         Integer \>=0           Đối chiếu số item mapping.

  item_codes          Không           ITEM-01;ITEM-02            Delimiter ;            Có thể import mapping nhiều item trong thùng.

  carton_status       Có              IN_STOCK/PARTIAL/EMPTY     Enum                   Nếu import đầu kỳ thường IN_STOCK/PARTIAL.

  carrier_type        Có              QR/BARCODE/RFID            Enum                   Không tạo SKU riêng cho carton.
  -------------------------------------------------------------------------------------------------------------------------------------

### 17.4. Template import Box / Hộp linh kiện

  ------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Column**       **Required?**          **Example**                                     **Validation**           **Note**
  ---------------- ---------------------- ----------------------------------------------- ------------------------ -------------------------------------------
  box_code         Có                     BOX-LK-0001                                     Unique Physical_Code     Mã hộp linh kiện.

  sku_code         Có                     LK-DCH-PIN-20V-V1                               SKU COMPONENT active     Một box chỉ chứa một SKU linh kiện.

  warehouse_code   Có                     KHO-TONG                                        MVP chỉ kho tổng         Không dùng agency.

  quantity         Có nếu quantity-only   100                                             Integer \>=0             Bắt buộc nếu SKU is_serial_tracked=false.

  tray_code        Không                  TRAY-01                                         TRAY active nếu đã xếp   Nếu trống: RECEIVED_PENDING_PUTAWAY.

  box_status       Có                     RECEIVED_PENDING_PUTAWAY/STORED/PARTIAL/EMPTY   Enum                     STORED nếu có tray_code.

  carrier_type     Có                     QR/BARCODE/RFID                                 Enum                     Box là container, không phải SKU.
  ------------------------------------------------------------------------------------------------------------------------------------------------------------

### 17.5. Template import Component / Linh kiện serial

  ------------------------------------------------------------------------------------------------------------------------------------------------------
  **Column**         **Required?**           **Example**           **Validation**         **Note**
  ------------------ ----------------------- --------------------- ---------------------- --------------------------------------------------------------
  component_code     Có nếu serial-tracked   COMP-0001             Unique Physical_Code   Không bắt buộc nếu SKU quantity-only.

  sku_code           Có                      LK-DCH-PIN-20V-V1     SKU COMPONENT active   Linh kiện thuộc SKU.

  box_code           Không/Nên có            BOX-LK-0001           Box active             Nếu có thì mapping linh kiện vào box.

  quantity           Có nếu không serial     50                    Integer \>=0           Với serial-tracked, mỗi row thường quantity=1 hoặc bỏ trống.

  carrier_type       Có nếu có code          QR/BARCODE/RFID/NFC   Enum                   Phương tiện mang mã linh kiện.

  component_status   Có                      IN_STOCK/NEW          Enum                   Không import ISSUED nếu chưa có chứng từ.

  warehouse_code     Có                      KHO-TONG              MVP chỉ kho tổng       Dùng đối soát tồn đầu kỳ.
  ------------------------------------------------------------------------------------------------------------------------------------------------------

## 18. NFR cơ bản

*Các chỉ số dưới đây là target đề xuất để Tech Lead xác nhận. Nếu hạ tầng hoặc thiết bị scan thật khác thực tế, cần cập nhật lại trước sprint planning/API freeze.*

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **NFR ID**      **Nhóm**                 **Yêu cầu**                                                                                                                                                                                                                                                             **Ghi chú/Assumption**
  --------------- ------------------------ ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- ---------------------------------------------------------------------------------------------------------------------------------------------
  NFR-PERF-01     Performance scan batch   Một scan batch 100 code_value phải validate và trả kết quả phân loại trong \<= 2 giây ở điều kiện dữ liệu bình thường. Batch 500 code_value \<= 5 giây hoặc chạy async có progress.                                                                                     Đề xuất target. Cần benchmark sau khi có DB/API. Không post tồn trong bước scan validate.

  NFR-PERF-02     UI responsiveness        Màn scan phải hiển thị trạng thái loading/progress, số mã valid/invalid/duplicate/unknown. Không khóa trình duyệt quá 3 giây.                                                                                                                                           Với batch lớn, FE dùng pagination/virtual list cho kết quả scan.

  NFR-CON-01      Concurrency khi post     Post Receipt/Post Issue/Apply Adjustment phải chạy trong DB transaction; lock theo SKU/item/box liên quan; không cho âm kho; không sinh movement nếu update tồn rollback.                                                                                               Bắt buộc cho Dev. QA cần test 2 user post cùng SKU/box.

  NFR-CON-02      Idempotency              Post Receipt, Post Issue, Apply Adjustment, Reverse Receipt và Reverse Issue bắt buộc có Idempotency-Key. Retry cùng key + cùng request_hash trả lại response cũ, không cập nhật tồn hoặc movement lần hai; cùng key nhưng payload khác trả 409 IDEMPOTENCY_CONFLICT.   Lưu operation, document_id, request_hash, response_snapshot và status; unique scope theo document/action. Áp dụng cho cả các reversal post.

  NFR-AUD-01      Audit log/outbox         Mọi create/update/cancel/post/apply/import phải ghi audit_outbox cùng transaction nghiệp vụ. Audit event có actor_id, action, object_type/id, before/after summary, request_id, created_at.                                                                             Không dùng Inventory_Movement thay Audit. Movement chỉ cho biến động tồn.

  NFR-AUD-02      Scan traceability        Scan_Event/Scan_Batch phải ghi scan_source REAL_SCANNER/DEMO_SCANNER/MANUAL_INPUT, code_value, code_type detected, result valid/invalid, business_context.                                                                                                              Hỗ trợ debug khi scan batch lỗi.

  NFR-SEC-01      Phân quyền dữ liệu WM    Admin/QL kho/NV kho/NV bảo hành/Lãnh đạo/App PV có quyền khác nhau. API phải kiểm tra role và data scope, không chỉ ẩn UI.                                                                                                                                              MVP có 01 kho tổng nhưng vẫn cần guard không lộ API nội bộ cho App PV.

  NFR-SEC-02      Phân quyền App PV        App PV chỉ đọc sản phẩm active + published_to_app và available_qty tổng theo SKU kho tổng. Không trả serial/item/container/movement/cost trong response public.                                                                                                         Endpoint PV tách response DTO với WM internal DTO.

  NFR-BACKUP-01   Backup dữ liệu           Đề xuất: backup full hằng ngày, incremental/WAL theo chính sách hạ tầng, retention tối thiểu 14-30 ngày, test restore định kỳ trước go-live.                                                                                                                            Cần DevOps xác nhận RPO/RTO chính thức.

  NFR-BACKUP-02   Recovery/rollback        Các nghiệp vụ post tồn phải atomic. Nếu lỗi giữa chừng thì rollback tồn, movement, document status, audit_outbox trong cùng transaction.                                                                                                                                Không chấp nhận trạng thái tồn đổi nhưng movement thiếu hoặc ngược lại.

  NFR-LOG-01      Error observability      Mọi lỗi API trả request_id/correlation_id; log có context document_id, operation, actor_id nhưng không log dữ liệu nhạy cảm quá mức.                                                                                                                                    Giúp support DEV/QA/UAT.

  NFR-DATA-01     Data quality import      Import phải có dry-run, error report theo dòng, không import partial nếu policy là all-or-nothing. Nếu partial allowed, phải có skipped/failed rows rõ ràng.                                                                                                            Chốt mode theo từng template trước khi build.
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 19. Backlog (dạng user story)

*Estimate assumption dùng T-shirt size: S = 1-2 dev-days, M = 3-5 dev-days, L = 6-10 dev-days, XL = cần tách nhỏ hoặc cần spike. Đây là assumption để BA/PM planning sơ bộ, không thay thế estimate cuối của Dev.*

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Story ID**   **Tên backlog**                                 **User story**                                                                                                                                                **Priority**   **Dependency**                              **Estimate assumption**   **Done/AC chính**
  -------------- ----------------------------------------------- ------------------------------------------------------------------------------------------------------------------------------------------------------------- -------------- ------------------------------------------- ------------------------- -----------------------------------------------------------------------------------------------------------------------------
  BL-SKU-01      Quản lý SKU sản phẩm/linh kiện                  Là Admin/QL kho, tôi muốn tạo/sửa/ngừng hoạt động SKU PRODUCT/COMPONENT theo Product Master Data để chuẩn hóa tồn kho, biến thể sản phẩm và App PV.           Must           Category master, quyền user                 M                         Không trùng sku_code; có brand/category/model/power/version/package nếu áp dụng; không đưa batch/condition/channel vào SKU.

  BL-SKU-02      Publish SKU lên App PV                          Là QL kho/Admin, tôi muốn cấu hình published_to_app, ảnh, mô tả, ngành nghề, tag để sản phẩm hiển thị trên App PV.                                            Must           BL-SKU-01, PV Industry/Tag                  M                         Chỉ active+published hiển thị; inactive bị ẩn.

  BL-IMP-01      Import SKU template                             Là BA/Admin, tôi muốn import danh mục SKU bằng Excel để tạo dữ liệu ban đầu nhanh.                                                                            Should         BL-SKU-01                                   M                         Có dry-run, error report, duplicate check.

  BL-CODE-01     Quản lý Physical_Code                           Là hệ thống, tôi muốn chuẩn hóa code_value/code_type/carrier_type để scan QR/Barcode/RFID/Demo dùng chung nghiệp vụ.                                          Must           SKU master                                  L                         Unique code_value, phân loại ITEM/CARTON/BOX/TRAY/TEMP.

  BL-CODE-02     Demo scanner và Scan_Batch                      Là NV kho, tôi muốn bấm Demo máy quét để giả lập một hoặc nhiều mã trong nhập/xuất/kiểm kê.                                                                   Must           BL-CODE-01                                  M                         Ghi Scan_Batch/Scan_Event source=DEMO_SCANNER.

  BL-IN-01       Tạo phiếu nhập DRAFT/SCANNING                   Là NV kho, tôi muốn tạo phiếu nhập và scan item/carton/box để chuẩn bị post nhập.                                                                             Must           SKU, Physical_Code                          L                         DRAFT/SCANNING không tăng tồn. Không có duyệt.

  BL-IN-02       Post Receipt idempotent                         Là NV kho, tôi muốn post nhập để tăng tồn đúng một lần và sinh movement.                                                                                      Must           BL-IN-01, concurrency/idempotency           L                         Post Receipt tăng available, sinh RECEIPT_POSTED, retry không cộng lần 2.

  BL-PTW-01      Putaway linh kiện sau nhập                      Là NV kho, tôi muốn scan tray + box để cập nhật vị trí linh kiện sau khi đã post nhập.                                                                        Must           BL-IN-02, Tray/Box                          M                         Cập nhật vị trí, không đổi tồn, không sinh movement tăng/giảm.

  BL-OUT-01      Tạo phiếu xuất cho đại lý/nơi nhận              Là NV kho, tôi muốn tạo phiếu xuất từ kho tổng cho agency để ghi nhận nơi nhận hàng.                                                                          Must           Agency master, SKU                          M                         agency_id không phải warehouse, không tạo tồn đại lý.

  BL-OUT-02      Scan xuất nguyên thùng/xuất lẻ/xuất linh kiện   Là NV kho, tôi muốn scan carton/item/box để xuất đúng hàng còn IN_STOCK.                                                                                      Must           BL-OUT-01, Physical_Code                    L                         Chặn item đã xuất/bảo hành/lost; chặn vượt box quantity.

  BL-OUT-03      Post Issue idempotent                           Là NV kho, tôi muốn post xuất để giảm tồn kho tổng đúng một lần.                                                                                              Must           BL-OUT-02, concurrency/idempotency          L                         Giảm available, sinh ISSUE_POSTED, không sinh tồn agency, retry không trừ lần 2.

  BL-STK-01      Tạo phiên kiểm kê có snapshot                   Là QL kho, tôi muốn tạo phiên kiểm kê và snapshot tồn tại thời điểm bắt đầu để đối soát chính xác.                                                            Must           Inventory_Balance                           M                         Có stocktake_started_at + Stocktake_Snapshot.

  BL-STK-02      Scan/nhập kết quả kiểm kê                       Là NV kho, tôi muốn scan item/carton/box/tray hoặc nhập quantity để ghi nhận thực đếm.                                                                        Must           BL-STK-01, Scan_Batch                       L                         Serial kiểm theo mã; quantity-only bắt actual_qty.

  BL-STK-03      Review variance và cảnh báo movement            Là QL kho, tôi muốn xem thiếu/thừa theo snapshot và cảnh báo nếu có movement phát sinh trong phiên.                                                           Must           BL-STK-02, Movement query                   M                         Hiển thị variance và movement_warning_flag.

  BL-STK-04      Apply Adjustment idempotent                     Là QL kho, tôi muốn áp dụng chênh lệch để cập nhật tồn và sinh ADJUST_IN/OUT.                                                                                 Must           BL-STK-03, idempotency                      L                         Chuyển APPLIED trong transaction, retry không adjust lần 2.

  BL-WAR-01      Tiếp nhận bảo hành có mã máy                    Là NV bảo hành, tôi muốn scan mã máy và tạo case bảo hành để theo dõi quá trình xử lý.                                                                        Must           Item_Instance                               M                         Case RECEIVED; item ISSUED -\> IN_WARRANTY; không tăng tồn bán.

  BL-WAR-02      Tiếp nhận bảo hành TEMP_ONLY                    Là NV bảo hành, tôi muốn tạo case không có mã khi chip/mã mất hoặc khách không nhớ mã.                                                                        Must           Warranty module                             M                         Sinh temporary_item_code, bắt buộc tên/SĐT/bệnh/lý do/mô tả; NOT_TRACEABLE.

  BL-WAR-03      Cập nhật trạng thái và trả máy                  Là NV bảo hành, tôi muốn chuyển CHECKING/REPAIRING/COMPLETED/RETURNED để quản lý tiến độ trả khách.                                                           Must           BL-WAR-01/02                                M                         RETURNED chỉ sau COMPLETED; item có mã -\> RETURNED_TO_CUSTOMER.

  BL-PV-01       App PV danh sách/tìm kiếm/lọc                   Là khách/đại lý, tôi muốn tìm và lọc sản phẩm theo tên, SKU, model, ngành nghề, tình trạng hàng.                                                              Must           SKU publish, Inventory_Balance read model   L                         Chỉ sản phẩm active+published; hiển thị available_qty và trạng thái.

  BL-PV-02       App PV chi tiết sản phẩm                        Là khách/đại lý, tôi muốn xem thông số, ảnh, bảo hành, phụ kiện tương thích, tồn khả dụng.                                                                    Must           BL-PV-01                                    M                         Không hiển thị serial/container/movement/cost.

  BL-PV-03       Gợi ý theo ngành nghề                           Là khách/đại lý, tôi muốn chọn ngành nghề để xem sản phẩm phù hợp.                                                                                            Must           PV_Industry, PV_Product_Industry            M                         Rule cấu hình, ưu tiên active+published+còn hàng.

  BL-PV-04       Analytics App PV cơ bản                         Là lãnh đạo, tôi muốn biết sản phẩm/ngành nghề được xem nhiều để cải thiện danh mục.                                                                          Should         PV events                                   S/M                       Ghi lượt xem/tìm kiếm; không tác động tồn.

  BL-NFR-01      Concurrency và lock khi post tồn                Là hệ thống, tôi muốn lock dữ liệu khi post để không sai lệch tồn khi nhiều người thao tác.                                                                   Must           DB transaction design                       L/Spike                   Test 2 user post cùng SKU/box không âm kho.

  BL-NFR-02      Audit Outbox                                    Là hệ thống, tôi muốn ghi audit_outbox cho mọi thao tác nghiệp vụ để truy vết.                                                                                Must           Audit service/outbox design                 M/L                       Audit có actor/action/object/request_id.

  BL-NFR-03      Backup/restore                                  Là vận hành, tôi muốn có backup và kiểm thử restore để giảm rủi ro mất dữ liệu kho.                                                                           Must           DevOps infra                                M/Spike                   Có lịch backup, retention, restore drill trước go-live.

  BL-SKU-03      Chuẩn hóa mã SKU theo Product Master Data       Là BA/Admin, tôi muốn hệ thống gợi ý và validate sku_code theo brand/category/model/power/version/package để tránh nhầm SKU khi sản phẩm có nhiều biến thể.   Must           BL-SKU-01, Category/Brand master            M                         Format SKU đúng; batch/container/item/condition/channel không nằm trong sku_code.

  BL-BATCH-01    Quản lý Import Batch                            Là NV kho/QL kho, tôi muốn quản lý lô/container nhập khẩu riêng để trace hàng theo batch mà không tách SKU.                                                   Should         Inbound, SKU master                         M                         Một SKU có nhiều batch; báo cáo/tìm kiếm được theo import_batch_code.
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 20. HISTORICAL P0 CLOSURE PACKAGE - v3.10 (SUPERSEDED BY v3.11)

Mục này lưu lịch sử remediation của v3.10 và không còn là nguồn implementation hiện hành. Mục 22, mục 23 và Source Register của BRD v3.11 là authoritative. Formal approval chỉ áp dụng cho attachment v3.11 hiện hành tại thời điểm WWHN-333 được transition sang DONE.

### 20.1. Document Control và trạng thái gate

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Trường**          **Giá trị**
  ------------------- --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  Document            BRD Module Quản lý kho & App xem sản phẩm - Hoa Nam Tools \| Jira: [[WWHN-333]{.underline}](https://tan0110.atlassian.net/browse/WWHN-333)

  Version             3.11 - Final Approval Candidate / Baseline effective on WWHN-333 Done

  Ngày cập nhật       23/07/2026

  Author/Owner        Nguyễn Đình Hùng - BA Owner

  Reviewer/Approver   PM/PO & Tech Lead: Nguyễn Quang Đức; Client Representative: Vũ Tuấn Cương; QA Lead: Đặng Thị Soi

  Phạm vi cập nhật    P1-01 đến P1-10: AC Given/When/Then, negative/concurrency/rollback, action-level RBAC, privacy, NFR định lượng, code_value, scope linh kiện bảo hành, report contract, import package và metadata/source register.

  Gate                BA baseline gate: WWHN-333 DONE với attachment/comment xác nhận v3.11 current. Technical/QA artifacts triển khai sau BRD baseline và phải hoàn tất trước core story Ready for Dev/QA.
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 20.2. Decision Matrix P0-4

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **ID**      **Chủ đề**            **Decision content v3.11**                                                                                                                                                **Rationale**                                                                                                                   **Decision owner / Approver**                                                                                                             **Formal status**
  ----------- --------------------- ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- ------------------------------------------------------------------------------------------------------------------------------- ----------------------------------------------------------------------------------------------------------------------------------------- ------------------------------------------------------------
  DM-01       Scope Beta            Stocktake, Warranty cơ bản và App PV đều Must/Beta. Future chỉ gồm chức năng nâng cao.                                                                                    Đều là năng lực lõi để vận hành/đánh giá Beta.                                                                                  Owner: Nguyễn Đình Hùng (BA). Approval route: Nguyễn Quang Đức qua WWHN-333 DONE; technical feasibility reviewed at Ready-for-Dev gate.   READY FOR BASELINE APPROVAL / EFFECTIVE WITH WWHN-333 DONE

  DM-02       Nền tảng App PV       App PV là Web App responsive cho desktop/tablet/mobile; native/offline thuộc P2/Future.                                                                                   Giảm rủi ro 30 ngày và phù hợp phạm vi tra cứu.                                                                                 Owner: Nguyễn Đình Hùng (BA). Approval route: Nguyễn Quang Đức qua WWHN-333 DONE; technical feasibility reviewed at Ready-for-Dev gate.   READY FOR BASELINE APPROVAL / EFFECTIVE WITH WWHN-333 DONE

  DM-03       Công nghệ định danh   Một Physical_Code. ITEM bắt buộc dùng chip. CARTON/BOX/TRAY dùng carrier cấu hình linh hoạt: QR, Barcode, RFID/NFC hoặc Manual. Hardware protocol chuyên sâu ngoài MVP.   Đáp ứng yêu cầu chip cho sản phẩm nhưng không khóa container/locator vào một loại phần cứng; giảm rủi ro chi phí và tích hợp.   Owner: Nguyễn Đình Hùng (BA). Approval route: Nguyễn Quang Đức qua WWHN-333 DONE; technical feasibility reviewed at Ready-for-Dev gate.   READY FOR BASELINE APPROVAL / EFFECTIVE WITH WWHN-333 DONE

  DM-04       Format SKU            Dùng format BRAND-CATEGORY-MODEL-POWER_OR_VOLTAGE-\[PACKAGE\]-VERSION. Mã cũ giữ alias.                                                                                   Một chuẩn duy nhất, hỗ trợ migration/tra cứu.                                                                                   Owner: Nguyễn Đình Hùng (BA). Approval route: Nguyễn Quang Đức qua WWHN-333 DONE; technical feasibility reviewed at Ready-for-Dev gate.   READY FOR BASELINE APPROVAL / EFFECTIVE WITH WWHN-333 DONE

  DM-05       Idempotency           Post Receipt, Post Issue, Apply Adjustment bắt buộc idempotent.                                                                                                           Mọi action đổi tồn phải chống retry/click lặp.                                                                                  Owner: Nguyễn Đình Hùng (BA). Approval route: Nguyễn Quang Đức qua WWHN-333 DONE; technical feasibility reviewed at Ready-for-Dev gate.   READY FOR BASELINE APPROVAL / EFFECTIVE WITH WWHN-333 DONE
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

#### 20.2.1. Formal Decision Status Register

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **ID**   **Effective date**                                                                      **Evidence/Link**                                                                                                                                                                 **Tài liệu bị tác động**
  -------- --------------------------------------------------------------------------------------- --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- -------------------------------------------------------
  DM-01    Decision recorded: 22/07/2026; baseline effective date: 23/07/2026 upon WWHN-333 DONE   Formal evidence: Jira transition/attachment history at [[WWHN-333]{.underline}](https://tan0110.atlassian.net/browse/WWHN-333). No separate pre-Done approval link is required.   BRD, Roadmap, Scope/Contract Mapping, UI/Traceability

  DM-02    Decision recorded: 22/07/2026; baseline effective date: 23/07/2026 upon WWHN-333 DONE   Formal evidence: Jira transition/attachment history at [[WWHN-333]{.underline}](https://tan0110.atlassian.net/browse/WWHN-333). No separate pre-Done approval link is required.   BRD, Roadmap, Screen Inventory, API App PV

  DM-03    Decision recorded: 22/07/2026; baseline effective date: 23/07/2026 upon WWHN-333 DONE   Formal evidence: Jira transition/attachment history at [[WWHN-333]{.underline}](https://tan0110.atlassian.net/browse/WWHN-333). No separate pre-Done approval link is required.   BRD, Data Model, Scan API, UI Scanner, Test

  DM-04    Decision recorded: 22/07/2026; baseline effective date: 23/07/2026 upon WWHN-333 DONE   Formal evidence: Jira transition/attachment history at [[WWHN-333]{.underline}](https://tan0110.atlassian.net/browse/WWHN-333). No separate pre-Done approval link is required.   BRD, SKU Master/API, Migration Mapping, Test

  DM-05    Decision recorded: 22/07/2026; baseline effective date: 23/07/2026 upon WWHN-333 DONE   Formal evidence: Jira transition/attachment history at [[WWHN-333]{.underline}](https://tan0110.atlassian.net/browse/WWHN-333). No separate pre-Done approval link is required.   BRD, NFR, API/OpenAPI, Traceability, Test
  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

Quy tắc hiệu lực hiện hành: WWHN-333 là approval workflow chính thức của BRD. Khi PM/PO transition task sang DONE, BRD v3.11 đính kèm và được xác nhận trong comment phê duyệt được coi là APPROVED/BASELINE, hiệu lực từ 23/07/2026. Các Decision Record có hiệu lực cùng baseline; v3.10 chỉ được giữ để truy vết lịch sử.

### 20.3. P0-5 - Stocktake không khóa kho

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **CÔNG THỨC CHÍNH THỨC**                                                          
  --------------------------------------------------------------------------------- -----------------------------------------------------------------------------------------------------------------------------
  **Tình huống**                                                                    **Quy tắc**

  expected_at_count = snapshot_qty + net_movement_after_snapshot_to_count_cutoff\   
  variance_to_apply = actual_qty - expected_at_count                                

  Cutoff                                                                            Session có stocktake_started_at; từng count có count_cutoff_at. Movement tính theo committed_at/posted_at.

  Movement giao cắt                                                                 Transaction chưa commit tại cutoff không tính vào expected; khi commit sau cutoff phải xuất hiện trong warning trước Apply.

  Partial approval                                                                  Variance line: PENDING → APPROVED/WAIVED → APPLIED. Chỉ APPROVED sinh adjustment.

  Transaction boundary                                                              Balance, movement, variance line và session status cập nhật atomically trong Apply.

  Stale session                                                                     Dùng session_version/If-Match; version cũ bị chặn, không cập nhật tồn.

  Idempotency                                                                       Idempotency-Key bắt buộc; retry không sinh double ADJUST_IN/ADJUST_OUT.

  Ví dụ bắt buộc                                                                    Snapshot 100; Issue -10; Actual 90 → Expected 90; Variance 0.
  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 20.4. P0-6 - Warranty và sửa sai sau Post

  ----------------------------------------------------------------------------------------------------------------------------------------------------
  **Nội dung**                        **Baseline**
  ----------------------------------- ----------------------------------------------------------------------------------------------------------------
  Warranty cycle                      ISSUED hoặc RETURNED_TO_CUSTOMER → IN_WARRANTY → RETURNED_TO_CUSTOMER.

  Concurrent case                     Tối đa một active case/item; unique constraint hoặc transactional guard.

  Reverse Receipt                     Sinh RECEIPT_REVERSED tham chiếu movement gốc; không sửa/xóa RECEIPT_POSTED.

  Reverse Issue                       Chỉ sinh ISSUE_REVERSED và cộng Available khi hàng vật lý đã về kho, condition hợp lệ.

  Correction/Return                   Dùng chứng từ riêng cho trả hàng/sửa sai hợp lệ; không dùng stocktake để che lỗi Post.

  Audit                               Bắt buộc reason, actor, permission, original_document_id, original_movement_id, timestamps và Idempotency-Key.

  Report                              Báo cáo XNT cộng movement gốc và movement đảo theo qty_delta; không loại movement lịch sử.
  ----------------------------------------------------------------------------------------------------------------------------------------------------

### 20.5. P0-7 - API/UI/Traceability closure

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Nhóm**            **API/action tối thiểu**                                           **Permission/Idempotency**             **Trạng thái tài liệu**
  ------------------- ------------------------------------------------------------------ -------------------------------------- ---------------------------------------------
  Identity & Access   User/role/permission CRUD; assign/revoke                           Admin; audit; no stock effect          Contract required; OpenAPI evidence pending

  Master Data         Warehouse, Agency, Category, Brand, Model, Defect CRUD             Admin/QL kho; 403/404 scope            Defined at BRD level

  Inventory Query     Balance, movement history, item/package trace                      Role/data scope; export permission     Defined; API sample pending

  Inbound             Create/detail/update draft/scan/post/reverse/cancel                Post/Reverse idempotent                Defined

  Outbound            Create/detail/update draft/scan/post/reverse/cancel                Post/Reverse idempotent                Defined

  Stocktake           Create/snapshot/count/submit/review/partial approve/apply/cancel   Apply idempotent + version             Defined

  Warranty            Create/process/return/close; active-case guard                     Role + 409 guard                       Defined

  Import/Migration    Template/validate/dry-run/commit/error export/reconcile/rollback   Commit batch duplicate protection      Defined; data file pending

  Media               Upload/metadata/delete/permission/type-size validation             Admin/content role                     Defined as Must for App PV

  Reports             XNT/current balance/movement/stocktake/warranty/export             Read/export permission                 Defined

  App PV              List/detail/search/filter/recommendation/publish                   Public/published read; admin publish   Defined web responsive
  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------

#### 20.5.1. UI state rule

  ------------------------------------------------------------------------------------------------------------------------------
  **State**                           **Yêu cầu chung**
  ----------------------------------- ------------------------------------------------------------------------------------------
  Loading                             Không cho double submit; hiển thị progress với import/post/apply.

  Empty                               Thông báo rõ không có dữ liệu và action hợp lệ để tiếp tục.

  Validation                          Field-level error; không mất dữ liệu form; map error.code.

  403/404                             Ẩn/disable action; detail ngoài scope dùng 404 theo policy, action thiếu quyền dùng 403.

  409/412/422                         State/idempotency/stale/business validation hiển thị thông báo có hướng xử lý.

  Success                             Hiển thị document/movement/batch ID và trạng thái sau action.

  Responsive                          Màn App PV và tác vụ scan thiết yếu dùng được trên mobile/tablet/desktop.
  ------------------------------------------------------------------------------------------------------------------------------

#### 20.5.2. Traceability Master convention

Traceability được kiểm soát theo ba gate: (1) BA Baseline: Contract/Customer Input → Requirement → Feature/Rule → Screen/Action → AC → TC ID, zero orphan/duplicate trong DOCX; (2) Ready for Dev/QA: gắn Jira/OpenAPI/Figma hoặc screen baseline/TestCase Master; (3) Execution/Release: gắn test run, log, screenshot và sign-off. Evidence ở gate sau không được yêu cầu ngược để chặn BRD baseline.

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Coverage**                **Mục tiêu**                                                            **Hiện trạng v3.11**
  --------------------------- ----------------------------------------------------------------------- -------------------------------------------------------------------------------
  BA Must requirement         100% có Feature/BR/Screen/API intent/AC/TC ID trong BRD                 PASS DOCUMENT-LEVEL; official BRD Jira WWHN-333

  Document orphan/duplicate   0 orphan Must; 0 orphan TC definition; 0 conflicting authoritative ID   PASS trong BRD/SRS registry; external reconciliation follows downstream tasks

  API/technical trace         REQ/AC map OpenAPI/data/permission before Ready for Dev                 PLANNED AFTER BRD BASELINE - WWHN-31

  QA design trace             REQ/AC/API map TestCase Master before Ready for QA                      PLANNED AFTER BRD BASELINE - WWHN-33

  Execution evidence          TC map test run/log/screenshot/defect/sign-off before release           NOT EXECUTED BY DESIGN; not a BRD Done prerequisite
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 20.6. P0-8 - Data Migration & Opening Balance Plan

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Dataset**                                                                                                                                                                                                                                                                                                                          **Nguồn dự kiến**                            **Owner nguồn/xác nhận**                                                                                                                               **Readiness**           **Quy tắc**
  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ -------------------------------------------- ------------------------------------------------------- ---------------------------------------------------------------------------------------------- ----------------------- -------------------------------------------------------------
  Bước                                                                                                                                                                                                                                                                                                                                                                                                                                      Nội dung/Exit criteria                                                                                                 

  Product/SKU master                                                                                                                                                                                                                                                                                                                   Excel Hoa Nam - chưa nhận file               Quản lý kho Hoa Nam / Vũ Tuấn Cương                                                                                                                    Pending customer file   Map sang SKU mới; mã cũ giữ legacy_sku_code/alias.

  Opening stock                                                                                                                                                                                                                                                                                                                        Excel và/hoặc kiểm đếm thực tế tại cutover   Quản lý kho Hoa Nam / Vũ Tuấn Cương                                                                                                                    Pending cutoff/file     Kiểm đếm ký xác nhận là source of truth khi lệch Excel.

  Item/Serial/Barcode/Chip                                                                                                                                                                                                                                                                                                             Excel nếu có + scan/kiểm đếm                 Quản lý kho Hoa Nam / Vũ Tuấn Cương                                                                                                                    Pending                 Unique code; map SKU; duplicate/invalid đưa error file.

  Carton/Box/Tray mapping                                                                                                                                                                                                                                                                                                              Excel nếu có + scan thực tế                  Quản lý kho Hoa Nam / Vũ Tuấn Cương                                                                                                                    Pending                 Không double count container; validate hierarchy/mixed-SKU.

  Agency                                                                                                                                                                                                                                                                                                                               Excel                                        Quản lý kho Hoa Nam / Vũ Tuấn Cương                                                                                                                    Pending                 Agency là receiver, không phải warehouse.

  User/Role                                                                                                                                                                                                                                                                                                                            Danh sách triển khai                         Nguyễn Quang Đức + Vũ Tuấn Cương                                                                                                                       Pending                 Least privilege; tài khoản tạm có expiry.

  Defect catalog                                                                                                                                                                                                                                                                                                                       Excel/danh sách nghiệp vụ                    Quản lý kho Hoa Nam / Vũ Tuấn Cương                                                                                                                    Pending                 Chuẩn code/name; cho phép mô tả bổ sung.

  App PV content                                                                                                                                                                                                                                                                                                                       Excel + thư mục ảnh                          Vũ Tuấn Cương / người quản trị nội dung được ủy quyền                                                                                                  Pending                 Kiểm tra quyền sở hữu ảnh, metadata và publish status.

  1\. Receive & profile                                                                                                                                                                                                                                                                                                                                                                                                                     Nhận Excel, checksum, thống kê field/duplicate/invalid; không chỉnh file gốc.                                          

  2\. Mapping & cleansing                                                                                                                                                                                                                                                                                                                                                                                                                   Map source-target, chuẩn SKU mới, alias mã cũ, normalize UOM/status/code.                                              

  3\. Dry-run                                                                                                                                                                                                                                                                                                                                                                                                                               Không thay đổi production data; xuất valid/invalid/duplicate report.                                                   

  4\. Correct & re-run                                                                                                                                                                                                                                                                                                                                                                                                                      QL kho sửa dữ liệu; lặp dry-run đến khi đạt ngưỡng được duyệt.                                                         

  5\. Cutoff/freeze                                                                                                                                                                                                                                                                                                                                                                                                                         Chốt thời gian; backup; khóa batch migration, không nhất thiết khóa nghiệp vụ toàn hệ thống.                           

  6\. Opening count                                                                                                                                                                                                                                                                                                                                                                                                                         Ưu tiên kiểm đếm thực tế tại cutover; Excel dùng seed/đối chiếu.                                                       

  7\. Commit                                                                                                                                                                                                                                                                                                                                                                                                                                Batch-level duplicate protection; audit user/time/file/checksum.                                                       

  8\. Reconciliation                                                                                                                                                                                                                                                                                                                                                                                                                        Đối soát SKU/item/package/kho tổng; QL kho ký.                                                                         

  9\. Rollback                                                                                                                                                                                                                                                                                                                                                                                                                              Nếu sai nghiêm trọng: restore backup hoặc reverse migration batch, không sửa tay lịch sử.                              

  P0-8 CONTROL                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     

  Ticket đã tạo; owner Duy Khánh Nguyễn; dependency là WWHN-333 DONE. Due date được PM/PO thiết lập khi kích hoạt ticket và bắt buộc có trước khi WWHN-69 chuyển IN PROGRESS. Exit criteria: source file/checksum, mapping, dry-run, error resolution, opening balance reconciliation, QL kho/client sign-off và rollback reference.                                                                                                                                                                                                                               
  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 20.7. P0-9 - UAT, Cutover và Handover

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Vai trò**                                                                                                                                                                                                                                                                                                                                                                       **Điều kiện/Gate**                                                                                                                                                                                                            **Người cụ thể**
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- --------------------------------------------------------------------------------------- ------------------------------------------------------------------------------------------------------------------------------------- -------------------------------------------------------------------------------------------------
  Severity                                                                                                                                                                                                                                                                                                                                                                          Định nghĩa                                                                                                                                                                                                                    Gate/Response

  Hạng mục cutover                                                                                                                                                                                                                                                                                                                                                                                                                                                          Điều kiện                                                                                                                             

  Tài sản bàn giao                                                                                                                                                                                                                                                                                                                                                                                                                                                          Hiện trạng                                                                                                                            

  UAT executors                                                                                                                                                                                                                                                                                                                                                                     QL kho, NV kho, NV bảo hành, đại diện khách hàng/App PV thực thi script                                                                                                                                                       UAT Owner: Nguyễn Đình Hùng; business users Hoa Nam được Vũ Tuấn Cương chỉ định trước execution

  Customer sign-off                                                                                                                                                                                                                                                                                                                                                                 Đại diện có thẩm quyền Hoa Nam hoặc người được ủy quyền                                                                                                                                                                       Vũ Tuấn Cương

  Defect coordination                                                                                                                                                                                                                                                                                                                                                               Tester + PM phối hợp thay QA Lead; BA xác nhận nghiệp vụ; Tech Lead xác nhận kỹ thuật                                                                                                                                         QA: Đặng Thị Soi; BA: Nguyễn Đình Hùng; Tech/PM: Nguyễn Quang Đức

  Go/No-Go/Rollback decision                                                                                                                                                                                                                                                                                                                                                        PM/PO quyết định dựa trên UAT, migration, technical health và xác nhận khách hàng                                                                                                                                             Nguyễn Quang Đức

  Hypercare                                                                                                                                                                                                                                                                                                                                                                         7 ngày làm việc sau Go-live; ưu tiên lỗi nhập/xuất/tồn/kiểm kê/access                                                                                                                                                         Nguyễn Quang Đức phân công trước cutover

  S1 - Critical                                                                                                                                                                                                                                                                                                                                                                     Mất dữ liệu, sai tồn diện rộng, không thể nhập/xuất, lỗi bảo mật nghiêm trọng                                                                                                                                                 Block Go-live/rollback; xử lý ngay

  S2 - Major                                                                                                                                                                                                                                                                                                                                                                        Must flow không dùng được, sai tồn có phạm vi, không có workaround chấp nhận                                                                                                                                                  Block sign-off trừ khi waiver PM/PO + customer

  S3 - Medium                                                                                                                                                                                                                                                                                                                                                                       Chức năng phụ lỗi hoặc có workaround an toàn                                                                                                                                                                                  Có thể go-live với plan/fix date

  S4 - Minor                                                                                                                                                                                                                                                                                                                                                                        UI/text/cosmetic, không ảnh hưởng nghiệp vụ                                                                                                                                                                                   Backlog/hypercare

  Pre-cutover                                                                                                                                                                                                                                                                                                                                                                                                                                                               UAT Must pass/waiver; backup/restore drill; migration rehearsal; opening count plan; contact list.                                    

  Go-live                                                                                                                                                                                                                                                                                                                                                                                                                                                                   Commit migration, reconcile, smoke test Post Receipt/Post Issue/Balance/App PV, customer confirmation.                                

  Rollback trigger                                                                                                                                                                                                                                                                                                                                                                                                                                                          S1; reconciliation không khớp; không thể khôi phục core flow trong cửa sổ quyết định.                                                 

  Post-cutover                                                                                                                                                                                                                                                                                                                                                                                                                                                              Hypercare 7 ngày; daily defect review; monitor idempotency, balance và error logs.                                                    

  Handover                                                                                                                                                                                                                                                                                                                                                                                                                                                                  Source/repository, accounts, config, domain/VPS, licenses, DB schema, OpenAPI, runbook, HDSD, training, backup/restore và biên bản.   

  Source code/repository                                                                                                                                                                                                                                                                                                                                                                                                                                                    Chưa có evidence                                                                                                                      

  Server/VPS/domain                                                                                                                                                                                                                                                                                                                                                                                                                                                         Chưa có evidence                                                                                                                      

  DEV/UAT/Production accounts                                                                                                                                                                                                                                                                                                                                                                                                                                               Chưa có evidence                                                                                                                      

  Backup/restore procedure & drill report                                                                                                                                                                                                                                                                                                                                                                                                                                   Chưa có                                                                                                                               

  HDSD/training/attendance                                                                                                                                                                                                                                                                                                                                                                                                                                                  Chưa có                                                                                                                               

  OpenAPI/config/license inventory                                                                                                                                                                                                                                                                                                                                                                                                                                          Chưa có                                                                                                                               

  UAT sign-off/cutover/reconciliation record                                                                                                                                                                                                                                                                                                                                                                                                                                Chưa thực thi                                                                                                                         

  P0-9 CONTROL                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    

  Ticket đã tạo; UAT Owner Nguyễn Đình Hùng; QA Owner Đặng Thị Soi; PM/PO Nguyễn Quang Đức; Client sign-off Vũ Tuấn Cương. Dependency: WWHN-333 DONE, API/test design và migration rehearsal sẵn sàng. Due date được PM/PO thiết lập khi kích hoạt ticket, trước khi WWHN-75 chuyển IN PROGRESS. Exit: Must-pass đạt, blocker đóng/waive, evidence đầy đủ và khách hàng sign-off.                                                                                                                                                                                                                                 
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 20.8. P1 và P2/Future boundary

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Mức**                             **Nội dung**
  ----------------------------------- -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  P1 - hoàn thiện tuần 1-2            OpenAPI machine-readable, physical ERD, Figma chi tiết, TestCase Master, contract test, automation và execution evidence được thực hiện sau BRD baseline. Đây là điều kiện trước Ready for Dev/QA hoặc Release, không phải điều kiện để BA transition WWHN-333 sang Done.

  P2/Future                           Native/offline App PV; hardware driver/gateway/protocol RFID/NFC chuyên sâu; multi-warehouse/tồn đại lý; bán hàng/đặt mua/thanh toán; bảo hành chi phí/người sửa/SLA; AI recommendation; analytics nâng cao. Nếu yêu cầu mới làm thay đổi đáng kể scope/chi phí/tiến độ/deliverable thì áp dụng Change Request.
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 20.9. Historical Change Log v3.10 - SUPERSEDED BY v3.11

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Change ID**     **Hạng mục**           **Thay đổi chính**                                                                                                                                         **Tài liệu bị tác động**
  ----------------- ---------------------- ---------------------------------------------------------------------------------------------------------------------------------------------------------- ---------------------------------------
  CHG-39-01         P0-4                   Chốt scope Beta, web responsive, Physical_Code/chip, SKU format, idempotency                                                                               BRD, roadmap, API, UI, traceability

  CHG-39-02         P0-5                   Sửa công thức stocktake, cutoff, partial approval, stale session                                                                                           BRD/SRS/API/AC/TC/report

  CHG-39-03         P0-6                   Warranty lặp, active-case guard, reversal append-only                                                                                                      State/API/UI/permission/report/TC

  CHG-39-04         P0-7                   Bổ sung API/UI gap inventory, trace convention và coverage gate                                                                                            BRD + Handoff Traceability v3.10

  CHG-39-05         P0-8                   Bổ sung data readiness, migration/opening balance, reconciliation/rollback                                                                                 BRD/UAT/cutover

  CHG-39-06         P0-9                   Bổ sung UAT/severity/cutover/hypercare/handover                                                                                                            BRD/UAT/handover package

  CHG-39-07         P0 cleanup             Đồng bộ NFR-CON-02: bắt buộc idempotency cho Post Receipt/Post Issue/Apply Adjustment/reversal; chuẩn hóa BR-COUNT và formal Decision Status Register.     BRD + Handoff Traceability v3.10

  CHG-310-08        P0-01..P0-08 closure   Bổ sung approval-by-Jira-Done, contract basis thật, staged traceability, v3.7/v3.9 supersession, named Migration/UAT owners và Ready-for-Dev sequencing.   BRD v3.10 + Jira WWHN-333/31/33/69/75
  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 21. P0 REMEDIATION PACKAGE - REVIEW 22/07/2026

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  Trạng thái kiểm soát: BRD v3.11 là FINAL APPROVAL CANDIDATE. Formal approval không cần tồn tại trước Jira Done; chính transition WWHN-333 sang DONE là sự kiện phê duyệt, attachment v3.11 tại thời điểm đó là baseline có hiệu lực 23/07/2026. Hợp đồng số 07072026/HOANAM-A4I đã được ghi nhận làm căn cứ thương mại; không có phụ lục (NOT APPLICABLE). Technical artifacts được thực hiện ở gate Ready for Dev/QA sau BRD baseline.
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 21.1. Document status và nguyên tắc phê duyệt

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Hạng mục**          **Giá trị hiện tại**                                           **Điều kiện chuyển trạng thái**
  --------------------- -------------------------------------------------------------- ----------------------------------------------------------------------------------------------------------
  Document version      BRD Hoa Nam v3.11 - current approval attachment                Canonical Jira/source: [[WWHN-333]{.underline}](https://tan0110.atlassian.net/browse/WWHN-333)

  Current status        FINAL APPROVAL CANDIDATE; APPROVED/BASELINE ON WWHN-333 DONE   Approval evidence = Jira transition history + v3.11 attachment at Done; effective date 23/07/2026.

  Decision content      PROJECT SOLUTION DECISIONS INCLUDED IN BRD BASELINE            Không yêu cầu ký riêng từng Decision Record; PM/PO approval của BRD bao phủ các quyết định.

  Core-flow readiness   BUSINESS BASELINE READY; TECHNICAL RFD GATE FOLLOWS            OpenAPI/physical ERD/Figma/TestCase phải hoàn tất trước story core Ready for Dev/QA, không chặn BA Done.
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 21.2. Baseline Index - bundle nghiệm thu SRS/FRD

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Artifact**               **Document ID / Version**                                                                                                                       **Vai trò**                                **Trạng thái**                      **Approval/Evidence**
  -------------------------- ----------------------------------------------------------------------------------------------------------------------------------------------- ------------------------------------------ ----------------------------------- -------------------------------------------------------
  SRS/FRD chính thức         HN-SRS-FRD-001 v1.1                                                                                                                             Functional/technical requirements          READY FOR FORMAL REVIEW             Downstream from BRD baseline

  BRD nghiệp vụ              HN-BRD-WM-PV v3.11                                                                                                                              Official business scope baseline           CURRENT FINAL APPROVAL CANDIDATE    WWHN-333 Done = approval evidence

  API/Traceability Handoff   HN-HANDOFF-WM-PV v3.10 (reference only)                                                                                                         Business/API handoff context               CONTENT READY                       Technical review follows BRD

  ERD                        Logical model in BRD §5/14.4; physical ERD post-baseline                                                                                        Data baseline                              BA LOGICAL BASELINE READY           Tech Lead approval before core story RFD

  Data Dictionary            Embedded in BRD/SRS                                                                                                                             Field/data semantics                       BA BASELINE READY                   Physical constraints approved at RFD

  OpenAPI YAML               [[WWHN-31]{.underline}](https://tan0110.atlassian.net/browse/WWHN-31)                                                                           Machine-readable API baseline              WAITING FOR BRD BASELINE            Tech Lead + QA before RFD/QA

  Screen/Figma               BRD §15-16; detailed Figma post-baseline                                                                                                        UI/action baseline                         BA SCREEN BASELINE READY            Detailed UX before FE story RFD

  Action Permission Matrix   BRD §3.1 + action rules                                                                                                                         Action authorization                       BA BASELINE READY                   Map endpoint permissions in WWHN-31

  Contract-test Evidence     [[WWHN-31]{.underline}](https://tan0110.atlassian.net/browse/WWHN-31) + [[WWHN-33]{.underline}](https://tan0110.atlassian.net/browse/WWHN-33)   Contract/test evidence                     POST-IMPLEMENTATION                 Not a BRD Done prerequisite

  Contract Coverage Matrix   HN-CCM-WM-PV v1.0 embedded                                                                                                                      Contract → scope group → detailed source   SOURCE BASIS RECORDED               Contract file must be attached/accessible in WWHN-333

  Migration/Data Readiness   [[WWHN-69]{.underline}](https://tan0110.atlassian.net/browse/WWHN-69)                                                                           Migration/opening balance                  PLANNED AFTER BRD BASELINE          Owner Duy Khánh Nguyễn; due date at activation

  UAT/Cutover                [[WWHN-75]{.underline}](https://tan0110.atlassian.net/browse/WWHN-75)                                                                           UAT/Go-No-Go/sign-off                      PLANNED AFTER TECHNICAL READINESS   Named BA/QA/PM/Client owners
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 21.3. Decision Records chính thức

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Decision ID**   **Chủ đề**                  **Quyết định**                                                                                                                                                                          **Nguồn/Reason**                                                            **Formal approver**                                                                         **Status / Effective date**
  ----------------- --------------------------- --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- --------------------------------------------------------------------------- ------------------------------------------------------------------------------------------- ---------------------------------------------------------------
  DR-PLAT-001       Platform App PV             App PV triển khai dưới dạng Web App responsive. Native app và offline ngoài MVP.                                                                                                        BA chốt 22/07/2026; giảm rủi ro triển khai và phù hợp scope read-only.      Nguyễn Quang Đức (PM/PO); client scope represented by Vũ Tuấn Cương through BRD review      READY FOR APPROVAL; EFFECTIVE 23/07/2026 WHEN WWHN-333 = DONE

  DR-CARRIER-001    Carrier theo object         ITEM bắt buộc dùng chip. CARTON/BOX/TRAY dùng carrier cấu hình linh hoạt: QR, BARCODE, RFID/NFC hoặc MANUAL. Physical_Code duy nhất và carrier-agnostic. Hardware protocol ngoài MVP.   Giải quyết mâu thuẫn nguồn và tránh khóa phần cứng cho container/locator.   Nguyễn Quang Đức (PM/PO/Tech Lead); client requirement basis represented by Vũ Tuấn Cương   READY FOR APPROVAL; EFFECTIVE 23/07/2026 WHEN WWHN-333 = DONE

  DEC-INV-001       Inventory Source of Truth   Inventory_Movement là append-only authoritative ledger; Inventory_Balance là projection; Item/Container subledger là lớp vật lý. Cả ba cập nhật trong cùng transaction.                 Đảm bảo audit, idempotency, reconciliation và rollback nguyên tử.           Nguyễn Quang Đức (Tech Lead/PM); QA testability by Đặng Thị Soi at downstream gate          READY FOR APPROVAL; EFFECTIVE 23/07/2026 WHEN WWHN-333 = DONE

  DEC-PARTIAL-001   Partial Receipt/Issue       Cho phép post thiếu và post nhiều lần; không cho over-receipt trong MVP; không cho issue vượt remaining/available; line OPEN → PARTIALLY_POSTED → CLOSED hoặc REMAINDER_CANCELLED.      Đóng logic expected/received và planned/issued.                             Nguyễn Quang Đức (PM/Tech); QA testability by Đặng Thị Soi; business need recorded by BA    READY FOR APPROVAL; EFFECTIVE 23/07/2026 WHEN WWHN-333 = DONE
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 21.4. DEC-INV-001 - authoritative ledger và reconciliation

• Inventory_Movement là sổ biến động authoritative, append-only; không update/delete movement đã post.

• Inventory_Balance là projection hiện tại theo warehouse_id + sku_id + stock_status.

• Item_Instance là subledger vật lý cho SKU serial-tracked; Container_Item/box quantity là subledger cho SKU quantity-only.

• Post Receipt, Post Issue, Reversal và Apply Adjustment phải ghi movement, cập nhật balance, subledger và trạng thái chứng từ trong cùng transaction; lỗi ở bất kỳ bước nào phải rollback toàn bộ.

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Đối soát**            **Công thức / điều kiện**                                                           **Kết quả khi lệch**
  ----------------------- ----------------------------------------------------------------------------------- ---------------------------------------------------------
  Ledger → Balance        Opening + Receipt - Issue + Adjust In - Adjust Out + Reversal = Inventory_Balance   Tạo Reconciliation Incident; không tự sửa/xóa movement.

  Serial subledger        available_qty = số Item_Instance ở IN_STOCK/AVAILABLE                               Chặn sign-off; điều tra item state và movement.

  Quantity subledger      available_qty = tổng quantity khả dụng trong box/subledger                          Chặn sign-off; điều tra mapping/quantity và movement.

  Divergence resolution   Chỉ dùng reversal hoặc adjustment có quyền, lý do, audit và idempotency             Không dùng UPDATE trực tiếp để che lệch.
  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 21.5. DEC-PARTIAL-001 - partial receipt/issue

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Nghiệp vụ**     **Rule**                                                                                        **State/Field**                                                                           **API/AC/TC tối thiểu**
  ----------------- ----------------------------------------------------------------------------------------------- ----------------------------------------------------------------------------------------- ----------------------------------------------------------------------------------
  Partial Receipt   Cho received_qty \< expected_qty và post nhiều lần. Không cho over-receipt trong MVP.           remaining_qty, posted_qty, line_status OPEN/PARTIALLY_POSTED/CLOSED/REMAINDER_CANCELLED   POST post-receipt theo confirmed_lines; AC-PART-IN-001..004; TC-PART-IN-001..005

  Partial Issue     Cho issued_qty \< planned_qty và post nhiều lần. Không vượt remaining_qty hoặc available_qty.   remaining_qty, issued_qty, line_status OPEN/PARTIALLY_POSTED/CLOSED/REMAINDER_CANCELLED   POST post-issue theo confirmed_lines; AC-PART-OUT-001..005; TC-PART-OUT-001..006

  Close remainder   Chỉ user có quyền được đóng phần còn lại, bắt buộc reason.                                      remainder_cancelled_qty, close_reason, closed_by, closed_at                               POST /lines/{line_id}/close-remainder; audit bắt buộc.

  Report effect     Báo cáo thể hiện Planned/Expected, Posted, Remaining và Cancelled Remainder.                    Không coi remaining là tồn đã nhập/xuất.                                                  TC-RPT-PART-001..002

  Idempotency       Mỗi lần post là một posting event riêng với Idempotency-Key riêng.                              posting_sequence, idempotency_key, movement_key                                           Retry cùng key không tạo movement lần hai.
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 21.6. Source Supersession Register

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Nguồn cũ / Mô tả**                                          **Trạng thái**       **Nội dung thay thế**                                                   **Decision controlling**
  ------------------------------------------------------------- -------------------- ----------------------------------------------------------------------- -------------------------------------------------
  Nguồn ghi “App local” hoặc diễn giải không rõ platform        SUPERSEDED FOR MVP   App PV = Web App responsive; native/offline Future                      DR-PLAT-001

  Nguồn/BRD cũ mặc định chip cho ITEM/CARTON/BOX/TRAY           SUPERSEDED           ITEM bắt buộc chip; CARTON/BOX/TRAY carrier linh hoạt                   DR-CARRIER-001

  State nhập/xuất chỉ DRAFT/SCANNING/POSTED/CANCELLED           SUPERSEDED           Bổ sung PARTIALLY_POSTED/CLOSED và close remainder                      DEC-PARTIAL-001

  Mô tả Balance/Movement/subledger không chỉ rõ authoritative   SUPERSEDED           Movement ledger authoritative; Balance projection; subledger physical   DEC-INV-001

  BR-SCAN-02 dùng cho hai nội dung                              NORMALIZED           BR-SCAN-02 giữ nghĩa scan batch; demo scanner dùng BR-SCAN-ADD-02       ID-NORM-001

  TC-PTW-01                                                     SUPERSEDED ALIAS     Chuẩn hóa thành TC-PUT-01                                               ID-NORM-002

  BRD Hoa Nam v3.7                                              SUPERSEDED           BRD Hoa Nam v3.11 là bản current approval attachment                    BRD Source Register + WWHN-333 approval comment

  BRD Hoa Nam v3.9                                              SUPERSEDED           BRD Hoa Nam v3.11 là bản current approval attachment                    BRD Source Register + WWHN-333 approval comment

  BRD Hoa Nam v3.10                                             SUPERSEDED           BRD Hoa Nam v3.11 thay thế sau khi hoàn thiện P1-01..P1-10              BRD Source Register + WWHN-333 approval comment
  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 21.7. Traceability integrity gate

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Quy ước authoritative: BRD/SRS định nghĩa Requirement/Rule/AC/TC ID và đạt document-level integrity. Jira/OpenAPI/Figma/TestCase/evidence được gắn theo vòng đời delivery sau khi WWHN-333 Done; việc chưa thực thi downstream artifact không tạo orphan ở BA baseline.**                                                                                             
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- ----------------------------------------------------------------------------------------- --------------------------------------------------------------------------------------------
  Gate                                                                                                                                                                                                                                                                          Kết quả trong bộ tài liệu                                                                 Điều kiện còn lại

  Duplicate ID                                                                                                                                                                                                                                                                  0 conflicting authoritative definitions trong phần chuẩn hóa; known conflict đã đổi ID.   Đối soát external trong WWHN-31/WWHN-33 trước RFD/QA.

  Orphan Must                                                                                                                                                                                                                                                                   0 orphan Must tại document-level registry.                                                Jira story decomposition được tạo sau baseline; không phải precondition của WWHN-333 Done.

  Orphan TC                                                                                                                                                                                                                                                                     0 orphan TC definition trong BRD/SRS registry.                                            QA import/mapping tại WWHN-33 trước Ready for QA.

  Trace chain                                                                                                                                                                                                                                                                   Source → REQ → BR/Decision → AC → API/UI/Data → TC → Evidence Location                    Execution evidence chỉ bắt buộc ở QA/UAT/Release gate.
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 21.8. Formal approval gate

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Vai trò**             **Tên người phê duyệt**   **Decision/Artifact**                                                **Trạng thái**                                  **Ngày hiệu lực / Evidence**
  ----------------------- ------------------------- -------------------------------------------------------------------- ----------------------------------------------- ---------------------------------------------------
  BA Owner                Nguyễn Đình Hùng          Nội dung BRD/SRS/Traceability                                        PREPARED / SUBMITTED                            23/07/2026; WWHN-333

  PM/PO                   Nguyễn Quang Đức          Scope, priority, baseline bundle                                     APPROVAL EVENT = JIRA DONE                      WWHN-333 transition history; effective 23/07/2026

  Client Representative   Vũ Tuấn Cương             Business scope/contract representation                               CONCURRENCE CONSOLIDATED BY PM/PO APPROVAL      Contract 07072026/HOANAM-A4I + WWHN-333

  Tech Lead               Nguyễn Quang Đức          BRD feasibility/decision alignment; technical artifacts downstream   BRD APPROVAL VIA WWHN-333; RFD APPROVAL LATER   WWHN-31 and technical gate

  QA Lead                 Đặng Thị Soi              BRD testability; TestCase/execution downstream                       BRD REVIEW; QA DESIGN/EXECUTION LATER           WWHN-33 and UAT evidence
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 22. P0 FINAL CLOSURE REGISTER - 23/07/2026

Mục 22 là nguồn kiểm soát cuối cho P0-01 đến P0-08. Khi có khác biệt với trạng thái cũ trong tài liệu, nội dung tại đây được ưu tiên. Tài liệu không giả lập approval hoặc execution evidence: WWHN-333 Done là formal approval event; các artifact kỹ thuật và test được thực hiện theo gate sau BRD baseline.

### 22.1. Approval mechanism và effective date

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Vai trò**             **Người phụ trách**   **Phạm vi**                                               **Trạng thái/Mechanism**                                       **Evidence**
  ----------------------- --------------------- --------------------------------------------------------- -------------------------------------------------------------- ---------------------------------------------------------------------------
  BA Owner                Nguyễn Đình Hùng      Chuẩn bị nội dung, traceability và submission             PREPARED / SUBMITTED 23/07/2026                                [[WWHN-333]{.underline}](https://tan0110.atlassian.net/browse/WWHN-333)

  PM/PO                   Nguyễn Quang Đức      Phê duyệt scope và baseline                               Transition WWHN-333 sang DONE là approval event                Jira transition history + BRD v3.11 attachment/comment tại thời điểm Done

  Client Representative   Vũ Tuấn Cương         Đại diện phạm vi kinh doanh và hợp đồng                   Sự đồng thuận được PM/PO xác nhận khi phê duyệt BRD            Contract 07072026/HOANAM-A4I + WWHN-333

  Tech Lead               Nguyễn Quang Đức      Feasibility ở mức BRD; technical artifacts ở RFD          BRD approval cùng WWHN-333; technical approval ở WWHN-31/RFD   [[WWHN-31]{.underline}](https://tan0110.atlassian.net/browse/WWHN-31)

  QA Lead                 Đặng Thị Soi          Testability ở mức BRD; test design/execution downstream   BRD review; QA design tại WWHN-33; execution tại UAT           [[WWHN-33]{.underline}](https://tan0110.atlassian.net/browse/WWHN-33)
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

**Formal status rule: trước khi WWHN-333 Done, trạng thái là FINAL APPROVAL CANDIDATE. Ngay khi PM/PO transition WWHN-333 sang Done và xác nhận attachment v3.11 là bản hiện hành, BRD v3.11 trở thành APPROVED/BASELINE với ngày hiệu lực 23/07/2026. Jira transition history, attachment v3.11 và comment phê duyệt là formal approval evidence.**

### 22.2. Contract Register và căn cứ phạm vi

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Trường**                       **Giá trị**
  -------------------------------- --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  Số hợp đồng                      07072026/HOANAM-A4I

  Tên hợp đồng                     HỢP ĐỒNG TƯ VẤN VÀ CUNG CẤP GIẢI PHÁP CHUYỂN ĐỔI SỐ - HỆ THỐNG KHO THÔNG MINH VÀ ỨNG DỤNG ĐẠI LÝ

  Ngày ký / hiệu lực               21/07/2026 / 21/07/2026

  Bên A                            CÔNG TY CỔ PHẦN THƯƠNG MẠI ĐIỆN MÁY HOA NAM

  Bên B                            CÔNG TY TNHH A4I

  Người quản lý/xác nhận phạm vi   Vũ Tuấn Cương

  Phụ lục                          NOT APPLICABLE - không phát hành phụ lục cho hợp đồng hiện tại

  Phạm vi rút gọn                  Khảo sát và phân tích quy trình kho/đại lý; xây dựng và triển khai WMS cùng ứng dụng đại lý; tích hợp thiết bị theo thiết kế; thử nghiệm các luồng nhập, xuất, kiểm kê và bảo hành; đào tạo, chuyển giao, vận hành chính thức và bảo hành/bảo trì giải pháp.

  Source evidence control          Contract file đã được đính kèm/liên kết tại [[WWHN-333 comment 12625]{.underline}](https://tan0110.atlassian.net/browse/WWHN-333?focusedCommentId=12625). Metadata trong BRD dùng để kiểm soát coverage; file hợp đồng gốc là evidence thương mại.
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

#### 22.2.1. Contract Coverage Matrix hai tầng

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Nhóm yêu cầu**                              **Contractual basis**                                            **Detailed scope basis**                     **Kết luận/Control**
  --------------------------------------------- ---------------------------------------------------------------- -------------------------------------------- ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  Hệ thống quản lý kho Hoa Nam                  Hợp đồng 07072026/HOANAM-A4I - Điều 1, khảo sát/phát triển WMS   BRD v3.11 - Scope, Epic A-I                  Thuộc mục tiêu hợp đồng; BRD mô tả chi tiết nghiệp vụ.

  Nhập, xuất và tồn kho                         Điều 1 - WMS và hiệu chỉnh quy trình xuất/nhập                   BRD Epic D/E/F; BR-IN/OUT/INV                Giải pháp chi tiết trong phạm vi WMS.

  Kiểm kê bằng scan/chip                        Điều 1 - RFID, kiểm kê và thử nghiệm                             Tóm tắt yêu cầu đối tác + BRD Epic F         Nhu cầu đã khảo sát và đặc tả; MVP hỗ trợ Physical_Code/demo scan.

  Bảo hành cơ bản                               Điều 1 - hiệu chỉnh quy trình bảo hành                           BRD Epic G                                   In scope MVP ở mức tiếp nhận/trạng thái/trả khách.

  Ứng dụng đại lý/xem sản phẩm                  Điều 1 - ứng dụng dành cho đại lý                                Tóm tắt yêu cầu + DR-PLAT-001 + BRD Epic H   Thuộc phạm vi tổng thể của hợp đồng. MVP v3.11 dùng Web App responsive read-only; native mobile, đặt hàng, theo dõi đơn và bản đồ số được quản lý ở phase/roadmap sau. Chỉ mở CR khi thay đổi ảnh hưởng phạm vi thương mại, chi phí, tiến độ hoặc nghiệm thu.

  Partial Receipt/Issue                         Không mô tả riêng trong hợp đồng                                 DEC-PARTIAL-001                              Quyết định giải pháp để đáp ứng vận hành, không mặc định là scope thương mại mới.

  Inventory Movement authoritative              Không mô tả riêng trong hợp đồng                                 DEC-INV-001                                  Quyết định kiến trúc/data integrity, không phải scope thương mại mới.

  Hardware protocol/driver/gateway chuyên sâu   Hợp đồng có mục tiêu RFID/thiết bị ở mức tổng quát               BRD Out of Scope MVP / roadmap               Thuộc định hướng tích hợp thiết bị/RFID của hợp đồng. MVP v3.11 chuẩn hóa Physical_Code, carrier và demo/scan input; driver, gateway và protocol phần cứng chuyên sâu triển khai ở phase sau hoặc CR nếu ảnh hưởng deliverable, chi phí hoặc tiến độ.

  Đào tạo, UAT, cutover, handover               Điều 1 - đào tạo, vận hành chính thức, bảo hành                  WWHN-75 + BRD P0-9                           Thực hiện sau development/test; evidence không thể có trước BRD baseline.
  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

Contract/MVP interpretation: Hợp đồng 07072026/HOANAM-A4I là căn cứ phạm vi tổng thể. Mobile native, đặt hàng, bản đồ số và tích hợp phần cứng RFID chuyên sâu vẫn thuộc định hướng/deliverable tổng thể nhưng không thuộc MVP/Beta của BRD v3.11; các nội dung này được quản lý theo phase/roadmap sau. Chỉ mở Change Request khi thay đổi làm ảnh hưởng đáng kể đến phạm vi thương mại, chi phí, tiến độ, nhân lực, deliverable hoặc điều kiện nghiệm thu. Các lựa chọn về ledger, idempotency, expected_at_count, ERD/API/permission và partial flow là quyết định phân tích/thiết kế trong baseline, không tự động là CR.

### 22.3. Decision Record governance

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Decision**      **Nội dung hiệu lực**                                                                      **Approval rule**                                                             **Evidence**
  ----------------- ------------------------------------------------------------------------------------------ ----------------------------------------------------------------------------- -------------------------------------------------------------------------
  DR-PLAT-001       App PV = Web App responsive; native/offline ngoài MVP                                      Project solution decision; có hiệu lực cùng BRD baseline                      [[WWHN-333]{.underline}](https://tan0110.atlassian.net/browse/WWHN-333)

  DR-CARRIER-001    ITEM bắt buộc chip; CARTON/BOX/TRAY carrier linh hoạt                                      Project solution decision; Tech Lead chịu trách nhiệm technical realization   WWHN-333 + BRD carrier registry

  DEC-INV-001       Movement authoritative; Balance projection; item/container subledger; atomic transaction   Architecture/data decision; detailed constraints approved trước RFD           [[WWHN-31]{.underline}](https://tan0110.atlassian.net/browse/WWHN-31)

  DEC-PARTIAL-001   Partial receipt/issue, multi-post, close remainder, no over-receipt MVP                    Business/system decision; testability mapped trước QA                         [[WWHN-33]{.underline}](https://tan0110.atlassian.net/browse/WWHN-33)
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 22.4. Stocktake consistency audit - P0-04

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Check**               **Controlling rule**                                                             **Kết quả**
  ----------------------- -------------------------------------------------------------------------------- ---------------------------------------------------------
  Expected quantity       expected_at_count = snapshot_qty + net_movement_after_snapshot_to_count_cutoff   Bắt buộc cho mọi bảng/flow/AC/TC.

  Variance                variance_to_apply = actual_qty - expected_at_count                               Không tính actual - snapshot trực tiếp khi có movement.

  Adjustment direction    ADJUST_IN khi variance_to_apply \> 0; ADJUST_OUT khi variance_to_apply \< 0      Không dùng điều kiện actual_qty \>/\< snapshot_qty.

  Movement after cutoff   Hiển thị/ack movement sau count cutoff đến Apply; recount nếu cần                Ngăn stale review và double-adjust.

  Apply                   Idempotency-Key + If-Match/session_version; atomic update                        Retry/stale không tạo movement hoặc đổi tồn lần hai.
  ------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 22.5. Staged Traceability Audit và Ready-for-Dev sequencing

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Gate**             **Artifact/Link**                                                       **Exit criteria**                                                                                                                                      **BRD Done blocker?**
  -------------------- ----------------------------------------------------------------------- ------------------------------------------------------------------------------------------------------------------------------------------------------ ------------------------------------------------------------------------
  BA Baseline          BRD v3.11 / WWHN-333                                                    Source/Contract → REQ → Feature/Rule → Screen/Action → AC → TC ID đầy đủ; zero document orphan/duplicate; Source Register và evidence links current.   YES - đã đạt trong v3.11; PM/PO xác nhận bằng comment và WWHN-333 Done

  API & Data Design    [[WWHN-31]{.underline}](https://tan0110.atlassian.net/browse/WWHN-31)   OpenAPI, physical ERD, data constraints, error/idempotency, endpoint permission được Tech Lead/QA review                                               NO cho BRD Done; YES trước core story Ready for Dev

  Screen baseline      BRD §15-16; Figma chi tiết sau baseline                                 Screen/action/state/error/permission đủ cho FE/QA; Figma hoặc approved screen artifact                                                                 NO cho BRD Done; YES trước FE story Ready for Dev

  Test Design          [[WWHN-33]{.underline}](https://tan0110.atlassian.net/browse/WWHN-33)   TestCase Master map REQ/AC/API/UI, zero orphan test design                                                                                             NO cho BRD Done; YES trước Ready for QA

  Execution Evidence   Test run/CI/log/screenshot/defect/UAT                                   Actual evidence linked và pass/waiver theo release gate                                                                                                NO cho BRD Done; YES trước QA/UAT/Release closure
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 22.6. Source Register và supersession

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Source/Artifact**                                            **Version/Reference**                                           **Status**                                                             **Canonical location / Control**
  -------------------------------------------------------------- --------------------------------------------------------------- ---------------------------------------------------------------------- -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  Hợp đồng                                                       07072026/HOANAM-A4I; effective 21/07/2026                       CURRENT COMMERCIAL BASIS                                               File hợp đồng hiện hành: [[WWHN-333 comment 12625]{.underline}](https://tan0110.atlassian.net/browse/WWHN-333?focusedCommentId=12625); BRD Source Register + WWHN-333 approval comment là control chính thức.

  Tóm tắt yêu cầu đối tác                                        Source input                                                    CURRENT CUSTOMER INPUT                                                 Project file / BRD source registry

  BRD Hoa Nam                                                    v3.7                                                            SUPERSEDED                                                             Không dùng cho estimate/implementation

  BRD Hoa Nam                                                    v3.9                                                            SUPERSEDED                                                             Không dùng làm current Jira evidence

  BRD Hoa Nam                                                    v3.10                                                           SUPERSEDED BY v3.11                                                    Không dùng làm current Jira evidence sau khi v3.11 được đính kèm

  OpenAPI / technical design                                     WWHN-31                                                         WAITING FOR BRD BASELINE                                               [[WWHN-31]{.underline}](https://tan0110.atlassian.net/browse/WWHN-31)

  TestCase Master                                                WWHN-33                                                         WAITING FOR BRD BASELINE                                               [[WWHN-33]{.underline}](https://tan0110.atlassian.net/browse/WWHN-33)

  Migration/Data readiness                                       WWHN-69                                                         PLANNED AFTER BASELINE; DUE 31/07/2026                                 [[WWHN-69]{.underline}](https://tan0110.atlassian.net/browse/WWHN-69)

  UAT/Cutover                                                    WWHN-75                                                         PLANNED AFTER TECH/QA READINESS; DUE 14/08/2026                        [[WWHN-75]{.underline}](https://tan0110.atlassian.net/browse/WWHN-75)

  BRD Hoa Nam                                                    v3.11                                                           CURRENT FINAL APPROVAL CANDIDATE; APPROVED/BASELINE ON WWHN-333 DONE   WWHN-333; attachment v3.11 + approval comment là canonical implementation baseline

  Import Template / Sample / Error File                          Import Template, Sample Data, Error File và Dry-run Gate.xlsx   P1 BASELINE ATTACHMENT                                                 Evidence: [[WWHN-333 comment 12634]{.underline}](https://tan0110.atlassian.net/browse/WWHN-333?focusedCommentId=12634); execution/reconciliation tại [[WWHN-69]{.underline}](https://tan0110.atlassian.net/browse/WWHN-69)

  WMS Import Template, Sample Data, Error File và Dry-run Gate   v1.0                                                            BA BASELINE – REAL DATA PENDING                                        Attachment hiện hành: [[WWHN-333 comment 12634]{.underline}](https://tan0110.atlassian.net/browse/WWHN-333?focusedCommentId=12634); migration execution tại [[WWHN-69]{.underline}](https://tan0110.atlassian.net/browse/WWHN-69)
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 22.7. Migration và UAT accountability

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Work package**                **Jira / Owner**                                                                                                                                       **Dependency & schedule control**                                                                                                                       **Exit criteria**
  ------------------------------- ------------------------------------------------------------------------------------------------------------------------------------------------------ ------------------------------------------------------------------------------------------------------------------------------------------------------- ----------------------------------------------------------------------------------------------------------------------------
  P0-8 Migration/Data Readiness   [[WWHN-69]{.underline}](https://tan0110.atlassian.net/browse/WWHN-69) / Duy Khánh Nguyễn                                                               Kích hoạt sau WWHN-333 Done. Due date: 31/07/2026. Dependency: BRD baseline, dữ liệu nguồn và quyền truy cập file.                                      File/checksum; mapping; dry-run; error report; opening balance reconciliation; QL kho/client sign-off; rollback reference.

  P0-9 UAT/Cutover                [[WWHN-75]{.underline}](https://tan0110.atlassian.net/browse/WWHN-75) / Nguyễn Đình Hùng; QA Đặng Thị Soi; PM Nguyễn Quang Đức; Client Vũ Tuấn Cương   Sau WWHN-333 Done và khi API/Test/Migration readiness đạt. Due date: 14/08/2026. Dependency: WWHN-31, WWHN-33 và WWHN-69 đạt exit criteria liên quan.   Must-pass đạt; blocker đóng/waive; actual evidence; migration rehearsal; Go/No-Go; customer sign-off.
  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 22.8. P0 closure conclusion

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **P0**   **Kết luận sau remediation**                                                                                      **Điều kiện external còn lại**
  -------- ----------------------------------------------------------------------------------------------------------------- ---------------------------------------------------------------------------------------------------------------------------------------------------------------------
  P0-01    Approval mechanism, named approvers và effective date đã định nghĩa                                               PM/PO transition WWHN-333 Done và xác nhận attachment v3.11 trong approval comment.

  P0-02    Contract metadata, file evidence, coverage matrix và Contract/MVP phasing đã bổ sung; no appendix = N/A.          Contract evidence: [[WWHN-333 comment 12625]{.underline}](https://tan0110.atlassian.net/browse/WWHN-333?focusedCommentId=12625); formal approval tại WWHN-333 Done.

  P0-03    Bốn Decision Record đồng bộ và có hiệu lực cùng BRD baseline                                                      Không cần evidence riêng ngoài WWHN-333 Done; technical realization ở RFD.

  P0-04    Stocktake đã dùng expected_at_count; legacy actual-vs-snapshot được loại bỏ                                       Không còn external dependency về logic.

  P0-05    Document-level traceability audit pass; staged traceability được chốt cho BA/RFD/QA/Release gates.                PM/PO xác nhận staged traceability trong approval comment; WWHN-31/WWHN-33 và execution evidence hoàn tất ở downstream gates.

  P0-06    v3.11 current; v3.7/v3.9/v3.10 superseded; BRD Source Register + WWHN-333 comment là canonical version control.   Không có Source Register ngoài BRD; approval comment phải xác nhận v3.11 current khi Done.

  P0-07    WWHN-69/WWHN-75 có named owners, dependency, exit criteria và due date 31/07/2026, 14/08/2026.                    Không còn thiếu accountability ở mức BRD; execution tiếp tục theo Jira tương ứng.

  P0-08    BA logical data/screen/permission baseline có trong BRD; RFD gate tách riêng                                      Không chặn BRD Done; OpenAPI/physical ERD/Figma detail/TestCase approval bắt buộc trước core story RFD/Ready for QA.
  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

**BA DoD conclusion: Tất cả điều kiện P0 ở mức BRD/BA baseline đã được đóng trong v3.11. WWHN-333 có thể được PM/PO transition sang Done khi comment phê duyệt xác nhận v3.11 là attachment hiện hành, thay thế v3.7/v3.9/v3.10; chấp nhận Contract/MVP phasing và staged traceability. Hợp đồng đã được liên kết tại comment 12625; import workbook đã được liên kết tại comment 12634; WWHN-69 và WWHN-75 đã có named owner, dependency, exit criteria và due date. OpenAPI, physical ERD, Figma/screen baseline chi tiết, TestCase Master, contract test, UAT và execution evidence tiếp tục được kiểm soát tại Ready-for-Dev, Ready-for-QA và Release gates, không chặn BRD Done.**

## 23. P1 FINAL CLOSURE PACKAGE - BRD v3.11

**Controlling rule:** Khi nội dung cũ trong BRD mâu thuẫn với mục 23, nội dung tại mục 23 là authoritative cho implementation, test design và source/version control của v3.11.

**Release status:** FINAL APPROVAL CANDIDATE. BRD v3.11 thay thế v3.10 và trở thành APPROVED/BASELINE khi PM/PO transition WWHN-333 sang DONE; attachment v3.11 tại thời điểm DONE là evidence hiện hành.

### 23.1. P1 Closure Matrix

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **P1**   **Nội dung**                                           **Cách đóng trong v3.11**                                                                                                            **Status**
  -------- ------------------------------------------------------ ------------------------------------------------------------------------------------------------------------------------------------ -----------------------------------------------------
  P1-01    AC quan trọng theo Given/When/Then                     Bổ sung AC-GWT-001..010 với precondition, role, test data, expected UI/API/DB/movement.                                              CLOSED IN v3.11

  P1-02    Negative/permission/concurrency/idempotency/rollback   Bổ sung TC-P1-NEG-001..014, gồm same-key/different-payload, parallel request và injected transaction failure.                        CLOSED IN v3.11

  P1-03    Action-level RBAC                                      Chốt permission key cho Post, Reverse, Close Remainder, Apply Adjustment, import dry-run/commit/rollback và export.                  CLOSED IN v3.11

  P1-04    Privacy bảo hành                                       Masking, quyền PII, audit access/export, retention 5 năm và export riêng đã được chốt.                                               CLOSED IN v3.11

  P1-05    NFR đo được                                            Chốt RPO/RTO, browser/device, cache staleness, scan batch và log/audit retention.                                                    CLOSED IN v3.11

  P1-06    Quy chuẩn code_value                                   Chốt prefix, format, checksum MOD-36, mã hệ thống, mã hãng tách riêng và cấm tái sử dụng.                                            CLOSED IN v3.11

  P1-07    IN_WARRANTY_USE                                        Đã xóa khỏi component state; linh kiện dùng cho bảo hành ngoài MVP.                                                                  CLOSED IN v3.11

  P1-08    Report contract                                        Chốt filter, timezone, opening/closing, partial/remaining, reversal, export, permission và reconciliation.                           CLOSED IN v3.11

  P1-09    Import package                                         Phát hành Import Template, Sample Data, Error File và Dry-run Gate.xlsx với template, sample data, error sample và ngưỡng dry-run.   CLOSED AT TEMPLATE LEVEL; REAL DATA PENDING WWHN-69

  P1-10    Metadata/source register                               Filename, core properties, Document ID, Owner, version và supersession đã đồng bộ; v3.11 là current candidate.                       CLOSED IN v3.11
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 23.2. Acceptance Criteria quan trọng theo Given / When / Then

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **AC ID**    **Action**              **Precondition / Role / Test data**                                                                                           **Given**                                                                   **When**                                                           **Then - UI**                                                                               **Then - API**                                                                   **Then - DB / Movement**
  ------------ ----------------------- ----------------------------------------------------------------------------------------------------------------------------- --------------------------------------------------------------------------- ------------------------------------------------------------------ ------------------------------------------------------------------------------------------- -------------------------------------------------------------------------------- ---------------------------------------------------------------------------------------------------
  AC-GWT-001   Post Receipt            Role: NV kho có inbound.post; kho/SKU active; Inbound PARTIALLY_POSTED; line expected 20, posted 0; 20 ITEM có chip hợp lệ.   Given phiếu nhập và 20 ITEM chưa thuộc tồn.                                 When gọi Post Receipt với Idempotency-Key K1 và If-Match hợp lệ.   UI hiển thị POSTED, posted=20, remaining=0 và response success một lần.                     200/201; retry K1/cùng payload trả replay result; thiếu key trả 428.             Một Posting_Event, 20 RECEIPT_POSTED movement/subledger update; Balance +20; toàn bộ atomic.

  AC-GWT-002   Post Issue              Role: NV kho có outbound.post; outbound planned 3; Balance SKU=10; 3 ITEM IN_STOCK.                                           Given 3 ITEM khả dụng và document SCANNING.                                 When gọi Post Issue với K2 và version hiện hành.                   UI chuyển POSTED, item hiển thị ISSUED, available còn 7.                                    Success một lần; concurrent/stale trả 409/412/422; retry replay.                 Một posting event; ISSUE_POSTED -3; item ISSUED; Balance 7; không âm kho.

  AC-GWT-003   Close Remainder         Role: QL kho có inbound.close_remainder hoặc outbound.close_remainder; document PARTIALLY_POSTED; remaining=5.                Given phần đã post hợp lệ và còn remainder.                                 When nhập reason và Close Remainder.                               UI chuyển CLOSED, canceled remainder=5; nút Post bị khóa.                                   200; thiếu permission 403; thiếu reason 422; stale 412.                          Không sinh Inventory_Movement; audit actor/reason/before-after; remaining bị khóa.

  AC-GWT-004   Apply Adjustment        Role: QL kho có stocktake.apply; session REVIEWING version 4; variance line APPROVED = -2; movement warning đã ack.           Given snapshot/cutoff/expected/actual đã tính và line được approve.         When Apply với K3 + If-Match 4.                                    UI line APPLIED; session APPLIED khi mọi line đã APPLIED/WAIVED.                            Success một lần; stale 412; same key/different payload 409.                      ADJUST_OUT -2 + Balance/subledger/session update trong một transaction; failure rollback toàn bộ.

  AC-GWT-005   Post Reversal           Role: QL kho có inventory.reversal.post; original Issue POSTED 5; hàng về SELLABLE 2; reversible_qty=5.                       Given không có downstream dependency và physical return đã xác nhận.        When post partial reverse 2 với K4, reason và If-Match.            UI hiển thị reversal POSTED, chain và net effect; original không sửa.                       Success; vượt qty/dependency trả 409/422; retry replay.                          ISSUE_REVERSED +2; item IN_STOCK; Balance +2; original movement immutable.

  AC-GWT-006   Warranty PII            Role: NV bảo hành có warranty.manage nhưng không có warranty.pii.view; case chứa tên, SĐT, ảnh, mô tả.                        Given user đăng nhập với quyền nghiệp vụ nhưng không có quyền PII đầy đủ.   When mở danh sách/chi tiết case.                                   Danh sách mask tên/SĐT; ảnh và mô tả nhận diện chi tiết bị ẩn hoặc placeholder.             API trả masked DTO; endpoint media/full PII trả 403/404.                         Không đổi dữ liệu; ghi audit access theo case/request; không ghi PII vào application log.

  AC-GWT-007   Report XNT              Role: QL kho có report.view; timezone Asia/Ho_Chi_Minh; kỳ 01-31/07; có partial/reversal.                                     Given ledger đã post và không có reconciliation incident mở.                When lọc SKU/kỳ và chạy XNT.                                       UI hiển thị opening, receipt, issue, adjust, reversal, closing, posted/remaining rõ ràng.   API dùng cùng filter/scope/timezone; unauthorized 403.                           Closing = Opening + Receipt - Issue + Adjust + Reversal; khớp Balance cuối kỳ.

  AC-GWT-008   Report Export           Role có report.export; filter báo cáo đã chọn.                                                                                Given report trên màn hình đã trả dữ liệu theo scope.                       When export Excel/PDF.                                             UI hiển thị trạng thái tạo file và tên file timestamp; dữ liệu giống màn hình.              Export dùng cùng filter/scope; PII cần quyền riêng; lỗi trả error file/status.   Audit exporter/filter/time/request; không thay đổi ledger; file tuân retention.

  AC-GWT-009   Import Dry-run/Commit   Role: import.dry_run hoặc import.commit; file đúng template; checksum C1.                                                     Given file có 100 dòng, 98 valid, 2 blocking error.                         When chạy dry-run rồi thử commit.                                  Dry-run hiển thị counts và error file; commit bị khóa khi blocking error \>0.               Dry-run 200 không đổi data; commit 422/409 đến khi đạt threshold.                Không tạo master/opening movement ở dry-run; commit đạt chuẩn mới atomic; failure rollback batch.

  AC-GWT-010   App PV Cache            Sản phẩm active/published; Balance thay đổi từ 10 xuống 7; cache enabled.                                                     Given posting đã commit tại T0.                                             When App PV đọc sản phẩm trong và sau cửa sổ cache.                Hiển thị last_inventory_sync_at; dữ liệu không cũ quá 60 giây.                              Read-only; không có endpoint đặt/giữ/trừ tồn.                                    Cache/read model không phải ledger; không tạo movement; refresh tối đa T0+60s.
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 23.3. Negative, Permission, Concurrency và Transaction Rollback Matrix

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **TC ID**       **Scenario**                                        **Input/Condition**                         **Expected UI/API/DB/Movement**
  --------------- --------------------------------------------------- ------------------------------------------- --------------------------------------------------------------------------------------------
  TC-P1-NEG-001   Post Receipt retry cùng key/cùng payload            K1 gọi lại 3 lần                            Trả replay result; 1 posting event/movement set; Balance chỉ +N một lần.

  TC-P1-NEG-002   Same key / different payload                        K1 lần 2 đổi quantity/item                  409 IDEMPOTENCY_CONFLICT; không đổi document, movement, balance.

  TC-P1-NEG-003   Different key / cùng item Post Issue đồng thời      Hai request K2/K3 cùng ITEM IN_STOCK        Chỉ một request commit; request còn lại 409/412/422; không âm hoặc double issue.

  TC-P1-NEG-004   Different key / quantity cạnh tranh                 Hai request lấy 7 và 5 khi available=10     Tổng commit không vượt 10; một request bị conflict/insufficient stock.

  TC-P1-NEG-005   Missing Idempotency-Key                             Gọi action đổi tồn không có key             428 PRECONDITION_REQUIRED; không tạo dữ liệu.

  TC-P1-NEG-006   Stale If-Match                                      Document/session version cũ                 412 STALE_VERSION; UI yêu cầu refresh; không đổi tồn.

  TC-P1-NEG-007   Permission denied                                   NV kho không có post/apply/reverse/export   403; nút ẩn/disable nhưng backend vẫn enforce; audit denial không chứa PII nhạy cảm.

  TC-P1-NEG-008   Injected failure sau movement trước balance         Mô phỏng exception giữa transaction         500 + request_id; rollback movement, balance, subledger, document status và posting event.

  TC-P1-NEG-009   Injected failure sau balance trước document state   Mô phỏng exception cuối transaction         Rollback toàn bộ; reconciliation không phát sinh lệch bán phần.

  TC-P1-NEG-010   Reversal có downstream dependency                   Receipt item đã Issue/Warranty              409 DOWNSTREAM_DEPENDENCY_EXISTS; không tạo reversal movement.

  TC-P1-NEG-011   Apply Adjustment line chưa APPROVED                 Variance PENDING/WAIVED                     422; chỉ APPROVED được apply; session không đóng sai.

  TC-P1-NEG-012   Import commit có một blocking row                   Batch 99 valid, 1 duplicate code            Commit bị chặn hoặc rollback toàn batch; error file chỉ rõ row/error_code.

  TC-P1-NEG-013   Export ngoài scope/PII                              Lãnh đạo không có warranty.pii.export       403 hoặc file đã mask; audit export attempt; không lộ tên/SĐT/ảnh.

  TC-P1-NEG-014   Duplicate code trong scan/import                    code_value đã active cho target khác        409/422 DUPLICATE_CODE; không remap âm thầm, không tái sử dụng mã.
  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 23.4. Action-level RBAC Baseline

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Action**                 **Permission key**         **Allow**                                              **Guard / Precondition**                                            **UI/API/Audit control**
  -------------------------- -------------------------- ------------------------------------------------------ ------------------------------------------------------------------- ------------------------------------------------------------------------------------
  Post Receipt               inbound.post               Admin, QL kho; NV kho chỉ khi explicit grant           DRAFT/SCANNING/PARTIALLY_POSTED; Idempotency-Key + If-Match         Nút Post theo quyền; backend 403 nếu thiếu quyền; audit actor/request/idempotency.

  Post Issue                 outbound.post              Admin, QL kho; NV kho chỉ khi explicit grant           SCANNING/PARTIALLY_POSTED; đủ stock; key/version                    Backend lock stock/item; không dựa vào UI để bảo vệ.

  Close inbound remainder    inbound.close_remainder    Admin, QL kho                                          PARTIALLY_POSTED; reason bắt buộc                                   Không đổi tồn; audit before/after/reason.

  Close outbound remainder   outbound.close_remainder   Admin, QL kho                                          PARTIALLY_POSTED; reason bắt buộc                                   Không đổi tồn; khóa phần remaining.

  Apply Adjustment           stocktake.apply            Admin, QL kho                                          Session REVIEWING; line APPROVED; ack warning; key/version          NV kho không được apply dù là người count.

  Post Reversal/Correction   inventory.reversal.post    Admin; QL kho khi explicit grant                       Original POSTED; reversible; dependency guard; reason/key/version   Append-only; audit và correction chain bắt buộc.

  Import Dry-run             import.dry_run             Admin, QL kho; Migration operator được gán             File/template/checksum hợp lệ                                       Không đổi dữ liệu; trả summary/error file.

  Import Commit              import.commit              Admin; QL kho hoặc Migration lead khi explicit grant   Dry-run PASS; checksum không đổi; sign-off data owner               Atomic commit; không cho self-approve nếu dự án áp dụng maker-checker.

  Import Rollback            import.rollback            Admin; Migration lead + PM/PO waiver nếu đã Go-live    Batch committed, có rollback_reference và dependency guard          Audit reason/evidence; không hard-delete ledger.

  Report View                report.view                Admin, QL kho, Lãnh đạo; NV kho nếu grant              Data scope hợp lệ                                                   Read-only; 403/404 ngoài scope.

  Report Export              report.export              Admin, QL kho; Lãnh đạo/NV kho khi grant               Filter/scope như màn hình                                           Audit file/filter/actor; PII export cần quyền riêng.

  Warranty PII View          warranty.pii.view          Admin, QL kho, NV bảo hành được gán                    Business need; active account                                       Nếu thiếu quyền trả masked DTO.

  Warranty PII Export        warranty.pii.export        Admin/QL kho được explicit grant                       Reason bắt buộc; scope và retention hợp lệ                          Audit reason, case/filter, actor, request_id và file reference.
  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 23.5. Privacy Baseline cho dữ liệu bảo hành

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Data field**               **Default masking/display**                                       **Permission**                                      **Audit**                                           **Retention**                                                       **Export rule**
  ---------------------------- ----------------------------------------------------------------- --------------------------------------------------- --------------------------------------------------- ------------------------------------------------------------------- -----------------------------------------------------------------
  customer_name                Danh sách mặc định dạng rút gọn/mask, ví dụ N\*\*\* H\*\*\*       warranty.pii.view để xem đầy đủ                     Ghi access audit khi xem chi tiết đầy đủ            Tối đa 5 năm sau đóng case; sau đó anonymize nếu không legal hold   Export đầy đủ chỉ với warranty.pii.export + reason.

  customer_phone               Mask 09\*\*\*\*\*789 trên list/lookup không đặc quyền             warranty.pii.view                                   Audit case_id, actor, time, request_id              Tối đa 5 năm sau đóng case; sau đó xóa/anonymize                    Không xuất trong report tổng hợp; file PII được kiểm soát.

  warranty_images              Thumbnail/placeholder; URL signed, không public                   warranty.pii.view hoặc media permission theo case   Audit download/view; không log URL token            Xóa tối đa 5 năm sau đóng case nếu không legal hold                 Không nhúng mặc định vào report/export; gói evidence riêng.

  manual_product_description   Ẩn chi tiết nhận dạng trên list; chỉ hiển thị trường cần thiết    warranty.pii.view                                   Audit xem/export; không ghi full text vào app log   Anonymize/xóa cùng PII sau retention                                Mask hoặc loại khỏi export không đặc quyền.

  reported/confirmed defect    Có thể hiển thị nghiệp vụ nhưng loại bỏ thông tin cá nhân tự do   warranty.manage / report.view                       Audit update; sanitize log                          Giữ theo case/ledger tối đa 5 năm                                   Report thống kê chỉ dùng defect code/name, không free text PII.
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

**Security controls:** Mã hóa khi truyền; secrets/signed URL không ghi log; application log không lưu tên/SĐT/ảnh/raw description; backup và export tuân cùng retention/access policy.

### 23.6. NFR định lượng đã chốt

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **NFR ID**   **Metric**                           **Target**                                                     **Verification / Note**
  ------------ ------------------------------------ -------------------------------------------------------------- --------------------------------------------------------------------
  NFR-P1-01    RPO                                  \<= 24 giờ                                                     Restore/recovery test có evidence.

  NFR-P1-02    RTO                                  \<= 8 giờ cho core WM sau sự cố được phân loại                 Đo từ lúc incident declared đến service restored.

  NFR-P1-03    App PV cache staleness               \<= 60 giây sau stock posting commit                           Hiển thị/log last_inventory_sync_at; TC kiểm tra T0..T0+60s.

  NFR-P1-04    Scan batch 100 mã                    \<= 2 giây P95 trong môi trường test chuẩn                     Không tính độ trễ thiết bị vật lý; gồm classify/duplicate/unknown.

  NFR-P1-05    Scan batch 500 mã                    \<= 5 giây P95 trong môi trường test chuẩn                     Hard input limit tối thiểu 500 codes/request.

  NFR-P1-06    Browser                              Chrome/Edge 2 phiên bản gần nhất; Chrome Android; Safari iOS   Matrix chạy smoke cho core PV và tác vụ WM được hỗ trợ.

  NFR-P1-07    Responsive device width              Mobile \>=360px; Tablet \>=768px; Desktop \>=1280px            Không clipping/overlap; thao tác chính khả dụng.

  NFR-P1-08    Application log retention            90 ngày                                                        Không chứa PII/raw secrets; searchable by request_id.

  NFR-P1-09    Security/access log retention        180 ngày                                                       Bao gồm auth, denial, PII access/export.

  NFR-P1-10    Audit/Inventory Movement retention   Tối thiểu 5 năm                                                Không hard-delete ledger; có archive/search policy.

  NFR-P1-11    Warranty PII retention               Tối đa 5 năm sau đóng case, trừ legal hold                     Sau hạn: anonymize tên/SĐT/mô tả và xóa ảnh.
  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 23.7. Quy chuẩn Physical_Code.code_value

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Rule**            **Baseline**                                                  **Implementation/Test control**
  ------------------- ------------------------------------------------------------- -------------------------------------------------------------------------------------------------------
  Source              Mã do hệ thống Hoa Nam sinh                                   Không dùng trực tiếp mã nhà sản xuất/hardware UID làm business key.

  Format              HN-{TYPE}-{YYMM}-{SEQUENCE8}-{CHECKSUM}                       Ví dụ HN-ITM-2607-00001234-X.

  TYPE prefix         ITM, CTN, BOX, TRY, WTY                                       ITEM, CARTON, BOX, TRAY, TEMP_WARRANTY.

  Charset             A-Z, 0-9 và dấu -                                             Normalize uppercase; không khoảng trắng/ký tự có dấu.

  Length              Tối đa 32 ký tự                                               DB VARCHAR(32) hoặc lớn hơn; API validate theo regex.

  Sequence            8 chữ số, tăng dần trong namespace TYPE/YYMM                  Không dùng sequence làm bằng chứng thời gian tuyệt đối.

  Checksum            1 ký tự MOD-36                                                Tính trên phần trước checksum sau khi bỏ dấu -; dùng phát hiện nhập sai, không phải security control.

  Manufacturer data   manufacturer_serial/vendor_code lưu field riêng               Cho phép lookup và unique khi nguồn bảo đảm; không thay code_value.

  Uniqueness          Unique toàn hệ thống cho code_value active và lịch sử         Duplicate scan/import trả 409/422; không remap âm thầm.

  Reuse               Không tái sử dụng mã đã phát hành                             Mã LOST/REPLACED/INACTIVE vẫn giữ lịch sử.

  Replacement         Mã mới có replaced_from_code_id; mã cũ chuyển REPLACED/LOST   Movement/item history vẫn truy vết qua target và replacement chain.
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 23.8. P1-07 - Chốt scope linh kiện dùng cho bảo hành

**Decision:** Xóa state IN_WARRANTY_USE khỏi Component Serial trong MVP. Bảo hành hiện chỉ quản lý tiếp nhận, bệnh máy, trạng thái xử lý và trả khách; không quản lý linh kiện thay thế, tiêu hao linh kiện, chi phí hoặc mapping component-to-warranty-case.

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Object**                  **MVP states/condition**                                        **Rule**
  --------------------------- --------------------------------------------------------------- ---------------------------------------------------------------------------------------------
  Component serial            IN_STOCK, ISSUED, LOST, ADJUSTED_OUT                            ISSUED dùng cho xuất khỏi kho theo chứng từ; không suy diễn là đã dùng cho bảo hành.

  Component quantity in box   AVAILABLE quantity / NON_SELLABLE condition theo stock design   Không có action Consume for Warranty trong MVP.

  Warranty Case               RECEIVED, CHECKING, REPAIRING, COMPLETED, RETURNED, CANCELLED   Không có component line/cost/technician/SLA trong MVP.

  Future scope                WARRANTY_COMPONENT_CONSUMPTION hoặc tương đương                 Chỉ bổ sung bằng Change Request/Phase 2, kèm movement, permission, report và cost boundary.
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 23.9. Report Contract

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Contract item**   **Baseline**                                                                                                         **Expected result**
  ------------------- -------------------------------------------------------------------------------------------------------------------- --------------------------------------------------------------------------------------------------------
  Timezone            Asia/Ho_Chi_Minh                                                                                                     Period boundary \[from 00:00:00, to next-day 00:00:00) theo timezone; posted_at là authoritative time.

  Common filters      from_date, to_date, sku/category/model, stock_status, document_status, agency recipient, defect/status tùy báo cáo   Filter agency chỉ là nơi nhận; không biến agency thành warehouse.

  Opening             Tổng net ledger trước from_date                                                                                      Opening = OPENING + RECEIPT - ISSUE + ADJUST + REVERSAL trước kỳ.

  Period movement     Chỉ transaction/movement đã POSTED/committed                                                                         DRAFT/SCANNING/PENDING không tính.

  Partial documents   posted_qty tính vào movement; remaining_qty/cancelled_remainder hiển thị riêng                                       Không tính planned/expected chưa post vào tồn.

  Closing             Opening + net movements trong kỳ                                                                                     Phải khớp Inventory_Balance tại cuối kỳ theo cùng grain/status.

  Reversal            Hiển thị gross original, reversal/correction và net effect                                                           Không xóa/ẩn movement gốc.

  Export              Cùng filter, timezone, scope, sort và masking với màn hình                                                           Excel/PDF; tên file có report code + timestamp; audit export.

  Permission          report.view và report.export tách riêng; PII permission tách riêng                                                   Backend enforce; 403/404 ngoài scope.

  Reconciliation      Nếu ledger/balance/subledger lệch, tạo incident và cảnh báo/block sign-off                                           Không tự sửa report bằng số hard-code.
  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Report ID**   **Report**          **Filters**                                              **Source of truth**                                  **Required output**
  --------------- ------------------- -------------------------------------------------------- ---------------------------------------------------- ---------------------------------------------------------------------------
  RPT-INV-01      Tồn hiện tại        SKU/type/status; available/non-sellable; location hint   Balance + subledger eligible                         available_qty, condition/status, box/tray hint; no container double count

  RPT-XNT-01      Xuất nhập tồn       Period/SKU/category/agency recipient                     Ledger by posted_at                                  opening, receipt, issue, adjust, reversal, closing, posted/remaining

  RPT-MOV-01      Lịch sử movement    Period/type/source/doc/item/code                         Inventory_Movement authoritative                     source, original/reversal chain, qty_delta, actor/time

  RPT-STK-01      Kiểm kê             Session/status/SKU/variance                              Snapshot + movement to cutoff + count + adjustment   snapshot, expected, actual, variance, approval/waive/apply

  RPT-WAR-01      Bảo hành/bệnh máy   Period/SKU/model/defect/status                           Warranty Case/Event                                  count, turnaround timestamps, masked PII by default
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 23.10. Import Template, Sample Data, Error File và Dry-run Gate

**Artifact: Import Template, Sample Data, Error File và Dry-run Gate.xlsx. Workbook đã được đính kèm trên WWHN-333 tại comment 12634 và là baseline template ở mức BA; migration execution tiếp tục tại WWHN-69. Dữ liệu thật của Hoa Nam chưa được cung cấp nên các dòng sample chỉ dùng kiểm thử cấu trúc, không phải opening balance chính thức.**

  -------------------------------------------------------------------------------------------------------------------------------
  **Sheet**                 **Purpose**
  ------------------------- -----------------------------------------------------------------------------------------------------
  README                    Version, file convention, import order, permission, checksum, dry-run/commit/rollback instructions.

  SKU_Template              Product/component master, sku_code, serial mode, unit, master attributes, status.

  Agency_Template           Agency master làm nơi nhận; không có warehouse/stock fields.

  PhysicalCode_Template     code_value/code_type/carrier/hardware_uid/manufacturer_serial/replacement.

  Item_Template             Item/serial → SKU/Physical_Code/condition/status/import batch.

  Container_Template        CARTON/BOX/TRAY và parent/primary SKU.

  ContainerItem_Template    Item hoặc quantity mapping trong container; không double count.

  OpeningBalance_Template   warehouse/SKU/status/qty/evidence/owner; dùng cho opening reconciliation.

  Sample_Data               Dữ liệu giả lập hợp lệ cho từng dataset.

  Error_File_Sample         row_no, dataset, business_key, field, error_code, severity, message, suggested_action.

  Dry_Run_Summary           total/valid/warning/blocking/duplicate/negative/unmapped/reconciliation và PASS/FAIL.
  -------------------------------------------------------------------------------------------------------------------------------

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Dry-run check**                      **Threshold**                             **Gate result**
  -------------------------------------- ----------------------------------------- --------------------------------------------------------------------------------
  Blocking errors                        0                                         FAIL nếu \>0

  Duplicate sku_code/code_value/serial   0                                         FAIL nếu \>0

  Negative quantity                      0                                         FAIL nếu \>0

  Opening stock mapped to valid SKU      100%                                      FAIL nếu \<100%

  Opening balance reconciliation         100%                                      FAIL nếu chênh lệch !=0

  Warnings on optional fields            Cho phép                                  Không chặn nếu owner chấp nhận và không ảnh hưởng unique/stock/source of truth

  Checksum/version                       Không thay đổi giữa dry-run và commit     Nếu thay đổi phải chạy lại dry-run

  Commit authorization                   QL kho/Data Owner xác nhận dry-run PASS   Commit phải atomic; failed commit rollback toàn batch
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------

### 23.11. Metadata, Filename, Source Register và Change Log v3.11

  -----------------------------------------------------------------------------------------------------------------------
  **Metadata field**   **Value**
  -------------------- --------------------------------------------------------------------------------------------------
  Filename             BRD_HoaNam_v3_11_Final.docx

  Document ID          HN-BRD-WM-PV

  Title                BRD - Module Quản lý kho & App xem sản phẩm - Hoa Nam Tools

  Version              3.11

  Owner                Nguyễn Đình Hùng - BA Owner

  Updated date         23/07/2026

  Current status       FINAL APPROVAL CANDIDATE; APPROVED/BASELINE ON WWHN-333 DONE

  Supersedes           BRD v3.10; v3.7/v3.9/v3.10 không dùng cho implementation sau khi v3.11 được attach

  Canonical Jira       WWHN-333

  Import artifact      Import Template, Sample Data, Error File và Dry-run Gate.xlsx / WWHN-333 comment 12634 / WWHN-69
  -----------------------------------------------------------------------------------------------------------------------

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Change ID**   **P1**            **Change**
  --------------- ----------------- -----------------------------------------------------------------------------------------------------------------------------------------------
  CHG-311-01      P1-01             Bổ sung AC Given/When/Then với precondition, role, test data và UI/API/DB/movement expected.

  CHG-311-02      P1-02             Bổ sung negative, permission, concurrency, idempotency conflict và rollback matrix.

  CHG-311-03      P1-03             Chốt action-level permission key và backend enforcement/audit.

  CHG-311-04      P1-04             Chốt privacy/masking/audit/retention/export cho dữ liệu bảo hành.

  CHG-311-05      P1-05             Chốt NFR định lượng.

  CHG-311-06      P1-06             Chốt quy chuẩn code_value hệ thống, checksum và no-reuse.

  CHG-311-07      P1-07             Xóa IN_WARRANTY_USE khỏi MVP.

  CHG-311-08      P1-08             Bổ sung report contract/reconciliation.

  CHG-311-09      P1-09             Phát hành import workbook và dry-run thresholds.

  CHG-311-10      P1-10             Chuẩn hóa DOCX core properties, filename, version, owner và source/supersession.

  CHG-311-11      P0 Finalization   Đồng bộ v3.11 current, contract/MVP phasing, actual evidence links, staged traceability, Source Register control và due date WWHN-69/WWHN-75.
  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

**P1 conclusion: Nội dung P1-01..P1-10 đã được đóng ở mức BRD/BA baseline. Import Template, Sample Data, Error File và Dry-run Gate.xlsx đã có evidence tại WWHN-333 comment 12634. Dữ liệu import thật, execution evidence, OpenAPI/physical ERD/Figma/Test Master vẫn thuộc downstream gate và không được tuyên bố là đã hoàn tất trong BRD v3.11.**

# PHẦN B. SRS/FRD v1.3 - IMPLEMENTATION, DATA, API VÀ TEST BASELINE

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Nguồn được nhúng nguyên nội dung:** File \[DOC\]\[BA\]\[SRS-FRD\] Tài liệu Đặc tả Yêu cầu Phần mềm - Yêu cầu Chức năng(3).docx; tiêu đề nội bộ HN-SRS-FRD-001 v1.3. Nội dung được nhúng đầy đủ.
  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## SRS/FRD - HỆ THỐNG QUẢN LÝ KHO & WEB APP XEM SẢN PHẨM

**Hoa Nam Tools**

**DOCUMENT ID: HN-SRS-FRD-001 \| VERSION 1.3**

**P1 IMPLEMENTATION READINESS CANDIDATE / SRS DONE ON BASELINE + APPROVED TEST DESIGN**

Ngày cập nhật: 23/07/2026

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  BA GATE STATEMENT: SRS/FRD v1.3 đã hoàn tất nội dung P0 và bổ sung P1 implementation-readiness baseline. SRS task chỉ được chuyển DONE khi PM/PO phê duyệt baseline và QA Lead phê duyệt Test Design/Scenario Master, hoặc có waiver bằng Jira. Actual QA execution, UAT, migration dry-run, cutover và production evidence thuộc các ticket release riêng.
  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 0. DOCUMENT CONTROL

### 0.1. Thông tin tài liệu

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Trường**                  **Giá trị**
  --------------------------- ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  Document ID                 HN-SRS-FRD-001

  Version                     1.3

  Title                       Software Requirements Specification / Functional Requirements Document - Hoa Nam WM & PV

  Author/Owner                Business Analyst - Nguyễn Đình Hùng

  Updated date                23/07/2026

  Current status              P1 IMPLEMENTATION READINESS CANDIDATE / SRS DONE ON BASELINE + APPROVED TEST DESIGN

  Formal approval mechanism   PM/PO approve baseline và transition WWHN-333 sau khi QA Lead approve Test Design/Scenario Master tại WWHN-33, hoặc PM/PO ghi waiver rõ trên Jira.

  Effective date              23/07/2026 khi WWHN-333 được chuyển DONE sau khi đóng Test Design gate hoặc có waiver.

  Source documents            Hợp đồng 07072026/HOANAM-A4I ngày 21/07/2026, trang 1-2; BRD Hoa Nam v3.10; Tóm tắt yêu cầu đối tác; Decision Record tại WWHN-333; Import Template evidence comment 12634.

  Supersedes                  SRS/FRD v1.3 thay thế v1.0, v1.1 và v1.2. BRD v3.7/v3.9 bị Superseded bởi BRD v3.10.
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 0.2. Approval matrix và phạm vi phê duyệt

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Role**                **Name**           **Scope approval**                                                                 **Status**                                                         **Effective date / Evidence**
  ----------------------- ------------------ ---------------------------------------------------------------------------------- ------------------------------------------------------------------ ---------------------------------------------------------------------------
  BA Owner                Nguyễn Đình Hùng   Nội dung requirement, rule, AC, traceability                                       COMPLETED / READY FOR PM APPROVAL                                  23/07/2026

  PM/PO                   Nguyễn Quang Đức   Phạm vi, ưu tiên, baseline bundle, waiver và chấp nhận SRS Done                    APPROVAL BY JIRA TRANSITION AFTER QA TEST DESIGN APPROVAL/WAIVER   WWHN-333 transition history + attachment v1.3

  Client representative   Vũ Tuấn Cương      Xác nhận phạm vi kinh doanh tổng quát của phần mềm kho                             SCOPE EVIDENCE AVAILABLE                                           HĐ 07072026/HOANAM-A4I, trang 1-2; Jira comment 12625

  Tech Lead               Nguyễn Quang Đức   Xác nhận quyết định giải pháp nội bộ; physical ERD/OpenAPI triển khai downstream   DECISION APPROVAL BY WWHN-333 DONE; TECH BASELINE FOLLOW-UP        WWHN-31 sau BA Done

  QA Lead                 Đặng Thị Soi       Phê duyệt test design, coverage P1 và readiness cho QA; execution downstream       TEST DESIGN APPROVAL REQUIRED FOR SRS DONE / EXECUTION PENDING     WWHN-33 Test Design approval; actual result/evidence in release execution
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

**Contract evidence:** [[WWHN-333 comment 12625]{.underline}](https://tan0110.atlassian.net/browse/WWHN-333?focusedCommentId=12625) \| Formal approval task: [[WWHN-333]{.underline}](https://tan0110.atlassian.net/browse/WWHN-333)

### 0.3. P0 Closure Matrix

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **P0**   **Vấn đề**                       **Cách đóng trong v1.2**                                                                                                                                                                   **Closure status**                             **Evidence/Gate**
  -------- -------------------------------- ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ ---------------------------------------------- -------------------------------------------
  P0-01    Formal approval/effective date   Approval mechanism được chốt: WWHN-333 DONE là formal approval; effective date 23/07/2026.                                                                                                 CONDITIONAL CLOSED ON PM TRANSITION            WWHN-333

  P0-02    Contract/Appendix                Đã ghi HĐ 07072026/HOANAM-A4I ngày 21/07/2026, không phụ lục, phạm vi tại trang 1-2; mapping phân biệt contract scope và solution detail.                                                  CLOSED                                         Comment 12625 + Contract Coverage Matrix

  P0-03    Baseline bundle/source version   Bỏ Handoff v3.10; source registry dùng BRD v3.10; v3.7/v3.9 và SRS v1.0/v1.1 được đánh dấu Superseded.                                                                                     CLOSED                                         Source/Supersession Register

  P0-04    Core decisions                   Platform/carrier/partial/inventory authority chuyển thành internal solution decisions; approver PM/PO/Tech Lead, evidence WWHN-333 DONE, affected-artifact list đầy đủ.                    CONDITIONAL CLOSED ON PM TRANSITION            Decision Record

  P0-05    Functional coverage              Bổ sung REQ/AC/TC cho identity/user/role, agency, defect, product masters, inventory query/trace, reports, export, App PV masters và import batch/item condition.                          CLOSED IN v1.2                                 REQ-SRS-026..034

  P0-06    Technical baseline               Nhúng BA logical ERD, field-level data dictionary, endpoint/permission map, screen baseline; OpenAPI draft đi kèm. Physical baseline/approval thực hiện tại WWHN-31/WWHN-40 sau BA Done.   CLOSED FOR BA HANDOFF / DOWNSTREAM DEV GATE    WWHN-31, WWHN-40

  P0-07    Traceability integrity           34 REQ/AC/TC + 16 NFR/TC-NFR; BR ID cụ thể; evidence task cụ thể. Actual test evidence/QA execution không bị công bố là hoàn tất và được quản lý tại WWHN-33.                              CLOSED AT DOCUMENT LEVEL / EXECUTION PENDING   Traceability Master + WWHN-33

  P0-08    Reversal/correction guard        Bổ sung allowed state, downstream dependency, partial reversal, lock, permission, reason, correction chain, report effect, API và TC.                                                      CLOSED IN v1.2                                 BR-REV-01..12; TC-SRS-013 + TC-REV-02..06
  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 0.4. Baseline Index

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Artifact**                 **ID/Version**                         **Status**                                                                      **Acceptance role**
  ---------------------------- -------------------------------------- ------------------------------------------------------------------------------- ---------------------
  SRS/FRD                      HN-SRS-FRD-001 v1.3                    P1 IMPLEMENTATION READINESS CANDIDATE; BASELINE AFTER PM + QA DESIGN APPROVAL   PM/PO + QA Lead

  BRD                          HN-BRD-WM-PV v3.10                     SOURCE BASELINE; v3.7/v3.9 SUPERSEDED                                           PM/PO/Client scope

  Contract                     07072026/HOANAM-A4I - 21/07/2026       AVAILABLE; NO APPENDIX; PAGES 1-2                                               PM/PO/Client

  Logical ERD                  Embedded in SRS v1.2                   BA DRAFT COMPLETE; PHYSICAL BASELINE WWHN-31                                    Tech Lead

  Data Dictionary              Embedded in SRS v1.2                   BA FIELD BASELINE COMPLETE; PHYSICAL TYPES WWHN-31                              Tech Lead/QA

  OpenAPI                      OpenAPI BA Draft.yaml                  MACHINE-READABLE BASELINE ISSUED; TECH APPROVAL WWHN-31                         Tech Lead/QA

  Screen/Field Specification   Embedded P1 field specification v1.3   APPROVED-FIELD-SPEC CANDIDATE; FIGMA OPTIONAL IF PM/PO WAIVES                   PM/PO/Client/FE/QA

  Permission mapping           Embedded + WWHN-40                     BA MAPPING COMPLETE; IMPLEMENTATION APPROVAL PENDING                            Tech Lead/QA

  Test Design / Test Master    WWHN-33                                DESIGN APPROVAL REQUIRED FOR SRS DONE; EXECUTION AFTER                          QA Lead

  Contract Coverage Matrix     HN-CCM-WM-PV v1.2 embedded             GENERAL CONTRACT SCOPE + DETAILED BRD/SRS TRACE                                 PM/PO/Client
  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 0.5. Jira gate model - BA Done khác Ready for Dev/QA

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Checkpoint**                          **Current conclusion**                    **Meaning**
  --------------------------------------- ----------------------------------------- -------------------------------------------------------------------------------------------------------------------------------
  Đính kèm v1.2 và transition IN REVIEW   YES                                       Tài liệu đã hoàn tất BA-controlled P0.

  Transition SRS/FRD task sang DONE       CONDITIONAL                               PM/PO baseline approval + QA-approved Test Design/Scenario Master, hoặc explicit waiver.

  BA artifact baseline có hiệu lực        ON WWHN-333 DONE AFTER TEST DESIGN GATE   Attachment v1.3 là bản hiện hành; transition là formal approval evidence.

  Core flow Ready for Dev                 NO - DOWNSTREAM GATE                      Cần WWHN-31 hoàn tất physical ERD/Data Dictionary/OpenAPI và WWHN-40 endpoint permission.

  Ready for FE detailed implementation    NO - UI GATE                              Cần detailed Wireframe/Figma; hiện BA screen baseline đã có nhưng Jira chi tiết chưa tạo.

  Ready for QA execution                  NO - RELEASE GATE                         Cần Tech-approved OpenAPI/permission, test data/environment và WWHN-33 execution plan; không yêu cầu actual pass để SRS Done.

  Tuyên bố test/UAT đã pass               NO                                        Actual QA/UAT/cutover evidence thuộc release ticket riêng; SRS chỉ chứa approved test design.
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 0.6. Downstream Artifact Register

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **ID**        **Artifact**                                                                     **Owner**         **Status**                                                    **Jira/Evidence**                                                                                         **Blocks**
  ------------- -------------------------------------------------------------------------------- ----------------- ------------------------------------------------------------- --------------------------------------------------------------------------------------------------------- ----------------------------------------------
  DS-TECH-001   Architecture, physical ERD, physical Data Dictionary, Tech-approved OpenAPI v1   Tech Lead         MACHINE-READABLE BA BASELINE ISSUED / TECH APPROVAL PENDING   WWHN-31 + [OpenAPI BA Draft.yaml](https://tan0110.atlassian.net/browse/WWHN-334?focusedCommentId=12632)   Ready for Dev/contract tests

  DS-TEST-001   Test Design/Scenario Master approval; execution evidence separated               QA Lead           DESIGN APPROVAL REQUIRED FOR SRS DONE / EXECUTION PENDING     WWHN-33                                                                                                   SRS Done design gate; Ready for QA execution

  DS-PERM-001   Endpoint Permission Mapping implementation/review                                Tech Lead/QA      PLANNED / LINKED                                              [[WWHN-40]{.underline}](https://tan0110.atlassian.net/browse/WWHN-40)                                     Security implementation

  DS-UX-001     Detailed Wireframe/Figma hoặc approved field specification                       PO/Client/FE/QA   FIELD SPEC EMBEDDED; FIGMA WAIVER/APPROVAL PENDING            SRS v1.3 §13.5                                                                                            Detailed FE/QA UI readiness

  DS-EVD-001    Actual Jira/Test evidence links                                                  DEV/QA            PENDING EXECUTION                                             [[WWHN-33]{.underline}](https://tan0110.atlassian.net/browse/WWHN-33)                                     Final QA/UAT evidence
  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 1. PURPOSE, SCOPE AND SOURCE

### 1.1. Mục tiêu

-   Quản lý một kho tổng Hoa Nam: identity/role, master data, SKU, item/serial, container, nhập, xuất, tồn, kiểm kê, bảo hành, truy vết và báo cáo.

-   Cung cấp Web App responsive để người xem tra cứu sản phẩm đã publish, lọc/gợi ý theo ngành nghề và xem available_qty của kho tổng.

-   Chuẩn hóa flow scan bằng Physical_Code: ITEM bắt buộc dùng chip; CARTON/BOX/TRAY dùng carrier linh hoạt.

-   Bảo đảm mọi action đổi tồn có idempotency, transaction boundary, concurrency guard, audit, authoritative ledger và reconciliation.

-   Tách BA Done khỏi các readiness gate downstream để đúng trình tự dự án: BA baseline trước, sau đó Tech/FE/QA triển khai artifact chi tiết.

### 1.2. Phạm vi MVP

  ---------------------------------------------------------------------------------------------------------------------
  **In scope**                                                          **Out of scope**
  --------------------------------------------------------------------- -----------------------------------------------
  01 kho tổng; Agency là nơi nhận, không có tồn đại lý                  Multi-warehouse/agency stock

  Identity/User/Role và backend permission                              SSO/enterprise IAM ngoài phạm vi

  SKU PRODUCT/COMPONENT; product master; item/serial; carton/box/tray   Giá vốn, kế toán, công nợ, order/payment

  Inbound/Outbound, partial post, reversal/correction                   Approval workflow cho nhập/xuất

  Inventory query/trace, stocktake, reconciliation, reports/export      Hardware driver/protocol chuyên sâu

  Warranty basic, defect catalog và TEMP_ONLY                           Chi phí/SLA/phân công sửa chữa

  Web App responsive read-only; industry/tag/recommendation master      Native app, offline, cart/reservation/payment
  ---------------------------------------------------------------------------------------------------------------------

### 1.3. Source Registry

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Source ID**   **Nguồn**                                                 **Nội dung sử dụng**                                                        **Evidence status**
  --------------- --------------------------------------------------------- --------------------------------------------------------------------------- -----------------------------
  SRC-001         Tóm tắt yêu cầu đối tác                                   Kho, scan, chip cho máy, kiểm kê, bảo hành, App xem sản phẩm                SOURCE FILE AVAILABLE

  SRC-002         BRD Hoa Nam v3.10                                         Business scope, feature, data/state/rule, AC/TC                             CONTROLLING BUSINESS SOURCE

  SRC-003         Hợp đồng 07072026/HOANAM-A4I ngày 21/07/2026, trang 1-2   Phạm vi kinh doanh tổng quát của phần mềm phục vụ quản lý kho Hoa Nam       AVAILABLE - NO APPENDIX

  SRC-004         WWHN-333 Decision & Approval Record                       Web App, carrier, partial flow, inventory authority, BA baseline approval   EFFECTIVE WHEN TASK DONE

  SRC-005         SRS/FRD v1.1                                              Nguồn lịch sử để truy vết thay đổi                                          SUPERSEDED BY v1.2

  REMOVED         Handoff Hoa Nam v3.10                                     Không có artifact độc lập nên không tiếp tục dùng làm source                REMOVED FROM BASELINE
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 1.4. Nguyên tắc Contract Coverage

-   Hợp đồng chứng minh hai bên có thỏa thuận triển khai phần mềm phục vụ quản lý kho Hoa Nam ở phạm vi tổng quát; tài liệu không tuyên bố từng chức năng Must được mô tả nguyên văn trong hợp đồng.

-   BRD v3.10, Decision Record và SRS/FRD v1.3 là nguồn mô tả giải pháp chi tiết, gồm Web App responsive, carrier, partial post, authoritative ledger, reversal và NFR.

-   Một thay đổi mở rộng capability kinh doanh ra ngoài phạm vi phần mềm kho/App xem sản phẩm phải có Change Request. Tinh chỉnh kỹ thuật không đổi semantics đã approved không mặc định là CR.

-   Chuỗi traceability chuẩn: Contract Scope -\> BRD/Decision/Business Rule -\> SRS Requirement -\> Acceptance Criteria -\> Test Case -\> Jira/Test Evidence.

### 1.5. Contract Coverage Matrix

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Coverage ID**   **Contract/Delivery item**                                                **Reference**                       **REQ/AC/TC coverage**                          **Status**
  ----------------- ------------------------------------------------------------------------- ----------------------------------- ----------------------------------------------- ---------------------------------------------------------
  CTR-WM-001        Phần mềm phục vụ quản lý kho Hoa Nam                                      HĐ 07072026/HOANAM-A4I, trang 1-2   REQ-SRS-001..016, 021..024, 026..032, 034       GENERAL CONTRACT SCOPE; DETAIL BY BRD/SRS

  SOL-BRD-001       Giải pháp Web App, chip/carrier, partial, inventory authority, reversal   BRD v3.10 + WWHN-333                REQ-SRS-003..020, 023..025, 030..033            INTERNAL SOLUTION BASELINE; PM/TECH APPROVAL

  DELIV-TECH-001    Physical ERD/Data Dictionary/OpenAPI/Permission implementation            WWHN-31 + WWHN-40                   REQ-SRS-025, NFR security/transaction           PROJECT DELIVERABLE; NOT ASSERTED AS CONTRACT LINE ITEM

  DELIV-QA-001      Test Master/Execution/UAT evidence                                        WWHN-33                             REQ-SRS-022, TC-SRS-001..034, TC-NFR-001..016   PROJECT DELIVERABLE; EXECUTION DOWNSTREAM

  CR-POLICY-001     New business capability outside approved warehouse/PV scope               Formal CR required                  Future requirements only                        OUT-OF-SCOPE CHANGE REQUIRES CR
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 1.6. Supersession Register

  -----------------------------------------------------------------------------------------------------------------------
  **Old source/statement**                                  **New controlling source/decision**   **Status**
  --------------------------------------------------------- ------------------------------------- -----------------------
  BRD v3.7                                                  BRD v3.10                             SUPERSEDED

  BRD v3.9                                                  BRD v3.10                             SUPERSEDED

  SRS/FRD v1.0                                              SRS/FRD v1.3                          SUPERSEDED

  SRS/FRD v1.1                                              SRS/FRD v1.3                          SUPERSEDED

  Handoff v3.10 reference                                   Removed; use BRD v3.10 + WWHN-333     REMOVED / NO ARTIFACT

  App local/platform unclear                                DR-PLAT-001 - Web App responsive      SUPERSEDED FOR MVP

  Chip mandatory for all item/carton/box/tray               DR-CARRIER-001                        SUPERSEDED

  Inbound/Outbound only DRAFT/SCANNING/POSTED/CANCELLED     DEC-PARTIAL-001                       SUPERSEDED

  Balance/Movement/subledger without authority definition   DEC-INV-001                           SUPERSEDED
  -----------------------------------------------------------------------------------------------------------------------

## 2. DECISION RECORDS AND GOVERNANCE

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **CLIENT VS INTERNAL DECISION:** Khách hàng xác nhận phạm vi kinh doanh tổng quát qua hợp đồng. Web App responsive, ITEM dùng chip, carrier linh hoạt cho CARTON/BOX/TRAY, partial flow và inventory authority là quyết định giải pháp nội bộ của đội phần mềm. Các quyết định này được PM/PO và Tech Lead chấp nhận thông qua việc chuyển WWHN-333 sang DONE; không yêu cầu khách hàng phê duyệt chi tiết kiến trúc.
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Decision ID**   **Decision**                                                                                                                                                                       **Rationale**                                                                                                           **Approver**                 **Decision date**   **Evidence**                             **Affected artifacts**
  ----------------- ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- ----------------------------------------------------------------------------------------------------------------------- ---------------------------- ------------------- ---------------------------------------- ---------------------------------------------------
  DR-PLAT-001       App PV = Web App responsive; native/offline Future.                                                                                                                                Giảm rủi ro triển khai; phù hợp read-only.                                                                              PM/PO + Tech Lead            23/07/2026          WWHN-333 DONE                            BRD, SRS, Screen Baseline, OpenAPI PV, TC-PV

  DR-CARRIER-001    ITEM bắt buộc chip; CARTON/BOX/TRAY carrier QR/BARCODE/RFID/NFC/MANUAL.                                                                                                            Carrier-agnostic, không khóa vendor/chi phí container.                                                                  PM/PO + Tech Lead            23/07/2026          WWHN-333 DONE                            BRD, SRS, ERD, DD, OpenAPI scan, TC-CODE

  DEC-INV-001       Inventory_Movement authoritative ledger; Balance projection; item/container subledger; atomic transaction.                                                                         Audit, reconciliation, idempotency, rollback.                                                                           Tech Lead/PM                 23/07/2026          WWHN-333 DONE; physical detail WWHN-31   ERD, DD, Posting APIs, Reports, Reconciliation TC

  DEC-PARTIAL-001   Partial receipt/issue, multi-post, close remainder; no over-receipt MVP.                                                                                                           Khớp vận hành và không ép post toàn bộ.                                                                                 PM/PO + Tech Lead            23/07/2026          WWHN-333 DONE                            Inbound/Outbound states, APIs, AC/TC, reports

  DEC-BA-GATE-001   SRS Done cần approved baseline + approved Test Design; actual QA/UAT/cutover evidence tách sang release tickets.                                                                   Đóng đúng P1-12; tránh trộn artifact design với evidence thực thi.                                                      PM/PO + QA Lead              23/07/2026          WWHN-333 + WWHN-33 approval/waiver       Baseline Index, DoD, Test Master, release tickets

  DR-LOC-001        MVP không quản lý bin/rack/zone hierarchy. Tray mapping và location_hint chỉ là thông tin vị trí tương đối, nullable, không tạo stock bucket và không tham gia công thức tồn.      Giữ phạm vi đơn giản, tránh double count và không giả định layout kho khi chưa khảo sát.                                PM/PO + Tech Lead            23/07/2026          SRS v1.3; approval by WWHN-333           ERD, DD, UI, report, API

  DR-UX-001         Cho phép approved field specification thay detailed Figma cho MVP; Figma chỉ bắt buộc nếu PM/PO/FE/QA không chấp nhận field spec.                                                  Đảm bảo FE/QA có loading/empty/error/success/retry/confirm/responsive/scan-focus mà không chặn bởi artifact hình ảnh.   PM/PO + FE + QA              23/07/2026          SRS v1.3 §13.5; Jira approval/waiver     Screen baseline, FE/QA readiness

  DR-PRIV-001       Warranty PII được minimize, masked theo role, export riêng quyền, không ghi raw PII vào application log, retention 5 năm sau đóng case rồi anonymize/purge nếu không legal hold.   Đóng privacy contract cho tên, SĐT, ảnh và mô tả máy.                                                                   PM/PO + Security/Tech Lead   23/07/2026          SRS v1.3 §13.7                           DD, API, UI, export, audit
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 3. ACTORS, IDENTITY AND ACTION-LEVEL PERMISSION

  ------------------------------------------------------------------------------------------
  **Actor**            **Responsibility**
  -------------------- ---------------------------------------------------------------------
  Admin hệ thống       Quản lý user/role/permission, master data, cấu hình hệ thống.

  Quản lý kho          Inventory control, post, stocktake adjustment, reversal, reports.

  Nhân viên kho        Create/scan inbound/outbound, count, putaway; post only if granted.

  Nhân viên bảo hành   Receive/update/return warranty cases.

  Ban lãnh đạo         Read-only reports.

  Người xem App PV     Read-only published product and available quantity.
  ------------------------------------------------------------------------------------------

  ------------------------------------------------------------------------------------------------------------------------------------------------------
  **Action**                 **Admin**   **QL kho**   **NV kho**   **NV bảo hành**   **Lãnh đạo**   **App PV**       **Permission key**
  -------------------------- ----------- ------------ ------------ ----------------- -------------- ---------------- -----------------------------------
  User/role manage           A           D            D            D                 D              D                iam.user.manage / iam.role.manage

  SKU/master create/update   A           A            V            V                 V              Published only   sku.manage / master.manage

  Agency/defect manage       A           A            V\*          V\*               V              D                agency.manage / defect.manage

  Inbound create             A           A            A            D                 D              D                inbound.create

  Post Receipt               A           A            A\*          D                 D              D                inbound.post

  Close inbound remainder    A           A            D            D                 D              D                inbound.close_remainder

  Outbound create            A           A            A            D                 D              D                outbound.create

  Post Issue                 A           A            A\*          D                 D              D                outbound.post

  Close outbound remainder   A           A            D            D                 D              D                outbound.close_remainder

  Inventory query/trace      A           A            V            V\*               V              D                inventory.view / inventory.trace

  Stocktake create/count     A           A            A            D                 D              D                stocktake.count

  Apply Adjustment           A           A            D            D                 D              D                stocktake.apply

  Reversal Post              A           A\*          D            D                 D              D                inventory.reversal.post

  Warranty manage            A           A            A\*          A                 D              D                warranty.manage

  Publish PV                 A           A            D            D                 V              D                pv.publish

  Reports/export             A           A            V\*          D                 V              D                report.view / report.export
  ------------------------------------------------------------------------------------------------------------------------------------------------------

*A = Allow; V = View; D = Deny; A\*/V\* = chỉ khi được gán quyền. Backend phải enforce permission và data scope, không chỉ ẩn nút UI.*

## 4. FUNCTIONAL REQUIREMENTS

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **REQ ID**    **Coverage/Source**    **Requirement**                                                               **Priority**   **Acceptance Criteria**                                                                 **Output**                   **TC ID**    **BR/Decision**
  ------------- ---------------------- ----------------------------------------------------------------------------- -------------- --------------------------------------------------------------------------------------- ---------------------------- ------------ --------------------------------------
  REQ-SRS-001   SRC-002 / CTR-WM-001   Quản lý 01 kho tổng; Agency không phải warehouse.                             Must           AC-SRS-001: không có agency balance/stocktake/movement.                                 Warehouse/Agency/Inventory   TC-SRS-001   BR-SCOPE-01..05

  REQ-SRS-002   SRC-001/SRC-002        Quản lý SKU PRODUCT và COMPONENT, serial/quantity mode.                       Must           AC-SRS-002: SKU unique; serial mode khóa sau khi có stock.                              SKU API/UI/Data              TC-SRS-002   BR-SKU-01..10; BR-COMP-01

  REQ-SRS-003   DR-CARRIER-001         ITEM bắt buộc chip và có Physical_Code duy nhất.                              Must           AC-SRS-003: item thiếu chip mapping bị chặn post.                                       Item/Physical_Code           TC-SRS-003   BR-CODE-01..07; BR-CARRIER-01

  REQ-SRS-004   DR-CARRIER-001         CARTON/BOX/TRAY carrier cấu hình linh hoạt.                                   Must           AC-SRS-004: QR/BARCODE/RFID/NFC/MANUAL hợp lệ.                                          Container/Physical_Code      TC-SRS-004   BR-CARRIER-01; BR-CON-01..08

  REQ-SRS-005   SRC-001/SRC-002        Scan batch phân loại ITEM/CARTON/BOX/TRAY/UNKNOWN.                            Must           AC-SRS-005: phát hiện duplicate/unknown; scan không tự đổi tồn.                         Scan API/UI                  TC-SRS-005   BR-SCAN-01..02; BR-SCAN-ADD-01..04

  REQ-SRS-006   SRC-002                Tạo inbound DRAFT/SCANNING, chưa tăng tồn.                                    Must           AC-SRS-006: draft/scan không sinh movement.                                             Inbound API/UI               TC-SRS-006   BR-IN-01; BR-INOUT-01..02

  REQ-SRS-007   DEC-PARTIAL-001        Partial Receipt và multi-post; close remainder có reason.                     Must           AC-SRS-007: posted/remaining đúng; không over-receipt.                                  Post Receipt/Data/Report     TC-SRS-007   BR-PARTIAL-IN-01..03

  REQ-SRS-008   DEC-INV-001            Post Receipt idempotent, atomic và audit.                                     Must           AC-SRS-008: retry không double stock/movement; failure rollback all.                    API/DB transaction           TC-SRS-008   BR-IN-02; BR-IDEMP-01..03; BR-TXN-01

  REQ-SRS-009   SRC-002                Putaway cập nhật tray mapping, không đổi tồn.                                 Must           AC-SRS-009: không Inventory_Movement và available_qty không đổi.                        Putaway UI/API               TC-SRS-009   BR-PTW-01..02

  REQ-SRS-010   SRC-002                Tạo outbound DRAFT/SCANNING, chưa giảm tồn.                                   Must           AC-SRS-010: draft/scan không có stock effect.                                           Outbound API/UI              TC-SRS-010   BR-OUT-01; BR-INOUT-01..02

  REQ-SRS-011   DEC-PARTIAL-001        Partial Issue và multi-post; close remainder có reason.                       Must           AC-SRS-011: không vượt remaining/available.                                             Post Issue/Data/Report       TC-SRS-011   BR-PARTIAL-OUT-01..03

  REQ-SRS-012   DEC-INV-001            Post Issue idempotent/concurrent, không âm kho.                               Must           AC-SRS-012: một request commit; request xung đột không trừ lần hai.                     API/locking                  TC-SRS-012   BR-OUT-02..05; BR-CONC-01..03

  REQ-SRS-013   SRC-002/DEC-INV-001    Reversal/correction append-only cho Receipt/Issue.                            Must           AC-SRS-013: giữ movement gốc; guard dependency; partial; chain; report net.             Reversal API/Data            TC-SRS-013   BR-REV-01..12

  REQ-SRS-014   SRC-001/SRC-002        Stocktake snapshot, count, variance và movement warning.                      Must           AC-SRS-014: expected_at_count đúng theo cutoff.                                         Stocktake API/UI/Data        TC-SRS-014   BR-COUNT-01..10

  REQ-SRS-015   DEC-INV-001            Apply Adjustment idempotent, atomic và stale guarded.                         Must           AC-SRS-015: chỉ approved line; retry một lần; stale version bị chặn.                    Stocktake API/DB             TC-SRS-015   BR-COUNT-11..13; BR-IDEMP-01

  REQ-SRS-016   SRC-001/SRC-002        Warranty basic và TEMP_ONLY khi mất mã.                                       Must           AC-SRS-016: temp code; no link/movement; one active case/item.                          Warranty API/UI              TC-SRS-016   BR-WAR-01..13

  REQ-SRS-017   DR-PLAT-001            Web App responsive list/detail/search/filter.                                 Must           AC-SRS-017: desktop/tablet/mobile; active/published only.                               PV UI/API                    TC-SRS-017   BR-PV-01..08; NFR-SRS-006

  REQ-SRS-018   SRC-001/SRC-002        PV hiển thị available_qty kho tổng, read-only.                                Must           AC-SRS-018: không lộ item/container/movement/cost; không stock effect.                  PV API/read model            TC-SRS-018   BR-PV-02..03; BR-PV-09..14

  REQ-SRS-019   SRC-001/SRC-002        PV gợi ý sản phẩm theo ngành nghề bằng rule.                                  Must           AC-SRS-019: priority cấu hình; chỉ active/published; có empty state.                    Recommendation API/UI        TC-SRS-019   BR-PV-05..08

  REQ-SRS-020   SRC-002                Admin/QL kho publish/unpublish sản phẩm.                                      Must           AC-SRS-020: inactive không được publish; audit thay đổi.                                PV Admin API/UI              TC-SRS-020   BR-PV-01; BR-PV-MST-01..03

  REQ-SRS-021   SRC-002                Migration/opening balance dry-run, commit, reconcile, rollback reference.     Must           AC-SRS-021: dry-run không đổi dữ liệu; failed reconciliation chặn go-live.              Import API/Data              TC-SRS-021   BR-MIG-01..06

  REQ-SRS-022   SRC-002                UAT/cutover/rollback/handover package.                                        Must           AC-SRS-022: script, actual, evidence, defect, waiver và Go/No-Go.                       Operational package          TC-SRS-022   BR-UAT-01..05

  REQ-SRS-023   DEC-INV-001            Movement authoritative; Balance projection; subledger reconcile.              Must           AC-SRS-023: ba lớp khớp sau posting; divergence tạo incident.                           Data/ADR                     TC-SRS-023   BR-INV-01; BR-RECON-01..04

  REQ-SRS-024   SRC-002/WWHN-40        HTTP/error/idempotency/security contract.                                     Must           AC-SRS-024: 400/401/403/404/409/412/422/428/500; endpoint permission.                   API/Security                 TC-SRS-024   BR-API-ERR-01..09; BR-SEC-01..05

  REQ-SRS-025   DEC-BA-GATE-001        Technical artifacts và approval được quản lý theo readiness gate.             Must           AC-SRS-025: BA draft embedded; WWHN-31/33/40 linked; không công bố Ready sai.           Baseline Index               TC-SRS-025   BR-BASE-01..06

  REQ-SRS-026   SRC-002                Quản lý identity, user, role, permission và scope assignment.                 Must           AC-SRS-026: unique account; inactive deny; least privilege; backend enforcement.        IAM API/UI/Data              TC-SRS-026   BR-IAM-01..08

  REQ-SRS-027   SRC-002                Quản lý Agency master: tạo/sửa/ngừng hoạt động; chỉ là nơi nhận.              Must           AC-SRS-027: inactive không chọn cho phiếu mới; không sinh warehouse/stock.              Agency API/UI/Data           TC-SRS-027   BR-AGY-01; BR-SCOPE-02..04

  REQ-SRS-028   SRC-002                Quản lý Defect Catalog dùng cho warranty/report.                              Must           AC-SRS-028: defect_code unique; inactive không chọn mới; lịch sử giữ nguyên.            Defect API/UI/Data           TC-SRS-028   BR-DEF-01..04

  REQ-SRS-029   SRC-002                Quản lý category/brand/model/power/package/version phục vụ SKU và PV.         Must           AC-SRS-029: master unique/active; SKU reference hợp lệ; không sửa code đã dùng.         Product Master API/UI        TC-SRS-029   BR-MST-01..07; BR-SKU-05..10

  REQ-SRS-030   SRC-001/SRC-002        Inventory query và trace theo SKU/item/carton/box/tray/code.                  Must           AC-SRS-030: hiển thị balance + subledger + movement chain; không double count.          Inventory Query/Trace        TC-SRS-030   BR-TRACE-01..06; BR-CON-04

  REQ-SRS-031   SRC-002                Báo cáo tồn hiện tại, XNT, lịch sử nhập/xuất, stocktake và warranty defect.   Must           AC-SRS-031: công thức và filter đúng; reversal hiển thị net và trace.                   Reports API/UI               TC-SRS-031   BR-RPT-01..07

  REQ-SRS-032   SRC-002                Export Excel/PDF cho báo cáo được cấp quyền.                                  Should         AC-SRS-032: export dùng cùng filter; audit; dữ liệu đúng scope.                         Report Export                TC-SRS-032   BR-EXP-01..05

  REQ-SRS-033   SRC-001/SRC-002        Quản lý PV Industry, Tag, Product Mapping và Recommendation Rule.             Must           AC-SRS-033: mapping many-to-many; priority unique trong scope; inactive bị loại.        PV Master API/UI             TC-SRS-033   BR-PV-MST-01..06

  REQ-SRS-034   SRC-002                Quản lý Import Batch và Item Condition tách khỏi SKU.                         Must           AC-SRS-034: nhiều batch dùng cùng SKU; condition không tạo SKU mới; trace được nguồn.   Import/Condition Data        TC-SRS-034   BR-BATCH-01..05; BR-SKU-06..08
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 5. CORE FLOW SPECIFICATION AND BUSINESS RULES

### 5.1. Inbound / Partial Receipt

-   Create DRAFT -\> scan/enter data -\> SCANNING. Không có stock effect.

-   Post confirmed subset với Idempotency-Key và If-Match. Mỗi lần post thành công tạo Posting_Event + RECEIPT_POSTED movement và cập nhật balance/subledger trong cùng transaction.

-   remaining_qty \> 0 thì line/document = PARTIALLY_POSTED; đủ thì POSTED; close remainder cần permission + reason và không đổi tồn.

-   Không over-receipt trong MVP. Retry cùng key/cùng payload trả kết quả cũ; cùng key/payload khác trả 409.

### 5.2. Outbound / Partial Issue

-   Create DRAFT -\> SCANNING; chỉ Post Issue mới giảm tồn.

-   Validate item status, box quantity, remaining_qty và available_qty tại thời điểm commit.

-   Lock theo SKU/item/box và optimistic version của balance. Một request commit; request cạnh tranh trả 409/412/422 phù hợp.

-   PARTIALLY_POSTED/POSTED/CLOSED giống inbound; close remainder không đổi tồn.

### 5.3. Stocktake

-   Tạo session và Stocktake_Snapshot tại stocktake_started_at trong cùng transaction.

-   Count serial list hoặc quantity-only; scan/count không đổi tồn. Mỗi count có count_cutoff_at.

-   expected_at_count = snapshot_qty + net_movement_after_snapshot_to_cutoff; variance_to_apply = actual_qty - expected_at_count.

-   QL kho ack movement warning, approve/waive theo line. Apply approved lines atomically với Idempotency-Key + If-Match/session_version.

### 5.4. Warranty

-   Case có item code: item hợp lệ ở ISSUED hoặc RETURNED_TO_CUSTOMER, không có active case khác; chuyển IN_WARRANTY.

-   Không có định danh tin cậy: tạo TEMP_ONLY, sinh temporary_item_code, item_code null, sales_trace_status=NOT_TRACEABLE.

-   Warranty không tăng/giảm sellable stock; chỉ IN_STOCK tính available.

### 5.5. Reversal / Correction Guard

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Rule ID**   **Rule / Guard**
  ------------- -------------------------------------------------------------------------------------------------------------------------------------------------------
  BR-REV-01     Mọi sửa sai sau Post dùng append-only reversal/correction; không UPDATE/DELETE movement gốc.

  BR-REV-02     Bắt buộc inventory.reversal.post, reason_code, reason_text, actor, audit, Idempotency-Key và If-Match.

  BR-REV-03     Reverse Receipt chỉ áp dụng cho reversible_qty chưa có downstream Issue/Warranty/Adjustment/Reversal phụ thuộc.

  BR-REV-04     Nếu có downstream dependency, phải đảo dependency theo thứ tự ngược hoặc tạo correction chain được phê duyệt; API trả 409 nếu gọi sai thứ tự.

  BR-REV-05     Cho phép partial reversal; tổng qty các reversal đã POSTED không vượt qty movement gốc.

  BR-REV-06     Reverse Issue chỉ cộng tồn khi hàng vật lý đã quay lại kho và return_condition = SELLABLE.

  BR-REV-07     Hàng quay lại DEFECTIVE/NON_SELLABLE không cộng available_qty; cập nhật condition/status phù hợp và vẫn ghi movement/audit.

  BR-REV-08     Không reverse trực tiếp một reversal POSTED. Sửa tiếp bằng correction mới tham chiếu movement gần nhất trong correction_chain_id.

  BR-REV-09     Một movement/reversible scope bị khóa trong transaction; concurrent reversal chỉ một request thành công.

  BR-REV-10     Warranty active, stocktake variance đang Apply hoặc posting transaction chưa hoàn tất là guard chặn reversal trực tiếp.

  BR-REV-11     Report hiển thị movement gốc, reversal/correction và net effect; không ẩn hoặc sửa lịch sử.

  BR-REV-12     Retry cùng Idempotency-Key/payload trả kết quả cũ; cùng key khác payload trả 409; failure rollback reversal document/line/movement/balance/subledger.
  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------

  --------------------------------------------------------------------------------------------------------------------------------------------
  **TC ID**   **Scenario**                                      **Expected result**
  ----------- ------------------------------------------------- ------------------------------------------------------------------------------
  TC-REV-02   Reverse Receipt khi item đã được Issue            409 DOWNSTREAM_DEPENDENCY_EXISTS; không đổi tồn.

  TC-REV-03   Partial Reverse Receipt 3/10 rồi reverse thêm 8   Lần hai bị chặn vì vượt reversible_qty còn 7.

  TC-REV-04   Reverse Issue hàng quay lại SELLABLE              Tạo ISSUE_REVERSED, item IN_STOCK, available +N đúng một lần.

  TC-REV-05   Reverse Issue hàng quay lại DEFECTIVE             Không cộng available; condition DEFECTIVE/NON_SELLABLE; report trace đầy đủ.

  TC-REV-06   Hai request reversal đồng thời cùng movement      Một request commit; request còn lại 409/412; không duplicate movement.

  TC-REV-07   Sửa một reversal đã POSTED                        Không edit/reverse trực tiếp; tạo correction mới trong chain.
  --------------------------------------------------------------------------------------------------------------------------------------------

### 5.6. Identity, Master Data, Reports và Export

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Rule group**    **Summary**
  ----------------- -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  BR-IAM-01..08     Account unique; inactive deny; password/token policy do Tech baseline chốt; role/permission least privilege; backend enforcement; permission change audit; no self-escalation; scope assignment explicit.

  BR-AGY-01         Agency là nơi nhận, không phải warehouse; inactive không chọn cho document mới.

  BR-DEF-01..04     Defect code unique; inactive không chọn mới; history không bị đổi; free-text note vẫn cho phép.

  BR-MST-01..07     Brand/category/model/power/package/version active/unique; code đã được tham chiếu không đổi; deactivate thay vì xóa.

  BR-TRACE-01..06   Query phải phân biệt balance, item/serial, container mapping và movement; không double count; scope/permission enforced.

  BR-RPT-01..07     XNT = opening + receipt - issue + adjust + reversal; reversal hiển thị gross và net; filter/period/timezone nhất quán.

  BR-EXP-01..05     Export dùng cùng filter/scope với màn hình; audit; tên file có timestamp; PII bị giới hạn; file lỗi không được công bố hoàn tất.
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 6. STATE MODEL

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Object**           **States**                                                                     **Key transition / stock effect**
  -------------------- ------------------------------------------------------------------------------ ---------------------------------------------------------------
  Inbound              DRAFT, SCANNING, PARTIALLY_POSTED, POSTED, CLOSED, CANCELLED                   Post subset +N; close remainder 0; cancel chỉ trước post đầu.

  Outbound             DRAFT, SCANNING, PARTIALLY_POSTED, POSTED, CLOSED, CANCELLED                   Post subset -N; close remainder 0; cancel chỉ trước post đầu.

  Item                 NEW, IN_STOCK, ISSUED, IN_WARRANTY, RETURNED_TO_CUSTOMER, LOST, ADJUSTED_OUT   Chỉ IN_STOCK tính available.

  Carton               EMPTY, IN_STOCK, PARTIAL, ISSUED, CLOSED                                       Container state; không có stock bucket riêng.

  Box                  RECEIVED_PENDING_PUTAWAY, STORED, PARTIAL, EMPTY, CLOSED                       Quantity subledger; tray mapping không movement.

  Stocktake            DRAFT, COUNTING, REVIEWING, APPLIED, CANCELLED                                 Chỉ Apply Adjustment đổi tồn.

  Stocktake Variance   PENDING, APPROVED, WAIVED, APPLIED                                             Chỉ APPROVED được Apply; WAIVED cần reason.

  Warranty             RECEIVED, CHECKING, REPAIRING, COMPLETED, RETURNED, CANCELLED                  Không có sellable stock movement.

  Reversal             DRAFT, CONFIRMED, POSTED, CANCELLED                                            POSTED tạo reverse movement; original remains.

  Correction chain     OPEN, RESOLVED                                                                 Append-only chain; không edit movement đã post.

  User/Agency/Master   ACTIVE, INACTIVE                                                               Inactive giữ lịch sử; không dùng cho transaction mới.
  -------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 7. DATA MODEL AND DATA DICTIONARY

### 7.1. DEC-INV-001 Source of Truth

  --------------------------------------------------------------------------------------------------------------------------------
  **Layer**                       **Authority**             **Rule**
  ------------------------------- ------------------------- ----------------------------------------------------------------------
  Inventory_Movement              Authoritative ledger      Append-only; movement_key/idempotency_key unique.

  Inventory_Balance               Projection                Updated in same transaction with ledger; optimistic version.

  Item_Instance                   Serial subledger          State/count reconcile to balance.

  Container_Item / Box quantity   Quantity subledger        Quantity/mapping reconcile to balance; no double count.

  Reversal/Correction chain       Audit correction ledger   References original movement; net effect reported, history retained.
  --------------------------------------------------------------------------------------------------------------------------------

### 7.2. Logical ERD - BA baseline

*Các sơ đồ dưới đây là logical ERD ở mức BA. Physical data type, index, partition và migration DDL được Tech Lead hoàn thiện/approve tại WWHN-31 mà không thay đổi business semantics đã chốt.*

`<img src="media/image1.png" alt="Logical ERD core inventory, posting, stocktake and reversal for Hoa Nam WMS." title="Logical ERD core inventory, posting, stocktake and reversal for Hoa Nam WMS." style="width:7.2in;height:2.7905883639545057in" />`{=html}

Hình 1 - Logical ERD core inventory, posting, stocktake và reversal

`<img src="media/image2.png" alt="Logical ERD identity, agency, warranty and App PV master data for Hoa Nam WMS." title="Logical ERD identity, agency, warranty and App PV master data for Hoa Nam WMS." style="width:7.2in;height:3.155003280839895in" />`{=html}

Hình 2 - Logical ERD identity, agency, warranty và App PV masters

### 7.3. Relationship/cardinality baseline

  ----------------------------------------------------------------------------------------------------------
  **Relationship**                        **Cardinality**   **Rule**
  --------------------------------------- ----------------- ------------------------------------------------
  Warehouse - Inventory_Balance           1-N               MVP chỉ có 01 warehouse vận hành.

  SKU - Item_Instance                     1-N               Áp dụng serial tracked.

  SKU - Inventory_Balance                 1-N               Theo warehouse + stock_status.

  SKU - Inventory_Movement                1-N               Mọi movement gắn SKU.

  Physical_Code - Item/Container          1-0..1            Một physical code định danh một target active.

  Container - Container_Item              1-N               Carton/Box/Tray mapping; không stock độc lập.

  Inbound_Document - Inbound_Line         1-N               Line có expected/posted/remaining.

  Outbound_Document - Outbound_Line       1-N               Line có planned/issued/remaining.

  Posting source - Inventory_Movement     1-N               Mỗi partial post có thể sinh nhiều movement.

  Stocktake_Session - Snapshot/Variance   1-N               Snapshot immutable; variance line-based.

  Reversal_Document - Reversal_Line       1-N               Line tham chiếu original_movement_id.

  Agency - Outbound_Document              1-N               Agency chỉ là nơi nhận.

  Item_Instance - Warranty_Case           0..1-N            TEMP_ONLY không có item_id.

  Defect_Catalog - Warranty_Case          1-N               Defect inactive vẫn giữ lịch sử.

  SKU - PV_Product_Profile                1-0..1            Chỉ published profile hiển thị.

  PV_Profile - Industry/Tag               N-N               Qua mapping tables.

  User - Role - Permission                N-N               Qua User_Role và Role_Permission.
  ----------------------------------------------------------------------------------------------------------

### 7.4. Field-level Data Dictionary - BA baseline

*Type dưới đây là logical type. Tech có thể ánh xạ sang PostgreSQL/ORM cụ thể tại WWHN-31, nhưng không được đổi requiredness, uniqueness, authority hoặc stock semantics nếu chưa có Change Decision.*

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Entity**                              **Field**                                                **Logical type**           **Required**         **Rule / Index / Meaning**
  --------------------------------------- -------------------------------------------------------- -------------------------- -------------------- ------------------------------------------------------------------------------------------
  Warehouse                               warehouse_id                                             UUID                       Y                    PK

  Warehouse                               warehouse_code                                           VARCHAR(30)                Y                    UQ; MVP một active warehouse

  Warehouse                               warehouse_name                                           VARCHAR(200)               Y                    

  Warehouse                               status                                                   ENUM                       Y                    ACTIVE/INACTIVE

  Warehouse                               created_at/updated_at                                    TIMESTAMPTZ                Y                    audit timestamps

  User                                    user_id                                                  UUID                       Y                    PK

  User                                    username                                                 VARCHAR(100)               Y                    UQ, normalized

  User                                    display_name                                             VARCHAR(200)               Y                    

  User                                    email/phone                                              VARCHAR                    N                    PII; access controlled

  User                                    status                                                   ENUM                       Y                    ACTIVE/INACTIVE/LOCKED

  User                                    version                                                  INT                        Y                    optimistic locking

  Role / Permission                       role_id / permission_id                                  UUID                       Y                    PK

  Role / Permission                       role_code / permission_key                               VARCHAR(100)               Y                    UQ

  Role / Permission                       name                                                     VARCHAR(200)               Y                    

  Role / Permission                       status                                                   ENUM                       Y                    ACTIVE/INACTIVE

  Role / Permission                       scope_type                                               ENUM                       N                    SYSTEM/WAREHOUSE/FUNCTION

  User_Role / Role_Permission             user_id, role_id                                         UUID                       Y                    composite UQ

  User_Role / Role_Permission             permission_id                                            UUID                       Y                    role-permission composite UQ

  User_Role / Role_Permission             scope_ref                                                VARCHAR/UUID               N                    explicit scope

  User_Role / Role_Permission             effective_from/to                                        TIMESTAMPTZ                N                    optional validity

  Agency                                  agency_id                                                UUID                       Y                    PK

  Agency                                  agency_code                                              VARCHAR(50)                Y                    UQ

  Agency                                  agency_name                                              VARCHAR(200)               Y                    

  Agency                                  phone/address                                            VARCHAR                    N                    PII/business contact

  Agency                                  status                                                   ENUM                       Y                    ACTIVE/INACTIVE

  Agency                                  version                                                  INT                        Y                    optimistic locking

  SKU                                     sku_id                                                   UUID                       Y                    PK

  SKU                                     sku_code                                                 VARCHAR(100)               Y                    UQ; immutable after stock

  SKU                                     sku_name                                                 VARCHAR(255)               Y                    

  SKU                                     sku_type                                                 ENUM                       Y                    PRODUCT/COMPONENT

  SKU                                     unit                                                     VARCHAR(30)                Y                    

  SKU                                     is_serial_tracked                                        BOOLEAN                    Y                    immutable after stock

  SKU                                     brand_id/category_id/model_id                            UUID                       N                    FK masters

  SKU                                     power_or_voltage                                         VARCHAR(50)                N                    

  SKU                                     package_variant                                          VARCHAR(50)                N                    

  SKU                                     version_code                                             VARCHAR(30)                Y                    default V1

  SKU                                     published_to_app                                         BOOLEAN                    Y                    default false

  SKU                                     status                                                   ENUM                       Y                    ACTIVE/INACTIVE

  Product_Master                          master_id                                                UUID                       Y                    PK

  Product_Master                          master_type                                              ENUM                       Y                    BRAND/CATEGORY/MODEL/PACKAGE/POWER

  Product_Master                          code                                                     VARCHAR(80)                Y                    UQ within type

  Product_Master                          name                                                     VARCHAR(200)               Y                    

  Product_Master                          status                                                   ENUM                       Y                    ACTIVE/INACTIVE

  Defect_Catalog                          defect_id                                                UUID                       Y                    PK

  Defect_Catalog                          defect_code                                              VARCHAR(50)                Y                    UQ

  Defect_Catalog                          defect_name                                              VARCHAR(200)               Y                    

  Defect_Catalog                          description                                              TEXT                       N                    

  Defect_Catalog                          status                                                   ENUM                       Y                    ACTIVE/INACTIVE

  Physical_Code                           physical_code_id                                         UUID                       Y                    PK

  Physical_Code                           code_value                                               VARCHAR(100)               Y                    UQ

  Physical_Code                           code_type                                                ENUM                       Y                    ITEM/CARTON/COMPONENT_SERIAL/BOX/TRAY/TEMP_WARRANTY

  Physical_Code                           carrier_type                                             ENUM                       Y                    ITEM requires RFID/NFC chip; others flexible

  Physical_Code                           hardware_uid                                             VARCHAR(150)               N                    UQ when populated

  Physical_Code                           status                                                   ENUM                       Y                    ACTIVE/LOST/REPLACED/INACTIVE

  Item_Instance                           item_id                                                  UUID                       Y                    PK

  Item_Instance                           sku_id                                                   UUID                       Y                    FK SKU

  Item_Instance                           physical_code_id                                         UUID                       Y for PRODUCT        UQ active mapping

  Item_Instance                           serial_number                                            VARCHAR(100)               N                    UQ when populated

  Item_Instance                           container_id                                             UUID                       N                    FK Container

  Item_Instance                           status                                                   ENUM                       Y                    NEW/IN_STOCK/ISSUED/IN_WARRANTY/RETURNED_TO_CUSTOMER/LOST/ADJUSTED_OUT

  Item_Instance                           item_condition                                           ENUM                       Y                    NORMAL/DEMO/DEFECTIVE/WARRANTY_RETURN/NON_SELLABLE

  Item_Instance                           import_batch_id                                          UUID                       N                    FK Import_Batch

  Item_Instance                           version                                                  INT                        Y                    optimistic locking

  Container                               container_id                                             UUID                       Y                    PK

  Container                               container_code                                           VARCHAR(100)               Y                    UQ

  Container                               container_type                                           ENUM                       Y                    CARTON/BOX/TRAY

  Container                               physical_code_id                                         UUID                       Y                    FK/UQ active mapping

  Container                               primary_sku_id                                           UUID                       N                    required for CARTON/BOX; null for mixed tray

  Container                               parent_container_id                                      UUID                       N                    BOX -\> TRAY

  Container                               status                                                   ENUM                       Y                    type-specific state

  Container                               version                                                  INT                        Y                    optimistic locking

  Container_Item                          container_item_id                                        UUID                       Y                    PK

  Container_Item                          container_id                                             UUID                       Y                    FK

  Container_Item                          item_id                                                  UUID                       N                    for serial item

  Container_Item                          sku_id                                                   UUID                       N                    for quantity-only

  Container_Item                          quantity                                                 DECIMAL(18,3)              N                    \>0 for quantity-only

  Container_Item                          status                                                   ENUM                       Y                    ACTIVE/REMOVED

  Container_Item                          UQ rule                                                  \-                         Y                    no duplicate active item; one SKU per carton/box

  Inventory_Balance                       warehouse_id                                             UUID                       Y                    FK

  Inventory_Balance                       sku_id                                                   UUID                       Y                    FK

  Inventory_Balance                       stock_status                                             ENUM                       Y                    AVAILABLE/NON_SELLABLE/etc.

  Inventory_Balance                       available_qty                                            DECIMAL(18,3)              Y                    projection

  Inventory_Balance                       version                                                  INT                        Y                    optimistic locking

  Inventory_Balance                       updated_at                                               TIMESTAMPTZ                Y                    

  Inventory_Balance                       Composite UQ                                             \-                         Y                    warehouse_id + sku_id + stock_status

  Inventory_Movement                      movement_id                                              UUID                       Y                    PK

  Inventory_Movement                      movement_key                                             VARCHAR(128)               Y                    UQ

  Inventory_Movement                      movement_type                                            ENUM                       Y                    OPENING/RECEIPT_POSTED/ISSUE_POSTED/ADJUST_IN/ADJUST_OUT/RECEIPT_REVERSED/ISSUE_REVERSED

  Inventory_Movement                      warehouse_id/sku_id                                      UUID                       Y                    FK

  Inventory_Movement                      qty_delta                                                DECIMAL(18,3)              Y                    signed

  Inventory_Movement                      source_type/source_id/source_line_id                     VARCHAR/UUID               Y                    trace source

  Inventory_Movement                      original_movement_id                                     UUID                       N                    for reversal/correction

  Inventory_Movement                      correction_chain_id                                      UUID                       N                    chain

  Inventory_Movement                      idempotency_key                                          VARCHAR(128)               Y for stock action   UQ within operation scope

  Inventory_Movement                      posted_at                                                TIMESTAMPTZ                Y                    authoritative time

  Inventory_Movement                      created_by/request_id                                    UUID/VARCHAR               Y                    audit

  Inbound_Document / Line                 inbound_id / line_id                                     UUID                       Y                    PK

  Inbound_Document / Line                 document_no                                              VARCHAR(50)                Y                    UQ

  Inbound_Document / Line                 status / line_status                                     ENUM                       Y                    partial states

  Inbound_Document / Line                 source_reference                                         VARCHAR(200)               N                    supplier/import ref

  Inbound_Document / Line                 sku_id                                                   UUID                       Y on line            FK

  Inbound_Document / Line                 expected_qty                                             DECIMAL(18,3)              Y                    \>=0

  Inbound_Document / Line                 posted_qty                                               DECIMAL(18,3)              Y                    \>=0

  Inbound_Document / Line                 remaining_qty                                            DECIMAL(18,3)              Y                    derived/validated

  Inbound_Document / Line                 cancelled_remainder_qty                                  DECIMAL(18,3)              Y                    default 0

  Inbound_Document / Line                 version                                                  INT                        Y                    If-Match

  Outbound_Document / Line                outbound_id / line_id                                    UUID                       Y                    PK

  Outbound_Document / Line                document_no                                              VARCHAR(50)                Y                    UQ

  Outbound_Document / Line                agency_id                                                UUID                       N                    recipient only

  Outbound_Document / Line                receiver_name                                            VARCHAR(200)               N                    other recipient

  Outbound_Document / Line                status / line_status                                     ENUM                       Y                    partial states

  Outbound_Document / Line                sku_id                                                   UUID                       Y on line            FK

  Outbound_Document / Line                planned_qty/issued_qty/remaining_qty                     DECIMAL(18,3)              Y                    validated

  Outbound_Document / Line                cancelled_remainder_qty                                  DECIMAL(18,3)              Y                    default 0

  Outbound_Document / Line                version                                                  INT                        Y                    If-Match

  Posting_Event                           posting_event_id                                         UUID                       Y                    PK

  Posting_Event                           document_type/document_id                                ENUM/UUID                  Y                    source

  Posting_Event                           idempotency_key                                          VARCHAR(128)               Y                    UQ in action scope

  Posting_Event                           payload_hash                                             VARCHAR(128)               Y                    same-key payload guard

  Posting_Event                           result_reference                                         JSONB                      Y                    replay response

  Posting_Event                           posted_at/posted_by/request_id                           TIMESTAMPTZ/UUID/VARCHAR   Y                    audit

  Stocktake_Session                       session_id                                               UUID                       Y                    PK

  Stocktake_Session                       scope_type/scope_ref                                     ENUM/UUID                  Y/N                  WHOLE/SKU/GROUP

  Stocktake_Session                       stocktake_started_at                                     TIMESTAMPTZ                Y                    snapshot time

  Stocktake_Session                       status                                                   ENUM                       Y                    DRAFT/COUNTING/REVIEWING/APPLIED/CANCELLED

  Stocktake_Session                       session_version                                          INT                        Y                    If-Match

  Stocktake_Session                       created_by/applied_by                                    UUID                       Y/N                  audit

  Stocktake_Snapshot / Count / Variance   snapshot_id/count_id/variance_id                         UUID                       Y                    PK

  Stocktake_Snapshot / Count / Variance   session_id                                               UUID                       Y                    FK

  Stocktake_Snapshot / Count / Variance   sku_id/item_id/container_id                              UUID                       N                    scope grain

  Stocktake_Snapshot / Count / Variance   snapshot_qty/actual_qty/expected_at_count/variance_qty   DECIMAL(18,3)              Y as applicable      formula controlled

  Stocktake_Snapshot / Count / Variance   count_cutoff_at                                          TIMESTAMPTZ                N                    on count confirm

  Stocktake_Snapshot / Count / Variance   line_status                                              ENUM                       Y on variance        PENDING/APPROVED/WAIVED/APPLIED

  Stocktake_Snapshot / Count / Variance   waive_reason/acknowledged_movement_ids                   TEXT/JSONB                 N                    required by rule

  Reversal_Document / Line                reversal_id / reversal_line_id                           UUID                       Y                    PK

  Reversal_Document / Line                reversal_type                                            ENUM                       Y                    RECEIPT/ISSUE/CORRECTION

  Reversal_Document / Line                original_document_id                                     UUID                       Y                    source

  Reversal_Document / Line                original_movement_id                                     UUID                       Y on line            FK

  Reversal_Document / Line                qty_to_reverse                                           DECIMAL(18,3)              Y                    \<= reversible_qty

  Reversal_Document / Line                return_condition                                         ENUM                       N                    SELLABLE/DEFECTIVE/NON_SELLABLE

  Reversal_Document / Line                reason_code/reason_text                                  VARCHAR/TEXT               Y                    

  Reversal_Document / Line                status                                                   ENUM                       Y                    DRAFT/CONFIRMED/POSTED/CANCELLED

  Reversal_Document / Line                correction_chain_id                                      UUID                       Y                    chain root

  Reversal_Document / Line                idempotency_key/payload_hash                             VARCHAR                    Y                    replay guard

  Reversal_Document / Line                version                                                  INT                        Y                    If-Match

  Warranty_Case                           case_id                                                  UUID                       Y                    PK

  Warranty_Case                           warranty_case_code                                       VARCHAR(50)                Y                    UQ

  Warranty_Case                           item_id/item_code                                        UUID/VARCHAR               N                    null for TEMP_ONLY

  Warranty_Case                           temporary_item_code                                      VARCHAR(100)               Y if TEMP_ONLY       UQ

  Warranty_Case                           identification_status                                    ENUM                       Y                    IDENTIFIED/TEMP_ONLY

  Warranty_Case                           customer_name/customer_phone                             VARCHAR                    Y                    PII

  Warranty_Case                           defect_id/reported_defect/confirmed_defect               UUID/TEXT                  Y/N                  

  Warranty_Case                           missing_code_reason/manual_product_description           TEXT                       Y if TEMP_ONLY       

  Warranty_Case                           status                                                   ENUM                       Y                    warranty states

  Warranty_Case                           received_date/returned_date                              TIMESTAMPTZ                Y/N                  

  Warranty_Case                           version                                                  INT                        Y                    optimistic lock

  PV_Product_Profile                      profile_id                                               UUID                       Y                    PK

  PV_Product_Profile                      sku_id                                                   UUID                       Y                    UQ FK

  PV_Product_Profile                      display_name/description/specification                   VARCHAR/TEXT/JSONB         Y/N                  public content

  PV_Product_Profile                      published                                                BOOLEAN                    Y                    default false

  PV_Product_Profile                      featured/sort_order                                      BOOLEAN/INT                Y                    

  PV_Product_Profile                      last_inventory_sync_at                                   TIMESTAMPTZ                N                    when cache used

  PV_Product_Profile                      version                                                  INT                        Y                    If-Match

  PV_Industry / Tag / Mapping             industry_id/tag_id                                       UUID                       Y                    PK

  PV_Industry / Tag / Mapping             code                                                     VARCHAR(80)                Y                    UQ

  PV_Industry / Tag / Mapping             name                                                     VARCHAR(200)               Y                    

  PV_Industry / Tag / Mapping             status                                                   ENUM                       Y                    ACTIVE/INACTIVE

  PV_Industry / Tag / Mapping             profile_id + industry_id/tag_id                          UUID                       Y                    composite UQ

  PV_Industry / Tag / Mapping             priority                                                 INT                        N                    recommendation order

  PV_Recommendation_Rule                  rule_id                                                  UUID                       Y                    PK

  PV_Recommendation_Rule                  industry_id                                              UUID                       Y                    FK

  PV_Recommendation_Rule                  category_id/tag_id/sku_id                                UUID                       N                    selector/priority

  PV_Recommendation_Rule                  priority                                                 INT                        Y                    unique within rule scope

  PV_Recommendation_Rule                  status                                                   ENUM                       Y                    ACTIVE/INACTIVE

  Import_Batch                            import_batch_id                                          UUID                       Y                    PK

  Import_Batch                            import_batch_code                                        VARCHAR(80)                Y                    UQ

  Import_Batch                            source_file/checksum                                     VARCHAR                    Y                    trace source

  Import_Batch                            status                                                   ENUM                       Y                    DRAFT/DRY_RUN_VALIDATED/COMMITTED/FAILED/ROLLED_BACK

  Import_Batch                            valid/invalid/duplicate_count                            INT                        Y                    

  Import_Batch                            reconciliation_status                                    ENUM                       Y                    PENDING/PASS/FAIL

  Import_Batch                            committed_at/rollback_reference                          TIMESTAMPTZ/VARCHAR        N                    

  Audit_Log / Reconciliation_Incident     audit_id/incident_id                                     UUID                       Y                    PK

  Audit_Log / Reconciliation_Incident     actor_id/action/object_type/object_id                    UUID/VARCHAR               Y                    audit

  Audit_Log / Reconciliation_Incident     reason/request_id                                        TEXT/VARCHAR               N/Y                  

  Audit_Log / Reconciliation_Incident     before_data/after_data                                   JSONB                      N                    permission controlled

  Audit_Log / Reconciliation_Incident     divergence_type/status/resolution_ref                    ENUM/VARCHAR               N                    for incident

  Audit_Log / Reconciliation_Incident     created_at/resolved_at                                   TIMESTAMPTZ                Y/N                  retention applies
  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 7.5. Reconciliation Controls

  ----------------------------------------------------------------------------------------------------------------------------------------------------
  **Check**             **Expected**
  --------------------- ------------------------------------------------------------------------------------------------------------------------------
  Ledger to Balance     Opening + Receipt - Issue + Adjust In - Adjust Out + Reversal = Balance

  Serial subledger      Balance available = count eligible Item_Instance status IN_STOCK and sellable condition

  Quantity subledger    Balance available = sum available quantity by box/subledger

  Container integrity   Container mapping does not create additional inventory bucket

  Divergence            Create Reconciliation_Incident, block readiness/sign-off; resolve with audited reversal/adjustment, never direct ledger edit
  ----------------------------------------------------------------------------------------------------------------------------------------------------

## 8. API, ERROR, PERMISSION AND UI BASELINE

### 8.1. Endpoint Catalog và action contract

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Group**   **Endpoints**                                                     **Permission**                                        **Request/Behavior**                                  **Primary errors**
  ----------- ----------------------------------------------------------------- ----------------------------------------------------- ----------------------------------------------------- ---------------------
  IAM         GET/POST /users; GET/POST /roles; PUT /roles/{id}/permissions     iam.user.\* / iam.role.\*                             User/Role payload; If-Match on update                 403/409/412/422

  Master      /agencies; /defects; /product-masters; /skus                      agency/defect/master/sku.\*                           Unique, active/inactive, immutable code after use     403/409/412/422

  Scan        POST /scan-batches/classify                                       scan.classify                                         scan_source + up to 500 code_value; no stock effect   403/409/422

  Inbound     /inbound-documents; /post-receipt; /close-remainder; /reversals   inbound.create/post/close; inventory.reversal.post    Idempotency-Key + If-Match on stock actions           403/409/412/422/428

  Outbound    /outbound-documents; /post-issue; /close-remainder; /reversals    outbound.create/post/close; inventory.reversal.post   Lock/available/remaining/dependency guard             403/409/412/422/428

  Inventory   GET /inventory; GET /inventory/trace/{code}                       inventory.view / inventory.trace                      Balance + subledger + movement chain                  403/404

  Stocktake   /stocktake-sessions; /counts; /variances; /apply-adjustment       stocktake.count/apply                                 Snapshot, cutoff, ack, Idempotency-Key, If-Match      403/409/412/422/428

  Warranty    /warranty-cases; /status; /return                                 warranty.manage                                       TEMP_ONLY conditional fields; one active case         403/409/412/422

  PV          GET /pv/products; /industries; /recommendations; PATCH /publish   pv.product.view / pv.publish                          Read-only inventory; publish uses If-Match            403/404/412/422

  Reports     GET /reports/\*; POST /reports/export                             report.view / report.export                           Same filters/scope; export audited                    403/422
  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 8.2. HTTP/Error Contract

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **HTTP**   **Error code examples**                                                                       **Use**
  ---------- --------------------------------------------------------------------------------------------- ---------------------------------------------------------
  400        INVALID_JSON / INVALID_QUERY / INVALID_PATH                                                   Malformed request.

  401        AUTH_REQUIRED / TOKEN_EXPIRED                                                                 Authentication.

  403        PERMISSION_DENIED / DATA_SCOPE_DENIED                                                         Action/scope denial.

  404        RESOURCE_NOT_FOUND                                                                            Missing or masked resource.

  409        STATE_CONFLICT / IDEMPOTENCY_CONFLICT / CONCURRENCY_CONFLICT / DOWNSTREAM_DEPENDENCY_EXISTS   State/duplicate/concurrent/dependency guard.

  412        STALE_VERSION                                                                                 If-Match/session version stale.

  422        VALIDATION_ERROR / BUSINESS_RULE_VIOLATION / INSUFFICIENT_STOCK                               Field/business validation.

  428        PRECONDITION_REQUIRED                                                                         Missing If-Match/Idempotency-Key where required.

  500        INTERNAL_ERROR                                                                                Unexpected; return request_id; transaction rolled back.
  ------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 8.3. Screen / UX Baseline (BA-level)

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Screen ID**   **Screen**                        **Key controls/actions**                                               **Actors**          **Critical UX rule**
  --------------- --------------------------------- ---------------------------------------------------------------------- ------------------- ------------------------------------------------
  SCR-IAM-01      User list/detail                  Search, status, role/scope assignment, deactivate                      Admin               No self-escalation; audit permission change

  SCR-MST-01      SKU & Product Master              Brand/category/model/power/package/version, serial mode                Admin/QL kho        Immutable code/mode after stock

  SCR-AGY-01      Agency master                     Create/edit/deactivate; recipient info                                 Admin/QL kho        No warehouse/balance fields

  SCR-DEF-01      Defect catalog                    Code/name/status                                                       Admin/QL kho        Inactive not selectable for new case

  SCR-IN-01       Inbound list/detail               DRAFT/SCANNING/partial progress; scan; post subset; close remainder    QL/NV kho           Buttons by permission; idempotency feedback

  SCR-OUT-01      Outbound list/detail              Agency recipient; scan carton/item/box; post subset; close remainder   QL/NV kho           Available/remaining visible; concurrency error

  SCR-REV-01      Reversal/Correction               Original movement, reversible qty, dependencies, reason, condition     QL kho              Guard summary before confirm; chain history

  SCR-INV-01      Inventory query                   Filter SKU/type/status; balance; container hint                        QL/NV/Lãnh đạo      No double count

  SCR-TRACE-01    Physical trace                    Search code; item/container/current status/movement chain              Authorized users    Masked by scope

  SCR-STK-01      Stocktake session/count/review    Snapshot, actual, movement warning, approve/waive/apply                QL/NV kho           If-Match; ack warning

  SCR-WAR-01      Warranty receive/process/return   Identified/TEMP_ONLY; customer; defect; status                         Warranty staff      Conditional required fields; one active case

  SCR-RPT-01      Reports                           Inventory, XNT, history, stocktake, warranty defect                    QL/Lãnh đạo         Filter/period/timezone; export by permission

  SCR-PV-01       PV product list                   Search/filter industry/category/stock; card available_qty              PV viewer           Responsive 360/768/1280+

  SCR-PV-02       PV product detail                 Images/spec/compatible accessories/industries/available_qty            PV viewer           Read-only; no internal data

  SCR-PV-ADM-01   PV publish/master                 Publish, tag, industry mapping, recommendation priority                Admin/QL kho        Inactive SKU cannot publish

  SCR-DEMO-01     Demo scanner component            Generate one/many code values in supported flows                       Operational users   Same validation as real/manual scan
  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

*Detailed Figma is a downstream FE/QA readiness artifact. BA screen baseline above is sufficient to close functional specification content; it does not mean detailed UI has been approved.*

### 8.4. OpenAPI Draft và Endpoint Permission Mapping

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Artifact**                        **Reference**           **Content**                                                                                                         **Status**
  ----------------------------------- ----------------------- ------------------------------------------------------------------------------------------------------------------- ---------------------------------------
  Machine-readable OpenAPI baseline   OpenAPI BA Draft.yaml   OpenAPI 3.0.3 paths, security, headers, pagination, request/response/error schemas, examples và x-contract-tests.   ISSUED; TECH APPROVAL PENDING WWHN-31

  Physical OpenAPI/Schema             WWHN-31                 Tech refines schema/index/protocol and approves contract after BA Done.                                             PLANNED AFTER BA DONE

  Permission implementation/review    WWHN-40                 Map x-permission to backend middleware/policy and QA negative tests.                                                PLANNED / LINKED
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 9. NON-FUNCTIONAL REQUIREMENTS

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **NFR ID**    **Requirement**                                                  **Measure / TC**
  ------------- ---------------------------------------------------------------- ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  NFR-SRS-001   All stock-changing actions idempotent.                           Retry 3 times cùng key/payload = một movement/balance change; TC-NFR-001.

  NFR-SRS-002   Atomic transaction cho document/movement/balance/subledger.      Injected failure rollback all; TC-NFR-002.

  NFR-SRS-003   Concurrency prevents negative stock and duplicate reversal.      Parallel requests: one success; others conflict/insufficient; TC-NFR-003.

  NFR-SRS-004   Audit post/reversal/close remainder/permission/master changes.   Actor/time/reason/request_id/before-after where allowed; TC-NFR-004.

  NFR-SRS-005   Security enforced by backend permission and scope.               403/404 policy; negative endpoint tests; TC-NFR-005.

  NFR-SRS-006   Responsive/browser/device matrix.                                Desktop \>=1280px; tablet \>=768px; mobile \>=360px; Chrome/Edge latest 2 versions, Chrome Android latest 2, Safari iOS latest 2; TC-NFR-006.

  NFR-SRS-007   Scan throughput và feedback latency.                             Batch \<=500 mã; batch P95 \<=5 giây; single-scan UI feedback P95 \<=500ms và end-to-end classify P95 \<=1.5 giây trong test environment; TC-NFR-007.

  NFR-SRS-008   Observability.                                                   X-Request-Id echo/log; stock action log includes idempotency/result; TC-NFR-008.

  NFR-SRS-009   Read/list API latency.                                           P95 \<=2 giây, P99 \<=4 giây với page_size\<=50 và approved dataset; TC-NFR-009.

  NFR-SRS-010   Stock action latency và concurrency.                             P95 \<=3 giây; tối thiểu 20 concurrent stock actions và 50 concurrent read users; không âm kho/double movement; TC-NFR-010.

  NFR-SRS-011   Availability và backup.                                          Availability \>=99.5% theo tháng, loại trừ planned maintenance đã thông báo; daily backup; restore test mỗi quý hoặc trước go-live; TC-NFR-011.

  NFR-SRS-012   Recovery Point Objective.                                        RPO \<=24 giờ; TC-NFR-012.

  NFR-SRS-013   Recovery Time Objective.                                         RTO \<=8 giờ cho dịch vụ core sau sự cố được phân loại; TC-NFR-013.

  NFR-SRS-014   Retention audit/movement.                                        Giữ tối thiểu 5 năm hoặc lâu hơn nếu hợp đồng/pháp luật yêu cầu; không hard-delete ledger; TC-NFR-014.

  NFR-SRS-015   Privacy, masking và warranty PII retention.                      Full PII chỉ cho warranty.pii.view; masked by default; export PII cần warranty.pii.export; không raw PII trong app log; retention 5 năm sau đóng case rồi anonymize/purge; TC-NFR-015.

  NFR-SRS-016   Export safety và cache staleness.                                Export private/audited/scope-correct; App PV inventory cache max staleness \<=60 giây, hiển thị data_as_of; TC-NFR-016.

  NFR-SRS-017   Migration/import capacity.                                       Dry-run tối thiểu 10,000 rows/file; error file đầy đủ row/field/code/message; no commit khi critical error \>0; TC-NFR-017.

  NFR-SRS-018   Retention/purge execution.                                       Audit/movement \>=5 years; warranty PII purge/anonymize job có report, retry và legal-hold exclusion; TC-NFR-018.
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 10. TRACEABILITY MASTER

### 10.1. Functional Traceability

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Source/Coverage**    **REQ**       **BR/Decision**                        **AC**       **API/UI/Data**              **TC**       **Evidence Location**
  ---------------------- ------------- -------------------------------------- ------------ ---------------------------- ------------ -----------------------------------------------------------------------------------------------------------------------------------------------------
  SRC-002 / CTR-WM-001   REQ-SRS-001   BR-SCOPE-01..05                        AC-SRS-001   Warehouse/Agency/Inventory   TC-SRS-001   Contract scope: comment 12625/pages 1-2 + WWHN-333 solution baseline; WWHN-33 Test Master/execution planned after BA Done; actual evidence pending.

  SRC-001/SRC-002        REQ-SRS-002   BR-SKU-01..10; BR-COMP-01              AC-SRS-002   SKU API/UI/Data              TC-SRS-002   Contract scope: comment 12625/pages 1-2 + WWHN-333 solution baseline; WWHN-33 Test Master/execution planned after BA Done; actual evidence pending.

  DR-CARRIER-001         REQ-SRS-003   BR-CODE-01..07; BR-CARRIER-01          AC-SRS-003   Item/Physical_Code           TC-SRS-003   WWHN-33 Test Master/execution planned after BA Done; actual evidence pending.

  DR-CARRIER-001         REQ-SRS-004   BR-CARRIER-01; BR-CON-01..08           AC-SRS-004   Container/Physical_Code      TC-SRS-004   WWHN-33 Test Master/execution planned after BA Done; actual evidence pending.

  SRC-001/SRC-002        REQ-SRS-005   BR-SCAN-01..02; BR-SCAN-ADD-01..04     AC-SRS-005   Scan API/UI                  TC-SRS-005   WWHN-33 Test Master/execution planned after BA Done; actual evidence pending.

  SRC-002                REQ-SRS-006   BR-IN-01; BR-INOUT-01..02              AC-SRS-006   Inbound API/UI               TC-SRS-006   WWHN-33 Test Master/execution planned after BA Done; actual evidence pending.

  DEC-PARTIAL-001        REQ-SRS-007   BR-PARTIAL-IN-01..03                   AC-SRS-007   Post Receipt/Data/Report     TC-SRS-007   WWHN-33 Test Master/execution planned after BA Done; actual evidence pending.

  DEC-INV-001            REQ-SRS-008   BR-IN-02; BR-IDEMP-01..03; BR-TXN-01   AC-SRS-008   API/DB transaction           TC-SRS-008   WWHN-33 Test Master/execution planned after BA Done; actual evidence pending.

  SRC-002                REQ-SRS-009   BR-PTW-01..02                          AC-SRS-009   Putaway UI/API               TC-SRS-009   WWHN-33 Test Master/execution planned after BA Done; actual evidence pending.

  SRC-002                REQ-SRS-010   BR-OUT-01; BR-INOUT-01..02             AC-SRS-010   Outbound API/UI              TC-SRS-010   WWHN-33 Test Master/execution planned after BA Done; actual evidence pending.

  DEC-PARTIAL-001        REQ-SRS-011   BR-PARTIAL-OUT-01..03                  AC-SRS-011   Post Issue/Data/Report       TC-SRS-011   WWHN-33 Test Master/execution planned after BA Done; actual evidence pending.

  DEC-INV-001            REQ-SRS-012   BR-OUT-02..05; BR-CONC-01..03          AC-SRS-012   API/locking                  TC-SRS-012   WWHN-33 Test Master/execution planned after BA Done; actual evidence pending.

  SRC-002/DEC-INV-001    REQ-SRS-013   BR-REV-01..12                          AC-SRS-013   Reversal API/Data            TC-SRS-013   WWHN-33 Test Master/execution planned after BA Done; actual evidence pending.

  SRC-001/SRC-002        REQ-SRS-014   BR-COUNT-01..10                        AC-SRS-014   Stocktake API/UI/Data        TC-SRS-014   WWHN-33 Test Master/execution planned after BA Done; actual evidence pending.

  DEC-INV-001            REQ-SRS-015   BR-COUNT-11..13; BR-IDEMP-01           AC-SRS-015   Stocktake API/DB             TC-SRS-015   WWHN-33 Test Master/execution planned after BA Done; actual evidence pending.

  SRC-001/SRC-002        REQ-SRS-016   BR-WAR-01..13                          AC-SRS-016   Warranty API/UI              TC-SRS-016   Contract scope: comment 12625/pages 1-2 + WWHN-333 solution baseline; WWHN-33 Test Master/execution planned after BA Done; actual evidence pending.

  DR-PLAT-001            REQ-SRS-017   BR-PV-01..08; NFR-SRS-006              AC-SRS-017   PV UI/API                    TC-SRS-017   Contract scope: comment 12625/pages 1-2 + WWHN-333 solution baseline; WWHN-33 Test Master/execution planned after BA Done; actual evidence pending.

  SRC-001/SRC-002        REQ-SRS-018   BR-PV-02..03; BR-PV-09..14             AC-SRS-018   PV API/read model            TC-SRS-018   Contract scope: comment 12625/pages 1-2 + WWHN-333 solution baseline; WWHN-33 Test Master/execution planned after BA Done; actual evidence pending.

  SRC-001/SRC-002        REQ-SRS-019   BR-PV-05..08                           AC-SRS-019   Recommendation API/UI        TC-SRS-019   Contract scope: comment 12625/pages 1-2 + WWHN-333 solution baseline; WWHN-33 Test Master/execution planned after BA Done; actual evidence pending.

  SRC-002                REQ-SRS-020   BR-PV-01; BR-PV-MST-01..03             AC-SRS-020   PV Admin API/UI              TC-SRS-020   Contract scope: comment 12625/pages 1-2 + WWHN-333 solution baseline; WWHN-33 Test Master/execution planned after BA Done; actual evidence pending.

  SRC-002                REQ-SRS-021   BR-MIG-01..06                          AC-SRS-021   Import API/Data              TC-SRS-021   WWHN-33 Test Master/execution planned after BA Done; actual evidence pending.

  SRC-002                REQ-SRS-022   BR-UAT-01..05                          AC-SRS-022   Operational package          TC-SRS-022   WWHN-33 Test Master/execution planned after BA Done; actual evidence pending.

  DEC-INV-001            REQ-SRS-023   BR-INV-01; BR-RECON-01..04             AC-SRS-023   Data/ADR                     TC-SRS-023   WWHN-33 Test Master/execution planned after BA Done; actual evidence pending.

  SRC-002/WWHN-40        REQ-SRS-024   BR-API-ERR-01..09; BR-SEC-01..05       AC-SRS-024   API/Security                 TC-SRS-024   WWHN-31/WWHN-40; approval/evidence pending downstream.

  DEC-BA-GATE-001        REQ-SRS-025   BR-BASE-01..06                         AC-SRS-025   Baseline Index               TC-SRS-025   WWHN-31/WWHN-40; approval/evidence pending downstream.

  SRC-002                REQ-SRS-026   BR-IAM-01..08                          AC-SRS-026   IAM API/UI/Data              TC-SRS-026   WWHN-40 for permission implementation; WWHN-33 for QA evidence.

  SRC-002                REQ-SRS-027   BR-AGY-01; BR-SCOPE-02..04             AC-SRS-027   Agency API/UI/Data           TC-SRS-027   Contract scope: comment 12625/pages 1-2 + WWHN-333 solution baseline; WWHN-33 Test Master/execution planned after BA Done; actual evidence pending.

  SRC-002                REQ-SRS-028   BR-DEF-01..04                          AC-SRS-028   Defect API/UI/Data           TC-SRS-028   Contract scope: comment 12625/pages 1-2 + WWHN-333 solution baseline; WWHN-33 Test Master/execution planned after BA Done; actual evidence pending.

  SRC-002                REQ-SRS-029   BR-MST-01..07; BR-SKU-05..10           AC-SRS-029   Product Master API/UI        TC-SRS-029   Contract scope: comment 12625/pages 1-2 + WWHN-333 solution baseline; WWHN-33 Test Master/execution planned after BA Done; actual evidence pending.

  SRC-001/SRC-002        REQ-SRS-030   BR-TRACE-01..06; BR-CON-04             AC-SRS-030   Inventory Query/Trace        TC-SRS-030   Contract scope: comment 12625/pages 1-2 + WWHN-333 solution baseline; WWHN-33 Test Master/execution planned after BA Done; actual evidence pending.

  SRC-002                REQ-SRS-031   BR-RPT-01..07                          AC-SRS-031   Reports API/UI               TC-SRS-031   Contract scope: comment 12625/pages 1-2 + WWHN-333 solution baseline; WWHN-33 Test Master/execution planned after BA Done; actual evidence pending.

  SRC-002                REQ-SRS-032   BR-EXP-01..05                          AC-SRS-032   Report Export                TC-SRS-032   Contract scope: comment 12625/pages 1-2 + WWHN-333 solution baseline; WWHN-33 Test Master/execution planned after BA Done; actual evidence pending.

  SRC-001/SRC-002        REQ-SRS-033   BR-PV-MST-01..06                       AC-SRS-033   PV Master API/UI             TC-SRS-033   Contract scope: comment 12625/pages 1-2 + WWHN-333 solution baseline; WWHN-33 Test Master/execution planned after BA Done; actual evidence pending.

  SRC-002                REQ-SRS-034   BR-BATCH-01..05; BR-SKU-06..08         AC-SRS-034   Import/Condition Data        TC-SRS-034   Contract scope: comment 12625/pages 1-2 + WWHN-333 solution baseline; WWHN-33 Test Master/execution planned after BA Done; actual evidence pending.
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 10.2. NFR Traceability

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Source**                               **NFR**       **Rule**    **TC**       **Measure**                                                                  **Evidence**
  ---------------------------------------- ------------- ----------- ------------ ---------------------------------------------------------------------------- ---------------------------------------------------------------------------------------
  DEC-INV-001 / SRS v1.2                   NFR-SRS-001   BR-NFR-01   TC-NFR-001   Retry 3 times cùng key/payload = một movement/balance change                 WWHN-33 execution evidence pending; technical setup WWHN-31/WWHN-40 where applicable.

  DEC-INV-001 / SRS v1.2                   NFR-SRS-002   BR-NFR-02   TC-NFR-002   Injected failure rollback all                                                WWHN-33 execution evidence pending; technical setup WWHN-31/WWHN-40 where applicable.

  DEC-INV-001 / SRS v1.2                   NFR-SRS-003   BR-NFR-03   TC-NFR-003   Parallel requests: one success                                               WWHN-33 execution evidence pending; technical setup WWHN-31/WWHN-40 where applicable.

  DEC-INV-001 / SRS v1.2                   NFR-SRS-004   BR-NFR-04   TC-NFR-004   Actor/time/reason/request_id/before-after where allowed                      WWHN-33 execution evidence pending; technical setup WWHN-31/WWHN-40 where applicable.

  SRS v1.2 baseline / WWHN-31 or WWHN-40   NFR-SRS-005   BR-NFR-05   TC-NFR-005   403/404 policy                                                               WWHN-33 execution evidence pending; technical setup WWHN-31/WWHN-40 where applicable.

  SRS v1.2 baseline / WWHN-31 or WWHN-40   NFR-SRS-006   BR-NFR-06   TC-NFR-006   Desktop \>=1280px                                                            WWHN-33 execution evidence pending; technical setup WWHN-31/WWHN-40 where applicable.

  SRS v1.2 baseline / WWHN-31 or WWHN-40   NFR-SRS-007   BR-NFR-07   TC-NFR-007   Tối thiểu 500 mã/lần, xử lý \<=5 giây trong môi trường test chuẩn            WWHN-33 execution evidence pending; technical setup WWHN-31/WWHN-40 where applicable.

  DEC-INV-001 / SRS v1.2                   NFR-SRS-008   BR-NFR-08   TC-NFR-008   X-Request-Id echo/log                                                        WWHN-33 execution evidence pending; technical setup WWHN-31/WWHN-40 where applicable.

  SRS v1.2 baseline / WWHN-31 or WWHN-40   NFR-SRS-009   BR-NFR-09   TC-NFR-009   P95 \<=2 giây cho inventory/product/report list theo dataset test approved   WWHN-33 execution evidence pending; technical setup WWHN-31/WWHN-40 where applicable.

  SRS v1.2 baseline / WWHN-31 or WWHN-40   NFR-SRS-010   BR-NFR-10   TC-NFR-010   P95 \<=3 giây, không tính thời gian thiết bị scan                            WWHN-33 execution evidence pending; technical setup WWHN-31/WWHN-40 where applicable.

  SRS v1.2 baseline / WWHN-31 or WWHN-40   NFR-SRS-011   BR-NFR-11   TC-NFR-011   Backup dữ liệu hằng ngày                                                     WWHN-33 execution evidence pending; technical setup WWHN-31/WWHN-40 where applicable.

  SRS v1.2 baseline / WWHN-31 or WWHN-40   NFR-SRS-012   BR-NFR-12   TC-NFR-012   RPO \<=24 giờ                                                                WWHN-33 execution evidence pending; technical setup WWHN-31/WWHN-40 where applicable.

  SRS v1.2 baseline / WWHN-31 or WWHN-40   NFR-SRS-013   BR-NFR-13   TC-NFR-013   RTO \<=8 giờ cho dịch vụ core sau sự cố được phân loại                       WWHN-33 execution evidence pending; technical setup WWHN-31/WWHN-40 where applicable.

  SRS v1.2 baseline / WWHN-31 or WWHN-40   NFR-SRS-014   BR-NFR-14   TC-NFR-014   Giữ tối thiểu 5 năm hoặc lâu hơn nếu hợp đồng/pháp luật yêu cầu              WWHN-33 execution evidence pending; technical setup WWHN-31/WWHN-40 where applicable.

  SRS v1.2 baseline / WWHN-31 or WWHN-40   NFR-SRS-015   BR-NFR-15   TC-NFR-015   Minimize customer name/phone                                                 WWHN-33 execution evidence pending; technical setup WWHN-31/WWHN-40 where applicable.

  SRS v1.2 baseline / WWHN-31 or WWHN-40   NFR-SRS-016   BR-NFR-16   TC-NFR-016   Export đúng scope                                                            WWHN-33 execution evidence pending; technical setup WWHN-31/WWHN-40 where applicable.
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **TRACEABILITY INTEGRITY RESULT:** 34 functional requirements (33 Must, 1 Should), 34 mapped ACs, 34 mapped functional TCs, 16 NFRs và 16 TC-NFR; 0 orphan requirement/TC/NFR và 0 duplicate ID ở mức tài liệu. Actual execution evidence chưa được tạo và được ghi đúng là PENDING tại WWHN-33; không còn tuyên bố "0 orphan evidence" hoặc "test passed" trước khi thực thi.
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 11. TECHNICAL BASELINE AND READINESS GATE

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Gate**                      **Current**                                                 **Ready/Done condition**
  ----------------------------- ----------------------------------------------------------- -----------------------------------------------------------------------------------------------------
  BA requirement content        COMPLETE                                                    REQ/AC/TC, rules, P0 closure, contract/source mapping complete.

  Formal BA baseline approval   ON WWHN-333 DONE                                            PM/PO transition is approval evidence; effective 23/07/2026.

  Contract coverage             COMPLETE AT GENERAL SCOPE                                   Contract 07072026/HOANAM-A4I pages 1-2; detail by BRD/SRS; no appendix.

  Logical ERD/Data Dictionary   BA DRAFT COMPLETE                                           Physical baseline/index/DDL approval at WWHN-31.

  OpenAPI                       MACHINE-READABLE BASELINE ISSUED / TECH APPROVAL PENDING    OpenAPI BA Draft.yaml; Tech approval at WWHN-31 before contract-test execution.

  Permission                    BA MAPPING COMPLETE                                         Implementation/review at WWHN-40.

  UX                            P1 FIELD SPEC COMPLETE / APPROVAL OR FIGMA WAIVER PENDING   Detailed Figma optional when PM/PO/FE/QA approve embedded field spec.

  QA testability/execution      TEST DESIGN REQUIRED / EXECUTION PENDING                    QA Lead approves design in WWHN-33 before SRS Done; actual execution remains release evidence.

  Core flow Ready for Dev       NO UNTIL WWHN-31 + WWHN-40                                  BA Done is prerequisite, not equivalent to Ready for Dev.

  Ready for QA                  NO UNTIL WWHN-33                                            No claim of executed evidence before task starts.

  SRS/FRD Jira Done             CONDITIONAL                                                 PM/PO baseline approval + QA Test Design approval, or explicit PM/PO waiver with scope/risk/expiry.
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  KẾT LUẬN SRS GATE: Tài liệu v1.3 đủ nội dung để formal review. WWHN-333 chỉ được transition DONE khi PM/PO phê duyệt baseline và QA Lead phê duyệt Test Design/Scenario Master, hoặc có waiver rõ. WWHN-31/WWHN-40 đóng technical contract/permission; actual QA execution, UAT, migration dry-run và cutover evidence thuộc các ticket release riêng.
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 12. CHANGE LOG

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Version**   **Date**     **Change**                                                                                                                                                                                                                                                                                                                     **Author**
  ------------- ------------ ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ ------------
  1.0           22/07/2026   Initial SRS/FRD from BRD v3.10; decisions and normalized traceability.                                                                                                                                                                                                                                                         BA

  1.1           22/07/2026   Prepared formal review edition; external closure register and readiness distinction.                                                                                                                                                                                                                                           BA

  1.2           23/07/2026   Closed BA-controlled P0: contract reference, source supersession, conditional approval/effective date, internal decision governance, 34 REQ/AC/TC, logical ERD, field-level dictionary, OpenAPI draft, screen/permission baseline, NFR metrics, NFR traceability, reversal/correction guards and downstream Jira gate model.   BA

  1.3           23/07/2026   Closed document-level P1: GWT core AC, stock-action test design, complete DD supplement, machine-readable OpenAPI, approved-field-spec candidate, quantified NFR, privacy, Physical Code lifecycle, report contract, location decision, migration/dry-run criteria and separated SRS DoD from release execution.               BA
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 13. P1 IMPLEMENTATION READINESS BASELINE

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **P1 CONTROL STATEMENT: Các mục dưới đây đóng khoảng trống đặc tả ở mức tài liệu. Những mục cần phê duyệt hoặc thực thi thật vẫn được ghi rõ owner, Jira gate và waiver; tài liệu không tuyên bố OpenAPI/Figma/Test/UAT đã được phê duyệt hoặc chạy khi chưa có evidence.**
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 13.1. P1 Closure Matrix

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **P1**   **Content**                                **Closure**                                        **Evidence/Gate**
  -------- ------------------------------------------ -------------------------------------------------- -------------------------------------------------------------------------------
  P1-01    Core AC Given/When/Then                    CLOSED IN v1.3                                     §13.2; actor/permission/precondition/test data/state/stock/movement/API/UI/DB

  P1-02    Stock-changing action test coverage        CLOSED AT TEST-DESIGN LEVEL                        §13.3; execution evidence at WWHN-33/release tickets

  P1-03    Complete Data Dictionary attributes        CLOSED AT LOGICAL BASELINE                         §13.4; physical DDL/index approval at WWHN-31

  P1-04    Machine-readable OpenAPI                   ARTIFACT ISSUED / TECH APPROVAL PENDING            OpenAPI BA Draft.yaml; §13.5; WWHN-31

  P1-05    Screen Map/Figma/field specification       FIELD SPEC COMPLETE / APPROVAL OR WAIVER PENDING   §13.6; DR-UX-001

  P1-06    Quantified NFR                             CLOSED IN v1.3                                     §13.7 + NFR-SRS-001..018

  P1-07    Privacy contract                           POLICY BASELINE COMPLETE / APPROVAL PENDING        §13.8; DR-PRIV-001

  P1-08    Physical Code lifecycle                    CLOSED IN v1.3                                     §13.9

  P1-09    Report contract                            CLOSED IN v1.3                                     §13.10

  P1-10    location_hint/zone decision                CLOSED BY DECISION                                 DR-LOC-001; §13.11

  P1-11    Migration template/sample/error/dry-run    DOC CONTRACT CLOSED / ACTUAL DRY-RUN PENDING       §13.12; Jira comment 12634

  P1-12    SRS DoD separated from release execution   CLOSED IN v1.3                                     §13.13; DEC-BA-GATE-001
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 13.2. Core Acceptance Criteria - Given/When/Then

  ------------------------------------------------------------------------------------------------------------------------------------------------
  **Test data IDs below are design fixtures. QA may map them to concrete seed records in WWHN-33 without changing expected business semantics.**
  ------------------------------------------------------------------------------------------------------------------------------------------------

  ------------------------------------------------------------------------------------------------------------------------------------------------

#### AC-GWT-IN-001 - Post Receipt partial thành công

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Clause**   **Specification**
  ------------ --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Given**    Actor NV kho có inbound.post; Inbound IN-001 ở SCANNING, version=3; line SKU HN-MK-001 expected=10, posted=0, remaining=10; 10 ITEM có chip ACTIVE và chưa thuộc movement; Balance AVAILABLE=20.

  **When**     Client POST /inbound-documents/{id}/post-receipt với Idempotency-Key=IK-IN-001, If-Match="3" và subset quantity=6.

  **Then**     • API trả 200, ETag/version=4, replayed=false; UI hiển thị thành công và tiến độ 6/10.\
               • Document/line = PARTIALLY_POSTED; posted_qty=6; remaining_qty=4.\
               • Stock +6; Inventory_Balance AVAILABLE 20→26; 6 Item_Instance → IN_STOCK.\
               • Tạo đúng một Posting_Event và các RECEIPT_POSTED movement; movement_key/idempotency unique.\
               • Document, movement, balance, item/subledger và audit commit trong cùng transaction.
  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

#### AC-GWT-OUT-001 - Post Issue thành công và không âm kho

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Clause**   **Specification**
  ------------ ----------------------------------------------------------------------------------------------------------------------------------------------------
  **Given**    Actor NV kho có outbound.post; Outbound OUT-001 SCANNING version=2; line planned=5, issued=0; Balance AVAILABLE=8; 5 ITEM IN_STOCK; Agency active.

  **When**     Client POST /outbound-documents/{id}/post-issue với IK-OUT-001, If-Match="2" và 5 item_codes.

  **Then**     • API 200; UI success, issued 5/5.\
               • Document/line = POSTED; Item status IN_STOCK→ISSUED.\
               • Stock -5; Balance 8→3; đúng 5 ISSUE_POSTED movement hoặc movement lines theo physical design.\
               • Agency chỉ lưu recipient; không tạo agency stock/balance.\
               • Atomic commit; audit actor/request/idempotency; không có negative balance.
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------

#### AC-GWT-STK-001 - Apply Adjustment line approved

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Clause**   **Specification**
  ------------ -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Given**    Actor QL kho có stocktake.apply; Session STK-001 REVIEWING version=5; snapshot=100, net movement đến cutoff=-10, actual=88, expected=90, variance=-2; line APPROVED và movement warning đã ack.

  **When**     Client POST /stocktake-sessions/{id}/apply-adjustment với IK-STK-001, If-Match="5" và approved_variance_id.

  **Then**     • API 200; UI line → APPLIED và session đóng khi mọi line APPLIED/WAIVED.\
               • Stock -2; Balance 90→88; tạo ADJUST_OUT -2.\
               • Snapshot/count giữ immutable; variance lưu expected/actual/adjustment link.\
               • Retry cùng key trả response cũ; không tạo adjustment lần hai.\
               • Transaction lỗi ở movement/balance/subledger phải rollback all.
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

#### AC-GWT-REV-001 - Reverse Issue hàng SELLABLE

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Clause**   **Specification**
  ------------ ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Given**    Actor QL kho có inventory.reversal.post; original ISSUE movement qty=-4, reversible_qty=4, không dependency; hàng vật lý đã quay lại; item condition SELLABLE; reversal DRAFT version=1.

  **When**     Client POST /reversals/{id}/post với IK-REV-001 và If-Match="1".

  **Then**     • API 200; reversal=POSTED; UI hiển thị original, reverse và net effect.\
               • Stock +4; item→IN_STOCK; Balance tăng đúng 4.\
               • Tạo ISSUE_REVERSED tham chiếu original_movement_id/correction_chain_id; movement gốc giữ nguyên.\
               • Audit reason/actor/request_id; report gross và net.\
               • Concurrent post trên cùng reversible scope chỉ một request commit.
  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

#### AC-GWT-REV-002 - Reverse Receipt bị chặn bởi downstream dependency

  ----------------------------------------------------------------------------------------------------------------------------------
  **Clause**   **Specification**
  ------------ ---------------------------------------------------------------------------------------------------------------------
  **Given**    Actor có quyền; original RECEIPT đã có item được ISSUE; reversible request qty=1; document version hiện hành.

  **When**     Client gọi Post Reversal.

  **Then**     • API 409 DOWNSTREAM_DEPENDENCY_EXISTS; UI hiển thị dependency và hướng dẫn đảo theo thứ tự ngược.\
               • Không đổi document gốc, stock, balance, item, movement.\
               • Không tạo Posting_Event/Reversal movement; audit failed attempt chỉ chứa request_id và error code, không raw PII.
  ----------------------------------------------------------------------------------------------------------------------------------

#### AC-GWT-IDEMP-001 - Retry cùng key và conflict khác payload

  ----------------------------------------------------------------------------------------------------------------
  **Clause**   **Specification**
  ------------ ---------------------------------------------------------------------------------------------------
  **Given**    Một stock action đã commit bằng key IK-001 và payload hash H1.

  **When**     Client retry lần 2/3 cùng key+H1, sau đó gọi key IK-001 với payload hash H2.

  **Then**     • Retry H1 trả cùng business result, replayed=true, không đổi stock/movement/version lần hai.\
               • Request H2 trả 409 IDEMPOTENCY_CONFLICT.\
               • DB giữ một Posting_Event trong operation scope; payload_hash và result_reference được kiểm tra.
  ----------------------------------------------------------------------------------------------------------------

#### AC-GWT-CONC-001 - Hai request cạnh tranh cùng tồn

  -------------------------------------------------------------------------------------------------------------------------------------
  **Clause**   **Specification**
  ------------ ------------------------------------------------------------------------------------------------------------------------
  **Given**    Balance SKU=5, version=10; hai request Post Issue A và B cùng yêu cầu 4; cả hai có key khác nhau và permission hợp lệ.

  **When**     A và B commit gần đồng thời.

  **Then**     • Tối đa một request thành công; request còn lại trả 409/412/422 theo điểm phát hiện.\
               • Balance không âm; tổng ISSUE_POSTED \<=5.\
               • UI request thua hiển thị refresh/retry, không tự submit lại bằng key mới.\
               • Lock/version và transaction log có evidence cho contract/concurrency test.
  -------------------------------------------------------------------------------------------------------------------------------------

#### AC-GWT-MIG-001 - Commit Opening Balance sau dry-run PASS

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Clause**   **Specification**
  ------------ ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Given**    Actor Data Migration Owner có quyền; batch MIG-001 DRY_RUN_VALIDATED; checksum không đổi; critical_error=0; duplicate=0; reconciliation pre-check PASS; QL kho xác nhận opening.

  **When**     Client commit migration batch trong cutover window.

  **Then**     • Tạo opening records/movements đúng một lần; Import_Batch=COMMITTED.\
               • Ledger/balance/subledger reconciliation=PASS; UI hiển thị summary và evidence link.\
               • Nếu bất kỳ bước nào lỗi, rollback toàn batch và status=FAILED; không để partial opening data.\
               • Re-run cùng checksum/batch key không duplicate; file đổi checksum phải dry-run lại.
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 13.3. Stock-changing Action Test Design Matrix

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **TC ID**   **Action**         **Coverage**            **Permission**            **Test data/precondition**                                      **Expected HTTP**            **Expected state/stock/movement/DB**
  ----------- ------------------ ----------------------- ------------------------- --------------------------------------------------------------- ---------------------------- ---------------------------------------------------------------------------------------------------------------------------------------------------
  TC-P1-001   POST_RECEIPT       POSITIVE                inbound.post              Valid actor/state/data/header                                   200/201                      +N; RECEIPT_POSTED; Commit exactly once; expected state, stock, movement and audit.

  TC-P1-002   POST_RECEIPT       NEGATIVE                inbound.post              Invalid quantity/code/dependency/business rule                  409/422                      +N; RECEIPT_POSTED; No stock/movement; return field/business error.

  TC-P1-003   POST_RECEIPT       PERMISSION              inbound.post              Actor lacks permission or scope                                 403/404                      +N; RECEIPT_POSTED; No write; denied attempt logged without sensitive payload.

  TC-P1-004   POST_RECEIPT       INVALID_STATE           inbound.post              Source DRAFT/CANCELLED/POSTED or line not eligible              409                          +N; RECEIPT_POSTED; No write; state remains unchanged.

  TC-P1-005   POST_RECEIPT       RETRY_SAME_KEY          inbound.post              3 retries same key + same canonical payload                     200 replay                   +N; RECEIPT_POSTED; One Posting_Event/movement/balance effect; replayed=true.

  TC-P1-006   POST_RECEIPT       SAME_KEY_DIFF_PAYLOAD   inbound.post              Same key, different payload hash                                409                          +N; RECEIPT_POSTED; No second write; IDEMPOTENCY_CONFLICT.

  TC-P1-007   POST_RECEIPT       CONCURRENCY             inbound.post              Two parallel requests on same stock/reversible/session scope    one 200; other 409/412/422   +N; RECEIPT_POSTED; No negative stock, over-post, duplicate movement or lost update.

  TC-P1-008   POST_RECEIPT       ROLLBACK                inbound.post              Inject failure after document/movement/balance/subledger step   500                          +N; RECEIPT_POSTED; Rollback all business writes; request_id/error audit retained; retry can safely execute.

  TC-P1-009   POST_ISSUE         POSITIVE                outbound.post             Valid actor/state/data/header                                   200/201                      -N; ISSUE_POSTED; Commit exactly once; expected state, stock, movement and audit.

  TC-P1-010   POST_ISSUE         NEGATIVE                outbound.post             Invalid quantity/code/dependency/business rule                  409/422                      -N; ISSUE_POSTED; No stock/movement; return field/business error.

  TC-P1-011   POST_ISSUE         PERMISSION              outbound.post             Actor lacks permission or scope                                 403/404                      -N; ISSUE_POSTED; No write; denied attempt logged without sensitive payload.

  TC-P1-012   POST_ISSUE         INVALID_STATE           outbound.post             Source DRAFT/CANCELLED/POSTED or line not eligible              409                          -N; ISSUE_POSTED; No write; state remains unchanged.

  TC-P1-013   POST_ISSUE         RETRY_SAME_KEY          outbound.post             3 retries same key + same canonical payload                     200 replay                   -N; ISSUE_POSTED; One Posting_Event/movement/balance effect; replayed=true.

  TC-P1-014   POST_ISSUE         SAME_KEY_DIFF_PAYLOAD   outbound.post             Same key, different payload hash                                409                          -N; ISSUE_POSTED; No second write; IDEMPOTENCY_CONFLICT.

  TC-P1-015   POST_ISSUE         CONCURRENCY             outbound.post             Two parallel requests on same stock/reversible/session scope    one 200; other 409/412/422   -N; ISSUE_POSTED; No negative stock, over-post, duplicate movement or lost update.

  TC-P1-016   POST_ISSUE         ROLLBACK                outbound.post             Inject failure after document/movement/balance/subledger step   500                          -N; ISSUE_POSTED; Rollback all business writes; request_id/error audit retained; retry can safely execute.

  TC-P1-017   APPLY_ADJUSTMENT   POSITIVE                stocktake.apply           Valid actor/state/data/header                                   200/201                      ±N; ADJUST_IN/ADJUST_OUT; Commit exactly once; expected state, stock, movement and audit.

  TC-P1-018   APPLY_ADJUSTMENT   NEGATIVE                stocktake.apply           Invalid quantity/code/dependency/business rule                  409/422                      ±N; ADJUST_IN/ADJUST_OUT; No stock/movement; return field/business error.

  TC-P1-019   APPLY_ADJUSTMENT   PERMISSION              stocktake.apply           Actor lacks permission or scope                                 403/404                      ±N; ADJUST_IN/ADJUST_OUT; No write; denied attempt logged without sensitive payload.

  TC-P1-020   APPLY_ADJUSTMENT   INVALID_STATE           stocktake.apply           Source DRAFT/CANCELLED/POSTED or line not eligible              409                          ±N; ADJUST_IN/ADJUST_OUT; No write; state remains unchanged.

  TC-P1-021   APPLY_ADJUSTMENT   RETRY_SAME_KEY          stocktake.apply           3 retries same key + same canonical payload                     200 replay                   ±N; ADJUST_IN/ADJUST_OUT; One Posting_Event/movement/balance effect; replayed=true.

  TC-P1-022   APPLY_ADJUSTMENT   SAME_KEY_DIFF_PAYLOAD   stocktake.apply           Same key, different payload hash                                409                          ±N; ADJUST_IN/ADJUST_OUT; No second write; IDEMPOTENCY_CONFLICT.

  TC-P1-023   APPLY_ADJUSTMENT   CONCURRENCY             stocktake.apply           Two parallel requests on same stock/reversible/session scope    one 200; other 409/412/422   ±N; ADJUST_IN/ADJUST_OUT; No negative stock, over-post, duplicate movement or lost update.

  TC-P1-024   APPLY_ADJUSTMENT   ROLLBACK                stocktake.apply           Inject failure after document/movement/balance/subledger step   500                          ±N; ADJUST_IN/ADJUST_OUT; Rollback all business writes; request_id/error audit retained; retry can safely execute.

  TC-P1-025   POST_REVERSAL      POSITIVE                inventory.reversal.post   Valid actor/state/data/header                                   200/201                      inverse/net; RECEIPT_REVERSED/ISSUE_REVERSED/CORRECTION; Commit exactly once; expected state, stock, movement and audit.

  TC-P1-026   POST_REVERSAL      NEGATIVE                inventory.reversal.post   Invalid quantity/code/dependency/business rule                  409/422                      inverse/net; RECEIPT_REVERSED/ISSUE_REVERSED/CORRECTION; No stock/movement; return field/business error.

  TC-P1-027   POST_REVERSAL      PERMISSION              inventory.reversal.post   Actor lacks permission or scope                                 403/404                      inverse/net; RECEIPT_REVERSED/ISSUE_REVERSED/CORRECTION; No write; denied attempt logged without sensitive payload.

  TC-P1-028   POST_REVERSAL      INVALID_STATE           inventory.reversal.post   Source DRAFT/CANCELLED/POSTED or line not eligible              409                          inverse/net; RECEIPT_REVERSED/ISSUE_REVERSED/CORRECTION; No write; state remains unchanged.

  TC-P1-029   POST_REVERSAL      RETRY_SAME_KEY          inventory.reversal.post   3 retries same key + same canonical payload                     200 replay                   inverse/net; RECEIPT_REVERSED/ISSUE_REVERSED/CORRECTION; One Posting_Event/movement/balance effect; replayed=true.

  TC-P1-030   POST_REVERSAL      SAME_KEY_DIFF_PAYLOAD   inventory.reversal.post   Same key, different payload hash                                409                          inverse/net; RECEIPT_REVERSED/ISSUE_REVERSED/CORRECTION; No second write; IDEMPOTENCY_CONFLICT.

  TC-P1-031   POST_REVERSAL      CONCURRENCY             inventory.reversal.post   Two parallel requests on same stock/reversible/session scope    one 200; other 409/412/422   inverse/net; RECEIPT_REVERSED/ISSUE_REVERSED/CORRECTION; No negative stock, over-post, duplicate movement or lost update.

  TC-P1-032   POST_REVERSAL      ROLLBACK                inventory.reversal.post   Inject failure after document/movement/balance/subledger step   500                          inverse/net; RECEIPT_REVERSED/ISSUE_REVERSED/CORRECTION; Rollback all business writes; request_id/error audit retained; retry can safely execute.

  TC-P1-033   MIGRATION_COMMIT   POSITIVE                migration.commit          Valid actor/state/data/header                                   200/201                      +opening; OPENING; Commit exactly once; expected state, stock, movement and audit.

  TC-P1-034   MIGRATION_COMMIT   NEGATIVE                migration.commit          Invalid quantity/code/dependency/business rule                  409/422                      +opening; OPENING; No stock/movement; return field/business error.

  TC-P1-035   MIGRATION_COMMIT   PERMISSION              migration.commit          Actor lacks permission or scope                                 403/404                      +opening; OPENING; No write; denied attempt logged without sensitive payload.

  TC-P1-036   MIGRATION_COMMIT   INVALID_STATE           migration.commit          Source DRAFT/CANCELLED/POSTED or line not eligible              409                          +opening; OPENING; No write; state remains unchanged.

  TC-P1-037   MIGRATION_COMMIT   RETRY_SAME_KEY          migration.commit          3 retries same key + same canonical payload                     200 replay                   +opening; OPENING; One Posting_Event/movement/balance effect; replayed=true.

  TC-P1-038   MIGRATION_COMMIT   SAME_KEY_DIFF_PAYLOAD   migration.commit          Same key, different payload hash                                409                          +opening; OPENING; No second write; IDEMPOTENCY_CONFLICT.

  TC-P1-039   MIGRATION_COMMIT   CONCURRENCY             migration.commit          Two parallel requests on same stock/reversible/session scope    one 200; other 409/412/422   +opening; OPENING; No negative stock, over-post, duplicate movement or lost update.

  TC-P1-040   MIGRATION_COMMIT   ROLLBACK                migration.commit          Inject failure after document/movement/balance/subledger step   500                          +opening; OPENING; Rollback all business writes; request_id/error audit retained; retry can safely execute.
  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 13.4. Data Dictionary Governance Supplement

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **The table below supplements §7.4 for every logical field. "NONE" is an explicit no-default decision. Physical storage type, exact index name and DDL are approved at WWHN-31; nullable/unique/FK/privacy/retention semantics may not be weakened without Change Decision.**
  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Entity.Field**                                                                               **Length/Precision**   **Nullable**   **Default**                       **Enum/Constraint**                                                                        **Unique/FK/Index**                   **Data Owner**             **Privacy**             **Retention**                                          **Sample value**
  ---------------------------------------------------------------------------------------------- ---------------------- -------------- --------------------------------- ------------------------------------------------------------------------------------------ ------------------------------------- -------------------------- ----------------------- ------------------------------------------------------ --------------------------
  Warehouse.warehouse_id                                                                         \-                     NO             GENERATED UUID                    \-                                                                                         PK/INDEX; QUERY INDEX                 Master Data / QL kho       INTERNAL                Active life + 5 years reference history                sample-value

  Warehouse.warehouse_code                                                                       30                     NO             NONE                              \-                                                                                         UNIQUE INDEX; QUERY INDEX             Master Data / QL kho       INTERNAL                Active life + 5 years reference history                sample-value

  Warehouse.warehouse_name                                                                       200                    NO             NONE                              \-                                                                                         NO DEDICATED INDEX                    Master Data / QL kho       INTERNAL                Active life + 5 years reference history                Máy khoan mẫu

  Warehouse.status                                                                               \-                     NO             ACTIVE                            ACTIVE/INACTIVE                                                                            QUERY INDEX                           Master Data / QL kho       INTERNAL                Active life + 5 years reference history                ACTIVE

  Warehouse.created_at/updated_at                                                                \-                     NO             CURRENT_TIMESTAMP as applicable   \-                                                                                         QUERY INDEX                           Master Data / QL kho       INTERNAL                Active life + 5 years reference history                sample-value

  User.user_id                                                                                   \-                     NO             GENERATED UUID                    \-                                                                                         PK/INDEX                              IAM / Admin                INTERNAL                Account life + 5 years audit reference                 sample-value

  User.username                                                                                  100                    NO             NONE                              \-                                                                                         UNIQUE INDEX                          IAM / Admin                INTERNAL                Account life + 5 years audit reference                 Máy khoan mẫu

  User.display_name                                                                              200                    NO             NONE                              \-                                                                                         NO DEDICATED INDEX                    IAM / Admin                PII-RESTRICTED          Account life + 5 years audit reference                 Máy khoan mẫu

  User.email/phone                                                                               \-                     YES            NONE                              \-                                                                                         NO DEDICATED INDEX                    IAM / Admin                PII-RESTRICTED          Account life + 5 years audit reference                 090\*\*\*9922

  User.status                                                                                    \-                     NO             ACTIVE                            ACTIVE/INACTIVE/LOCKED                                                                     QUERY INDEX                           IAM / Admin                INTERNAL                Account life + 5 years audit reference                 ACTIVE

  User.version                                                                                   \-                     NO             1                                 \-                                                                                         NO DEDICATED INDEX                    IAM / Admin                INTERNAL                Account life + 5 years audit reference                 1

  Role / Permission.role_id / permission_id                                                      \-                     NO             GENERATED UUID                    \-                                                                                         PK/INDEX                              IAM / Admin                INTERNAL                Account life + 5 years audit reference                 sample-value

  Role / Permission.role_code / permission_key                                                   100                    NO             NONE                              \-                                                                                         UNIQUE INDEX; QUERY INDEX             IAM / Admin                INTERNAL                Account life + 5 years audit reference                 sample-value

  Role / Permission.name                                                                         200                    NO             NONE                              \-                                                                                         NO DEDICATED INDEX                    IAM / Admin                INTERNAL                Account life + 5 years audit reference                 Máy khoan mẫu

  Role / Permission.status                                                                       \-                     NO             ACTIVE                            ACTIVE/INACTIVE                                                                            QUERY INDEX                           IAM / Admin                INTERNAL                Account life + 5 years audit reference                 ACTIVE

  Role / Permission.scope_type                                                                   \-                     YES            NONE                              SYSTEM/WAREHOUSE/FUNCTION                                                                  NO DEDICATED INDEX                    IAM / Admin                INTERNAL                Account life + 5 years audit reference                 sample-value

  User_Role / Role_Permission.user_id, role_id                                                   \-                     NO             NONE                              \-                                                                                         UNIQUE INDEX; FK INDEX                IAM / Admin                INTERNAL                Account life + 5 years audit reference                 sample-value

  User_Role / Role_Permission.permission_id                                                      \-                     NO             NONE                              \-                                                                                         UNIQUE INDEX; FK INDEX                IAM / Admin                INTERNAL                Account life + 5 years audit reference                 sample-value

  User_Role / Role_Permission.scope_ref                                                          per component          YES            NONE                              \-                                                                                         NO DEDICATED INDEX                    IAM / Admin                INTERNAL                Account life + 5 years audit reference                 sample-value

  User_Role / Role_Permission.effective_from/to                                                  \-                     YES            NONE                              \-                                                                                         NO DEDICATED INDEX                    IAM / Admin                INTERNAL                Account life + 5 years audit reference                 sample-value

  Agency.agency_id                                                                               \-                     NO             GENERATED UUID                    \-                                                                                         PK/INDEX                              Master Data / QL kho       INTERNAL                Active life + 5 years reference history                sample-value

  Agency.agency_code                                                                             50                     NO             NONE                              \-                                                                                         UNIQUE INDEX; QUERY INDEX             Master Data / QL kho       INTERNAL                Active life + 5 years reference history                sample-value

  Agency.agency_name                                                                             200                    NO             NONE                              \-                                                                                         NO DEDICATED INDEX                    Master Data / QL kho       INTERNAL                Active life + 5 years reference history                Máy khoan mẫu

  Agency.phone/address                                                                           \-                     YES            NONE                              PII/business contact                                                                       NO DEDICATED INDEX                    Master Data / QL kho       PII-RESTRICTED          Active life + 5 years reference history                090\*\*\*9922

  Agency.status                                                                                  \-                     NO             ACTIVE                            ACTIVE/INACTIVE                                                                            QUERY INDEX                           Master Data / QL kho       INTERNAL                Active life + 5 years reference history                ACTIVE

  Agency.version                                                                                 \-                     NO             1                                 \-                                                                                         NO DEDICATED INDEX                    Master Data / QL kho       INTERNAL                Active life + 5 years reference history                1

  SKU.sku_id                                                                                     \-                     NO             GENERATED UUID                    \-                                                                                         PK/INDEX; QUERY INDEX                 Master Data / QL kho       INTERNAL                Active life + 5 years reference history                sample-value

  SKU.sku_code                                                                                   100                    NO             NONE                              UQ; immutable after stock                                                                  UNIQUE INDEX; QUERY INDEX             Master Data / QL kho       INTERNAL                Active life + 5 years reference history                HN-MK-001-V1

  SKU.sku_name                                                                                   255                    NO             NONE                              \-                                                                                         NO DEDICATED INDEX                    Master Data / QL kho       INTERNAL                Active life + 5 years reference history                Máy khoan mẫu

  SKU.sku_type                                                                                   \-                     NO             NONE                              PRODUCT/COMPONENT                                                                          NO DEDICATED INDEX                    Master Data / QL kho       INTERNAL                Active life + 5 years reference history                sample-value

  SKU.unit                                                                                       30                     NO             NONE                              \-                                                                                         NO DEDICATED INDEX                    Master Data / QL kho       INTERNAL                Active life + 5 years reference history                sample-value

  SKU.is_serial_tracked                                                                          \-                     NO             NONE                              immutable after stock                                                                      NO DEDICATED INDEX                    Master Data / QL kho       INTERNAL                Active life + 5 years reference history                sample-value

  SKU.brand_id/category_id/model_id                                                              \-                     YES            NONE                              \-                                                                                         FK INDEX                              Master Data / QL kho       INTERNAL                Active life + 5 years reference history                sample-value

  SKU.power_or_voltage                                                                           50                     YES            NONE                              \-                                                                                         NO DEDICATED INDEX                    Master Data / QL kho       INTERNAL                Active life + 5 years reference history                sample-value

  SKU.package_variant                                                                            50                     YES            NONE                              \-                                                                                         NO DEDICATED INDEX                    Master Data / QL kho       INTERNAL                Active life + 5 years reference history                sample-value

  SKU.version_code                                                                               30                     NO             V1                                \-                                                                                         QUERY INDEX                           Master Data / QL kho       INTERNAL                Active life + 5 years reference history                sample-value

  SKU.published_to_app                                                                           \-                     NO             false                             \-                                                                                         NO DEDICATED INDEX                    Master Data / QL kho       INTERNAL                Active life + 5 years reference history                sample-value

  SKU.status                                                                                     \-                     NO             ACTIVE                            ACTIVE/INACTIVE                                                                            QUERY INDEX                           Master Data / QL kho       INTERNAL                Active life + 5 years reference history                ACTIVE

  Product_Master.master_id                                                                       \-                     NO             GENERATED UUID                    \-                                                                                         PK/INDEX                              Master Data / QL kho       INTERNAL                Active life + 5 years reference history                sample-value

  Product_Master.master_type                                                                     \-                     NO             NONE                              BRAND/CATEGORY/MODEL/PACKAGE/POWER                                                         NO DEDICATED INDEX                    Master Data / QL kho       INTERNAL                Active life + 5 years reference history                sample-value

  Product_Master.code                                                                            80                     NO             NONE                              \-                                                                                         UNIQUE INDEX; QUERY INDEX             Master Data / QL kho       INTERNAL                Active life + 5 years reference history                sample-value

  Product_Master.name                                                                            200                    NO             NONE                              \-                                                                                         NO DEDICATED INDEX                    Master Data / QL kho       INTERNAL                Active life + 5 years reference history                Máy khoan mẫu

  Product_Master.status                                                                          \-                     NO             ACTIVE                            ACTIVE/INACTIVE                                                                            QUERY INDEX                           Master Data / QL kho       INTERNAL                Active life + 5 years reference history                ACTIVE

  Defect_Catalog.defect_id                                                                       \-                     NO             GENERATED UUID                    \-                                                                                         PK/INDEX                              Warranty Owner             INTERNAL                Active life + 5 years reference history                sample-value

  Defect_Catalog.defect_code                                                                     50                     NO             NONE                              \-                                                                                         UNIQUE INDEX; QUERY INDEX             Warranty Owner             INTERNAL                Active life + 5 years reference history                sample-value

  Defect_Catalog.defect_name                                                                     200                    NO             NONE                              \-                                                                                         NO DEDICATED INDEX                    Warranty Owner             INTERNAL                Active life + 5 years reference history                Máy khoan mẫu

  Defect_Catalog.description                                                                     \-                     YES            NONE                              \-                                                                                         NO DEDICATED INDEX                    Warranty Owner             RESTRICTED              Active life + 5 years reference history                sample-value

  Defect_Catalog.status                                                                          \-                     NO             ACTIVE                            ACTIVE/INACTIVE                                                                            QUERY INDEX                           Warranty Owner             INTERNAL                Active life + 5 years reference history                ACTIVE

  Physical_Code.physical_code_id                                                                 \-                     NO             GENERATED UUID                    \-                                                                                         PK/INDEX; QUERY INDEX                 Master Data / QL kho       INTERNAL                Active life + 5 years reference history                sample-value

  Physical_Code.code_value                                                                       100                    NO             NONE                              \-                                                                                         UNIQUE INDEX; QUERY INDEX             Master Data / QL kho       INTERNAL                Active life + 5 years reference history                HN-ITEM-000001

  Physical_Code.code_type                                                                        \-                     NO             NONE                              ITEM/CARTON/COMPONENT_SERIAL/BOX/TRAY/TEMP_WARRANTY                                        QUERY INDEX                           Master Data / QL kho       INTERNAL                Active life + 5 years reference history                sample-value

  Physical_Code.carrier_type                                                                     \-                     NO             NONE                              ITEM requires RFID/NFC chip; others flexible                                               NO DEDICATED INDEX                    Master Data / QL kho       INTERNAL                Active life + 5 years reference history                sample-value

  Physical_Code.hardware_uid                                                                     150                    YES            NONE                              \-                                                                                         UNIQUE INDEX                          Master Data / QL kho       SECRET/RESTRICTED       Active life + 5 years reference history                E2000017221101441890ABCD

  Physical_Code.status                                                                           \-                     NO             NONE                              ACTIVE/LOST/REPLACED/INACTIVE                                                              QUERY INDEX                           Master Data / QL kho       INTERNAL                Active life + 5 years reference history                ACTIVE

  Item_Instance.item_id                                                                          \-                     NO             GENERATED UUID                    \-                                                                                         PK/INDEX                              Inventory Owner / QL kho   INTERNAL                Active life + 5 years reference history                sample-value

  Item_Instance.sku_id                                                                           \-                     NO             NONE                              \-                                                                                         FK INDEX; QUERY INDEX                 Inventory Owner / QL kho   INTERNAL                Active life + 5 years reference history                sample-value

  Item_Instance.physical_code_id                                                                 \-                     NO             NONE                              \-                                                                                         UNIQUE INDEX; FK INDEX; QUERY INDEX   Inventory Owner / QL kho   INTERNAL                Active life + 5 years reference history                sample-value

  Item_Instance.serial_number                                                                    100                    YES            NONE                              \-                                                                                         UNIQUE INDEX                          Inventory Owner / QL kho   INTERNAL                Active life + 5 years reference history                sample-value

  Item_Instance.container_id                                                                     \-                     YES            NONE                              \-                                                                                         FK INDEX                              Inventory Owner / QL kho   INTERNAL                Active life + 5 years reference history                sample-value

  Item_Instance.status                                                                           \-                     NO             NONE                              NEW/IN_STOCK/ISSUED/IN_WARRANTY/RETURNED_TO_CUSTOMER/LOST/ADJUSTED_OUT                     QUERY INDEX                           Inventory Owner / QL kho   INTERNAL                Active life + 5 years reference history                NEW

  Item_Instance.item_condition                                                                   \-                     NO             NONE                              NORMAL/DEMO/DEFECTIVE/WARRANTY_RETURN/NON_SELLABLE                                         NO DEDICATED INDEX                    Inventory Owner / QL kho   INTERNAL                Active life + 5 years reference history                sample-value

  Item_Instance.import_batch_id                                                                  \-                     YES            NONE                              \-                                                                                         FK INDEX                              Inventory Owner / QL kho   INTERNAL                Active life + 5 years reference history                sample-value

  Item_Instance.version                                                                          \-                     NO             1                                 \-                                                                                         NO DEDICATED INDEX                    Inventory Owner / QL kho   INTERNAL                Active life + 5 years reference history                1

  Container.container_id                                                                         \-                     NO             GENERATED UUID                    \-                                                                                         PK/INDEX                              Inventory Owner / QL kho   INTERNAL                Active life + 5 years reference history                sample-value

  Container.container_code                                                                       100                    NO             NONE                              \-                                                                                         UNIQUE INDEX; QUERY INDEX             Inventory Owner / QL kho   INTERNAL                Active life + 5 years reference history                sample-value

  Container.container_type                                                                       \-                     NO             NONE                              CARTON/BOX/TRAY                                                                            NO DEDICATED INDEX                    Inventory Owner / QL kho   INTERNAL                Active life + 5 years reference history                sample-value

  Container.physical_code_id                                                                     \-                     NO             NONE                              FK/UQ active mapping                                                                       UNIQUE INDEX; FK INDEX; QUERY INDEX   Inventory Owner / QL kho   INTERNAL                Active life + 5 years reference history                sample-value

  Container.primary_sku_id                                                                       \-                     YES            NONE                              required for CARTON/BOX; null for mixed tray                                               FK INDEX; QUERY INDEX                 Inventory Owner / QL kho   INTERNAL                Active life + 5 years reference history                sample-value

  Container.parent_container_id                                                                  \-                     YES            NONE                              \-                                                                                         FK INDEX                              Inventory Owner / QL kho   INTERNAL                Active life + 5 years reference history                sample-value

  Container.status                                                                               \-                     NO             NONE                              type-specific state                                                                        QUERY INDEX                           Inventory Owner / QL kho   INTERNAL                Active life + 5 years reference history                ACTIVE

  Container.version                                                                              \-                     NO             1                                 \-                                                                                         NO DEDICATED INDEX                    Inventory Owner / QL kho   INTERNAL                Active life + 5 years reference history                1

  Container_Item.container_item_id                                                               \-                     NO             GENERATED UUID                    \-                                                                                         PK/INDEX                              Inventory Owner / QL kho   INTERNAL                Active life + 5 years reference history                sample-value

  Container_Item.container_id                                                                    \-                     NO             NONE                              \-                                                                                         FK INDEX                              Inventory Owner / QL kho   INTERNAL                Active life + 5 years reference history                sample-value

  Container_Item.item_id                                                                         \-                     YES            NONE                              \-                                                                                         FK INDEX                              Inventory Owner / QL kho   INTERNAL                Active life + 5 years reference history                sample-value

  Container_Item.sku_id                                                                          \-                     YES            NONE                              \-                                                                                         FK INDEX; QUERY INDEX                 Inventory Owner / QL kho   INTERNAL                Active life + 5 years reference history                sample-value

  Container_Item.quantity                                                                        18,3                   YES            NONE                              \>0 for quantity-only                                                                      NO DEDICATED INDEX                    Inventory Owner / QL kho   INTERNAL                Active life + 5 years reference history                10.000

  Container_Item.status                                                                          \-                     NO             NONE                              ACTIVE/REMOVED                                                                             QUERY INDEX                           Inventory Owner / QL kho   INTERNAL                Active life + 5 years reference history                ACTIVE

  Container_Item.UQ rule                                                                         \-                     NO             NONE                              no duplicate active item; one SKU per carton/box                                           NO DEDICATED INDEX                    Inventory Owner / QL kho   INTERNAL                Active life + 5 years reference history                sample-value

  Inventory_Balance.warehouse_id                                                                 \-                     NO             NONE                              \-                                                                                         FK INDEX; QUERY INDEX                 Inventory Owner / QL kho   INTERNAL                Active life + 5 years reference history                sample-value

  Inventory_Balance.sku_id                                                                       \-                     NO             NONE                              \-                                                                                         FK INDEX; QUERY INDEX                 Inventory Owner / QL kho   INTERNAL                Active life + 5 years reference history                sample-value

  Inventory_Balance.stock_status                                                                 \-                     NO             NONE                              AVAILABLE/NON_SELLABLE/etc.                                                                QUERY INDEX                           Inventory Owner / QL kho   INTERNAL                Active life + 5 years reference history                AVAILABLE

  Inventory_Balance.available_qty                                                                18,3                   NO             NONE                              \-                                                                                         NO DEDICATED INDEX                    Inventory Owner / QL kho   INTERNAL                Active life + 5 years reference history                10.000

  Inventory_Balance.version                                                                      \-                     NO             1                                 \-                                                                                         NO DEDICATED INDEX                    Inventory Owner / QL kho   INTERNAL                Active life + 5 years reference history                1

  Inventory_Balance.updated_at                                                                   \-                     NO             CURRENT_TIMESTAMP as applicable   \-                                                                                         QUERY INDEX                           Inventory Owner / QL kho   INTERNAL                Active life + 5 years reference history                sample-value

  Inventory_Balance.Composite UQ                                                                 \-                     NO             NONE                              warehouse_id + sku_id + stock_status                                                       NO DEDICATED INDEX                    Inventory Owner / QL kho   INTERNAL                Active life + 5 years reference history                sample-value

  Inventory_Movement.movement_id                                                                 \-                     NO             GENERATED UUID                    \-                                                                                         PK/INDEX                              Inventory Owner / QL kho   INTERNAL                \>=5 years; append-only where applicable               sample-value

  Inventory_Movement.movement_key                                                                128                    NO             NONE                              \-                                                                                         UNIQUE INDEX                          Inventory Owner / QL kho   INTERNAL                \>=5 years; append-only where applicable               sample-value

  Inventory_Movement.movement_type                                                               \-                     NO             NONE                              OPENING/RECEIPT_POSTED/ISSUE_POSTED/ADJUST_IN/ADJUST_OUT/RECEIPT_REVERSED/ISSUE_REVERSED   NO DEDICATED INDEX                    Inventory Owner / QL kho   INTERNAL                \>=5 years; append-only where applicable               sample-value

  Inventory_Movement.warehouse_id/sku_id                                                         \-                     NO             NONE                              \-                                                                                         FK INDEX; QUERY INDEX                 Inventory Owner / QL kho   INTERNAL                \>=5 years; append-only where applicable               sample-value

  Inventory_Movement.qty_delta                                                                   18,3                   NO             NONE                              \-                                                                                         NO DEDICATED INDEX                    Inventory Owner / QL kho   INTERNAL                \>=5 years; append-only where applicable               10.000

  Inventory_Movement.source_type/source_id/source_line_id                                        per component          NO             NONE                              \-                                                                                         FK INDEX                              Inventory Owner / QL kho   INTERNAL                \>=5 years; append-only where applicable               sample-value

  Inventory_Movement.original_movement_id                                                        \-                     YES            NONE                              for reversal/correction                                                                    FK INDEX                              Inventory Owner / QL kho   INTERNAL                \>=5 years; append-only where applicable               sample-value

  Inventory_Movement.correction_chain_id                                                         \-                     YES            NONE                              \-                                                                                         FK INDEX                              Inventory Owner / QL kho   INTERNAL                \>=5 years; append-only where applicable               sample-value

  Inventory_Movement.idempotency_key                                                             128                    NO             NONE                              \-                                                                                         UNIQUE INDEX                          Inventory Owner / QL kho   INTERNAL                \>=5 years; append-only where applicable               sample-value

  Inventory_Movement.posted_at                                                                   \-                     NO             CURRENT_TIMESTAMP as applicable   \-                                                                                         QUERY INDEX                           Inventory Owner / QL kho   INTERNAL                \>=5 years; append-only where applicable               sample-value

  Inventory_Movement.created_by/request_id                                                       per component          NO             NONE                              \-                                                                                         FK INDEX                              Inventory Owner / QL kho   INTERNAL                \>=5 years; append-only where applicable               req-20260723-0001

  Inbound_Document / Line.inbound_id / line_id                                                   \-                     NO             GENERATED UUID                    \-                                                                                         PK/INDEX                              Inventory Owner / QL kho   INTERNAL                \>=5 years after closure                               sample-value

  Inbound_Document / Line.document_no                                                            50                     NO             NONE                              \-                                                                                         UNIQUE INDEX; QUERY INDEX             Inventory Owner / QL kho   INTERNAL                \>=5 years after closure                               IN-20260723-0001

  Inbound_Document / Line.status / line_status                                                   \-                     NO             NONE                              partial states                                                                             QUERY INDEX                           Inventory Owner / QL kho   INTERNAL                \>=5 years after closure                               ACTIVE

  Inbound_Document / Line.source_reference                                                       200                    YES            NONE                              supplier/import ref                                                                        NO DEDICATED INDEX                    Inventory Owner / QL kho   INTERNAL                \>=5 years after closure                               sample-value

  Inbound_Document / Line.sku_id                                                                 \-                     NO             NONE                              \-                                                                                         FK INDEX; QUERY INDEX                 Inventory Owner / QL kho   INTERNAL                \>=5 years after closure                               sample-value

  Inbound_Document / Line.expected_qty                                                           18,3                   NO             NONE                              \>=0                                                                                       NO DEDICATED INDEX                    Inventory Owner / QL kho   INTERNAL                \>=5 years after closure                               10.000

  Inbound_Document / Line.posted_qty                                                             18,3                   NO             0                                 \>=0                                                                                       NO DEDICATED INDEX                    Inventory Owner / QL kho   INTERNAL                \>=5 years after closure                               10.000

  Inbound_Document / Line.remaining_qty                                                          18,3                   NO             0                                 derived/validated                                                                          NO DEDICATED INDEX                    Inventory Owner / QL kho   INTERNAL                \>=5 years after closure                               10.000

  Inbound_Document / Line.cancelled_remainder_qty                                                18,3                   NO             0                                 \-                                                                                         NO DEDICATED INDEX                    Inventory Owner / QL kho   INTERNAL                \>=5 years after closure                               10.000

  Inbound_Document / Line.version                                                                \-                     NO             1                                 \-                                                                                         NO DEDICATED INDEX                    Inventory Owner / QL kho   INTERNAL                \>=5 years after closure                               1

  Outbound_Document / Line.outbound_id / line_id                                                 \-                     NO             GENERATED UUID                    \-                                                                                         PK/INDEX                              Inventory Owner / QL kho   INTERNAL                \>=5 years after closure                               sample-value

  Outbound_Document / Line.document_no                                                           50                     NO             NONE                              \-                                                                                         UNIQUE INDEX; QUERY INDEX             Inventory Owner / QL kho   INTERNAL                \>=5 years after closure                               IN-20260723-0001

  Outbound_Document / Line.agency_id                                                             \-                     YES            NONE                              \-                                                                                         FK INDEX                              Inventory Owner / QL kho   INTERNAL                \>=5 years after closure                               sample-value

  Outbound_Document / Line.receiver_name                                                         200                    YES            NONE                              \-                                                                                         NO DEDICATED INDEX                    Inventory Owner / QL kho   PII-RESTRICTED          \>=5 years after closure                               Máy khoan mẫu

  Outbound_Document / Line.status / line_status                                                  \-                     NO             NONE                              partial states                                                                             QUERY INDEX                           Inventory Owner / QL kho   INTERNAL                \>=5 years after closure                               ACTIVE

  Outbound_Document / Line.sku_id                                                                \-                     NO             NONE                              \-                                                                                         FK INDEX; QUERY INDEX                 Inventory Owner / QL kho   INTERNAL                \>=5 years after closure                               sample-value

  Outbound_Document / Line.planned_qty/issued_qty/remaining_qty                                  18,3                   NO             0                                 \-                                                                                         NO DEDICATED INDEX                    Inventory Owner / QL kho   INTERNAL                \>=5 years after closure                               10.000

  Outbound_Document / Line.cancelled_remainder_qty                                               18,3                   NO             0                                 \-                                                                                         NO DEDICATED INDEX                    Inventory Owner / QL kho   INTERNAL                \>=5 years after closure                               10.000

  Outbound_Document / Line.version                                                               \-                     NO             1                                 \-                                                                                         NO DEDICATED INDEX                    Inventory Owner / QL kho   INTERNAL                \>=5 years after closure                               1

  Posting_Event.posting_event_id                                                                 \-                     NO             GENERATED UUID                    \-                                                                                         PK/INDEX                              Inventory Owner / QL kho   INTERNAL                \>=5 years; append-only where applicable               sample-value

  Posting_Event.document_type/document_id                                                        per component          NO             NONE                              \-                                                                                         FK INDEX                              Inventory Owner / QL kho   INTERNAL                \>=5 years; append-only where applicable               sample-value

  Posting_Event.idempotency_key                                                                  128                    NO             NONE                              \-                                                                                         UNIQUE INDEX                          Inventory Owner / QL kho   INTERNAL                \>=5 years; append-only where applicable               sample-value

  Posting_Event.payload_hash                                                                     128                    NO             NONE                              \-                                                                                         NO DEDICATED INDEX                    Inventory Owner / QL kho   INTERNAL                \>=5 years; append-only where applicable               sample-value

  Posting_Event.result_reference                                                                 \-                     NO             NONE                              \-                                                                                         NO DEDICATED INDEX                    Inventory Owner / QL kho   INTERNAL                \>=5 years; append-only where applicable               sample-value

  Posting_Event.posted_at/posted_by/request_id                                                   per component          NO             NONE                              \-                                                                                         FK INDEX; QUERY INDEX                 Inventory Owner / QL kho   INTERNAL                \>=5 years; append-only where applicable               req-20260723-0001

  Stocktake_Session.session_id                                                                   \-                     NO             GENERATED UUID                    \-                                                                                         PK/INDEX                              Inventory Owner / QL kho   INTERNAL                \>=5 years after closure                               sample-value

  Stocktake_Session.scope_type/scope_ref                                                         per component          NO             NONE                              WHOLE/SKU/GROUP                                                                            NO DEDICATED INDEX                    Inventory Owner / QL kho   INTERNAL                \>=5 years after closure                               sample-value

  Stocktake_Session.stocktake_started_at                                                         \-                     NO             CURRENT_TIMESTAMP as applicable   \-                                                                                         NO DEDICATED INDEX                    Inventory Owner / QL kho   INTERNAL                \>=5 years after closure                               sample-value

  Stocktake_Session.status                                                                       \-                     NO             NONE                              DRAFT/COUNTING/REVIEWING/APPLIED/CANCELLED                                                 QUERY INDEX                           Inventory Owner / QL kho   INTERNAL                \>=5 years after closure                               DRAFT

  Stocktake_Session.session_version                                                              \-                     NO             1                                 \-                                                                                         NO DEDICATED INDEX                    Inventory Owner / QL kho   INTERNAL                \>=5 years after closure                               1

  Stocktake_Session.created_by/applied_by                                                        \-                     NO             NONE                              \-                                                                                         NO DEDICATED INDEX                    Inventory Owner / QL kho   INTERNAL                \>=5 years after closure                               sample-value

  Stocktake_Snapshot / Count / Variance.snapshot_id/count_id/variance_id                         \-                     NO             GENERATED UUID                    \-                                                                                         PK/INDEX                              Inventory Owner / QL kho   INTERNAL                \>=5 years after closure                               sample-value

  Stocktake_Snapshot / Count / Variance.session_id                                               \-                     NO             NONE                              \-                                                                                         FK INDEX                              Inventory Owner / QL kho   INTERNAL                \>=5 years after closure                               sample-value

  Stocktake_Snapshot / Count / Variance.sku_id/item_id/container_id                              \-                     YES            NONE                              \-                                                                                         FK INDEX; QUERY INDEX                 Inventory Owner / QL kho   INTERNAL                \>=5 years after closure                               sample-value

  Stocktake_Snapshot / Count / Variance.snapshot_qty/actual_qty/expected_at_count/variance_qty   18,3                   NO             NONE                              \-                                                                                         NO DEDICATED INDEX                    Inventory Owner / QL kho   INTERNAL                \>=5 years after closure                               10.000

  Stocktake_Snapshot / Count / Variance.count_cutoff_at                                          \-                     YES            NONE                              \-                                                                                         NO DEDICATED INDEX                    Inventory Owner / QL kho   INTERNAL                \>=5 years after closure                               sample-value

  Stocktake_Snapshot / Count / Variance.line_status                                              \-                     NO             NONE                              PENDING/APPROVED/WAIVED/APPLIED                                                            QUERY INDEX                           Inventory Owner / QL kho   INTERNAL                \>=5 years after closure                               PENDING

  Stocktake_Snapshot / Count / Variance.waive_reason/acknowledged_movement_ids                   per component          YES            NONE                              \-                                                                                         NO DEDICATED INDEX                    Inventory Owner / QL kho   INTERNAL                \>=5 years after closure                               sample-value

  Reversal_Document / Line.reversal_id / reversal_line_id                                        \-                     NO             GENERATED UUID                    \-                                                                                         PK/INDEX                              Inventory Owner / QL kho   INTERNAL                \>=5 years; append-only where applicable               sample-value

  Reversal_Document / Line.reversal_type                                                         \-                     NO             NONE                              RECEIPT/ISSUE/CORRECTION                                                                   NO DEDICATED INDEX                    Inventory Owner / QL kho   INTERNAL                \>=5 years; append-only where applicable               sample-value

  Reversal_Document / Line.original_document_id                                                  \-                     NO             NONE                              \-                                                                                         FK INDEX                              Inventory Owner / QL kho   INTERNAL                \>=5 years; append-only where applicable               sample-value

  Reversal_Document / Line.original_movement_id                                                  \-                     NO             NONE                              \-                                                                                         FK INDEX                              Inventory Owner / QL kho   INTERNAL                \>=5 years; append-only where applicable               sample-value

  Reversal_Document / Line.qty_to_reverse                                                        18,3                   NO             NONE                              \<= reversible_qty                                                                         NO DEDICATED INDEX                    Inventory Owner / QL kho   INTERNAL                \>=5 years; append-only where applicable               10.000

  Reversal_Document / Line.return_condition                                                      \-                     YES            NONE                              SELLABLE/DEFECTIVE/NON_SELLABLE                                                            NO DEDICATED INDEX                    Inventory Owner / QL kho   INTERNAL                \>=5 years; append-only where applicable               sample-value

  Reversal_Document / Line.reason_code/reason_text                                               per component          NO             NONE                              \-                                                                                         QUERY INDEX                           Inventory Owner / QL kho   RESTRICTED              \>=5 years; append-only where applicable               sample-value

  Reversal_Document / Line.status                                                                \-                     NO             NONE                              DRAFT/CONFIRMED/POSTED/CANCELLED                                                           QUERY INDEX                           Inventory Owner / QL kho   INTERNAL                \>=5 years; append-only where applicable               DRAFT

  Reversal_Document / Line.correction_chain_id                                                   \-                     NO             NONE                              \-                                                                                         FK INDEX                              Inventory Owner / QL kho   INTERNAL                \>=5 years; append-only where applicable               sample-value

  Reversal_Document / Line.idempotency_key/payload_hash                                          \-                     NO             NONE                              \-                                                                                         NO DEDICATED INDEX                    Inventory Owner / QL kho   INTERNAL                \>=5 years; append-only where applicable               sample-value

  Reversal_Document / Line.version                                                               \-                     NO             1                                 \-                                                                                         NO DEDICATED INDEX                    Inventory Owner / QL kho   INTERNAL                \>=5 years; append-only where applicable               1

  Warranty_Case.case_id                                                                          \-                     NO             GENERATED UUID                    \-                                                                                         PK/INDEX                              Warranty Owner             INTERNAL                5 years after case closure, then anonymize/purge PII   sample-value

  Warranty_Case.warranty_case_code                                                               50                     NO             NONE                              \-                                                                                         UNIQUE INDEX; QUERY INDEX             Warranty Owner             INTERNAL                5 years after case closure, then anonymize/purge PII   sample-value

  Warranty_Case.item_id/item_code                                                                per component          YES            NONE                              \-                                                                                         QUERY INDEX                           Warranty Owner             INTERNAL                5 years after case closure, then anonymize/purge PII   sample-value

  Warranty_Case.temporary_item_code                                                              100                    NO             NONE                              \-                                                                                         UNIQUE INDEX; QUERY INDEX             Warranty Owner             INTERNAL                5 years after case closure, then anonymize/purge PII   sample-value

  Warranty_Case.identification_status                                                            \-                     NO             NONE                              IDENTIFIED/TEMP_ONLY                                                                       QUERY INDEX                           Warranty Owner             INTERNAL                5 years after case closure, then anonymize/purge PII   IDENTIFIED

  Warranty_Case.customer_name/customer_phone                                                     \-                     NO             NONE                              \-                                                                                         NO DEDICATED INDEX                    Warranty Owner             PII-RESTRICTED          5 years after case closure, then anonymize/purge PII   090\*\*\*9922

  Warranty_Case.defect_id/reported_defect/confirmed_defect                                       per component          NO             NONE                              \-                                                                                         NO DEDICATED INDEX                    Warranty Owner             RESTRICTED              5 years after case closure, then anonymize/purge PII   sample-value

  Warranty_Case.missing_code_reason/manual_product_description                                   \-                     NO             NONE                              \-                                                                                         QUERY INDEX                           Warranty Owner             RESTRICTED              5 years after case closure, then anonymize/purge PII   sample-value

  Warranty_Case.status                                                                           \-                     NO             NONE                              warranty states                                                                            QUERY INDEX                           Warranty Owner             INTERNAL                5 years after case closure, then anonymize/purge PII   ACTIVE

  Warranty_Case.received_date/returned_date                                                      \-                     NO             CURRENT_TIMESTAMP as applicable   \-                                                                                         NO DEDICATED INDEX                    Warranty Owner             INTERNAL                5 years after case closure, then anonymize/purge PII   sample-value

  Warranty_Case.version                                                                          \-                     NO             1                                 \-                                                                                         NO DEDICATED INDEX                    Warranty Owner             INTERNAL                5 years after case closure, then anonymize/purge PII   1

  PV_Product_Profile.profile_id                                                                  \-                     NO             GENERATED UUID                    \-                                                                                         PK/INDEX                              PV Product Owner           INTERNAL                Active life + 1 year history                           sample-value

  PV_Product_Profile.sku_id                                                                      \-                     NO             NONE                              \-                                                                                         UNIQUE INDEX; FK INDEX; QUERY INDEX   PV Product Owner           INTERNAL                Active life + 1 year history                           sample-value

  PV_Product_Profile.display_name/description/specification                                      per component          NO             NONE                              \-                                                                                         NO DEDICATED INDEX                    PV Product Owner           PII-RESTRICTED          Active life + 1 year history                           Máy khoan mẫu

  PV_Product_Profile.published                                                                   \-                     NO             false                             \-                                                                                         NO DEDICATED INDEX                    PV Product Owner           INTERNAL                Active life + 1 year history                           sample-value

  PV_Product_Profile.featured/sort_order                                                         per component          NO             NONE                              \-                                                                                         NO DEDICATED INDEX                    PV Product Owner           INTERNAL                Active life + 1 year history                           sample-value

  PV_Product_Profile.last_inventory_sync_at                                                      \-                     YES            NONE                              \-                                                                                         NO DEDICATED INDEX                    PV Product Owner           INTERNAL                Active life + 1 year history                           sample-value

  PV_Product_Profile.version                                                                     \-                     NO             1                                 \-                                                                                         NO DEDICATED INDEX                    PV Product Owner           INTERNAL                Active life + 1 year history                           1

  PV_Industry / Tag / Mapping.industry_id/tag_id                                                 \-                     NO             GENERATED UUID                    \-                                                                                         PK/INDEX                              PV Product Owner           INTERNAL                Active life + 1 year history                           sample-value

  PV_Industry / Tag / Mapping.code                                                               80                     NO             NONE                              \-                                                                                         UNIQUE INDEX; QUERY INDEX             PV Product Owner           PUBLIC WHEN PUBLISHED   Active life + 1 year history                           sample-value

  PV_Industry / Tag / Mapping.name                                                               200                    NO             NONE                              \-                                                                                         NO DEDICATED INDEX                    PV Product Owner           PUBLIC WHEN PUBLISHED   Active life + 1 year history                           Máy khoan mẫu

  PV_Industry / Tag / Mapping.status                                                             \-                     NO             ACTIVE                            ACTIVE/INACTIVE                                                                            QUERY INDEX                           PV Product Owner           INTERNAL                Active life + 1 year history                           ACTIVE

  PV_Industry / Tag / Mapping.profile_id + industry_id/tag_id                                    \-                     NO             NONE                              \-                                                                                         UNIQUE INDEX; FK INDEX                PV Product Owner           INTERNAL                Active life + 1 year history                           sample-value

  PV_Industry / Tag / Mapping.priority                                                           \-                     YES            NONE                              \-                                                                                         NO DEDICATED INDEX                    PV Product Owner           INTERNAL                Active life + 1 year history                           1

  PV_Recommendation_Rule.rule_id                                                                 \-                     NO             GENERATED UUID                    \-                                                                                         PK/INDEX                              PV Product Owner           INTERNAL                Active life + 1 year history                           sample-value

  PV_Recommendation_Rule.industry_id                                                             \-                     NO             NONE                              \-                                                                                         FK INDEX                              PV Product Owner           INTERNAL                Active life + 1 year history                           sample-value

  PV_Recommendation_Rule.category_id/tag_id/sku_id                                               \-                     YES            NONE                              selector/priority                                                                          FK INDEX; QUERY INDEX                 PV Product Owner           INTERNAL                Active life + 1 year history                           sample-value

  PV_Recommendation_Rule.priority                                                                \-                     NO             NONE                              \-                                                                                         UNIQUE INDEX                          PV Product Owner           INTERNAL                Active life + 1 year history                           1

  PV_Recommendation_Rule.status                                                                  \-                     NO             ACTIVE                            ACTIVE/INACTIVE                                                                            QUERY INDEX                           PV Product Owner           INTERNAL                Active life + 1 year history                           ACTIVE

  Import_Batch.import_batch_id                                                                   \-                     NO             GENERATED UUID                    \-                                                                                         PK/INDEX                              Data Migration Owner       INTERNAL                \>=5 years after closure                               sample-value

  Import_Batch.import_batch_code                                                                 80                     NO             NONE                              \-                                                                                         UNIQUE INDEX; QUERY INDEX             Data Migration Owner       INTERNAL                \>=5 years after closure                               sample-value

  Import_Batch.source_file/checksum                                                              \-                     NO             NONE                              \-                                                                                         NO DEDICATED INDEX                    Data Migration Owner       RESTRICTED              \>=5 years after closure                               sha256:abc123...

  Import_Batch.status                                                                            \-                     NO             NONE                              DRAFT/DRY_RUN_VALIDATED/COMMITTED/FAILED/ROLLED_BACK                                       QUERY INDEX                           Data Migration Owner       INTERNAL                \>=5 years after closure                               DRAFT

  Import_Batch.valid/invalid/duplicate_count                                                     \-                     NO             0                                 \-                                                                                         NO DEDICATED INDEX                    Data Migration Owner       INTERNAL                \>=5 years after closure                               1

  Import_Batch.reconciliation_status                                                             \-                     NO             NONE                              PENDING/PASS/FAIL                                                                          QUERY INDEX                           Data Migration Owner       INTERNAL                \>=5 years after closure                               PENDING

  Import_Batch.committed_at/rollback_reference                                                   per component          YES            NONE                              \-                                                                                         NO DEDICATED INDEX                    Data Migration Owner       INTERNAL                \>=5 years after closure                               sample-value

  Audit_Log / Reconciliation_Incident.audit_id/incident_id                                       \-                     NO             GENERATED UUID                    \-                                                                                         PK/INDEX                              Tech Lead / Security       INTERNAL                \>=5 years; append-only where applicable               sample-value

  Audit_Log / Reconciliation_Incident.actor_id/action/object_type/object_id                      per component          NO             NONE                              \-                                                                                         FK INDEX                              Tech Lead / Security       INTERNAL                \>=5 years; append-only where applicable               sample-value

  Audit_Log / Reconciliation_Incident.reason/request_id                                          per component          CONDITIONAL    NONE                              \-                                                                                         FK INDEX                              Tech Lead / Security       INTERNAL                \>=5 years; append-only where applicable               req-20260723-0001

  Audit_Log / Reconciliation_Incident.before_data/after_data                                     \-                     YES            NONE                              \-                                                                                         NO DEDICATED INDEX                    Tech Lead / Security       RESTRICTED              \>=5 years; append-only where applicable               sample-value

  Audit_Log / Reconciliation_Incident.divergence_type/status/resolution_ref                      per component          YES            NONE                              \-                                                                                         QUERY INDEX                           Tech Lead / Security       INTERNAL                \>=5 years; append-only where applicable               ACTIVE

  Audit_Log / Reconciliation_Incident.created_at/resolved_at                                     \-                     NO             CURRENT_TIMESTAMP as applicable   \-                                                                                         QUERY INDEX                           Tech Lead / Security       INTERNAL                \>=5 years; append-only where applicable               sample-value
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 13.5. Machine-readable OpenAPI Release Contract

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Control**               **Status/Reference**    **Contract**
  ------------------------- ----------------------- ------------------------------------------------------------------------------------------------------------------------
  Artifact                  OpenAPI BA Draft.yaml   OpenAPI 3.0.3; machine-readable; accompanies this DOCX.

  Request/response schema   Included                Core inbound/outbound/stocktake/reversal/warranty/PV/report schemas with required/max/enum/examples.

  Error body                Included                ErrorEnvelope: code, message, request_id, timestamp, field_errors, details.

  Headers                   Included                Authorization, Idempotency-Key, If-Match, ETag, X-Request-Id.

  Pagination                Included                page, page_size default 50/max 200; total_items/total_pages.

  Security                  Included                bearerAuth + x-permission by operation; 403/404 scope convention.

  Examples                  Included                Scan and partial receipt examples; schema examples for errors/stock actions.

  Contract tests            Designed                x-contract-tests IDs for positive, negative, retry, idempotency conflict, concurrency, rollback; execution at WWHN-33.

  Approval                  PENDING                 Tech Lead approval at WWHN-31; QA design approval at WWHN-33 before SRS Done or explicit waiver.
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 13.6. Screen Map and Approved Field Specification Candidate

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Screen**      **Purpose**                                       **Loading**                                                        **Empty**                                                    **Error/Retry**                                                    **Success**                                                  **Confirm**                                                                                                                   **Responsive**                                                                               **Scan-focus**
  --------------- -------------------- ---------------------------- -------------------------------------------- --------------------- ------------------------------------- ---------------------- ------------------------------------------------------------------ ------------------------------------------------------------ --------------------------------------------------------------------------------------- ------------------------------------- ------------------------------------------------------ ------------------------------------- -------------------------------------------------------------------------------------
  **Screen**                           **Field/Control**                                                         **UI type**                                                 **Required**                                                                              **Validation**                                                                                                                                       **Permission/Editability**                                                                   **API/Data mapping**                  

  SCR-IAM-01      User/Role                                         Skeleton/spinner; disable duplicate action                         Explain no data + clear next action                          Inline + request_id; retry same idempotency key where applicable   Toast/summary + updated state/version                        Required for irreversible/post/close/publish/deactivate; show stock effect and reason                                         360/768/1280+, no horizontal loss of critical action                                         N/A

  SCR-MST-01      SKU/Product Master                                Skeleton/spinner; disable duplicate action                         Explain no data + clear next action                          Inline + request_id; retry same idempotency key where applicable   Toast/summary + updated state/version                        Only destructive actions                                                                                                      360/768/1280+, no horizontal loss of critical action                                         N/A

  SCR-AGY-01      Agency                                            Skeleton/spinner; disable duplicate action                         Explain no data + clear next action                          Inline + request_id; retry same idempotency key where applicable   Toast/summary + updated state/version                        Only destructive actions                                                                                                      360/768/1280+, no horizontal loss of critical action                                         N/A

  SCR-DEF-01      Defect                                            Skeleton/spinner; disable duplicate action                         Explain no data + clear next action                          Inline + request_id; retry same idempotency key where applicable   Toast/summary + updated state/version                        Only destructive actions                                                                                                      360/768/1280+, no horizontal loss of critical action                                         N/A

  SCR-IN-01       Inbound                                           Skeleton/spinner; disable duplicate action                         Explain no data + clear next action                          Inline + request_id; retry same idempotency key where applicable   Toast/summary + updated state/version                        Required for irreversible/post/close/publish/deactivate; show stock effect and reason                                         360/768/1280+, no horizontal loss of critical action                                         Auto-focus scan input; Enter/reader event submits; retain focus after success/error

  SCR-OUT-01      Outbound                                          Skeleton/spinner; disable duplicate action                         Explain no data + clear next action                          Inline + request_id; retry same idempotency key where applicable   Toast/summary + updated state/version                        Required for irreversible/post/close/publish/deactivate; show stock effect and reason                                         360/768/1280+, no horizontal loss of critical action                                         Auto-focus scan input; Enter/reader event submits; retain focus after success/error

  SCR-REV-01      Reversal                                          Skeleton/spinner; disable duplicate action                         Explain no data + clear next action                          Inline + request_id; retry same idempotency key where applicable   Toast/summary + updated state/version                        Required for irreversible/post/close/publish/deactivate; show stock effect and reason                                         360/768/1280+, no horizontal loss of critical action                                         N/A

  SCR-INV-01      Inventory                                         Skeleton/spinner; disable duplicate action                         Explain no data + clear next action                          Inline + request_id; retry same idempotency key where applicable   Toast/summary + updated state/version                        Only destructive actions                                                                                                      360/768/1280+, no horizontal loss of critical action                                         N/A

  SCR-TRACE-01    Trace                                             Skeleton/spinner; disable duplicate action                         Explain no data + clear next action                          Inline + request_id; retry same idempotency key where applicable   Toast/summary + updated state/version                        Only destructive actions                                                                                                      360/768/1280+, no horizontal loss of critical action                                         Auto-focus scan input; Enter/reader event submits; retain focus after success/error

  SCR-STK-01      Stocktake                                         Skeleton/spinner; disable duplicate action                         Explain no data + clear next action                          Inline + request_id; retry same idempotency key where applicable   Toast/summary + updated state/version                        Required for irreversible/post/close/publish/deactivate; show stock effect and reason                                         360/768/1280+, no horizontal loss of critical action                                         Auto-focus scan input; Enter/reader event submits; retain focus after success/error

  SCR-WAR-01      Warranty                                          Skeleton/spinner; disable duplicate action                         Explain no data + clear next action                          Inline + request_id; retry same idempotency key where applicable   Toast/summary + updated state/version                        Only destructive actions                                                                                                      360/768/1280+, no horizontal loss of critical action                                         Auto-focus scan input; Enter/reader event submits; retain focus after success/error

  SCR-RPT-01      Reports                                           Skeleton/spinner; disable duplicate action                         Explain no data + clear next action                          Inline + request_id; retry same idempotency key where applicable   Toast/summary + updated state/version                        Only destructive actions                                                                                                      360/768/1280+, no horizontal loss of critical action                                         N/A

  SCR-PV-01       PV List                                           Skeleton/spinner; disable duplicate action                         Explain no data + clear next action                          Inline + request_id; retry same idempotency key where applicable   Toast/summary + updated state/version                        Only destructive actions                                                                                                      360/768/1280+, no horizontal loss of critical action                                         N/A

  SCR-PV-02       PV Detail                                         Skeleton/spinner; disable duplicate action                         Explain no data + clear next action                          Inline + request_id; retry same idempotency key where applicable   Toast/summary + updated state/version                        Only destructive actions                                                                                                      360/768/1280+, no horizontal loss of critical action                                         N/A

  SCR-PV-ADM-01   PV Admin                                          Skeleton/spinner; disable duplicate action                         Explain no data + clear next action                          Inline + request_id; retry same idempotency key where applicable   Toast/summary + updated state/version                        Required for irreversible/post/close/publish/deactivate; show stock effect and reason                                         360/768/1280+, no horizontal loss of critical action                                         N/A

  SCR-DEMO-01     Demo Scanner                                      Skeleton/spinner; disable duplicate action                         Explain no data + clear next action                          Inline + request_id; retry same idempotency key where applicable   Toast/summary + updated state/version                        Only destructive actions                                                                                                      360/768/1280+, no horizontal loss of critical action                                         Auto-focus scan input; Enter/reader event submits; retain focus after success/error

  SCR-IN-01                            document_no                                                               Text/read-only                                              Y after create                                                                            Generated unique                                                                                                                                     All actors view                                                                              Inbound.document_no                   

  SCR-IN-01                            source_reference                                                          Text                                                        N                                                                                         max 200                                                                                                                                              Edit DRAFT/SCANNING                                                                          Inbound.source_reference              

  SCR-IN-01                            scan_input                                                                Scanner/Text                                                Y per scan                                                                                1..150 chars; trim; no auto stock effect                                                                                                             inbound.create users                                                                         /scan-batches/classify.codes\[\]      

  SCR-IN-01                            post_subset                                                               Line selector + qty                                         Y for post                                                                                \>0 and \<=remaining; ITEM requires eligible chip/fallback                                                                                           inbound.post only                                                                            PostLinesRequest.lines                

  SCR-IN-01                            close_reason                                                              Code + textarea                                             Y when close                                                                              reason text 5..500                                                                                                                                   inbound.close_remainder                                                                      CloseRemainderRequest                 

  SCR-OUT-01                           agency_id/receiver_name                                                   Select/Text                                                 Conditional                                                                               Active agency or receiver required                                                                                                                   outbound.create                                                                              OutboundCreateRequest                 

  SCR-OUT-01                           available_qty                                                             Number/read-only                                            Y                                                                                         Refresh at commit; stale warning                                                                                                                     Authorized view                                                                              Inventory_Balance.available_qty       

  SCR-OUT-01                           issue_subset                                                              Scan/qty                                                    Y for post                                                                                \<=remaining and \<=available                                                                                                                        outbound.post                                                                                PostLinesRequest                      

  SCR-STK-01                           scope_type/scope_ref                                                      Select                                                      Y/Conditional                                                                             WHOLE/SKU/GROUP                                                                                                                                      stocktake.count                                                                              StocktakeCreateRequest                

  SCR-STK-01                           actual_qty                                                                Decimal/scan count                                          Y at confirm                                                                              \>=0; duplicate serial ignored                                                                                                                       Counter                                                                                      Stocktake_Count.actual_qty            

  SCR-STK-01                           movement_warning_ack                                                      Checkbox/list                                               Y when warning                                                                            All movement IDs reviewed                                                                                                                            QL kho                                                                                       acknowledged_movement_ids             

  SCR-STK-01                           approve/waive                                                             Action + reason                                             Y per variance                                                                            Waive reason required                                                                                                                                QL kho                                                                                       Variance.line_status                  

  SCR-REV-01                           original_movement                                                         Search/read-only                                            Y                                                                                         POSTED and reversible                                                                                                                                inventory.reversal.post                                                                      original_movement_id                  

  SCR-REV-01                           qty_to_reverse                                                            Decimal                                                     Y                                                                                         \>0 \<= reversible_qty                                                                                                                               inventory.reversal.post                                                                      qty_to_reverse                        

  SCR-REV-01                           return_condition                                                          Select                                                      Conditional                                                                               Required for Reverse Issue physical return                                                                                                           inventory.reversal.post                                                                      SELLABLE/DEFECTIVE/NON_SELLABLE       

  SCR-WAR-01                           identification_status                                                     Radio                                                       Y                                                                                         IDENTIFIED/TEMP_ONLY                                                                                                                                 warranty.manage                                                                              Warranty.identification_status        

  SCR-WAR-01                           customer_name                                                             Text                                                        Y                                                                                         max 200; mask outside PII scope                                                                                                                      warranty.pii.view for full                                                                   Warranty.customer_name                

  SCR-WAR-01                           customer_phone                                                            Tel                                                         Y                                                                                         normalized; mask default                                                                                                                             warranty.pii.view for full                                                                   Warranty.customer_phone               

  SCR-WAR-01                           images                                                                    Upload                                                      N                                                                                         jpg/png/webp; \<=10 files; \<=10MB/file; malware scan                                                                                                warranty.manage                                                                              attachment IDs only                   

  SCR-WAR-01                           manual_product_description                                                Textarea                                                    Y if TEMP_ONLY                                                                            max 2000; no raw PII in logs                                                                                                                         warranty.manage                                                                              Warranty.manual_product_description   

  SCR-RPT-01                           date_from/date_to                                                         Date                                                        Y for period reports                                                                      from\<=to; timezone Asia/Ho_Chi_Minh                                                                                                                 report.view                                                                                  query filters                         

  SCR-RPT-01                           export                                                                    Action                                                      N                                                                                         same filters/scope; PII default excluded                                                                                                             report.export; PII extra permission                                                          /reports/export                       

  SCR-PV-ADM-01                        published                                                                 Toggle                                                      Y                                                                                         Only active SKU/profile complete                                                                                                                     pv.publish                                                                                   PV_Profile.published                  

  SCR-PV-01                            available_qty/data_as_of                                                  Read-only                                                   Y                                                                                         cache staleness \<=60s                                                                                                                               public/read-only                                                                             PV read model                         
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 13.7. Quantified NFR Baseline

  ----------------------------------------------------------------------------------------------------------------------------------
  **NFR dimension**      **Scope**                           **Measured target**
  ---------------------- ----------------------------------- -----------------------------------------------------------------------
  Scan batch             1..500 codes/request                P95 \<=5s; duplicate/unknown classified; no stock effect

  Single scan feedback   One scan event                      UI feedback P95 \<=500ms; API classify P95 \<=1.5s

  Read/list API          page_size\<=50                      P95 \<=2s; P99 \<=4s

  Stock action API       Post/Apply/Reversal                 P95 \<=3s excluding hardware read time

  Concurrency            20 stock-changing + 50 read users   No negative stock, duplicate movement, lost update

  Availability           Monthly                             \>=99.5%, excluding announced planned maintenance

  Backup/RPO/RTO         Core data/service                   Daily backup; RPO\<=24h; RTO\<=8h; restore test quarterly/pre-go-live

  Import                 10,000 rows/file minimum            Dry-run result/error file; critical errors=0 before commit

  Retention              Ledger/audit/transaction            \>=5 years; no hard-delete authoritative ledger

  Privacy retention      Warranty PII                        5 years after case closure then anonymize/purge unless legal hold

  Device/browser         360/768/1280+                       Chrome/Edge/Chrome Android/Safari iOS latest 2 versions

  PV cache               Inventory read model                max staleness \<=60s; expose data_as_of/last_inventory_sync_at
  ----------------------------------------------------------------------------------------------------------------------------------

### 13.8. Privacy Contract - Warranty Name, Phone, Image and Description

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Data**                           **Masking/access scope**                                               **UI/API scope**                                              **Export**                                           **Logging**                                          **Retention/purge**
  ---------------------------------- ---------------------------------------------------------------------- ------------------------------------------------------------- ---------------------------------------------------- ---------------------------------------------------- -------------------------------------------------------------
  Customer name                      Full: warranty.manage + warranty.pii.view; otherwise N\*\*\* H\*\*\*   Not in PV; report masked by default                           PII export requires warranty.pii.export + reason     Do not write full value to application/access logs   5 years after case close; anonymize/purge unless legal hold

  Customer phone                     Full only PII permission; otherwise \*\*\*\*\*\*9922                   Search may use normalized exact match for authorized staff    PII export separate permission; audit every export   Never log raw phone; use case_id/request_id          Same as customer name

  Warranty images                    Authorized warranty staff/QL/Admin only; signed/private URL            No public bucket; malware/content-type check                  Export/download audited; no bulk export by default   Log attachment_id only, not binary/URL token         Purge with case PII unless legal hold

  Machine description/defect notes   Warranty actors; reports aggregate unless detail permission            Free text may contain incidental PII; show warning/minimize   Detailed export restricted                           Sanitize; log ID/change metadata, not full text      5 years after closure; anonymize personal content
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

-   Purge job phải idempotent, có dry-run/report, retry, legal-hold exclusion và audit số record anonymized/purged; không xóa Inventory_Movement hoặc business evidence cần giữ.

-   API 404 được phép dùng để mask resource ngoài data scope; 403 dùng cho action đã nhận diện nhưng thiếu permission.

### 13.9. Physical Code / Chip Lifecycle

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **State**         **Action**            **Rule**                                                                             **Operational effect**
  ----------------- --------------------- ------------------------------------------------------------------------------------ --------------------------------------------------------------------------------
  UNASSIGNED        Generate/Import       Code/UID đã validate nhưng chưa gắn target                                           Không dùng post tồn

  ACTIVE            Assign                Một active code/UID map đúng một Item/Container; ITEM target carrier chip            Được scan theo permission/state

  DAMAGED           Mark damaged          Không đọc tin cậy; giữ lịch sử mapping                                               Normal post bị chặn; có controlled fallback

  LOST              Mark lost             Không còn vật lý; actor/reason/time bắt buộc                                         Code cũ không được tái gán

  REPLACED          Replace               Code mới ACTIVE; code cũ REPLACED; replacement_of link                               Mọi trace hiển thị chuỗi cũ→mới

  INACTIVE          Deactivate            Không dùng transaction mới; history retained                                         Không hard-delete

  FALLBACK_ACTIVE   Manual/QR exception   Tạm thời tối đa 24h, permission physical_code.fallback, reason + approver + expiry   Cho phép nghiệp vụ được duyệt; bắt buộc thay chip trước lần outbound tiếp theo
  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

-   Assign: code_value và hardware_uid unique khi populated; target không được có active mapping khác.

-   Replace: tạo mapping mới và audit; không sửa code/UID cũ thành giá trị mới; report/trace giữ replacement chain.

-   Duplicate hardware UID: trả 409 DUPLICATE_HARDWARE_UID, hiển thị target hiện tại theo scope; không tự merge.

-   Fallback manual/QR là exception có thời hạn, không thay quyết định ITEM carrier chip; UI hiển thị cảnh báo và danh sách cần remediate.

-   Warranty TEMP_ONLY vẫn dùng temporary_item_code riêng, không được xem là chip replacement hoặc tự link Item_Instance.

### 13.10. Report Contract

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Report**                     **Columns**                                                                                                                              **Filters**                            **Timezone/period**                                                       **Formula/partial handling**                                                                           **Export**                                             **Reconciliation**
  ------------------------------ ---------------------------------------------------------------------------------------------------------------------------------------- -------------------------------------- ------------------------------------------------------------------------- ------------------------------------------------------------------------------------------------------ ------------------------------------------------------ -----------------------------------------------------------------
  RPT-INV-01 Current Inventory   warehouse, sku_code/name, category, stock_status, available_qty, serial_qty, box_qty, location_hint, updated_at, reconciliation_status   SKU/category/status; as_of             Asia/Ho_Chi_Minh; as_of inclusive                                         Current Balance; location_hint non-authoritative                                                       XLSX/PDF same scope; no PII                            Ledger=Balance=subledger; FAIL warning/block sign-off

  RPT-XNT-01 XNT                 sku, opening_qty, receipt_qty, issue_qty, adjust_in/out, reversal_in/out, closing_qty, partial_remaining                                 from/to, SKU/category, movement type   posted_at converted to Asia/Ho_Chi_Minh; \[from 00:00,to 23:59:59.999\]   closing=opening+receipt-issue+adjust_in-adjust_out+reversal_net; only POSTED qty; remaining separate   XLSX/PDF; filter/timezone in header                    Closing must match Balance at period end

  RPT-MOV-01 Movement History    movement_id/key/type, posted_at, sku, qty_delta, source, original_movement, correction_chain, actor, request_id                          date, SKU, source, type, actor         posted_at authoritative                                                   Show gross and net; append-only; partial posts are separate events                                     XLSX; PDF summary optional                             movement_key unique; chain net equals balance effect

  RPT-STK-01 Stocktake           session, snapshot, cutoff, expected, actual, variance, status, warning/ack, adjustment movement, owner                                   session/date/SKU/status                snapshot/cutoff in Asia/Ho_Chi_Minh                                       expected=snapshot+net movement to cutoff; only APPROVED applied; WAIVED reason                         XLSX/PDF; evidence links                               Applied lines reconcile to adjustment and final Balance

  RPT-WAR-01 Warranty Defect     case code, SKU/model or UNKNOWN, defect, status, received/returned, turnaround; customer masked by default                               date, model, defect, status            event timestamps Asia/Ho_Chi_Minh                                         TEMP_ONLY counted separately; no inventory effect                                                      PII excluded unless extra permission; export audited   Case/event counts match source; no inference link for TEMP_ONLY

  RPT-IO-01 Inbound/Outbound     document, line, source/recipient, planned/expected, posted/issued, remaining, cancelled remainder, status, posting events                date, SKU, status, agency recipient    posting date by posted_at; document date shown separately                 Only posted quantities affect XNT; partial quantities shown explicitly                                 XLSX/PDF same filters                                  Sum posting events = posted/issued qty; no agency stock
  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 13.11. location_hint / Zone Decision

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **DECISION DR-LOC-001: Exact zone/rack/bin hierarchy is OUT OF MVP. MVP supports only Tray mapping and optional location_hint as relative, non-authoritative information. It does not create an inventory bucket, does not participate in available_qty, and may be null until physical survey.**
  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Field/capability**   **Baseline**                  **Usage**                                                       **Constraint**
  ---------------------- ----------------------------- --------------------------------------------------------------- -----------------------------------------------------------------------------
  location_hint          VARCHAR(200), nullable        Examples: "Cửa nhập - khu chờ", "Khay T-01"                     Inventory/trace/report display only; not used to post/reconcile stock

  tray_id / tray code    Existing Container relation   Scan locator for Box/Item mapping                               Putaway changes mapping only; no Inventory_Movement

  zone/rack/bin master   OUT OF MVP                    No hierarchy, capacity, occupancy or optimization requirement   Any future addition requires Change Decision/CR and migration impact review
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 13.12. Migration Template, Sample Data, Error File and Dry-run/Reconciliation Criteria

-   Template evidence đã được đính kèm tại WWHN-333 comment 12634: https://tan0110.atlassian.net/browse/WWHN-333?focusedCommentId=12634

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Sheet**               **Required columns**                                                                                                                                                                        **Sample row**
  ----------------------- ---------------------------------------------------------------------------------------------- -------------------------------------------------------------------------------------------- ---------------------------------------------------------------------------
  **Error-file column**                                                                                                  **Meaning**                                                                                  

  SKU                     sku_code, sku_name, sku_type, unit, is_serial_tracked, brand/category/model, version, status                                                                                                HN-MK-001-V1 \| Máy khoan mẫu \| PRODUCT \| PCS \| TRUE \| ...

  PHYSICAL_CODE           code_value, code_type, carrier_type, hardware_uid, target_legacy_key, status                                                                                                                HN-ITEM-000001 \| ITEM \| RFID \| E200... \| LEG-001 \| ACTIVE

  ITEM                    legacy_item_key, sku_code, serial_number, code_value, condition, import_batch                                                                                                               LEG-001 \| HN-MK-001-V1 \| SN0001 \| HN-ITEM-000001 \| NORMAL \| BATCH-01

  CONTAINER               container_code, type, carrier, physical_code, primary_sku, parent_tray, status                                                                                                              CTN-001 \| CARTON \| QR \| HN-CTN-001 \| HN-MK-001-V1 \| \| IN_STOCK

  OPENING_BALANCE         warehouse_code, sku_code, stock_status, quantity, source, owner_confirmation                                                                                                                HN-WH-01 \| HN-MK-001-V1 \| AVAILABLE \| 100 \| CUTOVER_COUNT \| QL_KHO

  AGENCY                  agency_code, agency_name, phone, address, status                                                                                                                                            AG-001 \| Đại lý mẫu \| 090\*\*\*9922 \| Hà Nội \| ACTIVE

  source_file                                                                                                            Original file name/checksum                                                                  

  sheet                                                                                                                  Logical dataset                                                                              

  row_no                                                                                                                 1-based source row                                                                           

  field                                                                                                                  Field causing error                                                                          

  error_code                                                                                                             DUPLICATE_CODE / UNKNOWN_SKU / INVALID_ENUM / REQUIRED / RECON_MISMATCH / CHECKSUM_CHANGED   

  message                                                                                                                Human-readable Vietnamese message                                                            

  raw_value                                                                                                              Masked if PII                                                                                

  severity                                                                                                               CRITICAL / ERROR / WARNING                                                                   

  suggested_action                                                                                                       Fix, map, confirm waiver or exclude                                                          
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

-   Dry-run PASS: checksum/version recorded; critical_error=0; duplicate active item/code/hardware_uid=0; unknown required FK=0; required/enum/type errors=0; valid+invalid+duplicate = input row count.

-   Reconciliation PASS: 100% SKU quantity match between approved opening source and ledger/balance; serial-tracked quantity equals eligible unique Item count; container mapping creates no extra stock; QL kho signs result.

-   Commit is blocked on FAIL. File changed after dry-run must receive a new checksum and rerun. Rollback test must prove no partial master/opening/movement remains.

### 13.13. Definition of Done - SRS Task vs Release Execution

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Artifact/Ticket**             **Done criteria**                                                                                                                                                                                                                                                  **Explicitly separated execution evidence**
  ------------------------------- ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ --------------------------------------------------------------------------------------------------------------
  SRS/FRD task WWHN-333           Approved baseline v1.3; 0 TBD in core logic; REQ/BR/AC GWT; entity/constraint/enum; stock/state effect; machine-readable API baseline; approved field spec or Figma; approved Test Design/Scenario Master; traceability; PM/PO + QA approval or explicit waiver.   Actual test pass, UAT pass, migration execution, cutover and production proof are NOT required in this task.

  Tech baseline WWHN-31/WWHN-40   Physical ERD/DD/index/DDL; Tech-approved OpenAPI; permission middleware mapping; contract-test environment.                                                                                                                                                        Blocks Ready for Dev/API integration; not proof of QA pass.

  QA design WWHN-33               QA Lead approves scenarios/data/expected results for positive/negative/permission/state/retry/idempotency/concurrency/rollback and UI/NFR.                                                                                                                         Design approval is SRS Done gate; actual execution may be split to release test ticket.

  QA execution ticket             Run approved tests; actual result, evidence, defect IDs, rerun and residual-risk waiver.                                                                                                                                                                           Blocks Ready for Beta/Release.

  UAT ticket                      Business scenarios, users/data/environment, actual result, defects, business sign-off/waiver.                                                                                                                                                                      Blocks Go/No-Go.

  Migration/Cutover ticket        Template/data frozen; dry-run/reconciliation/rollback rehearsal; cutover log; opening balance sign-off.                                                                                                                                                            Blocks production cutover.
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 13.14. P1 Waiver Minimum Content

  -------------------------------------------------------------------------------------------------------------------------
  **Waiver field**        **Required content**
  ----------------------- -------------------------------------------------------------------------------------------------
  Waiver ID/date/expiry   Unique Jira comment or decision; expiry no later than Beta gate

  Scope                   Exact P1 item/artifact/test excluded; no blanket waiver

  Reason                  Why unavailable and why risk is acceptable temporarily

  Risk/impact             DEV/FE/QA/Beta impact and data/stock/privacy exposure

  Compensating control    Manual review, limited pilot, feature flag, read-only, reduced scope, additional reconciliation

  Owner/due date          Named owner and closure date

  Approver                PM/PO; Tech/QA/Security co-approval where relevant

  Reopen condition        Condition that invalidates waiver or blocks Beta/Go-live
  -------------------------------------------------------------------------------------------------------------------------

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **P1 FINAL CONCLUSION: v1.3 closes the specification-level P1 gaps and is eligible for formal review. SRS Done remains conditional on PM/PO baseline approval plus QA-approved Test Design, or a scoped Jira waiver. Ready for Dev/QA/Beta/Go-live remains controlled by the separate technical, test execution, UAT and migration/cutover gates above.**
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

# PHẦN C. BPD v1.2 - PROCESS, BPMN, EXCEPTION VÀ OPERATION BASELINE

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Nguồn được nhúng nguyên nội dung:** File \[DOC\]\[BA\]\[BPD\] Tài liệu Quy trình Nghiệp vụ WMS(2).docx; tiêu đề nội bộ HN-BPD-WMS-001 v1.2. Bao gồm toàn bộ BPMN/swimlane, process detail, KPI, traceability và downstream register.
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

**BPD**

**TÀI LIỆU QUY TRÌNH NGHIỆP VỤ WMS**

**Hệ thống Quản lý Kho & Web App Xem Sản phẩm**

**Hoa Nam Tools**

  -------------------------------------------------------------------------------------------------------------------------
  **Thuộc tính**    **Giá trị**
  ----------------- -------------------------------------------------------------------------------------------------------
  Document ID       HN-BPD-WMS-001

  Version           1.2

  Ngày cập nhật     23/07/2026

  Tác giả/Owner     Business Analyst – Nguyễn Đình Hùng

  Trạng thái        FINAL APPROVAL CANDIDATE / BASELINE EFFECTIVE ON WWHN-335 DONE AFTER OR CONCURRENT WITH WWHN-333 DONE
  -------------------------------------------------------------------------------------------------------------------------

+--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------+
| **KẾT LUẬN PHÁT HÀNH v1.2**                                                                                                                                                                                                                      |
|                                                                                                                                                                                                                                                  |
| • P0 content đã được đóng ở cấp tài liệu: cơ chế approval/effective date, Source Registry/Contract Coverage, AS-IS discovery evidence giới hạn, một Accountable duy nhất, decision dependency, staged traceability và reversal/correction guard. |
|                                                                                                                                                                                                                                                  |
| • Khảo sát trực tiếp tại kho ngày 30/06/2026 đã được tổng hợp trong “Tóm tắt yêu cầu đối tác.docx”; WWHN-335 comment 12646 là evidence xác nhận mức discovery này đủ cho BPD và không yêu cầu bộ ảnh/layout/time study/mẫu phiếu AS-IS riêng.    |
|                                                                                                                                                                                                                                                  |
| • BPD v1.2 trở thành APPROVED/BASELINE vào ngày WWHN-335 được chuyển DONE, với điều kiện WWHN-333 đã DONE hoặc được PM/PO chuyển DONE đồng thời. WWHN-335 transition history và attachment v1.2 là formal approval evidence.                     |
|                                                                                                                                                                                                                                                  |
| • File Excel thật, checksum, dry-run và migration reconciliation được waiver sang WWHN-69: không chặn BPD Done nhưng vẫn chặn Migration Commit/Beta/Go-live.                                                                                     |
|                                                                                                                                                                                                                                                  |
| • Ready for Dev/QA/Beta tổng thể vẫn phụ thuộc ERD/OpenAPI/UX/permission/test data và các gate downstream theo SRS.                                                                                                                              |
+==================================================================================================================================================================================================================================================+
+--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------+

## 0. DOCUMENT CONTROL

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Trường**         **Giá trị**
  ------------------ -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  Document ID        HN-BPD-WMS-001

  Version            1.2

  Title              Business Process Document – Quy trình nghiệp vụ WMS Hoa Nam

  Author/Owner       Business Analyst – Nguyễn Đình Hùng

  Updated date       23/07/2026

  Current status     FINAL APPROVAL CANDIDATE / PROCESS BASELINE EFFECTIVE ON WWHN-335 DONE AFTER OR CONCURRENT WITH WWHN-333 DONE

  Source documents   \[DOC\]\[BA\]\[BRD\] WMS v3.11; \[DOC\]\[BA\]\[SRS-FRD\] v1.3; Tóm tắt yêu cầu đối tác; Hợp đồng 07072026/HOANAM-A4I; WWHN-333 comments 12634/12635; WWHN-335 comment 12646; WWHN-69 migration execution.

  Document purpose   Mô tả AS-IS ở mức discovery đã được xác nhận và TO-BE chi tiết; cung cấp process/business baseline. BPD Done tách khỏi migration execution và các API/data/UX/test gate downstream.
  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 0.1. Approval matrix

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Vai trò**                 **Người**                          **Phạm vi xác nhận**                                                             **Trạng thái**                                                    **Evidence cần có**
  --------------------------- ---------------------------------- -------------------------------------------------------------------------------- ----------------------------------------------------------------- ------------------------------------------------------------------
  BA Owner                    Nguyễn Đình Hùng                   Nội dung quy trình, traceability, P0/P1 closure                                  COMPLETED / READY FOR PM APPROVAL                                 BPD v1.2

  PM/PO                       Nguyễn Quang Đức                   Scope, priority, process baseline, waiver và task Done                           APPROVAL BY WWHN-335 DONE                                         Transition history + attachment v1.2; effective date = ngày DONE

  Đại diện xác nhận phạm vi   Vũ Tuấn Cương                      Phạm vi kinh doanh tổng quát và quyết định/waiver theo project approval list     SCOPE EVIDENCE AVAILABLE / PROCESS BASELINE BY WWHN-335 DONE      Hợp đồng + WWHN-335

  Process/Data Owner          Chị Phương – Quản lý kho Hoa Nam   Accountable duy nhất; AS-IS discovery, vận hành kho, dữ liệu và reconciliation   CONFIRMED FOR BPD BASELINE                                        Khảo sát 30/06/2026; WWHN-335 comment 12646

  Tech Lead                   Nguyễn Quang Đức                   Tính khả thi transaction/idempotency/data authority; technical handoff           DECISIONS EFFECTIVE ON WWHN-333 DONE; TECH ARTIFACTS DOWNSTREAM   WWHN-333 comment 12635 + transition; WWHN-31

  QA Lead                     Đặng Thị Soi                       Testability và staged test coverage; execution downstream                        PROCESS REVIEW BY WWHN-335 DONE / TEST DESIGN DOWNSTREAM          WWHN-33; waiver/approval recorded on Jira
  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 0.2. Readiness assessment

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Checkpoint**                                          **Kết luận**                       **Giải thích**
  ------------------------------------------------------- ---------------------------------- ------------------------------------------------------------------------------------------------------------
  Lập BPD TO-BE                                           YES                                Actor, scope, state, rule, BPMN, stock effect, exception, control và E2E đã có.

  Mô tả AS-IS discovery                                   YES – LIMITED DISCOVERY EVIDENCE   Khảo sát trực tiếp tại kho ngày 30/06/2026; kết quả tổng hợp tại “Tóm tắt yêu cầu đối tác.docx”.

  Chi tiết vật lý/ảnh/layout/time study/mẫu AS-IS riêng   NOT REQUIRED FOR BPD               Dự án nhỏ; PM/PO và Process Owner xác nhận mức evidence giới hạn đủ đóng P0-03 tại WWHN-335 comment 12646.

  Đính kèm Jira để final review                           YES                                BPD v1.2 là attachment hiện hành tại WWHN-335; v1.0/v1.1 được đánh dấu SUPERSEDED.

  Approved/Baseline / Jira Done                           CONDITIONAL ON TRANSITION          Baseline có hiệu lực khi WWHN-335 DONE, sau hoặc đồng thời với WWHN-333 DONE.

  P1 process/business readiness                           YES – BPD GATE                     P1-01..09, 11..12 đóng; P1-10 waiver sang WWHN-69 và chỉ chặn migration/Beta/Go-live.
  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 0.3. Open External Closure Register

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **ID**        **Điểm cần đóng**                          **Owner**                     **Hiện trạng**                           **Evidence/Điều kiện đóng**                                                                                                                                                                                                                  **Blocks**
  ------------- ------------------------------------------ ----------------------------- ---------------------------------------- -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- -------------------------------------
  BPD-EVD-001   AS-IS discovery và handoff vật lý          Chị Phương + BA               CLOSED WITH LIMITED DISCOVERY EVIDENCE   Khảo sát 30/06/2026; Trần Tùng Lâm, Nguyễn Đình Hùng và Chị Phương; [[WWHN-335 comment 12646]{.underline}](https://tan0110.atlassian.net/browse/WWHN-335?focusedCommentId=12646). Không yêu cầu package ảnh/layout/time study/form riêng.    Không

  BPD-EVD-002   Process Owner, executor và backup          Chị Phương + PM/PO            CLOSED                                   Chị Phương là Accountable duy nhất; executor theo role/permission; Backup NOT DESIGNATED và phải được Hoa Nam ủy quyền khi cần.                                                                                                              Không

  BPD-EVD-003   Formal approval/effective date             PM/PO                         PENDING FORMAL TRANSITION                [[WWHN-335 DONE]{.underline}](https://tan0110.atlassian.net/browse/WWHN-335) là approval event; effective date là ngày transition. [[WWHN-333]{.underline}](https://tan0110.atlassian.net/browse/WWHN-333) phải DONE trước hoặc đồng thời.   BPD Approved/Done

  BPD-EVD-004   Actual Excel/checksum/dry-run/reconcile    Chị Phương + WWHN-69 owners   WAIVED TO WWHN-69                        Không chặn BPD Done; bắt buộc trước Migration Commit/Beta/Go-live.                                                                                                                                                                           Migration/Beta

  BPD-EVD-005   Thiết bị/điểm quét và performance target   Tech Lead + Khách hàng        P2 / DOWNSTREAM                          Model thiết bị, vị trí đọc, batch/latency nếu tích hợp thật.                                                                                                                                                                                 Hardware readiness only

  BPD-EVD-006   Test Master/UAT/Cutover evidence           QA/PM/Khách hàng              DOWNSTREAM EXECUTION                     WWHN-33/WWHN-75; actual result, defect, evidence, sign-off.                                                                                                                                                                                  QA/UAT/Go-live; không chặn BPD Done
  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 0.4. Change log

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Version**   **Date**     **Thay đổi**                                                                                                                                                                                                                                                                                         **Tác giả**
  ------------- ------------ ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- -------------
  1.0           22/07/2026   Phát hành BPD TO-BE từ BRD v3.11, SRS/FRD v1.3 và tóm tắt yêu cầu đối tác; bổ sung readiness, AS-IS discovery, process inventory, process controls và open closure register.                                                                                                                         BA

  1.1           23/07/2026   Hoàn thiện P1: BPMN 2.0/swimlane; sơ đồ Putaway/Reconciliation/Reversal/App PV/Report/Migration; AS-IS–Gap–TO-BE; zone/location_hint; mẫu TO-BE; KPI baseline; warranty privacy; state/cancel/reopen rules; App PV cache; migration control; E2E; tách HTTP/API detail sang SRS/Technical Handoff.   BA

  1.2           23/07/2026   Đóng P0/P1 BPD gate: approval mechanism WWHN-335, AS-IS limited discovery evidence comment 12646, single-accountable RACI, Contract/Source/Supersession, staged traceability, reversal dependency guard và migration waiver WWHN-69.                                                                 BA
  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 0.5. P0 Closure Matrix

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **P0**         **Cách đóng trong BPD v1.2**                                                                                                    **Closure status**            **Evidence/Gate**            **Remaining condition**
  -------------- ------------------------------------------------------------------------------------------------------------------------------- ----------------------------- ---------------------------- ------------------------------------------------
  P0-01          Approval mechanism B: WWHN-335 DONE là formal approval; effective date = ngày transition.                                       CLOSED BY GOVERNANCE DESIGN   WWHN-335                     WWHN-333 DONE trước/đồng thời; PM transition

  P0-02          Contract-first Source Registry, Contract Coverage, discovery/decision/Jira sources và supersession register.                    CLOSED                        §1.3, §1.3.1, §1.3.2         Formal transition only

  P0-03          Khảo sát kho 30/06/2026 được tổng hợp trong Tóm tắt yêu cầu; limited evidence được xác nhận.                                    CLOSED                        WWHN-335 comment 12646; §2   Không yêu cầu ảnh/layout/time study/form riêng

  P0-04          Chị Phương là Accountable duy nhất; executor theo role; backup chưa chỉ định và cần ủy quyền.                                   CLOSED                        §3.2, §4.2–4.3               Không

  P0-05          Web App và carrier decision chỉ có hiệu lực theo BRD/Decision Record khi WWHN-333 DONE.                                         CONDITIONAL CLOSED            WWHN-333 comment 12635       WWHN-333 transition

  P0-06          Traceability theo staged model: Source/Decision → BP → REQ/BR/AC/TC → Jira/artifact/evidence.                                   CLOSED AT BPD LEVEL           §8.1; WWHN-31/33/40/69/75    Execution evidence downstream

  P0-07          Reversal khóa dependency, partial, chain correction, condition, pending return, permission, concurrency và report net effect.   CLOSED                        §5.10, §6.1–6.4              Implementation/test downstream
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 0.6. P1 Closure Matrix

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **P1**         **Nội dung đóng trong BPD v1.2**                                                  **Closure status**                    **Evidence/Section**   **Remaining dependency**
  -------------- --------------------------------------------------------------------------------- ------------------------------------- ---------------------- ---------------------------------------------------------------------------------
  **P1-01**      Thay 4 flowchart bằng BPMN swimlane, có actor/system/physical/document/handoff.   CLOSED                                Hình 2–5               Formal review

  **P1-02**      Bổ sung Putaway, Reconciliation, Reversal, App publish, Report, Migration.        CLOSED                                Hình 6–11              Không

  **P1-03**      AS-IS → Gap → TO-BE matrix có root cause, impact và KPI.                          CLOSED                                §2.3                   Limited discovery evidence đã được chấp thuận; refine qua Jira/CR nếu phát sinh

  **P1-04**      Chốt flat zone_code + location_hint/tray, không tạo stock bucket.                 CLOSED                                §3.3                   Khảo sát nhãn khu vực thực tế

  **P1-05**      Bổ sung 6 mẫu nghiệp vụ TO-BE; ghi rõ không phải mẫu AS-IS.                       CLOSED AS TO-BE                       §7.2                   Actual forms nếu Hoa Nam có

  **P1-06**      KPI có formula, owner, source, frequency và target.                               CLOSED / EFFECTIVE ON WWHN-335 DONE   §7.3                   Formal target approval

  **P1-07**      Warranty privacy: access, masking, consent, export, retention, purge.             CLOSED                                §6.5                   Security implementation

  **P1-08**      Chuẩn hóa transition một chiều; cancel/close/reopen terminal rules.               CLOSED                                §6.2                   Không

  **P1-09**      App PV cache ≤60s; stale warning \>5m; last_inventory_sync_at.                    CLOSED                                §5.11, Hình 9          Monitoring implementation

  **P1-10**      Data Owner/schema/tolerance/dry-run/checksum/rollback/reconcile đã chốt.          WAIVED TO WWHN-69 / CLOSED FOR BPD    §5.13 P1 baseline      Actual file + execution chặn Migration Commit/Beta/Go-live; không chặn BPD Done

  **P1-11**      E2E nhập→tồn→xuất→bảo hành→trả/reversal→App/report.                               CLOSED                                §5.14                  Test data implementation

  **P1-12**      BPD giữ business control; HTTP status/header/API convention chuyển SRS/Tech.      CLOSED                                §6.3                   WWHN-31/WWHN-40
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 1. MỤC TIÊU, PHẠM VI VÀ NGUỒN

### 1.1. Mục tiêu tài liệu

-   Mô tả các quy trình nghiệp vụ WMS từ trigger đến kết quả, bao gồm actor, dữ liệu đầu vào/đầu ra, bước xử lý, ngoại lệ và kiểm soát.

-   Thống nhất cách vận hành TO-BE cho kho tổng Hoa Nam trước khi DEV/FE/QA thiết kế chi tiết, phát triển và kiểm thử.

-   Phân biệt rõ hoạt động làm thay đổi tồn và hoạt động chỉ cập nhật thông tin/mapping.

-   Quản lý các điểm chưa có evidence bên ngoài mà không để placeholder làm mơ hồ logic nghiệp vụ lõi.

### 1.2. Phạm vi MVP

  ------------------------------------------------------------------------------------------------------------------------------
  **Trong phạm vi**                                                    **Ngoài phạm vi**
  -------------------------------------------------------------------- ---------------------------------------------------------
  01 kho tổng Hoa Nam; đại lý là nơi nhận, không phải kho              Multi-warehouse hoặc tồn kho đại lý/cửa hàng con

  SKU PRODUCT/COMPONENT; item/serial; carton/box/tray; Physical_Code   Kệ/bin/sơ đồ kho hoặc tối ưu vị trí kho chuyên sâu

  Nhập kho, partial receipt, putaway linh kiện, reversal               Approval workflow cho phiếu nhập/xuất

  Xuất kho, partial issue, reverse issue/correction                    Bán hàng, order, reservation, payment, công nợ, giá vốn

  Tồn kho, ledger/balance/subledger, kiểm kê snapshot                  Hardware driver/gateway/protocol chuyên sâu

  Bảo hành cơ bản và TEMP_ONLY                                         Chi phí, SLA, người sửa/phân công sửa chữa

  Báo cáo kho và Web App PV read-only                                  Native mobile app, offline mode
  ------------------------------------------------------------------------------------------------------------------------------

### 1.3. Source Registry

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Source ID**   **Nguồn**                                                                                                      **Nội dung sử dụng**                                                                                                     **Đánh giá evidence**
  --------------- -------------------------------------------------------------------------------------------------------------- ------------------------------------------------------------------------------------------------------------------------ ---------------------------------------------------------------------------------
  SRC-BPD-001     Tóm tắt yêu cầu đối tác – Biên bản tổng hợp discovery/khảo sát nghiệp vụ tại kho                               AS-IS discovery: Excel/thủ công, kiểm kê, hai cửa nhập–xuất, scan nhanh, bảo hành và App PV; khảo sát ngày 30/06/2026.   AVAILABLE – CONTROLLING AS-IS DISCOVERY SUMMARY; NO SEPARATE PHOTO/FORM PACKAGE

  SRC-BPD-002     BRD Hoa Nam v3.11                                                                                              Scope, actor, feature, state, business rule, luồng chi tiết, decision, migration/UAT plan                                FINAL APPROVAL CANDIDATE; EFFECTIVE ON WWHN-333 DONE

  SRC-BPD-003     SRS/FRD Hoa Nam v1.3                                                                                           Requirement, AC/TC, core flow, permission, data authority, privacy, cache/API/error/NFR và technical handoff reference   P1 READINESS SOURCE; TECH APPROVAL GATES DOWNSTREAM

  SRC-BPD-004     Hợp đồng 07072026/HOANAM-A4I, ngày 21/07/2026, trang 1–2                                                       Phạm vi tổng quát kho thông minh, App đại lý, RFID, nhập/xuất, kiểm kê, bảo hành                                         SOURCE AVAILABLE; NO APPENDIX

  SRC-BPD-005     WWHN-333 comment 12634 – Import Template                                                                       Schema migration TO-BE và evidence template; actual Excel data chưa có                                                   TEMPLATE AVAILABLE; ACTUAL FILE/EXECUTION WAIVED TO WWHN-69

  SRC-BPD-006     [[WWHN-333 comment 12635]{.underline}](https://tan0110.atlassian.net/browse/WWHN-333?focusedCommentId=12635)   Kết luận/đề nghị PM phê duyệt BRD v3.11 và chuyển WWHN-333 DONE.                                                         AVAILABLE; FORMAL EFFECT ON WWHN-333 DONE

  SRC-BPD-007     [[WWHN-335 comment 12646]{.underline}](https://tan0110.atlassian.net/browse/WWHN-335?focusedCommentId=12646)   Xác nhận AS-IS limited discovery evidence đủ đóng P0-03 và không chặn BPD Done.                                          AVAILABLE – CONTROLLING BPD AS-IS EVIDENCE

  SRC-BPD-008     [[WWHN-335 – BPD approval task]{.underline}](https://tan0110.atlassian.net/browse/WWHN-335)                    Approval mechanism, attachment v1.2, transition history và effective date.                                               FORMAL APPROVAL EVENT = TASK DONE

  SRC-BPD-009     [[WWHN-69 – Migration execution]{.underline}](https://tan0110.atlassian.net/browse/WWHN-69)                    Actual Excel, checksum, dry-run, reconcile, rollback và sign-off.                                                        DOWNSTREAM; WAIVED FROM BPD DONE
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

#### 1.3.1. Contract Coverage Matrix

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Coverage ID**   **Contract/Input item**                   **Process coverage**   **Detailed source**                                **Status**
  ----------------- ----------------------------------------- ---------------------- -------------------------------------------------- -----------------------------------------------
  CTR-BPD-001       Khảo sát, phân tích hệ thống kho          BP-WMS-01..13          HĐ trang 1–2; SRC-BPD-001; BRD v3.11; SRS v1.3     GENERAL CONTRACT SCOPE; DETAIL BY BRD/SRS/BPD

  CTR-BPD-002       RFID, nhập/xuất, kiểm kê, bảo hành        BP-WMS-02..10, 12      HĐ; DR-CARRIER-001; DEC-INV-001; DEC-PARTIAL-001   COVERED; TECH DETAIL DOWNSTREAM

  CTR-BPD-003       Ứng dụng đại lý/App PV                    BP-WMS-11              HĐ; DR-PLAT-001; BRD/SRS App PV scope              MVP WEB READ-ONLY BASELINE

  CTR-BPD-004       Dữ liệu đầu kỳ và triển khai thử nghiệm   BP-WMS-13              Import Template; WWHN-69; WWHN-75                  BPD DESIGN COVERED; EXECUTION DOWNSTREAM

  CR-POLICY-001     Capability ngoài kho/App PV baseline      N/A                    Formal Change Request                              OUT-OF-SCOPE CHANGE REQUIRES CR
  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

#### 1.3.2. Source and Supersession Register

  -------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Artifact/statement cũ**             **Controlling artifact/decision**                 **Status**                      **Evidence**
  ------------------------------------- ------------------------------------------------- ------------------------------- -------------------------------------
  BPD v1.0                              BPD v1.2                                          SUPERSEDED                      WWHN-335 attachment history

  BPD v1.2                              BPD v1.2                                          SUPERSEDED                      WWHN-335 attachment history

  BRD versions trước v3.11              BRD v3.11                                         SUPERSEDED WHEN WWHN-333 DONE   WWHN-333 comment 12635 + transition

  App local/platform chưa rõ            DR-PLAT-001 – Web App responsive                  SUPERSEDED FOR MVP              WWHN-333 DONE

  Carrier bắt buộc đồng nhất            DR-CARRIER-001 – ITEM chip; container linh hoạt   SUPERSEDED                      WWHN-333 DONE

  Actual migration evidence trong BPD   WWHN-69 execution record                          MOVED DOWNSTREAM / WAIVED       §5.13; WWHN-69
  -------------------------------------------------------------------------------------------------------------------------------------------------------------

### 1.4. Nguyên tắc ưu tiên khi nguồn mâu thuẫn

-   Decision Record/Decision Matrix mới nhất trong BRD v3.11 và SRS/FRD v1.3 điều khiển TO-BE.

-   Tóm tắt yêu cầu đối tác chỉ được dùng để mô tả nhu cầu ban đầu và AS-IS ở mức discovery; không tự mở rộng scope ngoài BRD/SRS.

-   Các điểm chưa được nguồn hỗ trợ không được tự suy đoán; được ghi vào Open Closure Register với owner và evidence cần có.

-   BPD tập trung quy trình nghiệp vụ; API, DB, OpenAPI và test detail chỉ được tham chiếu khi cần giải thích control.

## 2. AS-IS Ở MỨC DISCOVERY

+------------------------------------------------------------------------------------------------------------------------------------------------------------------------------+
| **GIỚI HẠN VÀ EVIDENCE AS-IS**                                                                                                                                               |
|                                                                                                                                                                              |
| • Đội dự án đã khảo sát trực tiếp tại kho Hoa Nam ngày 30/06/2026. Phía Hoa Nam: Chị Phương – Quản lý kho. Phía dự án: Trần Tùng Lâm và Nguyễn Đình Hùng.                    |
|                                                                                                                                                                              |
| • Kết quả khảo sát được tổng hợp trong “Tóm tắt yêu cầu đối tác.docx” và được dùng làm nguồn AS-IS discovery cho BPD.                                                        |
|                                                                                                                                                                              |
| • Do quy mô dự án nhỏ và hiện trạng chủ yếu quản lý bằng Excel/thao tác thủ công, hai bên không lập riêng bộ ảnh hiện trường, sơ đồ layout, time study hoặc mẫu phiếu AS-IS. |
|                                                                                                                                                                              |
| • BPD chỉ khẳng định các chi tiết được ghi trong nguồn discovery; điều chỉnh phát sinh khi UAT/vận hành thử được quản lý bằng Jira/Change Request.                           |
|                                                                                                                                                                              |
| • WWHN-335 comment 12646 xác nhận mức evidence này đủ đóng P0-03 và không chặn BPD Done.                                                                                     |
+==============================================================================================================================================================================+
+------------------------------------------------------------------------------------------------------------------------------------------------------------------------------+

### 2.1. Hiện trạng đã được nguồn hỗ trợ

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Nhóm**            **Hiện trạng discovery**                                                                                 **Hệ quả**
  ------------------- -------------------------------------------------------------------------------------------------------- ---------------------------------------------------------------------------
  Quản lý dữ liệu     Dữ liệu kho được lưu bằng Excel; SKU đang cần chuẩn hóa.                                                 Khó duy trì mã duy nhất, truy vết vòng đời và đối soát nhiều nguồn.

  Định danh hàng      Chưa có mã quét/chip ổn định cho toàn bộ máy; tem dán có thể mờ.                                         Tra cứu và kiểm kê phụ thuộc nhập tay/nhận diện thủ công.

  Kiểm kê             Kiểm kê chưa có phần mềm quản lý tốt và mất nhiều thời gian.                                             Rủi ro đếm trùng, thiếu evidence và khó giải thích chênh lệch.

  Nhập/xuất           Có điểm/cửa nhập và xuất cần thao tác nhanh bằng quét.                                                   Nếu ghi tay sau thao tác vật lý có thể chậm cập nhật hoặc sai tồn.

  Bảo hành            Máy khách dùng một thời gian có thể mang tới đại lý/kho để sửa/bảo hành; vị trí để máy chưa chuẩn hóa.   Khó nhận diện khi mất mã và khó theo dõi trạng thái xử lý.

  Kênh xem sản phẩm   Cần app nội bộ/read-only để chủ đại lý/khách xem sản phẩm và tồn khả dụng.                               Thông tin sản phẩm/tồn chưa được chuẩn hóa thành kênh tra cứu thống nhất.
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 2.2. Pain points và mục tiêu cải tiến

  -------------------------------------------------------------------------------------------------------------------------------------------
  **Pain point**                             **Mục tiêu TO-BE**
  ------------------------------------------ ------------------------------------------------------------------------------------------------
  Kiểm kê thủ công, chậm                     Scan batch/wide-range hoặc demo scanner; snapshot, count cutoff, variance và movement warning.

  Mã dán dễ mờ/khó nhận diện                 Physical_Code duy nhất; ITEM bắt buộc chip; container carrier linh hoạt.

  Excel và SKU chưa chuẩn                    Product Master Data, SKU code chuẩn và alias cho mã cũ.

  Nguy cơ cập nhật tồn lặp                   Post Receipt/Post Issue/Apply Adjustment idempotent và atomic.

  Không rõ máy bảo hành khi mất mã           TEMP_ONLY với temporary_item_code và thông tin nhận diện bắt buộc.

  Dữ liệu tồn và container dễ double count   Movement authoritative; Balance projection; item/container subledger reconcile.
  -------------------------------------------------------------------------------------------------------------------------------------------

### 2.3. AS-IS → Gap → TO-BE Impact Matrix

Ma trận dưới đây chuyển discovery evidence đã được xác nhận thành control TO-BE. Các chi tiết vật lý không có trong nguồn khảo sát không được tự suy đoán; nếu phát sinh khác biệt tại UAT/vận hành thử, BA cập nhật bằng Jira/Change Request với Process Owner.

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Pain point AS-IS**                              **Root cause**                                                         **Control TO-BE**                                                                               **Impact role/handoff**                                                 **Impact data/system**                                              **KPI**
  ------------------------------------------------- ---------------------------------------------------------------------- ----------------------------------------------------------------------------------------------- ----------------------------------------------------------------------- ------------------------------------------------------------------- -------------------------------------------------------------
  Kiểm kê thủ công, lâu và khó giải thích lệch      Không có Physical_Code thống nhất; không có snapshot/cutoff/evidence   Scan batch; snapshot; expected_at_count; movement warning; approve/waive; adjustment có audit   NV kho chuyển từ ghi tay sang scan/count; QL kho review variance        Stocktake Session/Snapshot/Count/Variance; authoritative movement   Stocktake cycle time; unknown scan; variance/reconciliation

  Tem/mã dễ mờ, định danh không ổn định             Mã dán và legacy code chưa có source of truth                          Physical_Code duy nhất; ITEM dùng chip; alias mã cũ; unknown không được Post                    NV kho scan/đối chiếu; Admin quản lý code                               Code uniqueness, carrier mapping, Scan_Event                        Unknown/duplicate scan rate

  Excel và SKU chưa chuẩn                           Nhiều nguồn, format/mã trùng, chưa có Data Owner rõ                    Product Master; SKU chuẩn; migration dry-run/checksum/error file                                Chị Phương là Data Owner; BA map; QA reconcile                          Migration_Batch, alias, validation report                           Migration pass rate

  Nhập/xuất có nguy cơ cập nhật chậm hoặc lặp       Handoff vật lý và ghi nhận hệ thống tách rời; retry/click lặp          Scan tại handoff; Post là điểm đổi tồn; business request key; atomic transaction                NV kho chịu trách nhiệm evidence; QL kho theo dõi lỗi                   Movement + Balance + subledger cùng commit                          Posting error; duplicate posting prevented

  Không rõ máy bảo hành khi mất mã                  Không có định danh thay thế và evidence tiếp nhận                      TEMP_ONLY, temporary_item_code, description/ảnh/consent, state history                          NV bảo hành tiếp nhận/đối chiếu; quyền PII tách biệt                    Warranty_Case/Event; PII access audit                               Unknown warranty identification; turnaround (monitor)

  Tồn và container dễ double count                  Không phân biệt SKU/item/container/locator                             Movement authoritative; Balance projection; carton/box/tray không là stock bucket               QL kho reconcile; NV kho sửa mapping bằng Putaway/Correction            Ledger–Balance–Subledger 3 lớp                                      Inventory reconciliation pass rate

  App xem tồn có thể chậm hoặc hiểu nhầm realtime   Read model/cache không có freshness contract                           Cache ≤60 giây; last_inventory_sync_at; cảnh báo stale \>5 phút; read-only                      Admin publish; Tech vận hành sync; người xem nhận freshness indicator   PV cache/read model; sync timestamp                                 PV freshness compliance
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 3. KIẾN TRÚC QUY TRÌNH TO-BE

`<img src="media/image3.png" alt="" style="width:7.0in;height:1.6001312335958005in" />`{=html}

*Hình 1. Bản đồ quy trình nghiệp vụ WMS và điểm tích hợp App PV*

### 3.1. Nguyên tắc điều khiển quy trình

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **ID**   **Nguyên tắc**                                                                                **Hệ quả vận hành**
  -------- --------------------------------------------------------------------------------------------- ------------------------------------------------------------------------
  P-01     MVP chỉ có 01 kho tổng. Agency là master data nơi nhận.                                       Không tạo balance, movement hoặc stocktake cho agency.

  P-02     Tồn chỉ đổi tại Post Receipt, Post Issue, Apply Adjustment hoặc Reversal đã post.             DRAFT/SCANNING/PUTAWAY/WARRANTY/App PV không tự đổi tồn.

  P-03     Inventory_Movement là authoritative ledger; Balance và subledger cập nhật cùng transaction.   Mọi lệch phải tạo incident và xử lý bằng reversal/adjustment có audit.

  P-04     Mọi action đổi tồn bắt buộc idempotent.                                                       Retry cùng key/payload không tạo movement hoặc cập nhật tồn lần hai.

  P-05     ITEM bắt buộc dùng chip; CARTON/BOX/TRAY carrier linh hoạt.                                   Nghiệp vụ xử lý theo Physical_Code, không phụ thuộc vendor thiết bị.

  P-06     Container/locator không phải bucket tồn độc lập.                                              Không double count thùng/hộp/khay với item/quantity bên trong.

  P-07     Nhập/xuất hỗ trợ partial post và close remainder.                                             Có PARTIALLY_POSTED; không over-receipt/over-issue.

  P-08     Kiểm kê không khóa toàn kho.                                                                  Dùng snapshot + movement đến cutoff + stale guard.
  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 3.2. Process inventory

  ------------------------------------------------------------------------------------------------------------------------------------------------------
  **Process ID**   **Tên quy trình**                    **Loại**       **Accountable / Process Owner**   **Kết quả**
  ---------------- ------------------------------------ -------------- --------------------------------- -----------------------------------------------
  BP-WMS-01        Quản lý danh mục, SKU và mã vật lý   Supporting     Chị Phương – QL kho Hoa Nam       Master data sẵn sàng cho giao dịch

  BP-WMS-02        Nhập kho sản phẩm/máy theo thùng     Core           Chị Phương – QL kho Hoa Nam       Tăng tồn sản phẩm đúng một lần

  BP-WMS-03        Nhập kho linh kiện và Putaway        Core           Chị Phương – QL kho Hoa Nam       Tăng tồn; gán vị trí khay không đổi tồn

  BP-WMS-04        Xuất kho sản phẩm                    Core           Chị Phương – QL kho Hoa Nam       Giảm tồn, ghi nơi nhận

  BP-WMS-05        Xuất kho linh kiện                   Core           Chị Phương – QL kho Hoa Nam       Giảm tồn theo serial/quantity

  BP-WMS-06        Tra cứu tồn và đối soát              Control        Chị Phương – QL kho Hoa Nam       Tồn ledger/balance/subledger khớp

  BP-WMS-07        Kiểm kê và điều chỉnh                Control        Chị Phương – QL kho Hoa Nam       Variance được review và apply an toàn

  BP-WMS-08        Bảo hành máy có mã                   Core-support   Chị Phương – QL kho Hoa Nam       Theo dõi vòng đời, không tăng tồn bán

  BP-WMS-09        Bảo hành TEMP_ONLY                   Core-support   Chị Phương – QL kho Hoa Nam       Theo dõi bằng mã tạm, không suy đoán item gốc

  BP-WMS-10        Reversal/Correction sau Post         Control        Chị Phương – QL kho Hoa Nam       Sửa sai append-only, giữ movement gốc

  BP-WMS-11        Publish và cung cấp tồn cho App PV   Integration    Chị Phương – QL kho Hoa Nam       Read-only product + available_qty

  BP-WMS-12        Báo cáo vận hành                     Reporting      Chị Phương – QL kho Hoa Nam       XNT, kiểm kê, bảo hành

  BP-WMS-13        Migration và Opening Balance         Transition     Chị Phương – QL kho Hoa Nam       Dữ liệu đầu kỳ đối soát và ký xác nhận
  ------------------------------------------------------------------------------------------------------------------------------------------------------

### 3.3. Decision Baseline – zone_code, location_hint và Tray

DEC-BPD-LOC-001: MVP không triển khai cây zone/rack/bin hoặc tồn theo vị trí. Để đáp ứng nhu cầu “khu vực/vị trí tương đối ở mức đủ dùng”, hệ thống cho phép nhãn khu vực phẳng và gợi ý vị trí; các trường này chỉ hỗ trợ tìm kiếm/handoff, không tham gia công thức tồn và không chặn Post.

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Thành phần**      **Baseline P1**                                                                                                                    **Bắt buộc**                                                             **Cách dùng**                                                     **Không được hiểu là**
  ------------------- ---------------------------------------------------------------------------------------------------------------------------------- ------------------------------------------------------------------------ ----------------------------------------------------------------- ---------------------------------------------
  **zone_code**       Nhãn khu vực phẳng do QL kho cấu hình; ví dụ RECEIVING, BULK_STORAGE, COMPONENT_AREA, WARRANTY, DISPATCH. Không có parent/child.   Optional; nên ghi tại handoff/putaway khi biết                           Lọc/tìm khu vực tương đối, điều phối vật lý và báo cáo vận hành   Warehouse con, bin hierarchy hoặc chiều tồn

  **location_hint**   Mô tả vị trí tương đối nullable; ví dụ “gần cửa nhập”, “dãy trái khu linh kiện”, “phòng bảo hành”.                                 Optional                                                                 Hiển thị cho NV kho khi tìm hàng; audit khi thay đổi              Địa chỉ tồn chính xác hoặc khóa duy nhất

  **tray_code**       Locator có Physical_Code; dùng cho box linh kiện sau Putaway.                                                                      Bắt buộc khi box đã được xếp vào tray; không bắt buộc ngay lúc Receipt   Box–Tray mapping và tra cứu vị trí                                SKU, stock bucket hoặc đơn vị xuất

  **handoff_area**    Khu vực vật lý ghi tại bước nhận/bàn giao nếu vận hành cần.                                                                        Optional theo SOP                                                        Evidence nơi chuyển giao trách nhiệm                              Điểm tích hợp thiết bị cố định trong MVP

  **Quy tắc stock**   Inventory_Balance chỉ theo kho tổng + SKU + stock status; zone/tray/location không tạo balance/movement riêng.                     Always                                                                   Ngăn double count và giữ inventory authority                      Tồn theo zone/tray
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 4. ACTOR, RACI VÀ PHÂN TÁCH TRÁCH NHIỆM

### 4.1. Actor

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Actor**                          **Trách nhiệm chính**
  ---------------------------------- --------------------------------------------------------------------------------------------------------------------------------------------------------------
  Admin hệ thống                     Cấu hình tài khoản/quyền, master data, SKU, Physical_Code, publish sản phẩm và tham số hệ thống.

  Quản lý kho tổng / Process Owner   Chị Phương – Quản lý kho Hoa Nam; Accountable duy nhất cho baseline nghiệp vụ kho, dữ liệu đầu vào, reconciliation và quyết định vận hành.

  Nhân viên kho                      Tạo/scan phiếu nhập/xuất, đếm kiểm kê, putaway, tra cứu item/container.

  Nhân viên bảo hành                 Tiếp nhận, cập nhật và trả máy bảo hành; quản lý TEMP_ONLY.

  Ban lãnh đạo                       Xem báo cáo read-only.

  Người xem App PV                   Xem sản phẩm published và available_qty, không tạo nghiệp vụ kho.

  PM/PO/Đại diện khách hàng          Phê duyệt scope, quyết định, waiver, UAT và Go/No-Go.

  Backup Process Owner               NOT DESIGNATED. Khi cần thay thế, Hoa Nam phải ủy quyền và cấp tài khoản/quyền tương ứng; không mặc định mọi nhân viên kho có quyền approve/adjust/reversal.

  Executor theo vai trò              Nhân viên kho, nhân viên bảo hành, Admin, BA/Tech/QA thực hiện theo tài khoản, role và action-level permission; không cần ghi tên cá nhân cố định.
  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 4.2. RACI cấp quy trình

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Process**                        **Accountable (A) duy nhất**   **Responsible / Executor (R)**                **Consulted (C)**                   **Informed / View (I/V)**
  ---------------------------------- ------------------------------ --------------------------------------------- ----------------------------------- ------------------------------------
  BP-WMS-01 Danh mục/SKU/Mã          Chị Phương                     Admin/người có master.manage                  NV kho, NV bảo hành, Tech           Lãnh đạo; App PV chỉ xem published

  BP-WMS-02 Nhập sản phẩm            Chị Phương                     NV kho được phân quyền                        Admin/Tech khi lỗi                  Lãnh đạo

  BP-WMS-03 Nhập linh kiện/Putaway   Chị Phương                     NV kho được phân quyền                        Admin/Tech                          Lãnh đạo

  BP-WMS-04 Xuất sản phẩm            Chị Phương                     NV kho được phân quyền                        Admin/Tech                          Lãnh đạo

  BP-WMS-05 Xuất linh kiện           Chị Phương                     NV kho được phân quyền                        Admin/Tech                          Lãnh đạo

  BP-WMS-06 Tra cứu/Đối soát         Chị Phương                     Admin hoặc NV kho được phân quyền             Tech/QA                             Lãnh đạo theo quyền

  BP-WMS-07 Kiểm kê/Adjustment       Chị Phương                     NV kho đếm; Chị Phương/người ủy quyền Apply   Admin/QA                            Lãnh đạo

  BP-WMS-08/09 Bảo hành              Chị Phương                     NV bảo hành/người được giao                   Admin; NV kho khi handoff           Lãnh đạo theo báo cáo

  BP-WMS-10 Reversal/Correction      Chị Phương                     Người có inventory.reversal.post              NV kho cung cấp evidence; Tech/QA   Lãnh đạo

  BP-WMS-11 Publish App PV           Chị Phương                     Admin hệ thống                                Tech/FE                             Lãnh đạo; App PV user

  BP-WMS-12 Báo cáo                  Chị Phương                     Hệ thống/Admin vận hành report                Tech/QA                             Lãnh đạo/người có quyền xem

  BP-WMS-13 Migration/Opening        Chị Phương – Data Owner        BA/Tech import; QA đối soát                   PM/PO                               Các bên Go/No-Go
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

#### 4.3. Quy tắc Process Owner, Backup và Ủy quyền

Process Owner/Data Owner: Chị Phương – Quản lý kho Hoa Nam. Chị Phương là Accountable duy nhất đối với baseline nghiệp vụ kho, dữ liệu đầu vào, kết quả đối soát và quyết định vận hành.

Backup: NOT DESIGNATED – authorization required when needed. Người thay thế chỉ được thực hiện action sau khi Hoa Nam ủy quyền và hệ thống cấp đúng role/permission.

Executor được xác định theo vai trò, không theo cá nhân cố định. Các action nhạy cảm như Post, Apply Adjustment và Reversal chỉ được thực hiện khi có action-level permission; backend phải enforce.

R = Responsible; A = Accountable; C = Consulted; I/V = Informed/View. Mỗi process có đúng một A là Chị Phương. Người thực hiện được xác định theo role/permission; ủy quyền không chuyển trách nhiệm Accountable và phải có audit.

## 5. QUY TRÌNH NGHIỆP VỤ TO-BE CHI TIẾT

Các quy trình dưới đây là nội dung có thể dùng để formal review. Mỗi quy trình được mô tả theo mục tiêu, trigger, actor, đầu vào/đầu ra, luồng chính, hậu điều kiện, ngoại lệ và nguồn truy vết.

*BPMN notation: user task = xanh dương; system task = xanh lá; physical task = vàng; document/evidence = biểu tượng tờ giấy; handoff/message = nét tím đứt; gateway = hình thoi.*

*Hình 2. BPMN 2.0/Swimlane – Nhập kho và Partial Receipt*

`<img src="media/image4.png" alt="" style="width:6.25in;height:2.7741633858267716in" />`{=html}

*Hình 3. BPMN 2.0/Swimlane – Xuất kho và Partial Issue*

`<img src="media/image5.png" alt="" style="width:6.25in;height:2.7741633858267716in" />`{=html}

*Hình 4. BPMN 2.0/Swimlane – Kiểm kê Snapshot, Reconciliation và Adjustment*

`<img src="media/image6.png" alt="" style="width:6.25in;height:2.7741633858267716in" />`{=html}

*Hình 5. BPMN 2.0/Swimlane – Bảo hành có mã, TEMP_ONLY và Privacy Control*

`<img src="media/image7.png" alt="" style="width:6.25in;height:2.7690168416447944in" />`{=html}

*Hình 6. BPMN 2.0/Swimlane – Putaway Linh kiện và Location Hint*

`<img src="media/image8.png" alt="" style="width:6.25in;height:2.7741633858267716in" />`{=html}

*Hình 7. BPMN 2.0/Swimlane – Reconciliation Ledger, Balance và Subledger*

`<img src="media/image9.png" alt="" style="width:6.25in;height:2.7834765966754156in" />`{=html}

*Hình 8. BPMN 2.0/Swimlane – Reversal/Correction Append-only*

`<img src="media/image10.png" alt="" style="width:6.25in;height:2.7741633858267716in" />`{=html}

*Hình 9. BPMN 2.0/Swimlane – App PV Publish, Cache Freshness và Degraded Behavior*

`<img src="media/image11.png" alt="" style="width:6.25in;height:2.770044838145232in" />`{=html}

*Hình 10. BPMN 2.0/Swimlane – Báo cáo, Export và Data Quality Warning*

`<img src="media/image12.png" alt="" style="width:6.25in;height:2.781402012248469in" />`{=html}

*Hình 11. BPMN 2.0/Swimlane – Migration, Dry-run, Reconciliation và Rollback Gate*

`<img src="media/image13.png" alt="" style="width:6.25in;height:2.7845155293088366in" />`{=html}

  -----------------------------------------------------------------------------------------------------------------------------
  **Thuộc tính**    **Nội dung**
  ----------------- -----------------------------------------------------------------------------------------------------------
  Mục tiêu          Tạo nền dữ liệu chuẩn để mọi giao dịch scan, nhập, xuất, kiểm kê, bảo hành và App PV dùng cùng định danh.

  Trigger           Có SKU/sản phẩm/linh kiện/đại lý/mã vật lý mới hoặc cần cập nhật/ngừng hoạt động.

  Actor chính       Admin, Quản lý kho; NV kho/NV bảo hành ở quyền xem hoặc tạo nhanh theo phân quyền.

  Đầu vào           Product master data, legacy SKU, thông tin brand/category/model/spec, code_value/hardware_uid, Agency.

  Tiền điều kiện    Người dùng có quyền master data; dữ liệu bắt buộc và quy tắc unique đã được xác định.

  Đầu ra/Kết thúc   SKU/Agency/Physical_Code/Container active; alias mã cũ; dữ liệu sẵn sàng cho giao dịch.
  -----------------------------------------------------------------------------------------------------------------------------

### Luồng TO-BE chi tiết

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Bước**   **Actor**      **Hoạt động nghiệp vụ**                                                            **Xử lý/Kết quả hệ thống**                                                                 **Rule/ngoại lệ liên quan**
  ---------- -------------- ---------------------------------------------------------------------------------- ------------------------------------------------------------------------------------------ --------------------------------
  01         Admin/QL kho   Tạo hoặc cập nhật category, brand, model, đơn vị tính, defect catalog và Agency.   Validate code unique, trạng thái ACTIVE/INACTIVE; Agency không sinh Warehouse/Balance.     WM-SCOPE-002; BR-AGY-01

  02         Admin/QL kho   Tạo SKU PRODUCT hoặc COMPONENT theo format chuẩn.                                  Validate sku_code unique; lưu sku_type, is_serial_tracked, unit và thuộc tính phân biệt.   BR-SKU-01..10

  03         Admin/QL kho   Khai báo alias cho mã SKU cũ nếu migration.                                        Alias unique theo source; không dùng alias làm khóa tồn.                                   REQ-SRS-021

  04         Admin/QL kho   Tạo Physical_Code cho ITEM/CARTON/BOX/TRAY.                                        code_value unique; ITEM bắt buộc carrier chip; container carrier linh hoạt.                DR-CARRIER-001; BR-CODE-01..07

  05         Hệ thống       Khóa sửa sku_code/serial mode khi đã phát sinh stock/movement.                     Trả business rule violation nếu cố thay đổi thuộc tính ảnh hưởng dữ liệu vận hành.         BR-SKU-10; AC-SRS-002

  06         Admin/QL kho   Publish/unpublish hồ sơ sản phẩm cho App PV.                                       Chỉ SKU active mới publish; lưu profile, ảnh, tags, industries.                            REQ-SRS-020; PV-VIS-001
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### Hậu điều kiện và kiểm soát

-   Master data không tự tạo Inventory_Movement.

-   Mỗi code_value chỉ map một đối tượng nghiệp vụ tại một thời điểm.

-   Agency chỉ là nơi nhận, không phải kho.

### Ngoại lệ chính

  ------------------------------------------------------------------------------------------------------------------
  **Tình huống**                **Cách xử lý bắt buộc**
  ----------------------------- ------------------------------------------------------------------------------------
  SKU trùng mã                  Chặn tạo; yêu cầu dùng mã khác hoặc cập nhật bản ghi hiện có.

  ITEM thiếu chip mapping       Không cho dùng trong core flow cần item identification.

  Carrier container chưa chốt   Cho phép cấu hình QR/BARCODE/RFID/NFC/MANUAL theo decision; không block core flow.

  Mã cũ trùng alias             Đưa vào error list để BA/QL kho xử lý trước commit migration.
  ------------------------------------------------------------------------------------------------------------------

## 5.2. BP-WMS-02 – Nhập kho sản phẩm/máy theo thùng

  -------------------------------------------------------------------------------------------------------------
  **Thuộc tính**    **Nội dung**
  ----------------- -------------------------------------------------------------------------------------------
  Mục tiêu          Ghi nhận máy vào kho tổng theo carton/item, hỗ trợ partial receipt và không tăng tồn lặp.

  Trigger           Hàng sản phẩm/máy được giao đến kho tổng.

  Actor chính       NV kho (R), QL kho/Admin (A theo quyền).

  Đầu vào           Phiếu/nguồn nhập, SKU dự kiến, carton code, item code/chip, expected_qty.

  Tiền điều kiện    Kho tổng active; SKU PRODUCT active; mã không trùng; quyền inbound.create/post.

  Đầu ra/Kết thúc   Item IN_STOCK; carton IN_STOCK/PARTIAL; Balance tăng; movement RECEIPT_POSTED.
  -------------------------------------------------------------------------------------------------------------

### Luồng TO-BE chi tiết

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Bước**   **Actor**       **Hoạt động nghiệp vụ**                                  **Xử lý/Kết quả hệ thống**                                                               **Rule/ngoại lệ liên quan**
  ---------- --------------- -------------------------------------------------------- ---------------------------------------------------------------------------------------- ------------------------------
  01         NV kho          Tạo phiếu nhập và dòng expected_qty theo SKU.            Tạo trạng thái DRAFT; chưa đổi tồn.                                                      BR-IN-01

  02         NV kho          Scan/nhập mã carton hoặc dùng Demo máy quét.             Tạo/nhận diện CARTON; ghi Scan_Batch/Scan_Event.                                         BR-SCAN-01..02; BR-CON-01

  03         NV kho          Scan các mã item trong carton; có thể nhận cùng batch.   Phân loại ITEM/CARTON/UNKNOWN; map item vào carton; cảnh báo trùng/lạ/khác SKU.          BR-SCAN-02; BR-CON-01/08

  04         NV kho          Kiểm tra số thực nhận và chọn subset cần post.           Tính remaining_qty; không cho vượt expected/remaining trong MVP.                         DEC-PARTIAL-001

  05         NV kho/QL kho   Post Receipt với khóa yêu cầu nghiệp vụ duy nhất.        Trong một transaction: tạo posting event/movement, tăng Balance, cập nhật Item/Carton.   REQ-SRS-007/008; DEC-INV-001

  06         Hệ thống        Xác định trạng thái phiếu.                               PARTIALLY_POSTED nếu còn remainder; POSTED nếu đủ.                                       DEC-PARTIAL-001

  07         QL kho/Admin    Nếu không nhận tiếp, Close remainder kèm lý do.          Phiếu CLOSED; cancelled_remainder_qty = remaining; không đổi tồn.                        REQ-SRS-007
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### Hậu điều kiện và kiểm soát

-   DRAFT/SCANNING không làm tăng tồn.

-   Retry cùng khóa yêu cầu nghiệp vụ duy nhất/cùng payload trả kết quả cũ.

-   Carton không được cộng thêm như một đơn vị tồn ngoài item.

### Ngoại lệ chính

  -------------------------------------------------------------------------------------------------------------------------------
  **Tình huống**                **Cách xử lý bắt buộc**
  ----------------------------- -------------------------------------------------------------------------------------------------
  Mã item trùng                 Chặn; hiển thị item đang thuộc phiếu/kho/trạng thái nào.

  Item khác SKU trong carton    Chặn hoặc yêu cầu tách carton.

  Over-receipt                  Không cho phép trong MVP; điều chỉnh expected hoặc tạo chứng từ khác theo quyền.

  Post lỗi giữa chừng           Rollback document/movement/balance/subledger trong cùng transaction.

  Cùng key nhưng payload khác   Chặn do business request key đã dùng với nội dung khác; không đổi tồn; technical code theo SRS.
  -------------------------------------------------------------------------------------------------------------------------------

## 5.3. BP-WMS-03 – Nhập kho linh kiện và Putaway

  ------------------------------------------------------------------------------------------------------------------------------
  **Thuộc tính**    **Nội dung**
  ----------------- ------------------------------------------------------------------------------------------------------------
  Mục tiêu          Nhập linh kiện theo serial hoặc quantity, tăng tồn tại Post Receipt và gán khay sau nhập mà không đổi tồn.

  Trigger           Linh kiện/phụ tùng được giao đến kho tổng.

  Actor chính       NV kho (R), QL kho/Admin (A).

  Đầu vào           SKU COMPONENT, is_serial_tracked, box code, serial hoặc quantity, expected_qty, tray code khi putaway.

  Tiền điều kiện    SKU active; box một SKU; mã/quantity hợp lệ.

  Đầu ra/Kết thúc   Balance linh kiện tăng; box RECEIVED_PENDING_PUTAWAY/STORED; mapping Box–Tray.
  ------------------------------------------------------------------------------------------------------------------------------

### Luồng TO-BE chi tiết

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Bước**   **Actor**       **Hoạt động nghiệp vụ**                                                        **Xử lý/Kết quả hệ thống**                                                              **Rule/ngoại lệ liên quan**
  ---------- --------------- ------------------------------------------------------------------------------ --------------------------------------------------------------------------------------- -----------------------------
  01         NV kho          Tạo phiếu nhập linh kiện và dòng expected_qty.                                 DRAFT; chưa đổi tồn.                                                                    BR-IN-01

  02         NV kho          Scan box và serial nếu is_serial_tracked=true; hoặc nhập quantity nếu false.   Validate một box chỉ chứa một SKU; SCANNING.                                            BR-COMP-01; BR-CON-02

  03         NV kho/QL kho   Post Receipt với khóa yêu cầu nghiệp vụ duy nhất.                              Tăng Balance; item serial → IN_STOCK hoặc box quantity tăng; movement RECEIPT_POSTED.   REQ-SRS-007/008

  04         Hệ thống        Nếu chưa có tray thực tế, gán trạng thái Chờ xếp khay.                         Box = RECEIVED_PENDING_PUTAWAY; quantity vẫn tính Available.                            BR-PTW-01

  05         NV kho          Khi đặt hàng vào khay, tạo Putaway task và scan tray + box.                    Tạo DRAFT putaway; validate tray active và box hợp lệ.                                  BR-PTW-02

  06         NV kho/QL kho   Confirm Putaway.                                                               Cập nhật tray mapping/location hint; box → STORED; không sinh movement.                 REQ-SRS-009
  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### Hậu điều kiện và kiểm soát

-   Post Receipt là điểm duy nhất tăng tồn trong quy trình này.

-   Putaway không đổi available_qty và không sinh Inventory_Movement.

-   Linh kiện quantity-only không yêu cầu serial.

### Ngoại lệ chính

  --------------------------------------------------------------------------------------------------------------------------------
  **Tình huống**                                          **Cách xử lý bắt buộc**
  ------------------------------------------------------- ------------------------------------------------------------------------
  Scan box nhưng không nhập quantity cho SKU non-serial   Không đủ dữ liệu; yêu cầu quantity thực nhận.

  Box chứa SKU khác                                       Chặn và yêu cầu box khác.

  Tray inactive/không tồn tại                             Không confirm putaway.

  Putaway lặp                                             Cập nhật mapping theo transaction/audit; không được tạo biến động tồn.

  Quantity vượt expected/remaining                        Chặn theo partial receipt rule.
  --------------------------------------------------------------------------------------------------------------------------------

## 5.4. BP-WMS-04 – Xuất kho sản phẩm

  -------------------------------------------------------------------------------------------------------------------
  **Thuộc tính**    **Nội dung**
  ----------------- -------------------------------------------------------------------------------------------------
  Mục tiêu          Xuất nguyên carton hoặc từng item từ kho tổng cho Agency/đối tượng nhận, giảm tồn đúng một lần.

  Trigger           Có yêu cầu xuất hàng hợp lệ.

  Actor chính       NV kho (R), QL kho/Admin (A).

  Đầu vào           Agency/receiver, SKU/planned_qty, carton/item code.

  Tiền điều kiện    Agency active; item IN_STOCK; đủ available; quyền outbound.create/post.

  Đầu ra/Kết thúc   Item ISSUED; carton PARTIAL/ISSUED; Balance giảm; ISSUE_POSTED; agency_id lưu trên chứng từ.
  -------------------------------------------------------------------------------------------------------------------

### Luồng TO-BE chi tiết

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Bước**   **Actor**       **Hoạt động nghiệp vụ**                                      **Xử lý/Kết quả hệ thống**                                               **Rule/ngoại lệ liên quan**
  ---------- --------------- ------------------------------------------------------------ ------------------------------------------------------------------------ -----------------------------
  01         NV kho          Tạo phiếu xuất, chọn Agency/đối tượng nhận và planned_qty.   DRAFT; không tạo tồn agency.                                             WM-SCOPE-002/003

  02         NV kho          Xuất nguyên thùng: scan carton.                              Tải các item còn IN_STOCK trong carton vào phiếu.                        BR-CON-05

  03         NV kho          Hoặc xuất lẻ: scan từng item.                                Validate item thuộc kho tổng, IN_STOCK và chưa nằm trong posting khác.   BR-OUT-02

  04         NV kho          Kiểm tra subset sẽ xuất.                                     Không vượt planned remaining hoặc available.                             DEC-PARTIAL-001

  05         NV kho/QL kho   Post Issue với khóa yêu cầu nghiệp vụ duy nhất.              Lock SKU/item/carton; tạo ISSUE_POSTED; giảm Balance; item → ISSUED.     REQ-SRS-011/012

  06         Hệ thống        Xác định PARTIALLY_POSTED hoặc POSTED.                       Carton PARTIAL nếu còn item IN_STOCK; ISSUED nếu hết.                    State model

  07         QL kho/Admin    Close remainder nếu không xuất tiếp.                         CLOSED kèm reason; không đổi tồn.                                        REQ-SRS-011
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### Hậu điều kiện và kiểm soát

-   Agency chỉ là chiều nơi nhận; không tạo movement tăng hoặc balance tại agency.

-   Concurrency phải bảo đảm chỉ một request commit trên cùng item/available.

-   App PV chỉ đọc balance sau khi post thành công.

### Ngoại lệ chính

  -----------------------------------------------------------------------------------------------------------------------
  **Tình huống**                                         **Cách xử lý bắt buộc**
  ------------------------------------------------------ ----------------------------------------------------------------
  Carton đã xuất hết                                     Báo không còn item khả dụng.

  Item đã ISSUED/IN_WARRANTY/RETURNED_TO_CUSTOMER/LOST   Chặn xuất.

  Thiếu available do request đồng thời                   Một request thành công; request còn lại conflict/insufficient.

  Retry post                                             Trả kết quả cũ; không trừ tồn lần hai.

  Hàng quay lại kho                                      Không sửa phiếu gốc; dùng Reverse Issue/Return confirmation.
  -----------------------------------------------------------------------------------------------------------------------

## 5.5. BP-WMS-05 – Xuất kho linh kiện

  ---------------------------------------------------------------------------------------------------------
  **Thuộc tính**    **Nội dung**
  ----------------- ---------------------------------------------------------------------------------------
  Mục tiêu          Xuất linh kiện theo serial hoặc quantity từ box; không xuất tray và không cho âm kho.

  Trigger           Có yêu cầu xuất linh kiện.

  Actor chính       NV kho (R), QL kho/Admin (A).

  Đầu vào           Agency/receiver, SKU component, serial hoặc box + quantity.

  Tiền điều kiện    SKU active; serial/quantity khả dụng; box tồn tại.

  Đầu ra/Kết thúc   Serial ISSUED hoặc box quantity giảm; Balance giảm; ISSUE_POSTED.
  ---------------------------------------------------------------------------------------------------------

### Luồng TO-BE chi tiết

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Bước**   **Actor**       **Hoạt động nghiệp vụ**                                                               **Xử lý/Kết quả hệ thống**                                  **Rule/ngoại lệ liên quan**
  ---------- --------------- ------------------------------------------------------------------------------------- ----------------------------------------------------------- -----------------------------
  01         NV kho          Tạo/thêm dòng linh kiện trên phiếu xuất.                                              DRAFT/SCANNING; chưa đổi tồn.                               BR-OUT-01

  02         Hệ thống        Hiển thị box/tray gợi ý để tìm hàng.                                                  Tra cứu mapping; tray chỉ là locator.                       BR-CON-03/06

  03         NV kho          Nếu serial-tracked, scan từng serial; nếu quantity-only, scan box và nhập số lượng.   Validate mode theo SKU.                                     BR-COMP-01

  04         Hệ thống        Kiểm tra quantity còn trong box và total available.                                   Chặn vượt box_qty/remaining/available.                      BR-OUT-03

  05         NV kho/QL kho   Post Issue idempotent.                                                                Giảm Balance; cập nhật serial/box; movement ISSUE_POSTED.   REQ-SRS-012

  06         Hệ thống        Cập nhật box PARTIAL/EMPTY và phiếu PARTIALLY_POSTED/POSTED.                          Không thay đổi tray như một đơn vị tồn.                     BR-CON-04/06
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### Hậu điều kiện và kiểm soát

-   Không có nghiệp vụ xuất tray.

-   Tồn quantity-only là tổng quantity khả dụng trong box đủ điều kiện.

-   Putaway/location không thay thế kiểm tra tồn ở commit time.

### Ngoại lệ chính

  ---------------------------------------------------------------------------------------------------
  **Tình huống**                  **Cách xử lý bắt buộc**
  ------------------------------- -------------------------------------------------------------------
  Xuất quantity vượt box          Chặn và yêu cầu nhập lại.

  Scan tray như hàng xuất         Chặn; chỉ dùng tray để liệt kê box.

  Serial không IN_STOCK           Chặn.

  Hai request lấy cùng quantity   Lock/optimistic version; không cho âm.

  Box quantity và Balance lệch    Tạo reconciliation incident; block sign-off, không tự sửa ledger.
  ---------------------------------------------------------------------------------------------------

## 5.6. BP-WMS-06 – Tra cứu tồn và đối soát

  -----------------------------------------------------------------------------------------------------------
  **Thuộc tính**    **Nội dung**
  ----------------- -----------------------------------------------------------------------------------------
  Mục tiêu          Cung cấp tồn hiện tại đáng tin cậy và phát hiện lệch giữa ledger, balance và subledger.

  Trigger           Người dùng tra cứu tồn, chạy báo cáo hoặc job/check phát hiện divergence.

  Actor chính       QL kho/Admin (R/A), NV kho/Lãnh đạo (View theo quyền).

  Đầu vào           Inventory_Movement, Inventory_Balance, Item_Instance, Box quantity, filter SKU/status.

  Tiền điều kiện    Các posting đã commit; người dùng có quyền report/inventory view.

  Đầu ra/Kết thúc   Tồn theo SKU/trạng thái; location hint; reconciliation incident nếu lệch.
  -----------------------------------------------------------------------------------------------------------

### Luồng TO-BE chi tiết

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Bước**   **Actor**      **Hoạt động nghiệp vụ**                              **Xử lý/Kết quả hệ thống**                                                                          **Rule/ngoại lệ liên quan**
  ---------- -------------- ---------------------------------------------------- --------------------------------------------------------------------------------------------------- -----------------------------
  01         Người dùng     Tra cứu SKU/item/carton/box/tray hoặc báo cáo tồn.   Hệ thống đọc Balance và subledger phù hợp; không cộng container độc lập.                            DEC-INV-001

  02         Hệ thống       Tính/hiển thị available_qty.                         Serial: count IN_STOCK; quantity: sum box eligible; warranty/issued không tính.                     BR-WAR-11; §13.2 BRD

  03         Hệ thống/Job   Đối soát ledger → balance.                           Opening + receipt - issue ± adjustment/reversal phải bằng Balance.                                  SRS §7.2

  04         Hệ thống/Job   Đối soát serial/quantity subledger → balance.        Tổng item/box đủ điều kiện phải khớp available.                                                     DEC-INV-001

  05         QL kho/Admin   Xử lý divergence.                                    Tạo incident, điều tra source; dùng reversal/adjustment có audit, không update/delete ledger gốc.   BR-REV-01/04
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### Hậu điều kiện và kiểm soát

-   Inventory_Movement là authoritative ledger.

-   Balance là projection phục vụ đọc nhanh.

-   Divergence phải có incident và evidence resolution.

### Ngoại lệ chính

  ------------------------------------------------------------------------------------------------------------------------------
  **Tình huống**                         **Cách xử lý bắt buộc**
  -------------------------------------- ---------------------------------------------------------------------------------------
  Balance âm                             Block posting liên quan; điều tra concurrency/data.

  Serial count lệch Balance              Tạo incident; không auto đổi item/ledger.

  Container mapping sai nhưng tồn đúng   Sửa mapping qua putaway/correction có audit; không sinh movement.

  Người dùng không có scope              Từ chối do ngoài quyền/phạm vi; cách biểu diễn kỹ thuật theo SRS/permission contract.

  Dữ liệu App PV cache                   Hiển thị/log last_inventory_sync_at nếu có cache.
  ------------------------------------------------------------------------------------------------------------------------------

## 5.7. BP-WMS-07 – Kiểm kê và điều chỉnh

  -------------------------------------------------------------------------------------------------------------------------------
  **Thuộc tính**    **Nội dung**
  ----------------- -------------------------------------------------------------------------------------------------------------
  Mục tiêu          Kiểm kê không khóa toàn hệ thống nhưng vẫn tính đúng chênh lệch tại thời điểm đếm và chống apply lặp/stale.

  Trigger           Kế hoạch kiểm kê toàn kho hoặc theo phạm vi SKU/nhóm.

  Actor chính       NV kho (count), QL kho/Admin (review/apply).

  Đầu vào           Phạm vi kiểm kê, snapshot, scan/count, movement sau snapshot, reason/approval line.

  Tiền điều kiện    Kho/SKU active; quyền stocktake; snapshot tạo thành công.

  Đầu ra/Kết thúc   Session APPLIED/CANCELLED; ADJUST_IN/ADJUST_OUT cho line approved; evidence variance.
  -------------------------------------------------------------------------------------------------------------------------------

### Luồng TO-BE chi tiết

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Bước**   **Actor**       **Hoạt động nghiệp vụ**                                             **Xử lý/Kết quả hệ thống**                                                        **Rule/ngoại lệ liên quan**
  ---------- --------------- ------------------------------------------------------------------- --------------------------------------------------------------------------------- -----------------------------
  01         QL kho/NV kho   Tạo Stocktake Session và phạm vi.                                   Cùng transaction ghi stocktake_started_at, session_version và snapshot.           BR-COUNT-08

  02         NV kho          Start Counting; scan item/carton/box/tray hoặc nhập quantity.       Ghi actual và count_cutoff_at; scan/count không đổi tồn.                          BR-COUNT-03..07

  03         Hệ thống        Tập hợp net movement đã commit từ snapshot đến cutoff.              Không tính transaction chưa commit tại cutoff; movement sau cutoff tạo warning.   BR-COUNT-09/10

  04         Hệ thống        Tính expected_at_count và variance_to_apply.                        expected = snapshot + net movement; variance = actual - expected.                 AC-P0-STK-001

  05         QL kho          Review actual/snapshot/expected/movement/variance; ack warning.     Session REVIEWING; stale data phải refresh/recount.                               BR-COUNT-10/13

  06         QL kho          Approve hoặc Waive từng variance line.                              Chỉ APPROVED được apply; WAIVED phải có reason.                                   BR-COUNT-12

  07         QL kho/Admin    Apply Adjustment với khóa yêu cầu nghiệp vụ duy nhất và If-Match.   Atomic update Balance/subledger/movement; line → APPLIED.                         REQ-SRS-015

  08         Hệ thống        Đóng session.                                                       APPLIED khi mọi line APPLIED hoặc WAIVED; retry không tạo duplicate adjustment.   BR-COUNT-11/12
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### Hậu điều kiện và kiểm soát

-   Không khóa toàn kho ở mức hệ thống.

-   Scan tray không thay thế đếm quantity trong box.

-   Ví dụ chuẩn: snapshot 100, issue -10, actual 90 → expected 90, variance 0.

### Ngoại lệ chính

  --------------------------------------------------------------------------------------------------------------------------------
  **Tình huống**                   **Cách xử lý bắt buộc**
  -------------------------------- -----------------------------------------------------------------------------------------------
  Scan trùng serial                Cảnh báo và không tính trùng.

  Movement phát sinh trong phiên   Hiển thị warning và bắt buộc ack trước apply.

  Stale session version            Chặn do session/review stale; refresh và review lại trước Apply; technical response theo SRS.

  Partial approval                 Chỉ line APPROVED sinh movement; line còn PENDING không được đóng session.

  Apply retry                      Trả response cũ; không sinh ADJUST lần hai.
  --------------------------------------------------------------------------------------------------------------------------------

## 5.8. BP-WMS-08 – Bảo hành máy có mã

  -----------------------------------------------------------------------------------------------------------------
  **Thuộc tính**    **Nội dung**
  ----------------- -----------------------------------------------------------------------------------------------
  Mục tiêu          Theo dõi tiếp nhận–xử lý–trả máy có item_code mà không đưa máy vào tồn bán.

  Trigger           Khách/đại lý gửi máy có mã hợp lệ để bảo hành/sửa chữa.

  Actor chính       NV bảo hành (R), QL kho/Admin (C/A theo quyền).

  Đầu vào           item_code, customer name/phone, reported defect, phụ kiện/ảnh, ngày nhận.

  Tiền điều kiện    Item ISSUED hoặc RETURNED_TO_CUSTOMER; không có active warranty case khác.

  Đầu ra/Kết thúc   Warranty Case RETURNED/CANCELLED; item IN_WARRANTY rồi RETURNED_TO_CUSTOMER; Warranty Events.
  -----------------------------------------------------------------------------------------------------------------

### Luồng TO-BE chi tiết

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Bước**   **Actor**     **Hoạt động nghiệp vụ**                                     **Xử lý/Kết quả hệ thống**                                      **Rule/ngoại lệ liên quan**
  ---------- ------------- ----------------------------------------------------------- --------------------------------------------------------------- -----------------------------
  01         NV bảo hành   Scan/nhập item_code.                                        Tra cứu item; validate state và active case.                    BR-WAR-10..13

  02         NV bảo hành   Nhập khách/SĐT, bệnh, tình trạng ban đầu và phụ kiện/ảnh.   Validate field bắt buộc; tạo case RECEIVED.                     BR-WAR-02/04

  03         Hệ thống      Chuyển item → IN_WARRANTY.                                  Không sinh Inventory_Movement; available không tăng.            BR-WAR-01/11

  04         NV bảo hành   Cập nhật CHECKING → REPAIRING → COMPLETED.                  Ghi Warranty_Event và kết quả/confirmed defect.                 State model

  05         NV bảo hành   Return to customer.                                         Case → RETURNED; item → RETURNED_TO_CUSTOMER; không IN_STOCK.   BR-WAR-05/13
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### Hậu điều kiện và kiểm soát

-   Một item tối đa một active case.

-   ISSUED/IN_WARRANTY/RETURNED_TO_CUSTOMER không tính Available.

-   Có thể mở chu kỳ bảo hành mới sau RETURNED nếu không có active case.

### Ngoại lệ chính

  -----------------------------------------------------------------------------------------------------------------------
  **Tình huống**                       **Cách xử lý bắt buộc**
  ------------------------------------ ----------------------------------------------------------------------------------
  Item không tồn tại                   Chuyển sang quy trình TEMP_ONLY nếu không thể định danh.

  Item đang IN_STOCK                   Không dùng warranty flow để lấy hàng kho; cần rule/nghiệp vụ riêng.

  Active case đã tồn tại               Chặn vì item đã có active warranty case; không tạo case thứ hai.

  Chuyển RETURNED khi chưa COMPLETED   Chặn.

  Muốn đưa lại hàng vào tồn bán        Không tự động; phải có nghiệp vụ kiểm định/nhập/điều chỉnh riêng được phê duyệt.
  -----------------------------------------------------------------------------------------------------------------------

## 5.9. BP-WMS-09 – Bảo hành TEMP_ONLY

  --------------------------------------------------------------------------------------------------------------------------
  **Thuộc tính**    **Nội dung**
  ----------------- --------------------------------------------------------------------------------------------------------
  Mục tiêu          Quản lý máy mất/hỏng mã hoặc không thể truy vết bằng mã tạm, tránh liên kết sai với máy đã bán.

  Trigger           Khách gửi máy nhưng không có/không đọc được/không nhớ item_code.

  Actor chính       NV bảo hành (R), QL kho/Admin (C).

  Đầu vào           Customer, phone, reported defect, missing_code_reason, manual description, ảnh nếu có, model nếu biết.

  Tiền điều kiện    Không thể xác nhận item gốc một cách đáng tin cậy.

  Đầu ra/Kết thúc   Warranty Case TEMP_ONLY với temporary_item_code; sales_trace_status=NOT_TRACEABLE.
  --------------------------------------------------------------------------------------------------------------------------

### Luồng TO-BE chi tiết

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Bước**   **Actor**     **Hoạt động nghiệp vụ**                                       **Xử lý/Kết quả hệ thống**                                                           **Rule/ngoại lệ liên quan**
  ---------- ------------- ------------------------------------------------------------- ------------------------------------------------------------------------------------ -----------------------------
  01         NV bảo hành   Chọn “Tạo case không có mã/TEMP_ONLY”.                        Bật bộ field bắt buộc cho case không định danh.                                      BR-WAR-07

  02         NV bảo hành   Nhập khách/SĐT, bệnh, lý do mất mã, mô tả nhận diện và ảnh.   Model có thể UNKNOWN_MODEL; không suy đoán item.                                     BR-WAR-08/09

  03         Hệ thống      Sinh temporary_item_code và warranty_case_code.               item_code null; identification_status=TEMP_ONLY; sales_trace_status=NOT_TRACEABLE.   REQ-SRS-016

  04         NV bảo hành   Xử lý CHECKING → REPAIRING → COMPLETED.                       Ghi event theo mã tạm.                                                               State model

  05         NV bảo hành   Đối chiếu mã tạm + khách/SĐT + mô tả/ảnh và trả máy.          Case → RETURNED; không cập nhật Item_Instance hoặc tồn.                              BR-WAR-08
  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### Hậu điều kiện và kiểm soát

-   TEMP_ONLY không gắn Item_Instance và không xác nhận lịch sử bán hàng.

-   Không làm tăng/giảm sellable stock.

-   Mã tạm chỉ dùng nội bộ cho ca bảo hành.

### Ngoại lệ chính

  ---------------------------------------------------------------------------------------------------------------------------------
  **Tình huống**                      **Cách xử lý bắt buộc**
  ----------------------------------- ---------------------------------------------------------------------------------------------
  Nhân viên cố link theo phỏng đoán   Không cho auto-link; giữ NOT_TRACEABLE.

  Thiếu missing_code_reason/mô tả     Không cho lưu.

  Khách mất mã tạm                    Tra cứu bằng case code/SĐT/mô tả/ảnh theo quyền.

  Sau này tìm lại item gốc            Chỉ link bằng quy trình correction có quyền/audit và bằng chứng; không sửa lịch sử âm thầm.
  ---------------------------------------------------------------------------------------------------------------------------------

## 5.10. BP-WMS-10 – Reversal và Correction sau Post

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Thuộc tính**    **Nội dung**
  ----------------- --------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  Mục tiêu          Sửa sai giao dịch đã post bằng chứng từ append-only, giữ nguyên lịch sử và chống đảo lặp.

  Trigger           Phát hiện Post Receipt/Issue sai hoặc hàng đã xuất quay lại kho.

  Actor chính       Chị Phương – QL kho (A); người có inventory.reversal.post (R); NV kho cung cấp evidence.

  Đầu vào           Original document/movement, reversal type, quantity, reason, condition hàng, khóa yêu cầu nghiệp vụ duy nhất.

  Tiền điều kiện    Giao dịch gốc POSTED; còn reversible_qty; quyền hợp lệ; dependency downstream đã được khóa/đảo theo thứ tự; hàng quay lại có evidence vật lý và condition được xác định.

  Đầu ra/Kết thúc   Reversal POSTED append-only; movement ngược; Balance/subledger và report net effect cập nhật; correction_chain/original reference đầy đủ.
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### Luồng TO-BE chi tiết

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Bước**       **Actor**                 **Hoạt động nghiệp vụ**                                              **Xử lý/Kết quả hệ thống**                                                                                                                  **Rule/ngoại lệ liên quan**
  -------------- ------------------------- -------------------------------------------------------------------- ------------------------------------------------------------------------------------------------------------------------------------------- -----------------------------
  01             Người có quyền reversal   Tạo request, chọn movement gốc, reason, line/qty và reversal type.   DRAFT; tạo correction_chain_id; không sửa/xóa document/movement gốc.                                                                        BR-REV-01/02

  02             Hệ thống                  Tính reversible_qty và khóa chain.                                   reversible_qty = original_qty − tổng qty reversal POSTED; chặn vượt qty và concurrent duplicate.                                            BR-REV-04/05

  03             Hệ thống/QL kho           Kiểm tra downstream dependency.                                      Reverse Receipt bị chặn nếu item/qty đã Issue/Warranty/Adjustment; phải đảo dependency theo thứ tự ngược hoặc mở correction chain hợp lệ.   BR-REV-06/07

  04             NV kho + QL kho           Xác nhận handoff vật lý và condition với Reverse Issue.              PENDING_RETURN cho đến khi hàng về; SELLABLE mới cộng available; DEFECTIVE/NON_SELLABLE vào condition/status riêng.                         BR-REV-03/08

  05             Người có quyền reversal   Confirm/Post với business request key duy nhất.                      Atomic: movement ngược, Balance/subledger/item state, reversal POSTED; rollback toàn bộ nếu một bước lỗi.                                   DEC-INV-001; BR-REV-09

  06             Hệ thống/QL kho           Đối soát và cập nhật báo cáo.                                        Ledger–Balance–Subledger khớp; report hiển thị original, reversal, correction chain và net effect.                                          BR-REV-10/11

  07             QL kho                    Sửa tiếp nếu reversal đã POSTED sai.                                 Không reverse trực tiếp reversal; tạo correction/reversal mới link correction_chain và giữ full audit.                                      BR-REV-12
  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### Hậu điều kiện và kiểm soát: reversal giữ nguyên movement gốc; dependency, reversible_qty, condition và correction_chain được audit; báo cáo trình bày gross movement, reversal và net effect.

-   Không UPDATE/DELETE Inventory_Movement hoặc chứng từ gốc; mọi sửa sai sau Post là append-only.

-   Stocktake/Adjustment không được dùng để che lỗi chứng từ có thể truy nguyên; ưu tiên reversal/correction theo source transaction.

-   Mọi reversal có reason, actor, timestamp, request_id, original reference, correction_chain, physical return evidence và condition khi áp dụng.

### Ngoại lệ chính

  -------------------------------------------------------------------------------------------------------------------------------------------------
  **Tình huống**                                            **Cách xử lý bắt buộc**
  --------------------------------------------------------- ---------------------------------------------------------------------------------------
  Retry cùng key/cùng payload                               Replay kết quả cũ; không sinh movement/stock effect lần hai.

  Cùng key nhưng payload khác                               Chặn request identity conflict; stock effect = 0.

  Partial reversal                                          Chỉ đảo subset hợp lệ; qty không vượt reversible_qty; phần còn lại vẫn truy vết được.

  Reverse Receipt có downstream Issue/Warranty/Adjustment   Chặn; đảo dependency theo thứ tự ngược hoặc xử lý correction chain được phê duyệt.

  Reverse Issue nhưng hàng chưa về                          Giữ PENDING_RETURN; không cộng IN_STOCK/available.

  Hàng về DEFECTIVE/NON_SELLABLE                            Ghi condition/status riêng; không cộng sellable available; report tách condition.

  Reverse-of-reversal                                       Không thao tác trực tiếp trên reversal POSTED; tạo correction mới link chain.

  Stocktake đang Apply/posting chưa hoàn tất                Chặn hoặc chờ transaction hoàn tất; refresh dữ liệu trước review/post.

  Hai request reversal đồng thời                            Lock chain/movement; chỉ một request commit.

  Reconciliation/report mismatch                            Tạo incident; block sign-off; không update/delete ledger gốc.
  -------------------------------------------------------------------------------------------------------------------------------------------------

## 5.11. BP-WMS-11 – Publish và cung cấp tồn cho App PV

  ------------------------------------------------------------------------------------------------------------------
  **Thuộc tính**    **Nội dung**
  ----------------- ------------------------------------------------------------------------------------------------
  Mục tiêu          Cung cấp sản phẩm published và available_qty kho tổng cho kênh Web App read-only.

  Trigger           Admin/QL kho publish sản phẩm hoặc tồn kho thay đổi sau posting.

  Actor chính       Admin/QL kho (publish), App PV/User (read).

  Đầu vào           PV Product Profile, published flag, ngành nghề/tag, Inventory_Balance available_qty.

  Tiền điều kiện    SKU active; profile đầy đủ; người dùng có quyền publish hoặc đọc public.

  Đầu ra/Kết thúc   Danh sách/chi tiết/recommendation; available_qty, Còn hàng/Hết hàng và last_inventory_sync_at.
  ------------------------------------------------------------------------------------------------------------------

### Luồng TO-BE chi tiết

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Bước**   **Actor**      **Hoạt động nghiệp vụ**                                         **Xử lý/Kết quả hệ thống**                                                                                                    **Rule/ngoại lệ liên quan**
  ---------- -------------- --------------------------------------------------------------- ----------------------------------------------------------------------------------------------------------------------------- -------------------------------------------------------
  01         Admin/QL kho   Cấu hình profile, ảnh, thông số, ngành nghề, tags và publish.   Chỉ active SKU mới published.                                                                                                 PV-VIS-001

  02         Người xem      Mở list/search/filter/recommendation.                           Chỉ trả product active + published; không lộ item/container/movement.                                                         REQ-SRS-017/019

  03         Hệ thống       Đọc available_qty từ Balance kho tổng.                          Hiển thị số và trạng thái: \>0 Còn hàng; =0 Hết hàng.                                                                         REQ-SRS-018

  04         Hệ thống       Đồng bộ PV cache/read model sau publish hoặc action đổi tồn.    Freshness target ≤ 60 giây trong điều kiện bình thường; ghi last_inventory_sync_at; App PV không reserve hoặc tạo movement.   BPD-PV-FRESH-001; tham chiếu SRS REQ-SRS-018/BR-PV-14

  05         Người xem      Xem chi tiết và phụ kiện tương thích.                           Read-only; không order/payment/hold/post issue.                                                                               DR-PLAT-001
  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### Hậu điều kiện và kiểm soát

-   App PV không thay đổi tồn.

-   Không hiển thị serial/item/carton/box/tray, movement, phiếu hoặc tồn agency.

-   Recommendation dùng rule cấu hình, không AI trong MVP.

### Ngoại lệ chính

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Tình huống**                                   **Cách xử lý bắt buộc**
  ------------------------------------------------ ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  Sản phẩm inactive/chưa publish                   Không xuất hiện kể cả deep link.

  Balance = 0                                      Hiển thị Hết hàng; không cho giữ/đặt.

  Cache chậm                                       Nếu tuổi dữ liệu \> 5 phút, vẫn cho xem read-only nhưng phải hiển thị “Dữ liệu tồn có thể chậm” và last_inventory_sync_at. Nếu chưa từng sync, hiển thị “Tạm thời chưa có dữ liệu tồn”, không suy đoán số lượng.

  Người dùng cố thực hiện thao tác ghi từ App PV   App PV không cung cấp nghiệp vụ kho/đặt hàng/giữ hàng; từ chối theo permission. Endpoint và technical response thuộc SRS.

  Profile thiếu dữ liệu hiển thị                   Không publish hoặc hiển thị empty/placeholder theo rule UI được duyệt.
  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 5.12. BP-WMS-12 – Báo cáo vận hành

  ------------------------------------------------------------------------------------------------------
  **Thuộc tính**    **Nội dung**
  ----------------- ------------------------------------------------------------------------------------
  Mục tiêu          Cung cấp dữ liệu tồn, XNT, lịch sử, kiểm kê và bảo hành phục vụ vận hành/quản trị.

  Trigger           Người dùng xem/export báo cáo hoặc kỳ báo cáo đến hạn.

  Actor chính       QL kho/Admin/Lãnh đạo; NV kho view nếu được cấp.

  Đầu vào           Movement, Balance, documents, stocktake, warranty, filters.

  Tiền điều kiện    Người dùng có quyền report.view; dữ liệu đã post.

  Đầu ra/Kết thúc   Báo cáo tồn hiện tại, XNT, lịch sử, kiểm kê, bệnh máy; export nếu triển khai.
  ------------------------------------------------------------------------------------------------------

### Luồng TO-BE chi tiết

  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Bước**   **Actor**    **Hoạt động nghiệp vụ**                                                                 **Xử lý/Kết quả hệ thống**                                **Rule/ngoại lệ liên quan**
  ---------- ------------ --------------------------------------------------------------------------------------- --------------------------------------------------------- -----------------------------
  01         Người dùng   Chọn báo cáo và filter thời gian/SKU/category/status/agency nơi nhận.                   Validate quyền và phạm vi.                                Permission matrix

  02         Hệ thống     Tính tồn đầu kỳ/nhập/xuất/adjust/reversal/cuối kỳ từ ledger.                            Không coi agency là kho; filter agency chỉ là nơi nhận.   BR-SCOPE-04

  03         Hệ thống     Hiển thị tồn hiện tại từ Balance kèm location hint.                                     Không double count container.                             BR-CON-04

  04         Hệ thống     Hiển thị kiểm kê: snapshot, expected, actual, variance, movement warning, adjustment.   Trace theo session/line.                                  REQ-SRS-014/015

  05         Hệ thống     Hiển thị bảo hành theo SKU/model/defect/status/date.                                    TEMP_ONLY có thể UNKNOWN_MODEL; không suy đoán item.      REQ-SRS-016

  06         Người dùng   Export Excel/PDF nếu thuộc release.                                                     Giữ filter, thời gian tạo và người xuất.                  Should
  -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### Hậu điều kiện và kiểm soát

-   Báo cáo phải có nguồn dữ liệu/logic nhất quán với ledger.

-   Role Lãnh đạo là read-only.

-   Export là Should, không chặn core flow nếu chưa triển khai.

### Ngoại lệ chính

  ------------------------------------------------------------------------------------------------------------------
  **Tình huống**             **Cách xử lý bắt buộc**
  -------------------------- ---------------------------------------------------------------------------------------
  Kỳ báo cáo có divergence   Hiển thị/cảnh báo incident; không che sai lệch.

  Filter agency              Chỉ thống kê xuất cho agency, không tồn agency.

  Dữ liệu chưa post          Không tính vào báo cáo tồn/XNT.

  Người dùng ngoài scope     Từ chối do ngoài quyền/phạm vi; cách biểu diễn kỹ thuật theo SRS/permission contract.
  ------------------------------------------------------------------------------------------------------------------

## 5.13. BP-WMS-13 – Migration và Opening Balance

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Thuộc tính**    **Nội dung**
  ----------------- -----------------------------------------------------------------------------------------------------------------------------------------------
  Mục tiêu          Chuyển dữ liệu ban đầu từ Excel/kiểm đếm vào hệ thống có dry-run, reconciliation và rollback.

  Trigger           Chuẩn bị UAT/cutover/go-live hoặc nạp master/opening stock.

  Actor chính       Data Owner: Chị Phương – Quản lý kho Hoa Nam; BA, Tech Lead/DEV, QA, PM/PO.

  Đầu vào           Import Template tại WWHN-333 comment 12634; actual Excel SKU/item/chip/container/agency/opening stock được bàn giao và kiểm soát tại WWHN-69.

  Tiền điều kiện    Template và owner dữ liệu xác định; backup/rollback plan; code format và unique rule sẵn sàng.

  Đầu ra/Kết thúc   BPD design baseline; execution output tại WWHN-69 gồm Migration Batch, opening balance, checksum, error file, reconciliation và sign-off.
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------

### Luồng TO-BE chi tiết

  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Bước**   **Actor**          **Hoạt động nghiệp vụ**                                                                         **Xử lý/Kết quả hệ thống**                                                                                                           **Rule/ngoại lệ liên quan**
  ---------- ------------------ ----------------------------------------------------------------------------------------------- ------------------------------------------------------------------------------------------------------------------------------------ -----------------------------
  01         Chị Phương/BA      Bàn giao Import Template và file dữ liệu theo version; xác định source of truth từng dataset.   Ghi owner, file/version, received_at và SHA-256 tại WWHN-69. Actual file chưa có không chặn BPD Done.                                BPD-MIG-CTRL-001

  02         Tech/BA            Chạy dry-run.                                                                                   Không đổi dữ liệu thật; xuất valid/invalid/duplicate counts và error file.                                                           REQ-SRS-021

  03         QL kho/BA          Sửa dữ liệu hoặc xác nhận waiver cho lỗi được phép.                                             Không commit nếu critical error \> 0 hoặc total pass rate \< 99,5%; mọi waiver non-critical phải có owner/reason/evidence.           AC-SRS-021

  04         Tech/QL kho        Commit migration trong cửa sổ cutover.                                                          Tạo opening data/movement theo thiết kế; audit Migration_Batch.                                                                      DEC-INV-001

  05         QL kho/QA          Đối soát theo SKU/item/container và kiểm đếm thực tế.                                           Reconcile 5 cấp: record, business key, quantity theo SKU, item/serial, container mapping; quantity và critical data phải đạt 100%.   BRD P0-8

  06         PM/PO/Khách hàng   Go/No-Go hoặc rollback.                                                                         Go/No-Go/rollback được thực hiện tại WWHN-69/WWHN-75; reconciliation failed thì No-Go.                                               REQ-SRS-022
  ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### Migration Control Baseline – P1-10

Status: CLOSED FOR BPD / EXECUTION WAIVED TO WWHN-69. Template, Data Owner, schema, tolerance, checksum, dry-run, rollback và reconciliation control đã chốt. Actual Excel file và execution evidence không chặn BPD Done nhưng bắt buộc trước Migration Commit/Beta/Go-live.

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Control**                         **Baseline đã chốt**
  ----------------------------------- --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Data Owner**                      Chị Phương – Quản lý kho Hoa Nam; chịu trách nhiệm tính đầy đủ, source of truth và xác nhận opening balance.

  **Controlled source**               Import Template: WWHN-333 comment 12634; actual Excel/checksum/execution: WWHN-69.

  **Schema minimum**                  SKU Master; SKU Alias; Agency; Physical_Code; Item/Serial; Carton/Box/Tray mapping; Opening Quantity/Condition.

  **Sample policy**                   Các dòng tại §7.2.6 là dữ liệu minh họa TO-BE, không phải dữ liệu thật Hoa Nam.

  **Tolerance**                       Critical identifier/reference/quantity error = 0; total valid record ≥ 99,5%; quantity reconciliation = 100%.

  **Dry-run exit criteria**           100% critical records valid; tổng pass ≥99,5%; duplicate critical = 0; error file phát hành; checksum khóa; Data Owner + QA xác nhận.

  **Checksum/version**                SHA-256 cho từng file trước dry-run và commit; file thay đổi phải tạo version/checksum mới và chạy lại toàn bộ dry-run.

  **Rollback**                        Chụp pre-migration backup/snapshot; ghi migration_batch_id và opening movement reference; No-Go thì restore hoặc chạy rollback plan được Tech phê duyệt. BPD không quy định lệnh DB.

  **Reconciliation level**            Record count; business key uniqueness; quantity theo SKU/status; serialized item count; container mapping; ledger–balance–subledger.

  BPD gate waiver                     PM/PO chấp thuận actual file, checksum, dry-run và reconciliation không chặn BPD Done; các nội dung này vẫn là hard gate cho Migration Commit/Beta/Go-live.
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

  ---------------------------------------------------------------------------------------------------------------------------------------
  **Dataset**            **Critical key**                  **Required field baseline**                     **Ví dụ minh họa**
  ---------------------- --------------------------------- ----------------------------------------------- ------------------------------
  SKU Master             sku_code                          sku_name, sku_type, unit, serial_mode, status   DCH-DRILL-DJZ10A-220V-V1

  SKU Alias              source + legacy_sku_code          sku_code mới, source                            EXCEL-OLD + MAYKHOAN-01

  Agency                 agency_code                       agency_name, status                             AG-HN-001

  Physical Code / Item   code_value hoặc item_code         code_type, carrier_type, sku_code, condition    ITEM-RFID-000001

  Container mapping      container_code + child code/SKU   container_type, parent/tray, quantity           BOX-001 → TRAY-01, qty 100

  Opening Balance        warehouse + sku + stock_status    quantity, source_ref, as_of_date                WH-HN + SKU-A + NORMAL = 100
  ---------------------------------------------------------------------------------------------------------------------------------------

### Hậu điều kiện và kiểm soát

-   Bộ file dữ liệu thật chưa được cung cấp trong nguồn hiện tại.

-   Opening balance phải có người có thẩm quyền xác nhận.

-   Rollback và rehearsal là gate trước Go-live.

### Ngoại lệ chính

  -----------------------------------------------------------------------------------------------------------------
  **Tình huống**              **Cách xử lý bắt buộc**
  --------------------------- -------------------------------------------------------------------------------------
  Duplicate item/chip/code    Không commit dòng lỗi; đưa error file.

  SKU cũ không map được       Giữ pending/error; không tạo SKU suy đoán.

  Excel lệch kiểm đếm         Dùng kiểm đếm được QL kho ký xác nhận làm source of truth theo quyết định hiện tại.

  Reconciliation không khớp   No-Go/rollback.

  File thay đổi sau dry-run   Chạy lại bằng checksum/version mới.
  -----------------------------------------------------------------------------------------------------------------

## 5.14. End-to-End Scenario – Nhập → Tồn → Xuất → Bảo hành → Trả/Reversal → App PV/Báo cáo

Scenario minh họa dùng SKU-A, opening balance = 0 và 10 item có mã. Đây là test data TO-BE, không phải dữ liệu thật Hoa Nam. Scenario tách rõ “trả máy bảo hành cho khách” và “Reverse Issue hàng quay lại kho” vì hai action có stock effect khác nhau.

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Bước**    **Process/Handoff**               **Business action**                                                           **State/Evidence**                                                     **Stock effect**   **Expected available_qty**
  ----------- --------------------------------- ----------------------------------------------------------------------------- ---------------------------------------------------------------------- ------------------ ----------------------------
  0           Precondition                      SKU-A active/published; 10 item code hợp lệ; kho tổng active.                 Opening = 0                                                            0                  0

  1           BP-WMS-02 Inbound                 Nhận và Post Receipt 10 item.                                                 Inbound POSTED; 10 RECEIPT_POSTED; item IN_STOCK                       +10                10

  2           BP-WMS-03 Putaway/Location        Gán carton/box/tray hoặc location_hint.                                       Putaway CONFIRMED; mapping audit                                       0                  10

  3           BP-WMS-11 App PV                  Đồng bộ read model trong ≤60 giây.                                            last_inventory_sync_at; App hiển thị Còn hàng                          0                  10

  4           BP-WMS-04 Outbound                Post Issue 2 item cho AG-HN-001.                                              2 ISSUE_POSTED; item A/B → ISSUED                                      -2                 8

  5           BP-WMS-08 Warranty Receive        Khách mang item A bảo hành; tạo case và consent.                              Item A → IN_WARRANTY; case RECEIVED                                    0                  8

  6           BP-WMS-08 Warranty Return         Hoàn tất và trả item A cho khách.                                             Case RETURNED; item A → RETURNED_TO_CUSTOMER                           0                  8

  7           BP-WMS-10 Reverse Issue           Phát hiện xuất nhầm item B; hàng vật lý quay lại SELLABLE và Post reversal.   ISSUE_REVERSED; item B → IN_STOCK; trace movement gốc                  +1                 9

  8           BP-WMS-06/12 Reconcile & Report   Reconcile rồi chạy XNT và warranty report.                                    0 + receipt 10 - issue 2 + issue reversal 1 = closing 9; report PASS   0                  9

  9           BP-WMS-11 App PV                  Cache nhận balance mới; nếu \>5 phút hiển thị stale warning.                  available_qty = 9; last_inventory_sync_at mới                          0                  9
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 6. QUY TẮC KIỂM SOÁT XUYÊN QUY TRÌNH

### 6.1. Stock effect matrix

  --------------------------------------------------------------------------------------------------------------------------------------------------------
  **Hoạt động**                   **Stock effect**         **Movement**                      **Ghi chú**
  ------------------------------- ------------------------ --------------------------------- -------------------------------------------------------------
  Create/Scan Inbound             0                        Không                             Chỉ tạo document/scan event.

  Post Receipt                    +N                       RECEIPT_POSTED                    Idempotent, atomic, partial supported.

  Putaway                         0                        Không                             Chỉ Box–Tray/location mapping.

  Create/Scan Outbound            0                        Không                             Chưa giảm tồn.

  Post Issue                      -N                       ISSUE_POSTED                      Idempotent, lock/no negative stock.

  Create/Count/Review Stocktake   0                        Không                             Snapshot/count/variance only.

  Apply Adjustment                ±N                       ADJUST_IN/ADJUST_OUT              Chỉ line approved; version hiện hành + idempotency control.

  Warranty Receive/Return         0                        Không                             Không tăng sellable stock.

  Post Reversal                   ±N ngược giao dịch gốc   RECEIPT_REVERSED/ISSUE_REVERSED   Append-only; giữ movement gốc.

  App PV view/search/filter       0                        Không                             Read-only.
  --------------------------------------------------------------------------------------------------------------------------------------------------------

### 6.2. State transition summary

Ký pháp chuẩn: FROM -- Action \[Guard\] / Stock effect --\> TO. Mũi tên là một chiều; không dùng ký hiệu ↔. Trạng thái terminal không được reopen trực tiếp; phát sinh nghiệp vụ mới hoặc correction/reversal có liên kết và audit.

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Object**   **FROM**                           **Action / Guard**                                **TO**                         **Stock effect**         **Cancel / Close / Reopen rule**
  ------------ ---------------------------------- ------------------------------------------------- ------------------------------ ------------------------ -------------------------------------------------------------------------------
  Inbound      DRAFT                              Start scan/enter data                             SCANNING                       0                        DRAFT/SCANNING được CANCEL nếu chưa có post.

  Inbound      SCANNING hoặc PARTIALLY_POSTED     Post Receipt subset hợp lệ                        PARTIALLY_POSTED hoặc POSTED   +N                       PARTIALLY_POSTED không Cancel; dùng Close remainder.

  Inbound      PARTIALLY_POSTED                   Close remainder + reason                          CLOSED                         0                        CLOSED terminal; không reopen.

  Inbound      POSTED                             Finalize/lock document                            CLOSED                         0                        Sửa sai sau post bằng Reversal/Correction.

  Outbound     DRAFT                              Start pick/scan                                   SCANNING                       0                        DRAFT/SCANNING được CANCEL nếu chưa có post.

  Outbound     SCANNING hoặc PARTIALLY_POSTED     Post Issue subset; đủ tồn tại commit              PARTIALLY_POSTED hoặc POSTED   -N                       PARTIALLY_POSTED không Cancel; dùng Close remainder.

  Outbound     PARTIALLY_POSTED/POSTED            Close/finalize                                    CLOSED                         0                        CLOSED terminal; hàng quay lại dùng Reverse Issue.

  Item         NEW                                Post Receipt                                      IN_STOCK                       +1                       Không edit state thủ công.

  Item         IN_STOCK                           Post Issue                                        ISSUED                         -1                       Không chuyển trực tiếp sang RETURNED_TO_CUSTOMER.

  Item         ISSUED hoặc RETURNED_TO_CUSTOMER   Warranty Receive; không có active case            IN_WARRANTY                    0                        Một item tối đa một active warranty case.

  Item         IN_WARRANTY                        Return to customer sau COMPLETED                  RETURNED_TO_CUSTOMER           0                        Mở chu kỳ mới bằng Warranty Case mới, không reopen case cũ.

  Item         ISSUED                             Reverse Issue; hàng đã về và SELLABLE             IN_STOCK                       +1                       Nếu non-sellable, condition/status riêng và không cộng Available.

  Item         IN_STOCK                           Reverse Receipt; không có downstream dependency   NEW                            -1                       Movement gốc giữ nguyên; tạo reversal append-only.

  Item         IN_STOCK                           Approved stocktake adjustment                     LOST hoặc ADJUSTED_OUT         -1                       Không dùng update state trực tiếp để che lỗi chứng từ.

  Stocktake    DRAFT                              Start count                                       COUNTING                       0                        Có thể CANCEL trước Apply; reason bắt buộc nếu đã Count.

  Stocktake    COUNTING                           Submit count / calculate variance                 REVIEWING                      0                        Reopen review chỉ bằng refresh/recount trước Apply, không đổi state terminal.

  Stocktake    REVIEWING                          Apply all approved lines; version hiện hành       APPLIED                        ±N                       APPLIED terminal; lỗi sau đó xử lý bằng session/correction mới.

  Warranty     RECEIVED                           Begin checking                                    CHECKING                       0                        CANCELLED cần reason và evidence trả máy nếu đang giữ vật lý.

  Warranty     CHECKING                           Begin repair                                      REPAIRING                      0                        Không skip trực tiếp sang RETURNED.

  Warranty     REPAIRING                          Complete processing                               COMPLETED                      0                        COMPLETED chưa phải đã trả máy.

  Warranty     COMPLETED                          Handoff machine to customer                       RETURNED                       0                        RETURNED/CANCELLED terminal; case mới phải link case cũ nếu cần.

  Putaway      DRAFT                              Confirm valid Box–Tray mapping                    CONFIRMED                      0                        DRAFT có thể CANCEL; CONFIRMED terminal, sửa bằng task correction mới.

  Reversal     DRAFT                              Confirm business scope/evidence                   CONFIRMED                      0                        DRAFT/CONFIRMED có thể CANCEL trước Post.

  Reversal     CONFIRMED                          Post reversal; guard dependency/reversible qty    POSTED                         ±N ngược giao dịch gốc   POSTED terminal; sửa tiếp bằng correction_chain mới.
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 6.3. Business Idempotency, Concurrency và Technical Handoff Boundary

-   BPD-CTRL-IDEMP-001: Post Receipt, Post Issue, Apply Adjustment và Post Reversal phải có khóa yêu cầu nghiệp vụ duy nhất để cùng một intent chỉ tạo stock effect một lần.

-   Cùng business request key + cùng operation/payload: trả lại kết quả nghiệp vụ đã ghi nhận; không tạo movement hoặc cập nhật tồn lần hai.

-   Cùng business request key nhưng nội dung khác: chặn do xung đột request identity; không đổi tồn. Mã lỗi/HTTP response được quy định trong SRS/OpenAPI.

-   BPD-CTRL-CONC-001: Post Issue kiểm tra remaining_qty/available_qty tại thời điểm commit và dùng cơ chế khóa/phiên bản kỹ thuật để chỉ một giao dịch cạnh tranh thành công.

-   BPD-CTRL-STALE-001: Apply Stocktake chỉ được thực hiện trên bản review/session hiện hành; bản stale phải refresh/review lại trước khi đổi tồn. Header, status code và API convention thuộc SRS/Technical Handoff.

Nguyên tắc ranh giới: BPD là nguồn normative cho actor, guard, state, stock effect và evidence. SRS/FRD v1.3, WWHN-31 và WWHN-40 là nguồn normative cho endpoint, header, HTTP status, error body, locking implementation và permission contract.

  -------------------------------------------------------------------------------------------------------------------------------------------------------
  **Business control ID**   **BPD requirement**                                                       **Technical reference**
  ------------------------- ------------------------------------------------------------------------- ---------------------------------------------------
  **BPD-CTRL-IDEMP-001**    Duplicate/retry không được tạo stock effect lần hai.                      SRS REQ-SRS-008/012/015/013; OpenAPI/WWHN-31

  **BPD-CTRL-CONC-001**     Concurrent issue/reversal chỉ một transaction thành công; không âm kho.   SRS BR-CONC; ERD/transaction design WWHN-31

  **BPD-CTRL-STALE-001**    Stale stocktake review bị chặn trước Apply.                               SRS REQ-SRS-015; OpenAPI concurrency contract

  **BPD-CTRL-PERM-001**     Ngoài quyền/scope bị từ chối; backend enforce.                            SRS REQ-SRS-026; permission mapping WWHN-40

  **BPD-CTRL-ERROR-001**    Mỗi business exception có reason rõ và stock effect = 0.                  SRS error catalog/OpenAPI; QA Test Design WWHN-33
  -------------------------------------------------------------------------------------------------------------------------------------------------------

### 6.4. Audit và evidence

  ----------------------------------------------------------------------------------------------------------------------------------------------
  **Nhóm action**                  **Audit tối thiểu**
  -------------------------------- -------------------------------------------------------------------------------------------------------------
  Post/Close/Reversal/Adjustment   actor, time, request_id, document/source, reason nếu có, idempotency_key, before/after state, movement IDs.

  Scan                             scan_batch_id, code_value, code_type, scan_source, result, duplicate/unknown flag.

  Master/Permission/Publish        actor, field changed, old/new value, effective time.

  Warranty                         case/event, actor, status, notes, item/temp code, customer contact access controlled.

  Migration/UAT                    file checksum/version, dry-run result, error file, commit batch, reconciliation, evidence link, sign-off.
  ----------------------------------------------------------------------------------------------------------------------------------------------

### 6.5. Warranty Privacy Process – SĐT, Ảnh và Dữ liệu Nhận diện

Privacy baseline áp dụng cho Warranty_Case có mã và TEMP_ONLY. Mục tiêu là dùng tối thiểu dữ liệu cần thiết để tiếp nhận, liên hệ, đối chiếu và trả máy; không công khai dữ liệu khách trên App PV hoặc báo cáo rộng.

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Bước/Control**    **Actor/Role**                                            **Business rule**                                                                                                                                         **Evidence / Data state**
  ------------------- --------------------------------------------------------- --------------------------------------------------------------------------------------------------------------------------------------------------------- -----------------------------------------------
  Collect & consent   NV bảo hành                                               Thông báo mục đích dùng SĐT/ảnh; ghi consent_status, consent_at, consent_channel. Ảnh là optional trừ khi cần evidence và khách đồng ý/quy định hợp lệ.   Warranty receipt; consent record; image_count

  Full view           NV bảo hành; QL kho/Admin có warranty.manage + pii.view   Chỉ xem full SĐT/ảnh khi xử lý case hoặc kiểm soát; mọi view/export nhạy cảm được audit.                                                                  pii_access_log: actor, case, purpose, time

  Masking             NV kho/Lãnh đạo/report viewer                             Danh sách và báo cáo hiển thị SĐT dạng 09\*\*\*\*\*\*67; ảnh chỉ thumbnail/ẩn nếu không có quyền.                                                         masked_phone; image_access_denied flag

  Search              NV bảo hành/QL kho theo quyền                             Cho phép tìm bằng SĐT nhưng kết quả chỉ trong warranty scope; không dùng PII để gợi ý App PV.                                                             search audit; scoped result

  Export              Role có warranty.pii.export                               Export phải có purpose/reason, filter/scope và audit; không đưa raw PII vào application log. Cơ chế encryption/TTL file thuộc Tech Handoff.               export_id, requester, reason, record count

  Retention           Data Owner + Admin                                        Giữ dữ liệu Warranty PII tối đa 5 năm sau khi case đóng, trừ legal hold hoặc nghĩa vụ pháp lý được phê duyệt.                                             closed_at, retention_until, legal_hold_flag

  Purge/Anonymize     Admin job + Data Owner review                             Sau retention: anonymize/purge SĐT/ảnh và dữ liệu nhận diện; giữ aggregate defect/SKU/status không định danh cho báo cáo.                                 purge_batch_id, affected cases, approval

  Customer request    NV bảo hành/QL kho                                        Yêu cầu xem/xuất/chỉnh sửa/xóa được ghi ticket và xử lý theo policy/pháp luật; không xóa movement/audit bắt buộc.                                         privacy_request_id, decision, evidence
  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

-   Degraded/privacy exception: nếu không có quyền xem full PII, người dùng vẫn xem được case code, status, SKU/model/defect và dữ liệu đã masking đủ để vận hành theo vai trò; không cấp quyền tạm bằng cách copy/export ngoài hệ thống.

## 7. INPUT/OUTPUT, BIỂU MẪU VÀ CHỈ SỐ QUY TRÌNH

### 7.1. Business document/input-output matrix

  ------------------------------------------------------------------------------------------------------------------------------------------
  **Quy trình**   **Đầu vào nghiệp vụ**                               **Đầu ra/evidence**
  --------------- --------------------------------------------------- ----------------------------------------------------------------------
  Danh mục/SKU    Product master, legacy codes, Agency list           SKU/alias, Physical_Code, Agency, publish profile.

  Nhập kho        Nguồn nhập, expected lines, scan/quantity           Inbound document, posting events, RECEIPT_POSTED, balance/subledger.

  Xuất kho        Receiver/Agency, planned lines, scan/quantity       Outbound document, ISSUE_POSTED, item/box state, receiver trace.

  Putaway         Tray + box/item mapping                             Putaway task/event, location hint.

  Kiểm kê         Scope, snapshot, counts, movement list              Variance lines, approval/waive, ADJUST movements, session evidence.

  Bảo hành        Customer, phone, item/temp identification, defect   Warranty case/events, returned evidence.

  Reversal        Original ref, reason, quantity/condition            Reversal doc, reverse movement, reconciliation.

  App PV          Published profile, balance read model               List/detail/search/filter/recommendation + available_qty.

  Migration       Excel/check count, mapping, checksum                Dry-run/error file, committed batch, reconciliation/sign-off.
  ------------------------------------------------------------------------------------------------------------------------------------------

### 7.2. Bộ Mẫu Nghiệp vụ TO-BE Đề xuất

*Các mẫu dưới đây là form TO-BE do BA đề xuất để DEV/FE/QA dùng làm field/evidence baseline. Chúng không được trình bày như biểu mẫu AS-IS thật của Hoa Nam; khi có mẫu/file thật, Data Owner xác nhận mapping và supersession trên Jira.*

  ----------------------------------------------------------------------------------------------------------------------------------------------------------
  **Form ID**      **Tên mẫu**                **Process**    **Owner sử dụng**             **Evidence chính**
  ---------------- -------------------------- -------------- ----------------------------- -----------------------------------------------------------------
  **FM-IN-001**    Phiếu nhập kho             BP-WMS-02/03   NV kho / QL kho               Expected/received lines, scan batch, posting event

  **FM-OUT-001**   Phiếu xuất kho             BP-WMS-04/05   NV kho / QL kho               Receiver, planned/issued, handoff, posting event

  **FM-STK-001**   Stocktake Sheet            BP-WMS-07      NV kho / QL kho               Snapshot, cutoff, actual, movement, variance, approve/waive

  **FM-WAR-001**   Phiếu tiếp nhận bảo hành   BP-WMS-08/09   NV bảo hành                   Item/temp code, PII/consent, defect, accessories/photo, handoff

  **FM-REV-001**   Reversal Request           BP-WMS-10      QL kho/Admin                  Original ref, reason, reversible qty, condition, approval

  **FM-MIG-001**   Migration Template         BP-WMS-13      Chị Phương / BA / Tech / QA   File version, checksum, dry-run, error, reconcile, sign-off
  ----------------------------------------------------------------------------------------------------------------------------------------------------------

### 7.2.1. FM-IN-001 – Phiếu Nhập Kho (sample)

  ---------------------------------------------------------------------------------------------------------------------------------------------------------
  **Nhóm field**          **Giá trị/field mẫu TO-BE**                                                         **Rule/evidence**
  ----------------------- ----------------------------------------------------------------------------------- ---------------------------------------------
  **Header**              inbound_no=IN-20260723-001; source=Nhà cung cấp TQ; warehouse=WH-HN; status=DRAFT   Document code unique; DRAFT stock effect 0

  **Line 1**              SKU=DCH-DRILL-DJZ10A-220V-V1; expected=10; received subset=8                        Không over-receipt; remaining=2

  **Physical scan**       carton=CTN-0001; item=ITEM-RFID-000001..008; scan_source=DEMO/REAL/MANUAL           Mã lạ/trùng bị tách error list

  **Posting evidence**    business_request_key; actor; posted_at; movement_ids; before/after state            Retry cùng intent không double stock

  **Close remainder**     close_reason; cancelled_remainder_qty=2                                             Chỉ QL kho/Admin theo quyền; stock effect 0
  ---------------------------------------------------------------------------------------------------------------------------------------------------------

### 7.2.2. FM-OUT-001 – Phiếu Xuất Kho (sample)

  -------------------------------------------------------------------------------------------------------------------------------------
  **Nhóm field**          **Giá trị/field mẫu TO-BE**                                       **Rule/evidence**
  ----------------------- ----------------------------------------------------------------- -------------------------------------------
  **Header**              outbound_no=OUT-20260723-001; agency=AG-HN-001; warehouse=WH-HN   Agency là nơi nhận, không phải kho

  **Line**                SKU-A; planned=2; issued=2; item codes A/B                        Kiểm tra IN_STOCK và available tại commit

  **Physical handoff**    handoff_area=DISPATCH; handed_by; received_by; handoff_at         Evidence bàn giao vật lý

  **Posting evidence**    ISSUE_POSTED movement; item → ISSUED; balance before/after        Không tạo balance/movement tại agency
  -------------------------------------------------------------------------------------------------------------------------------------

### 7.2.3. FM-STK-001 – Stocktake Sheet (sample)

  -------------------------------------------------------------------------------------------------------------------------------
  **Field**               **Giá trị mẫu**                                                 **Rule/evidence**
  ----------------------- --------------------------------------------------------------- ---------------------------------------
  **Session**             STK-20260723-001; scope=SKU-A; started_at=08:00; version=3      Snapshot tạo cùng session

  **Count line**          snapshot=100; movement_to_cutoff=-10; actual=90; cutoff=10:30   expected=90; variance=0

  **Review**              movement_warning_ack=true; reviewer=QL kho                      Stale review phải refresh

  **Decision**            line_status=WAIVED/APPROVED/APPLIED; reason                     Chỉ APPROVED được Apply

  **Adjustment**          movement_id nếu variance khác 0; request key; applied_at        Atomic; retry không double adjustment
  -------------------------------------------------------------------------------------------------------------------------------

### 7.2.4. FM-WAR-001 – Phiếu Tiếp nhận Bảo hành (sample)

  ----------------------------------------------------------------------------------------------------------------------------------------------
  **Field**               **Giá trị mẫu/an toàn**                                                   **Rule/evidence**
  ----------------------- ------------------------------------------------------------------------- --------------------------------------------
  **Case**                WAR-20260723-001; identification=ITEM hoặc TEMP_ONLY                      TEMP_ONLY không link item gốc

  **Customer**            name=Nguyễn Văn A (minh họa); phone display=09\*\*\*\*\*\*67              Full value chỉ role pii.view

  **Consent**             consent_status=GRANTED; channel=FORM; consent_at                          Ghi purpose cho contact/photo

  **Machine**             item_code hoặc temporary_item_code; SKU/model; description; accessories   missing_code_reason bắt buộc với TEMP_ONLY

  **Defect/Photo**        reported_defect; image_count; initial_condition                           Ảnh theo consent/quyền

  **Handoff**             received_by/at; returned_by/to/at; signature/evidence                     RETURNED chỉ sau COMPLETED
  ----------------------------------------------------------------------------------------------------------------------------------------------

### 7.2.5. FM-REV-001 – Reversal Request (sample)

  ----------------------------------------------------------------------------------------------------------------------------
  **Field**               **Giá trị mẫu**                                                   **Rule/evidence**
  ----------------------- ----------------------------------------------------------------- ----------------------------------
  **Request**             REV-20260723-001; type=ISSUE_REVERSAL; original_movement=MV-001   Append-only; giữ original

  **Scope**               qty=1; reversible_qty_before=1; item=ITEM-B                       Không vượt reversible qty

  **Reason**              reason_code=WRONG_ISSUE; reason_text; evidence link               Reason bắt buộc

  **Physical return**     returned_to_warehouse=true; condition=SELLABLE                    Chỉ SELLABLE cộng Available

  **Approval/Post**       created_by; confirmed_by; business_request_key; posted_at         Dependency guard + atomic commit
  ----------------------------------------------------------------------------------------------------------------------------

### 7.2.6. FM-MIG-001 – Migration Template (sample)

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Dataset/field**       **Dòng mẫu TO-BE**                                                               **Validation**
  ----------------------- -------------------------------------------------------------------------------- ------------------------------------------------------------------------
  **File control**        file=HN_OPENING_v0.1.xlsx; owner=Chị Phương; checksum=\<generated on receipt\>   Actual file managed at WWHN-69; SHA-256 required before dry-run/commit

  **SKU**                 DCH-DRILL-DJZ10A-220V-V1 \| Máy khoan \| PRODUCT \| ACTIVE                       sku_code unique; required refs active

  **Alias**               EXCEL_OLD \| MAYKHOAN-01 → DCH-DRILL-DJZ10A-220V-V1                              source+legacy key unique

  **Physical item**       ITEM-RFID-000001 \| RFID \| SKU \| NORMAL                                        code/item unique; ITEM carrier chip

  **Opening**             WH-HN \| SKU \| NORMAL \| qty=100 \| as_of_date                                  qty integer ≥0; quantity reconcile 100%

  **Dry-run result**      valid_count; invalid_count; duplicate_count; error_file                          Critical error=0; total pass ≥99,5%
  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### 7.3. KPI Baseline – Formula, Owner, Source và Target

Target dưới đây là baseline P1 được người dùng chốt ngày 23/07/2026 và cần PM/PO/Tech/QA xác nhận theo vai trò trước khi dùng làm acceptance/release target. Target “giảm 50%” cần đo AS-IS trong pilot đầu tiên.

  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **KPI**                              **Formula**                                                                                      **Owner**                                **Source/Frequency**                                         **Target**                                                                           **Action khi fail**
  ------------------------------------ ------------------------------------------------------------------------------------------------ ---------------------------------------- ------------------------------------------------------------ ------------------------------------------------------------------------------------ --------------------------------------------------------------------
  Inventory reconciliation pass rate   matched reconciliation scope / total scope ×100%                                                 Chị Phương + QL kho; Tech vận hành job   Reconciliation report; sau posting batch, daily và cutover   100% tại migration/cutover; ≥99,9% vận hành; mọi mismatch có incident                Block sign-off liên quan; điều tra và reversal/adjustment có audit

  Stocktake cycle time                 applied_at − stocktake_started_at                                                                Chị Phương / QL kho                      Session timestamps; mỗi session/pilot                        Giảm ≥50% so với AS-IS sau pilot; target phút/giờ tuyệt đối chốt sau lần pilot đầu   Phân tích wait time, scan coverage, recount và movement warning

  Unknown scan rate                    UNKNOWN scan events / total scan events ×100%                                                    QL kho                                   Scan_Event; daily/weekly                                     ≤1% sau data cleansing; 0 UNKNOWN được phép Post                                     Cleansing code/master; retrain thao tác; chặn Post

  Posting error rate                   Failed posting intents / total posting intents ×100%                                             Tech Lead + QL kho                       Posting/Audit log; daily/release                             \<0,5%; 0 negative stock; 0 duplicate movement                                       Incident + root cause; rollback transaction; regression test

  Migration pass rate                  valid total / total ×100%; critical valid / critical total; quantity matched / source quantity   Chị Phương + BA + QA                     Migration_Batch dry-run/commit; mỗi file version             100% critical; ≥99,5% total; quantity reconciliation 100%                            No-Go; sửa file/version mới; checksum + dry-run lại

  PV freshness compliance              Responses có now−last_inventory_sync_at ≤60s / total sampled responses ×100%                     Tech Lead / App PV owner                 Sync metrics; continuous/daily                               Normal target ≤60s; stale warning bắt buộc khi \>5 phút                              Hiển thị degraded warning; incident sync; không công bố realtime

  Duplicate posting prevented          Repeated same-intent requests trả replay / total repeated requests ×100%                         Tech Lead/QA                             Idempotency audit; contract test/release                     100% repeated same-intent không tạo stock effect lần hai                             Block release; kiểm tra request identity/transaction boundary
  -----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 8. TRACEABILITY VÀ GATE

### 8.1. Staged End-to-End Traceability Master

  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **BP**         **Contract/Input/Decision**        **REQ/BR/AC/TC**                        **Story/Screen/API/Data artifact**                             **Owner / Status**              **Evidence gate**
  -------------- ---------------------------------- --------------------------------------- -------------------------------------------------------------- ------------------------------- -----------------------------------------------
  BP-WMS-01      CTR-BPD-001; SRC-001; DR-CARRIER   REQ 002–005,020,026–029; TC tương ứng   Master/Code screens; WWHN-31 ERD/OpenAPI; WWHN-40 permission   BA complete; Tech planned       WWHN-335 baseline; DEV/QA evidence downstream

  BP-WMS-02      CTR-BPD-002; DEC-PARTIAL/INV       REQ 006–008; BR-IN/PARTIAL/IDEMP/TXN    Inbound screens/API/data; WWHN-31/40                           BA complete; Tech/QA pending    TC design WWHN-33; actual result downstream

  BP-WMS-03      SRC/DEC-LOC/INV                    REQ 007–009; BR-COMP/PTW                Component receipt/putaway UI/API/data                          BA complete                     WWHN-31/33

  BP-WMS-04      CTR; DEC-PARTIAL/INV               REQ 010–013; BR-OUT/CONC/REV            Outbound UI/API/data; permission                               BA complete                     WWHN-31/33/40

  BP-WMS-05      CTR; DEC-INV                       REQ 010–012,023; BR-COMP/OUT            Component issue/box quantity artifacts                         BA complete                     WWHN-31/33

  BP-WMS-06      DEC-INV                            REQ 023,030–032; BR-RECON/RPT           Inventory query/reconcile/report API/data                      BA complete                     WWHN-31/33

  BP-WMS-07      SRC; DEC-INV                       REQ 014–015; BR-COUNT; TC-STK           Stocktake screens/API/snapshot/data                            BA complete                     WWHN-31/33/40

  BP-WMS-08/09   CTR; DR-PRIV                       REQ 016; BR-WAR/PRIV; TC-WAR            Warranty/TEMP_ONLY UI/API/data                                 BA complete                     WWHN-31/33/40

  BP-WMS-10      DEC-INV; P0-07                     REQ 013; BR-REV-01..12; TC-REV          Reversal UI/API/data/report net                                BA complete                     WWHN-31/33/40

  BP-WMS-11      CTR-BPD-003; DR-PLAT               REQ 017–020,033; BR-PV; TC-PV           PV screens/read API/cache/last sync                            BA complete                     WWHN-31 + FE/QA gate

  BP-WMS-12      CTR; DEC-INV                       REQ 031–032; BR-RPT/EXP                 Report screens/API/export                                      BA complete                     WWHN-31/33/40

  BP-WMS-13      CTR-BPD-004; MIG waiver            REQ 021–022; BR-MIG/UAT; TC-MIG         Template + WWHN-69 migration + WWHN-75 UAT                     BPD closed; execution pending   Hard gate Migration/Beta/Go-live
  ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

*Governance trace: WWHN-333/335 comments → P0/P1 Closure Matrix → BPD v1.2 → WWHN-333 DONE trước hoặc đồng thời với WWHN-335 DONE; transition WWHN-335 là formal approval evidence và xác lập effective date.*

### 8.2. Gate sử dụng tài liệu

  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Gate**                           **Hiện trạng BPD v1.2**             **Điều kiện đạt**
  ---------------------------------- ----------------------------------- ----------------------------------------------------------------------------------------------------------
  Formal Review                      READY – FINAL APPROVAL CANDIDATE    Attach v1.2 vào WWHN-335; v1.0/v1.1 SUPERSEDED.

  BPD Task Done / Process Baseline   READY ON GOVERNANCE CONDITION       WWHN-333 DONE trước hoặc đồng thời; PM/PO chuyển WWHN-335 DONE. Effective date = ngày WWHN-335 DONE.

  Ready for Dev                      PROCESS READY / OVERALL NOT READY   Cần physical ERD/OpenAPI/UX/Permission/Test Design/Test Data theo SRS và WWHN-31/33/40.

  Ready for QA/UAT                   NOT READY – DOWNSTREAM              Test Master, test data, environment và execution/evidence tại WWHN-33/WWHN-75.

  Ready for Beta                     NOT READY – RELEASE GATE            Actual migration file/dry-run/reconcile WWHN-69; QA/UAT result/waiver; monitoring và rollback rehearsal.

  Go-live                            NOT READY                           Migration reconciliation pass, UAT pass/waiver, Go/No-Go, backup/rollback và sign-off.
  ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 9. DOWNSTREAM FOLLOW-UP VÀ WAIVER REGISTER

Các mục dưới đây không còn là P0 blocker của BPD khi cơ chế approval được thực hiện. Chúng được quản lý như downstream gate hoặc Change Request và không được dùng để tuyên bố Ready for Dev/QA/Beta/Go-live khi chưa hoàn tất.

  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------
  **Ưu tiên**             **Thông tin/Follow-up**                                                              **Mục đích**
  ----------------------- ------------------------------------------------------------------------------------ -------------------------------------------------------------
  CLOSED P0               AS-IS limited discovery: khảo sát 30/06/2026; summary + WWHN-335 comment 12646.      Đóng P0-03; không yêu cầu ảnh/layout/time study/form riêng.

  CLOSED P0               Chị Phương là Accountable duy nhất; executor theo role; Backup NOT DESIGNATED.       Đóng RACI/Process Owner.

  WAIVED P1-10            Actual Excel/checksum/dry-run/reconcile tại WWHN-69.                                 Không chặn BPD Done; hard gate Migration/Beta/Go-live.

  DOWNSTREAM              Code prefix/tem/chip và cách cấp mã thực tế.                                         WWHN-31/UX/operating instruction.

  P2                      Thiết bị scan thật, điểm đọc, batch/latency target.                                  Hardware readiness/performance test.

  CLOSED P0               Reverse Issue SELLABLE/NON_SELLABLE, pending return và dependency chain tại §5.10.   Implementation/test tại WWHN-31/33.

  CLOSED P1               App PV cache ≤60s; stale warning \>5m; last_inventory_sync_at.                       Monitoring implementation downstream.

  P2/CR                   SLA bảo hành, người sửa, chi phí/linh kiện thay thế.                                 Change Request/Phase 2.
  --------------------------------------------------------------------------------------------------------------------------------------------------------------------------

## 10. PHỤ LỤC THUẬT NGỮ

  ---------------------------------------------------------------------------------------------------------------------------------------------------------
  **Thuật ngữ**                     **Định nghĩa**
  --------------------------------- -----------------------------------------------------------------------------------------------------------------------
  SKU                               Mã loại hàng dùng quản lý tồn; PRODUCT hoặc COMPONENT.

  Item/Serial                       Một máy hoặc linh kiện cụ thể có mã riêng.

  Physical_Code                     Mã nghiệp vụ duy nhất dùng scan/tra cứu; carrier có thể QR/Barcode/RFID/NFC/Manual; ITEM bắt buộc chip.

  Carton                            Thùng chứa nhiều sản phẩm cùng SKU; container, không phải tồn độc lập.

  Box                               Hộp linh kiện một SKU; chứa serial hoặc quantity.

  Tray                              Locator chứa nhiều box; không xuất và không có quantity độc lập.

  Inventory_Movement                Ledger append-only, nguồn thẩm quyền của biến động tồn.

  Inventory_Balance                 Projection tồn hiện tại để đọc nhanh, cập nhật cùng transaction với ledger.

  Partial Receipt/Issue             Post nhiều lần trên cùng chứng từ đến đủ hoặc close remainder.

  khóa yêu cầu nghiệp vụ duy nhất   Khóa chống xử lý lặp cho action đổi tồn.

  Stocktake Snapshot                Ảnh chụp tồn tại stocktake_started_at dùng làm baseline kiểm kê.

  TEMP_ONLY                         Case bảo hành không có định danh gốc; quản lý bằng temporary_item_code và NOT_TRACEABLE.

  Reversal                          Chứng từ đảo append-only tham chiếu movement gốc.

  App PV                            Web App responsive read-only hiển thị sản phẩm published và available_qty kho tổng.

  zone_code                         Nhãn khu vực phẳng, optional, không có hierarchy và không tạo stock bucket.

  location_hint                     Gợi ý vị trí tương đối nullable để tìm/handoff; không phải địa chỉ tồn hoặc khóa balance.

  last_inventory_sync_at            Thời điểm PV cache/read model đồng bộ tồn gần nhất; dùng đánh giá freshness và degraded warning.

  Data Owner                        Người chịu trách nhiệm source of truth/chất lượng dữ liệu; dự án chốt Chị Phương – Quản lý kho Hoa Nam cho migration.
  ---------------------------------------------------------------------------------------------------------------------------------------------------------

## 11. KẾT LUẬN

+---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------+
| **KẾT LUẬN PHÁT HÀNH v1.2**                                                                                                                                                                             |
|                                                                                                                                                                                                         |
| • BPD v1.2 là FINAL APPROVAL CANDIDATE và đã đóng P0/P1 process-content ở cấp tài liệu.                                                                                                                 |
|                                                                                                                                                                                                         |
| • AS-IS discovery được xác nhận bằng khảo sát trực tiếp ngày 30/06/2026, “Tóm tắt yêu cầu đối tác.docx” và WWHN-335 comment 12646; không yêu cầu bộ ảnh/layout/time study/mẫu phiếu riêng cho BPD task. |
|                                                                                                                                                                                                         |
| • Chị Phương là Accountable/Process Owner/Data Owner duy nhất; executor theo role/permission; Backup chưa chỉ định và phải được Hoa Nam ủy quyền khi cần.                                               |
|                                                                                                                                                                                                         |
| • BPD v1.2 thay thế v1.0/v1.1 và trở thành APPROVED/BASELINE khi WWHN-335 DONE, sau hoặc đồng thời với WWHN-333 DONE. Effective date là ngày WWHN-335 transition DONE.                                  |
|                                                                                                                                                                                                         |
| • P1-10 được waiver sang WWHN-69: actual Excel/checksum/dry-run/reconciliation không chặn BPD Done nhưng chặn Migration Commit/Beta/Go-live.                                                            |
|                                                                                                                                                                                                         |
| • Ready for Dev/Beta không được suy ra chỉ từ BPD; physical ERD/OpenAPI/UX/permission/Test Design/Test Data/UAT và execution evidence tiếp tục theo gate downstream.                                    |
+=========================================================================================================================================================================================================+
+---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------+
