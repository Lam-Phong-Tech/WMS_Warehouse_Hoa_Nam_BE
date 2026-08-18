# WWHN-300 — P0/P1/P2 Action Register

Phát hiện khi thực hiện `Backend GoLive check` trên branch `m1`, driver PostgreSQL 16.
Tất cả đều **reproduce được** bằng `php artisan migrate:fresh --seed --force` rồi
`php artisan migrate:rollback --force`.

## P0

### BE1-GL-P0-01 — `migrate:rollback` làm hỏng database, cutover không có đường lùi

| Trường | Nội dung |
|---|---|
| Hiện trạng trước fix | `migrate:rollback` dừng ở migration **94/139** với `SQLSTATE[42809] "pv_recommendation_rules" is not a view` |
| Hậu quả đo được | Sau khi rollback fail, `php artisan migrate` **cũng fail** (`SQLSTATE[0A000] cannot alter type of a column used by a view`). Database còn 78 bảng + 32 view, không phục vụ được và không migrate tiếp được — chỉ restore backup mới cứu được |
| Vì sao là P0 | AC của WWHN-80: *"nếu trigger xảy ra rollback theo authority"*. Operator làm đúng runbook (`migrate:rollback`) sẽ tự tay phá môi trường ngay trong cutover window |
| Root cause | Chuỗi migration có các bước **fail-forward** (`down()` rỗng có chủ ý). `2026_08_10_000750` đổi tên view thành `*__legacy_projection` rồi tạo bảng vật lý; `2026_08_10_000791` đổi chiều bảng/view legacy. Vì `down()` của chúng không làm gì, mọi `down()` cũ hơn vẫn giả định hình dạng schema ban đầu và vỡ khi gặp hình dạng mới |
| Fix | 1. `HoaNamErdSchema::dropRelation()` — drop theo **relkind thật** (view / materialized view / table), CASCADE trên PostgreSQL.<br>2. `HoaNamErdSchema::dropIndexIfExists()`, `dropColumnsCascade()` — chịu được index/cột đã bị migration sau thay thế.<br>3. `2026_08_10_000750.down()` — trả tên lại cho `*__legacy_projection` thay vì no-op.<br>4. `2026_08_10_000791.down()` — xóa compatibility view + trigger function mà chính nó tạo ra.<br>5. `2026_08_09_000400.down()` — drop theo relkind, CASCADE, và bổ sung `pv_product_images` bị bỏ sót.<br>6. 39 migration `create_*_table`: `down()` dùng teardown CASCADE thay cho `Schema::dropIfExists` trần |
| Verify | `migrate:rollback` = **140/140 DONE, exit 0**, chỉ còn bảng `migrations`; `migrate` + `db:seed` sau đó chạy lại sạch; `wms:golive-check` PASS |
| Regression guard | `tests/Unit/HoaNamErdSchemaDropRelationTest.php`, `tests/Feature/Wwhn300BackendGoLiveCheckTest.php`, CI `wwhn-300-backend-golive-check.yml` |
| Owner | BE1 — **CLOSED** |

## P1

### BE1-GL-P1-01 — Không có gate readiness cho backend tại thời điểm cutover

| Trường | Nội dung |
|---|---|
| Hiện trạng | WWHN-155 chỉ chứng minh DB sạch. Không có lệnh nào trả lời "backend này, DB này, cutover được chưa" — Go/No-Go phải dựa vào đọc log thủ công |
| Fix | `php artisan wms:golive-check` — 9 dimension (migration/schema/seed/security/route default-deny/idempotency/audit/reconciliation), exit code 1 khi FAIL, `--json` xuất evidence machine-readable |
| Verify | PASS trên PostgreSQL và SQLite; `Wwhn300BackendGoLiveCheckTest` 4/4 |
| Owner | BE1 — **CLOSED** |

### BE1-GL-P1-02 — Migration mới có `down()` rỗng không bị phát hiện

| Trường | Nội dung |
|---|---|
| Hiện trạng | 75/140 migration đang là fail-forward. Đây là **quyết định có chủ ý** để bảo vệ physical contract HN-WMS-ERD-DD-001, nhưng không ai đếm và không ai chặn khi con số tăng thêm |
| Fix | `migrations.reversible` liệt kê chính xác tập fail-forward; test pin ngưỡng ≤ 75 (**must not grow**), migration mới thêm `down()` rỗng sẽ làm đỏ CI kèm tên file |
| Disposition | Giữ WARN, **không** chuyển thành FAIL: rollback dữ liệu qua các bước này vốn không khả thi, authority là restore backup (ghi trong `CUTOVER_ROLLBACK_RUNBOOK.md`) |
| Owner | BE1 — **CLOSED (governed)** |

## P2

### BE1-GL-P2-01 — `pv_product_images` sống sót qua rollback

Bảng do `2026_08_09_000400.up()` tạo nhưng thiếu trong danh sách drop của `down()`.
Đã bổ sung; rollback nay chỉ để lại `migrations`. **CLOSED**.

### BE1-GL-P2-02 — Probe schema chỉ nhìn thấy bảng vật lý

`Schema::hasTable()` không thấy compatibility view, mà chiều bảng/view **đảo ngược giữa
PostgreSQL và SQLite** — probe theo bảng sẽ báo nhầm "thiếu aggregate" trên đúng một driver.
Đã thêm `HoaNamErdSchema::relationExists()` và dùng trong `schema.canonical_relations`. **CLOSED**.

### BE1-GL-P2-03 — `/api/documentation` không có middleware

`config/l5-swagger.php` để `middleware.docs = []`, nên Swagger UI mở công khai nếu package được
bật ở production. **Không sửa trong ticket này** — đây là quyết định môi trường của DevOps
(WWHN-298/WWHN-32): hoặc set `L5_SWAGGER_GENERATE_ALWAYS=false` + chặn ở tầng ingress, hoặc thêm
middleware auth. Đã ghi lại để có owner và disposition thay vì đóng im lặng. **OPEN — owner DevOps**.

## Không thuộc phạm vi BE1 (ghi để không mất dấu)

| ID | Nội dung | Owner |
|---|---|---|
| BE1-GL-DEP-01 | WWHN-298 (`HN-DEVOPS-005-ST01`) đang Blocked/Pending — chưa có environment URL, deploy log, backup/restore evidence để chạy check này trên UAT/production | DevOps |
| BE1-GL-DEP-02 | WWHN-299 (`[BE] Thực hiện/xác minh lần nạp dữ liệu cuối`) là bước nạp dữ liệu cuối; `wms:golive-check` cố ý **không** kiểm tra số lượng bản ghi nghiệp vụ vì reconciliation threshold thuộc ticket đó | BE2 |
