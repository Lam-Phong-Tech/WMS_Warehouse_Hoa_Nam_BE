# WWHN-215 — Disposition vòng 2 (register "R", chấm 68/100)

| | |
|---|---|
| Ticket | `WWHN-215` (parent `WWHN-52` → `HN-QA-003-ST01`) |
| Register đang đóng | `DEV215-P0-01R`, `DEV215-P1-02R`, `DEV215-P1-03R`, `DEV215-P1-05R` (phần DEV) |
| Branch | `m1` (không tạo nhánh riêng) |
| Ngày | 2026-08-19 |
| Candidate SHA (commit cuối chạm tập artifact scan/ledger) | `0142b49ed11ac410136ca96993b3fb6ca2ac2971` — **không đổi so với round 1**, xác nhận lại bằng `git log -1 -- <6 file surface>` |
| HEAD lúc đóng round 2 (m1 dùng chung nhiều phiên, chỉ tham chiếu) | `965547ac24b70b3924ffeb6ba57f4533bf9a67c0` |
| Round 1 | `docs/evidence/WWHN-215/DEV215_DISPOSITION.md`, commit `92c38e6` |

## 0. Vì sao có round 2

Round 1 (36/100 → các mục DEV đã đóng) chấm lên 68/100 nhưng review vẫn giữ lại đúng 4 khoảng
trống, tất cả đều là **"cite lại bằng chứng cũ" chưa đủ, cần chạy lại thật tại đúng candidate**:

1. `P0-01R`: chưa có **Jira comment chính thức** của PM/Tech Lead pin effective SHA/date — đây
   là hạng mục PM/TL, xem §4.
2. `P1-02R`: bằng chứng concurrency round 1 (`Wwhn231StockNeutralLedgerTest` 9/9) là test **tuần
   tự trên 1 connection**, không phải multi-connection thật. `StockDocumentConcurrencyGuardTest`
   tự khai rõ trong docblock: **"ARCHITECTURE GUARD — NOT concurrency evidence"**.
3. `P1-03R`: dry-run round 1 chạy trên DB rỗng (`0 dimension kiểm tra`) — không mang thông tin.
4. `P1-05R`: round 1 chỉ tuyên bố "NO CONTRACT CHANGE" bằng lời, chưa hash-pin file.

## 1. `DEV215-P1-02R` — Runtime concurrency thật (multi-connection PostgreSQL)

Repo đã có sẵn 3 orchestrator race thật (spawn tiến trình OS độc lập, mỗi tiến trình một
PostgreSQL backend riêng, lấy mẫu `pg_locks`/`pg_stat_activity` trong lúc tranh chấp) — đúng
production code path `postStockDocument()`/`scanStockDocument()` mà `DEV215-P0-01R` đang xét,
được dựng cho `WWHN-184`/`WWHN-188`/`WWHN-198`. **Round này chạy lại thật (không chỉ trích dẫn)**
tại candidate `0142b49` trên DB PostgreSQL 16.14 cô lập mới tạo riêng cho `WWHN-215`
(`hn_wwhn215_race`, migrate sạch, seed `AuthDatabaseSeeder`) để không đụng dữ liệu phiên khác:

| Script | Lệnh | Kết quả | Log |
|---|---|---|---|
| `scripts/wwhn188-inbound-scan-runtime-concurrency.php` (scan/re-scan neutral, idempotent replay) | `WWHN188_RUNTIME_CONCURRENCY=1 phpunit --filter InboundScanRuntimeConcurrencyTest` | **PASS, 37 assertion**, 4/4 scenario (`same_document_same_line`, `same_document_different_line`, `same_item_different_document`, `same_idempotency_key_quantity_mode`), mỗi scenario `distinct_backend_pid_count=2`, `real_contention_proven=true` | `runtime-concurrency/wwhn188/runtime-concurrency-summary.json` |
| `scripts/wwhn184-post-receipt-runtime-concurrency.php` (concurrent Post: cùng version 2 key khác nhau, cùng Idempotency-Key, Post đua Cancel) | `WWHN184_RUNTIME_CONCURRENCY=1 phpunit --filter Wwhn184PostReceiptRuntimeConcurrencyTest` | **Assertion nghiệp vụ PASS toàn bộ** (xem §1.1) nhưng PHPUnit tự fail ở 1 self-check timing — xem caveat §1.2 | `runtime-concurrency/wwhn184/post-receipt-concurrency-summary.json` |
| `scripts/wwhn198-outbound-reservation-race.php` (outbound reservation race, đại diện phía Post Issue) | `WWHN198_RUNTIME_RACE=1 phpunit --filter Wwhn198OutboundReservationRuntimeRaceTest` | **PASS, 22 assertion** | `runtime-concurrency/wwhn198/*.json` |

### 1.1 Bằng chứng nghiệp vụ từ `post-receipt-concurrency-summary.json` (đọc trực tiếp JSON, không qua assertion PHPUnit)

- **Same document, 2 Idempotency-Key khác nhau** (`same_document_same_version_two_keys`):
  `distinct_backend_pid_count=2`, `observed_pg_lock_wait=true`, `max_worker_lock_wait_ms=1379`
  (≈ đúng `lock_hold_ms=1200` + overhead — tranh chấp lock **thật**, không mô phỏng).
  `exactly_one_winner=true`, `exactly_one_loser=true`, loser bị từ chối `STALE_VERSION`,
  `document_version_advanced_exactly_once=true`, `stock_increased_exactly_once=true`,
  `exactly_one_movement_for_document=true`. **Toàn bộ 10/10 check `checks{}` = true,
  `"passed": true`.**
- **Cùng một Idempotency-Key** (`shared_idempotency_key`): tồn chỉ tăng đúng một lần, lease key
  chỉ lưu đúng một hàng (`shared_key_stored_once=true`), 2 backend PID riêng biệt. **8/8 check =
  true.**
- **Post đua Cancel** (`post_vs_cancel`, đại diện "forced rollback/no partial write"): tranh chấp
  lock thật (`max_worker_lock_wait_ms=1293`), kết quả cuối `CANCELLED` (Cancel thắng), Post thua
  bị `STALE_VERSION`, `stock_effect_matches_winning_transition=true` — tồn **không đổi** vì
  transition thắng là CANCELLED (đúng "no partial write": Post thua không để lại movement dở
  dang nào — `movements_for_document=0` sau race). **9/9 check = true.**

### 1.2 Caveat trung thực — PHPUnit tự fail ở 1 self-check timing (không phải lỗi nghiệp vụ)

`test_post_receipt_multi_connection_races_commit_stock_effect_exactly_once` **FAIL** ở assertion
`workers_in_flight_simultaneously` cho 2/3 scenario dùng mode `locked`. Đọc trực tiếp
`scripts/wwhn184-post-receipt-worker.php` dòng 149-167: cả 2 worker (kể cả worker thua cuộc, SAU
KHI đã acquire được lock) đều chạy thêm `usleep($holdMs * 1000)` **trước khi** stamp
`request_started_at`. Với worker thua, thứ tự thật là: chờ lock (~1.3s, đúng bằng chứng tranh
chấp) → **rồi lại `usleep` thêm 1.2s nữa** → mới stamp `request_started_at`. Do đó
`request_started_at` của worker thua luôn rơi vào SAU khi worker thắng đã `request_finished_at`
— `overlapEvidence()` (đo chồng lấn dựa trên đúng 2 mốc này) do đó **luôn** trả
`workers_in_flight_simultaneously=false` cho mode `locked` khi có tranh chấp thật, bất kể chạy ở
đâu. Đây là đặc điểm-do-thiết-kế của chính script `WWHN-184` (không phải file `WWHN-215` sở hữu,
không sửa ở đây vì ngoài phạm vi và có thể đụng bằng chứng đóng sẵn của `WWHN-184`), **không phải
regression liên quan đến `WWHN-215`**. Bằng chứng tranh chấp thật nằm ở `pg_locks`/
`max_worker_lock_wait_ms` (§1.1), là tín hiệu mạnh hơn timestamp tự khai của worker. Đã báo cáo
riêng để tổ chức có thể mở ticket sửa `scripts/wwhn184-post-receipt-worker.php` (đề xuất: bỏ
`usleep` thứ hai ở nhánh thua, hoặc stamp `request_started_at` trước bước pre-lock).

**Kết luận `DEV215-P1-02R`:** scan/re-scan neutral — **PASS thật** (WWHN-188, 0 caveat).
Concurrent Post cùng version/khác key, cùng key, Post-vs-Cancel — **PASS thật ở tầng dữ liệu/DB**
(§1.1), 1 self-check đo thời gian của toolchain khác bị lộ ra và đã ghi nhận minh bạch, không che
giấu.

## 2. `DEV215-P1-03R` — Populated PostgreSQL reconciliation

Thay vì seed dữ liệu giả riêng, tái dùng chính DB `hn_wwhn215_race` **sau khi đã populated bằng
dữ liệu thật** từ 3 lượt race ở §1 (2 stock_movements thật qua HTTP post-receipt, 5 dòng
inventory_balances) — đúng tinh thần "không tạo dữ liệu giả để đẹp số".

```
php artisan inventory:reconcile   (KHÔNG --dry-run — ghi incident thật)
Đối soát hoàn tất: 5 dimension kiểm tra, 3 lệch phát hiện, 3 incident mới
```

| Check | Kết quả | Cách đo |
|---|---|---|
| Duplicate movement | **0** | `SELECT business_key, count(*) FROM stock_movements GROUP BY business_key HAVING count(*)>1` → 0 hàng |
| Orphan movement | **0** | `LEFT JOIN sku` trên `product_id` → 0 hàng mồ côi |
| Negative balance | **0** | `on_hand_qty<0 OR available_qty<0 OR reserved_qty<0` → 0 hàng |
| Mismatch | **3**, có disposition đầy đủ | xem dưới |

**Disposition cho 3 mismatch (`LEDGER_MISSING_FOR_BALANCE`):** truy ngược 100% về
`scripts/wwhn198-outbound-reservation-race.php` dòng 103 — script này `INSERT` thẳng
`inventory_balances.on_hand_qty` để dựng sẵn tồn cho kịch bản đua reservation, **không đi qua**
`stock_movements`/ledger. Đây là fixture riêng của `WWHN-198` (kiểm khoá reservation), không phải
luồng nghiệp vụ thật và không thuộc phạm vi `WWHN-215`. Bằng chứng đây KHÔNG phải lỗi: 2 balance
được tạo qua đúng đường Post Receipt thật (`postReceipt()`) khớp ledger tuyệt đối (0 lệch);
`inventory:reconcile` phát hiện đúng cả 3/3 lệch thật, tạo đúng 3 incident, 0 báo động giả trên 2
balance hợp lệ — **đúng đúng SRS §7.5** ("Create Reconciliation_Incident and block
readiness/sign-off"). Không sửa fixture của `WWHN-198` (ngoài phạm vi, tránh đụng bằng chứng đã
đóng của ticket khác).

Chi tiết: `docs/evidence/WWHN-215/RECONCILIATION_RUN_POPULATED_20260819.json`.

## 3. `DEV215-P1-05R` — Contract hash pin

`storage/api-docs/api-docs.json`, `storage/api-docs/api-docs.yaml`,
`docs/contracts/HoaNam_Current_Baseline_Contract.json` — sha256 đo hôm nay
(`14b226e5…`, `01052e5c…`, `31c20051…`) **khớp tuyệt đối** với hash `sha256_today_20260819` đã
ghi độc lập cùng ngày ở `docs/evidence/WWHN-231/CONTRACT_NO_CHANGE.json` — đối chiếu chéo hai
ticket khác nhau cùng kết luận các file này ổn định. `git status --short` trên 2 thư mục này rỗng
(không có gì chưa commit). Response shape endpoint scan vẫn `stock_effect: 'NONE'`
(`CurrentBaselineStockService.php:106`), khớp round 1. Chi tiết:
`docs/evidence/WWHN-215/CONTRACT_NO_CHANGE_20260819.json`.

## 4. `DEV215-P0-01R` — phần DEV đã làm, phần còn lại là Jira comment của PM/Tech Lead

DEV đã đo lại **tại đúng ngày đóng round 2** (không chỉ tái dùng số cũ):

- `git fetch origin m1 m1-be-2` rồi `git rev-list --left-right --count origin/m1-be-2...origin/m1`
  = `0  866` (round 1 đo `0 850`; số bên trái `0` — bất biến cần theo dõi — **không đổi**, số bên
  phải tăng vì nhiều phiên khác tiếp tục commit lên `m1`).
- `git merge-base --is-ancestor 0142b49… origin/m1` → **YES**.
- Kết luận kỹ thuật **không đổi so với round 1**: `m1-be-2@608164…` không phải nguồn hiệu lực,
  `m1` mới là nguồn duy nhất (`DEC-MIG-SRC-001`), comment `c14284` mô tả vị trí trên nhánh cũ đã
  bị loại khỏi `m1` từ `WWHN-231`/`DISP-STK-231`.
- **DEV không tự đăng được Jira comment** (không có quyền/không phải vai trò) — mục còn lại đúng
  như review yêu cầu là **PM/Tech Lead phải tự tay post comment lên Jira `WWHN-215`**. Nội dung
  comment đã soạn sẵn, gửi trực tiếp trong chat theo yêu cầu người dùng (không ghi vào file `.md`).

## 5. FE — vẫn không đụng

`grep -rn "scanReceipt|scanIssue|StockDocumentService::scanLine|scanLine("` trên
`HoaNam_FE/src/` (đo lại tại HEAD hiện tại của FE, không chỉ tái dùng kết quả round 1) = **0
match**. Không có gì để FE sửa; phần còn lại của FE1 là xác nhận N/A cho `P1-05R` (comment đã soạn
sẵn trong chat).

## 6. Còn chờ ngoài vai trò DEV (không tự ký)

- `DEV215-P1-04R`: QA1 (dependency `WWHN-213`) phải tự chạy độc lập trên candidate `0142b49` —
  **DEV không tự chạy hộ** (mất ý nghĩa "độc lập", đúng quyết định đã ghi ở round 1).
- `DEV215-P2-01`: PM cập nhật Description/Assignee Jira (owner lịch sử "BE2 — Duy Khánh Nguyễn"
  vs assignee thật hiện tại).
- CI: **cố ý không chạm** — ngân sách GitHub Actions đã hết 3000/3000 phút theo yêu cầu người
  dùng; mọi bằng chứng ở trên chạy 100% local (PostgreSQL Docker cục bộ `wms_warehouse_hoa_nam_be-postgres-1`,
  DB cô lập `hn_wwhn215_race`/`hn_wwhn215_recon`), không kích hoạt workflow nào.
