# WWHN-199 · DEV199-P1-06 — Current controlled source và mapping REQ/BR/AC

> Register yêu cầu, nguyên văn:
>
> *"Thay historical `BRD_HoaNam_v3_9`/`Handoff_v3_9` bằng current controlled source/version;
> map WWHN-199 sang current concurrency/Outbound REQ/BR/AC"* — vị trí: **WWHN-46 Source &
> Traceability**; evidence: *Parent source update + exact artifact/version/hash*.

Tài liệu này gom đủ evidence để BA/BAS **đọc và duyệt**, rồi sửa đúng một ô trên Jira.
Đây là **thao tác biên tập traceability**, không phải quyết định nghiệp vụ mới — nội dung
yêu cầu không đổi một chữ nào.

---

## 1. Vấn đề: ticket cha vẫn trích nguồn đã bị thay thế

`WWHN-199` không tự khai nguồn nghiệp vụ; nó thừa kế từ ticket cha **WWHN-46 — [OUTBOUND]
Lấy hàng/quét mã và chống ghi nhận trùng** (xem `docs/docs_task_jira/WWHN-199.md`, mục
*Input đầu vào* và *Source & Traceability*: `Parent Temp Key: HN-OUT-002`, `Epic Temp Key:
HN-EPIC-06`, `Dependency: HN-OUT-002-ST01`).

Ô *Source & Traceability* của WWHN-46 trên Jira vẫn trỏ `BRD_HoaNam_v3_9` /
`HoaNam_Handoff_v3_9`. Cả hai đã bị baseline hiện hành tuyên bố thay thế.

Đây là cùng một hạng mục đã được xử lý cho các ticket cha khác — `WWHN-43` (ghi tại
`docs/docs_task_jira/WWHN-190.md:227`) và `WWHN-24`/`WWHN-31`/`WWHN-48` (ghi tại
`docs/evidence/WWHN-204/SOURCE_BASELINE_PIN.md` §3). WWHN-46 là ticket cha còn sót lại của
nhóm này.

## 2. Baseline đã tự giải quyết — trích nguyên văn Supersession Register

Nguồn: `HN_SRS_FRD_v1.12_FULL.md` **§1.6 Supersession Register** (repo FE,
`docs/HN_SRS_FRD_v1.12_FULL.md`).

| Old source/statement | New controlling source/decision | Status |
|---|---|---|
| **BRD v3.9** | **BRD v3.20** | **HISTORICAL / SUPERSEDED** |
| BRD v3.7 / v3.10 / v3.11 / v3.15 | BRD v3.20 | HISTORICAL / SUPERSEDED |
| Các SRS/FRD revision trước | SRS/FRD v1.12 | SUPERSEDED |
| **Handoff v3.10 reference** | **Removed; use BRD v3.20 + WWHN-333 and SRS v1.12 + WWHN-334** | **REMOVED / NO ARTIFACT** |

⇒ `BRD_HoaNam_v3_9` là **HISTORICAL/SUPERSEDED**; `HoaNam_Handoff_v3_9` thuộc lớp
"Handoff … reference" đã bị **REMOVED / NO ARTIFACT** — không còn artifact để trỏ tới.

## 3. Current Source Register phải dùng cho WWHN-46

Nguồn: `HN_SRS_FRD_v1.12_FULL.md` **§1.3 Source Registry**.

| Source ID | Nguồn | Evidence status (nguyên văn §1.3) |
|---|---|---|
| `SRC-002` | **BRD Hoa Nam v3.20** — current controlling business candidate | CONTROLLING BUSINESS REVISION CANDIDATE; **WWHN-333 exact-hash approval pending** |
| `SRC-008` | Business confirmation + BRD v3.20 consistency clarification 07/08/2026 | ADOPTED IN BRD v3.20 / SRS v1.12; WWHN-25/26/27 formal approvals still pending where applicable |
| `SRC-009` | `HN_OpenAPI_v1_7.yaml` — required companion contract, 67 operations | REVIEW-READY CANDIDATE; final Jira attachment, Postman regeneration, contract-test và exact-hash independent review **pending** |
| `SRC-TOA-SAMPLE-2026` | `Phiếu toa hàng - 2026.xlsx` — customer sample | AVAILABLE / BUSINESS SAMPLE CONFIRMED 11/08/2026 · SHA-256 `0c0862cde71c8d7de5d0ae48ca3c78b27e849fdf1aa2da272ae4fb8d9e729ed4` |

Ghi chú bắt buộc giữ nguyên khi cập nhật Jira: trạng thái phê duyệt của
BRD v3.20 (WWHN-333) và SRS v1.12 (WWHN-334) vẫn **PENDING exact-hash**. Cập nhật ô Source
& Traceability **không** biến chúng thành đã duyệt.

### 3.1. Nội dung đề nghị cho ô *Source & Traceability* của WWHN-46

```
Source: SRC-002 BRD Hòa Nam v3.20 (current controlling business candidate; WWHN-333
        exact-hash approval pending)
        SRC-008 Business confirmation + BRD v3.20 clarification 07/08/2026
        SRC-009 HN_OpenAPI_v1_7.yaml (companion contract, review-ready candidate)
Spec:   HN_SRS_FRD_v1.12_FULL.md §5.2 (Outbound scan/match), §8.2 (HTTP/Error Contract),
        §13.2 AC-GWT-CONC-001, AC-GWT-OUTBOUND-REQUEST-001
Data:   HoaNam_ERD_Moi_Nhat.md — outbound_item_matches, inbound_scan_entries,
        outbound_requests, item_instances, inventory_balances
Thay thế: BRD_HoaNam_v3_9 (HISTORICAL/SUPERSEDED theo SRS v1.12 §1.6)
          HoaNam_Handoff_v3_9 (REMOVED / NO ARTIFACT theo SRS v1.12 §1.6)
```

## 4. Mapping WWHN-199 sang current concurrency/Outbound REQ/BR/AC

Chuỗi traceability chuẩn của baseline (SRS §1.4): *Contract Scope → BRD/Decision/Business
Rule → SRS Requirement → Acceptance Criteria → Test Case → Jira/Test Evidence*. Bảng dưới
đi đúng chuỗi đó cho từng bất biến mà WWHN-199 chịu trách nhiệm.

| # | Bất biến WWHN-199 | REQ (SRS §4) | Business rule (SRS §5.2) | AC (SRS §13.2) | Error contract (§8.2) | Evidence tại candidate |
|---|---|---|---|---|---|---|
| 1 | Một item vật lý không được match ACTIVE vào 2 phiếu xuất | `REQ-SRS-010`, `REQ-SRS-011` | *"Camera/manual scan Item; Item hợp lệ được match vào outbound line cùng SKU còn thiếu. Sai SKU, **trùng Item** hoặc Item đã xuất **bị chặn**."* | `AC-GWT-CONC-001` — *"Tối đa một request thành công; request còn lại trả 409/412/422 theo điểm phát hiện"* | 409 `STATE_CONFLICT`/`CONCURRENCY_CONFLICT` → mã nghiệp vụ `ITEM_ALREADY_MATCHED` | `race-report.json` mục A (6/6 vòng), `db-invariants.json` |
| 2 | Một item không được match 2 lần vào cùng 1 phiếu | `REQ-SRS-010` | cùng gạch đầu dòng trên ("trùng Item bị chặn") | `AC-GWT-CONC-001` | 409 → `DUPLICATE_ITEM_IN_DOCUMENT` / `DUPLICATE_ITEM_MATCH` | `Wwhn199ReservationConcurrencyGuardTest` |
| 3 | Một toa nguồn chỉ sinh đúng 1 phiếu xuất đang mở | `REQ-SRS-014` | *"Mỗi nguồn/toa hợp lệ chỉ tạo **một** outbound DRAFT; duplicate source/checksum/request no bị chặn"* + *"Không tạo phiếu trùng khi retry cùng source key/canonical payload"* | `AC-GWT-OUTBOUND-REQUEST-001` | 409 `STATE_CONFLICT` → `OUTBOUND_REQUEST_ALREADY_USED` | `race-report.json` mục B, `db-invariants.json` `request_level_after_state` |
| 4 | Đối xứng phía nhập: item không được nhận vào 2 phiếu nhập | `REQ-SRS-006`, `REQ-SRS-007` | §5.1 Inbound / Full Receipt | `AC-GWT-IN-001` | 409 → `ITEM_ALREADY_RECEIVING` | `Wwhn199ReservationConcurrencyGuardTest` |
| 5 | Không âm kho tại Post Issue (hard-check cuối) | `REQ-SRS-012` | *"Hoàn tất xuất/Post Issue là final stock-commit action… Post commit atomic/idempotent đúng một lần"* | `AC-GWT-CONC-001`, `AC-GWT-OUT-001` | 422 `INSUFFICIENT_STOCK` | WWHN-195/WWHN-201 (ngoài phạm vi task này, dẫn chiếu) |
| 6 | Backstop DB độc lập với ứng dụng | `REQ-SRS-023` (`DEC-INV-001`), `REQ-SRS-024` | §7.1 Source of Truth | — | 23505 → 409 | `PG_MIGRATION_AND_INDEX_EVIDENCE.md` §5-§6 |
| 7 | `reserved_qty` không phải nguồn giữ chỗ | `REQ-SRS-023` | §7.1 · Annex A Data Dictionary | — | — | `DEC-INV-RESERVED-001` (xem §5) |

Decision Record liên quan đang có hiệu lực: `DEC-INV-001` (Movement authoritative / Balance
projection), `DEC-FULL-POST-001` (full-post), `DEC-INV-RESERVED-001` (ngữ nghĩa
`reserved_qty`), `DEC-OUT-AVAILABILITY-001` (soft-check khả dụng tại scan),
`DISP-OUT-199` (hợp nhất unique index reservation).

## 5. Quan hệ với AC-GWT-CONC-001 — nói rõ phần nào đã đóng, phần nào không

`AC-GWT-CONC-001` viết trên **Post Issue** (*"Balance SKU=5, version=10; hai request Post
Issue A và B cùng yêu cầu 4"*). WWHN-199 **không** đóng toàn bộ AC đó:

| Clause của AC-GWT-CONC-001 | Ai đóng | Trạng thái |
|---|---|---|
| *"Tối đa một request thành công; request còn lại trả 409/412/422 theo điểm phát hiện"* — tại **tầng scan/match** (điểm phát hiện sớm nhất) | **WWHN-199** | **Đóng bằng race thật** — 6/6 vòng item-level + 1 vòng toa-level |
| *"Balance không âm; tổng ISSUE_POSTED ≤ 5"* — tại **Post Issue** | WWHN-195 / WWHN-201 | Ngoài phạm vi WWHN-199; dẫn chiếu, không tự nhận |
| *"UI request thua hiển thị refresh/retry, không tự submit lại bằng key mới"* | FE1 (WWHN-201 FE) | Ngoài phạm vi BE1 |
| *"Lock/version và transaction log có evidence cho contract/concurrency test"* | **WWHN-199** cho tầng scan | **Đóng** — mốc thời gian chồng lấn + after-state DB trong `race-report.json`/`db-invariants.json` |

Ghi tách như vậy để không ai đọc evidence của WWHN-199 rồi tưởng `AC-GWT-CONC-001` đã đóng
toàn bộ.

## 6. Việc BA/BAS cần làm

1. Sửa ô *Source & Traceability* của **WWHN-46** theo §3.1 — thay `BRD_HoaNam_v3_9` /
   `HoaNam_Handoff_v3_9` bằng `SRC-002 BRD v3.20` + `SRS/FRD v1.12`.
2. Xác nhận bảng mapping §4 là cách đọc đúng của baseline cho WWHN-199 (không cần ra quy
   tắc nghiệp vụ mới).
3. Xác nhận cách tách phạm vi ở §5 — WWHN-199 chỉ đóng phần scan/match của
   `AC-GWT-CONC-001`.

Không có hạng mục nào ở đây yêu cầu BA quyết định nội dung nghiệp vụ mới.
