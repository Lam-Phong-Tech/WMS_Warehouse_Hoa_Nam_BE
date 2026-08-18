# WWHN-268 — Biên nhận phần backend của vòng sửa lỗi/retest

- Task: `WWHN-268` · Parent: `WWHN-70` · Nguồn QA: `HN-QA-005-ST01`
- Nhánh: `m1` (không tạo nhánh riêng)
- Vai trò giao nộp: **BE1 / BE2** (tương ứng `WWHN-266` / `WWHN-267`)
- Vai trò nhận và xác nhận độc lập: **QA1** (`WWHN-264`)
- Trạng thái: `ĐÃ GIAO PHẦN THUỘC WWHN-268 — CHỜ QA1 CHẠY ĐỘC LẬP`

## 1. Vì sao có tài liệu này

Register review của WWHN-268 nêu hai thiếu sót thuộc phía backend:

| Mục | Nội dung review |
|-----|-----------------|
| P1 | `WWHN-264` — QA1 chạy API/DB/integration regression cho stock/reversal/warranty/PV/report dependency |
| P1 | `WWHN-266`/`WWHN-267` — BE1/BE2 cung cấp contract/error/sample/data fix nếu blocker chạm API/data |

Vấn đề gốc không phải "chưa chạy test" mà là **không có gì để đối chiếu**: phạm
vi regression chỉ tồn tại dưới dạng câu chữ, nên câu "đã chạy regression" không
kiểm chứng được, và hợp đồng lỗi mà FE dựa vào để hiển thị trạng thái 401/403
không được test nào khoá lại trên đúng nhóm bề mặt đó.

## 2. Đã giao gì

| Artifact | Vai trò |
|----------|---------|
| `docs/evidence/WWHN-268/BE_REGRESSION_SCOPE.json` | Phạm vi regression 5 vùng (stock / reversal / warranty / pv / report), mỗi vùng trỏ tới class test có thật |
| `tests/Feature/Wwhn268RegressionContractTest.php` | Khoá phạm vi + khoá hợp đồng lỗi §8.2 trên 6 bề mặt bảo vệ + giữ PV catalog ẩn danh đọc được |
| `.github/workflows/wwhn-268-be-regression-closure.yml` | Chạy đúng bộ trên `GITHUB_SHA`, xuất JSON làm CI log truy được |

Bốn ca kiểm trong test hợp đồng:

1. `test_regression_scope_manifest_covers_every_area_with_real_test_classes` —
   thiếu vùng, vùng rỗng, hoặc trỏ tới class đã xoá đều FAIL. Phạm vi không thể
   âm thầm co lại giữa các lần chạy.
2. `test_protected_surfaces_return_401_auth_required_with_canonical_envelope` —
   `inbound-documents`, `outbound-documents`, `inventory/balances`,
   `reversal-documents`, `warranty-cases`, `reports/hub` khi chưa xác thực phải
   trả 401 kèm `success=false`, `error_code`, `meta.request_id`.
3. `test_protected_surfaces_return_403_permission_denied_for_a_role_without_the_permission`
   — vai trò `LEADERSHIP` (chỉ đọc báo cáo theo SRS §3) bị từ chối bằng mã nằm
   trong danh mục đã chốt và vẫn có `request_id`.
4. `test_pv_public_catalog_stays_anonymous_readable` — `GET /api/v1/pv/catalog`
   giữ nguyên là bề mặt công khai theo SRS §3/§8.4, không rơi vào default-deny.

## 3. Kết quả chạy tại chỗ

```
php artisan test --filter=Wwhn268RegressionContractTest
{"tool":"phpunit","result":"passed","tests":4,"passed":4,"assertions":70}
```

Baseline toàn bộ Feature suite trên cùng cây làm việc (driver sqlite):

```
php artisan test --testsuite=Feature
{"tool":"phpunit","result":"passed","tests":732,"passed":705,"assertions":8586,"skipped":27}
```

Ranh giới sự thật: đây là lượt chạy in-process trên DB test, **không** thay thế
lượt regression của QA1 trên build đã deploy. Log local Windows không đủ cho
Beta sign-off — bằng chứng trích dẫn ra ngoài phải lấy từ lượt chạy CI trên
`GITHUB_SHA`.

## 4. Điểm còn mở, không tự đóng

**BE268-D02 (P2) — danh mục mã lỗi 403 chưa hợp nhất.**

SRS/FRD v1.12 §8.2 liệt kê `PERMISSION_DENIED` / `DATA_SCOPE_DENIED` ở cột
**"Error code examples"**, trong khi cài đặt hiện hành dùng `ACCESS_DENIED` làm
mã mặc định cho default-deny (`App\Traits\ApiResponse`, `CheckPermission`, và
các mô tả OpenAPI đã chốt từ WWHN-254).

Đây là khác biệt **cách gọi tên trong contract**, không phải lỗi kỹ thuật: cột
trong SRS là ví dụ chứ không phải enum bắt buộc, nên không bên nào đang vi phạm.
BE **không tự đổi** mã trong phạm vi WWHN-268 vì việc đó chạm OpenAPI, FE và
nhiều bộ test đã chốt — cần BA + Tech Lead disposition.

Kiểm soát bù trong lúc chờ: FE nhận diện trạng thái không đủ quyền theo **HTTP
403** (`isNoPermissionError` trong `src/shared/api/api-error.ts`), không theo
chuỗi mã, nên khác biệt này không làm sai trạng thái UI của người dùng.

## 5. Việc còn lại thuộc vai trò khác

| Việc | Vai trò | Ticket |
|------|---------|--------|
| Chạy API/DB/integration regression trên build đã deploy, dùng `BE_REGRESSION_SCOPE.json` làm phạm vi tối thiểu, comment pass/fail | QA1 | `WWHN-264` |
| Disposition danh mục mã 403 (§8.2 vs cài đặt) | BA + Tech Lead | `WWHN-268` BE268-D02 |
| PR URL + exact SHA + build ID + CI log cho lượt chạy chốt | DEV + DevOps | `WWHN-268` W268-EXT-04 |
