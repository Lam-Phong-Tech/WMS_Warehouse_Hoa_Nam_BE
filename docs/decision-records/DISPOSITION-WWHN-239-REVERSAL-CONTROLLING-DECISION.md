# DISPOSITION-WWHN-239 — Decision điều khiển của luồng Reversal và dependency tồn kho

| Field | Value |
|---|---|
| Disposition ID | `DISP-REV-239` |
| Đóng mục | `DEV239-P1-01` |
| Jira task | `WWHN-239` (DEV) · `WWHN-241` (QA1) |
| Controlling decision | `DEC-REV-001` — temp key `HN-DEC-004` — Jira `WWHN-26` |
| Stock dependency | temp key `HN-STK-003` |
| Repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` · branch `m1` |
| Ngày lập | `2026-08-18` |
| Người lập | DEV WWHN-239 |
| Trạng thái | **PROPOSED DISPOSITION — chờ BA Main/Approver ghi kết quả trên Jira** |

Tài liệu này **không tự phong** `EFFECTIVE` cho `DEC-REV-001`. Nó làm đúng một việc mà
DEV239-P1-01 yêu cầu: nêu chính xác trạng thái hiện tại của decision điều khiển, chỉ ra
nguồn nào đang thực sự điều khiển code, và liệt kê dependency tồn kho ở dạng kiểm được —
để BA/Approver chỉ cần **đọc và duyệt**, không phải đi tra lại.

---

## 1. Hiện trạng của controlling decision (đo được, không suy diễn)

| Nguồn | Vị trí | Trạng thái ghi trong nguồn |
|---|---|---|
| Decision Record | `docs/decision-records/DEC-REV-001.md` §Document control | `PROPOSED — PENDING INDEPENDENT SIGN-OFF`; contract release `1.3.0-proposed` |
| Sign-off ledger | `DEC-REV-001.md` §12 | BA / Tech Lead / QA / FE / BAS / PM-PO đều `PENDING`; chỉ BE1 Author = `PROPOSED` |
| SRS/FRD v1.12 | dòng 265, bảng Decision log | `CONTENT ADOPTED IN SRS v1.12 / WWHN-26 FORMAL APPROVAL PENDING` |
| SRS/FRD v1.12 | dòng 115, `P0-08 Reversal-only guard` | `CONTENT ADOPTED IN v1.8 / WWHN-26 FORMAL APPROVAL PENDING` |
| SRS/FRD v1.12 | dòng 93, `FIX-15` | "Khoá Reversal-only theo candidate content của WWHN-26 … WWHN-26 vẫn FORMAL APPROVAL PENDING" |

**Kết luận đo được:** không tồn tại phiên bản `EFFECTIVE` nào của `DEC-REV-001`.
`Effective date` của nó là `NULL`. Vì vậy yêu cầu "pin latest EFFECTIVE DEC-REV version"
**không thể thoả bằng cách trỏ tới một version** — chỉ có thể thoả bằng **formal
disposition**, đúng nhánh thứ hai mà DEV239-P1-01 cho phép.

## 2. Disposition đề xuất — nguồn nào thực sự điều khiển code

> **Nguồn điều khiển của implementation Reversal là SRS/FRD v1.12**
> (`REQ-SRS-013`, `AC-SRS-013`, `BR-REV-01..12`, `§6` state model, `§8.1` enforcement
> standard, `§8.2` error contract, ERD bảng 33/34), **không phải** contract đề xuất
> `1.3.0-proposed` trong `DEC-REV-001` §5.

Lý do — và đây là điểm phải duyệt tường minh:

1. **SRS v1.12 đã hấp thụ toàn bộ nội dung nghiệp vụ của WWHN-26.** Ba dòng ở bảng trên
   đều ghi "CONTENT ADOPTED". Nội dung append-only, movement gốc immutable, reversal
   movement đối ứng, `original_movement_id` + `reversal_chain_id`, cấm generic
   stock-edit — tất cả đã là điều khoản của SRS v1.12, có AC và TC riêng.
2. **Phần CHƯA được hấp thụ là API surface**, và `DEC-REV-001` §6 tự ghi nhận điều đó:
   contract đề xuất là `POST /api/v1/reversals` + permission `stock.reversal.create`,
   trong khi runtime là `POST /inbound|outbound-documents/{id}/reverse|reversals` +
   permission `inventory.reversal.post`. `DEC-REV-001` §6 kết luận
   `FAIL-CLOSED / NOT IMPLEMENTATION EVIDENCE` và §10 `OP-REV-05` để mở việc adoption.
3. **SRS v1.12 §8.1 mới là nguồn khai path/permission đang chạy.** Do đó code không
   được đổi sang contract `1.3.0-proposed` khi `OP-REV-05` chưa đóng; và ngược lại,
   việc code KHÁC `DEC-REV-001` §5 **không phải defect** — nó là hệ quả của disposition
   này.

**Điều BA/Approver cần duyệt (một lần, ngắn):**

- [ ] D-1. `DEC-REV-001` không có version `EFFECTIVE`; dùng **formal disposition** này
      thay cho việc pin version.
- [ ] D-2. Nguồn điều khiển implementation = **SRS/FRD v1.12** (REQ-SRS-013 + BR-REV-01..12
      + §6 + §8.1 + §8.2 + ERD 33/34).
- [ ] D-3. `DEC-REV-001` §5 (`POST /api/v1/reversals`, `stock.reversal.create`) **chưa
      adopt**; `OP-REV-05` vẫn OPEN và **không chặn** WWHN-239, vì nó là ticket đổi API
      surface chứ không phải bất biến nghiệp vụ.
- [ ] D-4. WWHN-239 được đánh giá theo SRS v1.12, không theo contract proposed.

## 3. Dependency tồn kho `HN-STK-003` — quy về danh sách kiểm được

Yêu cầu gốc: *"xác nhận HN-STK-003 prerequisite nào bắt buộc trước reversal"*. Câu hỏi
đó chỉ trả lời được nếu tách "prerequisite" thành các bất biến tồn kho mà Reversal thực
sự đứng lên trên. Dưới đây là toàn bộ danh sách đó, mỗi dòng có nơi enforce và nơi chứng
minh — BA chỉ cần duyệt cột "Bắt buộc trước reversal?".

| # | Bất biến tồn kho | Enforce ở đâu | Bằng chứng | Bắt buộc trước reversal? |
|---|---|---|---|---|
| S-1 | Ledger append-only: không UPDATE/DELETE `stock_movements` | trigger `2026_08_15_000100_enforce_stock_movements_append_only_trigger` | migrate:fresh trên PG (log §5) | **CÓ** — BR-REV-01 vô nghĩa nếu ledger sửa được |
| S-2 | Movement gốc bất biến khi reversal Post | `CurrentBaselineReversalService::postDraft` chỉ INSERT movement mới | `Wwhn207InboundReversalFlowTest` | **CÓ** |
| S-3 | Balance = SUM(qty_delta) có dấu | `mutateBalance` cập nhật cùng transaction | `Wwhn239...::test_ledger_reconciles_with_balance...` | **CÓ** |
| S-4 | No-negative on-hand và available | `mutateBalance` chặn trước khi ghi | `Wwhn201PostIssueAtomicNonNegativeStockTest`, `Wwhn239...` | **CÓ** |
| S-5 | CAS `version` trên `inventory_balances` | `mutateBalance` `where('version', …)` → 409 `CONCURRENCY_CONFLICT` | `Wwhn219RuntimeConcurrencyTest` | **CÓ** |
| S-6 | Item subledger: `item_instances.item_status` phản ánh đúng vòng đời | `applyReceiptSubledger` / `applyIssueSubledger` / `postDraft` | `Wwhn205StockMovementItemIdInvariantTest`, `Wwhn239...` | **CÓ** |
| S-7 | `reversal_lines.original_movement_id/posted_movement_id` FK về ledger canonical | migration `2026_08_18_193100_add_canonical_ledger_fks_to_reversal_lines` | migrate:fresh trên PG | **CÓ** |
| S-8 | Mỗi serial chỉ đảo một lần trên một movement gốc | `assertItemNotAlreadyReversed` + unique index `2026_08_18_214000` | `Wwhn239...::test_double_reverse_…` | **CÓ** (mở bởi WWHN-239, xem §4) |
| S-9 | Không có Stocktake/Variance/direct stock-edit | REQ-SRS-037 + baseline contract `forbidden_path_fragments` | `Wwhn219ApplyAdjustmentDispositionTest` | **KHÔNG** — là ràng buộc phủ định, không phải prerequisite |
| S-10 | Mô hình kiểm kê A (`DEC-STK-001`) | — | `DEC-STK-001` vẫn `PROPOSED`, Effective `NULL` | **KHÔNG** — REQ-SRS-037 đã gỡ Stocktake khỏi MVP; Reversal không đọc snapshot kiểm kê |

**Điều BA/Approver cần duyệt (một lần, ngắn):**

- [ ] D-5. Prerequisite bắt buộc trước Reversal = **S-1 … S-8**. Tất cả đều có
      enforcement + bằng chứng chạy thật ở `docs/evidence/WWHN-239/`.
- [ ] D-6. **S-9 và S-10 KHÔNG phải prerequisite.** `DEC-STK-001` (kiểm kê) đang
      `PROPOSED` nhưng không chặn WWHN-239, vì REQ-SRS-037 đã loại Stocktake khỏi phạm
      vi v1.12 và luồng Reversal không phụ thuộc bất kỳ artefact kiểm kê nào.
      → `HN-STK-003` được disposition là **NON-BLOCKING cho WWHN-239**.

## 4. Thay đổi mà WWHN-239 đưa vào để S-8 trở thành đúng

S-8 trước đây **không được enforce**. Chi tiết trong
`docs/evidence/WWHN-239/DEV239_FIX_REGISTER.md` §2 (defect D-239-01). Tóm tắt để BA
duyệt: guard `reversible_qty` tính theo TỔNG quantity của movement gốc; một
`stock_document_line` serial gồm N item chỉ sinh MỘT movement `quantity=N`; nên cùng một
`item_id` có thể được khai đảo tới N lần mà vẫn "trong hạn mức" — tạo N movement
`REVERSAL` cho một serial vật lý duy nhất. Đây đúng là ba dòng mà WWHN-241 phải chứng
minh (`double-reverse`, `duplicate movement = 0`, `ledger = balance`), nên phải đóng
trước khi QA1 chạy, không phải sau.

## 5. Ledger sign-off (mỗi vai trò ghi ĐÚNG MỘT lần trên Jira WWHN-239)

| Vai trò | Nội dung phải xác nhận | Kết quả cho phép | Hiện tại |
|---|---|---|---|
| BA Main | D-1 … D-6 | `REVIEWED-SUFFICIENT` / `CHANGES REQUIRED` | `PENDING` |
| Approver (PM/PO) | D-1, D-3, D-4, D-6 | `APPROVED` / `REJECTED` / `CHANGES REQUIRED` | `PENDING` |
| Tech Lead | §4 + evidence matrix DEV239-P1-02 | `REVIEWED-VERIFIED` / `CHANGES REQUIRED` | `PENDING` |
| QA1 | ma trận WWHN-241 (DEV239-P1-04) | `PASS` / `FAIL` | `PENDING` |
| BAS/PM | DEV239-P2-01 sprint metadata | `TRACEABILITY-PINNED` | `PENDING` |

Disposition này fail-closed: chưa có kết quả của BA Main + Approver thì DEV239-P1-01
vẫn OPEN, bất kể code và test đã xanh.
