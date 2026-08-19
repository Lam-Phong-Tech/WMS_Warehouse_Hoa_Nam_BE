#!/usr/bin/env bash
# WWHN-204 / DEV204-P1-04 — bằng chứng chạy thật trên PostgreSQL mục tiêu.
#
# Register yêu cầu: "Chạy migration + append-only UPDATE/DELETE + reconciliation trên
# PostgreSQL mục tiêu" với evidence "PG engine/version; migration list; raw SQL rejection;
# CI run; report".
#
# Script dựng một database RIÊNG (hn_wwhn204) thay vì dùng hoa_nam_audit dùng chung, để
# bằng chứng tái lập được và không đụng dữ liệu của các ticket khác đang chạy song song
# trên cùng container.
#
# LƯU Ý TÊN BẢNG VẬT LÝ TRÊN PostgreSQL (khác sqlite, xem migration 2026_08_10_000791):
#   skus / app_users / audit_logs là BẢNG (relkind='r'); sku / app_user / audit_log là VIEW.
#   audit_logs dùng cột entity_type/entity_id, không phải object_type/object_id.
#   stock_movements.created_by NOT NULL ⇒ phải có app_users trước.
#
# Chạy:  bash scripts/collect-wwhn204-pg-evidence.sh
# Output: docs/evidence/WWHN-204/logs/pg-append-only.log

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOG_DIR="$ROOT/docs/evidence/WWHN-204/logs"
LOG="$LOG_DIR/pg-append-only.log"
CONTAINER="${WWHN204_PG_CONTAINER:-wms_warehouse_hoa_nam_be-postgres-1}"
DB="${WWHN204_PG_DATABASE:-hn_wwhn204}"

WH='11111111-1111-4111-8111-111111111111'
SKU='22222222-2222-4222-8222-222222222222'
MOV='33333333-3333-4333-8333-333333333333'
BAL='44444444-4444-4444-8444-444444444444'
USR='55555555-5555-4555-8555-555555555555'

# Fail-closed trước khi xoá log cũ. `m1` là nhánh dùng chung: một phiên khác chạy
# `composer install` sẽ làm `vendor/` biến mất giữa chừng, khi đó mọi lệnh artisan bên dưới
# fatal và script vẫn ghi ra một log "trông như đã chạy" nhưng thiếu hết số liệu. Đã gặp thật
# một lần — kiểm trước, và tuyệt đối không ghi đè bằng chứng cũ khi môi trường chưa sẵn sàng.
if [ ! -f "$ROOT/vendor/autoload.php" ]; then
  echo "ABORT: thiếu $ROOT/vendor/autoload.php (phiên khác đang chạy composer?). Không ghi đè log cũ." >&2
  exit 2
fi
if ! docker exec "$CONTAINER" pg_isready -U postgres >/dev/null 2>&1; then
  echo "ABORT: container PostgreSQL '$CONTAINER' chưa sẵn sàng. Không ghi đè log cũ." >&2
  exit 2
fi

mkdir -p "$LOG_DIR"
: > "$LOG"

say() { echo "$@" | tee -a "$LOG"; }
psql_run() { docker exec -i "$CONTAINER" psql -U postgres -d "$DB" -v ON_ERROR_STOP=0 "$@" 2>&1 | tee -a "$LOG"; }
artisan_pg() {
  ( cd "$ROOT" && DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE="$DB" \
      DB_USERNAME=postgres DB_PASSWORD=postgres php artisan "$@" 2>&1 )
}

say "=== WWHN-204 DEV204-P1-04 — PostgreSQL append-only + reconciliation evidence ==="
say "generated_at_utc: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
say "git_sha: $(git -C "$ROOT" rev-parse HEAD)"
say "git_branch: $(git -C "$ROOT" rev-parse --abbrev-ref HEAD)"
say ""

say "--- [1] PG engine / version ---"
docker exec -i "$CONTAINER" psql -U postgres -c "SELECT version();" 2>&1 | tee -a "$LOG"

say ""
say "--- [2] Dựng database bằng chứng riêng: $DB ---"
docker exec -i "$CONTAINER" psql -U postgres -c "DROP DATABASE IF EXISTS $DB;" 2>&1 | tee -a "$LOG"
docker exec -i "$CONTAINER" psql -U postgres -c "CREATE DATABASE $DB;" 2>&1 | tee -a "$LOG"

say ""
say "--- [3] artisan migrate trên PostgreSQL mục tiêu (chỉ in phần đuôi) ---"
artisan_pg migrate --force --no-interaction | tee -a "$LOG" | tail -6

say ""
say "--- [4] Migration list: migration append-only phải ở trạng thái Ran ---"
artisan_pg migrate:status | grep -E "append_only|ledger_dimension" | tee -a "$LOG"

say ""
say "--- [5] Trigger append-only có thật trên BẢNG stock_movements (relkind='r') ---"
psql_run -c "SELECT t.tgname, c.relname, c.relkind, p.proname
             FROM pg_trigger t
             JOIN pg_class c ON c.oid = t.tgrelid
             JOIN pg_proc p ON p.oid = t.tgfoid
             WHERE c.relname = 'stock_movements' AND NOT t.tgisinternal
             ORDER BY t.tgname;"

say ""
say "--- [6] Dữ liệu mồi: ledger 10 và projection 10 khớp nhau ---"
psql_run <<SQL
INSERT INTO app_users (id, username, display_name, status, version, created_at, updated_at)
VALUES ('$USR', 'wwhn204-evidence', 'WWHN-204 Evidence', 'ACTIVE', 1, now(), now())
ON CONFLICT (id) DO NOTHING;

INSERT INTO warehouses (id, code, name, status, created_at, updated_at)
VALUES ('$WH', 'KHO-WWHN204', 'Kho bang chung WWHN-204', 'ACTIVE', now(), now())
ON CONFLICT (id) DO NOTHING;

INSERT INTO skus (id, sku_code, sku_name, sku_type, tracking_mode, unit_code, status, version, created_at, updated_at)
VALUES ('$SKU', 'SKU-WWHN204', 'SKU bang chung WWHN-204', 'COMPONENT', 'QUANTITY', 'cai', 'ACTIVE', 1, now(), now())
ON CONFLICT (id) DO NOTHING;

INSERT INTO stock_movements
  (id, movement_no, business_key, movement_type, warehouse_id, product_id, sku_id, stock_status,
   quantity, qty_before, qty_delta, qty_after, source_type, created_by, occurred_at, created_at)
VALUES
  ('$MOV', 'MV-WWHN204-0001', 'wwhn204-evidence-1', 'RECEIPT', '$WH', '$SKU', '$SKU', 'AVAILABLE',
   10, 0, 10, 10, 'EVIDENCE', '$USR', now(), now())
ON CONFLICT (id) DO NOTHING;

INSERT INTO inventory_balances
  (id, warehouse_id, product_id, sku_id, stock_status, quantity, on_hand_qty, available_qty, reserved_qty, version, created_at, updated_at)
VALUES
  ('$BAL', '$WH', '$SKU', '$SKU', 'AVAILABLE', 10, 10, 10, 0, 1, now(), now())
ON CONFLICT (id) DO NOTHING;

SELECT 'ledger' AS src, SUM(qty_delta) AS qty FROM stock_movements WHERE product_id = '$SKU'
UNION ALL
SELECT 'balance', SUM(on_hand_qty) FROM inventory_balances WHERE product_id = '$SKU';
SQL

say ""
say "--- [7] RAW SQL REJECTION: UPDATE trên ledger phải bị từ chối ---"
psql_run -c "UPDATE stock_movements SET qty_delta = 999 WHERE id = '$MOV';"

say ""
say "--- [8] RAW SQL REJECTION: DELETE trên ledger phải bị từ chối ---"
psql_run -c "DELETE FROM stock_movements WHERE id = '$MOV';"

say ""
say "--- [9] Ledger còn nguyên sau hai lần bị từ chối (phải vẫn là 10) ---"
psql_run -c "SELECT id, qty_delta FROM stock_movements WHERE id = '$MOV';"

say ""
say "--- [10] INTENTIONAL DRIFT: chỉ chạm projection (được phép), ledger giữ nguyên ---"
psql_run -c "UPDATE inventory_balances SET on_hand_qty = 99, quantity = 99, available_qty = 99 WHERE id = '$BAL';"
psql_run -c "SELECT on_hand_qty, quantity, available_qty, version FROM inventory_balances WHERE id = '$BAL';"

say ""
say "--- [11] inventory:reconcile — phát hiện lệch và ghi incident ---"
artisan_pg inventory:reconcile | tee -a "$LOG"

say ""
say "--- [12] inventory:reconcile --rebuild — đưa projection về ledger authoritative ---"
artisan_pg inventory:reconcile --rebuild --reason="DEV204-P1-04 evidence run" | tee -a "$LOG"

say ""
say "--- [13] SAU REBUILD: mismatch = 0, ledger vẫn 10, incident RESOLVED ---"
psql_run -c "SELECT b.on_hand_qty AS balance_qty,
                    (SELECT SUM(qty_delta) FROM stock_movements m
                      WHERE m.warehouse_id = b.warehouse_id AND m.product_id = b.product_id
                        AND m.stock_status = b.stock_status) AS ledger_qty,
                    b.on_hand_qty - (SELECT SUM(qty_delta) FROM stock_movements m
                      WHERE m.warehouse_id = b.warehouse_id AND m.product_id = b.product_id
                        AND m.stock_status = b.stock_status) AS mismatch
               FROM inventory_balances b WHERE b.id = '$BAL';"
psql_run -c "SELECT incident_no, divergence_type, status, resolution_ref FROM reconciliation_incidents
              WHERE warehouse_id = '$WH';"

say ""
say "--- [14] Rebuild lần 2 phải là no-op (idempotent) ---"
artisan_pg inventory:reconcile --rebuild | tee -a "$LOG"

say ""
say "--- [15] Audit trail của rebuild (audit_logs.entity_type trên PG) ---"
psql_run -c "SELECT action, entity_type, after_data->>'derived_from' AS derived_from
               FROM audit_logs WHERE action = 'INVENTORY_PROJECTION_REBUILT'
              ORDER BY created_at DESC LIMIT 5;"

say ""
say "--- [16] Ledger bất biến xuyên suốt phiên: vẫn đúng 1 dòng, tổng delta 10 ---"
psql_run -c "SELECT COUNT(*) AS rows, COALESCE(SUM(qty_delta),0) AS delta_sum FROM stock_movements;"

say ""
say "--- [17] Tự kiểm: log này có đủ mốc PASS mà QA1 phải đối chiếu không? ---"
missing=0
for marker in \
  'PostgreSQL 1' \
  'append_only_trigger .. [1] Ran' \
  'ERROR:  stock_movements is append-only under HN-WMS-ERD-DD-001' \
  'Rebuild hoàn tất' \
  'RESOLVED | REBUILD:'; do
  if grep -qF -- "$marker" "$LOG"; then
    say "  OK   $marker"
  else
    say "  THIẾU $marker"
    missing=$((missing + 1))
  fi
done
if [ "$missing" -gt 0 ]; then
  say "KẾT LUẬN: log KHÔNG đủ bằng chứng ($missing mốc thiếu) — không dùng làm evidence."
  say "=== HẾT ==="
  exit 1
fi
say "KẾT LUẬN: đủ mốc bằng chứng."

say ""
say "=== HẾT ==="
