# WWHN-188 — Trace & Supersession: đính chính attachment ATT-11852

| Trường | Giá trị |
|---|---|
| Review item đóng | `DEV188-P1-01R2` (P1, PARTIAL) |
| Yêu cầu nguyên văn | *"Supersede attachment stale; attach/pin corrected WWHN-188 evidence, explicitly WWHN-187=implementation, WWHN-188=concurrency. Map corrected repo blob/commit to Jira"* |
| Owner ký | **DEV** (nội dung repo) + **BA/PM** (hiệu lực attachment trên Jira) |
| Người soạn | BE1 — DEV **không** tự đánh dấu đã duyệt trên Jira |
| Closure evidence yêu cầu | New Jira evidence + exact commit/SHA + independent acknowledgement |

---

## 1. Sai lệch được ghi nhận

Attachment `ATT-11852` trên Jira `WWHN-188` là **bản cũ (stale)**: nó được đính kèm ở thời điểm
2026-08-15, khi `WWHN-188.md` còn mang giả định **sai** rằng `WWHN-187` và `WWHN-188` là **cùng một
task Jira** (hai số hiệu trùng nội dung). Giả định đó đã được bác bỏ ngày 2026-08-18 bằng cách đối
chiếu trực tiếp danh sách subtask của `WWHN-42` trên Jira live.

Hệ quả nếu không đính chính: người đọc `ATT-11852` sẽ hiểu evidence concurrency và evidence
implementation là **một**, nên không thể biết phần nào đã được review, phần nào chưa — chuỗi
`Contract Scope → Requirement → AC → TC → Jira evidence` đứt ngay ở mắt đầu tiên.

## 2. Phân định dứt điểm hai số hiệu (đây là điều cần ghi vào Jira)

| Jira key | Tiêu đề thật | Vai trò | Output | Tài liệu nguồn trong repo |
|---|---|---|---|---|
| `WWHN-187` | **[BE] Triển khai quét/ghi nhận/validation** | BE2 | *Inbound scan backend* | `docs/docs_task_jira/WWHN-187.md` |
| `WWHN-188` | **[DEV] Rà soát giao dịch/khóa đồng thời** | BE1 | *Kết quả rà soát đồng thời* | `docs/docs_task_jira/WWHN-188.md` |

Nói gọn để dán thẳng vào Jira:

> **WWHN-187 = implementation** (viết code quét/ghi nhận/validation).
> **WWHN-188 = concurrency** (rà soát giao dịch/khóa đồng thời của chính bước quét đó).
> Hai task khác nhau, cùng cha `WWHN-42` (HN-IN-002). Không phải trùng key.

Lịch sử của hiểu nhầm: nội dung task trên Jira ở thời điểm 2026-08-15 gần như giống hệt nhau, và bản
`WWHN-187.md` khi đó bị lưu nhầm dưới tên `WWHN-198-inbound-scan.md`, nên không có cách nào phân biệt
từ repo. Open point `BE1-DUPKEY-188-01` đã được đóng ngày 2026-08-18 sau khi đối chiếu Jira live.

## 3. Bản evidence đúng, gắn với đúng blob/commit

Bản thay thế `ATT-11852` là **gói evidence** dưới đây, không phải một file rời. Mọi giá trị blob SHA
và commit SHA thật được ghi bằng máy tại `WWHN-188_CANDIDATE_PIN.json` (mục `artifacts`), sinh ra
bằng `scripts/pin-wwhn-188-candidate.mjs` và kiểm lại được bằng
`node scripts/verify-wwhn-188-closure.mjs`.

| Vai trò | Đường dẫn trong repo |
|---|---|
| Evidence gốc của task | `docs/docs_task_jira/WWHN-188.md` |
| Quyết định thiết kế đóng defect | `docs/decision-records/DEC-SCAN-IDEMP-001-inbound-scan-request-idempotency.md` |
| Ma trận QA | `docs/evidence/WWHN-188/WWHN-188_QA_TEST_MATRIX.md` |
| Nguồn kiểm soát | `docs/evidence/WWHN-188/WWHN-188_SOURCE_REGISTER.md` |
| Bằng chứng race thật PostgreSQL | `docs/evidence/WWHN-188/runtime/runtime-concurrency-summary.json` + thư mục `runtime/runtime-concurrency/` (log thô, plan, mẫu `pg_locks`) |
| Bản trích OpenAPI + hash | `docs/evidence/WWHN-188/openapi/` |
| Pin candidate | `docs/evidence/WWHN-188/WWHN-188_CANDIDATE_PIN.json` |
| Sổ đóng review item | `docs/evidence/WWHN-188/WWHN-188_CLOSURE_REGISTER.md` |

**Không dùng floating HEAD.** Mọi tham chiếu ở trên đều đọc tại đúng commit ghi trong
`WWHN-188_CANDIDATE_PIN.json → candidate.commit_sha`; verifier có cờ `--expect-sha` để reviewer khoá
lại đúng bản mình đã đọc (xem `WWHN-188_CANDIDATE_PIN.json`).

## 4. Việc BA/PM cần làm trên Jira (chỉ cập nhật Jira, không sửa file repo)

| # | Hành động | Nơi thực hiện |
|---|---|---|
| 1 | Đánh dấu `ATT-11852` là **SUPERSEDED**, ghi lý do "bản 2026-08-15 mang giả định trùng key đã bị bác bỏ" | `WWHN-188` |
| 2 | Đính kèm gói evidence ở mục 3, ghi kèm **exact commit SHA** lấy từ `WWHN-188_CANDIDATE_PIN.json` | `WWHN-188` |
| 3 | Ghi vào trường mô tả/`Source & Traceability`: "WWHN-187 = implementation; WWHN-188 = concurrency" | `WWHN-187` và `WWHN-188` |
| 4 | Xin **independent acknowledgement** của QA1 và BE2 rằng hai key đã được hiểu đúng | comment trên `WWHN-188` |

DEV không thực hiện bước 1–4: đây là hành động trên Jira thuộc quyền BA/PM. Repo chỉ cung cấp nội dung
và giá trị SHA để dán.

## 5. Cái gì KHÔNG thay đổi

Toàn bộ kết luận kỹ thuật trong `WWHN-188.md` (transaction atomic, optimistic + row lock, state guard,
permission, error contract) **không đổi** vì việc đính chính số hiệu. Thứ duy nhất sai trước đây là
phần diễn giải "hai số hiệu trỏ cùng một task"; phần đó đã được sửa tại chỗ và đánh dấu rõ ngày sửa,
giữ nguyên câu chữ evidence lịch sử để không làm hỏng tính xác thực của các lần chạy trước.
