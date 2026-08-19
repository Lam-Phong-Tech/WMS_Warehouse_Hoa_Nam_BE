#!/usr/bin/env bash
# WWHN-310 / DEV310-P1-04 — diễn tập nạp dữ liệu cuối trên safe target dataset.
#
# Target: database PostgreSQL riêng `hn_wwhn310_fdl` (KHÔNG phải DB dev/app, KHÔNG
# phải DB test), được `migrate:fresh --seed --force` tại đúng candidate SHA trước
# khi chạy. Script tự nó không đụng vào bất kỳ database nào khác.
#
# Chạy lại:  bash docs/evidence/WWHN-310/round2/run-final-load-rehearsal.sh
set -uo pipefail

REPO="$(cd "$(dirname "$0")/../../../.." && pwd)"
OUT="$REPO/docs/evidence/WWHN-310/round2"
RUN_CODE="${RUN_CODE:-REHEARSAL-WWHN-310-R2}"
REJ_RUN_CODE="${REJ_RUN_CODE:-REHEARSAL-WWHN-310-R2-REJECTED}"
PKG="$OUT/package"
PKG_REJ="$OUT/package-rejected"
PSQL=(docker exec -i wms_warehouse_hoa_nam_be-postgres-1 psql -U postgres -d hn_wwhn310_fdl)

export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=hn_wwhn310_fdl \
       DB_USERNAME=postgres DB_PASSWORD=postgres

cd "$REPO"

echo "== 1. DRY_RUN (package hợp lệ) — kỳ vọng PASSED, exit 0 =="
php artisan migration:final-load --mode=DRY_RUN --package="$PKG" --run-code="$RUN_CODE" \
  --executed-by=keeper01 --note="WWHN-310 DEV310-P1-04 rehearsal" \
  --output="$OUT/10-dry-run.json" > "$OUT/10-dry-run.console.log" 2>&1
echo "exit=$?"

echo "== 2. COMMIT — kỳ vọng COMMITTED, exit 0 =="
php artisan migration:final-load --mode=COMMIT --package="$PKG" --run-code="$RUN_CODE" \
  --executed-by=keeper01 --approved-by=manager01 --approval-ref="WWHN-310#DEV310-P1-04" \
  --note="WWHN-310 DEV310-P1-04 rehearsal" \
  --output="$OUT/11-commit.json" > "$OUT/11-commit.console.log" 2>&1
echo "exit=$?"

echo "== 3. Đối soát sau COMMIT =="
"${PSQL[@]}" < "$OUT/14-reconcile-after-commit.sql" > "$OUT/14-reconcile-after-commit.txt" 2>&1
echo "exit=$?"

echo "== 4. ROLLBACK — kỳ vọng ROLLED_BACK, exit 0 =="
php artisan migration:final-load --mode=ROLLBACK --run-code="$RUN_CODE" \
  --executed-by=keeper01 --note="WWHN-310 DEV310-P1-04 rollback drill" \
  --output="$OUT/12-rollback.json" > "$OUT/12-rollback.console.log" 2>&1
echo "exit=$?"

echo "== 5. Đối soát sau ROLLBACK =="
"${PSQL[@]}" < "$OUT/15-reconcile-after-rollback.sql" > "$OUT/15-reconcile-after-rollback.txt" 2>&1
echo "exit=$?"

echo "== 6. DRY_RUN (package có dòng bị từ chối) — kỳ vọng FAILED, exit 1 =="
php artisan migration:final-load --mode=DRY_RUN --package="$PKG_REJ" --run-code="$REJ_RUN_CODE" \
  --executed-by=keeper01 --note="WWHN-310 rejected-rows sample" \
  --output="$OUT/13-dry-run-rejected.json" > "$OUT/13-dry-run-rejected.console.log" 2>&1
echo "exit=$? (1 = FAILED là đúng kỳ vọng)"

echo "== 7. COMMIT sau dry-run FAILED — kỳ vọng BLOCKED, exit 1 =="
php artisan migration:final-load --mode=COMMIT --package="$PKG_REJ" --run-code="$REJ_RUN_CODE" \
  --executed-by=keeper01 --approved-by=manager01 --approval-ref="WWHN-310#DEV310-P1-04" \
  --output="$OUT/16-commit-blocked.json" > "$OUT/16-commit-blocked.console.log" 2>&1
echo "exit=$? (1 = BLOCKED là đúng kỳ vọng)"

echo "== 8. Dòng bị từ chối đã ghi vào migration_errors =="
"${PSQL[@]}" -c "SELECT r.migration_run_code, e.dataset, e.row_no, e.field_name, e.error_code, e.severity, e.raw_value_masked FROM migration_errors e JOIN migration_runs r ON r.id = e.migration_run_id ORDER BY e.dataset, e.row_no, e.field_name;" > "$OUT/17-rejected-rows.txt" 2>&1
echo "exit=$?"
