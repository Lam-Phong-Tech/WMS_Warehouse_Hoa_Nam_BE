# WWHN-249 — Bằng chứng mặt App PV (public vs authenticated)

Bản máy đọc: [`WWHN-249_CLOSURE_REGISTER.json`](./WWHN-249_CLOSURE_REGISTER.json).
Verifier fail-closed: `node scripts/verify-wwhn-249-closure.mjs`.

| Hạng mục | Giá trị |
| --- | --- |
| Canonical branch | `m1` (không có nhánh riêng cho task này) |
| BE SHA khi dựng evidence | `231740fe991fea35005bdf195d6e4f88fa16569e` |
| FE SHA khi dựng evidence | `e02d4185608e7bb661481968a902b71c81386362` |
| OpenAPI runtime | `storage/api-docs/api-docs.json` — 231 operation, SHA-256 `f27e34ec08db1cada68b6a555e50fdcf13d8913921993d7cc62f759d4faaa593` |
| Ma trận endpoint×permission | `docs/api-contract/HN-BE-API-003_ENDPOINT_PERMISSION_MATRIX.md` (231 operation) |

> **Ranh giới sự thật.** Toàn bộ số liệu dưới đây là test tự động + artifact hợp
> đồng chạy trên máy dev. Đây **không** phải UAT và **không** thay QA1
> (WWHN-250). Hai test đỏ còn lại trên `m1` thuộc luồng khác (WWHN-236
> apply-adjustment/Postman và pin nguồn read model của WWHN-252) — chúng không
> nằm trong phạm vi WWHN-249.

---

## 1. DEV249-P1-02 — nguồn canonical

Jira đang pin `m1-be-2`, nhưng toàn bộ 8 operation App PV của Annex C tồn tại và
chạy được trên `m1`. Không có nhánh nào khác được tạo cho task này.

```
GET|HEAD   api/v1/pv/products                      | api,throttle:pv-public
GET|HEAD   api/v1/pv/products/{skuId}              | api,throttle:pv-public
GET|HEAD   api/v1/pv/industries                    | api,throttle:pv-public
GET|HEAD   api/v1/pv/recommendations               | api,throttle:pv-public
GET|HEAD   api/v1/pv/catalog                       | api,throttle:pv-public      (alias lịch sử)
GET|HEAD   api/v1/pv/tags                          | api,throttle:pv-public      (alias lịch sử)
PATCH      api/v1/pv/products/{id}/publish         | api,auth:api,permission:pv.publish
GET|HEAD   api/v1/pv-admin/resources/{resourceType}      | api,auth:api,permission:pv.master.manage
POST       api/v1/pv-admin/resources/{resourceType}      | api,auth:api,permission:pv.master.manage
PATCH      api/v1/pv-admin/resources/{resourceType}/{id} | api,auth:api,permission:pv.master.manage
```

Việc cần người làm: đổi source pin trên Jira sang `m1` + SHA của commit đóng task.

---

## 2. DEV249-P1-01 — ma trận actor / use-case / permission

Nguồn: SRS §3 (ma trận vai trò) + Annex C (permission theo operation) + seeder
`Modules/Auth/database/seeders/AuthDatabaseSeeder.php`.

| Use-case (SRS §3) | Admin | QL kho | NV kho | NV bảo hành | Lãnh đạo | App PV | Permission key | Enforce tại |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Xem sản phẩm đã publish + tồn khả dụng | A | A | A | A | A | **A (ẩn danh)** | _không_ | `throttle:pv-public`, không `auth:api` |
| Publish / gỡ publish sản phẩm | A | A | V* | V* | V | D | `pv.publish` | `PATCH /pv/products/{id}/publish` |
| Quản lý Industry / Tag / Product Mapping / Recommendation Rule | A | **A** | V* | D | V | Published only | `pv.master.manage` | `/pv-admin/resources/*` |

Đối chiếu seed thực tế (đọc từ `AuthDatabaseSeeder`):

| Role | `sku.manage` | `pv.publish` | `pv.master.manage` | Khớp §3? |
| --- | --- | --- | --- | --- |
| ADMIN | có | có | có | ✔ |
| WAREHOUSE_MANAGER (QL kho) | có | có | **KHÔNG** | ✘ — xem **D-04** |
| WAREHOUSE_STAFF / APPROVER / KEEPER | không | không | không | ✔ (mức V*, chưa được gán) |
| WARRANTY_STAFF | không | không | không | ✔ (D) |
| LEADERSHIP | không | không | không | xem **D-01** |

**Hai điểm BA phải xử lý trước khi ký:**

- **D-01** — §3 cho NV kho `V*` và Lãnh đạo `V` trên dòng PV master, nhưng Annex C
  chỉ khai **một** key `pv.master.manage` cho cả ba operation. Hiện enforce theo
  Annex C (fail-closed); nhu cầu chỉ-xem được phục vụ bằng nhóm PV public. Muốn
  có mức xem thật trên dữ liệu **chưa** publish thì phải thêm `pv.master.view`
  vào Annex C — đó là đổi contract, không phải sửa code.
- **D-04** — seed thiếu `pv.master.manage` cho `WAREHOUSE_MANAGER` trong khi §3
  ghi mức `A`. Hệ quả: QL kho gọi `/pv-admin/resources` bị `403` dù ma trận nói
  được. Bản vá đúng một dòng, nằm ở §6 dưới đây; **chưa áp** vì đây là thay đổi
  cấp quyền, phải có người duyệt.

---

## 3. DEV249-P1-03 / P1-04 — hai nửa của App PV

### 3.1. Vì sao nửa public được phép ẩn danh

Không phải bỏ sót phân quyền, mà là hợp đồng viết sẵn:

- SRS §2 `SRC-008` + `DR-PLAT-001`: "App PV anonymous", "MVP/Beta App PV = Web App
  responsive read-only".
- SRS §3: actor "Người xem App PV" = "Read-only published product and available
  quantity"; mọi hành động ghi của nhóm PV đều là `D` với actor này.
- SRS §8.1 nhóm PV: required permission = `PUBLIC_ANONYMOUS / pv.publish / pv.master.manage`.
- SRS §12 bảng Security: "Public GET /pv/products, /pv/products/{sku_id},
  /pv/industries, /pv/recommendations must explicitly override with anonymous
  security (`security: []`), read-only DTO/rate-limit/cache/anti-abuse controls.
  PATCH publish remains bearer + `pv.publish`."

### 3.2. Bốn ràng buộc bù cho việc ẩn danh

| Ràng buộc | Hiện thực | Khoá bằng |
| --- | --- | --- |
| Rate limit / anti-abuse (để 429 là mã có thật) | `throttle:pv-public`, ngưỡng ở `config/rate_limit.php` | `Wwhn249PvSurfaceGovernanceTest::test_every_anonymous_pv_route_is_rate_limited` |
| DTO chỉ đọc, không lộ item/container/movement/cost | whitelist tại `PvCatalogReadModel::presentMany()` | `Wwhn252PvContractTest::test_public_payload_never_leaks_operational_data` |
| Cache ≤ 60s + `data_as_of` (NFR-SRS-016) | `config/pv.php` `max_staleness_seconds` | `Wwhn252PvContractTest::test_public_response_exposes_freshness_meta` |
| Không stock effect (AC-SRS-018) | endpoint chỉ đọc | `Wwhn249PvSurfaceGovernanceTest::test_public_pv_traffic_has_no_stock_effect` |

### 3.3. Guard fail-closed cho mặt công khai

`Wwhn249PvSurfaceGovernanceTest::test_anonymous_api_surface_matches_the_declared_contract`
so **toàn bộ** route API không có `auth:` với danh sách đã chốt. Thêm bất kỳ
endpoint public nào — kể cả ngoài PV — mà không khai báo ở cả ba nơi dưới đây sẽ
làm test đỏ:

1. `tests/Feature/Wwhn249PvSurfaceGovernanceTest::DECLARED_ANONYMOUS_ROUTES`
2. `scripts/generate-endpoint-permission-matrix.mjs` → `PUBLIC_ALLOWLIST`
3. `docs/api-contract/HN-BE-API-003_ENDPOINT_PERMISSION_MATRIX.md`

### 3.4. Ánh xạ operationId Annex C ↔ OpenAPI runtime (D-03)

| Annex C | Runtime | Security |
| --- | --- | --- |
| `listPvProducts` | `get_api_v1_pv_products` | `[]` |
| `getPvProduct` | `get_api_v1_pv_products_skuid` | `[]` |
| `listPvIndustries` | `get_api_v1_pv_industries` | `[]` |
| `listPvRecommendations` | `get_api_v1_pv_recommendations` | `[]` |
| `publishPvProduct` | `patch_api_v1_pv_products_id_publish` | `bearerAuth` + `pv.publish` |
| `listPvAdminResources` | `get_api_v1_pv__admin_resources_resourcetype` | `bearerAuth` + `pv.master.manage` |
| `createPvAdminResource` | `post_api_v1_pv__admin_resources_resourcetype` | `bearerAuth` + `pv.master.manage` |
| `updatePvAdminResource` | `patch_api_v1_pv__admin_resources_resourcetype_id` | `bearerAuth` + `pv.master.manage` + `If-Match` |

---

## 4. DEV249-P1-05 — bằng chứng chạy thật (không thay QA1)

Lệnh (SQLite in-memory, driver mặc định của `phpunit.xml`):

```bash
php vendor/bin/phpunit --filter "Wwhn249PvSurfaceGovernanceTest|Wwhn252PvContractTest|DocumentationBaselineContractTest|SwaggerRouteSyncTest|MasterDataGovernanceTest"
```

Kết quả tại SHA pin: **47 test — 45 PASS, 0 FAIL, 2 SKIP**, 2.827 assertion.
Hai test skip là cổng chỉ chạy trên PostgreSQL; **không** được đọc là PASS.

Full suite BE cùng thời điểm: 922 test / 891 pass / 28 skip / 3 fail — cả 3 fail
đều **ngoài** phạm vi WWHN-249 (2 thuộc WWHN-236 + pin read model WWHN-252, 1 đã
được đóng trong phiên này là `SwaggerRouteSyncTest`).

Điểm QA1 vẫn phải tự chứng minh trên build thật tại exact SHA: auth, assignment,
positive/negative filter, field của detail, `403`, `409`, pagination và
no-stock-side-effect.

---

## 5. DEV249-P1-06 — artifact bàn giao

| Artifact | Trạng thái |
| --- | --- |
| OpenAPI runtime | Sinh lại; 4 GET public khai `security: []`, phần còn lại `bearerAuth` |
| Header `If-Match` của `updatePvAdminResource` | **Đã sửa**: `RouteSyncProcessor` trước đây chỉ quét helper 1 tầng nên bỏ sót header bắt buộc; nay quét đệ quy → contract khai đúng |
| Ma trận endpoint×permission | Sinh lại, 231 operation |
| Baseline contract | Đăng ký thêm 5 operation PV mới (`docs/contracts/HoaNam_Current_Baseline_Contract.json`) — nếu không, cổng `DocumentationBaselineContractTest` đỏ vì surface phình mà không ai review |
| PR/commit + build ID trên Jira | **Còn thiếu** — thao tác của người |

---

## 6. Bản vá còn treo cho D-04 (chưa áp)

`Modules/Auth/database/seeders/AuthDatabaseSeeder.php`, mảng
`ROLES['WAREHOUSE_MANAGER']['permissions']`, ngay sau `'pv.publish',`:

```php
// SRS §3 dòng "PV Industry/Tag/Product Mapping/Recommendation Rule manage" cho
// Quản lý kho mức A; seed hiện chỉ cấp pv.master.manage cho ADMIN nên QL kho bị
// 403 dù ma trận quyền nói được.
'pv.master.manage',
```

Sau khi áp: chạy lại seeder và bổ sung một test RBAC khẳng định role
`WAREHOUSE_MANAGER` gọi `GET /api/v1/pv-admin/resources/industries` trả `200`.
