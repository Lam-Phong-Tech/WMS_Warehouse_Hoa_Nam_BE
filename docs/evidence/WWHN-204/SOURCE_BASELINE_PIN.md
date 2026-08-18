# WWHN-204 — DEV204-P1-05: Disposition DEC-INV-001, giải quyết pin nguồn v3.9/Handoff, xác nhận contract FE/QA

Register yêu cầu:

> *"BA/Tech Lead formal disposition DEC-INV-001/current technical baseline; resolve v3.9/Handoff
> source pin; FE/QA confirm same contract"* — nguồn: `WWHN-24` / `WWHN-31` / `WWHN-48`
> source/handoff. Evidence: *Decision/version/effective date; current Source Register; contract
> hash; handoff comments*.

Tài liệu này gom đủ 4 mảnh evidence đó về một chỗ để BA/BAS + Tech Lead chỉ cần **đọc và duyệt**,
không phải đi tra lại từng nguồn.

---

## 1. DEC-INV-001 — trạng thái thật: ĐÃ DUYỆT, không phải quyết định còn thiếu

| Trường | Giá trị | Nguồn kiểm chứng được |
|---|---|---|
| Decision ID | `DEC-INV-001` | SRS/FRD v1.12 §1.4 Decision Register |
| Nội dung | *Inventory_Movement authoritative ledger; Balance projection; item/container subledger; atomic transaction* | SRS v1.12 §1.4, dòng `DEC-INV-001` |
| Chi tiết chuẩn | SRS v1.12 **§7.1 DEC-INV-001 Source of Truth** (bảng 5 dòng: Movement / Balance / Item_Instance / Container_Item / Reversal chain) | §7.1 |
| Thẩm quyền duyệt | Tech Lead + PM/PO | §1.4 cột *Owner* |
| Version/effective | Nằm trong baseline **SRS/FRD v1.12** — bản controlling hiện hành | §1.1 *Supersedes*: *"SRS/FRD v1.12 thay thế SRS/FRD v1.10 và toàn bộ revision trước cho phần candidate hiện hành"* |
| Trạng thái supersession | §1.6 Supersession Register: *"Balance/Movement/subledger without authority definition → **DEC-INV-001** — SUPERSEDED"* | §1.6 |

**Kết luận:** không cần BA ra quyết định nghiệp vụ mới. Việc còn lại là **xác nhận cách diễn
giải kỹ thuật** ở `DISP-INV-204` (mục 2 dưới đây) đúng với ý baseline.

*(Cùng kết luận đã ghi ở `docs/docs_task_jira/WWHN-190.md` mục DEC-INV-001 — WWHN-204 không
phát hiện điều gì trái ngược.)*

## 2. Current technical baseline cần duyệt: DISP-INV-204

Toàn văn: `docs/decision-records/DISP-INV-204-projection-rebuild.md` (v1.0.0, hiệu lực 2026-08-19).

Điểm duy nhất cần BA/Tech Lead phán quyết — **phân biệt hai khái niệm đang bị gộp**:

| | (A) Hấp thụ chênh lệch thực tế vào tồn — **BỊ CẤM** | (B) Dựng lại projection từ ledger — phạm vi WWHN-204 |
|---|---|---|
| Nguồn con số đích | Người vận hành nhập vào | `SUM(stock_movements.qty_delta)`, **không có tham số nhập tay** |
| Ledger sau thao tác | Phải sinh movement mới | **không đổi một byte** (có trigger DB chặn + assertion fingerprint) |
| Điều khoản áp | §7.5 *"never by direct ledger or Balance edit"*; `REQ-SRS-037` | §7.5 dòng 1: `Balance = Opening + Posted Receipt − Posted Issue + Posted Reversal`; §7.1 *Balance = Projection* |

Diễn giải đề xuất: §7.5 cấm **nội dung** của phép ghi (đặt Balance sang một con số không có
trong ledger), không cấm **việc chạm vào bảng projection** — bằng chứng là chính đường ghi
nghiệp vụ (`post-receipt`/`post-issue`/`reversal`) vẫn UPDATE bảng đó mỗi ngày. Rebuild chỉ tái
lập đúng đẳng thức mà §7.5 dòng 1 định nghĩa.

Ràng buộc kèm theo (R1..R6 trong `DISP-INV-204` §4) và bằng chứng chạy thật ở
`docs/evidence/WWHN-204/PG_APPEND_ONLY_EVIDENCE.md`.

## 3. Resolve pin nguồn v3.9 / Handoff — baseline đã tự giải quyết

`WWHN-24` / `WWHN-31` / `WWHN-48` (và các ticket cha khác) vẫn trích nguồn cũ. SRS v1.12 **§1.6
Supersession Register** đã xử lý dứt điểm, trích nguyên văn:

| Old source/statement | New controlling source/decision | Status |
|---|---|---|
| BRD v3.7 | BRD v3.20 | HISTORICAL / SUPERSEDED |
| **BRD v3.9** | **BRD v3.20** | **HISTORICAL / SUPERSEDED** |
| BRD v3.10 | BRD v3.20 | HISTORICAL / SUPERSEDED |
| Các SRS/FRD revision trước | SRS/FRD v1.12 | SUPERSEDED |
| **Handoff v3.10 reference** | **Removed; use BRD v3.20 + WWHN-333 and SRS v1.12 + WWHN-334** | **REMOVED / NO ARTIFACT** |
| Balance/Movement/subledger without authority definition | DEC-INV-001 | SUPERSEDED |

**Current Source Register phải dùng** (SRS v1.12 §1.3):

| ID | Nguồn | Trạng thái |
|---|---|---|
| `SRC-002` | **BRD Hòa Nam v3.20** — current controlling business candidate | CONTROLLING BUSINESS REVISION CANDIDATE; WWHN-333 exact-hash approval pending |
| `SRC-008` | Business confirmation + BRD v3.20 consistency clarification 07/08/2026 (*full-post, **no stocktake/direct stock-edit***) | ADOPTED IN BRD v3.20 / SRS v1.12 |
| `SRC-009` | `HN_OpenAPI_v1_7.yaml` — companion contract | REVIEW-READY CANDIDATE |

⇒ Hành động cần: **BA/BAS cập nhật ô *Source & Traceability* trên Jira `WWHN-24`/`WWHN-31`/
`WWHN-48`** từ `BRD_HoaNam_v3_9` / `HoaNam_Handoff_v3_9` sang `BRD v3.20 (SRC-002) + SRS/FRD
v1.12`. Đây là **thao tác biên tập traceability**, không phải quyết định nghiệp vụ mới — nội
dung yêu cầu không đổi một chữ nào.

## 4. FE/QA confirm same contract — bằng chứng "không đổi"

WWHN-204 **không đổi bất kỳ contract nào**. Kiểm chứng được:

| Kiểm tra | Kết quả |
|---|---|
| Endpoint mới | **0**. Bề mặt duy nhất của rebuild là CLI `php artisan inventory:reconcile --rebuild` |
| Permission mới | **0** (lệnh console, không qua `CheckPermission`) |
| Response shape đổi | **0** — không sửa controller nào |
| OpenAPI / Postman đổi | **0** — không có annotation mới; `storage/api-docs/*` không nằm trong thay đổi của ticket này |
| FE phải sửa | **0**. FE gọi `INVENTORY_RECONCILIATION: "/reports/inventory-reconciliation"` (`src/shared/api/endpoints.ts:129`) và `/inventory/balances` — cả hai giữ nguyên contract |
| Test chống hồi quy | `Wwhn204LedgerProjectionRebuildTest::test_rebuild_is_not_exposed_as_any_http_route` — đỏ ngay nếu ai đó gắn rebuild lên HTTP |

Cái FE/QA **có thể quan sát thấy khác đi** sau khi vận hành chạy rebuild: dòng lệch trong
`GET /reports/inventory-reconciliation` biến mất và `GET /inventory/balances` trả đúng số của
ledger. Đó là **dữ liệu đúng lên**, không phải contract đổi.

## 5. Cái KHÔNG nằm trong disposition này

- Không hồi sinh Stocktake / Variance / apply-adjustment. `DISP-STK-230`, `DISP-STK-231`,
  `DISP-STK-233`, `DISP-STK-236`, `DISP-STK-237` giữ nguyên hiệu lực.
- Không thay `WWHN-26` / `BR-REV-01` làm đường sửa sai nghiệp vụ.
- Không đụng `WAIVER-CONTRACT-001`, `WWHN-333`/`WWHN-334` (approval của BRD/SRS) — vẫn PENDING
  đúng như baseline ghi, và WWHN-204 không phụ thuộc vào chúng.
