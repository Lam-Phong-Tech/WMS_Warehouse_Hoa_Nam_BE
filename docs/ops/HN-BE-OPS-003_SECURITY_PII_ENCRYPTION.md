# HN-BE-OPS-003 — Mã hoá dữ liệu cá nhân, quản lý khoá và xoay khoá

Phạm vi: WWHN-SEC-06. Đối tượng đọc: DEV backend, DevOps, người ký cutover.

Tài liệu này KHÔNG chứa giá trị khoá nào. Mọi ví dụ khoá đều là minh hoạ.

---

## 1. Vấn đề đang giải quyết

Trước WWHN-SEC-06, quét toàn bộ `app/` + `Modules/` cho `Crypt::`, `encryptString`,
cast `encrypted`, `pgcrypto` trả về **0 kết quả**. Nghĩa là: ai lấy được một bản
sao lưu, một replica đọc, hoặc quyền `SELECT` trên PostgreSQL đều đọc được toàn
bộ dữ liệu cá nhân ở dạng rõ. Mọi lớp bảo vệ khác của hệ thống (RBAC, data scope,
mask PII ở tầng báo cáo, rate limit) đều nằm **trên** API và không có tác dụng gì
ở kịch bản này.

Lưu ý quan trọng về mức độ: dự án đã có lớp che PII rất tốt ở tầng API
(`WarrantyPiiMasker`, quyền `warranty.pii.view` / `warranty.pii.export`,
`audit.sensitive_keys` che 20 khoá trước khi ghi audit). Thiếu sót nằm **đúng ở
tầng lưu trữ**, không phải ở tầng truy cập.

---

## 2. Phân loại dữ liệu — mã hoá cái gì, và vì sao KHÔNG mã hoá phần còn lại

Bảng đầy đủ kèm lý do nằm trong `config/pii.php` (khối `classification`). Tóm tắt:

| Dữ liệu | Quyết định | Lý do ngắn |
|---|---|---|
| `public_quote_requests.full_name` / `.phone` / `.note` | **Mã hoá** (đã xong) | Dữ liệu cá nhân của người ngoài công ty, thu thập kèm `consent` + `privacy_version` |
| `warranty_cases.customer_name` / `.customer_phone` / `.customer_address` | **Mã hoá** (đã xong) | PII khách hàng cuối; name + phone có blind index |
| `app_users.email` / `.phone` | Không mã hoá | Là **định danh đăng nhập**; mã hoá thì mọi lần đăng nhập phải qua blind index và màn IAM mất tìm kiếm gần đúng. Dữ liệu nhân viên nội bộ, xử lý bằng kiểm soát truy cập + TDE |
| `stock_documents.recipient_name` / `.recipient_address` | Không mã hoá | Người nhận là **pháp nhân** (đại lý), không phải cá nhân; đây là dữ liệu chứng từ phải in nhãn và đối soát |
| `agencies.contact_phone` / `.address` | Không mã hoá | Liên hệ pháp nhân, thường công khai |
| `audit_logs.ip_address`, `refresh_tokens.ip_address`, `user_agent` | Không mã hoá | Là **bằng chứng điều tra**. Mã hoá làm mất khả năng truy vết hàng loạt đúng lúc cần nhất. Rủi ro xử lý bằng thời hạn lưu trữ |
| `password_hash`, `otp_hash`, `reset_token_hash`, `refresh_tokens.token_hash` | Không mã hoá | Đã là **một chiều**. "Mã hoá mật khẩu" là lỗi thiết kế, không phải nâng cấp |

Nguyên tắc: mã hoá một cột **mất** index, **mất** tìm kiếm gần đúng và **thêm**
chi phí giải mã cho mỗi hàng đọc ra. Mã hoá thứ không cần là làm hệ thống yếu đi
ở chỗ khác để checklist đẹp hơn.

---

## 3. Thiết kế mật mã

| Nhu cầu | Primitive | Nơi cài đặt |
|---|---|---|
| Mật khẩu / OTP / reset token | Băm một chiều (bcrypt cost 12) | đã có sẵn từ trước |
| Refresh token | SHA-256 (entropy cao nên không cần KDF chậm) | `RefreshTokenService` |
| PII hiển thị được | **AEAD** AES-256-GCM, khoá có phiên bản | `App\Support\Security\PiiCrypto` |
| PII cần tra cứu chính xác | **HMAC-SHA256 có khoá** (blind index) | `App\Support\Security\BlindIndex` |

Ba điểm thiết kế đáng chú ý:

1. **Ciphertext mang key id**: định dạng `"<key_id>$<payload>"`. Nhờ vậy xoay khoá
   không cần dừng hệ thống — xem §4.

2. **Ràng buộc theo cột (AAD)**: `PiiCrypto::encrypt($value, 'bang.cot')` gắn tên
   cột vào phần được xác thực. Không có nó, kẻ có quyền GHI vào DB nhưng không có
   khoá vẫn **hoán vị** được dữ liệu giữa các hồ sơ bằng cách chép ciphertext từ ô
   này sang ô khác, và hệ thống giải mã sạch sẽ mà không biết. Có nó thì thao tác
   đó ném lỗi.

3. **Blind index KHÔNG được là băm trần**: số điện thoại Việt Nam chỉ có khoảng
   `10^8` giá trị hợp lệ — một GPU duyệt hết trong vài giây. `sha256(phone)` do đó
   không giấu được gì. Bắt buộc HMAC có khoá, và khoá đó phải **khác** khoá mã hoá
   (một khoá lộ thì không được vừa giải mã vừa dựng bảng tra ngược).

**Chuẩn hoá trước khi HMAC.** `0912 345 678`, `+84912345678`, `84912345678` và
`0912345678` là cùng một số với người dùng nhưng ra bốn vân tay khác nhau nếu băm
thẳng. `BlindIndex::normalizePhone()` đưa tất cả về `0xxxxxxxxx` trước.

**Rò rỉ còn lại, chấp nhận có ý thức**: hai hồ sơ cùng số điện thoại có cùng vân
tay, nên người đọc được DB biết "hai hồ sơ này cùng một khách" dù không biết số
nào. Đổi lại là khả năng tra cứu. Muốn bịt nốt thì phải bỏ tra cứu.

---

## 4. Quản lý và xoay khoá

### 4.1 Vì sao không dùng APP_KEY

`APP_KEY` phục vụ session, cookie ký, signed URL. Hướng dẫn vận hành của Laravel
nói "xoay khi nghi ngờ lộ", và hệ quả của việc xoay chỉ là người dùng đăng nhập
lại. Nếu PII cũng nằm dưới `APP_KEY` thì xoay = **mất vĩnh viễn dữ liệu khách
hàng**, và hệ quả thực tế là không ai dám xoay nữa — khoá quan trọng nhất hệ thống
bị đóng băng.

### 4.2 Biến môi trường

```
PII_ENCRYPTION_KEYS={"v1":"base64:...."}      # keyring, JSON map key_id -> khoá
PII_ACTIVE_ENCRYPTION_KEY=v1                   # khoá dùng để GHI
PII_BLIND_INDEX_KEYS={"v1":"base64:...."}      # keyring RIÊNG cho blind index
PII_ACTIVE_BLIND_INDEX_KEY=v1
```

Sinh khoá: `php artisan pii:make-key --purpose=encryption --id=v1`
(lệnh chỉ IN ra, cố ý không tự ghi `.env` — ghi đè nhầm một khoá đang có dữ liệu
dưới nó là mất dữ liệu).

### 4.3 Máy lập trình viên không phải cấu hình gì

Thiếu keyring ở `local`/`testing` thì khoá được **dẫn xuất tất định từ APP_KEY**
bằng HKDF, salt riêng cho từng mục đích. `docker compose up` chạy được ngay.

Ngoài `local`/`testing`, chạy bằng khoá dẫn xuất là **FAIL** ở
`wms:golive-check` → `security.pii_at_rest` → `pii_encryption_key_derived`.
Lý do: khoá dẫn xuất nghĩa là ai đọc được `APP_KEY` (biến môi trường, dump cấu
hình, log lỗi có config) là giải mã được toàn bộ PII — lớp mã hoá không bảo vệ
thêm được gì.

### 4.4 Quy trình xoay khoá (không downtime)

1. `php artisan pii:make-key --id=v2` → **thêm** v2 vào keyring, **giữ nguyên** v1.
2. Đặt `PII_ACTIVE_ENCRYPTION_KEY=v2`. Từ lúc này bản ghi mới ký bằng v2; bản ghi
   cũ (`v1$...`) vẫn giải mã được vì v1 còn trong keyring.
3. Chạy re-encrypt theo lô (với `public_quote_requests`:
   `php artisan pii:quote-requests --encrypt` chỉ xử lý hàng chưa có ciphertext;
   một lệnh re-encrypt tổng quát cho mọi bảng là việc của WWHN-SEC-06 giai đoạn 2).
4. Chỉ **sau khi** không còn hàng nào mang tiền tố `v1$` mới được gỡ v1 khỏi
   keyring.

Với blind index, trong cửa sổ xoay khoá `BlindIndex::phoneCandidates()` sinh vân
tay theo **mọi** key id còn trong keyring, và truy vấn dùng `whereIn`. Nếu chỉ
dùng khoá active thì hàng chưa re-index sẽ **biến mất khỏi kết quả tìm kiếm** —
một lỗi im lặng, chỉ lộ ra khi khách hàng phàn nàn.

**Không bao giờ** xoay khoá bằng cách thay giá trị của một key id đang có dữ liệu.

---

## 5. Trạng thái triển khai và phần còn lại

### 5.1 Đã hoàn tất — `public_quote_requests`

Chọn bảng này trước vì nó có bề mặt hẹp nhất trong repo: là BASE TABLE thật, chỉ
một model Eloquent chạm tới, chỉ một controller ghi, và `full_name`/`phone`/`note`
**không được trả ra ở bất kỳ response nào**. Vì vậy mã hoá không đổi một byte nào
của hợp đồng API.

Đã chạy trên môi trường dev 08/09/2026:

- migration Phase 1 `2026_09_08_000100` (thêm cột ciphertext + `phone_lookup`)
- migration Phase 6 `2026_09_08_000200` (xoá `full_name`, `phone`,
  `phone_normalized`, `note`) — **self-guarding**: từ chối chạy nếu còn hàng chưa
  mã hoá hoặc nếu ciphertext không giải mã được bằng khoá của chính môi trường đó
- rollback đã được kiểm chứng: `migrate:rollback` dựng lại cột và **giải mã dữ
  liệu về đầy đủ**

### 5.2 Đã hoàn tất — `warranty_case`

Đây là bề mặt khó nhất của lớp mã hoá, vì hai lý do kỹ thuật:

- `warranty_case` **không phải bảng**. Trên PostgreSQL nó là VIEW trên bảng
  `warranty_cases`, kèm trigger `INSTEAD OF INSERT OR UPDATE OR DELETE`
  (`hn_legacy_warranty_case_write`). Trên sqlite (bộ test) bố cục **ngược lại**:
  bảng là `warranty_case`, còn `warranty_cases` là view alias legacy.
  `Schema::hasTable()` trả true cho cả view nên không phân biệt được bằng nó —
  dùng `WarrantyCasePii::baseTable()`.
- Toàn bộ luồng bảo hành đọc/ghi bằng **query builder** (`DB::table(...)`), không
  qua Eloquent. Cast/mutator do đó **không chạy**: thêm cast vào model `WarrantyCase`
  sẽ không mã hoá lúc ghi và trả ciphertext thô cho lớp mask lúc đọc.

Vì vậy quy ước là: mọi truy vấn chạm ba cột PII phải đi qua
`Modules\Warehouse\Support\WarrantyCasePii` — `encode()` khi ghi, `decodeRow()` /
`decodeRows()` khi đọc, `selectColumns()` trong SELECT tường minh,
`applyKeyword()` khi tìm. Một test quét mã nguồn
(`WwhnSec06WarrantyPiiTest::test_khong_con_truy_van_nao_doc_cot_plaintext`) chặn
việc ai đó thêm lại `select('wc.customer_name')` ở lần mở màn hình sau.

Đã chạy trên môi trường dev 08/09/2026:

- migration `2026_09_08_000300` — thêm 5 cột, dựng lại view + trigger, nới NOT NULL
- `php artisan pii:warranty-cases --encrypt` → 207/207 hồ sơ, `--verify` PASS
- migration `2026_09_08_000400` — xoá hẳn `customer_name`, `customer_phone`,
  `customer_address` khỏi `warranty_cases`

Ba cạm bẫy đã sập khi làm, ghi lại để không ai sập lại:

1. `CREATE OR REPLACE VIEW` **không giảm được số cột** ("cannot drop columns from
   view") → nhánh rollback hỏng. Phải `DROP VIEW` + `CREATE VIEW`, và khi đó
   trigger `INSTEAD OF` gắn trên view cũng bị xoá theo nên **phải tạo lại**.
2. Thứ tự trong `down()` quan trọng: view/trigger phải thôi tham chiếu cột **trước**
   khi `DROP COLUMN`, nếu không PostgreSQL từ chối với "cannot drop column …
   because other objects depend on it".
3. `customer_name` và `customer_phone` đang **NOT NULL**. Không nới ràng buộc ở
   Phase 1 thì mọi lần tiếp nhận bảo hành ném 23502 ngay lúc deploy.

#### Tìm kiếm ĐÃ đổi ngữ nghĩa — cần phổ biến cho người dùng WAR-01

| Người dùng gõ | Trước | Sau |
|---|---|---|
| Số điện thoại đầy đủ (mọi cách gõ: `0912345678`, `0912 345 678`, `+84912345678`) | khớp | **khớp**, và chính xác hơn |
| Mảnh số (`345`) | khớp | **không còn khớp** |
| Một từ trong tên, không dấu (`nguyen`) | khớp | **khớp** |
| Nhiều từ (`nguyen huong`) | khớp cụm liền | **khớp khi có ĐỦ các từ** (AND) — hẹp hơn, đúng kỳ vọng |
| Giữa một từ (`guyen`) | khớp | **không còn khớp** |
| Địa chỉ | khớp | **không còn tìm được** |

Hai dòng cuối là mất mát thật và đã được chấp nhận có ý thức: blind index chỉ trả
lời "bằng đúng", và chỉ mục cho phép khớp-giữa-từ sẽ rò rỉ nhiều hơn phần bảo vệ
nó mang lại. Địa chỉ là khoá tra cứu có giá trị nghiệp vụ thấp nhất trong ba cột.

`customer_phone_lookup` có index B-tree riêng thay cho index cũ trên
`customer_phone`, nên tra cứu theo số điện thoại vẫn là index scan.

---

## 6. Hiệu năng

- Keyring được phân giải **một lần cho mỗi tiến trình** (`PiiKeyring::$resolved`),
  `Encrypter` được cache theo key id. Không parse JSON, không chạy HKDF, không gọi
  mạng tới Vault/KMS trên mỗi request.
- Blind index có index B-tree riêng (`idx_public_quote_requests_phone_lookup`), nên
  tra cứu vẫn là index scan, không phải table scan.
- **Cấm tuyệt đối**: giải mã cả bảng trong PHP để tìm kiếm. Nếu một màn hình cần
  tìm gần đúng trên cột đã mã hoá thì câu trả lời là thiết kế lại truy vấn (blind
  index / cột dẫn xuất không nhạy cảm), không phải vòng lặp giải mã.

---

## 7. Lệnh vận hành

```bash
php artisan pii:make-key --purpose=encryption --id=v1
php artisan pii:make-key --purpose=blind_index --id=v1

php artisan pii:quote-requests --encrypt            # Phase 3, idempotent
php artisan pii:quote-requests --verify             # Phase 4, giải mã thử
php artisan pii:quote-requests --purge-plaintext    # Phase 5, fail-closed
php artisan migrate                                  # Phase 6, tự kiểm trước khi xoá cột

php artisan pii:warranty-cases --encrypt            # Phase 3 cho hồ sơ bảo hành
php artisan pii:warranty-cases --verify
php artisan pii:warranty-cases --purge-plaintext

php artisan pii:quote-requests --restore-plaintext  # rollback trước Phase 6
php artisan pii:warranty-cases --restore-plaintext
php artisan migrate:rollback --step=1               # rollback sau Phase 6 (giải mã về)
```

Không lệnh nào in ra giá trị dữ liệu — chỉ số đếm và id. Một lệnh di trú PII mà
`--verbose` in ra tên và số điện thoại là tự tạo bản sao PII trong log CI.

---

## 8. Bằng chứng

- Test: `tests/Feature/WwhnSec06PiiEncryptionTest.php` — chứng minh **hành vi**,
  không assert chuỗi cấu hình: dump DB không còn plaintext, blind index tra cứu
  được 6 cách gõ số, vân tay đổi theo khoá và khác `sha256` trần, ciphertext chép
  chéo cột thì ném lỗi, mất khoá thì không giải mã được, xoay khoá không mất dữ
  liệu cũ.
- Gate: `php artisan wms:golive-check` → `security.pii_at_rest`.
