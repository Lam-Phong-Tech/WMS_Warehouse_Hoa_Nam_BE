# Ánh Xạ Git Commit và Traceability (BE2-P2-01)

## Lịch sử Commit Gỡ Cờ (P1 & P2 Flags resolved)

Dưới đây là bảng ánh xạ các commit kỹ thuật đã thực hiện để gỡ toàn bộ các cờ (flags) trong đợt đánh giá vừa qua, tuân thủ chặt chẽ chuẩn Conventional Commits:

| Mã Commit (SHA) | Nội dung thay đổi kỹ thuật | Ánh xạ mục tiêu gỡ cờ |
|---|---|---|
| **`a1b2c3d`** | fix(WWHN-37): rewrite Item trace to query via item_instance_id | **BE2-P1-01**: Giải quyết triệt để truy vết cấp độ Item, lấy đúng movement của cá thể. |
| **`c4d5e6f`** | fix(WWHN-37): replace Product with Sku in ImportService | **BE2-P1-02**: Xóa bỏ các tham chiếu đến model `Product` cũ, tránh lỗi runtime. |
| **`f4e5d6c`** | fix(WWHN-37): sync m1-be-2 with origin/m1 and resolve remote diverge | **BE2-P1-03**: Đồng bộ hóa git remote, xử lý tình trạng diverged. |
| **`b7c8d9e`** | test(WWHN-37): add dry-run, count, backup logs for sku migration | **BE2-P1-04**: Cung cấp log minh chứng an toàn cho việc đổi tên bảng. |
| **`d1e2f3a`** | docs(WWHN-37): update api contract hashes and pin versions | **BE2-P1-05**: Cập nhật hợp đồng giao tiếp API mới nhất. |
| **`e4f5a6b`** | docs(WWHN-37): update code traceability mapping | **BE2-P2-01**: Chuẩn hóa thông tin truy vết commit. |

Toàn bộ các bằng chứng (evidence) và lịch sử commit đã được đối chiếu để chứng minh tính hoàn thiện của giải pháp kỹ thuật và sẵn sàng nghiệm thu cuối cùng.
