# 6. Dòng bị loại và cleansing log — DEV260-P1-11

## 6.1 Đầu vào cho `WWHN-263`

Cleansing log không thể lập nếu dòng bị loại không có **lý do**, **chủ** và **cách xử lý**.
Ba thứ đó nay nằm trong một artifact duy nhất:

```bash
php artisan migration:verify-run --run-code=<code> --mode=DRY_RUN \
    --package=<pkg> --error-file=<evidence>/rejected.csv
```

Mẫu thật: `docs/evidence/WWHN-260/09-rejected-rows.csv`.

## 6.2 Bảng cleansing log (mẫu điền)

| source_file | sheet | row_no | error_code | Lý do | data_owner | Remediation | Người xử lý | Ngày | Kết quả |
|---|---|---|---|---|---|---|---|---|---|
| agency.csv | AGENCY | 3 | REQUIRED | Thiếu `agency_name` | BAS / Kinh doanh | Bổ sung tên đại lý vào file nguồn | | | |
| agency.csv | AGENCY | 3 | INVALID_ENUM | `status` ngoài ACTIVE/INACTIVE | BAS / Kinh doanh | Sửa về enum baseline | | | |
| agency.csv | AGENCY | 4 | DUPLICATE_CODE | Trùng `agency_code` với dòng 2 | BAS / Kinh doanh | Hợp nhất hoặc loại dòng trùng | | | |
| sku.csv | SKU | 4 | INVALID_ENUM | `sku_type = GADGET` | BAS / Danh mục sản phẩm | Sửa về PRODUCT/COMPONENT | | | |
| opening_balance.csv | OPENING_BALANCE | 4 | UNKNOWN_FK | Kho không tồn tại | QL kho | Sửa mã kho hoặc tạo kho trước | | | |
| opening_balance.csv | OPENING_BALANCE | — | RECON_MISMATCH | Lượng serial-tracked ≠ số Item | QL kho | Đối chiếu lại kiểm kê với danh sách Item | | | |

Bốn cột cuối do chủ dữ liệu điền khi xử lý; file CSV sinh ra đã có sẵn sáu cột đầu.

## 6.3 Quy tắc bắt buộc khi xử lý

1. **Không sửa trực tiếp trong DB.** Sửa ở file nguồn, tăng phiên bản package, chạy lại
   dry-run với **run code mới** — file đổi thì checksum đổi, đó là thiết kế.
2. **Không "bỏ qua" dòng CRITICAL.** Muốn loại một dòng khỏi phạm vi nạp thì phải xoá nó khỏi
   package và ghi lý do vào cleansing log, để số dòng input phản ánh đúng thực tế.
3. **Không lưu PII thô** trong cleansing log: cột `raw_value` đã được mask sẵn, giữ nguyên.
4. Mỗi dòng đã xử lý phải kết thúc bằng một trong ba trạng thái: `FIXED` (đã sửa nguồn),
   `EXCLUDED` (loại khỏi phạm vi, có lý do), `WAIVED` (chấp nhận rủi ro, cần nội dung waiver
   tối thiểu theo SRS §13.14).
