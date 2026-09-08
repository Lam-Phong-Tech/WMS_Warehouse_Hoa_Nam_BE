# HN-BE-API-007 — App PV: gửi yêu cầu tư vấn nhiều sản phẩm

Đặc tả cho đội FE App PV.

| Nguồn | Đường dẫn |
|---|---|
| OpenAPI thu hẹp (đọc tay được) | `docs/api-contract/HN-BE-API-007-public-quote-request-openapi.yaml` |
| Bộ mẫu request/response (bắt thật từ DEV) | `docs/api-contract/HN-BE-API-007-public-quote-request-samples.json` |
| OpenAPI sinh máy (toàn hệ thống) | `storage/api-docs/api-docs.json` — operationId `createPublicQuoteRequest` |
| Code thi hành | `Modules/Warehouse/app/Http/Controllers/PublicApi/PublicQuoteRequestController.php` |
| Test khoá hợp đồng | `tests/Feature/PublicStorefrontApiTest.php` |

Nhóm này **ẩn danh hoàn toàn** — không gửi `Authorization`.

Envelope của mặt công khai luôn đủ **sáu khoá**, kể cả khi giá trị là `null`:

```json
{ "success": true, "message": "...", "data": {...}, "meta": {...}, "error_code": null, "errors": null }
```

---

## 0. Phiên bản & base URL theo môi trường

**Version đường dẫn: `/api/v1/` — KHÔNG đổi.** `items[]` là thay đổi cộng thêm,
body cũ vẫn chạy y nguyên, nên không sinh `/api/v2/`.

**Version hợp đồng:** đọc ở `GET /api/v1/public/config` → `contract_version`
(cũng có ở `GET /api/v1/public/health/version`).

| `contract_version` | Ý nghĩa |
|---|---|
| `1.0.0` | Chỉ nhận một sản phẩm (`product_id` ở gốc body). |
| `1.1.0` | Nhận `items[]` nhiều sản phẩm. Body cũ vẫn chạy. |

FE **không cần** đọc version để hoạt động (gửi `items[]` cho BE 1.0.0 sẽ ra
`422` vì thiếu `product_id`, không phải lỗi âm thầm), nhưng dùng nó để bật cờ
tính năng nhiều-sản-phẩm là cách sạch nhất trong lúc BE các môi trường chưa
đồng bộ. Đổi giá trị bằng `PUBLIC_API_CONTRACT_VERSION`, không sửa code.

| Môi trường | Base URL | Trạng thái |
|---|---|---|
| DEV | `http://localhost:8080` (+ `/api/v1/public/...`) | Chốt. Khớp `APP_URL` trong `.env` của BE và `VITE_API_BASE_URL` của FE. |
| UAT | **chưa công bố trong repo** | Host nằm trong secret `UAT_SSH_HOST` của workflow `hn-devops-ci-cd.yml`. Phải xin DevOps; **đừng suy ra từ tên miền của FE**. |
| PRODUCTION | **chưa tồn tại** (08/09/2026) | Chưa có pipeline production; job `deploy-uat` chạy tay bằng `workflow_dispatch` và hiện đang bị chặn bởi CI đỏ. |

> Nếu ai đó đưa cho FE một URL UAT/PROD không lấy từ DevOps thì đó là phỏng
> đoán, không phải hợp đồng. Chỗ này cố ý để trống cho tới khi có giá trị thật.

---

## 1. Vấn đề mà bản hợp đồng này giải quyết

Màn hình cho khách **chọn nhiều sản phẩm** rồi bấm gửi một lần. Hợp đồng cũ chỉ
có `product_id` đơn lẻ, nên FE chỉ còn ba đường vòng — và cả ba đều **sai nghiệp
vụ**, nên không đường nào được dùng:

| Cách lách | Hậu quả thật |
|---|---|
| Gửi N request, mỗi sản phẩm một cái | Một khách hoá thành N lead rời rạc. Người trực máy gọi lại N lần; không có gì nối chúng lại với nhau. |
| Chỉ gửi sản phẩm đầu tiên | N-1 sản phẩm khách quan tâm biến mất **không dấu vết** — không log, không cột, không cách nào biết là đã mất. |
| Nhét danh sách vào `note` | Dữ liệu nghiệp vụ chui vào ô văn bản tự do, không truy vấn được; `note` còn là cột PII đã mã hoá (WWHN-SEC-06) nên cũng không đọc được bằng SQL. |

Cách đúng, và là cách BE đang thi hành: **một yêu cầu = một dòng cha + N dòng
sản phẩm**.

Việc này **không** biến endpoint thành giỏ hàng: không giá, không số tồn, không
giữ chỗ, không trạng thái đơn, không đụng ledger kho.

---

## 2. `POST /api/v1/public/quote-requests`

### 2.1 Header

| Header | Bắt buộc | Ghi chú |
|---|---|---|
| `Content-Type: application/json` | ✔ | |
| `Idempotency-Key` | ✔ | 16–128 ký tự, chỉ `A–Z a–z 0–9 - _ . :`. Client tự sinh. Xem §4. |

### 2.2 Body — hình chính (nhiều sản phẩm)

```json
{
  "items": [
    { "product_id": "01a07b0e-9fee-7196-a008-ad03bce9aa38", "quantity": 2 },
    { "product_id": "01a07b0e-9ff3-70d0-a9aa-0b589dec6e89" },
    { "product_id": "01a07b0e-a00b-7279-a481-e64f6918f89a", "quantity": 5 }
  ],
  "full_name": "Nguyễn Văn Quang",
  "phone": "+84 912 345 678",
  "province_code": "79",
  "note": "Xin báo giá 3 mã máy khoan",
  "consent": true,
  "privacy_version": "1.0.0"
}
```

| Trường | Kiểu | Bắt buộc | Luật |
|---|---|---|---|
| `items` | array | ✔ (trừ khi dùng hình cũ) | **1..20** phần tử (`PUBLIC_API_QUOTE_MAX_ITEMS`). Mảng danh sách, không phải object. |
| `items[].product_id` | uuid | ✔ | Lấy từ `GET /products` hoặc `GET /products/{slug}` (trường `id`). **Không được trùng nhau** trong cùng một `items`. |
| `items[].variant_id` | uuid | – | Bỏ trống nếu khách không chọn biến thể. Nếu gửi thì phải là chính `product_id`, hoặc một id nằm trong `variants[]` của sản phẩm đó. |
| `items[].quantity` | integer | – | **1..9999** (`PUBLIC_API_QUOTE_MAX_QUANTITY`). **Bỏ trống ≠ 1**: `null` nghĩa là khách chưa nói số lượng, BE không điền hộ. Số này chỉ để tư vấn, không trừ tồn, không đặt chỗ. |
| `full_name` | string | ✔ | 2..120 ký tự. |
| `phone` | string | ✔ | Số VN; BE tự chuẩn hoá `+84…`/`84…` về `0…`. |
| `province_code` | string | – | ≤16 ký tự `A-Za-z0-9_-`. |
| `note` | string | – | ≤1000 ký tự. **Không dùng ô này để liệt kê sản phẩm** — đã có `items`. |
| `consent` | bool | ✔ | Phải `true`. |
| `privacy_version` | string | ✔ | Đúng chuỗi lấy từ `GET /config` (`privacy.version`). Hiện là `1.0.0` — **khác** `contract_version`. |

Trường lạ bị **bỏ qua** (không phải lỗi) để FE thêm field mới không làm vỡ app cũ.

### 2.3 Body — hình cũ (một sản phẩm) vẫn được nhận

```json
{ "product_id": "…", "variant_id": null, "full_name": "…", "phone": "…", "consent": true, "privacy_version": "1.0.0" }
```

BE chuẩn hoá thành `items[0]` với `quantity = null`, ghi xuống bảng con y hệt
hình mới. Bản App PV đang chạy ngoài thị trường **không cần đổi gì**.

### 2.4 Response thành công

```json
{ "success": true, "message": "Đã tiếp nhận yêu cầu tư vấn.",
  "data": { "request_id": "01a080be-…", "status": "RECEIVED" },
  "meta": { "request_id": "…" }, "error_code": null, "errors": null }
```

- `201` — vừa tạo.
- `200` — đã nhận trước đó với cùng `Idempotency-Key` (retry an toàn).
- `meta.request_id` là **trace id của lần gọi**, khác `data.request_id` (mã yêu
  cầu tư vấn). Đối soát với người trực máy thì dùng `data.request_id`.

**Có trả lại danh sách sản phẩm đã nhận không? KHÔNG — và đó là chủ ý.** BE
không bao giờ tự sửa danh sách: trùng sản phẩm thì **báo lỗi** chứ không gộp,
sản phẩm không khả dụng thì **báo lỗi** chứ không bỏ dòng, thứ tự giữ nguyên.
Vì vậy bản echo sẽ luôn bằng đúng cái FE vừa gửi, và một trường thừa trong
response chỉ tạo thêm một nguồn sự thật thứ hai để hai bên lệch nhau.

> Nếu sau này thật sự cần (ví dụ màn "xác nhận đã gửi" muốn hiển thị lại tên
> sản phẩm **theo bản BE đã chốt**), việc phải làm là: thêm `items[]` +
> `item_count` vào `data`, sửa pin `test_quote_request_is_created_then_replayed_idempotently`
> (đang khoá `data` đúng hai khoá `request_id`/`status`), và cập nhật cả hai
> file OpenAPI. Đây là thay đổi hợp đồng có chủ đích, không phải việc thêm âm thầm.

### 2.5 Vì sao KHÔNG có `product_ids: string[]`

Đề xuất ban đầu có hai cách gửi danh sách — `product_ids: string[]` và
`items: [{product_id, variant_id?}]` — kèm yêu cầu "chọn một trong hai, không
dùng cả hai". Hợp đồng chốt **`items[]`**, và đây là lý do:

- `product_ids` không mang nổi `variant_id` và `quantity`. Ngày đầu tiên khách
  chọn biến thể hoặc nêu số lượng là phải đẻ ra hình thứ hai — quay lại đúng
  tình trạng "hai cách gửi cho một việc".
- Hai hình danh sách = hai đường validate, hai bộ khoá lỗi (`product_ids.2` và
  `items.2.product_id`), hai nhánh test. Đó là chi phí vĩnh viễn để đổi lấy vài
  ký tự JSON.
- Chi phí phía FE gần bằng không: `productIds.map(id => ({ product_id: id }))`.

Chỉ có `items[]` là hình danh sách chính thức. Hình cũ `product_id` (một sản
phẩm) tồn tại vì **tương thích ngược**, không phải vì có hai cách gửi danh sách.

---

## 3. Lỗi

| HTTP | `error_code` | Khi nào | `errors` |
|---|---|---|---|
| 400 | `IDEMPOTENCY_KEY_INVALID` | Khoá sai độ dài/bảng chữ cái | `null` |
| 404 | `PRODUCT_NOT_FOUND` | Có ít nhất một sản phẩm không còn hiển thị công khai (chưa publish, hết hàng, hoặc không tồn tại — cố ý **không** phân biệt) | `{"items.1.product_id": ["Sản phẩm không còn khả dụng."]}` |
| 409 | `IDEMPOTENCY_CONFLICT` | Cùng khoá nhưng nội dung khác lần trước | `null` |
| 422 | `VALIDATION_FAILED` | Sai định dạng, trùng sản phẩm, vượt trần, `quantity` không hợp lệ, `variant_id` không thuộc sản phẩm | Theo dòng, xem dưới |
| 422 | `CONSENT_REQUIRED` | `consent` không phải `true` | `null` |
| 422 | `PRIVACY_VERSION_MISMATCH` | Khách đang xem bản chính sách cũ | `{"privacy_version": ["<bản hiện hành>"]}` |
| 428 | `IDEMPOTENCY_KEY_REQUIRED` | Thiếu header | `null` |
| 429 | `RATE_LIMIT_EXCEEDED` | Quá 5 lần/phút theo (IP + số điện thoại) | `null` |
| 503 | `FEATURE_DISABLED` / `SERVICE_UNAVAILABLE` | Tắt tính năng / đang bảo trì | `null` |

FE switch-case trên `error_code`, **không** trên `message`.

**Khoá của `errors` trỏ đúng dòng hỏng**, theo đúng chỉ số trong mảng client gửi
lên (đếm từ 0):

```json
{ "errors": {
    "items.1.product_id": ["Sản phẩm này đã có ở dòng 1."],
    "items.2.quantity":   ["Số lượng phải từ 1 đến 9999."]
} }
```

Nhờ vậy FE bỏ đúng dòng hỏng thay vì xoá sạch lựa chọn của khách. Với **hình
cũ** (một sản phẩm), khoá lỗi vẫn là `product_id` / `variant_id` như trước —
app cũ ngoài thị trường không bị đổi màn hình báo lỗi. Lỗi cấp cả mảng (`items`
rỗng, không phải mảng, vượt trần) dùng khoá `items`.

> **Lưu ý khi đọc `storage/api-docs/api-docs.json`:** operation này còn liệt kê
> `401`, `403`, `412`, `500`. Đó là bộ mã nền do `RouteSyncProcessor` chèn cho
> **mọi** operation của hệ thống. Endpoint này ẩn danh và không dùng `If-Match`
> nên **không bao giờ** trả 401/403/412. Bảng trên mới là danh sách thật.

---

## 4. Idempotency — điều FE cần biết chính xác

- Một khoá ↔ một yêu cầu. Retry cùng khoá **cùng nội dung** → `200` + đúng
  `request_id` cũ, **không** tạo lead thứ hai.
- Cùng khoá + **body khác** → `409 IDEMPOTENCY_CONFLICT` (không phải 200, không
  phải 422). "Khác" tính cả: thêm/bớt sản phẩm, đổi `quantity`, **đổi thứ tự
  các dòng**, đổi họ tên/số điện thoại/ghi chú/`province_code`.
- **Khoá sống bao lâu: VĨNH VIỄN.** Nó là cột `UNIQUE` trên
  `public_quote_requests.idempotency_key` và không có job nào dọn. Biến
  `PUBLIC_API_QUOTE_IDEMPOTENCY_TTL_HOURS=24` có trong config nhưng **hiện chưa
  có dòng code nào đọc** — đừng thiết kế FE dựa vào giả định khoá hết hạn sau
  24 giờ. (Muốn TTL có thật thì phải hiện thực rõ ràng: nó sẽ cho phép cùng một
  khoá tạo yêu cầu MỚI sau 24h, tức là đổi hành vi, không phải chỉnh cấu hình.)
- Quy tắc sinh khoá: **một khoá cho một lần khách bấm "Gửi"**. Giữ nguyên khoá
  cho các lần retry do mạng; đổi lựa chọn thì sinh khoá mới. Đề xuất:
  `quote-<uuid v4>` hoặc `quote-<ulid>`.
- Body một-sản-phẩm băm ra đúng chuỗi như hợp đồng trước khi có `items[]`, nên
  một lần retry vắt qua thời điểm deploy BE vẫn replay được thay vì ăn `409`.

---

## 5. Tương thích cũ — FE chọn đường nào

**Cùng một endpoint, cùng `/api/v1`.** Không có endpoint cũ/mới, không có
version song song. Ba trạng thái đều hợp lệ:

| Tình huống | Gửi gì | Kết quả |
|---|---|---|
| App cũ đã phát hành, không sửa được | `product_id` (+ `variant_id`) như cũ | Chạy y nguyên. BE ghi thành `items[0]`. |
| App mới, một sản phẩm | `items: [{ product_id }]` | **Khuyến nghị.** Một đường code duy nhất cho mọi màn hình. |
| App đang chuyển tiếp, cần chạy với cả BE cũ lẫn mới | Gửi **cả hai**: `product_id` và `items[]` | Được chấp nhận, **với điều kiện** `product_id === items[0].product_id`. Lệch nhau → `422` với khoá lỗi `product_id`. |

Trường hợp thứ ba lệch nhau bị chặn thay vì được "tự xử": im lặng chọn một
trong hai chính là kiểu sai nghiệp vụ mà cả hợp đồng này sinh ra để loại bỏ.

---

## 6. BE lưu gì (phần "truy vết")

| Bảng | Nội dung |
|---|---|
| `public_quote_requests` | Một dòng / một lần gửi. Thông tin liên hệ (mã hoá theo WWHN-SEC-06), `consent`, `privacy_version`, `item_count`, và bản sao `product_id`/`variant_id` **của dòng 1** để dữ liệu và truy vấn cũ chạy tiếp. |
| `public_quote_request_items` | Một dòng / một sản phẩm: `line_no` (đúng thứ tự khách chọn), `product_id`, `variant_id`, `quantity`, và **ảnh chụp** `sku_code_snapshot` / `product_name_snapshot` tại thời điểm gửi. |

Ảnh chụp là phần trả lời câu hỏi sáu tháng sau: danh mục đổi tên, gộp mã hay gỡ
publish thì tra ngược `product_id` chỉ ra cái tên **hôm nay**, không phải cái
tên khách đã nhìn thấy.

Truy vấn vận hành đúng là **join sang bảng items**; đọc
`public_quote_requests.product_id` rồi dừng lại là bỏ sót đúng phần mà bảng con
sinh ra để cứu — `item_count` nằm ngay cạnh để lỗi đó lộ ra ngay.

Yêu cầu cha và các dòng sản phẩm được ghi trong **cùng một transaction**: hỏng
một dòng thì không có yêu cầu nửa vời nào được tạo.

Các yêu cầu tạo TRƯỚC bản này đã được backfill mỗi dòng một item; riêng hai cột
ảnh chụp để `NULL` vì tên sản phẩm hôm nay không phải tên khách đã thấy lúc đó.

---

## 7. Cố ý KHÔNG có trong phạm vi này

Không CRM/ERP, không notification, không queue/outbox/retry worker, không giá,
không số tồn, không giỏ hàng, không đơn hàng, không máy trạng thái (`status`
chỉ có `RECEIVED`). Endpoint không dispatch job, không gửi mail, không bắn event
— và bộ test khoá lại đúng điều đó.

Cũng **không** có API đọc lại yêu cầu tư vấn cho phía quản trị: hiện chưa có
màn hình nào cần, và mở một endpoint đọc PII của người ngoài công ty phải đi
kèm phân quyền + nhật ký riêng, không làm kèm ở đây.

---

## 8. Ai được sửa hợp đồng này, và sửa ở đâu

**Chủ sở hữu: đội BE của repo `WMS_Warehouse_Hoa_Nam_BE`** (không có file
`CODEOWNERS`; theo `git log`, toàn bộ mặt `/api/v1/public/*` do
`quangntpk` tạo và bảo trì). FE **không** tự đổi hợp đồng bằng cách đổi body
gửi lên — mọi thay đổi phải qua BE, vì chính chỗ đó là ranh giới mà tài liệu
này bảo vệ.

Một thay đổi hợp đồng chỉ được coi là hoàn tất khi **cả năm** chỗ dưới đây đi
cùng nhau trong một PR:

1. `Modules/Warehouse/app/Http/Controllers/PublicApi/PublicQuoteRequestController.php`
   — code thi hành **và** annotation OpenAPI nằm ngay trên `store()`.
2. `config/public_api.php` — knob nghiệp vụ (`max_items`, `max_quantity`,
   `contract_version`, feature flag).
3. `tests/Feature/PublicStorefrontApiTest.php` — pin hành vi. Không có test thì
   thay đổi đó không tồn tại.
4. `docs/api-contract/HN-BE-API-007_PUBLIC_QUOTE_REQUEST.md` + `…-openapi.yaml`
   — hai file này.
5. `storage/api-docs/api-docs.json|yaml` — sinh lại bằng
   `php artisan l5-swagger:generate`.

Hàng rào tự động đang canh:

- `tests/Feature/SwaggerRouteSyncTest.php` — route thật phải khớp OpenAPI, và
  summary không được rơi về dạng máy sinh.
- `tests/Feature/Wwhn249PvSurfaceGovernanceTest.php` — thêm/bớt route ẩn danh
  phải cập nhật đồng thời `DECLARED_ANONYMOUS_ROUTES`,
  `scripts/generate-endpoint-permission-matrix.mjs` và
  `HN-BE-API-003_ENDPOINT_PERMISSION_MATRIX.md`.
- `config/l5-swagger.php` — thư mục `PublicApi/` phải nằm trong danh sách scan,
  nếu không OpenAPI publish ra skeleton máy sinh và đội FE lại không có hợp
  đồng để wire (đúng gốc rễ của vấn đề mà tài liệu này ra đời để đóng lại).

---

## 9. Cấu hình vận hành

| ENV | Mặc định | Ý nghĩa |
|---|---|---|
| `PUBLIC_API_QUOTE_MAX_ITEMS` | `20` | Trần số sản phẩm mỗi yêu cầu. Ranh giới nghiệp vụ, không phải giới hạn kỹ thuật. |
| `PUBLIC_API_QUOTE_MAX_QUANTITY` | `9999` | Trần số lượng mỗi dòng. |
| `PUBLIC_API_CONTRACT_VERSION` | `1.1.0` | Giá trị trả ở `/config` và `/health/version`. |
| `PUBLIC_API_FEATURE_QUOTE_REQUEST` | `true` | Tắt là `503 FEATURE_DISABLED`. |
| `PUBLIC_API_MAINTENANCE` | `false` | Bật là `503 SERVICE_UNAVAILABLE` cho riêng endpoint ghi này. |
| `PUBLIC_API_QUOTE_IDEMPOTENCY_TTL_HOURS` | `24` | **Chưa được code nào đọc** — xem §4. |
| `RATE_LIMIT_PUBLIC_QUOTE_REQUEST_PER_MINUTE` | `5` | Theo (IP + số điện thoại). |
