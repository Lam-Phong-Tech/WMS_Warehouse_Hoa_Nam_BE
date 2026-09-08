# Gửi đội FE App PV — chọn nhiều sản phẩm khi gửi yêu cầu tư vấn

**BE đã xong.** Endpoint cũ, thêm trường `items[]` để gửi nhiều sản phẩm trong
**một** request. App đang chạy ngoài thị trường **không cần sửa gì**.

`POST {BASE_URL}/api/v1/public/quote-requests` · ẩn danh, không `Authorization`
· header bắt buộc `Idempotency-Key`.

Kiểm tra BE trước mặt đã có chưa: `GET /api/v1/public/config` → `contract_version`
= **`1.1.0`** (bản cũ là `1.0.0`).

---

## 1. Body mới

```json
{
  "items": [
    { "product_id": "01a07b0e-…", "quantity": 2 },
    { "product_id": "01a07b0e-…" },
    { "product_id": "01a07b0e-…", "quantity": 5 }
  ],
  "full_name": "Nguyễn Văn Quang",
  "phone": "+84 912 345 678",
  "province_code": "79",
  "note": "Xin báo giá 3 mã máy khoan",
  "consent": true,
  "privacy_version": "1.0.0"
}
```

Bốn luật cần nhớ:

- `items`: **1..20** dòng. `product_id` lấy từ `GET /products` (trường `id`),
  **không được trùng nhau** — trùng là 422, BE không gộp hộ.
- `quantity`: tuỳ chọn, 1..9999. **Bỏ trống ≠ 1** — bỏ trống nghĩa là khách
  chưa nói số lượng. Không liên quan tồn kho, không giữ hàng.
- `variant_id`: chỉ gửi khi khách chọn biến thể, và phải là id lấy từ
  `variants[]` của chính sản phẩm đó.
- `note` **không** dùng để liệt kê sản phẩm nữa.

```ts
const body = {
  items: selected.map(p => ({ product_id: p.id, ...(p.qty ? { quantity: p.qty } : {}) })),
  full_name, phone, province_code, note,
  consent: true,
  privacy_version: appConfig.privacy.version,   // lấy từ GET /public/config
};

await api.post('/public/quote-requests', body, {
  headers: { 'Idempotency-Key': idempotencyKey },
});
```

---

## 2. Response — **không đổi**

```json
{ "success": true, "message": "Đã tiếp nhận yêu cầu tư vấn.",
  "data": { "request_id": "01a080be-…", "status": "RECEIVED" },
  "meta": { "request_id": "…" }, "error_code": null, "errors": null }
```

`201` = vừa tạo · `200` = đã nhận trước đó với cùng khoá (retry an toàn).

Response **không** trả lại danh sách sản phẩm: BE không bao giờ tự sửa danh
sách (trùng → báo lỗi, hỏng → báo lỗi, giữ nguyên thứ tự), nên nó luôn bằng
đúng cái các bạn vừa gửi.

---

## 3. Lỗi — switch trên `error_code`, đừng switch trên `message`

| HTTP | `error_code` | Ý nghĩa |
|---|---|---|
| 404 | `PRODUCT_NOT_FOUND` | Có sản phẩm không còn khả dụng (hết hàng / gỡ publish) |
| 409 | `IDEMPOTENCY_CONFLICT` | Cùng khoá nhưng nội dung đã khác |
| 422 | `VALIDATION_FAILED` | Sai định dạng / trùng sản phẩm / vượt trần / `quantity` sai |
| 422 | `CONSENT_REQUIRED` | Chưa tích đồng ý chính sách |
| 422 | `PRIVACY_VERSION_MISMATCH` | Bản chính sách đã cũ — tải lại `/config` |
| 428 | `IDEMPOTENCY_KEY_REQUIRED` | Thiếu header |
| 429 | `RATE_LIMIT_EXCEEDED` | 5 request/phút cho cùng (IP + SĐT); đọc `Retry-After` |
| 503 | `FEATURE_DISABLED` / `SERVICE_UNAVAILABLE` | Tắt tính năng / bảo trì |

**`errors` trỏ đúng dòng hỏng**, theo chỉ số mảng các bạn gửi (đếm từ 0):

```json
{ "error_code": "VALIDATION_FAILED",
  "errors": {
    "items.1.product_id": ["Sản phẩm này đã có ở dòng 1."],
    "items.2.quantity":   ["Số lượng phải từ 1 đến 9999."]
} }
```

```ts
// bỏ đúng dòng hỏng, không xoá sạch lựa chọn của khách
Object.keys(res.errors ?? {}).forEach(key => {
  const [scope, index, field] = key.split('.');   // "items" | "1" | "product_id"
  if (scope === 'items') markLineInvalid(Number(index), field, res.errors[key][0]);
});
```

Lưu ý 429 đếm **mọi** request kể cả các lần 422 — gõ sai form 5 lần là chạm trần.

---

## 4. Idempotency-Key

- 16–128 ký tự, chỉ `A-Z a-z 0-9 - _ . :`. Ví dụ `quote-<uuid v4>`.
- **Sinh một khoá cho một lần khách bấm "Gửi"**; giữ nguyên khoá khi retry vì mạng.
- Đổi lựa chọn (thêm/bớt sản phẩm, đổi số lượng, **đổi thứ tự**) → **sinh khoá
  mới**. Dùng lại khoá cũ với nội dung khác sẽ nhận `409`.
- Khoá **không hết hạn** (đừng thiết kế dựa trên giả định 24h).

---

## 5. Ba cách KHÔNG được dùng nữa

| Cách cũ | Vì sao cấm |
|---|---|
| Gửi N request, mỗi sản phẩm một cái | Một khách hoá thành N lead rời rạc, người trực máy gọi lại N lần |
| Chỉ gửi sản phẩm đầu tiên | N-1 sản phẩm biến mất **không dấu vết** |
| Nhét danh sách vào `note` | Không truy vấn được; `note` là dữ liệu cá nhân đã mã hoá |

BE giờ lưu mỗi sản phẩm thành một dòng riêng, kèm ảnh chụp mã + tên sản phẩm
tại thời điểm gửi — sáu tháng sau vẫn tra lại được đúng thứ khách đã thấy.

---

## 6. Base URL

| Môi trường | URL |
|---|---|
| DEV | `http://localhost:8080` |
| UAT | **chưa công bố** — xin DevOps, đừng suy ra từ tên miền FE |
| PRODUCTION | **chưa có** |

---

## 7. Đọc thêm

- Đặc tả đầy đủ: `docs/api-contract/HN-BE-API-007_PUBLIC_QUOTE_REQUEST.md`
- OpenAPI: `docs/api-contract/HN-BE-API-007-public-quote-request-openapi.yaml`
- Bộ mẫu request/response bắt thật từ DEV (đủ 11 ca):
  `docs/api-contract/HN-BE-API-007-public-quote-request-samples.json`

Cần thêm trường hay đổi luật thì báo BE — **đừng tự đổi body ở FE**, vì mọi
ràng buộc trong tài liệu này đều được khoá bằng test ở phía BE.
