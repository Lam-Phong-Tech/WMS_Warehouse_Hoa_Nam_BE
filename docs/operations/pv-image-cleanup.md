# Ảnh sản phẩm PV chưa được lưu

Chọn ảnh hoặc dán URL rồi bấm Thêm tạo tệp trong kho và bản ghi theo dõi
`pv_image_uploads`. Chỉ khi lưu sản phẩm thành công, ảnh mới được đánh dấu đã sử
dụng trong cùng giao dịch DB với `pv_product_images`. Lưu thất bại sẽ rollback
cả trạng thái này.

Ảnh tạm mặc định có hạn 24 giờ (`PV_IMAGE_PENDING_HOURS=24`). Lệnh
`pv-images:prune` được đăng ký chạy mỗi giờ qua Laravel scheduler. Không cần trình
duyệt còn mở: tắt tab, mất mạng hoặc bấm Hủy đều được xử lý bởi lịch dọn này.
Ảnh hết hạn được xóa ở lần chạy kế tiếp, thường trong khoảng 24–25 giờ sau tải lên.

Lệnh chỉ xem xét ảnh UUID JPG/JPEG/PNG/WEBP trong prefix PV và những ảnh đã theo
dõi. Trước khi xóa, lệnh kiểm tra cả `image_url` và `storage_url`, không lọc theo
trạng thái sản phẩm. Ảnh cũ chưa có metadata cũng được phát hiện. Tệp ngoài prefix
PV và tệp không đúng mẫu tên được bỏ qua. Ảnh đã lưu thành công được giữ lại;
chính sách xóa ảnh sau khi xóa sản phẩm nằm ngoài cơ chế dọn ảnh tạm này.

Lưu sản phẩm và dọn ảnh khóa cùng bản ghi theo dõi để tránh xóa trong lúc lưu.
Nếu form để quá lâu và ảnh đã bị dọn, API trả 422 `IMAGE_UPLOAD_EXPIRED`; người
dùng phải thêm lại ảnh. Lệnh kiểm tra tồn tại trước khi xóa và chỉ xóa metadata
sau khi xóa tệp thành công, nên có thể chạy lại sau lỗi kho lưu trữ.

## Triển khai và kiểm tra

Chạy migration mới trước khi phục vụ mã upload mới:

```sh
php artisan migrate --force
php artisan schedule:list
php artisan pv-images:prune --dry-run
```

Hạ tầng phải chạy Laravel scheduler (`schedule:run` mỗi phút hoặc scheduler
worker hiện có). Chỉ đăng ký lịch trong mã nguồn chưa kích hoạt lịch trên máy chủ.
`--dry-run` không xóa tệp hoặc ghi metadata. `--limit=500` là mặc định; nhận từ
1 đến 5000 ảnh mỗi lần chạy. Việc phát hiện ảnh cũ vẫn phải duyệt danh sách tệp
trong prefix, với truy vấn metadata theo từng nhóm 250 tệp.

Kiểm thử dùng SQLite trong bộ nhớ và `Storage::fake('s3')`; không chạy dọn dữ liệu
thật để xác minh bản sửa.
