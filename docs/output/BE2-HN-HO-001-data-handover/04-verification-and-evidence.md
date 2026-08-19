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

## 6. Evidence vòng 2 (2026-08-19) — đã nộp trong repo

Vòng 1 mô tả *cách* kiểm chứng nhưng chưa nộp bản chạy thật gắn với một revision. Vòng 2 bổ sung:

| Nhóm | Đường dẫn |
| --- | --- |
| Pin nguồn (supersede `m1-be-2`, exact SHA, file set + blob hash) | `docs/evidence/WWHN-310/WWHN-310_SOURCE_PIN.json` |
| Register đóng P1/P2 kèm bằng chứng từng hạng mục | `docs/evidence/WWHN-310/WWHN-310_ROUND2_REGISTER.md` |
| Hồ sơ review ERD/Data Dictionary cho Tech Lead + BA/BAS | `docs/evidence/WWHN-310/ERD_AMENDMENT_REVIEW_RECORD.md` |
| Gói retest cho QA1 (WWHN-312) | `docs/evidence/WWHN-310/QA1_RETEST_PACKET.md` |
| Diễn tập nạp dữ liệu cuối: DRY_RUN → COMMIT → ROLLBACK + mẫu bị từ chối | `docs/evidence/WWHN-310/round2/` |
| Sổ bàn giao (parent WWHN-83) | `docs/output/HN-HANDOVER-INDEX.md` |

Chạy lại toàn bộ diễn tập nạp dữ liệu cuối trên một database sạch:

```bash
docker exec wms_warehouse_hoa_nam_be-postgres-1 psql -U postgres -c "CREATE DATABASE hn_wwhn310_fdl;"
DB_CONNECTION=pgsql DB_DATABASE=hn_wwhn310_fdl php artisan migrate:fresh --seed --force
bash docs/evidence/WWHN-310/round2/run-final-load-rehearsal.sh
```

Cổng khoá lại chính bộ evidence này (đỏ nếu register và artifact nói khác nhau):

```bash
php vendor/bin/phpunit --no-coverage --filter 'Wwhn310Round2ClosureTest|Wwhn310ErdDocumentPinTest'
```

## 7. Pin bản ERD dùng chung hai repo

`docs/contracts/erd-document-pin.json` tồn tại **giống hệt nhau** ở repo BE và repo FE: nó pin
`effective_revision` (`1.0+AMD008`), `sha256` và danh sách `ERD-AMD-*` của `HN-WMS-ERD-DD-001`.

Khi sửa ERD phải làm đủ ba bước trong cùng một lần: thêm dòng `ERD-AMD` mới → nâng hậu tố
`+AMD<nnn>` → cập nhật `sha256`/`bytes`/`amendments` trong file pin của **cả hai repo**. Thiếu bước
nào thì `Wwhn310ErdDocumentPinTest` (BE) hoặc `wwhn-310-erd-document-pin.test.ts` (FE) đỏ.
