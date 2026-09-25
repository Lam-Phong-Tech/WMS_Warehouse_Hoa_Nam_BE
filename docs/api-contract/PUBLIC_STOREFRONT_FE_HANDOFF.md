# Bàn giao BE → FE mini app — Public Storefront 1.2.0

Phạm vi: `/api/v1/public/*`, nhánh BE `dev`. Bản sửa không đổi dữ liệu catalogue,
không tạo yêu cầu tư vấn trên môi trường thật, không sửa luồng kho hoặc `/api/v1/pv/*`.
Không cần migration cho bản sửa này. Test fixture chỉ nằm trong test database.

## FE cần cập nhật

1. **Phân trang Products/Search và Related:** đọc `data.page_info.total`,
   `data.page_info.next_cursor`, `data.page_info.has_more`, `data.page_info.limit`.
   `total` là số SKU công khai khớp toàn bộ bộ lọc trước cursor/limit, không phải
   số model, số tồn hay kích thước một trang. Rỗng: total = 0, has_more = false,
   next_cursor = null. Không đọc total/cursor từ meta. Khi đổi từ khóa/bộ lọc/sort,
   bỏ cursor và kết quả cũ của truy vấn trước; bỏ response đến muộn của truy vấn cũ.
   Progress x/y: x là số ID duy nhất đã nhận của truy vấn hiện tại, y là total từ
   API. Catalogue live có thể thay đổi giữa các lượt đọc; total không phải snapshot
   cố định. Không chế mẫu số khi response cũ thiếu total.

2. **Config Quote:** đọc `data.quote_request.max_items` (mặc định 20, env không
   nâng quá 20) và `max_quantity` (mặc định 9999). BE vẫn validate phía server.
   Không tự điền quantity = 1 khi người dùng chưa nhập; gửi null hoặc bỏ trường.
   `privacy.version` độc lập với `contract_version`, không thay privacy version
   thành 1.2.0 chỉ vì API nâng version.

3. **Gửi Quote:** một lần gửi logic dùng một `Idempotency-Key` (16–128 ký tự)
   và một payload đã chốt. Thành công mới: HTTP 201; replay đúng khóa + payload:
   HTTP 200, cùng `data.request_id`, không tạo thêm dòng. Cùng khóa khác nội dung:
   409 `IDEMPOTENCY_CONFLICT`. Replay không tra lại trạng thái công khai/biến thể
   hay so với privacy version mới. Yêu cầu MỚI vẫn kiểm tra privacy hiện hành,
   consent và sản phẩm/biến thể công khai. Các guard cú pháp, giới hạn đầu vào,
   consent, throttle và maintenance/feature flag vẫn có hiệu lực.
   Khi timeout/mất response, giữ nguyên khóa + payload; không tự đổi khóa,
   privacy_version, thứ tự items hay gửi N request thay cho một yêu cầu.
   Với lần gửi trước chưa rõ kết quả, gặp 503/429 thì giữ trạng thái chưa xác định
   và thử lại theo điều kiện cho phép bằng cùng khóa; không suy ra server chưa
   lưu chỉ từ lỗi mạng.

4. **Mã yêu cầu và hàng hóa:** `data.request_id` là mã yêu cầu tư vấn;
   `meta.request_id` chỉ truy vết HTTP. `IN_STOCK` → Sẵn hàng; `PREORDER` → Đặt trước.
   Sản phẩm ACTIVE đã publish hết tồn vẫn hiển thị. `GET /categories` trả
   `data.items[].product_count`; số này không thay cho total của tìm kiếm đã lọc.
   Chế độ `GET /products?ids[]=...` cho Saved/Selection giữ nguyên items/missing_ids
   và thứ tự ID, KHÔNG có page_info, không ghép với filter/sort/cursor/limit.

## Triển khai và QA

BE triển khai đúng commit, làm mới config cache và regenerate OpenAPI theo pipeline.
Kiểm tra `/public/config` có hai giới hạn mới và contract_version = 1.2.0; nếu môi
trường đã đặt PUBLIC_API_CONTRACT_VERSION thì cập nhật biến này. Giữ hotline,
privacy URL/version và các cấu hình liên hệ đã được duyệt, không dùng số mẫu.

Chạy kiểm thử ở database dùng một lần, không chạy RefreshDatabase trên DB vận hành:

```sh
DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= APP_ENV=testing \
  php artisan test --filter='PublicStorefront(Api|Handoff)Test|HandToolsFamilyVariantPublicTest'
```

Ca cần nghiệm thu: 20 dòng thành công/21 dòng bị chặn; mất response rồi replay sau
khi ẩn sản phẩm/biến thể hoặc đổi privacy; cùng khóa khác payload trả 409; yêu cầu
mới vẫn bị từ chối khi không hợp lệ; total giữ đúng bộ lọc qua nhiều trang và trả
0 khi không có kết quả; Saved giữ đúng thứ tự/missing_ids; không lộ số tồn hoặc PII.
CI riêng chạy các suite này trên SQLite và PostgreSQL test. Kết quả CI và nghiệm
thu môi trường Testing phải được ghi riêng; mã đã push không đồng nghĩa đã deploy.

Scenario 7 model/SKU và ảnh chuẩn pixel-compare vẫn cần BA/Thiết kế/QA chốt riêng.
Không thay catalogue live bằng 7 dòng, không lấy test fixtures làm canonical design.
