# Trạng thái lệch nhánh `m1-be-2` vs `m1` (BE2-P1-03)

Số liệu và phân tích rủi ro cho flag này **đã được viết chi tiết** ở lượt gỡ cờ trước (task WWHN-36/HN-FND-002, cùng nhánh `m1-be-2`) tại:

```
docs/output/BE2-HN-FND-002-master-danh-muc/01-branch-sync-risk-report.md
```

Không lặp lại toàn bộ phân tích ở đây — chỉ xác nhận tính hiện hành:

- Số liệu `git log origin/m1-be-2..origin/m1` (24 commit) / `git log origin/m1..origin/m1-be-2` (2 commit) không đổi kể từ báo cáo trước, vì chưa có hành động merge/rebase nào được thực hiện trên nhánh.
- Kết luận không đổi: đây là **xung đột thiết kế schema** (tên bảng số ít/số nhiều khác nhau cho cùng entity: `agency`/`agencies`, `product_master`/`product_masters`, `physical_code`/`physical_codes`, `container`/`containers`, có/không bảng `item_instance` riêng), không phải chỉ lệch commit — `git merge` cơ học sẽ không báo conflict nhưng để tồn tại song song 2 schema, rủi ro cao cho toàn bộ dữ liệu SKU/item/container/import_batch mà WWHN-37 đang xây dựng trên `m1-be-2`.
- **Không tự động merge/rebase** trong lượt gỡ cờ này — giữ nguyên quyết định đã thống nhất trước đó.

Xem file gốc để có bảng so sánh schema chi tiết theo từng entity.
