# SEC-20260910 P0 — SUPER_ADMIN bootstrap và xoay vòng credential đã lộ

**Phạm vi:** backend WMS Hoa Nam, từ commit `e5dbe0d` (nhánh `dev`).
**Đối tượng:** DevOps, quản trị hệ thống, Tech Lead.
**Không chứa bí mật:** mọi mật khẩu đi từ secret store vào biến môi trường của tiến trình. Không ghi vào tài liệu, ticket hay lịch sử shell.

---

## 1. Chuyện gì đã xảy ra

- Mật khẩu của tài khoản SUPER_ADMIN seed sẵn từng nằm cứng trong seeder. Nó cũng có mặt trong annotation OpenAPI, `storage/api-docs`, release pack WWHN-165, Postman, tài liệu bàn giao và script đo tải.
- Mỗi lần chạy `db:seed` ở bất kỳ môi trường nào, seeder tạo lại tài khoản `admin`, đặt lại mật khẩu đó, bật lại ACTIVE và gắn lại SUPER_ADMIN.
- `wms:golive-check` cố ý bỏ qua mật khẩu này, nên cổng readiness vẫn báo "sạch".
- Mật khẩu vẫn còn trong **lịch sử git**. Gỡ khỏi HEAD không làm nó hết lộ. Mọi môi trường từng seed bằng code cũ phải được coi là **đã lộ credential SUPER_ADMIN** cho tới khi xoay vòng xong (mục 4).

## 2. Hành vi seed mới

| Chế độ (`SeedEnvironment`) | Điều kiện | Danh mục quyền, vai trò, mapping | Tài khoản fixture | SUPER_ADMIN fixture |
|---|---|---|---|---|
| development | `APP_ENV` ∈ `golive.development_environments` (`local`, `testing`) | Có | Có. Mật khẩu = `WMS_FIXTURE_PASSWORD`, thiếu thì seeder dừng. | Có (`admin@gmail.com`, `super-admin-01..03`) |
| demo | `APP_ENV` ∈ `golive.demo_environments` (`uat`, `demo`) **và** `WMS_SEED_DEMO_ACCOUNTS=true` | Có | Có. Mật khẩu ≥ 12 ký tự và không thuộc danh sách đã lộ. | **Không** |
| production (mọi trường hợp còn lại) | — | Có | **Không.** Không tạo, sửa, kích hoạt lại hay gắn vai trò cho tài khoản nào. | **Không** |

Hệ quả vận hành:

- Chạy `php artisan db:seed --force` trên production là an toàn cho tài khoản. Nó **không** còn là cách "reset" quản trị viên.
- Seeder demo/tài khoản mẫu gọi thẳng bằng `db:seed --class=...` sẽ tự chặn ngoài development/demo.
- Các script ghi DB (`scripts/wwhn184-*`, `wwhn188-*`, `wwhn190-*`, `wwhn199-*`) từ chối chạy khi `APP_ENV=production`.

## 3. Tạo SUPER_ADMIN đầu tiên cho môi trường mới

Lệnh duy nhất: `php artisan wms:bootstrap-super-admin`.

### 3.1 Điều kiện trước

1. `php artisan migrate --force` và `php artisan db:seed --force` đã chạy, tức vai trò SUPER_ADMIN đã tồn tại.
2. Chưa có tài khoản nào giữ SUPER_ADMIN, kể cả tài khoản đang bị khoá. Nếu đã có, lệnh từ chối (xem 3.4).
3. Email quản trị viên chưa thuộc tài khoản nào, kể cả tài khoản đã xoá mềm.

### 3.2 Cách A: mật khẩu từ secret store

Secret đi thẳng vào biến môi trường của tiến trình. Không gõ giá trị trên dòng lệnh.

```bash
# Ví dụ: secret store đã nạp biến WMS_BOOTSTRAP_SUPER_ADMIN_PASSWORD vào môi trường phiên deploy
docker compose exec -T -u www-data -e WMS_BOOTSTRAP_SUPER_ADMIN_PASSWORD backend \
  php artisan wms:bootstrap-super-admin --email=<email-quan-tri> --force
```

Chính sách mật khẩu:

- Dài tối thiểu `WMS_BOOTSTRAP_MIN_PASSWORD_LENGTH` ký tự (mặc định 16).
- Không thuộc danh sách mật khẩu mặc định hoặc đã lộ (`golive.credential.*`).
- Không chứa email hoặc username.

### 3.3 Cách B: để lệnh tự sinh mật khẩu

```bash
docker compose exec -T -u www-data backend \
  php artisan wms:bootstrap-super-admin --email=<email-quan-tri> \
  --generate-password-file=/run/secrets/wms-first-admin.txt --force
```

- Mật khẩu sinh bằng CSPRNG và ghi vào một tệp **mới**, quyền `0600`. Lệnh không ghi đè tệp có sẵn.
- Chuyển mật khẩu cho quản trị viên qua kênh bảo mật, rồi xoá tệp.
- Lệnh không bao giờ in mật khẩu ra màn hình hay log.

### 3.4 Kết quả

- Tạo một tài khoản `ACTIVE` giữ SUPER_ADMIN.
  - `must_change_password = true`: lần đăng nhập đầu chỉ được đổi mật khẩu, mọi API khác trả `403 PASSWORD_CHANGE_REQUIRED`.
  - Tự khoá nếu không đăng nhập trong 7 ngày.
- Ghi audit `SUPER_ADMIN_BOOTSTRAPPED`. Mọi lần bị từ chối ghi `SUPER_ADMIN_BOOTSTRAP_REFUSED`. Không bản ghi nào chứa bí mật.
- Chạy lại khi đã có SUPER_ADMIN thì thoát 1 và không ghi gì. Hai lần chạy song song xếp hàng trên khoá hàng của vai trò.
- Mất quyền SUPER_ADMIN duy nhất (quên mật khẩu, bị khoá) **không** giải quyết bằng lệnh này. Hãy dùng quy trình khôi phục qua IAM của một SUPER_ADMIN khác, hoặc break-glass có phê duyệt thay đổi.

### 3.5 MFA

- SUPER_ADMIN nằm trong `MFA_REQUIRED_ROLES` (mặc định `SUPER_ADMIN,ADMIN,IAM_ADMIN`).
- Khi `MFA_ENFORCE_FOR_PRIVILEGED_ROLES=true`, sau khi đổi mật khẩu mọi API nghiệp vụ trả `403 MFA_ENROLLMENT_REQUIRED` cho tới khi đăng ký MFA. Riêng các endpoint dùng để đăng ký (`/api/v1/auth/mfa/*`, `me`, `logout`, `change-password`, `refresh`) vẫn mở. Hành vi này đã có test (`WwhnSecSuperAdminBootstrapTest`).
- **Rủi ro còn lại:** FE hiện chưa tự điều hướng sang màn đăng ký khi nhận `MFA_ENROLLMENT_REQUIRED`. Hãy cho quản trị viên đăng ký MFA qua màn thiết lập MFA **trước** khi bật cưỡng chế.

## 4. Xoay vòng ở môi trường đã triển khai (UAT, production)

Việc này bắt buộc cho mọi môi trường từng chạy seeder cũ. Theo lần đo ngày 10/09/2026, production `khohoanamfe.bigk.click` đang chạy build BE `e483484` (trước bản sửa).

1. **Triển khai bản sửa.** Deploy FE trước, rồi BE `e5dbe0d` trở lên (xem `SEC-20260910-P1-REFRESH-TOKEN-CONTRACT.md` §6).
2. **Liệt kê tài khoản còn dùng mật khẩu đã lộ.** Trên chính môi trường đó chạy `php artisan wms:golive-check` và đọc trường `accounts_on_default_credential` của mục vệ sinh tài khoản. Lệnh dò bằng `Hash::check`, không in mật khẩu.
3. **Xử lý từng tài khoản trong danh sách.** Người thực hiện là một SUPER_ADMIN không nằm trong danh sách. Nếu tất cả đều nằm trong danh sách, trước tiên dùng mục 3 trên một tài khoản mới **sau khi** khoá các tài khoản cũ theo break-glass có phê duyệt.
   - Tài khoản fixture không có chủ thật (`admin@gmail.com`, `super-admin-0X`, `test-*`, `*-01/02/03@gmail.com`…): **khoá** qua IAM. Không xoá cứng, vì dữ liệu audit còn tham chiếu.
   - Tài khoản người thật: đặt lại mật khẩu qua IAM, bật `must_change_password`, và yêu cầu đăng ký MFA nếu là vai trò đặc quyền.
   - Đổi hoặc đặt lại mật khẩu sẽ thu hồi mọi refresh token của tài khoản đó (có test `WwhnSecRefreshTokenTransportTest`). Nhờ vậy phiên dài hạn mà kẻ tấn công có thể đã tạo cũng chết theo.
4. **Kiểm tra lại.** Chạy lại `wms:golive-check`: `accounts_on_default_credential` phải rỗng.
5. **Tìm dấu hiệu đã bị dùng.** Tra `audit_log` các hành động `LOGIN`, `TOKEN_REFRESH` và mọi thao tác IAM của những tài khoản trong danh sách, từ khi mật khẩu xuất hiện trong repo (ít nhất từ commit `f9fd549`, 21/08/2026). Đối chiếu IP và thời điểm với lịch làm việc thật. Có bất thường thì mở quy trình sự cố bảo mật.
6. **Tuỳ chọn, cần phê duyệt thay đổi:** xoay `JWT_SECRET` để vô hiệu mọi access token đang lưu hành (TTL mặc định 60 phút). Việc này buộc mọi người đăng nhập lại. Nó không thay cho bước 3, vì refresh token là token trong DB chứ không phải JWT.

## 5. Biến môi trường liên quan

| Biến | Dùng ở | Ghi chú |
|---|---|---|
| `WMS_FIXTURE_PASSWORD` | Seeder (development/demo) | Không có mặc định. CI sinh ngẫu nhiên mỗi lượt và che trong log. PHPUnit ép giá trị riêng trong `phpunit.xml`. |
| `CI_DEMO_PASSWORD` | Dự phòng của `WMS_FIXTURE_PASSWORD`, script race HTTP | Không có mặc định. |
| `WMS_SEED_DEMO_ACCOUNTS` | Chế độ demo | Chỉ có hiệu lực khi `APP_ENV` ∈ `uat`, `demo`. |
| `WMS_BOOTSTRAP_SUPER_ADMIN_PASSWORD` | `wms:bootstrap-super-admin` | Tên biến đổi được bằng `--password-env`. |
| `WMS_BOOTSTRAP_MIN_PASSWORD_LENGTH` | `wms:bootstrap-super-admin` | Mặc định 16. |
| `MFA_ENFORCE_FOR_PRIVILEGED_ROLES`, `MFA_REQUIRED_ROLES` | Cưỡng chế MFA | Xem §3.5. |

## 6. Rollback

- Quay về code trước `e5dbe0d` sẽ đưa lại seeder tạo SUPER_ADMIN bằng mật khẩu đã lộ. **Không được rollback phần seed/bootstrap.**
- Nếu phải rollback vì lỗi khác, hãy cherry-pick ngược đúng thay đổi gây lỗi. Không revert cả commit bảo mật.

## 7. Bằng chứng kiểm thử

Các test sau là feature test chạy qua kernel HTTP và CSDL thật (không mock):

- `tests/Feature/WwhnSecSuperAdminBootstrapTest.php`: fail-closed, chính sách mật khẩu, audit không lộ bí mật, từ chối chạy lại, không chiếm tài khoản, tệp 0600, bắt đổi mật khẩu rồi MFA.
- `tests/Feature/Wwhn300ProductionSafeSeedingTest.php`: production không tạo, reset hay kích hoạt lại tài khoản nào.
- `tests/Feature/AuthLoginContractSecurityTest.php`: source, OpenAPI và api-docs sinh ra không chứa credential.
- `scripts/check-known-credentials.mjs`: cổng quét mọi tệp git theo dõi.
