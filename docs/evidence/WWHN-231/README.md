# WWHN-231 — Evidence pack

Exact candidate: **BE `a16d1d6`** / **FE `7968fa8`**, branch `m1`.
Disposition: `docs/decision-records/DISP-STK-231-ledger-rescope.md` (`DISP-STK-231` v1.0.0).

## Bản đồ register → bằng chứng

| Register | Bằng chứng | Cách chạy lại |
|---|---|---|
| `DEV231-P0-01` không API sửa tồn ngoài post/reversal | `CONTRACT_NO_CHANGE.json` §openapi_runtime_negative_proof; test `::test_no_stock_mutating_route_exists_outside_canonical_posting_surface`, `::test_canonical_stock_write_surface_is_intact_and_guarded` | `node scripts/verify-wwhn-231-closure.mjs` |
| `DEV231-P0-02` scan stock-neutral | test `::test_inbound_scan_does_not_touch_movement_or_balance`, `::test_outbound_scan_and_label_preparation_are_stock_neutral`; `RECONCILIATION_RUN.json.stock_neutral` | `php vendor/bin/phpunit --filter Wwhn231StockNeutralLedgerTest` |
| `DEV231-P1-01` re-scope | `../../decision-records/DISP-STK-231-ledger-rescope.md` §3, §4 | — |
| `DEV231-P1-02` implementation identity | `EXACT_SHA.json` | `git show a16d1d6 --stat` |
| `DEV231-P1-03` chứng minh tối ưu | `BENCHMARK.md`, `BENCHMARK_RESULT.json`, `plans/A..J*.txt` | `node scripts/wwhn231-ledger-benchmark.mjs` |
| `DEV231-P1-04` đối soát movement ↔ balance | `RECONCILIATION_RUN.json`, `logs/inventory-reconcile-dry-run.log`; test `::test_detected_divergence_creates_incident_without_touching_stock` | `node scripts/run-wwhn-231-evidence.mjs` |
| `DEV231-P1-05` contract | `CONTRACT_NO_CHANGE.json` — **NO CONTRACT CHANGE** | — |
| `DEV231-P1-06` QA exact-SHA | `logs/phpunit-wwhn231-sqlite.log`, `BASELINE_ISOLATION.md` | `php vendor/bin/phpunit --filter Wwhn231StockNeutralLedgerTest` |
| `DEV231-P1-07` CI | `EXACT_SHA.json.ci` | GitHub Actions tại `a16d1d6` |
| `DEV231-P2-01` wording owner | `DISP-STK-231` §7; comment PM tại `docs/docs_task_jira/WWHN-231_SIGNOFF_COMMENTS.md` | — |

## Đọc theo thứ tự

1. `../../decision-records/DISP-STK-231-ledger-rescope.md` — vì sao ticket đổi phạm vi.
2. `BASELINE_ISOLATION.md` — cách tách nhiễu của nhánh dùng chung; đọc trước khi diễn giải bất kỳ con số test nào.
3. `BENCHMARK.md` — số đo và **index decision**.
4. `RECONCILIATION_RUN.json` — đối soát chạy thật, DB trước/sau.
5. `CONTRACT_NO_CHANGE.json` — vì sao FE/QA không phải đổi gì.

## Lưu ý trung thực

- `RECONCILIATION_RUN.json` chạy trên `hoa_nam_audit` đang **rỗng** → `mismatch = 0` ở đó là kết
  quả rỗng, đã ghi tại trường `reconciliation_caveat`. Bằng chứng đối soát trên dữ liệu có lệch
  cấy sẵn nằm trong bộ test.
- Bộ test chốt trên **sqlite** (driver của CI baseline). PostgreSQL hiện đỏ từ trước ở migration
  `2026_08_19_000100` (`WWHN-252`) với mọi test dùng `RefreshDatabase` — xem `BASELINE_ISOLATION.md` §3.
- Benchmark chạy trên máy dev có tải; vì vậy mỗi cấu hình kèm số block đọc, không chỉ mili-giây.
