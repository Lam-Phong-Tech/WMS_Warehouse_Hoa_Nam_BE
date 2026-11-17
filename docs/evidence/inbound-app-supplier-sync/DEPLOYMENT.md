# Kết quả triển khai đồng bộ NCC app/web

Hoàn tất ngày 17/11/2026 lúc 22:01 (Asia/Saigon), trên hệ thống DEV đang phục vụ `khohoanamfe.lptech.info.vn`.

## Kết quả

- Backend đang chạy: `589f826224c776c5b14fe18f9dfb561e9dcf5747`.
- [Pipeline kiểm thử và triển khai](https://github.com/Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE/actions/runs/44108954288): thành công cả SQLite, PostgreSQL và deploy.
- Đã bổ sung NCC vào phiếu chính cho **25 phiếu app cũ**; chạy đối soát lại còn **0 phiếu đủ điều kiện chưa xử lý**.
- Phiếu app mới ghi thêm NCC vào phiếu chính trong cùng giao dịch ghi nhận; metadata/snapshot và hợp đồng API app được giữ nguyên.
- Web có cách đọc bổ sung từ metadata cho phiếu cũ thiếu NCC trên header; ưu tiên header đã có NCC.

Các mẫu đã kiểm tra bằng controller đọc thực tế của phiên bản đang chạy, ở cả danh sách và chi tiết:

| Phiếu | NCC trả về |
|---|---|
| PN-20261117072446 | NCC-007 — Công ty Cơ Khí Hoa Nam Test |
| PN-20261117001922 | NCC-001 — Honda Việt Nam |
| PN-20261021-0001 (POSTED) | NCC-001 — Honda Việt Nam |

Đây là kiểm chứng phản hồi backend thực tế, không phải kiểm thử thao tác giao diện app. Không tạo phiếu thử hoặc đăng nhập thay người dùng trên dữ liệu đang chạy.

## Bảo toàn dữ liệu

Checksum báo cáo trước/sau đã được kiểm tra với tệp kết quả commit. Dấu vân tay trước/sau giống hệt đối với:

- Tất cả trường khác NCC trên các phiếu có metadata app, gồm trạng thái, phiên bản, ngày giờ và người thao tác.
- Metadata/snapshot app, dòng hàng, stock_movements, inventory_balances.
- Toàn bộ bảng đối chiếu inbound_documents.

Không ghi đè NCC của bất kỳ phiếu không đủ điều kiện nào. Phiếu hủy `PN-20260925002826-NFIC` vẫn chưa được gán NCC vì không có dữ liệu chứng minh. Có 2 bản ghi metadata app không chọn NCC; giữ nguyên.

Ba phiếu có NCC header khác metadata app được giữ nguyên theo quy tắc ưu tiên đã chốt:

| Phiếu | NCC trên phiếu/web được giữ | NCC trong metadata app |
|---|---|---|
| PN-20261112170334 | NCC-007 | NCC-002 |
| PN-20261112232325 | NCC-007 | NCC-008 |
| PN-20261112165444 | NCC-007 | NCC-008 |

Nếu cần thay đổi ba phiếu này, cần xác định nguồn NCC đúng cho từng phiếu; đợt đồng bộ này không tự suy đoán hoặc ghi đè.

## Kiểm thử và bằng chứng

- Bản hoàn chỉnh: 78 ca SQLite / 1.004 assertions / 2 ca dành riêng PostgreSQL được skip; 70 ca PostgreSQL / 959 assertions đạt.
- Đã khôi phục backup vào PostgreSQL riêng, chạy thử đúng 25 phiếu thực và đối chiếu không đổi dữ liệu khác trước khi apply trên DEV.
- Bộ kiểm thử toàn dự án có lỗi ngoài phạm vi; không coi là xanh. Chi tiết tại [VALIDATION.md](VALIDATION.md) và [full-suite-summary.json](full-suite-summary.json).
- Phản hồi và kiểm chứng runtime: [runtime-verification.json](runtime-verification.json).
- Đã dọn 18 container kiểm thử cùng volume PostgreSQL riêng chứa bản sao dữ liệu. Backup mã hóa và báo cáo đối soát riêng tư được giữ lại trên VPS.

Thư mục vận hành trên VPS: `/opt/wms-hoanam/operations/inbound-app-supplier-sync-20261117`. Báo cáo đã commit ở shared storage: `storage/app/private/operations/inbound-app-supplier-sync-20261117/apply-v2.json` và `.result.json`. Lần đầu rollback được giữ riêng để đối chiếu, không ghi đè.
