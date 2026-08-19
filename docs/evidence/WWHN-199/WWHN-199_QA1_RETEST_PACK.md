# WWHN-199 · DEV199-P1-05R — Gói retest độc lập cho QA1 (gỡ chặn WWHN-200)

> Register yêu cầu, nguyên văn:
>
> *"QA1 chuyển khỏi Blocked khi candidate có đủ evidence; retest duplicate, race, negative,
> DB state, rollback/retry đúng exact build"* — evidence: *QA versioned API/DB report +
> defect/retest result*.

Gói này để **QA1 tự chạy lại từ đầu**, không đọc lại kết quả của DEV. Mọi lệnh bên dưới
chạy được nguyên văn; mọi kỳ vọng đều là con số hoặc mã lỗi cụ thể, không phải "hoạt động
đúng".

---

## 0. Vì sao WWHN-200 từng bị Blocked

WWHN-200 (QA1) chờ WWHN-199 (BE1) có bằng chứng đủ mạnh về quy tắc reservation. Vòng
trước không đủ vì đúng ba lý do — gói này đóng cả ba:

| Lý do Blocked | Đóng bằng |
|---|---|
| Race chỉ là mô phỏng tuần tự trong 1 process/1 connection | §2 — race thật, 2 server process, 2 pool connection, có mốc thời gian chứng minh chồng lấn |
| Backstop DB chỉ được khẳng định bằng cách **đọc** migration | §3 — `pg_indexes` thật + probe INSERT trùng nhận 23505 |
| Bằng chứng gắn với "HEAD lúc bắt đầu", không chạy lại được | §1 — verify đúng exact build bằng SHA-256 từng artifact |

## 1. Cố định exact build trước khi test bất cứ thứ gì

Candidate SHA **không** viết cứng trong tài liệu này (viết cứng là cách hồ sơ mục ruỗng).
Nguồn sự thật là `docs/evidence/WWHN-199/MANIFEST.json`.

```bash
cd <repo>/WMS_Warehouse_Hoa_Nam_BE
git fetch --all
node -e "console.log(require('./docs/evidence/WWHN-199/MANIFEST.json').candidate.commit)"
```

Lấy SHA in ra, gọi là `$SHA`, rồi:

```bash
node scripts/verify-wwhn199-closure.mjs --expect-sha=$SHA
```

**Kỳ vọng: `PASS`.** Lệnh này đọc lại **từng artifact từ chính commit đã pin** (không đọc
working tree) và so SHA-256 với manifest. Nếu ai đó sửa một byte sau khi pin, hoặc có
commit mới chạm artifact, nó đỏ. Đây chính là điều DEV199-P1-03R đòi hỏi.

> Nếu đỏ ở check *"Không có commit nào sau candidate còn chạm artifact"*: candidate đã hết
> hạn. **Dừng lại**, yêu cầu BE1 sinh lại manifest và pin SHA mới. Đừng test tiếp trên
> build cũ.

## 2. Race + duplicate + DB state — một lệnh

```bash
node scripts/run-wwhn199-evidence.mjs \
  --database=qa1_wwhn199 \
  --port-a=8391 --port-b=8392 \
  --username=postgres --password=postgres
```

Dùng **DB riêng của QA** (`qa1_wwhn199`) và **cổng riêng** để không đụng lần chạy của DEV.
Script tự từ chối nếu tên DB trùng `DB_DATABASE` trong `.env`, và bắt buộc tiền tố
`hn_wwhn`/`qa1_wwhn` do người chạy đặt — nó **DROP DATABASE**, nên hàng rào này là bắt buộc.

Sáu bước script thực hiện, fail-closed từng bước:

| Bước | Nội dung | Kỳ vọng |
|---|---|---|
| 1 | DROP + CREATE lại DB candidate | `recreated=qa1_wwhn199 server_version=16.x` |
| 2 | `php artisan migrate --force` | toàn bộ migration **DONE**, 0 lỗi |
| 3 | Dựng fixture (user/kho/SKU/6 item/6 cặp phiếu + 1 toa) | fixture JSON được ghi |
| 4 | Bật 2 server process trên 2 cổng | cả hai trả lời `/api/v1/health` |
| 5 | Race item-level (6 vòng) + toa-level (1 vòng) | **6/6 chồng lấn, 6/6 một-winner-một-conflict**; toa-level PASS |
| 6 | Đọc DB after-state + `pg_indexes` + duplicate probe | `db-invariants.json` = **PASS** |

### 2.1. Bất biến QA phải tự mắt xác nhận trong `build/evidence/wwhn-199/race-report.json`

| Bất biến | Cách đọc |
|---|---|
| Hai request **thật sự chồng lấn** | mỗi vòng có `overlapped: true` và `overlap_ms > 0`; đối chiếu `started_at`/`finished_at` của hai racer — request B bắt đầu **trước khi** A kết thúc |
| Đúng **một** winner | `verdict.winner_count == 1` |
| Đúng **một** loser, và loser bị chặn bằng mã nghiệp vụ | `verdict.loser_count == 1`, `loser_codes` chứa `ITEM_ALREADY_MATCHED` |
| Hai racer là **hai process khác nhau** | `results[].base` là hai URL khác cổng |
| Toa-level: 2 Confirm đồng thời cùng 1 toa | mục B — winner HTTP 200 có `outbound_document_ids`, loser HTTP 409 `STATE_CONFLICT`, `docs = null` |

### 2.2. DB state — bằng chứng độc lập với mã HTTP

Trong `build/evidence/wwhn-199/db-invariants.json`:

| Kiểm tra | Kỳ vọng |
|---|---|
| `checks.item_level_after_state[*].active_matches` | **= 1** cho mọi vòng (không vòng nào có 2 match ACTIVE) |
| `checks.item_level_after_state[*].documents_holding_item` | đúng **1** phiếu, và phải là phiếu của **winner** |
| `checks.request_level_after_state[*].active_documents` | **= 1** cho mọi toa |
| `checks.outbound_active_item_index_count` | **= 1** |
| `checks.inbound_active_item_index_count` | **= 1** |
| `result` | **PASS**, `failures` rỗng |

Điểm mấu chốt: mã HTTP có thể đúng trong khi dữ liệu vẫn sai (ví dụ trả 409 nhưng đã kịp
ghi match). Bảng trên đọc thẳng từ DB nên bắt được đúng loại lỗi đó.

## 3. Negative test — DB phải chặn cả khi bỏ qua ứng dụng

Đây là phần **không** thể chứng minh trên sqlite (các partial unique index chỉ được tạo
trên PostgreSQL). Verifier đã làm sẵn và ghi kết quả:

```
checks.duplicate_active_match_probe = {
  "executed": true,
  "blocked":  true,
  "sqlstate": "23505",
  "message":  "... duplicate key value violates unique constraint \"ux_erd_outbound_active_item\" ..."
}
```

QA xác nhận: `executed = true` (đã thật sự thử) **và** `blocked = true` với `sqlstate 23505`.
Nếu `executed = true, blocked = false` ⇒ backstop DB không tồn tại ⇒ **defect P0**, dừng
nghiệm thu.

Cổng chạy độc lập, không cần dựng lại DB:

```bash
DB_CONNECTION=pgsql DB_DATABASE=qa1_wwhn199 DB_USERNAME=postgres DB_PASSWORD=postgres \
  php artisan test --filter=Wwhn199ReservationIndexContractTest
```

Kỳ vọng trên pgsql: **3 passed** (đúng 1 index outbound, đúng 1 index inbound, INSERT trùng
bị QueryException). Trên sqlite: **skipped** kèm lý do — skip ở đây là đúng, không phải né
tránh: ràng buộc đó không tồn tại trên sqlite nên khẳng định gì cũng sai.

## 4. Test hành vi qua HTTP (không cần PostgreSQL)

```bash
php artisan test --filter=Wwhn199ReservationConcurrencyGuardTest
```

Kỳ vọng: **5 passed**.

| Test case | Chặn bằng |
|---|---|
| Cùng item vào 2 phiếu xuất khác nhau | 409 `ITEM_ALREADY_MATCHED` |
| Cùng item quét 2 lần vào cùng 1 phiếu | 409 `DUPLICATE_ITEM_IN_DOCUMENT` |
| Cùng item vào 2 phiếu nhập khác nhau | 409 `ITEM_ALREADY_RECEIVING` |
| Tạo phiếu xuất lần 2 từ cùng 1 toa | 409 `OUTBOUND_REQUEST_ALREADY_USED`, toa còn đúng 1 document active |
| Item đã unmatch được match lại vào phiếu khác | 200 — guard chỉ chặn match **ACTIVE**, không khoá vĩnh viễn item |

Case cuối là negative test theo chiều ngược: nó chứng minh guard **không siết quá tay**.
Một guard chặn cả trường hợp hợp lệ cũng là defect.

## 5. Rollback / retry

### 5.1. Retry của người thua cuộc

Sau khi loser nhận 409, QA thử lại **chính request đó** trên item khác của cùng phiếu:
phải thành công. Ý nghĩa: 409 chặn đúng một item đang bị chiếm, không làm hỏng phiên làm
việc của phiếu.

Kiểm chứng trong `race-report.json`: các vòng sau vòng thua vẫn có winner 200 trên chính
racer đã thua ở vòng trước (`racer_b` thắng ở vòng 3, 4, 5 sau khi thua vòng 1, 2).

### 5.2. Rollback migration — đọc kỹ trước khi thao tác

**Không dùng `php artisan migrate:rollback` để lùi nhóm ERD.** `2026_08_10_000670` và
`2026_08_10_000770` đều có `down()` **rỗng** (fail-forward): lệnh rollback sẽ báo thành
công và xoá dòng khỏi bảng `migrations`, **nhưng không gỡ ràng buộc nào** — schema và bảng
`migrations` phân kỳ trong im lặng.

Đường khôi phục được hỗ trợ, kèm cây quyết định: `PG_MIGRATION_AND_INDEX_EVIDENCE.md` §4.

Riêng migration hợp nhất index của task này (`2026_08_19_120000`) **có** `down()` thật. QA
có thể xác minh trên DB test:

```bash
DB_CONNECTION=pgsql DB_DATABASE=qa1_wwhn199 DB_USERNAME=postgres DB_PASSWORD=postgres \
  php artisan migrate:rollback --step=1
# -> uk_outbound_match_active_item xuất hiện trở lại
DB_CONNECTION=pgsql DB_DATABASE=qa1_wwhn199 DB_USERNAME=postgres DB_PASSWORD=postgres \
  php artisan migrate --force
# -> hợp nhất lại, còn đúng 1 index
```

Chạy trên **DB test của QA**, không chạy trên môi trường dùng chung.

## 6. Mẫu báo cáo QA (versioned API/DB report)

```
WWHN-199 / WWHN-200 — QA1 retest report
Report version : v1.0
Retest date    : ____
Candidate SHA  : ____            (lấy từ MANIFEST.json, không gõ tay từ Jira)
Verifier       : node scripts/verify-wwhn199-closure.mjs --expect-sha=<SHA>   -> PASS / FAIL
Environment    : PostgreSQL ____ , DB ____ , cổng ____ / ____

A. Race            : item-level __/__ chồng lấn, __/__ đúng bất biến; toa-level PASS/FAIL
B. Duplicate       : sqlstate ____ , blocked = ____
C. Negative        : Wwhn199ReservationIndexContractTest  -> __ passed / __ skipped
                     Wwhn199ReservationConcurrencyGuardTest -> __ passed
D. DB state        : active_matches = 1 cho __/__ item; active_documents = 1 cho __/__ toa
E. Rollback/retry  : §5.1 ____ ; §5.2 ____
F. Defect mở       : ____ (ID, mức, owner, ETA)

Kết luận: WWHN-200 chuyển khỏi Blocked  YES / NO
```

## 7. Điều kiện QA1 được phép gỡ Blocked cho WWHN-200

Đủ **cả năm**, không trừ điều nào:

1. `verify-wwhn199-closure.mjs --expect-sha=<SHA>` → **PASS**;
2. Race item-level **6/6 chồng lấn và 6/6 đúng bất biến**; toa-level PASS;
3. `db-invariants.json.result = PASS`, `failures` rỗng, hai `*_index_count` đều **= 1**;
4. `duplicate_active_match_probe`: `executed = true` **và** `blocked = true` (`23505`);
5. `Wwhn199ReservationConcurrencyGuardTest` **5 passed**, `Wwhn199ReservationIndexContractTest`
   **3 passed trên pgsql**.

Thiếu bất kỳ điều nào ⇒ giữ Blocked và mở defect kèm owner/ETA. QA1 **không** được gỡ
Blocked chỉ vì hồ sơ DEV nói đã xong.
