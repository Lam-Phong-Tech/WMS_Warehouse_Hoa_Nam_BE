# WWHN-249 — Bằng chứng mặt App PV (public vs authenticated)

Bản máy đọc: [`WWHN-249_CLOSURE_REGISTER.json`](./WWHN-249_CLOSURE_REGISTER.json).
Verifier fail-closed: `node scripts/verify-wwhn-249-closure.mjs`.

| Hạng mục | Giá trị |
| --- | --- |
| Canonical branch | `m1` (không có nhánh riêng cho task này) |
| BE SHA đóng R2 (chạm D-04 + test HTTP) | `c64fc2d91b665b967cfe2694e9969ae5aedada41` |
| FE SHA đối chiếu (commit cuối chạm code/test PV) | `1d4761ceb...` (`chore(format): chay prettier cho 17 file drift`) — không có thay đổi FE riêng cho vòng R2 vì implementation PV nằm ở WWHN-252 |
| BE SHA R1 (đã lỗi thời, KHÔNG dùng để sign-off) | ~~`231740fe991fea35005bdf195d6e4f88fa16569e`~~ |
| FE SHA R1 (đã lỗi thời, KHÔNG dùng để sign-off) | ~~`e02d4185608e7bb661481968a902b71c81386362`~~ |
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
| WAREHOUSE_MANAGER (QL kho) | có | có | **có** (từ commit `fb911c6`) | ✔ — **D-04 đã đóng**, xem §6 |
| WAREHOUSE_STAFF / APPROVER / KEEPER | không | không | không | ✔ (mức V*, chưa được gán) |
| WARRANTY_STAFF | không | không | không | ✔ (D) |
| LEADERSHIP | không | không | không | xem **D-01** |

**Một điểm BA còn phải quyết trước khi ký (D-04 và OP-09 đã đóng, xem §6/§7):**

- **D-01** — §3 cho NV kho `V*` và Lãnh đạo `V` trên dòng PV master, nhưng Annex C
  chỉ khai **một** key `pv.master.manage` cho cả ba operation (không có biến thể
  `.view`, khác với các dòng khác trong cùng bảng §3 — vd. `warehouse.view` /
  `warehouse.manage` — vốn luôn tách hai key). Hiện enforce theo Annex C
  (fail-closed): NV kho/Lãnh đạo không có quyền GET `/pv-admin/resources` dù bảng
  ghi V*/V; nhu cầu chỉ-xem dữ liệu **đã** publish được phục vụ bằng nhóm PV
  public (ẩn danh, không cần quyền). **Còn thiếu** là quyền xem dữ liệu **chưa**
  publish (bản nháp chưa publish trong `/pv-admin/resources`). Hai lựa chọn cho
  BA, không lựa chọn nào cần code ngay: (a) xác nhận hiện trạng đã đủ — vai trò
  "V*/V" được thoả mãn gián tiếp qua kênh public cho dữ liệu published, không cần
  xem riêng dữ liệu draft; hoặc (b) yêu cầu thêm key `pv.master.view` vào Annex C
  cho NV kho (khi được gán)/Lãnh đạo xem cả draft — đây là thay đổi contract,
  DEV sẽ làm sau khi có quyết định bằng văn bản.

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

Kết quả tại SHA `c64fc2d` (cập nhật R2, thay số liệu R1 phía trên):
`Wwhn249PvSurfaceGovernanceTest` + `Wwhn252PvContractTest` gộp — **sqlite 30/30
PASS**; PostgreSQL 29/30 PASS (1 lỗi driver-only ngoài phạm vi, xem §6).
`node scripts/verify-wwhn-249-closure.mjs` PASS tại đúng SHA này (9 route công
khai đã chốt, 8 operation Annex C khớp route table/OpenAPI/baseline contract).

FE (`WMS_Warehouse_Hoa_Nam_FE`, vitest): `src/test/wwhn-252-pv-contract.test.ts`
+ toàn bộ `src/modules/pv/**` — **4 file / 39 test PASS**. E2E QA2 responsive
(`e2e/wwhn-253-pv-qa2-responsive.spec.ts`, 28 test / 32 ô) đã PASS ở lần đóng
WWHN-252 vòng 2 (19/08); không chạy lại trong phiên này (không có thay đổi UI
PV kể từ đó, chưa re-run để tiết kiệm thời gian — nếu QA1 cần bằng chứng mới
nhất, chạy lại `npx playwright test wwhn-253-pv-qa2-responsive.spec.ts`).

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
| PR/commit + build ID trên Jira | **Còn thiếu** — thao tác của người; không có build/CI ID vì theo chỉ đạo hiện tại KHÔNG chạy GitHub Actions (ngân sách phút CI đã hết). Disposition thay thế: commit trực tiếp trên `m1` (không PR) tại SHA `c64fc2d`, cổng chạy **local** — xem §4. |
| `docs/api-evidence/BE_CONTRACT_PIN.json` (FE, pin OpenAPI toàn cục) | **Lệch** so với BE HEAD hiện tại (`npm run sync-be-openapi` báo hash mismatch) — đây là hạ tầng pin dùng CHUNG cho nhiều ticket (thấy cả ở WWHN-309), không riêng WWHN-249. Không re-pin trong phiên này để tránh đụng bằng chứng của ticket khác đang treo trên cùng file; không chặn sign-off WWHN-249 vì bằng chứng đặc thù PV (route table + OpenAPI runtime + test) đã tự chứng minh nhất quán ở §4. |

---

## 6. D-04 — ĐÃ ÁP (đóng)

`Modules/Auth/database/seeders/AuthDatabaseSeeder.php:221`, mảng
`ROLES['WAREHOUSE_MANAGER']['permissions']`, đã có `'pv.master.manage'` (commit
`fb911c6`, WWHN-252). Khoá bằng hai lớp test độc lập trong
`Wwhn249PvSurfaceGovernanceTest.php` (commit `c64fc2d`):

1. `test_seed_grants_pv_permissions_exactly_as_srs_role_matrix` — reflection trên
   `AuthDatabaseSeeder::ROLES`, khẳng định **đúng và chỉ đúng** `{ADMIN,
   WAREHOUSE_MANAGER}` có cả `pv.publish` lẫn `pv.master.manage`; vai khác (kể cả
   `WAREHOUSE_STAFF`, `LEADERSHIP`) có key thì đỏ ngay (không phải chỉ thiếu key
   mới đỏ).
2. `test_warehouse_manager_role_gets_200_and_warehouse_staff_role_gets_403_on_pv_admin`
   — bằng chứng HTTP thật qua `AuthDatabaseSeeder` thật (không phải permission rời
   rạc): đăng nhập vai `WAREHOUSE_MANAGER` → `GET /pv-admin/resources/industries`
   trả `200`; đăng nhập vai `WAREHOUSE_STAFF` → cùng route trả `403`.

> **Cập nhật 06/09/2026 — hai mục trên đã bị thay thế.** Tài liệu BA
> `taikhoantheorole_da_sua_v2` định nghĩa lại `WAREHOUSE_MANAGER` là vai **giám sát**
> (ma trận đánh ❌ ô "Master/SKU manage"; danh sách "Làm được" của vai không có mục
> App PV nào), nên `pv.publish` và `pv.master.manage` đã bị thu hồi khỏi vai này —
> chỉ còn `ADMIN` giữ. Trong repo:
> - mục 1 giữ nguyên tên, đổi kỳ vọng `{ADMIN, WAREHOUSE_MANAGER}` → `{ADMIN}`;
> - mục 2 đổi tên thành
>   `test_admin_role_gets_200_and_non_admin_roles_get_403_on_pv_admin`, với `ADMIN`
>   → `200` và cả `WAREHOUSE_MANAGER` lẫn `WAREHOUSE_STAFF` → `403`.
>
> Có một **xung đột nguồn** ở đây: SRS §3 vẫn xếp cả hai dòng App PV ở mức A cho actor
> "Quản lý kho" (lưu ý ánh xạ actor cũ trỏ actor đó về
> `WAREHOUSE_KEEPER`/`WAREHOUSE_APPROVER`, không phải `WAREHOUSE_MANAGER`; việc gán hai
> quyền PV cho `WAREHOUSE_MANAGER` đến từ WWHN-249 D-04).
>
> **BA đã chốt ngày 07/09/2026: giữ theo tài liệu mới — Quản lý kho không quản trị App
> PV.** SRS §3 là bản cũ ở điểm này. Xem `DELTA-06` trong
> `docs/evidence/WWHN-267/ROLE_BASELINE_PIN.json`.

Kết quả `Wwhn249PvSurfaceGovernanceTest` (9 test) tại SHA `c64fc2d`:
- sqlite: **9/9 PASS**.
- PostgreSQL (`hoa_nam_audit`, chạy độc lập tránh race với phiên khác): **9/9
  PASS**.

`Wwhn252PvContractTest` (21 test, cùng ngày): sqlite **21/21 PASS**; PostgreSQL
**20/21 PASS** — 1 lỗi `test_public_list_returns_only_active_published_sku_with_available_qty`
do trigger `hn_erd_guard_pv_publish_active_sku` chặn UPDATE `sku.status` sau khi
đã publish, tái hiện được khi chạy riêng lẻ (không phải race). Đây là hành vi
**chỉ có trên PostgreSQL**, thuộc code/test của WWHN-252 (không phải register
WWHN-249), **chưa sửa trong phiên này** — ngoài phạm vi 7 hạng mục P0/P1/P2 của
R2.

---

## 7. OP-09 — chính sách `available_qty` công khai (đã có phê duyệt)

SRS §15 (sửa đổi 12/08/2026, dòng 1593) — trích nguyên văn: *"REQ/BR/AC/TC – App
PV (OP-09). Anonymous public PV responses expose published SKU available_qty,
stock_status and data_as_of only; they exclude serial/item/container/movement/
cost and internal operational data."* Cùng nội dung tại REQ-SRS-018 (dòng 319):
*"PV hiển thị available_qty kho tổng, read-only"*, AC *"không lộ
item/container/movement/cost; không stock effect"*.

Implementation khớp đúng phạm vi này:
`Modules/Warehouse/app/Services/PvCatalogReadModel.php:174-192` tính
`available_qty` bằng `SUM(inventory_balances.available_qty) WHERE stock_status =
'AVAILABLE'` theo SKU (tổng kho, không phải theo từng kho/item), dòng 360 ép
`int` + `round()`, dòng 377-379 chỉ xuất `available_qty` / `stock_status` /
`stock_label` — không có field vận hành khác. `test_public_payload_never_leaks_operational_data`
(`Wwhn252PvContractTest.php:66`) khoá bằng test: response không được chứa
`warehouse_id/item_id/container_id/serial_number/movement/cost/reserved_qty/
on_hand_qty`.

**Kết luận**: đây không phải một quyết định còn treo — SRS đã phê duyệt tường
minh việc lộ `available_qty` chính xác cho SKU đã publish, và code hiện tại
khớp đúng phạm vi đó. Phần "chưa duyệt" mà R2 nêu chỉ là **chưa có ai trích dẫn
lại đúng dòng SRS này** trong hồ sơ Jira/PR — không phải thiếu quyết định
nghiệp vụ.
