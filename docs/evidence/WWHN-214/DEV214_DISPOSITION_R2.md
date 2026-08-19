# WWHN-214/215 — Source-of-Record · Round R2 (register 72/100)

| | |
|---|---|
| Chuỗi ticket | WWHN-213 (QA) / WWHN-214 (BE1 core transaction) / WWHN-215 (BE2 ledger-scan) — parent WWHN-52 → HN-QA-003-ST01 |
| Register đang đóng | DEV214-P1-01R2, P1-02R2, P1-03R2, P1-04R2 (HARD GATE), P1-05R2 (phần DEV); P2-01R2 thuộc PM |
| Branch | `m1` (không tạo nhánh riêng; không đụng `.github/`; mọi commit `[skip ci]`) |
| Ngày | 2026-08-19 |

## 1. SOURCE-OF-RECORD DUY NHẤT (đóng DEV214-P1-03R2 — "SOURCE CONFLICT")

Reviewer R2 chỉ ra đúng: qua các vòng, nhiều SHA khác nhau được nêu (089eacd, e45856a,
9c18f69, 0142b49, 92c38e6, 965547a) mà chưa có MỘT tuyên bố nguồn hiệu lực. Tuyên bố
chính thức, từ nay là nguồn duy nhất:

### 1.1 Bảng phả hệ candidate (lineage — vì sao từng SHA từng xuất hiện)

| SHA | Vai trò lịch sử | Trạng thái hiệu lực |
|---|---|---|
| `089eacd` / `28625ee` | Vòng 1 WWHN-214 (commit test / SHA lúc chạy) | HISTORICAL — superseded bởi vòng R |
| `e45856a` | Re-run giữa vòng (mục 5 disposition cũ) | HISTORICAL — superseded bởi vòng R |
| `92c38e6` / `0142b49` | Evidence WWHN-215 vòng 1–2 (bề mặt ledger/scan riêng của 215) | Giữ hiệu lực CHO WWHN-215; không phải candidate core-transaction |
| **`9c18f69f27352be78d9fadd23913ea7016876428`** | **EXACT CANDIDATE core-transaction — commit cuối cùng chạm bề mặt artifact** | **EFFECTIVE — source-of-record** |
| `965547a` | Commit đóng gói docs vòng R (chỉ docs, không chạm code) | Package pointer, không phải candidate |

### 1.2 Bất biến dải commit (range invariant) — candidate không rot

Tại thời điểm chạy evidence R2 (HEAD lúc chạy = `9d888a1c490ea64f7c0eaf8026a0f08c92e7cd41`,
tự pin trong từng file evidence `run.candidate_sha`):

```
git diff 9c18f69 9d888a1 --  <11 file/dir bề mặt core-transaction>   →  RỖNG (byte-identical)
git rev-list --count 9c18f69..9d888a1                                →  15 commit
```

15 commit chen giữa đều thuộc ticket khác (WWHN-236/237/242/331 — docs, import SKU,
citation), **không commit nào chạm** bề mặt core-transaction gồm:
`CurrentBaselineStockService.php`, `CurrentBaselineReversalService.php`,
`CurrentBaselineStockFlowController.php`, `AlignedOutboundFlowController.php`,
`CurrentBaselineOperationsController.php`, `ReversalDocumentController.php`,
`Modules/Warehouse/app/Http/Middleware/`, `Wwhn214QaRetestRawEvidenceTest.php`,
`Wwhn198OutboundReservationRuntimeRaceTest.php`, `Wwhn219RuntimeConcurrencyTest.php`,
`Wwhn184PostReceiptRuntimeConcurrencyTest.php`.
⇒ Evidence chạy tại `9d888a1` **có giá trị đúng cho candidate `9c18f69`**.

### 1.3 Disposition + PR + mapping (một chỗ, hết mâu thuẫn)

- **Disposition:** NO QUALIFYING NEW DEFECT → **NO CODE CHANGE / DEV FIX N/A**.
- **PR: N/A** — đúng điều khoản register R2 "Nếu NO CODE CHANGE thì không cần speculative PR".
- **Defect→SHA→test map hiệu lực duy nhất:** bảng §3 của `DEV214_DISPOSITION.md`
  (11 dòng, toàn bộ 15 SHA fix + 7 SHA mốc đã xác minh tồn tại bằng
  `git cat-file -e <sha>^{commit}` ngày 19/08 — 22/22 OK, 0 broken reference).
- **Nguồn nhánh:** `m1` duy nhất (DEC-MIG-SRC-001); `m1-be-2` HISTORICAL.
- Jira (WWHN-213/214/215) phải trỏ VỀ file này; mọi mô tả khác lệch với file này
  là lỗi trích dẫn.

## 2. Re-execution R2 tại exact candidate (đóng DEV214-P1-01R2 + P1-04R2 HARD GATE)

Toàn bộ 7 hạng mục checklist chạy lại **hôm nay**, không tái dùng số vòng trước.
Evidence JSON tự pin `candidate_sha`, driver, PHP, timestamp.

| Lượt | Env/DB | Kết quả | Raw evidence |
|---|---|---|---|
| Raw API/DB — sqlite | sqlite `:memory:` (driver test mặc định), PHP 8.3.30 | **PASS 7/7, 83 assertion** | `retest-r2/qa-retest-raw-api-db.sqlite.json` |
| Raw API/DB — PostgreSQL | PostgreSQL 16.14 (Alpine, container `wms_warehouse_hoa_nam_be-postgres-1`), **DB cô lập mới `wwhn214_retest_r2`** (không đụng `hoa_nam_audit` dùng chung) | **PASS 7/7, 83 assertion** | `retest-r2/qa-retest-raw-api-db.pgsql.json` |
| Race đa tiến trình PG — WWHN-198 (reservation) | như trên, sau `migrate:fresh --force` + `db:seed --force` | **PASS 3/3** (phpunit xanh; cả 3 scenario `passed=true`, mỗi scenario 2 backend PID riêng, `pg_locks` contention thật) | `retest-r2/wwhn-198-race-summary.json` |
| Race đa tiến trình PG — WWHN-184 (post receipt) | như trên | **PASS 3/3 theo orchestrator summary** (`passed=true` cả 3 scenario; `real_contention_proven=true` ở 2/3 — riêng `shared_idempotency_key` chặn ở tầng lease key nên không cần lock-wait; wrapper phpunit fail đúng 1 self-check wall-clock — hạn chế Windows local đã hồ sơ hoá từ vòng R, summary orchestrator là nguồn sự thật) | `retest-r2/wwhn-184-race-summary.json` |
| Race đa tiến trình PG — WWHN-219 (ledger/balance/audit) | như trên | **PASS 3/3** (phpunit xanh; không over-reversal, không lost update, không âm balance) | `retest-r2/wwhn-219-race-summary.json` |

**Đối chứng độc lập cùng ngày:** một lượt adoption chạy song song bởi phiên khác (log
`logs/core-transaction-sqlite-R2.log`, `logs/core-transaction-pgsql-R2.log`,
`logs/race-runtime-pgsql-R2.log`, DB `wwhn214_retest` riêng của lượt đó) tái lập đúng
cùng kết quả tại cùng SHA `9d888a1`: suite nền 33 class **232 test / 2 562 assertion /
0 fail / 4 skip** (sqlite), suite PG **10 test / 87 assertion / 0 fail / 1 skip**, race
198/219 xanh + 184 cùng một self-check wall-clock. Hai lượt thi hành độc lập, kết quả
trùng khớp. (Các log này bị `.gitignore` `*.log` nuốt mặc định — đã `git add -f` để
evidence không mất; đây đúng bẫy đã ghi nhận ở WWHN-217.)

7 hạng mục HARD GATE = no-negative (422 INSUFFICIENT_STOCK, tồn không đổi) ·
duplicate/retry (200 replay một hiệu ứng; khác payload → 409 IDEMPOTENCY_CONFLICT) ·
concurrency (428/412/409; race: đúng 1 commit) · rollback/no-partial (500 không lộ nội
bộ, rollback toàn bộ, retry được) · RBAC (401/403, không hiệu ứng tồn) · append-only
ledger (trigger DB chặn UPDATE/DELETE cả 2 driver) · reconciliation=0 (balance = Σ
qty_delta, mismatch_count=0, 0 incident). Oracle: `EXPECTED_RESULT_MAPPING.md`
(SRS v1.12 + ERD 1.0, trích dẫn đã đối chiếu lại văn bản gốc — xem header file đó).

**Khai báo minh bạch môi trường:** repo dùng chung nhiều phiên Claude; lúc chạy, working
tree có file modified/untracked của các phiên khác (WWHN-233/236/237 — report controllers,
evidence, docker-compose) nhưng **không file nào thuộc bề mặt core-transaction** (
`git status` lọc theo 11 path bề mặt = rỗng); autoload PSR-4 đã kiểm
(`Modules\Auth\ → Modules/Auth/app` hiện diện). Không kích hoạt GitHub Actions.

## 3. Reference integrity (đóng DEV214-P1-02R2)

Jira comment 15855 (WWHN-214) chứa reference lệch → khối tham chiếu ĐÚNG để thay thế
(DEV đã soạn comment sửa, PM/TL/DEV post lên Jira):

- Candidate: `9c18f69f27352be78d9fadd23913ea7016876428` (điều kiện hiệu lực: §1.2).
- Gói artifact (repo BE, nhánh `m1`): `docs/evidence/WWHN-214/`
  {`DEV214_DISPOSITION.md`, `DEV214_DISPOSITION_R2.md` (file này), `QA_RETEST_R.md`,
  `QA_ADOPTION_R2.md`, `EXPECTED_RESULT_MAPPING.md`, `retest/`, `retest-r2/`, `logs/`}.
- QA disposition thật: `QA_ADOPTION_R2.md` (WWHN-213) — NO QUALIFYING DEFECT / DEV FIX N/A.
- KHÔNG còn tham chiếu nào tới artifact/SHA ngoài danh sách trên.

## 4. Còn lại ngoài vai trò DEV

- **DEV214-P1-01R2 nửa QA:** QA1 ký chính danh việc adopt lượt chạy §2 (hoặc tự chạy lại
  bằng lệnh 1-dòng trong `QA_RETEST_R.md` §6, đổi DB thành `wwhn214_retest_r2`) và đăng
  formal NO QUALIFYING DEFECT lên WWHN-213 — comment đã soạn.
- **DEV214-P1-05R2 nửa BA/BAS:** pin mapping vào WWHN-52 bằng comment — đã soạn.
- **DEV214-P2-01R2:** PM xác nhận intentional carry-over (due 08/09 vs sprint end 25/08)
  hoặc chỉnh planning metadata — comment đã soạn.
