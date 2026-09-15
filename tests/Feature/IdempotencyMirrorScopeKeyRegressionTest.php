<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression for the DEV/TEST seed failure observed on PostgreSQL when two
 * runtime idempotency rows project to the same canonical
 * UNIQUE(scope, idempotency_key).
 */
class IdempotencyMirrorScopeKeyRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The idempotency mirror trigger is PostgreSQL-specific.');
        }
    }

    public function test_mirror_collapses_duplicate_runtime_rows_by_scope_and_idempotency_key(): void
    {
        $operation = 'stock.receipt.confirm';
        $key = 'seed-idem-001';
        $firstAt = now()->subMinute();
        $secondAt = now();

        DB::table('idempotency_keys')->insert([
            'idem_key' => $key,
            'user_id' => 'seed-user-a',
            'operation' => $operation,
            'business_key' => sha1($operation.'|seed-user-a|'.$key),
            'request_hash' => 'v2:'.hash('sha256', 'first'),
            'status' => 'done',
            'response_code' => 200,
            'response_body' => json_encode(['source' => 'first'], JSON_THROW_ON_ERROR),
            'locked_until' => $firstAt,
            'completed_at' => $firstAt,
            'created_at' => $firstAt,
            'updated_at' => $firstAt,
        ]);

        DB::table('idempotency_keys')->insert([
            'idem_key' => $key,
            'user_id' => 'seed-user-b',
            'operation' => $operation,
            'business_key' => sha1($operation.'|seed-user-b|'.$key),
            'request_hash' => 'v2:'.hash('sha256', 'second'),
            'status' => 'done',
            'response_code' => 200,
            'response_body' => json_encode(['source' => 'second'], JSON_THROW_ON_ERROR),
            'locked_until' => $secondAt,
            'completed_at' => $secondAt,
            'created_at' => $secondAt,
            'updated_at' => $secondAt,
        ]);

        $this->assertSame(
            2,
            DB::table('idempotency_keys')
                ->where('operation', $operation)
                ->where('idem_key', $key)
                ->count()
        );

        $this->assertSame(
            1,
            DB::table('idempotency_records')
                ->where('scope', $operation)
                ->where('idempotency_key', $key)
                ->count(),
            'Canonical mirror must respect UNIQUE(scope, idempotency_key) even when runtime rows differ by user.'
        );

        $canonical = DB::table('idempotency_records')
            ->where('scope', $operation)
            ->where('idempotency_key', $key)
            ->firstOrFail();

        $this->assertSame('seed-user-b', $canonical->user_id);
        $this->assertSame(200, (int) $canonical->response_status);
        $this->assertSame(
            hash('sha256', 'second'),
            trim((string) $canonical->request_hash)
        );
    }

    public function test_installed_mirror_definition_upserts_on_the_canonical_unique_key(): void
    {
        $definition = DB::selectOne(<<<'SQL'
            SELECT pg_get_functiondef(p.oid) AS definition
              FROM pg_proc p
              JOIN pg_namespace n ON n.oid = p.pronamespace
             WHERE n.nspname = current_schema()
               AND p.proname = 'hn_erd_mirror_idempotency_records'
             LIMIT 1
        SQL);

        $this->assertNotNull($definition);
        $sql = preg_replace('/\s+/', ' ', (string) $definition->definition) ?? '';

        $this->assertStringContainsString('ON CONFLICT (scope, idempotency_key) DO UPDATE', $sql);
        $this->assertStringContainsString('SELECT DISTINCT ON', $sql);
    }
}
