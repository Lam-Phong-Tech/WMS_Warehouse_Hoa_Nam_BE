# WWHN-237 — evidence pack

Bộ bằng chứng đóng register `DEV237-P1-01` … `DEV237-P1-05` của review 32/100 (18/08/2026).

| File | Đóng mục | Nội dung |
|---|---|---|
| `../../decision-records/DISP-STK-237-VARIANCE-REPORT-DEFERRED.md` | `P1-01` | Disposition Option A — DEFERRED/NO CODE, thang authority, điều kiện re-enable, affected ticket |
| `CONTRACT_NEGATIVE_LOCK.json` | `P1-02`, `P1-05` | Lock máy sinh: 11 check, digest baseline, allowlist historical tường minh |
| `NO_CODE_HANDOFF.md` | `P1-03`, `P1-05` | Handoff no-code, danh sách thay đổi thật, ranh giới không đụng, consumer confirmation |
| `QA_DISPOSITION_WWHN-238.md` | `P1-04` | QA gate: DEFERRED/N-A + ma trận N1..N8 và R1..R4 |
| `SOURCE_REGISTER.json` | tất cả | Exact BE/FE SHA, digest baseline, exit code từng lệnh, env |
| `logs/*.txt` | `P1-04` | Raw output từng lệnh gate, kèm `EXIT_CODE=` |
| `FE_CLOSURE_IDENTITY.json` (repo FE) | `P1-02`, `P1-05` | 17 check phía FE + pin định danh FE/BE |
| `SHA256SUMS` | tất cả | Digest của chính bộ evidence |

## Tái lập

```bash
# BE — sinh lại toàn bộ pack (chạy cả 6 lệnh, ghi log + register + SHA256SUMS)
node scripts/run-wwhn-237-evidence.mjs
```

Chạy lẻ từng gate:

```bash
node scripts/verify-wwhn237-variance-report-disposition.mjs --emit-lock
php artisan test --filter="Wwhn237VarianceReportDeferredGateTest|Flow05InventoryControlAlignmentTest"
```

```bash
npm run wwhn-237:verify
npx vitest run src/test/wwhn-237-variance-report-guard.test.ts
```

## Đọc kết quả thế nào

`SOURCE_REGISTER.json → summary.status` là kết luận duy nhất. Mỗi lệnh có `exit_code` và đường dẫn
log tương ứng; không có mục nào được ghi PASS mà thiếu log.

Ba điểm dễ đọc nhầm:

1. **`CONTRACT-HISTORICAL` là NOTE, không phải FAIL.** Pack immutable WWHN-180 còn dấu vết
   Stocktake là *có chủ đích*: theo `DISP-STK-236 §3` một immutable handoff chỉ được superseded
   bằng decision record, không bao giờ bằng cách sửa nội dung. Allowlist là hằng số trong
   verifier nên mở rộng nó là một thay đổi source có review.
2. **`variance` không bị cấm ở mọi chỗ.** Variance của reconciliation (balance vs ledger) là cơ
   chế thay thế mà chính `AC-SRS-037` chỉ định — gate có check dương `RECONCILIATION-ALIVE` để
   ai đó "dọn cho sạch" cũng bị đỏ.
3. **`status: PROPOSED`.** Không file nào ở đây tuyên bố APPROVED. Effective date chỉ có sau khi
   approver ghi disposition trên `WWHN-237`; guard vẫn fail-closed trong lúc chờ.

## Finding chuyển ticket khác

| Finding | Owner |
|---|---|
| `docs/api-contract/releases/DEC-REV-001/1.3.0-proposed/`: `CreateReversalRequest.original_document_type` còn enum `ADJUSTMENT`, runtime chỉ sinh `INBOUND`/`OUTBOUND` | `WWHN-26` / `WWHN-134` |
| `docs/evidence/WWHN-180/REPOSITORY_SHA256SUMS`: 3 mismatch có sẵn trước WWHN-237 | `WWHN-180` |
