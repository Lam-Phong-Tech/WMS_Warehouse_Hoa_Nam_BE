# DEC-MIG-SRC-001 — Nguồn nhánh của migration/cutover

| | |
|---|---|
| **Mã quyết định** | `DEC-MIG-SRC-001` |
| **Ngày** | 18/08/2026 |
| **Trạng thái** | PROPOSED — chờ PM/Tech Lead xác nhận tại `WWHN-260` |
| **Ticket** | `WWHN-260` (DEV260-P1-07, DEV260-P1-08) |
| **Liên quan** | `WWHN-290` (đối chiếu nhánh), `WWHN-299` (job), `WWHN-261`/`WWHN-298` (thực thi cutover) |
| **Người đề xuất** | BE |
| **Người duyệt** | PM / Tech Lead |

## Bối cảnh

Register của `WWHN-260` ghi hai điểm không truy vết được:

1. Không có PR/SHA/build nào gắn với `WWHN-260`; HEAD quan sát được lúc review thuộc `WWHN-151`.
2. Tồn tại hai tham chiếu nhánh trong lịch sử ticket — `m1-be-2` và `m1` — mà chưa có quyết
   định chính thức nhánh nào là nguồn của migration/release.

Hệ quả: không ai chứng minh được "cái đã review chính là cái sẽ chạy khi cutover", dù chức
năng có đúng đến đâu.

## Quyết định

1. **`m1` là nhánh nguồn duy nhất** của migration/cutover cho cả `WMS_Warehouse_Hoa_Nam_BE` và
   `WMS_Warehouse_Hoa_Nam_FE`. Không tạo nhánh riêng cho ticket migration.
2. **`m1-be-2` được coi là superseded.** `WWHN-290` đã kiểm và ghi nhận `m1-be-2` là tổ tiên
   thuần của `m1`, nghĩa là không mang commit nào mà `m1` thiếu. Mọi tham chiếu `m1-be-2`
   trong ticket cũ đọc là "nội dung đã nằm trong `m1`".
3. **Evidence phải pin full SHA, không pin tên nhánh.** `m1` có nhiều phiên làm việc song song
   nên "HEAD của m1" không phải bằng chứng ổn định. Mỗi gói bàn giao ghi `implementation_sha`
   trong `MANIFEST.json` và trong comment Jira; reviewer checkout đúng SHA đó để tái lập.
4. **Ticket cutover chỉ chấp nhận evidence sinh tại đúng SHA đã pin.** Evidence sinh ở SHA
   khác phải chạy lại, không được suy diễn tương đương.

## Lý do

- Nhánh là con trỏ di động; SHA là bất biến. Với một hoạt động chỉ chạy một lần và ghi thẳng
  vào tồn kho, bằng chứng phải neo vào cái bất biến.
- Giữ một nhánh nguồn tránh tình huống tệ nhất của cutover: chạy job của nhánh này trên schema
  của nhánh kia.
- Không thêm nhánh mới cho migration vì migration phụ thuộc toàn bộ schema/seed của release;
  tách nhánh sẽ tạo ra một bản schema thứ hai cần đồng bộ.

## Hệ quả

- `WWHN-261` (UAT/ops) và `WWHN-262` (QA độc lập) phải ghi rõ SHA họ đã chạy.
- Nếu sau này thực sự cần một nhánh release riêng, phải có Change Decision mới thay thế bản này.

## Chứng cứ liên quan

- `docs/output/BE-HN-MIG-001-migration-pack/MANIFEST.json` — `implementation_sha`
- `docs/evidence/WWHN-260/EVIDENCE_PACK.md` §1 — lệnh tái lập
