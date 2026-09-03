# Chịu tải WMS Hoà Nam — những gì đã làm và vì sao

Tài liệu này giải thích lớp cấu hình chịu tải được thêm vào dự án: nó gồm những
gì, vì sao từng mục có mặt, và cách chỉnh cho máy thật.

**Bất biến của toàn bộ công việc này:** không có thay đổi nào làm đổi hành vi
nghiệp vụ. Stack dev chạy `docker compose up` vẫn y như cũ. Lớp chịu tải nằm
trong một file overlay riêng và chỉ có hiệu lực khi được gọi tên tường minh.

---

## 1. Vấn đề thật sự nằm ở đâu

Đo đầu tiên, trước mọi thay đổi, trên đường `GET /` — một route trả về đúng một
chuỗi tĩnh, **không** chạm cơ sở dữ liệu, **không** qua middleware nào,
**không** đọc session:

```
GET /   0.301s
GET /   0.279s
GET /   0.279s
GET /   0.305s
```

**280–300 mili-giây cho một chuỗi tĩnh.** Trên một cài đặt Laravel bình thường
con số này là 5–15ms. Nghĩa là mỗi request của hệ thống — bất kể nó làm gì —
đang trả trước gần 300ms chi phí khởi động khung ứng dụng.

Đây là phát hiện quan trọng nhất: nút thắt **không** nằm ở truy vấn SQL hay ở
logic nghiệp vụ. Nó nằm ở phần chi phí cố định trả cho **mọi** request. Bao
nhiêu người dùng đồng thời cũng vô nghĩa nếu mỗi request tự nó đã tốn 300ms
trước khi bắt đầu làm việc.

Bốn nguyên nhân, xếp theo mức đóng góp:

| Nguyên nhân | Vì sao tốn | Cách xử lý |
|---|---|---|
| Không cache config/route | Mỗi request đọc và phân tích lại 30 file `config/`, quét manifest của 4 module, đăng ký lại 342 route | `config:cache` + `route:cache` + `event:cache` lúc container khởi động |
| `opcache.validate_timestamps=1` trên bind mount Windows | PHP `stat()` từng file mã nguồn để kiểm tra đã đổi chưa; qua lớp chia sẻ file của Windows mỗi lần `stat()` đắt hơn Linux nhiều lần | `validate_timestamps=0` trong hồ sơ chịu tải |
| `APP_DEBUG=true` | Laravel thu thập toàn bộ truy vấn SQL và stack trace cho **mọi** request, kể cả request thành công | `APP_DEBUG=false` |
| `LOG_LEVEL=debug` | `CacheService` ghi một dòng log cho **mỗi** lần đọc cache (`cache.hit`/`cache.miss`); ghi thẳng ra file trên bind mount Windows | `LOG_LEVEL=warning`, `LOG_STACK=stderr` |

---

## 2. Ba trần cứng sẽ chặn hệ thống trước khi tới 3000 người

Ngay cả khi sửa xong độ trễ mỗi request, ba giới hạn dưới đây vẫn chặn:

### 2.1 Postgres: `max_connections=100`

Ảnh `postgres:16-alpine` giữ nguyên mặc định của Postgres. Trong khi đó
`docker/php/www.conf` đặt `pm.max_children=150`. Nghĩa là ở tải cao, php-fpm cố
mở tới 150 kết nối vào một máy chủ chỉ nhận 100 — request thứ 101 nhận
`FATAL: sorry, too many clients already` và người dùng thấy lỗi 500.

`shared_buffers=128MB` cũng là mặc định "chạy được ở mọi nơi", không phải cấu
hình chạy tải.

### 2.2 Ngân sách RAM không khớp với máy

Chú thích trong `docker/php/www.conf` tính `pm.max_children=150` dựa trên giả
định máy 8 vCPU / **16GB** RAM. Máy thật đang chạy là Docker Desktop 16 vCPU /
**8GB**. 150 worker × ~45MB ≈ 6,7GB — cộng Postgres, Valkey, MinIO thì vượt
tổng RAM. Kết quả không phải "chậm" mà là **bị OOM-kill**: request đang xử lý
mất trắng.

### 2.3 Client Redis là `predis`

`.env` đặt `REDIS_CLIENT=predis` — client viết bằng PHP thuần. Mỗi request WMS
chạm Redis nhiều lần (cache RBAC, session, đếm rate limit). Image trước đây
không cài `ext-redis` nên không có lựa chọn nào khác.

---

## 3. Những gì đã thêm

Tất cả đều là file mới, trừ hai chỗ nói rõ bên dưới.

### `docker-compose.perf.yml` — overlay chịu tải

Không tự động được nạp. Bật bằng:

```bash
docker compose -f docker-compose.yml -f docker-compose.override.yml -f docker-compose.perf.yml up -d --build
```

Nội dung là **tham số vận hành**, không phải logic: kích thước pool php-fpm,
cấu hình opcache, giới hạn RAM/CPU từng container, tham số Postgres, chính sách
bộ nhớ Valkey. Mọi con số đọc từ biến môi trường nên cùng một file chạy được
trên máy dev 8GB lẫn server 32GB — xem `.env.perf.example`.

### `docker/php/perf-entrypoint.sh` — sinh cấu hình PHP lúc khởi động

php-fpm **không** nội suy biến môi trường trong file cấu hình pool (nó chỉ hiểu
`$pool`). Muốn một image chạy được nhiều cỡ máy khác nhau thì phải sinh file lúc
container khởi động. Script này ghi `zz-wms-pool.conf` và `zz-wms-opcache.ini`
theo biến môi trường, hâm nóng cache config/route/event, rồi **giao lại** cho
entrypoint gốc trong image (không sao chép lại logic composer/migrate của nó).

### `docker/nginx/perf/` — cấu hình nginx chịu tải

Giữ **nguyên văn** hợp đồng `X-Forwarded-*` của WWHN-295. Phần thêm vào:

- `upstream` tĩnh có `keepalive 128` — nginx giữ sẵn bể kết nối tới php-fpm
  thay vì bắt tay TCP mới cho từng request.
- `worker_connections 16384`, `worker_rlimit_nofile 65535`.
- `access_log off` — ở vài trăm request/giây, mỗi dòng log là một lượt ghi đĩa.
- `fastcgi_next_upstream off` — **cố ý**. Các endpoint ghi của WMS (đăng phiếu
  nhập/xuất) không idempotent; phát lại request khi upstream lỗi có thể tạo bút
  toán tồn kho lặp. Thà trả 502 để FE xử lý.

> **Chú ý khi chuyển GIỮA hồ sơ perf và stack dev.** Hồ sơ perf chạy
> `config:cache` và ghi `bootstrap/cache/config.php` — file này nằm trên bind
> mount **dùng chung** với stack dev. Sau khi chạy perf rồi quay lại
> `docker compose up` thường lệ, dev sẽ đọc phải config đã cache của perf (sai
> DB/driver). Chạy một lần để dọn:
> ```bash
> docker compose exec backend php artisan config:clear
> docker compose exec backend php artisan route:clear
> docker compose exec backend php artisan event:clear
> ```

### `load/` — bộ đo tải k6

31 endpoint đọc chia theo nhóm nghiệp vụ với trọng số theo tần suất thật, chạy
theo mô hình mở. Xem `load/README.md`.

### Hai file có sẵn được sửa

1. **`docker/php/Dockerfile`** — thêm `ext-redis`. Chỉ *cài sẵn*, không bật:
   driver vẫn do `REDIS_CLIENT` quyết định, stack dev giữ nguyên `predis`.
2. **`docker/php/www.conf`** và **`docker/php/opcache.ini`** — giữ nguyên, không
   đụng tới. Hồ sơ chịu tải ghi đè chúng lúc chạy chứ không sửa file.

---

## 4. Thay đổi trong mã nguồn

Đều nằm trên đường đi của request đọc nóng, đều được chứng minh không đổi hành
vi (mục 5).

> **Đã cân nhắc và LOẠI BỎ — `CheckPermission` bỏ truy vấn người dùng thừa.**
> Ý định ban đầu là đảo thứ tự trong `CheckPermission` để dùng lại người dùng mà
> guard `auth:api` đã phân giải, tránh gọi `JWTAuth::authenticate()` lần hai
> (một truy vấn DB thừa mỗi request). Nhưng bộ kiểm thử phát hiện lời gọi
> `JWTAuth::setToken(...)` cũ có một **tác dụng phụ ngầm**: nó đặt token hiện
> hành lên singleton JWTAuth mà một số nhánh hạ tầng phía sau dựa vào. Bỏ nó đi
> làm 8 test bảo mật/RBAC chuyển đỏ (trả sai mã lỗi, ví dụ 403 DATA_SCOPE_DENIED
> thay cho 428). Lợi ích nhỏ nhất trong nhóm mà rủi ro chức năng thật, nên thay
> đổi này **không được áp dụng** — `CheckPermission` giữ nguyên bản gốc.

### 4.1 `User::isSuperAdmin()` — đưa vào cache RBAC đã có sẵn

`hasPermission()` gọi `isSuperAdmin()` trước tiên, và `isSuperAdmin()` luôn
truy vấn bảng vai trò. Danh sách quyền thì đã được cache trong Valkey
(`AuthCache::rememberPermissions`), nên sau khi cache nóng, **truy vấn vai trò
này là lần chạm cơ sở dữ liệu duy nhất còn lại** của bước kiểm tra quyền.

Sửa: cache đúng một giá trị boolean qua `AuthCache`, dùng chính khoá đã có
`auth:user:{id}:v:{version}:...`. Cơ chế vô hiệu hoá **không đổi**: mọi chỗ đang
tăng `app_user.version` (gán/thu hồi vai trò, đổi trạng thái) tự động vô hiệu
hoá luôn khoá mới này, y như với hai khoá cũ.

Ghi chú về độ trễ dữ liệu: giá trị này đi cùng TTL 300 giây như
`permissionCodes()`. Không phát sinh độ trễ mới — danh sách quyền vốn đã được
suy ra từ chính các vai trò đó và đã cache 300 giây từ trước.

### 4.2 Sửa cache hỏng ở 3 endpoint đọc nóng (bug có sẵn)

Đo tải phát hiện một bug **có sẵn từ trước** (không do công việc này tạo ra) là
điểm nghẽn LỚN NHẤT ở tầng ứng dụng. Ba endpoint — `GET /inventory/balances`,
`GET /brands`, `GET /product-categories` — ghi log `cache.corrupted` trên MỌI
request và không bao giờ cache được:

- `inventory/balances`: payload cache chứa một object `Carbon` (cột `updated_at`).
- `brands`, `product-categories`: dùng `DB::table()` nên mỗi dòng là `stdClass`.

`CacheService` giải nén cache với `allowed_classes=false` (chốt an ninh chống
gadget-chain, `config/cache.php`). Ràng buộc đó biến mọi object thành
`__PHP_Incomplete_Class`, nên `CacheService` coi giá trị là hỏng, xoá đi và dựng
lại từ DB **ở từng request**. Với `inventory/balances` — vốn dùng
`rememberStampede` (có `Cache::lock`) và chạy một phép tổng hợp nặng — hệ quả
dưới tải đồng thời là mọi request dồn qua một khoá không bao giờ đọng cache: một
điểm nối tiếp làm sập thông lượng.

Sửa (đã kiểm chứng byte-for-byte, xem mục 5): serialize sẵn giá trị về đúng dạng
mà response vẫn trả:
- `updated_at` → `->toJSON()` (Carbon vốn tự `jsonSerialize` bằng `toJSON`, nên
  chuỗi ra không đổi một byte).
- dòng `stdClass` → `(array)` (mảng kết hợp và object mã hoá JSON giống hệt nhau).

Tác động đo được (hồ sơ baseline, cùng máy): p95 của request thành công giảm từ
1,43s xuống 0,81s; thông lượng tăng ~26%; số lỗi 5xx giảm từ 21 xuống 3.

### 4.3 Route `/` chuyển từ Closure sang controller

`php artisan route:cache` **thất bại toàn bộ** nếu có bất kỳ route nào dùng
Closure — Closure không tuần tự hoá được. Cả dự án chỉ có đúng một chỗ:
`routes/web.php` trả chuỗi kiểm tra sức khoẻ.

Chuyển sang một controller `__invoke` giữ nguyên đường dẫn, nguyên nội dung trả
về, nguyên `withoutMiddleware('web')` — và mở khoá `route:cache` cho 342 route
còn lại.

---

### 4.4 `User::isSuperAdmin()` — cache RBAC (nhắc lại vị trí)

Xem mục 4.1 ở trên — đây là thay đổi cùng nhóm, giữ đánh số cũ để tham chiếu.

## 5. Kiểm chứng không đổi hành vi

Ba tầng bằng chứng:

1. **So khớp JSON byte-for-byte** cho ba endpoint sửa cache: chụp response
   trước khi sửa (golden), sửa, chụp lại, so sánh sau khi chuẩn hoá đúng các
   trường vốn sinh mới mỗi request (`data_as_of` = `now()`, `request_id`,
   `timestamp` của envelope). Phần dữ liệu — gồm cả `updated_at` — **giống hệt**.

2. **Bộ kiểm thử đầy đủ** (~1100 test) chạy trên SQLite in-memory trước và sau
   toàn bộ thay đổi. Cách chạy đúng (bắt buộc ép SQLite, guard của dự án chặn
   chạy test trên CSDL thật):
   ```bash
   docker compose run --rm --no-deps \
     -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e DB_URL= \
     backend php -d memory_limit=1536M vendor/bin/phpunit
   ```

3. **Khoanh vùng hồi quy**: một ý định sửa (`CheckPermission`) bị bộ test bắt
   lỗi và đã LOẠI BỎ (xem hộp cảnh báo ở mục 4). Các thay đổi giữ lại đều cho
   kết quả test giống hệt bản gốc; những lỗi test còn lại trên máy này đã được
   đối chứng là **có sẵn từ trước** (chạy trên bản gốc cũng lỗi y hệt), không do
   công việc này gây ra.

Số liệu tải trước/sau và cách chạy lại: `docs/perf/LOAD_TEST_REPORT.md`.

---

## 6. Việc còn lại khi triển khai thật

Những mục dưới đây **không** được tự động áp dụng vì chúng cần quyết định của
người vận hành:

| Việc | Vì sao chưa làm |
|---|---|
| `JWT_TTL` đang là 43200 (30 ngày) | Đang để tạm cho đợt quét scanner, ghi chú hoàn tác đã có sẵn trong `.env`. Đặt lại 60 trước khi chạy thật. |
| `BCRYPT_ROUNDS=12` | Mỗi lần đăng nhập tốn ~200–300ms CPU. Với 3000 người đăng nhập đầu ca, đây là khoản CPU lớn nhất của hệ thống. Giá trị 10 vẫn an toàn và rẻ hơn ~4 lần. Đổi giá trị **không** làm hỏng mật khẩu cũ. Đây là quyết định về mức an toàn nên để người chủ hệ thống chọn. |
| PgBouncer (gộp kết nối) | Ở chế độ transaction pooling, PgBouncer đổi cách prepared statement và lệnh `SET` phía phiên hoạt động. Đây là rủi ro chức năng thật, cần một đợt kiểm thử hồi quy riêng. Nâng `max_connections` đủ giải quyết vấn đề ở quy mô một máy. |
| Laravel Octane | Bỏ được hoàn toàn chi phí khởi động khung, nhưng giữ ứng dụng sống giữa các request nên mọi trạng thái tĩnh/singleton rò rỉ đều thành lỗi. Không nên làm nếu chưa có đợt kiểm thử hồi quy toàn bộ. |
| `synchronous_commit=off` cho Postgres | Nhanh hơn rõ rệt nhưng đánh đổi bằng khả năng mất các giao dịch cuối khi mất điện. Với hệ thống kho, không nên. |
