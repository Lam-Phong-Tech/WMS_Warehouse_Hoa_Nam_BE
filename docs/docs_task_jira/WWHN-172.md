# WWHN-172 — [BE1] Rà soát ràng buộc/audit/ranh giới giao dịch

## 1. Document control

| Field | Value |
|---|---|
| Task | WWHN-172 |
| Parent task | WWHN-37 — `[SKU] Mô hình/API cho SKU, item, component, mã vật lý và container` |
| Parent Temp Key | HN-MC-001 |
| Epic Temp Key | HN-EPIC-04 |
| Dependency | HN-MC-001-ST01 |
| Role | BE1 |
| Assignee Placeholder | HN-BE-01 |
| Responsible person | Quang Nguyễn Thanh |
| Output | Kết quả rà soát kỹ thuật |
| Implementation branch | `m1` |
| Status | Code/contract/test source complete; independent QA/BA runtime sign-off pending |
| Scope | Harden đúng aggregate SKU/component/physical code/container; không đổi parent/epic/dependency/reference |

## 2. Kết quả rà soát

Trước WWHN-172, `/api/v1/skus` mới là alias CRUD của `products`; `physical_code`, `container`, `container_item` chủ yếu mới có schema/read contract. Luồng tạo product ghi business row và audit không nằm trong một transaction bao trùm. Chưa có component graph, optimistic concurrency API, ownership guard cho physical code, null-safe identity cho container item, serial cross-container guard hoặc sealed-container immutability.

WWHN-172 đưa write path vào hai aggregate transaction:

1. `SkuAggregateService`: `products` + `sku_component` + `physical_code` + `audit_log`.
2. `ContainerService`: `container` + `container_item` + physical-code binding + `audit_log`.
3. `IdempotencyStore` hiện hữu là outer transaction cho mutation có idempotency key; business data, audit và response snapshot commit/rollback cùng nhau.
4. `version`/`If-Match` là optimistic concurrency precondition cho update/revoke/seal.
5. `AuditLogger` hiện hữu tiếp tục là policy duy nhất cho redaction, append-only audit và fail-closed/outbox fallback.

## 3. Findings và disposition

| ID | Sev | Finding trước sửa | Disposition |
|---|---:|---|---|
| WWHN172-P0-01 | P0 | SKU/product có thể commit trước khi audit lỗi | Fixed: business write và audit cùng `DB::transaction` |
| WWHN172-P0-02 | P0 | Không có component self/cycle/positive-quantity guard | Fixed: request + locked service traversal + DB FK/unique/check nơi hỗ trợ |
| WWHN172-P0-03 | P0 | Physical code có thể bị chuyển chủ | Fixed: lock code row; kiểm tra đồng thời `product_id`, generic owner type/id; conflict `409` |
| WWHN172-P0-04 | P0 | Container root/items/audit không có aggregate transaction | Fixed: một transaction, retry deadlock tối đa 3 lần |
| WWHN172-P0-05 | P0 | Một serial có thể bị claim bởi hai container | Fixed: lock serial + active cross-container lookup |
| WWHN172-P1-01 | P1 | Không có optimistic lock cho mutation | Fixed: `If-Match` hoặc body `version`; stale trả `409` |
| WWHN172-P1-02 | P1 | SEALED container vẫn có thể bị sửa | Fixed: SEALED/CLOSED immutable; seal chỉ từ ACTIVE và phải có item |
| WWHN172-P1-03 | P1 | Unique với nullable product/serial không xác định grain | Fixed: deterministic `item_key`; unique `(container_id,item_key)` |
| WWHN172-P1-04 | P1 | Retry same command có thể tạo duplicate | Fixed: canonical request hash + exact replay + same-key/different-payload `409` |
| WWHN172-P1-05 | P1 | Parent container có thể self/cycle/cross-warehouse | Fixed: locked parent-chain traversal và warehouse guard |
| WWHN172-P2-01 | P2 | GET SKU detail không trả nested members | Fixed: aggregate snapshot gồm components và physical codes |

## 4. Changed files

### Runtime

- `Modules/Warehouse/app/Services/SkuAggregateService.php`
- `Modules/Warehouse/app/Services/ContainerService.php`
- `Modules/Warehouse/app/Http/Controllers/ProductController.php`
- `Modules/Warehouse/app/Http/Controllers/SkuGovernanceController.php`
- `Modules/Warehouse/app/Http/Controllers/ContainerController.php`
- `Modules/Warehouse/app/Http/Requests/StoreProductRequest.php`
- `Modules/Warehouse/database/migrations/2026_08_03_000210_harden_sku_component_container_contract.php`
- `routes/api.php`

### Contract/evidence source

- `tests/Feature/Wwhn172SkuContainerTransactionTest.php`
- `docs/api-contract/WWHN-172-sku-container-openapi.yaml`
- `docs/postman/WWHN-172 SKU Container Contract.postman_collection.json`
- `docs/testing/WWHN-172-test-cases.json`
- `docs/docs_task_jira/WWHN-172.md`

## 5. Transaction boundaries

### 5.1 Create SKU

```text
IdempotencyStore outer transaction (khi client gửi key)
  reserve/lock (operation, key, actor)
  SkuAggregateService transaction
    validate/normalize code
    insert products(version=1)
    replace sku_component edges
    assign physical_code under row lock
    insert append-only audit
  persist exact 2xx response snapshot
COMMIT
```

Validation, cycle, ownership, database hoặc audit failure sẽ rollback product, edges, physical codes, audit và idempotency lease. Failed response không được cache làm replay thành công.

### 5.2 Update SKU / physical code

```text
lock products row
compare expected_version == current version
capture before aggregate snapshot
validate graph/code ownership
write requested members
increment products.version exactly once
write before/after audit
commit response snapshot
```

Concurrent opposite component edges có thể deadlock do deterministic row ownership; transaction retry sẽ chạy lại trên graph mới và loser bị cycle guard từ chối.

### 5.3 Create/update/seal container

```text
lock container + parent + code + serial/product rows
validate version/state/hierarchy/warehouse/ownership
write root
replace active item set with deterministic item_key
bind physical-code owner
write append-only audit
commit response snapshot
```

Seal yêu cầu `ACTIVE`, có ít nhất một active item, set `SEALED`, `sealed_at`, tăng version một lần và chặn mọi update tiếp theo.

## 6. Constraint matrix

| Area | Constraint | Enforcement |
|---|---|---|
| SKU code | `code`/`sku_code` uppercase, unique | request, locked service check, DB unique |
| Component pair | một parent/component pair | `uk_sku_component_pair` |
| Component self | parent khác component | request/service + DB check khi engine hỗ trợ |
| Component quantity | `quantity > 0` | request/service + DB check |
| Component graph | directed acyclic graph | transaction traversal trước insert |
| Component status | component tồn tại và ACTIVE | FK + locked product check |
| Component type | SKU type COMPONENT không có child | request + service |
| Physical code | normalized `code_value` unique | DB unique + row lock |
| Physical owner | không được chiếm code có product/generic owner khác | kiểm tra đầy đủ owner id/type, `409` |
| Container code | globally unique | service lock + DB unique |
| Parent container | no self/no cycle | locked chain traversal |
| Warehouse boundary | child-parent và serial-container cùng warehouse khi cả hai được set | service |
| Item identity | serial grain hoặc product grain duy nhất trong container | deterministic `item_key` + DB unique |
| Serial state | chỉ `IN_STOCK`/`IN_TRANSIT`; quantity = 1 | locked service check |
| Serial ownership | một active serial không thuộc hai container | locked cross-container lookup |
| Bulk item | active product, quantity > 0 | locked product check + DB check |
| Primary product | toàn bộ item phải khớp `primary_product_id` | service |
| Seal | ACTIVE và non-empty | state guard + locked count |
| Optimistic concurrency | expected version bằng current version | `If-Match`/body `version`, `409` |
| Idempotency | operation + actor + canonical request hash | `IdempotencyStore` |

## 7. Audit matrix

| Action | Object | Before | After | Same transaction |
|---|---|---:|---:|---:|
| `SKU_CREATE` | `sku` | No | Aggregate | Yes |
| `SKU_UPDATE` | `sku` | Aggregate | Aggregate | Yes |
| `SKU_PHYSICAL_CODE_ASSIGN` | `sku` | Aggregate | Aggregate | Yes |
| `SKU_PHYSICAL_CODE_REVOKE` | `sku` | Aggregate | Aggregate | Yes |
| `CONTAINER_CREATE` | `container` | No | Aggregate | Yes |
| `CONTAINER_UPDATE` | `container` | Aggregate | Aggregate | Yes |
| `CONTAINER_SEAL` | `container` | Aggregate | Aggregate | Yes |
| `CREATE/ENABLE/DISABLE` | `product` | As applicable | Product | Yes |

Audit payload đi qua `AuditLogger::sanitize()`. API/audit không ghi Authorization token, secret hoặc stack trace. Nếu audit chính và outbox cùng thất bại, business transaction fail-closed.

## 8. API handoff cho FE1/QA

### Preconditions

- `POST /api/v1/skus`: giữ backward compatibility; `Idempotency-Key` optional. Khi có key, exact replay và same-key/different-payload conflict được áp dụng.
- Mutation mới (`PATCH SKU`, physical-code mutation, container create/update/seal): `Idempotency-Key` bắt buộc, tối đa 128 ký tự.
- `If-Match: "<version>"` hoặc body `version`: bắt buộc cho update/revoke/seal.
- First execution trả `Idempotent-Replay: false`; replay exact trả `true`.

### Endpoints

| Method | Endpoint | Permission | Purpose |
|---|---|---|---|
| POST | `/api/v1/skus` | `sku.manage` | Create aggregate atomically |
| GET | `/api/v1/skus/{id}` | `sku.manage` | Aggregate detail |
| PATCH | `/api/v1/skus/{id}` | `sku.manage` | Optimistic aggregate update |
| POST | `/api/v1/skus/{id}/physical-codes` | `sku.manage` | Assign/reactivate code |
| DELETE | `/api/v1/skus/{id}/physical-codes/{physicalCodeId}` | `sku.manage` | Revoke code |
| GET | `/api/v1/containers` | `inventory.view` | Paginated/filter list |
| GET | `/api/v1/containers/{id}` | `inventory.view` | Aggregate detail |
| POST | `/api/v1/containers` | `warehouse.manage` | Atomic create |
| PATCH | `/api/v1/containers/{id}` | `warehouse.manage` | Optimistic update |
| POST | `/api/v1/containers/{id}/seal` | `warehouse.manage` | Immutable seal transition |

### Error codes chính

- `PRECONDITION_REQUIRED` — thiếu idempotency key trên mutation mới hoặc thiếu expected version (`428`).
- `IDEMPOTENCY_ERROR` — same key/different payload hoặc competing lease (`409`).
- `SKU_VERSION_CONFLICT`, `CONTAINER_VERSION_CONFLICT` (`409`).
- `SKU_COMPONENT_SELF_REFERENCE`, `SKU_COMPONENT_CYCLE`, `DUPLICATE_COMPONENT` (`422`).
- `PHYSICAL_CODE_ALREADY_ASSIGNED` (`409`).
- `SERIAL_ALREADY_CONTAINERIZED`, `SERIAL_NOT_CONTAINERIZABLE` (`409`).
- `CONTAINER_PARENT_CYCLE`, `CONTAINER_WAREHOUSE_MISMATCH` (`422`).
- `CONTAINER_IMMUTABLE`, `CONTAINER_STATE_CONFLICT` (`409`).
- `EMPTY_CONTAINER_CANNOT_SEAL` (`422`).

## 9. Test/evidence source

`tests/Feature/Wwhn172SkuContainerTransactionTest.php` hiện cover:

1. SKU aggregate create với component + physical code;
2. exact idempotent replay, không duplicate row/audit;
3. same key/different payload `409` không side effect;
4. component cycle rollback edge/version/audit;
5. stale SKU version;
6. container create/replay;
7. seal và immutable update;
8. duplicate item làm rollback root/item/audit.

Test-case register có 20 P0/P1 scenario, gồm race physical code, race serial, audit failure, cross-warehouse, hierarchy cycle, empty seal, RBAC và no-stack-trace contract.

Static verification đã thực hiện trong phiên triển khai:

```text
PHP syntax: 9/9 changed PHP files — no syntax errors
OpenAPI YAML: parse success
Postman JSON: parse success
Test-case JSON: parse success
```

Giới hạn evidence: connector không cung cấp private repository checkout/vendor runtime, do đó chưa chạy full PHPUnit và database-engine matrix trong phiên này. CI/QA phải chạy trên exact commit SHA và attach raw logs trước formal Done/Approved. Không ghi test runtime là PASS khi chưa có log thực tế.

## 10. Migration/rollback

Migration `2026_08_03_000210_harden_sku_component_container_contract.php`:

- tạo `sku_component` với UUID PK, FK, pair unique, reverse index, version;
- thêm `physical_code.product_id`, `physical_code.version`, product/status index;
- thêm `container.sealed_at`;
- thêm/backfill `container_item.item_key`, `version`, unique `(container_id,item_key)`;
- legacy row thiếu cả serial/product dùng `LEGACY:<container_item_id>` để không tạo null ambiguity;
- tự precheck duplicate `(container_id,item_key)` và fail-fast với message WWHN-172 trước khi tạo unique index;
- thêm DB check trên MySQL/PostgreSQL khi hỗ trợ; application guard vẫn là portable enforcement cho SQLite test.

Không tự động merge/xóa legacy duplicate. Production migration phải có reconciliation owner/evidence nếu precheck fail.

## 11. Handoff và gate còn lại

### FE1

- Lưu `version` từ response.
- Gửi `If-Match` cho update/revoke/seal.
- Tạo idempotency key mới cho mỗi user command; chỉ reuse khi retry đúng payload cũ.
- Với `409`, refresh/reconcile; không blind retry payload mới dưới key cũ.

### QA/BA

- Chạy `docs/testing/WWHN-172-test-cases.json` trên exact commit.
- Verify DB before/after và audit row trong cùng scenario.
- Chạy parallel requests cho same version, same key, physical-code và serial ownership.
- Xác nhận cycle/self-reference/cross-warehouse/sealed immutability.
- Formal sign-off phải reference exact commit, environment, migration state và raw logs.

### Blocker disposition

Không còn known blocker thuộc phần code BE1. Formal QA/BA runtime verification, CI result và sign-off vẫn pending; tài liệu này không thay thế approval gate.
