# WWHN-289 · `DEV289-P1-05` — Bàn giao QA1 (thực hiện trên WWHN-291)

DEV **không** tự ký kết quả retest. Tài liệu này cung cấp đúng những gì QA1 cần để chạy lại trên **build đã pin**, không phải trên HEAD trôi nổi của `m1`.

## 1. Build phải retest

| Mục | Giá trị |
|---|---|
| BE repo / nhánh | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` / `m1` |
| BE SHA | `WWHN289_FINAL_SHA` (xem `WWHN-289_PINS.json`) |
| FE repo / nhánh | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_FE` / `m1` |
| FE SHA | `WWHN289_FE_FINAL_SHA` |
| DB tham chiếu | PostgreSQL 16 |

Không retest trên nhánh HEAD không pin — `m1` là nhánh dùng chung, ticket khác vẫn đang commit trong cùng ngày.

## 2. Lệnh chạy

```bash
git -C WMS_Warehouse_Hoa_Nam_BE checkout WWHN289_FINAL_SHA
composer install --no-interaction --prefer-dist
cp .env.example .env && php artisan key:generate && php artisan config:clear
php artisan migrate:fresh --seed --force
php artisan test --filter '/(Wwhn289StockInvariantProofTest|Wwhn289SecurityRegressionProofTest|Wwhn289ClosureEvidenceTest)/'
```

Số liệu thô sinh ra tại `build/evidence/wwhn-289/*.json` — nộp kèm báo cáo.

Chạy trên DB tham chiếu PostgreSQL (biến shell thắng `phpunit.xml` vì `<env>` không có `force="true"`):

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=hoa_nam_audit DB_USERNAME=postgres DB_PASSWORD=postgres php vendor/bin/phpunit --no-coverage
```

## 3. Kết quả kỳ vọng (QA1 xác nhận đúng/sai, không phải chép lại)

| Kịch bản | Kỳ vọng |
|---|---|
| Post Receipt retry 3× cùng key/payload | 1 `stock_movement`, balance cộng một lần, header `Idempotent-Replay` = false/true/true |
| Cùng key khác payload (reverse quantity 2 → 3) | **409 `IDEMPOTENCY_CONFLICT`**, movement/balance không đổi |
| Tiêm lỗi sau `INSERT stock_movements` | 500 `INTERNAL_SERVER_ERROR` không lộ trace; movement/balance rollback; document giữ `SCANNING`; retry sau đó Post được |
| Reverse trùng cùng `movement_id` | 422 `REVERSAL_QTY_EXCEEDED`, tồn không âm |
| Đối soát ledger ↔ balance | `mismatch_count = 0`, `status = PASS` |
| Ẩn danh gọi endpoint stock | 401 `UNAUTHENTICATED`, không stock effect |
| Chỉ có `inbound.view` gọi `post-receipt` | 403 `ACCESS_DENIED`, không stock effect |
| `scope_ref` kho khác gọi nhập kho | 403 `DATA_SCOPE_DENIED`; scope đúng → 200 |
| Refresh token đã xoay vòng, dùng lại | 401 |
| 4 lần đăng nhập sai liên tiếp | lần vượt ngưỡng → 429 `RATE_LIMIT_EXCEEDED` + `Retry-After`; mật khẩu đúng cũng bị chặn trong cửa sổ khoá; audit `LOGIN_THROTTLED` |
| Gọi `/api/v1/pv/industries` quá ngưỡng | 429 đúng envelope §8.2 |
| Body lỗi bất kỳ | không có `trace`/`file`/`line`/`SQLSTATE`/mật khẩu |

## 4. Điều QA1 **không** bị buộc phải chấp nhận

- Ba defect trong register là **DEV-discovered từ baseline docs**, không phải defect UAT. Nếu `WWHN-286`/`WWHN-288` nạp defect thật khác, phạm vi retest mở rộng theo và register phải thêm dòng.
- Ngưỡng rate limit là mặc định do DEV chọn; QA1 có quyền đánh dấu là **cần BA/DevOps chốt** thay vì nghiệm thu con số.
- Bằng chứng DEV chạy trên sqlite; nếu QA1 chỉ chấp nhận PostgreSQL thì dùng kết quả CI ở exact SHA hoặc tự chạy lệnh ở §2.

## 5. Mẫu báo cáo tối thiểu

```
Build: BE <sha> / FE <sha> · DB: <driver+version> · Ngày: <yyyy-mm-dd>
Kết quả: PASS | FAIL  (nếu FAIL: kịch bản nào, actual vs expected, request-id/log)
Artifact đính kèm: build/evidence/wwhn-289/stock-invariant-proof.json
                   build/evidence/wwhn-289/security-regression-proof.json
Phạm vi regression đã chạy: <danh sách filter>
Open point chuyển cho BA: ngưỡng rate limit vận hành
```
