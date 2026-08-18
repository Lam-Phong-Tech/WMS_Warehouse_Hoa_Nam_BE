# WWHN-299 — Comment xác nhận theo vai trò

Mỗi vai trò **một comment duy nhất**, dán nguyên văn vào `WWHN-299`. Đây là xác nhận
đã đọc và duyệt phần thuộc thẩm quyền của mình — **không phải** cam kết làm việc mới.
Thứ tự khuyến nghị: Tech Lead → BA → Data Owner → QA1 → DevOps → PM/PO → FE1.

Quy ước: `<BE_SHA>` = exact SHA của commit candidate backend trên `m1` (xem
`docs/evidence/WWHN-299/WWHN-299_SOURCE_PIN.json`).

---

## 1. Tech Lead

```
[Tech Lead sign-off — WWHN-299 @ <BE_SHA>]

Tôi xác nhận đã đọc và duyệt về mặt kỹ thuật:

1. DEV299-P1-01 — thiết kế rollback master có khả năng đảo ngược: snapshot before/after
   ghi vào audit_log (ERD bảng 7) theo request_id = migration_run_id, fail-closed trong
   cùng transaction COMMIT; không thêm bảng nên ERD vẫn đúng 59 bảng canonical.
2. Diễn giải "no subset master remains" (SRS §13.12) trong ràng buộc ledger append-only
   (DEC-REV-001): master do run tạo mà bị OPENING/REVERSAL giữ khoá ngoại thì KHÔNG xoá
   cứng được, và được disposition về INACTIVE/CLOSED/ADJUSTED_OUT với reason
   LEDGER_PINNED. Tôi chấp nhận đây là hành vi đúng, không phải lách yêu cầu.
3. DEV299-P1-03 — công thức source_checksum mới đã phủ manifest đã chuẩn hoá.
4. DEV299-P1-04 — nguồn canonical của WWHN-299 là nhánh m1, không phải m1-be-2.

Tôi KHÔNG xác nhận: kết quả QA, và việc đã nạp dữ liệu production.
```

---

## 2. BA

```
[BA sign-off — WWHN-299 @ <BE_SHA>]

Tôi xác nhận đã đọc và duyệt về mặt nghiệp vụ:

1. DEV299-P1-02 — "Data Owner" của opening balance được hiện thực đúng baseline: quyền
   duyệt = Admin/QL kho (warehouse.manage + inventory.view theo ma trận SRS §3), khớp
   SRS §13.12 "QL kho signs result" và ERD bảng 58 approved_by.
2. Separation of duty (người chạy cutover không tự ký duyệt) và bắt buộc --approval-ref
   là đúng ý đồ nghiệp vụ, không phải ràng buộc tự đặt thêm.
3. Import Package v1.2 vẫn là bản duy nhất được duyệt; đổi manifest = đổi nguồn = phải
   chạy lại dry-run.

Tôi KHÔNG xác nhận: DEV299-P1-06 — lần nạp dữ liệu production chưa diễn ra, WWHN-80 vẫn
là gate mở.
```

---

## 3. Data Owner / QL kho

```
[Data Owner sign-off — WWHN-299 @ <BE_SHA>]

Tôi xác nhận đã đọc và duyệt:

1. Hợp đồng ký duyệt: chỉ tài khoản Data Owner mới mở được COMMIT, và mỗi lần COMMIT
   phải gắn mã quyết định/biên bản ký duyệt opening balance qua --approval-ref (lưu vào
   migration_runs.note).
2. Hành vi rollback với dữ liệu của tôi: master có trước cutover bị package ghi đè sẽ
   được khôi phục đúng giá trị cũ; master do cutover tạo ra sẽ bị xoá, hoặc nếu bị ledger
   giữ thì bị đưa về trạng thái đóng và báo rõ trong evidence.

Tôi KHÔNG xác nhận: số liệu tồn đầu kỳ production — sẽ ký riêng trên Import Package v1.2
thật tại thời điểm cutover.
```

---

## 4. QA1

```
[QA1 sign-off — WWHN-299 @ <BE_SHA>]

Tôi xác nhận đã đọc và duyệt phạm vi kiểm thử:

1. DEV299-P1-01/02/03 đều có test negative chạy được, không phải tuyên bố:
   - rollback: xoá master do run tạo, disposition master bị ledger giữ, khôi phục master
     bị ghi đè, tồn dư = 0;
   - thẩm quyền: approver không phải Data Owner / approver trùng operator / thiếu
     --approval-ref / operator thiếu quyền đều bị chặn trước khi tạo run;
   - checksum: đổi metadata manifest chặn COMMIT, format lại manifest thì không.
2. Bộ MigrationFinalDataLoadTest chạy trên cả sqlite và PostgreSQL 16.
3. DEV299-P1-05 — evidence lấy được theo exact SHA (build/evidence/wwhn-299/ + MANIFEST.json
   có sha256), tái lập bằng Wwhn299CutoverRehearsalEvidenceTest.

Tôi KHÔNG xác nhận: QA PASS cho lần cutover thật (DEV299-P1-06) — cần chạy trên UAT/PROD
sau freeze + backup.
```

---

## 5. DevOps

```
[DevOps sign-off — WWHN-299 @ <BE_SHA>]

Tôi xác nhận đã đọc và duyệt:

1. Runbook docs/migration/FINAL_DATA_LOAD_RUNBOOK.md — lệnh, cổng quyền, tiêu chí PASS,
   thứ tự chạy trong cutover window và bộ evidence phải nộp cho HN-DEVOPS-005.
2. Workflow .github/workflows/wwhn-299-final-data-load-gate.yml chạy lại đủ ba pha trên
   PostgreSQL 16 và upload artifact evidence theo exact SHA.
3. Exit code 0/1 của job là tín hiệu gate hợp lệ cho pipeline cutover.

Tôi KHÔNG xác nhận: đã thực thi cutover production — WWHN-298 vẫn Blocked, cần freeze +
backup + rollback rehearsal trên môi trường thật.
```

---

## 6. PM/PO

```
[PM/PO sign-off — WWHN-299 @ <BE_SHA>]

Tôi xác nhận:

1. DEV299-P1-04 — chính thức supersede pin nguồn: nhánh canonical của WWHN-299 là m1 tại
   <BE_SHA>; m1-be-2 chỉ là ancestor lịch sử. Mọi đường dẫn evidence phải được resolve
   theo pin này (đây là lý do các link trước đó 404).
2. DEV299-P2-01 — quyền sở hữu: phần lịch sử do BE2 (Duy Khánh) khởi tạo, phần hiện tại
   do DEV hợp nhất (Quang) thực hiện; assignee live là nguồn đúng.
3. DEV299-P1-06 vẫn là gate mở của WWHN-80 và không được coi là đã đóng bởi ticket này.
```

---

## 7. FE1

```
[FE1 sign-off — WWHN-299 @ <BE_SHA>]

Comment xác nhận canonical duy nhất của FE cho WWHN-299 (thay cho các confirmation trùng
lặp trước đó — DEV299-P2-02):

Tôi xác nhận WWHN-299 không thay đổi contract FE: không thêm/sửa endpoint /api/v1, không
đổi payload/enum/error code, OpenAPI và Postman giữ nguyên. Repo FE không có tham chiếu
nào tới migration_run / final-load / opening_balance. FE không phải adapt gì.
```
