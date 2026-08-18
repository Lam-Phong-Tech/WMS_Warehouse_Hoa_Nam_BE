# 8. Bàn giao vận hành và QA — DEV260-P1-09, DEV260-P1-10

## 8.1 Cho DevOps / `WWHN-261`

Runbook: `docs/migration/CUTOVER_BACKUP_RESTORE_ROLLBACK.md` (`HN-MIG-OPS-001`).

Ba thứ ticket đang chờ, nay đã có đường làm cụ thể:

| Yêu cầu register | Nằm ở đâu |
|---|---|
| Chạy target UAT/reset | §4 runbook + evidence mẫu `docs/evidence/WWHN-260/01..07` |
| Thu raw log | Mọi lệnh đều có `--output=<file>.json`; log đầy đủ nằm trong artifact, không phải chép tay |
| Verify backup/restore/rollback **trước** lần nạp cuối | §2, §3, §5 runbook — có lệnh `pg_dump`/`pg_restore` và tiêu chí PASS |

Điểm bắt buộc: backup chưa restore thử thì chưa được coi là backup, và bản khôi phục phải chạy
được `php artisan migrate --force` + `php artisan wms:golive-check`.

## 8.2 Cho QA2 / `WWHN-262`

Lệnh xác minh độc lập:

```bash
php artisan migration:verify-run --run-code=<code> --mode=COMMIT \
    --package=<đường dẫn package production đã duyệt> \
    --error-file=<evidence>/rejected.csv \
    --output=<evidence>/verify.json
```

Lệnh này **không đọc** báo cáo do job sinh ra. Nó dựng lại từ đầu:

| Nhóm | Check |
|---|---|
| Kết cục | `RUN_REACHED_EXPECTED_TERMINAL_STATUS`, `RECONCILIATION_PASSED` |
| Định danh nguồn | `RUN_RECORDS_SOURCE_VERSION_CHECKSUM_AND_ID` |
| Số dòng | `VALID_PLUS_INVALID_PLUS_DUPLICATE_EQUALS_INPUT`, `STORED_COUNTS_MATCH_ERROR_TABLE` |
| Package | `PACKAGE_CHECKSUM_RECOMPUTED`, `PACKAGE_ROW_COUNT_MATCHES_RUN` |
| Tính lặp lại | `AT_MOST_ONE_LIVE_COMMIT_PER_CHECKSUM` |
| Tồn kho | `BALANCE_EQUALS_LEDGER`, `SERIAL_TRACKED_QTY_EQUALS_ITEM_COUNT`, `CONTAINER_MAPPING_NO_EXTRA_STOCK`, `RUN_WRITES_ONLY_OPENING_MOVEMENTS` |

Exit 0 ⇔ mọi check PASS. Exit 1 ⇔ có FAIL ⇒ QA **không** ký, cutover chuyển sang nhánh xử lý
ở §5 runbook.

Chống giả mạo số liệu đã được kiểm bằng test: sửa tay `migration_runs.error_rows` làm
`STORED_COUNTS_MATCH_ERROR_TABLE` đỏ ngay
(`Wwhn260MigrationPackClosureTest::test_verify_run_fails_when_stored_counts_no_longer_match_the_error_table`).

## 8.3 Cho BAS / Data Owner / `WWHN-263`

Xem phần 6 của pack này. Đầu vào là `--error-file`, đầu ra là cleansing log có bốn cột xử lý
và ba trạng thái kết thúc (`FIXED` / `EXCLUDED` / `WAIVED`).
