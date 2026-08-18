# WWHN-260 — Bộ comment xác nhận (sign-off) để dán lên Jira

Nguyên tắc: **mỗi vai trò một comment duy nhất**, nội dung là *đọc + duyệt*, không phát sinh việc
mới. Chi tiết kỹ thuật ở `docs/docs_task_jira/WWHN-260.md`,
`docs/output/BE-HN-MIG-001-migration-pack/` và `docs/evidence/WWHN-260/`.

SHA đã pin: BE `b90d5ef67c6577db166fdac00f1323741ccfd888` · FE `e02d4185608e7bb661481968a902b71c81386362`
(nhánh `m1`). Danh mục tệp + digest ở `docs/output/BE-HN-MIG-001-migration-pack/MANIFEST.json`;
lưu ý truy vết về việc commit BE mang nhiều ticket ở phần 7.5 của Migration Pack.

---

## 1. DEV / Fullstack — comment của bạn (người thực hiện)

> ✅ **WWHN-260 — Migration Pack v1.0 đã phát hành, 11 mục P1 + 1 mục P2 đã có bằng chứng**
>
> Đã rà soát theo SRS/FRD v1.12 §13.12–§13.14, `REQ-SRS-021/034`, `NFR-SRS-017`,
> `AC-GWT-MIG-001`, `DEC-MIG-API-001`, ERD `HN-WMS-ERD-DD-001` (bảng 58/59/19/20/22/43/44) và
> Wireframe ALIGNED v3.20.
>
> Đóng tại nguồn 8 khoảng trống đo được, không phải sửa tài liệu:
> (1) bất biến `valid + invalid + duplicate = input row count` trước đây **không kiểm được** vì
> dòng trùng bị gộp vào `error_rows` → tách nhóm + thêm `migration_runs.duplicate_rows`
> (`ERD-AMD-007`); (2) tiêu chí "container mapping creates no extra stock" **chưa có ai kiểm** →
> 2 check chạy trong transaction COMMIT; (3) chạy lại **cùng package** dưới run code khác sẽ cộng
> tồn đầu **lần thứ hai** → khoá theo `source_checksum` (`PACKAGE_ALREADY_COMMITTED`), tự mở sau
> rollback; (4) chưa có **error file** bàn giao được → sinh đúng field spec §13.12 + cột
> `data_owner`, PII đã mask; (5) chưa có đường **xác minh độc lập** → lệnh `migration:verify-run`
> dựng lại kết luận từ DB và băm lại package; (6) cột **brand/category/model** của dataset SKU bị
> bỏ khi ghi → map sang FK master, khai mà không giải được thì loại dòng; (7) `duration_ms` luôn
> bằng 0 vì suy từ cột giây → đo bằng đồng hồ đơn điệu; (8) tra cứu O(số dòng) → cache theo run,
> đo thật **30.000 dòng trong 353 ms** (`NFR-SRS-017` yêu cầu ≥10.000 dòng/file).
>
> Bàn giao: `docs/output/BE-HN-MIG-001-migration-pack/` (9 phần + `MANIFEST.json` có SHA-256),
> `docs/evidence/WWHN-260/` (11 artifact chạy thật + 2 package diễn tập),
> `docs/migration/DATA_MAPPING_v1.0.md`, `docs/migration/CUTOVER_BACKUP_RESTORE_ROLLBACK.md`,
> `docs/decision-records/DEC-MIG-SRC-001`. Test khoá lại:
> `tests/Feature/Wwhn260MigrationPackClosureTest.php` (10/10) và
> `src/test/wwhn-260-migration-operational-only.test.ts` (4/4).
>
> Nhánh `m1` cho cả BE và FE, không tạo nhánh riêng. BE `b90d5ef67c6577db166fdac00f1323741ccfd888` · FE `e02d4185608e7bb661481968a902b71c81386362`.
>
> Không còn blocker thuộc vai trò DEV. Các gate còn lại cần chữ ký của BAS/Data Owner, QL kho,
> Tech Lead, PM, DevOps và QA2.

---

## 2. Tech Lead — xác nhận thiết kế kỹ thuật và nhánh nguồn

> ✅ **Tech Lead sign-off — WWHN-260**
>
> Tôi xác nhận đã đọc và duyệt:
> 1. **Diễn giải rollback append-only**: `stock_movements` không sửa/xoá (`DEC-REV-001`), nên
>    "no subset master/opening/movement remains" của §13.12 được chứng minh bằng (a) COMMIT nằm
>    trọn trong một transaction và (b) `residual_opening_qty = 0` sau REVERSAL đối ứng, master do
>    run tạo bị đóng và trace bằng `migration_run_id`. Đây là cách hiểu đúng của yêu cầu.
> 2. **`ERD-AMD-007`** — thêm `migration_runs.duplicate_rows integer NOT NULL DEFAULT 0`; additive,
>    có `down()` thật, không đổi business semantics.
> 3. **`DEC-MIG-SRC-001`** — `m1` là nhánh nguồn duy nhất của migration/cutover; `m1-be-2` là tổ
>    tiên thuần của `m1` nên được coi là superseded; evidence pin **full SHA**, không pin tên nhánh.
> 4. **Khoá chống nạp trùng theo `source_checksum`** và 2 check container mới là ràng buộc đúng,
>    không cần Change Request nghiệp vụ.
>
> Xác nhận không có thay đổi `/api/v1`/OpenAPI/error code (`DEC-MIG-API-001` giữ nguyên).

---

## 3. BAS / Data Owner — duyệt mapping và quyền xử lý dòng bị loại

> ✅ **BAS/Data Owner sign-off — mapping v1.0 và chủ dữ liệu**
>
> Tôi xác nhận đã đọc và duyệt `docs/migration/DATA_MAPPING_v1.0.md` (`HN-MIG-MAP-001`,
> `mapping_version = 1.0`) cùng bản máy đọc được `config/migration_mapping.php`:
> 1. Ánh xạ 6 dataset của Import Package v1.2 sang bảng đích, gồm bốn điểm đã làm rõ:
>    `version_code` là "version" của §13.12 (không phải cột optimistic locking);
>    brand/category/model là **mã master** phải giải được FK, khai mà sai thì **loại dòng**;
>    `target_legacy_key`/`legacy_item_key`/`carrier` không có cột đích là **quyết định**, không
>    phải bỏ sót; `source`/`owner_confirmation` đi vào audit của run.
> 2. **Chủ dữ liệu theo dataset**: AGENCY → BAS/Kinh doanh; SKU → BAS/Danh mục sản phẩm;
>    PHYSICAL_CODE → BE2/Quản trị mã vật lý; CONTAINER, ITEM, OPENING_BALANCE → QL kho.
> 3. Quy tắc xử lý dòng bị loại và ba trạng thái kết thúc `FIXED` / `EXCLUDED` / `WAIVED`
>    (`WWHN-263` dùng đúng bảng này làm cleansing log).
>
> Xác nhận đổi mapping ⇒ tăng `mapping_version` ⇒ phải chạy lại dry-run.

---

## 4. QL kho — xác nhận cách đối soát và ký duyệt tồn đầu kỳ

> ✅ **QL kho sign-off — quy trình đối soát tồn đầu kỳ**
>
> Tôi xác nhận đã đọc và duyệt cách đối soát tồn đầu kỳ trước khi cutover:
> 1. Chiều duy nhất của tồn đầu là `(kho, SKU, trạng thái tồn)`; trùng bộ ba bị loại.
> 2. Bốn phép đối soát phải PASS mới được COMMIT: lượng nguồn = ledger; balance = ledger; lượng
>    của SKU quản lý theo serial = số Item hợp lệ; **container không sinh thêm tồn**.
> 3. COMMIT chỉ chạy khi có chữ ký của tôi (`--approved-by` + `--approval-ref` là mã biên bản),
>    và người chạy job không được là người ký.
> 4. Sai lệch bất kỳ ⇒ toàn bộ lần nạp bị rollback, không có trạng thái "nạp một phần".
>
> Việc ký duyệt số liệu tồn đầu **production** sẽ thực hiện tại `WWHN-261` khi có Import Package
> production đã duyệt.

---

## 5. PM / PO — xác nhận phạm vi, nhánh nguồn và quyền sở hữu ticket

> ✅ **PM/PO sign-off — WWHN-260**
>
> Tôi xác nhận đã đọc và duyệt:
> 1. Phạm vi `WWHN-260` là **Migration Pack + bằng chứng năng lực**, không bao gồm việc nạp dữ
>    liệu production — việc đó thuộc `WWHN-261`/`WWHN-298` và cần package thật + chữ ký QL kho.
> 2. `DEC-MIG-SRC-001`: `m1` là nhánh nguồn duy nhất cho migration/cutover; `m1-be-2` superseded.
> 3. **DEV260-P2-01 — quyền sở hữu**: Duy Khánh là **owner lịch sử** ở giai đoạn lập kế
>    hoạch/đặc tả; **Nguyễn Thanh Quang là owner hiện hành** chịu trách nhiệm thực thi và bàn
>    giao. Mô tả cũ giữ nguyên như dấu vết lịch sử.
> 4. Ticket giữ trạng thái chờ sign-off của QA2 và DevOps trước khi được coi là hoàn tất.

---

## 6. DevOps — xác nhận runbook vận hành cutover

> ✅ **DevOps sign-off — runbook backup/restore/rollback**
>
> Tôi xác nhận đã đọc và duyệt `docs/migration/CUTOVER_BACKUP_RESTORE_ROLLBACK.md`
> (`HN-MIG-OPS-001`):
> 1. Sáu điều kiện vào, trong đó **backup chưa restore thử thì chưa tính là backup**; bản khôi
>    phục phải chạy được `php artisan migrate --force` và `php artisan wms:golive-check`.
> 2. Ba lệnh cutover theo thứ tự, với `migration:verify-run` là **cổng chặn** (exit ≠ 0 ⇒ coi như
>    cutover chưa thành công).
> 3. Ma trận quyết định rollback: COMMIT `FAILED` không cần rollback; `COMMITTED` nhưng verify FAIL
>    và chưa phát sinh nghiệp vụ ⇒ rollback bằng job; **đã có nghiệp vụ tiêu thụ tồn ⇒ khôi phục
>    từ backup**, không rollback bằng job.
> 4. Cùng một package chỉ được có tối đa một COMMIT còn hiệu lực; khoá mở lại sau rollback.
>
> Việc thực thi trên UAT và thu log thật sẽ ghi tại `WWHN-261`.

---

## 7. QA / QA2 — xác nhận đường xác minh độc lập

> ✅ **QA sign-off — đường xác minh độc lập của lần nạp dữ liệu cuối**
>
> Tôi xác nhận đã đọc và duyệt cách QA kiểm chứng mà **không dựa vào báo cáo do job tự sinh**:
> 1. `php artisan migration:verify-run --run-code=<code> --mode=COMMIT --package=<pkg>
>    --error-file=<...> --output=<...>` dựng lại kết luận từ `migration_runs`, `migration_errors`,
>    `stock_movements`, `inventory_balances`, `item_instances` và **băm lại** package.
> 2. Bộ check bắt buộc: kết cục run + đối soát PASS; đủ 4 trường định danh nguồn;
>    `valid+invalid+duplicate = input`; số đã lưu khớp bảng lỗi; checksum và số dòng khớp package;
>    tối đa một COMMIT còn hiệu lực cho mỗi checksum; balance = ledger; lượng serial-tracked = số
>    Item; container không sinh thêm tồn.
> 3. Exit 0 ⇔ mọi check PASS. Sửa tay số liệu trong `migration_runs` bị bắt bằng
>    `STORED_COUNTS_MATCH_ERROR_TABLE` (đã khoá bằng test).
> 4. Bộ test `Wwhn260MigrationPackClosureTest` (10/10) là điều kiện cần trước khi QA nhận bàn giao.
>
> Việc chạy verify trên đúng SHA và checksum production sẽ ghi tại `WWHN-262`.
