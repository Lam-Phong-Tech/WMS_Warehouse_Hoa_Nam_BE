# WWHN-310 → WWHN-312 — Gói retest cho QA1 (DEV310-P1-05)

Mục tiêu: QA1 kết luận **PASS/FAIL dứt khoát** trên đúng một revision, bằng lệnh chạy được, không
phải bằng cách tin lời người thực hiện.

- Revision phải kiểm: `final_candidate.sha` trong `docs/evidence/WWHN-310/WWHN-310_SOURCE_PIN.json`
  (BE) và `frontend.sha` (FE). **Không** dùng "latest m1".
- Môi trường: PostgreSQL 16 (DB tham chiếu) cho cổng schema; SQLite cho bộ CI.

## 0. Chuẩn bị (một lần)

```bash
git -C <BE_repo> fetch origin m1
git -C <BE_repo> checkout <final_candidate.sha>
composer install --no-interaction
docker compose up -d postgres
docker exec wms_warehouse_hoa_nam_be-postgres-1 psql -U postgres -c "CREATE DATABASE qa_wwhn310;"
```

Mọi lệnh PostgreSQL bên dưới đặt trước các biến:

```bash
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=qa_wwhn310 DB_USERNAME=postgres DB_PASSWORD=postgres
```

**Không chạy hai tiến trình phpunit song song trên cùng một database** (`migrate:fresh` của hai tiến
trình gây deadlock `40P01`) — mỗi tiến trình một database riêng.

## 1. Constraint / schema vật lý — ERD parity

```bash
php vendor/bin/phpunit --no-coverage --filter 'ErdPhysicalSchemaParityTest|ErdBusinessInvariantParityTest|ErdRuntimeMirrorParityTest|ErdCompilerSourcePinTest'
```

Kỳ vọng: 0 fail. Kiểm được: 59 entity là bảng thật; kiểu/nullable/default từng field; PK/UNIQUE/UX/IDX
kể cả composite và partial; FK theo đúng bảng cha ghi trong tài liệu.

## 2. Migration + nạp dữ liệu cuối (dry-run / commit / rollback)

```bash
php vendor/bin/phpunit --no-coverage --filter 'MigrationFinalDataLoadTest|Wwhn260MigrationPackClosureTest|Wwhn172MigrationSafetyTest'
```

Diễn tập thật trên **database riêng** (không dùng DB dev/app):

```bash
docker exec wms_warehouse_hoa_nam_be-postgres-1 psql -U postgres -c "CREATE DATABASE qa_wwhn310_fdl;"
DB_DATABASE=qa_wwhn310_fdl php artisan migrate:fresh --seed --force
DB_DATABASE=qa_wwhn310_fdl bash docs/evidence/WWHN-310/round2/run-final-load-rehearsal.sh
```

Kỳ vọng theo đúng thứ tự script in ra: bước 1/2/4 exit `0`; bước 6/7 exit `1` (FAILED/BLOCKED là
đúng). So từng file sinh ra với bản đã nộp trong `docs/evidence/WWHN-310/round2/`:

| Artifact | Phải thấy |
| --- | --- |
| `10-dry-run.json` | `status = PASSED`, `counts.total = valid + invalid + duplicate`, `invalid = 0` |
| `11-commit.json` | `status = COMMITTED`, `source_checksum` **bằng** của dry-run, 5 phép đối soát đều PASS |
| `12-rollback.json` | `status = ROLLED_BACK`, `residual_opening_qty = 0`, `residual_master_rows = 0` |
| `13-dry-run-rejected.json` | `status = FAILED`, đủ `REQUIRED` / `INVALID_ENUM` / `DUPLICATE_CODE` / `UNKNOWN_SKU` / `UNKNOWN_FK` / `RECON_MISMATCH` |
| `16-commit-blocked.json` | `status = BLOCKED`, `error_code = DRY_RUN_NOT_PASSED`, `migration_run_id = null` |
| `14-` / `15-reconcile-*.txt` | balance ↔ ledger rỗng; serial-tracked: opening qty = số Item; sau rollback tổng ròng = 0 |

## 3. Permission / data-scope / PII của báo cáo

```bash
php vendor/bin/phpunit --no-coverage --filter 'ReportFlow06DocsAlignmentTest|ReportFlow06ContractTest|ReportFlow06IntegrationTest|DocumentationBaselineContractTest'
```

Kiểm: 9 báo cáo §13.10 đúng endpoint/permission/filter/export; `as_of`; PII bị mask theo §13.8.

## 4. Tính đúng của báo cáo đổi công thức (RPT-LABEL-01)

Kịch bản tay tối thiểu: in nhãn thất bại 1 lần → in lại thành công 1 lần.

- `total_attempts = 2`
- `failed_count = 1` (trước đây luôn 0 — đây chính là defect đã sửa)
- `reprint_count = 0` (in lại **thành công** trừ 1, không phải tổng lần thử trừ 1)

```bash
php vendor/bin/phpunit --no-coverage --filter 'Wwhn310DataHandoverContractTest'
```

## 5. Checksum / pin tài liệu

```bash
php vendor/bin/phpunit --no-coverage --filter 'Wwhn310ErdDocumentPinTest|Wwhn310Round2ClosureTest'
sha256sum docs/HoaNam_ERD_Moi_Nhat.md          # phải bằng sha256 trong docs/contracts/erd-document-pin.json
```

Ở repo FE, tại `frontend.sha`:

```bash
npx vitest run src/test/wwhn-310-erd-document-pin.test.ts
```

Hai repo phải cho **cùng một** chuỗi sha256.

## 6. Đối soát tồn kho

```bash
php vendor/bin/phpunit --no-coverage --filter 'InventoryReconciliationIntegrityTest|Wwhn204LedgerReconciliationCoverageTest'
```

## 7. Known failures — QA1 phải xác nhận đúng danh sách này, không nhận thêm

Danh sách đỏ được phép tồn tại tại revision này nằm ở `docs/evidence/WWHN-310/WWHN-310_ROUND2_REGISTER.md`
§DEV310-P1-03, kèm **owner ticket** của từng đỏ. Quy tắc kết luận:

- Đỏ nằm trong danh sách + đúng owner ticket → không chặn WWHN-310.
- Đỏ **không** nằm trong danh sách → FAIL, trả lại BE2.
- Không chấp nhận waiver dạng "người thực hiện nói là có sẵn từ trước": mỗi đỏ phải kèm cách tái lập
  hoặc commit gây ra nó.

## 8. Kết luận QA1 phải ghi vào WWHN-312

| Trường | Nội dung |
| --- | --- |
| Revision | BE `<sha>` · FE `<sha>` (chép từ `WWHN-310_SOURCE_PIN.json`) |
| Môi trường | PostgreSQL `<version>` · PHP `<version>` |
| Kết quả từng mục §1..§6 | PASS/FAIL kèm số liệu test |
| Known failures | Trùng/không trùng danh sách §7 |
| Kết luận | PASS hoặc FAIL (không có "PASS có điều kiện") |
