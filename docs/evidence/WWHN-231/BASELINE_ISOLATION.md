# WWHN-231 — Cô lập kết quả test khỏi nhiễu của nhánh dùng chung

`m1` đang được nhiều phiên làm việc song song (cùng ngày 18/08/2026 có `WWHN-233`, `WWHN-234`,
`WWHN-237`, `WWHN-268`, `WWHN-299`, `WWHN-330`). Vì vậy một kết quả "full suite đỏ" trên
working tree **không** tự nó nói lên điều gì về `WWHN-231`. Tài liệu này ghi lại phép đo đã
dùng để tách hai nguồn nhiễu.

## 1. Bộ khoá phạm vi của WWHN-231

| Môi trường | Lệnh | Kết quả |
|---|---|---|
| sqlite (baseline CI) | `php vendor/bin/phpunit --filter Wwhn231StockNeutralLedgerTest` | **9/9 PASS**, 54 assertion |
| sqlite — nhóm báo cáo/ledger liên đới | `php vendor/bin/phpunit --filter "Xnt|XNT|Report|Wwhn231"` | **134/134 PASS**, 2223 assertion |

## 2. Full suite trên working tree — và vì sao 5 đỏ không thuộc WWHN-231

`php vendor/bin/phpunit` (toàn bộ, sqlite): **935 test, 902 pass, 3 failed + 2 errors**.

| Test đỏ | Vùng phụ thuộc | Ai đang sửa |
|---|---|---|
| `Wwhn296BrowserRuntimeContractTest` (2 error) | `config/warehouse.php`, `BackendGoLiveCheck`, `storage/app/testing/...` | phiên khác (uncommitted) |
| `Wwhn299CutoverRehearsalEvidenceTest` | `FinalDataLoadService`, `ImportPackage`, `build/evidence/wwhn-299/*` | phiên khác (uncommitted) |
| `Wwhn330ImportR3ClosureTest` | `ImportErrorFileService`, `PurgeExpiredImportErrorFiles` | phiên khác (uncommitted) |
| `ValkeyIntegrationTest` | Valkey dùng chung giữa các phiên chạy song song | hạ tầng |

### Phép đo tách nguồn

Dựng worktree sạch tại `HEAD` (`41db2e0`) bằng `git worktree add --detach`, junction `vendor`,
copy `.env`, rồi:

1. **Chỉ HEAD, không thay đổi nào:**
   `phpunit --filter "Wwhn296BrowserRuntimeContractTest|ValkeyIntegrationTest|Wwhn299CutoverRehearsalEvidenceTest|Wwhn330ImportR3ClosureTest"`
   → **27/27 PASS**. Vậy 5 đỏ đến từ thay đổi *chưa commit* trên working tree, không từ code đã commit.

2. **HEAD + đúng 5 file của WWHN-231** (`LedgerReconciliationService`, `OperationalReportController`,
   `ReconcileInventoryBalances`, migration index, `Wwhn231StockNeutralLedgerTest`), cùng lệnh trên
   cộng thêm `|Wwhn231StockNeutralLedgerTest`
   → **36/36 PASS**.

Kết luận: thay đổi của `WWHN-231` **không** làm đỏ test nào; 5 đỏ thuộc về thay đổi chưa commit
của các task khác trên cùng nhánh.

## 3. PostgreSQL — baseline đỏ có sẵn, không do WWHN-231

Chạy bộ khoá phạm vi trên PostgreSQL (`hoa_nam_audit`) cho 8 error, tất cả cùng một nguyên nhân
duy nhất, và nguyên nhân đó nằm ở **migration** — tức trước khi test chạm tới bất kỳ dòng code
nào của WWHN-231:

```text
SQLSTATE[42804]: Datatype mismatch: 7 ERROR: column "created_by" is of type uuid but expression is of type text
QUERY: INSERT INTO "pv_industries" (...) SELECT ... FROM "pv_industries__legacy_projection" ...
CONTEXT: PL/pgSQL function hn_erd_mirror_pv_industries() line 1 at SQL statement
(SQL: update "pv_industry" set "version" = 1 where "version" is null)
```

Câu `update pv_industry set version = 1 where version is null` đến từ
`2026_08_19_000100_add_optimistic_lock_to_pv_master_tables` (commit `b90d5ef`, `WWHN-252`).

**Đối chứng:** chạy một test *không thuộc WWHN-231* trên cùng môi trường —
`Wwhn207OutboundBalanceReconciliationTest` — cho **đúng cùng lỗi** (log:
`logs/pgsql-preexisting-baseline-probe.log`). Đây là lỗi baseline của nhánh trên driver pgsql,
cần đóng ở `WWHN-252`; nó chặn mọi test dùng `RefreshDatabase` trên PostgreSQL, không riêng
WWHN-231.

Vì vậy gate QA của `WWHN-231` chốt trên **baseline sqlite** (đúng driver mà CI
`current-baseline-doc-contract.yml` dùng), và phần PostgreSQL chạy lại khi `WWHN-252` xanh.
Riêng các bước PostgreSQL **không cần** `RefreshDatabase` đã chạy được và đã thu evidence:
migration index (`logs/migrate-ledger-dimension-index.log`), đối soát dry-run
(`logs/inventory-reconcile-dry-run.log`), benchmark (`BENCHMARK_RESULT.json`).
