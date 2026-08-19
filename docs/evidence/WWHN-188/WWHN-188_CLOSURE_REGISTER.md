# WWHN-188 — Sổ đóng review item (vòng R2)

Task: `WWHN-188` — *[DEV] Rà soát giao dịch/khóa đồng thời* (HN-IN-002, BE1), cha `WWHN-42`.
Điểm vòng trước: **62/100**. Register này ánh xạ **từng dòng** của bảng R2 sang bằng chứng cụ thể.

Candidate/pin: `docs/evidence/WWHN-188/WWHN-188_CANDIDATE_PIN.json`
Verifier: `node scripts/verify-wwhn-188-closure.mjs [--expect-sha=<candidate.commit_sha>]`

---

## 1. Bảng đóng

| ID | Sev | Trạng thái R2 | Đã làm gì | Bằng chứng | Ai phải ký |
|---|---|---|---|---|---|
| `DEV188-P1-01R2` | P1 | PARTIAL | Lập hồ sơ supersede `ATT-11852`; phân định dứt điểm **WWHN-187 = implementation / WWHN-188 = concurrency**; ánh xạ blob+commit SHA thật cho từng artifact | `WWHN-188_TRACE_SUPERSESSION.md`, `WWHN-188_CANDIDATE_PIN.json → artifacts` | **BA/PM** (đính kèm + đánh dấu superseded trên Jira) + **QA1/BE2** (independent acknowledgement) |
| `DEV188-P1-02R2` | P1 | OPEN | Chạy race thật ≥2 kết nối/tiến trình PostgreSQL cho **4** kịch bản; bổ sung mốc thời gian thô, snapshot DB before/after, PostgreSQL version và SHA vào chính evidence; commit evidence vào repo (trước đây nằm trong `storage/` bị gitignore nên reviewer không đọc được) | `runtime/runtime-concurrency-summary.json` + `runtime/runtime-concurrency/**` (log thô, plan, mẫu `pg_locks`), gate `tests/Feature/InboundScanRuntimeConcurrencyTest.php` | **QA1** |
| `DEV188-P1-03R2` | P1 | OPEN — VERIFIED DEFECT | **Sửa defect**, không chỉ mô tả: request-level idempotency với fingerprint tất định (không dùng cửa sổ thời gian). Cập nhật OpenAPI + hash; FE đã gửi key theo cùng hợp đồng | `DEC-SCAN-IDEMP-001…md`, `app/Support/ScanRequestFingerprint.php`, `CurrentBaselineStockFlowController::inboundScan()`, `tests/Feature/Wwhn188ScanIdempotencyContractTest.php` (10 case), `openapi/stock_in_scan.*`, kịch bản race `same_idempotency_key_quantity_mode` | **BA + Tech Lead** (chốt semantics) |
| `DEV188-P1-04R2` | P1 | OPEN | Lập candidate QA phủ **đủ** ô reviewer liệt kê, mỗi ô trỏ đúng một test đang chạy được; kèm lệnh chạy và số liệu tại candidate | `WWHN-188_QA_TEST_MATRIX.md` | **QA1** |
| `DEV188-P1-05R2` | P1 | OPEN | Pin candidate **hai tầng** (commit SHA + blob SHA từng artifact), lấy commit **cuối cùng chạm artifact** thay vì floating `m1` HEAD; verifier có `--expect-sha` | `WWHN-188_CANDIDATE_PIN.json`, `scripts/pin-wwhn-188-candidate.mjs`, `scripts/verify-wwhn-188-closure.mjs`, workflow `.github/workflows/wwhn-188-scan-concurrency-gate.yml` | **DevOps** (CI run) + **QA1** |
| `DEV188-P1-06R2` | P1 | OPEN | Lập Source Register cho `WWHN-42`/`WWHN-188`: nguồn kiểm soát hiện hành + supersession `BRD v3.9 → v3.20` theo đúng SRS v1.12 §1.6, kèm neo yêu cầu riêng của luồng scan | `WWHN-188_SOURCE_REGISTER.md` | **BA/BAS** + **PM** |
| `DEV188-P2-01` | P2 | OPEN | Dọn sạch fixture `WWHN187-*` trong test concurrency của WWHN-188; verifier chặn tái phát | `tests/Feature/InboundScanConcurrencyBehaviorTest.php`, check `P2-01/no-stale-wwhn187-fixture-strings` | — (non-blocking) |

## 2. Thay đổi mã sản xuất trong vòng này

| File | Loại | Nội dung |
|---|---|---|
| `app/Support/ScanRequestFingerprint.php` | **Mới** | Fingerprint tất định cho request scan: chuẩn hoá alias `qty`/`quantity`, bỏ `printer_config_id`, **không** chứa version và **không** chứa thời gian |
| `Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php` | Sửa | `inboundScan()` đi qua `IdempotencyStore` với `IdempotencyKeyGuard::optional()`; trả `data.replayed` + header `Idempotent-Replay`; giữ nguyên `ETag` |
| `Modules/Warehouse/app/Http/Controllers/InboundDocumentController.php` | Sửa | Annotation OpenAPI `stock_in_scan`: thêm `Idempotency-Key` (optional, 8..100), mô tả replay, 409 liệt kê `IDEMPOTENCY_CONFLICT`/`CONCURRENCY_CONFLICT`, 200 mô tả `data.replayed` |

Không có migration, không đổi schema, không đụng `If-Match`/row lock/state guard. Client cũ **không**
bị phá: thiếu `Idempotency-Key` thì hành vi y như trước và **không** trả 428.

## 3. Điều KHÔNG tự nhận là đã xong

- Mọi hành động trên Jira (đính kèm, đánh dấu superseded, sửa trường nguồn, chuyển trạng thái) thuộc
  BA/PM/QA1 — DEV chỉ cung cấp nội dung và SHA để dán.
- `DEC-SCAN-IDEMP-001` đang ở trạng thái **PROPOSED**. Code đã hiện thực đúng nội dung đó và revert
  được nguyên khối nếu BA/Tech Lead chọn phương án khác.
- Nửa FE của `DEV188-P1-03R2` (gửi `Idempotency-Key` mỗi hành động quét, giữ nguyên key khi retry,
  hiển thị `replayed`) nằm ở `src/modules/inbound/services/inboundService.ts` thuộc `WWHN-187`
  (DEV187-P1-07) — hợp đồng đã được đối chiếu khớp với BE ở đây, nhưng việc ký nhận thuộc task đó.
- Open point kế thừa `BE1-SCANCONC-OP-01` (audit log riêng cho scan) vẫn mở, chờ BA/Tech Lead.
