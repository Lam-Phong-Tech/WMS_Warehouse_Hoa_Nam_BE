# WWHN-309 — Biên bản xác minh vận hành cho DevOps (DEV309-P1-06 / WWHN-313)

| Trường | Giá trị |
| --- | --- |
| Người thực hiện | DevOps — Minh Tân Lê |
| Phạm vi | Xác minh **điều kiện vận hành** mà backend handover phụ thuộc |
| Không thuộc phạm vi | Environment/access/backup/monitor cấp hạ tầng — đóng ở WWHN-313 |
| Cập nhật | 2026-08-19 |

Vòng review trước ghi WWHN-313 *"Blocked/Pending"*. Điểm kẹt không phải khối lượng mà
là không có chỗ ghi bằng chứng theo từng mục. Bảng dưới đây là chỗ đó: mỗi dòng có
lệnh kiểm cụ thể, kỳ vọng cụ thể và ô điền kết quả thật.

## 1. Bằng chứng runtime đã thu tại môi trường dev (2026-08-19)

Ba mục dưới đây **đã có bằng chứng thật**, không phải kỳ vọng — DevOps chỉ cần lặp lại
cùng lệnh trên UAT/production và dán kết quả:

### 1.1. Process `schedule:work` có thật

```
$ docker inspect wms_warehouse_hoa_nam_be-scheduler-1 --format '{{json .Config.Cmd}}'
["php","artisan","schedule:work"]

$ docker ps --format "{{.Names}}\t{{.Status}}"
wms_warehouse_hoa_nam_be-scheduler-1     Up 3 hours
wms_warehouse_hoa_nam_be-queue-1         Up 3 hours
wms_warehouse_hoa_nam_be-backend-1       Up 3 hours (healthy)
wms_warehouse_hoa_nam_be-postgres-1      Up 5 hours (healthy)
```

Đây là điều kiện sống còn: **không có process này thì cả 4 nghĩa vụ định kỳ đều không
tồn tại trên thực tế**, kể cả khi `schedule:list` in ra đẹp.

### 1.2. Đủ 4 job nền bắt buộc

```
$ docker exec wms_warehouse_hoa_nam_be-backend-1 php artisan schedule:list
  30  1 * * *  php artisan inventory:reconcile ........... Next Due: 1 giờ tới
  */5 * * * *  php artisan audit:reconcile .............. Next Due: 3 phút tới
  15  2 * * *  php artisan import:purge-error-files ...... Next Due: 1 giờ tới
  10  3 * * *  php artisan warranty:purge-pii ............ Next Due: 2 giờ tới
```

`warranty:purge-pii` là nghĩa vụ pháp lý (SRS §13.8 / DR-PRIV-001): thiếu nó thì PII
hết retention tồn tại vô thời hạn.

### 1.3. `APP_BUILD_*` — điểm CHƯA đạt ở môi trường ngoài local

```
$ curl -s http://127.0.0.1:8080/api/v1/health
"build": { "sha": null, "ref": null, "built_at": null }
"environment": "local"
```

Ở `local` thì null là chấp nhận được. Ở UAT/production thì **không**: `wms:golive-check`
chặn, và quan trọng hơn là mọi gate exact-SHA mất căn cứ — reviewer không có cách nào
biết commit nào đang chạy ngoài việc tin lời khai. DevOps phải inject 3 biến này lúc
build image.

> Ghi chú trung thực về môi trường dev tại thời điểm thu bằng chứng: `checks.seed` =
> `fail` do database dev vừa bị một suite test dùng `RefreshDatabase` xoá seed. Đây là
> trạng thái dữ liệu của máy dev, không phải defect của WWHN-309; trên môi trường đã
> `db:seed` thì health trả 200.

## 2. Bảng xác minh DevOps phải điền

| # | Hạng mục | Lệnh kiểm | Kỳ vọng | UAT | Production |
| --- | --- | --- | --- | --- | --- |
| 1 | Process scheduler | `docker ps` / `systemctl status` | Có process `schedule:work` hoặc cron `schedule:run` mỗi phút | `PENDING` | `PENDING` |
| 2 | 4 job nền | `php artisan schedule:list` | Đủ 4 lệnh, đúng giờ như §1.2 | `PENDING` | `PENDING` |
| 3 | Danh tính build | `curl /api/v1/health` | `build.sha` = SHA image đang chạy, khác `null` | `PENDING` | `PENDING` |
| 4 | Health probe | cấu hình probe | Dùng `/api/v1/health`; `cache` cố ý không phải critical check | `PENDING` | `PENDING` |
| 5 | Cấu hình theo `.env.example` | so sánh env thật | `APP_DEBUG=false`, `CACHE_PREFIX` riêng từng môi trường, `CORS_ALLOWED_ORIGINS` liệt kê tường minh | `PENDING` | `PENDING` |
| 6 | Queue worker | `docker ps` | Có `queue:work`, tách container với scheduler | `PENDING` | `PENDING` |
| 7 | `AUTO_MIGRATE` | env | `false` — migrate là bước có kiểm soát, không chạy lúc boot | `PENDING` | `PENDING` |
| 8 | Backup trước migrate | runbook | Có bản backup kiểm chứng được trước mỗi lần migrate | `PENDING` | `PENDING` |
| 9 | Rollback | `docs/evidence/WWHN-300/CUTOVER_ROLLBACK_RUNBOOK.md` | Ưu tiên deploy lại image cũ / restore backup. `migrate:rollback` toàn lịch sử **từng brick DB** — chỉ dùng khi đã kiểm `down()` và có backup | `PENDING` | `PENDING` |
| 10 | Monitor/alert | cấu hình | Alert khi health != 200 và khi `audit_outbox` tồn đọng tăng | `PENDING` | `PENDING` |

## 3. Ranh giới

Backend handover (WWHN-309) chỉ khai **yêu cầu vận hành và cách kiểm**. Việc dựng
môi trường, cấp quyền truy cập, lịch backup và hệ thống monitor thuộc **WWHN-313** và
không được tuyên bố đóng trong WWHN-309.

## 4. Kết luận DevOps

| Trường | Giá trị |
| --- | --- |
| Candidate SHA đối chiếu | `PENDING` |
| Số mục đạt / tổng (UAT) | `PENDING` |
| Số mục đạt / tổng (Production) | `PENDING` |
| Hạng mục còn thiếu | `PENDING` |
| Ngày | `PENDING` |
