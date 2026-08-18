# WWHN-234 — Execution log (lệnh đã chạy thật, không phải kế hoạch)

Baseline: BE `696868ce44ccccca51884c3c1ac7e9f41fd405b8` · FE `48f7ff096ee6c570ff3a27c93f1d47b4e3b8595e`
Ngày chạy: 2026-08-18. Driver test BE: sqlite (baseline CI).

| # | Lệnh | Kết quả |
|---|---|---|
| 1 | `php artisan test` (toàn bộ BE) | **934 test · 906 pass · 28 skip · 0 fail · 11.628 assertion** (415,9 s) |
| 2 | `php artisan test --filter "Wwhn234"` | **8 test · 8 pass · 68 assertion** |
| 3 | `php artisan test --filter "Wwhn207InboundReversalFlowTest\|Flow05InventoryControlAlignmentTest\|Wwhn289StockInvariantProofTest\|PostIssueConcurrencyBehaviorTest\|PostReceiptConcurrencyBehaviorTest\|Wwhn234ConflictClassContractTest"` | **34 test · 34 pass · 768 assertion** |
| 4 | `npx vitest run src/shared/api/concurrency-contract.test.ts` | **13 test · 13 pass** |
| 5 | `npx vitest run src/modules/inbound src/modules/shipping src/shared/api` | **9 file · 65 test · 65 pass** |
| 6 | `npx tsc --noEmit` (FE) | **0 lỗi** |
| 7 | `node scripts/generate-error-code-catalog.mjs` | 248 error code; `CONCURRENCY_CONFLICT` 8 hit, `DOWNSTREAM_DEPENDENCY_EXISTS` 4 hit |
| 8 | `node scripts/verify-wwhn234-closure.mjs` | **24/24 bất biến PASS** |
| 9 | `php artisan route:list --json` | 239 route · **0** route kiểm kê |
| 10 | `php -l` trên 11 file PHP đã sửa | không lỗi cú pháp |

## Ghi chú trung thực về môi trường

- 28 test `skip` của lệnh 1 là parity PostgreSQL-only, đã tồn tại từ trước task này
  (xem `reference_be_test_drivers`); không phải hệ quả của WWHN-234.
- Trong phiên làm việc này, `m1` có **một phiên khác chạy song song** trên cùng working
  tree. Một lần chạy trung gian của lệnh 3 báo 22 fail do file
  `CurrentBaselineReversalService.php` đang bị ghi dở ở thời điểm đọc (ParseError). Sau khi
  file ổn định, `php -l` sạch và cả 34 test xanh. Kết quả trong bảng là lần chạy trên cây
  ổn định.
- `CurrentBaselineFlowContractSourceTest::test_warranty_label_and_pv_rules_are_pinned` từng
  đỏ trong một lần chạy trung gian vì thay đổi `WWHN-252` (`pvCatalog` chuyển sang read
  model canonical). Đây là ticket khác, không thuộc phạm vi WWHN-234; ở lệnh 1 nó đã xanh
  sau khi phiên kia hoàn tất thay đổi.

## Cách reviewer tái lập

```bash
git -C <BE_REPO> checkout 696868ce44ccccca51884c3c1ac7e9f41fd405b8
node scripts/verify-wwhn234-closure.mjs        # kỳ vọng exit 0
php artisan test --filter "Wwhn234"            # kỳ vọng 8/8

git -C <FE_REPO> checkout 48f7ff096ee6c570ff3a27c93f1d47b4e3b8595e
npx vitest run src/shared/api/concurrency-contract.test.ts   # kỳ vọng 13/13
```
