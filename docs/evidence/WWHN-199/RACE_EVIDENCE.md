# WWHN-199 · DEV199-P1-01R — Race thật trên PostgreSQL (2 process, 2 connection)

> Register yêu cầu, nguyên văn:
>
> *"Thêm race thật trên PostgreSQL: 2 process/HTTP clients + 2 DB connections overlap cùng
> item; và 2 concurrent creates từ cùng business reservation/request invariant"* —
> evidence: *Raw race log, one winner/one conflict, DB after-state, exact SHA*.

---

## 1. Vì sao test PHPUnit cũ **không** phải race

`Wwhn199ReservationConcurrencyGuardTest` (bản 2026-08-15) chạy trong **một** process, **một**
connection, và `RefreshDatabase` bọc toàn bộ trong một transaction. Hai "racer" bên trong nó
không bao giờ là hai transaction PostgreSQL đang tranh nhau row lock — nó chỉ chứng minh
*"người thua quan sát thấy gì SAU KHI người thắng commit"*.

Đó là một khẳng định có giá trị (và vẫn được giữ, xem §6), nhưng **không** trả lời câu hỏi
mà register đặt ra: khi hai transaction thật sự chồng lấn, hệ thống có còn giữ đúng bất biến
không.

## 2. Thiết kế race

```
                    PostgreSQL 16.14 · database hn_wwhn199
                                   ▲              ▲
                    pool riêng ────┘              └──── pool riêng
                          │                              │
              php artisan serve :8199          php artisan serve :8299
                          ▲                              ▲
                          └──────── fetch() song song ───┘
                                node (một tiến trình điều phối,
                                 hai request phát đi cùng lúc)
```

Ba tính chất bắt buộc để gọi là race thật, cả ba đều được ghi vào evidence:

1. **Hai OS process khác nhau** — hai `php artisan serve` trên hai cổng; mỗi process có pool
   connection riêng, nên hai transaction thuộc hai backend PostgreSQL khác nhau.
2. **Chồng lấn có thật, đo được** — mỗi request ghi `started_at`/`finished_at` bằng
   `process.hrtime.bigint()`. Một vòng chỉ được tính là hợp lệ khi khoảng thời gian của hai
   request **giao nhau**; `overlap_ms` là độ dài phần giao. Vòng nào không giao ⇒ báo
   `overlapped: false` và cổng đỏ.
3. **Không dàn xếp bằng sleep** — hai request phát đi bằng `Promise.all`, không có độ trễ
   nhân tạo để "ép" ai thắng.

## 3. Kịch bản A — item-level: cùng một item vật lý, hai phiếu xuất khác nhau

Mỗi vòng: một item `IN_STOCK`, hai phiếu xuất DRAFT thuộc hai toa khác nhau, cùng SKU. Hai
racer đồng thời `POST /api/v1/outbound-documents/{doc}/scan` với **cùng item đó**.

Kết quả lần chạy pin trong `MANIFEST.json`
(raw: [`race-report.raw.txt`](../../../build/evidence/wwhn-199/race-report.raw.txt),
JSON: [`race-report.json`](../../../build/evidence/wwhn-199/race-report.json)):

| Vòng | `overlap_ms` | Winner | Loser | Mã lỗi của loser |
|---|---|---|---|---|
| 1 | 1285.081 | `:8299` HTTP 200 | `:8199` HTTP 409 | `ITEM_ALREADY_MATCHED` |
| 2 | 1255.703 | `:8299` HTTP 200 | `:8199` HTTP 409 | `ITEM_ALREADY_MATCHED` |
| 3 | 2024.707 | `:8299` HTTP 200 | `:8199` HTTP 409 | `ITEM_ALREADY_MATCHED` |
| 4 | 1694.461 | `:8299` HTTP 200 | `:8199` HTTP 409 | `ITEM_ALREADY_MATCHED` |
| 5 | 1929.581 | `:8299` HTTP 200 | `:8199` HTTP 409 | `ITEM_ALREADY_MATCHED` |

**5/5 vòng chồng lấn thật · 5/5 vòng đúng bất biến một-winner-một-conflict · 0 vòng có 2 winner.**

Chồng lấn 1.2–2.0 giây là rất lớn so với thời gian giữ lock — nghĩa là hai transaction thực
sự nằm đè lên nhau chứ không phải nối đuôi.

> Ghi chú đọc kết quả: ở một lần chạy trước đó (6 vòng), người thắng phân bố lẫn lộn giữa
> hai cổng (`:8199` thắng vòng 1, 2, 6; `:8299` thắng vòng 3, 4, 5). Việc ai thắng **không**
> phải bất biến và không được kỳ vọng — bất biến duy nhất là **có đúng một** người thắng.

## 4. Kịch bản B — toa-level: hai Confirm đồng thời trên cùng một toa

Cùng một `outbound_request_import` ở trạng thái `VALIDATED`, hai racer đồng thời gọi
`POST /api/v1/outbound-request-imports/{id}/confirm`.

| `overlap_ms` | Winner | Loser |
|---|---|---|
| 1625.701 | `:8199` HTTP 200, `outbound_document_ids = ["ffd76f36-…"]` | `:8299` HTTP 409 `STATE_CONFLICT`, `outbound_document_ids = null` |

Đây là bất biến nghiệp vụ ở SRS §5.2: *"Mỗi nguồn/toa hợp lệ chỉ tạo **một** outbound DRAFT"*.

## 5. DB after-state — bằng chứng độc lập với mã HTTP

Mã HTTP có thể đúng trong khi dữ liệu vẫn sai (trả 409 nhưng đã kịp ghi một match). Vì vậy
verifier đọc thẳng DB sau khi race kết thúc
([`db-invariants.json`](../../../build/evidence/wwhn-199/db-invariants.json)):

| Kiểm tra | Kết quả |
|---|---|
| `item_level_after_state[*].active_matches` | **1, 1, 1, 1, 1** — không item nào có 2 match ACTIVE |
| `item_level_after_state[*].documents_holding_item` | đúng 1 phiếu mỗi item, và đúng là phiếu của winner |
| `request_level_after_state[*].active_documents` | **1** cho cả 10 toa |
| `result` | **PASS**, `failures = []` |
| PostgreSQL | server_version **16.14**, database `hn_wwhn199` |

## 6. Quan hệ với test PHPUnit — bổ sung, không thay thế

| | Race thật (tài liệu này) | `Wwhn199ReservationConcurrencyGuardTest` |
|---|---|---|
| Chứng minh | Hai transaction chồng lấn vẫn giữ đúng bất biến | Ngữ nghĩa guard: mã lỗi nào, không tạo dữ liệu rác, unmatch-rematch vẫn chạy |
| Môi trường | PostgreSQL, 2 process, 2 connection | Driver test mặc định, 1 process |
| Chạy ở | Máy dev / CI có PostgreSQL service | Mọi nơi, mọi lúc, nhanh |
| Không chứng minh được | Các nhánh nghiệp vụ hiếm (unmatch rồi match lại…) | Bất kỳ điều gì về concurrency thật |

Cả hai đều cần. Bỏ test PHPUnit thì mất cổng hồi quy chạy nhanh; bỏ race thật thì quay lại
đúng lỗ hổng mà register bác.

## 7. Chạy lại

```bash
node scripts/run-wwhn199-evidence.mjs --database=hn_wwhn199 --port-a=8199 --port-b=8299 \
  --username=postgres --password=postgres
```

Một lệnh, sáu bước, fail-closed từng bước (dựng lại DB → migrate → fixture → 2 server →
race → invariant). Kết quả tổng: `build/evidence/wwhn-199/evidence-run.json`.

Số vòng item-level phụ thuộc fixture sinh ra ở bước 3; cổng đóng đòi **≥ 5 vòng** và
**tất cả** phải vừa chồng lấn vừa đúng bất biến — không có ngưỡng "đa số".

## 8. Cái race này KHÔNG chứng minh

- Không chứng minh gì về **Post Issue** (trừ tồn) — đó là WWHN-195/WWHN-201. Race này dừng
  ở tầng scan/match, đúng phạm vi task cha WWHN-46 *"lấy hàng/quét mã và chống ghi nhận trùng"*.
- Không chứng minh hành vi dưới tải cao (hàng trăm client). Nó chứng minh **tính đúng đắn
  của bất biến khi có tranh chấp thật**, không phải hiệu năng.
- Không thay thế nghĩa vụ của DevOps xác nhận index tồn tại trên staging/production — xem
  `PG_MIGRATION_AND_INDEX_EVIDENCE.md` §4.3.
