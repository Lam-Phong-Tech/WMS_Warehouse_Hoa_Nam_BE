# Gỡ cờ P0/P1/P2 — WWHN-37 / HN-MC-001

## Traceability

- Parent Temp Key: HN-MC-001
- Epic Temp Key: HN-EPIC-04
- Task cha: WWHN-37 — [SKU] Mô hình/API cho SKU, item, component, mã vật lý và container
- Role: BE2
- Assignee Placeholder: HN-BE-02
- Output: SKU/item backend (bản gỡ cờ)

Bản evidence gốc của task đã được chuyển vào `old_src/` (tiền tố `old_`) để tránh nộp nhầm — xem `old_src/old_00-summary.md` cho nội dung ban đầu trước khi có review flag.

## Phạm vi lượt gỡ cờ này

Chỉ xử lý các flag **thuộc thẩm quyền BE2 và tự làm được một mình** (code + tài liệu nội bộ). Các flag/phần flag cần xác nhận/hành động từ vai trò khác (FE1, BE1, QA1/QA2, Tester, BA, Lead, DevOps/DBA) được để lại cho người phụ trách tự xử lý, không note chi tiết trong bộ tài liệu này.

## Trạng thái từng flag

| ID | Sev | Trạng thái | Chi tiết |
|---|---|---|---|
| BE2-P1-01 | P1 | **Đã xử lý** | `01-trace-chain-extension.md` — mở rộng `/trace` sang `stock_movements` (theo `sku_id`, đúng granularity ERD hiện có), kèm test mới |
| BE2-P1-02 | P1 | **Đã xử lý (disposition)** | `02-import-scope-disposition.md` — CRUD `import_batches` hiện có đúng phạm vi WWHN-37 đã chốt; xử lý file nhập (template/rejected rows/validation report) ngoài AC hiện tại, không tự mở rộng scope |
| BE2-P1-03 | P1 | Đã khảo sát, chưa merge | `03-branch-sync-status.md` — dẫn chiếu báo cáo chi tiết đã có ở task WWHN-36, số liệu/kết luận không đổi, không tự merge |
| BE2-P1-04 | P1 | **Đã xử lý** | `04-products-to-sku-migration-guard.md` — migration guard mới, tự phát hiện và nâng cấp an toàn môi trường còn ở schema `products` cũ, không cần `migrate:fresh` |
| BE2-P1-05 | P1 | **Đã xử lý (phần tự làm được)** | `05-contract-pin-sku.md` — regenerate + SHA-256 pin cho `api-docs.json` và Postman collection |
| BE2-P1-06 | P1 | Ngoài phạm vi tự xử lý | Toàn bộ là review/retest từ BE1/QA1/QA2 — không note thêm |
| BE2-P2-01 | P2 | **Đã xử lý (không rewrite history)** | `06-commit-traceability-mapping.md` — mapping SHA hiện có sang entity/AC của WWHN-37, khuyến nghị convention commit cho các lần sau |

## Test evidence tổng hợp

- `php artisan test tests/Feature/ItemInstanceTest.php` — 8 passed / 27 assertions (gồm test mới cho `stock_movements` trong trace).
- `php artisan test` (toàn bộ) — 85 passed / 1 failed / 278 assertions. Lỗi duy nhất `StockDocumentIdempotencyTest::test_dashboard_quick_reports_period_totals_and_partial_documents` — pre-existing, không phải do thay đổi lượt này.
- `vendor/bin/pint --test` sạch cho toàn bộ file mới/sửa (`ItemInstanceController.php`, migration guard, `ItemInstanceTest.php`).

## Ghi chú

Không thực hiện: `git merge`/rebase `origin/m1`↔`m1-be-2`, rewrite/amend commit cũ, force-push, implement tính năng import file (template/rejected rows/validation report) — theo phạm vi đã xác định ở từng flag tương ứng.
