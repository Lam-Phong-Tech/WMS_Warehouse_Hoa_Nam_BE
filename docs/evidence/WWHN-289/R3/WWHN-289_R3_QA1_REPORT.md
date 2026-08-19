# WWHN-289 · R3 · DEV289-P1-03R3-QA — QA1 live evidence trên frozen candidate (HARD GATE)

> Đóng finding R3: *"WWHN-291 Blocked/Pending, 0 live QA evidence. QA1 chạy trực tiếp trên
> frozen candidate bằng PostgreSQL/UAT/local harness."*
> Register cho phép ba harness: PostgreSQL / UAT / **local harness** — báo cáo này dùng
> local harness + PostgreSQL 16.14 (DB tham chiếu theo docs), KHÔNG dùng GitHub Actions.

## 1. Định danh lần chạy

| Trường | Giá trị |
|---|---|
| Frozen candidate (exact SHA) | `8bdf9ad0a00461afe27e3e11a26e7fd13d68f9e6` (= `code_range.final` trong `WWHN-289_PINS.json`) |
| Cách dựng đúng SHA | `git worktree add --detach <dir> 8bdf9ad…` + vendor copy thật — working tree tách biệt hoàn toàn khỏi m1 đang di chuyển |
| DB | PostgreSQL 16.14 (postgres:16, container `wms_warehouse_hoa_nam_be-postgres-1`), database CÔ LẬP `hn_wwhn289_r3_frozen` tạo mới cho lần chạy này |
| Runtime | PHP 8.3.30, PHPUnit 12.5.33, RefreshDatabase (migrate từ scratch trên PG — toàn bộ chuỗi migration ERD, gồm write-bridge đã sửa ở BUILD289-05) |
| Ngày chạy | 2026-08-19, thời lượng 03:17.284 |
| Kết quả tổng | **OK — 16 tests, 197 assertions, 0 failure** |
| Raw log | `R3/QA1_LIVE_RUN_FROZEN_8bdf9ad.txt` (nguyên văn stdout PHPUnit) |
| Raw artifact máy sinh | `R3/stock-invariant-proof.frozen-8bdf9ad.json`, `R3/security-regression-proof.frozen-8bdf9ad.json` (`meta.driver = "pgsql"`) |

Lần chạy đối chứng thứ hai tại HEAD `5db3cb06a33aef726466e54801c7e087915d1801` (frozen + delta,
DB `hn_wwhn289_r3_head`): **OK — 18 tests, 202 assertions** — phục vụ disposition
DEV289-P1-01R3, xem `R3/WWHN-289_R3_DELTA_DISPOSITION.md`.

## 2. Ánh xạ đủ các kịch bản register đòi → phép đo → kết quả

| Kịch bản register (P1-03R3) | Test method (chạy thật trong log) | Kết quả trên PG |
|---|---|---|
| Same-key replay | `test_retry_dimension_three_identical_post_receipts_produce_exactly_one_movement` — 3 request cùng key/payload | PASS: đúng 1 stock_movement, balance +5 một lần |
| Different-payload conflict | `test_idempotency_dimension_same_key_with_different_payload_is_rejected` | PASS: 409 `IDEMPOTENCY_CONFLICT`, không stock effect |
| Injected rollback | `test_rollback_dimension_injected_failure_rolls_back_document_movement_and_balance` | PASS: 500 không lộ trace; movement/balance/document rollback; retry sau sự cố vẫn Post được |
| Negative stock | `test_negative_stock_dimension_no_path_can_drive_balance_below_zero` | PASS: mọi đường đều bị chặn, floor toàn DB ≥ 0 |
| True race / concurrency | `test_true_concurrency_dimension_race_losers_are_rejected_without_stock_effect` | PASS: `SELECT … FOR UPDATE` quan sát được trên pgsql; thua lease → 409 `CONCURRENCY_CONFLICT`; thua optimistic lock → không nhánh thua nào ghi stock effect |
| Reversal | `test_concurrent_dimension_duplicate_reversal_of_the_same_movement_is_blocked` | PASS: reversal trùng bị chặn, tồn không âm |
| Reconcile = 0 | `test_reconcile_dimension_ledger_and_balance_have_zero_mismatch_after_full_cycle` | PASS: `mismatch_count = 0`, `report_status = PASS`, đối soát SQL thô độc lập 0 lệch |
| Unauthorized | `test_unauthorized_dimension_anonymous_call_to_stock_endpoint_is_rejected` + `…underprivileged_caller_is_rejected_without_stock_effect` | PASS: 401 / 403, không stock effect |
| RBAC | `test_rbac_dimension_permission_matrix_has_no_cross_operation_bleed` — ma trận 3×3 | PASS: 6/6 ô ngoài đường chéo DENY, không rò quyền chéo |
| Data-scope | `test_data_scope_dimension_scoped_user_cannot_create_stock_effect_outside_scope` | PASS: 403 `DATA_SCOPE_DENIED` (SEC289-01) |
| Replay (token) | `test_replay_dimension_rotated_refresh_token_and_revoked_session_cannot_be_replayed` | PASS: token đã xoay vòng bị 401 |
| Rate-limit | `test_rate_limit_dimension_login_locks_out_after_configured_failed_attempts` + `…public_pv_catalog_returns_canonical_429` + `…thresholds_are_config_driven_and_zero_disables_guard` | PASS: 429 `RATE_LIMIT_EXCEEDED` + Retry-After đúng envelope §8.2 (SEC289-02); ngưỡng config-driven |
| Secret | `test_secret_dimension_no_credential_or_internal_detail_is_ever_exposed` | PASS: không lộ password_hash/trace/SQLSTATE |

## 3. Phạm vi và giới hạn khai báo tường minh

- Lần chạy này là **local harness trên PostgreSQL** — một trong ba hình thức register chấp
  nhận. Nó KHÔNG thay thế UAT môi trường thật (WWHN-76/HN-UAT-003) nếu BA/PM vẫn yêu cầu riêng.
- Số 16/197 tại frozen khác số 93/850 của CI run 32206778306 vì CI chạy focused suite rộng hơn
  (gồm cả các test class khác của cổng); hai phép đo cùng verdict PASS trên cùng candidate.
- Verdict dưới đây là dữ kiện để QA1 ký trên Jira (WWHN-291); DEV không tự flip trạng thái
  Jira nào.

## 4. Verdict đề xuất cho QA1

**PASS** — toàn bộ kịch bản hard-gate chạy sống trên frozen candidate
`8bdf9ad0a00461afe27e3e11a26e7fd13d68f9e6`, PostgreSQL 16.14, DB cô lập, 0 failure,
artifact máy sinh `driver=pgsql`, `mismatch_count=0`.
