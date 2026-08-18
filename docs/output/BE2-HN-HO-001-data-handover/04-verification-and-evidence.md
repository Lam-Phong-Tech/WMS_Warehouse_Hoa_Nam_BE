# 04 — Cách kiểm chứng và evidence

## 1. Hai nhánh driver — không so kết quả chéo

| Nhánh | Cách chạy | Vai trò |
| --- | --- | --- |
| SQLite (mặc định) | `php vendor/bin/phpunit --no-coverage` | Bộ CI chạy full suite; `phpunit.xml` ép `DB_CONNECTION=sqlite`, `:memory:` |
| PostgreSQL (DB tham chiếu) | set env `DB_*` trước rồi chạy phpunit | Nơi duy nhất cổng schema vật lý có hiệu lực |

`<env>` trong `phpunit.xml` không có `force="true"` nên biến shell thắng:

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=<db> DB_USERNAME=postgres DB_PASSWORD=postgres php vendor/bin/phpunit --no-coverage
```

**Không chạy hai tiến trình phpunit song song trên cùng một database** — `migrate:fresh` của hai
tiến trình gây deadlock `40P01`. Khi cần chạy song song, tạo database riêng cho từng tiến trình.

## 2. Cổng kiểm chứng liên quan trực tiếp tới gói bàn giao này

| Test | Kiểm gì |
| --- | --- |
| `ErdPhysicalSchemaParityTest` | 59 entity phải là bảng thật; kiểu/nullable/default từng field; PK/UNIQUE/UX/IDX kể cả composite và partial; FK theo đúng bảng cha ghi trong tài liệu |
| `DocumentationBaselineContractTest` | Quan hệ FK theo `docs/contracts/HoaNam_Current_Baseline_Contract.json`; bảng bắt buộc/bị loại; permission + role canonical; parity Swagger ↔ route |
| `ErdBusinessInvariantParityTest` | Bất biến nghiệp vụ + cầu ghi runtime → canonical còn sống |
| `ErdRuntimeMirrorParityTest` | Bảng mirror ERD đồng bộ với bảng runtime |
| `Wwhn310DataHandoverContractTest` | Khoá lại toàn bộ lệch đã đóng trong ticket này |
| `MigrationFinalDataLoadTest` | Dry-run/commit/rollback, checksum, reconciliation, idempotency của job nạp dữ liệu cuối |
| `ReportFlow06DocsAlignmentTest`, `ReportFlow06ContractTest`, `ReportFlow06IntegrationTest` | Báo cáo §13.10: cột, filter, as_of, PII, export, cấu hình Swagger |
| `InventoryReconciliationIntegrityTest` | 4 phép đối soát ledger/balance/subledger/container |

Lệnh chạy nhanh nhóm dữ liệu trên PostgreSQL:

```bash
php vendor/bin/phpunit --no-coverage --filter 'ErdPhysicalSchemaParityTest|DocumentationBaselineContractTest|ErdBusinessInvariantParityTest|ErdRuntimeMirrorParityTest|Wwhn310DataHandoverContractTest'
```

## 3. Kiểm chứng thủ công nhanh

```bash
php artisan migrate:fresh --seed --force
```

Sau đó đối soát trực tiếp trên PostgreSQL:

```sql
SELECT COUNT(*) FROM defect_catalogs WHERE id IS DISTINCT FROM defect_id;               -- phải = 0
SELECT COUNT(*) FROM warranty_cases w LEFT JOIN defect_catalogs d ON d.id = w.defect_id
  WHERE w.defect_id IS NOT NULL AND d.id IS NULL;                                       -- phải = 0
SELECT conname FROM pg_constraint WHERE conrelid = 'reversal_lines'::regclass AND contype = 'f';
SELECT indexdef FROM pg_indexes WHERE tablename = 'inbound_scan_entries';
```

## 4. Evidence cần nộp khi nghiệm thu

1. Exact commit SHA của nhánh `m1` dùng để kết luận (không dùng "latest m1" mơ hồ).
2. Kết quả phpunit trên **cả hai** driver tại đúng SHA đó, kèm số liệu pass/fail/skip.
3. Kết quả `php artisan migrate:fresh --seed --force` trên PostgreSQL tại đúng SHA đó.
4. Với nạp dữ liệu cuối: `dry-run.json` (PASSED), `commit.json` (COMMITTED, cùng `source_checksum`),
   `rollback.json` (`residual_opening_qty = 0`), bản ghi `migration_runs`/`migration_errors` tương
   ứng và audit log `MIGRATION_*_COMPLETED`.
5. Checksum trùng khớp của `docs/HoaNam_ERD_Moi_Nhat.md` giữa hai repo BE và FE.

## 5. Kết quả đo tại thời điểm bàn giao

| Phép đo | Trước thay đổi | Sau thay đổi |
| --- | --- | --- |
| SQLite full suite | 667 test — 645 pass, 22 skip, 0 fail | 744 test — 717 pass, 27 skip, **0 fail** |
| PostgreSQL, nhóm cổng dữ liệu | 5 fail: `defect_catalog` là view ×3, PK `user_roles`, FK `reversal_lines.original_movement_id` | **71/71 pass** (gồm `Wwhn310DataHandoverContractTest`) |
| PostgreSQL full suite | 5 đỏ nhóm WWHN-31 cộng các đỏ có sẵn | 744 test — 721 pass, 5 skip, 18 đỏ **đều có sẵn từ trước**: 17 × `Wwhn328DashboardQuickContractTest` (`uk_warehouses_single_active`) + 1 × `Flow02InboundPutawayAlignmentTest` (`ux_erd_doc_inbound_lines_*`). Đã xác minh bằng cách gỡ 4 migration và khôi phục ERD gốc rồi chạy lại. |
| PostgreSQL, `Flow01...::test_inactive_defect_cannot_be_attached_on_temp_only` | ĐỎ sẵn từ trước (fixture khai IDENTIFIED mà không có `item_id`, vi phạm `chk_erd_warranty_identification_shape`) | PASS sau khi sửa fixture về đúng shape TEMP_ONLY |
| `php artisan migrate:fresh --seed --force` trên PostgreSQL | — | Chạy sạch; `defect_catalogs` 0 dòng lệch `id`/`defect_id` |
| FE `typecheck` + `lint` + `test` + `build` | — | typecheck PASS, lint 0 error, **522/522 test PASS** (69 file), build PASS |

Số test tăng do các ticket khác cùng chạy trên `m1` bổ sung test; con số chốt phải đo lại tại đúng
closure SHA khi nhánh được commit.
