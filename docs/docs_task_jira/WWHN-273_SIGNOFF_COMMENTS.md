# WWHN-273 — Comment xác nhận / sign-off theo vai trò

Mỗi vai trò **một comment duy nhất**, gộp đủ các đầu P0/P1/P2 thuộc vai trò đó.
Nội dung là **xác nhận (đọc + duyệt)**, không giao việc mới.

Candidate đang xác nhận: BE `WMS_Warehouse_Hoa_Nam_BE` `m1` — `1655d0b78fa3cd082632e9b2d8510907abce0874`.

Chỉ dán khi workflow `WWHN-273 Backend Release Sign-off` đã xanh trên đúng SHA đang xét;
không dùng lại artifact/kết quả PASS của SHA hay task khác.

---

## Comment 1 — BE1 (chủ task WWHN-273)

Tôi xác nhận đã khắc phục và kiểm chứng phần BE của WWHN-273 trên nhánh `m1`:

- **W273-P0-01 (P0)** — Gate release chỉ chạy `migrate:fresh --seed` một chiều nên không chứng minh được điều mà task cha WWHN-72 yêu cầu là "diễn tập rollback". Đã thêm vào gate exact-SHA, chạy trên PostgreSQL 16: `migrate:fresh --seed` → `migrate:reset` (gỡ toàn bộ chain) → `migrate` → `db:seed`.
- **W273-P0-02 (P0)** — `App\Support\HoaNamErdSchema::parse()` đọc `docs/HoaNam_ERD_Moi_Nhat.md` **lúc chạy migration**, nên một thay đổi tên entity trong tài liệu làm vỡ `migrate:fresh --seed` trên DB sạch (tái hiện được: `ERD key field missing on defect_catalogs: id` tại `2026_08_10_0007695`, và seed lỗi `null value in column "id"`). Gate mới chạy migration + rollback drill trên DB PostgreSQL sạch nên chặn được đúng lớp lỗi này trước Beta.
- **W273-P0-03 (P0)** — Suite phpunit chạy `RefreshDatabase` trên **chính DB của job**, để lại DB đã migrate nhưng chưa seed; nếu smoke chạy ngay sau đó thì `/api/v1/health` trả 503 `HEALTH_CHECK_DEGRADED` và smoke đăng nhập không có identity. Bước smoke vì vậy nạp lại `migrate:fresh --seed` trước khi serve, và thứ tự này bị khoá bằng test.
- **W273-P1-01 (P1)** — Gate chỉ kiểm health trong tiến trình phpunit, trong khi Beta/UAT poll qua HTTP. Đã thêm smoke trên runtime đã `serve`: `/api/v1/health` = 200 `status=ok`, `/api/health` legacy = 404, `/api/v1/skus` không token = 401.
- **W273-P1-02 (P1)** — Gate không chạy readiness check. Đã thêm `php artisan wms:golive-check` kèm `golive-check.json` vào artifact; `TRUSTED_PROXIES` và `APP_BUILD_SHA=${{ github.sha }}` được pin trong workflow để kết quả tất định và bằng chứng gắn đúng commit đang xét.
- **W273-P1-04 (P1)** — AC của WWHN-72 yêu cầu smoke `auth/master/inbound/outbound/inventory` nhưng gate không gọi endpoint nghiệp vụ nào qua HTTP. Đã thêm: sau `POST /api/v1/auth/login`, các endpoint `skus`, `inbound-documents`, `outbound-documents`, `inventory/balances`, `reports/hub` phải trả 200 trên DB đã migrate + seed.
- **W273-P1-03 / W273-P2-01 (P1/P2)** — WWHN-273 không có tài liệu evidence trong source và hợp đồng gate chỉ nằm trong một file YAML. Đã thêm `docs/docs_task_jira/WWHN-273.md` (map AC ↔ bằng chứng + register P0/P1/P2) và `tests/Feature/Wwhn273ReleaseSignoffContractTest.php` — khoá các bước bắt buộc, khoá route mà smoke gọi, và chính nó nằm trong suite gate chạy.

Bằng chứng đã chạy trên đúng cây mã này (18/08/2026): focused release suite **28 test / 297 assertion PASS**; trên PostgreSQL 16 với DB sạch: `migrate:fresh --seed` PASS, `migrate:reset` PASS, `migrate` + `db:seed` PASS, `wms:golive-check` không có FAIL thuộc schema/seed/route-default-deny; smoke HTTP trả đúng 200 / 404 / 401 và cả 5 endpoint nghiệp vụ đều 200.

Ranh giới sự thật: đây là bằng chứng **BE-controlled** trên DB của CI theo đúng SHA, **không** thay thế QA1 (WWHN-275) và **không** thay thế DevOps deploy/backup/rollback trên môi trường thật (WWHN-272).

---

## Comment 2 — DevOps (DO)

Tôi xác nhận đã đọc và duyệt phần hạ tầng liên quan WWHN-273, không phát sinh việc mới cho task này:

- Health canonical là `/api/v1/health` (`routes/api.php` khai `Route::prefix('v1')` → `Route::get('health', ...)`, tên route `api.v1.health`); `hn-devops-ci-cd.yml` smoke đúng path này và gate WWHN-273 chặn `/api/health` legacy ở cả bước guard lẫn smoke HTTP.
- `wms:golive-check` chạy trong gate với `TRUSTED_PROXIES` và `APP_BUILD_SHA` được inject — đúng cách DevOps sẽ inject khi dựng image, nên bằng chứng đọc được SHA từ chính backend đang chạy.
- Cảnh báo `migrations.reversible` (nhiều migration có `down()` fail-forward) là **có chủ đích**: quyền rollback quá mốc fail-forward thuộc về restore-from-backup theo `docs/evidence/WWHN-300/CUTOVER_ROLLBACK_RUNBOOK.md`, không phải `migrate:rollback`.
- Bằng chứng deploy Beta / backup / restore / diễn tập rollback trên môi trường thật là hạng mục **độc lập của WWHN-272**, phải tự đính kèm build/SHA/môi trường riêng; không dùng artifact WWHN-273 để thay thế.

---

## Comment 3 — PM / PO

Tôi xác nhận **phần "kiểm kê" trong AC của WWHN-72**: baseline hiện hành (SRS/FRD v1.12, ERD mới nhất, Techstack/Dev Guide) **không** đưa Stocktake / variance / adjustment tổng quát vào phạm vi MVP — đường sửa sai là đối soát + Reversal append-only. Vì vậy câu chữ "smoke kiểm kê" trong mô tả parent là wording cũ; BE **không** được tự sinh API/màn hình ngoài baseline.

Tôi duyệt cách xử lý fail-closed theo baseline hiện hành và ghi nhận wording của parent cần chỉnh lại. Không phát sinh việc mới cho WWHN-273.

---

## Comment 4 — QA1

Tôi xác nhận đã đọc và duyệt ranh giới bằng chứng của WWHN-273: artifact của task này (`release-signoff.txt`, `SHA256SUMS`, `golive-check.json`, `health.json`, `served-smoke.txt`) là bằng chứng **BE-controlled** sinh trên DB của CI theo đúng SHA, hợp lệ cho đúng phạm vi migration / rollback drill / smoke backend mà BE1 chịu trách nhiệm.

Tôi xác nhận release smoke độc lập (WWHN-275) vẫn là hạng mục riêng, phải chạy trên môi trường triển khai thật và tự đính kèm bằng chứng; không dùng artifact WWHN-273 để thay thế và không tái sử dụng kết quả PASS từ SHA khác.

Trong phạm vi BE1 của WWHN-273, tôi không thấy P0/P1 còn tồn đọng.

---

## Không ký ở đây — còn phải làm việc thật

| Mục | Vì sao không ký | Owner |
|---|---|---|
| WWHN-272 — deploy Beta, backup, restore, diễn tập rollback trên môi trường thật | Cần thực thi + evidence hạ tầng, không phải đọc + duyệt | DevOps (Minh Tân Lê) |
| WWHN-275 — release smoke độc lập | QA phải tự chạy trên môi trường đã deploy | QA1 (DT Soi) |
| Chỉnh wording "kiểm kê" trong mô tả WWHN-72 | Sửa nội dung task cha là việc của người giữ backlog | PM/PO |
