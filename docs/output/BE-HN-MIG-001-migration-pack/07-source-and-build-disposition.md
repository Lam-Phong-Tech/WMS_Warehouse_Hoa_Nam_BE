# 7. Nguồn, nhánh và build — DEV260-P1-07, DEV260-P1-08

## 7.1 Vấn đề register nêu

- Không tìm được PR/SHA/build gắn với `WWHN-260`; HEAD quan sát được lại thuộc `WWHN-151`.
- Không có quyết định chính thức giữa `m1-be-2` và `m1` cho nguồn migration/release.

Cả hai đều là vấn đề **truy vết**, không phải vấn đề chức năng — nhưng nếu không đóng thì
không ai chứng minh được "cái đã review chính là cái sẽ chạy khi cutover".

## 7.2 Nhánh nguồn duy nhất

Quyết định: `docs/decision-records/DEC-MIG-SRC-001-migration-source-disposition.md`.

Tóm tắt: **`m1` là nhánh nguồn duy nhất** của migration/cutover cho cả BE và FE. `m1-be-2` là
tổ tiên thuần của `m1` (đã kiểm ở `WWHN-290`) nên không mang commit nào mà `m1` thiếu; mọi
tham chiếu tới `m1-be-2` trong ticket cũ được coi là superseded. Không tạo nhánh riêng cho
`WWHN-260`.

## 7.3 Cách pin SHA cho lần review này

Nhánh `m1` có nhiều phiên làm việc song song, nên "SHA mới nhất" không phải là bằng chứng.
Quy ước dùng cho pack này giống các gói trước (`WWHN-273/290/328`):

1. Commit thay đổi của `WWHN-260` thành một commit riêng, tiêu đề bắt đầu bằng `WWHN-260`.
2. Ghi **full SHA** của commit đó vào `MANIFEST.json` (`implementation_sha`) và vào comment Jira.
3. Reviewer checkout đúng SHA đó rồi chạy lại lệnh ở `docs/evidence/WWHN-260/EVIDENCE_PACK.md`
   §1 — mọi số trong evidence phải tái lập được.

Bảng dưới đây được điền ngay sau khi commit (xem `MANIFEST.json` để lấy giá trị hiện hành):

| Hạng mục | Giá trị |
|---|---|
| Repo BE | `WMS_Warehouse_Hoa_Nam_BE` |
| Nhánh | `m1` |
| Implementation SHA (BE) | xem `MANIFEST.json` → `implementation_sha.backend` |
| Repo FE | `WMS_Warehouse_Hoa_Nam_FE` |
| Implementation SHA (FE) | xem `MANIFEST.json` → `implementation_sha.frontend` |
| Merge target | `m1` (không có nhánh trung gian) |

## 7.4 Cổng CI cho lần nạp cuối

Migration là console job nên không có route để test hợp đồng. Cổng thực chất là bộ test:

```bash
php artisan test --filter=Wwhn260MigrationPackClosureTest
php artisan test --filter=MigrationFinalDataLoadTest
php artisan test --filter=ErdPhysicalSchemaParityTest   # ERD-AMD-007 phải khớp schema thật
```

Cả ba phải xanh trên đúng SHA đã pin; kết quả gắn vào `WWHN-260` như build evidence.

## 7.5 SHA thực tế của lần bàn giao này

| Hạng mục | Giá trị |
|---|---|
| BE — commit chứa nội dung `WWHN-260` | `b90d5ef67c6577db166fdac00f1323741ccfd888` |
| FE — commit guard operational-only | `e02d4185608e7bb661481968a902b71c81386362` |
| Nhánh | `m1` (cả hai repo) |

**Lưu ý truy vết (quan trọng, không giấu):** nhánh `m1` đang có nhiều phiên làm việc song song
dùng chung working tree. Commit BE `b90d5ef` vì thế mang **nhiều ticket** trong một commit
(`WWHN-252` App PV/PV Admin, phần cứng cố `WWHN-299`, và toàn bộ `WWHN-260`), và tiêu đề commit
ghi `WWHN-252`. Đây là hạn chế của quy trình làm việc, không phải của nội dung bàn giao.

Cách reviewer tách đúng phần `WWHN-260` ra khỏi commit đó:

```bash
git show b90d5ef67c6577db166fdac00f1323741ccfd888 -- \
  app/Support/Migration/ \
  app/Console/Commands/MigrationVerifyRun.php \
  app/Console/Commands/MigrationFinalDataLoad.php \
  config/migration_mapping.php \
  config/final_data_load.php \
  Modules/Warehouse/database/migrations/2026_08_18_193000_add_duplicate_row_count_to_migration_runs.php \
  docs/HoaNam_ERD_Moi_Nhat.md \
  docs/migration/ docs/output/BE-HN-MIG-001-migration-pack/ docs/evidence/WWHN-260/ \
  docs/docs_task_jira/WWHN-260.md docs/docs_task_jira/WWHN-260-signoff-comments.md \
  docs/decision-records/DEC-MIG-SRC-001-migration-source-disposition.md \
  tests/Feature/Wwhn260MigrationPackClosureTest.php
```

Danh sách tệp + digest của từng tệp nằm ở `MANIFEST.json`, nên nội dung bàn giao vẫn kiểm được
độc lập với việc commit có lẫn ticket khác hay không. Khuyến nghị quy trình cho lần sau
(`DEC-MIG-SRC-001` §Hệ quả): mỗi phiên làm việc commit bằng pathspec riêng trước khi phiên khác
`git add`, để một commit chỉ mang một ticket.

## 7.6 Xác nhận không trôi (no-drift) sau SHA đã pin — đóng DEV260-P1-07R2

Register vòng R2 nêu lo ngại `implementation_sha` có thể đã lỗi thời vì nhánh `m1` có nhiều
commit chạy sau `b90d5ef`. Đã kiểm bằng `git log -- <đúng các path thuộc phạm vi pack>` (danh
sách path ở §7.5) tính đến thời điểm audit này (`2026-08-19`, HEAD `m1` =
`6cfb642e7e78b46a012af54a47aac21420502443`, không chạm path nào của pack). Có đúng 3 commit
chạm phạm vi pack **sau** `b90d5ef`:

| SHA đầy đủ | Nội dung | Có đụng code chức năng (`app/`, `Modules/*/app`, `database/migrations`) không |
|---|---|---|
| `4626ce6d571bedfc02d406f2045d6a656ef10e56` | Ghi chú pin SHA + ràng buộc truy vết commit gộp (chính là §7.3–§7.5 ở trên) | Không — chỉ `docs/` + `MANIFEST.json` |
| `ee9e334a6b8f4d750c14f83f547f8670c17ac31a` | Đóng P1/P2 của `WWHN-299` (RBAC operator/approver, `--approval-ref`), cập nhật `FINAL_DATA_LOAD_RUNBOOK.md` | Không — commit này chủ yếu là `WWHN-299`; phần chạm phạm vi `WWHN-260` chỉ có runbook (docs) |
| `cbe70584607013da97449123a28d43e11eee8538` | Kiểm `ERD-AMD-007` (`migration_runs.duplicate_rows`) thật trên PostgreSQL, thêm evidence `12-postgres-column-check.txt` | Không — chỉ `docs/` + `MANIFEST.json` |

Kết luận: **`implementation_sha.backend = b90d5ef67c6577db166fdac00f1323741ccfd888` vẫn là SHA
đúng đại diện cho code chức năng đang chạy** (không có commit nào sau nó sửa
`app/Support/Migration/`, `app/Console/Commands/MigrationVerifyRun.php`,
`app/Console/Commands/MigrationFinalDataLoad.php`, hay migration
`2026_08_18_193000_add_duplicate_row_count_to_migration_runs.php`) — không cần re-pin. Ba
commit trên chỉ bồi thêm bằng chứng/tài liệu, và `MANIFEST.json` đã cập nhật checksum khớp
100% với nội dung file hiện hành của cả ba (đã tính lại `sha256sum` độc lập ngày `2026-08-19`
cho toàn bộ `related_documents` + `evidence_artifacts`, không lệch một ký tự).

Quy tắc cho reviewer các vòng sau: `implementation_sha` neo **code**, không neo **docs**. Nếu
một commit sau pin chỉ sửa `docs/` hoặc `MANIFEST.json`, không cần re-pin — chỉ cần bảng no-drift
này được cập nhật (thêm dòng mới), và `MANIFEST.json` checksum phải luôn khớp file hiện hành.
Nếu một commit sau pin sửa bất kỳ path nào trong danh sách "code chức năng" ở trên, **bắt buộc
re-pin** `implementation_sha` sang SHA đó và chạy lại toàn bộ evidence.

**Bằng chứng build local (2026-08-19, tại HEAD `6cfb642`, không phải CI — GitHub Actions đang
tạm dừng theo yêu cầu chủ dự án để tránh phát sinh phí, mọi kiểm chứng ở đây chạy local):**

```
php vendor/bin/phpunit --filter=Wwhn260MigrationPackClosureTest
{"tool":"phpunit","result":"passed","tests":10,"passed":10,"assertions":54,"duration_ms":2647}

php vendor/bin/phpunit --filter=MigrationFinalDataLoadTest
{"tool":"phpunit","result":"passed","tests":20,"passed":20,"assertions":120,"duration_ms":3979}

php vendor/bin/phpunit --filter=ErdPhysicalSchemaParityTest
{"tool":"phpunit","result":"passed","tests":4,"passed":0,"assertions":0,"duration_ms":2678,"skipped":4}
```

`ErdPhysicalSchemaParityTest` skip cả 4 trên sqlite theo thiết kế (test này chỉ có ý nghĩa trên
PostgreSQL vì trigger đồng bộ `product_masters` và cột `duplicate_rows` chỉ được migration cài
khi `DB::getDriverName() = pgsql`, xem `2026_08_16_135000` và ghi chú tại
[[reference-be-test-drivers]]) — bằng chứng PostgreSQL thật đã có sẵn ở
`docs/evidence/WWHN-260/12-postgres-column-check.txt` (từ `cbe7058`), không skip trên driver đó.

**Đóng luôn nhầm lẫn file nêu ở `DEV260-P1-03R2`:** register ghi "ImportService.php" là vị trí
cần sửa coverage dataset. Đây là nhầm file — `Modules/Warehouse/app/Services/ImportService.php`
là API import SKU vận hành thường ngày (`WWHN-172/WWHN-330/331`), không phải migration.
Coverage 6 dataset thật sự nằm ở `app/Support/Migration/FinalDataLoadService.php` và đã đủ,
xem giải thích chi tiết + bảng đối chiếu tại `02-scripts-and-coverage.md` §2.2–§2.3 (đã có sẵn
từ vòng bàn giao trước, không cần sửa thêm).
