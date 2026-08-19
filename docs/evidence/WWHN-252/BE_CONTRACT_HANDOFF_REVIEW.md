# WWHN-252 · FE252-P1-02R2 — Rà soát hợp đồng/handoff BE độc lập (WWHN-249 ↔ WWHN-252)

| Trường | Giá trị |
|---|---|
| Register item | FE252-P1-02R2 |
| Vai rà soát | BE / Tech Lead (độc lập với người viết code WWHN-249) |
| Ngày rà soát | 2026-08-19 |
| Nhánh canonical | `m1` (CẢ hai repo). Pin cũ `m1-be-2` trên Jira KHÔNG còn là nguồn canonical. |
| BE exact SHA | `fb911c6` (`fix(WWHN-252): publish App PV bat buoc If-Match theo version ho so + D-04 seed`) |
| FE exact SHA | `ce179400c1117cf000b670107fcca2817fd1a38e` (commit cuối chạm artifact App PV: `89deb8c` code + `ce17940` gỡ đỏ cổng nhánh) |
| Nguồn chuẩn | HN_SRS_FRD_v1.12_FULL §3, §8.1, §8.2, §12, §13 (Field mapping), Annex C, Annex D; HoaNam_ERD_Moi_Nhat bảng 51–57 |

## 1. Phạm vi và cách rà

Rà soát này trả lời đúng ba câu hỏi register đặt ra:

1. **API canonical của App PV là gì** (đường dẫn, version, security, header, error set)?
2. **SHA nào là bản được duyệt**, và artifact nào sinh ra từ SHA đó?
3. **Từng RedFlag của WWHN-249 giờ đứng ở đâu** — đã đóng, còn treo, hay là quyết
   định của người khác?

Cách rà: đọc hợp đồng (SRS/Annex C/ERD) → đối chiếu route table thật + OpenAPI
sinh từ code → chạy test. Không kết luận nào dựa trên mô tả Jira hay attachment.

## 2. API canonical của App PV (đã đối chiếu route table thật)

Base path canonical: **`/api/v1`**. OpenAPI runtime: `openapi 3.0.0`,
`info.title = WMS_HoaNam API`, `info.version = 2.0.0`.

> Lưu ý version: Annex C của SRS v1.12 nói tới "OpenAPI v1.7" như **phiên bản tài
> liệu hợp đồng**, còn `info.version = 2.0.0` là **phiên bản artifact runtime**.
> Hai con số này KHÔNG phải một, và đây là điểm đã gây hiểu nhầm trước đó. Nguồn
> sự thật để wire client là artifact runtime sinh từ code
> (`php artisan l5-swagger:generate` → `storage/api-docs/api-docs.json`).

| # | Method | Path | Security | If-Match | Nguồn |
|---|---|---|---|---|---|
| 1 | GET | `/api/v1/pv/products` | `security: []` (ẩn danh) | – | Annex C `listPvProducts` |
| 2 | GET | `/api/v1/pv/products/{skuId}` | `security: []` | – | Annex C `getPvProduct` |
| 3 | GET | `/api/v1/pv/industries` | `security: []` | – | Annex C `listPvIndustries` |
| 4 | GET | `/api/v1/pv/recommendations` | `security: []` | – | Annex C `listPvRecommendations` |
| 5 | PATCH | `/api/v1/pv/products/{id}/publish` | bearer + `pv.publish` | **bắt buộc** | Annex C `publishPvProduct` |
| 6 | GET | `/api/v1/pv-admin/resources/{resourceType}` | bearer + `pv.master.manage` | – | Annex C `listPvAdminResources` |
| 7 | POST | `/api/v1/pv-admin/resources/{resourceType}` | bearer + `pv.master.manage` | – | Annex C `createPvAdminResource` |
| 8 | PATCH | `/api/v1/pv-admin/resources/{resourceType}/{id}` | bearer + `pv.master.manage` | **bắt buộc** | Annex C `updatePvAdminResource` |
| 9 | GET | `/api/v1/pv/catalog`, `/api/v1/pv/tags` | `security: []` | – | Alias NGOÀI Annex C (D-02, đã ACCEPTED) |

Error set (khớp Annex C, đã đọc từ artifact runtime):

- Nhóm public (1–4): `400, 404, 429, 500` — **không có 401/403**, đúng với việc
  actor là ẩn danh (đây cũng là căn cứ của HN-PIN-09: App PV không thể là "hàng chờ").
- `publishPvProduct`: `400, 401, 403, 404, 409, 412, 422, 428, 500`.
- Nhóm `pv-admin`: `400, 401, 403, 404, 409, 412, 422, 428, 500`.

FE tiêu thụ đúng tập path này qua một chỗ duy nhất
(`src/modules/pv/services/pv-endpoints.ts`), tách khỏi `API_ENDPOINTS` để không ai
gắn interceptor auth vào endpoint public. `/pv/catalog` và `/pv/tags` **không**
được FE dùng — chúng chỉ tồn tại cho client cũ.

## 3. Hai lệch hợp đồng phát hiện trong lượt rà này (đã sửa, không phải nhận xét suông)

### 3.1 OpenAPI khai `If-Match` bắt buộc nhưng code cho phép thiếu header

`publishPvProduct` trước đó dùng `assertVersionMatchesIfProvided()` — thiếu header
thì **bỏ qua**. Nghĩa là:

- **428 PRECONDITION_REQUIRED là mã chết**: không đường code nào sinh ra được, dù
  Annex C khai nó trong error set.
- FE (trước `89deb8c`) **không gửi** header nào. Hai người mở SCR-PV-ADM-01 cùng
  lúc thì người bấm sau ghi đè trạng thái publish của người trước, im lặng.

Đã sửa tại `fb911c6`: header bắt buộc; token precondition là **version hồ sơ App
PV** (`PV_Profile.version`) theo §13 Field mapping — dòng
`SCR-PV-ADM-01 / published / Toggle` khai nguồn dữ liệu là `PV_Profile.published`.
Chi tiết và căn cứ: `docs/decision-records/DEC-API-IFMATCH-002-pv-publish-precondition.md`.

Kiểm chứng (sqlite, tại `fb911c6`):

```
php vendor/bin/phpunit --filter "Wwhn252PvContractTest"
→ 21 passed, 136 assertions

php vendor/bin/phpunit --filter "Wwhn249PvSurfaceGovernanceTest|MasterDataGovernanceTest|Wwhn252PvContractTest"
→ 38 passed, 217 assertions
```

### 3.2 D-04 — seed thiếu `pv.master.manage` cho Quản lý kho

SRS §3, dòng *"PV Industry/Tag/Product Mapping/Recommendation Rule manage"*, cho
**Quản lý kho mức A**. `AuthDatabaseSeeder` chỉ cấp key này cho `ADMIN`, nên
`WAREHOUSE_MANAGER` bị 403 ở `/pv-admin/resources` dù ma trận quyền nói được.

Đã sửa tại `fb911c6` và khoá bằng test hai chiều
(`Wwhn249PvSurfaceGovernanceTest::test_seed_grants_pv_permissions_exactly_as_srs_role_matrix`):
thiếu key thì đỏ, mà cấp nhầm cho vai chỉ-đọc cũng đỏ.

## 4. Disposition từng RedFlag của WWHN-249

| ID | Sev | Trạng thái sau rà | Căn cứ |
|---|---|---|---|
| DEV249-P1-01 | P1 | **READY_FOR_APPROVAL** — không đổi. Ma trận endpoint×permission sinh từ route table thật (231 operation), nhóm PV đủ 9 dòng. Còn chờ BA/BAS duyệt. | `docs/api-contract/HN-BE-API-003_ENDPOINT_PERMISSION_MATRIX.md` |
| DEV249-P1-02 | P1 | **ĐÓNG.** Canonical là `m1`; pin `m1-be-2` bỏ. SHA canonical ghi ở §1 file này. | Register WWHN-249 + commit `fb911c6`/`ce17940` |
| DEV249-P1-03 | P1 | **ĐÓNG.** Mặt public là CÓ CHỦ ĐÍCH và đóng khung fail-closed: thêm route public mà không khai vào 3 nơi (test governance, allowlist generator, ma trận) là đỏ. | `Wwhn249PvSurfaceGovernanceTest` |
| DEV249-P1-04 | P1 | **ĐÓNG + siết thêm.** Nửa authenticated đã đủ 403/409/412/422; lượt này bổ sung **428** vốn đang là mã chết. | §3.1 file này |
| DEV249-P1-05 | P1 | **CHUYỂN CHỦ SỞ HỮU → QA2 (WWHN-253).** Bằng chứng ma trận 4 khung nhìn × 8 trạng thái đã chạy tại FE `ce17940`. Vẫn KHÔNG thay UAT thiết bị thật của QA1. | `FE docs/evidence/WWHN-253/QA2_PV_RESPONSIVE_REPORT.md` |
| DEV249-P1-06 | P1 | **ĐÓNG phần BE.** Handoff = file này + ma trận + OpenAPI runtime + decision record; build ID = SHA ở §1. | file này |
| DEV249-P2-01 | P2 | **KHÔNG PHẢI VIỆC CỦA BE.** Sai assignee/description trên Jira — PM/BAS xử lý. | — |
| D-01 | P2 | **CÒN CHỜ BA.** Enforce fail-closed theo Annex C (một key `pv.master.manage`). Muốn có mức chỉ-xem thật thì phải THÊM key vào Annex C — đó là đổi contract, không phải sửa code. | Register WWHN-249 |
| D-02 | P2 | **ACCEPTED.** `/pv/catalog`, `/pv/tags` giữ cho client cũ, khai tường minh trong allowlist; FE không dùng. | `pv-endpoints.ts` |
| D-03 | P3 | **ACCEPTED.** `operationId` runtime sinh theo route (`patch_api_v1_pv_products_id_publish`) ≠ tên Annex C (`publishPvProduct`). Bảng ánh xạ giữ ở register WWHN-249. Đổi quy ước là thay đổi toàn cục bộ sinh contract. | — |
| D-04 | P1 | **ĐÓNG** tại `fb911c6` (xem §3.2). | `AuthDatabaseSeeder` + test |

## 5. Điều lượt rà này KHÔNG khẳng định

- Không phải UAT. Test BE là sqlite trên máy dev; hai cổng chỉ chạy trên
  PostgreSQL vẫn skip và **không được đọc là PASS**.
- Không khẳng định trạng thái của các luồng khác trên `m1` (nhánh này có nhiều
  phiên làm song song); chỉ khẳng định phần App PV tại đúng hai SHA đã pin.
- Không thay quyết định của BA cho D-01, và không thay QA1 cho UAT thiết bị thật.
