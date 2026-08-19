# DEC-INV-FULLPOST-PIN-190 — Pin nguồn hiệu lực cho DEC-INV-001 và DEC-FULL-POST-001

| Trường | Giá trị |
|---|---|
| Mục đích | Hồ sơ **chuẩn bị sẵn** cho BA/PM formalize theo register item `DEV190-P1-05R2` |
| Jira | WWHN-190 (DEV) → cần cập nhật ở WWHN-24 / WWHN-27 / WWHN-43 |
| Trạng thái | PREPARED BY DEV — **chờ BA/PM formal decision + cập nhật parent AC/source** |
| Ngày lập | 2026-08-19 |

> DEV **không** ra quyết định nghiệp vụ ở đây. File này chỉ pin **exact version / hash / date**
> của nguồn đang có hiệu lực để BA/PM ký một lần, không phải đi tra lại.

## 1. Yêu cầu của register

`DEV190-P1-05R2` (P1, owner BA/PM → Tech Lead + QA):
*"Formalize DEC-INV + full-post-only rule với exact effective version/hash/date; update WWHN-43 bỏ stale v3.9/partial ambiguity."*

## 2. Nguồn hiệu lực — pin chính xác

| Trường | Giá trị |
|---|---|
| Tài liệu | `docs/HN_SRS_FRD_v1.12_FULL.md` (repo FE `WMS_Warehouse_Hoa_Nam_FE`) |
| Document ID / Version | **HN-SRS-FRD-001 / VERSION 1.12** |
| Trạng thái ghi trong tài liệu | REVISION APPROVAL CANDIDATE / READY FOR FORMAL REVIEW |
| Ngày cập nhật ghi trong tài liệu | **14/08/2026** |
| SHA-256 nội dung file | `02cd010cdc40036b62ec23a0d3aa645e4cce813497edf8b0e851ba6fcb512ff2` |
| Git blob SHA-1 | `7943a9f171ac128cb4c4037b2a58f018902dd454` |
| Commit FE chạm file lần cuối | `09e0dd376f04610505669c79009478030c3a9223` (2026-08-18) |

Lệnh kiểm lại (chạy trong repo FE):

```bash
sha256sum docs/HN_SRS_FRD_v1.12_FULL.md && git rev-parse HEAD:docs/HN_SRS_FRD_v1.12_FULL.md
```

## 3. Nội dung hai quyết định (trích nguyên văn từ §2 Decision Records)

### DEC-INV-001

- **Decision**: "Inventory_Movement authoritative ledger; Balance projection; item/container subledger; atomic transaction."
- **Approver**: Tech Lead + PM/PO
- **Decision date (ghi trong SRS)**: "WWHN-334 DONE date" ← **đây là chỗ cần BA/PM thay bằng NGÀY THẬT**
- **Evidence**: BRD source WWHN-333; SRS adoption WWHN-334; physical detail WWHN-31
- **Chi tiết tầng authority**: SRS §7.1 "DEC-INV-001 Source of Truth" — Inventory_Movement append-only,
  `movement_key`/`idempotency_key` unique; Inventory_Balance là projection cập nhật **trong cùng transaction**
  với ledger + optimistic version; Item_Instance và Container_Item là subledger phải reconcile về balance.

### DEC-FULL-POST-001

- **Decision**: "Post Receipt/Issue exactly once for the complete document; every line must match the required quantity before Post."
- **Approver**: PM/PO + Tech Lead
- **Decision date (ghi trong SRS)**: "04/08/2026 confirmation; SRS adoption: WWHN-334; **formal Decision: WWHN-27 only**"
  ← **chỗ mơ hồ**: có ngày xác nhận khách hàng nhưng formal decision record vẫn treo ở WWHN-27
- **Evidence**: Customer confirmation 04/08/2026
- **Supersession Register §1.6** đã ghi: "Inbound/Outbound state model cũ có cơ chế đăng một phần → DEC-FULL-POST-001 → SUPERSEDED"
  và "Cơ chế đăng một phần cũ → DEC-FULL-POST-001 → SUPERSEDED 04/08/2026" ⇒ **không còn partial posting**.

## 4. Requirement/AC bị ràng buộc (để BA/PM đối chiếu khi ký)

| REQ | Nguồn | AC |
|---|---|---|
| REQ-SRS-007 | SRC-008 / DEC-FULL-POST-001 | AC-SRS-007: chỉ Post khi mọi line `scanned_qty = expected_qty`; lệch thì chặn, trạng thái/tồn/movement không đổi |
| REQ-SRS-008 | DEC-INV-001 | AC-SRS-008: retry không double stock/movement; failure rollback all |
| REQ-SRS-011 | SRC-008 / DEC-FULL-POST-001 | AC-SRS-011: chỉ Post khi mọi line khớp đủ và đủ tồn; RFID không phải gate |
| REQ-SRS-012 | DEC-INV-001 | AC-SRS-012: một request commit; request xung đột không trừ lần hai |
| REQ-SRS-023 | DEC-INV-001 | AC-SRS-023: ba lớp (movement/balance/subledger) khớp sau posting; divergence tạo incident |

## 5. Runtime đã thực thi đúng hai quyết định (bằng chứng cho reviewer)

| Quyết định | Nơi thực thi | Bằng chứng chạy thật |
|---|---|---|
| DEC-FULL-POST-001 | `CurrentBaselineStockService::postStockDocument()` — mọi dòng phải `scanned_qty = qty_planned`, lệch thì `422 FULL_POST_REQUIRED` | `Wwhn190PostingContractParityTest::test_partial_scan_is_blocked_by_full_post_rule` |
| DEC-INV-001 (ledger authoritative, balance projection, cùng transaction) | `CurrentBaselineStockService::mutateBalance()` — ghi `stock_movements` + update `inventory_balances` với `where version = ?` trong cùng transaction | `docs/evidence/WWHN-190/reconciliation/RECONCILIATION_REPORT.json`: L1 `accepted_receipt_qty = SUM(qty_delta)`, L2 `on_hand_qty = SUM(qty_delta)`, mismatch/orphan/duplicate = 0 |
| DEC-INV-001 (atomic, không half-post) | `IdempotencyStore::run()` bọc toàn bộ callback; lỗi giữa chừng xoá lease của chính request | kịch bản `05-forced-rollback` trong `docs/evidence/WWHN-190/concurrency/CONCURRENCY_EVIDENCE.json` |

## 6. Việc BA/PM cần làm (checklist ký một lần)

1. Thay "WWHN-334 DONE date" của **DEC-INV-001** bằng **ngày DONE thật** của WWHN-334.
2. Chốt trạng thái formal của **DEC-FULL-POST-001**: hoặc đóng WWHN-27 thành APPROVED, hoặc ghi rõ
   "content adopted in SRS v1.12, formal record WWHN-27 pending" — **không để hai cách hiểu song song**.
3. Cập nhật **WWHN-43** (task cha): Source & Traceability đang trích `BRD_HoaNam_v3_9` /
   `HoaNam_Handoff_v3_9`. Đính chính theo nguyên văn Supersession Register §1.6 (rà lại 2026-08-19):
   - `BRD v3.9` → có dòng supersession tường minh: **BRD v3.20, HISTORICAL / SUPERSEDED**.
   - `Handoff v3.9` → **không tồn tại** như một artifact trong registry (grep toàn bộ SRS v1.12
     không có "Handoff v3.9"); §1.6 chỉ có dòng `Handoff v3.10 reference → REMOVED / NO ARTIFACT`
     (thay bằng `BRD v3.20 + WWHN-333 and SRS v1.12 + WWHN-334`). Vậy trích dẫn "HoaNam_Handoff_v3_9"
     phải **xoá hẳn**, không có nguồn thay thế 1-1.
   Thay bằng **BRD v3.20 (WWHN-333) + SRS/FRD v1.12 (WWHN-334)**, và bỏ mọi câu chữ còn để ngỏ
   khả năng "đăng một phần / partial post" (căn cứ thêm: §0.3 P0-03 CLOSED — "Source Registry dùng
   BRD v3.20 và SRS/FRD v1.12; các revision cũ chỉ dùng truy vết lịch sử").
4. Ghi lại pin ở §2 (version + hash + date) vào chính decision record trên Jira để lần review sau
   không phải tra lại.
