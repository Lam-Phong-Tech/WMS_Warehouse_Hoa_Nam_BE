# DEC-API-IFMATCH-001 — Gỡ middleware `CurrentBaselineIfMatch` (optimistic locking ở tầng route)

- Ticket: WWHN-309 (đóng action `DEV309-P2-02`)
- Canonical repository: `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE`
- Canonical branch: `m1`
- File điều chỉnh: `app/Http/Middleware/CurrentBaselineIfMatch.php` (gỡ),
  `tests/Feature/CurrentBaselineIfMatchContractTest.php` (đổi vai),
  `docs/architecture/HN-BE-ARCH-001_BACKEND_ARCHITECTURE.md`,
  `docs/api-contract/HN-BE-API-002_ERROR_CODE_CATALOG.md|json`
- Người quyết định: Tech Lead
- Trạng thái: `ACCEPTED`

## Bối cảnh

`App\Http\Middleware\CurrentBaselineIfMatch` cài đặt optimistic locking ở tầng route:
đọc `If-Match`, `lockForUpdate()` bản ghi, so `version`, trả 412 `STALE_VERSION` khi
lệch, tăng `version` và set `ETag` sau khi handler chạy xong.

Middleware **chưa từng được gắn vào route nào**: không có trong `bootstrap/app.php`,
không có alias trong config, không xuất hiện ở bất kỳ file `routes/*.php` nào.
Enforcement thật nằm ở controller qua các helper `assertVersion` /
`assertVersionIfProvided` / `expectedVersion`.

WWHN-309 vòng trước chỉ ghi cảnh báo trong docblock và để hạng mục ở trạng thái
"DOCUMENTED — chờ quyết định gỡ". Review kế tiếp bắt đúng điểm đó: một open item
không có disposition thì vẫn là open item.

## Vì sao không chọn "giữ lại và ghi lý do"

Ba tác hại đo được, không phải suy đoán:

1. **Contract publish sai.** Catalog error code sinh tự động từ source, nên hai mã
   `RESOURCE_ID_REQUIRED` (422) và `VERSION_CONTRACT_MISSING` (500) được publish cho
   FE/QA dù **chỉ tồn tại trong middleware chết** — `occurrences: 1`, `first_seen` trỏ
   thẳng vào file này và không có nguồn nào khác trong toàn bộ `app/` + `Modules/`.
   FE/QA viết case cho hai mã mà không đường chạy nào có thể trả về.
2. **`first_seen` của 4 mã đang dùng thật** (`RESOURCE_NOT_FOUND`, `STALE_VERSION`,
   `INVALID_VERSION`, `PRECONDITION_REQUIRED`) trỏ vào code chết, nên người đọc
   catalog bị dẫn tới sai nơi enforcement.
3. **Rủi ro double-increment.** Nếu ai đó gắn middleware vào route đang có helper
   `assertVersion` ở controller, `version` tăng hai lần cho một request — lỗi này chỉ
   lộ ra khi client gửi `If-Match` lần kế tiếp và nhận 412 vô cớ.

Giá trị "để dành dùng lại" không bù được ba tác hại trên: lớp này 77 dòng, không có
route nào phụ thuộc, và nếu cần lại thì viết lại rẻ hơn nhiều so với chi phí một
contract sai đang được FE/QA tiêu thụ.

## Quyết định

1. **Gỡ** `app/Http/Middleware/CurrentBaselineIfMatch.php`.
2. Optimistic locking tiếp tục được enforce ở **tầng controller**; nguồn sự thật để
   biết endpoint nào có `If-Match` là cột `If-Match` trong
   `docs/api-contract/HN-BE-API-003_ENDPOINT_PERMISSION_MATRIX.md` và header khai
   trong OpenAPI (sinh tự động từ source).
3. `tests/Feature/CurrentBaselineIfMatchContractTest.php` **giữ nguyên tên file** (hai
   workflow `wwhn-218-hardening-evidence.yml` và `wwhn-219-final-gate.yml` gọi đích
   danh) nhưng đổi vai: khoá việc middleware không quay lại, khoá catalog không còn
   mã ma, và giữ nguyên phần kiểm 7 controller đang enforce thật.
4. Regenerate `HN-BE-API-002` sau khi gỡ: catalog giảm đúng 2 mã ma và `first_seen`
   của 4 mã còn lại trỏ về controller thật.

## Điều kiện nếu muốn quay lại tầng route

Phải làm đủ, trong cùng một PR:

- Gắn middleware vào route cụ thể (không đăng ký toàn cục).
- Gỡ helper `assertVersion` ở đúng các controller đó để không tăng `version` hai lần.
- Regenerate `HN-BE-API-003` và OpenAPI; cột `If-Match` phải phản ánh route mới.
- Bổ sung test đồng thời chứng minh `version` chỉ tăng 1 cho mỗi mutation thành công.

## Hệ quả

- Catalog error code giảm đúng 2 mã (hai mã ma nêu trên) và không còn mã nào không thể
  phát sinh. Số tuyệt đối tại từng SHA đọc ở `HN-BE-API-004_CONTRACT_METRICS.json`.
- Không có thay đổi hành vi runtime: không route nào từng đi qua middleware này.
