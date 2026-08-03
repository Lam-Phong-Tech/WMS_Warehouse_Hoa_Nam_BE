# HN-DEC-002 - DEC-INV-001: Nguồn Sự Thật Của Tồn Kho (Inventory Source of Truth)

## Kiểm Soát Tài Liệu

| Trường | Giá trị |
|-------|-------|
| **Mã tài liệu** | HN-DEC-002 |
| **Mã quyết định** | DEC-INV-001 |
| **Tiêu đề** | Nguồn sự thật của tồn kho - Kiến trúc sổ cái có thẩm quyền |
| **Phiên bản** | 1.1 |
| **Trạng thái** | ĐÃ PHÊ DUYỆT/ĐÃ CHUẨN HÓA (APPROVED/BASELINED) |
| **Ngày hiệu lực** | 2026-07-23 (hiệu lực khi WWHN-333 = DONE) |
| **Chủ sở hữu** | Nguyễn Quang Đức (Tech Lead/PM) |
| **Khả năng kiểm thử QA** | Đặng Thị Soi (ở cổng kiểm soát downstream) |
| **Epic cha** | HN-EPIC-01 |
| **Nhiệm vụ liên quan** | WWHN-31, WWHN-33, WWHN-40, WWHN-128, WWHN-129, WWHN-130 |
| **Ngày tạo** | 2026-07-23 |
| **Cập nhật lần cuối** | 2026-07-31 |
| **Hash phê duyệt** | SHA256:a1b2c3d4e5f6... (hash commit tại thời điểm phê duyệt) |
| **Nhận xét phê duyệt** | "APPROVED/BASELINED - All P0/P1 fixes incorporated. Effective 2026-07-23." - Nguyễn Quang Đức, 2026-07-31 |

---

## Tóm Tắt Điều Hành

DEC-INV-001 thiết lập **kiến trúc dữ liệu có thẩm quyền (authoritative)** cho việc quản lý tồn kho trong hệ thống WMS Hoa Nam. Quyết định này định nghĩa **mô hình tồn kho ba tầng** trong đó:

1. **Inventory_Movement (Chuyển động tồn kho)** là **sổ cái ghi nối tiếp không thể thay đổi (ledger append-only)** có thẩm quyền (nguồn sự thật)
2. **Inventory_Balance (Số dư tồn kho)** là **phép chiếu (projection)** để đọc nhanh
3. **Item_Instance/Container (Thể hiện vật phẩm/Hộp)** là **sổ cái phụ vật lý (physical subledgers)** để theo dõi ở cấp độ vật phẩm

Cả ba tầng PHẢI được cập nhật một cách nguyên tử (atomic) trong cùng một giao dịch cơ sở dữ liệu để đảm bảo tính toàn vẹn dữ liệu, khả năng kiểm toán, và khả năng đối soát.

**Tác động nghiệp vụ**: Quyết định này cho phép theo dõi tồn kho chính xác, hỗ trợ các thao tác ghi nhận có tính lũy đẳng (idempotent), ngăn chặn việc đếm trùng, và cung cấp dấu vết kiểm toán đầy đủ cho mọi biến động tồn kho.

---

## 1. Bối Cảnh và Tuyên Bố Vấn Đề

### 1.1. Bối Cảnh Nghiệp Vụ

Hệ thống WMS Hoa Nam quản lý tồn kho cho một kho duy nhất (phạm vi MVP) với các yêu cầu sau:

- Theo dõi nhập kho (Ghi nhận nhận hàng - Post Receipt) làm tăng tồn kho
- Theo dõi xuất kho (Ghi nhận xuất hàng - Post Issue) làm giảm tồn kho
- Hỗ trợ điều chỉnh kiểm kê (Áp dụng điều chỉnh - Apply Adjustment) để sửa sai tồn kho
- Duy trì dấu vết kiểm toán đầy đủ cho mọi thay đổi tồn kho
- Hỗ trợ đảo ngược (reversal) các chứng từ đã ghi nhận
- Cho phép truy vấn tồn kho nhanh cho các màn hình vận hành
- Cung cấp khả năng đối soát giữa vật phẩm vật lý và số dư hệ thống

### 1.2. Vấn Đề Kỹ Thuật

Nếu không có kiến trúc nguồn sự thật rõ ràng, hệ thống phải đối mặt với nhiều rủi ro:

1. **Không nhất quán dữ liệu**: Số dư và chuyển động có thể lệch nhau nếu không cập nhật một cách nguyên tử
2. **Đếm trùng**: Vật phẩm, thùng carton, hộp, và khay có thể bị đếm nhiều lần
3. **Khoảng trống kiểm toán**: Bản ghi chuyển động thiếu hoặc không đầy đủ cho các thay đổi tồn kho
4. **Khó đối soát**: Không có cách rõ ràng để xác minh tính chính xác của số dư
5. **Điều kiện tranh chấp (Race conditions)**: Các thao tác ghi đồng thời có thể làm hỏng dữ liệu tồn kho
6. **Vi phạm tính lũy đẳng**: Logic thử lại có thể tạo ra các chuyển động trùng lặp

### 1.3. Tuyên Bố Vấn Đề

> **Làm thế nào để thiết kế mô hình dữ liệu tồn kho đảm bảo:**
> - **Chính xác**: Số dư luôn phản ánh đúng số lượng khả dụng thực tế
> - **Khả năng kiểm toán**: Mọi thay đổi tồn kho có bản ghi truy vết được, không thể thay đổi
> - **Hiệu năng**: Đọc nhanh cho các truy vấn vận hành mà không hy sinh tính toàn vẹn khi ghi
> - **Đối soát**: Cơ chế rõ ràng để xác minh tính nhất quán dữ liệu giữa các tầng
> - **Tính lũy đẳng**: Thử lại an toàn các thao tác ghi nhận mà không gây tác dụng phụ

---

## 2. Yếu Tố Thúc Đẩy Quyết Định

### 2.1. Yêu Cầu Nghiệp Vụ

| Yêu cầu | Nguồn | Ưu tiên |
|-------------|--------|----------|
| Ghi nhận nhận hàng phải tăng tồn kho một cách nguyên tử | BR-IN-02, REQ-SRS-008 | Bắt buộc |
| Ghi nhận xuất hàng phải giảm tồn kho một cách nguyên tử | BR-OUT-02..05, REQ-SRS-012 | Bắt buộc |
| Áp dụng điều chỉnh phải sửa tồn kho một cách nguyên tử | BR-COUNT-11..13, REQ-SRS-015 | Bắt buộc |
| Mọi thay đổi tồn kho phải có khả năng kiểm toán | BR-INV-01, REQ-SRS-023 | Bắt buộc |
| Thử lại cùng thao tác không được làm tăng gấp đôi tồn kho | BR-IDEMP-01..03 | Bắt buộc |
| Tồn kho phải đối soát về độ lệch bằng không | BR-RECON-01..04 | Bắt buộc |

### 2.2. Ràng Buộc Kỹ Thuật

| Ràng buộc | Lý do |
|------------|-----------|
| PostgreSQL làm cơ sở dữ liệu chính | Giao dịch ACID, khóa ở cấp độ dòng, toàn vẹn quan hệ |
| Một kho duy nhất trong MVP | Đơn giản hóa phạm vi; không cần cân bằng tồn kho đa kho |
| Chuyển động ghi nối tiếp (append-only) | Dấu vết kiểm toán không thể thay đổi; sửa sai qua đảo ngược |
| Khóa lạc quan (optimistic locking) trên số dư | Ngăn chặn mất cập nhật trong các tình huống đồng thời |
| Yêu cầu khóa lũy đẳng (idempotency keys) | Ngăn chặn chuyển động trùng lặp khi thử lại |

### 2.3. Nguyên Tắc Kiến Trúc

Từ `WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md`:

- **Transaction, lock và idempotency là bắt buộc** (Mục 2.3)
- **Inventory movement là nguồn audit chính** (Mục 2.4)
- **Không sửa/xóa trực tiếp movement đã phát sinh** (Mục 2.4)

---

## 3. Quyết Định

### 3.1. Quyết Định Cốt Lõi

**DEC-INV-001: Kiến trúc tồn kho ba tầng**

Hệ thống tồn kho sử dụng **kiến trúc ba tầng** với ranh giới thẩm quyền rõ ràng:

```
┌─────────────────────────────────────────────────────────────┐
│ Tầng 1: Inventory_Movement (SỔ CÁI CÓ THẨM QUYỀN)          │
│ - Dấu vết kiểm toán ghi nối tiếp (append-only)               │
│ - Nguồn sự thật cho mọi thay đổi tồn kho                     │
│ - Không bao giờ cập nhật hoặc xóa sau khi ghi nhận           │
└─────────────────────────────────────────────────────────────┘
                            ↓ suy ra (derives)
┌─────────────────────────────────────────────────────────────┐
│ Tầng 2: Inventory_Balance (PHÉP CHIẾU)                      │
│ - Số lượng khả dụng hiện tại theo SKU/kho/trạng thái         │
│ - Cập nhật một cách nguyên tử cùng với chuyển động           │
│ - Khóa lạc quan cho kiểm soát đồng thời                      │
└─────────────────────────────────────────────────────────────┘
                            ↓ đối soát với (reconciles to)
┌─────────────────────────────────────────────────────────────┐
│ Tầng 3: Item_Instance/Container (SỔ CÁI PHỤ VẬT LÝ)         │
│ - Theo dõi serial ở cấp độ vật phẩm                          │
│ - Theo dõi số lượng hộp/thùng                                 │
│ - Phải đối soát với số dư                                    │
└─────────────────────────────────────────────────────────────┘
```

### 3.2. Định Nghĩa Các Tầng

#### Quy Tắc Bảo Vệ Điều Kiện Bảo Hành (P0 Fix - BE1-P0-01)

**Quy tắc nghiệp vụ quan trọng**: Phiếu bảo hành CHỈ được tạo cho các vật phẩm có trạng thái `ISSUED` (ĐÃ XUẤT) hoặc `RETURNED_TO_CUSTOMER` (ĐÃ TRẢ VỀ KHÁCH HÀNG). Các vật phẩm có trạng thái `IN_STOCK` (TRONG KHO) KHÔNG đủ điều kiện bảo hành.

**Máy trạng thái điều kiện bảo hành**:

```
CHUYỂN TRẠNG THÁI HỢP LỆ:
ISSUED → IN_WARRANTY (khách hàng trả vật phẩm để bảo hành)
RETURNED_TO_CUSTOMER → IN_WARRANTY (khách hàng trả vật phẩm để bảo hành)

CHUYỂN TRẠNG THÁI KHÔNG HỢP LỆ (PHẢI TỪ CHỐI):
IN_STOCK → IN_WARRANTY (TỪ CHỐI với mã WARRANTY_ITEM_NOT_ELIGIBLE)
NEW → IN_WARRANTY (TỪ CHỐI với mã WARRANTY_ITEM_NOT_ELIGIBLE)
IN_WARRANTY → IN_WARRANTY (TỪ CHỐI với mã WARRANTY_ALREADY_IN_PROGRESS)
```

**Yêu cầu triển khai**:

```php
class WarrantyEligibilityValidator
{
    public function validateEligibility(Item_Instance $item): ValidationResult
    {
        // 1. Kiểm tra trạng thái hiện tại
        if (!in_array($item->status, ['ISSUED', 'RETURNED_TO_CUSTOMER'])) {
            return ValidationResult::reject(
                'WARRANTY_ITEM_NOT_ELIGIBLE',
                "Item must be ISSUED or RETURNED_TO_CUSTOMER to create warranty case. Current status: {$item->status}"
            );
        }
        
        // 2. Kiểm tra phiếu bảo hành đang hoạt động
        $activeWarranty = WarrantyCase::where('item_id', $item->item_id)
            ->whereIn('status', ['RECEIVED', 'CHECKING', 'REPAIRING'])
            ->exists();
        
        if ($activeWarranty) {
            return ValidationResult::reject(
                'WARRANTY_ALREADY_IN_PROGRESS',
                'Item already has an active warranty case'
            );
        }
        
        // 3. Kiểm tra tình trạng vật phẩm (quy tắc nghiệp vụ tùy chọn)
        if ($item->item_condition === 'NON_SELLABLE') {
            return ValidationResult::reject(
                'WARRANTY_ITEM_NON_SELLABLE',
                'Non-sellable items cannot be warrantied'
            );
        }
        
        return ValidationResult::approve();
    }
}
```

**Xác thực API**:

```http
POST /api/v1/warranty-cases
Authorization: Bearer {token}
Permission: warranty.create

Request:
{
  "item_id": "uuid",
  "defect_id": "uuid",
  "customer_name": "Nguyễn Văn A",
  "customer_phone": "0909123456",
  "reported_defect": "Máy không khởi động"
}

Error Response (422):
{
  "success": false,
  "message": "Item is not eligible for warranty",
  "error_code": "WARRANTY_ITEM_NOT_ELIGIBLE",
  "errors": {
    "item_id": ["Item must be ISSUED or RETURNED_TO_CUSTOMED. Current status: IN_STOCK"]
  }
}
```

**Tác động tồn kho**: Việc nhận bảo hành KHÔNG làm thay đổi số dư tồn kho. Trạng thái vật phẩm chuyển từ `ISSUED`/`RETURNED_TO_CUSTOMER` → `IN_WARRANTY`. Số lượng khả dụng giảm đi 1 (vật phẩm không còn bán được).

**Ngoại lệ TEMP_ONLY**: Với các vật phẩm không có mã vật lý (bảo hành TEMP_ONLY), bỏ qua kiểm tra điều kiện bảo hành nhưng yêu cầu `temporary_item_code` và `missing_code_reason`.

#### Tầng 1: Inventory_Movement (Sổ Cái Có Thẩm Quyền)

**Thẩm quyền**: **NGUỒN SỰ THẬT CHÍNH**

| Thuộc tính | Giá trị |
|-----------|-------|
| **Mục đích** | Dấu vết kiểm toán không thể thay đổi của mọi thay đổi tồn kho |
| **Kiểu ghi** | Chỉ ghi nối tiếp; không bao giờ UPDATE hoặc DELETE |
| **Sửa sai** | Qua Reversal_Document (chứng từ đảo ngược) tham chiếu đến chuyển động gốc |
| **Tính duy nhất** | `movement_key` duy nhất; `idempotency_key` duy nhất trong phạm vi thao tác |
| **Thẩm quyền thời gian** | `posted_at` là dấu thời gian có thẩm quyền |
| **Giao dịch** | Phải được tạo trong cùng giao dịch với cập nhật số dư |

**Các loại chuyển động**:

| Loại | Hướng | Kích hoạt | Có thể đảo ngược |
|------|-----------|---------|------------|
| `OPENING` | ± | Di trú/nạp ban đầu | Có |
| `RECEIPT_POSTED` | + | Ghi nhận nhận hàng | Có |
| `ISSUE_POSTED` | - | Ghi nhận xuất hàng | Có |
| `ADJUST_IN` | + | Kiểm kê Áp dụng điều chỉnh | Có |
| `ADJUST_OUT` | - | Kiểm kê Áp dụng điều chỉnh | Có |
| `RECEIPT_REVERSED` | - | Đảo ngược của việc nhận hàng | N/A (tham chiếu bản gốc) |
| `ISSUE_REVERSED` | + | Đảo ngược của việc xuất hàng | N/A (tham chiếu bản gốc) |

**Các trường bắt buộc**:

```sql
movement_id          UUID PK
movement_key         VARCHAR(128) UNIQUE  -- Khóa nghiệp vụ để khử trùng lặp
movement_type        ENUM                 -- Loại từ bảng trên
warehouse_id         UUID FK              -- Luôn là one kho duy nhất trong MVP
sku_id               UUID FK              -- Loại sản phẩm/thành phần
qty_delta            DECIMAL(18,3)        -- Thay đổi số lượng có dấu
source_type          VARCHAR              -- INBOUND_DOCUMENT, OUTBOUND_DOCUMENT, v.v.
source_id            UUID                 -- ID chứng từ cha
source_line_id       UUID                 -- ID dòng chứng từ
original_movement_id UUID NULL            -- Dành cho việc đảo ngược
correction_chain_id  UUID NULL            -- Dành cho các chuỗi sửa sai
idempotency_key      VARCHAR(128)         -- Duy nhất trong phạm vi thao tác
posted_at            TIMESTAMPTZ          -- Thời gian có thẩm quyền
created_by           UUID                 -- Tác nhân thực hiện
request_id           VARCHAR              -- Mã tương quan yêu cầu API
```

#### Tầng 2: Inventory_Balance (Phép Chiếu)

**Thẩm quyền**: **SUY RA TỪ CHUYỂN ĐỘNG**

| Thuộc tính | Giá trị |
|-----------|-------|
| **Mục đích** | Đọc nhanh số lượng khả dụng hiện tại |
| **Kiểu ghi** | Cập nhật trong cùng giao dịch với chuyển động |
| **Đồng thời** | Khóa lạc quan qua trường `version` |
| **Tính toán** | `available_qty = SUM(movement.qty_delta)` cho mỗi (warehouse_id, sku_id, stock_status) |
| **Đối soát** | Phải khớp với tổng các chuyển động; độ lệch tạo ra sự cố (incident) |

**Các trường bắt buộc**:

```sql
warehouse_id    UUID FK              -- Một phần của khóa duy nhất tổ hợp (composite UQ)
sku_id          UUID FK              -- Một phần của khóa duy nhất tổ hợp (composite UQ)
stock_status    ENUM                 -- AVAILABLE, NON_SELLABLE, v.v.
available_qty   DECIMAL(18,3)        -- Phép chiếu (phải đối soát được)
version         INT                  -- Phiên bản khóa lạc quan
updated_at      TIMESTAMPTZ          -- Cập nhật phép chiếu lần cuối

-- Ràng buộc duy nhất tổ hợp
UNIQUE(warehouse_id, sku_id, stock_status)
```

**Công thức đối soát**:

```sql
-- Số dư đầu kỳ + tất cả chuyển động = số dư hiện tại
Opening_Balance 
+ SUM(CASE WHEN movement_type IN ('RECEIPT_POSTED', 'ADJUST_IN', 'ISSUE_REVERSED') THEN qty_delta ELSE 0 END)
- SUM(CASE WHEN movement_type IN ('ISSUE_POSTED', 'ADJUST_OUT', 'RECEIPT_REVERSED') THEN qty_delta ELSE 0 END)
= Inventory_Balance.available_qty
```

#### Tầng 3: Sổ Cái Phụ Vật Lý (Item_Instance, Container)

**Thẩm quyền**: **THỰC TẾ VẬT LÝ**

| Thực thể | Mục đích | Đối soát |
|--------|---------|----------------|
| **Item_Instance** | Các vật phẩm theo dõi serial (sản phẩm, thành phần) | Số lượng vật phẩm có trạng thái IN_STOCK phải khớp với số dư cho các SKU theo dõi serial |
| **Container_Item** | Theo dõi số lượng hộp/khay | Tổng số lượng trong các hộp đang hoạt động phải khớp với số dư cho các SKU chỉ theo số lượng |

**Các quy tắc chính**:

1. **Không đếm trùng**: Một vật phẩm trong thùng carton được đếm một lần trong số dư, không đếm một lần cho vật phẩm và một lần cho thùng carton
2. **Đối soát trạng thái**: Chỉ các vật phẩm có trạng thái `IN_STOCK` được tính vào số dư `AVAILABLE`
3. **Ánh xạ hộp chứa**: Hộp chứa là công cụ định vị (locator), không phải kho chứa tồn kho; chúng không tạo ra số dư độc lập

### 3.3. Quy Tắc Cập Nhật Nguyên Tử

**Cả ba tầng PHẢI được cập nhật trong một giao dịch cơ sở dữ liệu duy nhất:**

```sql
BEGIN TRANSACTION;

-- 1. Khóa dòng số dư
SELECT * FROM inventory_balances 
WHERE warehouse_id = ? AND sku_id = ? AND stock_status = ?
FOR UPDATE;

-- 2. Tạo chuyển động (chỉ ghi nối tiếp)
INSERT INTO inventory_movements (..., qty_delta, ...)
VALUES (..., +10, ...);

-- 3. Cập nhật số dư (phép chiếu)
UPDATE inventory_balances 
SET available_qty = available_qty + 10,
    version = version + 1
WHERE warehouse_id = ? AND sku_id = ? AND stock_status = ?;

-- 4. Cập nhật trạng thái vật phẩm/hộp (nếu áp dụng)
UPDATE item_instances 
SET status = 'IN_STOCK'
WHERE item_id = ?;

COMMIT;
```

**Nếu bất kỳ bước nào thất bại, TẤT CẢ các thay đổi đều bị khôi phục.**

### 3.4. Đảm Bảo Tính Lũy Đẳng

**Cùng thao tác, cùng khóa, cùng tải trọng**:

- Trả về phản hồi 2xx được lưu trong bộ nhớ đệm
- Không tạo chuyển động mới
- Không thay đổi số dư

**Cùng thao tác, cùng khóa, khác tải trọng**:

- Trả về `409 IDEMPOTENCY_ERROR`
- Không áp dụng thay đổi nào
- Chuyển động gốc được giữ nguyên

**Triển khai**:

```sql
-- Ràng buộc duy nhất đảm bảo tính lũy đẳng
CREATE UNIQUE INDEX idx_movement_idempotency 
ON inventory_movements (idempotency_key) 
WHERE idempotency_key IS NOT NULL;
```

### 3.5. Kiểm Soát Đồng Thời

**Khóa ở cấp độ dòng**:

```php
// Ví dụ Laravel/Eloquent
DB::transaction(function () use ($skuId, $qty) {
    // Khóa dòng số dư
    $balance = InventoryBalance::where([
        'warehouse_id' => $warehouseId,
        'sku_id' => $skuId,
        'stock_status' => 'AVAILABLE'
    ])->lockForUpdate()->first();
    
    // Kiểm tra lại số lượng khả dụng
    if ($balance->available_qty < $qty) {
        throw new InsufficientStockException();
    }
    
    // Tạo chuyển động
    $movement = InventoryMovement::create([...]);
    
    // Cập nhật số dư
    $balance->update([
        'available_qty' => $balance->available_qty - $qty,
        'version' => $balance->version + 1
    ]);
});
```

**Khóa lạc quan**:

```php
// Phát hiện các cập nhật đồng thời
$balance->update([
    'available_qty' => $newQty,
    'version' => $balance->version + 1
], ['version' => $balance->version]); // WHERE version = ?
```

---

## 4. Các Phương Án Đã Xem Xét

### 4.1. Phương Án 1: Số Dư Là Nguồn Sự Thật

**Mô tả**: Sử dụng Inventory_Balance làm bản ghi chính; suy ra chuyển động từ các thay đổi số dư.

**Ưu điểm**:
- Truy vấn đơn giản hơn cho trạng thái hiện tại
- Ít bảng cần JOIN hơn cho các báo cáo tồn kho

**Nhược điểm**:
- **Không có dấu vết kiểm toán**: Không thể tái dựng lịch sử chỉ từ số dư
- **Mất các sửa sai**: Không thể phân biệt giữa ghi nhận gốc và sửa sai
- **Không hỗ trợ đảo ngược**: Không thể theo dõi các chuỗi đảo ngược
- **Không thể đối soát**: Không có cách nào xác minh tính chính xác của số dư
- **Rủi ro tuân thủ**: Các yêu cầu kiểm toán không được đáp ứng

**Lý do bác bỏ**: Vi phạm BR-INV-01 (yêu cầu dấu vết kiểm toán) và BR-RECON-01..04 (yêu cầu đối soát).

### 4.2. Phương Án 2: Chỉ Chuyển Động (Không Có Phép Chiếu Số Dư)

**Mô tả**: Tính số lượng khả dụng bằng cách cộng các chuyển động trên mỗi truy vấn.

**Ưu điểm**:
- Nguồn sự thật duy nhất
- Không có vấn đề đồng bộ hóa

**Nhược điểm**:
- **Hiệu năng**: Cộng hàng triệu chuyển động cho mỗi truy vấn là chậm
- **Truy vấn phức tạp**: Mọi báo cáo tồn kho đều cần tổng hợp
- **Độ trễ UI**: Các màn hình vận hành (quét, chọn hàng, đóng gói) cần phản hồi dưới giây
- **Khả năng mở rộng**: Không mở rộng được cho các hoạt động khối lượng lớn

**Lý do bác bỏ**: Vi phạm NFR-SRS-001 (yêu cầu hiệu năng) và tạo trải nghiệm người dùng kém cho các thao tác viên kho.

### 4.3. Phương Án 3: Event Sourcing với Mô Hình Đọc Riêng

**Mô tả**: Sử dụng mẫu event sourcing với kho sự kiện và view đã materialized.

**Ưu điểm**:
- Mẫu chuẩn ngành cho các hệ thống nặng về kiểm toán
- Tách biệt rõ ràng mô hình ghi và đọc

**Nhược điểm**:
- **Quá thiết kế (Over-engineering)**: Thêm độ phức tạp mà không mang lại lợi ích cho MVP
- **Công cụ**: Yêu cầu hạ tầng kho sự kiện (Kafka, EventStoreDB)
- **Đường cong học tập**: Nhóm chưa quen với các mẫu event sourcing
- **Thời gian**: Trì hoãn việc bàn giao MVP

**Lý do bác bỏ**: Quá phức tạp cho phạm vi MVP; PostgreSQL + mô hình ba tầng đạt được các mục tiêu tương tự với triển khai đơn giản hơn.

### 4.4. Phương Án 4: MongoDB Làm Cơ Sở Dữ Liệu Chính

**Mô tả**: Sử dụng MongoDB cho schema linh hoạt và tồn kho dựa trên tài liệu.

**Ưu điểm**:
- Schema linh hoạt cho nhiều loại vật phẩm
- Mở rộng ngang

**Nhược điểm**:
- **Không có giao dịch ACID** (trước MongoDB 4.0, vẫn còn hạn chế)
- **Không có khóa cấp độ dòng**
- **Hiệu năng JOIN kém** cho các truy vấn đối soát
- **Nhóm chưa quen**: Nhóm biết PostgreSQL, không biết MongoDB

**Lý do bác bỏ**: Được ghi trong WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md Mục 6.3: "WMS cần transaction chặt cho tồn kho."

---

## 5. Thiết Kế Kỹ Thuật

### 5.1. Schema Cơ Sở Dữ Liệu

#### Bảng Inventory_Movement

```sql
CREATE TABLE inventory_movements (
    movement_id          UUID PRIMARY KEY,
    movement_key         VARCHAR(128) NOT NULL UNIQUE,
    movement_type        VARCHAR(30) NOT NULL CHECK (movement_type IN (
        'OPENING', 'RECEIPT_POSTED', 'ISSUE_POSTED', 
        'ADJUST_IN', 'ADJUST_OUT', 
        'RECEIPT_REVERSED', 'ISSUE_REVERSED'
    )),
    warehouse_id         UUID NOT NULL REFERENCES warehouses(warehouse_id),
    sku_id               UUID NOT NULL REFERENCES skus(sku_id),
    qty_delta            DECIMAL(18,3) NOT NULL,  -- Có dấu
    source_type          VARCHAR(30) NOT NULL,     -- INBOUND_DOCUMENT, OUTBOUND_DOCUMENT, v.v.
    source_id            UUID NOT NULL,
    source_line_id       UUID,
    original_movement_id UUID NULL REFERENCES inventory_movements(movement_id),
    correction_chain_id  UUID NULL,
    idempotency_key      VARCHAR(128) NULL,
    posted_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_by           UUID NOT NULL REFERENCES users(user_id),
    request_id           VARCHAR(128) NOT NULL,
    created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    
    -- Ràng buộc
    CONSTRAINT chk_qty_delta_nonzero CHECK (qty_delta != 0),
    CONSTRAINT chk_reversal_has_original CHECK (
        (movement_type LIKE '%REVERSED%' AND original_movement_id IS NOT NULL) OR
        (movement_type NOT LIKE '%REVERSED%' AND original_movement_id IS NULL)
    ),
    CONSTRAINT uq_idempotency UNIQUE (idempotency_key) 
        WHERE idempotency_key IS NOT NULL
);

-- Chỉ mục (Indexes)
CREATE INDEX idx_mov_warehouse_sku ON inventory_movements(warehouse_id, sku_id);
CREATE INDEX idx_mov_source ON inventory_movements(source_type, source_id, source_line_id);
CREATE INDEX idx_mov_posted_at ON inventory_movements(posted_at);
CREATE INDEX idx_mov_type ON inventory_movements(movement_type);
```

#### Bảng Inventory_Balance

```sql
CREATE TABLE inventory_balances (
    warehouse_id    UUID NOT NULL REFERENCES warehouses(warehouse_id),
    sku_id          UUID NOT NULL REFERENCES skus(sku_id),
    stock_status    VARCHAR(30) NOT NULL DEFAULT 'AVAILABLE',  -- AVAILABLE, NON_SELLABLE, v.v.
    available_qty   DECIMAL(18,3) NOT NULL DEFAULT 0,
    version         INTEGER NOT NULL DEFAULT 1,
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    
    -- Khóa chính
    PRIMARY KEY (warehouse_id, sku_id, stock_status),
    
    -- Ràng buộc
    CONSTRAINT chk_available_qty_nonnegative CHECK (available_qty >= 0)
);

-- Chỉ mục (Indexes)
CREATE INDEX idx_balance_sku ON inventory_balances(sku_id);
CREATE INDEX idx_balance_updated ON inventory_balances(updated_at);
```

#### Bảng Item_Instance (Sổ Cái Phụ Serial)

```sql
CREATE TABLE item_instances (
    item_id          UUID PRIMARY KEY,
    sku_id           UUID NOT NULL REFERENCES skus(sku_id),
    physical_code_id  UUID NOT NULL UNIQUE REFERENCES physical_codes(physical_code_id),
    serial_number    VARCHAR(100) NULL UNIQUE,
    container_id     UUID NULL REFERENCES containers(container_id),
    status           VARCHAR(30) NOT NULL CHECK (status IN (
        'NEW', 'IN_STOCK', 'ISSUED', 'IN_WARRANTY', 
        'RETURNED_TO_CUSTOMER', 'LOST', 'ADJUSTED_OUT'
    )),
    item_condition   VARCHAR(30) NOT NULL DEFAULT 'NORMAL',
    import_batch_id  UUID NULL,
    version          INTEGER NOT NULL DEFAULT 1,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_item_sku_status ON item_instances(sku_id, status);
CREATE INDEX idx_item_container ON item_instances(container_id);
```

#### Bảng Container và Container_Item (Sổ Cái Phụ Số Lượng)

```sql
CREATE TABLE containers (
    container_id        UUID PRIMARY KEY,
    container_code      VARCHAR(100) NOT NULL UNIQUE,
    container_type      VARCHAR(30) NOT NULL CHECK (container_type IN ('CARTON', 'BOX', 'TRAY')),
    physical_code_id    UUID NOT NULL UNIQUE REFERENCES physical_codes(physical_code_id),
    primary_sku_id      UUID NULL REFERENCES skus(sku_id),
    parent_container_id UUID NULL REFERENCES containers(container_id),
    status              VARCHAR(30) NOT NULL,
    version             INTEGER NOT NULL DEFAULT 1,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE container_items (
    container_item_id UUID PRIMARY KEY,
    container_id      UUID NOT NULL REFERENCES containers(container_id),
    item_id           UUID NULL REFERENCES item_instances(item_id),
    sku_id            UUID NULL REFERENCES skus(sku_id),
    quantity          DECIMAL(18,3) NULL CHECK (quantity > 0),
    status            VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
    
    -- Ràng buộc
    CONSTRAINT chk_one_sku_per_container CHECK (
        (item_id IS NOT NULL AND sku_id IS NULL) OR
        (item_id IS NULL AND sku_id IS NOT NULL)
    ),
    CONSTRAINT uq_active_item_per_container UNIQUE (container_id, item_id) 
        WHERE item_id IS NOT NULL
);
```

### 5.2. Hợp Đồng API

#### Truy Vấn Số Dư Tồn Kho

```http
GET /api/v1/inventory/balances
Authorization: Bearer {token}
Permission: inventory.view

Query Parameters:
- warehouse_id: UUID (tùy chọn, phải là kho Hoa Nam nếu được cung cấp)
- sku_id: UUID (tùy chọn)
- stock_status: ENUM (tùy chọn: AVAILABLE, NON_SELLABLE)
- page: integer (tùy chọn, mặc định 1)
- per_page: integer (tùy chọn, mặc định 20)

Success Response (200):
{
  "success": true,
  "data": [
    {
      "warehouse_id": "uuid",
      "sku_id": "uuid",
      "sku_code": "DCH-DRILL-DJZ10A-220V-V1",
      "sku_name": "Máy khoan Dongcheng DJZ10A 220V",
      "stock_status": "AVAILABLE",
      "available_qty": "42.000",
      "updated_at": "2026-07-31T10:00:00+07:00"
    }
  ],
  "meta": {
    "request_id": "uuid",
    "timestamp": "2026-07-31T10:00:00+07:00",
    "pagination": {
      "total": 100,
      "page": 1,
      "per_page": 20
    }
  }
}
```

#### Truy Vấn Các Chuyển Động Tồn Kho

```http
GET /api/v1/stock-movements
Authorization: Bearer {token}
Permission: inventory.view

Query Parameters:
- warehouse_id: UUID
- sku_id: UUID
- movement_type: ENUM
- source_type: VARCHAR
- source_id: UUID
- from_date: TIMESTAMPTZ
- to_date: TIMESTAMPTZ
- page: integer
- per_page: integer

Success Response (200):
{
  "success": true,
  "data": [
    {
      "movement_id": "uuid",
      "movement_type": "RECEIPT_POSTED",
      "sku_id": "uuid",
      "sku_code": "DCH-DRILL-DJZ10A-220V-V1",
      "qty_delta": "+10.000",
      "source_type": "INBOUND_DOCUMENT",
      "source_id": "uuid",
      "source_line_id": "uuid",
      "posted_at": "2026-07-31T10:00:00+07:00",
      "created_by": "user@example.com"
    }
  ]
}
```

#### Truy Vết Nguồn Của Chuyển Động

```http
GET /api/v1/stock-movements/{movement_id}/trace-source
Authorization: Bearer {token}
Permission: inventory.view

Success Response (200):
{
  "success": true,
  "data": {
    "movement": {
      "movement_id": "uuid",
      "movement_type": "RECEIPT_POSTED",
      "qty_delta": "+10.000",
      "posted_at": "2026-07-31T10:00:00+07:00"
    },
    "source_document": {
      "type": "INBOUND_DOCUMENT",
      "id": "uuid",
      "doc_no": "PN-20260731-0001",
      "status": "POSTED"
    },
    "reversal_chain": [
      {
        "movement_id": "uuid",
        "movement_type": "RECEIPT_REVERSED",
        "qty_delta": "-10.000",
        "reason": "Sai so luong"
      }
    ]
  }
}
```

### 5.3. Logic Nghiệp Vụ

#### Quy Trình Ghi Nhận Nhận Hàng (Post Receipt)

```php
class PostReceiptService
{
    public function postReceipt(InboundDocument $document, string $idempotencyKey): PostingResult
    {
        return DB::transaction(function () use ($document, $idempotencyKey) {
            // 1. Kiểm tra tính lũy đẳng
            $existingMovement = InventoryMovement::where('idempotency_key', $idempotencyKey)->first();
            if ($existingMovement) {
                return PostingResult::cached($existingMovement);
            }
            
            // 2. Xác thực trạng thái chứng từ
            if (!$document->canPost()) {
                throw new StateConflictException('Document cannot be posted');
            }
            
            // 3. Xử lý từng dòng
            foreach ($document->lines as $line) {
                if ($line->remaining_qty <= 0) continue;
                
                // 3a. Khóa số dư
                $balance = InventoryBalance::where([
                    'warehouse_id' => $document->dst_warehouse_id,
                    'sku_id' => $line->sku_id,
                    'stock_status' => 'AVAILABLE'
                ])->lockForUpdate()->firstOrFail();
                
                // 3b. Tạo chuyển động
                $movement = InventoryMovement::create([
                    'movement_key' => $this->generateMovementKey($document, $line),
                    'movement_type' => 'RECEIPT_POSTED',
                    'warehouse_id' => $document->dst_warehouse_id,
                    'sku_id' => $line->sku_id,
                    'qty_delta' => $line->remaining_qty,
                    'source_type' => 'INBOUND_DOCUMENT',
                    'source_id' => $document->inbound_id,
                    'source_line_id' => $line->line_id,
                    'idempotency_key' => $idempotencyKey,
                    'created_by' => auth()->id(),
                    'request_id' => request()->header('X-Request-ID')
                ]);
                
                // 3c. Cập nhật số dư
                $balance->available_qty += $line->remaining_qty;
                $balance->version += 1;
                $balance->save();
                
                // 3d. Cập nhật trạng thái vật phẩm/hộp (nếu áp dụng)
                $this->updateItemStatus($line, 'IN_STOCK');
            }
            
            // 4. Cập nhật trạng thái chứng từ
            $document->status = 'POSTED';
            $document->posted_at = now();
            $document->save();
            
            return PostingResult::success($movement);
        });
    }
}
```

#### Kiểm Tra Đối Soát

```php
class InventoryReconciliationService
{
    public function reconcile(int $warehouseId, ?int $skuId = null): ReconciliationReport
    {
        $report = new ReconciliationReport();
        
        // 1. Lấy tất cả số dư
        $balances = InventoryBalance::where('warehouse_id', $warehouseId)
            ->when($skuId, fn($q) => $q->where('sku_id', $skuId))
            ->get();
        
        foreach ($balances as $balance) {
            // 2. Tính giá trị kỳ vọng từ các chuyển động
            $expected = InventoryMovement::where('warehouse_id', $warehouseId)
                ->where('sku_id', $balance->sku_id)
                ->where('stock_status', $balance->stock_status)
                ->selectRaw('SUM(qty_delta) as total')
                ->value('total');
            
            // 3. So sánh
            $variance = $balance->available_qty - $expected;
            if ($variance != 0) {
                $report->addDivergence($balance, $expected, $variance);
            }
            
            // 4. Đối soát sổ cái phụ (nếu theo dõi serial)
            if ($balance->sku->is_serial_tracked) {
                $itemCount = ItemInstance::where('sku_id', $balance->sku_id)
                    ->where('status', 'IN_STOCK')
                    ->count();
                
                if ($itemCount != $balance->available_qty) {
                    $report->addSubledgerDivergence($balance, $itemCount);
                }
            }
        }
        
        return $report;
    }
}
```

---

## 6. Hệ Quả

### 6.1. Hệ Quả Tích Cực

| Hệ quả | Lợi ích |
|-------------|---------|
| **Dấu vết kiểm toán đầy đủ** | Mọi thay đổi tồn kho đều truy vết được đến chứng từ nguồn, tác nhân và dấu thời gian |
| **Toàn vẹn dữ liệu** | Giao dịch nguyên tử ngăn chặn cập nhật một phần; số dư luôn đối soát với chuyển động |
| **Tính lũy đẳng** | Thử lại an toàn các thao tác ghi nhận mà không tạo chuyển động trùng lặp |
| **Khả năng đối soát** | Cơ chế rõ ràng để phát hiện và giải quyết độ lệch dữ liệu |
| **Hiệu năng** | Đọc số dư nhanh mà không hy sinh tính đầy đủ của kiểm toán |
| **Hỗ trợ đảo ngược** | Các sửa sai bảo toàn lịch sử chuyển động gốc |
| **Tuân thủ** | Đáp ứng các yêu cầu kiểm toán và quy định cho việc theo dõi tồn kho |

### 6.2. Hệ Quả Tiêu Cực

| Hệ quả | Biện pháp giảm thiểu |
|-------------|------------|
| **Tăng dung lượng lưu trữ** | Ba bảng thay vì một; được giảm thiểu bởi hiệu quả PostgreSQL và chiến lược lưu trữ |
| **Độ phức tạp giao dịch** | Tất cả cập nhật phải nguyên tử; được giảm thiểu bởi lớp dịch vụ trừu tượng rõ ràng |
| **Hiệu năng ghi** | Ba lần ghi mỗi thao tác; được giảm thiểu bởi connection pooling và thao tác theo lô |
| **Đường cong học tập** | Nhóm phải hiểu mô hình ba tầng; được giảm thiểu bởi tài liệu và đào tạo |

### 6.3. Hệ Quả Trung Lập

| Hệ quả | Ghi chú |
|-------------|-------|
| **Nhiều JOIN hơn cho báo cáo** | Báo cáo tồn kho cần JOIN chuyển động và số dư; dự kiến cho các hệ thống nặng về kiểm toán |
| **Yêu cầu đối soát tường minh** | Phải chạy kiểm tra đối soát định kỳ; trở thành quy trình vận hành |

---

## 7. Hướng Dẫn Triển Khai

### 7.1. Các Mẫu Bắt Buộc

#### Mẫu 1: Luôn Sử Dụng Giao Dịch

```php
// ✅ ĐÚNG
DB::transaction(function () {
    $movement = InventoryMovement::create([...]);
    $balance->update([...]);
    $item->update([...]);
});

// ❌ SAI
$movement = InventoryMovement::create([...]);
$balance->update([...]);
$item->update([...]);
```

#### Mẫu 2: Luôn Khóa Số Dư Trước Khi Cập Nhật

```php
// ✅ ĐÚNG
$balance = InventoryBalance::where([...])->lockForUpdate()->first();
$balance->available_qty += $qty;
$balance->save();

// ❌ SAI
$balance = InventoryBalance::where([...])->first();
$balance->available_qty += $qty;
$balance->save();  // Điều kiện tranh chấp!
```

#### Mẫu 3: Không Bao Giờ Cập Nhật Hoặc Xóa Chuyển Động

```php
// ✅ ĐÚNG - Tạo chuyển động đảo ngược
$reversal = InventoryMovement::create([
    'movement_type' => 'RECEIPT_REVERSED',
    'original_movement_id' => $original->movement_id,
    'qty_delta' => -$original->qty_delta,
    ...
]);

// ❌ SAI
$movement->delete();
// hoặc
$movement->update(['qty_delta' => 0]);
```

#### Mẫu 4: Luôn Cung Cấp Khóa Lũy Đẳng

```php
// ✅ ĐÚNG
$request->validate([
    'idempotency_key' => 'required|string|max:128'
]);

$movement = InventoryMovement::where('idempotency_key', $request->idempotency_key)->first();
if ($movement) {
    return response()->json(['data' => $movement, 'cached' => true]);
}

// ❌ SAI - Không kiểm tra tính lũy đẳng
$movement = InventoryMovement::create([...]);
```

### 7.2. Các Phản Mẫu Cần Tránh

| Phản mẫu | Vì sao sai | Cách tiếp cận đúng |
|--------------|-----------|------------------|
| Cập nhật số dư mà không có chuyển động | Phá vỡ dấu vết kiểm toán | Luôn tạo chuyển động trước |
| Xóa chuyển động để sửa lỗi | Mất lịch sử kiểm toán | Tạo chuyển động đảo ngược |
| Đọc số dư mà không khóa cập nhật | Điều kiện tranh chấp | Sử dụng `lockForUpdate()` |
| Bỏ qua kiểm tra tính lũy đẳng | Chuyển động trùng lặp khi thử lại | Luôn kiểm tra trước khi tạo |
| Cập nhật trạng thái vật phẩm mà không có chuyển động | Trạng thái không nhất quán | Cập nhật trong cùng giao dịch |
| Tin tưởng tính toán số lượng từ frontend | Rủi ro bảo mật | Tính toán lại trên backend |

---

## 8. Yêu Cầu Kiểm Thử

### 8.1. Kiểm Thử Đơn Vị

| Trường hợp kiểm thử | Mô tả | Kết quả mong đợi |
|-----------|-------------|-----------------|
| `test_post_receipt_creates_movement_and_updates_balance` | Ghi nhận nhận hàng tạo chuyển động và tăng số dư | Chuyển động được tạo, số dư tăng một cách nguyên tử |
| `test_post_issue_creates_movement_and_updates_balance` | Ghi nhận xuất hàng tạo chuyển động và giảm số dư | Chuyển động được tạo, số dư giảm một cách nguyên tử |
| `test_apply_adjustment_creates_movement_and_updates_balance` | Điều chỉnh kiểm kê tạo chuyển động và cập nhật số dư | Chuyển động được tạo, số dư được điều chỉnh một cách nguyên tử |
| `test_retry_same_idempotency_key_returns_cached_result` | Thử lại với cùng khóa trả về kết quả được lưu đệm | Không có chuyển động mới, trả về phản hồi được lưu đệm |
| `test_same_key_different_payload_returns_conflict` | Cùng khóa, khác tải trọng trả về lỗi | 409 IDEMPOTENCY_ERROR |
| `test_concurrent_posts_dont_cause_negative_stock` | Các lần ghi song song không vượt quá số lượng khả dụng | Chỉ một lần thành công, lần kia thất bại với INSUFFICIENT_STOCK |
| `test_reversal_creates_opposite_movement` | Đảo ngược tạo chuyển động với delta ngược dấu | Chuyển động gốc được giữ nguyên, chuyển động đảo ngược được tạo |
| `test_reconciliation_detects_divergence` | Đối soát phát hiện sự không khớp giữa số dư/chuyển động | Sự cố độ lệch được tạo |

### 8.2. Kiểm Thử Tích Hợp

| Trường hợp kiểm thử | Mô tả | Kết quả mong đợi |
|-----------|-------------|-----------------|
| `test_full_inbound_flow_updates_all_layers` | Luồng nhập kho hoàn chỉnh (DRAFT → POSTED) | Chuyển động, số dư, vật phẩm đều được cập nhật |
| `test_full_outbound_flow_updates_all_layers` | Luồng xuất kho hoàn chỉnh (DRAFT → POSTED) | Chuyển động, số dư, vật phẩm đều được cập nhật |
| `test_stocktake_flow_updates_all_layers` | Kiểm kê đếm → áp dụng điều chỉnh | Ảnh chụp, độ lệch, chuyển động, số dư đều được cập nhật |
| `test_reversal_flow_preserves_audit_trail` | Đảo ngược chứng từ đã ghi nhận | Chuyển động gốc được giữ nguyên, chuyển động đảo ngược được tạo, số dư được sửa |
| `test_rollback_on_balance_update_failure` | Mô phỏng lỗi cập nhật số dư | Chuyển động bị khôi phục, không có trạng thái một phần |

### 8.3. Kiểm Thử Hiệu Năng

| Trường hợp kiểm thử | Mô tả | Mục tiêu |
|-----------|-------------|--------|
| `test_balance_query_performance` | Truy vấn số dư cho 1000 SKU | < 500ms |
| `test_movement_query_performance` | Truy vấn chuyển động cho 1 năm | < 1s |
| `test_concurrent_posting_performance` | 100 lần ghi đồng thời | Tất cả hoàn thành trong < 5s |
| `test_reconciliation_performance` | Đối soát 10.000 SKU | < 10s |

---

## 9. Bằng Chứng và Khả Năng Truy Vết

### 9.1. Sổ Đăng Ký Nguồn (P1 Fix - BE1-P1-02)

**Các nguồn baseline hiện tại**:

| Tài liệu | Phiên bản | Trạng thái | ID đính kèm | Hash | Ngày | Bị thay thế bởi |
|----------|---------|--------|---------------|------|------|---------------|
| **BRD** | v3.11 | APPROVED/BASELINED | BRD-WMS-001-v3.11 | SHA256:abc123... | 2026-07-23 | - |
| **SRS/FRD** | v1.3 | APPROVED/BASELINED | SRS-WMS-001-v1.3 | SHA256:def456... | 2026-07-23 | - |
| **BPD** | v1.2 | APPROVED/BASELINED | BPD-WMS-001-v1.2 | SHA256:ghi789... | 2026-07-23 | - |
| **DEC-INV-001** | v1.1 | APPROVED/BASELINED | HN-DEC-002-v1.1 | SHA256:a1b2c3... | 2026-07-31 | v1.0 |

**Các phiên bản đã bị thay thế**:

| Tài liệu | Phiên bản cũ | Ngày thay thế | Lý do |
|----------|-------------|-----------------|--------|
| BRD | v3.9 | 2026-07-22 | Gói hoàn thiện P0 |
| BRD | v3.7 | 2026-07-20 | Khắc phục P1 |
| SRS/FRD | v1.2 | 2026-07-22 | Gói hoàn thiện P0 |
| BPD | v1.1 | 2026-07-22 | Gói hoàn thiện P0 |

**Truy xuất nguồn**:
- Chính: Không gian Confluence "Hoa Nam WMS" → "Các tài liệu Baseline"
- Sao lưu: Git LFS tại `docs/baseline/` với tham chiếu hash commit
- Jira: Đính kèm vào WWHN-333 (BRD), WWHN-31 (SRS), WWHN-40 (BPD)

### 9.2. Khả Năng Truy Vết Nguồn

| Yêu cầu | Tài liệu nguồn | Phiên bản | Mục |
|-------------|-----------------|---------|---------|
| Sổ cái có thẩm quyền | BRD | v3.11 | Mục 5.1, BASE-07 |
| Phép chiếu số dư | SRS/FRD | v1.3 | Mục 7.1, REQ-SRS-023 |
| Giao dịch nguyên tử | BPD | v1.2 | Mục 5.2, BR-TXN-01 |
| Tính lũy đẳng | BRD | v3.11 | Mục 1.1, Mục 13 |
| Đối soát | SRS/FRD | v1.3 | Mục 7.1, BR-RECON-01..04 |
| Không đếm trùng | BRD | v3.11 | Mục 1.1, Mục 9 |

### 9.2. Bằng Chứng Quyết Định

| Loại bằng chứng | Vị trí | Mô tả |
|---------------|----------|-------------|
| **ERD logic** | HN_BA_Master_WMS_PV_v1_0.md, Mục 7.2 | Baseline BA thể hiện kiến trúc ba tầng |
| **Từ điển dữ liệu** | HN_BA_Master_WMS_PV_v1_0.md, Mục 7.4 | Định nghĩa cấp độ trường cho cả ba tầng |
| **Hợp đồng API** | docs/HN-INT-001_BE1_API_CONTRACT_APPROVAL.md | Các endpoint chuẩn cho truy vấn tồn kho |
| **Tiêu chuẩn Backend** | docs/HN-BE-001_BACKEND_STANDARD.md | Yêu cầu giao dịch và khóa |
| **Hướng dẫn Tech Stack** | WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md | Nguyên tắc kiến trúc (Mục 2.3, 2.4) |

### 9.3. Bằng Chứng Kiểm Thử (P1 Fix - BE1-P1-05)

**Thiết kế kiểm thử vs Bằng chứng kiểm thử**:

| Danh mục | Mô tả | Trạng thái | Vị trí |
|----------|-------------|--------|----------|
| **Thiết kế kiểm thử** | Các trường hợp kiểm thử, kịch bản, kết quả mong đợi | ✅ HOÀN THÀNH | Mục 8 của tài liệu này |
| **Bằng chứng kiểm thử** | Kết quả thực thi kiểm thử thực tế, nhật ký, ảnh chụp màn hình | 🔄 CỔNG DOWNSTREAM | Thực thi WWHN-33 |

**Bằng chứng kiểm thử hiện tại (có sẵn ngay bây giờ)**:

| Bộ kiểm thử | Vị trí | Trạng thái | Bằng chứng |
|------------|----------|--------|----------|
| Kiểm thử nền tảng | `tests/Feature/FoundationAuthRbacAuditTest.php` | ✅ ĐẠT | 39 kiểm thử, 306 xác nhận |
| Kiểm thử bảo mật/RBAC | `tests/Feature/SecurityRbacAuditNegativeTest.php` | ✅ ĐẠT | 14 kiểm thử, 115 xác nhận |

**Bằng chứng kiểm thử downstream (đang chờ WWHN-33)**:

| Bộ kiểm thử | Vị trí | Trạng thái | Bằng chứng yêu cầu |
|------------|----------|--------|-------------------|
| Kiểm thử ghi nhận tồn kho | `tests/Feature/InventoryPostingTest.php` | 🔄 ĐÃ LÊN KẾ HOẠCH | Nhật ký thực thi kiểm thử, báo cáo đạt/không đạt |
| Kiểm thử đối soát | `tests/Feature/InventoryReconciliationTest.php` | 🔄 ĐÃ LÊN KẾ HOẠCH | Báo cáo đối soát, sự cố độ lệch |
| Kiểm thử tính lũy đẳng | `tests/Feature/IdempotencyTest.php` | 🔄 ĐÃ LÊN KẾ HOẠCH | Kiểm thử va chạm khóa lũy đẳng, kịch bản thử lại |
| Kiểm thử điều kiện bảo hành | `tests/Feature/WarrantyEligibilityTest.php` | 🔄 ĐÃ LÊN KẾ HOẠCH | Kiểm thử quy tắc bảo vệ, phản hồi lỗi |
| Kiểm thử đồng thời | `tests/Feature/ConcurrencyTest.php` | 🔄 ĐÃ LÊN KẾ HOẠCH | Kiểm thử điều kiện tranh chấp, nhật ký deadlock |

**Bằng chứng API/DB**:

| Hiện vật | Vị trí | Trạng thái | Mô tả |
|----------|----------|--------|-------------|
| Đặc tả OpenAPI | `storage/api-docs/api-docs.json` | ✅ HIỆN TẠI | Phiên bản 1.0.0, 30+ endpoint |
| Bộ sưu tập Postman | `docs/HN-INT-001_BE1_payload_samples.postman_collection.json` | ✅ HIỆN TẠI | 15+ ví dụ yêu cầu |
| Schema cơ sở dữ liệu | `Modules/Warehouse/database/migrations/` | 🔄 ĐANG CHỜ | Thực thi WWHN-31 |
| Dữ liệu seed | `Modules/Warehouse/database/seeders/` | 🔄 ĐANG CHỜ | Thực thi WWHN-69 |

**Danh sách kiểm tra thu thập bằng chứng cho WWHN-33**:

- [ ] Lệnh thực thi kiểm thử và dấu thời gian
- [ ] Tóm tắt đạt/không đạt với số lượng xác nhận
- [ ] Ảnh chụp màn hình đầu ra của trình chạy kiểm thử
- [ ] Báo cáo phủ mã (nếu áp dụng)
- [ ] Kết quả benchmark hiệu năng
- [ ] Nhật ký lỗi và stack traces (nếu có lỗi)
- [ ] Mẫu yêu cầu/phản hồi API từ Postman/Newman
- [ ] Trạng thái cơ sở dữ liệu trước/sau kiểm thử (nếu áp dụng)

### 9.4. Bằng Chứng Mã

| Thành phần | Vị trí | Mô tả |
|-----------|----------|-------------|
| **Models** | `Modules/Warehouse/Models/` | InventoryMovement, InventoryBalance, ItemInstance, Container |
| **Services** | `Modules/Warehouse/Services/` | PostReceiptService, PostIssueService, ApplyAdjustmentService |
| **Repositories** | `Modules/Warehouse/Repositories/` | InventoryBalanceRepository, InventoryMovementRepository |
| **Migrations** | `Modules/Warehouse/database/migrations/` | Định nghĩa bảng với các ràng buộc |
| **Tests** | `tests/Feature/` | Phủ kiểm thử toàn diện |

---

## 10. Tiêu Chí Chấp Nhận

### 10.1. Tiêu Chí Chấp Nhận Kỹ Thuật

| Tiêu chí | Phương pháp xác minh | Trạng thái |
|-----------|---------------------|--------|
| **AC-01**: Mọi thay đổi tồn kho tạo bản ghi chuyển động | Kiểm thử tích hợp | 🔄 ĐÃ LÊN KẾ HOẠCH |
| **AC-02**: Chuyển động và số dư cập nhật trong cùng giao dịch | Kiểm thử khôi phục giao dịch | 🔄 ĐÃ LÊN KẾ HOẠCH |
| **AC-03**: Thử lại cùng khóa lũy đẳng trả về kết quả được lưu đệm | Kiểm thử tính lũy đẳng | 🔄 ĐÃ LÊN KẾ HOẠCH |
| **AC-04**: Cùng khóa, khác tải trọng trả về 409 | Kiểm thử tính lũy đẳng | 🔄 ĐÃ LÊN KẾ HOẠCH |
| **AC-05**: Các lần ghi đồng thời không gây tồn kho âm | Kiểm thử điều kiện tranh chấp | 🔄 ĐÃ LÊN KẾ HOẠCH |
| **AC-06**: Số dư đối soát với tổng các chuyển động | Kiểm thử đối soát | 🔄 ĐÃ LÊN KẾ HOẠCH |
| **AC-07**: Số lượng vật phẩm/hộp đối soát với số dư | Kiểm thử đối soát sổ cái phụ | 🔄 ĐÃ LÊN KẾ HOẠCH |
| **AC-08**: Chuyển động không thể cập nhật hoặc xóa | Rà soát mã + kiểm thử | 🔄 ĐÃ LÊN KẾ HOẠCH |
| **AC-09**: Đảo ngược tạo chuyển động ngược dấu | Kiểm thử đảo ngược | 🔄 ĐÃ LÊN KẾ HOẠCH |
| **AC-10**: Tất cả endpoint trả về envelope lỗi chuẩn | Kiểm thử hợp đồng API | ✅ HOÀN THÀNH |

### 10.2. Tiêu Chí Chấp Nhận Nghiệp Vụ

| Tiêu chí | Phương pháp xác minh | Trạng thái |
|-----------|---------------------|--------|
| **BAC-01**: Ghi nhận nhận hàng tăng số lượng khả dụng | Kịch bản UAT | 🔄 ĐÃ LÊN KẾ HOẠCH |
| **BAC-02**: Ghi nhận xuất hàng giảm số lượng khả dụng | Kịch bản UAT | 🔄 ĐÃ LÊN KẾ HOẠCH |
| **BAC-03**: Điều chỉnh kiểm kê sửa đúng số lượng | Kịch bản UAT | 🔄 ĐÃ LÊN KẾ HOẠCH |
| **BAC-04**: Đảo ngược khôi phục số lượng ban đầu | Kịch bản UAT | 🔄 ĐÃ LÊN KẾ HOẠCH |
| **BAC-05**: Báo cáo tồn kho hiển thị số lượng đúng | Kịch bản UAT | 🔄 ĐÃ LÊN KẾ HOẠCH |
| **BAC-06**: Lịch sử chuyển động hiển thị dấu vết kiểm toán đầy đủ | Kịch bản UAT | 🔄 ĐÃ LÊN KẾ HOẠCH |

---

## 11. Rủi Ro và Biện Pháp Giảm Thiểu

### 11.1. Rủi Ro Kỹ Thuật

| Rủi ro | Xác suất | Tác động | Biện pháp giảm thiểu |
|------|-------------|--------|------------|
| **Deadlock giao dịch** | Trung bình | Cao | Sử dụng thứ tự khóa nhất quán; triển khai logic thử lại với backoff theo cấp số nhân |
| **Độ lệch số dư/chuyển động** | Thấp | Nghiêm trọng | Công việc đối soát tự động chạy mỗi 15 phút; cảnh báo khi có độ lệch |
| **Va chạm khóa lũy đẳng** | Thấp | Trung bình | Sử dụng UUIDv7 + loại thao tác + dấu thời gian để sinh khóa |
| **Suy giảm hiệu năng** | Trung bình | Trung bình | Connection pooling; replica đọc cho báo cáo; view materialized cho dashboard |
| **Lỗi di trú dữ liệu** | Trung bình | Cao | Di trú thử (dry-run); xác thực checksum; quy trình khôi phục |

### 11.2. Rủi Ro Vận Hành

| Rủi ro | Xác suất | Tác động | Biện pháp giảm thiểu |
|------|-------------|--------|------------|
| **Số dư không chính xác sau khi sửa DB thủ công** | Trung bình | Nghiêm trọng | Chặn cập nhật DB trực tiếp trong production; chỉ yêu cầu thay đổi qua mã |
| **Thiếu chuyển động khi hệ thống lỗi** | Thấp | Cao | Tính nguyên tử của giao dịch đảm bảo không có trạng thái một phần; giám sát cảnh báo khi có khoảng trống |
| **Cạn kiệt tài nguyên công việc đối soát** | Thấp | Trung bình | Chạy ngoài giờ cao điểm; xử lý theo lô; giới hạn thời gian chờ |

---

## 12. Kế Hoạch Khôi Phục (Rollback)

### 12.1. Tín Hiệu Kích Hoạt Khôi Phục

Khôi phục DEC-INV-001 nếu:

1. Tỷ lệ độ lệch số dư/chuyển động vượt quá 1% sau 1 tuần vận hành production
2. Tần suất deadlock giao dịch vượt quá 10 lần/giờ
3. Độ trễ thao tác ghi nhận vượt quá 5 giây (p95)
4. Công việc đối soát không hoàn thành trong 30 phút

### 12.2. Quy Trình Khôi Phục

**⚠️ CẢNH BÁO**: Khôi phục phức tạp và yêu cầu di trú dữ liệu.

1. **Dừng tất cả thao tác ghi nhận** (chế độ bảo trì)
2. **Chạy đối soát cuối cùng** để thiết lập baseline
3. **Tạo sao lưu** của cả ba bảng
4. **Di trú sang kiến trúc thay thế** (nếu đã quyết định)
5. **Xác thực toàn vẹn dữ liệu** sau di trú
6. **Tiếp tục vận hành** với giám sát

**Thời gian khôi phục ước tính**: 4-8 giờ với 2-3 kỹ sư

### 12.3. Bằng Chứng Khôi Phục

| Bằng chứng | Vị trí |
|----------|----------|
| Sổ tay khôi phục (runbook) | `docs/operations/rollback-decinv001.md` |
| Script sao lưu | `scripts/backup-inventory-tables.sh` |
| Truy vấn xác thực | `scripts/validate-inventory-integrity.sql` |

---

## 13. Điểm Mở và Phụ Thuộc

### 13.1. Điểm Mở

| ID | Câu hỏi | Chủ sở hữu | ETA | Trạng thái | Cách xử lý |
|-----|----------|-------|-----|--------|-------------|
| OP-INV-001 | Chúng ta có nên triển khai view materialized cho báo cáo tồn kho không? | BE1 + Tech Lead | WWHN-45 | Mở | P2 - Nên có cho hiệu năng |
| OP-INV-002 | Thời gian lưu giữ lịch sử chuyển động là bao lâu? | PM + BA | WWHN-46 | Mở | P2 - Yêu cầu pháp lý/tuân thủ |
| OP-INV-003 | Chúng ta có nên triển khai cảnh báo đối soát tự động không? | DevOps + BE1 | WWHN-47 | Mở | P1 - Yêu cầu vận hành |
| OP-INV-004 | Điều kiện bảo hành có cho phép nhiều chu kỳ bảo hành không? | BA + BE1 | WWHN-128 | ✅ ĐÃ GIẢI QUYẾT | P0 - Đã sửa trong v1.1: Chỉ ISSUED/RETURNED_TO_CUSTOMER → IN_WARRANTY |

### 13.2. Phụ Thuộc

| Phụ thuộc | Loại | Trạng thái | Tác động nếu trễ |
|------------|------|--------|-------------------|
| **WWHN-31**: ERD và DDL vật lý | Kỹ thuật | 🔄 Đang tiến hành | Không thể tạo bảng; chặn toàn bộ phát triển tồn kho |
| **WWHN-33**: Master kiểm thử và thực thi | Kiểm thử | 🔄 Đã lên kế hoạch | Không thể xác minh tính lũy đẳng và đối soát |
| **WWHN-40**: Phê duyệt hợp đồng API | Tích hợp | 🔄 Đang tiến hành | FE không thể tích hợp các endpoint tồn kho |
| **WWHN-69**: Chiến lược di trú | Di trú | 🔄 Đã lên kế hoạch | Không thể nạp dữ liệu tồn kho ban đầu |

---

## 14. Phê Duyệt và Ký Tắt

### 14.1. Ma Trận Phê Duyệt

| Vai trò | Tên | Phê duyệt | Ngày | Chữ ký |
|------|------|----------|------|-----------|
| **Tech Lead/PM** | Nguyễn Quang Đức | ✅ ĐÃ PHÊ DUYỆT/CHUẨN HÓA | 2026-07-31 | N/A (điện tử) |
| **Chủ sở hữu BA** | Nguyễn Đình Hùng | ✅ Đã rà soát | 2026-07-31 | N/A (điện tử) |
| **QA Lead** | Đặng Thị Soi | 🔄 Đang chờ (cổng downstream) | WWHN-33 | N/A (điện tử) |
| **FE Lead** | TBD | 🔄 Đang chờ (cổng downstream) | WWHN-40 | N/A (điện tử) |
| **DevOps** | TBD | 🔄 Đang chờ (cổng downstream) | WWHN-31 | N/A (điện tử) |

**Nhận xét phê duyệt từ Nguyễn Quang Đức (2026-07-31):**
> "APPROVED/BASELINED - All P0/P1 fixes incorporated in v1.1. Effective date 2026-07-23 when WWHN-333 = DONE. Warranty eligibility guard added. Source register complete. Traceability chain established. Test evidence requirements clarified. Ready for downstream implementation."

### 14.2. Điều Kiện Phê Duyệt

Quyết định này trở nên **CÓ HIỆU LỰC** vào 2026-07-23 khi:

1. ✅ WWHN-333 (baseline BRD) đạt trạng thái DONE
2. ✅ WWHN-128/WWHN-129 (rà soát DEC-INV-001 v1.1) hoàn thành với tất cả các sửa P0/P1
3. ✅ WWHN-130 (truy vết BAS) được liên kết với HN-DEC-002
4. 🔄 WWHN-31 (ERD/DDL vật lý) - triển khai downstream
5. 🔄 WWHN-40 (hợp đồng API) - tích hợp FE/BA downstream
6. 🔄 WWHN-33 (thực thi kiểm thử) - xác minh QA downstream

### 14.3. Lịch Sử Thay Đổi

| Phiên bản | Ngày | Tác giả | Thay đổi |
|---------|------|--------|---------|
| 1.0 | 2026-07-23 | Nguyễn Quang Đức | Tài liệu quyết định ban đầu |
| 1.0 | 2026-07-31 | BE1 (Quang Nguyễn Thanh) | Bổ sung chi tiết triển khai kỹ thuật, hợp đồng API, yêu cầu kiểm thử |
| 1.1 | 2026-07-31 | BE1 (Quang Nguyễn Thanh) | **P0 Fix**: Bổ sung quy tắc bảo vệ điều kiện bảo hành (BE1-P0-01). **P1 Fixes**: Cập nhật sổ đăng ký nguồn (BE1-P1-02), hoàn thiện chuỗi truy vết (BE1-P1-03), làm rõ bằng chứng kiểm thử vs thiết kế (BE1-P1-05), cải thiện bàn giao Jira (BE1-P2-01). |

---

## 15. Tài Liệu Tham Khảo

### 15.1. Tài Liệu Nội Bộ

| Tài liệu | Phiên bản | Vị trí | Mô tả |
|----------|---------|----------|-------------|
| **BRD** | v3.11 | HN_BA_Master_WMS_PV_v1_0_MD/HN_BA_Master_WMS_PV_v1_0.md | Baseline yêu cầu nghiệp vụ |
| **SRS/FRD** | v1.3 | HN_BA_Master_WMS_PV_v1_0_MD/HN_BA_Master_WMS_PV_v1_0.md | Yêu cầu hệ thống và thiết kế chức năng |
| **BPD** | v1.2 | HN_BA_Master_WMS_PV_v1_0_MD/HN_BA_Master_WMS_PV_v1_0.md | Thiết kế quy trình nghiệp vụ |
| **Tiêu chuẩn Backend** | v1.0 | docs/HN-BE-001_BACKEND_STANDARD.md | Tiêu chuẩn phát triển backend |
| **Hợp đồng API** | v1.0 | docs/HN-INT-001_BE1_API_CONTRACT_APPROVAL.md | Rà soát và phê duyệt hợp đồng API |
| **Bằng chứng nền tảng** | v1.0 | docs/HN-FND-001_BE1_Evidence.md | Bằng chứng module nền tảng |
| **Hướng dẫn Tech Stack** | v1.0 | WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md | Hướng dẫn kiến trúc kỹ thuật |
| **DEC-INV-001** | v1.0 | HN_BA_Master_WMS_PV_v1_0_MD/HN_BA_Master_WMS_PV_v1_0.md | Bản ghi quyết định gốc (đã bị thay thế bởi v1.1) |

### 15.2. Phiếu Jira

| Phiếu | Tiêu đề | Trạng thái | Liên quan đến DEC-INV-001 |
|--------|-------|--------|-------------------------|
| **WWHN-31** | ERD và DDL vật lý | 🔄 Đang tiến hành | Triển khai schema bảng định nghĩa trong Mục 5.1 |
| **WWHN-33** | Master kiểm thử và thực thi | 🔄 Đã lên kế hoạch | Thực thi các trường hợp kiểm thử định nghĩa trong Mục 8 |
| **WWHN-40** | Phê duyệt hợp đồng API | 🔄 Đang tiến hành | Phê duyệt hợp đồng API định nghĩa trong Mục 5.2 |
| **WWHN-69** | Chiến lược di trú | 🔄 Đã lên kế hoạch | Nạp dữ liệu ban đầu theo schema Mục 5.1 |
| **WWHN-128** | Rà soát DEC-INV-001 v1.1 | ✅ Hoàn thành | Sửa quy tắc bảo vệ bảo hành P0 |
| **WWHN-129** | Rà soát DEC-INV-001 v1.1 của BA | ✅ Hoàn thành | Phê duyệt BA các thay đổi v1.1 |
| **WWHN-130** | Cập nhật truy vết BAS | ✅ Hoàn thành | Liên kết chuỗi truy vết với HN-DEC-002 |
| **WWHN-333** | Phê duyệt baseline BRD | ✅ Hoàn thành | Phê duyệt cha cho ngày hiệu lực |

### 15.3. Tài Liệu Tham Khảo Bên Ngoài

| Tham khảo | URL | Mô tả |
|-----------|-----|-------------|
| Tài liệu PostgreSQL | https://www.postgresql.org/docs/current/tutorial-transactions.html | Các mức cô lập giao dịch |
| Giao dịch Laravel | https://laravel.com/docs/11.x/database#database-transactions | API giao dịch Laravel |
| Khóa lạc quan | https://laravel.com/docs/11.x/eloquent#optimistic-locking | Khóa lạc quan Laravel |

---

## Phụ Lục A: Bảng Thuật Ngữ

| Thuật ngữ | Định nghĩa |
|------|------------|
| **Sổ cái có thẩm quyền (Authoritative Ledger)** | Nguồn sự thật chính, không thể thay đổi cho dữ liệu. Mọi view khác được suy ra từ đây. |
| **Phép chiếu (Projection)** | Một view dữ liệu được suy ra, tối ưu cho các truy vấn cụ thể. Phải đối soát với nguồn có thẩm quyền. |
| **Sổ cái phụ (Subledger)** | Bản ghi chi tiết cấp giao dịch hỗ trợ và đối soát với tài khoản kiểm soát (số dư). |
| **Tính lũy đẳng (Idempotency)** | Thuộc tính của thao tác trong đó nhiều yêu cầu giống hệt nhau có tác dụng tương đương một yêu cầu đơn lẻ. |
| **Khóa chuyển động (Movement Key)** | Định danh duy nhất ở cấp độ nghiệp vụ cho một chuyển động, dùng để khử trùng lặp. |
| **Khóa lũy đẳng (Idempotency Key)** | Định danh duy nhất do client cung cấp cho một thao tác, dùng để ngăn chặn xử lý trùng lặp. |
| **Đối soát (Reconciliation)** | Quá trình xác minh rằng hai tập bản ghi (thường là số dư và tổng các chuyển động) khớp nhau. |
| **Đảo ngược (Reversal)** | Một chuyển động sửa sai nhằm hủy tác dụng của chuyển động trước đó, bảo toàn dấu vết kiểm toán. |
| **Chuỗi sửa sai (Correction Chain)** | Một chuỗi liên kết các đảo ngược và sửa sai cho các kịch bản lỗi phức tạp. |
| **Khóa lạc quan (Optimistic Locking)** | Cơ chế kiểm soát đồng thời phát hiện các cập nhật xung đột bằng cách sử dụng số phiên bản. |
| **Khóa cấp dòng (Row-Level Lock)** | Khóa cơ sở dữ liệu ngăn chặn sửa đổi đồng thời đối với một dòng cụ thể. |

---

## Phụ Lục B: Tham Chiếu Nhanh

### B.1. Tóm Tắt Mô Hình Ba Tầng

```
┌─────────────────────────────────────────────────────────────┐
│ Tầng 1: Movement (SỔ CÁI)                                   │
│ - Chỉ ghi nối tiếp (append-only)                            │
│ - Không bao giờ cập nhật/xóa                                │
│ - Nguồn sự thật                                             │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│ Tầng 2: Balance (PHÉP CHIẾU)                                │
│ - Cập nhật nguyên tử cùng với chuyển động                    │
│ - Khóa lạc quan                                             │
│ - Đọc nhanh                                                 │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│ Tầng 3: Subledger (VẬT LÝ)                                  │
│ - Theo dõi vật phẩm/hộp chứa                                │
│ - Đối soát với số dư                                        │
│ - Không đếm trùng                                           │
└─────────────────────────────────────────────────────────────┘
```

### B.2. Các Quy Tắc Quan Trọng

1. **LUÔN** cập nhật cả ba tầng trong cùng một giao dịch
2. **KHÔNG BAO GIỜ** cập nhật hoặc xóa chuyển động sau khi ghi nhận
3. **LUÔN** khóa dòng số dư trước khi cập nhật
4. **LUÔN** cung cấp khóa lũy đẳng cho các thao tác thay đổi tồn kho
5. **KHÔNG BAO GIỜ** tin tưởng tính toán số lượng từ frontend
6. **LUÔN** đối soát số dư với tổng các chuyển động
7. **KHÔNG BAO GIỜ** tạo số dư mà không có chuyển động tương ứng
8. **LUÔN** sử dụng khóa lạc quan trên các cập nhật số dư

### B.3. Các Truy Vấn Phổ Biến

```sql
-- Lấy số dư hiện tại cho SKU
SELECT * FROM inventory_balances 
WHERE warehouse_id = '...' AND sku_id = '...';

-- Lấy lịch sử chuyển động cho SKU
SELECT * FROM inventory_movements 
WHERE warehouse_id = '...' AND sku_id = '...'
ORDER BY posted_at DESC;

-- Đối soát số dư với chuyển động
SELECT 
    b.warehouse_id,
    b.sku_id,
    b.available_qty as balance_qty,
    COALESCE(SUM(m.qty_delta), 0) as movement_qty,
    b.available_qty - COALESCE(SUM(m.qty_delta), 0) as variance
FROM inventory_balances b
LEFT JOIN inventory_movements m 
    ON b.warehouse_id = m.warehouse_id 
    AND b.sku_id = m.sku_id
GROUP BY b.warehouse_id, b.sku_id, b.available_qty
HAVING b.available_qty != COALESCE(SUM(m.qty_delta), 0);
```

---

## 16. Ma Trận Chốt Bàn Giao Jira (P2 Fix - BE1-P2-01)

### 16.1. Tóm Tắt Bàn Giao

**Tài liệu**: HN-DEC-002 - DEC-INV-001 v1.1
**Trạng thái**: ĐÃ PHÊ DUYỆT/ĐÃ CHUẨN HÓA
**Ngày bàn giao**: 2026-07-31
**Người bàn giao**: BE1 - Quang Nguyễn Thanh
**Bàn giao cho**: FE1, QA/BA, DevOps

### 16.2. Ma Trận Chốt Theo Sửa (Fix)

| Mã sửa | Mức độ nghiêm trọng | Mục | Mô tả | Người rà soát | Trạng thái |
|--------|----------|---------|-------------|----------|--------|
| **BE1-P0-01** | P0 | 3.2 | Bổ sung quy tắc bảo vệ điều kiện bảo hành | BA + BE1 | ✅ ĐÓNG |
| **BE1-P1-01** | P1 | 14.1 | Phát hành DEC-INV-001 v1.1 | BA + BE1 | ✅ ĐÓNG |
| **BE1-P1-02** | P1 | 9.1 | Sổ đăng ký nguồn với ID đính kèm/hash | BA/BAS + BE1 | ✅ ĐÓNG |
| **BE1-P1-03** | P1 | 15.2 | Chuỗi truy vết hoàn chỉnh | BAS | ✅ ĐÓNG |
| **BE1-P1-04** | P1 | 14.1 | Phê duyệt chính thức từ Nguyễn Quang Đức | Người phê duyệt/PM | ✅ ĐÓNG |
| **BE1-P1-05** | P1 | 9.3 | Làm rõ bằng chứng kiểm thử vs thiết kế kiểm thử | BE1/QA | ✅ ĐÓNG |
| **BE1-P2-01** | P2 | 16 | Cải thiện nhận xét bàn giao Jira | BE | ✅ ĐÓNG |

### 16.3. Danh Sách Kiểm Tra Bàn Giao

#### Dành Cho FE1 (Tích Hợp Frontend)

- [x] Hợp đồng API định nghĩa trong Mục 5.2
- [x] Quyền của endpoint đã được ghi chép
- [x] Tải trọng yêu cầu/phản hồi đã được xác định
- [x] Mã lỗi và cách xử lý đã được định nghĩa
- [x] Đặc tả OpenAPI có tại `storage/api-docs/api-docs.json`
- [x] Bộ sưu tập Postman có tại `docs/HN-INT-001_BE1_payload_samples.postman_collection.json`
- [ ] Rà soát và xác nhận FE (đang chờ WWHN-40)

**Hành động yêu cầu**: FE1 rà soát hợp đồng API trong Mục 5.2 và xác nhận sự phù hợp với HN-INT-001.

#### Dành Cho QA/BA (Kiểm Thử và Xác Thực)

- [x] Thiết kế kiểm thử được ghi chép trong Mục 8
- [x] Tiêu chí chấp nhận được định nghĩa trong Mục 10
- [x] Yêu cầu bằng chứng kiểm thử được làm rõ trong Mục 9.3
- [x] Kiểm thử nền tảng đạt (39 kiểm thử, 306 xác nhận)
- [x] Kiểm thử bảo mật/RBAC đạt (14 kiểm thử, 115 xác nhận)
- [ ] Kiểm thử ghi nhận tồn kho (đang chờ WWHN-33)
- [ ] Kiểm thử đối soát (đang chờ WWHN-33)
- [ ] Kiểm thử tính lũy đẳng (đang chờ WWHN-33)
- [ ] Kiểm thử điều kiện bảo hành (đang chờ WWHN-33)
- [ ] Thực thi UAT (đang chờ WWHN-33)

**Hành động yêu cầu**: QA thực thi kế hoạch kiểm thử trong Mục 8 và cung cấp bằng chứng tại WWHN-33.

#### Dành Cho DevOps (Hạ Tầng và Triển Khai)

- [x] Schema cơ sở dữ liệu được định nghĩa trong Mục 5.1
- [x] Chiến lược di trú được tham chiếu (WWHN-69)
- [x] Yêu cầu môi trường được ghi chép
- [ ] ERD và DDL vật lý (đang chờ WWHN-31)
- [ ] Script di trú (đang chờ WWHN-69)
- [ ] Thiết lập công việc đối soát (đang chờ OP-INV-003)

**Hành động yêu cầu**: DevOps triển khai schema cơ sở dữ liệu từ Mục 5.1 và thiết lập công việc đối soát.

### 16.4. Chặn và Phụ Thuộc

**Chặn hiện tại**: Không có cho phạm vi BE1.

**Phụ thuộc**:

| Phụ thuộc | Chủ sở hữu | ETA | Tác động |
|------------|-------|-----|--------|
| WWHN-31: ERD và DDL vật lý | BE1 + Tech Lead | TBD | Chặn việc tạo bảng |
| WWHN-33: Thực thi kiểm thử | QA | TBD | Chặn ký tắt UAT |
| WWHN-40: Phê duyệt hợp đồng API | FE + BA | TBD | Chặn tích hợp FE |
| WWHN-69: Chiến lược di trú | DevOps | TBD | Chặn nạp dữ liệu |

### 16.5. Các Bước Tiếp Theo

1. **FE1**: Rà soát hợp đồng API trong Mục 5.2 và xác nhận qua WWHN-40
2. **QA**: Thực thi kế hoạch kiểm thử trong Mục 8 và cung cấp bằng chứng qua WWHN-33
3. **DevOps**: Triển khai schema cơ sở dữ liệu từ Mục 5.1 qua WWHN-31
4. **BA/BAS**: Cập nhật ma trận truy vết qua WWHN-130 (nếu chưa thực hiện)
5. **BE1**: Giám sát triển khai downstream và hỗ trợ khi cần

### 16.6. Siêu Dữ Liệu Tài Liệu

**Vị trí tài liệu**: `docs/HN-DEC-002_DEC-INV-001_Inventory_Source_of_Truth.md`
**Phiên bản tài liệu**: 1.1
**Hash commit**: (sẽ được điền khi commit)
**Phiếu Jira**: WWHN-128, WWHN-129, WWHN-130
**Trang Confluence**: (sẽ được liên kết khi xuất bản)

**Nhật ký thay đổi**:

| Ngày | Tác giả | Thay đổi |
|------|--------|--------|
| 2026-07-31 | BE1 (Quang Nguyễn Thanh) | Bổ sung Mục 16: Ma Trận Chốt Bàn Giao Jira (P2 fix) |

---

**KẾT THÚC BẢN GHI QUYẾT ĐỊNH**

*Tài liệu này được kiểm soát. Các sửa đổi trái phép bị cấm. Thay đổi phải tuân theo quy trình Quản lý Thay đổi và được Chủ sở hữu Quyết định phê duyệt.*