# WWHN-187 — Register đóng P0/P1/P2 (vòng đánh giá 59/100)

- Task: **WWHN-187** — [BE] Triển khai quét/ghi nhận/validation (Parent `WWHN-42` / `HN-IN-002`, Epic `HN-EPIC-05`, role BE2)
- Canonical repository BE: `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE`, branch **`m1`**
- Canonical repository FE: `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_FE`, branch **`m1`**
- Ngày lập: 2026-08-19
- Nguồn điều khiển: **SRS/FRD v1.12** + **BRD v3.20** + **ERD hiện hành** + **Techstack/Architecture Dev Guide** (hash ở `WWHN-187_CONTRACT_LOCK.md`)

Không có hạng mục **P0** nào trong vòng đánh giá này. Register gồm 7 hạng mục P1 và 1 hạng mục P2.

## 1. Trạng thái từng hạng mục

| ID | Sev | Trạng thái trước | Trạng thái sau | Bằng chứng trong repo | Còn cần gì ngoài repo |
|---|---|---|---|---|---|
| `DEV187-P1-01R` | P1 PARTIAL | Repo trace đã sửa, Jira chưa có artifact/closure | **CLOSED (repo side)** | `EVIDENCE_PACK.md` — exact SHA, danh sách file, mapping test ↔ `HN-IN-002` | Dán comment BA/BAS + QA1 (mục 3) |
| `DEV187-P1-02R` | P1 OPEN | Jira pin `m1-be-2`, remediation ở `m1`; không có PR/CI scoped | **CLOSED** | `WWHN-187_BRANCH_DISPOSITION.md` — `m1-be-2` là **ancestor thuần** của `m1`; `c14280` không tồn tại ref trong cả hai repo | Dán comment Tech Lead (mục 3) |
| `DEV187-P1-03R` | P1 OPEN | `WWHN-189` blocked, zero execution evidence | **CLOSED (gate chạy được)** | `WWHN-187_QA_EXECUTION_HANDOFF.md` + `scripts/verify-wwhn-187-closure.mjs` + collection Postman `WWHN-187 Inbound Scan Retry` | QA1 chạy tại exact SHA rồi dán kết quả |
| `DEV187-P1-04R` | P1 OPEN | `HN-MC-001` không có tài liệu bàn giao trong repo | **CLOSED** | `WWHN-187_MASTER_DATA_BASELINE.md` — baseline thay thế được pin theo SRS v1.12 + ERD, kèm ma trận rule → runtime → test | Dán comment BA + BAS (mục 3) |
| `DEV187-P1-05R` | P1 OPEN | Ngữ nghĩa audit của scan chưa có disposition; contract chưa khoá | **CLOSED** | `DEC-SCAN-AUDIT-001` + `WWHN-187_CONTRACT_LOCK.md` (hash OpenAPI/Postman + ma trận consumer) | Dán comment BA + Tech + FE + QA1 (mục 3) |
| `DEV187-P1-06` | P1 SOURCE CONFLICT | Parent `WWHN-42` còn ghi v3.9; governance nói v3.11 controls | **CLOSED** | `WWHN-187_SOURCE_TRACEABILITY_SUPERSESSION.md` — SRS v1.12 §Supersession ghi rõ **cả v3.9 lẫn v3.11 đều SUPERSEDED bởi BRD v3.20** | Sửa trường Source & Traceability của `WWHN-42` (mục 3) |
| `DEV187-P1-07` | P1 OPEN (NEW) | COMPONENT quantity-mode double-count sau mất response + re-fetch | **CLOSED (đủ hai nửa BE + FE)** | BE: `DEC-SCAN-IDEMP-001` (`ScanRequestFingerprint` + `IdempotencyStore` trên `inboundScan()`), FE: gửi Idempotency-Key theo từng hành động quét | Dán comment BA + Tech + QA1 + FE (mục 3) |
| `DEV187-P2-01` | P2 | Description còn narrative BE2/Duy Khánh, thực tế là DEV/Quang | **CLOSED (khuyến nghị)** | Mục 4 dưới đây | Sửa metadata Jira, giữ lịch sử |

## 2. `DEV187-P1-07` — hai nửa của một defect, và vì sao thiếu nửa nào cũng chưa đóng

Defect được reviewer xác nhận: cùng một hành động quét COMPONENT quantity-mode được
chấp nhận hai lần sau khi client mất response rồi tải lại phiếu; `scanned_qty` 2 → 4.

**Vì sao `If-Match` không đủ.** `If-Match` phát hiện được replay *cũ* (client gửi lại
version đã lỗi thời → 412 `STALE_VERSION`). Nó không phát hiện được replay *mới*:
client tải lại, thấy version đã nhảy 1 → 2, tưởng lần quét trước trượt, gửi lại đúng
hành động đó kèm version 2 hợp lệ. Server không có căn cứ nào để phân biệt với một lần
quét thật thứ hai.

**Vì sao chỉ nhánh COMPONENT quantity-mode thủng.** Nhánh PRODUCT/serial luôn resolve
được `item_id`, nên `assertNoDuplicateItemInDocument()` chặn tuyệt đối (409
`DUPLICATE_ITEM_IN_DOCUMENT`). Nhánh COMPONENT quantity-mode không có `item_id` để bám.
Outbound cũng không thủng vì `PRODUCT_SKU_REQUIRED` chặn COMPONENT ngay từ đầu.

**Nửa BE (`DEC-SCAN-IDEMP-001`).** `Idempotency-Key` là **tùy chọn** trên endpoint scan
— Annex C dòng `scanInboundDocument` chỉ khai `If-Match`, và scan là stock-neutral nên
không thuộc phạm vi bắt buộc idempotency của `NFR-SRS-001`; thiếu header **không** trả
428. Khi có key, request hash là một fingerprint tất định (`ScanRequestFingerprint`)
gồm `line_id`/`quantity`/`code_value`/`item_id`/`container_id` — **không chứa version,
không chứa mốc thời gian**, nên một retry sau re-fetch vẫn ra đúng fingerprint cũ và
được replay.

**Nửa FE (task này).** Nửa BE chỉ có hiệu lực khi client thật sự gửi key. Chưa có nửa
FE thì defect vẫn sống nguyên trên production: giao diện quét không gửi key nào, nên BE
đi đúng nhánh "không có key → giữ nguyên hành vi cũ". Bốn thay đổi FE:

1. `scanInboundDocument()` luôn gửi `Idempotency-Key`, tách theo chỉ số mã
   (`<key>:0`, `<key>:1`, …) vì mỗi mã trong một lần gọi là một hành động quét khác
   nhau — dùng chung một key sẽ khiến mã thứ hai bị 409 `IDEMPOTENCY_CONFLICT`.
2. Màn hình quét giữ key của **hành động đang dở** (`scanKeyRef`) kèm dấu vân tay nội
   dung. Gửi lại chỉ dùng lại key cũ khi lần sau đúng là *cùng hành động*: thua race đã
   phân loại `RELOAD_THEN_RETRY` (412 `STALE_VERSION` / 409 `CONCURRENCY_CONFLICT`),
   hoặc lỗi **không có HTTP status** (mất mạng, timeout — đúng kịch bản mất response).
   Lỗi nghiệp vụ có status thì sinh key mới, tránh 409 `IDEMPOTENCY_CONFLICT` oan.
3. Request scan đi qua `withConcurrencyContract` để 412/409 trở thành hướng dẫn
   "tải lại rồi thử lại bằng đúng key cũ" theo `AC-GWT-CONC-001`, thay vì một lỗi đỏ
   chung khiến người dùng bấm quét lại.
4. `replayed=true` được hiển thị tường minh ("đã ghi nhận trước đó, không cộng thêm") và
   **không** cộng vào bản sao `scanned_qty` cục bộ — nếu cộng, guard "vượt Expected" ở
   vòng lặp sau sẽ chặn nhầm một mã hợp lệ.

**Biên còn lại, đã ghi nhận có chủ đích.** Client **không** gửi key vẫn cộng dồn như cũ
(Postman thủ công, script QA cũ, mobile chưa cập nhật). Đây là ràng buộc của chính
`DEC-SCAN-IDEMP-001`: không có key thì server không có căn cứ phân biệt với một lần
quét thật thứ hai — và hai lần quét thật liên tiếp cùng nội dung là nghiệp vụ hợp lệ
của COMPONENT quantity-mode (`Wwhn188ScanIdempotencyContractTest::test_different_key_same_payload_is_a_genuine_second_scan`).
Đề xuất chặn trùng khít ở tầng dữ liệu đã được **cân nhắc và bác bỏ** vì nó sẽ phá đúng
ca nghiệp vụ hợp lệ đó. Vì vậy điều kiện đóng là: **mọi consumer chính thức phải gửi
key** — đã khoá bằng ma trận consumer ở `WWHN-187_CONTRACT_LOCK.md` và bằng test hợp
đồng FE.

## 3. Việc còn lại nằm ngoài repo

| Ai | Làm gì | Đóng ID nào |
|---|---|---|
| BA/BAS | Duyệt baseline master data thay thế `HN-MC-001`, duyệt `DEC-SCAN-AUDIT-001`, duyệt ngữ nghĩa retry | `P1-01R`, `P1-04R`, `P1-05R`, `P1-07` |
| Tech Lead | Duyệt disposition nhánh + ngữ nghĩa retry + contract lock | `P1-02R`, `P1-05R`, `P1-07` |
| QA1 | Chạy bộ gate tại exact SHA, dán kết quả (bao gồm ca retry quantity) | `P1-01R`, `P1-03R`, `P1-07` |
| FE1 | Xác nhận đã consume đúng revision contract | `P1-05R`, `P1-07` |
| PM + BA | Sửa Source & Traceability của `WWHN-42`; dọn metadata description | `P1-06`, `P2-01` |

## 4. Phát hiện mới, chủ sở hữu ngoài WWHN-187

Trong lúc khoá contract, verifier bắt được một lệch thật giữa contract công bố và hành vi
runtime. Ghi ở đây để nó không trôi mất giữa hai task — **không** gộp vào register của
WWHN-187 vì sửa nó nằm ngoài blast radius của task này.

| ID | Sev đề xuất | Nội dung | Chủ sở hữu đề xuất |
|---|---|---|---|
| `HN-OPENAPI-IFMATCH-001` | P2 | `storage/api-docs/api-docs.json`: 52/58 operation khai `If-Match` publish `required: false` dù runtime trả 428 khi thiếu; chỉ 6 operation viết tay bằng OA attribute là đúng. Alias canonical `POST /inbound-documents/{id}/scans` (FE đang gọi) nằm trong nhóm sai | `WWHN-309` / `WWHN-266` — artifact OpenAPI |

Cách phân biệt "artifact stale" và "logic regress", cùng lý do WWHN-187 không tự sửa:
xem `WWHN-187_CONTRACT_LOCK.md` mục 7. Ảnh hưởng tới WWHN-187: **không có** — runtime
đúng, test hợp đồng phủ đủ, FE/QA đều đã gửi `If-Match`.

## 5. `DEV187-P2-01` — dọn metadata (không chặn)

Description của `WWHN-187` mô tả người chịu trách nhiệm chính là "BE2 — Duy Khánh
Nguyễn", trong khi thực thi hiện hành là DEV/Quang. Khuyến nghị: **giữ nguyên narrative
lịch sử** (nó là bằng chứng phân công tại thời điểm tạo task) và bổ sung một dòng
"Assignee hiện hành" ở đầu phần Vai trò, thay vì viết đè. Trường `Assignee Placeholder:
HN-BE-02` và `Role: BE2` phải giữ nguyên để không đứt traceability với `HN-IN-002`.
