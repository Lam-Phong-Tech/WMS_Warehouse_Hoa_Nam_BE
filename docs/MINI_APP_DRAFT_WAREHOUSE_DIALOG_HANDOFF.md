# Mini App — Phiếu dở, kho tạm dừng và dialog

## 1. Phiếu dở nhập/xuất

- Phiếu đã tạo được lưu trên BE. ID authoritative là `stock_documents.id`; API Mini App list/detail trả field `id`. Riêng API tạo phiếu xuất trực tiếp trả `document_id`.
- Phiếu mới có `status=DRAFT`. Hạn nháp được tính cố định bằng `created_at + 07 ngày`; BE không tạo thêm cột deadline và không tự xoá dữ liệu.
- List/detail trả: `draft.expires_at`, `draft.is_expired`, `draft.retention_days=7`, `can_continue`, `continue_from`.
- `continue_from`: nhập kho là `SCAN` hoặc `REVIEW`; xuất kho dùng semantic status hiện tại, tối thiểu `PICKING` hoặc `READY_TO_ISSUE`; phiếu terminal, nháp hết hạn hoặc kho tạm dừng trả `null`.
- Quy tắc 07 ngày chỉ áp dụng khi document còn đúng trạng thái `DRAFT`. Sau scan đầu tiên, state machine canonical chuyển sang `SCANNING`; không đổi state machine chỉ để phục vụ UI.
- Nháp hết hạn vẫn đọc được. Thao tác tiếp tục trên DRAFT hết hạn trả `409 DRAFT_EXPIRED`. Không tự chuyển CANCELLED.
- Hủy: `POST .../{id}/cancel` + `If-Match` + `reason`. Chỉ DRAFT/SCANNING. Kết quả là `CANCELLED`, stock-neutral, **không xóa phiếu/scan evidence/audit**. Với phiếu xuất, toa nguồn có thể được mở lại nếu không còn phiếu active/POSTED khác.

## 2. Kho tạm dừng

- `warehouse.status=INACTIVE` nghĩa là tạm dừng nghiệp vụ ghi. Read/Search/Report/Audit vẫn được phép theo contract hiện tại.
- Mini App list/detail trả `warehouse.status`, `warehouse.is_suspended`, `warehouse.suspension_reason`.
- Request ghi khi kho tạm dừng trả `409 WAREHOUSE_INACTIVE`; `error.details` có `warehouse_id`, `warehouse_status`, `is_suspended=true`, `suspension_reason`.
- `suspension_reason` là dữ liệu cấu hình thật, nullable. Nếu null, FE hiển thị thông báo chung; không tự bịa lý do.
- “Liên hệ quản trị” chỉ là nội dung hướng dẫn. **Không mở chat, gọi điện, deep link hay tạo ticket trong hệ thống.**

## 3. Khi Post nhưng client chưa biết kết quả

Không tạo state “UNKNOWN” trong WMS.

1. Trong lúc request Post đang chạy: khóa nút submit và không gửi request thứ hai.
2. Nếu timeout/mất response: gọi GET detail của đúng `document_id`.
3. Nếu đã `POSTED`: coi là thành công.
4. Nếu vẫn DRAFT/SCANNING và cần retry: giữ **nguyên Idempotency-Key của lần Post**, lấy ETag/version mới nhất rồi retry với `If-Match` phù hợp.
5. Không tự Cancel và không tạo phiếu mới chỉ vì client không nhận được response.

## 4. Dialog / Back / Escape

- Dùng **bottom sheet hiện tại của Mini App**, không tạo thêm kiểu dialog giữa màn chỉ cho các case này.
- Không thêm motion tùy biến. Dùng motion mặc định của component hiện có; nếu component không có motion sẵn thì render tĩnh.
- Info/confirm bình thường: cho đóng bằng nút Đóng, Back/Escape/chạm nền. Đóng confirm hủy chỉ có nghĩa “không hủy”.
- Khi request mutation đang in-flight: disable nút hành động lặp và tạm khóa Back/Escape/chạm nền của sheet đó để tránh double-submit.
- Khi request đã timeout/failed: mở lại khả năng đóng; nếu kết quả Post chưa rõ thì dùng quy tắc mục 3.
- Ngoài thời gian request đang in-flight, người dùng được rời màn nghiệp vụ. Phiếu đã tạo vẫn nằm trên BE để mở lại bằng ID.
- Sau khi dialog đóng, accessibility focus quay về control đã mở dialog; sau thành công thì focus/navigate theo màn đích.
