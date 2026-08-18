# DEC-WAR-STATE-001 — Ma trận trạng thái, guard nghiệp vụ và ràng buộc one-active-case của luồng bảo hành

| Trường | Giá trị |
|---|---|
| Mã quyết định | `DEC-WAR-STATE-001` |
| Ngày lập | 18/08/2026 |
| Bản sửa đổi | Rev.1 — 18/08/2026 |
| Phạm vi | `POST /api/v1/warranty-cases`, `POST /api/v1/warranty-cases/{id}/status` (alias `PATCH /api/v1/warranty-cases/{id}`), bảng `warranty_case` / `warranty_event` |
| Ticket | WWHN-244 (BE), WWHN-245 (QA) |
| Đóng mục review | **DEV244-P1-02**, **DEV244-P1-05**, **DEV244-P1-06** |
| Nguồn sự thật nghiệp vụ | SRS/FRD v1.12 §6 (bảng trạng thái), §13.8 (privacy), ERD `HoaNam_ERD_Moi_Nhat.md` §45–46 |
| Nguồn sự thật kỹ thuật | `CurrentBaselineWarrantyController::TRANSITIONS` + `REQUIRES_DIAGNOSIS` |
| Người duyệt | BA + Tech Lead (comment sign-off trên WWHN-244) |

---

## 1. Vấn đề

SRS v1.12 §6 và ERD §45 **liệt kê** sáu trạng thái `RECEIVED, CHECKING, REPAIRING,
COMPLETED, RETURNED, CANCELLED` nhưng **không phát biểu ma trận transition**, cũng không
nói điều kiện nào phải thoả trước khi đổi trạng thái. Hệ quả trước bản này:

1. `CHECKING → COMPLETED` bị code chặn (409) mà không có văn bản nào quyết định như vậy —
   register DEV244-P1-02 nêu đúng điểm này là "chưa chốt".
2. Không có guard nghiệp vụ nào: một case đi từ `CHECKING` thẳng tới `RETURNED` (qua
   `REPAIRING`/`COMPLETED`) mà **không cần ghi bất kỳ kết luận kiểm tra, kết quả xử lý
   hay bằng chứng bàn giao nào**. Case đóng lại với `confirmed_defect = NULL`.
3. OpenAPI công bố operation `transitionWarrantyStatus` với `requestBody` rỗng
   (`additionalProperties: true`), nên FE/QA không thể đọc được ma trận từ contract —
   mọi bên tự suy diễn, và đó là gốc của "status enum mismatch" giữa FE và BE.
4. Ràng buộc "một active case trên mỗi item" của ERD §45 chỉ được tạo trong nhánh
   `pgsql` của migration, nên toàn bộ test suite chạy SQLite **không có ràng buộc nào**:
   suite xanh không chứng minh gì về production.

## 2. Quyết định — ma trận transition

| Từ | Được phép sang |
|---|---|
| `RECEIVED` | `CHECKING`, `CANCELLED` |
| `CHECKING` | `REPAIRING`, **`COMPLETED`**, `CANCELLED` |
| `REPAIRING` | `COMPLETED`, `CANCELLED` |
| `COMPLETED` | `RETURNED`, `CANCELLED` |
| `RETURNED` | — (terminal) |
| `CANCELLED` | — (terminal) |

**`CHECKING → COMPLETED`: CHO PHÉP.** Lý do: case kiểm tra xong kết luận không cần sửa
(máy bình thường, lỗi thao tác người dùng, phụ kiện sai) là tình huống thực tế thường
gặp. Nếu ép đi qua `REPAIRING` giả thì (a) `REPAIRING` mất nghĩa, (b) mọi metric
"turnaround đã sửa chữa" của `RPT-WAR-01` bị thổi phồng bằng các case chưa từng sửa,
(c) lịch sử `warranty_event` ghi một sự kiện `REPAIR` không có thật. Nguy cơ "đóng case
mà bỏ qua bước kiểm tra" được xử lý bằng guard 1 dưới đây, không bằng cách bịt đường đi.

**Terminal.** `RETURNED`/`CANCELLED` không nhận **bất kỳ** mutation nào, kể cả request
không kèm `status` (nếu chỉ chặn transition thì vẫn sửa được `confirmed_defect`/PII trên
case đã đóng) → `409 STATE_CONFLICT`.

Mọi cặp `(from, to)` không có trong bảng → `409 INVALID_WARRANTY_TRANSITION`, state
không đổi, version không tăng.

## 3. Quyết định — guard nghiệp vụ

| # | Guard | Điều kiện | Mã lỗi | Trường ERD dùng |
|---|---|---|---|---|
| 1 | Chẩn đoán | Rời `CHECKING` sang `REPAIRING`/`COMPLETED` phải có `confirmed_defect` (payload lần này hoặc đã lưu trước đó) | `422 WARRANTY_DIAGNOSIS_REQUIRED` | `warranty_case.confirmed_defect` — ERD §45 "Kết quả kiểm tra" |
| 2 | Kết quả xử lý | Vào `COMPLETED` phải có `note` mô tả đã xử lý gì | `422 WARRANTY_TREATMENT_RESULT_REQUIRED` | `warranty_event.note` — §13.8 (KHÔNG đè `description` tiếp nhận) |
| 3 | Bàn giao / người nhận | Vào `RETURNED` phải có `note` biên bản bàn giao | `422 WARRANTY_HANDOVER_NOTE_REQUIRED` | `warranty_event.note` + `created_by` |
| 4 | Lý do huỷ | Vào `CANCELLED` phải có lý do | `422 CANCEL_REASON_REQUIRED` | `warranty_case.cancel_reason` |

**Vì sao guard 3 không dùng cột `receiver` riêng.** Register nhắc "receiver guard", nhưng
ERD §45 **không có** cột người nhận và ERD là baseline đã ký. Thêm cột = sửa ERD = mở lại
một baseline khác. Bằng chứng bàn giao vì vậy được ghi ở đúng chỗ ERD đã dành cho nó:
một `warranty_event` loại `RETURN` với `note` bắt buộc và `created_by` = actor thực hiện.
Nếu sau này BA quyết định cần trường có cấu trúc (tên/CMND/chữ ký người nhận), đó là một
amendment ERD riêng, không phải thay đổi âm thầm trong code.

## 4. Quyết định — one-active-case là ràng buộc tầng DB trên MỌI driver

ERD §45: *"Một active case/item (not RETURNED/CANCELLED)"* + *"partial UX(item_id) WHERE
active"*. Guard trong controller (`lockForUpdate` + `whereNotIn`) là **chưa đủ**: nó không
che job/seeder/import ghi thẳng vào bảng, và không che hai request song song trước khi
khoá hàng kịp có hiệu lực.

Migration `2026_08_18_000100_enforce_warranty_one_active_case_all_drivers.php` tạo partial
unique index `uk_warranty_case_one_active_item` trên **cả `pgsql` và `sqlite`** (SQLite hỗ
trợ partial index từ 3.8.0). Trước đó index chỉ tồn tại trong nhánh `pgsql` của
`2026_08_10_000620` / `000710`, nên suite mặc định (SQLite) xanh mà không kiểm tra gì.

Điều kiện "active" chốt theo ERD: `item_id IS NOT NULL AND status NOT IN ('RETURNED','CANCELLED')`.
Bản này **không** đổi định nghĩa active — `COMPLETED` vẫn là active (máy còn ở kho, chưa trả khách).

## 5. Công bố contract (DEV244-P1-05)

`transitionWarrantyStatus` nay được annotate trực tiếp trên
`CurrentBaselineWarrantyController::updateWarrantyCase()`, và
`config/l5-swagger.php` được bổ sung đường dẫn controller này vào danh sách `annotations`
— thiếu dòng đó thì `RouteSyncProcessor` tự sinh skeleton `requestBody {}` rỗng và toàn bộ
quyết định trên đây không xuất hiện trong OpenAPI. Contract nay công bố:

- `status` enum đủ 6 giá trị;
- ma trận transition trong `description`;
- bốn mã lỗi guard + `INVALID_WARRANTY_TRANSITION` + `STATE_CONFLICT`;
- `confirmed_defect` / `note` / `cancel_reason` là property có mô tả điều kiện bắt buộc;
- điều kiện `warranty.pii.view` cho ba trường PII.

Workflow `warranty-readiness.yml` có bước **"Verify DEC-WAR-STATE-001 is published in the
contract"** đọc lại `storage/api-docs/api-docs.json` và fail nếu bất kỳ mục nào ở trên
biến mất — để tài liệu này và OpenAPI không thể trôi khỏi nhau lần nữa.

## 6. Bằng chứng thực thi

| Điều khoản | Test |
|---|---|
| §2 `CHECKING → COMPLETED` được phép | `test_checking_to_completed_is_allowed_with_diagnosis_and_treatment_result` |
| §2 mọi cạnh ngoài ma trận → 409 | `test_full_transition_matrix_rejects_every_undeclared_edge` |
| §2 terminal bất biến | cùng test trên (nhánh `STATE_CONFLICT`) |
| §3 guard 1 | `test_leaving_checking_without_diagnosis_is_rejected` |
| §3 guard 2 và 3 | `test_completed_and_returned_require_treatment_and_handover_evidence` |
| §4 ràng buộc DB | `test_second_active_case_for_same_item_is_blocked_at_database_level` |
| §4 giải phóng sau khi đóng | `test_closed_case_releases_item_for_a_new_warranty_case` |
| §4 job PG không giả mạo driver | `test_suite_runs_on_the_driver_the_pipeline_claims` |

Tất cả nằm trong `tests/Feature/Wwhn244WarrantyStateMatrixTest.php`, chạy ở cả hai job của
`.github/workflows/warranty-readiness.yml` (SQLite và PostgreSQL 16 thật).

## 7. Ảnh hưởng FE

`src/modules/warranty/constants.ts` — `WARRANTY_NEXT_STATUS` khớp 1-1 §2;
`WARRANTY_DIAGNOSIS_REQUIRED_TARGETS` và `WARRANTY_NOTE_REQUIRED_LABELS` khớp §3 nên form
chặn tại chỗ thay vì để BE trả 422 sau mỗi lần submit.
