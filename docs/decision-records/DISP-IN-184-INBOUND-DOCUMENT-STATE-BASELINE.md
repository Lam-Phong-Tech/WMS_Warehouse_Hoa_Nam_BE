# DISP-IN-184 — Baseline vòng đời chứng từ nhập kho (DEV184-P1-04R)

| Field | Value |
|---|---|
| Disposition ID | `DISP-IN-184-INBOUND-DOCUMENT-STATE-BASELINE` |
| Register item | `DEV184-P1-04R` |
| Jira | `WWHN-184` (thực thi) · **`WWHN-41` / `HN-IN-001`** (task cha cần cập nhật) · `WWHN-186` (blocked) |
| Loại | Formalize current baseline — **không phát sinh code mới** |
| Controlling artifact | **`docs/HN_SRS_FRD_v1.12_FULL.md` (SRS/FRD v1.12)** |
| Superseded source | BRD v3.9 wording (`Draft → Ready/Processing`, `create/update/submit`) |
| Owner | BA + BAS + PM/PO |
| Reviewer | Tech Lead + QA1/QA2 |
| Trạng thái | **PROPOSED — chờ phê duyệt** |
| Effective date | *(BA điền khi duyệt)* |
| Approval evidence | *(BA điền: link/ID phê duyệt)* |

---

## 1. Vấn đề

Register ghi: *"Parent vẫn create/update/submit, Draft→Ready/Processing, source v3.9;
WWHN-186 blocked"*.

Nói cách khác: **AC của task cha `WWHN-41` đang mô tả một vòng đời không tồn tại trong hệ
thống, và cũng không tồn tại trong tài liệu đang có hiệu lực.** Điều này không chỉ gây khó
đọc — nó làm mọi bên đánh giá sai:

- QA viết ca kiểm thử cho `Ready`/`Processing` và cho bước `Submit/Approve/Reject` → ca
  kiểm thử không bao giờ chạy được, bị coi là "chưa test đủ".
- Reviewer đối chiếu code với AC cha → thấy "thiếu Submit/Approve/Reject" → kết luận sai
  rằng BE chưa làm xong.
- Tiêu đề `WWHN-41` là *"Tạo/chỉnh/**duyệt** chứng từ nhập"*, càng củng cố hiểu lầm rằng
  luồng nhập có bước duyệt.

Trên thực tế, **không có nguồn nào đang hiệu lực khai bước duyệt cho luồng nhập kho.**

---

## 2. Baseline đang có hiệu lực — trích dẫn nguyên văn từ SRS v1.12

### 2.1. Phạm vi MVP (§2, bảng In scope / Out of scope)

> `Inbound/Outbound DRAFT -> SCANNING -> POSTED/CANCELLED; full-post only`

và ở cột ngoài phạm vi:

> `Đăng một phần, thiếu/thừa số lượng và thao tác đóng phần chưa khớp`

### 2.2. Core flow (§5.1 Inbound / Full Receipt)

> - `Create DRAFT -> camera/manual scan -> SCANNING. Scan không có stock effect.`
> - `Post Receipt chỉ khi mọi line scanned_qty = expected_qty. Nếu lệch, sửa expected_qty khi DRAFT/SCANNING hoặc cancel/recreate.`
> - `Post với Idempotency-Key + If-Match; document/movement/balance/subledger commit atomically; phiếu POSTED và không Post lần hai.`

### 2.3. Requirement

| ID | Nội dung | AC |
|---|---|---|
| `REQ-SRS-006` | Tạo inbound DRAFT/SCANNING, chưa tăng tồn | `AC-SRS-006`: draft/scan không sinh movement |
| `REQ-SRS-007` | **Full Post Receipt: chỉ Post toàn bộ một lần** khi mọi line `scanned_qty = expected_qty` | `AC-SRS-007`: nếu lệch, request bị chặn, trạng thái/tồn/movement không đổi |
| `REQ-SRS-008` | Post Receipt idempotent, atomic và audit | `AC-SRS-008`: retry không double stock/movement; failure rollback all |

Nguồn của `REQ-SRS-007` là `SRC-008 / DEC-FULL-POST-001`.

### 2.4. Annex C — toàn bộ operation của luồng nhập

| Operation | Method | Path | Permission |
|---|---|---|---|
| `listInboundDocuments` | GET | `/inbound-documents` | `inbound.view` |
| `createInboundDocument` | POST | `/inbound-documents` | `inbound.create` |
| `updateInboundDocument` | PATCH | `/inbound-documents/{id}` | `inbound.create` |
| `scanInboundDocument` | POST | `/inbound-documents/{id}/scans` | `inbound.scan` |
| `cancelInboundDocument` | POST | `/inbound-documents/{id}/cancel` | `inbound.cancel\|inbound.create` |
| `postReceipt` | POST | `/inbound-documents/{id}/post-receipt` | `inbound.post` |
| `postReceiptReversal` | POST | `/inbound-documents/{id}/reversals` | `inventory.reversal.post` |

**Annex C không khai bất kỳ operation `submit` / `approve` / `reject` nào cho luồng nhập.**

### 2.5. Annex D — màn hình

`SCR-IN-01` được mô tả là *"Inbound create/scan/full Post"*, với hành vi thành công là
*"POSTED + Balance increased once + one receipt movement scope"* và *"Post disabled until
all lines match"*. Không có bước duyệt trên UI.

---

## 3. Disposition được đề nghị

### 3.1. Baseline chính thức của Inbound Document

| Mục | Quyết định |
|---|---|
| Tập trạng thái | **`DRAFT`, `SCANNING`, `POSTED`, `CANCELLED`** — và chỉ bốn trạng thái này |
| Transition hợp lệ | `DRAFT → SCANNING` (scan đầu tiên) · `DRAFT → CANCELLED` · `SCANNING → CANCELLED` · `SCANNING → POSTED` (Post Receipt) |
| Trạng thái terminal | **`POSTED` và `CANCELLED`**. Không có transition ra khỏi hai trạng thái này |
| Post | **Full-post exactly once**: chỉ khi mọi line `scanned_qty = expected_qty`; Post lần hai trả `409 STATE_CONFLICT` |
| Stock effect | **Chỉ** Post Receipt sinh `stock_movements`/`inventory_balances`. Create/Update/Scan/Unscan/Cancel là stock-neutral |
| Sửa sai sau Post | Không sửa/xoá movement. Dùng **Reversal** (`REQ-SRS-013`, append-only) |
| Điều khiển đồng thời | `If-Match` bắt buộc trên mọi mutation của phiếu hiện hữu; thiếu → `428`, cũ → `412 STALE_VERSION`. `Idempotency-Key` bắt buộc cho `post-receipt` và `reversals` |
| **Submit / Approve / Reject** | **KHÔNG TỒN TẠI trong baseline MVP.** Không có operation, không có trạng thái trung gian, không có role duyệt riêng cho luồng nhập |
| `Ready` / `Processing` | **KHÔNG TỒN TẠI.** Là wording của BRD v3.9, đã bị SRS v1.12 thay thế |

### 3.2. Thứ tự guard (đã cố định trong code, cần ghi vào AC vì QA phải kiểm đúng mã)

Mọi mutation trên phiếu hiện hữu kiểm tra theo đúng thứ tự:

1. **Permission** → `403`
2. **Data scope kho** → `403 DATA_SCOPE_DENIED`
3. **Có `If-Match` không** → thiếu là `428 PRECONDITION_REQUIRED`
4. **`If-Match` có khớp version hiện hành không** → không khớp là `412 STALE_VERSION`
5. **Trạng thái có cho phép hành động không** → không cho phép là `409 STATE_CONFLICT`

Hệ quả bắt buộc phải ghi vào AC: **một request gửi version cũ trên phiếu đã terminal sẽ trả
`412`, không phải `409`** — vì nó dừng ở bước 4 và không bao giờ tới bước 5. Đây chính là
điểm đã làm bộ Postman contract cũ không thể pass và khiến `DEV184-P1-01R` bị treo PARTIAL.

### 3.3. Việc BA/PM/PO cần làm

1. **Cập nhật AC của `WWHN-41`** theo mục 3.1 + 3.2; gỡ `create/update/submit` và
   `Draft → Ready/Processing`.
2. **Đổi tiêu đề `WWHN-41`** từ *"Tạo/chỉnh/duyệt chứng từ nhập"* thành *"Tạo/chỉnh/hủy
   chứng từ nhập và Post Receipt"* — chữ "duyệt" là nguồn hiểu lầm còn lại cuối cùng.
3. **Ghi clarification log**: nguồn BRD v3.9 bị thay thế bởi SRS v1.12 cho phạm vi này;
   nêu rõ artifact kiểm soát, ngày hiệu lực và người duyệt.
4. **Đóng `WWHN-186`** hoặc nêu rõ vì sao nó vẫn blocked sau khi baseline được chốt.

### 3.4. Erratum đề nghị cho SRS (BA quyết định — không sửa đơn phương)

`§6 STATE MODEL` của SRS v1.12 liệt kê state machine cho **Item, Carton, Box, Warranty,
Reversal, Reversal chain, User/Agency/Master, Outbound Request Snapshot, Packing_Label,
Component Issue, Putaway Task** — nhưng **thiếu hẳn dòng Inbound/Outbound Document**, tức
là thiếu đúng đối tượng mà `WWHN-41`/`WWHN-184` hiện thực.

Baseline của nó chỉ nằm rải ở §2 (bảng phạm vi) và §5.1 (prose). Đây là lý do cấu trúc
khiến AC của task cha trôi về wording v3.9 mà không ai bắt được: không có ô nào trong bảng
state model để đối chiếu.

Dòng đề nghị bổ sung vào `§6 STATE MODEL`:

| **Object** | **States** | **Allowed transition / stock effect / invalid-state behavior** |
|---|---|---|
| Inbound / Outbound Document | DRAFT, SCANNING, POSTED, CANCELLED | `DRAFT→SCANNING` khi có scan đầu tiên; `DRAFT/SCANNING→CANCELLED`; `SCANNING→POSTED` chỉ khi mọi line `scanned_qty = expected_qty` (full-post exactly once). POSTED/CANCELLED terminal. Create/Update/Scan/Unscan/Cancel stock-neutral; chỉ Post sinh movement/balance. Không có Submit/Approve/Reject. Mutation cần `If-Match` (thiếu `428`, cũ `412 STALE_VERSION`); trạng thái không cho phép trả `409 STATE_CONFLICT` và không ghi dữ liệu. Sửa sai sau Post dùng Reversal. |

---

## 4. Baseline này đã được khoá bằng gì (để không trôi lần nữa)

| Bất biến | Nơi khoá |
|---|---|
| Bốn trạng thái, transition, full-post exactly once, POSTED/CANCELLED terminal | `tests/Feature/Wwhn184InboundContractParityTest::test_runtime_matches_postman_expectations` |
| Không có operation submit/approve/reject; permission khớp Annex C | `tests/Feature/Wwhn184InboundContractParityTest::test_postman_paths_and_permissions_match_route_table` |
| Thứ tự guard (412 trước 409) | cặp probe `D03`/`D04` trong `docs/postman/WWHN-184 Inbound Contract.postman_collection.json` + verifier `scripts/verify-wwhn184-postman-contract.mjs` (rule R3/R4) |
| Contract công bố cho FE/QA khớp runtime | `test_scoped_openapi_matches_compiled_spec` |
| FE dùng đúng tập trạng thái | `src/modules/inbound/types/index.ts`: `InboundStatus = "DRAFT" \| "SCANNING" \| "POSTED" \| "CANCELLED"` |

---

## 5. Phạm vi KHÔNG thuộc disposition này

- Không thay đổi hành vi runtime. Đây là formalize hiện trạng, không phải đổi thiết kế.
- Không quyết định thay cho luồng xuất (`WWHN-195`/Flow 03) ngoài phần state machine dùng chung đã trích ở §2.1.
- Không đụng tới `DEC-FULL-POST-001` — disposition này **dẫn chiếu**, không sửa.
