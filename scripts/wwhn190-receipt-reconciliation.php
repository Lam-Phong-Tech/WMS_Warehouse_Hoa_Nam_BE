<?php

declare(strict_types=1);

/**
 * WWHN-190 / DEV190-P1-03R2 (đối chiếu cho WWHN-191, cùng RC).
 *
 * Yêu cầu review: "Chứng minh accepted_receipt_qty = signed receipt movements =
 * balance delta, mismatch=0; orphan=0; duplicate business effect=0."
 *
 * Ánh xạ thuật ngữ review sang schema thật (không có cột tên `accepted_receipt_qty`):
 *   accepted_receipt_qty(line) = stock_document_lines.qty_planned
 *   — tại thời điểm Post, DEC-FULL-POST-001 ép scanned_qty = qty_planned cho MỌI dòng
 *     (CurrentBaselineStockService::postStockDocument), và movement được ghi đúng
 *     qty_planned. Vì vậy script kiểm cả bất biến scanned_qty = qty_planned.
 *
 * Bốn lớp kiểm tra:
 *   L1 line-level : accepted_receipt_qty  == SUM(qty_delta) movement RECEIPT của dòng
 *   L2 projection : inventory_balances.on_hand_qty == SUM(qty_delta) toàn bộ ledger của bucket
 *   L3 orphan     : movement mồ côi / dòng POSTED không có movement / balance không có ledger
 *   L4 duplicate  : business_key trùng, movement trùng trên cùng dòng, posting_event trùng
 *
 * Xuất: báo cáo JSON có version + file .txt chứa NGUYÊN VĂN SQL và raw rows để QA chạy lại.
 * Dùng: php scripts/wwhn190-receipt-reconciliation.php [outputDir]
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

const REPORT_VERSION = '1.0.0';

$outputDir = $argv[1] ?? (getenv('WWHN190_RECON_DIR') ?: 'docs/evidence/WWHN-190/reconciliation');
@mkdir($outputDir, 0777, true);

$driver = DB::connection()->getDriverName();
$rawSections = [];

function section(string $title, string $sql, array $rows): void
{
    global $rawSections;
    $body = "===== {$title} =====\nSQL:\n{$sql}\n\nROWS (".count($rows)."):\n";
    foreach ($rows as $row) {
        $body .= json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
    }
    $rawSections[] = $body;
}

// ---------------------------------------------------------------------------
// L1 — accepted_receipt_qty vs signed receipt movements (theo từng dòng)
// ---------------------------------------------------------------------------
$l1Sql = <<<'SQL'
select d.id           as document_id,
       d.doc_no       as doc_no,
       l.id           as line_id,
       l.qty_planned  as accepted_receipt_qty,
       l.scanned_qty  as scanned_qty,
       coalesce(sum(m.qty_delta), 0) as signed_receipt_movement
from stock_documents d
join stock_document_lines l on l.stock_document_id = d.id
left join stock_movements m
       on m.stock_document_line_id = l.id
      and m.movement_type = 'RECEIPT'
where d.doc_type = 'receipt'
  and d.status = 'POSTED'
group by d.id, d.doc_no, l.id, l.qty_planned, l.scanned_qty
order by d.doc_no, l.id
SQL;

$l1Rows = DB::select($l1Sql);
section('L1 accepted_receipt_qty vs signed receipt movements', $l1Sql, $l1Rows);

$l1Mismatch = [];
$l1FullPostViolation = [];
foreach ($l1Rows as $row) {
    $accepted = (int) $row->accepted_receipt_qty;
    $signed = (int) $row->signed_receipt_movement;
    if ($accepted !== $signed) {
        $l1Mismatch[] = [
            'document_id' => $row->document_id,
            'doc_no' => $row->doc_no,
            'line_id' => $row->line_id,
            'accepted_receipt_qty' => $accepted,
            'signed_receipt_movement' => $signed,
            'delta' => $signed - $accepted,
        ];
    }
    if ((int) $row->scanned_qty !== $accepted) {
        $l1FullPostViolation[] = [
            'document_id' => $row->document_id,
            'line_id' => $row->line_id,
            'qty_planned' => $accepted,
            'scanned_qty' => (int) $row->scanned_qty,
        ];
    }
}

// ---------------------------------------------------------------------------
// L2 — balance projection vs toàn bộ signed ledger (DEC-INV-001)
// ---------------------------------------------------------------------------
$l2Sql = <<<'SQL'
select b.warehouse_id,
       coalesce(b.sku_id, b.product_id) as sku_id,
       b.stock_status,
       b.on_hand_qty,
       b.quantity,
       coalesce((
         select sum(m.qty_delta) from stock_movements m
         where m.warehouse_id = b.warehouse_id
           and coalesce(m.sku_id, m.product_id) = coalesce(b.sku_id, b.product_id)
           and m.stock_status = b.stock_status
       ), 0) as ledger_sum
from inventory_balances b
order by b.warehouse_id, sku_id, b.stock_status
SQL;

$l2Rows = DB::select($l2Sql);
section('L2 balance projection vs signed ledger', $l2Sql, $l2Rows);

$l2Mismatch = [];
foreach ($l2Rows as $row) {
    $onHand = (int) $row->on_hand_qty;
    $ledger = (int) $row->ledger_sum;
    $quantity = (int) $row->quantity;
    if ($onHand !== $ledger || $quantity !== $ledger) {
        $l2Mismatch[] = [
            'warehouse_id' => $row->warehouse_id,
            'sku_id' => $row->sku_id,
            'stock_status' => $row->stock_status,
            'on_hand_qty' => $onHand,
            'quantity' => $quantity,
            'ledger_sum' => $ledger,
        ];
    }
}

// ---------------------------------------------------------------------------
// L3 — orphan
// ---------------------------------------------------------------------------
$orphanMovementSql = <<<'SQL'
select m.id, m.movement_type, m.stock_document_id, m.stock_document_line_id, m.qty_delta
from stock_movements m
where m.stock_document_id is not null
  and not exists (select 1 from stock_documents d where d.id = m.stock_document_id)
SQL;
$orphanMovements = DB::select($orphanMovementSql);
section('L3a movement mồ côi (không có chứng từ gốc)', $orphanMovementSql, $orphanMovements);

$movementOnUnpostedSql = <<<'SQL'
select m.id, m.movement_type, d.doc_no, d.status, m.qty_delta
from stock_movements m
join stock_documents d on d.id = m.stock_document_id
where m.movement_type in ('RECEIPT', 'ISSUE')
  and d.status <> 'POSTED'
SQL;
$movementOnUnposted = DB::select($movementOnUnpostedSql);
section('L3b movement RECEIPT/ISSUE trên chứng từ chưa POSTED', $movementOnUnpostedSql, $movementOnUnposted);

$postedLineWithoutMovementSql = <<<'SQL'
select d.doc_no, d.id as document_id, l.id as line_id, l.qty_planned
from stock_documents d
join stock_document_lines l on l.stock_document_id = d.id
where d.doc_type = 'receipt'
  and d.status = 'POSTED'
  and not exists (
    select 1 from stock_movements m
    where m.stock_document_line_id = l.id and m.movement_type = 'RECEIPT'
  )
SQL;
$postedLineWithoutMovement = DB::select($postedLineWithoutMovementSql);
section('L3c dòng POSTED không sinh movement', $postedLineWithoutMovementSql, $postedLineWithoutMovement);

$balanceWithoutLedgerSql = <<<'SQL'
select b.warehouse_id, coalesce(b.sku_id, b.product_id) as sku_id, b.stock_status, b.on_hand_qty
from inventory_balances b
where b.on_hand_qty <> 0
  and not exists (
    select 1 from stock_movements m
    where m.warehouse_id = b.warehouse_id
      and coalesce(m.sku_id, m.product_id) = coalesce(b.sku_id, b.product_id)
      and m.stock_status = b.stock_status
  )
SQL;
$balanceWithoutLedger = DB::select($balanceWithoutLedgerSql);
section('L3d balance khác 0 nhưng không có ledger', $balanceWithoutLedgerSql, $balanceWithoutLedger);

// ---------------------------------------------------------------------------
// L4 — duplicate business effect
// ---------------------------------------------------------------------------
$duplicateBusinessKeySql = <<<'SQL'
select business_key, count(*) as occurrences
from stock_movements
where business_key is not null
group by business_key
having count(*) > 1
SQL;
$duplicateBusinessKeys = DB::select($duplicateBusinessKeySql);
section('L4a business_key trùng', $duplicateBusinessKeySql, $duplicateBusinessKeys);

$duplicateLineEffectSql = <<<'SQL'
select stock_document_line_id, movement_type, count(*) as occurrences
from stock_movements
where stock_document_line_id is not null
  and movement_type in ('RECEIPT', 'ISSUE')
group by stock_document_line_id, movement_type
having count(*) > 1
SQL;
$duplicateLineEffects = DB::select($duplicateLineEffectSql);
section('L4b movement RECEIPT/ISSUE trùng trên cùng một dòng', $duplicateLineEffectSql, $duplicateLineEffects);

$duplicatePostingEvents = [];
$duplicatePostingEventSql = 'n/a — bảng posting_events không tồn tại';
if (DB::getSchemaBuilder()->hasTable('posting_events')) {
    $keyColumn = null;
    foreach (['business_key', 'idempotency_key', 'event_key'] as $candidate) {
        if (DB::getSchemaBuilder()->hasColumn('posting_events', $candidate)) {
            $keyColumn = $candidate;
            break;
        }
    }
    if ($keyColumn !== null) {
        $duplicatePostingEventSql = "select {$keyColumn} as key_value, count(*) as occurrences from posting_events where {$keyColumn} is not null group by {$keyColumn} having count(*) > 1";
        $duplicatePostingEvents = DB::select($duplicatePostingEventSql);
    }
}
section('L4c posting_event trùng', $duplicatePostingEventSql, $duplicatePostingEvents);

// ---------------------------------------------------------------------------
// Tổng hợp
// ---------------------------------------------------------------------------
$totals = [
    'posted_receipt_lines' => count($l1Rows),
    'accepted_receipt_qty_total' => array_sum(array_map(fn ($r) => (int) $r->accepted_receipt_qty, $l1Rows)),
    'signed_receipt_movement_total' => array_sum(array_map(fn ($r) => (int) $r->signed_receipt_movement, $l1Rows)),
    'balance_buckets' => count($l2Rows),
    'balance_on_hand_total' => array_sum(array_map(fn ($r) => (int) $r->on_hand_qty, $l2Rows)),
    'ledger_sum_total' => array_sum(array_map(fn ($r) => (int) $r->ledger_sum, $l2Rows)),
];

$counters = [
    'line_level_mismatch_count' => count($l1Mismatch),
    'full_post_invariant_violation_count' => count($l1FullPostViolation),
    'balance_projection_mismatch_count' => count($l2Mismatch),
    'orphan_movement_count' => count($orphanMovements),
    'movement_on_unposted_document_count' => count($movementOnUnposted),
    'posted_line_without_movement_count' => count($postedLineWithoutMovement),
    'balance_without_ledger_count' => count($balanceWithoutLedger),
    'duplicate_business_key_count' => count($duplicateBusinessKeys),
    'duplicate_line_effect_count' => count($duplicateLineEffects),
    'duplicate_posting_event_count' => count($duplicatePostingEvents),
];

$orphanTotal = $counters['orphan_movement_count']
    + $counters['movement_on_unposted_document_count']
    + $counters['posted_line_without_movement_count']
    + $counters['balance_without_ledger_count'];
$duplicateTotal = $counters['duplicate_business_key_count']
    + $counters['duplicate_line_effect_count']
    + $counters['duplicate_posting_event_count'];
$mismatchTotal = $counters['line_level_mismatch_count']
    + $counters['balance_projection_mismatch_count']
    + $counters['full_post_invariant_violation_count'];

$status = ($mismatchTotal === 0 && $orphanTotal === 0 && $duplicateTotal === 0) ? 'PASS' : 'FAIL';

$commit = trim((string) @shell_exec('git rev-parse HEAD')) ?: 'unknown';
$report = [
    'report' => 'WWHN-190 / WWHN-191 receipt reconciliation',
    'report_version' => REPORT_VERSION,
    'jira_key' => 'WWHN-190',
    'register_item' => 'DEV190-P1-03R2',
    'related' => ['WWHN-191'],
    'authority' => 'DEC-INV-001 (SRS v1.12 §7.1) + DEC-FULL-POST-001',
    'status' => $status,
    'commit_sha' => $commit,
    'driver' => $driver,
    'database' => DB::connection()->getDatabaseName(),
    'generated_at' => now()->toIso8601String(),
    'identity_checked' => 'accepted_receipt_qty (stock_document_lines.qty_planned) = SUM(stock_movements.qty_delta WHERE movement_type=RECEIPT) = đóng góp vào inventory_balances.on_hand_qty',
    'totals' => $totals,
    'counters' => $counters,
    'aggregate' => [
        'mismatch' => $mismatchTotal,
        'orphan' => $orphanTotal,
        'duplicate_business_effect' => $duplicateTotal,
    ],
    'findings' => [
        'line_level_mismatch' => $l1Mismatch,
        'full_post_invariant_violation' => $l1FullPostViolation,
        'balance_projection_mismatch' => $l2Mismatch,
        'orphan_movements' => $orphanMovements,
        'movements_on_unposted_documents' => $movementOnUnposted,
        'posted_lines_without_movement' => $postedLineWithoutMovement,
        'balances_without_ledger' => $balanceWithoutLedger,
        'duplicate_business_keys' => $duplicateBusinessKeys,
        'duplicate_line_effects' => $duplicateLineEffects,
        'duplicate_posting_events' => $duplicatePostingEvents,
    ],
    'sql' => [
        'l1_line_level' => $l1Sql,
        'l2_projection' => $l2Sql,
        'l3a_orphan_movement' => $orphanMovementSql,
        'l3b_movement_on_unposted' => $movementOnUnpostedSql,
        'l3c_posted_line_without_movement' => $postedLineWithoutMovementSql,
        'l3d_balance_without_ledger' => $balanceWithoutLedgerSql,
        'l4a_duplicate_business_key' => $duplicateBusinessKeySql,
        'l4b_duplicate_line_effect' => $duplicateLineEffectSql,
        'l4c_duplicate_posting_event' => $duplicatePostingEventSql,
    ],
];

file_put_contents(
    $outputDir.'/RECONCILIATION_REPORT.json',
    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"
);

$header = "WWHN-190 / DEV190-P1-03R2 reconciliation raw SQL evidence\n"
    ."report_version={$report['report_version']} commit={$commit} driver={$driver} db={$report['database']}\n"
    ."generated_at={$report['generated_at']}\n"
    ."status={$status} mismatch={$mismatchTotal} orphan={$orphanTotal} duplicate={$duplicateTotal}\n\n";
file_put_contents($outputDir.'/reconciliation-raw-sql.txt', $header.implode("\n", $rawSections));

echo json_encode([
    'status' => $status,
    'totals' => $totals,
    'counters' => $counters,
    'output' => $outputDir,
], JSON_PRETTY_PRINT)."\n";

exit($status === 'PASS' ? 0 : 1);
