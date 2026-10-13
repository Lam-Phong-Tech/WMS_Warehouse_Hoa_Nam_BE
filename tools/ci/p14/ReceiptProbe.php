<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Throwable;

/** Test-only structural diagnostics. No response bodies or secret values leave the container. */
trait P14ReceiptProbe
{
    private array $probeEvents = [];

    private bool $probeBusy = false;

    private function probeStart(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertContains(DB::connection()->getDatabaseName(), [':memory:', 'p12_contract_test']);
        foreach (Route::getRoutes() as $route) {
            if (in_array($route->uri(), ['api/v1/mini-app/inbound/drafts/{draftId}/record', 'api/v1/mini-app/items/{itemId}/nfc'], true)) {
                $controller = $route->getController();
                $ref = new \ReflectionClass($controller);
                fwrite(STDOUT, 'P14_ROUTE_SOURCE='.json_encode(['uri' => $route->uri(), 'action' => $route->getActionName(), 'resolved_class' => $ref->getName(), 'source_sha256' => hash_file('sha256', $ref->getFileName())])."\n");
            }
        }
        DB::listen(function (QueryExecuted $query): void {
            if ($this->probeBusy || (! str_contains($query->sql, 'mini_app_draft') && ! str_contains($query->sql, 'idempotency'))) {
                return;
            }
            $this->probeBusy = true;
            try {
                $mask = static function (mixed $value): mixed {
                    if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                        return ['type' => get_debug_type($value), 'value' => $value];
                    }
                    if (is_string($value) && in_array($value, ['done', 'processing', 'failed', 'mini_app_nfc_confirm', 'INBOUND', 'OUTBOUND', 'SAVE', 'RECORD', 'DRAFT'], true)) {
                        return $value;
                    }
                    return ['type' => get_debug_type($value), 'hash' => substr(hash('sha256', (string) $value), 0, 12)];
                };
                $event = ['sql' => $query->sql, 'bindings' => array_map($mask, $query->bindings), 'transaction_level' => DB::transactionLevel(), 'connection' => $query->connectionName];
                if (str_contains($query->sql, 'idempotency')) {
                    $event['native_rows'] = DB::table('idempotency_keys')->limit(12)->get(['idem_key', 'operation', 'user_id', 'status', 'response_code'])->map(fn ($r) => array_map($mask, (array) $r))->all();
                } else {
                    $event['drafts'] = DB::table('mini_app_drafts')->limit(5)->get(['document_id', 'actor_id', 'auth_session_id', 'warehouse_id', 'last_request_id', 'version'])->map(fn ($r) => array_map($mask, (array) $r))->all();
                    $event['receipts'] = DB::table('mini_app_draft_receipts')->limit(5)->get(['document_id', 'actor_id', 'auth_session_id', 'warehouse_id', 'request_id', 'version', 'response_status'])->map(fn ($r) => array_map($mask, (array) $r))->all();
                }
                $event['caller'] = array_slice(array_values(array_filter(array_map(fn ($f) => ($f['class'] ?? '').'::'.($f['function'] ?? ''), debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30)), fn ($f) => str_contains($f, 'P14') || str_contains($f, 'P07') || str_contains($f, 'IdempotencyStore'))), 0, 5);
                $this->probeEvents[] = $event;
                $this->probeEvents = array_slice($this->probeEvents, -18);
            } finally {
                $this->probeBusy = false;
            }
        });
    }

    private function probeRun(callable $case): void
    {
        $this->probeStart();
        try {
            $case();
        } catch (Throwable $error) {
            foreach ($this->probeEvents as $event) {
                fwrite(STDOUT, 'P14_RECEIPT_STRUCTURE='.json_encode($event)."\n");
            }
            throw $error;
        }
    }
}
