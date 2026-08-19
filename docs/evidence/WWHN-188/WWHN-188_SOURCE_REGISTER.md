# WWHN-188 / WWHN-42 — Source Register & Supersession

| Trường | Giá trị |
|---|---|
| Review item đóng | `DEV188-P1-06R2` (P1, OPEN) |
| Yêu cầu nguyên văn | *"BA/BAS pin current controlling inbound/master-data source và formal supersession của v3.9/Handoff v3.9 nếu không còn authoritative"* |
| Owner ký | **BA/BAS** (nội dung nguồn) + **PM** (hiệu lực) |
| Người soạn | BE1 — DEV **không** sửa và **không** tự tuyên bố hiệu lực cho bất kỳ tài liệu baseline nào |
| Closure evidence yêu cầu | Source Register + exact attachment/version/hash/effective date/Decision |

---

## 1. Sai lệch được ghi nhận

Task cha `WWHN-42` (HN-IN-002 — *[INBOUND] Quét/ghi nhận item & component serial/quantity*) vẫn khai
nguồn theo **BRD v3.9 / Handoff v3.9**. Toàn bộ gói closure của `WWHN-188` lại được đối chiếu với
baseline hiện hành **BRD v3.20 + SRS/FRD v1.12**. Hai giá trị khác nhau ⇒ mắt đầu tiên của chuỗi truy
vết không khớp, và mọi kết luận phía sau đều bị đặt câu hỏi "đối chiếu với bản nào?".

Đây **không** phải mâu thuẫn nội dung: bản thân baseline hiện hành đã tuyên bố v3.9 là bản cũ. Thứ
đang thiếu là **cập nhật trường nguồn trên Jira** và một bản ghi supersession có chủ sở hữu.

## 2. Nguồn kiểm soát hiện hành (trích nguyên văn baseline, không diễn giải lại)

| Loại nguồn | Giá trị hiện hành | Căn cứ |
|---|---|---|
| Business source | **BRD Hoa Nam v3.20** (`HN-BRD-WM-PV v3.20`) | SRS/FRD v1.12 §0.1 *Source documents*; §1.3 Source Registry `SRC-002` |
| Requirement source | **SRS/FRD v1.12** (`HN-SRS-FRD-001`, cập nhật 14/08/2026) | SRS/FRD v1.12 §0.1 Document Control |
| Contract companion | **HN_OpenAPI_v1_7.yaml** (OpenAPI `1.7.0-ba`, 67 normative operations) | SRS/FRD v1.12 §1.6 và §P0-06 |
| Screen baseline | Wireframe MVP ALIGNED **v3.20 / v1.12** (14/08/2026) | `docs/HN_WMS_Wireframe_MVP_ALIGNED_v3.20_v1.12_20260814.html` |
| Data baseline | ERD/DD hiện hành | `docs/HoaNam_ERD_Moi_Nhat.md` |
| Kỹ thuật/kiến trúc | Techstack & Architecture Dev Guide hiện hành | `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md` |

## 3. Supersession — đã có sẵn trong baseline, chỉ cần ghi nhận

SRS/FRD v1.12 §1.6 *Supersession Register* có đúng dòng cần thiết:

| Old source/statement | New controlling source/decision | Status |
|---|---|---|
| BRD v3.7 | BRD v3.20 | HISTORICAL / SUPERSEDED |
| **BRD v3.9** | **BRD v3.20** | **HISTORICAL / SUPERSEDED** |
| BRD v3.10 / v3.11 / v3.15 | BRD v3.20 | HISTORICAL / SUPERSEDED |
| Các SRS/FRD revision trước | SRS/FRD v1.12 | SUPERSEDED |

⇒ **Không cần quyết định mới.** BA/BAS chỉ cần *ghi nhận* điều baseline đã tuyên bố, vào đúng trường
`Source & Traceability` của `WWHN-42` và `WWHN-188`. Cùng kết luận đã được áp dụng cho `WWHN-77` ở
review item `DEV290-P1-06` — dùng lại nguyên tắc đó, không tạo authority thứ hai.

## 4. Neo yêu cầu của riêng luồng inbound scan (phần WWHN-188 thực sự đối chiếu)

| Neo | Nội dung | Ý nghĩa với WWHN-188 |
|---|---|---|
| SRS §8.1 / Annex C — dòng `Inbound` | *"Create/update expected_qty/scan/cancel are **stock-neutral**; Full-document Post uses **Idempotency-Key + If-Match**"* | Scan **không** bị baseline bắt buộc `Idempotency-Key`; vì vậy `DEC-SCAN-IDEMP-001` chọn optional thay vì 428 khi thiếu header |
| SRS §8.2 — bảng mã lỗi | 409 chỉ được là `STATE_CONFLICT` / `IDEMPOTENCY_CONFLICT` / `CONCURRENCY_CONFLICT` / `DOWNSTREAM_DEPENDENCY_EXISTS` | Mã 409 mới thêm (`IDEMPOTENCY_CONFLICT`, `CONCURRENCY_CONFLICT`) nằm đúng trong tập cho phép |
| `NFR-SRS-001` | *"All stock-changing actions idempotent"* | Scan là stock-neutral nên **không** thuộc phạm vi bắt buộc của NFR này; việc thêm idempotency cho scan là **siết chặt hơn** baseline, không phải lệch baseline |
| `REQ-SRS-024` / `BR-API-ERR-01..09` | HTTP/error/idempotency/security contract 400/401/403/404/409/412/422/428 | Ma trận QA (`WWHN-188_QA_TEST_MATRIX.md`) phải phủ đủ bộ mã này cho endpoint scan |
| `AC-GWT-CONC-001` | *"UI request thua hiển thị refresh/retry, không tự submit lại bằng key mới"* | Ràng buộc phía FE của `DEC-SCAN-IDEMP-001`: retry **phải** dùng lại đúng key cũ |
| `REQ-SRS-008` / `BR-IDEMP-01..03` | Post Receipt idempotent/atomic | Chuẩn tham chiếu cho cách hành xử replay (`replayed=true`) mà scan nay tái sử dụng |

## 5. Việc BA/BAS + PM cần làm (chỉ Jira, không sửa file baseline)

| # | Hành động | Nơi thực hiện | Owner |
|---|---|---|---|
| 1 | Sửa trường nguồn `BRD v3.9 / Handoff v3.9` → `BRD v3.20` | `WWHN-42` | BA/BAS |
| 2 | Ghi nguồn requirement `SRS/FRD v1.12 (HN-SRS-FRD-001, 14/08/2026)` | `WWHN-42`, `WWHN-188` | BA/BAS |
| 3 | Ghi supersession `BRD v3.9 → BRD v3.20 (HISTORICAL/SUPERSEDED, theo SRS v1.12 §1.6)` | comment `WWHN-42` | BA/BAS |
| 4 | Ghi mốc phê duyệt/hiệu lực: BRD tại `WWHN-333`, SRS tại `WWHN-334` (exact attachment/hash ghi tại chính hai ticket đó) | `WWHN-42` | PM |
| 5 | Xác nhận `DEC-SCAN-IDEMP-001` là decision hợp lệ cho phạm vi inbound scan | comment `WWHN-188` | BA + Tech Lead |

## 6. Giới hạn của tài liệu này

Tài liệu này **không** tuyên bố hiệu lực cho bất kỳ baseline nào. Trạng thái formal vẫn theo baseline:
BRD v3.20 chỉ effective khi `WWHN-333` duyệt đúng attachment/hash; SRS v1.12 chỉ effective khi
`WWHN-334` DONE. Nếu tại thời điểm ký hai mốc đó chưa đóng, BA/PM ghi rõ trạng thái *pending* thay vì
ghi *effective* — và điều đó **không** chặn closure kỹ thuật của WWHN-188, vì mọi kết luận kỹ thuật ở
đây đều đối chiếu với đúng nội dung SRS v1.12 đang lưu trong repo.
