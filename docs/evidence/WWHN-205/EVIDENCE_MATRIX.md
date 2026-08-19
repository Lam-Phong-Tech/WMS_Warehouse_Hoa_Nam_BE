# WWHN-205 / WWHN-214 — Evidence matrix vòng 2 (đóng P1-01R … P1-04R)

Vòng trước: **70/100 — chưa đạt**. Nhận xét gốc quy về một điều: evidence là **lời văn**,
không phải dữ liệu chạy được. Tài liệu này chỉ làm ba việc — pin SHA, trỏ tới log thô, và
trỏ tới cổng chạy lại được. Mọi khẳng định đều có một lệnh tương ứng trong
[`QA1_INDEPENDENT_RUNBOOK.md`](QA1_INDEPENDENT_RUNBOOK.md).

**Môi trường của toàn bộ số liệu dưới đây:** PostgreSQL **16.14** (`postgres:16-alpine`),
PHP 8.3.30, DB riêng `hn_wwhn205b`, chạy trên **checkout sạch** tại exact candidate
(`git worktree add --detach`, `git status --porcelain` chỉ còn đúng các file của WWHN-205).

---

## 1. Ba mốc commit register yêu cầu pin

| Vai trò | SHA đầy đủ | Thời điểm | Nội dung |
|---|---|---|---|
| **Pre-fix baseline** | `fad424283638254f0b8ccb441fc49e88e9e1a692` | 2026-08-15 10:43:29 +0700 | `[DEV] Triển khai trạng thái/thao tác putaway` — HEAD của `m1` khi BẮT ĐẦU rà soát WWHN-205. Chưa có `item_id` write-side, chưa có trigger append-only trên `stock_movements`. |
| **Actual fix** | `7a78477424cc5b4e0685a0fa702dd085ee9f882e` | 2026-08-15 13:40:28 +0700 | `hoan thien m1` — commit MANG fix WWHN-205. Con **trực tiếp** của pre-fix baseline. |
| **Chosen candidate** | `d9f17695bb515dc43aa0451e13deddda433c185a` | 2026-08-19 08:52:32 +0700 | `feat(WWHN-190): dong bang chung P1/P2 vong R2 cho Post Receipt` — commit **cuối cùng chạm artifact file set**. Cách actual fix **217 commit**. |

Quan hệ được xác minh bằng `git merge-base --is-ancestor`, không bằng lời văn:

```
fad4242  ──(1 commit)──▶  7a78477  ──(217 commit)──▶  d9f1769
pre-fix                    fix                          candidate
```

> ⚠️ `7a78477` là commit **gộp nhiều ticket** (25 file). File set thuộc WWHN-205 chỉ gồm
> các file ở §2 — không được đọc toàn bộ commit như là phạm vi của WWHN-205.

### 1.1. Candidate pin theo "commit cuối chạm artifact", KHÔNG theo HEAD

Nhánh `m1` nhận commit vài phút một lần từ nhiều phiên song song. Pin theo `HEAD` làm
register sai ngay sau khi ghi. Quy tắc dùng ở đây:

> Pin candidate = **commit cuối cùng chạm `artifact_file_set`**. Sau đó bảo toàn
> **range invariant**: trong khoảng `(candidate, HEAD]` **không được có commit nào chạm
> file set đó**. Nếu có, evidence không còn nói về mã đang nằm trên `HEAD` và register
> **phải** được pin lại rồi chạy lại cổng.

Mệnh đề này do `verify-wwhn205-closure.mjs` **C-05** thực thi, fail-closed, và in ra đúng
danh sách commit vi phạm.

### 1.2. Candidate bị thay: `c29a220` → `d9f1769`, và vì sao

Vòng chạy đầu pin `c29a220e5ebc070012d4ff0715a2f42ef0e4324c`. Chạy thật trên checkout
sạch tại SHA đó phát hiện **`php artisan inventory:reconcile` không có option
`--rebuild`** — option này vào ở `da1c5af` (WWHN-204 / DEV204-P1-03,
`InventoryProjectionRebuildService`), **sau** `c29a220`. Nghĩa là **P1-02R không thể thoả
tại `c29a220`**: không có rebuild nào để chạy. Đã pin lại candidate thay vì mô tả một thứ
không chạy được là đã chạy.

**Vì sao lần đầu không thấy — và đã sửa cách đo:** worktree ban đầu dùng junction `vendor`
trỏ về repo chính, nên Laravel resolve `base_path()` về **repo chính** và Artisan nạp lệnh
từ working tree bẩn ở đó. "Exact SHA" khi ấy chỉ đúng trên giấy. Sau khi copy hẳn `vendor`
vào worktree, sự thật lộ ra ngay lần chạy kế tiếp. Mọi số liệu trong tài liệu này là từ
lần đo sau khi đã sửa.

## 2. Diff / file set thuộc WWHN-205 (P1-04R)

| File | Loại | +/- tại `7a78477` | Blob tại candidate | Ý nghĩa item-level (để BA xác nhận) |
|---|---|---|---|---|
| `Modules/Warehouse/app/Services/CurrentBaselineStockService.php` | sửa | +38 / −4 | `ab7ef2c6` | `mutateBalance()` nhận `?string $itemId`; thêm `resolveLineItemId()`; `postStockDocument()`/`postComponentIssue()` truyền `item_id` **khi và chỉ khi** line có đúng một item. Không đổi `business_key`, không đổi quantity/balance math. |
| `Modules/Warehouse/database/migrations/2026_08_15_000100_enforce_stock_movements_append_only_trigger.php` | mới | +67 | `3507da2c` | Cài `trg_stock_movements_append_only` **trực tiếp trên `stock_movements`**. Trước đó trigger DB-level chỉ bảo vệ bảng mirror `inventory_movements`, tức bảng ứng dụng thật sự ghi vào **không** có backstop chống UPDATE/DELETE khi bypass Eloquent. |
| `tests/Feature/ErdBusinessInvariantParityTest.php` | sửa | +12 | `fe14b5d6` | Đưa `chk_inventory_balance_non_negative` vào danh sách CHECK constraint bắt buộc; assert trigger append-only trên `stock_movements`. |
| `tests/Feature/Wwhn205StockMovementItemIdInvariantTest.php` | mới | +219 | `3513a1b7` | 4 test HTTP end-to-end: issue 1-item set `item_id`; receipt 1-item set `item_id`; receipt gộp 2 item giữ `item_id = null`; COMPONENT quantity-mode giữ `null`. |
| `.github/workflows/erd-postgres-diagnostic.yml` | sửa | +13 | `a3861699` | Mở rộng `paths` (push + thêm `pull_request`). Trước đó workflow **chỉ tự chạy khi sửa chính nó** → dormant, và đó là lý do gốc khiến gap trigger append-only tồn tại mà CI không bắt được. |
| `app/Console/Commands/ReconcileInventoryBalances.php` | dùng lại (WWHN-204) | — | `cdb34039` | Lệnh sản phẩm mà bước rebuild của P1-02R gọi. Đưa vào file set vì nếu ai đó gỡ `--rebuild` thì P1-02R **chết im lặng** — verifier kiểm marker để chuyện đó thành đỏ. |

### 2.1. Chống "còn file nhưng mất fix"

`CurrentBaselineStockService.php` đã bị **6 commit khác chạm** sau `7a78477` (WWHN-234
conflict code, Valkey cache versioning, …). Chỉ kiểm "file còn tồn tại" là vô nghĩa.
Register pin thêm **semantic marker** cho từng artifact và cổng kiểm từng marker:

| Artifact | Marker phải còn ở candidate |
|---|---|
| service | `private function resolveLineItemId(` · `?string $itemId = null` · `'item_id' => $itemId,` |
| migration | `trg_stock_movements_append_only` · `hn_erd_reject_append_only_change` |
| parity test | `chk_inventory_balance_non_negative` · `trg_stock_movements_append_only` |
| item_id test | đúng **4** method `test_` |
| workflow | `Modules/Warehouse/database/migrations/**` · `Modules/Warehouse/app/Services/**` · `pull_request` |
| reconcile command | `--rebuild` · `InventoryProjectionRebuildService` · `inventory:reconcile` |

Kết quả kiểm tại `d9f1769`: **tất cả marker còn nguyên**, và blob của từng file khớp đúng
giá trị pin trong register.

---

## 3. P1-01R — PG16 diagnostic tại đúng candidate

| Bước | Kết quả | Log thô |
|---|---|---|
| `migrate:fresh --seed --force` trên PG 16.14 | **exit 0** — chạy hết, không cần vá tay | [`raw/C1-exact-sha-d9f1769-pg16-migrate.log`](raw/C1-exact-sha-d9f1769-pg16-migrate.log) |
| Bộ test đích WWHN-205 | **36 test / 36 passed / 314 assertion** — `ErdBusinessInvariantParityTest` chạy THẬT (không `skip`, vì đang trên PostgreSQL) | [`raw/C2-exact-sha-d9f1769-pg16-target-suite.log`](raw/C2-exact-sha-d9f1769-pg16-target-suite.log) |
| Race Receipt/Issue (P1-03R) | exit 0 | [`raw/C3-receipt-issue-race.log`](raw/C3-receipt-issue-race.log) |
| Rebuild + reconciliation (P1-02R) | exit 0 | [`raw/C4-ledger-reconciliation.log`](raw/C4-ledger-reconciliation.log) |
| QA1 psql độc lập | **16/16 metric PASS** (không qua PHP/Laravel) | [`raw/C5-qa1-psql-reconciliation.log`](raw/C5-qa1-psql-reconciliation.log) |

### 3.1. Một lần chạy ĐỎ được giữ nguyên văn, không mô tả là PASS

Tại candidate cũ `c29a220`, `migrate:fresh` trên PostgreSQL **vỡ**:

```
2026_08_19_000100_add_optimistic_lock_to_pv_master_tables ......... 23.62ms FAIL
SQLSTATE[42804]: Datatype mismatch: 7 ERROR:  column "created_by" is of type uuid
                 but expression is of type text
CONTEXT:  PL/pgSQL function hn_erd_mirror_pv_industries() line 1 at SQL statement
STATEMENT: update "pv_industry" set "version" = 1 where "version" is null
```

Log giữ nguyên 190 dòng: [`raw/A1-exact-sha-c29a220-pg16-migrate-fresh-FAIL.log`](raw/A1-exact-sha-c29a220-pg16-migrate-fresh-FAIL.log).

Lỗi này **không thuộc WWHN-205**. Gốc là write-bridge sinh tự động ở
`2026_08_10_000765` build `INSERT … SELECT` **không cast type**, ngầm giả định type cột
view projection trùng type cột bảng vật lý; nhóm PV khai `NULL::text AS created_by` trong
khi ERD định nghĩa `uuid`. Trigger chỉ nổ khi có ai GHI vào bảng nguồn nên lỗi nằm im tới
khi `2026_08_19_000100` backfill `pv_industry.version`. Đã được **chủ sở hữu khác**
(WWHN-289 / DEV289-P1-02R) sửa ở commit `8bdf9ad`, và bản vá **nằm trong candidate
`d9f1769`** — đó là lý do §3 xanh mà không cần vá tay. WWHN-205 **không sửa chồng**.

### 3.2. Cổng chạy lại được

`.github/workflows/wwhn-205-invariant-gate.yml` — service `postgres:16`,
`fetch-depth: 0`, **không bước nào có `continue-on-error`**, upload toàn bộ
`docs/evidence/WWHN-205/` làm artifact 90 ngày. Cổng thực thi thật cả ba đường: race
script, reconciliation script và `psql -f`.

---

## 4. P1-02R — rebuild / reconciliation, QA1 chạy độc lập

**Thiết kế để không phải "DEV tự xác nhận":**

- Bước **rebuild** gọi đúng lệnh sản phẩm `php artisan inventory:reconcile --rebuild`
  (WWHN-204 / DEV204-P1-03, `InventoryProjectionRebuildService`) — bằng chứng nói về code
  chạy thật của hệ thống, không về một script riêng.
- Bước **khẳng định** là `scripts/wwhn206-ledger-reconciliation.sql`: SQL thuần, QA1 chạy
  bằng `psql -f`, không đi qua PHP/Laravel/service nào.
- Script PHP **đọc lại chính file `.sql` đó** rồi thực thi (bỏ lệnh meta của psql), nên
  đường chạy tay và đường CI không thể kết luận khác nhau. Cổng S-02 kiểm ràng buộc này.
- Dimension đối soát dùng đúng `LedgerReconciliationService::DIMENSION`
  = `(warehouse_id, product_id, stock_status)`, không phải một biến thể `GROUP BY` riêng —
  đúng bài học WWHN-231.
- Script **fail-closed**: một Throwable giữa chừng trước đây bị Laravel render ra console
  rồi tiến trình vẫn thoát 0. Đã bị đúng lỗi này một lần (chính là lúc phát hiện thiếu
  `--rebuild` ở candidate cũ). Nay có tiền đề tường minh + `try/catch` + exit code thật.

### Kết quả trên exact candidate `d9f1769`

| Metric register | Giá trị | Kỳ vọng |
|---|---|---|
| `ledger_balance_mismatch_count` — **ledger = balance** | **0** | 0 |
| `balance_without_ledger_count` / `ledger_without_balance_count` — **mismatch_count** | **0 / 0** | 0 |
| `duplicate_business_key_count` / `duplicate_source_count` — **duplicate_source** | **0 / 0** | 0 |
| `orphan_movement_document_count` / `orphan_movement_line_count` / `orphan_reversal_link_count` — **orphan** | **0 / 0 / 0** | 0 |
| `movement_on_unposted_document_count` / `posted_document_without_movement_count` — **rollback residue** | **0 / 0** | 0 |
| `negative_balance_count` / `negative_ledger_snapshot_count` / `inconsistent_ledger_row_count` | **0 / 0 / 0** | 0 |
| `serial_subledger_mismatch_count` — **SRS §7.5 Serial subledger** | **0** | 0 |
| `quantity_subledger_mismatch_count` — **SRS §7.5 Quantity subledger** | **0** | 0 |
| `container_integrity_mismatch_count` — **SRS §7.5 Container integrity** | **0** | 0 |
| **verdict** | `dimensions_checked=2`, `mismatch_count=0`, `ledger_rows=3`, `balance_rows=2`, `overall_status=PASS` | |

`rebuild_is_idempotent = true` — chạy `--rebuild` lần thứ hai không đổi một metric nào.
JSON đầy đủ: `runtime/wwhn206-ledger-reconciliation.json`.

> **Vượt phạm vi chữ nghĩa của P1-02R một cách có chủ đích.** Register chỉ liệt kê 4 con
> số, nhưng SRS **§7.5 Reconciliation Controls** có **bốn** check và **AC-SRS-023** đòi
> "**ba lớp** khớp sau posting" (ledger, balance, subledger). Nếu chỉ đối soát
> Ledger↔Balance thì baseline vẫn hở hai lớp. Ba metric §7.5 vì vậy được thêm vào,
> **sao y định nghĩa đang chạy thật** trong
> `OperationalReportController::inventoryReconciliation()` (`serialSubledgerCounts` /
> `boxSubledgerCounts`), để đối soát tay và báo cáo trong sản phẩm không thể kết luận
> khác nhau.

---

## 5. P1-03R — Receipt/Issue concurrency, ≥2 process thật trên PostgreSQL

**Lỗ hổng trước đó:** `PostIssue/PostReceiptConcurrencyBehaviorTest` chạy trên SQLite
`:memory:` (một connection/process) nên chỉ mô phỏng **kết quả** mà một racer thua quan
sát được, không phải race thật. Hai script race Node đã có
(`run-wwhn172-concurrency.mjs`, `run-wwhn150-concurrent-scan.mjs`) **chỉ được `node --check`**
trong CI, chưa từng thực thi. WWHN-201 có race thật nhưng **chỉ Issue×Issue**.
Register đòi cả **Receipt** — đó là phần bổ sung ở đây.

`scripts/wwhn205-receipt-issue-race.php` tái dùng nguyên
`scripts/wwhn219-concurrency-worker.php` (mỗi worker = một OS process, một
`pg_backend_pid()`, barrier bằng file, giữ row lock `hold_ms`) để không sinh ra định nghĩa
thứ hai của "thế nào là race".

### S1 — Receipt vs Issue tranh **cùng một** `inventory_balances` row (cross-direction)

| Bằng chứng | Giá trị |
|---|---|
| backend pid | `4525`, `4526` — hai connection thật |
| contention | `observed_pg_lock_wait = true`, `overlapping_critical_sections = true` |
| lock wait thật | tối đa **1642 ms** (≈ đúng `hold_ms = 1500`) |
| kết quả | cả hai **SUCCESS** — hai chiều đều hợp lệ nên đều phải qua |
| số dư | `5 → 6` (= 5 + 4 − 3), **độc lập thứ tự thắng lock** |
| version | `1 → 3` — bump đúng một lần mỗi writer ⇒ **không lost update** |
| ledger | 2 movement, `business_key` không trùng, path-consistency đúng |
| `item_id` | line gộp 4 item ⇒ `item_id = null` (đúng thiết kế WWHN-205 §2, không gán bừa) |

### S2 — Mixed oversell: Receipt(+5) + Issue(5) + Issue(5) trên số dư 5

| Bằng chứng | Giá trị |
|---|---|
| backend pid | `4550`, `4551`, `4552` — ba connection thật |
| lock wait thật | tối đa **3575 ms** — hàng đợi lock xếp chồng |
| kết quả | receipt thắng lock trước ⇒ cả 3 SUCCESS, `5 → 0` |
| bất biến | `no_negative_at_any_point`, `movement_count == success_count`, version `1 → 4`, không trùng `business_key` |

Mọi assertion của S2 **độc lập thứ tự** (kết quả hợp lệ là 0 hoặc 5 tuỳ ai thắng lock),
nên scenario không thể "xanh nhờ may".

### S3 — Hai process cùng Post **một** chứng từ receipt (no-double-movement chiều nhập)

| Bằng chứng | Giá trị |
|---|---|
| backend pid | `4569`, `4570` |
| kết quả | đúng **1 SUCCESS**, kẻ thua **REJECTED / `STATE_CONFLICT`** (chờ lock 1715 ms) |
| ledger | đúng **1** movement, `business_key == sha1('RECEIPT\|<doc>\|<line>')` |
| `item_id` | line 1 item ⇒ `item_id` **được set đúng item** — fix WWHN-205 vẫn đúng dưới contention |
| số dư | `0 → 1`, version `1 → 2` |

### Post-race reconcile (yêu cầu cuối của P1-03R)

| Check | Kết quả |
|---|---|
| `serial_divergence_confined_to_intentional_oversell_sku` | ✅ |
| `touched_dimensions_all_reconciled` | ✅ 3/3 dimension: balance = signed ledger |
| `ledger_equals_balance_globally` | ✅ (quét **toàn bộ** DB, không chỉ dimension vừa chạm) |
| `no_duplicate_business_key_globally` | ✅ |
| `no_negative_balance_globally` | ✅ |
| `no_orphan_movement_globally` | ✅ |

> **Ghi chú phương pháp — vì sao "tổng delta khớp" là chưa đủ.** Hai lost update đối xứng
> vẫn cho tổng delta đúng. Script vì vậy kiểm **path-consistency**: multiset
> `{qty_before} ∪ {final}` phải bằng `{qty_after} ∪ {initial}`. Một writer đọc số dư cũ sẽ
> tạo ra một `qty_before` lặp lại và hai vế lệch ngay.

> **Vì sao kịch bản oversell BẮT BUỘC làm lệch Serial subledger, và xử lý ra sao.**
> SRS §7.5 định nghĩa Serial subledger là `available_qty = count(Item_Instance IN_STOCK)`.
> Oversell theo đúng nghĩa là "sổ nói ít hơn số hàng đang có mặt", nên với một SKU
> serial-tracked **không thể** vừa giữ kịch bản oversell vừa giữ subledger khớp tuyệt đối —
> nếu subledger khớp thì chính pool item đã là ràng buộc và oversell không xảy ra được.
> Cách xử lý:
> 1. `seedBalance()` tạo **item thật đứng sau số dư mở đầu** và S1 **tiêu thụ** đúng các
>    item đó, nên S1/S3 khớp subledger tuyệt đối sau khi đua.
> 2. Riêng S2 cố ý oversell; race script assert **lệch bị giam đúng trong SKU của S2**.
>    Lệch ở bất kỳ SKU nào khác là defect thật và cổng đỏ.
> 3. Ba metric §7.5 được khẳng định **trước** khi đua, trên dữ liệu seed thật của
>    candidate (§4) — đúng thứ QA1 kiểm trên một deployment thật.
>
> Đây cũng là lý do thứ tự bước trong `wwhn-205-invariant-gate.yml` là **đối soát trước,
> race sau**, và runbook ghi rõ thứ tự đó.

Đóng open point kế thừa: **BE1-INV-OP-03** (WWHN-205 §9) và **BE1-OP-04** (WWHN-218) —
từ nay script race được CI **thực thi thật**, không còn `node --check`.

---

## 6. P1-04R — Traceability, PR và review record

### 6.1. Pull Request

**PR = N/A (chính thức).** Nhánh `m1` nhận commit trực tiếp; `fad4242` và `7a78477` không
đi qua merge commit nào. Register cho phép "formal PR=N/A cùng Tech Lead review", nên
review được ghi nhận dưới dạng review record ở §6.2 thay vì link PR.

### 6.2. Review record — cần chữ ký ở Jira

| Vai trò | Nội dung cần duyệt | Nơi ký |
|---|---|---|
| **Tech Lead** | (a) pin ba SHA ở §1 đúng vai trò; (b) chấp nhận quy tắc pin theo "commit cuối chạm artifact" + range invariant thay vì pin theo HEAD; (c) chấp nhận PR = N/A; (d) phạm vi race là service layer. | comment trên WWHN-205 |
| **BA** | ý nghĩa **item-level** ở §2: `item_id` chỉ set khi line có đúng một item, giữ `null` khi line gộp nhiều item — đây là quyết định nghiệp vụ, không phải field bị bỏ sót. | comment trên WWHN-205 |
| **QA1** | tự chạy lại theo [`QA1_INDEPENDENT_RUNBOOK.md`](QA1_INDEPENDENT_RUNBOOK.md) và xác nhận số liệu §4/§5. | comment trên WWHN-206 + WWHN-205 |
| **DevOps** | cổng `wwhn-205-invariant-gate.yml` chạy trên runner thật; xác nhận run ID + artifact. | comment trên WWHN-205 |

### 6.3. Artifact có thể trích dẫn bất biến

| Loại | Đường dẫn |
|---|---|
| Register máy đọc | `docs/evidence/WWHN-205/wwhn-205-source-register.json` |
| Cổng đóng | `scripts/verify-wwhn205-closure.mjs` |
| Khoá chống rot ở full-suite | `tests/Feature/Wwhn205InvariantClosureContractTest.php` |
| Workflow | `.github/workflows/wwhn-205-invariant-gate.yml` |
| Log thô | `docs/evidence/WWHN-205/raw/` |
| JSON runtime | `docs/evidence/WWHN-205/runtime/` |

---

## 7. Blocker và disposition (không mục nào để trống)

| ID | Sev | Nội dung | Owner | Disposition |
|---|---|---|---|---|
| `WWHN205-BLK-01` | P0 — **ĐÃ ĐÓNG** | `migrate:fresh` trên PostgreSQL 16 vỡ tại candidate cũ `c29a220`: `SQLSTATE[42804] column "created_by" is of type uuid but expression is of type text` trong `hn_erd_mirror_pv_industries()`. Không phải lỗi của WWHN-205 hay WWHN-252. | Phiên **WWHN-289 / DEV289-P1-02R** | Đã đóng **bởi chủ sở hữu**, WWHN-205 không sửa chồng. Bản vá `2026_08_19_000050_repair_erd_mirror_projection_casts.php` đã commit ở `8bdf9ad` và **nằm trong candidate `d9f1769`**. Log lượt đỏ giữ nguyên văn làm bằng chứng lịch sử, ghi rõ là ĐỎ. |
| `WWHN205-BLK-02` | P1 — **ĐÃ ĐÓNG** | P1-02R không thể thoả tại candidate cũ `c29a220` vì `inventory:reconcile` chưa có `--rebuild` (vào ở `da1c5af`, WWHN-204). | BE1 (WWHN-205) | Đã pin lại candidate sang `d9f1769` (có `--rebuild`), thêm `ReconcileInventoryBalances.php` vào file set + semantic marker, và script đối soát nay **fail-closed** với tiền đề tường minh thay vì thoát 0 khi hỏng. |
| `BE1-INV-OP-01` | P1 — **ĐÃ ĐÓNG** | Migration append-only trigger chưa từng chạy thật trên PostgreSQL (WWHN-205 §9). | DevOps/QA | `migrate:fresh` chạy thật trên PG 16.14 tại candidate; `ErdBusinessInvariantParityTest` chạy thật (không skip). |
| `BE1-INV-OP-02` | P1 — **ĐÃ ĐÓNG bằng cơ chế** | `erd-postgres-diagnostic.yml` vừa mở rộng `paths`, chưa xác nhận lần chạy đầu. | DevOps | `Wwhn205InvariantClosureContractTest` + cổng D-01 đỏ nếu `paths` bị thu lại; cổng mới `wwhn-205-invariant-gate.yml` chạy độc lập, không phụ thuộc `paths` của workflow cũ. |
| `BE1-INV-OP-03` | P1 — **ĐÃ ĐÓNG** | Race đa connection thật chưa có trong CI (kế thừa WWHN-218 BE1-OP-04). | BE1 + DevOps | §5 — script được CI thực thi thật (không `node --check`), có log `pg_locks` và backend pid. |
| `BE1-INV-OP-04` | P2 — **Mở, kế thừa** | Dead code chưa có disposition chính thức (`StockDocumentService`, `StockLedger`, `CurrentBaselineStockController`, `reverseStockDocument()`). | BA / Tech Lead | Ngoài phạm vi P1-01R…P1-04R, không tự ý xử lý. Treo từ WWHN-190/195/207/218. |

---

## 8. Điều tài liệu này **không** khẳng định

- Không khẳng định cổng đã chạy trên GitHub Actions runner. Toàn bộ số liệu §3–§5 là
  **chạy thật trên PostgreSQL 16.14 cục bộ** tại exact candidate; run ID của GitHub
  Actions do DevOps điền sau khi cổng chạy trên `m1` (§6.2).
- Không khẳng định race ở **tầng HTTP**. Phạm vi ở đây là service layer, đúng phạm vi đã
  được duyệt ở WWHN-219/201; race `If-Match`/`Idempotency-Key` ở controller là phần QA1
  chạy riêng.
- Không tự đánh dấu Done/Approved trên Jira. Evidence pack là **đầu vào để review**.
