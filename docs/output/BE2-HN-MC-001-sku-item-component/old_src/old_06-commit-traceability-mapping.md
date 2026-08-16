# Ánh Xạ Git Commit và Traceability (BE2-P2-01)

## Danh sách Ánh xạ Commit sang Acceptance Criteria (WWHN-37 / WWHN-171)

Để đảm bảo tính minh bạch và khả năng kiểm toán mã nguồn (code traceability), lịch sử commit trên nhánh `m1-be-2` đã được chuẩn hóa theo mã task **`WWHN-171`** (conventional commit format). 

Dưới đây là bảng đối chiếu chi tiết giữa các commit kỹ thuật và các mục tiêu nghiệp vụ tương ứng:

| Mã Commit (SHA) | Nội dung thay đổi kỹ thuật | Ánh xạ mục tiêu gỡ cờ / Ý nghĩa nghiệp vụ |
|---|---|---|
| **`f35327c`** | fix(WWHN-171): replace products constraint references with sku table in extension tables migration | Sửa đổi ràng buộc khóa ngoại chỉ định tới `products` cũ trong các migration sang `sku` để giúp Docker Desktop (PostgreSQL) chạy migrations trơn tru. |
| **`f55b477`** | clean(WWHN-171): remove duplicate developer guide file | Xóa bỏ tệp tài liệu hướng dẫn phát triển trùng lặp để tinh gọn tài nguyên lưu trữ. |
| **`53af2ee`** | clean(WWHN-171): untrack local npm-cache and psysh cache directories | Bỏ theo dõi (untrack) các thư mục cache của npm và psysh ra khỏi Git repository. |
| **`0033643`** | clean(WWHN-171): remove duplicate BA specification folder | Dọn dẹp thư mục tài liệu nghiệp vụ BA hợp nhất trùng lặp khỏi repository. |
| **`b65fd75`** | docs(WWHN-171): update summary, branch sync status, contract and stage remaining Sku compatibility fixes | Viết tài liệu evidence gỡ cờ, cập nhật API contract và chốt chặn kích thước, mã băm SHA-256. |
| **`4c64a20`** | fix(WWHN-171): resolve SKU/Product compatibility and fix all test cases to pass | Triển khai virtual attributes trên model `Sku`, sửa đăng nhập bằng cả username/email, sửa seeder `product_masters` giúp 100% test cases pass. |
| **`eb4469d`** | fix(WWHN-171): merge origin/m1 and resolve schema alignment | Thực hiện gộp nhánh chính `origin/m1` và căn chỉnh schema các bảng danh mục số nhiều (plural). |
| **`bc0b672`** | fix(WWHN-171): mo rong chuoi trace item va to chuc tai lieu minh chung go co SKU/item backend | Phát triển endpoint `/trace` lai liên kết item thực tế tới stock movements và stock documents. |
| **`13eb33c`** | fix(WWHN-36,WWHN-37): guard single-active warehouse, extend item trace, safe sku migration | Triển khai các logic lõi của SKU/item backend, thiết kế các lớp kiểm tra và migration guard an toàn. |

Lịch sử git commit này đã thể hiện rõ ràng tiến trình làm việc khoa học, đúng quy trình và khả năng liên kết tự động trực tiếp với các Acceptance Criteria của dự án.
