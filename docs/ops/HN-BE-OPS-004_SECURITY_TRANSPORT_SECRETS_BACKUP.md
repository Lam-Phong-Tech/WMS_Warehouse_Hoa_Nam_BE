# HN-BE-OPS-004 — Vận chuyển tầng dữ liệu, quản lý bí mật và bản sao lưu

Phạm vi: WWHN-SEC-05 / SEC-07. Đối tượng đọc: DevOps, DBA, người ký cutover.

Tài liệu này KHÔNG chứa giá trị bí mật nào.

---

## 1. Vận chuyển: chỗ hở nằm SAU API, không phải trước nó

Biên `browser → API` đã chặt từ trước (HSTS/CSP/nosniff/CORS allow-list/cookie
HttpOnly + `transport-security.ts` chặn HTTP cả lúc build lẫn lúc chạy). Ba đường
mạng **phía sau** API thì chưa ai nhìn tới, dù chúng chở đúng thứ dữ liệu mà TLS
phía trước đang bảo vệ.

### 1.1 PostgreSQL

Giá trị cũ: `'sslmode' => env('DB_SSLMODE', 'prefer')`.

`prefer` là chế độ nguy hiểm nhất của libpq vì nó **thành công cả khi không có
TLS**: client thử bắt tay, server từ chối, libpq âm thầm chạy tiếp bằng kết nối rõ.
Không exception, không log, không dấu hiệu nào.

Mặc định mới, phụ thuộc môi trường (`config/database.php`):

| APP_ENV | sslmode mặc định |
|---|---|
| `local`, `testing` | `prefer` (giữ nguyên hành vi cũ, dev không cần CA) |
| mọi giá trị khác, **kể cả gõ sai** | `require` (fail-closed) |

Bằng chứng runtime 08/09/2026 — cùng một container, chỉ khác `APP_ENV`:

```
APP_ENV=local       sslmode=prefer   -> kết nối OK
APP_ENV=production  sslmode=require  -> SQLSTATE[08006] "server does not support
                                        SSL, but SSL was required"
```

Đó là fail-closed thật, đo trên đường dây, không phải giá trị trong file config.

**Đích đến là `verify-full`**, không phải `require`: `require` mã hoá nhưng không
xác thực danh tính server nên vẫn MITM chủ động được. Nâng lên cần
`DB_SSLROOTCERT` trỏ tới CA của cụm DB — CA đó do DevOps sở hữu và không nằm trong
repo. `wms:golive-check` → `security.data_transport` phát **cảnh báo**
`db_sslmode_unverified` khi còn ở `require`, và **FAIL** nếu khai `verify-*` mà
thiếu CA.

Biến liên quan: `DB_SSLMODE`, `DB_SSLROOTCERT`, `DB_SSLCERT`, `DB_SSLKEY`
(bốn khoá này được Laravel ghép thẳng vào DSN và chỉ ghép khi khác null).

### 1.2 Valkey / Redis

Valkey **không chỉ giữ cache vô hại**: `SESSION_DRIVER=redis`,
`QUEUE_CONNECTION=redis`, toàn bộ bộ đếm rate-limit, và lớp cache đọc (55/84
endpoint) trong đó có payload báo cáo bảo hành mang `customer_name/phone/address`.

Trước SEC-05, config **không có khoá `scheme`** nào → phpredis luôn nối TCP trần.

Mặc định mới giữ nguyên `tcp` (không môi trường nào đang chạy bị đổi hành vi).
Production đặt:

```
REDIS_SCHEME=tls
REDIS_SSL_CAFILE=/đường/dẫn/ca.pem
REDIS_SSL_VERIFY_PEER=true        # mặc định; đặt false là biến TLS thành lớp mã hoá vô danh
```

> **Bẫy đã sập khi làm, ghi lại để không ai sập lại**: truyền `context` là **mảng
> rỗng** thay vì `null` khi chưa bật TLS vẫn khiến
> `PhpRedisConnector::establishConnection()` đẩy tham số stream-context thứ 7
> xuống `Redis::connect()`, và phpredis khi đó **cố bật TLS** → `SSL: Handshake
> timed out` / `Failed to enable crypto` / `Redis server went away`. Chỉ `null`
> mới thật sự là no-op. Test `WwhnSec05DataTransportTest::test_redis_context_la_null_khi_chua_bat_tls`
> khoá lại đúng cái bẫy này.

### 1.3 Object storage

`security.data_transport` FAIL nếu endpoint của đĩa `s3`/`r2` không phải `https`
ngoài local/testing (loopback và host `minio` được miễn — đó là stack dev).

**Chưa xác minh được từ code, cần DevOps xác nhận**: bucket có bật mã hoá phía
máy chủ không, và ở mức nào. Ba mức khác nhau về ý nghĩa và không được lẫn lộn:

- **mã hoá mặc định của provider** (Cloudflare R2, MinIO có cấu hình KMS): dữ liệu
  được mã hoá trên đĩa bằng khoá của provider. Với R2 điều này **luôn bật và không
  cấu hình được** — khai `ServerSideEncryption` trong client là **vô nghĩa**, không
  phải là "đã bật SSE".
- **SSE-S3**: AWS quản lý khoá.
- **SSE-KMS**: khoá trong KMS của khách hàng, có audit trail và có thể thu hồi.

Chỉ SSE-KMS mới bảo vệ được trước kịch bản "credential của bucket bị lộ". Đừng
thêm cấu hình `ServerSideEncryption` vào `config/filesystems.php` cho R2 chỉ để
checklist đẹp — nó không làm gì cả.

Điểm đã tốt sẵn: bucket ảnh PV để **private**, ảnh phục vụ qua
`PvProductImageController` với route constraint + regex kiểm lại `key`, storage key
là **UUID** (không đoán được), mọi đường `*/download` đều có `permission:`, và
`NoStoreApiResponses` ép `no-store, private` cho mọi response `api/*`.

---

## 2. Bí mật

### 2.1 Hiện trạng và việc bắt buộc phải làm

`.env` không bị track git (đã kiểm `git ls-files`). Nhưng bí mật vẫn nằm **plaintext
trong file** trên máy lập trình viên: `APP_KEY`, `JWT_SECRET`, `DB_PASSWORD`,
`REDIS_PASSWORD`, `MAIL_PASSWORD`.

> **Việc phải làm ngay, không phải việc của code**: `.env` của môi trường dev chứa
> một **mật khẩu ứng dụng Gmail thật** cho `MAIL_USERNAME`. Bất kỳ credential nào
> từng tồn tại trong `.env` của một máy lập trình viên phải được coi là **đã lộ**.
> Chủ tài khoản đó cần **thu hồi App Password** trong Google Account → Security →
> App passwords, và cấp giá trị mới **chỉ qua secret manager của môi trường**,
> không dán vào ticket/chat. Claude không có quyền và không được tự làm việc này.

### 2.2 Nguyên tắc

- Không secret trong image, không trong git, không trong log CI.
- Tách hoàn toàn dev / staging / prod — **không dùng lại** một giá trị nào.
- Bí mật được **inject lúc deploy** (env của orchestrator / secret manager), không
  bake vào artifact.
- **Đừng ép máy lập trình viên chạy Vault chỉ để boot app**: keyring PII tự dẫn
  xuất từ `APP_KEY` ở `local`/`testing` (xem HN-BE-OPS-003 §4.3), và
  `wms:golive-check` chặn đúng khi ra khỏi dev.

### 2.3 Xoay bí mật — mỗi loại một cách, không gộp

| Bí mật | Hệ quả khi xoay | Cách làm |
|---|---|---|
| `MAIL_PASSWORD` | Không có | Thu hồi ở Google, cấp mới, cập nhật secret manager |
| `DB_PASSWORD`, `REDIS_PASSWORD` | Kết nối đứt trong lúc đổi | Đổi trên server → cập nhật secret → restart cuốn chiếu |
| `APP_KEY` | Người dùng phải đăng nhập lại | Chấp nhận được — **với điều kiện** PII không nằm dưới APP_KEY (đó chính là lý do có keyring riêng) |
| `JWT_SECRET` | **Đăng xuất toàn hệ thống** | Xem §2.4 |
| Khoá PII | Mất dữ liệu nếu làm sai | HN-BE-OPS-003 §4.4 |

### 2.4 Về JWT: HS256 → RS256/ES256

Đề xuất, **chưa thực hiện**, và cố ý chưa: thư viện `tymon/jwt-auth` không hỗ trợ
sẵn nhiều khoá xác minh song song theo `kid`. Xoay `JWT_SECRET` hiện tại =
**đăng xuất toàn bộ người dùng ngay lập tức**.

Giá trị thật của việc chuyển sang khoá bất đối xứng ở dự án này là **thấp** khi chỉ
có một dịch vụ vừa phát vừa xác minh token: khoá công khai không giúp ai cả nếu
không có bên thứ hai cần xác minh độc lập. Nó chỉ đáng làm khi xuất hiện dịch vụ
thứ hai (Mini App backend riêng, gateway, hoặc bên tích hợp). Chuyển thuật toán chỉ
vì "web lớn dùng RS256" là thay đổi kiến trúc không có mô hình tấn công đi kèm.

Việc **đáng làm ngay** với JWT là thứ khác và đã có gate: giữ TTL access token
ngắn. `wms:golive-check` chặn `JWT_TTL` > 120 phút ngoài dev
(`golive.session.max_jwt_ttl_minutes`). `.env` dev đang là `43200` (30 ngày) cho
đợt quét — **phải hoàn tác về 60** sau đợt quét; refresh token xoay vòng đã lo phần
"không bắt người dùng đăng nhập lại".

---

## 3. Bản sao lưu

### 3.1 Sự việc

08/09/2026 tìm thấy `C:\LAM_PHONG_TECH\wms_hoanam-20260904-035730.dump` —
pg_dump custom format, 2,3 MB, **không mã hoá, không đặt mật khẩu**, nằm ngay thư
mục cha của cả hai repo.

Thư mục đó tình cờ **không phải** git repo nên nó chưa lọt vào lịch sử. Nhưng chỉ
cần một lần `pg_dump` chạy *bên trong* repo rồi `git add .` là toàn bộ PII lên
remote **vĩnh viễn** (git giữ blob kể cả sau khi xoá file).

**File này KHÔNG bị xoá bởi đợt hardening** — xác định retention là quyết định của
chủ dữ liệu, không phải của người làm bảo mật. Xem §3.3.

### 3.2 Hàng rào đã dựng

`.gitignore` của **cả hai repo** nay chặn: `*.dump`, `*.dump.gz`, `*.pgdump`,
`*.sql.gz|bz2|xz`, `*.bak`, `backup/`, `backups/`, và vật liệu khoá `*.pem`,
`*.key`, `*.p12`, `*.pfx`, `*.jks`, `*.keystore`. Đã kiểm: không file nào đang được
track bị các mẫu này che.

Đây là hàng rào **cuối**, không phải hàng rào chính.

### 3.3 Chính sách (cần chủ dữ liệu ký)

1. **Mã hoá ngay lúc tạo**, không phải "mã hoá sau":
   ```bash
   pg_dump -Fc wms_hoanam | age -r <public-key> > wms_hoanam-<ts>.dump.age
   # hoặc: | gpg --encrypt --recipient <key-id>
   ```
   Dump không mã hoá không bao giờ được tồn tại trên đĩa, dù chỉ vài phút.
2. **Ngoài cây làm việc**: không bao giờ đặt trong hoặc cạnh repo.
3. **Checksum** (`sha256sum`) lưu tách khỏi file, để phát hiện hỏng/sửa.
4. **Kiểm tra khôi phục định kỳ**: một bản sao lưu chưa từng restore thử là một
   giả định, không phải một bản sao lưu.
5. **Thời hạn lưu trữ** rõ ràng + xoá an toàn khi hết hạn.
6. **Quyền truy cập** tối thiểu, có ghi nhận ai tải bản nào.

**Việc cần làm với file đang tồn tại**: xác định nó còn cần không. Nếu còn — mã hoá
tại chỗ rồi chuyển vào vùng lưu trữ có kiểm soát. Nếu không — xoá an toàn. Trong
lúc chưa quyết, tối thiểu hãy chuyển nó ra khỏi thư mục chứa repo.

---

## 4. Checklist cutover (phần bảo mật)

Chạy `php artisan wms:golive-check --json=<đường dẫn>` trên **chính môi trường
đích**. Các mục dưới đây phải xanh:

- [ ] `security.runtime_config` — `APP_DEBUG=false`, `APP_KEY`/`JWT_SECRET` có và
      không phải giá trị mặc định, `JWT_TTL` ≤ 120 phút, blacklist bật,
      `TRUSTED_PROXIES` khai đúng, `LOG_STACK=daily`, `LOG_LEVEL=info`
- [ ] `security.browser_runtime_contract` — `APP_URL` https, mọi CORS origin https,
      không wildcard, refresh cookie `Secure` + `SameSite` đúng site
- [ ] `security.data_transport` — DB `require` (mục tiêu `verify-full` + CA),
      Redis `tls` + có mật khẩu + `verify_peer`, storage endpoint https
- [ ] `security.pii_at_rest` — keyring PII khai tường minh (không dẫn xuất từ
      APP_KEY), khoá mã hoá ≠ khoá blind index, không còn cột plaintext
- [ ] `security.route_default_deny` — hợp đồng route/permission khớp runtime
- [ ] `security.account_hygiene` — không còn tài khoản test/demo, không mật khẩu yếu

Ngoài gate của backend:

- [ ] `node scripts/check-frontend-security-headers.mjs https://<origin FE thật>`
      trả PASS — chứng minh `deploy/nginx/frontend.conf.example` đã **thật sự**
      được áp lên máy chủ tĩnh, chứ không chỉ tồn tại trong repo dưới dạng mẫu
- [ ] `API_DOCS_ENABLED=false` trên production
- [ ] `VITE_SCANNER_KIOSK_MODE=false`
- [ ] Đã thu hồi và cấp lại mọi credential từng xuất hiện trong `.env` của máy dev
