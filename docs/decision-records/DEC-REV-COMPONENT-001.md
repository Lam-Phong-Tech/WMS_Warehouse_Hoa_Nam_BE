# DEC-REV-COMPONENT-001 — Correction policy cho COMPONENT_ISSUE đã POSTED

- **Ticket:** WWHN-266 · register DEV266-P1-04R (defect BE266-D03)
- **Ngày:** 2026-08-19
- **Người quyết định:** BA + Tech Lead (sign-off bằng comment Jira trên WWHN-266)
- **Trạng thái:** APPROVED — hiện thực trong cùng vòng, evidence ở
  `tests/Feature/Wwhn266ComponentIssueReversalTest.php` + `build/evidence/wwhn-266/component-issue-reversal.json`

## 1. Vấn đề

`CurrentBaselineReversalService` chỉ nhận movement `RECEIPT`/`ISSUE` ở cả hai
chốt (create draft + post). Movement `COMPONENT_ISSUE` (phiếu xuất linh kiện bảo
hành, trừ tồn thật) vì thế **không có đường sửa sai hợp lệ** sau khi POSTED:
reversal chặn (kèm mã lỗi sai nghĩa `REVERSAL_OF_REVERSAL_FORBIDDEN`), còn sửa
tồn trực tiếp thì baseline cấm (BR-REV-01, AC-GWT-REV-001).

## 2. Xung đột authority (đo được trong docs)

| Nguồn | Nói gì |
|---|---|
| SRS §5.5 **BR-REV-01** (dòng 398) | "**Mọi** sửa sai sau Post dùng append-only reversal; không UPDATE/DELETE movement gốc và không sửa Balance trực tiếp." |
| **ERD bảng 33** `reversal_documents.original_document_type` (dòng 1621) | Allowed values **`INBOUND/OUTBOUND/COMPONENT_ISSUE`** — schema đã thiết kế sẵn cho reversal phiếu linh kiện. |
| REQ-SRS-013 (dòng 314), Annex A dòng 1252, Annex C, wireframe REV-00 | Chỉ liệt kê Receipt/Issue — bề mặt hẹp hơn, không có dòng nào cho COMPONENT_ISSUE. |

Không nguồn nào **cấm** reversal COMPONENT_ISSUE; nhóm REQ-SRS-013/Annex chỉ là
bề mặt liệt kê chưa được mở rộng khi Component Issue trở thành action đổi tồn
độc lập (root cause ghi ở BE266-D03). SRS không có bất kỳ cơ chế sửa sai nào
khác cho phiếu linh kiện đã POSTED ⇒ giữ nguyên hiện trạng là vi phạm BR-REV-01.

## 3. Quyết định

**Chọn phương án REVERSAL** — mở phạm vi reversal hiện có sang COMPONENT_ISSUE,
theo đúng BR-REV-01 và ERD bảng 33. Không tạo cơ chế correction riêng.

Ngữ nghĩa chốt:

1. `reversal_documents.original_document_type = 'COMPONENT_ISSUE'`,
   `original_document_id = component_issue_documents.id`,
   `stock_document_id = NULL` (chứng từ linh kiện không thuộc `stock_documents`).
2. Movement đối ứng: `movement_type = 'REVERSAL'` (ERD chỉ cho 5 giá trị, không
   thêm loại mới), `qty_delta` dương (cộng lại tồn), tham chiếu
   `original_movement_id` + `correction_chain_id`. **Append-only** — movement
   gốc không bị UPDATE/DELETE, balance chỉ đổi qua `mutateBalance()` có guard.
3. Component Issue cùng chiều stock effect với Issue ⇒ chịu **BR-REV-06/07**:
   chỉ Post khi `physical_return_confirmed`, bắt buộc `condition_status`; chỉ
   SELLABLE cộng `available_qty`, DEFECTIVE/NON_SELLABLE vào đúng bucket.
4. Serial component item: `ISSUED → IN_STOCK` khi đảo (đồng nhất Reverse Issue);
   item thuộc phiếu gốc xác minh qua `component_issue_items`
   (`source_line_id` của movement).
5. **Subledger BOX (`container_items`) không tự hoàn lại.** Linh kiện quay về
   được khai vào BOX bằng `POST /containers/{id}/items` — đường ghi duy nhất
   của bảng này, khớp disposition BE266-D04 (phương án b: khai nội dung BOX là
   bước vận hành). Test khoá bất biến "reversal không chạm container_items".
6. Chứng từ gốc giữ nguyên `POSTED` (sửa sai bằng chain, không đổi state gốc) —
   đồng nhất hành vi reversal receipt/issue hiện có.

## 4. Contract impact (DEV266-P1-06R)

- **Không thêm/bớt operation.** `POST /reversal-documents` +
  `/{id}/confirm|post|cancel` + `GET /stock-movements/reversible` dùng lại
  nguyên bộ; chỉ tập giá trị chấp nhận của `reference_document_id` mở rộng
  (nhận id `component_issue_documents`). Backward compatible — client cũ không
  đổi gì.
- Tập 47 operation bắt buộc If-Match **không đổi** (đo lại bằng
  `Wwhn266IfMatchContractTruthTest` 4/4 PASS sau thay đổi); Postman check PASS.
- Sửa mã lỗi sai nghĩa: movement ngoài phạm vi mà **không phải** REVERSAL giờ
  trả `422 ORIGINAL_MOVEMENT_INVALID` (khớp chốt create); riêng movement
  `REVERSAL` giữ `409 REVERSAL_OF_REVERSAL_FORBIDDEN` (BR-REV-08).
- Data scope: `EnforceReversalWarehouseScope` đóng lỗ fail-open (reference
  COMPONENT_ISSUE trước đây trả null = không kiểm tra kho).

## 5. Files

- `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php`
- `Modules/Warehouse/app/Http/Controllers/ReversalDocumentController.php`
- `Modules/Warehouse/app/Http/Controllers/StockMovementController.php`
- `Modules/Warehouse/app/Http/Middleware/EnforceReversalWarehouseScope.php`
- FE: `src/modules/reverse/types/index.ts` (helper `isStockRestoringMovement`),
  `src/modules/reverse/components/ReversalCreateModal.tsx` (COMPONENT_ISSUE
  phân loại issue-like), test `src/modules/reverse/types/movement-classification.test.ts`

## 6. Đề xuất cập nhật docs (chuyển BA, không sửa trong vòng này)

REQ-SRS-013, Annex A dòng 1252 (`reversal_type`), Annex C, wireframe REV-00 nên
được amend để liệt kê COMPONENT_ISSUE — hiện ERD (schema) và SRS (bề mặt) đang
mâu thuẫn; quyết định này chọn theo ERD + BR-REV-01 và ghi nhận nợ tài liệu.
