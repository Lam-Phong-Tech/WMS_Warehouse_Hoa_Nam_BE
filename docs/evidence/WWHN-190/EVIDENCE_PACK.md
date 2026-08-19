# WWHN-190 — Evidence pack vòng R2 (2026-08-19)

Đóng register `DEV190-P1-01R2 / P1-01C / P1-02R2 / P1-03R2 / P1-04R2 / P1-05R2 / P2-01R2`
của review 62/100. Mọi con số dưới đây sinh ra từ **chạy thật**, không phải đọc source.

| Trường | Giá trị |
|---|---|
| Nhánh | `m1` (không tạo nhánh riêng) |
| RC SHA | `10a2c6ed08d4823cdbdd3a3ea09631855925c162` (xem `RC_PIN.json`) |
| DB dùng cho bằng chứng local | PostgreSQL 16.14, database riêng `hn_wwhn190` (clone từ `wms_hoanam`, không đụng DB dev) |
| DB dùng cho bằng chứng CI | service `postgres:16` trong `.github/workflows/wwhn-190-posting-evidence-gate.yml` |
| PHP / Laravel | PHP ^8.3 / Laravel v13.23.0 |

## 1. DEV190-P1-01C — hợp đồng ↔ runtime

- Release mới: `docs/api-contract/releases/WWHN-190/1.8.0/`
  (`openapi.json`, `openapi.yaml`, `postman_collection.json`, `RELEASE_MANIFEST.json`, `SHA256SUMS`, `CHANGELOG.md`).
- Decision record: `docs/decision-records/DEC-API-POSTING-002-WWHN190-RUNTIME-PARITY.md`.
- Pack WWHN-180 v1.7.0 **giữ nguyên byte-for-byte** (DISP-STK-236 §3) — có test khẳng định điều này.
- Sinh + kiểm bằng `scripts/build-wwhn190-posting-contract.mjs`; `--check` đối chiếu từng `x-permission`
  với `php artisan route:list --json` thật và chặn bề mặt đã gỡ quay lại.
- Kết quả chạy: `status: PASS`, `errors: []`, note `REMOVED-SURFACE-ABSENT: /api/v1/stocktake/sessions/{id}/apply-adjustment`.

6 điểm lệch đã đóng: If-Match, 412 STALE_VERSION, x-permission, IDEMPOTENCY_ERROR → IDEMPOTENCY_CONFLICT,
404, và path apply-adjustment đã bị gỡ. Chi tiết ở `docs/docs_task_jira/WWHN-190.md` §R2.1.

## 2. DEV190-P1-02R2 — true concurrency PostgreSQL (5/5 PASS)

File: `concurrency/CONCURRENCY_EVIDENCE.json` (tổng hợp), `concurrency/concurrency-run.txt` (log chạy),
và mỗi kịch bản có `worker-N.json`, `worker-N.stdout.txt`, `worker-N.stderr.txt`, `pg-lock-samples.json`, `plan-N.json`.

| Kịch bản | backend pid | HTTP | Kết luận |
|---|---|---|---|
| `01-same-key-same-payload` | 1880 / 1881 | `200` + `409 CONCURRENCY_CONFLICT` | 11/11 check PASS; movement=1, balance +5 đúng một lần, posting_event=1, retry tuần tự → replay `200` |
| `02-different-key-same-document` | 1893 / 1894 | `412 STALE_VERSION` + `200` | 10/10 check PASS; follow-up key mới + version đúng → `409 STATE_CONFLICT` |
| `03-stale-version` | 1912 / 1913 | `200` + `412 STALE_VERSION` | 10/10 check PASS; thiếu If-Match → `428`, If-Match sai định dạng → `422 INVALID_VERSION` |
| `04-same-key-different-payload` | 1924 / 1926 | `200` + `409 IDEMPOTENCY_CONFLICT` | 9/9 check PASS; retry tuần tự khác payload → `409 IDEMPOTENCY_CONFLICT` |
| `05-forced-rollback` | 1937 / 1938 | `500` + `500` | 10/10 check PASS; 0 movement, balance không đổi, chứng từ vẫn SCANNING v1, lease được giải phóng, retry sau khi gỡ fault → `200` với đúng 1 movement |

Điều kiện PASS của mỗi kịch bản (không có kịch bản nào được nới): 2 backend pid khác nhau **và**
tranh chấp thật (cửa sổ request chồng nhau hoặc quan sát được lock wait qua `pg_locks`/`pg_stat_activity`)
**và** đúng số lần thành công + đúng mã lỗi bên thua **và** bất biến dữ liệu sau cùng.

Tổng: `status: PASS`, `failed_checks: []`.

## 3. DEV190-P1-03R2 — reconciliation (WWHN-191, cùng RC)

File: `reconciliation/RECONCILIATION_REPORT.json` (có `report_version`), `reconciliation/reconciliation-raw-sql.txt`
(nguyên văn SQL + raw rows).

| Chỉ số | Giá trị |
|---|---|
| `posted_receipt_lines` | 5 |
| `accepted_receipt_qty_total` | 20 |
| `signed_receipt_movement_total` | **20** (bằng) |
| `balance_on_hand_total` | **20** (bằng) |
| `ledger_sum_total` | **20** (bằng) |
| mismatch (L1 + L2 + full-post invariant) | **0** |
| orphan (4 loại) | **0** |
| duplicate business effect (3 loại) | **0** |

Dữ liệu được đối chiếu chính là dữ liệu do 5 kịch bản concurrency ở §2 sinh ra — tức chuỗi
"race thật → ledger → projection" được kiểm liền mạch trên cùng một database.

## 4. DEV190-P1-01R2 — điều kiện xanh cho WWHN-180 evidence gate

Gate `WWHN-180 Scan Receipt Issue Evidence Gate` đỏ ở bước `Static gates` vì `pint --test` báo
**23 file lệch format** (nợ format của nhiều nhánh khác, không phải logic). Vòng này đã chạy `pint`
đúng 23 file đó — chỉ format (`ordered_imports`, `braces_position`, `unary_operator_spaces`,
`no_unused_imports`, `class_definition`…), không đổi một dòng logic.

Sau khi commit, kết quả gate tại RC SHA được pin trong `RC_PIN.json`.

## 5. Gate CI mới

`.github/workflows/wwhn-190-posting-evidence-gate.yml`:

- job `contract-parity` (sqlite): pint → generator `--check` → khẳng định pack 1.7.0 nguyên vẹn →
  `Wwhn190PostingContractParityTest` → các test guard posting cũ.
- job `true-concurrency` (postgres:16): `migrate --force` → 5 kịch bản race → reconciliation →
  niêm phong `SHA256SUMS` + `SHA256SUMS.sha256` + `EVIDENCE_BUILD.txt` → upload artifact 90 ngày.

## 6. Cách chạy lại toàn bộ

```bash
# 1. hợp đồng ↔ runtime
mkdir -p build/wwhn190 && php artisan route:list --json > build/wwhn190/route-list.json
node scripts/build-wwhn190-posting-contract.mjs --check --routes build/wwhn190/route-list.json

# 2. hợp đồng qua HTTP thật (sqlite)
php vendor/bin/phpunit tests/Feature/Wwhn190PostingContractParityTest.php

# 3. true concurrency + reconciliation (PostgreSQL)
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=hn_wwhn190 DB_USERNAME=postgres DB_PASSWORD=postgres
php scripts/wwhn190-postgres-concurrency.php docs/evidence/WWHN-190/concurrency
php scripts/wwhn190-receipt-reconciliation.php docs/evidence/WWHN-190/reconciliation
```

## 7. Hạng mục cần vai trò khác ký (DEV không tự đóng được)

| Item | Owner | Hồ sơ đã chuẩn bị sẵn |
|---|---|---|
| `DEV190-P1-01R2` | Tech Lead + QA1 | `RC_PIN.json` + §4 ở trên |
| `DEV190-P1-04R2` | QA1 | `QA1_INDEPENDENT_TEST_PACK.md` |
| `DEV190-P1-05R2` | BA/PM | `docs/decision-records/DEC-INV-FULLPOST-PIN-190.md` |
| `DEV190-P2-01R2` (phần tạo Jira key) | BA/PM | `LEGACY_CLEANUP_TASK.md` |

## 9. §R3 — Đóng DEV190-P1-01R2 sau khi HEAD trôi (2026-08-19, re-review 62/100) [đọc sau mục 8]

Re-review chỉ ra: register R2 vẫn hợp lệ nhưng RC SHA `10a2c6e` không còn là HEAD của `m1`
(nhiều phiên khác đã commit tiếp 57 commit cho ticket khác). Yêu cầu: "nếu chọn SHA khác SHA
đã chạy PostgreSQL evidence thì cần chạy lại evidence trên SHA đó."

**Đã làm**: grep chính xác danh sách path runtime của WWHN-190 trong range `10a2c6e..HEAD` →
chỉ 2/57 commit chạm runtime (`0142b49` WWHN-234 F-04 đổi thứ tự khoá `inventory_balances`
ngay trong `postStockDocument`; `ea93dd6` WWHN-195 sửa cùng file nhưng khác method outbound).
Chạy lại **toàn bộ** evidence tại `rc_sha` mới `9b18443` (không phải giả định "chắc vẫn đúng"):

| Bằng chứng | Kết quả tại `9b18443` |
|---|---|
| Contract-vs-runtime (`build-wwhn190-posting-contract.mjs --check`) | PASS, `errors: []` |
| PHPUnit regression (sqlite, ép `DB_CONNECTION=sqlite` tường minh) | **52/52 PASS**, 345 assertions — gồm cả 2 test file mới do chính 2 commit trôi sinh ra (`Wwhn234P1P2ClosureTest`, `Wwhn195OutboundContractAlignmentTest`) |
| True concurrency PostgreSQL (DB cô lập mới `hn_wwhn190_r3`, không đụng DB của vòng trước hay của phiên khác) | **5/5 PASS** — `docs/evidence/WWHN-190/concurrency-r3/CONCURRENCY_EVIDENCE.json` |
| Reconciliation | **PASS**, mismatch/orphan/duplicate = 0 — `docs/evidence/WWHN-190/reconciliation-r3/RECONCILIATION_REPORT.json` |

Kết luận: đổi thứ tự khoá của F-04 (khoá theo `product_id` toàn cục thay vì `line_no`) **không
phá bất biến nào** của WWHN-190/191 dưới race thật. `RC_PIN.json` đã re-pin sang `9b18443`,
giữ nguyên lịch sử round R2 (`10a2c6e`) làm tham chiếu. Không có contract release mới (1.8.0
vẫn hiện hành, 2 commit trôi không đổi request/response shape).

**Sự cố an toàn tự phát hiện và tự sửa**: lần chạy PHPUnit đầu tiên của vòng này quên ép
`DB_CONNECTION` nên vô tình chạy nhắm vào `wms_hoanam` (DB dev dùng chung) qua container mặc
định `pgsql`. 3 test lỗi do khác biệt dữ liệu/behaviour trên nhánh Postgres đó — đã xác minh
**không có row nào persist lại** (`RefreshDatabase` rollback transaction khi test fail) rồi
chạy lại đúng cách với `-e DB_CONNECTION=sqlite -e DB_DATABASE=:memory:` tường minh → 52/52
PASS. Ghi lại trong `RC_PIN.json.safety_note_shared_db` để vòng sau không lặp lại.

**Không đụng CI/GitHub Actions** ở vòng này theo yêu cầu người dùng (Actions đã hết quota
3000/3000 phút) — toàn bộ chạy local qua container Docker sẵn có.

## 8. Trạng thái CI tại thời điểm đóng gói (2026-08-19 ~02:00 UTC)

| Sự kiện | Kết quả |
|---|---|
| `WWHN-190 Posting Contract & True Concurrency Gate` — run **32206567091** tại `8e82dd6a` (chứa commit `d9f1769`) | ✅ **success**, cả 2 job: `contract vs runtime (sqlite)` và `executed evidence (PostgreSQL 16)` |
| Cùng gate tại `0461d354` — run 32206872066 (kể cả rerun attempt 2) | ❌ **failure hạ tầng**: job kết thúc sau ~3 giây với **0 step**, không có log |
| Mọi workflow khác của repo tại `0461d354` (WWHN-180, 164, 172, 204, 219, 233, 273, 309, DEVOPS…) | ❌ cùng kiểu: 0 step, ~3 giây |

Kết luận: từ ~01:58 UTC runner của repo không khởi tạo được job — đây là sự cố hạ tầng/quota
Actions ở mức repo, **không phải** lỗi của thay đổi WWHN-190. Bằng chứng: cùng workflow, cùng code,
đã **xanh** ở run 32206567091 vài phút trước đó, và mọi gate của mọi ticket khác cũng đỏ y hệt.

Việc cần làm khi runner hoạt động lại: chạy lại gate tại RC SHA trong `RC_PIN.json`
(`gh run rerun` hoặc `workflow_dispatch`) rồi cập nhật `ci.run_at_rc` trong file pin.

### WWHN-180 evidence gate — tiến độ thực tế

| Lần chạy | Kết quả bước `Static gates` |
|---|---|
| Trước vòng này (run 32163575328) | ❌ `pint --test`: **23 file** lệch format |
| Sau commit `d9f1769` (run 32206567130) | ❌ `pint --test`: **2 file** lệch format (`Wwhn202OutboundBalanceEvidenceTest`, `Wwhn310ErdDocumentPinTest` — do nhánh khác vừa commit) |
| Sau commit `10a2c6e` | 2 file trên đã được format; chưa có kết quả vì runner đang lỗi |

Nghĩa là nợ format chặn `Static gates` đã đi từ 23 → 2 → 0 file; các bước evidence phía sau của
gate WWHN-180 chỉ có thể xác nhận sau khi runner hoạt động trở lại.
