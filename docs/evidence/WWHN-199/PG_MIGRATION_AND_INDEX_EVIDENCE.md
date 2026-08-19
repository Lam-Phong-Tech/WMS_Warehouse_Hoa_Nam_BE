# WWHN-199 · DEV199-P1-02R — Migration PostgreSQL, `pg_indexes`, duplicate behaviour và chiến lược khôi phục

> Register yêu cầu, nguyên văn:
>
> *"Chạy migration trên PostgreSQL candidate; query `pg_indexes`; xác minh unique behavior.
> Vì 000770 fail-forward, ghi explicit restore/fail-forward recovery thay vì giả định
> rollback migration"* — evidence: *migration output + pg_indexes + duplicate test + restore strategy*.

Tài liệu này trả lời đúng 4 mảnh đó. Mọi con số bên dưới đều đọc ra từ file evidence
được sinh bằng một lệnh (§6), không phải ghi tay.

---

## 1. Vì sao vòng trước bị bác

Bản rà soát 2026-08-15 (mục 2 của `docs/docs_task_jira/WWHN-199.md`) kết luận backstop
DB tồn tại **bằng cách đọc migration**:

> *"Backstop DB-level (Postgres-only, xác nhận qua migration `...000670.php:290` và
> `...000770.php:236`)"*

và tự ghi nhận giới hạn ở open point `BE1-RESV-OP-02`: *"chưa được chạy thật trên
PostgreSQL trong phiên rà soát này (không có Postgres khả dụng cục bộ)"*.

Đọc migration chỉ chứng minh **ý định**. Nó không chứng minh index thật sự tồn tại sau
khi toàn bộ 155 migration chạy xong theo đúng thứ tự — trong khi chính repo này có tiền
lệ một migration sau **xoá** index do migration trước tạo (`000710:14` DROP
`uk_inbound_scan_active_item`). Vòng này thay toàn bộ suy luận đó bằng câu trả lời của
chính PostgreSQL.

## 2. Môi trường candidate

| Trường | Giá trị |
|---|---|
| Driver | `pgsql` |
| PostgreSQL server | **16.14** (đọc bằng `SHOW server_version` / `version()`) |
| Database | `hn_wwhn199` — **DROP + CREATE lại từ số 0** ở mỗi lần chạy |
| Cách dựng | `php scripts/wwhn199-recreate-candidate-database.php hn_wwhn199` rồi `php artisan migrate --force` |
| DB nghiệp vụ | **không bị đụng tới** — script từ chối chạy nếu tên DB trùng `DB_DATABASE` trong `.env`, và bắt buộc tiền tố `hn_wwhn` |

Việc dựng lại từ số 0 là bắt buộc chứ không phải cho sạch sẽ: nếu tái dùng schema cũ thì
index quan sát được có thể là tàn dư của một lần migrate trước đó, và bằng chứng lại
quay về mức "tin là nó đã chạy".

## 3. Migration output

Raw log: [`build/evidence/wwhn-199/migrate-pgsql-candidate.raw.txt`](../../../build/evidence/wwhn-199/migrate-pgsql-candidate.raw.txt)

Các dòng quyết định trong log (số dòng theo bản log kèm theo):

| Migration | Kết quả |
|---|---|
| `2026_08_10_000670_align_identity_container_and_scan_lifecycle` | DONE — tạo `uk_outbound_match_active_item`, `uk_inbound_scan_active_item`, `uk_container_items_one_active_item` |
| `2026_08_10_000710_enforce_cross_aggregate_business_guards` | DONE — **DROP** `uk_inbound_scan_active_item` (thay bằng trigger) |
| `2026_08_10_000770_enforce_erd_1_0_keys_relationships_and_constraints` | DONE — tạo `ux_erd_outbound_active_item`, `ux_erd_component_active_item` |
| `2026_08_18_193200_*` | DONE — tạo `ux_erd_inbound_active_item` |
| `2026_08_19_120000_consolidate_outbound_active_item_unique_index` | DONE — hợp nhất bản sao trên `outbound_item_matches` (DEV199-P2-01) |

Tổng: **toàn bộ migration chạy DONE, 0 lỗi** trên DB dựng mới. Đây là lần đầu tiên
WWHN-199 có output migration PostgreSQL thật thay vì trích dẫn source code.

## 4. Chiến lược khôi phục — KHÔNG dùng `migrate:rollback` cho nhóm ERD

### 4.1. Sự thật kỹ thuật (đọc từ code, không suy đoán)

```php
// 2026_08_10_000670_align_identity_container_and_scan_lifecycle.php
public function down(): void
{
    // Fail-forward baseline alignment. History/audit fields are intentionally retained.
}

// 2026_08_10_000770_enforce_erd_1_0_keys_relationships_and_constraints.php
public function down(): void
{
    // Fail-forward signed ERD constraints.
}
```

Cả hai `down()` **rỗng**. Hệ quả vận hành, và đây là điểm nguy hiểm thật sự:

> `php artisan migrate:rollback` sẽ **báo thành công**, **xoá dòng khỏi bảng `migrations`**,
> nhưng **không gỡ một ràng buộc nào khỏi schema**. Sau lệnh đó, bảng `migrations` và
> schema thật **phân kỳ trong im lặng**. Lần `migrate` kế tiếp sẽ chạy lại 000670/000770;
> chúng dùng `CREATE ... IF NOT EXISTS` nên không nổ, nhưng bất kỳ ai đọc bảng `migrations`
> để suy ra trạng thái schema đều đang đọc một con số sai.

⇒ **Cấm dùng `migrate:rollback` như đường khôi phục cho nhóm migration ERD.** Nó không
phải là rollback; nó là một thao tác ghi sai sổ.

### 4.2. Hai đường khôi phục được hỗ trợ

| | (A) FAIL-FORWARD — mặc định | (B) RESTORE — khi (A) không diễn đạt được |
|---|---|---|
| Khi nào dùng | Sai lệch nằm ở **một đối tượng schema xác định** (index/constraint/trigger thừa hoặc sai) | Migration đã **mất dữ liệu** hoặc đổi kiểu cột không đảo ngược được, hoặc cần quay về đúng một mốc thời gian |
| Cách làm | Viết migration tiến mới, đảo đúng đối tượng đó | Khôi phục vật lý từ bản dump chụp **trước** khi deploy |
| Tiền lệ trong repo | `000710:14` DROP index do `000670` tạo; `2026_08_19_120000` hợp nhất bản sao index (task này) | — |
| Bằng chứng đóng | `pg_indexes` sau khi chạy + test hợp đồng index | checksum dump + kết quả verify sau restore |

### 4.3. Runbook deploy nhóm ERD (DevOps thực thi)

```bash
# B1 — chụp trạng thái TRƯỚC khi deploy (bắt buộc; đây là đường (B) duy nhất)
pg_dump -Fc -h "$DB_HOST" -U "$DB_USERNAME" -d "$DB_DATABASE" -f "pre-deploy-$(date +%Y%m%dT%H%M%S).dump"

# B2 — deploy
php artisan migrate --force

# B3 — verify NGAY, fail-closed (không đợi QA phát hiện hộ)
php artisan test --filter=Wwhn199ReservationIndexContractTest
```

**B4 — cây quyết định khi B3 đỏ:**

1. **Index/constraint thừa hoặc sai tên** → đường (A): viết migration tiến, deploy tiếp.
   *Không* rollback.
2. **Thiếu backstop `ux_erd_outbound_active_item`** → đường (A) khẩn cấp: chạy lại
   `000770`, hoặc tạo thẳng index bằng migration mới. Trong lúc chưa có index, hệ thống
   vẫn còn 3 lớp chặn khác (row lock, `assertItemAvailableForIssue()`, trigger
   `trg_erd_outbound_item_exclusive`) — **suy giảm chứ không thủng**, xem §5.
3. **Dữ liệu đã sai/mất** → đường (B): `pg_restore` từ dump ở B1, sau đó chạy lại
   `php artisan test --filter=Wwhn199ReservationIndexContractTest` để chứng minh đã về
   đúng hình dạng.

### 4.4. Migration của task này thì ngược lại — có `down()` thật

`2026_08_19_120000_consolidate_outbound_active_item_unique_index` **có** `down()` tạo lại
đúng index đã bỏ. Nghĩa là riêng bước dọn dẹp của DEV199-P2-01 là **đảo ngược được bằng
`migrate:rollback`**, khác với 000670/000770. Đây là chủ ý: một migration chỉ bỏ một
object dư thừa thì hoàn toàn diễn đạt được chiều nghịch, nên không có lý do gì để nó
fail-forward.

## 5. `pg_indexes` — index nào thực sự đang bảo vệ reservation

Nguồn: [`build/evidence/wwhn-199/db-invariants.json`](../../../build/evidence/wwhn-199/db-invariants.json)
→ `checks.reservation_unique_indexes`, truy vấn thẳng `pg_indexes` trên DB candidate.

| Bảng | Index unique chặn item ACTIVE trùng | Định nghĩa PostgreSQL trả về |
|---|---|---|
| `outbound_item_matches` | `ux_erd_outbound_active_item` | `CREATE UNIQUE INDEX ... USING btree (item_id) WHERE ((item_id IS NOT NULL) AND ((status)::text = 'ACTIVE'::text))` |
| `inbound_scan_entries` | `ux_erd_inbound_active_item` | cùng hình dạng, trên `inbound_scan_entries` |

Đếm ràng buộc (assert trong verifier, fail-closed):

- `outbound_active_item_index_count` = **1**
- `inbound_active_item_index_count` = **1**

Đúng 1 — không được 0 (mất backstop) và không được 2 (bản sao dư thừa; chính là
DEV199-P2-01, xem `docs/decision-records/DISP-OUT-199-RESERVATION-INDEX.md`).

Trigger cùng tồn tại (`checks.reservation_triggers`), là lớp phòng thủ độc lập thứ ba:

| Bảng | Trigger |
|---|---|
| `outbound_item_matches` | `trg_erd_outbound_item_exclusive`, `trg_outbound_match_fill_evidence` |
| `inbound_scan_entries` | `trg_inbound_scan_active_item_guard`, `trg_inbound_scan_fill_evidence` |

## 6. Duplicate behaviour — chứng minh bằng cách cố tình vi phạm

Đọc `pg_indexes` mới chỉ chứng minh index **tồn tại**. Register đòi *unique behavior*, tức
là nó phải **chặn thật**. Verifier vì thế bỏ qua toàn bộ guard ứng dụng và `INSERT` thẳng
một match ACTIVE thứ hai cho cùng một item:

```
checks.duplicate_active_match_probe = {
  "executed": true,
  "blocked":  true,
  "sqlstate": "23505",
  "message":  "SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates
               unique constraint \"ux_erd_outbound_active_item\"
               DETAIL: Key (item_id)=(...) already exists."
}
```

Đây là điều mà cả test PHPUnit trên sqlite lẫn việc đọc migration đều **không** chứng
minh được: trên sqlite các partial unique index này chưa bao giờ được tạo (mọi migration
liên quan đều gate `DB::getDriverName() === 'pgsql'`), nên chỉ còn guard tầng ứng dụng.

## 7. Chạy lại toàn bộ bằng một lệnh

```bash
node scripts/run-wwhn199-evidence.mjs --database=hn_wwhn199 --port-a=8199 --port-b=8299
```

Lệnh này thực hiện đúng 6 bước và **fail-closed ở từng bước**: dựng lại DB → `migrate
--force` → fixture → bật 2 server process → race thật → đọc invariant. Kết quả tổng ghi
tại `build/evidence/wwhn-199/evidence-run.json`.

Cổng hợp đồng chạy được trên mọi driver (tự skip trên sqlite, assert thật trên pgsql):

```bash
php artisan test --filter=Wwhn199ReservationIndexContractTest
```

## 8. Cái tài liệu này KHÔNG khẳng định

- Không khẳng định staging/production **đang** có đúng hình dạng index này. Nó khẳng định
  rằng **tập migration tại candidate SHA, chạy trên PostgreSQL 16.14 từ DB trống, tạo ra
  đúng hình dạng đó**. Xác nhận trên môi trường thật là bước của DevOps ở §4.3-B3, dùng
  đúng lệnh test đã nêu — đây chính là hạng mục QA1 retest ở `WWHN-199_QA1_RETEST_PACK.md`.
- Không khẳng định `migrate:rollback` an toàn cho nhóm ERD. Ngược lại — §4.1 nói rõ nó
  sai sổ, và §4.2/§4.3 thay nó bằng hai đường được hỗ trợ.
