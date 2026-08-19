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

---

## Vòng R2 (review 67/100, 19/08/2026)

Bốn artifact mới, mỗi cái đóng đúng một mục register:

| File | Đóng mục | Nội dung |
|---|---|---|
| `CANDIDATE_PAIR.json` | `DEV237-P1-02R2` | **Một** cặp SHA BE/FE đóng băng, hai bản byte-identical ở hai repo, kèm số đo supersede `m1-be-2` → `m1` |
| `APPROVAL_RECORD.json` | `DEV237-P1-01R2` | Ba slot approver-authored tách khỏi disposition DEV-authored; fail-closed khi self-approval hoặc thiếu effective date |
| `QA_EXECUTION_MATRIX.json` | `DEV237-P1-04R2` | `N1..N8` + `R1..R4` **từng dòng**, mỗi dòng một raw log, cộng raw API/DB trên driver production |
| `CONTRACT_DIGEST_PIN.json` | `DEV237-P1-05R2` | Digest + version OpenAPI/Postman/FE tại đúng cặp đã pin; ba slot xác nhận độc lập |
| `logs/qa/*.txt` | `DEV237-P1-04R2` | Raw output từng dòng N/R + `raw-api-pgsql.txt`, `raw-db-pgsql.txt` |

### Lỗi thật của vòng R1 mà R2 sửa

`SOURCE_REGISTER.json` và `FE_CLOSURE_IDENTITY.json` đều ghi `git rev-parse HEAD` tại thời điểm
chạy. Hai repo chạy lệch giờ nên bộ evidence khai **bốn** SHA cho **một** cặp:

| File | BE SHA | FE SHA |
|---|---|---|
| BE `SOURCE_REGISTER.json` (R1) | `809d4839…` | `f9f2e65e…` |
| FE `FE_CLOSURE_IDENTITY.json` (R1) | `22564b46…` | `cf0fe8d0…` |

Nay cả hai file **đọc định danh từ `CANDIDATE_PAIR.json`**; `head_at_run` được giữ lại nhưng chỉ
để truy vết môi trường, không phải định danh candidate. Candidate = `git log -1 -- <scope>`, tức
commit cuối chạm tập artifact do task sở hữu, nên `m1` chạy tiếp cho task khác thì pin vẫn đúng.

### Bốn điểm dễ đọc nhầm ở vòng R2

1. **`N7` là chính gate BE.** Nó chạy hai lần: pass 1 bootstrap (ma trận trên đĩa còn là của lần
   chạy trước), pass 2 sau khi ma trận đã ghi — kết quả có hiệu lực là pass 2. Và check
   `QA-MATRIX` **cố ý loại N7** khỏi mệnh đề "không còn dòng FAIL": khẳng định trạng thái của N7
   bên trong chính N7 là vòng tự tham chiếu, một lần đỏ sẽ khiến gate đỏ vĩnh viễn kể cả sau khi
   đã sửa xong.
2. **`401` ở `RAW-API` không có nghĩa là route variance tồn tại.** `/api/v1/reports/stocktake-variance`
   khớp catch-all `reports/{report_code}` nên middleware auth trả `401` trước khi dispatcher chạy.
   Việc dispatcher từ chối mã variance được chứng minh ở `N2` (PHPUnit, có xác thực). Các route
   kiểm kê thật sự vắng mặt trả `404 RESOURCE_NOT_FOUND` — xem `logs/qa/raw-api-pgsql.txt`.
3. **`digest drift` của artifact `consumed` là NOTE, không phải FAIL.** Postman DEV collection và
   OpenAPI runtime thuộc task khác và còn sống; hard-pin digest của chúng làm gate đỏ oan cho mọi
   PR không liên quan — đúng bẫy `DISP-STK-236` đã ghi. Gate chỉ FAIL khi bề mặt cấm quay lại.
   Giá trị ba consumer ký vào là `authoritative_digest_for_confirmation` (nội dung **tại commit**),
   không phải digest working tree.
4. **`FAIL_KNOWN_EXTERNAL` ≠ hồi quy của task này.** `fe-api-mapping` đỏ sẵn từ trước WWHN-237 do
   `BE_CONTRACT_PIN.json` lệch swagger hiện hành; bề mặt lệch **không** chứa stocktake/variance.
   Owner là `WWHN-309`. Raw log vẫn giữ nguyên để reviewer tự kiểm.

### Tái lập vòng R2

```bash
node scripts/build-wwhn237-candidate-pair.mjs
node scripts/build-wwhn237-contract-digest-pin.mjs
node scripts/run-wwhn237-qa-matrix.mjs
node scripts/verify-wwhn237-variance-report-disposition.mjs --emit-lock
node scripts/run-wwhn-237-evidence.mjs
```

Khoá đúng cặp đang review (cả hai gate đều nhận cờ):

```bash
node scripts/verify-wwhn237-variance-report-disposition.mjs --expect-be-sha=<BE> --expect-fe-sha=<FE>
```
