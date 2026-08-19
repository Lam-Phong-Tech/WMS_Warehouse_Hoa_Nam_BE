# DEC-API-IFMATCH-002 — Precondition của `publishPvProduct` là version HỒ SƠ App PV

| Trường | Giá trị |
|---|---|
| Decision ID | DEC-API-IFMATCH-002 |
| Ticket | WWHN-252 (FE252-P1-08) |
| Nguồn chuẩn | HN_SRS_FRD_v1.12_FULL — §8.2 (HTTP/Error contract), §13 Field mapping (SCR-PV-ADM-01), Annex C (operationId `publishPvProduct`) |
| Ngày chốt | 2026-08-19 |
| Trạng thái | ĐÃ CHỐT |
| Liên quan | [DEC-API-IFMATCH-001](DEC-API-IFMATCH-001-remove-dead-route-middleware.md), HN-PIN-09 (FE) |

## 1. Vấn đề

`PATCH /api/v1/pv/products/{sku_id}/publish` được Annex C khai **có header
`If-Match`** và error set `400, 401, 403, 404, 409, 412, 422, 428, 500`. Trước
quyết định này:

- BE coi `If-Match` là **tuỳ chọn** (`assertVersionMatchesIfProvided` trả `null`
  khi thiếu header) ⇒ **428 là mã chết**, không đường nào sinh ra được.
- FE **không gửi** header nào cả (`setPvProductPublished` chỉ gửi body).
- Hệ quả thực tế: hai người mở SCR-PV-ADM-01 cùng lúc, người bấm sau ghi đè
  trạng thái publish của người bấm trước mà không ai nhận được cảnh báo.

Câu hỏi phải chốt: **version nào là precondition** — `sku.version` hay version
hồ sơ App PV (`pv_product_profile.version`)?

## 2. Quyết định

1. `If-Match` là **BẮT BUỘC**. Thiếu header ⇒ `428 PRECONDITION_REQUIRED`,
   trạng thái publish không đổi.
2. Token precondition là **version HỒ SƠ App PV của SKU**, không phải
   `sku.version`.
3. Quy ước giá trị:
   - `If-Match: 0` — SKU **chưa có** hồ sơ App PV (lần publish đầu tiên).
   - `If-Match: N` (N ≥ 1) — version hồ sơ hiện tại.
4. Sai định dạng (không phải số nguyên không âm) ⇒ `422 INVALID_VERSION`.
   Lệch version ⇒ `412 STALE_VERSION` kèm `error.details.expected_version` và
   `error.details.current_version`.
5. Mỗi lần publish/unpublish **thành công** tăng version hồ sơ lên 1 và trả về
   `data.pv_profile_version` (version SAU thao tác).

## 3. Vì sao là version hồ sơ, không phải `sku.version`

| # | Căn cứ | Nội dung |
|---|---|---|
| 1 | SRS §13 Field mapping, dòng `SCR-PV-ADM-01 / published / Toggle` | Cột nguồn dữ liệu ghi **`PV_Profile.published`**. Công tắc thao tác trên hồ sơ, nên precondition phải là version của chính dòng đó. |
| 2 | Annex C `listPvAdminResources` | Response của `product-profiles` trả `version` của **hồ sơ**. Đây là con số DUY NHẤT FE nhìn thấy trên dòng đang bấm — dùng `sku.version` thì FE không có đường nào lấy được mà không gọi thêm API khác. |
| 3 | ERD bảng 51 | `pv_product_profile` có `version` và UX(sku_id): một hồ sơ / một SKU, nên version hồ sơ định danh được trạng thái publish. |
| 4 | Tách quyền §3 | `pv.publish` (publish) và `sku.manage` (sửa SKU) là hai quyền khác nhau. Nếu dùng `sku.version` thì một lần `updateSku` hợp lệ sẽ làm hỏng precondition của người đang publish dù hai việc không liên quan. |

## 4. Ảnh hưởng hai driver

Publish ghi `sku.published_to_app`; hồ sơ App PV được tạo/đồng bộ bởi trigger ở
**cả hai** driver — `hn_legacy_sku_write` (PostgreSQL, migration
`2026_08_16_121000`) và `hn_pv_profile_sync_on_sku_*` (sqlite, migration
`2026_08_19_000200`). Vì vậy sau lệnh update, mọi SKU đang publish đều đã có hồ
sơ để tăng version. Việc đọc/ghi bảng hồ sơ đi qua
`Modules/Warehouse/app/Support/PvRelations.php` (tên bảng và tên cột khoá ngoại
khác nhau giữa hai driver).

Trường hợp biên duy nhất không có dòng hồ sơ: unpublish một SKU **chưa từng**
publish. Khi đó version giữ nguyên `0` và `If-Match: 0` là giá trị đúng.

## 5. Bằng chứng (test)

| Kịch bản | Test |
|---|---|
| Thiếu header ⇒ 428, không đổi trạng thái | `tests/Feature/Wwhn252PvContractTest::test_publish_without_if_match_returns_428_and_changes_nothing` |
| Version lệch ⇒ 412 + expected/current | `…::test_publish_with_stale_profile_version_returns_412` |
| Version tăng + trả `pv_profile_version` + khớp số FE đọc từ danh sách | `…::test_publish_bumps_pv_profile_version_and_returns_it` |
| `If-Match` sai định dạng ⇒ 422 INVALID_VERSION | `…::test_publish_with_non_numeric_if_match_returns_422` |
| FE gửi đúng header trên dây (service → http → axios) | FE `src/modules/pv/services/pv-publish-if-match.contract.test.ts` |
| UI truyền version của đúng dòng + 412/428 ⇒ tải lại danh sách | FE `src/modules/pv/pages/PvAdminPage.test.tsx` |

## 6. Cái KHÔNG thay đổi

- `updateSku` (`PATCH /skus/{sku_id}`) giữ nguyên hành vi cũ: `If-Match` vẫn là
  tuỳ chọn theo baseline đang chạy — thay đổi ở đây chỉ khoanh vào publish App PV.
- Publish **không** đụng `sku.version`.
- Ranh giới quyền không đổi: `published` vẫn là trường chỉ đọc ở
  `/pv-admin/resources/product-profiles` (xem `PvAdminResourceController`).
