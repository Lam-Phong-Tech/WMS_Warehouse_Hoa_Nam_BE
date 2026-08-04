# WWHN-172 — Ràng buộc, audit và ranh giới giao dịch

- **Version:** 1.1.0
- **Branch:** `m1`
- **Baseline:** `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md`
- **Parent:** WWHN-37
- **Review dependencies:** WWHN-171 (BE2), WWHN-173 (QA1), WWHN-174 (QA2), Tech Lead, DevOps/DBA
- **Status:** implementation/evidence pipeline complete; chờ exact-SHA CI và sign-off độc lập.

## 1. Thay đổi kỹ thuật chính

### Transaction/race guard

`App\Http\Middleware\Wwhn172AggregateLock` được prepend toàn cục nhưng chỉ khóa mutation dưới `/api/v1/skus*` và `/api/v1/containers*`. Mutex row chạy trong DB transaction trên SQLite/PostgreSQL/MySQL, giải quyết race tại thời điểm object/edge chưa tồn tại: opposite graph edge, physical-code claim, serial/container claim và concurrent aggregate mutation.

Các service hiện có tiếp tục giữ row lock, expected version, DAG/hierarchy validation, idempotency snapshot và audit trong cùng commit unit.

### Import all-or-nothing

Lỗi runtime cũ: `ImportController` gọi `AuditLogger::record(metadata: ...)` dù method không có tham số `metadata`; lỗi có thể xảy ra sau partial write.

Đã sửa:

- controller chỉ orchestration/response/temp-file cleanup;
- `ImportService` chạy hai pha `validate toàn file -> DB lock/recheck -> write toàn bộ`;
- map theo **tên header**, không theo vị trí;
- reject unknown/duplicate/missing header;
- enum/boolean strict, không silent coercion;
- duplicate trong file/DB, inactive SKU hoặc identity collision làm `committed=false`, `partial_write=false`, zero business side effect;
- `update_existing=true` chỉ update SKU ACTIVE và tăng version một lần;
- per-row audit và summary audit nằm trong cùng transaction.

### Audit và error safety

- Mutation WWHN-172 ép `audit.write_policy=fail_closed` theo request và restore trong `finally`.
- Audit failure rollback business row, idempotency row và outbox.
- API 500 không trả `exception/file/line/trace`, kể cả `APP_DEBUG=true`.
- Unique race được chuẩn hóa thành 409 `UNIQUE_CONSTRAINT_CONFLICT`, không lộ SQL/binding.

## 2. Migration safety

Migration:

`Modules/Warehouse/database/migrations/2026_08_04_000220_harden_wwhn172_serial_and_code_uniqueness.php`

Nội dung:

- tạo `aggregate_mutexes`;
- chạy `Wwhn172MigrationInspector` trước DDL và BLOCK nếu legacy data vi phạm;
- unique `container_item.inventory_serial_id`;
- unique `container.physical_code_id`;
- check self-component, positive quantity và container-item identity trên PostgreSQL/MySQL;
- không nuốt lỗi DDL;
- có command preflight:

```bash
php artisan wwhn172:migration-preflight --output=build/evidence/wwhn-172/preflight.json
```

CI chạy `migrate --pretend`, `migrate:fresh`, preflight, rollback một step, re-apply và postcheck trên SQLite/PostgreSQL/MySQL.

## 3. Runtime/evidence pipeline

Workflow:

`.github/workflows/wwhn-172-sku-container-contract.yml`

Mỗi DB engine chạy trên exact `${{ github.sha }}`:

1. PHP syntax, Pint, `git diff --check`, Redocly;
2. migration pretend/fresh/preflight/rollback/reapply/postcheck;
3. focused tests:
   - `Wwhn172SkuContainerTransactionTest`;
   - `Wwhn172AuditFailClosedTest`;
   - `Wwhn172MigrationSafetyTest`;
   - `Wwhn174SkuImportValidationTest`;
4. full PHPUnit + JUnit;
5. generated OpenAPI;
6. copy OpenAPI/Postman/test-case source vào artifact;
7. DB before/after snapshot;
8. service proof: business row + audit row + exact idempotent replay + outbox empty;
9. exact commit/source-tree metadata;
10. `SHA256SUMS` và credential-leak gate.

Artifact:

`wwhn-172-evidence-<sqlite|pgsql|mysql>-<exact-sha>`

## 4. Contract/source bundle

| Source | Version/status |
|---|---|
| `docs/api-contract/WWHN-172-sku-container-openapi.yaml` | canonical contract; copied and hashed by CI |
| `docs/postman/WWHN-172 SKU Container Contract.postman_collection.json` | executable collection; copied and hashed by CI |
| `docs/testing/WWHN-172-test-cases.json` | P0/P1 test register; copied and hashed by CI |
| `docs/docs_task_jira/WWHN-172.md` | 1.1.0 |

`build-metadata.json`, `SHA256SUMS` và `SHA256SUMS.sha256` là nguồn hash exact-SHA. Không ghi trước commit SHA/hash tĩnh vào chính tài liệu để tránh self-referential hash.

## 5. Disposition đánh giá 74/100

| Finding | Disposition |
|---|---|
| BE1-P1-01 Runtime/DB evidence | exact-SHA CI, full/focused PHPUnit, raw logs, JUnit và matrix SQLite/PostgreSQL/MySQL |
| BE1-P1-02 OpenAPI/Postman | canonical files được copy vào artifact và hash trong manifest |
| BE1-P1-03 QA1 trace | automated idempotency/cycle/version/audit/no-stack tests; WWHN-173 vẫn cần QA1 sign-off độc lập trên exact artifact |
| BE1-P1-04 QA2 import | import all-or-nothing + duplicate/invalid/inactive/audit-failure tests; WWHN-174 cần QA2 sign-off độc lập |
| BE1-P1-05 BE2 boundary | mutex/transaction/schema ownership được chốt trong code và tài liệu; WWHN-171 + Tech Lead cần review độc lập |
| BE1-P1-06 Audit proof | `service-proof.json`, DB before/after, fail-closed raw test và outbox=0 |
| BE1-P1-07 Migration safety | preflight, pretend, rollback/reapply và DB matrix; DBA/DevOps cần ký target-environment result |
| BE1-P2-01 Jira handoff | collector sinh `jira-handoff-comment.txt`; BE1 post comment exact SHA/artifact/hash/reviewer/blocker |

Bảng đánh giá không nêu finding P0. Không suy diễn P0 mới là đã đóng.

## 6. Files thay đổi

- `.github/workflows/wwhn-172-sku-container-contract.yml`
- `.env.example`, `config/audit.php`, `bootstrap/app.php`
- `app/Http/Middleware/Wwhn172AggregateLock.php`
- `app/Support/Wwhn172MigrationInspector.php`
- `app/Console/Commands/Wwhn172MigrationPreflight.php`
- `routes/api.php`
- `Modules/Warehouse/app/Http/Controllers/ImportController.php`
- `Modules/Warehouse/app/Services/ImportService.php`
- migration hardening WWHN-172
- focused tests và evidence scripts
- tài liệu này và reviewer checklist.

## 7. Closure rule

BE1 chỉ được ghi **CI_VERIFIED** khi cả ba matrix jobs PASS trên exact commit. `Done/Approved/100%` cần đủ comment/sign-off từ QA1 WWHN-173, QA2 WWHN-174, BE2 WWHN-171, Tech Lead và DevOps/DBA. Tài liệu không tự xác nhận thay reviewer độc lập.
