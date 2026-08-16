<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$command = $argv[1] ?? '';
$documentId = $argv[2] ?? null;

function emit(array $payload): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    exit(0);
}

if ($command === 'prepare-post') {
    $document = DB::table('stock_documents')->where('doc_no', 'PN-SEED-0003')->where('doc_type', 'receipt')->first();
    if ($document === null) {
        fwrite(STDERR, "Seed fixture PN-SEED-0003 not found. Run migrate:fresh --seed first.\n");
        exit(2);
    }

    $line = DB::table('stock_document_lines')->where('stock_document_id', $document->id)->orderBy('line_no')->first();
    if ($line === null) {
        fwrite(STDERR, "Fixture line not found.\n");
        exit(2);
    }

    if ((string) $document->status !== 'DRAFT') {
        fwrite(STDERR, "Fixture must start DRAFT; got {$document->status}.\n");
        exit(2);
    }

    DB::transaction(function () use ($document, $line): void {
        DB::table('stock_document_lines')->where('id', $line->id)->update([
            'scanned_qty' => $line->qty_planned,
            'qty_actual' => $line->qty_planned,
            'updated_at' => now(),
        ]);
        DB::table('stock_documents')->where('id', $document->id)->update([
            'status' => 'SCANNING',
            'version' => ((int) ($document->version ?? 1)) + 1,
            'updated_at' => now(),
        ]);
    });

    $fresh = DB::table('stock_documents')->where('id', $document->id)->first();
    emit([
        'document_id' => (string) $fresh->id,
        'doc_no' => (string) $fresh->doc_no,
        'status' => (string) $fresh->status,
        'version' => (int) ($fresh->version ?? 0),
        'line_id' => (string) $line->id,
        'qty_planned' => (int) $line->qty_planned,
    ]);
}

if ($command === 'reverse-payload') {
    if (! $documentId) {
        fwrite(STDERR, "document id required\n");
        exit(2);
    }
    $document = DB::table('stock_documents')->where('id', $documentId)->first();
    if ($document === null) {
        fwrite(STDERR, "document not found\n");
        exit(2);
    }
    $movements = DB::table('stock_movements')
        ->where('stock_document_id', $documentId)
        ->where('movement_type', 'RECEIPT')
        ->orderBy('created_at')
        ->get();
    if ($movements->isEmpty()) {
        fwrite(STDERR, "receipt movements not found\n");
        exit(2);
    }
    $lines = $movements->map(fn ($movement): array => [
        'movement_id' => (string) $movement->id,
        'quantity' => abs((int) $movement->quantity),
    ])->values()->all();
    emit([
        'document_id' => (string) $document->id,
        'version' => (int) ($document->version ?? 0),
        'payload' => [
            'reason_code' => 'WWHN218_RACE_PROOF',
            'reason' => 'WWHN-218 PostgreSQL concurrent reversal evidence',
            'lines' => $lines,
        ],
    ]);
}

if ($command === 'snapshot') {
    if (! $documentId) {
        fwrite(STDERR, "document id required\n");
        exit(2);
    }
    $document = DB::table('stock_documents')->where('id', $documentId)->first();
    if ($document === null) {
        fwrite(STDERR, "document not found\n");
        exit(2);
    }
    $movementRows = DB::table('stock_movements')->where('stock_document_id', $documentId)->get();
    $reversalRows = DB::table('reversal_document')->where('original_document_id', $documentId)->get();
    $reversalIds = $reversalRows->pluck('reversal_document_id')->all();
    $reversalMovements = $reversalIds === []
        ? collect()
        : DB::table('stock_movements')->where('movement_type', 'REVERSAL')->whereIn('source_id', $reversalIds)->get();

    emit([
        'captured_at' => now()->toIso8601String(),
        'document' => [
            'id' => (string) $document->id,
            'doc_no' => (string) $document->doc_no,
            'status' => (string) $document->status,
            'version' => (int) ($document->version ?? 0),
        ],
        'original_movements' => [
            'count' => $movementRows->count(),
            'receipt_quantity' => (int) $movementRows->where('movement_type', 'RECEIPT')->sum(fn ($row) => abs((int) $row->quantity)),
        ],
        'reversal_documents' => [
            'count' => $reversalRows->count(),
            'posted_count' => $reversalRows->where('status', 'POSTED')->count(),
        ],
        'reversal_movements' => [
            'count' => $reversalMovements->count(),
            'quantity' => (int) $reversalMovements->sum(fn ($row) => abs((int) $row->quantity)),
        ],
    ]);
}

fwrite(STDERR, "Usage: php scripts/wwhn218-fixture.php prepare-post | reverse-payload <document-id> | snapshot <document-id>\n");
exit(2);
