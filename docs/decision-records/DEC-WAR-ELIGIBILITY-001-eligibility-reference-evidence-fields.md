# DEC-WAR-ELIGIBILITY-001 — Eligibility, reference và evidence fields của luồng bảo hành

| Trường | Giá trị |
|---|---|
| Mã quyết định | `DEC-WAR-ELIGIBILITY-001` |
| Ngày lập | 19/08/2026 |
| Bản sửa đổi | Rev.1 |
| Phạm vi | `POST /api/v1/warranty-cases` (eligibility), `warranty_cases.purchase_date` (reference), `POST /api/v1/warranty-cases/{id}/attachments` (evidence) |
| Ticket | WWHN-244 (đóng DEV244-P1-07), WWHN-246 |
| Đóng mục review | **DEV244-P1-07** |
| Nguồn sự thật nghiệp vụ | SRS/FRD v1.12 §5.4.1 (dòng 386–388), Annex D SCR-WAR-01 (field spec ~dòng 1489–1495) |
| Nguồn sự thật cấu trúc | ERD `HoaNam_ERD_Moi_Nhat.md` §45 `warranty_cases` (dòng 2156–2223), §46 `warranty_events` (2225–2256), §47 `warranty_attachments` (2258–2297) |
| Nguồn sự thật kỹ thuật | `CurrentBaselineWarrantyController::storeWarrantyCase/createWarrantyCase` (dòng 60–134), `CurrentBaselineWarrantyAttachmentController::store` (dòng 47–138) |
| Người duyệt | BAS/BA (comment sign-off trên WWHN-244/WWHN-246) |

---

## 1. Vấn đề

Review WWHN-244 vòng 2 (74/100) nêu DEV244-P1-07 (NEW): chưa có tài liệu nào **pin** rõ
ba nhóm trường của một warranty case — (a) **eligibility** (điều kiện được mở case), (b)
**reference** (trường tham chiếu nguồn gốc hàng), (c) **evidence** (bằng chứng đính kèm) —
và xác nhận implementation khớp baseline. Không có decision record nào từng khai ba nhóm
này tường minh (`DEC-WAR-STATE-001` chỉ khai ma trận trạng thái/guard, không khai điều
kiện mở case).

## 2. Quyết định — Eligibility (điều kiện được mở warranty case)

Theo SRS §5.4.1 dòng 386–388 và implement tại `createWarrantyCase()`:

| Nhánh | Điều kiện | Không thoả → |
|---|---|---|
| Có `item_id`/`item_code` xác định | `item_status ∈ {ISSUED, RETURNED_TO_CUSTOMER}` | `422 WARRANTY_ITEM_NOT_ELIGIBLE` |
| Có `item_id`/`item_code` xác định | Không có active case khác cho cùng item (`status NOT IN (RETURNED,CANCELLED)`) | `409 ACTIVE_WARRANTY_EXISTS` |
| Không định danh được (TEMP_ONLY) | Bắt buộc `missing_code_reason` **và** `manual_product_description` | `422 TEMP_ONLY_EVIDENCE_REQUIRED` |

**Chốt: không có luật "còn hạn bảo hành" (warranty-period-vs-purchase_date) nào trong SRS
hay ERD.** `purchase_date` là trường tham khảo, không phải cổng eligibility — xem mục 3.
Đừng tự thêm gate hạn bảo hành vào code nếu BA không amend SRS trước.

## 3. Quyết định — Reference fields (nguồn gốc hàng)

ERD §45 chỉ định nghĩa **một** trường tham chiếu nguồn gốc: `purchase_date` (date,
nullable, `CHECK <= current_date`, "Ngày mua nếu có"). **Không tồn tại** trường số
hoá đơn/invoice_no/proof_of_purchase nào trong SRS, ERD hay wireframe — đây là ranh giới
phạm vi MVP, không phải thiếu sót cần bổ sung code.

Đã xác minh khớp 1-1 xuyên suốt: ERD §45 dòng 2181 → migration
`2026_08_10_000600_enforce_current_erd_business_contract.php` (cột + CHECK constraint) →
BE validate `'purchase_date' => ['nullable','date','before_or_equal:today']`
(`CurrentBaselineWarrantyController.php:76`) → FE type `CreateWarrantyCaseRequest.purchase_date`
(`src/modules/warranty/types/index.ts:143`) → FE form `WarrantyIntakeModal.tsx`
(`Form.Item name="purchase_date"`, dòng 350) → BE report `AlignedWarrantyReportController.php:175`.
Không có gap.

## 4. Quyết định — Evidence fields (bằng chứng đính kèm)

ERD §47 `warranty_attachments` + Annex D SCR-WAR-01 quy định: tối đa 10 file/case, tối đa
10MB/file, chỉ nhận `jpg/jpeg/png/webp`, MIME thật đọc bằng kiểm tra magic-byte (không tin
`Content-Type` header/extension đơn thuần), `attachment_type ∈ {INTAKE,DIAGNOSTIC,RETURN,OTHER}`,
`status ∈ {ACTIVE,DELETED,QUARANTINED}`.

Đã xác minh khớp: `CurrentBaselineWarrantyAttachmentController::store()` — giới hạn 10 file
(`MAX_ACTIVE_ATTACHMENTS`, dòng 29, 61–68), `mimes:jpg,jpeg,png,webp` + `max:10240`
(dòng 57), `WarrantyAttachmentScanner::assertContentType()` cho lớp kiểm MIME thật (dòng 72),
file nhiễm/không quét được → ghi row `QUARANTINED` giữ metadata, **không** ghi nội dung ra
storage (`recordQuarantine()`, dòng 146–174), đúng ERD §47 "Trạng thái ACTIVE/DELETED/QUARANTINED".
Không có gap.

## 5. Trường đã loại bỏ đúng đắn (không phải regression)

`consent_contact` / `consent_photo` từng có mặt tạm thời trong FE type
`CreateWarrantyCaseRequest` nhưng **không có cột ERD tương ứng, không có validate BE, không
được SRS/Annex D nhắc tới**. Một phiên làm việc đã xoá hai trường này khỏi
`src/modules/warranty/types/index.ts` — quyết định này **đúng baseline**, không phải mất
dữ liệu: nếu BA thực sự cần "đồng ý liên hệ lại"/"đồng ý chụp ảnh" như trường có cấu trúc,
đó là một **amendment ERD riêng** (thêm cột `warranty_cases`), không phải khôi phục lại
trường FE mồ côi.

## 6. Bằng chứng đối chiếu (19/08/2026)

| Điều khoản | Vị trí xác minh trực tiếp |
|---|---|
| §2 eligibility 3 nhánh | `CurrentBaselineWarrantyController.php:112–128` |
| §3 purchase_date xuyên suốt 5 tầng | ERD 2181, migration `000600`, controller `:76`, FE type `:143`, FE form `WarrantyIntakeModal.tsx:350` |
| §4 evidence 10 file / MIME thật / quarantine | `CurrentBaselineWarrantyAttachmentController.php:29,57,61-68,72,146-174` |
| §5 consent_contact/consent_photo không có backing | grep toàn repo BE + FE: 0 kết quả ngoài định nghĩa type vừa xoá |

## 7. Việc BAS/BA cần xác nhận (sign-off, không phải làm mới)

BAS/BA đọc mục 2–5 và xác nhận: (a) ba nhóm trường ở trên đúng là toàn bộ phạm vi
eligibility/reference/evidence của MVP — không thiếu trường nghiệp vụ nào ngoài SRS đã ghi;
(b) việc **không** có gate hạn bảo hành theo `purchase_date` là chủ đích, không phải bug;
(c) việc xoá `consent_contact`/`consent_photo` là đúng, không cần khôi phục. Nếu BA muốn bổ
sung trường mới (hạn bảo hành, số hoá đơn, consent có cấu trúc) → đó là amendment SRS/ERD
riêng cho một ticket khác, không chặn Done của WWHN-244.
