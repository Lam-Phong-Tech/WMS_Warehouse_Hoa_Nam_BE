# WWHN-330 — Bộ comment xác nhận / sign-off (mỗi vai trò 1 comment duy nhất)

Dùng để dán thẳng lên Jira WWHN-330. Mỗi vai trò chỉ đăng **một** comment gộp đủ các
mục cần xác nhận, tránh comment nhiều lần.

> Trước khi đăng: thay `<SHA-BE>` / `<SHA-FE>` bằng SHA thật của commit chốt trên nhánh
> `m1` (kể cả commit vá residual-01/02). Không đăng khi chưa có SHA thật.

---

## 1) Tech Lead — 1 comment

**[Tech Lead] Xác nhận WWHN-330**

Tôi xác nhận 4 điểm sau, đã đọc code + evidence trên nhánh `m1`:

1. **Baseline đánh giá**: nguồn code chính thức của WWHN-330 là repo
   `WMS_Warehouse_Hoa_Nam_BE` / `WMS_Warehouse_Hoa_Nam_FE`, nhánh **`m1`**, SHA
   `<SHA-BE>` / `<SHA-FE>`. Nhánh `m1-be-2` là superseded (đã merge, nằm trong
   `LEGACY_SOURCES_EXCLUDED.txt` của gate WWHN-219) — **không** dùng làm nguồn review.
   Comment "BE2 code baseline for review" trỏ `m1-be-2` được thay thế bởi comment này.
2. **residual-01 (đọc file Excel)**: duyệt hướng đọc theo lô + đếm hạn mức theo dòng dữ
   liệu thật; `totalRows` chỉ dùng để dừng quét, không dùng để từ chối file (tránh
   dimension bloat). Phần vượt trần quét 20 040 dòng vật lý báo lỗi tường minh, không
   cắt âm thầm.
3. **residual-02 (idempotency)**: duyệt hướng khoá ứng dụng `Cache::lock` theo
   `(actor_id, sha256)`, **không** thêm unique index DB (vì unique index chặn vĩnh viễn,
   phá ngữ nghĩa cửa sổ 24h).
4. **Không tạo nhánh riêng**: toàn bộ thay đổi WWHN-330/331 nằm trên `m1`.

Không phát sinh việc mới từ phía Tech Lead.

---

## 2) BA — 1 comment

**[BA] Xác nhận business rule WWHN-330**

Tôi xác nhận 3 tham số nghiệp vụ đang để "TẠM" trong code, nay chốt chính thức:

1. **Giới hạn import**: `MAX_ROWS = 1000` dòng dữ liệu/lần import — giữ nguyên, đúng
   như hành vi hiện hành, chưa cần nâng.
2. **Cửa sổ idempotent**: 24 giờ, phạm vi **theo từng actor** (không theo toàn hệ
   thống) — cùng người nộp lại đúng file trong 24h thì trả kết quả cũ, không ghi lại.
   Hai người khác nhau nộp cùng file vẫn là 2 lần import độc lập.
3. **Hạn tải file lỗi CSV**: 24 giờ kể từ khi sinh; hết hạn thì file bị dọn và API trả
   404 — người dùng chạy lại dry-run để có báo cáo lỗi mới.

Không yêu cầu thay đổi thêm về nghiệp vụ.

---

## 3) PM — 1 comment

**[PM] Xác nhận phân công & trạng thái WWHN-330**

Tôi xác nhận:

1. **Owner**: theo comment "CẬP NHẬT PHÂN CÔNG FE/BE" trên WWHN-89, quyền sở hữu hợp
   nhất thuộc **Quang Nguyễn Thanh**. Phần Description của WWHN-330 còn ghi "BE2 — Duy
   Khánh Nguyễn" là tồn đọng, lấy comment này làm căn cứ (đóng DEV330-P2-01).
2. **Phạm vi**: DEV330-P1-01 → P1-05 và P2-01 đã có evidence; residual-01/02 đã đóng.
3. **Còn phụ thuộc**: WWHN-332 (QA1) chưa chạy độc lập — không chặn sign-off của DEV
   nhưng chặn chuyển Done toàn ticket.

Không phát sinh việc mới từ phía PM.

---

## 4) QA1 — 1 comment

**[QA1] Xác nhận phạm vi kiểm thử WWHN-330 / WWHN-332**

Tôi xác nhận đã đọc evidence và **đồng ý phạm vi test độc lập** sẽ chạy trên đúng SHA
`<SHA-BE>` / `<SHA-FE>` nhánh `m1`, gồm: file lỗi CSV (nội dung + RBAC `sku.manage` +
404 khi hết hạn), giới hạn 1000 dòng (422 `SKU_IMPORT_ROW_LIMIT_EXCEEDED`, không 500),
file nhiều dòng trống xen giữa (không mất dữ liệu), import lặp cùng file (duplicate,
không ghi 2 lần), và dry-run không ghi dữ liệu.

Tôi chưa ký nghiệm thu kết quả — WWHN-332 giữ nguyên trạng thái đang chờ chạy.

---

## 5) DEV (BE2/FE1) — 1 comment

**[DEV] Xác nhận bàn giao WWHN-330 / WWHN-331**

Tôi xác nhận đã hoàn thành và tự kiểm chứng trên nhánh `m1`:

- P1-01 file lỗi CSV thật + endpoint tải + job dọn file quá hạn; P1-02 giới hạn dòng có
  error code chuẩn, không nạp toàn bộ workbook; P1-03 idempotent theo sha256 + actor;
  P1-04 baseline `m1`; P1-05 UI import SKU đầy đủ (upload → dry-run → sửa lỗi → import →
  tải file lỗi), RBAC `sku.manage`.
- 2 residual tự phát hiện đã đóng: không cắt âm thầm dòng dữ liệu; import COMMIT được
  tuần tự hoá theo `(actor, sha256)`.
- Test: BE `Wwhn330ImportResidualHardeningTest` 4/4, regression import 29/29;
  FE `master-data` pass; `master-sku:verify` PASS.

Bàn giao cho QA1 (WWHN-332) kiểm thử độc lập.
