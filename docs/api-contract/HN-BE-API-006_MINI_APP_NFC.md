# HN-BE-API-006 — Mini App: gán & quét chip NFC

Đặc tả cho đội FE Mini App. Base URL: `{{BASE_URL}}/api/v1/mini-app`.
Mọi request cần `Authorization: Bearer <JWT>` như các API Mini App hiện có.
Envelope chuẩn: `{ success, message, data, meta }`; lỗi:
`{ success:false, message, error_code, error:{ code, details }, meta }`.

> **Phiên bản 2** — đã chốt theo đề xuất "API tối thiểu" của đội Mini App:
> đổi sang mô hình `prepare` → `PATCH` xác nhận, thêm `GET /items` mang
> `nfc_status`/`nfc_payload`. Endpoint `POST /nfc-tags` ở bản 1 **đã bỏ**.

---

## 1. Ý tưởng nền — đọc trước khi code

**Chip NFC không mang mã riêng. Chip mang lại đúng chuỗi mà QR của sản phẩm
đang mang.**

```
HN1|SKU=<sku_code>|ITEM=<item_unique>
```

Hệ quả cho FE:

- Sau khi gán xong, quét NFC ra chuỗi gì thì **gửi thẳng chuỗi đó vào đúng
  endpoint đang dùng cho QR** — kể cả `POST /api/v1/scan/classify`. Không có
  endpoint "scan bằng NFC" riêng, không cần đổi luồng nghiệp vụ.
- `scan_source` khi gửi scan từ chip dùng **`HARDWARE`** (enum hợp lệ ở BE chỉ
  có `CAMERA` / `MANUAL` / `HARDWARE`).
- Mỗi chip còn có alias phẳng `NFC-<hardware_uid>`; gửi alias làm `raw_code`
  cũng resolve về đúng hiện vật — dùng khi máy chỉ đọc được UID mà không đọc
  được NDEF. Alias luôn dùng được ở `/nfc/resolve-tag`; ở scan nhập/xuất thì phụ
  thuộc cờ `WMS_PRODUCT_QR_LEGACY_ENABLED` (đang bật ở DEV). **Đường chính vẫn
  là ghi và đọc NDEF.**

Toàn bộ nhóm API dưới đây **stock-neutral**: không tạo movement, không đổi tồn
kho, không phải điều kiện của Post Receipt / Post Issue. Mọi response đều trả
`stock_effect: "NONE"`.

---

## 2. Luồng gán NFC (màn hình "Gán chip")

```
[1] Quét QR trên sản phẩm                POST /nfc/resolve-code   → item_id
        ▼
[2] Giữ chỗ                              POST /items/{item_id}/nfc/prepare
        │                                → nfc_payload + reservation_token + expires_at
        ▼
[3] Ghi nfc_payload vào chip (NDEF), dán chip, ĐỌC LẠI xác nhận   ← trên máy
        ▼
[4] Xác nhận                             PATCH /items/{item_id}/nfc
                                         + Idempotency-Key
```

Quy tắc UI:

- Chỉ bật nút "Ghi chip" khi bước 2 trả `assignable === true`.
- **Chỉ gọi bước 4 sau khi đã đọc lại chip thành công.** Ghi lỗi thì không gọi.
- Gửi kèm `written_payload` = nội dung **đọc lại** từ chip. BE đối chiếu; lệch
  thì chặn ngay bằng `NFC_PAYLOAD_ITEM_MISMATCH` — đây là chốt chặn dán nhầm chip.
- `reservation_token` hết hạn (mặc định **300s**) thì gọi lại `prepare`.

`assign_block_code` cho biết vì sao bị chặn:

| assign_block_code | Nghĩa | Gợi ý UI |
|---|---|---|
| `ASSIGNABLE` | Gán được | Bật nút ghi chip |
| `ITEM_NOT_FOUND` | QR không khớp hiện vật nào | "Không tìm thấy sản phẩm" |
| `ITEM_ALREADY_HAS_NFC` | Đã có chip đang hoạt động | Hỏi "Thay chip mới?" → `replace_existing` |
| `ITEM_IDENTITY_NOT_RESOLVABLE` | Hiện vật chưa có serial/mã ổn định | Báo cần xử lý ở WMS trước |
| `ITEM_STATUS_NOT_TAGGABLE` | Hiện vật đã `LOST` / `ADJUSTED_OUT` | Chặn thao tác |

### Vì sao có bước "giữ chỗ"

Ghi NDEF là thao tác **vật lý không hoàn tác được**. Không có giữ chỗ, hai máy
cùng chuẩn bị một hiện vật sẽ có một bên ghi và **dán chip xong mới biết mình
thua** ở bước xác nhận — chip đó thành rác vật lý. `prepare` chặn ngay từ đầu
bằng `409 NFC_WRITE_RESERVED_BY_OTHER`.

Giữ chỗ là **hàng rào trải nghiệm**, không phải hàng rào đúng đắn: nó nằm ở
cache và có thể mất khi flush. Đúng đắn cuối cùng vẫn do `unique(hardware_uid)` +
khoá dòng trong transaction của bước xác nhận bảo đảm. Vì vậy nếu giữ chỗ đã
biến mất thì `PATCH` vẫn chạy bình thường; chỉ khi thấy rõ chỗ đang thuộc về
người khác mới trả 409.

---

## 3. Endpoints

### 3.1 `GET /items` — worklist hiện vật kèm trạng thái chip

Permission: `physical_code.assign_rfid` **hoặc** `item_instance.view` **hoặc**
`inventory.view`.

Mỗi dòng là **một hiện vật** (`item_instance`), không phải một dòng số dư.

> Ghi chú thiết kế: `nfc_status` / `nfc_payload` **không** gắn được vào
> `GET /inventory/balances` như đề xuất ban đầu — endpoint đó là số dư tổng hợp
> theo SKU × kho × stock_status, một dòng balances tương ứng N hiện vật. Trạng
> thái chip là thuộc tính của từng hiện vật nên phải nằm ở bề mặt per-item này.

Query: `keyword` (serial / sku_code / sku_name) · `sku_id` · `item_status`
(mặc định `IN_STOCK`, gửi `ALL` để bỏ lọc) · `container_id` ·
`nfc_status` (`UNASSIGNED` | `ASSIGNED` | `ALL`) · `page` · `per_page` ≤ 100.

```json
{
  "success": true,
  "data": [
    {
      "item_id": "c7042231-726e-4ce1-b529-c57bbdfc4231",
      "serial_number": "SN-HN-SEED-EXT-03-05",
      "item_status": "IN_STOCK",
      "item_condition": "NORMAL",
      "container_id": null,
      "sku": { "sku_id": "01a061a9-…", "sku_code": "HN-PRD-SEED-004", "sku_name": "Máy xới đất PV mẫu 004", "unit": "cái" },
      "nfc_status": "UNASSIGNED",
      "nfc": null,
      "nfc_payload": "HN1|SKU=HN-PRD-SEED-004|ITEM=SN-HN-SEED-EXT-03-05"
    }
  ],
  "meta": { "current_page": 1, "per_page": 20, "total": 388, "last_page": 20 }
}
```

`nfc_status` chỉ tính chip **ACTIVE**: chip đã `LOST`/`DAMAGED`/`INACTIVE` nghĩa
là hiện vật đang trần nên quay lại `UNASSIGNED`.

---

### 3.2 `POST /nfc/resolve-code` — quét QR lấy `item_id`

Permission: `physical_code.assign_rfid`. Read-only, **không giữ chỗ**.

Đây là cầu nối giữa "cầm cái máy trên tay" và `item_id` mà `prepare` cần.

```json
{ "raw_code": "HN1|SKU=DCPL02-8|ITEM=DCPL02-8-000001" }
```

`raw_code` nhận: composite QR, serial thuần, hoặc alias `NFC-<uid>`. Tối đa 512
ký tự.

```json
{
  "success": true,
  "data": {
    "raw_code": "HN1|SKU=DCPL02-8|ITEM=DCPL02-8-000001",
    "resolved": true,
    "assignable": true,
    "assign_block_code": "ASSIGNABLE",
    "item": { "item_id": "c7042231-…", "serial_number": "DCPL02-8-000001", "item_status": "IN_STOCK", "item_condition": "NORMAL", "container_id": null },
    "sku":  { "sku_id": "01a061a9-…", "sku_code": "DCPL02-8", "sku_name": "Máy khoan pin DCPL02-8", "unit": "cái", "status": "ACTIVE", "warranty_applicable": true },
    "nfc_status": "UNASSIGNED",
    "nfc": null,
    "nfc_payload": "HN1|SKU=DCPL02-8|ITEM=DCPL02-8-000001",
    "stock_effect": "NONE",
    "movement_created": false,
    "physical_code_created": false
  }
}
```

Lỗi: `403` thiếu quyền · `422` `SKU_NOT_FOUND` / `SKU_INACTIVE` / `WRONG_SKU`.

---

### 3.3 `POST /items/{itemId}/nfc/prepare` — giữ chỗ ghi chip

Permission: `physical_code.assign_rfid`. Body rỗng (`{}`).

Kiểm tra quyền, trạng thái hiện vật, SKU và chip chưa được gán, rồi giữ chỗ.
Gọi lại bởi **cùng người dùng** thì gia hạn chỗ đang giữ (mất sóng, mở lại màn).

```json
{
  "data": {
    "item": { "item_id": "c7042231-…", "serial_number": "SN-HN-SEED-EXT-03-05", "…": "…" },
    "sku":  { "sku_code": "HN-PRD-SEED-004", "…": "…" },
    "assignable": true,
    "assign_block_code": "ASSIGNABLE",
    "nfc_status": "UNASSIGNED",
    "nfc_payload": "HN1|SKU=HN-PRD-SEED-004|ITEM=SN-HN-SEED-EXT-03-05",
    "reservation_token": "18563c1b-ffd9-4295-aabb-c0cad6389b62",
    "expires_at": "2026-09-04T15:30:04+07:00",
    "ttl_seconds": 300,
    "stock_effect": "NONE",
    "movement_created": false,
    "physical_code_created": false
  }
}
```

| HTTP | `error.code` | Nghĩa |
|---|---|---|
| 404 | `RESOURCE_NOT_FOUND` | Không tìm thấy hiện vật |
| 409 | `ITEM_ALREADY_HAS_NFC` | Đã có chip ACTIVE — thu hồi trước, hoặc xác nhận kèm `replace_existing` |
| 409 | `NFC_WRITE_RESERVED_BY_OTHER` | Máy khác đang giữ chỗ; `details.expires_at` cho biết khi nào hết |
| 422 | `ITEM_IDENTITY_NOT_RESOLVABLE` | Chưa có serial/mã ổn định để dựng payload |
| 422 | `ITEM_STATUS_NOT_TAGGABLE` | Hiện vật `LOST` / `ADJUSTED_OUT` |

---

### 3.4 `PATCH /items/{itemId}/nfc` — xác nhận sau khi ghi & đọc lại

Permission: `physical_code.assign_rfid`. **Bắt buộc** header
`Idempotency-Key` (8–100 ký tự); thiếu → `428 PRECONDITION_REQUIRED`.

```json
{
  "hardware_uid": "04A1B2C3D4E5F6",
  "written_payload": "HN1|SKU=DCPL02-8|ITEM=DCPL02-8-000001",
  "reservation_token": "18563c1b-…",
  "code_value": null,
  "replace_existing": false,
  "reason": null
}
```

| Field | Bắt buộc | Ghi chú |
|---|---|---|
| `hardware_uid` | ✅ | UID phần cứng đọc từ chip, ≤150 ký tự, BE tự uppercase, **duy nhất toàn hệ thống** |
| `written_payload` | — | Nội dung **đọc lại** từ chip; BE đối chiếu phải trỏ đúng hiện vật. Nên luôn gửi |
| `reservation_token` | — | Token từ `prepare`; giúp xác nhận đúng phiên khi đổi thiết bị |
| `code_value` | — | Bỏ trống → BE dùng `NFC-<hardware_uid>` |
| `replace_existing` | — | `true` khi thay chip cũ |
| `reason` | khi `replace_existing=true` | Lý do thay chip |

**Response `201`** (gán mới) / **`200`** (chip đã gán trước đó cho đúng hiện vật này)

```json
{
  "data": {
    "item": { "item_id": "c7042231-…", "serial_number": "DCPL02-8-000001", "…": "…" },
    "sku":  { "sku_code": "DCPL02-8", "…": "…" },
    "nfc_status": "ASSIGNED",
    "nfc": {
      "physical_code_id": "0404d16c-3d58-43e4-b8b3-6705fc55598f",
      "code_value": "NFC-04A1B2C3D4E5F6",
      "carrier_type": "NFC",
      "hardware_uid": "04A1B2C3D4E5F6",
      "status": "ACTIVE",
      "is_primary": true,
      "assigned_at": "2026-09-04T10:12:33+07:00",
      "replaced_by_code_id": null,
      "version": 1
    },
    "nfc_payload": "HN1|SKU=DCPL02-8|ITEM=DCPL02-8-000001",
    "replaced_physical_code_id": null,
    "stock_effect": "NONE",
    "movement_created": false,
    "nfc_required_for_post": false
  }
}
```

Hai lớp idempotency chồng nhau **có chủ ý**: `Idempotency-Key` chặn double-submit
của cùng một lần bấm, còn khoá tự nhiên `hardware_uid` chặn cả trường hợp client
sinh key mới rồi chạm lại đúng chip cũ (trả `200`, không tạo bản ghi thứ hai).

| HTTP | `error.code` | Nghĩa |
|---|---|---|
| 428 | `PRECONDITION_REQUIRED` | Thiếu `Idempotency-Key` |
| 409 | `NFC_TAG_ALREADY_ASSIGNED` | Chip đã gắn hiện vật **khác** |
| 409 | `NFC_TAG_INACTIVE` | Chip đã thu hồi (mất/hỏng), không tái dùng |
| 409 | `ITEM_ALREADY_HAS_NFC` | Gửi lại kèm `replace_existing` + `reason` |
| 409 | `DUPLICATE_CODE_VALUE` | `code_value` tự đặt đã tồn tại |
| 409 | `NFC_WRITE_RESERVED_BY_OTHER` | Máy khác đang giữ chỗ hiện vật này |
| 422 | `NFC_PAYLOAD_ITEM_MISMATCH` | `written_payload` trỏ sang hiện vật khác — dán nhầm chip |
| 422 | `ITEM_IDENTITY_NOT_RESOLVABLE` / `REPLACE_REASON_REQUIRED` | |

Khi thay chip: chip cũ chuyển `INACTIVE`, **không bị xoá**, và có
`replaced_by_code_id` trỏ sang chip mới.

---

### 3.5 `POST /nfc/resolve-tag` — quét chip tra cứu sản phẩm đã xuất kho

Permission: `physical_code.assign_rfid` | `inventory.trace` | `warranty.view`.

Gửi ít nhất một trong hai:

```json
{ "hardware_uid": "04A1B2C3D4E5F6", "raw_code": "HN1|SKU=DCPL02-8|ITEM=DCPL02-8-000001" }
```

```json
{
  "data": {
    "resolved": true,
    "resolve_code": "RESOLVED",
    "item": { "item_id": "c7042231-…", "item_status": "ISSUED", "…": "…" },
    "sku":  { "sku_code": "HN-PRD-SEED-004", "…": "…" },
    "nfc":  { "physical_code_id": "0404d16c-…", "status": "ACTIVE", "…": "…" },
    "nfc_payload": "HN1|SKU=HN-PRD-SEED-004|ITEM=SN-HN-SEED-EXT-03-05",
    "last_issue": {
      "outbound_document_id": "10211459-1100-4920-b758-8246a551721c",
      "doc_no": "PX-SEED-0008",
      "request_no": "TOA-SEED-0008",
      "doc_date": "2026-09-02",
      "posted_at": "2026-09-02T17:28:37+07:00",
      "recipient_name": "Đại lý Hoa Nam Cần Thơ",
      "recipient_address": "Số 20, Ninh Kiều, Cần Thơ",
      "recipient_contact_phone": "0393177289",
      "pii_masked": false
    },
    "warranty": { "warranty_case_code": "BH-000045", "status": "CHECKING", "…": "…" },
    "stock_effect": "NONE"
  }
}
```

`resolve_code`: `RESOLVED` · `NFC_TAG_NOT_REGISTERED` · `NFC_TAG_NOT_ASSIGNED`.
`last_issue = null` khi chưa từng Post Issue. `warranty = null` khi không có hồ
sơ đang mở.

> **PII bên nhận**: chỉ trả nguyên văn cho tài khoản có quyền `outbound.view`.
> Không có quyền thì tên/địa chỉ/điện thoại bị mask và `pii_masked = true`.
> **FE render thẳng giá trị BE trả, không tự suy luận theo quyền phía mình.**

---

### 3.6 `GET /nfc-tags` — danh sách chip

Permission: `physical_code.assign_rfid` | `inventory.trace`.
Xoay quanh **chip** (khác `/items` xoay quanh hiện vật): gồm cả chip đã thu hồi.
Query: `item_id`, `hardware_uid`, `status` (`ACTIVE|INACTIVE|LOST|DAMAGED`),
`page`, `per_page` ≤ 100.

### 3.7 `POST /nfc-tags/{physicalCodeId}/deactivate` — thu hồi chip

Permission: `physical_code.assign_rfid`.

```json
{ "status": "LOST", "reason": "Chip bong khỏi vỏ máy khi vận chuyển" }
```

`status` ∈ `INACTIVE | LOST | DAMAGED`, `reason` bắt buộc. Sau thao tác này hiện
vật quay lại `nfc_status=UNASSIGNED` và gán được chip mới; bản ghi cũ không bị xoá.
Lỗi: `404 RESOURCE_NOT_FOUND` · `409 NFC_TAG_INACTIVE`.

**Shape `nfc`** dùng chung ở mọi response:

```ts
type NfcTag = {
  physical_code_id: string;   // uuid
  code_value: string;         // "NFC-<hardware_uid>"
  carrier_type: 'NFC';
  hardware_uid: string | null;
  status: 'ACTIVE' | 'INACTIVE' | 'LOST' | 'DAMAGED';
  is_primary: boolean;
  assigned_at: string | null; // ISO-8601
  replaced_by_code_id: string | null;
  version: number;
};
```

---

## 4. Quét NFC ở các luồng có sẵn (không cần API mới)

| Việc cần làm | Gọi endpoint có sẵn | Body |
|---|---|---|
| Phân loại / tra cứu mã bất kỳ | `POST /api/v1/scan/classify` | `{ raw_code: <chuỗi đọc từ chip>, context, warehouse_id }` |
| Kiểm tra mã trước khi vào batch xuất | `POST /outbound/resolve-code` | `{ warehouse_id, raw_code }` |
| Xác nhận batch xuất | `POST /outbound/record` | `items[].raw_code`, `items[].scan_source = "HARDWARE"` |
| Quét vào phiếu xuất đã có ¹ | `POST /outbound-documents/{id}/scan` | `{ code_value, quantity: 1, scan_source: "HARDWARE" }` |
| Tiếp nhận bảo hành | `POST /warranty/resolve-code` | `{ raw_code }` |

¹ Giữ nguyên hợp đồng sẵn có: `If-Match: "<version phiếu>"` vẫn bắt buộc ở scan,
`Idempotency-Key` vẫn bắt buộc ở `outbound/record`. Gán NFC không thêm ràng buộc
nào lên các luồng này.

Lưu ý `/scan/classify` là route **canonical** (`/api/v1/scan/classify`, không có
tiền tố `mini-app`) — cùng JWT dùng được, cần `context` +`warehouse_id`.

---

## 5. Những gì API này **không** trả

| Trường | Tình trạng |
|---|---|
| `age` | WMS không lưu tuổi người nhận ở bất kỳ bảng nào |
| `payment_status` | Ngoài phạm vi WMS — phải lấy từ hệ thống bán hàng / đơn hàng |
| `recipient_name` · `recipient_address` · `recipient_contact_phone` | Đúng ba trường người nhận phiếu xuất đang lưu — có trong `last_issue` |

---

## 6. Tham chiếu

- Swagger: tag **`22. Mini App`**, `operationId` `mini_app_nfc_*` /
  `mini_app_item_list` (`/api/documentation`).
- Format QR composite: `docs/decision-records/DEC-QR-001.md`.
- TTL giữ chỗ: `config/warehouse.php` → `nfc.write_reservation_ttl_seconds`
  (env `WMS_NFC_WRITE_RESERVATION_TTL`).
- Handler: `Modules/Warehouse/app/Http/Controllers/MiniAppNfcController.php`.
- Test hợp đồng: `tests/Feature/MiniAppNfcAdapterTest.php`.
