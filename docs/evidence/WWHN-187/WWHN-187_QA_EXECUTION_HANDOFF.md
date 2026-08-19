# WWHN-187 — Bàn giao QA để chạy tại exact build (`DEV187-P1-03R`, `WWHN-189`)

`WWHN-189` đang Blocked/Pending với **zero execution evidence**. Hồ sơ này gỡ blocker
bằng cách đưa ra đúng thứ QA1 cần: build cố định, lệnh chạy được, và danh sách case phải
xanh — bao gồm ca `quantity retry` mà register gọi đích danh.

## 1. Build cố định

| Repo | Nhánh | Candidate SHA |
|---|---|---|
| BE `WMS_Warehouse_Hoa_Nam_BE` | `m1` | `__BE_CANDIDATE__` |
| FE `WMS_Warehouse_Hoa_Nam_FE` | `m1` | `__FE_CANDIDATE__` |

`m1` có nhiều task chạy song song nên `HEAD` sẽ khác candidate. Điều kiện hợp lệ **không
phải** `HEAD == candidate` mà là: candidate là ancestor của `HEAD` **và** không commit
nào sau candidate chạm tập artifact đã pin. Verifier kiểm đúng bất biến đó
(`PIN-ANCESTOR`, `PIN-NO-DRIFT`), nên QA chạy trên `HEAD` mới vẫn hợp lệ miễn là gate
xanh.

## 2. Chạy gate đóng register

```bash
node scripts/verify-wwhn-187-closure.mjs --fe-repo="C:/LAM_PHONG_TECH/HoaNam_FE/WMS_Warehouse_Hoa_Nam_FE"
```

Gate kiểm: hợp đồng OpenAPI của hai alias scan (If-Match bắt buộc / Idempotency-Key tùy
chọn / đủ 409-412-422-428), digest hợp đồng khớp pin, range invariant của candidate,
sự tồn tại của từng artifact đóng register, sự tồn tại của từng test case bắt buộc, và
bốn điểm consumer phía FE. Exit 0 = xanh.

## 3. Bộ test backend

```bash
php vendor/bin/phpunit --filter "InboundScanConcurrencyBehaviorTest|Wwhn187InboundScanValidationTest|Wwhn188ScanIdempotencyContractTest|FoundationInboundPutawayReadinessTest"
```

Kết quả tại thời điểm bàn giao: **44 tests / 44 passed / 316 assertions**.

Lưu ý driver (quan trọng, đã làm nhiều lượt QA trước mất thời gian): `phpunit.xml` ép
`DB_CONNECTION=sqlite` `:memory:`. Muốn chạy trên PostgreSQL tham chiếu phải set biến
môi trường trước khi gọi, vì `<env>` trong `phpunit.xml` không có `force="true"` nên
biến shell thắng:

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=hoa_nam_audit DB_USERNAME=postgres DB_PASSWORD=postgres php vendor/bin/phpunit --filter "Wwhn188ScanIdempotencyContractTest"
```

## 4. Bộ test frontend

```bash
npx vitest run --pool=threads src/modules/inbound
npx tsc --noEmit
```

Kết quả tại thời điểm bàn giao: **22 tests / 22 passed** (2 file), `tsc` exit 0.

Nếu vitest báo `Failed to start forks worker` (máy đang tải nặng vì nhiều phiên chạy
song song), dùng `--pool=threads` như trên — không phải lỗi sản phẩm.

## 5. Bộ API/DB evidence (Postman)

Collection: `docs/postman/WWHN-187 Inbound Scan Retry.postman_collection.json`.
Chạy **tuần tự từ trên xuống trong một lần** (request sau dùng biến do request trước
set). Biến cần điền: `base_url`, `access_token`, `warehouse_id`, `component_sku_id`
(một SKU `COMPONENT`, `is_serial_tracked=false`, `status=ACTIVE`).

| # | Request | Điều phải xanh |
|---|---|---|
| 02 | Quét lần đầu quantity=2 kèm key | 200, `scanned_quantity=2`, `version=2`, `replayed=false`, `stock_effect=NONE`, `movement_created=false` |
| 03 | **Same request retry** (If-Match cũ, cùng key) | 200 (không 412), `replayed=true`, `scanned_quantity` **vẫn 2** |
| 04 | **Lost response + re-fetch** (If-Match mới, cùng key) | 200, `replayed=true`, `scanned_quantity` **vẫn 2, không phải 4** — đây là kịch bản defect gốc |
| 05 | Cùng key, đổi quantity | 409 `IDEMPOTENCY_CONFLICT` |
| 06 | Key mới, cùng nội dung | 200, `scanned_quantity=4` — nghiệp vụ hai lần quét thật vẫn phải chạy |
| 07 | Không gửi key | 200, **không** 428 (tương thích ngược) |
| 08 | Thiếu `If-Match` | 428 `PRECONDITION_REQUIRED` |

**Concurrent retries** (register yêu cầu): mở hai runner cùng lúc chạy request 03 với
cùng `Idempotency-Key`. Kết quả hợp lệ: một request 200 (`replayed` true hoặc false),
request còn lại 409 `CONCURRENCY_CONFLICT`. Điều **không** được phép xảy ra trong mọi
trường hợp: `scanned_quantity` vượt quá 2. Đối chiếu DB:

```sql
SELECT scanned_qty FROM stock_document_lines WHERE id = '<line_id>';
SELECT COUNT(*) FROM inbound_scan_entries
 WHERE inbound_line_id = '<line_id>' AND (status IS NULL OR status <> 'REMOVED');
```

Sau bước 04, `scanned_qty` phải bằng 2 và số entry ACTIVE phải bằng 1.

## 6. Kịch bản UI cần QA xác nhận bằng mắt

1. Mở phiếu nhập COMPONENT, chọn dòng, nhập BOX + số lượng, quét.
2. Ngắt mạng ngay sau khi bấm quét (DevTools → Offline), chờ lỗi hiện, bật mạng lại,
   bấm quét lại **không đổi mã/số lượng/BOX**.
3. Kỳ vọng: toast "Lần quét này đã được ghi nhận trước đó… số đã quét **KHÔNG** tăng
   thêm", và thẻ số của phiếu không nhảy gấp đôi.
4. Đổi số lượng rồi quét tiếp → phải là một lần quét mới bình thường, **không** ra 409
   `IDEMPOTENCY_CONFLICT` (FE sinh key mới khi nội dung đổi).

## 7. Cái QA1 cần dán lại vào Jira

- Lệnh đã chạy + output tóm tắt của mục 2, 3, 4.
- Kết quả Postman của mục 5, kèm hai câu SQL đối chiếu sau bước 04.
- Xác nhận mục 6 bằng ảnh chụp màn hình toast replay.
- Exact SHA của BE/FE lúc chạy (`git rev-parse HEAD` ở cả hai repo) và môi trường
  (driver DB, base_url).
