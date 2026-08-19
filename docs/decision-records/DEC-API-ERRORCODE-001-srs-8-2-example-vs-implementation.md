# DEC-API-ERRORCODE-001 — SRS §8.2 là bảng ví dụ minh hoạ, không phải enum kiệt liệt

| Trường | Giá trị |
|---|---|
| ID | `DEC-API-ERRORCODE-001` |
| Ticket nguồn | WWHN-268 (finding `BE268-D02`), mở rộng bởi WWHN-267 (`DIV-401-01`, `DIV-403-01`, `DIV-422-01`, `DIV-500-01`) |
| Vòng review | WWHN-268 round 2 — finding `FE268-R2-P1-05` |
| Ngày đề xuất | 2026-08-19 |
| Trạng thái | **PROPOSED — cần sign-off BA + Tech Lead trên Jira, không được coi là APPROVED trước khi có comment** |
| Phạm vi | Toàn bộ mã lỗi 401/403/422/500 phát sinh từ `App\Traits\ApiResponse` và các controller nghiệp vụ |
| Repository | `WMS_Warehouse_Hoa_Nam_BE` + `WMS_Warehouse_Hoa_Nam_FE`, nhánh `m1` |
| Không đổi contract | Đề xuất này **không đổi** một mã lỗi/status nào trong runtime, OpenAPI, hay FE. Nó chỉ chốt cách đọc §8.2. |

## 1. Vấn đề

`docs/evidence/WWHN-267/HTTP_ERROR_CODE_PAIRING.json` đo thực tế: 247 mã lỗi
trải trên 12 HTTP status trong khi SRS §8.2 chỉ liệt kê 9 status với 1-4 mã ví
dụ mỗi hàng. Bốn khác biệt tên gọi còn mở, tất cả đang `OPEN_NEEDS_BA_DECISION`:

| ID | Status | §8.2 nêu | Cài đặt thật |
|---|---|---|---|
| `DIV-401-01` | 401 | `AUTH_REQUIRED` / `TOKEN_EXPIRED` | `UNAUTHENTICATED` |
| `DIV-403-01` | 403 | `PERMISSION_DENIED` / `DATA_SCOPE_DENIED` | `ACCESS_DENIED` (mặc định) + `PERMISSION_DENIED` + `DATA_SCOPE_DENIED` + `WAREHOUSE_SCOPE_DENIED` |
| `DIV-422-01` | 422 | `VALIDATION_ERROR` / `BUSINESS_RULE_VIOLATION` / `INSUFFICIENT_STOCK` | `BUSINESS_RULE_ERROR` (mặc định) + các mã miền cụ thể |
| `DIV-500-01` | 500 | `INTERNAL_ERROR` | `INTERNAL_SERVER_ERROR` + `OUTBOUND_MATCH_MISSING` |

Ba ticket liên tiếp (WWHN-268, WWHN-266/267, và nay vòng 2 của WWHN-268) đều bị
review đánh dấu "chưa chốt", vì chưa có ai đọc lại nguyên văn §8.2 để hỏi: bảng
đó có **định nghĩa** tập mã hợp lệ, hay chỉ **minh hoạ**?

## 2. Căn cứ — đọc nguyên văn §8.2

`docs/HN_SRS_FRD_v1.12_FULL.md:536` — tiêu đề cột thứ hai của bảng §8.2 là:

> **"Error code examples"**

không phải "Error codes" hay "Allowed error codes". Đây là lựa chọn từ ngữ có
chủ đích của tài liệu, không phải sơ suất — toàn bộ 9 hàng của bảng chỉ liệt kê
1-4 mã mỗi hàng trong khi cột thứ ba ("Use / mandatory no-write behavior") mới
là phần bắt buộc máy-kiểm-được (ví dụ: "State, stock and movement unchanged").

Hàng cuối bảng (`docs/HN_SRS_FRD_v1.12_FULL.md:547`), hàng **Envelope**, ghi rõ
nguồn thật của ràng buộc:

> "code, message, request_id, timestamp, field_errors[], details; **HTTP and
> business code must match Annex/API contract**."

Tức là: điều bắt buộc là *HTTP status phải khớp với business code theo đúng
Annex/API contract* — không phải "business code phải là một trong các chuỗi ví
dụ ở cột 2". "Annex/API contract" trỏ tới Annex C (API contract) + OpenAPI +
`docs/api-contract/HN-BE-API-002_ERROR_CODE_CATALOG.json`, tức đúng bộ máy-đọc-
được mà WWHN-267 đã dựng (`HTTP_ERROR_CODE_PAIRING.json`).

**Tiền lệ đã có sẵn trong repo cho đúng cách đọc này:** `DIV-409-01` (409:
`STATE_CONFLICT`/`IDEMPOTENCY_CONFLICT`/`CONCURRENCY_CONFLICT`/
`DOWNSTREAM_DEPENDENCY_EXISTS` so với `App\Support\ConflictCode` + nhiều mã
miền cụ thể) đã được `CLOSED_BY_WWHN-234` với đúng lập luận: "mã miền là biến
thể chi tiết của lớp canonical, không phải vi phạm". Bốn khác biệt còn lại
(401/403/422/500) có hình dạng giống hệt — chỉ khác là chưa ai chính thức chốt.

## 3. Đề xuất

**Không đổi tên bất kỳ mã lỗi nào.** Chốt cách đọc: cột "Error code examples"
của §8.2 là **ví dụ minh hoạ không kiệt liệt (illustrative, non-exhaustive)**;
hợp đồng bắt buộc là **status đúng** + **envelope đủ trường** + **no-write khi
lỗi**, còn tập mã hợp lệ thật nằm ở tầng máy-đọc-được:
`HN-BE-API-002_ERROR_CODE_CATALOG.json` + OpenAPI +
`HTTP_ERROR_CODE_PAIRING.json` (`reservedPairings` khoá đúng 18 mã §8.2 vào
đúng status của chúng — nếu ai đó phát `AUTH_REQUIRED` ở status khác 401 thì
cổng `Wwhn267ContractClosureTest` vẫn đỏ).

| # | Khác biệt | Đề xuất disposition |
|---|---|---|
| 1 | `DIV-401-01` — `UNAUTHENTICATED` | `ACCEPTED_ILLUSTRATIVE_EXAMPLE` — giữ nguyên; FE nhận diện theo HTTP 401 |
| 2 | `DIV-403-01` — `ACCESS_DENIED` mặc định | `ACCEPTED_ILLUSTRATIVE_EXAMPLE` — giữ nguyên; FE267-D06 đã gom `isNoPermissionError()` theo `FORBIDDEN_ERROR_CODES` (HTTP 403), không theo chuỗi mã |
| 3 | `DIV-422-01` — `BUSINESS_RULE_ERROR` mặc định + mã miền | `ACCEPTED_ILLUSTRATIVE_EXAMPLE` — giữ nguyên; FE hiển thị message + `field_errors[]`, không rẽ nhánh theo tên mã mặc định |
| 4 | `DIV-500-01` — `INTERNAL_SERVER_ERROR` + `OUTBOUND_MATCH_MISSING` | `ACCEPTED_ILLUSTRATIVE_EXAMPLE` — giữ nguyên; `OUTBOUND_MATCH_MISSING` là guard bất biến nội bộ, giữ tên riêng để log truy được |

## 4. Vì sao không đổi mã (thay vì đổi mã cho khớp §8.2)

1. **Chi phí đổi ngược rất lớn, lợi ích không tương xứng.** Đổi `ACCESS_DENIED`
   → `PERMISSION_DENIED` chạm 41+ endpoint (đo ở WWHN-266), OpenAPI, error
   catalog, Postman, và mọi test đã khoá theo tên mã hiện tại — trong khi hành
   vi runtime (status, envelope, no-write) đã đúng 100%.
2. **Không có defect chức năng.** Không ca nào trong `Wwhn267ContractClosureTest`
   hay `Wwhn268RegressionContractTest` phát hiện sai status, sai envelope, hay
   ghi tồn khi lỗi. Khác biệt duy nhất là **tên chuỗi**.
3. **Kiểm soát bù đã tồn tại và được test khoá.** FE nhận diện theo HTTP status
   (`isNoPermissionError`, `isRateLimitedError`, …), không theo chuỗi mã — nên
   việc §8.2 dùng tên ví dụ khác không tạo ra hành vi sai ở FE.
4. **Đồng dạng với tiền lệ đã đóng.** Giữ cách xử lý nhất quán với `DIV-409-01`
   (`CLOSED_BY_WWHN-234`) thay vì áp hai tiêu chuẩn khác nhau cho hai nhóm status
   giống hệt nhau về bản chất.

## 5. Nếu BA/Tech Lead không đồng ý (phương án B)

Nếu BA xác định cột "Error code examples" **phải** được đọc như enum kiệt
liệt, đây là đổi contract thật, cần CR riêng vì phải sửa đồng bộ: OpenAPI (4+
schema), `HN-BE-API-002_ERROR_CODE_CATALOG.{md,json}`, toàn bộ test khoá theo
tên mã hiện tại (đếm được qua `grep -rl "ACCESS_DENIED\|UNAUTHENTICATED\|BUSINESS_RULE_ERROR\|INTERNAL_SERVER_ERROR"`),
Postman, và FE (`FORBIDDEN_ERROR_CODES` cùng các hằng tương đương). Phương án
B **không** được tự triển khai trước khi có CR — đúng nguyên tắc đã áp dụng
cho `DISP-STK-230` (Option A bị loại vì DEV/QA không có thẩm quyền tạo căn cứ
cho một thay đổi baseline).

## 6. Sign-off cần ghi trên Jira

```text
DEC-API-ERRORCODE-001 version: 1.0.0
Option đề xuất: giữ nguyên mã cài đặt hiện tại; §8.2 = ví dụ minh hoạ không kiệt liệt
Controlling text: SRS §8.2 tiêu đề cột "Error code examples" + hàng Envelope
  ("HTTP and business code must match Annex/API contract")
BA: <APPROVE Option đề xuất | YÊU CẦU Option B (đổi mã, cần CR) | ngày / comment URL>
Tech Lead: <APPROVE | NEEDS CHANGES / ngày / comment URL>
Exact BE commit SHA tại thời điểm sign-off: <sha>
```

Cho tới khi có comment ở trên, `docs/evidence/WWHN-267/HTTP_ERROR_CODE_PAIRING.json`
**giữ nguyên** `disposition: "OPEN_NEEDS_BA_DECISION"` ở bốn dòng liên quan —
chỉ thêm trường `proposedResolutionRef` trỏ tới tài liệu này, không tự đổi
sang trạng thái đã duyệt.

## 7. Liên quan

- `docs/evidence/WWHN-267/HTTP_ERROR_CODE_PAIRING.json` — số liệu đo + 4 divergence
- `docs/decision-records/DEC-OUT-STOCK-HTTP-001-insufficient-stock-http-status.md` — tiền lệ cách viết Decision Record cho tranh chấp §8.2
- WWHN-234 — tiền lệ `DIV-409-01` `CLOSED_BY_WWHN-234` (mã miền là biến thể của lớp canonical)
- `src/shared/api/api-error.ts` (FE) — `FORBIDDEN_ERROR_CODES`, `isNoPermissionError()` (kiểm soát bù theo HTTP status)
