<?php

namespace App\Console\Commands;

use App\Support\Cache\CacheService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Explicit repair from exact app metadata; never infers or replaces a supplier. */
final class SyncInboundSuppliers extends Command
{
    protected $signature = 'inbound:sync-suppliers {--apply : Fill NULL receipt header suppliers} {--report= : New local JSON manifest file (required for apply)}';

    protected $description = 'Reconcile receipt suppliers from app metadata; dry-run by default.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $path = trim((string) $this->option('report'));
        $stream = null;
        $committed = false;
        $report = [
            'operation' => 'inbound-supplier-sync-v1', 'apply' => $apply,
            'started_at' => now()->toIso8601String(), 'status' => 'preparing', 'updated' => 0,
            'summary' => array_fill_keys(['eligible', 'matched', 'conflict', 'no_supplier', 'missing_supplier', 'no_metadata'], 0),
            'rows' => [],
        ];

        try {
            if ($apply && $path === '') {
                throw new RuntimeException('--apply requires --report pointing to a new local file.');
            }
            if ($path !== '') {
                if (str_contains($path, '://') || file_exists($path.'.result.json') || ($stream = @fopen($path, 'x+b')) === false) {
                    throw new RuntimeException('Report must be a new writable local file; existing files are never overwritten.');
                }
                chmod($path, 0600);
                $this->writeReport($stream, $report);
            }

            DB::transaction(function () use ($apply, &$report, $stream): void {
                if ($apply && DB::getDriverName() === 'pgsql') {
                    // The posted-document trigger still verifies an exact,
                    // supplier-only NULL fill against immutable app metadata.
                    DB::statement("SET LOCAL hoanam.inbound_supplier_sync = 'on'");
                }
                $metadata = DB::table('mini_app_inbound_record_metadata')->get(['document_id', 'supplier_id'])->keyBy('document_id');
                $suppliers = DB::table('inbound_suppliers')->pluck('id')->flip();
                $fields = ['id', 'doc_no', 'supplier_id', 'version', 'status', 'updated_at'];
                $documents = DB::table('stock_documents')->where('doc_type', 'receipt')->orderBy('id')->get($fields);

                foreach ($documents as $document) {
                    $snapshot = $metadata->get($document->id);
                    $supplierId = $snapshot?->supplier_id;
                    // Only eligible receipts need a lock. Recheck the header
                    // under that lock so concurrent edits cannot be overwritten.
                    if ($apply && $document->supplier_id === null && $supplierId !== null && $suppliers->has($supplierId)) {
                        $document = DB::table('stock_documents')->where('id', $document->id)->where('doc_type', 'receipt')
                            ->lockForUpdate()->first($fields);
                        if ($document === null) {
                            continue;
                        }
                    }
                    $headerId = $document->supplier_id;
                    $outcome = match (true) {
                        $snapshot === null => 'no_metadata',
                        $supplierId === null => 'no_supplier',
                        $headerId !== null && $headerId !== $supplierId => 'conflict',
                        ! $suppliers->has($supplierId) => 'missing_supplier',
                        $headerId === $supplierId => 'matched',
                        default => 'eligible',
                    };
                    $report['summary'][$outcome]++;
                    $report['rows'][] = [
                        'document_id' => $document->id, 'doc_no' => $document->doc_no,
                        'outcome' => $outcome, 'metadata_supplier_id' => $supplierId,
                        'version' => $document->version, 'status' => $document->status, 'updated_at' => $document->updated_at,
                        'before' => ['supplier_id' => $headerId],
                        'after' => ['supplier_id' => $outcome === 'eligible' ? $supplierId : $headerId],
                    ];
                    if ($apply && $outcome === 'eligible') {
                        // Query builder intentionally leaves version/timestamps,
                        // status, metadata and every stock table untouched.
                        $report['updated'] += DB::table('stock_documents')->where('id', $document->id)
                            ->whereNull('supplier_id')->update(['supplier_id' => $supplierId]);
                    }
                }
                $report['status'] = $apply ? 'prepared' : 'dry_run';
                if (is_resource($stream)) {
                    // Persist rollback evidence before the database can commit.
                    $this->writeReport($stream, $report);
                }
            });
            $committed = $apply;
            $report['status'] = $apply ? 'committed' : 'dry_run';
            if ($apply) {
                // The prepared manifest is immutable after commit. A failed
                // outcome write must never truncate the before/after evidence.
                $resultStream = @fopen($path.'.result.json', 'x+b');
                if ($resultStream === false) {
                    throw new RuntimeException('Cannot create outcome report; prepared manifest is retained at '.$path);
                }
                try {
                    chmod($path.'.result.json', 0600);
                    $this->writeReport($resultStream, [
                        'status' => 'committed', 'updated' => $report['updated'],
                        'manifest' => $path, 'manifest_sha256' => hash_file('sha256', $path),
                        'completed_at' => now()->toIso8601String(),
                    ]);
                } finally {
                    fclose($resultStream);
                }
            }
            if ($report['updated'] > 0) {
                app(CacheService::class)->bumpVersion('stock:version');
            }
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $report['status'] = $committed ? 'committed' : 'rolled_back';
            $report['error'] = $error->getMessage();
            if (! $committed && is_resource($stream)) {
                try {
                    $this->writeReport($stream, $report);
                } catch (Throwable) {
                    // No database changes committed; report IO failure is surfaced.
                }
            }
            $this->error(($committed ? 'Database committed; post-commit step failed: ' : '').$error->getMessage());

            return self::FAILURE;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function writeReport(mixed $stream, array $report): void
    {
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
        if (! rewind($stream) || ! ftruncate($stream, 0)) {
            throw new RuntimeException('Cannot prepare supplier reconciliation report.');
        }
        $offset = 0;
        while ($offset < strlen($json)) {
            $written = fwrite($stream, substr($json, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Cannot persist supplier reconciliation report.');
            }
            $offset += $written;
        }
        if (! fflush($stream) || ! fsync($stream)) {
            throw new RuntimeException('Cannot flush supplier reconciliation report.');
        }
    }
}
