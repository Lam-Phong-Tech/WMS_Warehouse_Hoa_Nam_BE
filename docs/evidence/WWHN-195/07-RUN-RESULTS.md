# WWHN-195 — Kết quả chạy thật (register R2)

Mọi số dưới đây lấy từ lần chạy trên máy dev ngày 19/08/2026, nhánh `m1`.
Lệnh tái lập ghi kèm từng dòng.

## 1. Bộ test phạm vi WWHN-195 — xanh trên CẢ HAI driver

| Driver | Lệnh | Kết quả |
|---|---|---|
| sqlite (`:memory:`, đúng driver CI) | `php vendor/bin/phpunit --filter="Wwhn195OutboundContractAlignmentTest\|OutboundDocumentFlowTest\|PostIssueConcurrencyBehaviorTest\|PostIssueAuditLogTest\|OutboundPermissionTest\|Flow06PackingLabelAlignmentTest\|AnnexCOutboundLifecycleTest\|CurrentBaselineFlowContractSourceTest\|OutboundDocsAlignmentContractTest"` | **52 passed / 52**, 403 assertions |
| PostgreSQL 16 (`hn_wwhn195`) | cùng filter, thêm `DB_CONNECTION=pgsql DB_DATABASE=hn_wwhn195` | **52 passed / 52**, 403 assertions |

Raw: `03-phpunit-sqlite.json`, `04-phpunit-postgres.json`.

10 test mới (`Wwhn195OutboundContractAlignmentTest`) phủ đúng phần register R2 nêu:
route parity, ghost operation, contract == route, `code_value` 150, vòng đời nhãn §6,
`scan_source` explicit / inferred / invalid / không khoá mode, alias `/scans`.

## 2. Verifier contract — 6/6

```bash
node scripts/verify-wwhn-195-outbound-contract.mjs
```

Raw: `02-contract-verifier.json`. Chạy được cả khi không có DB; khi có `vendor` thì
V3 lấy route từ `php artisan route:list --json` thật thay vì đọc tĩnh.

## 3. PostgreSQL — migrate / schema / FK

```bash
DB_CONNECTION=pgsql DB_DATABASE=hn_wwhn195 php artisan migrate --force
docker exec -i wms_warehouse_hoa_nam_be-postgres-1 psql -U postgres -d hn_wwhn195 \
  < docs/evidence/WWHN-195/05-postgres-schema.sql
```

- Migrate sạch từ DB rỗng: **155 migration**, trong đó
  `2026_08_19_000300_expose_scan_source_on_sqlite_scan_evidence` chạy no-op đúng như
  thiết kế (driver-gated cho sqlite).
- `scan_events` là **TABLE** trên PostgreSQL, `scan_event` là **VIEW** — đúng chiều
  đảo tên bảng của migration `000791`.
- `scan_source varchar(20) NOT NULL` + CHECK
  `chk_erd_scan_events_0 = scan_source IN ('CAMERA','MANUAL','HARDWARE')` — khớp ERD bảng 25.
- FK đã repoint đúng bảng canonical: `fk_erd_scan_events_physical_code_id → physical_codes(id)`.
- `physical_codes.code_value` = **varchar(200)** trên PostgreSQL, khớp ERD (migration khởi
  tạo ghi 100 nhưng bước materialize ERD đã nới lên 200) → **không có lệch schema**;
  API cố ý chốt trần đầu vào hẹp hơn ở 150.
- `label_snapshots` có `uk_label_snapshots_outbound_document UNIQUE (outbound_document_id)`
  — đúng "01 Packing Label cho mỗi phiếu xuất".

Raw: `05-postgres-schema.txt` (+ `.sql` để chạy lại).

## 4. FE

| Hạng mục | Kết quả |
|---|---|
| `npx tsc --noEmit` | sạch |
| `npm test` (vitest) | **90 file / 701 test, toàn bộ pass** — gồm `src/test/wwhn-195-scan-mode-contract.test.ts` mới |

## 5. Full suite BE trên sqlite — 4 đỏ KHÔNG thuộc WWHN-195

`php vendor/bin/phpunit` → **1086 passed / 1126**, 35 skipped, **4 failed** (một lần chạy
có thêm 1 đỏ thứ 5 do phiên song song đang ghi dở `HN-BE-API-002_ERROR_CODE_CATALOG.json`;
chạy lại riêng `Wwhn217RuntimeErrorContractTest` → 4/4 pass).

Đã chứng minh 4 đỏ này **tồn tại sẵn**, không do WWHN-195: dựng worktree tách rời tại
`HEAD` sạch (commit `0c2d954`, không có thay đổi của WWHN-195) và chạy đúng 4 test đó →
tái hiện y hệt.

| Test đỏ | Nguyên nhân | Chủ sở hữu |
|---|---|---|
| `DocumentationBaselineContractTest::test_generated_swagger_has_route_parity_and_baseline_error_responses` | `get /api/v1/health` thiếu HTTP 400 trong `storage/api-docs` | nhóm health/runtime (WWHN-295) |
| `Flow05InventoryControlAlignmentTest::test_published_contract_artifacts_carry_no_stocktake_capability` | `docs/api-contract/releases/WWHN-190/1.8.0/*` còn `/stocktake` | WWHN-190 release artifact |
| `Wwhn267ContractClosureTest::test_role_baseline_matches_the_pin` | tập permission `WAREHOUSE_MANAGER` lệch bản pin | WWHN-267 |
| `Wwhn309PermissionCatalogEvidenceTest::test_published_catalog_document_is_not_stale` | catalog permission đã publish bị stale so với seeder | WWHN-309 |

Không đỏ nào chạm luồng xuất kho hay artifact của WWHN-195.

## 6. Lưu ý môi trường khi tái lập

Trong phiên làm việc này có nhiều tiến trình song song chạy `composer install` / `npm ci`
trên cùng repo, khiến `vendor/` và `node_modules/` biến mất giữa chừng vài lần. Triệu chứng:
`Could not open input file: vendor/bin/phpunit`, `Class "Laravel\Pao\UserFilters\CaptureFilter" not found`,
`'vitest' is not recognized`. Đây là nhiễu môi trường, không phải lỗi code — chờ cài xong
rồi chạy lại là ra đúng số ở trên.
