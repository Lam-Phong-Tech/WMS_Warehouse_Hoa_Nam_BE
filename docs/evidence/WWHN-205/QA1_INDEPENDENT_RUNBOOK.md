# WWHN-205 / WWHN-206 — Runbook để QA1 tự chạy lại (P1-02R, P1-03R)

Register vòng 2 ghi rõ: **"QA1 độc lập thực hiện rebuild/reconciliation trên exact
candidate … Không dùng Quang tự xác nhận"**. Runbook này viết cho người chạy, không
cho người đọc: mọi con số trong evidence pack đều tái tạo được bằng đúng các lệnh dưới
đây, và bước khẳng định cuối cùng **không đi qua PHP/Laravel/script nào của DEV**.

Thời lượng: ~10 phút (phần lớn là `migrate:fresh --seed`).

---

## 0. Điều kiện cần

| Thứ | Giá trị dùng khi dựng evidence pack này |
|---|---|
| Database | PostgreSQL **16.14** (image `postgres:16-alpine`) |
| PHP | 8.3 với `pdo_pgsql` |
| Exact candidate SHA | `d9f17695bb515dc43aa0451e13deddda433c185a` |
| DB dùng riêng | `hn_wwhn205b` (**không dùng chung DB với phiên khác** — `migrate:fresh` sẽ xoá sạch) |

> **Hai bẫy đo đạc đã gặp thật, QA1 nên biết trước:**
>
> 1. **Đừng dùng junction/symlink `vendor` trỏ về repo chính.** Laravel resolve
>    `base_path()` theo vị trí `vendor/autoload.php`, nên junction làm Artisan nạp lệnh
>    từ **repo chính** (working tree bẩn) chứ không từ checkout exact-SHA. "Exact SHA"
>    khi ấy chỉ đúng trên giấy. Hãy `composer install` trong checkout, hoặc **copy** hẳn
>    thư mục `vendor`.
> 2. **Dùng DB riêng.** Nhánh `m1` có nhiều phiên chạy song song; `migrate:fresh` sẽ xoá
>    sạch DB đang dùng chung.

---

## 1. Checkout đúng exact candidate (không dùng working tree đang có)

Working tree `m1` luôn có nhiều phiên sửa song song. Muốn nói "chạy trên exact SHA"
thì phải chạy trên một checkout sạch:

```bash
git worktree add --detach /tmp/wwhn205 d9f17695bb515dc43aa0451e13deddda433c185a
cd /tmp/wwhn205
git status --porcelain   # phải RỖNG. Có dòng nào là không còn "exact SHA".
composer install --no-interaction --prefer-dist
cp <đường-dẫn>/.env .env
```

## 2. Dựng schema trên PostgreSQL 16

```bash
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
       DB_DATABASE=hn_wwhn205b DB_USERNAME=postgres DB_PASSWORD=postgres \
       APP_ENV=testing CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
php artisan config:clear
php artisan migrate:fresh --seed --force
```

Kết quả mong đợi: **exit 0**, chạy hết mọi migration + seeder, **không cần vá tay gì**.

Bản vá `2026_08_19_000050_repair_erd_mirror_projection_casts.php` (WWHN-289, commit
`8bdf9ad`) đã nằm **trong** candidate này. Nếu QA1 chạy trên một SHA **cũ hơn** `8bdf9ad`
thì migrate sẽ vỡ tại `2026_08_19_000100_add_optimistic_lock_to_pv_master_tables` với
`SQLSTATE[42804] … column "created_by" is of type uuid but expression is of type text` —
đó là blocker `WWHN205-BLK-01` **đã đóng**, không phải lỗi thao tác.

> **Thứ tự các bước dưới đây có chủ đích: đối soát (§4/§5) chạy TRƯỚC race (§3').**
> Kịch bản S2 của race cố ý dựng oversell. Với SKU serial-tracked, oversell theo đúng
> định nghĩa nghĩa là "sổ nói ít hơn số hàng đang có mặt", nên nó **bắt buộc** làm lệch
> Serial subledger của SRS §7.5 — không có cách nào vừa giữ kịch bản oversell vừa giữ
> subledger khớp tuyệt đối. Vì vậy §7.5 được khẳng định trên **dữ liệu seed thật của
> candidate** (đúng thứ QA1 kiểm trên một deployment thật), còn bất biến ledger dưới
> contention do chính race script kiểm, kèm assert **"lệch subledger bị giam đúng trong
> SKU của S2"**. Nếu chạy race trước rồi mới đối soát, QA1 sẽ thấy
> `serial_subledger_mismatch_count = 1` và đó là **do fixture**, không phải defect.

## 3'. Chạy race Receipt vs Issue thật (P1-03R) — chạy SAU §4/§5

```bash
WWHN205_LOCK_HOLD_MS=1500 php scripts/wwhn205-receipt-issue-race.php docs/evidence/WWHN-205/runtime
```

Script tự spawn nhiều **OS process riêng biệt**, mỗi process một `pg_backend_pid()`,
đồng bộ bằng file barrier để tất cả cùng lao vào một row lock. Đọc kết quả ở
`docs/evidence/WWHN-205/runtime/receipt-issue-race-summary.json`.

Cần tự kiểm bằng mắt (không tin dòng `status`):

- `scenarios.*.distinct_backend_pid_count` ≥ 2 → đúng là nhiều connection thật.
- `scenarios.*.observed_pg_lock_wait` hoặc `overlapping_critical_sections` = `true`
  → có contention thật, không phải chạy tuần tự trá hình.
- `scenarios.*.checks` không có `false` nào.
- `post_race_reconciliation.checks` toàn `true`.

## 4. Rebuild + reconciliation (P1-02R)

```bash
php scripts/wwhn206-ledger-reconciliation.php docs/evidence/WWHN-205/runtime
```

Bước rebuild **gọi đúng lệnh sản phẩm** `php artisan inventory:reconcile --rebuild`
(WWHN-204 / DEV204-P1-03), không phải logic riêng của script. Script chạy rebuild
**hai lần** để chứng minh rebuild là idempotent (`rebuild_is_idempotent`).

## 5. Bước khẳng định độc lập — QA1 chạy thẳng SQL, không qua PHP

Đây là bước quan trọng nhất của P1-02R. Nếu chỉ tin output của script PHP thì vẫn là
"DEV tự xác nhận". File dưới đây là **SQL thuần**, đọc thẳng `inventory_balances` và
`stock_movements`:

```bash
psql -h 127.0.0.1 -U postgres -d hn_wwhn205b -v ON_ERROR_STOP=1 -f scripts/wwhn206-ledger-reconciliation.sql
```

Bảng kết quả phải có **`status = PASS` ở mọi dòng** và `overall_status = PASS`:

| metric | ý nghĩa register |
|---|---|
| `ledger_balance_mismatch_count` | **ledger = balance** |
| `balance_without_ledger_count`, `ledger_without_balance_count` | **mismatch_count = 0** |
| `duplicate_business_key_count`, `duplicate_source_count` | **duplicate_source = 0** |
| `orphan_movement_document_count`, `orphan_movement_line_count`, `orphan_reversal_link_count` | **orphan = 0** |
| `movement_on_unposted_document_count`, `posted_document_without_movement_count` | **rollback residue = 0** |
| `negative_balance_count`, `negative_ledger_snapshot_count`, `inconsistent_ledger_row_count` | bất biến không âm |
| `serial_subledger_mismatch_count` | **SRS §7.5** Serial subledger |
| `quantity_subledger_mismatch_count` | **SRS §7.5** Quantity subledger |
| `container_integrity_mismatch_count` | **SRS §7.5** Container integrity |

> Ba metric cuối vượt ra ngoài chữ nghĩa của P1-02R nhưng nằm đúng trong SRS §7.5
> ("Reconciliation Controls" có **bốn** check) và AC-SRS-023 ("**ba lớp** khớp sau
> posting"). Định nghĩa sao y `OperationalReportController::inventoryReconciliation()`
> đang chạy thật (`serialSubledgerCounts` / `boxSubledgerCounts`), nên đối soát tay và
> báo cáo trong sản phẩm không thể kết luận khác nhau.

> Script PHP ở bước 4 **đọc lại đúng file `.sql` này** rồi thực thi các câu SELECT trong
> đó (bỏ các lệnh meta của psql). Hai đường vì vậy không thể kết luận khác nhau — nếu ai
> đó sửa một bên, `verify-wwhn205-closure.mjs` (S-02) sẽ đỏ.

## 6. Bộ test đích của WWHN-205 trên PostgreSQL

```bash
php artisan test --filter '/(Wwhn205StockMovementItemIdInvariantTest|Wwhn205InvariantClosureContractTest|ErdBusinessInvariantParityTest|Wwhn204LedgerReconciliationCoverageTest|PostReceiptConcurrencyBehaviorTest|PostIssueConcurrencyBehaviorTest|StockDocumentConcurrencyGuardTest|StockDocumentIdempotencyTest)/'
```

`ErdBusinessInvariantParityTest` chỉ chạy thật trên PostgreSQL (trên SQLite nó `skip`
theo `requirePostgres()`). Nếu QA1 thấy 5 test `skipped` thì tức là đang chạy nhầm
driver SQLite — kiểm lại `DB_CONNECTION`.

## 7. Cổng truy vết (P1-04R)

```bash
node scripts/verify-wwhn205-closure.mjs
```

Cổng này kiểm chuỗi `fad4242 → 7a78477 → candidate` bằng `git merge-base`, kiểm blob
của từng artifact tại candidate, và kiểm các **semantic marker** của fix vẫn còn trong
mã nguồn (chống trường hợp "file còn nhưng fix đã bị refactor mất"). Cần checkout đủ
lịch sử (`fetch-depth: 0`), nếu không cổng sẽ **fail-closed** chứ không bỏ qua.

---

## Blocker bắt buộc biết trước (cả hai đã ĐÓNG, ghi lại để QA1 không đi lại vết cũ)

| ID | Nội dung | Owner | Disposition |
|---|---|---|---|
| `WWHN205-BLK-01` | `migrate:fresh` trên PostgreSQL 16 vỡ tại candidate **cũ** `c29a220`: `SQLSTATE[42804] column "created_by" is of type uuid but expression is of type text` trong `hn_erd_mirror_pv_industries()`. Gốc là write-bridge sinh tự động ở `2026_08_10_000765` không cast type — **không phải lỗi của WWHN-205 hay WWHN-252**. | Phiên **WWHN-289 / DEV289-P1-02R** | **ĐÃ ĐÓNG bởi chủ sở hữu**, WWHN-205 không sửa chồng. Bản vá `2026_08_19_000050_repair_erd_mirror_projection_casts.php` đã commit ở `8bdf9ad` và nằm **trong** candidate `d9f1769`. Log lượt đỏ giữ nguyên văn ở `raw/A1-…-FAIL.log`, ghi rõ là ĐỎ. |
| `WWHN205-BLK-02` | P1-02R **không thể thoả** tại candidate cũ `c29a220` vì `inventory:reconcile` chưa có `--rebuild` (option vào ở `da1c5af`, WWHN-204). | BE1 (WWHN-205) | **ĐÃ ĐÓNG** — pin lại candidate sang `d9f1769`; thêm `ReconcileInventoryBalances.php` vào file set kèm semantic marker; script đối soát nay **fail-closed** (kiểm tiền đề tường minh + `try/catch` + exit code thật) thay vì thoát 0 khi hỏng. |

## Điều runbook này **không** khẳng định

- Không khẳng định tầng HTTP (`If-Match`, `Idempotency-Key` ở controller). Phạm vi race
  ở đây là **service layer** `CurrentBaselineStockService::postStockDocument()`, đúng
  phạm vi đã được duyệt ở WWHN-219/201. Race ở tầng HTTP là phần chạy riêng của QA1.
- Không khẳng định thay cho QA1. Mọi số liệu trong evidence pack là **đầu vào để QA1
  kiểm lại**, không phải kết luận nghiệm thu.
