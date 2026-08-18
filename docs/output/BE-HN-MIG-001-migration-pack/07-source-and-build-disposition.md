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
